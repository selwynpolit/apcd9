<?php

declare(strict_types=1);

namespace Drupal\apc_calendar;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Matches event title/body text against curated `tags` terms.
 *
 * The keyword map used to live in a single config object edited as one YAML
 * blob (see auto-tag-task.md and the "field_tag_keywords" update in
 * apc_calendar.install for why that moved). It is now just the
 * `field_tag_keywords` / `field_tag_exclude_keywords` fields on ordinary,
 * published `tags` terms.
 *
 * Every published tag is a candidate, whether or not it has curated
 * keywords: the term's own name is always checked too, using the same
 * whole-word matching as a curated keyword. Curating keywords is for adding
 * synonyms/variations the name alone wouldn't catch (or exclusions to
 * suppress false positives) -- not a prerequisite for a tag to match at
 * all.
 *
 * The only matcher that exists for this task -- called from the "Suggest
 * tags" AJAX handler (a submitter is present, suggestions are advisory) and,
 * per auto-tag-task.md's "Collisions" section, intended to also be called
 * from the Feeds import path once that exists (no submitter present, so
 * matches would need to apply automatically there instead of as a
 * checklist).
 *
 * @see auto-tag-task.md
 */
final class TagSuggester {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Suggests tags for the given title and body.
   *
   * @param string $title
   *   Plain text.
   * @param string $body
   *   HTML, as stored in the body field -- stripped of tags here so a tag
   *   named after an HTML attribute value cannot fire on markup.
   *
   * @return string[]
   *   Matched term IDs keyed to their labels, e.g. `[12 => 'Housing']`. Real
   *   term IDs, not names -- a suggestion can only ever exist for a term
   *   that already exists, so unlike the old name-based map there is no
   *   "find or create" step for the caller to worry about.
   */
  public function suggest(string $title, string $body): array {
    $text = $this->normalize($title . ' ' . strip_tags($body));
    if ($text === '') {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['vid' => 'tags', 'status' => 1]);

    $suggestions = [];
    /** @var \Drupal\taxonomy\TermInterface $term */
    foreach ($terms as $term) {
      // The term's own name is always an implicit match phrase, curated
      // keywords or not -- see the class docblock. preg_quote() handles a
      // non-ASCII name safely; \b's non-Unicode boundary semantics just
      // aren't guaranteed correct right at a multi-byte character, the same
      // caveat that applies to curated keywords (kept ASCII-only by
      // apc_calendar_validate_tag_keywords_ascii()).
      $keywords = $this->fieldValues($term, 'field_tag_keywords');
      $keywords[] = $term->label();

      // Exclusions win outright -- checked first and short-circuits the
      // keyword check entirely, rather than masking matched regions (see
      // the Matching rules note in auto-tag-task.md on why the simpler,
      // less-correct approach is the deliberate choice here).
      if ($this->anyPhraseMatches($this->fieldValues($term, 'field_tag_exclude_keywords'), $text)) {
        continue;
      }
      if ($this->anyPhraseMatches($keywords, $text)) {
        $suggestions[(int) $term->id()] = $term->label();
      }
    }

    return $suggestions;
  }

  /**
   * Reads a multi-value plain-text field's values off a term.
   */
  private function fieldValues(TermInterface $term, string $field_name): array {
    if (!$term->hasField($field_name)) {
      return [];
    }
    return array_column($term->get($field_name)->getValue(), 'value');
  }

  /**
   * Collapses all whitespace (including newlines) to single spaces.
   *
   * So a phrase split across a paragraph break, or typed with irregular
   * spacing, still matches -- "city   council" or "city\ncouncil" both
   * normalize to "city council".
   */
  private function normalize(string $text): string {
    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
  }

  /**
   * Whole-word/whole-phrase, case-insensitive match against $text.
   *
   * No /u (UTF-8) modifier: keywords are validated ASCII-only (see
   * apc_calendar_validate_tag_keywords_ascii()) specifically so \b's
   * simpler, non-Unicode semantics are safe to rely on here without a
   * second set of rules for accented text.
   */
  private function anyPhraseMatches(array $phrases, string $text): bool {
    foreach ($phrases as $phrase) {
      $phrase = $this->normalize((string) $phrase);
      if ($phrase === '') {
        continue;
      }
      if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $text) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
