<?php
/**
 * URL Testing Proxy - Lightweight proxy for testing external URLs from frontend
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL Testing Proxy Class
 * 
 * Provides a lightweight proxy for testing external URLs that can't be tested
 * from the frontend due to CORS restrictions.
 */
class URL_Testing_Proxy
{
    /**
     * Timeout for requests (seconds)
     */
    const REQUEST_TIMEOUT = 10;

    /**
     * User agent string
     */
    const USER_AGENT = 'SEO AutoFix Pro/1.0 WordPress Link Checker';

    /**
     * Test a single external URL
     * 
     * @param string $url URL to test
     * @return array Test result with status_code, error_type, and is_broken
     */
    public function test_external_url($url)
    {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return array(
                'url' => $url,
                'status_code' => 0,
                'error_type' => 'dns',
                'is_broken' => true,
                'error' => __('Invalid URL format', 'seo-autofix-pro')
            );
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
            $error_message = $response->get_error_message();
            $error_type = 'timeout';

            // Check if it's a DNS error
            if (stripos($error_message, 'dns') !== false || stripos($error_message, 'resolve') !== false) {
                $error_type = 'dns';
            }

            return array(
                'url' => $url,
                'status_code' => 0,
                'error_type' => $error_type,
                'is_broken' => true,
                'error' => $error_message
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $error_type = $this->categorize_error_type($status_code);

        return array(
            'url' => $url,
            'status_code' => $status_code,
            'error_type' => $error_type,
            'is_broken' => $this->is_broken_status_code($status_code),
            'error' => null
        );
    }

    /**
     * Test multiple external URLs in parallel using cURL multi-handle
     * 
     * @param array $urls Array of URLs to test
     * @param int $parallel_limit Number of URLs to test simultaneously (default: 10)
     * @return array Associative array of URL => test_result
     */
    public function test_external_urls_batch($urls, $parallel_limit = 10)
    {
        if (empty($urls)) {
            return array();
        }

        error_log('[URL TESTING PROXY] Testing ' . count($urls) . ' external URLs (limit: ' . $parallel_limit . ' concurrent)');

        $results = array();

        // Process URLs in chunks to avoid overwhelming server
        $url_chunks = array_chunk($urls, $parallel_limit, true);

        foreach ($url_chunks as $chunk) {
            $chunk_results = $this->execute_parallel_curl($chunk);
            $results = array_merge($results, $chunk_results);
        }

        error_log('[URL TESTING PROXY] Completed testing ' . count($results) . ' URLs');

        return $results;
    }

    /**
     * Execute parallel cURL requests
     * 
     * @param array $urls URLs to test
     * @return array Results indexed by URL
     */
    private function execute_parallel_curl($urls)
    {
        $results = array();
        $curl_handles = array();
        $mh = curl_multi_init();

        // Initialize cURL handles for each URL
        foreach ($urls as $url) {
            $ch = curl_init();

            // Configure cURL options
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,  // HEAD request (faster)
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HEADER => false
            ));

            curl_multi_add_handle($mh, $ch);
            $curl_handles[$url] = $ch;
        }

        // Execute all requests simultaneously
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            // Wait for activity on any connection
            curl_multi_select($mh, 0.1);
        } while ($running > 0);

        // Collect results from each handle
        foreach ($curl_handles as $url => $ch) {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            $curl_errno = curl_errno($ch);

            if ($curl_errno) {
                // cURL error occurred
                $error_type = 'timeout';

                // Categorize based on error code
                if ($curl_errno === 6) { // CURLE_COULDNT_RESOLVE_HOST
                    $error_type = 'dns';
                } elseif ($curl_errno === 7) { // CURLE_COULDNT_CONNECT
                    $error_type = 'timeout';
                } elseif ($curl_errno === 28) { // CURLE_OPERATION_TIMEDOUT
                    $error_type = 'timeout';
                }

                $results[$url] = array(
                    'url' => $url,
                    'status_code' => 0,
                    'error_type' => $error_type,
                    'is_broken' => true,
                    'error' => $curl_error
                );
            } else {
                // Successful response (may still be error status code)
                $error_type = $this->categorize_error_type($http_code);

                $results[$url] = array(
                    'url' => $url,
                    'status_code' => $http_code,
                    'error_type' => $error_type,
                    'is_broken' => $this->is_broken_status_code($http_code),
                    'error' => null
                );
            }

            // Clean up this handle
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        // Close the multi handle
        curl_multi_close($mh);

        return $results;
    }

    /**
     * Categorize error type based on status code
     * 
     * @param int $status_code HTTP status code
     * @return string Error type (4xx, 5xx, timeout, dns, or null)
     */
    private function categorize_error_type($status_code)
    {
        if ($status_code >= 400 && $status_code < 500) {
            return '4xx';
        } elseif ($status_code >= 500) {
            return '5xx';
        } elseif ($status_code === 0) {
            return 'timeout';
        }
        return null; // Success codes (2xx, 3xx)
    }

    /**
     * Check if status code indicates a broken link
     * 
     * @param int $status_code HTTP status code
     * @return bool True if broken
     */
    private function is_broken_status_code($status_code)
    {
        // Consider 4xx and 5xx as broken, also 0 for connection failures
        return $status_code === 0 || $status_code >= 400;
    }
}
