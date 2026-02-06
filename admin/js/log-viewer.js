/**
 * Debug Log Viewer JavaScript
 * 
 * Handles log loading, filtering, search, and auto-refresh
 */

(function($) {
    'use strict';

    let offset = 0;
    let autoRefreshInterval = null;
    let isLoading = false;
    let hasMore = true;
    let totalLogs = 0;

    /**
     * Load logs from server
     */
    function loadLogs(reset = false) {
        if (isLoading) {
            return;
        }

        if (reset) {
            offset = 0;
            $('#log-table-body').empty();
            hasMore = true;
        }

        if (!hasMore && !reset) {
            return;
        }

        isLoading = true;
        showLoading();

        const data = {
            action: 'seoautofix_get_logs',
            nonce: seoautofixLogs.nonce,
            level: $('#log-level-filter').val(),
            module: $('#log-module-filter').val(),
            search: $('#log-search').val(),
            offset: offset,
            limit: 50
        };

        $.post(seoautofixLogs.ajaxUrl, data, function(response) {
            hideLoading();
            isLoading = false;

            if (response.success) {
                const logs = response.data.logs;
                totalLogs = response.data.total;
                hasMore = response.data.has_more;

                if (logs.length === 0 && offset === 0) {
                    showNoResults();
                } else {
                    hideNoResults();
                    displayLogs(logs);
                    offset += logs.length;
                }

                updateLoadMoreButton();
                updateLogCount();
            } else {
                alert('Error loading logs: ' + (response.data.message || 'Unknown error'));
            }
        }).fail(function() {
            hideLoading();
            isLoading = false;
            alert('Failed to load logs. Please try again.');
        });
    }

    /**
     * Display logs in table
     */
    function displayLogs(logs) {
        logs.forEach(function(log) {
            const parsed = parseLogEntry(log);
            if (!parsed) return;

            const levelClass = 'log-level-' + parsed.level.toLowerCase();
            const row = $('<tr>').addClass(levelClass);

            // Timestamp
            row.append($('<td>').text(parsed.timestamp));

            // Level badge
            const levelBadge = $('<span>')
                .addClass('log-badge')
                .addClass(levelClass)
                .text(parsed.level);
            row.append($('<td>').append(levelBadge));

            // Module
            row.append($('<td>').text(parsed.module));

            // Message (with expand button if truncated)
            const messageCell = $('<td>').addClass('log-message');
            const messageText = $('<span>').text(parsed.message);
            
            if (parsed.message.length > 150) {
                const truncated = parsed.message.substring(0, 150) + '...';
                const shortMessage = $('<span>').addClass('message-short').text(truncated);
                const fullMessage = $('<span>')
                    .addClass('message-full')
                    .text(parsed.message)
                    .hide();
                const expandBtn = $('<button>')
                    .addClass('button-link expand-message')
                    .text('Show more')
                    .on('click', function(e) {
                        e.preventDefault();
                        $(this).siblings('.message-short').toggle();
                        $(this).siblings('.message-full').toggle();
                        $(this).text(fullMessage.is(':visible') ? 'Show less' : 'Show more');
                    });

                messageCell.append(shortMessage, fullMessage, ' ', expandBtn);
            } else {
                messageCell.append(messageText);
            }

            row.append(messageCell);

            $('#log-table-body').append(row);
        });
    }

    /**
     * Parse log entry string
     */
    function parseLogEntry(log) {
        const regex = /\[([\d\-: ]+)\] \[([A-Z]+)\] \[([A-Z\-]+)\] (.+)/;
        const match = log.match(regex);

        if (!match) {
            return null;
        }

        return {
            timestamp: match[1],
            level: match[2],
            module: match[3],
            message: match[4]
        };
    }

    /**
     * Show loading indicator
     */
    function showLoading() {
        $('#log-loading').show();
        $('#log-table').css('opacity', '0.5');
    }

    /**
     * Hide loading indicator
     */
    function hideLoading() {
        $('#log-loading').hide();
        $('#log-table').css('opacity', '1');
    }

    /**
     * Show no results message
     */
    function showNoResults() {
        $('#log-table').hide();
        $('#log-no-results').show();
    }

    /**
     * Hide no results message
     */
    function hideNoResults() {
        $('#log-table').show();
        $('#log-no-results').hide();
    }

    /**
     * Update load more button visibility
     */
    function updateLoadMoreButton() {
        if (hasMore) {
            $('#load-more-logs').show();
        } else {
            $('#load-more-logs').hide();
        }
    }

    /**
     * Update log count display
     */
    function updateLogCount() {
        const displayed = $('#log-table-body tr').length;
        const text = 'Showing ' + displayed + ' of ' + totalLogs + ' entries';
        $('#log-count-display').text(text);
    }

    /**
     * Debounce function
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction() {
            const context = this;
            const args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }

    /**
     * Clear logs
     */
    function clearLogs() {
        if (!confirm('Are you sure you want to clear all logs? This action cannot be undone.')) {
            return;
        }

        $.post(seoautofixLogs.ajaxUrl, {
            action: 'seoautofix_clear_logs',
            nonce: seoautofixLogs.nonce
        }, function(response) {
            if (response.success) {
                $('#log-table-body').empty();
                offset = 0;
                hasMore = false;
                totalLogs = 0;
                updateLogCount();
                showNoResults();
                
                // Reload page to update stats
                location.reload();
            } else {
                alert('Error clearing logs: ' + (response.data.message || 'Unknown error'));
            }
        });
    }

    /**
     * Download logs
     */
    function downloadLogs() {
        window.location.href = seoautofixLogs.ajaxUrl + 
            '?action=seoautofix_download_logs' +
            '&nonce=' + seoautofixLogs.nonce;
    }

    /**
     * Toggle auto-refresh
     */
    function toggleAutoRefresh() {
        if ($('#auto-refresh').is(':checked')) {
            autoRefreshInterval = setInterval(function() {
                loadLogs(true);
            }, 5000);
        } else {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }
    }

    /**
     * Initialize
     */
    $(document).ready(function() {
        // Initial load
        loadLogs();

        // Event handlers
        $('#refresh-logs').on('click', function() {
            loadLogs(true);
        });

        $('#log-level-filter, #log-module-filter').on('change', function() {
            loadLogs(true);
        });

        $('#log-search').on('keyup', debounce(function() {
            loadLogs(true);
        }, 500));

        $('#auto-refresh').on('change', toggleAutoRefresh);

        $('#clear-logs').on('click', clearLogs);

        $('#download-logs').on('click', downloadLogs);

        $('#test-log').on('click', function() {
            $.post(seoautofixLogs.ajaxUrl, {
                action: 'seoautofix_test_log',
                nonce: seoautofixLogs.nonce
            }, function(response) {
                if (response.success) {
                    alert('✅ Test logs written successfully! Refreshing...');
                    loadLogs(true);
                } else {
                    alert('❌ Error writing test log: ' + (response.data.message || 'Unknown error'));
                }
            });
        });

        $('#load-more-logs').on('click', function() {
            loadLogs(false);
        });

        // Cleanup on page unload
        $(window).on('beforeunload', function() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }
        });
    });

})(jQuery);
