/**
 * SEO AutoFix Pro - Global Settings JavaScript
 */

jQuery(document).ready(function($) {
    
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
    
});
