<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\FullcalendarViewProcessor;

use Drupal\fullcalendar_view\Plugin\FullcalendarViewProcessorBase;

/**
 * Marks unpublished events on the manage calendar (calendar:page_manage).
 *
 * The manage display drops the "published" filter, so event_manager /
 * administrator see pending events alongside live ones (per-row node access
 * still hides them from everyone else -- see apc_calendar_node_access()). This
 * makes the pending ones obvious two ways: a "** " title prefix (data-level,
 * so it shows even before any CSS) and an .apc-event--pending class FullCalendar
 * puts on the event element for the muted/dashed styling in calendar-manage.css.
 *
 * Scoped to the page_manage display only -- the public calendar never carries
 * unpublished events, so it needs neither the marking nor the extra CSS.
 *
 * @FullcalendarViewProcessor(
 *   id = "apc_calendar_event_pending",
 *   label = @Translation("APC calendar pending-event marker")
 * )
 */
class EventPendingProcessor extends FullcalendarViewProcessorBase {

  use EventEntryNidTrait;

  /**
   * {@inheritdoc}
   */
  public function process(array &$variables) {
    /** @var \Drupal\views\ViewExecutable $view */
    $view = $variables['view'] ?? NULL;
    if (!$view || $view->storage->id() !== 'calendar' || ($view->current_display ?? '') !== 'page_manage') {
      return;
    }

    $settings = &$variables['#attached']['drupalSettings']['fullCalendarView'];
    if (empty($settings)) {
      return;
    }

    $view_index = key($settings);
    if (empty($settings[$view_index]['calendar_options'])) {
      return;
    }

    $calendar_options = json_decode($settings[$view_index]['calendar_options'], TRUE);
    if (empty($calendar_options['events'])) {
      return;
    }

    // Bulk-load the referenced nodes once, then flag the unpublished ones.
    $nids = [];
    foreach ($calendar_options['events'] as $entry) {
      $nid = $this->extractNid($entry);
      if ($nid !== NULL) {
        $nids[$nid] = $nid;
      }
    }
    if (!$nids) {
      return;
    }
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);

    $marked = FALSE;
    foreach ($calendar_options['events'] as &$entry) {
      $nid = $this->extractNid($entry);
      if ($nid === NULL || !isset($nodes[$nid]) || $nodes[$nid]->isPublished()) {
        continue;
      }
      $entry['title'] = '** ' . ($entry['title'] ?? '');
      $classes = $entry['classNames'] ?? [];
      if (is_string($classes)) {
        $classes = array_filter([$classes]);
      }
      $classes[] = 'apc-event--pending';
      $entry['classNames'] = $classes;
      $marked = TRUE;
    }
    unset($entry);

    if (!$marked) {
      return;
    }

    $settings[$view_index]['calendar_options'] = json_encode($calendar_options);
    $variables['#attached']['library'][] = 'apc_calendar/calendar_manage';
  }

}
