<?php
/**
 * Taxonomy-based Template Routing for FSE Block Themes
 * 
 * This file handles automatic template assignment based on taxonomy terms.
 * Similar to Divi's Theme Builder, but for the native block editor.
 * 
 * Includes an admin UI for mapping taxonomies to templates.
 * 
 * @package HPC Block Theme
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class NXT_Taxonomy_Template_Router
 * 
 * Handles the automatic routing of templates based on taxonomy assignments.
 * This replaces Divi Theme Builder's "Assign to pages with taxonomy X" functionality.
 */
class NXT_Taxonomy_Template_Router {

	/**
	 * Option name for storing mappings
	 */
	const OPTION_NAME = 'nxt_taxonomy_template_mappings';

	/**
	 * Option name for debug log
	 */
	const DEBUG_LOG_OPTION = 'nxt_taxonomy_template_debug_log';

	/**
	 * Admin page slug
	 */
	const ADMIN_PAGE_SLUG = 'nxt-template-routing';

	/**
	 * Debug mode - set to true to enable logging
	 */
	const DEBUG_MODE = false;

	/**
	 * Maximum number of debug log entries to keep
	 */
	const DEBUG_LOG_MAX_ENTRIES = 50;

	/**
	 * Singleton instance
	 * 
	 * @var NXT_Taxonomy_Template_Router|null
	 */
	private static $instance = null;

	/**
	 * Cached mappings
	 * 
	 * @var array|null
	 */
	private $mappings = null;

	/**
	 * Get singleton instance
	 * 
	 * @return NXT_Taxonomy_Template_Router
	 */
	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Flag to prevent recursive template lookups
	 * 
	 * @var bool
	 */
	private $is_resolving = false;

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->log('Router initialized', [
			'is_block_theme' => function_exists('wp_is_block_theme') ? wp_is_block_theme() : 'function not exists',
		]);

		// Frontend: Template routing - use multiple hooks for reliability
		// Primary: resolve_block_template filter (WordPress 6.1+)
		add_filter('resolve_block_template', [$this, 'resolve_block_template'], 10, 4);
		
		// Fallback: pre_get_block_template
		add_filter('pre_get_block_template', [$this, 'maybe_override_template'], 10, 3);
		
		// Try single_template / page_template filter (works for both classic and block themes)
		add_filter('single_template', [$this, 'filter_single_template'], 99);
		add_filter('page_template', [$this, 'filter_single_template'], 99);
		
		// Block template hierarchy filter (WordPress 6.1+)
		add_filter('get_block_templates', [$this, 'filter_block_templates'], 10, 3);
		
		// Last resort: template_include
		add_filter('template_include', [$this, 'filter_template_include'], 99);

		// Debug: Add HTML comment to frontend
		if (self::DEBUG_MODE) {
			add_action('wp_head', [$this, 'debug_html_comment'], 1);
		}

		// Admin: Settings page
		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

		// AJAX handlers for admin
		add_action('wp_ajax_nxt_save_template_mapping', [$this, 'ajax_save_mapping']);
		add_action('wp_ajax_nxt_delete_template_mapping', [$this, 'ajax_delete_mapping']);
		add_action('wp_ajax_nxt_get_taxonomy_terms', [$this, 'ajax_get_taxonomy_terms']);
		add_action('wp_ajax_nxt_clear_debug_log', [$this, 'ajax_clear_debug_log']);
		add_action('wp_ajax_nxt_sync_template_to_file', [$this, 'ajax_sync_template_to_file']);
	}

	/**
	 * Log a debug message
	 * 
	 * @param string $message
	 * @param array  $context Additional context data
	 */
	private function log($message, $context = []) {
		if (!self::DEBUG_MODE) {
			return;
		}

		$log = get_option(self::DEBUG_LOG_OPTION, []);
		if (!is_array($log)) {
			$log = [];
		}

		$entry = [
			'time' => current_time('mysql'),
			'timestamp' => time(),
			'message' => $message,
			'context' => $context,
			'url' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'N/A',
		];

		array_unshift($log, $entry);

		// Keep only the last N entries
		$log = array_slice($log, 0, self::DEBUG_LOG_MAX_ENTRIES);

		update_option(self::DEBUG_LOG_OPTION, $log, false);

		// Also log to error_log if WP_DEBUG_LOG is enabled
		if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			$context_str = !empty($context) ? ' | Context: ' . json_encode($context) : '';
			error_log('[NXT Template Router] ' . $message . $context_str);
		}
	}

	/**
	 * Get debug log entries
	 * 
	 * @return array
	 */
	public function get_debug_log() {
		$log = get_option(self::DEBUG_LOG_OPTION, []);
		return is_array($log) ? $log : [];
	}

	/**
	 * Clear debug log
	 */
	public function clear_debug_log() {
		delete_option(self::DEBUG_LOG_OPTION);
	}

	/**
	 * Output debug info as HTML comment in frontend
	 */
	public function debug_html_comment() {
		if (is_admin()) {
			return;
		}

		$post_id = get_queried_object_id();
		$post = get_post($post_id);
		$mappings = $this->get_mappings();
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

	/**
	 * Get all saved mappings
	 * 
	 * @return array
	 */
	public function get_mappings() {
		if ($this->mappings === null) {
			$this->mappings = get_option(self::OPTION_NAME, []);
			if (!is_array($this->mappings)) {
				$this->mappings = [];
			}
		}
		return $this->mappings;
	}

	/**
	 * Save mappings to database
	 * 
	 * @param array $mappings
	 * @return bool
	 */
	public function save_mappings($mappings) {
		$this->mappings = $mappings;
		$this->log('Mappings saved', ['count' => count($mappings)]);
		return update_option(self::OPTION_NAME, $mappings);
	}

	/**
	 * Get all available taxonomies that can be mapped
	 * 
	 * @return array
	 */
	public function get_available_taxonomies() {
		$taxonomies = get_taxonomies(['public' => true], 'objects');
		$result = [];

		foreach ($taxonomies as $taxonomy) {
			// Get post types this taxonomy is registered for
			$post_types = $taxonomy->object_type;
			
			// Skip taxonomies not attached to any post type
			if (empty($post_types)) {
				continue;
			}

			$result[$taxonomy->name] = [
				'name' => $taxonomy->name,
				'label' => $taxonomy->label,
				'post_types' => $post_types,
				'hierarchical' => $taxonomy->hierarchical,
			];
		}

		return $result;
	}

	/**
	 * Get all available templates from the theme
	 * 
	 * @return array
	 */
	public function get_available_templates() {
		$templates = [];

		// Get templates from the templates folder
		$template_dir = get_stylesheet_directory() . '/templates/';
		if (is_dir($template_dir)) {
			$files = glob($template_dir . '*.html');
			foreach ($files as $file) {
				$filename = basename($file, '.html');
				$templates[$filename] = [
					'slug' => $filename,
					'title' => $this->format_template_name($filename),
					'source' => 'theme',
				];
			}
		}

		// Get templates from the database (user-created in Site Editor)
		$db_templates = get_block_templates([], 'wp_template');
		foreach ($db_templates as $template) {
			$slug = $template->slug;
			if (!isset($templates[$slug])) {
				$templates[$slug] = [
					'slug' => $slug,
					'title' => $template->title ?? $this->format_template_name($slug),
					'source' => $template->source,
				];
			}
		}

		return $templates;
	}

	/**
	 * Format a template filename into a readable name
	 * 
	 * @param string $filename
	 * @return string
	 */
	private function format_template_name($filename) {
		$name = str_replace(['-', '_'], ' ', $filename);
		return ucwords($name);
	}

	/**
	 * Get terms for a taxonomy
	 * 
	 * @param string $taxonomy
	 * @return array
	 */
	public function get_taxonomy_terms($taxonomy) {
		$terms = get_terms([
			'taxonomy' => $taxonomy,
			'hide_empty' => false,
		]);

		if (is_wp_error($terms)) {
			return [];
		}

		$result = [];
		foreach ($terms as $term) {
			$result[] = [
				'term_id' => $term->term_id,
				'slug' => $term->slug,
				'name' => $term->name,
			];
		}

		return $result;
	}

	/**
	 * Get the template that should be used for the current post based on taxonomy.
	 * 
	 * @param int|null $post_id Optional. Post ID to check. Defaults to current post.
	 * @return string|false Template slug if found, false otherwise.
	 */
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
			'post_id' => $post_id,
			'post_type' => $post->post_type,
			'mappings_count' => count($mappings),
		]);

		$matched_template = false;
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

			$taxonomy = $mapping['taxonomy'];
			$template = $mapping['template'];
			$term_slugs = $mapping['terms'] ?? 'any';
			$post_types = $mapping['post_types'] ?? [];
			$priority = intval($mapping['priority'] ?? 10);

			// Check if this rule applies to this post type
			if (!empty($post_types) && !in_array($post->post_type, $post_types, true)) {
				$this->log("Mapping #{$index} skipped: post type mismatch", [
					'expected' => $post_types,
					'actual' => $post->post_type,
				]);
				continue;
			}

			// Check if the taxonomy is registered for this post type
			if (!is_object_in_taxonomy($post->post_type, $taxonomy)) {
				$this->log("Mapping #{$index} skipped: taxonomy not registered for post type", [
					'taxonomy' => $taxonomy,
					'post_type' => $post->post_type,
				]);
				continue;
			}

			// Get the terms for this post in this taxonomy
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

			// Check if any term matches our rule
			$has_match = false;
			if ($term_slugs === 'any' || (is_array($term_slugs) && in_array('any', $term_slugs, true))) {
				$has_match = true;
				$this->log("Mapping #{$index}: matches 'any' term rule");
			} else {
				$intersection = array_intersect($post_term_slugs, (array) $term_slugs);
				$has_match = !empty($intersection);
				$this->log("Mapping #{$index}: term match check", [
					'required_terms' => $term_slugs,
					'post_terms' => $post_term_slugs,
					'intersection' => $intersection,
					'has_match' => $has_match,
				]);
			}

			if ($has_match && $priority > $highest_priority) {
				$matched_template = $template;
				$highest_priority = $priority;
				$this->log("Mapping #{$index}: MATCHED!", [
					'template' => $template,
					'priority' => $priority,
				]);
			}
		}

		$this->log('Template matching complete', [
			'matched_template' => $matched_template ?: 'none',
			'priority' => $highest_priority,
		]);

		return $matched_template;
	}

	/**
	 * Filter for resolve_block_template (WordPress 6.1+)
	 * This is the most reliable hook for FSE template overrides.
	 * 
	 * @param WP_Block_Template      $template  The resolved template.
	 * @param string                 $type      The template type (e.g., 'single', 'page').
	 * @param array                  $templates Array of template candidates.
	 * @param array                  $args      Additional arguments.
	 * @return WP_Block_Template
	 */
	public function resolve_block_template($template, $type = '', $templates = [], $args = []) {
		// Prevent recursion
		if ($this->is_resolving) {
			return $template;
		}

		$this->log('resolve_block_template filter called', [
			'type' => $type,
			'current_template' => $template ? $template->slug : 'none',
			'is_admin' => is_admin(),
			'is_singular' => is_singular(),
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

		// Prevent recursion when calling get_block_template
		$this->is_resolving = true;

		$custom_template_id = get_stylesheet() . '//' . $custom_template_slug;
		$this->log('Attempting to load custom template via resolve_block_template', [
			'template_id' => $custom_template_id,
		]);

		$block_template = get_block_template($custom_template_id, 'wp_template');

		$this->is_resolving = false;

		if ($block_template) {
			$this->log('SUCCESS via resolve_block_template!', [
				'template_slug' => $block_template->slug,
				'template_source' => $block_template->source,
				'template_id' => $block_template->id,
			]);
			return $block_template;
		}

		$this->log('FAILED: Could not load template via resolve_block_template', [
			'template_id' => $custom_template_id,
		]);

		return $template;
	}

	/**
	 * Filter for pre_get_block_template - fallback override method.
	 * 
	 * @param WP_Block_Template|null $template
	 * @param string                 $id
	 * @param string                 $template_type
	 * @return WP_Block_Template|null
	 */
	public function maybe_override_template($template, $id, $template_type) {
		// Prevent recursion
		if ($this->is_resolving) {
			return $template;
		}

		$this->log('pre_get_block_template filter called', [
			'id' => $id,
			'template_type' => $template_type,
			'is_admin' => is_admin(),
			'is_singular' => is_singular(),
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

		// Check if the requested template ID already matches our custom template
		$custom_template_id = get_stylesheet() . '//' . $custom_template_slug;
		if ($id === $custom_template_id) {
			// Let WordPress handle the lookup normally
			return $template;
		}

		// Prevent recursion when calling get_block_template
		$this->is_resolving = true;

		$this->log('pre_get_block_template: Attempting to load custom template', [
			'template_id' => $custom_template_id,
		]);

		$block_template = get_block_template($custom_template_id, 'wp_template');

		$this->is_resolving = false;

		if ($block_template) {
			$this->log('SUCCESS via pre_get_block_template!', [
				'template_slug' => $block_template->slug,
				'template_source' => $block_template->source,
			]);
			return $block_template;
		}

		$this->log('FAILED via pre_get_block_template', [
			'template_id' => $custom_template_id,
		]);

		return $template;
	}

	/**
	 * Filter for single_template / page_template - override for pages.
	 * This fires earlier in the template hierarchy and can set the block template.
	 * 
	 * @param string $template
	 * @return string
	 */
	public function filter_single_template($template) {
		if ($this->is_resolving) {
			return $template;
		}

		$this->log('single_template/page_template filter called', [
			'template' => basename($template),
			'is_singular' => is_singular(),
		]);

		if (!is_singular()) {
			return $template;
		}

		$custom_template_slug = $this->get_template_for_post();
		if (!$custom_template_slug) {
			return $template;
		}

		// For block themes, we need to set the global template content
		global $_wp_current_template_content;
		global $_wp_current_template_id;

		$this->is_resolving = true;
		$custom_template_id = get_stylesheet() . '//' . $custom_template_slug;
		$block_template = get_block_template($custom_template_id, 'wp_template');
		$this->is_resolving = false;

		if ($block_template && !empty($block_template->content)) {
			$_wp_current_template_content = $block_template->content;
			$_wp_current_template_id = $block_template->id;
			
			$this->log('SUCCESS via single_template: Set block template content!', [
				'template_slug' => $block_template->slug,
				'template_id' => $block_template->id,
				'content_length' => strlen($block_template->content),
			]);

			// Return the canvas template to render the block content
			return ABSPATH . WPINC . '/template-canvas.php';
		}

		$this->log('single_template: Could not load block template', [
			'template_id' => $custom_template_id,
		]);

		return $template;
	}

	/**
	 * Filter block templates list.
	 * 
	 * @param WP_Block_Template[] $query_result
	 * @param array               $query
	 * @param string              $template_type
	 * @return WP_Block_Template[]
	 */
	public function filter_block_templates($query_result, $query, $template_type) {
		$this->log('get_block_templates filter called', [
			'template_type' => $template_type,
			'count' => count($query_result),
		]);
		return $query_result;
	}

	/**
	 * Fallback filter using template_include for compatibility.
	 * 
	 * @param string $template
	 * @return string
	 */
	public function filter_template_include($template) {
		$this->log('template_include filter called', [
			'template' => basename($template),
			'is_admin' => is_admin(),
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
			'path' => $block_template_file,
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

	/**
	 * Add admin menu item
	 */
	public function add_admin_menu() {
		add_theme_page(
			__('Template Routing', 'hpc-block-theme'),
			__('Template Routing', 'hpc-block-theme'),
			'edit_theme_options',
			self::ADMIN_PAGE_SLUG,
			[$this, 'render_admin_page']
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(self::OPTION_NAME, self::OPTION_NAME, [
			'type' => 'array',
			'sanitize_callback' => [$this, 'sanitize_mappings'],
		]);
	}

	/**
	 * Sanitize mappings before saving
	 * 
	 * @param array $mappings
	 * @return array
	 */
	public function sanitize_mappings($mappings) {
		if (!is_array($mappings)) {
			return [];
		}

		$sanitized = [];
		foreach ($mappings as $mapping) {
			if (empty($mapping['taxonomy']) || empty($mapping['template'])) {
				continue;
			}

			$sanitized[] = [
				'id' => sanitize_key($mapping['id'] ?? uniqid('mapping_')),
				'taxonomy' => sanitize_key($mapping['taxonomy']),
				'terms' => isset($mapping['terms']) ? array_map('sanitize_key', (array) $mapping['terms']) : 'any',
				'template' => sanitize_key($mapping['template']),
				'post_types' => isset($mapping['post_types']) ? array_map('sanitize_key', (array) $mapping['post_types']) : [],
				'priority' => intval($mapping['priority'] ?? 10),
				'enabled' => !empty($mapping['enabled']),
			];
		}

		return $sanitized;
	}

	/**
	 * Enqueue admin assets
	 * 
	 * @param string $hook
	 */
	public function enqueue_admin_assets($hook) {
		if ($hook !== 'appearance_page_' . self::ADMIN_PAGE_SLUG) {
			return;
		}

		wp_enqueue_style(
			'nxt-template-routing-admin',
			get_stylesheet_directory_uri() . '/assets/css/template-routing-admin.css',
			[],
			filemtime(get_stylesheet_directory() . '/assets/css/template-routing-admin.css')
		);

		wp_enqueue_script(
			'nxt-template-routing-admin',
			get_stylesheet_directory_uri() . '/assets/js/template-routing-admin.js',
			[],
			filemtime(get_stylesheet_directory() . '/assets/js/template-routing-admin.js'),
			true
		);

		wp_localize_script('nxt-template-routing-admin', 'nxtTemplateRouting', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('nxt_template_routing'),
			'mappings' => $this->get_mappings(),
			'taxonomies' => $this->get_available_taxonomies(),
			'templates' => $this->get_available_templates(),
			'i18n' => [
				'confirm_delete' => __('Are you sure you want to delete this mapping?', 'hpc-block-theme'),
				'save_success' => __('Mappings saved successfully.', 'hpc-block-theme'),
				'save_error' => __('Error saving mappings.', 'hpc-block-theme'),
				'any_term' => __('Any term', 'hpc-block-theme'),
				'select_taxonomy' => __('Select taxonomy...', 'hpc-block-theme'),
				'select_template' => __('Select template...', 'hpc-block-theme'),
				'select_terms' => __('Select terms (leave empty for any)...', 'hpc-block-theme'),
			],
		]);
	}

	/**
	 * AJAX: Save a mapping
	 */
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

	/**
	 * AJAX: Delete a mapping
	 */
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

	/**
	 * AJAX: Get terms for a taxonomy
	 */
	public function ajax_get_taxonomy_terms() {
		check_ajax_referer('nxt_template_routing', 'nonce');

		$taxonomy = sanitize_key($_GET['taxonomy'] ?? '');
		if (!$taxonomy || !taxonomy_exists($taxonomy)) {
			wp_send_json_error(['message' => 'Invalid taxonomy']);
		}

		wp_send_json_success(['terms' => $this->get_taxonomy_terms($taxonomy)]);
	}

	/**
	 * AJAX: Clear debug log
	 */
	public function ajax_clear_debug_log() {
		check_ajax_referer('nxt_template_routing', 'nonce');

		if (!current_user_can('edit_theme_options')) {
			wp_send_json_error(['message' => 'Unauthorized']);
		}

		$this->clear_debug_log();
		wp_send_json_success(['message' => 'Log cleared']);
	}

	/**
	 * Get templates that have database customizations (from Site Editor)
	 * 
	 * @return array
	 */
	public function get_templates_with_db_versions() {
		$result = [];
		$template_dir = get_stylesheet_directory() . '/templates/';

		// Get all templates from the database
		$db_templates = get_block_templates([], 'wp_template');

		foreach ($db_templates as $template) {
			// Only include templates that belong to this theme
			if (strpos($template->id, get_stylesheet() . '//') !== 0) {
				continue;
			}

			$slug = $template->slug;
			$file_path = $template_dir . $slug . '.html';
			$file_exists = file_exists($file_path);
			$file_content = $file_exists ? file_get_contents($file_path) : '';
			$db_content = $template->content;

			// Check if DB content differs from file content
			$is_modified = $file_exists ? (trim($db_content) !== trim($file_content)) : true;

			$result[$slug] = [
				'slug' => $slug,
				'title' => $template->title ?? $this->format_template_name($slug),
				'source' => $template->source,
				'id' => $template->id,
				'file_exists' => $file_exists,
				'file_path' => $file_path,
				'is_modified' => $is_modified,
				'db_content_length' => strlen($db_content),
				'file_content_length' => strlen($file_content),
			];
		}

		return $result;
	}

	/**
	 * Sync a template from database to file
	 * 
	 * @param string $template_slug
	 * @return array Result with success status and message
	 */
	public function sync_template_to_file($template_slug) {
		$template_id = get_stylesheet() . '//' . $template_slug;
		$template = get_block_template($template_id, 'wp_template');

		if (!$template) {
			return [
				'success' => false,
				'message' => 'Template not found in database: ' . $template_slug,
			];
		}

		$template_dir = get_stylesheet_directory() . '/templates/';
		
		// Ensure templates directory exists
		if (!is_dir($template_dir)) {
			if (!wp_mkdir_p($template_dir)) {
				return [
					'success' => false,
					'message' => 'Could not create templates directory',
				];
			}
		}

		$file_path = $template_dir . $template_slug . '.html';
		$content = $template->content;

		// Create a backup if file exists
		if (file_exists($file_path)) {
			$backup_path = $file_path . '.backup-' . date('Y-m-d-H-i-s');
			copy($file_path, $backup_path);
		}

		// Write the content
		$result = file_put_contents($file_path, $content);

		if ($result === false) {
			return [
				'success' => false,
				'message' => 'Failed to write file: ' . $file_path,
			];
		}

		$this->log('Template synced to file', [
			'template' => $template_slug,
			'file' => $file_path,
			'bytes' => $result,
		]);

		return [
			'success' => true,
			'message' => 'Template synced successfully',
			'file' => $file_path,
			'bytes' => $result,
		];
	}

	/**
	 * AJAX: Sync template from DB to file
	 */
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

	/**
	 * Render the admin page
	 */
	public function render_admin_page() {
		$debug_log = $this->get_debug_log();
		$templates = $this->get_available_templates();
		?>
		<div class="wrap nxt-template-routing-wrap">
			<h1><?php esc_html_e('Template Routing', 'hpc-block-theme'); ?></h1>
			<p class="description">
				<?php esc_html_e('Map taxonomies to templates. Pages with the specified taxonomy terms will automatically use the assigned template.', 'hpc-block-theme'); ?>
			</p>

			<div class="nxt-template-routing-container">
				<div class="nxt-mappings-header">
					<h2><?php esc_html_e('Taxonomy → Template Mappings', 'hpc-block-theme'); ?></h2>
					<button type="button" class="button button-primary" id="nxt-add-mapping">
						<?php esc_html_e('+ Add Mapping', 'hpc-block-theme'); ?>
					</button>
				</div>

				<div id="nxt-mappings-list" class="nxt-mappings-list">
					<!-- Mappings will be rendered here by JavaScript -->
				</div>

				<div class="nxt-mappings-footer">
					<button type="button" class="button button-primary button-hero" id="nxt-save-mappings">
						<?php esc_html_e('Save All Mappings', 'hpc-block-theme'); ?>
					</button>
					<span id="nxt-save-status" class="nxt-save-status"></span>
				</div>
			</div>

			<!-- Debug Section -->
			<?php if (self::DEBUG_MODE): ?>
			<div class="nxt-template-routing-debug" style="margin-top: 30px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
				<h2 style="margin-top: 0; display: flex; justify-content: space-between; align-items: center;">
					🔍 Debug Information
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
							$exists = file_exists($file_path);
						?>
						<tr>
							<td><code><?php echo esc_html($tpl['slug']); ?></code></td>
							<td><?php echo esc_html($tpl['title']); ?></td>
							<td><?php echo esc_html($tpl['source']); ?></td>
							<td><?php echo $exists ? '✅ Yes' : '❌ No (DB only)'; ?></td>
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

			<!-- Template Sync Section -->
			<div class="nxt-template-sync" style="margin-top: 30px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
				<h2 style="margin-top: 0;">📁 Sync Templates (DB → File)</h2>
				<p class="description" style="margin-bottom: 15px;">
					When you edit templates in the Site Editor, changes are saved to the database. 
					Use this section to export those changes back to your theme files (for version control, deployment, etc.).
				</p>

				<?php 
				$db_templates = $this->get_templates_with_db_versions();
				if (empty($db_templates)): 
				?>
					<p><em>No templates with database customizations found.</em></p>
				<?php else: ?>
					<table class="widefat">
						<thead>
							<tr>
								<th>Template</th>
								<th>Source</th>
								<th>File Status</th>
								<th>DB Size</th>
								<th>File Size</th>
								<th>Action</th>
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
									<?php 
									$source_label = $tpl['source'] === 'custom' ? '💾 Database' : '📄 Theme';
									echo $source_label;
									?>
								</td>
								<td>
									<?php if ($tpl['file_exists']): ?>
										<?php if ($tpl['is_modified']): ?>
											<span style="color: #dba617;">⚠️ Modified in DB</span>
										<?php else: ?>
											<span style="color: #00a32a;">✅ In Sync</span>
										<?php endif; ?>
									<?php else: ?>
										<span style="color: #d63638;">❌ No file</span>
									<?php endif; ?>
								</td>
								<td><?php echo number_format($tpl['db_content_length']); ?> bytes</td>
								<td><?php echo $tpl['file_exists'] ? number_format($tpl['file_content_length']) . ' bytes' : '—'; ?></td>
								<td>
									<button type="button" 
										class="button sync-template-btn" 
										data-slug="<?php echo esc_attr($tpl['slug']); ?>"
										<?php echo (!$tpl['is_modified'] && $tpl['file_exists']) ? 'disabled' : ''; ?>>
										<?php echo $tpl['file_exists'] ? 'Update File' : 'Create File'; ?>
									</button>
									<span class="sync-status" style="margin-left: 8px;"></span>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p style="margin-top: 15px;">
						<button type="button" class="button button-primary" id="nxt-sync-all-templates">
							Sync All Modified Templates
						</button>
						<span id="nxt-sync-all-status" style="margin-left: 10px;"></span>
					</p>
				<?php endif; ?>
			</div>

			<div class="nxt-template-routing-info">
				<h3><?php esc_html_e('How it works', 'hpc-block-theme'); ?></h3>
				<ul>
					<li><?php esc_html_e('Create a mapping between a taxonomy and a template.', 'hpc-block-theme'); ?></li>
					<li><?php esc_html_e('When a page/post has a term from that taxonomy, it will use the assigned template.', 'hpc-block-theme'); ?></li>
					<li><?php esc_html_e('You can restrict to specific terms or apply to any term in the taxonomy.', 'hpc-block-theme'); ?></li>
					<li><?php esc_html_e('Higher priority numbers win when multiple mappings match.', 'hpc-block-theme'); ?></li>
				</ul>

				<h3><?php esc_html_e('Creating Templates', 'hpc-block-theme'); ?></h3>
				<p>
					<?php 
					printf(
						esc_html__('Create new templates in %s or use the %s.', 'hpc-block-theme'),
						'<code>Appearance → Editor → Templates</code>',
						'<a href="' . esc_url(admin_url('site-editor.php?path=%2Fwp_template')) . '">' . esc_html__('Site Editor', 'hpc-block-theme') . '</a>'
					);
					?>
				</p>
			</div>
		</div>

		<script>
		document.getElementById('nxt-clear-debug-log')?.addEventListener('click', function() {
			if (!confirm('Clear the debug log?')) return;
			
			fetch(nxtTemplateRouting.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=nxt_clear_debug_log&nonce=' + nxtTemplateRouting.nonce
			})
			.then(r => r.json())
			.then(data => {
				if (data.success) {
					location.reload();
				}
			});
		});

		// Sync single template
		document.querySelectorAll('.sync-template-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				const slug = this.dataset.slug;
				const statusEl = this.parentNode.querySelector('.sync-status');
				
				this.disabled = true;
				this.textContent = 'Syncing...';
				statusEl.textContent = '';
				
				fetch(nxtTemplateRouting.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'action=nxt_sync_template_to_file&nonce=' + nxtTemplateRouting.nonce + '&template_slug=' + encodeURIComponent(slug)
				})
				.then(r => r.json())
				.then(data => {
					if (data.success) {
						statusEl.innerHTML = '<span style="color: #00a32a;">✅ Synced!</span>';
						this.textContent = 'Synced';
						// Update the status cell
						const row = this.closest('tr');
						const statusCell = row.querySelectorAll('td')[2];
						statusCell.innerHTML = '<span style="color: #00a32a;">✅ In Sync</span>';
					} else {
						statusEl.innerHTML = '<span style="color: #d63638;">❌ ' + (data.data?.message || 'Error') + '</span>';
						this.disabled = false;
						this.textContent = 'Retry';
					}
				})
				.catch(function() {
					statusEl.innerHTML = '<span style="color: #d63638;">❌ Network error</span>';
					btn.disabled = false;
					btn.textContent = 'Retry';
				});
			});
		});

		// Sync all modified templates
		document.getElementById('nxt-sync-all-templates')?.addEventListener('click', function() {
			const buttons = document.querySelectorAll('.sync-template-btn:not([disabled])');
			const statusEl = document.getElementById('nxt-sync-all-status');
			
			if (buttons.length === 0) {
				statusEl.innerHTML = '<span style="color: #646970;">No templates need syncing.</span>';
				return;
			}
			
			this.disabled = true;
			this.textContent = 'Syncing...';
			statusEl.textContent = 'Syncing ' + buttons.length + ' template(s)...';
			
			let completed = 0;
			let errors = 0;
			
			buttons.forEach(function(btn) {
				btn.click();
			});
			
			// Check completion after a delay (simple approach)
			setTimeout(function() {
				const remainingButtons = document.querySelectorAll('.sync-template-btn:not([disabled])');
				const syncedCount = buttons.length - remainingButtons.length;
				statusEl.innerHTML = '<span style="color: #00a32a;">✅ Synced ' + syncedCount + ' template(s)</span>';
				document.getElementById('nxt-sync-all-templates').disabled = false;
				document.getElementById('nxt-sync-all-templates').textContent = 'Sync All Modified Templates';
			}, 2000);
		});
		</script>
		<?php
	}
}

/**
 * Helper function to get the router instance.
 * 
 * @return NXT_Taxonomy_Template_Router
 */
function nxt_taxonomy_template_router() {
	return NXT_Taxonomy_Template_Router::get_instance();
}

// Initialize the router
nxt_taxonomy_template_router();
