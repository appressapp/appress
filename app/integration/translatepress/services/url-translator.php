<?php

namespace Appress\Integration\Translatepress\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around TranslatePress' internal API. Native consumers must NOT
 * see TRP-specific globals or class names — they get a plain array of pre-
 * resolved URL maps via the `appress/app/live_config` filter.
 */
class Url_Translator {

	/** @var \TRP_Translate_Press|null */
	private $trp_instance = null;

	/** @var \TRP_Url_Converter|null */
	private $url_converter = null;

	/** @var array TRP settings snapshot */
	private $settings = [];

	public function is_active(): bool {
		return class_exists( '\TRP_Translate_Press' );
	}

	/**
	 * Human-readable language names for the given codes. TRP's
	 * `TRP_Languages::get_language_names()` honors the admin's
	 * "native vs english" preference by default (passing null), so the
	 * names match what TRP itself renders in its language switcher.
	 *
	 * @param string[] $codes
	 * @return array<string, string> Map of code => "English (United States)"
	 */
	/**
	 * URL to the flag image TRP ships for a given language code. TRP's
	 * convention is `assets/images/flags/{wp_locale}.png` — passes both
	 * the `trp_flags_path` filter (so customers can swap to a custom
	 * folder) and `trp_flag_file_name` (per-language override) through
	 * unchanged. Empty string when TRP is off or its plugin constant
	 * `TRP_PLUGIN_URL` is missing.
	 */
	public function get_flag_url( string $code ): string {
		if ( ! $this->is_active() || ! defined( 'TRP_PLUGIN_URL' ) ) {
			return '';
		}
		$path     = apply_filters( 'trp_flags_path', TRP_PLUGIN_URL . 'assets/images/flags/', $code );
		$filename = apply_filters( 'trp_flag_file_name', $code . '.png', $code );
		return esc_url( $path . $filename );
	}

	public function get_language_names( array $codes ): array {
		if ( ! $this->is_active() || empty( $codes ) ) {
			return [];
		}
		$trp = \TRP_Translate_Press::get_trp_instance();
		$languages_component = $trp->get_component( 'languages' );
		if ( ! $languages_component ) {
			return [];
		}
		$names = $languages_component->get_language_names( $codes, null );
		return is_array( $names ) ? $names : [];
	}

	/**
	 * TRP settings normalized for native consumption. Keys mirror the native
	 * `i18n` schema documented in the integration's controller.
	 */
	public function get_settings(): array {
		if ( ! empty( $this->settings ) ) {
			return $this->settings;
		}
		if ( ! $this->is_active() ) {
			return [];
		}

		$raw = get_option( 'trp_settings', [] );

		$this->settings = [
			'default_language'      => $raw['default-language'] ?? '',
			'languages'             => array_values( $raw['publish-languages'] ?? [] ),
			'url_slugs'             => $raw['url-slugs'] ?? [],
			'add_subdir_to_default' => ( $raw['add-subdirectory-to-default-language'] ?? 'no' ) === 'yes',
		];

		return $this->settings;
	}

	/**
	 * Translate a single URL into the target language.
	 *
	 * TRP's `get_url_for_language` reads `global $TRP_LANGUAGE` to short-circuit
	 * when the source already matches the target — we bypass that by setting
	 * the global to the target before the call and restoring after, otherwise
	 * URLs translated outside a real frontend request return wrong results.
	 *
	 * @param string $url        Original (default-language) URL.
	 * @param string $target_lang TRP language code (e.g. `en_US`, `vi`).
	 * @return string Translated URL, or original on failure / inactive TRP.
	 */
	public function translate_url( string $url, string $target_lang ): string {
		if ( ! $this->is_active() || empty( $url ) || empty( $target_lang ) ) {
			return $url;
		}

		$converter = $this->get_url_converter();
		if ( ! $converter ) {
			return $url;
		}

		global $TRP_LANGUAGE;
		$backup        = $TRP_LANGUAGE;
		$TRP_LANGUAGE  = $target_lang;
		$translated    = $converter->get_url_for_language( $target_lang, $url, '' );
		$TRP_LANGUAGE  = $backup;

		return is_string( $translated ) && ! empty( $translated ) ? $translated : $url;
	}

	/**
	 * Bulk translate: returns `[ original_url => [ lang_code => translated_url ] ]`.
	 *
	 * Skips URLs whose host is not the site host (external links must not be
	 * rewritten by TRP). Caller deduplicates the input list.
	 *
	 * @param string[] $urls
	 * @return array<string, array<string, string>>
	 */
	public function bulk_translate( array $urls ): array {
		if ( ! $this->is_active() || empty( $urls ) ) {
			return [];
		}

		$settings = $this->get_settings();
		$languages = $settings['languages'] ?? [];
		if ( empty( $languages ) ) {
			return [];
		}

		$site_host = $this->get_site_host();
		$map       = [];

		foreach ( $urls as $url ) {
			if ( empty( $url ) || ! is_string( $url ) ) {
				continue;
			}
			if ( ! $this->is_internal_url( $url, $site_host ) ) {
				continue;
			}
			$map[ $url ] = [];
			foreach ( $languages as $lang ) {
				$map[ $url ][ $lang ] = $this->translate_url( $url, $lang );
			}
		}

		return $map;
	}

	private function get_url_converter() {
		if ( $this->url_converter !== null ) {
			return $this->url_converter;
		}
		if ( ! $this->is_active() ) {
			return null;
		}

		$this->trp_instance = \TRP_Translate_Press::get_trp_instance();
		$component          = $this->trp_instance->get_component( 'url_converter' );
		$this->url_converter = $component ?: null;

		return $this->url_converter;
	}

	private function get_site_host(): string {
		$parsed = wp_parse_url( home_url() );
		return $parsed['host'] ?? '';
	}

	private function is_internal_url( string $url, string $site_host ): bool {
		if ( empty( $site_host ) ) {
			return false;
		}
		$parsed = wp_parse_url( $url );
		// Relative URL (no host) — treat as internal.
		if ( empty( $parsed['host'] ) ) {
			return true;
		}
		return strcasecmp( $parsed['host'], $site_host ) === 0;
	}
}
