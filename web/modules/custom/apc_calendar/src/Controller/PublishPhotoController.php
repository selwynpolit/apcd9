<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * One-click publish for a pending community_photo from the moderation queue.
 *
 * Triggered by the "Publish" entity operation (apc_calendar_entity_operation)
 * on /admin/content/photos, so a reviewer approves a photo with a single
 * click instead of the select-then-apply bulk publish action.
 *
 * Access is enforced by the route's _entity_access: 'node.update' and
 * _csrf_token requirements, not here. Redirects back to the moderation queue;
 * a ?destination= on the link (added by the operations field) takes
 * precedence via the redirect-response subscriber, so the reviewer lands back
 * on the exact page/filter they were on.
 */
final class PublishPhotoController extends ControllerBase {

  /**
   * Publishes the photo, then redirects back to the moderation queue.
   */
  public function publish(NodeInterface $node): RedirectResponse {
    if ($node->bundle() === 'community_photo' && !$node->isPublished()) {
      $node->setPublished()->save();
      $this->messenger()->addStatus($this->t('Published "@title".', ['@title' => $node->label()]));
    }
    return $this->redirect('view.community_photos_review.page_1');
  }

}
