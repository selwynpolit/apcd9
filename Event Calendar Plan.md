# Calendar Application (Drupal) — Implementation Plan

## Context

The site needs a public calendar where anonymous visitors can submit events (which queue for admin approval before going live) and can specify a location from a shared, growing list — also anonymous-extensible, also gated by approval. Events need to support recurrence (e.g. "every Tuesday"), locations need a map, and visitors should be able to add an event to their own personal calendar (Google/Outlook/iCal).

Confirmed clean slate: no existing `calendar_event` content type, `locations` vocabulary, or related fields/views in `config/sync`. This is the `apc3` project (local DDEV instance at `apc3.ddev.site`, used for development/testing of austinprogressivecalendar.com — "APC"). Per this repo's CLAUDE.md, `web/modules/custom` doesn't exist here at all — there is no custom module to protect, and no custom theme either. Already enabled today (relevant to this plan): `smart_date` + `smart_date_recur` (v4.2.8), `honeypot`, `captcha` — see §1 for how this changes the composer/enable steps. Also installed: admin_toolbar, devel, module_filter, jquery_ui family.

Everything below is achievable through Composer + Drupal configuration (content types, fields, Views, permissions) — **no custom module or PHP code required.**

Key architectural decision already made: **Locations are a taxonomy vocabulary, not a content type.** Reasoning: taxonomy terms can hold arbitrary fields exactly like nodes (address, geofield, approval flag), but core's entity-reference "autocomplete + create referenced entities if they don't exist" widget only supports auto-create natively for taxonomy-term targets. Replicating that inline "type a new one and it gets created" behavior for a node target would require Inline Entity Form for no functional gain.

Locked-in decisions from stakeholder discussion:
- Event approval: **simple route** — content type defaults to unpublished, admin reviews at `/admin/content`. No Content Moderation/Workflows module.
- Recurrence: **Smart Date** module + its recur submodule.
- New locations: typed by anonymous users, auto-created but hidden from selection until an admin approves them (pure config via a filtered Views selection handler — no custom code).
- Maps: **Geofield + Leaflet** (OpenStreetMap tiles, no API key/billing).
- Geocoding: **Geocoder module**, auto-populates lat/long from a typed address on save — no manual coordinate entry.
- Location detail page: full page with map, address, and an embedded list of upcoming events there.
- Add-to-calendar: **Add to Calendar (addtocal)** module — currently beta-only (no stable release yet); approved for use anyway since it's low-risk (pure link generation, no data storage) and the only no-code option.
- Geocoder identification: use the DDEV local URL (`apc3.ddev.site`) as the Nominatim User-Agent/Referer for now — **must be swapped for the real production domain (`austinprogressivecalendar.com` / `d9.austinprogressivecalendar.com`) before this goes live with real traffic.**
- Anonymous submitters get **"View own unpublished content"** permission so they land on their own submission after posting, rather than a generic message.

---

## 1. Composer requires

`smart_date`, `smart_date_recur`, and `honeypot` are **already required (composer.json) and already enabled** on this site — skip requiring/enabling those three, just confirm current state (below) before proceeding. Only the following are actually new:

```bash
ddev composer require 'drupal/address:^2.0'
ddev composer require 'drupal/geofield:^10.3'
ddev composer require 'drupal/leaflet:^10.4'
ddev composer require 'drupal/geocoder:^4.34'
ddev composer require 'geocoder-php/nominatim-provider'
ddev composer require 'drupal/addtocal:^3.0@beta'
ddev composer require 'drupal/fullcalendar_view:^5.2'
```

Do **not** install `drupal/smart_date_calendar_kit` — it ships its own opinionated "Events" content type/views that would conflict with the purpose-built `calendar_event` type here.

Confirm the already-enabled state and check the new submodule names (release-to-release these occasionally shift):
```bash
ddev drush pm:list --status=enabled | grep -iE 'smart_date|honeypot|captcha'
ddev drush pm:list --status=disabled | grep -iE 'geocoder|address|geofield|leaflet|addtocal|fullcalendar'
```
Expect `smart_date` and `smart_date_recur` already in the enabled list. Expect `geocoder_field`, `geocoder_geofield`, `geocoder_address` (geocoding pipeline) to appear disabled, pending enable below. Confirm before running the enable command — if a name doesn't match, adjust rather than guessing.

```bash
ddev drush pm:enable address geofield leaflet geocoder geocoder_field geocoder_geofield geocoder_address addtocal fullcalendar_view -y
ddev drush cr
```

---

## 2. Content model

### 2a. Locations vocabulary (`/admin/structure/taxonomy/add`, machine name `locations`)

Fields (`/admin/structure/taxonomy/manage/locations/overview/fields`):

| Field | Machine name | Type | Cardinality | Notes |
|---|---|---|---|---|
| Address | `field_address` | Address | 1 | Default settings/all countries unless told otherwise |
| Coordinates | `field_geofield` | Geofield | 1 | Widget: Geofield Latitude/Longitude — auto-populated by Geocoder, rarely hand-typed |
| Approved | `field_approved` | Boolean | 1 | **Default value: unchecked (0)** — this is the crux of the approval mechanic; verify carefully. On/off labels: "Approved" / "Not approved" |

Keep `field_approved` visible on the term edit form for admins. Autocreated terms (from anonymous submissions) bypass the form entirely and get the field default (0) applied at storage level regardless of form display.

### 2b. Calendar Event content type (`/admin/structure/types/add`, machine name `calendar_event`)

- Keep default Title + Body.
- **Publishing options: uncheck "Published"** so new nodes default to unpublished — this is the entire moderation mechanic.

Fields (`/admin/structure/types/manage/calendar_event/fields`):

| Field | Machine name | Type | Cardinality | Widget/settings |
|---|---|---|---|---|
| Event Date | `field_event_date` | Smart date range | **Unlimited (-1)** | Recurring instances are stored as field deltas, so cardinality must be unlimited. Widget: Smart date range, check "Allow recurring date values" |
| Location | `field_location` | Entity reference → taxonomy term | 1 | Widget: Autocomplete (Tags style). Target bundle: `locations`. Configured fully in section 3 |

---

## 3. Location approval mechanic (build in this order — the view must exist before the field references it)

### 3a. Selection-handler view
`/admin/structure/views/add` → View of **Taxonomy term**, machine name `location_reference`.
- No Page display — add display type **Entity Reference** instead.
- Filter: `Approved (field_approved) = Yes`.
- Entity Reference display settings: Search fields = Name; IDs/Label field = default (term ID / Name).

### 3b. Wire the field to it
On `field_location` (edit field settings):
- Reference type → **"Views: Filter by an entity reference view"** → view `location_reference`, display `Entity Reference`.
- Check **"Create referenced entities if they don't exist"**, label field = Name.

Net effect: autocomplete only ever offers approved terms; typing a brand-new name autocreates a term (autocreate bypasses the filtered view) that lands with `field_approved = 0`, invisible in the same autocomplete until an admin approves it.

### 3c. Anonymous permissions (`/admin/people/permissions`, Anonymous row)
- Check **"Create Calendar Event content"**.
- Check **"Create terms in Locations"** — this is what core's entity-reference autocreate checks (`$term->access('create')`); safe here because 3a/3b hide anything unapproved.
- Check **"View own unpublished content"** (confirmed UX decision — lets a submitter see their own pending submission).
- Do **not** grant edit/delete on terms, "Administer taxonomy", or edit on Calendar Event content.

---

## 4. Geocoding & maps

### 4a. Geocoder provider
`/admin/config/system/geocoder/geocoder-provider` → Add provider, type **Nominatim**.
- Root URL: `https://nominatim.openstreetmap.org`
- User-Agent / Referer: `apc3.ddev.site` (placeholder for local dev — **swap for the real production domain (`austinprogressivecalendar.com`) before launch**; Nominatim's public instance is rate-limited/ToS-restricted, so also reconsider a paid/self-hosted provider if traffic grows beyond trivial).

### 4b. Wire geocoding on `locations`
Via `geocoder_field`'s per-bundle config surface (exact admin path may vary slightly by installed sub-version — confirm on the Manage Fields screen for the `locations` bundle once installed):
- Source: `field_address` → Target: `field_geofield`, Provider: Nominatim, Method: server-side (geocodes automatically on save, no JS needed).
- Test by editing a term's address and saving — confirm `field_geofield` populates.

### 4c. Map on the Location page
New view mode for Taxonomy Term: `card` (`/admin/structure/display-modes/view`). Configure both `card` and the default "Full content" view mode for the `locations` bundle:
- `field_geofield` → formatter **Leaflet Map**, OSM Mapnik tiles.
- `field_address` → default Address formatter.
- Hide `field_approved` (internal only).

Embed the "upcoming events here" view (section 5b) as a **Block** placed in the Content region via Block Layout, visibility: Request Path = `/taxonomy/term/*`. Its contextual filter resolves the term ID from the URL automatically via Views' "Taxonomy term ID from URL" default-argument plugin.

### 4d. Map on the Event page
Calendar Event → Manage Display: set `field_location` formatter to **Rendered entity**, view mode **Card** (built in 4c) — reuses the same map+address block.

---

## 5. Anonymous submission & spam

Honeypot is already enabled site-wide with an existing `honeypot.settings.yml` (5s time limit, `element_name: url`, protecting `user_register_form`, `user_pass`, and the two contact forms — no node forms yet). At `/admin/config/content/honeypot`, check the new **"calendar_event"** row under Node forms to add `node_calendar_event_form: true` to `form_settings`. Leave the existing time limit/element defaults as-is — they're fine for this form too.

---

## 6. Public display

### 6a. `/calendar` — FullCalendar view
Content view, type = Calendar Event, Published = Yes, display type **FullCalendar**, path `/calendar`. Map Start/End to `field_event_date`'s `value`/`end_value`.
- **Verify at build time**: confirm the field-mapping dropdown actually offers Smart Date's sub-fields as Start/End options — if not, check Smart Date's "Smart Date and Views" documentation for the correct exposed properties rather than guessing.
- Deselect "Show multiple values in the same row" on the date field's Views settings so recurring instances render as separate calendar entries.

### 6b. "Upcoming events at this location" view (feeds 4c)
Content view, type = Calendar Event, machine name `location_upcoming_events`.
- Filters: Published = Yes; `field_event_date` end/value ≥ "now" (relative date).
- Contextual filter: Has taxonomy term ID (on `field_location`), default "Taxonomy term ID from URL".
- Sort: `field_event_date` ascending. Display: Block only.

---

## 7. Admin review queues

### 7a. Unpublished events
Reuse `/admin/content?type=calendar_event&status=2` — no custom view needed. Optional later polish: a bookmarkable filtered view with bulk-publish, only if `/admin/content` proves tedious.

### 7b. Pending locations (required)
Taxonomy term view, Vocabulary = Locations, `field_approved = No`, machine name `pending_locations`. Fields: Name (linked to term edit form), Address, Created. Page display at `/admin/content/locations-pending`, access permission: "Administer taxonomy". Admin clicks through, checks Approved, saves.

---

## 8. Config export (final step, only after full manual verification)

```bash
ddev drush cex -y
git status   # review new/changed files under config/sync before committing
```

Expect new files including: `node.type.calendar_event.yml`, `field.storage.node.field_event_date.yml`, `field.field.node.calendar_event.field_event_date.yml`, `field.storage.node.field_location.yml`, `field.field.node.calendar_event.field_location.yml`, `taxonomy.vocabulary.locations.yml`, `field.storage.taxonomy_term.field_address.yml`, `field.storage.taxonomy_term.field_geofield.yml`, `field.storage.taxonomy_term.field_approved.yml` (+ bundle configs), `core.entity_view_display.taxonomy_term.locations.card.yml`, `core.entity_view_display.node.calendar_event.default.yml`, `views.view.location_reference.yml`, `views.view.calendar.yml`, `views.view.location_upcoming_events.yml`, `views.view.pending_locations.yml`, `geocoder.geocoder_provider.nominatim.yml`, `user.role.anonymous.yml`. Also expect a **modified** (not new) `honeypot.settings.yml` — diff should show only the added `node_calendar_event_form: true` line under `form_settings`, everything else unchanged. `ddev drush cst` before export should show a clean baseline (config already in sync) aside from these changes.

---

## Critical files

- `/Users/selwyn/Sites/apc3/composer.json` — module requires
- `/Users/selwyn/Sites/apc3/config/sync/` — destination for all exported config listed above (188 files pre-existing; see §8 for the expected new/changed set)

---

## Verification plan

Work through in order, anonymous/incognito session alongside an authenticated admin session:

**(a) Anonymous submission with a brand-new location**
1. As anonymous, `/node/add/calendar_event`: fill Title/Body/Date, type a brand-new Location name, let it autocreate.
2. Submit (pause a few seconds before submitting — Honeypot's time check can reject too-fast submissions). Confirm no errors, and you land on your own node page (unpublished, visible due to the granted permission).
3. As admin: `/admin/content?type=calendar_event&status=2` shows the node, unpublished.
4. `/admin/content/locations-pending` shows the new term, unapproved.
5. From a second anonymous session, confirm the new location does **not** appear in the Location autocomplete yet.

**(b) Approval flow**
1. As admin, edit the new term, fill in a real address, save — confirm `field_geofield` auto-populates.
2. Check Approved, save.
3. Re-test anonymous autocomplete — the location now appears.
4. Publish the pending event from `/admin/content`.
5. `/calendar` shows the published event on its correct date.

**(c) Maps**
1. Visit the term page — Leaflet map renders with correct pin, address shown, upcoming-events block lists the published event.
2. Visit the event node page — Location field renders the Card view mode (map + address).

**(d) Recurrence**
1. Create/edit an event with a recurring rule (e.g. weekly for several weeks).
2. Confirm multiple date instances generated on re-edit.
3. Confirm `/calendar` shows each occurrence as a separate entry.
4. Confirm the location's upcoming-events block reflects future occurrences correctly.

**(e) Add-to-calendar**
1. On a published event page, confirm Google Calendar/Outlook/Yahoo/.ics links render.
2. Click .ics — downloads/opens with correct date, title, location.
3. Click Google Calendar link — opens pre-filled with correct details.

Only after all five checks pass, run the `drush cex -y` export from section 8 and review the diff before committing.
