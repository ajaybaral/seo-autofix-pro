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

            default: // native — update post_title directly
                global $wpdb;
                $old_title = $post->post_title;

                // Strip the site name suffix if the user included it in their new title.
                // When no SEO plugin is active, WordPress builds "<title>Post Title | Site Name</title>"
                // automatically. We only store the "Post Title" part in post_title.
                // If the user applied "New Title | My Site", we save "New Title" so it
                // doesn't become "New Title | My Site | My Site" in the rendered page.
                $new_title = $this->strip_site_name_suffix($new_title);

                // Step 1: Use wp_update_post() to fire the save_post hook and all
                // cache-clearing hooks that themes and caching plugins listen to.
                // Without this, some page-caching layers never learn the title changed.
                // wp_slash() prevents WordPress from double-escaping the value.
                $update_result = wp_update_post(
                    array(
                        'ID'         => $post_id,
                        'post_title' => wp_slash($new_title),
                    ),
                    true // return WP_Error on failure
                );

                if (is_wp_error($update_result)) {
                    \SEOAutoFix_Debug_Logger::log(
                        "[TITLETAG APPLY native] wp_update_post failed: " . $update_result->get_error_message(),
                        'title-tag'
                    );
                    throw new \Exception('Failed to update post title: ' . $update_result->get_error_message());
                }

                // Step 2: wp_update_post() internally runs wptexturize() which can convert
                // plain hyphens (-) into en-dashes (&#8211;). Write the exact title string
                // again via $wpdb so the DB stores precisely what the user typed.
                $wpdb->update(
                    $wpdb->posts,
                    array('post_title' => $new_title),
                    array('ID' => $post_id),
                    array('%s'),
                    array('%d')
                );

                if ($wpdb->last_error) {
                    \SEOAutoFix_Debug_Logger::log(
                        "[TITLETAG APPLY native] wpdb->update error: " . $wpdb->last_error,
                        'title-tag'
                    );
                    throw new \Exception('Database error updating post title: ' . $wpdb->last_error);
                }

                // Clear all WordPress object caches for this post so the next
                // frontend request sees the fresh title.
                clean_post_cache($post_id);
                wp_cache_delete($post_id, 'posts');
                wp_cache_delete($post_id, 'post_meta');
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

    /**
     * Strip the site name (and its separator) from the end of a title string.
     *
     * When no SEO plugin is active WordPress automatically appends the blog name
     * to every page title: "Post Title | Site Name". If the admin copies or
     * generates a title that already includes " | Site Name", we must remove
     * that suffix before storing in post_title — otherwise the theme will render
     * "Post Title | Site Name | Site Name" in the <title> tag.
     *
     * Handles these separator chars: | / - – —  (plus surrounding whitespace).
     */
    private function strip_site_name_suffix(string $title): string
    {
        $site_name = get_bloginfo('name');

        if ('' === $site_name || '' === trim($title)) {
            return $title;
        }

        // Build a pattern that matches any common separator + the site name at the end.
        // Separators: |  /  -  – (en dash)  — (em dash)  · (middle dot)
        $escaped = preg_quote($site_name, '/');
        $pattern = '/[\s]*[|\\/\-\x{2013}\x{2014}\xB7][\s]*' . $escaped . '[\s]*$/iu';

        $stripped = preg_replace($pattern, '', $title);

        // Only use the stripped version if it is non-empty.
        return ('' !== trim((string) $stripped)) ? trim((string) $stripped) : trim($title);
    }
}
