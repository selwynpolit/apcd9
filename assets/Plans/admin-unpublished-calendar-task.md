# Show unpublished events in the calendar to admins (marked)

**Status:** designed, not started. Deferred from an autonomous session because the core step
(exposing unpublished content in a public view) is security-sensitive and needs a decision.

## Goal

On `/calendar`, let privileged users (administrators, and possibly `event_manager`) see
*unpublished* `calendar_event` nodes alongside published ones, visually distinguished — either a
different color or a `**` prefix on the title. Everyone else keeps seeing published events only.

## Why it isn't a quick change

`config/sync/views.view.calendar.yml` hard-filters `status: value '1'` (published only) for
**all** viewers (see the `filters.status` block, ~line 179). So unpublished events are invisible to
everyone right now. Two things have to happen, and the first carries the risk:

### 1. Expose unpublished events to the right users only (the decision)

Replace the plain boolean `status = 1` filter with Views' **"Published status or admin user"**
filter (`plugin_id: node_status`, the `status_extra` field). It shows published nodes to everyone
and unpublished nodes only to users who can view them.

**The catch — it may not match this site's custom access.** `node_status` keys off core
permissions (`bypass node access` / `view own unpublished content`), while this site controls
unpublished-event visibility through the custom `apc_calendar_node_access()` (grants view to
`event_manager` + `administrator`). So out of the box, `node_status` would likely reveal unpublished
events in the calendar to **administrators only** (who have `bypass node access`), *not*
`event_manager`. Decide up front:

- **Administrators only** → `node_status` filter is enough, no custom code for access.
- **`event_manager` too** → either grant `event_manager` a suitable core permission, or add a
  custom Views filter/query alter that mirrors `apc_calendar_node_access()`. More work.

**Cache correctness (don't skip):** once the calendar's rows vary by viewer, the view render + the
FullCalendar `drupalSettings` serialization must carry `user.permissions` (or `user`) cache context,
or a cached anonymous page could leak unpublished titles. FullCalendar View serializes every event
into `drupalSettings` on load, so this applies to the whole calendar render, not just a block.
Verify against the running site with an anonymous request after the change.

### 2. Mark the unpublished ones (the easy, safe half)

Best done in a new `FullcalendarViewProcessor` plugin (same pattern as the existing
`EventPopupProcessor` / `EventHoverDataProcessor` in
`web/modules/custom/apc_calendar/src/Plugin/FullcalendarViewProcessor/`). In `process()`, walk
`calendar_options['events']` and, for each entry whose node is unpublished:

- **`**` prefix (verifiable without CSS):** prepend `** ` to `entry['title']`. Purely data-level —
  testable straight from `drupalSettings`, no styling needed. Reuse `EventEntryNidTrait` to resolve
  the nid, then load status (or, better, have step 1's view expose the status field so the processor
  reads it from the row without an extra load).
- **Color (needs CSS):** add a class via `entry['classNames']` (FullCalendar supports per-event
  `classNames`), e.g. `apc-event--unpublished`, then style it in the theme (a muted/striped
  treatment reads better than a saturated color for "draft"). FullCalendar View passes `classNames`
  through if set on the event object.

Recommendation: do **both** — `**` prefix *and* a class — so it's unmistakable and the prefix
survives even where the color is subtle.

## Sequence

1. Get the decision on **which roles** (administrators only vs. + `event_manager`).
2. Swap the calendar view's `status` filter to `node_status`; add the cache context; verify
   anonymous still sees only published (test against the running site — the standing rule).
3. Add the marking processor (`**` prefix + `classNames`), attach a small CSS rule in `apc_brown`.
4. Verify: as anon (published only, no marks), as admin (unpublished visible + marked).

## Note for whoever picks this up

The `**`-prefix half is data-level and easy to verify from `drupalSettings`/curl (no CSS needed —
useful, since this environment's in-app browser blocks the ddev host's CSS). The color half needs a
real browser that can load the theme CSS.
