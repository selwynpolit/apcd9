# Spec: Unified photo gallery (protest signs + community photos)

Status: **designed, not started.** No code written, no config changed. Written to match the
format of `auto-tag-task.md` / `event-import-task.md` so it can drop into the repo as-is. Per
`CLAUDE.md`'s plan-doc convention, once this is implemented it belongs in `assets/Plans/`, not at
the repo root.

## Read first

- `CLAUDE.md` — TODO section and the two standing rules.
- `auto-tag-task.md` — the `TagSuggester` service is a candidate for reuse here (see
  "Interaction with the tag-suggest task" below); also the closest precedent in this repo for
  "field that's free-typed by the public vs. curated by an admin."
- `Event Calendar Plan.md` §3e — the location/tag moderation precedent this design's photo
  moderation queue is modeled on.

**Two standing rules for this repo, both learned the hard way:**

1. Run `ddev drush cst` **before** `ddev drush cex`.
2. Verify against the running site, not against exported YAML or with CSS aggregation on.

## Goal

Two blocks — "Protest Signs" and "Community Photos" — that cycle through images. Both are, at
the data level, the same thing: a moderated pool of photos. Building one content model that both
blocks pull from (each pre-filtered by a required "which gallery" field) means the
upload/tagging/moderation work only has to be built once, and a third gallery ("Volunteer Days,"
whatever comes next) is mostly a config change, not a dev task.

## Decisions already taken

| Question | Decision |
|---|---|
| One system or two | **One.** Single content type; blocks differ only in a required `field_gallery_placement` checkbox pair, not in separate content types. |
| Where a block's filter lives | **Baked into a Views block display**, not a runtime block-config form. See "Why Views displays, not a custom block plugin." |
| Cycling mechanism | **Server renders a cacheable batch (~20–30 photos); client-side JS shuffles and cycles.** Not a per-request-random query. See "Why client-side shuffle, not server-side random." |
| Content entity | **A node type (`community_photo`), not a bare Media entity.** Needs its own moderation status, submitter attribution, and a caption/tag field set independent of the image itself. |
| Category vocabulary | **New `photo_categories` vocabulary**, separate from the existing `tags` vocabulary events use. See "Why not reuse `tags`." |
| Free-typing categories | **`auto_create: true`, same as `tags`** — but gated: a term created by an anonymous submission saves **unpublished**, same mechanism as `locations` (`apc_calendar_taxonomy_term_presave()`'s gate on a bypass permission), not the same as `tags`'s ungated instant-publish. See "Why not reuse `tags`" — the widget shape is now the same autocomplete `tags` uses; the moderation posture is what's still different. |
| Protest Signs vs. Community Photos | **A dedicated required checkbox field, separate from the open tags.** Not inferred from the free-typed category field — see "How the two blocks actually differ," below. |
| Public submission | **Yes, from the start** — anonymous allowed, same trust model as event submission (unpublished until approved). |
| Spam protection | **Honeypot + CAPTCHA**, already installed, already wired to the anonymous event form — same config reused. Also adding core Flood API limits — see "Additional features worth considering." |
| Moderation bypass | **None for now.** Every submission, regardless of who submits it, lands unpublished in the queue. No new permission, no role decision needed. Revisit if the queue becomes a bottleneck. |
| Alt text | **The Media entity's own `field_media_image.alt`, nothing new.** No separate node-level alt field — see "Alt text," below, for why a second field isn't worth it. |
| Gallery placement widget | **`field_gallery_placement`, a `list_string` field (not taxonomy) with checkboxes, options "Protest Sign" / "Community Photo," required, multi-value.** Core's own required-field validation already refuses to submit with nothing checked — no custom validation code needed. |
| Initial bulk upload | **Admin uploads through Media Library (or Dropzone, if installed), then tags/captions/publishes each through the moderation view's VBO actions** — bulk apply-category plus a per-photo Claude-in-Chrome captioning pass. See "Bulk-tagging after upload" and "AI-assisted captioning." |
| Privacy (EXIF/attribution) | **Strip GPS EXIF data on upload; submitter attribution off by default.** See "Privacy and safety" — this matters more here than on a typical photo gallery. |
| Moderation queue tooling | **Views Bulk Operations** (`drupal/views_bulk_operations`, new dependency) on the moderation view — bulk publish, bulk apply-category. Supersedes the earlier "skip VBO" call now that you want it explicitly. |
| Upload UX | **Dropzone.js** (`drupal/dropzonejs`, new dependency) for drag-and-drop multi-file upload, on both the admin bulk-upload flow and the public submission form. See "Drag-and-drop upload." |

## Architecture

```
photo_categories (taxonomy, auto_create)   <- open, admin-moderated topic tags: Rally, Tabling, ...
        |
community_photo (node type)         <- field_photo_image (Media ref), field_caption,
        |                              field_photo_tags, field_gallery_placement,
        |                              field_submitter_name
        |
Views: "Photo Gallery"              <- one view, multiple block displays
   +-- block "Protest Signs"        <- filter: field_gallery_placement contains "Protest Sign"
   +-- block "Community Photos"     <- filter: field_gallery_placement contains "Community Photo"
   +-- (future block) "Volunteer"   <- add a new allowed value + new display, no code beyond that
        |
apc_brown/js/photo-carousel.js      <- client-side shuffle + cycle, reuses lightbox.js for
                                        click-through to full size
```

## Content model

New node type `community_photo`:

- `field_photo_image` — media reference (Image media type), required. Reuses the existing
  `image_focal_point` widget pattern from event/location images (same crosshair, same
  `focal_point.settings.default_value` of `50,25`) so a portrait phone photo of a sign doesn't
  get its top cropped off. Alt text lives on the Media entity itself
  (`field_media_image.alt`) — see "Alt text," below.
- `field_caption` — plain text, optional. Editorial/display text, shown under the photo.
- `field_gallery_placement` — **new field, this is the piece you asked for.** A `list_string`
  field, allowed values `Protest Sign` / `Community Photo`, checkboxes widget, multi-value,
  **required**. This is what each block's Views filter reads — see "How the two blocks actually
  differ." Because it's a plain list field (not an entity reference), Drupal's ordinary
  required-field validation already refuses to submit the form with nothing checked. No custom
  validation handler needed for "choose at least one."
- `field_photo_tags` — entity reference to `photo_categories`, multi-value, `auto_create: true`,
  autocomplete widget (same shape as the event `tags` field). Open-ended topical tags — "Rally,"
  "Tabling," whatever a submitter or admin wants to add — separate from and unrelated to which
  block(s) the photo appears in.
- `field_submitter_name` — plain text, optional. Mirrors how events capture a submitter without
  requiring an account.
- Published: **FALSE by default for anonymous**, same mechanism as `apc_calendar_node_presave()`
  gates `locations` — a `publish community_photo content immediately` permission, granted to a
  trusted role, bypasses this the same way `event_contributor` bypasses location approval. (No
  role has this permission yet, per the "moderation bypass: none for now" decision — everything
  queues until you decide otherwise.)

### How the two blocks actually differ

`field_gallery_placement` is the single source of truth — a required checkbox pair, not
inferred from the open-ended `field_photo_tags`. Checking "Protest Sign" puts it in the signs
block; checking "Community Photo" puts it in the photos block; checking both puts it in both.
This was deliberately split out from the general tags field: tags are open-ended and can drift
(new categories added, existing ones renamed), and you don't want block membership silently
changing because someone edited a tag. Gallery placement is a fixed two-option list that only
changes if you decide to add a third gallery.

Each Views block display's filter is then trivial: `field_gallery_placement` contains the one
value that display is for. Adding a third gallery later means adding a third allowed value to
the list field, plus a third Views block display filtered to it — a config change, still no code.

### Why a separate `photo_categories` vocabulary, not the existing `tags`

The `tags` vocabulary is event-topic folksonomy — deliberately ungated, free-typed, instantly
published no matter who types it (§3e). `photo_categories` now has the same *shape* (auto_create,
autocomplete widget) but a different *moderation posture*: an anonymously-submitted term saves
unpublished and needs review before it's usable, the same gate `locations` already has. Mixing
the two vocabularies would mean an event's tag list could suddenly include unreviewed photo
terms, or vice versa — keeping them separate keeps each vocabulary's moderation rule
unambiguous and keeps an event-tag admin screen free of photo-only terms.

### Why Views displays, not a custom block plugin

Two ways to get "each block pre-filtered to different terms":

1. **One Views block display per gallery**, each with a hard-coded taxonomy filter — pure
   config, matches how `location_page` already has `block_upcoming`/`block_past` as separate
   displays off one view, and `views.view.pending_events` pattern generally. Adding a new
   gallery later is a Views UI task (duplicate a display, change its filter), no deploy.
2. A custom Block plugin with a "which terms" config field on the block-placement form — more
   flexible per-instance (a content editor could spin up a new filtered block from Block Layout
   without touching Views), but it's new PHP in a codebase whose CLAUDE.md explicitly frames
   itself as "vanilla Drupal... 1 custom module, 1 custom theme," built almost entirely through
   config.

Recommending **option 1**. It costs you comfort with the Views UI (which you already have —
`location_page`, `pending_events`, the calendar views) instead of costing dev time now and
maintenance later. If you find yourself wanting non-technical staff to spin up new galleries
without any Views knowledge, option 2 is a clean follow-up — the content model doesn't change,
only how the filter gets configured.

### Why client-side shuffle, not server-side random

Production runs Drupal's page cache for anonymous traffic (see the `local` config-split notes in
`CLAUDE.md` — deliberately testing against real caching behavior was a lesson learned once
already on this project). A Views block with a `RAND()` sort or `max-age: 0` cache forces that
block, and often the whole page, to skip the cache on every anonymous hit — expensive for a
public marketing block that changes rarely.

Instead: the view returns a bounded, ordered set (e.g. the 30 most recent published photos in
that category) — a completely normal, cacheable Views block, invalidated by the usual node cache
tags whenever a photo is added/edited/approved. `photo-carousel.js` Fisher–Yates-shuffles that
list client-side on load and cycles through it — different order per visitor, per page load,
zero cache cost. Click-through to full size reuses `apc_brown/lightbox` (already built for
event/location galleries) rather than a new lightbox implementation.

Auto-advance respects `prefers-reduced-motion` (no forced motion) and pauses on hover/focus —
same accessibility bar as the existing hero-swap gallery, plus WCAG's "don't autoplay
uninterruptibly" rule, which the existing gallery doesn't have to worry about since it's
click-driven, not timed.

## Public submission form

New route, same shape as the existing anonymous event-submission form:

- Honeypot + CAPTCHA (already installed and configured; same settings reused, not
  reconfigured).
- Fields: image (Media Library widget, focal point, alt text prompt — see "Alt text" below),
  caption, gallery-placement checkboxes (required), topic tags (optional, auto-create), submitter
  name + credit-me-publicly checkbox.
- On submit: node saved unpublished, same "you'll see it once it's approved" messaging pattern as
  `/event-submitted`. No bypass permission exists yet (see "Moderation bypass" decision), so this
  is unconditional for now.
- Node moderation queue: the new `/admin/content/photos` view — see "Moderation queue view,"
  below.
- **A second, smaller moderation queue also falls out of making `photo_categories` auto_create +
  gated**: any brand-new term an anonymous submitter types saves unpublished, same as a new
  `locations` term does today. `/admin/content/tags-review` is `tags`-specific, confirmed — it
  doesn't generalize, so `photo_categories` needs its own equivalent. See "Term moderation queue,"
  below.

## Alt text — using the Media module's own field, no new field

Agreed — a second alt-text field on `community_photo` would just be a second place for the same
information to live, with no clear rule for which one wins if they ever disagreed. Drupal's Media
image field already has a real alt-text field (`field_media_image.alt`) built for exactly this,
and this codebase already relies on it for event/location photos. Using it here is the same
pattern, not a new one, and it's the field screen readers and every image-rendering path actually
read — a second field would need its own presave sync logic just to stay meaningful, for no real
benefit.

Two adjustments, both configuration, no new field:

1. **A specific prompt, not a generic label.** The Media Library widget's alt-text input, as it
   appears in the `community_photo` submission form and the admin bulk-upload flow, gets its
   field description overridden to something like "Describe the sign or scene for people using
   screen readers (what does the sign say, what's happening in the photo?)" instead of the bare
   default. Same precedent already used for the focal-point crosshair's description and the
   add-location link — a config-level description change, not new code.
2. **Caption doubles as alt text when alt is left empty**, via a presave fallback on the Media
   entity (same shape as the existing `ucfirst()`-on-taxonomy-term-save pattern in
   `apc_calendar_taxonomy_term_presave()`) — a safety net under the prompt above, not a
   replacement for it. Core's own image field already refuses to save with alt text completely
   blank if the field is marked required, so this fallback mostly matters for a media entity
   that's reused from elsewhere and never got alt text of its own.

### AI-assisted captioning during moderation

This is the piece you raised — using Claude in Chrome as a manual review step, not something
built into Drupal itself. The shape: once submissions exist in the moderation queue, you open
`/admin/content?type=community_photo` (unpublished filter) in Chrome and, working through it
with me, for each pending photo I look at the image and draft a caption and alt-text suggestion,
which you read, edit if needed, and paste into the fields before approving. It's a periodic
session you run (weekly, whenever the queue builds up), not an automated pipeline — the required
field at submission time (layer 1 above) is what keeps a *rushed* submitter from leaving it
blank; this pass is what upgrades "adequate" alt text to actually good alt text, and is also
where you'd catch the case above (someone submitting a sign whose photographed text is small or
at an angle and worth transcribing more carefully than a phone-typed submission would).

Worth being clear this is a workflow, not a feature to build — no code or config changes are
needed to make it possible, since it's just you and me looking at an existing admin page
together. I can walk through it with you once the moderation queue has real submissions in it.

For the **initial bulk upload** (your own Dropbox photos, going in before the public form even
exists), the same Chrome-assisted pass works even better, since there's no submitter-provided
alt text to start from at all — I can look at each photo as you upload it and draft both the
caption and alt text in one pass, rather than you writing placeholder text and fixing it later.

### Bulk-tagging after upload

Superseded by the moderation-view decision below — VBO's bulk "apply category to selected"
action covers this whether or not you're also doing individual captions. The two aren't
redundant: VBO handles the mechanical part (tag 15 photos from the same event as `Rally` in one
action), the Claude-in-Chrome pass still handles the part that has to be per-photo (captions, alt
text).

## Moderation queue view (with bulk operations)

A dedicated Views admin view, `/admin/content/photos` (or similar — not reusing the generic
`/admin/content` node listing, since that one isn't set up for this content type's fields), same
family as `pending_events`:

- Base: `community_photo` nodes, default sort newest-first, exposed filters on
  `field_gallery_placement`, `field_photo_tags`, and published/unpublished — so you can view
  "everything pending," "everything in Community Photos," or "everything tagged Rally"
  independently or combined.
- Columns: thumbnail, caption, gallery placement, categories, submitter (if credited), submitted
  date, published status.
- **VBO field** (`drupal/views_bulk_operations`, new composer dependency — not currently in this
  codebase) with a checkbox per row and an action selector. Two actions:
  - **Publish content** — core's own bulk publish action, nothing custom needed.
  - **Apply category to selected** — new custom VBO action, same shape as
    `PublishEventAndLocation` (`Plugin/Action/PublishEventAndLocation.php`) already in
    `apc_calendar`: a small Action plugin that takes a configured term (chosen from a select list
    VBO shows before running) and adds it to `field_photo_tags` on every selected node, skipping
    duplicates. Custom PHP, same as `PublishEventAndLocation` already is — one more Action plugin
    next to the one that already exists, not a new pattern.
- Unpublish and delete as VBO actions too, for rejecting spam/inappropriate submissions — core
  provides both, no custom code.

This view is the natural home for the Claude-in-Chrome captioning pass described above: filter to
unpublished, work photo by photo writing captions/alt text, then select everything you've
finished with and bulk-publish in one action rather than saving each node individually.

## Term moderation queue (`photo_categories`)

Separate from the node-moderation view above — this one moderates unpublished **taxonomy
terms**, not photos. Needed because `photo_categories` is now `auto_create` + gated, same as
`locations`: an anonymous submitter typing a new category creates a real term, unpublished,
invisible in the field's own autocomplete and in any Views filter that (correctly) excludes
unpublished terms until you approve it.

`/admin/content/tags-review` is specific to the `tags` vocabulary — confirmed, doesn't
generalize — so this needs its own view, not a shared one. Given that, worth building it as a
**vocabulary-agnostic replacement for both**, rather than a second near-duplicate of
`tags-review`'s logic: one Views admin page, exposed filter to pick which vocabulary (`tags` or
`photo_categories`, or both), base filter on unpublished terms, VBO field for bulk
publish/delete/merge (reusing the `term_merge`/`taxonomy_manager` modules already installed and
already enabled for `tags`). If `tags-review`'s existing implementation turns out to be a plain
Views display rather than custom code, generalizing it may be as simple as adding a second
allowed vocabulary to its existing filter — worth checking that before building a parallel view
from scratch. If it turns out to be more entangled with `tags`-specific logic than that, a
separate `photo_categories`-only view, same shape, is the fallback — still no new pattern, just
one more instance of "unpublished term review."

Either way, this needs to exist **before** the public submission form ships (phase 2), since
that's the point unpublished terms start actually being created by people other than you.

## Drag-and-drop upload

Core's plain File/Image widget (`managed_file`) doesn't ship a real drag-and-drop dropzone —
just a file-picker button plus an AJAX upload. (Modern browsers do let you drop a single file
onto that input and have it register, but there's no multi-file drop, no thumbnail previews, no
progress bar — it's an accident of browser behavior, not a designed feature.)

For an actual drag-and-drop experience — drop a folder of 40 photos onto the page, see thumbnails
and progress as they upload — the standard answer is **`drupal/dropzonejs`**, a contrib module
wrapping the DropzoneJS JS library. It replaces the widget on a File/Image (or Media reference)
field with a real dropzone: drag multiple files at once, per-file progress, thumbnail preview
before submit, works on both the admin bulk-upload screen and the public submission form.

One thing worth noting: `CLAUDE.md`'s pre-launch checklist already has "Confirm asset-packagist
is reachable from GreenGeeks before the first production `composer install`" as an open item —
asset-packagist is exactly the mechanism that pulls in DropzoneJS's actual JS library (it's not
a PHP package, so it can't come from Packagist alone). That checklist item was presumably written
for some other future JS-library need, but it means the infrastructure decision is already
anticipated, not new. Worth confirming that reachability check before relying on `dropzonejs`
here.

Alternative if you'd rather not add the dependency: core Media Library's own multi-file "Add
media" dialog already lets you select several files at once via the OS file picker (not
drag-and-drop, but multi-select) — meaningfully faster than one-at-a-time even without Dropzone,
and zero new dependencies. Worth trying that first on your actual bulk upload before deciding
whether Dropzone's drag-and-drop is worth the added module.

## Privacy and safety

Worth raising unprompted, since this wasn't in the original ask but matters more here than on a
typical photo gallery: **these are photos from protests, potentially identifying who attended
and where.**

- **Strip GPS EXIF data on upload — implementation plan.** Phone photos routinely embed precise
  GPS coordinates and a timestamp in the file's metadata — invisible in the browser, fully
  readable by anyone who downloads the original file. For an activism site, that's a real
  de-anonymization risk (home address if someone photographs from their porch, exact protest
  location/time tied to whoever's recognizable in frame). Drupal doesn't strip this
  automatically, and — important, easy to miss — generating an image style *derivative* (the
  cropped/resized versions the gallery normally shows) already strips EXIF as a side effect of
  GD re-encoding the pixels, but the **original uploaded file** on disk does not get touched by
  that, and the gallery's lightbox is built to link to the original, uncropped image for the
  full-size view (`_apc_brown_build_gallery()`'s `full` key, added for the event/location
  galleries). So without an explicit strip step, the one place this site *deliberately* exposes
  the original file is exactly the place GPS data would still be sitting in it.

  Concrete plan:
  1. Hook `hook_file_insert()` — not a media-entity hook — so this runs at the single point every
     upload path passes through (admin Media Library, Dropzone, the public form, a future import)
     rather than needing the same logic duplicated per entry point. Same "one implementation"
     reasoning `TagSuggester` already uses for tag matching in this codebase.
  2. Read the EXIF orientation tag first, before stripping anything (`exif_read_data($uri)`).
     This matters and is easy to get wrong: JPEG orientation for portrait phone photos is stored
     as an EXIF tag, not baked into the pixels, so stripping EXIF blindly leaves photos looking
     sideways or upside down for anyone whose browser was relying on that tag.
  3. Physically rotate/flip the image to match that orientation (`imagerotate()` / `imageflip()`
     via GD, core's own image toolkit — no new library needed for this part).
  4. Re-save with `imagejpeg()`. GD's `imagejpeg()` writes a fresh JPEG from the decoded raster
     data and does not carry EXIF (or XMP, which can also carry GPS) forward — the re-encode
     itself is the strip, not a separate "remove metadata" call.
  5. Scope this to JPEG uploads (where GPS EXIF actually shows up); PNG/GIF pass through
     untouched.

  Deliberately **not** shelling out to `exiftool` — GD avoids a dependency on a system binary
  being installed and `shell_exec` being permitted on GreenGeeks' shared hosting, which isn't
  confirmed either way. Worth verifying on the running site, not assumed: upload a real
  GPS-tagged phone photo, then check the *served* file (not just the upload) with a tool that
  reads EXIF, to confirm nothing survives the round trip. This applies to your own bulk upload
  too, not just public submissions — your Dropbox photos likely carry the same metadata.
- **Submitter attribution off by default.** `field_submitter_name` should not be *shown
  publicly* unless the submitter opts in — add a simple "Credit me publicly" checkbox on the
  form, default unchecked. The name can still be stored for your own moderation/contact purposes
  either way; the decision is just whether it renders on the public page. Someone submitting a
  protest photo may not want their name publicly tied to it even if they're comfortable
  submitting it.
- **No face detection or blurring.** Flagging this as explicitly out of scope, not silently
  skipped — some public photo tools do this automatically, this design doesn't attempt it. If
  it matters, the mitigation is editorial (the moderation pass is a human looking at every photo
  anyway, and can reject or ask for a re-crop of anything that clearly endangers someone) rather
  than automated.

## Additional features worth considering

A few things beyond what was asked, in rough order of how much they'd add for how little they
cost:

- **A dedicated `/photos` browsing page**, not just the two cycling blocks. The blocks are
  necessarily teasers (20-30 items, shuffled); a full page — reusing `better_exposed_filters`
  (already installed, already used elsewhere in this codebase) to filter by category — gives
  people a way to actually browse everything, especially once the pool grows past what a cycling
  block can reasonably show. Same view, same content type, just a `page_1` display alongside the
  block displays — the same "one view, multiple displays" shape as `location_page` already uses.
- **Core Flood API limits** on the submission form, alongside honeypot + CAPTCHA — caps
  submissions per IP per hour. Honeypot/CAPTCHA stop bots; flood control is the backstop against
  a person (or a bot that gets past CAPTCHA) hammering the form. Cheap to add, same submit
  handler.
- **Image size/dimension caps** on upload, matching the `2000x2000` cap already established for
  event images — consistency, and keeps a phone's 12MB original from being what gets stored and
  served.
- **OCR of sign text, for future search/filtering** — flagging as a "revisit later, not now"
  item, same posture as this repo's own auto-tag doc treats LLM-based tagging: genuinely useful
  (searching for a sign by what it says), but non-deterministic and worth waiting to see if
  manual captions already cover the need before adding an OCR dependency.
- **Duplicate-submission detection** (same file hash submitted twice) — minor, only worth adding
  if it turns out to actually happen; not designing it speculatively.

## Interaction with the tag-suggest task

`auto-tag-task.md`'s `TagSuggester` is built specifically against the `tags` vocabulary and
title/body text matching — it has no natural input here (photos don't have a body to match
against), so there's no reuse, just noting the two designs don't collide. If photo captions ever
get long enough to be worth auto-suggesting categories from, that would be a new, separate
matcher against `photo_categories`, not an extension of `TagSuggester`.

## Sequence

**Phase 1 — content model and admin-curated display**

1. `photo_categories` vocabulary (auto_create, gated same as `locations`); seed with an initial
   term list if you want a starting point beyond what auto-create will grow organically.
2. `community_photo` node type + fields (image w/ focal point, caption,
   `field_gallery_placement` checkboxes, `field_photo_tags`, submitter name + "credit me
   publicly" checkbox). No node-level alt-text field — Media's own is used, per "Alt text."
3. EXIF-stripping via `hook_file_insert()`, per the implementation plan above — orientation-fix
   then GD re-encode, applied to every upload path.
4. `drupal/views_bulk_operations` + `drupal/dropzonejs` (confirm asset-packagist reachability
   first, per the existing pre-launch checklist item).
5. Moderation queue view (`/admin/content/photos`) with VBO: bulk publish, bulk unpublish/delete,
   and the custom "apply category to selected" Action plugin.
6. Term moderation queue for `photo_categories` — check first whether `tags-review` can be
   generalized to cover both vocabularies (see "Term moderation queue") before building a
   parallel view. Needed before phase 2 ships, not blocking phase 1's admin-only bulk upload.
7. Bulk-upload your Dropbox activism photos and signs — via Dropzone if installed, via Media
   Library's multi-file picker if not — then tag/caption/alt-text each through the moderation
   view, ideally with a Claude-in-Chrome pass alongside you (see "AI-assisted captioning"),
   finishing with a bulk-publish action once a batch is ready.
8. "Photo Gallery" view: base filter (published, bundle), two block displays (Protest Signs,
   Community Photos) each filtered on `field_gallery_placement`, plus a `page_1` display for the
   `/photos` browse page (see "Additional features").
9. `photo-carousel.js` — client-side shuffle, cycle, pause-on-hover, `prefers-reduced-motion`
   guard, click-through into the existing lightbox.
10. Place both blocks in their regions.

**Phase 2 — public submission**

11. Submission route/form: image (Dropzone if installed), caption, gallery-placement checkboxes
    (required), topic tags (optional, auto-create), submitter name + credit-me-publicly
    checkbox, alt text (with the specific prompt above), honeypot + CAPTCHA + Flood API limits.
12. Anonymous unpublished-by-default + presave gate, mirroring the locations pattern. No bypass
    permission — everyone's submission queues into the same moderation view built in step 5.
13. `ddev drush cst`, review, `ddev drush cex -y`.

## Verify before calling it done

1. A freshly published photo appears in the correct block(s) (and only those) after cache clear —
   confirms each Views block display's `field_gallery_placement` filter is scoped correctly.
2. Reload the page with the block several times; order visibly changes, set of photos doesn't
   change unless a page cache boundary was crossed — confirms shuffle is client-side, not
   forcing a cache bypass.
3. `prefers-reduced-motion: reduce` in browser dev tools stops auto-advance; hovering/focusing a
   photo also pauses it.
4. Submit the public form with `field_gallery_placement` unchecked — confirms core's required
   validation actually blocks it, per the "don't let them proceed unless they choose at least
   one" ask. Then check just "Protest Sign," just "Community Photo," and both, and confirm each
   photo lands in the expected block(s).
5. Submit as anonymous, confirm node saves unpublished and doesn't appear in either block or the
   `/photos` page until approved.
6. Upload a phone photo with GPS EXIF data present. Check the **served** file (the one an actual
   page request returns, including the lightbox's full-size link), not just the local upload —
   confirm no EXIF survives, and confirm a portrait photo isn't rotated sideways by the
   orientation-fix step.
7. Type a brand-new `photo_categories` term as anonymous — confirms it auto-creates but saves
   unpublished, and is invisible in the autocomplete/filter until approved, mirroring how
   `locations` behaves today.
8. Submit without checking "credit me publicly," confirm the submitter name is not present
   anywhere in the rendered public page's markup (not just visually hidden).
9. `ddev drush cst`, review the diff, then `ddev drush cex -y`.

## Open questions for you

- Whether `tags-review`'s existing Views config can be generalized to a second vocabulary with a
  filter change, or needs a genuinely separate view for `photo_categories` — worth a quick look at
  the actual view before step 5a, since it changes how much work that step is.
- Whether the `/photos` browse page (under "Additional features") is worth building now alongside
  phase 1, or later once the two blocks exist and you can see whether people want to browse
  beyond what's cycling.
- Whether `event_contributor`/`event_manager` are the right roles to eventually grant the photo
  moderation-bypass permission to, once you decide to add one — not needed for phase 1 since
  nothing bypasses the queue yet.
