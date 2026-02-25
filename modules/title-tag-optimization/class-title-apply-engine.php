<?php
/**
 * Title Tag Optimization — Apply Engine
 *
 * @package SEO_AutoFix_Pro
 * @subpackage TitleTagOptimization
 */

namespace SEOAutoFix\TitleTagOptimization;

if (!defined('ABSPATH')) {
    exit;
}

class Title_Apply_Engine
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
     * Apply a new title to the correct SEO meta field.
     *
     * Rules:
     *  - Yoast     → _yoast_wpseo_title post meta
     *  - Rank Math → rank_math_title post meta
     *  - AIOSEO    → {prefix}aioseo_posts custom table (NOT post meta)
     *  - Native    → direct $wpdb->update on wp_posts (bypass wptexturize)
     *  - Do NOT touch post_title when an SEO plugin is active.
     *  - Do NOT modify the slug.
     */
    public function apply(int $post_id, string $new_title): array
    {
        $post = get_post($post_id);
        if (!$post) {
            throw new \Exception("Post {$post_id} not found.");
        }

        $plugin = $this->detect_seo_plugin();
        $meta_key = '';
        $old_title = '';

        switch ($plugin) {
            case 'yoast':
                $meta_key = '_yoast_wpseo_title';
                $old_title = (string) get_post_meta($post_id, $meta_key, true);
                update_post_meta($post_id, $meta_key, $new_title);
                break;

            case 'rankmath':
                $meta_key = 'rank_math_title';
                $old_title = (string) get_post_meta($post_id, $meta_key, true);
                update_post_meta($post_id, $meta_key, $new_title);
                break;

            case 'aioseo':
                // AIOSEO does NOT store titles in standard post meta.
                // It uses its own {prefix}aioseo_posts custom table.
                // Writing to '_aioseo_title' post meta is silently ignored by AIOSEO.
                global $wpdb;
                $aioseo_table = $wpdb->prefix . 'aioseo_posts';
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $existing = $wpdb->get_row(
                    $wpdb->prepare("SELECT id, title FROM `{$aioseo_table}` WHERE post_id = %d", $post_id)
                );
                $old_title = $existing
                    ? html_entity_decode((string) $existing->title, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    : '';

                if ($existing) {
                    // Row exists — update just the title column.
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->update(
                        $aioseo_table,
                        array('title' => $new_title),
                        array('post_id' => $post_id),
                        array('%s'),
                        array('%d')
                    );
                } else {
                    // No row yet — insert one with only the fields our plugin manages.
                    $wpdb->insert(
                        $aioseo_table,
                        array(
                            'post_id' => $post_id,
                            'title' => $new_title,
                        ),
                        array('%d', '%s')
                    );
                }
                clean_post_cache($post_id);
                break;

            default: // native — update post_title only, bypass wptexturize()
                global $wpdb;
                $old_title = $post->post_title;
                // Do NOT use wp_update_post() here — it runs wptexturize() internally,
                // which converts plain hyphens ( - ) into &#8211; before saving to the DB.
                // We write directly to the posts table so the title is stored exactly as-is.
                $wpdb->update(
                    $wpdb->posts,
                    array('post_title' => $new_title),
                    array('ID' => $post_id),
                    array('%s'),
                    array('%d')
                );
                clean_post_cache($post_id);
                break;
        }

        \SEOAutoFix_Debug_Logger::log(
            "[TITLETAG APPLY] post_id={$post_id} plugin={$plugin} old=\"{$old_title}\" new=\"{$new_title}\"",
            'title-tag'
        );

        // Append to audit log option (last 500 entries).
        $log = get_option('seoautofix_titletag_audit_log', array());
        $log[] = array(
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id),
            'old_title' => $old_title,
            'new_title' => $new_title,
            'plugin' => $plugin,
            'time' => current_time('mysql'),
        );
        if (count($log) > 500) {
            $log = array_slice($log, -500);
        }
        update_option('seoautofix_titletag_audit_log', $log);

        return array(
            'post_id' => $post_id,
            'old_title' => $old_title,
            'new_title' => $new_title,
            'plugin' => $plugin,
        );
    }
}
