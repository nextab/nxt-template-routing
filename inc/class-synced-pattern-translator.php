<?php
/**
 * Synced Pattern Translation Fallback
 *
 * Polylang Pro translates synced patterns (wp_block post type) by swapping
 * the core/block block's "ref" attribute (= wp_block post ID) with the
 * translated version's ID before rendering. When Polylang's own mechanism
 * fails (e.g. due to a version bug), this class acts as a fallback by
 * hooking into render_block_data at a later priority to perform the same swap.
 *
 * Priority 20 ensures we run AFTER Polylang's own filters (typically priority 10).
 * If Polylang already translated the ref correctly, pll_get_post() returns the
 * same ID and we become a no-op. If Polylang failed, we step in.
 *
 * @package NXT_Taxonomy_Template_Routing
 */

if (!defined('ABSPATH')) {
	exit;
}

class NXT_Synced_Pattern_Translator {

	const OPTION_NAME = 'nxt_synced_pattern_translation_enabled';

	private static $instance = null;

	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function is_enabled() {
		return (bool) get_option(self::OPTION_NAME, true);
	}

	private function __construct() {
		if (self::is_enabled()) {
			add_filter('render_block_data', [$this, 'translate_synced_pattern_ref'], 20, 3);
		}
	}

	/**
	 * Swap the ref ID of a core/block (synced pattern) with its Polylang translation.
	 *
	 * @param array         $parsed_block The block being rendered.
	 * @param array         $source_block The original block before inner block filtering.
	 * @param WP_Block|null $parent_block Parent block instance, or null.
	 * @return array
	 */
	public function translate_synced_pattern_ref($parsed_block, $source_block, $parent_block) {
		if (is_admin()) {
			return $parsed_block;
		}

		if (($parsed_block['blockName'] ?? '') !== 'core/block') {
			return $parsed_block;
		}

		$ref = isset($parsed_block['attrs']['ref']) ? (int) $parsed_block['attrs']['ref'] : 0;
		if ($ref <= 0) {
			return $parsed_block;
		}

		if (!function_exists('pll_get_post') || !function_exists('pll_current_language')) {
			return $parsed_block;
		}

		$current_lang = pll_current_language();
		if (!$current_lang) {
			return $parsed_block;
		}

		$translated_ref = pll_get_post($ref, $current_lang);
		if ($translated_ref && $translated_ref !== $ref) {
			$parsed_block['attrs']['ref'] = $translated_ref;
		}

		return $parsed_block;
	}
}
