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
                return (string) get_post_meta($post_id, '_aioseo_title', true);
            default:
                return $this->get_native_document_title($post_id);
        }
    }

    /**
     * Get the actual rendered HTML <title> tag value for a post when no SEO
     * plugin is active. Temporarily fakes a singular query context so that
     * wp_get_document_title() returns the same string the theme would output.
     */
    private function get_native_document_title(int $post_id): string
    {
        $post_obj = get_post($post_id);
        if (!$post_obj) {
            return '';
        }

        // Save original global state
        global $wp_query, $post;
        $original_wp_query = $wp_query;
        $original_post     = $post;

        // Build a minimal fake query that looks like a singular post request
        $fake_query = new \WP_Query();
        $fake_query->is_singular       = true;
        $fake_query->is_single         = ('post' === $post_obj->post_type);
        $fake_query->is_page           = ('page' === $post_obj->post_type);
        $fake_query->queried_object    = $post_obj;
        $fake_query->queried_object_id = $post_obj->ID;

        $wp_query = $fake_query;
        $post     = $post_obj;
        setup_postdata($post_obj);

        $title = wp_get_document_title();

        // Restore original global state
        $wp_query = $original_wp_query;
        $post     = $original_post;
        if ($original_post) {
            setup_postdata($original_post);
        }

        return $title;
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
                'post_title' => $post->post_title,
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
