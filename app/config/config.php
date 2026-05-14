<?php

namespace Appress;

if ( ! defined('ABSPATH') ) {
	exit;
}

return [
	'controllers' => require_once APPRESS_PLUGIN_DIR . 'app/config/controllers.config.php',
	'assets'      => require_once APPRESS_PLUGIN_DIR . 'app/config/assets.config.php',
	'schema'      => require_once APPRESS_PLUGIN_DIR . 'app/config/schema.config.php',
	'integrations'    => require_once APPRESS_PLUGIN_DIR . 'app/config/integrations.php',
];
