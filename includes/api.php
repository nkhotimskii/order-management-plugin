<?php
defined('ABSPATH') || exit;

/**
 * Get MoySklad access token
 *
 * @return string|false
 */
function omp_get_access_token() {

	// Get token data
	$token_data = get_option('omp_access_token_data', []);

	// Reuse token if exists
	if (
		!empty($token_data['access_token']) &&
		time() < $token_data['expires_at']
	) {
		return $token_data['access_token'];
	}

	// Get login and password
	$login    = get_option('omp_login');
	$password = get_option('omp_password');
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
			'expires_at'   => time() + ((int) ($data['expires_in'] ?? 3600)) - 60,
		]
	);

	return $data['access_token'];
}

/**
 * Make MoySklad API request
 *
 * @param string $method HTTP method (GET, POST, etc.)
 * @param string $endpoint API endpoint path
 * @param array  $args    Query parameters
 * @return array Response with success/data or error
 */
function omp_api_request($method, $endpoint, $args = []) {

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
 * Aggregated orders products with total weights and quantity
 *
 * @param string $date Date in YYYY-MM-DD format
 * @return array Aggregated orders products with weights and quantity or empty array
 */
function omp_agg_products($date) {

	$agg_orders_products = [];
	$product_cache = [];

	$db_orders = omp_get_customer_orders_by_date($date);

	foreach ($db_orders as $order) {

		$positions = $order['positions']['rows'] ?? [];

		foreach ($positions as $position) {

			$product_href = $position['assortment']['meta']['href'];

			if (!isset($product_cache[$product_href])) {
				$product_cache[$product_href] = omp_api_request('GET', $product_href);
			}

			$product_data = $product_cache[$product_href]['data'];
			$arbitrary_name = false;
			if (!empty($product_data['attributes'])) {
				foreach ($product_data['attributes'] as $attribute) {
					if ($attribute['name'] === 'Trumpas pavadinimas') {
						$product_name = $attribute['value'];
						$arbitrary_name = true;
						break;
					}
				}
			}
			if (!$arbitrary_name) {
				$product_name = $product_data['name'];
			}
			$product_weight = $product_data['weight']; // grams
			$position_quantity = $position['quantity'];
			$position_weight = $product_weight * $position_quantity / 1000; // kilograms

			// Create an associative array or add weight to existing one
			$found = false;
			foreach ($agg_orders_products as &$item) {
				if ($item['product_name'] === $product_name) {
					$item['weight'] += $position_weight;
					$item['quantity'] += $position_quantity;
					$found = true;
					break;
				}
			}
			unset($item);
			if (!$found) {
				$agg_orders_products[] = [
					'product_name' => $product_name,
					'weight' => $position_weight,
					'quantity' => $position_quantity
				];
			}
		}
	}

	return $agg_orders_products;
}

/**
 * Get customer orders by date
 *
 * @param string $date Date in YYYY-MM-DD format
 * @return array Order rows or empty array
 */
function omp_get_customer_orders_by_date($date) {

	$orders = [];
	$start_dt = new DateTime($date . ' 00:00:00');
	$end_dt   = new DateTime($date . ' 23:59:59');

	$limit = 100;
	$offset = 0;

	while (true) {

		$response = omp_api_request('GET', 'entity/customerorder', [
			'limit' => $limit,
			'offset' => $offset,
			'expand' => 'positions'
		]);

		if (empty($response['success'])
			|| empty($response['data']['rows'])) {
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

	return $orders;
}

/**
 * Check if order has "Kepimo data" attribute matching date range
 * 
 * @param array $order Order data from MoySklad API
 * @param DateTime $start_dt Start of date range (e.g. 2026-01-14 00:00:00)
 * @param DateTime $end_dt End of date range (e.g. 2026-01-14 23:59:59)
 * @return bool True if "Kepimo data" exists and is within range, false otherwise
 */
function omp_has_kepimo_data_matching($order, $start_dt, $end_dt) {

	foreach ($order['attributes'] ?? [] as $attribute) {
		if ($attribute['name'] === 'Kepimo data' && !empty($attribute['value'])) {
			$date_formatted = preg_replace('/\.\d+$/', '', $attribute['value']);
			$attr_dt = new DateTime($date_formatted);
			return $attr_dt >= $start_dt && $attr_dt <= $end_dt;
		}
	}

	return false;
}
