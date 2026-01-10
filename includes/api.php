<?php
defined('ABSPATH') || exit;

/**
 * Get MoySklad access token
 *
 * @return string|false
 */

function omp_get_access_token() {

	$token_data = get_option('omp_access_token_data', []);

	// Reuse valid token
	if (
		!empty($token_data['access_token']) &&
		!empty($token_data['expires_at']) &&
		time() < $token_data['expires_at']
	) {
		return $token_data['access_token'];
	}

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
				'Accept'        => 'application/json',
			],
			'timeout' => 15,
		]
	);

	if (is_wp_error($response)) {
		return false;
	}

	$body = wp_remote_retrieve_body($response);
	$data = json_decode($body, true);

	if (empty($data['access_token'])) {
		return false;
	}

	$token_data = [
		'access_token' => $data['access_token'],
		'expires_at'   => time() + (int)($data['expires_in'] ?? 3600) - 60,
	];

	update_option('omp_access_token_data', $token_data);

	return $data['access_token'];
}
