<?php
/**
 * Image SEO Module - Main Class
 * 
 * @package SEO_AutoFix_Pro
 * @subpackage Image_SEO
 */

namespace SEOAutoFix\ImageSEO;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Image SEO Module Class
 */
class SEOAutoFix_Image_SEO {
    
    /**
     * Module version
     */
    const VERSION = '1.0.0';
    
    /**
     * API Manager instance
     */
    private $api_manager;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Rollback instance
     */
    private $rollback;
    
    /**
     * Image Analyzer instance
     */
    private $analyzer;
    
    /**
     * Alt Generator instance
     */
    private $alt_generator;
    
    /**
     * SEO Scorer instance
     */
    private $seo_scorer;
    
    /**
     * Usage Tracker instance
     */
    private $usage_tracker;
    
    /**
     * Image History instance
     */
    private $image_history;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Define module constants
        if (!defined('IMAGESEO_MODULE_DIR')) {
            define('IMAGESEO_MODULE_DIR', dirname(__FILE__) . '/');
        }
        if (!defined('IMAGESEO_MODULE_URL')) {
            define('IMAGESEO_MODULE_URL', plugin_dir_url(__FILE__));
        }
        
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize classes
        $this->init_classes();
        
        // Register hooks
        $this->register_hooks();
        
        // Ensure tables exist (create if missing)
        $this->ensure_tables_exist();
        
        // Module activation
        register_activation_hook(SEOAUTOFIX_PLUGIN_FILE, array($this, 'activate'));
    }
    
    /**
     * Load module dependencies
     */
    private function load_dependencies() {
        require_once IMAGESEO_MODULE_DIR . 'class-api-manager.php';
        require_once IMAGESEO_MODULE_DIR . 'class-logger.php';
        require_once IMAGESEO_MODULE_DIR . 'class-rollback.php';
        require_once IMAGESEO_MODULE_DIR . 'class-image-analyzer.php';
        require_once IMAGESEO_MODULE_DIR . 'class-alt-generator.php';
        require_once IMAGESEO_MODULE_DIR . 'class-seo-scorer.php';
        require_once IMAGESEO_MODULE_DIR . 'class-image-usage-tracker.php';
        require_once IMAGESEO_MODULE_DIR . 'class-image-history.php';
    }
    
    /**
     * Initialize class instances
     */
    private function init_classes() {
        $this->api_manager = new API_Manager();
        $this->logger = new Logger();
        $this->rollback = new Rollback();
        $this->analyzer = new Image_Analyzer();
        $this->alt_generator = new Alt_Generator($this->api_manager);
        $this->seo_scorer = new SEO_Scorer($this->api_manager);
        $this->usage_tracker = new Image_Usage_Tracker();
        $this->image_history = new Image_History();
    }
    
    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Admin menu
        add_action('admin_menu', array($this, 'register_admin_menu'));
        
        // Admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // AJAX actions
        add_action('wp_ajax_imageseo_scan', array($this, 'ajax_scan_images'));
        add_action('wp_ajax_imageseo_generate', array($this, 'ajax_generate_alt'));
        add_action('wp_ajax_imageseo_score', array($this, 'ajax_score_alt'));
        add_action('wp_ajax_imageseo_apply', array($this, 'ajax_apply_change'));
        add_action('wp_ajax_imageseo_skip', array($this, 'ajax_skip_image'));
        add_action('wp_ajax_imageseo_bulk_apply', array($this, 'ajax_bulk_apply'));
        add_action('wp_ajax_imageseo_export_audit', array($this, 'ajax_export_audit_csv'));
        add_action('wp_ajax_imageseo_migrate_db', array($this, 'ajax_migrate_database'));
        add_action('wp_ajax_imageseo_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_imageseo_delete_image', array($this, 'ajax_delete_image'));
        add_action('wp_ajax_imageseo_email_csv', array($this, 'ajax_email_csv'));
        add_action('wp_ajax_imageseo_get_unused_count', array($this, 'ajax_get_unused_count'));
        add_action('wp_ajax_imageseo_create_unused_zip', array($this, 'ajax_create_unused_zip'));
        add_action('wp_ajax_imageseo_bulk_delete_unused', array($this, 'ajax_bulk_delete_unused'));
        add_action('wp_ajax_imageseo_validate_alt_text', array($this, 'ajax_validate_alt_text'));
        
        // NEW: Cleanup & Delete Tab Handlers
        add_action('wp_ajax_imageseo_remove_all_alt', array($this, 'ajax_remove_all_alt'));
        add_action('wp_ajax_imageseo_delete_by_url', array($this, 'ajax_delete_by_url'));
    }
    
    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        add_submenu_page(
            'seoautofix-settings',
            __('Image SEO', 'seo-autofix-pro'),
            __('Image SEO', 'seo-autofix-pro'),
            'manage_options',
            'seoautofix-image-seo',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        // Only load on this module's page
        if ($hook !== 'seo-autofix-pro_page_seoautofix-image-seo') {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'imageseo-admin-css',
            IMAGESEO_MODULE_URL . 'assets/css/image-seo.css',
            array(),
            self::VERSION
        );
        
        // JavaScript
        wp_enqueue_script(
            'imageseo-admin-js',
            IMAGESEO_MODULE_URL . 'assets/js/image-seo.js',
            array('jquery'),
            self::VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('imageseo-admin-js', 'imageSeoData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('imageseo_nonce'),
            'moduleUrl' => IMAGESEO_MODULE_URL,
            'hasApiKey' => \SEOAutoFix_Settings::is_api_configured(),
            'settingsUrl' => admin_url('admin.php?page=seoautofix-settings'),
            'strings' => array(
                'scanning' => __('Scanning images...', 'seo-autofix-pro'),
                'generating' => __('Generating AI suggestion...', 'seo-autofix-pro'),
                'scoring' => __('Scoring alt text...', 'seo-autofix-pro'),
                'applying' => __('Applying changes...', 'seo-autofix-pro'),
                'success' => __('Changes applied successfully!', 'seo-autofix-pro'),
                'error' => __('An error occurred. Please try again.', 'seo-autofix-pro'),
                'noApiKey' => __('OpenAI API key not configured', 'seo-autofix-pro'),
                'noApiKeyDesc' => __('AI suggestions are disabled. You can still scan images, manually edit alt text, and get real-time scores.', 'seo-autofix-pro'),
            )
        ));
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Load admin page template (no longer blocking access if no API key)
        require_once IMAGESEO_MODULE_DIR . 'views/admin-page.php';
    }
    
    /**
     * Render API key notice
     */
    private function render_api_key_notice() {
        ?>
        <div class="wrap">
            <h1><?php _e('Image SEO Optimizer', 'seo-autofix-pro'); ?></h1>
            <div class="notice notice-warning">
                <p>
                    <strong><?php _e('OpenAI API Key Required', 'seo-autofix-pro'); ?></strong><br>
                    <?php _e('Please configure your OpenAI API key in the settings to use this module.', 'seo-autofix-pro'); ?>
                </p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=seoautofix-settings'); ?>" class="button button-primary">
                        <?php _e('Go to Settings', 'seo-autofix-pro'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
    
    public function ajax_scan_images() {
        error_log('========================================');
        error_log('AJAX-DEBUG-PHP: ===== ajax_scan_images() CALLED =====');
        error_log('AJAX-DEBUG-PHP: Full $_POST data: ' . print_r($_POST, true));
        
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            error_log('AJAX-DEBUG-PHP: ❌ Permission denied');
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 50;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $status_filter = isset($_POST['status_filter']) ? sanitize_text_field($_POST['status_filter']) : 'blank';
        
        error_log('AJAX-DEBUG-PHP: Parameters extracted:');
        error_log('AJAX-DEBUG-PHP:   - batch_size: ' . $batch_size);
        error_log('AJAX-DEBUG-PHP:   - offset: ' . $offset);
        error_log('AJAX-DEBUG-PHP:   - status_filter: "' . $status_filter . '"');
        error_log('AJAX-DEBUG-PHP: Calling analyzer->scan_all_images()...');
        error_log('========================================');
        
        try {
            $results = $this->analyzer->scan_all_images($batch_size, $offset, $this->usage_tracker, $status_filter);
            
            // CALCULATE SEO SCORES for "Before" column
            foreach ($results as &$image) {
                if (!empty($image['current_alt'])) {
                    $usage_context = array('pages' => array(), 'posts' => array());
                    $score_data = $this->seo_scorer->score_alt_text($image['current_alt'], $usage_context);
                    $image['score_before'] = $score_data['score'];
                    $image['seo_score'] = $score_data['score'];
                    error_log('SCAN-SCORE-DEBUG: ID=' . $image['id'] . ' alt="' . substr($image['current_alt'], 0, 25) . '" score=' . $score_data['score']);
                } else {
                    $image['score_before'] = 0;
                    $image['seo_score'] = 0;
                    error_log('SCAN-SCORE-DEBUG: ID=' . $image['id'] . ' EMPTY alt, score=0');
                }
            }
            unset($image);
            
            error_log('FEATURE-DEBUG-PHP: Scan results count: ' . count($results));
            if (!empty($results)) {
                error_log('FEATURE-DEBUG-PHP: Sample image data: ' . print_r($results[0], true));
                error_log('FEATURE-DEBUG-PHP: Fields available: ' . implode(', ', array_keys($results[0])));
                error_log('FEATURE-DEBUG-PHP: NOW INCLUDES: score_before=' . ($results[0]['score_before'] ?? 'MISSING'));
                
                // Debug first 5 images' usage data
                error_log('USAGE-DEBUG: ===== CHECKING USAGE DATA FOR FIRST 5 IMAGES =====');
                for ($i = 0; $i < min(5, count($results)); $i++) {
                    $img = $results[$i];
                    error_log('USAGE-DEBUG: Image ID ' . $img['id'] . ' (' . $img['filename'] . '):');
                    error_log('USAGE-DEBUG:   used_in_posts = ' . $img['used_in_posts']);
                    error_log('USAGE-DEBUG:   used_in_pages = ' . $img['used_in_pages']);
                    error_log('USAGE-DEBUG:   Is used? ' . (($img['used_in_posts'] > 0 || $img['used_in_pages'] > 0) ? 'YES' : 'NO'));
                }
            }
            
            // On first batch, populate history table with ALL images
            if ($offset === 0) {
                $this->populate_all_images_in_history();
            }
            
            $response_data = array(
                'results' => $results,
                'offset' => $offset + $batch_size,
                'hasMore' => count($results) === $batch_size
            );

            if ($offset === 0) {
                // Get statistics from history table
                $response_data['stats'] = $this->image_history->get_statistics();
            }

            wp_send_json_success($response_data);
            
        } catch (\Exception $e) {
            $this->logger->log_error('Scan failed', $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Generate AI alt text
     */
    public function ajax_generate_alt() {
        error_log('DEBUG-FLOW-PHP: ====== ajax_generate_alt() CALLED ======');
        error_log('DEBUG-FLOW-PHP: This is the SERVER-SIDE handler for AI generation');
        
        check_ajax_referer('imageseo_nonce', 'nonce');
        error_log('DEBUG-FLOW-PHP: Nonce verified');
        
        if (!current_user_can('manage_options')) {
            error_log('DEBUG-FLOW-PHP: Permission denied');
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Check if API key is configured
        if (!\SEOAutoFix_Settings::is_api_configured()) {
            error_log('DEBUG-FLOW-PHP: No API key configured');
            wp_send_json_error(array('message' => 'OpenAI API key not configured. Please add your API key in settings.'));
        }
        
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        error_log('DEBUG-FLOW-PHP: Processing attachment ID: ' . $attachment_id);
        
        if (!$attachment_id) {
            error_log('DEBUG-FLOW-PHP: Invalid attachment ID');
            wp_send_json_error(array('message' => 'Invalid attachment ID'));
        }
        
        try {
            global $wpdb;
            $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';
            
            error_log('DEBUG-FLOW-PHP: Checking for cached suggestion');
            // Check for cached suggestion (within last 30 days)
            $cached = $wpdb->get_row($wpdb->prepare(
                "SELECT suggested_alt FROM $table_audit 
                WHERE attachment_id = %d 
                AND suggested_alt IS NOT NULL 
                AND suggested_alt != '' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY created_at DESC LIMIT 1",
                $attachment_id
            ));
            
            if ($cached && !empty($cached->suggested_alt)) {
                error_log('DEBUG-FLOW-PHP: Found cached suggestion: ' . $cached->suggested_alt);
                wp_send_json_success(array(
                    'alt_text' => $cached->suggested_alt,
                    'attachment_id' => $attachment_id,
                    'cached' => true
                ));
            }
            
            error_log('DEBUG-FLOW-PHP: No cache found, generating NEW AI suggestion');
            error_log('DEBUG-FLOW-PHP: *** CALLING OpenAI API via alt_generator ***');
            
            // Generate new suggestion via AI
            $context = $this->usage_tracker->get_image_usage($attachment_id);
            $alt_text = $this->alt_generator->generate_alt_text($attachment_id, $context);
            
            error_log('DEBUG-FLOW-PHP: AI generation successful: ' . $alt_text);
            
            // Get current alt text and issue type for audit
            $current_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $issue_type = $this->analyzer->classify_issue($attachment_id, $current_alt);
            
            // Save to audit table for caching
            $wpdb->insert(
                $table_audit,
                array(
                    'attachment_id' => $attachment_id,
                    'issue_type' => $issue_type,
                    'original_alt' => $current_alt,
                    'suggested_alt' => $alt_text,
                    'status' => 'pending',
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s')
            );
            
            error_log('DEBUG-FLOW-PHP: Saved to audit table, sending success response');
            
            wp_send_json_success(array(
                'alt_text' => $alt_text,
                'attachment_id' => $attachment_id,
                'cached' => false
            ));
            
        } catch (\Exception $e) {
            error_log('DEBUG-FLOW-PHP: Exception occurred: ' . $e->getMessage());
            $this->logger->log_error('Generation failed', $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Score alt text
     */
    public function ajax_score_alt() {
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // No API key check needed for scoring as it uses basic local scoring
        
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        $alt_text = isset($_POST['alt_text']) ? sanitize_text_field($_POST['alt_text']) : '';
        
        if (!$attachment_id || empty($alt_text)) {
            wp_send_json_error(array('message' => 'Invalid data'));
        }
        
        try {
            $context = $this->usage_tracker->get_image_usage($attachment_id);
            $score = $this->seo_scorer->score_alt_text($alt_text, $context);
            
            wp_send_json_success(array(
                'score' => $score,
                'attachment_id' => $attachment_id
            ));
            
        } catch (\Exception $e) {
            $this->logger->log_error('Scoring failed', $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Apply changes
     */
    public function ajax_apply_change() {
        error_log('====== APPLY-CHANGE BACKEND DEBUG START ======');
        error_log('APPLY-BACKEND-DEBUG: ajax_apply_change() called');
        error_log('APPLY-BACKEND-DEBUG: Request data: ' . json_encode($_POST));
        
        check_ajax_referer('imageseo_nonce', 'nonce');
        error_log('APPLY-BACKEND-DEBUG: ✓ Nonce verified');
        
        if (!current_user_can('manage_options')) {
            error_log('APPLY-BACKEND-DEBUG: ✗ ERROR - User lacks permissions');
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        error_log('APPLY-BACKEND-DEBUG: ✓ User has permissions');
        
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        $new_alt = isset($_POST['alt_text']) ? sanitize_text_field($_POST['alt_text']) : '';
        
        error_log('APPLY-BACKEND-DEBUG: Attachment ID: ' . $attachment_id);
        error_log('APPLY-BACKEND-DEBUG: New alt text: "' . $new_alt . '"');
        
        if (!$attachment_id || empty($new_alt)) {
            wp_send_json_error(array('message' => 'Invalid data'));
        }
        
        try {
            global $wpdb;
            $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';
            $table_history = $wpdb->prefix . 'seoautofix_imageseo_history';
            
            // CRITICAL DEBUG: Log the apply attempt
            error_log('========================================');
            error_log('APPLY-CHANGE-DEBUG: [Backend] ===== ajax_apply_change() CALLED =====');
            error_log('APPLY-CHANGE-DEBUG: [Backend] Attachment ID: ' . $attachment_id);
            error_log('APPLY-CHANGE-DEBUG: [Backend] New alt text: ' . $new_alt);
            
            // CRITICAL SAFETY CHECK: Get current status from history table
            $current_history = $wpdb->get_row($wpdb->prepare(
                "SELECT status, issue_type FROM $table_history WHERE attachment_id = %d LIMIT 1",
                $attachment_id
            ));
            
            error_log('APPLY-CHANGE-DEBUG: [Backend] Current status in DB: ' . ($current_history ? $current_history->status : 'NULL'));
            error_log('APPLY-CHANGE-DEBUG: [Backend] Current issue_type in DB: ' . ($current_history ? $current_history->issue_type : 'NULL'));
            
            // BLOCK re-applying to already-optimized images
            if ($current_history && ($current_history->status === 'optimal' || $current_history->status === 'optimized')) {
                error_log('❌ APPLY-CHANGE-ERROR: [Backend] ===== BLOCKING RE-APPLY =====');
                error_log('❌ APPLY-CHANGE-ERROR: [Backend] Image is ALREADY OPTIMIZED! Status: ' . $current_history->status);
                error_log('❌ APPLY-CHANGE-ERROR: [Backend] This should NOT happen - frontend should block this!');
                error_log('❌ APPLY-CHANGE-ERROR: [Backend] ===== ACTION BLOCKED =====');
                error_log('========================================');
                
                wp_send_json_error(array(
                    'message' => 'This image is already optimized and cannot be re-applied.'
                ));
                return;
            }
            
            error_log('========================================');
            
            // Get old values
            $old_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
             $old_title = get_the_title($attachment_id);
            $image_url = wp_get_attachment_url($attachment_id);
            $media_link = admin_url('post.php?post=' . $attachment_id . '&action=edit');
            
            error_log('APPLY-CHANGE-DEBUG: [Backend] Old alt text: ' . $old_alt);
            error_log('APPLY-CHANGE-DEBUG: [Backend] Old title: ' . $old_title);
            
            // Determine action type
            $action_type = empty($old_alt) ? 'Generated' : 'Optimized';
            
            // Store in rollback
            $this->rollback->store_change($attachment_id, '_wp_attachment_image_alt', $old_alt, $new_alt);
            
            // Update alt text
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $new_alt);
            
            // Update image title
            wp_update_post(array(
                'ID' => $attachment_id,
                'post_title' => $new_alt
            ));
            
            // CRITICAL FIX: Update audit table with proper previous alt text
            // Each edit should update: current → previous, new → current
            error_log('PREV-ALT-FIX-DEBUG: [Backend] Updating audit table');
            error_log('PREV-ALT-FIX-DEBUG: [Backend] Setting prev_alt to: ' . $old_alt);
            error_log('PREV-ALT-FIX-DEBUG: [Backend] Setting new_alt to: ' . $new_alt);
            
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_audit 
                SET prev_alt = %s,
                    new_alt = %s, 
                    prev_title = %s, 
                    new_title = %s, 
                    image_url = %s, 
                    media_link = %s, 
                    action_type = %s, 
                    status = 'applied', 
                    updated_at = %s 
                WHERE attachment_id = %d 
                AND status = 'pending' 
                ORDER BY created_at DESC 
                LIMIT 1",
                $old_alt,   // ✅ CRITICAL FIX: Now setting prev_alt properly
                $new_alt, $old_title, $new_alt, $image_url, $media_link, $action_type, current_time('mysql'), $attachment_id
            ));
            
            error_log('PREV-ALT-FIX-DEBUG: [Backend] Audit table updated successfully');
            
            // CRITICAL BUG FIX: Determine proper status based on score
            // When user clicks Apply, the image should be marked as optimized!
            // Calculate score for the new alt text
            $usage_context = array('pages' => array(), 'posts' => array());
            $score_data = $this->seo_scorer->score_alt_text($new_alt, $usage_context);
            $new_score = $score_data['score'];
            
            // Determine status based on score
            if ($new_score >= 75) {
                $status = 'optimal';  // High score = optimal
                error_log('APPLY-STATUS-FIX: Score ' . $new_score . ' >= 75 → status=optimal');
            } else {
                $status = 'optimized';  // Low score but user applied it manually = optimized
                error_log('APPLY-STATUS-FIX: Score ' . $new_score . ' < 75 → status=optimized (manual apply)');
            }
            
            // OLD BROKEN LOGIC (REMOVED):
            // $status = empty($old_alt) ? 'generate' : 'optimal';
            // This was WRONG because 'generate' means "queued for AI" not "applied"!
            
            error_log('APPLY-CHANGE-DEBUG: [Backend] Setting new status: ' . $status);
            error_log('APPLY-CHANGE-DEBUG: [Backend] New alt score: ' . $new_score);
            
            $this->image_history->update_image_history($attachment_id, array(
                'new_alt' => $new_alt,
                'new_title' => $new_alt,
                'status' => $status
            ));
            
            error_log('APPLY-CHANGE-DEBUG: [Backend] ✅ Image history updated successfully');
            error_log('========================================');
            
            // Log action
            $this->logger->log_action('apply_alt', $attachment_id, array(
                'old' => $old_alt,
                'new' => $new_alt
            ));
            
            
            wp_send_json_success(array(
                'message' => 'Alt text updated successfully'
            ));
            
        } catch (\Exception $e) {
            $this->logger->log_error('Apply failed', $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Skip image
     */
    public function ajax_skip_image() {
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        
        if (!$attachment_id) {
            wp_send_json_error(array('message' => 'Invalid attachment ID'));
        }
        
        global $wpdb;
        $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';
        
        // Get image data
        $current_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $title = get_the_title($attachment_id);
        $image_url = wp_get_attachment_url($attachment_id);
        $media_link = admin_url('post.php?post=' . $attachment_id . '&action=edit');
        
        // Update audit record or create new one
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_audit 
            SET image_url = %s, 
                media_link = %s, 
                action_type = 'Skip', 
                status = 'skipped', 
                updated_at = %s 
            WHERE attachment_id = %d 
            AND status = 'pending' 
            ORDER BY created_at DESC 
            LIMIT 1",
            $image_url, $media_link, current_time('mysql'), $attachment_id
        ));
        
        // Update image history table
        $this->image_history->update_image_history($attachment_id, array(
            'status' => 'skipped'
        ));
        
        // Log skip action
        $this->logger->log_action('skip', $attachment_id, array());
        
        wp_send_json_success(array('message' => 'Image skipped'));
    }
    
    /**
     * AJAX: Bulk apply
     */
    public function ajax_bulk_apply() {
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $changes = isset($_POST['changes']) ? json_decode(stripslashes($_POST['changes']), true) : array();
        
        if (empty($changes)) {
            wp_send_json_error(array('message' => 'No changes to apply'));
        }
        
        $applied = 0;
        $failed = 0;
        
        foreach ($changes as $change) {
            $attachment_id = absint($change['attachment_id']);
            $new_alt = sanitize_text_field($change['alt_text']);
            
            try {
                $old_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                $this->rollback->store_change($attachment_id, '_wp_attachment_image_alt', $old_alt, $new_alt);
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $new_alt);
                wp_update_post(array('ID' => $attachment_id, 'post_title' => $new_alt));
                $applied++;
            } catch (\Exception $e) {
                $failed++;
            }
        }
        
        wp_send_json_success(array(
            'applied' => $applied,
            'failed' => $failed
        ));
    }
    
    /**
     * Populate history table with all images from media library
     */
    private function populate_all_images_in_history() {
        error_log('IMAGESEO DEBUG: populate_all_images_in_history START');
        
        // Check if table exists
        global $wpdb;
        $table_name = $wpdb->prefix . 'seoautofix_image_history';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        error_log('IMAGESEO DEBUG: Table exists check: ' . ($table_exists ? 'YES' : 'NO'));
        
        if (!$table_exists) {
            error_log('IMAGESEO ERROR: History table does not exist!');
            return;
        }
        
        // Check existing row count BEFORE population
        $existing_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        error_log('IMAGESEO DEBUG: Existing rows in table BEFORE populate: ' . $existing_count);
        
        // Check for records with status other than 'blank' or 'optimal'
        $action_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status IN ('generate', 'optimized', 'skipped')");
        error_log('IMAGESEO DEBUG: Existing action records (generate/optimized/skipped): ' . $action_records);
        
        // Get ALL images from media library
        $all_images = get_posts(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => -1
        ));
        
        error_log('IMAGESEO DEBUG: Found ' . count($all_images) . ' images in media library');
        
        $success_count = 0;
        $error_count = 0;
        $updated_count = 0;
        $inserted_count = 0;
        $optimal_count = 0;
        $blank_count = 0;
        
        foreach ($all_images as $image) {
            $attachment_id = $image->ID;
            $current_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $current_title = get_the_title($attachment_id);
            
            // Check if record already exists
            $existing_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE attachment_id = %d",
                $attachment_id
            ));
            
            // If record exists with action status, DON'T overwrite it
            if ($existing_record && in_array($existing_record->status, array('generate', 'optimized', 'skipped'))) {
                error_log('IMAGESEO DEBUG: Skipping ID ' . $attachment_id . ' - has action status: ' . $existing_record->status);
                continue; // Skip this image to preserve its history
            }
            
            // Detect issues
            $issues = $this->analyzer->detect_issues($attachment_id);
            $issue_type = $this->analyzer->classify_issue($attachment_id, $current_alt);
            
            // CLASSIFICATION-DEBUG: Log issue detection
            error_log('CLASSIFICATION-DEBUG: Image ID ' . $attachment_id . ' alt="' . substr($current_alt, 0, 50) . '..." issues=' . json_encode($issues) . ' issue_type=' . $issue_type);
            
            // Determine status
            if (empty($issues)) {
                $status = 'optimal'; // Already has good alt text
                $optimal_count++;
                error_log('CLASSIFICATION-DEBUG: → Marked as OPTIMAL (no issues)');
            } else {
                $status = 'blank'; // Has issues, awaiting action
                $blank_count++;
                error_log('CLASSIFICATION-DEBUG: → Marked as BLANK (has ' . count($issues) . ' issues)');
                
                // Debug first few blank images
                if ($blank_count <= 5) {
                    error_log('IMAGESEO DEBUG: Image ID ' . $attachment_id . ' marked BLANK - alt="' . $current_alt . '", issues=' . print_r($issues, true));
                }
            }
            
            // Update or create history record
            $result = $this->image_history->update_image_history($attachment_id, array(
                'alt_history' => array($current_alt),
                'title_history' => array($current_title),
                'status' => $status,
                'issue_type' => $issue_type
            ));
            
            if ($result !== false) {
                $success_count++;
                if ($existing_record) {
                    $updated_count++;
                } else {
                    $inserted_count++;
                }
            } else {
                $error_count++;
                error_log('IMAGESEO ERROR: Failed to insert/update image ID: ' . $attachment_id);
            }
        }
        
        error_log('IMAGESEO DEBUG: Population complete. Success: ' . $success_count . ', Errors: ' . $error_count);
        error_log('IMAGESEO DEBUG: Updated: ' . $updated_count . ', Inserted: ' . $inserted_count);
        error_log('IMAGESEO DEBUG: Marked as OPTIMAL: ' . $optimal_count . ', Marked as BLANK: ' . $blank_count);
        
        // Check final row count AFTER population
        $final_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        error_log('IMAGESEO DEBUG: Final rows in table AFTER populate: ' . $final_count);
        
        // Check how many scan_all_images would return
        $scan_results = $this->analyzer->scan_all_images(999, 0);
        error_log('IMAGESEO DEBUG: scan_all_images returned ' . count($scan_results) . ' images WITH issues');
    }
    
    /**
     * AJAX: Export audit history to CSV
     */
    public function ajax_export_audit_csv() {
        error_log('FEATURE-DEBUG-PHP: === ajax_export_audit_csv() called ===');
        error_log('FEATURE-DEBUG-PHP: This is where EMAIL logic will be added');
        error_log('FEATURE-DEBUG-PHP: Will need WordPress admin email: ' . get_option('admin_email'));
        error_log('IMAGESEO CSV EXPORT: Function called');
        check_ajax_referer('imageseo_nonce', 'nonce');
        error_log('IMAGESEO CSV EXPORT: Nonce verified');
        
        if (!current_user_can('manage_options')) {
            error_log('IMAGESEO CSV EXPORT: Permission denied');
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        error_log('IMAGESEO CSV EXPORT: Getting all records for export');
        // Get all history records for export
        $results = $this->image_history->get_all_for_export();
        
        error_log('IMAGESEO CSV EXPORT: Found ' . count($results) . ' records');
        
        if (empty($results)) {
            error_log('IMAGESEO CSV EXPORT: No records found, returning error');
            wp_send_json_error(array('message' => 'No change history found'));
        }
        
        // Generate CSV content
        $csv_output = '';
        
        // CSV Headers
        $headers = array(
            'Media Link',
            'Previous Alt Text',
            'New Alt Text',
            'Image URL',
            'Previous Title',
            'New Title',
            'Type'
        );
        $csv_output .= '"' . implode('","', $headers) . '"' . "\n";
        
        // CSV Rows
        foreach ($results as $row) {
            // Decode JSON arrays
            $alt_history = json_decode($row->alt_history, true) ?: array();
            $title_history = json_decode($row->title_history, true) ?: array();
            
            // Get previous and current values
            $prev_alt = isset($alt_history[0]) ? $alt_history[0] : '';
            $new_alt = count($alt_history) > 1 ? end($alt_history) : $prev_alt;
            $prev_title = isset($title_history[0]) ? $title_history[0] : '';
            $new_title = count($title_history) > 1 ? end($title_history) : $prev_title;
            
            // Map status to action type for CSV
            $action_mapping = array(
                'generate' => 'Generated',
                'optimized' => 'Optimized',
                'skipped' => 'Skip',
                'blank' => 'Pending',
                'optimal' => 'Already Optimized'
            );
            $action_type = isset($action_mapping[$row->status]) ? $action_mapping[$row->status] : ucfirst($row->status);
            
            $csv_row = array(
                $row->media_link ?: '',
                $prev_alt,
                $new_alt,
                $row->image_url ?: '',
                $prev_title,
                $new_title,
                $action_type
            );
            
            // Escape and format each field
            $csv_output .= '"' . implode('","', array_map(function($field) {
                return str_replace('"', '""', $field);
            }, $csv_row)) . '"' . "\n";
        }
        
        // Send CSV response
        wp_send_json_success(array(
            'csv' => $csv_output,
            'filename' => 'image-seo-audit-' . date('Y-m-d') . '.csv',
            'count' => count($results)
        ));
    }
    /**
     * Module activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();
    }
    
    
    /**
     * Ensure database tables exist (auto-create if missing)
     */
    private function ensure_tables_exist() {
        global $wpdb;
        
        // Check if tables exist
        $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';
        $table_rollback = $wpdb->prefix . 'seoautofix_imageseo_rollback';
        $table_history = $wpdb->prefix . 'seoautofix_image_history';
        
        // Check if at least one table is missing
        $audit_exists = ($wpdb->get_var("SHOW TABLES LIKE '$table_audit'") == $table_audit);
        $rollback_exists = ($wpdb->get_var("SHOW TABLES LIKE '$table_rollback'") == $table_rollback);
        $history_exists = ($wpdb->get_var("SHOW TABLES LIKE '$table_history'") == $table_history);
        
        // If any table is missing, create all tables
        if (!$audit_exists || !$rollback_exists || !$history_exists) {
            $this->create_tables();
        }
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Audit table
        $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';
        $sql_audit = "CREATE TABLE IF NOT EXISTS $table_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attachment_id BIGINT UNSIGNED NOT NULL,
            issue_type VARCHAR(50) NOT NULL,
            original_alt TEXT,
            suggested_alt TEXT,
            new_alt TEXT,
            prev_title TEXT,
            new_title TEXT,
            image_url TEXT,
            media_link TEXT,
            action_type VARCHAR(20),
            ai_score_before INT,
            ai_score_after INT,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME,
            INDEX idx_attachment (attachment_id),
            INDEX idx_status (status),
            INDEX idx_action_type (action_type)
        ) $charset_collate;";
        
        // Rollback table
        $table_rollback = $wpdb->prefix . 'seoautofix_imageseo_rollback';
        $sql_rollback = "CREATE TABLE IF NOT EXISTS $table_rollback (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attachment_id BIGINT UNSIGNED NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            old_value TEXT,
            new_value TEXT,
            user_id BIGINT UNSIGNED,
            timestamp DATETIME NOT NULL,
            rolled_back TINYINT(1) DEFAULT 0,
            INDEX idx_attachment (attachment_id)
        ) $charset_collate;";
        
        // Image History table (centralized tracking)
        $table_history = $wpdb->prefix . 'seoautofix_image_history';
        $sql_history = "CREATE TABLE IF NOT EXISTS $table_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attachment_id BIGINT UNSIGNED NOT NULL UNIQUE,
            image_permalink TEXT NOT NULL,
            image_name VARCHAR(255) NOT NULL,
            alt_history TEXT,
            title_history TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'blank',
            issue_type VARCHAR(50),
            image_url TEXT,
            media_link TEXT,
            scan_timestamp DATETIME,
            last_updated DATETIME,
            INDEX idx_attachment (attachment_id),
            INDEX idx_status (status),
            INDEX idx_scan (scan_timestamp)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_audit);
        dbDelta($sql_rollback);
        dbDelta($sql_history);
        return true;
    }
    
    /**
     * AJAX: Get initial stats from database
     */
    public function ajax_get_stats() {
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        try {
            // Get statistics from history table
            $stats = $this->image_history->get_statistics();
            
            // Check if this is first time (no images scanned yet)
            $has_data = ($stats['total'] > 0);
            
            wp_send_json_success(array(
                'stats' => $stats,
                'has_data' => $has_data
            ));
            
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Delete unused image
     */
    public function ajax_delete_image() {
        error_log('DELETE-BACKEND: ===== ajax_delete_image() CALLED =====');
        error_log('DELETE-BACKEND: POST data: ' . print_r($_POST, true));
        
        check_ajax_referer('imageseo_nonce', 'nonce');
        error_log('DELETE-BACKEND: Nonce verified');
        
        if (!current_user_can('manage_options')) {
            error_log('DELETE-BACKEND: Permission denied');
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        error_log('DELETE-BACKEND: Permissions OK');
        
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        error_log('DELETE-BACKEND: Attachment ID to delete: ' . $attachment_id);
        
        if (!$attachment_id) {
            error_log('DELETE-BACKEND: No attachment ID provided');
            wp_send_json_error(array('message' => 'No attachment ID provided'));
        }
        
        // Check if attachment exists
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            error_log('DELETE-BACKEND: Attachment not found');
            wp_send_json_error(array('message' => 'Attachment not found'));
        }
        error_log('DELETE-BACKEND: Attachment found: ' . $attachment->post_title);
        
        // Delete the attachment
        $deleted = wp_delete_attachment($attachment_id, true);
        error_log('DELETE-BACKEND: wp_delete_attachment returned: ' . print_r($deleted, true));
        
        if ($deleted) {
            error_log('DELETE-BACKEND: SUCCESS - Image deleted from WordPress');
            
            // ALSO DELETE FROM HISTORY TABLE
            error_log('DELETE-BACKEND: Removing from image_history table...');
            global $wpdb;
            $table_name = $wpdb->prefix . 'seoautofix_image_history';
            
            $history_deleted = $wpdb->delete(
                $table_name,
                array('attachment_id' => $attachment_id),
                array('%d')
            );
            
            error_log('DELETE-BACKEND: History table rows deleted: ' . $history_deleted);
            
            if ($history_deleted) {
                error_log('DELETE-BACKEND: ✅ COMPLETE - Image deleted from both WordPress AND history table');
            } else {
                error_log('DELETE-BACKEND: ⚠️  WARNING - Image deleted from WordPress but history table update failed or no record existed');
            }
            
            wp_send_json_success(array('message' => 'Image deleted successfully'));
        } else {
            error_log('DELETE-BACKEND: FAILED - wp_delete_attachment returned false');
            wp_send_json_error(array('message' => 'Failed to delete image'));
        }
    }
    
    /**
     * AJAX: Email CSV to admin
     */
    public function ajax_email_csv() {
        error_log('FEATURE-DEBUG-EMAIL: === ajax_email_csv() START ===');
        check_ajax_referer('imageseo_nonce', 'nonce');
        error_log('FEATURE-DEBUG-EMAIL: Nonce verified');
        
        if (!current_user_can('manage_options')) {
            error_log('FEATURE-DEBUG-EMAIL: Permission denied');
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        error_log('FEATURE-DEBUG-EMAIL: Permissions OK');
        
        try {
            // Get all history records for export
            error_log('FEATURE-DEBUG-EMAIL: Getting history records...');
            $results = $this->image_history->get_all_for_export();
            error_log('FEATURE-DEBUG-EMAIL: Got ' . count($results) . ' records');
            
            if (empty($results)) {
                error_log('FEATURE-DEBUG-EMAIL: No data to email');
                wp_send_json_error(array('message' => 'No data to email'));
            }
            
            error_log('FEATURE-DEBUG-EMAIL: Generating CSV content...');
            // Generate CSV content (same as export)
            $csv_output = '';
            
            // CSV Headers
            $headers = array(
                'Media Link',
                'Previous Alt Text',
                'New Alt Text',
                'Image URL',
                'Previous Title',
                'New Title',
                'Type'
            );
            $csv_output .= '"' . implode('","', $headers) . '"' . "\n";
            
            // CSV Rows
            foreach ($results as $row) {
                $alt_history = json_decode($row->alt_history, true) ?: array();
                $title_history = json_decode($row->title_history, true) ?: array();
                
                $prev_alt = isset($alt_history[0]) ? $alt_history[0] : '';
                $new_alt = count($alt_history) > 1 ? end($alt_history) : $prev_alt;
                $prev_title = isset($title_history[0]) ? $title_history[0] : '';
                $new_title = count($title_history) > 1 ? end($title_history) : $prev_title;
                
                $action_mapping = array(
                    'generate' => 'Generated',
                    'optimized' => 'Optimized',
                    'skipped' => 'Skipped'
                );
                $action_type = isset($action_mapping[$row->status]) ? $action_mapping[$row->status] : $row->status;
                
                $csv_row = array(
                    $row->media_link,
                    $prev_alt,
                    $new_alt,
                    $row->image_url,
                    $prev_title,
                    $new_title,
                    $action_type
                );
                
                $csv_output .= '"' . implode('","', array_map('addslashes', $csv_row)) . '"' . "\n";
            }
            
            error_log('FEATURE-DEBUG-EMAIL: CSV generated, size: ' . strlen($csv_output) . ' bytes');
            
            // Send email with CSV attachment
            $admin_email = get_option('admin_email');
            error_log('FEATURE-DEBUG-EMAIL: Admin email: ' . $admin_email);
            
            $subject = 'Image SEO Audit Export - ' . get_bloginfo('name');
            $message = "Hi,\n\nAttached is the Image SEO audit export for " . get_bloginfo('name') . ".\n\nTotal records: " . count($results);
            
            error_log('FEATURE-DEBUG-EMAIL: Creating temp CSV file...');
            $attachments = array();
            $upload_dir = wp_upload_dir();
            error_log('FEATURE-DEBUG-EMAIL: Upload dir: ' . print_r($upload_dir, true));
            
            $csv_file = $upload_dir['path'] . '/image-seo-audit-' . date('Y-m-d') . '.csv';
            error_log('FEATURE-DEBUG-EMAIL: CSV file path: ' . $csv_file);
            
            $written = file_put_contents($csv_file, $csv_output);
            error_log('FEATURE-DEBUG-EMAIL: File written: ' . ($written ? 'YES (' . $written . ' bytes)' : 'NO'));
            
            if (!$written) {
                error_log('FEATURE-DEBUG-EMAIL: Failed to write CSV file');
                wp_send_json_error(array('message' => 'Failed to create CSV file'));
            }
            
            $attachments[] = $csv_file;
            error_log('FEATURE-DEBUG-EMAIL: Sending email to: ' . $admin_email);
            error_log('FEATURE-DEBUG-EMAIL: Subject: ' . $subject);
            error_log('FEATURE-DEBUG-EMAIL: Attachment: ' . $csv_file);
            
            $sent = wp_mail($admin_email, $subject, $message, '', $attachments);
            error_log('FEATURE-DEBUG-EMAIL: wp_mail returned: ' . ($sent ? 'TRUE' : 'FALSE'));
            
            // Clean up
            $deleted = @unlink($csv_file);
            error_log('FEATURE-DEBUG-EMAIL: Temp file deleted: ' . ($deleted ? 'YES' : 'NO'));
            
            if ($sent) {
                error_log('FEATURE-DEBUG-EMAIL: SUCCESS - Email sent');
                wp_send_json_success(array('message' => 'Email sent successfully to ' . $admin_email));
            } else {
                error_log('FEATURE-DEBUG-EMAIL: FAILED - wp_mail returned false');
                
                // Check if localhost
                $is_localhost = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
                
                if ($is_localhost) {
                    wp_send_json_error(array('message' => 'Email failed: XAMPP/localhost has no mail server. Email will work on production server with proper mail configuration.'));
                } else {
                    wp_send_json_error(array('message' => 'Failed to send email. Check WordPress mail configuration or use SMTP plugin.'));
                }
            }
            
        } catch (\Exception $e) {
            error_log('FEATURE-DEBUG-EMAIL: EXCEPTION: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Get count of unused images
     */
    public function ajax_get_unused_count() {
        error_log('BULK-DELETE-BACKEND: ajax_get_unused_count() called');
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $unused_images = $this->get_unused_images();
        $count = count($unused_images);
        
        error_log('BULK-DELETE-BACKEND: Found ' . $count . ' unused images');
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * AJAX: Create ZIP of unused images
     */
    public function ajax_create_unused_zip() {
        error_log('BULK-DELETE-BACKEND: ajax_create_unused_zip() called');
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $unused_images = $this->get_unused_images();
        
        if (empty($unused_images)) {
            wp_send_json_error(array('message' => 'No unused images found'));
        }
        
        // Create ZIP
        $upload_dir = wp_upload_dir();
        $zip_filename = 'unused-images-' . date('Y-m-d-His') . '.zip';
        $zip_path = $upload_dir['path'] . '/' . $zip_filename;
        
        error_log('BULK-DELETE-BACKEND: Creating ZIP at: ' . $zip_path);
        
        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE) !== TRUE) {
            error_log('BULK-DELETE-BACKEND: Failed to create ZIP file');
            wp_send_json_error(array('message' => 'Failed to create ZIP file'));
        }
        
        $added_count = 0;
        foreach ($unused_images as $img_id) {
            $file_path = get_attached_file($img_id);
            if ($file_path && file_exists($file_path)) {
                $zip->addFile($file_path, basename($file_path));
                $added_count++;
            }
        }
        
        $zip->close();
        
        error_log('BULK-DELETE-BACKEND: ZIP created with ' . $added_count . ' images');
        
        $zip_url = $upload_dir['url'] . '/' . $zip_filename;
        
        wp_send_json_success(array(
            'zip_url' => $zip_url,
            'zip_filename' => $zip_filename,
            'image_count' => $added_count
        ));
    }
    
    /**
     * AJAX: Bulk delete unused images
     */
    public function ajax_bulk_delete_unused() {
        error_log('BULK-DELETE-BACKEND: ajax_bulk_delete_unused() called');
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $unused_images = $this->get_unused_images();
        
        if (empty($unused_images)) {
            error_log('BULK-DELETE-BACKEND: No unused images to delete');
            wp_send_json_success(array('deleted_count' => 0));
            return;
        }
        
        $deleted_count = 0;
        foreach ($unused_images as $img_id) {
            error_log('BULK-DELETE-BACKEND: Deleting image ID: ' . $img_id);
            $result = wp_delete_attachment($img_id, true);
            if ($result) {
                $deleted_count++;
            }
        }
        
        error_log('BULK-DELETE-BACKEND: Successfully deleted ' . $deleted_count . ' images');
        wp_send_json_success(array('deleted_count' => $deleted_count));
    }
    
    /**
     * Get all unused images
     */
    private function get_unused_images() {
        error_log('DELETE-UNUSED-DEBUG: [Backend] ===== get_unused_images() CALLED =====');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'seoautofix_image_history';
        
        // Get all images from history
        $all_images = $wpdb->get_col("SELECT DISTINCT attachment_id FROM $table_name");
        
        error_log('DELETE-UNUSED-DEBUG: [Backend] Found ' . count($all_images) . ' total images in history table');
        
        $unused_images = array();
        
        foreach ($all_images as $img_id) {
            // Check if attachment still exists
            if (!get_post($img_id)) {
                error_log('DELETE-UNUSED-DEBUG: [Backend] Image ' . $img_id . ' - attachment no longer exists, skipping');
                continue;
            }
            
            // Check usage via Image_Usage_Tracker
            $usage = $this->usage_tracker->get_image_usage($img_id);
            error_log('DELETE-UNUSED-DEBUG: [Backend] Image ' . $img_id . ' - raw usage data: ' . print_r($usage, true));
            
            // Count posts vs pages from the 'pages' array (same logic as scan)
            $post_count = 0;
            $page_count = 0;
            
            if (isset($usage['pages']) && is_array($usage['pages'])) {
                foreach ($usage['pages'] as $page_data) {
                    if (isset($page_data['type'])) {
                        if ($page_data['type'] === 'post') {
                            $post_count++;
                        } elseif ($page_data['type'] === 'page') {
                            $page_count++;
                        }
                    }
                }
            }
            
            error_log('DELETE-UNUSED-DEBUG: [Backend] Image ' . $img_id . ' - Posts: ' . $post_count . ', Pages: ' . $page_count);
            
            // Image is unused if it's not in any post or page
            if ($post_count == 0 && $page_count == 0) {
                $unused_images[] = $img_id;
                error_log('DELETE-UNUSED-DEBUG: [Backend] ✓ Image ' . $img_id . ' is UNUSED - will be included in bulk delete');
            } else {
                error_log('DELETE-UNUSED-DEBUG: [Backend] ✗ Image ' . $img_id . ' is USED - excluding from bulk delete');
            }
        }
        
        error_log('DELETE-UNUSED-DEBUG: [Backend] ===== SUMMARY =====');
        error_log('DELETE-UNUSED-DEBUG: [Backend] Total images checked: ' . count($all_images));
        error_log('DELETE-UNUSED-DEBUG: [Backend] Truly unused images: ' . count($unused_images));
        error_log('DELETE-UNUSED-DEBUG: [Backend] Used images excluded: ' . (count($all_images) - count($unused_images)));
        
        return $unused_images;
    }
    
    /**
     * AJAX: Validate user-written alt text with AI
     */
    public function ajax_validate_alt_text() {
        error_log('AI-VALIDATION-DEBUG: [Backend AJAX] ===== ajax_validate_alt_text() CALLED =====');
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            error_log('AI-VALIDATION-DEBUG: [Backend AJAX] Permission denied');
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Check if API key is configured
        if (!\SEOAutoFix_Settings::is_api_configured()) {
            error_log('AI-VALIDATION-DEBUG: [Backend AJAX] No API key configured');
            wp_send_json_error(array('message' => 'OpenAI API key not configured'));
        }
        
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        $user_alt_text = isset($_POST['alt_text']) ? sanitize_text_field($_POST['alt_text']) : '';
        
        error_log('AI-VALIDATION-DEBUG: [Backend AJAX] Attachment ID: ' . $attachment_id);
        error_log('AI-VALIDATION-DEBUG: [Backend AJAX] User alt text: ' . $user_alt_text);
        
        if (!$attachment_id || empty($user_alt_text)) {
            error_log('AI-VALIDATION-DEBUG: [Backend AJAX] Invalid data - ID or alt text empty');
            wp_send_json_error(array('message' => 'Invalid data'));
        }
        
        try {
            error_log('AI-VALIDATION-DEBUG: [Backend AJAX] Calling alt_generator->validate_alt_text_with_image()');
            
            // Call validation method
            $validation_result = $this->alt_generator->validate_alt_text_with_image($attachment_id, $user_alt_text);
            
            error_log('AI-VALIDATION-DEBUG: [Backend AJAX] Validation complete. Result: ' . print_r($validation_result, true));
            
            wp_send_json_success($validation_result);
            
        } catch (\Exception $e) {
            error_log('AI-VALIDATION-DEBUG: [Backend AJAX] EXCEPTION: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Remove All Alt Texts (Cleanup & Delete Tab)
     */
    public function ajax_remove_all_alt() {
        error_log('====== REMOVE-ALL-ALT BACKEND DEBUG START ======');
        
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        try {
            global $wpdb;
            $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';
            
            $attachments = get_posts(array(
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ));
            
            $removed_count = 0;
            
            foreach ($attachments as $attachment_id) {
                $old_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                
                if (!empty($old_alt)) {
                    delete_post_meta($attachment_id, '_wp_attachment_image_alt');
                    
                    // Log in audit
                    $wpdb->insert($table_audit, array(
                        'attachment_id' => $attachment_id,
                        'prev_alt' => $old_alt,
                        'new_alt' => '',
                        'action_type' => 'Alt Text Removed (Bulk)',
                        'status' => 'removed',
                        'created_at' => current_time('mysql')
                    ));
                    
                    $removed_count++;
                }
            }
            
            error_log('REMOVE-ALL-DEBUG: Removed ' . $removed_count . ' alt texts');
            
            wp_send_json_success(array(
                'removed_count' => $removed_count
            ));
            
        } catch (Exception $e) {
            error_log('REMOVE-ALL-DEBUG: ERROR - ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Delete Images by URL
     */
    public function ajax_delete_by_url() {
        error_log('====== DELETE-BY-URL BACKEND DEBUG START ======');
        
        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $urls = isset($_POST['urls']) ? $_POST['urls'] : '';
        $urls_array = array_filter(array_map('trim', explode("\n", $urls)));
        
        if (empty($urls_array) || count($urls_array) > 50) {
            wp_send_json_error(array('message' => 'Invalid URL count'));
        }
        
        try {
            global $wpdb;
            $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';
            $site_url = get_site_url();
            
            $deleted = 0;
            $skipped = 0;
            $errors = array();
            
            foreach ($urls_array as $url) {
                // Validate URL
                if (strpos($url, $site_url) !== 0) {
                    $errors[] = "External: $url";
                    $skipped++;
                    continue;
                }
                
                $attachment_id = attachment_url_to_postid($url);
                
                if (!$attachment_id) {
                    $errors[] = "Not found: $url";
                    $skipped++;
                    continue;
                }
                
                // Log deletion
                $wpdb->insert($table_audit, array(
                    'attachment_id' => $attachment_id,
                    'image_url' => $url,
                    'action_type' => 'Deleted by URL',
                    'status' => 'deleted',
                    'created_at' => current_time('mysql')
                ));
                
                wp_delete_attachment($attachment_id, true);
                $deleted++;
            }
            
            wp_send_json_success(array(
                'deleted' => $deleted,
                'skipped' => $skipped,
                'errors' => $errors
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}
