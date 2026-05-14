<?php

namespace Appress\Integration\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woocommerce_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->filter( 'appress/integrations/registered', '@register_integration' );
		$this->on( 'appress/integration/woocommerce/execute', '@bootstrap_integrations' );
		$this->on( 'appress/integrations/admin_template/woocommerce', '@render_detail' );

		// Events_Controller + Iap_Controller are always on so the
		// Integrations → WooCommerce detail page (Events + IAP tabs) can
		// read + write their data regardless of the module toggle —
		// admins need to pre-configure things before flipping the
		// master switch. Runtime dispatchers inside gate themselves
		// on `class_exists( 'WooCommerce' )` so it's a no-op on sites
		// without WC installed.
		if ( class_exists( 'WooCommerce' ) ) {
			new Controllers\Events_Controller();
			new Controllers\Iap_Controller();
		}
	}

	protected function register_integration( $integrations ) {
		$integrations['woocommerce'] = [
			'name'         => __( 'WooCommerce', 'appress' ),
			'description'  => __( 'Enable native cart indicator with real-time badge updates and order lifecycle push notifications', 'appress' ),
			'color'        => 'purple',
			'icon'            => APPRESS_PLUGIN_URL . 'app/integration/woocommerce/logo.svg',

			'configurable' => true, // has a detail page (Events + IAP tabs)
			'requires_plugin' => [
				'name'  => 'WooCommerce',
				'class' => 'WooCommerce',
			],
			'integrations'     => [
				'indicators' => __( 'Cart indicator', 'appress' ),
				'events'     => __( 'App events (orders, reviews, stock)', 'appress' ),
			]
		];
		return $integrations;
	}

	protected function bootstrap_integrations() {
		// Events_Controller is intentionally NOT here — it's instantiated
		// in hooks() so the event schema shows up in the Integrations admin UI
		// even when the WooCommerce module is toggled off.
		if ( class_exists( 'WooCommerce' ) ) {
			new \Appress\Integration\Woocommerce\Controllers\Indicator_Controller();
			new \Appress\Integration\Woocommerce\Controllers\Inline_Links_Controller();
			new \Appress\Integration\Woocommerce\Controllers\Account_Controller();
		}
	}

	/**
	 * WooCommerce detail page. Two tabs for now:
	 *   • Events — embeds the shared events panel
	 *   • In-App Purchase — placeholder; real product-mapping UI lands
	 *     with the IAP integration. Left visible (even before IAP ships) so
	 *     admins know where to look for it later.
	 */
	public function render_detail() {
		$tabs = [
			'events' => __( 'Events', 'appress' ),
			// IAP tab is on hold — setup (Apple Paid Applications
			// agreement, receipt verify endpoint, native StoreKit /
			// Play Billing integration, S2S renewal webhooks) is a
			// multi-phase build. Tab stays visible so admins see it's
			// on the roadmap; content is a placeholder until phase 2.
			'iap'    => [
				'text'  => __( 'In-App Purchase', 'appress' ),
				'badge' => __( 'Coming soon', 'appress' ),
			],
		];
		$active = \Appress\current_integration_tab( $tabs, 'events' );
		\Appress\render_integration_tab_bar( 'woocommerce', $tabs, $active );

		if ( $active === 'events' ) {
			\Appress\render_integration_events_panel( 'woocommerce' );
			return;
		}

		// IAP placeholder — no Vue bundle, no AJAX endpoints called.
		// Keep the full PHP admin surface + schema fields wired (they
		// persist harmlessly to meta / options) so the transition to
		// the real implementation later doesn't require UI rework,
		// just unhiding the Vue mount.
		// See Voxel controller for why critical layout props are inline
		// (WP admin CSS is unlayered and wins over `@layer utilities`).
		?>
		<div class="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl" style="padding:2.5rem;text-align:center;">
			<div class="rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-100 dark:border-amber-500/20 text-amber-500 flex items-center justify-center" style="width:3rem;height:3rem;margin:0 auto 1rem;">
				<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
			</div>
			<h2 class="font-bold text-gray-800 dark:text-white/90" style="font-size:1rem;margin:0 0 0.5rem;"><?php esc_html_e( 'In-App Purchase — coming soon', 'appress' ); ?></h2>
			<p class="text-gray-500 dark:text-gray-400" style="font-size:0.875rem;line-height:1.625;max-width:36rem;margin:0 auto;">
				<?php esc_html_e( 'StoreKit (iOS) and Google Play Billing support will ship in a later release. The full flow — product mapping, receipt verification, subscription renewal webhooks, WooCommerce order completion — depends on finishing the native purchase pipeline and passing Apple\'s Paid Applications agreement checks. Until then, digital products continue to use your regular web checkout.', 'appress' ); ?>
			</p>
		</div>
		<?php
	}
}
