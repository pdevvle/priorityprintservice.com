<?php
/**
 * Plugin Name: PPS MCP Diagnostics
 * Description: Read-only report on why an MCP connector cannot authenticate against this site. Answers the questions that cannot be seen from outside: whether PHP ever receives the Authorization header, whether the MCP endpoint's 401 carries the WWW-Authenticate header a client needs to start OAuth, and what else is filtering the REST API.
 * Version: 1.0.0
 * Author: PPS
 *
 * WHY THIS EXISTS
 * ---------------
 * An MCP connector reported "connected" while delivering no tools, and every probe from
 * outside returned a bare 401. From outside you cannot tell the difference between:
 *
 *   (a) the Authorization header being stripped before PHP sees it — normal on
 *       Apache/LiteSpeed under CGI/FastCGI, and it makes every credential look invalid;
 *   (b) the 401 omitting the WWW-Authenticate header, which is what points a client at
 *       the authorization server (RFC 9728). Without it a client has nowhere to go; and
 *   (c) a security plugin short-circuiting REST before the MCP route runs.
 *
 * All three produce the same 401. This tells them apart.
 *
 * SAFETY
 * ------
 * - Reads only. No option is written, no file is touched, nothing is deleted.
 * - Requires either a logged-in administrator, or a shared secret stored in the
 *   `pps_diag_token` option and sent as `X-PPS-Diag`. The token is NEVER in this file:
 *   this repository is public, and a secret in source is a secret published.
 * - Every credential it finds is reported as present/absent with a length, never a value.
 *
 * REMOVAL
 * -------
 * Deactivate and delete once the connector works. It stores nothing, so deleting the
 * file is a complete uninstall — except the `pps_diag_token` option, if you set one:
 * `delete_option( 'pps_diag_token' )`.
 *
 * SETUP
 * -----
 * As an administrator, just visit:
 *   /wp-json/pps-diag/v1/report
 *
 * To call it without a session (e.g. from an external shell), first set a token:
 *   wp option update pps_diag_token "$(openssl rand -hex 24)"
 * then:
 *   curl -sS -H "X-PPS-Diag: <token>" https://<site>/wp-json/pps-diag/v1/report | python3 -m json.tool
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Administrator session, or a shared secret. Compared with hash_equals so the check
 * cannot be narrowed by timing.
 */
function pps_diag_may_view() {
    if ( current_user_can( 'manage_options' ) ) return true;

    $expected = (string) get_option( 'pps_diag_token', '' );
    if ( $expected === '' || strlen( $expected ) < 20 ) return false;   // unset or too weak to trust

    $given = '';
    if ( isset( $_SERVER['HTTP_X_PPS_DIAG'] ) ) $given = (string) $_SERVER['HTTP_X_PPS_DIAG'];
    if ( $given === '' ) return false;

    return hash_equals( $expected, $given );
}

/** Present/absent and a length. Never the value — this output may be pasted into a chat. */
function pps_diag_redact( $v ) {
    $v = (string) $v;
    if ( $v === '' ) return array( 'set' => false );
    return array( 'set' => true, 'length' => strlen( $v ), 'starts_with' => substr( $v, 0, 4 ) . '…' );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'pps-diag/v1', '/report', array(
        'methods'             => 'GET',
        'permission_callback' => 'pps_diag_may_view',
        'callback'            => 'pps_diag_report',
    ) );
} );

/**
 * The same report as a wp-admin page.
 *
 * The REST route above authenticates by cookie, and WordPress REST cookie auth
 * requires an X-WP-Nonce header — so pasting that URL into a browser returns
 * `rest_forbidden` 401 even while logged in as an administrator, which reads as the
 * plugin refusing you rather than as a missing nonce. An admin screen is inside the
 * normal admin auth flow and has no such requirement.
 *
 * Keep the REST route: it is the one that can be called with an explicit
 * `Authorization: Bearer` header, which is how you test whether that header survives
 * the trip to PHP at all — the single most useful thing this plugin measures.
 */
add_action( 'admin_menu', function () {
    add_management_page( 'MCP Diagnostics', 'MCP Diagnostics', 'manage_options', 'pps-diag', function () {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Denied.' );
        $report = pps_diag_report( new WP_REST_Request( 'GET', '/pps-diag/v1/report' ) );
        $data   = $report instanceof WP_REST_Response ? $report->get_data() : $report;

        echo '<div class="wrap"><h1>MCP Diagnostics</h1>';
        echo '<p>Read-only. Safe to copy and paste — credentials are reported as present/absent with a length, never as values.</p>';

        $auth = $data['authorization_header'] ?? array();
        $got  = ! empty( $auth['HTTP_AUTHORIZATION'] ) || ! empty( $auth['REDIRECT_HTTP_AUTHORIZATION'] ) || ! empty( $auth['getallheaders'] );
        echo '<div style="padding:12px 16px;margin:14px 0;border-radius:6px;background:' . ( $got ? '#dcfce7;border:1px solid #86efac' : '#fef3c7;border:1px solid #fcd34d' ) . '">';
        echo '<strong>Authorization header:</strong> ' . ( $got ? 'reached PHP on this request.' : 'not present on this request — expected, since a browser does not send one. Use the curl command below to test it properly.' );
        echo '</div>';

        $wa = $data['endpoints']['mcp_http']['www_authenticate'] ?? '(not probed)';
        $ok = $wa && strpos( $wa, 'ABSENT' ) === false;
        echo '<div style="padding:12px 16px;margin:14px 0;border-radius:6px;background:' . ( $ok ? '#dcfce7;border:1px solid #86efac' : '#fee2e2;border:1px solid #fca5a5' ) . '">';
        echo '<strong>MCP endpoint 401 carries WWW-Authenticate:</strong> ' . esc_html( $wa );
        if ( ! $ok ) echo '<br><span style="color:#991b1b">Without this header an MCP client has nowhere to discover the authorization server, so it reports a bare 401 and never attempts OAuth.</span>';
        echo '</div>';

        echo '<h2>Test whether the Authorization header survives</h2>';
        echo '<p>Run this from an external machine — a server reaching itself can bypass the edge firewall:</p>';
        echo '<pre style="background:#f6f7f7;padding:12px;overflow:auto;user-select:all">curl -sS -H \'Authorization: Bearer probe123\' \\
  \'' . esc_html( rest_url( 'pps-diag/v1/report' ) ) . '\' | head -c 400</pre>';
        echo '<p>That needs a token, since it has no browser session. Generate one over SSH:</p>';
        echo '<pre style="background:#f6f7f7;padding:12px;overflow:auto;user-select:all">wp option update pps_diag_token "$(openssl rand -hex 24)"</pre>';
        echo '<p>then add <code>-H "X-PPS-Diag: &lt;token&gt;"</code>. In that run, <code>authorization_header</code> should show at least one <code>true</code>. If all three are false, the header is being stripped and no credential can ever work.</p>';

        echo '<h2>Full report</h2>';
        echo '<textarea readonly rows="30" style="width:100%;font-family:monospace;font-size:12px">'
           . esc_textarea( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea>';
        echo '</div>';
    } );
} );

function pps_diag_report( WP_REST_Request $req ) {
    $out = array( 'generated' => gmdate( 'c' ), 'site' => home_url( '/' ) );

    // ── 1. Does the Authorization header survive the trip to PHP? ────────────────
    // The single most common cause of "every token is rejected". CGI and FastCGI do not
    // forward Authorization unless the server is told to, and WordPress then behaves as
    // though nothing was sent. REDIRECT_HTTP_AUTHORIZATION appearing alone is the
    // fingerprint of the .htaccess workaround already being in place.
    $hdrs = function_exists( 'getallheaders' ) ? array_change_key_case( (array) getallheaders(), CASE_LOWER ) : array();
    $out['authorization_header'] = array(
        'HTTP_AUTHORIZATION'          => isset( $_SERVER['HTTP_AUTHORIZATION'] ),
        'REDIRECT_HTTP_AUTHORIZATION' => isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ),
        'getallheaders'               => isset( $hdrs['authorization'] ),
        'note' => 'Send this request with `-H "Authorization: Bearer probe"`. If all three read false, the header is being stripped and no credential can ever work. Fix in .htaccess: RewriteCond %{HTTP:Authorization} . / RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
    );

    // ── 2. Server shape ──────────────────────────────────────────────────────────
    $out['server'] = array(
        'software'    => $_SERVER['SERVER_SOFTWARE'] ?? '(unknown)',
        'sapi'        => php_sapi_name(),
        'php'         => PHP_VERSION,
        'wp'          => get_bloginfo( 'version' ),
        'permalinks'  => get_option( 'permalink_structure' ) ?: '(plain — REST routes need pretty permalinks)',
        'rest_url'    => get_rest_url(),
        'is_ssl'      => is_ssl(),
        'home_vs_site'=> array( 'home' => home_url(), 'site' => site_url() ),
    );

    // ── 3. What is filtering REST before a route ever runs ───────────────────────
    // A security plugin on rest_authentication_errors returns 401 for everything and no
    // amount of MCP configuration will change it.
    global $wp_filter;
    $watch = array( 'rest_authentication_errors', 'rest_pre_dispatch', 'rest_post_dispatch', 'rest_send_nocache_headers' );
    $out['rest_filters'] = array();
    foreach ( $watch as $hook ) {
        $names = array();
        if ( isset( $wp_filter[ $hook ] ) ) {
            foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
                foreach ( $cbs as $cb ) {
                    $f = $cb['function'];
                    if ( is_string( $f ) )                    $names[] = "$prio:$f";
                    elseif ( is_array( $f ) && count($f) === 2 ) $names[] = "$prio:" . ( is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0] ) . '::' . $f[1];
                    else                                       $names[] = "$prio:(closure)";
                }
            }
        }
        $out['rest_filters'][ $hook ] = $names;
    }

    // ── 4. Which plugins are in play ─────────────────────────────────────────────
    if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $all    = get_plugins();
    $active = (array) get_option( 'active_plugins', array() );
    $interesting = array();
    foreach ( $all as $file => $meta ) {
        $hay = strtolower( $file . ' ' . ( $meta['Name'] ?? '' ) );
        if ( preg_match( '/mcp|ai[- ]?engine|meow|secur|firewall|jwt|oauth|limit|cloudflare|wordfence|itheme|sucuri/', $hay ) ) {
            $interesting[] = array(
                'file'    => $file,
                'name'    => $meta['Name'] ?? '',
                'version' => $meta['Version'] ?? '',
                'active'  => in_array( $file, $active, true ),
            );
        }
    }
    $out['plugins_of_interest'] = $interesting;
    $out['plugin_count'] = array( 'installed' => count( $all ), 'active' => count( $active ) );

    // ── 5. AI Engine's own MCP configuration, redacted ───────────────────────────
    // Option name has moved between releases, so try the known spellings rather than
    // reporting "not configured" for what is really "renamed".
    $ai = array();
    foreach ( array( 'mwai_options', 'mwai_option', 'ai_engine_options' ) as $opt ) {
        $val = get_option( $opt, null );
        if ( ! is_array( $val ) ) continue;
        $flat = array();
        foreach ( $val as $k => $v ) {
            if ( ! is_scalar( $v ) ) { $flat[ $k ] = '(' . gettype( $v ) . ')'; continue; }
            // Anything that smells like a credential is reported, never shown.
            $flat[ $k ] = preg_match( '/key|token|secret|password|bearer/i', $k )
                ? pps_diag_redact( $v ) : $v;
        }
        $ai[ $opt ] = $flat;
    }
    $out['ai_engine'] = $ai ?: '(no recognised AI Engine option found)';

    // ── 6. The question that outside probes cannot answer ────────────────────────
    // Does the MCP endpoint's 401 carry WWW-Authenticate? Without it an MCP client has
    // no way to discover the authorization server and gives up — which looks exactly
    // like a network failure from the client side. Loopback is fine here: the header is
    // generated by PHP, so it does not matter that this bypasses the edge firewall.
    $endpoints = array(
        'mcp_http'  => get_rest_url( null, 'mcp/v1/http' ),
        'discovery' => home_url( '/.well-known/oauth-protected-resource/wp-json/mcp/v1/http' ),
        'as_meta'   => home_url( '/.well-known/oauth-authorization-server' ),
    );
    $out['endpoints'] = array();
    foreach ( $endpoints as $label => $url ) {
        $args = array( 'timeout' => 12, 'sslverify' => false, 'redirection' => 0 );
        if ( $label === 'mcp_http' ) {
            $args['method'] = 'POST';
            $args['headers'] = array( 'Content-Type' => 'application/json' );
            $args['body'] = wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => new stdClass() ) );
        }
        $res = wp_remote_request( $url, $args );
        if ( is_wp_error( $res ) ) {
            $out['endpoints'][ $label ] = array( 'url' => $url, 'error' => $res->get_error_message() );
            continue;
        }
        $h = wp_remote_retrieve_headers( $res );
        $h = is_object( $h ) && method_exists( $h, 'getAll' ) ? $h->getAll() : (array) $h;
        $out['endpoints'][ $label ] = array(
            'url'               => $url,
            'status'            => wp_remote_retrieve_response_code( $res ),
            'www_authenticate'  => $h['www-authenticate'] ?? '(ABSENT — an MCP client cannot start OAuth without this)',
            'content_type'      => $h['content-type'] ?? '',
            'body_head'         => substr( (string) wp_remote_retrieve_body( $res ), 0, 400 ),
        );
    }

    // ── 7. Is the Authorization workaround already installed? ────────────────────
    $ht = ABSPATH . '.htaccess';
    $out['htaccess'] = file_exists( $ht ) && is_readable( $ht )
        ? array(
            'exists'          => true,
            'bytes'           => filesize( $ht ),
            'forwards_auth'   => (bool) preg_match( '/HTTP_AUTHORIZATION/i', (string) file_get_contents( $ht ) ),
          )
        : array( 'exists' => false, 'note' => 'Nginx-only stack, or unreadable — check the app-level Nginx config instead.' );

    return new WP_REST_Response( $out, 200 );
}
