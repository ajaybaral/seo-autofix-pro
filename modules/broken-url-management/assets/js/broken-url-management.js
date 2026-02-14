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
    let isScanInProgress = false; // Track if scan is actively running to disable buttons

    // Track fixed links in current session (for export)
    let fixedLinksSession = [];

    // Track skipped links in current session (UI-only, for undo button state)
    let skippedLinksSession = [];

    // Undo stack for tracking changes
    let undoStack = [];

    // Initialize on document ready
    $(document).ready(function () {
        console.log('=================================================');
        console.log('🔥 BROKEN URL MANAGEMENT JS - VERSION 3.0 - SIMPLIFIED BUTTON LOGIC 🔥');
        console.log('🆕 Timestamp: 2026-02-02 16:05 - FIXED ALERT MESSAGES 🆕');
        console.log('🆕 fixedLinksSession starts at:', fixedLinksSession.length);
        console.log('🆕 skippedLinksSession starts at:', skippedLinksSession.length);
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
        // Set initial button states (all disabled until scan completes)
        $('#fix-all-issues-btn, #remove-broken-links-btn, #replace-broken-links-btn').prop('disabled', true);
        $('#download-report-btn, #email-report-btn, #download-report-empty-btn, #email-report-empty-btn').prop('disabled', true);
        $('#undo-last-fix-btn, #undo-changes-btn').prop('disabled', true);

        // Header action buttons
        $('#start-auto-fix-btn').on('click', startNewScan);
        $('#export-report-btn').on('click', exportToCSV);
        // ✅ REMOVED: Old undo function - now using undoChanges() snapshot system instead
        // $('#undo-last-fix-btn').on('click', undoLastFix);
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

            if (!window.currentFixData) {
                console.error('[SKIP] No currentFixData available');
                $('#auto-fix-panel').hide();
                return;
            }

            const entryId = window.currentFixData.id;
            console.log('[SKIP] Skipping entry ID:', entryId);

            // Get the row
            const $row = $(`tr[data-id="${entryId}"]`);

            if ($row.length) {
                const rowData = $row.data('result');

                if (rowData) {
                    // ✅ ADD TO undoStack so it can be restored
                    undoStack.push({
                        id: entryId,
                        action: 'skip',
                        original_data: rowData,
                        row_html: $row[0] ? $row[0].outerHTML : ''
                    });
                    console.log('[SKIP] Added to undoStack. Stack size:', undoStack.length);

                    // ✅ ADD TO skippedLinksSession for button state management
                    skippedLinksSession.push({
                        id: entryId,
                        broken_url: rowData.broken_url,
                        link_type: rowData.link_type,
                        skipped_at: new Date().toISOString()
                    });
                    console.log('[SKIP] Added to skippedLinksSession. Count:', skippedLinksSession.length);

                    // ✅ Show grey skip animation
                    animateSkippedRow($row, function () {
                        // Update stats after row removed
                        updateStatsFromVisibleRows();
                    });

                    // ✅ Update button states (will enable undo button automatically)
                    updateButtonStates();
                    console.log('[SKIP] Button states updated');
                } else {
                    console.error('[SKIP] No rowData found for entry:', entryId);
                }
            } else {
                console.error('[SKIP] Row not found for entry:', entryId);
            }

            // Hide the panel
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

        // ✅ SET SCAN STATE AND DISABLE ALL ACTION BUTTONS
        isScanInProgress = true;
        setTableButtonsState(false); // Disable Fix/Remove/Replace buttons
        console.log('[SCAN START] Set isScanInProgress = true, disabled all action buttons');

        // Add loading animation class to table
        $('#results-table-body').closest('table').addClass('scan-in-progress');
        console.log('[SCAN START] Added loading animation class to table');

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
     * Process a batch of URLs (Frontend-driven v3.0)
     * NEW: Fetches page URLs from backend, then uses frontend to:
     * - Fetch rendered HTML
     * - Parse with native DOMParser (works with ALL page builders)
     * - Test URLs asynchronously
     * - Save broken links to database
     */
    async function processBatch() {
        console.log('[SCAN V3] processBatch() called for scan_id:', currentScanId);

        try {
            // Step 1: Get next batch of page URLs from backend
            const pageUrlsResponse = await $.ajax({
                url: seoautofixBrokenUrls.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'seoautofix_broken_links_get_page_urls_batch',
                    nonce: seoautofixBrokenUrls.nonce,
                    scan_id: currentScanId,
                    batch_size: 5 // Process 5 pages at a time
                }
            });

            if (!pageUrlsResponse.success) {
                throw new Error(pageUrlsResponse.data.message || 'Failed to get page URLs');
            }

            const data = pageUrlsResponse.data;
            console.log('[SCAN V3] Received page URLs batch:', data);

            // Update progress bar
            const progress = Math.round(data.progress || 0);
            $('#scan-progress-fill').css('width', progress + '%');
            $('#scan-progress-percentage').text(progress + '%');
            $('#scan-progress-text').text('Scanning...');

            // Check if scan is complete
            if (data.completed) {
                console.log('[SCAN V3] Scan completed!');
                $('#scan-progress-text').text(seoautofixBrokenUrls.strings.scanComplete || 'Scan complete!');

                isScanInProgress = false;
                setTableButtonsState(true);
                $('#results-table-body').closest('table').removeClass('scan-in-progress');

                onScanComplete();
                return;
            }

            const pageUrls = data.urls || [];
            if (pageUrls.length === 0) {
                console.log('[SCAN V3] No URLs in batch, continuing...');
                setTimeout(processBatch, 100);
                return;
            }

            console.log('[SCAN V3] Processing ' + pageUrls.length + ' pages...');

            // Step 2: Fetch and parse HTML for each page (in parallel)
            const pageParsingPromises = pageUrls.map(async (pageData) => {
                const pageUrl = pageData.url;
                const pageTitle = pageData.page_title || '';
                const pageId = pageData.page_id || 0;

                console.log('[SCAN V3] Fetching HTML from:', pageUrl);

                try {
                    // Fetch rendered HTML  
                    const html = await fetchPageHTML(pageUrl);

                    // Extract links using native browser DOMParser
                    const links = extractLinksFromHTML(html, pageUrl, pageTitle, pageId);

                    return { pageUrl, pageTitle, pageId, links };
                } catch (error) {
                    console.error('[SCAN V3] Error processing page ' + pageUrl + ':', error);
                    return { pageUrl, pageTitle, pageId, links: [] };
                }
            });

            const pagesWithLinks = await Promise.all(pageParsingPromises);
            console.log('[SCAN V3] ✅ Parsed ' + pagesWithLinks.length + ' pages');

            // Step 3: Collect all unique links
            const allLinks = {};
            let totalLinksFound = 0;

            pagesWithLinks.forEach(({ links }) => {
                links.forEach(link => {
                    if (!allLinks[link.url]) {
                        allLinks[link.url] = {
                            url: link.url,
                            link_type: link.link_type,
                            occurrences: []
                        };
                    }
                    allLinks[link.url].occurrences.push({
                        found_on_url: link.found_on_url,
                        found_on_page_id: link.found_on_page_id,
                        found_on_page_title: link.found_on_page_title,
                        anchor_text: link.anchor_text,
                        location: link.location
                    });
                    totalLinksFound++;
                });
            });

            const uniqueUrls = Object.keys(allLinks);
            console.log('[SCAN V3] Found ' + totalLinksFound + ' total links (' + uniqueUrls.length + ' unique)');

            // Update UI with links found
            $('#scan-urls-tested').text(totalLinksFound);
            $('#scan-urls-total').text(totalLinksFound);

            // Step 4: Test all unique URLs via proxy
            console.log('[SCAN V3] Testing ' + uniqueUrls.length + ' unique URLs...');

            const testResults = await testURLsBatch(uniqueUrls, 10); // Test 10 URLs concurrency

            console.log('[SCAN V3] ✅ Tested ' + testResults.length + ' URLs');

            // Step 5: Identify broken links
            const brokenLinks = [];

            testResults.forEach((result, index) => {
                const url = uniqueUrls[index];
                const linkData = allLinks[url];

                if (result.is_broken) {
                    // Add each occurrence as a separate broken link entry
                    linkData.occurrences.forEach(occurrence => {
                        brokenLinks.push({
                            url: url,
                            status_code: result.status_code,
                            error_type: result.error_type,
                            found_on_url: occurrence.found_on_url,
                            found_on_page_id: occurrence.found_on_page_id,
                            found_on_page_title: occurrence.found_on_page_title,
                            anchor_text: occurrence.anchor_text,
                            link_type: linkData.link_type,
                            location: occurrence.location,
                            suggested_url: result.suggested_url || null
                        });
                    });
                }
            });

            console.log('[SCAN V3] Found ' + brokenLinks.length + ' broken link occurrences');

            // Step 6: Save broken links to database
            if (brokenLinks.length > 0) {
                const savedData = await saveBrokenLinksBatch(currentScanId, brokenLinks);
                console.log('[SCAN V3] ✅ Saved broken links to database');

                // ✅ Use saved data which contains database IDs
                const savedBrokenLinks = savedData.broken_links || brokenLinks;

                // Update UI with broken links count
                $('#scan-broken-count').text(savedBrokenLinks.length);

                // Update 4xx/5xx stats
                const stats4xx = savedBrokenLinks.filter(l => l.error_type === '4xx').length;
                const stats5xx = savedBrokenLinks.filter(l => l.error_type === '5xx').length;
                $('#header-4xx-count').text(stats4xx);
                $('#header-5xx-count').text(stats5xx);

                // Display broken links in real-time (using saved data with IDs!)
                updateDynamicResults(savedBrokenLinks, {
                    total: savedBrokenLinks.length,
                    '4xx': stats4xx,
                    '5xx': stats5xx
                });
            }

            // Step 7: Process next batch
            console.log('[SCAN V3] Batch complete, processing next batch...');
            setTimeout(processBatch, 100);

        } catch (error) {
            console.error('[SCAN V3] ❌ Error in processBatch:', error);
            alert('Error processing batch: ' + error.message);
            resetScanState();
        }
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

            // Update button states even when no results
            updateButtonStates();
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

        // Update button states after displaying results
        updateButtonStates();
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

        // ✅ Create table row with data-id AND attach result data
        const row = $('<tr>')
            .attr('data-id', result.id)
            .data('result', result);  // ✅ CRITICAL FIX: Attach result data to row!

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
        console.log('═══════════════════════════════════════════════════════');
        console.log('🔄 [DYNAMIC UPDATE] FUNCTION CALLED');
        console.log('[DYNAMIC UPDATE] Timestamp:', new Date().toLocaleTimeString());
        console.log('[DYNAMIC UPDATE] Broken links received:', brokenLinks.length);
        console.log('[DYNAMIC UPDATE] Broken links IDs:', brokenLinks.map(l => l.id));
        console.log('[DYNAMIC UPDATE] Stats:', stats);

        // Show table container if hidden (correct selector!)
        const $tableContainer = $('.seoautofix-table-container-new');
        const $filterSection = $('.filter-section');
        const isTableHidden = $tableContainer.is(':hidden');

        console.log('[DYNAMIC UPDATE] 📊 TABLE STATE CHECK:');
        console.log('  - Table container found:', $tableContainer.length);
        console.log('  - Is hidden?', isTableHidden);
        console.log('  - Display CSS:', $tableContainer.css('display'));
        console.log('  - Filter section found:', $filterSection.length);

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

            // ✅ UPDATE 4xx AND 5xx STATS IN REAL-TIME
            if (stats['4xx'] !== undefined) {
                $('#header-4xx-count').text(stats['4xx']);
                console.log('[DYNAMIC UPDATE] Updated 4xx count:', stats['4xx']);
            }
            if (stats['5xx'] !== undefined) {
                $('#header-5xx-count').text(stats['5xx']);
                console.log('[DYNAMIC UPDATE] Updated 5xx count:', stats['5xx']);
            }
            if (stats.internal !== undefined) {
                $('#internal-broken-count, #stat-internal-count, [data-stat="internal"]').text(stats.internal);
                console.log('[DYNAMIC UPDATE] Updated internal count:', stats.internal);
            }
            if (stats.external !== undefined) {
                $('#external-broken-count, #stat-external-count, [data-stat="external"]').text(stats.external);
                console.log('[DYNAMIC UPDATE] Updated external count:', stats.external);
            }

            updateFilterCounts({ stats: stats });
        }

        // Track which links we've already displayed
        if (!window.displayedLinkIds) {
            window.displayedLinkIds = new Set();
        }

        // Add new rows for links we haven't shown yet
        console.log('[DYNAMIC UPDATE] 🔨 ADDING ROWS TO TABLE');
        console.log('  - displayedLinkIds size:', window.displayedLinkIds.size);
        console.log('  - Already displayed IDs:', Array.from(window.displayedLinkIds));

        const $tbody = $('#results-table-body');
        console.log('  - Table tbody found:', $tbody.length);
        console.log('  - Current rows in tbody:', $tbody.find('tr').length);
        let newRowsAdded = 0;

        brokenLinks.forEach((link, index) => {
            console.log(`[DYNAMIC UPDATE] Processing link ${index + 1}/${brokenLinks.length}: ID=${link.id}`);

            if (!window.displayedLinkIds.has(link.id)) {
                console.log(`  ✅ Link ${link.id} is NEW - creating row`);
                const $row = createResultRow(link);
                console.log(`  - Row created:`, $row.length ? 'SUCCESS' : 'FAILED', '(length:', $row.length, ')');

                $tbody.append($row);
                console.log(`  - Row appended to tbody`);

                $row.hide().fadeIn(400);
                console.log(`  - Fade-in animation started`);

                window.displayedLinkIds.add(link.id);
                newRowsAdded++;
                console.log(`  ✅ Link ${link.id} added successfully`);
            } else {
                console.log(`  ⏭️ Link ${link.id} ALREADY displayed, skipping`);
            }
        });

        console.log('[DYNAMIC UPDATE] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        console.log('[DYNAMIC UPDATE] 📊 SUMMARY:');
        console.log('  - New rows added:', newRowsAdded);
        console.log('  - Total rows now:', $tbody.find('tr').length);
        console.log('  - Total tracked IDs:', window.displayedLinkIds.size);
        console.log('[DYNAMIC UPDATE] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (newRowsAdded > 0) {
            console.log('[DYNAMIC UPDATE] ✅ Successfully added', newRowsAdded, 'new rows');

            // ✅ Ensure buttons stay disabled if scan is still in progress
            if (isScanInProgress) {
                setTableButtonsState(false);
                console.log('[DYNAMIC UPDATE] 🔒 Scan in progress - re-disabled buttons');
            }
        } else {
            console.log('[DYNAMIC UPDATE] ⚠️ No new rows added (all links already displayed)');
        }

        console.log('═══════════════════════════════════════════════════════');
        console.log('🔄 [DYNAMIC UPDATE] COMPLETED');
        console.log('═══════════════════════════════════════════════════════');
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
     * Apply selected fixes
     */
    function applySelectedFixes(idsToFix) {
        console.log('========================================');
        console.log('[APPLY SELECTED FIXES] 🔥 FUNCTION CALLED 🔥');
        console.log('[APPLY SELECTED FIXES] Timestamp:', new Date().toISOString());
        console.log('[APPLY SELECTED FIXES] IDs parameter received:', idsToFix);
        console.log('========================================');

        let selectedIds = idsToFix || [];

        // If no IDs provided, get from checkboxes
        if (!selectedIds || selectedIds.length === 0) {
            console.log('[APPLY SELECTED FIXES] No IDs provided, checking for checkboxes');
            $('.result-checkbox:checked').each(function () {
                selectedIds.push($(this).data('id'));
            });
            console.log('[APPLY SELECTED FIXES] IDs from checkboxes:', selectedIds);
        }

        console.log('[APPLY SELECTED FIXES] Final selected IDs:', selectedIds);
        console.log('[APPLY SELECTED FIXES] Total count:', selectedIds.length);

        if (selectedIds.length === 0) {
            console.log('[APPLY SELECTED FIXES] ❌ NO IDS - Showing alert');
            alert('Please select at least one entry to fix');
            return;
        }

        if (!confirm('Are you sure you want to apply fixes for ' + selectedIds.length + ' link(s)?')) {
            console.log('[APPLY SELECTED FIXES] ❌ USER CANCELLED');
            return;
        }

        console.log('[APPLY SELECTED FIXES] ✅ User confirmed, preparing AJAX request');
        console.log('[APPLY SELECTED FIXES] AJAX URL:', seoautofixBrokenUrls.ajaxUrl);
        console.log('[APPLY SELECTED FIXES] Nonce:', seoautofixBrokenUrls.nonce);
        console.log('[APPLY SELECTED FIXES] Action: seoautofix_broken_links_apply_fixes');
        console.log('[APPLY SELECTED FIXES] Sending IDs:', JSON.stringify(selectedIds));

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_apply_fixes',
                nonce: seoautofixBrokenUrls.nonce,
                ids: selectedIds
            },
            beforeSend: function () {
                console.log('[APPLY SELECTED FIXES] 📡 AJAX REQUEST SENT');
            },
            success: function (response) {
                console.log('========================================');
                console.log('[APPLY SELECTED FIXES] 📥 AJAX SUCCESS RESPONSE RECEIVED');
                console.log('[APPLY SELECTED FIXES] Response object:', response);
                console.log('[APPLY SELECTED FIXES] Response.success:', response.success);
                console.log('[APPLY SELECTED FIXES] Response.data:', response.data);
                console.log('========================================');

                if (response.success) {
                    const fixed = response.data.fixed_count || 0;
                    const failed = response.data.failed_count || 0;
                    const skipped = response.data.skipped_count || 0;

                    console.log('[APPLY SELECTED FIXES] ✅ SUCCESS - Fixed:', fixed, 'Failed:', failed, 'Skipped:', skipped);
                    console.log('[APPLY SELECTED FIXES] Messages:', response.data.messages);

                    // Show summary message
                    let message = '✅ Fixed: ' + fixed + '\n❌ Failed: ' + failed;
                    if (skipped > 0) {
                        message += '\n⚠️ Skipped: ' + skipped;
                    }
                    message += '\n\nMessages:\n' + response.data.messages.join('\n');
                    alert(message);

                    // Remove successfully fixed rows dynamically
                    if (fixed > 0) {
                        console.log('[APPLY SELECTED FIXES] Removing fixed rows from table');
                        selectedIds.forEach(function (id) {
                            const $row = $('tr[data-id="' + id + '"]');
                            const rowData = $row.data('result');

                            console.log('[APPLY SELECTED FIXES] Processing row ID:', id, 'Row found:', $row.length > 0);

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

                            // Use new animation function
                            animateFixedRow($row);
                        });

                        console.log('[APPLY SELECTED FIXES] Updating stats after fix');
                        // Update stats dynamically (subtract fixed count)
                        updateStatsAfterFix(fixed);

                        // Update button text to show number of fixed links
                        updateFixedReportButtonText();

                        // Update button states based on new data
                        // updateButtonStates() now handles EVERYTHING based on fixedLinksSession.length
                        updateButtonStates();
                    }

                    // If there were failures, reload to show updated state
                    if (failed > 0) {
                        console.log('[APPLY SELECTED FIXES] Some failures detected, reloading results in 500ms');
                        setTimeout(function () {
                            loadScanResults(currentScanId);
                        }, 500);
                    }
                } else {
                    console.log('[APPLY SELECTED FIXES] ❌ RESPONSE SUCCESS = FALSE');
                    console.log('[APPLY SELECTED FIXES] Error message:', response.data.message);
                    alert(response.data.message || 'Failed to apply fixes');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.log('========================================');
                console.log('[APPLY SELECTED FIXES] ❌ AJAX ERROR');
                console.log('[APPLY SELECTED FIXES] Status:', textStatus);
                console.log('[APPLY SELECTED FIXES] Error:', errorThrown);
                console.log('[APPLY SELECTED FIXES] jqXHR:', jqXHR);
                console.log('[APPLY SELECTED FIXES] Response Text:', jqXHR.responseText);
                console.log('========================================');
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
     * Update stats after undoing a fix
     */
    function updateStatsAfterUndo() {
        // Increase the total count badge
        const totalBadge = $('.filter-btn[data-filter="all"] .count');
        if (totalBadge.length) {
            const currentTotal = parseInt(totalBadge.text()) || 0;
            totalBadge.text(currentTotal + 1);
        }

        // Update header broken count
        const headerCount = $('#header-broken-count');
        if (headerCount.length) {
            const currentCount = parseInt(headerCount.text()) || 0;
            headerCount.text(currentCount + 1);
        }
    }

    /**
     * Update button states based on current data state
     * SIMPLE CENTRALIZED LOGIC - ONE SOURCE OF TRUTH
     */
    function updateButtonStates() {
        console.log('[UPDATE BUTTON STATES] Checking button states...');

        // Count broken links (excluding fixed and empty rows)
        const brokenCount = $('#results-table-body tr')
            .not('.status-fixed, .empty-results-row')
            .length;

        // ✅ Count fixes/deletes (for report buttons)
        const totalActionsCount = fixedLinksSession.length;

        // ✅ Count skipped items (UI-only, for undo button)
        const skippedCount = skippedLinksSession.length;

        const hasScan = currentScanId !== null;

        console.log('[UPDATE BUTTON STATES] fixedLinksSession:', totalActionsCount, 'skippedLinksSession:', skippedCount, 'brokenCount:', brokenCount, 'hasScan:', hasScan);

        // Fix All, Remove, Replace buttons - ACTIVE when there's a scan
        const actionButtons = $('#fix-all-issues-btn, #remove-broken-links-btn, #replace-broken-links-btn');
        actionButtons.prop('disabled', !hasScan);
        console.log('[UPDATE BUTTON STATES] Action buttons disabled:', !hasScan);

        // ✅ Download/Email buttons - ONLY enabled if there are fixes/deletes (not for skips)
        const shouldEnableReportButtons = totalActionsCount > 0;
        const reportButtons = $('#download-report-btn, #email-report-btn, #download-report-empty-btn, #email-report-empty-btn');
        reportButtons.prop('disabled', !shouldEnableReportButtons);
        console.log('[UPDATE BUTTON STATES] Report buttons:', shouldEnableReportButtons ? 'ENABLED' : 'DISABLED', '(fixes/deletes:', totalActionsCount + ')');

        // ✅ Undo button - enabled if there are fixes, deletes, OR skips
        const shouldEnableUndoButton = totalActionsCount > 0 || skippedCount > 0;
        const undoButtons = $('#undo-last-fix-btn, #undo-changes-btn');
        undoButtons.prop('disabled', !shouldEnableUndoButton);
        console.log('[UPDATE BUTTON STATES] Undo buttons:', shouldEnableUndoButton ? 'ENABLED' : 'DISABLED', '(fixes/deletes:', totalActionsCount, 'skipped:', skippedCount + ')');
    }

    /**
     * Update stats dynamically based on visible rows in the table
     * Calculates: total broken links, 4xx count, 5xx count, internal/external counts
     */
    function updateStatsFromVisibleRows() {
        console.log('[UPDATE STATS] Calculating stats from visible rows...');

        // Get all visible rows (excluding empty result rows)
        const $visibleRows = $('#results-table-body tr:visible').not('.empty-results-row');
        const totalBrokenLinks = $visibleRows.length;

        let count4xx = 0;
        let count5xx = 0;
        let countInternal = 0;
        let countExternal = 0;

        // Iterate through visible rows and count by type
        $visibleRows.each(function () {
            const rowData = $(this).data('result');

            if (rowData) {
                // Count by status code
                const statusCode = parseInt(rowData.status_code);
                if (statusCode >= 400 && statusCode < 500) {
                    count4xx++;
                } else if (statusCode >= 500) {
                    count5xx++;
                }

                // Count by link type
                if (rowData.link_type === 'internal') {
                    countInternal++;
                } else if (rowData.link_type === 'external') {
                    countExternal++;
                }
            }
        });

        // Update header stats
        $('#header-broken-count').text(totalBrokenLinks);
        $('#header-4xx-count').text(count4xx);
        $('#header-5xx-count').text(count5xx);

        console.log('[UPDATE STATS] Updated stats:', {
            total: totalBrokenLinks,
            '4xx': count4xx,
            '5xx': count5xx,
            internal: countInternal,
            external: countExternal
        });
    }


    /**
     * Enable or disable all table action buttons (Fix, Remove, Replace)
     * Used to lock buttons during scan to prevent conflicts
     */
    function setTableButtonsState(enabled) {
        console.log('[BUTTON LOCK] Setting table buttons to:', enabled ? 'ENABLED' : 'DISABLED');

        // Individual Fix buttons in each table row
        $('.fix-entry-btn').prop('disabled', !enabled);

        // Bulk action buttons at the bottom
        $('#fix-all-issues-btn').prop('disabled', !enabled);
        $('#remove-broken-links-btn').prop('disabled', !enabled);
        $('#replace-broken-links-btn').prop('disabled', !enabled);

        // Visual feedback - add/remove 'disabled' class for styling
        if (enabled) {
            $('.fix-entry-btn, #fix-all-issues-btn, #remove-broken-links-btn, #replace-broken-links-btn').removeClass('button-locked');
        } else {
            $('.fix-entry-btn, #fix-all-issues-btn, #remove-broken-links-btn, #replace-broken-links-btn').addClass('button-locked');
        }

        console.log('[BUTTON LOCK] All table action buttons are now', enabled ? 'ENABLED' : 'ENABLED');
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
    // ✅ DEPRECATED - OLD UNDO FUNCTION (undoStack-based)
    // This function is NO LONGER USED - replaced by undoChanges() which uses backend snapshots
    // Keeping code commented for reference only
    /*
    function undoLastFix() {
        // ... old code removed ...
    }
    */

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

        if (!confirm('Fix ' + brokenLinks.length + ' broken link(s)? This will use suggested URLs where available.')) {
            return;
        }

        // Disable button during processing
        $('#fix-all-issues-btn').prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Fixing...');

        // Extract IDs for the AJAX call
        const linkIds = brokenLinks.map(link => link.id);

        console.log('[FIX ALL] Fixing', linkIds.length, 'links with IDs:', linkIds);

        // Apply fixes in batch
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_apply_fixes',
                nonce: seoautofixBrokenUrls.nonce,
                ids: linkIds // Correct format: array of IDs
            },
            success: function (response) {
                console.log('[FIX ALL] Response:', response);

                if (response.success) {
                    const fixed = response.data.fixed_count || 0;
                    const failed = response.data.failed_count || 0;
                    const skipped = response.data.skipped_count || 0;

                    // Remove successfully fixed rows
                    if (fixed > 0) {
                        brokenLinks.forEach(function (link) {
                            const $row = $('[data-id="' + link.id + '"]');

                            // Push to undo stack before removing
                            undoStack.push({
                                id: link.id,
                                action: 'fix',
                                original_data: link,
                                row_html: $row[0].outerHTML
                            });

                            // Add to fixed links session for export
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

                            // Use new animation function
                            animateFixedRow($row);
                        });

                        // Update stats and buttons
                        updateStatsAfterFix(fixed);
                        updateFixedReportButtonText();
                        updateButtonStates();

                        // EXPLICITLY enable undo buttons
                        console.log('[FIX ALL] Undo stack length:', undoStack.length);
                        $('#undo-last-fix-btn, #undo-changes-btn').prop('disabled', false);
                        console.log('[FIX ALL] Undo buttons explicitly enabled');
                    }

                    // Show summary
                    let message = '✅ Fixed: ' + fixed + '\n❌ Failed: ' + failed;
                    if (skipped > 0) {
                        message += '\n⚠️ Skipped: ' + skipped;
                    }
                    if (response.data.messages && response.data.messages.length > 0) {
                        message += '\n\nDetails:\n' + response.data.messages.join('\n');
                    }
                    alert(message);

                    // Reset button
                    $('#fix-all-issues-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Fix All Issues');
                } else {
                    alert('Error: ' + (response.data.message || 'Failed to fix links'));
                    $('#fix-all-issues-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Fix All Issues');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[FIX ALL] Error:', textStatus, errorThrown);
                alert('An error occurred while fixing links. Please try again.');
                $('#fix-all-issues-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Fix All Issues');
            }
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
     * Escape HTML special characters
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }
        text = String(text); // Convert to string if not already
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    /**
     * Animate fixed row - NEW IMPLEMENTATION
     * Shows green background with "Fixed" text, waits 2.5 seconds, then fades out and removes
     */
    function animateFixedRow($row, onComplete) {
        console.log('[ANIMATE FIXED ROW] Starting animation for row:', $row.attr('data-id'));

        // Step 1: Add green background class
        $row.addClass('row-being-fixed');

        // Step 2: Find the action column and replace with "Fixed" label
        const $actionCell = $row.find('.column-action');
        if ($actionCell.length) {
            $actionCell.html('<span class="fixed-status-label">Fixed</span>');
        }

        // Step 3: Wait 2.5 seconds, then fade out and remove
        setTimeout(function () {
            console.log('[ANIMATE FIXED ROW] Starting fade out for row:', $row.attr('data-id'));
            $row.fadeOut(400, function () {
                console.log('[ANIMATE FIXED ROW] Removing row:', $row.attr('data-id'));
                $(this).remove();

                // Check if table is empty
                if ($('#results-table-body tr').length === 0 ||
                    $('#results-table-body tr:visible').length === 0) {
                    $('#results-table-body').html(
                        '<tr class="empty-results-row"><td colspan="5" style="text-align:center; padding: 30px;">No broken links found</td></tr>'
                    );
                }

                // Call completion callback if provided
                if (onComplete && typeof onComplete === 'function') {
                    onComplete();
                }
            });
        }, 2500); // 2.5 seconds delay
    }

    /**
     * Animate deleted row - RED VERSION for delete operations
     * Shows red background with "Deleted" text, waits 2.5 seconds, then fades out and removes
     */
    function animateDeletedRow($row, onComplete) {
        console.log('[ANIMATE DELETED ROW] Starting animation for row:', $row.attr('data-id'));

        // Step 1: Add red background class
        $row.addClass('row-being-deleted');

        // Step 2: Find the action column and replace with "Deleted" label
        const $actionCell = $row.find('.column-action');
        if ($actionCell.length) {
            $actionCell.html('<span class="deleted-status-label">Deleted</span>');
        }

        // Step 3: Wait 2.5 seconds, then fade out and remove
        setTimeout(function () {
            console.log('[ANIMATE DELETED ROW] Starting fade out for row:', $row.attr('data-id'));
            $row.fadeOut(400, function () {
                console.log('[ANIMATE DELETED ROW] Removing row:', $row.attr('data-id'));
                $(this).remove();

                // Check if table is empty
                if ($('#results-table-body tr').length === 0 ||
                    $('#results-table-body tr:visible').length === 0) {
                    $('#results-table-body').html(
                        '<tr class="empty-results-row"><td colspan="5" style="text-align:center; padding: 30px;">No broken links found</td></tr>'
                    );
                }

                // Call completion callback if provided
                if (onComplete && typeof onComplete === 'function') {
                    onComplete();
                }
            });
        }, 2500); // 2.5 seconds delay
    }

    /**
     * Animate skipped row - GREY VERSION for skip operations
     * Shows grey background with "Skipped" text, waits 2.5 seconds, then fades out and removes
     */
    function animateSkippedRow($row, onComplete) {
        console.log('[ANIMATE SKIPPED ROW] Starting animation for row:', $row.attr('data-id'));

        // Step 1: Add grey background class
        $row.addClass('row-being-skipped');

        // Step 2: Find the action column and replace with "Skipped" label
        const $actionCell = $row.find('.column-action');
        if ($actionCell.length) {
            $actionCell.html('<span class="skipped-status-label">Skipped</span>');
        }

        // Step 3: Wait 2.5 seconds, then fade out and remove
        setTimeout(function () {
            console.log('[ANIMATE SKIPPED ROW] Starting fade out for row:', $row.attr('data-id'));
            $row.fadeOut(400, function () {
                console.log('[ANIMATE SKIPPED ROW] Removing row:', $row.attr('data-id'));
                $(this).remove();

                // Check if table is empty
                if ($('#results-table-body tr').length === 0 ||
                    $('#results-table-body tr:visible').length === 0) {
                    $('#results-table-body').html(
                        '<tr class="empty-results-row"><td colspan="5" style="text-align:center; padding: 30px;">No broken links found</td></tr>'
                    );
                }

                // Call completion callback if provided
                if (onComplete && typeof onComplete === 'function') {
                    onComplete();
                }
            });
        }, 2500); // 2.5 seconds delay
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
        // Get data from parent row, not from button
        const $row = $(this).closest('tr');
        const resultData = $row.data('result');
        console.log('[FIX BTN] Clicked');
        console.log('[FIX BTN] Row:', $row);
        console.log('[FIX BTN] Result data:', resultData);

        if (!resultData) {
            console.error('[FIX BTN] No result data found on row!');
            alert('Error: Could not find link data. Please refresh the page.');
            return;
        }

        showAutoFixPanel(resultData);
    });

    /**
     * Show auto-fix panel with link data
     */
    function showAutoFixPanel(result) {
        console.log('╔════════════════════════════════════════════════════════╗');
        console.log('║       SHOW AUTO FIX PANEL CALLED                       ║');
        console.log('╚════════════════════════════════════════════════════════╝');
        console.log('[AUTO FIX PANEL] Result parameter received:', result);
        console.log('[AUTO FIX PANEL] Result type:', typeof result);
        console.log('[AUTO FIX PANEL] Result is null?', result === null);
        console.log('[AUTO FIX PANEL] Result is undefined?', result === undefined);

        if (!result) {
            console.error('[AUTO FIX PANEL] ❌ ERROR: No result data provided!');
            alert('Error: No data available for this entry');
            return;
        }

        console.log('[AUTO FIX PANEL] ✅ Result data exists');
        console.log('[AUTO FIX PANEL] Result.id:', result.id);
        console.log('[AUTO FIX PANEL] Result.found_on_page_title:', result.found_on_page_title);
        console.log('[AUTO FIX PANEL] Result.found_on_url:', result.found_on_url);
        console.log('[AUTO FIX PANEL] Result.broken_url:', result.broken_url);

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
                    // NOTE: Do NOT enable undo button here - it should only be enabled AFTER fixes are applied
                    // The button will be enabled by applySelectedFixes() or fixAllIssues() when they make changes
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
        $('#undo-changes-btn').prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Undoing...');
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

                    // ✅ ALWAYS CLEAR fixedLinksSession when undo succeeds
                    // Content is restored, so fixed links are no longer fixed!
                    console.log('[UNDO] Clearing fixedLinksSession - was:', fixedLinksSession.length);
                    fixedLinksSession = [];
                    console.log('[UNDO] fixedLinksSession cleared to:', fixedLinksSession.length);

                    // ✅ CLEAR skippedLinksSession as well
                    console.log('[UNDO] Clearing skippedLinksSession - was:', skippedLinksSession.length);
                    skippedLinksSession = [];
                    console.log('[UNDO] skippedLinksSession cleared to:', skippedLinksSession.length);

                    // Show detailed success message
                    const activityDeleted = response.data.activity_deleted || 0;
                    let message = response.data.message || 'Changes undone successfully!';

                    if (activityDeleted > 0) {
                        message += `\n\n✓ ${activityDeleted} fix/delete action(s) removed from history`;
                        message += '\n✓ "Download Fixed Report" is now empty';
                    }

                    showNotification(message, 'success');

                    // ✅ Restore rows from undoStack
                    // Backend has restored page content, now restore UI rows
                    console.log('[UNDO] Restoring', undoStack.length, 'rows from undoStack');

                    if (undoStack.length > 0) {
                        const $tbody = $('#results-table-body');
                        const $emptyRow = $tbody.find('.empty-results-row');

                        // Remove "No broken links found" message if present
                        if ($emptyRow.length) {
                            $emptyRow.remove();
                            console.log('[UNDO] Removed empty results message');
                        }

                        // Restore each deleted/fixed/skipped row
                        undoStack.forEach(function (item) {
                            // ✅ FIX: Restore rows for 'fix', 'delete', AND 'skip' actions
                            if ((item.action === 'delete' || item.action === 'fix' || item.action === 'skip') && item.row_html && item.original_data) {
                                console.log('[UNDO] Restoring row:', item.id, 'Action:', item.action);

                                // Create row from stored HTML
                                const $restoredRow = $(item.row_html);

                                // Re-attach the data object
                                $restoredRow.data('result', item.original_data);

                                // Append to table
                                $tbody.append($restoredRow);

                                // Fade in animation
                                $restoredRow.hide().fadeIn(400);

                                console.log('[UNDO] Row restored:', item.id);
                            }
                        });

                        // Update stats
                        const restoredCount = undoStack.length;
                        const count4xx = undoStack.filter(item => item.original_data && item.original_data.status_code >= 400 && item.original_data.status_code < 500).length;
                        const count5xx = undoStack.filter(item => item.original_data && item.original_data.status_code >= 500).length;
                        const countInternal = undoStack.filter(item => item.original_data && item.original_data.link_type === 'internal').length;
                        const countExternal = undoStack.filter(item => item.original_data && item.original_data.link_type === 'external').length;

                        // Update header count
                        $('.broken-count').text(restoredCount);

                        // Update stats cards if they exist
                        $('#stat-4xx-count').text(count4xx);
                        $('#stat-5xx-count').text(count5xx);
                        $('#stat-internal-count').text(countInternal);
                        $('#stat-external-count').text(countExternal);

                        console.log('[UNDO] Stats updated - restored', restoredCount, 'broken links');
                    }

                    // Clear undo stack
                    console.log('[UNDO] Clearing undoStack:', undoStack.length, 'items');
                    undoStack = [];

                    // Update button states
                    updateButtonStates();

                    // Update stats from restored rows
                    updateStatsFromVisibleRows();

                    // Restore button text (but keep disabled state from updateButtonStates)
                    $('#undo-changes-btn').html('<span class="dashicons dashicons-undo"></span> Undo Changes');

                    console.log('[UNDO] ✅ Undo complete - rows restored');

                } else {
                    console.error('[UNDO] Undo failed:', response.data.message);
                    showNotification(response.data.message || 'Failed to undo changes', 'error');
                    $('#undo-changes-btn').prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> Undo Changes');
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
                $('#undo-changes-btn').prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> Undo Changes');
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
                        // ✅ ADD TO fixedLinksSession - CRITICAL FOR BUTTON STATES!
                        const $row = $(`tr[data-id="${entryId}"]`);
                        console.log('[APPLY FIX] 🔍 DEBUG: $row.length:', $row.length);

                        if ($row.length) {
                            const rowData = $row.data('result');
                            console.log('[APPLY FIX] 🔍 DEBUG: rowData:', rowData);
                            console.log('[APPLY FIX] 🔍 DEBUG: rowData type:', typeof rowData);

                            if (rowData) {
                                // ✅ ADD TO undoStack BEFORE animating row removal
                                undoStack.push({
                                    id: entryId,
                                    action: 'fix',
                                    original_data: rowData,
                                    row_html: $row[0] ? $row[0].outerHTML : ''
                                });
                                console.log('[APPLY FIX] ✅ Added to undoStack. Stack size:', undoStack.length);

                                fixedLinksSession.push({
                                    id: entryId,
                                    location: rowData.found_on_page_title || rowData.page_title || 'Unknown',
                                    anchor_text: rowData.anchor_text || '',
                                    broken_url: rowData.broken_url || '',
                                    link_type: rowData.link_type || 'unknown',
                                    status_code: rowData.status_code || '',
                                    error_type: rowData.error_type || '',
                                    suggested_url: customUrl,
                                    reason: 'Individual fix',
                                    is_fixed: 1
                                });
                                console.log('[APPLY FIX] ✅ Added to fixedLinksSession. Count now:', fixedLinksSession.length);
                            } else {
                                console.error('[APPLY FIX] ❌ rowData is NULL/UNDEFINED - cannot add to fixedLinksSession!');
                                // FALLBACK: Add minimal data using window.currentFixData
                                if (window.currentFixData) {
                                    console.log('[APPLY FIX] 🔄 Using window.currentFixData as fallback');
                                    fixedLinksSession.push({
                                        id: entryId,
                                        location: window.currentFixData.found_on_page_title || 'Unknown',
                                        anchor_text: window.currentFixData.anchor_text || '',
                                        broken_url: window.currentFixData.broken_url || '',
                                        link_type: window.currentFixData.link_type || 'unknown',
                                        status_code: window.currentFixData.status_code || '',
                                        error_type: window.currentFixData.error_type || '',
                                        suggested_url: customUrl,
                                        reason: 'Individual fix',
                                        is_fixed: 1
                                    });
                                    console.log('[APPLY FIX] ✅ Added to fixedLinksSession (fallback). Count now:', fixedLinksSession.length);
                                }
                            }

                            // Use proper green background animation
                            console.log('[APPLY FIX] Calling animateFixedRow for entry:', entryId);
                            animateFixedRow($row, function () {
                                // Update stats after row removed
                                updateStatsFromVisibleRows();
                            });
                        } else {
                            console.error('[APPLY FIX] ❌ Row not found for entry:', entryId);
                        }

                        // Enable undo button since a fix was successfully applied
                        console.log('[APPLY FIX] Enabling undo button - fixed count:', fixed);
                        $('#undo-changes-btn').prop('disabled', false);

                        // ✅ Update button states to enable Download/Email buttons
                        updateButtonStates();
                        console.log('[APPLY FIX] Button states updated');
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

                    // ✅ TRACK DELETION IN UNDO STACK
                    const $row = $(`tr[data-id="${entryId}"]`);
                    const rowData = $row.data('result');

                    undoStack.push({
                        id: entryId,
                        action: 'delete',
                        original_data: rowData,
                        row_html: $row[0] ? $row[0].outerHTML : ''
                    });
                    console.log('[DELETE] Added to undoStack. Stack size:', undoStack.length);

                    // ✅ TRACK DELETION IN FIXED LINKS SESSION
                    if (rowData) {
                        fixedLinksSession.push({
                            location: rowData.location || 'content',
                            anchor_text: rowData.anchor_text || '',
                            broken_url: rowData.broken_url,
                            link_type: rowData.link_type,
                            status_code: rowData.status_code,
                            error_type: rowData.status_code,
                            suggested_url: '',
                            reason: 'Link deleted by user',
                            is_fixed: 'Deleted',
                            fixed_at: new Date().toISOString()
                        });
                        console.log('[DELETE] Added to fixedLinksSession. Session size:', fixedLinksSession.length);
                    }

                    // Hide the panel
                    $('#auto-fix-panel').slideUp();

                    // ✅ Use proper DELETE animation (red version)
                    if ($row.length) {
                        console.log('[DELETE] Showing DELETED animation for entry:', entryId);

                        animateDeletedRow($row, function () {
                            // Update header count
                            updateHeaderBrokenCount();

                            // Update stats after row removed
                            updateStatsAfterFix(1);

                            // ✅ UPDATE STATS CARDS (4xx, 5xx, internal, external)
                            if (rowData) {
                                // Reduce 4xx or 5xx count
                                if (rowData.status_code >= 400 && rowData.status_code < 500) {
                                    const current4xx = parseInt($('#header-4xx-count').text()) || 0;
                                    $('#header-4xx-count').text(Math.max(0, current4xx - 1));
                                } else if (rowData.status_code >= 500) {
                                    const current5xx = parseInt($('#header-5xx-count').text()) || 0;
                                    $('#header-5xx-count').text(Math.max(0, current5xx - 1));
                                }

                                // Reduce internal or external count
                                if (rowData.link_type === 'internal') {
                                    const currentInternal = parseInt($('#stat-internal-count').text()) || 0;
                                    $('#stat-internal-count, #internal-broken-count, [data-stat="internal"]').text(Math.max(0, currentInternal - 1));
                                } else if (rowData.link_type === 'external') {
                                    const currentExternal = parseInt($('#stat-external-count').text()) || 0;
                                    $('#stat-external-count, #external-broken-count, [data-stat="external"]').text(Math.max(0, currentExternal - 1));
                                }

                                console.log('[DELETE] Updated stats cards for deleted link');
                            }

                            // ✅ UPDATE BUTTON STATES (enables undo button!)
                            updateButtonStates();
                            console.log('[DELETE] ✅ Button states updated. Undo should now be ENABLED');
                        });
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
                                // The response structure is: applyResponse.data = { fixed_count, failed_count, removed_count, messages, etc. }
                                const successCount = applyResponse.data.fixed_count || 0;
                                const failedCount = applyResponse.data.failed_count || 0;
                                const removedCount = applyResponse.data.removed_count || 0;
                                const messages = applyResponse.data.messages || [];

                                console.log('[BULK REPLACE V2] Apply results:', {
                                    fixed: successCount,
                                    failed: failedCount,
                                    removed: removedCount,
                                    messages: messages,
                                    fullResponse: applyResponse.data
                                });

                                // Build success message - show total links processed
                                const totalProcessed = successCount + removedCount;
                                let message = '';

                                if (totalProcessed > 0) {
                                    message = `✅ Successfully processed ${totalProcessed} broken link(s) across ${applyResponse.data.total_pages || 0} page(s).`;

                                    // Show breakdown if:
                                    // 1. There were both fixes AND removals (mixed actions)
                                    // 2. There were ONLY removals (user deleted links)
                                    // Skip breakdown only if there were ONLY replacements (cleaner message)
                                    if (removedCount > 0) {
                                        // Always show breakdown when links were removed
                                        message += `\n\n`;
                                        if (successCount > 0) {
                                            message += `• ${successCount} link(s) replaced with new URLs\n`;
                                        }
                                        message += `• ${removedCount} link(s) removed (deleted)`;
                                    }
                                }

                                if (failedCount > 0) {
                                    message += `\n\n⚠️ ${failedCount} link(s) failed to process.`;
                                }

                                // Show detailed messages if available
                                if (messages.length > 0) {
                                    message += '\n\nDetails:\n' + messages.join('\n');
                                }

                                if (message) {
                                    alert(message);
                                }

                                // Dynamically update the table instead of reloading
                                if (successCount > 0 || removedCount > 0) {
                                    console.log('[BULK REPLACE V2] Updating table dynamically');

                                    // Get the entry IDs that were ACTUALLY fixed from the backend response
                                    const fixedIds = applyResponse.data.fixed_entry_ids || [];
                                    console.log('[BULK REPLACE V2] Backend confirmed fixed IDs:', fixedIds);
                                    console.log('[BULK REPLACE V2] Total IDs sent:', links.map(l => l.id));

                                    if (fixedIds.length === 0) {
                                        console.warn('[BULK REPLACE V2] No fixed_entry_ids in response, cannot update table');
                                        return;
                                    }

                                    // ✅ POPULATE fixedLinksSession AND undoStack
                                    console.log('[BULK REPLACE V2] Adding', fixedIds.length, 'links to fixedLinksSession and undoStack');
                                    fixedIds.forEach(id => {
                                        const $row = $(`tr[data-id="${id}"]`);
                                        // Find the original link data from the links array
                                        const linkData = links.find(l => l.id == id);
                                        if (linkData) {
                                            // Add to fixedLinksSession for reports
                                            fixedLinksSession.push({
                                                id: linkData.id,
                                                location: linkData.found_on_page_title || linkData.page_title,
                                                anchor_text: linkData.anchor_text,
                                                broken_url: linkData.broken_url,
                                                link_type: linkData.link_type,
                                                status_code: linkData.status_code,
                                                error_type: linkData.error_type,
                                                suggested_url: linkData.suggested_url || linkData.new_url,
                                                reason: linkData.reason || 'Bulk action',
                                                is_fixed: 1
                                            });

                                            // ✅ Add to undoStack for visual row restoration
                                            if ($row.length) {
                                                undoStack.push({
                                                    id: id,
                                                    action: 'fix',
                                                    original_data: linkData,
                                                    row_html: $row[0] ? $row[0].outerHTML : ''
                                                });
                                            }
                                        }
                                    });
                                    console.log('[BULK REPLACE V2] fixedLinksSession now has', fixedLinksSession.length, 'entries');
                                    console.log('[BULK REPLACE V2] undoStack now has', undoStack.length, 'entries');

                                    // Remove only the rows that were actually fixed
                                    let removedRowsCount = 0;
                                    fixedIds.forEach(id => {
                                        const $row = $(`tr[data-id="${id}"]`);
                                        if ($row.length) {
                                            console.log('[BULK REPLACE V2] Animating and removing row for fixed ID:', id);
                                            // ✅ USE PROPER GREEN ANIMATION instead of simple fadeOut
                                            animateFixedRow($row, function () {
                                                removedRowsCount++;

                                                // Check if table is now empty (after last row is removed)
                                                if (removedRowsCount === fixedIds.length) {
                                                    const remainingRows = $('#broken-links-table tbody tr:visible').length;
                                                    console.log('[BULK REPLACE V2] All fixed rows removed. Remaining rows:', remainingRows);

                                                    if (remainingRows === 0) {
                                                        console.log('[BULK REPLACE V2] No more broken links - showing empty state');
                                                        $('#broken-links-table tbody').html(
                                                            '<tr><td colspan="7" style="text-align: center; padding: 40px;">' +
                                                            '<p style="font-size: 16px; color: #10b981; margin: 0;">✅ All broken links have been fixed!</p>' +
                                                            '<p style="font-size: 14px; color: #6b7280; margin-top: 10px;">Run a new scan to check for more issues.</p>' +
                                                            '</td></tr>'
                                                        );

                                                        // Update stats to show 0
                                                        $('.stat-value').text('0');

                                                        // Update all button states (handled by centralized function)
                                                        updateButtonStates();
                                                        updateStatsFromVisibleRows();
                                                    } else {
                                                        // ✅ Update stats dynamically from visible rows
                                                        console.log('[BULK REPLACE V2] Updating stats from visible rows');
                                                        updateStatsFromVisibleRows();

                                                        // Update all button states (handled by centralized function)
                                                        updateButtonStates();
                                                    }
                                                }
                                            });
                                        } else {
                                            console.warn('[BULK REPLACE V2] Row not found for ID:', id);
                                        }
                                    });

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

                        // ✅ TRACK DELETION BEFORE removing row
                        const $row = $(`tr[data-id="${linkId}"]`);
                        if ($row.length) {
                            const rowData = $row.data('result');

                            // ✅ Add to undoStack
                            if (rowData) {
                                undoStack.push({
                                    id: linkId,
                                    action: 'delete',
                                    original_data: rowData,
                                    row_html: $row[0] ? $row[0].outerHTML : ''
                                });
                                console.log('[FIX ALL - INDIVIDUAL DELETE] Added to undoStack. Stack size:', undoStack.length);

                                // ✅ Add to fixedLinksSession
                                fixedLinksSession.push({
                                    id: linkId,
                                    location: rowData.found_on_page_title || 'Unknown',
                                    anchor_text: rowData.anchor_text || '',
                                    broken_url: rowData.broken_url,
                                    link_type: rowData.link_type,
                                    status_code: rowData.status_code,
                                    error_type: rowData.error_type,
                                    suggested_url: '',
                                    reason: 'Deleted',
                                    is_fixed: 1,
                                    fixed_at: new Date().toISOString()
                                });
                                console.log('[FIX ALL - INDIVIDUAL DELETE] Added to fixedLinksSession. Session size:', fixedLinksSession.length);
                            }

                            // ✅ Use proper DELETE animation (red version)
                            animateDeletedRow($row, function () {
                                // Update header count
                                updateHeaderBrokenCount();

                                // ✅ Update button states (enables undo/download/email buttons)
                                updateButtonStates();
                                console.log('[FIX ALL - INDIVIDUAL DELETE] Button states updated');
                            });
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
                    const successCount = data.fixed_count || data.removed_count || 0;

                    alert(
                        `✅ Success!\n\n` +
                        `${actionVerb} ${data.links_fixed || entryIds.length} link(s) on ${data.pages_modified || 0} page(s).\n\n` +
                        `You can undo this action using the "Undo Changes" button if needed.`
                    );

                    // ✅ Process fixed links
                    if (successCount > 0) {
                        console.log('[APPLY BULK FIX] Processing', successCount, 'fixed links - action:', action);

                        // Get the entry IDs that were fixed/removed
                        const processedIds = data.fixed_entry_ids || entryIds;
                        console.log('[APPLY BULK FIX] Processed IDs:', processedIds);

                        // ✅ POPULATE fixedLinksSession AND undoStack
                        // Extract link data from table rows before removing them
                        console.log('[APPLY BULK FIX] Adding', processedIds.length, 'links to fixedLinksSession and undoStack');
                        processedIds.forEach(id => {
                            const $row = $(`tr[data-id="${id}"]`);
                            if ($row.length) {
                                // ✅ USE $row.data('result') to get proper data - NOT table cells!
                                const rowData = $row.data('result');
                                console.log('[APPLY BULK FIX] 🔍 DEBUG: rowData for ID', id, ':', rowData);

                                if (rowData) {
                                    // Add to fixedLinksSession for reports
                                    fixedLinksSession.push({
                                        id: id,
                                        location: rowData.found_on_page_title || rowData.page_title || 'Unknown',
                                        anchor_text: rowData.anchor_text || '',
                                        broken_url: rowData.broken_url || '',
                                        link_type: rowData.link_type || 'unknown',
                                        status_code: rowData.status_code || '',
                                        error_type: rowData.error_type || '',
                                        suggested_url: rowData.suggested_url || '',
                                        reason: action === 'delete' ? 'Removed' : 'Fixed',
                                        is_fixed: 1
                                    });
                                    console.log('[APPLY BULK FIX] ✅ Added ID', id, 'to fixedLinksSession');

                                    // ✅ Add to undoStack so undo can restore the row
                                    undoStack.push({
                                        id: id,
                                        action: action, // 'delete' or 'fix'
                                        original_data: rowData,
                                        row_html: $row[0] ? $row[0].outerHTML : ''
                                    });
                                    console.log('[APPLY BULK FIX] ✅ Added ID', id, 'to undoStack');
                                } else {
                                    console.error('[APPLY BULK FIX] ❌ No rowData for ID', id, '- cannot add to sessions');
                                }
                            } else {
                                console.error('[APPLY BULK FIX] ❌ Row not found for ID', id);
                            }
                        });
                        console.log('[APPLY BULK FIX] fixedLinksSession now has', fixedLinksSession.length, 'entries');
                        console.log('[APPLY BULK FIX] undoStack now has', undoStack.length, 'entries');

                        // ✅ ANIMATE rows individually - Use DELETE animation for delete action
                        let removedCount = 0;
                        processedIds.forEach(id => {
                            const $row = $(`tr[data-id="${id}"]`);
                            if ($row.length) {
                                console.log('[APPLY BULK FIX] Animating row:', id, '- Action:', action);
                                // ✅ Use correct animation based on action type
                                const animationFn = action === 'delete' ? animateDeletedRow : animateFixedRow;
                                animationFn($row, function () {
                                    removedCount++;

                                    // Check if all rows removed
                                    if (removedCount === processedIds.length) {
                                        const remainingRows = $('#broken-links-table tbody tr:visible').length;
                                        console.log('[APPLY BULK FIX] All rows processed. Remaining:', remainingRows);

                                        if (remainingRows === 0) {
                                            // Show empty state
                                            $('#broken-links-table tbody').html(
                                                '<tr><td colspan="7" style="text-align: center; padding: 40px;">' +
                                                '<p style="font-size: 16px; color: #10b981; margin: 0;">✅ All broken links have been fixed!</p>' +
                                                '<p style="font-size: 14px; color: #6b7280; margin-top: 10px;">Run a new scan to check for more issues.</p>' +
                                                '</td></tr>'
                                            );
                                            $('.stat-value').text('0');
                                            updateButtonStates();
                                            updateStatsFromVisibleRows();
                                        } else {
                                            // ✅ Update stats dynamically from visible rows
                                            updateStatsFromVisibleRows();
                                            updateButtonStates();
                                        }
                                    }
                                });
                            }
                        });
                    } else {
                        // Fallback: Refresh if no processed IDs
                        loadScanResults(currentScanId);
                    }
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

    /**
     * Create snapshot of scan results for undo functionality
     * Called when scan completes successfully
     */
    function createSnapshot(scanId) {
        console.log('[SKU] [SNAPSHOT] Creating snapshot for scan:', scanId);

        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'POST',
            data: {
                action: 'seoautofix_broken_links_create_snapshot',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: scanId
            },
            success: function (response) {
                console.log('[SKU] [SNAPSHOT] Response:', response);

                if (response.success) {
                    console.log('[SKU] [SNAPSHOT] ✅ Snapshot created successfully:', response.data.snapshot_count, 'pages');
                } else {
                    console.error('[SKU] [SNAPSHOT] ❌ Failed to create snapshot:', response.data.message);
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('[SKU] [SNAPSHOT] ❌ AJAX error creating snapshot:', {
                    status: jqXHR.status,
                    statusText: textStatus,
                    error: errorThrown,
                    response: jqXHR.responseText
                });
            }
        });
    }

    // ========================================
    // ASYNC URL TESTING (Frontend with Promises)
    // ========================================

    /**
     * Test a single internal URL using fetch API (async)
     * Internal URLs can be tested directly from the frontend
     * 
     * @param {string} url URL to test
     * @returns {Promise} Promise resolving to test result
     */
    async function testInternalUrlAsync(url) {
        console.log('[ASYNC URL TEST] Testing internal URL:', url);

        try {
            const response = await fetch(url, {
                method: 'HEAD',
                cache: 'no-cache',
                redirect: 'follow'
            });

            const result = {
                url: url,
                status_code: response.status,
                is_broken: response.status >= 400,
                error_type: categorizeErrorType(response.status),
                error: null
            };

            console.log('[ASYNC URL TEST] Internal URL result:', result);
            return result;
        } catch (error) {
            console.error('[ASYNC URL TEST] Error testing internal URL:', error);
            return {
                url: url,
                status_code: 0,
                is_broken: true,
                error_type: 'timeout',
                error: error.message
            };
        }
    }

    /**
     * Test a single external URL via PHP proxy (async)
     * External URLs must use the proxy to avoid CORS issues
     * 
     * @param {string} url URL to test
     * @returns {Promise} Promise resolving to test result
     */
    async function testExternalUrlAsync(url) {
        console.log('[ASYNC URL TEST] Testing external URL via proxy:', url);

        return new Promise((resolve, reject) => {
            $.ajax({
                url: seoautofixBrokenUrls.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'seoautofix_broken_links_test_external_url',
                    nonce: seoautofixBrokenUrls.nonce,
                    url: url
                },
                success: function (response) {
                    if (response.success) {
                        console.log('[ASYNC URL TEST] External URL result:', response.data);
                        resolve(response.data);
                    } else {
                        console.error('[ASYNC URL TEST] External URL test failed:', response.data.message);
                        reject(new Error(response.data.message));
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('[ASYNC URL TEST] AJAX error:', errorThrown);
                    reject(new Error(errorThrown));
                }
            });
        });
    }

    /**
     * Test multiple URLs in parallel batches (async)
     * Automatically routes internal vs external URLs to appropriate testing method
     * 
     * @param {Array} urls Array of URLs to test
     * @param {number} batchSize Number of URLs to test in parallel (default: 10)
     * @param {Function} progressCallback Optional callback for progress updates
     * @returns {Promise} Promise resolving to array of test results
     */
    async function testUrlsBatchAsync(urls, batchSize = 10, progressCallback = null) {
        console.log('[ASYNC URL TEST] Starting batch test for', urls.length, 'URLs (batch size:', batchSize + ')');

        const results = [];
        const homeUrl = seoautofixBrokenUrls.homeUrl;

        // Separate internal and external URLs
        const internalUrls = [];
        const externalUrls = [];

        urls.forEach(url => {
            if (url.startsWith(homeUrl) || url.startsWith('/')) {
                internalUrls.push(url);
            } else {
                externalUrls.push(url);
            }
        });

        console.log('[ASYNC URL TEST] Split:', internalUrls.length, 'internal,', externalUrls.length, 'external');

        // Test internal URLs in parallel batches
        for (let i = 0; i < internalUrls.length; i += batchSize) {
            const batch = internalUrls.slice(i, i + batchSize);
            console.log('[ASYNC URL TEST] Testing internal batch', (i / batchSize) + 1, ':', batch.length, 'URLs');

            const batchPromises = batch.map(url => testInternalUrlAsync(url));
            const batchResults = await Promise.all(batchPromises);
            results.push(...batchResults);

            if (progressCallback) {
                progressCallback(results.length, urls.length, results.filter(r => r.is_broken).length);
            }
        }

        // Test external URLs via proxy in parallel batches
        for (let i = 0; i < externalUrls.length; i += batchSize) {
            const batch = externalUrls.slice(i, i + batchSize);
            console.log('[ASYNC URL TEST] Testing external batch', (i / batchSize) + 1, ':', batch.length, 'URLs');

            const batchPromises = batch.map(url => testExternalUrlAsync(url));
            const batchResults = await Promise.all(batchPromises);
            results.push(...batchResults);

            if (progressCallback) {
                progressCallback(results.length, urls.length, results.filter(r => r.is_broken).length);
            }
        }

        console.log('[ASYNC URL TEST] ✅ Batch complete. Tested', results.length, 'URLs,', results.filter(r => r.is_broken).length, 'broken');
        return results;
    }

    /**
     * Categorize error type based on status code
     * 
     * @param {number} statusCode HTTP status code
     * @returns {string} Error type (4xx, 5xx, timeout, or null)
     */
    function categorizeErrorType(statusCode) {
        if (statusCode >= 400 && statusCode < 500) {
            return '4xx';
        } else if (statusCode >= 500) {
            return '5xx';
        } else if (statusCode === 0) {
            return 'timeout';
        }
        return null; // Success codes (2xx, 3xx)
    }

    // ========================================
    // FRONTEND-DRIVEN SCANNING (v3.0)
    // Universal Page Builder Support
    // ========================================

    /**
     * Fetch rendered HTML from a page URL
     * Works with ALL page builders (Elementor, Gutenberg, Divi, etc.)
     * because we're getting the final rendered output
     */
    async function fetchPageHTML(pageUrl) {
        console.log('[FETCH HTML] Fetching:', pageUrl);
        
        // Create AbortController for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 90000); // 90 seconds timeout
        
        try {
            const response = await fetch(pageUrl, {
                method: 'GET',
                credentials: 'same-origin', // Include cookies for logged-in checks
                headers: {
                    'Accept': 'text/html'
                },
                signal: controller.signal // Add abort signal
            });

            clearTimeout(timeoutId); // Clear timeout if request completes

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const html = await response.text();
            console.log('[FETCH HTML] ✅ Fetched ' + html.length + ' bytes from:', pageUrl);
            return html;
        } catch (error) {
            clearTimeout(timeoutId); // Clear timeout on error
            
            if (error.name === 'AbortError') {
                console.error('[FETCH HTML] ⏱️ Timeout fetching ' + pageUrl + ' (90s limit exceeded)');
                throw new Error('Fetch timeout - page took too long to load');
            }
            
            console.error('[FETCH HTML] ❌ Error fetching ' + pageUrl + ':', error);
            throw error;
        }
    }

    /**
     * Extract all links from HTML using native browser DOMParser
     * UNIVERSAL: Works with Elementor, Gutenberg, Divi, WPBakery, etc.
     * because we parse the final rendered HTML
     */
    function extractLinksFromHTML(html, pageUrl, pageTitle, pageId) {
        console.log('[EXTRACT LINKS] Parsing HTML from:', pageUrl);

        // Use native browser DOMParser (fast!)
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        const links = [];
        const siteUrl = window.location.origin;

        // Find all anchor tags
        doc.querySelectorAll('a[href]').forEach((anchor) => {
            try {
                const href = anchor.getAttribute('href');

                // Skip empty hrefs, anchors, javascript:, mailto:, tel:
                if (!href || href.startsWith('#') || href.startsWith('javascript:') ||
                    href.startsWith('mailto:') || href.startsWith('tel:')) {
                    return;
                }

                // Convert relative URLs to absolute
                const absoluteUrl = new URL(href, pageUrl).href;

                // ============================================
                // FILTER OUT THIRD-PARTY TRACKING URLS
                // ============================================
                if (shouldExcludeURL(absoluteUrl)) {
                    console.log('[EXTRACT LINKS] ⚠️ Excluded tracking URL:', absoluteUrl);
                    return;
                }

                // Determine if internal or external
                const isInternal = absoluteUrl.startsWith(siteUrl);

                // Get anchor text
                const anchorText = anchor.textContent.trim() || '';

                // Detect location (header, footer, sidebar, content)
                const location = detectLinkLocationDOM(anchor);

                links.push({
                    url: absoluteUrl,
                    anchor_text: anchorText,
                    found_on_url: pageUrl,
                    found_on_page_title: pageTitle,
                    found_on_page_id: pageId,
                    location: location,
                    link_type: isInternal ? 'internal' : 'external'
                });
            } catch (error) {
                console.warn('[EXTRACT LINKS] Error processing link:', error);
            }
        });

        console.log('[EXTRACT LINKS] ✅ Found ' + links.length + ' links in:', pageUrl);
        return links;
    }

    /**
     * Check if URL should be excluded from scanning
     * Excludes tracking, analytics, and third-party service URLs
     * 
     * @param {string} url URL to check
     * @returns {boolean} True if URL should be excluded
     */
    function shouldExcludeURL(url) {
        // List of domains/patterns to exclude (tracking, analytics, ads, social media pixels)
        const excludePatterns = [
            // Google Services
            'google.com/search',
            'google.com/url',
            'google-analytics.com',
            'googletagmanager.com',
            'googlesyndication.com',
            'doubleclick.net',
            'googleadservices.com',
            'adservice.google.com',
            'pagead2.googlesyndication.com',
            'developers.google.com/speed/pagespeed/insights',
            'search.google.com/test/rich-results',
            
            // Facebook/Meta
            'facebook.com/tr',
            'facebook.com/plugins',
            'connect.facebook.net',
            'developers.facebook.com/tools/debug',
            
            // Analytics & Tracking
            'analytics.google.com',
            'stats.wp.com',
            'pixel.wp.com',
            'tracking.',
            'track.',
            
            // Ad Networks
            'adnxs.com',
            'adsystem.com',
            'advertising.com',
            'criteo.com',
            'outbrain.com',
            'taboola.com',
            
            // Social Media Sharing/Tracking
            'twitter.com/intent',
            'linkedin.com/shareArticle',
            'pinterest.com/pin/create',
            
            // Common tracking parameters
            '?utm_',
            '&utm_',
            '?fbclid=',
            '&fbclid=',
            '?gclid=',
            '&gclid=',
            
            // WordPress Admin URLs (should never be scanned)
            '/wp-admin/',
            '/wp-login.php',
            'customize.php?url=',
            'post.php?post=',
            'edit.php',
            
            // Other tracking
            'hotjar.com',
            'mouseflow.com',
            'crazyegg.com',
            'quantserve.com'
        ];

        // Check if URL matches any exclude pattern
        const urlLower = url.toLowerCase();
        for (const pattern of excludePatterns) {
            if (urlLower.includes(pattern.toLowerCase())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect link location within page using DOM navigation
     * Checks parent elements to determine if link is in header, footer, sidebar, or content
     */
    function detectLinkLocationDOM(anchorElement) {
        let element = anchorElement;

        // Traverse up the DOM tree to find location markers
        while (element && element.parentElement) {
            const tagName = element.tagName ? element.tagName.toLowerCase() : '';
            const className = element.className || '';
            const id = element.id || '';

            // Check for header
            if (tagName === 'header' || className.includes('header') || className.includes('navbar') || id.includes('header')) {
                return 'header';
            }

            // Check for footer
            if (tagName === 'footer' || className.includes('footer') || id.includes('footer')) {
                return 'footer';
            }

            // Check for sidebar
            if (className.includes('sidebar') || className.includes('aside') || tagName === 'aside' || id.includes('sidebar')) {
                return 'sidebar';
            }

            element = element.parentElement;
        }

        // Default to content
        return 'content';
    }

    /**
     * Test external URL via backend proxy
     * Uses AJAX to bypass CORS restrictions
     */
    async function testURLviaProxy(url) {
        console.log('[TEST URL PROXY] Testing:', url);

        return new Promise((resolve, reject) => {
            $.ajax({
                url: seoautofixBrokenUrls.ajaxUrl,
                method: 'POST',
                timeout: 120000, // 120 seconds timeout for slow external URLs
                data: {
                    action: 'seoautofix_broken_links_test_url_proxy',
                    nonce: seoautofixBrokenUrls.nonce,
                    url: url
                },
                success: function (response) {
                    if (response.success) {
                        console.log('[TEST URL PROXY] ✅ Result for ' + url + ':', response.data);
                        resolve(response.data);
                    } else {
                        reject(new Error(response.data.message || 'Test failed'));
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('[TEST URL PROXY] ❌ Error testing ' + url + ':', errorThrown);
                    reject(new Error(errorThrown));
                }
            });
        });
    }

    /**
     * Test multiple URLs in parallel using Promise.all
     * Limits concurrency to avoid overwhelming the server
     */
    async function testURLsBatch(urls, concurrency = 10) {
        console.log('[TEST URLS BATCH] Testing ' + urls.length + ' URLs with concurrency: ' + concurrency);

        const results = [];

        // Process URLs in chunks to limit concurrency
        for (let i = 0; i < urls.length; i += concurrency) {
            const chunk = urls.slice(i, i + concurrency);
            console.log('[TEST URLS BATCH] Testing chunk ' + (i / concurrency + 1) + ': ' + chunk.length + ' URLs');

            const chunkPromises = chunk.map(url => testURLviaProxy(url));
            const chunkResults = await Promise.all(chunkPromises);

            results.push(...chunkResults);
        }

        console.log('[TEST URLS BATCH] ✅ Tested ' + results.length + ' URLs');
        return results;
    }

    /**
     * Save broken links batch to database
     */
    async function saveBrokenLinksBatch(scanId, brokenLinks) {
        console.log('[SAVE BROKEN LINKS] Saving ' + brokenLinks.length + ' broken links');

        return new Promise((resolve, reject) => {
            $.ajax({
                url: seoautofixBrokenUrls.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'seoautofix_broken_links_save_broken_links_batch',
                    nonce: seoautofixBrokenUrls.nonce,
                    scan_id: scanId,
                    broken_links: JSON.stringify(brokenLinks)
                },
                success: function (response) {
                    if (response.success) {
                        console.log('[SAVE BROKEN LINKS] ✅ Saved:', response.data);
                        resolve(response.data);
                    } else {
                        reject(new Error(response.data.message || 'Save failed'));
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('[SAVE BROKEN LINKS] ❌ Error:', errorThrown);
                    reject(new Error(errorThrown));
                }
            });
        });
    }

})(jQuery);
