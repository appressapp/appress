<?php

namespace Appress\Controllers\Screens;

if (! defined('ABSPATH')) {
	exit;
}

class Template_Controller extends \Appress\Controllers\Base_Controller
{
	protected function hooks()
	{
		$this->on('template_redirect', '@check_access');
	}

	public function check_access()
	{
		if (! is_singular('appress_screen')) {
			return;
		}

		// Editors/admins may view the screen for Edit/Preview (e.g. inside an Elementor iframe).
		if (current_user_can('edit_posts')) {
			return;
		} 

		// Native app (User-Agent contains "Appress") is always allowed through.
		if (\Appress\is_app()) {
			return;
		}

		// All other visitors (regular web users, crawlers, referrers...) are
		// kicked out. Send a noindex header before dropping the connection so
		// Google drops the appress_screen URL from its index if it ever saw one.
		if (! headers_sent()) {
			header('X-Robots-Tag: noindex, nofollow, noarchive', true);
			status_header(410); // Gone — stronger hint than 404 for search engines to de-index.
		}
		exit;
	}
}
