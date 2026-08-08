# Calendar Application (Drupal) — Implementation Plan

## Context

The site needs a public calendar where anonymous visitors can submit events (which queue for admin approval before going live) and can specify a location from a shared, growing list — also anonymous-extensible, also gated by approval. Events need to support recurrence (e.g. "every Tuesday"), locations need a map, and visitors should be able to add an event to their own personal calendar (Google/Outlook/iCal).

Confirmed clean slate: no existing `calendar_event` content type, `locations` vocabulary, or related fields/views in `config/sync`. This is the `apc3` project (local DDEV instance at `apc3.ddev.site`, used for development/testing of austinprogressivecalendar.com — "APC"). Per this repo's CLAUDE.md, `web/modules/custom` doesn't exist here at all — there is no custom module to protect, and no custom theme either. Already enabled today (relevant to this plan): `smart_date` + `smart_date_recur` (v4.2.8), `honeypot`, `captcha` — see §1 for how this changes the composer/enable steps. Also installed: admin_toolbar, devel, module_filter, jquery_ui family.

> **This section described the intended shape at planning time. It is no longer accurate — kept for the record.** The plan expected "no custom module or PHP code required." The build ended with a custom module, a custom theme, and a contrib patch. Each is justified in place; the honest summary is that the config-only ambition did not survive contact with anonymous submission, recurrence and theming.

The build now comprises:

- **Config** — content type, vocabulary, fields, views, displays, permissions (most of this plan).
- **`web/modules/custom/apc_calendar`** — the approval gate (§3c), a selection plugin (§3f), the confirmation page (§5b), the add-location modal (§5c), the calendar popup (§6c), and the directions field (§4c-quater). None of these are achievable in config; each section explains why.
- **`web/themes/custom/apc_brown`** — a fork of Olivero (§7c).
- **`patches/`** — one contrib patch (§6c).

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

### 1b. Front-end JS libraries — ADDED DURING BUILD

`fullcalendar_view` ships no libraries of its own. `fullcalendar_view_library_info_alter()` checks `file_exists()` on each expected path and **silently substitutes a CDN URL for anything missing** — so the calendar works out of the box while quietly loading JS from `unpkg.com` and `cdn.jsdelivr.net` on every page view. That is a supply-chain and visitor-privacy exposure, and it would break if `seckit`'s CSP is ever enabled (currently `checkbox: false`).

Libraries are now installed locally via [asset-packagist](https://asset-packagist.org), declared in `composer.json`:

- Repository `https://asset-packagist.org` plus `oomphinc/composer-installers-extender` and `extra.installer-types: ["npm-asset"]`.
- Explicit `installer-paths` entries mapping each `npm-asset/fullcalendar--*` package into the `web/libraries/fullcalendar/<plugin>/` layout the module expects. **These must precede the generic `web/libraries/{$name}` rule** — `mapCustomInstallPaths()` returns the first match.
- That generic rule needs `type:npm-asset` alongside `type:drupal-library`, otherwise transitive npm dependencies (`luxon`, `tslib`, pulled in by rrule) have no matching path and `composer install` dies with `Package type "npm-asset" is not supported`.

Two packages cannot be path-matched, because their npm layouts differ from what the module expects (`moment/min/moment.min.js` vs `/libraries/moment/2.29.4/moment.min.js`; `rrule/dist/es5/rrule.min.js` vs `/libraries/rrule/2.6.8/rrule.min.js`). `apc_calendar_library_info_alter()` repoints those two. Hook order is irrelevant: the module's own alter only substitutes a CDN when `file_exists()` fails, so once these point at real files it leaves them alone.

**Deployment consequence:** `web/libraries/` is gitignored, so these arrive via `composer install --no-dev` on GreenGeeks — which now requires `asset-packagist.org` to be reachable from the production host. Verify that before the first deploy that depends on it.

**JSFrame is deliberately not installed.** It is only attached when the `dialogWindow` display option is enabled (`FullcalendarViewPreprocess` line ~445), which this build does not use. Enabling that option later would silently reintroduce a CDN dependency.

Verify no CDN references remain after any change here:
```bash
curl -s https://apc3.ddev.site/calendar | grep -oE '(unpkg\.com|jsdelivr\.net)[^"]*'
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

### 3b. Field configuration — SUPERSEDED, see §3f

The build originally used the **Default** handler with "Create referenced entities if they don't exist" checked, so an unmatched name silently auto-created a term. That produced locations with a name and nothing else — no address, no way for the submitter to supply one.

Current configuration on `field_location`:

- Reference type → **`apc_locations`** (custom selection plugin, §3f)
- **`auto_create: false`** — the modal in §5c is now the only creation path, which is what makes its required fields enforceable

The `location_reference` view from the original 3a was never used and has been deleted.

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

**Anonymous only, by design.** Terms created by authenticated users are published immediately — see the decision note in the Context section. If open self-registration is ever turned on, change the condition to `!\Drupal::currentUser()->hasPermission('administer taxonomy')`.

**Restricted to `locations`, also by design — see §3e.** `field_tags` was added after the initial build and is deliberately *not* gated. Any other future reference field with `auto_create` that anonymous users can reach will publish terms immediately unless its bundle is added to this condition.

**Known limitation:** because unapproved locations are invisible, a second submitter typing the same location name creates a *duplicate* term rather than matching the pending one. Duplicates accumulate until an admin approves or merges them. This is inherent to the gate — the alternative (showing unapproved terms in the autocomplete) is what the gate exists to prevent. Watch the pending queue (§7b) for near-duplicate names. The modal in §5c mitigates it with a name-collision check that deliberately matches unpublished terms too.

### 3d. Anonymous permissions (`/admin/people/permissions`, Anonymous row)
- Check **"Create Calendar Event content"**.
- Check **"Create terms in Locations"** — harmless, but note the original rationale for it was **wrong**. This permission does *not* gate entity-reference autocreate. Nothing in the autocreate path checks create access: `EntityAutocomplete::validateEntityAutocomplete()` calls `$handler->createNewEntity()` unconditionally when `#autocreate` is set, and `EntityReferenceItem::preSave()` then calls `$this->entity->save()` unconditionally. **Withholding the permission would not have prevented anonymous term creation.** The gate is held entirely by the presave hook in §3c — that hook is load-bearing, not belt-and-braces.
- ~~Check **"View own unpublished content"**~~ — **do not grant.** Core ignores it for anonymous users by design; see the Context section.
- Do **not** grant edit/delete on terms, "Administer taxonomy", or edit on Calendar Event content.

### 3e. Tags — ADDED AFTER INITIAL BUILD, and deliberately NOT gated

`field_tags` (vocabulary `tags`, `auto_create: true`) was added to `calendar_event` to support filtering. Anonymous submitters can create tags, and **those tags are published immediately** — the opposite of the locations rule.

**Why the asymmetry.** An unapproved term is invisible in the autocomplete, so the next submitter who wants it simply retypes it and creates a second one. For locations that is a rare edge case (few people type the same venue name). For tags it is the *normal* case — many submitters will reach for "housing", "mutual aid", "city council" — and the result is a filter facet fragmented across near-duplicates. Core ships no term-merge UI, so that cleanup is manual and unbounded. A gate that reliably produces duplicates defeats the purpose of the field it is protecting.

Tags are therefore reviewed **after** the fact rather than before, at `/admin/content/tags-review` (`views.view.tags_review`): newest first by term ID, with an exposed name filter for spotting near-duplicates, `revision_created` and `revision_user` columns, and the VBO bulk form for delete/publish/unpublish. Term ID descending is the reliable "newest" sort — taxonomy terms have no `created` base field, and `revision_created` reflects the last edit rather than creation once a term has been touched.

**What this accepts.** An anonymous visitor can create a publicly reachable, immediately live taxonomy term by typing it into an event submission. Honeypot and CAPTCHA on the node form are the only things in front of it. Mitigating factors: the *event* is still unpublished, so a spam tag's term page lists no content; and taxonomy terms are not currently included in the XML sitemap (there is no `simple_sitemap.bundle_settings.default.taxonomy_term.*` config), so they are not actively advertised to crawlers. **If taxonomy terms are ever added to the sitemap, revisit this decision** — that would turn an accepted nuisance into an SEO-spam surface.

**Worth doing before launch:** seed the 15–30 tags the calendar actually wants to filter by. Submitters then find a match in the autocomplete instead of inventing one, which cuts duplicates at the source far more effectively than any review queue.

---

### 3f. `apc_locations` selection plugin — ADDED DURING BUILD

`SessionLocationSelection` (`src/Plugin/EntityReferenceSelection/`) exists to solve one problem created by the gate above.

Anonymously-created locations are unpublished, and core's `TermSelection` filters `status = 1` for non-admins in **both** directions — listing *and* validation. So a submitter who had just added a location through the modal would have their own brand-new location rejected when they submitted the event.

The plugin relaxes that one term at a time: unpublished terms whose IDs were recorded in the current session's private tempstore are also referenceable. Scoping is per-session — core generates a random owner token for anonymous users and prefixes tempstore keys with it — so one submitter's pending location never becomes selectable by anyone else.

Two implementation notes worth preserving:

- **It extends `DefaultSelection`, not `TermSelection`.** `TermSelection` adds its `status = 1` condition unconditionally, and an entity query condition cannot be removed once added. The status logic is therefore reimplemented as an OR group rather than inherited and patched.
- **`getReferenceableEntities()` is overridden** to append " - pending" to unpublished labels. Display only: `EntityAutocomplete::validateEntityAutocomplete()` stores the extracted integer ID, and on re-edit the widget rebuilds the label from `$entity->label()`, so the suffix is never persisted and publishing clears it everywhere at once.

**Failure mode to recognise:** if the plugin is ever missing while the field still points at it, `SelectionPluginManager::getPluginId()` does `uasort($groups[$id], …)` on a group that no longer exists and every page rendering the field dies with *"uasort(): Argument #1 must be of type array, null given"*. `apc_calendar_uninstall()` resets the handler to `default:taxonomy_term` to prevent exactly this.

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

Embed the "upcoming events here" view (section 5b) as a **Block** placed in the Content region via Block Layout, visibility: Request Path = `/taxonomy/term/*`. Its contextual filter resolves the term ID from the URL automatically via Views' "Taxonomy term ID from URL" default-argument plugin. (`RequestPath::evaluate()` matches both the alias and the internal path, so this keeps working if term aliases are added later.)

> **Superseded during build — see §4c-ter.** The term page turned out to be owned by core's `views.view.taxonomy_term`, which adds its own content listing. Locations now have a dedicated page instead.

### 4c-bis. Date display on the Event page — CORRECTED DURING BUILD

The build shipped `field_event_date` on the **`smartdate_default`** formatter in `core.entity_view_display.node.calendar_event.default.yml`. With unlimited cardinality and `smart_date_recur`'s `month_limit: 12`, a weekly event materialises ~52 deltas — so its node page rendered 52 separate date lines.

Use **`smartdate_recurring`** (from `smart_date_recur`) instead. It collapses instances that share an rrule into a rule summary plus a bounded number of occurrences. It degrades correctly for one-off events, so a bundle carrying both kinds needs no special handling:

```php
// SmartDateRecurrenceFormatter::viewElements()
if (empty($item->rrule) || $force_chrono) {
  // No rule so include the item directly.
  $elements[$delta] = $this->buildOutput($delta, $item, $settings);
}
```

Settings used:

| Setting | Value | Rationale |
|---|---|---|
| `format` | `default` | Existing Smart Date Format entity (`D, M j Y` + `g:ia`) |
| `show_next` | true | Isolates the next occurrence — the most useful thing on an event page |
| `upcoming_display` | 4 | Enough to convey the pattern without a wall of dates |
| `past_display` | 1 | Minimal series context; 0 is also defensible |
| `current_upcoming` | true | An event running *now* reads as on-now rather than past — matters for evening events spanning hours |
| `force_chronological` | false | Merging rules back into a flat list defeats the purpose |

The **teaser** view mode had `field_event_date` in `hidden` — an event teaser with no date. Now shown with `smartdate_recurring`, format `compact`, `upcoming_display: 1`.

Any future view mode built for this bundle (e.g. the calendar popup) should use `smartdate_recurring` for the same reason.

### 4c-ter. Location pages — REDESIGNED DURING BUILD

**The problem.** `/taxonomy/term/{tid}` is not a plain entity page. Core's `views.view.taxonomy_term` has a page display at `taxonomy/term/%`, and Views' route subscriber overrides `entity.taxonomy_term.canonical` with it — keeping the route *name*, which matters below. That view renders the term itself in a **header area** and lists all content referencing the term as teasers below. So a location page showed the map plus a second, worse event list (no date filter, sorted by node creation).

**Two dead ends, recorded so they aren't retried:**

1. *Filter the core view by content type* (`type not in [calendar_event]`). Works until events are tagged — it then hides events from Tags term pages, which is the whole point of `field_tags`.
2. *Restrict the `tid` argument to the Tags vocabulary with `fail: empty`.* This **destroys the term header**, because `ViewExecutable::_buildArguments()` `break`s on validation failure *before* recording `$substitutions`, so the header's `{{ raw_arguments.tid }}` token has nothing to resolve against. Row suppression and header rendering are the same code path — no setting separates them. `empty: true` on the area is necessary but not sufficient.

**What was built instead.** A dedicated page, leaving core's view untouched for Tags:

- **`views.view.location_page`** at `locations/%`. Base = node, contextual filter on `field_location_target_id` (*not* `taxonomy_index`, which would also match tags), validator restricted to the `locations` bundle with `fail: not found`. A header area of type **Entity: Taxonomy term** targeting `{{ raw_arguments.field_location_target_id }}` renders the map and address. Upcoming events below, `group_rows: false` for one row per occurrence.
  - The header token resolves here — unlike dead end 2 — because a valid location tid always passes validation. The failure mode is a clean 404 on a bad ID, not a silently half-rendered page.
- **Rabbit Hole** (2.x — configured via `rabbit_hole.settings.enabled_entity_types`, *not* the deprecated `rh_taxonomy` submodule) redirects the `locations` vocabulary to `/locations/[term:tid]`. This is what makes every existing and future link to a location term land on the new page, including the linked term name that core's `taxonomy-term.html.twig` already renders inside the Card on event pages. It works despite Views owning the route because Views preserves the route *name*, and Rabbit Hole matches on `^entity\.(.+)\.canonical$`.
- No pathauto pattern for locations. `/locations/[term:name]` would have collided with the view's own `locations/%` path — aliases resolve before routing, so the readable URL would have landed on the core term page and only `/locations/123` would reach the view. Tags keep `/tags/[term:name]`.

**Debugging note.** Rabbit Hole is bypassed for any user with `rabbit hole bypass taxonomy_term` — which **uid 1 has implicitly**. An admin will see the un-redirected term page while anonymous users redirect correctly. Set `bypass_message: true` on the behavior settings while working, or `no_bypass: true` to force the redirect for everyone. Verify with `curl -sI` (anonymous) rather than a logged-in browser.

### 4c-quater. Directions links — ADDED DURING BUILD

Locations carry a **Directions** pseudo-field offering OpenStreetMap and Google Maps, both opening in a new tab with `rel="noopener noreferrer"`.

Implemented as an **extra field** (`hook_entity_extra_field_info()` + `hook_taxonomy_term_view()`) rather than a Twig override or Views rewrite, so placement stays config: it appears in Manage Display for `default`, `card` and `compact_card` independently and is off by default. A Twig override would have needed a custom theme; a Views rewrite would only have covered the location page, not the card embedded in event pages and the popup.

**Coordinates are preferred over the address** — they are unambiguous and route correctly even where the address itself cannot be geocoded, which §4a shows is common. The formatted address is the fallback; both services geocode it themselves. A location with neither renders "No directions available" rather than nothing, so an editor can see the location is incomplete rather than wondering whether the feature is broken.

Both services are offered because the trade is real: OSM is what this site already uses for tiles and geocoding, but its US house-number coverage is patchy; Google resolves ordinary addresses reliably. A visitor tapping "Get directions" is standing somewhere trying to reach a venue, which is a bad moment to meet the limits of volunteer mapping.

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

### 5c. Adding a location during submission — ADDED DURING BUILD

**Two approaches were tried and abandoned before this one.** Both are recorded because each looked reasonable:

1. **Inline Entity Form.** Its Complex widget renders the *term's whole form display* inline — map widget, notes, image, publishing controls — because `create_bundles_count == 1 && allow_new && !allow_existing` makes it skip the button stage entirely and auto-open the add form. A volunteer adding one event met the admin location form. Uninstalled.
2. **Making `field_geofield` required so a map pin was mandatory.** Abandoned when Nominatim failed to geocode `12903 Humphrey Dr, Austin, TX 78729` — an ordinary suburban address that OSM simply lacks at house-number level. **A free geocoder cannot be a gate**: that submitter would have been hard-blocked from posting a legitimate event.

**What was built instead.** The plain `entity_reference_autocomplete` widget does the searching; a modal covers the case where nothing matches.

- `hook_form_alter` adds a *"Can't find it? Add a new location"* link below the field, with core's `use-ajax` / `data-dialog-type="modal"` attributes. Gated on `create terms in locations`, the same permission the route requires, so it can never lead to a 403.
- `AddLocationForm` (route `/calendar/location/add`) collects **name, street, city, state, ZIP and optional access notes**. No map, no coordinates, no publishing controls. State is validated against a list of US abbreviations; ZIP against `^\d{5}(-\d{4})?$`.
- On submit it creates the term (unpublished via §3c), records the ID in the private tempstore for §3f, and returns an `AjaxResponse` that closes the dialog and writes `Label (id)` into the autocomplete. The event form behind it is untouched, so nothing typed is lost.
- A **name-collision check deliberately matches unpublished terms**. Without it a submitter who cannot find a pending location adds a second one, and the vocabulary fragments exactly where it matters.
- `js/add-location-autocomplete.js` appends a *"＋ Add a new venue…"* row to the autocomplete results, including on **zero results** — which is the whole point, since jQuery UI hides the menu when nothing matches. Selecting it triggers a click on the existing link rather than duplicating the dialog wiring. The visible link remains the no-JavaScript path.

**Implementation note:** the JS must set the widget's `source` and `select` through `$input.autocomplete('option', …)`. jQuery UI resolves the source once in `_initSource()`, so assigning `options.source` after initialisation is silently ignored. It is also scoped to this one element — overriding the shared `Drupal.autocomplete.options` would alter every autocomplete on the site.

Everything else about a location — coordinates, image, approval — remains an admin's job at review time.

---

## 6. Public display

### 6a. `/calendar` — FullCalendar view
Content view, type = Calendar Event, Published = Yes, display type **FullCalendar**, path `/calendar`.

> **Field mapping — the original instruction was wrong and produced an empty calendar.** The plan said to map Start/End to `field_event_date`'s `value`/`end_value` sub-properties. Those report as generic date fields, and `SmartDateProcessor::process()` returns early on them (`if (strpos($start_field_options['type'], 'smartdate') !== 0) return;`), so the entries never receive their real start/end and the calendar silently renders zero events.
>
> **Correct mapping:** Start = `field_event_date` (the base field, formatter `smartdate_default`), End = **left empty**. The processor reads `end_value` off the same field. This is what is now in `views.view.calendar.yml`.

> **Do NOT deselect "Show multiple values in the same row"** (`group_rows`). The original plan called for this; it would cause the exact duplication it was meant to avoid. `FullcalendarViewPreprocess` never uses Views' rendered date output — it reads deltas directly off the entity and emits one calendar entry per delta, so recurring instances already separate on their own. Setting `group_rows: false` makes Views emit one *row* per delta, and since that loop runs per row with no entity dedup, you get rows × deltas. Leave `group_rows: true`.

**Pager must be "Display all items."** FullCalendar View is client-side: every event is serialized into `drupalSettings` on page load, and prev/next merely re-renders what is already there. A paged view therefore does not paginate the calendar — it permanently truncates it. The build initially shipped `type: mini, items_per_page: 10`, which meant `/calendar` contained only 10 event nodes total, selected by creation date, with every other event missing from every month and no visible error.

**Bounding the payload.** With the pager unbounded, and `smart_date_recur`'s `month_limit: 12` materialising ~52 deltas for a weekly event, the serialized payload grows without limit as content accumulates. A filter on `field_event_date_end_value >= -1 month` (granularity: day) keeps it bounded.

- Granularity **day**, not second: the boundary then moves once per day rather than every second, which matters because the view uses tag-based caching and cannot invalidate on time passing alone.
- **Trade-off to be aware of:** because navigation is client-side, anything excluded by this filter is unreachable — users cannot browse back further than the window. Widen it if browsing history matters more than payload size.

**`distinct: true` is required, not optional.** Filtering on `field_event_date_end_value` joins `node__field_event_date`, which has one row per delta — so a weekly event would return ~52 duplicate result rows, and the preprocessor emits every delta again for each one. Without `distinct`, adding the date filter multiplies events on the calendar. Verify by hand after any change here: create a weekly recurring event and confirm each occurrence appears exactly once.

**No sort.** Deliberately empty. FullCalendar orders events client-side, so a Views sort buys nothing, and sorting on the multi-delta `field_event_date_value` alongside `DISTINCT` invites "ORDER BY expression not in select list" errors under MySQL's `ONLY_FULL_GROUP_BY`. The original `created DESC` sort was also actively harmful in combination with the pager, since it made the truncation cutoff arbitrary with respect to event dates.

**Still at defaults, revisit if they bite:**
- `updateAllowed: 1` — drag-to-reschedule. Correctly gated (`if (!$current_entity->access('update')) $entry['editable'] = FALSE;`), so not a security issue, but dragging a Smart Date *recurring instance* is a good way to mangle its rule.
- `bundle_type: calendar_event` — set during the build. This enables double-click-on-a-day to create an event, a second entry point that bypasses the §5b confirmation redirect and the §5c modal. Anonymous users hold `create calendar_event content`, so it works for them too.

### 6b. "Upcoming events at this location" view (feeds 4c)
Content view, type = Calendar Event, machine name `location_upcoming_events`.
- Filters: Published = Yes; `field_event_date` end/value ≥ "now" (relative date).
- Contextual filter: Has taxonomy term ID (on `field_location`), default "Taxonomy term ID from URL".
- Sort: `field_event_date` ascending. Display: Block only.
- Fields: Title **and `field_event_date`** (formatter `smartdate_default`, format `compact`). The build initially shipped Title only, which left a block whose entire purpose is "when is something happening here" showing no dates.

**`group_rows: false` on the date field — and this is deliberately the opposite of §6a.** Views emits one result row per matching delta and renders only that delta's value, so a weekly event lists as consecutive dated rows ("Weekly Vigil — Tue Aug 5", "— Tue Aug 12") merged chronologically with one-off events. That is the correct shape for a listing.

On `/calendar`, `group_rows` must stay **true**, because FullCalendar's preprocessor reads deltas straight off the entity and splitting rows there multiplies events. Both settings are right for their own display; do not "fix" one to match the other.

**`distinct` stays false here**, also unlike §6a. The row multiplication caused by joining `node__field_event_date` is precisely what produces one row per occurrence — which this view wants and the calendar view does not.

Because the pager is `some / 5`, getting this wrong is very visible: before the date field was added, a single weekly recurring event filled the whole block with five identical copies of itself.

---

### 6c. Event popup — ADDED DURING BUILD

Clicking an event opens a modal showing **the occurrence that was clicked**, with a "View full event" link.

**The contrib bug that had to be patched.** `eventClick()` in `fullcalendar_view` navigates unconditionally whenever the event has a URL — `window.open()` or `location.href` — with no `dialogModal` guard. `preventDefault()` does not help, because it stops the anchor's default navigation, not those explicit calls. So the module's own modal option raced the navigation and could not be used at all.

`patches/fullcalendar_view-eventclick-respect-dialogmodal.patch` adds an early return. This is the repo's **first patch**, so it also introduced `cweagans/composer-patches` and the `extra.patches` block. Verify with `git apply --check`, not `patch` — composer-patches tries `git apply` first and rejects fuzz, and the hunk applied "with fuzz 2" until the blank context line carried its leading space.

**The pieces:**

- **`EventPopupProcessor`** (`@FullcalendarViewProcessor`, the documented extension point Smart Date also uses) rewrites each entry's `url` to `/calendar/event/{nid}/{delta}`. Fullcalendar View computes one URL per *row* and reuses it for every date on that row, so without this every occurrence of a recurring event links to the same place.
  - The delta comes from `$entry['id']`, which the preprocessor builds as `"{row index}-{delta}"` before any processor runs. That is more reliable than parsing `eid`: processors run in **discovery order with no weight control**, so there is no guarantee of running after `SmartDateProcessor` rewrites `eid` to `nid-D-delta`. Both forms are handled anyway.
  - It also sets `height: 'auto'` — the module sets no height, so FullCalendar falls back to a fixed `aspectRatio: 1.35`.
- **`EventPopupController`** renders a `calendar_item` view mode. Route carries `_entity_access: 'node.view'` — that requirement is what stops the popup becoming a way to read unpublished pending submissions.
  - It renders a **clone** with `field_event_date` reduced to the single delta, rather than post-processing the render array: Smart Date formatters decide their output from the values they are given.
  - It appends the delta to `#cache['keys']`. `EntityViewBuilder` keys only on entity ID and view mode, so without this every occurrence would serve the first one's markup.
- **No custom JS is needed for the main path.** With `url` set, FullCalendar renders events as anchors and the module's own `eventDidMount()` adds Drupal's `use-ajax` attributes. Core's dialog does the rest.
- **`js/more-popover-dialog.js`** covers the one gap. Fullcalendar View binds Drupal's AJAX in `datesRender()`, which fires for the date grid only — the "+N more" popover is built later on click, so `attachBehaviors` never runs over it and its links are inert. A delegated handler scoped to `.fc-more-popover` reads the `href` already on the anchor and opens it. Delegation is what makes this work on DOM that did not exist at attach time; the popover is created with `parentEl: view.el`, so it is inside `.js-drupal-fullcalendar` and within reach.

**`eventLimit` is `4`.** It can be raised but never disabled from config — `FullcalendarViewPreprocess` passes it through `intval()`, so `false` becomes `0`. Removing the cap entirely would need the processor to set `eventLimit: FALSE`.

**Event pill colour is view config, not CSS.** `style.options.color_bundle` writes straight into each entry's `backgroundColor`; no stylesheet can reach it. Currently `#3e2d1f` for `calendar_event`. The `article`/`blog_entry`/`my_work`/`page` entries are leftovers Views repopulates on every save.

## 7. Admin review queues

### 7a. Unpublished events
Reuse `/admin/content?type=calendar_event&status=2` — no custom view needed. Optional later polish: a bookmarkable filtered view with bulk-publish, only if `/admin/content` proves tedious.

### 7b. Pending locations (required)
Taxonomy term view, Vocabulary = Locations, machine name `pending_locations`. Page display at `/admin/content/locations-pending`, access permission: "Administer taxonomy". Admin clicks through, checks **Published**, saves.

This queue covers **locations only**. Tags are published on creation and reviewed separately at `/admin/content/tags-review` — see §3e for why the two vocabularies are treated differently.

- **Filter: `Published = No`** (not `field_approved = No` — that field was dropped; see §2a/§3).
- **Fields: Name** (linked to term edit form) **+ Address.** Not "Created": taxonomy terms have no `created` base field in core, unlike nodes. Sort by **Term ID descending** for newest-first, which is what "Created" was there to provide. Terms *do* have a `changed` base field if a timestamp column is genuinely wanted.
- Because unapproved locations are invisible to submitters, near-duplicates will show up here (§3c). Scan for them before approving.

---

## 7c. Front-end theme — APC Brown

`web/themes/custom/apc_brown`, a **wholesale fork of Olivero** (`base theme: false`). Source design: `assets/Drupal theme with apc_logo/`.

### Why a fork, and the bug that forced it

It was first built as an Olivero **subtheme** overriding `--color--primary-*` from its own stylesheet. Fonts and backgrounds applied; the colours never did. Several rounds of diagnosis — load order, CSS groups, specificity, ID selectors, `!important`, aggregation, browser caching, file serving — all came back clean, because the cause was not in any stylesheet:

```php
// apc_brown_preprocess_html()
$brand_color_hex = theme_get_setting('base_primary_color') ?? '#1b9ae4';
$variables['html_attributes']->setAttribute('style',
  "--color--primary-hue:$h;--color--primary-saturation:$s%;--color--primary-lightness:$l");
```

Olivero writes its brand colour as an **inline style on `<html>`**. Inline styles beat every stylesheet regardless of load order or specificity, so the primary palette was pinned to blue no matter what any CSS file said.

**The lesson worth keeping:** when a Drupal theme's colours won't budge, check for a theme *setting* writing inline custom properties before investigating the cascade. It is a setting, not CSS — `base_primary_color: '#8a5a34'` in `config/install/apc_brown.settings.yml`, and `drush config:set` for an already-installed theme, since `config/install` only runs at install time.

The fork was chosen independently of that discovery and remains the right call: the palette is now edited at source, so there is nothing left to override.

### What the fork changed

**Palette, edited directly in `css/base/variables.css`:**

| | Olivero | APC Brown |
|---|---|---|
| `--color--white` | `#fff` | `#f5ead8` |
| `--color--gray-100` | `hsl(…97%)` | `#f5ead8` |
| `--color--gray-95` | `hsl(…93%)` | `#ebddc5` |
| `--color--primary-*` | `202 / 79% / 50` | `27 / 45% / 37` |
| `--color--gray-hue` | `201` | `32` |

Overriding `--color--white` would have been too blunt in a subtheme — it is used 40× as a background but also 38× as a text colour. In a fork it is exactly right: `.page-wrapper`, `.site-header__inner` and `.header-nav` all read from it, so "white" simply becomes the cream ground.

**Namespace rename** covered 35 `.theme` functions, the `Drupal\olivero` PHP namespace, 8 library references, Twig's `@olivero` namespace, `attach_library()` calls and `Drupal.olivero` in JS. Two were genuine breakages rather than cosmetics: `olivero_path` in the templates stopped matching the variable `.theme` sets, and `const { olivero } = Drupal` destructured a renamed property.

**`css/theme/apc.css`** carries everything Olivero has no equivalent for: the accent ramps, the Organic neutrals, and rules for the header band, footer, tags, Smart Date output, the location card, the directions links, FullCalendar and `/locations/%`.

**Header band.** The design's masthead spans the header with content left-aligned. Olivero paints the gradient on `.site-branding`, which is sized to its own content — hence a band stopping partway across. Moving the colour to `.site-header__inner` spans it properly. `.header-nav` matches the band (it is both the wide nav strip and the mobile drawer), and the hamburger bars had to be recoloured because Olivero draws them in `--color--primary-50`, which would be brown-on-brown.

**Footer** is `--color-neutral-100` per the style guide. Olivero uses a `background` shorthand with a gradient, so a flat colour must replace the whole shorthand; it also draws a black gutter via `border-inline-start` at ≥75rem which needs recolouring or it reads as a stray bar.

### Known gaps

1. **Fonts load from Google Fonts CDN** (`css/base/fonts.css`). Contradicts §1b, where the JS libraries were deliberately pulled local for supply-chain and visitor-privacy reasons. Needs the Caprasimo/Figtree WOFF2 files, which the design export doesn't include. **Resolve before launch.** `templates/includes/preload.twig` was emptied and is where preload links belong once self-hosted.
2. **`apc_logo.jpg` is 127×100, not square.** `object-fit: cover` crops rather than distorts, but a square asset would be better.
3. **`screenshot.png` is Olivero's** — replace, or the Appearance page misrepresents the theme.
4. **Olivero's `config/` was deliberately not copied.** It contained `olivero.settings.yml` and ten `block.block.olivero_*` configs that would have fought the real Olivero. Blocks must be placed into APC Brown's regions manually; region names are identical.
5. **`*.pcss.css` files came along** with the copy. They are PostCSS sources, loaded by nothing, and still reference Metropolis. Harmless but misleading.
6. `support.js` and `_ds_bundle.js` from the design export are **not used** — the former is the preview harness, the latter declares zero components.

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

- `composer.json` — module requires, the `npm-asset` installer paths (§1b), and `extra.patches` (§6c)
- `config/sync/` — destination for all exported config listed above
- `patches/` — the repo's first patch; see §6c
- `web/modules/custom/apc_calendar/`:
  - `apc_calendar.module` — location approval presave (§3c), add-location link + submit redirect (§5b, §5c), admin notification + `hook_mail()` (§5b), directions extra field (§4c-quater), library alter for Moment/RRule paths (§1b)
  - `apc_calendar.install` — `hook_uninstall()` resetting `field_location`'s handler; see the failure mode in §3f
  - `src/Form/AddLocationForm.php` — the modal (§5c)
  - `src/Plugin/EntityReferenceSelection/SessionLocationSelection.php` — session-aware selection (§3f)
  - `src/Plugin/FullcalendarViewProcessor/EventPopupProcessor.php` — per-occurrence popup URLs (§6c)
  - `src/Controller/EventSubmittedController.php` — session-scoped confirmation page (§5b)
  - `src/Controller/EventPopupController.php` — single-occurrence popup (§6c)
  - `js/` — autocomplete "add a venue" row, "+N more" popover handler
- `web/themes/custom/apc_brown/` — forked Olivero (§7c)

This was the first custom module in the repo. Per CLAUDE.md the site was a vanilla, config-only build with no custom module or theme; **neither is true any more**, and deployments must account for code, a patch, and asset-packagist availability as well as config.

---

## Verification plan

Work through in order, anonymous/incognito session alongside an authenticated admin session:

**(a) Anonymous submission with a brand-new location**
1. As anonymous, `/node/add/calendar_event`: fill Title/Body/Date, then type a venue name that does not exist. Confirm the autocomplete shows **"＋ Add a new venue…"** even though there are zero matches — that row is the whole point of the JS and is the case most likely to break.
2. Select it (or use the "Can't find it?" link). The modal opens **over** the event form with everything you typed still there. Add name/street/city/state/ZIP, submit; the dialog closes and the Location field fills in with `Name - pending (id)`.
3. Submit the event (pause a few seconds — Honeypot's time check can reject too-fast submissions). Confirm no errors, and you land on **`/event-submitted`** showing your own submitted values and a "pending review" message — *not* the front page, and *not* a "has been created" message.
   - **This step is the one that exercises §3f.** The location you just created is unpublished, so without the session-aware selection plugin the event form would reject it. A validation error naming the Location field means the tempstore scoping or the OR condition has broken.
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

**(c) Maps, locations and directions**
1. Visit a location via the venue heading on an event page — it should redirect to `/locations/{tid}` and show the map, address and upcoming events. **Test anonymously**: Rabbit Hole is bypassed for anyone with `rabbit hole bypass taxonomy_term`, which uid 1 holds implicitly, so an admin sees the un-redirected term page while everyone else redirects correctly.
2. Visit the event node page — Location field renders the Card view mode.
3. **Directions:** one location with coordinates and one with only an address — both links should land correctly, via different code paths. A location with neither must render "No directions available".

**(c2) Calendar popup**
1. Click an event on `/calendar` — a modal opens showing **that occurrence**, not the whole series, with a working "View full event" link.
2. Click a *different* occurrence of the same recurring event. If it shows the first one's date, the delta is missing from `#cache['keys']` (§6c).
3. Find a day with more than four events, click **"+N more"**, then click an event inside that popover. This is the path Drupal never binds AJAX to; it exercises `more-popover-dialog.js`.
4. Confirm clicking an event does **not** also navigate away — that is the patch working. If both happen, `composer install` did not apply it.

**(d) Recurrence**
1. Create/edit an event with a recurring rule (e.g. weekly for several weeks).
2. Confirm multiple date instances generated on re-edit.
3. Confirm `/calendar` shows each occurrence as a separate entry — and, critically, **exactly once each**. Duplicated occurrences mean either `distinct` got turned off or `group_rows` got turned off; see §6a.
4. Confirm an event dated more than a month in the past is absent, and one from last week is present — that is the date filter working.
5. Confirm the total event count on `/calendar` matches the published events in range. A count that stops suspiciously round (10, 50) means a pager crept back in.
6. Confirm the location's upcoming-events block reflects future occurrences correctly.

**(e) Add-to-calendar**
1. On a published event page, confirm Google Calendar/Outlook/Yahoo/.ics links render.
2. Click .ics — downloads/opens with correct date, title, location.
3. Click Google Calendar link — opens pre-filled with correct details.

Only after all five checks pass, run the `drush cex -y` export from section 8 and review the diff before committing.
