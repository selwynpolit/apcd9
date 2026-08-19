<?php

declare(strict_types=1);

namespace Drupal\Tests\apc_calendar\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\apc_calendar\TagSuggester;
use Drupal\taxonomy\TermInterface;

/**
 * Pins down the phrase and exclusion matching rules from auto-tag-task.md.
 *
 * @coversDefaultClass \Drupal\apc_calendar\TagSuggester
 * @group apc_calendar
 */
class TagSuggesterTest extends UnitTestCase {

  /**
   * Builds a TagSuggester whose `tags` storage returns exactly $termsSpec.
   *
   * @param array $termsSpec
   *   Keyed by term name; each value is ['keywords' => [...], 'exclude' =>
   *   [...]]. Term IDs are assigned 1, 2, 3... in iteration order.
   */
  private function suggesterFor(array $termsSpec): TagSuggester {
    $terms = [];
    $tid = 1;
    foreach ($termsSpec as $name => $entry) {
      $terms[] = $this->termMock($tid++, $name, $entry['keywords'] ?? [], $entry['exclude'] ?? []);
    }

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')
      ->with(['vid' => 'tags', 'status' => 1])
      ->willReturn($terms);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($storage);

    return new TagSuggester($entityTypeManager);
  }

  /**
   * A term double exposing field_tag_keywords / field_tag_exclude_keywords.
   */
  private function termMock(int $tid, string $name, array $keywords, array $exclude): TermInterface {
    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn($tid);
    $term->method('label')->willReturn($name);
    $term->method('hasField')->willReturn(TRUE);
    $term->method('get')->willReturnMap([
      ['field_tag_keywords', $this->fieldItemList($keywords)],
      ['field_tag_exclude_keywords', $this->fieldItemList($exclude)],
    ]);
    return $term;
  }

  /**
   * A minimal FieldItemListInterface double: just enough for getValue().
   */
  private function fieldItemList(array $values): object {
    $list = $this->getMockBuilder(\stdClass::class)->addMethods(['getValue'])->getMock();
    $list->method('getValue')->willReturn(array_map(static fn ($value) => ['value' => $value], $values));
    return $list;
  }

  /**
   * @covers ::suggest
   */
  public function testSimpleKeywordMatch(): void {
    $suggester = $this->suggesterFor([
      'Housing' => ['keywords' => ['housing'], 'exclude' => []],
    ]);

    $this->assertSame([1 => 'Housing'], $suggester->suggest('A meeting about housing', ''));
  }

  /**
   * @covers ::suggest
   */
  public function testCaseInsensitive(): void {
    $suggester = $this->suggesterFor([
      'Housing' => ['keywords' => ['housing'], 'exclude' => []],
    ]);

    $this->assertSame([1 => 'Housing'], $suggester->suggest('HOUSING meeting', ''));
  }

  /**
   * @covers ::suggest
   */
  public function testWholeWordBoundary(): void {
    // "council" must not fire inside "councillor" -- the exact case named in
    // auto-tag-task.md's Matching rules.
    $suggester = $this->suggesterFor([
      'City council' => ['keywords' => ['council'], 'exclude' => []],
    ]);

    $this->assertSame([], $suggester->suggest('Meet your local councillor', ''));
    $this->assertSame([1 => 'City council'], $suggester->suggest('Meet your local council', ''));
  }

  /**
   * @covers ::suggest
   */
  public function testPhraseWithNormalizedWhitespace(): void {
    $suggester = $this->suggesterFor([
      'City council' => ['keywords' => ['city council'], 'exclude' => []],
    ]);

    $this->assertSame([1 => 'City council'], $suggester->suggest('', "The city   council\nmet today."));
  }

  /**
   * @covers ::suggest
   */
  public function testExclusionWinsOutright(): void {
    $suggester = $this->suggesterFor([
      'Parking' => ['keywords' => ['parking'], 'exclude' => ['no parking']],
    ]);

    $this->assertSame([], $suggester->suggest('Reminder: no parking on Elm St.', ''));
    $this->assertSame([1 => 'Parking'], $suggester->suggest('Free parking available.', ''));
  }

  /**
   * @covers ::suggest
   */
  public function testBodyHtmlIsStrippedBeforeMatching(): void {
    $suggester = $this->suggesterFor([
      'Housing' => ['keywords' => ['housing'], 'exclude' => []],
    ]);

    // "housing" only appears inside an attribute value, not in the visible
    // text -- strip_tags() drops the whole tag including its attributes, so
    // this must not match.
    $result = $suggester->suggest('', '<a href="/housing-info">Click here</a>');
    $this->assertSame([], $result);
  }

  /**
   * @covers ::suggest
   */
  public function testNoMatchesReturnsEmptyArray(): void {
    $suggester = $this->suggesterFor([
      'Housing' => ['keywords' => ['housing'], 'exclude' => []],
    ]);

    $this->assertSame([], $suggester->suggest('A picnic in the park', 'Bring your own blanket.'));
  }

  /**
   * @covers ::suggest
   */
  public function testMultipleTagsCanMatch(): void {
    $suggester = $this->suggesterFor([
      'Housing' => ['keywords' => ['housing'], 'exclude' => []],
      'Parking' => ['keywords' => ['parking'], 'exclude' => []],
    ]);

    $this->assertSame([1 => 'Housing', 2 => 'Parking'], $suggester->suggest('Housing meeting', 'Free parking available.'));
  }

  /**
   * @covers ::suggest
   */
  public function testTermNameMatchesWithoutCuratedKeywords(): void {
    // A published tags term with no curated keywords still matches on its
    // own name -- curating keywords is for synonyms/exclusions, not a
    // prerequisite for matching at all.
    $suggester = $this->suggesterFor([
      'Database' => ['keywords' => [], 'exclude' => []],
    ]);

    $this->assertSame([1 => 'Database'], $suggester->suggest('Notes on the new database schema', ''));
    $this->assertSame([], $suggester->suggest('A picnic in the park', ''));
  }

  /**
   * @covers ::suggest
   */
  public function testExclusionAppliesToNameFallbackToo(): void {
    // Exclusions suppress a name-based match exactly like a keyword-based
    // one -- the check happens before either kind of match is attempted.
    $suggester = $this->suggesterFor([
      'Code' => ['keywords' => [], 'exclude' => ['zip code']],
    ]);

    $this->assertSame([], $suggester->suggest('Please include your zip code', ''));
    $this->assertSame([1 => 'Code'], $suggester->suggest('A code review meetup', ''));
  }

}
