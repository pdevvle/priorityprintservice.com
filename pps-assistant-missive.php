<?php
/**
 * PPS Assistant — Missive custom-channel bridge (stage 2).
 *
 * Required by pps-assistant.php. Not a plugin in its own right: no plugin header, and
 * every entry point returns a refusal unless PPS_ASSISTANT_VERSION is already defined.
 *
 * ── WHAT THIS DOES ──
 * Moves a live conversation out of Claude and into Missive, and carries the agent's
 * replies back to the widget:
 *
 *   escalate_to_human (human available)
 *      └─ pps_assistant_missive_open()  →  POST /v1/messages   (creates the conversation)
 *   agent types a reply in Missive
 *      └─ outgoing webhook  →  pps-assistant-webhook.php  →  pps_assistant_missive_receive()
 *      └─ appended to $session['human_log']
 *   widget polls /pps/v1/assistant/poll  →  renders it
 *
 * ── THE HONEST PART ──
 * Missive 403s every automated fetch of its own documentation, so two things here are
 * RECONSTRUCTED rather than read off a spec:
 *
 *   1. The outbound body shape. Base URL, Bearer auth, POST /messages and the need to
 *      pass a channel id are confirmed; the exact key names are modelled on the /v1/posts
 *      endpoint, which wraps its payload in a resource-named key.
 *   2. The inbound webhook payload. Entirely undocumented publicly.
 *
 * Both unknowns are contained deliberately:
 *
 *   · Every outbound attempt records the exact request and the exact response to
 *     wp_options['pps_assistant_missive_log']. One "Send test message" click from the
 *     admin screen turns the guess into a fact, with no customer involved.
 *   · The payload is built in ONE function behind the `pps_assistant_missive_payload`
 *     filter, so correcting a field name is a one-line change and needs no redeploy of
 *     the engine.
 *   · Inbound extraction tries every plausible field path and logs the raw body when
 *     none match, so a miss is diagnosable from a single real delivery.
 *   · A failed post NEVER silently degrades the promise made to the customer. The
 *     handoff only happens if the post succeeded; otherwise the caller falls back to the
 *     email path and the model is told not to claim anyone is picking it up.
 *
 * ── THREADING ──
 * Every message we post carries references: ["pps-session-<sid>"]. That is the same
 * mechanism email uses to thread, and it means we can find our way back from a webhook
 * delivery to a session WITHOUT depending on Missive echoing a conversation id we
 * recognise. The conversation id is stored too, as a second route home.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! defined( 'PPS_ASSISTANT_VERSION' ) ) return;   // engine not loaded

define( 'PPS_ASSISTANT_MISSIVE_API', 'https://public.missiveapp.com/v1' );
define( 'PPS_ASSISTANT_MISSIVE_LOG', 'pps_assistant_missive_log' );

// ═══════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════

/** Is the bridge configured well enough to attempt anything? */
function pps_assistant_missive_ready() {
    $cfg = pps_assistant_config();
    return ! empty( $cfg['missive_channel_id'] ) && ! empty( $cfg['missive_token'] );
}

/**
 * Is this a text channel rather than an email one?
 *
 * Not cosmetic. Missive validates on it: posting a subject to a text channel returns
 * HTTP 400 "'subject' is not allowed for 'text' messages", and an HTML body on a text
 * channel reaches the agent as literal <p> tags. A custom channel built for live chat is
 * a text channel, so that is the default.
 */
function pps_assistant_missive_is_text_channel() {
    $cfg = pps_assistant_config();
    return ( $cfg['missive_channel_type'] ?? 'text' ) !== 'email';
}

/**
 * What name the CUSTOMER sees on an agent's reply.
 *
 * Defaults to the channel alias, not the person who typed. A shared alias like
 * "Priority Print Service Staff" exists precisely so a customer is talking to the shop
 * rather than to whoever happened to be at the desk — showing individual staff names
 * would undo the reason the alias was configured, and it puts real people's names in
 * front of strangers by default.
 *
 * The real author is still recorded in the bridge log, so internally you can always tell
 * who replied. That split is deliberate: attribution for you, one identity for them.
 */
function pps_assistant_missive_agent_display( $author ) {
    $cfg = pps_assistant_config();

    if ( ! empty( $cfg['missive_show_agent_name'] ) && is_string( $author ) && $author !== '' ) {
        return $author;
    }

    $name = (string) ( $cfg['missive_alias_name'] ?? '' );
    if ( $name === '' ) $name = (string) ( $cfg['missive_alias'] ?? '' );
    if ( $name === '' && function_exists( 'get_bloginfo' ) ) $name = (string) get_bloginfo( 'name' );

    return $name !== '' ? $name : 'Our team';
}

/**
 * The threading key for a session.
 *
 * Ours, not Missive's — which is the whole point. A webhook delivery that echoes this
 * back identifies the session exactly, with no id-format assumptions.
 */
function pps_assistant_missive_reference( $sid ) {
    return 'pps-session-' . sanitize_key( (string) $sid );
}

// ═══════════════════════════════════════════════════════════════
// DIAGNOSTIC LOG
//
// The only reason the two reconstructed shapes above are acceptable risk. Bounded,
// autoload off, and it records what was SENT as well as what came back — a 422 whose
// request body you cannot see tells you nothing.
// ═══════════════════════════════════════════════════════════════

function pps_assistant_missive_log( $event, array $data ) {
    $log = get_option( PPS_ASSISTANT_MISSIVE_LOG, array() );
    if ( is_string( $log ) ) $log = json_decode( $log, true );
    if ( ! is_array( $log ) ) $log = array();

    array_unshift( $log, array_merge( array( 'at' => gmdate( 'c' ), 'event' => $event ), $data ) );
    update_option( PPS_ASSISTANT_MISSIVE_LOG, array_slice( $log, 0, 15 ), false );
}

/**
 * Strip a payload down to its SHAPE before logging it.
 *
 * The reason for logging the request is to see which field names Missive rejected. The
 * customer's name, email, phone and entire chat transcript are not part of that, and a
 * diagnostic buffer rendered on an admin screen and kept for 15 entries is the wrong
 * place for them. Field names are preserved exactly; their contents are not.
 */
function pps_assistant_missive_loggable( array $payload ) {
    $p = $payload;
    foreach ( array( 'messages', 'message' ) as $k ) {
        if ( empty( $p[ $k ] ) || ! is_array( $p[ $k ] ) ) continue;

        if ( isset( $p[ $k ]['body'] ) ) {
            $p[ $k ]['body'] = '[' . strlen( (string) $p[ $k ]['body'] ) . ' bytes omitted]';
        }
        if ( isset( $p[ $k ]['subject'] ) ) $p[ $k ]['subject'] = '[omitted]';

        foreach ( array( 'from_field', 'to_fields' ) as $addr ) {
            if ( empty( $p[ $k ][ $addr ] ) || ! is_array( $p[ $k ][ $addr ] ) ) continue;
            // to_fields is a list, from_field a single object — normalise the walk.
            $rows = isset( $p[ $k ][ $addr ][0] ) ? $p[ $k ][ $addr ] : array( $p[ $k ][ $addr ] );
            foreach ( $rows as $i => $row ) {
                foreach ( array( 'name', 'username', 'id' ) as $field ) {
                    if ( isset( $row[ $field ] ) ) $rows[ $i ][ $field ] = '[omitted]';
                }
            }
            $p[ $k ][ $addr ] = isset( $p[ $k ][ $addr ][0] ) ? $rows : $rows[0];
        }
    }
    return $p;
}

/**
 * Redact before logging. The bearer token never appears in the log — a diagnostic buffer
 * readable from an admin screen is not where a credential should live.
 */
function pps_assistant_missive_safe( $s, $limit = 4000 ) {
    $s = (string) $s;
    $cfg = pps_assistant_config();
    if ( ! empty( $cfg['missive_token'] ) ) {
        $s = str_replace( $cfg['missive_token'], '[token redacted]', $s );
    }
    return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $limit ) : substr( $s, 0, $limit );
}

// ═══════════════════════════════════════════════════════════════
// OUTBOUND — WP → Missive
// ═══════════════════════════════════════════════════════════════

/**
 * Build the POST body.
 *
 * Isolated and filtered because it is the single reconstructed thing in the outbound
 * path. If the test send comes back 422 naming a field, fix it here (or from a theme,
 * via the filter) and nothing else changes.
 *
 * from_field is the VISITOR and to_fields is our alias: we are creating an *incoming*
 * message on the channel, the same direction a real customer email would arrive from.
 */
function pps_assistant_missive_payload( $sid, array $session, $body, array $opts = array() ) {
    $cfg = pps_assistant_config();

    $name  = ( $session['name'] ?? '' ) ?: 'Website visitor';
    $email = ( $session['email'] ?? '' ) ?: '';
    $host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

    // Missive keys a contact off username. A visitor who somehow reached a handoff with
    // no email still needs a stable, unique one or separate strangers merge into one
    // contact record.
    if ( ! $email ) $email = 'chat-' . sanitize_key( (string) $sid ) . '@' . ( $host ?: 'example.com' );

    $message = array(
        'account'    => (string) $cfg['missive_channel_id'],
        'from_field' => array(
            'id'       => 'pps-visitor-' . sanitize_key( (string) $sid ),
            'name'     => $name,
            'username' => $email,
        ),
        'to_fields'  => array( array(
            'id'       => (string) ( $cfg['missive_alias'] ?: 'website-chat' ),
            'name'     => (string) ( $cfg['missive_alias_name'] ?: 'Website chat' ),
            'username' => (string) ( $cfg['missive_alias'] ?: 'website-chat' ),
        ) ),
        'body'       => (string) $body,
        // Ours. Survives whatever Missive does with its own ids.
        'references' => array( pps_assistant_missive_reference( $sid ) ),
    );

    // Only an email channel takes one. Sending it to a text channel is a hard 400, not a
    // warning — Missive refuses the whole message.
    if ( ! pps_assistant_missive_is_text_channel() ) {
        $message['subject'] = (string) ( $opts['subject'] ?? ( 'Website chat — ' . $name ) );
    }

    // Thread into the conversation we already opened, when we know it.
    if ( ! empty( $session['missive_conversation'] ) ) {
        $message['conversation'] = (string) $session['missive_conversation'];
    }
    if ( ! empty( $opts['add_to_inbox'] ) ) {
        $message['add_to_inbox'] = true;
    }

    return apply_filters( 'pps_assistant_missive_payload', array( 'messages' => $message ), $sid, $session, $opts );
}

/**
 * POST a message onto the custom channel.
 *
 * Returns the decoded response array, or WP_Error. Callers must treat WP_Error as
 * "the customer was NOT handed off" — never as a soft warning.
 */
function pps_assistant_missive_send( $sid, array $session, $body, array $opts = array() ) {
    if ( ! pps_assistant_missive_ready() ) {
        return new WP_Error( 'not_configured', 'Missive channel id or API token is missing.' );
    }
    // Without a session id the threading key degenerates to a shared constant, and every
    // visitor's handoff lands in one conversation — replies would then be delivered to
    // whichever stranger polled first. Refuse rather than cross the wires.
    if ( sanitize_key( (string) $sid ) === '' ) {
        return new WP_Error( 'no_session', 'Refusing to post without a session id.' );
    }

    $cfg     = pps_assistant_config();
    $payload = pps_assistant_missive_payload( $sid, $session, $body, $opts );

    // Test seam, mirroring pps_assistant_pre_api_call. Keeps the guardrail suite free of
    // network access while still exercising every branch below.
    $pre = apply_filters( 'pps_assistant_missive_pre_send', null, $payload, $sid );
    if ( $pre !== null ) return $pre;

    $res = wp_remote_post( PPS_ASSISTANT_MISSIVE_API . '/messages', array(
        // Short on purpose. This runs inside a customer's chat turn — a stalled handoff
        // that holds the request open for 30s is worse than a fast fall back to email.
        'timeout' => 12,
        'headers' => array(
            'Authorization' => 'Bearer ' . $cfg['missive_token'],
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode( $payload ),
    ) );

    if ( is_wp_error( $res ) ) {
        pps_assistant_missive_log( 'send_transport_error', array(
            'sid'     => $sid,
            'request' => pps_assistant_missive_safe( wp_json_encode( pps_assistant_missive_loggable( $payload ) ) ),
            'error'   => $res->get_error_message(),
        ) );
        return new WP_Error( 'transport', $res->get_error_message() );
    }

    $code = (int) wp_remote_retrieve_response_code( $res );
    $raw  = (string) wp_remote_retrieve_body( $res );
    $data = json_decode( $raw, true );

    // Record BOTH directions. A 422 naming a field we got wrong is the single most
    // useful artefact this whole file can produce, and it is worthless without the
    // request body beside it.
    pps_assistant_missive_log( $code >= 200 && $code < 300 ? 'send_ok' : 'send_failed', array(
        'sid'      => $sid,
        'status'   => $code,
        'request'  => pps_assistant_missive_safe( wp_json_encode( pps_assistant_missive_loggable( $payload ) ) ),
        'response' => pps_assistant_missive_safe( $raw ),
    ) );

    if ( $code < 200 || $code >= 300 ) {
        error_log( '[pps-assistant] missive send failed ' . $code . ': ' . substr( $raw, 0, 300 ) );
        return new WP_Error( 'http', 'Missive returned HTTP ' . $code, array( 'status' => $code ) );
    }

    return is_array( $data ) ? $data : array();
}

/**
 * Dig the conversation id out of a send response.
 *
 * Shape unconfirmed, so try the plausible paths rather than asserting one. Returning ''
 * is survivable — the references key is the primary route home, this is the backup.
 */
function pps_assistant_missive_conversation_id( $response ) {
    if ( ! is_array( $response ) ) return '';

    $m = $response['messages'] ?? $response['message'] ?? $response;
    if ( ! is_array( $m ) ) return '';

    foreach ( array( $m, $response ) as $node ) {
        if ( ! is_array( $node ) ) continue;
        if ( ! empty( $node['conversation'] ) && is_string( $node['conversation'] ) ) return $node['conversation'];
        if ( ! empty( $node['conversation']['id'] ) ) return (string) $node['conversation']['id'];
        if ( ! empty( $node['conversation_id'] ) ) return (string) $node['conversation_id'];
    }
    return '';
}

/**
 * Open a live handoff: post the transcript into Missive and index the conversation.
 *
 * Returns true only when the message is genuinely in Missive. The caller keys the
 * customer-facing promise off this boolean, so a soft-true here becomes a lie on screen.
 */
function pps_assistant_missive_open( $sid, array &$session, $reason, $summary ) {
    $body = pps_assistant_missive_body( $session, $reason, $summary );

    $res = pps_assistant_missive_send( $sid, $session, $body, array(
        'subject'      => 'Website chat — ' . ( ( $session['name'] ?? '' ) ?: 'visitor' )
                        . ( $reason ? ' — ' . wp_trim_words( (string) $reason, 8 ) : '' ),
        'add_to_inbox' => true,
    ) );

    if ( is_wp_error( $res ) ) return false;

    $conv = pps_assistant_missive_conversation_id( $res );
    if ( $conv ) {
        $session['missive_conversation'] = $conv;
        pps_assistant_missive_index( $conv, $sid );
    }
    // Index by our own reference too — this is the route that does not depend on any
    // Missive id format being what we assumed.
    pps_assistant_missive_index( pps_assistant_missive_reference( $sid ), $sid );

    return true;
}

/** The opening message an agent sees: who this is, and everything said so far. */
function pps_assistant_missive_body( array $session, $reason, $summary ) {
    $rows = array(
        'Name'    => ( $session['name'] ?? '' ) ?: '(not given)',
        'Company' => ( $session['company'] ?? '' ) ?: '(not given)',
        'Email'   => ( $session['email'] ?? '' ) ?: '(not given)',
        'Phone'   => ( $session['phone'] ?? '' ) ?: '(not given)',
        'Order'   => ( $session['verified_order'] ?? '' ) ?: '(none verified)',
        'Reason'  => (string) $reason,
    );

    // A text channel shows markup as literal tags, so it gets a plain-text build. This is
    // the shape an agent actually reads on a chat channel anyway.
    if ( pps_assistant_missive_is_text_channel() ) {
        $out = "Live handoff from the website chat — reply here and it appears in the "
             . "visitor's chat window.\n\n";
        foreach ( $rows as $k => $v ) {
            $out .= $k . ': ' . (string) $v . "\n";
        }
        if ( $summary ) $out .= "\nSummary: " . (string) $summary . "\n";
        $out .= "\n--- conversation so far ---\n" . pps_assistant_transcript( $session );
        return $out;
    }

    $html = '<p><strong>Live handoff from the website chat.</strong> '
          . 'Reply here and it appears in the visitor\'s chat window.</p><ul>';
    foreach ( $rows as $k => $v ) {
        $html .= '<li><strong>' . esc_html( $k ) . ':</strong> ' . esc_html( (string) $v ) . '</li>';
    }
    $html .= '</ul>';

    if ( $summary ) $html .= '<p><strong>Summary:</strong> ' . esc_html( (string) $summary ) . '</p>';

    $html .= '<hr><p><strong>Conversation so far</strong></p><pre style="white-space:pre-wrap">'
           . esc_html( pps_assistant_transcript( $session ) ) . '</pre>';

    return $html;
}

/** Relay a visitor's later message into the open Missive conversation. */
function pps_assistant_missive_relay( $sid, array $session, $text ) {
    $body = pps_assistant_missive_is_text_channel()
        ? (string) $text
        : '<p>' . esc_html( (string) $text ) . '</p>';

    $res = pps_assistant_missive_send( $sid, $session, $body );
    return ! is_wp_error( $res );
}

// ═══════════════════════════════════════════════════════════════
// CONVERSATION → SESSION INDEX
//
// The webhook knows a Missive conversation. We need the session id. Transient rather
// than an option row so abandoned chats expire themselves; 24h rather than the session's
// 2h because an agent replying to a stale conversation should still resolve to something
// we can log, instead of looking like an unknown delivery.
// ═══════════════════════════════════════════════════════════════

function pps_assistant_missive_index_key( $key ) {
    return 'pps_asst_mconv_' . md5( (string) $key );
}

function pps_assistant_missive_index( $key, $sid ) {
    if ( ! $key ) return;
    set_transient( pps_assistant_missive_index_key( $key ), (string) $sid, DAY_IN_SECONDS );
}

function pps_assistant_missive_lookup( $key ) {
    if ( ! $key ) return '';
    $sid = get_transient( pps_assistant_missive_index_key( $key ) );
    return is_string( $sid ) ? $sid : '';
}

// ═══════════════════════════════════════════════════════════════
// INBOUND — Missive → WP
// ═══════════════════════════════════════════════════════════════

/**
 * Pull the fields we need out of a webhook delivery.
 *
 * The payload shape is undocumented, so this walks a list of plausible paths instead of
 * asserting one and crashing on the first real delivery. Returns nulls rather than
 * throwing; the caller logs the raw body when the extraction comes back empty, which is
 * what makes the first miss a one-line fix rather than an investigation.
 */
function pps_assistant_missive_extract( $payload ) {
    $out = array( 'reference' => '', 'conversation' => '', 'text' => '', 'author' => '' );
    if ( ! is_array( $payload ) ) return $out;

    // Missive wraps deliveries; unwrap the usual suspects before looking for fields.
    $nodes = array( $payload );
    foreach ( array( 'message', 'messages', 'post', 'posts', 'draft', 'data', 'comment' ) as $k ) {
        if ( isset( $payload[ $k ] ) && is_array( $payload[ $k ] ) ) $nodes[] = $payload[ $k ];
    }

    foreach ( $nodes as $n ) {
        // ── conversation id
        if ( $out['conversation'] === '' ) {
            if ( ! empty( $n['conversation'] ) && is_string( $n['conversation'] ) ) {
                $out['conversation'] = $n['conversation'];
            } elseif ( ! empty( $n['conversation']['id'] ) ) {
                $out['conversation'] = (string) $n['conversation']['id'];
            } elseif ( ! empty( $n['conversation_id'] ) ) {
                $out['conversation'] = (string) $n['conversation_id'];
            }
        }

        // ── our own threading key, wherever it surfaces
        if ( $out['reference'] === '' ) {
            foreach ( array( 'references', 'in_reply_to', 'reference' ) as $rk ) {
                if ( empty( $n[ $rk ] ) ) continue;
                foreach ( (array) $n[ $rk ] as $ref ) {
                    if ( is_string( $ref ) && strpos( $ref, 'pps-session-' ) === 0 ) {
                        $out['reference'] = $ref;
                        break 2;
                    }
                }
            }
        }

        // ── the reply text
        if ( $out['text'] === '' ) {
            foreach ( array( 'body', 'text', 'delivered_body', 'preview', 'markdown' ) as $bk ) {
                if ( ! empty( $n[ $bk ] ) && is_string( $n[ $bk ] ) ) { $out['text'] = $n[ $bk ]; break; }
            }
        }

        // ── who sent it
        //
        // Order matters. On a message going OUT of Missive, from_field is our own channel
        // alias ("Website chat"), not the colleague who typed it — so the person-shaped
        // keys are tried first and from_field is the fallback. Worst case the customer
        // sees the shop name, which is honest; best case they see who they are talking to.
        if ( $out['author'] === '' ) {
            foreach ( array( 'author', 'user', 'sender', 'from_field' ) as $ak ) {
                if ( empty( $n[ $ak ] ) || ! is_array( $n[ $ak ] ) ) continue;
                $a = $n[ $ak ];
                $out['author'] = (string) ( $a['name'] ?? $a['display_name'] ?? $a['username'] ?? '' );
                if ( $out['author'] !== '' ) break;
            }
        }
    }

    return $out;
}

/**
 * Deliver an agent's reply into the visitor's session.
 *
 * Returns a short status string for the webhook's response body — Missive retries on
 * non-2xx, so an unroutable delivery still acknowledges, it just says why.
 */
function pps_assistant_missive_receive( $payload ) {
    $f = pps_assistant_missive_extract( $payload );

    // Reference first: it is the only identifier we minted ourselves.
    $sid = pps_assistant_missive_lookup( $f['reference'] );
    if ( ! $sid ) $sid = pps_assistant_missive_lookup( $f['conversation'] );

    if ( ! $sid ) {
        pps_assistant_missive_log( 'inbound_unmatched', array(
            'extracted' => $f,
            'raw'       => pps_assistant_missive_safe( wp_json_encode( $payload ), 6000 ),
        ) );
        return 'unmatched';
    }

    $text = trim( wp_strip_all_tags( (string) $f['text'] ) );
    if ( $text === '' ) {
        pps_assistant_missive_log( 'inbound_empty', array(
            'sid'       => $sid,
            'extracted' => $f,
            'raw'       => pps_assistant_missive_safe( wp_json_encode( $payload ), 6000 ),
        ) );
        return 'empty';
    }

    // Append through the re-reading helper: the chat endpoint writes this same array from
    // the other direction, across a relay that can sit on the wire for seconds.
    $session = pps_assistant_append_human_log( $sid, array(
        'from' => pps_assistant_missive_agent_display( $f['author'] ),
        'text' => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 4000 ) : substr( $text, 0, 4000 ),
        'at'   => time(),
        'kind' => 'agent',
    ) );

    // An agent replying is what makes the handoff real. Set here too so a conversation
    // still lands even if the mode flip was lost to a race.
    if ( ( $session['mode'] ?? '' ) !== 'human' ) {
        $session['mode'] = 'human';
        pps_assistant_session_save( $sid, $session );
    }

    // Log the SUCCESS too, not only the failures.
    //
    // Without this a working inbound leg is invisible: the operator replies in Missive,
    // everything works, the admin screen shows nothing, and the sensible conclusion is
    // that it failed. Which route matched also matters — reference means our threading
    // key came back, conversation means we fell through to the id.
    //
    // The reply text itself is deliberately not stored; its length is enough to confirm
    // extraction found the right field, and this row lives in an options table.
    pps_assistant_missive_log( 'inbound_delivered', array(
        'sid'     => $sid,
        'matched' => $f['reference'] !== '' ? 'reference' : 'conversation',
        'author'  => $f['author'] ?: '(none found)',
        'chars'   => strlen( $text ),
    ) );

    return 'delivered';
}

// ═══════════════════════════════════════════════════════════════
// ADMIN — "send a test message"
//
// The point of this button: it converts the two reconstructed shapes above from
// assumptions into verified facts, in one click, without a customer in the loop.
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_post_pps_assistant_missive_test', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
    check_admin_referer( 'pps_assistant_missive_test' );

    $sid     = 'admintest' . substr( md5( (string) time() ), 0, 8 );
    $session = array(
        'name'    => 'Connection test',
        'company' => '',
        'email'   => get_option( 'admin_email' ),
        'phone'   => '',
        'messages'=> array(),
    );

    $text = 'Test message from the PPS Assistant bridge. If you can see this in Missive, '
          . 'the outbound half works. Reply to it and the inbound half gets recorded too.';

    $res = pps_assistant_missive_send(
        $sid,
        $session,
        pps_assistant_missive_is_text_channel() ? $text : '<p>' . $text . '</p>',
        array( 'subject' => 'PPS Assistant — connection test', 'add_to_inbox' => true )
    );

    if ( ! is_wp_error( $res ) ) {
        $conv = pps_assistant_missive_conversation_id( $res );
        // Index it so a reply to this very test exercises the inbound path end to end.
        pps_assistant_missive_index( pps_assistant_missive_reference( $sid ), $sid );
        if ( $conv ) pps_assistant_missive_index( $conv, $sid );
    }

    wp_safe_redirect( add_query_arg(
        array( 'page' => 'pps-assistant', 'pps_test' => is_wp_error( $res ) ? 'fail' : 'ok' ),
        admin_url( 'admin.php' )
    ) );
    exit;
} );
