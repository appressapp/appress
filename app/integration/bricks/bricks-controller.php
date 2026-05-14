<?php

namespace Appress\Integration\Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bricks Builder integration: adds an "Appress" condition group + 3 conditions
 * to the per-element Conditions panel. Same semantic as the Elementor integration —
 * lets editors hide/show elements based on whether the visitor is browsing inside
 * the Appress mobile app, optionally filtered by app id.
 *
 * Auto-enabled (no admin toggle, no card in appress-integrations) — only loads
 * when Bricks is active.
 *
 * Reference: https://academy.bricksbuilder.io/article/element-conditions/#api
 */
class Bricks_Controller extends \Appress\Controllers\Base_Controller {

	const GROUP = 'appress';

	protected function hooks() {
		// Bricks ships as a THEME — BRICKS_VERSION is defined during after_setup_theme,
		// AFTER plugins_loaded fires. Wait until init (priority 1) so the constant is
		// guaranteed to exist before we register filters that target Bricks-only hooks.
		$this->on( 'init', '@maybe_boot', 1 );
	}

	protected function maybe_boot() {
		if ( defined( 'BRICKS_VERSION' ) ) {
			$this->boot();
		}
	}

	protected function boot() {
		$this->filter( 'bricks/conditions/groups',  '@register_group' );
		$this->filter( 'bricks/conditions/options', '@register_options' );
		$this->filter( 'bricks/conditions/result',  '@evaluate_condition', 10, 3 );

		// Bricks element registration runs on `init` (the element API is
		// available once the theme has loaded) — wait until init:20 so we're
		// comfortably after Bricks' own registration.
		$this->on( 'init', '@register_bricks_element', 20 );

		// Dedicated "Appress" element category so our elements cluster together
		// in the builder's "+" panel rather than sitting in General.
		$this->filter( 'bricks/builder/elements_categories', '@register_category' );

		// Load shared mask-icon CSS into the builder. Bricks builder runs as a
		// FRONTEND takeover (`/page/?bricks=run`) — not an admin page — so we
		// hook `wp_enqueue_scripts`, not `admin_enqueue_scripts`, and gate on
		// the `bricks=run` query flag to avoid loading on public pages.
		$this->on( 'wp_enqueue_scripts', '@enqueue_builder_assets' );
	}

	public function register_bricks_element() {
		if ( ! class_exists( '\Bricks\Elements' ) || ! method_exists( '\Bricks\Elements', 'register_element' ) ) {
			return;
		}
		// Bricks takes a FILE PATH (not a class) — it requires the file and
		// reads `$this->name` off the class defined inside. The element file
		// is standalone (no namespace) per Bricks convention.
		\Bricks\Elements::register_element( __DIR__ . '/elements/notifications-element.php' );
		\Bricks\Elements::register_element( __DIR__ . '/elements/biometric-element.php' );
		\Bricks\Elements::register_element( __DIR__ . '/elements/account-deletion-element.php' );
		\Bricks\Elements::register_element( __DIR__ . '/elements/apple-login-element.php' );
		\Bricks\Elements::register_element( __DIR__ . '/elements/qr-login-element.php' );
		\Bricks\Elements::register_element( __DIR__ . '/elements/qr-scanner-element.php' );
		\Bricks\Elements::register_element( __DIR__ . '/elements/back-button-element.php' );
		\Bricks\Elements::register_element( __DIR__ . '/elements/menu-toggle-element.php' );
		\Bricks\Elements::register_element( __DIR__ . '/elements/status-bar-height-element.php' );
		\Bricks\Elements::register_element( __DIR__ . '/elements/dismiss-first-launch-element.php' );

		// TranslatePress switcher — gated like the Elementor widget so
		// the Bricks panel doesn't surface a stub on sites without TRP.
		if ( class_exists( '\TRP_Translate_Press' ) ) {
			\Bricks\Elements::register_element( __DIR__ . '/elements/translatepress-switcher-element.php' );
		}
	}

	public function register_group( $groups ) {
		$groups[] = [
			'name'  => self::GROUP,
			'label' => esc_html__( 'Appress', 'appress' ),
		];
		return $groups;
	}

	public function register_options( $options ) {
		$compare = [
			'type'    => 'select',
			'options' => [
				'==' => esc_html__( 'is', 'appress' ),
				'!=' => esc_html__( 'is not', 'appress' ),
			],
		];

		$options[] = [
			'key'     => 'appress_in_app',
			'label'   => esc_html__( 'In Appress app (any platform)', 'appress' ),
			'group'   => self::GROUP,
			'compare' => $compare,
			// No value field — the condition is a pure boolean (in app or not).
		];

		$options[] = [
			'key'     => 'appress_in_android',
			'label'   => esc_html__( 'In Appress app (Android)', 'appress' ),
			'group'   => self::GROUP,
			'compare' => $compare,
		];

		$options[] = [
			'key'     => 'appress_in_ios',
			'label'   => esc_html__( 'In Appress app (iOS)', 'appress' ),
			'group'   => self::GROUP,
			'compare' => $compare,
		];

		$options[] = [
			'key'     => 'appress_app_id',
			'label'   => esc_html__( 'Appress app id matches', 'appress' ),
			'group'   => self::GROUP,
			'compare' => $compare,
			'value'   => [
				'type'        => 'text',
				'placeholder' => esc_html__( 'e.g. 3', 'appress' ),
			],
		];

		return $options;
	}

	public function register_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			$categories = [];
		}
		$categories[ self::GROUP ] = esc_html__( 'Appress', 'appress' );
		return $categories;
	}

	public function enqueue_builder_assets() {
		// Bricks builder URL is `/some-page/?bricks=run`. Superglobal read is
		// safe-read only — we just check presence, no user input is used.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['bricks'] ) || $_GET['bricks'] !== 'run' ) {
			return;
		}
		wp_enqueue_style( 'appress:builder-icon.css' );
	}

	public function evaluate_condition( $result, $condition_key, $condition ) {
		// Only handle our own keys; pass through everything else untouched.
		if ( ! in_array( $condition_key, [ 'appress_in_app', 'appress_in_android', 'appress_in_ios', 'appress_app_id' ], true ) ) {
			return $result;
		}

		$compare = isset( $condition['compare'] ) ? (string) $condition['compare'] : '==';

		switch ( $condition_key ) {
			case 'appress_in_app':
				$is = \Appress\is_app();
				break;
			case 'appress_in_android':
				$is = \Appress\is_android();
				break;
			case 'appress_in_ios':
				$is = \Appress\is_ios();
				break;
			case 'appress_app_id':
				$wanted = (int) ( $condition['value'] ?? 0 );
				$is = $wanted > 0 && \Appress\is_app( $wanted );
				break;
			default:
				return $result;
		}

		return $compare === '!=' ? ! $is : $is;
	}
}
