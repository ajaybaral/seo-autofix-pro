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

            default: // native — store in our own SEO title meta (mirrors Yoast's approach)
                // Read old value from our meta (fall back to post_title for initial apply).
                $old_title = (string) get_post_meta($post_id, '_seoautofix_title', true);
                if ('' === $old_title) {
                    $old_title = $post->post_title;
                }

                \SEOAutoFix_Debug_Logger::log(
                    "[TITLETAG APPLY native] post_id={$post_id} received_title=\"{$new_title}\" old_title=\"{$old_title}\"",
                    'title-tag'
                );

                // Strip site name suffix if present (e.g. "Title - SiteName" → "Title").
                $before_strip = $new_title;
                $new_title = $this->strip_site_name_suffix($new_title);
                \SEOAutoFix_Debug_Logger::log(
                    "[TITLETAG APPLY native] strip_site_name: before=\"{$before_strip}\" after=\"{$new_title}\"",
                    'title-tag'
                );

                // Write the clean title to our dedicated meta key.
                $meta_result = update_post_meta($post_id, '_seoautofix_title', $new_title);
                \SEOAutoFix_Debug_Logger::log(
                    "[TITLETAG APPLY native] update_post_meta result=" . var_export($meta_result, true),
                    'title-tag'
                );

                // Verify the meta was saved correctly by reading it back.
                $verify = get_post_meta($post_id, '_seoautofix_title', true);
                \SEOAutoFix_Debug_Logger::log(
                    "[TITLETAG APPLY native] verify read-back: _seoautofix_title=\"{$verify}\"",
                    'title-tag'
                );

                // Flush caches so the next frontend request hits the DB.
                clean_post_cache($post_id);
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
        $site_name = trim(get_bloginfo('name'));

        if ('' === $site_name || '' === trim($title)) {
            return $title;
        }

        // All separator characters WordPress (and common SEO plugins) place between
        // the post title and the site name. Include the entity-decoded en dash (–)
        // and em dash (—) which wptexturize() may have written.
        $separators = array( '|', '/', '-', '–', '—', '·' );

        $lower_title     = mb_strtolower($title,     'UTF-8');
        $lower_site_name = mb_strtolower($site_name, 'UTF-8');

        foreach ($separators as $sep) {
            // Build the suffix we are looking for, e.g. " – Natascha Jacobs"
            $suffix = $sep . $lower_site_name;            // no spaces
            $suffix_spaced = $sep . ' ' . $lower_site_name; // with one space

            foreach (array($suffix_spaced, $suffix) as $candidate) {
                $candidate = mb_strtolower(trim($candidate), 'UTF-8');
                $len = mb_strlen($candidate, 'UTF-8');

                if (mb_substr($lower_title, -$len, null, 'UTF-8') === $candidate) {
                    $stripped = trim(mb_substr($title, 0, mb_strlen($title, 'UTF-8') - $len, 'UTF-8'));
                    if ('' !== $stripped) {
                        return $stripped;
                    }
                }
            }
        }

        return $title; // nothing matched — return as-is
    }
}
