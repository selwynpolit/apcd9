<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\EntityReferenceSelection;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Location selection that also offers locations still awaiting approval.
 *
 * Location terms added by anonymous (and other non-trusted) submitters are
 * saved unpublished (see apc_calendar_taxonomy_term_presave()), which keeps
 * them off the public location pages until an admin approves them. Core's
 * TermSelection also hides them from the reference autocomplete and rejects
 * them on validation for anyone without "administer taxonomy", so a submitter
 * could not even pick the venue they had just added.
 *
 * This handler drops that status filter: every submitter can attach an event
 * to any location, approved or pending. The approval gate that matters is
 * unchanged -- a pending location stays unpublished, and the event page shows
 * "Pending review" in its place for anyone who cannot see the term. The
 * accepted trade-off is that a pending location's name (and nothing else) is
 * visible in the autocomplete, so a junk venue name can be seen before an
 * admin removes it.
 *
 * Extends DefaultSelection rather than TermSelection deliberately:
 * TermSelection adds its `status = 1` condition unconditionally, and an entity
 * query condition cannot be removed once added.
 *
 * @see \Drupal\apc_calendar\Form\AddLocationForm
 */
#[EntityReferenceSelection(
  id: "apc_locations",
  label: new TranslatableMarkup("Locations (including ones awaiting approval)"),
  entity_types: ["taxonomy_term"],
  group: "apc_locations",
  weight: 0
)]
class PendingLocationSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   *
   * Marks unpublished locations in the autocomplete so it is obvious which ones
   * are still awaiting approval -- for an admin triaging the queue, and for a
   * submitter looking at the venue they just added.
   *
   * Display only. EntityAutocomplete::validateEntityAutocomplete() extracts the
   * integer ID from the input and stores that, so the field never persists this
   * string; and on re-edit the widget rebuilds the label from $entity->label().
   * Publishing a term therefore clears the marker everywhere at once, including
   * on events saved while it was pending. The term's own name is never touched.
   *
   * Reimplements the parent loop rather than calling parent::: the status of
   * each term is needed, and the parent returns only labels, so deferring to it
   * would mean a second loadMultiple() of the same entities.
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $target_type = $this->getConfiguration()['target_type'];

    $query = $this->buildEntityQuery($match, $match_operator);
    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $result = $query->execute();
    if (empty($result)) {
      return [];
    }

    $options = [];
    $entities = $this->entityTypeManager->getStorage($target_type)->loadMultiple($result);

    foreach ($entities as $entity_id => $entity) {
      $label = $this->entityRepository->getTranslationFromContext($entity)->label() ?? '';

      if ($entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
        $label .= ' - ' . $this->t('pending');
      }

      $options[$entity->bundle()][$entity_id] = Html::escape($label);
    }

    return $options;
  }

}
