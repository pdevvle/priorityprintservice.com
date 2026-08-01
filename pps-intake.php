<?php
/**
 * Plugin Name: PPS Intake
 * Description: Native replacement for the Forminator forms. Quote requests, trouble tickets
 *              and reorder research all submit through one path into the Calc Questions
 *              record, with one recipient setting and one email format.
 * Version: 0.1.0
 * Author: Priority Print Service
 *
 * ── WHY FORMS AND NOT THE CHAT ──
 * A trouble ticket usually needs research before anyone can answer it. The chat widget and
 * the Missive custom channel exist to make a reply appear in someone's browser in real
 * time, which is the opposite of what a ticket wants. So these submit by email, landing in
 * Missive as an ordinary threaded conversation with Reply-To set to the customer — the same
 * inbox, answered when the work is done rather than while someone waits.
 *
 * Live chat and async tickets are different jobs. This is the async one.
 *
 * ── WHAT IT REPLACES ──
 * Forminator forms 34080 (generic-quote-form), 34161 (support-ticket) and 85266
 * (reorder-form). Swap the shortcode on the page and the plugin can be deactivated:
 *
 *     [forminator_form id="34161"]   →   [pps_intake form="support"]
 *     [forminator_form id="34080"]   →   [pps_intake form="quote"]
 *     [forminator_form id="85266"]   →   [pps_intake form="reorder"]
 *
 * ── WHY ONE RECIPIENT RESOLVER MATTERS ──
 * Before this there were FOUR places a submission address could come from, and they
 * disagreed: the calculators read a Central Config value first, the assistant read only the
 * option, the category wizard hardcoded admin_email, and the Forminator ticket form had its
 * own address baked into the form definition. pps_intake_recipient() is the single answer,
 * and it keeps the calculators' precedence because that is the one an operator can edit.
 *
 * ── ON SPAM ──
 * The Forminator ticket form ran with Akismet off and no captcha, while accepting unlimited
 * files of nearly any type at 50MB each. This uses a honeypot, a per-IP rate limit, an
 * extension allowlist and a size cap. No captcha: it costs real submissions, and the limits
 * below already bound the damage.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_INTAKE_VERSION', '0.1.0' );
define( 'PPS_INTAKE_MAX_FILE', 20 * 1024 * 1024 );   // per file
define( 'PPS_INTAKE_MAX_TOTAL', 40 * 1024 * 1024 );  // per submission

// ═══════════════════════════════════════════════════════════════
// WHERE SUBMISSIONS GO — one answer, used by everything
// ═══════════════════════════════════════════════════════════════

function pps_intake_recipient() {
    // Central Config first: it is the only one of these an operator can change without
    // a developer, so it has to win.
    if ( function_exists( 'pps_get_config' ) ) {
        $cfg  = pps_get_config();
        $cand = isset( $cfg['pcf']['question_recipient_email'] ) ? trim( (string) $cfg['pcf']['question_recipient_email'] ) : '';
        if ( is_email( $cand ) ) return $cand;
    }
    $cand = get_option( 'pps_question_recipient', '' );
    if ( is_email( $cand ) ) return $cand;

    return get_option( 'admin_email' );
}

// ═══════════════════════════════════════════════════════════════
// FORM DEFINITIONS
//
// Declarative so the renderer, the validator and the email formatter all read from one
// place. Adding a field is one array entry, not four edits in three functions.
// ═══════════════════════════════════════════════════════════════

function pps_intake_forms() {
    $name  = array( 'type' => 'text',  'label' => 'Full name',     'required' => true, 'autocomplete' => 'name' );
    $email = array( 'type' => 'email', 'label' => 'Email address', 'required' => true, 'autocomplete' => 'email' );
    // Phone is required on none of them. The Forminator ticket form had no phone field at
    // all, and a required one on a page someone reaches when something has gone wrong is
    // friction at the worst possible moment.
    $phone = array( 'type' => 'tel',   'label' => 'Phone',         'required' => false, 'autocomplete' => 'tel',
                    'help' => 'Optional — only if you would rather we called.' );

    return array(
        'support' => array(
            'title'   => 'Trouble ticket',
            'source'  => 'support-ticket',
            'subject' => 'Trouble ticket',
            'submit'  => 'Submit ticket',
            'uploads' => true,
            'confirm' => 'Thanks — your ticket is logged. We research these properly before '
                       . 'replying, so expect an email rather than an instant answer.',
            'fields'  => array(
                'name'      => $name,
                'email'     => $email,
                'phone'     => $phone,
                'order_ref' => array( 'type' => 'text', 'label' => 'Order reference', 'required' => false,
                                      'placeholder' => 'Invoice # / PO # / Order #',
                                      'help' => 'Anything we can use to identify your order.' ),
                'message'   => array( 'type' => 'textarea', 'label' => 'Describe your issue', 'required' => true,
                                      'rows' => 6, 'maxlength' => 4000 ),
            ),
        ),

        // Mirrors the Forminator quote form field for field, including the conditional
        // reorder question. It is embedded on the home page as well as /contact/, so it is
        // the highest-traffic form on the site — a replacement that quietly drops a field
        // loses real enquiries.
        'quote' => array(
            'title'   => 'Request a quote',
            'source'  => 'quote-form',
            'subject' => 'Quote request',
            'submit'  => 'Submit request',
            'uploads' => true,
            'confirm' => 'Thanks — we have your request and will be in touch shortly.',
            'fields'  => array(
                'is_reorder' => array( 'type' => 'checkbox', 'required' => false,
                                       'label' => 'Is this a reorder or based on a previous order?',
                                       'box'   => 'Yes, I am an existing customer.',
                                       'help'  => 'Tick this if you are a returning customer and want us to '
                                                . 'reference an earlier order.' ),
                'name'       => $name,
                'email'      => array( 'type' => 'email', 'label' => 'Email address', 'required' => true,
                                       'autocomplete' => 'email',
                                       'help' => 'We work through email before and during projects — please '
                                               . 'give an address you monitor.' ),
                // Required here, unlike the trouble ticket. A quote needs a conversation.
                'phone'      => array( 'type' => 'tel', 'label' => 'Phone number', 'required' => true,
                                       'autocomplete' => 'tel',
                                       'help' => 'Used if we need to talk something through.' ),
                'callback'   => array( 'type' => 'checkbox', 'required' => false,
                                       'label' => 'Are you requesting a callback?',
                                       'box'   => 'Yes, I think I need a phone call.',
                                       'help'  => 'We will agree a good time before calling.' ),
                'prev_order' => array( 'type' => 'textarea', 'label' => 'Your previous order', 'required' => false,
                                       'rows' => 4, 'maxlength' => 500, 'show_if' => 'is_reorder',
                                       'help' => 'Order number, billing name or email — anything that helps us find it.' ),
                'message'    => array( 'type' => 'textarea', 'label' => 'Current project details', 'required' => true,
                                       'rows' => 6, 'maxlength' => 500,
                                       'help' => 'Size, page count, paper type, quantity, deadline, and anything '
                                               . 'else you can think of.' ),
            ),
        ),

        'reorder' => array(
            'title'   => 'Reorder request',
            'source'  => 'reorder-request',
            'subject' => 'Reorder research request',
            'submit'  => 'Submit request',
            'uploads' => true,
            'confirm' => 'Thanks — we will research your past project and be in touch shortly.',
            'fields'  => array(
                'basis'      => array( 'type' => 'radio', 'required' => false,
                                       'label'   => 'Is this based on a previous order?',
                                       'options' => array(
                                           'previous' => 'This is based on a previous order.',
                                           'new'      => 'This is not based on a previous order, but is a new '
                                                       . 'product you have not produced yet.',
                                       ) ),
                'name'       => $name,
                'email'      => array( 'type' => 'email', 'label' => 'Email address', 'required' => true,
                                       'autocomplete' => 'email',
                                       'help' => 'We work through email before and during projects — please '
                                               . 'give an address you monitor.' ),
                'phone'      => array( 'type' => 'tel', 'label' => 'Phone number', 'required' => true,
                                       'autocomplete' => 'tel',
                                       'help' => 'Used if we need to talk something through.' ),
                'callback'   => array( 'type' => 'checkbox', 'required' => false,
                                       'label' => 'Are you requesting a callback?',
                                       'box'   => 'Yes, I think I need a phone call.' ),
                'prev_order' => array( 'type' => 'textarea', 'label' => 'Your previous order', 'required' => true,
                                       'rows' => 4, 'maxlength' => 500,
                                       'help' => 'Order number, billing name or email — anything that helps us '
                                               . 'find the past project.' ),
                'message'    => array( 'type' => 'textarea', 'label' => 'Comments', 'required' => true,
                                       'rows' => 5, 'maxlength' => 500,
                                       'help' => 'Anything that has changed since last time.' ),
            ),
        ),
    );
}

// ═══════════════════════════════════════════════════════════════
// RENDER
// ═══════════════════════════════════════════════════════════════

add_shortcode( 'pps_intake', function ( $atts ) {
    $atts  = shortcode_atts( array( 'form' => 'support' ), $atts, 'pps_intake' );
    $forms = pps_intake_forms();
    $key   = isset( $forms[ $atts['form'] ] ) ? $atts['form'] : 'support';
    $form  = $forms[ $key ];

    $anchor = 'pps-intake-' . $key;
    $done   = isset( $_GET['pps_sent'] ) && sanitize_key( wp_unslash( $_GET['pps_sent'] ) ) === $key;
    $error  = isset( $_GET['pps_err'] )  ? sanitize_text_field( wp_unslash( $_GET['pps_err'] ) ) : '';

    ob_start();
    echo '<div class="pps-intake" id="' . esc_attr( $anchor ) . '">';

    if ( $done ) {
        echo '<p class="pps-intake-ok" role="status">' . esc_html( $form['confirm'] ) . '</p>';
        echo '</div>';
        return ob_get_clean();
    }

    if ( $error !== '' ) {
        echo '<p class="pps-intake-err" role="alert">' . esc_html( $error ) . '</p>';
    }

    // Posts to admin-post.php rather than fetch(). A contact form that needs JavaScript to
    // work is a contact form that silently fails for some people, and this one is reached
    // by customers who already have a problem.
    echo '<form class="pps-intake-form" method="post" enctype="multipart/form-data" action="'
       . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    echo '<input type="hidden" name="action" value="pps_intake_submit">';
    echo '<input type="hidden" name="form" value="' . esc_attr( $key ) . '">';
    echo '<input type="hidden" name="ref" value="' . esc_attr( home_url( add_query_arg( array() ) ) ) . '">';
    wp_nonce_field( 'pps_intake_' . $key, 'pps_intake_nonce' );

    // Honeypot. Named to look worth filling in to a bot and hidden from everyone else.
    echo '<div class="pps-intake-hp" aria-hidden="true">'
       . '<label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';

    foreach ( $form['fields'] as $fkey => $f ) {
        $id  = $anchor . '-' . $fkey;
        $req = ! empty( $f['required'] );

        // A conditional field renders VISIBLE and is hidden by the script below. Without
        // JavaScript it simply stays on screen, which is a slightly longer form rather than
        // a field nobody can reach.
        $cond = ! empty( $f['show_if'] ) ? ' data-show-if="' . esc_attr( $f['show_if'] ) . '"' : '';

        if ( $f['type'] === 'radio' ) {
            echo '<fieldset class="pps-intake-field pps-intake-choice"' . $cond . '>';
            echo '<legend>' . esc_html( $f['label'] );
            if ( $req ) echo ' <span class="pps-intake-req" aria-hidden="true">*</span>';
            echo '</legend>';
            foreach ( (array) $f['options'] as $ov => $ol ) {
                printf(
                    '<label><input type="radio" name="%s" value="%s"%s> %s</label>',
                    esc_attr( $fkey ), esc_attr( $ov ), $req ? ' required' : '', esc_html( $ol )
                );
            }
            if ( ! empty( $f['help'] ) ) echo '<span class="pps-intake-help">' . esc_html( $f['help'] ) . '</span>';
            echo '</fieldset>';
            continue;
        }

        if ( $f['type'] === 'checkbox' ) {
            printf(
                '<p class="pps-intake-field pps-intake-check"%s><label for="%s">'
                    . '<input id="%s" name="%s" type="checkbox" value="1"> %s</label>',
                $cond, esc_attr( $id ), esc_attr( $id ), esc_attr( $fkey ),
                esc_html( $f['box'] ?? $f['label'] )
            );
            if ( ! empty( $f['help'] ) ) echo '<span class="pps-intake-help">' . esc_html( $f['help'] ) . '</span>';
            echo '</p>';
            continue;
        }

        echo '<p class="pps-intake-field"' . $cond . '>';
        echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] );
        if ( $req ) echo ' <span class="pps-intake-req" aria-hidden="true">*</span>';
        echo '</label>';

        if ( $f['type'] === 'textarea' ) {
            printf(
                '<textarea id="%s" name="%s" rows="%d" maxlength="%d"%s></textarea>',
                esc_attr( $id ), esc_attr( $fkey ),
                (int) ( $f['rows'] ?? 5 ), (int) ( $f['maxlength'] ?? 4000 ),
                $req ? ' required' : ''
            );
        } else {
            printf(
                '<input id="%s" name="%s" type="%s"%s%s%s>',
                esc_attr( $id ), esc_attr( $fkey ), esc_attr( $f['type'] ),
                $req ? ' required' : '',
                ! empty( $f['placeholder'] ) ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '',
                ! empty( $f['autocomplete'] ) ? ' autocomplete="' . esc_attr( $f['autocomplete'] ) . '"' : ''
            );
        }

        if ( ! empty( $f['help'] ) ) echo '<span class="pps-intake-help">' . esc_html( $f['help'] ) . '</span>';
        echo '</p>';
    }

    if ( ! empty( $form['uploads'] ) ) {
        echo '<p class="pps-intake-field">'
           . '<label for="' . esc_attr( $anchor ) . '-files">Attach files</label>'
           . '<input id="' . esc_attr( $anchor ) . '-files" type="file" name="files[]" multiple '
           . 'accept="' . esc_attr( implode( ',', pps_intake_accept_list() ) ) . '">'
           . '<span class="pps-intake-help">Optional. PDF, images, or native artwork files — '
           . '20MB each, 40MB total.</span></p>';
    }

    echo '<p><button type="submit" class="pps-intake-submit">' . esc_html( $form['submit'] ) . '</button></p>';
    echo '</form>';

    // Conditional reveal. Progressive: the fields are already rendered and usable, this
    // only tidies them away until they are relevant.
    echo '<script>(function(){var r=document.getElementById(' . wp_json_encode( $anchor ) . ');'
       . 'if(!r)return;var c=r.querySelectorAll("[data-show-if]");if(!c.length)return;'
       . 'function sync(){Array.prototype.forEach.call(c,function(el){'
       . 'var src=r.querySelector(\'[name="\'+el.dataset.showIf+\'"]\');'
       . 'el.hidden=!(src&&src.checked)})}'
       . 'Array.prototype.forEach.call(r.querySelectorAll(\'input[type=checkbox]\'),function(b){'
       . 'b.addEventListener("change",sync)});sync()})();<\/script>';

    echo '</div>';

    return ob_get_clean();
} );

/** Extensions we accept. Deliberately narrower than Forminator's near-everything list. */
function pps_intake_allowed_ext() {
    return apply_filters( 'pps_intake_allowed_ext', array(
        'pdf', 'png', 'jpg', 'jpeg', 'gif', 'tif', 'tiff', 'webp',
        'ai', 'psd', 'eps', 'indd', 'svg', 'zip',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
    ) );
}

function pps_intake_accept_list() {
    $out = array();
    foreach ( pps_intake_allowed_ext() as $e ) $out[] = '.' . $e;
    return $out;
}

add_action( 'wp_enqueue_scripts', function () {
    // Tiny and unconditional — smaller than the request it would take to load conditionally.
    wp_register_style( 'pps-intake', false, array(), PPS_INTAKE_VERSION );
    wp_enqueue_style( 'pps-intake' );
    wp_add_inline_style( 'pps-intake', '
.pps-intake{max-width:640px}
.pps-intake-hp{position:absolute;left:-9999px;height:0;overflow:hidden}
.pps-intake-field{display:flex;flex-direction:column;gap:5px;margin:0 0 18px}
.pps-intake-field label{font-weight:600;font-size:14.5px}
.pps-intake-req{color:#dc2626}
.pps-intake-field input,.pps-intake-field textarea{width:100%;padding:10px 12px;border:1px solid #ccd0d4;
  border-radius:8px;font:inherit;font-size:15px;background:#fff}
.pps-intake-field input:focus,.pps-intake-field textarea:focus{outline:0;border-color:#007eff;
  box-shadow:0 0 0 3px rgba(0,126,255,.12)}
.pps-intake-help{font-size:13px;color:#64748b;line-height:1.4}
.pps-intake-check label{display:flex;align-items:flex-start;gap:9px;font-weight:500;font-size:14.5px}
.pps-intake-check input{width:auto;margin-top:2px}
.pps-intake-choice{border:0;padding:0;margin:0 0 18px}
.pps-intake-choice legend{font-weight:600;font-size:14.5px;padding:0;margin-bottom:7px}
.pps-intake-choice label{display:flex;align-items:flex-start;gap:9px;font-size:14.5px;margin-bottom:6px}
.pps-intake-choice input{width:auto;margin-top:3px}
/* An ID or class rule that sets display would beat [hidden]; this one restates it. */
.pps-intake-field[hidden]{display:none}
.pps-intake-submit{background:#007eff;color:#fff;border:0;border-radius:9px;padding:12px 24px;
  font:600 15px/1 system-ui,sans-serif;cursor:pointer}
.pps-intake-ok{padding:14px 16px;border-radius:9px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.pps-intake-err{padding:14px 16px;border-radius:9px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
' );
} );

// ═══════════════════════════════════════════════════════════════
// SUBMIT
// ═══════════════════════════════════════════════════════════════

function pps_intake_rate_key() {
    $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'pps';
    return 'pps_intake_' . substr( hash( 'sha256', $ip . $salt ), 0, 24 );
}

/** Send the visitor back where they came from, with a flag the shortcode reads. */
function pps_intake_bounce( $key, $args ) {
    $ref = isset( $_POST['ref'] ) ? esc_url_raw( wp_unslash( $_POST['ref'] ) ) : '';
    // Never redirect off-site on the strength of a POST field.
    $host = $ref ? wp_parse_url( $ref, PHP_URL_HOST ) : '';
    if ( ! $ref || strcasecmp( (string) $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) !== 0 ) {
        $ref = home_url( '/' );
    }
    $ref = remove_query_arg( array( 'pps_sent', 'pps_err' ), $ref );
    wp_safe_redirect( add_query_arg( $args, $ref ) . '#pps-intake-' . $key );
    exit;
}

add_action( 'admin_post_nopriv_pps_intake_submit', 'pps_intake_handle_submit' );
add_action( 'admin_post_pps_intake_submit',        'pps_intake_handle_submit' );

function pps_intake_handle_submit() {
    $forms = pps_intake_forms();
    $key   = sanitize_key( wp_unslash( $_POST['form'] ?? '' ) );
    if ( ! isset( $forms[ $key ] ) ) wp_die( 'Unknown form.' );
    $form = $forms[ $key ];

    if ( ! isset( $_POST['pps_intake_nonce'] )
      || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pps_intake_nonce'] ) ), 'pps_intake_' . $key ) ) {
        pps_intake_bounce( $key, array( 'pps_err' => 'That form expired — please try again.' ) );
    }

    // Honeypot: answer as though it worked. Telling a bot it failed teaches it to retry.
    if ( ! empty( $_POST['website'] ) ) {
        pps_intake_bounce( $key, array( 'pps_sent' => $key ) );
    }

    $rkey = pps_intake_rate_key();
    if ( (int) get_transient( $rkey ) >= 5 ) {
        pps_intake_bounce( $key, array( 'pps_err' => 'Too many submissions from your connection. Please try again in a few minutes.' ) );
    }
    set_transient( $rkey, (int) get_transient( $rkey ) + 1, 15 * MINUTE_IN_SECONDS );

    // ── collect + validate ──
    $values = array();
    foreach ( $form['fields'] as $fkey => $f ) {
        if ( $f['type'] === 'checkbox' ) {
            $values[ $fkey ] = empty( $_POST[ $fkey ] ) ? '' : '1';
            continue;
        }
        if ( $f['type'] === 'radio' ) {
            // Only ever store one of the options we rendered — a posted value is untrusted.
            $picked = sanitize_key( wp_unslash( $_POST[ $fkey ] ?? '' ) );
            $values[ $fkey ] = isset( $f['options'][ $picked ] ) ? (string) $f['options'][ $picked ] : '';
            if ( ! empty( $f['required'] ) && $values[ $fkey ] === '' ) {
                pps_intake_bounce( $key, array( 'pps_err' => 'Please answer: ' . $f['label'] ) );
            }
            continue;
        }
        $raw = wp_unslash( $_POST[ $fkey ] ?? '' );
        $val = $f['type'] === 'textarea' ? sanitize_textarea_field( $raw )
             : ( $f['type'] === 'email'  ? sanitize_email( $raw ) : sanitize_text_field( $raw ) );
        $val = trim( (string) $val );

        if ( ! empty( $f['required'] ) && $val === '' ) {
            pps_intake_bounce( $key, array( 'pps_err' => $f['label'] . ' is required.' ) );
        }
        if ( $f['type'] === 'email' && $val !== '' && ! is_email( $val ) ) {
            pps_intake_bounce( $key, array( 'pps_err' => 'That email address does not look right.' ) );
        }
        $max = (int) ( $f['maxlength'] ?? 200 );
        if ( strlen( $val ) > $max ) $val = substr( $val, 0, $max );

        $values[ $fkey ] = $val;
    }

    $uploads = ! empty( $form['uploads'] ) ? pps_intake_take_uploads() : array( 'urls' => array(), 'error' => '' );
    if ( $uploads['error'] !== '' ) {
        pps_intake_bounce( $key, array( 'pps_err' => $uploads['error'] ) );
    }

    $post_id = pps_intake_record( $key, $form, $values, $uploads['urls'] );
    pps_intake_notify( $key, $form, $values, $uploads['urls'], $post_id );

    pps_intake_bounce( $key, array( 'pps_sent' => $key ) );
}

/**
 * Move uploads somewhere durable and return their URLs.
 *
 * Files land in a token directory with directory listing off, so a URL is unguessable but
 * shareable with whoever is doing the work. Not attached to the notification email:
 * artwork is routinely larger than a mail server will accept, and a bounced notification
 * is worse than a link.
 */
function pps_intake_take_uploads() {
    $out = array( 'urls' => array(), 'error' => '' );
    if ( empty( $_FILES['files'] ) || ! is_array( $_FILES['files']['name'] ?? null ) ) return $out;

    $allowed = pps_intake_allowed_ext();
    $total   = 0;
    $valid   = array();

    foreach ( array_keys( $_FILES['files']['name'] ) as $i ) {
        if ( (int) $_FILES['files']['error'][ $i ] === UPLOAD_ERR_NO_FILE ) continue;
        if ( (int) $_FILES['files']['error'][ $i ] !== UPLOAD_ERR_OK ) {
            $out['error'] = 'One of those files did not upload cleanly. Please try again.';
            return $out;
        }
        $orig = sanitize_file_name( (string) $_FILES['files']['name'][ $i ] );
        $ext  = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed, true ) ) {
            $out['error'] = sprintf( 'We cannot accept .%s files. Try a PDF or an image.', $ext ?: '?' );
            return $out;
        }
        if ( (int) $_FILES['files']['size'][ $i ] > PPS_INTAKE_MAX_FILE ) {
            $out['error'] = sprintf( '%s is over the 20MB per-file limit.', $orig );
            return $out;
        }
        $total += (int) $_FILES['files']['size'][ $i ];
        $valid[] = $i;
    }

    if ( ! $valid ) return $out;
    if ( $total > PPS_INTAKE_MAX_TOTAL ) {
        $out['error'] = 'Those files come to more than 40MB in total. Send the largest separately.';
        return $out;
    }

    $token = wp_generate_password( 20, false, false );
    $up    = wp_upload_dir();
    $dir   = $up['basedir'] . '/pps-intake/' . $token;
    wp_mkdir_p( $dir );
    if ( ! file_exists( $dir . '/.htaccess' ) ) {
        file_put_contents( $dir . '/.htaccess', "Options -Indexes\n" );
    }

    foreach ( $valid as $i ) {
        $orig = sanitize_file_name( (string) $_FILES['files']['name'][ $i ] );
        if ( move_uploaded_file( $_FILES['files']['tmp_name'][ $i ], $dir . '/' . $orig ) ) {
            $out['urls'][] = $up['baseurl'] . '/pps-intake/' . $token . '/' . rawurlencode( $orig );
        }
    }
    return $out;
}

/**
 * Write the Calc Questions record.
 *
 * Same CPT and the same _pps_q_* keys the calculator question form and the category wizard
 * already use, so everything lands in one admin list. _pps_q_source is the addition: until
 * now the only way to tell submissions apart was a "Lead: " / "Wizard: " string prefix on
 * the calculator label, which is not something to build filtering on.
 */
function pps_intake_record( $key, array $form, array $values, array $file_urls ) {
    $title = sprintf( '%s — %s', $values['name'] ?? 'Website visitor', $form['title'] );

    $post_id = wp_insert_post( array(
        'post_type'    => 'pps_question',
        'post_status'  => 'publish',
        'post_title'   => wp_strip_all_tags( $title ),
        'post_content' => (string) ( $values['message'] ?? '' ),
    ), true );

    if ( is_wp_error( $post_id ) || ! $post_id ) return 0;

    update_post_meta( $post_id, '_pps_q_source',     $form['source'] );
    update_post_meta( $post_id, '_pps_q_name',       (string) ( $values['name'] ?? '' ) );
    update_post_meta( $post_id, '_pps_q_email',      (string) ( $values['email'] ?? '' ) );
    update_post_meta( $post_id, '_pps_q_phone',      (string) ( $values['phone'] ?? '' ) );
    update_post_meta( $post_id, '_pps_q_company',    (string) ( $values['company'] ?? '' ) );
    // Reuses the label column so these rows are not blank in the admin list.
    update_post_meta( $post_id, '_pps_q_calc_label', $form['title'] );
    update_post_meta( $post_id, '_pps_q_user_ip',    isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '' );
    // Reuses the key the Calc Questions meta box already renders as a 'Callback' row,
    // so a callback request from a form looks identical to one from the wizard.
    if ( ! empty( $values['callback'] ) )  update_post_meta( $post_id, '_pps_q_callback', 1 );
    if ( ! empty( $values['order_ref'] ) ) update_post_meta( $post_id, '_pps_q_order_ref', $values['order_ref'] );
    if ( $file_urls )                      update_post_meta( $post_id, '_pps_q_files', $file_urls );

    return (int) $post_id;
}

/** Staff notification + customer confirmation. */
function pps_intake_notify( $key, array $form, array $values, array $file_urls, $post_id ) {
    $to   = pps_intake_recipient();
    $name = (string) ( $values['name'] ?? 'Website visitor' );
    $mail = (string) ( $values['email'] ?? '' );

    $lines = array( $form['title'], '' );
    if ( ! empty( $values['callback'] ) ) {
        $lines[] = '*** CALLBACK REQUESTED ***';
        $lines[] = '';
    }
    foreach ( $form['fields'] as $fkey => $f ) {
        $v = trim( (string) ( $values[ $fkey ] ?? '' ) );
        if ( $v === '' ) continue;                       // unticked boxes say nothing
        if ( $f['type'] === 'checkbox' ) { $lines[] = $f['label'] . ' Yes'; continue; }
        $lines[] = $f['label'] . ': ' . ( $f['type'] === 'textarea' ? "\n" . $v : $v );
    }
    if ( $file_urls ) {
        $lines[] = '';
        $lines[] = 'Files:';
        foreach ( $file_urls as $u ) $lines[] = '  ' . $u;
    }
    if ( $post_id ) {
        $lines[] = '';
        $lines[] = 'Record: ' . admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
    }
    $lines[] = '';
    $lines[] = '— Submitted ' . current_time( 'M j, Y g:i a T' );

    // Reply-To the customer, so answering in Missive replies to them and the thread works
    // the way every other email in that inbox does.
    $sent = wp_mail(
        $to,
        sprintf( '[PPS] %s — %s', $form['subject'], $name ),
        implode( "\n", $lines ),
        array(
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . sprintf( '%s <%s>', $name, $mail ),
        )
    );
    if ( $post_id ) update_post_meta( $post_id, '_pps_q_email_sent', $sent ? 1 : 0 );
    if ( ! $sent )  error_log( '[pps-intake] staff notification FAILED to ' . $to );

    if ( ! is_email( $mail ) ) return;

    $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $c    = array( sprintf( 'Hi %s,', $name ), '', $form['confirm'], '' );
    if ( ! empty( $values['message'] ) ) {
        $c[] = 'What you sent us:';
        $c[] = '';
        $c[] = $values['message'];
        $c[] = '';
    }
    $c[] = sprintf( '— The %s team', $site );

    wp_mail(
        $mail,
        sprintf( 'We got your %s — %s', strtolower( $form['subject'] ), $site ),
        implode( "\n", $c ),
        array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $to )
    );
}

// ═══════════════════════════════════════════════════════════════
// ADMIN — make the source visible in the Calc Questions list
// ═══════════════════════════════════════════════════════════════

add_filter( 'manage_pps_question_posts_columns', function ( $cols ) {
    $out = array();
    foreach ( $cols as $k => $v ) {
        $out[ $k ] = $v;
        if ( $k === 'title' ) $out['pps_source'] = 'Source';
    }
    return $out;
}, 20 );

add_action( 'manage_pps_question_posts_custom_column', function ( $col, $post_id ) {
    if ( $col !== 'pps_source' ) return;
    $src = get_post_meta( $post_id, '_pps_q_source', true );
    if ( ! $src ) {
        // Rows written before _pps_q_source existed. Inferred, not guessed at silently.
        $label = (string) get_post_meta( $post_id, '_pps_q_calc_label', true );
        if ( strpos( $label, 'Lead: ' ) === 0 )        $src = 'lead';
        elseif ( strpos( $label, 'Wizard: ' ) === 0 )  $src = 'wizard';
        elseif ( get_post_meta( $post_id, '_pps_asst_order', true ) !== '' ) $src = 'assistant';
        else $src = $label !== '' ? 'calculator' : '—';
    }
    echo esc_html( $src );
}, 20, 2 );
