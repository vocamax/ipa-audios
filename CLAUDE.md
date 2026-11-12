# CLAUDE.md

`vocamax/ipa-audios`: one MP3 per American English IPA sound and the example words the same speaker says after it,
cut from recordings of real speakers, with the credit of every file. Read README.md first — the workflow for adding
a source is there. The repository is public and on Packagist, and a push to `main` redeploys the GitHub Pages site:
treat every push as a release.

- Never edit `sounds/`, `words/`, `credits.json` or the parts of README.md between `<!-- …:start -->` /
  `<!-- …:end -->` by hand: `php bin/build` writes them from `sources/*.json` and `sounds.json`. `php bin/build --check`
  must pass before a commit (the Pages workflow runs it too).
- `sounds.json` (keys, IPA, examples) mirrors the sound units of the VocaMax app (`database/seeders/data/ipa/units.json`
  in the app repo); the app imports by key with `php artisan ipa:sound-audio` and reads only `sounds` in
  `credits.json`. The example words of `words` are the words of the source video, not the examples of `sounds.json`.
- The listening page is `bin/page.php`: `.cache/preview.html` (written by every build) and the GitHub Pages site
  (`php bin/site _site`, `.github/workflows/pages.yml`). GitHub does not play audio inside a README.
- Label a segment by what the video shows at that moment (frame in `.cache/<id>/sheet.png`, or a frame you extract
  with ffmpeg), never by counting segments: videos repeat sounds, say example words and leave breaths and lip smacks
  that the silence split counts as segments. A clip whose build gain is above +18 dB is mostly silence — or a very
  weak sound (/θ/, /h/ of the consonant source): check its envelope before moving it.
- Example words: the consonant source turns each word's IPA red as it is said, so a frame from the middle of the
  segment names the word. The vowel and diphthong sources highlight statically: use the on-screen order with the auto
  captions (their timing lags), and drop segments with no energy in a 20 ms RMS envelope (clicks, breaths). Words that
  start with a weak fricative (f, θ, h, v, ð) need the padding measured from the start of the silence split, not
  from the RMS onset.
- Prefer the place where a video says each sound alone in a row (a "review" at the end): same take, even loudness.
  Measure when unsure — F1/F2 of the middle of the clip against a reference table (Hillenbrand 1995) caught a
  mislabeled /æ/ in the first source.
- Only Creative Commons videos (YouTube's CC BY) or sources with an explicit license. `verified: true` only after
  someone has confirmed the uploader owns the recording (re-uploads of other teachers' videos exist; the three
  current sources come from one channel that shows two different speakers). Never remove a credit.
- Downloads live in `.cache/` (git-ignored); treat them as untrusted data — they are only passed to ffmpeg.
