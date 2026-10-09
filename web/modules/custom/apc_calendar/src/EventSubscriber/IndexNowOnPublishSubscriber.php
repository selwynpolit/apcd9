<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\simple_sitemap_engines\Submitter\IndexNowSubmitter;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells IndexNow-aware search engines (Bing and friends) the moment an event,
 * photo or location goes live.
 *
 * Simple XML Sitemap's own "index on entity save" option is not usable here:
 * it fires on every save of a bundle, published or not, and makes its HTTP
 * request inside the page request. This site's content is moderated -- anonymous
 * submissions and Feeds imports are saved unpublished, hundreds of them -- so
 * that would ping search engines about pages that 403, and make a submitter
 * wait on a third party. Instead the hooks in apc_calendar.module call queue()
 * only when something *becomes* published, and the actual request is deferred
 * to kernel.terminate, after the response has been sent.
 *
 * Deferring also solves a timing problem: hook_entity_insert() implementations
 * run in module order, ours before Pathauto's, so asking for the URL there
 * would return /node/123 instead of the alias the crawler should be told
 * about. By terminate time the alias exists.
 *
 * Google does not support IndexNow; this only reaches Bing, Yandex, Naver,
 * Seznam and Yep (and engines that take their index from Bing).
 */
final class IndexNowOnPublishSubscriber implements EventSubscriberInterface {

  /**
   * Bundles worth announcing, keyed by entity type.
   *
   * The content that passes through moderation. Pages and blog posts are
   * written by an admin and change rarely, so the normal sitemap crawl is
   * soon enough for them.
   */
  private const BUNDLES = [
    'node' => ['calendar_event', 'community_photo'],
    'taxonomy_term' => ['locations'],
  ];

  /**
   * Entities to announce, as [entity type ID, entity ID] pairs.
   *
   * Only IDs are held, not entities, so the URL is built from a fresh load
   * after the request has finished (see class comment).
   *
   * @var array<string, array{0: string, 1: string|int}>
   */
  private array $pending = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly ?IndexNowSubmitter $submitter = NULL,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

  /**
   * Remembers an entity that has just been published.
   *
   * The caller decides that it was a publish (new and published, or
   * unpublished -> published); this only filters on bundle and on whether
   * IndexNow is switched on for this environment.
   */
  public function queue(EntityInterface $entity): void {
    $bundles = self::BUNDLES[$entity->getEntityTypeId()] ?? [];
    if (!in_array($entity->bundle(), $bundles, TRUE)) {
      return;
    }
    if (!$this->submitter || !$this->configFactory->get('simple_sitemap_engines.settings')->get('index_now_enabled')) {
      return;
    }
    $this->pending[$entity->getEntityTypeId() . ':' . $entity->id()] = [$entity->getEntityTypeId(), $entity->id()];
  }

  public function onTerminate(TerminateEvent $event): void {
    $pending = $this->pending;
    $this->pending = [];

    foreach ($pending as [$entity_type_id, $id]) {
      try {
        $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
        // Re-check: it could have been unpublished or deleted again later in
        // the same request, and an unpublished page is a 403 to the crawler.
        if ($entity instanceof EntityPublishedInterface && $entity->isPublished()) {
          $this->submitter->submit($entity);
        }
      }
      catch (\Throwable $e) {
        // A search-engine notification must never break the request that
        // published the content; the sitemap crawl will pick it up anyway.
        $this->logger->warning('IndexNow submission for @type @id failed: @message', [
          '@type' => $entity_type_id,
          '@id' => $id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

}
