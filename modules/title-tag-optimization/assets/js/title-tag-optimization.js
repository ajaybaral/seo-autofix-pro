/**
 * Title Tag Optimization — Admin JavaScript
 * Fully isolated. No imports from Image SEO or Broken URL modules.
 *
 * @package SEO_AutoFix_Pro
 */
/* global titleTagData, jQuery */
(function ($) {
    'use strict';

    /* =========================================================
     * Module State
     * ========================================================= */
    var TitleTag = {
        // All rows from current scan (unfiltered)
        allRows: [],

        // Currently displayed (filtered) rows
        visibleRows: [],

        // Active filter
        activeFilter: 'all',

        // cancelGeneration flag
        cancelGeneration: false,

        // Applied changes for CSV export: { post_id, post_url, old_title, new_title }
        appliedChanges: [],

        // Skip list (session-only, no DB)
        skippedIds: []
    };

    /* =========================================================
     * DOM Ready
     * ========================================================= */
    $(document).ready(function () {
        bindEvents();
    });

    function bindEvents() {
        $('#titletag-scan-btn').on('click', startScan);
        $('#titletag-reset-filter-btn').on('click', resetFilter);
        $('#titletag-bulk-generate-btn').on('click', bulkGenerate);
        $('#titletag-bulk-apply-btn').on('click', bulkApply);
        $('#titletag-cancel-btn').on('click', cancelGeneration);
        $('#titletag-export-csv-btn').on('click', exportCSV);

        // Filter radio cards
        $(document).on('click', '.titletag-filter-card', function () {
            var val = $(this).data('filter');
            setFilter(val);
            $(this).find('input[type="radio"]').prop('checked', true);
        });

        // Row-level events (delegated)
        $('#titletag-tbody').on('click', '.titletag-generate-btn', function () {
            var $row = $(this).closest('tr');
            generateSingle($row, false);
        });

        $('#titletag-tbody').on('click', '.titletag-apply-btn', function () {
            var $row = $(this).closest('tr');
            applySingle($row);
        });

        $('#titletag-tbody').on('click', '.titletag-skip-btn', function () {
            var $row = $(this).closest('tr');
            skipRow($row);
        });

        // Char counter on edit
        $('#titletag-tbody').on('input', '.titletag-suggested-editable', function () {
            updateCharCounter($(this));
            var $row = $(this).closest('tr');
            var hasText = $(this).text().trim().length > 0;
            $row.find('.titletag-apply-btn').prop('disabled', !hasText);
        });
    }

    /* =========================================================
     * Scan
     * ========================================================= */
    function startScan() {
        $('#titletag-scan-btn').prop('disabled', true).text('Scanning…');
        $('#titletag-empty-state').hide();
        $('#titletag-results').hide();
        $('#titletag-stats').hide();
        $('#titletag-controls').hide();
        $('#titletag-scan-progress').show();
        setProgress(0);

        TitleTag.allRows = [];
        TitleTag.appliedChanges = [];
        TitleTag.skippedIds = [];

        fetchScanBatch(0);
    }

    function fetchScanBatch(offset) {
        $.ajax({
            url: titleTagData.ajaxUrl,
            method: 'POST',
            data: {
                action:  'titletag_scan',
                nonce:   titleTagData.nonce,
                offset:  offset,
                post_type:    'any',
                issue_filter: 'all'
            },
            success: function (res) {
                if (!res.success) {
                    showToast('Scan error: ' + res.data.message, 'error');
                    scanComplete();
                    return;
                }

                var data = res.data;
                TitleTag.allRows = TitleTag.allRows.concat(data.results || []);

                if (data.stats && offset === 0) {
                    renderStats(data.stats);
                }

                setProgress(data.hasMore ? 50 : 100);

                if (data.hasMore) {
                    fetchScanBatch(data.offset);
                } else {
                    scanComplete();
                }
            },
            error: function () {
                showToast('Scan failed. Check the debug log.', 'error');
                scanComplete();
            }
        });
    }

    function scanComplete() {
        $('#titletag-scan-progress').hide();
        $('#titletag-scan-btn').prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Posts & Pages');

        if (TitleTag.allRows.length === 0) {
            $('#titletag-empty-state').show();
            return;
        }

        $('#titletag-stats').show();
        $('#titletag-controls').show();
        $('#titletag-results').show();
        setFilter(TitleTag.activeFilter);
        showToast('Scan complete. Found ' + TitleTag.allRows.length + ' posts/pages.');
    }

    /* =========================================================
     * Stats
     * ========================================================= */
    function renderStats(stats) {
        $('#stat-total').text(stats.total || 0);
        $('#stat-with-titles').text(stats.with_titles || 0);
        $('#stat-without-titles').text(stats.without_titles || 0);
    }

    /* =========================================================
     * Filter
     * ========================================================= */
    function setFilter(filter) {
        TitleTag.activeFilter = filter;
        TitleTag.appliedChanges = []; // reset export on filter change
        $('#titletag-export-csv-btn').hide();

        // Update active class on cards
        $('.titletag-filter-card').removeClass('titletag-filter-active');
        $('.titletag-filter-card[data-filter="' + filter + '"]').addClass('titletag-filter-active');

        // Filter rows
        if (filter === 'all') {
            TitleTag.visibleRows = TitleTag.allRows.slice();
        } else {
            TitleTag.visibleRows = TitleTag.allRows.filter(function (r) {
                return r.issue_type === filter;
            });
        }

        renderTable(TitleTag.visibleRows);
    }

    function resetFilter() {
        setFilter('all');
        $('input[name="titletag-filter"][value="all"]').prop('checked', true);
    }

    /* =========================================================
     * Table Rendering
     * ========================================================= */
    function renderTable(rows) {
        var $tbody = $('#titletag-tbody');
        $tbody.empty();

        if (rows.length === 0) {
            $tbody.append('<tr><td colspan="6" style="text-align:center;padding:30px;color:#646970;">No issues match the selected filter.</td></tr>');
            return;
        }

        var tmpl = document.getElementById('titletag-row-template');

        rows.forEach(function (row, idx) {
            var $tr = $(document.importNode(tmpl.content, true).firstElementChild);

            $tr.attr('data-post-id', row.post_id);
            $tr.attr('data-issue', row.issue_type);
            $tr.attr('data-post-url', row.post_url);
            $tr.attr('data-old-title', row.rendered_title);

            $tr.find('.titletag-col-num').text(idx + 1);
            $tr.find('.titletag-post-title').text(row.post_title);
            $tr.find('.titletag-post-type-badge').text(row.post_type);
            $tr.find('.titletag-edit-link').attr('href', row.edit_url);
            $tr.find('.titletag-post-url').attr('href', row.post_url).text(shortenUrl(row.post_url));
            $tr.find('.titletag-current-title-text').text(row.rendered_title || '(empty)');
            $tr.find('.titletag-issue-badge').html(buildIssueBadge(row.issue_type));
            $tr.find('.titletag-char-count').text('0');

            $tbody.append($tr);
        });
    }

    function buildIssueBadge(issue_type) {
        var labels = {
            'missing':   'Missing',
            'too_short': 'Too Short',
            'too_long':  'Too Long',
            'duplicate': 'Duplicate',
            'ok':        'OK'
        };
        return '<span class="titletag-issue-badge titletag-badge-' + issue_type + '">' + (labels[issue_type] || issue_type) + '</span>';
    }

    function shortenUrl(url) {
        try {
            var u = new URL(url);
            return u.pathname.length > 35 ? u.pathname.substring(0, 32) + '…' : u.pathname;
        } catch (e) { return url; }
    }

    /* =========================================================
     * Single Generate
     * ========================================================= */
    function generateSingle($row, isBulk) {
        var postId = $row.data('post-id');
        var $editable = $row.find('.titletag-suggested-editable');
        var $indicator = $row.find('.titletag-generating-indicator');
        var $genBtn = $row.find('.titletag-generate-btn');
        var $applyBtn = $row.find('.titletag-apply-btn');

        $editable.hide();
        $indicator.show();
        $genBtn.prop('disabled', true);
        $applyBtn.prop('disabled', true);

        return $.ajax({
            url: titleTagData.ajaxUrl,
            method: 'POST',
            data: {
                action:  'titletag_generate',
                nonce:   titleTagData.nonce,
                post_id: postId,
                force:   isBulk ? 'false' : 'false'
            }
        }).then(function (res) {
            $indicator.hide();
            $editable.show();
            $genBtn.prop('disabled', false);

            if (res.success) {
                var title = res.data.title;
                $editable.text(title).addClass('has-suggestion');
                updateCharCounter($editable);
                $applyBtn.prop('disabled', false);
                setRowStatus($row, '', '');
            } else {
                setRowStatus($row, 'Error: ' + res.data.message, 'error');
                $applyBtn.prop('disabled', true);
            }
        }).fail(function () {
            $indicator.hide();
            $editable.show();
            $genBtn.prop('disabled', false);
            setRowStatus($row, 'Request failed.', 'error');
        });
    }

    /* =========================================================
     * Apply Single
     * ========================================================= */
    function applySingle($row) {
        var postId   = parseInt($row.data('post-id'), 10);
        var postUrl  = $row.attr('data-post-url') || '';
        var oldTitle = $row.attr('data-old-title') || '';
        var newTitle = $row.find('.titletag-suggested-editable').text().trim();

        if (!newTitle) { showToast('Title cannot be empty.', 'error'); return; }

        var $applyBtn = $row.find('.titletag-apply-btn');
        $applyBtn.prop('disabled', true).text('Applying…');
        setRowStatus($row, '', '');

        $.ajax({
            url: titleTagData.ajaxUrl,
            method: 'POST',
            data: {
                action:    'titletag_apply',
                nonce:     titleTagData.nonce,
                post_id:   postId,
                new_title: newTitle
            },
            success: function (res) {
                if (res.success) {
                    setRowStatus($row, '✅ Applied!', 'success');
                    $row.addClass('titletag-row-applied');
                    $applyBtn.text('Applied').prop('disabled', true);

                    // Record for CSV export
                    TitleTag.appliedChanges.push({
                        post_url:  postUrl,
                        old_title: oldTitle,
                        new_title: newTitle
                    });
                    if (TitleTag.appliedChanges.length > 0) {
                        $('#titletag-export-csv-btn').show();
                    }

                    // Update old title data attr
                    $row.attr('data-old-title', newTitle);
                } else {
                    setRowStatus($row, '❌ ' + res.data.message, 'error');
                    $applyBtn.prop('disabled', false).text('Apply');
                }
            },
            error: function () {
                setRowStatus($row, '❌ Request failed.', 'error');
                $applyBtn.prop('disabled', false).text('Apply');
            }
        });
    }

    /* =========================================================
     * Skip
     * ========================================================= */
    function skipRow($row) {
        var postId = parseInt($row.data('post-id'), 10);
        TitleTag.skippedIds.push(postId);
        $row.addClass('titletag-row-skipped');
        $row.find('.titletag-apply-btn, .titletag-generate-btn, .titletag-skip-btn').prop('disabled', true);

        // Server ping (no DB, just logging)
        $.post(titleTagData.ajaxUrl, {
            action:  'titletag_skip',
            nonce:   titleTagData.nonce,
            post_id: postId
        });
    }

    /* =========================================================
     * Bulk Generate
     * ========================================================= */
    function bulkGenerate() {
        if (!titleTagData.hasApiKey) {
            showToast(titleTagData.strings.noApiKey, 'error');
            return;
        }

        var $rows = $('#titletag-tbody .titletag-row:not(.titletag-row-skipped):not(.titletag-row-applied)');
        var total = $rows.length;
        if (total === 0) { showToast('No rows to process.', 'error'); return; }

        TitleTag.cancelGeneration = false;
        $('#titletag-bulk-progress').show();
        $('#titletag-bulk-generate-btn').prop('disabled', true);
        updateBulkProgress(0, total);

        var rowArray = $rows.toArray();
        var idx = 0;

        function next() {
            if (TitleTag.cancelGeneration) {
                finishBulkGenerate(true);
                return;
            }
            if (idx >= rowArray.length) {
                finishBulkGenerate(false);
                return;
            }

            var $row = $(rowArray[idx]);
            idx++;
            generateSingle($row, true).always(function () {
                updateBulkProgress(idx, total);
                next();
            });
        }

        next();
    }

    function updateBulkProgress(done, total) {
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;
        $('#titletag-bulk-progress-text').text('Generating: ' + done + ' of ' + total);
        $('#titletag-bulk-progress-fill').css('width', pct + '%');
    }

    function finishBulkGenerate(cancelled) {
        $('#titletag-bulk-progress').hide();
        $('#titletag-bulk-generate-btn').prop('disabled', false);
        if (cancelled) {
            // Clear all generated suggestions
            $('.titletag-suggested-editable').text('').removeClass('has-suggestion');
            $('.titletag-apply-btn').prop('disabled', true);
            showToast(titleTagData.strings.cancelled, 'error');
        } else {
            showToast('Bulk generation complete!');
        }
    }

    function cancelGeneration() {
        TitleTag.cancelGeneration = true;
    }

    /* =========================================================
     * Bulk Apply
     * ========================================================= */
    function bulkApply() {
        var changes = [];
        $('#titletag-tbody .titletag-row:not(.titletag-row-skipped):not(.titletag-row-applied)').each(function () {
            var $row     = $(this);
            var postId   = parseInt($row.data('post-id'), 10);
            var postUrl  = $row.attr('data-post-url') || '';
            var oldTitle = $row.attr('data-old-title') || '';
            var newTitle = $row.find('.titletag-suggested-editable').text().trim();
            if (postId && newTitle) {
                changes.push({ post_id: postId, new_title: newTitle, post_url: postUrl, old_title: oldTitle });
            }
        });

        if (changes.length === 0) {
            showToast(titleTagData.strings.noSuggestions, 'error');
            return;
        }

        if (!confirm(titleTagData.strings.confirmBulk + ' (' + changes.length + ' posts)')) { return; }

        $('#titletag-bulk-apply-btn').prop('disabled', true).text('Applying…');

        $.ajax({
            url: titleTagData.ajaxUrl,
            method: 'POST',
            data: {
                action:  'titletag_bulk_apply',
                nonce:   titleTagData.nonce,
                changes: JSON.stringify(changes.map(function (c) { return { post_id: c.post_id, new_title: c.new_title }; }))
            },
            success: function (res) {
                $('#titletag-bulk-apply-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Bulk Apply Suggestions');

                if (res.success) {
                    var summary = res.data;
                    showToast('Applied: ' + summary.applied + ', Failed: ' + summary.failed);

                    // Mark rows + accumulate export changes
                    changes.forEach(function (c) {
                        var $row = $('#titletag-tbody .titletag-row[data-post-id="' + c.post_id + '"]');
                        if ($row.length) {
                            $row.addClass('titletag-row-applied');
                            setRowStatus($row, '✅ Applied!', 'success');
                            $row.find('.titletag-apply-btn').text('Applied').prop('disabled', true);
                            $row.attr('data-old-title', c.new_title);
                        }
                        TitleTag.appliedChanges.push({ post_url: c.post_url, old_title: c.old_title, new_title: c.new_title });
                    });

                    if (TitleTag.appliedChanges.length > 0) { $('#titletag-export-csv-btn').show(); }
                } else {
                    showToast('Bulk apply error: ' + res.data.message, 'error');
                }
            },
            error: function () {
                $('#titletag-bulk-apply-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Bulk Apply Suggestions');
                showToast('Request failed.', 'error');
            }
        });
    }

    /* =========================================================
     * Export CSV
     * ========================================================= */
    function exportCSV() {
        if (TitleTag.appliedChanges.length === 0) {
            showToast('No applied changes to export.', 'error');
            return;
        }

        $('#titletag-export-csv-btn').prop('disabled', true).text('Exporting…');

        $.ajax({
            url: titleTagData.ajaxUrl,
            method: 'POST',
            data: {
                action:  'titletag_export_csv',
                nonce:   titleTagData.nonce,
                changes: JSON.stringify(TitleTag.appliedChanges)
            },
            success: function (res) {
                $('#titletag-export-csv-btn').prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Export Applied Changes (CSV)');
                if (res.success) {
                    window.location.href = res.data.download_url;
                    showToast('CSV export ready!');
                } else {
                    showToast('Export error: ' + res.data.message, 'error');
                }
            },
            error: function () {
                $('#titletag-export-csv-btn').prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Export Applied Changes (CSV)');
                showToast('Export request failed.', 'error');
            }
        });
    }

    /* =========================================================
     * Helpers
     * ========================================================= */
    function updateCharCounter($editable) {
        var len = $editable.text().trim().length;
        var $counter = $editable.closest('td').find('.titletag-char-count');
        $counter.text(len).removeClass('chars-ok chars-short chars-long');
        if (len === 0)       { return; }
        if (len < 30)        { $counter.addClass('chars-short'); }
        else if (len > 60)   { $counter.addClass('chars-long'); }
        else                 { $counter.addClass('chars-ok'); }
    }

    function setRowStatus($row, msg, type) {
        var $status = $row.find('.titletag-action-status');
        $status.removeClass('success error').text(msg);
        if (type) { $status.addClass(type); }
    }

    function setProgress(pct) {
        $('#titletag-progress-fill').css('width', pct + '%');
        $('#titletag-progress-pct').text(pct + '%');
    }

    function showToast(msg, type) {
        var $toast = $('<div class="titletag-toast"><span class="titletag-toast-message"></span></div>');
        $toast.find('.titletag-toast-message').text(msg);
        if (type === 'error') { $toast.addClass('toast-error'); }
        $('body').append($toast);
        setTimeout(function () { $toast.addClass('show'); }, 50);
        setTimeout(function () {
            $toast.removeClass('show');
            setTimeout(function () { $toast.remove(); }, 400);
        }, 3500);
    }

})(jQuery);
