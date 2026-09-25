# Production cron/login redirect bug — "Redirects to external URLs are not allowed"

Reported by Claude Fable 5.1 High
9-17-26


## Symptom

On **production** (`www.austinprogressivecalendar.com`), clicking "Run cron" from
`/admin/reports/status` redirects to `/admin/reports/status/run-cron?token=...`
and shows:

> Redirects to external URLs are not allowed by default, use
> `\Drupal\Core\Routing\TrustedRedirectResponse` for it.

Cron itself completes successfully (the error is only in the *redirect back*,
not the cron run). This does **not** reproduce on local (DDEV) or on
`dev.austinprogressivecalendar.com` / `d9.austinprogressivecalendar.com`.

Editing any event and clicking save caused the same error.



A fix was applied (hardcoding `$base_url` in `settings.php`, see below) and
**did not resolve it** — cron still fails after deploying that change. That's
where this task picks up.

## What's been ruled out (verified with hard evidence this session)

1. **Cloudflare / reverse-proxy scheme confusion.** `www.` is proxied through
   Cloudflare (confirmed via `server: cloudflare` response header); `d9.`/`dev.`
   are not. Initially suspected Drupal couldn't reconstruct the true
   scheme/host behind the proxy. Added `$settings['reverse_proxy']` +
   Cloudflare's published IP ranges to `settings.php` (scoped to the
   production-only `elseif (str_contains($app_root, '/d9/'))` branch) — **did
   not fix it**, and was later removed again after further diagnosis showed it
   was irrelevant: a temporary diagnostic script dropped directly on
   production (`web/_diag_tmp.php`, since deleted) proved GreenGeeks' own
   Apache layer already unmasks Cloudflare before PHP ever sees the request —
   `$_SERVER['REMOTE_ADDR']` is already the real client IP, `$_SERVER['HTTPS']`
   is already `on`, `HTTP_HOST` is already correct. This was a real dead end;
   removed cleanly (see `git log` on `settings.php` this session /
   conversation history for the exact revert).

2. **A hardcoded `$base_url` elsewhere in `settings.php`.** Checked — none
   existed before this session's changes.

3. **The `redirect` contrib module.** Confirmed **disabled** on production
   (`drush @apc.prod pm:list` shows `Redirect (redirect) — Disabled`), and its
   DB table doesn't even exist. Not the cause of this specific bug (though it
   may be related to a *different*, already-addressed "too many redirects"
   issue from an earlier conversation — the module was apparently disabled as
   a result of that).

4. **Drupal's own URL-generation vs. `RequestContext` validation logic, under
   a *simulated* bootstrap.** Ran `drush @apc.prod --uri=https://www.austinprogressivecalendar.com php:eval`
   to directly call `Url::fromRoute('system.status', [], ['absolute' => TRUE])->toString()`
   and `\Drupal::service('router.request_context')->getCompleteBaseUrl()` —
   under this simulated bootstrap, for **both** `www.` and `d9.` hostnames,
   the two values matched perfectly. No discrepancy. This is why the bug
   couldn't be reproduced via drush directly — it only shows up on a **real**
   HTTP request.

5. **Symfony/Drupal-11-specific regression.** Checked whether upgrading
   Drupal 10 → 11 (which bumped Symfony ~6.4 → ~7.4 and rewrote `web/index.php`
   to use Symfony's Runtime component instead of a manual bootstrap) changed
   how the base path gets computed. Diffed `Request::prepareBaseUrl()` between
   Symfony 6.4 and 7.4 — byte-for-byte identical. Traced Symfony Runtime's
   `HttpKernelRunner` — it still calls `Request::createFromGlobals()`
   internally, same as the old manual `index.php` did. No code-level evidence
   this is a new Drupal-11 regression; more likely a pre-existing latent bug
   that simply wasn't exercised before (e.g. cron previously ran on a schedule
   via a different path, not the UI button on `www.`).

## What was found (root cause, as best diagnosed so far)

Added a **temporary** one-line `error_log()` diagnostic directly into
`web/core/lib/Drupal/Core/Routing/LocalAwareRedirectResponseTrait.php` on
production (backed up first, reverted immediately after — checksums verified
identical to the repo's own copy afterward, so no residual change). Reproduced
via a real HTTP request (a fresh one-time login link — simpler than fighting
cron's CSRF token over curl) and captured this from
`~/public_html/d9/web/error_log`:

```
DIAG isLocal url=https://www.austinprogressivecalendar.com/user/1/edit?pass-reset-token=...
    base=https://www.austinprogressivecalendar.com/d9/web
    host=www.austinprogressivecalendar.com scheme=https
```

The generated redirect target (`url=`) is clean — no `/d9/web`. But
`RequestContext::getCompleteBaseUrl()` (`base=`) incorrectly reports
`.../d9/web` as the site's own base URL. Since the clean target doesn't start
with the (wrongly) reported base path, Drupal's redirect-safety check
(`LocalAwareRedirectResponseTrait::isLocal()` →
`UrlHelper::externalIsLocal()`) flags it as "external" and blocks it.

**Suspected cause of the `/d9/web` corruption:** cPanel shows
`austinprogressivecalendar.com` (the **Main Domain**, and `www.` is just its
automatic alias — it is *not* a separate cPanel domain entry) with Document
Root `/public_html`, reaching the actual site via an internal
rewrite/pass-through: `Redirects To: http://austinprogressivecalendar.com/d9/web/index.php?q=$0`.
This isn't a client-visible redirect (no 301/302 — confirmed via `curl`), but
it's suspected of corrupting `SCRIPT_NAME`/`SCRIPT_FILENAME`, which is exactly
what Symfony's `Request::prepareBaseUrl()` reads to compute the base path.
Compare: `d9.austinprogressivecalendar.com`'s cPanel entry has Document Root
set **directly** to `/public_html/d9/web`, no rewrite — and that hostname
never exhibits this bug.

cPanel's "Manage" screen for the Main Domain shows "No configuration options
currently exist," so this can't be fixed self-service — **a GreenGeeks
support ticket was filed** (2026-09-18) asking them to set the Main Domain's
real Document Root to `/home/austinpr/public_html/d9/web` directly, removing
the rewrite, to match how `d9.` is already configured. Ticket has been
submitted; GreenGeeks has not yet responded/fixed it as of this writing.

## The fix that was applied (and did NOT resolve the problem)

In `web/sites/default/settings.php`, inside the existing production-only
`elseif (str_contains($app_root, '/d9/'))` branch (alongside the
`environment_indicator` overrides already there):

```php
$base_url = 'https://www.austinprogressivecalendar.com';
```

The theory: hardcoding `$base_url` should make Drupal skip its own (broken,
for this vhost) auto-detection entirely and always use this literal value —
which should make `RequestContext::getCompleteBaseUrl()` report the correct
value regardless of what `SCRIPT_NAME` says. Verified locally that this is
correctly scoped (inert on DDEV — `Settings::get('reverse_proxy')` returns
`NULL` there, confirming the `/d9/` branch only matches on production's real
docroot path). The user has since deployed this to production and **reports
cron still fails with the same error**.

## Where this leaves things — starting point for the next session

The `$base_url` fix not working contradicts the working theory in one of a
few ways, and this needs to be re-diagnosed rather than assumed:

1. **Verify the deploy actually took effect.** Check
   `drush @apc.prod php:eval 'var_export($GLOBALS["base_url"] ?? "UNSET");'`
   and/or `drush @apc.prod status` to confirm the live file on GreenGeeks
   really has this line and that the `/d9/` branch condition is actually
   matching (confirm `$app_root` really does contain `/d9/` at runtime — don't
   assume). Also consider PHP opcache: GreenGeeks may have
   `opcache.validate_timestamps=0` or similar, which could mean the deployed
   file change isn't actually being picked up without a cache-clear/PHP
   restart of some kind. Rule this out explicitly.
2. **If the deploy is confirmed live**, redo the same temporary diagnostic
   (`error_log()` in `LocalAwareRedirectResponseTrait::isLocal()`, or better,
   log `$GLOBALS['base_url']` / `Settings::get(...)` alongside
   `getCompleteBaseUrl()`) to see whether `RequestContext` is actually reading
   the hardcoded value at all. It's possible `$base_url` (the legacy global)
   isn't actually consulted by whatever populates `router.request_context` in
   this Drupal version/context — that assumption was never directly verified,
   only inferred from Drupal's general historical behavior. **This is the
   most important thing to check first — the original assumption may simply
   be wrong.**
3. Remember to **back up any core file before editing it directly on
   production, and revert immediately after** (verified via `md5`/`diff`
   against the local repo's copy) — this was done cleanly twice this session;
   keep doing it that way.
4. Check on the GreenGeeks support ticket status — if they've already fixed
   the Main Domain Document Root, that alone might resolve everything and
   make the `$base_url` question moot.
5. SSH access to the GreenGeeks host works directly
   (`ssh austinpr@chi204.greengeeks.net`) — same credentials the
   `drush/sites/apc.site.yml` `@apc.prod` alias uses. Note: automated remote
   *writes* over SSH get an extra permission check from Claude Code's auto
   mode — expect to explicitly confirm that if it comes up again.
6. Full command reference used this session for reproducing without fighting
   CSRF tokens: generate a fresh one-time login link
   (`drush @apc.prod --uri=https://www.austinprogressivecalendar.com uli`) and
   `curl` it directly — it hits the exact same redirect-safety check as cron,
   with none of cron's CSRF-token fragility.

## Not part of this bug, but touched this session (context only)

- `web/sites/default/files.old/` was accidentally committed to `develop` and
  then purged from git history via `git filter-repo` (a heavier tool than
  needed for a tip-commit fix — noted as a standing lesson for next time, see
  memory). This required also rewriting/force-pushing `main` and deleting two
  stale feature branches (`feature/config-split`, `feature/eventcalendar`) to
  restore shared ancestry. All confirmed resolved and unrelated to the
  redirect bug.
- `web/themes/custom/apc_brown/config/schema/apc_brown.schema.yml` was added
  to fix an unrelated `No schema for apc_brown.settings` warning. Still
  uncommitted as of this writing (user chose to leave it uncommitted for now).
- `noli42/chosen` was added to `composer.json` as a proper Composer-managed
  package so `web/libraries/chosen` is fetched automatically instead of
  needing a manual `drush chosen:plugin` run. Committed (`ff66d56`).

---

## Session 2 (2026-09-17) — re-diagnosis and a working fix

### Why the `$base_url` fix could never work (verified in core, not inferred)

The deploy **did** take effect: the live `settings.php` on GreenGeeks has the
line (line 976), and PHP opcache there has `validate_timestamps=On`,
`revalidate_freq=2`, so file changes are picked up. The line is simply dead
code in Drupal 8+, for two independent reasons:

1. `Settings::initialize()` (`core/lib/Drupal/Core/Site/Settings.php`) does
   `require settings.php` inside a static method that only declares
   `global $config;`. A `$base_url = ...` assignment there is a **local
   variable** of that method and never reaches `$GLOBALS`.
2. Even if it did, `DrupalKernel::preHandle()` calls
   `initializeRequestGlobals()` *after* settings are loaded, and that method
   **unconditionally** sets `global $base_url` from
   `dirname($request->server->get('SCRIPT_NAME'))`. There is no `if (isset(...))`
   guard the way Drupal 7 had.

### The actual mechanism, confirmed end to end

Drupal computes the site's base path twice, from two different sources, and
on the `www.` vhost they disagree:

| Consumer | Source | Value on `www.` |
|---|---|---|
| `RequestContext::getCompleteBaseUrl()` (redirect-safety check), `base_path()`, file/asset URLs, `drupalSettings.path.baseUrl` | `$GLOBALS['base_url']` ← `dirname(SCRIPT_NAME)` | `https://www…/d9/web` |
| URL generator (every link and redirect target) | Symfony `Request::prepareBaseUrl()` ← SCRIPT_NAME matched against REQUEST_URI | `""` (correct — the rewrite is invisible to the browser) |

`/home/austinpr/public_html/.htaccess` rewrites `www.` requests to
`d9/web/index.php?q=$0`, so `SCRIPT_NAME` is `/d9/web/index.php` while
`REQUEST_URI` stays clean. (An earlier draft of this section guessed the bug was
"as old as the launch" from that file's 2026-09-16 mtime; that was wrong — see
"Why this only appeared with Drupal 11" below.) Core knows about this
design flaw: https://www.drupal.org/node/2404601 ("Figure out how to best
deal with RequestContext::setCompleteBaseUrl", still Active) and
https://www.drupal.org/node/2529170 (remove `initializeRequestGlobals`).

**Reproduced locally**, no production edits needed: boot the kernel like
`index.php` but with a crafted `Request` whose `SCRIPT_NAME` is
`/d9/web/index.php`. Before the fix: one-time login link → **400** with the
exact production message; after: **302** to `/user/1/edit?pass-reset-token=…`.
Script (run inside the container as `ddev exec php /tmp/repro.php <path> bad|good`):

```php
<?php
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;
chdir('/var/www/html/web');
$kernel = new DrupalKernel('prod', require '/var/www/html/web/autoload.php');
[$path, $mode] = [$argv[1], $argv[2] ?? 'bad'];
$fake = $mode === 'bad';
$request = Request::create('https://apc3.ddev.site' . $path, 'GET', [], [], [], [
  'SCRIPT_NAME' => $fake ? '/d9/web/index.php' : '/index.php',
  'PHP_SELF' => $fake ? '/d9/web/index.php' : '/index.php',
  'SCRIPT_FILENAME' => '/var/www/html/web/index.php',
  'HTTP_HOST' => 'apc3.ddev.site', 'HTTPS' => 'on', 'SERVER_PORT' => 443,
]);
$response = $kernel->handle($request);
echo "status=", $response->getStatusCode(), "\nlocation=", $response->headers->get('Location'), "\n";
echo "GLOBALS base_url=", $GLOBALS['base_url'], "\nsymfony basePath='", $request->getBasePath(), "'\n";
echo substr(strip_tags($response->getContent()), 0, 160), "\n";
$kernel->terminate($request, $response);
```
Get a fresh path each run with `ddev drush uli --no-browser | sed -E 's#^https?://[^/]*##'`.

### Impact is wider than the cron button

- `RedirectResponseSubscriber::checkRedirectUrl()` wraps **every** plain
  `RedirectResponse` in a `LocalRedirectResponse` and runs this check, and
  `FormSubmitter::redirectForm()` always builds an **absolute** target. So on
  `www.` this hits any form redirect, password-reset / one-time-login links,
  and `ControllerBase::redirect()` — not just cron. (Admin work presumably
  goes through `d9.`, which is why only cron was noticed.)
- **Cross-host render-cache pollution.** Render-cached fragments don't vary
  by `url.site`, and `base_path()` returns `/d9/web/` under `www.`, so image
  `src`, some `href`s and the search form `action` get `/d9/web/…` baked in
  and are then served on **every** hostname. Verified live: the `www.` and
  `d9.` homepages both contain ~60 `/d9/web/` URLs; an image URL from the
  `d9.` homepage returns **404 on d9.** and 200 on www. `dev.` is clean
  (0 occurrences of `apcdev/web`). This means `drush cr` is a required part of
  deploying the fix.

### The fix (local, uncommitted, not deployed)

`web/modules/custom/apc_calendar/src/EventSubscriber/BaseUrlConsistencySubscriber.php`
+ a service entry in `apc_calendar.services.yml`. A `KernelEvents::REQUEST`
subscriber at priority 1000 (before `RouterListener` at 32) that, on the main
request, compares `$GLOBALS['base_url']` with
`$request->getSchemeAndHttpHost() . $request->getBasePath()` and, **only when
they differ**, rewrites `base_url` / `base_path` / `base_secure_url` /
`base_insecure_url` to the Symfony values and calls
`RequestContext::setCompleteBaseUrl()`. No-op on `d9.`, `dev.` and DDEV,
where the two already agree. Genuine subdirectory installs are unaffected
(Symfony's `getBasePath()` returns the subdirectory there).

Verified locally with the repro script: bad-SCRIPT_NAME login link 400 → 302
with a clean Location; cold-cache homepage under the bad SCRIPT_NAME went from
2 `/d9/web` occurrences (subscriber disabled) to 0; the normal-SCRIPT_NAME
control still redirects correctly.

The dead `$base_url` line in `settings.php` was replaced with a comment
explaining why it cannot work, so nobody re-tries it.

### Deploying it

Standard deploy (`git pull`, `composer install --no-dev`, `drush cr`).
`drush cr` is not optional here — it flushes the `/d9/web/` render-cache
pollution. Then verify from outside: `drush @apc.prod --uri=https://www.austinprogressivecalendar.com uli`
and `curl -sI` the link — expect `HTTP 302`/`303` with a clean `Location`, not
400. Then "Run cron" from `/admin/reports/status` on `www.`.

### GreenGeeks ticket

No reply as of 2026-09-17 evening (Gmail checked: only a login alert from
support@greengeeks.com on 2026-09-17 20:59 CT). The subscriber is a
hosting-independent workaround; once GreenGeeks points the Main Domain's
Document Root at `/home/austinpr/public_html/d9/web`, the subscriber becomes
a no-op on `www.` too and can be deleted, along with the note in `settings.php`.
An alternative self-service route (a stub `/public_html/index.php` that
`require`s `d9/web/index.php`, so SCRIPT_NAME becomes `/index.php`) was
considered but not tested; the subscriber is smaller and reversible.

---

## Why this only appeared with Drupal 11 (2026-09-17, answered with evidence)

**Short version:** production has an untracked, git-ignored
`web/sites/default/settings.local.php` (dated 2024-01-05) whose last lines are a
hack that patches `SCRIPT_NAME` on the global `$request` object during settings
load:

```php
if (isset($GLOBALS['request']) and '/d9/web/index.php' === $GLOBALS['request']->server->get('SCRIPT_NAME')) {
    $GLOBALS['request']->server->set('SCRIPT_NAME', '/index.php');
}
```

Drupal 10's `index.php` did `$request = Request::createFromGlobals();` at file
scope, so `$GLOBALS['request']` existed and this ran before
`DrupalKernel::preHandle()` computed the base-URL globals — everything was clean.
Drupal 11's `index.php` returns a closure and Symfony Runtime builds the request
as a private property of the runtime object; there is no global `$request`, so
the hack silently became a no-op and the mismatch surfaced. Because the file is
git-ignored it survived the deploy's `git reset --hard`, never showed in any
diff, and is invisible from the repo. Staging (`apcdev`) carries the same lines
but its document root is `apcdev/web` directly, so it never needed them.

**Evidence chain (so nobody has to redo this):**

- Timeline: Drupal 11 files landed on production 2026-09-18 00:50–00:51 UTC
  (mtimes of `composer.lock`, `web/index.php`, `web/autoload_runtime.php`; prod
  reflog `reset: moving to origin/main` at 00:50:32). Last successful form
  redirect on `www.`: 17 Sep 17:48 UTC (303). First failure: 18 Sep 00:53:59
  (run-cron 400). Three minutes after the deploy.
- Ruled out as the trigger: the root `.htaccess` edit (16 Sep 14:50 UTC — 30+
  successful 303s followed it), the PHP version (CloudLinux alt-php 8.3 under
  LSAPI, selector last changed 15 Sep, and the deploy-time fatal at 00:50:30
  shows the same alt-php83 include path), composer patches (none on core),
  and any non-core tracked file (the upgrade commit touches only core scaffold,
  config views, and theme files).
- Code: every class on the path (`DrupalKernel::initializeRequestGlobals`,
  `RequestContext`, `RedirectResponseSubscriber`, `LocalAwareRedirectResponseTrait`,
  `SecuredRedirectResponse`, `UrlHelper::externalIsLocal`, `UrlGenerator`,
  `FormSubmitter::redirectForm`, `ControllerBase::redirect`, `Url`) is
  functionally identical between 10.6.16 and 11.4.7; Symfony
  `Request::prepareBaseUrl()` and `RequestContext::fromRequest()` likewise. D11
  did swap `router_listener` to `Drupal\Core\Http\EventListener\RouterListener`,
  but it is a line-for-line port of Symfony's.
- Bisect: a `git worktree` of `79cfdd6` (Drupal 10.6.16) with its own DB copy,
  run through the same faked-`SCRIPT_NAME` reproduction, fails **identically**
  (400, `base_url=…/d9/web`). So the D10 *code* had the same flaw.
- Proof of mechanism: running each site's **real** `index.php` from CLI with
  production's `$_SERVER` shape and the hack appended to `settings.local.php`
  (subscriber disabled): D10 → `Redirecting to …/user/1/edit`, global `$request`
  exists, `base_url` clean. D11 → the exact production 400, global `$request`
  absent, `base_url=…/d9/web`.

**Follow-ups:** the hack lines in production's (and staging's)
`settings.local.php` are now dead and misleading; delete them when convenient
(they are harmless as-is). `BaseUrlConsistencySubscriber` (commit `73e7c96`,
deployed 03:04 UTC, confirmed working) is the replacement and, unlike the hack,
does not depend on how the front controller creates the request. The
`?q=$0` rewrite also has a second, unrelated symptom worth knowing about:
aggregated CSS/JS requests that have to be generated through `index.php`
intermittently return 400 on `www.` (visible in the access log on 31 Aug, 1, 2,
4, 7, 10 Sep and 17 Sep 18:22 UTC), because the merged `q=` parameter breaks the
asset controller's query validation — one more reason to get GreenGeeks to fix
the Document Root.

I commented out the errant lines in production's `settings.local.php` and confirmed that the subscriber still works.
The GreenGeeks ticket remains open for the Document Root fix, but the site is now stable with the subscriber in place.
