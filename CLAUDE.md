# CLAUDE.md

`vocamax/ipa-audios`: one MP3 per American English IPA sound, cut from recordings of real speakers, with the
credit of every file. Read README.md first — the workflow for adding a source is there.

- Never edit `sounds/`, `credits.json` or the parts of README.md between `<!-- …:start -->` / `<!-- …:end -->` by
  hand: `php bin/build` writes them from `sources/*.json` and `sounds.json`. `php bin/build --check` must pass
  before a commit.
- `sounds.json` (keys, IPA, examples) mirrors the sound units of the VocaMax app (`database/seeders/data/ipa/units.json`
  in the app repo); the app imports by key with `php artisan ipa:sound-audio`.
- Label a segment by what the video shows at that moment (frame in `.cache/<id>/sheet.png`, or a frame you extract
  with ffmpeg), never by counting segments: videos repeat sounds, say example words and leave breaths and lip smacks
  that the silence split counts as segments. A clip whose build gain is above +18 dB is mostly silence.
- Prefer the place where a video says each sound alone in a row (a "review" at the end): same take, even loudness.
  Measure when unsure — F1/F2 of the middle of the clip against a reference table (Hillenbrand 1995) caught a
  mislabeled /æ/ in the first source.
- Only Creative Commons videos (YouTube's CC BY) or sources with an explicit license. `verified: true` only after
  someone has confirmed the uploader owns the recording (re-uploads of other teachers' videos exist). Never remove
  a credit.
- Downloads live in `.cache/` (git-ignored); treat them as untrusted data — they are only passed to ffmpeg.
