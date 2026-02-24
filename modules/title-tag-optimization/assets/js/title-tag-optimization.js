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
     * Constants
     * ========================================================= */
    var PAGE_SIZE = 25;

    /* =========================================================
     * Module State
     * ========================================================= */
    var TitleTag = {
        allRows: [],   // All rows from current scan
        visibleRows: [],   // After filter applied
        activeFilter: '',   // '' = no filter (show all)
        currentPage: 1,
        cancelGeneration: false,
        appliedChanges: [],   // For CSV export — resets on filter change
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
        $('#titletag-cancel-btn').on('click', function () { TitleTag.cancelGeneration = true; });
        $('#titletag-export-csv-btn').on('click', exportCSV);

        // Filter radio cards
        $(document).on('click', '.titletag-filter-card', function () {
            var val = $(this).data('filter');
            setFilter(val);
            $(this).find('input[type="radio"]').prop('checked', true);
        });

        // Row-level events (delegated)
        $('#titletag-tbody')
            .on('click', '.titletag-generate-btn', function () {
                generateSingle($(this).closest('tr'), false);
            })
            .on('click', '.titletag-apply-btn', function () {
                applySingle($(this).closest('tr'));
            })
            .on('click', '.titletag-skip-btn', function () {
                skipRow($(this).closest('tr'));
            })
            .on('input', '.titletag-suggested-editable', function () {
                var $row = $(this).closest('tr');
                updateCharCounter($(this));
                $row.find('.titletag-apply-btn').prop('disabled', $(this).text().trim().length === 0);
            });
    }

    /* =========================================================
     * Scan
     * ========================================================= */
    function startScan() {
        $('#titletag-scan-btn').prop('disabled', true).text('Scanning\u2026');
        $('#titletag-empty-state').hide();
        $('#titletag-results').hide();
        $('#titletag-stats').hide();
        $('#titletag-controls').hide();
        $('#titletag-scan-progress').show();
        setProgress(0);

        TitleTag.allRows = [];
        TitleTag.appliedChanges = [];
        TitleTag.skippedIds = [];
        TitleTag.activeFilter = '';
        TitleTag.currentPage = 1;

        fetchScanBatch(0);
    }

    function fetchScanBatch(offset) {
        $.ajax({
            url: titleTagData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'titletag_scan',
                nonce: titleTagData.nonce,
                offset: offset,
                post_type: 'any',
                issue_filter: 'all'
            },
            success: function (res) {
                if (!res.success) {
                    showToast('Scan error: ' + res.data.message, 'error');
                    scanComplete(); return;
                }
                var data = res.data;
                TitleTag.allRows = TitleTag.allRows.concat(data.results || []);
                if (data.stats && offset === 0) { renderStats(data.stats); }
                setProgress(data.hasMore ? 50 : 100);
                if (data.hasMore) { fetchScanBatch(data.offset); }
                else { scanComplete(); }
            },
            error: function () {
                showToast('Scan failed. Check the debug log.', 'error');
                scanComplete();
            }
        });
    }

    function scanComplete() {
        $('#titletag-scan-progress').hide();
        $('#titletag-scan-btn')
            .prop('disabled', false)
            .html('<span class="dashicons dashicons-search"></span> Scan Posts &amp; Pages');

        if (TitleTag.allRows.length === 0) {
            $('#titletag-empty-state').show(); return;
        }

        $('#titletag-stats').show();
        $('#titletag-controls').show();
        $('#titletag-results').show();

        // Default: no filter, show all
        applyFilter('');
        showToast('Scan complete. ' + TitleTag.allRows.length + ' posts/pages found.');
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
        // Toggle: clicking the already-active filter deselects it
        if (TitleTag.activeFilter === filter) {
            resetFilter(); return;
        }
        applyFilter(filter);
        // Mark card active
        $('.titletag-filter-card').removeClass('titletag-filter-active');
        $('.titletag-filter-card[data-filter="' + filter + '"]').addClass('titletag-filter-active');
    }

    function applyFilter(filter) {
        TitleTag.activeFilter = filter;
        TitleTag.appliedChanges = [];
        TitleTag.currentPage = 1;
        $('#titletag-export-csv-btn').hide();

        if (filter === '') {
            TitleTag.visibleRows = TitleTag.allRows.slice();
        } else {
            TitleTag.visibleRows = TitleTag.allRows.filter(function (r) {
                return r.issue_type === filter;
            });
        }

        renderCurrentPage();
    }

    function resetFilter() {
        TitleTag.activeFilter = '';
        // Force-uncheck via native DOM to avoid browser caching issues
        $('input[name="titletag-filter"]').each(function () { this.checked = false; });
        $('.titletag-filter-card').removeClass('titletag-filter-active');
        applyFilter('');
    }

    /* =========================================================
     * Pagination helpers
     * ========================================================= */
    function totalPages() {
        return Math.max(1, Math.ceil(TitleTag.visibleRows.length / PAGE_SIZE));
    }

    function pageRows() {
        var start = (TitleTag.currentPage - 1) * PAGE_SIZE;
        return TitleTag.visibleRows.slice(start, start + PAGE_SIZE);
    }

    function renderCurrentPage() {
        renderTable(pageRows(), (TitleTag.currentPage - 1) * PAGE_SIZE);
        renderPagination();
    }

    /* =========================================================
     * Table Rendering
     * ========================================================= */
    function renderTable(rows, startIdx) {
        var $tbody = $('#titletag-tbody');
        $tbody.empty();

        if (TitleTag.visibleRows.length === 0) {
            $tbody.append(
                '<tr><td colspan="6" style="text-align:center;padding:30px;color:#646970;">' +
                (TitleTag.activeFilter ? 'No issues match the selected filter.' : 'No posts found.') +
                '</td></tr>'
            );
            return;
        }

        var tmpl = document.getElementById('titletag-row-template');

        rows.forEach(function (row, i) {
            var globalIdx = startIdx + i;
            var $tr = $(document.importNode(tmpl.content, true).firstElementChild);

            $tr.attr('data-post-id', row.post_id);
            $tr.attr('data-issue', row.issue_type);
            $tr.attr('data-post-url', row.post_url);
            $tr.attr('data-old-title', row.rendered_title);

            $tr.find('.titletag-col-num').text(globalIdx + 1);
            $tr.find('.titletag-post-title').text(row.post_title);
            $tr.find('.titletag-post-type-badge').text(row.post_type);
            $tr.find('.titletag-edit-link').attr('href', row.edit_url);
            $tr.find('.titletag-post-url').attr('href', row.post_url).text(shortenUrl(row.post_url));
            $tr.find('.titletag-current-title-text').text(row.rendered_title || '(empty)');
            $tr.find('.titletag-issue-badge-wrap').html(buildIssueBadge(row.issue_type));
            var currentTitleLen = (row.rendered_title || '').length;
            $tr.find('.titletag-current-char-count').text(currentTitleLen + ' chars');
            $tr.find('.titletag-char-count').text('0');

            $tbody.append($tr);
        });
    }

    function buildIssueBadge(issue_type) {
        var labels = {
            missing: 'Missing',
            too_short: 'Too Short',
            too_long: 'Too Long',
            duplicate: 'Duplicate',
            ok: 'OK'
        };
        return '<span class="titletag-issue-badge titletag-badge-' + issue_type + '">' +
            (labels[issue_type] || issue_type) + '</span>';
    }

    function shortenUrl(url) {
        try {
            var u = new URL(url);
            var p = u.pathname;
            return p.length > 40 ? p.substring(0, 37) + '\u2026' : p;
        } catch (e) { return url; }
    }

    /* =========================================================
     * Pagination Rendering
     * ========================================================= */
    function renderPagination() {
        var $pag = $('#titletag-pagination');
        var $info = $('#titletag-pagination-info');
        var $ctrl = $('#titletag-pagination-controls');
        var total = TitleTag.visibleRows.length;
        var tp = totalPages();
        var cp = TitleTag.currentPage;

        if (total === 0) { $pag.hide(); return; }

        var start = (cp - 1) * PAGE_SIZE + 1;
        var end = Math.min(cp * PAGE_SIZE, total);
        $info.text('Showing ' + start + '\u2013' + end + ' of ' + total);

        $ctrl.empty();

        // Prev
        var $prev = $('<button class="button">&laquo; Prev</button>');
        if (cp <= 1) { $prev.prop('disabled', true); }
        else { $prev.on('click', function () { goToPage(cp - 1); }); }
        $ctrl.append($prev);

        // Page numbers
        var pages = buildPageNumbers(cp, tp);
        pages.forEach(function (p) {
            if (p === '\u2026') {
                $ctrl.append('<span class="titletag-pagination-ellipsis">\u2026</span>');
            } else {
                var $btn = $('<button class="button">' + p + '</button>');
                if (p === cp) { $btn.addClass('current-page'); }
                else {
                    (function (pg) {
                        $btn.on('click', function () { goToPage(pg); });
                    })(p);
                }
                $ctrl.append($btn);
            }
        });

        // Next
        var $next = $('<button class="button">Next &raquo;</button>');
        if (cp >= tp) { $next.prop('disabled', true); }
        else { $next.on('click', function () { goToPage(cp + 1); }); }
        $ctrl.append($next);

        if (tp > 1) { $pag.show(); }
        else { $pag.hide(); }
    }

    function buildPageNumbers(current, total) {
        if (total <= 7) {
            var arr = [];
            for (var i = 1; i <= total; i++) { arr.push(i); }
            return arr;
        }
        var pages = [1];
        if (current > 3) { pages.push('\u2026'); }
        for (var p = Math.max(2, current - 1); p <= Math.min(total - 1, current + 1); p++) {
            pages.push(p);
        }
        if (current < total - 2) { pages.push('\u2026'); }
        pages.push(total);
        return pages;
    }

    function goToPage(page) {
        TitleTag.currentPage = page;
        renderCurrentPage();
        // Scroll to table top
        $('html, body').animate({ scrollTop: $('#titletag-results').offset().top - 40 }, 200);
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
                action: 'titletag_generate',
                nonce: titleTagData.nonce,
                post_id: postId,
                force: 'false'
            }
        }).then(function (res) {
            $indicator.hide();
            $editable.show();
            $genBtn.prop('disabled', false);

            if (res.success) {
                $editable.text(res.data.title).addClass('has-suggestion');
                updateCharCounter($editable);
                $applyBtn.prop('disabled', false);
                setRowStatus($row, '', '');
            } else {
                setRowStatus($row, 'Error: ' + res.data.message, 'error');
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
        var postId = parseInt($row.data('post-id'), 10);
        var postUrl = $row.attr('data-post-url') || '';
        var oldTitle = $row.attr('data-old-title') || '';
        var newTitle = $row.find('.titletag-suggested-editable').text().trim();

        if (!newTitle) { showToast('Title cannot be empty.', 'error'); return; }

        var $applyBtn = $row.find('.titletag-apply-btn');
        $applyBtn.prop('disabled', true).text('Applying\u2026');
        setRowStatus($row, '', '');

        $.ajax({
            url: titleTagData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'titletag_apply',
                nonce: titleTagData.nonce,
                post_id: postId,
                new_title: newTitle
            },
            success: function (res) {
                if (res.success) {
                    // Immediately: green row BG + green 'Applied' button (no status box)
                    $row.addClass('titletag-row-green');
                    $applyBtn.addClass('titletag-apply-btn-applied').text('Applied').prop('disabled', true);

                    // Record for CSV
                    $row.attr('data-old-title', newTitle);
                    TitleTag.appliedChanges.push({ post_url: postUrl, old_title: oldTitle, new_title: newTitle });
                    if (TitleTag.appliedChanges.length > 0) { $('#titletag-export-csv-btn').show(); }

                    // After 3.5s: update current title + badge, clear suggestion, reset button
                    setTimeout(function () {
                        // Update 'Current SEO Title' column
                        $row.find('.titletag-current-title-text').text(newTitle);

                        // Update issue badge based on new title length
                        var newLen = newTitle.length;
                        var newIssue = newLen === 0 ? 'missing'
                            : newLen < 30 ? 'too_short'
                                : newLen > 60 ? 'too_long'
                                    : 'ok';
                        $row.find('.titletag-issue-badge-wrap').html(buildIssueBadge(newIssue));

                        // Clear AI suggestion field
                        $row.find('.titletag-suggested-editable').text('').removeClass('has-suggestion');
                        $row.find('.titletag-char-count').text('0').removeClass('chars-ok chars-short chars-long');

                        // Revert button to 'Apply' + disabled (until next Generate)
                        $applyBtn.removeClass('titletag-apply-btn-applied').text('Apply').prop('disabled', true);

                        // Fade out green BG
                        $row.removeClass('titletag-row-green');
                    }, 3500);

                } else {
                    setRowStatus($row, res.data.message, 'error');
                    $applyBtn.text('Apply').prop('disabled', false);
                }
            },
            error: function () {
                setRowStatus($row, 'Request failed.', 'error');
                $applyBtn.text('Apply').prop('disabled', false);
            }
        });
    }

    /* =========================================================
     * Skip (frontend-only — removes row from session with animation)
     * ========================================================= */
    function skipRow($row) {
        var postId = parseInt($row.data('post-id'), 10);
        TitleTag.skippedIds.push(postId);

        // Determine the skipped row's issue type for stat adjustment
        var skippedIssue = $row.attr('data-issue') || '';

        // Disable buttons immediately
        $row.find('.titletag-apply-btn, .titletag-generate-btn, .titletag-skip-btn').prop('disabled', true);

        // Step 1: Turn row grey
        $row.addClass('titletag-row-being-skipped');

        // Step 2: After grey shows, fade out
        setTimeout(function () {
            $row.addClass('titletag-row-fade-out');

            // Step 3: After fade-out completes, remove from data and re-render
            setTimeout(function () {
                // Remove from data arrays
                TitleTag.allRows = TitleTag.allRows.filter(function (r) {
                    return r.post_id !== postId;
                });
                TitleTag.visibleRows = TitleTag.visibleRows.filter(function (r) {
                    return r.post_id !== postId;
                });

                // Update stats counters
                var total = parseInt($('#stat-total').text(), 10) || 0;
                var withTitles = parseInt($('#stat-with-titles').text(), 10) || 0;
                var withoutTitles = parseInt($('#stat-without-titles').text(), 10) || 0;

                total = Math.max(0, total - 1);
                if (skippedIssue === 'missing') {
                    withoutTitles = Math.max(0, withoutTitles - 1);
                } else {
                    withTitles = Math.max(0, withTitles - 1);
                }

                $('#stat-total').text(total);
                $('#stat-with-titles').text(withTitles);
                $('#stat-without-titles').text(withoutTitles);

                // Fix current page if it now exceeds total pages
                if (TitleTag.currentPage > totalPages()) {
                    TitleTag.currentPage = Math.max(1, totalPages());
                }

                // Re-render table and pagination
                renderCurrentPage();
            }, 400);
        }, 300);
    }

    /* =========================================================
     * Bulk Generate
     * ========================================================= */
    function bulkGenerate() {
        if (!titleTagData.hasApiKey) {
            showToast('OpenAI API key not configured.', 'error'); return;
        }

        var $rows = $('#titletag-tbody .titletag-row:not(.titletag-row-skipped)');
        var total = $rows.length;
        if (total === 0) { showToast('No rows visible to generate.', 'error'); return; }

        TitleTag.cancelGeneration = false;
        $('#titletag-bulk-progress').show();
        $('#titletag-bulk-generate-btn').prop('disabled', true);
        updateBulkProgress(0, total);

        var rowArray = $rows.toArray();
        var idx = 0;

        function next() {
            if (TitleTag.cancelGeneration) { finishBulkGenerate(true); return; }
            if (idx >= rowArray.length) { finishBulkGenerate(false); return; }
            var $row = $(rowArray[idx++]);
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
            // Clear all generated suggestions and reset char counters
            $('.titletag-suggested-editable').text('').removeClass('has-suggestion');
            $('.titletag-char-count').text('0').removeClass('chars-ok chars-short chars-long');
            $('.titletag-apply-btn').prop('disabled', true);
            showToast('Generation cancelled.', 'error');
        } else {
            showToast('Bulk generation complete!');
        }
    }

    /* =========================================================
     * Bulk Apply
     * ========================================================= */
    function bulkApply() {
        var changes = [];
        $('#titletag-tbody .titletag-row:not(.titletag-row-skipped)').each(function () {
            var $row = $(this);
            var postId = parseInt($row.data('post-id'), 10);
            var postUrl = $row.attr('data-post-url') || '';
            var oldTitle = $row.attr('data-old-title') || '';
            var newTitle = $row.find('.titletag-suggested-editable').text().trim();
            if (postId && newTitle) {
                changes.push({ post_id: postId, new_title: newTitle, post_url: postUrl, old_title: oldTitle });
            }
        });

        if (changes.length === 0) {
            showToast('No suggestions to apply. Use Generate first.', 'error'); return;
        }

        if (!confirm('Apply all ' + changes.length + ' suggested titles now?')) { return; }

        $('#titletag-bulk-apply-btn').prop('disabled', true).text('Applying\u2026');

        $.ajax({
            url: titleTagData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'titletag_bulk_apply',
                nonce: titleTagData.nonce,
                changes: JSON.stringify(changes.map(function (c) { return { post_id: c.post_id, new_title: c.new_title }; }))
            },
            success: function (res) {
                $('#titletag-bulk-apply-btn')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-yes"></span> Bulk Apply Titles Below');

                if (res.success) {
                    var s = res.data;
                    showToast('Applied: ' + s.applied + (s.failed ? ', Failed: ' + s.failed : ''));

                    changes.forEach(function (c) {
                        var $row = $('#titletag-tbody .titletag-row[data-post-id="' + c.post_id + '"]');
                        if (!$row.length) { return; }
                        // Green row flash for bulk too
                        $row.addClass('titletag-row-green');
                        $row.find('.titletag-current-title-text').text(c.new_title);
                        $row.find('.titletag-suggested-editable').text('').removeClass('has-suggestion');
                        $row.find('.titletag-char-count').text('0').removeClass('chars-ok chars-short chars-long');
                        $row.find('.titletag-apply-btn').prop('disabled', true).text('Apply');
                        setTimeout(function () { $row.removeClass('titletag-row-green'); }, 3500);
                        TitleTag.appliedChanges.push({ post_url: c.post_url, old_title: c.old_title, new_title: c.new_title });
                    });

                    if (TitleTag.appliedChanges.length > 0) { $('#titletag-export-csv-btn').show(); }
                } else {
                    showToast('Bulk apply error: ' + res.data.message, 'error');
                }
            },
            error: function () {
                $('#titletag-bulk-apply-btn')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-yes"></span> Bulk Apply Titles Below');
                showToast('Request failed.', 'error');
            }
        });
    }

    /* =========================================================
     * Export CSV
     * ========================================================= */
    function exportCSV() {
        if (TitleTag.appliedChanges.length === 0) {
            showToast('No applied changes to export.', 'error'); return;
        }

        $('#titletag-export-csv-btn').prop('disabled', true).text('Exporting\u2026');

        $.ajax({
            url: titleTagData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'titletag_export_csv',
                nonce: titleTagData.nonce,
                changes: JSON.stringify(TitleTag.appliedChanges)
            },
            success: function (res) {
                $('#titletag-export-csv-btn')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-download"></span> Export Changes in CSV');
                if (res.success) {
                    window.location.href = res.data.download_url;
                    showToast('CSV ready — downloading.');
                } else {
                    showToast('Export error: ' + res.data.message, 'error');
                }
            },
            error: function () {
                $('#titletag-export-csv-btn')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-download"></span> Export Changes in CSV');
                showToast('Export failed.', 'error');
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
        if (len === 0) { /* no colour */ }
        else if (len < 30) { $counter.addClass('chars-short'); }
        else if (len > 60) { $counter.addClass('chars-long'); }
        else { $counter.addClass('chars-ok'); }
    }

    function setRowStatus($row, msg, type) {
        var $s = $row.find('.titletag-action-status');
        $s.removeClass('success error').text(msg);
        if (type) { $s.addClass(type); }
    }

    function setProgress(pct) {
        $('#titletag-progress-fill').css('width', pct + '%');
        $('#titletag-progress-pct').text(pct + '%');
    }

    function showToast(msg, type) {
        var $t = $('<div class="titletag-toast"><span class="titletag-toast-message"></span></div>');
        $t.find('.titletag-toast-message').text(msg);
        if (type === 'error') { $t.addClass('toast-error'); }
        $('body').append($t);
        setTimeout(function () { $t.addClass('show'); }, 50);
        setTimeout(function () {
            $t.removeClass('show');
            setTimeout(function () { $t.remove(); }, 400);
        }, 3500);
    }

})(jQuery);
