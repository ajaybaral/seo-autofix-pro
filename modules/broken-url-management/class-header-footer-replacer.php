<?php
/**
 * Header/Footer Replacer - URL replacement in site-wide elements
 * 
 * Handles URL replacement in navigation menus, widgets, and theme builder templates.
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 * @since 1.0.0
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Header/Footer Replacer Class
 * 
 * Handles URL replacement in site-wide elements that appear across multiple pages:
 * - Navigation menus (header/footer menus)
 * - Widget areas (sidebars, footers)
 * - Theme builder templates (Elementor header/footer templates)
 */
class Header_Footer_Replacer
{
    /**
     * Universal replacement engine instance
     */
    private $universal_engine;

    /**
     * Statistics tracking
     */
    private $stats = [
        'menu_items_scanned' => 0,
        'menu_items_modified' => 0,
        'widgets_scanned' => 0,
        'widgets_modified' => 0,
        'templates_scanned' => 0,
        'templates_modified' => 0
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->universal_engine = new Universal_Replacement_Engine();
    }

    /**
     * Replace URL in all site-wide elements
     * 
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     * @return array Result with statistics
     */
    public function replace_in_site_wide_elements($old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Starting site-wide URL replacement');
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Old URL: ' . $old_url);
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] New URL: ' . $new_url);

        // Reset stats
        $this->stats = [
            'menu_items_scanned' => 0,
            'menu_items_modified' => 0,
            'widgets_scanned' => 0,
            'widgets_modified' => 0,
            'templates_scanned' => 0,
            'templates_modified' => 0
        ];

        // Step 1: Replace in navigation menus
        $this->replace_in_nav_menus($old_url, $new_url);

        // Step 2: Replace in widgets
        $this->replace_in_widgets($old_url, $new_url);

        // Step 3: Replace in theme builder templates
        $this->replace_in_theme_builder_templates($old_url, $new_url);

        // Log statistics
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] === Site-wide Replacement Statistics ===');
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Menu items scanned: ' . $this->stats['menu_items_scanned']);
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Menu items modified: ' . $this->stats['menu_items_modified']);
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Widgets scanned: ' . $this->stats['widgets_scanned']);
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Widgets modified: ' . $this->stats['widgets_modified']);
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Templates scanned: ' . $this->stats['templates_scanned']);
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Templates modified: ' . $this->stats['templates_modified']);

        $total_modified = $this->stats['menu_items_modified'] + 
                         $this->stats['widgets_modified'] + 
                         $this->stats['templates_modified'];

        return [
            'success' => $total_modified > 0,
            'stats' => $this->stats
        ];
    }

    /**
     * Replace URL in navigation menus
     * 
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     */
    public function replace_in_nav_menus($old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Scanning navigation menus...');

        // Get all nav menu items
        $menu_items = get_posts([
            'post_type' => 'nav_menu_item',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        if (empty($menu_items)) {
            \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] No menu items found');
            return;
        }

        $this->stats['menu_items_scanned'] = count($menu_items);
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Found ' . count($menu_items) . ' menu items');

        foreach ($menu_items as $menu_item) {
            // Get the custom URL from menu item meta
            $menu_url = get_post_meta($menu_item->ID, '_menu_item_url', true);

            if (empty($menu_url)) {
                continue;
            }

            // Check if this URL matches (case-insensitive, with or without trailing slash)
            $normalized_menu_url = untrailingslashit($menu_url);
            $normalized_old_url = untrailingslashit($old_url);

            if (strcasecmp($normalized_menu_url, $normalized_old_url) === 0) {
                \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Found matching URL in menu item ID: ' . $menu_item->ID);
                \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Menu item title: ' . $menu_item->post_title);
                
                // Update the menu item URL
                update_post_meta($menu_item->ID, '_menu_item_url', $new_url);
                $this->stats['menu_items_modified']++;
                
                \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] ✅ Updated menu item URL: ' . $menu_item->post_title);
            }
        }

        if ($this->stats['menu_items_modified'] > 0) {
            // Clear menu caches
            wp_cache_delete('nav_menu_items', 'post_meta');
            \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Cleared menu caches');
        }
    }

    /**
     * Replace URL in widget areas
     * 
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     */
    public function replace_in_widgets($old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Scanning widgets...');

        global $wp_registered_widgets;

        // Get all widget instances organized by widget type
        $widget_types = [];
        
        foreach ($wp_registered_widgets as $widget_id => $widget) {
            if (isset($widget['callback'][0]) && is_object($widget['callback'][0])) {
                $widget_obj = $widget['callback'][0];
                $widget_type = $widget_obj->id_base;
                $widget_types[$widget_type] = true;
            }
        }

        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Found ' . count($widget_types) . ' widget types in use');

        // Process each widget type
        foreach (array_keys($widget_types) as $widget_type) {
            $this->process_widget_type($widget_type, $old_url, $new_url);
        }
    }

    /**
     * Process a specific widget type
     * 
     * @param string $widget_type Widget type ID (e.g., 'text', 'custom_html')
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     */
    private function process_widget_type($widget_type, $old_url, $new_url)
    {
        $option_name = 'widget_' . $widget_type;
        $widget_data = get_option($option_name);

        if (!is_array($widget_data)) {
            return;
        }

        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Processing widget type: ' . $widget_type);

        $modified = false;
        $replacement_count = 0;

        foreach ($widget_data as $instance_id => $instance_data) {
            // Skip _multiwidget flag
            if ($instance_id === '_multiwidget') {
                continue;
            }

            $this->stats['widgets_scanned']++;

            if (!is_array($instance_data)) {
                continue;
            }

            // Use universal engine to replace in widget instance data
            $replaced_data = $this->universal_engine->replace_in_structure_recursive(
                $instance_data, 
                $old_url, 
                $new_url, 
                $replacement_count
            );

            if ($replacement_count > 0) {
                $widget_data[$instance_id] = $replaced_data;
                $modified = true;
                $this->stats['widgets_modified']++;
                
                \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] ✅ Updated widget: ' . $widget_type . ' #' . $instance_id);
            }
        }

        if ($modified) {
            update_option($option_name, $widget_data);
            \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Saved updates to widget type: ' . $widget_type);
        }
    }

    /**
     * Replace URL in theme builder templates
     * 
     * Handles Elementor header/footer templates and similar theme builder elements.
     * 
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     */
    public function replace_in_theme_builder_templates($old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Scanning theme builder templates...');

        // Get Elementor library templates (header, footer, etc.)
        $templates = get_posts([
            'post_type' => 'elementor_library',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);

        if (empty($templates)) {
            \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] No Elementor templates found');
            return;
        }

        $this->stats['templates_scanned'] = count($templates);
        \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Found ' . count($templates) . ' Elementor templates');

        foreach ($templates as $template) {
            // Use universal engine to replace in template
            $result = $this->universal_engine->replace_url_in_post($template->ID, $old_url, $new_url);

            if ($result['success']) {
                $this->stats['templates_modified']++;
                \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] ✅ Updated template: ' . $template->post_title);
            }
        }

        if ($this->stats['templates_modified'] > 0) {
            // Clear Elementor cache if plugin is active
            if (class_exists('\\Elementor\\Plugin')) {
                try {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                    \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Cleared Elementor cache');
                } catch (\Exception $e) {
                    \SEOAutoFix_Debug_Logger::log('[HEADER_FOOTER] Cache clear error: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Get replacement statistics
     * 
     * @return array Statistics array
     */
    public function get_stats()
    {
        return $this->stats;
    }
}
