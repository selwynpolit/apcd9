<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\Action;

use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Rejects a pending Feeds-imported event without deleting it.
 *
 * Built for the `views.view.imported_events` moderation queue
 * (event-import-task.md, "Rejection: flag, never delete"). The node stays in
 * place, unpublished, with field_import_state set to 'rejected' -- deleting
 * it instead would destroy the only record (feeds_item, on the node) that
 * its GUID was ever seen, so the next import of the same source calendar
 * would recreate it forever.
 *
 * @see \Drupal\apc_calendar\Plugin\Action\AcceptImportedEvent
 */
#[Action(
  id: 'apc_calendar_reject_imported_event',
  label: new TranslatableMarkup('Reject (keep unpublished, mark rejected)'),
  type: 'node',
)]
class RejectImportedEvent extends EntityActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'calendar_event' || !$entity->hasField('field_import_state')) {
      return;
    }

    $entity->set('field_import_state', 'rejected');
    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $object->access('update', $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
