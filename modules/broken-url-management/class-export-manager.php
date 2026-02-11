<?php
/**
 * Export Manager - Handles report exports (CSV, PDF, Email)
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export Manager Class
 * Handles exporting broken links reports
 */
class Export_Manager
{

    /**
     * Database manager
     */
    private $db_manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db_manager = new Database_Manager();
    }

    /**
     * Export scan results to CSV
     * 
     * @param string $scan_id Scan ID
     * @param string $filter Filter type (all, internal, external)
     * @return bool Success
     */
    public function export_to_csv($scan_id, $filter = 'all')
    {
        $results = $this->db_manager->get_scan_results($scan_id, $filter, '', 1, 99999);

        if (empty($results['results'])) {
            return false;
        }

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="broken-links-' . $scan_id . '-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // CSV Headers
        fputcsv($output, array(
            'Page Title',
            'Page URL',
            'Location',
            'Anchor Text',
            'Broken URL',
            'Link Type',
            'Status Code',
            'Error Type',
            'Suggested URL',
            'Reason',
            'Is Fixed'
        ));

        // CSV Data
        foreach ($results['results'] as $row) {
            fputcsv($output, array(
                $row['found_on_page_title'],
                $row['found_on_url'],
                $row['link_location'],
                $row['anchor_text'],
                $row['broken_url'],
                $row['link_type'],
                $row['status_code'],
                $row['error_type'],
                $row['suggested_url'],
                $row['reason'],
                $row['is_fixed'] ? 'Yes' : 'No'
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Export scan results to PDF
     * 
     * @param string $scan_id Scan ID
     * @param string $filter Filter type
     * @return string|false PDF file path or false on failure
     */
    public function export_to_pdf($scan_id, $filter = 'all')
    {
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            return false;
        }

        $results = $this->db_manager->get_scan_results($scan_id, $filter, '', 1, 99999);

        if (empty($results['results'])) {
            return false;
        }

        // Create PDF
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('SEO AutoFix Pro');
        $pdf->SetAuthor(get_bloginfo('name'));
        $pdf->SetTitle('Broken Links Report - ' . $scan_id);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Add page
        $pdf->AddPage();

        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Broken Links Report', 0, 1, 'C');
        $pdf->Ln(5);

        // Summary
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Scan ID: ' . $scan_id, 0, 1);
        $pdf->Cell(0, 6, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1);
        $pdf->Cell(0, 6, 'Total Broken Links: ' . count($results['results']), 0, 1);
        $pdf->Ln(5);

        // Table
        $pdf->SetFont('helvetica', '', 8);

        foreach ($results['results'] as $row) {
            // Page title
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 6, $row['found_on_page_title'], 0, 1);

            // Details
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Cell(40, 5, 'Location:', 0, 0);
            $pdf->Cell(0, 5, $row['link_location'], 0, 1);

            $pdf->Cell(40, 5, 'Anchor Text:', 0, 0);
            $pdf->Cell(0, 5, substr($row['anchor_text'], 0, 100), 0, 1);

            $pdf->Cell(40, 5, 'Broken URL:', 0, 0);
            $pdf->Cell(0, 5, $row['broken_url'], 0, 1);

            $pdf->Cell(40, 5, 'Status:', 0, 0);
            $pdf->Cell(0, 5, $row['status_code'] . ' (' . $row['error_type'] . ')', 0, 1);

            if (!empty($row['suggested_url'])) {
                $pdf->Cell(40, 5, 'Suggested:', 0, 0);
                $pdf->Cell(0, 5, $row['suggested_url'], 0, 1);
            }

            $pdf->Ln(3);
        }

        // Output PDF
        $filename = 'broken-links-' . $scan_id . '-' . date('Y-m-d') . '.pdf';
        $pdf->Output($filename, 'D');
        exit;
    }

    /**
     * Email report to specified address
     * 
     * @param string $scan_id Scan ID
     * @param string $email Email address
     * @param string $format Format (csv or summary)
     * @return array Result
     */
    public function email_report($scan_id, $email, $format = 'summary')
    {
        if (!is_email($email)) {
            return array(
                'success' => false,
                'message' => __('Invalid email address', 'seo-autofix-pro')
            );
        }

        $results = $this->db_manager->get_scan_results($scan_id, 'all', '', 1, 99999);

        if (empty($results['results'])) {
            return array(
                'success' => false,
                'message' => __('No results to email', 'seo-autofix-pro')
            );
        }

        $subject = sprintf(
            __('Broken Links Report - %s', 'seo-autofix-pro'),
            get_bloginfo('name')
        );

        if ($format === 'csv') {
            // Generate CSV attachment
            $csv_content = $this->generate_csv_content($results['results']);
            $filename = 'broken-links-' . $scan_id . '-' . date('Y-m-d') . '.csv';

            $attachments = array();
            $upload_dir = wp_upload_dir();
            $temp_file = $upload_dir['basedir'] . '/' . $filename;

            file_put_contents($temp_file, $csv_content);
            $attachments[] = $temp_file;

            $message = $this->generate_email_summary($scan_id, $results);

            $sent = wp_mail($email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'), $attachments);

            // Clean up temp file
            @unlink($temp_file);

        } else {
            // Send summary only
            $message = $this->generate_email_summary($scan_id, $results);
            $sent = wp_mail($email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
        }

        if ($sent) {
            return array(
                'success' => true,
                'message' => sprintf(__('Report sent to %s', 'seo-autofix-pro'), $email)
            );
        } else {
            return array(
                'success' => false,
                'message' => __('Failed to send email', 'seo-autofix-pro')
            );
        }
    }

    /**
     * Generate CSV content as string
     * 
     * @param array $results Results array
     * @return string CSV content
     */
    private function generate_csv_content($results)
    {
        $output = fopen('php://temp', 'r+');

        // Headers
        fputcsv($output, array(
            'Page Title',
            'Page URL',
            'Location',
            'Anchor Text',
            'Broken URL',
            'Link Type',
            'Status Code',
            'Error Type',
            'Suggested URL',
            'Is Fixed'
        ));

        // Data
        foreach ($results as $row) {
            fputcsv($output, array(
                $row['found_on_page_title'],
                $row['found_on_url'],
                $row['link_location'],
                $row['anchor_text'],
                $row['broken_url'],
                $row['link_type'],
                $row['status_code'],
                $row['error_type'],
                $row['suggested_url'],
                $row['is_fixed'] ? 'Yes' : 'No'
            ));
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);

        return $csv_content;
    }

    /**
     * Generate email summary HTML
     * 
     * @param string $scan_id Scan ID
     * @param array $results Results data
     * @return string HTML content
     */
    private function generate_email_summary($scan_id, $results)
    {
        $total = count($results['results']);
        $internal = 0;
        $external = 0;
        $error_4xx = 0;
        $error_5xx = 0;

        foreach ($results['results'] as $row) {
            if ($row['link_type'] === 'internal') {
                $internal++;
            } else {
                $external++;
            }

            if ($row['error_type'] === '4xx') {
                $error_4xx++;
            } elseif ($row['error_type'] === '5xx') {
                $error_5xx++;
            }
        }

        $html = '
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #0073aa; color: white; padding: 20px; text-align: center; }
                .summary { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .stat { display: inline-block; margin: 10px 20px; }
                .stat-label { font-weight: bold; color: #666; }
                .stat-value { font-size: 24px; color: #0073aa; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th { background: #0073aa; color: white; padding: 10px; text-align: left; }
                td { padding: 8px; border-bottom: 1px solid #ddd; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Broken Links Report</h1>
                <p>' . get_bloginfo('name') . '</p>
            </div>
            
            <div class="summary">
                <h2>Summary</h2>
                <div class="stat">
                    <div class="stat-label">Total Broken Links</div>
                    <div class="stat-value">' . $total . '</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Internal Links</div>
                    <div class="stat-value">' . $internal . '</div>
                </div>
                <div class="stat">
                    <div class="stat-label">External Links</div>
                    <div class="stat-value">' . $external . '</div>
                </div>
                <div class="stat">
                    <div class="stat-label">4xx Errors</div>
                    <div class="stat-value">' . $error_4xx . '</div>
                </div>
                <div class="stat">
                    <div class="stat-label">5xx Errors</div>
                    <div class="stat-value">' . $error_5xx . '</div>
                </div>
            </div>
            
            <p><strong>Scan ID:</strong> ' . esc_html($scan_id) . '<br>
            <strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
            
            <p>Please log in to your WordPress dashboard to view detailed results and apply fixes.</p>
            
            <div class="footer">
                <p>This report was generated by SEO AutoFix Pro - Broken URL Management Module</p>
            </div>
        </body>
        </html>';

        return $html;
    }

    /**
     * Export activity log to CSV - FIXED/DELETED links only
     * 
     * @param string $scan_id Scan ID
     * @return bool Success
     */
    public function export_activity_log_csv($scan_id)
    {
        global $wpdb;
        $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';
        
        \SEOAutoFix_Debug_Logger::log('[EXPORT ACTIVITY LOG] Starting export for scan_id: ' . $scan_id);
        \SEOAutoFix_Debug_Logger::log('[EXPORT ACTIVITY LOG] Table name: ' . $table_activity);
        
        // Get all activity for this scan
        $query = $wpdb->prepare(
            "SELECT * FROM {$table_activity} WHERE scan_id = %s ORDER BY created_at DESC",
            $scan_id
        );
        
        \SEOAutoFix_Debug_Logger::log('[EXPORT ACTIVITY LOG] Query: ' . $query);
        
        $activities = $wpdb->get_results($query, ARRAY_A);
        
        \SEOAutoFix_Debug_Logger::log('[EXPORT ACTIVITY LOG] Found ' . count($activities) . ' activity entries');
        
        if (!empty($activities)) {
            \SEOAutoFix_Debug_Logger::log('[EXPORT ACTIVITY LOG] First entry: ' . print_r($activities[0], true));
        }

        if (empty($activities)) {
            \SEOAutoFix_Debug_Logger::log('[EXPORT ACTIVITY LOG] No activities found, returning false');
            return false;
        }

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="fixed-links-' . $scan_id . '-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // CSV Headers
        fputcsv($output, array(
            'Page Title',
            'Page URL',
            'Broken URL',
            'Replacement URL',
            'Action',
            'Date/Time'
        ));

        // CSV Data
        foreach ($activities as $row) {
            fputcsv($output, array(
                $row['page_title'],
                $row['page_url'],
                $row['broken_url'],
                $row['replacement_url'] ?? 'N/A',
                ucfirst($row['action_type']),
                $row['created_at']
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Email activity log as CSV attachment
     * Automatically sends to WordPress admin email
     * 
     * @param string $scan_id Scan ID
     * @return array Result
     */
    public function email_activity_log($scan_id)
    {
        global $wpdb;
        $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';
        
        \SEOAutoFix_Debug_Logger::log('[EMAIL ACTIVITY LOG] Starting for scan_id: ' . $scan_id);
        
        // Get all activity for this scan
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_activity} WHERE scan_id = %s ORDER BY created_at DESC",
            $scan_id
        ), ARRAY_A);

        if (empty($activities)) {
            \SEOAutoFix_Debug_Logger::log('[EMAIL ACTIVITY LOG] No activities found');
            return array(
                'success' => false,
                'message' => __('No fixed links to export', 'seo-autofix-pro')
            );
        }

        \SEOAutoFix_Debug_Logger::log('[EMAIL ACTIVITY LOG] Found ' . count($activities) . ' activities');

        // Generate CSV content
        $csv_content = '';
        $csv_content .= chr(0xEF) . chr(0xBB) . chr(0xBF); // BOM

        // Headers
        $headers = array('Page Title', 'Page URL', 'Broken URL', 'Replacement URL', 'Action', 'Date/Time');
        $csv_content .= '"' . implode('","', $headers) . '"' . "\n";

        // Data
        foreach ($activities as $row) {
            $data = array(
                $row['page_title'],
                $row['page_url'],
                $row['broken_url'],
                $row['replacement_url'] ?? 'N/A',
                ucfirst($row['action_type']),
                $row['created_at']
            );
            $csv_content .= '"' . implode('","', array_map(function($d) { 
                return str_replace('"', '""', $d ?: ''); 
            }, $data)) . '"' . "\n";
        }

        // Get admin email automatically
        $admin_email = get_option('admin_email');
        \SEOAutoFix_Debug_Logger::log('[EMAIL ACTIVITY LOG] Sending to admin email: ' . $admin_email);

        // Email subject
        $subject = 'Broken Links Fixed Report - ' . get_bloginfo('name');

        // Email message with nice template
        $message = "Hi,\n\n";
        $message .= "Attached is the broken links activity report for " . get_bloginfo('name') . ".\n\n";
        $message .= "This report contains all the changes you made to broken links:\n";
        $message .= "- Fixed links: Links that were auto-fixed\n";
        $message .= "- Replaced links: Links you manually replaced\n";
        $message .= "- Deleted links: Links you removed from content\n\n";
        $message .= "Total actions performed: " . count($activities) . "\n\n";
        $message .= "Scan ID: " . $scan_id . "\n";
        $message .= "Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        $message .= "Thank you for using SEO AutoFix Pro!";

        // Create temporary file for attachment
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/fixed-links-' . $scan_id . '-' . time() . '.csv';
        
        $written = file_put_contents($temp_file, $csv_content);
        
        if (!$written) {
            \SEOAutoFix_Debug_Logger::log('[EMAIL ACTIVITY LOG] Failed to create temp file');
            return array(
                'success' => false,
                'message' => __('Failed to create CSV file', 'seo-autofix-pro')
            );
        }

        \SEOAutoFix_Debug_Logger::log('[EMAIL ACTIVITY LOG] Temp file created: ' . $temp_file);

        // Send email
        $sent = wp_mail($admin_email, $subject, $message, '', array($temp_file));

        // Clean up temp file
        if (file_exists($temp_file)) {
            unlink($temp_file);
            \SEOAutoFix_Debug_Logger::log('[EMAIL ACTIVITY LOG] Temp file deleted');
        }

        if ($sent) {
            \SEOAutoFix_Debug_Logger::log('[EMAIL ACTIVITY LOG] Email sent successfully');
            return array(
                'success' => true,
                'message' => sprintf(__('Report sent to %s', 'seo-autofix-pro'), $admin_email)
            );
        } else {
            \SEOAutoFix_Debug_Logger::log('[EMAIL ACTIVITY LOG] Failed to send email');
            
            // Check if localhost
            $is_localhost = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
            
            if ($is_localhost) {
                return array(
                    'success' => false,
                    'message' => __('Email failed: XAMPP/localhost has no mail server. Email will work on production server with proper mail configuration.', 'seo-autofix-pro')
                );
            } else {
                return array(
                    'success' => false,
                    'message' => __('Failed to send email. Check WordPress mail configuration or use SMTP plugin.', 'seo-autofix-pro')
                );
            }
        }
    }
}
