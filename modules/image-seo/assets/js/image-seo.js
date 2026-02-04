/**
 * Image SEO Module - Admin JavaScript
 */

jQuery(document).ready(function ($) {
    // Global state
    window.scannedImages = [];
    let scannedImages = window.scannedImages;
    let globalStats = null;
    let currentPage = 1;
    let itemsPerPage = 50;

    // Bulk Generation Progress Tracking
    let isGenerating = false;
    let generationCancelled = false;
    let totalToGenerate = 0;
    let generatedCount = 0;

    // Filter-Scoped CSV Export Tracking
    let filterChanges = [];  // Track changes made in current filter
    let currentFilterValue = null;  // Track which filter is active

    // Elements
    const $scanBtn = $('#scan-btn');
    const $exportBtn = $('#export-csv-btn');
    const $exportFilterCsvBtn = $('#export-filter-csv-btn');  // NEW: Filter-scoped export
    const $bulkApplyBtn = $('#bulk-apply-btn');
    const $resultsTable = $('.imageseo-results');
    const $resultsTbody = $('#results-tbody');
    const $emptyState = $('#empty-state');
    const $statsSection = $('.imageseo-stats');
    const $filtersSection = $('.imageseo-filters');
    const $scanProgress = $('#scan-progress');
    const $progressFill = $('.progress-fill');

    console.log('Timestamp: 10:42');

    /**
     * Show toast notification
     */
    function showToast(message, type = 'success') {
        // Remove existing toasts
        $('.imageseo-toast').remove();

        const toastClass = type === 'success' ? 'toast-success' : 'toast-error';
        const icon = type === 'success' ? '✓' : '✕';

        const $toast = $(`
            <div class="imageseo-toast ${toastClass}">
                <span class="toast-icon">${icon}</span>
                <span class="toast-message">${message}</span>
            </div>
        `);

        $('body').append($toast);

        // Animate in
        setTimeout(() => $toast.addClass('show'), 10);

        // Remove after 3 seconds
        setTimeout(() => $toast.removeClass('show'), 3000);
        setTimeout(() => $toast.remove(), 3300);
    }

    /**
     * Update stats cards display
     * Recalculates stats from scannedImages array and updates DOM
     */
    function updateStats() {

        if (!scannedImages || scannedImages.length === 0) {

            return;
        }

        // Recalculate stats from current scannedImages array
        const total = scannedImages.length;
        const withAlt = scannedImages.filter(img => {
            return img.current_alt && img.current_alt.trim().length > 0;
        }).length;
        const withoutAlt = total - withAlt;

        // Update stat cards
        $('#stat-total').text(total);
        $('#stat-missing-alt').text(withoutAlt);
        $('#stat-has-alt').text(withAlt);
    }

    /**
     * Load initial stats from database on page load
     * This shows existing scan data from previous sessions
     */
    function loadInitialStats() {

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_get_stats',
                nonce: imageSeoData.nonce
            },
            success: function (response) {
                console.log('📊 INIT-STATS: Response received:', response);

                if (response.success && response.data.has_data) {
                    const stats = response.data.stats;
                    console.log('📊 INIT-STATS: Database has data, updating stats cards');
                    console.log('📊 INIT-STATS: Stats:', stats);

                    // Update stat cards with database values
                    $('#stat-total').text(stats.total || 0);
                    $('#stat-missing-alt').text(stats.without_alt || 0);
                    $('#stat-has-alt').text(stats.with_alt || 0);

                    // Show stats section since we have data
                    $statsSection.show();

                } else {
                    // Keep stats hidden until first scan
                }
            },
            error: function (xhr, status, error) {
                console.error('❌ INIT-STATS: Failed to load initial stats');
                console.error('❌ INIT-STATS: Status:', status);
                console.error('❌ INIT-STATS: Error:', error);
            }
        });
    }

    /**
     * Update Bulk Apply button state based on available Apply buttons
     * Button should only be enabled when at least one visible row has an enabled Apply button
     */
    function updateBulkApplyButtonState() {
        const $visibleRows = $resultsTbody.find('tr.result-row:visible');

        // Count rows with ENABLED Apply buttons
        let readyCount = 0;
        $visibleRows.each(function () {
            const $applyBtn = $(this).find('.apply-btn');
            if ($applyBtn.length > 0 && $applyBtn.is(':visible') && !$applyBtn.prop('disabled')) {
                readyCount++;
            }
        });

        // Enable/disable Bulk Apply based on ready count
        if (readyCount > 0) {
            $bulkApplyBtn.prop('disabled', false).removeClass('disabled');
        } else {
            $bulkApplyBtn.prop('disabled', true).addClass('disabled');
        }
    }

    // ========== TAB NAVIGATION ==========
    $('.nav-tab').on('click', function (e) {
        e.preventDefault();
        const tabId = $(this).data('tab');

        // Update tab buttons
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        // Show/hide tab content
        $('.tab-content').hide();
        $('#tab-' + tabId).show();

        console.log('TAB-DEBUG: Switched to tab:', tabId);

        // VISIBILITY FIX: Only show the Destructive Warning on Cleanup tab
        if (tabId === 'cleanup-delete') {
            $('#cleanup-tab-warning').show();
        } else {
            $('#cleanup-tab-warning').hide();
        }
    });

    /**
     * Initialize page state on load
     */
    console.log('PAGE-INIT-DEBUG: [Frontend] Initializing page state...');

    // Ensure scan progress is hidden on page load
    $scanProgress.hide();
    console.log('PAGE-INIT-DEBUG: [Frontend] Scan progress hidden');

    // Empty state should be hidden initially (table will show if there's cached data)
    $emptyState.hide();
    console.log('PAGE-INIT-DEBUG: [Frontend] Empty state hidden');

    // Filter controls should be hidden until first scan completes
    $('.imageseo-filter-controls').hide();
    console.log('PAGE-INIT-DEBUG: [Frontend] Filter controls hidden (will show after scan)');

    // Disable AI generation features if no API key
    if (!imageSeoData.hasApiKey) {
        console.log('API-KEY-DEBUG: No API key - Disabling AI generation features');
        $('#generate-visible-btn').prop('disabled', true).css('opacity', '0.5');
        // Individual generate buttons will be disabled when rows are created
    } else {
        console.log('API-KEY-DEBUG: API key configured - AI features enabled');
    }

    console.log('PAGE-INIT-DEBUG: [Frontend] Page initialized successfully');

    // Scan Images
    $scanBtn.on('click', function () {
        scanImages();
    });

    // UX-IMPROVEMENT: Removed "View Optimized Images" button
    // Now using stat card filtering instead

    // Bulk Apply - Click individual Apply buttons for visible rows with ENABLED apply buttons
    $bulkApplyBtn.on('click', function () {
        // Get only VISIBLE rows
        const $visibleRows = $resultsTbody.find('tr.result-row:visible');

        if ($visibleRows.length === 0) {
            showToast('No visible images to apply', 'error');
            return;
        }

        // Find rows that have ENABLED apply buttons
        const $applicableRows = [];

        $visibleRows.each(function () {
            const $row = $(this);
            const imageId = $row.data('attachment-id');
            const $applyBtn = $row.find('.apply-btn');

            // Check if Apply button exists, is visible, and NOT disabled
            if ($applyBtn.length > 0 && $applyBtn.is(':visible') && !$applyBtn.prop('disabled')) {
                $applicableRows.push($row);
            }
        });

        if ($applicableRows.length === 0) {
            showToast('No images ready to apply. Generate or edit alt text first, then click Apply on individual rows.', 'warning');
            return;
        }

        // Confirm
        if (!confirm(`Apply alt text changes for ${$applicableRows.length} visible images?`)) {
            return;
        }

        // Click Apply button on each row
        let clickedCount = 0;
        $applicableRows.forEach(($row, index) => {
            const imageId = $row.data('attachment-id');
            const $applyBtn = $row.find('.apply-btn');

            // Delay between clicks to avoid overwhelming server
            setTimeout(() => {
                $applyBtn.click();
            }, index * 200);

            clickedCount++;
        });

        showToast(`Applying changes to ${clickedCount} images...`, 'info');
    });

    // ===== UX REDESIGN: REMOVED OLD 4 BULK GENERATION BUTTONS =====
    // They are replaced by radio filters + single Generate button


    // ===== UX REDESIGN: STAT CARDS ARE NOW NON-CLICKABLE =====
    // Removed click handlers - stat cards are display-only now

    // ===== UX REDESIGN: NEW 4 RADIO BUTTON FILTERS with Unsaved Warning =====
    $('input[name="image-filter"]').on('change', function () {
        const filterValue = $(this).val();
        const $this = $(this);

        console.log('NEW-RADIO-DEBUG: Filter changed to:', filterValue);

        if (scannedImages.length === 0) {
            showToast('Please click "Scan Images" first', 'error');
            return;
        }

        // CHECK: Are there unsaved AI suggestions?
        const hasUnsavedSuggestions = checkForUnsavedSuggestions();

        if (hasUnsavedSuggestions) {
            // Show warning
            const confirmSwitch = confirm(
                '⚠️ You have unsaved AI suggestions!\n\n' +
                'Switching filters will clear all generated AI alt text.\n\n' +
                'Click OK to continue and lose suggestions, or Cancel to stay.'
            );

            if (!confirmSwitch) {
                // User cancelled - revert to previous filter (uncheck this one)
                $this.prop('checked', false);
                console.log('FILTER-WARNING: User cancelled filter switch');
                return;
            }

            // User confirmed - clear all suggestions
            console.log('FILTER-WARNING: User confirmed - clearing suggestions');
            clearAllAISuggestions();
        }

        let filtered = scannedImages;
        let shouldGroup = false;

        // Apply filter based on radio selection (SIMPLIFIED: Shows ALL images, no optimization filtering)
        if (filterValue === 'with-alt-postpage') {
            filtered = scannedImages.filter(img => {
                const hasAlt = img.current_alt && img.current_alt.trim().length > 0;
                const inPostsPages = img.used_in_posts > 0 || img.used_in_pages > 0;
                return hasAlt && inPostsPages; // Show ALL images with alt in posts/pages
            });
            shouldGroup = true;
        } else if (filterValue === 'without-alt-postpage') {
            filtered = scannedImages.filter(img => {
                const noAlt = !img.current_alt || img.current_alt.trim().length === 0;
                const inPostsPages = img.used_in_posts > 0 || img.used_in_pages > 0;
                return noAlt && inPostsPages; // Show ALL images without alt in posts/pages
            });
            shouldGroup = true;
        } else if (filterValue === 'with-alt-all') {
            filtered = scannedImages.filter(img => {
                const hasAlt = img.current_alt && img.current_alt.trim().length > 0;
                return hasAlt; // Show ALL images with alt
            });
        } else if (filterValue === 'without-alt-all') {
            filtered = scannedImages.filter(img => {
                const noAlt = !img.current_alt || img.current_alt.trim().length === 0;
                return noAlt; // Show ALL images without alt
            });
        }

        // FIXED: Clear changes when switching to a different filter
        // Each filter view is independent - start fresh
        if (currentFilterValue !== filterValue) {
            console.log('FILTER-CSV: Filter changed from', currentFilterValue, 'to', filterValue, '- CLEARING', filterChanges.length, 'tracked changes');
            filterChanges = []; // Clear the array
            currentFilterValue = filterValue; // Update current filter

            // Disable export button since we have no changes in this new filter view
            $exportFilterCsvBtn.show().prop('disabled', true).addClass('disabled');
            console.log('EXPORT-BTN: Disabled - switched to new filter, no changes yet');
        }

        currentPage = 1;
        renderResults(filtered, shouldGroup);
        console.log('NEW-RADIO-DEBUG: Showing', filtered.length, 'images, grouped:', shouldGroup);
    });

    // ===== NEW: GENERATE VISIBLE IMAGES BUTTON with Progress Tracking =====
    $('#generate-visible-btn').on('click', function () {
        console.log('====== GENERATE VISIBLE DEBUG START ======');
        console.log('GEN-VISIBLE-DEBUG: Button clicked');

        if (!imageSeoData.hasApiKey) {
            showToast('API key not configured', 'error');
            return;
        }

        // Prevent starting if already generating
        if (isGenerating) {
            showToast('Generation already in progress', 'warning');
            return;
        }

        // Get currently visible image IDs
        const $visibleRows = $resultsTbody.find('tr.result-row:visible');
        console.log('GEN-VISIBLE-DEBUG: Found visible rows:', $visibleRows.length);

        if ($visibleRows.length === 0) {
            showToast('No images visible to generate', 'error');
            return;
        }

        const visibleImageIds = [];
        $visibleRows.each(function () {
            const id = parseInt($(this).data('attachment-id'));
            visibleImageIds.push(id);
        });

        // Get full image objects from scannedImages
        const imagesToGenerate = scannedImages.filter(img => visibleImageIds.includes(parseInt(img.id)));
        console.log('GEN-VISIBLE-DEBUG: Images to generate:', imagesToGenerate.length);

        if (!confirm(`Generate AI alt text for ${imagesToGenerate.length} visible images?`)) {
            return;
        }

        // START GENERATION with progress tracking
        startBulkGeneration(imagesToGenerate);

        console.log('====== GENERATE VISIBLE DEBUG END ======');
    });

    // ===== CANCEL GENERATION BUTTON =====
    $(document).on('click', '#cancel-generation-btn', function () {
        console.log('CANCEL-GENERATION: Cancel button clicked');

        if (!isGenerating) {
            return;
        }

        if (confirm('Cancel AI generation? Any generated alt text will be lost.')) {
            generationCancelled = true;
            console.log('CANCEL-GENERATION: User confirmed cancellation');

            // Clear all AI-generated alt text from visible rows
            $('.result-row').each(function () {
                const $row = $(this);
                const $editableInput = $row.find('.alt-text-editable');
                const $applyBtn = $row.find('.apply-btn');
                const attachmentId = $row.attr('data-attachment-id');

                // Clear the input field
                $editableInput.text('');

                // Disable Apply button
                $applyBtn.prop('disabled', true);

                // Clear from scannedImages array
                const imgIndex = scannedImages.findIndex(img => parseInt(img.id) === parseInt(attachmentId));
                if (imgIndex !== -1) {
                    delete scannedImages[imgIndex].ai_suggestion;
                }

                console.log('CANCEL-GENERATION: Cleared AI suggestion for image', attachmentId);
            });

            // Hide progress, re-enable filters
            endBulkGeneration(true);

            showToast('Generation cancelled - All AI suggestions cleared', 'info');
        }
    });

    // ===== CLEAR ALL AI SUGGESTIONS BUTTON =====
    $(document).on('click', '#clear-suggestions-btn', function () {
        console.log('CLEAR-SUGGESTIONS: Clear All button clicked');

        const hasUnsaved = checkForUnsavedSuggestions();

        if (!hasUnsaved) {
            showToast('No AI suggestions to clear', 'info');
            return;
        }

        if (confirm('Clear all AI-generated alt text suggestions?\n\nThis cannot be undone.')) {
            clearAllAISuggestions();
            showToast('All AI suggestions cleared', 'success');
        }
    });

    // ===== RESET FILTER BUTTON =====
    $('#reset-filter-btn').on('click', function () {
        console.log('RESET-FILTER: Clearing all filters to show ALL images');

        // Uncheck all radio buttons
        $('input[name="image-filter"]').prop('checked', false);

        // FIXED: Clear changes when resetting to "no filter" view
        console.log('FILTER-CSV: Reset filter - CLEARING', filterChanges.length, 'tracked changes');
        filterChanges = []; // Clear the array
        currentFilterValue = 'no_filter'; // Set to "no filter" state

        // Disable export button since we have no changes in this view yet
        $exportFilterCsvBtn.show().prop('disabled', true).addClass('disabled');
        console.log('EXPORT-BTN: Disabled - reset to no filter, no changes yet');

        // Show ALL images (no filtering)
        currentPage = 1;
        renderResults(scannedImages, false);
        showToast(`Showing all ${scannedImages.length} images`, 'success');
    });

    /**
     * Load initial stats from database
     */
    function loadInitialStats() {
        console.log('STATS-REFRESH-DEBUG: ===== loadInitialStats() CALLED =====');

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_get_stats',
                nonce: imageSeoData.nonce
            },
            success: function (response) {
                console.log('STATS-REFRESH-DEBUG: Stats AJAX response:', response);

                if (response.success && response.data.has_data) {
                    console.log('STATS-REFRESH-DEBUG: Stats data received:', response.data.stats);
                    // Update stats if data exists - but we can't use scannedImages yet on page load
                    // Just display the backend stats directly
                    const stats = response.data.stats;
                    $('#stat-total').text(stats.total || 0);
                    $('#stat-missing-alt').text(stats.low_score_empty || 0);
                    $('#stat-has-alt').text((stats.total || 0) - (stats.low_score_empty || 0));
                } else {
                    console.log('STATS-REFRESH-DEBUG: No stats data available');
                }
                // If no data, keep "--" placeholders
            },
            error: function (xhr, status, error) {
                console.error('STATS-REFRESH-DEBUG: Failed to load stats');
                console.error('STATS-REFRESH-DEBUG: Error:', error);
            }
        });
    }

    // Load stats on page load
    loadInitialStats();

    // Export CSV button - now just downloads
    $('#export-csv-btn').on('click', function () {
        console.log('FEATURE-EMAIL: Export CSV button clicked - DOWNLOAD only');
        downloadCSV();
    });

    // Email CSV button - separate button
    $('#email-csv-btn').on('click', function () {
        console.log('FEATURE-EMAIL: Email CSV button clicked');
        emailCSV();
    });

    // Bulk Delete Unused Images
    $('#bulk-delete-unused-btn').on('click', function () {
        console.log('BULK-DELETE-DEBUG: Bulk delete button clicked');

        // Get count of unused images
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_get_unused_count',
                nonce: imageSeoData.nonce
            },
            success: function (response) {
                console.log('BULK-DELETE-DEBUG: Unused count response:', response);
                if (response.success) {
                    const count = response.data.count;
                    $('.bulk-delete-count').text(count + ' unused images found.');
                    $('#bulk-delete-modal').fadeIn(200);
                } else {
                    alert('Error: ' + response.data.message);
                }
            }
        });
    });

    // Close modal
    $('.bulk-delete-modal-close, .bulk-delete-modal-overlay').on('click', function () {
        $('#bulk-delete-modal').fadeOut(200);
    });

    // Download & Delete
    $('#bulk-delete-with-download').on('click', function () {
        console.log('BULK-DELETE-DEBUG: Download & Delete clicked');
        $('.bulk-delete-options').hide();
        $('.bulk-delete-progress').show();
        $('.bulk-delete-progress-text').text('Creating ZIP file...');
        $('.bulk-delete-progress-fill').css('width', '30%');

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_create_unused_zip',
                nonce: imageSeoData.nonce
            },
            success: function (response) {
                console.log('BULK-DELETE-DEBUG: ZIP creation response:', response);
                if (response.success) {
                    $('.bulk-delete-progress-text').text('Downloading ZIP...');
                    $('.bulk-delete-progress-fill').css('width', '60%');

                    // Trigger download
                    const link = document.createElement('a');
                    link.href = response.data.zip_url;
                    link.download = response.data.zip_filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);

                    // Wait a bit then delete
                    setTimeout(function () {
                        bulkDeleteUnusedImages();
                    }, 2000);
                } else {
                    alert('Error creating ZIP: ' + response.data.message);
                    $('#bulk-delete-modal').fadeOut(200);
                }
            },
            error: function () {
                alert('Error creating ZIP file');
                $('#bulk-delete-modal').fadeOut(200);
            }
        });
    });

    // Delete Without Download
    $('#bulk-delete-without-download').on('click', function () {
        if (!confirm('Are you absolutely sure? This will permanently delete all unused images without saving them.')) {
            return;
        }
        console.log('BULK-DELETE-DEBUG: Delete without download confirmed');
        $('.bulk-delete-options').hide();
        $('.bulk-delete-progress').show();
        bulkDeleteUnusedImages();
    });

    function bulkDeleteUnusedImages() {
        $('.bulk-delete-progress-text').text('Deleting unused images...');
        $('.bulk-delete-progress-fill').css('width', '80%');

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_bulk_delete_unused',
                nonce: imageSeoData.nonce
            },
            success: function (response) {
                console.log('BULK-DELETE-DEBUG: Bulk delete response:', response);
                $('.bulk-delete-progress-fill').css('width', '100%');

                if (response.success) {
                    $('.bulk-delete-progress-text').text('Success! Deleted ' + response.data.deleted_count + ' images.');
                    // Refresh stats cards immediately
                    loadInitialStats();
                    setTimeout(function () {
                        $('#bulk-delete-modal').fadeOut(200);
                        // Don't reload page - stats already refreshed
                    }, 1500);
                } else {
                    alert('Error: ' + response.data.message);
                    $('#bulk-delete-modal').fadeOut(200);
                }
            },
            error: function () {
                alert('Error deleting images');
                $('#bulk-delete-modal').fadeOut(200);
            }
        });
    }

    // Migrate Database
    $('#migrate-db-btn').on('click', function () {
        if (!confirm('Update database to support audit tracking? This is safe and only needs to be done once.')) {
            return;
        }

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_migrate_db',
                nonce: imageSeoData.nonce
            },
            success: function (response) {
                if (response.success) {
                    showToast(' Database updated successfully!', 'success');
                    $('#migrate-db-btn').hide();
                } else {
                    showToast(' Migration failed', 'error');
                }
            },
            error: function () {
                showToast(' Migration failed', 'error');
            }
        });
    });

    $exportBtn.on('click', function () {
        exportToCSV();
    });


    /**
     * Scan all images (UX-IMPROVEMENT: No filtering, loads ALL images)
     */
    function scanImages() {

        scannedImages = [];
        currentPage = 1; // Reset to first page on new scan
        $resultsTbody.empty();


        // RESET RADIO BUTTONS - UNCHECK ALL (no default filter)
        console.log('SCAN-DEBUG: Unchecking all radio buttons');
        $('input[name="image-filter"]').prop('checked', false); // No default selection
        $('input[name="image-filter"]').prop('disabled', true); // Disable all during scan

        // Show progress, hide results
        $scanProgress.show();

        // 🔧 FIX: Reset progress bar width to 0% to ensure it updates on first scan
        $progressFill.css('width', '0%');
        console.log('🔧 PROGRESS-FIX: Reset progress bar width to 0%');

        $resultsTable.hide();
        $emptyState.hide();

        // HIDE filter controls and pagination during scan for cleaner UX
        $('.imageseo-filter-controls').hide();
        $('.imageseo-pagination').hide();
        console.log('SCAN-DEBUG: Hidden filter controls and pagination during scan');

        // Stats section should remain HIDDEN until scan completes
        // User wants stats to appear only after scan is done
        $statsSection.hide();
        console.log('SCAN-DEBUG: Stats section hidden - will show after scan completes');

        // Reset percentage
        $('#progress-percentage').text('0%');

        $filtersSection.hide();

        // Reset active filter when new scan starts
        activeFilter = null;
        $('.stat-card, .stat-subcard').removeClass('active');

        // Update button state
        $scanBtn.prop('disabled', true).text('Scanning...');

        console.log('UX-IMPROVEMENT-DEBUG: [Frontend] Starting batch scan...');

        // Start scanning (no filter parameter)
        scanBatch(0);
    }

    /**
     * Scan a batch of images (UX-IMPROVEMENT: No status filter)
     */
    function scanBatch(offset = 0) {

        ;
        const ajaxData = {
            action: 'imageseo_scan',
            nonce: imageSeoData.nonce,
            batch_size: 50,
            offset: offset
            // UX-IMPROVEMENT: No status_filter parameter - backend returns ALL
        };
        ;

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: ajaxData,
            success: function (response) {
                if (response.success) {
                    const results = response.data.results;

                    scannedImages = scannedImages.concat(results);
                    window.scannedImages = scannedImages; // Keep global ref in sync

                    if (offset === 0 && response.data.stats) {
                        globalStats = response.data.stats;

                        // Store total image count for accurate progress calculation
                        if (response.data.total_images) {
                            window.totalImages = response.data.total_images;
                        }
                    }

                    // Update progress with percentage display
                    // 🎯 NEW: Use actual total from backend instead of assuming 500
                    const totalImages = window.totalImages || 500; // Fallback to 500 if not set
                    const scannedSoFar = offset + results.length;
                    const progress = Math.min(100, (scannedSoFar / totalImages) * 100);

                    // 🔍 COMPREHENSIVE DEBUG: Log EVERYTHING about progress bar

                    const $progressBar = $progressFill.parent();

                    if ($progressFill[0]) {
                        // 🔧 FIX: Calculate width in PIXELS instead of percentage
                        // Browser wasn't computing percentage correctly (showed 0px)
                        const parentWidth = $progressBar.width(); // Get parent width in pixels
                        const widthInPixels = (progress / 100) * parentWidth;


                        $progressFill[0].style.width = widthInPixels + 'px';

                    } else {

                    }


                    $('#progress-percentage').text(Math.round(progress) + '%');


                    // FIX: Update stats in real-time after each batch
                    if (scannedImages.length > 0) {
                        updateStats();

                    }

                    // Continue scanning if there are more
                    if (response.data.hasMore) {
                        scanBatch(response.data.offset);
                    } else {
                        // Show 100% completion before hiding
                        const parentWidth = $progressFill.parent().width();
                        const widthInPixels = parentWidth; // 100% = full width

                        $progressFill[0].style.width = widthInPixels + 'px';
                        $('#progress-percentage').text('100%');

                        // Wait 800ms to let user see 100% completion, then render results
                        setTimeout(() => {






                            renderResults(scannedImages);
                            updateStats(); // Recalculate from scannedImages array

                            // ENSURE Export Changes button is visible after renderResults
                            $exportFilterCsvBtn.show().prop('disabled', filterChanges.length === 0);

                            // RE-ENABLE RADIO BUTTONS after scan completes
                            $('input[name="image-filter"]').prop('disabled', false);

                            // SHOW filter controls and pagination after scan completes
                            $('.imageseo-filter-controls').show();
                            $('.imageseo-pagination').show();

                            // SHOW Stats and Results Table (which were hidden initially)
                            $('.imageseo-stats').show();
                            $('.imageseo-results').show();

                            // SHOW Export CSV button after scan completes
                            $('#export-csv-btn').show();

                            // FIXED: Initialize filter changes tracking for new scan
                            filterChanges = []; // Clear any previous changes
                            currentFilterValue = 'no_filter'; // Initial state is "no filter"
                            console.log('FILTER-CSV: Initialized - empty changes, currentFilter:', currentFilterValue);

                            // SHOW Export Changes in CSV button (disabled until changes are made)
                            $exportFilterCsvBtn.show().prop('disabled', true);
                            console.log('EXPORT-CHANGES-DEBUG: Button shown after scan completion, disabled:', $exportFilterCsvBtn.prop('disabled'));

                            $resultsTable.show();

                            console.log('SCAN-DEBUG: Showing filter controls, stats, and results after scan');
                        }, 800); // 800ms delay to show 100% completion
                    }
                } else {
                    showError('Scan failed: ' + (response.data.message || 'Unknown error'));
                    resetUI();
                }
            },
            error: function () {
                showError('Network error occurred during scan');
                resetUI();
            }
        });
    }

    /**
     * Render paginated results
     */
    function renderResults(images, shouldGroup = false) {

        if (!images || images.length === 0) {

            // Hide scan progress and reset button
            $scanProgress.hide();
            $scanBtn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Images');

            // Determine WHY it's empty

            // Check if there are truly no images in the library vs all optimized
            if (globalStats && globalStats.total === 0) {
                console.log('RENDER-DEBUG: [Frontend] Reason: No images in media library (globalStats.total = 0)');
                // No images in media library at all
                $emptyState.find('h2').text('No Images Found');
                $emptyState.find('p').text('Your media library is empty. Upload some images to start optimizing their SEO!');
            } else if (scannedImages.length === 0) {
                console.log('RENDER-DEBUG: [Frontend] Reason: No scan performed yet (scannedImages is empty)');
                // NO SCAN HAS BEEN PERFORMED YET
                $emptyState.find('h2').text('No Issues Found');
                $emptyState.find('p').text('Click "Scan Images" to analyze your media library for SEO issues.');
            } else {
                console.log('RENDER-DEBUG: [Frontend] Reason: All images filtered out or optimized');

                // Check if a filter is active
                const activeFilter = $('input[name="image-filter"]:checked').val();

                if (activeFilter) {
                    // Filter is active = no images match this filter
                    $emptyState.find('h2').text('No Images Found');
                    $emptyState.find('p').text('No images match the selected filter. Try a different filter or click Reset to view all images.');
                } else {
                    // No filter active = media library is empty
                    $emptyState.find('h2').text('No Images Found in the Media');
                    $emptyState.find('p').text('Your media library appears to be empty. Upload some images to get started.');
                }
            }

            $resultsTable.hide();
            $emptyState.show();
            console.log('RENDER-DEBUG: [Frontend] Showing empty state with message');
            return;
        }



        $scanProgress.hide();
        $scanBtn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Images');

        $resultsTbody.empty();

        if (!images || images.length === 0) {
            console.log('No images to render!');

            // Check if a filter is active
            const activeFilter = $('input[name="image-filter"]:checked').val();

            if (activeFilter) {
                // Filter is active = no images match this filter
                $emptyState.find('h2').text('No Images Found');
                $emptyState.find('p').text('No images match the selected filter. Try a different filter or click Reset to view all images.');
            } else {
                // No filter active = media library is empty
                $emptyState.find('h2').text('No Images Found in the Media');
                $emptyState.find('p').text('Your media library appears to be empty. Upload some images to get started.');
            }

            $emptyState.show();
            $resultsTable.hide();
            $filtersSection.hide();
            $statsSection.show(); // Still show stats even if no issues
            return;
        }

        // Show results table, filters, and AI controls
        $resultsTable.show();
        $filtersSection.show();
        $statsSection.show();
        $('.imageseo-ai-controls').show();
        $emptyState.hide();

        // Calculate pagination
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = Math.min(startIndex + itemsPerPage, images.length);
        const pageImages = images.slice(startIndex, endIndex);

        console.log('Rendering images from index', startIndex, 'to', endIndex, '=', pageImages.length, 'images');

        // Render with or without grouping
        if (shouldGroup) {
            console.log('GROUPING-DEBUG: [Frontend] Grouping mode active - grouping images by post/page');
            renderGroupedImages(pageImages, startIndex);
        } else {
            console.log('GROUPING-DEBUG: [Frontend] Flat mode - rendering images without grouping');
            // Render only current page images
            pageImages.forEach((image, index) => {
                const rowNumber = startIndex + index + 1;
                addResultRow(image, rowNumber);
            });
        }

        renderPagination(images);


        // NO PLACEHOLDER TEXT - inputs start empty
        $resultsTbody.find('tr').each(function () {
            const $row = $(this);
            $row.find('.loading-indicator').hide();

            // Start with empty input (already set in addResultRow, just make sure it's editable)
            $row.find('.alt-text-editable')
                .attr('contenteditable', 'true')
                .show();

            // Apply button already managed by updateBulkApplyButtonState
            // clickable-for-ai class already added in addResultRow
        });

        // Update Bulk Apply button state based on ready rows
        updateBulkApplyButtonState();
    }

    /**
     * Render images grouped by post/page
     */
    function renderGroupedImages(images, startIndex) {
        console.log('GROUPING-DEBUG: [Frontend] === renderGroupedImages() called with', images.length, 'images ===');

        // Group images by post/page
        const groups = groupImagesByPostPage(images);
        console.log('GROUPING-DEBUG: [Frontend] Created', Object.keys(groups).length, 'groups');

        let globalRowNumber = startIndex;

        // Render each group
        Object.keys(groups).forEach(groupKey => {
            const group = groups[groupKey];
            console.log('GROUPING-DEBUG: [Frontend] Rendering group:', groupKey, '- Title:', group.title, '- Images:', group.images.length);

            // Add heading row with URL and title
            addHeadingRow(group.title, group.url, group.type);

            // Add image rows for this group
            group.images.forEach((image) => {
                globalRowNumber++;
                addResultRow(image, globalRowNumber);
            });
        });

        console.log('GROUPING-DEBUG: [Frontend] Finished rendering all groups. Total rows rendered:', globalRowNumber - startIndex);
    }

    /**
     * Group images by the posts/pages they appear in
     */
    function groupImagesByPostPage(images) {
        console.log('GROUPING-DEBUG: [Frontend] === groupImagesByPostPage() called ===');
        const groups = {};

        images.forEach(image => {
            console.log('GROUPING-DEBUG: [Frontend] Processing image ID:', image.id);
            console.log('GROUPING-DEBUG: [Frontend] usage_details:', image.usage_details);

            // Check if image has usage details
            if (!image.usage_details || image.usage_details.length === 0) {
                console.log('GROUPING-DEBUG: [Frontend] No usage_details for image', image.id, '- skipping');
                return;
            }

            // Image can appear in multiple posts/pages
            image.usage_details.forEach(detail => {
                const groupKey = detail.type + '_' + detail.post_id;

                if (!groups[groupKey]) {
                    groups[groupKey] = {
                        title: detail.title,
                        url: detail.url || '',  // Capture URL for heading display
                        type: detail.type,
                        post_id: detail.post_id,
                        images: []
                    };
                    console.log('GROUPING-DEBUG: [Frontend] Created new group:', groupKey, '- Title:', detail.title, '- URL:', detail.url);
                }

                groups[groupKey].images.push(image);
            });
        });

        console.log('GROUPING-DEBUG: [Frontend] Final groups structure:', groups);
        return groups;
    }

    /**
     * Add a heading row for a post/page group
     * @param {string} title - Page/post title 
     * @param {string} url - Page/post URL
     * @param {string} type - 'post' or 'page'
     */
    function addHeadingRow(title, url, type) {
        console.log('GROUPING-DEBUG: [Frontend] Adding heading row - Title:', title, 'URL:', url, 'Type:', type);

        const typeLabel = type === 'post' ? 'Post' : 'Page';
        const cssClass = type === 'post' ? 'post-type' : 'page-type';

        // Build heading with URL as main text and title as subtitle
        const $headingRow = $(`
            <tr class="post-page-heading ${cssClass}">
                <td colspan="5">
                    <div class="page-header-content">
                        <div class="page-type-label">${typeLabel}</div>
                        <a href="${url}" target="_blank" class="page-url">${url}</a>
                        <div class="page-title">${title}</div>
                    </div>
                </td>
            </tr>
        `);

        $resultsTbody.append($headingRow);
        console.log('GROUPING-DEBUG: [Frontend] Heading row added successfully');
    }

    /**
     * Render pagination controls
     */
    function renderPagination(images) {
        console.log('FEATURE-DEBUG: === renderPagination() called ===');

        const totalPages = Math.ceil(images.length / itemsPerPage);

        if (totalPages <= 1) {
            $('#pagination-controls').remove();
            return;
        }

        let paginationHtml = '<div id="pagination-controls" style="margin-top: 20px; text-align: center;">';
        paginationHtml += '<div class="tablenav"><div class="tablenav-pages">';
        paginationHtml += '<span class="displaying-num">' + images.length + ' items</span>';

        // Previous button
        if (currentPage > 1) {
            paginationHtml += '<a class="prev-page button" data-page="' + (currentPage - 1) + '">‹ Previous</a>';
        } else {
            paginationHtml += '<span class="prev-page button disabled">‹ Previous</span>';
        }

        // Page numbers
        paginationHtml += '<span class="paging-input">';
        paginationHtml += '<label for="current-page-selector" class="screen-reader-text">Current Page</label>';
        paginationHtml += '<input class="current-page" id="current-page-selector" type="text" name="paged" value="' + currentPage + '" size="2" aria-describedby="table-paging">';
        paginationHtml += '<span class="tablenav-paging-text"> of <span class="total-pages">' + totalPages + '</span></span>';
        paginationHtml += '</span>';

        // Next button  
        if (currentPage < totalPages) {
            paginationHtml += '<a class="next-page button" data-page="' + (currentPage + 1) + '">Next ›</a>';
        } else {
            paginationHtml += '<span class="next-page button disabled">Next ›</span>';
        }

        paginationHtml += '</div></div></div>';

        // Remove existing pagination and add new
        $('#pagination-controls').remove();
        $resultsTable.after(paginationHtml);

        // Bind click events
        $('.prev-page, .next-page').on('click', function (e) {
            e.preventDefault();
            if (!$(this).hasClass('disabled')) {
                currentPage = parseInt($(this).data('page'));
                renderResults(images);
                $('html, body').animate({ scrollTop: $resultsTable.offset().top - 100 }, 300);
            }
        });

        // Page input change
        $('#current-page-selector').on('change', function () {
            const newPage = parseInt($(this).val());
            if (newPage >= 1 && newPage <= totalPages) {
                currentPage = newPage;
                renderResults(images);
                $('html, body').animate({ scrollTop: $resultsTable.offset().top - 100 }, 300);
            } else {
                $(this).val(currentPage);
            }
        });
    }
    /**
     * Add a result row to the table
     */
    function addResultRow(image, rowNumber) {
        // USAGE TRACKING DEBUG - Show detailed usage information
        console.log('🔍 Image:', image.filename, '(ID:', image.id + ')');
        console.log('   Used in posts:', image.used_in_posts || 0, '| Used in pages:', image.used_in_pages || 0);

        if (image.usage_details && image.usage_details.length > 0) {
            console.log('   ✅ Used in', image.usage_details.length, 'posts/pages:');
            image.usage_details.forEach((detail, idx) => {
                console.log(`      ${idx + 1}. ${detail.type}: "${detail.title}"`);
            });
        } else {
            console.log('   ❌ Not used anywhere');
        }

        // Determine if delete button should be visible
        const isUnused = (!image.used_in_posts && !image.used_in_pages);

        const template = document.getElementById('result-row-template');
        const clone = template.content.cloneNode(true);
        const $row = $(clone.querySelector('.result-row'));

        // Set data
        $row.attr('data-attachment-id', image.id);
        $row.attr('data-issue-type', image.issue_type);
        $row.find('.row-number').text(rowNumber);
        $row.find('.attachment-thumbnail').attr('src', image.thumbnail);
        $row.find('.image-filename').text(image.filename);
        $row.find('.row-current-alt .alt-text').text(image.current_alt || 'Empty');

        // Store original alt text in row data for live updates
        $row.data('original-alt', image.current_alt || 'Empty');

        // CRITICAL: Restore AI suggestion from array if it exists (persists across filter changes)
        if (image.ai_suggestion) {
            $row.find('.alt-text-editable').text(image.ai_suggestion);
            $row.find('.char-count').text(image.ai_suggestion.length);
            $row.find('.apply-btn').prop('disabled', false); // Enable Apply button
            $row.find('.loading-indicator').hide();
            $row.data('ai-suggestion', image.ai_suggestion); // Store for validation skip
        } else {
            // No suggestion yet - START WITH EMPTY INPUT (no placeholder)
            $row.find('.alt-text-editable').text(''); // Empty, not placeholder
            $row.find('.apply-btn').prop('disabled', true);
            $row.find('.loading-indicator').show();
        }

        // Append to table (score columns removed)
        $resultsTbody.append($row);

        // Attach event handlers (all images treated equally now)
        attachRowHandlers($row, image);
    }

    /**
     * Attach event handlers to a row
     */
    function attachRowHandlers($row, image) {
        const attachmentId = image.id;

        // Apply button
        $row.find('.apply-btn').on('click', function () {
            const $btn = $(this);
            const altText = $row.find('.alt-text-editable').text().trim();

            // Double-check text exists
            if (!altText || altText.length === 0) {
                showToast('Please enter alt text before applying', 'error');
                return;
            }

            applyAltText(attachmentId, altText, $row, $btn);
        });

        // Generate button - NEW INDIVIDUAL GENERATE FEATURE
        // Disable if no API key
        if (!imageSeoData.hasApiKey) {
            $row.find('.generate-btn').prop('disabled', true).css('opacity', '0.5');
        }

        $row.find('.generate-btn').on('click', function () {
            // Check if API key is configured
            if (!imageSeoData.hasApiKey) {
                showToast('OpenAI API key not configured', 'error');
                return;
            }

            // Check if already generating
            if ($row.find('.loading-indicator').is(':visible')) {
                return;
            }

            const $editable = $row.find('.alt-text-editable');
            const $generateBtn = $(this);

            // Disable button and show loading
            $generateBtn.prop('disabled', true);
            $editable.html('');
            $row.find('.loading-indicator').show();

            // Generate AI suggestion (FORCE REFRESH = true)
            generateSuggestion(attachmentId, $row, true);
        });

        // Skip button
        $row.find('.skip-btn').on('click', function () {
            skipImage(attachmentId, $row);
        });

        // UX-IMPROVEMENT: Removed click-on-row feature to generate AI

        // Delete button click handler
        $row.find('.delete-btn').on('click', function () {
            const imageUrl = $row.find('.attachment-thumbnail').attr('src');
            const imageTitle = $row.find('.attachment-title').text();

            const message = `Are you sure you want to delete "${imageTitle}"?\n\nDownload image first (recommended):\n${imageUrl}\n\nThis action cannot be undone.`;

            if (confirm(message)) {
                deleteImage(attachmentId, $row);
            }
        });

        // Editable alt text input handling
        const $editable = $row.find('.alt-text-editable');

        $editable.on('input', function () {
            const text = $(this).text();
            const charCount = text.length;
            $row.find('.char-count').text(charCount);

            // LIVE UPDATE: Show typed text in "Current Alt Text" column
            const $currentAltDisplay = $row.find('.row-current-alt .alt-text');
            if (text.trim().length > 0) {
                $currentAltDisplay.text(text);
                $currentAltDisplay.css('color', '#2271b1'); // Blue to indicate it's being edited
            } else {
                // If empty, show original alt text
                const originalAlt = $row.data('original-alt') || 'Empty';
                $currentAltDisplay.text(originalAlt);
                $currentAltDisplay.css('color', ''); // Reset to default color
            }

            // Enable/disable Apply button based on content
            $row.find('.apply-btn').prop('disabled', charCount === 0);

            // Update Bulk Apply button state
            updateBulkApplyButtonState();
        });

        $editable.on('blur', function () {
            const altText = $(this).text().trim();
            if (altText) {
                rescoreAltText(attachmentId, altText, $row);
            }
        });
    }

    /**
     * Generate AI suggestions for all images


    /**

    /**
     * Generate AI suggestion for an image
     */
    function generateSuggestion(attachmentId, $row, force = false) {
        console.log(`DEBUG-FLOW: === generateSuggestion(force=${force}) ===`);

        // Skip if no API key
        if (!imageSeoData.hasApiKey) {
            $row.find('.loading-indicator').hide();
            $row.find('.alt-text-editable')
                .html('<em style="color: #999;">Manual entry required (no API key)</em>')
                .show();
            $row.find('.apply-btn').prop('disabled', false);
            // Re-enable generate button
            $row.find('.generate-btn').prop('disabled', false);
            return;
        }

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_generate',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId,
                force: force // Send force parameter
            },
            success: function (response) {
                if (response.success) {
                    const altText = response.data.alt_text;
                    $row.find('.loading-indicator').hide();
                    $row.find('.alt-text-editable').text(altText).show();
                    $row.find('.char-count').text(altText.length);

                    // Enable Apply button now that suggestion is ready
                    $row.find('.apply-btn').prop('disabled', false);

                    // Update Bulk Apply button state
                    updateBulkApplyButtonState();

                    // Score both original and suggested
                    scoreOriginal(attachmentId, $row);
                    scoreSuggested(attachmentId, altText, $row);
                } else {
                    $row.find('.loading-indicator').hide();
                    $row.find('.alt-text-editable').text('Error generating suggestion').show();
                }
                // Re-enable generate button
                $row.find('.generate-btn').prop('disabled', false);
            },
            error: function () {
                $row.find('.loading-indicator').hide();
                $row.find('.alt-text-editable').text('Error generating suggestion').show();
                // Re-enable generate button
                $row.find('.generate-btn').prop('disabled', false);
            }
        });
    }

    /**
     * Start bulk generation with progress tracking
     */
    function startBulkGeneration(imagesToGenerate) {
        console.log('BULK-GEN: Starting PARALLEL generation for', imagesToGenerate.length, 'images');

        // Reset state
        isGenerating = true;
        generationCancelled = false;
        totalToGenerate = imagesToGenerate.length;
        generatedCount = 0;

        // Disable filter radio buttons
        $('input[name="image-filter"]').prop('disabled', true);
        console.log('BULK-GEN: Disabled filter radio buttons');

        // Show progress bar
        $('#bulk-generation-progress').show();
        updateProgress();

        // Generate ALL images in PARALLEL
        generateAllImagesParallel(imagesToGenerate);
    }

    /**
     * Generate ALL images in parallel for faster processing
     */
    function generateAllImagesParallel(images) {
        console.log('BULK-GEN-PARALLEL: Sending', images.length, 'API requests simultaneously');

        let completedCount = 0;

        images.forEach((image, index) => {
            // Check if already cancelled before starting
            if (generationCancelled) {
                return;
            }

            const $row = $resultsTbody.find(`tr[data-attachment-id="${image.id}"]`);

            // Show loading state
            $row.find('.alt-text-editable').text('');
            $row.find('.loading-indicator').show();

            // Send API request (all run in parallel)
            $.ajax({
                url: imageSeoData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'imageseo_generate',
                    nonce: imageSeoData.nonce,
                    attachment_id: image.id
                },
                success: function (response) {
                    completedCount++;

                    if (response.success && !generationCancelled) {
                        const altText = response.data.alt_text;

                        // Update row
                        $row.find('.loading-indicator').hide();
                        $row.find('.alt-text-editable').text(altText);
                        $row.find('.apply-btn').prop('disabled', false);
                        $row.data('ai-suggestion', altText);

                        // Update image in scannedImages array
                        const imgIndex = scannedImages.findIndex(img => parseInt(img.id) === parseInt(image.id));
                        if (imgIndex !== -1) {
                            scannedImages[imgIndex].ai_suggestion = altText;
                        }

                        generatedCount++;
                        updateProgress();
                        updateBulkApplyButtonState();

                        console.log(`BULK-GEN-PARALLEL: Completed ${completedCount}/${images.length} (${generatedCount} successful)`);
                    }

                    // Check if all are complete
                    if (completedCount >= images.length) {
                        console.log('BULK-GEN-PARALLEL: All requests completed!');
                        endBulkGeneration(false);
                        showToast(`Generated alt text for ${completedCount} images`, 'success');
                    }
                },
                error: function () {
                    completedCount++;
                    console.error('BULK-GEN-PARALLEL: Error generating for image', image.id);
                    $row.find('.loading-indicator').hide();
                    $row.find('.alt-text-editable').text('Error');

                    // Check if all are complete (even with errors)
                    if (completedCount >= images.length) {
                        console.log('BULK-GEN-PARALLEL: All requests completed (with some errors)!');
                        endBulkGeneration(false);
                        showToast(`Generated alt text for ${generatedCount} images`, 'success');
                    }
                }
            });
        });
    }

    /**
     * Update progress bar and counter
     */
    function updateProgress() {
        const percentage = totalToGenerate > 0 ? (generatedCount / totalToGenerate) * 100 : 0;

        $('#progress-text').text(`Generating: ${generatedCount} of ${totalToGenerate} images`);
        $('#progress-bar-fill').css('width', percentage + '%');

        console.log('BULK-GEN: Progress:', generatedCount, '/', totalToGenerate, '(' + percentage.toFixed(1) + '%)');
    }

    /**
     * End bulk generation and clean up
     */
    function endBulkGeneration(wasCancelled) {
        console.log('BULK-GEN: Ending generation, cancelled:', wasCancelled);

        // Reset state
        isGenerating = false;
        generationCancelled = false;
        totalToGenerate = 0;
        generatedCount = 0;

        // Hide progress bar
        $('#bulk-generation-progress').hide();
        $('#progress-bar-fill').css('width', '0%');

        // Re-enable filter radio buttons
        $('input[name="image-filter"]').prop('disabled', false);
        console.log('BULK-GEN: Re-enabled filter radio buttons');

        // Show Clear All button container if generation completed successfully (not cancelled)
        if (!wasCancelled && checkForUnsavedSuggestions()) {
            $('#clear-suggestions-container').show();
            console.log('BULK-GEN: Showing Clear All button');
        }

        // Update Bulk Apply button state
        updateBulkApplyButtonState();
    }

    /**
     * Clear all generated suggestions (on cancel)
     */


    /**
     * Check if there are any unsaved AI suggestions
     */
    function checkForUnsavedSuggestions() {
        let unsavedCount = 0;

        $resultsTbody.find('tr.result-row:visible').each(function () {
            const $row = $(this);
            const aiSuggestion = $row.data('ai-suggestion');
            const currentAlt = $row.data('original-alt') || '';

            // Only count as unsaved if:
            // 1. AI suggestion exists
            // 2. It's different from the current alt text (hasn't been applied)
            if (aiSuggestion && aiSuggestion.length > 0 && aiSuggestion !== currentAlt) {
                unsavedCount++;
            }
        });

        console.log('UNSAVED-CHECK: Found', unsavedCount, 'unsaved AI suggestions');
        return unsavedCount > 0;
    }

    /**
     * Clear ALL AI suggestions and hide Clear button
     */
    function clearAllAISuggestions() {
        console.log('CLEAR-ALL: Clearing all AI suggestions from table and memory');

        let clearedCount = 0;

        // Clear from ALL rows (visible or not)
        $resultsTbody.find('tr.result-row').each(function () {
            const $row = $(this);

            if ($row.data('ai-suggestion')) {
                $row.find('.alt-text-editable').text('');
                $row.find('.apply-btn').prop('disabled', true);
                $row.data('ai-suggestion', '');
                $row.find('.loading-indicator').hide();

                clearedCount++;
            }
        });

        // Clear from scannedImages array
        scannedImages.forEach(img => {
            if (img.ai_suggestion) {
                delete img.ai_suggestion;
            }
        });

        console.log('CLEAR-ALL: Cleared', clearedCount, 'suggestions');

        // Hide Clear All button container
        $('#clear-suggestions-container').hide();

        // Update Bulk Apply button state
        updateBulkApplyButtonState();
    }

    /**
     * Get the last NON-EMPTY alt text from an image's history
     * This is critical for CSV export - we want the last actual value, not empty states
     */
    function getLastNonEmptyAltText(attachmentId) {
        const imgIndex = scannedImages.findIndex(img => parseInt(img.id) === parseInt(attachmentId));

        if (imgIndex === -1) {
            return '';  // Image not found
        }

        const image = scannedImages[imgIndex];

        // Current alt text (before applying new one)
        const currentAlt = image.current_alt || '';

        // If current is not empty, use it
        if (currentAlt.trim().length > 0) {
            return currentAlt;
        }

        // Otherwise, check if there's a history
        // NOTE: In the current implementation, we don't store full history in scannedImages
        // So if current is empty, we return empty
        // The backend should handle this via the database history
        return '';
    }

    // scoreOriginal() removed - SEO scoring feature disabled

    // scoreSuggested() removed - SEO scoring feature disabled

    /**
     * Delete unused image
     */
    function deleteImage(attachmentId, $row) {
        console.log('FEATURE-DELETE: Deleting image ID:', attachmentId);
        $row.find('.action-status').html('<span class="spinner is-active"></span> Deleting...');

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_delete_image',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId
            },
            beforeSend: function (xhr, settings) {
                console.log('FEATURE-DELETE: AJAX request starting...');
                console.log('FEATURE-DELETE: Request data:', settings.data);
            },
            success: function (response) {

                if (response.success) {
                    console.log('FEATURE-DELETE: Backend confirmed deletion');

                    // Remove from scannedImages array
                    scannedImages = scannedImages.filter(img => img.id !== attachmentId);
                    console.log('FEATURE-DELETE: Removed from scannedImages array. New count:', scannedImages.length);

                    // Remove row from UI
                    $row.fadeOut(300, function () {
                        $(this).remove();
                    });

                    showToast('Image deleted successfully', 'success');

                    // Refresh stats after deletion
                    console.log('FEATURE-DELETE: Refreshing stats...');
                    loadInitialStats();
                } else {
                    console.error('FEATURE-DELETE: Backend returned error:', response.data);
                    showToast('Failed to delete: ' + (response.data.message || 'Unknown error'), 'error');
                }
                $row.find('.action-status').html('');
            },
            error: function (xhr, status, error) {
                console.error('FEATURE-DELETE: AJAX error occurred');
                console.error('FEATURE-DELETE: XHR status:', status);
                console.error('FEATURE-DELETE: Error:', error);
                console.error('FEATURE-DELETE: XHR response:', xhr.responseText);
                console.error('FEATURE-DELETE: XHR full object:', xhr);
                showToast('Failed to delete image', 'error');
                $row.find('.action-status').html('');
            }
        });
    }

    /**
     * Re-score alt text after editing
     */
    function rescoreAltText(attachmentId, altText, $row) {
        // Scoring is now local, so always enabled

        $row.find('.row-score-after').html('<span class="spinner is-active"></span>');

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_score',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId,
                alt_text: altText
            },
            success: function (response) {
                if (response.success && response.data.score) {
                    const scoreData = response.data.score;
                    const score = scoreData.score || scoreData;
                    $row.find('.row-score-after').html(getScoreBadge(score));
                    $row.attr('data-score', score);
                }
            }
        });
    }

    /**
     * Apply alt text with intelligent validation
     */
    function applyAltText(attachmentId, altText, $row, $btn) {

        // SIMPLIFIED: Always apply directly without validation
        console.log('APPLY-DEBUG: [Frontend] Applying alt text directly (no validation)');
        applyAltTextDirect(attachmentId, altText, $row, $btn, 0);
    }

    /**
     * Apply low-score alt text (no validation, keep in table)
     */

    /**
     * Apply high-score alt text with AI validation
     */


    /**
     * Apply alt text directly (validation passed or not required)
     */
    function applyAltTextDirect(attachmentId, altText, $row, $btn) {
        console.log('========================================');
        console.log('🔵 APPLY-DEBUG: applyAltTextDirect() called');
        console.log('🔵 APPLY-DEBUG: Attachment ID:', attachmentId);
        console.log('🔵 APPLY-DEBUG: Alt text:', altText);
        console.log('🔵 APPLY-DEBUG: Button element:', $btn);
        console.log('🔵 APPLY-DEBUG: Row element:', $row);
        console.log('========================================');

        // Update button
        $btn.prop('disabled', true).text('Applying...');
        console.log('🔵 APPLY-DEBUG: Button disabled and text set to "Applying..."');

        const ajaxData = {
            action: 'imageseo_apply',
            nonce: imageSeoData.nonce,
            attachment_id: attachmentId,
            alt_text: altText
        };

        console.log('🔵 APPLY-DEBUG: AJAX URL:', imageSeoData.ajaxUrl);
        console.log('🔵 APPLY-DEBUG: AJAX Data:', JSON.stringify(ajaxData, null, 2));
        console.log('🔵 APPLY-DEBUG: Sending AJAX request NOW...');

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: ajaxData,
            success: function (response) {
                console.log('========================================');
                console.log('✅ APPLY-DEBUG: AJAX SUCCESS callback triggered');
                console.log('✅ APPLY-DEBUG: Full response:', JSON.stringify(response, null, 2));
                console.log('✅ APPLY-DEBUG: response.success:', response.success);
                console.log('✅ APPLY-DEBUG: response.data:', response.data);
                console.log('========================================');

                if (response.success) {
                    console.log('DIRECT-APPLY: [Frontend] Applied successfully');
                    // Show success toast
                    showToast('✓ Alt text applied!', 'success');

                    // SMART REMOVAL LOGIC
                    // 1. If we are in "Without Alt" filter, the image no longer matches the filter criteria → REMOVE
                    // 2. If we are in "With Alt" filter, the image still matches → KEEP & UPDATE

                    const currentFilter = $('input[name="image-filter"]:checked').val();
                    const isWithoutAltFilter = currentFilter && currentFilter.includes('without-alt');

                    if (isWithoutAltFilter) {
                        console.log('DIRECT-APPLY: [Frontend] Current filter is "Without Alt" - Removing row as it now has text');
                        $row.fadeOut(400, function () {
                            $(this).remove();
                            updateBulkApplyButtonState();

                            // Check empty state
                            if ($resultsTbody.find('tr').length === 0) {
                                $resultsTable.hide(); // Allow re-scan but hide empty table
                                // Don't hide filters, let user switch
                            }
                        });
                    } else {
                        console.log('DIRECT-APPLY: [Frontend] Keeping row visible (matches current filter)');

                        // Update the "Current Alt Text" column to show the new value
                        $row.find('.row-current-alt .alt-text').text(altText);
                        $row.find('.row-current-alt .alt-text').css('color', ''); // Reset color if it was blue

                        // Update data attribute
                        $row.data('original-alt', altText);

                        // Change Apply button state to indicate success
                        $btn.text('Applied').prop('disabled', true);
                    }

                    // Update internal data store regardless of UI state
                    // Find image in array
                    const imgIndex = scannedImages.findIndex(img => parseInt(img.id) === parseInt(attachmentId));
                    if (imgIndex !== -1) {
                        scannedImages[imgIndex].current_alt = altText;
                        scannedImages[imgIndex].status = 'optimized'; // Mark as optimized

                        // FIX: Track changes for CSV export regardless of filter state
                        const image = scannedImages[imgIndex];

                        // Get previous alt text (last non-empty value before this change)
                        const previousAlt = getLastNonEmptyAltText(attachmentId);

                        filterChanges.push({
                            attachment_id: attachmentId,
                            filename: image.filename || '',
                            thumbnail: image.thumbnail || '',
                            previous_alt: previousAlt,
                            new_alt: altText,
                            filter: currentFilterValue || 'no_filter'
                        });

                        console.log('FILTER-CSV: Tracked change', filterChanges.length, '- Previous:', previousAlt, '→ New:', altText);

                        // Enable and show the Export button now that we have changes
                        $exportFilterCsvBtn.show().prop('disabled', false).removeClass('disabled');
                        console.log('EXPORT-BTN: Enabled after apply - ' + filterChanges.length + ' changes tracked');
                    }

                    // Update stats cards in real-time - fetch fresh from backend
                    loadInitialStats();

                    // Update Bulk Apply button state
                    updateBulkApplyButtonState();
                } else {
                    console.log('========================================');
                    console.log('❌ APPLY-DEBUG: Backend returned success=false');
                    console.log('❌ APPLY-DEBUG: Error message:', response.data ? response.data.message : 'No message');
                    console.log('========================================');
                    showToast(' Failed to apply: ' + (response.data.message || 'Unknown error'), 'error');
                    $btn.prop('disabled', false).text('Apply');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.log('========================================');
                console.log('❌ APPLY-DEBUG: AJAX ERROR callback triggered');
                console.log('❌ APPLY-DEBUG: textStatus:', textStatus);
                console.log('❌ APPLY-DEBUG: errorThrown:', errorThrown);
                console.log('❌ APPLY-DEBUG: jqXHR.status:', jqXHR.status);
                console.log('❌ APPLY-DEBUG: jqXHR.statusText:', jqXHR.statusText);
                console.log('❌ APPLY-DEBUG: jqXHR.responseText:', jqXHR.responseText);
                console.log('❌ APPLY-DEBUG: Full jqXHR object:', jqXHR);
                console.log('========================================');
                showToast(' Network error occurred', 'error');
                $btn.prop('disabled', false).text('Apply');
            }
        });
    }

    /**
     * Email CSV to WordPress admin
     */
    function emailCSV() {
        console.log('FEATURE-EMAIL: Emailing CSV to admin...');
        showToast('Sending email...', 'info');

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_email_csv',
                nonce: imageSeoData.nonce
            },
            success: function (response) {
                if (response.success) {
                    console.log('FEATURE-EMAIL: Email sent successfully');
                    showToast('CSV emailed to ' + imageSeoData.adminEmail, 'success');
                } else {
                    console.log('FEATURE-EMAIL: Email failed:', response.data.message);
                    showToast('Failed to send email: ' + response.data.message, 'error');
                }
            },
            error: function () {
                console.log('FEATURE-EMAIL: AJAX error');
                showToast('Error sending email', 'error');
            }
        });
    }

    /**
     * Skip image (session only - does not update database)
     */
    function skipImage(attachmentId, $row) {
        console.log('SKIP-DEBUG: [Frontend] Skip clicked for image ID:', attachmentId);
        console.log('SKIP-DEBUG: [Frontend] This is session-only - just hiding row, NOT updating database');

        // Show success toast
        showToast('Image skipped for this session', 'success');

        // NO DATABASE UPDATE - just remove from current view
        // On next scan, if image still has issues, it will appear again

        // Remove row with fade animation
        $row.fadeOut(400, function () {
            $(this).remove();

            console.log('SKIP-DEBUG: [Frontend] Row removed from table');
            console.log('SKIP-DEBUG: [Frontend] Image will reappear on next scan if still has issues');

            // Update Bulk Apply button state (might now be disabled if no rows left)
            updateBulkApplyButtonState();

            // Check if no rows left
            if ($resultsTbody.find('tr').length === 0) {
                $resultsTable.hide();
                $filtersSection.hide();
                $emptyState.find('h2').text('All Done!');
                $emptyState.find('p').text('All visible images have been processed for this session.');
                $emptyState.show();
                console.log('SKIP-DEBUG: [Frontend] No more rows in table - showing empty state');
            }
        });
    }

    /**
     * Bulk apply high confidence suggestions
     */




    /**
     * Get score badge HTML
     */
    function getScoreBadge(score) {
        let cssClass = 'score-empty';

        if (score === 0) {
            cssClass = 'score-empty';
        } else if (score < 50) {
            cssClass = 'score-poor';
        } else if (score < 80) {
            cssClass = 'score-fair';
        } else {
            cssClass = 'score-good';
        }

        return `<div class="score-badge ${cssClass}">${score}</div>`;
    }

    /**
     * Filter results by issue type
 

    /**
     * Export results to CSV
     */
    function exportToCSV() {


        // Direct download (separate Email button exists now)
        downloadCSV();
    }

    /**
     * Download CSV to user's computer
     */
    function downloadCSV() {
        console.log('FEATURE-EMAIL: Downloading CSV...');

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_export_audit',
                nonce: imageSeoData.nonce
            },
            success: function (response) {
                console.log('CSV Export response received:', response);

                if (response.success) {
                    console.log('CSV data length:', response.data.csv ? response.data.csv.length : 'undefined');
                    console.log('Filename:', response.data.filename);
                    console.log('Record count:', response.data.count);

                    // Download CSV
                    const blob = new Blob([response.data.csv], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = response.data.filename;
                    a.click();
                    window.URL.revokeObjectURL(url);

                    console.log('CSV download initiated');
                    showToast(` Exported ${response.data.count} records`, 'success');
                } else {
                    console.error('CSV export failed:', response.data);
                    showToast(+ (response.data.message || 'No change history found'), 'error');
                }
            },
            error: function (xhr, status, error) {
                console.error('CSV export Ajax error:', { xhr, status, error });
                showToast(' CSV Export failed', 'error');
            }
        });
    }

    /**
     * Show error message
     */
    function showError(message) {
        alert('Error: ' + message);
    }

    /**
     * Reset UI
     */
    function resetUI() {
        $scanProgress.hide();
        $scanBtn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Images');
        $emptyState.show();
    }


    // ========== FILTER-SCOPED CSV EXPORT ==========

    $exportFilterCsvBtn.on('click', function () {
        if (filterChanges.length === 0) {
            showToast('No changes to export', 'warning');
            return;
        }

        console.log('FILTER-CSV: Exporting', filterChanges.length, 'changes from filter:', currentFilterValue);

        const $btn = $(this);
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite;"></span> Generating CSV...');

        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_export_filter_csv',
                nonce: imageSeoData.nonce,
                changes: JSON.stringify(filterChanges),
                filter: currentFilterValue
            },
            success: function (response) {
                if (response.success) {
                    // Trigger CSV download
                    const blob = new Blob([response.data.csv], { type: 'text/csv' });
                    const link = document.createElement('a');
                    link.href = window.URL.createObjectURL(blob);
                    link.download = response.data.filename;
                    link.click();

                    showToast(`✓ Exported ${filterChanges.length} changes to CSV`, 'success');
                    console.log('FILTER-CSV: Export successful -', response.data.filename);
                } else {
                    showToast('Failed to export CSV: ' + response.data.message, 'error');
                }
            },
            error: function () {
                showToast('Error exporting CSV', 'error');
            },
            complete: function () {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Export Changes in CSV');
            }
        });
    });

    // ========== CLEANUP & DELETE TAB HANDLERS ==========

    // Remove All Alt Texts
    $('#remove-all-alt-btn').on('click', function () {
        if (!confirm('⚠️ WARNING: Remove ALL alt text from ALL images?\n\nThis cannot be undone!')) return;
        if (!confirm('FINAL CONFIRMATION: Click OK to proceed.')) return;

        const $btn = $(this);
        $btn.prop('disabled', true).text('Removing...');

        $.post(imageSeoData.ajaxUrl, {
            action: 'imageseo_remove_all_alt',
            nonce: imageSeoData.nonce
        }, function (response) {
            if (response.success) {
                showToast(`Removed alt text from ${response.data.removed_count} images`, 'success');
                // Refresh stats cards immediately
                loadInitialStats();
            } else {
                showToast('Failed: ' + response.data.message, 'error');
            }
        }).fail(function () {
            showToast('Error removing alt texts', 'error');
        }).always(function () {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-warning"></span> Remove All Alt Texts');
        });
    });

    // Delete by URL
    $('#delete-by-url-btn').on('click', function () {
        console.log('====== DELETE BY URL DEBUG START ======');

        const urls = $('#delete-urls-input').val().trim();
        console.log('DELETE-URL-DEBUG: Raw textarea value:', urls);
        console.log('DELETE-URL-DEBUG: Textarea value length:', urls.length);

        if (!urls) {
            console.log('DELETE-URL-DEBUG: ERROR - No URLs provided');
            showToast('Please enter at least one URL', 'warning');
            return;
        }

        const urlArray = urls.split('\n').filter(u => u.trim());
        console.log('DELETE-URL-DEBUG: URL array after split:', urlArray);
        console.log('DELETE-URL-DEBUG: URL count:', urlArray.length);

        urlArray.forEach((url, index) => {
            console.log(`DELETE-URL-DEBUG: URL[${index}]:`, url);
        });

        if (urlArray.length > 50) {
            console.log('DELETE-URL-DEBUG: ERROR - Too many URLs:', urlArray.length);
            showToast('Maximum 50 URLs allowed', 'error');
            return;
        }

        if (!confirm(`⚠️ Delete ${urlArray.length} images?\n\nThis cannot be undone!`)) {
            console.log('DELETE-URL-DEBUG: User cancelled');
            return;
        }

        console.log('DELETE-URL-DEBUG: User confirmed - sending AJAX request');
        console.log('DELETE-URL-DEBUG: AJAX URL:', imageSeoData.ajaxUrl);
        console.log('DELETE-URL-DEBUG: Nonce:', imageSeoData.nonce);
        console.log('DELETE-URL-DEBUG: URLs being sent:', urls);

        const $btn = $(this);
        $btn.prop('disabled', true).text('Deleting...');

        $.post(imageSeoData.ajaxUrl, {
            action: 'imageseo_delete_by_url',
            nonce: imageSeoData.nonce,
            urls: urls
        }, function (response) {
            console.log('DELETE-URL-DEBUG: AJAX response received:', response);
            console.log('DELETE-URL-DEBUG: Response success:', response.success);

            if (response.success) {
                console.log('DELETE-URL-DEBUG: Deleted count:', response.data.deleted);
                console.log('DELETE-URL-DEBUG: Skipped count:', response.data.skipped);
                console.log('DELETE-URL-DEBUG: Errors:', response.data.errors);

                let msg = `Deleted ${response.data.deleted}, skipped ${response.data.skipped}`;
                let toastType = 'success';

                // If items were skipped, show error details
                if (response.data.skipped > 0 && response.data.errors && response.data.errors.length > 0) {
                    toastType = 'warning';
                    // Show first error as example
                    msg = `Deleted ${response.data.deleted}, skipped ${response.data.skipped}: ${response.data.errors[0]}`;

                    // Log all errors to console
                    console.log('DELETE-URL-DEBUG: Skip reasons:');
                    response.data.errors.forEach((err, idx) => {
                        console.log(`  [${idx}]:`, err);
                    });
                }

                showToast(msg, toastType);
                $('#delete-urls-input').val('');
                // Refresh stats cards immediately
                loadInitialStats();
            } else {
                console.log('DELETE-URL-DEBUG: Request failed:', response.data.message);
                showToast('Failed: ' + response.data.message, 'error');
            }
        }).fail(function (xhr, status, error) {
            console.log('DELETE-URL-DEBUG: AJAX error');
            console.log('DELETE-URL-DEBUG: XHR:', xhr);
            console.log('DELETE-URL-DEBUG: Status:', status);
            console.log('DELETE-URL-DEBUG: Error:', error);
            showToast('Error deleting images', 'error');
        }).always(function () {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Delete Images by URL');
            console.log('====== DELETE BY URL DEBUG END ======');
        });
    });

    // ========== INITIALIZATION ==========
    // Load initial stats from database on page load
    console.log('🚀 PAGE-LOAD: Calling loadInitialStats()...');
    loadInitialStats();

})
