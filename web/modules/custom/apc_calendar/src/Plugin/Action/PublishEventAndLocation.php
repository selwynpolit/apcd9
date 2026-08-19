<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Publishes a pending calendar event and, if needed, its location term.
 *
 * Built for the `views.view.pending_events` bulk-operations queue
 * (`/admin/content/pending-events`). Core's own "Publish content" action
 * (`entity:publish_action:node`) is not enough for that queue on its own, for
 * two reasons:
 *
 * - It only publishes the node. A location term that an anonymous submitter
 *   typed for this event may still be sitting unpublished in the pending
 *   queue (apc_calendar_taxonomy_term_presave()), and nothing else would ever
 *   publish it — the event goes live pointing at a venue whose page 403s.
 * - Its access() requires 'administer nodes', because it checks field-level
 *   edit access on the 'status' base field
 *   (NodeAccessControlHandler::checkFieldAccess() reserves that field for
 *   'administer nodes' regardless of 'edit any ... content'). The
 *   event_manager role is deliberately not given 'administer nodes' — see
 *   the permission matrix note in apc_calendar.module — so this action
 *   checks ordinary entity update access instead. That is what makes the
 *   pending_events bulk form usable for a manager at all.
 *
 * @see \Drupal\Core\Action\Plugin\Action\PublishAction
 */
#[Action(
  id: 'apc_calendar_publish_event_and_location',
  label: new TranslatableMarkup('Publish event (and its location, if still pending)'),
  type: 'node',
)]
class PublishEventAndLocation extends EntityActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'calendar_event') {
      return;
    }

    $entity->setPublished()->save();

    if (!$entity->hasField('field_location') || $entity->get('field_location')->isEmpty()) {
      return;
    }

    $term = $entity->get('field_location')->entity;
    if ($term === NULL || $term->isPublished()) {
      return;
    }

    $term->setPublished()->save();

    // A manager approving the event has no other reason to visit the
    // location's own review queue, so without this an admin has no way to
    // know a venue just went live except by noticing it later on the site.
    \Drupal::logger('apc_calendar')->notice('Publishing event %event also published its previously pending location %location (term @tid).', [
      '%event' => $entity->label(),
      '%location' => $term->label(),
      '@tid' => $term->id(),
    ]);
    \Drupal::messenger()->addStatus(new TranslatableMarkup('Publishing "@event" also published its location "@location", which was still pending review.', [
      '@event' => $entity->label(),
      '@location' => $term->label(),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    // Deliberately entity update access only — not the field-level 'status'
    // edit check core's own publish action performs (see class docblock).
    $result = $object->access('update', $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
