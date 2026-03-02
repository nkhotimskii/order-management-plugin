<?php

/**
 * Template Name: Order Management Dashboard
 * Description: Dashboard page with WordPress theme header
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Add cache headers for back button caching
add_action('wp', function () {
    if (!is_user_logged_in()) {
        return;
    }

    $selected_date = isset($_GET['selected_date']) ? $_GET['selected_date'] : '';

    if (!empty($selected_date)) {
        header('Cache-Control: private, max-age=300');
        header('Pragma: cache');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
    }
}, 1);

// Check if viewing detailed orders (category)
$selected_date = isset($_GET['selected_date']) ? $_GET['selected_date'] : '';
$delivery_type_id = isset($_GET['delivery_type_id']) ? $_GET['delivery_type_id'] : '';

// Get dashboard content based on view type
if (!empty($delivery_type_id) && !empty($selected_date)) {
    // Detailed view - show orders for specific category
    $content = do_shortcode('[detailed_orders_data]');
} else {
    // Main dashboard view
    $content = do_shortcode('[order_management_dashboard]');
}

// Check if theme has header.php
$theme = wp_get_theme();
$has_header = file_exists($theme->get_stylesheet_directory() . '/header.php');

if ($has_header) {
    get_header();
} else {
    // Standalone template without theme header
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html__("Order Management Dashboard", "order-management-plugin"); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #1d2327; }
        .omp-dashboard { max-width: 1400px !important; margin: 0 auto !important; padding: 80px 20px 30px !important; background: #fff !important; min-height: 100vh !important; position: relative !important; z-index: 99999 !important; }
        .omp-dashboard h1 { font-size: 28px; padding-bottom: 10px; border-bottom: 2px solid #2271b1; margin-bottom: 20px; }
        .omp-dashboard h2 { margin-top: 30px; }
        .omp-dashboard h2:first-child { margin-top: 0; }
        .omp-dashboard form { margin-top: 20px; }
        .omp-dashboard h3 { font-size: 18px; margin-top: 25px; margin-bottom: 10px; }
        .omp-dashboard table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px; }
        .omp-dashboard th, .omp-dashboard td { padding: 10px 12px; text-align: left; border: 1px solid #dcdcde; }
        .omp-dashboard th { background: #f0f0f1; font-weight: 600; }
        .omp-dashboard a { color: #2271b1; text-decoration: none; }
        .omp-dashboard a:hover { text-decoration: underline; }
        .omp-dashboard input[type="date"] { padding: 10px 12px; border: 1px solid #dcdcde; border-radius: 4px; font-size: 14px; }
        .omp-dashboard input[type="submit"] { padding: 10px 20px; background: #2271b1; color: #fff; border: none; border-radius: 4px; font-size: 14px; cursor: pointer; }
        .omp-dashboard input[type="submit"]:hover { background: #135e96; }
        .omp-dashboard .total-bread-weight { font-size: 16px; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #2271b1; }
        .omp-dashboard .back-link { display: inline-block; margin-bottom: 15px; }
        .omp-dashboard h2 { text-align: left; }
        .omp-dashboard h2.center { text-align: center; }
        .omp-dashboard .response-time { text-align: center; font-size: 14px; margin-bottom: 20px; }
        .omp-dashboard h3.orders-title { font-size: 24px; margin-top: 30px; margin-bottom: 10px; }
        .product-table { border: 1px solid; border-collapse: collapse; margin-top: 15px; margin-bottom: 30px; }
        th { border: 1px solid; border-collapse: collapse; }
        td { border: 1px solid; border-collapse: collapse; }
        .product-table.pickup-shop { margin-bottom: 50px; }
        .quantity-column { width: 50px; }
        .order-status { color: white; border-radius: 15px; padding: 5px; }
        .detailed-orders.order-headers { margin-bottom: 1px; }
        .tracking-number { color: inherit; }
        .detailed-orders.google-maps { margin-bottom: 1px; }
        .detailed-orders.counterparty-phone { margin-bottom: 1px; }
        .detailed-orders.city { margin-bottom: 1px; }
        .detailed-orders.sellers { margin-bottom: 1px; }
        .comment { font-style: italic; }
        .dashboard-order { cursor: pointer; }
        .clickable-row, .clickable-row td { cursor: pointer !important; }
        .order-management-dashboard .counterparty-grey { color: #cccccc !important; }
        .detailed-orders { margin-top: 20px; }
        .detailed-orders.pickup-shop { margin-bottom: 10px; }
        .detailed-orders.order-number { font-weight: bold; }
        .detailed-orders .product-table { font-size: 13px; }
    </style>
</head>
<body>
<?php
}

if ($has_header) {
    echo '<div class="omp-dashboard" style="padding-top: 50px;">';
} else {
    echo '<div class="omp-dashboard" style="padding-top: 50px;">';
}

    echo $content;

echo '</div>';

if ($has_header) {
    get_footer();
} else {
    echo '</body></html>';
}
