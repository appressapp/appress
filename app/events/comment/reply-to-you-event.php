<?php
namespace Appress\Events\Comment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reply_To_You_Event extends \Appress\Events\Base_Event {

	public function get_key(): string          { return 'comment/reply_to_you'; }
	public function get_label(): string        { return 'Reply to your comment'; }
	public function get_category(): string     { return 'social'; }
	public function get_category_label(): string { return 'Social'; }
	public function get_description(): string {
		return 'Push the original commenter when someone replies to their comment — WooCommerce product reviews, BuddyPress, forums, any plugin that fires core `comment_post`.';
	}

	protected function user_default_subject(): string {
		return '{{reply_author}} replied to you';
	}

	protected function user_default_message(): string {
		return '"{{reply_excerpt}}" — on {{post_title}}';
	}

	public function token_hints(): array {
		// `display_name` etc still resolve via parent but describe the PARENT
		// commenter (the push recipient). reply_* fields describe the NEW
		// comment that triggered the notification.
		return parent::token_hints() + [
			'reply_author'  => 'Reply author name',
			'reply_excerpt' => 'Reply content (trimmed)',
			'reply_url'     => 'Deep link to the reply',
			'post_title'    => 'Post / product title',
		];
	}
}
