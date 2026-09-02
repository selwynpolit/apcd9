<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation page shown after an anonymous visitor submits a photo/sign.
 *
 * Same problem and same shape as EventSubmittedController: an anonymous
 * submitter cannot be shown their own unpublished node via the normal view
 * builder (core blocks "view own unpublished content" for uid 0 because it
 * would expose the whole queue), so this echoes back what they sent, scoped to
 * their session, with the node ID carried through the private tempstore rather
 * than an enumerable query parameter.
 *
 * A separate tempstore collection from the event page keeps the two flows from
 * clobbering each other's handoff.
 *
 * @see apc_calendar_photo_submit_confirmation()
 * @see \Drupal\apc_calendar\Controller\EventSubmittedController
 */
final class PhotoSubmittedController extends ControllerBase {

  public const COLLECTION = 'apc_calendar_photo';

  public const KEY = 'submitted_nid';

  /**
   * How long after submitting the visitor can still see their own photo.
   *
   * Same reasoning as EventSubmittedController::FRESHNESS_WINDOW -- the
   * tempstore's week-long default lifetime is far too long for content that
   * should only echo back to the person who just submitted it, especially on a
   * shared machine.
   */
  private const FRESHNESS_WINDOW = 1800;

  public function __construct(
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('tempstore.private'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(): array {
    $build = [
      // Session-scoped, unpublished content -- never cache. The route also
      // sets no_cache: TRUE; this is the belt to those braces.
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['session'],
      ],
    ];

    $node = $this->loadSubmittedNode();

    if (!$node instanceof NodeInterface) {
      $build['message'] = $this->statusMessage(
        $this->t('Thanks! Your photo has been submitted and is waiting for review. It will appear in the gallery once a moderator approves it, usually within a couple of days.')
      );
      $build['back'] = $this->backLink();
      return $build;
    }

    $build['message'] = $this->statusMessage(
      $node->isPublished()
        ? $this->t('Your photo has been approved and is now in the gallery.')
        : $this->t('Thanks! Here is what you submitted. It is waiting for review, and will appear in the gallery once a moderator approves it — usually within a couple of days.')
    );
    $build['summary'] = $this->buildSummary($node);
    $build['back'] = $this->backLink();

    return $build;
  }

  /**
   * Loads the node this session just submitted, if still valid to show.
   *
   * Returns NULL for anything doubtful -- no entry, a stale entry, a deleted
   * node, or the wrong bundle -- all of which fall back to the generic
   * thank-you rather than an error.
   */
  private function loadSubmittedNode(): ?NodeInterface {
    $submission = $this->tempStoreFactory->get(self::COLLECTION)->get(self::KEY);

    if (!is_array($submission) || empty($submission['nid']) || empty($submission['time'])) {
      return NULL;
    }
    if ($this->time->getRequestTime() - $submission['time'] > self::FRESHNESS_WINDOW) {
      return NULL;
    }

    $node = $this->entityTypeManager()->getStorage('node')->load($submission['nid']);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'community_photo') {
      return NULL;
    }

    return $node;
  }

  /**
   * Renders the submitted photo, caption and categories.
   *
   * Renders each field individually rather than through the node view builder
   * (which would add node/contextual links pointing at a URL the anonymous
   * submitter gets a 403 on). The image is rendered via the referenced media's
   * photo_gallery_card view mode -- the media itself is published, only the
   * wrapping node is pending, so this is safe and reuses the same cropped card
   * style the gallery uses.
   */
  private function buildSummary(NodeInterface $node): array {
    $summary = [
      '#type' => 'container',
      '#attributes' => ['class' => ['apc-photo-submitted']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $node->label(),
      ],
    ];

    if ($node->hasField('field_photo_image') && !$node->get('field_photo_image')->isEmpty()) {
      $summary['image'] = $node->get('field_photo_image')->view([
        'label' => 'hidden',
        'type' => 'entity_reference_entity_view',
        'settings' => ['view_mode' => 'photo_gallery_card', 'link' => FALSE],
      ]);
    }

    foreach (['field_caption', 'field_photo_tags'] as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $summary[$field_name] = $node->get($field_name)->view(['label' => 'hidden']);
      }
    }

    return $summary;
  }

  /**
   * Wraps a message in the site's status message theming.
   */
  private function statusMessage(string|\Stringable $text): array {
    return [
      '#theme' => 'status_messages',
      '#message_list' => ['status' => [$text]],
    ];
  }

  /**
   * Link back to the photo gallery.
   */
  private function backLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Back to the photo gallery'),
      '#url' => Url::fromUserInput('/photos'),
    ];
  }

}
