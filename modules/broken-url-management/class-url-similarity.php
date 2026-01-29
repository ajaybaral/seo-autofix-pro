<?php
/**
 * URL Similarity - Calculates similarity between URLs
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL Similarity Class
 */
class URL_Similarity
{

    /**
     * Minimum similarity score threshold (0-100)
     * Only suggest URLs with similarity score above this threshold
     * Otherwise, fallback to homepage
     */
    const MIN_SCORE_THRESHOLD = 30; // Lowered to allow more fuzzy matches for typos/formatting

    /**
     * Scoring weights
     * Prioritize slug similarity for better text-wise matching
     */
    const WEIGHT_SEGMENT_OVERLAP = 35;
    const WEIGHT_SLUG_SIMILARITY = 45;
    const WEIGHT_STRUCTURE_SIMILARITY = 20;

    /**
     * Find closest matching URL
     * 
     * @param string $broken_url Broken URL
     * @param array $valid_urls Array of valid URLs
     * @return array Match result with url and reason
     */
    public function find_closest_match($broken_url, $valid_urls)
    {
        if (empty($valid_urls)) {
            return $this->get_homepage_fallback();
        }

        $best_match = null;
        $best_score = 0;

        // Extract broken URL components
        $broken_segments = $this->extract_path_segments($broken_url);
        $broken_slug = $this->extract_slug($broken_url);

        foreach ($valid_urls as $valid_url) {
            $score = 0;

            // Extract valid URL components
            $valid_segments = $this->extract_path_segments($valid_url);
            $valid_slug = $this->extract_slug($valid_url);

            // Calculate segment overlap score
            $segment_score = $this->calculate_segment_overlap($broken_segments, $valid_segments);
            $score += $segment_score * (self::WEIGHT_SEGMENT_OVERLAP / 100);

            // Calculate slug similarity score
            $slug_score = $this->calculate_slug_similarity($broken_slug, $valid_slug);
            $score += $slug_score * (self::WEIGHT_SLUG_SIMILARITY / 100);

            // Calculate structure similarity score
            $structure_score = $this->calculate_structure_similarity($broken_url, $valid_url);
            $score += $structure_score * (self::WEIGHT_STRUCTURE_SIMILARITY / 100);

            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $valid_url;
            }
        }

        // If best score is below threshold, return homepage
        if ($best_score < self::MIN_SCORE_THRESHOLD) {
            return $this->get_homepage_fallback();
        }

        return array(
            'url' => $best_match,
            'reason' => __('This is the closest relevant link we found', 'seo-autofix-pro'),
            'score' => round($best_score, 2)
        );
    }

    /**
     * Extract path segments from URL
     * 
     * @param string $url URL
     * @return array Path segments
     */
    private function extract_path_segments($url)
    {
        $parsed = parse_url($url);
        $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';

        if (empty($path)) {
            return array();
        }

        return array_filter(explode('/', $path));
    }

    /**
     * Extract slug (last segment) from URL
     * 
     * @param string $url URL
     * @return string Slug
     */
    private function extract_slug($url)
    {
        $segments = $this->extract_path_segments($url);

        if (empty($segments)) {
            return '';
        }

        return end($segments);
    }

    /**
     * Calculate segment overlap score
     * 
     * @param array $segments1 First URL segments
     * @param array $segments2 Second URL segments
     * @return float Score (0-100)
     */
    private function calculate_segment_overlap($segments1, $segments2)
    {
        if (empty($segments1) || empty($segments2)) {
            return 0;
        }

        // Count matching segments
        $matches = count(array_intersect($segments1, $segments2));

        // Calculate overlap percentage based on the smaller array
        $min_length = min(count($segments1), count($segments2));

        if ($min_length === 0) {
            return 0;
        }

        return ($matches / $min_length) * 100;
    }

    /**
     * Calculate slug similarity using enhanced fuzzy matching
     * Handles common URL errors: missing slashes, typos, separator variations
     * 
     * @param string $slug1 First slug
     * @param string $slug2 Second slug
     * @return float Score (0-100)
     */
    private function calculate_slug_similarity($slug1, $slug2)
    {
        if (empty($slug1) || empty($slug2)) {
            return 0;
        }

        // Convert to lowercase for case-insensitive comparison
        $slug1 = strtolower($slug1);
        $slug2 = strtolower($slug2);

        // Create normalized versions (remove all separators for fuzzy matching)
        $normalized1 = preg_replace('/[-_\s]+/', '', $slug1);
        $normalized2 = preg_replace('/[-_\s]+/', '', $slug2);

        // Check for exact match after normalization (handles separator variations)
        if ($normalized1 === $normalized2) {
            return 100;
        }

        // Check substring containment (e.g., "aboutme" contains in "about-me")
        $substring_score = 0;
        if (strlen($normalized1) > 0 && strlen($normalized2) > 0) {
            if (strpos($normalized2, $normalized1) !== false) {
                // slug1 is contained in slug2
                $substring_score = (strlen($normalized1) / strlen($normalized2)) * 100;
            } elseif (strpos($normalized1, $normalized2) !== false) {
                // slug2 is contained in slug1
                $substring_score = (strlen($normalized2) / strlen($normalized1)) * 100;
            }
        }

        // Calculate normalized Levenshtein similarity (handles typos)
        $levenshtein_normalized = $this->levenshtein_similarity($normalized1, $normalized2);

        // Remove common separators and split into words
        $words1 = preg_split('/[-_\s]+/', $slug1);
        $words2 = preg_split('/[-_\s]+/', $slug2);

        // Remove common stop words
        $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for');
        $words1 = array_diff($words1, $stop_words);
        $words2 = array_diff($words2, $stop_words);

        // Calculate word overlap score
        $word_overlap_score = 0;
        if (!empty($words1) && !empty($words2)) {
            $common_words = count(array_intersect($words1, $words2));
            $total_words = max(count($words1), count($words2));
            $word_overlap_score = ($common_words / $total_words) * 100;
        }

        // Also calculate Levenshtein distance for original slugs
        $levenshtein_original = $this->levenshtein_similarity($slug1, $slug2);

        // Combine scores with intelligent weighting
        // Prioritize substring matches, then normalized similarity, then word overlap
        $final_score = max(
            $substring_score * 0.9, // High weight for substring matches
            ($levenshtein_normalized * 0.4) + ($word_overlap_score * 0.3) + ($levenshtein_original * 0.3)
        );

        return $final_score;
    }

    /**
     * Calculate similarity using Levenshtein distance
     * 
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Score (0-100)
     */
    private function levenshtein_similarity($str1, $str2)
    {
        $max_length = max(strlen($str1), strlen($str2));

        if ($max_length === 0) {
            return 100;
        }

        $distance = levenshtein($str1, $str2);

        return (1 - ($distance / $max_length)) * 100;
    }

    /**
     * Calculate structure similarity
     * 
     * @param string $url1 First URL
     * @param string $url2 Second URL
     * @return float Score (0-100)
     */
    private function calculate_structure_similarity($url1, $url2)
    {
        $segments1 = $this->extract_path_segments($url1);
        $segments2 = $this->extract_path_segments($url2);

        $depth1 = count($segments1);
        $depth2 = count($segments2);

        if ($depth1 === 0 && $depth2 === 0) {
            return 100;
        }

        $max_depth = max($depth1, $depth2);
        $depth_diff = abs($depth1 - $depth2);

        // URLs with similar depth get higher scores
        return (1 - ($depth_diff / $max_depth)) * 100;
    }

    /**
     * Get homepage fallback
     * 
     * @return array Homepage result
     */
    private function get_homepage_fallback()
    {
        return array(
            'url' => home_url('/'), // Suggest homepage when no relevant page found
            'reason' => __('No highly relevant page found. Suggesting homepage as fallback.', 'seo-autofix-pro'),
            'score' => 0
        );
    }

    /**
     * Check if URL is internal
     * 
     * @param string $url URL to check
     * @return bool True if internal
     */
    public function is_internal_url($url)
    {
        $site_url = get_site_url();
        $home_url = get_home_url();

        // Check if URL starts with site URL or home URL
        if (strpos($url, $site_url) === 0 || strpos($url, $home_url) === 0) {
            return true;
        }

        // Check if it's a relative URL (starts with /)
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return true;
        }

        // Check if it's a query string or anchor (always relative to current page)
        if (strpos($url, '?') === 0 || strpos($url, '#') === 0) {
            return true;
        }

        return false;
    }
}
