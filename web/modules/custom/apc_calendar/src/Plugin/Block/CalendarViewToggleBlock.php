<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * A button to toggle between the public calendar and the manage calendar.
 *
 * Rendered on both /calendar and /calendar/manage (placed in content_above,
 * request-path limited). On the public calendar it links forward to the manage
 * view; on the manage calendar it links back. Only users who can reach the
 * manage calendar ever see it -- gated on the same permission the manage
 * display uses.
 *
 * @Block(
 *   id = "apc_calendar_view_toggle",
 *   admin_label = @Translation("Calendar view toggle")
 * )
 */
final class CalendarViewToggleBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if (\Drupal::routeMatch()->getRouteName() === 'view.calendar.page_manage') {
      $url = Url::fromRoute('view.calendar.page_1');
      $label = $this->t('← Public calendar');
    }
    else {
      $url = Url::fromRoute('view.calendar.page_manage');
      $label = $this->t('Pending / manage view →');
    }

    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => $url,
      '#attributes' => ['class' => ['apc-calendar-toggle']],
      '#attached' => ['library' => ['apc_calendar/calendar_toggle']],
      // Varies by which calendar page we're on, and by permission (via access).
      '#cache' => ['contexts' => ['route', 'user.permissions']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    // Same gate as the manage display, so the toggle never appears to a user
    // who would be 403'd on /calendar/manage.
    return AccessResult::allowedIfHasPermission($account, 'edit any calendar_event content');
  }

}
