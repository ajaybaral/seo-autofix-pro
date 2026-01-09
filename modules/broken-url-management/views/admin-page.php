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
    <h1 class="seoautofix-page-title">
        <?php echo esc_html__('404 & Broken URL Management', 'seo-autofix-pro'); ?>
    </h1>
    
    <div class="seoautofix-notice-info">
        <p>
            <strong><?php echo esc_html__('What this module does:', 'seo-autofix-pro'); ?></strong>
            <?php echo esc_html__('Scans your website for broken links (404, 4xx, 5xx errors) and suggests the closest relevant replacements for internal links. For external links, you can manually provide new URLs or delete them.', 'seo-autofix-pro'); ?>
        </p>
    </div>

    <!-- Scan Control Section -->
    <div class="seoautofix-card">
        <h2><?php echo esc_html__('Scan Control', 'seo-autofix-pro'); ?></h2>
        
        <div class="seoautofix-scan-controls">
            <button id="start-scan-btn" class="button button-primary button-large">
                <span class="dashicons dashicons-search"></span>
                <?php echo esc_html__('Start New Scan', 'seo-autofix-pro'); ?>
            </button>
            
            <?php if ($latest_scan_id): ?>
                <button id="view-latest-scan-btn" class="button button-secondary button-large" data-scan-id="<?php echo esc_attr($latest_scan_id); ?>">
                    <span class="dashicons dashicons-visibility"></span>
                    <?php echo esc_html__('View Last Scan Results', 'seo-autofix-pro'); ?>
                </button>
            <?php endif; ?>
            
            <?php if (!empty($recent_scans) && count($recent_scans) > 1): ?>
                <div class="seoautofix-scan-history">
                    <label for="scan-history-select"><?php echo esc_html__('Or view previous scan:', 'seo-autofix-pro'); ?></label>
                    <select id="scan-history-select">
                        <option value=""><?php echo esc_html__('Select a scan...', 'seo-autofix-pro'); ?></option>
                        <?php foreach ($recent_scans as $scan): ?>
                            <option value="<?php echo esc_attr($scan['scan_id']); ?>">
                                <?php 
                                echo esc_html(
                                    sprintf(
                                        __('%s - %d broken links', 'seo-autofix-pro'),
                                        date('M d, Y g:i A', strtotime($scan['started_at'])),
                                        $scan['total_broken_links']
                                    )
                                ); 
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
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
    </div>

    <!-- Results Section -->
    <div id="results-container" style="display: none;">
        <div class="seoautofix-card">
            <div class="seoautofix-results-header">
                <h2>
                    <?php echo esc_html__('Broken Links Found:', 'seo-autofix-pro'); ?>
                    <span id="total-broken-count" class="seoautofix-count-badge">0</span>
                </h2>
                
                <div class="seoautofix-results-actions">
                    <button id="apply-selected-fixes-btn" class="button button-primary">
                        <span class="dashicons dashicons-yes"></span>
                        <?php echo esc_html__('Apply Selected Fixes', 'seo-autofix-pro'); ?>
                    </button>
                    <button id="export-results-btn" class="button">
                        <span class="dashicons dashicons-download"></span>
                        <?php echo esc_html__('Export to CSV', 'seo-autofix-pro'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Filters and Search -->
            <div class="seoautofix-filters">
                <div class="seoautofix-filter-buttons">
                    <button class="seoautofix-filter-btn active" data-filter="all">
                        <?php echo esc_html__('All', 'seo-autofix-pro'); ?>
                        <span class="filter-count" id="filter-count-all">0</span>
                    </button>
                    <button class="seoautofix-filter-btn" data-filter="internal">
                        <?php echo esc_html__('Internal', 'seo-autofix-pro'); ?>
                        <span class="filter-count" id="filter-count-internal">0</span>
                    </button>
                    <button class="seoautofix-filter-btn" data-filter="external">
                        <?php echo esc_html__('External', 'seo-autofix-pro'); ?>
                        <span class="filter-count" id="filter-count-external">0</span>
                    </button>
                </div>
                
                <div class="seoautofix-search">
                    <input 
                        type="text" 
                        id="search-results" 
                        placeholder="<?php echo esc_attr__('Search URLs...', 'seo-autofix-pro'); ?>"
                        class="regular-text"
                    />
                    <span class="dashicons dashicons-search"></span>
                </div>
            </div>
            
            <!-- Results Table -->
            <div class="seoautofix-table-container">
                <table class="wp-list-table widefat fixed striped seoautofix-results-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="select-all-results" />
                            </th>
                            <th class="column-serial"><?php echo esc_html__('#', 'seo-autofix-pro'); ?></th>
                            <th class="column-type"><?php echo esc_html__('Type', 'seo-autofix-pro'); ?></th>
                            <th class="column-current-url"><?php echo esc_html__('Current URL', 'seo-autofix-pro'); ?></th>
                            <th class="column-suggested-url"><?php echo esc_html__('Suggested URL', 'seo-autofix-pro'); ?></th>
                            <th class="column-reason"><?php echo esc_html__('Reason', 'seo-autofix-pro'); ?></th>
                            <th class="column-actions"><?php echo esc_html__('Delete', 'seo-autofix-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="results-table-body">
                        <!-- Results will be loaded via JavaScript -->
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div id="pagination-container" class="seoautofix-pagination">
                <!-- Pagination will be loaded via JavaScript -->
            </div>
        </div>
    </div>
    
    <!-- Empty State -->
    <div id="empty-state" class="seoautofix-empty-state">
        <div class="seoautofix-empty-state-icon">
            <span class="dashicons dashicons-yes-alt"></span>
        </div>
        <h3><?php echo esc_html__('No broken links found', 'seo-autofix-pro'); ?></h3>
        <p><?php echo esc_html__('Start a scan to check for broken links on your website.', 'seo-autofix-pro'); ?></p>
    </div>
</div>
