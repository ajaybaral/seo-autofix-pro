<?php
/**
 * Title Tag Optimization — Scan Engine
 *
 * @package SEO_AutoFix_Pro
 * @subpackage TitleTagOptimization
 */

namespace SEOAutoFix\TitleTagOptimization;

if (!defined('ABSPATH')) {
    exit;
}

class Title_Scanner
{

    const MIN_LEN = 30;
    const MAX_LEN = 60;

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

        \SEOAutoFix_Debug_Logger::log('[TITLETAG SCANNER] SEO plugin detected: ' . $this->seo_plugin, 'title-tag');
        return $this->seo_plugin;
    }

    /**
     * Get SEO meta title for a post.
     */
    public function get_seo_title(int $post_id): string
    {
        switch ($this->detect_seo_plugin()) {
            case 'yoast':
                return (string) get_post_meta($post_id, '_yoast_wpseo_title', true);
            case 'rankmath':
                return (string) get_post_meta($post_id, 'rank_math_title', true);
            case 'aioseo':
                return $this->get_aioseo_title($post_id);
            default:
                return $this->get_native_document_title($post_id);
        }
    }

    /**
     * Fetch and render the AIOSEO title for a post.
     *
     * AIOSEO stores per-post data in the custom `aioseo_posts` table.
     * The title column may contain template tags like #post_title,
     * #site_title, #separator_sa — we resolve the common ones here.
     * If the post has no custom AIOSEO title, we fall back to the
     * AIOSEO default title format (stored in the plugin options).
     */
    private function get_aioseo_title(int $post_id): string
    {
        global $wpdb;

        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        // 1. Try the aioseo_posts custom table (primary storage).
        $table = $wpdb->prefix . 'aioseo_posts';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT title FROM `{$table}` WHERE post_id = %d", $post_id)
        );

        // Decode HTML entities — AIOSEO sometimes stores titles with encoded characters.
        $raw = ($row && !empty($row->title)) ? html_entity_decode((string) $row->title, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';

        // 2. If the post has no individual override, read the global default format.
        if ('' === $raw) {
            $defaults = get_option('aioseo_titles', array());
            if (is_string($defaults)) {
                $defaults = json_decode($defaults, true) ?: array();
            }
            $post_type = $post->post_type;
            // AIOSEO nests defaults under postTypes->{postType}->title
            $raw = $defaults['postTypes'][$post_type]['title']
                ?? $defaults['postTypes']['post']['title']
                ?? '';
        }

        if ('' === $raw) {
            // Nothing configured — return empty so the scanner marks it missing.
            return '';
        }

        // 3. Resolve common AIOSEO template tags.
        return $this->resolve_aioseo_tags($raw, $post);
    }

    /**
     * Replace AIOSEO template tags in a raw title string.
     *
     * Only the most common subset is handled here; edge-case tags
     * (breadcrumbs, taxonomies, etc.) are left as-is.
     */
    private function resolve_aioseo_tags(string $raw, \WP_Post $post): string
    {
        $site_title = get_bloginfo('name');
        $tagline = get_bloginfo('description');
        $sep = '-';

        // Attempt to read the separator from AIOSEO options.
        // AIOSEO stores separators as HTML entities (e.g. &#8211;) — decode them.
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

        $replacements = array(
            // Post-level
            '#post_title' => $post->post_title,
            '#page_title' => $post->post_title,
            '#title' => $post->post_title,
            // Site-level
            '#site_title' => $site_title,
            '#blog_name' => $site_title,
            '#site_description' => $tagline,
            '#tagline' => $tagline,
            // Separator
            '#separator_sa' => $sep,
            // Date helpers
            '#current_year' => date('Y'),
            '#current_month' => date('F'),
            '#current_day' => date('j'),
            // Post author
            '#post_author_first_name' => get_the_author_meta('first_name', $post->post_author),
            '#post_author_last_name' => get_the_author_meta('last_name', $post->post_author),
            '#author_name' => get_the_author_meta('display_name', $post->post_author),
        );

        $title = str_ireplace(
            array_keys($replacements),
            array_values($replacements),
            $raw
        );

        // Strip any remaining unresolved tags (#something).
        $title = preg_replace('/#\w+/', '', $title);

        // Decode any HTML entities that AIOSEO may have embedded in the stored title.
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s{2,}/', ' ', $title));
    }

    /**
     * Get the stored post_title for a post in native WordPress mode (no SEO plugin).
     *
     * We return post_title directly — NOT wp_get_document_title() — because:
     *  1. wp_get_document_title() returns "Post Title – Site Name" (site name included).
     *     If that full string is later applied back, the title becomes doubled.
     *  2. post_title IS the "SEO title" we are managing; WordPress appends the site
     *     name automatically via its document_title_parts filter.
     *  3. Avoids manipulating $wp_query / $post globals inside an AJAX request,
     *     which can leave the global state inconsistent if anything throws.
     */
    private function get_native_document_title(int $post_id): string
    {
        $post_obj = get_post($post_id);
        if (!$post_obj) {
            return '';
        }

        // Check for a custom title set by our plugin's Apply action.
        // This is the same pattern Yoast uses (_yoast_wpseo_title meta).
        $custom = get_post_meta($post_id, '_seoautofix_title', true);
        if ('' !== (string) $custom) {
            return html_entity_decode((string) $custom, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // No custom title applied yet — fall back to the raw post_title.
        // (WordPress will append the site name on the frontend automatically.)
        return html_entity_decode(
            (string) $post_obj->post_title,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * Classify a title string.
     * $seen_map is passed by reference to track duplicates across the batch.
     */
    public function classify(string $title, array &$seen_map, array $frequency_map = array()): string
    {
        $trimmed = trim($title);
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
     *                  edit_url, current_seo_title, rendered_title,
     *                  char_count, issue_type, seo_plugin
     */
    public function scan_batch(int $batch_size, int $offset, string $post_type = 'any', string $issue_filter = 'all'): array
    {
        \SEOAutoFix_Debug_Logger::log("[TITLETAG SCANNER] scan_batch offset={$offset} filter={$issue_filter}", 'title-tag');

        $types = ('any' === $post_type) ? array('post', 'page') : array($post_type);

        $query = new \WP_Query(array(
            'post_type' => $types,
            'post_status' => 'any',
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

        // Pass 1: Gather titles and calculate frequencies
        foreach ($query->posts as $post) {
            $seo_title = $this->get_seo_title($post->ID);
            $rendered_title = ('' !== trim($seo_title)) ? $seo_title : $post->post_title;
            $norm = preg_replace('/\s+/', ' ', strtolower(trim($rendered_title)));

            $pre_scan[$post->ID] = array(
                'seo_title' => $seo_title,
                'rendered_title' => $rendered_title,
                'norm' => $norm,
            );

            if ('' !== $norm) {
                $frequency_map[$norm] = ($frequency_map[$norm] ?? 0) + 1;
            }
        }

        // Pass 2: Classify and filter
        foreach ($query->posts as $post) {
            $data = $pre_scan[$post->ID];
            $issue = $this->classify($data['rendered_title'], $seen_map, $frequency_map);

            if ('all' !== $issue_filter && $issue !== $issue_filter) {
                continue;
            }

            $results[] = array(
                'post_id' => (int) $post->ID,
                'post_type' => $post->post_type,
                'post_title' => html_entity_decode((string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'post_url' => get_permalink($post->ID),
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
                'current_seo_title' => $data['seo_title'],
                'rendered_title' => $data['rendered_title'],
                'char_count' => mb_strlen($data['rendered_title']),
                'issue_type' => $issue,
                'seo_plugin' => $this->detect_seo_plugin(),
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
            'total' => count($all),
            'missing' => 0,
            'too_short' => 0,
            'too_long' => 0,
            'duplicate' => 0,
            'ok' => 0,
        );

        foreach ($all as $row) {
            if (isset($stats[$row['issue_type']])) {
                $stats[$row['issue_type']]++;
            }
        }

        // Derived
        $stats['with_titles'] = $stats['ok'] + $stats['too_short'] + $stats['too_long'] + $stats['duplicate'];
        $stats['without_titles'] = $stats['missing'];

        \SEOAutoFix_Debug_Logger::log('[TITLETAG SCANNER] Stats: ' . json_encode($stats), 'title-tag');
        return $stats;
    }
}
