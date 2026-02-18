<?php
/**
 * Builder-Aware Replacement Engine
 *
 * Routes URL replacement and link removal through builder-specific handlers.
 * Falls back to the Universal Replacement Engine when builder-specific logic
 * returns zero replacements.
 *
 * Supports: Elementor, WPBakery, Divi, Gutenberg, Classic
 *
 * @package SEOAutoFix\BrokenUrlManagement
 * @since   2.0.0
 */

namespace SEOAutoFix\BrokenUrlManagement;

if (!defined('ABSPATH')) {
    exit;
}

class Builder_Replacement_Engine
{
    /* ─── public: URL replacement ─────────────────────────────────────────── */

    /**
     * Replace a URL inside a post using builder-specific storage awareness.
     *
     * @param int    $post_id Post ID.
     * @param string $old_url URL to replace.
     * @param string $new_url Replacement URL.
     * @return array {
     *     'success'      => bool,
     *     'builder'      => string,
     *     'replacements' => int,
     *     'method'       => string   'builder_specific' | 'universal_fallback'
     * }
     */
    public function replace_url($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ========== replace_url() START ==========');
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Post ID  : ' . $post_id);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Old URL  : ' . $old_url);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] New URL  : ' . $new_url);

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ❌ Post not found for ID ' . $post_id);
            return ['success' => false, 'builder' => 'unknown', 'replacements' => 0, 'method' => 'none'];
        }
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Post found: "' . $post->post_title . '" (type: ' . $post->post_type . ')');

        // Check if URL exists in post_content at all
        $url_in_content = (stripos($post->post_content, $old_url) !== false);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] URL found in post_content: ' . ($url_in_content ? 'YES' : 'NO'));
        if ($url_in_content) {
            $pos = stripos($post->post_content, $old_url);
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Content snippet around URL: ' . substr($post->post_content, max(0, $pos - 30), 120));
        }

        $builder = Builder_Detector::detect($post_id);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Detected builder: ' . $builder);

        $count = 0;

        switch ($builder) {
            case Builder_Detector::ELEMENTOR:
                $count = $this->replace_elementor($post_id, $old_url, $new_url);
                break;

            case Builder_Detector::WPBAKERY:
                $count = $this->replace_wpbakery($post_id, $old_url, $new_url);
                break;

            case Builder_Detector::DIVI:
                $count = $this->replace_divi($post_id, $old_url, $new_url);
                break;

            case Builder_Detector::GUTENBERG:
                $count = $this->replace_gutenberg($post_id, $old_url, $new_url);
                break;

            case Builder_Detector::CLASSIC:
                $count = $this->replace_classic($post_id, $old_url, $new_url);
                break;

            default:
                \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Unknown builder — skipping builder-specific replacement');
                break;
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Total replacements made by builder handler: ' . $count);

        if ($count > 0) {
            // Clear WP object cache for this post
            clean_post_cache($post_id);

            // VERIFY: Re-read post content to confirm the write stuck
            $post_after = get_post($post_id);
            $still_there = ($post_after && stripos($post_after->post_content, $old_url) !== false);
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] POST-WRITE VERIFY: URL still in post_content after save: ' . ($still_there ? '⚠️ YES (PROBLEM!)' : '✅ NO (GOOD)'));

            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ========== replace_url() END — SUCCESS ==========');
            return [
                'success'      => true,
                'builder'      => $builder,
                'replacements' => $count,
                'method'       => 'builder_specific',
            ];
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Builder-specific replacement returned 0 — caller should fallback');
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ========== replace_url() END — NO REPLACEMENTS ==========');

        return [
            'success'      => false,
            'builder'      => $builder,
            'replacements' => 0,
            'method'       => 'none',
        ];
    }

    /* ─── public: link removal (delete) ───────────────────────────────────── */

    /**
     * Remove a hyperlink from a post using builder-specific awareness.
     *
     * Converts: <a href="target_url">text</a> → text
     *
     * @param int    $post_id    Post ID.
     * @param string $target_url URL whose anchor tags should be removed.
     * @return array Same shape as replace_url().
     */
    public function remove_link($post_id, $target_url)
    {
        $builder = Builder_Detector::detect($post_id);
        \SEOAutoFix_Debug_Logger::log('[BUILDER] Detected (remove): ' . $builder);

        $count = 0;

        switch ($builder) {
            case Builder_Detector::ELEMENTOR:
                $count = $this->remove_elementor($post_id, $target_url);
                break;

            case Builder_Detector::GUTENBERG:
                $count = $this->remove_gutenberg($post_id, $target_url);
                break;

            case Builder_Detector::WPBAKERY:
            case Builder_Detector::DIVI:
            case Builder_Detector::CLASSIC:
                $count = $this->remove_shortcode_or_classic($post_id, $target_url);
                break;

            default:
                \SEOAutoFix_Debug_Logger::log('[BUILDER] Unknown builder — skipping builder-specific removal');
                break;
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER] Removals made: ' . $count);

        if ($count > 0) {
            clean_post_cache($post_id);

            return [
                'success'      => true,
                'builder'      => $builder,
                'replacements' => $count,
                'method'       => 'builder_specific',
            ];
        }

        return [
            'success'      => false,
            'builder'      => $builder,
            'replacements' => 0,
            'method'       => 'none',
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  BUILDER-SPECIFIC REPLACEMENT HANDLERS
     * ═══════════════════════════════════════════════════════════════════════ */

    /* ── Elementor ──────────────────────────────────────────────────────── */

    /**
     * Replace URL in Elementor's _elementor_data JSON + post_content.
     */
    private function replace_elementor($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] --- Elementor handler START ---');
        $total = 0;

        // 1) _elementor_data (JSON storage — the primary source of truth)
        $raw = get_post_meta($post_id, '_elementor_data', true);
        \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] _elementor_data exists: ' . (!empty($raw) ? 'YES (length: ' . strlen($raw) . ')' : 'NO'));

        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] JSON parse result: ' . (json_last_error() === JSON_ERROR_NONE ? 'OK' : 'FAILED: ' . json_last_error_msg()));

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // Check if URL exists in raw JSON string
                $url_in_json = (stripos($raw, $old_url) !== false);
                \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] URL found in raw _elementor_data: ' . ($url_in_json ? 'YES' : 'NO'));

                $count = 0;
                $decoded = $this->recursive_replace($decoded, $old_url, $new_url, $count);

                if ($count > 0) {
                    $encoded = wp_json_encode($decoded);
                    $update_result = update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] update_post_meta result: ' . ($update_result ? 'TRUE' : 'FALSE'));
                    $total += $count;
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] Replaced ' . $count . ' in _elementor_data');
                } else {
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] recursive_replace found 0 matches in JSON structure');
                }
            }
        }

        // 2) post_content (Elementor stores rendered HTML here)
        \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] Now replacing in post_content...');
        $content_count = $this->replace_in_post_content($post_id, $old_url, $new_url);
        $total += $content_count;

        \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] --- Elementor handler END (total: ' . $total . ') ---');
        return $total;
    }

    /* ── WPBakery ───────────────────────────────────────────────────────── */

    /**
     * Replace URL in WPBakery content (shortcodes stored in post_content)
     * and all relevant post meta.
     */
    private function replace_wpbakery($post_id, $old_url, $new_url)
    {
        $total = 0;

        // post_content (shortcodes)
        $total += $this->replace_in_post_content($post_id, $old_url, $new_url);

        // Scan all meta for the URL (WPBakery may store extra data)
        $total += $this->replace_in_all_meta($post_id, $old_url, $new_url);

        return $total;
    }

    /* ── Divi ───────────────────────────────────────────────────────────── */

    /**
     * Replace URL in Divi content (shortcodes stored in post_content)
     * and all relevant post meta.
     */
    private function replace_divi($post_id, $old_url, $new_url)
    {
        $total = 0;

        // post_content (shortcodes)
        $total += $this->replace_in_post_content($post_id, $old_url, $new_url);

        // Scan all meta
        $total += $this->replace_in_all_meta($post_id, $old_url, $new_url);

        return $total;
    }

    /* ── Gutenberg ──────────────────────────────────────────────────────── */

    /**
     * Replace URL in Gutenberg blocks using parse_blocks / serialize_blocks.
     */
    private function replace_gutenberg($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] --- Gutenberg handler START ---');
        $total = 0;

        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] Post not found or empty content');
            return 0;
        }

        // Parse blocks
        $blocks = parse_blocks($post->post_content);
        \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] Parsed ' . count($blocks) . ' top-level blocks');

        $count = 0;
        $blocks = $this->replace_in_blocks($blocks, $old_url, $new_url, $count);
        \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] Replacements in blocks: ' . $count);

        if ($count > 0) {
            $new_content = serialize_blocks($blocks);
            \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] Serialized content length: ' . strlen($new_content));

            $result = wp_update_post([
                'ID'           => $post_id,
                'post_content' => $new_content,
            ], true);

            if (is_wp_error($result)) {
                \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] ❌ wp_update_post FAILED: ' . $result->get_error_message());
            } else {
                \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] ✅ wp_update_post succeeded (post ID: ' . $result . ')');
            }
            $total += $count;
        }

        // Also scan meta
        $meta_count = $this->replace_in_all_meta($post_id, $old_url, $new_url);
        \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] Meta replacements: ' . $meta_count);
        $total += $meta_count;

        \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] --- Gutenberg handler END (total: ' . $total . ') ---');
        return $total;
    }

    /* ── Classic ────────────────────────────────────────────────────────── */

    /**
     * Replace URL in classic editor content + all meta.
     */
    private function replace_classic($post_id, $old_url, $new_url)
    {
        $total = 0;

        $total += $this->replace_in_post_content($post_id, $old_url, $new_url);
        $total += $this->replace_in_all_meta($post_id, $old_url, $new_url);

        return $total;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  BUILDER-SPECIFIC REMOVAL (DELETE) HANDLERS
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Build anchor-strip regex patterns for a target URL.
     *
     * @param string $target_url URL to remove.
     * @return array Regex patterns (capture group $1 = inner text).
     */
    private function build_anchor_patterns($target_url)
    {
        $escaped      = preg_quote($target_url, '/');
        $escaped_nots = preg_quote(untrailingslashit($target_url), '/');

        return [
            '/<a\s[^>]*href=["\']' . $escaped . '["\'][^>]*>(.*?)<\/a>/is',
            '/<a\s[^>]*href=["\']' . $escaped_nots . '\/?' . '["\'][^>]*>(.*?)<\/a>/is',
        ];
    }

    /* ── Elementor removal ─────────────────────────────────────────────── */

    /**
     * Remove link from Elementor JSON data + post_content.
     */
    private function remove_elementor($post_id, $target_url)
    {
        $total = 0;

        // 1) _elementor_data JSON — clear matching URL fields in settings
        $raw = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $count = 0;
                $decoded = $this->recursive_remove_link($decoded, $target_url, $count);

                if ($count > 0) {
                    $encoded = wp_json_encode($decoded);
                    update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
                    $total += $count;
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] Removed ' . $count . ' link(s) from _elementor_data');
                }
            }
        }

        // 2) post_content — strip anchor tags
        $total += $this->remove_anchors_in_post_content($post_id, $target_url);

        return $total;
    }

    /* ── Gutenberg removal ─────────────────────────────────────────────── */

    /**
     * Remove link from Gutenberg blocks.
     */
    private function remove_gutenberg($post_id, $target_url)
    {
        $total = 0;
        $post  = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return 0;
        }

        $blocks  = parse_blocks($post->post_content);
        $count   = 0;
        $blocks  = $this->remove_link_in_blocks($blocks, $target_url, $count);

        if ($count > 0) {
            $new_content = serialize_blocks($blocks);
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $new_content,
            ], true);
            $total += $count;
            \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] Removed ' . $count . ' link(s) from blocks');
        }

        // Also strip from meta
        $total += $this->remove_anchors_in_all_meta($post_id, $target_url);

        return $total;
    }

    /* ── WPBakery / Divi / Classic removal ──────────────────────────────── */

    /**
     * Remove anchor tags from post_content + all meta (works for any
     * shortcode-based or plain-HTML builder).
     */
    private function remove_shortcode_or_classic($post_id, $target_url)
    {
        $total = 0;
        $total += $this->remove_anchors_in_post_content($post_id, $target_url);
        $total += $this->remove_anchors_in_all_meta($post_id, $target_url);
        return $total;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  SHARED HELPERS
     * ═══════════════════════════════════════════════════════════════════════ */

    /* ── post_content replacement ──────────────────────────────────────── */

    /**
     * Simple str_ireplace in post_content.
     *
     * @return int Number of replacements.
     */
    private function replace_in_post_content($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] Checking post_content for post ID: ' . $post_id);

        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] Post not found or empty content');
            return 0;
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] Content length: ' . strlen($post->post_content));

        if (stripos($post->post_content, $old_url) === false) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] ⚠️ Old URL NOT FOUND in post_content — nothing to replace');
            return 0;
        }

        $count       = substr_count(strtolower($post->post_content), strtolower($old_url));
        \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] Found ' . $count . ' occurrence(s) of URL in post_content');

        $new_content = str_ireplace($old_url, $new_url, $post->post_content);

        // Confirm the replacement was made in memory
        $still_has_old = (stripos($new_content, $old_url) !== false);
        \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] After str_ireplace — old URL still in new content: ' . ($still_has_old ? '⚠️ YES' : '✅ NO'));

        $result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $new_content,
        ], true);

        if (is_wp_error($result)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] ❌ wp_update_post FAILED: ' . $result->get_error_message());
            return 0;
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] ✅ wp_update_post succeeded (returned: ' . $result . ')');

        // FINAL VERIFY: Re-read from database to confirm the write persisted
        wp_cache_delete($post_id, 'posts');
        $recheck = get_post($post_id);
        if ($recheck) {
            $verify_gone = (stripos($recheck->post_content, $old_url) === false);
            \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] DB RE-READ VERIFY: old URL gone from post_content: ' . ($verify_gone ? '✅ YES' : '❌ NO (WRITE DID NOT PERSIST!)'));
            if (!$verify_gone) {
                \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] ⚠️ Possible causes: kses filter, hook interference, or wp_update_post hooked filter stripping changes');
            }
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] Replaced ' . $count . ' in post_content');
        return $count;
    }

    /* ── all-meta replacement ──────────────────────────────────────────── */

    /**
     * Scan every post meta value for the old URL and replace it.
     * Handles JSON, serialized, and plain string formats.
     *
     * @return int Number of replacements.
     */
    private function replace_in_all_meta($post_id, $old_url, $new_url)
    {
        $all_meta = get_post_meta($post_id);
        if (empty($all_meta)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER:META] No meta found for post ID: ' . $post_id);
            return 0;
        }

        $skip = $this->excluded_meta_keys();
        $total = 0;
        $keys_checked = 0;
        $keys_with_url = 0;

        \SEOAutoFix_Debug_Logger::log('[BUILDER:META] Scanning ' . count($all_meta) . ' meta keys for post ID: ' . $post_id);

        foreach ($all_meta as $meta_key => $meta_values) {
            if (in_array($meta_key, $skip, true)) {
                continue;
            }
            $keys_checked++;
            foreach ($meta_values as $meta_value) {
                if (!is_string($meta_value) || stripos($meta_value, $old_url) === false) {
                    continue;
                }

                $keys_with_url++;
                \SEOAutoFix_Debug_Logger::log('[BUILDER:META] URL found in meta key: ' . $meta_key . ' (value length: ' . strlen($meta_value) . ')');

                // Try JSON decode
                $decoded = @json_decode($meta_value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $count   = 0;
                    $decoded = $this->recursive_replace($decoded, $old_url, $new_url, $count);
                    if ($count > 0) {
                        $update_result = update_post_meta($post_id, $meta_key, wp_slash(wp_json_encode($decoded)));
                        \SEOAutoFix_Debug_Logger::log('[BUILDER:META] Replaced ' . $count . ' in meta (JSON) key: ' . $meta_key . ' | update_post_meta: ' . ($update_result ? 'TRUE' : 'FALSE'));
                        $total += $count;
                    }
                    continue;
                }

                // Plain string replace
                $count     = substr_count(strtolower($meta_value), strtolower($old_url));
                $new_value = str_ireplace($old_url, $new_url, $meta_value);
                if ($new_value !== $meta_value) {
                    $update_result = update_post_meta($post_id, $meta_key, $new_value);
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:META] Replaced ' . $count . ' in meta (string) key: ' . $meta_key . ' | update_post_meta: ' . ($update_result ? 'TRUE' : 'FALSE'));
                    $total += $count;
                }
            }
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER:META] Scan complete. Keys checked: ' . $keys_checked . ', Keys with URL: ' . $keys_with_url . ', Total replacements: ' . $total);
        return $total;
    }

    /* ── post_content anchor removal ───────────────────────────────────── */

    /**
     * Strip <a href="target">text</a> → text in post_content.
     *
     * @return int 1 if modified, 0 otherwise.
     */
    private function remove_anchors_in_post_content($post_id, $target_url)
    {
        $post = get_post($post_id);
        if (!$post || empty($post->post_content) || stripos($post->post_content, $target_url) === false) {
            return 0;
        }

        $patterns    = $this->build_anchor_patterns($target_url);
        $new_content = $post->post_content;
        foreach ($patterns as $pattern) {
            $new_content = preg_replace($pattern, '$1', $new_content);
        }

        if ($new_content === $post->post_content) {
            return 0;
        }

        $result = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
        if (is_wp_error($result)) {
            return 0;
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER] Removed anchor(s) from post_content');
        return 1;
    }

    /* ── all-meta anchor removal ───────────────────────────────────────── */

    /**
     * Strip anchor tags matching the target URL from all post meta values.
     *
     * @return int Number of meta keys modified.
     */
    private function remove_anchors_in_all_meta($post_id, $target_url)
    {
        $all_meta = get_post_meta($post_id);
        if (empty($all_meta)) {
            return 0;
        }

        $skip     = $this->excluded_meta_keys();
        $patterns = $this->build_anchor_patterns($target_url);
        $modified = 0;

        foreach ($all_meta as $meta_key => $meta_values) {
            if (in_array($meta_key, $skip, true)) {
                continue;
            }
            foreach ($meta_values as $meta_value) {
                if (!is_string($meta_value) || stripos($meta_value, $target_url) === false) {
                    continue;
                }

                $updated = $meta_value;
                foreach ($patterns as $pattern) {
                    $updated = preg_replace($pattern, '$1', $updated);
                }

                if ($updated !== $meta_value) {
                    update_post_meta($post_id, $meta_key, $updated);
                    $modified++;
                    \SEOAutoFix_Debug_Logger::log('[BUILDER] Removed anchor(s) in meta key: ' . $meta_key);
                }
            }
        }

        return $modified;
    }

    /* ── recursive data-structure replacement ──────────────────────────── */

    /**
     * Recursively replace a URL in any nested array/object structure.
     *
     * @param mixed  $data    Data to process.
     * @param string $old_url URL to find.
     * @param string $new_url Replacement URL.
     * @param int    &$count  Replacement counter (by reference).
     * @return mixed Modified data.
     */
    private function recursive_replace($data, $old_url, $new_url, &$count)
    {
        if (is_string($data)) {
            if (stripos($data, $old_url) !== false) {
                $count += substr_count(strtolower($data), strtolower($old_url));
                return str_ireplace($old_url, $new_url, $data);
            }
            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->recursive_replace($value, $old_url, $new_url, $count);
            }
            return $data;
        }

        if (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->recursive_replace($value, $old_url, $new_url, $count);
            }
            return $data;
        }

        return $data;
    }

    /* ── recursive link removal for Elementor JSON ─────────────────────── */

    /**
     * Recursively remove/clear link URLs matching target in Elementor's JSON
     * data. Handles both URL fields (settings.url, settings.link) and anchor
     * tags embedded in HTML string fields.
     *
     * @param mixed  $data       Data to process.
     * @param string $target_url URL to remove.
     * @param int    &$count     Removal counter (by reference).
     * @return mixed Modified data.
     */
    private function recursive_remove_link($data, $target_url, &$count)
    {
        if (is_string($data)) {
            // Strip anchor tags whose href matches target_url
            if (stripos($data, $target_url) !== false) {
                $patterns = $this->build_anchor_patterns($target_url);
                $original = $data;
                foreach ($patterns as $pattern) {
                    $data = preg_replace($pattern, '$1', $data);
                }
                if ($data !== $original) {
                    $count++;
                }
            }
            return $data;
        }

        if (is_array($data)) {
            // Special handling: url/link fields in Elementor settings
            // These are associative arrays like { "url": "http://...", "is_external": "..." }
            if (isset($data['url']) && is_string($data['url'])) {
                $normalised_stored = untrailingslashit($data['url']);
                $normalised_target = untrailingslashit($target_url);
                if (strcasecmp($normalised_stored, $normalised_target) === 0) {
                    $data['url'] = '';
                    $count++;
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] Cleared url field');
                }
            }

            foreach ($data as $key => $value) {
                $data[$key] = $this->recursive_remove_link($value, $target_url, $count);
            }
            return $data;
        }

        if (is_object($data)) {
            if (isset($data->url) && is_string($data->url)) {
                if (strcasecmp(untrailingslashit($data->url), untrailingslashit($target_url)) === 0) {
                    $data->url = '';
                    $count++;
                }
            }
            foreach ($data as $key => $value) {
                $data->$key = $this->recursive_remove_link($value, $target_url, $count);
            }
            return $data;
        }

        return $data;
    }

    /* ── Gutenberg block helpers ───────────────────────────────────────── */

    /**
     * Replace URL inside parsed Gutenberg blocks (attrs + innerHTML).
     *
     * @param array  $blocks  Parsed blocks.
     * @param string $old_url URL to find.
     * @param string $new_url Replacement URL.
     * @param int    &$count  Counter.
     * @return array Modified blocks.
     */
    private function replace_in_blocks($blocks, $old_url, $new_url, &$count)
    {
        foreach ($blocks as &$block) {
            // Replace in block attributes
            if (!empty($block['attrs'])) {
                $block['attrs'] = $this->recursive_replace($block['attrs'], $old_url, $new_url, $count);
            }

            // Replace in innerHTML
            if (!empty($block['innerHTML']) && stripos($block['innerHTML'], $old_url) !== false) {
                $occurrences = substr_count(strtolower($block['innerHTML']), strtolower($old_url));
                $count += $occurrences;
                $block['innerHTML'] = str_ireplace($old_url, $new_url, $block['innerHTML']);
            }

            // Replace in innerContent array
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as &$piece) {
                    if (is_string($piece) && stripos($piece, $old_url) !== false) {
                        $piece = str_ireplace($old_url, $new_url, $piece);
                    }
                }
                unset($piece);
            }

            // Recurse into inner blocks
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->replace_in_blocks($block['innerBlocks'], $old_url, $new_url, $count);
            }
        }
        unset($block);

        return $blocks;
    }

    /**
     * Remove link (anchor tags) from parsed Gutenberg blocks.
     *
     * @param array  $blocks     Parsed blocks.
     * @param string $target_url URL to remove.
     * @param int    &$count     Counter.
     * @return array Modified blocks.
     */
    private function remove_link_in_blocks($blocks, $target_url, &$count)
    {
        $patterns = $this->build_anchor_patterns($target_url);

        foreach ($blocks as &$block) {
            // Strip anchors in innerHTML
            if (!empty($block['innerHTML']) && stripos($block['innerHTML'], $target_url) !== false) {
                $original = $block['innerHTML'];
                foreach ($patterns as $pattern) {
                    $block['innerHTML'] = preg_replace($pattern, '$1', $block['innerHTML']);
                }
                if ($block['innerHTML'] !== $original) {
                    $count++;
                }
            }

            // Strip anchors in innerContent array
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as &$piece) {
                    if (is_string($piece) && stripos($piece, $target_url) !== false) {
                        $original = $piece;
                        foreach ($patterns as $pattern) {
                            $piece = preg_replace($pattern, '$1', $piece);
                        }
                    }
                }
                unset($piece);
            }

            // Clear matching URL in attrs
            if (!empty($block['attrs'])) {
                $block['attrs'] = $this->recursive_remove_link($block['attrs'], $target_url, $count);
            }

            // Recurse
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->remove_link_in_blocks($block['innerBlocks'], $target_url, $count);
            }
        }
        unset($block);

        return $blocks;
    }

    /* ── excluded meta keys ────────────────────────────────────────────── */

    /**
     * Meta keys that should never be scanned for URLs.
     *
     * @return array
     */
    private function excluded_meta_keys()
    {
        return [
            '_edit_lock',
            '_edit_last',
            '_wp_page_template',
            '_thumbnail_id',
            '_wp_attachment_metadata',
            '_wp_attached_file',
            '_encloseme',
            '_pingme',
            '_elementor_css',
            '_elementor_version',
            '_elementor_edit_mode',
            '_elementor_template_type',
            '_elementor_pro_version',
        ];
    }

    /* ── cache clearing + extensibility ──────────────────────────────── */

    /**
     * Clear all relevant caches for a post after a verified content change.
     *
     * Clears: WP object cache, Elementor CSS cache, LiteSpeed single-page cache.
     * Fires the `seoautofix_after_content_modified` action for external integrations.
     *
     * @param int    $post_id   Post ID.
     * @param string $operation One of 'replace', 'delete', 'undo'.
     */
    public static function clear_all_caches($post_id, $operation = 'replace')
    {
        \SEOAutoFix_Debug_Logger::log('[CACHE] Clearing all caches for post ID: ' . $post_id . ' (operation: ' . $operation . ')');

        // 1) WordPress object cache
        clean_post_cache($post_id);

        // 2) WordPress object cache flush
        wp_cache_flush();

        // 3) Elementor CSS file cache
        if (class_exists('\\Elementor\\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
            \SEOAutoFix_Debug_Logger::log('[CACHE] Cleared Elementor CSS cache');
        }

        // 4) LiteSpeed Cache — single post purge
        if (function_exists('litespeed_purge_single')) {
            litespeed_purge_single($post_id);
            \SEOAutoFix_Debug_Logger::log('[CACHE] Purged LiteSpeed cache for post ' . $post_id);
        }

        // 5) Extensibility hook — allows CDN purge, webhooks, etc.
        do_action('seoautofix_after_content_modified', $post_id, $operation);

        \SEOAutoFix_Debug_Logger::log('[CACHE] All caches cleared for post ' . $post_id);
    }
}
