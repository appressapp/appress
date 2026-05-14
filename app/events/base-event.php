<?php
namespace Appress\Events;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared base for built-in Appress events (auth / account / comment …).
 *
 * Built-in events are a first-class Appress integration — not an "integration"
 * bolted onto an external plugin — so the event classes live under
 * `app/events/` and the hook dispatcher under `app/controllers/events/`.
 * External integrations (WooCommerce, Voxel) keep their own
 * `app/integration/<id>/events/` tree; only events that ship with the core
 * plugin (and run on any WP stack) belong here.
 *
 * Each built-in event has a single recipient — the user the event happened to.
 * We still model it as a one-entry `destinations` map so the Events admin
 * Vue renders the same toggle + target_apps + subject/body/url/image card
 * used for WC events. Zero UI branching, consistent UX.
 *
 * Subclasses declare:
 *   - identity:  get_key / get_label / get_category
 *   - defaults:  user_default_subject / user_default_message
 *   - extras:    optional token_hints() override for {{variables}}
 *
 * The dispatcher (`Appress\Controllers\Events\Builtin_Controller`) binds the
 * WP hook and calls `dispatch($user_id, $extra)`.
 */
abstract class Base_Event {

	/** Integration key — matches what Builtin_Controller::register_schema returns. */
	const INTEGRATION_ID = 'appress';

	/** Hydrated by dispatch(); subclasses can read these in dynamic_tags(). */
	public $user_id = 0;
	public $extra   = [];

	abstract public function get_key(): string;
	abstract public function get_label(): string;
	abstract public function get_category(): string;
	abstract public function get_category_label(): string;
	abstract protected function user_default_subject(): string;
	abstract protected function user_default_message(): string;

	public function get_description(): string {
		return '';
	}

	/** What {{variables}} the admin UI advertises + renders inside templates. */
	public function token_hints(): array {
		return [
			'display_name' => 'User display name',
			'first_name'   => 'User first name',
			'user_email'   => 'User email',
		];
	}

	/** Merge built-in user tags with whatever the hook handler passed as $extra. */
	public function dynamic_tags(): array {
		$tags = [];
		if ( $this->user_id > 0 ) {
			$user = get_userdata( $this->user_id );
			if ( $user ) {
				$tags['display_name'] = (string) $user->display_name;
				$tags['first_name']   = (string) ( $user->first_name ?: $user->display_name );
				$tags['user_email']   = (string) $user->user_email;
			}
		}
		return array_merge( $tags, $this->extra );
	}

	public function default_destinations(): array {
		return [
			'user' => [
				'label'           => 'Notify user',
				'default_enabled' => true,
				'default_subject' => $this->user_default_subject(),
				'default_message' => $this->user_default_message(),
				'default_url'     => '',
			],
		];
	}

	final public function destinations(): array {
		$dests = $this->default_destinations();
		$dests = apply_filters( "appress/events/{$this->get_key()}/destinations", $dests, $this );
		return (array) apply_filters( 'appress/events/destinations', $dests, $this );
	}

	public function get_schema(): array {
		$destinations = [];
		foreach ( $this->destinations() as $dest_key => $dest ) {
			$destinations[ $dest_key ] = [
				'label'           => $dest['label'] ?? $dest_key,
				'default_enabled' => (bool) ( $dest['default_enabled'] ?? false ),
				'default_subject' => $dest['default_subject'] ?? '',
				'default_message' => $dest['default_message'] ?? '',
				'default_url'     => $dest['default_url'] ?? '',
			];
		}
		return [
			'label'          => $this->get_label(),
			'description'    => $this->get_description(),
			'category'       => $this->get_category(),
			'category_label' => $this->get_category_label(),
			'has_content'    => false,
			'destinations'   => $destinations,
			'variables'      => array_keys( $this->token_hints() ),
		];
	}

	/**
	 * Called by the hook handler. Loads admin config, renders templates, and
	 * hands each (app × user) pair to Notification::send_to so built-in events
	 * share the same persist / push / source_id pipeline as WC + Voxel + broadcast.
	 */
	final public function dispatch( int $user_id, array $extra = [] ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$this->user_id = $user_id;
		$this->extra   = $extra;

		$settings  = (array) \Appress\get( 'events', [] );
		$event_cfg = $settings[ self::INTEGRATION_ID ][ $this->get_key() ] ?? [];
		$dest_cfgs = (array) ( $event_cfg['destinations'] ?? [] );

		foreach ( $this->destinations() as $dest_key => $dest ) {
			$dest_cfg = (array) ( $dest_cfgs[ $dest_key ] ?? [] );
			if ( ! ( $dest_cfg['enabled'] ?? false ) ) {
				continue;
			}
			$target_apps = array_filter( array_map( 'intval', (array) ( $dest_cfg['target_apps'] ?? [] ) ) );
			if ( empty( $target_apps ) ) {
				continue;
			}

			// Falsy (empty-string) stored values fall back to destination defaults —
			// admin never needs to retype the bundled copy to turn on an event.
			$subject = (string) ( ! empty( $dest_cfg['subject'] ) ? $dest_cfg['subject'] : ( $dest['default_subject'] ?? '' ) );
			$message = (string) ( ! empty( $dest_cfg['body'] )    ? $dest_cfg['body']    : ( $dest['default_message'] ?? '' ) );
			$url     = (string) ( ! empty( $dest_cfg['url'] )     ? $dest_cfg['url']     : ( $dest['default_url'] ?? '' ) );
			$image   = (string) ( $dest_cfg['image'] ?? '' );

			$tags    = $this->dynamic_tags();
			$subject = $this->render( $subject, $tags );
			$message = $this->render( $message, $tags );
			$url     = $this->render( $url, $tags );

			foreach ( $target_apps as $app_id ) {
				\Appress\Notification::send_to(
					$user_id,
					(int) $app_id,
					$subject,
					$message,
					[ 'image' => $image, 'url' => $url ],
					[]
				);
			}
		}
	}

	private function render( string $template, array $tags ): string {
		if ( $template === '' ) {
			return '';
		}
		foreach ( $tags as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$template = str_replace( '{{' . $key . '}}', (string) $value, $template );
			}
		}
		return $template;
	}
}
