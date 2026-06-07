<?php
/**
 * Plugin Name: Priority Print MCP — Styling Tools
 * Plugin URI:  https://woocommerce-70867-4915293.cloudwaysapps.com/
 * Description: Companion plugin for AI Engine that adds styling/design + element-control MCP tools (Customizer CSS, theme mods, Astra settings, nav menus, sidebars, reusable blocks, site identity). Additive to priority-print-mcp via the same mwai_mcp_* filters; does not modify that plugin.
 * Version:     1.1.0
 * Author:      Priority Print Service
 * License:     GPL v2 or later
 * Requires PHP: 8.0
 *
 * SAFETY MODEL
 *  - Additive only: hooks mwai_mcp_tools / mwai_mcp_callback inside rest_api_init
 *    and cooperatively passes through other plugins' tools (returns $prev
 *    unchanged whenever the requested tool isn't one of ours). Mirrors the
 *    priority-print-mcp companion plugin's pattern exactly.
 *  - Auth is handled upstream by AI Engine's MCP layer (bearer token), the same
 *    model priority-print-mcp relies on — there is intentionally no separate
 *    auth/capability layer here so behaviour matches the sibling plugin.
 *  - Writes go through WordPress's own sanitising/validating APIs:
 *      * Custom CSS   -> wp_update_custom_css_post() (strips tags, revisions it)
 *      * Theme mods   -> set_theme_mod() / the theme_mods_<theme> option
 *      * Astra        -> single-key read-modify-write of astra-settings
 *      * Nav menus    -> wp_create_nav_menu / wp_update_nav_menu_item / core
 *      * Reusable blk -> wp_insert_post / wp_update_post on the wp_block CPT
 *      * Site ident.  -> blogname/blogdescription options, custom_logo theme mod
 *  - Widgets/sidebars are READ-ONLY here: block-widget storage is serialized
 *    block markup that is unsafe to mutate blindly, so no widget write tool.
 *  - Each tool declares MCP annotations (readOnlyHint / destructiveHint).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Priority_Print_MCP_Style {

	const PREFIX = 'pps_';

	/** Cap on a single CSS payload to avoid runaway writes. */
	const MAX_CSS_BYTES = 262144; // 256 KB

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_filters' ) );
	}

	public function register_filters() {
		add_filter( 'mwai_mcp_tools', array( $this, 'register_tools' ) );
		add_filter( 'mwai_mcp_callback', array( $this, 'handle_call' ), 10, 4 );
	}

	/**
	 * Tool definitions, keyed by tool name (matches priority-print-mcp's shape).
	 */
	private function tools() : array {
		$any = array( 'description' => 'Value of any JSON type (string, number, boolean, array, object).' );

		return array(

			// ── Styling ──────────────────────────────────────────────────
			self::PREFIX . 'get_custom_css' => array(
				'name'        => self::PREFIX . 'get_custom_css',
				'description' => 'Read the Customizer "Additional CSS" for a theme (defaults to the active theme). Theme-agnostic.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array( 'type' => 'string', 'description' => 'Optional theme folder slug. Defaults to the active theme.' ),
					),
				),
			),

			self::PREFIX . 'set_custom_css' => array(
				'name'        => self::PREFIX . 'set_custom_css',
				'description' => 'Set the Customizer "Additional CSS" for a theme (defaults to the active theme). Saved via WordPress wp_update_custom_css_post(). Use append=true to add to existing CSS instead of replacing it.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'css'        => array( 'type' => 'string', 'description' => 'The CSS to save.' ),
						'append'     => array( 'type' => 'boolean', 'description' => 'If true, append to existing CSS; otherwise replace. Default false.' ),
						'stylesheet' => array( 'type' => 'string', 'description' => 'Optional theme folder slug. Defaults to the active theme.' ),
					),
					'required'   => array( 'css' ),
				),
			),

			self::PREFIX . 'get_theme_mods' => array(
				'name'        => self::PREFIX . 'get_theme_mods',
				'description' => 'Get all theme mods for a theme (defaults to the active theme).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array( 'type' => 'string', 'description' => 'Optional theme folder slug. Defaults to the active theme.' ),
					),
				),
			),

			self::PREFIX . 'set_theme_mod' => array(
				'name'        => self::PREFIX . 'set_theme_mod',
				'description' => 'Set a single theme mod by key for a theme (defaults to the active theme).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key'        => array( 'type' => 'string', 'description' => 'Theme mod key.' ),
						'value'      => $any,
						'stylesheet' => array( 'type' => 'string', 'description' => 'Optional theme folder slug. Defaults to the active theme.' ),
					),
					'required'   => array( 'key', 'value' ),
				),
			),

			self::PREFIX . 'get_astra_setting' => array(
				'name'        => self::PREFIX . 'get_astra_setting',
				'description' => 'Read the Astra theme settings array. Pass key to read one setting; omit to return all of astra-settings.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key' => array( 'type' => 'string', 'description' => 'Optional astra-settings key. Omit to return the whole array.' ),
					),
				),
			),

			self::PREFIX . 'set_astra_setting' => array(
				'name'        => self::PREFIX . 'set_astra_setting',
				'description' => 'Set a single key in the Astra theme settings array via safe read-modify-write (other keys preserved).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key'   => array( 'type' => 'string', 'description' => 'astra-settings key to set.' ),
						'value' => $any,
					),
					'required'   => array( 'key', 'value' ),
				),
			),

			// ── Navigation menus ─────────────────────────────────────────
			self::PREFIX . 'list_nav_menus' => array(
				'name'        => self::PREFIX . 'list_nav_menus',
				'description' => 'List all navigation menus (term_id, name, slug, item count, assigned theme locations) plus the theme\'s registered menu locations.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'get_nav_menu' => array(
				'name'        => self::PREFIX . 'get_nav_menu',
				'description' => 'Get the items of one navigation menu (by id, slug, or name).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'menu' => array( 'description' => 'Menu id (int), slug, or name.' ),
					),
					'required'   => array( 'menu' ),
				),
			),

			self::PREFIX . 'create_nav_menu' => array(
				'name'        => self::PREFIX . 'create_nav_menu',
				'description' => 'Create a new (empty) navigation menu.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array( 'type' => 'string', 'description' => 'Menu name.' ),
					),
					'required'   => array( 'name' ),
				),
			),

			self::PREFIX . 'assign_menu_location' => array(
				'name'        => self::PREFIX . 'assign_menu_location',
				'description' => 'Assign a menu to a registered theme location (or unassign by passing menu=0).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'location' => array( 'type' => 'string', 'description' => 'Registered theme location slug (see list_nav_menus).' ),
						'menu'     => array( 'description' => 'Menu id/slug/name to assign, or 0 to unassign.' ),
					),
					'required'   => array( 'location', 'menu' ),
				),
			),

			self::PREFIX . 'save_menu_item' => array(
				'name'        => self::PREFIX . 'save_menu_item',
				'description' => 'Create or update a menu item. Omit menu_item_id to create. For a custom link pass title+url; to link a page/post pass object (e.g. "page") + object_id (and item_type, default post_type).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'menu'         => array( 'description' => 'Target menu id/slug/name.' ),
						'menu_item_id' => array( 'type' => 'integer', 'description' => 'Existing item id to update; omit/0 to create.' ),
						'title'        => array( 'type' => 'string', 'description' => 'Item label.' ),
						'url'          => array( 'type' => 'string', 'description' => 'URL for a custom-link item.' ),
						'object'       => array( 'type' => 'string', 'description' => 'Linked object type, e.g. "page", "post", "category".' ),
						'object_id'    => array( 'type' => 'integer', 'description' => 'Linked object id (post/term id).' ),
						'item_type'    => array( 'type' => 'string', 'description' => 'menu-item-type: post_type (default), taxonomy, or custom.' ),
						'parent'       => array( 'type' => 'integer', 'description' => 'Parent menu item id (for nesting).' ),
						'position'     => array( 'type' => 'integer', 'description' => 'Order position within the menu.' ),
					),
					'required'   => array( 'menu' ),
				),
			),

			self::PREFIX . 'delete_menu_item' => array(
				'name'        => self::PREFIX . 'delete_menu_item',
				'description' => 'Delete a navigation menu item by id.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'menu_item_id' => array( 'type' => 'integer', 'description' => 'Menu item id to delete.' ),
					),
					'required'   => array( 'menu_item_id' ),
				),
			),

			// ── Widgets / sidebars (read-only) ───────────────────────────
			self::PREFIX . 'list_sidebars' => array(
				'name'        => self::PREFIX . 'list_sidebars',
				'description' => 'List registered sidebars/widget areas and the widget ids currently in each. Read-only.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'get_sidebars_widgets' => array(
				'name'        => self::PREFIX . 'get_sidebars_widgets',
				'description' => 'Return the raw sidebars-to-widgets assignment map (wp_get_sidebars_widgets). Read-only.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			// ── Reusable blocks / synced patterns ────────────────────────
			self::PREFIX . 'list_reusable_blocks' => array(
				'name'        => self::PREFIX . 'list_reusable_blocks',
				'description' => 'List reusable blocks / synced patterns (wp_block posts): id, title, slug, status, modified.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'description' => 'Max results (default 100).' ),
					),
				),
			),

			self::PREFIX . 'get_reusable_block' => array(
				'name'        => self::PREFIX . 'get_reusable_block',
				'description' => 'Get one reusable block (wp_block) including its block-markup content.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => 'wp_block post id.' ),
					),
					'required'   => array( 'id' ),
				),
			),

			self::PREFIX . 'save_reusable_block' => array(
				'name'        => self::PREFIX . 'save_reusable_block',
				'description' => 'Create or update a reusable block (wp_block). Omit id to create (title required). content is block markup.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'description' => 'Existing wp_block id to update; omit to create.' ),
						'title'   => array( 'type' => 'string', 'description' => 'Block title.' ),
						'content' => array( 'type' => 'string', 'description' => 'Block markup (Gutenberg serialized HTML).' ),
					),
				),
			),

			self::PREFIX . 'delete_reusable_block' => array(
				'name'        => self::PREFIX . 'delete_reusable_block',
				'description' => 'Delete a reusable block (wp_block). Pass force=true to bypass trash.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer', 'description' => 'wp_block post id.' ),
						'force' => array( 'type' => 'boolean', 'description' => 'Permanently delete instead of trashing. Default false.' ),
					),
					'required'   => array( 'id' ),
				),
			),

			// ── Site identity ────────────────────────────────────────────
			self::PREFIX . 'get_site_identity' => array(
				'name'        => self::PREFIX . 'get_site_identity',
				'description' => 'Get site title, tagline, custom logo, and site icon (favicon).',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'set_site_identity' => array(
				'name'        => self::PREFIX . 'set_site_identity',
				'description' => 'Set any of: title, tagline, custom_logo (attachment id, 0 to clear), site_icon (attachment id, 0 to clear).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'       => array( 'type' => 'string', 'description' => 'Site title (blogname).' ),
						'tagline'     => array( 'type' => 'string', 'description' => 'Tagline (blogdescription).' ),
						'custom_logo' => array( 'type' => 'integer', 'description' => 'Logo attachment id; 0 to clear.' ),
						'site_icon'   => array( 'type' => 'integer', 'description' => 'Site icon attachment id; 0 to clear.' ),
					),
				),
			),

		);
	}

	/**
	 * Contribute our tool definitions to the MCP tool list.
	 */
	public function register_tools( array $prev ) : array {
		$readonly = array(
			self::PREFIX . 'get_custom_css',
			self::PREFIX . 'get_theme_mods',
			self::PREFIX . 'get_astra_setting',
			self::PREFIX . 'list_nav_menus',
			self::PREFIX . 'get_nav_menu',
			self::PREFIX . 'list_sidebars',
			self::PREFIX . 'get_sidebars_widgets',
			self::PREFIX . 'list_reusable_blocks',
			self::PREFIX . 'get_reusable_block',
			self::PREFIX . 'get_site_identity',
		);
		$destructive = array(
			self::PREFIX . 'set_custom_css',
			self::PREFIX . 'set_theme_mod',
			self::PREFIX . 'set_astra_setting',
			self::PREFIX . 'assign_menu_location',
			self::PREFIX . 'save_menu_item',
			self::PREFIX . 'delete_menu_item',
			self::PREFIX . 'save_reusable_block',
			self::PREFIX . 'delete_reusable_block',
			self::PREFIX . 'set_site_identity',
		);

		$tools = $this->tools();
		foreach ( $tools as &$tool ) {
			$tool['category']    = 'Priority Print';
			$tool['annotations'] = array(
				'readOnlyHint'    => in_array( $tool['name'], $readonly, true ),
				'destructiveHint' => in_array( $tool['name'], $destructive, true ),
				'openWorldHint'   => false,
			);
		}
		unset( $tool );

		return array_merge( $prev, array_values( $tools ) );
	}

	/**
	 * Execute a call if it is one of ours; otherwise pass through unchanged.
	 */
	public function handle_call( $prev, string $tool, array $args, ?int $id ) {
		if ( ! empty( $prev ) || ! isset( $this->tools()[ $tool ] ) ) {
			return $prev;
		}

		$response = array( 'jsonrpc' => '2.0', 'id' => $id );

		try {
			switch ( $tool ) {
				case self::PREFIX . 'get_custom_css':        $data = $this->get_custom_css( $args ); break;
				case self::PREFIX . 'set_custom_css':        $data = $this->set_custom_css( $args ); break;
				case self::PREFIX . 'get_theme_mods':        $data = $this->get_theme_mods( $args ); break;
				case self::PREFIX . 'set_theme_mod':         $data = $this->set_theme_mod( $args ); break;
				case self::PREFIX . 'get_astra_setting':     $data = $this->get_astra_setting( $args ); break;
				case self::PREFIX . 'set_astra_setting':     $data = $this->set_astra_setting( $args ); break;
				case self::PREFIX . 'list_nav_menus':        $data = $this->list_nav_menus( $args ); break;
				case self::PREFIX . 'get_nav_menu':          $data = $this->get_nav_menu( $args ); break;
				case self::PREFIX . 'create_nav_menu':       $data = $this->create_nav_menu( $args ); break;
				case self::PREFIX . 'assign_menu_location':  $data = $this->assign_menu_location( $args ); break;
				case self::PREFIX . 'save_menu_item':        $data = $this->save_menu_item( $args ); break;
				case self::PREFIX . 'delete_menu_item':      $data = $this->delete_menu_item( $args ); break;
				case self::PREFIX . 'list_sidebars':         $data = $this->list_sidebars( $args ); break;
				case self::PREFIX . 'get_sidebars_widgets':  $data = $this->get_sidebars_widgets( $args ); break;
				case self::PREFIX . 'list_reusable_blocks':  $data = $this->list_reusable_blocks( $args ); break;
				case self::PREFIX . 'get_reusable_block':    $data = $this->get_reusable_block( $args ); break;
				case self::PREFIX . 'save_reusable_block':   $data = $this->save_reusable_block( $args ); break;
				case self::PREFIX . 'delete_reusable_block': $data = $this->delete_reusable_block( $args ); break;
				case self::PREFIX . 'get_site_identity':     $data = $this->get_site_identity( $args ); break;
				case self::PREFIX . 'set_site_identity':     $data = $this->set_site_identity( $args ); break;
				default:
					return $prev;
			}

			$response['result'] = array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
					),
				),
			);
		} catch ( Exception $e ) {
			$response['error'] = array(
				'code'    => -32603,
				'message' => $e->getMessage(),
			);
		}

		return $response;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Handlers: styling
	// ─────────────────────────────────────────────────────────────────────

	private function resolve_stylesheet( array $args ) : string {
		if ( ! empty( $args['stylesheet'] ) && is_string( $args['stylesheet'] ) ) {
			return sanitize_text_field( $args['stylesheet'] );
		}
		return get_stylesheet();
	}

	private function get_custom_css( array $args ) : array {
		$stylesheet = $this->resolve_stylesheet( $args );
		$css        = wp_get_custom_css( $stylesheet );
		return array(
			'stylesheet' => $stylesheet,
			'length'     => strlen( $css ),
			'css'        => $css,
		);
	}

	private function set_custom_css( array $args ) : array {
		if ( ! isset( $args['css'] ) || ! is_string( $args['css'] ) ) {
			throw new Exception( 'css (string) is required.' );
		}
		$stylesheet = $this->resolve_stylesheet( $args );
		$css        = $args['css'];
		$append     = ! empty( $args['append'] );

		if ( $append ) {
			$existing = wp_get_custom_css( $stylesheet );
			$css      = ( $existing !== '' ) ? $existing . "\n" . $css : $css;
		}

		if ( strlen( $css ) > self::MAX_CSS_BYTES ) {
			throw new Exception( 'CSS exceeds the ' . self::MAX_CSS_BYTES . '-byte limit.' );
		}

		$result = wp_update_custom_css_post( $css, array( 'stylesheet' => $stylesheet ) );
		if ( is_wp_error( $result ) ) {
			throw new Exception( 'Failed to save custom CSS: ' . $result->get_error_message() );
		}

		return array(
			'stylesheet' => $stylesheet,
			'mode'       => $append ? 'append' : 'replace',
			'post_id'    => $result->ID,
			'length'     => strlen( $css ),
			'saved'      => true,
			'note'       => 'If a page/object cache (WP Rocket, Object Cache Pro) or Astra "Load CSS as file" is active, purge it to see the change on the front end.',
		);
	}

	private function get_theme_mods( array $args ) : array {
		$stylesheet = $this->resolve_stylesheet( $args );

		if ( $stylesheet === get_stylesheet() ) {
			$mods = get_theme_mods();
		} else {
			$mods = get_option( 'theme_mods_' . $stylesheet, array() );
		}
		if ( ! is_array( $mods ) ) {
			$mods = array();
		}

		return array(
			'stylesheet' => $stylesheet,
			'theme_mods' => $mods,
			'count'      => count( $mods ),
		);
	}

	private function set_theme_mod( array $args ) : array {
		if ( empty( $args['key'] ) || ! is_string( $args['key'] ) ) {
			throw new Exception( 'key (string) is required.' );
		}
		if ( ! array_key_exists( 'value', $args ) ) {
			throw new Exception( 'value is required.' );
		}
		$key        = sanitize_text_field( $args['key'] );
		$stylesheet = $this->resolve_stylesheet( $args );

		if ( $stylesheet === get_stylesheet() ) {
			set_theme_mod( $key, $args['value'] );
		} else {
			$mods = get_option( 'theme_mods_' . $stylesheet, array() );
			if ( ! is_array( $mods ) ) {
				$mods = array();
			}
			$mods[ $key ] = $args['value'];
			update_option( 'theme_mods_' . $stylesheet, $mods );
		}

		return array(
			'stylesheet' => $stylesheet,
			'key'        => $key,
			'value'      => $args['value'],
			'saved'      => true,
		);
	}

	private function get_astra_setting( array $args ) : array {
		$settings = get_option( 'astra-settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( ! empty( $args['key'] ) && is_string( $args['key'] ) ) {
			$key = sanitize_text_field( $args['key'] );
			return array(
				'key'    => $key,
				'value'  => $settings[ $key ] ?? null,
				'exists' => array_key_exists( $key, $settings ),
			);
		}

		return array(
			'astra_settings' => $settings,
			'count'          => count( $settings ),
		);
	}

	private function set_astra_setting( array $args ) : array {
		if ( empty( $args['key'] ) || ! is_string( $args['key'] ) ) {
			throw new Exception( 'key (string) is required.' );
		}
		if ( ! array_key_exists( 'value', $args ) ) {
			throw new Exception( 'value is required.' );
		}
		$key      = sanitize_text_field( $args['key'] );
		$settings = get_option( 'astra-settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$old              = $settings[ $key ] ?? null;
		$settings[ $key ] = $args['value'];
		update_option( 'astra-settings', $settings );

		return array(
			'key'       => $key,
			'old_value' => $old,
			'new_value' => $args['value'],
			'saved'     => true,
			'note'      => 'Astra may cache generated CSS; if the change is not visible, purge caches (WP Rocket / Object Cache Pro) or re-save in the Customizer.',
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Handlers: navigation menus
	// ─────────────────────────────────────────────────────────────────────

	private function list_nav_menus( array $args ) : array {
		$menus     = wp_get_nav_menus();
		$locations = get_nav_menu_locations();
		$by_menu   = array();
		foreach ( $locations as $loc => $tid ) {
			$by_menu[ (int) $tid ][] = $loc;
		}
		$out = array();
		foreach ( $menus as $m ) {
			$out[] = array(
				'term_id'   => (int) $m->term_id,
				'name'      => $m->name,
				'slug'      => $m->slug,
				'count'     => (int) $m->count,
				'locations' => $by_menu[ (int) $m->term_id ] ?? array(),
			);
		}
		return array(
			'menus'                => $out,
			'registered_locations' => get_registered_nav_menus(),
		);
	}

	private function get_nav_menu( array $args ) : array {
		if ( ! isset( $args['menu'] ) || $args['menu'] === '' ) {
			throw new Exception( 'menu (id, slug, or name) is required.' );
		}
		$menu = wp_get_nav_menu_object( $args['menu'] );
		if ( ! $menu ) {
			throw new Exception( 'Menu not found: ' . wp_json_encode( $args['menu'] ) );
		}
		$items = wp_get_nav_menu_items( $menu->term_id );
		$out   = array();
		if ( is_array( $items ) ) {
			foreach ( $items as $it ) {
				$out[] = array(
					'id'        => (int) $it->ID,
					'title'     => $it->title,
					'url'       => $it->url,
					'parent'    => (int) $it->menu_item_parent,
					'order'     => (int) $it->menu_order,
					'type'      => $it->type,
					'object'    => $it->object,
					'object_id' => (int) $it->object_id,
				);
			}
		}
		return array(
			'term_id' => (int) $menu->term_id,
			'name'    => $menu->name,
			'items'   => $out,
		);
	}

	private function create_nav_menu( array $args ) : array {
		if ( empty( $args['name'] ) || ! is_string( $args['name'] ) ) {
			throw new Exception( 'name is required.' );
		}
		$id = wp_create_nav_menu( sanitize_text_field( $args['name'] ) );
		if ( is_wp_error( $id ) ) {
			throw new Exception( 'Failed to create menu: ' . $id->get_error_message() );
		}
		return array( 'term_id' => (int) $id, 'name' => $args['name'], 'created' => true );
	}

	private function assign_menu_location( array $args ) : array {
		if ( empty( $args['location'] ) || ! is_string( $args['location'] ) ) {
			throw new Exception( 'location is required.' );
		}
		$location   = sanitize_key( $args['location'] );
		$registered = get_registered_nav_menus();
		if ( ! isset( $registered[ $location ] ) ) {
			throw new Exception( 'Unknown menu location: ' . $location );
		}
		$locations = get_nav_menu_locations();

		$menu_arg = $args['menu'] ?? 0;
		if ( $menu_arg === 0 || $menu_arg === '0' || $menu_arg === '' || $menu_arg === null ) {
			unset( $locations[ $location ] );
			$menu_id = 0;
		} else {
			$menu = wp_get_nav_menu_object( $menu_arg );
			if ( ! $menu ) {
				throw new Exception( 'Menu not found: ' . wp_json_encode( $menu_arg ) );
			}
			$locations[ $location ] = (int) $menu->term_id;
			$menu_id                = (int) $menu->term_id;
		}
		set_theme_mod( 'nav_menu_locations', $locations );

		return array( 'location' => $location, 'menu_id' => $menu_id, 'saved' => true );
	}

	private function save_menu_item( array $args ) : array {
		if ( ! isset( $args['menu'] ) || $args['menu'] === '' ) {
			throw new Exception( 'menu is required.' );
		}
		$menu = wp_get_nav_menu_object( $args['menu'] );
		if ( ! $menu ) {
			throw new Exception( 'Menu not found: ' . wp_json_encode( $args['menu'] ) );
		}
		$item_id = isset( $args['menu_item_id'] ) ? (int) $args['menu_item_id'] : 0;

		$item_args = array( 'menu-item-status' => 'publish' );

		if ( isset( $args['title'] ) ) {
			$item_args['menu-item-title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['url'] ) && $args['url'] !== '' ) {
			$item_args['menu-item-url']  = esc_url_raw( $args['url'] );
			$item_args['menu-item-type'] = 'custom';
		}
		if ( isset( $args['object'] ) && isset( $args['object_id'] ) ) {
			$item_args['menu-item-type']      = isset( $args['item_type'] ) ? sanitize_key( $args['item_type'] ) : 'post_type';
			$item_args['menu-item-object']    = sanitize_key( $args['object'] );
			$item_args['menu-item-object-id'] = (int) $args['object_id'];
		}
		if ( isset( $args['parent'] ) ) {
			$item_args['menu-item-parent-id'] = (int) $args['parent'];
		}
		if ( isset( $args['position'] ) ) {
			$item_args['menu-item-position'] = (int) $args['position'];
		}

		$result = wp_update_nav_menu_item( $menu->term_id, $item_id, $item_args );
		if ( is_wp_error( $result ) ) {
			throw new Exception( 'Failed to save menu item: ' . $result->get_error_message() );
		}

		return array(
			'menu_id'      => (int) $menu->term_id,
			'menu_item_id' => (int) $result,
			'created'      => ( $item_id === 0 ),
			'saved'        => true,
		);
	}

	private function delete_menu_item( array $args ) : array {
		if ( empty( $args['menu_item_id'] ) ) {
			throw new Exception( 'menu_item_id is required.' );
		}
		$id = (int) $args['menu_item_id'];
		if ( ! is_nav_menu_item( $id ) ) {
			throw new Exception( 'Not a navigation menu item: ' . $id );
		}
		if ( ! wp_delete_post( $id, true ) ) {
			throw new Exception( 'Failed to delete menu item ' . $id );
		}
		return array( 'menu_item_id' => $id, 'deleted' => true );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Handlers: widgets / sidebars (read-only)
	// ─────────────────────────────────────────────────────────────────────

	private function list_sidebars( array $args ) : array {
		global $wp_registered_sidebars;
		$assignments = wp_get_sidebars_widgets();
		$out         = array();
		if ( is_array( $wp_registered_sidebars ) ) {
			foreach ( $wp_registered_sidebars as $id => $sb ) {
				$out[] = array(
					'id'      => $id,
					'name'    => $sb['name'] ?? $id,
					'widgets' => $assignments[ $id ] ?? array(),
				);
			}
		}
		return array(
			'sidebars'         => $out,
			'inactive_widgets' => $assignments['wp_inactive_widgets'] ?? array(),
		);
	}

	private function get_sidebars_widgets( array $args ) : array {
		return array( 'sidebars_widgets' => wp_get_sidebars_widgets() );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Handlers: reusable blocks / synced patterns (wp_block CPT)
	// ─────────────────────────────────────────────────────────────────────

	private function list_reusable_blocks( array $args ) : array {
		$limit = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;
		$posts = get_posts( array(
			'post_type'   => 'wp_block',
			'post_status' => array( 'publish', 'draft', 'private' ),
			'numberposts' => $limit,
			'orderby'     => 'modified',
			'order'       => 'DESC',
		) );
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'       => (int) $p->ID,
				'title'    => $p->post_title,
				'slug'     => $p->post_name,
				'status'   => $p->post_status,
				'modified' => $p->post_modified_gmt,
			);
		}
		return array( 'reusable_blocks' => $out, 'count' => count( $out ) );
	}

	private function get_reusable_block( array $args ) : array {
		if ( empty( $args['id'] ) ) {
			throw new Exception( 'id is required.' );
		}
		$p = get_post( (int) $args['id'] );
		if ( ! $p || $p->post_type !== 'wp_block' ) {
			throw new Exception( 'Reusable block not found: ' . (int) $args['id'] );
		}
		return array(
			'id'      => (int) $p->ID,
			'title'   => $p->post_title,
			'slug'    => $p->post_name,
			'status'  => $p->post_status,
			'content' => $p->post_content,
		);
	}

	private function save_reusable_block( array $args ) : array {
		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$data = array( 'post_type' => 'wp_block', 'post_status' => 'publish' );

		if ( isset( $args['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			if ( ! is_string( $args['content'] ) ) {
				throw new Exception( 'content must be a string of block markup.' );
			}
			$data['post_content'] = $args['content'];
		}

		if ( $id > 0 ) {
			$existing = get_post( $id );
			if ( ! $existing || $existing->post_type !== 'wp_block' ) {
				throw new Exception( 'Reusable block not found: ' . $id );
			}
			$data['ID'] = $id;
			$res        = wp_update_post( $data, true );
		} else {
			if ( empty( $data['post_title'] ) ) {
				throw new Exception( 'title is required when creating a reusable block.' );
			}
			$res = wp_insert_post( $data, true );
		}
		if ( is_wp_error( $res ) ) {
			throw new Exception( 'Failed to save reusable block: ' . $res->get_error_message() );
		}

		return array( 'id' => (int) $res, 'created' => ( $id === 0 ), 'saved' => true );
	}

	private function delete_reusable_block( array $args ) : array {
		if ( empty( $args['id'] ) ) {
			throw new Exception( 'id is required.' );
		}
		$id = (int) $args['id'];
		$p  = get_post( $id );
		if ( ! $p || $p->post_type !== 'wp_block' ) {
			throw new Exception( 'Reusable block not found: ' . $id );
		}
		$force = ! empty( $args['force'] );
		if ( ! wp_delete_post( $id, $force ) ) {
			throw new Exception( 'Failed to delete reusable block ' . $id );
		}
		return array( 'id' => $id, 'deleted' => true, 'forced' => $force );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Handlers: site identity
	// ─────────────────────────────────────────────────────────────────────

	private function get_site_identity( array $args ) : array {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$icon_id = (int) get_option( 'site_icon' );
		return array(
			'title'       => get_option( 'blogname' ),
			'tagline'     => get_option( 'blogdescription' ),
			'custom_logo' => array(
				'id'  => $logo_id,
				'url' => $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '',
			),
			'site_icon'   => array(
				'id'  => $icon_id,
				'url' => $icon_id ? wp_get_attachment_image_url( $icon_id, 'full' ) : '',
			),
		);
	}

	private function set_site_identity( array $args ) : array {
		$changed = array();

		if ( isset( $args['title'] ) ) {
			update_option( 'blogname', sanitize_text_field( $args['title'] ) );
			$changed[] = 'title';
		}
		if ( isset( $args['tagline'] ) ) {
			update_option( 'blogdescription', sanitize_text_field( $args['tagline'] ) );
			$changed[] = 'tagline';
		}
		if ( array_key_exists( 'custom_logo', $args ) ) {
			$lid = (int) $args['custom_logo'];
			if ( $lid > 0 ) {
				set_theme_mod( 'custom_logo', $lid );
			} else {
				remove_theme_mod( 'custom_logo' );
			}
			$changed[] = 'custom_logo';
		}
		if ( array_key_exists( 'site_icon', $args ) ) {
			$sid = (int) $args['site_icon'];
			if ( $sid > 0 ) {
				update_option( 'site_icon', $sid );
			} else {
				delete_option( 'site_icon' );
			}
			$changed[] = 'site_icon';
		}

		if ( empty( $changed ) ) {
			throw new Exception( 'Nothing to set. Provide title, tagline, custom_logo, or site_icon.' );
		}
		return array( 'changed' => $changed, 'saved' => true );
	}
}

new Priority_Print_MCP_Style();
