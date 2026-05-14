<?php

namespace Appress\Integration\Voxel\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Voxel-aware contributor to the `appress/app/subscreen_url_patterns`
 * filter. Auto-adds each Voxel post type's archive URL so the native
 * router force-pushes a subscreen when a search form JS-redirects there
 * (Voxel's `assets/dist/search-form.js` calls
 * `window.location.href = post_type.archive + "?" + params`).
 *
 * Covers `ts_on_submit = submit-to-archive` (the common default).
 * For `submit-to-page`, the admin enters the target page URL pattern
 * manually in the live config textarea — auto-discovery via Elementor
 * data scan was dropped as over-engineering for an uncommon case.
 *
 * Pretty-permalink only: archives that resolve to `/?post_type=…`
 * (non-pretty perma) are skipped because the URL path is `/` which
 * would over-match every page on the site. Admins on non-pretty sites
 * add patterns manually.
 */
class Subscreen_Patterns_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Filter signature is ($patterns, $app_id) — Voxel post-type
		// archives are site-wide, so we ignore the app_id and contribute
		// the same set regardless.
		$this->filter( 'appress/app/subscreen_url_patterns', '@contribute', 10, 2 );
	}

	public function contribute( $patterns, $app_id ) {
		if ( ! class_exists( '\Voxel\Post_Type' ) ) {
			return $patterns;
		}

		foreach ( \Voxel\Post_Type::get_voxel_types() as $post_type ) {
			$archive_link = $post_type->get_archive_link();
			if ( ! is_string( $archive_link ) || $archive_link === '' ) {
				continue;
			}
			$path = wp_parse_url( $archive_link, PHP_URL_PATH );
			if ( ! $path || $path === '/' ) {
				continue;
			}
			// Single suffix-wildcard pattern catches the bare archive,
			// trailing slash, sub-paths, and querystring variants — all
			// in one match. e.g. `/listings*` matches `/listings`,
			// `/listings/`, `/listings/?orderby=date`, `/listings/page/2/`.
			$patterns[] = rtrim( $path, '/' ) . '*';
		}

		return $patterns;
	}
}
