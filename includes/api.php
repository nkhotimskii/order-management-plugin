<?php

defined('ABSPATH') || exit;

use function OrderManagementPlugin\omp_get_encryption_key;

// Token expiry buffer in seconds
define('OMP_TOKEN_EXPIRY_BUFFER', 60);

/**
 * Get MoySklad access token
 *
 * @return string|false
 */
function omp_get_access_token(): string|false
{

    // Get token data
    $token_data = get_option('omp_access_token_data', []);

    // Reuse token if exists
    if (
        !empty($token_data['access_token'])
        && time() < $token_data['expires_at']
    ) {
        return $token_data['access_token'];
    }

    // Get login and password
    $login    = get_option('omp_login');
    $password = get_option('omp_password');
    if ($password) {
        $key = omp_get_encryption_key();
        $parts = explode('::', base64_decode($password), 2);
        if (count($parts) === 2) {
            [$encrypted, $iv] = $parts;
            $password = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
        }
    }
    if (!$login || !$password) {
        return false;
    }

    $response = wp_remote_post(
        'https://api.moysklad.ru/api/remap/1.2/security/token',
        [
        'headers' => [
                'Authorization' => 'Basic ' . base64_encode($login . ':' . $password),
                'Accept'        => 'application/json;charset=utf-8'
        ],
        'timeout' => 15,
        ]
    );

    if (is_wp_error($response)) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data) || empty($data['access_token'])) {
        return false;
    }

    update_option(
        'omp_access_token_data',
        [
        'access_token' => $data['access_token'],
        'expires_at'   => time() + ((int) ($data['expires_in'] ?? 3600)) - OMP_TOKEN_EXPIRY_BUFFER,
        ]
    );

    return $data['access_token'];
}

function omp_get_dashboard_orders(string $date, ?string $delivery_type_id = null): array
{
    $response = $delivery_type_id
        ? omp_extract_orders($date, $delivery_type_id)
        : omp_extract_orders($date);

    $orders = $response['data'];
    $response_time = $response['response_time'];
    $sorted_orders = omp_sort_extracted_orders($orders);

    return [
    'response_time' => $response_time,
    'data' => $sorted_orders
    ];
}

/**
 * Get dashboard data
 *
 * @param  string      $date             Date in YYYY-MM-DD format
 * @param  string|null $delivery_type_id Delivery type ID, dashboard data will be only for this type
 * @return array Dashboard data
 */
function omp_extract_orders($date, $delivery_type_id = null)
{

    // Get customer orders by date, and by delivery type if set
    if ($delivery_type_id) {
        $db_orders = omp_get_customer_orders_by_date($date, $delivery_type_id);
    } else {
        $db_orders = omp_get_customer_orders_by_date($date);
    }

    $current_datetime = new DateTime();
    $timezone = new DateTimeZone('Europe/Vilnius');
    $current_datetime->setTimezone($timezone);
    $response_time = $current_datetime->format('F d, Y H:i');

    // Return data
    $orders = [];
    // $agg_products = [];
    // $delivery_tables = [];

    // Cache
    $product_cache = [];
    $delivery_sort_number_cache = [];

    foreach ($db_orders as $order) {
        if (isset($order['applicable']) && $order['applicable']) {
            $attributes = $order['attributes'];

            $attributes_data_and_updated_delivery_sort_number_cache =
            omp_get_order_attributes_data($delivery_sort_number_cache, $attributes);

            $attributes_data = $attributes_data_and_updated_delivery_sort_number_cache['attributes_data'];
            $delivery_sort_number_cache =
            $attributes_data_and_updated_delivery_sort_number_cache['delivery_sort_number_cache'];

            // Counterparty data, address comment
            $counterparty = $order['agent']['name'] ?? null;
            $counterparty_phone = $order['agent']['phone'] ?? null;
            $has_google_maps = false;
            if (isset($order['agent']['attributes'])) {
                foreach ($order['agent']['attributes'] as $attr) {
                    if ($attr['id'] === OMP_GOOGLE_MAPS_FIELD_ID) {
                        $google_maps = $attr['value'];
                        $has_google_maps = true;
                        break;
                    }
                }
            }
            if (!$has_google_maps) {
                $google_maps = null;
            }

            $comment = $order['shipmentAddressFull']['comment'] ?? null;

            $order_amount = $order['sum'] / 100;
            $order_status = $order['state']['name'];
            $status_color = $order['state']['color'];

            $db_positions = $order['positions']['rows'];
            $positions = [];
            foreach ($db_positions as $position) {
                // Get product data
                $product_href = $position['assortment']['meta']['href'];
                if (!isset($product_cache[$product_href])) {
                    $product_cache[$product_href] = omp_api_request('GET', $product_href);
                }
                $product_data = $product_cache[$product_href]['data'];

                // Check if product should be hidden
                $is_hidden = false;
                // Get product attributes data
                $product_name = null;
                $sort_number = null;
                $is_bread = false;
                if (!empty($product_data['attributes'])) {
                    foreach ($product_data['attributes'] as $attribute) {
                        if ($attribute['id'] === OMP_PRODUCT_HIDDEN_FIELD_ID && !empty($attribute['value'])) {
                            $is_hidden = true;
                            break;
                        }
                        if ($attribute['id'] === OMP_ARBITRARY_PRODUCT_NAME_FIELD_ID) {
                               $product_name = $attribute['value'];
                        } elseif ($attribute['id'] === OMP_PRODUCT_SORT_NUMBER_FIELD_ID) {
                            $sort_number = $attribute['value'];
                        } elseif ($attribute['id'] === OMP_IS_BREAD_FIELD_ID) {
                            $is_bread = $attribute['value'];
                        }
                    }
                }

                if ($is_hidden) {
                    continue;
                }

                if (!$product_name) {
                    $product_name = $product_data['name'];
                }

                // Calculate position weight
                if (isset($product_data['weight'])) {
                    $product_weight = (float) $product_data['weight']; // grams
                } else {
                    $product_weight = null;
                }
                $position_quantity = (float) $position['quantity'];
                if (!$product_weight || $product_weight == 0) {
                    $product_weight = omp_parse_product_weight($product_name);
                }
                if ($product_weight > 0.0) {
                    $position_weight = $product_weight * $position_quantity / 1000.0; // kilograms
                } else {
                    $position_weight = null;
                }

                $positions [] = [
                'product' => $product_name,
                'quantity' => $position_quantity,
                'weight' => $position_weight,
                'is_bread' => $is_bread,
                'sort_number' => $sort_number
                ];
            }

            $orders [] = [
            'id' => $order['id'],
            'order_number' => $attributes_data['order_number'],
            'counterparty' => $counterparty,
            'google_maps' => $google_maps,
            'counterparty_phone' => $counterparty_phone,
            'city' => $attributes_data['city'],
            'pickup_shop' => $attributes_data['pickup_shop'],
            'tracking_number' => $attributes_data['tracking_number'],
            'delivery' => $attributes_data['delivery'],
            'delivery_type_id' => $attributes_data['delivery_type_id'],
            'delivery_sort_number' => $attributes_data['delivery_sort_number'],
            'order_amount' => $order_amount . ' €',
            'status' => $order_status,
            'status_color' => $status_color,
            'sellers' => $attributes_data['sellers'],
            'comment' => $comment,
            'positions' => $positions
            ];
        }
    }

    return [
    'data' => $orders,
    'response_time' => $response_time
    ];
}

/**
 * @param  array $extracted_orders Extracted orders from MoySklad API response
 * @return array Sorted orders data
 */
function omp_sort_extracted_orders($extracted_orders)
{

    if (empty($extracted_orders)) {
        return $extracted_orders;
    } else {
        $sorted_orders = [];

        // Divide orders into specific groups to apply specific sorting afterwards
        $cafe_orders = [];
        $market_orders = [];
        $non_cafe_and_market_orders = [];
        foreach ($extracted_orders as $order) {
            if ($order['delivery_type_id'] === OMP_CAFE_DELIVERY_TYPE_FIELD_ID) {
                $cafe_orders [] = $order;
            } elseif ($order['delivery_type_id'] === OMP_MARKET_DELIVERY_TYPE_FIELD_ID) {
                $market_orders [] = $order;
            } else {
                $non_cafe_and_market_orders [] = $order;
            }
        }

        // Sort cafe orders
        $sorted_cafe_orders = [];
        foreach ($cafe_orders as $order) {
            if (!$order['pickup_shop']) {
                $sorted_cafe_orders [] = $order;
                foreach ($cafe_orders as $possibly_related_order) {
                    if ($possibly_related_order['pickup_shop'] === $order['counterparty']) {
                        $sorted_cafe_orders [] = $possibly_related_order;
                    }
                }
            }
        }
        foreach ($cafe_orders as $order) {
            if (!in_array($order, $sorted_cafe_orders)) {
                $sorted_cafe_orders [] = $order;
            }
        }
        if (!empty($sorted_cafe_orders)) {
            $cafe_order_sort_number = $cafe_orders[0]['delivery_sort_number'];
        }

        // Sort market orders
        $sorted_market_orders = [];
        foreach ($market_orders as $order) {
            if (!$order['pickup_shop']) {
                $sorted_market_orders [] = $order;
                foreach ($market_orders as $possibly_related_order) {
                    if ($possibly_related_order['pickup_shop'] === $order['counterparty']) {
                        $sorted_market_orders [] = $possibly_related_order;
                    }
                }
            }
        }
        foreach ($market_orders as $order) {
            if (!in_array($order, $sorted_market_orders)) {
                $sorted_market_orders [] = $order;
            }
        }
        if (!empty($sorted_market_orders)) {
            $market_order_sort_number = $market_orders[0]['delivery_sort_number'];
        }

        // Sort other orders
        $non_cafe_and_market_orders_sort_numbers = array_column($non_cafe_and_market_orders, 'delivery_sort_number');
        array_multisort($non_cafe_and_market_orders_sort_numbers, SORT_ASC, $non_cafe_and_market_orders);

        // Combine orders
        if (!isset($cafe_order_sort_number) && (!isset($market_order_sort_number))) {
            $sorted_orders = $non_cafe_and_market_orders;
        } else {
            if (isset($cafe_order_sort_number)) {
                $sorted_orders = omp_add_special_delivery_type_orders(
                    $non_cafe_and_market_orders,
                    $sorted_cafe_orders,
                    (int) $cafe_order_sort_number
                );
            } else {
                $sorted_orders = $non_cafe_and_market_orders;
            }

            if (isset($market_order_sort_number)) {
                $sorted_orders = omp_add_special_delivery_type_orders(
                    $sorted_orders,
                    $sorted_market_orders,
                    (int) $market_order_sort_number
                );
            }
        }

        return $sorted_orders;
    }
}

/**
 * @param  array $sorted_orders Sorted orders
 * @param  array $special_delivery_type_orders Special delivery type orders to insert
 * @param  int   $special_delivery_type_order_sort_number Sort number threshold
 * @return array Merged orders
 */
function omp_add_special_delivery_type_orders(
    array $sorted_orders,
    array $special_delivery_type_orders,
    int $special_delivery_type_order_sort_number
): array {

    if (empty($sorted_orders)) {
        return $special_delivery_type_orders;
    }

    $sorted_orders_with_special = [];
    $count = 0;

    foreach ($sorted_orders as $order) {
        if ($order['delivery_sort_number'] < $special_delivery_type_order_sort_number) {
            $sorted_orders_with_special[] = $order;
        } else {
            break;
        }
        $count++;
    }

    $sorted_orders_with_special = array_merge(
        $sorted_orders_with_special,
        $special_delivery_type_orders
    );
    $remained_orders = array_splice($sorted_orders, $count);
    $sorted_orders_with_special = array_merge(
        $sorted_orders_with_special,
        $remained_orders
    );

    return $sorted_orders_with_special;
}

/**
 * @param  array $extracted_orders Extracted orders from MoySklad API response
 * @return array Total positions data
 */
function omp_get_dashboard_total_positions_data(array $extracted_orders): array
{

    $total_positions = [];
    foreach ($extracted_orders as $order) {
        foreach ($order['positions'] as $position) {
            $total_position_exists = false;

            foreach ($total_positions as &$total_position) {
                if ($total_position['product'] === $position['product']) {
                    $total_position['quantity'] += $position['quantity'];
                    $total_position['weight'] += $position['weight'];
                    $total_position_exists = true;
                    break;
                }
            }
            unset($total_position);

            if (!$total_position_exists) {
                $total_positions [] = $position;
            }
        }
    }
    $total_positions_sort_numbers = array_column($total_positions, 'sort_number');
    array_multisort($total_positions_sort_numbers, SORT_ASC, $total_positions);
    $total_bread_weight = 0;
    $total_positions_bread = [];
    $total_positions_non_bread = [];
    foreach ($total_positions as $total_position) {
        if ($total_position['is_bread']) {
            $total_bread_weight += $total_position['weight'];
            $total_positions_bread [] = $total_position;
        } else {
            $total_positions_non_bread [] = $total_position;
        }
    }

    return [
    'total_bread_weight' => $total_bread_weight,
    'total_positions_bread' => $total_positions_bread,
    'total_positions_non_bread' => $total_positions_non_bread,

    ];
}

/**
 * Get customer orders by date
 *
 * @param  string $date Date in YYYY-MM-DD format
 * @return array Order rows or empty array
 */
function omp_get_customer_orders_by_date(
    $date,
    $delivery_type_id = null, // Only set when $date_field is set to "deliveryPlannedMoment"
    $date_field = 'deliveryPlannedMoment'
) {

    // Get customer orders bringing into attention difference between MoySklad and Host
    $start_dt = new DateTime($date . ' 01:00:00');
    $end_dt = new DateTime($date . ' 00:59:59');
    $end_dt->modify('+1 day');

    $limit = 100;
    $offset = 0; // start order for arbitrary date filtering

    if ($date_field === 'deliveryPlannedMoment') {
        $start = $start_dt->format('Y-m-d H:i:s');
        $end = $end_dt->format('Y-m-d H:i:s');

        $response = omp_api_request(
            'GET',
            'entity/customerorder',
            [
            'filter' => 'deliveryPlannedMoment>=' . $start . ';' . 'deliveryPlannedMoment<=' . $end,
            'limit' => $limit,
            'expand' => 'agent,positions,state'
            ]
        );


        $orders = $response['data']['rows'] ?? [];

        // Filter orders by delivery type
        if ($delivery_type_id) {
            $selected_delivery_type_orders = [];
            foreach ($orders as $order) {
                if (isset($order['attributes'])) {
                    foreach ($order['attributes'] as $attr) {
                        if ($attr['id'] === OMP_DELIVERY_TYPE_FIELD_ID) {
                            $delivery_href_el = explode('/', $attr['value']['meta']['href']);
                            $order_delivery_type_id = $delivery_href_el[array_key_last($delivery_href_el)];
                            if ($order_delivery_type_id === $delivery_type_id) {
                                $selected_delivery_type_orders [] = $order;
                            }
                            break;
                        }
                    }
                }
            }
            $orders = $selected_delivery_type_orders;
        }
    } elseif ($date_field === OMP_DATE_FILTER_FIELD_ID) {
        $orders = [];

        while (true) {
            $response = omp_api_request(
                'GET',
                'entity/customerorder',
                [
                'applicable' => true,
                'limit' => $limit,
                'offset' => $offset,
                'expand' => 'agent,positions,state'
                ]
            );

            if (
                empty($response['success'])
                || empty($response['data']['rows'])
            ) {
                   break;
            }

            foreach ($response['data']['rows'] as $order) {
                if (omp_has_kepimo_data_matching($order, $start_dt, $end_dt)) {
                    $orders[] = $order;
                }
            }

            if (count($response['data']['rows']) < $limit) {
                break;
            }

            $offset += $limit;
        }
    }

    return $orders;
}

/**
 * Get order attributes data
 *
 * @param  array $delivery_sort_number_cache Delivery sort number cache
 * @param  array $attributes                 Order attributes
 * @return array Array with updated delivery sort number cache and attributes data
 */
function omp_get_order_attributes_data($delivery_sort_number_cache, $attributes)
{

    foreach ($attributes as $attr) {
        // Get delivery type and delivery type sort number
        if ($attr['id'] === OMP_DELIVERY_TYPE_FIELD_ID) {
            $delivery = $attr['value']['name'];

            $delivery_href = $attr['value']['meta']['href'];

            $delivery_href_el = explode('/', $delivery_href);

            $delivery_type_id = $delivery_href_el[array_key_last($delivery_href_el)];

            if (!isset($delivery_sort_number_cache[$delivery])) {
                $delivery_api_response = omp_api_request('GET', $delivery_href);

                $delivery_sort_number_cache[$delivery] = $delivery_api_response['data']['code'];
            }

            $delivery_sort_number = $delivery_sort_number_cache[$delivery];
        } elseif ($attr['id'] === OMP_ORDER_NUMBER_FIELD_ID) {
            $order_number = $attr['value'];
        } elseif ($attr['id'] === OMP_CITY_FIELD_ID) {
            $city = $attr['value']['name'];
        } elseif ($attr['id'] === OMP_PICKUP_SHOP_FIELD_ID) {
            $pickup_shop = $attr['value']['name'];
        } elseif ($attr['id'] === OMP_TRACKING_NUMBER_FIELD_ID) {
            $tracking_number = $attr['value']; // To test
        } elseif ($attr['id'] === OMP_SELLERS_FIELD_ID) {
            $sellers = $attr['value'];
        }
    }

    return [
    'attributes_data' => [
    'delivery' => $delivery ?? null,
    'delivery_type_id' => $delivery_type_id ?? null,
    'delivery_sort_number' => $delivery_sort_number ?? null,
    'order_number' => $order_number ?? null,
    'city' => $city ?? null,
    'pickup_shop' => $pickup_shop ?? null,
    'tracking_number' => $tracking_number ?? null,
    'sellers' => $sellers ?? null
    ],
    'delivery_sort_number_cache' => $delivery_sort_number_cache,
    ];
}

/**
 * Make MoySklad API request
 *
 * @param  string $method   HTTP method (GET, POST, etc.)
 * @param  string $endpoint API endpoint path
 * @param  array  $args     Query parameters
 * @return array Response with success/data or error
 */
function omp_api_request($method, $endpoint, $args = [])
{

    $token = omp_get_access_token();
    if (!$token) {
        return ['success' => false, 'error' => 'No access token'];
    }

    // Generate url if endpoint doesn't contain base
    $url = (strpos($endpoint, 'http') === 0)
    ? $endpoint
    : 'https://api.moysklad.ru/api/remap/1.2/' . ltrim($endpoint, '/');

    // Add args
    if (!empty($args)) {
        $url = add_query_arg($args, $url);
    }

    $response = wp_remote_request(
        $url,
        [
        'method'  => $method,
        'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json;charset=utf-8',
        ],
        'timeout' => 15,
        ]
    );

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Invalid JSON response'];
    }

    return [
    'success' => true,
    'code'    => wp_remote_retrieve_response_code($response),
    'data'    => $data,
    ];
}

/**
 * Find product weight from product name
 *
 * @param  string $product_name Product name
 * @param  string $desired_unit Return weight with this unit (default 'g')
 * @return float Weight if there is a weight in g/kg at the end of the string, false otherwise
 */
function omp_parse_product_weight($product_name, $desired_unit = 'g')
{

    preg_match('/.*(?<weight>\d+)\s?(?<unit>g|kg)\s*$/', $product_name, $matches);

    if (!isset($matches['weight']) || !isset($matches['unit'])) {
        return false;
    }

    $weight = (float) $matches['weight'];
    $unit = $matches['unit'];

    if ($unit === $desired_unit) {
        return $weight;
    }

    if ($unit === 'g' && $desired_unit === 'kg') {
        return $weight / 1000;
    }

    if ($unit === 'kg' && $desired_unit === 'g') {
        return $weight * 1000;
    }

    return false;
}
