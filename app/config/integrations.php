<?php

/**
 * Registry of available integrations.
 *
 * Integrations are the user-facing cards on the Appress Integrations admin
 * page — each entry here renders as one card. 3rd-party plugins add
 * themselves to the list via the `appress/integrations/registered`
 * filter. The built-in empty array is intentional: every concrete
 * integration (WooCommerce, Voxel, Appress core events, …) registers
 * from its own controller's `hooks()`, so this file stays a stub.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [];
