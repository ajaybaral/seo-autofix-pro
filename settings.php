<?php
/**
 * Global Settings Page
 * 
 * This is the ONLY shared file across all modules.
 * Contains: OpenAI API Key, Model Selection, License Key (Phase 2)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO AutoFix Pro - Global Settings
 */
class SEOAutoFix_Settings {
    
    /**
     * Settings option name
     */
    const OPTION_API_KEY = 'seoautofix_openai_api_key';
    const OPTION_MODEL = 'seoautofix_openai_model';
    const OPTION_LICENSE_KEY = 'seoautofix_license_key';
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }
    
    /**
     * Add settings page to WordPress admin
     */
    public function add_settings_page() {
        add_menu_page(
            __('SEO AutoFix Pro', 'seo-autofix-pro'),
            __('SEO AutoFix Pro', 'seo-autofix-pro'),
            'manage_options',
            'seoautofix-settings',
            array($this, 'render_settings_page'),
            'dashicons-admin-tools',
            30
        );
        
        // Add submenu item for settings
        add_submenu_page(
            'seoautofix-settings',
            __('Settings', 'seo-autofix-pro'),
            __('Settings', 'seo-autofix-pro'),
            'manage_options',
            'seoautofix-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register settings
        register_setting('seoautofix_settings', self::OPTION_API_KEY, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_api_key'),
            'default' => ''
        ));
        
        register_setting('seoautofix_settings', self::OPTION_MODEL, array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gpt-4o'
        ));
        
        register_setting('seoautofix_settings', self::OPTION_LICENSE_KEY, array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        // Add settings section
        add_settings_section(
            'seoautofix_openai_section',
            __('OpenAI Configuration', 'seo-autofix-pro'),
            array($this, 'render_openai_section'),
            'seoautofix-settings'
        );
        
        // Add API Key field
        add_settings_field(
            'seoautofix_api_key',
            __('OpenAI API Key', 'seo-autofix-pro'),
            array($this, 'render_api_key_field'),
            'seoautofix-settings',
            'seoautofix_openai_section'
        );
        
        // Add Model Selection field
        add_settings_field(
            'seoautofix_model',
            __('AI Model', 'seo-autofix-pro'),
            array($this, 'render_model_field'),
            'seoautofix-settings',
            'seoautofix_openai_section'
        );
    }
    
    /**
     * Sanitize API key
     */
    public function sanitize_api_key($value) {
        $value = sanitize_text_field($value);
        
        // Validate API key format (starts with sk-)
        if (!empty($value) && !preg_match('/^sk-[a-zA-Z0-9\-_]+$/', $value)) {
            add_settings_error(
                self::OPTION_API_KEY,
                'invalid_api_key',
                __('Invalid API key format. OpenAI API keys start with "sk-".', 'seo-autofix-pro'),
                'error'
            );
            return get_option(self::OPTION_API_KEY);
        }
        
        return $value;
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        // Only load on settings page
        if ($hook !== 'toplevel_page_seoautofix-settings') {
            return;
        }
        
        wp_enqueue_style(
            'seoautofix-settings',
            SEOAUTOFIX_PLUGIN_URL . 'assets/css/settings.css',
            array(),
            SEOAUTOFIX_VERSION
        );
        
        wp_enqueue_script(
            'seoautofix-settings',
            SEOAUTOFIX_PLUGIN_URL . 'assets/js/settings.js',
            array('jquery'),
            SEOAUTOFIX_VERSION,
            true
        );
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check for settings saved message
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'seoautofix_messages',
                'seoautofix_message',
                __('Settings saved successfully.', 'seo-autofix-pro'),
                'updated'
            );
        }
        
        settings_errors('seoautofix_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('seoautofix_settings');
                do_settings_sections('seoautofix-settings');
                submit_button(__('Save Settings', 'seo-autofix-pro'));
                ?>
            </form>
            
            <div class="seoautofix-settings-info">
                <h2><?php _e('Getting Started', 'seo-autofix-pro'); ?></h2>
                <ol>
                    <li><?php _e('Get your OpenAI API key from', 'seo-autofix-pro'); ?> <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a></li>
                    <li><?php _e('Enter your API key above', 'seo-autofix-pro'); ?></li>
                    <li><?php _e('Select your preferred AI model', 'seo-autofix-pro'); ?></li>
                    <li><?php _e('Navigate to individual modules to start optimizing your SEO', 'seo-autofix-pro'); ?></li>
                </ol>
                
                <p><strong><?php _e('Note:', 'seo-autofix-pro'); ?></strong> <?php _e('Your API key is stored securely and never transmitted to any server except OpenAI.', 'seo-autofix-pro'); ?></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render OpenAI section description
     */
    public function render_openai_section() {
        echo '<p>' . __('Configure your OpenAI API settings. These settings are used by all modules.', 'seo-autofix-pro') . '</p>';
    }
    
    /**
     * Render API key field
     */
    public function render_api_key_field() {
        $api_key = get_option(self::OPTION_API_KEY, '');
        $masked_key = $this->mask_api_key($api_key);
        ?>
        <input 
            type="password" 
            id="seoautofix_api_key" 
            name="<?php echo esc_attr(self::OPTION_API_KEY); ?>" 
            value="<?php echo esc_attr($api_key); ?>" 
            class="regular-text"
            placeholder="sk-..."
        />
        <button type="button" class="button" id="toggle-api-key">
            <?php _e('Show', 'seo-autofix-pro'); ?>
        </button>
        <?php if (!empty($api_key)) : ?>
            <p class="description">
                <?php _e('Current key:', 'seo-autofix-pro'); ?> <?php echo esc_html($masked_key); ?>
            </p>
        <?php else : ?>
            <p class="description">
                <?php _e('Enter your OpenAI API key. You can get one from', 'seo-autofix-pro'); ?> 
                <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>
            </p>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Render model selection field
     */
    public function render_model_field() {
        $current_model = get_option(self::OPTION_MODEL, 'gpt-4o');
        $models = array(
            'gpt-4o' => __('GPT-4o (Recommended) - Best quality, fast, cost-effective', 'seo-autofix-pro'),
            'gpt-4-turbo' => __('GPT-4 Turbo - High quality, balanced performance', 'seo-autofix-pro'),
            'gpt-3.5-turbo' => __('GPT-3.5 Turbo - Faster, cheaper, good quality', 'seo-autofix-pro'),
        );
        
        foreach ($models as $value => $label) {
            ?>
            <label style="display: block; margin-bottom: 10px;">
                <input 
                    type="radio" 
                    name="<?php echo esc_attr(self::OPTION_MODEL); ?>" 
                    value="<?php echo esc_attr($value); ?>"
                    <?php checked($current_model, $value); ?>
                />
                <?php echo esc_html($label); ?>
            </label>
            <?php
        }
        ?>
        <p class="description">
            <?php _e('Select the AI model to use for generating SEO content. GPT-4o is recommended for best results.', 'seo-autofix-pro'); ?>
        </p>
        <?php
    }
    
    /**
     * Mask API key for display
     */
    private function mask_api_key($key) {
        if (empty($key)) {
            return '';
        }
        
        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }
        
        return substr($key, 0, 7) . str_repeat('*', $length - 11) . substr($key, -4);
    }
    
    /**
     * Get API key
     */
    public static function get_api_key() {
        return get_option(self::OPTION_API_KEY, '');
    }
    
    /**
     * Get model
     */
    public static function get_model() {
        return get_option(self::OPTION_MODEL, 'gpt-4o');
    }
    
    /**
     * Check if API key is configured
     */
    public static function is_api_configured() {
        $api_key = self::get_api_key();
        return !empty($api_key);
    }
}

// Initialize settings on plugins_loaded hook
add_action('plugins_loaded', function() {
    new SEOAutoFix_Settings();
});
