/**
 * Broken URL Management - Frontend JavaScript
 */

(function($) {
    'use strict';
    
    // State management
    let currentScanId = null;
    let currentFilter = 'all';
    let currentSearch = '';
    let currentPage = 1;
    let isScanning = false;
    let scanProgressInterval = null;
    
    // Initialize on document ready
    $(document).ready(function() {
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
        // Start scan button
        $('#start-scan-btn').on('click', startNewScan);
        
        // View latest scan button
        $('#view-latest-scan-btn').on('click', function() {
            const scanId = $(this).data('scan-id');
            loadScanResults(scanId);
        });
        
        // Scan history dropdown
        $('#scan-history-select').on('change', function() {
            const scanId = $(this).val();
            if (scanId) {
                loadScanResults(scanId);
            }
        });
        
        // Filter buttons
        $('.seoautofix-filter-btn').on('click', function() {
            $('.seoautofix-filter-btn').removeClass('active');
            $(this).addClass('active');
            currentFilter = $(this).data('filter');
            currentPage = 1;
            loadScanResults(currentScanId);
        });
        
        // Search input with debounce
        let searchTimeout;
        $('#search-results').on('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                currentSearch = $('#search-results').val();
                currentPage = 1;
                loadScanResults(currentScanId);
            }, 500);
        });
        
        // Select all checkbox
        $('#select-all-results').on('change', function() {
            $('.result-checkbox').prop('checked', $(this).is(':checked'));
        });
        
        // Apply selected fixes button
        $('#apply-selected-fixes-btn').on('click', applySelectedFixes);
        
        // Export to CSV button
        $('#export-results-btn').on('click', exportToCSV);
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
            success: function(response) {
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
            error: function(jqXHR, textStatus, errorThrown) {
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
            success: function(response) {
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
                    
                    // Load current results if any broken links found  
                    loadScanResults(currentScanId);
                    
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
            error: function(jqXHR, textStatus, errorThrown) {
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
        
        setTimeout(function() {
            $('#scan-progress-container').hide();
            loadScanResults(currentScanId);
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
        currentScanId = scanId;
        
        $.ajax({
            url: seoautofixBrokenUrls.ajaxUrl,
            method: 'GET',
            data: {
                action: 'seoautofix_broken_links_get_results',
                nonce: seoautofixBrokenUrls.nonce,
                scan_id: scanId,
                filter: currentFilter,
                search: currentSearch,
                page: currentPage,
                per_page: 25
            },
            success: function(response) {
                if (response.success) {
                    displayResults(response.data);
                } else {
                    alert(response.data.message || seoautofixBrokenUrls.strings.error);
                }
            },
            error: function() {
                alert(seoautofixBrokenUrls.strings.error);
            }
        });
    }
    
    /**
     * Display results in table
     */
    function displayResults(data) {
        const results = data.results;
        const total = data.total;
        
        // Update counts
        $('#total-broken-count').text(total);
        
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
        results.forEach(function(result, index) {
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
        const typeClass = result.link_type === 'internal' ? 'internal' : 'external';
        const isFixed = result.is_fixed == 1;
        
        let suggestedUrlHtml;
        if (result.link_type === 'external') {
            suggestedUrlHtml = '<input type="text" class="suggested-url-input" data-id="' + result.id + '" value="" placeholder="Enter new URL or delete this entry" />';
        } else {
            const displayUrl = result.user_modified_url || result.suggested_url || '';
            suggestedUrlHtml = '<div class="suggested-url-editable" data-id="' + result.id + '" data-url="' + escapeHtml(displayUrl) + '">' +
                '<span class="url-display">' + escapeHtml(displayUrl) + '</span>' +
                '</div>';
        }
        
        const row = $('<tr></tr>');
        
        if (isFixed) {
            row.addClass('status-fixed');
        }
        
        row.html(
            '<td class="check-column">' +
                (isFixed ? '<span class="status-fixed">Fixed</span>' : '<input type="checkbox" class="result-checkbox" data-id="' + result.id + '" />') +
            '</td>' +
            '<td>' + serialNumber + '</td>' +
            '<td><span class="link-type-badge ' + typeClass + '">' + result.link_type + '</span></td>' +
            '<td><span class="url-display">' + escapeHtml(result.broken_url) + '</span></td>' +
            '<td>' + suggestedUrlHtml + '</td>' +
            '<td><span class="reason-text">' + escapeHtml(result.reason) + '</span></td>' +
            '<td class="column-actions">' +
                '<span class="dashicons dashicons-trash delete-entry-btn" data-id="' + result.id + '" title="Delete"></span>' +
            '</td>'
        );
        
        return row;
    }
    
    /**
     * Update pagination
     */
    function updatePagination(data) {
        const container = $('#pagination-container');
        container.empty();
        
        if (data.pages <= 1) {
            return;
        }
        
        const paginationHtml = $('<div class="pagination-buttons"></div>');
        
        // Previous button
        const prevBtn = $('<button class="pagination-btn">Previous</button>');
        if (data.current_page <= 1) {
            prevBtn.prop('disabled', true);
        } else {
            prevBtn.on('click', function() {
                currentPage = data.current_page - 1;
                loadScanResults(currentScanId);
            });
        }
        paginationHtml.append(prevBtn);
        
        // Page numbers (simplified - just show current page)
        for (let i = 1; i <= data.pages; i++) {
            if (i === 1 || i === data.pages || (i >= data.current_page - 1 && i <= data.current_page + 1)) {
                const pageBtn = $('<button class="pagination-btn">' + i + '</button>');
                if (i === data.current_page) {
                    pageBtn.addClass('active');
                } else {
                    pageBtn.on('click', function() {
                        currentPage = i;
                        loadScanResults(currentScanId);
                    });
                }
                paginationHtml.append(pageBtn);
            } else if (i === data.current_page - 2 || i === data.current_page + 2) {
                paginationHtml.append('<span>...</span>');
            }
        }
        
        // Next button
        const nextBtn = $('<button class="pagination-btn">Next</button>');
        if (data.current_page >= data.pages) {
            nextBtn.prop('disabled', true);
        } else {
            nextBtn.on('click', function() {
                currentPage = data.current_page + 1;
                loadScanResults(currentScanId);
            });
        }
        paginationHtml.append(nextBtn);
        
        container.append(paginationHtml);
        
        // Pagination info
        const info = $('<div class="pagination-info"></div>');
        info.text('Showing ' + ((data.current_page - 1) * data.per_page + 1) + ' to ' + Math.min(data.current_page * data.per_page, data.total) + ' of ' + data.total + ' results');
        container.prepend(info);
    }
    
    /**
     * Update filter counts
     */
    function updateFilterCounts(data) {
        $('#filter-count-all').text(data.total);
        // Note: For accurate counts per type, we'd need separate API calls
        // For now, we'll just update based on current view
    }
    
    /**
     * Handle inline URL editing
     */
    $(document).on('click', '.suggested-url-editable', function() {
        const $this = $(this);
        const id = $this.data('id');
        const currentUrl = $this.data('url');
        
        const input = $('<input type="text" class="suggested-url-input" />');
        input.val(currentUrl);
        input.data('id', id);
        
        $this.replaceWith(input);
        input.focus();
        
        // Handle blur (save)
        input.on('blur', function() {
            saveEditedUrl($(this));
        });
        
        // Handle Enter key
        input.on('keypress', function(e) {
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
            success: function(response) {
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
    $(document).on('click', '.delete-entry-btn', function() {
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
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(function() {
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
        $('.result-checkbox:checked').each(function() {
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
            success: function(response) {
                $('#apply-selected-fixes-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Apply Selected Fixes');
                
                if (response.success) {
                    alert('Fixed: ' + response.data.fixed_count + '\nFailed: ' + response.data.failed_count);
                    loadScanResults(currentScanId);
                } else {
                    alert(response.data.message || 'Failed to apply fixes');
                }
            },
            error: function() {
                $('#apply-selected-fixes-btn').prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Apply Selected Fixes');
                alert(seoautofixBrokenUrls.strings.error);
            }
        });
    }
    
    /**
     * Export to CSV
     */
    function exportToCSV() {
        // Simple CSV export - in production, you'd want server-side generation
        alert('CSV export feature coming soon!');
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
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
})(jQuery);
