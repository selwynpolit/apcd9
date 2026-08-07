<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
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

    return [
      'event' => $build,
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['apc-event-popup__actions']],
        'full' => [
          '#type' => 'link',
          '#title' => $this->t('View full event'),
          '#url' => $node->toUrl(),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ],
    ];
  }

}
