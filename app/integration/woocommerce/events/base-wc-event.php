<?php
namespace Appress\Integration\Woocommerce\Events;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for every WooCommerce → Appress event.
 *
 * Design mirrors Voxel's Base_Event: each concrete event declares
 * (1) which WooCommerce hook feeds it, (2) which recipients it
 * fans out to (customer/admin/…), and (3) default title/body
 * templates per destination.
 *
 * Extensibility — the destinations() list is filtered under a
 * per-event hook so vendor-marketplace integrations (WCFM, Dokan)
 * can inject a `vendor` destination without touching core code:
 *
 *     add_filter(
 *         'appress/wc/events/woocommerce/order/paid/destinations',
 *         function( $dests, $event ) {
 *             $dests['vendor'] = [
 *                 'label'            => 'Notify vendor',
 *                 'default_enabled'  => true,
 *                 'recipient'        => fn( $e ) => WCFM::vendor_id( $e->order ),
 *                 'default_subject'  => 'New order on your store',
 *                 'default_message'  => 'Order #{{order_number}}',
 *             ];
 *             return $dests;
 *         }, 10, 2
 *     );
 */
abstract class Base_WC_Event {

	/** Populated by prepare() — available to destination recipient/template callables. */
	public $order;
	public $product;
	public $extra = [];

	abstract public function get_key(): string;
	abstract public function get_label(): string;
	abstract public function get_category(): string;
	abstract public function get_category_label(): string;

	/**
	 * Hydrate $this->order / $this->product / $this->extra from the raw
	 * hook arguments. Throw to skip dispatch (e.g. guest order).
	 */
	abstract public function prepare( ...$args ): void;

	/**
	 * Destination map: `[ dest_key => [ label, default_enabled, recipient, default_subject, default_message, default_url ] ]`.
	 * Subclasses return the built-ins; the filter below lets third-party
	 * plugins append a `vendor` destination (or similar).
	 */
	abstract protected function default_destinations(): array;

	public function get_description(): string {
		return '';
	}

	/**
	 * Template variables available in subject/body/url templates.
	 * Subclasses override to add order/product-specific tokens.
	 */
	public function dynamic_tags(): array {
		return [];
	}

	final public function destinations(): array {
		$dests = $this->default_destinations();
		/**
		 * Filter destinations for a SPECIFIC event — use this to add vendor recipients
		 * only where they make sense (e.g. order events, not low-stock alerts).
		 *
		 * @param array     $destinations
		 * @param Base_WC_Event $event
		 */
		$dests = apply_filters( "appress/wc/events/{$this->get_key()}/destinations", $dests, $this );
		/**
		 * Global fallback filter for every WC event — use when a plugin adds a recipient
		 * type universally (e.g. site manager role across all events).
		 */
		return (array) apply_filters( 'appress/wc/events/destinations', $dests, $this );
	}

	/**
	 * Main entry — called by Events_Controller when the WC hook fires.
	 * Safe to call with any positional args the concrete event's prepare() expects.
	 */
	final public function dispatch( ...$args ): void {
		try {
			$this->prepare( ...$args );
		} catch ( \Exception $e ) {
			return; // prepare() signals "skip this dispatch".
		}

		$settings  = (array) \Appress\get( 'events', [] );
		$event_cfg = $settings['woocommerce'][ $this->get_key() ] ?? [];
		$dest_cfgs = (array) ( $event_cfg['destinations'] ?? [] );

		foreach ( $this->destinations() as $dest_key => $dest ) {
			$dest_cfg = (array) ( $dest_cfgs[ $dest_key ] ?? [] );

			// Honor admin toggle; default-on destinations still require explicit opt-in via target_apps.
			if ( ! ( $dest_cfg['enabled'] ?? false ) ) {
				continue;
			}
			$target_apps = array_filter( array_map( 'intval', (array) ( $dest_cfg['target_apps'] ?? [] ) ) );
			if ( empty( $target_apps ) ) {
				continue;
			}

			$recipient_ids = $this->resolve_recipient( $dest );
			if ( empty( $recipient_ids ) ) {
				continue;
			}

			// Admin UI saves empty string for untouched fields — '?? ' only catches
			// null, so an empty saved value would short-circuit past the defaults
			// and silently send a blank push. Fall back whenever the saved value
			// is falsy (empty string or missing).
			$subject = (string) ( ! empty( $dest_cfg['subject'] ) ? $dest_cfg['subject'] : ( $dest['default_subject'] ?? '' ) );
			$message = (string) ( ! empty( $dest_cfg['body'] )    ? $dest_cfg['body']    : ( $dest['default_message'] ?? '' ) );
			$url     = (string) ( ! empty( $dest_cfg['url'] )     ? $dest_cfg['url']     : ( $dest['default_url'] ?? '' ) );
			$image   = (string) ( $dest_cfg['image'] ?? '' );

			$tags = $this->dynamic_tags();
			$subject = $this->render( $subject, $tags );
			$message = $this->render( $message, $tags );
			$url     = $this->render( $url, $tags );

			foreach ( $recipient_ids as $user_id ) {
				$appress_event = new \Appress\Event( $this->get_key(), (int) $user_id, [
					'_destination'    => $dest_key,
					'_override_title' => $subject,
					'_override_body'  => $message,
					'_override_url'   => $url,
					'_override_image' => $image,
				] );
				$appress_event->send();
			}
		}
	}

	private function resolve_recipient( array $dest ): array {
		if ( ! is_callable( $dest['recipient'] ?? null ) ) {
			return [];
		}
		try {
			$ids = $dest['recipient']( $this );
		} catch ( \Exception $e ) {
			return [];
		}
		return array_filter( array_map( 'intval', (array) $ids ) );
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

	/** Serialize event + destinations for the admin Vue schema. */
	public function get_schema(): array {
		$destinations = [];
		foreach ( $this->destinations() as $dest_key => $dest ) {
			$destinations[ $dest_key ] = [
				'label'            => $dest['label'] ?? $dest_key,
				'default_enabled'  => (bool) ( $dest['default_enabled'] ?? false ),
				'default_subject'  => $dest['default_subject'] ?? '',
				'default_message'  => $dest['default_message'] ?? '',
				'default_url'      => $dest['default_url'] ?? '',
			];
		}
		return [
			'label'          => $this->get_label(),
			'description'    => $this->get_description(),
			'category'       => $this->get_category(),
			'category_label' => $this->get_category_label(),
			'has_content'    => false,     // templates live inside each destination, not at event root.
			'destinations'   => $destinations,
			'variables'      => array_keys( $this->token_hints() ),
		];
	}

	/**
	 * Admin-facing list of template variables for the UI hint panel.
	 * Distinct from dynamic_tags() because we may want to advertise
	 * variables even before any order exists to compute them.
	 */
	public function token_hints(): array {
		return [];
	}

	/** Helper for admin destinations — list every admin-role user id. */
	protected function get_admin_recipient_ids(): array {
		$ids = get_users( [
			'role__in' => [ 'administrator', 'shop_manager' ],
			'fields'   => 'ID',
		] );
		return array_filter( array_map( 'intval', (array) $ids ) );
	}
}
