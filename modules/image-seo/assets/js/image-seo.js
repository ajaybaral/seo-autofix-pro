/**
 * Image SEO Module - Admin JavaScript
 */

jQuery(document).ready(function($) {
    // Global state
    let scannedImages = [];
    let globalStats = null;
    let currentPage = 1;
    let itemsPerPage = 50;
    let currentFilter = 'all'; // For stat card filtering
    
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
        setTimeout(() => {
            $toast.removeClass('show');
            setTimeout(() => $toast.remove(), 300);
        }, 3000);
    }
    
    // Scan Images
    $scanBtn.on('click', function() {
        scanImages();
    });
    
    // Bulk Apply
    $bulkApplyBtn.on('click', function() {
        bulkApplyHighConfidence();
    });
    
    // Generate AI for All Images
    $('#generate-all-btn').on('click', function() {
        console.log('DEBUG-FLOW: Generate All button clicked');
        if (!imageSeoData.hasApiKey) {
            showToast('API key not configured', 'error');
            return;
        }
        
        if (!confirm(`Generate AI suggestions for all ${scannedImages.length} images? This may take some time and use API credits.`)) {
            return;
        }
        
        generateAllSuggestions();
        showToast('Generating AI suggestions...', 'success');
    });
    
    // Generate AI for Post/Page Images Only
    $('#generate-postpage-btn').on('click', function() {
        console.log('DEBUG-FLOW: Generate Post/Page button clicked');
        if (!imageSeoData.hasApiKey) {
            showToast('API key not configured', 'error');
            return;
        }
        
        generatePostPageSuggestions();
    });
    
    // Filter radio buttons
    $('input[name="image-filter"]').on('change', function() {
        const filterValue = $(this).val();
        console.log('FEATURE: Filter changed to:', filterValue);
        
        if (filterValue === 'all') {
            // Show all images
            $resultsTbody.find('tr').show();
        } else if (filterValue === 'post_page') {
            // Show only images used in posts or pages
            $resultsTbody.find('tr').each(function() {
                const $row = $(this);
                const attachmentId = $row.data('attachment-id');
                const image = scannedImages.find(img => img.id == attachmentId);
                
                if (image) {
                    const isUsed = (image.used_in_posts > 0 || image.used_in_pages > 0);
                    if (isUsed) {
                        $row.show();
                    } else {
                        $row.hide();
                    }
                }
            });
        }
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
    
    // FILTER-CLICK-DEBUG: Subcategory filter click handlers
    console.log('FILTER-CLICK-DEBUG: Setting up click handlers for .stat-subcard.clickable');
    
    $(document).on('click', '.stat-subcard.clickable', function() {
        console.log('FILTER-CLICK-DEBUG: ===== FILTER CLICKED =====');
        const filter = $(this).data('filter');
        console.log('FILTER-CLICK-DEBUG: Filter type:', filter);
        console.log('FILTER-CLICK-DEBUG: Clicked element:', this);
        
        // Toggle active state
        $('.stat-subcard').removeClass('active');
        $(this).addClass('active');
        console.log('FILTER-CLICK-DEBUG: Active class toggled');
        
        console.log('FILTER-CLICK-DEBUG: Total scanned images available:', scannedImages.length);
        console.log('FILTER-CLICK-DEBUG: Sample scanned image:', scannedImages[0]);
        
        // Filter images based on selection
        let filteredImages = [];
        if (filter === 'empty') {
            console.log('FILTER-CLICK-DEBUG: Filtering for EMPTY alt images');
            filteredImages = scannedImages.filter(img => {
                const isEmpty = img.issue_type === 'empty';
                if (isEmpty) console.log('FILTER-CLICK-DEBUG: Found empty image ID:', img.id);
                return isEmpty;
            });
        } else if (filter === 'low-with-alt') {
            console.log('FILTER-CLICK-DEBUG: Filtering for LOW SCORE images WITH alt text');
            filteredImages = scannedImages.filter(img => {
                const hasAltLowScore = img.issue_type !== 'empty' && img.current_alt && img.current_alt.trim() !== '';
                if (hasAltLowScore) console.log('FILTER-CLICK-DEBUG: Found low-score with alt, ID:', img.id, 'alt:', img.current_alt);
                return hasAltLowScore;
            });
        }
        
        console.log('FILTER-CLICK-DEBUG: Filtered result count:', filteredImages.length);
        console.log('FILTER-CLICK-DEBUG: Calling renderResults with filtered images');
        
        // Re-render with filtered images
        renderResults(filteredImages);
    });
    
    console.log('FILTER-CLICK-DEBUG: Click handlers setup complete');
    
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
     * Scan all images
     */
    function scanImages() {
        scannedImages = [];
        currentPage = 1; // Reset to first page on new scan
        $resultsTbody.empty();
        
        // Show progress, hide results
        $scanProgress.show();
        $emptyState.hide();
        $resultsTable.hide();
        $statsSection.hide();
        $filtersSection.hide();
        // Export button now always visible
        
        // Disable scan button
        $scanBtn.prop('disabled', true).text('Scanning...');
        
        // Start scanning
        scanBatch(0);
    }
    
    /**
     * Scan a batch of images
     */
    function scanBatch(offset = 0) {
        console.log('=== SCAN BATCH START ===');
        console.log('Offset:', offset);
        console.log('Current scannedImages count:', scannedImages.length);
        
        $.ajax({
            url: imageSeoData.ajaxUrl, // Changed from imageSecureAjax.ajaxUrl to imageSeoData.ajaxUrl to match existing pattern
            type: 'POST',
            data: {
                action: 'imageseo_scan', // Changed from imageseo_scan_images to imageseo_scan to match existing pattern
                nonce: imageSeoData.nonce,
                batch_size: 50,
                offset: offset
            },
            success: function(response) {
                console.log('Response received:', response);
                
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
                        console.log('Has more batches, continuing scan...');
                        scanBatch(response.data.offset); // Use response.data.offset for next batch
                    } else {
                        console.log('No more batches, finishing scan...');
                        console.log('Calling renderResults with', scannedImages.length, 'images');
                        console.log('Global stats:', globalStats);
                        renderResults(scannedImages);
                        updateStats(globalStats);
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
    function renderResults(images) {
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
            console.log('NO-API-KEY-DEBUG: No images to render - showing empty message');
            showEmptyState();
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
        
        // Render only current page images
        pageImages.forEach((image, index) => {
            const rowNumber = startIndex + index + 1;
            addResultRow(image, rowNumber);
        });
        
        renderPagination();

        console.log('DEBUG-FLOW: AI generation is now MANUAL only');
        console.log('DEBUG-FLOW: Showing manual input placeholder for all rows');
        
        // NO AUTOMATIC AI GENERATION - Show manual input placeholder for all rows
        $resultsTbody.find('tr').each(function() {
            const $row = $(this);
            $row.find('.loading-indicator').hide();
            
            // Set placeholder text based on API key availability
            const placeholderText = imageSeoData.hasApiKey 
                ? '<em style="color: #999;">Click on image to generate AI suggestions or type manually</em>'
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
                    if ($(this).html().includes('Click on image')) {
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
     * Render pagination controls
     */
    function renderPagination() {
        console.log('FEATURE-DEBUG: === renderPagination() called ===');
        // The instruction refers to `createRow` but the context matches `renderPagination`.
        // Assuming `createRow` in the instruction was a typo for `renderPagination`.
        // If `createRow` is meant to be a new function, please clarify.
        // For now, adding debug logs to `renderPagination` as per context.
        // console.log('FEATURE-DEBUG: Creating row for image ID:', image.id); // This line would need `image` and `index` parameters
        // console.log('FEATURE-DEBUG: Usage data for this image:', { // This line would need `image` parameter
        //     used_in_posts: image.used_in_posts || 0,
        //     used_in_pages: image.used_in_pages || 0,
        //     is_unused: (!image.used_in_posts && !image.used_in_pages)
        // });
        // console.log('FEATURE-DEBUG: DELETE button should appear for unused images here'); // This line would need `image` parameter
        const totalPages = Math.ceil(scannedImages.length / itemsPerPage);
        
        if (totalPages <= 1) {
            $('#pagination-controls').remove();
            return;
        }
        
        let paginationHtml = '<div id="pagination-controls" style="margin-top: 20px; text-align: center;">';
        paginationHtml += '<div class="tablenav"><div class="tablenav-pages">';
        paginationHtml += '<span class="displaying-num">' + scannedImages.length + ' items</span>';
        
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
                renderResults(scannedImages);
                $('html, body').animate({scrollTop: $resultsTable.offset().top - 100}, 300);
            }
        });
        
        // Page input change
        $('#current-page-selector').on('change', function() {
            const newPage = parseInt($(this).val());
            if (newPage >= 1 && newPage <= totalPages) {
                currentPage = newPage;
                renderResults(scannedImages);
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
        console.log('FEATURE-DEBUG: Creating row for image ID:', image.id);
        console.log('FEATURE-DEBUG: Usage data for this image:', {
            used_in_posts: image.used_in_posts || 0,
            used_in_pages: image.used_in_pages || 0,
            is_unused: (!image.used_in_posts && !image.used_in_pages)
        });
        console.log('FEATURE-DEBUG: DELETE button should appear for unused images here');
        
        // Determine if delete button should be visible
        const isUnused = (!image.used_in_posts && !image.used_in_pages);
        console.log('FEATURE-DELETE: Image ID', image.id, 'is unused:', isUnused);
        console.log('FEATURE-DELETE: Delete button will be', isUnused ? 'SHOWN' : 'HIDDEN');
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
        
        // Disable Apply button until suggestion is generated
        $row.find('.apply-btn').prop('disabled', true);
        
        // Show loading in suggested column
        $row.find('.loading-indicator').show();
        
        // POPULATE "BEFORE" SEO SCORE WITH DEBUG
        console.log('BEFORE-SCORE-DEBUG: Image ID:', image.id);
        console.log('BEFORE-SCORE-DEBUG: score_before:', image.score_before);
        console.log('BEFORE-SCORE-DEBUG: seo_score:', image.seo_score);
        
        const beforeScore = image.score_before !== undefined ? image.score_before : (image.seo_score || 0);
        $row.find('.row-score-before .score-badge')
            .text(beforeScore)
            .removeClass('score-good score-bad')
            .addClass(beforeScore >= 50 ? 'score-good' : 'score-bad');
        
        console.log('BEFORE-SCORE-DEBUG: Populated Before column with score:', beforeScore);
        
        // Append to table
        $resultsTbody.append($row);
        
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
        
        // Skip button
        $row.find('.skip-btn').on('click', function() {
            skipImage(attachmentId, $row);
        });
        
        // Click on row (except text box and buttons) to generate AI
        $row.on('click', function(e) {
            const $target = $(e.target);
            
            // Don't trigger if clicking on text box, buttons, or if already editing
            if ($target.hasClass('alt-text-editable') || 
                $target.closest('.alt-text-editable').length > 0 ||
                $target.hasClass('button') || 
                $target.closest('button').length > 0 ||
                !$row.hasClass('clickable-for-ai')) {
                console.log('DEBUG-CLICK: Click ignored - user clicked on editable area or button');
                return;
            }
            
            console.log('DEBUG-CLICK: ===== ROW CLICK EVENT FIRED =====');
            console.log('DEBUG-CLICK: Row has clickable-for-ai class:', $row.hasClass('clickable-for-ai'));
            console.log('DEBUG-CLICK: imageSeoData.hasApiKey:', imageSeoData.hasApiKey);
            
            // Only trigger AI if row has clickable class AND API key exists
            if ($row.hasClass('clickable-for-ai') && imageSeoData.hasApiKey) {
                console.log('DEBUG-CLICK: ✅ Generating AI for ID:', attachmentId);
                
                const $editable = $row.find('.alt-text-editable');
                
                // Clear placeholder and show loading
                $editable.html('');
                $row.removeClass('clickable-for-ai');
                $row.find('.loading-indicator').show();
                
                // Generate AI suggestion
                generateSuggestion(attachmentId, $row);
            }
        });
        
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
     * Apply alt text
     */
    function applyAltText(attachmentId, altText, $row, $btn) {
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
                    // Show success toast
                    showToast('✓ Alt text applied successfully!', 'success');
                    
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
                } else {
                    // Show error toast
                    showToast('✕ Failed to apply changes: ' + (response.data.message || 'Unknown error'), 'error');
                    
                    // Re-enable button
                    $btn.prop('disabled', false).text('Apply');
                }
            },
            error: function() {
                // Show error toast
                showToast('✕ Network error occurred', 'error');
                
                // Re-enable button
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
     * Skip image
     */
    function skipImage(attachmentId, $row) {
        $.ajax({
            url: imageSeoData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'imageseo_skip',
                nonce: imageSeoData.nonce,
                attachment_id: attachmentId
            },
            success: function(response) {
                if (response.success) {
                    // Show success toast
                    showToast('Image skipped', 'success');
                    
                    // Update stats dynamically
                    if (globalStats) {
                        const issueType = $row.attr('data-issue-type');
                        if (issueType === 'empty' && globalStats.empty > 0) {
                            globalStats.empty--;
                        } else if (issueType === 'generic' && globalStats.generic > 0) {
                            globalStats.generic--;
                        }
                        updateStats(globalStats);
                    }
                    
                    // Remove row with fade animation
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
                } else {
                    // Show error toast
                    showToast('✕ Failed to skip image: ' + (response.data.message || 'Unknown error'), 'error');
                }
            },
            error: function() {
                // Show error toast
                showToast('✕ Network error occurred', 'error');
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
        console.log('=== UPDATE STATS (NEW STRUCTURE) ===');
        console.log('Stats received:', stats);
        console.log('stats.total:', stats.total);
        console.log('stats.low_score_empty:', stats.low_score_empty);
        console.log('stats.low_score_with_alt:', stats.low_score_with_alt);
        console.log('stats.optimized:', stats.optimized);
        
        $('#stat-total').text(stats.total);
        $('#stat-low-empty').text(stats.low_score_empty);
        $('#stat-low-with-alt').text(stats.low_score_with_alt);
        $('#stat-fixed').text(stats.optimized);
        
        console.log('Stats updated successfully with new structure!');
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
    
});
