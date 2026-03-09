<?php
/**
 * Plugin Name: NXT Taxonomy Template Routing
 * Plugin URI:  https://nextab.de
 * Description: Automatic template assignment based on taxonomy terms for FSE block themes. Replaces Divi's Theme Builder "Assign to pages with taxonomy X" functionality.
 * Version:     1.0.0
 * Author:      nexTab
 * Author URI:  https://nextab.de
 * Text Domain: nxt-taxonomy-template-routing
 * Domain Path: /languages
 * Requires at least: 6.1
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
	exit;
}

define('NXT_TEMPLATE_ROUTING_VERSION', '1.0.0');
define('NXT_TEMPLATE_ROUTING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NXT_TEMPLATE_ROUTING_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once NXT_TEMPLATE_ROUTING_PLUGIN_DIR . 'inc/class-taxonomy-template-router.php';
require_once NXT_TEMPLATE_ROUTING_PLUGIN_DIR . 'inc/class-synced-pattern-translator.php';

add_action('plugins_loaded', function() {
	NXT_Taxonomy_Template_Router::get_instance();
	NXT_Synced_Pattern_Translator::get_instance();
});
