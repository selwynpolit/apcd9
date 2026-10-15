<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\EventSubscriber;

use Drupal\apc_calendar\CalendarRewarmTracker;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rebuilds the cached /calendar page right after something expires it.
 *
 * The page is by far the slowest on the site to build cold (~6-7 s on
 * production against ~0.5 s for /home), and Drupal's page cache only fills on
 * the first anonymous visit after an expiry -- so that visitor pays. Any node
 * save clears it (node_list), including unpublished anonymous submissions and
 * Feeds imports. This makes the *saving* request, not a visitor, pay for the
 * rebuild: once the response has gone out (kernel.terminate), it fetches
 * /calendar as an anonymous visitor would, which repopulates the page cache.
 *
 * The request URL is built from the current request, so each environment
 * warms itself (production -> www host, staging -> dev host) with no
 * hardcoded domain. It must go out as a plain anonymous GET with no cookies
 * -- the page cache is keyed on the full URL and only stores anonymous
 * responses -- so the shared Guzzle client is used, never a session-bearing
 * one, and no query string is added.
 *
 * Deliberately best-effort. A failure is logged and ignored; the cron-driven
 * curl described in CLAUDE.md remains the safety net for anything this misses
 * (the hourly max-age expiry, `drush cr`, a warm skipped by the lock).
 *
 * Off by default under DDEV, where a self-request to the https project host
 * isn't worth the noise. To force it on or off in any environment:
 * @code
 * $settings['apc_calendar_rewarm'] = TRUE;
 * @endcode
 *
 * Not triggered from Drush: it doesn't dispatch kernel.terminate. Imports run
 * from the command line are covered by the cron warm instead.
 */
final class CalendarRewarmSubscriber implements EventSubscriberInterface {

  private const LOCK_NAME = 'apc_calendar_calendar_rewarm';

  public function __construct(
    private readonly CalendarRewarmTracker $tracker,
    private readonly ClientInterface $httpClient,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    // Low priority: run after other terminate work (IndexNow etc.).
    return [KernelEvents::TERMINATE => ['onTerminate', -100]];
  }

  public function onTerminate(TerminateEvent $event): void {
    if (!$this->tracker->isStale()) {
      return;
    }
    // One warm per request however many saves it contained (a bulk approve
    // of 40 events, a Feeds batch).
    $this->tracker->reset();

    if (!Settings::get('apc_calendar_rewarm', !getenv('IS_DDEV_PROJECT'))) {
      return;
    }

    // Skip if another request is already rebuilding the page. That warm may
    // have read the data just before this save, so the page can still be
    // slightly stale -- but the 1-hour max-age and the cron warm bound that,
    // and queueing a second 7 s rebuild behind it would only stack workers.
    if (!$this->lock->acquire(self::LOCK_NAME, 60)) {
      return;
    }

    try {
      $url = Url::fromRoute('view.calendar.page_1', [], ['absolute' => TRUE])->toString();
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 30,
        'connect_timeout' => 5,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'headers' => ['User-Agent' => 'APC calendar cache warmer'],
      ]);
      if ($response->getStatusCode() !== 200) {
        $this->logger->warning('Re-warming @url returned HTTP @status.', [
          '@url' => $url,
          '@status' => $response->getStatusCode(),
        ]);
      }
    }
    catch (\Throwable $e) {
      // Must never break the request that saved the content.
      $this->logger->warning('Re-warming the calendar page failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

}
