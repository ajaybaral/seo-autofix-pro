    }
    
    /**
     * AJAX: Get count of unused images
     */
    public function ajax_get_unused_count() {

        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $unused_images = $this->get_unused_images();
        $count = count($unused_images);
        

        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * AJAX: Create ZIP of unused images
     */
    public function ajax_create_unused_zip() {

        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $unused_images = $this->get_unused_images();
        
        if (empty($unused_images)) {
            wp_send_json_error(array('message' => 'No unused images found'));
        }
        
        // Create ZIP
        $upload_dir = wp_upload_dir();
        $zip_filename = 'unused-images-' . date('Y-m-d-His') . '.zip';
        $zip_path = $upload_dir['path'] . '/' . $zip_filename;
        

        
        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE) !== TRUE) {

            wp_send_json_error(array('message' => 'Failed to create ZIP file'));
        }
        
        $added_count = 0;
        foreach ($unused_images as $img_id) {
            $file_path = get_attached_file($img_id);
            if ($file_path && file_exists($file_path)) {
                $zip->addFile($file_path, basename($file_path));
                $added_count++;
            }
        }
        
        $zip->close();
        

        
        $zip_url = $upload_dir['url'] . '/' . $zip_filename;
        
        wp_send_json_success(array(
            'zip_url' => $zip_url,
            'zip_filename' => $zip_filename,
            'image_count' => $added_count
        ));
    }
    
    /**
     * AJAX: Bulk delete unused images
     */
    public function ajax_bulk_delete_unused() {

        check_ajax_referer('imageseo_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $unused_images = $this->get_unused_images();
        
        if (empty($unused_images)) {

            wp_send_json_success(array('deleted_count' => 0));
            return;
        }
        
        $deleted_count = 0;
        foreach ($unused_images as $img_id) {

            $result = wp_delete_attachment($img_id, true);
            if ($result) {
                $deleted_count++;
            }
        }
        

        wp_send_json_success(array('deleted_count' => $deleted_count));
    }
    
    /**
     * Get all unused images
     */
    private function get_unused_images() {

        
        global $wpdb;
        $table_name = $wpdb->prefix . 'seoautofix_image_history';
        
        // Get all images from history
        $all_images = $wpdb->get_col("SELECT DISTINCT attachment_id FROM $table_name");
        

        
        $unused_images = array();
        
        foreach ($all_images as $img_id) {
            // Check if attachment still exists
            if (!get_post($img_id)) {
                continue;
            }
            
            // Check usage via Image_Usage_Tracker
            $usage = $this->usage_tracker->get_image_usage($img_id);
            
            if ($usage['used_in_posts'] == 0 && $usage['used_in_pages'] == 0) {
                $unused_images[] = $img_id;

            }
        }
        

        
        return $unused_images;
    }
}
