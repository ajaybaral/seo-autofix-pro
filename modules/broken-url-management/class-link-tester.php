<?php
/**
 * Link Tester - Tests URLs for HTTP status codes
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Link Tester Class
 */
class Link_Tester {
    
    /**
     * Timeout for requests (seconds)
     */
    const REQUEST_TIMEOUT = 10;
    
    /**
     * User agent string
     */
    const USER_AGENT = 'SEO AutoFix Pro/1.0 WordPress Link Checker';
    
    /**
     * Test a URL and return its status
     * 
     * @param string $url URL to test
     * @return array Test result with status_code and is_broken
     */
    public function test_url($url) {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL) && strpos($url, '/') !== 0) {
            return array(
                'status_code' => 0,
                'is_broken' => true,
                'error' => __('Invalid URL format', 'seo-autofix-pro')
            );
        }
        
        // Convert relative URLs to absolute
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $url = home_url($url);
        }
        
        // Make HEAD request first (faster)
        $response = wp_remote_head($url, array(
            'timeout' => self::REQUEST_TIMEOUT,
            'redirection' => 5,
            'user-agent' => self::USER_AGENT,
            'sslverify' => false // Allow self-signed certificates
        ));
        
        // If HEAD fails, try GET request
        if (is_wp_error($response)) {
            $response = wp_remote_get($url, array(
                'timeout' => self::REQUEST_TIMEOUT,
                'redirection' => 5,
                'user-agent' => self::USER_AGENT,
                'sslverify' => false
            ));
        }
        
        // Handle errors
        if (is_wp_error($response)) {
            return array(
                'status_code' => 0,
                'is_broken' => true,
                'error' => $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        return array(
            'status_code' => $status_code,
            'is_broken' => $this->is_broken_status_code($status_code),
            'error' => null
        );
    }
    
    /**
     * Test multiple URLs in batch
     * 
     * @param array $urls Array of URLs to test
     * @param callable $progress_callback Optional callback for progress updates
     * @return array Results indexed by URL
     */
    public function test_urls_batch($urls, $progress_callback = null) {
        $results = array();
        $total = count($urls);
        $tested = 0;
        
        foreach ($urls as $url) {
            $results[$url] = $this->test_url($url);
            $tested++;
            
            // Call progress callback if provided
            if (is_callable($progress_callback)) {
                call_user_func($progress_callback, $tested, $total);
            }
            
            // Small delay to avoid overwhelming servers
            usleep(100000); // 0.1 second delay
        }
        
        return $results;
    }
    
    /**
     * Check if status code indicates a broken link
     * 
     * @param int $status_code HTTP status code
     * @return bool True if broken
     */
    private function is_broken_status_code($status_code) {
        // Consider 4xx and 5xx as broken, also 0 for connection failures
        return $status_code === 0 || $status_code >= 400;
    }
    
    /**
     * Get categorized broken status codes
     * 
     * @param int $status_code HTTP status code
     * @return string Category
     */
    public function categorize_status_code($status_code) {
        if ($status_code === 0) {
            return 'connection_failed';
        } elseif ($status_code === 404) {
            return 'not_found';
        } elseif ($status_code >= 400 && $status_code < 500) {
            return 'client_error';
        } elseif ($status_code >= 500) {
            return 'server_error';
        } else {
            return 'success';
        }
    }
    
    /**
     * Get human-readable status message
     * 
     * @param int $status_code HTTP status code
     * @return string Status message
     */
    public function get_status_message($status_code) {
        switch ($status_code) {
            case 0:
                return __('Connection failed', 'seo-autofix-pro');
            case 200:
                return __('OK', 'seo-autofix-pro');
            case 301:
            case 302:
                return __('Redirected', 'seo-autofix-pro');
            case 400:
                return __('Bad Request', 'seo-autofix-pro');
            case 401:
                return __('Unauthorized', 'seo-autofix-pro');
            case 403:
                return __('Forbidden', 'seo-autofix-pro');
            case 404:
                return __('Not Found', 'seo-autofix-pro');
            case 500:
                return __('Internal Server Error', 'seo-autofix-pro');
            case 502:
                return __('Bad Gateway', 'seo-autofix-pro');
            case 503:
                return __('Service Unavailable', 'seo-autofix-pro');
            default:
                return sprintf(__('HTTP %d', 'seo-autofix-pro'), $status_code);
        }
    }
}
