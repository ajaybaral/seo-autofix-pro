<?php
/**
 * Meta Description Optimization — Scan Engine
 *
 * @package SEO_AutoFix_Pro
 * @subpackage MetaDescriptionOptimization
 */

namespace SEOAutoFix\MetaDescriptionOptimization;

if (!defined('ABSPATH')) {
    exit;
}

class Description_Scanner
{

    const MIN_LEN = 60;
    const MAX_LEN = 120;

    // Issue type constants
    const ISSUE_MISSING = 'missing';
    const ISSUE_SHORT = 'too_short';
    const ISSUE_LONG = 'too_long';
    const ISSUE_DUPLICATE = 'duplicate';
    const ISSUE_OK = 'ok';

    /** @var string|null memoised */
    private $seo_plugin = null;

    /* ------------------------------------------------------------------ */

    /**
     * Detect active SEO plugin — isolated duplicate, no cross-module import.
     */
    public function detect_seo_plugin(): string
    {
        if (null !== $this->seo_plugin) {
            return $this->seo_plugin;
        }

        if (defined('WPSEO_VERSION')) {
            $this->seo_plugin = 'yoast';
        } elseif (defined('RANK_MATH_VERSION')) {
            $this->seo_plugin = 'rankmath';
        } elseif (defined('AIOSEO_VERSION')) {
            $this->seo_plugin = 'aioseo';
        } else {
            $this->seo_plugin = 'native';
        }

        \SEOAutoFix_Debug_Logger::log('[METADESC SCANNER] SEO plugin detected: ' . $this->seo_plugin, 'meta-desc');
        return $this->seo_plugin;
    }

    /**
     * Get SEO meta description for a post.
     * Routes to the correct resolver per active plugin.
     */
    public function get_seo_description(int $post_id): string
    {
        switch ($this->detect_seo_plugin()) {
            case 'yoast':
                return $this->get_yoast_description($post_id);
            case 'rankmath':
                return $this->get_rankmath_description($post_id);
            case 'aioseo':
                return $this->get_aioseo_description($post_id);
            default:
                return $this->get_native_description($post_id);
        }
    }

    /**
     * Yoast SEO: read _wpseo_metadesc and resolve %%variable%% format.
     * If the meta is empty, Yoast uses its global default post-type description
     * template — stored under metadesc-{post_type} in wpseo_titles option.
     */
    private function get_yoast_description(int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }
        $raw = (string) get_post_meta($post_id, '_wpseo_metadesc', true);

        // If Yoast meta description is empty, fall back to the global post-type description template.
        if ('' === $raw) {
            $wpseo_titles = get_option('wpseo_titles', array());
            $post_type    = $post->post_type;
            $raw = $wpseo_titles['metadesc-' . $post_type] ?? $wpseo_titles['metadesc-post'] ?? '';
        }

        if ('' === $raw) {
            return '';
        }

        // Yoast uses %%variable%% style tags.
        return $this->resolve_yoast_variables($raw, $post);
    }

    /**
     * Resolve Yoast %%variable%% placeholders to actual values.
     */
    private function resolve_yoast_variables(string $raw, \WP_Post $post): string
    {
        if (false === strpos($raw, '%%')) {
            return trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $sep     = html_entity_decode(
            (string) (get_option('wpseo_titles')['separator'] ?? '-'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $author  = get_the_author_meta('display_name', (int) $post->post_author);
        $cats    = get_the_category($post->ID);
        $cat     = !empty($cats) ? $cats[0]->name : '';
        $tags    = get_the_tags($post->ID);
        $tag     = (!empty($tags) && !is_wp_error($tags)) ? $tags[0]->name : '';

        $map = array(
            '%%title%%'         => $post->post_title,
            '%%sep%%'           => $sep,
            '%%sitename%%'      => get_bloginfo('name'),
            '%%sitedesc%%'      => get_bloginfo('description'),
            '%%name%%'          => $author,
            '%%post_author%%'   => $author,
            '%%category%%'      => $cat,
            '%%tag%%'           => $tag,
            '%%date%%'          => get_the_date('', $post),
            '%%modified%%'      => get_the_modified_date('', $post),
            '%%currentyear%%'   => date('Y'),
            '%%currentmonth%%'  => date('F'),
            '%%currentday%%'    => date('j'),
            '%%excerpt%%'       => wp_trim_words($post->post_excerpt ?: $post->post_content, 30),
        );

        $description = str_ireplace(array_keys($map), array_values($map), $raw);
        $description = preg_replace('/%%[a-z_]+%%/i', '', $description);
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s{2,}/', ' ', $description));
    }

    /**
     * Rank Math: read rank_math_description meta and resolve %variable% format.
     * Falls back to Rank Math global post-type description template if meta is empty.
     */
    private function get_rankmath_description(int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $raw = (string) get_post_meta($post_id, 'rank_math_description', true);

        // Fall back to Rank Math global post-type description template.
        // Option: rank-math-options-titles  key: pt_{post_type}_description
        if ('' === $raw) {
            $rm_titles = get_option('rank-math-options-titles', array());
            $post_type = $post->post_type;
            $raw = $rm_titles['pt_' . $post_type . '_description']
                ?? $rm_titles['pt_post_description']
                ?? '';
        }

        if ('' === $raw) {
            return '';
        }

        return $this->resolve_rankmath_variables($raw, $post);
    }

    /**
     * Resolve Rank Math %variable% placeholders to actual values.
     * Rank Math uses single-% delimiters: %title%, %sep%, %sitename% etc.
     */
    private function resolve_rankmath_variables(string $raw, \WP_Post $post): string
    {
        if (false === strpos($raw, '%')) {
            return trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Rank Math separator stored in rank-math-options-general under 'title_separator'.
        $rm_gen = get_option('rank-math-options-general', array());
        $sep = !empty($rm_gen['title_separator'])
            ? html_entity_decode((string) $rm_gen['title_separator'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '-';

        $author_id = (int) $post->post_author;
        $cats    = get_the_category($post->ID);
        $cat     = !empty($cats) ? $cats[0]->name : '';
        $tags    = get_the_tags($post->ID);
        $tag     = (!empty($tags) && !is_wp_error($tags)) ? $tags[0]->name : '';

        $map = array(
            '%title%'           => $post->post_title,
            '%sep%'             => $sep,
            '%sitename%'        => get_bloginfo('name'),
            '%sitedesc%'        => get_bloginfo('description'),
            '%author%'          => get_the_author_meta('display_name', $author_id),
            '%post_author%'     => get_the_author_meta('display_name', $author_id),
            '%firstname%'       => get_the_author_meta('first_name', $author_id),
            '%lastname%'        => get_the_author_meta('last_name', $author_id),
            '%category%'        => $cat,
            '%tag%'             => $tag,
            '%date%'            => get_the_date('', $post),
            '%modified%'        => get_the_modified_date('', $post),
            '%currentyear%'     => date('Y'),
            '%currentmonth%'    => date('F'),
            '%currentday%'      => date('j'),
            '%excerpt%'         => wp_trim_words($post->post_excerpt ?: $post->post_content, 30),
        );

        $description = str_ireplace(array_keys($map), array_values($map), $raw);
        // Strip any leftover unrecognised %variables%.
        $description = preg_replace('/%[a-z_]+%/i', '', $description);
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s{2,}/', ' ', $description));
    }

    /**
     * Read and resolve the AIOSEO meta description for a post.
     *
     * Approach (fastest → most fallback):
     * 1. AIOSEO's own PHP API  — aioseo()->meta->description->getDescription($post)
     * 2. Per-post DB row       — aioseo_posts.description (may be a #tag template)
     * 3. Global post-type default from aioseo_options.searchAppearance.postTypes
     * All #tag placeholders are then resolved to their real values.
     */
    private function get_aioseo_description(int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        // ── APPROACH 1: AIOSEO's own PHP API ─────────────────────────────────
        if (function_exists('aioseo')) {
            $aioseo = aioseo();
            if (
                isset($aioseo->meta)
                && isset($aioseo->meta->description)
                && method_exists($aioseo->meta->description, 'getDescription')
            ) {
                $api_description = $aioseo->meta->description->getDescription($post);
                if (!empty($api_description)) {
                    return html_entity_decode(
                        (string) $api_description,
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    );
                }
            }
        }

        // ── APPROACH 2 & 3: DB read + tag resolution (fallback) ───────────────
        global $wpdb;

        // 2a. Per-post override in the aioseo_posts custom table.
        $table = $wpdb->prefix . 'aioseo_posts';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT description FROM `{$table}` WHERE post_id = %d", $post_id)
        );
        $raw = ($row && !empty($row->description))
            ? html_entity_decode((string) $row->description, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';

        // 2b. Global post-type meta description template from aioseo_options.
        //     AIOSEO v4 stores this at:
        //     aioseo_options → searchAppearance → postTypes → {post_type} → metaDescription
        if ('' === $raw) {
            $opts = get_option('aioseo_options', '');
            if ($opts) {
                $opts = is_string($opts) ? json_decode($opts, true) : $opts;
                $post_type = $post->post_type;
                $raw = $opts['searchAppearance']['postTypes'][$post_type]['metaDescription']
                    ?? $opts['searchAppearance']['postTypes']['post']['metaDescription']
                    ?? '';
                if (is_string($raw)) {
                    $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        if ('' === $raw) {
            return '';
        }

        return $this->resolve_aioseo_tags((string) $raw, $post);
    }

    /**
     * Replace every AIOSEO #tag placeholder with its actual value.
     *
     * Covers all tags visible in the AIOSEO Page Description UI.
     * Any unrecognised tags are stripped so they don't leak into the output.
     * Plain-text descriptions (no #tags at all) pass through unchanged.
     */
    private function resolve_aioseo_tags(string $raw, \WP_Post $post): string
    {
        // Quick exit: if there are no #tags at all, return the plain text as-is.
        if (false === strpos($raw, '#')) {
            return trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $site_title = get_bloginfo('name');
        $tagline    = get_bloginfo('description');
        $sep        = '-';

        // Read the configured separator from AIOSEO options (stored as an HTML entity).
        $aioseo_opts = get_option('aioseo_options', '');
        if ($aioseo_opts) {
            $opts = is_string($aioseo_opts) ? json_decode($aioseo_opts, true) : $aioseo_opts;
            if (!empty($opts['searchAppearance']['global']['separator'])) {
                $sep = html_entity_decode(
                    (string) $opts['searchAppearance']['global']['separator'],
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            }
        }

        // Author data.
        $author_id         = (int) $post->post_author;
        $author_first      = get_the_author_meta('first_name', $author_id);
        $author_last       = get_the_author_meta('last_name', $author_id);
        $author_display    = get_the_author_meta('display_name', $author_id);
        $author_nice       = get_the_author_meta('user_nicename', $author_id);

        // Primary category (first term in category taxonomy).
        $categories  = get_the_category($post->ID);
        $category    = !empty($categories) ? $categories[0]->name : '';

        // First tag.
        $tags     = get_the_tags($post->ID);
        $first_tag = (!empty($tags) && !is_wp_error($tags)) ? $tags[0]->name : '';

        // Dates.
        $post_date     = get_the_date('', $post);
        $modified_date = get_the_modified_date('', $post);

        $replacements = array(
            // ── Post title (all known aliases) ─────────────────────
            '#post_title'            => $post->post_title,
            '#page_title'            => $post->post_title,
            '#title'                 => $post->post_title,

            // ── Site ───────────────────────────────────────────────
            '#site_title'            => $site_title,
            '#blog_name'             => $site_title,
            '#site_description'      => $tagline,
            '#tagline'               => $tagline,

            // ── Separator ──────────────────────────────────────────
            '#separator_sa'          => $sep,

            // ── Author (both prefixed and short forms) ─────────────
            '#post_author_first_name' => $author_first,
            '#author_first_name'     => $author_first,
            '#post_author_last_name' => $author_last,
            '#author_last_name'      => $author_last,
            '#post_author'           => $author_display,
            '#author_name'           => $author_display,
            '#author'                => $author_display,
            '#post_author_login'     => $author_nice,
            '#author_login'          => $author_nice,

            // ── Taxonomy ───────────────────────────────────────────
            '#post_category'         => $category,
            '#category_title'        => $category,
            '#taxonomy_title'        => $category,
            '#post_tag'              => $first_tag,
            '#tag_title'             => $first_tag,

            // ── Dates ──────────────────────────────────────────────
            '#post_date'             => $post_date,
            '#date'                  => $post_date,
            '#post_modified_date'    => $modified_date,
            '#modified_date'         => $modified_date,
            '#current_year'          => date('Y'),
            '#year'                  => date('Y'),
            '#current_month'         => date('F'),
            '#month'                 => date('F'),
            '#current_day'           => date('j'),
            '#day'                   => date('j'),

            // ── Misc ───────────────────────────────────────────────
            '#search_term'           => '',   // empty on non-search pages
        );

        $description = str_ireplace(
            array_keys($replacements),
            array_values($replacements),
            $raw
        );

        // Strip any leftover unrecognised #tags.
        $description = preg_replace('/#[a-z_]+/i', '', $description);

        // Decode HTML entities and collapse extra whitespace.
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s{2,}/', ' ', $description));
    }

    /**
     * Get the stored meta description for a post in native WordPress mode (no SEO plugin).
     *
     * We return our custom _seoautofix_description post meta if set,
     * otherwise fall back to post_excerpt (WordPress's closest native equivalent).
     */
    private function get_native_description(int $post_id): string
    {
        $post_obj = get_post($post_id);
        if (!$post_obj) {
            return '';
        }

        // Check for a custom description set by our plugin's Apply action.
        $custom = get_post_meta($post_id, '_seoautofix_description', true);
        if ('' !== (string) $custom) {
            return html_entity_decode((string) $custom, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // No custom description applied yet — fall back to the raw post_excerpt.
        return html_entity_decode(
            (string) $post_obj->post_excerpt,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * Classify a description string.
     * $seen_map is passed by reference to track duplicates across the batch.
     */
    public function classify(string $description, array &$seen_map, array $frequency_map = array()): string
    {
        $trimmed = trim($description);
        if ('' === $trimmed) {
            return self::ISSUE_MISSING;
        }

        $norm = preg_replace('/\s+/', ' ', strtolower($trimmed));

        // Priority 1: Duplicate (More critical than length)
        // Check pre-calculated frequency if available, else fallback to seen_map
        if (!empty($frequency_map)) {
            if (($frequency_map[$norm] ?? 0) > 1) {
                return self::ISSUE_DUPLICATE;
            }
        } elseif (isset($seen_map[$norm])) {
            return self::ISSUE_DUPLICATE;
        }
        $seen_map[$norm] = true;

        // Priority 2: Length
        $len = mb_strlen($trimmed);
        if ($len < self::MIN_LEN) {
            return self::ISSUE_SHORT;
        }
        if ($len > self::MAX_LEN) {
            return self::ISSUE_LONG;
        }

        return self::ISSUE_OK;
    }

    /**
     * Scan a paginated batch of posts/pages.
     *
     * @return array[]  Each item: post_id, post_type, post_title, post_url,
     *                  edit_url, current_seo_description, rendered_description,
     *                  char_count, issue_type, seo_plugin
     */
    public function scan_batch(int $batch_size, int $offset, string $post_type = 'any', string $issue_filter = 'all'): array
    {
        \SEOAutoFix_Debug_Logger::log("[METADESC SCANNER] scan_batch offset={$offset} filter={$issue_filter}", 'meta-desc');

        $types = ('any' === $post_type) ? array('post', 'page') : array($post_type);

        $query = new \WP_Query(array(
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ));

        $seen_map = array();
        $frequency_map = array();
        $pre_scan = array();
        $results = array();

        // Pass 1: Gather descriptions and calculate frequencies
        foreach ($query->posts as $post) {
            $seo_description = $this->get_seo_description($post->ID);
            $rendered_description = ('' !== trim($seo_description)) ? $seo_description : $post->post_excerpt;
            $norm = preg_replace('/\s+/', ' ', strtolower(trim($rendered_description)));

            $pre_scan[$post->ID] = array(
                'seo_description'      => $seo_description,
                'rendered_description' => $rendered_description,
                'norm'                 => $norm,
            );

            if ('' !== $norm) {
                $frequency_map[$norm] = ($frequency_map[$norm] ?? 0) + 1;
            }
        }

        // Pass 2: Classify and filter
        foreach ($query->posts as $post) {
            $data = $pre_scan[$post->ID];
            $issue = $this->classify($data['rendered_description'], $seen_map, $frequency_map);

            if ('all' !== $issue_filter && $issue !== $issue_filter) {
                continue;
            }

            $results[] = array(
                'post_id'              => (int) $post->ID,
                'post_type'            => $post->post_type,
                'post_title'           => html_entity_decode((string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'post_url'             => get_permalink($post->ID),
                'edit_url'             => get_edit_post_link($post->ID, 'raw'),
                'current_seo_description' => $data['seo_description'],
                'rendered_description' => $data['rendered_description'],
                'char_count'           => mb_strlen($data['rendered_description']),
                'issue_type'           => $issue,
                'seo_plugin'           => $this->detect_seo_plugin(),
            );
        }

        return $results;
    }

    /**
     * Full-site stats (all issues).
     */
    public function get_stats(string $post_type = 'any', string $current_filter = 'all'): array
    {
        // Get all posts (no batching for stats — capped at 5000)
        $all = $this->scan_batch(5000, 0, $post_type, 'all');

        $stats = array(
            'total'     => count($all),
            'missing'   => 0,
            'too_short' => 0,
            'too_long'  => 0,
            'duplicate' => 0,
            'ok'        => 0,
        );

        foreach ($all as $row) {
            if (isset($stats[$row['issue_type']])) {
                $stats[$row['issue_type']]++;
            }
        }

        // Derived
        $stats['with_descriptions']    = $stats['ok'] + $stats['too_short'] + $stats['too_long'] + $stats['duplicate'];
        $stats['without_descriptions'] = $stats['missing'];

        \SEOAutoFix_Debug_Logger::log('[METADESC SCANNER] Stats: ' . json_encode($stats), 'meta-desc');
        return $stats;
    }
}
