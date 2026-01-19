/**
 * Broken URL Management - Frontend JavaScript
 */

(function ($) {
    'use strict';

    // State management
    let currentScanId = null;
    let currentFilter = 'all';
    let currentSearch = '';
    let currentPage = 1;
    let isScanning = false;
    let scanProgressInterval = null;

    // Initialize on document ready
    $(document).ready(function () {
        initializeEventListeners();

        // Check if there's a scan ID in URL (from "View Last Scan")
        const urlParams = new URLSearchParams(window.location.search);
        const scanId = urlParams.get('scan_id');
        if (scanId) {
            loadScanResults(scanId);
        }
    });

    /**
     * Initialize all event listeners
     */
    function initializeEventListeners() {
        // Header action buttons
        $('#start-auto-fix-btn').on('click', startNewScan);
        $('#export-report-btn').on('click', exportToCSV);

        // Filter dropdowns
        $('#filter-page-type, #filter-error-type, #filter-location').on('change', function () {
            console.log('[FILTER DROPDOWN] Changed:', $(this).attr('id'), 'Value:', $(this).val(), 'currentScanId:', currentScanId);
            if (!currentScanId) {
                console.log('[FILTER DROPDOWN] No scan ID, returning');
                return;
            }
            currentPage = 1;
            loadScanResults(currentScanId);
        });

        // Filter button
        $('#filter-btn').on('click', function () {
            console.log('[FILTER BUTTON] Clicked, currentScanId:', currentScanId);
            if (!currentScanId) {
                console.log('[FILTER BUTTON] No scan ID, returning');
                return;
            }
            currentSearch = $('#search-results').val();
            currentPage = 1;
            loadScanResults(currentScanId);
        });

        // Search input with debounce
        let searchTimeout;
        $('#search-results').on('input', function () {
            if (!currentScanId) return;
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function () {
                currentSearch = $('#search-results').val();
                currentPage = 1;
                loadScanResults(currentScanId);
            }, 500);
        });

        // Per page selector - use event delegation since it's recreated
        $(document).on('change', '#per-page-select', function () {
            console.log('[PER PAGE] Changed to:', $(this).val(), 'currentScanId:', currentScanId);
            if (!currentScanId) {
                console.log('[PER PAGE] No scan ID, returning');
                return;
            }
            currentPage = 1;
            loadScanResults(currentScanId);
        });

        // Pagination buttons - use event delegation since they're recreated
        $(document).on('click', '.page-btn:not(:disabled)', function () {
            console.log('[PAGINATION] Button clicked:', $(this).text(), 'data-page:', $(this).data('page'), 'currentScanId:', currentScanId);
            if (!currentScanId) {
                console.log('[PAGINATION] No scan ID, returning');
                return;
            }
            const page = $(this).data('page');
            console.log('[PAGINATION] Page value:', page, 'hasClass active:', $(this).hasClass('active'));
            if (page && !$(this).hasClass('active')) {
                currentPage = parseInt(page);
                console.log('[PAGINATION] Setting currentPage to:', currentPage);
                loadScanResults(currentScanId);
            } else {
                console.log('[PAGINATION] Not loading - either no page or already active');
            }
        });

        // Auto-fix panel radio buttons
        $('input[name="fix-action"]').on('change', function () {
            if ($(this).val() === 'custom') {
                $('#custom-url-input').show();
            } else {
                $('#custom-url-input').hide();
            }
        });

        // Auto-fix panel buttons
        $('#apply-fix-btn').on('click', applyCurrentFix);
        $('#skip-fix-btn').on('click', function () {
            $('#auto-fix-panel').hide();
        });

        // Bulk action buttons
        $('#remove-broken-links-btn').on('click', removeBrokenLinks);
        $('#replace-broken-links-btn').on('click', replaceBrokenLinks);
        $('#fix-all-issues-btn').on('click', fixAllIssues);

        // History & Export buttons
        $('#undo-changes-btn').on('click', undoChanges);
        $('#download-report-btn, #download-report-empty-btn').on('click', downloadReport);
        $('#email-report-btn, #email-report-empty-btn').on('click', emailReport);
    }

    /**
     * Start a new scan
     */
    function startNewScan() {
        console.log('[SCAN DEBUG] startNewScan() called');

        if (isScanning) {
            console.log('[SCAN DEBUG] Already scanning, aborting');
            alert(seoautofixBrokenUrls.strings.scanInProgress);
            return;
        }

        if (!confirm('This will scan your entire website for broken links. This may take several minutes. Continue?')) {
            console.log('[SCAN DEBUG] User cancelled confirmation');
            return;
        }

        console.log('[SCAN DEBUG] Setting up scan UI');
        isScanning = true;
        $('#start-scan-btn').prop('disabled', true).text(seoautofixBrokenUrls.strings.startingScan);
        $('#scan-progress-container').show();
        $('#results-container').hide();
        $('#empty-state').hide();

        console.log('[SCAN DEBUG] Sending AJAX request to:', seoautofixBrokenUrls.ajaxUrl);
        console.log('[SCAN DEBUG] AJAX data:', {
            action: 'seoautofix_broken_links_start_scan',
            nonce: seoautofixBrokenUrls.nonce
        });

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_start_scan',
                nonce: seoautofixBrokenUrls.nonce
            },
            success: function (response) {
                console.log('[SCAN DEBUG] AJAX success response:', response);

                if (response.success) {
                    console.log('[SCAN DEBUG] Scan started successfully, scan_id:', response.data.scan_id);
                    currentScanId = response.data.scan_id;
                    startProgressMonitoring();
                } else {
                    console.error('[SCAN DEBUG] Scan failed:', response.data.message);
                    alert(response.data.message || seoautofixBrokenUrls.strings.error);
                    resetScanState();
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[SCAN DEBUG] AJAX error:', {
                    status: jqXHR.status,
                    statusText: textStatus,
                    error: errorThrown,
                    response: jqXHR.responseText
                });
                alert(seoautofixBrokenUrls.strings.error);
                resetScanState();
            }
        });
    }

    /**
     * Start monitoring scan progress and processing batches
     */
    function startProgressMonitoring() {
        console.log('[SCAN DEBUG] startProgressMonitoring() called, scan_id:', currentScanId);

        // Process the first batch immediately
        processBatch();
    }

    /**
     * Process a batch of URLs
     */
    function processBatch() {
        console.log('[SCAN DEBUG] processBatch() called for scan_id:', currentScanId);

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_process_batch',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: currentScanId
            },
            success: function (response) {
                console.log('[SCAN DEBUG] Batch response:', response);

                if (response.success) {
                    const data = response.data;

                    // Update progress
                    updateProgressBar({
                        progress: data.progress || 0,
                        tested_urls: data.pages_processed || 0,
                        total_urls: data.total_pages || 0,
                        broken_count: 0, // Will get from results
                        status: data.completed ? 'completed' : 'in_progress'
                    });

                    // Don't load results during scanning to prevent flickering
                    // Results will be loaded when scan completes

                    if (data.completed) {
                        console.log('[SCAN DEBUG] Scan completed!');
                        onScanComplete();
                    } else {
                        // Process next batch after a short delay
                        setTimeout(processBatch, 500);
                    }
                } else {
                    console.error('[SCAN DEBUG] Batch processing failed:', response.data);
                    alert('Batch processing failed: ' + (response.data.message || 'Unknown error'));
                    resetScanState();
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[SCAN DEBUG] Batch processing error:', {
                    status: jqXHR.status,
                    statusText: textStatus,
                    error: errorThrown
                });
                alert('Error processing batch. See console for details.');
                resetScanState();
            }
        });
    }

    /**
     * Update progress bar
     */
    function updateProgressBar(data) {
        $('#scan-progress-percentage').text(data.progress + '%');
        $('#scan-progress-fill').css('width', data.progress + '%');
        $('#scan-urls-tested').text(data.tested_urls);
        $('#scan-urls-total').text(data.total_urls);
        $('#scan-broken-count').text(data.broken_count);
    }

    /**
     * Handle scan completion
     */
    function onScanComplete() {
        isScanning = false;
        $('#scan-progress-text').text(seoautofixBrokenUrls.strings.scanComplete);

        // Load final results first to prevent disappearing
        loadScanResults(currentScanId);

        // Then hide progress bar after a short delay
        setTimeout(function () {
            $('#scan-progress-container').hide();
            resetScanButton();
        }, 1500);
    }

    /**
     * Reset scan state
     */
    function resetScanState() {
        isScanning = false;
        $('#scan-progress-container').hide();
        resetScanButton();
        if (scanProgressInterval) {
            clearInterval(scanProgressInterval);
        }
    }

    /**
     * Reset scan button
     */
    function resetScanButton() {
        $('#start-scan-btn').prop('disabled', false).html(
            '<span class="dashicons dashicons-search"></span> Start New Scan'
        );
    }

    /**
     * Load scan results
     */
    function loadScanResults(scanId) {
        console.log('[LOAD SCAN RESULTS] Called with scanId:', scanId);
        currentScanId = scanId;

        // Get filter values from dropdowns
        const pageType = $('#filter-page-type').val() || 'all';
        const errorType = $('#filter-error-type').val() || 'all';
        const location = $('#filter-location').val() || 'all';
        const perPage = $('#per-page-select').val() || 25;

        console.log('[LOAD SCAN RESULTS] Filter values:', {
            pageType,
            errorType,
            location,
            currentPage,
            perPage,
            currentSearch
        });

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'GET',
            data: {
                action: 'seoautofix_broken_links_get_results',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: scanId,
                filter: currentFilter, // Keep for backward compatibility
                page_type: pageType,
                error_type: errorType,
                location: location,
                search: currentSearch,
                page: currentPage,
                per_page: perPage
            },
            success: function (response) {
                if (response.success) {
                    displayResults(response.data);
                } else {
                    alert(response.data.message || seoautofixBrokenUrls.strings.error);
                }
            },
            error: function () {
                alert(seoautofixBrokenUrls.strings.error);
            }
        });
    }

    /**
     * Display results in table
     */
    function displayResults(data) {
        console.log('[DISPLAY RESULTS] Data received:', data);
        console.log('[DISPLAY RESULTS] Stats:', data.stats);

        const results = data.results;
        const total = data.total;

        // Update header stats
        updateHeaderStats(data.stats);

        // Show/hide appropriate sections
        if (total === 0) {
            $('#results-container').hide();
            $('#empty-state').show();
            return;
        }

        $('#results-container').show();
        $('#empty-state').hide();

        // Clear table
        $('#results-table-body').empty();

        // Calculate serial number offset
        const offset = (data.current_page - 1) * data.per_page;

        // Populate table
        results.forEach(function (result, index) {
            const row = createResultRow(result, offset + index + 1);
            $('#results-table-body').append(row);
        });

        // Update pagination
        updatePagination(data);

        // Update filter counts (simplified - just show current results)
        updateFilterCounts(data);
    }

    /**
     * Create result table row
     */
    function createResultRow(result, serialNumber) {
        const isFixed = result.is_fixed == 1;
        const statusCode = result.status_code || 404;
        const errorType = statusCode >= 500 ? '5xx' : '4xx';
        const errorClass = statusCode >= 500 ? 'error-5xx' : 'error-4xx';

        // Determine link type display (Anchor Text vs Naked Link)
        let linkTypeDisplay = '';
        console.log('[ANCHOR TEXT CHECK]', {
            anchor_text: result.anchor_text,
            anchor_text_type: typeof result.anchor_text,
            anchor_text_length: result.anchor_text ? result.anchor_text.length : 0,
            trimmed: result.anchor_text ? result.anchor_text.trim() : ''
        });

        if (result.anchor_text && result.anchor_text.trim() !== '' && result.anchor_text.trim() !== result.broken_url) {
            linkTypeDisplay = 'Anchor Text: "' + escapeHtml(result.anchor_text) + '"';
        } else {
            linkTypeDisplay = 'Naked Link:';
        }

        // Get page title or use found_on_url
        console.log('[CREATE ROW] Result data:', {
            found_on_page_title: result.found_on_page_title,
            found_on_url: result.found_on_url,
            broken_url: result.broken_url,
            link_type: result.link_type,
            anchor_text: result.anchor_text
        });
        const pageTitle = result.found_on_page_title || extractPageName(result.found_on_url);

        const row = $('<tr></tr>');

        if (isFixed) {
            row.addClass('status-fixed');
        }

        row.html(
            '<td class="column-page">' +
            '<strong>' + escapeHtml(pageTitle) + '</strong>' +
            '</td>' +
            '<td class="column-broken-link">' +
            '<div class="link-type">' + linkTypeDisplay + '</div>' +
            '<a href="' + escapeHtml(result.broken_url) + '" class="broken-url-link" target="_blank">' +
            escapeHtml(result.broken_url) +
            '</a>' +
            '</td>' +
            '<td class="column-link-type">' +
            '<span class="link-type-badge ' + (result.link_type === 'internal' ? 'badge-internal' : 'badge-external') + '">' +
            (result.link_type === 'internal' ? 'Internal' : 'External') +
            '</span>' +
            '</td>' +
            '<td class="column-status">' +
            '<span class="status-text">' + statusCode + ' Error</span> ' +
            '<span class="status-badge ' + errorClass + '">' + errorType + '</span>' +
            '</td>' +
            '<td class="column-action">' +
            (isFixed ?
                '<span class="fixed-label">Fixed</span>' :
                '<button class="fix-btn" data-id="' + result.id + '" data-result=\'' + JSON.stringify(result) + '\'>' +
                'Fix ' +
                '<span class="dashicons dashicons-arrow-down-alt2"></span>' +
                '</button>'
            ) +
            '</td>'
        );

        return row;
    }

    /**
     * Extract page name from URL
     */
    function extractPageName(url) {
        try {
            const urlObj = new URL(url);
            const path = urlObj.pathname;

            // Handle root/home page
            if (path === '/' || path === '') {
                return 'Home Page';
            }

            // Split path and filter out empty parts
            const parts = path.split('/').filter(p => p);

            // Remove common WordPress directory names
            const filteredParts = parts.filter(part => {
                const lower = part.toLowerCase();
                return lower !== 'wordpress' &&
                    lower !== 'wp-content' &&
                    lower !== 'wp-includes' &&
                    lower !== 'wp-admin';
            });

            // Get the last meaningful part (the page slug)
            if (filteredParts.length > 0) {
                const slug = filteredParts[filteredParts.length - 1];
                // Convert slug to title case (e.g., "about-me" -> "About Me")
                return slug
                    .replace(/-/g, ' ')
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, l => l.toUpperCase());
            }

            return 'Page';
        } catch (e) {
            return 'Page';
        }
    }
    /**
     * Update pagination
     */
    function updatePagination(data) {
        console.log('[UPDATE PAGINATION] Called with data:', data);

        // Update page info at top and bottom
        $('#current-page-top, #current-page-bottom').text(data.current_page || 1);
        $('#total-pages-top, #total-pages-bottom').text(data.pages || 1);

        // Clear both pagination containers
        const topContainer = $('.pagination-controls-top');
        const bottomContainer = $('#pagination-container');
        topContainer.empty();
        bottomContainer.empty();

        if (data.pages <= 1) {
            console.log('[UPDATE PAGINATION] Only 1 page, not showing pagination');
            return;
        }

        // Create pagination HTML
        const paginationHtml = createPaginationButtons(data);

        // Add to both containers
        topContainer.append(paginationHtml.clone());
        bottomContainer.append(paginationHtml);

        console.log('[UPDATE PAGINATION] Pagination buttons created');
    }

    /**
     * Create pagination buttons HTML
     */
    function createPaginationButtons(data) {
        const paginationHtml = $('<div class="pagination-controls"></div>');

        // "Page:" label
        paginationHtml.append('<span>Page:</span>');

        // First page button
        const firstBtn = $('<button class="page-btn" data-page="1">«</button>');
        if (data.current_page <= 1) {
            firstBtn.prop('disabled', true);
        }
        paginationHtml.append(firstBtn);

        // Previous button
        const prevPage = data.current_page - 1;
        const prevBtn = $('<button class="page-btn" data-page="' + prevPage + '">‹</button>');
        if (data.current_page <= 1) {
            prevBtn.prop('disabled', true);
        }
        paginationHtml.append(prevBtn);

        // Page numbers (show current and nearby pages)
        for (let i = 1; i <= data.pages; i++) {
            if (i === 1 || i === data.pages || (i >= data.current_page - 1 && i <= data.current_page + 1)) {
                const pageBtn = $('<button class="page-btn" data-page="' + i + '">' + i + '</button>');
                if (i === data.current_page) {
                    pageBtn.addClass('active');
                }
                paginationHtml.append(pageBtn);
            } else if (i === data.current_page - 2 || i === data.current_page + 2) {
                paginationHtml.append('<span>...</span>');
            }
        }

        // Next button
        const nextPage = data.current_page + 1;
        const nextBtn = $('<button class="page-btn" data-page="' + nextPage + '">›</button>');
        if (data.current_page >= data.pages) {
            nextBtn.prop('disabled', true);
        }
        paginationHtml.append(nextBtn);

        // Last page button
        const lastBtn = $('<button class="page-btn" data-page="' + data.pages + '">»</button>');
        if (data.current_page >= data.pages) {
            lastBtn.prop('disabled', true);
        }
        paginationHtml.append(lastBtn);

        // "Show X entries" section
        paginationHtml.append('<span>Show</span>');

        const perPageSelect = $('<select id="per-page-select" class="per-page-select"></select>');
        const currentPerPage = data.per_page || 25;
        [10, 25, 50, 100].forEach(function (val) {
            const option = $('<option value="' + val + '">' + val + '</option>');
            if (val == currentPerPage) {
                option.attr('selected', 'selected');
            }
            perPageSelect.append(option);
        });
        paginationHtml.append(perPageSelect);

        paginationHtml.append('<span>entries</span>');

        return paginationHtml;
    }

    /**
     * Update filter counts
     */
    function updateFilterCounts(data) {
        console.log('[UPDATE FILTER COUNTS] Data:', data);
        console.log('[UPDATE FILTER COUNTS] Stats available:', !!data.stats);

        // Use stats from API response if available
        if (data.stats) {
            console.log('[UPDATE FILTER COUNTS] Using stats:', data.stats);
            $('#filter-count-all').text(data.stats.total || 0);
            $('#filter-count-internal').text(data.stats.internal || 0);
            $('#filter-count-external').text(data.stats.external || 0);
        } else {
            console.log('[UPDATE FILTER COUNTS] No stats, using fallback');
            // Fallback to total count
            $('#filter-count-all').text(data.total || 0);
        }
    }

    /**
     * Handle inline URL editing
     */
    $(document).on('click', '.suggested-url-editable', function () {
        const $this = $(this);
        const id = $this.data('id');
        const currentUrl = $this.data('url');

        const input = $('<input type="text" class="suggested-url-input" />');
        input.val(currentUrl);
        input.data('id', id);

        $this.replaceWith(input);
        input.focus();

        // Handle blur (save)
        input.on('blur', function () {
            saveEditedUrl($(this));
        });

        // Handle Enter key
        input.on('keypress', function (e) {
            if (e.which === 13) {
                $(this).blur();
            }
        });
    });

    /**
     * Save edited URL
     */
    function saveEditedUrl($input) {
        const id = $input.data('id');
        const newUrl = $input.val();

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_update_suggestion',
                nonce: seoautofixBrokenUrls.nonce,
                id: id,
                new_url: newUrl
            },
            success: function (response) {
                if (response.success) {
                    // Replace input with display
                    const display = $('<div class="suggested-url-editable" data-id="' + id + '" data-url="' + escapeHtml(newUrl) + '">' +
                        '<span class="url-display">' + escapeHtml(newUrl) + '</span>' +
                        '</div>');
                    $input.replaceWith(display);
                } else {
                    alert(response.data.message || 'Failed to update URL');
                }
            }
        });
    }

    /**
     * Delete entry
     */
    $(document).on('click', '.delete-entry-btn', function () {
        if (!confirm(seoautofixBrokenUrls.strings.confirmDelete)) {
            return;
        }

        const id = $(this).data('id');
        const $row = $(this).closest('tr');

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_delete_entry',
                nonce: seoautofixBrokenUrls.nonce,
                id: id
            },
            success: function (response) {
                if (response.success) {
                    $row.fadeOut(function () {
                        $row.remove();
                        // Reload to update counts
                        loadScanResults(currentScanId);
                    });
                } else {
                    alert(response.data.message || 'Failed to delete entry');
                }
            }
        });
    });

    /**
     * Apply selected fixes
     */
    function applySelectedFixes() {
        const selectedIds = [];
        $('.result-checkbox:checked').each(function () {
            selectedIds.push($(this).data('id'));
        });

        if (selectedIds.length === 0) {
            alert('Please select at least one entry to fix');
            return;
        }

        if (!confirm(seoautofixBrokenUrls.strings.confirmApplyFixes)) {
            return;
        }

        $('#apply-selected-fixes-btn').prop('disabled', true).text('Applying fixes...');

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_apply_fixes',
                nonce: seoautofixBrokenUrls.nonce,
                ids: selectedIds
            },
            success: function (response) {
                $('#apply-selected-fixes-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Apply Selected Fixes');

                if (response.success) {
                    alert('Fixed: ' + response.data.fixed_count + '\nFailed: ' + response.data.failed_count);
                    loadScanResults(currentScanId);
                } else {
                    alert(response.data.message || 'Failed to apply fixes');
                }
            },
            error: function () {
                $('#apply-selected-fixes-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Apply Selected Fixes');
                alert(seoautofixBrokenUrls.strings.error);
            }
        });
    }

    /**
     * Export to CSV
     */
    function exportToCSV() {
        if (!currentScanId) {
            alert('No scan results to export');
            return;
        }

        const url = seoautofixBrokenUrls.ajaxUrl +
            '?action=seoautofix_broken_links_export_csv' +
            '&nonce=' + seoautofixBrokenUrls.nonce +
            '&scan_id=' + currentScanId +
            '&filter=' + currentFilter;

        window.location.href = url;
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    // ========================================
    // NEW v2.0 FUNCTIONS
    // ========================================

    /**
     * Show occurrences modal
     */
    window.showOccurrences = function (brokenUrl) {
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'GET',
            data: {
                action: 'seoautofix_broken_links_get_occurrences',
                nonce: seoautofixBrokenUrls.nonce,
                broken_url: brokenUrl,
                scan_id: currentScanId
            },
            success: function (response) {
                if (response.success) {
                    displayOccurrencesModal(brokenUrl, response.data.occurrences);
                } else {
                    alert(response.data.message || 'Failed to load occurrences');
                }
            }
        });
    };

    /**
     * Display occurrences modal
     */
    function displayOccurrencesModal(brokenUrl, occurrences) {
        let html = '<div class="occurrences-modal-overlay">' +
            '<div class="occurrences-modal">' +
            '<div class="modal-header">' +
            '<h3>Occurrences of: ' + escapeHtml(brokenUrl) + '</h3>' +
            '<span class="modal-close">&times;</span>' +
            '</div>' +
            '<div class="modal-body">' +
            '<p>Found on ' + occurrences.length + ' page(s):</p>' +
            '<ul class="occurrences-list">';

        occurrences.forEach(function (occ) {
            html += '<li>' +
                '<strong>' + escapeHtml(occ.found_on_page_title) + '</strong><br>' +
                '<small>Location: ' + occ.link_location + ' | Anchor: ' + escapeHtml(occ.anchor_text) + '</small>' +
                '</li>';
        });

        html += '</ul>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button class="button button-secondary modal-close-btn">Close</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(html);

        // Close handlers
        $('.modal-close, .modal-close-btn, .occurrences-modal-overlay').on('click', function (e) {
            if (e.target === this) {
                $('.occurrences-modal-overlay').remove();
            }
        });
    }

    /**
     * Generate fix plan
     */
    window.generateFixPlan = function () {
        const selectedIds = [];
        $('.result-checkbox:checked').each(function () {
            selectedIds.push($(this).data('id'));
        });

        if (selectedIds.length === 0) {
            alert('Please select at least one entry');
            return;
        }

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_generate_fix_plan',
                nonce: seoautofixBrokenUrls.nonce,
                entry_ids: selectedIds
            },
            success: function (response) {
                if (response.success) {
                    displayFixPlanReview(response.data.plan_id, response.data.fix_plan);
                } else {
                    alert(response.data.message || 'Failed to generate fix plan');
                }
            }
        });
    };

    /**
     * Display fix plan review modal
     */
    function displayFixPlanReview(planId, fixPlan) {
        let html = '<div class="fix-plan-modal-overlay">' +
            '<div class="fix-plan-modal">' +
            '<div class="modal-header">' +
            '<h3>Review Fix Plan (' + fixPlan.length + ' fixes)</h3>' +
            '<span class="modal-close">&times;</span>' +
            '</div>' +
            '<div class="modal-body">' +
            '<table class="fix-plan-table">' +
            '<thead>' +
            '<tr>' +
            '<th><input type="checkbox" id="select-all-fixes" checked></th>' +
            '<th>Page</th>' +
            '<th>Broken URL</th>' +
            '<th>New URL</th>' +
            '<th>Action</th>' +
            '</tr>' +
            '</thead>' +
            '<tbody>';

        fixPlan.forEach(function (fix) {
            html += '<tr>' +
                '<td><input type="checkbox" class="fix-checkbox" data-entry-id="' + fix.entry_id + '" checked></td>' +
                '<td>' + escapeHtml(fix.found_on_page_title) + '</td>' +
                '<td>' + escapeHtml(fix.broken_url) + '</td>' +
                '<td><input type="text" class="fix-new-url" data-entry-id="' + fix.entry_id + '" value="' + escapeHtml(fix.new_url) + '"></td>' +
                '<td>' +
                '<select class="fix-action" data-entry-id="' + fix.entry_id + '">' +
                '<option value="replace"' + (fix.fix_action === 'replace' ? ' selected' : '') + '>Replace</option>' +
                '<option value="remove"' + (fix.fix_action === 'remove' ? ' selected' : '') + '>Remove</option>' +
                '</select>' +
                '</td>' +
                '</tr>';
        });

        html += '</tbody>' +
            '</table>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button class="button button-secondary modal-close-btn">Cancel</button>' +
            '<button class="button button-primary apply-fix-plan-btn" data-plan-id="' + planId + '">Apply Selected Fixes</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(html);

        // Select all handler
        $('#select-all-fixes').on('change', function () {
            $('.fix-checkbox').prop('checked', $(this).is(':checked'));
        });

        // Close handlers
        $('.modal-close, .modal-close-btn').on('click', function () {
            $('.fix-plan-modal-overlay').remove();
        });

        // Apply fixes handler
        $('.apply-fix-plan-btn').on('click', function () {
            applyFixPlan(planId);
        });
    }

    /**
     * Apply fix plan
     */
    function applyFixPlan(planId) {
        const selectedEntryIds = [];
        $('.fix-checkbox:checked').each(function () {
            selectedEntryIds.push($(this).data('entry-id'));
        });

        if (selectedEntryIds.length === 0) {
            alert('Please select at least one fix to apply');
            return;
        }

        if (!confirm('Apply ' + selectedEntryIds.length + ' fix(es)? This will modify your content.')) {
            return;
        }

        $('.apply-fix-plan-btn').prop('disabled', true).text('Applying...');

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_apply_fix_plan',
                nonce: seoautofixBrokenUrls.nonce,
                plan_id: planId,
                selected_entry_ids: selectedEntryIds
            },
            success: function (response) {
                $('.fix-plan-modal-overlay').remove();

                if (response.success) {
                    alert('Success!\nFixed: ' + response.data.fixed_count + '\nRemoved: ' + response.data.removed_count + '\nFailed: ' + response.data.failed_count);
                    loadScanResults(currentScanId);

                    // Show revert option
                    if (response.data.fix_session_id) {
                        showRevertNotification(response.data.fix_session_id);
                    }
                } else {
                    alert(response.data.message || 'Failed to apply fixes');
                }
            },
            error: function () {
                $('.fix-plan-modal-overlay').remove();
                alert('Error applying fixes');
            }
        });
    }

    /**
     * Show revert notification
     */
    function showRevertNotification(sessionId) {
        const notification = $('<div class="revert-notification">' +
            '<span>Fixes applied successfully! </span>' +
            '<button class="button button-small revert-btn" data-session-id="' + sessionId + '">Undo Changes</button>' +
            '<span class="close-notification">&times;</span>' +
            '</div>');

        $('body').append(notification);

        notification.find('.revert-btn').on('click', function () {
            revertFixes(sessionId);
        });

        notification.find('.close-notification').on('click', function () {
            notification.remove();
        });

        // Auto-hide after 30 seconds
        setTimeout(function () {
            notification.fadeOut(function () {
                notification.remove();
            });
        }, 30000);
    }

    /**
     * Revert fixes
     */
    window.revertFixes = function (sessionId) {
        if (!confirm('This will undo all changes from this fix session. Continue?')) {
            return;
        }

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_revert_fixes',
                nonce: seoautofixBrokenUrls.nonce,
                fix_session_id: sessionId
            },
            success: function (response) {
                if (response.success) {
                    alert('Reverted ' + response.data.reverted_count + ' page(s) successfully!');
                    $('.revert-notification').remove();
                    loadScanResults(currentScanId);
                } else {
                    alert(response.data.message || 'Failed to revert changes');
                }
            }
        });
    };

    /**
     * Show export options
     */
    window.showExportOptions = function () {
        if (!currentScanId) {
            alert('No scan results to export');
            return;
        }

        const html = '<div class="export-modal-overlay">' +
            '<div class="export-modal">' +
            '<div class="modal-header">' +
            '<h3>Export Options</h3>' +
            '<span class="modal-close">&times;</span>' +
            '</div>' +
            '<div class="modal-body">' +
            '<div class="export-option">' +
            '<button class="button button-primary export-csv-btn">Download CSV</button>' +
            '<p>Export all results to CSV file</p>' +
            '</div>' +
            '<div class="export-option">' +
            '<button class="button button-primary export-pdf-btn">Download PDF</button>' +
            '<p>Generate PDF report (requires TCPDF)</p>' +
            '</div>' +
            '<div class="export-option">' +
            '<h4>Email Report</h4>' +
            '<input type="email" id="email-report-address" placeholder="Enter email address" class="regular-text">' +
            '<select id="email-report-format">' +
            '<option value="summary">Summary Only</option>' +
            '<option value="csv">Summary + CSV Attachment</option>' +
            '</select>' +
            '<button class="button button-primary send-email-btn">Send Email</button>' +
            '</div>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button class="button button-secondary modal-close-btn">Close</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(html);

        // Export CSV
        $('.export-csv-btn').on('click', function () {
            exportToCSV();
            $('.export-modal-overlay').remove();
        });

        // Export PDF
        $('.export-pdf-btn').on('click', function () {
            const url = seoautofixBrokenUrls.ajaxUrl +
                '?action=seoautofix_broken_links_export_pdf' +
                '&nonce=' + seoautofixBrokenUrls.nonce +
                '&scan_id=' + currentScanId +
                '&filter=' + currentFilter;
            window.location.href = url;
            $('.export-modal-overlay').remove();
        });

        // Send email
        $('.send-email-btn').on('click', function () {
            const email = $('#email-report-address').val();
            const format = $('#email-report-format').val();

            if (!email) {
                alert('Please enter an email address');
                return;
            }

            $(this).prop('disabled', true).text('Sending...');

            $.ajax({
                url: seoautofixBrokenUrls.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'seoautofix_broken_links_email_report',
                    nonce: seoautofixBrokenUrls.nonce,
                    scan_id: currentScanId,
                    email: email,
                    format: format
                },
                success: function (response) {
                    if (response.success) {
                        alert(response.data.message);
                        $('.export-modal-overlay').remove();
                    } else {
                        alert(response.data.message || 'Failed to send email');
                        $('.send-email-btn').prop('disabled', false).text('Send Email');
                    }
                }
            });
        });

        // Close handlers
        $('.modal-close, .modal-close-btn, .export-modal-overlay').on('click', function (e) {
            if (e.target === this) {
                $('.export-modal-overlay').remove();
            }
        });
    };

    /**
     * Event delegation for Fix buttons (since they're dynamically created)
     */
    $(document).on('click', '.fix-btn', function () {
        const resultData = $(this).data('result');
        showAutoFixPanel(resultData);
    });

    /**
     * Show auto-fix panel with link data
     */
    function showAutoFixPanel(result) {
        const pageTitle = result.found_on_page_title || extractPageName(result.found_on_url);
        const statusCode = result.status_code || 404;
        const errorType = statusCode >= 500 ? '5xx' : '4xx';
        const errorClass = statusCode >= 500 ? 'error-5xx' : 'error-4xx';

        // Populate panel
        $('#fix-page-name').text(pageTitle);
        $('#fix-broken-url').text(result.broken_url);
        $('#fix-error-badge').text(errorType).attr('class', 'error-badge ' + errorClass);

        const suggestedUrl = result.user_modified_url || result.suggested_url || '';
        if (suggestedUrl) {
            $('#fix-suggested-url').text(suggestedUrl).attr('href', suggestedUrl).show();
            $('.fix-suggestion').show();
        } else {
            $('.fix-suggestion').hide();
        }

        // Store result data for later use
        $('#auto-fix-panel').data('current-result', result);

        // Reset radio buttons
        $('input[name="fix-action"][value="suggested"]').prop('checked', true);
        $('#custom-url-input').hide();

        // Show panel
        $('#auto-fix-panel').slideDown();
    }

    /**
     * Apply current fix from auto-fix panel
     */
    function applyCurrentFix() {
        const result = $('#auto-fix-panel').data('current-result');
        const action = $('input[name="fix-action"]:checked').val();

        let newUrl = '';
        if (action === 'suggested') {
            newUrl = result.suggested_url || result.user_modified_url;
        } else if (action === 'custom') {
            newUrl = $('#custom-url-field').val();
        } else if (action === 'home') {
            newUrl = window.location.origin;
        }

        if (!newUrl) {
            alert('Please enter a URL or select an option');
            return;
        }

        // Apply fix via AJAX
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_update_suggestion',
                nonce: seoautofixBrokenUrls.nonce,
                id: result.id,
                new_url: newUrl
            },
            success: function (response) {
                if (response.success) {
                    $('#auto-fix-panel').slideUp();
                    loadScanResults(currentScanId);
                    alert('Fix applied successfully!');
                } else {
                    alert(response.data.message || 'Failed to apply fix');
                }
            },
            error: function () {
                alert('An error occurred while applying the fix');
            }
        });
    }

    /**
     * Remove broken links
     */
    function removeBrokenLinks() {
        if (!confirm('Are you sure you want to remove all broken links? This action cannot be undone.')) {
            return;
        }

        alert('Remove broken links functionality will be implemented');
    }

    /**
     * Replace broken links
     */
    function replaceBrokenLinks() {
        if (!confirm('Are you sure you want to replace all broken links with suggested URLs?')) {
            return;
        }

        alert('Replace broken links functionality will be implemented');
    }

    /**
     * Fix all issues
     */
    function fixAllIssues() {
        if (!confirm('This will automatically fix all broken links. Continue?')) {
            return;
        }

        alert('Fix all issues functionality will be implemented');
    }

    /**
     * Undo changes
     */
    function undoChanges() {
        if (!confirm('Are you sure you want to undo recent changes?')) {
            return;
        }

        alert('Undo functionality will be implemented');
    }

    /**
     * Download report
     */
    function downloadReport() {
        if (!currentScanId) {
            alert('No scan data available');
            return;
        }

        const url = seoautofixBrokenUrls.ajaxUrl +
            '?action=seoautofix_broken_links_export_csv' +
            '&nonce=' + seoautofixBrokenUrls.nonce +
            '&scan_id=' + currentScanId;
        window.location.href = url;
    }

    /**
     * Email report
     */
    function emailReport() {
        const email = prompt('Enter email address to send the report:');
        if (!email) {
            return;
        }

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_email_report',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: currentScanId,
                email: email,
                format: 'csv'
            },
            success: function (response) {
                if (response.success) {
                    alert('Report sent successfully!');
                } else {
                    alert(response.data.message || 'Failed to send report');
                }
            },
            error: function () {
                alert('An error occurred while sending the report');
            }
        });
    }

    /**
     * Update header stats
     */
    function updateHeaderStats(stats) {
        if (stats) {
            $('#header-broken-count').text(stats.total || 0);
            $('#header-4xx-count').text(stats['4xx'] || 0);
            $('#header-5xx-count').text(stats['5xx'] || 0);
        }
    }

})(jQuery);
