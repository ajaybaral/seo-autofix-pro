/**
 * Image SEO Module - Admin JavaScript
 */

jQuery(document).ready(function($) {
    // Global state
    let scannedImages = [];
    let globalStats = null;
    let currentPage = 1;
    let itemsPerPage = 50;
    let currentFilter = 'all'; // For stat card filtering ('all', 'missing', 'poor', 'optimized')
    let currentRadioFilter = 'all'; // For radio button filtering ('all', 'postpage')
    
    // Elements
    console.log('SCAN-BUTTON-DEBUG: Setting up scan button selector #scan-btn');
    const $scanBtn = $('#scan-btn');
    console.log('SCAN-BUTTON-DEBUG: Scan button found:', $scanBtn.length, 'element:', $scanBtn[0]);
    const $exportBtn = $('#export-csv-btn');
    const $bulkApplyBtn = $('#bulk-apply-btn');
    const $resultsTable = $('.imageseo-results');
    const $resultsTbody = $('#results-tbody');
    const $emptyState = $('#empty-state');
    const $statsSection = $('.imageseo-stats');
    const $filtersSection = $('.imageseo-filters');
    const $scanProgress = $('#scan-progress');
    const $progressFill = $('.progress-fill');
    
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
     * Initialize page state on load
     */
    console.log('PAGE-INIT-DEBUG: [Frontend] Initializing page state...');
    
    // Ensure scan progress is hidden on page load
    $scanProgress.hide();
    console.log('PAGE-INIT-DEBUG: [Frontend] Scan progress hidden');
    
    // Empty state should be hidden initially (table will show if there's cached data)
    $emptyState.hide();
    console.log('PAGE-INIT-DEBUG: [Frontend] Empty state hidden');
    
    console.log('PAGE-INIT-DEBUG: [Frontend] Page initialized successfully');
    
    // Scan Images
    $scanBtn.on('click', function() {
        scanImages();
    });
    
    // UX-IMPROVEMENT: Removed "View Optimized Images" button
    // Now using stat card filtering instead
    
    // Bulk Apply - ONLY VISIBLE ROWS!
    $bulkApplyBtn.on('click', function() {
        // Get only VISIBLE rows
        const $visibleRows = $resultsTbody.find('tr.result-row:visible');
        
        if ($visibleRows.length === 0) {
            showToast('No visible images to apply', 'error');
            return;
        }
        
        if (!confirm(`Apply alt text changes for ${$visibleRows.length} visible images?`)) {
            return;
        }
        
        const imageIds = [];
        $visibleRows.each(function() {
            const $row = $(this);
            const altText = $row.find('.alt-text-editable').text().trim();
            if (altText) {
                imageIds.push(parseInt($row.data('attachment-id')));
            }
        });
        
        if (imageIds.length === 0) {
            showToast('No images with alt text to apply', 'error');
            return;
        }
        
        // Call backend for bulk apply
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_bulk_apply',
                nonce: imageSeoData.nonce,
                image_ids: imageIds,
                visible_only: true
            },
            success: function(response) {
                if (response.success) {
                    showToast(`Successfully applied ${imageIds.length} changes`, 'success');
                    // Refresh the table
                    scanImages();
                } else {
                    showToast('Bulk apply failed', 'error');
                }
            },
            error: function() {
                showToast('Error during bulk apply', 'error');
            }
        });
    });
    
    // ===== UX REDESIGN: REMOVED OLD 4 BULK GENERATION BUTTONS =====
    // They are replaced by radio filters + single Generate button
    
    /**
     * Get filtered images based on BOTH stat card and radio button selections
     */
    function getFilteredImages() {
        console.log('========================================');
        console.log('COMBINED-FILTER-DEBUG: Getting filtered images');
        console.log('COMBINED-FILTER-DEBUG: Current stat filter:', currentFilter);
        console.log('COMBINED-FILTER-DEBUG: Current radio filter:', currentRadioFilter);
        console.log('COMBINED-FILTER-DEBUG: Total scanned images:', scannedImages.length);
        
        let filtered = scannedImages;
        
        // STEP 1: Apply stat card filter
        if (currentFilter === 'empty') {
            filtered = filtered.filter(img => !img.current_alt || img.current_alt.trim() === '');
            console.log('COMBINED-FILTER-DEBUG: After "empty" filter:', filtered.length, 'images');
        } else if (currentFilter === 'low-with-alt') {
            filtered = filtered.filter(img => {
                const hasAlt = img.current_alt && img.current_alt.trim().length > 0;
                const lowScore = img.seo_score < 75;
                return hasAlt && lowScore;
            });
            console.log('COMBINED-FILTER-DEBUG: After "low-with-alt" filter:', filtered.length, 'images');
        } else if (currentFilter === 'optimized') {
            filtered = filtered.filter(img => img.status === 'optimal' || img.status === 'optimized');
            console.log('COMBINED-FILTER-DEBUG: After "optimized" filter:', filtered.length, 'images');
        } else {
            console.log('COMBINED-FILTER-DEBUG: No stat filter applied (showing all)');
        }
        
        // STEP 2: Apply radio button filter
        if (currentRadioFilter === 'post_page') {
            filtered = filtered.filter(img => img.used_in_posts > 0 || img.used_in_pages > 0);
            console.log('COMBINED-FILTER-DEBUG: After "post_page" filter:', filtered.length, 'images');
        } else {
            console.log('COMBINED-FILTER-DEBUG: No radio filter applied (showing all)');
        }
        
        console.log('COMBINED-FILTER-DEBUG: Final filtered count:', filtered.length);
        console.log('========================================');
        
        return filtered;
    }
    
    // ===== UX REDESIGN: STAT CARDS ARE NOW NON-CLICKABLE =====
    // Removed click handlers - stat cards are display-only now
    
    // ===== UX REDESIGN: NEW 4 RADIO BUTTON FILTERS =====
    $('input[name="image-filter"]').on('change', function() {
        const filterValue = $(this).val();
        
        console.log('NEW-RADIO-DEBUG: Filter changed to:', filterValue);
        
        if (scannedImages.length === 0) {
            showToast('Please click "Scan Images" first', 'error');
            return;
        }
        
        let filtered = scannedImages;
        let shouldGroup = false;
        
        // Apply filter based on radio selection
        if (filterValue === 'with-alt-postpage') {
            filtered = scannedImages.filter(img => {
                const hasAlt = img.current_alt && img.current_alt.trim().length > 0;
                const inPostsPages = img.used_in_posts > 0 || img.used_in_pages > 0;
                const notOptimized = img.status !== 'optimal' && img.status !== 'optimized';
                return hasAlt && inPostsPages && notOptimized;
            });
            shouldGroup = true;
        } else if (filterValue === 'without-alt-postpage') {
            filtered = scannedImages.filter(img => {
                const noAlt = !img.current_alt || img.current_alt.trim().length === 0;
                const inPostsPages = img.used_in_posts > 0 || img.used_in_pages > 0;
                const notOptimized = img.status !== 'optimal' && img.status !== 'optimized';
                return noAlt && inPostsPages && notOptimized;
            });
            shouldGroup = true;
        } else if (filterValue === 'with-alt-all') {
            filtered = scannedImages.filter(img => {
                const hasAlt = img.current_alt && img.current_alt.trim().length > 0;
                const notOptimized = img.status !== 'optimal' && img.status !== 'optimized';
                return hasAlt && notOptimized;
            });
        } else if (filterValue === 'without-alt-all') {
            filtered = scannedImages.filter(img => {
                const noAlt = !img.current_alt || img.current_alt.trim().length === 0;
                const notOptimized = img.status !== 'optimal' && img.status !== 'optimized';
                return noAlt && notOptimized;
            });
        }
        
        currentPage = 1;
        renderResults(filtered, shouldGroup);
        console.log('NEW-RADIO-DEBUG: Showing', filtered.length, 'images, grouped:', shouldGroup);
    });
    
    // ===== NEW: GENERATE VISIBLE IMAGES BUTTON =====
    $('#generate-visible-btn').on('click', function() {
        console.log('====== GENERATE VISIBLE DEBUG START ======');
        console.log('GEN-VISIBLE-DEBUG: Button clicked');
        console.log('GEN-VISIBLE-DEBUG: Has API key?', imageSeoData.hasApiKey);
        
        if (!imageSeoData.hasApiKey) {
            showToast('API key not configured', 'error');
            return;
        }
        
        // DEBUG: Check if tbody exists
        console.log('GEN-VISIBLE-DEBUG: $resultsTbody exists?', $resultsTbody.length > 0);
        console.log('GEN-VISIBLE-DEBUG: $resultsTbody selector:', '#results-tbody');
        
        // Get currently visible image IDs
        const $visibleRows = $resultsTbody.find('tr.result-row:visible');
        console.log('GEN-VISIBLE-DEBUG: Found visible rows:', $visibleRows.length);
        
        // DEBUG: Check ALL rows (visible or not)
        const $allRows = $resultsTbody.find('tr.result-row');
        console.log('GEN-VISIBLE-DEBUG: Total .result-row elements:', $allRows.length);
        
        // DEBUG: Check ANY tr elements
        const $anyTr = $resultsTbody.find('tr');
        console.log('GEN-VISIBLE-DEBUG: Total tr elements:', $anyTr.length);
        
        // DEBUG: Log first row classes if exists
        if ($anyTr.length > 0) {
            console.log('GEN-VISIBLE-DEBUG: First row HTML:', $anyTr.first()[0].outerHTML.substring(0, 200));
            console.log('GEN-VISIBLE-DEBUG: First row classes:', $anyTr.first().attr('class'));
        }
        
        const visibleImageIds = [];
        
        $visibleRows.each(function() {
            const id = parseInt($(this).data('attachment-id'));
            console.log('GEN-VISIBLE-DEBUG: Found image ID:', id);
            visibleImageIds.push(id);
        });
        
        console.log('GEN-VISIBLE-DEBUG: Visible image IDs:', visibleImageIds);
        console.log('GEN-VISIBLE-DEBUG: scannedImages.length:', scannedImages.length);
        console.log('GEN-VISIBLE-DEBUG: Sample scannedImages[0]:', scannedImages[0]);
        console.log('GEN-VISIBLE-DEBUG: scannedImages[0] keys:', scannedImages[0] ? Object.keys(scannedImages[0]) : 'none');
        
        if (visibleImageIds.length === 0) {
            console.log('GEN-VISIBLE-DEBUG: ERROR - No visible images found!');
            showToast('No images visible to generate', 'error');
            return;
        }
        
        // Get full image objects from scannedImages
        // FIX: scannedImages has STRING IDs, visibleImageIds has NUMBER IDs - convert for comparison!
        const imagesToGenerate = scannedImages.filter(img => visibleImageIds.includes(parseInt(img.id)));
        console.log('GEN-VISIBLE-DEBUG: Images to generate:', imagesToGenerate.length);
        console.log('GEN-VISIBLE-DEBUG: FIX APPLIED - Converting string IDs to numbers for comparison');
        
        if (!confirm(`Generate AI alt text for ${imagesToGenerate.length} visible images?`)) {
            return;
        }
        
        console.log('GEN-VISIBLE-DEBUG: Calling generateFilteredSuggestions...');
        generateFilteredSuggestions(imagesToGenerate, 'Visible Images');
        console.log('====== GENERATE VISIBLE DEBUG END ======');
    });
    
    // ===== NEW: VIEW OPTIMIZED IMAGES BUTTON =====
    $('#view-optimized-btn').on('click', function() {
        if (scannedImages.length === 0) {
            showToast('Please scan images first', 'error');
            return;
        }
        
        const optimized = scannedImages.filter(img => 
            img.status === 'optimal' || img.status === 'optimized'
        );
        
        if (optimized.length === 0) {
            showToast('No optimized images found', 'info');
            return;
        }
        
        renderResults(optimized, false);
        showToast(`Showing ${optimized.length} optimized images`, 'success');
    });
    
    // ===== RESET FILTER BUTTON =====
    $('#reset-filter-btn').on('click', function() {
        console.log('RESET-FILTER: Clearing all filters to show all issues');
        
        // Uncheck all radio buttons
        $('input[name="image-filter"]').prop('checked', false);
        
        // Show all NON-OPTIMIZED images (all issues)
        const allIssues = scannedImages.filter(img => 
            img.status !== 'optimal' && img.status !== 'optimized'
        );
        
        currentPage = 1;
        renderResults(allIssues, false);
        showToast(`Showing all ${allIssues.length} images with issues`, 'info');
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
            success: function(response) {
                console.log('STATS-REFRESH-DEBUG: Stats AJAX response:', response);
                
                if (response.success && response.data.has_data) {
                    console.log('STATS-REFRESH-DEBUG: Stats data received:', response.data.stats);
                    // Update stats if data exists
                    updateStats(response.data.stats);
                } else {
                    console.log('STATS-REFRESH-DEBUG: No stats data available');
                }
                // If no data, keep "--" placeholders
            },
            error: function(xhr, status, error) {
                console.error('STATS-REFRESH-DEBUG: Failed to load stats');
                console.error('STATS-REFRESH-DEBUG: Error:', error);
            }
        });
    }
    
    // Load stats on page load
    loadInitialStats();
    
    // Export CSV button - now just downloads
    $('#export-csv-btn').on('click', function() {
        console.log('FEATURE-EMAIL: Export CSV button clicked - DOWNLOAD only');
        downloadCSV();
    });
    
    // Email CSV button - separate button
    $('#email-csv-btn').on('click', function() {
        console.log('FEATURE-EMAIL: Email CSV button clicked');
        emailCSV();
    });
    
    // UX-IMPROVEMENT: Clickable stat cards for filtering
    console.log('UX-IMPROVEMENT-DEBUG: Setting up stat card click handlers');
    console.log('UX-IMPROVEMENT-DEBUG: Stat cards will filter: Total (all), Missing Alt (empty), Poor Quality (low-with-alt), Optimized');
    
    let activeFilter = null; // Track current active filter
    
    // Click handler for ALL stat cards (including Total and Optimized)
    $(document).on('click', '.stat-card, .stat-subcard.clickable', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        console.log('========================================');
        console.log('STAT-FILTER-DEBUG: ===== STAT CARD CLICKED =====');
        
        // Get filter type from data attribute
        let filter = $(this).data('filter');
        console.log('STAT-FILTER-DEBUG: Filter from data-filter:', filter);
        
        // If no data-filter, try to determine from ID
        if (!filter) {
            const cardId = $(this).find('.stat-number').attr('id');
            console.log('STAT-FILTER-DEBUG: No data-filter, checking ID:', cardId);
            
            if (cardId === 'stat-total') filter = 'all';
            else if (cardId === 'stat-fixed') filter = 'optimized';
        }
        
        console.log('STAT-FILTER-DEBUG: Determined filter type:', filter);
        console.log('STAT-FILTER-DEBUG: Current active filter:', activeFilter);
        console.log('STAT-FILTER-DEBUG: Total scanned images:', scannedImages.length);
        
        // Check if images have been scanned
        if (scannedImages.length === 0) {
            console.log('STAT-FILTER-DEBUG: ⚠️ No images scanned yet!');
            showToast('Please click "Scan Images" first', 'error');
            return;
        }
        
        // Toggle logic: clicking same filter again shows all
        if (activeFilter === filter) {
            console.log('STAT-FILTER-DEBUG: Same filter clicked - toggling OFF (show all)');
            activeFilter = null;
            filter = 'all';
        } else {
            console.log('STAT-FILTER-DEBUG: New filter selected:', filter);
            activeFilter = filter;
        }
        
        // Update visual active state
        $('.stat-card, .stat-subcard').removeClass('active');
        if (activeFilter) {
            $(this).addClass('active');
        }
        
        // Filter images
        let filteredImages = [];
        
        if (filter === 'all') {
            console.log('STAT-FILTER-DEBUG: Showing ALL images');
            filteredImages = scannedImages;
        }
        else if (filter === 'empty') {
            console.log('STAT-FILTER-DEBUG: Filtering for MISSING ALT TEXT (empty)');
            filteredImages = scannedImages.filter(img => {
                const isEmpty = img.issue_type === 'empty' || !img.current_alt || img.current_alt.trim() === '';
                return isEmpty;
            });
            console.log('STAT-FILTER-DEBUG: Found', filteredImages.length, 'images with missing alt');
        }
        else if (filter === 'low-with-alt') {
            console.log('STAT-FILTER-DEBUG: Filtering for POOR QUALITY (has alt but low score)');
            filteredImages = scannedImages.filter(img => {
                const hasAlt = img.current_alt && img.current_alt.trim() !== '';
                const isNotOptimal = img.status !== 'optimal' && img.status !== 'optimized';
                const hasAltLowScore = hasAlt && isNotOptimal;
                return hasAltLowScore;
            });
            console.log('STAT-FILTER-DEBUG: Found', filteredImages.length, 'images with poor quality alt');
        }
        else if (filter === 'optimized') {
            console.log('STAT-FILTER-DEBUG: Filtering for OPTIMIZED images');
            filteredImages = scannedImages.filter(img => {
                const isOptimized = img.status === 'optimal' || img.status === 'optimized';
                return isOptimized;
            });
            console.log('STAT-FILTER-DEBUG: Found', filteredImages.length, 'optimized images');
        }
        
        console.log('STAT-FILTER-DEBUG: Filtered result count:', filteredImages.length);
        console.log('STAT-FILTER-DEBUG: Calling renderResults()');
        console.log('========================================');
        
        // Render filtered results
        renderResults(filteredImages);
    });
    
    console.log('UX-IMPROVEMENT-DEBUG: Stat card click handlers setup complete');
    
    // Bulk Delete Unused Images
    $('#bulk-delete-unused-btn').on('click', function() {
        console.log('BULK-DELETE-DEBUG: Bulk delete button clicked');
        
        // Get count of unused images
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_get_unused_count',
                nonce: imageSeoData.nonce
            },
            success: function(response) {
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
    $('.bulk-delete-modal-close, .bulk-delete-modal-overlay').on('click', function() {
        $('#bulk-delete-modal').fadeOut(200);
    });
    
    // Download & Delete
    $('#bulk-delete-with-download').on('click', function() {
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
            success: function(response) {
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
                    setTimeout(function() {
                        bulkDeleteUnusedImages();
                    }, 2000);
                } else {
                    alert('Error creating ZIP: ' + response.data.message);
                    $('#bulk-delete-modal').fadeOut(200);
                }
            },
            error: function() {
                alert('Error creating ZIP file');
                $('#bulk-delete-modal').fadeOut(200);
            }
        });
    });
    
    // Delete Without Download
    $('#bulk-delete-without-download').on('click', function() {
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
            success: function(response) {
                console.log('BULK-DELETE-DEBUG: Bulk delete response:', response);
                $('.bulk-delete-progress-fill').css('width', '100%');
                
                if (response.success) {
                    $('.bulk-delete-progress-text').text('Success! Deleted ' + response.data.deleted_count + ' images.');
                    setTimeout(function() {
                        $('#bulk-delete-modal').fadeOut(200);
                        location.reload();
                    }, 1500);
                } else {
                    alert('Error: ' + response.data.message);
                    $('#bulk-delete-modal').fadeOut(200);
                }
            },
            error: function() {
                alert('Error deleting images');
                $('#bulk-delete-modal').fadeOut(200);
            }
        });
    }
    
    // Migrate Database
    $('#migrate-db-btn').on('click', function() {
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
            success: function(response) {
                if (response.success) {
                    showToast(' Database updated successfully!', 'success');
                    $('#migrate-db-btn').hide();
                } else {
                    showToast(' Migration failed', 'error');
                }
            },
            error: function() {
                showToast(' Migration failed', 'error');
            }
        });
    });
    
        $exportBtn.on('click', function() {
        exportToCSV();
    });
    
    // Stat box filters
    
    $('.stat-card').on('click', function() {
        const $card = $(this);
        const statId = $card.find('.stat-number').attr('id');
        
        // Determine filter type
        let filterType = 'all';
        if (statId === 'stat-total') {
            filterType = 'all';
        } else if (statId === 'stat-empty') {
            filterType = 'empty';
        } else if (statId === 'stat-generic') {
            filterType = 'generic';
        } else if (statId === 'stat-fixed') {
            filterType = 'optimized';
        }
        
        // Update active state
        $('.stat-card').removeClass('filter-active');
        $card.addClass('filter-active');
        
        // Apply filter
        currentFilter = filterType;
        filterResults(filterType);
    });
    
    /**
     * Scan all images (UX-IMPROVEMENT: No filtering, loads ALL images)
     */
    function scanImages() {
        console.log('========================================');
        console.log('UX-IMPROVEMENT-DEBUG: [Frontend] ===== scanImages() CALLED =====');
        console.log('UX-IMPROVEMENT-DEBUG: [Frontend] Will scan ALL images (no status filter)');
        console.log('UX-IMPROVEMENT-DEBUG: [Frontend] Backend sorted by priority (issues first, then optimized)');
        console.log('========================================');
        
        scannedImages = [];
        currentPage = 1; // Reset to first page on new scan
        $resultsTbody.empty();
        
        console.log('SCAN-DEBUG: [Frontend] Cleared scannedImages array');
        console.log('SCAN-DEBUG: [Frontend] Reset currentPage to 1');
        console.log('SCAN-DEBUG: [Frontend] Emptied results tbody');
        
        // RESET RADIO BUTTONS - UNCHECK ALL (no default filter)
        console.log('SCAN-DEBUG: Unchecking all radio buttons');
        $('input[name="image-filter"]').prop('checked', false); // No default selection
        $('input[name="image-filter"]').prop('disabled', true); // Disable all during scan
        
        // Show progress, hide results
        $scanProgress.show();
        $emptyState.hide();
        $resultsTable.hide();
        $statsSection.hide();
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
        console.log('========================================');
        console.log('UX-IMPROVEMENT-DEBUG: [Frontend] ===== scanBatch() CALLED =====');
        console.log('UX-IMPROVEMENT-DEBUG: [Frontend] offset:', offset);
        console.log('UX-IMPROVEMENT-DEBUG: [Frontend] Current scannedImages.length:', scannedImages.length);
        console.log('========================================');
        
        console.log('AJAX-DEBUG: [Frontend] ===== PREPARING AJAX REQUEST =====');
        const ajaxData = {
            action: 'imageseo_scan',
            nonce: imageSeoData.nonce,
            batch_size: 50,
            offset: offset
            // UX-IMPROVEMENT: No status_filter parameter - backend returns ALL
        };
        console.log('AJAX-DEBUG: [Frontend] AJAX data object:', JSON.stringify(ajaxData, null, 2));
        console.log('AJAX-DEBUG: [Frontend] URL:', imageSeoData.ajaxUrl);
        console.log('AJAX-DEBUG: [Frontend] Sending request NOW...');
        
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                console.log('========================================');
                console.log('AJAX-DEBUG: [Frontend] ===== AJAX SUCCESS =====');
                console.log('AJAX-DEBUG: [Frontend] Full response:', JSON.stringify(response, null, 2));
                console.log('AJAX-DEBUG: [Frontend] response.success:', response.success);
                console.log('AJAX-DEBUG: [Frontend] response.data:', response.data);
                
                if (response.success) {
                    const results = response.data.results;
                    console.log('Results in this batch:', results.length);
                    console.log('hasMore flag:', response.data.hasMore);
                    
                    scannedImages = scannedImages.concat(results);
                    console.log('Total scannedImages after concat:', scannedImages.length);
                
                    // Store backend statistics from first batch
                    if (offset === 0 && response.data.stats) {
                        globalStats = response.data.stats;
                    }
                    // Update progress
                    const progress = Math.min(100, (offset + 50) / 500 * 100);
                    $progressFill.css('width', progress + '%');
                    console.log('Progress:', progress + '%');
                    
                    // Continue scanning if there are more
                    if (response.data.hasMore) {
                        console.log('UX-IMPROVEMENT-DEBUG: Has more batches, continuing scan...');
                        scanBatch(response.data.offset); // UX-IMPROVEMENT: No status filter
                    } else {
                        console.log('No more batches, finishing scan...');
                        console.log('Calling renderResults with', scannedImages.length, 'images');
                        console.log('Global stats:', globalStats);
                        renderResults(scannedImages);
                        updateStats(globalStats);
                        
                        // RE-ENABLE RADIO BUTTONS after scan completes
                        $('input[name="image-filter"]').prop('disabled', false);
                    }
                } else {
                    showError('Scan failed: ' + (response.data.message || 'Unknown error'));
                    resetUI();
                }
            },
            error: function() {
                showError('Network error occurred during scan');
                resetUI();
            }
        });
    }
    
    /**
     * Render paginated results
     */
    function renderResults(images, shouldGroup = false) {
        console.log('RENDER-DEBUG: [Frontend] ===== renderResults() CALLED =====');
        console.log('RENDER-DEBUG: [Frontend] images parameter length:', images ? images.length : 'null/undefined');
        console.log('RENDER-DEBUG: [Frontend] shouldGroup:', shouldGroup);
        console.log('RENDER-DEBUG: [Frontend] scannedImages.length:', scannedImages.length);
        console.log('RENDER-DEBUG: [Frontend] globalStats:', globalStats);
        
        console.log('FEATURE-DEBUG: === renderResults() START ===');
        console.log('FEATURE-DEBUG: Total images to render:', images.length);
        console.log('FEATURE-DEBUG: Sample image data:', images[0]);
        console.log('FEATURE-DEBUG: Image fields available:', images[0] ? Object.keys(images[0]) : 'none');
        console.log('FEATURE-DEBUG: Check if usage data exists (used_in_posts, used_in_pages):', {
            has_used_in_posts: images[0] && 'used_in_posts' in images[0],
            has_used_in_pages: images[0] && 'used_in_pages' in images[0],
            sample_values: images[0] ? {used_in_posts: images[0].used_in_posts, used_in_pages: images[0].used_in_pages} : 'N/A'
        });
        console.log('FEATURE-DEBUG: This is where FILTER logic will be applied');
        console.log('=== RENDER RESULTS ===');
        console.log('Images to render:', images ? images.length : 0);
        console.log('Current page:', currentPage);
        
        console.log('NO-API-KEY-DEBUG: API Key present:', imageSeoData.hasApiKey);
        console.log('NO-API-KEY-DEBUG: Should still render table even without API key');
        
        if (!images || images.length === 0) {
            console.log('RENDER-DEBUG: [Frontend] ⚠️ Empty images array detected');
            console.log('RENDER-DEBUG: [Frontend] Determining reason for empty state...');
            console.log('NO-API-KEY-DEBUG: No images to render - showing empty message');
            
            // Hide scan progress and reset button
            $scanProgress.hide();
            $scanBtn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Images');
            
            // Determine WHY it's empty
            console.log('RENDER-DEBUG: [Frontend] scannedImages.length =', scannedImages.length);
            console.log('RENDER-DEBUG: [Frontend] globalStats =', globalStats);
            console.log('RENDER-DEBUG: [Frontend] globalStats.total =', globalStats ? globalStats.total : 'no stats');
            
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
                // Images exist but all are optimized (filtered out)
                $emptyState.find('h2').text('Great Job!');
                $emptyState.find('p').text('All images have optimized alt text. No issues found.');
            }
            
            $resultsTable.hide();
            $emptyState.show();
            console.log('RENDER-DEBUG: [Frontend] Showing empty state with message');
            return;
        }
        
        console.log('NO-API-KEY-DEBUG: Proceeding to render', images.length, 'images');
        
        $scanProgress.hide();
        $scanBtn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Images');

        $resultsTbody.empty();
        
        if (!images || images.length === 0) {
            console.log('No images to render!');
            $emptyState.find('h2').text('Great Job!');
            $emptyState.find('p').text('All images have optimized alt text. No issues found.');
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

        console.log('DEBUG-FLOW: AI generation is now MANUAL only');
        console.log('DEBUG-FLOW: Showing manual input placeholder for all rows');
        
        // NO AUTOMATIC AI GENERATION - Show manual input placeholder for all rows
        $resultsTbody.find('tr').each(function() {
            const $row = $(this);
            $row.find('.loading-indicator').hide();
            
            // Set placeholder text based on API key availability
            const placeholderText = imageSeoData.hasApiKey 
                ? '<em style="color: #999;">Type manually or use Generate button for AI suggestions</em>'
                : '<em style="color: #999;">Manual entry required (no API key)</em>';
            
            $row.find('.alt-text-editable')
                .html(placeholderText)
                .attr('contenteditable', 'true')
                .on('input', function() {
                    // Enable/disable Apply button based on content
                    const text = $(this).text().trim();
                    $row.find('.apply-btn').prop('disabled', text.length === 0);
                    
                    // Remove clickable-for-ai class once user starts typing
                    $row.removeClass('clickable-for-ai');
                })
                .on('focus', function() {
                    // Clear placeholder when user focuses to type
                    if ($(this).html().includes('Type manually')) {
                        $(this).html('');
                    }
                })
                .show();
            
            // Disable Apply button initially (no text yet)
            $row.find('.apply-btn').prop('disabled', true);
            
            // Add clickable-for-ai class to the entire row
            $row.addClass('clickable-for-ai');
        });
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
            
            // Add heading row
            addHeadingRow(group.title, group.type);
            
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
                        type: detail.type,
                        post_id: detail.post_id,
                        images: []
                    };
                    console.log('GROUPING-DEBUG: [Frontend] Created new group:', groupKey, '- Title:', detail.title);
                }
                
                groups[groupKey].images.push(image);
            });
        });
        
        console.log('GROUPING-DEBUG: [Frontend] Final groups structure:', groups);
        return groups;
    }
    
    /**
     * Add a heading row for a post/page group
     */
    function addHeadingRow(title, type) {
        console.log('GROUPING-DEBUG: [Frontend] Adding heading row - Title:', title, 'Type:', type);
        
        
        const typeLabel = type === 'post' ? 'Post' : 'Page';
        const cssClass = type === 'post' ? 'post-type' : 'page-type';
        
        const $headingRow = $(`
            <tr class="post-page-heading ${cssClass}">
                <td colspan="7">
                    <h3> ${typeLabel}: ${title}</h3>
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
        $('.prev-page, .next-page').on('click', function(e) {
            e.preventDefault();
            if (!$(this).hasClass('disabled')) {
                currentPage = parseInt($(this).data('page'));
                renderResults(images);
                $('html, body').animate({scrollTop: $resultsTable.offset().top - 100}, 300);
            }
        });
        
        // Page input change
        $('#current-page-selector').on('change', function() {
            const newPage = parseInt($(this).val());
            if (newPage >= 1 && newPage <= totalPages) {
                currentPage = newPage;
                renderResults(images);
                $('html, body').animate({scrollTop: $resultsTable.offset().top - 100}, 300);
            } else {
                $(this).val(currentPage);
            }
        });
    }
    
    /**
     * Add a result row to the table
     */
    function addResultRow(image, rowNumber) {
        console.log('========================================');
        console.log('ROW-RENDER-DEBUG: [Frontend] ===== ADDING ROW =====');
        console.log('ROW-RENDER-DEBUG: [Frontend] Image ID:', image.id);
        console.log('ROW-RENDER-DEBUG: [Frontend] Image filename:', image.filename);
        console.log('ROW-RENDER-DEBUG: [Frontend] Image status:', image.status);
        console.log('ROW-RENDER-DEBUG: [Frontend] Image issue_type:', image.issue_type);
        console.log('ROW-RENDER-DEBUG: [Frontend] Current alt:', image.current_alt);
        console.log('ROW-RENDER-DEBUG: [Frontend] SEO score:', image.seo_score);
        console.log('========================================');
        
        console.log('FEATURE-DEBUG: Creating row for image ID:', image.id);
        console.log('FEATURE-DEBUG: Usage data for this image:', {
            used_in_posts: image.used_in_posts || 0,
            used_in_pages: image.used_in_pages || 0,
            is_unused: (!image.used_in_posts && !image.used_in_pages)
        })
        console.log('FEATURE-DEBUG: DELETE button should appear for unused images here');
        
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
        
        // CRITICAL: Restore AI suggestion from array if it exists (persists across filter changes)
        if (image.ai_suggestion) {
            console.log('RESTORE-DEBUG: Found ai_suggestion for image', image.id, ':', image.ai_suggestion);
            $row.find('.alt-text-editable').text(image.ai_suggestion);
            $row.find('.char-count').text(image.ai_suggestion.length);
            $row.find('.apply-btn').prop('disabled', false); // Enable Apply button
            $row.find('.loading-indicator').hide();
            $row.data('ai-suggestion', image.ai_suggestion); // Store for validation skip
        } else {
            // No suggestion yet - disable Apply button and show loading
            $row.find('.apply-btn').prop('disabled', true);
            $row.find('.loading-indicator').show();
        }
        
        // POPULATE "BEFORE" SEO SCORE WITH DEBUG
        
        
        const beforeScore = image.score_before !== undefined ? image.score_before : (image.seo_score || 0);
        $row.find('.row-score-before .score-badge')
            .text(beforeScore)
            .removeClass('score-good score-bad')
            .addClass(beforeScore >= 50 ? 'score-good' : 'score-bad');
        
        
        
        // Append to table
        $resultsTbody.append($row);
        
        // CHECK IF IMAGE IS ALREADY OPTIMIZED
        console.log('========================================');
        console.log('OPTIMIZED-CHECK-DEBUG: [Frontend] Checking if image ID ' + image.id + ' is optimized');
        console.log('OPTIMIZED-CHECK-DEBUG: [Frontend] image.status:', image.status);
        console.log('OPTIMIZED-CHECK-DEBUG: [Frontend] Is optimal?', image.status === 'optimal');
        console.log('OPTIMIZED-CHECK-DEBUG: [Frontend] Is optimized?', image.status === 'optimized');
        console.log('========================================');
        
        // CRITICAL FIX: Check for BOTH 'optimal' AND 'optimized' statuses
        if (image.status === 'optimal' || image.status === 'optimized') {
            console.log('========================================');
            console.log('OPTIMIZED-VISUAL-DEBUG: [Frontend] ✅ Image ID ' + image.id + ' IS OPTIMIZED');
            console.log('OPTIMIZED-VISUAL-DEBUG: [Frontend] Status: "' + image.status + '"');
            console.log('OPTIMIZED-VISUAL-DEBUG: [Frontend] Applying green styling and disabling buttons');
            console.log('========================================');
            
            // Add visual class for optimized images
            $row.addClass('image-optimized');
            
            // Disable all action buttons (can't apply/skip optimized images)
            $row.find('.apply-btn').prop('disabled', true).text('Optimized ✓');
            $row.find('.skip-btn').prop('disabled', true).hide();
            $row.find('.delete-btn').prop('disabled', true).hide();
            
            // CRITICAL: Store status in row data for safety checks
            $row.data('status', image.status);
            $row.data('is-optimized', true);
            
            // Hide loading indicator
            $row.find('.loading-indicator').hide();
            
            // Show current alt as suggested (it's already good)
            $row.find('.alt-text-editable').text(image.current_alt).prop('contenteditable', false);
            
            // Mark row as not clickable for AI
            $row.removeClass('clickable-for-ai');
            
            console.log('OPTIMIZED-VISUAL-DEBUG: [Frontend] ✅ Row styled - green BG, buttons disabled, not editable');
        } else {
            console.log('OPTIMIZED-VISUAL-DEBUG: [Frontend] ❌ Image ID ' + image.id + ' is NOT optimized');
            console.log('OPTIMIZED-VISUAL-DEBUG: [Frontend] Status: "' + image.status + '" - buttons will be enabled');
            $row.data('status', image.status);
            $row.data('is-optimized', false);
        }
        
        // Attach event handlers
        attachRowHandlers($row, image);
    }
    
    /**
     * Attach event handlers to a row
     */
    function attachRowHandlers($row, image) {
        const attachmentId = image.id;
        
        // Apply button
        $row.find('.apply-btn').on('click', function() {
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
        $row.find('.generate-btn').on('click', function() {
            console.log('GENERATE-BTN-DEBUG: Generate button clicked for image ID:', attachmentId);
            
            // Check if API key is configured
            if (!imageSeoData.hasApiKey) {
                showToast('OpenAI API key not configured', 'error');
                return;
            }
            
            // Check if already generating
            if ($row.find('.loading-indicator').is(':visible')) {
                console.log('GENERATE-BTN-DEBUG: Already generating, ignoring click');
                return;
            }
            
            console.log('GENERATE-BTN-DEBUG: Starting AI generation...');
            
            const $editable = $row.find('.alt-text-editable');
            const $generateBtn = $(this);
            
            // Disable button and show loading
            $generateBtn.prop('disabled', true);
            $editable.html('');
            $row.find('.loading-indicator').show();
            
            // Generate AI suggestion
            generateSuggestion(attachmentId, $row);
        });
        
        // Skip button
        $row.find('.skip-btn').on('click', function() {
            skipImage(attachmentId, $row);
        });
        
        // UX-IMPROVEMENT: Removed click-on-row feature to generate AI
        // Users must now use the dedicated Generate button only
        // This prevents accidental AI generation when clicking anywhere on the row
        
        console.log('DEBUG-ATTACH: Click handler attached for row ID:', attachmentId);
        
        // Delete button click handler
        $row.find('.delete-btn').on('click', function() {
            console.log('FEATURE-DELETE: Delete clicked for image ID:', attachmentId);
            
            const imageUrl = $row.find('.attachment-thumbnail').attr('src');
            const imageTitle = $row.find('.attachment-title').text();
            
            // Show confirmation modal with download option
            const message = `Are you sure you want to delete "${imageTitle}"?\n\nDownload image first (recommended):\n${imageUrl}\n\nThis action cannot be undone.`;
            
            if (confirm(message)) {
                console.log('FEATURE-DELETE: User confirmed deletion');
                deleteImage(attachmentId, $row);
            } else {
                console.log('FEATURE-DELETE: User cancelled deletion');
            }
        });
        
        // Editable alt text input handling
        const $editable = $row.find('.alt-text-editable');
        
        $editable.on('input', function() {
            const text = $(this).text();
            const charCount = text.length;
            $row.find('.char-count').text(charCount);
            
            // Enable/disable Apply button based on content
            $row.find('.apply-btn').prop('disabled', charCount === 0);
            
            // Limit to 60 chars
            if (charCount > 60) {
                const limited = text.substring(0, 60);
                $(this).text(limited);
                placeCaretAtEnd(this);
            }
        });
        
        $editable.on('blur', function() {
            const altText = $(this).text().trim();
            if (altText) {
                rescoreAltText(attachmentId, altText, $row);
            }
        });
    }
    
    /**
     * Generate AI suggestions for all images
     */
    function generateAllSuggestions() {
        console.log('DEBUG-FLOW: === generateAllSuggestions() called ===');
        console.log('DEBUG-FLOW: Total scannedImages:', scannedImages.length);
        console.log('DEBUG-FLOW: This function will call generateSuggestion() for each image');
        
        scannedImages.forEach((image, index) => {
            const $row = $resultsTbody.find(`tr[data-attachment-id="${image.id}"]`);
            console.log(`DEBUG-FLOW: Calling generateSuggestion for image ${index + 1}/${scannedImages.length} (ID: ${image.id})`);
            
            // Clear placeholder and show loading
            $row.find('.alt-text-editable').html('').show();
            $row.find('.loading-indicator').show();
            
            generateSuggestion(image.id, $row);
        });
    }
    
    /**
     * Generate AI suggestions for images used in posts/pages only
     */
    function generatePostPageSuggestions() {
        console.log('DEBUG-FLOW: === generatePostPageSuggestions() called ===');
        console.log('DEBUG-FLOW: Total scannedImages:', scannedImages.length);
        
        // Filter images that are used in posts or pages
        const postPageImages = scannedImages.filter(image => {
            // Check if image has usage information indicating it's in a post or page
            // This would be populated from the backend scan
            return image.used_in_posts > 0 || image.used_in_pages > 0;
        });
        
        console.log('DEBUG-FLOW: Found', postPageImages.length, 'images used in posts/pages');
        
        if (postPageImages.length === 0) {
            showToast('No images found that are used in posts or pages', 'error');
            return;
        }
        
        if (!confirm(`Generate AI suggestions for ${postPageImages.length} images used in posts/pages? This may take some time and use API credits.`)) {
            return;
        }
        
        postPageImages.forEach((image, index) => {
            const $row = $resultsTbody.find(`tr[data-attachment-id="${image.id}"]`);
            console.log(`DEBUG-FLOW: Calling generateSuggestion for post/page image ${index + 1}/${postPageImages.length} (ID: ${image.id})`);
            
            // Clear placeholder and show loading
            $row.find('.alt-text-editable').html('').show();
            $row.find('.loading-indicator').show();
            
            generateSuggestion(image.id, $row);
        });
        
        showToast(`Generating AI suggestions for ${postPageImages.length} images...`, 'success');
    }
    
    /**
     * Generate AI suggestion for an image
     */
    function generateSuggestion(attachmentId, $row) {
        console.log('DEBUG-FLOW: === generateSuggestion() called ===');
        console.log('DEBUG-FLOW: Attachment ID:', attachmentId);
        console.log('DEBUG-FLOW: *** THIS MAKES THE AJAX CALL TO SERVER FOR AI GENERATION ***');
        
        // Skip if no API key
        if (!imageSeoData.hasApiKey) {
            console.log('DEBUG-FLOW: Skipping - no API key');
            $row.find('.loading-indicator').hide();
            $row.find('.alt-text-editable')
                .html('<em style="color: #999;">Manual entry required (no API key)</em>')
                .show();
            $row.find('.apply-btn').prop('disabled', false);
            return;
        }
        
        console.log('DEBUG-FLOW: Making AJAX call to action: imageseo_generate');
        
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_generate',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId
            },
            success: function(response) {
                console.log('DEBUG-FLOW: AJAX response received for ID', attachmentId, ':', response);
                if (response.success) {
                    const altText = response.data.alt_text;
                    console.log('DEBUG-FLOW: AI suggestion generated:', altText);
                    $row.find('.loading-indicator').hide();
                    $row.find('.alt-text-editable').text(altText).show();
                    $row.find('.char-count').text(altText.length);
                    
                    // Enable Apply button now that suggestion is ready
                    $row.find('.apply-btn').prop('disabled', false);
                    
                    // Score both original and suggested
                    scoreOriginal(attachmentId, $row);
                    scoreSuggested(attachmentId, altText, $row);
                } else {
                    console.log('DEBUG-FLOW: Error in response:', response);
                    $row.find('.loading-indicator').hide();
                    $row.find('.alt-text-editable').text('Error generating suggestion').show();
                }
            },
            error: function() {
                console.log('DEBUG-FLOW: AJAX error for ID', attachmentId);
                $row.find('.loading-indicator').hide();
                $row.find('.alt-text-editable').text('Error generating suggestion').show();
            }
        });
    }
    
    /**
     * Score original alt text
     */
    function scoreOriginal(attachmentId, $row) {
        const originalAlt = $row.find('.row-current-alt .alt-text').text();
        
        if (!originalAlt || originalAlt === 'Empty') {
            $row.find('.row-score-before').html(getScoreBadge(0));
            return;
        }
        
        // Scoring is now local, so always enabled
        
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_score',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId,
                alt_text: originalAlt
            },
            success: function(response) {
                if (response.success && response.data.score) {
                    const scoreData = response.data.score;
                    const score = scoreData.score || scoreData;
                    $row.find('.row-score-before').html(getScoreBadge(score));
                }
            }
        });
    }
    
    /**
     * Score suggested alt text
     */
    function scoreSuggested(attachmentId, altText, $row) {
        // Scoring is now local, so always enabled
        
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_score',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId,
                alt_text: altText
            },
            success: function(response) {
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
     * Delete unused image
     */
    function deleteImage(attachmentId, $row) {
        console.log('FEATURE-DELETE: Deleting image ID:', attachmentId);
        $row.find('.action-status').html('<span class="spinner is-active"></span> Deleting...');
        console.log('FEATURE-DELETE: Deleting image ID:', attachmentId);
        console.log('FEATURE-DELETE: AJAX URL:', imageSeoData.ajaxUrl);
        console.log('FEATURE-DELETE: Nonce:', imageSeoData.nonce);
        
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_delete_image',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId
            },
            beforeSend: function(xhr, settings) {
                console.log('FEATURE-DELETE: AJAX request starting...');
                console.log('FEATURE-DELETE: Request data:', settings.data);
            },
            success: function(response) {
                console.log('FEATURE-DELETE: AJAX success response:', response);
                console.log('FEATURE-DELETE: response.success:', response.success);
                console.log('FEATURE-DELETE: response.data:', response.data);
                
                if (response.success) {
                    console.log('FEATURE-DELETE: Backend confirmed deletion');
                    
                    // Remove from scannedImages array
                    scannedImages = scannedImages.filter(img => img.id !== attachmentId);
                    console.log('FEATURE-DELETE: Removed from scannedImages array. New count:', scannedImages.length);
                    
                    // Remove row from UI
                    $row.fadeOut(300, function() {
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
            error: function(xhr, status, error) {
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
            success: function(response) {
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
        console.log('========================================');
        console.log('APPLY-DEBUG: [Frontend] ===== applyAltText() CALLED =====');
        console.log('APPLY-DEBUG: [Frontend] Attachment ID:', attachmentId);
        console.log('APPLY-DEBUG: [Frontend] Alt text to apply:', altText);
        console.log('APPLY-DEBUG: [Frontend] Row data-status:', $row.data('status'));
        console.log('APPLY-DEBUG: [Frontend] Row is-optimized:', $row.data('is-optimized'));
        console.log('APPLY-DEBUG: [Frontend] Button text:', $btn.text());
        console.log('APPLY-DEBUG: [Frontend] Button disabled?', $btn.prop('disabled'));
        console.log('========================================');
        
        // CRITICAL SAFETY CHECK: Don't allow applying to already-optimized images
        if ($row.data('is-optimized') === true) {
            console.log('\u274c APPLY-ERROR-DEBUG: [Frontend] ===== BLOCKING RE-APPLY =====');
            console.log('\u274c APPLY-ERROR-DEBUG: [Frontend] Attempting to apply to ALREADY OPTIMIZED image!');
            console.log('\u274c APPLY-ERROR-DEBUG: [Frontend] Image ID:', attachmentId, 'Status:', $row.data('status'));
            console.log('\u274c APPLY-ERROR-DEBUG: [Frontend] This should NOT happen - button should be disabled!');
            console.log('\u274c APPLY-ERROR-DEBUG: [Frontend] ===== ACTION BLOCKED =====');
            showToast('This image is already optimized!', 'error');
            return;
        }
        
        // Get the current "After" score from the row
        const $scoreAfter = $row.find('.row-score-after .score-badge');
        const scoreText = $scoreAfter.text().trim();
        const currentScore = parseInt(scoreText) || 0;
        
        console.log('APPLY-DEBUG: [Frontend] Current inline SEO score:', currentScore);
        
        // UX-IMPROVEMENT: Check if this is UNMODIFIED AI-generated text
        const originalAiSuggestion = $row.data('ai-suggestion');
        const isUnmodifiedAi = (originalAiSuggestion && altText === originalAiSuggestion);
        
        console.log('========================================');
        console.log('AI-VALIDATION-SKIP-DEBUG: [Frontend] Checking if AI text is unmodified');
        console.log('AI-VALIDATION-SKIP-DEBUG: [Frontend] Original AI suggestion:', originalAiSuggestion);
        console.log('AI-VALIDATION-SKIP-DEBUG: [Frontend] Current alt text:', altText);
        console.log('AI-VALIDATION-SKIP-DEBUG: [Frontend] Is unmodified AI?', isUnmodifiedAi);
        console.log('========================================');
        
        // LOGIC SPLIT: Low score vs High score
        if (currentScore < 75) {
            console.log('APPLY-DEBUG: [Frontend] Score < 75 - Applying WITHOUT removal');
            console.log('APPLY-DEBUG: [Frontend] Image will stay in table (still has issues)');
            
            // LOW SCORE: Apply but don't mark as optimized, keep in table
            applyAltTextLowScore(attachmentId,altText, $row, $btn, currentScore);
            
        } else {
            // HIGH SCORE: Check if validation is needed
            if (isUnmodifiedAi) {
                console.log('========================================');
                console.log('✅ AI-VALIDATION-SKIP-DEBUG: [Frontend] SKIPPING VALIDATION');
                console.log('✅ AI-VALIDATION-SKIP-DEBUG: [Frontend] Reason: User applied unmodified AI');
                console.log('✅ AI-VALIDATION-SKIP-DEBUG: [Frontend] Trusting AI, no 70% check needed');
                console.log('========================================');
                
                // SKIP VALIDATION: Call validation function but it will short-circuit
                applyAltTextWithValidation(attachmentId, altText, $row, $btn, currentScore, true);
            } else {
                console.log('APPLY-DEBUG: [Frontend] Score >= 75 - Triggering AI validation');
                console.log('APPLY-DEBUG: [Frontend] Reason: User edited or typed manually');
                console.log('APPLY-DEBUG: [Frontend] Need to verify alt text matches image');
                
                // USER EDITED: Validate with AI before accepting
                applyAltTextWithValidation(attachmentId, altText, $row, $btn, currentScore, false);
            }
        }
    }
    
    /**
     * Apply low-score alt text (no validation, keep in table)
     */
    function applyAltTextLowScore(attachmentId, altText, $row, $btn, score) {
        console.log('LOW-SCORE-APPLY: [Frontend] Applying low-score alt text');
        
        // Disable button
        $btn.prop('disabled', true).text('Applying...');
        
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_apply',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId,
                alt_text: altText
            },
            success: function(response) {
                if (response.success) {
                    console.log('LOW-SCORE-APPLY: [Frontend] Applied successfully');
                    
                    // Show toast
                    showToast(`Alt text applied (Score: ${score} - Low quality)`, 'success');
                    
                    // Re-enable button
                    $btn.prop('disabled', false).text('Apply');
                    
                    // DON'T remove row - it stays in table because score is still low
                    // DON'T increment optimized count
                    // On next scan, this image will appear again
                    
                    console.log('LOW-SCORE-APPLY: [Frontend] Row kept in table (still has issues)');
                    console.log('LOW-SCORE-APPLY: [Frontend] Image will reappear on next scan');
                    
                } else {
                    showToast('✕ Failed to apply: ' + (response.data.message || 'Unknown error'), 'error');
                    $btn.prop('disabled', false).text('Apply');
                }
            },
            error: function() {
                showToast('✕ Network error occurred', 'error');
                $btn.prop('disabled', false).text('Apply');
            }
        });
    }
    
    /**
     * Apply high-score alt text with AI validation
     */
    function applyAltTextWithValidation(attachmentId, altText, $row, $btn, score) {
        console.log('AI-VALIDATION-APPLY: [Frontend] Starting AI validation');
        
        // Check if API key exists
        if (!imageSeoData.hasApiKey) {
            console.log('AI-VALIDATION-APPLY: [Frontend] No API key - falling back to direct apply');
            // No API key: Just apply and accept it
            applyAltTextDirect(attachmentId, altText, $row, $btn, score);
            return;
        }
        
        // Disable button and show validating state
        $btn.prop('disabled', true).text('Validating...');
        
        console.log('AI-VALIDATION-APPLY: [Frontend] Calling AI validation API');
        
        // Call AI validation
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_validate_alt_text',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId,
                alt_text: altText
            },
            success: function(response) {
                console.log('AI-VALIDATION-APPLY: [Frontend] Validation response:', response);
                
                if (response.success) {
                    const validation = response.data;
                    
                    console.log('AI-VALIDATION-APPLY: [Frontend] Match percentage:', validation.match_percentage + '%');
                    console.log('AI-VALIDATION-APPLY: [Frontend] Is valid:', validation.is_valid);
                    console.log('AI-VALIDATION-APPLY: [Frontend] Reasoning:', validation.reasoning);
                    
                    if (validation.is_valid) {
                        // ✓ AI APPROVED: Alt text matches image
                        console.log('AI-VALIDATION-APPLY: [Frontend] ✓ AI APPROVED - Accepting as optimized');
                        showToast(`✓ AI validated (${validation.match_percentage}% match) - Marked as optimized!`, 'success');
                        
                        // Apply and remove from table
                        applyAltTextDirect(attachmentId, altText, $row, $btn, score);
                        
                    } else {
                        // ✗ AI REJECTED: Alt text doesn't match image
                        console.log('AI-VALIDATION-APPLY: [Frontend] ✗ AI REJECTED - Downgrading score');
                        
                        showToast(`✗ AI detected mismatch (${validation.match_percentage}% match): ${validation.reasoning}`, 'error');
                        
                        // Update "After" score to show it's bad
                        $row.find('.row-score-after').html(getScoreBadge(25));
                        
                        // Re-enable button
                        $btn.prop('disabled', false).text('Apply');
                        
                        // DON'T apply, DON'T remove row
                        // User needs to fix the alt text
                        console.log('AI-VALIDATION-APPLY: [Frontend] Alt text NOT applied - user must fix it');
                    }
                } else {
                    console.log('AI-VALIDATION-APPLY: [Frontend] Validation failed:', response.data.message);
                    showToast('Validation failed: ' + response.data.message, 'error');
                    $btn.prop('disabled', false).text('Apply');
                }
            },
            error: function() {
                console.log('AI-VALIDATION-APPLY: [Frontend] Validation API error - falling back to direct apply');
                showToast('Validation unavailable - applying anyway', 'success');
                
                // On network error, accept it anyway
                applyAltTextDirect(attachmentId, altText, $row, $btn, score);
            }
        });
    }
    
    /**
     * Apply alt text directly (validation passed or not required)
     */
    function applyAltTextDirect(attachmentId, altText, $row, $btn, score) {
        console.log('DIRECT-APPLY: [Frontend] Applying alt text directly (validated or no validation needed)');
        
        // Update button
        $btn.prop('disabled', true).text('Applying...');
        
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_apply',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId,
                alt_text: altText
            },
            success: function(response) {
                if (response.success) {
                    console.log('DIRECT-APPLY: [Frontend] Applied successfully');
                    
                    // Show success toast
                    showToast('✓ Alt text applied and marked as optimized!', 'success');
                    
                    // Update stats dynamically
                    if (globalStats) {
                        // Decrease the appropriate issue count
                        const issueType = $row.attr('data-issue-type');
                        if (issueType === 'empty' && globalStats.empty > 0) {
                            globalStats.empty--;
                        } else if (issueType === 'generic' && globalStats.generic > 0) {
                            globalStats.generic--;
                        }
                        // Increase optimized count
                        globalStats.optimized++;
                        // Update the display
                        updateStats(globalStats);
                    }
                    
                    // Remove row with fade animation
                    $row.fadeOut(400, function() {
                        $(this).remove();
                        
                        console.log('DIRECT-APPLY: [Frontend] Row removed from table');
                        
                        // Check if no rows left
                        if ($resultsTbody.find('tr').length === 0) {
                            $resultsTable.hide();
                            $filtersSection.hide();
                            $emptyState.find('h2').text('All Done!');
                            $emptyState.find('p').text('All images have been processed.');
                            $emptyState.show();
                        }
                    });
                } else {
                    showToast('✕ Failed to apply: ' + (response.data.message || 'Unknown error'), 'error');
                    $btn.prop('disabled', false).text('Apply');
                }
            },
            error: function() {
                showToast('✕ Network error occurred', 'error');
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
            success: function(response) {
                if (response.success) {
                    console.log('FEATURE-EMAIL: Email sent successfully');
                    showToast('CSV emailed to ' + imageSeoData.adminEmail, 'success');
                } else {
                    console.log('FEATURE-EMAIL: Email failed:', response.data.message);
                    showToast('Failed to send email: ' + response.data.message, 'error');
                }
            },
            error: function() {
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
        $row.fadeOut(400, function() {
            $(this).remove();
            
            console.log('SKIP-DEBUG: [Frontend] Row removed from table');
            console.log('SKIP-DEBUG: [Frontend] Image will reappear on next scan if still has issues');
            
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
    function bulkApplyHighConfidence() {
        const $highConfRows = $resultsTbody.find('tr[data-score]').filter(function() {
            return parseInt($(this).attr('data-score')) >= 80 && !$(this).hasClass('applied');
        });
        
        if ($highConfRows.length === 0) {
            showToast('No high confidence suggestions to apply', 'error');
            return;
        }
        
        if (!confirm(`Apply ${$highConfRows.length} high confidence suggestions?`)) {
            return;
        }
        
        const changes = [];
        $highConfRows.each(function() {
            const $row = $(this);
            changes.push({
                attachment_id: $row.attr('data-attachment-id'),
                alt_text: $row.find('.alt-text-editable').text().trim()
            });
        });
        
        $bulkApplyBtn.prop('disabled', true).text('Applying...');
        
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_bulk_apply',
                nonce: imageSeoData.nonce,
                changes: JSON.stringify(changes)
            },
            success: function(response) {
                if (response.success) {
                    showToast(`✓ Successfully applied ${response.data.applied} changes!`, 'success');
                    
                    // Remove rows with animation
                    $highConfRows.each(function() {
                        const $row = $(this);
                        $row.fadeOut(400, function() {
                            $(this).remove();
                            
                            // Check if no rows left
                            if ($resultsTbody.find('tr').length === 0) {
                                $resultsTable.hide();
                                $filtersSection.hide();
                                // Export button now always visible
                                $emptyState.find('h2').text('All Done!');
                                $emptyState.find('p').text('All images have been processed.');
                                $emptyState.show();
                            }
                        });
                    });
                    
                    // Stats remain static - no need to update
                } else {
                    showToast('✕ Bulk apply failed', 'error');
                }
                
                $bulkApplyBtn.prop('disabled', false).text('Apply All High Confidence');
            },
            error: function() {
                showToast('✕ Network error occurred', 'error');
                $bulkApplyBtn.prop('disabled', false).text('Apply All High Confidence');
            }
        });
    }
    
    /**
     * Calculate statistics
     * Note: Stats are now static from backend - don't recalculate
     */
    function calculateStats() {
        // Simply return the global stats from backend
        // Don't try to recalculate based on DOM state
        return globalStats;
    }
    
    /**
     * Update statistics display
     */
    function updateStats(stats) {
        console.log('STATS-DEBUG: [Frontend] ===== UPDATE STATS =====');
        console.log('STATS-DEBUG: [Frontend] Stats received:', stats);
        console.log('STATS-DEBUG: [Frontend] stats.total:', stats.total);
        console.log('STATS-DEBUG: [Frontend] stats.low_score_empty:', stats.low_score_empty);
        console.log('STATS-DEBUG: [Frontend] stats.low_score_with_alt:', stats.low_score_with_alt);
        console.log('STATS-DEBUG: [Frontend] stats.optimized:', stats.optimized);
        
        $('#stat-total').text(stats.total);
        $('#stat-low-empty').text(stats.low_score_empty);
        $('#stat-low-with-alt').text(stats.low_score_with_alt);
        
        // FIX: Use correct ID - HTML has "stat-fixed" not "stat-optimized"
        $('#stat-fixed').text(stats.optimized || 0);
        console.log('STATS-DEBUG: [Frontend] Updated #stat-fixed with optimized count:', stats.optimized || 0);
        
        console.log('STATS-DEBUG: [Frontend] Stats updated successfully!');
        
        // Disable action buttons when no images exist
        if (stats.total === 0) {
            console.log('STATS-UPDATE: 0 images - disabling action buttons');
            $('#export-csv').prop('disabled', true).addClass('disabled');
            $('#email-csv').prop('disabled', true).addClass('disabled');
            $('#bulk-delete-btn').prop('disabled', true).addClass('disabled');
        } else {
            console.log('STATS-UPDATE: Images exist - enabling action buttons');
            $('#export-csv').prop('disabled', false).removeClass('disabled');
            $('#email-csv').prop('disabled', false).removeClass('disabled');
            $('#bulk-delete-btn').prop('disabled', false).removeClass('disabled');
        }
    }
    
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
     */
    function filterResults(filterType) {
        const $rows = $resultsTbody.find('tr');
        
        if (filterType === 'all') {
            $rows.show();
        } else if (filterType === 'optimized') {
            // Optimized means no rows should be shown since table only contains issues
            $rows.hide();
            // Show message if no optimized in table
            if ($rows.length > 0) {
                showToast('ℹ Optimized images are not shown in the issues list', 'success');
            }
        } else {
            // Show only rows matching the filter
            $rows.each(function() {
                const $row = $(this);
                const issueType = $row.attr('data-issue-type');
                
                if (issueType === filterType) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        }
    }
    
    /**
     * Export results to CSV
     */
    function exportToCSV() {
        console.log('FEATURE-DEBUG: === exportToCSV() called ===');
        console.log('FEATURE-DEBUG: This is where EMAIL option will be added');
        console.log('FEATURE-DEBUG: Current flow: Download CSV directly');
        console.log('FEATURE-DEBUG: New flow needed: Ask user Download vs Email');
        console.log('FEATURE-EMAIL: Step 1 - User clicked Export button');
        console.log('FEATURE-EMAIL: Step 2 - Will show modal: Download or Email?');
        console.log('FEATURE-EMAIL: Step 3a - If Download: Current flow continues');
        console.log('FEATURE-EMAIL: Step 3b - If Email: Call new AJAX action for emailing');
        console.log('=== EXPORT TO CSV ===');
        console.log('Initiating CSV export Ajax request...');
        
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
            success: function(response) {
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
                    showToast(`✓ Exported ${response.data.count} records`, 'success');
                } else {
                    console.error('CSV export failed:', response.data);
                    showToast('✕ ' + (response.data.message || 'No change history found'), 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('CSV export Ajax error:', {xhr, status, error});
                showToast('✕ Export failed', 'error');
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
    
    /**
     * Place caret at end of contenteditable
     */
    function placeCaretAtEnd(el) {
        el.focus();
        if (typeof window.getSelection != "undefined" && typeof document.createRange != "undefined") {
            const range = document.createRange();
            range.selectNodeContents(el);
            range.collapse(false);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }
    }
    
    /**
     * Generate AI suggestions for filtered images - REUSES INDIVIDUAL BUTTON LOGIC
     */
    function generateFilteredSuggestions(filteredImages, buttonLabel) {
        console.log('GEN-FILTERED-DEBUG: Starting generation for', filteredImages.length, 'images');
        console.log('GEN-FILTERED-DEBUG: Button:', buttonLabel);
        
        if (!filteredImages || filteredImages.length === 0) {
            showToast('No images to generate', 'error');
            return;
        }
        
        // Show progress
        showToast(`Generating AI suggestions for ${filteredImages.length} images...`, 'success');
        
        // Process each image
        let processed = 0;
        let succeeded = 0;
        let failed = 0;
        
        filteredImages.forEach((image, index) => {
            const attachmentId = image.id;
            const $row = $(`tr[data-attachment-id="${attachmentId}"]`);
            
            if ($row.length === 0) {
                console.log('GEN-FILTERED-DEBUG: Row not found for image:', attachmentId);
                processed++;
                failed++;
                return;
            }
            
            // Show loading
            $row.find('.alt-text-editable').html('');
            $row.find('.loading-indicator').show();
            
            // Make AJAX call to generate (SAME AS INDIVIDUAL BUTTON!)
            $.ajax({
                url: imageSeoData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'imageseo_generate',
                    nonce: imageSeoData.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    processed++;
                    
                    if (response.success && response.data.alt_text) {
                        succeeded++;
                        const altText = response.data.alt_text;
                        
                        // CRITICAL: Update scannedImages array so text persists across filter changes
                        const imageIndex = scannedImages.findIndex(img => img.id === attachmentId);
                        if (imageIndex !== -1) {
                            scannedImages[imageIndex].ai_suggestion = altText;
                            console.log('GEN-FILTERED-DEBUG: Updated scannedImages array for image', attachmentId);
                        }
                        
                        // Update row (SAME AS INDIVIDUAL BUTTON!)
                        $row.find('.loading-indicator').hide();
                        $row.find('.alt-text-editable').text(altText).show();
                        $row.find('.char-count').text(altText.length);
                        
                        // Enable Apply button
                        $row.find('.apply-btn').prop('disabled', false);
                        
                        // Store AI suggestion for validation skip
                        $row.data('ai-suggestion', altText);
                        
                        // Score BOTH original and suggested (SAME AS INDIVIDUAL BUTTON!)
                        scoreOriginal(attachmentId, $row);
                        scoreSuggested(attachmentId, altText, $row);
                        
                        console.log('GEN-FILTERED-DEBUG: Success for image', attachmentId);
                    } else {
                        failed++;
                        $row.find('.loading-indicator').hide();
                        $row.find('.alt-text-editable').text('Error generating suggestion').show();
                        console.log('GEN-FILTERED-DEBUG: Failed for image', attachmentId);
                    }
                    
                    // Check if all done
                    if (processed === filteredImages.length) {
                        showToast(`Generation complete! Success: ${succeeded}, Failed: ${failed}`, 'success');
                    }
                },
                error: function() {
                    processed++;
                    failed++;
                    $row.find('.loading-indicator').hide();
                    $row.find('.alt-text-editable').text('Error generating suggestion').show();
                    
                    if (processed === filteredImages.length) {
                        showToast(`Generation complete! Success: ${succeeded}, Failed: ${failed}`, 'success');
                    }
                }
            });
            
            // Add small delay between requests
            setTimeout(() => {}, index * 100);
        });
    }
    
})
