<?php

declare(strict_types=1);

namespace Drupal\apc_calendar\Drush\Commands;

use Drupal\apc_calendar\PhotoBatchImporter;
use Drupal\apc_calendar\PhotoBatchPrep;
use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Imports a manifest-described directory of photos into community_photo nodes.
 *
 * A deliberately thin wrapper: argument parsing, chunking, and terminal
 * output only. Everything that decides what a photo becomes lives in
 * PhotoBatchImporter, so that a Batch API form -- the obvious next step the
 * day someone without shell access needs to run an import -- can wrap the
 * same service without a line of this being copied.
 *
 * Drush rather than a UI form for the first cut because the files arrive over
 * SSH anyway (rsync into the batch directory, then run this in the same
 * shell), and because a CLI loop over a few hundred photos does not have to
 * fight GreenGeeks' web request timeout or memory_limit. The "review before
 * committing" advantage a UI batch would otherwise have is already spent: the
 * manifest was reviewed in an editor before it was ever uploaded.
 *
 * @see \Drupal\apc_calendar\PhotoBatchImporter
 */
final class PhotoImportCommands extends DrushCommands {

  /**
   * Rows to process between entity storage cache resets.
   */
  private const CHUNK_SIZE = 25;

  public function __construct(
    private readonly PhotoBatchImporter $importer,
    private readonly PhotoBatchPrep $prep,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('apc_calendar.photo_batch_importer'),
      $container->get('apc_calendar.photo_batch_prep'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Imports photos described by a manifest.yml into community_photo nodes.
   */
  #[CLI\Command(name: 'apc:import-photos', aliases: ['apc-ip'])]
  #[CLI\Argument(name: 'directory', description: 'Directory holding the photos and their manifest.yml.')]
  #[CLI\Option(name: 'dry-run', description: 'Validate the manifest and report what would happen, without writing anything.')]
  #[CLI\Option(name: 'skip-invalid', description: 'Import the valid rows instead of refusing to start when any row has errors.')]
  #[CLI\Option(name: 'publish', description: 'Publish imported nodes, overriding the manifest. Off by default -- community_photo defaults to unpublished so the moderation queue stays the gate.')]
  #[CLI\Option(name: 'limit', description: 'Stop after this many rows. Useful for a first pass over a large batch.')]
  #[CLI\Option(name: 'cleanup', description: 'Delete each source file once its row has imported, and -- once every row in the manifest has a nid and nothing failed -- manifest.yml and the batch directory itself.')]
  #[CLI\Usage(name: 'drush apc:import-photos ~/photo-import/2026-09 --dry-run', description: 'Check a manifest without importing.')]
  #[CLI\Usage(name: 'drush @apc.dev apc:import-photos ~/photo-import/2026-09 --limit=5', description: 'Import the first five rows on the dev site.')]
  public function importPhotos(
    string $directory,
    array $options = [
      'dry-run' => FALSE,
      'skip-invalid' => FALSE,
      'publish' => FALSE,
      'limit' => NULL,
      'cleanup' => FALSE,
    ],
  ): int {
    $directory = rtrim($this->resolveDirectory($directory), '/');
    if (!is_dir($directory)) {
      $this->logger()->error(dt('Not a directory: @dir', ['@dir' => $directory]));
      return self::EXIT_FAILURE;
    }

    try {
      $manifest = $this->importer->readManifest($directory);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    $defaults = (array) $manifest['defaults'];
    $rows = $manifest['photos'];

    // Validate everything before importing anything. A manifest with a typo
    // in row 40 is better caught now than after 39 nodes exist.
    $invalid = [];
    foreach ($rows as $index => $row) {
      if ($this->alreadyImported($row)) {
        continue;
      }
      $errors = $this->importer->validateRow((array) $row, $directory, $defaults);
      if ($errors !== []) {
        $invalid[$index] = $errors;
      }
    }

    foreach ($invalid as $index => $errors) {
      $file = $rows[$index]['file'] ?? sprintf('row %d', $index + 1);
      foreach ($errors as $error) {
        $this->logger()->warning(dt('@file: @error', ['@file' => $file, '@error' => $error]));
      }
    }

    if ($invalid !== [] && !$options['skip-invalid'] && !$options['dry-run']) {
      $this->logger()->error(dt('@count row(s) have problems. Fix the manifest, or re-run with --skip-invalid.', [
        '@count' => count($invalid),
      ]));
      return self::EXIT_FAILURE;
    }

    if ($options['dry-run']) {
      $importable = 0;
      $skipped = 0;
      foreach ($rows as $index => $row) {
        if ($this->alreadyImported($row)) {
          $skipped++;
        }
        elseif (!isset($invalid[$index])) {
          $importable++;
        }
      }
      $this->logger()->notice(dt('Dry run: @ok importable, @bad with errors, @skip already imported.', [
        '@ok' => $importable,
        '@bad' => count($invalid),
        '@skip' => $skipped,
      ]));
      return self::EXIT_SUCCESS;
    }

    $limit = $options['limit'] !== NULL ? (int) $options['limit'] : NULL;
    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $processed = 0;

    foreach ($rows as $index => $row) {
      if ($limit !== NULL && $imported >= $limit) {
        break;
      }
      if ($this->alreadyImported($row)) {
        $skipped++;
        continue;
      }
      if (isset($invalid[$index])) {
        $failed++;
        continue;
      }

      $row = (array) $row;
      if ($options['publish']) {
        $row['published'] = TRUE;
      }

      try {
        $node = $this->importer->importRow($row, $directory, $defaults);
      }
      catch (\Throwable $e) {
        $failed++;
        $this->logger()->error(dt('@file failed: @message', [
          '@file' => $row['file'] ?? '?',
          '@message' => $e->getMessage(),
        ]));
        continue;
      }

      // Record the nid immediately, and rewrite the manifest each time: an
      // interrupted run then leaves a manifest that already knows what
      // landed, so re-running skips those rows rather than duplicating them.
      $manifest['photos'][$index]['nid'] = (int) $node->id();
      $this->importer->writeManifest($directory, $manifest);

      if ($options['cleanup'] && !@unlink($directory . '/' . $row['file'])) {
        // Worth saying out loud rather than swallowing: the node imported
        // fine, so the run is not a failure, but the staged copy is still
        // sitting there and whoever asked for --cleanup should know.
        $this->logger()->warning(dt('Imported @file but could not delete the staged copy.', [
          '@file' => $row['file'],
        ]));
      }

      $imported++;
      $this->logger()->success(dt('@file -> node @nid (@title)', [
        '@file' => $row['file'],
        '@nid' => $node->id(),
        '@title' => $node->label(),
      ]));

      if (++$processed % self::CHUNK_SIZE === 0) {
        $this->resetCaches();
      }
    }

    $this->logger()->notice(dt('Imported @ok, skipped @skip already-imported, @bad failed.', [
      '@ok' => $imported,
      '@skip' => $skipped,
      '@bad' => $failed,
    ]));

    if ($options['cleanup'] && $failed === 0 && $options['limit'] === NULL) {
      $this->finishCleanup($directory, $manifest);
    }

    return $failed > 0 ? self::EXIT_FAILURE_WITH_CLARITY : self::EXIT_SUCCESS;
  }

  /**
   * Removes `manifest.yml` and the batch directory once nothing is left.
   *
   * `--cleanup` already deletes each original photo the moment its row
   * imports, but that leaves `manifest.yml` itself, and the now-empty
   * directory, sitting on the remote host forever -- exactly the mess a
   * staging directory shouldn't leave behind. Only fires when every row in
   * the manifest has a `nid` (the whole batch is done, not just this run's
   * slice of it -- a `--limit` run is excluded by the caller for the same
   * reason). If the directory isn't otherwise empty -- `.prep/` got rsynced
   * here despite the docs' exclude, say -- this reports that and leaves
   * everything in place rather than guessing what's safe to remove.
   */
  private function finishCleanup(string $directory, array $manifest): void {
    foreach ($manifest['photos'] as $row) {
      if (empty($row['nid'])) {
        return;
      }
    }

    $entries = array_diff((array) scandir($directory), ['.', '..', PhotoBatchImporter::MANIFEST_FILENAME]);
    if ($entries !== []) {
      $this->logger()->notice(dt('Every photo is imported, but @dir still has other files in it -- left in place: @entries', [
        '@dir' => $directory,
        '@entries' => implode(', ', $entries),
      ]));
      return;
    }

    @unlink($directory . '/' . PhotoBatchImporter::MANIFEST_FILENAME);
    if (@rmdir($directory)) {
      $this->logger()->success(dt('Every photo is imported -- removed @dir.', ['@dir' => $directory]));
    }
    else {
      $this->logger()->warning(dt('Every photo is imported, but could not remove @dir.', ['@dir' => $directory]));
    }
  }

  /**
   * Thumbnails a photo directory and writes the brief for describing it.
   */
  #[CLI\Command(name: 'apc:prep-photo-batch', aliases: ['apc-ppb'])]
  #[CLI\Argument(name: 'directory', description: 'Directory holding the photos to prepare.')]
  #[CLI\Usage(name: 'drush apc:prep-photo-batch photo-batches/2026-09', description: 'Build thumbnails, a task brief and a skeleton manifest.')]
  public function prepPhotoBatch(string $directory): int {
    $directory = rtrim($this->resolveDirectory($directory), '/');

    try {
      $result = $this->prep->prep($directory);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    $summary = $result['summary'];
    $rotated = count(array_filter(
      $summary,
      static fn (array $item): bool => $item['orientation'] !== NULL && $item['orientation'] > 1,
    ));
    $dated = count(array_filter(
      $summary,
      static fn (array $item): bool => $item['taken'] !== NULL,
    ));

    $this->logger()->success(dt('Prepared @count photo(s): @rotated needed rotating, @dated carried a date.', [
      '@count' => count($summary),
      '@rotated' => $rotated,
      '@dated' => $dated,
    ]));

    if ($result['new_rows'] < count($summary)) {
      // Fewer new rows than photos on disk means this ran against a batch
      // that already had a manifest -- worth saying so explicitly, since
      // "Prepared 44" alone reads as if all 44 are new when most already
      // went through review.
      $this->logger()->notice(dt('@new of those are new since the last run -- the rest already had a manifest row and were left untouched.', [
        '@new' => $result['new_rows'],
      ]));
    }
    $this->logger()->notice(dt('Brief: @path', [
      '@path' => $directory . '/' . PhotoBatchPrep::PREP_DIR . '/TASK.md',
    ]));

    return self::EXIT_SUCCESS;
  }

  /**
   * Merges written descriptions from .prep/rows into the manifest.
   */
  #[CLI\Command(name: 'apc:merge-photo-batch', aliases: ['apc-mpb'])]
  #[CLI\Argument(name: 'directory', description: 'Directory holding the photos and their .prep working files.')]
  #[CLI\Usage(name: 'drush apc:merge-photo-batch photo-batches/2026-09', description: 'Fold .prep/rows/*.json into manifest.yml.')]
  public function mergePhotoBatch(string $directory): int {
    $directory = rtrim($this->resolveDirectory($directory), '/');

    try {
      $result = $this->prep->merge($directory);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    foreach ($result['problems'] as $problem) {
      $this->logger()->warning($problem);
    }

    $this->logger()->success(dt('Merged @merged row(s); @skipped photo(s) had no description yet.', [
      '@merged' => $result['merged'],
      '@skipped' => $result['skipped'],
    ]));

    if ($result['suggested_categories'] !== []) {
      $this->logger()->notice(dt('Not yet in photo_categories -- apc:import-photos will create these automatically unless you remove them from the manifest first:'));
      foreach ($result['suggested_categories'] as $name => $count) {
        $this->logger()->notice(dt('  @count row(s): @name', ['@count' => $count, '@name' => $name]));
      }
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Clears every row's `nid`, so the manifest can go to a different site.
   *
   * Run once, on the batch's local copy, after importing to the first site
   * and before rsyncing it anywhere else -- see photo-batch-import-task.md's
   * "Promoting a batch to dev, then prod".
   */
  #[CLI\Command(name: 'apc:reset-photo-batch-nids', aliases: ['apc-rpn'])]
  #[CLI\Argument(name: 'directory', description: 'Directory holding the manifest.yml to reset.')]
  #[CLI\Usage(name: 'drush apc:reset-photo-batch-nids photo-batches/2026-09', description: 'Clear nid tracking before pushing an already-imported batch to another site.')]
  public function resetPhotoBatchNids(string $directory): int {
    $directory = rtrim($this->resolveDirectory($directory), '/');

    try {
      $cleared = $this->importer->resetNids($directory);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->logger()->success(dt('Cleared @count nid(s). Safe to rsync this batch to another site now.', [
      '@count' => $cleared,
    ]));

    return self::EXIT_SUCCESS;
  }

  /**
   * Builds a static HTML page: each thumbnail next to its manifest text.
   *
   * Read-only -- edit manifest.yml itself, then re-run this to see the
   * update reflected.
   */
  #[CLI\Command(name: 'apc:review-photo-batch', aliases: ['apc-review'])]
  #[CLI\Argument(name: 'directory', description: 'Directory holding manifest.yml and its .prep/thumbs.')]
  #[CLI\Usage(name: 'drush apc:review-photo-batch photo-batches/2026-09', description: 'Write .prep/review.html, then open it in a browser.')]
  public function reviewPhotoBatch(string $directory): int {
    $directory = rtrim($this->resolveDirectory($directory), '/');

    try {
      $flagged = $this->prep->buildReviewPage($directory);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->logger()->success(dt('Wrote @path (@flagged flagged).', [
      '@path' => $directory . '/' . PhotoBatchPrep::PREP_DIR . '/review.html',
      '@flagged' => $flagged,
    ]));
    $this->logger()->notice(dt('Open it from a Mac terminal (not ddev exec/ssh) -- ddev bind-mounts the project root, so this is the same file: open the path above, replacing /var/www/html with your project root.'));

    return self::EXIT_SUCCESS;
  }

  /**
   * Whether a row already points at a node that still exists.
   *
   * A row keeps its `nid` even if the node is later deleted -- in that case
   * this returns FALSE and the row imports again, which is the behaviour we
   * want here and deliberately the opposite of the Feeds import path, where
   * a deleted node comes back forever (see event-import-task.md). Here the
   * manifest is the state, and deleting a node you did not want is a normal
   * thing to do; clearing the `nid` line is how you say "and don't bring it
   * back".
   */
  private function alreadyImported(mixed $row): bool {
    if (!is_array($row) || empty($row['nid'])) {
      return FALSE;
    }

    return $this->entityTypeManager->getStorage('node')->load((int) $row['nid']) !== NULL;
  }

  /**
   * Releases the entity memory a long import otherwise accumulates.
   */
  private function resetCaches(): void {
    foreach (['node', 'media', 'file', 'taxonomy_term'] as $entity_type) {
      $this->entityTypeManager->getStorage($entity_type)->resetCache();
    }
    gc_collect_cycles();
  }

  /**
   * Expands a leading `~` and makes a relative path absolute.
   *
   * Relative paths resolve against the directory drush was *launched* from,
   * not getcwd() -- by the time a command runs, PHP's working directory is
   * the Drupal root (`web/`), so `photo-batches/2026-09` typed at the project
   * root would otherwise resolve to `web/photo-batches/2026-09` and quietly
   * miss the photos.
   */
  private function resolveDirectory(string $directory): string {
    if (str_starts_with($directory, '~/')) {
      $home = $this->getConfig()->home() ?: getenv('HOME');
      if (is_string($home) && $home !== '') {
        $directory = $home . substr($directory, 1);
      }
    }

    if (!str_starts_with($directory, '/')) {
      $base = $this->getConfig()->cwd() ?: getcwd();
      $directory = rtrim((string) $base, '/') . '/' . $directory;
    }

    return $directory;
  }

}
