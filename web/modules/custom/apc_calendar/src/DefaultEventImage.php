<?php

declare(strict_types=1);

namespace Drupal\apc_calendar;

/**
 * URL of the illustrated fallback shown wherever a calendar_event's
 * field_event_image is empty (FrontPageController's cards, the event
 * detail page's gallery) -- not every organizer uploads a photo.
 *
 * A static theme asset (apc_brown's images/default-event-image.svg), not a
 * managed Drupal file: it never changes per-request, so there's nothing to
 * gain from the file/media entity machinery, just a plain URL to build.
 */
final class DefaultEventImage {

  public static function url(): string {
    $theme_path = \Drupal::service('extension.list.theme')->getPath('apc_brown');
    return base_path() . $theme_path . '/images/default-event-image.svg';
  }

}
