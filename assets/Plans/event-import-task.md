# Task: importing events from external calendars

Status: **built and verified locally (2026-09-03).** Forward TX permission confirmed by Wendy
(the calendar's author) — the one real blocker on this doc's own Sequence is cleared. First source
is the Forward TX Google Calendar (iCal); a second candidate, the Austin Chronicle, is still
**blocked pending investigation** — see "Other sources" near the end.

Everything in "Architecture" through "Sequence" below describes what was actually built, not just
the plan — including several real bugs the design didn't anticipate, found only by running the
first live import against production data. See "Bugs found only by running a real import" after
"Traps found in the real data" for what they were and how they were fixed. **Not yet done:**
production deployment (this was all built and verified against the local DDEV site only — see
CLAUDE.md's Deployment section for the actual rollout steps) and item I's tag-suggestion work this
doc's "Collisions" section anticipated depending on.

Everything below is built for N sources in more than one format from the outset. Read "Genericity"
before changing any of it.

## Read first

- `CLAUDE.md` — TODO section and the two standing rules.
- `Event Calendar Plan.md` — the authoritative record of what exists and why several planned
  approaches were abandoned.
- `virtual-events-task.md` — **collides with this work**, see "Collisions" at the end.

**Two standing rules for this repo, both learned the hard way:**

1. Run `ddev drush cst` **before** `ddev drush cex`. An unguarded `cex` has already silently
   discarded hand-edited config that had not yet been imported.
2. Verify against the running site, not against exported YAML.

## The source, measured not assumed

Forward TX publishes a working public iCal feed:

```
https://calendar.google.com/calendar/ical/info%40forwrd-tx.org/public/basic.ics
```

Measured 2026-08-09 — 106 KB, valid `VCALENDAR`:

| | |
|---|---|
| Events | 82 total; ~24 dated after today (Aug–Dec 2026), ~58 past |
| Recurring | 12, **all `FREQ=WEEKLY`** — no `BYSETPOS`, no `EXDATE`, no monthly-by-position |
| `RECURRENCE-ID` | 0 — every UID unique, so GUID dedupe is safe |
| All-day (`VALUE=DATE`) | 6 |
| Timestamps | Mixed UTC (`Z`) and `TZID=America/Chicago` |
| `LOCATION` | Free text, wildly inconsistent |

**Before going live, ask Forward TX for permission** and agreed attribution wording. Aggregating a
partner org's public calendar is normal, but asking costs one email and gets you a heads-up if they
ever change or unpublish the feed.

## Architecture

`composer require 'drupal/feeds_ical:^3.1'` (D9/10/11, security-covered). `feeds` and `feeds_tamper`
are already enabled and two feed types already work, so this is configuration, not code.

**Import into `calendar_event` as unpublished. Do not create a staging content type.** A staging
bundle means duplicating five fields plus a conversion step maintained forever. `feeds_item` — the
field Feeds adds, holding a reference to the feed plus the ICS `UID` — already provides the staging
behaviour, the dedupe, and the "which source did this come from" marker.

### Genericity: feed types group by format, not by organization

In Feeds, a **feed type** is the config (parser, processor, mappings); a **feed** is an instance with
a URL. The rule that makes this scale:

> One feed type per **source format**. One feed per **calendar**.

| Feed type | Parser | Feeds under it |
|---|---|---|
| `ical_event_import` | iCal | Forward TX, and any other iCal/Google calendar |
| *(later)* `rss_event_import` | RSS/Atom | any source publishing a feed |
| *(later, only if forced)* | `feeds_ex` XPath | scraped HTML |

Forward TX shares a type with future Google calendars because they share a *format* — the mappings
are identical byte for byte. A different publisher gets its own feed type only when its **format**
differs, not because it is a different organization.

Do **not** create a feed type per calendar. Mappings duplicated N times drift apart within months,
and the failure is silent — you update one and forget the others.

Everything else in this document — the `event_sources` vocabulary, `field_event_source`,
`field_import_state`, `field_import_location_raw`, the curation view, the never-delete rule — is
**format-agnostic** and shared across every feed type. That is the actual payoff of the design, and
heterogeneous sources make it more valuable rather than less.

This makes the per-calendar source value the only interesting problem. Solve it like this:

- `feeds_feed` is a fieldable entity. Add `field_event_source` (term reference → `event_sources`) to
  the **feed** bundle, and set it per feed.
- Feeds exposes feed-type fields as mapping sources, so map that feed field straight onto the node's
  `field_event_source`. One feed type, correct source per feed, zero code.
- **Do not** use a Tamper "Default value" plugin for this. Tamper is configured per feed type, so
  every feed sharing the type would get the same source. Note also that Tamper plugins reportedly do
  not yet apply to feed-field sources — another reason not to rely on them here.

**Verify the feed-field-as-mapping-source works in your Feeds version before building around it.**
If it doesn't, the fallback is a small `FeedsEvents` subscriber in `apc_calendar` that copies the
feed's source term onto the node during processing (~20 lines). Don't fall back to one-feed-type-per-
calendar.

### Vocabulary

New vocabulary `event_sources`, one term per calendar:

- name — the org or calendar name, e.g. "Forward TX"
- `field_source_url` — link to their calendar or site, so attribution on a published event can link
  back. This is what you offer them in exchange for permission.

### Fields on `calendar_event`

| Field | Type | Purpose |
|---|---|---|
| `field_event_source` | term ref → `event_sources`, single | Which calendar it came from. Empty for human submissions, which is meaningful. Displayable, unlike `feeds_item`. |
| `field_import_location_raw` | plain text | The raw `LOCATION` string, for the curator to read |
| `field_import_state` | list: `pending` / `accepted` / `rejected`, default `pending` | Review state |

`field_event_source` is **not** redundant with `feeds_item`. `feeds_item` references the feed
*entity* — delete or rebuild the feed and the link breaks — and it is admin plumbing, awkward to
render. The source term survives and can be shown on the published event.

### Why `LOCATION` gets a plain text field and not an entity reference

Do **not** map `LOCATION` to `field_location` with auto-create. The real data:

```
LOCATION:Carver Branch\, Austin Public Library 1161 Angelina St Austin\, TX
LOCATION:Carver Branch\, Austin Public Library\, 1161 Angelina St\, Austin\, TX
```

Same venue, two strings, two terms. And the spread also includes bare addresses with no venue name,
hand-typed strings with no comma structure, street intersections, and values that are not locations
at all (`RSVP for location`, `Mueller Neighborhood`). No normalization scheme survives that last row.

The curator picks the real term during review. Most strings *are* geocodable addresses, so the
existing `AddLocationForm` + Nominatim handles the ones that need a new term — reuse it rather than
building a matcher.

### Rejection: flag, never delete

**Deleting a rejected node causes it to be re-imported on the next run, forever.** The dedupe state
lives in `feeds_item` *on the node*; delete the node and the only record that the GUID was ever seen
goes with it. Feeds keeps no separate ledger.

So rejecting sets `field_import_state = rejected` and leaves the node in place, unpublished. The node
is its own ledger. This is the only approach requiring zero custom code, and 82 nodes a year is
nothing.

If the parked rejects ever genuinely bother you, the safe garbage-collection rule is "delete rejected
nodes whose event date is older than the feed's retention window" — once an event ages out of the
source feed it can never be re-imported. Check the oldest `DTSTART` in the feed to find that window;
it currently reaches back to at least July 2025.

An alternative ledger (custom table, or `\Drupal::keyValue()`) would let you delete, but still needs
a `FeedsEvents` subscriber to consult it, and gives up the admin UI that makes un-rejecting a
dropdown change. Not worth it for one feed.

## Feed type configuration

- Parser **Ical**, processor **Node**, bundle **`calendar_event`**
- Status **unpublished**
- **Previously imported items: do not update existing.** Otherwise re-imports clobber edits made
  while curating. This is the single most important setting here.
- Import period: **weekly.** 24 future events over five months does not justify nightly.
- Mappings: `SUMMARY`→title, `DTSTART`/`DTEND`→`field_event_date`, `DESCRIPTION`→body,
  `LOCATION`→`field_import_location_raw`, `UID`→GUID, feed's `field_event_source`→node's
  `field_event_source`
- Pick the body text format **deliberately** — Google descriptions carry HTML and pasted links.

## Views — two changes, not one

1. **New `imported_events` view.** Filter: `field_import_state = pending`. Expose
   `field_event_source` as a filter so it works for N calendars. Columns should include
   `field_import_location_raw` and the event date. VBO bulk form for accept/reject.
2. **Add an exclusion filter to `views.view.pending_events`** — exclude nodes where `feeds_item` is
   set. Without this the first import dumps ~58 past events into your human-submission moderation
   queue and makes it useless. Easy to forget, immediately annoying.

**Do not filter past events at import time.** Recurring series store the *series start* in `DTSTART`,
often more than a year old, and feeds_ical does not expand RRULE. A `DTSTART >= now` filter would
silently discard all 12 recurring events — which are the standing weekly org meetings, the most
valuable things in the feed. Express "future OR recurring" in the view instead. **Built as:** a new
`field_import_recurrence_raw` (plain text) mapped from the feed's `RRULE` source — doubles as the
raw pattern shown to the curator in the `Recurrence` column and as the "is this a recurring series"
signal for the filter. **Do not build the OR itself as a Views UI filter group** — the obvious
approach (duplicate the view's `type` and `field_import_state` filters into a second group, set
`filter_groups.operator: OR`) is what the Views UI itself does for this exact scenario, but
duplicating `field_import_state` across two groups triggers a genuine Views query-builder bug: the
second copy's auto-generated table join carries a self-contradicting `!= 'pending'` condition, so
that whole group can never match a row (confirmed by dumping the built SQL — `WHERE ... AND
node__field_import_state2.field_import_state_value = 'pending'` joined via `... AND
node__field_import_state2.field_import_state_value != 'pending'`, an unsatisfiable pair). Built
instead as a single `hook_views_query_alter()` (`apc_calendar_views_query_alter()`) that adds one
raw `addWhereExpression()` — `(date >= now OR recurrence IS NOT NULL)` — reusing table joins it
forces explicitly via `ensureTable()` with a hand-built `standard` join plugin instance, rather than
trusting the view's own displayed fields to provide those joins: the pager's separate COUNT query
strips joins that only serve SELECT-ed display fields, so a join present in the main query can be
silently absent from the COUNT query (hit this live — the COUNT query 500'd with "Unknown column
node__field_import_recurrence_raw..." before the joins were forced).

## Curation workflow

Accept → set state `accepted` and publish. This reuses the VBO action being built for `CLAUDE.md`
item **F** (publish event + its location in one click). Reject → set state `rejected`, node stays.

Load is small: ~24 events over five months, roughly five a month, plus twelve recurring series set up
once. The view needs a pager and filters, nothing more.

### Recurrence: investigated, deliberately left manual

Whether the raw RRULE captured in `field_import_recurrence_raw` could instead auto-populate real
Smart Date recurrence on import was investigated after the first live import (2026-09-03) and
rejected for now, not overlooked. **Not a config tweak — it's real custom code, and wasn't built.**

- Smart Date's `rrule` sub-field on `field_event_date` is an **integer** (the entity ID of a
  `smart_date_rule` content entity), not a place for RRULE text —
  `SmartDateItem.php` in the `smart_date` module.
- `feeds_ical`'s `rrule` mapping source is the **raw RFC5545 string**
  (`FREQ=WEEKLY;BYDAY=TU;UNTIL=...`), unparsed — `IcalParser.php` in `feeds_ical`.
- Smart Date's own Feeds target plugin is a dumb passthrough (`SmartDate.php` under
  `smart_date/src/Feeds/Target/`) — it does not interpret `rrule` at all, just writes whatever it's
  handed straight into that integer column. Mapping the raw string there (the reason it's left blank
  in the mapping config today) would corrupt the field, not create a recurring series.
- There is no existing converter anywhere in `feeds_ical` or `smart_date`/`smart_date_recur` from a
  raw RRULE string to Smart Date's decomposed rule format (`freq`, `start`/`end`, `limit`,
  `parameters` on the `smart_date_rule` entity). `simshaun/recurr` (already a transitive dependency,
  pulled in by `smart_date_recur`) can parse an RRULE string into its parts, so a bridge is
  buildable — either a custom Feeds target plugin or an `apc_calendar` presave hook that parses the
  string, creates a `SmartDateRule` entity, and points `field_event_date[$delta]['rrule']` at its ID
  — but it would need to correctly handle every RRULE shape the real feeds emit (`BYDAY` lists,
  `INTERVAL`, `COUNT` vs `UNTIL`, the all-day/`UNTIL` timezone quirks already documented above as a
  real bug source on the manual path), with no contrib precedent or test coverage to lean on.
- **Decision:** given the current volume — 12 recurring series, all plain weekly, curated by hand
  once per import — this was judged a real scope expansion, not worth building for now. Recurrence
  stays a manual curation step. Revisit only if the recurring-series count or RRULE variety grows
  enough that hand-curation becomes the bottleneck.

## Traps found in the real data

- **feeds_ical does not expand RRULE.** Recurrence is set by hand during curation. All 12 rules are
  plain weekly, so this is quick — but it is manual, every time.
- **`UNTIL` is UTC and will trick you.** `UNTIL=20260917T045959Z` is 23:59:59 Central on
  **September 16**. Enter the local date in Smart Date or every series runs a day long.
- **`INTERVAL=4` is not monthly.** "Blue Action North Austin Monthly Meeting" recurs every 28 days.
  Reproduce the rule, not the title.
- **The same series can arrive as several VEVENTs.** That same Blue Action meeting appears three
  times with different `UNTIL` dates — someone kept extending it in Google, and each has its own UID.
  GUID dedupe cannot catch this because they are genuinely distinct entries. Eyeball titles for
  repeats.
- **Two series have no `UNTIL`.** `field_event_date` has `month_limit: 12`, so Smart Date generates a
  year and stops. They need extending annually and nothing will remind you.
- **All-day events.** Smart Date represents these as duration **1439** minutes (00:00–23:59), not a
  zero-length event at midnight. Verify what feeds_ical actually produces for the 6 `VALUE=DATE`
  events on the first manual import; if wrong, a Tamper plugin on the end value fixes it.
- **ICS folds lines at 75 characters.** Any `grep`-based inspection of the raw file truncates values.
  The parser handles it; your eyeballing does not.

## Bugs found only by running a real import

None of these were visible from reading the feed or the module source in isolation — each only
showed up by actually importing the live Forward TX feed and checking the resulting nodes. Fixed in
`apc_calendar.module` / the feed type's own mapping config, not worked around at the data level.

- **`field_event_source`'s reference-by setting must be Term ID, not Name.** The feed-field-as-
  mapping-source approach this doc bet on (see "Genericity") does work — `Feed: Event Source`
  appears as a real mapping source — but the value it hands the mapping is the feed's own
  `field_event_source` **target_id** (an integer, e.g. `173`), not the term's label. Mapping it with
  the target's default "Reference by: Name" setting tried to look up a term literally named `173`,
  failed, and the whole node failed validation. Fixed by changing that mapping's `reference_by`
  setting to `tid`.
- **The current-user auto-publish permission check fires during an admin-triggered import.**
  `apc_calendar_node_presave()` publishes a new `calendar_event` immediately when
  `\Drupal::currentUser()` holds `publish calendar_event content immediately` — written for the
  human-submission form flow, where "current user" is the actual submitter. Feeds saves
  programmatically under whichever account triggered the import (an admin clicking Import, or
  cron's account), so that check fired on every single imported node and defeated the entire
  curation queue on the very first import (all 108 events published immediately). Fixed by skipping
  that branch outright whenever the node carries a non-empty `feeds_item` — Feeds-imported nodes are
  never eligible for this permission-based auto-publish, regardless of who is running the import.
- **`feeds_ical` hardcodes UTC as the default timezone for bare `VALUE=DATE` all-day entries, with
  no feed-type setting to override it.** (`IcalParser.php`: `'defaultTimeZone' => 'UTC'`, passed
  straight to the underlying ICal library.) A source like `DTSTART;VALUE=DATE:20260913` has no
  timezone of its own by iCal spec, so the library resolved it against that hardcoded default and
  produced 2026-09-13 00:00:00 **UTC** — which, rendered in the site's actual default timezone
  (America/Chicago), displays as **September 12, 7:00 PM**: the event looks like it starts the
  previous evening instead of being an all-day event. Not worth patching the contrib module for six
  events a year; fixed with a narrow, DST-safe presave correction
  (`_apc_calendar_fix_feeds_all_day_timezone()`) that detects the exact signature — a Smart Date
  item with duration exactly 1440 minutes whose start *and* end both land on an exact UTC
  midnight — and re-anchors both to the actual local midnight for that calendar date, computed via
  PHP's real timezone database so it stays correct on both sides of the DST boundary. A genuine
  24-hour timed event starting at local midnight would not hit this signature except in the one
  timezone where local and UTC midnight coincide, so this does not misfire on real timed events.
- **The `dtstartTimezone` source is not a timezone name.** Tried mapping it to Smart Date's Timezone
  sub-target, expecting something like `America/Chicago`; `feeds_ical` actually concatenates the
  datetime string directly onto the TZID (`IcalParser.php`: `dtstart_array[1] . TZID`, e.g.
  `2026-09-06T16:00:00America/Chicago`), which Smart Date's timezone select correctly rejects as not
  a valid choice. Left this sub-target unmapped instead — the site's own default timezone is already
  America/Chicago (`system.date.timezone.default`), matching Forward TX, so Smart Date's own
  fallback produces the right answer with no mapping at all. Verified this is DST-safe by checking
  the one event that straddles the Nov 1 2026 boundary: its stored UTC timestamps decode to the
  correct local wall-clock time on both sides purely because Smart Date/PHP resolve the site
  timezone at render time, not at import time.
- **Google's DESCRIPTION HTML needs `basic_html`, not `plain_text`.** Live descriptions carry real
  `<a>`, `<br>`, `<p>`, `<span>` markup (confirmed against an actual event body, not assumed from the
  spec). Mapped with the body target's default `plain_text` format, that HTML shows as literal
  angle-bracket text instead of rendering — links dead, paragraphs one wall of text. Switched the
  mapping's filter format to `basic_html` (Drupal's filtered/sanitized format — appropriate here
  since this is third-party HTML from an external source, not something to trust with `full_html`).

## Other sources

### Austin Chronicle — blocked, investigate before promising anything

```
https://calendar.austinchronicle.com/austin/EventSearch?eventSection=11485675&sortType=date&v=g
```

Fetching that URL returned a **completely empty body**, which means either client-rendered
JavaScript or bot-blocking. Both matter enormously, so settle this first:

```
curl -s '<the URL above>' \
  | grep -ioE '<link[^>]*(rss|atom|ical|calendar)[^>]*>|href="[^"]*\.(ics|rss|xml)[^"]*"' | sort -u
```

Also just look at the page in a browser for a subscribe/RSS/iCal affordance. `v=` in the URL is
clearly a view switch, so a feed variant may exist.

Three outcomes, in descending order of preference:

1. **They publish a feed.** Add an `rss_event_import` (or iCal) feed type, one feed, done. Everything
   else in this document applies unchanged.
2. **No feed, but the HTML is server-rendered.** `feeds_ex` gives Feeds an XPath/QueryPath parser.
   Workable, but scrapers break whenever the markup changes, and you will not be told.
3. **No feed and the page is JavaScript-rendered.** `feeds_ex` parses *raw* HTML, so there is nothing
   to select and no Drupal module fixes it. This would need a headless browser in the pipeline, which
   is well outside what this site should carry. **Treat this outcome as "don't."**

An empty `curl` body confirms outcome 3.

### Before scraping anyone, note the difference in kind

Forward TX is a partner nonprofit publishing a calendar it presumably wants amplified. The Austin
Chronicle is a commercial publisher whose curated listings *are* its product, with terms of service,
and whose event descriptions are its copyrightable writing even though the underlying facts are not.

Practically: scrapers get IP-blocked at exactly the moment you have come to depend on them, and
republishing descriptions verbatim is the part with real exposure. **Ask first** — a nonprofit
community calendar that links back is the sort of thing publications often say yes to, and a
sanctioned feed beats a scraper you have to keep repairing. If the answer is no, importing bare facts
(title, date, venue) and linking out is far more defensible than mirroring their copy.

The same applies, more gently, to Forward TX: ask for permission and agreed attribution wording.
`field_source_url` on the `event_sources` term exists so you can offer them a credited link back.

## Collisions

`virtual-events-task.md` added a rule that location is required when an event is not virtual, and
**imported events arrive with no `field_location`.**

**Resolved — no action needed.** Verified in `apc_calendar.module`: enforcement is
`$form['#validate'][] = 'apc_calendar_form_node_calendar_event_form_validate_location'`, a form
validate handler, and there are no constraint plugins in the module. Feeds saves programmatically and
bypasses form validation entirely, so imports are unaffected.

**Keep it that way.** If anyone later promotes that rule to an entity-level constraint — which is the
"more correct" refactor and therefore tempting — **every Feeds import will fail validation.** Leave a
comment at the validate handler saying so before someone discovers it the hard way.

## Sequence

1. [x] Confirm permission from Forward TX. Confirmed 2026-09-03 by Wendy, the calendar's author.
2. [x] `composer require 'drupal/feeds_ical:^3.1'`, enable.
3. [x] Create `event_sources` vocabulary + `field_source_url`; add the "Forward TX" term.
   `field_source_url` was set to `https://forwrd-tx.org`, inferred from the feed URL's
   `info%40forwrd-tx.org` owner address rather than confirmed directly with Wendy — worth double-
   checking against whatever URL she'd actually want credited.
4. [x] Add the three fields to `calendar_event`; add `field_event_source` to the feed bundle. Both
   entity reference fields defaulted to a stray `apc_locations`-scoped "Reference method" (this
   site's custom location-autocomplete selection plugin, apparently offered as the Field UI's first
   option for any new taxonomy-term reference field) — had to be switched to "Default" and the
   vocabulary re-picked by hand on both, or every event/feed would have silently referenced the
   `locations` vocabulary instead of `event_sources`.
5. [x] Create the `ical_event_import` feed type and mappings. **Verified the feed-field mapping
   source exists** — it does (`Feed: Event Source` in the source dropdown) — but see "Bugs found
   only by running a real import" for what actually needed configuring around it (`reference_by:
   tid`, not the default `name`).
6. [x] Create the Forward TX feed. **Imported once by hand** — surfaced all the bugs below.
7. [x] Inspected what actually arrived. Real bugs, not just the anticipated timezone/duration
   questions — see "Bugs found only by running a real import" above. The DST boundary and all-day
   duration questions this step was written to check both came back clean once those bugs were
   fixed; see that section for the actual verification.
8. [x] Build the `imported_events` view; add the exclusion filter to `pending_events`. Two purpose-
   built VBO actions (`apc_calendar_accept_imported_event`, `apc_calendar_reject_imported_event`) do
   the Accept/Reject half — Accept extends the existing `PublishEventAndLocation` action (item F)
   and additionally sets `field_import_state = accepted`; Reject only ever sets `field_import_state
   = rejected`, per "Rejection: flag, never delete" above. Lives at `/admin/imported-events` (admin
   theme, matching `pending_events`), full pager, filtered to "future OR recurring" — see "Do not
   filter past events at import time" above for why and how, including the Views bug that ruled out
   the obvious filter-group approach.
9. [x] Scheduled import enabled (weekly), now that permission is confirmed.

## Verify before calling it done

1. [x] Import twice in a row. Second run creates **zero** new nodes. Confirmed via
   `drush feeds:import 3` — "There are no new Calendar Event items."
2. [x] Reject an event, run the import again. It does **not** come back. Confirmed the same way.
3. [x] Confirm imported events do **not** appear in `pending_events`. Confirmed — stayed at exactly
   28 (the pre-existing human submissions) after importing 108 events.
4. [x] Confirm a published imported event shows its source attribution and links back. Did not
   originally — `node--calendar-event--full.html.twig` had no markup for `field_event_source` at
   all. Added an "Imported from [Source]" line (linking to the term's `field_source_url` when set)
   to `apc_brown_preprocess_node()` / the template, gated on the field being non-empty since empty
   is meaningful (a human submission).
5. [x] Checked one all-day event and one event either side of 2026-11-01 against the Google UI. Both
   were wrong before the fixes in "Bugs found only by running a real import" and correct after.
6. [x] `ddev drush cst`, reviewed the diff, then `ddev drush cex -y`. Config sync clean.

**Not yet done:** deploying any of this to production. Everything above was built and verified
against the local DDEV site only — see CLAUDE.md's Deployment section for the actual rollout, and
decide deliberately whether the ~108 events already imported locally during this build-and-verify
pass should ship as-is or be re-imported fresh once live.
