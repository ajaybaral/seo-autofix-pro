<?php
/**
 * Image SEO Module - SEO Scorer
 * 
 * Scores alt text quality using AI
 * 
 * @package SEO_AutoFix_Pro
 * @subpackage Image_SEO
 */

namespace SEOAutoFix\ImageSEO;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO Scorer Class
 */
class SEO_Scorer {
    
    /**
     * API Manager instance
     */
    private $api_manager;
    
    /**
     * Constructor
     *
     * @param API_Manager $api_manager The API manager instance
     */
    public function __construct($api_manager) {
        $this->api_manager = $api_manager;
    }
    
    /**
     * Score alt text quality
     *
     * @param string $alt_text The alt text to score
     * @param array $context Usage context
     * @return array Score data with score and reasoning
     * @throws \Exception If scoring fails
     */
    public function score_alt_text($alt_text, $context) {
        // If empty, return 0
        if (empty($alt_text)) {
            return array(
                'score' => 0,
                'reasoning' => 'Empty alt text'
            );
        }
        
        // Use basic local scoring
        return $this->basic_score($alt_text);
    }
    
    /**
     * Build scoring prompt
     *
     * @param string $alt_text The alt text to score
     * @param array $context Usage context
     * @return string The prompt
     */
    private function build_scoring_prompt($alt_text, $context) {
        $page_topic = 'general';
        
        if (!empty($context['pages'])) {
            $page_topic = $context['pages'][0]['title'];
        }
        
        $prompt = "Rate this alt text for SEO quality (0-100).\n\n";
        $prompt .= "Alt Text: \"" . $alt_text . "\"\n";
        $prompt .= "Page Topic: " . $page_topic . "\n\n";
        
        $prompt .= "Criteria:\n";
        $prompt .= "1. Length (30-60 chars optimal)\n";
        $prompt .= "2. Keyword relevance\n";
        $prompt .= "3. Descriptive quality\n";
        $prompt .= "4. Accessibility\n";
        $prompt .= "5. Natural language\n\n";
        
        $prompt .= "Return JSON only in this exact format:\n";
        $prompt .= '{"score": 85, "reasoning": "Brief explanation"}';
        
        return $prompt;
    }
    
    /**
     * Parse score response from API
     *
     * @param array $response The API response
     * @return array Parsed score data
     */
    public function parse_score_response($response) {
        if (!isset($response['score'])) {
            throw new \Exception('Invalid score response format');
        }
        
        return array(
            'score' => max(0, min(100, intval($response['score']))),
            'reasoning' => isset($response['reasoning']) ? $response['reasoning'] : ''
        );
    }
    
    /**
     * Basic score calculation (fallback when API fails)
     *
     * @param string $alt_text The alt text to score
     * @return array Score data
     */
    private function basic_score($alt_text) {
        $score = 50; // Start at middle
        $reasons = array();
        
        $length = strlen($alt_text);
        
        // Length scoring (30-60 chars is optimal)
        if ($length >= 30 && $length <= 60) {
            $score += 20;
            $reasons[] = 'Good length';
        } elseif ($length < 10) {
            $score -= 30;
            $reasons[] = 'Too short';
        } elseif ($length > 60) {
            $score -= 20;
            $reasons[] = 'Too long';
        } else {
            $score += 5;
        }
        
        // Check for generic words
        $generic = array('image', 'img', 'photo', 'picture');
        $alt_lower = strtolower($alt_text);
        $is_generic = false;
        
        foreach ($generic as $word) {
            if ($alt_lower === $word || strpos($alt_lower, $word) === 0) {
                $is_generic = true;
                break;
            }
        }
        
        if ($is_generic) {
            $score -= 25;
            $reasons[] = 'Generic terminology';
        } else {
            $score += 15;
        }
        
        // Check for descriptiveness (number of words)
        $word_count = str_word_count($alt_text);
        if ($word_count >= 5 && $word_count <= 15) {
            $score += 15;
            $reasons[] = 'Good descriptiveness';
        } elseif ($word_count < 3) {
            $score -= 10;
            $reasons[] = 'Not descriptive enough';
        }
        
        // Ensure score is within 0-100
        $score = max(0, min(100, $score));
        






        
        // OPTIMIZATION THRESHOLD DEBUG
        if ($score >= 50) {

        } else {

        }
        
        return array(
            'score' => $score,
            'reasoning' => implode(', ', $reasons)
        );
    }
}
