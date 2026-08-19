<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation page shown after an anonymous visitor submits an event.
 *
 * Anonymous users cannot be granted "view own unpublished content" — core
 * blocks it deliberately, because every anonymous node is owned by uid 0, so
 * the permission would expose the entire moderation queue to the public. This
 * page gives the submitter a read-only echo of what they just sent instead,
 * scoped to their own session.
 *
 * The node ID arrives via the private tempstore, never a query parameter: an
 * enumerable ?nid= on an unpublished node would recreate exactly the leak that
 * core is preventing.
 *
 * @see apc_calendar_event_submit_confirmation()
 */
final class EventSubmittedController extends ControllerBase {

  /**
   * Tempstore collection written by the node form submit handler.
   */
  public const COLLECTION = 'apc_calendar';

  /**
   * Tempstore key holding the submitted node ID and its timestamp.
   */
  public const KEY = 'submitted_nid';

  /**
   * How long after submitting the visitor can still see their own values.
   *
   * The private tempstore's own lifetime (`tempstore.expire`, a week by
   * default) is far too long for this page: a submission made on a shared
   * machine — a library or community centre terminal, entirely plausible for
   * this site's audience — would stay readable to whoever sits down next with
   * the same session cookie. That parameter is shared with Views UI and every
   * other private tempstore consumer, so the window is enforced here instead.
   *
   * Long enough to survive a refresh, a back-button, or a distracted user;
   * short enough that the browser session stops being a disclosure risk.
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
      // This page renders session-scoped, unpublished content, so it must never
      // land in the anonymous page cache or the dynamic page cache — otherwise
      // one submitter's pending event gets served to the next visitor. The
      // route sets no_cache: TRUE; this is the belt to those braces.
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['session'],
      ],
    ];

    $node = $this->loadSubmittedNode();

    if (!$node instanceof NodeInterface) {
      // Direct visit, expired tempstore, or the node was deleted. Show the
      // generic thank-you rather than a 404 — a refresh or a bookmarked visit
      // landing on "page not found" reads as though the submission failed.
      $build['message'] = $this->statusMessage(
        $this->t('Thanks! Your event has been submitted and is waiting for review. It will appear on the calendar once an organizer approves it, usually within a couple of days.')
      );
      $build['back'] = $this->backLink();
      return $build;
    }

    $build['message'] = $this->statusMessage(
      $node->isPublished()
        ? $this->t('Your event has been approved and is now on the calendar.')
        : $this->t('Thanks! Here is what you submitted. It is waiting for review, and will appear on the calendar once an organizer approves it — usually within a couple of days.')
    );
    $build['summary'] = $this->buildSummary($node);
    $build['back'] = $this->backLink();

    return $build;
  }

  /**
   * Loads the node this session just submitted, if it is still valid to show.
   *
   * Returns NULL for anything doubtful — no entry, a stale entry, an entry left
   * by an older release of this module, a deleted node, or the wrong bundle.
   * Every one of those falls back to the generic thank-you rather than an
   * error, because to the visitor a failure here is indistinguishable from
   * their submission having failed.
   */
  private function loadSubmittedNode(): ?NodeInterface {
    $submission = $this->tempStoreFactory->get(self::COLLECTION)->get(self::KEY);

    // Entries written before the freshness window was introduced were a bare
    // int. Treat them as expired rather than trusting an unknown age.
    if (!is_array($submission) || empty($submission['nid']) || empty($submission['time'])) {
      return NULL;
    }

    if ($this->time->getRequestTime() - $submission['time'] > self::FRESHNESS_WINDOW) {
      return NULL;
    }

    $node = $this->entityTypeManager()->getStorage('node')->load($submission['nid']);

    // Defence in depth. The tempstore is already per-session and not
    // user-supplied, but pinning the bundle means a stale or unexpected value
    // can never render arbitrary content off this route.
    if (!$node instanceof NodeInterface || $node->bundle() !== 'calendar_event') {
      return NULL;
    }

    return $node;
  }

  /**
   * Renders the submitted values via the submission_confirmation view mode.
   *
   * Deliberately not the node view builder: that would render node links
   * ("Read more", contextual/edit links) pointing at a URL the submitter gets
   * a 403 on. Instead this walks the fields configured on the
   * `submission_confirmation` view mode
   * (core.entity_view_display.node.calendar_event.submission_confirmation)
   * and renders each one individually with its own formatter, so a field
   * added to that view mode later shows up here without a code change.
   *
   * `field_location` on that view mode uses a plain, non-linking label
   * formatter rather than the "card" formatter used on the public view
   * modes — a newly auto-created location term is unpublished too, so a link
   * to it would 403 the very person who just typed it.
   */
  private function buildSummary(NodeInterface $node): array {
    $summary = [
      '#type' => 'container',
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $node->label(),
      ],
    ];

    $display = EntityViewDisplay::collectRenderDisplay($node, 'submission_confirmation');
    $components = $display->getComponents();
    uasort($components, [SortArray::class, 'sortByWeightElement']);

    foreach ($components as $field_name => $component) {
      // EntityDisplayBase::init() always re-adds 'title', 'uid', 'created'
      // (and other base fields) as visible components on every fresh load,
      // regardless of what is actually persisted in this view mode's config
      // — they are not truly display-configurable for nodes. Restrict to
      // this module's own field naming convention so only fields explicitly
      // added to the submission_confirmation view mode render here.
      if ($field_name !== 'body' && !str_starts_with($field_name, 'field_')) {
        continue;
      }
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }
      // format_custom_false on field_virtual's formatter is deliberately
      // empty (see the view mode config) so a non-virtual event has nothing
      // to say here; skip it outright rather than rendering an empty field
      // wrapper.
      if ($field_name === 'field_virtual' && !(bool) $node->get('field_virtual')->value) {
        continue;
      }
      $summary[$field_name] = $node->get($field_name)->view($component);
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
   * Link back to the public calendar.
   */
  private function backLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Back to the calendar'),
      '#url' => Url::fromUserInput('/calendar'),
    ];
  }

}
