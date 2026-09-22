# Anonymous site sweep (automated smoke test)

A repeatable "look at the whole site as a stranger" check, run against the local DDEV
site (or production, read-only). It catches the kind of bug that is invisible to an
admin-logged-in developer: things that overflow a phone screen, overlays with an
off-screen close button, dead links, PHP notices, and so on.

There is no PHPUnit/JS suite in this repo (see `CLAUDE.md`), so this is the closest thing
to a regression check. Run it after any theme/CSS/JS change that could affect layout, and
before a deploy.

Two halves:

| Half | File | Checks |
|---|---|---|
| HTTP | `_readme/site-sweep-http.py` | status codes, PHP/Drupal error text, `<title>`/viewport, every internal link and image, `<img>` without `alt` |
| Browser | `_readme/site-sweep-browser.js` | per page at desktop + phone width: horizontal scroll, broken/over-wide images, tap targets, tiny text, sticky bars, `<h1>` count; and every overlay (popup, lightboxes): fits the screen, close control reachable, Esc / backdrop click / back gesture all close it and leave no stray history entry; the `/photos` collapsible filters |

## 1. HTTP sweep (always anonymous, no browser)

```
python3 _readme/site-sweep-http.py                                   # local DDEV site
python3 _readme/site-sweep-http.py https://www.austinprogressivecalendar.com   # production (GET/HEAD only)
```

Plain HTTP requests carry no cookies, so this is anonymous by construction. Takes ~25 s.
Output is `PROBLEMS` (real) and `ADVISORY (accessibility)` (missing `alt`, collapsed). Exit
code 1 if there are any problems.

To tell a local-only problem from a real one, HEAD the same path on production — the dead
legacy links/images below 404 on both.

## 2. Browser sweep (needs a logged-out browser tab)

The real Chrome (`mcp__claude-in-chrome__*`) is normally logged in as admin, and admin
markup hides anonymous-only bugs, so log out first. Permission to do this on the **local
DDEV site** was given explicitly; it does not extend to production or any other site.

1. **Log out:** navigate to `/user/logout`, click **Log out** on the confirm page.
2. **Load the script** into the page. It is served from the git-ignored public files dir
   so it never needs pasting inline:
   ```
   cp _readme/site-sweep-browser.js web/sites/default/files/apc-site-sweep.js
   ```
   then in `javascript_tool`:
   ```js
   await new Promise((res, rej) => { const s = document.createElement('script');
     s.src = '/sites/default/files/apc-site-sweep.js?' + Date.now();
     s.onload = res; s.onerror = rej; document.head.appendChild(s); });
   ```
3. **Run** (both widths; 1280 = desktop, 412 = Pixel-8-class phone):
   ```js
   const pages = ['/', '/calendar', '/calendar/upcoming', '/node/295', '/locations/acc-highland',
                  '/photos', '/photos/cnn-banned-white-house', '/taxonomy/term/144', '/contact',
                  '/user/login', '/node/add/calendar_event', '/node/add/community_photo',
                  '/blog', '/whats-it-all-about'];
   for (const width of [1280, 412]) console.log(await apcSweep.pages(pages, width));
   await apcSweep.overlays('/', 412);                 // popup + hero/wall lightboxes
   await apcSweep.overlays('/calendar/upcoming', 412);
   await apcSweep.overlays('/photos', 412);           // rich lightbox
   await apcSweep.photosFilters('/photos', 412);      // collapsible filters (closed) ...
   await apcSweep.photosFilters('/photos', 1280);     // ... and always-open on desktop
   ```
   Run **~6 pages per call** and repeat for 1280 and 412 (and `overlays` for both widths).
   An empty `issues` array means the page passed.
4. **Clean up:** `rm web/sites/default/files/apc-site-sweep.js`, then log back in:
   ```
   ddev drush uli --uri=https://apc3.ddev.site
   ```
   and open the printed link. Navigate away from the password-edit page it lands on.

The page list above is a sample: swap in whatever changed. Event and photo node IDs
change; pick any published `calendar_event` / `community_photo`
(`ddev drush sql-query "select nid,type from node_field_data where status=1 and type in ('calendar_event','community_photo') order by nid desc limit 5"`).
`/calendar` on a phone is a *list* view; its popup opens from the `<a>` inside
`.fc-list-item` rows, on desktop from `.fc-event` (checked ad hoc, not in `overlays()`).

### Gotchas learned the hard way

- **Resizing the real Chrome window is unreliable.** It applies late and leaves the
  screenshot coordinate frame stale (clicks get rejected as "outside the coordinate
  frame" — take a fresh screenshot to resync). The script instead loads each page in a
  **same-origin iframe of the target width**, so media queries, fixed positioning and
  dialogs all behave as at that width. This is how the 800px-wide popup on a 412px phone
  was reproduced.
- **Never run two sweeps at once.** If the tool call errors ("Debugger is not attached",
  "extension disconnected") the run is *not* cancelled — it keeps going in the page and
  shares the single `#apc-sweep-frame` iframe with the next call, producing bogus
  "failed to load" results. Wait for the frame to disappear before re-running:
  `for (let i=0;i<100&&document.getElementById('apc-sweep-frame');i++) await new Promise(r=>setTimeout(r,1000));`
- **The browser tool redacts any output containing a query string** (`[BLOCKED: Cookie/query
  string data]`). The script strips `?…` from URLs it reports; if you still see the
  placeholder, return category prefixes only (`issue.slice(0, 24)`) to find out which
  check fired.
- **`read_console_messages` starts tracking on its first call** and can detach the JS
  debugger. Call it once *before* the sweep to start tracking, read it *after*, and don't
  overlap it with a running sweep. The only error seen so far is from a password-manager
  browser extension's autofill script, not the site.
- **Don't `.click()` an AJAX submit input inside the iframe.** Drupal binds those on
  `mousedown`, so a bare `click()` can fall through to a real form submit. To test
  "Add another item" and similar, use a real click on the real (top-level) page.
- **No form is ever submitted.** Anonymous submissions create real content and hit
  spam protection; forms are only loaded and measured.
- Ignored by design: Leaflet map tiles (they overhang their clipped map container).

## 3. Baseline (last run: 2026-09-21, local DDEV, anonymous)

Use this to tell new problems from known ones. Both widths passed the checks that matter
for layout except where noted.

**Fixed as a result of this sweep / the session it ran in:**
- Event popup was 800px wide on a phone (close button off-screen) → `max-width` in
  `event-popup.css`.
- Back gesture left the site instead of closing popup/lightbox → `js/overlay-history.js`.
- "Submit a photo, meme or sign" text spilled out of its button → auto-height buttons.
- `/photos` filters took several screens on a phone → collapsible `<details>`.
- **`/node/add/calendar_event` scrolled sideways on a phone (page 490px in a 412px
  viewport; Remove button clipped)** — the Event Date multi-value table → grid layout in
  `css/theme/apc.css`.
- Event popup close button was a 20×20px grey square → 44px round "×" (`event-popup.css`).
- GreenGeeks seal `<img>` had no `alt` (left its link with no accessible name on every
  page) → the footer "Green Website" block body. **That block is database content, not
  config, so it does not deploy with `git pull`:** `apc_calendar_update_10004()` applies the
  same edit on any environment that runs `drush updb` (idempotent; finds the block by
  UUID).
- `/` and `/calendar` had no `<h1>` → a visually-hidden `<h1>` on each (`front-page.html.twig`;
  `apc_brown_preprocess_views_view()` for the calendar view). Measured: 1×1px, and the calendar
  sits at exactly the same offset with or without it.

**Still open (not fixed; decide case by case):**

| Finding | Where | Notes |
|---|---|---|
| FullCalendar day numbers <24px; "Sun…" and `h2.block__title` text <12px | `/calendar` (desktop) | 65 small targets, mostly the month grid's day-number links. |
| "Show row weights" button (22px tall) visible to anonymous | `/node/add/calendar_event` | Drupal's tabledrag toggle; probably shouldn't be shown to submitters at all. |
| Photo category terms have no URL alias | `/taxonomy/term/144` etc., linked from the homepage | Works (200), just a bare URL; `locations` terms get `/locations/…` aliases from a pathauto pattern, `photo_categories` has none. |
| ~30 legacy content images with no `alt`; dead links/images (`/books`, `/selwyn-resume`, `imce_images/*`, …) | legacy pages and `/blog/*` | **Identical 404s on production** — migrated Drupal 7 content, not a local artifact. |
| `/blog` is 415px wide on a phone; `/permaculture` 578px | legacy blog image without `max-width`; a legacy `<iframe>` | Content-level. |

## Maintaining this

- New overlay/dialog? Add it to `overlays()` in `site-sweep-browser.js` — the checks are
  small (`fit()` plus close-path assertions).
- New page type worth covering? Add it to the page list above.
- A finding that is intentionally accepted goes in the "still open" table so the next run
  doesn't re-report it as new.
