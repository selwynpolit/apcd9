<?php

declare(strict_types=1);

namespace Drupal\apc_calendar;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Prepares a photo batch for description, and merges the descriptions back.
 *
 * The authoring half of the bulk-import workflow, and the only half that
 * involves looking at the photos. It brackets a step it does not itself
 * perform:
 *
 *   1. `prep()` writes orientation-corrected thumbnails and a task brief.
 *   2. *Something looks at the thumbnails* -- in practice a cheap vision
 *      model -- and drops one small JSON file per photo into `.prep/rows/`.
 *   3. `merge()` folds those JSON files into `manifest.yml`.
 *   4. A human edits `manifest.yml`, then PhotoBatchImporter consumes it.
 *
 * Step 2 writes **one flat JSON file per photo** rather than editing the
 * manifest directly, and that is the load-bearing design decision here. A
 * small model asked to maintain correct indentation across a 200-entry YAML
 * file will eventually corrupt it, and a corrupted manifest is a corrupted
 * batch; asked to write `{"title": "...", "alt": "..."}` for one image at a
 * time, it is reliable. All the YAML is emitted by this class, from data it
 * validated.
 *
 * Thumbnails, not originals, for two reasons: a 4032px phone photo costs
 * roughly 20x the image tokens of a 900px thumbnail for no gain in what can
 * be described, and -- less obvious -- the thumbnail must have the EXIF
 * orientation *baked into its pixels*. A vision model has no EXIF; handed a
 * raw portrait phone photo it sees a sideways image, describes it badly, and
 * places the focal point in a rotated coordinate frame. Hence
 * apc_calendar_apply_exif_orientation(), shared with the upload hook so the
 * two can never disagree about which way is up.
 *
 * @see \Drupal\apc_calendar\PhotoBatchImporter
 */
final class PhotoBatchPrep {

  /**
   * Working subdirectory created inside the batch directory.
   */
  public const PREP_DIR = '.prep';

  /**
   * Longest edge of a generated thumbnail, in pixels.
   *
   * Large enough to read a hand-lettered protest sign, small enough that a
   * few hundred of them stay cheap. Image tokens scale with width x height,
   * so this is the single biggest lever on what a batch costs to describe.
   */
  public const THUMB_MAX = 900;

  /**
   * Keys a description row may set, and whether each is required.
   */
  private const ROW_SCHEMA = [
    'title' => TRUE,
    'alt' => TRUE,
    'caption' => FALSE,
    'placement' => TRUE,
    'categories' => FALSE,
    'suggested_new_categories' => FALSE,
    'focal_point' => FALSE,
    'notes' => FALSE,
  ];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds thumbnails, a task brief and a skeleton manifest.
   *
   * @param string $directory
   *   Absolute path to the batch directory.
   *
   * @return array{summary: array, new_rows: int}
   *   `summary`: per-image rows (filename, dimensions, whether a date was
   *   found), for every image currently in the directory. `new_rows`: how
   *   many of those didn't already have a manifest row and just got one --
   *   equal to the full count on a first run, and the number of newly added
   *   photos on a re-run against a batch that already has a manifest.
   */
  public function prep(string $directory): array {
    $directory = rtrim($directory, '/');

    // Everything is checked before anything is created. mkdir() with the
    // recursive flag will happily bring a whole tree into existence at a
    // mistyped path, so a wrong argument used to leave a bogus `.prep`
    // skeleton behind instead of just failing.
    if (!is_dir($directory)) {
      throw new \RuntimeException("Not a directory: $directory");
    }

    $images = $this->sourceImages($directory);
    if ($images === []) {
      throw new \RuntimeException("No importable images found in $directory.");
    }

    $prep = $directory . '/' . self::PREP_DIR;
    foreach (["$prep/thumbs", "$prep/rows"] as $path) {
      if (!is_dir($path) && !mkdir($path, 0775, TRUE) && !is_dir($path)) {
        throw new \RuntimeException("Could not create $path.");
      }
    }

    $summary = [];
    foreach ($images as $file) {
      $summary[] = $this->prepareOne($directory, $prep, $file);
    }

    file_put_contents("$prep/TASK.md", $this->buildBrief($directory, $summary));
    $newRows = $this->writeSkeleton($directory, $summary);

    return ['summary' => $summary, 'new_rows' => $newRows];
  }

  /**
   * Folds `.prep/rows/*.json` into the manifest, validating as it goes.
   *
   * @return array{merged: int, skipped: int, problems: string[]}
   *   Counts plus anything that was rejected and why.
   */
  public function merge(string $directory): array {
    $directory = rtrim($directory, '/');
    $manifestPath = $directory . '/' . PhotoBatchImporter::MANIFEST_FILENAME;
    if (!is_readable($manifestPath)) {
      throw new \RuntimeException('Run the prep command first -- no manifest.yml to merge into.');
    }

    $manifest = Yaml::parse((string) file_get_contents($manifestPath));
    $known = $this->categoryNames();
    $merged = 0;
    $skipped = 0;
    $problems = [];

    foreach ($manifest['photos'] as $index => $row) {
      $jsonPath = sprintf('%s/%s/rows/%s.json', $directory, self::PREP_DIR, pathinfo((string) $row['file'], PATHINFO_FILENAME));
      if (!is_readable($jsonPath)) {
        $skipped++;
        continue;
      }

      $decoded = json_decode((string) file_get_contents($jsonPath), TRUE);
      if (!is_array($decoded)) {
        $problems[] = sprintf('%s: not valid JSON.', basename($jsonPath));
        continue;
      }

      [$clean, $rowProblems] = $this->sanitize($decoded, (string) $row['file'], $known);
      $problems = array_merge($problems, $rowProblems);

      // A row that lost its required keys during sanitising is left alone
      // rather than half-merged -- a half-filled row looks done at a glance
      // and is exactly the kind of thing that slips through review.
      if (isset($clean['title'], $clean['alt'])) {
        $manifest['photos'][$index] = $row + $clean;
        $merged++;
      }
    }

    file_put_contents($manifestPath, $this->dump($manifest));

    return [
      'merged' => $merged,
      'skipped' => $skipped,
      'problems' => $problems,
      'suggested_categories' => $this->tallySuggestedCategories($manifest),
    ];
  }

  /**
   * Builds a static, read-only HTML page: each thumbnail beside its text.
   *
   * Reviewing a 44-row `manifest.yml` meant a text editor in one window and
   * `.prep/thumbs/` in a file browser in another, matching filenames by eye
   * -- workable, but exactly the kind of clunky a page that just puts each
   * photo next to its own text fixes. Deliberately not an editor: no form
   * fields, no save-back, no server. `manifest.yml` stays the one place
   * edits happen (open it in a text editor on another monitor, as
   * intended); this is regenerated fresh from whatever it currently says,
   * every time it's asked for -- it can drift from a manifest edited since
   * the last generation, which is fine given how cheap regenerating it is.
   *
   * @return int
   *   How many rows have a NOT-A-PHOTO/POSSIBLE-REPOST flag, for the
   *   command's own summary line.
   */
  public function buildReviewPage(string $directory): int {
    $directory = rtrim($directory, '/');
    $manifest = $this->readManifestForReview($directory);

    $cards = [];
    $jumpLinks = [];
    $flagged = 0;
    foreach ($manifest['photos'] as $index => $row) {
      if (!is_array($row)) {
        continue;
      }
      $notes = trim((string) ($row['notes'] ?? ''));
      $isFlagged = str_starts_with($notes, 'NOT-A-PHOTO') || str_starts_with($notes, 'POSSIBLE-REPOST');
      if ($isFlagged) {
        $flagged++;
        $jumpLinks[] = sprintf(
          '<a href="#photo-%d">%s</a>',
          $index,
          htmlspecialchars((string) ($row['file'] ?? "row $index"), ENT_QUOTES),
        );
      }
      $cards[] = $this->buildReviewCard($index, $row, $isFlagged);
    }

    $html = $this->renderReviewPage($directory, count($manifest['photos']), $flagged, $jumpLinks, $cards);
    file_put_contents(rtrim($directory, '/') . '/' . self::PREP_DIR . '/review.html', $html);

    return $flagged;
  }

  /**
   * Reads manifest.yml for buildReviewPage() -- read-only, never written back.
   */
  private function readManifestForReview(string $directory): array {
    $path = $directory . '/' . PhotoBatchImporter::MANIFEST_FILENAME;
    if (!is_readable($path)) {
      throw new \RuntimeException('No manifest.yml to review -- run prep and merge first.');
    }

    $manifest = Yaml::parse((string) file_get_contents($path));
    if (!is_array($manifest) || !is_array($manifest['photos'] ?? NULL)) {
      throw new \RuntimeException('manifest.yml did not parse as expected.');
    }

    return $manifest;
  }

  /**
   * Renders one photo's thumbnail + fields as an HTML fragment.
   */
  private function buildReviewCard(int $index, array $row, bool $flagged): string {
    $file = (string) ($row['file'] ?? '');
    $thumb = 'thumbs/' . rawurlencode(pathinfo($file, PATHINFO_FILENAME)) . '.jpg';

    $marker = '';
    if (!empty($row['focal_point']) && preg_match('/^\s*(\d{1,3})\s*,\s*(\d{1,3})\s*$/', (string) $row['focal_point'], $m)) {
      $marker = sprintf('<div class="focal" style="left:%d%%;top:%d%%" title="focal_point: %s"></div>', (int) $m[1], (int) $m[2], (string) $row['focal_point']);
    }

    $badges = '';
    foreach ((array) ($row['categories'] ?? []) as $name) {
      $badges .= '<span class="badge">' . htmlspecialchars((string) $name, ENT_QUOTES) . '</span>';
    }
    foreach ((array) ($row['suggested_new_categories'] ?? []) as $name) {
      $badges .= '<span class="badge badge-new">' . htmlspecialchars((string) $name, ENT_QUOTES) . ' (new)</span>';
    }

    $notes = trim((string) ($row['notes'] ?? ''));
    $notesHtml = $notes === '' ? '' : sprintf(
      '<p class="notes%s">%s</p>',
      $flagged ? ' notes-flagged' : '',
      nl2br(htmlspecialchars($notes, ENT_QUOTES)),
    );
    $captionHtml = empty($row['caption']) ? '' : '<p class="caption">' . htmlspecialchars((string) $row['caption'], ENT_QUOTES) . '</p>';
    $nidHtml = empty($row['nid']) ? '' : sprintf('<span class="nid">node %d</span>', (int) $row['nid']);

    return sprintf(
      <<<'HTML'
      <article class="card%s" id="photo-%d">
        <div class="thumb"><img src="%s" loading="lazy" alt="">%s</div>
        <div class="fields">
          <h2>%s%s</h2>
          <p class="meta">%s &middot; %s</p>
          <p class="alt"><span class="label">alt</span>%s</p>
          %s
          <div class="badges">%s</div>
          %s
        </div>
      </article>
      HTML,
      $flagged ? ' flagged' : '',
      $index,
      htmlspecialchars($thumb, ENT_QUOTES),
      $marker,
      htmlspecialchars((string) ($row['title'] ?? '(no title)'), ENT_QUOTES),
      $nidHtml,
      htmlspecialchars($file, ENT_QUOTES),
      htmlspecialchars(implode(', ', (array) ($row['placement'] ?? [])), ENT_QUOTES),
      htmlspecialchars((string) ($row['alt'] ?? ''), ENT_QUOTES),
      $captionHtml,
      $badges,
      $notesHtml,
    );
  }

  /**
   * Wraps the rendered cards in the page shell: summary, jump list, cards.
   */
  private function renderReviewPage(string $directory, int $total, int $flagged, array $jumpLinks, array $cards): string {
    $generated = date('Y-m-d H:i');
    $jumpSection = $jumpLinks === [] ? '' : sprintf(
      '<p class="jump"><strong>Flagged (%d):</strong> %s</p>',
      count($jumpLinks),
      implode(' &middot; ', $jumpLinks),
    );
    $cardsHtml = implode("\n", $cards);

    return <<<HTML
    <!doctype html>
    <html>
    <head>
    <meta charset="utf-8">
    <title>Photo batch review — {$directory}</title>
    <style>
      body { font: 15px/1.5 -apple-system, system-ui, sans-serif; background: #f4f1ec; color: #2a2118; margin: 0; padding: 2rem; }
      h1 { font-size: 1.3rem; margin: 0 0 0.25rem; }
      .summary { color: #6b5f4f; margin: 0 0 1.5rem; }
      .jump { background: #fff3e6; border: 1px solid #e8c99a; border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; }
      .jump a { color: #8a4b12; text-decoration: none; margin-right: 0.5rem; }
      .jump a:hover { text-decoration: underline; }
      .card { display: grid; grid-template-columns: 320px 1fr; gap: 1.25rem; background: #fff; border: 1px solid #e2dbcd; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
      .card.flagged { border-color: #d98c3f; background: #fffaf3; }
      .thumb { position: relative; }
      .thumb img { width: 100%; display: block; border-radius: 4px; background: #eee; }
      .focal { position: absolute; width: 14px; height: 14px; margin: -7px; border: 2px solid #fff; border-radius: 50%; background: rgba(217,66,40,0.85); box-shadow: 0 0 0 1px rgba(0,0,0,0.4); }
      .fields h2 { font-size: 1.05rem; margin: 0 0 0.35rem; }
      .nid { font-size: 0.75rem; font-weight: normal; color: #4b7a4f; background: #e7f2e7; border-radius: 4px; padding: 0.1rem 0.4rem; margin-left: 0.5rem; }
      .meta { color: #8a8171; font-size: 0.85rem; margin: 0 0 0.6rem; }
      .alt, .caption { margin: 0.35rem 0; }
      .label { display: inline-block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #8a8171; margin-right: 0.5rem; }
      .badges { margin: 0.6rem 0; }
      .badge { display: inline-block; background: #eee6d8; color: #5a4d38; border-radius: 999px; padding: 0.15rem 0.6rem; font-size: 0.8rem; margin: 0 0.3rem 0.3rem 0; }
      .badge-new { background: #fde8d2; color: #8a4b12; }
      .notes { font-size: 0.88rem; color: #6b5f4f; border-top: 1px dashed #e2dbcd; padding-top: 0.5rem; margin-top: 0.6rem; }
      .notes-flagged { color: #8a4b12; font-weight: 600; }
    </style>
    </head>
    <body>
    <h1>Photo batch review</h1>
    <p class="summary">{$total} photos &middot; {$flagged} flagged &middot; generated {$generated} &middot; edit manifest.yml directly, then regenerate this page</p>
    {$jumpSection}
    {$cardsHtml}
    </body>
    </html>
    HTML;
  }

  /**
   * Counts how many rows suggested each not-yet-existing category name.
   *
   * Buried per-row in `suggested_new_categories`, this surfaces it as one
   * list: a name several rows independently suggest is a real gap in
   * `photo_categories` worth creating; a name only one row suggests,
   * especially one flagged NOT-A-PHOTO, often is not.
   *
   * @return array<string, int>
   *   Suggested name to row count, sorted most-suggested first.
   */
  private function tallySuggestedCategories(array $manifest): array {
    $tally = [];
    foreach ($manifest['photos'] as $row) {
      foreach ((array) ($row['suggested_new_categories'] ?? []) as $name) {
        $tally[$name] = ($tally[$name] ?? 0) + 1;
      }
    }
    arsort($tally);

    return $tally;
  }

  /**
   * Lists the importable image filenames in a batch directory, sorted.
   *
   * Extensions match `file_extensions` on field_media_image, so anything the
   * import would later reject never gets a thumbnail or a manifest row in the
   * first place. Not recursive, and the `.prep` working directory is skipped
   * by virtue of only files being considered.
   *
   * @return string[]
   *   Bare filenames, alphabetically sorted.
   */
  private function sourceImages(string $directory): array {
    $allowed = ['png', 'gif', 'jpg', 'jpeg', 'webp'];

    $files = [];
    foreach ((array) scandir($directory) as $entry) {
      if (!is_string($entry) || str_starts_with($entry, '.')) {
        continue;
      }
      if (!is_file("$directory/$entry")) {
        continue;
      }
      if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $allowed, TRUE)) {
        $files[] = $entry;
      }
    }

    sort($files);

    return $files;
  }

  /**
   * Thumbnails one image and reads what metadata is worth keeping.
   */
  private function prepareOne(string $directory, string $prep, string $file): array {
    $path = "$directory/$file";
    $info = @getimagesize($path);
    if ($info === FALSE) {
      throw new \RuntimeException("Not a readable image: $file");
    }

    $orientation = NULL;
    $taken = NULL;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
      $exif = @exif_read_data($path);
      if (is_array($exif)) {
        $orientation = !empty($exif['Orientation']) ? (int) $exif['Orientation'] : NULL;
        // The timestamp is worth carrying: the galleries sort on the node's
        // created date, and a batch is usually months of accumulated photos.
        // The GPS tags sitting next to it in the same EXIF block are
        // deliberately never read -- photo-gallery-specs.md treats precise
        // coordinates on a protest photo as a de-anonymisation risk, and the
        // upload hook strips them. Carrying them into a manifest would put
        // them back in a plain-text file.
        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $key) {
          if (!empty($exif[$key]) && ($stamp = strtotime((string) $exif[$key])) !== FALSE) {
            $taken = date('c', $stamp);
            break;
          }
        }
      }
    }

    $this->writeThumb($path, $info[2], $orientation, sprintf('%s/thumbs/%s.jpg', $prep, pathinfo($file, PATHINFO_FILENAME)));

    // Report the *displayed* dimensions, which for an orientation-tagged
    // photo are the stored ones swapped.
    [$width, $height] = [$info[0], $info[1]];
    if ($orientation !== NULL && in_array($orientation, [5, 6, 7, 8], TRUE)) {
      [$width, $height] = [$height, $width];
    }

    return [
      'file' => $file,
      'width' => $width,
      'height' => $height,
      'orientation' => $orientation,
      'taken' => $taken,
    ];
  }

  /**
   * Writes one orientation-corrected, downscaled JPEG thumbnail.
   */
  private function writeThumb(string $path, int $type, ?int $orientation, string $destination): void {
    $image = match ($type) {
      IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
      IMAGETYPE_PNG => @imagecreatefrompng($path),
      IMAGETYPE_GIF => @imagecreatefromgif($path),
      IMAGETYPE_WEBP => @imagecreatefromwebp($path),
      default => FALSE,
    };
    if ($image === FALSE) {
      throw new \RuntimeException("Could not decode $path.");
    }

    $image = apc_calendar_apply_exif_orientation($image, $orientation);

    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1.0, self::THUMB_MAX / max($width, $height));
    if ($scale < 1.0) {
      $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
      if ($resized !== FALSE) {
        imagedestroy($image);
        $image = $resized;
      }
    }

    imagejpeg($image, $destination, 82);
    imagedestroy($image);
  }

  /**
   * Rejects anything a description row should not be able to introduce.
   *
   * @return array{array, string[]}
   *   The cleaned row and any problems worth reporting.
   */
  private function sanitize(array $row, string $file, array $known): array {
    $clean = [];
    $problems = [];

    foreach ($row as $key => $value) {
      if (!array_key_exists($key, self::ROW_SCHEMA)) {
        $problems[] = sprintf('%s: ignoring unknown key "%s".', $file, $key);
        continue;
      }
      $clean[$key] = $value;
    }

    foreach (['title', 'alt', 'caption', 'notes'] as $key) {
      if (isset($clean[$key])) {
        // Collapse to a single line: the manifest stays readable and
        // hand-editable, and nothing downstream wants embedded newlines.
        $clean[$key] = trim(preg_replace('/\s+/u', ' ', (string) $clean[$key]) ?? '');
        if ($clean[$key] === '') {
          unset($clean[$key]);
        }
      }
    }

    $placements = array_values(array_filter(
      (array) ($clean['placement'] ?? []),
      static fn ($value): bool => in_array($value, PhotoBatchImporter::PLACEMENTS, TRUE),
    ));
    if ($placements === []) {
      $problems[] = sprintf('%s: no usable placement, falling back to community_photo.', $file);
      $placements = ['community_photo'];
    }
    $clean['placement'] = $placements;

    // The whole point of the separate suggested_new_categories key: anything
    // that is not already a real term gets moved there instead of staying in
    // `categories`, where the importer would refuse the row -- and where, if
    // the importer ever stopped refusing, field_photo_tags' auto_create would
    // quietly turn a hallucinated near-duplicate into a real term.
    $categories = [];
    $suggested = [];
    foreach ((array) ($clean['categories'] ?? []) as $name) {
      $name = trim((string) $name);
      if ($name === '') {
        continue;
      }
      $match = $known[mb_strtolower($name)] ?? NULL;
      if ($match !== NULL) {
        $categories[] = $match;
      }
      else {
        $problems[] = sprintf('%s: "%s" is not an existing category -- moved to suggested_new_categories.', $file, $name);
        $suggested[] = $name;
      }
    }
    // suggested_new_categories can legitimately name something that already
    // exists -- the model doesn't always get the categories/suggestions
    // split right (seen live: it suggested "EcoFest" as new when that term
    // already existed). Resolve those quietly, rather than a real name just
    // sitting there looking like a gap in the vocabulary that isn't one; no
    // $problems entry, since filing an existing name here isn't a rule
    // violation the way putting one directly in `categories` is.
    foreach ((array) ($clean['suggested_new_categories'] ?? []) as $name) {
      $name = trim((string) $name);
      if ($name === '') {
        continue;
      }
      $match = $known[mb_strtolower($name)] ?? NULL;
      if ($match !== NULL) {
        $categories[] = $match;
      }
      else {
        $suggested[] = $name;
      }
    }
    $clean['categories'] = array_values(array_unique($categories));
    if ($suggested !== []) {
      $clean['suggested_new_categories'] = array_values(array_unique($suggested));
    }
    else {
      unset($clean['suggested_new_categories']);
    }

    if (isset($clean['focal_point']) && !preg_match('/^\s*\d{1,3}\s*,\s*\d{1,3}\s*$/', (string) $clean['focal_point'])) {
      $problems[] = sprintf('%s: dropping unparseable focal_point "%s".', $file, $clean['focal_point']);
      unset($clean['focal_point']);
    }

    return [$clean, $problems];
  }

  /**
   * Writes the manifest skeleton, one row per image, descriptions blank.
   *
   * If a manifest already exists, this **appends** a skeleton row for any
   * file not already in it, rather than doing nothing. That gap was real,
   * not hypothetical: adding more photos to a batch that had already been
   * prepped, merged and partly imported left the new files with thumbnails
   * and AI-authored `.prep/rows/*.json`, but no manifest row at all --
   * `merge()` only ever loops over rows the manifest already has, so it
   * silently ignored them and reported "0 photo(s) had no description yet",
   * which reads as "nothing missing" when 16 fully-described photos had in
   * fact been dropped on the floor. Existing rows -- including any `nid`
   * from a prior import, or hand edits -- are left completely untouched;
   * only genuinely new files get a new row appended.
   *
   * @return int
   *   How many new rows were added.
   */
  private function writeSkeleton(string $directory, array $summary): int {
    $path = $directory . '/' . PhotoBatchImporter::MANIFEST_FILENAME;

    if (!file_exists($path)) {
      $photos = [];
      foreach ($summary as $item) {
        $photos[] = $this->skeletonRow($item);
      }

      file_put_contents($path, $this->dump([
        'defaults' => [
          'submitter_name' => '',
          'credit_publicly' => FALSE,
          'placement' => ['community_photo'],
          'published' => FALSE,
        ],
        'photos' => $photos,
      ]));

      return count($photos);
    }

    $manifest = Yaml::parse((string) file_get_contents($path));
    $manifest += ['defaults' => [], 'photos' => []];
    $known = array_column($manifest['photos'], 'file');

    $added = 0;
    foreach ($summary as $item) {
      if (in_array($item['file'], $known, TRUE)) {
        continue;
      }
      $manifest['photos'][] = $this->skeletonRow($item);
      $added++;
    }

    if ($added > 0) {
      file_put_contents($path, $this->dump($manifest));
    }

    return $added;
  }

  /**
   * Builds one bare manifest row (file, plus a date if EXIF had one).
   */
  private function skeletonRow(array $item): array {
    $row = ['file' => $item['file']];
    if ($item['taken'] !== NULL) {
      $row['created'] = $item['taken'];
    }

    return $row;
  }

  /**
   * Renders the brief that tells the describing step what to produce.
   */
  private function buildBrief(string $directory, array $summary): string {
    $categories = array_values($this->categoryNames());
    sort($categories);

    $lines = [];
    foreach ($summary as $item) {
      $lines[] = sprintf(
        '- `%s.jpg` -- %dx%d%s',
        pathinfo($item['file'], PATHINFO_FILENAME),
        $item['width'],
        $item['height'],
        $item['height'] > $item['width'] ? ' (portrait)' : '',
      );
    }

    $lines_joined = implode("\n", $lines);
    $placements = implode('`, `', PhotoBatchImporter::PLACEMENTS);
    $categoryList = $categories === [] ? '_(none exist yet)_' : '`' . implode('`, `', $categories) . '`';
    // Relative paths deliberately: this brief is written by PHP running in
    // the DDEV container, but read by something working on the host, where
    // /var/www/html does not exist. Whoever hands over the brief supplies the
    // batch directory; everything in here is relative to it.
    $thumbDir = self::PREP_DIR . '/thumbs';
    $rowDir = self::PREP_DIR . '/rows';
    $count = count($summary);

    return <<<MD
    # Describe {$count} photos for the Austin Progressive Calendar

    These are photos from progressive activism in Austin, Texas -- protests,
    rallies, tabling events, community gatherings, hand-made protest signs, and
    memes/graphics shared in that same spirit. They are going into a public
    photo gallery.

    All paths below are relative to the batch directory you were given.

    Look at each thumbnail in `{$thumbDir}/` and write one JSON file per photo
    into `{$rowDir}/`, named after the image (`rally-01.jpg` -> `rally-01.json`).

    ## The JSON to write

    ```json
    {
      "title": "Short specific title, under 80 characters",
      "alt": "One sentence describing what is visible, for a screen reader",
      "caption": "One or two sentences of context, or omit if you have nothing to add",
      "placement": ["community_photo"],
      "categories": ["Protest"],
      "suggested_new_categories": [],
      "focal_point": "50,25",
      "notes": "Anything you were unsure about"
    }
    ```

    ## Rules

    - **`placement`** must contain only: `{$placements}`. Use `protest_sign` for
      a hand-made sign, banner, placard, or a meme/graphic making a political
      point -- signs and memes belong in the same collection here; use
      `community_photo` for people, crowds, tabling and events. A photo that is
      clearly both can list both.
    - **`categories`** may ONLY contain names from this exact list:
      {$categoryList}
      Match case-insensitively but reproduce the name as written above. If a
      photo obviously needs a category that is not on the list, put your
      suggestion in `suggested_new_categories` instead -- never invent a value
      for `categories`. A suggestion does eventually become a real category
      (a person reviews the manifest first), so pick a clear, general name
      you'd be comfortable seeing reused on other photos, not a one-off
      description of this specific image.
    - **`alt`** is required and must describe what is actually visible, not the
      significance of it. If a sign or meme's text is legible, quote it --
      that is usually the single most useful thing in the photo. Keep it to
      one or two sentences, well under 500 characters. If a photo has many
      signs or a lot of text, describe the scene and quote the one or two
      most prominent signs rather than transcribing every sign in the photo
      -- that level of detail belongs in `caption`, not `alt`.
    - **`title`** is required. Prefer the sign or meme's own words.
    - **`focal_point`** is `x,y` as percentages from the top-left, marking the
      most important part of the image -- a face, the readable text on a sign.
      The gallery crops around this point, so it matters most for portrait
      photos. Omit it if the subject is centred and it does not matter.
    - **`notes`** is for you: say so if a photo is blurry, ambiguous, appears to
      show something sensitive, or if you are guessing. A person reads these.
    - **Say so in `notes` if the image is not an original photograph** -- a
      meme, a screenshot, a poster, an AI-generated or composited picture, or
      anything that looks downloaded from the internet rather than taken by
      someone at an event. Start the note with `NOT-A-PHOTO:`. Memes and
      graphics shared for commentary are welcome content here and don't need
      anyone's approval -- flag them anyway, just so it's clear this wasn't
      photographed at a specific Austin event: describe it accurately, and
      never write a caption or `created` date implying it was.
    - **A second, narrower flag: `POSSIBLE-REPOST:`** for something that
      isn't a meme/graphic but looks like a screenshot of someone *else's*
      original photograph -- a candid photo or an ad/sign someone else
      photographed, reposted with no caption or joke added, not created for
      sharing the way a meme is. That's a different, real question (using
      an unknown photographer's uncredited work) worth a person's separate
      look, unlike a meme.
    - **Look for app chrome before deciding an image is an original photo.**
      The easiest one to get wrong is a screenshot *of* a photograph -- the
      picture inside it looks real, so it reads as a genuine photo. Check the
      edges and corners every time for: like / comment / share / bookmark
      counts, a heart or speech-bubble icon, a username or handle, "Repost",
      "For You", "Explore", "more", a location label, a status bar, a search
      icon, or any vertical strip of icons down one side. Any of those means
      it came off a phone screen, not out of a camera -- flag it
      `NOT-A-PHOTO:` (or `POSSIBLE-REPOST:`, per the rule above) and say
      which cue you saw.

    ## Cautions

    - Identifying people is fine -- especially a well-known/public figure you
      recognize; name them rather than writing "a speaker at the podium." For
      someone you don't actually recognize, describe them rather than
      guessing a name.
    - Dates are usually fine to state -- the filename often carries a real
      capture date/time (e.g. `2026-01-08 17.03.46.jpg`), which is a
      reliable source even when nothing in the photo itself is dated.
      Locations and organisation names are different: only state those if
      they're actually visible in the photo (a sign, a banner, a badge) --
      don't guess from context.
    - If a thumbnail is unreadable or you cannot tell what it shows, still write
      the file, with your best `title`/`alt` and an explanatory `notes`.

    ## Photos

    {$lines_joined}
    MD . "\n";
  }

  /**
   * Existing photo_categories term names, keyed by lowercase name.
   *
   * @return array<string, string>
   *   Term labels as written, keyed by their lowercased form for matching.
   */
  private function categoryNames(): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'photo_categories']);

    $names = [];
    foreach ($terms as $term) {
      $names[mb_strtolower($term->label())] = $term->label();
    }

    return $names;
  }

  /**
   * Dumps a manifest to YAML with settings that keep it hand-editable.
   */
  private function dump(array $manifest): string {
    return Yaml::dump($manifest, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  }

}
