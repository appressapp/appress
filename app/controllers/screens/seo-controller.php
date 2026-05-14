<?php

namespace Appress\Controllers\Screens;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Keep `appress_screen` posts out of Google.
 *
 * Layers (belt-and-suspenders because SEO plugins override each other):
 *  1. wp_sitemaps_post_types       — WP core sitemap (wp-sitemap.xml)
 *  2. wpseo_sitemap_exclude_post_type  — Yoast SEO
 *  3. rank_math/sitemap/exclude_post_type — RankMath
 *  4. aioseo_sitemap_exclude_post_types  — All in One SEO
 *  5. wp_robots                    — Noindex in the Robots-Tag HTTP header
 *  6. wp_head @ priority 1         — <meta name="robots" content="noindex,nofollow"> fallback
 */
class Seo_Controller extends \Appress\Controllers\Base_Controller
{
	protected function hooks()
	{
		// WP core sitemap (5.5+)
		$this->on('wp_sitemaps_post_types', '@exclude_from_core_sitemap');

		// SEO plugin sitemaps
		$this->on('wpseo_sitemap_exclude_post_type', '@exclude_yoast', 10, 2);
		$this->on('rank_math/sitemap/exclude_post_type', '@exclude_rankmath', 10, 2);
		$this->on('aioseo_sitemap_exclude_post_types', '@exclude_aioseo');

		// Robots directives (HTTP header + HTML meta)
		$this->on('wp_robots', '@noindex_robots');
		$this->on('wp_head', '@meta_noindex', 1);
	}

	public function exclude_from_core_sitemap($post_types)
	{
		unset($post_types['appress_screen']);
		return $post_types;
	}

	public function exclude_yoast($excluded, $post_type)
	{
		return $post_type === 'appress_screen' ? true : $excluded;
	}

	public function exclude_rankmath($excluded, $post_type)
	{
		return $post_type === 'appress_screen' ? true : $excluded;
	}

	public function exclude_aioseo($post_types)
	{
		if (! is_array($post_types)) $post_types = [];
		$post_types[] = 'appress_screen';
		return $post_types;
	}

	public function noindex_robots($robots)
	{
		if (is_singular('appress_screen') || is_post_type_archive('appress_screen')) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			$robots['noarchive'] = true;
		}
		return $robots;
	}

	public function meta_noindex()
	{
		if (is_singular('appress_screen') || is_post_type_archive('appress_screen')) {
			echo wp_kses(
				'<meta name="robots" content="noindex,nofollow,noarchive">' . "\n",
				array( 'meta' => array( 'name' => array(), 'content' => array() ) )
			);
		}
	}
}
