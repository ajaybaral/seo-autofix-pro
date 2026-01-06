<?php
/**
 * Image SEO Module - Admin Page View
 * 
 * @package SEO_AutoFix_Pro
 * @subpackage Image_SEO
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap imageseo-admin">
    <script type="text/javascript">
        var imageSeoData = {
            ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('imageseo_nonce'); ?>',
            hasApiKey: <?php echo \SEOAutoFix_Settings::is_api_configured() ? '1' : '0'; ?>,
            adminEmail: '<?php echo get_option('admin_email'); ?>'
        };
    </script>
    <h1><?php _e('Image SEO Optimizer', 'seo-autofix-pro'); ?></h1>
    
    <?php if (!\SEOAutoFix_Settings::is_api_configured()): ?>
    <div class="notice notice-info" id="no-api-key-notice">
        <p>
            <strong><?php _e('AI Features Disabled', 'seo-autofix-pro'); ?></strong><br>
            <?php _e('OpenAI API key not configured. You can still scan images and manually edit alt text, but AI suggestions and scoring are disabled.', 'seo-autofix-pro'); ?>
        </p>
        <p>
            <a href="<?php echo admin_url('admin.php?page=seoautofix-settings'); ?>" class="button button-secondary">
                <?php _e('Configure API Key', 'seo-autofix-pro'); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>
    
    <!-- Tab Navigation -->
    <h2 class="nav-tab-wrapper" style="margin: 20px 0;">
        <a href="#" class="nav-tab nav-tab-active" data-tab="alt-images">
            <span class="dashicons dashicons-images-alt2" style="margin-top: 4px;"></span>
            <?php _e('Alt & Images', 'seo-autofix-pro'); ?>
        </a>
        <a href="#" class="nav-tab" data-tab="cleanup-delete">
            <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span>
            <?php _e('Cleanup & Delete', 'seo-autofix-pro'); ?>
        </a>
    </h2>
    
    <!-- Tab Content: Alt & Images -->
    <div id="tab-alt-images" class="tab-content" style="display: block;">
    
    <!-- Header Buttons -->
    <div class="imageseo-header">
        <button id="scan-btn" class="button button-primary">
            <span class="dashicons dashicons-search"></span>
            <?php _e('Scan Images', 'seo-autofix-pro'); ?>
        </button>
        <button id="export-csv-btn" class="button button-secondary">
            <span class="dashicons dashicons-media-spreadsheet"></span>
            <?php _e('Export CSV', 'seo-autofix-pro'); ?>
        </button>
        <button id="email-csv-btn" class="button button-secondary">
            <span class="dashicons dashicons-email"></span>
            <?php _e('Email CSV to Admin', 'seo-autofix-pro'); ?>
        </button>
    </div>

    
    <!-- Stats Cards - Simplified (3 cards only) -->
    <div class="imageseo-stats">
        <div class="stat-card">
            <div class="stat-number" id="stat-total">--</div>
            <div class="stat-label"><?php _e('Total Images', 'seo-autofix-pro'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-number" id="stat-missing-alt">--</div>
            <div class="stat-label"><?php _e('Images with Missing Alt Text', 'seo-autofix-pro'); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-number" id="stat-has-alt">--</div>
            <div class="stat-label"><?php _e('Images with Alt Text', 'seo-autofix-pro'); ?></div>
        </div>
    </div>
    
    <!-- Progress Bar -->
    <div id="scan-progress" style="display:none;">
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        <p class="progress-text"><?php _e('Scanning images...', 'seo-autofix-pro'); ?></p>
    </div>
    
    
    <!-- NEW: Filter & AI Generation Controls (hidden until first scan) -->
    <?php if (\SEOAutoFix_Settings::is_api_configured()): ?>
    <div class="imageseo-filter-controls" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px; display: none;">
        <!-- Filter Section -->
        <div style="margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h3 style="margin: 0;"><?php _e('Filter Images', 'seo-autofix-pro'); ?></h3>
                <button id="reset-filter-btn" class="button" style="background: #f0f0f1; border-color: #ddd; display: flex; align-items: center; gap: 6px;">
                    <span class="dashicons dashicons-image-rotate" style="margin: 0; font-size: 16px;"></span>
                    <?php _e('Reset', 'seo-autofix-pro'); ?>
                </button>
            </div>
            <p style="margin: 8px 0 15px; color: #666; font-size: 13px;"><?php _e('Choose a filter to narrow down the images shown below:', 'seo-autofix-pro'); ?></p>
            
            <!-- 4 Radio Buttons in ONE LINE -->
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <label style="flex: 1; display: flex; align-items: center; padding: 12px 16px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; transition: all 0.2s; min-width: 200px;">
                    <input type="radio" name="image-filter" value="with-alt-postpage" style="margin-right: 10px;">
                    <span style="font-size: 14px; font-weight: 500;"><?php _e('WITH Alt (Posts/Pages)', 'seo-autofix-pro'); ?></span>
                </label>
                <label style="flex: 1; display: flex; align-items: center; padding: 12px 16px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; transition: all 0.2s; min-width: 200px;">
                    <input type="radio" name="image-filter" value="without-alt-postpage" style="margin-right: 10px;">
                    <span style="font-size: 14px; font-weight: 500;"><?php _e('WITHOUT Alt (Posts/Pages)', 'seo-autofix-pro'); ?></span>
                </label>
                <label style="flex: 1; display: flex; align-items: center; padding: 12px 16px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; transition: all 0.2s; min-width: 200px;">
                    <input type="radio" name="image-filter" value="with-alt-all" style="margin-right: 10px;">
                    <span style="font-size: 14px; font-weight: 500;"><?php _e('WITH Alt (All Media)', 'seo-autofix-pro'); ?></span>
                </label>
                <label style="flex: 1; display: flex; align-items: center; padding: 12px 16px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; transition: all 0.2s; min-width: 200px;">
                    <input type="radio" name="image-filter" value="without-alt-all" style="margin-right: 10px;">
                    <span style="font-size: 14px; font-weight: 500;"><?php _e('WITHOUT Alt (All Media)', 'seo-autofix-pro'); ?></span>
                </label>
            </div>
        </div>
        
        <!-- Action Buttons in Opposite Corners -->
        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 20px; border-top: 1px solid #e0e0e0;">
            <button id="generate-visible-btn" class="button button-primary" style="padding: 8px 20px;">
                <span class="dashicons dashicons-superhero"></span>
                <?php _e('Generate AI Suggested Alt Text for Below', 'seo-autofix-pro'); ?>
            </button>
            
            <button id="bulk-apply-btn" class="button button-primary" style="padding: 8px 20px;">
                <span class="dashicons dashicons-yes"></span>
                <?php _e('Bulk Apply Images Below', 'seo-autofix-pro'); ?>
            </button>
        </div>
        
        <!-- Bulk Generation Progress Indicator (hidden by default) -->
        <div id="bulk-generation-progress" style="display: none; margin-top: 20px; padding: 15px; background: #f0f6fc; border: 1px solid #0073aa; border-radius: 4px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span id="progress-text" style="font-weight: 500; color: #0073aa;">Generating: 0 of 0 images</span>
                <button id="cancel-generation-btn" class="button" style="padding: 4px 12px; background: #dc3232; color: white; border-color: #dc3232;">
                    <span class="dashicons dashicons-no" style="margin-top: 3px;"></span>
                    Cancel
                </button>
            </div>
            <div style="width: 100%; height: 20px; background: #ddd; border-radius: 10px; overflow: hidden;">
                <div id="progress-bar-fill" style="height: 100%; width: 0%; background: linear-gradient(90deg, #0073aa, #00a0d2); transition: width 0.3s ease;"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Clear All AI Suggestions Button (appears after generation) -->
    <div id="clear-suggestions-container" style="display: none; margin: 20px 0; text-align: right;">
        <button id="clear-suggestions-btn" class="button" style="padding: 10px 24px; background: #dc3232; color: white; border-color: #dc3232; font-size: 14px;">
            <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
            <?php _e('Clear All AI Suggestions', 'seo-autofix-pro'); ?>
        </button>
    </div>
    
    <!--Results Table -->
    <div class="imageseo-results" >
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th style="width: 100px;"><?php _e('Image', 'seo-autofix-pro'); ?></th>
                    <th><?php _e('Current Alt Text', 'seo-autofix-pro'); ?></th>
                    <th><?php _e('AI Suggested Alt Text', 'seo-autofix-pro'); ?></th>
                    <th style="width: 180px;"><?php _e('Actions', 'seo-autofix-pro'); ?></th>
                </tr>
            </thead>
            <tbody id="results-tbody">
                <!-- Results will be populated via JavaScript -->
            </tbody>
        </table>
    </div>
    
    <!-- Empty State -->
    <div id="empty-state" class="imageseo-empty-state">
        <div class="empty-icon">
            <span class="dashicons dashicons-images-alt2"></span>
        </div>
        <h2><?php _e('No Issues Found', 'seo-autofix-pro'); ?></h2>
        <p><?php _e('Click "Scan Images" to analyze your media library for SEO issues.', 'seo-autofix-pro'); ?></p>
    </div>
</div>

<!-- Row Template (hidden) -->
<template id="result-row-template">
    <tr class="result-row" data-attachment-id="">
        <td class="row-number"></td>
        <td class="row-image">
            <img src="" alt="" class="attachment-thumbnail">
            <div class="image-filename"></div>
        </td>
        <td class="row-current-alt">
            <div class="alt-text"></div>
            <div class="usage-info"></div>
        </td>
        <td class="row-suggested-alt">
            <div class="alt-text-editable" contenteditable="true"></div>
            <!-- Character limit removed as per user request -->
            <div class="loading-indicator" >
                <span class="spinner is-active"></span>
                <?php _e('Generating...', 'seo-autofix-pro'); ?>
            </div>
        </td>
        <td class="row-actions-col">
            <button class="button button-secondary generate-btn" title="Generate AI suggestion for this image">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php _e('Generate', 'seo-autofix-pro'); ?>
            </button>
            <button class="button button-primary apply-btn">
                <?php _e('Apply', 'seo-autofix-pro'); ?>
            </button>
            <button class="button skip-btn">
                <?php _e('Skip', 'seo-autofix-pro'); ?>
            </button>
            <button class="button button-link-delete delete-btn" style="display:none; color:#b32d2e;">
                <?php _e('Delete', 'seo-autofix-pro'); ?>
            </button>
            <div class="action-status"></div>
        </td>
    </tr>
</template>

<!-- Bulk Delete Unused Images Modal -->
<div id="bulk-delete-modal" class="bulk-delete-modal" style="display:none;">
    <div class="bulk-delete-modal-overlay"></div>
    <div class="bulk-delete-modal-content">
        <div class="bulk-delete-modal-header">
            <h2><?php _e('Bulk Delete Unused Images', 'seo-autofix-pro'); ?></h2>
            <button class="bulk-delete-modal-close">&times;</button>
        </div>
        <div class="bulk-delete-modal-body">
            <p class="bulk-delete-count"></p>
            <p class="bulk-delete-warning">
                <strong><?php _e('Warning:', 'seo-autofix-pro'); ?></strong>
                <?php _e('This action cannot be undone. All unused images will be permanently deleted from your media library.', 'seo-autofix-pro'); ?>
            </p>
            <div class="bulk-delete-options">
                <button id="bulk-delete-with-download" class="button button-primary button-large">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Download as ZIP & Delete', 'seo-autofix-pro'); ?>
                </button>
                <button id="bulk-delete-without-download" class="button button-danger button-large">
                    <span class="dashicons dashicons-trash"></span>
                    <?php _e('Delete Without Saving', 'seo-autofix-pro'); ?>
                </button>
            </div>
            <div class="bulk-delete-progress" style="display:none;">
                <p class="bulk-delete-progress-text"></p>
                <div class="bulk-delete-progress-bar">
                    <div class="bulk-delete-progress-fill"></div>
                </div>
            </div>
        </div>
    </div>
</div>

</div><!-- End tab-alt-images -->

<!-- Tab Content: Cleanup & Delete -->
<div id="tab-cleanup-delete" class="tab-content" style="display: none;">
    
    <div class="notice notice-warning" style="margin: 20px 0;">
        <p>
            <strong><?php _e('⚠️ Warning: Destructive Operations', 'seo-autofix-pro'); ?></strong><br>
            <?php _e('All actions on this tab perform permanent changes. Use with caution and ensure you have backups.', 'seo-autofix-pro'); ?>
        </p>
    </div>
    
    <!-- Feature 1: Remove All Alt Texts -->
    <div class="cleanup-section" style="margin: 30px 0; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
        <h2><?php _e('Remove All Image Alt Texts', 'seo-autofix-pro'); ?></h2>
        <p style="color: #666;">
            <?php _e('Remove alt text from ALL images in the media library.', 'seo-autofix-pro'); ?>
        </p>
        <div style="background: #fff9e6; border-left: 4px solid #ffb900; padding: 12px; margin: 15px 0;">
            <strong><?php _e('Warning:', 'seo-autofix-pro'); ?></strong>
            <?php _e('This will permanently remove all alt texts. Confirmation required.', 'seo-autofix-pro'); ?>
        </div>
        <button id="remove-all-alt-btn" class="button" style="background: #dc3232; color: #fff; border-color: #dc3232;">
            <span class="dashicons dashicons-warning"></span>
            <?php _e('Remove All Alt Texts', 'seo-autofix-pro'); ?>
        </button>
    </div>
    
    <!-- Feature 2: Delete by URL -->
    <div class="cleanup-section" style="margin: 30px 0; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
        <h2><?php _e('Delete Images by URL', 'seo-autofix-pro'); ?></h2>
        <p style="color: #666;">
            <?php _e('Delete specific images by pasting URLs. One per line, max 50.', 'seo-autofix-pro'); ?>
        </p>
        <textarea id="delete-urls-input" rows="8" style="width: 100%; max-width: 800px; font-family: monospace;" placeholder="<?php _e('Paste URLs here, one per line', 'seo-autofix-pro'); ?>"></textarea>
        <div style="margin: 15px 0;">
            <button id="delete-by-url-btn" class="button" style="background: #dc3232; color: #fff; border-color: #dc3232;">
                <span class="dashicons dashicons-trash"></span>
                <?php _e('Delete Images by URL', 'seo-autofix-pro'); ?>
            </button>
        </div>
    </div>
    
    <!-- Feature 3: Delete Unused Images -->
    <div class="cleanup-section" style="margin: 30px 0; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
        <h2><?php _e('Delete Unused Images', 'seo-autofix-pro'); ?></h2>
        <p style="color: #666;">
            <?php _e('Delete images not used in any posts or pages.', 'seo-autofix-pro'); ?>
        </p>
        <button id="bulk-delete-unused-btn" class="button" style="background: #dc3232; color: #fff; border-color: #dc3232;">
            <span class="dashicons dashicons-trash"></span>
            <?php _e('Bulk Delete Unused Images', 'seo-autofix-pro'); ?>
        </button>
    </div>
    
</div><!-- End tab-cleanup-delete -->

</div><!-- .imageseo-admin -->
