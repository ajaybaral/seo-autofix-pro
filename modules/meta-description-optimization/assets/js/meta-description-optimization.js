/**
 * Meta Description Optimization — Admin JavaScript
 * Fully isolated. No imports from Image SEO or Broken URL modules.
 *
 * @package SEO_AutoFix_Pro
 */
/* global metaDescData, jQuery */
(function ($) {
    'use strict';

    /* =========================================================
     * Constants
     * ========================================================= */
    var PAGE_SIZE = 25;

    /* =========================================================
     * Module State
     * ========================================================= */
    var MetaDesc = {
        allRows: [],            // All rows from current scan
        typeFilteredRows: [],   // After post type filter
        visibleRows: [],        // After issue filter applied
        activeFilter: '',       // '' = no filter (show all)
        postTypeFilter: 'all',  // 'all', 'post', or 'page'
        currentPage: 1,
        cancelGeneration: false,
        appliedChanges: [],     // For CSV export — resets on filter change
        skippedIds: [],
        lockedUrls: [],         // URLs locked from bulk actions
        scanSnapshot: []        // Deep copy of allRows at scan completion (for undo)
    };

    /* =========================================================
     * DOM Ready
     * ========================================================= */
    $(document).ready(function () {
        bindEvents();
    });

    function bindEvents() {
        $('#metadesc-scan-btn').on('click', startScan);
        $('#metadesc-reset-filter-btn').on('click', resetFilter);
        $('#metadesc-bulk-generate-btn').on('click', bulkGenerate);
        $('#metadesc-bulk-apply-btn').on('click', bulkApply);
        $('#metadesc-cancel-btn').on('click', function () { MetaDesc.cancelGeneration = true; });
        $('#metadesc-export-csv-btn').on('click', exportCSV);
        $('#metadesc-scan-export-btn').on('click', exportScanCSV);
        $('#metadesc-undo-btn').on('click', undoChanges);

        // Post type dropdown
        $('#metadesc-posttype-filter').on('change', function () {
            applyPostTypeFilter($(this).val());
        });

        // Lock modal
        $('#metadesc-lock-btn').on('click', openLockModal);
        $('.metadesc-lock-modal-close, .metadesc-lock-modal-overlay').on('click', closeLockModal);
        $('#metadesc-lock-done-btn').on('click', processLockUrls);
        $('#metadesc-lock-clear-btn').on('click', function () {
            $('#metadesc-lock-urls-input').val('');
        });

        // Filter radio cards — ignore clicks on the <input> itself to prevent double-fire
        $(document).on('click', '.metadesc-filter-card', function (e) {
            if ($(e.target).is('input')) { return; }
            var val = $(this).data('filter');
            setFilter(val);
            $(this).find('input[type="radio"]').prop('checked', true);
        });

        // Row-level events (delegated)
        $('#metadesc-tbody')
            .on('click', '.metadesc-generate-btn', function () {
                generateSingle($(this).closest('tr'), false);
            })
            .on('click', '.metadesc-apply-btn', function () {
                applySingle($(this).closest('tr'));
            })
            .on('click', '.metadesc-skip-btn', function () {
                skipRow($(this).closest('tr'));
            })
            .on('input', '.metadesc-suggested-editable', function () {
                var $row = $(this).closest('tr');
                updateCharCounter($(this));
                $row.find('.metadesc-apply-btn').prop('disabled', $(this).text().trim().length === 0);
            });
    }

    /* =========================================================
     * Scan
     * ========================================================= */
    function startScan() {
        $('#metadesc-scan-btn').prop('disabled', true).text('Scanning\u2026');
        $('#metadesc-empty-state').hide();
        $('#metadesc-results').hide();
        $('#metadesc-stats').hide();
        $('#metadesc-controls').hide();
        $('#metadesc-scan-progress').show();
        setProgress(0);

        MetaDesc.allRows = [];
        MetaDesc.appliedChanges = [];
        MetaDesc.skippedIds = [];
        MetaDesc.activeFilter = '';
        MetaDesc.currentPage = 1;

        fetchScanBatch(0);
    }

    function fetchScanBatch(offset) {
        $.ajax({
            url: metaDescData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'metadesc_scan',
                nonce: metaDescData.nonce,
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
                MetaDesc.allRows = MetaDesc.allRows.concat(data.results || []);
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
        $('#metadesc-scan-progress').hide();
        $('#metadesc-scan-btn')
            .prop('disabled', false)
            .html('<span class="dashicons dashicons-search"></span> Scan Posts &amp; Pages');

        if (MetaDesc.allRows.length === 0) {
            $('#metadesc-scan-export-btn').hide();
            $('#metadesc-empty-state').show(); return;
        }

        $('#metadesc-stats').show();
        $('#metadesc-controls').show();
        $('#metadesc-results').show();

        // Initialize with current post type filter and no issue filter
        // Take snapshot for undo
        MetaDesc.scanSnapshot = JSON.parse(JSON.stringify(MetaDesc.allRows));
        MetaDesc.appliedChanges = [];
        updateUndoState();

        MetaDesc.postTypeFilter = $('#metadesc-posttype-filter').val() || 'all';
        applyPostTypeFilter(MetaDesc.postTypeFilter);

        // Show the scan-results Export CSV button if there are problematic rows
        var problemRows = MetaDesc.allRows.filter(function (r) {
            return r.issue_type !== 'ok';
        });
        if (problemRows.length > 0) {
            $('#metadesc-scan-export-btn').show();
        } else {
            $('#metadesc-scan-export-btn').hide();
        }

        showToast('Scan complete. ' + MetaDesc.allRows.length + ' posts/pages found.');
    }

    /* =========================================================
     * Stats
     * ========================================================= */
    function renderStats(stats) {
        $('#stat-total').text(stats.total || 0);
        $('#stat-with-descriptions').text(stats.with_descriptions || 0);
        $('#stat-without-descriptions').text(stats.without_descriptions || 0);
    }

    /* =========================================================
     * Post Type Filter (dimension filter)
     * ========================================================= */
    function applyPostTypeFilter(postType) {
        MetaDesc.postTypeFilter = postType;

        // Filter allRows by post type
        if (postType === 'all') {
            MetaDesc.typeFilteredRows = MetaDesc.allRows.slice();
        } else {
            MetaDesc.typeFilteredRows = MetaDesc.allRows.filter(function (r) {
                return r.post_type === postType;
            });
        }

        // Recalculate stats from typeFilteredRows
        recalcStats();

        // Update filter counts
        updateFilterCounts();

        // Reset issue filter and re-apply
        resetFilter();
    }

    function recalcStats() {
        var rows = MetaDesc.typeFilteredRows;
        var total = rows.length;
        var missing = 0;

        rows.forEach(function (r) {
            if (r.issue_type === 'missing') { missing++; }
        });

        $('#stat-total').text(total);
        $('#stat-without-descriptions').text(missing);
        $('#stat-with-descriptions').text(total - missing);
    }

    /* =========================================================
     * Filter Count Badges
     * ========================================================= */
    function updateFilterCounts() {
        var counts = { missing: 0, too_short: 0, too_long: 0, duplicate: 0 };

        MetaDesc.typeFilteredRows.forEach(function (r) {
            if (counts.hasOwnProperty(r.issue_type)) {
                counts[r.issue_type]++;
            }
        });

        $('.metadesc-filter-card').each(function () {
            var filter = $(this).data('filter');
            var count = counts[filter] || 0;
            var $countSpan = $(this).find('.metadesc-filter-count');
            $countSpan.text('(' + count + ')');

            // Visually dim zero-count cards
            if (count === 0) {
                $(this).addClass('metadesc-filter-card-empty');
            } else {
                $(this).removeClass('metadesc-filter-card-empty');
            }
        });
    }

    /* =========================================================
     * Issue Filter
     * ========================================================= */
    function setFilter(filter) {
        // Toggle: clicking the already-active filter deselects it
        if (MetaDesc.activeFilter === filter) {
            resetFilter(); return;
        }
        applyFilter(filter);
        // Mark card active
        $('.metadesc-filter-card').removeClass('metadesc-filter-active');
        $('.metadesc-filter-card[data-filter="' + filter + '"]').addClass('metadesc-filter-active');
    }

    function applyFilter(filter) {
        MetaDesc.activeFilter = filter;
        MetaDesc.appliedChanges = [];
        MetaDesc.currentPage = 1;
        $('#metadesc-export-csv-btn').hide();

        if (filter === '') {
            MetaDesc.visibleRows = MetaDesc.typeFilteredRows.slice();
        } else {
            MetaDesc.visibleRows = MetaDesc.typeFilteredRows.filter(function (r) {
                return r.issue_type === filter;
            });
        }

        renderCurrentPage();
    }

    function resetFilter() {
        MetaDesc.activeFilter = '';
        // Force-uncheck via native DOM to avoid browser caching issues
        $('input[name="metadesc-filter"]').each(function () { this.checked = false; });
        $('.metadesc-filter-card').removeClass('metadesc-filter-active');
        applyFilter('');
    }

    /* =========================================================
     * Lock Rows
     * ========================================================= */
    function normalizeUrl(url) {
        return url.replace(/^https?:\/\//, '').replace(/\/+$/, '').toLowerCase();
    }

    function isRowLocked(postUrl) {
        if (!MetaDesc.lockedUrls.length) { return false; }
        var norm = normalizeUrl(postUrl);
        return MetaDesc.lockedUrls.some(function (u) {
            return normalizeUrl(u) === norm;
        });
    }

    function getSiteDomain() {
        try {
            return new URL(metaDescData.ajaxUrl).hostname.toLowerCase();
        } catch (e) { return ''; }
    }

    function openLockModal() {
        // Populate textarea with currently locked URLs
        $('#metadesc-lock-urls-input').val(MetaDesc.lockedUrls.join('\n'));
        $('#metadesc-lock-modal').show();
    }

    function closeLockModal() {
        $('#metadesc-lock-modal').hide();
    }

    function processLockUrls() {
        var raw = $('#metadesc-lock-urls-input').val();
        var lines = raw.split('\n').map(function (l) { return l.trim(); }).filter(function (l) { return l.length > 0; });
        var hadLockedBefore = MetaDesc.lockedUrls.length > 0;

        // Build a lookup map of all known post URLs (normalised)
        var knownMap = {};
        MetaDesc.allRows.forEach(function (r) {
            knownMap[normalizeUrl(r.post_url)] = r.post_url; // normalised → original
        });

        var siteDomain = getSiteDomain();
        var validUrls = [];
        var notFound = [];
        var external = [];

        lines.forEach(function (url) {
            // Check if external domain
            try {
                var parsed = new URL(url);
                if (siteDomain && parsed.hostname.toLowerCase() !== siteDomain) {
                    external.push(url);
                    return;
                }
            } catch (e) {
                // Not a valid URL — treat as relative or malformed, try matching anyway
            }

            var norm = normalizeUrl(url);
            if (knownMap[norm]) {
                validUrls.push(knownMap[norm]); // Store the original URL
            } else {
                notFound.push(url);
            }
        });

        // Show alert for invalid URLs
        var msgs = [];
        if (external.length) {
            msgs.push('External URLs (not your domain):\n\u2022 ' + external.join('\n\u2022 '));
        }
        if (notFound.length) {
            msgs.push('URLs not found in scan results:\n\u2022 ' + notFound.join('\n\u2022 '));
        }
        if (msgs.length) {
            alert('Some URLs could not be locked:\n\n' + msgs.join('\n\n'));
        }

        // Update locked URLs and re-render
        MetaDesc.lockedUrls = validUrls;
        closeLockModal();
        renderCurrentPage();

        if (validUrls.length > 0) {
            showToast(validUrls.length + ' row(s) locked.');
        } else if (hadLockedBefore && validUrls.length === 0) {
            showToast('All rows unlocked.');
        }
    }

    /* =========================================================
     * Pagination helpers
     * =========================================================*/
    function totalPages() {
        return Math.max(1, Math.ceil(MetaDesc.visibleRows.length / PAGE_SIZE));
    }

    function pageRows() {
        var start = (MetaDesc.currentPage - 1) * PAGE_SIZE;
        return MetaDesc.visibleRows.slice(start, start + PAGE_SIZE);
    }

    function renderCurrentPage() {
        renderTable(pageRows(), (MetaDesc.currentPage - 1) * PAGE_SIZE);
        renderPagination();
    }

    /* =========================================================
     * Table Rendering
     * ========================================================= */
    function renderTable(rows, startIdx) {
        var $tbody = $('#metadesc-tbody');
        $tbody.empty();

        if (MetaDesc.visibleRows.length === 0) {
            $tbody.append(
                '<tr><td colspan="6" style="text-align:center;padding:30px;color:#646970;">' +
                (MetaDesc.activeFilter ? 'No issues match the selected filter.' : 'No posts found.') +
                '</td></tr>'
            );
            return;
        }

        var tmpl = document.getElementById('metadesc-row-template');

        rows.forEach(function (row, i) {
            var globalIdx = startIdx + i;
            var $tr = $(document.importNode(tmpl.content, true).firstElementChild);

            $tr.attr('data-post-id', row.post_id);
            $tr.attr('data-issue', row.issue_type);
            $tr.attr('data-post-url', row.post_url);
            $tr.attr('data-old-description', row.rendered_description);

            $tr.find('.metadesc-col-num').text(globalIdx + 1);
            $tr.find('.metadesc-post-title').text(row.post_title);
            $tr.find('.metadesc-post-type-badge').text(row.post_type);
            $tr.find('.metadesc-edit-link').attr('href', row.edit_url);
            $tr.find('.metadesc-post-url').attr('href', row.post_url).text(shortenUrl(row.post_url));
            $tr.find('.metadesc-current-description-text').text(row.rendered_description || '(empty)');
            $tr.find('.metadesc-issue-badge-wrap').html(buildIssueBadge(row.issue_type));
            var currentDescLen = (row.rendered_description || '').length;
            $tr.find('.metadesc-current-char-count').text(currentDescLen + ' chars');
            $tr.find('.metadesc-char-count').text('0');

            // Lock styling
            if (isRowLocked(row.post_url)) {
                $tr.addClass('metadesc-row-locked');
                $tr.find('.metadesc-generate-btn').prop('disabled', true);
                $tr.find('.metadesc-action-status').html(
                    '<span class="metadesc-lock-badge">' +
                    '<span class="dashicons dashicons-lock"></span> Locked' +
                    '</span>'
                );
            }

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
        return '<span class="metadesc-issue-badge metadesc-badge-' + issue_type + '">' +
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
        var $pag = $('#metadesc-pagination');
        var $info = $('#metadesc-pagination-info');
        var $ctrl = $('#metadesc-pagination-controls');
        var total = MetaDesc.visibleRows.length;
        var tp = totalPages();
        var cp = MetaDesc.currentPage;

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
                $ctrl.append('<span class="metadesc-pagination-ellipsis">\u2026</span>');
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
        MetaDesc.currentPage = page;
        renderCurrentPage();
        // Scroll to table top
        $('html, body').animate({ scrollTop: $('#metadesc-results').offset().top - 40 }, 200);
    }

    /* =========================================================
     * Single Generate
     * ========================================================= */
    function generateSingle($row, isBulk) {
        var postId = $row.data('post-id');
        var $editable = $row.find('.metadesc-suggested-editable');
        var $indicator = $row.find('.metadesc-generating-indicator');
        var $genBtn = $row.find('.metadesc-generate-btn');
        var $applyBtn = $row.find('.metadesc-apply-btn');

        $editable.hide();
        $indicator.show();
        $genBtn.prop('disabled', true);
        $applyBtn.prop('disabled', true);

        return $.ajax({
            url: metaDescData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'metadesc_generate',
                nonce: metaDescData.nonce,
                post_id: postId,
                force: 'false'
            }
        }).then(function (res) {
            $indicator.hide();
            $editable.show();
            $genBtn.prop('disabled', false);

            if (res.success) {
                $editable.text(res.data.description).addClass('has-suggestion');
                updateCharCounter($editable);
                $applyBtn.prop('disabled', false);
                // Show primary keyword if returned
                var $kwEl = $row.find('.metadesc-primary-keyword');
                if (res.data.keyword) {
                    $kwEl.text('Primary Keyword: ' + res.data.keyword).show();
                } else {
                    $kwEl.text('').hide();
                }
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
        var oldDescription = $row.attr('data-old-description') || '';
        var newDescription = $row.find('.metadesc-suggested-editable').text().trim();

        if (!newDescription) { showToast('Description cannot be empty.', 'error'); return; }

        var $applyBtn = $row.find('.metadesc-apply-btn');
        $applyBtn.prop('disabled', true).text('Applying\u2026');
        setRowStatus($row, '', '');

        $.ajax({
            url: metaDescData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'metadesc_apply',
                nonce: metaDescData.nonce,
                post_id: postId,
                new_description: newDescription
            },
            success: function (res) {
                if (res.success) {
                    $row.addClass('metadesc-row-green');
                    $applyBtn.addClass('metadesc-apply-btn-applied').text('Applied').prop('disabled', true);

                    $row.attr('data-old-description', newDescription);
                    MetaDesc.appliedChanges.push({ post_id: postId, post_url: postUrl, old_description: oldDescription, new_description: newDescription });
                    updateUndoState();

                    setTimeout(function () {
                        $row.find('.metadesc-current-description-text').text(newDescription);

                        var newLen = newDescription.length;
                        var newIssue = newLen === 0 ? 'missing'
                            : newLen < 60 ? 'too_short'
                                : newLen > 120 ? 'too_long'
                                    : 'ok';
                        $row.find('.metadesc-issue-badge-wrap').html(buildIssueBadge(newIssue));
                        $row.find('.metadesc-current-char-count').text(newLen + ' chars');

                        $row.find('.metadesc-suggested-editable').text('').removeClass('has-suggestion');
                        $row.find('.metadesc-char-count').text('0').removeClass('chars-ok chars-short chars-long');
                        $row.find('.metadesc-primary-keyword').text('').hide();

                        $applyBtn.removeClass('metadesc-apply-btn-applied').text('Apply').prop('disabled', true);
                        $row.removeClass('metadesc-row-green');
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
        MetaDesc.skippedIds.push(postId);

        // Determine the skipped row's issue type for stat adjustment

        // Disable buttons immediately
        $row.find('.metadesc-apply-btn, .metadesc-generate-btn, .metadesc-skip-btn').prop('disabled', true);

        // Step 1: Turn row grey
        $row.addClass('metadesc-row-being-skipped');

        // Step 2: After grey shows, fade out
        setTimeout(function () {
            $row.addClass('metadesc-row-fade-out');

            // Step 3: After fade-out completes, remove from data and re-render
            setTimeout(function () {
                // Remove from data arrays
                MetaDesc.allRows = MetaDesc.allRows.filter(function (r) {
                    return r.post_id !== postId;
                });
                MetaDesc.typeFilteredRows = MetaDesc.typeFilteredRows.filter(function (r) {
                    return r.post_id !== postId;
                });
                MetaDesc.visibleRows = MetaDesc.visibleRows.filter(function (r) {
                    return r.post_id !== postId;
                });

                // Recalculate stats from current type-filtered rows
                recalcStats();

                // Update filter count badges after skip
                updateFilterCounts();

                // Fix current page if it now exceeds total pages
                if (MetaDesc.currentPage > totalPages()) {
                    MetaDesc.currentPage = Math.max(1, totalPages());
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
        if (!metaDescData.hasApiKey) {
            showToast('OpenAI API key not configured.', 'error'); return;
        }

        var $rows = $('#metadesc-tbody .metadesc-row:not(.metadesc-row-skipped):not(.metadesc-row-locked)');
        var total = $rows.length;
        if (total === 0) { showToast('No rows visible to generate.', 'error'); return; }

        MetaDesc.cancelGeneration = false;
        $('#metadesc-bulk-progress').show();
        $('#metadesc-bulk-generate-btn').prop('disabled', true);
        updateBulkProgress(0, total);

        var rowArray = $rows.toArray();
        var idx = 0;

        function next() {
            if (MetaDesc.cancelGeneration) { finishBulkGenerate(true); return; }
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
        $('#metadesc-bulk-progress-text').text('Generating: ' + done + ' of ' + total);
        $('#metadesc-bulk-progress-fill').css('width', pct + '%');
    }

    function finishBulkGenerate(cancelled) {
        $('#metadesc-bulk-progress').hide();
        $('#metadesc-bulk-generate-btn').prop('disabled', false);
        if (cancelled) {
            // Clear all generated suggestions, char counters, and keywords
            $('.metadesc-suggested-editable').text('').removeClass('has-suggestion');
            $('.metadesc-char-count').text('0').removeClass('chars-ok chars-short chars-long');
            $('.metadesc-primary-keyword').text('').hide();
            $('.metadesc-apply-btn').prop('disabled', true);
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
        $('#metadesc-tbody .metadesc-row:not(.metadesc-row-skipped):not(.metadesc-row-locked)').each(function () {
            var $row = $(this);
            var postId = parseInt($row.data('post-id'), 10);
            var postUrl = $row.attr('data-post-url') || '';
            var oldDescription = $row.attr('data-old-description') || '';
            var newDescription = $row.find('.metadesc-suggested-editable').text().trim();
            if (postId && newDescription) {
                changes.push({ post_id: postId, new_description: newDescription, post_url: postUrl, old_description: oldDescription });
            }
        });

        if (changes.length === 0) {
            showToast('No suggestions to apply. Use Generate first.', 'error'); return;
        }

        if (!confirm('Apply all ' + changes.length + ' suggested descriptions now?')) { return; }

        $('#metadesc-bulk-apply-btn').prop('disabled', true).text('Applying\u2026');

        $.ajax({
            url: metaDescData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'metadesc_bulk_apply',
                nonce: metaDescData.nonce,
                changes: JSON.stringify(changes.map(function (c) { return { post_id: c.post_id, new_description: c.new_description }; }))
            },
            success: function (res) {
                $('#metadesc-bulk-apply-btn')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-yes"></span> Bulk Apply Descriptions Below');

                if (res.success) {
                    var s = res.data;
                    showToast('Applied: ' + s.applied + (s.failed ? ', Failed: ' + s.failed : ''));

                    changes.forEach(function (c) {
                        var $row = $('#metadesc-tbody .metadesc-row[data-post-id="' + c.post_id + '"]');
                        if (!$row.length) { return; }
                        // Green row flash for bulk too
                        $row.addClass('metadesc-row-green');
                        $row.find('.metadesc-current-description-text').text(c.new_description);
                        $row.find('.metadesc-current-char-count').text(c.new_description.length + ' chars');
                        $row.find('.metadesc-suggested-editable').text('').removeClass('has-suggestion');
                        $row.find('.metadesc-char-count').text('0').removeClass('chars-ok chars-short chars-long');
                        $row.find('.metadesc-apply-btn').prop('disabled', true).text('Apply');
                        setTimeout(function () { $row.removeClass('metadesc-row-green'); }, 3500);
                        MetaDesc.appliedChanges.push({ post_id: c.post_id, post_url: c.post_url, old_description: c.old_description, new_description: c.new_description });
                    });

                    updateUndoState();
                } else {
                    showToast('Bulk apply error: ' + res.data.message, 'error');
                }
            },
            error: function () {
                $('#metadesc-bulk-apply-btn')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-yes"></span> Bulk Apply Descriptions Below');
                showToast('Request failed.', 'error');
            }
        });
    }

    /* =========================================================
     * Export CSV
     * ========================================================= */
    function exportCSV() {
        if (MetaDesc.appliedChanges.length === 0) {
            showToast('No applied changes to export.', 'error'); return;
        }

        $('#metadesc-export-csv-btn').prop('disabled', true).text('Exporting\u2026');

        $.ajax({
            url: metaDescData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'metadesc_export_csv',
                nonce: metaDescData.nonce,
                changes: JSON.stringify(MetaDesc.appliedChanges)
            },
            success: function (res) {
                $('#metadesc-export-csv-btn')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-download"></span> Export Changes in CSV');
                if (res.success) {
                    // Use a hidden anchor with the download attribute so the
                    // browser triggers a file download instead of navigating.
                    var $a = $('<a>')
                        .attr('href', res.data.download_url)
                        .attr('download', '')
                        .css('display', 'none');
                    $('body').append($a);
                    $a[0].click();
                    $a.remove();
                    showToast('CSV ready \u2014 downloading.');
                } else {
                    showToast('Export error: ' + res.data.message, 'error');
                }
            },
            error: function () {
                $('#metadesc-export-csv-btn')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-download"></span> Export Changes in CSV');
                showToast('Export failed.', 'error');
            }
        });
    }

    /* =========================================================
     * Export Scan Results CSV (client-side Blob)
     * Exports all posts/pages with issues from the current scan.
     * ========================================================= */
    function exportScanCSV() {
        // Include all issue types except 'ok'
        var issueRows = MetaDesc.allRows.filter(function (r) {
            return r.issue_type !== 'ok';
        });

        if (issueRows.length === 0) {
            showToast('No issues found to export.', 'error'); return;
        }

        var issueLabels = {
            missing:   'Missing Description',
            too_short: 'Description Too Short (< 60 chars)',
            too_long:  'Description Too Long (> 120 chars)',
            duplicate: 'Duplicate Description'
        };

        // Build CSV string
        var lines = [];
        lines.push(csvRow(['Page Name', 'Page URL', 'Issue', 'Current Description']));
        issueRows.forEach(function (r) {
            lines.push(csvRow([
                r.post_title  || '',
                r.post_url    || '',
                issueLabels[r.issue_type] || r.issue_type,
                r.rendered_description || ''
            ]));
        });

        var csvContent = lines.join('\r\n');
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var filename = 'metadesc-issues-' + new Date().toISOString().slice(0, 10) + '.csv';

        var $a = $('<a>')
            .attr('href', url)
            .attr('download', filename)
            .css('display', 'none');
        $('body').append($a);
        $a[0].click();
        $a.remove();
        URL.revokeObjectURL(url);

        showToast('CSV downloaded \u2014 ' + issueRows.length + ' issue(s) exported.');
    }

    /* Helper: escape a single CSV field */
    function csvRow(fields) {
        return fields.map(function (f) {
            var s = String(f).replace(/"/g, '""');
            return '"' + s + '"';
        }).join(',');
    }

    /* =========================================================
     * Undo
     * ========================================================= */
    function updateUndoState() {
        var hasChanges = MetaDesc.appliedChanges.length > 0;
        $('#metadesc-undo-btn').prop('disabled', !hasChanges);
        if (hasChanges) {
            $('#metadesc-export-csv-btn').show();
        } else {
            $('#metadesc-export-csv-btn').hide();
        }
    }

    function undoChanges() {
        if (MetaDesc.appliedChanges.length === 0) { return; }
        if (!confirm('Undo all ' + MetaDesc.appliedChanges.length + ' applied change(s)? This will revert descriptions in the database to their original values.')) { return; }

        var $btn = $('#metadesc-undo-btn');
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-undo"></span> Undoing\u2026');

        // Deduplicate: if same post_id was changed multiple times, only revert to the FIRST old_description
        var revertMap = {};
        MetaDesc.appliedChanges.forEach(function (c) {
            if (!revertMap[c.post_id]) {
                revertMap[c.post_id] = c.old_description; // earliest old_description = original
            }
        });
        var revertPayload = Object.keys(revertMap).map(function (pid) {
            return { post_id: parseInt(pid, 10), new_description: revertMap[pid] };
        });

        $.ajax({
            url: metaDescData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'metadesc_bulk_apply',
                nonce: metaDescData.nonce,
                changes: JSON.stringify(revertPayload)
            },
            success: function (res) {
                $btn.html('<span class="dashicons dashicons-undo"></span> Undo');

                if (res.success) {
                    // Restore from snapshot
                    MetaDesc.allRows = JSON.parse(JSON.stringify(MetaDesc.scanSnapshot));
                    MetaDesc.appliedChanges = [];
                    updateUndoState();

                    // Re-apply filters and re-render
                    applyPostTypeFilter(MetaDesc.postTypeFilter);
                    showToast('All changes undone. Descriptions reverted to scan results.');
                } else {
                    showToast('Undo failed: ' + res.data.message, 'error');
                    $btn.prop('disabled', false);
                }
            },
            error: function () {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> Undo');
                showToast('Undo request failed.', 'error');
            }
        });
    }

    /* =========================================================
     * Helpers
     * ========================================================= */
    function updateCharCounter($editable) {
        var len = $editable.text().trim().length;
        var $counter = $editable.closest('td').find('.metadesc-char-count');
        $counter.text(len).removeClass('chars-ok chars-short chars-long');
        if (len === 0) { /* no colour */ }
        else if (len < 60) { $counter.addClass('chars-short'); }
        else if (len > 120) { $counter.addClass('chars-long'); }
        else { $counter.addClass('chars-ok'); }
    }

    function setRowStatus($row, msg, type) {
        var $s = $row.find('.metadesc-action-status');
        $s.removeClass('success error').text(msg);
        if (type) { $s.addClass(type); }
    }

    function setProgress(pct) {
        $('#metadesc-progress-fill').css('width', pct + '%');
        $('#metadesc-progress-pct').text(pct + '%');
    }

    function showToast(msg, type) {
        var $t = $('<div class="metadesc-toast"><span class="metadesc-toast-message"></span></div>');
        $t.find('.metadesc-toast-message').text(msg);
        if (type === 'error') { $t.addClass('toast-error'); }
        $('body').append($t);
        setTimeout(function () { $t.addClass('show'); }, 50);
        setTimeout(function () {
            $t.removeClass('show');
            setTimeout(function () { $t.remove(); }, 400);
        }, 3500);
    }

})(jQuery);
