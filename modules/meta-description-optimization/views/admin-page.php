<?php
/**
 * Meta Description Optimization — Admin Page View
 *
 * @package SEO_AutoFix_Pro
 * @subpackage MetaDescriptionOptimization
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap metadesc-admin">

    <script type="text/javascript">
        if (typeof metaDescData === 'undefined') {
            var metaDescData = {
                ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo esc_js(wp_create_nonce('metadesc_nonce')); ?>',
                hasApiKey: <?php echo \SEOAutoFix_Settings::is_api_configured() ? '1' : '0'; ?>
            };
        }
    </script>

    <h1><?php _e('Meta Description Optimization', 'seo-autofix-pro'); ?></h1>

    <?php if (!\SEOAutoFix_Settings::is_api_configured()): ?>
        <div class="notice notice-info">
            <p>
                <strong><?php _e('AI Features Disabled', 'seo-autofix-pro'); ?></strong><br>
                <?php _e('OpenAI API key not configured. You can still scan and view issues, but AI description generation is disabled.', 'seo-autofix-pro'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=seoautofix-settings')); ?>"
                    class="button button-secondary">
                    <?php _e('Configure API Key', 'seo-autofix-pro'); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <!-- Header Action Bar -->
    <div class="metadesc-header">
        <button id="metadesc-scan-btn" class="button button-primary">
            <span class="dashicons dashicons-search"></span>
            <?php _e('Scan Posts & Pages', 'seo-autofix-pro'); ?>
        </button>
        <button id="metadesc-scan-export-btn" class="button" style="display:none;">
            <span class="dashicons dashicons-download"></span>
            <?php _e('Export CSV', 'seo-autofix-pro'); ?>
        </button>
        <select id="metadesc-posttype-filter" class="metadesc-posttype-select">
            <option value="all"><?php _e('All (Posts & Pages)', 'seo-autofix-pro'); ?></option>
            <option value="post"><?php _e('Posts Only', 'seo-autofix-pro'); ?></option>
            <option value="page"><?php _e('Pages Only', 'seo-autofix-pro'); ?></option>
        </select>
    </div>

    <!-- Scan Progress Bar -->
    <div id="metadesc-scan-progress" style="display:none;">
        <div class="metadesc-progress-bar">
            <div class="metadesc-progress-fill" id="metadesc-progress-fill"></div>
        </div>
        <p class="metadesc-progress-text">
            <?php _e('Scanning…', 'seo-autofix-pro'); ?> <span id="metadesc-progress-pct">0%</span>
        </p>
    </div>

    <!-- Stats Cards -->
    <div class="metadesc-stats" id="metadesc-stats" style="display:none;">
        <div class="metadesc-stat-card">
            <div class="metadesc-stat-number" id="stat-total">--</div>
            <div class="metadesc-stat-label"><?php _e('Total Posts / Pages', 'seo-autofix-pro'); ?></div>
        </div>
        <div class="metadesc-stat-card">
            <div class="metadesc-stat-number" id="stat-with-descriptions">--</div>
            <div class="metadesc-stat-label"><?php _e('Pages With Descriptions', 'seo-autofix-pro'); ?></div>
        </div>
        <div class="metadesc-stat-card">
            <div class="metadesc-stat-number" id="stat-without-descriptions">--</div>
            <div class="metadesc-stat-label"><?php _e('Pages Without Descriptions', 'seo-autofix-pro'); ?></div>
        </div>
    </div>

    <!-- Filter & Bulk Controls -->
    <div class="metadesc-controls" id="metadesc-controls" style="display:none;">

        <!-- Filter Row -->
        <div class="metadesc-filter-section">
            <div class="metadesc-filter-header">
                <h3><?php _e('Filter Results', 'seo-autofix-pro'); ?></h3>
                <button id="metadesc-reset-filter-btn" class="button"
                    style="background:#f0f0f1; border-color:#ddd; display:flex; align-items:center; gap:6px;">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php _e('Reset', 'seo-autofix-pro'); ?>
                </button>
            </div>
            <p style="margin:8px 0 15px; color:#666; font-size:13px;">
                <?php _e('Choose a filter to narrow down the results shown below:', 'seo-autofix-pro'); ?>
            </p>
            <div class="metadesc-filter-cards">
                <label class="metadesc-filter-card" data-filter="missing">
                    <input type="radio" name="metadesc-filter" value="missing">
                    <span class="metadesc-filter-label"><?php _e('Missing Descriptions', 'seo-autofix-pro'); ?></span>
                    <span class="metadesc-filter-count"></span>
                </label>
                <label class="metadesc-filter-card" data-filter="too_short">
                    <input type="radio" name="metadesc-filter" value="too_short">
                    <span class="metadesc-filter-label"><?php _e('Descriptions &lt; 60 chars', 'seo-autofix-pro'); ?></span>
                    <span class="metadesc-filter-count"></span>
                </label>
                <label class="metadesc-filter-card" data-filter="too_long">
                    <input type="radio" name="metadesc-filter" value="too_long">
                    <span class="metadesc-filter-label"><?php _e('Descriptions &gt; 120 chars', 'seo-autofix-pro'); ?></span>
                    <span class="metadesc-filter-count"></span>
                </label>
                <label class="metadesc-filter-card" data-filter="duplicate">
                    <input type="radio" name="metadesc-filter" value="duplicate">
                    <span class="metadesc-filter-label"><?php _e('Duplicate Descriptions', 'seo-autofix-pro'); ?></span>
                    <span class="metadesc-filter-count"></span>
                </label>
            </div>
        </div>

        <!-- Bulk Action Bar -->
        <div class="metadesc-bulk-bar">
            <div class="metadesc-bulk-left">
                <button id="metadesc-bulk-generate-btn" class="button button-primary" style="padding:8px 20px;">
                    <span class="dashicons dashicons-superhero"></span>
                    <?php _e('Generate AI Suggested Descriptions for Below', 'seo-autofix-pro'); ?>
                </button>
                <!-- <button id="metadesc-lock-btn" class="button" style="padding:8px 20px;">
                    <span class="dashicons dashicons-lock"></span>
                    <?php _e('Lock Rows', 'seo-autofix-pro'); ?>
                </button> -->
            </div>
            <div class="metadesc-bulk-right">
                <button id="metadesc-undo-btn" class="button" style="padding:8px 20px;" disabled>
                    <span class="dashicons dashicons-undo"></span>
                    <?php _e('Undo', 'seo-autofix-pro'); ?>
                </button>
                <button id="metadesc-export-csv-btn" class="button" style="display:none; padding:8px 20px;">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Export Changes in CSV', 'seo-autofix-pro'); ?>
                </button>
                <button id="metadesc-bulk-apply-btn" class="button button-primary" style="padding:8px 20px;">
                    <span class="dashicons dashicons-yes"></span>
                    <?php _e('Bulk Apply Descriptions Below', 'seo-autofix-pro'); ?>
                </button>
            </div>
        </div>

        <!-- Bulk Generation Progress -->
        <div id="metadesc-bulk-progress"
            style="display:none; margin-top:20px; padding:15px; background:#f0f6fc; border:1px solid #0073aa; border-radius:4px;">
            <div class="metadesc-bulk-progress-inner">
                <span id="metadesc-bulk-progress-text" style="font-weight:500; color:#0073aa;">
                    <?php _e('Generating: 0 of 0', 'seo-autofix-pro'); ?>
                </span>
                <button id="metadesc-cancel-btn" class="button metadesc-btn-cancel">
                    <span class="dashicons dashicons-no"></span>
                    <?php _e('Cancel', 'seo-autofix-pro'); ?>
                </button>
            </div>
            <div class="metadesc-bulk-progress-bar-track">
                <div id="metadesc-bulk-progress-fill" class="metadesc-bulk-progress-bar-fill"></div>
            </div>
        </div>
    </div>

    <!-- Results Table -->
    <div class="metadesc-results" id="metadesc-results" style="display:none;">
        <table class="wp-list-table widefat fixed striped metadesc-table">
            <thead>
                <tr>
                    <th class="metadesc-col-num">#</th>
                    <th class="metadesc-col-title"><?php _e('Page Name', 'seo-autofix-pro'); ?></th>
                    <th class="metadesc-col-url"><?php _e('Page URL', 'seo-autofix-pro'); ?></th>
                    <th class="metadesc-col-current"><?php _e('Current SEO Description', 'seo-autofix-pro'); ?></th>
                    <th class="metadesc-col-suggested"><?php _e('AI Suggested Description', 'seo-autofix-pro'); ?></th>
                    <th class="metadesc-col-actions"><?php _e('Actions', 'seo-autofix-pro'); ?></th>
                </tr>
            </thead>
            <tbody id="metadesc-tbody">
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="metadesc-pagination" id="metadesc-pagination" style="display:none;">
            <div class="metadesc-pagination-info" id="metadesc-pagination-info"></div>
            <div class="metadesc-pagination-controls" id="metadesc-pagination-controls"></div>
        </div>
    </div>

    <!-- Empty State -->
    <div id="metadesc-empty-state" class="metadesc-empty-state">
        <div class="metadesc-empty-icon">
            <span class="dashicons dashicons-editor-paragraph"></span>
        </div>
        <h2><?php _e('Ready to Scan', 'seo-autofix-pro'); ?></h2>
        <p><?php _e('Click "Scan Posts & Pages" to detect meta description issues across your site.', 'seo-autofix-pro'); ?>
        </p>
    </div>

    <!-- Row Template -->
    <template id="metadesc-row-template">
        <tr class="metadesc-row" data-post-id="" data-issue="">
            <td class="metadesc-col-num"></td>
            <td class="metadesc-col-title">
                <div class="metadesc-post-title"></div>
                <div class="metadesc-post-meta">
                    <span class="metadesc-post-type-badge"></span>
                    &bull;
                    <a class="metadesc-edit-link" href="#" target="_blank"
                        rel="noopener"><?php _e('Edit', 'seo-autofix-pro'); ?></a>
                </div>
            </td>
            <td class="metadesc-col-url">
                <a class="metadesc-post-url" href="#" target="_blank" rel="noopener"></a>
            </td>
            <td class="metadesc-col-current">
                <div class="metadesc-current-description-text"></div>
                <div class="metadesc-issue-badge-wrap" style="display:inline;"></div>
                <span class="metadesc-current-char-count" style="font-size:11px; color:#888; margin-left:6px;"></span>
            </td>
            <td class="metadesc-col-suggested">
                <div class="metadesc-suggested-editable" contenteditable="true"></div>
                <div class="metadesc-char-counter"><span class="metadesc-char-count">0</span>
                    <?php _e('chars', 'seo-autofix-pro'); ?></div>
                <div class="metadesc-primary-keyword" style="display:none;"></div>
                <div class="metadesc-generating-indicator" style="display:none;">
                    <span class="spinner is-active"></span>
                    <?php _e('Generating…', 'seo-autofix-pro'); ?>
                </div>
            </td>
            <td class="metadesc-col-actions">
                <button class="button button-secondary metadesc-generate-btn">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php _e('Generate', 'seo-autofix-pro'); ?>
                </button>
                <button class="button button-primary metadesc-apply-btn" disabled>
                    <?php _e('Apply', 'seo-autofix-pro'); ?>
                </button>
                <button class="button metadesc-skip-btn">
                    <?php _e('Skip', 'seo-autofix-pro'); ?>
                </button>
                <div class="metadesc-action-status"></div>
            </td>
        </tr>
    </template>

    <!-- Lock Rows Modal -->
    <div id="metadesc-lock-modal" class="metadesc-lock-modal" style="display:none;">
        <div class="metadesc-lock-modal-overlay"></div>
        <div class="metadesc-lock-modal-content">
            <div class="metadesc-lock-modal-header">
                <h2>
                    <span class="dashicons dashicons-lock" style="margin-right:6px;"></span>
                    <?php _e('Lock Rows by URL', 'seo-autofix-pro'); ?>
                </h2>
                <button class="metadesc-lock-modal-close">&times;</button>
            </div>
            <div class="metadesc-lock-modal-body">
                <p style="color:#666; font-size:13px; margin:0 0 12px;">
                    <?php _e('Enter page URLs to lock (one per line). Locked rows are excluded from bulk Generate & Apply actions.', 'seo-autofix-pro'); ?>
                </p>
                <textarea id="metadesc-lock-urls-input" rows="10"
                    placeholder="<?php _e('Paste URLs here, one per line', 'seo-autofix-pro'); ?>"></textarea>
            </div>
            <div class="metadesc-lock-modal-footer">
                <button id="metadesc-lock-clear-btn" class="button">
                    <span class="dashicons dashicons-dismiss"></span>
                    <?php _e('Clear All', 'seo-autofix-pro'); ?>
                </button>
                <button id="metadesc-lock-done-btn" class="button button-primary">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php _e('Done', 'seo-autofix-pro'); ?>
                </button>
            </div>
        </div>
    </div>

</div><!-- .metadesc-admin -->