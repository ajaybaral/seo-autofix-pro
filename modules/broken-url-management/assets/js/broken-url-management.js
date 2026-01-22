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
    let perPage = 25; // Default entries per page
    let isScanning = false;
    let scanProgressInterval = null;

    // Track fixed links in current session (for export)
    let fixedLinksSession = [];

    // Undo stack for tracking changes
    let undoStack = [];

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
        $('#undo-last-fix-btn').on('click', undoLastFix);
        $('#fix-all-issues-btn').on('click', fixAllIssues);

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
            perPage = parseInt($(this).val());
            currentPage = 1; // Reset to first page
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

        // Fix button - use event delegation since rows are dynamically created
        $(document).on('click', '.fix-btn', function () {
            console.log('[FIX BTN] Clicked');
            const resultData = $(this).data('result');
            const entryId = $(this).data('id');

            console.log('[FIX BTN] Entry ID:', entryId, 'Result data:', resultData);

            if (!resultData) {
                alert('Error: No data available for this entry');
                return;
            }

            // Store current fix data globally
            window.currentFixData = {
                id: entryId,
                broken_url: resultData.broken_url,
                suggested_url: resultData.suggested_url,
                found_on_page_title: resultData.found_on_page_title,
                status_code: resultData.status_code
            };

            // Populate the auto-fix panel
            $('#fix-page-name').text(resultData.found_on_page_title || 'Unknown Page');
            $('#fix-broken-url').text(resultData.broken_url);
            $('#fix-error-badge').text(resultData.status_code + 'XX');

            if (resultData.suggested_url) {
                // Show suggested URL section
                $('.fix-suggestion').show();
                $('input[name="fix-action"][value="suggested"]').parent().show();

                $('#fix-suggested-url').text(resultData.suggested_url).attr('href', resultData.suggested_url);
                $('input[name="fix-action"][value="suggested"]').prop('disabled', false).prop('checked', true);
                $('#custom-url-input').hide();
                $('#custom-url-field').val('');
            } else {
                // Hide suggested URL section (like external links)
                $('.fix-suggestion').hide();
                $('input[name="fix-action"][value="suggested"]').parent().hide();
                $('input[name="fix-action"][value="suggested"]').prop('disabled', true);

                // Auto-select custom option
                $('input[name="fix-action"][value="custom"]').prop('checked', true);
                $('#custom-url-input').show();
                $('#custom-url-field').val('');
            }

            // Show the panel
            $('#auto-fix-panel').slideDown();

            // Scroll to panel
            $('html, body').animate({
                scrollTop: $('#auto-fix-panel').offset().top - 100
            }, 500);
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
        $('#apply-fix-btn').on('click', function () {
            console.log('[APPLY FIX BTN] Button clicked!');
            console.log('[APPLY FIX BTN] currentFixData:', window.currentFixData);
            applyCurrentFix();
        });
        $('#skip-fix-btn').on('click', function () {
            console.log('[SKIP FIX BTN] Clicked');
            $('#auto-fix-panel').hide();
        });
        $('#delete-broken-link-btn').on('click', function () {
            console.log('[DELETE BTN] Clicked');
            deleteBrokenLink();
        });

        // Bulk action buttons
        $('#remove-broken-links-btn').on('click', removeBrokenLinks);
        $('#replace-broken-links-btn').on('click', replaceBrokenLinks);
        $('#fix-all-issues-btn').on('click', fixAllIssues);

        // History & Export buttons
        $('#undo-changes-btn').on('click', undoChanges);
        $('#download-report-btn, #download-report-empty-btn, #download-report-header-btn').on('click', downloadReport);
        $('#email-report-btn, #email-report-empty-btn, #email-report-header-btn').on('click', emailReport);
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
        $('#start-auto-fix-btn').prop('disabled', true).text(seoautofixBrokenUrls.strings.startingScan);

        // Reset progress bar values to 0
        $('#scan-progress-percentage').text('0%');
        $('#scan-progress-fill').css('width', '0%');
        $('#scan-urls-tested').text('0');
        $('#scan-urls-total').text('0');
        $('#scan-broken-count').text('0');
        $('#scan-progress-text').text(seoautofixBrokenUrls.strings.scanning || 'Scanning...');

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
                        tested_urls: data.tested_urls || 0,
                        total_urls: data.total_urls || 0,
                        broken_count: data.broken_count || 0,
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
        $('#start-auto-fix-btn').prop('disabled', false).html(
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

        // Always show results container (table and filters)
        $('#results-container').show();
        $('#empty-state').hide();

        // Show download/email buttons when results are available
        $('.history-export-section-header').show();

        // Clear table
        $('#results-table-body').empty();

        // If no results, show message in table
        if (total === 0) {
            const emptyRow = $('<tr class="empty-results-row"></tr>');
            emptyRow.html(
                '<td colspan="5" style="text-align: center; padding: 40px; color: #6b7280;">' +
                '<div style="font-size: 16px; font-weight: 500; margin-bottom: 8px;">No broken links found matching your filters</div>' +
                '<div style="font-size: 14px;">Try adjusting your filter criteria or search term</div>' +
                '</td>'
            );
            $('#results-table-body').append(emptyRow);

            // Update pagination to show no pages
            updatePagination({
                current_page: 1,
                total_pages: 0,
                total: 0
            });

            // Update filter counts
            updateFilterCounts(data);
            return;
        }

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
        const statusCode = parseInt(result.status_code) || 0;
        const errorType = result.error_type || (statusCode >= 500 ? '5xx' : '4xx');
        const errorClass = errorType === '5xx' ? 'error-5xx' : 'error-4xx';

        // Determine link type display (Anchor Text vs Naked Link)
        let linkTypeDisplay = '';
        console.log('[ANCHOR TEXT CHECK]', {
            anchor_text: result.anchor_text,
            anchor_text_type: typeof result.anchor_text,
            anchor_text_length: result.anchor_text ? result.anchor_text.length : 0,
            trimmed: result.anchor_text ? result.anchor_text.trim() : ''
        });

        // Check if it's a real anchor text (not empty, not the URL itself, not placeholder text)
        const hasRealAnchorText = result.anchor_text &&
            result.anchor_text.trim() !== '' &&
            result.anchor_text.trim() !== result.broken_url &&
            result.anchor_text !== '[No text]' &&
            !result.anchor_text.startsWith('Image: ');

        if (hasRealAnchorText) {
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

        // Build suggested URL display for internal links
        let suggestedUrlHtml = '';
        if (result.link_type === 'internal' && result.suggested_url) {
            suggestedUrlHtml = '<div class="suggested-url-display">' +
                '<span class="suggested-label">Suggested: </span>' +
                '<a href="' + escapeHtml(result.suggested_url) + '" class="suggested-url-link" target="_blank">' +
                escapeHtml(result.suggested_url) +
                '</a>' +
                '</div>';
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
            suggestedUrlHtml +
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
            const searchParams = new URLSearchParams(urlObj.search);

            // Check for page_id or p parameter (WordPress post/page ID)
            const pageId = searchParams.get('page_id') || searchParams.get('p');
            if (pageId) {
                return 'Post/Page ID: ' + pageId;
            }

            // Check for pagename parameter
            const pageName = searchParams.get('pagename');
            if (pageName) {
                return pageName
                    .replace(/-/g, ' ')
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, l => l.toUpperCase());
            }

            // Handle root/home page
            if (path === '/' || path === '') {
                return 'Home Page';
            }

            // Split path and filter out empty parts
            const parts = path.split('/').filter(p => p);

            // Remove common WordPress directory names but keep at least one part
            const filteredParts = parts.filter(part => {
                const lower = part.toLowerCase();
                return lower !== 'wp-content' &&
                    lower !== 'wp-includes' &&
                    lower !== 'wp-admin';
            });

            // If we filtered out everything, use the original parts (except wp-content, wp-includes, wp-admin)
            const finalParts = filteredParts.length > 0 ? filteredParts : parts.filter(part => {
                const lower = part.toLowerCase();
                return lower !== 'wp-content' &&
                    lower !== 'wp-includes' &&
                    lower !== 'wp-admin';
            });

            // Get the last meaningful part (the page slug)
            if (finalParts.length > 0) {
                const slug = finalParts[finalParts.length - 1];
                // Convert slug to title case (e.g., "about-me" -> "About Me")
                return slug
                    .replace(/-/g, ' ')
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, l => l.toUpperCase());
            }

            return 'Home Page';
        } catch (e) {
            console.error('[extractPageName] Error:', e);
            return 'Unknown Page';
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

        // Always create pagination HTML (even for 1 page)
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
        const currentPerPage = data.per_page || 5;
        [5, 10, 25, 50, 100].forEach(function (val) {
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
    function applySelectedFixes(idsToFix) {
        console.log('[APPLY SELECTED FIXES] Called with IDs:', idsToFix);

        let selectedIds = idsToFix || [];

        // If no IDs provided, get from checkboxes
        if (!selectedIds || selectedIds.length === 0) {
            $('.result-checkbox:checked').each(function () {
                selectedIds.push($(this).data('id'));
            });
        }

        console.log('[APPLY SELECTED FIXES] Selected IDs:', selectedIds);

        if (selectedIds.length === 0) {
            alert('Please select at least one entry to fix');
            return;
        }

        if (!confirm('Are you sure you want to apply fixes for ' + selectedIds.length + ' link(s)?')) {
            return;
        }

        console.log('[APPLY SELECTED FIXES] Sending AJAX request');

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_apply_fixes',
                nonce: seoautofixBrokenUrls.nonce,
                ids: selectedIds
            },
            success: function (response) {
                console.log('[APPLY SELECTED FIXES] Success response:', response);

                if (response.success) {
                    const fixed = response.data.fixed_count || 0;
                    const failed = response.data.failed_count || 0;
                    const skipped = response.data.skipped_count || 0;

                    // Show summary message
                    let message = '✅ Fixed: ' + fixed + '\n❌ Failed: ' + failed;
                    if (skipped > 0) {
                        message += '\n⚠️ Skipped: ' + skipped;
                    }
                    message += '\n\nMessages:\n' + response.data.messages.join('\n');
                    alert(message);

                    // Remove successfully fixed rows dynamically
                    if (fixed > 0) {
                        selectedIds.forEach(function (id) {
                            const $row = $('tr[data-id="' + id + '"]');
                            const rowData = $row.data('result');

                            // Push to undo stack before removing
                            undoStack.push({
                                id: id,
                                action: 'fix',
                                original_data: rowData,
                                row_html: $row[0].outerHTML
                            });

                            // Add to fixed links session for export
                            if (rowData) {
                                fixedLinksSession.push({
                                    location: rowData.location || 'content',
                                    anchor_text: rowData.anchor_text || '',
                                    broken_url: rowData.broken_url,
                                    link_type: rowData.link_type,
                                    status_code: rowData.status_code,
                                    error_type: rowData.status_code,
                                    suggested_url: rowData.suggested_url || '',
                                    reason: rowData.reason || '',
                                    is_fixed: 'Yes',
                                    fixed_at: new Date().toISOString()
                                });
                            }

                            $row.fadeOut(300, function () {
                                $(this).remove();

                                // Update the "No results" message if table is empty
                                if ($('.broken-links-table tbody tr').length === 0) {
                                    $('.broken-links-table tbody').html(
                                        '<tr><td colspan="5" style="text-align:center; padding: 30px;">No broken links found</td></tr>'
                                    );
                                }
                            });
                        });

                        // Update stats dynamically (subtract fixed count)
                        updateStatsAfterFix(fixed);

                        // Update button text to show number of fixed links
                        updateFixedReportButtonText();

                        // Enable undo button since we have changes in the stack
                        if (undoStack.length > 0) {
                            $('#undo-last-fix-btn').prop('disabled', false);
                        }
                    }

                    // If there were failures, reload to show updated state
                    if (failed > 0) {
                        setTimeout(function () {
                            loadScanResults(currentScanId);
                        }, 500);
                    }
                } else {
                    alert(response.data.message || 'Failed to apply fixes');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.log('[APPLY SELECTED FIXES] Error:', textStatus, errorThrown);
                alert('Error applying fixes: ' + textStatus);
            }
        });
    }


    /**
     * Update stats dynamically after fixing links
     */
    function updateStatsAfterFix(fixedCount) {
        // Update the total count badge if it exists
        const totalBadge = $('.filter-btn[data-filter="all"] .count');
        if (totalBadge.length) {
            const currentTotal = parseInt(totalBadge.text()) || 0;
            const newTotal = Math.max(0, currentTotal - fixedCount);
            totalBadge.text(newTotal);
        }

        // Update pagination info
        const paginationInfo = $('.pagination-controls span:first');
        if (paginationInfo.length) {
            const text = paginationInfo.text();
            const match = text.match(/of (\d+)/);
            if (match) {
                const currentTotal = parseInt(match[1]);
                const newTotal = Math.max(0, currentTotal - fixedCount);
                const newText = text.replace(/of \d+/, 'of ' + newTotal);
                paginationInfo.text(newText);
            }
        }

        console.log('[UPDATE STATS] Reduced counts by', fixedCount);
    }

    /**
     * Update fixed report button text with count
     */
    function updateFixedReportButtonText() {
        const count = fixedLinksSession.length;
        if (count > 0) {
            $('#download-report-header-btn').find('.file-format').text('.csv (' + count + ')');
            $('#email-report-header-btn').find('.email-icon').text('✉ (' + count + ')');
        }
    }

    /**
     * Download fixed links report as CSV
     */
    function downloadFixedReport(e) {
        if (e) e.preventDefault();

        if (fixedLinksSession.length === 0) {
            alert('No fixed links to export. Fix some broken links first!');
            return;
        }

        // Create CSV content
        const headers = ['Location', 'Anchor Text', 'Broken URL', 'Link Type', 'Status Code', 'Error Type', 'Suggested URL', 'Reason', 'Is Fixed'];
        const rows = fixedLinksSession.map(function (link) {
            return [
                link.location,
                link.anchor_text,
                link.broken_url,
                link.link_type,
                link.status_code,
                link.error_type,
                link.suggested_url,
                link.reason,
                link.is_fixed
            ];
        });

        let csvContent = headers.join(',') + '\n';
        rows.forEach(function (row) {
            csvContent += row.map(function (cell) {
                // Escape quotes and wrap in quotes if contains comma
                const escaped = String(cell || '').replace(/"/g, '""');
                return escaped.indexOf(',') >= 0 ? '"' + escaped + '"' : escaped;
            }).join(',') + '\n';
        });

        // Create download link
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'fixed-links-' + Date.now() + '.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    /**
     * Email fixed links report
     */
    function emailFixedReport(e) {
        if (e) e.preventDefault();

        if (fixedLinksSession.length === 0) {
            alert('No fixed links to email. Fix some broken links first!');
            return;
        }

        // Show loading state
        $('#email-report-header-btn').prop('disabled', true).text('Sending...');

        // Convert fixed links to CSV format
        const headers = ['Location', 'Anchor Text', 'Broken URL', 'Link Type', 'Status Code', 'Error Type', 'Suggested URL', 'Reason', 'Is Fixed'];
        const csvData = {
            headers: headers,
            rows: fixedLinksSession.map(function (link) {
                return [
                    link.location,
                    link.anchor_text,
                    link.broken_url,
                    link.link_type,
                    link.status_code,
                    link.error_type,
                    link.suggested_url,
                    link.reason,
                    link.is_fixed
                ];
            })
        };

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_email_fixed_report',
                nonce: seoautofixBrokenUrls.nonce,
                csv_data: JSON.stringify(csvData)
            },
            success: function (response) {
                $('#email-report-header-btn').prop('disabled', false).find('.email-icon').text('✉ (' + fixedLinksSession.length + ')');

                if (response.success) {
                    alert(response.data.message || 'Fixed links report sent successfully!');
                } else {
                    alert(response.data.message || 'Failed to send email');
                }
            },
            error: function () {
                $('#email-report-header-btn').prop('disabled', false).find('.email-icon').text('✉ (' + fixedLinksSession.length + ')');
                alert('An error occurred while sending the email');
            }
        });
    }

    /**
     * Undo the last fix
     */
    function undoLastFix() {
        if (undoStack.length === 0) {
            alert('No changes to undo');
            return;
        }

        const lastChange = undoStack.pop();
        console.log('[UNDO] Restoring change:', lastChange);

        // Call backend to restore the link
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_undo_fix',
                nonce: seoautofixBrokenUrls.nonce,
                id: lastChange.id
            },
            success: function (response) {
                if (response.success) {
                    // Re-insert the row with animation
                    const newRow = $(lastChange.row_html);
                    $('#results-table-body').prepend(newRow);
                    newRow.hide().fadeIn(400);

                    // Update stats
                    updateStatsAfterUndo();

                    // Remove from fixed links session
                    fixedLinksSession = fixedLinksSession.filter(function (link) {
                        return link.id !== lastChange.id;
                    });

                    // Update fixed report buttons
                    updateFixedReportButtonText();

                    // Disable undo button if stack is empty
                    if (undoStack.length === 0) {
                        $('#undo-last-fix-btn').prop('disabled', true);
                    }

                    console.log('[UNDO] Successfully restored link ID:', lastChange.id);
                } else {
                    alert(response.data.message || 'failed to undo fix');
                    undoStack.push(lastChange); // Put it back
                }
            },
            error: function () {
                alert('Error occurred while trying to undo');
                undoStack.push(lastChange); // Put it back
            }
        });
    }

    /**
     * Fix all issues on current page
     */
    function fixAllIssues() {
        const brokenLinks = [];

        // Collect all broken links from the table
        $('#results-table-body tr').not('.status-fixed').each(function () {
            const $fixBtn = $(this).find('.fix-btn');
            if ($fixBtn.length) {
                const resultData = $fixBtn.data('result');
                if (resultData) {
                    brokenLinks.push(resultData);
                }
            }
        });

        if (brokenLinks.length === 0) {
            alert('No broken links to fix!');
            return;
        }

        if (!confirm('Fix ' + brokenLinks.length + ' broken link(s)? This will use suggested URLs where available, or redirect to homepage.')) {
            return;
        }

        // Disable button during processing
        $('#fix-all-issues-btn').prop('disabled', true).text('Fixing...');

        let processed = 0;
        const total = brokenLinks.length;

        // Process each link
        brokenLinks.forEach(function (link, index) {
            const redirectUrl = link.suggested_url || seoautofixBrokenUrls.homeUrl;

            // Push to undo stack before fixing
            const $row = $('#results-table-body').find('.fix-btn[data-id="' + link.id + '"]').closest('tr');
            undoStack.push({
                id: link.id,
                action: 'fix',
                original_data: link,
                row_html: $row[0].outerHTML
            });

            // Apply the fix
            $.ajax({
                url: seoautofixBrokenUrls.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'seoautofix_broken_links_apply_fixes',
                    nonce: seoautofixBrokenUrls.nonce,
                    fixes: JSON.stringify([{
                        id: link.id,
                        fix_type: link.suggested_url ? 'suggested' : 'homepage',
                        redirect_url: redirectUrl
                    }])
                },
                success: function (response) {
                    processed++;

                    if (response.success) {
                        // Remove row
                        $row.fadeOut(300, function () {
                            $(this).remove();

                            if ($('#results-table-body tr').length === 0) {
                                $('#results-table-body').html(
                                    '<tr class="empty-results-row"><td colspan="5" style="text-align: center; padding: 40px;">No broken links found</td></tr>'
                                );
                            }
                        });

                        // Track for export
                        fixedLinksSession.push({
                            id: link.id,
                            location: link.found_on_page_title,
                            anchor_text: link.anchor_text,
                            broken_url: link.broken_url,
                            link_type: link.link_type,
                            status_code: link.status_code,
                            error_type: link.error_type,
                            suggested_url: link.suggested_url,
                            reason: link.reason,
                            is_fixed: 1
                        });
                    }

                    // When all processed, update UI
                    if (processed === total) {
                        $('#fix-all-issues-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Fix All Issues');
                        updateStatsAfterFix(total);
                        updateFixedReportButtonText();

                        // Enable undo button
                        $('#undo-last-fix-btn').prop('disabled', false);

                        alert('Successfully fixed ' + total + ' link(s)!');
                    }
                },
                error: function () {
                    processed++;

                    if (processed === total) {
                        $('#fix-all-issues-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Fix All Issues');
                        alert('Some fixes failed. Please try again.');
                    }
                }
            });
        });
    }

    /**
     * Update stats after undo
     */
    function updateStatsAfterUndo() {
        const currentTotal = parseInt($('#header-broken-count').text()) || 0;
        $('#header-broken-count').text(currentTotal + 1);

        // Update pagination info if visible
        const $paginationInfo = $('.pagination-container .pagination-info');
        if ($paginationInfo.length) {
            const newTotal = currentTotal + 1;
            $paginationInfo.text('Page 1 of 1 | Showing 1 of ' + newTotal + ' total');
        }
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
        const isExternal = result.link_type === 'external';

        // Populate panel
        $('#fix-page-name').text(pageTitle);
        $('#fix-broken-url').text(result.broken_url);
        $('#fix-error-badge').text(errorType).attr('class', 'error-badge ' + errorClass);

        const suggestedUrl = result.user_modified_url || result.suggested_url || '';

        // For internal links with suggested URL, show the suggestion
        if (!isExternal && suggestedUrl) {
            $('#fix-suggested-url').text(suggestedUrl).attr('href', suggestedUrl).show();
            $('.fix-suggestion').show();
            $('input[name="fix-action"][value="suggested"]').closest('.fix-option').show();
            $('input[name="fix-action"][value="suggested"]').prop('checked', true);
        } else {
            // For external links or no suggestion, hide the suggestion section
            $('.fix-suggestion').hide();
            $('input[name="fix-action"][value="suggested"]').closest('.fix-option').hide();
            // Default to custom URL for external links
            $('input[name="fix-action"][value="custom"]').prop('checked', true);
        }

        // Store result data for later use
        $('#auto-fix-panel').data('current-result', result);

        // Reset custom URL input
        $('#custom-url-input').hide();
        $('#custom-url-field').val('');

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
     * Delete broken link entry from auto-fix panel
     */
    function deleteBrokenLink() {
        const result = $('#auto-fix-panel').data('current-result');

        if (!confirm('Are you sure you want to delete this broken link entry? This action cannot be undone.')) {
            return;
        }

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_delete_entry',
                nonce: seoautofixBrokenUrls.nonce,
                id: result.id
            },
            success: function (response) {
                if (response.success) {
                    $('#auto-fix-panel').slideUp();
                    loadScanResults(currentScanId);
                    alert('Broken link entry deleted successfully!');
                } else {
                    alert(response.data.message || 'Failed to delete entry');
                }
            },
            error: function () {
                alert('An error occurred while deleting the entry');
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

    /**
     * Apply current fix from auto-fix panel
     */
    function applyCurrentFix() {
        console.log('[APPLY CURRENT FIX] Called');
        console.log('[APPLY CURRENT FIX] window.currentFixData:', window.currentFixData);
        console.log('[APPLY CURRENT FIX] typeof applySelectedFixes:', typeof applySelectedFixes);

        if (!window.currentFixData) {
            console.log('[APPLY CURRENT FIX] No currentFixData, showing alert');
            alert('Error: No fix data available');
            return;
        }

        const fixAction = $('input[name="fix-action"]:checked').val();
        console.log('[APPLY CURRENT FIX] Fix action:', fixAction);

        let replacementUrl = '';

        // Determine replacement URL based on user selection
        if (fixAction === 'suggested') {
            replacementUrl = window.currentFixData.suggested_url;
            console.log('[APPLY CURRENT FIX] Using suggested URL:', replacementUrl);
            if (!replacementUrl) {
                alert('No suggested URL available');
                return;
            }
        } else if (fixAction === 'custom') {
            replacementUrl = $('#custom-url-field').val().trim();
            console.log('[APPLY CURRENT FIX] Using custom URL:', replacementUrl);
            if (!replacementUrl) {
                alert('Please enter a custom URL');
                return;
            }
        } else if (fixAction === 'home') {
            // Use WordPress home URL
            replacementUrl = window.location.origin + '/wordpress/';
            console.log('[APPLY CURRENT FIX] Using home URL:', replacementUrl);
        }

        console.log('[APPLY CURRENT FIX] Final replacement URL:', replacementUrl);

        // Update the entry with the user-modified URL
        const entryId = window.currentFixData.id;
        console.log('[APPLY CURRENT FIX] Entry ID:', entryId);

        // First, update the database with the user's custom URL choice
        console.log('[APPLY CURRENT FIX] Sending update entry AJAX request');
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_update_entry',
                nonce: seoautofixBrokenUrls.nonce,
                id: entryId,
                user_modified_url: replacementUrl
            },
            success: function (response) {
                console.log('[APPLY CURRENT FIX] Update entry response:', response);

                // Now apply the fix
                console.log('[APPLY CURRENT FIX] Calling applySelectedFixes with ID:', [entryId]);
                applySelectedFixes([entryId]);

                // Hide the panel
                $('#auto-fix-panel').slideUp();
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.log('[APPLY CURRENT FIX] Error updating entry:', textStatus, errorThrown);
                // Still try to apply the fix even if update fails
                console.log('[APPLY CURRENT FIX] Still calling applySelectedFixes despite error');
                applySelectedFixes([entryId]);
                $('#auto-fix-panel').slideUp();
            }
        });
    }

    /**
     * Delete broken link
     */
    function deleteBrokenLink() {
        console.log('[DELETE BROKEN LINK] Called');

        if (!window.currentFixData) {
            alert('Error: No fix data available');
            return;
        }

        if (!confirm('Are you sure you want to delete this broken link entry?')) {
            return;
        }

        const entryId = window.currentFixData.id;

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_delete_entry',
                nonce: seoautofixBrokenUrls.nonce,
                id: entryId
            },
            success: function (response) {
                console.log('[DELETE BROKEN LINK] Response:', response);

                if (response.success) {
                    alert('Broken link entry deleted successfully');
                    $('#auto-fix-panel').slideUp();
                    loadScanResults(currentScanId);
                } else {
                    alert(response.data.message || 'Failed to delete entry');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.log('[DELETE BROKEN LINK] Error:', textStatus, errorThrown);
                alert('Error deleting entry: ' + textStatus);
            }
        });
    }

})(jQuery);
