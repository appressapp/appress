<?php

namespace Appress\Integration\Voxel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Voxel_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Register integration card on the Appress Integrations page.
		$this->filter( 'appress/integrations/registered', '@register_integration' );

		// Run integrations ONLY when dynamically activated by the Integrations Manager.
		$this->on( 'appress/integration/voxel/execute', '@bootstrap_integrations' );

		// Detail-page renderer (Integrations → Voxel)
		$this->on( 'appress/integrations/admin_template/voxel', '@render_detail' );

		// Events_Controller is always on (regardless of module toggle)
		// so the Voxel event catalogue shows up on the Integrations → Voxel
		// detail page before the admin enables the module — they need
		// to see what's available in order to configure target apps +
		// push copy upfront. Runtime dispatchers inside the controller
		// gate themselves on `class_exists( \Voxel\Events\Base_Event )`
		// so this is a no-op on sites that don't run Voxel.
		new Controllers\Events_Controller();
	}

	protected function register_integration( $integrations ) {
		$integrations['voxel'] = [
			'name'         => __( 'Voxel Theme', 'appress' ),
			'description'  => __( 'Enable native integrations: Visibility Rules, App Events, and Google Login', 'appress' ),
			'color'        => 'purple',
			'icon'            => APPRESS_PLUGIN_URL . 'app/integration/voxel/logo.svg',

			'configurable' => true, // has a detail page (Events + IAP tabs)
			'requires_plugin' => [
				'name'  => 'Voxel Theme',
				'class' => '\\Voxel\\Post',
			],
			'integrations'     => [
				'events'       => __( 'In-app Voxel event', 'appress' ),
				'google_login' => __( 'Google Login', 'appress' ),
				'indicators'   => __( 'Cart, Message & Notification indicators', 'appress' ),
			]
		];
		return $integrations;
	}

	protected function bootstrap_integrations() {
		// All integrations auto-enabled when main integration is on.
		// Events_Controller is intentionally NOT here — it's instantiated
		// in hooks() so the event schema shows up in the Integrations admin
		// UI even when Voxel module is toggled off.
		new \Appress\Integration\Voxel\Controllers\Visibility_Rules_Controller();
		new \Appress\Integration\Voxel\Controllers\Google_Login_Controller();
		new \Appress\Integration\Voxel\Controllers\Indicator_Controller();
		new \Appress\Integration\Voxel\Controllers\Notifications_Controller();
		new \Appress\Integration\Voxel\Controllers\App_Css_Controller();
		new \Appress\Integration\Voxel\Controllers\Subscreen_Patterns_Controller();
	}

	/**
	 * Voxel detail page. Events tab + IAP placeholder (membership plan
	 * mapping lands with the IAP integration).
	 */
	public function render_detail() {
		$tabs = [
			'events' => __( 'Events', 'appress' ),
			'iap'    => [
				'text'  => __( 'In-App Purchase', 'appress' ),
				'badge' => __( 'Coming soon', 'appress' ),
			],
		];
		$active = \Appress\current_integration_tab( $tabs, 'events' );
		\Appress\render_integration_tab_bar( 'voxel', $tabs, $active );

		if ( $active === 'events' ) {
			\Appress\render_integration_events_panel( 'voxel' );
			return;
		}

		// Layout uses inline styles for padding / max-width / text-align
		// because WordPress admin CSS is unlayered and wins the cascade
		// against Tailwind's `@layer utilities`. Without these inlines,
		// `p-8` / `max-w-xl` / `text-center` get silently overridden on
		// deeply-nested wp-admin `.wrap` descendants. Colors + borders
		// stay as utility classes because they're not fought for by WP.
		?>
		<div class="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl" style="padding:2.5rem;text-align:center;">
			<div class="rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-100 dark:border-amber-500/20 text-amber-500 flex items-center justify-center" style="width:3rem;height:3rem;margin:0 auto 1rem;">
				<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
			</div>
			<h2 class="font-bold text-gray-800 dark:text-white/90" style="font-size:1rem;margin:0 0 0.5rem;"><?php esc_html_e( 'In-App Purchase — coming soon', 'appress' ); ?></h2>
			<p class="text-gray-500 dark:text-gray-400" style="font-size:0.875rem;line-height:1.625;max-width:36rem;margin:0 auto;">
				<?php esc_html_e( 'Mapping Voxel membership plans to StoreKit / Play Billing product IDs ships in a later release. Until then, memberships continue to use your regular web checkout.', 'appress' ); ?>
			</p>
		</div>
		<?php
	}
}
