<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\Field\FieldFormatter;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Entity label formatter that does not hide entities the viewer cannot view.
 *
 * Used only on the node `submission_confirmation` view mode, which shows a
 * submitter what they just entered. A location they added (or picked) while it
 * is still pending approval is unpublished, so the core label formatter's
 * per-entity access check drops it and the confirmation page showed no
 * location at all. Only the label is ever printed here, never a link or any
 * other field of the term, and the page is reachable only by the session that
 * made the submission.
 *
 * @see \Drupal\apc_calendar\Controller\EventSubmittedController
 */
#[FieldFormatter(
  id: 'apc_unrestricted_label',
  label: new TranslatableMarkup('Label (including unpublished)'),
  description: new TranslatableMarkup('Display the label of the referenced entities even when they are unpublished.'),
  field_types: [
    'entity_reference',
  ],
)]
class UnrestrictedLabelFormatter extends EntityReferenceLabelFormatter {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity) {
    return AccessResult::allowed();
  }

  /**
   * {@inheritdoc}
   *
   * Tags a still-unpublished location "(pending approval)", so the submitter
   * can tell their new venue is not live yet.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    foreach ($elements as &$element) {
      $entity = $element['#entity'] ?? NULL;
      if ($entity instanceof EntityPublishedInterface && !$entity->isPublished() && isset($element['#plain_text'])) {
        $element['#plain_text'] .= ' ' . $this->t('(pending approval)');
      }
    }
    return $elements;
  }

}
