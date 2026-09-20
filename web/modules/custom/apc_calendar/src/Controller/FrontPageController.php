<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\apc_calendar\EventCardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the "Bulletin Board" homepage: hero, upcoming strip, featured
 * event, and the blended signs/memes wall.
 *
 * Event queries and card rendering are shared with UpcomingEventsController
 * via EventCardBuilder (@see that class for why "soonest upcoming" needs a
 * raw query rather than an EntityQuery condition/sort).
 */
final class FrontPageController extends ControllerBase {

  public function __construct(
    protected readonly EventCardBuilder $eventCardBuilder,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('apc_calendar.event_card_builder'),
      $container->get('datetime.time'),
    );
  }

  public function build(): array {
    $now = $this->time->getRequestTime();
    $cache_tags = ['node_list'];

    $featured_row = $this->findEvent($now, TRUE) ?? $this->findEvent($now, FALSE);
    $exclude = $featured_row ? [$featured_row->nid] : [];

    // 7-day window first; if nothing falls inside it, drop the upper bound
    // entirely rather than showing an empty "Coming up" strip.
    //
    // 6, not 4 -- .apc-front__upcoming-row is a 3-column grid at the design's
    // intended desktop width (the card tilt pattern in front-page.css is
    // itself keyed off nth-child(3n), confirming 3-per-row is the designed
    // layout, not incidental), so 6 fills two full rows instead of leaving a
    // sparse trailing row of one. Confirmed live to look clunky otherwise.
    $upcoming_rows = $this->eventCardBuilder->findEvents($now, $now + (7 * 86400), 6, $exclude);
    if (!$upcoming_rows) {
      $upcoming_rows = $this->eventCardBuilder->findEvents($now, NULL, 6, $exclude);
    }

    $featured = $featured_row ? $this->eventCardBuilder->renderOccurrence($featured_row, FALSE) : NULL;
    // link_title: FALSE -- these small cards already have a card-wide
    // overlay link (see EventCardBuilder::renderOccurrence()), so a second
    // link on just the title was redundant, not extra functionality.
    $upcoming = array_map(fn (object $row) => $this->eventCardBuilder->renderOccurrence($row, TRUE, FALSE), $upcoming_rows);
    foreach (array_merge($featured_row ? [$featured_row] : [], $upcoming_rows) as $row) {
      $cache_tags[] = 'node:' . $row->nid;
    }

    $signs_pool = $this->findSignsPool(8);
    foreach ($signs_pool as $node) {
      $cache_tags = Cache::mergeTags($cache_tags, $node->getCacheTags());
    }

    return [
      '#theme' => 'apc_front_page',
      '#featured_event' => $featured,
      '#upcoming_events' => $upcoming,
      // Raw entities, not rendered markup -- apc_brown_preprocess_apc_front_page()
      // builds both this pool's placard image data and the blended
      // photo_gallery:wall_recent_all wall, matching how Views embeds are
      // built for other custom templates (see apc_brown_preprocess_taxonomy_term()).
      '#signs_pool' => $signs_pool,
      '#attached' => [
        'library' => ['apc_brown/front-page'],
      ],
      '#cache' => [
        'tags' => Cache::mergeTags($cache_tags, [
          'config:views.view.photo_gallery',
        ]),
        // "Soonest upcoming" changes with the clock, not just with content
        // edits -- an event that was "coming up" can become "featured" (or
        // drop off the strip entirely) with no node save in between.
        'max-age' => 900,
      ],
    ];
  }

  /**
   * Finds the single soonest occurrence at or after $now.
   */
  private function findEvent(int $now, bool $require_promoted): ?object {
    $rows = $this->eventCardBuilder->findEvents($now, NULL, 1, [], $require_promoted);
    return $rows[0] ?? NULL;
  }

  /**
   * Loads the ~8 most recent published protest-sign photos for the hero's
   * shuffle placard.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function findSignsPool(int $limit): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'community_photo')
      ->condition('status', 1)
      ->condition('field_gallery_placement', 'protest_sign')
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();
    return array_values($storage->loadMultiple($ids));
  }

}
