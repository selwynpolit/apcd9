# Homepage redesign — implementation task

Scoped in a Cowork conversation with Selwyn (Sept 2026), starting from a Design canvas
mockup of two homepage concepts. Selwyn picked "Concept A — The Bulletin Board":
https://claude.ai/artifact/L1dKmcsXy5RukThB46mSSe
Everything below reflects decisions he already made — this doc is the brief for whoever
(Claude Code or otherwise) actually builds it. Nothing in the codebase has been touched yet.

## Goal

Replace the stock Drupal core "Frontpage" view (currently a flat teaser list of anything with
`promote` checked — mixes events and memes with no distinction, empty-state text is the default
"Welcome to [site:name]") with a purpose-built front page in a whimsical "bulletin board" style:
a hero, an upcoming-events strip, one featured event, and a single blended wall of recent
signs/memes/community photos, plus real submission CTAs.

## Content model recap (already exists — no schema/field changes needed for any of this)

- `calendar_event`: `field_event_date`, `field_event_image`, `field_location`, `field_virtual`,
  `field_tags`, plus core base fields `promote` ("Promoted to front page") and `status`.
- `community_photo` ("Community Photo/Signs"): `field_photo_image`, `field_caption`,
  `field_submitter_name`, `field_credit_publicly`, `field_photo_tags`,
  `field_gallery_placement` (`protest_sign` | `community_photo`).
- `views.view.photo_gallery` already has `block_protest_signs` and `block_community_photos`
  block displays (currently placed on `/calendar` only, via
  `block.block.apc_brown_calendar_protest_signs_mini` /
  `..._community_photos_mini`). The homepage wall is a **new, third** display on this same view
  that blends both placements — do not repurpose the existing two, they're still used on
  `/calendar`.
- `views.view.calendar` (`fullcalendar_view` style) has `page_1` at `/calendar` and
  `page_manage` at `/calendar/manage`. The month/week/day/list toggle on `/calendar` is
  FullCalendar's own client-side view switch (JS), **not** separate Drupal routes — there is
  currently no plain chronological "upcoming events" URL, hence the new `page_upcoming` display
  below.
- Theme: `apc_brown` (forked from Olivero). Real logo/mascot file:
  `web/themes/custom/apc_brown/images/apc_logo.jpg` — use this, not an invented mascot graphic.
  Palette (from `css/base/variables.css`): cream `#f5ead8` (ground), tan `#ebddc5` (surface),
  deep brown ink (`--color--gray-5`/`-10`/`-20` range), rust primary `hsl(27,45%,37%)`
  (~`#8a5333`), plus named accents red `#e33f1e`, gold `#fdca40`, green `#3fa21c`.
- `apc_calendar` custom module already has an established `src/Controller/` directory with
  several controllers (`EventSubmittedController`, `PhotoSubmittedController`,
  `PublishEventAndLocationController`, etc.) and its own `apc_calendar.routing.yml` — the new
  front-page controller/route belongs alongside these, following their existing conventions
  (check one of them for house style before writing the new one).
- `apc_brown.theme` currently has no `apc_brown_theme()` hook implementation (only
  `apc_brown_theme_suggestions_*_alter()` and `apc_brown_preprocess_*()` hooks exist) — this
  needs to be added if the front page is themed as a render array (`#theme => 'apc_front_page'`)
  rather than rendered via a `page--front.html.twig` page-template override. Pick whichever
  matches how `event_detail`/`location_page` custom templates are already wired for consistency
  (check those first).

## Decisions already made with Selwyn — do not re-litigate these

1. **Featured event** = the soonest upcoming `calendar_event` with `promote = 1` (Drupal's
   built-in "Promoted to front page" checkbox — no new field). If none are both promoted and
   upcoming, fall back to the single soonest upcoming `calendar_event` regardless of `promote`.
2. **Upcoming events strip**: try a 7-day window from today first. If that query returns zero
   results, drop the 7-day upper bound and just show the next N upcoming events regardless of how
   far out they are. Cap display at 4 cards either way. Label it "Coming up", not "This week" —
   the copy needs to stay true when the fallback kicks in.
3. **Signs/memes wall**: one blended grid, not split into labeled groups — mixes
   `protest_sign` and `community_photo` placements together, newest first, capped at 6.
4. **Sign shuffle**: real `Drupal.behaviors` JS, no AJAX endpoint. Server-render a pool of the
   ~8 most recent signs into the page (hidden), JS behavior swaps which one is shown on click.
   Trade-off already accepted: shuffles within that rendered pool, not the full gallery.
5. **New "upcoming events only" page**: add a `page_upcoming` display to the existing
   `calendar` view at `/calendar/upcoming` — plain chronological list (no calendar grid),
   filtered `field_event_date >= now`, sorted ascending, with a pager. This is the "browse
   upcoming events" CTA target; `/calendar` remains the "browse the full calendar" CTA target.
6. Fonts in the comp: Anton (display), Permanent Marker (sign captions), Karla (body) — self-host
   in the theme's existing `fonts/` directory rather than a Google Fonts CDN link, for
   consistency and to avoid an external request. Confirm with Selwyn if that's still fine.

## Build plan

### 1. Views changes (via the local DDEV admin UI, then `drush cex` — this repo's normal
   config workflow per its own CLAUDE.md; do not hand-author these two as raw YAML)

- **`photo_gallery` view**: add a new block display (e.g. `block_recent_all`) — same base
  filters as the existing gallery displays (published), but with no `field_gallery_placement`
  filter, so both placements are included. Sort `created` DESC, no pager, limit 6.
- **`calendar` view**: add a new page display `page_upcoming` at path `calendar/upcoming` —
  filter `field_event_date` >= now, sort ascending, page pager, row = teaser or a lighter
  "upcoming_list" view mode if one doesn't already exist.
- Featured event and the 7-day-with-fallback upcoming strip are branching logic, not a single
  static filter set — build those as PHP entity queries in the controller (below), not as Views,
  to avoid two near-duplicate displays for the "events this week" / "no events this week" cases.

### 2. New front page route + controller

- Add a route + controller to `apc_calendar` (e.g. `apc_calendar.front_page` →
  `Drupal\apc_calendar\Controller\FrontPageController::build()`), matching the existing
  controllers' conventions in that module.
- Controller responsibilities: run the featured-event query (with fallback), the upcoming-events
  query (7-day window with fallback), load the `photo_gallery` `block_recent_all` display
  result, and pass all three into a themed template.
- Update `system.site.yml`: `page.front` → the new route's path.

### 3. Template + theme work (`apc_brown`)

- New template implementing the bulletin-board layout: hero (mascot + shuffle placard), "Coming
  up" card row, featured spotlight, blended signs/memes wall, footer CTA band. Use the real
  `apc_logo.jpg` mascot art.
- New component stylesheet (e.g. `css/components/front-page.css` + its `.pcss.css` pair,
  matching this theme's existing per-component file convention).
- New library entry in `apc_brown.libraries.yml` for the front-page CSS + shuffle JS.
- New JS behavior (e.g. `js/sign-shuffle.js`): `Drupal.behaviors.apcSignShuffle` cycles through
  the server-rendered pool of recent signs on click of the "Shuffle" button.

### 4. CTAs on the page (real routes)

- "Browse the full calendar" → `/calendar`
- "See upcoming events" → `/calendar/upcoming` (new)
- "See the full wall of signs" → `/photos`
- "Submit a photo, meme or sign" → `/node/add/community_photo`
- "Add an event" → `/node/add/calendar_event`

### 5. Verification checklist

- `ddev drush cr`, visit `/` — hero, coming-up strip, featured card, blended wall, footer CTAs
  all render with real data.
- Toggle `promote` on a couple of upcoming events in the admin UI — confirm the featured card
  follows it, and falls back correctly when nothing is promoted.
- Force the "no events in next 7 days" case (temporarily, or just test on a slow week) — confirm
  "Coming up" falls back to next-N instead of rendering empty.
- Visit `/calendar/upcoming` directly — plain list, correct sort/filter, pager works.
- Click "Shuffle" a few times — cycles through different signs from the pool.
- `ddev drush cex -y`, `ddev drush cst` clean, review the Views config diff before committing.
- Per this repo's own workflow: work on `develop`, merge into `main` for deploy; this .md file
  gets moved to `assets/` once the feature ships (see CLAUDE.md's note on feature `.md` files).

## Open items to confirm with Selwyn during/after build

- Confirm self-hosting the three fonts vs. a Google Fonts CDN link (leaning self-host).
- Confirm the front-page route path / whether it should literally own `/` or sit at a distinct
  path with `page.front` pointed at it — check how other custom front-page-like routes (if any)
  are declared in this codebase first.
