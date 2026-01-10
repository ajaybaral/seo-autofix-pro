<?php
/**
 * Image SEO Module - Image Captioning Service
 * 
 * Uses BLIP via Replicate API to caption images
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
 * Image Captioning Class
 */
class Image_Captioner {
    
    /**
     * Replicate API endpoint
     */
    const REPLICATE_API = 'https://api.replicate.com/v1/predictions';
    
    /**
     * BLIP model on Replicate
     */
    const BLIP_MODEL = 'salesforce/blip:2e1dddc8621f72155f24cf2e0adbde548458d3cab9f00c0139eea840d0ac4746';
    
    /**
     * Get Replicate API key from settings
     */
    private function get_api_key() {
        return get_option('seoautofix_replicate_api_key', '');
    }
    
    /**
     * Check if Replicate API key is configured
     */
    public function is_configured() {
        $key = $this->get_api_key();
        return !empty($key);
    }
    
    /**
     * Caption an image using BLIP
     *
     * @param int $attachment_id The attachment ID
     * @return string|false Caption or false on failure
     */
    public function caption_image($attachment_id) {
        // Check if API key is configured
        if (!$this->is_configured()) {
            return false;
        }
        
        // Get image URL
        $image_url = wp_get_attachment_url($attachment_id);
        if (!$image_url) {
            return false;
        }
        
        try {
            // Call Replicate API
            $caption = $this->call_blip_api($image_url);
            return $caption;
            
        } catch (\Exception $e) {

            return false;
        }
    }
    
    /**
     * Call Replicate BLIP API
     *
     * @param string $image_url The image URL
     * @return string The caption
     * @throws \Exception If API call fails
     */
    private function call_blip_api($image_url) {
        $api_key = $this->get_api_key();
        
        // Prepare prediction request
        $body = array(
            'version' => self::BLIP_MODEL,
            'input' => array(
                'image' => $image_url,
                'task' => 'image_captioning'
            )
        );
        
        // Create prediction
        $response = wp_remote_post(self::REPLICATE_API, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Token ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode($body)
        ));
        
        if (is_wp_error($response)) {
            throw new \Exception('API request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if ($response_code !== 201) {
            $error_message = isset($data['detail']) ? $data['detail'] : 'Unknown error';
            throw new \Exception('Replicate API error: ' . $error_message);
        }
        
        // Get prediction ID and URL
        $prediction_id = $data['id'];
        $get_url = $data['urls']['get'];
        
        // Poll for result (max 30 seconds)
        $max_attempts = 30;
        $attempt = 0;
        
        while ($attempt < $max_attempts) {
            sleep(1);
            
            $result_response = wp_remote_get($get_url, array(
                'headers' => array(
                    'Authorization' => 'Token ' . $api_key
                )
            ));
            
            if (is_wp_error($result_response)) {
                throw new \Exception('Failed to get prediction result');
            }
            
            $result_body = wp_remote_retrieve_body($result_response);
            $result_data = json_decode($result_body, true);
            
            $status = $result_data['status'];
            
            if ($status === 'succeeded') {
                // BLIP returns output as a string
                $caption = isset($result_data['output']) ? $result_data['output'] : '';
                
                if (empty($caption)) {
                    throw new \Exception('Empty caption returned');
                }
                
                return trim($caption);
                
            } elseif ($status === 'failed') {
                $error = isset($result_data['error']) ? $result_data['error'] : 'Unknown error';
                throw new \Exception('Caption generation failed: ' . $error);
            }
            
            $attempt++;
        }
        
        throw new \Exception('Caption generation timed out');
    }
}
