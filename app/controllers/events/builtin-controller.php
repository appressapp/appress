<?php
namespace Appress\Controllers\Events;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
use Appress\Controllers\Base_Controller;
use Appress\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built-in event dispatcher — binds WP core hooks to the Appress event
 * classes under `app/events/` and registers their schema under the `appress`
 * integration id so they show up in the admin Events view.
 *
 * Follows the same shape as the WooCommerce / Voxel events-controllers but
 * lives in core (not under `app/integration/`) because the events it owns
 * are default Appress integrations — they work on any WP install, no external
 * plugin required.
 *
 * Hooks bound:
 *   wp_login               → auth/login_new_device  (UA+IP fingerprint guard)
 *   after_password_reset   → auth/password_changed  (lost-password flow)
 *   profile_update         → auth/password_changed  (self-edit / admin-edit)
 *                          + account/email_changed  (case-insensitive diff)
 *   set_user_role          → account/role_changed   (skips registration default)
 *   comment_post           → comment/reply_to_you   (approved + has parent user)
 */
class Builtin_Controller extends Base_Controller {

	/** @var Events\Base_Event[] — keyed by event key for O(1) dispatch. */
	private $events = [];

	protected function hooks() {
		$this->filter( 'appress/events', '@register_schema' );

		if ( is_admin() ) {
			// Priority 20 lands the submenu after Integrations (1) but
			// before late-registered submenus like Broadcast + Settings.
			// Built-in events deserve top-billing UX — they're the
			// out-of-the-box push notifications every site gets without
			// installing any third-party plugin.
			$this->on( 'admin_menu', '@register_menu', 20 );
			$this->on( 'admin_enqueue_scripts', '@enqueue_assets' );
		}

		// Priority 20 — let WP core finish updating user data before we read it.
		$this->on( 'wp_login',             '@on_login',          20, 2 );
		$this->on( 'after_password_reset', '@on_password_reset', 20, 1 );
		$this->on( 'profile_update',       '@on_profile_update', 20, 2 );
		$this->on( 'set_user_role',        '@on_set_user_role',  20, 3 );
		$this->on( 'comment_post',         '@on_comment_post',   20, 2 );
	}

	protected function register_menu() {
		add_submenu_page(
			'appress',
			__( 'App Events', 'appress' ),
			__( 'App Events', 'appress' ),
			'manage_options',
			'appress-app-events',
			[ $this, 'render_page' ],
			2
		);
	}

	protected function enqueue_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== 'appress-app-events' ) {
			return;
		}
		// Tailwind admin reset + utilities scope. The events panel bundle
		// (`appress:integration-events-panel.js`) is enqueued lazily by
		// `render_integration_events_panel()` itself, so this method only
		// needs to ensure the base stylesheet is on the page.
		wp_enqueue_style( 'appress:admin.css' );
	}

	public function render_page() {
		// `#appress-app-events-app` is the Tailwind scope marker — listed
		// in `dev/src/assets/main.css` alongside the other Vue admin
		// surfaces so the same base reset + utility classes apply.
		?>
		<div class="wrap appress-wrap appress-admin-wrap">
			<div id="appress-app-events-app" class="mx-auto max-w-screen-2xl p-4 md:p-6 2xl:p-10 bg-gray-50 dark:bg-gray-900 min-h-screen">
				<div class="mb-6">
					<h1 class="text-xl font-bold text-gray-900 dark:text-white/90"><?php esc_html_e( 'App Events', 'appress' ); ?></h1>
					<p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?php esc_html_e( 'Built-in push notifications for account security and social activity. Works on any WordPress install — no third-party plugin required.', 'appress' ); ?></p>
				</div>
				<?php \Appress\render_integration_events_panel( 'appress' ); ?>
			</div>
		</div>
		<?php
	}

	private function events(): array {
		if ( ! empty( $this->events ) ) {
			return $this->events;
		}
		$events = [
			new Events\Auth\Login_New_Device_Event(),
			new Events\Auth\Password_Changed_Event(),
			new Events\Account\Email_Changed_Event(),
			new Events\Account\Role_Changed_Event(),
			new Events\Comment\Reply_To_You_Event(),
		];
		$events = (array) apply_filters( 'appress/events/register', $events );
		foreach ( $events as $e ) {
			if ( $e instanceof Events\Base_Event ) {
				$this->events[ $e->get_key() ] = $e;
			}
		}
		return $this->events;
	}

	protected function register_schema( $integrations ) {
		$schema = [];
		foreach ( $this->events() as $key => $event ) {
			$schema[ $key ] = $event->get_schema();
		}
		$integrations['appress'] = [
			'name'         => 'Appress',
			'icon'            => APPRESS_PLUGIN_URL . 'assets/images/logo.svg',


			'type'         => 'global',
			'description'  => 'Built-in push notifications for account security + social activity',
			'configurable' => true, // has a detail page (events panel)
			'events'       => $schema,
		];
		return $integrations;
	}

	private function dispatch( string $key, int $user_id, array $extra = [] ): void {
		$events = $this->events();
		if ( isset( $events[ $key ] ) ) {
			$events[ $key ]->dispatch( $user_id, $extra );
		}
	}

	// ── wp_login ───────────────────────────────────────────────────────────

	public function on_login( $user_login, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$user_id = (int) $user->ID;
		if ( $user_id <= 0 ) {
			return;
		}

		$ua_raw = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
		$ip_raw = $this->client_ip();
		// Fingerprint on UA+IP: same browser + same network = same device.
		// Switching network (mobile → wifi) flags as new device — acceptable
		// false positive; users prefer an extra alert over missing a real takeover.
		$fp = md5( $ua_raw . '|' . $ip_raw );

		$seen = (array) get_user_meta( $user_id, 'appress_known_devices', true );
		if ( in_array( $fp, $seen, true ) ) {
			return;
		}
		$seen[] = $fp;
		// Bound the meta — last 10 covers "laptop + phone + work" without the
		// meta row growing unbounded over years of sessions.
		if ( count( $seen ) > 10 ) {
			$seen = array_slice( $seen, -10 );
		}
		update_user_meta( $user_id, 'appress_known_devices', $seen );

		// Registration-flow suppression: the FIRST login right after signup is
		// the registration handshake — don't scare the user on first touch.
		// 5 min covers OTP / email-verify detours comfortably.
		$user_registered_ts = (int) ( $user->user_registered ? strtotime( $user->user_registered . ' UTC' ) : 0 );
		if ( $user_registered_ts > 0 && ( time() - $user_registered_ts ) < 300 ) {
			return;
		}

		$this->dispatch( 'auth/login_new_device', $user_id, [
			'device_ua' => $this->short_ua( $ua_raw ),
			'device_ip' => $this->mask_ip( $ip_raw ),
		] );
	}

	// ── after_password_reset + profile_update ──────────────────────────────

	public function on_password_reset( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$this->dispatch( 'auth/password_changed', (int) $user->ID );
	}

	public function on_profile_update( $user_id, $old_user ) {
		$user_id = (int) $user_id;
		$new = get_userdata( $user_id );
		if ( ! $new || ! $old_user instanceof \WP_User ) {
			return;
		}

		// Password: wp_update_user hashes the new password before calling
		// profile_update, so comparing user_pass hashes is a reliable check.
		if ( isset( $new->user_pass, $old_user->user_pass ) && $new->user_pass !== $old_user->user_pass ) {
			$this->dispatch( 'auth/password_changed', $user_id );
		}

		$old_email = (string) $old_user->user_email;
		$new_email = (string) $new->user_email;
		if ( strcasecmp( $old_email, $new_email ) !== 0 ) {
			$this->dispatch( 'account/email_changed', $user_id, [
				'old_email' => $this->mask_email( $old_email ),
				'new_email' => $this->mask_email( $new_email ),
			] );
		}
	}

	// ── set_user_role ──────────────────────────────────────────────────────

	public function on_set_user_role( $user_id, $new_role, $old_roles ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		// First registration: old_roles empty → this is the signup completing,
		// not a role change worth notifying about.
		$old_roles = (array) $old_roles;
		if ( empty( $old_roles ) ) {
			return;
		}

		// Default-role no-op (plugin re-assigning the default a second time).
		$default = (string) get_option( 'default_role', 'subscriber' );
		if ( $new_role === $default && count( $old_roles ) === 1 && in_array( $default, $old_roles, true ) ) {
			return;
		}
		// Same single role as before — not a change.
		if ( count( $old_roles ) === 1 && in_array( $new_role, $old_roles, true ) ) {
			return;
		}

		$all_roles = wp_roles()->roles;
		$old_slug  = (string) reset( $old_roles );
		$new_label = isset( $all_roles[ $new_role ]['name'] ) ? translate_user_role( $all_roles[ $new_role ]['name'] ) : $new_role;
		$old_label = isset( $all_roles[ $old_slug ]['name'] ) ? translate_user_role( $all_roles[ $old_slug ]['name'] ) : $old_slug;

		$this->dispatch( 'account/role_changed', $user_id, [
			'new_role' => $new_label,
			'old_role' => $old_label,
		] );
	}

	// ── comment_post ───────────────────────────────────────────────────────

	public function on_comment_post( $comment_id, $comment_approved ) {
		if ( $comment_approved !== 1 ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		if ( (int) $comment->comment_parent <= 0 ) {
			return;
		}
		$parent = get_comment( $comment->comment_parent );
		if ( ! $parent instanceof \WP_Comment ) {
			return;
		}
		$parent_user_id = (int) $parent->user_id;
		if ( $parent_user_id <= 0 ) {
			return; // Guest parent — no account to push to.
		}
		if ( $parent_user_id === (int) $comment->user_id ) {
			return; // Self-reply — no push.
		}

		$post       = get_post( (int) $comment->comment_post_ID );
		$post_title = $post ? (string) $post->post_title : '';
		$reply_url  = (string) get_comment_link( $comment );
		$excerpt    = wp_trim_words( wp_strip_all_tags( (string) $comment->comment_content ), 20 );

		$this->dispatch( 'comment/reply_to_you', $parent_user_id, [
			'reply_author'  => (string) $comment->comment_author,
			'reply_excerpt' => $excerpt,
			'reply_url'     => $reply_url,
			'post_title'    => $post_title,
		] );
	}

	// ── helpers ────────────────────────────────────────────────────────────

	private function client_ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $h ) {
			if ( empty( $_SERVER[ $h ] ) ) {
				continue;
			}
			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
			if ( strpos( $ip, ',' ) !== false ) {
				$ip = trim( strtok( $ip, ',' ) );
			}
			return $ip;
		}
		return '';
	}

	private function short_ua( string $ua ): string {
		$platform = '';
		$map = [ 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Linux' => 'Linux' ];
		foreach ( $map as $needle => $label ) {
			if ( stripos( $ua, $needle ) !== false ) {
				$platform = $label;
				break;
			}
		}

		// Appress app (iOS/Android WebView). Native layer stamps "Appress"
		// into UA — treat as a device, not a browser, so the user sees
		// "Mobile app on iPhone" instead of the misleading "Safari on iPhone".
		if ( stripos( $ua, 'Appress' ) !== false ) {
			return $platform ? "Mobile app on {$platform}" : 'Mobile app';
		}

		$browser = '';
		// Chrome UA contains "Safari" for legacy reasons — check Edge/Opera/Chrome
		// before falling through to Safari.
		foreach ( [ 'Edg', 'OPR', 'Firefox', 'Chrome', 'Safari' ] as $b ) {
			if ( strpos( $ua, $b . '/' ) !== false ) {
				$browser = str_replace( [ 'Edg', 'OPR' ], [ 'Edge', 'Opera' ], $b );
				break;
			}
		}
		if ( $browser && $platform ) return "{$browser} on {$platform}";
		if ( $platform ) return $platform;
		if ( $browser ) return $browser;
		return 'unknown device';
	}

	private function mask_ip( string $ip ): string {
		if ( $ip === '' ) {
			return '';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.*';
		}
		// IPv6 — keep first four segments, rest privacy-masked.
		$parts = explode( ':', $ip );
		return implode( ':', array_slice( $parts, 0, 4 ) ) . ':*';
	}

	private function mask_email( string $email ): string {
		if ( $email === '' || strpos( $email, '@' ) === false ) {
			return $email;
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		$keep   = max( 1, min( 3, (int) floor( strlen( $local ) / 2 ) ) );
		$masked = substr( $local, 0, $keep ) . str_repeat( '*', max( 1, strlen( $local ) - $keep ) );
		return $masked . '@' . $domain;
	}
}
