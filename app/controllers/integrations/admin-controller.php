<?php

namespace Appress\Controllers\Integrations;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.NonceVerification.Missing
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin_Controller — dispatcher for the Integrations page.
 *
 * `?page=appress-integrations` (no `integration` param) → PHP renders the
 * list of integration cards.
 *
 * `?page=appress-integrations&integration=X` → PHP fires the per-integration
 * render hook `appress/integrations/admin_template/{X}`. Each integration
 * (WooCommerce, Voxel, FluentCRM, core Appress events, …) owns its
 * detail template end-to-end — it may emit pure PHP, mount a Vue
 * panel, run a WC product search widget, anything. The goal is to
 * stop forcing every integration through one rigid schema / UI.
 *
 * No hook registered for the requested id → {@see render_default_integration_detail}
 * provides a best-effort fallback (Overview + shared events panel if
 * the integration has events).
 *
 * "Integration" is the umbrella on the admin side for things the user
 * can enable / configure — integrations with external plugins
 * (WooCommerce, Voxel, FluentCRM) AND built-in modules (Appress core
 * events). 3rd-party plugin code still lives under `app/integration/`,
 * which keeps its name because those are genuinely integrations; the
 * admin UI just calls the collective surface "integrations".
 */
class Admin_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		if ( is_admin() ) {
			$this->on( 'admin_menu', '@register_menus', 20 );
			$this->on( 'admin_enqueue_scripts', '@enqueue_scripts' );
		}
	}

	protected function register_menus() {
		// Position 1 puts Integrations immediately after the auto-generated parent
		// duplicate entry ("Appress" → main dashboard), ahead of Broadcast.
		add_submenu_page(
			'appress',
			__( 'Integrations', 'appress' ),
			__( 'Integrations', 'appress' ),
			'manage_options',
			'appress-integrations',
			[ $this, 'render_integrations_page' ],
			1
		);
	}

	protected function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'appress' ) === false ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'appress';
		$integration_id = isset( $_GET['integration'] ) ? sanitize_text_field( wp_unslash( $_GET['integration'] ) ) : '';

		if ( $page !== 'appress-integrations' ) {
			return;
		}

		wp_enqueue_style( 'appress:admin.css' );

		// Only enqueue the list bundle on the list page — detail pages
		// are owned by the integration and shouldn't pay the cost of the
		// list grid's reactive module-toggle logic.
		if ( $integration_id === '' ) {
			wp_enqueue_script( 'appress:integrations.js' );
			// Bootstrap config is attached to the bundle handle via the
			// proper inline-script API instead of being printed as a
			// literal <script> tag in render_integrations_list(). 'before'
			// position guarantees the JSON literal is parsed by the JS
			// engine before integrations.js evaluates, so its Vue mount
			// always sees `window.appressConfig` populated. Both ESM
			// (`type="module"`) and classic script handles support
			// `before`-position inline scripts in core's output (see
			// `WP_Scripts::print_inline_script`).
			$payload = wp_json_encode( $this->build_localize() );
			wp_add_inline_script(
				'appress:integrations.js',
				'window.appressConfig=' . $payload . ';window.appConfig=window.appressConfig;',
				'before'
			);
		}
	}

	private function build_localize(): array {
		// `modules` ships embedded with the bootstrap config so the Vue
		// list page can hydrate the toggle state SYNCHRONOUSLY at mount
		// time. Previously the page deferred to an async
		// `integrations.get_modules` AJAX, which raced with user clicks: a
		// fast user could toggle a card BEFORE the fetch returned, and
		// the response would then overwrite `modules.value` and silently
		// wipe the in-flight change — making the first save look like it
		// did nothing while the second save (with the now-loaded state)
		// worked. Inlining kills the race because Vue has the truth
		// before the first frame paints.
		$modules = \Appress\get( 'modules', [] );
		if ( ! is_array( $modules ) ) {
			$modules = [];
		}
		// Shared Vue UI dict + page-specific overrides; page entries
		// win on collision (see App\Admin_Controller::build_localize).
		$shared_i18n = file_exists( APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php' )
			? require APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php'
			: [];
		$page_i18n = [
			'Appress Integrations' => __( 'Appress Integrations', 'appress' ),
		];

		return [
			'nonce'    => wp_create_nonce( 'appress_admin_action' ),
			'page'     => 'appress-integrations',
			'integrations' => $this->registered_integrations(),
			'modules'  => (object) $modules,
			'i18n'     => array_merge( $shared_i18n, $page_i18n ),
		];
	}

	public function render_integrations_page() {
		$integration_id = isset( $_GET['integration'] ) ? sanitize_text_field( wp_unslash( $_GET['integration'] ) ) : '';

		if ( $integration_id === '' ) {
			$this->render_integrations_list();
			return;
		}

		$integrations = $this->registered_integrations();

		// Unknown / disabled / filtered-out integration → show the list
		// page with a notice. Refusing to render a random hook keeps
		// third-party plugins from punching out of the dispatcher.
		if ( ! isset( $integrations[ $integration_id ] ) ) {
			echo wp_kses_post(
				'<div class="wrap appress-wrap appress-admin-wrap"><div class="notice notice-error"><p>'
				. esc_html__( 'Integration not found.', 'appress' )
				. '</p></div></div>'
			);
			return;
		}

		$integration = $integrations[ $integration_id ];

		// `configurable=false` integrations are info-only on the list page
		// — they have no detail template registered and requesting the
		// URL directly would either 404 the hook or fall through to the
		// default renderer showing nothing useful. Treat it as a bad
		// request and bounce back to the list.
		if ( empty( $integration['configurable'] ) ) {
			echo wp_kses_post(
				'<div class="wrap appress-wrap appress-admin-wrap"><div class="notice notice-warning"><p>'
				. esc_html__( 'This integration has no admin page.', 'appress' )
				. '</p></div></div>'
			);
			return;
		}

		$this->render_integration_detail( $integration_id, $integration );
	}

	// ── List ─────────────────────────────────────────────────────────────

	protected function render_integrations_list() {
		// Bootstrap config is attached via `wp_add_inline_script` in the
		// `enqueue_admin_assets` step above — emitted as a `before`-position
		// inline script on the `appress:integrations.js` handle. Markup here
		// is just the Vue mount root.
		?>
		<div class="wrap appress-wrap appress-admin-wrap">
			<div id="appress-integrations-app"></div>
		</div>
		<?php
	}

	// ── Detail ───────────────────────────────────────────────────────────

	protected function render_integration_detail( string $integration_id, array $integration ) {
		// The detail shell is ALWAYS wrapped in `#appress-integrations-app`
		// so the same Tailwind base reset + utility scope used by the
		// list view applies here. Without the wrapper, WP admin CSS
		// leaks into every element (h1 margins, anchor underlines,
		// button appearance) and Tailwind utilities applied by each
		// integration's own template don't get the reset they expect.
		?>
		<div class="wrap appress-wrap appress-admin-wrap">
			<div id="appress-integrations-app" class="appress-integration-detail mx-auto max-w-screen-2xl p-4 md:p-6 2xl:p-10 bg-gray-50 dark:bg-gray-900 min-h-screen" data-integration-id="<?php echo esc_attr( $integration_id ); ?>">
				<?php $this->render_detail_header( $integration_id, $integration ); ?>

				<?php
				// Hook IS prefixed ("appress/integrations/admin_template/...");
				// variable interpolation hides that from PCP's static analysis.
				$hook = 'appress/integrations/admin_template/' . $integration_id;
				if ( has_action( $hook ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
					do_action( $hook, $integration_id, $integration );
				} else {
					$this->render_default_integration_detail( $integration_id, $integration );
				}
				?>
			</div>
		</div>
		<?php
	}

	protected function render_detail_header( string $integration_id, array $integration ) {
		$back_url = esc_url( admin_url( 'admin.php?page=appress-integrations' ) );
		$name     = isset( $integration['name'] ) ? $integration['name'] : $integration_id;
		$desc     = isset( $integration['description'] ) ? $integration['description'] : '';
		?>
		<div class="flex items-start gap-4 mb-6">
			<a href="<?php echo esc_attr( $back_url ); ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 hover:border-brand-300 hover:text-brand-600 transition-colors whitespace-nowrap no-underline">
				<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
				<?php esc_html_e( 'Integrations', 'appress' ); ?>
			</a>
			<div class="min-w-0">
				<h1 class="text-xl font-bold text-gray-900 dark:text-white/90"><?php echo esc_html( $name ); ?></h1>
				<p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?php echo esc_html( $desc ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Fallback for integrations that haven't registered their own render
	 * hook. Provides a minimal but functional detail page:
	 *   • integrations-of-this-integration list (from schema)
	 *   • shared events panel if this integration publishes events
	 * Third-party plugins can extend or replace this by hooking
	 * `appress/integrations/admin_template/{id}` themselves.
	 */
	protected function render_default_integration_detail( string $integration_id, array $integration ) {
		$sub_integrations = isset( $integration['integrations'] ) && is_array( $integration['integrations'] ) ? $integration['integrations'] : [];
		?>
		<div class="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl p-6 mb-5">
			<h2 class="text-base font-bold text-gray-800 dark:text-white/90 mb-3"><?php esc_html_e( 'Overview', 'appress' ); ?></h2>
			<?php if ( ! empty( $sub_integrations ) ) : ?>
				<ul class="space-y-2">
				<?php foreach ( $sub_integrations as $sub_id => $sub_label ) : ?>
					<li class="flex items-center gap-2">
						<svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
						<span class="text-sm text-gray-700 dark:text-gray-300"><?php echo esc_html( $sub_label ); ?></span>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="text-sm text-gray-500 dark:text-gray-400"><?php esc_html_e( 'No integration metadata declared.', 'appress' ); ?></p>
			<?php endif; ?>
		</div>

		<?php
		if ( $this->integration_has_events( $integration_id ) ) {
			echo '<h2 class="text-base font-bold text-gray-800 dark:text-white/90 mt-6 mb-3">' . esc_html__( 'Events', 'appress' ) . '</h2>';
			\Appress\render_integration_events_panel( $integration_id );
		}
	}

	// ── Utilities ────────────────────────────────────────────────────────

	/**
	 * Registered integrations = user-toggleable modules PLUS any entry
	 * that publishes events but isn't a "module" (third-party platforms
	 * like WooCommerce / Voxel that ship an events schema for the in-app
	 * Notifications builder). The Appress core events schema is
	 * intentionally excluded here — it has its own top-level submenu
	 * ("App Events") and shouldn't show up as an integration card.
	 */
	protected function registered_integrations(): array {
		$modules = \Appress\config( 'integrations' );
		if ( ! is_array( $modules ) ) {
			$modules = [];
		}
		$modules = apply_filters( 'appress/integrations/registered', $modules );

		// Stamp `_togglable` so the list view knows which cards get a
		// user-facing on/off switch. Modules came from the registry +
		// filter → togglable. Entries we merge in from the events
		// schema below are platform-level and are always on.
		foreach ( $modules as $id => &$entry ) {
			$entry['_togglable'] = true;
		}
		unset( $entry );

		$events_schema = \Appress\Event::get_all();
		foreach ( $events_schema as $id => $entry ) {
			if ( $id === 'appress' ) {
				continue; // Lives under the "App Events" submenu, not the integrations list.
			}
			if ( ! isset( $modules[ $id ] ) ) {
				$entry['_togglable'] = false;
				$modules[ $id ]       = $entry;
			}
		}
		return $modules;
	}

	protected function integration_has_events( string $integration_id ): bool {
		$schema = \Appress\Event::get_all();
		return ! empty( $schema[ $integration_id ]['events'] );
	}
}
