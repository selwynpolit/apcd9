#APC Drupal 11 version

## Hosting
hosted at greengeeks.com


## Sites
- www.austinprogressivecalendar.com - Drupal 10 live site - ~/www/d9 (/home/austinpr/www/d9)
- d9.austinprogressivecalendar.com - redirects to Drupal10 live site
- 'database' => 'austinpr_d9', 'username' => 'austinpr_d9',

- dev.austinprogressivecalendar.com - Drupal 11 dev site - ~/www/apcdev
- 'database' => 'austinpr_apcdev', 'username' => 'austinpr_apcdev',
- typically deploy develop branch here

- d7.austinprogressivecalendar.com - old d7 live site - ~/www/live
- 'database' => 'austinpr_apclive',   'username' => 'austinpr_apclive',

- d7dev.austinprogressivecalendar.com - d7 dev site - ~/www/dev
- 'database' => 'austinpr_d7/domaindev','username' => 'austinpr_apcdev',

From Domains in GreenGeeks cPanel
- austinprogressivecalendar.com - /public_html
- d7.austinprogressivecalendar.com - /public_html/live/docroot
- d9.austinprogressivecalendar.com - /public_html/d9/web
- dev.austinprogressivecalendar.com - /public_html/dev/docroot


# .htaccess for prod site only. Each site has its own Drupal .htaccess in its web root.
From ~/www:

```
#RewriteEngine on
#RewriteRule (.*) live/docroot/$1 [L]

#RewriteBase /web

RewriteEngine on
RewriteCond %{HTTP_HOST} ^austinprogressivecalendar.com$
RewriteCond %{REQUEST_URI} !^.*www.*$
RewriteRule ^(.*)$ http://www.austinprogressivecalendar.com [R=301]

RewriteCond %{HTTP_HOST} ^www\.austinprogressivecalendar\.com$ [NC]
RewriteRule ^$ d9/web/index.php [L]
RewriteCond %{HTTP_HOST} ^www\.austinprogressivecalendar\.com$ [NC]
RewriteCond %{DOCUMENT_ROOT}/d9/web%{REQUEST_URI} -f
RewriteRule .* d9/web/$0 [L]
RewriteCond %{HTTP_HOST} ^www\.austinprogressivecalendar\.com$ [NC]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule .* d9/web/index.php?q=$0 [QSA]
```



## Gitflow
- work in develop branch
- merge into main
- deploy main



## DDEV

Create a .ddev/config.local.yaml and include the following:

```
router_http_port: "80"
router_https_port: "443"
timezone: America/Chicago
```


## Deployment
- ssh into greengeeks
- cd ~/www/d9
- git pull
- composer install --no-dev
- drush updb
- drush cr
- drush cst
- drush cim -y
- drush cr


## Local setup
- clone repo: `git clone git@github.com:selwynpolit/apcd9.git apc3`
- ddev config
- edit .ddev/config.yaml to remove the upload_dirs entry for upgrade_status/tests/modules/upgrade_status_test_11_compatible/node_modules
- also remove name from .ddev/config.yaml
- create .ddev/config.local.yaml with timezone: America/Chicago, name: apc3 (assuming this is your dir name)
- ddev start
- ddev composer install
- setup sites/default/settings.local.php from `example.settings.local.php`
- optionally add
  # $config['config_split.config_split.dev']['status'] = TRUE;
  # $config['config_split.config_split.local']['status'] = TRUE;

- grab prod db: `ddev drush @apc.prod sql-dump >dbprod.sql`
- gzip it: `gzip dbprod.sql`
- import it: `ddev import-db --file=dbprod.sql.gz`
- launch site: `ddev launch $(ddev drush uli)`


## Config Split

Two splits: `local` (devel tools, aggregation off) and `dev` (mirrors the GreenGeeks dev box:
stage_file_proxy on, no devel, prod-level aggregation). Prod is the base — no split active.

**Enable a split via `web/sites/default/settings.local.php`.** This is a runtime override; it is
NOT exported, so `cim`/`cex` stay clean:

```php
$config['config_split.config_split.local']['status'] = TRUE;
// $config['config_split.config_split.dev']['status'] = TRUE;   // use ONE at a time, not both
```

Enable only one at a time (both list `stage_file_proxy.settings`). Leave the committed
`config_split.config_split.*.yml` files at `status: false`.

To confirm which split is active, the only way is to look at https://apc3.ddev.site/admin/config/development/configuration/config-split under Current Status.  You will see "active(settings.php)" for the currently active split.  The other will show "inactive".


Day-to-day (local enabled via the override):
1. Make the change in the Drupal UI.
2. `ddev drush cst` — check what changed.
3. `ddev drush cex -y` — clean; the override never leaks into the export.
4. `git diff config/sync/` and commit.

Notes:
- **Do NOT** use the config-split `activate`/`deactivate` drush commands or the admin UI links.
  They write `status: true` into stored config, which then dirties every `cex`
  (`config_split.config_split.local.yml` keeps flipping to `true` — the phantom-diff problem).
  The settings.local.php override avoids this entirely.
- Preview production locally: comment out the override line + `ddev drush cr`. Uncomment + `cr` to
  return.
- Check current mode: `ddev drush cst` (clean = prod baseline), or
  `ddev drush ev 'var_dump((bool) \Drupal::config("config_split.config_split.local")->get("status"));'`.
- Changing what's *inside* a split (e.g. a new dev-only module/setting): enable that split via the
  override, make the change, `cex` (it lands in `config/split/<name>/`), commit that dir.


## Setup on Greengeeks
- in Greengeeks cpanel add a new database e.g. austinpr_apcdev
- Add a db user austinpr_apcdev with all privs and access to austinpr_apcdev db
- in ~/www/apcdev git clone git@github.com:selwynpolit/apcd9.git apcdev
- in Greengeeks, add a "domain" in the cpanel:
dev.austinprogressivecalendar.com pointint to /public_html/apcdev/web
- In ~/www/apcdev run composer install (no-dev may be an option for testing prod setup)
- Add a trusted host for your new domain in settings.php: '^dev.austinprogressivecalendar\.com$'

Add a web/sites/default/settings.local.php which looks like:

```php
<?php

$databases['default']['default'] = array (
  'database' => 'austinpr_apcdev',
  'username' => 'austinpr_apcdev',
  'password' => 'password goes here',
  'prefix' => '',
  'host' => 'localhost',
  'port' => '3306',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
);

$settings['hash_salt'] = 'bgpC1g9Dz6_kIH5LpsT5-IvYkT1AzBXtxnqsPDYIGMtCr2_hnvOOQXZs6UHEBvvaxIWQb5q1pw%';

// 1-4-24: for submitting sitemap to search engines.
$settings['simple_sitemap_engines.index_now.key'] = '9f170430-2830-413f-9410-f76f761f8f0b';
```
- drush cim -y in ~/www/apcdev
- drush cr
- enjoy!

## Bulk photo import

Here is the sequence to follow:

```
drush apc:prep-photo-batch <directory>        # thumbnails + task brief
drush apc:merge-photo-batch <directory>       # folds AI-authored descriptions into manifest.yml
drush apc:review-photo-batch <directory>      # writes .prep/review.html (thumbnail + text, side by side)
drush apc:import-photos <directory>           # creates the community_photo nodes
drush apc:reset-photo-batch-nids <directory>  # before pushing an already-imported batch elsewhere
```

**Prep and merge run locally, against local files — no rsync needed for either.**
`apc:prep-photo-batch` actually opens every image (decodes it, reads EXIF, writes a
thumbnail), so it needs the real files on whatever filesystem it runs against; since the
photos start out on your Mac, that means running it in `ddev`, not remotely. `rsync` only
enters the picture once, right before the import step — that's the only part that
actually needs a specific site's database.

Actual sequence:
1. Put the photos in `photo-batches/2026-09/` (repo root, gitignored).
2. Prep them locally. This writes thumbnails, a task brief `.prep/TASK.md`, and a skeleton `manifest.yml`.
```shell
ddev drush apc:prep-photo-batch photo-batches/2026-09
```
3. In your Claude Code chat, ask Claude to describe the batch — this is a normal chat
   message, nothing special to install or run:
   > Please describe the photos in photo-batches/2026-09 using a Haiku subagent. Read
   > .prep/TASK.md in that directory for the brief, then write one JSON file per photo
   > into .prep/rows/.

   Claude reads the brief and spawns the subagent itself.
4. Merge the descriptions into `manifest.yml`, locally:
```shell
ddev drush apc:merge-photo-batch photo-batches/2026-09
```
5. Build a visual review page — each thumbnail next to its own text, instead of
   cross-referencing `.prep/thumbs/` against the YAML by eye:
```shell
ddev drush apc:review-photo-batch photo-batches/2026-09
```
   Open `photo-batches/2026-09/.prep/review.html` directly in a browser.
   It's read-only; flagged photos (`NOT-A-PHOTO`/`POSSIBLE-REPOST`) are
   highlighted with a jump list at the top.
6. Edit `manifest.yml` by hand — this is the actual review/edit step (on another
   monitor, alongside the page from step 5). Check captions/alt text against the actual
   photos (especially any with several signs in one frame), decide on any
   `suggested_new_categories` the merge step lists — note that `apc:import-photos` will
   create these automatically now, so remove one here if you don't want it kept — and
   drop or fix anything wrong. **Re-run step 5 any time to see edits reflected.**
7. Import locally to see it for real:
```shell
ddev drush apc:import-photos photo-batches/2026-09 --dry-run   # check first
ddev drush apc:import-photos photo-batches/2026-09
```

### Promoting the same batch to dev, then prod

No need to redo prep/describe/merge/review for this — the manifest is fully reusable.
The one thing that isn't: local's import just wrote a `nid` into every row it created,
and `nid` means "already imported" only relative to *that* database. Reset it once,
before the first push elsewhere:
```shell
ddev drush apc:reset-photo-batch-nids photo-batches/2026-09
```
Then, for dev:
```shell
ddev drush rsync photo-batches @apc.dev:%files -- --exclude=.DS_Store --exclude='._*' --exclude=.prep --info=progress2
ddev drush @apc.dev apc:import-photos /home/austinpr/public_html/apcdev/web/sites/default/files/photo-batches/2026-09 --cleanup
```

For prod:
```shell
ddev drush rsync photo-batches @apc.prod:%files -- --exclude=.DS_Store --exclude='._*' --exclude=.prep --info=progress2
ddev drush @apc.prod apc:import-photos /home/austinpr/public_html/d9/web/sites/default/files/photo-batches/2026-09 --cleanup
```
Note. leave off --cleanup if you want to keep the staging folder on dev/prod for any reason.  It will be removed if you use --cleanup.


**`apc:import-photos` needs a literal absolute path**
- dev: `/home/austinpr/public_html/apcdev/web/sites/default/files/photo-batches/2026-09`
- prod: `/home/austinpr/public_html/d9/web/sites/default/files/photo-batches/2026-09`


**`--cleanup` removes the whole remote staging folder, not just the photos.** It deletes
each original photo as its row imports (as before), and once every row in the manifest
has a `nid` and nothing failed, `manifest.yml` and the now-empty batch directory too —
nothing is left on dev/prod afterward.

Full workflow, why the reset step matters, and known gaps:
[assets/Plans/photo-batch-import-task.md](assets/Plans/photo-batch-import-task.md).


## Importing events from external calendars

Feeds import iCal calendars (Forward TX is the first source) into `calendar_event` as
**unpublished**, for a human to curate — never straight to published.

**Run an import:**
- Automatic: runs weekly on cron.
- Manual: `/admin/content/feed` → the feed's "Import" link, or `ddev drush feeds:import <fid>`
  (find `<fid>` on that same page).

**Curate what came in** at `/admin/imported-events`:
- Check `Raw location` and `Recurrence` (recurring series need Smart Date recurrence set by hand —
  it's never auto-populated) against the source calendar.
- Select rows, then **Accept (publish and mark accepted)** or
  **Reject (keep unpublished, mark rejected)** from the bulk action dropdown.

**Things to watch for while reviewing:**
- *Recurrence — only applies to rows that actually have one.* A plain one-off event's date imports
  correctly with nothing to edit. Only rows with an `RRULE` need manual work, because `feeds_ical`
  never expands RRULE or populates Smart Date's recurrence — the `Recurrence` column is raw RRULE
  text for your reference only.
  - **A past `DTSTART` on a recurring row is normal — don't change it to today's date.** It's the
    series' real, original start (often a year+ old for a standing weekly meeting) and is the
    correct anchor for the weekday/time-of-day. Enter the recurring rule (weekly, etc.) on top of
    that real start date; Smart Date then generates every instance of the pattern, past and future,
    each as its own row — past instances simply land in "Past Events," which is expected. Moving the
    start date to today risks shifting which weekday the series actually runs on.
  - Its `UNTIL` date is in **UTC** and reads a day later than the real local last occurrence (e.g.
    `UNTIL=20260917T045959Z` is Sept 16, 11:59pm Central). Enter the correct local end date — don't
    copy the UTC date literally.
  - `INTERVAL` isn't always what the title implies — a "Monthly Meeting" with a weekly rule and
    `INTERVAL=4` recurs every 28 days, not on a calendar month. Check the actual rule, not the name.
  - The same series can arrive as **several separate rows** — if an organizer extended a recurring
    meeting in Google Calendar over time, each extension has its own UID, so GUID dedupe can't merge
    them. Eyeball titles for repeats before accepting.
  - A series with no `UNTIL` only gets Smart Date's default 12-month cap and quietly stops after a
    year — nothing reminds you to extend it.
- *Location.* `Raw location` is free text from the source, not a location term — the same venue can
  show up as several differently-formatted strings. Match or create the real `locations` term
  yourself during review.
- *Scope.* Only future events or recurring series appear in this queue — past one-off events from
  the source are intentionally excluded.
- **Re-importing never duplicates or overwrites anything you've already curated.** Confirmed by
  testing: running the import twice in a row created zero new nodes the second time. Dedup is by the
  iCal `UID` (stored via `feeds_item`), and the feed type is set to never update existing items — so
  hand-set recurrence, location, or an accept/reject decision on an already-imported node is
  permanently safe from a later import run, as long as you don't delete the node.
- Rejected events do **not** re-import on the next run, but they're never deleted either — deleting
  one *would* cause it to come back forever, since the dedupe record lives on the node itself. Leave
  rejects in place, unpublished.

**Add a new source calendar** (same iCal format as Forward TX):
1. Add a term to the `event_sources` vocabulary (name + `field_source_url` for attribution).
2. `/admin/content/feed` → add feed → type **iCal event import**, paste the calendar's `.ics` URL,
   set its `Event Source` field to the new term.
3. Import once by hand and check the results at `/admin/imported-events` before trusting the weekly
   cron run.

A calendar published as RSS/Atom or scraped HTML instead of iCal needs a new feed *type*, not just a
new feed — see [assets/Plans/event-import-task.md](assets/Plans/event-import-task.md) ("Genericity")
before building one.

### Reproducing a feed on another environment (dev, prod)

**A feed is content, not config** — `drush cim`/`cex` never move it. `drush cst` will show nothing
even though dev/prod is completely missing a feed that exists on apc3. There is no export/import
shortcut for this (checked: Feeds' own drush commands only manage feeds that already exist, and no
content-staging module like `default_content`/`content_sync` is installed) — until an update hook to
create this automatically is built, it has to be repeated by hand, once per environment.

To reproduce the Forward TX feed:
1. Taxonomy → Event Sources → Add term. Name: `Forward TX`. `field_source_url`: `https://forwrd-tx.org`.
2. `/admin/content/feed` → Add feed → type **iCal event import**. URL:
   `https://calendar.google.com/calendar/ical/info%40forwrd-tx.org/public/basic.ics`. For the
   **Event Source** field, type `Forward TX` and pick the term the autocomplete finds — it'll show as
   `Forward TX (<id>)`. That `<id>` is just the term's ID *on this environment*; it will not match
   apc3's (173) or any other site's, and that's fine — don't type a number, let autocomplete resolve it.
3. Import once by hand at `/admin/content/feed` and check `/admin/imported-events` before trusting
   the weekly cron run.

Full design, real bugs hit on the first live import, and why several tempting shortcuts (auto-
expanding recurrence, deleting rejects, an entity-level location-required constraint) were rejected:
[assets/Plans/event-import-task.md](assets/Plans/event-import-task.md).
