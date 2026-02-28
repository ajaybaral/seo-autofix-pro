<?php
/**
 * Meta Description Optimization — Apply Engine
 *
 * @package SEO_AutoFix_Pro
 * @subpackage MetaDescriptionOptimization
 */

namespace SEOAutoFix\MetaDescriptionOptimization;

if (!defined('ABSPATH')) {
    exit;
}

class Description_Apply_Engine
{

    /**
     * Detect the active SEO plugin (isolated — no cross-module import).
     */
    private function detect_seo_plugin(): string
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION')) {
            return 'rankmath';
        }
        if (defined('AIOSEO_VERSION')) {
            return 'aioseo';
        }
        return 'native';
    }

    /**
     * Apply a new meta description to the correct SEO meta field.
     *
     * Rules:
     *  - Yoast     → _wpseo_metadesc post meta
     *  - Rank Math → rank_math_description post meta
     *  - AIOSEO    → {prefix}aioseo_posts custom table (NOT post meta)
     *  - Native    → _seoautofix_description post meta + post_excerpt
     *  - Do NOT touch post_title when an SEO plugin is active.
     *  - Do NOT modify the slug.
     */
    public function apply(int $post_id, string $new_description): array
    {
        $post = get_post($post_id);
        if (!$post) {
            throw new \Exception("Post {$post_id} not found.");
        }

        $plugin = $this->detect_seo_plugin();
        $meta_key = '';
        $old_description = '';

        switch ($plugin) {
            case 'yoast':
                $meta_key = '_wpseo_metadesc';
                $old_description = (string) get_post_meta($post_id, $meta_key, true);
                update_post_meta($post_id, $meta_key, $new_description);
                break;

            case 'rankmath':
                // Read old_description as the fully-rendered string via scanner.
                $scanner        = new Description_Scanner();
                $old_description = $scanner->get_seo_description($post_id);

                update_post_meta($post_id, 'rank_math_description', $new_description);
                $this->purge_all_caches($post_id);
                break;

            case 'aioseo':
                // Read old_description as the fully-rendered string (same as what the user
                // sees in the SERP preview) by reusing the scanner's get_seo_description().
                $scanner        = new Description_Scanner();
                $old_description = $scanner->get_seo_description($post_id);

                // APPROACH 1: Use AIOSEO's own Post model when available.
                // model->save() runs through AIOSEO's internal persistence layer,
                // which clears AIOSEO's own query/result cache immediately.
                if (class_exists('\AIOSEO\Plugin\Common\Models\Post')) {
                    $aioseo_post              = \AIOSEO\Plugin\Common\Models\Post::getPost($post_id);
                    $aioseo_post->description = $new_description;
                    $aioseo_post->save();
                    $this->purge_all_caches($post_id);
                    break;
                }

                // APPROACH 2: Direct DB write + aggressive cache flushing (fallback).
                // This path runs when AIOSEO's model class is not available (older versions).
                global $wpdb;
                $aioseo_table = $wpdb->prefix . 'aioseo_posts';
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $existing = $wpdb->get_row(
                    $wpdb->prepare("SELECT id FROM `{$aioseo_table}` WHERE post_id = %d", $post_id)
                );

                if ($existing) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->update(
                        $aioseo_table,
                        array('description' => $new_description, 'updated' => current_time('mysql')),
                        array('post_id' => $post_id),
                        array('%s', '%s'),
                        array('%d')
                    );
                } else {
                    $wpdb->insert(
                        $aioseo_table,
                        array('post_id' => $post_id, 'description' => $new_description),
                        array('%d', '%s')
                    );
                }

                // Flush all caches AIOSEO and WordPress may hold for this post.
                $this->purge_all_caches($post_id);
                do_action('aioseo_flush_cache', $post_id);
                break;


            default: // native — store in our own SEO description meta
                // Read old value from our meta (fall back to post_excerpt for initial apply).
                $old_description = (string) get_post_meta($post_id, '_seoautofix_description', true);
                if ('' === $old_description) {
                    $old_description = $post->post_excerpt;
                }

                update_post_meta($post_id, '_seoautofix_description', $new_description);
                $this->purge_all_caches($post_id);
                break;
        }

        // Append to audit log option (last 500 entries).
        $log = get_option('seoautofix_metadesc_audit_log', array());
        $log[] = array(
            'post_id'         => $post_id,
            'post_url'        => get_permalink($post_id),
            'old_description' => $old_description,
            'new_description' => $new_description,
            'plugin'          => $plugin,
            'time'            => current_time('mysql'),
        );
        if (count($log) > 500) {
            $log = array_slice($log, -500);
        }
        update_option('seoautofix_metadesc_audit_log', $log);

        return array(
            'post_id'         => $post_id,
            'old_description' => $old_description,
            'new_description' => $new_description,
            'plugin'          => $plugin,
        );
    }

    /**
     * Purge every cache layer that could serve a stale meta description.
     *
     * Covers:
     *  - WordPress object cache (always)
     *  - Rank Math / AIOSEO plugin-level caches
     *  - LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache,
     *    Nginx Helper, Autoptimize
     *
     * Each call is guarded so it is a no-op when the plugin is not installed.
     */
    private function purge_all_caches(int $post_id): void
    {
        // ── WordPress core ────────────────────────────────────────────────────
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'post_meta');
        wp_cache_delete($post_id, 'posts');

        // ── SEO plugin caches ─────────────────────────────────────────────────
        delete_transient('rank_math_post_' . $post_id);
        delete_transient('aioseo_post_' . $post_id);
        do_action('rank_math/flush_cache', $post_id);
        do_action('aioseo_flush_cache', $post_id);

        // ── LiteSpeed Cache ───────────────────────────────────────────────────
        do_action('litespeed_purge_post', $post_id);

        // ── WP Rocket ─────────────────────────────────────────────────────────
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
        }

        // ── W3 Total Cache ────────────────────────────────────────────────────
        if (function_exists('w3tc_pgcache_flush_post')) {
            w3tc_pgcache_flush_post($post_id);
        }

        // ── WP Super Cache ────────────────────────────────────────────────────
        if (function_exists('wp_cache_post_change')) {
            wp_cache_post_change($post_id);
        }

        // ── Nginx Helper ──────────────────────────────────────────────────────
        do_action('rt_nginx_helper_purge_url', get_permalink($post_id));

        // ── Autoptimize ───────────────────────────────────────────────────────
        do_action('autoptimize_action_cachepurged');
    }
}
