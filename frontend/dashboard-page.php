<?php

/**
 * Standalone dashboard page template handler
 */

namespace OrderManagementPlugin;

defined('ABSPATH') || exit;

/**
 * Register dashboard page template
 */
function register_dashboard_template($template)
{
    // Get current path
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';

    // Check if this is our dashboard URL
    if (strpos($request_uri, '/order-management-dashboard') !== false) {
        $custom_template = plugin_dir_path(__FILE__) . 'dashboard-template.php';

        if (file_exists($custom_template)) {
            return $custom_template;
        }
    }

    return $template;
}
add_filter('template_include', __NAMESPACE__ . '\\register_dashboard_template', 99);
