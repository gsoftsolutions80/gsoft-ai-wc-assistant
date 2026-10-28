<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Links the anonymous visitor cookie to a known person (login, checkout, newsletter).
 * Only a SHA-256 of the normalised email ever leaves the shop; the platform re-hashes it with its own salt.
 */
class Identity {

	private const MARKER = 'gsai_idf';

	private Settings $settings;
	private Consent $consent;
	private Visitor $visitor;
	private Outbox $outbox;

	public function __construct( Settings $settings, Consent $consent, Visitor $visitor, Outbox $outbox ) {
		$this->settings = $settings;
		$this->consent  = $consent;
		$this->visitor  = $visitor;
		$this->outbox   = $outbox;
	}

	public function register(): void {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'init', array( $this, 'sync_logged_in_user' ), 20 );
		add_action( 'gsai_identify_email', array( $this, 'identify_email' ), 10, 2 );
		add_action( 'gsai_consent_changed', array( $this, 'on_consent_changed' ) );
	}

	public static function hash_email( string $email ): ?string {
		$email = strtolower( trim( $email ) );
		return is_email( $email ) ? hash( 'sha256', $email ) : null;
	}

	/** @return array{wc_user_id?:int,email_sha256?:string} */
	public static function for_user( \WP_User $user ): array {
		$identity = array( 'wc_user_id' => (int) $user->ID );
		$hash     = self::hash_email( (string) $user->user_email );
		if ( $hash ) {
			$identity['email_sha256'] = $hash;
		}
		return $identity;
	}

	public function current(): ?array {
		return is_user_logged_in() ? self::for_user( wp_get_current_user() ) : null;
	}

	public function on_login( string $user_login, \WP_User $user ): void {
		if ( $this->consent->ignore_user( $user ) ) {
			return;
		}
		$this->identify( $user->ID, $user->user_email, 'login' );
	}

	/** Covers cleared cookies, consent changes and sessions that predate the cookie. */
	public function sync_logged_in_user(): void {
		if ( ! is_user_logged_in() || ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$user = wp_get_current_user();
		$anon = $this->visitor->current();
		if ( ! $anon || $this->consent->ignore_user( $user ) || ! $this->ready() ) {
			return;
		}

		$expected = $this->marker( $anon, $user->ID, $this->consent->cross_site_for_user( $user->ID ) );
		$actual   = isset( $_COOKIE[ self::MARKER ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::MARKER ] ) ) : '';
		if ( ! hash_equals( $expected, $actual ) ) {
			$this->identify( $user->ID, $user->user_email, 'login' );
		}
	}

	public function identify_email( $email, $source = 'newsletter' ): void {
		if ( ! is_string( $email ) ) {
			return;
		}
		$this->identify( null, $email, in_array( $source, array( 'newsletter', 'checkout' ), true ) ? $source : 'newsletter' );
	}

	public function on_consent_changed( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user && ! $this->consent->ignore_user( $user ) ) {
			$this->identify( $user->ID, $user->user_email, 'login' );
		}
	}

	public function identify_order( \WC_Order $order ): void {
		$meta  = $order->get_meta( '_gsai_cross_site_consent' );
		$cross = '' === $meta ? null : '1' === $meta;
		$this->identify( $order->get_customer_id() ? $order->get_customer_id() : null, (string) $order->get_billing_email(), 'checkout', $cross );
	}

	private function identify( ?int $user_id, ?string $email, string $source, ?bool $cross_site = null ): void {
		if ( ! $this->ready() ) {
			return;
		}
		$anon = $this->visitor->current();
		$hash = $email ? self::hash_email( $email ) : null;
		if ( ! $anon || ( ! $user_id && ! $hash ) ) {
			return;
		}

		if ( null === $cross_site && $user_id ) {
			$cross_site = $this->consent->cross_site_for_user( $user_id );
		}

		$payload = array(
			'anon_id'          => $anon,
			'source'           => $source,
			'consent_tracking' => true,
		);
		if ( $user_id ) {
			$payload['wc_user_id'] = $user_id;
		}
		if ( $hash ) {
			$payload['email_sha256'] = $hash;
		}
		if ( null !== $cross_site ) {
			$payload['consent_cross_site'] = $cross_site;
		}

		$this->outbox->enqueue( 'identify', $payload );

		if ( $user_id ) {
			Visitor::set_cookie( self::MARKER, $this->marker( $anon, $user_id, (bool) $cross_site ) );
		}
	}

	private function ready(): bool {
		return $this->settings->is_configured() && ! $this->visitor->is_bot() && $this->consent->tracking_allowed();
	}

	private function marker( string $anon, int $user_id, bool $cross_site ): string {
		return substr( hash_hmac( 'sha256', $anon . '|' . $user_id . '|' . (int) $cross_site, wp_salt( 'auth' ) ), 0, 20 );
	}
}
