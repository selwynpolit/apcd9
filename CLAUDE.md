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
