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
        $builder = Builder_Detector::detect($post_id);
        \SEOAutoFix_Debug_Logger::log('[BUILDER] Detected: ' . $builder);

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
                \SEOAutoFix_Debug_Logger::log('[BUILDER] Unknown builder — skipping builder-specific replacement');
                break;
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER] Replacements made: ' . $count);

        if ($count > 0) {
            // Clear WP object cache for this post
            clean_post_cache($post_id);

            return [
                'success'      => true,
                'builder'      => $builder,
                'replacements' => $count,
                'method'       => 'builder_specific',
            ];
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER] Builder-specific replacement returned 0 — caller should fallback');

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
        $total = 0;

        // 1) _elementor_data (JSON storage — the primary source of truth)
        $raw = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $count = 0;
                $decoded = $this->recursive_replace($decoded, $old_url, $new_url, $count);

                if ($count > 0) {
                    $encoded = wp_json_encode($decoded);
                    update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
                    $total += $count;
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] Replaced ' . $count . ' in _elementor_data');
                }
            }
        }

        // 2) post_content (Elementor stores rendered HTML here)
        $total += $this->replace_in_post_content($post_id, $old_url, $new_url);

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
        $total = 0;

        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return 0;
        }

        // Parse blocks
        $blocks = parse_blocks($post->post_content);
        $count = 0;
        $blocks = $this->replace_in_blocks($blocks, $old_url, $new_url, $count);

        if ($count > 0) {
            $new_content = serialize_blocks($blocks);
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $new_content,
            ], true);
            $total += $count;
            \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] Replaced ' . $count . ' in blocks');
        }

        // Also scan meta (Gutenberg reusable blocks or custom field plugins)
        $total += $this->replace_in_all_meta($post_id, $old_url, $new_url);

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
        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return 0;
        }

        if (stripos($post->post_content, $old_url) === false) {
            return 0;
        }

        $count       = substr_count(strtolower($post->post_content), strtolower($old_url));
        $new_content = str_ireplace($old_url, $new_url, $post->post_content);

        $result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $new_content,
        ], true);

        if (is_wp_error($result)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER] ❌ wp_update_post failed for post_content: ' . $result->get_error_message());
            return 0;
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER] Replaced ' . $count . ' in post_content');
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
            return 0;
        }

        $skip = $this->excluded_meta_keys();
        $total = 0;

        foreach ($all_meta as $meta_key => $meta_values) {
            if (in_array($meta_key, $skip, true)) {
                continue;
            }
            foreach ($meta_values as $meta_value) {
                if (!is_string($meta_value) || stripos($meta_value, $old_url) === false) {
                    continue;
                }

                // Try JSON decode
                $decoded = @json_decode($meta_value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $count   = 0;
                    $decoded = $this->recursive_replace($decoded, $old_url, $new_url, $count);
                    if ($count > 0) {
                        update_post_meta($post_id, $meta_key, wp_slash(wp_json_encode($decoded)));
                        $total += $count;
                        \SEOAutoFix_Debug_Logger::log('[BUILDER] Replaced ' . $count . ' in meta (JSON) key: ' . $meta_key);
                    }
                    continue;
                }

                // Plain string replace
                $count     = substr_count(strtolower($meta_value), strtolower($old_url));
                $new_value = str_ireplace($old_url, $new_url, $meta_value);
                if ($new_value !== $meta_value) {
                    update_post_meta($post_id, $meta_key, $new_value);
                    $total += $count;
                    \SEOAutoFix_Debug_Logger::log('[BUILDER] Replaced ' . $count . ' in meta (string) key: ' . $meta_key);
                }
            }
        }

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
}
