# Calendar Application (Drupal) — Implementation Plan

## Context

The site needs a public calendar where anonymous visitors can submit events (which queue for admin approval before going live) and can specify a location from a shared, growing list — also anonymous-extensible, also gated by approval. Events need to support recurrence (e.g. "every Tuesday"), locations need a map, and visitors should be able to add an event to their own personal calendar (Google/Outlook/iCal).

Confirmed clean slate: no existing `calendar_event` content type, `locations` vocabulary, or related fields/views in `config/sync`. This is the `apc3` project (local DDEV instance at `apc3.ddev.site`, used for development/testing of austinprogressivecalendar.com — "APC"). Per this repo's CLAUDE.md, `web/modules/custom` doesn't exist here at all — there is no custom module to protect, and no custom theme either. Already enabled today (relevant to this plan): `smart_date` + `smart_date_recur` (v4.2.8), `honeypot`, `captcha` — see §1 for how this changes the composer/enable steps. Also installed: admin_toolbar, devel, module_filter, jquery_ui family.

Nearly everything below is achievable through Composer + Drupal configuration (content types, fields, Views, permissions). **Two requirements could not be met by configuration alone** and are handled by a small custom module, `web/modules/custom/apc_calendar` — see §3 and §5b. Everything else remains pure config.

Key architectural decision already made: **Locations are a taxonomy vocabulary, not a content type.** Reasoning: taxonomy terms can hold arbitrary fields exactly like nodes (address, geofield, approval flag), but core's entity-reference "autocomplete + create referenced entities if they don't exist" widget only supports auto-create natively for taxonomy-term targets. Replicating that inline "type a new one and it gets created" behavior for a node target would require Inline Entity Form for no functional gain.

Locked-in decisions from stakeholder discussion:
- Event approval: **simple route** — content type defaults to unpublished, admin reviews at `/admin/content`. No Content Moderation/Workflows module.
- Recurrence: **Smart Date** module + its recur submodule.
- New locations: typed by anonymous users, auto-created but hidden from selection until an admin approves them. **Revised during build** — the planned "filtered Views selection handler" approach is impossible (core's `ViewsSelection` does not implement `SelectionWithAutocreateInterface`, so a Views-backed handler cannot auto-create at all). Implemented instead with the taxonomy term's built-in **published status** as the approval flag, held unpublished by an `apc_calendar` presave hook. See §3.
- **Approval gate applies to anonymous submitters only.** A location typed by an authenticated user is published immediately. Registered users are a known, accountable set on this site, so the spam risk that motivates the gate does not apply to them, and making them wait for approval would be pointless friction. If open self-registration is ever enabled, revisit this — the presave check should then key on a permission (e.g. `!hasPermission('administer taxonomy')`) rather than on anonymity.
- Maps: **Geofield + Leaflet** (OpenStreetMap tiles, no API key/billing).
- Geocoding: **Geocoder module**, auto-populates lat/long from a typed address on save — no manual coordinate entry.
- Location detail page: full page with map, address, and an embedded list of upcoming events there.
- Add-to-calendar: **Add to Calendar (addtocal)** module — currently beta-only (no stable release yet); approved for use anyway since it's low-risk (pure link generation, no data storage) and the only no-code option.
- Geocoder identification: use the DDEV local URL (`apc3.ddev.site`) as the Nominatim User-Agent/Referer for now — **must be swapped for the real production domain (`austinprogressivecalendar.com` / `d9.austinprogressivecalendar.com`) before this goes live with real traffic.**
- ~~Anonymous submitters get **"View own unpublished content"** permission so they land on their own submission after posting, rather than a generic message.~~ **Abandoned during build — not fixable, and should not be.** Core's `NodeAccessControlHandler` explicitly refuses this permission for anonymous users. The reason is sound: every anonymous node is owned by uid 0, so "view own unpublished" would mean "view *every* pending submission from *every* visitor" — it would publish the entire moderation queue. Replaced by a session-scoped confirmation page at `/event-submitted` (§5b) that echoes back the submitter's own values. **Do not grant this permission to the anonymous role**; it has no effect and misrepresents the access model.

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
| ~~Approved~~ | ~~`field_approved`~~ | ~~Boolean~~ | — | **Dropped during build — do not create.** The term's built-in `status` (Published) base field does this job, and core already enforces it in the reference autocomplete and on the term page. A separate boolean would have needed custom code to be enforced *anywhere*. See §3. |

Approval state is therefore the standard **Published** checkbox on the term edit form. An unpublished location is invisible in the reference autocomplete and its term page returns 403 — both straight from core, no configuration required.

### 2b. Calendar Event content type (`/admin/structure/types/add`, machine name `calendar_event`)

- Keep default Title + Body.
- **Publishing options: uncheck "Published"** so new nodes default to unpublished — this is the entire moderation mechanic.

Fields (`/admin/structure/types/manage/calendar_event/fields`):

| Field | Machine name | Type | Cardinality | Widget/settings |
|---|---|---|---|---|
| Event Date | `field_event_date` | Smart date range | **Unlimited (-1)** | Recurring instances are stored as field deltas, so cardinality must be unlimited. Widget: Smart date range, check "Allow recurring date values" |
| Location | `field_location` | Entity reference → taxonomy term | 1 | Widget: Autocomplete (Tags style). Target bundle: `locations`. Configured fully in section 3 |

---

## 3. Location approval mechanic — REVISED DURING BUILD

> **The original plan for this section does not work.** It called for a Views-backed entity-reference selection handler filtered to approved terms, with auto-create enabled. Core's `ViewsSelection` does not implement `SelectionWithAutocreateInterface`, so the "Create referenced entities if they don't exist" checkbox is simply not available on that handler. Views-filtered selection and auto-create are mutually exclusive in core. The section below is what was actually built.

### 3a. Approval flag = the term's `status` field

Core already does almost all of this, which is why the custom `field_approved` was dropped:

- `TermSelection::buildEntityQuery()` adds `$query->condition('status', 1)` for anyone lacking `administer taxonomy` → unpublished locations never appear in the autocomplete.
- `TermSelection::validateReferenceableNewEntities()` mirrors that check → an unpublished term can't be smuggled in by typing its exact name.
- `TermAccessControlHandler::checkAccess()` requires `$entity->isPublished()` for `view` → an unapproved location's term page is 403 for the public.

### 3b. Field configuration
On `field_location` (edit field settings):
- Reference type → **Default** (`default:taxonomy_term`), target bundle `locations`.
- Check **"Create referenced entities if they don't exist"**.

The `location_reference` view from the original 3a is **not used** and should be deleted.

### 3c. The one piece core gets wrong, and the module that fixes it

`TermSelection::createNewEntity()` calls `$term->setPublished()` unconditionally on auto-created terms — so without intervention every anonymously-typed location goes live instantly. `web/modules/custom/apc_calendar` closes exactly that gap and nothing else:

```php
function apc_calendar_taxonomy_term_presave(TermInterface $term): void {
  if ($term->isNew() && $term->bundle() === 'locations') {
    if (\Drupal::currentUser()->isAnonymous()) {
      $term->setUnpublished();
    }
  }
}
```

**Anonymous only, by design.** Locations created by authenticated users are published immediately — see the decision note in the Context section. If open self-registration is ever turned on, change the condition to `!\Drupal::currentUser()->hasPermission('administer taxonomy')`.

**Known limitation:** because unapproved locations are invisible, a second submitter typing the same location name creates a *duplicate* term rather than matching the pending one. Duplicates accumulate until an admin approves or merges them. This is inherent to the gate — the alternative (showing unapproved terms in the autocomplete) is what the gate exists to prevent. Watch the pending queue (§7b) for near-duplicate names.

### 3d. Anonymous permissions (`/admin/people/permissions`, Anonymous row)
- Check **"Create Calendar Event content"**.
- Check **"Create terms in Locations"** — this is what core's entity-reference autocreate checks (`$term->access('create')`); safe here because 3a–3c hide anything unapproved.
- ~~Check **"View own unpublished content"**~~ — **do not grant.** Core ignores it for anonymous users by design; see the Context section.
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

### 5a. Honeypot

Honeypot is already enabled site-wide with an existing `honeypot.settings.yml` (5s time limit, `element_name: url`, protecting `user_register_form`, `user_pass`, and the two contact forms — no node forms yet). At `/admin/config/content/honeypot`, check the new **"calendar_event"** row under Node forms to add `node_calendar_event_form: true` to `form_settings`. Leave the existing time limit/element defaults as-is — they're fine for this form too.

### 5b. Post-submission confirmation & admin notification — ADDED DURING BUILD

Both live in `apc_calendar`; neither is achievable with configuration alone.

**Confirmation page (`/event-submitted`).** Replaces the original plan's "land on your own unpublished node," which core prohibits (see Context). Without this, core's `NodeForm::save()` falls back to bouncing the submitter to the front page with a "Calendar Event *X* has been created" message that reads as though the event is already live.

- `hook_form_node_calendar_event_form_alter()` appends a submit handler **after** `::save`, so its redirect wins over the one `NodeForm` sets.
- That handler clears the misleading status message, writes the new node ID to the **private tempstore**, and redirects to the `apc_calendar.event_submitted` route.
- `EventSubmittedController` reads the ID back from the tempstore and renders the submitter's own values.

Two constraints on that controller worth preserving if it's ever refactored:

1. **The node ID must travel through the tempstore, not a query parameter.** An enumerable `?nid=` on an unpublished node would recreate precisely the leak core is preventing. It must be `tempstore.private`, not `tempstore.shared`: for anonymous users core stores a random per-session owner token (`core.tempstore.private.owner`) and prefixes the storage key with it, so simultaneous submitters write to distinct rows and `get()` re-verifies the owner on read. Concurrent anonymous submissions cannot collide.
2. **The response must not be cached.** It renders session-scoped unpublished content; if it enters the anonymous page cache, one submitter's pending event is served to the next visitor. Enforced twice — `no_cache: TRUE` on the route and `max-age: 0` + `session` cache context on the render array.
3. **The 30-minute freshness window is a privacy control, not a tidiness one.** `tempstore.expire` defaults to a week, so without the window a submission stays readable to whoever uses that browser next — a real exposure on a shared library or community-centre terminal. Do **not** address this by lowering `tempstore.expire`: that container parameter is shared with Views UI and every other private tempstore consumer. Do not address it by deleting the entry after first render either — a refresh or back-button would then silently degrade to the generic message. The timestamp is stored alongside the node ID and checked in `loadSubmittedNode()`.

Fields are rendered individually rather than through the node view builder, so the Smart Date formatter still applies but no node links appear — the canonical node URL and a freshly auto-created location term both 403 for the person who just submitted them.

Side effect to be aware of: writing to the tempstore starts a session for that visitor, which disables the anonymous page cache for the rest of their browsing session. Only affects people who actually submit.

**Admin notification.** `hook_node_insert()` mails the site address (override with `drush state:set apc_calendar.notify_email <address>`) whenever an anonymous visitor creates an unpublished `calendar_event`, with a direct edit link and a link to the pending queue. Without it, nothing signals that the queue at §7a has items in it, and submissions sit unreviewed. Mail sends synchronously inside the form submit — if a slow production SMTP hop makes that visible to submitters, move it to a queue worker.

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
Taxonomy term view, Vocabulary = Locations, machine name `pending_locations`. Page display at `/admin/content/locations-pending`, access permission: "Administer taxonomy". Admin clicks through, checks **Published**, saves.

- **Filter: `Published = No`** (not `field_approved = No` — that field was dropped; see §2a/§3).
- **Fields: Name** (linked to term edit form) **+ Address.** Not "Created": taxonomy terms have no `created` base field in core, unlike nodes. Sort by **Term ID descending** for newest-first, which is what "Created" was there to provide. Terms *do* have a `changed` base field if a timestamp column is genuinely wanted.
- Because unapproved locations are invisible to submitters, near-duplicates will show up here (§3c). Scan for them before approving.

---

## 8. Config export (final step, only after full manual verification)

```bash
ddev drush cex -y
git status   # review new/changed files under config/sync before committing
```

Expect new files including: `node.type.calendar_event.yml`, `core.base_field_override.node.calendar_event.status.yml` (the unpublished-by-default mechanic — §2b), `field.storage.node.field_event_date.yml`, `field.field.node.calendar_event.field_event_date.yml`, `field.storage.node.field_location.yml`, `field.field.node.calendar_event.field_location.yml`, `taxonomy.vocabulary.locations.yml`, `field.storage.taxonomy_term.field_address.yml`, `field.storage.taxonomy_term.field_geofield.yml` (+ bundle configs), `core.entity_view_mode.taxonomy_term.card.yml`, `core.entity_view_display.taxonomy_term.locations.card.yml`, `core.entity_view_display.node.calendar_event.default.yml`, `views.view.calendar.yml`, `views.view.location_upcoming_events.yml`, `views.view.pending_locations.yml`, `block.block.olivero_views_block__location_upcoming_events_block_1.yml`, `geocoder.geocoder_provider.nominatim.yml`, `geocoder.settings.yml`.

Deviations from the original expected set, all consequences of §3:

- **No `field.storage.taxonomy_term.field_approved.yml`** or its bundle config — field dropped.
- **No `views.view.location_reference.yml`** — the Views selection handler approach was abandoned. If this file was already created during an earlier build pass, delete both the view and the config file.
- `user.role.anonymous.yml` is **modified**, and its diff should add only `create calendar_event content` and `create terms in locations`. If `view own unpublished content` appears, remove it — it does nothing for anonymous and misrepresents the access model.
- `core.extension.yml` is **modified** to enable `apc_calendar` alongside the contrib modules.

Also expect a **modified** (not new) `honeypot.settings.yml` — diff should show only the added `node_calendar_event_form: true` line under `form_settings`, everything else unchanged. `ddev drush cst` before export should show a clean baseline (config already in sync) aside from these changes.

Note that the `apc_calendar` module itself is **code, not config** — it is committed under `web/modules/custom/` and does not appear in a `cst`/`cex` diff beyond the `core.extension.yml` line that enables it.

---

## Critical files

- `/Users/selwyn/Sites/apc3/composer.json` — module requires
- `/Users/selwyn/Sites/apc3/config/sync/` — destination for all exported config listed above (188 files pre-existing; see §8 for the expected new/changed set)
- `/Users/selwyn/Sites/apc3/web/modules/custom/apc_calendar/` — the only custom code in this build:
  - `apc_calendar.module` — location approval presave (§3c), submit redirect (§5b), admin notification + `hook_mail()` (§5b)
  - `apc_calendar.routing.yml` — the `/event-submitted` route
  - `src/Controller/EventSubmittedController.php` — session-scoped confirmation page

  This is the first custom module in the repo. Per CLAUDE.md the site was a vanilla, config-only build; that is no longer strictly true, and deployments must now account for code as well as config.

---

## Verification plan

Work through in order, anonymous/incognito session alongside an authenticated admin session:

**(a) Anonymous submission with a brand-new location**
1. As anonymous, `/node/add/calendar_event`: fill Title/Body/Date, type a brand-new Location name, let it autocreate.
2. Submit (pause a few seconds before submitting — Honeypot's time check can reject too-fast submissions). Confirm no errors, and you land on **`/event-submitted`** showing your own submitted values and a "pending review" message — *not* the front page, and *not* a "has been created" message.
3. Confirm the admin notification email arrived (`ddev launch -m` for Mailpit locally) with a working edit link.
4. As admin: `/admin/content?type=calendar_event&status=2` shows the node, unpublished.
5. `/admin/content/locations-pending` shows the new term, unpublished.
6. From a second anonymous session, confirm the new location does **not** appear in the Location autocomplete yet.
7. **Cache-leak check (important).** From that second anonymous session, visit `/event-submitted` directly — it must show the *generic* thank-you with no event details. Seeing the first submitter's event here means the no-cache guards have been broken.
8. **Freshness-window check.** Back in the first session, refresh `/event-submitted` — the event details must still render. Then expire the entry without waiting 30 minutes by temporarily lowering `FRESHNESS_WINDOW` to a few seconds (or by editing the stored `time` value), reload, and confirm it falls back to the generic thank-you. Restore the constant afterwards.
9. **Concurrency check.** Submit from two anonymous sessions in different browsers (not two tabs of one browser — they share a session). Each `/event-submitted` must show only its own event.
10. As an authenticated non-admin (if any such role exists), submit an event with a new location — that location should be published immediately, per the §3c decision.

**(b) Approval flow**
1. As admin, edit the new term, fill in a real address, save — confirm `field_geofield` auto-populates.
2. Check **Published**, save. (Confirm saving as admin does *not* re-unpublish it — the presave hook is guarded on `isNew()`.)
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
