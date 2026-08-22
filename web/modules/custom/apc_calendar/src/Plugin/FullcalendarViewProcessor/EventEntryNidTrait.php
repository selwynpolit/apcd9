<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\FullcalendarViewProcessor;

/**
 * Reads the node ID out of a Fullcalendar View entry.
 *
 * Shared by more than one processor because the `eid` format is not just
 * the node ID: SmartDateProcessor may already have rewritten it to
 * "<nid>-D-<delta>" or "<nid>-R-<rule>-I-<index>" by the time a given
 * processor runs, since processor plugins run in discovery order with no
 * weight control. Keeping the parsing in one place means both processors
 * stay correct if that format ever changes.
 */
trait EventEntryNidTrait {

  /**
   * Reads the node ID from an entry.
   */
  private function extractNid(array $entry): ?int {
    if (!isset($entry['eid'])) {
      return NULL;
    }

    return preg_match('/^(\d+)/', (string) $entry['eid'], $matches)
      ? (int) $matches[1]
      : NULL;
  }

}
