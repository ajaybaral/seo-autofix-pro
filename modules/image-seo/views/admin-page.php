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
        <button id="bulk-delete-unused-btn" class="button button-danger" style="margin-left: auto;">
            <span class="dashicons dashicons-trash"></span>
            <?php _e('Bulk Delete Unused Images', 'seo-autofix-pro'); ?>
        </button>
    </div>

    
    <!-- Stats Cards -->
    <div class="imageseo-stats" >
        <div class="stat-card">
            <div class="stat-number" id="stat-total">--</div>
            <div class="stat-label"><?php _e('Total Images', 'seo-autofix-pro'); ?></div>
        </div>
        <div class="stat-card stat-parent">
            <div class="stat-label stat-parent-label"><?php _e('Low Score Images', 'seo-autofix-pro'); ?></div>
            <div class="stat-subcategories">
                <div class="stat-subcard clickable" data-filter="empty">
                    <div class="stat-number" id="stat-low-empty">--</div>
                    <div class="stat-label"><?php _e('Empty Alt', 'seo-autofix-pro'); ?></div>
                </div>
                <div class="stat-subcard clickable" data-filter="low-with-alt">
                    <div class="stat-number" id="stat-low-with-alt">--</div>
                    <div class="stat-label"><?php _e('Has Alt (Low Score)', 'seo-autofix-pro'); ?></div>
                </div>
            </div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-number" id="stat-fixed">--</div>
            <div class="stat-label"><?php _e('Optimized', 'seo-autofix-pro'); ?></div>
        </div>
    </div>
    
    <!-- Progress Bar -->
    <div id="scan-progress" >
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
        <p class="progress-text"><?php _e('Scanning images...', 'seo-autofix-pro'); ?></p>
    </div>
    
    <!-- AI Generation Controls -->
    <?php if (\SEOAutoFix_Settings::is_api_configured()): ?>
    <div class="imageseo-ai-controls" style="display:none;">
        <div class="ai-controls-header">
            <h3><?php _e('AI Generation Controls', 'seo-autofix-pro'); ?></h3>
            <p><?php _e('Choose how to generate AI suggestions for alt text:', 'seo-autofix-pro'); ?></p>
        </div>
        <div class="ai-controls-buttons">
            <button id="generate-all-btn" class="button button-secondary">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php _e('Generate AI for All Images', 'seo-autofix-pro'); ?>
            </button>
            <button id="generate-postpage-btn" class="button button-secondary">
                <span class="dashicons dashicons-admin-page"></span>
                <?php _e('Generate AI for Post/Page Images', 'seo-autofix-pro'); ?>
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filters & Bulk Actions -->
    <div class="imageseo-filters">
        <div class="filter-left">
            <label style="margin-right: 15px;">
                <input type="radio" name="image-filter" id="filter-all-images" value="all" checked>
                <?php _e('Show All Images', 'seo-autofix-pro'); ?>
            </label>
            <label>
                <input type="radio" name="image-filter" id="filter-post-page-images" value="post_page">
                <?php _e('Show Post/Page Images Only', 'seo-autofix-pro'); ?>
            </label>
        </div>
        <div class="filter-right" style="margin-left: auto;">
            <button id="bulk-apply-btn" class="button button-primary">
                <?php _e('Bulk Apply All', 'seo-autofix-pro'); ?>
            </button>
        </div>
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
                    <th style="width: 80px;"><?php _e('Before', 'seo-autofix-pro'); ?></th>
                    <th style="width: 80px;"><?php _e('After', 'seo-autofix-pro'); ?></th>
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
            <div class="char-counter"><span class="char-count">0</span> / 60</div>
            <div class="loading-indicator" >
                <span class="spinner is-active"></span>
                <?php _e('Generating...', 'seo-autofix-pro'); ?>
            </div>
        </td>
        <td class="row-score-before">
            <div class="score-badge"></div>
        </td>
        <td class="row-score-after">
            <div class="score-badge"></div>
        </td>
        <td class="row-actions-col">
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

</div><!-- .imageseo-admin -->
