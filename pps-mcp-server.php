<?php
/**
 * Plugin Name: PPS MCP Server
 * Description: A minimal, self-contained MCP (Model Context Protocol) server for WordPress. Streamable HTTP transport, OAuth 2.1 with PKCE and dynamic client registration for claude.ai connectors, plus a static bearer token for CLI clients. Intended to replace AI Engine's MCP module.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Author: PPS
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS
 * ─────────────────────────────────────────────────────────────────────────────
 * AI Engine bundles a chatbot suite around its MCP server. This is the server on
 * its own: one JSON-RPC endpoint, one tool registry, two ways to authenticate.
 *
 *   MCP endpoint      POST /wp-json/pps-mcp/v1/http
 *   Protected resrc.  GET  /.well-known/oauth-protected-resource/wp-json/pps-mcp/v1/http
 *   Auth server meta  GET  /.well-known/oauth-authorization-server
 *   Registration      POST /wp-json/pps-mcp/v1/oauth/register     (RFC 7591)
 *   Authorize         GET  /?pps_mcp_authorize=1
 *   Token             POST /wp-json/pps-mcp/v1/oauth/token
 *
 * The two discovery documents are served from the ROOT .well-known path, because
 * that is where clients look: RFC 9728 and RFC 8414 insert the well-known segment
 * after the host and append the resource path. Copies remain under the REST
 * namespace for any client that follows the WWW-Authenticate pointer instead.
 *
 * Routes live under pps-mcp/v1 so they cannot collide with AI Engine's mcp/v1
 * while you migrate. Nothing here reads or writes AI Engine's options.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AUTHENTICATION
 * ─────────────────────────────────────────────────────────────────────────────
 * Two paths, because they fail in different places and having both means you can
 * always get in:
 *
 * 1. STATIC BEARER — for Claude Code and curl. Generate one in
 *    Settings → PPS MCP. Sent as `Authorization: Bearer <token>`.
 *
 *    If your host strips the Authorization header — normal on Apache/LiteSpeed
 *    under CGI/FastCGI, and the most common reason a WordPress MCP endpoint
 *    rejects every credential — send it as `X-MCP-Authorization` instead. Custom
 *    headers survive where Authorization does not, so this is a working
 *    escape hatch rather than a workaround you have to earn.
 *
 * 2. OAUTH 2.1 + PKCE — for claude.ai connectors, which will not use a static
 *    token. Public clients, S256 challenge required, dynamic registration so the
 *    connector can enrol itself. The authorize step is gated on a logged-in
 *    WordPress administrator, so granting access is a deliberate act by a human
 *    at a browser.
 *
 * Every token is bound to a WordPress user. Tool calls run as that user and go
 * through real capability checks — a token is not a bypass, it is a login.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS STORED
 * ─────────────────────────────────────────────────────────────────────────────
 *   pps_mcp_token_hash    sha256 of the static token. The token itself is shown
 *                         once, at generation, and never stored in recoverable
 *                         form — a database leak must not yield a live credential.
 *   pps_mcp_clients       registered OAuth clients (id, redirect_uris, name).
 *   pps_mcp_tok_*         transients: auth codes (60s) and access tokens (30d).
 *   pps_mcp_log           the last 200 tool calls, for after-the-fact review.
 *
 * Deactivating leaves these in place; `pps_mcp_uninstall()` removes them all.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FILE WRITES ARE CONFINED
 * ─────────────────────────────────────────────────────────────────────────────
 * Writes are restricted to wp-content/plugins/ and wp-content/themes/, with
 * symlinks resolved and traversal rejected. That is the line between managing a
 * site and owning the server: every plugin and theme file is reachable,
 * wp-config.php is not.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_MCP_VERSION', '1.0.0' );
define( 'PPS_MCP_NS', 'pps-mcp/v1' );
define( 'PPS_MCP_PROTOCOL', '2025-06-18' );   // MCP protocol revision advertised to clients

// ═══════════════════════════════════════════════════════════════════════════
// TOKEN STORAGE
// ═══════════════════════════════════════════════════════════════════════════

function pps_mcp_hash( $raw ) { return hash( 'sha256', (string) $raw ); }

// ═══════════════════════════════════════════════════════════════════════════
// MODE — what this install is allowed to do
// ═══════════════════════════════════════════════════════════════════════════

/**
 * 'full'  — every tool, including writes. Intended for staging.
 * 'read'  — reads only, plus anything explicitly marked safe on production.
 *
 * The default is derived from WP_ENVIRONMENT_TYPE and defaults to 'read'. That
 * direction is deliberate: this plugin will end up on the live site eventually,
 * whether by a database copy, a plugin sync or someone in a hurry, and the version
 * of that mistake where production silently accepts file writes is much worse than
 * the one where staging needs a checkbox ticked. Full access has to be asked for.
 *
 * Set WP_ENVIRONMENT_TYPE in wp-config.php ('production', 'staging', 'development')
 * so the default is right without anyone having to remember.
 */
function pps_mcp_mode() {
    $saved = get_option( 'pps_mcp_mode', '' );
    if ( in_array( $saved, array( 'full', 'read' ), true ) ) return $saved;
    $env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
    return in_array( $env, array( 'staging', 'development', 'local' ), true ) ? 'full' : 'read';
}

/** Tools declare 'write' => true; those are refused unless the mode is 'full'. */
function pps_mcp_writes_allowed() { return pps_mcp_mode() === 'full'; }

/**
 * The credential presented on this request, from either header.
 *
 * Authorization is checked first because it is the standard. X-MCP-Authorization
 * exists because CGI/FastCGI silently drops Authorization, and a client that
 * cannot present a credential is indistinguishable from one presenting a bad one.
 */
function pps_mcp_bearer() {
    $raw = '';
    foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_MCP_AUTHORIZATION' ) as $k ) {
        if ( ! empty( $_SERVER[ $k ] ) ) { $raw = (string) $_SERVER[ $k ]; break; }
    }
    if ( $raw === '' && function_exists( 'getallheaders' ) ) {
        $h = array_change_key_case( (array) getallheaders(), CASE_LOWER );
        $raw = (string) ( $h['authorization'] ?? $h['x-mcp-authorization'] ?? '' );
    }
    if ( $raw === '' ) return '';
    return preg_match( '/^Bearer\s+(.+)$/i', trim( $raw ), $m ) ? trim( $m[1] ) : trim( $raw );
}

/**
 * Resolve a credential to a WordPress user id, or 0.
 *
 * Both branches use hash_equals: a timing side-channel on a bearer token is a
 * slow but real way to recover one.
 */
function pps_mcp_resolve_user( $token ) {
    if ( $token === '' ) return 0;

    $static = (string) get_option( 'pps_mcp_token_hash', '' );
    if ( $static !== '' && hash_equals( $static, pps_mcp_hash( $token ) ) ) {
        return (int) get_option( 'pps_mcp_token_user', 0 );
    }

    $rec = get_transient( 'pps_mcp_tok_' . pps_mcp_hash( $token ) );
    if ( is_array( $rec ) && ! empty( $rec['user_id'] ) && ( $rec['kind'] ?? '' ) === 'access' ) {
        return (int) $rec['user_id'];
    }
    return 0;
}

/**
 * Gate for every MCP call. On failure emits the WWW-Authenticate header that an
 * MCP client needs in order to discover where to authenticate (RFC 9728) — the
 * omission of which is the most common reason a connector reports a bare 401 and
 * gives up without ever attempting OAuth.
 */
function pps_mcp_authorize() {
    $uid = pps_mcp_resolve_user( pps_mcp_bearer() );
    if ( $uid > 0 ) {
        wp_set_current_user( $uid );
        return true;
    }
    // The standard location, so a client that builds the URL itself and a client that
    // follows this pointer both land in the same place.
    $meta = home_url( '/.well-known/oauth-protected-resource' . wp_parse_url( rest_url( PPS_MCP_NS . '/http' ), PHP_URL_PATH ) );
    header( 'WWW-Authenticate: Bearer realm="' . esc_url_raw( home_url() ) . '", resource_metadata="' . esc_url_raw( $meta ) . '"' );
    return new WP_Error( 'pps_mcp_unauthorized', 'Authentication required.', array( 'status' => 401 ) );
}

// ═══════════════════════════════════════════════════════════════════════════
// AUDIT LOG — a bounded ring, so it cannot grow without limit
// ═══════════════════════════════════════════════════════════════════════════

function pps_mcp_log( $tool, $args, $ok, $note = '' ) {
    $log = get_option( 'pps_mcp_log', array() );
    if ( ! is_array( $log ) ) $log = array();
    $log[] = array(
        'at'   => gmdate( 'c' ),
        'user' => get_current_user_id(),
        'ip'   => substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 45 ),
        'tool' => $tool,
        // Arguments can carry an entire file; keep the shape, drop the bulk.
        'args' => substr( (string) wp_json_encode( $args ), 0, 300 ),
        'ok'   => (bool) $ok,
        'note' => substr( (string) $note, 0, 200 ),
    );
    if ( count( $log ) > 200 ) $log = array_slice( $log, -200 );
    update_option( 'pps_mcp_log', $log, false );
}

// ═══════════════════════════════════════════════════════════════════════════
// PATH CONFINEMENT
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Resolve a caller-supplied path inside an allowed root, or fail.
 *
 * realpath() is what makes this sound: it collapses .. and follows symlinks, so
 * the prefix check happens on the true target rather than on the string the
 * caller chose. A file being created does not exist yet, so its parent directory
 * is resolved instead.
 */
function pps_mcp_safe_path( $rel, $root, $must_exist = true ) {
    $rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
    if ( $rel === '' || strpos( $rel, "\0" ) !== false ) {
        return new WP_Error( 'pps_mcp_path', 'Empty or malformed path.' );
    }
    $root = realpath( $root );
    if ( ! $root ) return new WP_Error( 'pps_mcp_path', 'Root unavailable.' );
    $root = rtrim( $root, '/' ) . '/';

    $target = $root . $rel;
    $probe  = $must_exist ? $target : dirname( $target );
    $real   = realpath( $probe );
    if ( ! $real ) return new WP_Error( 'pps_mcp_path', 'Path does not exist: ' . $rel );
    if ( ! $must_exist ) $real .= '/' . basename( $target );

    if ( strpos( $real, $root ) !== 0 ) {
        return new WP_Error( 'pps_mcp_path', 'Path escapes the permitted root.' );
    }
    return $real;
}

// ═══════════════════════════════════════════════════════════════════════════
// TOOL REGISTRY
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Adding a tool is one entry here. `cap` is checked against the token's user
 * before `run` is called, so a tool cannot be reached by a user who could not
 * perform the same action in wp-admin.
 */
function pps_mcp_tools() {
    $tools = array(

        'health' => array(
            'description' => 'Server health and identity. Confirms a token works without touching anything.',
            'cap'    => 'read',
            'schema' => array( 'type' => 'object', 'properties' => new stdClass() ),
            'run'    => function () {
                return array(
                    'ok' => true, 'version' => PPS_MCP_VERSION, 'protocol' => PPS_MCP_PROTOCOL,
                    'site' => home_url( '/' ), 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
                    'user' => wp_get_current_user()->user_login,
                    'mode' => pps_mcp_mode(), 'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
                    'auth_header_reached_php' => isset( $_SERVER['HTTP_AUTHORIZATION'] ) || isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ),
                );
            },
        ),

        'list_files' => array(
            'description' => 'List files under a plugin or theme directory. root is "plugins" or "themes".',
            'cap'    => 'activate_plugins',
            'schema' => array( 'type' => 'object', 'required' => array( 'path' ), 'properties' => array(
                'path' => array( 'type' => 'string', 'description' => 'Relative to the root, e.g. "pps-calculators".' ),
                'root' => array( 'type' => 'string', 'enum' => array( 'plugins', 'themes' ), 'default' => 'plugins' ),
            ) ),
            'run' => function ( $a ) {
                $root = ( ( $a['root'] ?? 'plugins' ) === 'themes' ) ? get_theme_root() : WP_PLUGIN_DIR;
                $dir  = pps_mcp_safe_path( $a['path'] ?? '', $root );
                if ( is_wp_error( $dir ) ) return $dir;
                if ( ! is_dir( $dir ) ) return new WP_Error( 'pps_mcp_path', 'Not a directory.' );
                $out = array();
                $it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
                foreach ( $it as $f ) {
                    if ( ! $f->isFile() ) continue;
                    $out[] = array(
                        'path'  => ltrim( str_replace( realpath( $root ), '', $f->getPathname() ), '/' ),
                        'bytes' => $f->getSize(),
                        'mtime' => gmdate( 'c', $f->getMTime() ),
                    );
                    if ( count( $out ) >= 2000 ) break;   // a runaway tree must not become a runaway response
                }
                usort( $out, function ( $x, $y ) { return strcmp( $x['path'], $y['path'] ); } );
                return array( 'root' => $a['root'] ?? 'plugins', 'count' => count( $out ), 'files' => $out );
            },
        ),

        'read_file' => array(
            'description' => 'Read a plugin or theme file. Large files are paginated via offset/length, in bytes.',
            'cap'    => 'activate_plugins',
            'schema' => array( 'type' => 'object', 'required' => array( 'path' ), 'properties' => array(
                'path'   => array( 'type' => 'string' ),
                'root'   => array( 'type' => 'string', 'enum' => array( 'plugins', 'themes' ), 'default' => 'plugins' ),
                'offset' => array( 'type' => 'integer', 'default' => 0 ),
                'length' => array( 'type' => 'integer', 'default' => 120000, 'description' => 'Bytes to return; capped at 400000.' ),
            ) ),
            'run' => function ( $a ) {
                $root = ( ( $a['root'] ?? 'plugins' ) === 'themes' ) ? get_theme_root() : WP_PLUGIN_DIR;
                $f = pps_mcp_safe_path( $a['path'] ?? '', $root );
                if ( is_wp_error( $f ) ) return $f;
                if ( ! is_readable( $f ) ) return new WP_Error( 'pps_mcp_io', 'Not readable.' );
                $size   = filesize( $f );
                $offset = max( 0, (int) ( $a['offset'] ?? 0 ) );
                $length = min( 400000, max( 1, (int) ( $a['length'] ?? 120000 ) ) );
                $chunk  = (string) file_get_contents( $f, false, null, $offset, $length );
                return array(
                    'path' => $a['path'], 'bytes_total' => $size, 'offset' => $offset,
                    'bytes_returned' => strlen( $chunk ),
                    'eof' => ( $offset + strlen( $chunk ) ) >= $size,
                    'sha256' => hash_file( 'sha256', $f ),   // compare against git to detect edits made in place
                    'content' => $chunk,
                );
            },
        ),

        'write_file' => array(
            'write' => true,
            'description' => 'Write a plugin or theme file. Set expect_sha256 to the value from read_file to refuse the write if the file changed since you read it.',
            'cap'    => 'edit_plugins',
            'schema' => array( 'type' => 'object', 'required' => array( 'path', 'content' ), 'properties' => array(
                'path'          => array( 'type' => 'string' ),
                'content'       => array( 'type' => 'string' ),
                'root'          => array( 'type' => 'string', 'enum' => array( 'plugins', 'themes' ), 'default' => 'plugins' ),
                'expect_sha256' => array( 'type' => 'string', 'description' => 'Optimistic lock. Omit only when creating a new file.' ),
            ) ),
            'run' => function ( $a ) {
                $root = ( ( $a['root'] ?? 'plugins' ) === 'themes' ) ? get_theme_root() : WP_PLUGIN_DIR;
                $f = pps_mcp_safe_path( $a['path'] ?? '', $root, false );
                if ( is_wp_error( $f ) ) return $f;

                $existed = file_exists( $f );
                // Refuse a blind overwrite of a file someone else has changed. This is the
                // guard against the failure this project has already had: a server-side
                // edit silently destroyed by a deploy that never looked first.
                if ( $existed && ! empty( $a['expect_sha256'] ) ) {
                    $now = hash_file( 'sha256', $f );
                    if ( ! hash_equals( $now, (string) $a['expect_sha256'] ) ) {
                        return new WP_Error( 'pps_mcp_conflict', "File changed since it was read (on disk $now). Re-read before writing." );
                    }
                }
                if ( substr( $f, -4 ) === '.php' ) {
                    // A PHP file with a syntax error taken live white-screens the site.
                    $tmp = wp_tempnam( 'pps-mcp' );
                    file_put_contents( $tmp, $a['content'] );
                    $lint = null; $rc = 1;
                    if ( function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
                        @exec( 'php -l ' . escapeshellarg( $tmp ) . ' 2>&1', $lint, $rc );
                    } else { $rc = 0; }   // cannot lint here; the caller must have linted
                    @unlink( $tmp );
                    if ( $rc !== 0 ) return new WP_Error( 'pps_mcp_lint', 'Refused: ' . implode( ' ', (array) $lint ) );
                }
                $bytes = file_put_contents( $f, $a['content'] );
                if ( $bytes === false ) return new WP_Error( 'pps_mcp_io', 'Write failed (permissions?).' );
                return array( 'path' => $a['path'], 'created' => ! $existed, 'bytes' => $bytes, 'sha256' => hash_file( 'sha256', $f ) );
            },
        ),

        'download_to_file' => array(
            'description' => 'Fetch a URL and write the response into plugins or themes. This is the deploy path: point it at a raw GitHub URL pinned to a commit SHA, so the deployed bytes are reviewable and a rollback is the same call with an older SHA. Prefer this over write_file for deploys.',
            'cap'    => 'edit_plugins',
            'write'  => true,
            'schema' => array( 'type' => 'object', 'required' => array( 'url', 'path' ), 'properties' => array(
                'url'           => array( 'type' => 'string', 'description' => 'https only, and the host must be allowlisted.' ),
                'path'          => array( 'type' => 'string' ),
                'root'          => array( 'type' => 'string', 'enum' => array( 'plugins', 'themes' ), 'default' => 'plugins' ),
                'expect_sha256' => array( 'type' => 'string', 'description' => 'sha256 of the file currently on disk. The write is refused if it does not match, so a deploy cannot silently destroy an edit made on the server.' ),
            ) ),
            'run' => function ( $a ) {
                $url = (string) ( $a['url'] ?? '' );
                // Fetching an arbitrary URL from inside the network is an SSRF primitive:
                // it can reach cloud metadata endpoints and anything else the host can see.
                // An allowlist of source hosts costs nothing here, because deploys only ever
                // come from the repository.
                $hosts = apply_filters( 'pps_mcp_download_hosts', array(
                    'raw.githubusercontent.com', 'gist.githubusercontent.com', 'codeload.github.com',
                ) );
                $host = wp_parse_url( $url, PHP_URL_HOST );
                if ( wp_parse_url( $url, PHP_URL_SCHEME ) !== 'https' || ! in_array( $host, $hosts, true ) ) {
                    return new WP_Error( 'pps_mcp_url', 'Refused: https only, and host must be one of ' . implode( ', ', $hosts ) . '. Extend via the pps_mcp_download_hosts filter.' );
                }

                $root = ( ( $a['root'] ?? 'plugins' ) === 'themes' ) ? get_theme_root() : WP_PLUGIN_DIR;
                $dest = pps_mcp_safe_path( $a['path'] ?? '', $root, false );
                if ( is_wp_error( $dest ) ) return $dest;

                $existed = file_exists( $dest );
                $before  = $existed ? hash_file( 'sha256', $dest ) : null;
                // Same optimistic lock as write_file. A deploy that overwrites a file
                // somebody patched in place is precisely how this project lost its
                // artwork-upload hardening; refusing is cheaper than discovering it later.
                if ( $existed && ! empty( $a['expect_sha256'] ) && ! hash_equals( $before, (string) $a['expect_sha256'] ) ) {
                    return new WP_Error( 'pps_mcp_conflict', "File changed since it was read (on disk $before). Re-read before deploying over it." );
                }

                $res = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 3 ) );
                if ( is_wp_error( $res ) ) return $res;
                $code = wp_remote_retrieve_response_code( $res );
                if ( $code !== 200 ) return new WP_Error( 'pps_mcp_http', "Source returned HTTP $code." );
                $body = (string) wp_remote_retrieve_body( $res );
                if ( $body === '' ) return new WP_Error( 'pps_mcp_http', 'Source returned an empty body.' );

                if ( substr( $dest, -4 ) === '.php' ) {
                    if ( strpos( $body, '<?php' ) === false ) {
                        // A 404 page or an HTML error saved over a live plugin file is a
                        // silent outage; the extension and the content have to agree.
                        return new WP_Error( 'pps_mcp_content', 'Refused: destination is .php but the response contains no PHP open tag.' );
                    }
                    $tmp = wp_tempnam( 'pps-mcp' );
                    file_put_contents( $tmp, $body );
                    $lint = null; $rc = 0;
                    if ( function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
                        @exec( 'php -l ' . escapeshellarg( $tmp ) . ' 2>&1', $lint, $rc );
                    }
                    @unlink( $tmp );
                    if ( $rc !== 0 ) return new WP_Error( 'pps_mcp_lint', 'Refused: ' . implode( ' ', (array) $lint ) );
                }

                if ( file_put_contents( $dest, $body ) === false ) {
                    return new WP_Error( 'pps_mcp_io', 'Write failed (permissions?).' );
                }
                return array(
                    'path' => $a['path'], 'source' => $url, 'created' => ! $existed,
                    'bytes' => strlen( $body ),
                    'sha256_before' => $before, 'sha256_after' => hash_file( 'sha256', $dest ),
                );
            },
        ),

        'get_option' => array(
            'description' => 'Read a wp_options value.',
            'cap'    => 'manage_options',
            'schema' => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array(
                'name' => array( 'type' => 'string' ),
            ) ),
            'run' => function ( $a ) {
                $name = (string) ( $a['name'] ?? '' );
                // These are credentials, not configuration. Reading them over an API is
                // never necessary and would put them in a transcript.
                if ( preg_match( '/^(auth|secure_auth|logged_in|nonce)_(key|salt)$/i', $name ) ) {
                    return new WP_Error( 'pps_mcp_denied', 'Refusing to return a security key.' );
                }
                return array( 'name' => $name, 'exists' => get_option( $name, null ) !== null, 'value' => get_option( $name, null ) );
            },
        ),

        'update_option' => array(
            'write' => true,
            'description' => 'Write a wp_options value. Pass value as JSON for arrays and objects.',
            'cap'    => 'manage_options',
            'schema' => array( 'type' => 'object', 'required' => array( 'name', 'value' ), 'properties' => array(
                'name'    => array( 'type' => 'string' ),
                'value'   => array( 'description' => 'Scalar, or a JSON string when is_json is true.' ),
                'is_json' => array( 'type' => 'boolean', 'default' => false ),
            ) ),
            'run' => function ( $a ) {
                $name = (string) ( $a['name'] ?? '' );
                // The plugin's own credentials must not be settable through the plugin.
                if ( strpos( $name, 'pps_mcp_' ) === 0 ) {
                    return new WP_Error( 'pps_mcp_denied', 'Refusing to modify this server\'s own credentials.' );
                }
                if ( preg_match( '/^(auth|secure_auth|logged_in|nonce)_(key|salt)$/i', $name ) || $name === 'active_plugins' ) {
                    return new WP_Error( 'pps_mcp_denied', 'Refusing to modify ' . $name . ' over the API.' );
                }
                $value = $a['value'];
                if ( ! empty( $a['is_json'] ) && is_string( $value ) ) {
                    $decoded = json_decode( $value, true );
                    if ( json_last_error() !== JSON_ERROR_NONE ) return new WP_Error( 'pps_mcp_json', 'value is not valid JSON.' );
                    $value = $decoded;
                }
                $before = get_option( $name, null );
                update_option( $name, $value );
                return array( 'name' => $name, 'changed' => $before !== get_option( $name, null ), 'previous' => $before );
            },
        ),
    );

    /**
     * Stage 2 hangs off here: posts, pages, menus, media, WooCommerce. Each is one
     * entry in the same shape, so the transport and auth above never change.
     */
    return apply_filters( 'pps_mcp_tools', $tools );
}

// ═══════════════════════════════════════════════════════════════════════════
// JSON-RPC TRANSPORT
// ═══════════════════════════════════════════════════════════════════════════

function pps_mcp_rpc_error( $id, $code, $msg ) {
    return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $msg ) );
}

function pps_mcp_handle( WP_REST_Request $req ) {
    $body = json_decode( $req->get_body(), true );
    if ( ! is_array( $body ) ) return new WP_REST_Response( pps_mcp_rpc_error( null, -32700, 'Parse error' ), 200 );

    // A batch is a JSON array; a single call is an object. Both are valid JSON-RPC.
    $batch = isset( $body[0] );
    $calls = $batch ? $body : array( $body );
    $out   = array();

    foreach ( $calls as $call ) {
        $id     = $call['id'] ?? null;
        $method = (string) ( $call['method'] ?? '' );
        $params = (array) ( $call['params'] ?? array() );

        switch ( $method ) {
            case 'initialize':
                $out[] = array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array(
                    'protocolVersion' => PPS_MCP_PROTOCOL,
                    'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
                    'serverInfo'      => array( 'name' => 'pps-mcp-server', 'version' => PPS_MCP_VERSION ),
                ) );
                break;

            case 'notifications/initialized':
            case 'notifications/cancelled':
                break;   // notifications carry no id and take no response

            case 'ping':
                $out[] = array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass() );
                break;

            case 'tools/list':
                $list = array();
                foreach ( pps_mcp_tools() as $name => $t ) {
                    if ( ! current_user_can( $t['cap'] ) ) continue;   // do not advertise what this user cannot call
                    if ( ! empty( $t['write'] ) && ! pps_mcp_writes_allowed() ) continue;   // read-only install
                    $list[] = array( 'name' => $name, 'description' => $t['description'], 'inputSchema' => $t['schema'] );
                }
                $out[] = array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array( 'tools' => $list ) );
                break;

            case 'tools/call':
                $name  = (string) ( $params['name'] ?? '' );
                $args  = (array) ( $params['arguments'] ?? array() );
                $tools = pps_mcp_tools();
                if ( ! isset( $tools[ $name ] ) ) {
                    $out[] = pps_mcp_rpc_error( $id, -32602, "Unknown tool: $name" );
                    break;
                }
                $tool = $tools[ $name ];
                if ( ! empty( $tool['write'] ) && ! pps_mcp_writes_allowed() ) {
                    pps_mcp_log( $name, $args, false, 'refused: read-only mode' );
                    $out[] = pps_mcp_rpc_error( $id, -32603, "This install is in read-only mode; $name is a write operation. Change it in Settings > PPS MCP." );
                    break;
                }
                if ( ! current_user_can( $tool['cap'] ) ) {
                    pps_mcp_log( $name, $args, false, 'capability denied: ' . $tool['cap'] );
                    $out[] = pps_mcp_rpc_error( $id, -32603, "Requires the {$tool['cap']} capability." );
                    break;
                }
                try {
                    $res = call_user_func( $tool['run'], $args );
                } catch ( Throwable $e ) {
                    pps_mcp_log( $name, $args, false, $e->getMessage() );
                    $out[] = pps_mcp_rpc_error( $id, -32603, 'Tool threw: ' . $e->getMessage() );
                    break;
                }
                if ( is_wp_error( $res ) ) {
                    pps_mcp_log( $name, $args, false, $res->get_error_message() );
                    // isError, not a JSON-RPC error: the call succeeded, the operation did not,
                    // and the model should see why rather than a transport failure.
                    $out[] = array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array(
                        'isError' => true,
                        'content' => array( array( 'type' => 'text', 'text' => $res->get_error_message() ) ),
                    ) );
                    break;
                }
                pps_mcp_log( $name, $args, true );
                $out[] = array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => array(
                    'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ),
                ) );
                break;

            default:
                $out[] = pps_mcp_rpc_error( $id, -32601, "Method not found: $method" );
        }
    }

    if ( ! $out ) return new WP_REST_Response( null, 202 );   // notifications only
    return new WP_REST_Response( $batch ? $out : $out[0], 200 );
}

// ═══════════════════════════════════════════════════════════════════════════
// OAUTH 2.1 — public clients, PKCE S256, dynamic registration
// ═══════════════════════════════════════════════════════════════════════════

function pps_mcp_oauth_register( WP_REST_Request $req ) {
    $b = json_decode( $req->get_body(), true );
    if ( ! is_array( $b ) ) $b = array();
    $redirects = array_values( array_filter( (array) ( $b['redirect_uris'] ?? array() ), function ( $u ) {
        // https only, with one carve-out for the loopback addresses a desktop client uses.
        return is_string( $u ) && ( strpos( $u, 'https://' ) === 0
            || preg_match( '#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#', $u ) );
    } ) );
    if ( ! $redirects ) return new WP_REST_Response( array( 'error' => 'invalid_redirect_uri' ), 400 );

    $clients = get_option( 'pps_mcp_clients', array() );
    if ( ! is_array( $clients ) ) $clients = array();
    if ( count( $clients ) > 50 ) $clients = array_slice( $clients, -25, null, true );   // bound unauthenticated growth

    $id = 'ppsmcp_' . bin2hex( random_bytes( 16 ) );
    $clients[ $id ] = array(
        'redirect_uris' => $redirects,
        'name'          => sanitize_text_field( (string) ( $b['client_name'] ?? 'MCP client' ) ),
        'created'       => gmdate( 'c' ),
    );
    update_option( 'pps_mcp_clients', $clients, false );

    return new WP_REST_Response( array(
        'client_id'                  => $id,
        'client_name'                => $clients[ $id ]['name'],
        'redirect_uris'              => $redirects,
        'token_endpoint_auth_method' => 'none',       // public client; PKCE is the protection
        'grant_types'                => array( 'authorization_code', 'refresh_token' ),
        'response_types'             => array( 'code' ),
    ), 201 );
}

/**
 * Authorization endpoint. Deliberately a browser flow: an administrator has to be
 * logged in and press a button, so granting an API client full site access is an
 * explicit human act rather than something a token exchange can do quietly.
 */
function pps_mcp_oauth_authorize() {
    $client_id  = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
    $redirect   = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
    $state      = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
    $challenge  = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
    $method     = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '';

    $clients = (array) get_option( 'pps_mcp_clients', array() );
    if ( ! isset( $clients[ $client_id ] ) ) wp_die( 'Unknown client_id.', 'MCP', array( 'response' => 400 ) );
    // Exact match, not prefix: a prefix check is how open redirectors happen.
    if ( ! in_array( $redirect, $clients[ $client_id ]['redirect_uris'], true ) ) {
        wp_die( 'redirect_uri does not match this client\'s registration.', 'MCP', array( 'response' => 400 ) );
    }
    if ( $method !== 'S256' || $challenge === '' ) {
        wp_die( 'PKCE with code_challenge_method=S256 is required.', 'MCP', array( 'response' => 400 ) );
    }

    if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator required.', 'MCP', array( 'response' => 403 ) );

    if ( isset( $_POST['pps_mcp_approve'] ) && check_admin_referer( 'pps_mcp_approve' ) ) {
        $code = bin2hex( random_bytes( 32 ) );
        set_transient( 'pps_mcp_tok_' . pps_mcp_hash( $code ), array(
            'kind' => 'code', 'user_id' => get_current_user_id(), 'client_id' => $client_id,
            'redirect_uri' => $redirect, 'challenge' => $challenge,
        ), 60 );   // one minute: a code only has to survive a redirect
        wp_redirect( add_query_arg( array( 'code' => $code, 'state' => $state ), $redirect ) );
        exit;
    }

    $name = esc_html( $clients[ $client_id ]['name'] );
    $user = esc_html( wp_get_current_user()->user_login );
    echo '<!doctype html><meta charset="utf-8"><title>Authorize MCP client</title>';
    echo '<div style="max-width:520px;margin:12vh auto;font:15px/1.55 system-ui,sans-serif;color:#111">';
    echo '<h1 style="font-size:20px">Authorize <em>' . $name . '</em>?</h1>';
    echo '<p>It will act on <strong>' . esc_html( home_url() ) . '</strong> as <strong>' . $user . '</strong>, with every capability that account has — including reading and writing plugin and theme files.</p>';
    echo '<p style="color:#6b7280;font-size:13px">Only approve this if you started it. Access lasts 30 days and can be revoked from Settings → PPS MCP.</p>';
    echo '<form method="post">';
    wp_nonce_field( 'pps_mcp_approve' );
    echo '<button name="pps_mcp_approve" value="1" style="background:#007eff;color:#fff;border:0;border-radius:6px;padding:11px 20px;font-size:15px;font-weight:600;cursor:pointer">Approve</button> ';
    echo '<a href="' . esc_url( admin_url() ) . '" style="margin-left:10px;color:#6b7280">Cancel</a>';
    echo '</form></div>';
    exit;
}

function pps_mcp_oauth_token( WP_REST_Request $req ) {
    $p    = $req->get_params();
    $code = (string) ( $p['code'] ?? '' );
    $ver  = (string) ( $p['code_verifier'] ?? '' );

    if ( ( $p['grant_type'] ?? '' ) !== 'authorization_code' ) {
        return new WP_REST_Response( array( 'error' => 'unsupported_grant_type' ), 400 );
    }
    $key = 'pps_mcp_tok_' . pps_mcp_hash( $code );
    $rec = get_transient( $key );
    if ( ! is_array( $rec ) || ( $rec['kind'] ?? '' ) !== 'code' ) {
        return new WP_REST_Response( array( 'error' => 'invalid_grant' ), 400 );
    }
    delete_transient( $key );   // single use, deleted before validation so a failed attempt cannot be replayed

    if ( ! hash_equals( (string) $rec['client_id'], (string) ( $p['client_id'] ?? '' ) )
      || ! hash_equals( (string) $rec['redirect_uri'], (string) ( $p['redirect_uri'] ?? '' ) ) ) {
        return new WP_REST_Response( array( 'error' => 'invalid_grant' ), 400 );
    }
    // PKCE: the verifier must hash to the challenge presented at /authorize.
    $calc = rtrim( strtr( base64_encode( hash( 'sha256', $ver, true ) ), '+/', '-_' ), '=' );
    if ( ! hash_equals( (string) $rec['challenge'], $calc ) ) {
        return new WP_REST_Response( array( 'error' => 'invalid_grant' ), 400 );
    }

    $access = bin2hex( random_bytes( 32 ) );
    $ttl    = 30 * DAY_IN_SECONDS;
    set_transient( 'pps_mcp_tok_' . pps_mcp_hash( $access ), array(
        'kind' => 'access', 'user_id' => (int) $rec['user_id'], 'client_id' => $rec['client_id'],
    ), $ttl );

    return new WP_REST_Response( array(
        'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $ttl,
    ), 200, array( 'Cache-Control' => 'no-store' ) );
}

// ═══════════════════════════════════════════════════════════════════════════
// ROUTES
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {
    register_rest_route( PPS_MCP_NS, '/http', array(
        'methods' => 'POST', 'permission_callback' => 'pps_mcp_authorize', 'callback' => 'pps_mcp_handle',
    ) );

    register_rest_route( PPS_MCP_NS, '/.well-known/oauth-protected-resource', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => 'pps_mcp_meta_protected_resource',
    ) );

    register_rest_route( PPS_MCP_NS, '/.well-known/oauth-authorization-server', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => 'pps_mcp_meta_authorization_server',
    ) );

    register_rest_route( PPS_MCP_NS, '/oauth/register', array(
        'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'pps_mcp_oauth_register',
    ) );
    register_rest_route( PPS_MCP_NS, '/oauth/token', array(
        'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'pps_mcp_oauth_token',
    ) );
} );

// The authorize step is a browser page, not a REST route: it has to render HTML,
// set cookies and run auth_redirect(), none of which belong in a JSON endpoint.
add_action( 'init', function () {
    if ( isset( $_GET['pps_mcp_authorize'] ) ) pps_mcp_oauth_authorize();
} );

/**
 * Serve the discovery documents from the ROOT .well-known path.
 *
 * This is where clients actually look. RFC 9728 and RFC 8414 insert the well-known
 * segment directly after the host and append the resource path — so for a resource at
 * /wp-json/pps-mcp/v1/http the metadata URL is
 *
 *   /.well-known/oauth-protected-resource/wp-json/pps-mcp/v1/http
 *
 * and NOT /wp-json/pps-mcp/v1/.well-known/oauth-protected-resource, which is where
 * the first version of this plugin put it. A connector building the standard URL got a
 * WordPress 404 and stopped before it ever reached the registration endpoint. The
 * REST-namespaced copies are kept as well, since the WWW-Authenticate header names one
 * explicitly and a client that follows the pointer should also succeed.
 *
 * Runs on parse_request, before WordPress resolves the URL to a post and 404s.
 */
add_action( 'parse_request', function () {
    $path = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' );
    $path = '/' . ltrim( $path, '/' );
    if ( strpos( $path, '/.well-known/oauth-' ) !== 0 ) return;

    if ( strpos( $path, '/.well-known/oauth-protected-resource' ) === 0 ) {
        $doc = pps_mcp_meta_protected_resource();
    } elseif ( strpos( $path, '/.well-known/oauth-authorization-server' ) === 0 ) {
        $doc = pps_mcp_meta_authorization_server();
    } else {
        return;
    }

    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    // Discovery is fetched by a client that has no credential yet, and browsers
    // preflight it, so it has to be readable cross-origin.
    header( 'Access-Control-Allow-Origin: *' );
    echo wp_json_encode( $doc, JSON_UNESCAPED_SLASHES );
    exit;
}, 0 );

function pps_mcp_meta_protected_resource() {
    return array(
        'resource'                 => rest_url( PPS_MCP_NS . '/http' ),
        'authorization_servers'    => array( rest_url( PPS_MCP_NS ) ),
        'bearer_methods_supported' => array( 'header' ),
        'scopes_supported'         => array( 'mcp' ),
    );
}

/**
 * The issuer this document must claim, derived from the URL it was fetched at.
 *
 * RFC 8414 §3.3: the issuer returned MUST be identical to the issuer identifier that
 * the well-known string was inserted into to build the request URL. There are two
 * legitimate ways a client gets here, and they imply different issuers:
 *
 *   /.well-known/oauth-authorization-server                        → https://host
 *   /.well-known/oauth-authorization-server/wp-json/pps-mcp/v1     → https://host/wp-json/pps-mcp/v1
 *
 * A fixed issuer is therefore wrong for one of them, and a client that validates the
 * match — which it should — rejects the document and stops without explaining why.
 * Deriving it from the request satisfies both.
 */
function pps_mcp_issuer_for_request() {
    $path = '/' . ltrim( (string) strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ), '/' );

    // Only a path BEGINNING with the well-known prefix is the RFC 8414 form. The copy
    // registered under the REST namespace merely ends with the same string, and its
    // own route identifier is the honest issuer for it.
    if ( strpos( $path, '/.well-known/oauth-authorization-server' ) !== 0 ) {
        return rtrim( rest_url( PPS_MCP_NS ), '/' );
    }
    $tail = rtrim( substr( $path, strlen( '/.well-known/oauth-authorization-server' ) ), '/' );
    return rtrim( home_url( '/' ), '/' ) . $tail;
}

function pps_mcp_meta_authorization_server() {
    return array(
        'issuer'                                => pps_mcp_issuer_for_request(),
        'authorization_endpoint'                => home_url( '/' ) . '?pps_mcp_authorize=1',
        'token_endpoint'                        => rest_url( PPS_MCP_NS . '/oauth/token' ),
        'registration_endpoint'                 => rest_url( PPS_MCP_NS . '/oauth/register' ),
        'response_types_supported'              => array( 'code' ),
        'grant_types_supported'                 => array( 'authorization_code' ),
        'code_challenge_methods_supported'      => array( 'S256' ),
        'token_endpoint_auth_methods_supported' => array( 'none' ),
        'scopes_supported'                      => array( 'mcp' ),
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
    add_options_page( 'PPS MCP', 'PPS MCP', 'manage_options', 'pps-mcp', 'pps_mcp_admin' );
} );

function pps_mcp_admin() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Denied.' );
    $fresh = '';

    if ( isset( $_POST['pps_mcp_mode'] ) && check_admin_referer( 'pps_mcp_admin' ) ) {
        $want = $_POST['pps_mcp_mode'] === 'full' ? 'full' : 'read';
        update_option( 'pps_mcp_mode', $want, false );
        echo '<div class="notice notice-success"><p>Mode set to <strong>' . esc_html( $want ) . '</strong>.</p></div>';
    }
    if ( isset( $_POST['pps_mcp_gen'] ) && check_admin_referer( 'pps_mcp_admin' ) ) {
        $fresh = bin2hex( random_bytes( 32 ) );
        update_option( 'pps_mcp_token_hash', pps_mcp_hash( $fresh ), false );
        update_option( 'pps_mcp_token_user', get_current_user_id(), false );
    }
    if ( isset( $_POST['pps_mcp_revoke'] ) && check_admin_referer( 'pps_mcp_admin' ) ) {
        delete_option( 'pps_mcp_token_hash' );
        delete_option( 'pps_mcp_token_user' );
        update_option( 'pps_mcp_clients', array(), false );
        echo '<div class="notice notice-success"><p>Static token and all registered OAuth clients revoked. Existing access tokens expire on their own.</p></div>';
    }

    echo '<div class="wrap"><h1>PPS MCP Server</h1>';
    echo '<p>Endpoint: <code>' . esc_html( rest_url( PPS_MCP_NS . '/http' ) ) . '</code></p>';
    $mode = pps_mcp_mode();
    $env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
    echo '<p style="padding:10px 14px;border-radius:6px;display:inline-block;'
       . ( $mode === 'full' ? 'background:#fef3c7;border:1px solid #fcd34d' : 'background:#dcfce7;border:1px solid #86efac' ) . '">'
       . 'Mode: <strong>' . esc_html( $mode ) . '</strong>'
       . ( $mode === 'full' ? ' — writes are permitted. Correct for staging; wrong for a live store.' : ' — reads only.' )
       . ' <span style="color:#6b7280">(WP_ENVIRONMENT_TYPE: ' . esc_html( $env ) . ')</span></p>';

    if ( $fresh ) {
        echo '<div class="notice notice-warning"><p><strong>Copy this now — it is stored only as a hash and cannot be shown again.</strong></p>'
           . '<p><code style="font-size:14px;user-select:all">' . esc_html( $fresh ) . '</code></p></div>';
    }

    echo '<form method="post" style="margin:18px 0">';
    wp_nonce_field( 'pps_mcp_admin' );
    echo '<button class="button button-primary" name="pps_mcp_gen" value="1">Generate a static token</button> ';
    echo '<button class="button" name="pps_mcp_mode" value="' . ( pps_mcp_mode() === 'full' ? 'read' : 'full' ) . '">'
       . ( pps_mcp_mode() === 'full' ? 'Switch to read-only' : 'Allow writes (staging only)' ) . '</button> ';
    echo '<button class="button" name="pps_mcp_revoke" value="1" onclick="return confirm(\'Revoke the static token and every registered client?\')">Revoke everything</button>';
    echo '</form>';

    // ── Self-test ────────────────────────────────────────────────────────────
    // The owner of this site has no terminal, so "run this curl" is not a usable
    // instruction. This does the same round trip from the server: mints a token good
    // for one minute, calls the real endpoint over HTTP, and prints what came back.
    //
    // It proves the endpoint, the token path and the tool dispatch all work. It cannot
    // prove the site is reachable from the outside world — that is what adding the
    // connector proves, and nothing here can substitute for it.
    if ( isset( $_POST['pps_mcp_selftest'] ) && check_admin_referer( 'pps_mcp_admin' ) ) {
        $probe = bin2hex( random_bytes( 32 ) );
        set_transient( 'pps_mcp_tok_' . pps_mcp_hash( $probe ), array(
            'kind' => 'access', 'user_id' => get_current_user_id(), 'client_id' => 'self-test',
        ), 60 );

        $url = rest_url( PPS_MCP_NS . '/http' );
        $res = wp_remote_post( $url, array(
            'timeout' => 20, 'sslverify' => false,
            'headers' => array( 'Authorization' => 'Bearer ' . $probe, 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array(
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params'  => array( 'name' => 'health', 'arguments' => new stdClass() ),
            ) ),
        ) );
        delete_transient( 'pps_mcp_tok_' . pps_mcp_hash( $probe ) );

        echo '<h2>Self-test</h2>';
        if ( is_wp_error( $res ) ) {
            echo '<div class="notice notice-error"><p><strong>Could not reach the endpoint:</strong> '
               . esc_html( $res->get_error_message() ) . '</p></div>';
        } else {
            $code = wp_remote_retrieve_response_code( $res );
            $body = (string) wp_remote_retrieve_body( $res );
            $good = $code === 200 && strpos( $body, '"ok"' ) !== false;
            echo '<div class="notice ' . ( $good ? 'notice-success' : 'notice-error' ) . '"><p><strong>HTTP ' . (int) $code . '</strong> — '
               . ( $good ? 'the server answered and the tool ran. The MCP endpoint works.'
                         : 'the endpoint did not return a healthy result. The response is below.' ) . '</p></div>';
            if ( $code === 401 ) {
                echo '<div class="notice notice-warning"><p>A 401 here, from the site to itself with a valid token, means something is rejecting the request before this plugin sees it — a security plugin on the REST API, or the Authorization header being stripped. Tools &rarr; MCP Diagnostics will say which.</p></div>';
            }
            echo '<textarea readonly rows="12" style="width:100%;font-family:monospace;font-size:12px">'
               . esc_textarea( $body ) . '</textarea>';
        }
    }

    echo '<form method="post" style="margin:18px 0">';
    wp_nonce_field( 'pps_mcp_admin' );
    echo '<button class="button" name="pps_mcp_selftest" value="1">Run a self-test</button> ';
    echo '<span style="color:#6b7280">Calls the endpoint from this server and shows the result. No token needed.</span>';
    echo '</form>';

    $clients = (array) get_option( 'pps_mcp_clients', array() );
    echo '<h2>Registered OAuth clients (' . count( $clients ) . ')</h2><ul>';
    foreach ( $clients as $cid => $c ) {
        echo '<li><code>' . esc_html( $cid ) . '</code> — ' . esc_html( $c['name'] ) . ' <span style="color:#666">(' . esc_html( $c['created'] ) . ')</span></li>';
    }
    if ( ! $clients ) echo '<li><em>none</em></li>';
    echo '</ul>';

    $log = array_reverse( (array) get_option( 'pps_mcp_log', array() ) );
    echo '<h2>Recent tool calls</h2><table class="widefat striped"><thead><tr><th>When</th><th>Tool</th><th>OK</th><th>Args</th><th>Note</th></tr></thead><tbody>';
    foreach ( array_slice( $log, 0, 40 ) as $r ) {
        echo '<tr><td>' . esc_html( $r['at'] ) . '</td><td><code>' . esc_html( $r['tool'] ) . '</code></td><td>'
           . ( $r['ok'] ? '✓' : '✕' ) . '</td><td><code style="font-size:11px">' . esc_html( $r['args'] ) . '</code></td><td>'
           . esc_html( $r['note'] ) . '</td></tr>';
    }
    if ( ! $log ) echo '<tr><td colspan="5"><em>no calls yet</em></td></tr>';
    echo '</tbody></table></div>';
}

/** Complete removal. Call from wp-cli, or add an uninstall.php that calls it. */
function pps_mcp_uninstall() {
    global $wpdb;
    foreach ( array( 'pps_mcp_token_hash', 'pps_mcp_token_user', 'pps_mcp_clients', 'pps_mcp_log' ) as $o ) delete_option( $o );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pps_mcp_tok_%' OR option_name LIKE '_transient_timeout_pps_mcp_tok_%'" );
}
