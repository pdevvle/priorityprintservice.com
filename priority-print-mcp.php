<?php
/**
 * Plugin Name: Priority Print MCP Tools
 * Plugin URI:  https://woocommerce-70867-4915293.cloudwaysapps.com/
 * Description: Companion plugin for AI Engine that adds custom WooCommerce, theme, plugin file management, uploads cleanup, WordPress update, and URL-download tools to the MCP server.
 * Version:     1.9.0
 * Author:      Preston / Priority Print Service
 * License:     GPL v2 or later
 * Requires PHP: 8.0
 *
 * SAFETY MODEL
 *  - Theme writes are restricted to the priority-print theme directory only
 *  - Plugin writes (including downloads and deletes) are restricted to the wp-content/plugins directory only
 *  - Path traversal is blocked via realpath() validation
 *  - plugin_download_url enforces https:// only, 12MB max, 60s timeout
 *  - plugin_delete_file removes a single file only: refuses directories, this plugin's own file, and any active plugin's entry file
 *  - Uploads tools are restricted to wp_upload_dir()['basedir'] only, with a stricter containment
 *    test than the plugin/theme helpers (exact match or trailing separator, so a prefix-sharing
 *    sibling like wp-content/uploads-old cannot pass)
 *  - uploads_delete_file / uploads_delete_batch take explicit paths only -- never a pattern or
 *    glob -- so a bad filter cannot cascade into an over-delete. Both refuse directories, the
 *    directory-guard files (index.php / index.html / .htaccess / web.config) that keep the uploads
 *    tree unbrowsable, and any file still backing a media-library attachment (that would orphan the
 *    attachment row; use the media tools instead). Batch is capped at 500 paths and reports each
 *    per-file failure rather than aborting
 *  - uploads_list_files reports matched_count / matched_total_bytes for the FULL match set even when
 *    the returned list is limited, so a cap is never mistaken for the whole picture
 *  - Uploads retention (the daily WP-Cron age-based cleanup) ships OFF, with dry_run ON and no
 *    directory, so it is inert until deliberately configured. It refuses the uploads root (a blank
 *    or "/" directory cannot cascade), floors min_age_days at RETENTION_MIN_AGE_FLOOR so a typo
 *    cannot mean "2 days", caps deletes per run, reuses the same per-file guards as the manual
 *    delete tools, and logs every run (counts, bytes, sample paths, skip reasons) to
 *    pps_uploads_retention_log. uploads_retention_run_now defaults to a dry run even when the
 *    stored policy is live -- deleting from a manual call requires an explicit dry_run=false
 *  - Behaviour-carrying options: pps_uploads_retention (policy) and pps_uploads_retention_log
 *    (history). Both are documented in docs/GO_LIVE_RUNBOOK.md per the CLAUDE.md server-patch rule
 *  - Every write / download / delete calls opcache_invalidate() on the affected file, so new
 *    bytecode takes effect on the next request even when OPcache runs with validate_timestamps=0
 *    (the Cloudways default) -- without this a deployed .php sits on disk while stale bytecode runs
 *  - Each tool declares MCP annotations (readOnlyHint, destructiveHint)
 *  - Update tools wrap WordPress's own Plugin_Upgrader / Theme_Upgrader / Core_Upgrader
 *  - Auth is handled by AI Engine's MCP layer (bearer token)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Priority_Print_MCP {

	const ALLOWED_THEME_SLUG = 'priority-print';
	const PLUGIN_SCOPE       = 'all';
	const PREFIX             = 'pps_';

	// Uploads retention (automatic age-based cleanup of a single uploads subdirectory)
	const RETENTION_OPTION = 'pps_uploads_retention';
	const RETENTION_LOG    = 'pps_uploads_retention_log';
	const RETENTION_CRON   = 'pps_uploads_retention_run';
	const RETENTION_MIN_AGE_FLOOR = 30;   // refuse to treat anything younger than this as expired

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_filters' ) );

		// Retention runs from WP-Cron, which never reaches rest_api_init, so these
		// two hooks are registered at load time rather than in register_filters().
		add_action( 'init', array( $this, 'maybe_schedule_retention' ) );
		add_action( self::RETENTION_CRON, array( $this, 'retention_cron_run' ) );
	}

	public function register_filters() {
		add_filter( 'mwai_mcp_tools',    array( $this, 'register_tools' ) );
		add_filter( 'mwai_mcp_callback', array( $this, 'handle_call' ), 10, 4 );
	}

	private function tools() {
		return array(

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

			self::PREFIX . 'woo_list_categories' => array(
				'name'        => self::PREFIX . 'woo_list_categories',
				'description' => 'List all WooCommerce product categories with id, name, slug, parent, count, and description.',
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

			self::PREFIX . 'woo_get_order_meta' => array(
				'name'        => self::PREFIX . 'woo_get_order_meta',
				'description' => 'Read-only: all meta on a WooCommerce order (HPOS wp_wc_orders_meta included) plus per-line-item meta. Built to verify PPS data (PPS-Spec, _pps_artwork_*, _pps_summary) on orders, which woo_get_order and wp_get_post_meta cannot reach. Optionally filter by key prefix.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id'   => array( 'type' => 'integer', 'description' => 'The WooCommerce order ID.' ),
						'key_prefix' => array( 'type' => 'string', 'description' => 'Optional. Only return meta whose key starts with this (e.g. "_pps" or "PPS").' ),
					),
					'required' => array( 'order_id' ),
				),
			),

			self::PREFIX . 'woo_update_order_status' => array(
				'name'        => self::PREFIX . 'woo_update_order_status',
				'description' => 'WRITE OPERATION. Change the status of a WooCommerce order.',
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
						'is_customer_note' => array( 'type' => 'boolean', 'description' => 'Optional. If true, customer is notified by email.' ),
					),
					'required' => array( 'order_id', 'note' ),
				),
			),

			self::PREFIX . 'theme_list_files' => array(
				'name'        => self::PREFIX . 'theme_list_files',
				'description' => 'List all files in the priority-print theme directory.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'subdirectory' => array( 'type' => 'string', 'description' => 'Optional. List files within a subdirectory.' ),
					),
				),
			),

			self::PREFIX . 'theme_read_file' => array(
				'name'        => self::PREFIX . 'theme_read_file',
				'description' => 'Read a file in the priority-print theme directory.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to theme root.' ),
					),
					'required' => array( 'relative_path' ),
				),
			),

			self::PREFIX . 'theme_write_file' => array(
				'name'        => self::PREFIX . 'theme_write_file',
				'description' => 'WRITE OPERATION. Write contents to a file in the priority-print theme directory.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to theme root.' ),
						'contents'      => array( 'type' => 'string', 'description' => 'Full file contents to write.' ),
					),
					'required' => array( 'relative_path', 'contents' ),
				),
			),

			self::PREFIX . 'plugin_list_files' => array(
				'name'        => self::PREFIX . 'plugin_list_files',
				'description' => 'List files inside a plugin directory under wp-content/plugins.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_folder' => array( 'type' => 'string', 'description' => 'Optional. Plugin folder name. Omit to list all top-level plugin directories.' ),
					),
				),
			),

			self::PREFIX . 'plugin_read_file' => array(
				'name'        => self::PREFIX . 'plugin_read_file',
				'description' => 'Read a file from any plugin directory under wp-content/plugins.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to wp-content/plugins.' ),
					),
					'required' => array( 'relative_path' ),
				),
			),

			self::PREFIX . 'plugin_write_file' => array(
				'name'        => self::PREFIX . 'plugin_write_file',
				'description' => 'WRITE OPERATION. Write contents to a file in any plugin directory under wp-content/plugins. Creates the file if it does not exist.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to wp-content/plugins.' ),
						'contents'      => array( 'type' => 'string', 'description' => 'Full file contents to write.' ),
					),
					'required' => array( 'relative_path', 'contents' ),
				),
			),

			self::PREFIX . 'plugin_download_url' => array(
				'name'        => self::PREFIX . 'plugin_download_url',
				'description' => 'WRITE OPERATION. Server-side download a file from an https URL and save it to a path inside wp-content/plugins. Useful for large files that exceed direct-write payload limits. Constraints: https only, 12MB cap, 60s timeout. Returns bytes_written.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'           => array( 'type' => 'string', 'description' => 'Public https:// URL to fetch from.' ),
						'relative_path' => array( 'type' => 'string', 'description' => 'Target path relative to wp-content/plugins/ (e.g. "pps-calculators/calc-brochure.html").' ),
					),
					'required' => array( 'url', 'relative_path' ),
				),
			),

			self::PREFIX . 'plugin_delete_file' => array(
				'name'        => self::PREFIX . 'plugin_delete_file',
				'description' => 'WRITE OPERATION. Permanently delete a single file under wp-content/plugins (irreversible). Refuses directories, path traversal, this MCP plugin\'s own file, and the entry file of any active plugin. Returns bytes_deleted. Confirm with the user before calling.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to wp-content/plugins of the single file to delete.' ),
					),
					'required' => array( 'relative_path' ),
				),
			),

			self::PREFIX . 'uploads_list_files' => array(
				'name'        => self::PREFIX . 'uploads_list_files',
				'description' => 'List files under wp-content/uploads, with optional age and size filters. Built for finding old, large customer artwork that has no media-library attachment row and so is invisible to the media tools. Returns path / size / mtime / age_days per file, plus the total size of the whole matched set so you can see what a cleanup would reclaim before deleting anything.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'subdirectory' => array( 'type' => 'string',  'description' => 'Optional. Scope the scan to this path relative to the uploads root (e.g. "pps-artwork"). Omit to scan the whole uploads tree.' ),
						'min_age_days' => array( 'type' => 'integer', 'description' => 'Optional. Only return files whose mtime is at least this many days old.' ),
						'min_size_kb'  => array( 'type' => 'integer', 'description' => 'Optional. Only return files of at least this many KB.' ),
						'order_by'     => array( 'type' => 'string',  'description' => 'Optional. "size" (default) or "age". Both descending — largest, or oldest, first.' ),
						'limit'        => array( 'type' => 'integer', 'description' => 'Optional. Max files to return; default 200, max 2000. matched_count and matched_total_bytes always describe the full match, so a limit is never a silent cap.' ),
					),
				),
			),

			self::PREFIX . 'uploads_delete_file' => array(
				'name'        => self::PREFIX . 'uploads_delete_file',
				'description' => 'WRITE OPERATION. Permanently delete a single file under wp-content/uploads (irreversible). Refuses directories, path traversal, directory-guard files (index.php / index.html / .htaccess / web.config), and any file still attached to a media-library item. Returns bytes_deleted. Confirm with the user before calling.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to the uploads root of the single file to delete.' ),
					),
					'required' => array( 'relative_path' ),
				),
			),

			self::PREFIX . 'uploads_delete_batch' => array(
				'name'        => self::PREFIX . 'uploads_delete_batch',
				'description' => 'WRITE OPERATION. Permanently delete many files under wp-content/uploads in one call (irreversible). Takes an explicit list of paths only — never a pattern or glob — and applies the same per-file guards as uploads_delete_file. A file that fails its guard is reported and skipped rather than aborting the batch. Max 500 paths per call. Returns per-file results plus total bytes_deleted. Confirm with the user before calling.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_paths' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Explicit list of paths relative to the uploads root. Max 500.',
						),
					),
					'required' => array( 'relative_paths' ),
				),
			),

			self::PREFIX . 'uploads_retention_get' => array(
				'name'        => self::PREFIX . 'uploads_retention_get',
				'description' => 'Read the automatic uploads-retention policy (the daily cron that deletes aged files from one configured uploads subdirectory), plus the next scheduled run and the last run\'s log.',
				'inputSchema' => array( 'type' => 'object', 'properties' => array() ),
			),

			self::PREFIX . 'uploads_retention_set' => array(
				'name'        => self::PREFIX . 'uploads_retention_set',
				'description' => 'WRITE OPERATION. Configure the automatic uploads-retention policy. Starts disabled with dry_run on and no directory, and does nothing until a directory is set and enabled is true. Setting enabled schedules/unschedules the daily cron. min_age_days is floored at 30 and the directory may not be the uploads root.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'enabled'             => array( 'type' => 'boolean', 'description' => 'Turn the daily cron on or off.' ),
						'dry_run'             => array( 'type' => 'boolean', 'description' => 'When true (the default) the job reports what it would delete and deletes nothing.' ),
						'directory'           => array( 'type' => 'string',  'description' => 'Target path relative to the uploads root, e.g. "wcpa_uploads". Must exist and may not be the uploads root itself.' ),
						'min_age_days'        => array( 'type' => 'integer', 'description' => 'Delete files older than this. Default 730 (2 years); minimum 30.' ),
						'max_deletes_per_run' => array( 'type' => 'integer', 'description' => 'Per-run delete cap, default 500, max 5000.' ),
					),
				),
			),

			self::PREFIX . 'uploads_retention_run_now' => array(
				'name'        => self::PREFIX . 'uploads_retention_run_now',
				'description' => 'WRITE OPERATION. Run the retention policy immediately instead of waiting for cron. Defaults to a DRY RUN regardless of the stored setting — pass dry_run=false to actually delete. Returns counts, bytes, sample paths and skip reasons.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'dry_run' => array( 'type' => 'boolean', 'description' => 'Defaults to true. Pass false to delete for real.' ),
					),
				),
			),

			self::PREFIX . 'wp_check_updates' => array(
				'name'        => self::PREFIX . 'wp_check_updates',
				'description' => 'Refresh and return available core, plugin, and theme updates. Read-only.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'wp_get_plugin_versions' => array(
				'name'        => self::PREFIX . 'wp_get_plugin_versions',
				'description' => 'List all installed plugins with current version, active status, and upgrade-target version.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'wp_update_plugin' => array(
				'name'        => self::PREFIX . 'wp_update_plugin',
				'description' => 'WRITE OPERATION. Update a single plugin via WordPress Plugin_Upgrader.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array( 'type' => 'string', 'description' => 'Plugin file (e.g. "woocommerce/woocommerce.php").' ),
					),
					'required' => array( 'plugin' ),
				),
			),

			self::PREFIX . 'wp_update_theme' => array(
				'name'        => self::PREFIX . 'wp_update_theme',
				'description' => 'WRITE OPERATION. Update a single theme via WordPress Theme_Upgrader.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array( 'type' => 'string', 'description' => 'Theme stylesheet folder name.' ),
					),
					'required' => array( 'stylesheet' ),
				),
			),

			self::PREFIX . 'wp_update_core' => array(
				'name'        => self::PREFIX . 'wp_update_core',
				'description' => 'WRITE OPERATION. Update WordPress core via Core_Upgrader.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),
		);
	}

	public function register_tools( array $prev ) : array {
		$tools = $this->tools();

		foreach ( $tools as &$tool ) {
			$tool['category'] = 'Priority Print';

			$name = $tool['name'];

			$is_readonly = (
				strpos( $name, self::PREFIX . 'woo_list_'  ) === 0 ||
				strpos( $name, self::PREFIX . 'woo_get_'   ) === 0 ||
				$name === self::PREFIX . 'theme_list_files'  ||
				$name === self::PREFIX . 'theme_read_file'   ||
				$name === self::PREFIX . 'plugin_list_files' ||
				$name === self::PREFIX . 'plugin_read_file'  ||
				$name === self::PREFIX . 'uploads_list_files' ||
				$name === self::PREFIX . 'uploads_retention_get' ||
				$name === self::PREFIX . 'woo_get_order_meta' ||
				$name === self::PREFIX . 'wp_check_updates'  ||
				$name === self::PREFIX . 'wp_get_plugin_versions'
			);

			$is_destructive = (
				$name === self::PREFIX . 'theme_write_file'        ||
				$name === self::PREFIX . 'plugin_write_file'       ||
				$name === self::PREFIX . 'plugin_download_url'     ||
				$name === self::PREFIX . 'plugin_delete_file'      ||
				$name === self::PREFIX . 'uploads_delete_file'     ||
				$name === self::PREFIX . 'uploads_delete_batch'    ||
				$name === self::PREFIX . 'uploads_retention_set'   ||
				$name === self::PREFIX . 'uploads_retention_run_now' ||
				$name === self::PREFIX . 'woo_update_product'      ||
				$name === self::PREFIX . 'woo_update_order_status' ||
				$name === self::PREFIX . 'wp_update_plugin'        ||
				$name === self::PREFIX . 'wp_update_theme'         ||
				$name === self::PREFIX . 'wp_update_core'
			);

			$tool['annotations'] = array(
				'readOnlyHint'    => $is_readonly,
				'destructiveHint' => $is_destructive,
				'openWorldHint'   => $name === self::PREFIX . 'plugin_download_url',
			);
		}
		unset( $tool );

		return array_merge( $prev, array_values( $tools ) );
	}

	public function handle_call( $prev, string $tool, array $args, ?int $id ) {

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
				case self::PREFIX . 'woo_get_order_meta':      $data = $this->woo_get_order_meta( $args ); break;
				case self::PREFIX . 'woo_update_order_status': $data = $this->woo_update_order_status( $args ); break;
				case self::PREFIX . 'woo_add_order_note':      $data = $this->woo_add_order_note( $args ); break;
				case self::PREFIX . 'theme_list_files':        $data = $this->theme_list_files( $args ); break;
				case self::PREFIX . 'theme_read_file':         $data = $this->theme_read_file( $args ); break;
				case self::PREFIX . 'theme_write_file':        $data = $this->theme_write_file( $args ); break;
				case self::PREFIX . 'plugin_list_files':       $data = $this->plugin_list_files( $args ); break;
				case self::PREFIX . 'plugin_read_file':        $data = $this->plugin_read_file( $args ); break;
				case self::PREFIX . 'plugin_write_file':       $data = $this->plugin_write_file( $args ); break;
				case self::PREFIX . 'plugin_download_url':     $data = $this->plugin_download_url( $args ); break;
				case self::PREFIX . 'plugin_delete_file':      $data = $this->plugin_delete_file( $args ); break;
				case self::PREFIX . 'uploads_list_files':      $data = $this->uploads_list_files( $args ); break;
				case self::PREFIX . 'uploads_delete_file':     $data = $this->uploads_delete_file( $args ); break;
				case self::PREFIX . 'uploads_delete_batch':    $data = $this->uploads_delete_batch( $args ); break;
				case self::PREFIX . 'uploads_retention_get':     $data = $this->uploads_retention_get(); break;
				case self::PREFIX . 'uploads_retention_set':     $data = $this->uploads_retention_set( $args ); break;
				case self::PREFIX . 'uploads_retention_run_now': $data = $this->uploads_retention_run_now( $args ); break;
				case self::PREFIX . 'wp_check_updates':        $data = $this->wp_check_updates(); break;
				case self::PREFIX . 'wp_get_plugin_versions':  $data = $this->wp_get_plugin_versions(); break;
				case self::PREFIX . 'wp_update_plugin':        $data = $this->wp_update_plugin( $args ); break;
				case self::PREFIX . 'wp_update_theme':         $data = $this->wp_update_theme( $args ); break;
				case self::PREFIX . 'wp_update_core':          $data = $this->wp_update_core(); break;
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

	private function require_woo() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			throw new Exception( 'WooCommerce is not active.' );
		}
	}

	private function woo_list_products( array $args ) : array {
		$this->require_woo();
		$limit  = isset( $args['limit'] )  ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'publish';
		$query = array( 'limit' => $limit, 'status' => $status );
		if ( ! empty( $args['category_slug'] ) ) {
			$query['category'] = array( sanitize_title( $args['category_slug'] ) );
		}
		$products = wc_get_products( $query );
		$rows = array();
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
		if ( empty( $args['product_id'] ) ) throw new Exception( 'product_id is required.' );
		$product = wc_get_product( (int) $args['product_id'] );
		if ( ! $product ) throw new Exception( 'Product not found.' );
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
		if ( empty( $args['product_id'] ) ) throw new Exception( 'product_id is required.' );
		$product = wc_get_product( (int) $args['product_id'] );
		if ( ! $product ) throw new Exception( 'Product not found.' );
		$changed = array();
		if ( isset( $args['name'] ) )              { $product->set_name( sanitize_text_field( $args['name'] ) ); $changed[] = 'name'; }
		if ( isset( $args['description'] ) )       { $product->set_description( wp_kses_post( $args['description'] ) ); $changed[] = 'description'; }
		if ( isset( $args['short_description'] ) ) { $product->set_short_description( wp_kses_post( $args['short_description'] ) ); $changed[] = 'short_description'; }
		if ( isset( $args['status'] ) )            { $product->set_status( sanitize_text_field( $args['status'] ) ); $changed[] = 'status'; }
		if ( isset( $args['regular_price'] ) )     { $product->set_regular_price( sanitize_text_field( $args['regular_price'] ) ); $changed[] = 'regular_price'; }
		$product->save();
		return array( 'success' => true, 'product_id' => $product->get_id(), 'fields_updated' => $changed );
	}

	private function woo_list_categories() : array {
		$this->require_woo();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) throw new Exception( $terms->get_error_message() );
		$rows = array();
		foreach ( $terms as $term ) {
			$rows[] = array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => $term->parent, 'count' => $term->count, 'description' => $term->description );
		}
		return array( 'count' => count( $rows ), 'categories' => $rows );
	}

	private function woo_get_category( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['slug'] ) ) throw new Exception( 'slug is required.' );
		$term = get_term_by( 'slug', sanitize_title( $args['slug'] ), 'product_cat' );
		if ( ! $term ) throw new Exception( 'Category not found.' );
		$link = get_term_link( $term );
		return array(
			'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => $term->parent,
			'count' => $term->count, 'description' => $term->description,
			'permalink' => is_wp_error( $link ) ? null : $link,
		);
	}

	private function woo_list_orders( array $args ) : array {
		$this->require_woo();
		$limit  = isset( $args['limit'] )  ? max( 1, min( 100, (int) $args['limit'] ) ) : 25;
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'processing';
		$orders = wc_get_orders( array( 'limit' => $limit, 'status' => $status ) );
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

	/**
	 * Read-only order meta, order-level and per-line-item. Exists because
	 * woo_get_order returns no meta and wp_get_post_meta cannot reach the HPOS
	 * wp_wc_orders_meta store — which made PPS-Spec/_pps_* verification on the
	 * production orders pulled to staging impossible (DEPLOY_QA_FIXES_REPORT).
	 */
	private function woo_get_order_meta( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['order_id'] ) ) throw new Exception( 'order_id is required.' );
		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) throw new Exception( 'Order not found.' );

		$prefix = isset( $args['key_prefix'] ) ? (string) $args['key_prefix'] : '';
		$keep   = function ( array $meta ) use ( $prefix ) : array {
			if ( $prefix === '' ) return $meta;
			return array_filter( $meta, function ( $k ) use ( $prefix ) {
				return strpos( (string) $k, $prefix ) === 0;
			}, ARRAY_FILTER_USE_KEY );
		};

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$m = $keep( $this->flatten_meta( $item->get_meta_data() ) );
			if ( ! empty( $m ) ) {
				$items[] = array(
					'item_id' => (int) $item_id,
					'name'    => $item->get_name(),
					'meta'    => $m,
				);
			}
		}

		return array(
			'order_id'   => (int) $args['order_id'],
			'status'     => $order->get_status(),
			'order_meta' => $keep( $this->flatten_meta( $order->get_meta_data() ) ),
			'line_items' => $items,
		);
	}

	private function woo_get_order( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['order_id'] ) ) throw new Exception( 'order_id is required.' );
		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) throw new Exception( 'Order not found.' );
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
		if ( empty( $args['order_id'] ) || empty( $args['status'] ) ) throw new Exception( 'order_id and status are required.' );
		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) throw new Exception( 'Order not found.' );
		$order->update_status( sanitize_text_field( $args['status'] ), isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '' );
		return array( 'success' => true, 'order_id' => $order->get_id(), 'status' => $order->get_status() );
	}

	private function woo_add_order_note( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['order_id'] ) || empty( $args['note'] ) ) throw new Exception( 'order_id and note are required.' );
		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) throw new Exception( 'Order not found.' );
		$is_customer_note = ! empty( $args['is_customer_note'] ) ? 1 : 0;
		$note_id = $order->add_order_note( sanitize_textarea_field( $args['note'] ), $is_customer_note, false );
		return array( 'success' => (bool) $note_id, 'note_id' => $note_id );
	}

	private function safe_theme_path( string $relative_path ) : string {
		$theme_root = trailingslashit( get_theme_root() ) . self::ALLOWED_THEME_SLUG;
		$real_root  = realpath( $theme_root );
		if ( ! $real_root ) throw new Exception( 'Theme directory not found: ' . self::ALLOWED_THEME_SLUG );
		$relative_path = ltrim( $relative_path, '/\\' );
		if ( strpos( $relative_path, '..' ) !== false ) throw new Exception( 'Path traversal not allowed.' );
		$full_path = $real_root . DIRECTORY_SEPARATOR . $relative_path;
		$check = file_exists( $full_path ) ? realpath( $full_path ) : realpath( dirname( $full_path ) );
		if ( ! $check || strpos( $check, $real_root ) !== 0 ) throw new Exception( 'Path is outside the allowed theme directory.' );
		return $full_path;
	}

	private function theme_list_files( array $args ) : array {
		$theme_root = trailingslashit( get_theme_root() ) . self::ALLOWED_THEME_SLUG;
		$real_root  = realpath( $theme_root );
		if ( ! $real_root ) throw new Exception( 'Theme directory not found: ' . self::ALLOWED_THEME_SLUG );
		$start_dir = $real_root;
		if ( ! empty( $args['subdirectory'] ) ) {
			$start_dir = $this->safe_theme_path( $args['subdirectory'] );
			if ( ! is_dir( $start_dir ) ) throw new Exception( 'Subdirectory not found.' );
		}
		$files = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $start_dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$rel = str_replace( $real_root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
				$files[] = array( 'path' => str_replace( '\\', '/', $rel ), 'size_bytes' => $file->getSize(), 'last_modified' => date( 'c', $file->getMTime() ) );
			}
		}
		return array( 'count' => count( $files ), 'files' => $files );
	}

	private function theme_read_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) ) throw new Exception( 'relative_path is required.' );
		$full_path = $this->safe_theme_path( $args['relative_path'] );
		if ( ! file_exists( $full_path ) ) throw new Exception( 'File not found: ' . $args['relative_path'] );
		$contents = file_get_contents( $full_path );
		if ( $contents === false ) throw new Exception( 'Could not read file.' );
		return array( 'relative_path' => $args['relative_path'], 'size_bytes' => filesize( $full_path ), 'last_modified' => date( 'c', filemtime( $full_path ) ), 'contents' => $contents );
	}

	private function theme_write_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) || ! isset( $args['contents'] ) ) throw new Exception( 'relative_path and contents are required.' );
		$full_path = $this->safe_theme_path( $args['relative_path'] );
		$dir = dirname( $full_path );
		if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
		$bytes = file_put_contents( $full_path, $args['contents'] );
		if ( $bytes === false ) throw new Exception( 'Could not write file.' );
		if ( function_exists( 'opcache_invalidate' ) ) opcache_invalidate( $full_path, true );
		return array( 'success' => true, 'relative_path' => $args['relative_path'], 'bytes_written' => $bytes );
	}

	private function safe_plugin_path( string $relative_path ) : string {
		$plugins_root = realpath( WP_PLUGIN_DIR );
		if ( ! $plugins_root ) throw new Exception( 'Could not resolve plugins directory.' );
		$relative_path = ltrim( $relative_path, '/\\' );
		if ( strpos( $relative_path, '..' ) !== false ) throw new Exception( 'Path traversal not allowed.' );
		$full_path = $plugins_root . DIRECTORY_SEPARATOR . $relative_path;
		$check = file_exists( $full_path ) ? realpath( $full_path ) : realpath( dirname( $full_path ) );
		if ( ! $check || strpos( $check, $plugins_root ) !== 0 ) throw new Exception( 'Path is outside the plugins directory.' );
		return $full_path;
	}

	private function plugin_list_files( array $args ) : array {
		$plugins_root = realpath( WP_PLUGIN_DIR );
		if ( ! $plugins_root ) throw new Exception( 'Could not resolve plugins directory.' );
		if ( empty( $args['plugin_folder'] ) ) {
			$dirs = glob( $plugins_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
			$out = array();
			foreach ( $dirs as $dir ) $out[] = basename( $dir );
			return array( 'plugin_folders' => $out, 'count' => count( $out ) );
		}
		$start_dir = $this->safe_plugin_path( sanitize_text_field( $args['plugin_folder'] ) );
		if ( ! is_dir( $start_dir ) ) throw new Exception( 'Plugin folder not found: ' . $args['plugin_folder'] );
		$files = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $start_dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$rel = str_replace( $plugins_root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
				$files[] = array( 'path' => str_replace( '\\', '/', $rel ), 'size_bytes' => $file->getSize(), 'last_modified' => date( 'c', $file->getMTime() ) );
			}
		}
		return array( 'count' => count( $files ), 'files' => $files );
	}

	private function plugin_read_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) ) throw new Exception( 'relative_path is required.' );
		$full_path = $this->safe_plugin_path( $args['relative_path'] );
		if ( ! file_exists( $full_path ) ) throw new Exception( 'File not found: ' . $args['relative_path'] );
		$contents = file_get_contents( $full_path );
		if ( $contents === false ) throw new Exception( 'Could not read file.' );
		return array( 'relative_path' => $args['relative_path'], 'size_bytes' => filesize( $full_path ), 'last_modified' => date( 'c', filemtime( $full_path ) ), 'contents' => $contents );
	}

	private function plugin_write_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) || ! isset( $args['contents'] ) ) throw new Exception( 'relative_path and contents are required.' );
		$full_path = $this->safe_plugin_path( $args['relative_path'] );
		$dir = dirname( $full_path );
		if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
		$bytes = file_put_contents( $full_path, $args['contents'] );
		if ( $bytes === false ) throw new Exception( 'Could not write file.' );
		if ( function_exists( 'opcache_invalidate' ) ) opcache_invalidate( $full_path, true );
		return array( 'success' => true, 'relative_path' => $args['relative_path'], 'bytes_written' => $bytes );
	}

	/**
	 * Fetch from a public https URL and write into wp-content/plugins.
	 * Caps: 12MB, 60s, https only.
	 */
	private function plugin_download_url( array $args ) : array {
		if ( empty( $args['url'] ) || empty( $args['relative_path'] ) ) {
			throw new Exception( 'url and relative_path are required.' );
		}

		$url = esc_url_raw( $args['url'], array( 'https' ) );
		if ( ! $url || strpos( $url, 'https://' ) !== 0 ) {
			throw new Exception( 'Only https:// URLs are allowed.' );
		}

		$full_path = $this->safe_plugin_path( $args['relative_path'] );

		$response = wp_safe_remote_get( $url, array(
			'timeout'     => 60,
			'redirection' => 3,
			'sslverify'   => true,
		) );

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Fetch failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			throw new Exception( 'Fetch returned HTTP ' . $code );
		}

		$body = wp_remote_retrieve_body( $response );
		$len  = strlen( $body );

		if ( $len === 0 ) {
			throw new Exception( 'Response body is empty.' );
		}
		if ( $len > 12 * 1024 * 1024 ) {
			throw new Exception( 'Response too large (>12MB).' );
		}

		$dir = dirname( $full_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$bytes = file_put_contents( $full_path, $body );
		if ( $bytes === false ) {
			throw new Exception( 'Could not write file. Check filesystem permissions.' );
		}

		if ( function_exists( 'opcache_invalidate' ) ) opcache_invalidate( $full_path, true );

		return array(
			'success'       => true,
			'relative_path' => $args['relative_path'],
			'source_url'    => $url,
			'bytes_written' => $bytes,
			'http_status'   => $code,
		);
	}

	/**
	 * Permanently delete a single file inside wp-content/plugins.
	 * Refuses directories, this plugin's own file, and any active plugin's entry file.
	 */
	private function plugin_delete_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) ) throw new Exception( 'relative_path is required.' );
		$full_path = $this->safe_plugin_path( $args['relative_path'] );
		if ( ! file_exists( $full_path ) ) throw new Exception( 'File not found: ' . $args['relative_path'] );
		if ( is_dir( $full_path ) ) throw new Exception( 'Refusing to delete a directory; this tool removes single files only.' );

		$real = realpath( $full_path );

		// Self-preservation: never delete this MCP tools plugin's own file.
		if ( $real === realpath( __FILE__ ) ) {
			throw new Exception( 'Refusing to delete the MCP tools plugin itself.' );
		}

		// Never delete the entry file of an active plugin (would break the site).
		$plugins_root   = realpath( WP_PLUGIN_DIR );
		$active_plugins = (array) get_option( 'active_plugins', array() );
		foreach ( $active_plugins as $active ) {
			if ( realpath( $plugins_root . DIRECTORY_SEPARATOR . $active ) === $real ) {
				throw new Exception( 'Refusing to delete an active plugin entry file: ' . $active . '. Deactivate it first.' );
			}
		}

		$bytes = filesize( $full_path );
		if ( ! unlink( $full_path ) ) throw new Exception( 'Could not delete file. Check filesystem permissions.' );
		if ( function_exists( 'opcache_invalidate' ) ) opcache_invalidate( $full_path, true );
		return array( 'success' => true, 'relative_path' => $args['relative_path'], 'bytes_deleted' => $bytes );
	}

	// ── Uploads (wp-content/uploads) ──────────────────────────────────────────
	//
	// Customer artwork accumulates here without media-library attachment rows, so
	// the media tools cannot see it and the plugin-scoped tools cannot reach it.
	// These three close that gap: one read tool that can answer "what is old and
	// large", and two delete tools that take explicit paths only. No pattern or
	// glob deletion — the caller decides exactly which files die, so the tool
	// cannot over-delete on a bad filter.

	private function uploads_root() : string {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) throw new Exception( 'Could not resolve uploads directory: ' . $upload['error'] );
		$root = realpath( $upload['basedir'] );
		if ( ! $root ) throw new Exception( 'Could not resolve uploads directory.' );
		return $root;
	}

	/**
	 * Resolve a path inside the uploads root, refusing anything outside it.
	 *
	 * The containment test is stricter than the plugin/theme helpers above: it
	 * requires an exact match or a trailing separator, so a sibling directory
	 * that merely shares the prefix (wp-content/uploads-old) cannot pass.
	 */
	private function safe_uploads_path( string $relative_path ) : string {
		$uploads_root  = $this->uploads_root();
		$relative_path = ltrim( $relative_path, '/\\' );
		if ( strpos( $relative_path, '..' ) !== false ) throw new Exception( 'Path traversal not allowed.' );
		$full_path = $uploads_root . DIRECTORY_SEPARATOR . $relative_path;
		$check = file_exists( $full_path ) ? realpath( $full_path ) : realpath( dirname( $full_path ) );
		if ( ! $check ) throw new Exception( 'Path is outside the uploads directory.' );
		if ( $check !== $uploads_root && strpos( $check, $uploads_root . DIRECTORY_SEPARATOR ) !== 0 ) {
			throw new Exception( 'Path is outside the uploads directory.' );
		}
		return $full_path;
	}

	private function uploads_list_files( array $args ) : array {
		$uploads_root = $this->uploads_root();

		$start_dir = $uploads_root;
		$scope     = '';
		if ( ! empty( $args['subdirectory'] ) ) {
			$scope     = trim( str_replace( '\\', '/', sanitize_text_field( $args['subdirectory'] ) ), '/' );
			$start_dir = $this->safe_uploads_path( $scope );
			if ( ! is_dir( $start_dir ) ) throw new Exception( 'Directory not found under uploads: ' . $scope );
		}

		$min_age_days = isset( $args['min_age_days'] ) ? max( 0, (int) $args['min_age_days'] ) : 0;
		$min_size_kb  = isset( $args['min_size_kb'] )  ? max( 0, (int) $args['min_size_kb'] )  : 0;
		$limit        = isset( $args['limit'] ) ? max( 1, min( 2000, (int) $args['limit'] ) ) : 200;
		$order_by     = ( isset( $args['order_by'] ) && $args['order_by'] === 'age' ) ? 'age' : 'size';

		$now      = time();
		$cutoff   = $min_age_days > 0 ? ( $now - $min_age_days * DAY_IN_SECONDS ) : 0;
		$min_size = $min_size_kb * 1024;

		// Soft cap so a very large tree cannot hang the request. Always reported.
		$scan_cap       = 200000;
		$scanned        = 0;
		$scan_truncated = false;

		$files       = array();
		$total_bytes = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $start_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ++$scanned > $scan_cap ) { $scan_truncated = true; break; }
			if ( ! $file->isFile() ) continue;

			$size  = $file->getSize();
			$mtime = $file->getMTime();

			if ( $min_size && $size < $min_size ) continue;
			if ( $cutoff && $mtime > $cutoff ) continue;

			$rel = str_replace( '\\', '/', str_replace( $uploads_root . DIRECTORY_SEPARATOR, '', $file->getPathname() ) );

			$total_bytes += $size;
			$files[]      = array(
				'path'          => $rel,
				'size_bytes'    => $size,
				'last_modified' => date( 'c', $mtime ),
				'age_days'      => (int) floor( ( $now - $mtime ) / DAY_IN_SECONDS ),
			);
		}

		$matched_count = count( $files );

		usort( $files, function( $a, $b ) use ( $order_by ) {
			return $order_by === 'age'
				? $b['age_days'] <=> $a['age_days']
				: $b['size_bytes'] <=> $a['size_bytes'];
		} );

		$returned = array_slice( $files, 0, $limit );

		return array(
			'uploads_scope'      => $scope === '' ? '(entire uploads tree)' : $scope,
			'filters'            => array( 'min_age_days' => $min_age_days, 'min_size_kb' => $min_size_kb, 'order_by' => $order_by ),
			'matched_count'      => $matched_count,
			'matched_total_bytes' => $total_bytes,
			'matched_total_mb'   => round( $total_bytes / 1048576, 1 ),
			'returned_count'     => count( $returned ),
			'truncated'          => $matched_count > count( $returned ),
			'scanned_entries'    => $scanned,
			'scan_truncated'     => $scan_truncated,
			'files'              => $returned,
		);
	}

	/**
	 * Guards shared by both uploads delete tools.
	 *
	 * Refuses the directory-guard files that stop the uploads tree being browsed
	 * publicly, and refuses any file that still backs a media-library attachment
	 * (deleting those here would leave an orphaned attachment row pointing at a
	 * missing file — that is the media tools' job).
	 */
	private function assert_uploads_file_deletable( string $full_path, string $relative_path, ?array $attached_paths = null ) {
		if ( ! file_exists( $full_path ) ) throw new Exception( 'File not found: ' . $relative_path );
		if ( is_dir( $full_path ) ) throw new Exception( 'Refusing to delete a directory; these tools remove single files only.' );

		$basename = strtolower( basename( $full_path ) );
		if ( in_array( $basename, array( 'index.php', 'index.html', '.htaccess', 'web.config' ), true ) ) {
			throw new Exception( 'Refusing to delete a directory guard file (' . $basename . '); it blocks public indexing of the uploads tree.' );
		}

		// Retention passes the prefetched set (one query per run); the single-file
		// tools pass nothing and keep the targeted per-path query.
		if ( is_array( $attached_paths ) ) {
			if ( isset( $attached_paths[ ltrim( str_replace( '\\', '/', $relative_path ), '/' ) ] ) ) {
				throw new Exception( 'Refusing to delete a file attached to a media item; use the media tools so the attachment row goes with it.' );
			}
			return;
		}

		$attached = get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => '_wp_attached_file',
			'meta_value'       => $relative_path,
			'suppress_filters' => true,
		) );
		if ( ! empty( $attached ) ) {
			throw new Exception( 'Refusing to delete a file attached to media item #' . (int) $attached[0] . '; use the media tools so the attachment row goes with it.' );
		}
	}

	private function uploads_delete_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) ) throw new Exception( 'relative_path is required.' );
		$relative_path = ltrim( str_replace( '\\', '/', (string) $args['relative_path'] ), '/' );
		$full_path     = $this->safe_uploads_path( $relative_path );

		$this->assert_uploads_file_deletable( $full_path, $relative_path );

		$bytes = filesize( $full_path );
		if ( ! unlink( $full_path ) ) throw new Exception( 'Could not delete file. Check filesystem permissions.' );

		return array( 'success' => true, 'relative_path' => $relative_path, 'bytes_deleted' => $bytes );
	}

	private function uploads_delete_batch( array $args ) : array {
		if ( empty( $args['relative_paths'] ) || ! is_array( $args['relative_paths'] ) ) {
			throw new Exception( 'relative_paths is required and must be an array of paths.' );
		}

		$paths = array_values( array_unique( array_filter( array_map( 'strval', $args['relative_paths'] ) ) ) );
		if ( count( $paths ) > 500 ) {
			throw new Exception( 'Too many paths in one call (' . count( $paths ) . '); max 500.' );
		}

		$deleted = array();
		$failed  = array();
		$total   = 0;

		foreach ( $paths as $path ) {
			$relative_path = ltrim( str_replace( '\\', '/', $path ), '/' );
			try {
				$full_path = $this->safe_uploads_path( $relative_path );
				$this->assert_uploads_file_deletable( $full_path, $relative_path );
				$bytes = filesize( $full_path );
				if ( ! unlink( $full_path ) ) throw new Exception( 'Could not delete file. Check filesystem permissions.' );
				$total    += $bytes;
				$deleted[] = array( 'path' => $relative_path, 'bytes_deleted' => $bytes );
			} catch ( Exception $e ) {
				// One bad path must not abort the rest of the batch.
				$failed[] = array( 'path' => $relative_path, 'error' => $e->getMessage() );
			}
		}

		return array(
			'success'       => empty( $failed ),
			'requested'     => count( $paths ),
			'deleted_count' => count( $deleted ),
			'failed_count'  => count( $failed ),
			'bytes_deleted' => $total,
			'mb_deleted'    => round( $total / 1048576, 1 ),
			'deleted'       => $deleted,
			'failed'        => $failed,
		);
	}

	// ── Uploads retention (automatic, age-based) ──────────────────────────────
	//
	// A daily WP-Cron job that deletes files older than N days from ONE configured
	// uploads subdirectory. Built for legacy customer artwork (e.g. the WCPA tree)
	// where the orders are long closed and offline archives exist.
	//
	// Deliberately conservative, because this deletes customer files with nobody
	// watching:
	//   - disabled by default, and does nothing at all until a directory is set
	//   - dry_run defaults to true: it reports what it WOULD delete and deletes
	//     nothing, so the first runs are reviewable before anything is destroyed
	//   - refuses the uploads root itself, so a blank or "/" directory cannot
	//     cascade into wiping every upload on the site
	//   - min_age_days is floored at RETENTION_MIN_AGE_FLOOR, so a typo like 2
	//     cannot mean "two days old"
	//   - per-run delete cap bounds the damage of any misconfiguration and keeps
	//     the cron run inside PHP's time limit
	//   - reuses the same per-file guards as the manual delete tools
	//   - every run writes a log (counts, bytes, sample paths, skip reasons)

	public function retention_defaults() : array {
		return array(
			'enabled'             => false,
			'dry_run'             => true,
			'directory'           => '',     // relative to the uploads root; empty = no-op
			'min_age_days'        => 730,    // 2 years
			'max_deletes_per_run' => 500,
		);
	}

	public function retention_settings() : array {
		$saved = get_option( self::RETENTION_OPTION, array() );
		if ( ! is_array( $saved ) ) $saved = array();
		return array_merge( $this->retention_defaults(), $saved );
	}

	/**
	 * Keep the daily cron event in step with the enabled flag.
	 */
	public function maybe_schedule_retention() {
		$enabled   = (bool) $this->retention_settings()['enabled'];
		$scheduled = wp_next_scheduled( self::RETENTION_CRON );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_CRON );
		} elseif ( ! $enabled && $scheduled ) {
			wp_unschedule_event( $scheduled, self::RETENTION_CRON );
		}
	}

	public function retention_cron_run() {
		$settings = $this->retention_settings();
		if ( empty( $settings['enabled'] ) ) return;
		try {
			$this->retention_run();
		} catch ( Exception $e ) {
			update_option( self::RETENTION_LOG, array_merge(
				(array) get_option( self::RETENTION_LOG, array() ),
				array( 'last_error' => $e->getMessage(), 'last_error_at' => date( 'c' ) )
			), false );
		}
	}

	/**
	 * Enforce the retention policy once.
	 *
	 * @param array $overrides Settings to override for this run only (e.g. dry_run).
	 */
	/**
	 * All attachment-backed upload paths, fetched once per retention run. The
	 * per-candidate get_posts() this replaces is what pushed large runs past the
	 * client's 60s timeout (one meta query per expired file).
	 */
	private function attached_upload_paths() : array {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);
		$set = array();
		foreach ( (array) $rows as $p ) {
			if ( is_string( $p ) && $p !== '' ) $set[ ltrim( str_replace( '\\', '/', $p ), '/' ) ] = true;
		}
		return $set;
	}

	private function retention_run( array $overrides = array() ) : array {
		$s = array_merge( $this->retention_settings(), $overrides );

		// Concurrency guard: a client-side timeout does not stop a PHP run — it
		// keeps deleting server-side, and a second run started meanwhile races it
		// (each sees files the other already removed). Refuse instead. The lock
		// self-expires so a fataled run cannot wedge retention permanently.
		if ( get_transient( 'pps_retention_run_lock' ) ) {
			return array(
				'ran_at' => date( 'c' ),
				'error'  => 'A retention run is already in progress (lock held; expires within 10 minutes). Not started.',
			);
		}
		set_transient( 'pps_retention_run_lock', time(), 10 * MINUTE_IN_SECONDS );
		try {
			return $this->retention_run_locked( $s );
		} finally {
			delete_transient( 'pps_retention_run_lock' );
		}
	}

	private function retention_run_locked( array $s ) : array {

		$min_age = max( self::RETENTION_MIN_AGE_FLOOR, (int) $s['min_age_days'] );
		$cap     = max( 1, min( 5000, (int) $s['max_deletes_per_run'] ) );
		$dry_run = ! empty( $s['dry_run'] );

		$result = array(
			'ran_at'         => date( 'c' ),
			'dry_run'        => $dry_run,
			'directory'      => (string) $s['directory'],
			'min_age_days'   => $min_age,
			'scanned'        => 0,
			'expired_count'  => 0,
			'expired_bytes'  => 0,
			'deleted_count'  => 0,
			'deleted_bytes'  => 0,
			'skipped_count'  => 0,
			'cap_reached'    => false,
			'samples'        => array(),
			'skipped'        => array(),
		);

		if ( trim( (string) $s['directory'] ) === '' ) {
			$result['error'] = 'No directory configured; retention is a no-op until one is set.';
			return $result;
		}

		$uploads_root = $this->uploads_root();
		$dir          = $this->safe_uploads_path( (string) $s['directory'] );

		if ( ! is_dir( $dir ) ) {
			$result['error'] = 'Configured directory not found under uploads: ' . $s['directory'];
			return $result;
		}
		if ( realpath( $dir ) === $uploads_root ) {
			$result['error'] = 'Refusing to run retention against the uploads root; configure a specific subdirectory.';
			return $result;
		}

		$cutoff = time() - $min_age * DAY_IN_SECONDS;

		// One query for the whole run instead of one get_posts() per candidate.
		$attached_paths = $this->attached_upload_paths();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			$result['scanned']++;
			if ( ! $file->isFile() ) continue;
			if ( $file->getMTime() > $cutoff ) continue;

			$size = $file->getSize();
			$rel  = str_replace( '\\', '/', str_replace( $uploads_root . DIRECTORY_SEPARATOR, '', $file->getPathname() ) );

			$result['expired_count']++;
			$result['expired_bytes'] += $size;

			try {
				$this->assert_uploads_file_deletable( $file->getPathname(), $rel, $attached_paths );
			} catch ( Exception $e ) {
				$result['skipped_count']++;
				if ( count( $result['skipped'] ) < 25 ) {
					$result['skipped'][] = array( 'path' => $rel, 'reason' => $e->getMessage() );
				}
				continue;
			}

			if ( count( $result['samples'] ) < 25 ) {
				$result['samples'][] = array( 'path' => $rel, 'size_bytes' => $size, 'age_days' => (int) floor( ( time() - $file->getMTime() ) / DAY_IN_SECONDS ) );
			}

			if ( $dry_run ) continue;

			if ( @unlink( $file->getPathname() ) ) {
				$result['deleted_count']++;
				$result['deleted_bytes'] += $size;
				if ( $result['deleted_count'] >= $cap ) { $result['cap_reached'] = true; break; }
			} else {
				$result['skipped_count']++;
				if ( count( $result['skipped'] ) < 25 ) {
					// "vanished" is normal (another process removed it between the
					// scan and the unlink) and is not a permissions problem.
					$reason = file_exists( $file->getPathname() )
						? 'unlink failed with the file still present — likely a real permission problem'
						: 'file vanished before deletion (removed by another process); nothing to fix';
					$result['skipped'][] = array( 'path' => $rel, 'reason' => $reason );
				}
			}
		}

		$result['expired_mb'] = round( $result['expired_bytes'] / 1048576, 1 );
		$result['deleted_mb'] = round( $result['deleted_bytes'] / 1048576, 1 );

		$prev = (array) get_option( self::RETENTION_LOG, array() );
		update_option( self::RETENTION_LOG, array(
			'last_run'              => $result,
			'cumulative_deleted'    => (int) ( $prev['cumulative_deleted'] ?? 0 ) + $result['deleted_count'],
			'cumulative_bytes'      => (int) ( $prev['cumulative_bytes'] ?? 0 ) + $result['deleted_bytes'],
		), false );

		return $result;
	}

	private function uploads_retention_get() : array {
		return array(
			'settings'      => $this->retention_settings(),
			'defaults'      => $this->retention_defaults(),
			'min_age_floor' => self::RETENTION_MIN_AGE_FLOOR,
			'next_run'      => wp_next_scheduled( self::RETENTION_CRON ) ? date( 'c', wp_next_scheduled( self::RETENTION_CRON ) ) : null,
			'log'           => get_option( self::RETENTION_LOG, array() ),
		);
	}

	private function uploads_retention_set( array $args ) : array {
		$s = $this->retention_settings();

		if ( isset( $args['enabled'] ) )    $s['enabled'] = (bool) $args['enabled'];
		if ( isset( $args['dry_run'] ) )    $s['dry_run'] = (bool) $args['dry_run'];
		if ( isset( $args['directory'] ) ) {
			$dir = trim( str_replace( '\\', '/', sanitize_text_field( (string) $args['directory'] ) ), '/' );
			if ( $dir !== '' ) {
				$full = $this->safe_uploads_path( $dir );   // rejects traversal / outside-root now, not at cron time
				if ( ! is_dir( $full ) ) throw new Exception( 'Directory not found under uploads: ' . $dir );
				if ( realpath( $full ) === $this->uploads_root() ) throw new Exception( 'Refusing to target the uploads root.' );
			}
			$s['directory'] = $dir;
		}
		if ( isset( $args['min_age_days'] ) ) {
			$age = (int) $args['min_age_days'];
			if ( $age < self::RETENTION_MIN_AGE_FLOOR ) {
				throw new Exception( 'min_age_days must be at least ' . self::RETENTION_MIN_AGE_FLOOR . '.' );
			}
			$s['min_age_days'] = $age;
		}
		if ( isset( $args['max_deletes_per_run'] ) ) {
			$s['max_deletes_per_run'] = max( 1, min( 5000, (int) $args['max_deletes_per_run'] ) );
		}

		update_option( self::RETENTION_OPTION, $s, false );
		$this->maybe_schedule_retention();

		return array( 'success' => true, 'settings' => $s, 'next_run' => wp_next_scheduled( self::RETENTION_CRON ) ? date( 'c', wp_next_scheduled( self::RETENTION_CRON ) ) : null );
	}

	private function uploads_retention_run_now( array $args ) : array {
		// Defaults to a dry run regardless of the stored setting: an explicit
		// dry_run=false is required to actually delete from a manual invocation.
		$dry_run = isset( $args['dry_run'] ) ? (bool) $args['dry_run'] : true;
		return $this->retention_run( array( 'dry_run' => $dry_run ) );
	}

	private function require_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		if ( ! WP_Filesystem() ) throw new Exception( 'Could not initialize WP_Filesystem.' );
	}

	private function wp_check_updates() : array {
		$this->require_upgrader();
		wp_update_plugins();
		wp_update_themes();
		wp_version_check();
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$all_plugins = get_plugins();
		$plugins = array();
		if ( isset( $plugin_updates->response ) && is_array( $plugin_updates->response ) ) {
			foreach ( $plugin_updates->response as $file => $info ) {
				$plugins[] = array(
					'plugin'    => $file,
					'name'      => isset( $all_plugins[ $file ]['Name'] ) ? $all_plugins[ $file ]['Name'] : $file,
					'current'   => isset( $all_plugins[ $file ]['Version'] ) ? $all_plugins[ $file ]['Version'] : '?',
					'available' => isset( $info->new_version ) ? $info->new_version : '?',
				);
			}
		}
		$themes = array();
		if ( isset( $theme_updates->response ) && is_array( $theme_updates->response ) ) {
			foreach ( $theme_updates->response as $stylesheet => $info ) {
				$theme = wp_get_theme( $stylesheet );
				$themes[] = array(
					'stylesheet' => $stylesheet,
					'name'       => $theme->get( 'Name' ),
					'current'    => $theme->get( 'Version' ),
					'available'  => isset( $info['new_version'] ) ? $info['new_version'] : '?',
				);
			}
		}
		$core = null;
		$core_updates = get_core_updates();
		if ( is_array( $core_updates ) ) {
			foreach ( $core_updates as $u ) {
				if ( isset( $u->response ) && $u->response === 'upgrade' ) {
					global $wp_version;
					$core = array(
						'current'                => $wp_version,
						'available'              => $u->version,
						'php_version_required'   => isset( $u->php_version ) ? $u->php_version : null,
						'mysql_version_required' => isset( $u->mysql_version ) ? $u->mysql_version : null,
					);
					break;
				}
			}
		}
		return array(
			'core_update'    => $core,
			'plugin_updates' => $plugins,
			'theme_updates'  => $themes,
			'plugin_count'   => count( $plugins ),
			'theme_count'    => count( $themes ),
			'core_available' => $core !== null,
		);
	}

	private function wp_get_plugin_versions() : array {
		if ( ! function_exists( 'get_plugins' ) )       require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! function_exists( 'wp_update_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$update_response = isset( $updates->response ) ? $updates->response : array();
		$all = get_plugins();
		$rows = array();
		foreach ( $all as $file => $data ) {
			$target = null;
			if ( isset( $update_response[ $file ] ) && isset( $update_response[ $file ]->new_version ) ) {
				$target = $update_response[ $file ]->new_version;
			}
			$rows[] = array(
				'plugin'           => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'active'           => is_plugin_active( $file ),
				'update_available' => $target,
			);
		}
		return array( 'count' => count( $rows ), 'plugins' => $rows );
	}

	private function wp_update_plugin( array $args ) : array {
		if ( empty( $args['plugin'] ) ) throw new Exception( 'plugin is required.' );
		$this->require_upgrader();
		wp_update_plugins();
		$plugin = sanitize_text_field( $args['plugin'] );
		$all = get_plugins();
		if ( ! isset( $all[ $plugin ] ) ) throw new Exception( 'Plugin not found: ' . $plugin );
		$before = $all[ $plugin ]['Version'];
		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result = $upgrader->upgrade( $plugin );
		if ( is_wp_error( $result ) ) throw new Exception( 'Upgrade failed: ' . $result->get_error_message() );
		if ( $result === false )      throw new Exception( 'Upgrade returned false.' );
		if ( $result === null )       throw new Exception( 'Upgrade returned null.' );
		wp_clean_plugins_cache();
		$all_after = get_plugins();
		$after = isset( $all_after[ $plugin ]['Version'] ) ? $all_after[ $plugin ]['Version'] : '?';
		return array( 'success' => true, 'plugin' => $plugin, 'before' => $before, 'after' => $after, 'messages' => $skin->get_upgrade_messages() );
	}

	private function wp_update_theme( array $args ) : array {
		if ( empty( $args['stylesheet'] ) ) throw new Exception( 'stylesheet is required.' );
		$this->require_upgrader();
		wp_update_themes();
		$stylesheet = sanitize_text_field( $args['stylesheet'] );
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) throw new Exception( 'Theme not found: ' . $stylesheet );
		$before = $theme->get( 'Version' );
		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result = $upgrader->upgrade( $stylesheet );
		if ( is_wp_error( $result ) ) throw new Exception( 'Upgrade failed: ' . $result->get_error_message() );
		if ( $result === false )      throw new Exception( 'Upgrade returned false.' );
		wp_clean_themes_cache();
		$theme_after = wp_get_theme( $stylesheet );
		return array( 'success' => true, 'stylesheet' => $stylesheet, 'before' => $before, 'after' => $theme_after->get( 'Version' ), 'messages' => $skin->get_upgrade_messages() );
	}

	private function wp_update_core() : array {
		$this->require_upgrader();
		wp_version_check();
		$updates = get_core_updates();
		if ( empty( $updates ) || ! is_array( $updates ) ) throw new Exception( 'No core update info available.' );
		$update = false;
		foreach ( $updates as $u ) {
			if ( isset( $u->response ) && $u->response === 'upgrade' ) { $update = $u; break; }
		}
		if ( ! $update ) {
			global $wp_version;
			return array( 'success' => true, 'message' => 'Core is already up to date.', 'version' => $wp_version );
		}
		global $wp_version;
		$before = $wp_version;
		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result = $upgrader->upgrade( $update );
		if ( is_wp_error( $result ) ) throw new Exception( 'Core upgrade failed: ' . $result->get_error_message() );
		return array( 'success' => true, 'before' => $before, 'after' => is_string( $result ) ? $result : 'see messages', 'messages' => $skin->get_upgrade_messages() );
	}

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
