<?php
/**
 * Taxonomy-based Template Routing for FSE Block Themes
 *
 * Handles automatic template assignment based on taxonomy terms.
 * Similar to Divi's Theme Builder, but for the native block editor.
 *
 * Includes an admin UI for mapping taxonomies to templates.
 *
 * @package NXT_Taxonomy_Template_Routing
 */

if (!defined('ABSPATH')) {
	exit;
}

class NXT_Taxonomy_Template_Router {

	const OPTION_NAME = 'nxt_taxonomy_template_mappings';
	const DEBUG_LOG_OPTION = 'nxt_taxonomy_template_debug_log';
	const ADMIN_PAGE_SLUG = 'nxt-template-routing';
	const DEBUG_MODE = false;
	const DEBUG_LOG_MAX_ENTRIES = 50;
	const PLL_TEMPLATE_SLUG_SEPARATOR = '___';

	private static $instance = null;
	private $mappings = null;
	private $is_resolving = false;

	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->log('Router initialized', [
			'is_block_theme' => function_exists('wp_is_block_theme') ? wp_is_block_theme() : 'function not exists',
		]);

		add_filter('resolve_block_template', [$this, 'resolve_block_template'], 10, 4);
		add_filter('pre_get_block_template', [$this, 'maybe_override_template'], 10, 3);
		add_filter('single_template', [$this, 'filter_single_template'], 99);
		add_filter('page_template', [$this, 'filter_single_template'], 99);
		add_filter('get_block_templates', [$this, 'filter_block_templates'], 10, 3);
		add_filter('template_include', [$this, 'filter_template_include'], 99);

		if (self::DEBUG_MODE) {
			add_action('wp_head', [$this, 'debug_html_comment'], 1);
		}

		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

		add_action('wp_ajax_nxt_save_template_mapping', [$this, 'ajax_save_mapping']);
		add_action('wp_ajax_nxt_delete_template_mapping', [$this, 'ajax_delete_mapping']);
		add_action('wp_ajax_nxt_get_taxonomy_terms', [$this, 'ajax_get_taxonomy_terms']);
		add_action('wp_ajax_nxt_clear_debug_log', [$this, 'ajax_clear_debug_log']);
		add_action('wp_ajax_nxt_sync_template_to_file', [$this, 'ajax_sync_template_to_file']);
		add_action('wp_ajax_nxt_toggle_pattern_translation', [$this, 'ajax_toggle_pattern_translation']);
	}

	private function log($message, $context = []) {
		if (!self::DEBUG_MODE) {
			return;
		}

		$log = get_option(self::DEBUG_LOG_OPTION, []);
		if (!is_array($log)) {
			$log = [];
		}

		$entry = [
			'time'      => current_time('mysql'),
			'timestamp' => time(),
			'message'   => $message,
			'context'   => $context,
			'url'       => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'N/A',
		];

		array_unshift($log, $entry);
		$log = array_slice($log, 0, self::DEBUG_LOG_MAX_ENTRIES);
		update_option(self::DEBUG_LOG_OPTION, $log, false);

		if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			$context_str = !empty($context) ? ' | Context: ' . json_encode($context) : '';
			error_log('[NXT Template Router] ' . $message . $context_str);
		}
	}

	public function get_debug_log() {
		$log = get_option(self::DEBUG_LOG_OPTION, []);
		return is_array($log) ? $log : [];
	}

	public function clear_debug_log() {
		delete_option(self::DEBUG_LOG_OPTION);
	}

	public function debug_html_comment() {
		if (is_admin()) {
			return;
		}

		$post_id         = get_queried_object_id();
		$post            = get_post($post_id);
		$mappings        = $this->get_mappings();
		$matched_template = $this->get_template_for_post($post_id);

		echo "\n<!-- NXT Template Router Debug\n";
		echo "Post ID: " . esc_html($post_id) . "\n";
		echo "Post Type: " . esc_html($post ? $post->post_type : 'N/A') . "\n";
		echo "Is Singular: " . (is_singular() ? 'Yes' : 'No') . "\n";
		echo "Mappings Count: " . count($mappings) . "\n";
		echo "Matched Template: " . esc_html($matched_template ?: 'None') . "\n";

		if ($post_id && $post) {
			echo "Post Taxonomies:\n";
			$taxonomies = get_object_taxonomies($post->post_type);
			foreach ($taxonomies as $tax) {
				$terms = get_the_terms($post_id, $tax);
				if ($terms && !is_wp_error($terms)) {
					$term_names = wp_list_pluck($terms, 'slug');
					echo "  - {$tax}: " . implode(', ', $term_names) . "\n";
				}
			}
		}

		echo "-->\n";
	}

	public function get_mappings() {
		if ($this->mappings === null) {
			$this->mappings = get_option(self::OPTION_NAME, []);
			if (!is_array($this->mappings)) {
				$this->mappings = [];
			}
		}
		return $this->mappings;
	}

	public function save_mappings($mappings) {
		$this->mappings = $mappings;
		$this->log('Mappings saved', ['count' => count($mappings)]);
		return update_option(self::OPTION_NAME, $mappings);
	}

	public function get_available_taxonomies() {
		$taxonomies = get_taxonomies(['public' => true], 'objects');
		$result     = [];

		foreach ($taxonomies as $taxonomy) {
			$post_types = $taxonomy->object_type;
			if (empty($post_types)) {
				continue;
			}

			$result[$taxonomy->name] = [
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->label,
				'post_types'   => $post_types,
				'hierarchical' => $taxonomy->hierarchical,
			];
		}

		return $result;
	}

	public function get_available_templates() {
		$templates    = [];
		$template_dir = get_stylesheet_directory() . '/templates/';

		if (is_dir($template_dir)) {
			$files = glob($template_dir . '*.html');
			foreach ($files as $file) {
				$filename              = basename($file, '.html');
				$templates[$filename] = [
					'slug'   => $filename,
					'title'  => $this->format_template_name($filename),
					'source' => 'theme',
				];
			}
		}

		$db_templates = get_block_templates([], 'wp_template');
		foreach ($db_templates as $template) {
			$slug = $template->slug;
			if (!isset($templates[$slug])) {
				$templates[$slug] = [
					'slug'   => $slug,
					'title'  => $template->title ?? $this->format_template_name($slug),
					'source' => $template->source,
				];
			}
		}

		return $templates;
	}

	private function format_template_name($filename) {
		$name = str_replace(['-', '_'], ' ', $filename);
		return ucwords($name);
	}

	public function get_taxonomy_terms($taxonomy) {
		$terms = get_terms([
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		]);

		if (is_wp_error($terms)) {
			return [];
		}

		$result = [];
		foreach ($terms as $term) {
			$result[] = [
				'term_id' => $term->term_id,
				'slug'    => $term->slug,
				'name'    => $term->name,
			];
		}

		return $result;
	}

	private function resolve_template_slug_for_language($base_slug, $mapping = null) {
		$template_per_lang = $mapping['template_per_lang'] ?? null;
		if (is_array($template_per_lang) && !empty($template_per_lang)) {
			$current_lang = $this->get_current_polylang_language();
			if ($current_lang && isset($template_per_lang[$current_lang])) {
				$override = $template_per_lang[$current_lang];
				if (is_string($override) && $override !== '') {
					return $override;
				}
			}
		}

		if (!$this->is_polylang_active()) {
			return $base_slug;
		}

		$current_lang = $this->get_current_polylang_language();
		$default_lang = $this->get_polylang_default_language();
		if (!$current_lang || !$default_lang || $current_lang === $default_lang) {
			return $base_slug;
		}

		$lang_slug   = $base_slug . self::PLL_TEMPLATE_SLUG_SEPARATOR . $current_lang;
		$template_id = get_stylesheet() . '//' . $lang_slug;

		$this->is_resolving = true;
		$block_template     = get_block_template($template_id, 'wp_template');
		$this->is_resolving = false;

		if ($block_template) {
			$this->log('Polylang: Using language-specific template', [
				'base'     => $base_slug,
				'resolved' => $lang_slug,
				'lang'     => $current_lang,
			]);
			return $lang_slug;
		}

		return $base_slug;
	}

	public function is_polylang_active() {
		return function_exists('pll_current_language');
	}

	private function get_current_polylang_language() {
		if (!$this->is_polylang_active()) {
			return null;
		}
		$lang = pll_current_language();
		return is_string($lang) && $lang !== '' ? $lang : null;
	}

	private function get_polylang_default_language() {
		if (!$this->is_polylang_active() || !function_exists('pll_default_language')) {
			return null;
		}
		$lang = pll_default_language();
		return is_string($lang) && $lang !== '' ? $lang : null;
	}

	public function get_template_for_post($post_id = null) {
		if ($post_id === null) {
			$post_id = get_queried_object_id();
		}

		$this->log('get_template_for_post called', ['post_id' => $post_id]);

		if (!$post_id) {
			$this->log('No post ID found');
			return false;
		}

		$post = get_post($post_id);
		if (!$post) {
			$this->log('Post not found', ['post_id' => $post_id]);
			return false;
		}

		$mappings = $this->get_mappings();
		if (empty($mappings)) {
			$this->log('No mappings configured');
			return false;
		}

		$this->log('Processing mappings', [
			'post_id'        => $post_id,
			'post_type'      => $post->post_type,
			'mappings_count' => count($mappings),
		]);

		$matched_template = false;
		$matched_mapping  = null;
		$highest_priority = -1;

		foreach ($mappings as $index => $mapping) {
			$this->log("Checking mapping #{$index}", $mapping);

			if (empty($mapping['taxonomy']) || empty($mapping['template'])) {
				$this->log("Mapping #{$index} skipped: missing taxonomy or template");
				continue;
			}

			if (empty($mapping['enabled'])) {
				$this->log("Mapping #{$index} skipped: not enabled");
				continue;
			}

			$taxonomy   = $mapping['taxonomy'];
			$template   = $mapping['template'];
			$term_slugs = $mapping['terms'] ?? 'any';
			$post_types = $mapping['post_types'] ?? [];
			$priority   = intval($mapping['priority'] ?? 10);

			if (!empty($post_types) && !in_array($post->post_type, $post_types, true)) {
				$this->log("Mapping #{$index} skipped: post type mismatch", [
					'expected' => $post_types,
					'actual'   => $post->post_type,
				]);
				continue;
			}

			if (!is_object_in_taxonomy($post->post_type, $taxonomy)) {
				$this->log("Mapping #{$index} skipped: taxonomy not registered for post type", [
					'taxonomy'  => $taxonomy,
					'post_type' => $post->post_type,
				]);
				continue;
			}

			$terms = get_the_terms($post_id, $taxonomy);

			if (is_wp_error($terms)) {
				$this->log("Mapping #{$index} skipped: error getting terms", [
					'error' => $terms->get_error_message(),
				]);
				continue;
			}

			if (empty($terms)) {
				$this->log("Mapping #{$index} skipped: post has no terms in taxonomy", [
					'taxonomy' => $taxonomy,
				]);
				continue;
			}

			$post_term_slugs = wp_list_pluck($terms, 'slug');
			$this->log("Post terms in {$taxonomy}", ['terms' => $post_term_slugs]);

			$has_match = false;
			if ($term_slugs === 'any' || (is_array($term_slugs) && in_array('any', $term_slugs, true))) {
				$has_match = true;
				$this->log("Mapping #{$index}: matches 'any' term rule");
			} else {
				$intersection = array_intersect($post_term_slugs, (array) $term_slugs);
				$has_match    = !empty($intersection);
				$this->log("Mapping #{$index}: term match check", [
					'required_terms' => $term_slugs,
					'post_terms'     => $post_term_slugs,
					'intersection'   => $intersection,
					'has_match'      => $has_match,
				]);
			}

			if ($has_match && $priority > $highest_priority) {
				$matched_template = $template;
				$matched_mapping  = $mapping;
				$highest_priority = $priority;
				$this->log("Mapping #{$index}: MATCHED!", [
					'template' => $template,
					'priority' => $priority,
				]);
			}
		}

		$this->log('Template matching complete', [
			'matched_template' => $matched_template ?: 'none',
			'priority'         => $highest_priority,
		]);

		if (!$matched_template) {
			return false;
		}

		return $this->resolve_template_slug_for_language($matched_template, $matched_mapping);
	}

	public function resolve_block_template($template, $type = '', $templates = [], $args = []) {
		if ($this->is_resolving) {
			return $template;
		}

		$this->log('resolve_block_template filter called', [
			'type'             => $type,
			'current_template' => $template ? $template->slug : 'none',
			'is_admin'         => is_admin(),
			'is_singular'      => is_singular(),
		]);

		if (is_admin() || !is_singular()) {
			$this->log('Skipped: is_admin or not is_singular');
			return $template;
		}

		$custom_template_slug = $this->get_template_for_post();
		if (!$custom_template_slug) {
			$this->log('No custom template matched in resolve_block_template');
			return $template;
		}

		$this->is_resolving   = true;
		$custom_template_id   = get_stylesheet() . '//' . $custom_template_slug;
		$this->log('Attempting to load custom template via resolve_block_template', [
			'template_id' => $custom_template_id,
		]);

		$block_template     = get_block_template($custom_template_id, 'wp_template');
		$this->is_resolving = false;

		if ($block_template) {
			$this->log('SUCCESS via resolve_block_template!', [
				'template_slug'   => $block_template->slug,
				'template_source' => $block_template->source,
				'template_id'     => $block_template->id,
			]);
			return $block_template;
		}

		$this->log('FAILED: Could not load template via resolve_block_template', [
			'template_id' => $custom_template_id,
		]);

		return $template;
	}

	public function maybe_override_template($template, $id, $template_type) {
		if ($this->is_resolving) {
			return $template;
		}

		$this->log('pre_get_block_template filter called', [
			'id'            => $id,
			'template_type' => $template_type,
			'is_admin'      => is_admin(),
			'is_singular'   => is_singular(),
		]);

		if ($template_type !== 'wp_template') {
			return $template;
		}

		if (is_admin() || !is_singular()) {
			return $template;
		}

		$custom_template_slug = $this->get_template_for_post();
		if (!$custom_template_slug) {
			return $template;
		}

		$custom_template_id = get_stylesheet() . '//' . $custom_template_slug;
		if ($id === $custom_template_id) {
			return $template;
		}

		$this->is_resolving = true;
		$this->log('pre_get_block_template: Attempting to load custom template', [
			'template_id' => $custom_template_id,
		]);

		$block_template     = get_block_template($custom_template_id, 'wp_template');
		$this->is_resolving = false;

		if ($block_template) {
			$this->log('SUCCESS via pre_get_block_template!', [
				'template_slug'   => $block_template->slug,
				'template_source' => $block_template->source,
			]);
			return $block_template;
		}

		$this->log('FAILED via pre_get_block_template', [
			'template_id' => $custom_template_id,
		]);

		return $template;
	}

	public function filter_single_template($template) {
		if ($this->is_resolving) {
			return $template;
		}

		$this->log('single_template/page_template filter called', [
			'template'    => basename($template),
			'is_singular' => is_singular(),
		]);

		if (!is_singular()) {
			return $template;
		}

		$custom_template_slug = $this->get_template_for_post();
		if (!$custom_template_slug) {
			return $template;
		}

		global $_wp_current_template_content;
		global $_wp_current_template_id;

		$this->is_resolving   = true;
		$custom_template_id   = get_stylesheet() . '//' . $custom_template_slug;
		$block_template       = get_block_template($custom_template_id, 'wp_template');
		$this->is_resolving   = false;

		if ($block_template && !empty($block_template->content)) {
			$_wp_current_template_content = $block_template->content;
			$_wp_current_template_id      = $block_template->id;

			$this->log('SUCCESS via single_template: Set block template content!', [
				'template_slug'   => $block_template->slug,
				'template_id'     => $block_template->id,
				'content_length'  => strlen($block_template->content),
			]);

			return ABSPATH . WPINC . '/template-canvas.php';
		}

		$this->log('single_template: Could not load block template', [
			'template_id' => $custom_template_id,
		]);

		return $template;
	}

	public function filter_block_templates($query_result, $query, $template_type) {
		$this->log('get_block_templates filter called', [
			'template_type' => $template_type,
			'count'         => count($query_result),
		]);
		return $query_result;
	}

	public function filter_template_include($template) {
		$this->log('template_include filter called', [
			'template'    => basename($template),
			'is_admin'    => is_admin(),
			'is_singular' => is_singular(),
		]);

		if (is_admin() || !is_singular()) {
			return $template;
		}

		$custom_template_slug = $this->get_template_for_post();
		if (!$custom_template_slug) {
			return $template;
		}

		$block_template_file = get_stylesheet_directory() . '/templates/' . $custom_template_slug . '.html';

		$this->log('Checking for block template file', [
			'path'   => $block_template_file,
			'exists' => file_exists($block_template_file),
		]);

		if (file_exists($block_template_file)) {
			return $template;
		}

		$php_template = get_stylesheet_directory() . '/' . $custom_template_slug . '.php';
		if (file_exists($php_template)) {
			$this->log('Using PHP template fallback', ['path' => $php_template]);
			return $php_template;
		}

		return $template;
	}

	public function add_admin_menu() {
		add_theme_page(
			__('Template Routing', 'nxt-taxonomy-template-routing'),
			__('Template Routing', 'nxt-taxonomy-template-routing'),
			'edit_theme_options',
			self::ADMIN_PAGE_SLUG,
			[$this, 'render_admin_page']
		);
	}

	public function register_settings() {
		register_setting(self::OPTION_NAME, self::OPTION_NAME, [
			'type'              => 'array',
			'sanitize_callback' => [$this, 'sanitize_mappings'],
		]);
	}

	public function sanitize_mappings($mappings) {
		if (!is_array($mappings)) {
			return [];
		}

		$sanitized = [];
		foreach ($mappings as $mapping) {
			if (empty($mapping['taxonomy']) || empty($mapping['template'])) {
				continue;
			}

			$template_per_lang = [];
			if (!empty($mapping['template_per_lang']) && is_array($mapping['template_per_lang'])) {
				foreach ($mapping['template_per_lang'] as $lang => $tpl) {
					if (is_string($lang) && is_string($tpl) && $tpl !== '') {
						$template_per_lang[sanitize_key($lang)] = sanitize_key($tpl);
					}
				}
			}

			$sanitized[] = [
				'id'                => sanitize_key($mapping['id'] ?? uniqid('mapping_')),
				'taxonomy'          => sanitize_key($mapping['taxonomy']),
				'terms'             => isset($mapping['terms']) ? array_map('sanitize_key', (array) $mapping['terms']) : 'any',
				'template'          => sanitize_key($mapping['template']),
				'template_per_lang' => $template_per_lang,
				'post_types'        => isset($mapping['post_types']) ? array_map('sanitize_key', (array) $mapping['post_types']) : [],
				'priority'          => intval($mapping['priority'] ?? 10),
				'enabled'           => !empty($mapping['enabled']),
			];
		}

		return $sanitized;
	}

	public function enqueue_admin_assets($hook) {
		if ($hook !== 'appearance_page_' . self::ADMIN_PAGE_SLUG) {
			return;
		}

		wp_enqueue_style(
			'nxt-template-routing-admin',
			NXT_TEMPLATE_ROUTING_PLUGIN_URL . 'assets/css/template-routing-admin.css',
			[],
			NXT_TEMPLATE_ROUTING_VERSION
		);

		wp_enqueue_script(
			'nxt-template-routing-admin',
			NXT_TEMPLATE_ROUTING_PLUGIN_URL . 'assets/js/template-routing-admin.js',
			[],
			NXT_TEMPLATE_ROUTING_VERSION,
			true
		);

		$languages = [];
		if (function_exists('pll_languages_list') && function_exists('PLL')) {
			$polylang  = PLL();
			$lang_slugs = pll_languages_list();
			foreach ($lang_slugs as $slug) {
				$lang_obj            = isset($polylang->model) ? $polylang->model->get_language($slug) : null;
				$languages[$slug]    = $lang_obj && isset($lang_obj->name) ? $lang_obj->name : $slug;
			}
		}

		wp_localize_script('nxt-template-routing-admin', 'nxtTemplateRouting', [
			'ajaxUrl'   => admin_url('admin-ajax.php'),
			'nonce'     => wp_create_nonce('nxt_template_routing'),
			'mappings'  => $this->get_mappings(),
			'taxonomies' => $this->get_available_taxonomies(),
			'templates' => $this->get_available_templates(),
			'languages' => $languages,
			'i18n'      => [
				'confirm_delete'     => __('Are you sure you want to delete this mapping?', 'nxt-taxonomy-template-routing'),
				'save_success'       => __('Mappings saved successfully.', 'nxt-taxonomy-template-routing'),
				'save_error'         => __('Error saving mappings.', 'nxt-taxonomy-template-routing'),
				'any_term'           => __('Any term', 'nxt-taxonomy-template-routing'),
				'select_taxonomy'    => __('Select taxonomy...', 'nxt-taxonomy-template-routing'),
				'select_template'    => __('Select template...', 'nxt-taxonomy-template-routing'),
				'select_terms'       => __('Select terms (leave empty for any)...', 'nxt-taxonomy-template-routing'),
				'template_per_lang'  => __('Template per language (optional)', 'nxt-taxonomy-template-routing'),
				'template_per_lang_desc' => __('Override the template for specific languages. Leave empty to use Polylang\'s automatic template translation.', 'nxt-taxonomy-template-routing'),
			],
		]);
	}

	public function ajax_save_mapping() {
		check_ajax_referer('nxt_template_routing', 'nonce');

		if (!current_user_can('edit_theme_options')) {
			wp_send_json_error(['message' => 'Unauthorized']);
		}

		$mappings = isset($_POST['mappings']) ? json_decode(stripslashes($_POST['mappings']), true) : [];
		$mappings = $this->sanitize_mappings($mappings);

		if ($this->save_mappings($mappings)) {
			wp_send_json_success(['message' => 'Saved', 'mappings' => $mappings]);
		} else {
			wp_send_json_error(['message' => 'Failed to save']);
		}
	}

	public function ajax_delete_mapping() {
		check_ajax_referer('nxt_template_routing', 'nonce');

		if (!current_user_can('edit_theme_options')) {
			wp_send_json_error(['message' => 'Unauthorized']);
		}

		$mapping_id = sanitize_key($_POST['mapping_id'] ?? '');
		if (!$mapping_id) {
			wp_send_json_error(['message' => 'Invalid mapping ID']);
		}

		$mappings = $this->get_mappings();
		$mappings = array_filter($mappings, function($m) use ($mapping_id) {
			return ($m['id'] ?? '') !== $mapping_id;
		});

		if ($this->save_mappings(array_values($mappings))) {
			wp_send_json_success(['message' => 'Deleted']);
		} else {
			wp_send_json_error(['message' => 'Failed to delete']);
		}
	}

	public function ajax_get_taxonomy_terms() {
		check_ajax_referer('nxt_template_routing', 'nonce');

		$taxonomy = sanitize_key($_GET['taxonomy'] ?? '');
		if (!$taxonomy || !taxonomy_exists($taxonomy)) {
			wp_send_json_error(['message' => 'Invalid taxonomy']);
		}

		wp_send_json_success(['terms' => $this->get_taxonomy_terms($taxonomy)]);
	}

	public function ajax_clear_debug_log() {
		check_ajax_referer('nxt_template_routing', 'nonce');

		if (!current_user_can('edit_theme_options')) {
			wp_send_json_error(['message' => 'Unauthorized']);
		}

		$this->clear_debug_log();
		wp_send_json_success(['message' => 'Log cleared']);
	}

	public function get_templates_with_db_versions() {
		$result       = [];
		$template_dir = get_stylesheet_directory() . '/templates/';

		$db_templates = get_block_templates([], 'wp_template');

		foreach ($db_templates as $template) {
			if (strpos($template->id, get_stylesheet() . '//') !== 0) {
				continue;
			}

			$slug         = $template->slug;
			$file_path    = $template_dir . $slug . '.html';
			$file_exists  = file_exists($file_path);
			$file_content = $file_exists ? file_get_contents($file_path) : '';
			$db_content   = $template->content;
			$is_modified  = $file_exists ? (trim($db_content) !== trim($file_content)) : true;

			$result[$slug] = [
				'slug'                => $slug,
				'title'               => $template->title ?? $this->format_template_name($slug),
				'source'              => $template->source,
				'id'                  => $template->id,
				'file_exists'         => $file_exists,
				'file_path'           => $file_path,
				'is_modified'         => $is_modified,
				'db_content_length'   => strlen($db_content),
				'file_content_length' => strlen($file_content),
			];
		}

		return $result;
	}

	public function sync_template_to_file($template_slug) {
		$template_id = get_stylesheet() . '//' . $template_slug;
		$template    = get_block_template($template_id, 'wp_template');

		if (!$template) {
			return [
				'success' => false,
				'message' => 'Template not found in database: ' . $template_slug,
			];
		}

		$template_dir = get_stylesheet_directory() . '/templates/';

		if (!is_dir($template_dir)) {
			if (!wp_mkdir_p($template_dir)) {
				return [
					'success' => false,
					'message' => 'Could not create templates directory',
				];
			}
		}

		$file_path = $template_dir . $template_slug . '.html';
		$content   = $template->content;

		if (file_exists($file_path)) {
			$backup_path = $file_path . '.backup-' . date('Y-m-d-H-i-s');
			copy($file_path, $backup_path);
		}

		$result = file_put_contents($file_path, $content);

		if ($result === false) {
			return [
				'success' => false,
				'message' => 'Failed to write file: ' . $file_path,
			];
		}

		$this->log('Template synced to file', [
			'template' => $template_slug,
			'file'     => $file_path,
			'bytes'    => $result,
		]);

		return [
			'success' => true,
			'message' => 'Template synced successfully',
			'file'    => $file_path,
			'bytes'   => $result,
		];
	}

	public function ajax_sync_template_to_file() {
		check_ajax_referer('nxt_template_routing', 'nonce');

		if (!current_user_can('edit_theme_options')) {
			wp_send_json_error(['message' => 'Unauthorized']);
		}

		$template_slug = sanitize_key($_POST['template_slug'] ?? '');
		if (!$template_slug) {
			wp_send_json_error(['message' => 'No template slug provided']);
		}

		$result = $this->sync_template_to_file($template_slug);

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result);
		}
	}

	public function ajax_toggle_pattern_translation() {
		check_ajax_referer('nxt_template_routing', 'nonce');

		if (!current_user_can('edit_theme_options')) {
			wp_send_json_error(['message' => 'Unauthorized']);
		}

		$enabled = isset($_POST['enabled']) ? (bool) $_POST['enabled'] : false;
		update_option(NXT_Synced_Pattern_Translator::OPTION_NAME, $enabled);
		wp_send_json_success(['enabled' => $enabled]);
	}

	public function render_admin_page() {
		$debug_log = $this->get_debug_log();
		$templates = $this->get_available_templates();
		?>
		<div class="wrap nxt-template-routing-wrap">
			<h1><?php esc_html_e('Template Routing', 'nxt-taxonomy-template-routing'); ?></h1>
			<p class="description">
				<?php esc_html_e('Map taxonomies to templates. Pages with the specified taxonomy terms will automatically use the assigned template.', 'nxt-taxonomy-template-routing'); ?>
			</p>

			<div class="nxt-plugin-settings" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; margin-top: 20px; padding: 16px 20px;">
				<h2 style="margin: 0 0 12px;"><?php esc_html_e('Plugin Settings', 'nxt-taxonomy-template-routing'); ?></h2>
				<label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
					<input
						type="checkbox"
						id="nxt-pattern-translation-toggle"
						style="width: 18px; height: 18px; margin: 0;"
						<?php checked(NXT_Synced_Pattern_Translator::is_enabled()); ?>
					>
					<span>
						<strong><?php esc_html_e('Synced Pattern Translation Fallback', 'nxt-taxonomy-template-routing'); ?></strong>
						<span style="display: block; font-size: 12px; color: #646970; margin-top: 2px;">
							<?php esc_html_e('Intercepts core/block rendering and swaps the pattern ref with the Polylang-translated version. Enable as a fallback when Polylang\'s own mechanism fails.', 'nxt-taxonomy-template-routing'); ?>
						</span>
					</span>
					<span id="nxt-pattern-translation-status" style="font-size: 12px; color: #646970; margin-left: auto;"></span>
				</label>
			</div>

			<div class="nxt-template-routing-container">
				<div class="nxt-mappings-header">
					<h2><?php esc_html_e('Taxonomy → Template Mappings', 'nxt-taxonomy-template-routing'); ?></h2>
					<button type="button" class="button button-primary" id="nxt-add-mapping">
						<?php esc_html_e('+ Add Mapping', 'nxt-taxonomy-template-routing'); ?>
					</button>
				</div>

				<div id="nxt-mappings-list" class="nxt-mappings-list">
					<!-- Mappings will be rendered here by JavaScript -->
				</div>

				<div class="nxt-mappings-footer">
					<button type="button" class="button button-primary button-hero" id="nxt-save-mappings">
						<?php esc_html_e('Save All Mappings', 'nxt-taxonomy-template-routing'); ?>
					</button>
					<span id="nxt-save-status" class="nxt-save-status"></span>
				</div>
			</div>

			<?php if (self::DEBUG_MODE): ?>
			<div class="nxt-template-routing-debug" style="margin-top: 30px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
				<h2 style="margin-top: 0; display: flex; justify-content: space-between; align-items: center;">
					Debug Information
					<button type="button" class="button" id="nxt-clear-debug-log">Clear Log</button>
				</h2>

				<h3>Available Templates (<?php echo count($templates); ?>)</h3>
				<table class="widefat" style="margin-bottom: 20px;">
					<thead>
						<tr>
							<th>Slug</th>
							<th>Title</th>
							<th>Source</th>
							<th>File Exists</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($templates as $tpl):
							$file_path = get_stylesheet_directory() . '/templates/' . $tpl['slug'] . '.html';
							$exists    = file_exists($file_path);
						?>
						<tr>
							<td><code><?php echo esc_html($tpl['slug']); ?></code></td>
							<td><?php echo esc_html($tpl['title']); ?></td>
							<td><?php echo esc_html($tpl['source']); ?></td>
							<td><?php echo $exists ? 'Yes' : 'No (DB only)'; ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h3>Recent Activity Log (Last <?php echo count($debug_log); ?> entries)</h3>
				<?php if (empty($debug_log)): ?>
					<p><em>No log entries yet. Visit a page on the frontend to see routing activity.</em></p>
				<?php else: ?>
					<div style="max-height: 400px; overflow-y: auto; background: #1d2327; color: #f0f0f1; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px;">
						<?php foreach ($debug_log as $entry): ?>
							<div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #3c434a;">
								<span style="color: #72aee6;">[<?php echo esc_html($entry['time']); ?>]</span>
								<span style="color: #f0f0f1;"><?php echo esc_html($entry['message']); ?></span>
								<?php if (!empty($entry['context'])): ?>
									<div style="color: #c3c4c7; margin-left: 20px; margin-top: 4px;">
										<?php echo esc_html(json_encode($entry['context'], JSON_PRETTY_PRINT)); ?>
									</div>
								<?php endif; ?>
								<?php if (!empty($entry['url']) && $entry['url'] !== 'N/A'): ?>
									<div style="color: #8c8f94; margin-left: 20px;">
										URL: <?php echo esc_html($entry['url']); ?>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<h3 style="margin-top: 20px;">Current Mappings (Raw Data)</h3>
				<pre style="background: #f0f0f1; padding: 15px; overflow-x: auto; border-radius: 4px;"><?php
					$mappings = $this->get_mappings();
					echo esc_html(json_encode($mappings, JSON_PRETTY_PRINT));
				?></pre>
			</div>
			<?php endif; ?>

			<div class="nxt-template-sync" style="margin-top: 30px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
				<h2 style="margin-top: 0;">Sync Templates (DB &rarr; File)</h2>
				<p class="description" style="margin-bottom: 15px;">
					<?php esc_html_e('When you edit templates in the Site Editor, changes are saved to the database. Use this section to export those changes back to your theme files (for version control, deployment, etc.).', 'nxt-taxonomy-template-routing'); ?>
				</p>

				<?php
				$db_templates = $this->get_templates_with_db_versions();
				if (empty($db_templates)):
				?>
					<p><em><?php esc_html_e('No templates with database customizations found.', 'nxt-taxonomy-template-routing'); ?></em></p>
				<?php else: ?>
					<table class="widefat">
						<thead>
							<tr>
								<th><?php esc_html_e('Template', 'nxt-taxonomy-template-routing'); ?></th>
								<th><?php esc_html_e('Source', 'nxt-taxonomy-template-routing'); ?></th>
								<th><?php esc_html_e('File Status', 'nxt-taxonomy-template-routing'); ?></th>
								<th><?php esc_html_e('DB Size', 'nxt-taxonomy-template-routing'); ?></th>
								<th><?php esc_html_e('File Size', 'nxt-taxonomy-template-routing'); ?></th>
								<th><?php esc_html_e('Action', 'nxt-taxonomy-template-routing'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($db_templates as $tpl): ?>
							<tr data-template="<?php echo esc_attr($tpl['slug']); ?>">
								<td>
									<strong><?php echo esc_html($tpl['title']); ?></strong><br>
									<code style="font-size: 11px;"><?php echo esc_html($tpl['slug']); ?></code>
								</td>
								<td>
									<?php echo $tpl['source'] === 'custom' ? esc_html__('Database', 'nxt-taxonomy-template-routing') : esc_html__('Theme', 'nxt-taxonomy-template-routing'); ?>
								</td>
								<td>
									<?php if ($tpl['file_exists']): ?>
										<?php if ($tpl['is_modified']): ?>
											<span style="color: #dba617;"><?php esc_html_e('Modified in DB', 'nxt-taxonomy-template-routing'); ?></span>
										<?php else: ?>
											<span style="color: #00a32a;"><?php esc_html_e('In Sync', 'nxt-taxonomy-template-routing'); ?></span>
										<?php endif; ?>
									<?php else: ?>
										<span style="color: #d63638;"><?php esc_html_e('No file', 'nxt-taxonomy-template-routing'); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo number_format($tpl['db_content_length']); ?> bytes</td>
								<td><?php echo $tpl['file_exists'] ? number_format($tpl['file_content_length']) . ' bytes' : '—'; ?></td>
								<td>
									<button type="button"
										class="button sync-template-btn"
										data-slug="<?php echo esc_attr($tpl['slug']); ?>"
										<?php echo (!$tpl['is_modified'] && $tpl['file_exists']) ? 'disabled' : ''; ?>>
										<?php echo $tpl['file_exists'] ? esc_html__('Update File', 'nxt-taxonomy-template-routing') : esc_html__('Create File', 'nxt-taxonomy-template-routing'); ?>
									</button>
									<span class="sync-status" style="margin-left: 8px;"></span>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p style="margin-top: 15px;">
						<button type="button" class="button button-primary" id="nxt-sync-all-templates">
							<?php esc_html_e('Sync All Modified Templates', 'nxt-taxonomy-template-routing'); ?>
						</button>
						<span id="nxt-sync-all-status" style="margin-left: 10px;"></span>
					</p>
				<?php endif; ?>
			</div>

			<div class="nxt-template-routing-info">
				<h3><?php esc_html_e('How it works', 'nxt-taxonomy-template-routing'); ?></h3>
				<ul>
					<li><?php esc_html_e('Create a mapping between a taxonomy and a template.', 'nxt-taxonomy-template-routing'); ?></li>
					<li><?php esc_html_e('When a page/post has a term from that taxonomy, it will use the assigned template.', 'nxt-taxonomy-template-routing'); ?></li>
					<li><?php esc_html_e('You can restrict to specific terms or apply to any term in the taxonomy.', 'nxt-taxonomy-template-routing'); ?></li>
					<li><?php esc_html_e('Higher priority numbers win when multiple mappings match.', 'nxt-taxonomy-template-routing'); ?></li>
				</ul>

				<?php if ($this->is_polylang_active()): ?>
				<h3><?php esc_html_e('Polylang Integration', 'nxt-taxonomy-template-routing'); ?></h3>
				<p>
					<?php esc_html_e('With Polylang active, the router automatically uses language-specific templates (e.g. single-mypage___de for German) when they exist in the Site Editor. You can also override the template per language in each mapping.', 'nxt-taxonomy-template-routing'); ?>
				</p>
				<?php endif; ?>

				<h3><?php esc_html_e('Creating Templates', 'nxt-taxonomy-template-routing'); ?></h3>
				<p>
					<?php
					printf(
						esc_html__('Create new templates in %s or use the %s.', 'nxt-taxonomy-template-routing'),
						'<code>Appearance &rarr; Editor &rarr; Templates</code>',
						'<a href="' . esc_url(admin_url('site-editor.php?path=%2Fwp_template')) . '">' . esc_html__('Site Editor', 'nxt-taxonomy-template-routing') . '</a>'
					);
					?>
				</p>
			</div>
		</div>

		<?php
	}
}
