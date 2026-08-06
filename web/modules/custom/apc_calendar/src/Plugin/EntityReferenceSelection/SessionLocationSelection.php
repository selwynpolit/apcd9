<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\EntityReferenceSelection;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Location selection that also accepts terms created in this session.
 *
 * The problem this solves:
 *
 * Anonymously-created location terms are saved unpublished (see
 * apc_calendar_taxonomy_term_presave()) so they stay out of everyone else's
 * autocomplete until an admin approves them. But core's TermSelection filters
 * `status = 1` for non-admins in *both* directions — listing and validation —
 * so a submitter who has just added a location through the modal would have
 * their own brand-new location rejected when they submit the event.
 *
 * This handler relaxes that by one term at a time: unpublished terms whose IDs
 * were recorded in the current session's private tempstore are also
 * referenceable. Scoping is per-session — core generates a random owner token
 * for anonymous users and prefixes tempstore keys with it — so one submitter's
 * pending location never becomes selectable by anyone else, and the approval
 * gate is untouched for every other term.
 *
 * Extends DefaultSelection rather than TermSelection deliberately: TermSelection
 * adds its `status = 1` condition unconditionally, and an entity query condition
 * cannot be removed once added. The status logic is therefore reimplemented
 * here as an OR group rather than inherited and patched.
 *
 * @see \Drupal\apc_calendar\Form\AddLocationForm
 */
#[EntityReferenceSelection(
  id: "apc_locations",
  label: new TranslatableMarkup("Locations (including ones added in this session)"),
  entity_types: ["taxonomy_term"],
  group: "apc_locations",
  weight: 0
)]
class SessionLocationSelection extends DefaultSelection {

  /**
   * Tempstore collection and key holding this session's new term IDs.
   */
  public const COLLECTION = 'apc_calendar';
  public const KEY = 'session_location_tids';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    AccountInterface $current_user,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityRepositoryInterface $entity_repository,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $module_handler,
      $current_user,
      $entity_field_manager,
      $entity_type_bundle_info,
      $entity_repository
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('current_user'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity.repository'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);

    // Admins see everything, exactly as core's TermSelection allows.
    if ($this->currentUser->hasPermission('administer taxonomy')) {
      return $query;
    }

    $published_or_mine = $query->orConditionGroup()->condition('status', 1);

    if ($tids = $this->sessionTermIds()) {
      $published_or_mine->condition('tid', $tids, 'IN');
    }

    return $query->condition($published_or_mine);
  }

  /**
   * {@inheritdoc}
   *
   * Marks unpublished locations in the autocomplete so it is obvious which ones
   * are still awaiting approval — for an admin triaging the queue, and for the
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

  /**
   * Records a term ID as referenceable for the remainder of this session.
   */
  public function rememberTerm(int $tid): void {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $tids = $store->get(self::KEY) ?: [];
    if (!in_array($tid, $tids, TRUE)) {
      $tids[] = $tid;
      $store->set(self::KEY, $tids);
    }
  }

  /**
   * Term IDs this session has created and may still reference.
   */
  private function sessionTermIds(): array {
    $tids = $this->tempStoreFactory->get(self::COLLECTION)->get(self::KEY);
    return is_array($tids) ? array_filter(array_map('intval', $tids)) : [];
  }

}
