<?php
namespace NumosR2;

defined('ABSPATH') || exit;

/**
 * Admin - Dashboard and settings UI with tabbed interface
 */
class Admin {

    private R2Client $r2_client;
    private Migrator $migrator;
    private LocalCleaner $local_cleaner;

    public function __construct(R2Client $r2_client, Migrator $migrator, LocalCleaner $local_cleaner) {
        $this->r2_client = $r2_client;
        $this->migrator = $migrator;
        $this->local_cleaner = $local_cleaner;

        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Media Library hooks
        add_filter('manage_media_columns', [$this, 'add_media_column']);
        add_action('manage_media_custom_column', [$this, 'render_media_column'], 10, 2);

        // AJAX handlers — existing
        add_action('wp_ajax_numos_r2_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_numos_r2_migrate_batch', [$this, 'ajax_migrate_batch']);
        add_action('wp_ajax_numos_r2_get_progress', [$this, 'ajax_get_progress']);
        add_action('wp_ajax_numos_r2_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_numos_r2_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_numos_r2_reset_failed', [$this, 'ajax_reset_failed']);
        add_action('wp_ajax_numos_r2_purge_meta', [$this, 'ajax_purge_meta']);
        add_action('wp_ajax_numos_r2_list_orphans', [$this, 'ajax_list_orphans']);
        add_action('wp_ajax_numos_r2_delete_orphans', [$this, 'ajax_delete_orphans']);
        add_action('wp_ajax_numos_r2_scan_db_orphans', [$this, 'ajax_scan_db_orphans']);
        add_action('wp_ajax_numos_r2_delete_db_orphans', [$this, 'ajax_delete_db_orphans']);

        // AJAX handlers — new
        add_action('wp_ajax_numos_r2_delete_local_batch', [$this, 'ajax_delete_local_batch']);
        add_action('wp_ajax_numos_r2_delete_all_local', [$this, 'ajax_delete_all_local']);
        add_action('wp_ajax_numos_r2_verify_batch', [$this, 'ajax_verify_batch']);
        add_action('wp_ajax_numos_r2_verify_all', [$this, 'ajax_verify_all']);
        add_action('wp_ajax_numos_r2_restore_batch', [$this, 'ajax_restore_batch']);
        add_action('wp_ajax_numos_r2_restore_all', [$this, 'ajax_restore_all']);
        add_action('wp_ajax_numos_r2_get_savings', [$this, 'ajax_get_savings']);
        add_action('wp_ajax_numos_r2_delete_from_r2_batch', [$this, 'ajax_delete_from_r2_batch']);
        add_action('wp_ajax_numos_r2_sync_from_r2', [$this, 'ajax_sync_from_r2']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_media_page(
            __('R2 Media Offload', 'numos-r2'),
            __('R2 Offload', 'numos-r2'),
            'manage_options',
            'numos-r2-offload',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets(string $hook): void {
        // Media Library page — grid view badge JS
        if ($hook === 'upload.php') {
            wp_enqueue_script(
                'numos-r2-media-library',
                NUMOS_R2_PLUGIN_URL . 'assets/media-library.js',
                ['jquery', 'media-grid'],
                NUMOS_R2_VERSION,
                true
            );
            wp_enqueue_style(
                'numos-r2-admin',
                NUMOS_R2_PLUGIN_URL . 'assets/admin.css',
                [],
                NUMOS_R2_VERSION
            );

            // Pass R2 status data for grid badges (capped at 500 for performance)
            global $wpdb;
            $r2_statuses = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id,
                    MAX(CASE WHEN meta_key = %s THEN meta_value END) as r2_status,
                    MAX(CASE WHEN meta_key = %s THEN meta_value END) as local_deleted
                FROM {$wpdb->postmeta}
                WHERE meta_key IN (%s, %s)
                GROUP BY post_id
                ORDER BY post_id DESC
                LIMIT 500",
                '_numos_r2_status',
                '_numos_r2_local_deleted',
                '_numos_r2_status',
                '_numos_r2_local_deleted'
            ), ARRAY_A);

            $status_map = [];
            foreach ($r2_statuses as $row) {
                $status_map[$row['post_id']] = [
                    's' => $row['r2_status'] ?: '',
                    'd' => $row['local_deleted'] ? '1' : '',
                ];
            }

            wp_localize_script('numos-r2-media-library', 'numosR2Grid', [
                'statuses' => $status_map,
            ]);
            return;
        }

        if ($hook !== 'media_page_numos-r2-offload') {
            return;
        }

        wp_enqueue_style(
            'numos-r2-admin',
            NUMOS_R2_PLUGIN_URL . 'assets/admin.css',
            [],
            NUMOS_R2_VERSION
        );

        wp_enqueue_script(
            'numos-r2-admin',
            NUMOS_R2_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            NUMOS_R2_VERSION,
            true
        );

        wp_localize_script('numos-r2-admin', 'numosR2', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('numos_r2_nonce'),
            'strings' => [
                'migrating' => __('Migrating...', 'numos-r2'),
                'complete' => __('Migration complete!', 'numos-r2'),
                'error' => __('An error occurred', 'numos-r2'),
                'confirmMigration' => __('Start migration? This will upload all media to R2.', 'numos-r2'),
                'confirmPurge' => __('Are you sure? This will remove all R2 metadata from the database. Files on R2 will NOT be deleted. You will need to re-migrate if you want to use R2 again.', 'numos-r2'),
                'purging' => __('Purging...', 'numos-r2'),
                'purgeComplete' => __('Purge complete!', 'numos-r2'),
                'testing' => __('Testing connection...', 'numos-r2'),
                'connectionOk' => __('Connection successful!', 'numos-r2'),
                'connectionFailed' => __('Connection failed', 'numos-r2'),
                'scanning' => __('Scanning R2 bucket...', 'numos-r2'),
                'confirmDeleteOrphans' => __('Delete these orphan files from R2? This cannot be undone.', 'numos-r2'),
                'deleting' => __('Deleting...', 'numos-r2'),
                'noOrphans' => __('No orphan files found.', 'numos-r2'),
                'scanningDb' => __('Scanning database...', 'numos-r2'),
                'noDbOrphans' => __('No orphan database entries found. All attachments have physical files.', 'numos-r2'),
                'dbOrphansFound' => __('orphan database entries found (attachments without files)', 'numos-r2'),
                'confirmDeleteDbOrphans' => __('Delete these orphan database entries? This will remove the attachment records from WordPress. This cannot be undone.', 'numos-r2'),
                'selectEntries' => __('Please select entries to delete', 'numos-r2'),
                'selectAll' => __('Select all', 'numos-r2'),
                'title' => __('Title', 'numos-r2'),
                'type' => __('Type', 'numos-r2'),
                // New strings
                'confirmDeleteLocal' => __('Delete local files for verified synced attachments? Each file will be checked on R2 before deletion.', 'numos-r2'),
                'deletingLocal' => __('Deleting local files...', 'numos-r2'),
                'confirmRestore' => __('Restore files from R2 to local filesystem?', 'numos-r2'),
                'restoring' => __('Restoring from R2...', 'numos-r2'),
                'verifying' => __('Verifying R2 files...', 'numos-r2'),
                'confirmDeleteFromR2' => __('WARNING: This will permanently delete ALL files for this site from R2. Files cannot be recovered. Are you sure?', 'numos-r2'),
                'deletingFromR2' => __('Deleting from R2...', 'numos-r2'),
                'confirmSyncFromR2' => __('Scan R2 and reconcile database? This will mark attachments as synced if their files are found on R2.', 'numos-r2'),
                'syncingFromR2' => __('Scanning R2...', 'numos-r2'),
            ],
        ]);
    }

    /**
     * Render admin page with tabs
     */
    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'numos-r2'));
        }

        $settings = get_option('numos_r2_settings', [
            'enabled' => true,
            'sync_new_uploads' => true,
            'remove_local_files' => false,
            'log_level' => 'info',
            'batch_size' => 50,
        ]);

        $progress = $this->migrator->get_progress();
        $stats = $this->migrator->get_stats();
        $is_r2_available = $this->r2_client->is_available();
        $last_migration = get_option('numos_r2_last_migration', []);
        $savings = $this->local_cleaner->get_savings();
        $synced_with_local = $this->local_cleaner->count_synced_with_local();
        ?>
        <div class="wrap numos-r2-admin">
            <h1><?php esc_html_e('Numos R2 Media Offload', 'numos-r2'); ?></h1>

            <!-- Status Band -->
            <div class="numos-r2-status-band" role="status">
                <div class="numos-r2-status-band-item">
                    <span><?php esc_html_e('R2:', 'numos-r2'); ?></span>
                    <strong class="<?php echo $is_r2_available ? 'status-ok' : 'status-error'; ?>">
                        <?php echo $is_r2_available ? esc_html__('Connected', 'numos-r2') : esc_html__('Disconnected', 'numos-r2'); ?>
                    </strong>
                </div>
                <?php if (!empty($last_migration['completed_at'])): ?>
                <div class="numos-r2-status-band-item">
                    <span><?php esc_html_e('Last Migration:', 'numos-r2'); ?></span>
                    <strong><?php echo esc_html(human_time_diff($last_migration['completed_at']) . ' ' . __('ago', 'numos-r2')); ?></strong>
                </div>
                <?php endif; ?>
                <?php if (!empty($stats['synced_size_human'])): ?>
                <div class="numos-r2-status-band-item">
                    <span><?php esc_html_e('Total on R2:', 'numos-r2'); ?></span>
                    <strong id="numos-r2-synced-size"><?php echo esc_html($stats['synced_size_human']); ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($savings['freed_bytes'] > 0): ?>
                <div class="numos-r2-status-band-item">
                    <span><?php esc_html_e('Disk Freed:', 'numos-r2'); ?></span>
                    <strong class="status-ok"><?php echo esc_html($savings['freed_human']); ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab Navigation (CSS-only) -->
            <div class="numos-r2-tabs">
                <input type="radio" name="numos-r2-tab" id="numos-r2-tab-dashboard" class="numos-r2-tab-input" checked>
                <label for="numos-r2-tab-dashboard" class="numos-r2-tab-label"><?php esc_html_e('Dashboard', 'numos-r2'); ?></label>

                <input type="radio" name="numos-r2-tab" id="numos-r2-tab-migration" class="numos-r2-tab-input">
                <label for="numos-r2-tab-migration" class="numos-r2-tab-label"><?php esc_html_e('Migration', 'numos-r2'); ?></label>

                <input type="radio" name="numos-r2-tab" id="numos-r2-tab-tools" class="numos-r2-tab-input">
                <label for="numos-r2-tab-tools" class="numos-r2-tab-label"><?php esc_html_e('Tools', 'numos-r2'); ?></label>

                <input type="radio" name="numos-r2-tab" id="numos-r2-tab-settings" class="numos-r2-tab-input">
                <label for="numos-r2-tab-settings" class="numos-r2-tab-label"><?php esc_html_e('Settings', 'numos-r2'); ?></label>

                <input type="radio" name="numos-r2-tab" id="numos-r2-tab-logs" class="numos-r2-tab-input">
                <label for="numos-r2-tab-logs" class="numos-r2-tab-label"><?php esc_html_e('Logs', 'numos-r2'); ?></label>

                <!-- ==================== DASHBOARD TAB ==================== -->
                <div class="numos-r2-tab-panel" id="numos-r2-panel-dashboard">
                    <!-- Status Cards -->
                    <div class="numos-r2-cards">
                        <div class="numos-r2-card">
                            <h3><?php esc_html_e('R2 Status', 'numos-r2'); ?></h3>
                            <div class="numos-r2-status <?php echo $is_r2_available ? 'status-ok' : 'status-error'; ?>" role="status">
                                <?php echo $is_r2_available ? '&#10003; ' . esc_html__('Connected', 'numos-r2') : '&#10007; ' . esc_html__('Disconnected', 'numos-r2'); ?>
                            </div>
                            <button type="button" class="button" id="numos-r2-test-connection">
                                <?php esc_html_e('Test Connection', 'numos-r2'); ?>
                            </button>
                        </div>

                        <div class="numos-r2-card">
                            <h3><?php esc_html_e('Media on R2', 'numos-r2'); ?></h3>
                            <div class="numos-r2-stat">
                                <span class="numos-r2-stat-number" id="numos-r2-synced-count"><?php echo esc_html($progress['synced']); ?></span>
                                <span class="numos-r2-stat-label"><?php printf(esc_html__('of %d total', 'numos-r2'), $progress['total']); ?></span>
                                <?php if (!empty($stats['synced_size_human'])): ?>
                                <span class="numos-r2-stat-detail"><?php echo esc_html($stats['synced_size_human']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="numos-r2-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr($progress['percentage']); ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="numos-r2-progress-fill" style="width: <?php echo esc_attr($progress['percentage']); ?>%"></div>
                            </div>
                            <span class="numos-r2-progress-text"><?php echo esc_html($progress['percentage']); ?>%</span>
                        </div>

                        <div class="numos-r2-card">
                            <h3><?php esc_html_e('Pending Migration', 'numos-r2'); ?></h3>
                            <div class="numos-r2-stat">
                                <span class="numos-r2-stat-number" id="numos-r2-pending-count"><?php echo esc_html($progress['pending']); ?></span>
                                <span class="numos-r2-stat-label"><?php esc_html_e('files to migrate', 'numos-r2'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Storage Savings -->
                    <div class="numos-r2-section numos-r2-savings">
                        <h2><?php esc_html_e('Storage Savings', 'numos-r2'); ?></h2>
                        <div class="numos-r2-savings-grid">
                            <div class="numos-r2-savings-card">
                                <span class="numos-r2-savings-number" id="numos-r2-savings-freed"><?php echo esc_html($savings['freed_human']); ?></span>
                                <span class="numos-r2-savings-label"><?php esc_html_e('Disk Space Freed', 'numos-r2'); ?></span>
                            </div>
                            <div class="numos-r2-savings-card">
                                <span class="numos-r2-savings-number" id="numos-r2-savings-r2only"><?php echo esc_html($savings['r2_only_count']); ?></span>
                                <span class="numos-r2-savings-label"><?php esc_html_e('R2-Only Files', 'numos-r2'); ?></span>
                            </div>
                            <div class="numos-r2-savings-card">
                                <span class="numos-r2-savings-number"><?php echo esc_html($savings['synced_count']); ?></span>
                                <span class="numos-r2-savings-label"><?php esc_html_e('Total Synced', 'numos-r2'); ?></span>
                            </div>
                            <div class="numos-r2-savings-card">
                                <span class="numos-r2-savings-number"><?php echo esc_html($synced_with_local); ?></span>
                                <span class="numos-r2-savings-label"><?php esc_html_e('Synced + Local', 'numos-r2'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Configuration Info -->
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('Configuration', 'numos-r2'); ?></h2>
                        <table class="widefat">
                            <tbody>
                                <tr>
                                    <td><strong><?php esc_html_e('Account ID', 'numos-r2'); ?></strong></td>
                                    <td><code><?php echo esc_html(substr(NUMOS_R2_ACCOUNT_ID, 0, 8) . '...'); ?></code></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Bucket', 'numos-r2'); ?></strong></td>
                                    <td><code><?php echo esc_html(NUMOS_R2_BUCKET); ?></code></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Public URL', 'numos-r2'); ?></strong></td>
                                    <td><code><?php echo esc_html(NUMOS_R2_PUBLIC_URL); ?></code></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Site Prefix', 'numos-r2'); ?></strong></td>
                                    <td><code><?php echo esc_html(numos_r2_get_site_prefix()); ?></code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ==================== MIGRATION TAB ==================== -->
                <div class="numos-r2-tab-panel" id="numos-r2-panel-migration">
                    <!-- Migration Controls -->
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('Upload to R2', 'numos-r2'); ?></h2>

                        <div class="numos-r2-migration-controls">
                            <button type="button" class="button button-primary" id="numos-r2-start-migration" <?php echo $progress['pending'] === 0 ? 'disabled' : ''; ?>>
                                <?php esc_html_e('Migrate Existing Media', 'numos-r2'); ?>
                            </button>
                            <button type="button" class="button" id="numos-r2-reset-failed">
                                <?php esc_html_e('Retry Failed', 'numos-r2'); ?>
                            </button>
                        </div>

                        <div id="numos-r2-migration-progress" style="display: none;">
                            <div class="numos-r2-progress-bar large" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                <div class="numos-r2-progress-fill" id="numos-r2-migration-bar" style="width: 0%"></div>
                            </div>
                            <div class="numos-r2-migration-status">
                                <span id="numos-r2-migration-text"><?php esc_html_e('Preparing...', 'numos-r2'); ?></span>
                                <div class="numos-r2-migration-eta" id="numos-r2-migration-eta" style="display: none;"></div>
                            </div>
                        </div>

                        <div id="numos-r2-migration-log" class="numos-r2-log" style="display: none;"></div>
                    </div>

                    <!-- Delete Local Files -->
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('Delete Local Files', 'numos-r2'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Remove local copies of files already verified on R2. Each file is checked before deletion. This frees disk space on your server.', 'numos-r2'); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Eligible:', 'numos-r2'); ?></strong>
                            <span id="numos-r2-local-eligible"><?php echo esc_html($synced_with_local); ?></span>
                            <?php esc_html_e('synced attachments with local files', 'numos-r2'); ?>
                        </p>

                        <div class="numos-r2-migration-controls">
                            <button type="button" class="button button-primary" id="numos-r2-delete-local" <?php echo $synced_with_local === 0 ? 'disabled' : ''; ?>>
                                <?php esc_html_e('Delete Verified Local Files', 'numos-r2'); ?>
                            </button>
                        </div>

                        <div id="numos-r2-delete-local-progress" style="display: none;">
                            <div class="numos-r2-progress-bar large" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                <div class="numos-r2-progress-fill" id="numos-r2-delete-local-bar" style="width: 0%"></div>
                            </div>
                            <div class="numos-r2-migration-status">
                                <span id="numos-r2-delete-local-text"><?php esc_html_e('Preparing...', 'numos-r2'); ?></span>
                            </div>
                        </div>

                        <div id="numos-r2-delete-local-log" class="numos-r2-log" style="display: none;"></div>
                    </div>
                </div>

                <!-- ==================== TOOLS TAB ==================== -->
                <div class="numos-r2-tab-panel" id="numos-r2-panel-tools">
                    <!-- Verify R2 Files -->
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('Verify R2 Files', 'numos-r2'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Check that all synced files exist on R2 with correct sizes. Useful after bucket changes or to audit integrity.', 'numos-r2'); ?>
                        </p>
                        <button type="button" class="button" id="numos-r2-verify-files">
                            <?php esc_html_e('Verify Synced Files', 'numos-r2'); ?>
                        </button>
                        <div id="numos-r2-verify-results" style="display: none; margin-top: 15px;">
                            <div id="numos-r2-verify-summary"></div>
                        </div>
                    </div>

                    <!-- Restore from R2 -->
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('Restore from R2', 'numos-r2'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Download files from R2 back to local filesystem. Use this to restore files after local deletion, or before migrating away from R2.', 'numos-r2'); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('R2-only attachments:', 'numos-r2'); ?></strong>
                            <span id="numos-r2-r2only-count"><?php echo esc_html($savings['r2_only_count']); ?></span>
                        </p>
                        <button type="button" class="button" id="numos-r2-restore-r2" <?php echo $savings['r2_only_count'] === 0 ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Restore from R2', 'numos-r2'); ?>
                        </button>
                        <div id="numos-r2-restore-progress" style="display: none; margin-top: 15px;">
                            <div class="numos-r2-progress-bar large" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                <div class="numos-r2-progress-fill" id="numos-r2-restore-bar" style="width: 0%"></div>
                            </div>
                            <div class="numos-r2-migration-status">
                                <span id="numos-r2-restore-text"><?php esc_html_e('Preparing...', 'numos-r2'); ?></span>
                            </div>
                        </div>
                        <div id="numos-r2-restore-log" class="numos-r2-log" style="display: none;"></div>
                    </div>

                    <!-- Database Cleanup -->
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('Database Cleanup', 'numos-r2'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Scan for orphan database entries - attachment records without physical files.', 'numos-r2'); ?>
                        </p>
                        <div class="numos-r2-orphan-controls">
                            <button type="button" class="button" id="numos-r2-scan-db-orphans">
                                <?php esc_html_e('Scan for Orphan Entries', 'numos-r2'); ?>
                            </button>
                            <button type="button" class="button button-danger" id="numos-r2-delete-db-orphans" style="display: none;">
                                <?php esc_html_e('Delete Selected', 'numos-r2'); ?>
                            </button>
                        </div>
                        <div id="numos-r2-db-orphan-results" style="display: none; margin-top: 15px;">
                            <div id="numos-r2-db-orphan-summary"></div>
                            <div id="numos-r2-db-orphan-list" class="numos-r2-orphan-list"></div>
                        </div>
                    </div>

                    <!-- R2 Bucket Cleanup -->
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('R2 Bucket Cleanup', 'numos-r2'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Scan your R2 bucket for files not in your current site prefix.', 'numos-r2'); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Current prefix:', 'numos-r2'); ?></strong>
                            <code><?php echo esc_html(numos_r2_get_site_prefix()); ?>/</code>
                        </p>
                        <div class="numos-r2-orphan-controls">
                            <button type="button" class="button" id="numos-r2-scan-orphans">
                                <?php esc_html_e('Scan for Orphan Files', 'numos-r2'); ?>
                            </button>
                            <button type="button" class="button button-danger" id="numos-r2-delete-orphans" style="display: none;">
                                <?php esc_html_e('Delete Selected', 'numos-r2'); ?>
                            </button>
                        </div>
                        <div id="numos-r2-orphan-results" style="display: none; margin-top: 15px;">
                            <div id="numos-r2-orphan-summary"></div>
                            <div id="numos-r2-orphan-list" class="numos-r2-orphan-list"></div>
                        </div>
                    </div>

                    <!-- Sync from R2 -->
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('Sync from R2', 'numos-r2'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Reconcile database with R2. Scans R2 and marks attachments as synced if their files are found. Useful after a database restore.', 'numos-r2'); ?>
                        </p>
                        <button type="button" class="button" id="numos-r2-sync-from-r2">
                            <?php esc_html_e('Sync from R2', 'numos-r2'); ?>
                        </button>
                        <div id="numos-r2-sync-from-r2-results" style="display: none; margin-top: 15px;">
                            <div id="numos-r2-sync-from-r2-summary"></div>
                        </div>
                    </div>

                    <!-- Delete from R2 -->
                    <div class="numos-r2-section numos-r2-danger-zone">
                        <h2><?php esc_html_e('Delete Site Files from R2', 'numos-r2'); ?></h2>
                        <p class="description">
                            <?php printf(
                                esc_html__('Delete all files for this site from the R2 bucket. This removes all objects under the prefix %s. Local files are NOT affected. R2 metadata will be reset.', 'numos-r2'),
                                '<code>' . esc_html(numos_r2_get_site_prefix()) . '/</code>'
                            ); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Synced files:', 'numos-r2'); ?></strong>
                            <span id="numos-r2-r2-file-count"><?php echo esc_html($progress['synced']); ?></span>
                        </p>
                        <button type="button" class="button button-danger" id="numos-r2-delete-from-r2" <?php echo $progress['synced'] === 0 ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Delete All from R2', 'numos-r2'); ?>
                        </button>
                        <div id="numos-r2-delete-r2-progress" style="display: none; margin-top: 15px;">
                            <div class="numos-r2-progress-bar large" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                <div class="numos-r2-progress-fill" id="numos-r2-delete-r2-bar" style="width: 0%"></div>
                            </div>
                            <div class="numos-r2-migration-status">
                                <span id="numos-r2-delete-r2-text"><?php esc_html_e('Preparing...', 'numos-r2'); ?></span>
                            </div>
                        </div>
                        <div id="numos-r2-delete-r2-log" class="numos-r2-log" style="display: none;"></div>
                    </div>

                    <!-- Danger Zone -->
                    <div class="numos-r2-section numos-r2-danger-zone">
                        <h2><?php esc_html_e('Danger Zone', 'numos-r2'); ?></h2>
                        <p class="description">
                            <?php esc_html_e('Purge all R2 metadata from the database. This will NOT delete files from R2 or your local server.', 'numos-r2'); ?>
                        </p>
                        <button type="button" class="button button-danger" id="numos-r2-purge-meta">
                            <?php esc_html_e('Purge R2 Metadata', 'numos-r2'); ?>
                        </button>
                    </div>
                </div>

                <!-- ==================== SETTINGS TAB ==================== -->
                <div class="numos-r2-tab-panel" id="numos-r2-panel-settings">
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('Settings', 'numos-r2'); ?></h2>

                        <form id="numos-r2-settings-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e('Enable R2 Offload', 'numos-r2'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                                            <?php esc_html_e('Enable URL rewriting to R2', 'numos-r2'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('When enabled, synced media URLs will point to R2.', 'numos-r2'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Sync New Uploads', 'numos-r2'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="sync_new_uploads" value="1" <?php checked(!empty($settings['sync_new_uploads'])); ?>>
                                            <?php esc_html_e('Automatically upload new media to R2', 'numos-r2'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('New uploads will be automatically synced to R2.', 'numos-r2'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Remove Local Files', 'numos-r2'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="remove_local_files" value="1" <?php checked(!empty($settings['remove_local_files'])); ?>>
                                            <?php esc_html_e('Automatically delete local files after successful R2 upload', 'numos-r2'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('Each file is verified on R2 before local deletion. Applies to new uploads and migrations. Use with caution.', 'numos-r2'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Log Level', 'numos-r2'); ?></th>
                                    <td>
                                        <select name="log_level" id="numos-r2-log-level">
                                            <option value="debug" <?php selected($settings['log_level'] ?? 'info', 'debug'); ?>><?php esc_html_e('Debug', 'numos-r2'); ?></option>
                                            <option value="info" <?php selected($settings['log_level'] ?? 'info', 'info'); ?>><?php esc_html_e('Info', 'numos-r2'); ?></option>
                                            <option value="warning" <?php selected($settings['log_level'] ?? 'info', 'warning'); ?>><?php esc_html_e('Warning', 'numos-r2'); ?></option>
                                            <option value="error" <?php selected($settings['log_level'] ?? 'info', 'error'); ?>><?php esc_html_e('Error', 'numos-r2'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Minimum log level to record.', 'numos-r2'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Batch Size', 'numos-r2'); ?></th>
                                    <td>
                                        <div class="range-slider">
                                            <input type="range" name="batch_size" id="numos-r2-batch-size"
                                                   min="10" max="200" step="10"
                                                   value="<?php echo esc_attr($settings['batch_size'] ?? 50); ?>">
                                            <span class="range-value" id="numos-r2-batch-size-value"><?php echo esc_html($settings['batch_size'] ?? 50); ?></span>
                                        </div>
                                        <p class="description"><?php esc_html_e('Number of files to process per batch.', 'numos-r2'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'numos-r2'); ?></button>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- ==================== LOGS TAB ==================== -->
                <div class="numos-r2-tab-panel" id="numos-r2-panel-logs">
                    <div class="numos-r2-section">
                        <h2><?php esc_html_e('Recent Logs', 'numos-r2'); ?></h2>

                        <button type="button" class="button" id="numos-r2-refresh-logs">
                            <?php esc_html_e('Refresh Logs', 'numos-r2'); ?>
                        </button>

                        <div id="numos-r2-logs" class="numos-r2-log">
                            <?php echo esc_html($this->get_recent_logs(50)); ?>
                        </div>
                    </div>

                    <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                    <div class="numos-r2-section numos-r2-debug">
                        <details>
                            <summary><?php esc_html_e('Debug Information', 'numos-r2'); ?></summary>
                            <table class="numos-r2-debug-table">
                                <tr>
                                    <td><?php esc_html_e('PHP Version', 'numos-r2'); ?></td>
                                    <td><code><?php echo esc_html(phpversion()); ?></code></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('Memory Limit', 'numos-r2'); ?></td>
                                    <td><code><?php echo esc_html(ini_get('memory_limit')); ?></code></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('Max Execution Time', 'numos-r2'); ?></td>
                                    <td><code><?php echo esc_html(ini_get('max_execution_time')); ?>s</code></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('Upload Max Filesize', 'numos-r2'); ?></td>
                                    <td><code><?php echo esc_html(ini_get('upload_max_filesize')); ?></code></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('cURL Version', 'numos-r2'); ?></td>
                                    <td><code><?php $curl = curl_version(); echo esc_html($curl['version'] ?? 'N/A'); ?></code></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('Plugin Version', 'numos-r2'); ?></td>
                                    <td><code><?php echo esc_html(NUMOS_R2_VERSION); ?></code></td>
                                </tr>
                            </table>
                            <details style="margin-top: 15px;">
                                <summary><?php esc_html_e('Raw Settings', 'numos-r2'); ?></summary>
                                <pre><?php echo esc_html(print_r(get_option('numos_r2_settings', []), true)); ?></pre>
                            </details>
                        </details>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Add R2 Status column to Media Library list view
     */
    public function add_media_column(array $columns): array {
        $columns['numos_r2_status'] = __('R2 Status', 'numos-r2');
        return $columns;
    }

    /**
     * Render R2 Status column content
     */
    public function render_media_column(string $column_name, int $post_id): void {
        if ($column_name !== 'numos_r2_status') {
            return;
        }

        $status = get_post_meta($post_id, '_numos_r2_status', true);
        $local_deleted = get_post_meta($post_id, '_numos_r2_local_deleted', true);

        if ($status === 'synced' && $local_deleted) {
            echo '<span class="numos-r2-badge numos-r2-badge-r2only">' . esc_html__('R2 Only', 'numos-r2') . '</span>';
        } elseif ($status === 'synced') {
            echo '<span class="numos-r2-badge numos-r2-badge-synced">' . esc_html__('Synced', 'numos-r2') . '</span>';
        } elseif ($status === 'partial') {
            echo '<span class="numos-r2-badge numos-r2-badge-partial">' . esc_html__('Partial', 'numos-r2') . '</span>';
        } elseif ($status === 'skipped') {
            $reason = get_post_meta($post_id, '_numos_r2_skip_reason', true);
            echo '<span class="numos-r2-badge numos-r2-badge-local" title="' . esc_attr($reason) . '">' . esc_html__('Skipped', 'numos-r2') . '</span>';
        } else {
            echo '<span class="numos-r2-badge numos-r2-badge-local">' . esc_html__('Local', 'numos-r2') . '</span>';
        }
    }

    // ==================== AJAX HANDLERS — EXISTING ==================== //

    public function ajax_test_connection(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        delete_transient('numos_r2_health_status');
        $is_available = $this->r2_client->head_bucket();

        if ($is_available) {
            // Auto-configure CORS if not already done
            $cors_configured = get_option('numos_r2_cors_configured');
            $cors_msg = '';
            if (!$cors_configured) {
                $cors_result = $this->r2_client->put_bucket_cors();
                if ($cors_result['success']) {
                    update_option('numos_r2_cors_configured', true);
                    $cors_msg = ' ' . __('CORS configured.', 'numos-r2');
                } else {
                    $cors_msg = ' ' . __('Warning: could not configure CORS automatically.', 'numos-r2') . ' ' . $cors_result['message'];
                }
            }
            wp_send_json_success(['message' => __('Connection successful!', 'numos-r2') . $cors_msg, 'status' => 'ok']);
        } else {
            wp_send_json_error(['message' => __('Connection failed. Check your credentials and bucket configuration.', 'numos-r2'), 'status' => 'error']);
        }
    }

    public function ajax_migrate_batch(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $is_start = isset($_POST['start']) && $_POST['start'] === 'true';
        if ($is_start) {
            if (!$this->migrator->start_migration()) {
                wp_send_json_error(['message' => __('A migration is already in progress.', 'numos-r2')]);
            }
        }

        $result = $this->migrator->migrate_batch();
        $progress = $this->migrator->get_progress();
        wp_send_json_success(['result' => $result, 'progress' => $progress]);
    }

    public function ajax_get_progress(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $progress = $this->migrator->get_progress();
        $stats = $this->migrator->get_stats();
        $savings = $this->local_cleaner->get_savings();
        $synced_with_local = $this->local_cleaner->count_synced_with_local();
        wp_send_json_success([
            'progress' => $progress,
            'stats' => $stats,
            'savings' => $savings,
            'synced_with_local' => $synced_with_local,
        ]);
    }

    public function ajax_save_settings(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 50;
        $batch_size = max(10, min(200, $batch_size));

        $log_level = sanitize_text_field($_POST['log_level'] ?? 'info');
        if (!in_array($log_level, ['debug', 'info', 'warning', 'error'], true)) {
            $log_level = 'info';
        }

        $settings = [
            'enabled' => !empty($_POST['enabled']),
            'sync_new_uploads' => !empty($_POST['sync_new_uploads']),
            'remove_local_files' => !empty($_POST['remove_local_files']),
            'log_level' => $log_level,
            'batch_size' => $batch_size,
        ];

        update_option('numos_r2_settings', $settings);
        Logger::reset();

        wp_send_json_success(['message' => __('Settings saved', 'numos-r2')]);
    }

    public function ajax_get_logs(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }
        wp_send_json_success(['logs' => $this->get_recent_logs(100)]);
    }

    public function ajax_reset_failed(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $count = $this->migrator->reset_failed();
        $progress = $this->migrator->get_progress();
        wp_send_json_success([
            'message' => sprintf(__('Reset %d failed items', 'numos-r2'), $count),
            'progress' => $progress,
        ]);
    }

    public function ajax_purge_meta(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('_numos_r2_') . '%'
        ));

        delete_option('numos_r2_migration_checkpoint');
        delete_option('numos_r2_migration_stats');
        delete_transient('numos_r2_health_status');
        delete_transient('numos_r2_stats_cache');
        delete_transient('numos_r2_synced_paths');
        $this->migrator->invalidate_progress_cache();

        wp_send_json_success([
            'message' => sprintf(__('Purged %d metadata entries', 'numos-r2'), $deleted),
            'progress' => $this->migrator->get_progress(true),
        ]);
    }

    public function ajax_list_orphans(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $site_prefix = numos_r2_get_site_prefix();
        $continuation_token = sanitize_text_field($_POST['token'] ?? '');
        $result = $this->r2_client->list_objects('', $continuation_token, 500);

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to list R2 objects', 'numos-r2')]);
        }

        $orphans = [];
        $total_size = 0;
        foreach ($result['objects'] as $obj) {
            if (strpos($obj['key'], $site_prefix . '/') !== 0) {
                $orphans[] = [
                    'key' => $obj['key'],
                    'size' => $obj['size'],
                    'size_human' => size_format($obj['size']),
                    'last_modified' => $obj['last_modified'],
                ];
                $total_size += $obj['size'];
            }
        }

        wp_send_json_success([
            'orphans' => $orphans,
            'total_size' => $total_size,
            'total_size_human' => size_format($total_size),
            'has_more' => $result['is_truncated'],
            'next_token' => $result['next_token'],
            'site_prefix' => $site_prefix,
        ]);
    }

    public function ajax_delete_orphans(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $keys = isset($_POST['keys']) ? array_map('sanitize_text_field', (array)$_POST['keys']) : [];
        if (empty($keys)) {
            wp_send_json_error(['message' => __('No files selected', 'numos-r2')]);
        }

        $site_prefix = numos_r2_get_site_prefix();
        $safe_keys = [];
        foreach ($keys as $key) {
            if (strpos($key, $site_prefix . '/') !== 0) {
                $safe_keys[] = $key;
            }
        }

        if (empty($safe_keys)) {
            wp_send_json_error(['message' => __('No valid orphan files to delete', 'numos-r2')]);
        }

        $result = $this->r2_client->delete_objects($safe_keys);
        wp_send_json_success([
            'message' => sprintf(__('Deleted %d files from R2', 'numos-r2'), $result['deleted']),
            'deleted' => $result['deleted'],
            'errors' => $result['errors'],
        ]);
    }

    public function ajax_scan_db_orphans(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $orphans = $this->migrator->get_db_orphans(500);
        wp_send_json_success(['orphans' => $orphans, 'count' => count($orphans)]);
    }

    public function ajax_delete_db_orphans(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];
        if (empty($ids)) {
            wp_send_json_error(['message' => __('No entries selected', 'numos-r2')]);
        }

        $result = $this->migrator->delete_db_orphans($ids);
        $progress = $this->migrator->get_progress();
        wp_send_json_success([
            'message' => sprintf(__('Deleted %d orphan database entries', 'numos-r2'), $result['deleted']),
            'deleted' => $result['deleted'],
            'errors' => $result['errors'],
            'progress' => $progress,
        ]);
    }

    // ==================== AJAX HANDLERS — NEW ==================== //

    public function ajax_delete_local_batch(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 50;
        $batch_size = max(1, min(200, $batch_size));

        $result = $this->local_cleaner->batch_delete_local($batch_size);
        $savings = $this->local_cleaner->get_savings();
        $remaining = $this->local_cleaner->count_synced_with_local();

        wp_send_json_success([
            'result' => $result,
            'savings' => $savings,
            'remaining' => $remaining,
        ]);
    }

    public function ajax_delete_all_local(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $result = $this->local_cleaner->delete_all_local();
        $savings = $this->local_cleaner->get_savings();
        $remaining = $this->local_cleaner->count_synced_with_local();

        wp_send_json_success([
            'result' => $result,
            'savings' => $savings,
            'remaining' => $remaining,
        ]);
    }

    public function ajax_verify_batch(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 20;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_numos_r2_status' AND meta_value = %s
            ORDER BY post_id ASC LIMIT %d OFFSET %d",
            'synced', $batch_size, $offset
        )));

        if (empty($ids)) {
            wp_send_json_success(['done' => true, 'ok' => 0, 'issues' => 0, 'details' => []]);
        }

        $ok = 0;
        $issues = 0;
        $details = [];

        foreach ($ids as $id) {
            $v = $this->local_cleaner->verify_attachment($id);
            if ($v['verified']) {
                $ok++;
            } else {
                $issues++;
                $details[] = [
                    'id' => $id,
                    'errors' => $v['errors'],
                ];
            }
        }

        wp_send_json_success([
            'done' => count($ids) < $batch_size,
            'ok' => $ok,
            'issues' => $issues,
            'details' => $details,
            'next_offset' => $offset + count($ids),
        ]);
    }

    public function ajax_verify_all(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $result = $this->local_cleaner->verify_all();
        wp_send_json_success($result);
    }

    public function ajax_restore_batch(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 20;

        $result = $this->local_cleaner->batch_restore_from_r2($batch_size);
        $savings = $this->local_cleaner->get_savings();

        wp_send_json_success([
            'result' => $result,
            'savings' => $savings,
        ]);
    }

    public function ajax_restore_all(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        $result = $this->local_cleaner->restore_all();
        $savings = $this->local_cleaner->get_savings();

        wp_send_json_success([
            'result' => $result,
            'savings' => $savings,
        ]);
    }

    public function ajax_get_savings(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        wp_send_json_success($this->local_cleaner->get_savings());
    }

    public function ajax_delete_from_r2_batch(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        // Block if any attachments have local files deleted (R2 is the only copy)
        $savings = $this->local_cleaner->get_savings();
        if ($savings['r2_only_count'] > 0) {
            wp_send_json_error([
                'message' => sprintf(
                    __('%d attachments have no local copy — R2 is their only source. Restore them first before deleting from R2.', 'numos-r2'),
                    $savings['r2_only_count']
                ),
            ]);
        }

        $continuation_token = sanitize_text_field($_POST['token'] ?? '');
        $site_prefix = numos_r2_get_site_prefix();

        // List objects under this site's prefix
        $result = $this->r2_client->list_objects($site_prefix . '/', $continuation_token, 500);

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to list R2 objects', 'numos-r2')]);
        }

        $keys = array_column($result['objects'], 'key');
        $deleted = 0;
        $errors = [];

        if (!empty($keys)) {
            $delete_result = $this->r2_client->delete_objects($keys);
            $deleted = $delete_result['deleted'];
            $errors = $delete_result['errors'];
        }

        $has_more = $result['is_truncated'];

        // If no more files, clean up metadata
        if (!$has_more) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like('_numos_r2_') . '%'
            ));
            delete_option('numos_r2_migration_checkpoint');
            delete_option('numos_r2_migration_stats');
            delete_transient('numos_r2_health_status');
            delete_transient('numos_r2_stats_cache');
            delete_transient('numos_r2_synced_paths');
            $this->migrator->invalidate_progress_cache();
        }

        wp_send_json_success([
            'deleted' => $deleted,
            'errors' => $errors,
            'has_more' => $has_more,
            'next_token' => $result['next_token'],
            'done' => !$has_more,
        ]);
    }

    public function ajax_sync_from_r2(): void {
        check_ajax_referer('numos_r2_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'numos-r2')]);
        }

        @set_time_limit(300);

        $result = $this->local_cleaner->sync_from_r2();

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        $this->migrator->invalidate_progress_cache();

        wp_send_json_success([
            'result' => $result,
            'progress' => $this->migrator->get_progress(),
        ]);
    }

    // ==================== HELPERS ==================== //

    private function get_recent_logs(int $lines = 50): string {
        $log_file = WP_CONTENT_DIR . '/numos-r2-logs/numos-r2-' . gmdate('Y-m-d') . '.log.php';
        if (!file_exists($log_file)) {
            return __('No logs available', 'numos-r2');
        }

        $file_content = file($log_file, FILE_IGNORE_NEW_LINES);
        if (empty($file_content)) {
            return __('No logs available', 'numos-r2');
        }

        // Skip the PHP guard line (first line)
        if (!empty($file_content[0]) && strpos($file_content[0], '<' . '?php exit;') === 0) {
            array_shift($file_content);
        }

        $recent = array_slice($file_content, -$lines);
        return implode("\n", array_reverse($recent));
    }
}
