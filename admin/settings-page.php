<div class="wrap">

    <h1><?php esc_html_e('Access Settings', 'order-management-plugin'); ?></h1>

    <?php settings_errors('omp_messages'); ?>

    <form method="post" action="">

        <?php wp_nonce_field('omp_save_settings_nonce'); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="omp_login"><?php esc_html_e('Login', 'order-management-plugin'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="omp_login"
                        name="omp_login"
                        value="<?php echo esc_attr($login); ?>"
                        class="regular-text"
                        autocomplete="username"
                    />
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="omp_password"><?php esc_html_e('Password', 'order-management-plugin'); ?></label>
                </th>
                <td>
                    <input
                        type="password"
                        id="omp_password"
                        name="omp_password"
                        class="regular-text"
                        autocomplete="new-password"
                    />
                </td>
            </tr>
        </table>

        <?php
        submit_button(
            esc_html__('Save Settings', 'order-management-plugin'),
            'primary',
            'omp_save_settings'
        );
        submit_button(
            esc_html__('Test Connection', 'order-management-plugin'),
            'secondary',
            'omp_test_connection',
            false
        );
        ?>
    </form>
</div>
