<?php

declare(strict_types=1);

namespace Drupal\apc_calendar;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Utility\Token;
use Drupal\file\FileInterface;
use Drupal\focal_point\FocalPointManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports a directory of photos into `community_photo` nodes from a manifest.
 *
 * The bulk-upload path photo-gallery-specs.md's "Initial bulk upload" row
 * describes as a Media Library upload plus a per-photo Claude-in-Chrome
 * captioning pass. That workflow still works and is still the right answer
 * for a handful of photos; this is the same job done offline for a few
 * hundred, where driving a browser per photo stops being reasonable.
 *
 * The split that matters: **this class contains no AI and does no
 * inspection.** It consumes a `manifest.yml` sitting in the same directory
 * as the photos, and every judgement call -- caption, alt text, gallery
 * placement, category -- is already written down in that file by the time
 * this runs. The manifest is authored elsewhere (a local pass over the
 * images before they are rsynced up) and, critically, reviewed by a human in
 * an editor first. So this importer is plain, deterministic, testable code,
 * and the reviewed manifest is the audit trail for what it did.
 *
 * All logic lives here rather than in the Drush command so that a Batch API
 * form can wrap the same service later without duplicating any of it -- see
 * the class docblock note in PhotoImportCommands.
 *
 * Deliberately **not** built on Feeds, even though feeds/feeds_tamper are
 * already installed for the iCal event import: event-import-task.md's
 * hardest-won lesson is that the dedupe state lives in `feeds_item` on the
 * node, so deleting a rejected import causes it to be re-imported forever.
 * A manifest whose rows gain a visible `nid:` on success puts that same
 * state somewhere a person can read and edit.
 *
 * @see \Drupal\apc_calendar\Drush\Commands\PhotoImportCommands
 * @see photo-gallery-specs.md
 */
final class PhotoBatchImporter {

  /**
   * Name of the manifest file expected inside the photo directory.
   */
  public const MANIFEST_FILENAME = 'manifest.yml';

  /**
   * Gallery placement values allowed by field_gallery_placement.
   *
   * Hardcoded rather than read from field storage because a manifest naming
   * a placement this list does not have is a manifest bug worth reporting
   * by name, not a silently dropped value.
   */
  public const PLACEMENTS = ['protest_sign', 'community_photo'];

  /**
   * Longest edge, in pixels, an imported original is allowed to keep.
   *
   * Mirrors `max_resolution: 3000x3000` on field_media_image. That setting
   * is enforced by the *widget*, so an importer writing File entities
   * directly bypasses it -- without this, imported originals would be full
   * 4032px phone photos while form-uploaded ones are capped, and the
   * gallery lightbox links the original for its full-size view.
   */
  public const MAX_DIMENSION = 3000;

  /**
   * Longest `alt` text ImageItem's schema allows, in characters.
   *
   * Fixed by \Drupal\image\Plugin\Field\FieldType\ImageItem::schema()
   * (varchar(512)) -- not a Field UI setting, so this cannot be raised by
   * reconfiguring field_media_image.
   */
  public const MAX_ALT_LENGTH = 512;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly ImageFactory $imageFactory,
    protected readonly FocalPointManagerInterface $focalPointManager,
    protected readonly MimeTypeGuesserInterface $mimeTypeGuesser,
    protected readonly Token $token,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Reads and decodes the manifest in the given directory.
   *
   * @param string $directory
   *   Absolute path to the batch directory.
   *
   * @return array
   *   Decoded manifest with `defaults` and `photos` keys guaranteed present.
   *
   * @throws \RuntimeException
   *   If the directory or manifest is missing or unreadable.
   */
  public function readManifest(string $directory): array {
    $path = $this->manifestPath($directory);
    if (!is_readable($path)) {
      throw new \RuntimeException(sprintf('No readable %s in %s.', self::MANIFEST_FILENAME, $directory));
    }

    $manifest = DrupalYaml::decode((string) file_get_contents($path));
    if (!is_array($manifest)) {
      throw new \RuntimeException(sprintf('%s did not parse as a YAML mapping.', $path));
    }

    $manifest += ['defaults' => [], 'photos' => []];
    if (!is_array($manifest['photos'])) {
      throw new \RuntimeException('The manifest\'s `photos` key must be a list.');
    }

    return $manifest;
  }

  /**
   * Writes the manifest back, preserving the caller's row order.
   *
   * Called after each successful row so an interrupted run leaves behind a
   * manifest that already records what did land -- re-running then skips
   * those rows instead of creating duplicates.
   */
  public function writeManifest(string $directory, array $manifest): void {
    $yaml = Yaml::dump($manifest, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    file_put_contents($this->manifestPath($directory), $yaml);
  }

  /**
   * Strips every row's `nid`, for reuse against a different site's database.
   *
   * `nid` means "already imported" only relative to whichever database
   * `apc:import-photos` last ran against -- see photo-batch-import-task.md's
   * "Promoting a batch to dev, then prod". A manifest reviewed and imported
   * locally is otherwise fully portable (every other field is either plain
   * content or, for `categories`, resolved fresh against whatever site it
   * lands on), so this is the one thing that needs clearing before rsyncing
   * the same batch to a second site -- local's node IDs mean nothing there,
   * and worse, could coincidentally collide with something unrelated and
   * cause a real row to be silently skipped (alreadyImported() has no
   * bundle guard). Run once, right after the first site's import, before
   * the first rsync elsewhere; nothing else in the manifest needs touching.
   *
   * @return int
   *   How many rows had a `nid` removed.
   */
  public function resetNids(string $directory): int {
    $manifest = $this->readManifest($directory);

    $cleared = 0;
    foreach ($manifest['photos'] as &$row) {
      if (is_array($row) && array_key_exists('nid', $row)) {
        unset($row['nid']);
        $cleared++;
      }
    }
    unset($row);

    if ($cleared > 0) {
      $this->writeManifest($directory, $manifest);
    }

    return $cleared;
  }

  /**
   * Validates one manifest row without touching the database.
   *
   * @return string[]
   *   Human-readable problems; empty means the row is importable.
   */
  public function validateRow(array $row, string $directory, array $defaults = []): array {
    $errors = [];
    $row = $this->applyDefaults($row, $defaults);

    $file = trim((string) ($row['file'] ?? ''));
    if ($file === '') {
      $errors[] = 'Missing `file`.';
    }
    elseif (basename($file) !== $file) {
      // A manifest is not a place to reach outside the batch directory.
      $errors[] = sprintf('`file` must be a bare filename, got "%s".', $file);
    }
    elseif (!is_readable($directory . '/' . $file)) {
      $errors[] = sprintf('File not readable: %s', $file);
    }
    else {
      $duplicate = $this->findDuplicateByChecksum((string) sha1_file($directory . '/' . $file));
      if ($duplicate !== NULL) {
        $errors[] = sprintf(
          '%s looks like an exact duplicate of an existing photo: node %d ("%s"). Remove this row, or delete that node first if this import should replace it.',
          $file,
          $duplicate->id(),
          $duplicate->label(),
        );
      }
    }

    if (trim((string) ($row['title'] ?? '')) === '') {
      $errors[] = 'Missing `title` (the node title).';
    }

    // field_media_image sets alt_field_required: true, so an empty alt would
    // fail entity validation at save time with a much less useful message.
    // An over-length alt is not flagged here (2026-09-10 decision) --
    // resolveAlt() fixes it word-safely at import time instead. See that
    // method's docblock for why blind truncation would be the wrong fix.
    if (trim((string) ($row['alt'] ?? '')) === '') {
      $errors[] = 'Missing `alt` (required by field_media_image).';
    }

    $placements = (array) ($row['placement'] ?? []);
    if ($placements === []) {
      $errors[] = 'Missing `placement` (field_gallery_placement is required).';
    }
    foreach ($placements as $placement) {
      if (!in_array($placement, self::PLACEMENTS, TRUE)) {
        $errors[] = sprintf('Unknown placement "%s" (expected one of: %s).', $placement, implode(', ', self::PLACEMENTS));
      }
    }

    // No validation on `categories`/`suggested_new_categories` names here
    // (2026-09-09 decision, reversing the original "never auto-create"
    // stance) -- importRow() creates whatever doesn't already exist rather
    // than refusing the row. See findOrCreateCategory()'s docblock.
    if (isset($row['focal_point']) && $this->parseFocalPoint((string) $row['focal_point']) === NULL) {
      $errors[] = sprintf('`focal_point` must look like "50,25", got "%s".', $row['focal_point']);
    }

    if (isset($row['created']) && strtotime((string) $row['created']) === FALSE) {
      $errors[] = sprintf('`created` is not a parseable date: "%s".', $row['created']);
    }

    return $errors;
  }

  /**
   * Imports one manifest row, creating a file, a media entity and a node.
   *
   * @param array $row
   *   One entry from the manifest's `photos` list.
   * @param string $directory
   *   Absolute path to the batch directory.
   * @param array $defaults
   *   The manifest's `defaults` mapping.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  public function importRow(array $row, string $directory, array $defaults = []): NodeInterface {
    $row = $this->applyDefaults($row, $defaults);

    // Hashed from the untouched source, before createFile()'s copy and the
    // EXIF-strip re-encode it triggers -- both change bytes even when the
    // photo is identical, so hashing after either would defeat the point.
    $checksum = sha1_file($directory . '/' . $row['file']);

    $file = $this->createFile($directory . '/' . $row['file']);
    $alt = $this->resolveAlt($row);
    $media = $this->createMedia($file, (string) $row['title'], $alt, $checksum ?: NULL);
    // $row's `caption` may have just been filled in by resolveAlt() above --
    // createNode() below must see that update, which is why $row is a plain
    // local variable threaded through this whole method rather than each
    // step taking its own copy.
    if (!empty($row['focal_point'])) {
      $this->applyFocalPoint($file, (string) $row['focal_point']);
    }

    return $this->createNode($row, $media);
  }

  /**
   * Copies the source image into Drupal's file system and saves a File entity.
   *
   * The ordering here is load-bearing and easy to get backwards. Saving the
   * File entity fires apc_calendar_file_insert(), which reads the JPEG's
   * EXIF orientation tag, physically rotates the pixels to match, and
   * re-encodes (that re-encode being what strips GPS metadata). Scaling the
   * image *before* that save would re-encode it first, dropping the
   * orientation tag along with everything else -- and the hook would then
   * find nothing to rotate, leaving every portrait phone photo sideways. So
   * the file is saved first and only scaled afterwards.
   */
  protected function createFile(string $source): FileInterface {
    $directory = 'public://' . $this->token->replace('[date:custom:Y]-[date:custom:m]');
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $destination = $this->fileSystem->copy(
      $source,
      $directory . '/' . $this->fileSystem->basename($source),
      FileExists::Rename,
    );

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $destination,
      'status' => 1,
    ]);
    // File::preSave() fills in the size but never the MIME type, and
    // apc_calendar_file_insert() branches on exactly that -- leaving it
    // unset would silently skip the EXIF/GPS strip on every import.
    $file->setMimeType((string) $this->mimeTypeGuesser->guessMimeType($destination));
    $file->save();

    $this->scaleDown($file);

    return $file;
  }

  /**
   * Caps an oversized original at MAX_DIMENSION, matching the field setting.
   *
   * Runs after the File entity is saved -- see createFile()'s docblock for
   * why that ordering is not negotiable.
   */
  protected function scaleDown(FileInterface $file): void {
    $image = $this->imageFactory->get($file->getFileUri());
    if (!$image->isValid()) {
      return;
    }

    if ($image->getWidth() <= self::MAX_DIMENSION && $image->getHeight() <= self::MAX_DIMENSION) {
      // Still refresh the recorded size: the EXIF hook re-encoded the file
      // on save without updating the File entity, so the stored filesize is
      // already stale for any JPEG that passed through it.
      $this->refreshSize($file);
      return;
    }

    $image->scale(self::MAX_DIMENSION, self::MAX_DIMENSION);
    $image->save();
    $this->refreshSize($file);
  }

  /**
   * Re-reads the file's size from disk onto the File entity.
   *
   * File::preSave() recomputes the size from disk on every save, so this is
   * just a save with the stat cache cleared first -- no explicit setSize().
   */
  protected function refreshSize(FileInterface $file): void {
    $path = $this->fileSystem->realpath($file->getFileUri());
    if ($path !== FALSE) {
      clearstatcache(TRUE, $path);
    }
    $file->save();
  }

  /**
   * Shortens an over-length `alt` word-safely, keeping the overflow.
   *
   * ValidateRow() used to refuse a row over ImageItem's hard 512-char `alt`
   * limit outright (2026-09-09 decision, before this method existed) and
   * ask for a manual manifest edit. 2026-09-10: the site owner would rather
   * this just work. A blind `substr($alt, 0, 512)` would too, but "just
   * work" for `alt` specifically means not producing a scrambled ending --
   * this is text a screen reader speaks aloud, so a value that runs to the
   * schema limit is exactly the kind of over-detailed transcription that's
   * gotten AI-authored `alt` wrong before in this batch (see
   * photo-batch-import-task.md), and cutting it off mid-word compounds
   * that rather than fixing it.
   *
   * `Unicode::truncate()` (already used for `title`, below) truncates at a
   * word boundary and accounts for the ellipsis it appends, so the result
   * never exceeds the limit. The trimmed tail isn't discarded: if `caption`
   * is empty, the *full, untruncated* alt becomes the caption -- matching
   * where this level of detail belongs anyway, per the prep brief's own
   * guidance ("quote the one or two most prominent signs... that level of
   * detail belongs in caption, not alt"). If `caption` already has
   * something, only `alt` is trimmed and the overflow is genuinely dropped
   * -- rare in practice, and logged either way so a truncation is never
   * silent.
   *
   * @param array $row
   *   Mutated in place: `caption` may be filled in from the untruncated alt.
   *
   * @return string
   *   The alt text to actually use, guaranteed to fit MAX_ALT_LENGTH.
   */
  protected function resolveAlt(array &$row): string {
    $alt = (string) ($row['alt'] ?? '');
    if (mb_strlen($alt) <= self::MAX_ALT_LENGTH) {
      return $alt;
    }

    $truncated = Unicode::truncate($alt, self::MAX_ALT_LENGTH, TRUE, TRUE);

    if (empty($row['caption'])) {
      $row['caption'] = $alt;
      $this->logger->notice('Shortened %file\'s alt text (%from to %to characters) and moved the full text to caption.', [
        '%file' => $row['file'] ?? '?',
        '%from' => mb_strlen($alt),
        '%to' => mb_strlen($truncated),
      ]);
    }
    else {
      $this->logger->notice('Shortened %file\'s alt text (%from to %to characters); caption already had content, so the overflow was dropped.', [
        '%file' => $row['file'] ?? '?',
        '%from' => mb_strlen($alt),
        '%to' => mb_strlen($truncated),
      ]);
    }

    return $truncated;
  }

  /**
   * Creates the `image` media entity wrapping the file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The already-saved File entity to wrap.
   * @param string $title
   *   The media entity's name.
   * @param string $alt
   *   Alt text for field_media_image.
   * @param string|null $checksum
   *   SHA-1 of the original source file, written to field_import_checksum so
   *   a later batch re-importing the same photo can be caught by content
   *   rather than by name -- see validateRow()'s duplicate check and that
   *   field's own description for why filename/title were rejected as the
   *   signal (both collide across genuinely different photos: sequential
   *   camera filenames like IMG_1234.jpg repeat constantly, and this
   *   importer's own AI-authored titles have already repeated verbatim
   *   across unrelated photos in the same batch).
   */
  protected function createMedia(FileInterface $file, string $title, string $alt, ?string $checksum = NULL): MediaInterface {
    $values = [
      'bundle' => 'image',
      'name' => $title,
      'status' => 1,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $alt,
      ],
    ];
    if ($checksum !== NULL) {
      $values['field_import_checksum'] = $checksum;
    }

    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->entityTypeManager->getStorage('media')->create($values);
    $media->save();

    return $media;
  }

  /**
   * Writes an explicit focal point, replacing focal_point's site-wide default.
   *
   * Without this the Crop entity focal_point_entity_update() creates on media
   * save keeps focal_point.settings' `default_value` of 50,25 -- a sane guess
   * for portrait photos, but a guess. A manifest row that names where the
   * subject actually is beats it.
   */
  protected function applyFocalPoint(FileInterface $file, string $value): void {
    $parsed = $this->parseFocalPoint($value);
    if ($parsed === NULL) {
      return;
    }

    $image = $this->imageFactory->get($file->getFileUri());
    if (!$image->isValid()) {
      return;
    }

    [$x, $y] = $parsed;
    $crop = $this->focalPointManager->getCropEntity($file, 'focal_point');
    $this->focalPointManager->saveCropEntity(
      (float) $x,
      (float) $y,
      (int) $image->getWidth(),
      (int) $image->getHeight(),
      $crop,
    );
  }

  /**
   * Creates the community_photo node.
   */
  protected function createNode(array $row, MediaInterface $media): NodeInterface {
    $values = [
      'type' => 'community_photo',
      'title' => Unicode::truncate((string) $row['title'], 255, TRUE, TRUE),
      'status' => !empty($row['published']),
      'field_photo_image' => ['target_id' => $media->id()],
      'field_gallery_placement' => array_map(
        static fn (string $value): array => ['value' => $value],
        array_values((array) $row['placement']),
      ),
    ];

    if (!empty($row['caption'])) {
      $values['field_caption'] = (string) $row['caption'];
    }
    if (!empty($row['submitter_name'])) {
      $values['field_submitter_name'] = (string) $row['submitter_name'];
    }
    if (isset($row['credit_publicly'])) {
      $values['field_credit_publicly'] = (bool) $row['credit_publicly'];
    }
    if (!empty($row['created'])) {
      // Photos in a batch are usually months old; the node's created date is
      // what the galleries sort on, so "when it was shot" beats "when it was
      // imported". EXIF is the source upstream, but only the timestamp -- the
      // GPS tag alongside it is deliberately never carried into the manifest.
      $values['created'] = (int) strtotime((string) $row['created']);
    }

    // Both keys are resolved here, not just `categories` -- by the time a
    // row reaches import, `categories` only ever holds names sanitize()
    // already matched at merge time; anything not yet in the vocabulary
    // sits in `suggested_new_categories` instead. Auto-creating both here
    // (2026-09-09 decision) is what actually makes that suggestion useful
    // rather than a dead end -- previously it just sat there until someone
    // ran a separate script to add the term by hand.
    $names = array_merge(
      (array) ($row['categories'] ?? []),
      (array) ($row['suggested_new_categories'] ?? []),
    );
    $terms = [];
    foreach ($names as $name) {
      $tid = $this->findOrCreateCategory((string) $name);
      if ($tid !== NULL) {
        $terms[] = ['target_id' => $tid];
      }
    }
    if ($terms !== []) {
      $values['field_photo_tags'] = $terms;
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->create($values);
    $node->save();

    $this->logger->info('Imported photo %file as node %nid.', [
      '%file' => $row['file'],
      '%nid' => $node->id(),
    ]);

    return $node;
  }

  /**
   * Finds the community_photo node already using a photo with this checksum.
   *
   * Content-based, not name-based, deliberately: this batch's own data shows
   * why a filename or title check would be unsafe here. Sequential camera
   * filenames like `IMG_1234.jpg` are near-certain to recur across
   * completely unrelated real photos, and this importer's own AI-authored
   * titles have already repeated verbatim ("Signs of fascism ...") across
   * different photos in the same 28-image batch. Either would produce false
   * "already imported" positives that silently drop a genuinely new photo.
   * A byte-identical SHA-1 match has no such false-positive risk, and --
   * unlike the `nid` written back into the manifest -- it is checked against
   * whichever site's database is actually running, so it catches a
   * re-import regardless of which manifest or environment it came from.
   *
   * Its blind spot is the opposite kind: two different edits or re-exports
   * of the same original photo hash differently and will not be caught.
   */
  protected function findDuplicateByChecksum(string $checksum): ?NodeInterface {
    if ($checksum === '') {
      return NULL;
    }

    $media = $this->entityTypeManager->getStorage('media')
      ->loadByProperties(['bundle' => 'image', 'field_import_checksum' => $checksum]);
    if ($media === []) {
      return NULL;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'community_photo',
      'field_photo_image' => array_key_first($media),
    ]);

    return $nodes === [] ? NULL : reset($nodes);
  }

  /**
   * Finds an existing photo_categories term by name, case-insensitively.
   *
   * Unpublished terms match too -- photo-gallery-specs.md's term moderation
   * queue means a legitimate category can be sitting unapproved, and failing
   * an import row over that would be surprising.
   *
   * @return int|null
   *   The term ID, or NULL when no term of that name exists.
   */
  protected function findCategory(string $name): ?int {
    $name = trim($name);
    if ($name === '') {
      return NULL;
    }

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'photo_categories']);

    foreach ($terms as $term) {
      if (mb_strtolower($term->label()) === mb_strtolower($name)) {
        return (int) $term->id();
      }
    }

    return NULL;
  }

  /**
   * Finds a photo_categories term by name, creating/publishing one if needed.
   *
   * Reverses this importer's original stance -- 2026-09-09 decision: the
   * site owner would rather have a broader, occasionally-imperfect
   * vocabulary than a manual "create the term first" step before every
   * batch that introduces a new theme. The fragmentation risk that stance
   * used to guard against (`field_photo_tags`'s `auto_create: true` turning
   * a near-duplicate name into a permanent new term) is still real -- it is
   * just no longer this importer's problem to prevent. `findCategory()`'s
   * case-insensitive match still runs first, so re-importing "fascism"
   * after "Fascism" already exists reuses it rather than creating a
   * duplicate; only a name that doesn't match anything at all gets a new
   * term.
   *
   * Two saves, not one: apc_calendar_taxonomy_term_presave() force-unpublishes
   * a *new* photo_categories term unless the acting user has the `bypass
   * photo category approval` permission -- correct for an anonymous
   * submitter typing a category into a web form, but this importer's own
   * Drush/CLI user doesn't have it either, and there is no submitter here to
   * gate. Explicitly publishing on a second save is simpler than trying to
   * make the presave hook recognise this caller as trusted.
   */
  protected function findOrCreateCategory(string $name): ?int {
    $name = trim($name);
    if ($name === '') {
      return NULL;
    }

    $existing = $this->findCategory($name);
    if ($existing !== NULL) {
      return $existing;
    }

    /** @var \Drupal\taxonomy\TermInterface $term */
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
      'vid' => 'photo_categories',
      'name' => $name,
      'status' => 1,
    ]);
    $term->save();
    if (!$term->isPublished()) {
      $term->setPublished()->save();
    }

    $this->logger->notice('Created new photo_categories term "%name" (tid %tid).', [
      '%name' => $term->label(),
      '%tid' => $term->id(),
    ]);

    return (int) $term->id();
  }

  /**
   * Parses an "x,y" focal point into a pair of percentages.
   *
   * @return array{int, int}|null
   *   NULL when the value is not two integers in 0-100.
   */
  protected function parseFocalPoint(string $value): ?array {
    if (!preg_match('/^\s*(\d{1,3})\s*,\s*(\d{1,3})\s*$/', $value, $matches)) {
      return NULL;
    }

    $x = (int) $matches[1];
    $y = (int) $matches[2];
    if ($x > 100 || $y > 100) {
      return NULL;
    }

    return [$x, $y];
  }

  /**
   * Merges the manifest's `defaults` under one row's own values.
   */
  protected function applyDefaults(array $row, array $defaults): array {
    foreach ($defaults as $key => $value) {
      if (!array_key_exists($key, $row)) {
        $row[$key] = $value;
      }
    }

    return $row;
  }

  /**
   * Absolute path to a directory's manifest.
   */
  protected function manifestPath(string $directory): string {
    return rtrim($directory, '/') . '/' . self::MANIFEST_FILENAME;
  }

}
