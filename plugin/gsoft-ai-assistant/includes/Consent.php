<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Consent gate. Nothing is tracked and no cookie is set unless tracking_allowed() is true.
 * Default mode relies on the WP Consent API (works with Complianz, CookieYes, etc.); if it is not
 * installed, tracking stays OFF. "always" is for sites whose consent is handled elsewhere.
 */
class Consent {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_cross_site_choice' ) );
		add_action( 'woocommerce_edit_account_form', array( $this, 'render_account_field' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_account_field' ) );
	}

	public function tracking_allowed(): bool {
		if ( ! $this->settings->get( 'tracking_enabled' ) ) {
			return false;
		}

		if ( 'always' === $this->settings->get( 'consent_mode' ) ) {
			$allowed = true;
		} else {
			$category = (string) apply_filters( 'gsai_consent_category', 'marketing' );
			$allowed  = function_exists( 'wp_has_consent' ) && wp_has_consent( $category );
		}

		return (bool) apply_filters( 'gsai_tracking_allowed', $allowed );
	}

	public function cross_site_for_user( int $user_id ): bool {
		return '1' === get_user_meta( $user_id, 'gsai_cross_site_consent', true );
	}

	/** Staff browsing/testing the shop should not pollute shopper profiles. */
	public function ignore_user( \WP_User $user ): bool {
		return (bool) apply_filters( 'gsai_ignore_user', user_can( $user, 'manage_woocommerce' ), $user );
	}

	/** Remember, per order, whether tracking was allowed at checkout (gates later status events). Caller saves the order. */
	public function record_order_consent( \WC_Order $order, bool $allowed ): void {
		if ( '' === $order->get_meta( '_gsai_tracking_consent' ) ) {
			$order->update_meta_data( '_gsai_tracking_consent', $allowed ? '1' : '0' );
		}
	}

	public function render_checkout_field(): void {
		if ( ! $this->settings->is_configured() || ! $this->tracking_allowed() ) {
			return;
		}

		$checked = is_user_logged_in() && $this->cross_site_for_user( get_current_user_id() );

		echo '<input type="hidden" name="gsai_cross_site_present" value="1" />';
		woocommerce_form_field(
			'gsai_cross_site_consent',
			array(
				'type'     => 'checkbox',
				'label'    => esc_html__( 'Recognise me across other shops that use this assistant, to give me better recommendations.', 'gsoft-ai-assistant' ),
				'required' => false,
			),
			$checked ? 1 : 0
		);
	}

	public function save_cross_site_choice( \WC_Order $order ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified by WooCommerce during checkout.
		if ( ! isset( $_POST['gsai_cross_site_present'] ) ) {
			return;
		}
		$value = empty( $_POST['gsai_cross_site_consent'] ) ? '0' : '1';
		// phpcs:enable

		$order->update_meta_data( '_gsai_cross_site_consent', $value );
		if ( $order->get_customer_id() ) {
			update_user_meta( $order->get_customer_id(), 'gsai_cross_site_consent', $value );
		}
	}

	public function render_account_field(): void {
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$checked = $this->cross_site_for_user( get_current_user_id() );
		echo '<p class="woocommerce-form-row form-row"><label><input type="checkbox" name="gsai_cross_site_consent" value="1" ' . checked( $checked, true, false ) . ' /> ';
		echo esc_html__( 'Recognise me across other shops that use this assistant, to give me better recommendations.', 'gsoft-ai-assistant' ) . '</label>';
		echo '<input type="hidden" name="gsai_cross_site_present" value="1" /></p>';
	}

	public function save_account_field( int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified by WooCommerce on account save.
		if ( ! isset( $_POST['gsai_cross_site_present'] ) ) {
			return;
		}
		$value = empty( $_POST['gsai_cross_site_consent'] ) ? '0' : '1';
		// phpcs:enable

		update_user_meta( $user_id, 'gsai_cross_site_consent', $value );
		do_action( 'gsai_consent_changed', $user_id );
	}
}
