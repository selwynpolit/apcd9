<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\FullcalendarViewProcessor;

use Drupal\file\FileInterface;
use Drupal\fullcalendar_view\Plugin\FullcalendarViewProcessorBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;

/**
 * Attaches a thumbnail, location and tags to each calendar entry.
 *
 * FullCalendar buckets any non-standard key on an event object into
 * event.extendedProps, so apc_calendar/event_hover's hover card reads these
 * straight off event.extendedProps client-side -- no extra request per
 * hover, since the data already travelled down with the page.
 *
 * Also hides the built-in time label in the month grid (dayGridMonth only --
 * the mobile list view keeps it, where there's room and no hover affordance
 * exists anyway), since the hover card is what surfaces the time instead.
 *
 * @FullcalendarViewProcessor(
 *   id = "apc_calendar_event_hover_data",
 *   label = @Translation("APC calendar event hover data")
 * )
 */
class EventHoverDataProcessor extends FullcalendarViewProcessorBase {

  use EventEntryNidTrait;

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

    $nids = [];
    foreach ($calendar_options['events'] as $entry) {
      $nid = $this->extractNid($entry);
      if ($nid !== NULL) {
        $nids[$nid] = $nid;
      }
    }

    if ($nids) {
      $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
      $hover_data = [];
      foreach ($nodes as $nid => $node) {
        $hover_data[$nid] = $this->buildHoverData($node);
      }

      foreach ($calendar_options['events'] as &$entry) {
        $nid = $this->extractNid($entry);
        if ($nid !== NULL && isset($hover_data[$nid])) {
          $entry += $hover_data[$nid];
        }
      }
      unset($entry);
    }

    // Per-view option override -- Fullcalendar View has no UI for this, and
    // it must not apply to the mobile list view, where the time is the point.
    $calendar_options['views']['dayGridMonth']['displayEventTime'] = FALSE;

    $settings[$view_index]['calendar_options'] = json_encode($calendar_options);

    $variables['#attached']['library'][] = 'apc_calendar/event_hover';
    $variables['#attached']['library'][] = 'apc_brown/event-hover';
  }

  /**
   * Builds the extendedProps payload for one node.
   */
  private function buildHoverData(NodeInterface $node): array {
    $data = [];

    $data['virtual'] = !$node->get('field_virtual')->isEmpty() && (bool) $node->get('field_virtual')->value;

    if (!$node->get('field_location')->isEmpty()) {
      $term = $node->get('field_location')->entity;
      // An anonymous submission can reference a location still pending
      // review -- omit it here rather than naming it, same reasoning as the
      // node template's "Pending review" fallback.
      if ($term !== NULL && $term->isPublished()) {
        $data['location'] = $term->label();
      }
    }

    if (!$node->get('field_tags')->isEmpty()) {
      $tags = [];
      foreach ($node->get('field_tags') as $item) {
        if ($item->entity !== NULL) {
          $tags[] = $item->entity->label();
        }
      }
      // Keep the card small -- the click-through popup shows all of them.
      $data['tags'] = array_slice($tags, 0, 3);
    }

    if (!$node->get('field_event_image')->isEmpty()) {
      $media = $node->get('field_event_image')->first()->entity;
      if ($media !== NULL && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
        $file = $media->get('field_media_image')->entity;
        if ($file instanceof FileInterface) {
          $style = ImageStyle::load('gallery_thumb');
          if ($style !== NULL) {
            $data['image'] = $style->buildUrl($file->getFileUri());
          }
        }
      }
    }

    return $data;
  }

}
