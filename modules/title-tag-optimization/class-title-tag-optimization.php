<?php
/**
 * Title Tag Optimization Module — Main Controller
 *
 * @package SEO_AutoFix_Pro
 * @subpackage TitleTagOptimization
 */

namespace SEOAutoFix\TitleTagOptimization;

if (!defined('ABSPATH')) {
    exit;
}

class SEOAutoFix_Title_Tag_Optimization
{

    const VERSION = '1.0.1';
    const NONCE = 'titletag_nonce';
    const PAGE_SLUG = 'seoautofix-title-tags';
    const HOOK_SUFFIX = 'seo-autofix-pro_page_seoautofix-title-tags';

    private $scanner;
    private $ai_generator;
    private $apply_engine;
    private $bulk_engine;
    private $export_engine;

    public function __construct()
    {
        if (!defined('TITLETAG_MODULE_DIR')) {
            define('TITLETAG_MODULE_DIR', trailingslashit(dirname(__FILE__)));
        }
        if (!defined('TITLETAG_MODULE_URL')) {
            define('TITLETAG_MODULE_URL', plugin_dir_url(__FILE__));
        }

        $this->load_dependencies();
        $this->init_classes();
        add_action('plugins_loaded', array($this, 'register_hooks'));
    }

    private function load_dependencies()
    {
        require_once TITLETAG_MODULE_DIR . 'class-title-scanner.php';
        require_once TITLETAG_MODULE_DIR . 'class-title-ai-generator.php';
        require_once TITLETAG_MODULE_DIR . 'class-title-apply-engine.php';
        require_once TITLETAG_MODULE_DIR . 'class-title-bulk-engine.php';
        require_once TITLETAG_MODULE_DIR . 'class-title-export-engine.php';
    }

    private function init_classes()
    {
        $this->scanner = new Title_Scanner();
        $this->ai_generator = new Title_AI_Generator();
        $this->apply_engine = new Title_Apply_Engine();
        $this->bulk_engine = new Title_Bulk_Engine($this->apply_engine);
        $this->export_engine = new Title_Export_Engine();
    }

    public function register_hooks()
    {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        add_action('wp_ajax_titletag_scan', array($this, 'ajax_scan'));
        add_action('wp_ajax_titletag_generate', array($this, 'ajax_generate'));
        add_action('wp_ajax_titletag_apply', array($this, 'ajax_apply'));
        add_action('wp_ajax_titletag_skip', array($this, 'ajax_skip'));
        add_action('wp_ajax_titletag_bulk_apply', array($this, 'ajax_bulk_apply'));
        add_action('wp_ajax_titletag_export_csv', array($this, 'ajax_export_csv'));

        // Register frontend title filters only when running in native mode
        // (no Yoast, Rank Math, or AIOSEO installed). SEO plugins manage the
        // <title> tag themselves; we must not interfere with their output.
        if ( ! is_admin() && $this->is_native_mode() ) {
            $this->register_frontend_title_filters();
        }
    }

    /**
     * Returns true when no supported SEO plugin is active.
     * Mirrors the detection in Title_Apply_Engine::detect_seo_plugin().
     */
    private function is_native_mode(): bool
    {
        return ! defined( 'WPSEO_VERSION' )
            && ! defined( 'RANK_MATH_VERSION' )
            && ! defined( 'AIOSEO_VERSION' );
    }

    /**
     * Hook into WordPress's title pipeline to return our custom SEO title
     * (_seoautofix_title post meta) exactly as stored — no site name appended.
     *
     * Two hooks are registered:
     *  1. pre_get_document_title — for themes using add_theme_support('title-tag')
     *  2. wp_title              — fallback for older themes using wp_title() directly
     */
    private function register_frontend_title_filters(): void
    {
        // Hook 1: modern themes (WordPress 4.1+)
        add_filter( 'pre_get_document_title', function ( $title ) {
            if ( ! is_singular() ) {
                return $title;
            }
            $post = get_queried_object();
            if ( ! $post || ! isset( $post->ID ) ) {
                return $title;
            }
            $custom = get_post_meta( $post->ID, '_seoautofix_title', true );
            if ( '' !== (string) $custom ) {
                return html_entity_decode( (string) $custom, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }
            // No custom title applied yet — return just the post title, no site name.
            return html_entity_decode( (string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }, 99 );

        // Hook 2: older themes that call wp_title() directly in header.php
        add_filter( 'wp_title', function ( $title, $sep ) {
            if ( ! is_singular() ) {
                return $title;
            }
            $post = get_queried_object();
            if ( ! $post || ! isset( $post->ID ) ) {
                return $title;
            }
            $custom = get_post_meta( $post->ID, '_seoautofix_title', true );
            if ( '' !== (string) $custom ) {
                return html_entity_decode( (string) $custom, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }
            return $title;
        }, 99, 2 );
    }


    public function register_admin_menu()
    {
        add_submenu_page(
            'seoautofix-settings',
            __('Title Tag Optimization', 'seo-autofix-pro'),
            __('Title Tags', 'seo-autofix-pro'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_assets($hook)
    {
        if (self::HOOK_SUFFIX !== $hook) {
            return;
        }

        wp_enqueue_style(
            'titletag-css',
            TITLETAG_MODULE_URL . 'assets/css/title-tag-optimization.css',
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'titletag-js',
            TITLETAG_MODULE_URL . 'assets/js/title-tag-optimization.js',
            array('jquery'),
            self::VERSION,
            true
        );

        wp_localize_script('titletag-js', 'titleTagData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'hasApiKey' => \SEOAutoFix_Settings::is_api_configured(),
            'settingsUrl' => admin_url('admin.php?page=seoautofix-settings'),
            'strings' => array(
                'scanning' => __('Scanning posts…', 'seo-autofix-pro'),
                'generating' => __('Generating…', 'seo-autofix-pro'),
                'applying' => __('Applying…', 'seo-autofix-pro'),
                'success' => __('Applied!', 'seo-autofix-pro'),
                'error' => __('Error. Try again.', 'seo-autofix-pro'),
                'noApiKey' => __('OpenAI API key not configured.', 'seo-autofix-pro'),
                'confirmBulk' => __('Apply all suggested titles now?', 'seo-autofix-pro'),
                'noSuggestions' => __('No suggestions to apply. Run Bulk Generate first.', 'seo-autofix-pro'),
                'cancelled' => __('Generation cancelled.', 'seo-autofix-pro'),
            ),
        ));
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        require_once TITLETAG_MODULE_DIR . 'views/admin-page.php';
    }

    /* =========================================================
     * AJAX: Scan
     * ========================================================= */
    public function ajax_scan()
    {
        \SEOAutoFix_Debug_Logger::log('===== TITLETAG SCAN START =====', 'title-tag');
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'any';
        $issue_filter = isset($_POST['issue_filter']) ? sanitize_text_field($_POST['issue_filter']) : 'all';
        $batch_size = 100;

        try {
            $results = $this->scanner->scan_batch($batch_size, $offset, $post_type, $issue_filter);
            $has_more = count($results) === $batch_size;
            $response = array(
                'results' => $results,
                'offset' => $offset + $batch_size,
                'hasMore' => $has_more,
            );
            if ($offset === 0) {
                $response['stats'] = $this->scanner->get_stats($post_type, $issue_filter);
            }
            \SEOAutoFix_Debug_Logger::log('[TITLETAG SCAN] Returned ' . count($results) . ' items.', 'title-tag');
            wp_send_json_success($response);
        } catch (\Exception $e) {
            \SEOAutoFix_Debug_Logger::log('❌ [TITLETAG SCAN] ' . $e->getMessage(), 'title-tag');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /* =========================================================
     * AJAX: Generate AI suggestion
     * ========================================================= */
    public function ajax_generate()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }
        if (!\SEOAutoFix_Settings::is_api_configured()) {
            wp_send_json_error(array('message' => 'OpenAI API key not configured.'));
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error(array('message' => 'Invalid post ID.'));
        }

        \SEOAutoFix_Debug_Logger::log("[TITLETAG GENERATE] post_id={$post_id}", 'title-tag');

        try {
            $result = $this->ai_generator->generate($post_id);
            wp_send_json_success(array(
                'post_id' => $post_id,
                'title' => $result['title'],
                'keyword' => $result['keyword'],
                'char_count' => mb_strlen($result['title']),
            ));
        } catch (\Exception $e) {
            \SEOAutoFix_Debug_Logger::log('❌ [TITLETAG GENERATE] ' . $e->getMessage(), 'title-tag');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /* =========================================================
     * AJAX: Apply single
     * ========================================================= */
    public function ajax_apply()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $new_title = isset($_POST['new_title']) ? sanitize_text_field($_POST['new_title']) : '';

        if (!$post_id || empty($new_title)) {
            wp_send_json_error(array('message' => 'Invalid data.'));
        }

        \SEOAutoFix_Debug_Logger::log("[TITLETAG APPLY] post_id={$post_id}", 'title-tag');

        try {
            $result = $this->apply_engine->apply($post_id, $new_title);
            wp_send_json_success(array('post_id' => $post_id, 'message' => 'Title updated.', 'detail' => $result));
        } catch (\Exception $e) {
            \SEOAutoFix_Debug_Logger::log('❌ [TITLETAG APPLY] ' . $e->getMessage(), 'title-tag');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /* =========================================================
     * AJAX: Skip (session-only, no DB)
     * ========================================================= */
    public function ajax_skip()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(array('message' => 'Invalid post ID.'));
        }

        \SEOAutoFix_Debug_Logger::log("[TITLETAG SKIP] post_id={$post_id}", 'title-tag');
        wp_send_json_success(array('post_id' => $post_id));
    }

    /* =========================================================
     * AJAX: Bulk apply
     * ========================================================= */
    public function ajax_bulk_apply()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $raw = isset($_POST['changes']) ? stripslashes($_POST['changes']) : '[]';
        $changes = json_decode($raw, true);

        if (empty($changes) || !is_array($changes)) {
            wp_send_json_error(array('message' => 'No changes provided.'));
        }

        \SEOAutoFix_Debug_Logger::log('[TITLETAG BULK APPLY] count=' . count($changes), 'title-tag');

        try {
            $summary = $this->bulk_engine->apply_bulk($changes);
            wp_send_json_success($summary);
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /* =========================================================
     * AJAX: Export CSV
     * ========================================================= */
    public function ajax_export_csv()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $raw_changes = isset($_POST['changes']) ? stripslashes($_POST['changes']) : '[]';
        $changes = json_decode($raw_changes, true);

        if (empty($changes) || !is_array($changes)) {
            wp_send_json_error(array('message' => 'No applied changes to export.'));
        }

        \SEOAutoFix_Debug_Logger::log('[TITLETAG EXPORT CSV] rows=' . count($changes), 'title-tag');

        try {
            $url = $this->export_engine->export_csv($changes);
            wp_send_json_success(array('download_url' => $url));
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}
