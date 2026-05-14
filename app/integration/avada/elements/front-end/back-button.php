<?php
/**
 * Avada Builder Live Editor template — Appress back button.
 *
 * Mirrors Avada's Woo elements (e.g. `front-end/templates/fusion-woo-mini-cart.php`).
 * The view-element pipeline (`view-element.js::renderContent`) looks for
 * `#tmpl-{shortcode}-shortcode` and runs it through Underscore. The element's
 * AJAX callback (`get_fusion_appress_back_button`) populates `query_data.html`
 * with the server-rendered HTML, so dropping the element shows real output
 * without a save+reload.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<script type="text/html" id="tmpl-fusion_appress_back_button-shortcode">
	{{{ 'undefined' !== typeof query_data && 'undefined' !== typeof query_data.html ? query_data.html : '' }}}
</script>
