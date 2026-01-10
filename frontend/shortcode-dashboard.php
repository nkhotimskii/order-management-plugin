<?php

defined('ABSPATH') || exit;

/**
 * Render Order Management frontend dashboard
 *
 * @return string
 */

function omp_render_frontend_dashboard() {

	// Optional: restrict to logged-in users
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

		<p>
			<?php esc_html_e(
				'Frontend dashboard placeholder. Orders will appear here.',
				'order-management-plugin'
			); ?>
		</p>
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
}

add_action('init', 'omp_register_shortcodes');
