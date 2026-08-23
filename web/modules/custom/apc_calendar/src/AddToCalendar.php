<?php

declare(strict_types=1);

namespace Drupal\apc_calendar;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Builds "Add to calendar" links for one occurrence of a calendar_event.
 *
 * Single source of truth for the calendar-export feature, used by three
 * callers that must agree on the same occurrence and the same event details:
 *  - the /calendar popup (EventPopupController),
 *  - the full event page (apc_brown_preprocess_node()),
 *  - the .ics download route (EventIcsController).
 *
 * No contrib module and no third-party service: Google/Yahoo/Outlook.com each
 * accept a fully pre-filled event as a plain URL, and Apple/iCal is served by
 * our own EventIcsController. This replaces the theme's old single-provider
 * _apc_brown_google_calendar_url() helper -- keeping the URL-building in the
 * module (which owns the popup and the .ics route) rather than the theme.
 *
 * Occurrence-correct by design: a recurring event's popup passes the delta of
 * the date that was actually clicked, so "Add to calendar" adds that Tuesday,
 * not the series' first date. The full page has no clicked occurrence, so it
 * passes delta 0 (the same first/next occurrence the old helper used).
 */
final class AddToCalendar {

  /**
   * Builds the render array for the "Add to calendar" control.
   *
   * A native <details>/<summary> disclosure rather than a JS dropdown: it
   * expands in the normal document flow, so it can't be clipped by the
   * scrollable jQuery UI dialog the popup lives in, and it carries its own
   * keyboard support with no behavior to attach.
   *
   * @return array
   *   A render array, or an empty array when the event has no usable date (so
   *   a caller can `{% if add_to_calendar %}` it out cleanly).
   */
  public static function build(NodeInterface $node, int $delta = 0): array {
    $links = self::links($node, $delta);
    if (!$links) {
      return [];
    }

    // Order = how they render in the menu. 'external' controls target=_blank:
    // the three web providers open a new tab; Apple is our own route serving a
    // file download (attachment), so it must NOT open in a tab.
    $labels = [
      'google' => t('Google Calendar'),
      'apple' => t('Apple Calendar (.ics)'),
      'outlook' => t('Outlook.com'),
      'yahoo' => t('Yahoo Calendar'),
    ];
    $items = [];
    foreach ($labels as $key => $label) {
      if (empty($links[$key])) {
        continue;
      }
      $items[] = [
        'title' => $label,
        'url' => $links[$key],
        'external' => $key !== 'apple',
      ];
    }

    return [
      '#type' => 'inline_template',
      '#template' => '<details class="apc-addtocal">'
      . '<summary class="apc-pill-button apc-addtocal__toggle">{{ label }}</summary>'
      . '<div class="apc-addtocal__menu" role="menu">'
      . '{% for item in items %}'
      . '<a class="apc-addtocal__item" role="menuitem" href="{{ item.url }}"{% if item.external %} target="_blank" rel="noopener noreferrer"{% endif %}>{{ item.title }}</a>'
      . '{% endfor %}'
      . '</div></details>',
      '#context' => [
        // "your" makes it unambiguous that this adds the event to the
        // visitor's own calendar, not the site's.
        'label' => t('Add to your calendar'),
        'items' => $items,
      ],
      '#attached' => ['library' => ['apc_brown/add-to-calendar']],
    ];
  }

  /**
   * Returns the export URL for each provider, keyed by provider.
   *
   * @return array
   *   ['google' => url, 'apple' => ics_url, 'outlook' => url, 'yahoo' => url],
   *   or [] when the event has no usable date.
   */
  public static function links(NodeInterface $node, int $delta = 0): array {
    $data = self::occurrence($node, $delta);
    if ($data === NULL) {
      return [];
    }

    // Compact UTC (basic) form for Google/Yahoo/iCal; ISO-8601 UTC for Outlook.
    $utc = static fn(int $ts): string => gmdate('Ymd\THis\Z', $ts);
    $iso = static fn(int $ts): string => gmdate('Y-m-d\TH:i:s\Z', $ts);

    $google = 'https://calendar.google.com/calendar/render?' . http_build_query([
      'action' => 'TEMPLATE',
      'text' => $data['title'],
      'dates' => $utc($data['start']) . '/' . $utc($data['end']),
      'details' => $data['description'],
      'location' => $data['location'],
    ]);

    $yahoo = 'https://calendar.yahoo.com/?' . http_build_query([
      'v' => 60,
      'title' => $data['title'],
      'st' => $utc($data['start']),
      'et' => $utc($data['end']),
      'desc' => $data['description'],
      'in_loc' => $data['location'],
    ]);

    $outlook = 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query([
      'path' => '/calendar/action/compose',
      'rru' => 'addevent',
      'subject' => $data['title'],
      'startdt' => $iso($data['start']),
      'enddt' => $iso($data['end']),
      'body' => $data['description'],
      'location' => $data['location'],
    ]);

    // Use the effective delta occurrence() resolved to, not the raw argument,
    // so the .ics link points at the same occurrence as the web providers.
    $apple = Url::fromRoute('apc_calendar.event_ics', [
      'node' => $node->id(),
      'delta' => $data['delta'],
    ])->setAbsolute()->toString();

    return [
      'google' => $google,
      'apple' => $apple,
      'outlook' => $outlook,
      'yahoo' => $yahoo,
    ];
  }

  /**
   * Builds the RFC 5545 .ics body for one occurrence, or NULL if no date.
   */
  public static function icsBody(NodeInterface $node, int $delta = 0): ?string {
    $data = self::occurrence($node, $delta);
    if ($data === NULL) {
      return NULL;
    }

    $host = \Drupal::request()->getHost() ?: 'austinprogressivecalendar.com';
    $lines = [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//Austin Progressive Calendar//Calendar//EN',
      'CALSCALE:GREGORIAN',
      'METHOD:PUBLISH',
      'BEGIN:VEVENT',
      'UID:node-' . $node->id() . '-' . $data['delta'] . '@' . $host,
      'DTSTAMP:' . gmdate('Ymd\THis\Z'),
      'DTSTART:' . gmdate('Ymd\THis\Z', $data['start']),
      'DTEND:' . gmdate('Ymd\THis\Z', $data['end']),
      'SUMMARY:' . self::escapeText($data['title']),
      'DESCRIPTION:' . self::escapeText($data['description']),
      'LOCATION:' . self::escapeText($data['location']),
      'URL:' . self::escapeText($data['url']),
      'END:VEVENT',
      'END:VCALENDAR',
    ];

    return implode("\r\n", array_map([self::class, 'foldLine'], $lines)) . "\r\n";
  }

  /**
   * Resolves the clicked occurrence to a flat array of event details.
   *
   * @return array|null
   *   ['delta','start','end','title','description','location','url'] with
   *   start/end as Unix timestamps, or NULL when there is no usable date.
   */
  private static function occurrence(NodeInterface $node, int $delta): ?array {
    if ($node->bundle() !== 'calendar_event') {
      return NULL;
    }
    if (!$node->hasField('field_event_date') || $node->get('field_event_date')->isEmpty()) {
      return NULL;
    }

    $values = $node->get('field_event_date')->getValue();
    // Fall back to the first occurrence for an out-of-range delta rather than
    // failing -- the full page always passes 0, and a stale popup link should
    // still add *something* sensible.
    if (!isset($values[$delta])) {
      $delta = 0;
    }
    if (empty($values[$delta]['value']) || empty($values[$delta]['end_value'])) {
      return NULL;
    }
    $start = (int) $values[$delta]['value'];
    $end = (int) $values[$delta]['end_value'];
    if ($start <= 0 || $end <= 0) {
      return NULL;
    }

    $url = $node->toUrl()->setAbsolute()->toString();

    return [
      'delta' => $delta,
      'start' => $start,
      'end' => $end,
      'title' => (string) $node->label(),
      'description' => self::description($node, $url),
      'location' => self::location($node),
      'url' => $url,
    ];
  }

  /**
   * Builds the plain-text event description (body + join link + back-link).
   */
  private static function description(NodeInterface $node, string $url): string {
    $text = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->first();
      $raw = $body->summary ?: ($body->value ?? '');
      // Cap the length: calendar clients truncate long descriptions anyway,
      // and this keeps the prefilled provider URLs to a sane size.
      $text = trim(mb_substr(html_entity_decode(strip_tags((string) $raw), ENT_QUOTES | ENT_HTML5), 0, 600));
    }

    // Virtual events: surface the join URL, since there is no location to go to.
    if ($node->hasField('field_virtual') && (bool) $node->get('field_virtual')->value
      && $node->hasField('field_event_url') && !$node->get('field_event_url')->isEmpty()) {
      $join = $node->get('field_event_url')->first()->uri ?? '';
      if ($join !== '') {
        $text .= ($text !== '' ? "\n\n" : '') . (string) t('Join online: @url', ['@url' => $join]);
      }
    }

    $text .= ($text !== '' ? "\n\n" : '') . (string) t('Event details: @url', ['@url' => $url]);

    return $text;
  }

  /**
   * Builds the location string (venue name + address), or "Online" if virtual.
   */
  private static function location(NodeInterface $node): string {
    if ($node->hasField('field_virtual') && (bool) $node->get('field_virtual')->value) {
      return (string) t('Online');
    }
    if (!$node->hasField('field_location') || $node->get('field_location')->isEmpty()) {
      return '';
    }

    $term = $node->get('field_location')->entity;
    // Access-gate the venue name: an unpublished (pending) location on an
    // otherwise-published event must not leak its name to an anonymous viewer
    // via the calendar URL.
    if ($term === NULL || !$term->access('view')) {
      return '';
    }

    $parts = [$term->label()];
    if ($term->hasField('field_address') && !$term->get('field_address')->isEmpty()) {
      $address = $term->get('field_address')->first();
      foreach (['address_line1', 'locality', 'administrative_area'] as $property) {
        $value = trim((string) ($address->{$property} ?? ''));
        if ($value !== '') {
          $parts[] = $value;
        }
      }
    }

    return implode(', ', $parts);
  }

  /**
   * Escapes a text value for an RFC 5545 content line.
   */
  private static function escapeText(string $text): string {
    // Backslash first, so the escapes added below aren't double-escaped.
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(["\r\n", "\n", "\r"], '\n', $text);
    $text = str_replace([',', ';'], ['\,', '\;'], $text);
    return $text;
  }

  /**
   * Folds a content line to 75 octets with CRLF + space continuation.
   */
  private static function foldLine(string $line): string {
    if (strlen($line) <= 75) {
      return $line;
    }
    $out = substr($line, 0, 75);
    $rest = substr($line, 75);
    while ($rest !== '' && $rest !== FALSE) {
      // A continuation line begins with a single space, which counts toward
      // the 75-octet limit -- so 74 octets of payload per folded line.
      $out .= "\r\n " . substr($rest, 0, 74);
      $rest = substr($rest, 74);
    }
    return $out;
  }

}
