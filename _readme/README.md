#APC D9/D10 version

## Hosting
hosted at greengeeks.com


## Sites
- www.austinprogressivecalendar.com - Drupal 10 live site - ~/www/d9 (/home/austinpr/www/d9)
- d9.austinprogressivecalendar.com - redirects to Drupal10 live site
- 'database' => 'austinpr_d9', 'username' => 'austinpr_d9',

- dev.austinprogressivecalendar.com - Drupal 10 dev site - ~/www/apcdev
- 'database' => 'austinpr_apcdev', 'username' => 'austinpr_apcdev',


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

DON'T use the UI to "Deactivate"/"Activate" links at /admin/config/development/configuration/config-split.

Note.
- It sticks until you reverse it (another activate) or run a plain cim (which reloads whatever's committed — currently false, i.e. inactive, since that's local's safe default for every environment).
- Don't run cex while deactivated if you're mid-edit on local-only config — you'd be exporting the "no split" state and could lose track of what's local-only vs. base. It's safe to cex while deactivated if nothing's actually changed.

Prod isn't a split (it's the base with nothing active) and dev is a real split:

* Local: dev-friendly — devel tools, aggregation off:
ddev drush config-split:deactivate dev -y   # only needed if dev was active
ddev drush config-split:activate local -y
ddev drush cr

See https://apc3.ddev.site/admin/config/development/configuration/config-split
Current status will show inactive for dev, active for local.

* Dev (mirrors the GreenGeeks dev box — just stage_file_proxy on, no devel, prod-level aggregation):
ddev drush config-split:deactivate local -y
ddev drush config-split:activate dev -y
ddev drush cr
See https://apc3.ddev.site/admin/config/development/configuration/config-split
Current status will show active for dev, inactive for local.

* Prod (the real production baseline — nothing active):
ddev drush config-split:deactivate local -y  # no-op/safe if already inactive
ddev drush config-split:deactivate dev -y     # no-op/safe if already inactive
ddev drush cr

Notes:
- Verify which mode you're in any time with ddev drush config:get config_split.config_split.local status / ...dev status, or ddev drush cst (clean diff = you're in prod mode).
- local and dev both list stage_file_proxy.settings in their complete lists, but under different module-enable states — don't try to activate both at once, deactivate one before activating the other, as shown above.
- These rewrite active DB config immediately (see the mechanics I walked through last message) — they don't touch config/sync on disk unless you follow up with cex.

Normal day-to-day flow:
1. Leave local active — that's just your standing dev state, same as it's always been.
2. Make the change in the Drupal UI (add a field, edit a view, whatever).
3. ddev drush cst — check what's about to change (your existing standing rule, still applies).
4. ddev drush cex -y — safe to run with local active.
5. git diff on config/sync/ to see exactly what's shipping. Commit.

You never need to deactivate local for this — it's already routing the local-only stuff to the right place.

The only time you'd touch dev (or add to local's lists) is when you're deliberately changing something inside that split itself — e.g., tweaking stage_file_proxy's settings for the dev box, or deciding a new module/setting should be local-only or dev-only:
1. Deactivate local, Activate dev
3. Make the change via UI/settings form.
4. cex — lands in config/split/dev/, not config/sync/.
5. ddev drush config-split:deactivate dev && ddev drush config-split:activate local to get back to normal.
6. Commit both config/sync (if the split's complete/partial list changed) and config/split/dev/.


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

