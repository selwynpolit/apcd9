<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;

/**
 * Converts bare domain names (no "http://" or "www.") into links.
 *
 * Core's own filter_url (Drupal\filter\Plugin\Filter\FilterUrl) only
 * recognizes an absolute URL (has a scheme) or a "www."-prefixed domain --
 * confirmed directly against this site's basic_html/restricted_html formats
 * (the only ones anonymous submitters can pick): typing "example.com" with
 * neither prefix leaves it as plain text, while "www.example.com" and
 * "https://example.com" both auto-link already. That gap is exactly what an
 * anonymous event submitter hits in practice -- people routinely type a bare
 * domain into prose ("more info at austinindivisible.org").
 *
 * This runs as a second, separate filter rather than extending FilterUrl,
 * because FilterUrl::process() builds its whole tag-skipping/task loop
 * inline with no extension point to add one more pattern. Placed at a
 * higher weight than filter_url in the formats that enable it, so by the
 * time this runs, anything filter_url already linked is sitting inside an
 * <a> tag and gets skipped by the same ignore-tag mechanism used here.
 *
 * Matching is deliberately restricted to a curated list of common TLDs
 * (self::TLDS) rather than "any two letters after a dot" -- the latter
 * would also catch abbreviations like "e.g." or "U.S." sitting in ordinary
 * prose. The list can simply grow if a legitimate domain is reported as
 * missed.
 */
#[Filter(
  id: 'apc_calendar_filter_bare_domain_links',
  title: new TranslatableMarkup('Convert bare domain names (without http:// or www.) into links'),
  type: FilterInterface::TYPE_MARKUP_LANGUAGE,
  settings: [
    'filter_url_length' => 72,
  ],
)]
class FilterBareDomainLinks extends FilterBase {

  /**
   * Curated top-level domains this filter will treat as a link.
   *
   * Intentionally not "any 2-64 letter TLD" (what filter_url allows for
   * scheme-prefixed URLs) -- that's far too permissive for text with no
   * scheme or "www." to anchor on, and would start linking ordinary
   * abbreviations.
   */
  protected const TLDS = [
    'com', 'org', 'net', 'edu', 'gov', 'mil', 'info', 'biz',
    'co', 'io', 'me', 'us', 'uk', 'ca', 'tv', 'app', 'dev',
    'blog', 'shop', 'store', 'online', 'ngo',
  ];

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form['filter_url_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum link text length'),
      '#default_value' => $this->settings['filter_url_length'],
      '#min' => 1,
      '#field_suffix' => $this->t('characters'),
      '#description' => $this->t('URLs longer than this number of characters will be truncated to prevent long strings that break formatting. The link itself will be retained; just the text portion of the link will be truncated.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $saved_text = $text;

    // Same tags core's filter_url skips -- notably 'a', so a URL filter_url
    // already linked (or markup otherwise containing one) is never touched.
    $ignore_tags = 'a|script|style|code|pre';

    $label = '[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?';
    $tld_pattern = implode('|', array_map(
      static fn (string $tld): string => preg_quote($tld, '`'),
      self::TLDS,
    ));
    $domain = "(?:$label\\.)+(?:$tld_pattern)\\b";
    // Separate "mid-path" and "last character" classes -- without this, a
    // trailing comma/period after a path (the overwhelmingly common case in
    // prose: "...at example.org/signup, it's quick") gets swallowed into the
    // href. Same split core's own filter_url uses, for the same reason.
    $path_characters = '[\p{L}\p{M}\p{N}!\*\';:=\+,\.\$\/%#\[\]\-_~@&]';
    $path_ending_characters = '[\p{L}\p{M}\p{N}:_+~#=\/]';
    $path = '(?:\/' . $path_characters . '*' . $path_ending_characters . ')?';

    // Not preceded by '@', '.', or a word character -- keeps this from
    // matching an email local-part/subdomain tail, or the end of a domain
    // filter_url already linked in a scheme it was able to recognize.
    $pattern = "`(?<![\\w@.])($domain$path)`iu";

    $text = is_null($text) ? '' : preg_replace_callback('`<!--(.*?)-->`s', [self::class, 'escapeComment'], $text);
    $chunks = is_null($text) ? [''] : preg_split('/(<.+?>)/is', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

    if ($chunks !== FALSE) {
      $chunk_type = 'text';
      $open_tag = '';
      for ($i = 0; $i < count($chunks); $i++) {
        if ($chunk_type === 'text') {
          if ($open_tag === '') {
            $chunks[$i] = preg_replace_callback($pattern, [$this, 'linkBareDomain'], $chunks[$i]);
          }
          $chunk_type = 'tag';
        }
        else {
          if ($open_tag === '') {
            if (preg_match("`<($ignore_tags)(?:\\s|>)`i", $chunks[$i], $matches)) {
              $open_tag = $matches[1];
            }
          }
          elseif (preg_match("`</$open_tag>`i", $chunks[$i])) {
            $open_tag = '';
          }
          $chunk_type = 'text';
        }
      }
      $text = implode($chunks);
    }

    $text = $text ? preg_replace_callback('`<!--(.*?)-->`', [self::class, 'unescapeComment'], $text) : $text;
    $text = strlen((string) $text) > 0 ? $text : $saved_text;

    return new FilterProcessResult($text);
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('Bare web addresses (such as example.com, with no "http://" or "www.") turn into links automatically.');
  }

  /**
   * Builds the replacement link markup for a matched bare domain.
   *
   * Callback for preg_replace_callback() within self::process().
   */
  protected function linkBareDomain(array $match): string {
    $url = Html::decodeEntities($match[1]);
    $caption = Html::escape(Unicode::truncate(
      string: $url,
      max_length: $this->settings['filter_url_length'],
      add_ellipsis: TRUE,
    ));
    return '<a href="' . Html::escape('http://' . $url) . '">' . $caption . '</a>';
  }

  /**
   * Temporary storage for HTML comment contents during processing.
   */
  protected static array $htmlComments = [];

  /**
   * Escapes HTML comment contents so they're left untouched by matching.
   */
  protected static function escapeComment(array $match): string {
    $hash = hash('sha256', $match[1]);
    static::$htmlComments[$hash] = $match[1];
    return "<!-- $hash -->";
  }

  /**
   * Restores HTML comment contents escaped by self::escapeComment().
   */
  protected static function unescapeComment(array $match): string {
    $hash = trim($match[1]);
    $content = static::$htmlComments[$hash] ?? '';
    return "<!--$content-->";
  }

}
