<?php
/**
 * Plugin Name: Priority Print MCP — Styling Tools
 * Plugin URI:  https://woocommerce-70867-4915293.cloudwaysapps.com/
 * Description: Companion plugin for AI Engine that adds styling/design MCP tools (Customizer Additional CSS, theme mods, Astra settings). Additive to priority-print-mcp via the same mwai_mcp_* filters; does not modify that plugin.
 * Version:     1.0.0
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
 *  - Writes are scoped and go through WordPress's own sanitising APIs:
 *      * Custom CSS  -> wp_update_custom_css_post() (strips tags, revisions it)
 *      * Theme mods  -> set_theme_mod() / the theme_mods_<theme> option
 *      * Astra       -> read-modify-write of a SINGLE key in astra-settings, so
 *                       a change never clobbers the rest of the array.
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
		return array(

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
				'description' => 'Set a single theme mod by key for a theme (defaults to the active theme). Value may be a string, number, boolean, or array.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key'        => array( 'type' => 'string', 'description' => 'Theme mod key.' ),
						'value'      => array( 'description' => 'New value (any JSON type).' ),
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
				'description' => 'Set a single key in the Astra theme settings array via safe read-modify-write (other keys are preserved). Value may be a string, number, boolean, or array (e.g. typography settings).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'key'   => array( 'type' => 'string', 'description' => 'astra-settings key to set.' ),
						'value' => array( 'description' => 'New value (any JSON type).' ),
					),
					'required'   => array( 'key', 'value' ),
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
		);
		$destructive = array(
			self::PREFIX . 'set_custom_css',
			self::PREFIX . 'set_theme_mod',
			self::PREFIX . 'set_astra_setting',
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
				case self::PREFIX . 'get_custom_css':    $data = $this->get_custom_css( $args ); break;
				case self::PREFIX . 'set_custom_css':    $data = $this->set_custom_css( $args ); break;
				case self::PREFIX . 'get_theme_mods':    $data = $this->get_theme_mods( $args ); break;
				case self::PREFIX . 'set_theme_mod':     $data = $this->set_theme_mod( $args ); break;
				case self::PREFIX . 'get_astra_setting': $data = $this->get_astra_setting( $args ); break;
				case self::PREFIX . 'set_astra_setting': $data = $this->set_astra_setting( $args ); break;
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
	// Handlers
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
}

new Priority_Print_MCP_Style();
