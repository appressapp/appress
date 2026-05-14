<?php

namespace Appress\Controllers\Screens;

if (! defined('ABSPATH')) {
	exit;
}

class Post_Type_Controller extends \Appress\Controllers\Base_Controller
{
	protected function hooks()
	{
		$this->on('init', '@register_post_type');
	}

	public function register_post_type()
	{
		$labels = [
			'name'               => __( 'Appress Screens', 'appress' ),
			'singular_name'      => __( 'Appress Screen', 'appress' ),
			// Sits inside the "Appress" parent menu — sub-menu row added
			// manually by `App\Admin_Controller::register_menus` so we
			// can control label + position. Drop the redundant "Appress"
			// prefix; the row simply reads "Screens".
			'menu_name'          => __( 'Screens', 'appress' ),
			'name_admin_bar'     => __( 'Appress Screen', 'appress' ),
			'add_new'            => __( 'Add New', 'appress' ),
			'add_new_item'       => __( 'Add New Screen', 'appress' ),
			'new_item'           => __( 'New Screen', 'appress' ),
			'edit_item'          => __( 'Edit Screen', 'appress' ),
			'view_item'          => __( 'View Screen', 'appress' ),
			'all_items'          => __( 'Screens', 'appress' ),
			'search_items'       => __( 'Search Screens', 'appress' ),
			'parent_item_colon'  => __( 'Parent Screens:', 'appress' ),
			'not_found'          => __( 'No screens found.', 'appress' ),
			'not_found_in_trash' => __( 'No screens found in Trash.', 'appress' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			// `false` = no auto-submenu via WP core's `_add_post_type_submenus`.
			// We register the row manually in `App\Admin_Controller::register_menus`
			// with an explicit position so it lands at the BOTTOM of the
			// "Appress" sub-menu (after Integrations, Broadcast, integrations…).
			// Auto-mode appends in registration order, which placed Screens
			// somewhere in the middle and ignored the user's preferred order.
			'show_in_menu'       => false,
			'query_var'          => true,
			'rewrite'            => ['slug' => 'appress_screen'],
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'supports'           => ['title', 'editor', 'thumbnail', 'elementor'], // Gutenberg + Elementor support.
			'show_in_rest'       => true, // Enable Gutenberg editor.
			'exclude_from_search' => true,
		];

		register_post_type('appress_screen', $args);
	}
}
