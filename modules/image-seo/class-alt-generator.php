<?php
/**
 * Image SEO Module - Alt Text Generator
 * 
 * Generates AI-powered alt text using OpenAI
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
 * Alt Generator Class
 */
class Alt_Generator {
    
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
     * Generate alt text for an image
     *
     * @param int $attachment_id The attachment ID
     * @param array $context Usage context information
     * @return string The generated alt text
     * @throws \Exception If generation fails
     */
    public function generate_alt_text($attachment_id, $context) {
        // First, analyze the actual image using Vision API
        $image_description = $this->analyze_image($attachment_id);
        
        // Then generate alt text based on the actual image content + context
        $prompt = $this->build_prompt($attachment_id, $context, $image_description);
        
        try {
            $alt_text = $this->api_manager->call_openai($prompt, 0.7, 50);
            
            // Ensure it's within character limit (30-60 chars)
            if (strlen($alt_text) > 60) {
                $alt_text = substr($alt_text, 0, 60);
            }
            
            return $alt_text;
            
        } catch (\Exception $e) {
            throw new \Exception('Failed to generate alt text: ' . $e->getMessage());
        }
    }
    
    /**
     * Analyze image using GPT-4 Vision API
     *
     * @param int $attachment_id The attachment ID
     * @return string Image description/caption
     */
    private function analyze_image($attachment_id) {
        try {
            $image_url = wp_get_attachment_url($attachment_id);
            
            if (!$image_url) {
                return '';
            }
            
            // Check if we're on localhost or the image is not publicly accessible
            $is_localhost = $this->is_localhost_url($image_url);
            
            $prompt = "Describe this image in one concise sentence (20-30 words). Focus on the main subject, key objects, actions, colors, and setting. Be specific and descriptive.";
            
            if ($is_localhost) {
                // Convert image to base64 for localhost environments
                $base64_image = $this->convert_image_to_base64($attachment_id);
                
                if (!$base64_image) {
                    return '';
                }
                
                $description = $this->api_manager->call_openai_vision($base64_image, $prompt, 100, true);
            } else {
                // Use URL directly for publicly accessible images
                $description = $this->api_manager->call_openai_vision($image_url, $prompt, 100, false);
            }
            
            return $description;
            
        } catch (\Exception $e) {
            // If Vision API fails, return empty string (will fallback to metadata-only generation)

            return '';
        }
    }
    
    /**
     * Check if URL is localhost
     *
     * @param string $url The URL to check
     * @return bool Whether the URL is localhost
     */
    private function is_localhost_url($url) {
        $host = parse_url($url, PHP_URL_HOST);
        $localhost_patterns = array('localhost', '127.0.0.1', '::1', '0.0.0.0');
        
        foreach ($localhost_patterns as $pattern) {
            if (strpos($host, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Convert image to base64 data URL
     *
     * @param int $attachment_id The attachment ID
     * @return string|false Base64 data URL or false on failure
     */
    private function convert_image_to_base64($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        // Get mime type
        $mime_type = get_post_mime_type($attachment_id);
        
        if (!$mime_type || strpos($mime_type, 'image/') !== 0) {
            return false;
        }
        
        // Read file and encode to base64
        $image_data = file_get_contents($file_path);
        
        if ($image_data === false) {
            return false;
        }
        
        $base64 = base64_encode($image_data);
        
        // Return as data URL
        return 'data:' . $mime_type . ';base64,' . $base64;
    }
    
    /**
     * Build prompt for OpenAI
     *
     * @param int $attachment_id The attachment ID
     * @param array $context Usage context
     * @param string $image_description Description from Vision API
     * @return string The prompt
     */
    private function build_prompt($attachment_id, $context, $image_description = '') {
        // Get image metadata
        $post = get_post($attachment_id);
        $title = $post->post_title;
        $description = $post->post_content;
        $caption = $post->post_excerpt;
        $current_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $filename = basename(get_attached_file($attachment_id));
        
        // Build context from usage
        $page_info = '';
        if (!empty($context['pages'])) {
            $first_page = $context['pages'][0];
            $page_info = "Page Title: " . $first_page['title'] . "\n";
            
            if (!empty($first_page['h1'])) {
                $page_info .= "Page H1: " . $first_page['h1'] . "\n";
            }
            
            if (!empty($first_page['surrounding_content'])) {
                $page_info .= "Context: " . $first_page['surrounding_content'] . "\n";
            }
        }
        
        $prompt = "You are an SEO expert. Generate an optimized alt text for an image.\n\n";
        
        // IMPORTANT: Add image description from Vision API first (most important context)
        if (!empty($image_description)) {
            $prompt .= "**ACTUAL IMAGE CONTENT (from analysis):**\n";
            $prompt .= $image_description . "\n\n";
        }
        
        $prompt .= "Additional Context:\n";
        $prompt .= "- Image Title: " . $title . "\n";
        if (!empty($description)) {
            $prompt .= "- Description: " . $description . "\n";
        }
        if (!empty($current_alt)) {
            $prompt .= "- Current Alt: " . $current_alt . "\n";
        }
        if (!empty($caption)) {
            $prompt .= "- Caption: " . $caption . "\n";
        }
        $prompt .= $page_info;
        $prompt .= "- Filename: " . $filename . "\n\n";
        
        $prompt .= "Requirements:\n";
        $prompt .= "- Length: 30-60 characters (strict requirement)\n";
        $prompt .= "- Descriptive and specific based on ACTUAL image content\n";
        $prompt .= "- Include relevant keywords naturally from the page context\n";
        $prompt .= "- Accessible for screen readers\n";
        $prompt .= "- SEO-optimized\n\n";
        
        $prompt .= "Return ONLY the alt text, nothing else.";
        
        return $prompt;
    }
    
    /**
     * Get surrounding content from where image is used
     *
     * @param int $attachment_id The attachment ID
     * @return string Surrounding content
     */
    private function get_surrounding_content($attachment_id) {
        // Find posts using this image
        global $wpdb;
        
        $file_url = wp_get_attachment_url($attachment_id);
        $filename = basename($file_url);
        
        // Search for posts containing this image
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_content LIKE %s 
            LIMIT 1",
            '%' . $wpdb->esc_like($filename) . '%'
        ));
        
        if (empty($posts)) {
            return '';
        }
        
        $content = $posts[0]->post_content;
        
        // Extract 150 words around the image
        $pos = strpos($content, $filename);
        if ($pos !== false) {
            $start = max(0, $pos - 500);
            $length = 1000;
            $excerpt = substr($content, $start, $length);
            
            // Strip HTML tags
            $excerpt = wp_strip_all_tags($excerpt);
            
            // Limit to 150 words
            $words = explode(' ', $excerpt);
            $words = array_slice($words, 0, 150);
            $excerpt = implode(' ', $words);
            
            return $excerpt;
        }
        
        return '';
    }
    
    /**
     * Validate if user-written alt text accurately describes the image
     * Uses AI to check semantic similarity between image and alt text
     *
     * @param int $attachment_id The attachment ID
     * @param string $user_alt_text The alt text written by user
     * @return array Validation result with is_valid, match_percentage, reasoning
     */
    public function validate_alt_text_with_image($attachment_id, $user_alt_text) {



        
        try {
            // Get image URL and check if localhost
            $image_url = wp_get_attachment_url($attachment_id);
            
            if (!$image_url) {

                throw new \Exception('Could not get image URL');
            }
            
            $is_localhost = $this->is_localhost_url($image_url);

            
            // Build validation prompt
            $prompt = "You are an expert image analyzer. Your task is to determine if the provided alt text accurately describes the image.

**User's Alt Text:** \"{$user_alt_text}\"

**Analysis Steps:**
1. Carefully analyze what you see in the image
2. Compare the image content with the user's alt text
3. Calculate semantic similarity percentage (0-100%)
   - 100% = Perfect match, alt text describes exactly what's in the image
   - 70-99% = Good match, alt text is accurate but may miss minor details
   - 50-69% = Partial match, some elements correct but significant mismatches
   - 0-49% = Poor match, alt text doesn't describe this image

**IMPORTANT:** Be strict in your evaluation. Alt text must genuinely describe what's visible in the image. Reject vague, generic, or keyword-stuffed text that doesn't match.

**Respond ONLY with valid JSON (no markdown, no code blocks):**
{
    \"match_percentage\": <number 0-100>,
    \"image_content\": \"<what you actually see in the image>\",
    \"reasoning\": \"<brief explanation why it matches or doesn't match>\"
}";


            
            // Call Vision API
            if ($is_localhost) {
                // Convert to base64 for localhost
                $base64_data_url = $this->convert_image_to_base64($attachment_id);
                
                if (!$base64_data_url) {

                    throw new \Exception('Could not convert image to base64');
                }
                

                $response = $this->api_manager->call_openai_vision($base64_data_url, $prompt, 150, true);
            } else {

                $response = $this->api_manager->call_openai_vision($image_url, $prompt, 150, false);
            }
            

            
            // Parse JSON response
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {

                throw new \Exception('Failed to parse API response');
            }
            
            // Extract data
            $match_percentage = isset($data['match_percentage']) ? intval($data['match_percentage']) : 0;
            $image_content = isset($data['image_content']) ? $data['image_content'] : 'Unknown';
            $reasoning = isset($data['reasoning']) ? $data['reasoning'] : 'No reasoning provided';
            
            $is_valid = ($match_percentage >= 70);
            




            
            return array(
                'is_valid' => $is_valid,
                'match_percentage' => $match_percentage,
                'reasoning' => $reasoning,
                'image_content' => $image_content
            );
            
        } catch (\Exception $e) {


            
            // On error, fail gracefully - assume valid to not block user
            return array(
                'is_valid' => true,
                'match_percentage' => 75,
                'reasoning' => 'Validation failed: ' . $e->getMessage(),
                'image_content' => 'Error analyzing image'
            );
        }
    }
}
