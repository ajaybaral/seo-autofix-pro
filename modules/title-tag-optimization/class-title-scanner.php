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
                $post = get_post($post_id);
                return $post ? (string) $post->post_title : '';
        }
    }

    /**
     * Classify a title string.
     * $seen_map is passed by reference to track duplicates across the batch.
     */
    public function classify(string $title, array &$seen_map): string
    {
        $trimmed = trim($title);
        if ('' === $trimmed) {
            return self::ISSUE_MISSING;
        }

        $len = mb_strlen($trimmed);
        if ($len < self::MIN_LEN) {
            return self::ISSUE_SHORT;
        }
        if ($len > self::MAX_LEN) {
            return self::ISSUE_LONG;
        }

        // Duplicate: normalise (trim + lowercase + collapse spaces)
        $norm = preg_replace('/\s+/', ' ', strtolower($trimmed));
        if (isset($seen_map[$norm])) {
            return self::ISSUE_DUPLICATE;
        }
        $seen_map[$norm] = true;

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
        $results = array();

        foreach ($query->posts as $post) {
            $seo_title = $this->get_seo_title($post->ID);
            $rendered_title = ('' !== trim($seo_title)) ? $seo_title : $post->post_title;
            $issue = $this->classify($rendered_title, $seen_map);

            if ('all' !== $issue_filter && $issue !== $issue_filter) {
                continue;
            }

            $results[] = array(
                'post_id' => (int) $post->ID,
                'post_type' => $post->post_type,
                'post_title' => $post->post_title,
                'post_url' => get_permalink($post->ID),
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
                'current_seo_title' => $seo_title,
                'rendered_title' => $rendered_title,
                'char_count' => mb_strlen($rendered_title),
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
