<?php

declare(strict_types=1);

namespace Drupal\apc_calendar;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Finds upcoming calendar_event occurrences and renders them as clickable
 * cards -- shared by FrontPageController (the homepage's featured/upcoming
 * cards) and UpcomingEventsController (the /calendar/upcoming grid).
 *
 * Recurring events store one row per occurrence in node__field_event_date
 * (see EventPopupController), so "soonest upcoming" cannot be answered with
 * a plain EntityQuery condition/sort on that field -- both operate on the
 * field as a whole and default to the entity's first delta, not the delta
 * that actually matches. findEvents() joins the field table directly and
 * filters/sorts on its value column instead, mirroring the SQL Views itself
 * used to generate for views.view.calendar's now-removed page_upcoming
 * display.
 */
final class EventCardBuilder {

  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {}

  /**
   * Finds up to $limit soonest occurrences at or after $now.
   *
   * @return object[]
   *   Rows with ->nid and ->occurrence (the matched field_event_date_value).
   */
  public function findEvents(int $now, ?int $upper_bound, int $limit, array $exclude_nids = [], bool $require_promoted = FALSE, int $offset = 0): array {
    $query = $this->buildQuery($now, $upper_bound, $exclude_nids, $require_promoted);
    $query->orderBy('d.field_event_date_value', 'ASC');
    $query->range($offset, $limit);
    return $query->execute()->fetchAll();
  }

  /**
   * Counts occurrences at or after $now, for paginating findEvents().
   */
  public function countEvents(int $now, ?int $upper_bound, array $exclude_nids = [], bool $require_promoted = FALSE): int {
    $query = $this->buildQuery($now, $upper_bound, $exclude_nids, $require_promoted);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  private function buildQuery(int $now, ?int $upper_bound, array $exclude_nids, bool $require_promoted) {
    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin('node__field_event_date', 'd', 'n.nid = d.entity_id AND d.deleted = 0');
    $query->addField('n', 'nid');
    $query->addField('d', 'field_event_date_value', 'occurrence');
    $query->condition('n.status', 1);
    $query->condition('n.type', 'calendar_event');
    $query->condition('d.field_event_date_value', $now, '>=');
    if ($upper_bound !== NULL) {
      $query->condition('d.field_event_date_value', $upper_bound, '<=');
    }
    if ($require_promoted) {
      $query->condition('n.promote', 1);
    }
    if ($exclude_nids) {
      $query->condition('n.nid', $exclude_nids, 'NOT IN');
    }
    return $query;
  }

  /**
   * Renders one matched occurrence, narrowing field_event_date to its delta.
   *
   * Same clone-and-reduce technique as EventPopupController::popup() -- the
   * only reliable way to get the Smart Date formatter to show the occurrence
   * that was actually matched, rather than the entity's first delta.
   *
   * The calendar_item view mode was built for the FullCalendar popup dialog,
   * so it includes the full body, an embedded location card, tags and the
   * event URL -- confirmed live to be far too much for a card grid. Rather
   * than a new view mode, the built view mode's components are filtered
   * down to just image + date (plus a body blurb when $compact is FALSE),
   * reusing calendar_item's own formatter settings for those fields instead
   * of duplicating them. Every card is wired to open the same AJAX popup
   * dialog /calendar uses (EventPopupController via apc_calendar.event_popup)
   * -- confirmed live to be the only way to give a card enough at-a-glance
   * detail (location, directions, "add to calendar") to be useful.
   *
   * $link_title controls whether the title itself is a second link to that
   * same popup, on top of the card-wide overlay link every card already
   * gets below -- FALSE for the homepage's "Coming up" strip, where that
   * second link was purely redundant chrome (the whole card was already
   * clickable) rather than extra functionality.
   */
  public function renderOccurrence(object $row, bool $compact, bool $link_title = TRUE): array {
    $node = $this->entityTypeManager->getStorage('node')->load($row->nid);
    if (!$node instanceof NodeInterface) {
      return [];
    }

    $values = $node->get('field_event_date')->getValue();
    $delta = NULL;
    foreach ($values as $key => $value) {
      if ((int) $value['value'] === (int) $row->occurrence) {
        $delta = $key;
        break;
      }
    }
    if ($delta === NULL) {
      return [];
    }

    $occurrence = clone $node;
    $occurrence->set('field_event_date', [$values[$delta]]);

    $view_modes = $this->entityDisplayRepository->getViewModeOptionsByBundle('node', 'calendar_event');
    $view_mode = isset($view_modes['calendar_item']) ? 'calendar_item' : 'default';

    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $build = $view_builder->view($occurrence, $view_mode);
    if (!empty($build['#cache']['keys'])) {
      $build['#cache']['keys'][] = 'apc_event_card';
      $build['#cache']['keys'][] = (string) $delta;
    }

    // ::view() defers building field components to a #pre_render callback
    // that only runs when the renderer processes the array -- so the field
    // keys below don't exist yet at this point without forcing it now (see
    // EventPopupController::popup(), which hits the same thing).
    $build = $view_builder->build($build);
    unset($build['#pre_render']);

    $keep = $compact
      ? ['field_event_image', 'field_event_date']
      : ['field_event_image', 'field_event_date', 'body'];
    foreach (Element::children($build) as $key) {
      if (!in_array($key, $keep, TRUE)) {
        unset($build[$key]);
      }
    }

    // Not every organizer uploads a photo -- confirmed live, several of
    // this site's own events have none. An empty field_event_image
    // component just vanishes rather than rendering a broken/empty <img>,
    // so a card with no photo has nothing where the image should be. Falls
    // back to the theme's illustrated default (mascot + skyline).
    if ($occurrence->get('field_event_image')->isEmpty()) {
      $build['field_event_image'] = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => [
          'src' => DefaultEventImage::url(),
          'alt' => '',
          'class' => ['apc-default-event-image'],
          'loading' => 'lazy',
        ],
      ];
    }

    $popup_route = 'apc_calendar.event_popup';
    $popup_route_params = ['node' => $node->id(), 'delta' => $delta];
    // Matches fullcalendar_view's own dialog_modal_options width exactly
    // (FullcalendarViewPreprocess::preprocessFullcalendarView(), hardcoded
    // to 800) -- without it, core's ajax.js falls back to jQuery UI
    // dialog's own default (a few hundred px), rendering noticeably
    // narrower than the identical popup opened from /calendar. Confirmed
    // live.
    $popup_dialog_options = json_encode(['width' => 800]);

    // Confirmed live: unlike the full view mode, calendar_item's 'title'
    // pseudo-field isn't an enabled component, so node.html.twig's own
    // {% if label and view_mode != 'full' %} heading never prints -- the
    // calendar's own popup gets away without one because
    // EventPopupController's "View full event" button already names the
    // event, but a card with no name at all is not usable.
    // Weighted between the image (0) and the date (1) -- not first -- so
    // the pushpin dot some callers draw (::before, positioned over the top
    // of the card) lands on the image instead of colliding with title text.
    $build['apc_title'] = $link_title ? [
      '#type' => 'link',
      '#title' => $occurrence->label(),
      // A separate Url::fromRoute() call per link, not one Url instance
      // shared between the two below -- Link::preRenderLink() calls
      // $url->setOption('attributes', ...) on the #url object itself, which
      // mutates it in place. Sharing one instance let one link's
      // aria-hidden/tabindex/class bleed into the other, confirmed live.
      '#url' => Url::fromRoute($popup_route, $popup_route_params),
      '#attributes' => [
        'class' => ['use-ajax', 'apc-front__card-title'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => $popup_dialog_options,
      ],
      '#weight' => 0.5,
    ] : [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => Html::escape($occurrence->label()),
      '#attributes' => ['class' => ['apc-front__card-title']],
      '#weight' => 0.5,
    ];

    // A transparent full-card overlay so the whole card is clickable, not
    // just the title text -- aria-hidden/tabindex=-1 so assistive tech only
    // ever encounters the one real link (the title above), not two links to
    // the same place.
    $build['apc_card_overlay'] = [
      '#type' => 'link',
      '#title' => '',
      '#url' => Url::fromRoute($popup_route, $popup_route_params),
      '#attributes' => [
        'class' => ['use-ajax', 'apc-front__card-overlay'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => $popup_dialog_options,
        'aria-hidden' => 'true',
        'tabindex' => '-1',
      ],
      '#weight' => -20,
    ];

    return $build;
  }

}
