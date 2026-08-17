<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders one occurrence of a calendar event for the /calendar popup.
 *
 * Fetched on click rather than prerendered into drupalSettings. FullCalendar
 * View serialises every event into the page on load, and smart_date_recur's
 * 12-month horizon means a weekly event is ~52 deltas — embedding popup markup
 * would multiply by that, undoing the payload work in the plan's §6a.
 *
 * The delta identifies which occurrence was clicked. FullCalendar View encodes
 * it into each entry's `eid` as `nid-D-delta` (see SmartDateProcessor::
 * updateEntry()), and the JS passes it through, so the popup can show the
 * Tuesday someone actually clicked rather than the whole recurring series.
 */
final class EventPopupController extends ControllerBase {

  public function __construct(
    protected readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_display.repository'));
  }

  /**
   * Builds the popup for a single occurrence.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The calendar event. Access is enforced by the route's _entity_access
   *   requirement, not here.
   * @param int $delta
   *   Which value of field_event_date was clicked.
   */
  public function popup(NodeInterface $node, int $delta): array {
    if ($node->bundle() !== 'calendar_event') {
      throw new NotFoundHttpException();
    }

    // Fall back to the 'default' view mode if calendar_item has not been
    // configured, so a missing display degrades to something readable rather
    // than an empty dialog.
    $view_modes = $this->entityDisplayRepository->getViewModeOptionsByBundle('node', 'calendar_event');
    $view_mode = isset($view_modes['calendar_item']) ? 'calendar_item' : 'default';

    // Narrow the date field to the clicked occurrence.
    //
    // Done by rendering a clone with field_event_date reduced to the single
    // delta, rather than post-processing the render array: the Smart Date
    // formatters decide their own output from the values they are given, so
    // handing them one value is the only reliable way to get one occurrence.
    // The clone is never saved.
    $occurrence = clone $node;
    $values = $node->get('field_event_date')->getValue();
    if (!isset($values[$delta])) {
      throw new NotFoundHttpException();
    }
    $occurrence->set('field_event_date', [$values[$delta]]);

    $build = $this->entityTypeManager()
      ->getViewBuilder('node')
      ->view($occurrence, $view_mode);

    // EntityViewBuilder keys the render cache on entity ID and view mode only,
    // so without this every occurrence of a recurring event would serve the
    // first one's markup.
    if (!empty($build['#cache']['keys'])) {
      $build['#cache']['keys'][] = 'apc_delta';
      $build['#cache']['keys'][] = (string) $delta;
    }

    $result = [
      '#attached' => ['library' => ['apc_brown/event-popup']],
    ];

    // Virtual/online badge, matching the one on the full event page --
    // field_virtual is hidden on the calendar_item view mode (it's a flag,
    // not something with its own formatter worth showing as a plain
    // Yes/No), so this is built directly rather than through the view mode.
    // Placed before 'event' so it's the first thing visible in the popup,
    // not something a viewer has to notice among the other fields.
    if ($occurrence->hasField('field_virtual') && (bool) $occurrence->get('field_virtual')->value) {
      $result['virtual_badge'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['apc-popup-badge']],
        'icon' => [
          '#markup' => Markup::create('<svg class="apc-popup-badge__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"></circle><ellipse cx="12" cy="12" rx="4" ry="9" fill="none" stroke="currentColor" stroke-width="2"></ellipse><line x1="3" y1="12" x2="21" y2="12" stroke="currentColor" stroke-width="2"></line></svg>'),
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['apc-popup-badge__label']],
          '#value' => $this->t('Online only'),
        ],
      ];
    }

    $result['event'] = $build;
    $result['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['apc-event-popup__actions']],
      'full' => [
        '#type' => 'link',
        '#title' => $this->t('View full event'),
        '#url' => $node->toUrl(),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    return $result;
  }

}
