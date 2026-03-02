<?php

// phpcs:disable PSR1.Files.SideEffect

/**
 * Plugin Name: Order Management
 * Description: Order Management (MoySklad JSON API v1.2)
 * Version: 1.0
 * Text Domain: order-management-plugin
 */

namespace OrderManagementPlugin;

defined('ABSPATH') || exit;

// Load translations - only if WP functions are available
if (function_exists('load_plugin_textdomain')) {
    load_plugin_textdomain('order-management-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Define arbitrary MoySklad fields
define('OMP_ENCRYPTION_KEY_OPTION', 'omp_encryption_key');

function omp_get_encryption_key(): string
{
    $key = get_option(OMP_ENCRYPTION_KEY_OPTION);
    if (!$key) {
        $key = bin2hex(random_bytes(32));
        update_option(OMP_ENCRYPTION_KEY_OPTION, $key);
    }
    return hex2bin($key);
}

/**
 * Encrypt a string
 *
 * @param string $data Data to encrypt
 * @return string Encrypted data base64 encoded
 */
function omp_encrypt(string $data): string
{
    if (empty($data)) {
        return '';
    }
    $key = omp_get_encryption_key();
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

/**
 * Decrypt a string
 *
 * @param string $data Encrypted data base64 encoded
 * @return string Decrypted data
 */
function omp_decrypt(string $data): string
{
    if (empty($data)) {
        return '';
    }
    $key = omp_get_encryption_key();
    $parts = explode('::', base64_decode($data), 2);
    if (count($parts) !== 2) {
        return '';
    }
    [$encrypted, $iv] = $parts;
    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
}

define('OMP_DATE_FILTER_FIELD_ID', 'dd2bb6db-8cb5-11f0-0a80-02590034b9d9');
define('OMP_ARBITRARY_PRODUCT_NAME_FIELD_ID', '0c70ad3e-e9aa-11f0-0a80-17c7006ac7fd');
define('OMP_DELIVERY_TYPE_FIELD_ID', '7343c419-9d1e-11f0-0a80-03a9002f2ae8');
define('OMP_ORDER_NUMBER_FIELD_ID', 'a2351b48-7b6d-11f0-0a80-11d400349100');
define('OMP_CITY_FIELD_ID', '6f5fa582-93b7-11f0-0a80-10f1000db0a8');
define('OMP_PICKUP_SHOP_FIELD_ID', 'c03bab10-da9f-11f0-0a80-0c0400003f7d');
define('OMP_TRACKING_NUMBER_FIELD_ID', '5147d784-e8a5-11f0-0a80-0d8e00620dd1');
define('OMP_IS_BREAD_FIELD_ID', 'a8b40ad7-f522-11f0-0a80-1365002f4822');
define('OMP_PRODUCT_SORT_NUMBER_FIELD_ID', '78941a16-da9d-11f0-0a80-05a500002261');
define('OMP_CAFE_DELIVERY_TYPE_FIELD_ID', '04bf2038-da9e-11f0-0a80-008f0000332d');
define('OMP_PARCEL_TERMINAL_DELIVERY_TYPE_FIELD_ID', '76dfdfeb-d8f2-11f0-0a80-059d0023ba50');
define('OMP_BUS_STOP_DELIVERY_TYPE_FIELD_ID', '00790f93-deaa-11f0-0a80-0b0a00277318');
define('OMP_MARKET_DELIVERY_TYPE_FIELD_ID', 'bfbf71b9-e0cd-11f0-0a80-03730011e8f2');
define('OMP_GOOGLE_MAPS_FIELD_ID', 'b1bb251b-e8b6-11f0-0a80-18d10061576b');
define('OMP_SELLERS_FIELD_ID', '22f8cae5-e0cd-11f0-0a80-1a1800117e82');

// Frontend dashboard page URL - update this to match your WordPress page
define('OMP_DASHBOARD_PAGE_URL', '/order-management-dashboard');

define('OMP_DASHBOARD_DOMAIN', ''); // Set custom domain for dashboard redirects (empty = auto-detect)

define(
    'OMP_ORDERS_TABLES_FIELDS',
    [
    OMP_CAFE_DELIVERY_TYPE_FIELD_ID => [
        'Counterparty',
        'Pickup Shop',
        'Order Amount',
        'Status'
    ],
    OMP_PARCEL_TERMINAL_DELIVERY_TYPE_FIELD_ID => [
        'Order Number',
        'Counterparty',
        'Tracking Number',
        'Order Amount',
        'Status'
    ],
    OMP_BUS_STOP_DELIVERY_TYPE_FIELD_ID => [
        'Order Number',
        'Counterparty',
        'City',
        'Tracking Number',
        'Order Amount',
        'Status'
    ],
    OMP_MARKET_DELIVERY_TYPE_FIELD_ID => [
        'Counterparty',
        'Pickup Shop',
        'Sellers',
        'Order Amount',
        'Status'
    ],
    'other_tables' => [
        'Counterparty',
        'Order Amount',
        'Status'
    ]
    ]
);

// Hook registration
\add_action('admin_menu', __NAMESPACE__ . '\\add_admin_menu');
\add_action('admin_init', __NAMESPACE__ . '\\handle_settings');
\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin_styles');

function add_admin_menu()
{
    \add_submenu_page(
        'options-general.php',
        'Order Management',
        'Order Management',
        'manage_options',
        'omp-settings',
        __NAMESPACE__ . '\\render_settings'
    );
}




function enqueue_admin_styles($hook)
{

    if ('settings_page_omp-settings' !== $hook) {
        return;
    }

    $style_path = \plugin_dir_url(__FILE__) . 'css/settings-page.css';
    \wp_enqueue_style('order-management-settings', $style_path, array(), '1.0');
}

function handle_settings()
{

    if (! \current_user_can('manage_options')) {
        return;
    }

    // Save settings
    if (! empty($_POST['omp_save_settings'])) {
        \check_admin_referer('omp_save_settings_nonce');

        $login    = isset($_POST['omp_login']) ? \sanitize_text_field(\wp_unslash($_POST['omp_login'])) : '';
        $password = isset($_POST['omp_password']) ? \sanitize_text_field(\wp_unslash($_POST['omp_password'])) : '';

        \update_option('omp_login', $login);

        if ($password !== '') {
            \update_option('omp_password', omp_encrypt($password));
        }

        \add_settings_error(
            'omp_messages',
            'omp_settings_saved',
            \esc_html__('Settings saved', 'order-management-plugin'),
            'success'
        );
    }

    // Test API connection
    if (! empty($_POST['omp_test_connection'])) {
        \check_admin_referer('omp_save_settings_nonce');

        delete_option('omp_access_token_data');
        $token = omp_get_access_token();

        if ($token) {
            \add_settings_error(
                'omp_messages',
                'omp_connection_ok',
                \esc_html__('Connection successful', 'order-management-plugin'),
                'success'
            );
        } else {
            \add_settings_error(
                'omp_messages',
                'omp_connection_failed',
                \esc_html__('Connection failed. Check credentials.', 'order-management-plugin'),
                'error'
            );
        }
    }

    // Go to Orders dashboard (frontend)
    if (! empty($_POST['omp_to_orders'])) {
        $frontend_url = \home_url(OMP_DASHBOARD_PAGE_URL);
        \wp_safe_redirect($frontend_url);
        exit;
    }
}

function render_settings()
{

    if (! \current_user_can('manage_options')) {
        \wp_die(
            \esc_html__('You do not have permission to access this page', 'order-management-plugin'),
            403
        );
    }

    $login    = \get_option('omp_login', '');
    $password = \get_option('omp_password', '');
    if ($password) {
        $password = omp_decrypt($password);
    }


    $template = \plugin_dir_path(__FILE__) . 'admin/settings-page.php';

    if (\file_exists($template)) {
        include $template;
    }
}

require_once \plugin_dir_path(__FILE__) . 'includes/api.php';
require_once \plugin_dir_path(__FILE__) . 'frontend/shortcode-dashboard.php';
require_once \plugin_dir_path(__FILE__) . 'frontend/dashboard-page.php';
