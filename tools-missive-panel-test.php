<?php
/**
 * pps-missive-panel.php: the read-only Missive sidebar (owner, 2026-10-17).
 *
 * Fake open orders shaped like the real ones (two flats on the same 100lb Gloss 13×19 with
 * overlapping windows, one on another paper, one already printed, one shipping weeks later,
 * a custom quote line), driven through the real file with the real Paper Order reading
 * the paper. Checks: the window, "run together" (same paper AND sheet AND overlapping
 * window only), the automation preview, the key gate on page and endpoint, and that
 * nothing in the file can write to Missive or to an order.
 * The page's order-number finder is run under node.
 *
 * Run: php tools-missive-panel-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['opts'] = array(); $GLOBALS['tr'] = array();
function add_action() {} function add_filter() {} function add_submenu_page() {}
function apply_filters( $h, $v ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function wp_next_scheduled() { return true; }
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
class WP_Error { public $c; public $m; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['http'] = array();
function wp_remote_request( $url, $args ) {
    $GLOBALS['http'][] = array( 'url' => $url, 'args' => $args );
    $path = parse_url( $url, PHP_URL_PATH ); parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
    if ( ( $args['headers']['Authorization'] ?? '' ) !== 'Bearer missive_pat-TEST' ) return array( 'code' => 401, 'body' => '{}' );
    if ( $path === '/v1/organizations' ) return array( 'code' => 200, 'body' => json_encode( array( 'organizations' => array( array( 'id' => 'org-1' ) ) ) ) );
    if ( $path === '/v1/tasks' && $args['method'] === 'GET' ) { $t = array_values( array_filter( $GLOBALS['mtasks'], function ( $t ) use ( $q ) { return $t['state'] === $q['state']; } ) ); return array( 'code' => 200, 'body' => json_encode( array( 'tasks' => $t ) ) ); }
    if ( strpos( $path, '/v1/conversations/' ) === 0 ) { $id = substr( $path, 18 ); return array( 'code' => 200, 'body' => json_encode( array( 'conversations' => array( array( 'id' => $id, 'subject' => $GLOBALS['msubjects'][ $id ] ?? 'Question about paper' ) ) ) ) ); }
    if ( strpos( $path, '/v1/tasks/' ) === 0 && $args['method'] === 'PATCH' ) return array( 'code' => 200, 'body' => '{"tasks":{}}' );
    if ( $path === '/v1/tasks' && $args['method'] === 'POST' ) { $GLOBALS['made'] = ( $GLOBALS['made'] ?? 0 ) + 1; if ( ! empty( $GLOBALS['fail_after'] ) && $GLOBALS['made'] > $GLOBALS['fail_after'] ) return array( 'code' => 500, 'body' => '{}' ); return array( 'code' => 201, 'body' => json_encode( array( 'tasks' => array( 'id' => 'new-' . $GLOBALS['made'], 'state' => 'todo' ) ) ) ); }
    return array( 'code' => 404, 'body' => '{}' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function pps_get_config() { return array( 'papers_nc' => array( array( 'val' => 0.01, 'label' => '100lb Gloss Text' ), array( 'val' => 0.005, 'label' => '80lb Matte Text' ) ), 'papers_cs' => array() ); }
function wc_get_orders( $a ) { $GLOBALS['asked'] = $a; return $GLOBALS['orders']; }
function wc_get_order( $id ) { foreach ( $GLOBALS['orders'] as $o ) if ( $o->get_id() === $id ) return $o; return $GLOBALS['extra'][ $id ] ?? false; }
require __DIR__ . '/pps-paper-report.php';
require __DIR__ . '/pps-paper-order.php';
require __DIR__ . '/pps-missive-panel.php';

class M { public $k; public $v; function __construct( $k, $v ) { $this->k = $k; $this->v = $v; } function get_data() { return array( 'key' => $this->k, 'value' => $this->v ); } }
class I { public $n; public $m; function __construct( $n, $m ) { $this->n = $n; $this->m = $m; }
    function get_name() { return $this->n; } function get_meta( $k ) { return $this->m[ $k ] ?? ''; }
    function get_meta_data() { $o = array(); foreach ( $this->m as $k => $v ) $o[] = new M( $k, $v ); return $o; } }
class O { public $id; public $c; public $it; public $st;
    function __construct( $id, $c, $it, $st = 'processing' ) { $this->id = $id; $this->c = $c; $this->it = $it; $this->st = $st; }
    function get_id() { return $this->id; } function get_status() { return $this->st; } function get_items() { return $this->it; }
    function get_billing_first_name() { return $this->c; } function get_billing_last_name() { return ''; } function get_billing_company() { return ''; }
    function get_date_created() { return null; } function get_meta( $k ) { return ''; } }

function flat( $paper, $qty, $imp, $start, $ship, $extra = array() ) {
    return array( '_pps_metadata' => json_encode( $extra + array( 'paper' => $paper, 'qty' => $qty, 'jobName' => 'Job ' . $qty, 'sizeLabel' => '8.5×11',
        'productionStartDate' => $start, 'mustShipByDate' => $ship, 'debug' => array( 'imp' => $imp, 'qty' => $qty, 'pressSheets' => $qty / $imp ) ) ),
        'PPS-Production-Start' => $start, '_pps_delivery_date' => $ship, 'Job Ticket' => "Product: Flyer\nPaper: 100lb Gloss Text\nQuantity: $qty", 'Add-ons' => 'Coating: UV Gloss (both sides); Fold: Tri-fold' );
}
$gloss = array( 'val' => 0.01, 'label' => '100lb Gloss Text' );
$matte = array( 'val' => 0.005, 'label' => '80lb Matte Text' );
$GLOBALS['orders'] = array(
    new O( 87501, 'Ana', array( 11 => new I( 'Flyer', flat( $gloss, 1000, 2, '2026-10-19', '2026-10-22', array( 'rushCost' => 40 ) ) ) ) ),
    new O( 87502, 'Ben', array( 12 => new I( 'Flyer', flat( $gloss, 500, 2, '2026-10-21', '2026-10-23' ) ) ) ),
    new O( 87503, 'Cal', array( 13 => new I( 'Flyer', flat( $matte, 500, 2, '2026-10-19', '2026-10-22' ) ) ) ),
    new O( 87504, 'Dee', array( 14 => new I( 'Flyer', flat( $gloss, 400, 2, '2026-10-19', '2026-10-22' ) + array( '_pps_paper_done' => '2026-10-16' ) ) ) ),
    new O( 87505, 'Eve', array( 15 => new I( 'Flyer', flat( $gloss, 300, 2, '2026-11-09', '2026-11-12' ) ) ) ),
    new O( 87506, 'Fay', array( 16 => new I( 'Custom Order', array( 'Specs' => '500 postcards 4x6 14pt', 'Requested delivery' => '2026-10-30' ) ) ) ),
);
$fail = 0; $n = 0;
function ok( $c, $w, $g = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $g !== null ? ' — ' . json_encode( $g ) : '' ) . "\n"; } }

$open = pps_mp_open_jobs();
ok( $GLOBALS['asked']['status'] === array( 'processing', 'on-hold' ), 'only open orders are scanned' );
ok( count( $open ) === 6, 'one job per open line', count( $open ) );
$a = $open[0];
ok( $a['start'] === '2026-10-19' && $a['must_ship'] === '2026-10-22' && $a['rush'] === true, 'start, must-ship and rush are read from the line', $a );
ok( $a['papers'][0]['label'] === '100lb Gloss Text' && $a['papers'][0]['sheet'] === '13×19' && $a['papers'][0]['sheets'] === 500, 'paper comes from the Paper Order reading', $a['papers'] );
ok( $a['ticket'][1] === array( 'Paper', '100lb Gloss Text' ), 'the Job Ticket is split into label and value', $a['ticket'] );
ok( pps_mp_window( $a, '2026-10-17' ) === array( '2026-10-19', '2026-10-22' ), 'the window runs start → must-ship' );
ok( pps_mp_window( array( 'start' => '', 'must_ship' => '2026-10-22', 'delivery' => '' ), '2026-10-17' ) === array( '2026-10-17', '2026-10-22' ), 'no start date → the window starts today' );

$b = pps_mp_batch( $a, $open, '2026-10-17' );
ok( array_column( $b['mates'], 'order' ) === array( 87502 ), 'run together: same paper and sheet, overlapping window — not other paper, not printed, not weeks later', array_column( $b['mates'], 'order' ) );
ok( $b['combined'][0]['sheets'] === 750 && $b['combined'][0]['jobs'] === 1, 'the combined sheet count includes this job', $b['combined'] );
ok( pps_mp_batch( $open[2], $open, '2026-10-17' )['mates'] === array(), 'a job alone on its paper has no mates' );

$custom = $open[5];
ok( $custom['source'] === 'custom' && $custom['delivery'] === '2026-10-30' && $custom['ticket'][0] === array( 'Specs', '500 postcards 4x6 14pt' ), 'a custom line shows its own text and requested delivery', $custom );

$p = pps_mp_preview( $a );
ok( $p['due'] === '2026-10-19' && $p['title'] === '#87501 Job 1000 · start Oct 19 · ship Oct 22', 'the preview task is due on the start date (the team convention) with the window in its title', $p );
ok( $p['labels'] === array( '100lb Gloss Text', '13×19', 'RUSH', 'Coating', 'Fold' ), 'the preview labels: paper, sheet, rush, finishing', $p['labels'] );

$ans = pps_mp_answer( array( 87501, 99999 ) );
ok( count( $ans['orders'] ) === 1 && $ans['orders'][0]['jobs'][0]['batch']['mates'][0]['order'] === 87502, 'the endpoint answers for the order in view, with its batch', $ans['orders'] );
ok( $ans['missing'] === array( 99999 ), 'a number that is not an order is said, not guessed' );
$board = pps_mp_answer( array() )['board'];
ok( count( $board ) === 6 && count( $ans['board'] ) === 6, 'the board is always there, with an order in view or not', count( $board ) );
$r1 = $board[0];
ok( $r1['paper'] === '100lb Gloss Text' && $r1['sheet'] === '13×19' && $r1['sheets'] === 500 && $r1['finishing'] === array( 'Coating', 'Fold' ) && $r1['delivery'] === '2026-10-22', 'a board row carries the sortable attributes: paper, sheet, sheets, finishing, dates', $r1 );
ok( $r1['task'] === null && $ans['missive']['connected'] === false, 'with no token, no tasks and no Missive call', $GLOBALS['http'] );
$GLOBALS['extra'][ 87000 ] = new O( 87000, 'Old', array( 9 => new I( 'Flyer', flat( $gloss, 100, 2, '2026-09-01', '2026-09-04' ) ) ), 'completed' );
ok( pps_mp_answer( array( 87000 ) )['orders'][0]['status'] === 'completed', 'a completed order still shows (for reference)' );

// ── the key ──
ok( pps_mp_key_ok( 'anything' ) === false, 'with no key made, nothing gets in' );
$k = pps_mp_key( true );
ok( strlen( $k ) === 40 && pps_mp_key_ok( $k ) && ! pps_mp_key_ok( '' ) && ! pps_mp_key_ok( substr( $k, 0, 39 ) . 'x' ), 'only the exact key gets in' );
ok( pps_mp_key( true ) === $k, 'the key is made once' );

// ── Missive subtasks ──
$GLOBALS['mtasks'] = array(
    array( 'id' => 't1', 'title' => 'Print job', 'state' => 'todo', 'due_at' => strtotime( '2026-10-22 17:00 America/Phoenix' ), 'conversation' => 'c-501', 'last_activity_at' => 5 ),
    array( 'id' => 't2', 'title' => 'Run #87502', 'state' => 'in_progress', 'due_at' => strtotime( '2026-10-20 17:00 America/Phoenix' ), 'started_at' => strtotime( '2026-10-17 09:00 America/Phoenix' ), 'conversation' => 'c-x', 'last_activity_at' => 4 ),
    array( 'id' => 't3', 'title' => 'Proof call', 'state' => 'todo', 'due_at' => 0, 'conversation' => 'c-q', 'last_activity_at' => 3 ),
    array( 'id' => 't4', 'title' => 'Standalone', 'state' => 'todo', 'due_at' => 0, 'conversation' => null, 'last_activity_at' => 2 ),
    array( 'id' => 't5', 'title' => 'Old', 'state' => 'closed', 'due_at' => 0, 'conversation' => 'c-503', 'last_activity_at' => 1 ),
);
$GLOBALS['msubjects'] = array( 'c-503' => '[Priority Print Service]: New order #87503' );
list( $pby, $pun ) = pps_mp_pair_tasks( $GLOBALS['mtasks'], array( 'c-501' => 87501 ) );
ok( array_keys( $pby ) === array( 87501, 87502 ) && $pby[87501]['due'] === '2026-10-22' && $pby[87502]['started'] === '2026-10-17', 'subtasks pair by their conversation, or by an order number in their title; a standalone task is ignored', $pby );
ok( count( $pun ) === 2, 'a subtask whose conversation names no order is left unpaired, not guessed', $pun );
$GLOBALS['tr'] = array(); $GLOBALS['opts'][ PPS_MP_TOKEN_OPTION ] = 'missive_pat-TEST';
$withconv = pps_mp_answer( array( 87501 ), 'c-501' );
ok( ( $GLOBALS['opts'][ PPS_MP_CONV_OPTION ]['c-501'] ?? null ) === 87501, 'what the panel saw (this conversation holds this order) is remembered' );
ok( ( $GLOBALS['opts'][ PPS_MP_CONV_OPTION ]['c-503'] ?? null ) === 87503 && ( $GLOBALS['opts'][ PPS_MP_CONV_OPTION ]['c-q'] ?? null ) === 0, 'other subtasks pair from their conversation subject, and a subject with no order is remembered as none', $GLOBALS['opts'][ PPS_MP_CONV_OPTION ] );
$auth = array_unique( array_map( function ( $h ) { return $h['args']['headers']['Authorization']; }, $GLOBALS['http'] ) );
ok( $auth === array( 'Bearer missive_pat-TEST' ) && strpos( $GLOBALS['http'][0]['url'], 'https://public.missiveapp.com/v1/' ) === 0, 'the token goes only in the Authorization header, to Missive', $auth );
ok( $withconv['missive']['connected'] && $withconv['orders'][0]['jobs'][0]['task']['id'] === 't1', 'the order in view shows its subtask' );
$rows = array(); foreach ( $withconv['board'] as $r ) $rows[ $r['order'] ] = $r;
ok( $rows[87502]['task']['state'] === 'in_progress' && $rows[87502]['due_mismatch'] === true, 'a subtask due on a different day from the job start date is flagged', $rows[87502] );
$GLOBALS['mtasks'][0]['due_at'] = strtotime( '2026-10-19 17:00 America/Phoenix' ); $GLOBALS['tr'] = array();
$rs = array(); foreach ( pps_mp_answer( array() )['board'] as $r ) $rs[ $r['order'] ] = $r;
ok( $rs[87501]['due_mismatch'] === false, 'a subtask due on the job start date (the team convention) is not flagged', $rs[87501]['task'] );
ok( $rows[87503]['task']['state'] === 'closed', 'a done subtask shows as done' );
ok( strpos( pps_mp_page_html( 'K', 'a', 'b' ), 'missive_pat' ) === false, 'the token never reaches the page' );
// state changes
$GLOBALS['http'] = array();
$r = pps_mp_set_state( 't1', 'in_progress' );
ok( is_wp_error( $r ) && strpos( $r->get_error_message(), 'switched off' ) !== false && ! $GLOBALS['http'], 'changing a subtask is refused while the setting is off, without calling Missive', $r );
$GLOBALS['opts'][ PPS_MP_ALLOW_OPTION ] = 1;
$r = pps_mp_set_state( 't3', 'closed' );
ok( is_wp_error( $r ) && strpos( $r->get_error_message(), 'not paired' ) !== false, 'an unpaired subtask is never changed', $r );
ok( is_wp_error( pps_mp_set_state( 't1', 'deleted' ) ), 'only to do / in progress / done are accepted' );
$GLOBALS['http'] = array();
$r = pps_mp_set_state( 't1', 'in_progress' );
$last = end( $GLOBALS['http'] );
ok( $r === true && $last['args']['method'] === 'PATCH' && strpos( $last['url'], '/v1/tasks/t1' ) !== false && $last['args']['body'] === '{"tasks":{"state":"in_progress"}}', 'allowed: a paired subtask gets exactly its state changed, nothing else', $last );
ok( ! isset( $GLOBALS['tr'][ PPS_MP_TASKS_CACHE ] ), 'the task cache is dropped after a change so the board shows it' );
$GLOBALS['opts'][ PPS_MP_TOKEN_OPTION ] = 'wrong';  $GLOBALS['tr'] = array(); unset( $GLOBALS['opts'][ PPS_MP_ORG_OPTION ] );
$bad = pps_mp_answer( array() );
ok( $bad['missive']['connected'] === false && strpos( $bad['missive']['error'], 'refused' ) !== false && count( $bad['board'] ) === 6, 'a refused token is said on the panel and the board still shows', $bad['missive'] );

// ── creating one subtask per job ──
$GLOBALS['opts'][ PPS_MP_TOKEN_OPTION ] = 'missive_pat-TEST'; $GLOBALS['opts'][ PPS_MP_ORG_OPTION ] = 'org-1'; $GLOBALS['tr'] = array();
$two = new O( 87600, 'Gus', array( 21 => new I( 'Flyer', flat( $gloss, 200, 2, '2026-10-20', '2026-10-23' ) ), 22 => new I( 'Flyer', flat( $matte, 300, 2, '2026-10-21', '2026-10-23', array( 'jobName' => 'Menu' ) ) ) ) );
$GLOBALS['extra'][ 87600 ] = $two;
$st = pps_mp_subtask_for( pps_mp_jobs( array( $two ) )[0], 1, 2, 'https://x/order' );
ok( $st['title'] === '#87600 · Job 200 · 200 × 8.5×11 · start Tue Oct 20 · ship Fri Oct 23 (job 1 of 2)', 'a subtask title names the order, job, quantity, size and window', $st['title'] );
ok( strpos( $st['description'], "Paper: 100lb Gloss Text" ) !== false && substr( $st['description'], -22 ) === 'Order: https://x/order', 'its description is the Job Ticket and a link to the order', $st['description'] );
ok( gmdate( 'Y-m-d H:i', $st['due_at'] ) === '2026-10-21 00:00', 'it is due on the start date at 17:00 shop time (00:00 UTC next day in Phoenix)', gmdate( 'c', $st['due_at'] ) );
$GLOBALS['http'] = array();
$r = pps_mp_create_subtasks( 87600, 'c-600' );
ok( is_wp_error( $r ) && strpos( $r->get_error_message(), 'switched off' ) !== false && ! $GLOBALS['http'], 'creating is refused while its setting is off, without calling Missive', $r );
$GLOBALS['opts'][ PPS_MP_CREATE_OPTION ] = 1;
$r = pps_mp_create_subtasks( 87600, 'c-600' );
ok( is_wp_error( $r ) && strpos( $r->get_error_message(), 'own conversation' ) !== false, 'a conversation the panel has not seen holding this order is refused', $r );
pps_mp_answer( array( 87600 ), 'c-600' );   // the panel opens the order's conversation
$GLOBALS['http'] = array(); $GLOBALS['made'] = 0;
$r = pps_mp_create_subtasks( 87600, 'c-600' );
$posts = array_values( array_filter( $GLOBALS['http'], function ( $h ) { return $h['args']['method'] === 'POST'; } ) );
$b0 = json_decode( $posts[0]['args']['body'], true )['tasks'] ?? array();
ok( $r === 2 && count( $posts ) === 2, 'one subtask per job', array( $r, count( $posts ) ) );
ok( $b0['subtask'] === true && $b0['conversation'] === 'c-600' && $b0['organization'] === 'org-1' && strpos( $b0['title'], '#87600' ) === 0 && $b0['due_at'] === $st['due_at'], 'each is a subtask in that conversation, with title and due date', $b0 );
ok( ! isset( $b0['assignees'] ) && ! isset( $b0['add_to_inbox'] ) && ! isset( $b0['add_users'] ), 'it assigns nobody and moves nothing into anyone\'s inbox' );
ok( pps_mp_item_map() === array( 21 => 'new-1', 22 => 'new-2' ), 'each job remembers its subtask', pps_mp_item_map() );
$GLOBALS['http'] = array();
ok( pps_mp_create_subtasks( 87600, 'c-600' ) === 0 && ! array_filter( $GLOBALS['http'], function ( $h ) { return $h['args']['method'] === 'POST'; } ), 'pressing again creates nothing: a job never gets a second subtask' );
$GLOBALS['mtasks'][] = array( 'id' => 'new-1', 'title' => '#87600 · Job 200', 'state' => 'todo', 'due_at' => $st['due_at'], 'conversation' => 'c-600', 'last_activity_at' => 9 );
$GLOBALS['mtasks'][] = array( 'id' => 'new-2', 'title' => '#87600 · Menu', 'state' => 'in_progress', 'due_at' => pps_mp_due_unix( '2026-10-21' ), 'conversation' => 'c-600', 'last_activity_at' => 9 );
$GLOBALS['tr'] = array();
$a6 = pps_mp_answer( array( 87600 ), 'c-600' );
$jt = array_column( array_column( $a6['orders'][0]['jobs'], 'task' ), 'id' );
ok( $jt === array( 'new-1', 'new-2' ) && $a6['orders'][0]['needs'] === 0, 'each job now shows its own subtask, and the order needs none', $jt );
ok( pps_mp_set_state( 'new-2', 'closed' ) === true, 'a created subtask can be started and finished from the panel' );
// a failure part-way keeps what was made and never duplicates it
$three = new O( 87601, 'Hal', array( 31 => new I( 'Flyer', flat( $gloss, 100, 2, '2026-10-20', '2026-10-23' ) ), 32 => new I( 'Flyer', flat( $gloss, 110, 2, '2026-10-20', '2026-10-23' ) ) ) );
$GLOBALS['extra'][ 87601 ] = $three; pps_mp_answer( array( 87601 ), 'c-601' );
$GLOBALS['made'] = 0; $GLOBALS['fail_after'] = 1;
$r = pps_mp_create_subtasks( 87601, 'c-601' );
ok( is_wp_error( $r ) && strpos( $r->get_error_message(), 'Made 1' ) === 0 && isset( pps_mp_item_map()[31] ) && ! isset( pps_mp_item_map()[32] ), 'a failure part-way says how many were made and keeps them', $r );
$GLOBALS['fail_after'] = 0; $GLOBALS['made'] = 5;
ok( pps_mp_create_subtasks( 87601, 'c-601' ) === 1, 'trying again makes only the missing one' );

// ── read-only, by construction ──
$src = file_get_contents( __DIR__ . '/pps-missive-panel.php' );
$code = preg_replace( '#/\*.*?\*/#s', '', $src );
foreach ( array( 'createTask', 'addLabels', 'removeLabels', 'assign(', 'addAssignees', 'comment(', 'archive(', 'trash(', 'close(', 'setSubject', 'setColor', 'compose', 'reply(', 'forward(', 'insertText', 'storeSet' ) as $w ) {
    ok( strpos( $code, 'Missive.' . $w ) === false, "the page never calls Missive.$w" );
}
foreach ( array( 'update_status', '->save(', 'wc_update_order_item_meta', 'update_meta_data', 'add_order_note', 'wp_mail' ) as $w ) {
    ok( strpos( $code, $w ) === false, "the server never calls $w" );
}
ok( substr_count( $code, "pps_mp_api( 'PATCH'" ) === 1 && substr_count( $code, "pps_mp_api( 'POST', 'tasks'" ) === 1 && substr_count( $code, "pps_mp_api( 'POST'" ) === 1 && strpos( $code, "pps_mp_api( 'DELETE'" ) === false, 'the only Missive writes are a task state change and creating a subtask' );
ok( strpos( $src, "'methods' => 'GET', 'permission_callback' => \$key_ok" ) !== false && strpos( $src, "'methods' => 'POST', 'permission_callback' => \$key_ok" ) !== false && strpos( $src, 'pps_mp_key_ok( (string) $req->get_header' ) !== false, 'both endpoints need the key header' );
$html = pps_mp_page_html( 'KEY123', 'https://x/wp-json/pps/v1/missive/panel' );
ok( strpos( $html, '"key":"KEY123"' ) !== false && strpos( $html, 'textContent' ) !== false && strpos( $html, 'innerHTML' ) === false, 'the page carries its key and writes text, never HTML' );
ok( substr_count( strtolower( $html ), '</script>' ) === 2, 'no stray script close inside the page script' );
ok( substr_count( $html, 'data-cfasync="false"' ) === 2, 'Cloudflare Rocket Loader is told to leave both scripts alone' );
ok( strpos( $html, "class:'tools'" ) === false && strpos( $html, "{cls:'tools'}" ) !== false && strpos( $html, ".tools{position:sticky" ) !== false, 'the filter bar stays at the top while the board scrolls' );
foreach ( array( "['todo','To do']", "['in_progress','In progress']", "['closed','Done']", "['none','No subtask']", "'Ships within 7 days'", "'Any paper'", "' rush only'" ) as $w ) ok( strpos( $html, $w ) !== false, "the panel offers the filter $w" );
ok( preg_match( '/"build":"[0-9a-f]{8}"/', $html ) === 1, 'the page carries a build stamp, so the version Missive shows can be told apart' );

// ── the order-number finder, run as the browser runs it ──
if ( preg_match( '/function ppsOrderNumbers\(text\)\{.*?\n\}/s', $html, $m ) ) {
    $js = $m[0] . "\nconsole.log(JSON.stringify([ppsOrderNumbers('[Priority Print Service]: New order #87347'),ppsOrderNumbers('Re: Your order 87301 and order #87302, also #87301'),ppsOrderNumbers('Call 602-818-0021 about 12345 sheets'),ppsOrderNumbers('Order #  87455 is ready')]));";
    $f = tempnam( sys_get_temp_dir(), 'mp' ) . '.js'; file_put_contents( $f, $js );
    $got = json_decode( (string) shell_exec( 'node ' . escapeshellarg( $f ) . ' 2>&1' ), true ); @unlink( $f );
    ok( $got === array( array( '87347' ), array( '87301', '87302' ), array(), array( '87455' ) ), 'order numbers come from "#87347" and "order 87301", never from a phone number or a sheet count', $got );
} else { ok( false, 'ppsOrderNumbers is in the page' ); }

echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> MISSIVE PANEL OK ($n checks)\n";
exit( $fail ? 1 : 0 );
