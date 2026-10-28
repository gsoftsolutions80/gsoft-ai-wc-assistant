<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

/** First-party visitor cookie (server-set, HttpOnly, 13 months). */
class Visitor {

	public const COOKIE = 'gsai_vid';

	private Consent $consent;

	public function __construct( Consent $consent ) {
		$this->consent = $consent;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'ensure_cookie' ), 1 );
	}

	/** Valid visitor UUID from the cookie, or null. */
	public function current(): ?string {
		$value = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ? strtolower( $value ) : null;
	}

	public function is_bot(): bool {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return '' === $agent || 1 === preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|headless|lighthouse|preview/i', $agent );
	}

	public function ensure_cookie(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( $this->current() || $this->is_bot() || ! $this->consent->tracking_allowed() ) {
			return;
		}

		self::set_cookie( self::COOKIE, wp_generate_uuid4() );
	}

	public static function set_cookie( string $name, string $value ): void {
		$_COOKIE[ $name ] = $value;

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + 395 * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
