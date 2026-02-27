<?php

/**
 * Plugin Name: Order Management
 * Description: Settings page for Order Management.
 * Version: 1.0
 * Text Domain: order-management-plugin
 * Requires PHP: 8.2.12
 * Author: Nikolai Khotimskii
 * License: MIT
 */

namespace OrderManagementPlugin;

defined('ABSPATH') || exit;
?>

<div class="order-settings">
    <h1><?php echo esc_html__("Order Management Plugin", "order-management-plugin"); ?> 🍞</h1>

    <form action="" method="post">
        <?php \wp_nonce_field('omp_save_settings_nonce'); ?>

        <div class="form-group">
            <label for="omp_login"><?php echo esc_html__("MoySklad Login", "order-management-plugin"); ?></label>
            <input type="text" id="omp_login" name="omp_login" value="<?php echo \esc_attr($login); ?>" class="regular-text" autocomplete="username">
        </div>

        <div class="form-group">
            <label for="omp_password"><?php echo esc_html__("MoySklad Password", "order-management-plugin"); ?></label>
            <input type="password" id="omp_password" name="omp_password" class="regular-text" autocomplete="new-password">
            <?php if (!empty($password)) : ?>
                <p class="description"><?php echo esc_html__("Leave blank to keep current password", "order-management-plugin"); ?></p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <?php \submit_button(\esc_html__('Save Settings', 'order-management-plugin'), 'primary', 'omp_save_settings', false); ?>
            <?php \submit_button(\esc_html__('Test Connection', 'order-management-plugin'), 'secondary', 'omp_test_connection', false); ?>
            <button type="submit" name="omp_to_orders" value="1" class="button"><?php echo esc_html__("To Orders", "order-management-plugin"); ?></button>
        </div>
    </form>
</div>
