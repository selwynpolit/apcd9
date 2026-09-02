<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Adds one configured Photo Categories term to the selected photos.
 *
 * Built for the community_photo moderation queue's bulk-operations field
 * (views.view.community_photos_review) -- the "mechanical" half of tagging a
 * batch of photos from the same event (e.g. 15 photos as "Rally" in one
 * action), leaving per-photo captions/alt text to the manual Claude-in-Chrome
 * pass described in photo-gallery-specs.md, "Bulk-tagging after upload".
 *
 * Same shape as PublishEventAndLocation (a small Action plugin next to the
 * one that already exists in this module, not a new pattern), but
 * configurable -- VBO shows buildConfigurationForm() once, before running,
 * so the admin picks the term a single time for the whole batch rather than
 * per row.
 */
#[Action(
  id: 'apc_calendar_apply_photo_category',
  label: new TranslatableMarkup('Apply category to selected'),
  type: 'node',
)]
class ApplyPhotoCategory extends ConfigurableActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'community_photo') {
      return;
    }
    if (empty($this->configuration['term_id'])) {
      return;
    }
    if (!$entity->hasField('field_photo_tags')) {
      return;
    }

    $term_id = (int) $this->configuration['term_id'];
    $existing = array_column($entity->get('field_photo_tags')->getValue(), 'target_id');
    if (in_array($term_id, $existing, TRUE)) {
      // Already tagged -- skip rather than add a duplicate reference.
      return;
    }

    $entity->get('field_photo_tags')->appendItem(['target_id' => $term_id]);
    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'term_id' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $default_term = !empty($this->configuration['term_id']) ? Term::load($this->configuration['term_id']) : NULL;

    $form['term_id'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Category to apply'),
      '#description' => $this->t('Added to every selected photo. Existing categories on a photo are kept; this only adds the one chosen here.'),
      '#target_type' => 'taxonomy_term',
      '#selection_settings' => [
        'target_bundles' => ['photo_categories'],
      ],
      '#default_value' => $default_term,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $term_id = $form_state->getValue('term_id');
    $term = $term_id ? Term::load($term_id) : NULL;
    if ($term === NULL || $term->bundle() !== 'photo_categories') {
      $form_state->setErrorByName('term_id', $this->t('Choose an existing Photo Categories term.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['term_id'] = $form_state->getValue('term_id');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $object->access('update', $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
