<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * WP-CLI Commands for Numos R2 Media Offload
 */
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
            \WP_CLI::log('Dry run — no files will be uploaded.');
            $attachments = $this->migrator->get_pending_attachments($pending);
            foreach ($attachments as $att) {
                \WP_CLI::log("  Would migrate: #{$att->ID} — {$att->post_title}");
            }
            return;
        }

        if (!$this->migrator->start_migration()) {
            \WP_CLI::error('A migration is already in progress. Wait or clear the lock.');
        }

        $progress = \WP_CLI\Utils\make_progress_bar('Migrating media', $pending);
        $total_success = 0;
        $total_failed = 0;
        $max_iterations = (int)ceil($pending / $batch_size) + 10;

        for ($iteration = 0; $iteration < $max_iterations; $iteration++) {
            $result = $this->migrator->migrate_batch($batch_size);

            $total_success += $result['success'];
            $total_failed += $result['failed'];

            for ($i = 0; $i < $result['processed']; $i++) {
                $progress->tick();
            }

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    \WP_CLI::warning($error);
                }
            }

            if ($result['done'] || $result['processed'] === 0) {
                break;
            }
        }

        $progress->finish();
        \WP_CLI::success("Migration complete: {$total_success} succeeded, {$total_failed} failed.");
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
     * Verify synced files exist on R2 with correct sizes.
     *
     * ## OPTIONS
     *
     * [--fix]
     * : Re-upload files that are missing or have wrong size on R2.
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

                if ($fix) {
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
            \WP_CLI::error('R2 is not available — refusing to delete local files.');
        }

        $count = $this->local_cleaner->count_synced_with_local();

        if ($count === 0) {
            \WP_CLI::success('No synced attachments with local files to delete.');
            return;
        }

        \WP_CLI::log("Found {$count} synced attachments with local files.");

        if ($dry_run) {
            \WP_CLI::log('Dry run — no files will be deleted.');
            $ids = $this->local_cleaner->get_synced_with_local($count);
            foreach ($ids as $id) {
                $files = $this->local_cleaner->get_attachment_files($id);
                $existing = array_filter($files, 'file_exists');
                $size = array_sum(array_map('filesize', $existing));
                \WP_CLI::log("  #{$id}: " . count($existing) . " files, " . size_format($size));
            }
            return;
        }

        \WP_CLI::confirm("Delete local files for {$count} synced attachments? Each will be verified on R2 first.", $assoc_args);

        $progress = \WP_CLI\Utils\make_progress_bar('Deleting local files', $count);
        $total_deleted = 0;
        $total_freed = 0;
        $total_errors = 0;
        $max_iterations = (int)ceil($count / $batch_size) + 10;

        for ($iteration = 0; $iteration < $max_iterations; $iteration++) {
            $result = $this->local_cleaner->batch_delete_local($batch_size);

            $total_deleted += $result['deleted'];
            $total_freed += $result['freed_bytes'];
            $total_errors += count($result['errors']);

            for ($i = 0; $i < $result['processed']; $i++) {
                $progress->tick();
            }

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $err) {
                    \WP_CLI::warning($err);
                }
            }

            if ($result['done'] || $result['processed'] === 0) {
                break;
            }
        }

        $progress->finish();
        \WP_CLI::success("Deleted local files for {$total_deleted} attachments, freed " . size_format($total_freed) . ". Errors: {$total_errors}.");
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

        // Single attachment restore
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

        // Batch restore all R2-only
        $r2_only = $this->local_cleaner->get_r2_only_attachments(10000);
        $count = count($r2_only);

        if ($count === 0) {
            \WP_CLI::success('No R2-only attachments to restore.');
            return;
        }

        \WP_CLI::confirm("Restore {$count} attachments from R2 to local?", $assoc_args);

        $progress = \WP_CLI\Utils\make_progress_bar('Restoring from R2', $count);
        $total_restored = 0;
        $total_errors = 0;
        $max_iterations = (int)ceil($count / $batch_size) + 10;

        for ($iteration = 0; $iteration < $max_iterations; $iteration++) {
            $result = $this->local_cleaner->batch_restore_from_r2($batch_size);

            $total_restored += $result['restored'];
            $total_errors += count($result['errors']);

            for ($i = 0; $i < $result['processed']; $i++) {
                $progress->tick();
            }

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $err) {
                    \WP_CLI::warning($err);
                }
            }

            if ($result['done'] || $result['processed'] === 0) {
                break;
            }
        }

        $progress->finish();
        \WP_CLI::success("Restored {$total_restored} attachments. Errors: {$total_errors}.");
    }

    /**
     * Delete all files for this site from R2 and purge metadata.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
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

        // Purge metadata
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('_numos_r2_') . '%'
        ));

        delete_option('numos_r2_migration_checkpoint');
        delete_option('numos_r2_migration_stats');
        delete_transient('numos_r2_health_status');
        delete_transient('numos_r2_stats_cache');
        delete_transient('numos_r2_migration_lock');
        delete_transient('numos_r2_fully_synced');
        delete_transient('numos_r2_progress_cache');
        delete_transient('numos_r2_synced_paths');

        \WP_CLI::success("Deleted {$total_deleted} objects from R2. All R2 metadata has been purged.");
    }

    /**
     * Sync from R2: reconcile database with R2 bucket contents.
     *
     * Scans R2 and marks attachments as synced if their files are found.
     * Useful after a database restore when R2 files exist but the database
     * has no R2 metadata.
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
     * Register WP-CLI commands
     */
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
