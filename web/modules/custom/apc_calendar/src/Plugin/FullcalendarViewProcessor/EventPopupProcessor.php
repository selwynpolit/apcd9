<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\FullcalendarViewProcessor;

use Drupal\Core\Url;
use Drupal\fullcalendar_view\Plugin\FullcalendarViewProcessorBase;

/**
 * Points each calendar entry at the popup route for its own occurrence.
 *
 * Fullcalendar View computes one URL per *row* (from the rendered title field)
 * and reuses it for every date value on that row, so a recurring event's
 * occurrences all link to the same place. This rewrites each entry's url to
 * /calendar/event/{nid}/{delta} so the popup can show the date that was
 * actually clicked.
 *
 * With that url in place and the dialogModal option enabled, no custom
 * JavaScript is needed: Fullcalendar renders the event as an anchor whenever
 * event.url is set, and the module's own eventDidMount() adds Drupal's use-ajax
 * and data-dialog-type attributes to it. Core's dialog system does the rest.
 *
 * This relies on patches/fullcalendar_view-eventclick-respect-dialogmodal.patch.
 * Without it, eventClick() also fires and navigates away, so the dialog opens
 * and is immediately abandoned.
 *
 * @FullcalendarViewProcessor(
 *   id = "apc_calendar_event_popup",
 *   label = @Translation("APC calendar event popup links")
 * )
 */
class EventPopupProcessor extends FullcalendarViewProcessorBase {

  /**
   * {@inheritdoc}
   */
  public function process(array &$variables) {
    /** @var \Drupal\views\ViewExecutable $view */
    $view = $variables['view'] ?? NULL;
    if (!$view || $view->storage->id() !== 'calendar') {
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

    foreach ($calendar_options['events'] as &$entry) {
      $nid = $this->extractNid($entry);
      if ($nid === NULL) {
        continue;
      }

      $entry['url'] = Url::fromRoute('apc_calendar.event_popup', [
        'node' => $nid,
        'delta' => $this->extractDelta($entry),
      ])->toString();
    }
    unset($entry);

    // Let day cells grow to fit their events instead of scrolling.
    //
    // Fullcalendar View sets no height, so FullCalendar falls back to
    // aspectRatio 1.35 — a fixed, width-derived height. On a busy day that
    // pushes events behind the "+N more" popover, which is DOM created after
    // datesRender() has run, so Drupal's AJAX never binds to the links inside
    // it. Growing the grid keeps events in the cell where the bindings work.
    //
    // Set here rather than in the view because the module offers no height
    // option, and its eventLimit setting is passed through intval(), so the
    // limit can be raised but never switched off from config.
    $calendar_options['height'] = 'auto';

    $settings[$view_index]['calendar_options'] = json_encode($calendar_options);

    // Handles clicks inside the "+N more" popover, which Fullcalendar View
    // builds after datesRender() and therefore never binds Drupal's AJAX to.
    // Attached here rather than in the view so it always travels with the URL
    // rewriting above — the two are only useful together.
    $variables['#attached']['library'][] = 'apc_calendar/more_popover_dialog';
  }

  /**
   * Reads the node ID from an entry.
   *
   * `eid` is the entity ID as Fullcalendar View sets it, but SmartDateProcessor
   * may already have rewritten it to "<nid>-D-<delta>" or
   * "<nid>-R-<rule>-I-<index>". Processor plugins run in discovery order with no
   * weight control, so both forms have to be handled.
   */
  private function extractNid(array $entry): ?int {
    if (!isset($entry['eid'])) {
      return NULL;
    }

    return preg_match('/^(\d+)/', (string) $entry['eid'], $matches)
      ? (int) $matches[1]
      : NULL;
  }

  /**
   * Reads the field delta from an entry.
   *
   * Uses `id`, which Fullcalendar View builds as "<row index>-<delta>" while
   * looping the field's values — the delta is authoritative there and is set
   * before any processor runs. Falls back to the "-D-<delta>" form in case a
   * future version stops setting `id` that way.
   */
  private function extractDelta(array $entry): int {
    if (isset($entry['id']) && preg_match('/-(\d+)$/', (string) $entry['id'], $matches)) {
      return (int) $matches[1];
    }

    if (isset($entry['eid']) && preg_match('/-D-(\d+)$/', (string) $entry['eid'], $matches)) {
      return (int) $matches[1];
    }

    return 0;
  }

}
