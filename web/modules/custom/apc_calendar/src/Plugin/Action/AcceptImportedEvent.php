<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Accepts a pending Feeds-imported event: publishes it and marks it accepted.
 *
 * Built for the `views.view.imported_events` moderation queue
 * (`/imported-events`, event-import-task.md). Extends
 * PublishEventAndLocation rather than duplicating its publish-node-and-
 * location logic, then additionally sets field_import_state to 'accepted' --
 * the marker that keeps this event out of the imported_events queue on
 * future visits without deleting it (deleting would let the same GUID
 * re-import forever, since the dedupe record lives in feeds_item on the
 * node itself).
 *
 * @see \Drupal\apc_calendar\Plugin\Action\PublishEventAndLocation
 * @see \Drupal\apc_calendar\Plugin\Action\RejectImportedEvent
 */
#[Action(
  id: 'apc_calendar_accept_imported_event',
  label: new TranslatableMarkup('Accept (publish and mark accepted)'),
  type: 'node',
)]
class AcceptImportedEvent extends PublishEventAndLocation {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'calendar_event') {
      return;
    }

    parent::execute($entity);

    if ($entity->hasField('field_import_state')) {
      $entity->set('field_import_state', 'accepted');
      $entity->save();
    }
  }

}
