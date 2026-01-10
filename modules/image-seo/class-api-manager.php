<?php
/**
 * Image SEO Module - API Manager
 * 
 * Handles OpenAI API communication
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
 * API Manager Class
 */
class API_Manager {
    
    /**
     * OpenAI API endpoint
     */
    const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    
    /**
     * Get API key from global settings
     */
    private function get_api_key() {
        return get_option('seoautofix_openai_api_key', '');
    }
    
    /**
     * Get model from global settings
     */
    private function get_model() {
        return get_option('seoautofix_openai_model', 'gpt-4o');
    }
    
    /**
     * Call OpenAI API
     *
     * @param string $prompt The prompt to send
     * @param float $temperature Temperature setting (0-1)
     * @param int $max_tokens Maximum tokens to generate
     * @param bool $json_mode Whether to request JSON response
     * @return string|array The response from OpenAI
     * @throws \Exception If API call fails
     */
    public function call_openai($prompt, $temperature = 0.7, $max_tokens = 50, $json_mode = false) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            throw new \Exception('OpenAI API key not configured');
        }
        
        $model = $this->get_model();
        
        // Prepare request body
        $body = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => $temperature,
            'max_tokens' => $max_tokens
        );
        
        // Add JSON mode if requested
        if ($json_mode) {
            $body['response_format'] = array('type' => 'json_object');
        }
        
        // Make API request
        $response = wp_remote_post(self::API_ENDPOINT, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => wp_json_encode($body)
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            throw new \Exception('API request failed: ' . $response->get_error_message());
        }
        
        // Get response body
        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        
        // Check response code
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unknown error';
            throw new \Exception('OpenAI API error: ' . $error_message);
        }
        
        // Decode response
        $data = json_decode($response_body, true);
        
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid API response format');
        }
        
        $content = $data['choices'][0]['message']['content'];
        
        // Parse JSON if requested
        if ($json_mode) {
            $parsed = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse JSON response');
            }
            return $parsed;
        }
        
        return trim($content);
    }
    
    /**
     * Call OpenAI Vision API to analyze an image
     *
     * @param string $image_data Either image URL or base64 encoded image with mime type
     * @param string $prompt The prompt for analysis
     * @param int $max_tokens Maximum tokens to generate
     * @param bool $is_base64 Whether the image_data is base64 encoded
     * @return string The analysis response
     * @throws \Exception If API call fails
     */
    public function call_openai_vision($image_data, $prompt = 'Describe this image in detail', $max_tokens = 300, $is_base64 = false) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            throw new \Exception('OpenAI API key not configured');
        }
        
        $model = $this->get_model();
        
        // Prepare image URL object
        if ($is_base64) {
            $image_url_obj = array(
                'url' => $image_data // base64 data URL format: data:image/jpeg;base64,/9j/4AA...
            );
        } else {
            $image_url_obj = array(
                'url' => $image_data,
                'detail' => 'low' // 'low' for faster/cheaper, 'high' for more detail
            );
        }
        
        // Prepare request body with image
        $body = array(
            'model' => $model, // gpt-4o supports vision natively
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => $prompt
                        ),
                        array(
                            'type' => 'image_url',
                            'image_url' => $image_url_obj
                        )
                    )
                )
            ),
            'max_tokens' => $max_tokens,
            'temperature' => 0.5
        );
        
        // Make API request
        $response = wp_remote_post(self::API_ENDPOINT, array(
            'timeout' => 45, // Vision API may take longer
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => wp_json_encode($body)
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            throw new \Exception('Vision API request failed: ' . $response->get_error_message());
        }
        
        // Get response body
        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        
        // Check response code
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unknown error';
            throw new \Exception('OpenAI Vision API error: ' . $error_message);
        }
        
        // Decode response
        $data = json_decode($response_body, true);
        
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid Vision API response format');
        }
        
        return trim($data['choices'][0]['message']['content']);
    }
    
    /**
     * Handle API errors
     *
     * @param \Exception $error The error to handle
     * @return array Error response
     */
    public function handle_error($error) {
        $message = $error->getMessage();
        
        // Log error

        
        // Return formatted error
        return array(
            'success' => false,
            'error' => $message
        );
    }
}
