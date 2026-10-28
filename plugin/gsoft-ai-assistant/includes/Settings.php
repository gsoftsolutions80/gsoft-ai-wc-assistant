<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

class Settings {

	public const OPTION = 'gsai_settings';

	private const DEFAULTS = array(
		'api_base_url'       => '',
		'site_key'           => '',
		'site_secret'        => '',
		'tracking_enabled'   => 1,
		'consent_mode'       => 'wp_consent_api',
		'backfill_confirmed' => 0,
	);

	private ?array $cache = null;

	public function register(): void {
		$reset = function (): void {
			$this->cache = null;
		};
		add_action( 'add_option_' . self::OPTION, $reset );
		add_action( 'update_option_' . self::OPTION, $reset );
	}

	public function all(): array {
		return $this->cache ??= wp_parse_args( (array) get_option( self::OPTION, array() ), self::DEFAULTS );
	}

	public function get( string $key, $default = null ) {
		return $this->all()[ $key ] ?? $default;
	}

	public function is_configured(): bool {
		return '' !== $this->get( 'api_base_url' ) && '' !== $this->get( 'site_key' ) && '' !== $this->get( 'site_secret' );
	}

	/** Sanitize callback for register_setting(). */
	public static function sanitize( $input ): array {
		$old   = wp_parse_args( (array) get_option( self::OPTION, array() ), self::DEFAULTS );
		$input = is_array( $input ) ? $input : array();

		$url = untrailingslashit( esc_url_raw( trim( (string) ( $input['api_base_url'] ?? '' ) ) ) );
		if ( '' !== $url && 0 !== strpos( $url, 'https://' ) && ! self::is_local_url( $url ) ) {
			add_settings_error( self::OPTION, 'gsai_https', __( 'The API URL must use HTTPS.', 'gsoft-ai-assistant' ) );
			$url = (string) $old['api_base_url'];
		}

		$secret = trim( (string) ( $input['site_secret'] ?? '' ) );
		if ( '' === $secret ) {
			$secret = (string) $old['site_secret'];
		}

		$mode = (string) ( $input['consent_mode'] ?? 'wp_consent_api' );

		return array(
			'api_base_url'       => $url,
			'site_key'           => sanitize_text_field( (string) ( $input['site_key'] ?? '' ) ),
			'site_secret'        => $secret,
			'tracking_enabled'   => empty( $input['tracking_enabled'] ) ? 0 : 1,
			'consent_mode'       => in_array( $mode, array( 'wp_consent_api', 'always' ), true ) ? $mode : 'wp_consent_api',
			'backfill_confirmed' => empty( $input['backfill_confirmed'] ) ? 0 : 1,
		);
	}

	private static function is_local_url( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || (bool) preg_match( '/\.(test|local|localhost)$/', $host );
	}
}
