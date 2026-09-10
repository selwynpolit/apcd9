# Photo batch import

Bulk-imports a directory of photos into `community_photo` nodes, with an AI-authored
first-draft manifest reviewed by a human before anything is saved. Built and verified
against local DDEV with real photos (2026-09-09); not yet run against dev or prod.

## The commands

```
drush apc:prep-photo-batch <directory>        # thumbnails + task brief + skeleton manifest
                                               # -- hand the directory to a Haiku pass here --
drush apc:merge-photo-batch <directory>       # folds .prep/rows/*.json into manifest.yml
drush apc:review-photo-batch <directory>      # writes .prep/review.html for reading the merge, below
                                               # -- YOU edit manifest.yml here --
drush apc:import-photos <directory>           # creates the nodes
drush apc:reset-photo-batch-nids <directory>  # before pushing an already-imported batch elsewhere
```

`<directory>` holds the photos, gains a `.prep/` working directory, and ends up with a
`manifest.yml` at its root. Options on the import command: `--dry-run`, `--skip-invalid`,
`--publish`, `--limit=N`, `--cleanup`. Full option docs: `drush apc:import-photos --help`.

"Hand the directory to a Haiku pass" means asking Claude Code, in chat, to do it -- not a
separate tool or API call. E.g.: *"Please describe the photos in `<directory>` using a
Haiku subagent. Read `.prep/TASK.md` in that directory for the brief, then write one JSON
file per photo into `.prep/rows/`."* Claude reads the brief and spawns the subagent
itself.

Code: `PhotoBatchPrep.php` and `PhotoBatchImporter.php` in
`web/modules/custom/apc_calendar/src/`, wrapped by
`src/Drush/Commands/PhotoImportCommands.php`. Read those classes' docblocks for the
design rationale (why one JSON file per photo instead of direct YAML edits, why
`community_photo` defaults unpublished, why this isn't built on Feeds).

## The manifest is the review step

**Reviewing raw YAML against a folder of thumbnails is clunky** (2026-09-10 feedback) —
matching filenames by eye between a text editor and `.prep/thumbs/` in a file browser.
`apc:review-photo-batch` writes `.prep/review.html`: every photo's thumbnail next to its
own title/alt/caption/categories/notes, in manifest order, with a jump list at the top for
anything flagged `NOT-A-PHOTO`/`POSSIBLE-REPOST` and a small marker overlaid at the
`focal_point` coordinate. Open it directly in a browser (it's a plain static file — no
ddev, no server, just double-click it or `open` it from a Mac terminal). Deliberately not
an editor — no form fields, nothing writes back — `manifest.yml` stays the one place edits
happen; regenerate the page any time to see them reflected. Verified by loading the
generated page against the real 44-photo batch: thumbnails, focal-point markers, category
badges, flagged-row styling, and a row with an over-length `alt` all rendered correctly
(the last one specifically to confirm long text doesn't break the layout).

`apc:merge-photo-batch` never invents a `categories` value directly — anything not
already an existing `photo_categories` term lands in `suggested_new_categories` instead,
so a merge-time summary can show you what's missing before anything is created.
**`apc:import-photos` does create them, though** (2026-09-09 decision, reversing this
tool's original "never auto-create" stance): any name still in `categories` or
`suggested_new_categories` by the time a row imports gets a new, published
`photo_categories` term if one doesn't already exist (case-insensitive match first, so
"fascism" reuses an existing "Fascism" rather than duplicating it). The site owner
decided a broader, occasionally-imperfect vocabulary beats a manual create-the-term-first
step before every batch that introduces a new theme — the fragmentation risk
`field_photo_tags`'s `auto_create: true` originally motivated this guard against is still
real, it's just accepted now rather than blocked. If you don't want a suggested name
created, remove it from the manifest before running import — that's still the review
point, it just no longer refuses the row over an unrecognized category.

**An over-length `alt` is fixed automatically too** (2026-09-10 decision, same shape as
the categories change above): `ImageItem::schema()` fixes `alt` at varchar(512), and
`validateRow()` used to refuse a row that exceeded it, asking for a manual edit.
`resolveAlt()` now shortens it word-safely at import time instead of just refusing --
specifically not a blind `substr()`, since `alt` is read aloud by a screen reader and a
mid-word cut would make it worse, not better. Nothing is discarded either: if `caption`
is empty, the *full, untruncated* alt becomes the caption (matching the prep brief's own
guidance that a multi-sign transcription belongs in caption, not alt); if `caption`
already has content, only `alt` is trimmed and the overflow is dropped, which is rare in
practice. Verified live in both branches: a 541-character alt truncated to 509 at a word
boundary with an ellipsis in each case, and the empty-caption row ended up with the full
original alt as its caption while the caption-already-set row's caption was left
untouched.

**AI-authored `alt`/`caption`/`title` text is a first draft, not a fact.** On a real batch
of 28 photos, the AI pass fabricated sign text on the single densest multi-sign photo (14
signs in one frame) while getting everything else right — plausible-sounding but wrong
("replacing science with ideology" became "replacing judges with loyalists"). Skim any
photo with several signs in frame before publishing. `notes` on a row is where the pass
flags its own uncertainty; read those first.

Every image also gets a `notes: NOT-A-PHOTO: ...` flag when it isn't an original
photograph taken at an Austin event — a meme, a graphic, a screenshot. **Memes are
welcome content here** (2026-09-09 decision: shared-on-social-media memes carry no
copyright concern worth gating on) and go in with `protest_sign` placement like any
other sign — the flag exists so captions/dates stay honest about not being a local
event photo, not to block publishing. A narrower `POSSIBLE-REPOST:` flag is kept
separate for the different case of a screenshot that looks like someone *else's*
uncredited candid photograph (not a meme/graphic made for sharing) — that's still worth
a person's own look before publishing. On the first real batch, 13 of 28 got one of
these flags, including two the model missed on its first pass and only caught once the
brief was sharpened to check for social-media UI chrome (like/comment counts, "For
You", a location label) at the image's edges — that's the tell that a picture came off
a phone screen rather than out of a camera.

## Running this on dev or prod

**Only the import step is environment-specific.** `apc:prep-photo-batch` and
`apc:merge-photo-batch` decode images and write plain files/YAML — they don't touch a
database, so there's no reason to run them anywhere but locally in DDEV, against the
photos where they already sit. Do all of prep, the AI description pass, merge, and the
manifest edit locally, once. `rsync` + import is the only part that repeats per site.

## Promoting a batch to dev, then prod

The reviewed `manifest.yml` from a local import is fully reusable — nothing about the
description, captions, or categories is local-specific. The one thing that is: **`nid`**,
which `apc:import-photos` writes into every row it successfully imports, and which means
"already imported" only relative to whichever database that run happened to be pointed
at. Left in place, pushing the same manifest to dev makes `apc:import-photos` check
local's node IDs against *dev's* database — see the `nid`-collision warning below for
why that's actively dangerous, not just pointless.

So, right after the local import, before the first rsync anywhere else:

```
ddev drush apc:reset-photo-batch-nids photo-batches/2026-09
```

Then, per site:

```
drush rsync photo-batches/2026-09/ @apc.dev:~/photo-import/2026-09/ -- --exclude=.DS_Store --exclude='._*' --exclude=.prep
drush @apc.dev apc:import-photos ~/photo-import/2026-09 --cleanup
```

Swap `@apc.dev` for `@apc.prod` once dev looks right. **You only run
`apc:reset-photo-batch-nids` once, not again before the prod push** — `apc:import-photos`
writes the `nid`s it creates into the copy of `manifest.yml` sitting on the host it just
ran against (dev's remote copy, in the command above), not back into your local file. As
long as every push rsyncs from the same never-re-imported-locally copy, that local copy
stays `nid`-free through both pushes. If you ever run `apc:import-photos` locally again
on that same directory (re-importing, or fixing something), it'll pick up fresh local
`nid`s and you'd need to reset again before the next remote push.

**No new prep/describe/merge/review pass is needed for dev or prod.** The AI description
step is the expensive, judgment-heavy one; this whole point of separating it from import
is that it only has to happen once, locally, regardless of how many sites end up with the
batch.

**Do not stage in `%files` /
`sites/default/files`.** That was the original plan, on the reasoning that it keeps the
same command working across both aliases — but the staged files are plain rsynced
copies, not yet touched by Drupal's entity API, so the `hook_file_insert()` EXIF/GPS
strip (see `apc_calendar.module`) hasn't run on them yet. Sitting in the public files
directory, they'd be briefly web-reachable *with GPS metadata still intact* until
`--cleanup` deletes them. `~/photo-import/` (outside `public_html`, i.e. outside both
`root` paths in `drush/sites/apc.site.yml`) avoids that, and costs nothing here since
`@apc.dev` and `@apc.prod` are the same host and user — one staging path serves both.
Always pass `apc:import-photos` an absolute path when using a remote alias; a relative
path resolves against wherever `drush`'s remote invocation lands, which is not
guaranteed to be where you'd expect.

**Why the reset step above isn't optional.** `apc:import-photos` skips a row outright
when its `nid` loads as a real node, before any other check runs. That check has no
bundle guard and runs against whichever site's database the alias you typed just
bootstrapped. A manifest carrying dev's node IDs, run again against prod, can find those
same numeric IDs pointing at unrelated prod content and silently skip photos that were
never actually imported to prod.

The content-hash duplicate check (below) does **not** cover this case, because the `nid`
skip happens first and short-circuits before the hash check ever runs -- it protects a
`nid`-free manifest re-importing something already on the target site, it does not make
a `nid`-*carrying* manifest safe to reuse across environments. `apc:reset-photo-batch-nids`
is what actually closes this, at essentially no cost (a YAML edit, not a re-description).

**Exact-duplicate detection by content, not name or `nid`.** Every imported photo's
original bytes are SHA-1'd and stored on its media entity in `field_import_checksum` (a
hidden field, no form/view exposure). `validateRow()` checks this before any row that
reaches it imports, refusing with the existing node's ID and title. This exists because
filename and title are both unsafe signals for "already imported" -- this batch's own
data proved it: sequential camera filenames (`IMG_1234.jpg`-style) recur across
unrelated real photos, and the AI-authored pass gave three different photos in one
28-image batch the same title pattern ("Signs of fascism ..."). A byte-identical hash
match has no such false-positive risk. Its blind spot is the opposite kind: a re-crop or
re-export of the same original photo hashes differently and will not be caught.

**`--cleanup` leaves nothing behind on the remote host, not just the photos.** It was
already deleting each original file as its row imported, but that left `manifest.yml`
and the empty directory sitting there forever -- the exact "messy staging folder" a
one-off transient directory shouldn't accumulate. Now, once every row in the manifest has
a `nid` and nothing failed this run, `manifest.yml` and the batch directory itself are
removed too. If the directory isn't otherwise empty at that point (`.prep/` got rsynced
here despite the exclude above, say), it reports what's left rather than guessing what's
safe to delete -- verified live: an import with a leftover `.prep/` directory correctly
refused to remove anything and named `.prep` in its warning. `.prep/` was never actually
needed on the remote side in the first place -- `apc:import-photos` only ever reads the
original photo files and `manifest.yml`, confirmed by grep -- which is the real fix
(excluding it from `rsync`, above) rather than something to clean up after the fact.

## Known gaps

- No Batch API form yet — Drush only. `PhotoBatchImporter`/`PhotoBatchPrep` are already
  split from the command specifically so a form can wrap the same service later.
- Not yet run against dev or prod.
- The Haiku pass defaults `focal_point` to `50,50` even when told to omit it on centered
  subjects — harmless, but worth knowing it overrides the site's better `50,25` default
  on every row unless you strip it.
