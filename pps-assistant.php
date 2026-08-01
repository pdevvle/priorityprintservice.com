<?php
/**
 * Plugin Name: PPS Assistant
 * Description: Claude-powered on-site customer service chat. Grounded on real order
 *              data via tools; never prices, never promises dates. Template/scaffold —
 *              see docs/CUSTOMER_SERVICE_BOT.md for the design rationale.
 * Version: 0.2.1
 * Author: Priority Print Service
 *
 * ── WHY NO COMPOSER ──
 * The official anthropic-ai/sdk PHP package is the better long-term transport, but this
 * plugin is deployed as single-file writes via the priority-print MCP and the host has no
 * Composer. Every HTTP call is isolated in pps_assistant_api_call() — swapping to the SDK
 * is a one-function change, nothing else in this file knows about the wire format.
 *
 * ── WHAT THIS IS NOT ──
 * Not a pricing engine. Not an order-mutation surface. It reads, it links, it escalates.
 * The calculators price; docs/MASTER_PRICING_LOGIC.md is the source of truth.
 *
 * Lives in the pps-calculators folder but is its own activatable plugin, exactly like
 * pps-html-deploy.php and pps-cart-price-floor.php. Deactivating it is a second kill
 * switch independent of the config flag. Presentation lives in pps-assistant-ui.php.
 *
 * ── SETUP ──
 * 1. Activate the plugin (it does nothing at all until you do).
 * 2. PPS Calculators → Assistant: paste the Anthropic API key, leave "Visible to" on
 *    "Logged-in admins only", tick Enabled, Save.
 * 3. Load any front-end page while logged in as an admin.
 *
 * ── CHANGELOG ──
 * 0.2.1  Full intake gate (name, company, email, phone). Bookmarkable availability URL —
 *        a secret-authenticated REST route so the toggle can be flipped from a phone
 *        without a wp-admin round trip.
 * 0.2.0  Email gate, manual availability toggle, two-path escalation, record_contact.
 *        Plus the hardening pass from the adversarial review (13 confirmed findings):
 *        a bailed turn no longer poisons the session history; 'visible_to' is now a real
 *        permission callback rather than a rendering-only gate; escalations are recorded
 *        durably before mail is attempted and the model is told when delivery failed; the
 *        daily/per-IP budget is metered in API calls and is atomic under a persistent
 *        object cache; the guest-auth limiter fails closed; the policy prompt is no longer
 *        mangled by sanitize_textarea_field(). max_tokens 4096 -> 8192. UI split out.
 * 0.1.1  Send X-WP-Nonce on the fetch; surface real HTTP status to admins; guard mb_substr.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( defined( 'PPS_ASSISTANT_VERSION' ) ) return;   // co-load guard
define( 'PPS_ASSISTANT_VERSION', '0.2.0' );
define( 'PPS_ASSISTANT_API_URL', 'https://api.anthropic.com/v1/messages' );

// ═══════════════════════════════════════════════════════════════
// 1. CONFIG + KILL SWITCH
// ═══════════════════════════════════════════════════════════════

function pps_assistant_config() {
    $defaults = array(
        'enabled'       => false,          // ← ships OFF. This is the kill switch.
        'visible_to'    => 'admins',       // 'admins' | 'everyone' — who sees the widget
        'api_key'       => '',
        // Human availability. Operator-chosen: a manual toggle, no schedule. Accurate when
        // maintained, and silently wrong when someone forgets to flip it off — so the admin
        // screen shows how long it has been on.
        'available_now'   => false,
        'available_since' => 0,
        'avail_secret'    => '',           // minted lazily; authenticates the bookmark URL
        'require_email'   => true,         // gate the chat behind an email address
        // Stage 2 — live handoff into Missive. Blank until the custom channel exists.
        'missive_channel_id'     => '',
        'missive_token'          => '',
        'missive_webhook_secret' => '',
        'model'         => 'claude-opus-5',
        'effort'        => 'medium',       // low | medium | high | xhigh | max
        // Thinking is ON by default on Opus 5 and max_tokens caps thinking PLUS reply. 4096
        // truncated tool-using turns routinely. Kept under ~16k because this is a
        // non-streaming wp_remote_post on a 120s timeout.
        'max_tokens'    => 8192,
        'max_turns'     => 12,             // per session
        'max_tool_hops' => 6,              // per turn — bounds a runaway tool loop
        'daily_cap'     => 300,            // API calls per day, site-wide
        'policy'        => '',             // blank = use pps_assistant_policy() default
    );
    $saved = get_option( 'pps_assistant_config', array() );
    if ( is_string( $saved ) ) $saved = json_decode( $saved, true ); // MCP writes options as JSON strings
    return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
}

/**
 * Single gate checked before ANY API call. Flip 'enabled' to false and the whole
 * assistant degrades to a static "we'll get back to you" — no requests, no spend.
 */
function pps_assistant_enabled() {
    $cfg = pps_assistant_config();
    return ! empty( $cfg['enabled'] ) && ! empty( $cfg['api_key'] );
}

/**
 * Is a human available to take a live handoff right now?
 *
 * Manual toggle only, by operator choice — no schedule, no presence detection. The
 * failure mode is a toggle left on overnight, so the admin screen reports how long it
 * has been on rather than silently trusting it.
 */
function pps_assistant_human_available() {
    $cfg = pps_assistant_config();
    return ! empty( $cfg['available_now'] );
}

/**
 * When did availability last go ON? Pure, because two callers need the identical rule and
 * a duplicated rule drifts: the admin checkbox and the bookmarkable URL below.
 *
 * Re-asserting an already-on state keeps the ORIGINAL timestamp. That matters — tapping
 * the "on" bookmark a second time must not reset the "on for 9h" warning, which is the
 * only thing that catches a toggle left on overnight.
 */
function pps_assistant_availability_stamp( $was_on, $was_since, $now_on ) {
    if ( ! $now_on ) return 0;
    return $was_on ? (int) $was_since : time();
}

/** Flip availability and stamp it. Returns the new state. */
function pps_assistant_set_available( $on ) {
    $cfg = pps_assistant_config();
    $on  = (bool) $on;

    $cfg['available_since'] = pps_assistant_availability_stamp(
        ! empty( $cfg['available_now'] ), $cfg['available_since'] ?? 0, $on
    );
    $cfg['available_now'] = $on;

    update_option( 'pps_assistant_config', $cfg );
    return $on;
}

/**
 * Secret for the bookmarkable availability URL. Minted on first read rather than on save,
 * so the admin screen can show a working link the moment this deploys.
 *
 * Deliberately NOT the Missive webhook secret: different blast radius, and rotating one
 * should never silently break the other.
 */
function pps_assistant_avail_secret() {
    $cfg = pps_assistant_config();
    if ( ! empty( $cfg['avail_secret'] ) ) return (string) $cfg['avail_secret'];

    $cfg['avail_secret'] = wp_generate_password( 32, false, false );
    update_option( 'pps_assistant_config', $cfg );
    return $cfg['avail_secret'];
}

/**
 * Authorize an availability flip.
 *
 * The secret in the query string IS the authentication — there is no cookie on a phone
 * that has never logged into wp-admin, which is the entire point of the bookmark. Compared
 * with hash_equals() so the route is not an oracle for guessing the secret a byte at a
 * time. An admin with a live session is let through without it, so the link keeps working
 * from a desktop after a rotation.
 *
 * Worst case if the URL leaks: someone can flip a boolean that changes which of two
 * honest sentences the bot says. No customer data is reachable from here.
 */
function pps_assistant_availability_authorized( $given ) {
    if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) return true;

    // `?k[]=x` arrives as an array. Casting it would emit a warning and compare "Array",
    // so reject anything that is not already a string rather than coercing it.
    if ( ! is_string( $given ) ) return false;

    $cfg      = pps_assistant_config();
    $expected = (string) ( $cfg['avail_secret'] ?? '' );

    if ( $expected === '' || $given === '' ) return false;
    return hash_equals( $expected, $given );
}

/** True once a live handoff has happened — Claude must stop answering this session. */
function pps_assistant_session_is_human( array $session ) {
    return ( $session['mode'] ?? 'bot' ) === 'human';
}

/**
 * Who gets escalation mail.
 *
 * get_option()'s default only applies when the row is ABSENT. pps-calculators.php:1124
 * reads this same option with an is_email() guard, which implies it can exist as an empty
 * string — in which case a naive read hands wp_mail() an empty recipient and every
 * escalation fails silently.
 */
function pps_assistant_staff_email() {
    $cand = get_option( 'pps_question_recipient', '' );
    return is_email( $cand ) ? $cand : get_option( 'admin_email' );
}

/**
 * Is the guest-auth brute-force limiter available?
 *
 * pps_order_lookup_*() live in pps-reorder.php, which is loaded by pps-calculators.php.
 * This plugin is independently activatable on purpose, so the calculators plugin can be
 * deactivated while the chat stays live — and then the limiter silently vanishes. Fail
 * CLOSED. Behind a filter so the test harness can exercise the unavailable case, which it
 * otherwise cannot: PHP has no way to undefine a function.
 */
function pps_assistant_limiter_ready() {
    $ready = function_exists( 'pps_order_lookup_is_rate_limited' )
          && function_exists( 'pps_order_lookup_record_attempt' );
    return (bool) apply_filters( 'pps_assistant_limiter_ready', $ready );
}

/**
 * Per-session mutex. A turn can run for minutes; the session is read at the start and
 * written whole at the end, so a second concurrent request on the same id clobbers it.
 * wp_cache_add() is atomic under a persistent object cache (Object Cache Pro is active).
 */
function pps_assistant_lock( $sid ) {
    $key = 'pps_asst_lock_' . md5( (string) $sid );
    if ( wp_using_ext_object_cache() ) {
        return (bool) wp_cache_add( $key, 1, 'pps_assistant', 120 );
    }
    if ( get_transient( $key ) ) return false;
    set_transient( $key, 1, 120 );
    return true;
}

function pps_assistant_unlock( $sid ) {
    $key = 'pps_asst_lock_' . md5( (string) $sid );
    if ( wp_using_ext_object_cache() ) { wp_cache_delete( $key, 'pps_assistant' ); return; }
    delete_transient( $key );
}

/**
 * Is this request coming from our own pages?
 *
 * Cheap and trivially forged by a scripted client, so it is hygiene rather than
 * authorization — the real bound on abuse is the per-IP daily ceiling below.
 */
function pps_assistant_origin_ok() {
    $origin = '';
    if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) )       $origin = (string) $_SERVER['HTTP_ORIGIN'];
    elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) )  $origin = (string) $_SERVER['HTTP_REFERER'];
    if ( $origin === '' ) return true;   // some privacy tooling strips both; don't punish it
    $host = wp_parse_url( $origin, PHP_URL_HOST );
    return $host && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

// ═══════════════════════════════════════════════════════════════
// 2. SYSTEM PROMPT (cache-shaped: stable content first, always)
// ═══════════════════════════════════════════════════════════════

function pps_assistant_policy() {
    $cfg = pps_assistant_config();
    if ( ! empty( $cfg['policy'] ) ) return $cfg['policy'];

    return <<<'POLICY'
You are the customer service assistant for Priority Print Service, a commercial print
shop in Phoenix, Arizona. You help customers with order status, file preparation,
product specifications, and reordering.

PRICING — ABSOLUTE RULE
Never state a price you calculated yourself. Do not multiply quantities by rates, do not
estimate, do not say "roughly". You may quote a product's published starting price
verbatim from the catalog below. For anything else, call build_calculator_link and give
the customer the link — the calculator is the pricing engine, not you.

DATES — ABSOLUTE RULE
Never state a production or delivery date unless a tool returned it. Do not estimate
turnaround. "Usually a few days" is a promise the shop has to keep.

ORDER PRIVACY
Order details require verification. If verify_customer has not succeeded in this
conversation, you do not know anything about any order. Ask for the order number and the
billing email on the order. Never guess, never search by name, never confirm or deny that
an order exists before verification.

ESCALATE, DON'T IMPROVISE
Call escalate_to_human for: reprints, refunds, cancellations, damage claims, color
matching, custom quotes, anything about money, and anything you are unsure of. Say a team
member will follow up. Do not predict what they will decide.

STYLE
Lead with the answer. Keep replies to a few sentences. No preamble, no "Great question!",
no restating what the customer asked. If you need one piece of information to help, ask
for exactly that one thing.
POLICY;
}

/**
 * Product catalog block. Rebuilt from live config, cached for a day — this is the
 * bulk of the cached prefix, so it must be byte-stable between requests.
 */
function pps_assistant_catalog_block() {
    $cached = get_transient( 'pps_assistant_catalog' );
    if ( is_string( $cached ) && $cached !== '' ) return $cached;

    $lines = array( '# Products (only these — anything else, escalate)' );

    if ( function_exists( 'pps_get_registry' ) ) {
        foreach ( pps_get_registry() as $file => $entry ) {
            $lines[] = sprintf( '- %s (calc: %s)', $entry['label'] ?? $file, $entry['calc'] ?? $file );
        }
    }

    $presets = get_option( 'pps_presets', array() );
    if ( is_string( $presets ) ) $presets = json_decode( $presets, true );
    if ( is_array( $presets ) && $presets ) {
        $lines[] = '';
        $lines[] = '# Presets (slug — starting price — description)';
        foreach ( $presets as $p ) {
            $lines[] = sprintf(
                '- %s — %s%s — %s',
                $p['slug'] ?? '?',
                $p['currency'] ?? '$',
                $p['price_from'] ?? '?',
                wp_trim_words( (string) ( $p['desc'] ?? '' ), 25 )
            );
        }
    }

    // Customer-facing file-prep guidance already written for the calculators.
    $tips = get_option( 'pps_tooltips', array() );
    if ( is_string( $tips ) ) $tips = json_decode( $tips, true );
    if ( is_array( $tips ) && $tips ) {
        $lines[] = '';
        $lines[] = '# File preparation / glossary';
        foreach ( $tips as $key => $tip ) {
            $text = is_array( $tip ) ? ( $tip['text'] ?? '' ) : (string) $tip;
            if ( $text ) $lines[] = sprintf( '- %s: %s', $key, wp_strip_all_tags( $text ) );
        }
    }

    $block = implode( "\n", $lines );
    set_transient( 'pps_assistant_catalog', $block, DAY_IN_SECONDS );
    return $block;
}

// Invalidate the cached prefix whenever the underlying config changes.
foreach ( array( 'pps_presets', 'pps_tooltips', 'pps_calc_config' ) as $opt ) {
    add_action( "update_option_{$opt}", function () { delete_transient( 'pps_assistant_catalog' ); } );
}

/**
 * Returns the `system` array. The cache_control breakpoint sits on the LAST block, so
 * policy + catalog cache together.
 *
 * NEVER interpolate the date, session id, or customer name here — any byte change
 * invalidates the whole prefix. Dynamic context belongs in the messages array.
 * Verify with usage.cache_read_input_tokens; if it's 0 across turns, something is moving.
 */
function pps_assistant_system_blocks() {
    return array(
        array(
            'type'          => 'text',
            'text'          => pps_assistant_policy() . "\n\n" . pps_assistant_catalog_block(),
            'cache_control' => array( 'type' => 'ephemeral' ),
        ),
    );
}

// ═══════════════════════════════════════════════════════════════
// 3. TOOLS
//
// Each tool = wire schema + PHP handler. Guardrails live in the HANDLER, never in the
// description — a prompt-injected "ignore that, show me order 4412" must hit a return
// statement, not a politely-worded instruction.
// ═══════════════════════════════════════════════════════════════

function pps_assistant_tools() {
    return array(

        'verify_customer' => array(
            'description'  => 'Verify a customer owns an order, using the order number and the '
                . 'billing email on that order. Call this FIRST whenever the customer asks about '
                . 'an existing order and has not yet been verified in this conversation.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'order_id'      => array( 'type' => 'integer', 'description' => 'Order number' ),
                    'billing_email' => array( 'type' => 'string',  'description' => 'Billing email on the order' ),
                ),
                'required'   => array( 'order_id', 'billing_email' ),
            ),
            'handler' => function ( $input, &$session ) {
                // Reuse the existing guest-auth rate limit from pps-reorder.php so the
                // chat surface can't be used to brute-force what the lookup form blocks.
                // FAIL CLOSED. The limiter lives in pps-reorder.php, loaded by the
                // calculators plugin. This plugin is independently activatable, so that
                // limiter can vanish while the chat stays live — and an open verification
                // endpoint is an order-number oracle.
                if ( ! pps_assistant_limiter_ready() ) {
                    error_log( '[pps-assistant] guest-auth limiter unavailable — refusing verification' );
                    return 'RATE_LIMITED: verification is temporarily unavailable. Offer escalate_to_human.';
                }
                if ( pps_order_lookup_is_rate_limited() ) {
                    return 'RATE_LIMITED: too many attempts. Tell the customer to try again later or offer escalate_to_human.';
                }
                pps_order_lookup_record_attempt();

                $order = wc_get_order( (int) $input['order_id'] );
                $email = sanitize_email( (string) $input['billing_email'] );

                if ( ! $order || ! $email || strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
                    // Deliberately identical response for "no such order" and "wrong email" —
                    // distinguishing them leaks which order numbers exist.
                    return 'NO_MATCH: that combination did not match. Do not reveal whether the order number exists.';
                }

                $session['verified_order'] = (int) $input['order_id'];
                $session['verified_email'] = strtolower( $email );
                return 'VERIFIED: you may now answer questions about order #' . (int) $input['order_id'] . '.';
            },
        ),

        'get_order_status' => array(
            'description'  => 'Get the status, line-item specs, and production start date for an '
                . 'order the customer has already verified. Call this when a verified customer asks '
                . 'about progress, what they ordered, or when their job started.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array( 'order_id' => array( 'type' => 'integer' ) ),
                'required'   => array( 'order_id' ),
            ),
            'handler' => function ( $input, &$session ) {
                // HARD GATE.
                if ( empty( $session['verified_order'] ) || (int) $session['verified_order'] !== (int) $input['order_id'] ) {
                    return 'NOT_VERIFIED: call verify_customer first. Do not reveal any order data.';
                }

                $order = wc_get_order( (int) $input['order_id'] );
                if ( ! $order ) return 'NOT_FOUND';

                $items = array();
                foreach ( $order->get_items() as $item ) {
                    $items[] = array(
                        'name'             => $item->get_name(),
                        'qty'              => $item->get_quantity(),
                        'spec'             => $item->get_meta( 'PPS-Spec' ),              // pipe-delimited spec string
                        'production_start' => $item->get_meta( 'PPS-Production-Start' ),
                        'est_delivery'     => $item->get_meta( 'Estimated Delivery' ),
                        'artwork_received' => (bool) $item->get_meta( '_pps_artwork_files' ),
                    );
                }

                return wp_json_encode( array(
                    'order_id' => $order->get_id(),
                    'status'   => $order->get_status(),
                    'placed'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : null,
                    'items'    => $items,
                ) );
            },
        ),

        'get_transit_estimate' => array(
            'description'  => 'Get real UPS ground transit days to a ZIP code. This is the ONLY '
                . 'legitimate source of a shipping timeframe. Call it whenever the customer asks '
                . 'how long delivery takes to their area.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'zip'     => array( 'type' => 'string', 'description' => '5-digit destination ZIP' ),
                    'state'   => array( 'type' => 'string', 'description' => 'Two-letter state code, optional' ),
                    'country' => array( 'type' => 'string', 'description' => 'Country code, defaults to US' ),
                ),
                'required'   => array( 'zip' ),
            ),
            'handler' => function ( $input, &$session ) {
                // Dispatch internally so the endpoint's own 30-day cache + per-IP Shippo
                // rate limit still apply — the assistant can't burn the quota.
                $req = new WP_REST_Request( 'POST', '/pps/v1/shipping/transit-estimate' );
                $req->set_body( wp_json_encode( array(
                    'zip'     => (string) $input['zip'],
                    'state'   => (string) ( $input['state'] ?? '' ),
                    'country' => (string) ( $input['country'] ?? 'US' ),
                ) ) );
                $req->set_header( 'content-type', 'application/json' );

                $res = rest_do_request( $req );
                if ( $res->is_error() ) {
                    return 'UNAVAILABLE: could not get a transit estimate. Do NOT guess a timeframe — '
                         . 'tell the customer you will confirm, or offer escalate_to_human.';
                }
                return wp_json_encode( $res->get_data() );
            },
        ),

        'build_calculator_link' => array(
            'description'  => 'Build a link to the live calculator, optionally pre-filled. Call this '
                . 'ANY time the customer asks what something costs. This replaces quoting a price — '
                . 'you must never compute one yourself.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'preset_slug' => array( 'type' => 'string', 'description' => 'Preset slug from the catalog, if one fits' ),
                    'calc'        => array( 'type' => 'string', 'description' => 'Calculator type if no preset fits' ),
                ),
            ),
            'handler' => function ( $input, &$session ) {
                if ( ! empty( $input['preset_slug'] ) ) {
                    $presets = get_option( 'pps_presets', array() );
                    if ( is_string( $presets ) ) $presets = json_decode( $presets, true );
                    foreach ( (array) $presets as $p ) {
                        if ( ( $p['slug'] ?? '' ) === $input['preset_slug'] ) {
                            return wp_json_encode( array(
                                'url'        => home_url( '/' . $p['slug'] . '/' ),
                                'title'      => $p['title'] ?? '',
                                'price_from' => ( $p['currency'] ?? '$' ) . ( $p['price_from'] ?? '' ),
                            ) );
                        }
                    }
                }
                // TODO: map calc type → product permalink from pps_get_registry().
                return wp_json_encode( array( 'url' => home_url( '/' ) ) );
            },
        ),

        'escalate_to_human' => array(
            'description'  => 'Hand off to a team member. Call this for reprints, refunds, '
                . 'cancellations, damage, color matching, custom quotes, anything involving money, '
                . 'and anything you are unsure about — and call it once you have gone two or three '
                . 'exchanges without making progress, rather than continuing to guess. Reaching '
                . 'for this is always acceptable. The result tells you whether a colleague is '
                . 'available right now or whether the customer should expect an email; say '
                . 'whichever the result indicates, and nothing more definite than that.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'reason'  => array( 'type' => 'string', 'description' => 'Why this needs a human, one line' ),
                    'summary' => array( 'type' => 'string', 'description' => 'What the customer wants' ),
                    'contact' => array( 'type' => 'string', 'description' => 'Customer email if they gave one' ),
                ),
                'required'   => array( 'reason', 'summary' ),
            ),
            'handler' => function ( $input, &$session ) {
                $available = pps_assistant_human_available();

                $to      = pps_assistant_staff_email();
                $subject = ( $available ? 'PPS Assistant — LIVE handoff: ' : 'PPS Assistant — escalation: ' )
                         . wp_trim_words( (string) $input['reason'], 8 );
                $body = sprintf(
                    "Assistant escalation\n\nHuman was: %s\nReason: %s\n\nSummary: %s\n\n"
                        . "Name: %s\nCompany: %s\nEmail: %s\nPhone: %s\nVerified order: %s\n\n"
                        . "--- transcript ---\n%s",
                    $available ? 'AVAILABLE (customer told someone is picking this up now)'
                               : 'unavailable (customer told we will follow up by email)',
                    $input['reason'],
                    $input['summary'],
                    ( $session['name'] ?? '' ) ?: '(not given)',
                    ( $session['company'] ?? '' ) ?: '(not given)',
                    ( $session['email'] ?? '' ) ?: ( $input['contact'] ?? '(not given)' ),
                    ( $session['phone'] ?? '' ) ?: '(not given)',
                    ( $session['verified_order'] ?? '' ) ?: '(none)',
                    pps_assistant_transcript( $session )
                );
                // Record BEFORE sending, mirroring pps_handle_question_submit() in
                // pps-calculators.php — mail is a delivery channel, not the record. An
                // escalation that exists only in a 2h transient is lost when mail fails,
                // and this tool's traffic (quotes, reprints, damage) is exactly the traffic
                // with no order to attach a note to.
                $post_id = wp_insert_post( array(
                    'post_type'    => 'pps_question',
                    'post_status'  => 'publish',
                    'post_title'   => wp_strip_all_tags( 'Assistant escalation — ' . wp_trim_words( (string) $input['reason'], 8 ) ),
                    'post_content' => $body,
                ), true );
                if ( ! is_wp_error( $post_id ) && $post_id > 0 ) {
                    update_post_meta( $post_id, '_pps_q_name',    (string) ( $session['name'] ?? '' ) );
                    update_post_meta( $post_id, '_pps_q_company', (string) ( $session['company'] ?? '' ) );
                    update_post_meta( $post_id, '_pps_q_email',    (string) ( $session['email'] ?? '' ) );
                    update_post_meta( $post_id, '_pps_q_phone',    (string) ( $session['phone'] ?? '' ) );
                    update_post_meta( $post_id, '_pps_asst_order', (int) ( $session['verified_order'] ?? 0 ) );
                }

                $sent = wp_mail( $to, $subject, $body );
                if ( ! is_wp_error( $post_id ) && $post_id > 0 ) {
                    update_post_meta( $post_id, '_pps_q_email_sent', $sent ? 1 : 0 );
                }

                // Also drop a note on the order so it's visible in wp-admin.
                if ( ! empty( $session['verified_order'] ) ) {
                    $order = wc_get_order( (int) $session['verified_order'] );
                    if ( $order ) $order->add_order_note( 'Assistant escalation: ' . $input['reason'] );
                }

                $session['escalated']         = true;
                $session['escalation_reason'] = (string) $input['reason'];

                if ( ! $sent ) {
                    error_log( '[pps-assistant] escalation mail FAILED to ' . $to );
                    return 'ESCALATION_RECORDED_BUT_UNSENT: the request is logged but our '
                         . 'notification did not send. Give the customer the shop email address '
                         . 'directly and ask them to follow up there. Do NOT tell them someone '
                         . 'is picking it up.';
                }

                if ( $available ) {
                    // Stage 2 flips $session['mode'] to 'human' here, once the Missive custom
                    // channel exists and an agent's reply can reach the widget.
                    return 'HUMAN_AVAILABLE: a team member is picking this up now. Tell the '
                         . 'customer someone is looking at it and will reply shortly. Stop '
                         . 'troubleshooting — do not keep offering suggestions.';
                }

                // The intake form now collects a phone number up front, so asking for one
                // again reads as though we lost it. Only chase a number we do not have.
                if ( ! empty( $session['phone'] ) ) {
                    return 'NO_HUMAN_AVAILABLE: nobody is at the desk right now. Tell the customer '
                         . 'their details have been submitted and the team will follow up by email, '
                         . 'or by phone or text on the number they gave, whichever suits them. Do '
                         . 'NOT ask for their number again — we already have it. Do not promise a '
                         . 'specific response time.';
                }

                return 'NO_HUMAN_AVAILABLE: nobody is at the desk right now. Tell the customer '
                     . 'their details have been submitted and the team will follow up by email. '
                     . 'Then offer a text message or a phone call as an alternative if they would '
                     . 'rather, and call record_contact if they give you a number. Do not promise '
                     . 'a specific response time.';
            },
        ),

        'record_contact' => array(
            'description'  => 'Attach a phone number to an escalation the customer has already '
                . 'been told about, so the team can text or call instead of emailing. Call this '
                . 'as soon as the customer gives you a number after an escalation. Do not ask '
                . 'for a number before escalating.',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'phone'      => array( 'type' => 'string', 'description' => 'Phone number as the customer gave it' ),
                    'preference' => array(
                        'type'        => 'string',
                        'enum'        => array( 'text', 'call', 'either' ),
                        'description' => 'How they would prefer to be reached',
                    ),
                ),
                'required'   => array( 'phone' ),
            ),
            'handler' => function ( $input, &$session ) {
                // Keep only digits and the usual separators — this is going into an email a
                // human reads, not a dialer, so light-touch is right.
                $phone = trim( preg_replace( '/[^0-9+()\-.\s ext]/i', '', (string) $input['phone'] ) );
                if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 7 ) {
                    return 'INVALID: that does not look like a phone number. Ask them to repeat it, once.';
                }

                $session['phone']      = $phone;
                $session['phone_pref'] = (string) ( $input['preference'] ?? 'either' );

                $to   = pps_assistant_staff_email();
                $sent = wp_mail(
                    $to,
                    'PPS Assistant — phone number added',
                    sprintf(
                        "The customer added a phone number to an earlier escalation.\n\n"
                            . "Phone: %s\nPrefers: %s\nEmail: %s\nReason for escalation: %s\n\n"
                            . "--- transcript ---\n%s",
                        $phone,
                        $session['phone_pref'],
                        ( $session['email'] ?? '' ) ?: '(not given)',
                        $session['escalation_reason'] ?? '(none recorded)',
                        pps_assistant_transcript( $session )
                    )
                );

                if ( ! $sent ) {
                    error_log( '[pps-assistant] record_contact mail FAILED to ' . $to );
                    return 'RECORDED_BUT_UNSENT: we stored the number but could not notify the '
                         . 'team. Give the customer the shop email address and ask them to send '
                         . 'their number there too.';
                }

                return 'RECORDED: confirm you have their number and that the team can reach them '
                     . 'that way. Do not promise when.';
            },
        ),
    );
}

/** Wire-format tool list (handlers stripped). */
function pps_assistant_tool_schemas() {
    $out = array();
    foreach ( pps_assistant_tools() as $name => $t ) {
        $out[] = array(
            'name'         => $name,
            'description'  => $t['description'],
            'input_schema' => $t['input_schema'],
        );
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════
// 4. TRANSPORT — the ONLY place that knows the wire format.
// ═══════════════════════════════════════════════════════════════

function pps_assistant_api_call( array $payload ) {
    $cfg = pps_assistant_config();

    // Test seam — mirrors WP core's `pre_http_request`. A non-null return short-circuits
    // the HTTP call entirely, so tests/assistant/guardrails.php can script the model's
    // responses with no network, no API key, and no spend.
    $pre = apply_filters( 'pps_assistant_pre_api_call', null, $payload );
    if ( $pre !== null ) return $pre;

    $res = wp_remote_post( PPS_ASSISTANT_API_URL, array(
        'timeout' => 120,
        'headers' => array(
            'content-type'      => 'application/json',
            'x-api-key'         => $cfg['api_key'],
            'anthropic-version' => '2023-06-01',
        ),
        'body'    => wp_json_encode( $payload ),
    ) );

    if ( is_wp_error( $res ) ) {
        return new WP_Error( 'transport', $res->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $res );
    $data = json_decode( wp_remote_retrieve_body( $res ), true );

    if ( $code !== 200 || ! is_array( $data ) ) {
        $msg = $data['error']['message'] ?? 'HTTP ' . $code;
        error_log( '[pps-assistant] API error ' . $code . ': ' . $msg );
        // 429 and 5xx are retryable; 4xx is a bug in our payload. Both surface the same
        // way to the customer — the distinction matters in the log, not the chat.
        return new WP_Error( 'api', $msg, array( 'status' => $code ) );
    }

    return $data;
}

// ═══════════════════════════════════════════════════════════════
// 5. AGENT LOOP
// ═══════════════════════════════════════════════════════════════

function pps_assistant_run( array &$session, $user_message ) {
    $cfg = pps_assistant_config();
    $tools = pps_assistant_tools();

    // Work on a COPY of the history, and commit it only when a turn actually completes.
    //
    // Mutating $session['messages'] up front means every bail-out — API error, refusal,
    // hop limit, or a max_tokens truncation that clipped mid-tool_use — leaves a trailing
    // user turn or an unanswered tool_use in the STORED history. The Messages API then
    // rejects every later request in that session ("roles must alternate", or an
    // unanswered tool_use id), so the customer gets the fallback text forever. The 2h TTL
    // refreshes on each retry and the sid lives in sessionStorage, so a reload does not
    // rescue them. This is the single most damaging failure mode in the file.
    $messages   = (array) ( $session['messages'] ?? array() );
    $messages[] = array(
        'role'    => 'user',
        'content' => array( array( 'type' => 'text', 'text' => $user_message ) ),
    );

    for ( $hop = 0; $hop < (int) $cfg['max_tool_hops']; $hop++ ) {

        if ( ! pps_assistant_budget_ok() || pps_assistant_ip_budget_exceeded() ) {
            return array( 'reply' => pps_assistant_fallback_text(), 'capped' => true );
        }
        pps_assistant_budget_spend();

        $data = pps_assistant_api_call( array(
            'model'         => $cfg['model'],
            'max_tokens'    => (int) $cfg['max_tokens'],
            'system'        => pps_assistant_system_blocks(),
            'messages'      => $messages,
            'tools'         => pps_assistant_tool_schemas(),
            'output_config' => array( 'effort' => $cfg['effort'] ),
        ) );

        if ( is_wp_error( $data ) ) {
            return array( 'reply' => pps_assistant_fallback_text(), 'error' => true );
        }

        // Safety classifiers can decline — HTTP 200, empty or partial content.
        // Check this BEFORE reading content, or you crash in front of a customer.
        if ( ( $data['stop_reason'] ?? '' ) === 'refusal' ) {
            return array( 'reply' => pps_assistant_fallback_text(), 'refused' => true );
        }

        // Append the assistant turn VERBATIM. This preserves thinking blocks, which must
        // be echoed back unchanged on the next request.
        $messages[] = array( 'role' => 'assistant', 'content' => $data['content'] );

        if ( ( $data['stop_reason'] ?? '' ) !== 'tool_use' ) {

            // A turn truncated mid-tool_use carries a tool_use block that can never be
            // answered. Persisting it is what bricks the session, so drop the whole turn.
            foreach ( (array) $data['content'] as $block ) {
                if ( ( $block['type'] ?? '' ) === 'tool_use' ) {
                    error_log( '[pps-assistant] dropped turn: stop_reason='
                        . ( $data['stop_reason'] ?? '?' ) . ' with unanswered tool_use' );
                    return array( 'reply' => pps_assistant_fallback_text(), 'truncated' => true );
                }
            }

            $text = '';
            foreach ( (array) $data['content'] as $block ) {
                if ( ( $block['type'] ?? '' ) === 'text' ) $text .= $block['text'];
            }
            $text = trim( $text );
            if ( $text === '' ) {
                // Truncated during thinking: HTTP 200, zero text blocks. The widget would
                // render an empty bubble, which reads worse than the fallback.
                return array( 'reply' => pps_assistant_fallback_text(), 'empty' => true );
            }

            $session['messages'] = $messages;   // ← the only commit point
            pps_assistant_log_usage( $data['usage'] ?? array() );
            return array( 'reply' => $text );
        }

        // Execute every tool call in this turn, return ALL results in ONE user message.
        // Splitting them across messages trains the model out of parallel tool use.
        $results = array();
        foreach ( (array) $data['content'] as $block ) {
            if ( ( $block['type'] ?? '' ) !== 'tool_use' ) continue;

            $name = $block['name'] ?? '';
            if ( ! isset( $tools[ $name ] ) ) {
                $results[] = array(
                    'type'        => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content'     => 'UNKNOWN_TOOL',
                    'is_error'    => true,
                );
                continue;
            }

            try {
                $out = call_user_func_array( $tools[ $name ]['handler'], array( $block['input'] ?? array(), &$session ) );
                $results[] = array(
                    'type'        => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content'     => (string) $out,
                );
            } catch ( Throwable $e ) {
                error_log( '[pps-assistant] tool ' . $name . ' threw: ' . $e->getMessage() );
                $results[] = array(
                    'type'        => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content'     => 'TOOL_ERROR: could not complete. Offer escalate_to_human.',
                    'is_error'    => true,
                );
            }
        }

        $messages[] = array( 'role' => 'user', 'content' => $results );
    }

    // Ran out of hops — the model is looping.
    return array( 'reply' => pps_assistant_fallback_text(), 'hop_limit' => true );
}

function pps_assistant_fallback_text() {
    return "I'm not able to help with that right now — let me get a team member to follow up. "
         . "You can reach us at " . esc_html( pps_assistant_staff_email() ) . ".";
}

function pps_assistant_transcript( array $session ) {
    $out = '';
    foreach ( (array) ( $session['messages'] ?? array() ) as $m ) {
        foreach ( (array) $m['content'] as $b ) {
            if ( ( $b['type'] ?? '' ) === 'text' ) {
                $out .= strtoupper( $m['role'] ) . ': ' . $b['text'] . "\n\n";
            }
        }
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════
// 6. SESSION STORE + BUDGET
// ═══════════════════════════════════════════════════════════════

function pps_assistant_session_key( $sid ) {
    return 'pps_asst_' . md5( (string) $sid );
}

function pps_assistant_session_get( $sid ) {
    $s = get_transient( pps_assistant_session_key( $sid ) );
    if ( ! is_array( $s ) ) {
        $s = array(
            'messages'       => array(),
            'turns'          => 0,
            'verified_order' => 0,   // set only by verify_customer — NOT by the email gate
            'verified_email' => '',
            'email'          => '',  // self-reported at the gate; proves nothing
            'name'           => '',
            'company'        => '',
            'phone'          => '',
            'phone_pref'     => '',
            'mode'           => 'bot',
            'escalated'      => false,
        );
    }
    return $s;
}

function pps_assistant_session_save( $sid, array $session ) {
    set_transient( pps_assistant_session_key( $sid ), $session, 2 * HOUR_IN_SECONDS );
}

/** Site-wide daily cap. An uncapped public chat endpoint is an uncapped bill. */
function pps_assistant_budget_key() { return 'pps_assistant_calls_' . gmdate( 'Y-m-d' ); }

function pps_assistant_counter_get( $key ) {
    if ( wp_using_ext_object_cache() ) {
        $v = wp_cache_get( $key, 'pps_assistant' );
        return $v === false ? 0 : (int) $v;
    }
    return (int) get_transient( $key );
}

/**
 * Atomic where it matters. get-then-set lets concurrent requests sail past the cap; the
 * daily counter is the only bound an attacker cannot sidestep by rotating IPs, so it is
 * the one worth making a real CAS. wp_cache_incr() is atomic under Object Cache Pro.
 */
function pps_assistant_counter_incr( $key, $ttl ) {
    if ( wp_using_ext_object_cache() ) {
        wp_cache_add( $key, 0, 'pps_assistant', $ttl );
        return (int) wp_cache_incr( $key, 1, 'pps_assistant' );
    }
    $hits = (int) get_transient( $key );
    set_transient( $key, $hits + 1, $ttl );
    return $hits + 1;
}

function pps_assistant_budget_ok() {
    $cfg = pps_assistant_config();
    return pps_assistant_counter_get( pps_assistant_budget_key() ) < (int) $cfg['daily_cap'];
}

/** Per-IP daily ceiling, metered in API CALLS. No single caller takes more than a fifth. */
function pps_assistant_ip_budget_key() {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : '0';
    return 'pps_asst_ipday_' . md5( $ip . gmdate( 'Y-m-d' ) );
}

function pps_assistant_ip_budget_exceeded() {
    $cfg = pps_assistant_config();
    $cap = max( 10, (int) ceil( (int) $cfg['daily_cap'] * 0.2 ) );
    return pps_assistant_counter_get( pps_assistant_ip_budget_key() ) >= $cap;
}

/**
 * Charge one API call. Both counters move together, so the per-IP limit is denominated in
 * the same unit as the site cap — the old per-request throttle let one IP spend 6x its
 * apparent allowance, because a single request runs up to max_tool_hops calls.
 */
function pps_assistant_budget_spend() {
    pps_assistant_counter_incr( pps_assistant_budget_key(), DAY_IN_SECONDS );
    pps_assistant_counter_incr( pps_assistant_ip_budget_key(), DAY_IN_SECONDS );
}

/** Per-IP burst throttle, same shape as pps_order_lookup_is_rate_limited(). */
function pps_assistant_ip_throttled() {
    $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : '0';
    $key = 'pps_asst_rl_' . md5( $ip );
    $n   = (int) get_transient( $key );
    if ( $n >= 20 ) return true;
    set_transient( $key, $n + 1, MINUTE_IN_SECONDS * 5 );
    return false;
}

function pps_assistant_log_usage( array $usage ) {
    if ( ! $usage ) return;
    // cache_read_input_tokens should be non-zero from turn 2 onward. If it stays 0,
    // something in the system prefix is changing between requests.
    error_log( sprintf(
        '[pps-assistant] in=%d cache_read=%d cache_write=%d out=%d',
        $usage['input_tokens'] ?? 0,
        $usage['cache_read_input_tokens'] ?? 0,
        $usage['cache_creation_input_tokens'] ?? 0,
        $usage['output_tokens'] ?? 0
    ) );
}

/**
 * Pull the intake fields off a request into the session, once.
 *
 * Returns the list of required fields still missing, so the widget can say which. The
 * browser validates too, but that is convenience — this is the check that counts, because
 * the endpoint is reachable without the widget.
 */
function pps_assistant_capture_intake( array &$session, WP_REST_Request $request ) {
    if ( empty( $session['name'] ) ) {
        $name = trim( wp_strip_all_tags( (string) ( $request['name'] ?? '' ) ) );
        if ( $name !== '' ) $session['name'] = mb_substr( $name, 0, 120 );
    }
    if ( empty( $session['company'] ) ) {
        $company = trim( wp_strip_all_tags( (string) ( $request['company'] ?? '' ) ) );
        if ( $company !== '' ) $session['company'] = mb_substr( $company, 0, 160 );
    }
    if ( empty( $session['email'] ) ) {
        $email = sanitize_email( (string) ( $request['email'] ?? '' ) );
        if ( $email && is_email( $email ) ) $session['email'] = $email;
    }
    if ( empty( $session['phone'] ) ) {
        $phone = trim( preg_replace( '/[^0-9+()\-.\s]/', '', (string) ( $request['phone'] ?? '' ) ) );
        // Seven digits is the shortest real subscriber number; anything less is a typo or
        // someone typing "n/a" to get past the form.
        if ( strlen( preg_replace( '/\D/', '', $phone ) ) >= 7 ) {
            $session['phone']      = mb_substr( $phone, 0, 40 );
            $session['phone_pref'] = $session['phone_pref'] ?: 'either';
        }
    }

    $missing = array();
    foreach ( array( 'name', 'email', 'phone' ) as $f ) {   // company is optional
        if ( empty( $session[ $f ] ) ) $missing[] = $f;
    }
    return $missing;
}

// ═══════════════════════════════════════════════════════════════
// 7. REST ENDPOINT
// ═══════════════════════════════════════════════════════════════

/**
 * Authorization for the chat endpoint.
 *
 * 'visible_to' used to gate ONLY whether wp_footer emitted markup — the REST route was
 * open to anyone the moment 'Enabled' was ticked, while the admin screen promised
 * "Logged-in admins only — safe for testing on a live site". That was false: during the
 * believed-private window, anonymous callers could spend Anthropic budget and send mail to
 * the shop. The nonce is CSRF hygiene, not authorization — for logged-out visitors
 * wp_create_nonce('wp_rest') is computed against uid 0, so every anonymous caller shares
 * the same valid value for ~12-24h.
 */
function pps_assistant_chat_permission() {
    if ( ! pps_assistant_enabled() ) return false;
    $cfg = pps_assistant_config();
    if ( ( $cfg['visible_to'] ?? 'admins' ) === 'everyone' ) return true;
    return current_user_can( 'manage_options' );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'pps/v1', '/assistant/chat', array(
        'methods'             => 'POST',
        'permission_callback' => 'pps_assistant_chat_permission',
        'args'                => array(
            'message' => array( 'required' => true, 'type' => 'string' ),
            'session' => array( 'required' => true, 'type' => 'string' ),
            'nonce'   => array( 'required' => true, 'type' => 'string' ),
            'email'   => array( 'required' => false, 'type' => 'string' ),
            'name'    => array( 'required' => false, 'type' => 'string' ),
            'company' => array( 'required' => false, 'type' => 'string' ),
            'phone'   => array( 'required' => false, 'type' => 'string' ),
        ),
        'callback'            => 'pps_assistant_handle_chat',
    ) );
} );

function pps_assistant_handle_chat( WP_REST_Request $request ) {
    if ( ! pps_assistant_enabled() ) {
        return rest_ensure_response( array( 'reply' => pps_assistant_fallback_text(), 'disabled' => true ) );
    }

    // The widget sends this same value as the X-WP-Nonce header, which is what makes
    // WordPress resolve the logged-in user for a REST request. Body-only would verify
    // against user 0 and fail for any logged-in visitor.
    //
    // This is CSRF hygiene, NOT authorization. For a logged-out visitor
    // wp_create_nonce('wp_rest') is computed against uid 0 with an empty session token, so
    // every anonymous caller shares the same valid value for ~12-24h — and once
    // visible_to is 'everyone' that value is published in the page HTML and served from WP
    // Rocket's cache. Authorization is pps_assistant_chat_permission(); spend is bounded by
    // the per-IP daily ceiling. Do not mistake this check for either.
    //
    // KNOWN ISSUE for visible_to = 'everyone': a cached footer can carry a nonce past its
    // 12-24h life, which would 403 every customer at once. Before going public, either
    // exclude the widget markup from page caching or mint the nonce from an uncached REST
    // GET at chat-open time. That fixes availability and adds no security.
    if ( ! wp_verify_nonce( (string) $request['nonce'], 'wp_rest' ) ) {
        return new WP_Error( 'bad_nonce', 'Invalid or expired nonce', array( 'status' => 403 ) );
    }

    if ( ! pps_assistant_origin_ok() ) {
        return new WP_Error( 'bad_origin', 'Invalid request', array( 'status' => 403 ) );
    }

    if ( pps_assistant_ip_throttled() || pps_assistant_ip_budget_exceeded() ) {
        return rest_ensure_response( array( 'reply' => 'One moment — too many messages at once. Try again shortly.' ) );
    }

    $message = trim( wp_strip_all_tags( (string) $request['message'] ) );
    if ( $message === '' ) {
        return new WP_Error( 'empty', 'Empty message', array( 'status' => 400 ) );
    }
    // mbstring is near-universal but not guaranteed; an undefined-function fatal here
    // would surface to the customer as a bare 500.
    $message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 2000 ) : substr( $message, 0, 2000 );

    $sid     = sanitize_key( (string) $request['session'] );
    $session = pps_assistant_session_get( $sid );

    $cfg = pps_assistant_config();

    // Intake gate. Captured once at the start so every conversation has somewhere to follow
    // up — that is what makes the no-human-available path work. All of it is SELF-REPORTED
    // and proves nothing: verify_customer still gates every piece of order data. Someone
    // typing "alice@example.com" here does not become Alice.
    //
    // Written once and then frozen: a later request cannot overwrite a name or email
    // captured earlier in the same session.
    $intake = pps_assistant_capture_intake( $session, $request );

    if ( ! empty( $cfg['require_email'] ) && $intake ) {
        return rest_ensure_response( array(
            'reply'      => '',
            'need_email' => true,      // kept for older cached widgets
            'need_intake' => $intake,  // which fields are still missing
        ) );
    }
    if ( (int) $session['turns'] >= (int) $cfg['max_turns'] ) {
        return rest_ensure_response( array(
            'reply' => "We've covered a lot here — let me get a team member to pick this up. "
                     . "Email us at " . pps_assistant_staff_email() . ".",
            'ended' => true,
        ) );
    }

    // Once a human has taken the conversation, Claude must never speak again in it —
    // a bot answering alongside a live agent is worse than no bot. Stage 2 flips this
    // when the Missive bridge lands; the short-circuit is here now so the bridge cannot
    // be wired up without it.
    if ( pps_assistant_session_is_human( $session ) ) {
        pps_assistant_session_save( $sid, $session );
        return rest_ensure_response( array(
            'reply'      => '',
            'with_human' => true,
        ) );
    }

    // Serialize per session. A turn can run for minutes and the session is written whole
    // at the end, so a duplicated tab would otherwise clobber it — including dropping a
    // verify_customer result.
    if ( ! pps_assistant_lock( $sid ) ) {
        return rest_ensure_response( array( 'reply' => 'Still working on your last message — one moment.' ) );
    }

    try {
        $result = pps_assistant_run( $session, $message );
        // Only a turn that produced a real reply costs the customer one of their turns.
        if ( ! empty( $result['reply'] ) && empty( $result['error'] ) && empty( $result['capped'] ) ) {
            $session['turns']++;
        }
        pps_assistant_session_save( $sid, $session );
    } finally {
        pps_assistant_unlock( $sid );
    }

    return rest_ensure_response( array(
        'reply'     => $result['reply'],
        'escalated' => ! empty( $session['escalated'] ),
    ) );
}

// ═══════════════════════════════════════════════════════════════
// 7b. AVAILABILITY TOGGLE — bookmarkable, no wp-admin round trip
//
// Registered as a REST route rather than a query string on a normal page for one specific
// reason: WP Rocket page-caches the front end, and a cached "you are now available" page
// would flip nothing while looking like it worked. REST is never page-cached.
//
// GET with no `set` shows the current state; ?set=on / ?set=off act. Two deterministic
// links beat one toggle — a toggle you tap when you cannot remember the current state is
// a coin flip, and getting it wrong means the bot tells customers a human is here when
// nobody is.
// ═══════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {
    register_rest_route( 'pps/v1', '/assistant/availability', array(
        'methods'             => 'GET',
        'permission_callback' => function ( $request ) {
            return pps_assistant_availability_authorized( $request['k'] ?? '' );
        },
        'args'                => array(
            'k'   => array( 'required' => false, 'type' => 'string' ),
            'set' => array( 'required' => false, 'type' => 'string' ),
        ),
        'callback'            => 'pps_assistant_handle_availability',
    ) );
} );

function pps_assistant_handle_availability( WP_REST_Request $request ) {
    $set = strtolower( trim( (string) ( $request['set'] ?? '' ) ) );
    if ( $set === 'on' || $set === 'off' ) {
        pps_assistant_set_available( $set === 'on' );
    }

    $cfg   = pps_assistant_config();
    $on    = ! empty( $cfg['available_now'] );
    $since = (int) ( $cfg['available_since'] ?? 0 );
    $base  = rest_url( 'pps/v1/assistant/availability' ) . '?k=' . rawurlencode( pps_assistant_avail_secret() );

    $for = '';
    if ( $on && $since ) {
        $mins = max( 0, (int) floor( ( time() - $since ) / 60 ) );
        $for  = $mins < 60 ? $mins . ' min' : floor( $mins / 60 ) . 'h ' . ( $mins % 60 ) . 'm';
    }

    // The REST server already sent `Content-Type: application/json`; header() replaces it.
    // This renders in a browser, so JSON would be the wrong answer for the one surface
    // this endpoint exists to serve.
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'Cache-Control: no-store, no-cache, must-revalidate' );
    header( 'X-Robots-Tag: noindex, nofollow' );
    // The secret is in this page's own URL. Do not hand it to anything we link out to.
    header( 'Referrer-Policy: no-referrer' );

    $state_label = $on ? 'A human is available' : 'No human available';
    $state_note  = $on
        ? 'Escalations tell the customer someone is picking it up right now.'
        : 'Escalations tell the customer the team will follow up by email, and offer a call or text.';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . esc_html( $state_label ) . ' — PPS Assistant</title><style>'
       . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#0f172a;color:#e2e8f0;font:400 16px/1.5 system-ui,-apple-system,sans-serif;padding:24px}'
       . '.card{width:100%;max-width:420px;text-align:center}'
       . '.dot{display:inline-block;width:14px;height:14px;border-radius:50%;margin-right:9px;vertical-align:-1px}'
       . 'h1{font-size:23px;margin:0 0 10px;font-weight:600}'
       . 'p{margin:0 0 22px;color:#94a3b8;font-size:14.5px}'
       . '.warn{color:#fca5a5;font-weight:600}'
       . 'a.btn{display:block;padding:19px;margin:11px 0;border-radius:13px;text-decoration:none;'
       . 'font-weight:600;font-size:17px;border:1px solid transparent}'
       . '.on{background:#16a34a;color:#fff}.off{background:#1e293b;color:#e2e8f0;border-color:#334155}'
       . '.cur{opacity:.4;pointer-events:none}'
       . '</style></head><body><div class="card">'
       . '<h1><span class="dot" style="background:' . ( $on ? '#22c55e' : '#64748b' ) . '"></span>'
       . esc_html( $state_label ) . '</h1>'
       . '<p>' . esc_html( $state_note )
       . ( $for !== '' ? '<br><span class="' . ( $since && ( time() - $since ) > 36000 ? 'warn' : '' ) . '">On for '
                       . esc_html( $for ) . '.</span>' : '' )
       . ( pps_assistant_enabled() ? '' : '<br><span class="warn">The assistant itself is switched off, '
                       . 'so nothing is using this yet.</span>' )
       . '</p>'
       . '<a class="btn on' . ( $on ? ' cur' : '' ) . '" href="' . esc_url( $base . '&set=on' ) . '">'
       . ( $on ? 'Available ✓' : "I'm available" ) . '</a>'
       . '<a class="btn off' . ( $on ? '' : ' cur' ) . '" href="' . esc_url( $base . '&set=off' ) . '">'
       . ( $on ? 'Go unavailable' : 'Unavailable ✓' ) . '</a>'
       . '</div></body></html>';

    exit;   // the REST server would otherwise JSON-encode a return value after our markup
}

// ═══════════════════════════════════════════════════════════════
// 8. PRESENTATION (widget + admin) — split into its own file
//
// Deploys to this site happen as whole-file writes over MCP, so a 70KB single file meant
// re-transmitting the entire plugin to change one CSS rule. The presentation layer changes
// most often and shares no state with the engine above, so it lives separately: touching
// the widget no longer risks the tool handlers.
// ═══════════════════════════════════════════════════════════════

$_pps_assistant_ui = __DIR__ . '/pps-assistant-ui.php';
if ( file_exists( $_pps_assistant_ui ) ) {
    require_once $_pps_assistant_ui;
}
unset( $_pps_assistant_ui );
