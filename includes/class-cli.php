<?php
namespace NumosR2;

defined('ABSPATH') || exit;

class CLI {

    private R2Client $r2_client;
    private Migrator $migrator;
    private MediaHandler $media_handler;
    private LocalCleaner $local_cleaner;

    public function __construct(R2Client $r2_client, Migrator $migrator, MediaHandler $media_handler, LocalCleaner $local_cleaner) {
        $this->r2_client = $r2_client;
        $this->migrator = $migrator;
        $this->media_handler = $media_handler;
        $this->local_cleaner = $local_cleaner;
    }

    /**
     * Migrate existing media to R2.
     *
     * ## OPTIONS
     *
     * [--batch-size=<number>]
     * : Number of files per batch.
     * ---
     * default: 50
     * ---
     *
     * [--dry-run]
     * : Show what would be migrated without actually uploading.
     *
     * ## EXAMPLES
     *
     *     wp numos-r2 migrate
     *     wp numos-r2 migrate --batch-size=100
     *     wp numos-r2 migrate --dry-run
     *
     * @subcommand migrate
     */
    public function migrate($args, $assoc_args) {
        $batch_size = (int)($assoc_args['batch-size'] ?? 50);
        $dry_run = isset($assoc_args['dry-run']);

        if (!$this->r2_client->is_available()) {
            \WP_CLI::error('R2 is not available. Check your credentials and connectivity.');
        }

        $progress_data = $this->migrator->get_progress(true);
        $pending = $progress_data['pending'];

        if ($pending === 0) {
            \WP_CLI::success('All media is already synced to R2.');
            return;
        }

        \WP_CLI::log("Found {$pending} attachments to migrate.");

        if ($dry_run) {
            \WP_CLI::log('Dry run: no files will be uploaded.');
            $attachments = $this->migrator->get_pending_attachments($pending);
            foreach ($attachments as $att) {
                \WP_CLI::log("  Would migrate: #{$att->ID} - {$att->post_title}");
            }
            return;
        }

        if (!$this->migrator->start_migration()) {
            \WP_CLI::error('A migration is already in progress. Wait or clear the lock.');
        }

        $totals = $this->run_batches('Migrating media', $pending, $batch_size, [$this->migrator, 'migrate_batch'], ['success', 'failed']);
        \WP_CLI::success("Migration complete: {$totals['success']} succeeded, {$totals['failed']} failed.");
    }

    /**
     * Show current R2 offload status.
     *
     * ## EXAMPLES
     *
     *     wp numos-r2 status
     *
     * @subcommand status
     */
    public function status($args, $assoc_args) {
        $is_available = $this->r2_client->is_available();
        $progress = $this->migrator->get_progress(true);
        $savings = $this->local_cleaner->get_savings();

        $table = [];
        $table[] = ['Metric', 'Value'];
        $table[] = ['R2 Connection', $is_available ? 'Connected' : 'Disconnected'];
        $table[] = ['Total Attachments', $progress['total']];
        $table[] = ['Synced to R2', $progress['synced']];
        $table[] = ['Pending Migration', $progress['pending']];
        $table[] = ['Sync Progress', $progress['percentage'] . '%'];
        $table[] = ['R2-Only (local deleted)', $savings['r2_only_count']];
        $table[] = ['Disk Space Freed', $savings['freed_human']];
        $table[] = ['Site Prefix', numos_r2_get_site_prefix()];
        $table[] = ['Bucket', NUMOS_R2_BUCKET];

        \WP_CLI\Utils\format_items('table', array_map(function($row) {
            return ['Metric' => $row[0], 'Value' => $row[1]];
        }, array_slice($table, 1)), ['Metric', 'Value']);
    }

    /**
     * Check that every file of each synced attachment is on R2 with the recorded size and content.
     *
     * ## OPTIONS
     *
     * [--fix]
     * : Re-upload the attachments found faulty. Media without a manifest are left to sync-from-r2.
     *
     * [--limit=<number>]
     * : Maximum number of attachments to verify.
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     wp numos-r2 verify
     *     wp numos-r2 verify --fix
     *     wp numos-r2 verify --limit=100
     *
     * @subcommand verify
     */
    public function verify($args, $assoc_args) {
        $fix = isset($assoc_args['fix']);
        $limit = (int)($assoc_args['limit'] ?? 0);

        if (!$this->r2_client->is_available()) {
            \WP_CLI::error('R2 is not available.');
        }

        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_numos_r2_status' AND meta_value = %s
            ORDER BY post_id ASC" . ($limit > 0 ? " LIMIT %d" : ''),
            ...($limit > 0 ? ['synced', $limit] : ['synced'])
        );

        $ids = array_map('intval', $wpdb->get_col($query));

        if (empty($ids)) {
            \WP_CLI::success('No synced attachments to verify.');
            return;
        }

        \WP_CLI::log("Verifying " . count($ids) . " synced attachments...");
        $progress = \WP_CLI\Utils\make_progress_bar('Verifying', count($ids));

        $ok = 0;
        $issues = 0;
        $fixed = 0;

        foreach ($ids as $id) {
            $result = $this->local_cleaner->verify_attachment($id);
            $progress->tick();

            if ($result['verified']) {
                $ok++;
            } else {
                $issues++;
                foreach ($result['errors'] as $err) {
                    \WP_CLI::warning("Attachment #{$id}: {$err}");
                }

                // Without a manifest the media predates manifests: sync-from-r2 rebuilds it from
                // the existing objects, a re-upload would duplicate them under new keys.
                if ($fix && Manifest::get($id) !== null) {
                    \WP_CLI::log("  Re-uploading attachment #{$id}...");
                    $upload = $this->media_handler->upload_attachment($id);
                    if ($upload['success']) {
                        $fixed++;
                        \WP_CLI::log("  Fixed attachment #{$id}");
                    } else {
                        \WP_CLI::warning("  Failed to fix attachment #{$id}: " . ($upload['error'] ?? 'unknown'));
                    }
                }
            }
        }

        $progress->finish();

        \WP_CLI::log("Results: {$ok} OK, {$issues} issues" . ($fix ? ", {$fixed} fixed" : ''));

        if ($issues > 0 && !$fix) {
            \WP_CLI::log('Run with --fix to re-upload missing/broken files.');
        }
    }

    /**
     * Delete local files for synced attachments after R2 verification.
     *
     * ## OPTIONS
     *
     * [--batch-size=<number>]
     * : Number of attachments per batch.
     * ---
     * default: 50
     * ---
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * [--dry-run]
     * : Show what would be deleted without actually deleting.
     *
     * ## EXAMPLES
     *
     *     wp numos-r2 delete-local --dry-run
     *     wp numos-r2 delete-local --yes
     *     wp numos-r2 delete-local --batch-size=100 --yes
     *
     * @subcommand delete-local
     */
    public function delete_local($args, $assoc_args) {
        $batch_size = (int)($assoc_args['batch-size'] ?? 50);
        $dry_run = isset($assoc_args['dry-run']);

        if (!$this->r2_client->is_available()) {
            \WP_CLI::error('R2 is not available; refusing to delete local files.');
        }

        $count = $this->local_cleaner->count_synced_with_local();

        if ($count === 0) {
            \WP_CLI::success('No synced attachments with local files to delete.');
            return;
        }

        \WP_CLI::log("Found {$count} synced attachments with local files.");

        if ($dry_run) {
            \WP_CLI::log('Dry run: no files will be deleted.');
            $ids = $this->local_cleaner->get_synced_with_local($count);
            foreach ($ids as $id) {
                $files = Manifest::attachment_paths(get_attached_file($id), wp_get_attachment_metadata($id) ?: []);
                $existing = array_filter($files, 'file_exists');
                $size = array_sum(array_map('filesize', $existing));
                \WP_CLI::log("  #{$id}: " . count($existing) . " files, " . size_format($size));
            }
            return;
        }

        \WP_CLI::confirm("Delete local files for {$count} synced attachments? Each will be verified on R2 first.", $assoc_args);

        $totals = $this->run_batches('Deleting local files', $count, $batch_size, [$this->local_cleaner, 'batch_delete_local'], ['deleted', 'freed_bytes']);
        \WP_CLI::success("Deleted local files for {$totals['deleted']} attachments, freed " . size_format($totals['freed_bytes']) . ". Errors: {$totals['errors']}.");
    }

    /**
     * Restore files from R2 to local filesystem.
     *
     * ## OPTIONS
     *
     * [--attachment-id=<id>]
     * : Restore a specific attachment. If omitted, restores all R2-only attachments.
     *
     * [--batch-size=<number>]
     * : Number of attachments per batch (when restoring all).
     * ---
     * default: 50
     * ---
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp numos-r2 restore --attachment-id=123
     *     wp numos-r2 restore --yes
     *
     * @subcommand restore
     */
    public function restore($args, $assoc_args) {
        $attachment_id = (int)($assoc_args['attachment-id'] ?? 0);
        $batch_size = (int)($assoc_args['batch-size'] ?? 50);

        if (!$this->r2_client->is_available()) {
            \WP_CLI::error('R2 is not available.');
        }

        if ($attachment_id > 0) {
            \WP_CLI::log("Restoring attachment #{$attachment_id} from R2...");
            $result = $this->local_cleaner->restore_from_r2($attachment_id);

            if ($result['success']) {
                \WP_CLI::success("Restored {$result['restored_files']} files for attachment #{$attachment_id}.");
            } else {
                \WP_CLI::error("Restore failed: " . implode(', ', $result['errors']));
            }
            return;
        }

        $r2_only = $this->local_cleaner->get_r2_only_attachments(10000);
        $count = count($r2_only);

        if ($count === 0) {
            \WP_CLI::success('No R2-only attachments to restore.');
            return;
        }

        \WP_CLI::confirm("Restore {$count} attachments from R2 to local?", $assoc_args);

        $totals = $this->run_batches('Restoring from R2', $count, $batch_size, [$this->local_cleaner, 'batch_restore_from_r2'], ['restored']);
        \WP_CLI::success("Restored {$totals['restored']} attachments. Errors: {$totals['errors']}.");
    }

    /**
     * Delete all files for this site from R2 and purge metadata.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * [--force]
     * : Delete even when some media have no local copy (R2 is their only source).
     *
     * ## EXAMPLES
     *
     *     wp numos-r2 delete-r2 --yes
     *
     * @subcommand delete-r2
     */
    public function delete_r2($args, $assoc_args) {
        if (!$this->r2_client->is_available()) {
            \WP_CLI::error('R2 is not available.');
        }

        // Same guard as the admin UI: R2-only media would be lost outright.
        $savings = $this->local_cleaner->get_savings();
        if ($savings['r2_only_count'] > 0 && !isset($assoc_args['force'])) {
            \WP_CLI::error(sprintf(
                '%d attachment(s) have no local copy; R2 is their only source. Restore them first, or pass --force.',
                $savings['r2_only_count']
            ));
        }

        $site_prefix = numos_r2_get_site_prefix();
        \WP_CLI::log("Site prefix: {$site_prefix}/");

        \WP_CLI::confirm("Delete ALL files under '{$site_prefix}/' from R2 and purge all R2 metadata? This cannot be undone.", $assoc_args);

        $total_deleted = 0;
        $token = '';

        do {
            $result = $this->r2_client->list_objects($site_prefix . '/', $token, 500);

            if ($result === false) {
                \WP_CLI::error('Failed to list R2 objects.');
            }

            $keys = array_column($result['objects'], 'key');

            if (!empty($keys)) {
                $delete_result = $this->r2_client->delete_objects($keys);
                $total_deleted += $delete_result['deleted'];
                \WP_CLI::log("Deleted {$delete_result['deleted']} objects...");

                if (!empty($delete_result['errors'])) {
                    foreach ($delete_result['errors'] as $err) {
                        \WP_CLI::warning($err);
                    }
                }
            }

            $token = $result['next_token'];
        } while ($result['is_truncated']);

        $this->migrator->purge_metadata();

        \WP_CLI::success("Deleted {$total_deleted} objects from R2. All R2 metadata has been purged.");
    }

    /**
     * Rebuild the manifest of media offloaded before manifests existed, from the objects on R2.
     *
     * Only attachments that carry offload metadata and no manifest are considered. Each is
     * marked synced when all its files are found, partial when some are missing.
     *
     * ## EXAMPLES
     *
     *     wp numos-r2 sync-from-r2
     *
     * @subcommand sync-from-r2
     */
    public function sync_from_r2($args, $assoc_args) {
        if (!$this->r2_client->is_available()) {
            \WP_CLI::error('R2 is not available. Check your credentials and connectivity.');
        }

        $prefix = numos_r2_get_site_prefix();
        \WP_CLI::log("Scanning R2 under prefix '{$prefix}/'...");

        $result = $this->local_cleaner->sync_from_r2();

        if (isset($result['error'])) {
            \WP_CLI::error($result['error']);
        }

        if (isset($result['r2_files'])) {
            \WP_CLI::log("Found {$result['r2_files']} files on R2.");
        }

        \WP_CLI::log("Checked {$result['total']} attachments:");
        \WP_CLI::log("  Synced:  {$result['synced']}");
        \WP_CLI::log("  Partial: {$result['partial']}");
        \WP_CLI::log("  Skipped: {$result['skipped']}");

        if ($result['synced'] > 0) {
            \WP_CLI::success("Synced {$result['synced']} attachments from R2.");
        } else {
            \WP_CLI::warning('No new attachments to sync from R2.');
        }
    }

    /**
     * Call $batch until it reports done or processes nothing, with a progress bar and every
     * error printed. Batches start from the first remaining item, so an item that keeps
     * failing comes back each time: the iteration bound is what ends that loop.
     *
     * @param callable $batch fn(int $batch_size): array{processed:int,errors:string[],done:bool}
     * @param string[] $counters result fields to add up
     * @return array<string,int> the summed counters, plus "errors"
     */
    private function run_batches(string $label, int $count, int $batch_size, callable $batch, array $counters): array {
        $progress = \WP_CLI\Utils\make_progress_bar($label, $count);
        $totals = array_fill_keys($counters, 0) + ['errors' => 0];
        $max_iterations = (int) ceil($count / $batch_size) + 10;

        for ($iteration = 0; $iteration < $max_iterations; $iteration++) {
            $result = $batch($batch_size);
            foreach ($counters as $counter) {
                $totals[$counter] += $result[$counter];
            }
            $totals['errors'] += count($result['errors']);
            for ($i = 0; $i < $result['processed']; $i++) {
                $progress->tick();
            }
            foreach ($result['errors'] as $error) {
                \WP_CLI::warning($error);
            }
            if ($result['done'] || $result['processed'] === 0) {
                break;
            }
        }

        $progress->finish();
        return $totals;
    }

    public static function register(R2Client $r2_client, Migrator $migrator, MediaHandler $media_handler, LocalCleaner $local_cleaner): void {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        $cli = new self($r2_client, $migrator, $media_handler, $local_cleaner);

        \WP_CLI::add_command('numos-r2 migrate', [$cli, 'migrate']);
        \WP_CLI::add_command('numos-r2 status', [$cli, 'status']);
        \WP_CLI::add_command('numos-r2 verify', [$cli, 'verify']);
        \WP_CLI::add_command('numos-r2 delete-local', [$cli, 'delete_local']);
        \WP_CLI::add_command('numos-r2 restore', [$cli, 'restore']);
        \WP_CLI::add_command('numos-r2 delete-r2', [$cli, 'delete_r2']);
        \WP_CLI::add_command('numos-r2 sync-from-r2', [$cli, 'sync_from_r2']);
    }
}
