<?php
/**
 * Debug Logs Settings Page
 * 
 * Admin page for viewing and managing plugin debug logs
 * 
 * @package SEO_AutoFix_Pro
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get log reader instance
require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-log-reader.php';
$log_reader = new SEO_AutoFix_Log_Reader();

// Get stats
$stats = $log_reader->get_stats();
$file_size = $log_reader->get_log_file_size();
$modules = $log_reader->get_available_modules();
?>

<div class="wrap seoautofix-logs-page">
    <h1><?php echo esc_html__('Debug Logs', 'seo-autofix-pro'); ?></h1>
    <p class="description" style="margin-bottom: 20px;">
        <?php echo esc_html__('Viewing WordPress debug.log file:', 'seo-autofix-pro'); ?> 
        <code><?php echo esc_html(WP_CONTENT_DIR . '/debug.log'); ?></code>
    </p>

    <div class="log-stats-cards">
        <div class="stat-card">
            <div class="stat-label"><?php echo esc_html__('Total Entries', 'seo-autofix-pro'); ?></div>
            <div class="stat-value"><?php echo esc_html(number_format($stats['total'])); ?></div>
        </div>
        <div class="stat-card error">
            <div class="stat-label"><?php echo esc_html__('Errors', 'seo-autofix-pro'); ?></div>
            <div class="stat-value"><?php echo esc_html(number_format($stats['errors'])); ?></div>
        </div>
        <div class="stat-card warning">
            <div class="stat-label"><?php echo esc_html__('Warnings', 'seo-autofix-pro'); ?></div>
            <div class="stat-value"><?php echo esc_html(number_format($stats['warnings'])); ?></div>
        </div>
        <div class="stat-card info">
            <div class="stat-label"><?php echo esc_html__('Info', 'seo-autofix-pro'); ?></div>
            <div class="stat-value"><?php echo esc_html(number_format($stats['info'])); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><?php echo esc_html__('File Size', 'seo-autofix-pro'); ?></div>
            <div class="stat-value"><?php echo esc_html($file_size); ?></div>
        </div>
    </div>

    <div class="log-controls">
        <div class="log-controls-left">
            <input 
                type="text" 
                id="log-search" 
                placeholder="<?php echo esc_attr__('Search logs...', 'seo-autofix-pro'); ?>"
                class="regular-text"
            >

            <select id="log-level-filter">
                <option value="all"><?php echo esc_html__('All Levels', 'seo-autofix-pro'); ?></option>
                <option value="ERROR"><?php echo esc_html__('Errors', 'seo-autofix-pro'); ?></option>
                <option value="WARNING"><?php echo esc_html__('Warnings', 'seo-autofix-pro'); ?></option>
                <option value="INFO"><?php echo esc_html__('Info', 'seo-autofix-pro'); ?></option>
                <option value="DEBUG"><?php echo esc_html__('Debug', 'seo-autofix-pro'); ?></option>
            </select>

            <select id="log-module-filter">
                <option value="all"><?php echo esc_html__('All Modules', 'seo-autofix-pro'); ?></option>
                <?php foreach ($modules as $module) : ?>
                    <option value="<?php echo esc_attr($module); ?>">
                        <?php echo esc_html($module); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button id="refresh-logs" class="button">
                <span class="dashicons dashicons-update"></span>
                <?php echo esc_html__('Refresh', 'seo-autofix-pro'); ?>
            </button>
        </div>

        <div class="log-controls-right">
            <label class="auto-refresh-label">
                <input type="checkbox" id="auto-refresh">
                <?php echo esc_html__('Auto-refresh (5s)', 'seo-autofix-pro'); ?>
            </label>

            <button id="download-logs" class="button">
                <span class="dashicons dashicons-download"></span>
                <?php echo esc_html__('Download', 'seo-autofix-pro'); ?>
            </button>

            <button id="test-log" class="button button-secondary">
                <span class="dashicons dashicons-yes"></span>
                <?php echo esc_html__('Write Test Log', 'seo-autofix-pro'); ?>
            </button>

            <button id="clear-logs" class="button button-secondary">
                <span class="dashicons dashicons-trash"></span>
                <?php echo esc_html__('Clear Logs', 'seo-autofix-pro'); ?>
            </button>
        </div>
    </div>

    <div id="log-viewer">
        <div id="log-loading" class="log-loading">
            <span class="spinner is-active"></span>
            <span><?php echo esc_html__('Loading logs...', 'seo-autofix-pro'); ?></span>
        </div>

        <table class="wp-list-table widefat fixed striped" id="log-table">
            <thead>
                <tr>
                    <th style="width: 160px;"><?php echo esc_html__('Timestamp', 'seo-autofix-pro'); ?></th>
                    <th style="width: 90px;"><?php echo esc_html__('Level', 'seo-autofix-pro'); ?></th>
                    <th style="width: 130px;"><?php echo esc_html__('Module', 'seo-autofix-pro'); ?></th>
                    <th><?php echo esc_html__('Message', 'seo-autofix-pro'); ?></th>
                </tr>
            </thead>
            <tbody id="log-table-body">
                <!-- Logs will be loaded here via AJAX -->
            </tbody>
        </table>

        <div id="log-no-results" class="log-no-results" style="display: none;">
            <p><?php echo esc_html__('No log entries found.', 'seo-autofix-pro'); ?></p>
        </div>
    </div>

    <div class="log-pagination">
        <button id="load-more-logs" class="button button-primary" style="display: none;">
            <?php echo esc_html__('Load More', 'seo-autofix-pro'); ?>
        </button>
        <span id="log-count-display"></span>
    </div>
</div>

<script type="text/javascript">
    var seoautofixLogs = {
        nonce: '<?php echo wp_create_nonce('seoautofix_logs'); ?>',
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>'
    };
</script>
