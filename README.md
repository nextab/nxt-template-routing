# NXT Taxonomy Template Routing

Automatic template assignment based on taxonomy terms for WordPress Full Site Editing (FSE) block themes. Replaces Divi's Theme Builder "Assign to pages with taxonomy X" functionality in the native block editor.

## Features

- **Taxonomy → Template mappings**: Assign block templates to posts/pages based on taxonomy terms
- **Admin UI**: Configure mappings under **Appearance → Template Routing**
- **Term filtering**: Apply to any term in a taxonomy, or restrict to specific terms
- **Priority system**: When multiple mappings match, the highest priority wins
- **Post type restriction**: Limit mappings to specific post types (posts, pages, CPTs)
- **Polylang integration**: Automatic language-specific templates (`slug___de`, `slug___fr`, etc.) and optional per-language overrides
- **Synced Pattern Translation**: Fallback for Polylang when synced patterns (reusable blocks) fail to translate
- **Template sync**: Export database-edited templates back to theme files for version control

## Requirements

- WordPress 6.1+
- PHP 8.0+
- Block theme (FSE)

## Installation

1. Download or clone this repository
2. Place the `nxt-taxonomy-template-routing` folder in `wp-content/plugins/`
3. Activate the plugin in **Plugins → Installed Plugins**

## Usage

1. Go to **Appearance → Template Routing**
2. Click **+ Add Mapping**
3. Select a taxonomy (e.g. `category`, `post_tag`, or custom taxonomies)
4. Choose terms (or leave "Any term" for all terms in that taxonomy)
5. Select the template to use
6. Set priority (higher = wins when multiple mappings match)
7. Click **Save All Mappings**

When a singular post/page has a matching taxonomy term, the assigned template is used instead of the default.

### Creating Templates

Create templates in **Appearance → Editor → Templates** or via the Site Editor. Templates can be stored in the theme (`templates/*.html`) or in the database (custom templates).

## Polylang Integration

With [Polylang](https://polylang.pro/) active:

- The router looks for language-specific templates (e.g. `single-project___de` for German)
- Each mapping can define **Template per language** overrides for explicit control
- **Synced Pattern Translation Fallback**: Enable in plugin settings when Polylang fails to translate synced patterns (reusable blocks). The plugin intercepts `core/block` rendering and swaps the pattern ref with the translated version.

## Template Sync (DB → File)

Templates edited in the Site Editor are stored in the database. Use the **Sync Templates** section to export them back to your theme's `templates/` directory for version control and deployment. A backup is created before overwriting.

## Debug Mode

Set `NXT_Taxonomy_Template_Router::DEBUG_MODE` to `true` in `inc/class-taxonomy-template-router.php` to enable:

- HTML comment output on the frontend with routing info
- Activity log in the admin page
- `error_log` entries when `WP_DEBUG_LOG` is enabled

## File Structure

```
nxt-taxonomy-template-routing/
├── nxt-taxonomy-template-routing.php   # Main plugin file
├── inc/
│   ├── class-taxonomy-template-router.php
│   └── class-synced-pattern-translator.php
├── assets/
│   ├── css/template-routing-admin.css
│   └── js/template-routing-admin.js
└── README.md
```

## License

Proprietary. © [nexTab](https://nextab.de)
