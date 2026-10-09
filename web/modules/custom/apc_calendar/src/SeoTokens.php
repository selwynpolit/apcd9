<?php

declare(strict_types=1);

namespace Drupal\apc_calendar;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Token values that feed the Metatag / Schema.org defaults.
 *
 * Metatag's stock tokens ([node:summary], an image field's URL) come back
 * empty, or as raw HTML, when an editor left a field blank -- and an empty
 * value on a bundle default *overrides* the global fallback instead of
 * deferring to it, so the page ended up with an empty description or no
 * og:image at all. Everything here returns something usable (plain text, an
 * absolute URL), falling back to the site-wide values configured at
 * /admin/config/search/metatag, so editing the global default still changes
 * every page that has nothing better of its own.
 */
final class SeoTokens {

  /**
   * Longest description emitted; search snippets cut off well before this.
   */
  private const DESCRIPTION_LENGTH = 200;

  /**
   * Image fields to try, in order, per entity.
   *
   * Each is an entity reference to an Image media item.
   */
  private const IMAGE_FIELDS = ['field_event_image', 'field_photo_image', 'field_location_image'];

  public static function nodeTokens(NodeInterface $node, array $tokens, BubbleableMetadata $bubbleable_metadata): array {
    $replacements = [];
    foreach ($tokens as $name => $original) {
      $value = match ($name) {
        'apc-seo-description' => self::nodeDescription($node),
        'apc-seo-image' => self::image($node),
        'apc-seo-location-type' => self::isVirtual($node) ? 'VirtualLocation' : 'Place',
        'apc-seo-location-url' => self::locationUrl($node),
        'apc-seo-attendance-mode' => 'https://schema.org/' . (self::isVirtual($node) ? 'Online' : 'Offline') . 'EventAttendanceMode',
        default => NULL,
      };
      if ($value !== NULL) {
        $replacements[$original] = $value;
      }
    }
    if ($replacements) {
      $bubbleable_metadata->addCacheableDependency($node);
      $bubbleable_metadata->addCacheTags(['config:metatag.metatag_defaults.global']);
    }
    return $replacements;
  }

  public static function termTokens(TermInterface $term, array $tokens, BubbleableMetadata $bubbleable_metadata): array {
    $replacements = [];
    foreach ($tokens as $name => $original) {
      $value = match ($name) {
        'apc-seo-description' => self::termDescription($term),
        'apc-seo-image' => self::image($term),
        default => NULL,
      };
      if ($value !== NULL) {
        $replacements[$original] = $value;
      }
    }
    if ($replacements) {
      $bubbleable_metadata->addCacheableDependency($term);
      $bubbleable_metadata->addCacheTags(['config:metatag.metatag_defaults.global']);
    }
    return $replacements;
  }

  /**
   * Body summary (or trimmed body), else the photo caption, as plain text.
   */
  private static function nodeDescription(NodeInterface $node): string {
    $raw = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $item = $node->get('body')->first();
      $raw = (string) ($item->summary ?: $item->value);
    }
    elseif ($node->hasField('field_caption') && !$node->get('field_caption')->isEmpty()) {
      $raw = (string) $node->get('field_caption')->value;
    }
    return self::plainText($raw) ?: self::globalTag('description');
  }

  /**
   * Location notes / term description, else a sentence built from the name.
   */
  private static function termDescription(TermInterface $term): string {
    $raw = '';
    if ($term->hasField('field_notes') && !$term->get('field_notes')->isEmpty()) {
      $raw = (string) $term->get('field_notes')->value;
    }
    elseif (!$term->get('description')->isEmpty()) {
      $raw = (string) $term->get('description')->value;
    }
    if ($text = self::plainText($raw)) {
      return $text;
    }
    return match ($term->bundle()) {
      'locations' => sprintf('Upcoming and past progressive events at %s in Austin, Texas.', $term->label()),
      'tags' => sprintf('Austin progressive events and photos tagged “%s”.', $term->label()),
      default => self::globalTag('description'),
    };
  }

  /**
   * Absolute URL of the entity's first usable image, else the site fallback.
   */
  private static function image(FieldableEntityInterface $entity): string {
    foreach (self::IMAGE_FIELDS as $field_name) {
      if (!$entity->hasField($field_name)) {
        continue;
      }
      $media = $entity->get($field_name)->entity;
      if ($media && $media->isPublished() && $media->hasField('field_media_image')) {
        $file = $media->get('field_media_image')->entity;
        if ($file) {
          return \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        }
      }
    }
    return \Drupal::token()->replace(self::globalTag('og_image'));
  }

  private static function isVirtual(NodeInterface $node): bool {
    return $node->hasField('field_virtual') && (bool) $node->get('field_virtual')->value;
  }

  /**
   * Where the event happens: the venue's page, or the online link.
   *
   * A virtual event with no URL of its own points at its own page, since
   * schema.org's VirtualLocation requires one.
   */
  private static function locationUrl(NodeInterface $node): string {
    if (self::isVirtual($node)) {
      $link = $node->hasField('field_event_url') ? $node->get('field_event_url')->first() : NULL;
      $uri = $link ? (string) $link->uri : '';
      return $uri !== '' ? $uri : $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    $term = $node->hasField('field_location') ? $node->get('field_location')->entity : NULL;
    return $term && $term->isPublished() ? $term->toUrl('canonical', ['absolute' => TRUE])->toString() : '';
  }

  private static function plainText(string $html): string {
    // Tags become spaces first so "<br>" or adjacent paragraphs don't weld
    // two words together; &nbsp; decodes to U+00A0, which \s doesn't match
    // without the /u flag. Decoded twice because imported event bodies
    // (Google Calendar) arrive double-encoded ("&amp;#039;" for an apostrophe).
    $text = Html::decodeEntities(Html::decodeEntities(preg_replace('/<[^>]*>/', ' ', $html)));
    $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    return $text === '' ? '' : Unicode::truncate($text, self::DESCRIPTION_LENGTH, TRUE, TRUE);
  }

  /**
   * A value from the Metatag "Global" default (the site-wide fallback).
   */
  private static function globalTag(string $tag): string {
    return (string) \Drupal::config('metatag.metatag_defaults.global')->get("tags.$tag");
  }

}
