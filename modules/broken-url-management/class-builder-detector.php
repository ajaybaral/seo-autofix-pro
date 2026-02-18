<?php
/**
 * Builder Detector — Identifies which page builder created a post's content.
 *
 * @package SEOAutoFix\BrokenUrlManagement
 * @since   2.0.0
 */

namespace SEOAutoFix\BrokenUrlManagement;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builder Detector Class
 *
 * Detects which page builder (if any) was used to create a WordPress post.
 * Returns a canonical builder identifier used to route replacement/deletion
 * to the correct builder-specific handler.
 */
class Builder_Detector
{
    /** Builder type constants */
    const ELEMENTOR  = 'elementor';
    const WPBAKERY   = 'wpbakery';
    const DIVI       = 'divi';
    const GUTENBERG  = 'gutenberg';
    const CLASSIC    = 'classic';
    const UNKNOWN    = 'unknown';

    /**
     * Detect the page builder used for a given post.
     *
     * Detection order matters — Elementor / WPBakery / Divi store metadata or
     * specific shortcodes, so they are checked first. Gutenberg vs Classic is
     * determined by whether the post_content contains block delimiters.
     *
     * @param int $post_id WordPress post ID.
     * @return string One of the class constants above.
     */
    public static function detect($post_id)
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER_DETECT] Detecting builder for post ID: ' . $post_id);

        $post = get_post($post_id);
        if (!$post) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER_DETECT] Post not found — returning unknown');
            return self::UNKNOWN;
        }

        // ── Elementor ────────────────────────────────────────────────────────
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($elementor_data)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER_DETECT] Detected: elementor (_elementor_data exists)');
            return self::ELEMENTOR;
        }

        // ── WPBakery (Visual Composer) ───────────────────────────────────────
        $wpb_status = get_post_meta($post_id, '_wpb_vc_js_status', true);
        if (!empty($wpb_status)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER_DETECT] Detected: wpbakery (_wpb_vc_js_status exists)');
            return self::WPBAKERY;
        }

        // ── Divi ─────────────────────────────────────────────────────────────
        if (strpos($post->post_content, '[et_pb_section') !== false) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER_DETECT] Detected: divi (et_pb_section shortcode found)');
            return self::DIVI;
        }

        // ── Gutenberg ────────────────────────────────────────────────────────
        if (function_exists('has_blocks') && has_blocks($post->post_content)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER_DETECT] Detected: gutenberg (has_blocks() returned true)');
            return self::GUTENBERG;
        }

        // ── Classic editor (plain HTML) ──────────────────────────────────────
        \SEOAutoFix_Debug_Logger::log('[BUILDER_DETECT] Detected: classic (no builder markers found)');
        return self::CLASSIC;
    }
}
