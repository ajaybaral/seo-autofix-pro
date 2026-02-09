/**
 * SEO AutoFix Pro - Global Settings JavaScript
 */

console.log('🔘 DEBUG-SETTINGS: Script file loaded!');

jQuery(document).ready(function($) {

    console.log('🔘 DEBUG-SETTINGS: jQuery ready');
    console.log('🔘 DEBUG-SETTINGS: Button exists?', $('#download-debug-logs-btn').length);

    
    // Toggle API key visibility
    $('#toggle-api-key').on('click', function() {
        const $input = $('#seoautofix_api_key');
        const $button = $(this);
        
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $button.text('Hide');
        } else {
            $input.attr('type', 'password');
            $button.text('Show');
        }
    });
    
    // Download debug logs
    $('#download-debug-logs-btn').on('click', function() {
        console.log('🔘 DEBUG-DOWNLOAD: Button clicked');
        
        const $button = $(this);
        const $status = $('#download-debug-status');
        
        console.log('🔘 DEBUG-DOWNLOAD: ajaxurl =', typeof ajaxurl !== 'undefined' ? ajaxurl : 'UNDEFINED!');
        console.log('🔘 DEBUG-DOWNLOAD: nonce =', typeof seoautofixSettings !== 'undefined' ? seoautofixSettings.debugNonce : 'UNDEFINED!');
        
        // Disable button and show loading state
        $button.prop('disabled', true).text('Downloading...');
        $status.html('<span style="color: #666;">⏳ Fetching logs...</span>');
        
        console.log('🔘 DEBUG-DOWNLOAD: Sending AJAX request...');
        
        // Get debug log content via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'seoautofix_get_debug_logs',
                nonce: seoautofixSettings.debugNonce
            },
            success: function(response) {
                console.log('✅ DEBUG-DOWNLOAD: AJAX Success', response);
                
                if (response.success) {
                    console.log('✅ DEBUG-DOWNLOAD: Log size:', response.data.size, 'bytes');
                    console.log('✅ DEBUG-DOWNLOAD: Log path:', response.data.path);
                    
                    // Create a download link for the log content
                    const blob = new Blob([response.data.content], { type: 'text/plain' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'seoautofix-debug-' + new Date().toISOString().slice(0,10) + '.log';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                    
                    console.log('✅ DEBUG-DOWNLOAD: File downloaded successfully');
                    $status.html('<span style="color: #46b450;">✓ Downloaded successfully!</span>');
                    setTimeout(function() {
                        $status.html('');
                    }, 3000);
                } else {
                    console.error('❌ DEBUG-DOWNLOAD: Server returned error:', response.data);
                    $status.html('<span style="color: #dc3232;">✗ Error: ' + (response.data.message || 'Failed to fetch logs') + '</span>');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ DEBUG-DOWNLOAD: AJAX Error');
                console.error('   XHR:', xhr);
                console.error('   Status:', status);
                console.error('   Error:', error);
                console.error('   Response Text:', xhr.responseText);
                
                $status.html('<span style="color: #dc3232;">✗ Error downloading logs: ' + error + '</span>');
            },
            complete: function() {
                console.log('🔘 DEBUG-DOWNLOAD: AJAX Complete');
                // Re-enable button
                $button.prop('disabled', false).html('<span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Download Debug Logs');
            }
        });
    });
    
});
