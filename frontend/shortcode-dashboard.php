<?php
defined('ABSPATH') || exit;

function omp_shortcode_orders() {

	// Check if user is logged in
	if (!is_user_logged_in()) {
		return esc_html__('Please log in to view orders.', 'order-management-plugin');
	}

	// Get orders data
	$current_date = date('Y-m-d');
	$orders = omp_get_customer_orders_by_date($current_date);

	// Return if no orders exist
	if (!$orders) {
		return '<p>No orders available</p>';
	}

	// Count orders
	$orders_quantity = count($orders);

	$output = '<p>Total Orders: ' . $orders_quantity . '</p>';

	return $output;
}

function omp_render_frontend_dashboard() {

	// Restrict to logged-in users
	if (!is_user_logged_in()) {
		return esc_html__(
			'You must be logged in to view this page.',
			'order-management-plugin'
		);
	}

	ob_start();
	?>
	<div class="omp-frontend-dashboard">
		<h2><?php esc_html_e('Order Management', 'order-management-plugin'); ?></h2>
	</div>
	<?php

	return ob_get_clean();
}

// Register shortcode
function omp_register_shortcodes() {
	add_shortcode(
		'order_management_dashboard',
		'omp_render_frontend_dashboard'
	);
	add_shortcode(
		'omp_orders',
		'omp_shortcode_orders'
	);
}

add_action('init', 'omp_register_shortcodes');
