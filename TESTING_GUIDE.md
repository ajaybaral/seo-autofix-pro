# Running PHPUnit Tests in XAMPP - Complete Guide

## Prerequisites

Before running tests, you need to set up PHPUnit and WordPress test environment in your XAMPP installation.

---

## Step 1: Install Composer (if not already installed)

1. **Download Composer**:
   - Visit: https://getcomposer.org/download/
   - Download `Composer-Setup.exe` for Windows

2. **Install Composer**:
   - Run the installer
   - When asked for PHP location, point to: `C:\xampp\php\php.exe`
   - Complete the installation

3. **Verify Installation**:
   ```bash
   composer --version
   ```

---

## Step 2: Install PHPUnit via Composer

1. **Navigate to your plugin directory**:
   ```bash
   cd d:\seo-autofix-pro
   ```

2. **Create `composer.json` file** (if it doesn't exist):
   ```bash
   composer init
   ```
   - Follow the prompts (you can accept defaults)

3. **Install PHPUnit**:
   ```bash
   composer require --dev phpunit/phpunit ^9.0
   ```

4. **Verify PHPUnit installation**:
   ```bash
   vendor\bin\phpunit --version
   ```

---

## Step 3: Set Up WordPress Test Environment

### 3.1 Install WordPress Test Suite

1. **Create a test database**:
   - Open phpMyAdmin: http://localhost/phpmyadmin
   - Create new database: `wordpress_test`
   - User: `root`
   - Password: (leave empty for XAMPP default)

2. **Download WordPress test suite installation script**:
   
   Create file: `d:\seo-autofix-pro\bin\install-wp-tests.sh`
   
   ```bash
   #!/usr/bin/env bash
   
   if [ $# -lt 3 ]; then
       echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
       exit 1
   fi
   
   DB_NAME=$1
   DB_USER=$2
   DB_PASS=$3
   DB_HOST=${4-localhost}
   WP_VERSION=${5-latest}
   SKIP_DB_CREATE=${6-false}
   
   TMPDIR=${TMPDIR-/tmp}
   TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
   WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
   WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress/}
   
   download() {
       if [ `which curl` ]; then
           curl -s "$1" > "$2";
       elif [ `which wget` ]; then
           wget -nv -O "$2" "$1"
       fi
   }
   
   if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
       WP_TESTS_TAG="branches/$WP_VERSION"
   elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
       if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
           WP_TESTS_TAG="tags/${WP_VERSION%??}"
       else
           WP_TESTS_TAG="tags/$WP_VERSION"
       fi
   elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
       WP_TESTS_TAG="trunk"
   else
       WP_TESTS_TAG="tags/$WP_VERSION"
   fi
   
   set -ex
   
   install_wp() {
       if [ -d $WP_CORE_DIR ]; then
           return;
       fi
       
       mkdir -p $WP_CORE_DIR
       
       if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
           mkdir -p $TMPDIR/wordpress-nightly
           download https://wordpress.org/nightly-builds/wordpress-latest.zip  $TMPDIR/wordpress-nightly/wordpress-nightly.zip
           unzip -q $TMPDIR/wordpress-nightly/wordpress-nightly.zip -d $TMPDIR/wordpress-nightly/
           mv $TMPDIR/wordpress-nightly/wordpress/* $WP_CORE_DIR
       else
           if [ $WP_VERSION == 'latest' ]; then
               local ARCHIVE_NAME='latest'
           elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
               local ARCHIVE_NAME="wordpress-$WP_VERSION"
           else
               local ARCHIVE_NAME="wordpress-${WP_VERSION%??}"
           fi
           download https://wordpress.org/${ARCHIVE_NAME}.tar.gz  $TMPDIR/wordpress.tar.gz
           tar --strip-components=1 -zxmf $TMPDIR/wordpress.tar.gz -C $WP_CORE_DIR
       fi
       
       download https://raw.github.com/markoheijnen/wp-mysqli/master/db.php $WP_CORE_DIR/wp-content/db.php
   }
   
   install_test_suite() {
       if [ -d $WP_TESTS_DIR ]; then
           return;
       fi
       
       mkdir -p $WP_TESTS_DIR
       svn co --quiet https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ $WP_TESTS_DIR/includes
       svn co --quiet https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ $WP_TESTS_DIR/data
       
       if [ ! -f wp-tests-config.php ]; then
           download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
           WP_CORE_DIR_ESCAPED=$(echo $WP_CORE_DIR | sed 's:/:\\/:g')
           sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR_ESCAPED':" "$WP_TESTS_DIR"/wp-tests-config.php
           sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
           sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
           sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
           sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
       fi
   }
   
   install_db() {
       if [ ${SKIP_DB_CREATE} = "true" ]; then
           return 0
       fi
       
       EXTRA=""
       
       if ! [ -z $DB_HOSTNAME ] ; then
           EXTRA=" --host=$DB_HOSTNAME --protocol=tcp --port=$DB_PORT --socket=$DB_SOCK"
       fi
       
       mysql --user="$DB_USER" --password="$DB_PASS"$EXTRA --execute="CREATE DATABASE IF NOT EXISTS $DB_NAME"
       mysql --user="$DB_USER" --password="$DB_PASS"$EXTRA --execute="GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
   }
   
   install_wp
   install_test_suite
   install_db
   ```

### 3.2 For Windows Users (Easier Alternative)

Since you're using XAMPP on Windows, use this PowerShell script instead:

Create file: `d:\seo-autofix-pro\install-wp-tests.ps1`

```powershell
# WordPress Test Suite Installation for Windows/XAMPP
param(
    [string]$DbName = "wordpress_test",
    [string]$DbUser = "root",
    [string]$DbPass = "",
    [string]$DbHost = "localhost"
)

$TmpDir = $env:TEMP
$WpTestsDir = "$TmpDir\wordpress-tests-lib"
$WpCoreDir = "$TmpDir\wordpress"

Write-Host "Installing WordPress Test Suite..." -ForegroundColor Green

# Create test database
Write-Host "Creating test database..." -ForegroundColor Yellow
$mysqlPath = "C:\xampp\mysql\bin\mysql.exe"
& $mysqlPath -u$DbUser -p$DbPass -e "CREATE DATABASE IF NOT EXISTS $DbName"

# Download WordPress
if (!(Test-Path $WpCoreDir)) {
    Write-Host "Downloading WordPress..." -ForegroundColor Yellow
    New-Item -ItemType Directory -Force -Path $WpCoreDir | Out-Null
    $wpZip = "$TmpDir\wordpress.zip"
    Invoke-WebRequest -Uri "https://wordpress.org/latest.zip" -OutFile $wpZip
    Expand-Archive -Path $wpZip -DestinationPath $TmpDir -Force
}

# Download test suite
if (!(Test-Path $WpTestsDir)) {
    Write-Host "Downloading WordPress test suite..." -ForegroundColor Yellow
    New-Item -ItemType Directory -Force -Path "$WpTestsDir\includes" | Out-Null
    New-Item -ItemType Directory -Force -Path "$WpTestsDir\data" | Out-Null
    
    # Download test files
    Invoke-WebRequest -Uri "https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/" -OutFile "$WpTestsDir\includes"
    Invoke-WebRequest -Uri "https://develop.svn.wordpress.org/trunk/tests/phpunit/data/" -OutFile "$WpTestsDir\data"
}

# Create wp-tests-config.php
$configContent = @"
<?php
define( 'DB_NAME', '$DbName' );
define( 'DB_USER', '$DbUser' );
define( 'DB_PASSWORD', '$DbPass' );
define( 'DB_HOST', '$DbHost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

`$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

define( 'ABSPATH', '$($WpCoreDir.Replace('\', '\\'))\\' );
"@

$configContent | Out-File -FilePath "$WpTestsDir\wp-tests-config.php" -Encoding UTF8

Write-Host "WordPress Test Suite installed successfully!" -ForegroundColor Green
Write-Host "WP_TESTS_DIR: $WpTestsDir" -ForegroundColor Cyan
```

**Run the PowerShell script**:
```powershell
cd d:\seo-autofix-pro
powershell -ExecutionPolicy Bypass -File .\install-wp-tests.ps1
```

---

## Step 4: Create Bootstrap File

Create file: `d:\seo-autofix-pro\tests\bootstrap.php`

```php
<?php
/**
 * PHPUnit bootstrap file for SEO AutoFix Pro
 */

// Get WordPress test environment
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find $_tests_dir/includes/functions.php\n";
    exit( 1 );
}

// Give access to tests_add_filter() function
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested
 */
function _manually_load_plugin() {
    // Load your plugin here
    require dirname( dirname( __FILE__ ) ) . '/seo-autofix-pro.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment
require $_tests_dir . '/includes/bootstrap.php';
```

---

## Step 5: Create PHPUnit Configuration

Create file: `d:\seo-autofix-pro\phpunit.xml`

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
    >
    <testsuites>
        <testsuite name="Broken URL Management">
            <directory prefix="test-" suffix=".php">./modules/broken-url-management/tests/</directory>
        </testsuite>
    </testsuites>
    <filter>
        <whitelist>
            <directory suffix=".php">./modules/</directory>
            <exclude>
                <directory suffix=".php">./modules/*/tests/</directory>
            </exclude>
        </whitelist>
    </filter>
</phpunit>
```

---

## Step 6: Run the Tests

### Option A: Run All Tests
```bash
cd d:\seo-autofix-pro
vendor\bin\phpunit
```

### Option B: Run Specific Test File
```bash
vendor\bin\phpunit modules\broken-url-management\tests\test-broken-url-management.php
```

### Option C: Run Specific Test Method
```bash
vendor\bin\phpunit --filter test_create_tables
```

### Option D: Run with Verbose Output
```bash
vendor\bin\phpunit --verbose
```

### Option E: Run with Code Coverage
```bash
vendor\bin\phpunit --coverage-html coverage
```

---

## Step 7: Simplified Manual Testing (Without PHPUnit)

If you want to test without setting up PHPUnit, create a simple test page:

Create file: `d:\seo-autofix-pro\manual-test.php`

```php
<?php
/**
 * Manual Testing Script
 * Access via: http://localhost/wordpress/wp-content/plugins/seo-autofix-pro/manual-test.php
 */

// Load WordPress
require_once('../../../wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Unauthorized');
}

echo '<h1>SEO AutoFix Pro - Manual Tests</h1>';
echo '<style>
    body { font-family: Arial; padding: 20px; }
    .test { margin: 20px 0; padding: 15px; border: 1px solid #ddd; }
    .pass { background: #d4edda; border-color: #c3e6cb; }
    .fail { background: #f8d7da; border-color: #f5c6cb; }
</style>';

// Test 1: Database Tables
echo '<div class="test">';
echo '<h2>Test 1: Database Tables Exist</h2>';
global $wpdb;
$tables = array(
    $wpdb->prefix . 'seoautofix_broken_links_scans',
    $wpdb->prefix . 'seoautofix_broken_links_scan_results',
    $wpdb->prefix . 'seoautofix_broken_links_fixes_history'
);

$all_exist = true;
foreach ($tables as $table) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    if ($exists) {
        echo "✓ Table $table exists<br>";
    } else {
        echo "✗ Table $table NOT found<br>";
        $all_exist = false;
    }
}
echo '</div>';

// Test 2: Classes Loaded
echo '<div class="test">';
echo '<h2>Test 2: Classes Loaded</h2>';
$classes = array(
    'Database_Manager',
    'Link_Crawler',
    'Link_Tester',
    'Occurrences_Manager',
    'Fix_Plan_Manager',
    'History_Manager',
    'Export_Manager'
);

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "✓ Class $class loaded<br>";
    } else {
        echo "✗ Class $class NOT found<br>";
    }
}
echo '</div>';

// Test 3: Create and Insert Test Data
echo '<div class="test">';
echo '<h2>Test 3: Database Operations</h2>';
try {
    $db_manager = new Database_Manager();
    $scan_id = $db_manager->create_scan();
    echo "✓ Created scan with ID: $scan_id<br>";
    
    $result_id = $db_manager->insert_scan_result(array(
        'scan_id' => $scan_id,
        'broken_url' => 'https://example.com/test',
        'status_code' => 404,
        'error_type' => '4xx'
    ));
    echo "✓ Inserted scan result with ID: $result_id<br>";
    
    // Cleanup
    $wpdb->delete($wpdb->prefix . 'seoautofix_broken_links_scan_results', array('id' => $result_id));
    $wpdb->delete($wpdb->prefix . 'seoautofix_broken_links_scans', array('id' => $scan_id));
    echo "✓ Cleanup successful<br>";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "<br>";
}
echo '</div>';

echo '<p><strong>All basic tests completed!</strong></p>';
```

**Access the manual test**:
```
http://localhost/wordpress/wp-content/plugins/seo-autofix-pro/manual-test.php
```

---

## Troubleshooting

### Issue: "composer: command not found"
**Solution**: Add Composer to PATH or use full path:
```bash
C:\ProgramData\ComposerSetup\bin\composer
```

### Issue: "Could not find wordpress-tests-lib"
**Solution**: Set environment variable:
```bash
set WP_TESTS_DIR=C:\Users\YourUser\AppData\Local\Temp\wordpress-tests-lib
```

### Issue: "Database connection error"
**Solution**: 
1. Make sure XAMPP MySQL is running
2. Verify database credentials in `wp-tests-config.php`
3. Check if test database exists in phpMyAdmin

### Issue: "Class not found"
**Solution**: Make sure your plugin is activated in WordPress

---

## Quick Start (Recommended for XAMPP)

If you just want to test quickly without full PHPUnit setup:

1. **Use the manual test script** (Step 7 above)
2. **Test via WordPress admin**:
   - Activate the plugin
   - Go to SEO AutoFix > Broken URLs
   - Click "Start New Scan"
   - Verify results appear

3. **Check browser console** for JavaScript errors:
   - Press F12
   - Go to Console tab
   - Look for any errors

---

## Summary

**For Full Automated Testing**:
1. Install Composer
2. Install PHPUnit
3. Set up WordPress test environment
4. Run: `vendor\bin\phpunit`

**For Quick Manual Testing**:
1. Create `manual-test.php`
2. Access via browser
3. Check results

Choose the approach that best fits your needs!
