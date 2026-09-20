<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\apc_calendar\EventCardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plain chronological "browse upcoming events" page at /calendar/upcoming.
 *
 * Replaces an earlier views.view.calendar:page_upcoming Views display --
 * moved to a controller because each event needed to render as a clickable
 * card wired to the same AJAX popup dialog /calendar uses, which a Views
 * Fields row (one column at a time) has no clean way to do. Event queries
 * and card rendering are shared with FrontPageController via
 * EventCardBuilder.
 */
final class UpcomingEventsController extends ControllerBase {

  private const ITEMS_PER_PAGE = 12;

  public function __construct(
    protected readonly EventCardBuilder $eventCardBuilder,
    protected readonly TimeInterface $time,
    protected readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('apc_calendar.event_card_builder'),
      $container->get('datetime.time'),
      $container->get('pager.manager'),
    );
  }

  public function build(): array {
    $now = $this->time->getRequestTime();

    $total = $this->eventCardBuilder->countEvents($now, NULL);
    $pager = $this->pagerManager->createPager($total, self::ITEMS_PER_PAGE);
    $offset = $pager->getCurrentPage() * self::ITEMS_PER_PAGE;

    $rows = $this->eventCardBuilder->findEvents($now, NULL, self::ITEMS_PER_PAGE, [], FALSE, $offset);

    $cache_tags = ['node_list'];
    $cards = [];
    foreach ($rows as $row) {
      $cache_tags[] = 'node:' . $row->nid;
      $cards[] = $this->eventCardBuilder->renderOccurrence($row, TRUE);
    }

    return [
      '#theme' => 'apc_upcoming_events',
      '#cards' => $cards,
      // signs_sidebar and recent_posts are built in
      // apc_brown_preprocess_apc_upcoming_events() -- same split as the
      // front page template, theme layer owns Views embeds and ImageStyle
      // usage (see apc_brown_preprocess_taxonomy_term()).
      '#pager' => ['#type' => 'pager'],
      '#attached' => [
        'library' => ['apc_brown/upcoming-events'],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:page'],
        'tags' => Cache::mergeTags($cache_tags, [
          'config:views.view.photo_gallery',
          'config:views.view.blog',
        ]),
        // Same reasoning as the front page: which events are "upcoming" (and
        // their page/offset) changes with the clock, not just on content
        // edits.
        'max-age' => 900,
      ],
    ];
  }

}
