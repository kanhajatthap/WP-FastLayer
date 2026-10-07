(function ($) {
    'use strict';

    /*
     * The localized settings object is only printed by
     * Admin::enqueue_admin_assets(), and this script is also enqueued from the
     * admin bar and the front end of the site. Reading it through a safe
     * accessor keeps a missing localization from aborting every handler below.
     * It must not be named wpFastLayerAdmin locally: a var declaration is
     * hoisted, so the typeof guard above would then read the hoisted but still
     * unassigned local instead of the global.
     */
    var settings = (typeof wpFastLayerAdmin !== 'undefined' && wpFastLayerAdmin) ? wpFastLayerAdmin : {};

    $(document).ready(function () {
        $(document).on('click', '.wp_fastlayer_dismiss_nginx_notice', function (event) {
            event.preventDefault();
            var $notice = $(this).closest('.notice');
            var nonce = $(this).data('nonce') || '';
            if (nonce) {
                $.post(ajaxurl, {
                    action: 'wp_fastlayer_dismiss_nginx_webp_notice',
                    nonce: nonce
                });
            }
            $notice.remove();
        });

        $(document).on('click', '.wpfl-copy-example-btn', function (event) {
            event.preventDefault();

            var $button = $(this);
            var textToCopy = $button.data('copy-text') || '';
            var defaultText = settings.copy_text || 'Copy Example';
            var copiedText = settings.copied_text || 'Copied';
            var failedText = settings.copy_failed_text || 'Copy failed';

            if (!textToCopy) {
                return;
            }

            var updateButtonText = function (text) {
                $button.text(text);
                window.setTimeout(function () {
                    $button.text(defaultText);
                }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(function () {
                    updateButtonText(copiedText);
                }).catch(function () {
                    updateButtonText(failedText);
                });

                return;
            }

            var $tempInput = $('<input type="text" readonly>').val(textToCopy).appendTo('body');
            $tempInput[0].select();
            try {
                document.execCommand('copy');
                updateButtonText(copiedText);
            } catch (error) {
                updateButtonText(failedText);
            }
            $tempInput.remove();
        });

        var $imagekitPreview = $('#wpfl-imagekit-preview');
        if ($imagekitPreview.length) {
            var $endpointInput = $('#imagekit_endpoint');
            var $modeInput = $('#imagekit_origin_path_mode');
            var $previewOriginal = $('#wpfl-imagekit-preview-original');
            var $previewRewritten = $('#wpfl-imagekit-preview-rewritten');

            var uploadsPath = (settings.imagekit_uploads_path || '/wp-content/uploads').trim();
            uploadsPath = '/' + uploadsPath.replace(/^\/+|\/+$/g, '');

            var normalizeEndpoint = function (value) {
                var endpoint = (value || '').trim();
                if (!endpoint) {
                    return '';
                }
                if (!/^https?:\/\//i.test(endpoint)) {
                    endpoint = 'https://' + endpoint.replace(/^\/+/, '');
                }
                return endpoint.replace(/\/+$/, '');
            };

            var extractPath = function (urlValue) {
                var value = (urlValue || '').trim();
                if (!value) {
                    return '';
                }

                try {
                    var parsedUrl = new URL(value, window.location.origin);
                    return parsedUrl.pathname || '';
                } catch (error) {
                    var noProtocol = value.replace(/^https?:\/\/[^/]+/i, '');
                    return noProtocol.charAt(0) === '/' ? noProtocol : '/' + noProtocol;
                }
            };

            var normalizePath = function (pathValue) {
                var path = (pathValue || '').replace(/\/+/g, '/');
                return '/' + path.replace(/^\/+/, '');
            };

            var buildPreviewUrl = function () {
                var endpoint = normalizeEndpoint($endpointInput.val());
                if (!endpoint) {
                    return settings.imagekit_preview_missing_endpoint || '';
                }

                var mode = ($modeInput.val() || 'full_uploads_path').trim();
                var originalUrl = $imagekitPreview.data('original-url') || $previewOriginal.text() || '';
                var originalPath = normalizePath(extractPath(originalUrl));

                if (!originalPath || originalPath.indexOf(uploadsPath) !== 0) {
                    return settings.imagekit_preview_invalid || '';
                }

                var relativePath = originalPath.slice(uploadsPath.length).replace(/^\/+/, '');
                var rewrittenPath = mode === 'uploads_relative_path'
                    ? relativePath
                    : originalPath.replace(/^\/+/, '');

                if (!rewrittenPath) {
                    return settings.imagekit_preview_invalid || '';
                }

                return endpoint + '/' + rewrittenPath + '?tr=fm-auto,q-auto,w-400';
            };

            var renderPreview = function () {
                $previewRewritten.text(buildPreviewUrl());
            };

            $endpointInput.on('input change', renderPreview);
            $modeInput.on('change', renderPreview);
            renderPreview();
        }

        // High-risk confirmation removed: submit handlers no longer block or prompt.

        var $dbCleanupForm = $('#wpfl-db-cleanup-form');
        var $dbCleanupButton = $('#wpfl-db-cleanup-submit');
        var $dbCleanupFallbackInputs = $('.wpfl-db-cleanup-fallback');
        var $dbCleanupModal = $('#wpfl-db-cleanup-modal');
        var $dbCleanupModalTitle = $('#wpfl-db-cleanup-modal-title');
        var $dbCleanupModalStatus = $('#wpfl-db-cleanup-modal-status');
        var $dbCleanupModalProgress = $('.wpfl-db-cleanup-modal-progress');
        var $dbCleanupModalRows = $('.wpfl-db-cleanup-modal-rows-removed');
        var $dbCleanupModalResults = $('.wpfl-db-cleanup-modal-results');
        var $dbCleanupModalResultList = $('.wpfl-db-cleanup-modal-results ul');
        var $dbCleanupModalErrors = $('.wpfl-db-cleanup-modal-errors');
        var $dbCleanupModalClose = $('.wpfl-db-cleanup-modal-close');
        var $dbCleanupModalRetry = $('.wpfl-db-cleanup-modal-retry');
        var dbCleanupRunning = false;

        /*
         * The database cleanup toggles live in the settings form while the
         * cleanup form posts to admin-post.php, so the selection is mirrored
         * into hidden cleanup_items inputs. Keeping them in sync here also makes
         * the non JavaScript fallback submit the right selection.
         */
        $('.wpfl-db-cleanup-toggle').on('change', function () {
            var cleanupKey = $(this).attr('data-cleanup-key');
            if (!cleanupKey) {
                return;
            }

            $dbCleanupFallbackInputs
                .filter('[data-cleanup-key="' + cleanupKey + '"]')
                .val($(this).is(':checked') ? '1' : '0');
        });

        $('#wpfl-clean-all-toggle').on('change', function () {
            $('.wpfl-db-cleanup-toggle').prop('checked', $(this).is(':checked')).trigger('change');
        });

        if ($dbCleanupForm.length && $dbCleanupButton.length) {
            var defaultCleanupText = settings.db_cleanup_default || 'Clean Now';
            var processingCleanupText = settings.db_cleanup_processing || 'Cleaning...';
            var cleanupAjaxUrl = settings.ajax_url || '';
            var cleanupNonce = settings.db_cleanup_nonce || '';
            var cleanupAction = settings.db_cleanup_action || 'wp_fastlayer_database_cleanup';
            var canUseAjax = !!cleanupAjaxUrl && !!cleanupNonce;

            var formatCleanupCount = function (value) {
                var numeric = parseInt(value, 10);
                if (isNaN(numeric) || numeric < 0) {
                    numeric = 0;
                }

                try {
                    return numeric.toLocaleString();
                } catch (error) {
                    return String(numeric);
                }
            };

            var setModalState = function (state) {
                if (!$dbCleanupModalProgress.length) {
                    return;
                }

                $dbCleanupModalProgress.attr('data-state', state);
            };

            var setModalStatus = function (text) {
                $dbCleanupModalStatus.text(text);
            };

            var openModal = function () {
                $dbCleanupModalTitle.text(settings.db_cleanup_modal_title || 'Database cleanup in progress');
                setModalStatus(settings.db_cleanup_modal_starting || 'Starting database cleanup...');
                setModalState('running');
                $dbCleanupModalRows.prop('hidden', true).text('');
                $dbCleanupModalResults.prop('hidden', true);
                $dbCleanupModalResultList.empty();
                $dbCleanupModalErrors.prop('hidden', true).empty();
                $dbCleanupModalClose.prop('hidden', true);
                $dbCleanupModalRetry.prop('hidden', true);
                $dbCleanupModal.prop('hidden', false).addClass('is-open');
                $('body').addClass('wpfl-db-cleanup-modal-open');
            };

            var closeModal = function () {
                $dbCleanupModal.prop('hidden', true).removeClass('is-open');
                $('body').removeClass('wpfl-db-cleanup-modal-open');
            };

            var applyStatistics = function (statistics) {
                var counts = statistics && statistics.counts ? statistics.counts : {};
                var transients = statistics && statistics.transients ? statistics.transients : null;

                Object.keys(counts).forEach(function (cleanupKey) {
                    var $count = $('.wpfl-db-card[data-cleanup-key="' + cleanupKey + '"] .wpfl-db-count');
                    if (!$count.length) {
                        return;
                    }

                    $count.text(formatCleanupCount(counts[cleanupKey]));
                });

                if (!transients) {
                    return;
                }

                $('[data-transient-breakdown]').each(function () {
                    var template = $(this).attr('data-transient-breakdown') || '';
                    if (!template) {
                        return;
                    }

                    var text = template
                        .replace('%timeout%', formatCleanupCount(transients.timeout_rows))
                        .replace('%active%', formatCleanupCount(transients.active_runtime_rows))
                        .replace('%expired%', formatCleanupCount(transients.expired_timeout_rows))
                        .replace('%no_timeout%', formatCleanupCount(transients.runtime_no_timeout_rows))
                        .replace('%orphans%', formatCleanupCount(transients.orphan_timeout_rows));

                    $(this).text(text);
                });
            };

            var renderResultList = function (result) {
                var entries = result && result.stats ? result.stats : [];

                if (!$.isArray(entries) || !entries.length) {
                    $dbCleanupModalResults.prop('hidden', true);
                    return;
                }

                $dbCleanupModalResultList.empty();

                entries.forEach(function (entry) {
                    if (!entry || !entry.label) {
                        return;
                    }

                    $('<li>').text((entry.label + ': ' + formatCleanupCount(entry.count))).appendTo($dbCleanupModalResultList);
                });

                $dbCleanupModalResults.prop('hidden', false);
            };

            var renderErrors = function (message, errors) {
                var messages = [];

                if (message) {
                    messages.push(message);
                }

                if ($.isArray(errors)) {
                    errors.forEach(function (error) {
                        if (error) {
                            messages.push(error);
                        }
                    });
                }

                if (!messages.length) {
                    $dbCleanupModalErrors.prop('hidden', true).empty();
                    return;
                }

                $dbCleanupModalErrors.empty();

                messages.forEach(function (text) {
                    $('<p>').text(text).appendTo($dbCleanupModalErrors);
                });

                $dbCleanupModalErrors.prop('hidden', false);
            };

            var finishRunning = function () {
                dbCleanupRunning = false;
                $dbCleanupForm.data('processing', false);
                $dbCleanupButton.prop('disabled', false).removeClass('is-loading');
                $dbCleanupButton.find('.wpfl-db-cleanup-btn-text').text(defaultCleanupText);
            };

            var showSuccess = function (payload) {
                var data = payload && payload.data ? payload.data : {};
                var result = data.result || {};

                $dbCleanupModalTitle.text(settings.db_cleanup_modal_success_title || 'Database cleanup complete');
                setModalState('success');

                if (result.rows_removed !== undefined) {
                    $dbCleanupModalRows
                        .text((settings.db_cleanup_modal_rows_removed || 'Records removed: %s').replace('%s', formatCleanupCount(result.rows_removed)))
                        .prop('hidden', false);
                }

                renderResultList(result);
                setModalStatus(settings.db_cleanup_modal_stats_refreshed || 'All cleanup counters now reflect fresh database queries.');
                $dbCleanupModalClose.prop('hidden', false);
                $dbCleanupModalRetry.prop('hidden', true);
                finishRunning();
            };

            var showError = function (payload, fallbackMessage) {
                var data = payload && payload.data ? payload.data : {};

                $dbCleanupModalTitle.text(settings.db_cleanup_modal_error_title || 'Database cleanup failed');
                setModalState('error');
                renderErrors(data.message || fallbackMessage, data.errors);
                setModalStatus(data.message || settings.db_cleanup_modal_error_title || 'Database cleanup failed');
                $dbCleanupModalClose.prop('hidden', false);
                $dbCleanupModalRetry.prop('hidden', false);
                finishRunning();
            };

            var requestCleanup = function () {
                var $form = $dbCleanupForm;
                var selection = {};

                $('.wpfl-db-cleanup-toggle').each(function () {
                    var cleanupKey = $(this).attr('data-cleanup-key');
                    if (cleanupKey) {
                        selection[cleanupKey] = $(this).is(':checked') ? '1' : '0';
                    }
                });

                openModal();

                $.ajax({
                    url: cleanupAjaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: cleanupAction,
                        nonce: cleanupNonce,
                        cleanup_items: selection
                    }
                }).done(function (payload) {
                    var data = payload && payload.data ? payload.data : {};

                    if (payload && payload.success) {
                        if (data.statistics) {
                            applyStatistics(data.statistics);
                        }

                        showSuccess(payload);
                        return;
                    }

                    if (data.statistics) {
                        applyStatistics(data.statistics);
                    }

                    showError(payload, '');
                }).fail(function (xhr) {
                    var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;

                    if (response && response.data && response.data.statistics) {
                        applyStatistics(response.data.statistics);
                    }

                    showError(response, '');
                });
            };

            $dbCleanupForm.on('submit', function (event) {
                if (!canUseAjax) {
                    /*
                     * Without the localized AJAX endpoint the server rendered
                     * fallback inputs are posted as a normal admin-post request
                     * so the cleanup still works without JavaScript.
                     */
                    $dbCleanupFallbackInputs.each(function () {
                        var cleanupKey = $(this).attr('data-cleanup-key');
                        var $toggle = $('.wpfl-db-cleanup-toggle[data-cleanup-key="' + cleanupKey + '"]');
                        $(this).val($toggle.length ? ($toggle.is(':checked') ? '1' : '0') : '0');
                    });

                    return;
                }

                event.preventDefault();

                if (dbCleanupRunning) {
                    return;
                }

                dbCleanupRunning = true;
                $dbCleanupForm.data('processing', true);
                $dbCleanupButton.prop('disabled', true).addClass('is-loading');
                $dbCleanupButton.find('.wpfl-db-cleanup-btn-text').text(processingCleanupText);

                setModalStatus(settings.db_cleanup_modal_running || 'Removing selected records and optimizing tables. Please keep this tab open.');
                requestCleanup();
            });

            $dbCleanupModalClose.on('click', function () {
                closeModal();
            });

            $dbCleanupModalRetry.on('click', function () {
                closeModal();
                $dbCleanupButton.trigger('click');
            });

            $dbCleanupModal.on('click', function (event) {
                if (event.target === this) {
                    closeModal();
                }
            });

            $(document).on('keydown', function (event) {
                if (event.key === 'Escape' && dbCleanupRunning === false && $dbCleanupModal.hasClass('is-open')) {
                    closeModal();
                }
            });
        }

        $(document).on('click', '.wp-fastlayer-clear-cache-action, .wp-fastlayer-preload-cache-action', function (event) {
            var $button = $(this);
            if ($button.data('wpfl-loading') === true) {
                event.preventDefault();
                return;
            }

            $button.data('wpfl-loading', true);
            $button.addClass('is-loading');
        });

        var $webpButton = $('#wp-fastlayer-bulk-webp-start');
        if ($webpButton.length) {
            initWebpBulkProgress($webpButton);
        }

        function initWebpBulkProgress($button) {
            var $pauseButton = $('#wp-fastlayer-bulk-webp-pause');
            var $cancelButton = $('#wp-fastlayer-bulk-webp-cancel');
            var $progressWrapper = $('#wpfl-webp-bulk-progress');
            var $progressBar = $('#wpfl-webp-bulk-bar');
            var $percentage = $('#wpfl-webp-progress-percentage');
            var $message = $('#wpfl-webp-bulk-message');
            var $batchProgress = $('#wpfl-webp-batch-progress');
            var $logArea = $('#wpfl-webp-bulk-log');
            var $summary = $('#wpfl-webp-bulk-summary');
            var $notice = $('#wpfl-webp-bulk-notice');
            var $statusBadge = $('#wpfl-webp-status-badge');
            var $spinner = $('#wpfl-webp-spinner');
            var $summaryBox = $('#wpfl-webp-summary-box');
            var $progressCount = $('#wpfl-webp-progress-count');
            var $summaryRefresh = $('#wpfl-webp-summary-refresh');
            var $summaryDescription = $('#wpfl-webp-summary-description');
            var $summaryTotal = $('#wpfl-webp-summary-total');
            var $summaryConverted = $('#wpfl-webp-summary-converted');
            var $summaryRemaining = $('#wpfl-webp-summary-remaining');
            var $summaryPercentage = $('#wpfl-webp-summary-percentage');
            var $summaryLastRun = $('#wpfl-webp-summary-last-run');
            var $summaryLastDuration = $('#wpfl-webp-summary-last-duration');
            var $summaryLastStatus = $('#wpfl-webp-summary-last-status');
            var $total = $('#wpfl-webp-total');
            var $processed = $('#wpfl-webp-processed');
            var $currentBatch = $('#wpfl-webp-current-batch');
            var $converted = $('#wpfl-webp-converted');
            var $failed = $('#wpfl-webp-failed');
            var $remaining = $('#wpfl-webp-remaining');
            var $eta = $('#wpfl-webp-eta');
            var $toggleLogsButton = $('#wpfl-webp-toggle-logs');

            var page = 1;
            var batchSize = parseInt($button.data('batch-size'), 10) || 20;
            var total = 0;
            var processed = 0;
            var converted = 0;
            var failed = 0;
            var activeRequest = null;
            var isCancelled = false;
            var isPaused = false;
            var detailedMode = false;
            var runStartedAt = 0;

            function formatTemplate(template, replacements) {
                var output = template || '';
                $.each(replacements, function (token, value) {
                    output = output.replace(new RegExp(token, 'g'), String(value));
                });
                return output;
            }

            function setStatus(type, label) {
                $statusBadge.removeClass('wpfl-webp-status-idle wpfl-webp-status-running wpfl-webp-status-paused wpfl-webp-status-completed wpfl-webp-status-error wpfl-webp-status-cancelled');
                $statusBadge.addClass('wpfl-webp-status-' + type).text(label);
            }

            function toggleSpinner(show) {
                $spinner.toggleClass('is-active', show);
            }

            function toggleCancelButton(show) {
                if (!$cancelButton.length) {
                    return;
                }

                if (show) {
                    $cancelButton.text(settings.bulk_cancel_text || 'Cancel Conversion').show();
                } else {
                    $cancelButton.hide();
                }
            }

            function setMessage(text) {
                $message.text(text);
            }

            function setSummary(remainingCount) {
                $summary.text(formatTemplate(
                    settings.bulk_summary_template || '%converted% converted, %failed% failed, %remaining% remaining.',
                    {
                        '%converted%': converted,
                        '%failed%': failed,
                        '%remaining%': remainingCount
                    }
                ));
            }

            function showNotice(type, text) {
                $notice.removeClass('wpfl-webp-notice-success wpfl-webp-notice-error wpfl-webp-notice-warning');
                $notice.addClass('wpfl-webp-notice-' + type).text(text).show();
            }

            function clearNotice() {
                $notice.hide().text('').removeClass('wpfl-webp-notice-success wpfl-webp-notice-error wpfl-webp-notice-warning');
            }

            function appendLog(message) {
                if (!detailedMode || !$logArea.length) {
                    return;
                }
                var $entry = $('<div class="wpfl-webp-bulk-log-entry"></div>').text(message);
                $logArea.append($entry);
                $logArea.show();
                $logArea.scrollTop($logArea.prop('scrollHeight'));
            }

            function setBatchProgress(page, processedInBatch, batchTotal, totalImages, overallProcessed) {
                if (!$batchProgress.length) {
                    return;
                }

                var batchCount = totalImages > 0 ? Math.ceil(totalImages / batchSize) : 0;
                var currentImage = overallProcessed > 0 ? overallProcessed : 0;
                var status = formatTemplate(
                    settings.bulk_status_template || 'Processing batch %page% of %count% — Converting image %current% of %total%',
                    {
                        '%page%': page,
                        '%count%': batchCount || page,
                        '%current%': currentImage,
                        '%total%': totalImages || batchTotal
                    }
                );

                $batchProgress.text(status);
                updateETA(totalImages, overallProcessed);
            }

            function updateETA(totalImages, overallProcessed) {
                if (!$eta.length) {
                    return;
                }

                if (!runStartedAt || overallProcessed <= 0 || !totalImages || totalImages <= overallProcessed) {
                    $eta.text(settings.bulk_eta_unavailable || 'ETA unavailable until conversion starts.');
                    return;
                }

                var elapsed = Math.max(1, Math.round((Date.now() - runStartedAt) / 1000));
                var remaining = Math.max(0, totalImages - overallProcessed);
                var avgPerImage = elapsed / overallProcessed;
                var remainingSeconds = Math.round(remaining * avgPerImage);
                $eta.text(formatTemplate(settings.bulk_eta_template || 'Estimated time remaining: %eta%', {
                    '%eta%': formatDuration(remainingSeconds)
                }));
            }

            function formatDuration(seconds) {
                seconds = Math.max(0, parseInt(seconds, 10) || 0);
                var hours = Math.floor(seconds / 3600);
                var minutes = Math.floor((seconds % 3600) / 60);
                var secs = seconds % 60;
                if (hours > 0) {
                    return hours + 'h ' + minutes + 'm';
                }
                if (minutes > 0) {
                    return minutes + 'm ' + secs + 's';
                }
                return secs + 's';
            }

            function togglePauseButton(show, paused) {
                if (!$pauseButton.length) {
                    return;
                }

                if (!show) {
                    $pauseButton.hide();
                    return;
                }

                $pauseButton.text(paused ? (settings.bulk_resume_text || 'Resume Conversion') : (settings.bulk_pause_text || 'Pause Conversion'));
                $pauseButton.show();
            }

            function toggleLogArea(show) {
                if (!$logArea.length) {
                    return;
                }
                if (show && detailedMode) {
                    $logArea.show();
                } else {
                    $logArea.hide();
                }
            }

            function setDetailedMode(show) {
                detailedMode = !!show;
                if (!$toggleLogsButton.length) {
                    return;
                }

                $toggleLogsButton.text(detailedMode ? (settings.bulk_hide_logs_text || 'Hide Detailed Logs') : (settings.bulk_view_logs_text || 'View Detailed Logs'));
                toggleLogArea(detailedMode);
                if (detailedMode && $logArea.children().length === 0) {
                    appendLog(settings.bulk_log_started || 'WebP conversion started. Processing batches in the background.');
                }
            }

            function updateSummaryBox(data, stateText) {
                if (!$summaryBox.length || !data) {
                    return;
                }

                $summaryBox.attr('data-total', data.total || 0);
                $summaryBox.attr('data-converted', data.converted || 0);
                $summaryBox.attr('data-remaining', data.remaining || 0);
                $summaryBox.attr('data-percentage', data.percentage || 0);
                $summaryBox.attr('data-last-run-at', data.last_run_at || '');
                $summaryBox.attr('data-last-run-duration', data.last_run_duration || '');
                $summaryBox.attr('data-last-run-status', data.last_run_status || '');

                $summaryTotal.text(data.total || 0);
                $summaryConverted.text(data.converted || 0);
                $summaryRemaining.text(data.remaining || 0);
                $summaryPercentage.text((data.percentage || 0) + '%');
                $summaryLastRun.text(data.last_run_at || '');
                $summaryLastDuration.text(data.last_run_duration || '');
                $summaryLastStatus.text(data.last_run_status || '');

                if ($summaryDescription.length) {
                    $summaryDescription.text(data.resume_message || formatTemplate(
                        settings.webp_summary_coverage || '%percent%% of eligible images already have WebP files.',
                        {
                            '%percent%': data.percentage || 0
                        }
                    ));
                }

                if ($summaryRefresh.length) {
                    $summaryRefresh.text(stateText || settings.webp_summary_updated || 'Conversion summary updated.');
                }
            }

            function fetchSummary(stateText) {
                if (!$summaryBox.length) {
                    return $.Deferred().resolve().promise();
                }

                if ($summaryRefresh.length) {
                    $summaryRefresh.text(settings.webp_summary_loading || 'Loading conversion summary...');
                }

                return $.post(
                    settings.ajax_url,
                    {
                        action: 'wp_fastlayer_webp_summary',
                        nonce: settings.nonce
                    }
                ).done(function (response) {
                    if (!response || !response.success || !response.data) {
                        if ($summaryRefresh.length) {
                            $summaryRefresh.text(settings.webp_summary_error || 'Unable to load current conversion summary.');
                        }
                        return;
                    }

                    updateSummaryBox(response.data, stateText || settings.webp_summary_updated || 'Conversion summary updated.');
                }).fail(function () {
                    if ($summaryRefresh.length) {
                        $summaryRefresh.text(settings.webp_summary_error || 'Unable to load current conversion summary.');
                    }
                });
            }

            function saveLastRun(status) {
                var duration = runStartedAt ? Math.max(0, Math.round((Date.now() - runStartedAt) / 1000)) : 0;

                return $.post(
                    settings.ajax_url,
                    {
                        action: 'wp_fastlayer_webp_last_run',
                        nonce: settings.nonce,
                        status: status,
                        duration: duration
                    }
                ).done(function (response) {
                    if (response && response.success && response.data) {
                        updateSummaryBox(response.data, settings.webp_summary_updated || 'Conversion summary updated.');
                    }
                });
            }

            function updateStats(serverData) {
                var remainingCount = !isNaN(serverData.remaining) ? serverData.remaining : Math.max(total - processed, 0);
                var percentValue = !isNaN(serverData.percentage) ? serverData.percentage : (total ? Math.min(100, Math.round((processed / total) * 100)) : 0);
                var currentBatchProcessed = !isNaN(serverData.processed) ? serverData.processed : 0;
                var batchTotal = total > 0 ? Math.min(batchSize, Math.max(0, total - ((page - 1) * batchSize))) : batchSize;

                $total.text(total);
                $processed.text(processed);
                $converted.text(converted);
                $failed.text(failed);
                $remaining.text(remainingCount);
                $currentBatch.text(total > 0 ? page + ' / ' + Math.ceil(total / batchSize) : page);
                $percentage.text(percentValue + '%');
                $progressBar.css('width', percentValue + '%').attr('aria-valuenow', percentValue).attr('aria-valuetext', formatTemplate(settings.bulk_percentage_template || '%percent%% complete', { '%percent%': percentValue }));
                if ($progressCount.length) {
                    $progressCount.text(formatTemplate(settings.bulk_progress_count_template || '%converted% / %total% converted', {
                        '%converted%': converted,
                        '%total%': total
                    }));
                }
                setSummary(remainingCount);
                setBatchProgress(page, currentBatchProcessed, batchTotal, total, processed);
            }

            function finish(success, customMessage) {
                var remainingCount = Math.max(total - processed, 0);
                updateStats({ remaining: remainingCount, percentage: total ? Math.min(100, Math.round((processed / total) * 100)) : 0 });
                toggleSpinner(false);
                toggleCancelButton(false);
                togglePauseButton(false);
                activeRequest = null;
                $button.prop('disabled', false).text(settings.bulk_button_text || 'Generate WebP for Existing Images');

                if (success) {
                    setStatus('completed', settings.bulk_completed_label || 'Completed');
                    setMessage(customMessage || settings.bulk_success || 'WebP generation completed.');
                    showNotice('success', settings.bulk_success_notice || 'WebP generation completed successfully.');
                    saveLastRun('success').always(function () {
                        fetchSummary(settings.bulk_refreshing || 'Refreshing conversion stats...');
                    });
                } else {
                    setStatus('error', settings.bulk_failed_label || 'Error');
                    setMessage(customMessage || settings.bulk_error || 'WebP generation failed.');
                    showNotice('error', customMessage || settings.bulk_error_notice || 'WebP generation stopped before completion.');
                    saveLastRun('error').always(function () {
                        fetchSummary(settings.bulk_refreshing || 'Refreshing conversion stats...');
                    });
                }
            }

            function finishPaused() {
                updateStats({ remaining: Math.max(total - processed, 0), percentage: total ? Math.min(100, Math.round((processed / total) * 100)) : 0 });
                toggleSpinner(false);
                togglePauseButton(true, true);
                toggleCancelButton(true);
                activeRequest = null;
                setStatus('paused', settings.bulk_paused_label || 'Paused');
                setMessage(settings.bulk_paused_message || 'WebP conversion is paused. Resume when you are ready.');
                appendLog(settings.bulk_log_paused || 'Conversion paused after the current batch.');
            }

            function finishCancelled() {
                updateStats({ remaining: Math.max(total - processed, 0), percentage: total ? Math.min(100, Math.round((processed / total) * 100)) : 0 });
                toggleSpinner(false);
                toggleCancelButton(false);
                togglePauseButton(false);
                activeRequest = null;
                $button.prop('disabled', false).text(settings.bulk_button_text || 'Generate WebP for Existing Images');
                setStatus('cancelled', settings.bulk_cancelled_label || 'Cancelled');
                setMessage(settings.bulk_cancelled_message || 'WebP conversion cancelled.');
                showNotice('warning', settings.bulk_cancelled_notice || 'WebP conversion was cancelled. Partial progress has been saved.');
                saveLastRun('cancelled').always(function () {
                    fetchSummary(settings.bulk_refreshing || 'Refreshing conversion stats...');
                });
            }

            function processBatch() {
                if (isCancelled) {
                    finishCancelled();
                    return;
                }

                if (isPaused) {
                    finishPaused();
                    return;
                }

                var nextIndex = processed + 1;
                var nextIndex = processed + 1;
                var batchTotal = total > 0 ? Math.min(batchSize, Math.max(0, total - ((page - 1) * batchSize))) : batchSize;
                if (total > 0 && nextIndex <= total) {
                    setMessage(formatTemplate(
                        settings.bulk_progress_template || 'Converting image %current% of %total%',
                        {
                            '%current%': nextIndex,
                            '%total%': total
                        }
                    ));
                } else {
                    setMessage(settings.bulk_processing || 'Preparing next batch...');
                }

                setBatchProgress(page, 0, batchTotal, total, processed);
                appendLog(formatTemplate(
                    settings.bulk_log_batch_started || 'Starting batch %page% (%processed% expected).',
                    {
                        '%page%': page,
                        '%processed%': Math.min(batchSize, total > 0 ? Math.min(batchSize, total - ((page - 1) * batchSize)) : batchSize)
                    }
                ));

                activeRequest = $.post(
                    settings.ajax_url,
                    {
                        action: 'wp_fastlayer_bulk_webp_batch',
                        nonce: settings.nonce,
                        page: page,
                        batch_size: batchSize
                    }
                ).done(function (response) {
                    if (!response || !response.success) {
                        var errorMessage = response && response.data && response.data.message ? response.data.message : (settings.bulk_error || 'WebP generation failed.');
                        finish(false, errorMessage);
                        return;
                    }

                    var data = response.data || {};
                    total = parseInt(data.total, 10) || total;
                    processed = parseInt(data.overall_processed, 10);
                    if (isNaN(processed)) {
                        processed = 0;
                    }
                    converted += parseInt(data.converted, 10) || 0;
                    failed += parseInt(data.failed, 10) || 0;

                    updateStats({
                        remaining: parseInt(data.remaining, 10),
                        percentage: parseInt(data.percentage, 10),
                        processed: parseInt(data.processed, 10)
                    });

                    appendLog(formatTemplate(
                        settings.bulk_log_batch_completed || 'Batch %page% completed: %processed% processed, %converted% converted, %failed% failed.',
                        {
                            '%page%': page,
                            '%processed%': parseInt(data.processed, 10) || 0,
                            '%converted%': parseInt(data.converted, 10) || 0,
                            '%failed%': parseInt(data.failed, 10) || 0
                        }
                    ));

                    if (!total && !data.has_more) {
                        updateStats({ remaining: 0, percentage: 100 });
                        toggleSpinner(false);
                        toggleCancelButton(false);
                        togglePauseButton(false);
                        activeRequest = null;
                        $button.prop('disabled', false).text(settings.bulk_button_text || 'Generate WebP for Existing Images');
                        setStatus('completed', settings.bulk_completed_label || 'Completed');
                        setMessage(settings.bulk_empty_message || 'No images needed WebP conversion.');
                        showNotice('warning', settings.bulk_empty_notice || 'No eligible JPEG or PNG images were found for conversion.');
                        saveLastRun('empty').always(function () {
                            fetchSummary(settings.bulk_refreshing || 'Refreshing conversion stats...');
                        });
                        return;
                    }

                    if (isCancelled) {
                        finishCancelled();
                        return;
                    }

                    if (data.has_more) {
                        page = parseInt(data.next_page, 10) || (page + 1);
                        if (isPaused) {
                            finishPaused();
                            return;
                        }
                        window.setTimeout(processBatch, 150);
                        return;
                    }

                    finish(true, settings.bulk_success || 'WebP generation completed.');
                }).fail(function (xhr) {
                    if (isCancelled) {
                        finishCancelled();
                        return;
                    }

                    var responseMessage = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : (settings.bulk_error || 'WebP generation request failed.');
                    finish(false, responseMessage);
                }).always(function () {
                    activeRequest = null;
                });
            }

            if ($cancelButton.length) {
                $cancelButton.on('click', function (event) {
                    event.preventDefault();
                    if ($cancelButton.prop('disabled')) {
                        return;
                    }

                    isCancelled = true;
                    $cancelButton.prop('disabled', true);
                    setMessage(settings.bulk_cancel_requested || 'Cancelling WebP conversion after the current request...');

                    if (activeRequest && typeof activeRequest.abort === 'function') {
                        activeRequest.abort();
                    }
                });
            }

            if ($pauseButton.length) {
                $pauseButton.on('click', function (event) {
                    event.preventDefault();
                    if ($pauseButton.prop('disabled')) {
                        return;
                    }

                    isPaused = !isPaused;
                    togglePauseButton(true, isPaused);
                    if (isPaused) {
                        setMessage(settings.bulk_pausing || 'Pausing after the current batch...');
                        appendLog(settings.bulk_log_pause_requested || 'Pause requested. Current batch will complete before stopping.');
                    } else {
                        setStatus('running', settings.bulk_running_label || 'Running');
                        setMessage(settings.bulk_resuming || 'Resuming WebP conversion...');
                        appendLog(settings.bulk_log_resumed || 'Resuming conversion from the remaining images.');
                        processBatch();
                    }
                });
            }

            if ($toggleLogsButton.length) {
                $toggleLogsButton.on('click', function (event) {
                    event.preventDefault();
                    setDetailedMode(!detailedMode);
                });
            }

            $button.on('click', function (event) {
                event.preventDefault();
                if ($button.prop('disabled')) {
                    return;
                }

                page = 1;
                total = 0;
                processed = 0;
                converted = 0;
                failed = 0;
                isCancelled = false;
                runStartedAt = Date.now();

                clearNotice();
                $progressWrapper.show();
                if ($toggleLogsButton.length) {
                    setDetailedMode(false);
                }
                $button.prop('disabled', true).text(settings.bulk_running || 'Starting WebP conversion...');
                if ($cancelButton.length) {
                    $cancelButton.prop('disabled', false);
                }
                if ($pauseButton.length) {
                    isPaused = false;
                    togglePauseButton(true, false);
                    $pauseButton.prop('disabled', false);
                }
                setStatus('running', settings.bulk_running_label || 'Running');
                toggleSpinner(true);
                toggleCancelButton(true);
                setMessage(settings.bulk_running || 'Starting WebP conversion...');
                updateStats({ remaining: 0, percentage: 0 });
                processBatch();
            });

            fetchSummary(settings.webp_summary_loading || 'Loading conversion summary...');
        }

        /*
         * Cache preload runs in WP-Cron, so the page that started it has already
         * finished by the time any progress exists. This polls the job state
         * only while a job is actually in flight, and stops for good once the
         * job reaches a final state, so an idle admin page makes no requests.
         */
        var $preloadStatus = $('[data-wpfl-preload-status]');
        if ($preloadStatus.length && settings.ajax_url && settings.preload_status_nonce) {
            var preloadLabels = {
                queued: settings.preload_status_queued,
                running: settings.preload_status_running,
                completed: settings.preload_status_completed,
                completed_with_errors: settings.preload_status_completed_with_errors,
                failed: settings.preload_status_failed
            };

            var preloadIsActive = function () {
                return $preloadStatus.attr('data-wpfl-preload-active') === 'yes';
            };

            var formatPreload = function (template, values) {
                var out = template || '';
                $.each(values, function (key, value) {
                    out = out.replace('%' + key + '%', value);
                });
                return out;
            };

            var renderPreloadErrors = function (errors) {
                var $details = $preloadStatus.find('[data-wpfl-preload-errors]');
                var errors = $.isArray(errors) ? errors : [];

                if (!$details.length) {
                    if (!errors.length) {
                        return;
                    }
                    $details = $('<details>', { 'data-wpfl-preload-errors': '' })
                        .append($('<summary>').text(settings.preload_status_errors_heading || 'Pages that could not be warmed'))
                        .append($('<ul>'));
                    $preloadStatus.append($details);
                }

                var $list = $details.find('ul');
                $list.empty();
                $.each(errors.slice(-20), function (index, error) {
                    $list.append($('<li>').text(
                        formatPreload(settings.preload_status_error_row_template || '%url% (%reason%)', {
                            url: error.url || '',
                            reason: error.reason || ''
                        })
                    ));
                });
                $details.show();
            };

            var renderPreloadState = function (state) {
                state = state || {};

                var status = state.status || '';
                $preloadStatus.attr('data-wpfl-preload-status', status);
                $preloadStatus.find('[data-wpfl-preload-label]')
                    .text(preloadLabels[status] || settings.preload_status_never_run || '');
                $preloadStatus.find('[data-wpfl-preload-progress]')
                    .text(formatPreload(settings.preload_status_progress_template || '%processed% of %total% pages processed', {
                        processed: state.processed || 0,
                        total: state.total || 0
                    }));
                $preloadStatus.find('[data-wpfl-preload-succeeded]')
                    .text(formatPreload(settings.preload_status_warmed_template || '%succeeded% warmed', {
                        succeeded: state.succeeded || 0
                    }));
                $preloadStatus.find('[data-wpfl-preload-failed]')
                    .text(formatPreload(settings.preload_status_failed_template || '%failed% failed', {
                        failed: state.failed || 0
                    }));

                if (state.message) {
                    var $message = $preloadStatus.find('[data-wpfl-preload-message]');
                    if (!$message.length) {
                        $message = $('<p>', { 'data-wpfl-preload-message': '' });
                        $preloadStatus.append($message);
                    }
                    $message.text(state.message);
                }

                renderPreloadErrors(state.errors);

                var stillActive = status === 'queued' || status === 'running';
                $preloadStatus.attr('data-wpfl-preload-active', stillActive ? 'yes' : 'no');
            };

            var pollPreloadState = function () {
                $.post(settings.ajax_url, {
                    action: settings.preload_status_action,
                    nonce: settings.preload_status_nonce
                }).done(function (response) {
                    if (!response || !response.success) {
                        return;
                    }
                    renderPreloadState(response.data);
                });
            };

            if (preloadIsActive()) {
                var preloadTimer = window.setInterval(function () {
                    if (!preloadIsActive()) {
                        window.clearInterval(preloadTimer);
                        return;
                    }
                    pollPreloadState();
                }, 5000);

                pollPreloadState();
            }
        }
    });
})(jQuery);
