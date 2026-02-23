<?php
/**
 * Title Tag Optimization — Admin Page View
 *
 * @package SEO_AutoFix_Pro
 * @subpackage TitleTagOptimization
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap titletag-admin">

    <!-- Inline data passthrough (backup for localize) -->
    <script type="text/javascript">
        if (typeof titleTagData === 'undefined') {
            var titleTagData = {
                ajaxUrl: '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
                nonce:   '<?php echo esc_js( wp_create_nonce( 'titletag_nonce' ) ); ?>',
                hasApiKey: <?php echo \SEOAutoFix_Settings::is_api_configured() ? '1' : '0'; ?>,
                adminEmail: '<?php echo esc_js( get_option( 'admin_email' ) ); ?>'
            };
        }
    </script>

    <h1><?php _e( 'Title Tag Optimization', 'seo-autofix-pro' ); ?></h1>

    <?php if ( ! \SEOAutoFix_Settings::is_api_configured() ) : ?>
        <div class="notice notice-info" id="titletag-no-api-notice">
            <p>
                <strong><?php _e( 'AI Features Disabled', 'seo-autofix-pro' ); ?></strong><br>
                <?php _e( 'OpenAI API key not configured. You can still scan and view issues, but AI title generation is disabled.', 'seo-autofix-pro' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=seoautofix-settings' ) ); ?>" class="button button-secondary">
                    <?php _e( 'Configure API Key', 'seo-autofix-pro' ); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <!-- ===== Header Action Bar ===== -->
    <div class="titletag-header">
        <button id="titletag-scan-btn" class="button button-primary">
            <span class="dashicons dashicons-search"></span>
            <?php _e( 'Scan Posts & Pages', 'seo-autofix-pro' ); ?>
        </button>
    </div>

    <!-- ===== Scan Progress Bar ===== -->
    <div id="titletag-scan-progress" style="display:none;">
        <div class="titletag-progress-bar">
            <div class="titletag-progress-fill" id="titletag-progress-fill"></div>
        </div>
        <p class="titletag-progress-text">
            <?php _e( 'Scanning…', 'seo-autofix-pro' ); ?> <span id="titletag-progress-pct">0%</span>
        </p>
    </div>

    <!-- ===== Stats Cards (hidden until first scan) ===== -->
    <div class="titletag-stats" id="titletag-stats" style="display:none;">
        <div class="titletag-stat-card">
            <div class="titletag-stat-number" id="stat-total">--</div>
            <div class="titletag-stat-label"><?php _e( 'Total Posts / Pages', 'seo-autofix-pro' ); ?></div>
        </div>
        <div class="titletag-stat-card">
            <div class="titletag-stat-number" id="stat-with-titles">--</div>
            <div class="titletag-stat-label"><?php _e( 'Pages With Titles', 'seo-autofix-pro' ); ?></div>
        </div>
        <div class="titletag-stat-card titletag-stat-warning">
            <div class="titletag-stat-number" id="stat-without-titles">--</div>
            <div class="titletag-stat-label"><?php _e( 'Pages Without Titles', 'seo-autofix-pro' ); ?></div>
        </div>
    </div>

    <!-- ===== Filter & Bulk Controls (hidden until first scan) ===== -->
    <div class="titletag-controls" id="titletag-controls" style="display:none;">

        <!-- Filter Row -->
        <div class="titletag-filter-section">
            <div class="titletag-filter-header">
                <h3><?php _e( 'Filter Results', 'seo-autofix-pro' ); ?></h3>
                <button id="titletag-reset-filter-btn" class="button">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php _e( 'Reset', 'seo-autofix-pro' ); ?>
                </button>
            </div>
            <div class="titletag-filter-cards">
                <label class="titletag-filter-card titletag-filter-active" data-filter="all">
                    <input type="radio" name="titletag-filter" value="all" checked>
                    <span class="titletag-filter-icon">📋</span>
                    <span><?php _e( 'All Titles', 'seo-autofix-pro' ); ?></span>
                </label>
                <label class="titletag-filter-card" data-filter="missing">
                    <input type="radio" name="titletag-filter" value="missing">
                    <span class="titletag-filter-icon">❌</span>
                    <span><?php _e( 'Missing Titles', 'seo-autofix-pro' ); ?></span>
                </label>
                <label class="titletag-filter-card" data-filter="too_short">
                    <input type="radio" name="titletag-filter" value="too_short">
                    <span class="titletag-filter-icon">📏</span>
                    <span><?php _e( 'Titles &lt; 30 chars', 'seo-autofix-pro' ); ?></span>
                </label>
                <label class="titletag-filter-card" data-filter="too_long">
                    <input type="radio" name="titletag-filter" value="too_long">
                    <span class="titletag-filter-icon">📐</span>
                    <span><?php _e( 'Titles &gt; 60 chars', 'seo-autofix-pro' ); ?></span>
                </label>
                <label class="titletag-filter-card" data-filter="duplicate">
                    <input type="radio" name="titletag-filter" value="duplicate">
                    <span class="titletag-filter-icon">⚠️</span>
                    <span><?php _e( 'Duplicate Titles', 'seo-autofix-pro' ); ?></span>
                </label>
            </div>
        </div>

        <!-- Bulk Action Bar -->
        <div class="titletag-bulk-bar">
            <div class="titletag-bulk-left">
                <button id="titletag-bulk-generate-btn" class="button button-primary">
                    <span class="dashicons dashicons-superhero"></span>
                    <?php _e( 'Bulk Generate AI Titles', 'seo-autofix-pro' ); ?>
                </button>
            </div>
            <div class="titletag-bulk-right">
                <button id="titletag-export-csv-btn" class="button" style="display:none;">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e( 'Export Applied Changes (CSV)', 'seo-autofix-pro' ); ?>
                </button>
                <button id="titletag-bulk-apply-btn" class="button button-primary">
                    <span class="dashicons dashicons-yes"></span>
                    <?php _e( 'Bulk Apply Suggestions', 'seo-autofix-pro' ); ?>
                </button>
            </div>
        </div>

        <!-- Bulk Generation Progress -->
        <div id="titletag-bulk-progress" style="display:none;">
            <div class="titletag-bulk-progress-inner">
                <span id="titletag-bulk-progress-text" style="font-weight:500; color:#0073aa;">
                    <?php _e( 'Generating: 0 of 0', 'seo-autofix-pro' ); ?>
                </span>
                <button id="titletag-cancel-btn" class="button titletag-btn-cancel">
                    <span class="dashicons dashicons-no" style="margin-top:3px;"></span>
                    <?php _e( 'Cancel', 'seo-autofix-pro' ); ?>
                </button>
            </div>
            <div class="titletag-bulk-progress-bar-track">
                <div id="titletag-bulk-progress-fill" class="titletag-bulk-progress-bar-fill"></div>
            </div>
        </div>
    </div>

    <!-- ===== Results Table ===== -->
    <div class="titletag-results" id="titletag-results" style="display:none;">
        <table class="wp-list-table widefat fixed striped titletag-table">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th><?php _e( 'Page Title', 'seo-autofix-pro' ); ?></th>
                    <th><?php _e( 'Page URL', 'seo-autofix-pro' ); ?></th>
                    <th><?php _e( 'Current SEO Title', 'seo-autofix-pro' ); ?></th>
                    <th><?php _e( 'AI Suggested Title', 'seo-autofix-pro' ); ?></th>
                    <th style="width:160px;"><?php _e( 'Actions', 'seo-autofix-pro' ); ?></th>
                </tr>
            </thead>
            <tbody id="titletag-tbody">
                <!-- Populated by JS -->
            </tbody>
        </table>
    </div>

    <!-- ===== Empty State ===== -->
    <div id="titletag-empty-state" class="titletag-empty-state">
        <div class="titletag-empty-icon">
            <span class="dashicons dashicons-tag"></span>
        </div>
        <h2><?php _e( 'Ready to Scan', 'seo-autofix-pro' ); ?></h2>
        <p><?php _e( 'Click "Scan Posts & Pages" to detect title tag issues across your site.', 'seo-autofix-pro' ); ?></p>
    </div>

    <!-- ===== Row Template ===== -->
    <template id="titletag-row-template">
        <tr class="titletag-row" data-post-id="" data-issue="">
            <td class="titletag-col-num"></td>
            <td class="titletag-col-title">
                <div class="titletag-post-title"></div>
                <div class="titletag-post-meta">
                    <span class="titletag-post-type-badge"></span>
                    &bull;
                    <a class="titletag-edit-link" href="#" target="_blank" rel="noopener"><?php _e( 'Edit', 'seo-autofix-pro' ); ?></a>
                </div>
            </td>
            <td class="titletag-col-url">
                <a class="titletag-post-url" href="#" target="_blank" rel="noopener"></a>
            </td>
            <td class="titletag-col-current">
                <div class="titletag-current-title-text"></div>
                <div class="titletag-issue-badge"></div>
            </td>
            <td class="titletag-col-suggested">
                <div class="titletag-suggested-editable" contenteditable="true"></div>
                <div class="titletag-char-counter"><span class="titletag-char-count">0</span> <?php _e( 'chars', 'seo-autofix-pro' ); ?></div>
                <div class="titletag-generating-indicator" style="display:none;">
                    <span class="spinner is-active"></span>
                    <?php _e( 'Generating…', 'seo-autofix-pro' ); ?>
                </div>
            </td>
            <td class="titletag-col-actions">
                <button class="button button-secondary titletag-generate-btn">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php _e( 'Generate', 'seo-autofix-pro' ); ?>
                </button>
                <button class="button button-primary titletag-apply-btn" disabled>
                    <?php _e( 'Apply', 'seo-autofix-pro' ); ?>
                </button>
                <button class="button titletag-skip-btn">
                    <?php _e( 'Skip', 'seo-autofix-pro' ); ?>
                </button>
                <div class="titletag-action-status"></div>
            </td>
        </tr>
    </template>

</div><!-- .titletag-admin -->
