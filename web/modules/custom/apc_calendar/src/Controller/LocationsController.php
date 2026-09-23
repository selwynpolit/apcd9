<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\apc_calendar\EventCardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the /locations directory: every published location, each with its
 * next upcoming event if it has one.
 *
 * Same split as FrontPageController: this controller owns the query side
 * (which locations, which event is "next" at each) and hands raw term
 * entities + precomputed event data to the theme layer, which owns
 * ImageStyle/URL building for the actual card markup -- see
 * apc_brown_preprocess_apc_locations_page().
 */
final class LocationsController extends ControllerBase {

  public function __construct(
    protected readonly EventCardBuilder $eventCardBuilder,
    protected readonly TimeInterface $time,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('apc_calendar.event_card_builder'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
    );
  }

  public function build(): array {
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'locations')
      ->condition('status', 1)
      ->sort('name', 'ASC')
      ->execute();
    $terms = $storage->loadMultiple($tids);

    $now = $this->time->getRequestTime();
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $next_events = [];
    $cache_tags = ['node_list', 'taxonomy_term_list'];

    foreach ($terms as $term) {
      $cache_tags = Cache::mergeTags($cache_tags, $term->getCacheTags());

      $row = $this->eventCardBuilder->findNextEventAtLocation((int) $term->id(), $now);
      if (!$row) {
        continue;
      }
      $node = $node_storage->load($row->nid);
      if (!$node) {
        continue;
      }
      $cache_tags = Cache::mergeTags($cache_tags, $node->getCacheTags());

      $next_events[$term->id()] = [
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'date' => $this->dateFormatter->format((int) $row->occurrence, 'apc_brown_medium'),
      ];
    }

    return [
      '#theme' => 'apc_locations_page',
      '#terms' => array_values($terms),
      '#next_events' => $next_events,
      '#cache' => [
        'tags' => $cache_tags,
        // "Next event" changes with the clock, not just with content edits --
        // matches FrontPageController's own reasoning for the same max-age.
        'max-age' => 900,
      ],
    ];
  }

}
