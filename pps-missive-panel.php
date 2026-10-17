<?php
/**
 * PPS Missive Panel — the production view inside Missive's sidebar. READ-ONLY.
 *
 * Owner, 2026-10-17: "write the sidebar functions first so I can test it against live tasks
 * without interfering with anything". Missive's custom iFrame integration loads a page in
 * its right-hand sidebar and tells it which conversation is open. This file serves that page
 * and the data behind it:
 *
 * - The page (`/?pps_missive_panel=1&k=<key>`) finds order numbers in the open
 *   conversation's subject and latest message, asks the site for those jobs, and shows each
 *   one: the production window (start → must-ship → delivery), the Job Ticket, the paper it
 *   runs on, and the other open jobs on the same paper and press sheet whose windows overlap
 *   ("run together"). With no order in view it shows every open job by must-ship date.
 *   It also shows, greyed, what the planned automation WOULD write to Missive (task due
 *   date, title, labels), so it can be compared with today's tasks before anything writes.
 * - The data (`GET /wp-json/pps/v1/missive/panel`) answers only with the panel key, sent
 *   in the `X-PPS-Panel-Key` header.
 *
 * NOTHING HERE WRITES. The page calls only Missive's read and navigation methods
 * (on, fetchConversations, search, openURL) — never createTask, addLabels, assign, comment
 * or any other method that changes Missive — and the endpoint never changes an order.
 * tools-missive-panel-test.php checks both.
 *
 * The key lives in `wp_options['pps_missive_panel_key']` (autoload off) and is made on the
 * first visit to PPS Calculators → Missive Panel, which shows the URL to paste into
 * Missive (Settings → Integrations → Add integration → Custom). Regenerating it there cuts
 * off the old URL. The key is in the URL because Missive loads the page by URL; it lets the
 * holder read open-job schedules and tickets (names, ship-to), nothing more.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_MP_KEY_OPTION', 'pps_missive_panel_key' );
define( 'PPS_MP_CACHE', 'pps_missive_panel_open' );

/* ─────────────────────────────────────────────────────────────
 * Pure helpers — the test drives these directly
 * ───────────────────────────────────────────────────────────── */

/** A Y-m-d out of anything date-shaped, or ''. */
function pps_mp_ymd( $v ) {
    $v = trim( (string) $v );
    if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) return "$m[1]-$m[2]-$m[3]";
    $t = $v !== '' ? strtotime( $v ) : false;
    return $t ? gmdate( 'Y-m-d', $t ) : '';
}

/** "Label: value" lines of the Job Ticket → [[label, value], …]. */
function pps_mp_ticket_pairs( $text ) {
    $out = array();
    foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
        $line = trim( $line );
        if ( $line === '' ) continue;
        $p = strpos( $line, ': ' );
        $out[] = $p === false ? array( '', $line ) : array( substr( $line, 0, $p ), substr( $line, $p + 2 ) );
    }
    return $out;
}

/**
 * One record per order line: dates, ticket, paper rows, finishing. `$paper` maps item id →
 * paper rows already classified by pps_po_build() (the Paper Order's own reading), so the
 * panel and the supply order can never disagree about what a job runs on.
 */
function pps_mp_jobs( array $orders, array $paper = array() ) {
    $jobs = array();
    foreach ( $orders as $order ) {
        $customer = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $order->get_billing_company();
        foreach ( $order->get_items() as $item_id => $item ) {
            $meta = json_decode( (string) $item->get_meta( '_pps_metadata' ), true );
            $meta = is_array( $meta ) ? $meta : array();
            $j = array(
                'order' => (int) $order->get_id(), 'item' => (int) $item_id, 'status' => $order->get_status(),
                'customer' => $customer, 'product' => $item->get_name(),
                'job' => (string) ( $meta['jobName'] ?? '' ),
                'qty' => (string) ( $meta['totalQty'] ?? $meta['qty'] ?? '' ),
                'size' => (string) ( $meta['sizeLabel'] ?? '' ),
                'start' => pps_mp_ymd( $item->get_meta( 'PPS-Production-Start' ) ?: ( $meta['productionStartDate'] ?? '' ) ),
                'must_ship' => pps_mp_ymd( $meta['mustShipByDate'] ?? '' ),
                'delivery' => pps_mp_ymd( $item->get_meta( '_pps_delivery_date' ) ?: ( $meta['estimatedDeliveryDate'] ?? '' ) ),
                'rush' => (float) $item->get_meta( '_pps_rush' ) > 0 || (float) ( $meta['rushCost'] ?? 0 ) > 0,
                'addons' => (string) $item->get_meta( 'Add-ons' ),
                'ticket' => pps_mp_ticket_pairs( $item->get_meta( 'Job Ticket' ) ),
                'printed' => (string) $item->get_meta( '_pps_paper_done' ) !== '',
                'source' => $meta ? 'calculator' : 'custom',
                'papers' => array(),
            );
            if ( $j['delivery'] === '' && ! $meta ) $j['delivery'] = pps_mp_ymd( $item->get_meta( 'Requested delivery' ) );
            if ( ! $j['ticket'] && ! $meta ) {
                // A custom line has no ticket: show what a person can read on it.
                foreach ( $item->get_meta_data() as $m ) {
                    $d = $m->get_data();
                    if ( strpos( (string) $d['key'], '_' ) === 0 || ! is_scalar( $d['value'] ) || (string) $d['value'] === '' ) continue;
                    $j['ticket'][] = array( (string) $d['key'], (string) $d['value'] );
                }
            }
            foreach ( $paper[ (int) $item_id ] ?? array() as $r ) {
                $p = $r['paper'] ?? null;
                if ( ! is_array( $p ) ) continue;
                $j['papers'][] = array(
                    'key' => ( (string) ( $p['val'] ?? '' ) !== '' ? 'v' . $p['val'] : 'l' . strtolower( (string) $p['label'] ) ) . '|' . $r['sheet'],
                    'label' => (string) $p['label'], 'sheet' => str_replace( 'x', '×', (string) $r['sheet'] ),
                    'sheets' => (int) $r['sheets'], 'role' => (string) $r['role'], 'in_stock' => ! empty( $p['in_stock'] ),
                );
            }
            $jobs[] = $j;
        }
    }
    return $jobs;
}

/** A job's window as [from, to] Y-m-d; from falls back to today, to to delivery. */
function pps_mp_window( array $j, $today ) {
    $to   = $j['must_ship'] !== '' ? $j['must_ship'] : $j['delivery'];
    $from = $j['start'] !== '' ? $j['start'] : $today;
    if ( $to !== '' && $from > $to ) $from = $to;
    return array( $from, $to );
}

/**
 * Open jobs that could run with this one: they share a paper AND press sheet, and their
 * windows overlap. A job with no end date, already printed, or the job itself, is left out.
 * Each mate lists the shared paper and its sheets; `combined` is per shared paper.
 */
function pps_mp_batch( array $job, array $open, $today ) {
    list( $f, $t ) = pps_mp_window( $job, $today );
    if ( $t === '' || ! $job['papers'] ) return array( 'mates' => array(), 'combined' => array() );
    $mine = array();
    foreach ( $job['papers'] as $p ) $mine[ $p['key'] ] = $p;
    $mates = array(); $combined = array();
    foreach ( $mine as $k => $p ) $combined[ $k ] = array( 'label' => $p['label'], 'sheet' => $p['sheet'], 'sheets' => 0, 'jobs' => 0 );
    foreach ( $open as $o ) {
        if ( $o['item'] === $job['item'] || $o['printed'] ) continue;
        list( $of, $ot ) = pps_mp_window( $o, $today );
        if ( $ot === '' || $of > $t || $ot < $f ) continue;
        $shared = array();
        foreach ( $o['papers'] as $p ) {
            if ( ! isset( $mine[ $p['key'] ] ) ) continue;
            $shared[] = array( 'label' => $p['label'], 'sheet' => $p['sheet'], 'sheets' => $p['sheets'] );
            $combined[ $p['key'] ]['sheets'] += $p['sheets'];
            $combined[ $p['key'] ]['jobs']++;
        }
        if ( ! $shared ) continue;
        $mates[] = array( 'order' => $o['order'], 'customer' => $o['customer'], 'what' => $o['job'] !== '' ? $o['job'] : $o['product'],
                          'start' => $of, 'must_ship' => $ot, 'rush' => $o['rush'], 'shared' => $shared );
    }
    foreach ( $job['papers'] as $p ) if ( isset( $combined[ $p['key'] ] ) ) $combined[ $p['key'] ]['sheets'] += $p['sheets'];
    usort( $mates, function ( $a, $b ) { return strcmp( $a['must_ship'], $b['must_ship'] ) ?: $a['order'] <=> $b['order']; } );
    $combined = array_values( array_filter( $combined, function ( $c ) { return $c['jobs'] > 0; } ) );
    return array( 'mates' => $mates, 'combined' => $combined );
}

/**
 * What the planned automation would write to Missive for this job. Shown greyed in the
 * panel so it can be checked against today's tasks; nothing sends it.
 */
function pps_mp_preview( array $j ) {
    $md = function ( $ymd ) { return $ymd !== '' ? gmdate( 'M j', strtotime( $ymd . ' 12:00 UTC' ) ) : ''; };
    $what  = $j['job'] !== '' ? $j['job'] : $j['product'];
    $title = '#' . $j['order'] . ' ' . $what . ( $j['start'] !== '' ? ' · start ' . $md( $j['start'] ) : '' )
           . ( $j['must_ship'] !== '' ? ' · ship ' . $md( $j['must_ship'] ) : '' );
    $labels = array();
    foreach ( $j['papers'] as $p ) { $labels[] = $p['label']; $labels[] = $p['sheet']; }
    if ( $j['rush'] ) $labels[] = 'RUSH';
    foreach ( preg_split( '/;\s*/', $j['addons'] ) as $a ) {
        $a = trim( $a );
        if ( $a === '' ) continue;
        $labels[] = trim( strpos( $a, ':' ) !== false ? substr( $a, 0, strpos( $a, ':' ) ) : $a );
    }
    return array( 'due' => $j['must_ship'], 'title' => $title, 'labels' => array_values( array_unique( array_filter( $labels ) ) ) );
}

/* ─────────────────────────────────────────────────────────────
 * WordPress side
 * ───────────────────────────────────────────────────────────── */

function pps_mp_key( $make = false ) {
    $k = (string) get_option( PPS_MP_KEY_OPTION, '' );
    if ( $k === '' && $make ) {
        $k = bin2hex( random_bytes( 20 ) );
        update_option( PPS_MP_KEY_OPTION, $k, false );
    }
    return $k;
}

function pps_mp_key_ok( $given ) {
    $k = pps_mp_key();
    return $k !== '' && is_string( $given ) && $given !== '' && hash_equals( $k, $given );
}

function pps_mp_today() {
    $tz = function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix';
    try { return ( new DateTimeImmutable( 'now', new DateTimeZone( $tz ) ) )->format( 'Y-m-d' ); }
    catch ( \Throwable $e ) { return gmdate( 'Y-m-d' ); }
}

/** Paper rows by item id, from the Paper Order's reading of these orders. */
function pps_mp_paper_index( array $orders ) {
    $idx = array();
    if ( ! function_exists( 'pps_po_build' ) ) return $idx;
    $b = pps_po_build( $orders );
    foreach ( $b['jobs'] as $j ) $idx[ (int) $j['item_id'] ] = $j['rows'];
    return $idx;
}

/** Every open job (processing, on hold), cached five minutes — one scan serves many panels. */
function pps_mp_open_jobs() {
    $c = get_transient( PPS_MP_CACHE );
    if ( is_array( $c ) ) return $c;
    $orders = function_exists( 'wc_get_orders' )
        ? wc_get_orders( array( 'limit' => 300, 'orderby' => 'date', 'order' => 'ASC', 'status' => array( 'processing', 'on-hold' ) ) ) : array();
    $orders = is_array( $orders ) ? $orders : array();
    $jobs = pps_mp_jobs( $orders, pps_mp_paper_index( $orders ) );
    set_transient( PPS_MP_CACHE, $jobs, 5 * 60 );
    return $jobs;
}

/** The endpoint's answer. `$ids` are order numbers found in the conversation. */
function pps_mp_answer( array $ids ) {
    $today = pps_mp_today();
    $open  = pps_mp_open_jobs();
    $out   = array( 'today' => $today, 'orders' => array(), 'missing' => array(), 'board' => array() );
    foreach ( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, 5 ) as $id ) {
        if ( $id <= 0 ) continue;
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
        if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) { $out['missing'][] = $id; continue; }
        $jobs = pps_mp_jobs( array( $order ), pps_mp_paper_index( array( $order ) ) );
        foreach ( $jobs as &$j ) {
            $j['window'] = pps_mp_window( $j, $today );
            $j['batch']  = pps_mp_batch( $j, $open, $today );
            $j['preview'] = pps_mp_preview( $j );
        }
        unset( $j );
        $out['orders'][] = array( 'order' => $id, 'status' => $order->get_status(),
            'admin_url' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $id ), 'jobs' => $jobs );
    }
    if ( ! $out['orders'] ) {
        foreach ( $open as $j ) {
            list( $f, $t ) = pps_mp_window( $j, $today );
            $out['board'][] = array( 'order' => $j['order'], 'customer' => $j['customer'], 'what' => $j['job'] !== '' ? $j['job'] : $j['product'],
                'qty' => $j['qty'], 'size' => $j['size'], 'start' => $f, 'must_ship' => $t, 'rush' => $j['rush'], 'printed' => $j['printed'],
                'papers' => array_map( function ( $p ) { return $p['label'] . ' ' . $p['sheet'] . ' (' . $p['sheets'] . ')'; }, $j['papers'] ) );
        }
        usort( $out['board'], function ( $a, $b ) {
            return strcmp( $a['must_ship'] !== '' ? $a['must_ship'] : '9999', $b['must_ship'] !== '' ? $b['must_ship'] : '9999' ) ?: $a['order'] <=> $b['order'];
        } );
    }
    return $out;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'pps/v1', '/missive/panel', array(
        'methods' => 'GET',
        'permission_callback' => function ( $req ) { return pps_mp_key_ok( (string) $req->get_header( 'x_pps_panel_key' ) ); },
        'callback' => function ( $req ) {
            $ids = array_filter( array_map( 'intval', explode( ',', (string) $req->get_param( 'orders' ) ) ) );
            $res = new WP_REST_Response( pps_mp_answer( $ids ) );
            $res->header( 'Cache-Control', 'no-store' );
            return $res;
        },
    ) );
} );

/* ── The page Missive loads ───────────────────────────────────── */

add_action( 'init', function () {
    if ( ! isset( $_GET['pps_missive_panel'] ) ) return;
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    nocache_headers();
    header_remove( 'X-Frame-Options' );
    $k = (string) wp_unslash( $_GET['k'] ?? '' );
    if ( ! pps_mp_key_ok( $k ) ) { status_header( 403 ); header( 'Content-Type: text/plain; charset=UTF-8' ); echo 'This panel link is not valid. Get the current one from PPS Calculators → Missive Panel.'; exit; }
    header( 'Content-Type: text/html; charset=UTF-8' );
    header( 'Referrer-Policy: no-referrer' );
    echo pps_mp_page_html( $k, rest_url( 'pps/v1/missive/panel' ) );
    exit;
}, 1 );

function pps_mp_page_html( $key, $api ) {
    $cfg = wp_json_encode( array( 'key' => $key, 'api' => $api ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PPS Production</title>
<link href="https://integrations.missiveapp.com/missive.css" rel="stylesheet">
<style>
body{font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:10px;color:var(--missive-text-color-a,#222)}
h2{font-size:14px;margin:4px 0 6px} h3{font-size:12px;margin:12px 0 4px;text-transform:uppercase;letter-spacing:.04em;color:#777}
.card{border:1px solid rgba(128,128,128,.25);border-radius:6px;padding:8px;margin:0 0 10px}
.muted{color:#888} .rush{color:#c0392b;font-weight:600} .late{color:#c0392b} .ok{color:#2e7d32}
.bar{position:relative;height:8px;background:rgba(128,128,128,.18);border-radius:4px;margin:6px 0 2px}
.bar i{position:absolute;top:0;bottom:0;background:#3b82f6;border-radius:4px}
.bar b{position:absolute;top:-3px;width:2px;height:14px;background:#c0392b}
table{border-collapse:collapse;width:100%} td{padding:2px 4px;vertical-align:top;border-top:1px solid rgba(128,128,128,.12);overflow-wrap:anywhere}
td:first-child{color:#888;white-space:nowrap}
a{color:#2563eb;cursor:pointer;text-decoration:none}
.preview{opacity:.65;font-size:12px} .tag{display:inline-block;border:1px solid rgba(128,128,128,.4);border-radius:3px;padding:0 4px;margin:1px 2px 1px 0;font-size:11px}
input{width:120px} .row{padding:4px 0;border-top:1px solid rgba(128,128,128,.12)}
</style></head>
<body><div id="app" class="muted">Loading…</div>
<script data-cfasync="false" src="https://integrations.missiveapp.com/missive.js"></script>
<script data-cfasync="false">
(function(){
'use strict';
var CFG = {$cfg};
var app = document.getElementById('app');
var inMissive = typeof window.Missive !== 'undefined' && window.parent !== window;

/* READ-ONLY: this page may call Missive.on, Missive.fetchConversations, Missive.search and
   Missive.openURL — nothing that changes a conversation, task, label or assignee. */

function el(tag, attrs, kids){ var e=document.createElement(tag); attrs=attrs||{};
  for (var k in attrs){ if(k==='text') e.textContent=attrs[k]; else if(k==='cls') e.className=attrs[k]; else if(k==='on') e.addEventListener('click',attrs[k]); else if(k==='style') e.setAttribute('style',attrs[k]); }
  (kids||[]).forEach(function(c){ if(c) e.appendChild(typeof c==='string'?document.createTextNode(c):c); }); return e; }
function md(ymd){ if(!ymd) return '—'; var d=new Date(ymd+'T12:00:00Z'); return d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',timeZone:'UTC'}); }
function days(a,b){ return Math.round((Date.parse(b+'T12:00:00Z')-Date.parse(a+'T12:00:00Z'))/864e5); }
function open(url){ if(inMissive && Missive.openURL) Missive.openURL(url); else window.open(url,'_blank','noopener'); }
function find(order){ if(inMissive && Missive.search) Missive.search(String(order)); }

/* Order numbers in what the conversation says: "#87347", "Order 87347", "order #87347". */
function ppsOrderNumbers(text){
  var seen={}, out=[], re=/(?:order\s*#?\s*|#\s?)(\d{5,6})\b/gi, m;
  while((m=re.exec(String(text||'')))){ if(!seen[m[1]]){ seen[m[1]]=1; out.push(m[1]); } }
  return out.slice(0,5);
}
window.ppsOrderNumbers = ppsOrderNumbers;

function load(ids){
  app.className='muted'; app.textContent='Loading…';
  var url = CFG.api + (CFG.api.indexOf('?')<0?'?':'&') + 'orders=' + encodeURIComponent(ids.join(','));
  fetch(url,{headers:{'X-PPS-Panel-Key':CFG.key},credentials:'omit',cache:'no-store'})
    .then(function(r){ if(!r.ok) throw new Error('The site answered '+r.status+'.'); return r.json(); })
    .then(render)
    .catch(function(e){ app.className=''; app.textContent=''; app.appendChild(el('div',{cls:'late',text:'Could not load: '+e.message})); });
}

function windowBar(j, today){
  var f=j.window[0], t=j.window[1]; if(!t) return el('div',{cls:'muted',text:'No ship date on this job.'});
  var lo = f<today?f:today, hi = t>today?t:today, span=Math.max(1,days(lo,hi));
  var bar=el('div',{cls:'bar'},[ el('i',{style:'left:'+(100*days(lo,f)/span)+'%;width:'+Math.max(2,100*days(f,t)/span)+'%'}),
    el('b',{style:'left:'+(100*days(lo,today)/span)+'%'}) ]);
  var left = days(today,t), state = left<0 ? el('span',{cls:'late',text:'LATE by '+(-left)+' day'+(left===-1?'':'s')}) :
    el('span',{cls:left<=1?'rush':'ok',text:left===0?'ships today':left+' day'+(left===1?'':'s')+' to ship'});
  return el('div',{},[ el('div',{},['Start ',el('b',{text:md(f)}),' → ship by ',el('b',{text:md(t)}),' · delivery ',md(j.delivery),' · ',state]), bar ]);
}

function jobCard(j, today){
  var c=el('div',{cls:'card'});
  c.appendChild(el('h2',{},[ (j.job||j.product), j.rush?el('span',{cls:'rush',text:' RUSH'}):null, j.printed?el('span',{cls:'muted',text:' · marked printed'}):null ]));
  c.appendChild(el('div',{cls:'muted',text:[j.qty?j.qty+' qty':'',j.size].filter(Boolean).join(' · ')}));
  c.appendChild(windowBar(j, today));
  if(j.papers.length){ c.appendChild(el('h3',{text:'Paper'}));
    j.papers.forEach(function(p){ c.appendChild(el('div',{},[p.label+' · '+p.sheet+' · '+p.sheets+' sheets'+(p.role!=='stock'?' ('+p.role+')':''), p.in_stock?null:el('span',{cls:'rush',text:' to order'})])); }); }
  c.appendChild(el('h3',{text:'Run together'}));
  if(!j.batch.mates.length){ c.appendChild(el('div',{cls:'muted',text:'No other open job on the same paper and sheet in this window.'})); }
  else {
    j.batch.combined.forEach(function(x){ c.appendChild(el('div',{},[el('b',{text:x.sheets+' sheets'}),' of '+x.label+' '+x.sheet+' across '+(x.jobs+1)+' jobs'])); });
    j.batch.mates.forEach(function(m){ c.appendChild(el('div',{cls:'row'},[ el('a',{text:'#'+m.order,on:function(){find(m.order);}}), ' '+m.customer+' — '+m.what+' · ship '+md(m.must_ship), m.rush?el('span',{cls:'rush',text:' RUSH'}):null ])); });
  }
  if(j.addons){ c.appendChild(el('h3',{text:'Finishing'})); c.appendChild(el('div',{text:j.addons})); }
  if(j.ticket.length){ c.appendChild(el('h3',{text:'Job Ticket'})); var t=el('table');
    j.ticket.forEach(function(p){ t.appendChild(el('tr',{},[el('td',{text:p[0]}),el('td',{text:p[1]})])); }); c.appendChild(t); }
  var pv=el('div',{cls:'preview'},[ el('h3',{text:'Automation preview (not sent)'}),
    el('div',{text:'Task: '+j.preview.title}), el('div',{text:'Due: '+md(j.preview.due)}) ]);
  var tags=el('div'); j.preview.labels.forEach(function(l){ tags.appendChild(el('span',{cls:'tag',text:l})); }); pv.appendChild(tags);
  c.appendChild(pv);
  return c;
}

function render(d){
  app.className=''; app.textContent='';
  if(!inMissive){ var i=el('input',{}); i.placeholder='Order #'; var b=el('button',{text:'Look up',on:function(){ load(ppsOrderNumbers('#'+i.value)); }});
    app.appendChild(el('div',{cls:'card'},[el('div',{cls:'muted',text:'Outside Missive: type an order number to test.'}), i, ' ', b])); }
  d.orders.forEach(function(o){
    app.appendChild(el('div',{style:'margin:0 0 6px'},[ el('b',{text:'Order #'+o.order}), ' · '+o.status+' · ', el('a',{text:'open in WooCommerce',on:function(){open(o.admin_url);}}) ]));
    o.jobs.forEach(function(j){ app.appendChild(jobCard(j, d.today)); });
  });
  d.missing.forEach(function(id){ app.appendChild(el('div',{cls:'muted',text:'#'+id+' is not an order on the site.'})); });
  if(!d.orders.length){
    app.appendChild(el('h2',{text:'Open jobs by ship date'}));
    if(!d.board.length) app.appendChild(el('div',{cls:'muted',text:'No open jobs.'}));
    d.board.forEach(function(r){
      var late = r.must_ship && r.must_ship < d.today;
      app.appendChild(el('div',{cls:'row'},[ el('span',{cls:late?'late':'',text:md(r.must_ship)}), ' ', el('a',{text:'#'+r.order,on:function(){find(r.order);}}),
        ' '+r.customer+' — '+r.what, r.rush?el('span',{cls:'rush',text:' RUSH'}):null, r.printed?el('span',{cls:'muted',text:' · printed'}):null,
        el('div',{cls:'muted',text:r.papers.join(', ')}) ]));
    });
  }
}

if(inMissive){
  Missive.on('change:conversations', function(ids){
    if(!ids || ids.length!==1){ load([]); return; }
    Missive.fetchConversations(ids).then(function(cs){
      var c=cs&&cs[0]; if(!c){ load([]); return; }
      var m=c.latest_message||{}, body=String(m.body||'').replace(/<[^>]+>/g,' ');
      load(ppsOrderNumbers([c.subject,m.subject,m.preview,body].join(' \\n ')));
    }, function(){ load([]); });
  }, { retroactive: true });
} else { load([]); }
})();
</script></body></html>
HTML;
}

/* ── Admin: the link to paste into Missive ────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page( 'pps-calculators', 'Missive Panel', '🧭 Missive Panel', 'manage_woocommerce', 'pps-missive-panel', 'pps_mp_render_admin' );
}, 62 );

add_action( 'admin_post_pps_mp_regen', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
    check_admin_referer( 'pps_mp_regen' );
    delete_option( PPS_MP_KEY_OPTION );
    pps_mp_key( true );
    wp_safe_redirect( admin_url( 'admin.php?page=pps-missive-panel&regen=1' ) );
    exit;
} );

function pps_mp_render_admin() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );
    $url = add_query_arg( array( 'pps_missive_panel' => 1, 'k' => pps_mp_key( true ) ), home_url( '/' ) );
    echo '<div class="wrap"><h1>Missive Panel</h1>'
       . '<p>A read-only production view in Missive\'s sidebar: the open conversation\'s order, its window, paper and the jobs it could run with. It never changes Missive or an order.</p>'
       . ( isset( $_GET['regen'] ) ? '<div class="notice notice-success"><p>New link made. The old one no longer works — update it in Missive.</p></div>' : '' )
       . '<h2>Add it to Missive</h2><ol>'
       . '<li>In Missive: Settings → Integrations → Add integration → <strong>Custom</strong> (iFrame).</li>'
       . '<li>Name it "PPS Production" and paste this URL:</li></ol>'
       . '<p><input type="text" readonly value="' . esc_attr( $url ) . '" style="width:100%;max-width:900px" onclick="this.select()"></p>'
       . '<p>Open the link in a browser to try it outside Missive (it offers an order-number box). Anyone with the link can read open-job schedules and tickets, so share it only inside Missive.</p>'
       . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'pps_mp_regen', '_wpnonce', true, false )
       . '<input type="hidden" name="action" value="pps_mp_regen"><button class="button">Make a new link (cuts off the old one)</button></form></div>';
}
