<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\apc_calendar\AddToCalendar;
use Drupal\apc_calendar\DefaultEventImage;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_display.repository'),
      $container->get('request_stack'),
    );
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

    $view_builder = $this->entityTypeManager()->getViewBuilder('node');
    $build = $view_builder->view($occurrence, $view_mode);

    // EntityViewBuilder keys the render cache on entity ID and view mode only,
    // so without this every occurrence of a recurring event would serve the
    // first one's markup.
    if (!empty($build['#cache']['keys'])) {
      $build['#cache']['keys'][] = 'apc_delta';
      $build['#cache']['keys'][] = (string) $delta;
    }

    // ::view() normally defers building the actual field components
    // (field_event_date, body, etc.) to a #pre_render callback that only runs
    // when Drupal's renderer processes the array -- so $build['field_event_date']
    // does not exist yet at this point. Force it to build now instead, so the
    // date field can be pulled out and restructured below (see 'apc_datebar').
    // The #pre_render entry ::view() added is removed afterward so the
    // renderer does not run this same build step a second time later.
    $build = $view_builder->build($build);
    unset($build['#pre_render']);

    // Not every organizer uploads a photo -- an empty field_event_image
    // component just vanishes rather than rendering anything, so the popup
    // otherwise has a blank gap where the photo would be. Falls back to the
    // theme's illustrated default (mascot + skyline), same as the homepage
    // cards and the full event page's gallery.
    if ($occurrence->get('field_event_image')->isEmpty()) {
      $build['field_event_image'] = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => [
          'src' => DefaultEventImage::url(),
          'alt' => '',
          'class' => ['apc-default-event-image'],
        ],
        '#weight' => -10,
      ];
    }

    // #type container (not a bare array) so the render array has a wrapper
    // element -- 'actions' below floats to its top-right corner, beside the
    // title, rather than sitting at the bottom of the dialog.
    $result = [
      '#type' => 'container',
      '#attributes' => ['class' => ['apc-event-popup']],
      '#attached' => ['library' => ['apc_brown/event-popup']],
    ];

    // Placed first in the render array -- not just floated to the corner via
    // CSS, but first in DOM/tab order too, matching where a keyboard or
    // screen-reader user actually encounters it.
    // 'Details', not 'View full event': shorter label for a smaller,
    // lighter-weight corner button -- see event-popup.css for why it's a
    // corner button at all, and why it used to overlap the title.
    $result['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['apc-event-popup__actions']],
      'full' => [
        '#type' => 'link',
        '#title' => $this->t('Details'),
        '#url' => $node->toUrl(),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
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

    // "Add to your calendar" for the clicked occurrence, placed beside the
    // date/time rather than below the fold at the bottom of the scrollable
    // dialog. field_event_date is pulled out of the entity view builder's
    // render array and re-inserted (same weight: 1, right after the weight-0
    // gallery) as a flex row alongside the control -- see
    // .apc-event-popup__datebar in event-popup.css. Keyed to $delta so a
    // recurring event adds the occurrence that was actually clicked.
    $add_to_calendar = AddToCalendar::build($node, $delta);
    if ($add_to_calendar) {
      $addtocal_component = [
        '#type' => 'container',
        '#attributes' => ['class' => ['apc-event-popup__addtocal']],
        'control' => $add_to_calendar,
      ];
      if (!empty($build['field_event_date'])) {
        $date_field = $build['field_event_date'];
        unset($build['field_event_date']);
        // The field carries its own #weight (1, from the calendar_item view
        // mode's component ordering) from when EntityViewBuilder assigned it
        // -- left in place, that would sort it AFTER addtocal (default weight
        // 0) as a sibling here, landing the date on the right and the button
        // on the left. Force explicit weights so 'date' always renders first
        // (left) and 'addtocal' second (right), regardless of what the field
        // carried in from the view mode.
        $date_field['#weight'] = 0;
        $addtocal_component['#weight'] = 1;
        $build['apc_datebar'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['apc-event-popup__datebar']],
          '#weight' => 1,
          'date' => $date_field,
          'addtocal' => $addtocal_component,
        ];
      }
      else {
        // No date field on this view mode (unexpected, but degrade gracefully
        // rather than silently dropping the control): append it on its own.
        $build['apc_add_to_calendar'] = $addtocal_component + ['#weight' => 1.5];
      }
    }

    $result['event'] = $build;

    // Manager actions: publish an unpublished event + its pending location in
    // one click, and jump to the edit form. Gated on node.update access, the
    // exact gate the full event page uses (apc_brown_preprocess_node()) and
    // the same access apc_calendar_node_access() ties unpublished-event view
    // to -- so these only appear for event_manager/administrator, and only
    // where the underlying routes would not 403. This is what makes the
    // /calendar/manage popup a one-click approval surface.
    if ($node->access('update')) {
      // When the popup was opened from the /calendar/manage view (tagged by
      // EventPopupProcessor with ?from=manage on the popup link itself),
      // send the publish/unpublish action links back there instead of the
      // node's own page -- core's RedirectResponseSubscriber honours a
      // 'destination' query parameter on any link whose target issues a
      // redirect, overriding whatever URL the controller redirects to.
      $return_options = $this->requestStack->getCurrentRequest()->query->get('from') === 'manage'
        ? ['query' => ['destination' => '/calendar/manage']]
        : [];

      $manage = [
        '#type' => 'container',
        '#attributes' => ['class' => ['apc-event-popup__manage']],
      ];
      if (!$node->isPublished()) {
        $manage['publish'] = [
          '#type' => 'link',
          '#title' => $this->t('Publish event & location'),
          // The route carries a _csrf_token requirement; RouteProcessorCsrf
          // adds the token to this URL automatically on generation.
          '#url' => Url::fromRoute('apc_calendar.publish_event_and_location', ['node' => $node->id()], $return_options),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ];
      }
      else {
        $manage['unpublish'] = [
          '#type' => 'link',
          '#title' => $this->t('Unpublish event'),
          '#url' => Url::fromRoute('apc_calendar.unpublish_event', ['node' => $node->id()], $return_options),
          '#attributes' => ['class' => ['button']],
        ];
        $manage['unpublish_all'] = [
          '#type' => 'link',
          '#title' => $this->t('Unpublish event & location'),
          '#url' => Url::fromRoute('apc_calendar.unpublish_event_and_location', ['node' => $node->id()], $return_options),
          '#attributes' => ['class' => ['button']],
        ];
      }
      $manage['edit'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => $node->toUrl('edit-form'),
        '#attributes' => ['class' => ['button']],
      ];
      $result['manage_actions'] = $manage;

      // The buttons vary by viewer, and the publish URL embeds a per-session
      // CSRF token, so this render must not be shared across users/sessions.
      // The 'from' query arg also changes the baked-in destination above, so
      // it must vary the cache too -- otherwise a manager opening this same
      // occurrence's popup once from /calendar and once from /calendar/manage
      // could be served the other page's cached redirect target.
      $result['#cache']['contexts'][] = 'user.permissions';
      $result['#cache']['contexts'][] = 'session';
      $result['#cache']['contexts'][] = 'url.query_args:from';
    }

    // Plain-language close hint for non-technical visitors -- the dialog's own
    // close control is just an unlabeled "✕" icon in the corner. Rendered last
    // so it sits at the foot of the popup.
    $result['close_hint'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('To close this window, click the ✕ in the top-right corner or press the Esc key.'),
      '#attributes' => ['class' => ['apc-event-popup__close-hint']],
    ];

    return $result;
  }

}
