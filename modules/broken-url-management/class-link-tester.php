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
class Link_Tester
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
     * Test a URL and return its status
     * 
     * @param string $url URL to test
     * @return array Test result with status_code, error_type, and is_broken
     */
    public function test_url($url)
    {
        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL) && strpos($url, '/') !== 0) {
            return array(
                'status_code' => 0,
                'error_type' => 'dns',
                'is_broken' => true,
                'error' => __('Invalid URL format', 'seo-autofix-pro')
            );
        }

        // Convert relative URLs to absolute
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            // Get only the scheme and host (without subdirectory path)
            $parsed_home = parse_url(home_url());
            $base = $parsed_home['scheme'] . '://' . $parsed_home['host'];
            if (isset($parsed_home['port'])) {
                $base .= ':' . $parsed_home['port'];
            }
            $url = $base . $url;
        }

        // For internal WordPress URLs, use faster WordPress functions
        $site_url = get_site_url();
        $home_url = get_home_url();

        if (strpos($url, $site_url) === 0 || strpos($url, $home_url) === 0) {
            // This is an internal WordPress URL - use WordPress functions
            $path = parse_url($url, PHP_URL_PATH);
            $query = parse_url($url, PHP_URL_QUERY);
            $fragment = parse_url($url, PHP_URL_FRAGMENT);

            // Build the internal URL (path + query, without fragment)
            $internal_url = $path;
            if ($query) {
                $internal_url .= '?' . $query;
            }

            // Check if it's a valid WordPress post/page
            $post_id = url_to_postid($internal_url);

            if ($post_id > 0) {
                // Valid post/page exists
                $post = get_post($post_id);
                if ($post && $post->post_status === 'publish') {
                    // Post exists and is published - not broken
                    return array(
                        'status_code' => 200,
                        'error_type' => null,
                        'is_broken' => false,
                        'error' => null
                    );
                }
            }

            // Check if it's a valid attachment
            if (preg_match('/wp-content\/uploads\//', $url)) {
                $upload_dir = wp_upload_dir();
                $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);

                if (file_exists($file_path)) {
                    return array(
                        'status_code' => 200,
                        'error_type' => null,
                        'is_broken' => false,
                        'error' => null
                    );
                } else {
                    return array(
                        'status_code' => 404,
                        'error_type' => '4xx',
                        'is_broken' => true,
                        'error' => __('File not found', 'seo-autofix-pro')
                    );
                }
            }

            // Check if it's admin/login page (not broken, just requires auth)
            if (strpos($url, '/wp-admin/') !== false || strpos($url, '/wp-login.php') !== false) {
                return array(
                    'status_code' => 200,
                    'error_type' => null,
                    'is_broken' => false,
                    'error' => null
                );
            }
        }

        // For external URLs or URLs that don't match above patterns, use HTTP request
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
                'status_code' => 0,
                'error_type' => $error_type,
                'is_broken' => true,
                'error' => $error_message
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $error_type = $this->categorize_error_type($status_code);

        return array(
            'status_code' => $status_code,
            'error_type' => $error_type,
            'is_broken' => $this->is_broken_status_code($status_code),
            'error' => null
        );
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
     * Test multiple URLs in batch
     * 
     * @param array $urls Array of URLs to test
     * @param callable $progress_callback Optional callback for progress updates
     * @return array Results indexed by URL
     */
    public function test_urls_batch($urls, $progress_callback = null)
    {
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
    private function is_broken_status_code($status_code)
    {
        // Consider 4xx and 5xx as broken, also 0 for connection failures
        return $status_code === 0 || $status_code >= 400;
    }

    /**
     * Get categorized broken status codes
     * 
     * @param int $status_code HTTP status code
     * @return string Category
     */
    public function categorize_status_code($status_code)
    {
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
    public function get_status_message($status_code)
    {
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
