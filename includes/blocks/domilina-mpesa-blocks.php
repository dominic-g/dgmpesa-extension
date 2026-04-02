<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * DOMILINA_Mpesa_Block_Payment
 *
 * Registers M-Pesa as a payment method in the WooCommerce Blocks checkout.
 * Extends AbstractPaymentMethodType to supply the JS script handle and
 * gateway settings data to the React-based checkout block.
 *
 * @extends AbstractPaymentMethodType
 */
final class DOMILINA_Mpesa_Block_Payment extends AbstractPaymentMethodType {

	/** @var string Must match the gateway's $id property. */
	protected $name = 'domilina_mpesa_checkout';

	/** @var string Script handle registered via wp_register_script. */
	private const SCRIPT_HANDLE = 'domilina-mpesa-block-checkout';

	// -----------------------------------------------------------------------
	// Lifecycle
	// -----------------------------------------------------------------------

	public function initialize() {
		$this->settings = get_option( 'woocommerce_domilina_mpesa_checkout_settings', [] );
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	// -----------------------------------------------------------------------
	// Script registration
	// -----------------------------------------------------------------------

	public function get_payment_method_script_handles() {
		$asset_file = DOMILINA_MPESA_PLUGIN_PATH . 'assets/js/mpesa-block-checkout.asset.php';

		$version      = DOMILINA_MPESA_VERSION;
		$dependencies = [ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-polyfill' ];

		if ( file_exists( $asset_file ) ) {
			$asset        = require $asset_file;
			$version      = $asset['version']      ?? $version;
			$dependencies = $asset['dependencies'] ?? $dependencies;
		}

		wp_register_script(
			self::SCRIPT_HANDLE,
			DOMILINA_MPESA_PLUGIN_URL . 'assets/js/mpesa-block-checkout.js',
			$dependencies,
			$version,
			true
		);

		return [ self::SCRIPT_HANDLE ];
	}

	// -----------------------------------------------------------------------
	// Data exposed to JS
	// -----------------------------------------------------------------------

	public function get_payment_method_data() {
		return [
			'title'    => $this->get_setting( 'title' ),
			'supports' => $this->get_supported_features(),
		];
	}

	public function get_supported_features() {
		return [ 'products' ];
	}
}
