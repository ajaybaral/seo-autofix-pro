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
class SEOAutoFix_Image_SEO
{

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
    // private $seo_scorer; // Removed - SEO scoring disabled

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
    public function __construct()
    {
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

        // Register hooks after WordPress loads (fixes menu registration timing)
        add_action('plugins_loaded', array($this, 'register_hooks'));

        // Ensure tables exist (create if missing)
        $this->ensure_tables_exist();

        // Module activation
        register_activation_hook(SEOAUTOFIX_PLUGIN_FILE, array($this, 'activate'));
    }

    /**
     * Load module dependencies
     */
    private function load_dependencies()
    {
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
    private function init_classes()
    {
        $this->api_manager = new API_Manager();
        $this->logger = new Logger();
        $this->rollback = new Rollback();
        $this->analyzer = new Image_Analyzer();
        $this->alt_generator = new Alt_Generator($this->api_manager);
        // $this->seo_scorer = new SEO_Scorer($this->api_manager); // Removed - SEO scoring disabled
        $this->usage_tracker = new Image_Usage_Tracker();
        $this->image_history = new Image_History();
    }

    /**
     * Register WordPress hooks
     */
    public function register_hooks()
    {
        // Admin menu
        add_action('admin_menu', array($this, 'register_admin_menu'));

        // Admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX actions
        add_action('wp_ajax_imageseo_scan', array($this, 'ajax_scan_images'));
        add_action('wp_ajax_imageseo_generate', array($this, 'ajax_generate_alt'));
        // add_action('wp_ajax_imageseo_score', array($this, 'ajax_score_alt')); // Removed - SEO scoring disabled
        add_action('wp_ajax_imageseo_apply', array($this, 'ajax_apply_change'));
        add_action('wp_ajax_imageseo_skip', array($this, 'ajax_skip_image'));
        add_action('wp_ajax_imageseo_bulk_apply', array($this, 'ajax_bulk_apply'));
        add_action('wp_ajax_imageseo_export_audit', array($this, 'ajax_export_audit_csv'));
        add_action('wp_ajax_imageseo_export_filter_csv', array($this, 'ajax_export_filter_csv'));  // NEW: Filter-scoped CSV
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
    public function register_admin_menu()
    {
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
    public function enqueue_assets($hook)
    {
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
    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Load admin page template (no longer blocking access if no API key)
        require_once IMAGESEO_MODULE_DIR . 'views/admin-page.php';
    }

    /**
     * Render API key notice
     */
    private function render_api_key_notice()
    {
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

    public function ajax_scan_images()
    {




        check_ajax_referer('imageseo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {

            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 50;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        // Always show ALL images - no filtering by status
        $status_filter = 'all';

        // 🔍 DEBUG: Log scan parameters
        error_log('🔍 [IMAGE-SEO-SCAN] ===== SCAN BATCH START =====');
        error_log('🔍 [IMAGE-SEO-SCAN] Batch size: ' . $batch_size);
        error_log('🔍 [IMAGE-SEO-SCAN] Offset: ' . $offset);
        error_log('🔍 [IMAGE-SEO-SCAN] Status filter: ' . $status_filter);
        error_log('🔍 [IMAGE-SEO-SCAN] Is first batch: ' . ($offset === 0 ? 'YES' : 'NO'));








        try {
            $results = $this->analyzer->scan_all_images($batch_size, $offset, $this->usage_tracker, $status_filter);

            // 🔍 DEBUG: Log scan results
            error_log('🔍 [IMAGE-SEO-SCAN] Results returned: ' . count($results));
            if (!empty($results)) {
                error_log('🔍 [IMAGE-SEO-SCAN] First image ID: ' . $results[0]['id']);
                error_log('🔍 [IMAGE-SEO-SCAN] First image title: ' . $results[0]['title']);
            } else {
                error_log('🔍 [IMAGE-SEO-SCAN] ⚠️ NO RESULTS RETURNED!');
            }

            // SEO scoring removed for performance - no longer calculating scores during scan



            if (!empty($results)) {




                // Debug first 5 images' usage data

                for ($i = 0; $i < min(5, count($results)); $i++) {
                    $img = $results[$i];




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

                // 🎯 NEW: Get total image count for accurate progress calculation
                global $wpdb;
                $total_images = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'");
                $response_data['total_images'] = (int) $total_images;

                // 🔍 DEBUG: Log first batch response
                error_log('🔍 [IMAGE-SEO-SCAN] First batch - Stats: ' . json_encode($response_data['stats']));
                error_log('🔍 [IMAGE-SEO-SCAN] First batch - Total images in DB: ' . $total_images);
            }

            // 🔍 DEBUG: Log final response
            error_log('🔍 [IMAGE-SEO-SCAN] Response - Results count: ' . count($response_data['results']));
            error_log('🔍 [IMAGE-SEO-SCAN] Response - Has more: ' . ($response_data['hasMore'] ? 'true' : 'false'));
            error_log('🔍 [IMAGE-SEO-SCAN] Response - Next offset: ' . $response_data['offset']);
            error_log('🔍 [IMAGE-SEO-SCAN] ===== SCAN BATCH END =====');

            wp_send_json_success($response_data);

        } catch (\Exception $e) {
            $this->logger->log_error('Scan failed', $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX: Generate AI alt text
     */
    public function ajax_generate_alt()
    {



        check_ajax_referer('imageseo_nonce', 'nonce');


        if (!current_user_can('manage_options')) {

            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        // Check if API key is configured
        if (!\SEOAutoFix_Settings::is_api_configured()) {

            wp_send_json_error(array('message' => 'OpenAI API key not configured. Please add your API key in settings.'));
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;


        if (!$attachment_id) {

            wp_send_json_error(array('message' => 'Invalid attachment ID'));
        }

        $force_refresh = isset($_POST['force']) && $_POST['force'] === 'true';

        try {
            global $wpdb;
            $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';

            // Get current alt text to compare with cache
            $current_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

            $cached = null;
            if (!$force_refresh) {

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
            } else {

            }

            // If cache exists AND it's different from current alt, use it
            // If cache == current alt, it's useless, so generate new
            if ($cached && !empty($cached->suggested_alt) && $cached->suggested_alt !== $current_alt) {

                wp_send_json_success(array(
                    'alt_text' => $cached->suggested_alt,
                    'attachment_id' => $attachment_id,
                    'cached' => true
                ));
                return; // Stop here
            } elseif ($cached && $cached->suggested_alt === $current_alt) {

            }





            // Generate new suggestion via AI
            $context = $this->usage_tracker->get_image_usage($attachment_id);
            $alt_text = $this->alt_generator->generate_alt_text($attachment_id, $context);

            // FALLBACK: If AI returns an error message or can't analyze the image
            $error_patterns = ['sorry', 'cannot', 'can\'t', 'unable', 'please provide', 'provide a detailed', 'i cannot'];
            $is_error_response = false;

            foreach ($error_patterns as $pattern) {
                if (stripos($alt_text, $pattern) !== false) {
                    $is_error_response = true;
                    break;
                }
            }

            // If AI failed, generate alt text from available metadata instead of 'Blank Image'
            if ($is_error_response || empty(trim($alt_text))) {
                $post = get_post($attachment_id);
                $title = $post->post_title;
                $description = $post->post_content;
                $caption = $post->post_excerpt;
                $filename = basename(get_attached_file($attachment_id));

                // Remove file extension and clean filename
                $filename_clean = preg_replace('/\.(jpg|jpeg|png|gif|webp|svg)$/i', '', $filename);
                $filename_clean = str_replace(['-', '_'], ' ', $filename_clean);

                // Priority 1: Use title if meaningful (not auto-generated)
                if (!empty($title) && strlen($title) > 3 && !preg_match('/^(image|img|photo|picture)[\s\-_0-9]*$/i', $title)) {
                    $alt_text = ucfirst(trim($title));
                }
                // Priority 2: Use description if available
                elseif (!empty($description) && strlen($description) > 3) {
                    $alt_text = ucfirst(trim(wp_strip_all_tags($description)));
                    // Limit to first sentence or 100 chars
                    $first_sentence = preg_split('/[.!?]\s/', $alt_text, 2);
                    $alt_text = isset($first_sentence[0]) ? trim($first_sentence[0]) : $alt_text;
                    if (strlen($alt_text) > 100) {
                        $alt_text = substr($alt_text, 0, 97) . '...';
                    }
                }
                // Priority 3: Use caption if available
                elseif (!empty($caption) && strlen($caption) > 3) {
                    $alt_text = ucfirst(trim($caption));
                }
                // Priority 4: Use cleaned filename
                elseif (!empty($filename_clean) && strlen($filename_clean) > 3) {
                    $alt_text = ucfirst(trim($filename_clean));
                }
                // Last Resort: Only now use 'Blank Image' when truly no metadata exists
                else {
                    $alt_text = 'Blank Image';
                }
            }

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



            wp_send_json_success(array(
                'alt_text' => $alt_text,
                'attachment_id' => $attachment_id,
                'cached' => false
            ));

        } catch (\Exception $e) {

            $this->logger->log_error('Generation failed', $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    // ajax_score_alt() removed - SEO scoring feature removed

    /**
     * AJAX: Apply changes
     */
    public function ajax_apply_change()
    {




        check_ajax_referer('imageseo_nonce', 'nonce');


        if (!current_user_can('manage_options')) {

            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }


        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        $new_alt = isset($_POST['alt_text']) ? sanitize_text_field($_POST['alt_text']) : '';




        if (!$attachment_id || empty($new_alt)) {
            wp_send_json_error(array('message' => 'Invalid data'));
        }

        try {
            global $wpdb;
            $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';
            $table_history = $wpdb->prefix . 'seoautofix_image_history';

            // CRITICAL DEBUG: Log the apply attempt









            // Get old values
            $old_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $old_title = get_the_title($attachment_id);
            $image_url = wp_get_attachment_url($attachment_id);
            $media_link = admin_url('post.php?post=' . $attachment_id . '&action=edit');




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




            // Try to update existing PENDING record (from AI Generation)
            $update_result = $wpdb->query($wpdb->prepare(
                "UPDATE $table_audit 
                SET original_alt = %s,
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
                $old_alt,   // Use original_alt column
                $new_alt,
                $old_title,
                $new_alt,
                $image_url,
                $media_link,
                $action_type,
                current_time('mysql'),
                $attachment_id
            ));

            // If NO pending record found (Manual Edit), INSERT new record
            if ($update_result === 0) {

                $wpdb->insert(
                    $table_audit,
                    array(
                        'attachment_id' => $attachment_id,
                        'issue_type' => 'manual', // unknown issue type context
                        'original_alt' => $old_alt,
                        'suggested_alt' => '', // Manual, no suggestion
                        'new_alt' => $new_alt,
                        'prev_title' => $old_title,
                        'new_title' => $new_alt,
                        'image_url' => $image_url,
                        'media_link' => $media_link,
                        'action_type' => 'Manual',
                        'status' => 'applied',
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ),
                    array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
                );
            }



            // SEO scoring removed for performance
            // Images are marked as optimized when user manually applies alt text
            $status = 'optimized';

            $this->image_history->update_image_history($attachment_id, array(
                'new_alt' => $new_alt,
                'new_title' => $new_alt,
                'status' => $status
            ));




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
    public function ajax_skip_image()
    {
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
            $image_url,
            $media_link,
            current_time('mysql'),
            $attachment_id
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
    public function ajax_bulk_apply()
    {
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
    private function populate_all_images_in_history()
    {


        // Check if table exists
        global $wpdb;
        $table_name = $wpdb->prefix . 'seoautofix_image_history';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");


        if (!$table_exists) {

            return;
        }

        // Check existing row count BEFORE population
        $existing_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");


        // Check for records with status other than 'blank' or 'optimal'
        $action_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status IN ('generate', 'optimized', 'skipped')");


        // Get ALL images from media library (INCLUDING all statuses: inherit, private, trash, etc.)
        $all_images = get_posts(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'any',  // Changed from 'inherit' to 'any' to catch ALL images
            'posts_per_page' => -1
        ));



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

                continue; // Skip this image to preserve its history
            }

            // Detect issues
            $issues = $this->analyzer->detect_issues($attachment_id);
            $issue_type = $this->analyzer->classify_issue($attachment_id, $current_alt);

            // CLASSIFICATION-DEBUG: Log issue detection


            // Determine status
            if (empty($issues)) {
                $status = 'optimal'; // Already has good alt text
                $optimal_count++;

            } else {
                $status = 'blank'; // Has issues, awaiting action
                $blank_count++;


                // Debug first few blank images
                if ($blank_count <= 5) {

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

            }
        }





        // Check final row count AFTER population
        $final_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");


        // Check how many scan_all_images would return
        $scan_results = $this->analyzer->scan_all_images(999, 0);

    }

    /**
     * AJAX: Export audit history to CSV
     */
    public function ajax_export_audit_csv()
    {

        check_ajax_referer('imageseo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        global $wpdb;
        $table_audit = $wpdb->prefix . 'seoautofix_imageseo_audit';

        // QUERY: Get only COMPLETED actions (applied, optimized, skipped)
        // Order by most recent update first
        $results = $wpdb->get_results("
            SELECT * FROM $table_audit 
            WHERE status IN ('applied', 'optimized', 'skipped')
            ORDER BY updated_at DESC
        ");



        if (empty($results)) {
            wp_send_json_error(array('message' => 'No changes found in audit log'));
        }

        // GENREATE CSV
        $csv_output = '';

        // Headers
        $headers = array(
            'Date',
            'Image Link',
            'Action Type',
            'Previous Alt Text',
            'New Alt Text',
            'Previous Title',
            'New Title',
            'Media Link'
        );
        $csv_output .= '"' . implode('","', $headers) . '"' . "\n";

        // Rows
        foreach ($results as $row) {
            // Format updated_at to site's timezone (using wp_date)
            // strtotime converts DB UTC string to timestamp, wp_date formats it using timezone settings
            // If updated_at is missing, fallback to current time
            $date_str = $row->updated_at ? wp_date('Y-m-d H:i:s', strtotime($row->updated_at)) : '';

            $csv_row = array(
                $date_str,
                $row->image_url,
                ucfirst($row->action_type), // e.g. "Generated", "Manual", "Skip"
                $row->original_alt,        // PREVIOUS value (before this action)
                $row->new_alt,             // NEW value (after this action)
                $row->prev_title,
                $row->new_title,
                $row->media_link
            );

            // Escape CSV fields
            $csv_output .= '"' . implode('","', array_map(function ($field) {
                return str_replace('"', '""', $field ?: ''); // Handle nulls
            }, $csv_row)) . '"' . "\n";
        }

        wp_send_json_success(array(
            'csv' => $csv_output,
            'filename' => 'seo-audit-log-' . date('Y-m-d-His') . '.csv',
            'count' => count($results)
        ));
    }
    /**
     * Module activation
     */
    public function activate()
    {
        global $wpdb;

        // Drop old tables if they exist (prevents errors from old plugin versions)
        $tables_to_drop = array(
            $wpdb->prefix . 'seoautofix_imageseo_audit',
            $wpdb->prefix . 'seoautofix_imageseo_rollback',
            $wpdb->prefix . 'seoautofix_image_history'
        );

        foreach ($tables_to_drop as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }

        // Create fresh database tables
        $this->create_tables();
    }


    /**
     * Ensure database tables exist (auto-create if missing)
     */
    private function ensure_tables_exist()
    {
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
    private function create_tables()
    {
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
    public function ajax_get_stats()
    {
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
    public function ajax_delete_image()
    {



        check_ajax_referer('imageseo_nonce', 'nonce');


        if (!current_user_can('manage_options')) {

            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }


        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;


        if (!$attachment_id) {

            wp_send_json_error(array('message' => 'No attachment ID provided'));
        }

        // Check if attachment exists
        $attachment = get_post($attachment_id);
        if (!$attachment) {

            wp_send_json_error(array('message' => 'Attachment not found'));
        }


        // Delete the attachment
        $deleted = wp_delete_attachment($attachment_id, true);


        if ($deleted) {


            // ALSO DELETE FROM HISTORY TABLE

            global $wpdb;
            $table_name = $wpdb->prefix . 'seoautofix_image_history';

            $history_deleted = $wpdb->delete(
                $table_name,
                array('attachment_id' => $attachment_id),
                array('%d')
            );



            if ($history_deleted) {

            } else {

            }

            wp_send_json_success(array('message' => 'Image deleted successfully'));
        } else {

            wp_send_json_error(array('message' => 'Failed to delete image'));
        }
    }

    /**
     * AJAX: Email CSV to admin
     */
    public function ajax_email_csv()
    {

        check_ajax_referer('imageseo_nonce', 'nonce');


        if (!current_user_can('manage_options')) {

            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }


        try {
            // Get all history records for export

            $results = $this->image_history->get_all_for_export();


            if (empty($results)) {

                wp_send_json_error(array('message' => 'No data to email'));
            }


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



            // Send email with CSV attachment
            $admin_email = get_option('admin_email');


            $subject = 'Image SEO Audit Export - ' . get_bloginfo('name');
            $message = "Hi,\n\nAttached is the Image SEO audit export for " . get_bloginfo('name') . ".\n\nTotal records: " . count($results);


            $attachments = array();
            $upload_dir = wp_upload_dir();


            $csv_file = $upload_dir['path'] . '/image-seo-audit-' . date('Y-m-d') . '.csv';


            $written = file_put_contents($csv_file, $csv_output);


            if (!$written) {

                wp_send_json_error(array('message' => 'Failed to create CSV file'));
            }

            $attachments[] = $csv_file;




            $sent = wp_mail($admin_email, $subject, $message, '', $attachments);


            // Clean up
            $deleted = @unlink($csv_file);


            if ($sent) {

                wp_send_json_success(array('message' => 'Email sent successfully to ' . $admin_email));
            } else {


                // Check if localhost
                $is_localhost = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);

                if ($is_localhost) {
                    wp_send_json_error(array('message' => 'Email failed: XAMPP/localhost has no mail server. Email will work on production server with proper mail configuration.'));
                } else {
                    wp_send_json_error(array('message' => 'Failed to send email. Check WordPress mail configuration or use SMTP plugin.'));
                }
            }

        } catch (\Exception $e) {

            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX: Export filter-scoped CSV
     * Only exports changes made in the current filter session
     */
    public function ajax_export_filter_csv()
    {
        check_ajax_referer('imageseo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        // Get changes from frontend
        $changes_json = isset($_POST['changes']) ? stripslashes($_POST['changes']) : '';
        $filter_name = isset($_POST['filter']) ? sanitize_text_field($_POST['filter']) : 'unknown';

        if (empty($changes_json)) {
            wp_send_json_error(array('message' => 'No changes to export'));
        }

        $changes = json_decode($changes_json, true);

        if (!is_array($changes) || empty($changes)) {
            wp_send_json_error(array('message' => 'Invalid changes data'));
        }

        // Generate CSV content
        $csv_output = '';

        // CSV Headers (same format as main export)
        $headers = array(
            'Media Link',
            'Previous Alt Text',
            'New Alt Text',
            'Image URL',
            'Filter'
        );
        $csv_output .= '"' . implode('","', $headers) . '"' . "\n";

        // CSV Rows
        foreach ($changes as $change) {
            $attachment_id = intval($change['attachment_id']);
            $previous_alt = isset($change['previous_alt']) ? $change['previous_alt'] : '';
            $new_alt = isset($change['new_alt']) ? $change['new_alt'] : '';
            $filename = isset($change['filename']) ? $change['filename'] : '';

            // Get media edit link
            $media_link = admin_url('post.php?post=' . $attachment_id . '&action=edit');

            // Get image URL
            $image_url = wp_get_attachment_url($attachment_id);

            // Escape values for CSV
            $row = array(
                $media_link,
                str_replace('"', '""', $previous_alt),
                str_replace('"', '""', $new_alt),
                $image_url,
                $filter_name
            );

            $csv_output .= '"' . implode('","', $row) . '"' . "\n";
        }

        // Generate filename
        $timestamp = current_time('Y-m-d-His');
        $filter_slug = sanitize_title($filter_name);
        $filename = "image-seo-filter-{$filter_slug}-{$timestamp}.csv";

        wp_send_json_success(array(
            'csv' => $csv_output,
            'filename' => $filename,
            'count' => count($changes)
        ));
    }

    /**
     * AJAX: Get count of unused images
     */
    public function ajax_get_unused_count()
    {

        check_ajax_referer('imageseo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $unused_images = $this->get_unused_images();
        $count = count($unused_images);


        wp_send_json_success(array('count' => $count));
    }

    /**
     * AJAX: Create ZIP of unused images
     */
    public function ajax_create_unused_zip()
    {

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



        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE) !== TRUE) {

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
    public function ajax_bulk_delete_unused()
    {

        check_ajax_referer('imageseo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $unused_images = $this->get_unused_images();

        if (empty($unused_images)) {

            wp_send_json_success(array('deleted_count' => 0));
            return;
        }

        $deleted_count = 0;
        foreach ($unused_images as $img_id) {

            $result = wp_delete_attachment($img_id, true);
            if ($result) {
                $deleted_count++;
            }
        }


        wp_send_json_success(array('deleted_count' => $deleted_count));
    }

    /**
     * Get all unused images
     */
    private function get_unused_images()
    {


        global $wpdb;
        $table_name = $wpdb->prefix . 'seoautofix_image_history';

        // Get all images from history
        $all_images = $wpdb->get_col("SELECT DISTINCT attachment_id FROM $table_name");



        $unused_images = array();

        foreach ($all_images as $img_id) {
            // Check if attachment still exists
            if (!get_post($img_id)) {

                continue;
            }

            // Check usage via Image_Usage_Tracker
            $usage = $this->usage_tracker->get_image_usage($img_id);


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



            // Image is unused if it's not in any post or page
            if ($post_count == 0 && $page_count == 0) {
                $unused_images[] = $img_id;

            } else {

            }
        }






        return $unused_images;
    }

    /**
     * AJAX: Validate user-written alt text with AI
     */
    public function ajax_validate_alt_text()
    {

        check_ajax_referer('imageseo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {

            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        // Check if API key is configured
        if (!\SEOAutoFix_Settings::is_api_configured()) {

            wp_send_json_error(array('message' => 'OpenAI API key not configured'));
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        $user_alt_text = isset($_POST['alt_text']) ? sanitize_text_field($_POST['alt_text']) : '';




        if (!$attachment_id || empty($user_alt_text)) {

            wp_send_json_error(array('message' => 'Invalid data'));
        }

        try {


            // Call validation method
            $validation_result = $this->alt_generator->validate_alt_text_with_image($attachment_id, $user_alt_text);



            wp_send_json_success($validation_result);

        } catch (\Exception $e) {

            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX: Remove All Alt Texts (Cleanup & Delete Tab)
     */
    public function ajax_remove_all_alt()
    {


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
                'post_status' => 'any',  // Changed from 'inherit' to 'any' to match Media library
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



            wp_send_json_success(array(
                'removed_count' => $removed_count
            ));

        } catch (Exception $e) {

            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX: Delete Images by URL
     */
    public function ajax_delete_by_url()
    {


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