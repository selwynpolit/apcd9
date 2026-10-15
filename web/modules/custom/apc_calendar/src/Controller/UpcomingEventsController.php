<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\apc_calendar\EventCardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Plain chronological "browse upcoming events" page at /calendar/upcoming.
 *
 * Replaces an earlier views.view.calendar:page_upcoming Views display --
 * moved to a controller because each event needed to render as a clickable
 * card wired to the same AJAX popup dialog /calendar uses, which a Views
 * Fields row (one column at a time) has no clean way to do. Event queries
 * and card rendering are shared with FrontPageController via
 * EventCardBuilder.
 *
 * Doubles as the phone version of /calendar: below 768 px the calendar page
 * sends visitors here (apc_brown/calendar-mobile-redirect) instead of
 * showing FullCalendar's year list, which opened on the first event of the
 * month-old window with past events first. That is why it has a category
 * filter (?tag=<term id>, the same "tags" vocabulary /calendar filters on).
 */
final class UpcomingEventsController extends ControllerBase {

  private const ITEMS_PER_PAGE = 12;

  public function __construct(
    protected readonly EventCardBuilder $eventCardBuilder,
    protected readonly TimeInterface $time,
    protected readonly PagerManagerInterface $pagerManager,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('apc_calendar.event_card_builder'),
      $container->get('datetime.time'),
      $container->get('pager.manager'),
      $container->get('request_stack'),
    );
  }

  public function build(): array {
    $now = $this->time->getRequestTime();
    [$from, $to, $today] = $this->parseDateRange($now);

    // With no start date this is the *upcoming* list, so events that already
    // began are hidden. An explicit start date is taken at its word, past or
    // not -- visitors can go looking for older events -- and runs from that
    // day's midnight. An end date alone that is already past (no start) has no
    // "upcoming" to apply to, so it reaches back to the first event on record.
    if ($from) {
      $lower = $from->getTimestamp();
    }
    elseif ($to && $to < $today) {
      $lower = 0;
    }
    else {
      $lower = $now;
    }
    $upper = $to ? $to->modify('+1 day')->getTimestamp() - 1 : NULL;

    $filter = $this->buildFilter($lower, $upper, $from, $to);
    $tag_tid = $filter['selected'] ?: NULL;

    $total = $this->eventCardBuilder->countEvents($lower, $upper, [], FALSE, $tag_tid);
    $pager = $this->pagerManager->createPager($total, self::ITEMS_PER_PAGE);
    $offset = $pager->getCurrentPage() * self::ITEMS_PER_PAGE;

    $rows = $this->eventCardBuilder->findEvents($lower, $upper, self::ITEMS_PER_PAGE, [], FALSE, $offset, $tag_tid);

    $cache_tags = ['node_list', 'taxonomy_term_list'];
    $cards = [];
    foreach ($rows as $row) {
      $cache_tags[] = 'node:' . $row->nid;
      $cards[] = $this->eventCardBuilder->renderOccurrence($row, TRUE);
    }

    return [
      '#theme' => 'apc_upcoming_events',
      '#cards' => $cards,
      '#filter' => $filter,
      '#heading' => $lower < $now ? $this->t('Events') : $this->t('Upcoming events'),
      '#summary' => $this->buildSummary($total, $offset, count($rows)),
      // signs_sidebar and recent_posts are built in
      // apc_brown_preprocess_apc_upcoming_events() -- same split as the
      // front page template, theme layer owns Views embeds and ImageStyle
      // usage (see apc_brown_preprocess_taxonomy_term()).
      //
      // quantity 5: the default is 9 page numbers, which plus the four
      // first/previous/next/last controls cannot fit one row on a phone.
      '#pager' => ['#type' => 'pager', '#quantity' => 5],
      '#attached' => [
        'library' => ['apc_brown/upcoming-events'],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:page', 'url.query_args:tag', 'url.query_args:from', 'url.query_args:to'],
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

  /**
   * Reads ?from= and ?to= (YYYY-MM-DD) into start-of-day timestamps.
   *
   * Days are in the site's/visitor's own timezone, since that is what the
   * date pickers show. Anything unparsable is ignored rather than rejected,
   * and a "to" earlier than "from" swaps them rather than showing an empty
   * page for what is plainly a reversed pair. Dates before today are allowed
   * on purpose (older events can be browsed).
   *
   * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: \DateTimeImmutable}
   *   From, to, and today's midnight.
   */
  private function parseDateRange(int $now): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    $query = $this->requestStack->getCurrentRequest()?->query;

    $parse = static function (mixed $raw) use ($tz): ?\DateTimeImmutable {
      if (!is_string($raw) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return NULL;
      }
      return new \DateTimeImmutable($raw . ' 00:00:00', $tz);
    };

    $from = $parse($query?->get('from'));
    $to = $parse($query?->get('to'));
    if ($from && $to && $to < $from) {
      [$from, $to] = [$to, $from];
    }
    return [$from, $to, $today];
  }

  /**
   * "Showing 13-24 of 36 events" -- orientation for a long, paged list.
   */
  private function buildSummary(int $total, int $offset, int $shown): ?string {
    if ($total === 0) {
      return NULL;
    }
    if ($total <= self::ITEMS_PER_PAGE) {
      return (string) $this->formatPlural($total, '1 event', '@count events');
    }
    return (string) $this->t('Showing @start–@end of @total events', [
      '@start' => $offset + 1,
      '@end' => $offset + $shown,
      '@total' => $total,
    ]);
  }

  /**
   * Builds the filter form's values: dates, category options, selection.
   *
   * Only tags with at least one upcoming occurrence *in the chosen range* are
   * offered, each with its card count (see
   * EventCardBuilder::countUpcomingByTag()), so choosing one can never land
   * on an empty page. A ?tag= value that isn't one of those -- a stale
   * bookmark, a typo, or a hand-edited URL -- is ignored and the page shows
   * everything, rather than erroring or showing "none".
   *
   * @return array{selected: int, options: array<int, array{tid: int, label: string, count: int}>, from: string, to: string, active: bool, description: string}
   */
  private function buildFilter(int $lower, ?int $upper, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array {
    $counts = $this->eventCardBuilder->countUpcomingByTag($lower, $upper);

    $options = [];
    if ($counts) {
      $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadMultiple(array_keys($counts));
      foreach ($terms as $tid => $term) {
        if ($term->bundle() !== 'tags' || !$term->isPublished()) {
          continue;
        }
        $options[] = [
          'tid' => (int) $tid,
          'label' => $term->label(),
          'count' => $counts[$tid],
        ];
      }
      usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
    }

    $requested = (int) $this->requestStack->getCurrentRequest()?->query->get('tag', 0);
    $selected = in_array($requested, array_column($options, 'tid'), TRUE) ? $requested : 0;

    // What is applied, in words, for the collapsed panel's heading -- so a
    // closed box still says what the list is filtered by.
    $parts = [];
    if ($from || $to) {
      $fmt = static fn (\DateTimeImmutable $d): string => $d->format($d->format('Y') === date('Y') ? 'M j' : 'M j, Y');
      $parts[] = match (TRUE) {
        $from && $to => $fmt($from) . ' – ' . $fmt($to),
        (bool) $from => (string) $this->t('from @date', ['@date' => $fmt($from)]),
        default => (string) $this->t('through @date', ['@date' => $fmt($to)]),
      };
    }
    if ($selected) {
      $parts[] = $options[array_search($selected, array_column($options, 'tid'), TRUE)]['label'];
    }

    return [
      'selected' => $selected,
      'options' => $options,
      'from' => $from?->format('Y-m-d') ?? '',
      'to' => $to?->format('Y-m-d') ?? '',
      'active' => (bool) $parts,
      'description' => implode(' · ', $parts),
    ];
  }

}
