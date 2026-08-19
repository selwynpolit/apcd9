<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Core\Action\ActionManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Runs the apc_calendar_publish_event_and_location action from a single link.
 *
 * The action plugin itself (Plugin/Action/PublishEventAndLocation.php) was
 * built for the pending_events VBO bulk-operations queue and already handles
 * publishing the node plus its still-pending location term. This controller
 * exists only to trigger that same action from a one-click link on the
 * event's own detail page, so a reviewer does not have to leave the rendered
 * page to approve it.
 *
 * Access is enforced entirely by the route's _entity_access: 'node.update'
 * and _csrf_token requirements, not here -- the same access check the
 * action's own access() method performs.
 */
final class PublishEventAndLocationController extends ControllerBase {

  public function __construct(
    protected readonly ActionManager $actionManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('plugin.manager.action'));
  }

  /**
   * Publishes the event (and its location, if still pending), then redirects back.
   */
  public function publish(NodeInterface $node): RedirectResponse {
    if ($node->bundle() === 'calendar_event') {
      $this->actionManager->createInstance('apc_calendar_publish_event_and_location')->execute($node);
      $this->messenger()->addStatus($this->t('Published "@title".', ['@title' => $node->label()]));
    }
    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }

}
