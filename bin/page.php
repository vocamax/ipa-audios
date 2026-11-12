<?php

declare(strict_types=1);

/*
 * The listening page: every sound and its example words with a play button, and the credit of every source.
 * bin/build writes it to .cache/preview.html (files linked with $prefix '../'), bin/site to the GitHub Pages site.
 */

/**
 * @param  array<string, array<string, mixed>>  $sounds  sounds.json by key
 * @param  array{sounds: array<string, array<string, mixed>>, words: array<string, list<array<string, mixed>>>, changes: string}  $credits
 * @param  array<string, array<string, mixed>>  $sources  manifests by id
 */
function page(array $sounds, array $credits, array $sources, string $prefix = ''): string
{
    $e = fn (string $text) => htmlspecialchars($text, ENT_QUOTES);
    $src = fn (array $entry) => $e($prefix.$entry['file'].'?v='.substr($entry['sha1'], 0, 8));
    $unverified = false;

    $groups = '';
    foreach (GROUPS as $group => $title) {
        $rows = array_filter($sounds, fn (array $s) => $s['group'] === $group);
        $have = count(array_intersect_key($credits['sounds'], $rows));
        $cards = '';
        foreach ($rows as $key => $s) {
            $c = $credits['sounds'][$key] ?? null;
            $words = '';
            foreach ($credits['words'][$key] ?? [] as $w) {
                $words .= sprintf('<button type="button" class="word" data-src="%s">%s</button>', $src($w), $e($w['word']));
            }
            $unverified = $unverified || ($c !== null && ! $c['verified']);
            $cards .= sprintf(
                '<article class="card%s"><button type="button" class="sound" %s aria-label="Play /%s/"><span class="ipa">/%s/</span>'
                .'<span class="key">%s</span></button><div class="words">%s</div><p class="credit">%s</p></article>',
                $c ? '' : ' missing',
                $c ? 'data-src="'.$src($c).'"' : 'disabled',
                $e($s['ipa']), $e($s['ipa']), $e($key), $words,
                $c ? sprintf('<a href="%s" title="%s — %s">source</a> · %s%s', $e($c['url']), $e($c['title']), $e($c['author']), $e($c['license']), $c['verified'] ? '' : '<sup>¹</sup>') : 'missing',
            );
        }
        $groups .= sprintf('<section><h2>%s <small>%d/%d</small></h2><div class="grid">%s</div></section>', $title, $have, count($rows), $cards);
    }

    $list = '';
    foreach ($sources as $id => $s) {
        $count = count($s['clips'] ?? []);
        $wordCount = count($s['words'] ?? []);
        $author = isset($s['author_url']) ? sprintf('<a href="%s">%s</a>', $e($s['author_url']), $e($s['author'])) : $e($s['author']);
        $list .= sprintf(
            '<li><a href="%s">%s</a> — %s · <a href="%s">%s</a>%s<br><small>%d sound%s%s · retrieved %s</small></li>',
            $e($s['url'] ?? ''), $e($s['title']), $author, $e($s['license_url']), $e($s['license']),
            $s['verified'] ? '' : '<sup>¹</sup>',
            $count, $count === 1 ? '' : 's', $wordCount > 0 ? ", {$wordCount} example words" : '', $e($s['retrieved_at']),
        );
    }
    $total = count($credits['sounds']);
    $words = wordCount($credits['words']);

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>American English IPA sounds</title>
        <meta name="description" content="One recording per American English IPA sound, with example words, cut from recordings of real speakers. Every file is credited.">
        <style>
        :root{--bg:#f8fafc;--card:#fff;--text:#0f172a;--muted:#64748b;--line:#e2e8f0;--accent:#2563eb;--accent-soft:#dbeafe;--chip:#f1f5f9}
        @media (prefers-color-scheme:dark){:root{--bg:#0b1120;--card:#111827;--text:#e2e8f0;--muted:#94a3b8;--line:#1f2937;--accent:#60a5fa;--accent-soft:#1e3a5f;--chip:#1f2937}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        .wrap{max-width:1080px;margin:0 auto;padding:32px 16px 48px}
        header h1{margin:0 0 6px;font-size:28px;letter-spacing:-.01em}
        header p{margin:0 0 6px;color:var(--muted);max-width:720px}
        a{color:var(--accent)}
        h2{font-size:18px;margin:36px 0 12px}h2 small{color:var(--muted);font-weight:500;font-size:14px}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(176px,1fr));gap:12px}
        .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:10px}
        .card.missing{opacity:.5}
        button{font:inherit;color:inherit;cursor:pointer;border:0}
        .sound{display:flex;align-items:baseline;justify-content:space-between;width:100%;padding:10px 12px;border-radius:10px;background:var(--accent-soft);text-align:left}
        .sound:disabled{cursor:default;background:var(--chip)}
        .ipa{font-family:"Charis SIL","Doulos SIL","Noto Sans","Segoe UI","Lucida Sans Unicode",system-ui,sans-serif;font-size:26px;line-height:1.2}
        .key{font:12px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted)}
        .words{display:flex;flex-wrap:wrap;gap:6px;min-height:28px}
        .word{padding:3px 10px;border-radius:999px;background:var(--chip);font-size:14px}
        .sound:hover,.word:hover{outline:2px solid var(--accent);outline-offset:-2px}
        .sound:focus-visible,.word:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
        .playing{background:var(--accent)!important;color:var(--card)}.playing .key{color:inherit}
        .credit{margin:auto 0 0;font-size:12px;color:var(--muted)}
        footer{margin-top:40px;border-top:1px solid var(--line);padding-top:16px;color:var(--muted);font-size:14px}
        footer li{margin-bottom:8px}footer ul{padding-left:18px}
        </style>
        </head>
        <body>
        <div class="wrap">
        <header>
        <h1>American English IPA sounds</h1>
        <p>{$total} sounds and {$words} example words, cut from recordings of real speakers. Press a symbol to hear the sound alone, a word to hear it in use.</p>
        <p><a href="https://github.com/vocamax/ipa-audios">GitHub</a> · <a href="https://packagist.org/packages/vocamax/ipa-audios">Packagist</a> · <a href="{$prefix}credits.json">credits.json</a></p>
        </header>
        <main>{$groups}</main>
        <footer>
        <h2>Sources</h2>
        <ul>{$list}</ul>
        <p>Changes: {$e($credits['changes'])} Each file keeps the license of its source; give the credit where the sound is used.</p>
        HTML
        .($unverified ? '<p><sup>¹</sup> License as labeled by the uploader; the original source of the recording is not confirmed yet.</p>' : '')
        .<<<'HTML'
        </footer>
        </div>
        <script>
        const player = new Audio();
        let current = null;
        const stop = () => { if (current) current.classList.remove('playing'); current = null; };
        document.addEventListener('click', (event) => {
          const button = event.target.closest('[data-src]');
          if (!button) return;
          stop();
          player.src = button.dataset.src;
          player.play().then(() => { current = button; button.classList.add('playing'); }).catch(stop);
        });
        player.addEventListener('ended', stop);
        player.addEventListener('error', stop);
        </script>
        </body>
        </html>

        HTML;
}
