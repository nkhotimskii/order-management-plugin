<?php
/**
 * Plugin Name: Order Management
 * Description: Order Management (MoySklad JSON API v1.2)
 * Version: 1.0
 * Text Domain: order-management-plugin
 */

defined('ABSPATH') || exit;

// Hook registration
add_action('admin_init', 'omp_handle_settings');
add_action('admin_menu', 'omp_admin_menu');

// Admin menu setup
function omp_admin_menu() {

	add_menu_page(
		'Order Management',
		'Order Management',
		'manage_options',
		'omp-dashboard',
		'omp_render_dashboard',
		'dashicons-store',
		25
	);

	add_submenu_page(
		'omp-dashboard',
		'Settings',
		'Settings',
		'manage_options',
		'omp-settings',
		'omp_render_settings'
	);
}

// Settings page handler
function omp_handle_settings() {

	if (!current_user_can('manage_options')) {
		return;
	}

	// Only handle POST submissions
	if (!empty($_POST['omp_save_settings'])) {

		check_admin_referer('omp_save_settings_nonce');

		$login    = isset($_POST['omp_login']) ? sanitize_text_field(wp_unslash($_POST['omp_login'])) : '';
		$password = isset($_POST['omp_password']) ? sanitize_text_field(wp_unslash($_POST['omp_password'])) : '';

		update_option('omp_login', $login);
		if ($password !== '') {
			update_option('omp_password', $password);
		}

		add_settings_error(
			'omp_messages',
			'omp_settings_saved',
			esc_html__('Settings saved', 'order-management-plugin'),
			'success'
		);
	}
}

// Render Settings Page
function omp_render_settings() {

	if (!current_user_can('manage_options')) {
		wp_die(
			esc_html__('You do not have permission to access this page', 'order-management-plugin'),
			403
		);
	}

	$login    = get_option('omp_login', '');
	$password = get_option('omp_password', '');

	settings_errors('omp_messages');

	$template = plugin_dir_path(__FILE__) . 'admin/settings-page.php';
	if (file_exists($template)) {
		include $template;
	}
}
// Render Dashboard Page
function omp_render_dashboard() {
	echo '<div class="wrap"><h1>' .
		esc_html__('Dashboard', 'order-management-plugin') .
		'</h1></div>';
}

require_once plugin_dir_path(__FILE__) . 'frontend/shortcode-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/api.php';
