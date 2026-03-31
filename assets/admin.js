/**
 * Numos R2 Media Offload - Admin JavaScript
 */
(function($) {
    'use strict';

    const NumosR2Admin = {
        isMigrating: false,
        isDeletingLocal: false,
        isVerifying: false,
        isRestoring: false,
        isDeletingFromR2: false,
        pendingRequests: new Map(),
        migrationStats: {
            startTime: null,
            filesProcessed: 0,
            totalFiles: 0,
            batchTimes: []
        },

        init: function() {
            this.bindEvents();
            this.initBatchSizeSlider();
        },

        bindEvents: function() {
            // Existing
            $('#numos-r2-test-connection').on('click', this.testConnection.bind(this));
            $('#numos-r2-start-migration').on('click', this.startMigration.bind(this));
            $('#numos-r2-reset-failed').on('click', this.resetFailed.bind(this));
            $('#numos-r2-refresh-logs').on('click', this.refreshLogs.bind(this));
            $('#numos-r2-settings-form').on('submit', this.saveSettings.bind(this));
            $('#numos-r2-purge-meta').on('click', this.purgeMeta.bind(this));
            $('#numos-r2-scan-orphans').on('click', this.scanOrphans.bind(this));
            $('#numos-r2-delete-orphans').on('click', this.deleteOrphans.bind(this));
            $('#numos-r2-scan-db-orphans').on('click', this.scanDbOrphans.bind(this));
            $('#numos-r2-delete-db-orphans').on('click', this.deleteDbOrphans.bind(this));

            // New
            $('#numos-r2-delete-local').on('click', this.startDeleteLocal.bind(this));
            $('#numos-r2-verify-files').on('click', this.startVerify.bind(this));
            $('#numos-r2-restore-r2').on('click', this.startRestore.bind(this));
            $('#numos-r2-delete-from-r2').on('click', this.startDeleteFromR2.bind(this));
            $('#numos-r2-sync-from-r2').on('click', this.startSyncFromR2.bind(this));
        },

        initBatchSizeSlider: function() {
            const $slider = $('#numos-r2-batch-size');
            const $value = $('#numos-r2-batch-size-value');

            if ($slider.length && $value.length) {
                $slider.on('input', function() {
                    $value.text(this.value);
                });
            }
        },

        orphanFiles: [],
        dbOrphanEntries: [],

        escHtml: function(str) {
            if (typeof str !== 'string') {
                str = String(str);
            }
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

        fetchWithRetry: function(options, retries = 3) {
            const self = this;
            var defaults = { timeout: 60000 };

            return new Promise(function(resolve, reject) {
                function attempt(attemptNumber) {
                    $.ajax($.extend({}, defaults, options, {
                        success: function(response) {
                            resolve(response);
                        },
                        error: function(xhr, status, error) {
                            if (attemptNumber < retries && status !== 'abort') {
                                const delay = Math.pow(2, attemptNumber) * 1000;
                                setTimeout(function() {
                                    attempt(attemptNumber + 1);
                                }, delay);
                            } else {
                                reject({ xhr: xhr, status: status, error: error });
                            }
                        }
                    }));
                }

                attempt(0);
            });
        },

        executeOnce: function(key, callback) {
            if (this.pendingRequests.has(key)) {
                return this.pendingRequests.get(key);
            }

            const promise = callback().finally(() => {
                this.pendingRequests.delete(key);
            });

            this.pendingRequests.set(key, promise);
            return promise;
        },

        // ==================== CONNECTION ==================== //

        testConnection: function(e) {
            e.preventDefault();

            const self = this;
            const $button = $(e.currentTarget);

            this.executeOnce('testConnection', function() {
                $button.addClass('loading').prop('disabled', true);

                return self.fetchWithRetry({
                    url: numosR2.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'numos_r2_test_connection',
                        nonce: numosR2.nonce
                    }
                }).then(function(response) {
                    if (response.success) {
                        self.showNotice(response.data.message, 'success');
                        $('.numos-r2-status')
                            .removeClass('status-error')
                            .addClass('status-ok')
                            .html('&#10003; Connected');
                    } else {
                        self.showNotice(response.data.message, 'error');
                        $('.numos-r2-status')
                            .removeClass('status-ok')
                            .addClass('status-error')
                            .html('&#10007; Disconnected');
                    }
                }).catch(function() {
                    self.showNotice(numosR2.strings.connectionFailed, 'error');
                }).finally(function() {
                    $button.removeClass('loading').prop('disabled', false);
                });
            });
        },

        // ==================== MIGRATION ==================== //

        startMigration: function(e) {
            e.preventDefault();

            if (this.isMigrating) return;

            if (!confirm(numosR2.strings.confirmMigration)) return;

            this.isMigrating = true;
            this.migrationStats = {
                startTime: Date.now(),
                filesProcessed: 0,
                totalFiles: 0,
                batchTimes: []
            };

            const $button = $('#numos-r2-start-migration');
            const $progress = $('#numos-r2-migration-progress');
            const $log = $('#numos-r2-migration-log');
            const $eta = $('#numos-r2-migration-eta');

            $button.addClass('loading').prop('disabled', true);
            $progress.show();
            $log.show().empty();
            $eta.show().text('Calculating ETA...');

            this.runMigrationBatch(true);
        },

        runMigrationBatch: function(isStart) {
            const self = this;
            const batchStartTime = Date.now();

            this.fetchWithRetry({
                url: numosR2.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'numos_r2_migrate_batch',
                    nonce: numosR2.nonce,
                    start: isStart ? 'true' : 'false'
                }
            }, 5).then(function(response) {
                if (response.success) {
                    const data = response.data;
                    const progress = data.progress;
                    const result = data.result;

                    const batchTime = Date.now() - batchStartTime;
                    self.migrationStats.batchTimes.push(batchTime);
                    self.migrationStats.filesProcessed = progress.synced;
                    self.migrationStats.totalFiles = progress.total;

                    $('#numos-r2-migration-bar').css('width', progress.percentage + '%');
                    $('#numos-r2-migration-text').text(
                        progress.synced + ' / ' + progress.total + ' (' + progress.percentage + '%)'
                    );

                    self.updateETA(progress.pending);

                    $('#numos-r2-synced-count').text(progress.synced);
                    $('#numos-r2-pending-count').text(progress.pending);

                    if (result.errors && result.errors.length > 0) {
                        result.errors.forEach(function(error) {
                            self.appendLog('#numos-r2-migration-log', error, 'error');
                        });
                    }

                    self.appendLog('#numos-r2-migration-log',
                        'Batch: ' + result.success + ' success, ' + result.failed + ' failed',
                        'info'
                    );

                    if (!result.done) {
                        setTimeout(function() {
                            self.runMigrationBatch(false);
                        }, 100);
                    } else {
                        self.finishMigration();
                    }
                } else {
                    self.showNotice(response.data.message, 'error');
                    self.finishMigration();
                }
            }).catch(function(err) {
                self.appendLog('#numos-r2-migration-log', 'Request failed after retries: ' + (err.error || 'Network error'), 'error');
                self.finishMigration();
            });
        },

        updateETA: function(remaining) {
            const stats = this.migrationStats;
            const $eta = $('#numos-r2-migration-eta');

            if (stats.batchTimes.length < 2) {
                $eta.text('Calculating ETA...');
                return;
            }

            const recentBatches = stats.batchTimes.slice(-5);
            const avgBatchTime = recentBatches.reduce((a, b) => a + b, 0) / recentBatches.length;
            const batchSize = parseInt($('#numos-r2-batch-size').val()) || 50;
            const filesPerBatch = Math.min(batchSize, remaining);
            const remainingBatches = Math.ceil(remaining / filesPerBatch);
            const estimatedMs = remainingBatches * avgBatchTime;
            const eta = this.formatDuration(estimatedMs);
            const speed = Math.round((batchSize / avgBatchTime) * 1000 * 60);

            $eta.html('ETA: <strong>' + this.escHtml(eta) + '</strong> (~' + speed + ' files/min)');
        },

        formatDuration: function(ms) {
            const seconds = Math.floor(ms / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);

            if (hours > 0) {
                return hours + 'h ' + (minutes % 60) + 'm';
            } else if (minutes > 0) {
                return minutes + 'm ' + (seconds % 60) + 's';
            } else {
                return seconds + 's';
            }
        },

        finishMigration: function() {
            this.isMigrating = false;

            const $button = $('#numos-r2-start-migration');
            const $eta = $('#numos-r2-migration-eta');
            const stats = this.migrationStats;

            $button.removeClass('loading').prop('disabled', false);

            const totalDuration = this.formatDuration(Date.now() - stats.startTime);
            $eta.html('Completed in <strong>' + this.escHtml(totalDuration) + '</strong>');

            $('#numos-r2-migration-text').text(numosR2.strings.complete);
            this.appendLog('#numos-r2-migration-log', 'Migration completed in ' + totalDuration, 'success');

            this.refreshProgress();
        },

        // ==================== DELETE LOCAL FILES ==================== //

        startDeleteLocal: function(e) {
            e.preventDefault();

            if (this.isDeletingLocal) return;
            if (!confirm(numosR2.strings.confirmDeleteLocal)) return;

            this.isDeletingLocal = true;

            const self = this;
            const $button = $('#numos-r2-delete-local');
            const $progress = $('#numos-r2-delete-local-progress');
            const $log = $('#numos-r2-delete-local-log');
            const startTime = Date.now();

            $button.addClass('loading').prop('disabled', true);
            $progress.show();
            $log.show().empty();

            $('#numos-r2-delete-local-bar').css('width', '0%');
            $('#numos-r2-delete-local-text').text('Deleting local files...');

            this.fetchWithRetry({
                url: numosR2.ajaxUrl,
                type: 'POST',
                timeout: 120000,
                data: {
                    action: 'numos_r2_delete_all_local',
                    nonce: numosR2.nonce
                }
            }).then(function(response) {
                if (response.success) {
                    const data = response.data;
                    const result = data.result;
                    const totalDuration = self.formatDuration(Date.now() - startTime);

                    // Update progress bar to 100%
                    $('#numos-r2-delete-local-bar').css('width', '100%');
                    $('#numos-r2-delete-local-text').text(
                        result.deleted + ' deleted, ' +
                        self.formatSize(result.freed_bytes) + ' freed'
                    );
                    $('#numos-r2-local-eligible').text(data.remaining);

                    // Update savings
                    if (data.savings) {
                        $('#numos-r2-savings-freed').text(data.savings.freed_human);
                        $('#numos-r2-savings-r2only').text(data.savings.r2_only_count);
                    }

                    if (result.errors && result.errors.length > 0) {
                        result.errors.forEach(function(error) {
                            self.appendLog('#numos-r2-delete-local-log', error, 'error');
                        });
                    }

                    if (result.skipped > 0) {
                        self.appendLog('#numos-r2-delete-local-log',
                            result.skipped + ' attachments skipped (verification failed)',
                            'warning'
                        );
                    }

                    self.appendLog('#numos-r2-delete-local-log',
                        'Completed in ' + totalDuration + ': ' + result.deleted + ' attachments, ' + self.formatSize(result.freed_bytes) + ' freed',
                        'success'
                    );

                    self.showNotice(
                        'Deleted local files for ' + result.deleted + ' attachments, freed ' + self.formatSize(result.freed_bytes),
                        'success'
                    );
                } else {
                    self.showNotice(response.data.message, 'error');
                }
            }).catch(function(err) {
                self.appendLog('#numos-r2-delete-local-log', 'Request failed: ' + (err.error || 'Network error'), 'error');
                self.showNotice(numosR2.strings.error, 'error');
            }).finally(function() {
                self.isDeletingLocal = false;
                $button.removeClass('loading').prop('disabled', false);
            });
        },

        // ==================== VERIFY ==================== //

        startVerify: function(e) {
            e.preventDefault();

            if (this.isVerifying) return;

            this.isVerifying = true;

            const self = this;
            const $button = $(e.currentTarget);
            const $results = $('#numos-r2-verify-results');
            const $summary = $('#numos-r2-verify-summary');

            $button.addClass('loading').prop('disabled', true);
            $results.show();
            $summary.html('<p>' + this.escHtml(numosR2.strings.verifying) + '</p>');

            this.fetchWithRetry({
                url: numosR2.ajaxUrl,
                type: 'POST',
                timeout: 120000,
                data: {
                    action: 'numos_r2_verify_all',
                    nonce: numosR2.nonce
                }
            }).then(function(response) {
                if (response.success) {
                    self.verifyStats = {
                        ok: response.data.ok,
                        issues: response.data.issues,
                        details: response.data.details || []
                    };
                    self.finishVerify();
                } else {
                    self.showNotice(response.data.message, 'error');
                    self.finishVerify();
                }
            }).catch(function() {
                self.showNotice(numosR2.strings.error, 'error');
                self.finishVerify();
            });
        },

        finishVerify: function() {
            this.isVerifying = false;

            var $button = $('#numos-r2-verify-files');
            var $summary = $('#numos-r2-verify-summary');

            $button.removeClass('loading').prop('disabled', false);

            var stats = this.verifyStats;
            var html = '<p><strong>' + stats.ok + '</strong> OK, <strong>' + stats.issues + '</strong> issues</p>';

            if (stats.details.length > 0) {
                html += '<ul>';
                stats.details.forEach(function(d) {
                    html += '<li>Attachment #' + d.id + ': ' + d.errors.map(function(e) {
                        return '<code>' + NumosR2Admin.escHtml(e) + '</code>';
                    }).join(', ') + '</li>';
                });
                html += '</ul>';
            }

            $summary.html(html);
        },

        // ==================== RESTORE ==================== //

        startRestore: function(e) {
            e.preventDefault();

            if (this.isRestoring) return;
            if (!confirm(numosR2.strings.confirmRestore)) return;

            this.isRestoring = true;
            this.restoreStats = { totalRestored: 0, totalErrors: 0, startTime: Date.now() };

            var $button = $('#numos-r2-restore-r2');
            var $progress = $('#numos-r2-restore-progress');
            var $log = $('#numos-r2-restore-log');
            var total = parseInt($('#numos-r2-r2only-count').text()) || 0;

            $button.addClass('loading').prop('disabled', true);
            $progress.show();
            $log.show().empty();

            $('#numos-r2-restore-bar').css('width', '0%');
            $('#numos-r2-restore-text').text('0 / ' + total);

            this.restoreStats.total = total;
            this.runRestoreBatch();
        },

        runRestoreBatch: function() {
            var self = this;

            this.fetchWithRetry({
                url: numosR2.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'numos_r2_restore_batch',
                    nonce: numosR2.nonce,
                    batch_size: 20
                }
            }, 3).then(function(response) {
                if (response.success) {
                    var data = response.data;
                    var result = data.result;

                    self.restoreStats.totalRestored += result.restored;
                    self.restoreStats.totalErrors += (result.errors ? result.errors.length : 0);

                    var done = self.restoreStats.totalRestored + self.restoreStats.totalErrors;
                    var total = self.restoreStats.total || done;
                    var pct = total > 0 ? Math.min(100, Math.round(done / total * 100)) : 0;

                    $('#numos-r2-restore-bar').css('width', pct + '%');
                    $('#numos-r2-restore-text').text(done + ' / ' + total + ' (' + pct + '%)');

                    self.appendLog('#numos-r2-restore-log',
                        'Batch: ' + result.restored + ' restored' + (result.errors && result.errors.length > 0 ? ', ' + result.errors.length + ' errors' : ''),
                        'info'
                    );

                    if (result.errors && result.errors.length > 0) {
                        result.errors.forEach(function(error) {
                            self.appendLog('#numos-r2-restore-log', error, 'error');
                        });
                    }

                    if (data.savings) {
                        $('#numos-r2-savings-freed').text(data.savings.freed_human);
                        $('#numos-r2-savings-r2only').text(data.savings.r2_only_count);
                    }

                    if (!result.done) {
                        setTimeout(function() { self.runRestoreBatch(); }, 100);
                    } else {
                        self.finishRestore();
                    }
                } else {
                    self.appendLog('#numos-r2-restore-log', response.data.message, 'error');
                    self.finishRestore();
                }
            }).catch(function(err) {
                self.appendLog('#numos-r2-restore-log', 'Request failed: ' + (err.error || 'Network error'), 'error');
                self.finishRestore();
            });
        },

        finishRestore: function() {
            var stats = this.restoreStats;
            var totalDuration = this.formatDuration(Date.now() - stats.startTime);

            this.isRestoring = false;

            $('#numos-r2-restore-bar').css('width', '100%');
            $('#numos-r2-restore-text').text(stats.totalRestored + ' restored');
            $('#numos-r2-restore-r2').removeClass('loading').prop('disabled', false);

            this.appendLog('#numos-r2-restore-log',
                'Completed in ' + totalDuration + ': ' + stats.totalRestored + ' restored',
                'success'
            );

            this.showNotice('Restored ' + stats.totalRestored + ' attachments from R2', 'success');
            this.refreshProgress();
        },

        // ==================== DELETE FROM R2 ==================== //

        startDeleteFromR2: function(e) {
            e.preventDefault();

            if (this.isDeletingFromR2) return;
            if (!confirm(numosR2.strings.confirmDeleteFromR2)) return;

            this.isDeletingFromR2 = true;

            const $button = $('#numos-r2-delete-from-r2');
            const $progress = $('#numos-r2-delete-r2-progress');
            const $log = $('#numos-r2-delete-r2-log');

            $button.addClass('loading').prop('disabled', true);
            $progress.show();
            $log.show().empty();

            this.deleteR2Stats = { totalDeleted: 0, startTime: Date.now() };
            this.runDeleteFromR2Batch('');
        },

        runDeleteFromR2Batch: function(token) {
            const self = this;

            this.fetchWithRetry({
                url: numosR2.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'numos_r2_delete_from_r2_batch',
                    nonce: numosR2.nonce,
                    token: token
                }
            }, 5).then(function(response) {
                if (response.success) {
                    const data = response.data;

                    self.deleteR2Stats.totalDeleted += data.deleted;

                    self.appendLog('#numos-r2-delete-r2-log',
                        'Deleted ' + data.deleted + ' objects from R2',
                        'info'
                    );

                    $('#numos-r2-delete-r2-text').text(
                        self.deleteR2Stats.totalDeleted + ' objects deleted...'
                    );

                    if (data.errors && data.errors.length > 0) {
                        data.errors.forEach(function(error) {
                            self.appendLog('#numos-r2-delete-r2-log', error, 'error');
                        });
                    }

                    if (data.has_more) {
                        setTimeout(function() {
                            self.runDeleteFromR2Batch(data.next_token);
                        }, 300);
                    } else {
                        self.finishDeleteFromR2();
                    }
                } else {
                    self.showNotice(response.data.message, 'error');
                    self.finishDeleteFromR2();
                }
            }).catch(function(err) {
                self.appendLog('#numos-r2-delete-r2-log', 'Request failed: ' + (err.error || 'Network error'), 'error');
                self.finishDeleteFromR2();
            });
        },

        finishDeleteFromR2: function() {
            this.isDeletingFromR2 = false;

            var $button = $('#numos-r2-delete-from-r2');
            $button.removeClass('loading').prop('disabled', true);

            var totalDuration = this.formatDuration(Date.now() - this.deleteR2Stats.startTime);
            this.appendLog('#numos-r2-delete-r2-log',
                'Completed in ' + totalDuration + ': ' + this.deleteR2Stats.totalDeleted + ' objects deleted. Metadata purged.',
                'success'
            );

            this.showNotice(
                'Deleted ' + this.deleteR2Stats.totalDeleted + ' objects from R2. Metadata has been reset.',
                'success'
            );

            // Refresh counters
            $('#numos-r2-synced-count').text('0');
            $('#numos-r2-pending-count').text($('#numos-r2-synced-count').closest('.numos-r2-cards').find('.numos-r2-stat-number').first().text());
            $('#numos-r2-r2-file-count').text('0');
            this.refreshProgress();
        },

        // ==================== SYNC FROM R2 ==================== //

        startSyncFromR2: function(e) {
            e.preventDefault();

            if (!confirm(numosR2.strings.confirmSyncFromR2)) return;

            const self = this;
            const $button = $(e.currentTarget);
            const $results = $('#numos-r2-sync-from-r2-results');
            const $summary = $('#numos-r2-sync-from-r2-summary');

            $button.addClass('loading').prop('disabled', true);
            $results.show();
            $summary.html('<p>' + this.escHtml(numosR2.strings.syncingFromR2) + '</p>');

            this.fetchWithRetry({
                url: numosR2.ajaxUrl,
                type: 'POST',
                timeout: 300000,
                data: {
                    action: 'numos_r2_sync_from_r2',
                    nonce: numosR2.nonce
                }
            }, 1).then(function(response) {
                if (response.success) {
                    var r = response.data.result;
                    var html = '<p>';
                    if (r.r2_files) {
                        html += r.r2_files + ' files found on R2. ';
                    }
                    html += '<strong>' + r.synced + '</strong> attachments synced';
                    if (r.partial > 0) {
                        html += ', <strong>' + r.partial + '</strong> partial';
                    }
                    if (r.skipped > 0) {
                        html += ', <strong>' + r.skipped + '</strong> not found on R2';
                    }
                    html += ' (of ' + r.total + ' checked)</p>';
                    $summary.html(html);

                    if (r.synced > 0) {
                        self.showNotice('Synced ' + r.synced + ' attachments from R2', 'success');
                    } else {
                        self.showNotice('No new attachments to sync from R2', 'warning');
                    }

                    self.refreshProgress();
                } else {
                    self.showNotice(response.data.message, 'error');
                    $summary.html('<p class="numos-r2-error">' + self.escHtml(response.data.message) + '</p>');
                }
            }).catch(function(err) {
                var msg = 'Request failed';
                if (err && err.status === 'timeout') {
                    msg = 'Request timed out. Your R2 bucket may be very large — try using WP-CLI: wp numos-r2 sync-from-r2';
                }
                self.showNotice(msg, 'error');
                $summary.html('<p class="numos-r2-error">' + self.escHtml(msg) + '</p>');
            }).finally(function() {
                $button.removeClass('loading').prop('disabled', false);
            });
        },

        // ==================== EXISTING HANDLERS ==================== //

        resetFailed: function(e) {
            e.preventDefault();

            const self = this;
            const $button = $(e.currentTarget);

            this.executeOnce('resetFailed', function() {
                $button.addClass('loading').prop('disabled', true);

                return self.fetchWithRetry({
                    url: numosR2.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'numos_r2_reset_failed',
                        nonce: numosR2.nonce
                    }
                }).then(function(response) {
                    if (response.success) {
                        self.showNotice(response.data.message, 'success');
                        self.updateProgress(response.data.progress);
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                }).catch(function() {
                    self.showNotice(numosR2.strings.error, 'error');
                }).finally(function() {
                    $button.removeClass('loading').prop('disabled', false);
                });
            });
        },

        refreshLogs: function(e) {
            e.preventDefault();

            const self = this;
            const $button = $(e.currentTarget);

            this.executeOnce('refreshLogs', function() {
                $button.addClass('loading').prop('disabled', true);

                return self.fetchWithRetry({
                    url: numosR2.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'numos_r2_get_logs',
                        nonce: numosR2.nonce
                    }
                }).then(function(response) {
                    if (response.success) {
                        $('#numos-r2-logs').text(response.data.logs);
                    }
                }).finally(function() {
                    $button.removeClass('loading').prop('disabled', false);
                });
            });
        },

        refreshProgress: function() {
            const self = this;

            this.fetchWithRetry({
                url: numosR2.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'numos_r2_get_progress',
                    nonce: numosR2.nonce
                }
            }).then(function(response) {
                if (response.success) {
                    self.updateProgress(response.data);
                }
            }).catch(function() {});
        },

        updateProgress: function(data) {
            // Accept both full response data ({progress, savings, ...}) and plain progress object
            var progress = data.progress || data;

            $('#numos-r2-synced-count').text(progress.synced);
            $('#numos-r2-pending-count').text(progress.pending);
            $('.numos-r2-progress-fill').css('width', progress.percentage + '%');
            $('.numos-r2-progress-text').text(progress.percentage + '%');

            if (progress.synced_size_human) {
                $('#numos-r2-synced-size').text(progress.synced_size_human);
            }

            $('#numos-r2-start-migration').prop('disabled', progress.pending === 0);

            // Update delete local section
            if (data.synced_with_local !== undefined) {
                $('#numos-r2-local-eligible').text(data.synced_with_local);
                $('#numos-r2-delete-local').prop('disabled', data.synced_with_local === 0);
            }

            // Update savings & restore section
            if (data.savings) {
                $('#numos-r2-savings-size').text(data.savings.human_size);
                $('#numos-r2-savings-r2only').text(data.savings.r2_only_count);
                $('#numos-r2-r2only-count').text(data.savings.r2_only_count);
                $('#numos-r2-restore-r2').prop('disabled', data.savings.r2_only_count === 0);
            }
        },

        saveSettings: function(e) {
            e.preventDefault();

            const self = this;
            const $form = $(e.currentTarget);
            const $button = $form.find('button[type="submit"]');

            this.executeOnce('saveSettings', function() {
                $button.addClass('loading').prop('disabled', true);

                return self.fetchWithRetry({
                    url: numosR2.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'numos_r2_save_settings',
                        nonce: numosR2.nonce,
                        enabled: $form.find('[name="enabled"]').is(':checked') ? 1 : 0,
                        sync_new_uploads: $form.find('[name="sync_new_uploads"]').is(':checked') ? 1 : 0,
                        remove_local_files: $form.find('[name="remove_local_files"]').is(':checked') ? 1 : 0,
                        log_level: $form.find('[name="log_level"]').val(),
                        batch_size: $form.find('[name="batch_size"]').val()
                    }
                }).then(function(response) {
                    if (response.success) {
                        self.showNotice(response.data.message, 'success');
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                }).catch(function() {
                    self.showNotice(numosR2.strings.error, 'error');
                }).finally(function() {
                    $button.removeClass('loading').prop('disabled', false);
                });
            });
        },

        // ==================== UI HELPERS ==================== //

        showNotice: function(message, type) {
            $('.numos-r2-notice').remove();

            var iconMap = { success: '&#10003;', error: '&#10007;', warning: '&#9888;' };
            var icon = iconMap[type] || iconMap.warning;

            var $notice = $('<div>', {
                'class': 'numos-r2-notice ' + type,
                'role': 'alert'
            });
            $notice.append(
                $('<span>', { 'aria-hidden': 'true' }).html(icon),
                document.createTextNode(' ' + message)
            );

            $('.numos-r2-admin h1').after($notice);

            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        appendLog: function(selector, message, type) {
            var $log = $(selector);
            if (!$log.length) return;

            var timestamp = new Date().toLocaleTimeString();
            var prefix = type === 'error' ? '[ERROR]' :
                           type === 'success' ? '[OK]' :
                           type === 'warning' ? '[WARN]' : '[INFO]';

            $log.append(document.createTextNode(timestamp + ' ' + prefix + ' ' + message + '\n'));
            $log.scrollTop($log[0].scrollHeight);
        },

        purgeMeta: function(e) {
            e.preventDefault();

            if (!confirm(numosR2.strings.confirmPurge)) return;

            const self = this;
            const $button = $(e.currentTarget);

            this.executeOnce('purgeMeta', function() {
                $button.addClass('loading').prop('disabled', true);

                return self.fetchWithRetry({
                    url: numosR2.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'numos_r2_purge_meta',
                        nonce: numosR2.nonce
                    }
                }).then(function(response) {
                    if (response.success) {
                        self.showNotice(response.data.message, 'success');
                        self.updateProgress(response.data.progress);
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                }).catch(function() {
                    self.showNotice(numosR2.strings.error, 'error');
                }).finally(function() {
                    $button.removeClass('loading').prop('disabled', false);
                });
            });
        },

        // ==================== ORPHAN SCANNING ==================== //

        scanOrphans: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const $results = $('#numos-r2-orphan-results');
            const $list = $('#numos-r2-orphan-list');
            const $summary = $('#numos-r2-orphan-summary');
            const $deleteBtn = $('#numos-r2-delete-orphans');

            $button.addClass('loading').prop('disabled', true);
            $results.show();
            $list.empty();
            $summary.html('<p>' + this.escHtml(numosR2.strings.scanning) + '</p>');
            $deleteBtn.hide();

            this.orphanFiles = [];
            this.scanOrphansBatch('');
        },

        scanOrphansBatch: function(token) {
            const self = this;

            this.fetchWithRetry({
                url: numosR2.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'numos_r2_list_orphans',
                    nonce: numosR2.nonce,
                    token: token
                }
            }).then(function(response) {
                if (response.success) {
                    const data = response.data;
                    data.orphans.forEach(function(orphan) {
                        self.orphanFiles.push(orphan);
                    });

                    if (data.has_more) {
                        $('#numos-r2-orphan-summary').html(
                            '<p>' + self.escHtml(numosR2.strings.scanning) + ' (' + self.orphanFiles.length + ' found...)</p>'
                        );
                        self.scanOrphansBatch(data.next_token);
                    } else {
                        self.displayOrphans();
                    }
                } else {
                    self.showNotice(response.data.message, 'error');
                    $('#numos-r2-scan-orphans').removeClass('loading').prop('disabled', false);
                }
            }).catch(function() {
                self.showNotice(numosR2.strings.error, 'error');
                $('#numos-r2-scan-orphans').removeClass('loading').prop('disabled', false);
            });
        },

        displayOrphans: function() {
            const self = this;
            const $button = $('#numos-r2-scan-orphans');
            const $list = $('#numos-r2-orphan-list');
            const $summary = $('#numos-r2-orphan-summary');
            const $deleteBtn = $('#numos-r2-delete-orphans');

            $button.removeClass('loading').prop('disabled', false);

            if (this.orphanFiles.length === 0) {
                $summary.html('<p class="numos-r2-success">' + this.escHtml(numosR2.strings.noOrphans) + '</p>');
                return;
            }

            let totalSize = 0;
            this.orphanFiles.forEach(function(f) { totalSize += f.size; });

            $summary.html(
                '<p><strong>' + this.orphanFiles.length + ' orphan files found</strong> (' +
                this.escHtml(this.formatSize(totalSize)) + ')</p>' +
                '<p><label><input type="checkbox" id="numos-r2-select-all-orphans"> Select all</label></p>'
            );

            var html = '<table class="widefat"><thead><tr>' +
                '<th style="width:30px"><input type="checkbox" id="numos-r2-select-all-orphans-table" aria-label="Select all"></th>' +
                '<th>File</th><th>Size</th></tr></thead><tbody>';

            this.orphanFiles.forEach(function(file, index) {
                html += '<tr>' +
                    '<td><input type="checkbox" class="numos-r2-orphan-check" data-index="' + index + '"></td>' +
                    '<td><code>' + self.escHtml(file.key) + '</code></td>' +
                    '<td>' + self.escHtml(file.size_human) + '</td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            $list.html(html);
            $deleteBtn.show();

            $('#numos-r2-select-all-orphans, #numos-r2-select-all-orphans-table').on('change', function() {
                $('.numos-r2-orphan-check').prop('checked', $(this).is(':checked'));
            });
        },

        deleteOrphans: function(e) {
            e.preventDefault();

            const selected = [];
            const self = this;

            $('.numos-r2-orphan-check:checked').each(function() {
                const index = $(this).data('index');
                selected.push(self.orphanFiles[index].key);
            });

            if (selected.length === 0) {
                this.showNotice('Please select files to delete', 'warning');
                return;
            }

            if (!confirm(numosR2.strings.confirmDeleteOrphans)) return;

            const $button = $(e.currentTarget);

            this.executeOnce('deleteOrphans', function() {
                $button.addClass('loading').prop('disabled', true);

                return self.fetchWithRetry({
                    url: numosR2.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'numos_r2_delete_orphans',
                        nonce: numosR2.nonce,
                        keys: selected
                    }
                }).then(function(response) {
                    if (response.success) {
                        self.showNotice(response.data.message, 'success');
                        self.orphanFiles = self.orphanFiles.filter(function(f) {
                            return selected.indexOf(f.key) === -1;
                        });
                        self.displayOrphans();
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                }).catch(function() {
                    self.showNotice(numosR2.strings.error, 'error');
                }).finally(function() {
                    $button.removeClass('loading').prop('disabled', false);
                });
            });
        },

        formatSize: function(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        scanDbOrphans: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const $results = $('#numos-r2-db-orphan-results');
            const $list = $('#numos-r2-db-orphan-list');
            const $summary = $('#numos-r2-db-orphan-summary');
            const $deleteBtn = $('#numos-r2-delete-db-orphans');

            $button.addClass('loading').prop('disabled', true);
            $results.show();
            $list.empty();
            $summary.html('<p>' + this.escHtml(numosR2.strings.scanningDb) + '</p>');
            $deleteBtn.hide();

            const self = this;
            this.dbOrphanEntries = [];

            this.fetchWithRetry({
                url: numosR2.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'numos_r2_scan_db_orphans',
                    nonce: numosR2.nonce
                }
            }).then(function(response) {
                if (response.success) {
                    self.dbOrphanEntries = response.data.orphans;
                    self.displayDbOrphans();
                } else {
                    self.showNotice(response.data.message, 'error');
                }
            }).catch(function() {
                self.showNotice(numosR2.strings.error, 'error');
            }).finally(function() {
                $button.removeClass('loading').prop('disabled', false);
            });
        },

        displayDbOrphans: function() {
            const self = this;
            const $list = $('#numos-r2-db-orphan-list');
            const $summary = $('#numos-r2-db-orphan-summary');
            const $deleteBtn = $('#numos-r2-delete-db-orphans');

            if (this.dbOrphanEntries.length === 0) {
                $summary.html('<p class="numos-r2-success">' + this.escHtml(numosR2.strings.noDbOrphans) + '</p>');
                return;
            }

            $summary.html(
                '<p><strong>' + this.dbOrphanEntries.length + ' ' + this.escHtml(numosR2.strings.dbOrphansFound) + '</strong></p>' +
                '<p><label><input type="checkbox" id="numos-r2-select-all-db-orphans"> ' + this.escHtml(numosR2.strings.selectAll) + '</label></p>'
            );

            var html = '<table class="widefat"><thead><tr>' +
                '<th style="width:30px"><input type="checkbox" id="numos-r2-select-all-db-orphans-table" aria-label="Select all"></th>' +
                '<th>ID</th><th>' + this.escHtml(numosR2.strings.title) + '</th><th>' + this.escHtml(numosR2.strings.type) + '</th></tr></thead><tbody>';

            this.dbOrphanEntries.forEach(function(entry, index) {
                html += '<tr>' +
                    '<td><input type="checkbox" class="numos-r2-db-orphan-check" data-index="' + index + '"></td>' +
                    '<td>' + self.escHtml(entry.id) + '</td>' +
                    '<td>' + self.escHtml(entry.title) + '</td>' +
                    '<td><code>' + self.escHtml(entry.mime_type) + '</code></td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            $list.html(html);
            $deleteBtn.show();

            $('#numos-r2-select-all-db-orphans, #numos-r2-select-all-db-orphans-table').on('change', function() {
                $('.numos-r2-db-orphan-check').prop('checked', $(this).is(':checked'));
            });
        },

        deleteDbOrphans: function(e) {
            e.preventDefault();

            const selected = [];
            const self = this;

            $('.numos-r2-db-orphan-check:checked').each(function() {
                const index = $(this).data('index');
                selected.push(self.dbOrphanEntries[index].id);
            });

            if (selected.length === 0) {
                this.showNotice(numosR2.strings.selectEntries, 'warning');
                return;
            }

            if (!confirm(numosR2.strings.confirmDeleteDbOrphans)) return;

            const $button = $(e.currentTarget);

            this.executeOnce('deleteDbOrphans', function() {
                $button.addClass('loading').prop('disabled', true);

                return self.fetchWithRetry({
                    url: numosR2.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'numos_r2_delete_db_orphans',
                        nonce: numosR2.nonce,
                        ids: selected
                    }
                }).then(function(response) {
                    if (response.success) {
                        self.showNotice(response.data.message, 'success');
                        if (response.data.progress) {
                            self.updateProgress(response.data.progress);
                        }
                        self.dbOrphanEntries = self.dbOrphanEntries.filter(function(entry) {
                            return selected.indexOf(entry.id) === -1;
                        });
                        self.displayDbOrphans();
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                }).catch(function() {
                    self.showNotice(numosR2.strings.error, 'error');
                }).finally(function() {
                    $button.removeClass('loading').prop('disabled', false);
                });
            });
        }
    };

    $(document).ready(function() {
        NumosR2Admin.init();
    });

})(jQuery);
