// ========================================
// NEW v2.0 AJAX HANDLERS
// ========================================

/**
* AJAX: Get occurrences of a broken URL
*/
public function ajax_get_occurrences() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
}

$broken_url = isset($_GET['broken_url']) ? sanitize_text_field($_GET['broken_url']) : '';
$scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';

if (empty($broken_url) || empty($scan_id)) {
wp_send_json_error(array('message' => __('Missing parameters', 'seo-autofix-pro')));
}

$occurrences_manager = new Occurrences_Manager();
$occurrences = $occurrences_manager->get_occurrences($broken_url, $scan_id);

wp_send_json_success(array(
'occurrences' => $occurrences,
'total' => count($occurrences)
));
}

/**
* AJAX: Bulk fix occurrences
*/
public function ajax_bulk_fix() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
}

$broken_url = isset($_POST['broken_url']) ? sanitize_text_field($_POST['broken_url']) : '';
$replacement_url = isset($_POST['replacement_url']) ? esc_url_raw($_POST['replacement_url']) : '';
$scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';
$occurrence_ids = isset($_POST['occurrence_ids']) ? array_map('intval', (array)$_POST['occurrence_ids']) : array();

if (empty($broken_url) || empty($scan_id)) {
wp_send_json_error(array('message' => __('Missing parameters', 'seo-autofix-pro')));
}

$occurrences_manager = new Occurrences_Manager();
$result = $occurrences_manager->bulk_fix_occurrences($broken_url, $replacement_url, $scan_id, $occurrence_ids);

if ($result['success']) {
wp_send_json_success($result);
} else {
wp_send_json_error($result);
}
}

/**
* AJAX: Group broken links by URL
*/
public function ajax_group_by_url() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
}

$scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';

if (empty($scan_id)) {
wp_send_json_error(array('message' => __('Scan ID required', 'seo-autofix-pro')));
}

$occurrences_manager = new Occurrences_Manager();
$grouped = $occurrences_manager->group_by_broken_url($scan_id);
$stats = $occurrences_manager->get_occurrence_stats($scan_id);

wp_send_json_success(array(
'grouped' => $grouped,
'stats' => $stats
));
}

/**
* AJAX: Generate fix plan
*/
public function ajax_generate_fix_plan() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
}

$entry_ids = isset($_POST['entry_ids']) ? array_map('intval', (array)$_POST['entry_ids']) : array();

if (empty($entry_ids)) {
wp_send_json_error(array('message' => __('No entries selected', 'seo-autofix-pro')));
}

$fix_plan_manager = new Fix_Plan_Manager();
$result = $fix_plan_manager->generate_fix_plan($entry_ids);

if ($result['success']) {
wp_send_json_success($result);
} else {
wp_send_json_error($result);
}
}

/**
* AJAX: Update fix plan entry
*/
public function ajax_update_fix_plan() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
}

$plan_id = isset($_POST['plan_id']) ? sanitize_text_field($_POST['plan_id']) : '';
$entry_id = isset($_POST['entry_id']) ? intval($_POST['entry_id']) : 0;
$new_url = isset($_POST['new_url']) ? esc_url_raw($_POST['new_url']) : '';
$fix_action = isset($_POST['fix_action']) ? sanitize_text_field($_POST['fix_action']) : 'replace';

if (empty($plan_id) || empty($entry_id)) {
wp_send_json_error(array('message' => __('Missing parameters', 'seo-autofix-pro')));
}

$fix_plan_manager = new Fix_Plan_Manager();
$result = $fix_plan_manager->update_fix_plan_entry($plan_id, $entry_id, $new_url, $fix_action);

if ($result['success']) {
wp_send_json_success($result);
} else {
wp_send_json_error($result);
}
}

/**
* AJAX: Apply fix plan
*/
public function ajax_apply_fix_plan() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
}

$plan_id = isset($_POST['plan_id']) ? sanitize_text_field($_POST['plan_id']) : '';
$selected_entry_ids = isset($_POST['selected_entry_ids']) ? array_map('intval', (array)$_POST['selected_entry_ids']) :
array();

if (empty($plan_id)) {
wp_send_json_error(array('message' => __('Plan ID required', 'seo-autofix-pro')));
}

$fix_plan_manager = new Fix_Plan_Manager();
$result = $fix_plan_manager->apply_fix_plan($plan_id, $selected_entry_ids);

if ($result['success']) {
wp_send_json_success($result);
} else {
wp_send_json_error($result);
}
}

/**
* AJAX: Revert fixes
*/
public function ajax_revert_fixes() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
}

$fix_session_id = isset($_POST['fix_session_id']) ? sanitize_text_field($_POST['fix_session_id']) : '';

if (empty($fix_session_id)) {
wp_send_json_error(array('message' => __('Session ID required', 'seo-autofix-pro')));
}

$history_manager = new History_Manager();
$result = $history_manager->revert_session($fix_session_id);

if ($result['success']) {
wp_send_json_success($result);
} else {
wp_send_json_error($result);
}
}

/**
* AJAX: Get fix sessions
*/
public function ajax_get_fix_sessions() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
}

$scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';

if (empty($scan_id)) {
wp_send_json_error(array('message' => __('Scan ID required', 'seo-autofix-pro')));
}

$history_manager = new History_Manager();
$sessions = $history_manager->get_scan_fix_sessions($scan_id);

wp_send_json_success(array(
'sessions' => $sessions,
'total' => count($sessions)
));
}

/**
* AJAX: Export to CSV
*/
public function ajax_export_csv() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_die(__('Unauthorized', 'seo-autofix-pro'));
}

$scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';
$filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';

if (empty($scan_id)) {
wp_die(__('Scan ID required', 'seo-autofix-pro'));
}

$export_manager = new Export_Manager();
$export_manager->export_to_csv($scan_id, $filter);
// Note: export_to_csv() handles output and exit
}

/**
* AJAX: Export to PDF
*/
public function ajax_export_pdf() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_die(__('Unauthorized', 'seo-autofix-pro'));
}

$scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';
$filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';

if (empty($scan_id)) {
wp_die(__('Scan ID required', 'seo-autofix-pro'));
}

$export_manager = new Export_Manager();
$result = $export_manager->export_to_pdf($scan_id, $filter);

if (!$result) {
wp_die(__('PDF export failed. TCPDF library may not be available.', 'seo-autofix-pro'));
}
// Note: export_to_pdf() handles output and exit
}

/**
* AJAX: Email report
*/
public function ajax_email_report() {
check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

if (!current_user_can('manage_options')) {
wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
}

$scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';
$email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
$format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'summary';

if (empty($scan_id) || empty($email)) {
wp_send_json_error(array('message' => __('Missing parameters', 'seo-autofix-pro')));
}

$export_manager = new Export_Manager();
$result = $export_manager->email_report($scan_id, $email, $format);

if ($result['success']) {
wp_send_json_success($result);
} else {
wp_send_json_error($result);
}
}
}

// Module will be auto-instantiated by the WordPress plugin loader