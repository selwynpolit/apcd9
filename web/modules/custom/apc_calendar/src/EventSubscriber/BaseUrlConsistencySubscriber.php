<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\EventSubscriber;

use Drupal\Core\Routing\RequestContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps Drupal's legacy base-URL globals consistent with the Symfony request.
 *
 * Drupal computes the site's base path twice, from two different sources:
 *
 * - DrupalKernel::initializeRequestGlobals() sets $GLOBALS['base_url'] and
 *   $GLOBALS['base_path'] from dirname($_SERVER['SCRIPT_NAME']). These feed
 *   RequestContext::getCompleteBaseUrl() (the redirect-safety check in
 *   RedirectResponseSubscriber / LocalAwareRedirectResponseTrait), base_path(),
 *   file/asset URLs and drupalSettings.path.baseUrl.
 * - Symfony's Request::prepareBaseUrl() derives the base path by matching
 *   SCRIPT_NAME against REQUEST_URI. This feeds the URL generator, i.e. every
 *   link and redirect target Drupal produces.
 *
 * On production the Main Domain's document root is /public_html and an
 * .htaccess rewrite hands requests to /d9/web/index.php, so SCRIPT_NAME is
 * "/d9/web/index.php" while REQUEST_URI is a clean "/admin/...". Symfony
 * correctly concludes the base path is "" (the rewrite is invisible to the
 * browser); Drupal's globals wrongly say "/d9/web". Every absolute-URL
 * redirect (run cron, one-time login links, password resets, most form
 * submissions) then fails the "is this redirect local?" check with
 * "Redirects to external URLs are not allowed by default", and image/asset
 * URLs get "/d9/web/" baked into the render cache for every hostname.
 *
 * Core tracks the underlying design problem in
 * https://www.drupal.org/node/2404601 (RequestContext depends on
 * $GLOBALS['base_url']) and https://www.drupal.org/node/2529170. There is no
 * settings.php override: a $base_url assignment there is a local variable
 * inside Settings::initialize() and is overwritten by preHandle() regardless.
 *
 * This subscriber runs before routing (RouterListener is priority 32) and,
 * only when the two computations disagree, rewrites the globals to match the
 * Symfony request. On hosts whose document root is the Drupal web directory
 * (d9., dev., DDEV) the values already agree and this is a no-op. It can be
 * removed once the production vhost's document root points directly at
 * /home/austinpr/public_html/d9/web.
 */
final class BaseUrlConsistencySubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RequestContext $requestContext,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => [['onRequest', 1000]],
    ];
  }

  /**
   * Aligns the base-URL globals with what the Symfony request computed.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    // '' for a docroot install, '/sub' for a genuine subdirectory install.
    $base_path = $request->getBasePath();
    $base_url = $request->getSchemeAndHttpHost() . $base_path;

    if (($GLOBALS['base_url'] ?? NULL) === $base_url) {
      return;
    }

    // Mirror exactly what DrupalKernel::initializeRequestGlobals() sets.
    $GLOBALS['base_url'] = $base_url;
    $GLOBALS['base_path'] = $base_path . '/';
    $GLOBALS['base_secure_url'] = str_replace('http://', 'https://', $base_url);
    $GLOBALS['base_insecure_url'] = str_replace('https://', 'http://', $base_url);

    // The request context service may already have been built from the stale
    // global; RouterListener re-reads it, but don't depend on that.
    $this->requestContext->setCompleteBaseUrl($base_url);
  }

}
