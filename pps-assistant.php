<?php
/**
 * Plugin Name: PPS Assistant
 * Description: Claude-powered on-site customer service chat. Grounded on real order
 *              data via tools; never prices, never promises dates. Template/scaffold —
 *              see docs/CUSTOMER_SERVICE_BOT.md for the design rationale.
 * Version: 0.1.1
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
 * Deployed to wp-content/plugins/pps-calculators/pps-assistant.php — its own activatable
 * plugin, exactly like pps-html-deploy.php and pps-cart-price-floor.php. Deactivating it
 * is a second kill switch independent of the config flag.
 *
 * ── SETUP ──
 * 1. Activate the plugin (it does nothing at all until you do).
 * 2. PPS Calculators → Assistant: paste the Anthropic API key, leave "Visible to" on
 *    "Logged-in admins only", tick Enabled, Save.
 * 3. Load any front-end page while logged in as an admin. Customers see nothing until
 *    "Visible to" is changed to Everyone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( defined( 'PPS_ASSISTANT_VERSION' ) ) return;   // co-load guard
define( 'PPS_ASSISTANT_VERSION', '0.1.1' );
define( 'PPS_ASSISTANT_API_URL', 'https://api.anthropic.com/v1/messages' );

// ═══════════════════════════════════════════════════════════════
// 1. CONFIG + KILL SWITCH
// ═══════════════════════════════════════════════════════════════

function pps_assistant_config() {
    $defaults = array(
        'enabled'       => false,          // ← ships OFF. This is the kill switch.
        'visible_to'    => 'admins',       // 'admins' | 'everyone' — who sees the widget
        'api_key'       => '',
        'model'         => 'claude-opus-5',
        'effort'        => 'medium',       // low | medium | high | xhigh | max
        'max_tokens'    => 4096,           // thinking is ON by default on Opus 5 — leave headroom
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
 * Same spirit as pps-emergency-deactivate.php.
 */
function pps_assistant_enabled() {
    $cfg = pps_assistant_config();
    return ! empty( $cfg['enabled'] ) && ! empty( $cfg['api_key'] );
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
//
// Descriptions state WHEN to call, not just what the tool does. Opus-tier models reach
// for tools conservatively; trigger conditions measurably improve the call rate.
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
                if ( function_exists( 'pps_order_lookup_is_rate_limited' ) && pps_order_lookup_is_rate_limited() ) {
                    return 'RATE_LIMITED: too many attempts. Tell the customer to try again later or offer escalate_to_human.';
                }
                if ( function_exists( 'pps_order_lookup_record_attempt' ) ) {
                    pps_order_lookup_record_attempt();
                }

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
                . 'and anything you are unsure about. Reaching for this is always acceptable.',
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
                $to = get_option( 'pps_question_recipient', get_option( 'admin_email' ) );
                $body = sprintf(
                    "Assistant escalation\n\nReason: %s\n\nSummary: %s\n\nContact: %s\nVerified order: %s\n\n--- transcript ---\n%s",
                    $input['reason'],
                    $input['summary'],
                    $input['contact'] ?? '(not given)',
                    $session['verified_order'] ?? '(none)',
                    pps_assistant_transcript( $session )
                );
                wp_mail( $to, 'PPS Assistant — escalation', $body );

                // Also drop a note on the order so it's visible in wp-admin.
                if ( ! empty( $session['verified_order'] ) ) {
                    $order = wc_get_order( (int) $session['verified_order'] );
                    if ( $order ) $order->add_order_note( 'Assistant escalation: ' . $input['reason'] );
                }

                $session['escalated'] = true;
                return 'ESCALATED: tell the customer a team member will follow up, and stop.';
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
//    Swap this one function to move to anthropic-ai/sdk.
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

    $session['messages'][] = array(
        'role'    => 'user',
        'content' => array( array( 'type' => 'text', 'text' => $user_message ) ),
    );

    for ( $hop = 0; $hop < (int) $cfg['max_tool_hops']; $hop++ ) {

        if ( ! pps_assistant_budget_ok() ) {
            return array( 'reply' => pps_assistant_fallback_text(), 'capped' => true );
        }
        pps_assistant_budget_spend();

        $data = pps_assistant_api_call( array(
            'model'         => $cfg['model'],
            'max_tokens'    => (int) $cfg['max_tokens'],
            'system'        => pps_assistant_system_blocks(),
            'messages'      => $session['messages'],
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
        $session['messages'][] = array( 'role' => 'assistant', 'content' => $data['content'] );

        if ( ( $data['stop_reason'] ?? '' ) !== 'tool_use' ) {
            $text = '';
            foreach ( (array) $data['content'] as $block ) {
                if ( ( $block['type'] ?? '' ) === 'text' ) $text .= $block['text'];
            }
            pps_assistant_log_usage( $data['usage'] ?? array() );
            return array( 'reply' => trim( $text ) );
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

        $session['messages'][] = array( 'role' => 'user', 'content' => $results );
    }

    // Ran out of hops — the model is looping.
    return array( 'reply' => pps_assistant_fallback_text(), 'hop_limit' => true );
}

function pps_assistant_fallback_text() {
    return "I'm not able to help with that right now — let me get a team member to follow up. "
         . "You can reach us at " . esc_html( get_option( 'pps_question_recipient', get_option( 'admin_email' ) ) ) . ".";
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
        $s = array( 'messages' => array(), 'turns' => 0, 'verified_order' => 0, 'verified_email' => '' );
    }
    return $s;
}

function pps_assistant_session_save( $sid, array $session ) {
    set_transient( pps_assistant_session_key( $sid ), $session, 2 * HOUR_IN_SECONDS );
}

/** Site-wide daily cap. An uncapped public chat endpoint is an uncapped bill. */
function pps_assistant_budget_ok() {
    $cfg = pps_assistant_config();
    $key = 'pps_assistant_calls_' . gmdate( 'Y-m-d' );
    return (int) get_transient( $key ) < (int) $cfg['daily_cap'];
}

function pps_assistant_budget_spend() {
    $key  = 'pps_assistant_calls_' . gmdate( 'Y-m-d' );
    $hits = (int) get_transient( $key );
    set_transient( $key, $hits + 1, DAY_IN_SECONDS );
}

/** Per-IP throttle, same shape as pps_order_lookup_is_rate_limited(). */
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

// ═══════════════════════════════════════════════════════════════
// 7. REST ENDPOINT
// ═══════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {
    register_rest_route( 'pps/v1', '/assistant/chat', array(
        'methods'             => 'POST',
        'permission_callback' => '__return_true', // public widget; guarded by nonce + throttle below
        'args'                => array(
            'message' => array( 'required' => true, 'type' => 'string' ),
            'session' => array( 'required' => true, 'type' => 'string' ),
            'nonce'   => array( 'required' => true, 'type' => 'string' ),
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
    // KNOWN ISSUE for visible_to = 'everyone': WP Rocket may serve a cached footer whose
    // nonce has expired (nonces live ~12-24h), which would 403 every customer at once.
    // Before going public, either exclude the widget markup from page caching or fetch
    // the nonce from an uncached endpoint at open-chat time.
    if ( ! wp_verify_nonce( (string) $request['nonce'], 'wp_rest' ) ) {
        return new WP_Error( 'bad_nonce', 'Invalid or expired nonce', array( 'status' => 403 ) );
    }

    if ( pps_assistant_ip_throttled() ) {
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
    if ( (int) $session['turns'] >= (int) $cfg['max_turns'] ) {
        return rest_ensure_response( array(
            'reply' => "We've covered a lot here — let me get a team member to pick this up. "
                     . "Email us at " . get_option( 'pps_question_recipient', get_option( 'admin_email' ) ) . ".",
            'ended' => true,
        ) );
    }

    $session['turns']++;
    $result = pps_assistant_run( $session, $message );
    pps_assistant_session_save( $sid, $session );

    return rest_ensure_response( array(
        'reply'     => $result['reply'],
        'escalated' => ! empty( $session['escalated'] ),
    ) );
}

// ═══════════════════════════════════════════════════════════════
// 8. WIDGET (front-end)
//
// Vanilla JS, no React — this loads on every page, unlike the calculators.
// The visual source of truth is assistant-widget-preview.html on the Pages branch;
// keep the two in sync the same way the CC/ST blocks are synced across calculators.
// ═══════════════════════════════════════════════════════════════

/**
 * Should the widget render on this request?
 *
 * Two independent gates, both deliberately conservative:
 *
 *  1. NEVER on cart or checkout. A JS error beside a live payment form is a revenue
 *     incident, and nothing the assistant offers is worth that risk. This is not
 *     configurable on purpose.
 *  2. Logged-in admins only, until someone explicitly flips visible_to to 'everyone'.
 *     That makes "deploy it" and "show it to customers" two separate decisions.
 */
function pps_assistant_should_render() {
    if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) return false;

    $cfg = pps_assistant_config();
    if ( ( $cfg['visible_to'] ?? 'admins' ) === 'everyone' ) return true;

    return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

add_action( 'wp_footer', function () {
    if ( ! pps_assistant_enabled() || is_admin() ) return;
    if ( ! pps_assistant_should_render() ) return;

    $boot = wp_json_encode( array(
        'endpoint' => rest_url( 'pps/v1/assistant/chat' ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'greeting' => 'Hi — I can help with order status, file setup, and product questions. '
                    . 'For pricing I\'ll point you at the calculator.',
        // Badge the launcher while it's admin-only, so nobody mistakes a test session
        // for something customers can see.
        'admin'    => current_user_can( 'manage_options' ) && ( pps_assistant_config()['visible_to'] ?? '' ) !== 'everyone',
    ) );

    // NOTE: never emit a literal closing script tag inside this JS. Same hazard as the
    // calculators' babel blocks — the HTML parser closes at the first match.
    ?>
    <div id="pps-asst-root"></div>
    <script>
    (function () {
      var CFG = <?php echo $boot; // phpcs:ignore ?>;
      var C = { cyan:'#007eff', ink:'#0f172a', mid:'#475569', bg:'#f6f7f9', border:'#e3e8ef' };
      var sid = sessionStorage.getItem('ppsAsstSid');
      if (!sid) { sid = 'S' + Math.random().toString(36).slice(2) + Date.now().toString(36);
                  sessionStorage.setItem('ppsAsstSid', sid); }

      var root = document.getElementById('pps-asst-root');
      root.innerHTML =
        '<button id="pps-asst-launch" aria-label="Open chat">Chat' +
          (CFG.admin ? '<span class="pps-asst-flag">admin only</span>' : '') + '</button>' +
        '<div id="pps-asst-panel" hidden>' +
          '<header><span>Priority Print Service</span><button id="pps-asst-close" aria-label="Close">&times;</button></header>' +
          '<div id="pps-asst-log" role="log" aria-live="polite"></div>' +
          '<form id="pps-asst-form">' +
            '<input id="pps-asst-input" autocomplete="off" placeholder="Ask about an order, file setup, or a product…">' +
            '<button type="submit">Send</button>' +
          '</form>' +
        '</div>';

      var log = document.getElementById('pps-asst-log');
      var busy = false;

      function add(role, text) {
        var el = document.createElement('div');
        el.className = 'pps-asst-msg pps-asst-' + role;
        el.textContent = text;
        log.appendChild(el);
        log.scrollTop = log.scrollHeight;
        return el;
      }

      document.getElementById('pps-asst-launch').onclick = function () {
        var p = document.getElementById('pps-asst-panel');
        p.hidden = false;
        if (!log.childElementCount) add('bot', CFG.greeting);
        document.getElementById('pps-asst-input').focus();
      };
      document.getElementById('pps-asst-close').onclick = function () {
        document.getElementById('pps-asst-panel').hidden = true;
      };

      document.getElementById('pps-asst-form').onsubmit = function (e) {
        e.preventDefault();
        if (busy) return;
        var input = document.getElementById('pps-asst-input');
        var text = input.value.trim();
        if (!text) return;
        input.value = '';
        add('user', text);

        busy = true;
        var pending = add('bot', '…');
        pending.className += ' pps-asst-pending';

        fetch(CFG.endpoint, {
          method: 'POST',
          // X-WP-Nonce is what authenticates the cookie session for the REST API. Without
          // it WordPress runs the request as user 0, so a nonce minted for a logged-in
          // admin fails verification and the endpoint 403s. Sending the nonce in the JSON
          // body alone is NOT enough — that only feeds our own wp_verify_nonce call, which
          // is itself evaluated against whatever user WordPress resolved.
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({ message: text, session: sid, nonce: CFG.nonce })
        })
        .then(function (r) {
          return r.text().then(function (body) {
            if (!r.ok) throw new Error('HTTP ' + r.status + ' — ' + body.slice(0, 300));
            return JSON.parse(body);
          });
        })
        .then(function (d) { pending.className = 'pps-asst-msg pps-asst-bot';
                             pending.textContent = d.reply || ''; })
        .catch(function (err) {
          pending.className = 'pps-asst-msg pps-asst-bot';
          // Admins testing get the real failure; customers get the friendly line.
          pending.textContent = CFG.admin
            ? 'DEBUG (admin only): ' + err.message
            : 'Sorry — something went wrong. Please email us and we will help.';
        })
        .finally(function () { busy = false; log.scrollTop = log.scrollHeight; });
      };
    })();
    </script>
    <style>
      #pps-asst-launch{position:fixed;right:20px;bottom:20px;z-index:99998;background:#007eff;color:#fff;
        border:0;border-radius:24px;padding:12px 22px;font:600 15px/1 system-ui,sans-serif;cursor:pointer;
        box-shadow:0 4px 14px rgba(16,24,40,.18)}
      .pps-asst-flag{display:inline-block;margin-left:8px;background:rgba(255,255,255,.22);border-radius:10px;
        padding:2px 8px;font-size:11px;font-weight:600;letter-spacing:.02em;vertical-align:middle}
      #pps-asst-panel{position:fixed;right:20px;bottom:20px;z-index:99999;width:360px;max-width:calc(100vw - 32px);
        height:520px;max-height:calc(100vh - 40px);display:flex;flex-direction:column;background:#fff;
        border:1px solid #e3e8ef;border-radius:14px;box-shadow:0 12px 40px rgba(16,24,40,.18);overflow:hidden;
        font:400 14px/1.5 system-ui,sans-serif}
      #pps-asst-panel header{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;
        border-bottom:1px solid #e3e8ef;font-weight:600;color:#0f172a}
      #pps-asst-panel header button{background:0;border:0;font-size:22px;line-height:1;cursor:pointer;color:#475569}
      #pps-asst-log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#f6f7f9}
      .pps-asst-msg{max-width:85%;padding:9px 12px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word}
      .pps-asst-bot{background:#fff;border:1px solid #e3e8ef;color:#0f172a;align-self:flex-start}
      .pps-asst-user{background:#007eff;color:#fff;align-self:flex-end}
      .pps-asst-pending{opacity:.55}
      #pps-asst-form{display:flex;gap:8px;padding:10px;border-top:1px solid #e3e8ef;background:#fff}
      #pps-asst-input{flex:1;border:1px solid #e3e8ef;border-radius:9px;padding:9px 11px;font:inherit;background:#fff}
      #pps-asst-input:focus{outline:0;border-color:#007eff;box-shadow:0 0 0 3px rgba(0,126,255,.12)}
      #pps-asst-form button{background:#007eff;color:#fff;border:0;border-radius:9px;padding:9px 16px;
        font:600 14px/1 system-ui,sans-serif;cursor:pointer}
    </style>
    <?php
}, 99 );

// ═══════════════════════════════════════════════════════════════
// 9. ADMIN
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
    add_submenu_page(
        'pps-calculators',                 // parent slug — adjust if the menu slug differs
        'Assistant',
        'Assistant',
        'manage_options',
        'pps-assistant',
        'pps_assistant_render_admin'
    );
}, 20 );

function pps_assistant_render_admin() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );

    if ( isset( $_POST['pps_assistant_nonce'] )
      && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pps_assistant_nonce'] ) ), 'pps_assistant_save' ) ) {

        $cfg = pps_assistant_config();
        $cfg['enabled']    = ! empty( $_POST['enabled'] );
        $cfg['visible_to'] = ( ( $_POST['visible_to'] ?? '' ) === 'everyone' ) ? 'everyone' : 'admins';
        $cfg['api_key']   = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        $cfg['model']     = sanitize_text_field( wp_unslash( $_POST['model'] ?? 'claude-opus-5' ) );
        $cfg['effort']    = sanitize_key( wp_unslash( $_POST['effort'] ?? 'medium' ) );
        $cfg['daily_cap'] = max( 0, (int) ( $_POST['daily_cap'] ?? 300 ) );
        $cfg['policy']    = sanitize_textarea_field( wp_unslash( $_POST['policy'] ?? '' ) );

        update_option( 'pps_assistant_config', $cfg );
        delete_transient( 'pps_assistant_catalog' );
        echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }

    $cfg   = pps_assistant_config();
    $today = (int) get_transient( 'pps_assistant_calls_' . gmdate( 'Y-m-d' ) );
    ?>
    <div class="wrap">
        <h1>PPS Assistant</h1>
        <p><strong>API calls today:</strong> <?php echo (int) $today; ?> / <?php echo (int) $cfg['daily_cap']; ?></p>
        <?php if ( $cfg['visible_to'] === 'everyone' && ! empty( $cfg['enabled'] ) ) : ?>
            <div class="notice notice-warning inline"><p><strong>Live to customers.</strong>
            Every visitor sees the chat widget (except on cart and checkout).</p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field( 'pps_assistant_save', 'pps_assistant_nonce' ); ?>
            <table class="form-table">
                <tr><th>Enabled</th><td>
                    <label><input type="checkbox" name="enabled" <?php checked( $cfg['enabled'] ); ?>> Serve the widget</label>
                    <p class="description">Unchecking this is the kill switch — no API calls are made.</p>
                </td></tr>
                <tr><th>Visible to</th><td>
                    <label><input type="radio" name="visible_to" value="admins" <?php checked( $cfg['visible_to'], 'admins' ); ?>>
                        <strong>Logged-in admins only</strong> — safe for testing on a live site</label><br>
                    <label><input type="radio" name="visible_to" value="everyone" <?php checked( $cfg['visible_to'], 'everyone' ); ?>>
                        Everyone — customers will see it</label>
                    <p class="description">Never renders on cart or checkout, under either setting.</p>
                </td></tr>
                <tr><th>API key</th><td>
                    <input type="password" name="api_key" value="<?php echo esc_attr( $cfg['api_key'] ); ?>" class="regular-text" autocomplete="off">
                </td></tr>
                <tr><th>Model</th><td>
                    <input type="text" name="model" value="<?php echo esc_attr( $cfg['model'] ); ?>" class="regular-text">
                    <p class="description">claude-opus-5 (default) · claude-sonnet-5 · claude-haiku-4-5</p>
                </td></tr>
                <tr><th>Effort</th><td>
                    <select name="effort">
                        <?php foreach ( array( 'low', 'medium', 'high', 'xhigh', 'max' ) as $e ) : ?>
                            <option value="<?php echo esc_attr( $e ); ?>" <?php selected( $cfg['effort'], $e ); ?>><?php echo esc_html( $e ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Start at medium and sweep down — low and medium are strong on Opus 5, and lower effort means faster replies.</p>
                </td></tr>
                <tr><th>Daily cap</th><td>
                    <input type="number" name="daily_cap" value="<?php echo (int) $cfg['daily_cap']; ?>" min="0">
                </td></tr>
                <tr><th>Policy prompt</th><td>
                    <textarea name="policy" rows="16" class="large-text code" placeholder="Leave blank to use the built-in default"><?php echo esc_textarea( $cfg['policy'] ); ?></textarea>
                    <p class="description">Editing this changes the cached prefix — the next request pays a fresh cache write.</p>
                </td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
