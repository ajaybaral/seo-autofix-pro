<?php
/**
 * Admin Page View - Broken URL Management
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get database manager for fetching latest scan
$db_manager = new \SEOAutoFix\BrokenUrlManagement\Database_Manager();
$latest_scan_id = $db_manager->get_latest_scan_id();
$recent_scans = $db_manager->get_scans(10);

?>

<div class="wrap seoautofix-broken-urls">
    <!-- Header Section -->
    <div class="seoautofix-header">
        <h1 class="seoautofix-page-title">
            <?php echo esc_html__('Broken Link Scanner & Fixer', 'seo-autofix-pro'); ?>
        </h1>

        <div class="seoautofix-header-stats">
            <span class="stat-badge stat-broken">
                <?php echo esc_html__('Broken Links:', 'seo-autofix-pro'); ?>
                <strong id="header-broken-count">0</strong>
            </span>
            <span class="stat-badge stat-4xx">
                <?php echo esc_html__('4xx Errors:', 'seo-autofix-pro'); ?>
                <strong id="header-4xx-count">0</strong>
            </span>
            <span class="stat-badge stat-5xx">
                <?php echo esc_html__('5xx Errors:', 'seo-autofix-pro'); ?>
                <strong id="header-5xx-count">0</strong>
            </span>
        </div>

        <div class="seoautofix-header-actions">
            <button id="export-report-btn" class="button button-secondary" disabled>
                <?php echo esc_html__('Export Report', 'seo-autofix-pro'); ?>
            </button>
            <button id="start-auto-fix-btn" class="button button-primary">
                <?php echo esc_html__('Start Scan', 'seo-autofix-pro'); ?>
            </button>
        </div>
    </div>

    <!-- Scan Progress Bar (hidden by default) -->
    <div id="scan-progress-container" class="seoautofix-progress-container" style="display: none;">
        <div class="seoautofix-progress-info">
            <span id="scan-progress-text"><?php echo esc_html__('Scanning...', 'seo-autofix-pro'); ?></span>
            <span id="scan-progress-percentage">0%</span>
        </div>
        <div class="seoautofix-progress-bar">
            <div id="scan-progress-fill" class="seoautofix-progress-fill"></div>
        </div>
        <div class="seoautofix-progress-details">
            <span id="scan-urls-tested">0</span> / <span id="scan-urls-total">0</span>
            <?php echo esc_html__('URLs tested', 'seo-autofix-pro'); ?>
            &nbsp;|&nbsp;
            <span id="scan-broken-count">0</span>
            <?php echo esc_html__('broken links found', 'seo-autofix-pro'); ?>
        </div>
    </div>

    <!-- Results Section -->
    <div id="results-container" style="display: none;">
        <!-- Filters and Search -->
        <div class="seoautofix-filters-new">
            <div class="filter-dropdowns">
                <select id="filter-page-type" class="filter-select">
                    <option value="all"><?php echo esc_html__('Showing Published Pages Only', 'seo-autofix-pro'); ?>
                    </option>
                    <option value="published"><?php echo esc_html__('Published Pages', 'seo-autofix-pro'); ?></option>
                    <option value="drafts"><?php echo esc_html__('Drafts', 'seo-autofix-pro'); ?></option>
                    <option value="all-pages"><?php echo esc_html__('All Pages', 'seo-autofix-pro'); ?></option>
                </select>

                <select id="filter-error-type" class="filter-select">
                    <option value="all"><?php echo esc_html__('All Errors', 'seo-autofix-pro'); ?></option>
                    <option value="4xx"><?php echo esc_html__('4xx Errors', 'seo-autofix-pro'); ?></option>
                    <option value="5xx"><?php echo esc_html__('5xx Errors', 'seo-autofix-pro'); ?></option>
                </select>

                <select id="filter-location" class="filter-select">
                    <option value="all"><?php echo esc_html__('All Locations', 'seo-autofix-pro'); ?></option>
                    <option value="header"><?php echo esc_html__('Header', 'seo-autofix-pro'); ?></option>
                    <option value="footer"><?php echo esc_html__('Footer', 'seo-autofix-pro'); ?></option>
                    <option value="content"><?php echo esc_html__('Content', 'seo-autofix-pro'); ?></option>
                    <option value="sidebar"><?php echo esc_html__('Sidebar', 'seo-autofix-pro'); ?></option>
                </select>
            </div>

            <div class="search-filter-group">
                <input type="text" id="search-results"
                    placeholder="<?php echo esc_attr__('Search URL...', 'seo-autofix-pro'); ?>" class="search-input" />
                <button id="filter-btn" class="button button-primary filter-button">
                    <?php echo esc_html__('Filter', 'seo-autofix-pro'); ?>
                </button>
            </div>
        </div>

        <!-- Pagination Top -->
        <div class="pagination-info-top">
            <span><?php echo esc_html__('Page', 'seo-autofix-pro'); ?> <span id="current-page-top">1</span>
                <?php echo esc_html__('of', 'seo-autofix-pro'); ?> <span id="total-pages-top">1</span></span>
            <div class="pagination-controls-top">
                <!-- Pagination buttons will be created dynamically by JavaScript -->
            </div>
        </div>

        <!-- Results Table -->
        <div class="seoautofix-table-container-new">
            <table class="broken-links-table">
                <thead>
                    <tr>
                        <th class="column-page"><?php echo esc_html__('Page', 'seo-autofix-pro'); ?></th>
                        <th class="column-broken-link"><?php echo esc_html__('Broken Link', 'seo-autofix-pro'); ?></th>
                        <th class="column-link-type"><?php echo esc_html__('Link Type', 'seo-autofix-pro'); ?></th>
                        <th class="column-status"><?php echo esc_html__('Status', 'seo-autofix-pro'); ?></th>
                        <th class="column-action"><?php echo esc_html__('Action', 'seo-autofix-pro'); ?></th>
                    </tr>
                </thead>
                <tbody id="results-table-body">
                    <!-- Results will be loaded via JavaScript -->
                </tbody>
            </table>
        </div>

        <!-- Pagination Bottom -->
        <div class="pagination-info-bottom">
            <span><?php echo esc_html__('Page', 'seo-autofix-pro'); ?> <span id="current-page-bottom">1</span>
                <?php echo esc_html__('of', 'seo-autofix-pro'); ?> <span id="total-pages-bottom">2</span></span>
            <div class="pagination-controls-bottom" id="pagination-container">
                <!-- Pagination will be loaded via JavaScript -->
            </div>
        </div>

        <!-- Auto Fix Panel (Hidden by default) -->
        <div id="auto-fix-panel" class="auto-fix-panel" style="display: none;">
            <h3><?php echo esc_html__('Auto Fix Broken Links', 'seo-autofix-pro'); ?></h3>

            <div class="auto-fix-content">
                <div class="fix-item-info">
                    <strong id="fix-page-name"></strong>
                </div>

                <div class="fix-broken-link">
                    <span><?php echo esc_html__('Broken Link:', 'seo-autofix-pro'); ?></span>
                    <span id="fix-broken-url" class="broken-url-text"></span>
                    <span id="fix-error-badge" class="error-badge"></span>
                </div>

                <div class="fix-suggestion">
                    <span><?php echo esc_html__('Suggested Redirect:', 'seo-autofix-pro'); ?></span>
                    <a href="#" id="fix-suggested-url" class="suggested-url-link" target="_blank"></a>
                </div>

                <div class="fix-options">
                    <label class="fix-option">
                        <input type="radio" name="fix-action" value="suggested" checked />
                        <?php echo esc_html__('Use Suggested URL', 'seo-autofix-pro'); ?>
                    </label>

                    <label class="fix-option">
                        <input type="radio" name="fix-action" value="custom" />
                        <span class="dashicons dashicons-plus"></span>
                        <?php echo esc_html__('Enter Custom URL', 'seo-autofix-pro'); ?>
                    </label>

                    <div id="custom-url-input" class="custom-url-input" style="display: none;">
                        <input type="url" id="custom-url-field"
                            placeholder="<?php echo esc_attr__('Enter custom URL...', 'seo-autofix-pro'); ?>" />
                    </div>

                    <label class="fix-option">
                        <input type="radio" name="fix-action" value="home" />
                        <?php echo esc_html__('Or Redirect to Home Page', 'seo-autofix-pro'); ?>
                        <span class="home-url-display" style="color: #666; font-size: 0.9em; margin-left: 8px;">
                            (<?php echo esc_html(home_url('/')); ?>)
                        </span>
                    </label>

                    <div class="fix-delete-option">
                        <button id="delete-broken-link-btn" class="button button-link-delete">
                            <span class="dashicons dashicons-trash"></span>
                            <?php echo esc_html__('Delete This Broken Link', 'seo-autofix-pro'); ?>
                        </button>
                    </div>
                </div>

                <div class="fix-actions">
                    <button id="apply-fix-btn" class="button button-primary">
                        <?php echo esc_html__('Apply Fix', 'seo-autofix-pro'); ?>
                    </button>
                    <button id="skip-fix-btn" class="button button-secondary">
                        <?php echo esc_html__('Skip', 'seo-autofix-pro'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Bulk Action Buttons -->
        <div class="bulk-actions-section">
            <div class="bulk-actions-left">
                <button id="remove-broken-links-btn" class="button">
                    <?php echo esc_html__('Remove Broken Links', 'seo-autofix-pro'); ?>
                </button>
                <button id="replace-broken-links-btn" class="button">
                    <?php echo esc_html__('Replace Broken Links', 'seo-autofix-pro'); ?>
                </button>
            </div>

            <div class="bulk-actions-right">
                <button id="fix-all-issues-btn" class="button button-success">
                    <?php echo esc_html__('Fix All Issues', 'seo-autofix-pro'); ?>
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </button>
            </div>
        </div>

        <!-- History & Export Section -->
        <div class="history-export-section">
            <button id="undo-changes-btn" class="button">
                <span class="dashicons dashicons-undo"></span>
                <?php echo esc_html__('Undo Changes', 'seo-autofix-pro'); ?>
            </button>

            <button id="download-report-btn" class="button">
                <span class="dashicons dashicons-download"></span>
                <?php echo esc_html__('Download Fixed Report', 'seo-autofix-pro'); ?>
            </button>

            <button id="email-report-btn" class="button">
                <span class="dashicons dashicons-email"></span>
                <?php echo esc_html__('Email Fixed Report', 'seo-autofix-pro'); ?>
            </button>
        </div>
    </div>

    <!-- Empty State / Success State -->
    <div id="empty-state" class="seoautofix-empty-state" style="display: none;">
        <div class="seoautofix-empty-state-icon success">
            <span class="dashicons dashicons-yes-alt"></span>
        </div>
        <h3><?php echo esc_html__('All Issues Fixed! No Broken Links Found.', 'seo-autofix-pro'); ?></h3>

        <div class="empty-state-actions">
            <button id="download-report-empty-btn" class="button">
                <span class="dashicons dashicons-download"></span>
                <?php echo esc_html__('Download Fixed Report', 'seo-autofix-pro'); ?>
                <span class="file-format">.csv</span>
            </button>

            <button id="email-report-empty-btn" class="button">
                <span class="dashicons dashicons-email"></span>
                <?php echo esc_html__('Email Fixed Report', 'seo-autofix-pro'); ?>
                <span class="email-icon">✉</span>
            </button>
        </div>
    </div>
</div>