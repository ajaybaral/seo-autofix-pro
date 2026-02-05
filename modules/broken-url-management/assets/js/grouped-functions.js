/**
 * Display grouped results (NEW FOR GROUPED VIEW)
 * 
 * FLOW:
 * 1. Clear existing table
 * 2. For each grouped URL:
 *    a. Create parent row with summary
 *    b. Add occurrence count badge
 *    c. Add expand/collapse button
 *    d. Create hidden child row with occurrence details
 * 3. Attach event listeners for expand/collapse
 */
function displayGroupedResults(data) {
    console.log('🎨 [GROUPED DISPLAY] Rendering grouped results');
    console.log('📋 [GROUPED DISPLAY] Results to display:', data.results.length);

    const results = data.results;
    const total = data.total_items;

    // Update header stats
    if (data.stats) {
        updateHeaderStats(data.stats);
    }

    // Always show results container (table and filters)
    $('#results-container').show();
    $('#empty-state').hide();

    // Show download/email buttons when results are available
    $('.history-export-section-header').show();

    // Enable export button when results are available
    $('#export-report-btn').prop('disabled', false).removeClass('disabled');
    console.log('[GROUPED DISPLAY] Export button enabled - results loaded');

    // Clear table
    const tbody = $('#results-table-body');
    tbody.empty();

    // If no results, show message in table
    if (total === 0) {
        console.log('ℹ [GROUPED DISPLAY] No results to display');
        const emptyRow = $('<tr class="empty-results-row"></tr>');
        emptyRow.html(
            '<td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">' +
            '<div style="font-size: 16px; font-weight: 500; margin-bottom: 8px;">No broken links found matching your filters</div>' +
            '<div style="font-size: 14px;">Try adjusting your filter criteria or search term</div>' +
            '</td>'
        );
        tbody.append(emptyRow);

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

    let serialNumber = ((data.current_page - 1) * data.per_page) + 1;

    results.forEach((result, index) => {
        console.log(`  ├─ [GROUPED DISPLAY] Row ${serialNumber}: ${result.broken_url}`);
        console.log(`  │  └─ ${result.occurrence_count} occurrences on pages:`,
            result.occurrences.map(o => o.found_on_page_title).join(', '));

        // Create parent row
        const parentRow = createGroupedRow(result, serialNumber);
        tbody.append(parentRow);

        console.log(`  └─ [GROUPED DISPLAY] Created row for group ${serialNumber}`);

        serialNumber++;
    });

    console.log('✅ [GROUPED DISPLAY] Rendering complete');
    console.log('📊 [GROUPED DISPLAY] Total rows in table:', tbody.find('tr').length);

    // Update button states after displaying results
    updateButtonStates();
}

/**
 * Create grouped row (NEW FOR GROUPED VIEW)
 * 
 * STRUCTURE:
 * - Serial number
 * - Broken URL with expand button
 * - Error type badge
 * - Occurrence count badge (e.g., "15 pages")
 * - First suggested URL (editable)
 * - Actions (Fix All, Skip)
 */
function createGroupedRow(result, serialNumber) {
    console.log(`🔨 [CREATE GROUPED ROW] Creating grouped row ${serialNumber} for: ${result.broken_url}`);
    console.log(`  └─ [CREATE GROUPED ROW] Occurrences: ${result.occurrence_count}`);
    console.log(`  └─ [CREATE GROUPED ROW] Error type: ${result.error_type}`);
    console.log(`  └─ [CREATE GROUPED ROW] Status code: ${result.status_code}`);

    const errorTypeBadge = getErrorTypeBadge(result.error_type, result.status_code);

    // Get location summary
    const locations = getLocationSummary(result.occurrences);
    console.log(`  └─ [CREATE GROUPED ROW] Locations: ${locations}`);

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

    // Prepare occurrence details HTML
    let occurrencesListHtml = '<ul class="occurrences-list">';
    result.occurrences.forEach((occ, index) => {
        console.log(`    ${index + 1}. ${occ.found_on_page_title} (${occ.link_location})`);

        const locationIcon = getLocationIcon(occ.link_location);

        occurrencesListHtml += `
                <li>
                    <strong>${escapeHtml(occ.found_on_page_title || 'Untitled')}</strong>
                    <span class="location-badge">${locationIcon} ${occ.link_location}</span>
                    ${occ.anchor_text ? `<span class="anchor-text">Text: "${escapeHtml(occ.anchor_text)}"</span>` : ''}
                    <a href="${escapeHtml(occ.found_on_url)}" target="_blank" class="view-page">View Page</a>
                </li>
            `;
    });
    occurrencesListHtml += '</ul>';

    const row = $(`
            <tr class="grouped-parent-row" data-group-id="${result.first_id}" data-result='${JSON.stringify(result)}'>
                <td>${serialNumber}</td>
                <td>
                    <button class="expand-toggle" data-group-id="${result.first_id}">
                        <span class="dashicons dashicons-arrow-right"></span>
                    </button>
                    <strong>${escapeHtml(result.broken_url)}</strong>
                    <div class="occurrence-info">
                        <span class="badge badge-info">
                            📍 ${result.occurrence_count} page${result.occurrence_count > 1 ? 's' : ''}
                        </span>
                        <span class="location-summary">${locations}</span>
                    </div>
                    ${suggestedUrlHtml}
                </td>
                <td>
                    <span class="link-type-badge ${result.link_type === 'internal' ? 'badge-internal' : 'badge-external'}">
                        ${result.link_type === 'internal' ? 'Internal' : 'External'}
                    </span>
                </td>
                <td>${errorTypeBadge}</td>
                <td class="column-action">
                    <button class="fix-all-occurrences btn btn-primary" 
                            data-group-id="${result.first_id}"
                            data-broken-url="${escapeHtml(result.broken_url)}"
                            data-entry-ids="${result.entry_ids.join(',')}">
                        Fix All (${result.occurrence_count})
                    </button>
                    <button class="skip-link btn btn-secondary" 
                            data-id="${result.first_id}">
                        Skip
                    </button>
                </td>
            </tr>
            <tr class="occurrences-detail-row" data-group-id="${result.first_id}" style="display: none;">
                <td colspan="6">
                    <div class="occurrences-container">
                        <h4>Found on ${result.occurrence_count} page(s):</h4>
                        ${occurrencesListHtml}
                    </div>
                </td>
            </tr>
        `);

    console.log(`  ✅ [CREATE GROUPED ROW] Row created for group ${result.first_id}`);

    return row;
}

/**
 * Get location summary for display
 */
function getLocationSummary(occurrences) {
    console.log('📍 [LOCATION SUMMARY] Analyzing locations for occurrences');

    const locations = {};
    occurrences.forEach(occ => {
        locations[occ.link_location] = (locations[occ.link_location] || 0) + 1;
    });

    const summary = Object.entries(locations)
        .map(([loc, count]) => `${getLocationIcon(loc)} ${loc} (${count})`)
        .join(', ');

    console.log('  └─ [LOCATION SUMMARY] Location summary:', summary);

    return summary;
}

/**
 * Get location icon
 */
function getLocationIcon(location) {
    const icons = {
        'header': '📌',
        'footer': '📍',
        'content': '📝',
        'sidebar': '📊',
        'image': '🖼️'
    };
    return icons[location] || '📄';
}

/**
 * Get error type badge
 */
function getErrorTypeBadge(errorType, statusCode) {
    const badges = {
        '4xx': `<span class="status-badge error-4xx">4xx - ${statusCode}</span>`,
        '5xx': `<span class="status-badge error-5xx">5xx - ${statusCode}</span>`,
        'timeout': `<span class="status-badge error-timeout">Timeout</span>`,
        'dns': `<span class="status-badge error-dns">DNS Error</span>`
    };
    return badges[errorType] || `<span class="status-badge">${statusCode}</span>`;
}
