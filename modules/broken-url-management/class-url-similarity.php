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
    const MIN_SCORE_THRESHOLD = 60; // Updated to 60% for higher quality suggestions

    /**
     * Scoring weights for anchor text matching
     */
    const WEIGHT_ANCHOR_TEXT = 70;
    const WEIGHT_URL_SLUG = 30;

    /**
     * Scoring weights for URL-only matching
     * Prioritize slug similarity for better text-wise matching
     */
    const WEIGHT_SEGMENT_OVERLAP = 35;
    const WEIGHT_SLUG_SIMILARITY = 45;
    const WEIGHT_STRUCTURE_SIMILARITY = 20;

    /**
     * Find closest matching URL using 3-path intelligent algorithm
     * 
     * @param string $broken_url Broken URL
     * @param array $valid_urls Array of valid URLs
     * @param string $anchor_text Anchor text (if available)
     * @param array $valid_urls_with_titles Associative array mapping URLs to page titles
     * @return array Match result with url and reason
     */
    public function find_closest_match($broken_url, $valid_urls, $anchor_text = '', $valid_urls_with_titles = array())
    {
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ========== FIND_CLOSEST_MATCH START ==========');
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Broken URL: ' . $broken_url);
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Anchor text: ' . ($anchor_text ? $anchor_text : '[NONE]'));
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Valid URLs count: ' . count($valid_urls));

        if (empty($valid_urls)) {
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ❌ No valid URLs provided - returning homepage fallback');
            return $this->get_homepage_fallback();
        }

        // 🎯 PATH 1: IMAGE-TYPE BROKEN LINKS
        if ($this->is_image_url($broken_url)) {
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION] 🖼️ PATH 1: Image-type broken link detected');
            
            // Filter valid URLs to only images
            $image_urls = array_filter($valid_urls, function($url) {
                return $this->is_image_url($url);
            });
            
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Found ' . count($image_urls) . ' image URLs to compare');
            
            if (empty($image_urls)) {
                \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ❌ No image URLs available - no suggestion');
                return array('url' => null, 'reason' => '', 'score' => 0);
            }
            
            // Match by filename similarity (ignore extension)
            return $this->match_image_by_filename($broken_url, $image_urls);
        }

        // 🎯 PATH 2: NAKED LINK (No Anchor Text)
        if ($this->is_generic_anchor($anchor_text)) {
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION] 🔗 PATH 2: Naked link (no meaningful anchor text)');
            return $this->match_by_url_only($broken_url, $valid_urls);
        }

        // 🎯 PATH 3: ANCHOR TEXT EXISTS (Weighted Matching)
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] 📝 PATH 3: Anchor text exists - using weighted matching');
        
        // Step A: Primary weighted matching (anchor text + URL)
        $weighted_match = $this->match_by_anchor_and_url($broken_url, $anchor_text, $valid_urls_with_titles);
        
        if ($weighted_match['score'] >= self::MIN_SCORE_THRESHOLD) {
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ✅ Weighted match succeeded with score: ' . $weighted_match['score']);
            return $weighted_match;
        }
        
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ⚠️ Weighted match failed (score: ' . $weighted_match['score'] . ' < ' . self::MIN_SCORE_THRESHOLD . ')');
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] 🔄 Falling back to URL-only matching...');
        
        // Step B: Fallback to URL-only matching
        return $this->match_by_url_only($broken_url, $valid_urls);
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

    /**
     * Check if URL is an image
     * 
     * @param string $url URL to check
     * @return bool True if image URL
     */
    private function is_image_url($url)
    {
        $image_extensions = array('jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp', 'ico');
        $path = parse_url($url, PHP_URL_PATH);
        
        if (!$path) {
            return false;
        }
        
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, $image_extensions);
    }

    /**
     * Check if anchor text is generic/meaningless
     * 
     * @param string $anchor_text Anchor text to check
     * @return bool True if generic
     */
    private function is_generic_anchor($anchor_text)
    {
        if (empty($anchor_text)) {
            return true;
        }
        
        $generic_phrases = array(
            '[No text]',
            '[Image]',
            '[Elementor Link]',
            'Click here',
            'Read more',
            'Learn more',
            'More',
            'Here'
        );
        
        $trimmed = trim($anchor_text);
        
        // Check if it's a generic phrase
        foreach ($generic_phrases as $phrase) {
            if (strcasecmp($trimmed, $phrase) === 0) {
                return true;
            }
        }
        
        // Check if it starts with "Image:"
        if (stripos($trimmed, 'Image:') === 0) {
            return true;
        }
        
        return false;
    }

    /**
     * Match image by filename similarity
     * 
     * @param string $broken_url Broken image URL
     * @param array $image_urls Valid image URLs
     * @return array Match result
     */
    private function match_image_by_filename($broken_url, $image_urls)
    {
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Matching image by filename...');
        
        // Extract filename without extension from broken URL
        $broken_path = parse_url($broken_url, PHP_URL_PATH);
        $broken_filename = pathinfo($broken_path, PATHINFO_FILENAME);
        
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Broken image filename: ' . $broken_filename);
        
        $best_match = null;
        $best_score = 0;
        
        foreach ($image_urls as $image_url) {
            $image_path = parse_url($image_url, PHP_URL_PATH);
            $image_filename = pathinfo($image_path, PATHINFO_FILENAME);
            
            // Calculate filename similarity (ignore extension)
            $score = $this->calculate_slug_similarity($broken_filename, $image_filename);
            
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Comparing with: ' . $image_filename . ' - Score: ' . round($score, 2));
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $image_url;
            }
        }
        
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Best match score: ' . round($best_score, 2) . ' (threshold: ' . self::MIN_SCORE_THRESHOLD . ')');
        
        if ($best_score >= self::MIN_SCORE_THRESHOLD) {
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ✅ Image match found: ' . $best_match);
            return array(
                'url' => $best_match,
                'reason' => __('Similar image filename found', 'seo-autofix-pro'),
                'score' => round($best_score, 2)
            );
        }
        
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ❌ No image match above threshold');
        return array('url' => null, 'reason' => '', 'score' => round($best_score, 2));
    }

    /**
     * Match by URL only (naked link path)
     * 
     * @param string $broken_url Broken URL
     * @param array $valid_urls Valid URLs
     * @return array Match result
     */
    private function match_by_url_only($broken_url, $valid_urls)
    {
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Matching by URL slug similarity only...');
        
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

        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] URL-only best score: ' . round($best_score, 2) . ' (threshold: ' . self::MIN_SCORE_THRESHOLD . ')');

        // If best score is below threshold, return no suggestion
        if ($best_score < self::MIN_SCORE_THRESHOLD) {
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ❌ No URL match above threshold');
            return array('url' => null, 'reason' => '', 'score' => round($best_score, 2));
        }

        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ✅ URL match found: ' . $best_match);
        return array(
            'url' => $best_match,
            'reason' => __('This is the closest relevant link we found', 'seo-autofix-pro'),
            'score' => round($best_score, 2)
        );
    }

    /**
     * Match by anchor text and URL (weighted)
     * 
     * @param string $broken_url Broken URL
     * @param string $anchor_text Anchor text
     * @param array $valid_urls_with_titles URLs mapped to titles
     * @return array Match result
     */
    private function match_by_anchor_and_url($broken_url, $anchor_text, $valid_urls_with_titles)
    {
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Weighted matching: anchor text + URL...');
        
        $best_match = null;
        $best_score = 0;
        
        // Extract broken URL slug for comparison
        $broken_slug = $this->extract_slug($broken_url);
        
        foreach ($valid_urls_with_titles as $valid_url => $page_title) {
            // Calculate anchor text similarity (compare anchor with page title)
            $anchor_score = $this->calculate_anchor_similarity($anchor_text, $page_title);
            
            // Calculate URL slug similarity  
            $valid_slug = $this->extract_slug($valid_url);
            $url_score = $this->calculate_slug_similarity($broken_slug, $valid_slug);
            
            // Weighted combination: 70% anchor + 30% URL
            $weighted_score = ($anchor_score * self::WEIGHT_ANCHOR_TEXT / 100) + 
                            ($url_score * self::WEIGHT_URL_SLUG / 100);
            
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Checking: ' . $page_title . ' | Anchor: ' . round($anchor_score, 2) . ', URL: ' . round($url_score, 2) . ', Weighted: ' . round($weighted_score, 2));
            
            if ($weighted_score > $best_score) {
                $best_score = $weighted_score;
                $best_match = $valid_url;
            }
        }
        
        \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Weighted best score: ' . round($best_score, 2));
        
        return array(
            'url' => $best_match,
            'reason' => __('This page matches your link text', 'seo-autofix-pro'),
            'score' => round($best_score, 2)
        );
    }

    /**
     * Calculate similarity between anchor text and page title
     * 
     * @param string $anchor_text Anchor text
     * @param string $page_title Page title
     * @return float Similarity score (0-100)
     */
    private function calculate_anchor_similarity($anchor_text, $page_title)
    {
        if (empty($anchor_text) || empty($page_title)) {
            return 0;
        }
        
        // Normalize both strings
        $anchor_normalized = strtolower(trim($anchor_text));
        $title_normalized = strtolower(trim($page_title));
        
        // Remove common stop words
        $stop_words = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with');
        
        $anchor_words = array_filter(preg_split('/[\s\-_]+/', $anchor_normalized), function($word) use ($stop_words) {
            return !in_array($word, $stop_words) && strlen($word) > 2;
        });
        
        $title_words = array_filter(preg_split('/[\s\-_]+/', $title_normalized), function($word) use ($stop_words) {
            return !in_array($word, $stop_words) && strlen($word) > 2;
        });
        
        if (empty($anchor_words) || empty($title_words)) {
            // Fallback to levenshtein if word splitting fails
            return $this->levenshtein_similarity($anchor_normalized, $title_normalized);
        }
        
        // Calculate word overlap
        $common_words = count(array_intersect($anchor_words, $title_words));
        $total_words = max(count($anchor_words), count($title_words));
        $word_overlap_score = ($common_words / $total_words) * 100;
        
        // Also calculate string similarity
        $string_similarity = $this->levenshtein_similarity($anchor_normalized, $title_normalized);
        
        // Combine: prioritize word overlap but consider string similarity
        $final_score = ($word_overlap_score * 0.7) + ($string_similarity * 0.3);
        
        return $final_score;
    }
}
