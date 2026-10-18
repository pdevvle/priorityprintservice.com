<?php
/**
 * pps-missive-impose.php: the imposition tool inside the Missive panel (owner, 2026-10-18).
 *
 * Runs the bridge for real: this file is also the ROUTER of a `php -S` server, so uploads
 * arrive as real multipart uploads (is_uploaded_file) and downloads are streamed bytes. The
 * imposition side is the LIVE code, pulled from git — pps-imposition.php and imposition-tool.html
 * at 38d9be9 (v1.58, on production since 2026-10-18 06:10) for the helpers and the page —
 * so the rows this bridge builds are checked against the tool that actually reads them.
 *
 * Checks: the page needs the panel key and a real order; its token is good for that order
 * only; a call without the token, or with another order's, is refused; status and hide are
 * refused; a download is refused for any file outside the order's folder (the Drive
 * connection can read the whole Drive); an upload must be IMPOSED_/CLEAN_ PDF bytes for that
 * order and leaves a note; a call with no `pps_mpi` falls through to the wp-admin handlers.
 * Then a browser loads the page, imposes the order from its queue and files it to "Drive".
 *
 * Run: php tools-missive-impose-test.php      (needs git, curl, node + playwright)
 */

$STATE = getenv( 'PPS_MPI_STATE' ) ?: '';

/* ═════════ ROUTER MODE: one request of the fake WordPress ═════════ */
if ( PHP_SAPI === 'cli-server' ) {
    define( 'ABSPATH', __DIR__ . '/' ); define( 'MINUTE_IN_SECONDS', 60 );
    $GLOBALS['hooks'] = array();
    function add_action( $h, $cb, $p = 10 ) { $GLOBALS['hooks'][ $h ][ $p ][] = $cb; }
    function add_filter() {} function add_submenu_page() {}
    function apply_filters( $h, $v ) { return $v; }
    function do_hook( $h ) { $l = $GLOBALS['hooks'][ $h ] ?? array(); ksort( $l ); foreach ( $l as $cbs ) foreach ( $cbs as $cb ) $cb(); }
    function st() { return json_decode( file_get_contents( getenv( 'PPS_MPI_STATE' ) ), true ); }
    function st_put( $s ) { file_put_contents( getenv( 'PPS_MPI_STATE' ), json_encode( $s ) ); }
    function wp_unslash( $v ) { return $v; }
    function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
    function sanitize_file_name( $v ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $v ); }
    function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
    function wp_send_json( $v, $code = 200 ) { http_response_code( $code ); header( 'Content-Type: application/json' ); echo json_encode( $v ); exit; }
    function wp_send_json_success( $d = null, $c = 200 ) { wp_send_json( array( 'success' => true, 'data' => $d ), $c ); }
    function wp_send_json_error( $d = null, $c = 200 ) { wp_send_json( array( 'success' => false, 'data' => $d ), $c ); }
    function nocache_headers() {} function status_header( $c ) { http_response_code( $c ); }
    function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
    function add_query_arg( $k, $v, $u ) { return $u . ( strpos( $u, '?' ) === false ? '?' : '&' ) . $k . '=' . rawurlencode( $v ); }
    function get_transient() { return false; } function set_transient() { return true; }
    function get_option( $k, $d = false ) { $s = st(); return $s['options'][ $k ] ?? $d; }
    function update_option( $k, $v ) { $s = st(); $s['options'][ $k ] = $v; st_put( $s ); return true; }
    function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
    function wp_max_upload_size() { return 67108864; }
    function esc_url( $u ) { return $u; }
    function delete_transient( $k ) { $s = st(); $s['cleared'][] = $k; st_put( $s ); return true; }
    function is_wp_error( $x ) { return false; }
    function wp_tempnam( $p ) { return tempnam( sys_get_temp_dir(), $p ); }
    function wp_remote_get( $url, $a ) {
        $s = st();
        if ( strpos( $url, '/drive/v3/files?q=' ) !== false ) { // a folder listing (the live pps_impose_drive_list)
            parse_str( parse_url( $url, PHP_URL_QUERY ), $q ); preg_match( "/^'([^']+)'/", $q['q'], $m );
            $s['listed'][] = $m[1]; st_put( $s );
            return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'files' => $s['folders'][ $m[1] ] ?? array() ) ) );
        }
        $s['fetched'][] = $url; st_put( $s );
        copy( $s['pdf'], $a['filename'] ); return array( 'response' => array( 'code' => 200 ), 'filename' => $a['filename'] );
    }
    function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
    function wp_remote_retrieve_body( $r ) { return $r['body']; }
    function pps_gdrive_get_access_token() { return 'tok'; }
    function pps_gdrive_is_connected() { return true; }
    function pps_gdrive_upload_file( $tmp, $name, $folder ) {
        $s = st(); $s['uploads'][] = array( 'name' => $name, 'folder' => $folder, 'bytes' => filesize( $tmp ), 'head' => file_get_contents( $tmp, false, null, 0, 5 ) ); st_put( $s );
        return 'newFILE' . count( $s['uploads'] );
    }
    function pps_get_config() { return array(); }
    // the panel's key, as pps-missive-panel.php checks it
    function pps_mp_key() { return 'PANELKEY'; }
    function pps_mp_key_ok( $g ) { return is_string( $g ) && $g !== '' && hash_equals( 'PANELKEY', $g ); }
    class TI { public $n; public $m; function __construct( $n, $m ) { $this->n = $n; $this->m = $m; }
        function get_name() { return $this->n; } function get_meta( $k ) { return $this->m[ $k ] ?? ''; } function get_quantity() { return 1; } function get_product_id() { return 0; } }
    class TO { public $id; public $meta; public $items;
        function __construct( $id, $meta, $items ) { $this->id = $id; $this->meta = $meta; $this->items = $items; }
        function get_id() { return $this->id; } function get_status() { return 'processing'; } function get_items() { return $this->items; }
        function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } function get_edit_order_url() { return '/wp-admin/post.php?post=' . $this->id; }
        function add_order_note( $n ) { $s = st(); $s['notes'][] = array( $this->id, $n ); st_put( $s ); }
        function set_status() { $s = st(); $s['wrote'][] = 'status'; st_put( $s ); } function update_meta_data() { $s = st(); $s['wrote'][] = 'meta'; st_put( $s ); } function save() {} }
    function wc_get_order( $id ) {
        $flat = json_encode( array( 'calcType' => 'brochure', 'sizeMode' => 'custom', 'longEdge' => 11, 'shortEdge' => 8.5, 'sizeLabel' => '8.5×11', 'sides' => 1, 'qty' => 500, 'jobName' => 'Flyer' ) );
        $o = array(
            87347 => new TO( 87347, array( '_pps_gdrive_folder_id' => 'FOLDER_A_000001' ), array( 11 => new TI( 'Brochure', array( '_pps_metadata' => $flat, '_pps_artwork_files' => json_encode( array( array( 'name' => 'Flyer_print-ready.pdf' ) ) ) ) ) ) ),
            87400 => new TO( 87400, array( '_pps_gdrive_folder_id' => 'FOLDER_B_000001' ), array( 21 => new TI( 'Brochure', array( '_pps_metadata' => $flat ) ) ) ),
            87401 => new TO( 87401, array(), array( 31 => new TI( 'Brochure', array( '_pps_metadata' => $flat ) ) ) ),
        );
        return $o[ (int) $id ] ?? false;
    }
    function wc_get_order_status_name( $s ) { return ucfirst( $s ); }
    define( 'PPS_CALC_DIR', getenv( 'PPS_MPI_DIR' ) . '/' );
    define( 'PPS_CALC_URL', '/calcdir/' );
    if ( strpos( $_SERVER['REQUEST_URI'], '/calcdir/' ) === 0 ) { // the plugin folder, served as the web server would
        $f = PPS_CALC_DIR . substr( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), 9 );
        if ( strpos( $f, '..' ) === false && is_file( $f ) ) { header( 'Content-Type: application/javascript' ); readfile( $f ); exit; }
        http_response_code( 404 ); exit;
    }
    require getenv( 'PPS_MPI_IMPOSITION' );   // the live pps-imposition.php (helpers + wp-admin handlers)
    require __DIR__ . '/pps-missive-impose.php';
    $path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
    if ( $path === '/wp-admin/admin-ajax.php' ) {
        $_REQUEST = $_GET + $_POST;
        $a = (string) ( $_REQUEST['action'] ?? '' );
        do_hook( 'wp_ajax_nopriv_' . $a );
        echo '0'; exit; // what admin-ajax says when nothing answered
    }
    do_hook( 'init' ); do_hook( 'wp_loaded' );
    echo 'site page'; exit;
}

/* ═════════ TEST MODE ═════════ */
$fail = 0; $n = 0;
function ok( $c, $w, $g = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $g !== null ? ' — ' . json_encode( $g ) : '' ) . "\n"; } }

$tmp = sys_get_temp_dir() . '/pps-mpi-' . getmypid(); @mkdir( $tmp );
$imp_php  = getenv( 'PPS_IMPOSE_PHP' ) ?: "$tmp/pps-imposition.php";
$tool_dir = getenv( 'PPS_IMPOSE_TOOL_DIR' ) ?: $tmp;
if ( ! getenv( 'PPS_IMPOSE_PHP' ) ) file_put_contents( $imp_php, shell_exec( 'git -C ' . escapeshellarg( __DIR__ ) . ' show 38d9be9:pps-imposition.php' ) );
if ( ! getenv( 'PPS_IMPOSE_TOOL_DIR' ) ) file_put_contents( "$tmp/imposition-tool.html", shell_exec( 'git -C ' . escapeshellarg( __DIR__ ) . ' show 38d9be9:imposition-tool.html' ) );
ok( filesize( $imp_php ) === 38382 && filesize( "$tool_dir/imposition-tool.html" ) === 451423, 'the live imposition files (v1.58: 38,382 and 451,423 bytes) are what this runs against', array( filesize( $imp_php ), filesize( "$tool_dir/imposition-tool.html" ) ) );

// A one-page 8.75×11.25″ PDF: an 8.5×11 flyer with bleed, the shape a print-ready file has.
function tiny_pdf() {
    $c = "0.9 0.2 0.2 rg 0 0 630 810 re f 0 0 0 rg BT /F1 48 Tf 120 400 Td (PPS TEST) Tj ET";
    $objs = array( '<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 630 810] /TrimBox [9 9 621 801] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen( $c ) . " >>\nstream\n$c\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>' );
    $out = "%PDF-1.4\n"; $off = array();
    foreach ( $objs as $i => $o ) { $off[] = strlen( $out ); $out .= ( $i + 1 ) . " 0 obj\n$o\nendobj\n"; }
    $x = strlen( $out ); $out .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
    foreach ( $off as $o ) $out .= sprintf( "%010d 00000 n \n", $o );
    return $out . "trailer\n<< /Size " . ( count( $objs ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
}
file_put_contents( "$tmp/art.pdf", tiny_pdf() );
file_put_contents( "$tmp/fake.txt", "not a pdf at all" );

$state = "$tmp/state.json";
function reset_state() { global $state, $tmp; file_put_contents( $state, json_encode( array( 'pdf' => "$tmp/art.pdf", 'uploads' => array(), 'notes' => array(), 'fetched' => array(), 'listed' => array(), 'wrote' => array(), 'cleared' => array(),
    'folders' => array(
        'FOLDER_A_000001' => array( array( 'id' => 'artPRINTREADY01', 'name' => 'Flyer_print-ready.pdf', 'mimeType' => 'application/pdf' ), array( 'id' => 'rawFILE0000001', 'name' => 'Flyer.pdf', 'mimeType' => 'application/pdf' ) ),
        'FOLDER_B_000001' => array( array( 'id' => 'otherORDERfile1', 'name' => 'Other_print-ready.pdf', 'mimeType' => 'application/pdf' ) ),
    ) ) ) ); }
function S() { global $state; return json_decode( file_get_contents( $state ), true ); }
reset_state();

$port = 18000 + getmypid() % 1000;
$srv = proc_open( array( PHP_BINARY, '-S', "127.0.0.1:$port", __FILE__ ), array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes,
    __DIR__, array( 'PPS_MPI_STATE' => $state, 'PPS_MPI_IMPOSITION' => $imp_php, 'PPS_MPI_DIR' => $tool_dir, 'PATH' => getenv( 'PATH' ) ) );
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $port ); $i++ ) usleep( 100000 );
$base = "http://127.0.0.1:$port";
function req( $path, $form = array() ) { // form: name => value, or '@file;filename=x;type=y'
    global $base; $args = '';
    foreach ( $form as $k => $v ) $args .= ' -F ' . escapeshellarg( "$k=$v" );
    $out = shell_exec( "curl -sS -o - -w '\n%{http_code} %{content_type}' $args " . escapeshellarg( $base . $path ) );
    $p = strrpos( $out, "\n" ); list( $code, $type ) = explode( ' ', substr( $out, $p + 1 ) . ' ', 2 );
    $body = substr( $out, 0, $p );
    return array( 'code' => (int) $code, 'type' => trim( $type ), 'body' => $body, 'json' => json_decode( $body, true ) );
}
$tok = function ( $o ) { return hash_hmac( 'sha256', 'pps-impose|' . $o, 'PANELKEY' ); };
$ajax = function ( $order ) { return '/wp-admin/admin-ajax.php?pps_mpi=' . $order; };

// ── the page ──
$r = req( '/?pps_missive_impose=1&k=WRONG&order=87347' );
ok( $r['code'] === 403 && strpos( $r['body'], 'PPS_IMPOSE_CFG' ) === false, 'the page needs the panel key', $r['code'] );
$r = req( '/?pps_missive_impose=1&k=PANELKEY&order=99999' );
ok( $r['code'] === 403, 'and a real order', $r['code'] );
$r = req( '/?pps_missive_impose=1&k=PANELKEY&order=87347' );
preg_match( '/window\.PPS_IMPOSE_CFG = (\{.*?\});<\/script>/', $r['body'], $m ); $cfg = json_decode( $m[1] ?? 'null', true );
ok( $r['code'] === 200 && strpos( $r['body'], 'PPS Imposition Tool' ) !== false, 'with both, it is the live imposition tool', $r['code'] );
ok( is_array( $cfg ) && $cfg['nonce'] === $tok( 87347 ) && $cfg['ajaxUrl'] === '/wp-admin/admin-ajax.php?pps_mpi=87347', 'carrying the token for THIS order and the admin-ajax URL scoped to it', $cfg );
ok( is_array( $cfg ) && strpos( $r['body'], 'PANELKEY' ) === false, 'the panel key itself is not written into the tool' );
ok( is_array( $cfg ) && $cfg['orderStatuses'] === array(), 'the status menu is given nothing to move to' );
ok( is_array( $cfg ) && $cfg['maxUpload'] === 67108864, 'the upload limit is passed, so the tool\'s size guard (v1.34) works in the frame', $cfg['maxUpload'] ?? null );
ok( strpos( $r['body'], '"https://unpkg.com/react@18.3.1/umd/react.production.min.js"' ) !== false, 'with no vendored copy on the site, the CDN tag stays' );
ok( substr_count( $r['body'], '<script data-cfasync="false">window.PPS_IMPOSE_CFG = ' ) === 1, 'the config is injected once' );
preg_match_all( '/<script\b[^>]*>/i', $r['body'], $tags );
ok( count( $tags[0] ) >= 8 && count( preg_grep( '/data-cfasync="false"/', $tags[0] ) ) === count( $tags[0] ), 'every script on the page tells Cloudflare Rocket Loader to leave it alone (it blanked the tool on the live site)', $tags[0] );

// ── list ──
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_list', 'nonce' => $tok( 87347 ) ) );
$it = $r['json']['data']['items'] ?? array();
ok( $r['json']['success'] === true && count( $it ) === 1 && $it[0]['order_id'] === 87347 && $it[0]['item_id'] === 11, 'the list is that order\'s jobs only', $r['json'] );
ok( ( $it[0]['art_file_id'] ?? '' ) === 'artPRINTREADY01' && $it[0]['calc'] === 'brochure' && $it[0]['long_edge'] == 11 && $it[0]['short_edge'] == 8.5 && $it[0]['qty'] === 500 && $it[0]['files_listed'] === true,
    'with the print-ready file picked by the live helper, and the spec the tool reads', $it[0] ?? null );
$live_keys = array( 'order_id', 'item_id', 'status', 'status_label', 'hidden', 'order_url', 'product', 'job_name', 'calc', 'size_label', 'long_edge', 'short_edge', 'sides', 'bind_dir', 'qty', 'folder_url', 'art_file_id', 'art_file_name', 'proof_hash', 'imposed', 'files_listed' );
ok( array_keys( $it[0] ?? array() ) === $live_keys, 'every field the wp-admin list sends, in the same order' );
preg_match_all( "/'([a-z_]+)'\s*=>/", substr( file_get_contents( $imp_php ), strpos( file_get_contents( $imp_php ), '$items[] = array(' ), 3000 ), $mm );
ok( array_slice( $mm[1], 0, count( $live_keys ) ) === $live_keys, 'and the live list builds exactly those fields (the two cannot drift unseen)', array_slice( $mm[1], 0, 22 ) );
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_list', 'nonce' => $tok( 87400 ) ) );
ok( $r['code'] === 403 && $r['json']['success'] === false, 'another order\'s token is refused', $r['code'] );
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_list' ) );
ok( $r['code'] === 403, 'no token is refused', $r['code'] );
$r = req( $ajax( 87401 ), array( 'action' => 'pps_impose_list', 'nonce' => $tok( 87401 ) ) );
ok( $r['json']['success'] === true && $r['json']['data']['items'] === array(), 'an order with no Drive folder lists nothing (and does not fail)', $r['json'] );

// ── writes to the order are refused ──
foreach ( array( 'pps_impose_set_status' => array( 'status' => 'completed' ), 'pps_impose_set_hidden' => array( 'hidden' => 1 ) ) as $a => $extra ) {
    $r = req( $ajax( 87347 ), array( 'action' => $a, 'nonce' => $tok( 87347 ), 'order_id' => 87347 ) + $extra );
    ok( $r['code'] === 403 && strpos( $r['json']['data']['message'] ?? '', 'does not change orders' ) !== false, "$a is refused and says why", $r['json'] );
}
ok( S()['wrote'] === array(), 'and nothing was written to the order' );

// ── saved setups (v1.37): the office's shared list, through the live sanitiser ──
reset_state();
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_setups', 'nonce' => $tok( 87347 ) ) );
ok( $r['json']['success'] === true && $r['json']['data']['setups'] === array(), 'setups are read (none saved yet)', $r['json'] );
$list = json_encode( array( array( 'id' => 'a1', 'name' => '<b>Gloss 2-up</b>', 'spec' => array( 'sheet' => '13x19', 'marks' => true ), 'saved' => '2026-10-18' ), array( 'id' => '', 'name' => 'no id', 'spec' => array() ) ) );
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_setups', 'nonce' => $tok( 87347 ), 'op' => 'save', 'setups' => $list ) );
$saved = json_decode( S()['options']['pps_impose_setups'] ?? 'null', true );
ok( $r['json']['success'] === true && count( $saved ) === 1 && $saved[0]['name'] === 'Gloss 2-up' && $saved[0]['spec'] === array( 'sheet' => '13x19', 'marks' => true ), 'and saved to the same option the wp-admin screen uses, cleaned by its own sanitiser', $saved );
$r = req( $ajax( 87400 ), array( 'action' => 'pps_impose_setups', 'nonce' => $tok( 87400 ) ) );
ok( $r['json']['success'] === true && $r['json']['data']['setups'][0]['id'] === 'a1', 'every order\'s page sees the same list', $r['json'] );
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_setups', 'nonce' => $tok( 87400 ), 'op' => 'save', 'setups' => '[]' ) );
ok( $r['code'] === 403 && count( json_decode( S()['options']['pps_impose_setups'], true ) ) === 1, 'a save without this page\'s token is refused and changes nothing', $r['code'] );
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_setups', 'nonce' => $tok( 87347 ), 'op' => 'save', 'setups' => 'not json' ) );
ok( $r['json']['success'] === false, 'a save that is not a JSON list is refused' );

// ── download ──
reset_state();
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_download', 'nonce' => $tok( 87347 ), 'file_id' => 'artPRINTREADY01' ) );
ok( $r['code'] === 200 && $r['type'] === 'application/pdf' && $r['body'] === tiny_pdf(), 'a file in the order\'s folder comes back as its bytes', array( $r['code'], $r['type'], strlen( $r['body'] ) ) );
ok( S()['fetched'] === array( 'https://www.googleapis.com/drive/v3/files/artPRINTREADY01?alt=media' ), 'fetched from Drive by that id' );
reset_state();
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_download', 'nonce' => $tok( 87347 ), 'file_id' => 'otherORDERfile1' ) );
ok( $r['code'] === 403 && S()['fetched'] === array(), 'another order\'s file is refused, and Drive is never asked for it', array( $r['code'], S()['fetched'] ) );
ok( S()['listed'] === array( 'FOLDER_A_000001', 'FOLDER_A_000001' ), 'after looking again with a fresh listing (a file added a minute ago is not refused)', S()['listed'] );
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_download', 'nonce' => $tok( 87347 ), 'file_id' => 'anyFileInMyDrive99' ) );
ok( $r['code'] === 403 && S()['fetched'] === array(), 'so is any other file in the Drive' );
$r = req( $ajax( 87347 ), array( 'action' => 'pps_impose_download', 'nonce' => $tok( 87347 ), 'file_id' => '../x' ) );
ok( $r['code'] === 400, 'a malformed id is refused', $r['code'] );

// ── upload ──
reset_state();
$up = function ( $order, $name, $file, $tokfor = null, $post_order = null ) use ( $ajax, $tok, $tmp ) {
    return req( $ajax( $order ), array( 'action' => 'pps_impose_upload', 'nonce' => $tok( $tokfor ?? $order ), 'order_id' => $post_order ?? $order, 'filename' => $name, 'file' => "@$file;filename=$name;type=application/pdf" ) );
};
$r = $up( 87347, 'IMPOSED_Order-87347-i11_Flyer_11x8.5_2up_13x19.pdf', "$tmp/art.pdf" );
$s = S();
ok( $r['json']['success'] === true && $r['json']['data']['file_id'] === 'newFILE1', 'an IMPOSED PDF for the order is filed', $r['json'] );
ok( $s['uploads'] === array( array( 'name' => 'IMPOSED_Order-87347-i11_Flyer_11x8.5_2up_13x19.pdf', 'folder' => 'FOLDER_A_000001', 'bytes' => strlen( tiny_pdf() ), 'head' => '%PDF-' ) ), 'into that order\'s own folder, whole', $s['uploads'] );
ok( $s['notes'] === array( array( 87347, 'Imposed press-ready PDF filed to Drive from the Missive panel: IMPOSED_Order-87347-i11_Flyer_11x8.5_2up_13x19.pdf' ) ), 'with an order note saying it came from the Missive panel', $s['notes'] );
ok( $s['cleared'] === array( 'pps_impose_ls_' . md5( 'FOLDER_A_000001' ) ), 'and the folder listing is refreshed, so the panel shows it' );
reset_state();
$r = $up( 87347, 'CLEAN_Order-87347-i11_Flyer.pdf', "$tmp/art.pdf" );
ok( $r['json']['success'] === true && strpos( S()['notes'][0][1], 'Sanitized (CLEAN) copy filed to Drive from the Missive panel' ) === 0, 'a CLEAN copy is filed too, and named as one' );
reset_state();
$r = $up( 87347, 'IMPOSED_x.pdf', "$tmp/art.pdf", null, 87400 );
ok( $r['code'] === 403 && S()['uploads'] === array(), 'a file for another order is refused on this order\'s page', $r['json'] );
$r = $up( 87400, 'IMPOSED_x.pdf', "$tmp/art.pdf", 87347 );
ok( $r['code'] === 403 && S()['uploads'] === array(), 'and with this order\'s token on another order', $r['code'] );
$r = $up( 87347, 'Flyer.pdf', "$tmp/art.pdf" );
ok( $r['json']['success'] === false && S()['uploads'] === array(), 'a name that is not IMPOSED_/CLEAN_ is refused (it cannot overwrite the customer\'s files)', $r['json'] );
$r = $up( 87347, 'IMPOSED_fake.pdf', "$tmp/fake.txt" );
ok( $r['json']['success'] === false && strpos( $r['json']['data']['message'], 'not a PDF' ) !== false && S()['uploads'] === array(), 'bytes that are not a PDF are refused', $r['json'] );
ok( S()['notes'] === array(), 'and no refusal leaves a note' );

// ── no pps_mpi: the wp-admin screen's own call falls through, untouched ──
$r = req( '/wp-admin/admin-ajax.php', array( 'action' => 'pps_impose_list', 'nonce' => $tok( 87347 ) ) );
ok( $r['body'] === '0', 'a call without pps_mpi is not answered by the bridge', $r['body'] );
$src = file_get_contents( __DIR__ . '/pps-missive-impose.php' );
ok( substr_count( $src, "add_action( 'wp_ajax_' . \$a, 'pps_mpi_ajax', 0 )" ) === 1 && substr_count( $src, "add_action( 'wp_ajax_nopriv_' . \$a, 'pps_mpi_ajax', 0 )" ) === 1, 'it answers first, logged in (browser) or not (Missive)' );
ok( strpos( $src, "add_action( 'wp_loaded', function () {\n    if ( ! isset( \$_GET['pps_missive_impose'] ) ) return;" ) !== false && strpos( $src, "add_action( 'init'" ) === false,
    'the page waits for wp_loaded — at init 1 WooCommerce has not registered orders yet, and every order read as missing' );
ok( strpos( $src, 'wp_set_current_user' ) === false && strpos( $src, 'set_status(' ) === false && strpos( $src, 'update_meta_data' ) === false, 'it never signs anyone in and never changes an order' );

// ── the panel offers it ──
$panel = file_get_contents( __DIR__ . '/pps-missive-panel.php' );
ok( strpos( $panel, "require_once __DIR__ . '/pps-missive-impose.php';" ) !== false, 'the panel loads the bridge' );
ok( strpos( $panel, "text:'Impose'" ) !== false && strpos( $panel, "text:' [Impose]'" ) !== false && strpos( $panel, "el('iframe',{cls:'imp',src:u" ) !== false, 'an Impose button on the order card and each board row opens it in the panel' );
ok( strpos( $panel, "DATA=d; if(!IMPOSE) render();" ) !== false, 'a conversation change never reloads the tool mid-job' );

// ── the browser: impose the order from its queue and file it ──
$js = <<<'JS'
const { chromium } = require(process.env.PW);
(async () => {
  const b = await chromium.launch(); const p = await b.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(process.env.URL, { waitUntil: 'domcontentloaded' });
  const out = {};
  try {
    await p.waitForSelector('table.imp-q tbody tr', { timeout: 60000 });
    out.rows = await p.$$eval('table.imp-q tbody tr', t => t.map(r => r.innerText.replace(/\s+/g, ' ')));
    await p.click('table.imp-q tbody tr button:has-text("Impose")');
    await p.waitForSelector('button:has-text("Send to order")', { timeout: 60000 });
    await p.click('button:has-text("Send to order")');
    await p.waitForSelector('button:has-text("Filed to Drive")', { timeout: 30000 });
    out.filed = true;
    out.statusOptions = await p.$$eval('table.imp-q select option', o => o.map(x => x.value));
  } catch (e) { out.err = e.message; out.text = (await p.innerText('body')).slice(0, 1500); }
  out.errs = errs; console.log(JSON.stringify(out)); await b.close();
})();
JS;
// a vendored library on the site is used instead of its CDN tag (v1.58), and must still load
@mkdir( "$tool_dir/imposition-vendor" );
file_put_contents( "$tool_dir/imposition-vendor/react.production.min.js", (string) shell_exec( 'curl -sS https://unpkg.com/react@18.3.1/umd/react.production.min.js' ) );
$r = req( '/?pps_missive_impose=1&k=PANELKEY&order=87347' );
ok( filesize( "$tool_dir/imposition-vendor/react.production.min.js" ) === 10751 && preg_match( '#<script data-cfasync="false" src="/calcdir/imposition-vendor/react\.production\.min\.js\?v=\d+"#', $r['body'] ) === 1, 'a vendored library already on the site replaces its CDN tag', filesize( "$tool_dir/imposition-vendor/react.production.min.js" ) );
reset_state();
file_put_contents( "$tmp/b.js", $js );
$pw = trim( shell_exec( 'npm root -g' ) ) . '/playwright';
$got = json_decode( (string) shell_exec( 'PW=' . escapeshellarg( $pw ) . ' URL=' . escapeshellarg( "$base/?pps_missive_impose=1&k=PANELKEY&order=87347" ) . ' node ' . escapeshellarg( "$tmp/b.js" ) . ' 2>&1' ), true );
$s = S();
ok( is_array( $got ) && count( $got['rows'] ?? array() ) === 1 && strpos( $got['rows'][0], '#87347' ) !== false && strpos( $got['rows'][0], 'Flyer_print-ready.pdf' ) !== false, 'the tool shows the order\'s one job with its print-ready file', $got );
ok( ! empty( $got['filed'] ), 'Impose then "Send to order\'s Drive folder" files it', $got['err'] ?? $got['text'] ?? null );
ok( count( $s['uploads'] ) === 1 && preg_match( '/^IMPOSED_Order-87347-i11_Flyer_11x8\.5_\d+up_[\dx.]+\.pdf$/', $s['uploads'][0]['name'] ) && $s['uploads'][0]['folder'] === 'FOLDER_A_000001' && $s['uploads'][0]['head'] === '%PDF-' && $s['uploads'][0]['bytes'] > 1000,
    'as a real imposed PDF, named for the order and line, in its folder', $s['uploads'] );
ok( $s['fetched'] === array( 'https://www.googleapis.com/drive/v3/files/artPRINTREADY01?alt=media' ), 'from the artwork it downloaded through the bridge', $s['fetched'] );
ok( ( $got['statusOptions'] ?? null ) === array( 'wc-processing' ), 'its status menu holds only the current status', $got['statusOptions'] ?? null );
ok( is_array( $got ) && $got['errs'] === array(), 'no script errors', $got['errs'] ?? null );

proc_terminate( $srv ); proc_close( $srv );
array_map( 'unlink', glob( "$tool_dir/imposition-vendor/*" ) ); @rmdir( "$tool_dir/imposition-vendor" );
array_map( 'unlink', array_filter( glob( "$tmp/*" ), 'is_file' ) ); @rmdir( $tmp );
echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> MISSIVE IMPOSE OK ($n checks)\n";
exit( $fail ? 1 : 0 );
