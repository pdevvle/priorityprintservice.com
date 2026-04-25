<?php
/**
 * Plugin Name: Priority Print MCP Tools
 * Plugin URI:  https://woocommerce-70867-4915293.cloudwaysapps.com/
 * Description: Companion plugin for AI Engine that adds custom WooCommerce, theme, and plugin file management tools to the MCP server.
 * Version:     1.1.0
 * Author:      Preston / Priority Print Service
 * License:     GPL v2 or later
 * Requires PHP: 8.0
 *
 * INSTALLATION
 *  1. Save this file as /wp-content/plugins/priority-print-mcp/priority-print-mcp.php
 *  2. Activate "Priority Print MCP Tools" in WordPress admin → Plugins
 *  3. Verify tools appear in AI Engine → MCP Features (look for "Priority Print" category)
 *  4. Enable the tools you want exposed
 *
 * SAFETY MODEL
 *  - Theme writes are restricted to the priority-print theme directory only
 *  - Plugin writes are restricted to the wp-content/plugins directory only
 *  - Path traversal is blocked via realpath() validation on both
 *  - Each tool declares MCP annotations (readOnlyHint, destructiveHint) so Claude Code
 *    treats writes with appropriate caution
 *  - Auth is handled by AI Engine's MCP layer (bearer token); this plugin runs only
 *    after the request has been authenticated
 *  - No tools touch checkout, payment, cart, or user authentication
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Priority_Print_MCP {

	/** Theme slug we are allowed to read/write. */
	const ALLOWED_THEME_SLUG = 'priority-print';

	/** Plugins are scoped to WP_PLUGIN_DIR — resolved at runtime via safe_plugin_path(). */
	const PLUGIN_SCOPE = 'all';

	/** Tool name prefix used to route callbacks. */
	const PREFIX = 'pps_';

	public function __construct() {
		// AI Engine wires its filters during rest_api_init for MCP, so we follow the same pattern.
		add_action( 'rest_api_init', array( $this, 'register_filters' ) );
	}

	public function register_filters() {
		add_filter( 'mwai_mcp_tools',    array( $this, 'register_tools' ) );
		add_filter( 'mwai_mcp_callback', array( $this, 'handle_call' ), 10, 4 );
	}

	/* =========================================================================
	 * TOOL DEFINITIONS
	 * ========================================================================= */

	/**
	 * Tool registry, keyed by tool name. Mirrors AI Engine's mcp-core.php pattern.
	 */
	private function tools() {
		return array(

			/* ---------- WooCommerce: Products ---------- */

			self::PREFIX . 'woo_list_products' => array(
				'name'        => self::PREFIX . 'woo_list_products',
				'description' => 'List WooCommerce products. Returns id, name, slug, status, price, stock_status, and category_ids.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'category_slug' => array( 'type' => 'string',  'description' => 'Optional. Filter by product category slug.' ),
						'status'        => array( 'type' => 'string',  'description' => 'Optional. publish | draft | any. Defaults to publish.' ),
						'limit'         => array( 'type' => 'integer', 'description' => 'Optional. Max products to return (1-200, default 50).' ),
					),
				),
			),

			self::PREFIX . 'woo_get_product' => array(
				'name'        => self::PREFIX . 'woo_get_product',
				'description' => 'Get full details for a single WooCommerce product by ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer', 'description' => 'The WooCommerce product ID.' ),
					),
					'required' => array( 'product_id' ),
				),
			),

			self::PREFIX . 'woo_update_product' => array(
				'name'        => self::PREFIX . 'woo_update_product',
				'description' => 'WRITE OPERATION. Update fields on a WooCommerce product. Always confirm with the user before calling.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'        => array( 'type' => 'integer', 'description' => 'Product ID to update.' ),
						'name'              => array( 'type' => 'string',  'description' => 'Optional. New product name.' ),
						'description'       => array( 'type' => 'string',  'description' => 'Optional. Full description (HTML allowed, sanitized via wp_kses_post).' ),
						'short_description' => array( 'type' => 'string',  'description' => 'Optional. Short description.' ),
						'status'            => array( 'type' => 'string',  'description' => 'Optional. publish | draft | pending | private.' ),
						'regular_price'     => array( 'type' => 'string',  'description' => 'Optional. Regular price as string (e.g. "19.99").' ),
					),
					'required' => array( 'product_id' ),
				),
			),

			/* ---------- WooCommerce: Categories ---------- */

			self::PREFIX . 'woo_list_categories' => array(
				'name'        => self::PREFIX . 'woo_list_categories',
				'description' => 'List all WooCommerce product categories with id, name, slug, parent, count, and description.',
				// Empty input schema MUST use (object) [] not [] -- per AI Engine docs, [] breaks parsers.
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'woo_get_category' => array(
				'name'        => self::PREFIX . 'woo_get_category',
				'description' => 'Get details for a single WooCommerce product category by slug.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'slug' => array( 'type' => 'string', 'description' => 'The category slug.' ),
					),
					'required' => array( 'slug' ),
				),
			),

			/* ---------- WooCommerce: Orders ---------- */

			self::PREFIX . 'woo_list_orders' => array(
				'name'        => self::PREFIX . 'woo_list_orders',
				'description' => 'List recent WooCommerce orders. Returns id, status, total, customer name/email, and date.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string',  'description' => 'Optional. processing | on-hold | completed | pending | cancelled | refunded | failed | any. Defaults to processing.' ),
						'limit'  => array( 'type' => 'integer', 'description' => 'Optional. Max orders (1-100, default 25).' ),
					),
				),
			),

			self::PREFIX . 'woo_get_order' => array(
				'name'        => self::PREFIX . 'woo_get_order',
				'description' => 'Get full details for a single WooCommerce order including line items, billing, shipping, totals, and notes.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array( 'type' => 'integer', 'description' => 'The WooCommerce order ID.' ),
					),
					'required' => array( 'order_id' ),
				),
			),

			self::PREFIX . 'woo_update_order_status' => array(
				'name'        => self::PREFIX . 'woo_update_order_status',
				'description' => 'WRITE OPERATION. Change the status of a WooCommerce order. Confirm with the user before calling.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array( 'type' => 'integer', 'description' => 'Order ID.' ),
						'status'   => array( 'type' => 'string',  'description' => 'New status: processing | on-hold | completed | pending | cancelled | refunded | failed.' ),
						'note'     => array( 'type' => 'string',  'description' => 'Optional. Note to add with the status change.' ),
					),
					'required' => array( 'order_id', 'status' ),
				),
			),

			self::PREFIX . 'woo_add_order_note' => array(
				'name'        => self::PREFIX . 'woo_add_order_note',
				'description' => 'Add a note to a WooCommerce order. Notes can be internal (private) or customer-visible.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id'         => array( 'type' => 'integer', 'description' => 'Order ID.' ),
						'note'             => array( 'type' => 'string',  'description' => 'Note text.' ),
						'is_customer_note' => array( 'type' => 'boolean', 'description' => 'Optional. If true, customer is notified by email. Defaults to false.' ),
					),
					'required' => array( 'order_id', 'note' ),
				),
			),

			/* ---------- Theme File Management ---------- */

			self::PREFIX . 'theme_list_files' => array(
				'name'        => self::PREFIX . 'theme_list_files',
				'description' => 'List all files in the priority-print theme directory. Returns relative paths and last modified dates.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'subdirectory' => array( 'type' => 'string', 'description' => 'Optional. List files within a subdirectory of the theme.' ),
					),
				),
			),

			self::PREFIX . 'theme_read_file' => array(
				'name'        => self::PREFIX . 'theme_read_file',
				'description' => 'Read a file in the priority-print theme directory. Always use this before editing to see the current live version.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to theme root (e.g. "header.php" or "inc/seo-class.php").' ),
					),
					'required' => array( 'relative_path' ),
				),
			),

			self::PREFIX . 'theme_write_file' => array(
				'name'        => self::PREFIX . 'theme_write_file',
				'description' => 'WRITE OPERATION. Write contents to a file in the priority-print theme directory. ALWAYS read the file first, confirm with user, then write. Restricted to the priority-print theme only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to theme root.' ),
						'contents'      => array( 'type' => 'string', 'description' => 'Full file contents to write. OVERWRITES the existing file.' ),
					),
					'required' => array( 'relative_path', 'contents' ),
				),
			),

			/* ---------- Plugin File Management ---------- */

			self::PREFIX . 'plugin_list_files' => array(
				'name'        => self::PREFIX . 'plugin_list_files',
				'description' => 'List files inside a plugin directory under wp-content/plugins. Pass a plugin folder name to scope results, or omit to list all plugin directories.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_folder' => array( 'type' => 'string', 'description' => 'Optional. Plugin folder name (e.g. "pps-calculators"). Omit to list all top-level plugin directories.' ),
					),
				),
			),

			self::PREFIX . 'plugin_read_file' => array(
				'name'        => self::PREFIX . 'plugin_read_file',
				'description' => 'Read a file from any plugin directory under wp-content/plugins. Always use this before editing to see the current live version.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to wp-content/plugins (e.g. "pps-calculators/pps-calculators.php" or "pps-calculators/calc-booklet.html").' ),
					),
					'required' => array( 'relative_path' ),
				),
			),

			self::PREFIX . 'plugin_write_file' => array(
				'name'        => self::PREFIX . 'plugin_write_file',
				'description' => 'WRITE OPERATION. Write contents to a file in any plugin directory under wp-content/plugins. ALWAYS read the file first, confirm with user, then write. Creates the file if it does not exist. Creates subdirectories as needed.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to wp-content/plugins (e.g. "pps-calculators/calc-booklet.html").' ),
						'contents'      => array( 'type' => 'string', 'description' => 'Full file contents to write. OVERWRITES the existing file.' ),
					),
					'required' => array( 'relative_path', 'contents' ),
				),
			),
		);
	}

	/* =========================================================================
	 * REGISTRATION
	 * ========================================================================= */

	public function register_tools( array $prev ) : array {
		$tools = $this->tools();

		foreach ( $tools as &$tool ) {
			$tool['category'] = 'Priority Print';

			$name = $tool['name'];

			$is_readonly = (
				strpos( $name, self::PREFIX . 'woo_list_'  ) === 0 ||
				strpos( $name, self::PREFIX . 'woo_get_'   ) === 0 ||
				$name === self::PREFIX . 'theme_list_files' ||
				$name === self::PREFIX . 'theme_read_file'  ||
				$name === self::PREFIX . 'plugin_list_files' ||
				$name === self::PREFIX . 'plugin_read_file'
			);

			$is_destructive = (
				$name === self::PREFIX . 'theme_write_file'        ||
				$name === self::PREFIX . 'plugin_write_file'       ||
				$name === self::PREFIX . 'woo_update_product'      ||
				$name === self::PREFIX . 'woo_update_order_status'
			);

			$tool['annotations'] = array(
				'readOnlyHint'    => $is_readonly,
				'destructiveHint' => $is_destructive,
				'openWorldHint'   => false,
			);
		}
		unset( $tool );

		return array_merge( $prev, array_values( $tools ) );
	}

	/* =========================================================================
	 * CALLBACK
	 *
	 * AI Engine passes 5 args (the 5th is the MCP class instance) but we declared
	 * the filter for 4. The signature here matches the AI Engine core pattern.
	 *
	 * IMPORTANT: We must return a JSON-RPC envelope, not a plain array.
	 * Returning anything non-null short-circuits subsequent filters.
	 * ========================================================================= */

	public function handle_call( $prev, string $tool, array $args, ?int $id ) {

		// Another handler already responded, or this isn't a tool we own.
		if ( ! empty( $prev ) || ! isset( $this->tools()[ $tool ] ) ) {
			return $prev;
		}

		$response = array( 'jsonrpc' => '2.0', 'id' => $id );

		try {
			switch ( $tool ) {
				case self::PREFIX . 'woo_list_products':       $data = $this->woo_list_products( $args ); break;
				case self::PREFIX . 'woo_get_product':         $data = $this->woo_get_product( $args ); break;
				case self::PREFIX . 'woo_update_product':      $data = $this->woo_update_product( $args ); break;
				case self::PREFIX . 'woo_list_categories':     $data = $this->woo_list_categories(); break;
				case self::PREFIX . 'woo_get_category':        $data = $this->woo_get_category( $args ); break;
				case self::PREFIX . 'woo_list_orders':         $data = $this->woo_list_orders( $args ); break;
				case self::PREFIX . 'woo_get_order':           $data = $this->woo_get_order( $args ); break;
				case self::PREFIX . 'woo_update_order_status': $data = $this->woo_update_order_status( $args ); break;
				case self::PREFIX . 'woo_add_order_note':      $data = $this->woo_add_order_note( $args ); break;
				case self::PREFIX . 'theme_list_files':        $data = $this->theme_list_files( $args ); break;
				case self::PREFIX . 'theme_read_file':         $data = $this->theme_read_file( $args ); break;
				case self::PREFIX . 'theme_write_file':        $data = $this->theme_write_file( $args ); break;
				case self::PREFIX . 'plugin_list_files':       $data = $this->plugin_list_files( $args ); break;
				case self::PREFIX . 'plugin_read_file':        $data = $this->plugin_read_file( $args ); break;
				case self::PREFIX . 'plugin_write_file':       $data = $this->plugin_write_file( $args ); break;
				default:
					return $prev; // Defensive — shouldn't reach here.
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

	/* =========================================================================
	 * WOOCOMMERCE: PRODUCTS
	 * ========================================================================= */

	private function require_woo() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			throw new Exception( 'WooCommerce is not active.' );
		}
	}

	private function woo_list_products( array $args ) : array {
		$this->require_woo();

		$limit  = isset( $args['limit'] )  ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'publish';

		$query = array(
			'limit'  => $limit,
			'status' => $status,
		);

		if ( ! empty( $args['category_slug'] ) ) {
			$query['category'] = array( sanitize_title( $args['category_slug'] ) );
		}

		$products = wc_get_products( $query );
		$rows     = array();

		foreach ( $products as $product ) {
			$rows[] = array(
				'id'           => $product->get_id(),
				'name'         => $product->get_name(),
				'slug'         => $product->get_slug(),
				'status'       => $product->get_status(),
				'price'        => $product->get_price(),
				'stock_status' => $product->get_stock_status(),
				'category_ids' => $product->get_category_ids(),
				'permalink'    => get_permalink( $product->get_id() ),
			);
		}

		return array( 'count' => count( $rows ), 'products' => $rows );
	}

	private function woo_get_product( array $args ) : array {
		$this->require_woo();

		if ( empty( $args['product_id'] ) ) {
			throw new Exception( 'product_id is required.' );
		}

		$product = wc_get_product( (int) $args['product_id'] );
		if ( ! $product ) {
			throw new Exception( 'Product not found.' );
		}

		return array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'status'            => $product->get_status(),
			'type'              => $product->get_type(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'sku'               => $product->get_sku(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'price'             => $product->get_price(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'weight'            => $product->get_weight(),
			'dimensions'        => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			),
			'category_ids'      => $product->get_category_ids(),
			'tag_ids'           => $product->get_tag_ids(),
			'image_id'          => $product->get_image_id(),
			'gallery_image_ids' => $product->get_gallery_image_ids(),
			'permalink'         => get_permalink( $product->get_id() ),
			'meta_data'         => $this->flatten_meta( $product->get_meta_data() ),
		);
	}

	private function woo_update_product( array $args ) : array {
		$this->require_woo();

		if ( empty( $args['product_id'] ) ) {
			throw new Exception( 'product_id is required.' );
		}

		$product = wc_get_product( (int) $args['product_id'] );
		if ( ! $product ) {
			throw new Exception( 'Product not found.' );
		}

		$changed = array();

		if ( isset( $args['name'] ) ) {
			$product->set_name( sanitize_text_field( $args['name'] ) );
			$changed[] = 'name';
		}
		if ( isset( $args['description'] ) ) {
			$product->set_description( wp_kses_post( $args['description'] ) );
			$changed[] = 'description';
		}
		if ( isset( $args['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $args['short_description'] ) );
			$changed[] = 'short_description';
		}
		if ( isset( $args['status'] ) ) {
			$product->set_status( sanitize_text_field( $args['status'] ) );
			$changed[] = 'status';
		}
		if ( isset( $args['regular_price'] ) ) {
			$product->set_regular_price( sanitize_text_field( $args['regular_price'] ) );
			$changed[] = 'regular_price';
		}

		$product->save();

		return array(
			'success'         => true,
			'product_id'      => $product->get_id(),
			'fields_updated'  => $changed,
		);
	}

	/* =========================================================================
	 * WOOCOMMERCE: CATEGORIES
	 * ========================================================================= */

	private function woo_list_categories() : array {
		$this->require_woo();

		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) ) {
			throw new Exception( $terms->get_error_message() );
		}

		$rows = array();
		foreach ( $terms as $term ) {
			$rows[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent'      => $term->parent,
				'count'       => $term->count,
				'description' => $term->description,
			);
		}

		return array( 'count' => count( $rows ), 'categories' => $rows );
	}

	private function woo_get_category( array $args ) : array {
		$this->require_woo();

		if ( empty( $args['slug'] ) ) {
			throw new Exception( 'slug is required.' );
		}

		$term = get_term_by( 'slug', sanitize_title( $args['slug'] ), 'product_cat' );
		if ( ! $term ) {
			throw new Exception( 'Category not found.' );
		}

		$link = get_term_link( $term );

		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'parent'      => $term->parent,
			'count'       => $term->count,
			'description' => $term->description,
			'permalink'   => is_wp_error( $link ) ? null : $link,
		);
	}

	/* =========================================================================
	 * WOOCOMMERCE: ORDERS
	 * ========================================================================= */

	private function woo_list_orders( array $args ) : array {
		$this->require_woo();

		$limit  = isset( $args['limit'] )  ? max( 1, min( 100, (int) $args['limit'] ) ) : 25;
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'processing';

		$orders = wc_get_orders( array(
			'limit'  => $limit,
			'status' => $status,
		) );

		$rows = array();
		foreach ( $orders as $order ) {
			$rows[] = array(
				'id'             => $order->get_id(),
				'status'         => $order->get_status(),
				'total'          => $order->get_total(),
				'currency'       => $order->get_currency(),
				'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'customer_email' => $order->get_billing_email(),
				'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			);
		}

		return array( 'count' => count( $rows ), 'orders' => $rows );
	}

	private function woo_get_order( array $args ) : array {
		$this->require_woo();

		if ( empty( $args['order_id'] ) ) {
			throw new Exception( 'order_id is required.' );
		}

		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) {
			throw new Exception( 'Order not found.' );
		}

		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$line_items[] = array(
				'product_id' => $item->get_product_id(),
				'name'       => $item->get_name(),
				'quantity'   => $item->get_quantity(),
				'subtotal'   => $item->get_subtotal(),
				'total'      => $item->get_total(),
			);
		}

		$notes = array();
		if ( function_exists( 'wc_get_order_notes' ) ) {
			foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
				$notes[] = array(
					'id'            => $note->id,
					'date_created'  => $note->date_created ? $note->date_created->date( 'c' ) : null,
					'content'       => $note->content,
					'customer_note' => (bool) $note->customer_note,
					'added_by'      => $note->added_by,
				);
			}
		}

		return array(
			'id'             => $order->get_id(),
			'status'         => $order->get_status(),
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'billing'        => $order->get_address( 'billing' ),
			'shipping'       => $order->get_address( 'shipping' ),
			'payment_method' => $order->get_payment_method_title(),
			'line_items'     => $line_items,
			'notes'          => $notes,
		);
	}

	private function woo_update_order_status( array $args ) : array {
		$this->require_woo();

		if ( empty( $args['order_id'] ) || empty( $args['status'] ) ) {
			throw new Exception( 'order_id and status are required.' );
		}

		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) {
			throw new Exception( 'Order not found.' );
		}

		$status = sanitize_text_field( $args['status'] );
		$note   = isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '';

		$order->update_status( $status, $note );

		return array(
			'success'  => true,
			'order_id' => $order->get_id(),
			'status'   => $order->get_status(),
		);
	}

	private function woo_add_order_note( array $args ) : array {
		$this->require_woo();

		if ( empty( $args['order_id'] ) || empty( $args['note'] ) ) {
			throw new Exception( 'order_id and note are required.' );
		}

		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) {
			throw new Exception( 'Order not found.' );
		}

		$is_customer_note = ! empty( $args['is_customer_note'] ) ? 1 : 0;
		// add_order_note signature: ( $note, $is_customer_note = 0, $added_by_user = false )
		$note_id = $order->add_order_note(
			sanitize_textarea_field( $args['note'] ),
			$is_customer_note,
			false
		);

		return array(
			'success' => (bool) $note_id,
			'note_id' => $note_id,
		);
	}

	/* =========================================================================
	 * THEME FILE MANAGEMENT
	 * ========================================================================= */

	/**
	 * Resolve a theme-relative path and verify it stays inside the allowed theme.
	 * Throws if the resolved path escapes the theme directory.
	 */
	private function safe_theme_path( string $relative_path ) : string {
		$theme_root = trailingslashit( get_theme_root() ) . self::ALLOWED_THEME_SLUG;
		$real_root  = realpath( $theme_root );

		if ( ! $real_root ) {
			throw new Exception( 'Theme directory not found: ' . self::ALLOWED_THEME_SLUG );
		}

		$relative_path = ltrim( $relative_path, '/\\' );

		// Reject obvious traversal attempts before touching the filesystem.
		if ( strpos( $relative_path, '..' ) !== false ) {
			throw new Exception( 'Path traversal not allowed.' );
		}

		$full_path = $real_root . DIRECTORY_SEPARATOR . $relative_path;

		// Validate using realpath of either the file itself (read) or its parent (write of new file).
		$check = file_exists( $full_path ) ? realpath( $full_path ) : realpath( dirname( $full_path ) );
		if ( ! $check || strpos( $check, $real_root ) !== 0 ) {
			throw new Exception( 'Path is outside the allowed theme directory.' );
		}

		return $full_path;
	}

	private function theme_list_files( array $args ) : array {
		$theme_root = trailingslashit( get_theme_root() ) . self::ALLOWED_THEME_SLUG;
		$real_root  = realpath( $theme_root );

		if ( ! $real_root ) {
			throw new Exception( 'Theme directory not found: ' . self::ALLOWED_THEME_SLUG );
		}

		$start_dir = $real_root;
		if ( ! empty( $args['subdirectory'] ) ) {
			$start_dir = $this->safe_theme_path( $args['subdirectory'] );
			if ( ! is_dir( $start_dir ) ) {
				throw new Exception( 'Subdirectory not found.' );
			}
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $start_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$rel = str_replace( $real_root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
				$files[] = array(
					'path'          => str_replace( '\\', '/', $rel ),
					'size_bytes'    => $file->getSize(),
					'last_modified' => date( 'c', $file->getMTime() ),
				);
			}
		}

		return array( 'count' => count( $files ), 'files' => $files );
	}

	private function theme_read_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) ) {
			throw new Exception( 'relative_path is required.' );
		}

		$full_path = $this->safe_theme_path( $args['relative_path'] );

		if ( ! file_exists( $full_path ) ) {
			throw new Exception( 'File not found: ' . $args['relative_path'] );
		}

		$contents = file_get_contents( $full_path );
		if ( $contents === false ) {
			throw new Exception( 'Could not read file. Check permissions.' );
		}

		return array(
			'relative_path' => $args['relative_path'],
			'size_bytes'    => filesize( $full_path ),
			'last_modified' => date( 'c', filemtime( $full_path ) ),
			'contents'      => $contents,
		);
	}

	private function theme_write_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) || ! isset( $args['contents'] ) ) {
			throw new Exception( 'relative_path and contents are required.' );
		}

		$full_path = $this->safe_theme_path( $args['relative_path'] );

		// Ensure parent directory exists (still inside theme dir thanks to safe_theme_path).
		$dir = dirname( $full_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$bytes = file_put_contents( $full_path, $args['contents'] );
		if ( $bytes === false ) {
			throw new Exception( 'Could not write file. Check filesystem permissions.' );
		}

		return array(
			'success'       => true,
			'relative_path' => $args['relative_path'],
			'bytes_written' => $bytes,
		);
	}

	/* =========================================================================
	 * PLUGIN FILE MANAGEMENT
	 * ========================================================================= */

	/**
	 * Resolve a plugins-relative path and verify it stays inside WP_PLUGIN_DIR.
	 * Throws if the resolved path escapes the plugins directory.
	 */
	private function safe_plugin_path( string $relative_path ) : string {
		$plugins_root = realpath( WP_PLUGIN_DIR );

		if ( ! $plugins_root ) {
			throw new Exception( 'Could not resolve plugins directory.' );
		}

		$relative_path = ltrim( $relative_path, '/\\' );

		if ( strpos( $relative_path, '..' ) !== false ) {
			throw new Exception( 'Path traversal not allowed.' );
		}

		$full_path = $plugins_root . DIRECTORY_SEPARATOR . $relative_path;

		$check = file_exists( $full_path ) ? realpath( $full_path ) : realpath( dirname( $full_path ) );
		if ( ! $check || strpos( $check, $plugins_root ) !== 0 ) {
			throw new Exception( 'Path is outside the plugins directory.' );
		}

		return $full_path;
	}

	private function plugin_list_files( array $args ) : array {
		$plugins_root = realpath( WP_PLUGIN_DIR );

		if ( ! $plugins_root ) {
			throw new Exception( 'Could not resolve plugins directory.' );
		}

		// If no plugin_folder given, list top-level plugin directories only.
		if ( empty( $args['plugin_folder'] ) ) {
			$dirs = glob( $plugins_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
			$out  = array();
			foreach ( $dirs as $dir ) {
				$out[] = basename( $dir );
			}
			return array( 'plugin_folders' => $out, 'count' => count( $out ) );
		}

		$start_dir = $this->safe_plugin_path( sanitize_text_field( $args['plugin_folder'] ) );

		if ( ! is_dir( $start_dir ) ) {
			throw new Exception( 'Plugin folder not found: ' . $args['plugin_folder'] );
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $start_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$rel = str_replace( $plugins_root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
				$files[] = array(
					'path'          => str_replace( '\\', '/', $rel ),
					'size_bytes'    => $file->getSize(),
					'last_modified' => date( 'c', $file->getMTime() ),
				);
			}
		}

		return array( 'count' => count( $files ), 'files' => $files );
	}

	private function plugin_read_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) ) {
			throw new Exception( 'relative_path is required.' );
		}

		$full_path = $this->safe_plugin_path( $args['relative_path'] );

		if ( ! file_exists( $full_path ) ) {
			throw new Exception( 'File not found: ' . $args['relative_path'] );
		}

		$contents = file_get_contents( $full_path );
		if ( $contents === false ) {
			throw new Exception( 'Could not read file. Check permissions.' );
		}

		return array(
			'relative_path' => $args['relative_path'],
			'size_bytes'    => filesize( $full_path ),
			'last_modified' => date( 'c', filemtime( $full_path ) ),
			'contents'      => $contents,
		);
	}

	private function plugin_write_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) || ! isset( $args['contents'] ) ) {
			throw new Exception( 'relative_path and contents are required.' );
		}

		$full_path = $this->safe_plugin_path( $args['relative_path'] );

		$dir = dirname( $full_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$bytes = file_put_contents( $full_path, $args['contents'] );
		if ( $bytes === false ) {
			throw new Exception( 'Could not write file. Check filesystem permissions.' );
		}

		return array(
			'success'       => true,
			'relative_path' => $args['relative_path'],
			'bytes_written' => $bytes,
		);
	}

	/* =========================================================================
	 * HELPERS
	 * ========================================================================= */

	/**
	 * Convert WC meta_data objects to a flat associative array.
	 */
	private function flatten_meta( $meta_data ) : array {
		$out = array();
		foreach ( $meta_data as $meta ) {
			$data = $meta->get_data();
			$out[ $data['key'] ] = $data['value'];
		}
		return $out;
	}
}

new Priority_Print_MCP();
