<?php

/**
 * Plugin Name: Order Management
 * Description: Frontend dashboard shortcode for Order Management.
 * Version: 1.0
 * Text Domain: order-management-plugin
 * Requires PHP: 8.2.12
 * Author: Nikolai Khotimskii
 * License: MIT
 */

defined('ABSPATH') || exit;

define(
    'OMP_STATUS_COLOR_MAPPING',
    [
    '10066329' =>     '#999999', // серый
    '15280409' => '#e42f2f', // красный
    '15106326' => '#e28136', // оранжевый
    '6900510' => '#684b25', // коричневый
    '12430848' => '#bcac34', // фисташка
    '10667543' => '#a4c33d', // салатовый
    '8825440' => '#88a867', // зелёный
    '34617' => '#1b8540', // тёмно-зелёный
    '8767198' => '#8ac6dc', // голубой
    '40931' => '#27a1de', // ярко-голубой
    '4354177' => '#46707f', // морская волна
    '18842' => '#104d96', // синий
    '15491487' => '#e8669f', // розовый
    '10774205' => '#a26bb9', // фиолетовый
    '9245744' => '#8a1934', // бордовый
    '0' => '#000000' //чёрный
    ]
);

function save_selected_date($date)
{
    setcookie('selected_date', $date, strtotime('+30 days'));
}


function datepicker_shortcode(): string
{
    if (isset($_POST['selected_date'])) {
        $date = sanitize_text_field($_POST['selected_date']);
        save_selected_date($date);
        setcookie('fresh_request', '1', time() + 60, '/');

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $redirect_url = add_query_arg('selected_date', $date, $_SERVER['REQUEST_URI']);
        wp_redirect($redirect_url);
        exit;
    } elseif (isset($_GET['selected_date'])) {
        $date = sanitize_text_field($_GET['selected_date']);
        setcookie('selected_date', $date, time() + 86400, '/');
    } elseif (isset($_COOKIE['selected_date'])) {
        $date = sanitize_text_field($_COOKIE['selected_date']);
    }

    return '
		<form method="post" style="margin-bottom: 40px;">
			<input type="date" name="selected_date" value="' . esc_attr($date ?? '') . '" style="width: 200px;">
			<input type="submit" value="' . esc_html__('Show Orders', 'order-management-plugin') . '" style="padding: 14.5px;background-color: #35415B">
		</form>
	';
}

function omp_get_dashboard(): string
{
    // Get selected date - from GET (after redirect) or cookie
    if (isset($_GET['selected_date'])) {
        $selected_date = sanitize_text_field($_GET['selected_date']);
    } elseif (isset($_COOKIE['selected_date'])) {
        $selected_date = sanitize_text_field($_COOKIE['selected_date']);
    } else {
        return '';
    }

    // Check if user is logged in (optional - can be removed to allow public access)
    // if (!is_user_logged_in()) {
    //     return esc_html__('Please log in to view orders', 'order-management-plugin');
    // }

    // Get orders data - use cache unless fresh request
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        $current_user_id = 'guest';
    }
    $orders_transient_name = $current_user_id . '_orders_' . $selected_date;

    // Check if this is a fresh request (Show Orders clicked)
    $is_fresh = isset($_COOKIE['fresh_request']);
    if ($is_fresh) {
        setcookie('fresh_request', '', time() - 3600, '/');
        delete_transient($orders_transient_name);
    }

    $cached_orders = get_transient($orders_transient_name);

    // Also try shared cache
    $shared_orders_transient_name = 'shared_orders_' . $selected_date;
    if (!$cached_orders) {
        $cached_orders = get_transient($shared_orders_transient_name);
    }

    if ($cached_orders) {
        // Use cached data
        $orders_response = $cached_orders;
    } else {
        // No cache - make API call
        $orders_response = omp_get_dashboard_orders($selected_date);
        set_transient($orders_transient_name, $orders_response, 3600);
        // Save to shared cache for guest users
        set_transient($shared_orders_transient_name, $orders_response, 3600);
    }

    $response_time = $orders_response['response_time'];
    $orders = $orders_response['data'];
    $total_positions_data = omp_get_dashboard_total_positions_data($orders);

    // Return if no orders exist
    if (empty($orders)) {
        return '<p>' . esc_html__('No orders for chosen day', 'order-management-plugin') . '</p>';
    }

    $time = sprintf(
        '<div class="response-time"><strong>' . esc_html__('Duomenys atnaujinti:', 'order-management-plugin') . '</strong> %s</div>',
        $response_time
    );

    // Format selected date as weekday, month, day
    $date_obj = DateTime::createFromFormat('Y-m-d', $selected_date);
    if ($date_obj) {
        $locale = get_locale();
        $is_lithuanian = strpos($locale, 'lt') === 0;

        if ($is_lithuanian) {
            $day_names = [
                1 => 'Pirmadienis',
                2 => 'Antradienis',
                3 => 'Trečiadienis',
                4 => 'Ketvertadienis',
                5 => 'Penktadienis',
                6 => 'Šeštadienis',
                7 => 'Sekmadienis'
            ];
            $month_names = [
                1 => 'sausis',
                2 => 'vasaris',
                3 => 'kovas',
                4 => 'balandis',
                5 => 'gegužė',
                6 => 'birželis',
                7 => 'liepa',
                8 => 'rugpjūtis',
                9 => 'rugsėjis',
                10 => 'spalis',
                11 => 'lapkritis',
                12 => 'gruodis'
            ];
            $weekday = $day_names[(int) $date_obj->format('N')];
            $month = $month_names[(int) $date_obj->format('n')];
        } else {
            $weekday = $date_obj->format('l');
            $month = $date_obj->format('F');
            $month = strtolower($month);
        }
        $day = ltrim($date_obj->format('d'), '0');
        $date_header = sprintf(
            '<h2 class="center">%s, %s %s</h2>',
            $weekday,
            $month,
            $day
        );
    } else {
        $date_header = '';
    }

    $total_positions_html = omp_get_total_positions_html($total_positions_data);
    $orders_html = omp_get_orders_html($orders, $selected_date);

    $script = '<script>
        document.addEventListener("DOMContentLoaded", function() {
            document.querySelectorAll(".clickable-row").forEach(function(row) {
                row.addEventListener("click", function(e) {
                    if (!e.target.closest("a")) {
                        window.location.href = row.dataset.href;
                    }
                });
            });
        });
    </script>';

    return $date_header . $time . $total_positions_html . $orders_html . $script;
}

function omp_generate_total_positions_html(array $total_positions): string
{
    if (empty($total_positions)) {
        return '';
    }

    $total_positions_html = '<table class="order-management-dashboard product-table">';
    $total_positions_html .= '
		<colgroup>
			<col class="quantity-column" />
			<col class="product-column" />
		</colgroup>
	';
    $total_positions_html .= '
		<tr>
			<th><strong>' . esc_html__('Quantity', 'order-management-plugin') . '</strong></th>
			<th><strong>' . esc_html__('Product', 'order-management-plugin') . '</strong></th>
		</tr>
	';

    foreach ($total_positions as $total_position) {
        $product = esc_html($total_position['product']);
        $quantity = $total_position['quantity'];
        $product_row = sprintf(
            '<tr><td><strong>%s</strong></td><td>%s</td></tr>',
            $quantity,
            $product
        );
        $total_positions_html .= $product_row;
    }

    $total_positions_html .= '</table>';

    return $total_positions_html;
}

function omp_generate_market_product_table($orders)
{
    $total_bread_weight = 0;
    $total_order_amount = 0;
    $product_data = [];
    $counterparty_totals = [];

    foreach ($orders as $order) {
        // Add order amount
        if (!empty($order['order_amount'])) {
            $total_order_amount += (float) $order['order_amount'];
        }

        // Track counterparty order amounts
        $counterparty = $order['counterparty'] ?? '';
        if (!empty($counterparty) && !empty($order['order_amount'])) {
            if (!isset($counterparty_totals[$counterparty])) {
                $counterparty_totals[$counterparty] = [
                    'weight' => 0,
                    'amount' => 0
                ];
            }
            $counterparty_totals[$counterparty]['amount'] += (float) $order['order_amount'];
        }

        foreach ($order['positions'] as $position) {
            $is_bread = $position['is_bread'];

            if ($is_bread) {
                $total_bread_weight += $position['weight'];
                // Add to counterparty weight if we have a counterparty
                if (!empty($counterparty)) {
                    if (!isset($counterparty_totals[$counterparty])) {
                        $counterparty_totals[$counterparty] = ['weight' => 0, 'amount' => 0];
                    }
                    $counterparty_totals[$counterparty]['weight'] += $position['weight'];
                }
            }

            if (!isset($product_data[$position['product']])) {
                $product_data[$position['product']] = [
                    'total_quantity' => $position['quantity'],
                    'is_bread' => $is_bread
                ];
            } else {
                $product_data[$position['product']]['total_quantity'] += $position['quantity'];
            }
        }

        if (!$order['pickup_shop']) {
            foreach ($order['positions'] as $position) {
                if (!isset($product_data[$position['product']][$order['counterparty']])) {
                    $product_data[$position['product']][$order['counterparty']] = $position['quantity'];
                } else {
                    $product_data[$position['product']][$order['counterparty']] += $position['quantity'];
                }
            }
        }
    }

    $counterparties = [];
    foreach ($product_data as $product) {
        foreach (array_keys($product) as $product_key) {
            if ((!in_array($product_key, ['total_quantity', 'is_bread']))
                && (!in_array($product_key, $counterparties))) {
                $counterparties[] = $product_key;
            }
        }
    }

    // Add totals to table header/caption
    // $table_caption = sprintf(
    //     '<caption>Total: %s kg | %s</caption>',
    //     number_format($total_bread_weight, 2),
    //     number_format($total_order_amount, 2)
    // );

    $product_table_html = '<table class="product-table market">'; // . $table_caption
    $product_table_html .= '
        <colgroup>
            <col class="quantity-column"></col>
            <col class="product-column"></col>
    ';

    foreach ($counterparties as $counterparty) {
        $product_table_html .= '<col class="counterparty quantity-column"></col>';
    }

    $product_table_html .= '</colgroup>';
    $product_table_html .= '
        <thead>
            <tr>
                <th>' . esc_html__('Quantity', 'order-management-plugin') . '</th>
                <th>' . esc_html__('Product', 'order-management-plugin') . '</th>
    ';

    foreach ($counterparties as $counterparty) {
        $totals = $counterparty_totals[$counterparty] ?? ['weight' => 0, 'amount' => 0];
        $product_table_html .= sprintf(
            '<th>%s<br><small>%s kg<br>%s €</small></th>',
            esc_html($counterparty),
            number_format($totals['weight'], 2),
            number_format($totals['amount'], 2)
        );
    }

    $product_table_html .= '</tr></thead>';

    foreach ($product_data as $product) {
        $create_bread_table = true;
        $create_non_bread_table = true;
        if (!$create_bread_table && !$create_non_bread_table) {
            break;
        }
        if ($product['is_bread'] && $create_bread_table) {
            $bread_table = $product_table_html;
            $create_bread_table = false;
        } elseif (!$product['is_bread'] && $create_non_bread_table) {
            $non_bread_table = $product_table_html;
            $create_non_bread_table = false;
        }
    }

    $add_product_row = function($product_table, $product_data, $product, $counterparties) {
        $product_table .= sprintf(
            '<tr><td>%s</td><td>%s</td>',
            $product_data[$product]['total_quantity'],
            esc_html($product)
        );

        foreach ($counterparties as $counterparty) {
            $product_table .= sprintf(
                '<td>%s</td>',
                $product_data[$product][$counterparty] ?? '.'
            );
        }

        $product_table .= '</tr>';
        return $product_table;
    };

    foreach (array_keys($product_data) as $product) {
        if ($product_data[$product]['is_bread']) {
            $bread_table = $add_product_row($bread_table, $product_data, $product, $counterparties);
        } else {
            $non_bread_table = $add_product_row($non_bread_table, $product_data, $product, $counterparties);
        }
    }

    $total_bread_weight_html = sprintf(
        '<p class="total-bread-weight">' . esc_html__('Total Weight (bread): %s kg', 'order-management-plugin') . '</p>',
        number_format($total_bread_weight, 2)
    );

    $market_products_table = '';

    if (isset($bread_table)) {
        $bread_table .= '</table>';
        $market_products_table .= $bread_table;
    }

    if (isset($non_bread_table)) {
        $non_bread_table .= '</table>';
        $market_products_table .= $non_bread_table;
    }

    return $total_bread_weight_html . $market_products_table;
}

function omp_get_total_positions_html(array $total_positions_data): string
{
    $total_bread_weight = $total_positions_data['total_bread_weight'];
    $total_positions_bread = $total_positions_data['total_positions_bread'];
    $total_positions_non_bread = $total_positions_data['total_positions_non_bread'];

    $total_bread_weight_html = sprintf(
        '<p class="total-bread-weight">' . esc_html__('Total Weight (bread): %s kg', 'order-management-plugin') . '</p>',
        number_format($total_bread_weight, 2)
    );

    $total_positions_bread_html = '';

    $total_positions_bread_html = omp_generate_total_positions_html($total_positions_bread);
    $total_positions_non_bread_html = omp_generate_total_positions_html($total_positions_non_bread);

    // Check if all bread has weight
    $no_weight_bread = [];
    foreach ($total_positions_bread as $total_position_bread) {
        if (
            !$total_position_bread['weight']
            || $total_position_bread['weight'] === ''
            || $total_position_bread['weight'] === 0
        ) {
            $no_weight_bread[] = $total_position_bread;
        }
    }

    // Raise error if not all bread has weight
    if (!empty($no_weight_bread)) {
        $total_bread_weight_html .= '<p><strong>' . esc_html__('! Error calculating total weight', 'order-management-plugin') . '</strong><br>' . esc_html__('No weight for product(s):', 'order-management-plugin') . '</p>';
        $total_bread_weight_html .= '<ul>';
        foreach ($no_weight_bread as $bread) {
            $total_bread_weight_html .= sprintf(
                '<li><strong>' . esc_html__('%s', 'order-management-plugin') . '</strong></li>',
                $bread['product']
            );
        }
        $total_bread_weight_html .= '</ul>';
    }

    return $total_bread_weight_html . $total_positions_bread_html . $total_positions_non_bread_html;
}

function omp_get_orders_html(
    array $orders,
    string $selected_date,
    bool $details_knob = true,
    array $orders_tables_fields = []
): string {
    if (empty($orders_tables_fields)) {
        $orders_tables_fields = OMP_ORDERS_TABLES_FIELDS;
    }

    $orders_by_delivery_type = [];

    // Sort orders by delivery type
    foreach ($orders as $order) {
        $delivery_type_id = $order['delivery_type_id'] ?? 'other';
        if (!isset($orders_by_delivery_type[$delivery_type_id])) {
            $orders_by_delivery_type[$delivery_type_id] = [];
        }
        $orders_by_delivery_type[$delivery_type_id][] = $order;
    }

    $orders_html = '';

    // Generate table for each delivery type
    foreach ($orders_by_delivery_type as $delivery_type_id => $delivery_type_orders) {
        // Calculate total bread weight for this category
        $total_bread_weight = 0;
        foreach ($delivery_type_orders as $order) {
            foreach ($order['positions'] as $position) {
                if (!empty($position['is_bread']) && !empty($position['weight'])) {
                    $total_bread_weight += $position['weight'];
                }
            }
        }
        $total_bread_weight_html = $total_bread_weight > 0 
            ? sprintf(' <span class="category-bread-weight">(%.2f kg)</span>', $total_bread_weight) 
            : '';

        $table_class = 'order-management-dashboard';
        $orders_table_fields = $orders_tables_fields[$delivery_type_id] ?? $orders_tables_fields['other_tables'];

        $orders_html .= omp_generate_product_table(
            [
            'delivery_type_id' => $delivery_type_id,
            'delivery' => $delivery_type_orders[0]['delivery'] ?? 'Other',
            'orders' => $delivery_type_orders,
            'fields' => $orders_table_fields,
            'details_knob' => $details_knob,
            'selected_date' => $selected_date,
            'total_bread_weight_html' => $total_bread_weight_html
            ],
            $table_class
        );
    }

    return $orders_html;
}

function add_product_row($quantity, $product)
{
    return sprintf(
        '<tr><td><strong>%s</strong></td><td>%s</td></tr>',
        $quantity,
        esc_html($product)
    );
}

function omp_generate_product_table($product_data, $table_class)
{

    $delivery_type_id = $product_data['delivery_type_id'];
    $delivery_type_name = $product_data['delivery'];
    $orders = $product_data['orders'];
    $fields = $product_data['fields'];
    $details_knob = $product_data['details_knob'] ?? true;
    $selected_date = $product_data['selected_date'];
    $total_bread_weight_html = $product_data['total_bread_weight_html'] ?? '';

    // Create URL for category details
    $category_url = add_query_arg(
        [
        'selected_date' => $selected_date,
        'delivery_type_id' => $delivery_type_id
        ]
    );

    // Only show category header on main dashboard, not on category detail page
    $table_html = '';
    if ($details_knob) {
        $table_html = sprintf(
            '<h3><a href="%s">%s</a>%s</h3>',
            esc_url($category_url),
            esc_html($delivery_type_name),
            $total_bread_weight_html
        );
    }

    $table_html .= sprintf(
        '<table class="%s">',
        esc_attr($table_class)
    );

    $table_html .= '
		<colgroup>
			<col class="number-column" />
		</colgroup>
	';

    // Add table header
    $table_html .= '<tr>';
    foreach ($fields as $field) {
        $table_html .= sprintf(
            '<th><strong>%s</strong></th>',
            esc_html__($field, 'order-management-plugin')
        );
    }
    $table_html .= '</tr>';

    // Add table rows
    foreach ($orders as $order) {
        $order_id = $order['id'] ?? '';
        $order_link = add_query_arg(
            [
            'selected_date' => $selected_date,
            'delivery_type_id' => $delivery_type_id,
            'order_id' => $order_id
            ]
        ) . '#order-' . $order_id;
        $table_html .= sprintf('<tr class="clickable-row" data-href="%s">', esc_url($order_link));
        foreach ($fields as $field) {
            $cell_value = '';
            switch ($field) {
                case 'Order Number':
                    $cell_value = $order['order_number'] ?? '';
                    break;
                case 'Counterparty':
                    $cell_value = $order['counterparty'] ?? '';
                    if (!empty($order['pickup_shop'])) {
                        $cell_value = sprintf('<span class="counterparty-grey">%s</span>', $cell_value);
                    }
                    break;
                case 'Tracking Number':
                       $cell_value = $order['tracking_number'] ?? '';
                    break;
                case 'City':
                    $cell_value = $order['city'] ?? '';
                    break;
                case 'Order Amount':
                    $cell_value = $order['order_amount'] ?? '';
                    break;
                case 'Sellers':
                    $cell_value = $order['sellers'] ?? '';
                    break;
                case 'Pickup Shop':
                    $cell_value = $order['pickup_shop'] ?? '';
                    break;
                case 'Status':
                    $cell_value = $order['status'] ?? '';
                    $status_color = OMP_STATUS_COLOR_MAPPING[$order['status_color']] ?? '#000000';
                    $cell_value = sprintf(
                        '<span class="order-status" style="background-color: %s;">%s</span>',
                        esc_attr($status_color),
                        esc_html($cell_value)
                    );
                    break;
            }
            $table_html .= sprintf('<td>%s</td>', $cell_value);
        }

        $table_html .= '</tr>';
    }

    $table_html .= '</table>';

    return $table_html;
}

function omp_get_detailed_orders_html($orders, $highlight_order_id = '')
{

    $delivery_type_id = $orders[0]['delivery_type_id'];

    $detailed_orders_html = '';
    $count = 1;

    // Determine if we should highlight an order
    $is_highlighted = !empty($highlight_order_id);

    foreach ($orders as $order) {
        $order_id = $order['id'] ?? '';
        if (empty($order_id)) {
            $order_id = 'order-' . $count;
        }
        $is_current_highlighted = $is_highlighted && $order_id === $highlight_order_id;

        // Add highlight class if this is the highlighted order
        $div_class = 'detailed-order-data';
        if ($is_current_highlighted) {
            $div_class .= ' highlighted-order';
        }

        $tabindex = $is_current_highlighted ? ' tabindex="-1"' : '';
        $detailed_orders_html .= sprintf('<div class="%s" id="order-%s"%s>', $div_class, esc_attr($order_id), $tabindex);

        $count++;

        // Add pickup shop if exists
        if (!empty($order['pickup_shop'])) {
            $pickup_shop_display = $order['pickup_shop'];
            // Add counterparty to pickup shop for market orders
            if (!empty($order['counterparty'])) {
                $pickup_shop_display .= ' - ' . $order['counterparty'];
            }
            $detailed_orders_html .= sprintf(
                '<p class="detailed-orders pickup-shop"><strong>' . esc_html__('— %s', 'order-management-plugin') . '</strong></p>',
                esc_html($pickup_shop_display)
            );
        }

        // Order headers - use order ID for anchor
        $detailed_orders_html .= sprintf('<p id="order-header-%s" class="detailed-orders order-headers">', esc_attr($order_id));

        if (!empty($order['order_number'])) {
            $detailed_orders_html .= sprintf(
                '<span class="detailed-orders order-number">%s </span>',
                $order['order_number']
            );
        }

        if (!empty($order['counterparty'])) {
            $counterparty_class = !empty($order['pickup_shop']) ? ' counterparty-grey' : '';
            $detailed_orders_html .= sprintf(
                '<span class="detailed-orders counterparty%s">%s </span>',
                $counterparty_class,
                $order['counterparty']
            );
        }

        if (!empty($order['tracking_number'])) {
            $tracking_number = $order['tracking_number'];
            $tracking_number_link = sprintf('https://parcelsapp.com/tracking/%s', $tracking_number);
            $detailed_orders_html .= sprintf(
                '<a href="%s" class="detailed-orders tracking-number"><strong>%s</strong> </a>',
                $tracking_number_link,
                $tracking_number
            );
        }

        if (!empty($order['status'])) {
            $status_color = OMP_STATUS_COLOR_MAPPING[$order['status_color']] ?? '#000000';
            $detailed_orders_html .= sprintf(
                '<span class="detailed-orders order-status" style="background-color: %s">%s</span>',
                $status_color,
                $order['status']
            );
        }

        $detailed_orders_html .= '</p>';

        if (!empty($order['google_maps'])) {
            $detailed_orders_html .= sprintf(
                '<p class="detailed-orders google-maps"><a href="%s" target="_blank"><strong>Žemėlapis</strong></a></p>',
                $order['google_maps']
            );
        }

        if (!empty($order['counterparty_phone'])) {
            $detailed_orders_html .= sprintf(
                '<p class="detailed-orders counterparty-phone"><a href="tel:%s">%s</a></p>',
                $order['counterparty_phone'],
                $order['counterparty_phone']
            );
        }

        if (!empty($order['city'])) {
            $detailed_orders_html .= sprintf(
                '<p><strong>' . esc_html__('City', 'order-management-plugin') . '</strong>: %s</p>',
                $order['city']
            );
        }

        if (!empty($order['sellers'])) {
            $detailed_orders_html .= sprintf(
                '<p><strong>' . esc_html__('Sellers', 'order-management-plugin') . ':</strong> %s</p>',
                $order['sellers']
            );
        }

        // Product table
        $detailed_orders_html .= '
			<table class="detailed-orders product-table">
				<colgroup>
					<col class="quantity-column" />
					<col class="product-column" />
				</colgroup>
				<thead>
					<tr>
						<th>' . esc_html__('Quantity', 'order-management-plugin') . '</th>
						<th>' . esc_html__('Product', 'order-management-plugin') . '</th>
					</tr>
				</thead>';

        foreach ($order['positions'] as $position) {
            $detailed_orders_html .= sprintf(
                '<tr><td>%s</td><td>%s</td></tr>',
                $position['quantity'],
                $position['product']
            );
        }

        $detailed_orders_html .= '</table>';

        if (!empty($order['comment'])) {
            $detailed_orders_html .= sprintf(
                '<p class="detailed-orders comment">%s</p>',
                $order['comment']
            );
        }

        $detailed_orders_html .= '</div>';
    }

    return $detailed_orders_html;
}

function omp_show_detailed_orders_data()
{

    $selected_date = $_GET['selected_date'] ?? '';
    $delivery_type_id = $_GET['delivery_type_id'] ?? '';
    $highlight_order_id = $_GET['order_id'] ?? '';
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        $current_user_id = 'guest';
    }
    $orders_transient_name = $current_user_id . '_orders_' . $selected_date;

    // Use date-based key for shared orders cache (accessible to all users)
    $shared_orders_transient_name = 'shared_orders_' . $selected_date;
    $orders_response = get_transient($shared_orders_transient_name);

    // Fallback to user-specific cache if shared doesn't exist
    if (!$orders_response) {
        $orders_response = get_transient($orders_transient_name);
    }

    $cache_key = 'omp_category_' . $current_user_id . '_' . md5($selected_date . $delivery_type_id);
    $cached_html = get_transient($cache_key);

    // If we have cached HTML but need to scroll to an order, return cached HTML with scroll script
    if ($cached_html !== false && !empty($highlight_order_id)) {
        $scroll_script = sprintf(
            '<script>document.addEventListener("DOMContentLoaded", function() { setTimeout(function() { var targetId = "order-%s"; var el = document.getElementById(targetId); if(el) { el.scrollIntoView({behavior: "smooth", block: "center"}); } else { var headers = document.querySelectorAll("[id^=\"order-header-%s\"]"); if(headers.length > 0) { headers[0].scrollIntoView({behavior: "smooth", block: "center"}); } } }, 100); });</script>',
            esc_js($highlight_order_id),
            esc_js($highlight_order_id)
        );
        return $cached_html . $scroll_script;
    }

    if ($cached_html !== false) {
        return $cached_html;
    }

    $orders_response = get_transient($orders_transient_name);

    // Also try shared cache
    if (!$orders_response) {
        $orders_response = get_transient($shared_orders_transient_name);
    }

    if ($orders_response) {
        $orders = [];
        foreach ($orders_response['data'] as $order) {
            if ($order['delivery_type_id'] === $delivery_type_id) {
                $orders[] = $order;
            }
        }
    } else {
        return '<p>' . esc_html__('No orders found. Please go back and click Show Orders.', 'order-management-plugin') . '</p>';
    }

    if (empty($orders)) {
        return '<p>' . esc_html__('No orders found', 'order-management-plugin') . '</p>';
    }

    $response_time = $orders_response['response_time'];

    // Format selected date as weekday, month, day
    $date_obj = DateTime::createFromFormat('Y-m-d', $selected_date);
    if ($date_obj) {
        $locale = get_locale();
        $is_lithuanian = strpos($locale, 'lt') === 0;

        if ($is_lithuanian) {
            $day_names = [
                1 => 'Pirmadienis',
                2 => 'Antradienis',
                3 => 'Trečiadienis',
                4 => 'Ketvertadienis',
                5 => 'Penktadienis',
                6 => 'Šeštadienis',
                7 => 'Sekmadienis'
            ];
            $month_names = [
                1 => 'sausis',
                2 => 'vasaris',
                3 => 'kovas',
                4 => 'balandis',
                5 => 'gegužė',
                6 => 'birželis',
                7 => 'liepa',
                8 => 'rugpjūtis',
                9 => 'rugsėjis',
                10 => 'spalis',
                11 => 'lapkritis',
                12 => 'gruodis'
            ];
            $weekday = $day_names[(int) $date_obj->format('N')];
            $month = $month_names[(int) $date_obj->format('n')];
        } else {
            $weekday = $date_obj->format('l');
            $month = $date_obj->format('F');
            $month = strtolower($month);
        }
        $day = ltrim($date_obj->format('d'), '0');
        $date_header = sprintf(
            '<h2 class="center">%s, %s %s</h2>',
            $weekday,
            $month,
            $day
        );
    } else {
        $date_header = '';
    }

    // Get category name for category page
    $category_name = $orders[0]['delivery'] ?? '';
    $category_header = $category_name ? sprintf('<h2>%s</h2>', esc_html($category_name)) : '';

    $time_html = sprintf('<div class="response-time"><strong>' . esc_html__('Duomenys atnaujinti:', 'order-management-plugin') . '</strong> %s</div>', $response_time);

    if ($delivery_type_id === \OMP_MARKET_DELIVERY_TYPE_FIELD_ID) {
        $total_positions_html = omp_generate_market_product_table($orders);
    } else {
        $total_positions_data = omp_get_dashboard_total_positions_data($orders);
        $total_positions_html = omp_get_total_positions_html($total_positions_data);
    }

    $orders_html = omp_get_orders_html($orders, $selected_date, false);
    $orders_with_header = '<h3 class="orders-title">' . esc_html__('Užsakymai', 'order-management-plugin') . '</h3>' . $orders_html;
    $detailed_orders_html = omp_get_detailed_orders_html($orders, $highlight_order_id);

    // Add scroll script if highlight_order_id is present
    $scroll_script = '';
    if (!empty($highlight_order_id)) {
        $scroll_script = sprintf(
            '<script>document.addEventListener("DOMContentLoaded", function() { setTimeout(function() { var targetId = "order-%s"; var el = document.getElementById(targetId); if(el) { el.scrollIntoView({behavior: "smooth", block: "center"}); } else { var headers = document.querySelectorAll("[id^=\"order-header-%s\"]"); if(headers.length > 0) { headers[0].scrollIntoView({behavior: "smooth", block: "center"}); } } }, 100); });</script>',
            esc_js($highlight_order_id),
            esc_js($highlight_order_id)
        );
    }

    $output = $date_header . $time_html . $category_header . $total_positions_html . $orders_with_header . $detailed_orders_html . $scroll_script;

    set_transient($cache_key, $output, 300);

    return $output;
}

function omp_render_frontend_dashboard()
{
    $output = "";

    // if (!is_user_logged_in()) {
    //     return esc_html__(
    //         'You must be logged in to view this page.',
    //         'order-management-plugin'
    //     );
    // }

    $output .= datepicker_shortcode();
    $output .= omp_get_dashboard();

    return $output;
}

function omp_register_shortcodes()
{
    add_shortcode(
        'order_management_dashboard',
        'omp_render_frontend_dashboard'
    );
    add_shortcode('datepicker', 'datepicker_shortcode');
    add_shortcode('dashboard', 'omp_get_dashboard');
    add_shortcode('detailed_orders_data', 'omp_show_detailed_orders_data');
    add_shortcode('weekly_orders', 'omp_get_weekly_orders');
}

add_action('init', 'omp_register_shortcodes');

function omp_get_weekly_orders()
{
    $day_names_lt = [
        1 => 'Pirmadienis',
        2 => 'Antradienis',
        3 => 'Trečiadienis',
        4 => 'Ketvertadienis',
        5 => 'Penktadienis',
        6 => 'Šeštadienis',
        7 => 'Sekmadienis'
    ];

    $today = new DateTime();
    $today_day_of_week = (int) $today->format('N');

    $monday = clone $today;
    $monday->modify('-' . ($today_day_of_week - 1) . ' days');

    $dashboard_url = home_url('/order-management-dashboard/');

    $html = '<ul>';
    for ($i = 0; $i < 7; $i++) {
        $day = clone $monday;
        $day->modify('+' . $i . ' days');

        $date_str = ltrim($day->format('m.d'), '0');
        $date_param = $day->format('Y-m-d');
        $day_name = $day_names_lt[$i + 1];

        $html .= sprintf(
            '<li><a href="%s?selected_date=%s">%s (%s)</a></li>',
            $dashboard_url,
            $date_param,
            $day_name,
            $date_str
        );
    }
    $html .= '</ul>';

    return $html;
}
