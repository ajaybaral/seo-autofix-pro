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
        console.log('=================================================');
        console.log('🔥 BROKEN URL MANAGEMENT JS - VERSION 2.0 - NEW CODE LOADED 🔥');
        console.log('🆕 Timestamp: 2026-01-24 23:22 - COMPREHENSIVE LOGGING 🆕');
        console.log('=================================================');

        // Check if we're on the broken URL management pagers();
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
            $('#fix-error-badge').text((String(resultData.status_code).charAt(0) + "XX"));

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
                $('#custom-url-field').focus();
            } else {
                $('#custom-url-input').hide();
                // Clear validation feedback when switching away from custom
                clearUrlValidationFeedback();
            }
        });

        // Real-time validation for custom URL input (debounced)
        let urlValidationTimeout;
        $('#custom-url-field').on('input', function () {
            const url = $(this).val().trim();

            // Clear previous timeout
            clearTimeout(urlValidationTimeout);

            // Clear feedback if empty
            if (!url) {
                clearUrlValidationFeedback();
                return;
            }

            // Show loading state
            showUrlValidationFeedback('loading', '');

            // Debounce validation (500ms)
            urlValidationTimeout = setTimeout(function () {
                validateCustomUrl(url);
            }, 500);
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
        console.log('[UNDO INIT] Undo Changes button handler attached. Button exists:', $('#undo-changes-btn').length > 0);
        $('#export-report-btn').on('click', downloadReport); // Export ALL broken links
        $('#download-report-btn, #download-report-empty-btn').on('click', downloadActivityLog); // Download FIXED links
        $('#email-report-btn, #email-report-empty-btn').on('click', emailActivityLog); // Email FIXED links
    }

    /**
     * Update the header broken link count based on visible table rows
     */
    function updateHeaderBrokenCount() {
        const visibleRows = $('.broken-links-table tbody tr:visible').not(':has(td[colspan])').length;
        $('#header-broken-count').text(visibleRows);
        console.log('[UPDATE HEADER COUNT] Updated broken link count to:', visibleRows);
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

        // Reset progress text to "Scanning..."
        $('#scan-progress-text').text('Scanning...');

        // Clear dynamic display tracking
        window.displayedLinkIds = new Set();

        // Hide table initially - will show when first broken link found
        console.log('[SCAN START] Hiding table container and filters');
        $('#scan-progress-container').show();
        $('.seoautofix-table-container-new').hide();
        $('.filter-section').hide();

        // Disable export button until results are available
        $('#export-report-btn').prop('disabled', true).addClass('disabled');
        console.log('[SCAN START] Export button disabled');

        console.log('[SCAN START] Table hidden - will show when broken links found');

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
     * Update progress bar UI with current scan stats
     */
    function updateProgressBar(data) {
        try {
            console.log('[UPDATE PROGRESS BAR] ✅ FUNCTION CALLED, Data:', data);

            // Update progress percentage and bar width
            const progress = Math.round(data.progress || 0);
            $('#scan-progress-fill').css('width', progress + '%');
            $('#scan-progress-percentage').text(progress + '%');

            // Update URLs tested counts
            const testedUrls = data.tested_urls || 0;
            const totalUrls = data.total_urls || 0;
            const brokenCount = data.broken_count || 0;

            console.log('[UPDATE PROGRESS BAR] Setting values:', {
                testedUrls, totalUrls, brokenCount
            });

            $('#scan-urls-tested').text(testedUrls);
            $('#scan-urls-total').text(totalUrls);
            $('#scan-broken-count').text(brokenCount);

            // Update text to show progress
            $('#scan-progress-text').text('Scanning...');

            console.log('[UPDATE PROGRESS BAR] ✅ COMPLETE - Progress:', progress + '%', 'Tested:', testedUrls + '/' + totalUrls, 'Broken:', brokenCount);

            // Only show "Scan complete!" when actually completed
            if (data.status === 'completed') {
                $('#scan-progress-text').text(seoautofixBrokenUrls.strings.scanComplete || 'Scan complete!');
            }
        } catch (error) {
            console.error('🔥🔥🔥 ERROR IN updateProgressBar:', error);
        }
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

                    // Update progress BAR directly (bypass function to avoid cache issues)
                    console.log('📊 UPDATING PROGRESS:', {
                        progress: data.progress,
                        pages: data.pages_processed + '/' + data.total_pages,
                        links_tested: data.links_found,
                        broken: data.stats ? data.stats.total : 0,
                        completed: data.completed
                    });

                    // Update progress bar percentage
                    const progress = Math.round(data.progress || 0);
                    $('#scan-progress-fill').css('width', progress + '%');
                    $('#scan-progress-percentage').text(progress + '%');

                    // Show LINKS tested (not pages)
                    if (data.links_found !== undefined) {
                        const linksTested = data.links_found || 0;
                        // While scanning: show "X" for both tested and total
                        // This will update as more links are found
                        $('#scan-urls-tested').text(linksTested);
                        $('#scan-urls-total').text(linksTested);
                        console.log('✅ Updated links tested:', linksTested);
                    }

                    if (data.stats && data.stats.total !== undefined) {
                        $('#scan-broken-count').text(data.stats.total);
                        console.log('✅ Updated broken count:', data.stats.total);
                    }

                    $('#scan-progress-text').text('Scanning...');

                    console.log('✅ PROGRESS UPDATED');

                    // NEW: Update results and stats in real-time if broken links found
                    if (data.broken_links && data.broken_links.length > 0) {
                        console.log('[SCAN DEBUG] 🟢 Found', data.broken_links.length, 'broken links, calling updateDynamicResults NOW');
                        console.log('[SCAN DEBUG] Broken links data:', data.broken_links);
                        console.log('[SCAN DEBUG] Stats:', data.stats);
                        updateDynamicResults(data.broken_links, data.stats);
                        console.log('[SCAN DEBUG] ✅ updateDynamicResults completed');
                    } else {
                        console.log('[SCAN DEBUG] No broken links in this batch');
                    }

                    if (data.completed) {
                        console.log('[SCAN DEBUG] Scan completed!');
                        $('#scan-progress-text').text(seoautofixBrokenUrls.strings.scanComplete || 'Scan complete!');
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

        // Create snapshot for undo functionality
        createSnapshot(currentScanId);

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

        // Enable export button when results are available
        $('#export-report-btn').prop('disabled', false).removeClass('disabled');
        console.log('[DISPLAY RESULTS] Export button enabled - results loaded');

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
        // Handle status code 0 (connection failures) separately
        let errorType, errorClass;
        if (statusCode === 0) {
            errorType = result.error_type || 'Connection';
            errorClass = 'error-connection'; // Use connection error styling
        } else {
            errorType = result.error_type || (statusCode >= 500 ? '5xx' : '4xx');
            errorClass = errorType === '5xx' ? 'error-5xx' : 'error-4xx';
        }

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
        console.log('[CREATE ROW] Result data:', result);

        // Create table row with data-id for animations
        const row = $('<tr>').attr('data-id', result.id);
        const pageTitle = result.found_on_page_title || extractPageName(result.found_on_url);

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
            '<a href="' + escapeHtml(result.found_on_url) + '" class="page-url-link" target="_blank" style="font-weight: bold;">' +
            escapeHtml(result.found_on_url) +
            '</a>' +
            '<br>' +
            '<span style="font-size: 0.85em; color: #666;">' + escapeHtml(pageTitle) + '</span>' +
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
            '<span class="status-text">' + (statusCode === 0 ? 'Connection Failed' : statusCode + ' Error') + '</span> ' +
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
     * Update results and stats dynamically during scanning
     * Shows results in real-time as links are discovered
     */
    function updateDynamicResults(brokenLinks, stats) {
        console.log('[DYNAMIC UPDATE] Updating with', brokenLinks.length, 'links');

        // Show table container if hidden (correct selector!)
        const $tableContainer = $('.seoautofix-table-container-new');
        const $filterSection = $('.filter-section');
        const isTableHidden = $tableContainer.is(':hidden');

        console.log('[DYNAMIC UPDATE] Table container found:', $tableContainer.length, 'hidden:', isTableHidden);

        if (isTableHidden) {
            console.log('[DYNAMIC UPDATE] 🔥 SHOWING TABLE NOW 🔥');
            $tableContainer.show();
            $filterSection.show(); // Also show filters

            // Enable export button now that we have results
            $('#export-report-btn').prop('disabled', false).removeClass('disabled');
            console.log('[DYNAMIC UPDATE] Export button enabled');

            console.log('[DYNAMIC UPDATE] Table should now be visible');
        } else {
            console.log('[DYNAMIC UPDATE] Table already visible');
        }

        // Update stats
        if (stats) {
            console.log('[DYNAMIC UPDATE] Updating stats:', stats);
            $('#header-broken-count').text(stats.total || 0);
            updateFilterCounts({ stats: stats });
        }

        // Track which links we've already displayed
        if (!window.displayedLinkIds) {
            window.displayedLinkIds = new Set();
        }

        // Add new rows for links we haven't shown yet
        const $tbody = $('#results-table tbody');
        let newRowsAdded = 0;

        brokenLinks.forEach(link => {
            if (!window.displayedLinkIds.has(link.id)) {
                const $row = createResultRow(link);
                $tbody.append($row); // Add to bottom (chronological order)
                $row.hide().fadeIn(400); // Smooth appearance
                window.displayedLinkIds.add(link.id);
                newRowsAdded++;
            }
        });

        if (newRowsAdded > 0) {
            console.log('[DYNAMIC UPDATE] Added', newRowsAdded, 'new rows');
        }
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
            newUrl = $('#custom-url-field').val().trim();
        } else if (action === 'home') {
            newUrl = window.location.origin;
        }

        if (!newUrl) {
            alert('Please enter a URL or select an option');
            return;
        }

        // If custom URL, validate it first
        if (action === 'custom') {
            console.log('[APPLY FIX] Validating custom URL before saving:', newUrl);

            // Check if validation feedback shows invalid
            const $statusIcon = $('#url-validation-status');
            if ($statusIcon.hasClass('invalid')) {
                alert('Please enter a valid URL. The current URL is broken or invalid.');
                return;
            }

            // Validate the URL before proceeding
            $.ajax({
                url: seoautofixBrokenUrls.ajax_url,
                method: 'POST',
                data: {
                    action: 'seoautofix_broken_links_test_url',
                    nonce: seoautofixBrokenUrls.nonce,
                    url: newUrl
                },
                success: function (validationResponse) {
                    if (validationResponse.success && validationResponse.data.is_valid) {
                        // URL is valid, proceed with saving
                        saveFixedUrl(result.id, newUrl);
                    } else {
                        // URL is invalid
                        const errorMsg = validationResponse.data ? validationResponse.data.message : 'URL is invalid';
                        alert('Cannot save broken URL: ' + errorMsg);
                        showUrlValidationFeedback('invalid', errorMsg);
                    }
                },
                error: function () {
                    alert('Failed to validate URL. Please try again.');
                }
            });
        } else {
            // For suggested or home URLs, save directly
            saveFixedUrl(result.id, newUrl);
        }
    }

    /**
     * Save the fixed URL (helper function for applyCurrentFix)
     */
    function saveFixedUrl(entryId, newUrl) {
        console.log('[SAVE FIX] Saving URL for entry', entryId, ':', newUrl);

        // Apply fix via AJAX
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_update_suggestion',
                nonce: seoautofixBrokenUrls.nonce,
                id: entryId,
                new_url: newUrl
            },
            success: function (response) {
                if (response.success) {
                    // Update the table row's suggested URL display
                    updateTableRowSuggestedUrl(entryId, newUrl);

                    // Close panel and refresh
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
    /**
     * Create snapshot of current scan state for undo functionality
     */
    function createSnapshot(scanId) {
        console.log('[SNAPSHOT] Creating snapshot for scan:', scanId);

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_create_snapshot',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: scanId
            },
            success: function (response) {
                console.log('[SNAPSHOT] Response:', response);

                if (response.success) {
                    console.log('[SNAPSHOT] Snapshot created:', response.data.snapshot_count, 'pages');
                    // Enable undo button
                    $('#undo-changes-btn').prop('disabled', false);
                } else {
                    console.error('[SNAPSHOT] Failed to create snapshot:', response.data.message);
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[SNAPSHOT] Error creating snapshot:', textStatus, errorThrown);
            }
        });
    }

    /**
     * Undo all changes - restore from snapshot
     */
    function undoChanges() {
        console.log('██████████████████████████████████████████████████████████');
        console.log('🆕 NEW UNDO FUNCTION CALLED - SNAPSHOT SYSTEM - v2.0 🆕');
        console.log('██████████████████████████████████████████████████████████');
        console.log('[UNDO] ========== UNDO BUTTON CLICKED ==========');
        console.log('[UNDO] Button element:', $('#undo-changes-btn')[0]);
        console.log('[UNDO] Button disabled state:', $('#undo-changes-btn').prop('disabled'));
        console.log('[UNDO] Current scan ID:', currentScanId);

        if (!currentScanId) {
            console.error('[UNDO] No currentScanId available!');
            alert('No scan available to undo.');
            return;
        }

        console.log('[UNDO] Showing confirmation dialog...');
        if (!confirm('Are you sure you want to undo ALL changes? This will restore all pages to their original state before any fixes or deletions.')) {
            console.log('[UNDO] User cancelled confirmation');
            return;
        }

        console.log('[UNDO] User confirmed. Proceeding with restore...');
        console.log('[UNDO] Restoring from snapshot for scan:', currentScanId);

        // Show loading state
        showNotification('Restoring original state...', 'info');
        $('#undo-changes-btn').prop('disabled', true).text('Undoing...');
        console.log('[UNDO] Button disabled and text changed to "Undoing..."');

        console.log('[UNDO] Sending AJAX request...');
        console.log('[UNDO] AJAX URL:', seoautofixBrokenUrls.ajaxUrl);
        console.log('[UNDO] AJAX data:', {
            action: 'seoautofix_broken_links_undo_changes',
            nonce: seoautofixBrokenUrls.nonce,
            scan_id: currentScanId
        });

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_undo_changes',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: currentScanId
            },
            success: function (response) {
                console.log('[UNDO] ========== AJAX SUCCESS ==========');
                console.log('[UNDO] Response:', response);
                console.log('[UNDO] Response type:', typeof response);
                console.log('[UNDO] Response.success:', response.success);
                console.log('[UNDO] Response.data:', response.data);

                if (response.success) {
                    console.log('[UNDO] Undo successful!');

                    // Show detailed success message
                    const activityDeleted = response.data.activity_deleted || 0;
                    let message = response.data.message || 'Changes undone successfully!';

                    if (activityDeleted > 0) {
                        message += `\n\n✓ ${activityDeleted} fix/delete action(s) removed from history`;
                        message += '\n✓ "Download Fixed Report" is now empty';
                    }

                    showNotification(message, 'success');

                    // Reload scan results to show restored links
                    console.log('[UNDO] Reloading scan results...');
                    loadScanResults(currentScanId);

                    // Re-enable button now that undo is complete
                    // User can make new changes and undo again if needed
                    $('#undo-changes-btn').prop('disabled', false).text('Undo Changes');
                    console.log('[UNDO] Button re-enabled and text reset to "Undo Changes"');
                } else {
                    console.error('[UNDO] Undo failed:', response.data.message);
                    showNotification(response.data.message || 'Failed to undo changes', 'error');
                    $('#undo-changes-btn').prop('disabled', false).text('Undo Changes');
                    console.log('[UNDO] Button re-enabled due to failure');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[UNDO] ========== AJAX ERROR ==========');
                console.error('[UNDO] jqXHR:', jqXHR);
                console.error('[UNDO] Status:', jqXHR.status);
                console.error('[UNDO] Response text:', jqXHR.responseText);
                console.error('[UNDO] Text status:', textStatus);
                console.error('[UNDO] Error thrown:', errorThrown);
                showNotification('Error undoing changes: ' + textStatus, 'error');
                $('#undo-changes-btn').prop('disabled', false).text('Undo Changes');
                console.log('[UNDO] Button re-enabled due to error');
            }
        });
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
            replacementUrl = seoautofixBrokenUrls.homeUrl || (window.location.origin + '/wordpress/');
            console.log('[APPLY CURRENT FIX] Using home URL:', replacementUrl);
        }

        console.log('[APPLY CURRENT FIX] Final replacement URL:', replacementUrl);

        const entryId = window.currentFixData.id;
        console.log('[APPLY CURRENT FIX] Entry ID:', entryId);

        // Apply the fix directly with the custom URL
        console.log('[APPLY CURRENT FIX] Calling applyFixWithCustomUrl');
        applyFixWithCustomUrl(entryId, replacementUrl);

        // Hide the panel
        $('#auto-fix-panel').slideUp();
    }

    /**
     * Apply fix with custom URL
     */
    function applyFixWithCustomUrl(entryId, customUrl) {
        console.log('[APPLY FIX WITH CUSTOM URL] Entry ID:', entryId, 'URL:', customUrl);

        if (!customUrl) {
            alert('No replacement URL provided');
            return;
        }

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_apply_fixes',
                nonce: seoautofixBrokenUrls.nonce,
                ids: [entryId],
                custom_url: customUrl  // Pass the custom URL
            },
            success: function (response) {
                console.log('[APPLY FIX WITH CUSTOM URL] Response:', response);

                if (response.success) {
                    const fixed = response.data.fixed_count || 0;
                    const failed = response.data.failed_count || 0;

                    if (fixed > 0) {
                        // Show blinking FIXED status
                        const $row = $(`tr[data-id="${entryId}"]`);
                        if ($row.length) {
                            console.log('[APPLY FIX] Showing FIXED animation for entry:', entryId);

                            // Replace row content with FIXED status
                            const colspan = $row.find('td').length;
                            $row.html(`<td colspan="${colspan}" class="row-status-fixed">✓ FIXED</td>`);

                            // Remove row after 3 seconds
                            setTimeout(() => {
                                $row.fadeOut(300, function () {
                                    $(this).remove();

                                    // Update "No results" message if table is empty
                                    if ($('.broken-links-table tbody tr').length === 0) {
                                        $('.broken-links-table tbody').html(
                                            '<tr><td colspan="5" style="text-align:center; padding: 30px;">No broken links found</td></tr>'
                                        );
                                    }
                                });
                            }, 3000);
                        }
                    }

                    // Show summary message
                    let message = '';
                    if (fixed > 0) {
                        message = '✅ Fixed: ' + fixed;
                    }
                    if (failed > 0) {
                        message += (message ? '\\n' : '') + '❌ Failed: ' + failed;
                    }
                    if (response.data.messages && response.data.messages.length > 0) {
                        message += '\\n\\nMessages:\\n' + response.data.messages.join('\\n');
                    }

                    if (message) {
                        alert(message);
                    }
                } else {
                    alert('Error: ' + (response.data.message || 'Failed to apply fix'));
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[APPLY FIX WITH CUSTOM URL] Error:', textStatus, errorThrown);
                alert('Error applying fix: ' + textStatus);
            }
        });
    }

    /**
     * Delete broken link from individual Fix modal - Removes link from WordPress content
     */
    function deleteBrokenLink() {
        console.log('[DELETE BROKEN LINK] Called');
        console.log('[DELETE BROKEN LINK] currentFixData:', window.currentFixData);

        if (!window.currentFixData) {
            alert('Error: No fix data available');
            return;
        }

        if (!confirm('This will REMOVE the broken link from your WordPress content.\n\nFor links: Keeps the text, removes the <a> tag\nFor images: Removes the entire <img> tag\n\nContinue?')) {
            console.log('[DELETE BROKEN LINK] User cancelled');
            return;
        }

        const entryId = window.currentFixData.id;
        console.log('[DELETE BROKEN LINK] Removing link from content for entry ID:', entryId);

        // Call backend to remove link from WordPress content
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_delete_entry',
                nonce: seoautofixBrokenUrls.nonce,
                id: entryId
            },
            success: function (response) {
                console.log('[DELETE BROKEN LINK] Backend response:', response);

                if (response.success) {
                    console.log('[DELETE BROKEN LINK] Successfully removed link from content');

                    // Hide the panel
                    $('#auto-fix-panel').slideUp();

                    // Show blinking DELETED status
                    const $row = $(`tr[data-id="${entryId}"]`);
                    if ($row.length) {
                        console.log('[DELETE] Showing DELETED animation for entry:', entryId);

                        // Replace row content with DELETED status
                        const colspan = $row.find('td').length;
                        $row.html(`<td colspan="${colspan}" class="row-status-deleted">✗ DELETED</td>`);

                        // Remove row after 2 seconds
                        setTimeout(() => {
                            $row.fadeOut(300, function () {
                                $(this).remove();

                                // Update header count
                                updateHeaderBrokenCount();

                                // Update "No results" message if table is empty
                                if ($('.broken-links-table tbody tr').length === 0) {
                                    $('.broken-links-table tbody').html(
                                        '<tr><td colspan="5" style="text-align:center; padding: 30px;">No broken links found</td></tr>'
                                    );
                                }
                            });
                        }, 2000);
                    }
                } else {
                    console.error('[DELETE BROKEN LINK] Delete failed:', response.data);
                    alert('Error: ' + (response.data.message || 'Failed to remove link from content'));
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[DELETE BROKEN LINK] AJAX error:', {
                    status: jqXHR.status,
                    statusText: textStatus,
                    error: errorThrown,
                    responseText: jqXHR.responseText
                });
                alert('Error removing link: ' + textStatus);
            }
        });
    }

    /**
     * Download report - Export broken links to CSV
     */
    function downloadReport() {
        console.log('[DOWNLOAD REPORT] Button clicked');

        if (!currentScanId) {
            alert('No scan available. Please run a scan first.');
            return;
        }

        // Build download URL
        const downloadUrl = seoautofixBrokenUrls.ajaxUrl +
            '?action=seoautofix_broken_links_export_csv' +
            '&nonce=' + encodeURIComponent(seoautofixBrokenUrls.nonce) +
            '&scan_id=' + encodeURIComponent(currentScanId) +
            '&filter=all';

        console.log('[DOWNLOAD REPORT] Download URL:', downloadUrl);

        // Create temporary anchor element to trigger download
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = 'broken-links-report.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Show success notification
        showNotification('CSV report download started', 'success');
    }

    /**
     * Download activity log - FIXED/DELETED links only
     */
    function downloadActivityLog() {
        console.log('[DOWNLOAD ACTIVITY LOG] ========== BUTTON CLICKED ==========');
        console.log('[DOWNLOAD ACTIVITY LOG] currentScanId:', currentScanId);

        if (!currentScanId) {
            console.error('[DOWNLOAD ACTIVITY LOG] No currentScanId available');
            alert('No scan available. Please run a scan first.');
            return;
        }

        // Build download URL
        const downloadUrl = seoautofixBrokenUrls.ajaxUrl +
            '?action=seoautofix_broken_links_export_activity_log' +
            '&nonce=' + encodeURIComponent(seoautofixBrokenUrls.nonce) +
            '&scan_id=' + encodeURIComponent(currentScanId);

        console.log('[DOWNLOAD ACTIVITY LOG] Download URL:', downloadUrl);
        console.log('[DOWNLOAD ACTIVITY LOG] Ajax URL:', seoautofixBrokenUrls.ajaxUrl);
        console.log('[DOWNLOAD ACTIVITY LOG] Nonce:', seoautofixBrokenUrls.nonce);

        // Create temporary anchor element to trigger download
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = 'fixed-links-report.csv';
        document.body.appendChild(link);

        console.log('[DOWNLOAD ACTIVITY LOG] Triggering download...');
        link.click();

        document.body.removeChild(link);
        console.log('[DOWNLOAD ACTIVITY LOG] Download initiated');

        // Show success notification
        showNotification('Fixed links report download started', 'success');
    }

    /**
     * Email activity log - FIXED/DELETED links only
     * Automatically sends to WordPress admin email
     */
    function emailActivityLog() {
        console.log('[EMAIL ACTIVITY LOG] ========== BUTTON CLICKED ==========');
        console.log('[EMAIL ACTIVITY LOG] currentScanId:', currentScanId);

        if (!currentScanId) {
            console.error('[EMAIL ACTIVITY LOG] No currentScanId available');
            alert('No scan available. Please run a scan first.');
            return;
        }

        console.log('[EMAIL ACTIVITY LOG] Sending email to WordPress admin...');

        // Show loading state
        showNotification('Sending email to admin...', 'info');

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_email_activity_log',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: currentScanId
            },
            success: function (response) {
                console.log('[EMAIL ACTIVITY LOG] Response:', response);

                if (response.success) {
                    showNotification(response.data.message || 'Email sent successfully to admin!', 'success');
                } else {
                    showNotification(response.data.message || 'Failed to send email', 'error');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[EMAIL ACTIVITY LOG] Error:', textStatus, errorThrown);
                showNotification('Error sending email: ' + textStatus, 'error');
            }
        });
    }

    /**
     * Email report - Send report via email
     */
    function emailReport() {
        console.log('[EMAIL REPORT] Button clicked');

        if (!currentScanId) {
            alert('No scan available. Please run a scan first.');
            return;
        }

        // Show email input modal
        showEmailReportModal();
    }

    /**
     * Show email report modal
     */
    function showEmailReportModal() {
        // Remove existing modal if any
        $('#email-report-modal').remove();

        // Get admin email as default
        const adminEmail = seoautofixBrokenUrls.adminEmail || '';

        // Build modal HTML
        const modalHtml = `
            <div id="email-report-modal" class="seoautofix-modal">
                <div class="seoautofix-modal-content">
                    <div class="seoautofix-modal-header">
                        <h2>Email Broken Links Report</h2>
                        <button class="seoautofix-modal-close">&times;</button>
                    </div>
                    <div class="seoautofix-modal-body">
                        <p>Enter the email address where you want to receive the broken links report.</p>
                        <div class="email-input-group">
                            <label for="report-email-input">Email Address:</label>
                            <input type="email" id="report-email-input" class="regular-text" 
                                   placeholder="your@email.com" value="${adminEmail}" required />
                        </div>
                        <div class="email-format-group">
                            <label>
                                <input type="radio" name="email-format" value="summary" checked />
                                Summary only (HTML email with statistics)
                            </label>
                            <label>
                                <input type="radio" name="email-format" value="csv" />
                                Full report with CSV attachment
                            </label>
                        </div>
                        <div id="email-error-message" class="error-message" style="display: none; color: #dc3232; margin-top: 10px;"></div>
                    </div>
                    <div class="seoautofix-modal-footer">
                        <button class="button seoautofix-modal-cancel">Cancel</button>
                        <button class="button button-primary" id="send-email-report-btn">Send Report</button>
                    </div>
                </div>
            </div>
        `;

        // Append to body
        $('body').append(modalHtml);

        // Show modal
        $('#email-report-modal').fadeIn(200);

        // Focus on email input
        $('#report-email-input').focus();

        // Event listeners
        $('#email-report-modal .seoautofix-modal-close, #email-report-modal .seoautofix-modal-cancel').on('click', function () {
            $('#email-report-modal').fadeOut(200, function () {
                $(this).remove();
            });
        });

        // Click outside to close
        $('#email-report-modal').on('click', function (e) {
            if ($(e.target).is('#email-report-modal')) {
                $(this).fadeOut(200, function () {
                    $(this).remove();
                });
            }
        });

        // Send button click
        $('#send-email-report-btn').on('click', function () {
            const email = $('#report-email-input').val().trim();
            const format = $('input[name="email-format"]:checked').val();

            // Validate email
            if (!email) {
                $('#email-error-message').text('Please enter an email address').show();
                return;
            }

            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                $('#email-error-message').text('Please enter a valid email address').show();
                return;
            }

            // Hide error message
            $('#email-error-message').hide();

            // Disable button and show loading
            $(this).prop('disabled', true).text('Sending...');

            // Send email
            sendEmailReport(email, format);
        });

        // Allow Enter key to submit
        $('#report-email-input').on('keypress', function (e) {
            if (e.which === 13) {
                $('#send-email-report-btn').click();
            }
        });
    }

    /**
     * Send email report via AJAX
     */
    function sendEmailReport(email, format) {
        console.log('[SEND EMAIL] Email:', email, 'Format:', format);

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
                console.log('[SEND EMAIL] Response:', response);

                if (response.success) {
                    alert(response.data.message || `Report sent successfully to ${email}`);

                    // Close modal
                    $('#email-report-modal').fadeOut(200, function () {
                        $(this).remove();
                    });
                } else {
                    $('#email-error-message').text(response.data.message || 'Failed to send email').show();
                    $('#send-email-report-btn').prop('disabled', false).text('Send Report');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[SEND EMAIL] Error:', textStatus, errorThrown);
                $('#email-error-message').text('Error sending email: ' + textStatus).show();
                $('#send-email-report-btn').prop('disabled', false).text('Send Report');
            }
        });
    }

    /**
     * Show notification message
     */
    function showNotification(message, type) {
        // Remove existing notification if any
        $('.seoautofix-notification').remove();

        const notificationClass = type === 'success' ? 'notice-success' : 'notice-error';
        const notificationHtml = `
            <div class="seoautofix-notification notice ${notificationClass} is-dismissible" style="position: fixed; top: 32px; right: 20px; z-index: 10000; max-width: 400px;">
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `;

        $('body').append(notificationHtml);

        // Auto dismiss after 3 seconds
        setTimeout(function () {
            $('.seoautofix-notification').fadeOut(300, function () {
                $(this).remove();
            });
        }, 3000);

        // Manual dismiss
        $('.seoautofix-notification .notice-dismiss').on('click', function () {
            $(this).parent().fadeOut(300, function () {
                $(this).remove();
            });
        });
    }

    /**
     * Remove Broken Links - Bulk delete all broken links
     */
    function removeBrokenLinks() {
        console.log('[REMOVE BROKEN LINKS] Button clicked');

        if (!currentScanId) {
            alert('No scan available. Please run a scan first.');
            return;
        }

        // Fetch all unfixed broken links
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'GET',
            data: {
                action: 'seoautofix_broken_links_get_results',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: currentScanId,
                page: 1,
                per_page: 99999 // Get all results
            },
            success: function (response) {
                if (response.success) {
                    const allLinks = response.data.results || [];
                    const unfixedLinks = allLinks.filter(link => !link.is_fixed || link.is_fixed == 0);

                    if (unfixedLinks.length === 0) {
                        alert('No broken links to remove. All links are already fixed or there are no broken links.');
                        return;
                    }

                    // Show confirmation
                    const confirmed = confirm(
                        `This will remove ${unfixedLinks.length} broken link(s) from your content.\n\n` +
                        `The anchor text will remain, but the <a> tags will be deleted.\n\n` +
                        `This action can be undone later. Continue?`
                    );

                    if (confirmed) {
                        performBulkRemove(unfixedLinks);
                    }
                } else {
                    alert(response.data.message || 'Failed to fetch broken links');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[REMOVE BROKEN LINKS] Error:', textStatus, errorThrown);
                alert('Error fetching broken links: ' + textStatus);
            }
        });
    }

    /**
     * Perform bulk remove operation
     */
    function performBulkRemove(links) {
        console.log('[PERFORM BULK REMOVE] Removing', links.length, 'links');

        // Show progress modal
        showBulkProgressModal('Removing Broken Links', links.length);

        // Group links by page to create fix plan
        const entryIds = links.map(link => link.id);

        // Generate fix plan
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_generate_fix_plan',
                nonce: seoautofixBrokenUrls.nonce,
                entry_ids: entryIds
            },
            success: function (response) {
                console.log('[BULK REMOVE] Fix plan response:', response);

                if (response.success) {
                    const planId = response.data.plan_id;

                    // Now apply the fix plan with delete action
                    applyBulkFixPlan(planId, entryIds, 'delete', 'Removed');
                } else {
                    closeBulkProgressModal();
                    alert(response.data.message || 'Failed to generate fix plan');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[BULK REMOVE] Error:', textStatus, errorThrown);
                closeBulkProgressModal();
                alert('Error creating fix plan: ' + textStatus);
            }
        });
    }

    /**
     * Replace Broken Links - Bulk replace with suggested URLs
     */
    function replaceBrokenLinks() {
        console.log('[REPLACE BROKEN LINKS] Button clicked');

        if (!currentScanId) {
            alert('No scan available. Please run a scan first.');
            return;
        }

        // Fetch all unfixed broken links
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'GET',
            data: {
                action: 'seoautofix_broken_links_get_results',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: currentScanId,
                page: 1,
                per_page: 99999
            },
            success: function (response) {
                if (response.success) {
                    const allLinks = response.data.results || [];
                    const unfixedLinks = allLinks.filter(link => !link.is_fixed || link.is_fixed == 0);

                    if (unfixedLinks.length === 0) {
                        alert('No broken links to replace.');
                        return;
                    }

                    // Categorize into THREE groups
                    const withSuggestion = unfixedLinks.filter(link =>
                        link.link_type === 'internal' && link.suggested_url && link.suggested_url.trim() !== ''
                    );
                    const internalNoSuggestion = unfixedLinks.filter(link =>
                        link.link_type === 'internal' && (!link.suggested_url || link.suggested_url.trim() === '')
                    );
                    const external = unfixedLinks.filter(link =>
                        link.link_type === 'external'
                    );

                    if (withSuggestion.length === 0 && internalNoSuggestion.length === 0 && external.length === 0) {
                        alert('No broken links found.');
                        return;
                    }

                    // Show preview modal with three categories
                    showReplacePreviewModal(withSuggestion, internalNoSuggestion, external);
                } else {
                    alert(response.data.message || 'Failed to fetch broken links');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[REPLACE BROKEN LINKS] Error:', textStatus, errorThrown);
                alert('Error fetching broken links: ' + textStatus);
            }
        });
    }

    /**
     * Show replace preview modal with three categories
     */
    function showReplacePreviewModal(withSuggestion, internalNoSuggestion, external) {
        // Remove existing modal
        $('#bulk-replace-modal').remove();

        // Get home URL for internal links without suggestion
        const homeUrl = seoautofixBrokenUrls.homeUrl || window.location.origin;

        let modalHtml = `
            <div id="bulk-replace-modal" class="seoautofix-modal">
                <div class="seoautofix-modal-content" style="width: 900px; max-width: 95%;">
                    <div class="seoautofix-modal-header">
                        <h2>Replace Broken Links - Select Categories</h2>
                        <button class="seoautofix-modal-close">&times;</button>
                    </div>
                    <div class="seoautofix-modal-body" style="text-align: center;">
                        <p style="margin-bottom: 25px; font-size: 14px; color: #646970;">
                            Select which categories of broken links you want to replace:
                        </p>
        `;

        // Category 1: Links with Suggestions
        if (withSuggestion.length > 0) {
            modalHtml += `
                <div class="replace-category">
                    <div class="category-header">
                        <label class="category-checkbox-label">
                            <input type="checkbox" class="category-checkbox" id="category-suggested" checked data-category="suggested" />
                            <strong>Links with Suggested Replacements</strong>
                            <span class="category-count">(${withSuggestion.length} link${withSuggestion.length > 1 ? 's' : ''})</span>
                        </label>
                        <div class="category-description">Will replace broken URLs with suggested URLs (click to edit)</div>
                    </div>
                    <div class="category-preview">
            `;

            withSuggestion.slice(0, 5).forEach(link => {
                modalHtml += `
                    <div class="preview-item-compact" data-link-id="${link.id}">
                        <div class="preview-url-old">${escapeHtml(link.broken_url)}</div>
                        <div class="preview-arrow-compact">→</div>
                        <div class="preview-url-new editable" 
                             contenteditable="true" 
                             data-original-url="${escapeHtml(link.suggested_url)}"
                             data-category="suggested"
                             spellcheck="false">${escapeHtml(link.suggested_url)}</div>
                        <button class="reset-url-btn" title="Reset to original URL" aria-label="Reset URL">↺</button>
                    </div>
                `;
            });

            if (withSuggestion.length > 5) {
                modalHtml += `<div class="preview-more-compact">... and ${withSuggestion.length - 5} more</div>`;
            }

            modalHtml += `
                    </div>
                </div>
            `;
        }

        // Category 2: Internal Links Without Suggestion
        if (internalNoSuggestion.length > 0) {
            modalHtml += `
                <div class="replace-category">
                    <div class="category-header">
                        <label class="category-checkbox-label">
                            <input type="checkbox" class="category-checkbox" id="category-internal-no-suggestion" checked data-category="internal-no-suggestion" />
                            <strong>Internal Links Without Suggestions</strong>
                            <span class="category-count">(${internalNoSuggestion.length} link${internalNoSuggestion.length > 1 ? 's' : ''})</span>
                        </label>
                        <div class="category-description">Will replace with Home Page: <code>${escapeHtml(homeUrl)}</code> (click to edit)</div>
                    </div>
                    <div class="category-preview">
            `;

            internalNoSuggestion.slice(0, 5).forEach(link => {
                modalHtml += `
                    <div class="preview-item-compact" data-link-id="${link.id}">
                        <div class="preview-url-old">${escapeHtml(link.broken_url)}</div>
                        <div class="preview-arrow-compact">→</div>
                        <div class="preview-url-new editable" 
                             contenteditable="true" 
                             data-original-url="${escapeHtml(homeUrl)}"
                             data-category="internal-no-suggestion"
                             spellcheck="false">${escapeHtml(homeUrl)}</div>
                        <button class="reset-url-btn" title="Reset to Home URL" aria-label="Reset URL">↺</button>
                    </div>
                `;
            });

            if (internalNoSuggestion.length > 5) {
                modalHtml += `<div class="preview-more-compact">... and ${internalNoSuggestion.length - 5} more</div>`;
            }

            modalHtml += `
                    </div>
                </div>
            `;
        }

        // Category 3: External Links
        if (external.length > 0) {
            modalHtml += `
                <div class="replace-category">
                    <div class="category-header">
                        <label class="category-checkbox-label">
                            <input type="checkbox" class="category-checkbox" id="category-external" data-category="external" />
                            <strong>External Links</strong>
                            <span class="category-count">(${external.length} link${external.length > 1 ? 's' : ''})</span>
                        </label>
                        <div class="category-description">Will replace with Home Page URL (click to edit)</div>
                    </div>
                    <div class="category-preview">
            `;

            external.slice(0, 5).forEach(link => {
                modalHtml += `
                    <div class="preview-item-compact" data-link-id="${link.id}">
                        <div class="preview-url-old">${escapeHtml(link.broken_url)}</div>
                        <div class="preview-arrow-compact">→</div>
                        <div class="preview-url-new editable" 
                             contenteditable="true" 
                             data-original-url="${escapeHtml(homeUrl)}"
                             data-category="external"
                             spellcheck="false">${escapeHtml(homeUrl)}</div>
                        <button class="reset-url-btn" title="Reset to Home URL" aria-label="Reset URL">↺</button>
                    </div>
                `;
            });

            if (external.length > 5) {
                modalHtml += `<div class="preview-more-compact">... and ${external.length - 5} more</div>`;
            }

            modalHtml += `
                    </div>
                </div>
            `;
        }

        modalHtml += `
                    </div>
                    <div class="seoautofix-modal-footer">
                        <button class="button seoautofix-modal-cancel">Cancel</button>
                        <button class="button button-primary" id="confirm-bulk-replace-v2">Apply Selected Changes</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
        setTimeout(() => $('#bulk-replace-modal').addClass('show'), 10);

        // Setup inline editing functionality
        setupInlineEditing();

        // Event listeners
        $('#bulk-replace-modal .seoautofix-modal-close, #bulk-replace-modal .seoautofix-modal-cancel').on('click', function () {
            $('#bulk-replace-modal').removeClass('show');
            setTimeout(() => $('#bulk-replace-modal').remove(), 200);
        });

        $('#confirm-bulk-replace-v2').on('click', function () {
            console.log('[CONFIRM REPLACE V2] Button clicked');

            // Collect edited URLs from DOM first
            const editedURLs = collectEditedURLs();
            console.log('[CONFIRM REPLACE V2] Edited URLs collected:', editedURLs);

            // Get selected categories
            const selectedCategories = [];
            const linksToProcess = [];

            console.log('[CONFIRM REPLACE V2] Category checkboxes state:', {
                suggested: $('#category-suggested').is(':checked'),
                internalNoSuggestion: $('#category-internal-no-suggestion').is(':checked'),
                external: $('#category-external').is(':checked')
            });

            if ($('#category-suggested').is(':checked')) {
                selectedCategories.push('suggested');
                console.log('[CONFIRM REPLACE V2] Processing suggested category, links:', withSuggestion.length);
                // Add links with their suggested URLs (or edited URLs)
                withSuggestion.forEach(link => {
                    const editedUrl = editedURLs[link.id];
                    const processedLink = {
                        ...link,
                        replace_action: 'suggested',
                        new_url: editedUrl || link.suggested_url
                    };
                    linksToProcess.push(processedLink);
                    console.log('[CONFIRM REPLACE V2] Added suggested link:', {
                        id: link.id,
                        broken_url: link.broken_url,
                        new_url: processedLink.new_url,
                        edited: !!editedUrl
                    });
                });
            }

            if ($('#category-internal-no-suggestion').is(':checked')) {
                selectedCategories.push('internal-no-suggestion');
                console.log('[CONFIRM REPLACE V2] Processing internal-no-suggestion category, links:', internalNoSuggestion.length);
                // Add internal links with home URL (or edited URL)
                internalNoSuggestion.forEach(link => {
                    const editedUrl = editedURLs[link.id];
                    const processedLink = {
                        ...link,
                        replace_action: 'home',
                        new_url: editedUrl || homeUrl
                    };
                    linksToProcess.push(processedLink);
                    console.log('[CONFIRM REPLACE V2] Added internal-no-suggestion link:', {
                        id: link.id,
                        broken_url: link.broken_url,
                        new_url: processedLink.new_url,
                        edited: !!editedUrl
                    });
                });
            }

            if ($('#category-external').is(':checked')) {
                selectedCategories.push('external');
                console.log('[CONFIRM REPLACE V2] Processing external category, links:', external.length);
                // Add external links for home URL replacement (or edited URL)
                external.forEach(link => {
                    const editedUrl = editedURLs[link.id];
                    const processedLink = {
                        ...link,
                        replace_action: 'home',
                        new_url: editedUrl || homeUrl
                    };
                    linksToProcess.push(processedLink);
                    console.log('[CONFIRM REPLACE V2] Added external link:', {
                        id: link.id,
                        broken_url: link.broken_url,
                        new_url: processedLink.new_url,
                        edited: !!editedUrl
                    });
                });
            }

            console.log('[CONFIRM REPLACE V2] Selected categories:', selectedCategories);
            console.log('[CONFIRM REPLACE V2] Total links to process:', linksToProcess.length);
            console.log('[CONFIRM REPLACE V2] Links summary:', linksToProcess.map(l => ({
                id: l.id,
                action: l.replace_action,
                broken: l.broken_url,
                new: l.new_url
            })));

            if (linksToProcess.length === 0) {
                console.warn('[CONFIRM REPLACE V2] No links to process!');
                alert('Please select at least one category to process.');
                return;
            }

            $('#bulk-replace-modal').removeClass('show');
            setTimeout(() => $('#bulk-replace-modal').remove(), 200);

            console.log('[CONFIRM REPLACE V2] Calling performBulkReplaceV2 with', linksToProcess.length, 'links');
            performBulkReplaceV2(linksToProcess);
        });
    }

    /**
     * Setup inline editing for URL fields
     */
    function setupInlineEditing() {
        const $modal = $('#bulk-replace-modal');

        // Handle focus on editable URLs - add visual indicator
        $modal.on('focus', '.preview-url-new.editable', function () {
            $(this).addClass('editing');
        });

        // Handle blur - remove editing indicator
        $modal.on('blur', '.preview-url-new.editable', function () {
            $(this).removeClass('editing');
            const $this = $(this);
            const originalUrl = $this.data('original-url');
            const currentUrl = $this.text().trim();

            // Mark as modified if changed
            if (currentUrl !== originalUrl) {
                $this.addClass('modified');
            } else {
                $this.removeClass('modified');
            }
        });

        // Handle input - mark as modified while typing
        $modal.on('input', '.preview-url-new.editable', function () {
            const $this = $(this);
            const originalUrl = $this.data('original-url');
            const currentUrl = $this.text().trim();

            if (currentUrl !== originalUrl) {
                $this.addClass('modified');
            } else {
                $this.removeClass('modified');
            }
        });

        // Handle reset button click
        $modal.on('click', '.reset-url-btn', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $item = $btn.closest('.preview-item-compact');
            const $urlField = $item.find('.preview-url-new.editable');
            const originalUrl = $urlField.data('original-url');

            // Reset to original URL
            $urlField.text(originalUrl);
            $urlField.removeClass('modified editing');
        });

        // Prevent line breaks in contenteditable
        $modal.on('keydown', '.preview-url-new.editable', function (e) {
            if (e.keyCode === 13) { // Enter key
                e.preventDefault();
                $(this).blur(); // Close editing
            }
        });
    }

    /**
     * Collect edited URLs from the DOM
     * Returns an object mapping link IDs to edited URLs
     */
    function collectEditedURLs() {
        const editedURLs = {};

        $('#bulk-replace-modal .preview-item-compact').each(function () {
            const $item = $(this);
            const linkId = $item.data('link-id');
            const $urlField = $item.find('.preview-url-new.editable');

            if ($urlField.length > 0) {
                const editedUrl = $urlField.text().trim();
                const originalUrl = $urlField.data('original-url');

                // Only store if modified
                if (editedUrl && editedUrl !== originalUrl) {
                    editedURLs[linkId] = editedUrl;
                }
            }
        });

        console.log('[COLLECT EDITED URLS] Found', Object.keys(editedURLs).length, 'edited URLs:', editedURLs);
        return editedURLs;
    }

    /**
     * Perform bulk replace operation (V2 with multi-action support)
     */
    function performBulkReplaceV2(links) {
        console.log('[PERFORM BULK REPLACE V2] ===== START =====');
        console.log('[PERFORM BULK REPLACE V2] Replacing', links.length, 'links');
        console.log('[PERFORM BULK REPLACE V2] Links with actions:', links);

        showBulkProgressModal('Replacing Broken Links', links.length);

        // Build a customized fix plan based on replacement actions
        const fixPlanData = links.map(link => {
            const baseEntry = {
                entry_id: link.id,
                page_id: link.page_id,
                broken_url: link.broken_url
            };

            switch (link.replace_action) {
                case 'suggested':
                    return {
                        ...baseEntry,
                        action: 'replace',
                        new_url: link.new_url || link.suggested_url
                    };
                case 'home':
                    return {
                        ...baseEntry,
                        action: 'replace',
                        new_url: link.new_url // Home URL passed from modal
                    };
                case 'delete':
                    return {
                        ...baseEntry,
                        action: 'delete',
                        new_url: ''
                    };
                default:
                    console.warn('[BULK REPLACE V2] Unknown action:', link.replace_action, 'for link', link.id);
                    return null;
            }
        }).filter(entry => entry !== null);

        console.log('[BULK REPLACE V2] Generated fix plan data:', fixPlanData);
        console.log('[BULK REPLACE V2] Sending to backend:', {
            action: 'seoautofix_broken_links_generate_fix_plan',
            entry_ids: links.map(l => l.id),
            custom_plan_length: fixPlanData.length
        });

        // Generate fix plan with custom actions
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_generate_fix_plan',
                nonce: seoautofixBrokenUrls.nonce,
                entry_ids: links.map(l => l.id),
                custom_plan: JSON.stringify(fixPlanData) // Pass our custom plan
            },
            success: function (response) {
                console.log('[BULK REPLACE V2] Generate plan response:', response);

                if (response.success) {
                    const planId = response.data.plan_id;
                    console.log('[BULK REPLACE V2] Plan generated successfully, ID:', planId);
                    console.log('[BULK REPLACE V2] Applying fix plan...');

                    // Apply the fix plan
                    $.ajax({
                        url: seoautofixBrokenUrls.ajaxUrl,
                        method: 'POST',
                        data: {
                            action: 'seoautofix_broken_links_apply_fix_plan',
                            nonce: seoautofixBrokenUrls.nonce,
                            plan_id: planId
                        },
                        success: function (applyResponse) {
                            console.log('[BULK REPLACE V2] Apply plan response:', applyResponse);
                            closeBulkProgressModal();

                            if (applyResponse.success) {
                                const results = applyResponse.data.results || {};
                                const successCount = results.success || 0;
                                const failedCount = results.failed || 0;

                                console.log('[BULK REPLACE V2] Apply results:', {
                                    success: successCount,
                                    failed: failedCount,
                                    fullResults: results
                                });

                                let message = `Successfully processed ${successCount} link(s).`;
                                if (failedCount > 0) {
                                    message += `\n${failedCount} link(s) failed to process.`;
                                }

                                alert(message);

                                // Refresh the page to show updated results
                                if (successCount > 0) {
                                    console.log('[BULK REPLACE V2] Reloading page to show results');
                                    location.reload();
                                } else {
                                    console.warn('[BULK REPLACE V2] No links were successfully processed');
                                }
                            } else {
                                console.error('[BULK REPLACE V2] Apply failed:', applyResponse.data);
                                alert(applyResponse.data.message || 'Failed to apply fixes');
                            }
                        },
                        error: function (jqXHR, textStatus, errorThrown) {
                            console.error('[BULK REPLACE V2] Apply AJAX error:', {
                                status: jqXHR.status,
                                statusText: textStatus,
                                error: errorThrown,
                                responseText: jqXHR.responseText
                            });
                            closeBulkProgressModal();
                            alert('Error applying fixes: ' + textStatus);
                        }
                    });
                } else {
                    console.error('[BULK REPLACE V2] Generate plan failed:', response.data);
                    closeBulkProgressModal();
                    alert(response.data.message || 'Failed to generate fix plan');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[BULK REPLACE V2] Generate AJAX error:', {
                    status: jqXHR.status,
                    statusText: textStatus,
                    error: errorThrown,
                    responseText: jqXHR.responseText
                });
                closeBulkProgressModal();
                alert('Error creating fix plan: ' + textStatus);
            }
        });
    }

    /**
     * Fix All Issues - Comprehensive fixing with replace AND delete options
     */
    function fixAllIssues() {
        console.log('[FIX ALL ISSUES] Button clicked');

        if (!currentScanId) {
            alert('No scan available. Please run a scan first.');
            return;
        }

        // Fetch all unfixed broken links (same as Replace)
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'GET',
            data: {
                action: 'seoautofix_broken_links_get_results',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: currentScanId,
                page: 1,
                per_page: 99999
            },
            success: function (response) {
                if (response.success) {
                    const allLinks = response.data.results || [];
                    const unfixedLinks = allLinks.filter(link => !link.is_fixed || link.is_fixed == 0);

                    if (unfixedLinks.length === 0) {
                        alert('No broken links to fix. All issues have been resolved!');
                        return;
                    }

                    // Categorize into THREE groups (same as Replace)
                    const withSuggestion = unfixedLinks.filter(link =>
                        link.link_type === 'internal' && link.suggested_url && link.suggested_url.trim() !== ''
                    );
                    const internalNoSuggestion = unfixedLinks.filter(link =>
                        link.link_type === 'internal' && (!link.suggested_url || link.suggested_url.trim() === '')
                    );
                    const external = unfixedLinks.filter(link =>
                        link.link_type === 'external'
                    );

                    if (unfixedLinks.length === 0) {
                        alert('No broken links found.');
                        return;
                    }

                    const homeUrl = seoautofixBrokenUrls.homeUrl || window.location.origin;

                    // Show Fix All modal with delete options
                    showFixAllPreviewModal(withSuggestion, internalNoSuggestion, external, homeUrl);
                } else {
                    alert(response.data.message || 'Failed to fetch broken links');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[FIX ALL ISSUES] Error:', textStatus, errorThrown);
                alert('Error fetching broken links: ' + textStatus);
            }
        });
    }

    /**
     * Show Fix All preview modal - Enhanced with delete options
     */
    function showFixAllPreviewModal(withSuggestion, internalNoSuggestion, external, homeUrl) {
        console.log('[FIX ALL PREVIEW] Opening modal');

        $('#fix-all-modal').remove();

        const totalLinks = withSuggestion.length + internalNoSuggestion.length + external.length;

        let modalHtml = `
            <div id="fix-all-modal" class="seoautofix-modal">
                <div class="seoautofix-modal-content" style="max-width: 900px;">
                    <div class="seoautofix-modal-header">
                        <h2>Fix All Issues - Select Actions</h2>
                        <button class="seoautofix-modal-close">&times;</button>
                    </div>
                    <div class="seoautofix-modal-body" style="max-height: 600px; overflow-y: auto;">
                        <div class="bulk-action-summary">
                            <p><strong>${totalLinks} broken link(s)</strong> found. Select which to replace or delete:</p>
                        </div>
        `;

        // Category 1: Links with Suggestions
        if (withSuggestion.length > 0) {
            modalHtml += `
                <div class="replace-category">
                    <div class="category-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <label class="category-checkbox-label" style="flex: 1;">
                            <input type="checkbox" class="category-checkbox" id="fix-category-suggested" checked data-category="suggested" data-action="replace" />
                            <strong>Links with Suggested Replacements</strong>
                            <span class="category-count">(${withSuggestion.length} link${withSuggestion.length > 1 ? 's' : ''})</span>
                        </label>
                        <button class="category-delete-btn" data-category="suggested" style="margin-left: 10px; padding: 6px 12px; background: #fff; color: #dc3232; border: 1px solid #dc3232; border-radius: 4px; cursor: pointer; font-weight: 500;">
                            Delete All
                        </button>
                    </div>
                    <div class="category-description">Will replace with suggested URLs (click to edit) or delete all</div>
                    <div class="category-preview">
            `;

            withSuggestion.slice(0, 10).forEach(link => {
                modalHtml += `
                    <div class="preview-item-compact" data-link-id="${link.id}" style="display: flex; align-items: center; gap: 10px;">
                        <div class="preview-url-old" style="flex: 1;">${escapeHtml(link.broken_url)}</div>
                        <div class="preview-arrow-compact">→</div>
                        <div class="preview-url-new editable" 
                             contenteditable="true" 
                             data-original-url="${escapeHtml(link.suggested_url)}"
                             data-category="suggested"
                             spellcheck="false" style="flex: 1;">${escapeHtml(link.suggested_url)}</div>
                        <button class="reset-url-btn" title="Reset to original URL">↺</button>
                        <button class="delete-link-btn" data-link-id="${link.id}" title="Delete this link" style="background: #fff; color: #dc3232; border: 1px solid #dc3232; padding: 4px 8px; border-radius: 3px; cursor: pointer;">×</button>
                    </div>
                `;
            });

            if (withSuggestion.length > 10) {
                modalHtml += `<div class="preview-more-compact">... and ${withSuggestion.length - 10} more</div>`;
            }

            modalHtml += `
                    </div>
                </div>
            `;
        }

        // Category 2: Internal Without Suggestions
        if (internalNoSuggestion.length > 0) {
            modalHtml += `
                <div class="replace-category">
                    <div class="category-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <label class="category-checkbox-label" style="flex: 1;">
                            <input type="checkbox" class="category-checkbox" id="fix-category-internal-no-suggestion" checked data-category="internal-no-suggestion" data-action="replace" />
                            <strong>Internal Links Without Suggestions</strong>
                            <span class="category-count">(${internalNoSuggestion.length} link${internalNoSuggestion.length > 1 ? 's' : ''})</span>
                        </label>
                        <button class="category-delete-btn" data-category="internal-no-suggestion" style="margin-left: 10px; padding: 6px 12px; background: #fff; color: #dc3232; border: 1px solid #dc3232; border-radius: 4px; cursor: pointer; font-weight: 500;">
                            Delete All
                        </button>
                    </div>
                    <div class="category-description">Will replace with Home Page: <code>${escapeHtml(homeUrl)}</code> (click to edit) or delete all</div>
                    <div class="category-preview">
            `;

            internalNoSuggestion.slice(0, 10).forEach(link => {
                modalHtml += `
                    <div class="preview-item-compact" data-link-id="${link.id}" style="display: flex; align-items: center; gap: 10px;">
                        <div class="preview-url-old" style="flex: 1;">${escapeHtml(link.broken_url)}</div>
                        <div class="preview-arrow-compact">→</div>
                        <div class="preview-url-new editable" 
                             contenteditable="true" 
                             data-original-url="${escapeHtml(homeUrl)}"
                             data-category="internal-no-suggestion"
                             spellcheck="false" style="flex: 1;">${escapeHtml(homeUrl)}</div>
                        <button class="reset-url-btn" title="Reset to Home URL">↺</button>
                        <button class="delete-link-btn" data-link-id="${link.id}" title="Delete this link" style="background: #fff; color: #dc3232; border: 1px solid #dc3232; padding: 4px 8px; border-radius: 3px; cursor: pointer;">×</button>
                    </div>
                `;
            });

            if (internalNoSuggestion.length > 10) {
                modalHtml += `<div class="preview-more-compact">... and ${internalNoSuggestion.length - 10} more</div>`;
            }

            modalHtml += `
                    </div>
                </div>
            `;
        }

        // Category 3: External Links
        if (external.length > 0) {
            modalHtml += `
                <div class="replace-category">
                    <div class="category-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <label class="category-checkbox-label" style="flex: 1;">
                            <input type="checkbox" class="category-checkbox" id="fix-category-external" data-category="external" data-action="replace" />
                            <strong>External Links</strong>
                            <span class="category-count">(${external.length} link${external.length > 1 ? 's' : ''})</span>
                        </label>
                        <button class="category-delete-btn" data-category="external" style="margin-left: 10px; padding: 6px 12px; background: #fff; color: #dc3232; border: 1px solid #dc3232; border-radius: 4px; cursor: pointer; font-weight: 500;">
                            Delete All
                        </button>
                    </div>
                    <div class="category-description">Will replace with Home Page URL (click to edit) or delete all</div>
                    <div class="category-preview">
            `;

            external.slice(0, 10).forEach(link => {
                modalHtml += `
                    <div class="preview-item-compact" data-link-id="${link.id}" style="display: flex; align-items: center; gap: 10px;">
                        <div class="preview-url-old" style="flex: 1;">${escapeHtml(link.broken_url)}</div>
                        <div class="preview-arrow-compact">→</div>
                        <div class="preview-url-new editable" 
                             contenteditable="true" 
                             data-original-url="${escapeHtml(homeUrl)}"
                             data-category="external"
                             spellcheck="false" style="flex: 1;">${escapeHtml(homeUrl)}</div>
                        <button class="reset-url-btn" title="Reset to Home URL">↺</button>
                        <button class="delete-link-btn" data-link-id="${link.id}" title="Delete this link" style="background: #fff; color: #dc3232; border: 1px solid #dc3232; padding: 4px 8px; border-radius: 3px; cursor: pointer;">×</button>
                    </div>
                `;
            });

            if (external.length > 10) {
                modalHtml += `<div class="preview-more-compact">... and ${external.length - 10} more</div>`;
            }

            modalHtml += `
                    </div>
                </div>
            `;
        }

        modalHtml += `
                    </div>
                    <div class="seoautofix-modal-footer">
                        <button class="button seoautofix-modal-cancel">Cancel</button>
                        <button class="button button-primary" id="confirm-fix-all-v2">Apply Selected Actions</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
        setTimeout(() => $('#fix-all-modal').addClass('show'), 10);

        // Setup inline editing
        setupInlineEditingForModal('#fix-all-modal');

        // Event listeners
        $('#fix-all-modal .seoautofix-modal-close, #fix-all-modal .seoautofix-modal-cancel').on('click', function () {
            $('#fix-all-modal').removeClass('show');
            setTimeout(() => $('#fix-all-modal').remove(), 200);
        });

        // Category Delete buttons
        $('#fix-all-modal .category-delete-btn').on('click', function (e) {
            e.preventDefault();
            const category = $(this).data('category');
            console.log('[FIX ALL - CATEGORY DELETE] Delete All clicked for category:', category);

            // Get all links in this category
            let linksToDelete = [];
            if (category === 'suggested') {
                linksToDelete = withSuggestion;
            } else if (category === 'internal-no-suggestion') {
                linksToDelete = internalNoSuggestion;
            } else if (category === 'external') {
                linksToDelete = external;
            }

            if (linksToDelete.length === 0) {
                alert('No links to delete in this category');
                return;
            }

            if (!confirm(`This will PERMANENTLY DELETE ${linksToDelete.length} broken link(s) from your WordPress content.\\n\\nFor links: Keeps the text, removes the <a> tag\\nFor images: Removes the entire <img> tag\\n\\nContinue?`)) {
                console.log('[FIX ALL - CATEGORY DELETE] User cancelled');
                return;
            }

            // Delete each link one by one
            let successCount = 0;
            let failCount = 0;

            const deleteNextLink = (index) => {
                if (index >= linksToDelete.length) {
                    // All done
                    console.log('[FIX ALL - CATEGORY DELETE] Completed. Success:', successCount, 'Failed:', failCount);

                    // Update header count
                    updateHeaderBrokenCount();

                    // Close modal and reload results
                    $('#fix-all-modal').removeClass('show');
                    setTimeout(() => {
                        $('#fix-all-modal').remove();
                        if (currentScanId) {
                            loadScanResults(currentScanId);
                        }
                    }, 200);

                    if (successCount > 0) {
                        alert(`Successfully deleted ${successCount} link(s) from content${failCount > 0 ? '. Failed: ' + failCount : ''}`);
                    }
                    return;
                }

                const link = linksToDelete[index];
                console.log(`[FIX ALL - CATEGORY DELETE] Deleting link ${index + 1}/${linksToDelete.length}, ID:`, link.id);

                // Call backend to remove from content
                $.ajax({
                    url: seoautofixBrokenUrls.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'seoautofix_broken_links_delete_entry',
                        nonce: seoautofixBrokenUrls.nonce,
                        id: link.id
                    },
                    success: function (response) {
                        if (response.success) {
                            successCount++;
                            console.log('[FIX ALL - CATEGORY DELETE] Successfully deleted ID:', link.id);

                            // Show DELETED animation on main table
                            const $row = $(`tr[data-id="${link.id}"]`);
                            if ($row.length) {
                                const colspan = $row.find('td').length;
                                $row.html(`<td colspan="${colspan}" class="row-status-deleted">✗ DELETED</td>`);

                                setTimeout(() => {
                                    $row.fadeOut(300, function () {
                                        $(this).remove();
                                        updateHeaderBrokenCount(); // Update count after row removed
                                    });
                                }, 2000);
                            }
                        } else {
                            failCount++;
                            console.error('[FIX ALL - CATEGORY DELETE] Failed to delete ID:', link.id, response.data);
                        }

                        // Delete next link
                        deleteNextLink(index + 1);
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        failCount++;
                        console.error('[FIX ALL - CATEGORY DELETE] AJAX error for ID:', link.id, {
                            status: jqXHR.status,
                            statusText: textStatus,
                            error: errorThrown
                        });

                        // Continue with next link even if this failed
                        deleteNextLink(index + 1);
                    }
                });
            };

            // Start deleting from first link
            deleteNextLink(0);
        });

        // Individual delete buttons - Call backend to actually delete
        $('#fix-all-modal .delete-link-btn').on('click', function (e) {
            e.preventDefault();
            const linkId = $(this).data('link-id');
            const $item = $(this).closest('.preview-item-compact');

            console.log('[FIX ALL - INDIVIDUAL DELETE] Button clicked for link ID:', linkId);

            if (!confirm('This will PERMANENTLY DELETE this broken link from your WordPress content.\\n\\nFor links: Keeps the text, removes the <a> tag\\nFor images: Removes the entire <img> tag\\n\\nContinue?')) {
                console.log('[FIX ALL - INDIVIDUAL DELETE] User cancelled');
                return;
            }

            console.log('[FIX ALL - INDIVIDUAL DELETE] Calling backend to delete ID:', linkId);

            // Call backend to remove from content
            $.ajax({
                url: seoautofixBrokenUrls.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'seoautofix_broken_links_delete_entry',
                    nonce: seoautofixBrokenUrls.nonce,
                    id: linkId
                },
                success: function (response) {
                    console.log('[FIX ALL - INDIVIDUAL DELETE] Backend response:', response);

                    if (response.success) {
                        console.log('[FIX ALL - INDIVIDUAL DELETE] Successfully deleted from content');

                        // Show DELETED animation on main table
                        const $row = $(`tr[data-id="${linkId}"]`);
                        if ($row.length) {
                            const colspan = $row.find('td').length;
                            $row.html(`<td colspan="${colspan}" class="row-status-deleted">✗ DELETED</td>`);

                            setTimeout(() => {
                                $row.fadeOut(300, function () {
                                    $(this).remove();

                                    // Update header count
                                    updateHeaderBrokenCount();

                                    // Update "No results" message if table is empty
                                    if ($('.broken-links-table tbody tr').length === 0) {
                                        $('.broken-links-table tbody').html(
                                            '<tr><td colspan="5" style="text-align:center; padding: 30px;">No broken links found</td></tr>'
                                        );
                                    }
                                });
                            }, 2000);
                        }

                        // Remove from UI modal
                        $item.fadeOut(200, function () {
                            $(this).remove();

                            // Check if this was the last item in the modal
                            const remainingItems = $('#fix-all-modal .preview-item-compact:visible').length;
                            console.log('[FIX ALL - INDIVIDUAL DELETE] Remaining items in modal:', remainingItems);

                            if (remainingItems === 0) {
                                console.log('[FIX ALL - INDIVIDUAL DELETE] No items left, closing modal');
                                $('#fix-all-modal').removeClass('show');
                                setTimeout(() => {
                                    $('#fix-all-modal').remove();
                                }, 200);
                            }
                        });
                    } else {
                        console.error('[FIX ALL - INDIVIDUAL DELETE] Delete failed:', response.data);
                        alert('Error: ' + (response.data.message || 'Failed to delete'));
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('[FIX ALL - INDIVIDUAL DELETE] AJAX error:', {
                        status: jqXHR.status,
                        statusText: textStatus,
                        error: errorThrown,
                        responseText: jqXHR.responseText
                    });
                    alert('Error deleting link: ' + textStatus);
                }
            });
        });

        // Confirm button
        $('#confirm-fix-all-v2').on('click', function () {
            handleFixAllConfirm(withSuggestion, internalNoSuggestion, external, homeUrl);
        });
    }

    /**
     * Setup inline editing for any modal
     */
    function setupInlineEditingForModal(modalSelector) {
        const $modal = $(modalSelector);

        $modal.on('focus', '.preview-url-new.editable', function () {
            $(this).addClass('editing');
        });

        $modal.on('blur', '.preview-url-new.editable', function () {
            $(this).removeClass('editing');
            const $this = $(this);
            const originalUrl = $this.data('original-url');
            const currentUrl = $this.text().trim();

            if (currentUrl !== originalUrl) {
                $this.addClass('modified');
            } else {
                $this.removeClass('modified');
            }
        });

        $modal.on('input', '.preview-url-new.editable', function () {
            const $this = $(this);
            const originalUrl = $this.data('original-url');
            const currentUrl = $this.text().trim();

            if (currentUrl !== originalUrl) {
                $this.addClass('modified');
            } else {
                $this.removeClass('modified');
            }
        });

        $modal.on('click', '.reset-url-btn', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $item = $btn.closest('.preview-item-compact');
            const $urlField = $item.find('.preview-url-new.editable');
            const originalUrl = $urlField.data('original-url');

            $urlField.text(originalUrl);
            $urlField.removeClass('modified editing');
        });

        $modal.on('keydown', '.preview-url-new.editable', function (e) {
            if (e.keyCode === 13) {
                e.preventDefault();
                $(this).blur();
            }
        });
    }

    /**
     * Handle category delete button  
     */
    function handleCategoryDelete(category, withSuggestion, internalNoSuggestion, external) {
        let categoryData = [];
        let categoryName = '';

        if (category === 'suggested') {
            categoryData = withSuggestion;
            categoryName = 'Links with Suggestions';
        } else if (category === 'internal-no-suggestion') {
            categoryData = internalNoSuggestion;
            categoryName = 'Internal Links Without Suggestions';
        } else if (category === 'external') {
            categoryData = external;
            categoryName = 'External Links';
        }

        console.log('[CATEGORY DELETE] Category:', category, 'Count:', categoryData.length);

        if (!confirm(`Permanently delete all ${categoryData.length} links in "${categoryName}" from the database?`)) {
            console.log('[CATEGORY DELETE] User cancelled');
            return;
        }

        const linkIds = categoryData.map(l => l.id);
        console.log('[CATEGORY DELETE] Deleting link IDs:', linkIds);

        performBulkDelete(linkIds, true); // Pass true to indicate we should reload after delete

        // Close modal after initiating delete
        $('#fix-all-modal').removeClass('show');
        setTimeout(() => $('#fix-all-modal').remove(), 200);
    }

    /**
     * Handle Fix All confirm button
     */
    function handleFixAllConfirm(withSuggestion, internalNoSuggestion, external, homeUrl) {
        console.log('[FIX ALL CONFIRM] Processing actions');

        const editedURLs = collectEditedURLsFrom('#fix-all-modal');
        const linksToProcess = [];
        const linksToDelete = [];

        // Get visible items (non-deleted by individual delete button)
        $('#fix-all-modal .preview-item-compact:visible').each(function () {
            const linkId = $(this).data('link-id');
            const category = $(this).find('.preview-url-new').data('category');
            const categoryCheckbox = $(`#fix-category-${category}`);

            if (categoryCheckbox.is(':checked')) {
                // This link should be processed (replace action)
                const originalLink = [...withSuggestion, ...internalNoSuggestion, ...external].find(l => l.id == linkId);
                if (originalLink) {
                    const editedUrl = editedURLs[linkId];
                    let newUrl = '';

                    if (editedUrl) {
                        newUrl = editedUrl;
                    } else if (category === 'suggested') {
                        newUrl = originalLink.suggested_url;
                    } else {
                        newUrl = homeUrl;
                    }

                    linksToProcess.push({
                        ...originalLink,
                        replace_action: category === 'suggested' ? 'suggested' : 'home',
                        new_url: newUrl
                    });
                }
            }
        });

        if (linksToProcess.length === 0) {
            alert('No actions selected. Please check at least one category.');
            return;
        }

        $('#fix-all-modal').removeClass('show');
        setTimeout(() => $('#fix-all-modal').remove(), 200);

        performBulkReplaceV2(linksToProcess);
    }

    /**
     * Collect edited URLs from specific modal
     */
    function collectEditedURLsFrom(modalSelector) {
        const editedURLs = {};

        $(`${modalSelector} .preview-item-compact:visible`).each(function () {
            const $item = $(this);
            const linkId = $item.data('link-id');
            const $urlField = $item.find('.preview-url-new.editable');

            if ($urlField.length > 0) {
                const editedUrl = $urlField.text().trim();
                const originalUrl = $urlField.data('original-url');

                if (editedUrl && editedUrl !== originalUrl) {
                    editedURLs[linkId] = editedUrl;
                }
            }
        });

        return editedURLs;
    }

    /**
     * Perform bulk delete
     */
    function performBulkDelete(linkIds, shouldReload = true) {
        console.log('[BULK DELETE] ===== START =====');
        console.log('[BULK DELETE] Deleting', linkIds.length, 'links');
        console.log('[BULK DELETE] Link IDs:', linkIds);
        console.log('[BULK DELETE] Should reload after:', shouldReload);

        if (linkIds.length === 0) {
            alert('No links to delete');
            return;
        }

        showBulkProgressModal('Deleting Links', linkIds.length);

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_bulk_delete',
                nonce: seoautofixBrokenUrls.nonce,
                ids: linkIds
            },
            success: function (response) {
                console.log('[BULK DELETE] Backend response:', response);
                closeBulkProgressModal();

                if (response.success) {
                    const deletedCount = response.data.deleted_count || linkIds.length;
                    console.log('[BULK DELETE] Successfully deleted:', deletedCount, 'links');
                    alert(`Successfully deleted ${deletedCount} link(s) from database`);

                    // Always reload results to show updated state
                    if (currentScanId) {
                        console.log('[BULK DELETE] Reloading results for scan:', currentScanId);
                        loadScanResults(currentScanId);
                    } else {
                        console.warn('[BULK DELETE] No currentScanId, cannot reload results');
                    }
                } else {
                    console.error('[BULK DELETE] Delete failed:', response.data);
                    alert('Error: ' + (response.data.message || 'Failed to delete links'));
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[BULK DELETE] AJAX error:', {
                    status: jqXHR.status,
                    statusText: textStatus,
                    error: errorThrown,
                    responseText: jqXHR.responseText
                });
                closeBulkProgressModal();
                alert('Error deleting links: ' + textStatus);
            }
        });
    }

    /**
     * Apply bulk fix plan
     */
    function applyBulkFixPlan(planId, entryIds, action, actionVerb) {
        console.log('[APPLY BULK FIX] Plan ID:', planId, 'Action:', action);

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_apply_fix_plan',
                nonce: seoautofixBrokenUrls.nonce,
                plan_id: planId,
                selected_entry_ids: entryIds
            },
            success: function (response) {
                console.log('[APPLY BULK FIX] Response:', response);

                closeBulkProgressModal();

                if (response.success) {
                    const data = response.data;
                    alert(
                        `✅ Success!\n\n` +
                        `${actionVerb} ${data.links_fixed || entryIds.length} link(s) on ${data.pages_modified || 0} page(s).\n\n` +
                        `You can undo this action using the "Undo Changes" button if needed.`
                    );

                    // Refresh results table
                    loadScanResults(currentScanId);
                } else {
                    alert(response.data.message || `Failed to ${action} links`);
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[APPLY BULK FIX] Error:', textStatus, errorThrown);
                closeBulkProgressModal();
                alert(`Error applying fixes: ${textStatus}`);
            }
        });
    }

    /**
     * Show bulk progress modal
     */
    function showBulkProgressModal(title, count) {
        $('#bulk-progress-modal').remove();

        const modalHtml = `
            <div id="bulk-progress-modal" class="seoautofix-modal">
                <div class="seoautofix-modal-content" style="width: 500px;">
                    <div class="seoautofix-modal-header">
                        <h2>${title}</h2>
                    </div>
                    <div class="seoautofix-modal-body" style="text-align: center; padding: 40px;">
                        <div class="spinner is-active" style="float: none; margin: 0 auto 20px;"></div>
                        <p style="font-size: 16px; margin-bottom: 10px;">Processing ${count} link(s)...</p>
                        <p style="font-size: 13px; color: #666;">Please wait, this may take a moment.</p>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
        $('#bulk-progress-modal').fadeIn(200);
    }

    /**
     * Close bulk progress modal
     */
    function closeBulkProgressModal() {
        $('#bulk-progress-modal').fadeOut(200, function () {
            $(this).remove();
        });
    }

    /**
     * Validate custom URL via AJAX
     */
    function validateCustomUrl(url) {
        console.log('[URL VALIDATION] Testing URL:', url);

        $.ajax({
            url: seoautofixBrokenUrls.ajax_url,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_test_url',
                nonce: seoautofixBrokenUrls.nonce,
                url: url
            },
            success: function (response) {
                console.log('[URL VALIDATION] Response:', response);

                if (response.success && response.data.is_valid) {
                    showUrlValidationFeedback('valid', response.data.message || 'URL is valid');
                } else {
                    const errorMsg = response.data ? response.data.message : 'URL validation failed';
                    showUrlValidationFeedback('invalid', errorMsg);
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[URL VALIDATION] Error:', textStatus, errorThrown);
                showUrlValidationFeedback('invalid', 'Failed to validate URL');
            }
        });
    }

    /**
     * Show URL validation feedback
     */
    function showUrlValidationFeedback(status, message) {
        const $statusIcon = $('#url-validation-status');
        const $message = $('#url-validation-message');

        // Clear previous classes
        $statusIcon.removeClass('loading valid invalid');
        $message.removeClass('success error');

        if (status === 'loading') {
            $statusIcon.addClass('loading').html('<span class="dashicons dashicons-update"></span>');
            $message.text('Validating URL...').removeClass('success error');
        } else if (status === 'valid') {
            $statusIcon.addClass('valid').html('<span class="dashicons dashicons-yes-alt"></span>');
            $message.text(message).addClass('success');
        } else if (status === 'invalid') {
            $statusIcon.addClass('invalid').html('<span class="dashicons dashicons-dismiss"></span>');
            $message.text(message).addClass('error');
        }
    }

    /**
     * Clear URL validation feedback
     */
    function clearUrlValidationFeedback() {
        $('#url-validation-status').removeClass('loading valid invalid').html('');
        $('#url-validation-message').removeClass('success error').text('');
    }

    /**
     * Update table row's suggested URL display
     */
    function updateTableRowSuggestedUrl(entryId, newUrl) {
        const $row = $(`tr[data-entry-id="${entryId}"]`);
        if ($row.length) {
            const $suggestedUrlCell = $row.find('.suggested-url-editable');
            if ($suggestedUrlCell.length) {
                $suggestedUrlCell.text(newUrl);
                console.log('[UPDATE TABLE] Updated suggested URL for entry', entryId, 'to:', newUrl);
            }
        }
    }

})(jQuery);
