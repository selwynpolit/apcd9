<?php

declare(strict_types=1);

namespace Drupal\apc_calendar;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * Notices when something that the /calendar page depends on has changed.
 *
 * Registered as a cache_tags_invalidator purely to be told about every tag
 * invalidation in the request; it clears nothing itself. Kept free of
 * constructor dependencies on purpose -- cache tag invalidators are
 * instantiated early (they sit underneath the cache layer), so pulling the
 * HTTP client or lock service in here risks a circular dependency. The
 * request that does the warming is CalendarRewarmSubscriber.
 *
 * Only the tags that actually expire the page are watched: node_list (every
 * node save, delete or publish, including unpublished anonymous submissions
 * and Feeds imports), taxonomy_term_list (a location or tag renamed or
 * added, both of which show on event cards) and the view's own config.
 * Single-entity tags (node:123, taxonomy_term:7) are not listed because a
 * save always raises the matching *_list tag as well.
 */
final class CalendarRewarmTracker implements CacheTagsInvalidatorInterface {

  private const WATCHED_TAGS = [
    'node_list',
    'taxonomy_term_list',
    'config:views.view.calendar',
  ];

  private bool $stale = FALSE;

  public function invalidateTags(array $tags): void {
    if (!$this->stale && array_intersect($tags, self::WATCHED_TAGS)) {
      $this->stale = TRUE;
    }
  }

  /**
   * Whether the calendar's cached copy was expired during this request.
   */
  public function isStale(): bool {
    return $this->stale;
  }

  public function reset(): void {
    $this->stale = FALSE;
  }

}
