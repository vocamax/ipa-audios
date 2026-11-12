<?php

declare(strict_types=1);

/*
 * Shared helpers of bin/build and bin/scan. No Composer dependencies: PHP 8.2+, ext-json, ext-gd (scan only),
 * and ffmpeg / ffprobe / yt-dlp on PATH for anything that touches audio.
 */

const SOUND_MEAN_DB = -20.0;
const SOUND_BITRATE = '96k';
const SOUND_RATE = 44100;
const FADE_IN = 0.02;
const FADE_OUT = 0.06;
const CLIP_MIN = 0.2;
const CLIP_MAX = 3.0;
/** A clip that needs more gain than this was most likely cut into silence (breath, lip smack). */
const GAIN_WARN = 18.0;
const CHANGES = 'Cut to the sound (about 0.1 s of silence before it, 0.2 s after), loudness normalized (mean '
    .'−20 dBFS), peak-limited, 20 ms fade-in and 60 ms fade-out, mono MP3 96 kbps with the credit in the ID3 tags.';
const KINDS = ['youtube', 'url', 'file'];

/**
 * `--name=value` / `--flag` anywhere on the command line, the rest positional (`getopt()` stops at the first
 * positional argument).
 *
 * @param  list<string>  $known
 * @return array{0: array<string, string|true>, 1: list<string>}
 */
function args(array $argv, array $known): array
{
    $options = [];
    $positional = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            $positional[] = $arg;

            continue;
        }
        [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        if (! in_array($name, $known, true)) {
            fail("unknown option --{$name} (known: --".implode(', --', $known).')');
        }
        $options[$name] = $value;
    }

    return [$options, $positional];
}

function root(): string
{
    return dirname(__DIR__);
}

function cacheDir(string $id): string
{
    $dir = root().'/.cache/'.$id;
    if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
        fail("cannot create {$dir}");
    }

    return $dir;
}

function fail(string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}

function warn(string $message): void
{
    fwrite(STDERR, "warning: {$message}\n");
}

function readJson(string $path): array
{
    $data = json_decode((string) @file_get_contents($path), true);
    if (! is_array($data)) {
        fail("{$path} is missing or not valid JSON");
    }

    return $data;
}

function toJson(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

/** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
function run(array $command, bool $check = true): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        fail('cannot run '.$command[0]);
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($check && $code !== 0) {
        fail(implode(' ', array_map('escapeshellarg', $command))." exited with {$code}\n".trim($err));
    }

    return [$code, (string) $out, (string) $err];
}

function need(string ...$binaries): void
{
    foreach ($binaries as $bin) {
        if (run(['sh', '-c', 'command -v '.escapeshellarg($bin)], false)[0] !== 0) {
            fail("{$bin} is not on PATH");
        }
    }
}

/** @return array<string, array{key: string, ipa: string, group: string, examples: list<string>, note?: string}> */
function sounds(): array
{
    return array_column(readJson(root().'/sounds.json')['sounds'], null, 'key');
}

/** @return array<string, array<string, mixed>> manifests of sources/*.json, by id (= file name) */
function sources(): array
{
    $sources = [];
    foreach (glob(root().'/sources/*.json') ?: [] as $path) {
        $sources[basename($path, '.json')] = readJson($path);
    }
    ksort($sources);

    return $sources;
}

/** @return list<string> problems in the manifests */
function validate(array $sources, array $sounds): array
{
    $errors = [];
    $used = [];
    foreach ($sources as $id => $s) {
        $at = "sources/{$id}.json";
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $id)) {
            $errors[] = "{$at}: file name must be letters, digits, '.', '_' or '-'";
        }
        if (! in_array($s['kind'] ?? null, KINDS, true)) {
            $errors[] = "{$at}: kind must be one of ".implode(', ', KINDS);
        }
        $where = ($s['kind'] ?? null) === 'file' ? 'path' : 'url';
        foreach ([$where, 'title', 'author', 'license', 'license_url', 'retrieved_at'] as $field) {
            if (! is_string($s[$field] ?? null) || trim($s[$field]) === '') {
                $errors[] = "{$at}: `{$field}` is required";
            }
        }
        if (! is_bool($s['verified'] ?? null)) {
            $errors[] = "{$at}: `verified` must be true or false — has anyone confirmed the uploader owns the recording?";
        }
        foreach ($s['clips'] ?? [] as $i => $clip) {
            $c = "{$at} clips[{$i}]";
            $key = $clip['sound'] ?? null;
            if (! isset($sounds[$key])) {
                $errors[] = "{$c}: unknown sound `".json_encode($key).'` (keys are in sounds.json)';

                continue;
            }
            if (isset($used[$key])) {
                $errors[] = "{$c}: sound `{$key}` already comes from {$used[$key]} — one file per sound";
            }
            $used[$key] = $at;
            $from = $clip['from'] ?? null;
            $to = $clip['to'] ?? null;
            if (! is_numeric($from) || ! is_numeric($to) || $from < 0 || $to <= $from) {
                $errors[] = "{$c}: needs numbers 0 ≤ from < to (seconds)";
            } elseif ($to - $from < CLIP_MIN || $to - $from > CLIP_MAX) {
                $errors[] = sprintf('%s: %.2f s long — a sound with its padding is %.1f–%.1f s', $c, $to - $from, CLIP_MIN, CLIP_MAX);
            }
        }
    }

    return $errors;
}

/** Link to the moment of a clip in its source (YouTube `t=`), else the source link. */
function clipUrl(array $source, array $clip): ?string
{
    if (($source['kind'] ?? null) !== 'youtube') {
        return $source['url'] ?? null;
    }

    return $source['url'].(str_contains($source['url'], '?') ? '&' : '?').'t='.(int) floor((float) $clip['from']).'s';
}

function youtubeId(string $input): ?string
{
    if (preg_match('/^[\w-]{11}$/', $input)) {
        return $input;
    }
    if (preg_match('~(?:v=|youtu\.be/|/shorts/|/embed/)([\w-]{11})~', $input, $m)) {
        return $m[1];
    }

    return null;
}

/** Mono 48 kHz WAV of a source in .cache/<id>/source.wav, downloaded / converted once. */
function fetchSource(string $id, array $source): string
{
    $dir = cacheDir($id);
    $wav = "{$dir}/source.wav";
    if (is_file($wav)) {
        return $wav;
    }

    need('ffmpeg');
    $input = match ($source['kind']) {
        'youtube' => downloadYoutubeAudio($source['url'], $dir),
        'url' => downloadUrl($source['url'], $dir),
        'file' => root().'/'.ltrim($source['path'], '/'),
    };
    if (! is_file($input)) {
        fail("source file {$input} not found");
    }
    run(['ffmpeg', '-v', 'error', '-y', '-i', $input, '-ac', '1', '-ar', '48000', $wav]);

    return $wav;
}

function downloadYoutubeAudio(string $url, string $dir): string
{
    $existing = glob("{$dir}/download.wav") ?: [];
    if ($existing !== []) {
        return $existing[0];
    }
    need('yt-dlp');
    run(['yt-dlp', '-q', '--no-warnings', '-f', 'bestaudio', '-x', '--audio-format', 'wav', '-o', "{$dir}/download.%(ext)s", $url]);

    return "{$dir}/download.wav";
}

function downloadUrl(string $url, string $dir): string
{
    $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'bin';
    $path = "{$dir}/download.{$ext}";
    if (! is_file($path)) {
        $context = stream_context_create(['http' => ['header' => "User-Agent: ipa-audios/1.0 (+https://github.com/vocamax/ipa-audios; bin/build)\r\n"]]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false || $data === '') {
            fail("cannot download {$url}");
        }
        file_put_contents($path, $data);
    }

    return $path;
}

/**
 * Speech segments of a WAV (between silences of `silencedetect`).
 *
 * @return list<array{0: float, 1: float}>
 */
function speech(string $wav, float $noiseDb = -38.0, float $minSilence = 0.25): array
{
    [, , $err] = run(['ffmpeg', '-hide_banner', '-i', $wav, '-af', "silencedetect=noise={$noiseDb}dB:d={$minSilence}", '-f', 'null', '-']);
    preg_match_all('/silence_(start|end): (-?[0-9.]+)/', $err, $m, PREG_SET_ORDER);
    $duration = duration($wav);

    $segments = [];
    $start = 0.0;
    foreach ($m as [, $kind, $t]) {
        if ($kind === 'start') {
            if ((float) $t - $start > 0.05) {
                $segments[] = [round($start, 2), round((float) $t, 2)];
            }
        } else {
            $start = (float) $t;
        }
    }
    if ($duration - $start > 0.05 && ($m === [] || end($m)[1] === 'end')) {
        $segments[] = [round($start, 2), round($duration, 2)];
    }

    return $segments;
}

function duration(string $path): float
{
    [, $out] = run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path]);

    return (float) trim($out);
}

/**
 * Cut [from, to] of a WAV into a normalized mono MP3 with the credit in its ID3 tags.
 *
 * @param  array<string, string>  $tags
 * @return float the gain applied, in dB
 */
function cutClip(string $wav, float $from, float $to, string $out, array $tags): float
{
    $tmp = tempnam(sys_get_temp_dir(), 'ipa-clip-').'.wav';
    $length = $to - $from;
    try {
        run(['ffmpeg', '-v', 'error', '-y', '-ss', sprintf('%.3f', $from), '-t', sprintf('%.3f', $length), '-i', $wav, '-ac', '1', '-ar', (string) SOUND_RATE, $tmp]);
        [, , $err] = run(['ffmpeg', '-hide_banner', '-i', $tmp, '-af', 'volumedetect', '-f', 'null', '-']);
        if (! preg_match('/mean_volume: (-?[0-9.]+) dB/', $err, $m)) {
            fail("no audio between {$from} and {$to} s");
        }
        $gain = round(SOUND_MEAN_DB - (float) $m[1], 1);
        $filter = sprintf('volume=%.1fdB,alimiter=limit=0.89,afade=t=in:d=%.2f,afade=t=out:st=%.3f:d=%.2f', $gain, FADE_IN, $length - FADE_OUT, FADE_OUT);

        $command = ['ffmpeg', '-v', 'error', '-y', '-i', $tmp, '-af', $filter, '-map_metadata', '-1', '-fflags', '+bitexact', '-flags:a', '+bitexact'];
        foreach ($tags as $name => $value) {
            array_push($command, '-metadata', "{$name}={$value}");
        }
        array_push($command, '-codec:a', 'libmp3lame', '-b:a', SOUND_BITRATE, '-id3v2_version', '3', $out);
        run($command);
    } finally {
        @unlink($tmp);
    }

    return $gain;
}
