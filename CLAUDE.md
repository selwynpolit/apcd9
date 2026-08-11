# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Drupal 10 site for the Austin Progressive Calendar (austinprogressivecalendar.com). This is a
"vanilla" Drupal build: no custom modules (`web/modules/custom` doesn't exist) and no custom theme
(`web/themes/custom` doesn't exist). The site's behavior is defined almost entirely through
**configuration** (`config/sync/*.yml`, exported/imported with Drush) plus a curated set of
contrib modules declared in `composer.json`. Default admin theme is Claro; default frontend theme
is Olivero (see `config/sync/system.theme.yml`).

Site is hosted on GreenGeeks (`chi204.greengeeks.net`, path `/home/austinpr/public_html/d9`,
domain `d9.austinprogressivecalendar.com`). There is also a legacy Drupal 7 site running in
parallel (`d7.austinprogressivecalendar.com`) — not part of this repo.

## Local environment (DDEV)

This project uses DDEV (`docroot: web`, PHP 8.3, nginx-fpm, MariaDB 10.11).

First-time setup:
```
ddev config
# edit .ddev/config.yaml: remove the upload_dirs entry for
#   upgrade_status/tests/modules/upgrade_status_test_11_compatible/node_modules
# also remove the top-level `name` from .ddev/config.yaml
# create .ddev/config.local.yaml with:
#   timezone: America/Chicago
#   name: <your-local-dir-name>
ddev start
ddev composer install
```

Pulling a fresh copy of prod data:
```
ddev drush @apc.prod sql-dump > dbprod.sql
gzip dbprod.sql
ddev import-db --file=dbprod.sql.gz
ddev launch $(ddev drush uli)
```

The `@apc.prod` Drush alias is defined in `drush/sites/apc.site.yml`.

DB dump files (`*.sql`, `*.sql.gz`) and `.idea/` are gitignored — don't add them to commits.

## Common commands

Run everything through `ddev` (or `ddev ssh` first) so PHP/MySQL versions match the container.

```
ddev composer install              # install PHP dependencies (web/core, contrib modules/themes)
ddev composer update <package>     # update a single contrib dependency

ddev drush cr                      # rebuild cache
ddev drush cim -y                  # import config from config/sync into the DB
ddev drush cex -y                  # export DB config to config/sync (run after any UI config change)
ddev drush cst                     # config status — diff between DB and config/sync
ddev drush updb                    # run pending DB updates (hook_update_N)
ddev drush uli                     # generate a one-time admin login link
ddev drush @apc.prod <command>     # run drush against production over SSH
```

There is no custom PHPUnit/JS test suite in this repo (only Drupal core's own tests under
`web/core/tests`); there are no lint/build scripts defined in `composer.json`.

## Configuration workflow

Because there's no custom code, most "development" here is config management:
1. Make changes in the Drupal admin UI (locally, via DDEV).
2. `ddev drush cex -y` to export the change into `config/sync/*.yml`.
3. Commit the resulting YAML diff.
4. On deploy, `drush cim -y` applies it to the target environment.

Check `ddev drush cst` before exporting/committing to see exactly what changed.

## Deployment (production, GreenGeeks)

```
ssh into greengeeks
cd ~/www/d9
git pull
composer install --no-dev
drush updb
drush cr
drush cst
drush cim -y
drush cr
```

## Git workflow

Gitflow-style: work happens on `develop`, then merges into `main`; `main` is what gets deployed.

## Contrib module surface

Notable contrib modules in use (see `composer.json` for the full/versioned list): `pathauto`,
`redirect`, `linkit`, `linkchecker` + `feeds`/`feeds_tamper` (content import/tamper pipelines),
`smart_date`, `config_pages`, `dynamic_entity_reference`, `rabbit_hole`, `simple_sitemap`,
`better_exposed_filters`, `chosen`/`select2_all`/jQuery UI family (widgets/filters), `honeypot` +
`captcha` (spam protection), `seckit` + `shield` (security hardening), `admin_toolbar`,
`module_filter`, `upgrade_status` (Drupal 11 upgrade readiness — repo is mid-prep for a Drupal 11
move per recent commit history).

`find-broken-links.sh` parses a `wget` recursive-crawl log (e.g. `wget.log`) to extract
source/broken-URL pairs for dead-link auditing; run as `./find-broken-links.sh wget.log`.

---

## TODO — next phase

Background for anyone picking this up: `Event Calendar Plan.md` is the authoritative record of what
has been built and, importantly, *why several planned approaches were abandoned*. Read it before
starting — three of the items below re-enter territory where an obvious-looking approach has already
been tried and rejected.

**Two standing rules for this codebase, both learned the hard way:**

1. Run `ddev drush cst` **before** `ddev drush cex`. An unguarded `cex` has already silently
   discarded hand-edited config that had not yet been imported.
2. Verify against the running site, not against exported YAML. A theming bug in this repo took
   several rounds to find because it was diagnosed from `config/sync` and from a `grep` run while
   CSS aggregation was on. Neither reflected reality.

Items are ordered by dependency, not by importance. **A, B, C are independent and can be done in any
order; D depends on A; F depends on E.**

### Group 1 — Anonymous form polish (one hook, three items)

`core.entity_form_display.node.calendar_event.default.yml` is the *only* form display for this
bundle, and Drupal form displays are per-bundle, not per-role. So none of these can be done in the
UI. All three belong in the existing `if ($account->isAnonymous())` branch of
`apc_calendar_form_node_calendar_event_form_alter()`, alongside the revision-hiding code already
there. Do them as one change, not three.

- [x] **Hide the body summary from anonymous submitters.** Done. Widget is
      `text_textarea_with_summary` with `display_summary: true`; set
      `$form['body']['widget'][0]['summary']['#access'] = FALSE` in the anonymous branch of
      `apc_calendar_form_node_calendar_event_form_alter()`. `display_summary` in field config was
      left untouched, so admins still see it.
- [x] **Allow recurring events for anonymous submitters.** Decision reversed from the original plan
      (which called for blocking this) — anonymous submitters should be able to create recurring
      events. Turned out to need no `#access` code at all: the recurrence UI
      (`repeat`/`interval`/`repeat-end`/advanced weekday controls) is only added to the widget's
      render array in the first place when the current user has the `make smart dates recur`
      permission — see `smart_date_recur_widget_extra_fields()` in `smart_date_recur.module`. Granted
      that permission to both the `anonymous` and `event_contributor` roles. `field_event_date` is
      the only field of type `smartdate` on the site, so the permission is effectively scoped to
      this one form. Verified on the running site: the add-event form shows the repeat controls for
      both `anonymous` and `event_contributor`, and body summary/revision fields stay hidden for
      anonymous only, as designed.
- [x] **Force an initial capital on taxonomy terms.** Done. Extended
      `apc_calendar_taxonomy_term_presave()` to run on every save (not just new terms) for both the
      `locations` and `tags` vocabularies, using `\Drupal\Component\Utility\Unicode::ucfirst()` so
      multibyte leading characters aren't corrupted — verified against the running site with an
      accented name (`étoile lounge` → `Étoile lounge`). No exception carved out for deliberately-
      lowercase names; decided not worth the complexity.

### Group 2 — Content and display

- [x] **A. Past events block on the location detail page.** Done. Added an `attachment_1` display
      ("Past Events") attached after `page_1` on `views.view.location_page`, with the inverse filter
      (`field_event_date_end_value < now`), `DESC` sort, and a `some` pager capped at 5 items.
      `location_upcoming_events` (the separate block-display view) was confirmed orphaned, not just
      possibly redundant: its block placements (both `apc_brown` and `olivero`) had a visibility rule
      targeting `/taxonomy/term/*`, but `rabbit_hole.behavior_settings.taxonomy_term.locations.yml`
      302-redirects that path to `/locations/[term:tid]` before any block region renders — the same
      fate as `views.view.location_reference`. Deleted the view; both block placements cascade-deleted
      automatically as dependents.
      **The `distinct` concern turned out to be a red herring — tested and confirmed, not assumed.**
      A 5-instance recurring event was verified to produce 5 separate rows on this page (each a real,
      individually-matching Smart Date delta). Neither `distinct: true` nor Views aggregation
      (`group_by` + `MIN()`/`MAX()`) fixes this cleanly: aggregating a composite field like Smart Date
      (`value`/`end_value`/`duration`/`rrule` stored together) per-column independently breaks the
      formatter (it rendered raw unformatted numbers instead of a date). **Deliberately not fixed** —
      decided a recurring series showing each instance separately is acceptable, normal calendar UX;
      no dedup code was added. `distinct` was left at its existing `false` on both the `default` and
      new `attachment_1` displays.
      **Also hit and worth remembering:** building a new Views display programmatically (not through
      Views UI) requires calling the display handler's `setOverride($section, FALSE)` for each
      section you intend to customize (`header`, `footer`, `filters`, `sorts`, `title`, `empty`) —
      Views' "same as Default" behavior is governed by a `display_options.defaults` flag per option,
      not by merely writing a value into the display's own `display_options` array. Hand-writing that
      `defaults` array directly and saving via the plain entity API silently failed to persist it;
      only the official `setOverride()` API worked. Also: Views' `title` option does **not** render as
      a visible on-page heading for attachment/block displays in this theme (`apc_brown`'s
      `views-view.html.twig` only prints `title` for the admin preview) — the existing "Upcoming
      Events" heading on this same view is a `header` text area (`<h2>Upcoming Events</h2>`), not the
      `title` option; the new "Past Events" heading was built the same way.
- [x] **B. Improve `/event-submitted`.** Done. Replaced the hand-rolled `buildSummary()` loop with a
      dedicated `submission_confirmation` node view mode
      (`core.entity_view_display.node.calendar_event.submission_confirmation.yml`) — `field_event_image`
      now appears (the original gap), plus `field_tags`. The controller still does **not** call the
      full node view builder (that would bring back node/contextual links pointing at a URL the
      submitter gets a 403 on) — it renders each of the view mode's configured field components
      individually, in weight order. Two non-obvious things baked into this:
      - `field_location` on this view mode uses `entity_reference_label` with `link: false`, not the
        `entity_reference_entity_view` "card" formatter used on the public view modes — a newly
        auto-created location term is unpublished too, so a linked card would 403 the person who just
        typed it.
      - `EntityDisplayBase::init()` always re-adds node base fields (`title`, `uid`, `created`) as
        visible components on every fresh `getComponents()` call, regardless of what's actually
        persisted in this view mode's config — discovered by testing against the running site, not by
        reading the YAML. The controller filters to `field_*` names plus `body` to exclude them.
      - `field_virtual`'s formatter setting `format_custom_false: ''` means a non-virtual event has
        nothing to print; the controller skips it outright when false rather than rendering an empty
        field wrapper.
      Session/freshness guards in `loadSubmittedNode()` and `no_cache: TRUE` on the route were left
      untouched, as required.
- [x] **C. Templates for event detail and location detail.** Done, built against the Claude Design
      comps in `assets/*.pdf`. One template each, not separate mobile/desktop templates — the comps
      are the same layout reflowing at a breakpoint, handled entirely in `css/components/detail-page.css`
      (stacks below `62.5rem`, sidebar grid above).
      - `node--calendar-event--full.html.twig` — badges (`field_tags`), title, date/time, an "Add to
        calendar" Google Calendar link (`_apc_brown_google_calendar_url()` — no ICS service exists in
        this codebase, so this is a plain prefilled URL, no new dependency), a single "Get directions"
        Google Maps link, an image gallery, and sidebar cards.
      - **The location page's whole architecture changed**, not just a template add. It used to be a
        Views page (`location_page`) that Rabbit Hole redirected `/taxonomy/term/%` to. That's gone —
        `rabbit_hole.behavior_settings.taxonomy_term.locations.yml` now uses `display_page` (normal
        entity render) instead of `page_redirect`, and a new `pathauto.pattern.locations` pattern
        (`/locations/[term:name]`) gives terms their own alias so the URL keeps working. `location_page`
        lost its `page_1`/`attachment_1` displays (the path they lived at is now the term's own alias —
        keeping them would have meant two routes competing for the same path) and gained two **block**
        displays instead, `block_upcoming` and `block_past`, embedded directly in
        `taxonomy-term--locations--full.html.twig` via
        `Views::getView('location_page')->buildRenderable(...)` in `apc_brown_preprocess_taxonomy_term()`.
      - **Non-obvious, found by testing against the running site, not by reading the YAML:** disabling
        the redirect didn't hand rendering to the plain entity view builder — it revealed
        `views.view.taxonomy_term`, Drupal's optional default "Taxonomy term" view. It was `status: true`
        this whole time, silently masked because it registers its own route at the literal path
        `taxonomy/term/%`, which Views intentionally lets take priority over the plain entity route —
        Rabbit Hole's redirect ran first and nobody ever saw it. It was never an intentional part of this
        site (would apply to every vocabulary, `tags` included) and is now disabled.
      - **Also non-obvious:** `taxonomy_theme_suggestions_taxonomy_term()` in core only adds
        `taxonomy_term__BUNDLE` and `taxonomy_term__ID` suggestions — unlike nodes, there is no free
        `taxonomy_term__BUNDLE__VIEWMODE` suggestion, so a `taxonomy-term--locations--full.html.twig`
        file is silently never picked up on its own. Added
        `apc_brown_theme_suggestions_taxonomy_term_alter()` to add it.
      - New `field_tags` field added to the `locations` vocabulary (targeting the same `tags`
        vocabulary events use) for the amenity pills ("Wheelchair accessible", "Free parking") the
        comps show — there was no backing field for these before.
      - New `taxonomy_term.location_reference` view mode (name + address only) for the event page's
        compact "Location" sidebar card — reusing the existing `card` formatter there rendered a full
        embedded card (map, image, full address) that was never right for a sidebar.
      - **Known gap, not built:** the comp's "Organizer" sidebar card on the event page has no backing
        field (no `field_organizer` exists) and was left out rather than guessed at — needs a real
        decision (plain text vs. a reference) before it's added.
      - **Known simplification:** the embedded upcoming/past events lists on the location page render
        with the `location_page` view's own default field-row markup, not bespoke `.apc-event-card`
        styling — functionally correct and reasonably readable, just not pixel-matched to the comp's
        card treatment.
      - Gallery click-to-swap (event and location pages both): `apc_brown/js/gallery.js`, plain JS using
        `<button>` thumbnails (free keyboard support) that swap the hero `<img>`/`<source>` via data
        attributes — no framework, no Views/render-array involvement for the interactive part.
      - **Fixed after initial build, from user feedback:**
        - The event page's "Get directions" was a single Google-only link
          (`apc_calendar_get_directions_url()`); changed to the same two-link OSM+Google block
          (`apc_calendar_build_directions()`) the location page uses, for consistency.
        - The location page's map was missing entirely — `field_geofield` had been removed from
          `taxonomy_term.locations.default`'s components on the assumption it was "only used
          internally" for geocoding/directions. It wasn't; `card`'s own `leaflet_formatter_default`
          formatter was the map the comp's "Map" placeholder was standing in for. Restored with the
          same formatter settings as `card`, placed side by side with the gallery
          (`.apc-detail__media`, a two-column grid above `62.5rem`).
        - An anonymous visitor viewing a **published** event whose referenced location term is still
          **unpublished** (a real case — an event can be approved before its newly-typed location is)
          saw a "Location" sidebar card with a visible label but a blank value: `entity_reference_label`
          correctly access-checks the referenced term per-item and silently renders nothing, but
          `{% if content.field_location %}` in the template still evaluated truthy (the render array
          exists, it's just empty), so the broken-looking blank card wasn't caught by that guard. Now
          shows "Pending review" instead — computed by checking `$term->isPublished()` directly in
          `apc_brown_preprocess_node()` (reading a loaded entity's own property isn't subject to the
          same access check formatter rendering is), gated on `\Drupal::currentUser()->isAnonymous()`
          so a manager or admin still sees the real venue name and link. Deliberately shows the literal
          word "Pending review", not the unapproved venue's actual name.
      - **A second round of fixes, from a closer look at the live pages:**
        - Both detail pages showed the title twice — the theme's generic "Page title" block
          (`apc_brown_page_title`, placed in `content_above` site-wide) stacked on top of each
          template's own styled `<h1>`. That block already excluded the `/calendar` listing page;
          added `/calendar/*` and `/locations/*` to its `request_path` visibility so it's hidden on
          every individual event/location page too, site-wide, with no template code needed.
        - Location page amenity badges (`field_tags`) moved from just under the title to after "About
          this location" — matches where the design comp actually places them.
        - **The map settings restored earlier for the location page were wrong** — an abbreviated
          hand-typed settings array, missing `disable_wheel` among others, so scroll-wheel zoom was
          silently enabled when it shouldn't have been. Re-fixed by copying `card`'s `field_geofield`
          component **verbatim** (`$card->getComponent('field_geofield')`, not retyped) onto both
          `taxonomy_term.locations.default` and the event page's `location_reference` view mode, so
          both pages now match `card`'s exact settings, including `disable_wheel: true` and
          `map_position.zoom: 15`.
        - **The event page's map was gone too**, and for the same root cause as the "Get directions"
          regression: `field_location` used to render via `entity_reference_entity_view` +
          `card` (which includes the Leaflet map), and swapping it to the slimmer `location_reference`
          view mode for the sidebar dropped the map along with the embedded photo/full address it was
          also trying to shed. Fix was config-only — add `field_geofield` (with `card`'s exact
          settings) as a component of `location_reference` — no template change needed, since
          `content.field_location` already renders the whole embedded view mode.
- [x] **D. Responsive images.** Done, folded into item C rather than done separately, since both
      needed the same hero image markup. Enabled `responsive_image`; new `gallery_hero_mobile` /
      `gallery_hero_desktop` / `gallery_thumb` image styles (scale-and-crop, well under the `2000x2000`
      upload cap) and a `gallery_hero` responsive image style mapped to the `apc_brown.lg` breakpoint.
      Used directly in `_apc_brown_build_gallery()` (in `apc_brown.theme`) rather than through the
      Field UI formatter system — the interactive gallery needs precomputed mobile+desktop URLs on
      every thumbnail for the swap JS, which a standard responsive-image field formatter has no way to
      expose.

### Group 3 — Editorial workflow and access

- [x] **E. Contributor and manager roles.** Done. `event_contributor` (create own events/locations,
      `publish calendar_event content immediately` + `bypass location approval`) and `event_manager`
      (edit/delete any event, taxonomy administration via `taxonomy_manager`/`term_merge`) both exist
      with a real permission matrix — see `apc_calendar.permissions.yml`.
      `apc_calendar_taxonomy_term_presave()` was revisited as planned: it now gates on the
      `bypass location approval` permission rather than `!isAnonymous()`, so a future low-trust
      authenticated role doesn't silently inherit instant-publish. `field_term_author` (on the
      `locations` vocabulary) was added alongside this so pending locations show who submitted them
      without needing full revisions.
- [x] **F. One-click publish of event + its location.** Done.
      `Plugin/Action/PublishEventAndLocation.php` publishes the node and, if its referenced location
      term is still unpublished, publishes that too — with a status message and a log entry so an
      admin is never surprised by a venue going live. Wired into `pending_events`'s
      `selected_actions`. Access is checked via ordinary entity update access rather than core's
      publish action (which requires `administer nodes` for the `status` field) so `event_manager`
      can use it without that broader permission.

### Group 4 — Designed, written up separately

- [ ] **H. Import events from external iCal calendars.** Full design in `event-import-task.md`.
      First source is the Forward TX Google Calendar, but the design is multi-source from the start:
      one `ical_event_import` feed type, one feed per calendar, an `event_sources` vocabulary. Import
      into `calendar_event` unpublished and curate — *not* a staging content type. **The one thing to
      know before touching it:** deleting a rejected import causes it to be re-imported forever,
      because the dedupe state lives in `feeds_item` on the node. Rejection is a flag, never a
      delete. Depends on F for the publish action.

### Group 5 — Needs a decision before any implementation

- [x] **G. Virtual / online-only events.** Done. `field_virtual` (boolean, default off) plus an
      optional `field_event_url` (link, external URLs only) were added to `calendar_event`. Checking
      the box hides `field_location` client-side via `#states`, but the actual enforcement is
      server-side: `apc_calendar_node_presave()` clears `field_location` whenever `field_virtual` is
      TRUE, and a validate handler added in
      `apc_calendar_form_node_calendar_event_form_alter()` requires `field_location` when it is
      FALSE. **That validation is a form validate handler, not an entity-level constraint** — it only
      runs for submissions through the node-add/edit form. Anything that creates or saves a
      `calendar_event` another way bypasses it entirely; see `event-import-task.md`, where Feeds does
      exactly that. Item H's importer will need its own answer for this (e.g. default imported events
      to non-virtual, or add an equivalent check in the import pipeline) rather than assuming this
      validation covers it.
      The ripple effects this bullet originally worried about **do not apply, and no guards were
      added for them.** Both the geocoder and `apc_calendar_build_directions()` are reached only from
      `apc_calendar_taxonomy_term_view()` on the location *term* — never from the node — so a virtual
      event, which has no location term, never touches either path. Don't re-add guards there later
      on the assumption they're needed.

### Pre-launch (carried over from `Event Calendar Plan.md`)

- [x] Swap the Nominatim User-Agent/Referer off `apc3.ddev.site` to the production domain.
- [ ] Self-host the Caprasimo/Figtree fonts.
- [ ] Replace Olivero's inherited `screenshot.png`; crop `apc_logo.jpg` square.
- [ ] Seed 15–30 starter tags.
- [ ] Confirm asset-packagist is reachable from GreenGeeks before the first production
      `composer install`.
- [ ] Delete smoke-test content (node 125, term 97).
- [ ] Place blocks into the APC Brown regions — Olivero's `config/` was deliberately not copied
      during the fork, so region assignments do not carry over.

### Address lookup helpers on the `locations` term form

Done — full design was in `location-address-lookup-task.md` (now removed; superseded by this note).
`apc_calendar_form_taxonomy_term_locations_form_alter()` adds two independent tools:

- **Find on Google Maps / OpenStreetMap** — static links (no AJAX, no geocoder call) built from the
  term's current name + city/state, defaulting to "Austin TX" when no address is saved yet.
- **Paste-box geocoder** — a textfield that accepts either a `lat,lon` pair or an address string,
  dispatches to `\Drupal::service('geocoder')` (`reverse()`/`geocode()`, `nominatim` provider),
  shows the candidate(s) (radios if more than one), and an "Apply" button that saves
  `field_address`/`field_geofield` and reloads the page.
- **`UsStates`** (`src/UsStates.php`) — the state-abbreviation list moved out of `AddLocationForm`
  here so both consumers share it, plus a new `NAME_TO_CODE` map for normalizing Nominatim's
  spelled-out state names ("Texas") to the two-letter code the Address field wants — confirmed live
  against real Nominatim responses that `getAdminLevels()` really does come back with an empty
  `code` and only a `name` some of the time, exactly as the design doc warned.
- **`apc_calendar_get_directions_destination()` / `apc_calendar_get_directions_url()`** — the
  destination-resolution half of `apc_calendar_build_directions()` was split out so a single-link
  consumer doesn't need to duplicate the coordinates-preferred-over-address logic. (Ended up used by
  the event detail page's "Get directions" button as well as this feature.)
- **Non-obvious, found only by testing in the browser, not by reasoning about the API:**
  `$form_state->setValueForElement()` on the Apply button changes what a *submit handler* reads, not
  what a *rebuilt widget displays* — field widgets rebuild their shown value from the form's entity,
  not from `$form_state`'s values. Setting the values directly on the (still-unsaved) entity before
  `$form_state->setRebuild(TRUE)` was tried next and still didn't reliably redisplay — Address
  (composite element) and Geofield (GeoJSON textarea + its own map widget) both have enough internal
  state that an in-memory rebuild wasn't trustworthy. **Apply saves immediately and redirects back to
  the same edit page** instead — simpler, and a fresh page load is the one path every field widget is
  guaranteed to render correctly. Trade-off: other fields the admin was mid-editing (name, notes,
  image, tags) are not part of this intermediate save; they stay in the form to submit normally
  afterward, not lost, just not saved by this button.
- Also non-obvious: the `apc_geocode` fieldset needs `'#tree' => TRUE` for its children's form
  values to nest under `['apc_geocode', ...]` — without it Drupal flattens them to top-level keys,
  silently breaking every `$form_state->getValue(['apc_geocode', ...])` call in the form.
