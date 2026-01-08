<?php
/**
 * Plugin Name: Order Management
 * Description: Order Management (MoySklad JSON API v1.2)
 * Version: 1.0
 * Text Domain: order-management-plugin
 */

defined('ABSPATH') || exit;

// Hook registration
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

// Render Settings Page
function omp_render_settings() {
	echo '<div class="wrap"><h1>Settings</h1></div>';
}

// Render Dashboard Page
function omp_render_dashboard() {
	echo '<div class="wrap"><h1>Dashboard</h1></div>';
}
