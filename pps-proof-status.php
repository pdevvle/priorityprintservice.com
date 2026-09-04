<?php
/**
 * Plugin Name: PPS Proof Status
 * Description: Makes "SelfApproved" mean what it says. The spec token now reflects whether the customer actually signed off on their artwork in the online proofer, not merely which proof product they bought, and every order records the approval state explicitly.
 * Version: 1.0.0
 * Author: Priority Print Service
 *
 * ── The problem ──────────────────────────────────────────────────────────────
 *
 * PPS-Spec derived its proof token from the proof OPTION the customer selected:
 *
 *     $proof = $full['proof'] >= 3 ? 'Hardcopy'
 *            : ($full['proof'] > 0 ? 'DigitalProof' : 'SelfApproved');
 *
 * `proof` is a purchase: 0 = the free "Proof & Approve Online", 1-2 = a paid
 * manual digital proof, 3 = a paid hardcopy. Zero is also the default the
 * calculator boots with. So "SelfApproved" appeared on every order where the
 * customer simply didn't buy a staff proof — including someone who uploaded a
 * PDF, never opened the proofer, and checked out. It described a thing not
 * bought, while reading like a thing done.
 *
 * ── What actually proves a sign-off ──────────────────────────────────────────
 *
 * `_pps_proof_hash`: a SHA-256 of the exact print-ready bytes, emitted only from
 * the approval branch in the calculator, after the customer clicks approve and
 * the approval package is generated. The imposition tool already refuses to
 * impose a file whose hash does not match it. It is the one signal in the system
 * that cannot be produced by accident.
 *
 * ── What this file does ──────────────────────────────────────────────────────
 *
 * Rewrites ONLY the `SelfApproved` token in PPS-Spec, to one of:
 *
 *   SelfApproved  the print file was approved in the proofer, hash on the order
 *   ArtToFollow   no finished artwork at order time (emailing later, Canva,
 *                 already discussed, or design/edit services purchased)
 *   NotApproved   artwork was uploaded but nobody signed off on it
 *
 * `Hardcopy` and `DigitalProof` are left exactly as they are: those describe a
 * purchase that genuinely happened, and any Missive rule matching them keeps
 * working unchanged.
 *
 * It also adds a `PPS-Proof` item meta spelling the state out in words (with the
 * hash when there is one), and drops an order note when a job arrives carrying
 * artwork that was never approved — the case worth someone's attention.
 *
 * Standalone on purpose: production's pps-calculators.php is ~78 KB ahead of the
 * copy in git, so the spec builder cannot be fixed by redeploying that file.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** The token the upstream spec builder emits when no staff proof was purchased. */
if ( ! defined( 'PPS_PROOF_LEGACY_TOKEN' ) ) define( 'PPS_PROOF_LEGACY_TOKEN', 'SelfApproved' );

/**
 * Did the customer sign off in the proofer?
 *
 * The hash is the whole test. It is a SHA-256 of the print file the approval was
 * taken against, so it exists only if that approval happened and the file
 * uploaded. Same shape check the main plugin applies before storing it.
 */
function pps_proof_is_approved( $values ) {
    $hash = isset( $values['pps_proof_hash'] ) ? strtolower( (string) $values['pps_proof_hash'] ) : '';
    return (bool) preg_match( '/^[0-9a-f]{64}$/', $hash );
}

/**
 * Is a finished file expected to arrive later rather than now?
 *
 * Artwork option 0.01 is "Upload Art with Order" — the only one that means a
 * print-ready file should be in hand at checkout. Everything else (email later,
 * already discussed, Canva link, artwork needs edits, design from scratch) means
 * there is legitimately nothing to have approved yet, and flagging those as
 * unapproved would cry wolf on the majority of design jobs.
 */
function pps_proof_art_to_follow( $meta, $values ) {
    $art = isset( $meta['artwork'] ) ? (float) $meta['artwork'] : 0.0;
    if ( abs( $art - 0.01 ) < 0.0001 ) return false;      // upload with order
    if ( $art > 0 ) return true;                          // every other art route

    // Metadata missing or unrecognised: fall back to whether a file actually
    // travelled with the order.
    return empty( $values['pps_artwork_path'] ) && empty( $values['pps_artwork_files'] );
}

/**
 * Resolve one line item to a token plus the sentence a human should read.
 * Returns array( token, sentence, hash|'' ).
 */
function pps_proof_resolve( $values ) {
    $meta = json_decode( (string) ( $values['pps_metadata'] ?? '' ), true );
    if ( ! is_array( $meta ) ) $meta = array();

    $hash     = isset( $values['pps_proof_hash'] ) ? strtolower( (string) $values['pps_proof_hash'] ) : '';
    $approved = pps_proof_is_approved( $values );
    $bought   = isset( $meta['proof'] ) ? (float) $meta['proof'] : 0.0;

    if ( $approved ) {
        return array(
            'SelfApproved',
            'Approved by the customer in the online proofer. Print file SHA-256 ' . $hash . '.',
            $hash,
        );
    }

    if ( $bought >= 3 ) {
        return array( 'Hardcopy', 'Hardcopy proof purchased — awaiting sign-off on the printed proof.', '' );
    }
    if ( $bought > 0 ) {
        return array( 'DigitalProof', 'Manual digital proof purchased — awaiting sign-off from staff proof.', '' );
    }

    if ( pps_proof_art_to_follow( $meta, $values ) ) {
        return array( 'ArtToFollow', 'No artwork at order time — nothing has been proofed or approved yet.', '' );
    }

    return array(
        'NotApproved',
        'Artwork was uploaded but NOT approved in the online proofer. No sign-off on file.',
        '',
    );
}

/**
 * Swap the token inside the pipe-delimited spec.
 *
 * Split and compare whole segments rather than str_replace on the raw string: a
 * job name or a paper label could contain the word, and rewriting that would
 * corrupt a spec Missive parses.
 */
function pps_proof_rewrite_spec( $spec, $token ) {
    $parts   = array_map( 'trim', explode( '|', (string) $spec ) );
    $changed = false;
    foreach ( $parts as $i => $p ) {
        if ( $p === PPS_PROOF_LEGACY_TOKEN ) {
            $parts[ $i ] = $token;
            $changed     = true;
        }
    }
    return $changed ? implode( ' | ', $parts ) : null;
}

// Priority 100: after the main plugin writes PPS-Spec (10) and after the
// delivery-date guard (99), so this reads the finished spec.
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
    if ( ! is_object( $item ) || ! isset( $values['pps_metadata'] ) ) return;

    list( $token, $sentence, $hash ) = pps_proof_resolve( $values );

    // Spec token — only ever replaces the inaccurate one.
    $spec = (string) $item->get_meta( 'PPS-Spec', true );
    if ( $spec !== '' ) {
        $rewritten = pps_proof_rewrite_spec( $spec, $token );
        if ( $rewritten !== null ) $item->update_meta_data( 'PPS-Spec', $rewritten );
    }

    // The state in words, on the item, always. This is the record: it survives
    // regardless of what any spec parser does with the token.
    $item->update_meta_data( 'PPS-Proof', $sentence );

    // A file we hold that nobody signed off on is the case worth interrupting
    // someone for. The other states are ordinary business and stay quiet.
    if ( $token === 'NotApproved' && is_object( $order ) && method_exists( $order, 'add_order_note' ) ) {
        $name = method_exists( $item, 'get_name' ) ? $item->get_name() : 'item';
        $order->add_order_note( sprintf(
            'PROOF: no sign-off recorded for "%s". Artwork was uploaded but never approved through the online proofer, '
            . 'so there is no approved print file and no SHA-256 binding one. Confirm with the customer before production.',
            $name
        ) );
    }
}, 100, 4 );

// PPS-Proof is staff information. The main plugin strips its own internal keys
// from customer-facing surfaces via pps_internal_item_meta_keys(); that list is a
// plain function, not a filter, so this mirrors the same test rather than editing
// it. Reuses the global the main plugin sets around the email order table.
add_filter( 'woocommerce_order_item_get_formatted_meta_data', function( $formatted, $item ) {
    if ( ! is_array( $formatted ) ) return $formatted;

    // Same fail-open posture as the main plugin: strip only on positive
    // identification of a customer surface. Being wrong the other way would hide
    // the field from staff, which is where it matters.
    $customer_email = isset( $GLOBALS['pps_email_sent_to_admin'] ) && ! $GLOBALS['pps_email_sent_to_admin'];

    $customer_page = false;
    if ( ! is_admin() && function_exists( 'is_wc_endpoint_url' ) ) {
        $customer_page = is_wc_endpoint_url( 'order-received' )
                      || is_wc_endpoint_url( 'view-order' )
                      || is_wc_endpoint_url( 'order-pay' );
    }

    if ( ! $customer_email && ! $customer_page ) return $formatted;

    foreach ( $formatted as $id => $meta ) {
        if ( isset( $meta->key ) && $meta->key === 'PPS-Proof' ) unset( $formatted[ $id ] );
    }
    return $formatted;
}, 20, 2 );
