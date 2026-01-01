<?php
/**
 * Image SEO Module - Local Vision Analyzer
 * 
 * Handles local image analysis using LM Studio or similar
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
 * Local Vision Analyzer Class
 */
class Local_Vision_Analyzer {
    
    /**
     * Analyze image using local vision model
     *
     * @param int $attachment_id The attachment ID
     * @return string|false Image caption or false on failure
     */
    public function analyze_image($attachment_id) {
        // Check if local vision is enabled
        if (!\SEOAutoFix_Settings::is_local_vision_enabled()) {
            return false;
        }
        
        $api_url = \SEOAutoFix_Settings::get_local_vision_url();
        
        // Get image file path
        $image_path = get_attached_file($attachment_id);
        
        if (!file_exists($image_path)) {
            return false;
        }
        
        // Convert image to base64
        $image_data = file_get_contents($image_path);
        $base64_image = base64_encode($image_data);
        $mime_type = mime_content_type($image_path);
        
        // Build request for LM Studio vision model
        $body = array(
            'model' => 'local-model', // LM Studio uses loaded model
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => 'Describe this image in one detailed sentence suitable for SEO alt text. Focus on what is visible in the image.'
                        ),
                        array(
                            'type' => 'image_url',
                            'image_url' => array(
                                'url' => "data:{$mime_type};base64,{$base64_image}"
                            )
                        )
                    )
                )
            ),
            'temperature' => 0.7,
            'max_tokens' => 100
        );
        
        // Make API request to local model
        $response = wp_remote_post($api_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode($body)
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            error_log('Local vision API error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            error_log('Local vision API returned code: ' . $response_code);
            return false;
        }
        
        // Parse response
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return false;
        }
        
        $caption = trim($data['choices'][0]['message']['content']);
        
        return $caption;
    }
    
    /**
     * Test connection to local vision model
     *
     * @return array Result with success status and message
     */
    public function test_connection() {
        $api_url = \SEOAutoFix_Settings::get_local_vision_url();
        
        // Simple health check
        $response = wp_remote_get($api_url, array(
            'timeout' => 5
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Cannot connect to LM Studio: ' . $response->get_error_message()
            );
        }
        
        return array(
            'success' => true,
            'message' => 'Connection successful'
        );
    }
}
