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
      this one form. Still to verify on the running site: the add-event form actually shows the
      repeat controls for both roles, and a submitted recurring event saves its `rrule` correctly.
- [ ] **Force an initial capital on taxonomy terms.** Extend the existing
      `apc_calendar_taxonomy_term_presave()`. Use `\Drupal\Component\Utility\Unicode::ucfirst()`,
      not `ucfirst()`, or accented venue names will corrupt. Decide explicitly which vocabularies
      this applies to (`locations`, `tags`, or both) and guard on bundle. Consider whether
      deliberately-lowercase names ("eBay", "danceAustin") are worth an exception — probably not
      worth the complexity, but make it a decision rather than an accident.

### Group 2 — Content and display

- [ ] **A. Past events block on the location detail page.** `views.view.location_page` filters on
      `field_event_date_end_value >= now` (offset). Add a second display with the inverse filter,
      DESC sort, and a small item limit. **First resolve an ambiguity:** there are two views here —
      `location_page` (the `/locations/%` page) and `location_upcoming_events` (a block display).
      Determine which is actually placed and whether the second is now orphaned, as
      `views.view.location_reference` was. Do not add a third view before answering that.
      Remember the `distinct` setting — multi-delta Smart Date joins duplicate rows without it.
- [ ] **B. Improve `/event-submitted`.** `EventSubmittedController::buildSummary()` hand-rolls a
      render array covering only `field_event_date`, `body`, and `field_location` — which is why
      uploaded images do not appear. Recommended: replace the hand-rolled loop with a dedicated
      `submission_confirmation` view mode on `calendar_event`, so future fields appear without code
      changes. Note the controller renders a node the visitor cannot otherwise view; the session and
      freshness guards at the top of the class are what make that safe. **Preserve them**, and keep
      `no_cache: TRUE` on the route.
- [ ] **C. Templates for event detail and location detail.** `apc_brown/templates/content/` has only
      `node.html.twig` and `node--teaser.html.twig`. Needs `node--calendar-event--full.html.twig`
      and a location equivalent. **Non-obvious:** location term pages are redirected by Rabbit Hole
      to `/locations/[term:tid]`, a Views page — so a `taxonomy-term--locations.html.twig` may never
      render. Confirm what the Claude Design comps actually target (Views row/page templates vs. the
      term template) before writing either.
- [ ] **D. Responsive images.** `responsive_image` is **not enabled**; `breakpoint` is, and the
      Olivero fork did carry `apc_brown.breakpoints.yml` (sm/md/lg/xl, 1x only). Work: enable the
      module, define responsive image styles, switch the formatters on `field_event_image` and
      `field_media_image`. Existing image styles are only core's defaults plus `wide`. Interacts
      with the `2000x2000` upload cap on `field.field.media.image.field_media_image` — do not define
      styles wider than the largest image that can be uploaded. Add `1x`/`2x` multipliers if the
      design calls for retina.

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

- [ ] Swap the Nominatim User-Agent/Referer off `apc3.ddev.site` to the production domain.
- [ ] Self-host the Caprasimo/Figtree fonts.
- [ ] Replace Olivero's inherited `screenshot.png`; crop `apc_logo.jpg` square.
- [ ] Seed 15–30 starter tags.
- [ ] Confirm asset-packagist is reachable from GreenGeeks before the first production
      `composer install`.
- [ ] Delete smoke-test content (node 125, term 97).
- [ ] Place blocks into the APC Brown regions — Olivero's `config/` was deliberately not copied
      during the fork, so region assignments do not carry over.
