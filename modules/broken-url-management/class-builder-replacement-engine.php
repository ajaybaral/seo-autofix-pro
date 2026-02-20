<?php
/**
 * Builder-Aware Replacement Engine — Universal 4-Layer Edition
 *
 * Layer 1: Builder-specific deep recursive engine (Elementor, Gutenberg, WPBakery, Divi, Classic)
 * Layer 2: Template-level header/footer routing (Elementor templates + nav menus)
 * Layer 3: Universal postmeta deep scan (controlled — only for this post)
 * Layer 4: Global content fallback → manual_required if still zero
 *
 * @package SEOAutoFix\BrokenUrlManagement
 * @since   3.0.0
 */

namespace SEOAutoFix\BrokenUrlManagement;

if (!defined('ABSPATH')) {
    exit;
}

class Builder_Replacement_Engine
{
    /**
     * URL-bearing field keys found in Elementor and other builder data structures.
     * Checked during recursive traversal for normalized URL matching.
     */
    private static $LINK_KEYS = [
        'url',
        'href',
        'link',
        'external_url',
        'custom_link',
        'attachment_link',
        'file_url',
    ];

    /**
     * Meta keys that must never be modified.
     */
    private static $EXCLUDED_META_KEYS = [
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

    /* ─────────────────────────────────────────────────────────────────────── */
    /*  PUBLIC: URL REPLACEMENT                                                 */
    /* ─────────────────────────────────────────────────────────────────────── */

    /**
     * Replace a URL inside a post using the 4-layer durable engine.
     *
     * @param int    $post_id  Post ID.
     * @param string $old_url  URL to replace.
     * @param string $new_url  Replacement URL.
     * @param string $location Link location: 'content', 'header', 'footer', 'sidebar', 'image'.
     * @return array {
     *     'success'         => bool,
     *     'builder'         => string,
     *     'replacements'    => int,
     *     'method'          => string  'layer1'|'layer2'|'layer3'|'layer4'|'none',
     *     'manual_required' => bool,
     *     'reason'          => string,
     * }
     */
    public function replace_url($post_id, $old_url, $new_url, $location = 'content')
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ========== replace_url() START ==========');
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Post ID  : ' . $post_id);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Old URL  : ' . $old_url);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] New URL  : ' . $new_url);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Location : ' . $location);

        $post = get_post($post_id);
        if (!$post) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ❌ Post not found for ID ' . $post_id);
            return $this->result(false, 'unknown', 0, 'none', false, 'Post not found');
        }

        $builder = Builder_Detector::detect($post_id);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Detected builder: ' . $builder);

        /* ── LAYER 2: Header / Footer template routing ───────────────────── */
        if (in_array($location, ['header', 'footer'], true)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] LAYER 2 — template routing for location: ' . $location);
            $count = $this->replace_in_template($old_url, $new_url, $location);
            if ($count > 0) {
                clean_post_cache($post_id);
                \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ✅ LAYER 2 success — ' . $count . ' replacement(s)');
                return $this->result(true, 'template_' . $location, $count, 'layer2');
            }
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] LAYER 2 returned 0 — falling to Layer 1');
        }

        /* ── LAYER 1: Builder-specific deep recursive engine ─────────────── */
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
                \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Unknown builder — skipping Layer 1');
        }

        if ($count > 0) {
            clean_post_cache($post_id);
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ✅ LAYER 1 success — ' . $count . ' replacement(s)');
            return $this->result(true, $builder, $count, 'layer1');
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] LAYER 1 returned 0 — falling to Layer 3');

        /* ── LAYER 3: Universal postmeta deep scan ───────────────────────── */
        $count = $this->replace_in_postmeta_deep_scan($post_id, $old_url, $new_url);
        if ($count > 0) {
            clean_post_cache($post_id);
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ✅ LAYER 3 success — ' . $count . ' replacement(s)');
            return $this->result(true, $builder, $count, 'layer3');
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] LAYER 3 returned 0 — falling to Layer 4');

        /* ── LAYER 4: Global content fallback ───────────────────────────── */
        $count = $this->replace_in_global_content($post_id, $old_url, $new_url);
        if ($count > 0) {
            clean_post_cache($post_id);
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ✅ LAYER 4 success — ' . $count . ' replacement(s)');
            return $this->result(true, $builder, $count, 'layer4');
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ❌ All layers returned 0 — manual_required');
        return $this->result(
            false, $builder, 0, 'none', true,
            'Link not found in any storage layer. Possibly hardcoded or dynamically injected.'
        );
    }

    /* ─────────────────────────────────────────────────────────────────────── */
    /*  PUBLIC: LINK REMOVAL (DELETE)                                           */
    /* ─────────────────────────────────────────────────────────────────────── */

    /**
     * Remove a hyperlink from a post using the 4-layer durable engine.
     *
     * Converts: <a href="target_url">text</a> → text
     *
     * @param int    $post_id    Post ID.
     * @param string $target_url URL whose anchor tags should be removed.
     * @param string $location   Link location.
     * @return array Same shape as replace_url().
     */
    public function remove_link($post_id, $target_url, $location = 'content')
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ========== remove_link() START ==========');
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Post ID    : ' . $post_id);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Target URL : ' . $target_url);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Location   : ' . $location);

        $post = get_post($post_id);
        if (!$post) {
            return $this->result(false, 'unknown', 0, 'none', false, 'Post not found');
        }

        $builder = Builder_Detector::detect($post_id);
        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Detected builder: ' . $builder);

        /* ── LAYER 2: Header / Footer template routing ───────────────────── */
        if (in_array($location, ['header', 'footer'], true)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] LAYER 2 — template removal for location: ' . $location);
            $count = $this->remove_link_in_template($target_url, $location);
            if ($count > 0) {
                clean_post_cache($post_id);
                \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ✅ LAYER 2 removal success — ' . $count . ' removal(s)');
                return $this->result(true, 'template_' . $location, $count, 'layer2');
            }
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] LAYER 2 returned 0 — falling to Layer 1');
        }

        /* ── LAYER 1: Builder-specific ───────────────────────────────────── */
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
                \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] Unknown builder — skipping Layer 1 removal');
        }

        if ($count > 0) {
            clean_post_cache($post_id);
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ✅ LAYER 1 removal success — ' . $count . ' removal(s)');
            return $this->result(true, $builder, $count, 'layer1');
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] LAYER 1 removal returned 0 — falling to Layer 3');

        /* ── LAYER 3: Postmeta deep scan ─────────────────────────────────── */
        $count = $this->remove_anchors_in_postmeta_deep_scan($post_id, $target_url);
        if ($count > 0) {
            clean_post_cache($post_id);
            \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] ✅ LAYER 3 removal success — ' . $count . ' removal(s)');
            return $this->result(true, $builder, $count, 'layer3');
        }

        \SEOAutoFix_Debug_Logger::log('[BUILDER_ENGINE] LAYER 3 removal returned 0 — manual_required');
        return $this->result(
            false, $builder, 0, 'none', true,
            'Link not found in any storage layer. Possibly hardcoded or dynamically injected.'
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  LAYER 2 — TEMPLATE ROUTING                                             */
    /* ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Replace URL in Elementor theme-builder templates (header/footer) and nav menus.
     *
     * @param string $old_url  URL to replace.
     * @param string $new_url  New URL.
     * @param string $location 'header' or 'footer'.
     * @return int Total replacements made.
     */
    private function replace_in_template($old_url, $new_url, $location)
    {
        \SEOAutoFix_Debug_Logger::log('[LAYER2] replace_in_template() location=' . $location);
        $total = 0;

        /* ── Elementor theme-builder templates ─────────────────────────── */
        $template_posts = get_posts([
            'post_type'   => 'elementor_library',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => [[
                'key'   => '_elementor_template_type',
                'value' => $location,
            ]],
        ]);

        \SEOAutoFix_Debug_Logger::log('[LAYER2] Found ' . count($template_posts) . ' Elementor ' . $location . ' template(s)');

        foreach ($template_posts as $tpl) {
            $n = $this->replace_elementor($tpl->ID, $old_url, $new_url);
            \SEOAutoFix_Debug_Logger::log('[LAYER2] Template post ' . $tpl->ID . ' (' . $tpl->post_title . '): ' . $n . ' replacement(s)');
            $total += $n;
        }

        /* ── Nav menus via Header_Footer_Replacer ──────────────────────── */
        if (class_exists(__NAMESPACE__ . '\\Header_Footer_Replacer')) {
            $hf = new Header_Footer_Replacer();
            $hf_result = $hf->replace_in_site_wide_elements($old_url, $new_url);
            $hf_count  = $hf_result['replacements'] ?? 0;
            \SEOAutoFix_Debug_Logger::log('[LAYER2] Header_Footer_Replacer: ' . $hf_count . ' replacement(s)');
            $total += $hf_count;
        }

        \SEOAutoFix_Debug_Logger::log('[LAYER2] replace_in_template() total: ' . $total);
        return $total;
    }

    /**
     * Remove link in Elementor theme-builder templates (header/footer) and nav menus.
     *
     * @param string $target_url URL to remove.
     * @param string $location   'header' or 'footer'.
     * @return int Total removals made.
     */
    private function remove_link_in_template($target_url, $location)
    {
        \SEOAutoFix_Debug_Logger::log('[LAYER2] remove_link_in_template() location=' . $location);
        $total = 0;

        $template_posts = get_posts([
            'post_type'   => 'elementor_library',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => [[
                'key'   => '_elementor_template_type',
                'value' => $location,
            ]],
        ]);

        \SEOAutoFix_Debug_Logger::log('[LAYER2] Found ' . count($template_posts) . ' Elementor ' . $location . ' template(s) for removal');

        foreach ($template_posts as $tpl) {
            $n = $this->remove_elementor($tpl->ID, $target_url);
            \SEOAutoFix_Debug_Logger::log('[LAYER2] Template post ' . $tpl->ID . ': ' . $n . ' removal(s)');
            $total += $n;
        }

        /* ── Nav menu items ────────────────────────────────────────────── */
        $nav_count = $this->remove_from_nav_menus($target_url);
        \SEOAutoFix_Debug_Logger::log('[LAYER2] Nav menu removals: ' . $nav_count);
        $total += $nav_count;

        return $total;
    }

    /**
     * Clear the URL from nav menu items that use it.
     *
     * @param string $target_url URL to clear.
     * @return int Number of menu items updated.
     */
    private function remove_from_nav_menus($target_url)
    {
        global $wpdb;
        $count = 0;
        $menus = get_registered_nav_menus();
        foreach (array_keys($menus) as $location) {
            $menu_id = get_nav_menu_locations()[$location] ?? 0;
            if (!$menu_id) {
                continue;
            }
            $items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
            if (empty($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (self::normalize_url($item->url) === self::normalize_url($target_url)) {
                    update_post_meta($item->ID, '_menu_item_url', '');
                    $count++;
                    \SEOAutoFix_Debug_Logger::log('[LAYER2] Cleared nav menu item #' . $item->ID . ' URL: ' . $item->url);
                }
            }
        }
        return $count;
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  LAYER 3 — UNIVERSAL POSTMETA DEEP SCAN                                 */
    /* ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Deep-scan all postmeta for the old URL and replace it.
     * Handles JSON, serialized PHP, and plain strings.
     *
     * Scoped to a single post — never scans the entire DB.
     *
     * @param int    $post_id Post ID.
     * @param string $old_url URL to find.
     * @param string $new_url Replacement URL.
     * @return int Number of replacements.
     */
    private function replace_in_postmeta_deep_scan($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[LAYER3] replace_in_postmeta_deep_scan() post_id=' . $post_id);
        global $wpdb;

        $norm = self::normalize_url($old_url);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_value LIKE %s",
            $post_id,
            '%' . $wpdb->esc_like($old_url) . '%'
        ), ARRAY_A);

        \SEOAutoFix_Debug_Logger::log('[LAYER3] Rows with URL match: ' . count($rows));

        $total = 0;
        foreach ($rows as $row) {
            $meta_key = $row['meta_key'];
            $raw      = $row['meta_value'];

            if (in_array($meta_key, self::$EXCLUDED_META_KEYS, true)) {
                continue;
            }

            // Try JSON
            $decoded = @json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $count    = 0;
                $modified = $this->recursive_replace($decoded, $old_url, $new_url, $count);
                if ($count > 0) {
                    $encoded = wp_json_encode($modified);
                    update_post_meta($post_id, $meta_key, wp_slash($encoded));
                    \SEOAutoFix_Debug_Logger::log('[LAYER3] JSON meta key "' . $meta_key . '": ' . $count . ' replacement(s)');
                    $total += $count;
                }
                continue;
            }

            // Try WordPress serialized
            if (is_serialized($raw)) {
                $data  = @unserialize($raw);
                $count = 0;
                if ($data !== false) {
                    $modified = $this->recursive_replace($data, $old_url, $new_url, $count);
                    if ($count > 0) {
                        update_post_meta($post_id, $meta_key, $modified);
                        \SEOAutoFix_Debug_Logger::log('[LAYER3] Serialized meta key "' . $meta_key . '": ' . $count . ' replacement(s)');
                        $total += $count;
                    }
                }
                continue;
            }

            // Plain string
            if (is_string($raw) && self::url_in_string($old_url, $raw)) {
                $new_value = $this->str_replace_normalized($old_url, $new_url, $raw);
                if ($new_value !== $raw) {
                    update_post_meta($post_id, $meta_key, $new_value);
                    \SEOAutoFix_Debug_Logger::log('[LAYER3] Plain string meta key "' . $meta_key . '": replaced');
                    $total++;
                }
            }
        }

        \SEOAutoFix_Debug_Logger::log('[LAYER3] replace_in_postmeta_deep_scan() total: ' . $total);
        return $total;
    }

    /**
     * Deep-scan all postmeta and strip anchor tags matching the target URL.
     *
     * @param int    $post_id    Post ID.
     * @param string $target_url URL to remove.
     * @return int Number of meta keys modified.
     */
    private function remove_anchors_in_postmeta_deep_scan($post_id, $target_url)
    {
        \SEOAutoFix_Debug_Logger::log('[LAYER3] remove_anchors_in_postmeta_deep_scan() post_id=' . $post_id);
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_value LIKE %s",
            $post_id,
            '%' . $wpdb->esc_like($target_url) . '%'
        ), ARRAY_A);

        $patterns = $this->build_anchor_patterns($target_url);
        $count    = 0;

        foreach ($rows as $row) {
            $meta_key = $row['meta_key'];
            $raw      = $row['meta_value'];

            if (in_array($meta_key, self::$EXCLUDED_META_KEYS, true)) {
                continue;
            }

            $updated = $raw;
            foreach ($patterns as $pattern) {
                $updated = preg_replace($pattern, '$1', $updated);
            }

            // Also clear URL-field occurrences inside JSON
            $decoded = @json_decode($updated, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $c       = 0;
                $decoded = $this->recursive_remove_link($decoded, $target_url, $c);
                if ($c > 0) {
                    $updated = wp_json_encode($decoded);
                }
            }

            if ($updated !== $raw) {
                update_post_meta($post_id, $meta_key, wp_slash($updated));
                $count++;
                \SEOAutoFix_Debug_Logger::log('[LAYER3] Removed anchor in meta key "' . $meta_key . '"');
            }
        }

        \SEOAutoFix_Debug_Logger::log('[LAYER3] remove_anchors_in_postmeta_deep_scan() total keys modified: ' . $count);
        return $count;
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  LAYER 4 — GLOBAL CONTENT FALLBACK                                      */
    /* ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Last-resort: try post_content raw + widget options + theme_mods.
     *
     * @param int    $post_id Post ID.
     * @param string $old_url URL to replace.
     * @param string $new_url Replacement URL.
     * @return int Replacements made.
     */
    private function replace_in_global_content($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[LAYER4] replace_in_global_content() post_id=' . $post_id);
        $total = 0;

        // post_content (raw, normalized match)
        $total += $this->replace_in_post_content($post_id, $old_url, $new_url);

        // theme_mods
        $theme_mods = get_theme_mods();
        if (is_array($theme_mods)) {
            $count    = 0;
            $modified = $this->recursive_replace($theme_mods, $old_url, $new_url, $count);
            if ($count > 0) {
                foreach ($modified as $key => $value) {
                    set_theme_mod($key, $value);
                }
                \SEOAutoFix_Debug_Logger::log('[LAYER4] theme_mods: ' . $count . ' replacement(s)');
                $total += $count;
            }
        }

        // Widget options (text, custom_html)
        foreach (['widget_text', 'widget_custom_html'] as $option_key) {
            $opt = get_option($option_key);
            if (is_array($opt)) {
                $count    = 0;
                $modified = $this->recursive_replace($opt, $old_url, $new_url, $count);
                if ($count > 0) {
                    update_option($option_key, $modified);
                    \SEOAutoFix_Debug_Logger::log('[LAYER4] Option "' . $option_key . '": ' . $count . ' replacement(s)');
                    $total += $count;
                }
            }
        }

        \SEOAutoFix_Debug_Logger::log('[LAYER4] replace_in_global_content() total: ' . $total);
        return $total;
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  BUILDER-SPECIFIC REPLACEMENT HANDLERS (LAYER 1)                        */
    /* ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Replace URL in Elementor's _elementor_data JSON + post_content.
     */
    private function replace_elementor($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] --- Elementor handler START ---');
        $total = 0;

        // 1) _elementor_data
        $raw = get_post_meta($post_id, '_elementor_data', true);
        \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] _elementor_data exists: ' . (!empty($raw) ? 'YES (length: ' . strlen($raw) . ')' : 'NO'));

        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $count   = 0;
                $decoded = $this->recursive_replace($decoded, $old_url, $new_url, $count);
                if ($count > 0) {
                    $encoded = wp_json_encode($decoded);
                    update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
                    $total += $count;
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] Replaced ' . $count . ' in _elementor_data');
                } else {
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] recursive_replace found 0 in JSON');
                }
            }
        }

        // Also scan _elementor_page_settings
        $page_settings_raw = get_post_meta($post_id, '_elementor_page_settings', true);
        if (!empty($page_settings_raw) && is_string($page_settings_raw)) {
            $page_settings = json_decode($page_settings_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($page_settings)) {
                $count        = 0;
                $page_settings = $this->recursive_replace($page_settings, $old_url, $new_url, $count);
                if ($count > 0) {
                    update_post_meta($post_id, '_elementor_page_settings', wp_slash(wp_json_encode($page_settings)));
                    $total += $count;
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] Replaced ' . $count . ' in _elementor_page_settings');
                }
            }
        }

        // 2) post_content
        $content_count = $this->replace_in_post_content($post_id, $old_url, $new_url);
        $total        += $content_count;

        \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] --- Elementor handler END (total: ' . $total . ') ---');
        return $total;
    }

    /**
     * Replace URL in WPBakery content and all relevant post meta.
     */
    private function replace_wpbakery($post_id, $old_url, $new_url)
    {
        $total  = 0;
        $total += $this->replace_in_post_content($post_id, $old_url, $new_url);
        $total += $this->replace_in_all_meta($post_id, $old_url, $new_url);
        return $total;
    }

    /**
     * Replace URL in Divi content and all relevant post meta.
     */
    private function replace_divi($post_id, $old_url, $new_url)
    {
        $total  = 0;
        $total += $this->replace_in_post_content($post_id, $old_url, $new_url);
        $total += $this->replace_in_all_meta($post_id, $old_url, $new_url);
        return $total;
    }

    /**
     * Replace URL in Gutenberg blocks.
     */
    private function replace_gutenberg($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] --- Gutenberg handler START ---');
        $total = 0;

        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return 0;
        }

        $blocks = parse_blocks($post->post_content);
        $count  = 0;
        $blocks = $this->replace_in_blocks($blocks, $old_url, $new_url, $count);

        if ($count > 0) {
            $new_content = serialize_blocks($blocks);
            $result      = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
            if (is_wp_error($result)) {
                \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] ❌ wp_update_post FAILED: ' . $result->get_error_message());
            } else {
                $total += $count;
                \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] ✅ Updated blocks (' . $count . ' replacements)');
            }
        }

        $total += $this->replace_in_all_meta($post_id, $old_url, $new_url);

        \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] --- Gutenberg handler END (total: ' . $total . ') ---');
        return $total;
    }

    /**
     * Replace URL in Classic Editor content + all meta.
     */
    private function replace_classic($post_id, $old_url, $new_url)
    {
        $total  = 0;
        $total += $this->replace_in_post_content($post_id, $old_url, $new_url);
        $total += $this->replace_in_all_meta($post_id, $old_url, $new_url);
        return $total;
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  BUILDER-SPECIFIC REMOVAL HANDLERS (LAYER 1)                            */
    /* ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Remove link from Elementor JSON data + post_content.
     */
    private function remove_elementor($post_id, $target_url)
    {
        $total = 0;

        // _elementor_data
        $raw = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $count   = 0;
                $decoded = $this->recursive_remove_link($decoded, $target_url, $count);
                if ($count > 0) {
                    update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($decoded)));
                    $total += $count;
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:ELEMENTOR] Removed ' . $count . ' link(s) from _elementor_data');
                }
            }
        }

        // _elementor_page_settings
        $ps_raw = get_post_meta($post_id, '_elementor_page_settings', true);
        if (!empty($ps_raw) && is_string($ps_raw)) {
            $ps = json_decode($ps_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($ps)) {
                $count = 0;
                $ps    = $this->recursive_remove_link($ps, $target_url, $count);
                if ($count > 0) {
                    update_post_meta($post_id, '_elementor_page_settings', wp_slash(wp_json_encode($ps)));
                    $total += $count;
                }
            }
        }

        // post_content
        $total += $this->remove_anchors_in_post_content($post_id, $target_url);

        return $total;
    }

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

        $blocks = parse_blocks($post->post_content);
        $count  = 0;
        $blocks = $this->remove_link_in_blocks($blocks, $target_url, $count);

        if ($count > 0) {
            wp_update_post(['ID' => $post_id, 'post_content' => serialize_blocks($blocks)], true);
            $total += $count;
            \SEOAutoFix_Debug_Logger::log('[BUILDER:GUTENBERG] Removed ' . $count . ' link(s) from blocks');
        }

        $total += $this->remove_anchors_in_all_meta($post_id, $target_url);
        return $total;
    }

    /**
     * Remove anchor tags from post_content + all meta (WPBakery / Divi / Classic).
     */
    private function remove_shortcode_or_classic($post_id, $target_url)
    {
        $total  = 0;
        $total += $this->remove_anchors_in_post_content($post_id, $target_url);
        $total += $this->remove_anchors_in_all_meta($post_id, $target_url);
        return $total;
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  SHARED HELPERS                                                          */
    /* ═══════════════════════════════════════════════════════════════════════ */

    /* ── post_content replacement ─────────────────────────────────────────── */

    /**
     * Normalized str_ireplace in post_content.
     *
     * @return int Number of replacements.
     */
    private function replace_in_post_content($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] Checking post_content for post ID: ' . $post_id);

        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return 0;
        }

        if (!self::url_in_string($old_url, $post->post_content)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] ⚠️ Old URL NOT FOUND in post_content');
            return 0;
        }

        $count       = substr_count(strtolower($post->post_content), strtolower($old_url));
        $new_content = $this->str_replace_normalized($old_url, $new_url, $post->post_content);

        $result = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
        if (is_wp_error($result)) {
            \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] ❌ wp_update_post FAILED: ' . $result->get_error_message());
            return 0;
        }

        // Verify
        wp_cache_delete($post_id, 'posts');
        $recheck     = get_post($post_id);
        $verify_gone = $recheck && !self::url_in_string($old_url, $recheck->post_content);
        \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] DB verify: old URL gone: ' . ($verify_gone ? '✅ YES' : '❌ NO'));

        \SEOAutoFix_Debug_Logger::log('[BUILDER:POST_CONTENT] Replaced ' . $count . ' in post_content');
        return $count;
    }

    /* ── all-meta replacement ─────────────────────────────────────────────── */

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

        $total = 0;

        foreach ($all_meta as $meta_key => $meta_values) {
            if (in_array($meta_key, self::$EXCLUDED_META_KEYS, true)) {
                continue;
            }
            foreach ($meta_values as $meta_value) {
                if (!is_string($meta_value) || !self::url_in_string($old_url, $meta_value)) {
                    continue;
                }

                \SEOAutoFix_Debug_Logger::log('[BUILDER:META] URL found in meta key: ' . $meta_key);

                // JSON
                $decoded = @json_decode($meta_value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $count   = 0;
                    $decoded = $this->recursive_replace($decoded, $old_url, $new_url, $count);
                    if ($count > 0) {
                        update_post_meta($post_id, $meta_key, wp_slash(wp_json_encode($decoded)));
                        \SEOAutoFix_Debug_Logger::log('[BUILDER:META] JSON key "' . $meta_key . '": ' . $count . ' replacement(s)');
                        $total += $count;
                    }
                    continue;
                }

                // Plain string
                $count     = substr_count(strtolower($meta_value), strtolower($old_url));
                $new_value = $this->str_replace_normalized($old_url, $new_url, $meta_value);
                if ($new_value !== $meta_value) {
                    update_post_meta($post_id, $meta_key, $new_value);
                    \SEOAutoFix_Debug_Logger::log('[BUILDER:META] Plain key "' . $meta_key . '": ' . $count . ' replacement(s)');
                    $total += $count;
                }
            }
        }

        return $total;
    }

    /* ── post_content anchor removal ──────────────────────────────────────── */

    /**
     * Strip <a href="target">text</a> → text in post_content.
     *
     * @return int 1 if modified, 0 otherwise.
     */
    private function remove_anchors_in_post_content($post_id, $target_url)
    {
        $post = get_post($post_id);
        if (!$post || empty($post->post_content) || !self::url_in_string($target_url, $post->post_content)) {
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

    /* ── all-meta anchor removal ──────────────────────────────────────────── */

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

        $patterns = $this->build_anchor_patterns($target_url);
        $modified = 0;

        foreach ($all_meta as $meta_key => $meta_values) {
            if (in_array($meta_key, self::$EXCLUDED_META_KEYS, true)) {
                continue;
            }
            foreach ($meta_values as $meta_value) {
                if (!is_string($meta_value) || !self::url_in_string($target_url, $meta_value)) {
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

    /* ── recursive data-structure replacement ─────────────────────────────── */

    /**
     * Recursively replace a URL in any nested array/object structure.
     * Uses normalized URL comparison and handles URL-bearing link-field keys.
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
            if (self::url_in_string($old_url, $data)) {
                $count += substr_count(strtolower($data), strtolower($old_url));
                // Also count normalized variants without trailing slash
                $no_slash = untrailingslashit($old_url);
                if ($no_slash !== $old_url) {
                    $count += substr_count(strtolower($data), strtolower($no_slash));
                }
                return $this->str_replace_normalized($old_url, $new_url, $data);
            }
            return $data;
        }

        if (is_array($data)) {
            // ── Link-field key detection ──────────────────────────────────
            foreach (self::$LINK_KEYS as $lk) {
                if (isset($data[$lk]) && is_string($data[$lk])) {
                    if (self::normalize_url($data[$lk]) === self::normalize_url($old_url)) {
                        $data[$lk] = $new_url;
                        $count++;
                        \SEOAutoFix_Debug_Logger::log('[RECURSIVE] Replaced link-field key "' . $lk . '" → ' . $new_url);
                    }
                }
            }
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

    /* ── recursive link removal for Elementor JSON ────────────────────────── */

    /**
     * Recursively remove/clear link URLs matching target in any nested structure.
     * Handles both URL string fields and embedded anchor tags in HTML strings.
     *
     * @param mixed  $data       Data to process.
     * @param string $target_url URL to remove.
     * @param int    &$count     Removal counter (by reference).
     * @return mixed Modified data.
     */
    private function recursive_remove_link($data, $target_url, &$count)
    {
        if (is_string($data)) {
            if (self::url_in_string($target_url, $data)) {
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
            // ── Link-field key detection (normalized comparison) ──────────
            foreach (self::$LINK_KEYS as $lk) {
                if (isset($data[$lk]) && is_string($data[$lk])) {
                    if (self::normalize_url($data[$lk]) === self::normalize_url($target_url)) {
                        $data[$lk] = '';
                        $count++;
                        \SEOAutoFix_Debug_Logger::log('[RECURSIVE_REMOVE] Cleared link-field "' . $lk . '"');
                    }
                }
            }
            foreach ($data as $key => $value) {
                $data[$key] = $this->recursive_remove_link($value, $target_url, $count);
            }
            return $data;
        }

        if (is_object($data)) {
            foreach (self::$LINK_KEYS as $lk) {
                if (isset($data->$lk) && is_string($data->$lk)) {
                    if (self::normalize_url($data->$lk) === self::normalize_url($target_url)) {
                        $data->$lk = '';
                        $count++;
                    }
                }
            }
            foreach ($data as $key => $value) {
                $data->$key = $this->recursive_remove_link($value, $target_url, $count);
            }
            return $data;
        }

        return $data;
    }

    /* ── Gutenberg block helpers ───────────────────────────────────────────── */

    /**
     * Replace URL inside parsed Gutenberg blocks (attrs + innerHTML + innerContent).
     */
    private function replace_in_blocks($blocks, $old_url, $new_url, &$count)
    {
        foreach ($blocks as &$block) {
            if (!empty($block['attrs'])) {
                $block['attrs'] = $this->recursive_replace($block['attrs'], $old_url, $new_url, $count);
            }
            if (!empty($block['innerHTML']) && self::url_in_string($old_url, $block['innerHTML'])) {
                $occurrences        = substr_count(strtolower($block['innerHTML']), strtolower($old_url));
                $count             += $occurrences;
                $block['innerHTML'] = $this->str_replace_normalized($old_url, $new_url, $block['innerHTML']);
            }
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as &$piece) {
                    if (is_string($piece) && self::url_in_string($old_url, $piece)) {
                        $piece = $this->str_replace_normalized($old_url, $new_url, $piece);
                    }
                }
                unset($piece);
            }
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->replace_in_blocks($block['innerBlocks'], $old_url, $new_url, $count);
            }
        }
        unset($block);
        return $blocks;
    }

    /**
     * Remove link (anchor tags) from parsed Gutenberg blocks.
     */
    private function remove_link_in_blocks($blocks, $target_url, &$count)
    {
        $patterns = $this->build_anchor_patterns($target_url);

        foreach ($blocks as &$block) {
            if (!empty($block['innerHTML']) && self::url_in_string($target_url, $block['innerHTML'])) {
                $original           = $block['innerHTML'];
                foreach ($patterns as $pattern) {
                    $block['innerHTML'] = preg_replace($pattern, '$1', $block['innerHTML']);
                }
                if ($block['innerHTML'] !== $original) {
                    $count++;
                }
            }
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as &$piece) {
                    if (is_string($piece) && self::url_in_string($target_url, $piece)) {
                        $orig  = $piece;
                        foreach ($patterns as $pattern) {
                            $piece = preg_replace($pattern, '$1', $piece);
                        }
                        if ($piece !== $orig) {
                            $count++;
                        }
                    }
                }
                unset($piece);
            }
            if (!empty($block['attrs'])) {
                $block['attrs'] = $this->recursive_remove_link($block['attrs'], $target_url, $count);
            }
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->remove_link_in_blocks($block['innerBlocks'], $target_url, $count);
            }
        }
        unset($block);
        return $blocks;
    }

    /* ── Anchor pattern builder ───────────────────────────────────────────── */

    /**
     * Build anchor-strip regex patterns for a target URL.
     * Handles both exact URL and trailing-slash variant.
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
            '/<a\s[^>]*href=["\']' . $escaped_nots . '\\/?' . '["\'][^>]*>(.*?)<\/a>/is',
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  URL NORMALIZATION HELPERS                                               */
    /* ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Normalize a URL for comparison purposes.
     * Lowercases, strips protocol, removes trailing slash, decodes entities.
     *
     * @param string $url URL to normalize.
     * @return string Normalized URL.
     */
    public static function normalize_url($url)
    {
        // Decode HTML entities (e.g. &amp; → & , &#47; → /)
        $url = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Strip whitespace
        $url = trim($url);
        // Lowercase
        $url = strtolower($url);
        // Strip protocol
        $url = preg_replace('#^https?://#', '', $url);
        // Remove trailing slash
        $url = rtrim($url, '/');
        return $url;
    }

    /**
     * Check whether a URL (or its normalized form / trailing-slash variant) appears
     * anywhere in a string. Used as a fast pre-check before replacement.
     *
     * @param string $url    URL to search for.
     * @param string $haystack String to search in.
     * @return bool
     */
    private static function url_in_string($url, $haystack)
    {
        if (stripos($haystack, $url) !== false) {
            return true;
        }
        // Also check without trailing slash
        $no_slash = untrailingslashit($url);
        if ($no_slash !== $url && stripos($haystack, $no_slash) !== false) {
            return true;
        }
        return false;
    }

    /**
     * Replace old URL in a string, also handling normalized variants
     * (with/without trailing slash, http vs https).
     *
     * @param string $old_url  URL to find.
     * @param string $new_url  Replacement URL.
     * @param string $haystack String to operate on.
     * @return string Modified string.
     */
    private function str_replace_normalized($old_url, $new_url, $haystack)
    {
        // Replace exact match
        $result = str_ireplace($old_url, $new_url, $haystack);

        // Replace without trailing slash if different
        $no_slash = untrailingslashit($old_url);
        if ($no_slash !== $old_url) {
            $result = str_ireplace($no_slash, $new_url, $result);
        }

        // Replace http ↔ https variant
        if (strpos($old_url, 'https://') === 0) {
            $http_variant = str_replace('https://', 'http://', $old_url);
            $result       = str_ireplace($http_variant, $new_url, $result);
        } elseif (strpos($old_url, 'http://') === 0) {
            $https_variant = str_replace('http://', 'https://', $old_url);
            $result        = str_ireplace($https_variant, $new_url, $result);
        }

        return $result;
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  RESULT BUILDER                                                          */
    /* ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Build a standardized result array.
     */
    private function result($success, $builder, $replacements, $method, $manual_required = false, $reason = '')
    {
        return [
            'success'         => $success,
            'builder'         => $builder,
            'replacements'    => $replacements,
            'method'          => $method,
            'manual_required' => $manual_required,
            'reason'          => $reason,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════ */
    /*  CACHE CLEARING + EXTENSIBILITY                                          */
    /* ═══════════════════════════════════════════════════════════════════════ */

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
        if (class_exists('\Elementor\Plugin')) {
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
