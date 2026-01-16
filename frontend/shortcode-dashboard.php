<?php
defined('ABSPATH') || exit;

function omp_shortcode_agg_products_table() {

	// Check if user is logged in
	if (!is_user_logged_in()) {
		return esc_html__('Please log in to view orders', 'order-management-plugin');
	}

	// Get orders data
	$current_date = date('Y-m-d');
	$products = omp_agg_products($current_date);

	// Return if no orders exist
	if (empty($products)) {
		return '<p>' . esc_html__('No orders for chosen day', 'order-management-plugin') . '</p>';
	}

	// Count total weight
	$total_weight_number = 0;

	$products_table = '<table>';

	// Add header
	$products_table .= '<tr><th>Product</th><th>Weight (kg)</th><th>Quantity</th></tr>';

	foreach ($products as $product) {

		$total_weight_number += (float) $product['weight'];
		$product_name = esc_html($product['product_name']);
		$product_weight = $product['weight'];
		if (!is_string($product_weight)) {
			$product_weight = number_format($product['weight'], 2);
		}
		$product_quantity = $product['quantity'];
		$products_table .= sprintf(
			'<tr><td>%s</td><td><strong>%s</strong></td><td><strong>%s</strong></td></tr>',
			$product_name,
			$product_weight,
			$product_quantity
		);
	}

	$products_table .= '</table>';

	$total_weight = sprintf(
		'<p>Total Weight: <strong>%s kg</strong></p>',
		number_format($total_weight_number, 2)
	);

	return $total_weight . $products_table;
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
		'agg_products_table',
		'omp_shortcode_agg_products_table'
	);
}

add_action('init', 'omp_register_shortcodes');
