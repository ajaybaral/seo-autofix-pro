# SEO AutoFix Pro - Plugin Architecture Rules

## Core Design Principles

### 1. **Modular Architecture**
All functionality MUST be organized into self-contained modules within the `modules/` directory. Each module is a complete, independent unit that can function without affecting other modules.

### 2. **Module Structure**
Every module MUST follow this directory structure:

```
modules/
└── [module-name]/
    ├── class-[module-name].php          # Main module class (required)
    ├── class-*.php                      # Additional module-specific classes
    ├── assets/                          # Module-specific assets
    │   ├── css/
    │   ├── js/
    │   └── images/
    └── views/                           # Module-specific view templates
        └── admin-page.php
```

### 3. **Module Independence Rules**

#### **CRITICAL: Complete Module Isolation**
- ✅ Each module MUST be completely self-contained
- ✅ All assets (CSS, JS, images) MUST be stored within the module's folder
- ✅ All views/templates MUST be stored within the module's folder
- ✅ All PHP classes MUST be within the module's folder
- ✅ Module-specific database tables MUST use the module name in the table name
- ❌ Modules MUST NOT modify files outside their own directory
- ❌ Modules MUST NOT directly access files from other modules
- ❌ Modules MUST NOT share code between each other (except through global settings)

#### **Shared Resources (The ONLY Exceptions)**
Modules MAY ONLY access these shared resources:
1. **API Credentials** from `settings.php`:
   - OpenAI API Key: `SEOAutoFix_Settings::get_api_key()`
   - AI Model Selection: `SEOAutoFix_Settings::get_model()`
   - API Configuration Check: `SEOAutoFix_Settings::is_api_configured()`

2. **WordPress Core Functions** (standard WordPress APIs)

3. **Plugin Constants**:
   - `SEOAUTOFIX_VERSION`
   - `SEOAUTOFIX_PLUGIN_FILE`
   - `SEOAUTOFIX_PLUGIN_DIR`
   - `SEOAUTOFIX_PLUGIN_URL`
   - `SEOAUTOFIX_PLUGIN_BASENAME`

### 4. **Module Naming Conventions**

#### **File Naming**
- Module folder: `kebab-case` (e.g., `image-seo`, `broken-url-management`)
- Main class file: `class-[module-name].php` (e.g., `class-image-seo.php`)
- Additional class files: `class-[descriptive-name].php`
- View files: `[descriptive-name].php`

#### **Class Naming**
- Namespace: `SEOAutoFix\[ModuleName]` (e.g., `SEOAutoFix\ImageSEO`)
- Main class: `SEOAutoFix_[Module_Name]` (e.g., `SEOAutoFix_Image_SEO`)
- Additional classes: `SEOAutoFix_[Module_Name]_[Component]`

#### **Database Table Naming**
- Pattern: `{prefix}seoautofix_[module_name]_[table_purpose]`
- Example: `wp_seoautofix_image_seo_history`
- Example: `wp_seoautofix_broken_links_scan_results`

### 5. **Module Loading**
- Modules are auto-loaded by the main plugin class
- Module's main class file MUST be named `class-[module-name].php`
- Module's main class MUST follow the naming convention
- Module initialization happens automatically if the class exists

### 6. **WordPress Admin Integration**

#### **Menu Structure**
- Each module MAY add its own submenu page under "SEO AutoFix Pro"
- Use `add_submenu_page()` with parent slug: `'seoautofix-settings'`
- Menu capability SHOULD be `'manage_options'` unless specific permissions needed

#### **Asset Enqueueing**
- Modules MUST enqueue their own assets
- Use proper hooks: `admin_enqueue_scripts` for admin, `wp_enqueue_scripts` for frontend
- MUST check current page/hook before enqueueing
- Use versioning for cache busting: `SEOAUTOFIX_VERSION`

### 7. **AJAX & API Endpoints**

#### **AJAX Actions**
- Prefix all AJAX actions with module name: `wp_ajax_seoautofix_[module]_[action]`
- Example: `wp_ajax_seoautofix_image_seo_scan_images`
- Always include nonce verification
- Always include capability checks

#### **REST API**
- If using REST API, namespace with: `seoautofix/v1/[module-name]`
- Example: `seoautofix/v1/broken-links`

### 8. **Database Design**

#### **Schema Management**
- Each module MUST manage its own database tables
- Create tables during module activation (use `seoautofix_activated` action)
- Include proper indexes for performance
- Use `$wpdb->prefix` for table prefix

#### **Data Isolation**
- Module data MUST be stored in module-specific tables
- Module data MUST be stored in module-specific options (prefix: `seoautofix_[module]_`)
- Clean up module data on deactivation/uninstall if appropriate

### 9. **Error Handling & Logging**

#### **Logging Standards**
- Each module SHOULD implement its own logger class
- Log files SHOULD be stored in: `wp-content/uploads/seo-autofix-pro/logs/[module-name]/`
- Include timestamps and severity levels
- Rotate logs to prevent excessive file sizes

#### **Error Reporting**
- Use WordPress admin notices for user-facing errors
- Log technical errors to module-specific log files
- Never expose sensitive data (API keys, etc.) in errors

### 10. **Security Best Practices**

- ✅ Always verify nonces for AJAX/form submissions
- ✅ Always check user capabilities
- ✅ Always sanitize input data
- ✅ Always escape output data
- ✅ Use prepared statements for database queries
- ✅ Validate and sanitize file uploads
- ✅ Never trust user input

### 11. **Code Quality Standards**

#### **PHP Standards**
- Follow WordPress Coding Standards
- Use type hints where appropriate (PHP 7.4+)
- Document all public methods with PHPDoc
- Keep methods focused and small
- Use meaningful variable and function names

#### **JavaScript Standards**
- Use modern ES6+ syntax
- Use `const` and `let` instead of `var`
- Document complex functions
- Handle errors gracefully
- Avoid global scope pollution

#### **CSS Standards**
- Prefix module-specific classes: `.seoautofix-[module]-[component]`
- Use BEM methodology where appropriate
- Avoid `!important` unless absolutely necessary
- Mobile-first responsive design

### 12. **Performance Guidelines**

- ✅ Lazy-load assets only on relevant admin pages
- ✅ Implement pagination for large datasets
- ✅ Use transients/caching for expensive operations
- ✅ Optimize database queries (use indexes, limit results)
- ✅ Use AJAX for long-running operations
- ✅ Implement batch processing for bulk operations
- ❌ Never load all data at once
- ❌ Avoid N+1 query problems

### 13. **Development Workflow**

#### **Before Creating a New Module**
1. Define module purpose and scope
2. Plan database schema
3. Design UI/UX mockup
4. Document API requirements
5. Create implementation plan

#### **During Development**
1. Create module folder structure
2. Implement main class with WordPress hooks
3. Create database schema (if needed)
4. Build backend logic and API endpoints
5. Develop frontend UI
6. Add proper error handling and logging
7. Test thoroughly

#### **Testing Checklist**
- [ ] Module loads without errors
- [ ] Database tables created correctly
- [ ] AJAX endpoints work as expected
- [ ] UI displays properly
- [ ] Assets load correctly
- [ ] No conflicts with other modules
- [ ] Security measures in place
- [ ] Performance is acceptable

### 14. **Documentation Requirements**

Each module SHOULD include:
- Module-specific README.md explaining functionality
- Inline code comments for complex logic
- PHPDoc blocks for all classes and public methods
- User-facing documentation for features

---

## Module Examples

### Example 1: Image SEO Module
- **Location**: `modules/image-seo/`
- **Main Class**: `SEOAutoFix\ImageSEO\SEOAutoFix_Image_SEO`
- **Database Tables**: `{prefix}seoautofix_image_seo_history`, `{prefix}seoautofix_image_usage_tracking`
- **Assets**: All CSS/JS in `modules/image-seo/assets/`
- **Views**: Admin page in `modules/image-seo/views/`

### Example 2: Broken URL Management Module
- **Location**: `modules/broken-url-management/`
- **Main Class**: `SEOAutoFix\BrokenUrlManagement\SEOAutoFix_Broken_Url_Management`
- **Database Tables**: `{prefix}seoautofix_broken_links_scan_results`
- **Assets**: All CSS/JS in `modules/broken-url-management/assets/`
- **Views**: Admin page in `modules/broken-url-management/views/`

---

## Important Reminders

> [!WARNING]
> **Module Isolation is Critical**
> Never share code between modules or modify files outside your module's directory. This ensures that modules can be developed, tested, and potentially distributed independently.

> [!IMPORTANT]
> **Shared Settings Access**
> The ONLY shared resource is `settings.php` for API credentials. Use the static methods provided by `SEOAutoFix_Settings` class.

> [!NOTE]
> **Auto-Loading System**
> The main plugin file automatically loads all modules from the `modules/` directory. Simply create your module folder with the correct structure and naming, and it will be loaded automatically.

---

## Version History

- **v1.0** - Initial architecture rules established (2026-01-09)
