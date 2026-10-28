<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

/** Signed HTTP client for the platform API (HMAC-SHA256 over "{timestamp}.{nonce}.{body}"). */
class ApiClient {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/** @return array{code:int,body:array}|\WP_Error */
	public function get( string $path ) {
		return $this->request( 'GET', $path, null );
	}

	/** @return array{code:int,body:array}|\WP_Error */
	public function post( string $path, array $body ) {
		return $this->request( 'POST', $path, $body );
	}

	/** @param array|\WP_Error $response */
	public static function is_success( $response ): bool {
		return is_array( $response ) && $response['code'] >= 200 && $response['code'] < 300;
	}

	/** @param array|\WP_Error $response */
	public static function error_message( $response ): string {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$detail = $response['body']['error'] ?? $response['body']['message'] ?? '';
		return sprintf( 'HTTP %d %s', $response['code'], is_string( $detail ) ? $detail : '' );
	}

	/** @return array{code:int,body:array}|\WP_Error */
	private function request( string $method, string $path, ?array $body ) {
		if ( ! $this->settings->is_configured() ) {
			return new \WP_Error( 'gsai_not_configured', __( 'The plugin is not configured yet.', 'gsoft-ai-assistant' ) );
		}

		$json = '';
		if ( null !== $body ) {
			$json = wp_json_encode( $body );
			if ( false === $json ) {
				return new \WP_Error( 'gsai_json', __( 'Could not encode the request body.', 'gsoft-ai-assistant' ) );
			}
		}

		$timestamp = (string) time();
		$nonce     = wp_generate_password( 24, false, false );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $json, (string) $this->settings->get( 'site_secret' ) );

		$response = wp_remote_request(
			$this->settings->get( 'api_base_url' ) . '/api/v1/' . ltrim( $path, '/' ),
			array(
				'method'     => $method,
				'timeout'    => 8,
				'user-agent' => 'GSoftAIAssistant/' . GSAI_VERSION,
				'headers'    => array(
					'Accept'           => 'application/json',
					'Content-Type'     => 'application/json',
					'X-GSAI-Key'       => (string) $this->settings->get( 'site_key' ),
					'X-GSAI-Timestamp' => $timestamp,
					'X-GSAI-Nonce'     => $nonce,
					'X-GSAI-Signature' => $signature,
				),
				'body'       => $json,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => is_array( $decoded ) ? $decoded : array(),
		);
	}
}
