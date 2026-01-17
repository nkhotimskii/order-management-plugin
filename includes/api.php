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

			// Get product data
			$product_href = $position['assortment']['meta']['href'];
			if (!isset($product_cache[$product_href])) {
				$product_cache[$product_href] = omp_api_request('GET', $product_href);
			}
			$product_data = $product_cache[$product_href]['data'];

			// Get product name, sort number
			$arbitrary_name = null;
			$sort_number = null;
			if (!empty($product_data['attributes'])) {
				foreach ($product_data['attributes'] as $attribute) {
					if ($attribute['name'] === 'Trumpas pavadinimas') {
						$product_name = $attribute['value'];
						$arbitrary_name = true;
					}
					if ($attribute['name'] === 'Rūšiavimas') {
						$sort_number = $attribute['value'];
					}
					if ($arbitrary_name && $sort_number) {
						break;
					}
				}
			}
			if (!$arbitrary_name) {
				$product_name = $product_data['name'];
			}

			// Calculate position weight
			$product_weight = (float) $product_data['weight']; // grams
			$position_quantity = (float) $position['quantity'];
			if ($product_weight == 0 || empty($product_weight)) {
				$product_weight = omp_parse_product_weight($product_name);
			} 
			if ($product_weight > 0) {
				$position_weight = $product_weight * $position_quantity / 1000; // kilograms
			} else {
				$position_weight = 'Error parsing weight';
			}

			// Create an associative array or add weight and quantity to existing one
			$found = false;
			foreach ($agg_orders_products as &$item) {
				if ($item['product_name'] === $product_name) {
					if (!is_string($position_weight)) {
						$item['weight'] += $position_weight;
					}
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
					'quantity' => $position_quantity,
					'sort_number' => $sort_number
				];
			}
		}
	}

	// Sort products
	$sort_numbers = [];
	foreach ($agg_orders_products as $product) {

		$sort_numbers []= $product['sort_number'];
	}

	sort($sort_numbers);

	$agg_products_sorted = [];
	foreach ($sort_numbers as $sort_number) {

		foreach ($agg_orders_products as $product_to_sort) {

			if ($product_to_sort['sort_number'] === $sort_number) {

				$agg_products_sorted []= $product_to_sort;
			}
		}
	}

	// Check if bread or not
	foreach ($agg_products_sorted as &$product) {
		if (!$product['sort_number'] || $product['sort_number'] == '') {
			$product['is_bread'] = false;
		} else {
			$product['is_bread'] = true;
		}
	}
	unset($product);

	return $agg_products_sorted;
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

/**
 * Find product weight from product name
 * 
 * @param string $product_name Product name
 * @param string $desired_unit Return weight with this unit (default 'g')
 * @return float Weight if there is a weight in g/kg at the end of the string, false otherwise
 */
function omp_parse_product_weight($product_name, $desired_unit='g') {

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
