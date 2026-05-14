<?php

namespace Appress\Controllers\Updater;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide "Appress update available" banner on every admin page.
 *
 * WordPress's stock update flow already announces our updates in two
 * places: a counter on the `Plugins` menu and a per-row notice on the
 * Plugins admin page. Both are easy to miss — admins land on Apps /
 * Settings / Integrations far more often than the Plugins list. To
 * match the visibility pattern users expect from Yoast / RankMath /
 * Wordfence we surface the same data as a top-of-page dismissible
 * notice on EVERY admin screen, with a one-click link straight to
 * the Updates tab on our Settings page.
 *
 * The check is server-side cheap: we read the existing
 * `update_plugins` site transient that
 * `Github_Updater_Controller`'s underlying `plugin-update-checker`
 * library already populates on its periodic WP-Cron tick. No extra
 * HTTP request from this controller — we're a pure consumer of the
 * cached transient. Hiding the notice on the Updates tab itself
 * avoids the awkward "you are on the updates page; here's a notice
 * pointing to the updates page" loop.
 */
class Admin_Notice_Controller extends Base_Controller {

	/**
	 * Only render in admin context — `admin_notices` doesn't fire on
	 * the frontend anyway, but the controller is loaded everywhere
	 * via the global controllers config, so we gate to keep the
	 * hook list tight on frontend requests too.
	 */
	protected function authorize() {
		return is_admin();
	}

	protected function hooks() {
		$this->on( 'admin_notices', '@maybe_render' );
	}

	protected function maybe_render() {
		// Admins who can't update plugins anyway don't need the nag.
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// Skip the notice on the Updates tab itself — the user is
		// already looking at the dedicated UI for this, surfacing
		// the same info as a banner above it is redundant noise.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] )  ? sanitize_text_field( wp_unslash( $_GET['tab'] ) )  : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $page === 'appress-settings' && $tab === 'updates' ) {
			return;
		}

		// `update_plugins` is the canonical WP transient that holds
		// "what's available to update right now". Plugin-update-checker
		// writes to this on its periodic cron tick. If there's no
		// transient yet (fresh install, just-deactivated WP-Cron),
		// silently skip — the user can still trigger a manual check
		// from the Updates tab.
		$transient = get_site_transient( 'update_plugins' );
		if ( ! $transient || empty( $transient->response ) ) {
			return;
		}

		$slug = plugin_basename( APPRESS_PLUGIN_FILE );
		if ( ! isset( $transient->response[ $slug ] ) ) {
			return;
		}

		$info        = $transient->response[ $slug ];
		$new_version = isset( $info->new_version ) ? (string) $info->new_version : '';
		if ( $new_version === '' ) {
			return;
		}

		// One-click WP-native update URL. Same target the
		// `Update now` link on the Plugins admin page row uses —
		// hits `update.php?action=upgrade-plugin` which fires
		// Plugin_Upgrader server-side, runs the same machinery as
		// our Updates tab without the round-trip through it. Nonce
		// is bound to the plugin basename so a leaked notice on a
		// different page can't be replayed against another plugin.
		$upgrade_url = wp_nonce_url(
			self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . urlencode( $slug ) ),
			'upgrade-plugin_' . $slug
		);
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong>Appress</strong>
				<?php
				printf(
					/* translators: %s: new version number, e.g. 1.0.0.8 */
					esc_html__( 'version %s is available.', 'appress' ),
					esc_html( $new_version )
				);
				?>
				&nbsp;<a href="<?php echo esc_url( $upgrade_url ); ?>" class="button button-primary button-small">
					<?php esc_html_e( 'Update now', 'appress' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
