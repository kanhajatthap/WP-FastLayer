(function ($) {
    'use strict';

    $(document).ready(function () {
        $(document).on('click', '.wpfl-copy-example-btn', function (event) {
            event.preventDefault();

            var $button = $(this);
            var textToCopy = $button.data('copy-text') || '';
            var defaultText = wpFastLayerAdmin.copy_text || 'Copy Example';
            var copiedText = wpFastLayerAdmin.copied_text || 'Copied';
            var failedText = wpFastLayerAdmin.copy_failed_text || 'Copy failed';

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

            var uploadsPath = (wpFastLayerAdmin.imagekit_uploads_path || '/wp-content/uploads').trim();
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
                    return wpFastLayerAdmin.imagekit_preview_missing_endpoint || '';
                }

                var mode = ($modeInput.val() || 'full_uploads_path').trim();
                var originalUrl = $imagekitPreview.data('original-url') || $previewOriginal.text() || '';
                var originalPath = normalizePath(extractPath(originalUrl));

                if (!originalPath || originalPath.indexOf(uploadsPath) !== 0) {
                    return wpFastLayerAdmin.imagekit_preview_invalid || '';
                }

                var relativePath = originalPath.slice(uploadsPath.length).replace(/^\/+/, '');
                var rewrittenPath = mode === 'uploads_relative_path'
                    ? relativePath
                    : originalPath.replace(/^\/+/, '');

                if (!rewrittenPath) {
                    return wpFastLayerAdmin.imagekit_preview_invalid || '';
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

        $('#wpfl-clean-all-toggle').on('change', function () {
            var isChecked = $(this).is(':checked');
            $('.wpfl-db-cleanup-toggle').prop('checked', isChecked);
        });

        var $dbCleanupForm = $('#wpfl-db-cleanup-form');
        var $dbCleanupButton = $('#wpfl-db-cleanup-submit');
        if ($dbCleanupForm.length && $dbCleanupButton.length) {
            var defaultCleanupText = wpFastLayerAdmin.db_cleanup_default || 'Clean Now';
            var processingCleanupText = wpFastLayerAdmin.db_cleanup_processing || 'Cleaning...';

            $dbCleanupForm.on('submit', function (event) {
                var $form = $(this);
                var alreadyProcessing = $form.data('processing') === true;
                if (alreadyProcessing) {
                    event.preventDefault();
                    return;
                }

                $form.data('processing', true);
                $form.find('input[name^="cleanup_items["]').remove();

                $('.wpfl-db-cleanup-toggle').each(function () {
                    var $checkbox = $(this);
                    var fieldName = $checkbox.attr('name') || '';
                    var matches = fieldName.match(/\[([^\]]+)\]$/);
                    if (!matches || !matches[1]) {
                        return;
                    }

                    var cleanupKey = matches[1];
                    var cleanupValue = $checkbox.is(':checked') ? '1' : '0';
                    $('<input>', {
                        type: 'hidden',
                        name: 'cleanup_items[' + cleanupKey + ']',
                        value: cleanupValue
                    }).appendTo($form);
                });

                var $label = $dbCleanupButton.find('.wpfl-db-cleanup-btn-text');
                if ($label.length) {
                    $label.text(processingCleanupText);
                }

                $dbCleanupButton.prop('disabled', true).addClass('is-loading');
                $dbCleanupButton.attr('data-default-text', defaultCleanupText);
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
                    $cancelButton.text(wpFastLayerAdmin.bulk_cancel_text || 'Cancel Conversion').show();
                } else {
                    $cancelButton.hide();
                }
            }

            function setMessage(text) {
                $message.text(text);
            }

            function setSummary(remainingCount) {
                $summary.text(formatTemplate(
                    wpFastLayerAdmin.bulk_summary_template || '%converted% converted, %failed% failed, %remaining% remaining.',
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
                    wpFastLayerAdmin.bulk_status_template || 'Processing batch %page% of %count% — Converting image %current% of %total%',
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
                    $eta.text(wpFastLayerAdmin.bulk_eta_unavailable || 'ETA unavailable until conversion starts.');
                    return;
                }

                var elapsed = Math.max(1, Math.round((Date.now() - runStartedAt) / 1000));
                var remaining = Math.max(0, totalImages - overallProcessed);
                var avgPerImage = elapsed / overallProcessed;
                var remainingSeconds = Math.round(remaining * avgPerImage);
                $eta.text(formatTemplate(wpFastLayerAdmin.bulk_eta_template || 'Estimated time remaining: %eta%', {
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

                $pauseButton.text(paused ? (wpFastLayerAdmin.bulk_resume_text || 'Resume Conversion') : (wpFastLayerAdmin.bulk_pause_text || 'Pause Conversion'));
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

                $toggleLogsButton.text(detailedMode ? (wpFastLayerAdmin.bulk_hide_logs_text || 'Hide Detailed Logs') : (wpFastLayerAdmin.bulk_view_logs_text || 'View Detailed Logs'));
                toggleLogArea(detailedMode);
                if (detailedMode && $logArea.children().length === 0) {
                    appendLog(wpFastLayerAdmin.bulk_log_started || 'WebP conversion started. Processing batches in the background.');
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
                        wpFastLayerAdmin.webp_summary_coverage || '%percent%% of eligible images already have WebP files.',
                        {
                            '%percent%': data.percentage || 0
                        }
                    ));
                }

                if ($summaryRefresh.length) {
                    $summaryRefresh.text(stateText || wpFastLayerAdmin.webp_summary_updated || 'Conversion summary updated.');
                }
            }

            function fetchSummary(stateText) {
                if (!$summaryBox.length) {
                    return $.Deferred().resolve().promise();
                }

                if ($summaryRefresh.length) {
                    $summaryRefresh.text(wpFastLayerAdmin.webp_summary_loading || 'Loading conversion summary...');
                }

                return $.post(
                    wpFastLayerAdmin.ajax_url,
                    {
                        action: 'wp_fastlayer_webp_summary',
                        nonce: wpFastLayerAdmin.nonce
                    }
                ).done(function (response) {
                    if (!response || !response.success || !response.data) {
                        if ($summaryRefresh.length) {
                            $summaryRefresh.text(wpFastLayerAdmin.webp_summary_error || 'Unable to load current conversion summary.');
                        }
                        return;
                    }

                    updateSummaryBox(response.data, stateText || wpFastLayerAdmin.webp_summary_updated || 'Conversion summary updated.');
                }).fail(function () {
                    if ($summaryRefresh.length) {
                        $summaryRefresh.text(wpFastLayerAdmin.webp_summary_error || 'Unable to load current conversion summary.');
                    }
                });
            }

            function saveLastRun(status) {
                var duration = runStartedAt ? Math.max(0, Math.round((Date.now() - runStartedAt) / 1000)) : 0;

                return $.post(
                    wpFastLayerAdmin.ajax_url,
                    {
                        action: 'wp_fastlayer_webp_last_run',
                        nonce: wpFastLayerAdmin.nonce,
                        status: status,
                        duration: duration
                    }
                ).done(function (response) {
                    if (response && response.success && response.data) {
                        updateSummaryBox(response.data, wpFastLayerAdmin.webp_summary_updated || 'Conversion summary updated.');
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
                $progressBar.css('width', percentValue + '%').attr('aria-valuenow', percentValue).attr('aria-valuetext', formatTemplate(wpFastLayerAdmin.bulk_percentage_template || '%percent%% complete', { '%percent%': percentValue }));
                if ($progressCount.length) {
                    $progressCount.text(formatTemplate(wpFastLayerAdmin.bulk_progress_count_template || '%converted% / %total% converted', {
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
                $button.prop('disabled', false).text(wpFastLayerAdmin.bulk_button_text || 'Generate WebP for Existing Images');

                if (success) {
                    setStatus('completed', wpFastLayerAdmin.bulk_completed_label || 'Completed');
                    setMessage(customMessage || wpFastLayerAdmin.bulk_success || 'WebP generation completed.');
                    showNotice('success', wpFastLayerAdmin.bulk_success_notice || 'WebP generation completed successfully.');
                    saveLastRun('success').always(function () {
                        fetchSummary(wpFastLayerAdmin.bulk_refreshing || 'Refreshing conversion stats...');
                    });
                } else {
                    setStatus('error', wpFastLayerAdmin.bulk_failed_label || 'Error');
                    setMessage(customMessage || wpFastLayerAdmin.bulk_error || 'WebP generation failed.');
                    showNotice('error', customMessage || wpFastLayerAdmin.bulk_error_notice || 'WebP generation stopped before completion.');
                    saveLastRun('error').always(function () {
                        fetchSummary(wpFastLayerAdmin.bulk_refreshing || 'Refreshing conversion stats...');
                    });
                }
            }

            function finishPaused() {
                updateStats({ remaining: Math.max(total - processed, 0), percentage: total ? Math.min(100, Math.round((processed / total) * 100)) : 0 });
                toggleSpinner(false);
                togglePauseButton(true, true);
                toggleCancelButton(true);
                activeRequest = null;
                setStatus('paused', wpFastLayerAdmin.bulk_paused_label || 'Paused');
                setMessage(wpFastLayerAdmin.bulk_paused_message || 'WebP conversion is paused. Resume when you are ready.');
                appendLog(wpFastLayerAdmin.bulk_log_paused || 'Conversion paused after the current batch.');
            }

            function finishCancelled() {
                updateStats({ remaining: Math.max(total - processed, 0), percentage: total ? Math.min(100, Math.round((processed / total) * 100)) : 0 });
                toggleSpinner(false);
                toggleCancelButton(false);
                togglePauseButton(false);
                activeRequest = null;
                $button.prop('disabled', false).text(wpFastLayerAdmin.bulk_button_text || 'Generate WebP for Existing Images');
                setStatus('cancelled', wpFastLayerAdmin.bulk_cancelled_label || 'Cancelled');
                setMessage(wpFastLayerAdmin.bulk_cancelled_message || 'WebP conversion cancelled.');
                showNotice('warning', wpFastLayerAdmin.bulk_cancelled_notice || 'WebP conversion was cancelled. Partial progress has been saved.');
                saveLastRun('cancelled').always(function () {
                    fetchSummary(wpFastLayerAdmin.bulk_refreshing || 'Refreshing conversion stats...');
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
                        wpFastLayerAdmin.bulk_progress_template || 'Converting image %current% of %total%',
                        {
                            '%current%': nextIndex,
                            '%total%': total
                        }
                    ));
                } else {
                    setMessage(wpFastLayerAdmin.bulk_processing || 'Preparing next batch...');
                }

                setBatchProgress(page, 0, batchTotal, total, processed);
                appendLog(formatTemplate(
                    wpFastLayerAdmin.bulk_log_batch_started || 'Starting batch %page% (%processed% expected).',
                    {
                        '%page%': page,
                        '%processed%': Math.min(batchSize, total > 0 ? Math.min(batchSize, total - ((page - 1) * batchSize)) : batchSize)
                    }
                ));

                activeRequest = $.post(
                    wpFastLayerAdmin.ajax_url,
                    {
                        action: 'wp_fastlayer_bulk_webp_batch',
                        nonce: wpFastLayerAdmin.nonce,
                        page: page,
                        batch_size: batchSize
                    }
                ).done(function (response) {
                    if (!response || !response.success) {
                        var errorMessage = response && response.data && response.data.message ? response.data.message : (wpFastLayerAdmin.bulk_error || 'WebP generation failed.');
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
                        wpFastLayerAdmin.bulk_log_batch_completed || 'Batch %page% completed: %processed% processed, %converted% converted, %failed% failed.',
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
                        $button.prop('disabled', false).text(wpFastLayerAdmin.bulk_button_text || 'Generate WebP for Existing Images');
                        setStatus('completed', wpFastLayerAdmin.bulk_completed_label || 'Completed');
                        setMessage(wpFastLayerAdmin.bulk_empty_message || 'No images needed WebP conversion.');
                        showNotice('warning', wpFastLayerAdmin.bulk_empty_notice || 'No eligible JPEG or PNG images were found for conversion.');
                        saveLastRun('empty').always(function () {
                            fetchSummary(wpFastLayerAdmin.bulk_refreshing || 'Refreshing conversion stats...');
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

                    finish(true, wpFastLayerAdmin.bulk_success || 'WebP generation completed.');
                }).fail(function (xhr) {
                    if (isCancelled) {
                        finishCancelled();
                        return;
                    }

                    var responseMessage = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : (wpFastLayerAdmin.bulk_error || 'WebP generation request failed.');
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
                    setMessage(wpFastLayerAdmin.bulk_cancel_requested || 'Cancelling WebP conversion after the current request...');

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
                        setMessage(wpFastLayerAdmin.bulk_pausing || 'Pausing after the current batch...');
                        appendLog(wpFastLayerAdmin.bulk_log_pause_requested || 'Pause requested. Current batch will complete before stopping.');
                    } else {
                        setStatus('running', wpFastLayerAdmin.bulk_running_label || 'Running');
                        setMessage(wpFastLayerAdmin.bulk_resuming || 'Resuming WebP conversion...');
                        appendLog(wpFastLayerAdmin.bulk_log_resumed || 'Resuming conversion from the remaining images.');
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
                $button.prop('disabled', true).text(wpFastLayerAdmin.bulk_running || 'Starting WebP conversion...');
                if ($cancelButton.length) {
                    $cancelButton.prop('disabled', false);
                }
                if ($pauseButton.length) {
                    isPaused = false;
                    togglePauseButton(true, false);
                    $pauseButton.prop('disabled', false);
                }
                setStatus('running', wpFastLayerAdmin.bulk_running_label || 'Running');
                toggleSpinner(true);
                toggleCancelButton(true);
                setMessage(wpFastLayerAdmin.bulk_running || 'Starting WebP conversion...');
                updateStats({ remaining: 0, percentage: 0 });
                processBatch();
            });

            fetchSummary(wpFastLayerAdmin.webp_summary_loading || 'Loading conversion summary...');
        }
    });
})(jQuery);
