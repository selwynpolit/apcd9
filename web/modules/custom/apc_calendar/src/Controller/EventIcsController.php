<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\apc_calendar\AddToCalendar;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves one occurrence of a calendar_event as a downloadable .ics file.
 *
 * The "Apple Calendar (.ics)" option in the AddToCalendar menu points here.
 * Node access is enforced by the route's _entity_access requirement, so this
 * cannot expose a pending/unpublished event that the popup route can't.
 */
final class EventIcsController extends ControllerBase {

  /**
   * Returns the .ics body for a single occurrence.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The calendar event.
   * @param int $delta
   *   Which value of field_event_date to export.
   */
  public function ics(NodeInterface $node, int $delta): Response {
    if ($node->bundle() !== 'calendar_event') {
      throw new NotFoundHttpException();
    }

    $body = AddToCalendar::icsBody($node, $delta);
    if ($body === NULL) {
      throw new NotFoundHttpException();
    }

    $response = new Response($body);
    $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="event-' . $node->id() . '.ics"');
    return $response;
  }

}
