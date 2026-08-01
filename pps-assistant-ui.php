<?php
/**
 * PPS Assistant — presentation layer (front-end widget + admin screen).
 *
 * Required by pps-assistant.php. Not a plugin in its own right: no plugin header, and it
 * does nothing unless the engine has already defined PPS_ASSISTANT_VERSION.
 *
 * Split out because deploys to this site are whole-file writes over MCP — keeping the UI
 * separate means a CSS tweak does not re-transmit the tool handlers and the agent loop.
 *
 * The visual source of truth is assistant-widget-preview.html on the Pages branch; keep
 * the two in sync the same way the CC/ST blocks are synced across the calculators.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! defined( 'PPS_ASSISTANT_VERSION' ) ) return;   // engine not loaded — nothing to render

// ═══════════════════════════════════════════════════════════════
// WIDGET (front-end) — vanilla JS, no React; this loads on every page.
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
        'poll'     => rest_url( 'pps/v1/assistant/poll' ),
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
      var sid = sessionStorage.getItem('ppsAsstSid');
      if (!sid) { sid = 'S' + Math.random().toString(36).slice(2) + Date.now().toString(36);
                  sessionStorage.setItem('ppsAsstSid', sid); }

      var root = document.getElementById('pps-asst-root');
      root.innerHTML =
        '<button id="pps-asst-launch" aria-label="Open chat">Chat' +
          (CFG.admin ? '<span class="pps-asst-flag">admin only</span>' : '') + '</button>' +
        '<div id="pps-asst-panel" hidden>' +
          '<header><span>Priority Print Service</span><button id="pps-asst-close" aria-label="Close">&times;</button></header>' +
          '<div id="pps-asst-banner" role="status" hidden></div>' +
          '<div id="pps-asst-log" role="log" aria-live="polite"></div>' +
          '<form id="pps-asst-gate">' +
            '<p class="pps-asst-gate-intro">A few details so we can follow up if we miss you.</p>' +
            '<input id="pps-asst-name" type="text" required autocomplete="name" placeholder="Full name">' +
            '<input id="pps-asst-company" type="text" autocomplete="organization" placeholder="Company (optional)">' +
            '<input id="pps-asst-email" type="email" required autocomplete="email" placeholder="Email">' +
            '<input id="pps-asst-phone" type="tel" required autocomplete="tel" placeholder="Phone">' +
            '<p class="pps-asst-gate-err" hidden></p>' +
            '<button type="submit">Start chat</button>' +
          '</form>' +
          '<form id="pps-asst-form" hidden>' +
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

      // ── live handoff ────────────────────────────────────────────────────────────
      // While a human owns the conversation the bot is silent server-side, so the only
      // way an agent's words reach this window is by asking for them.
      var cursor = 0, pollTimer = null, withHuman = false, firstPoll = true, graceLeft = 0;

      function setBanner(text) {
        var b = document.getElementById('pps-asst-banner');
        if (!text) { b.hidden = true; return; }
        b.textContent = text;
        b.hidden = false;
      }

      function renderHuman(m, showOwn) {
        // The visitor's own relayed lines are already on screen from when they were typed,
        // so re-rendering them mid-session would duplicate. On the FIRST poll after a page
        // load the window is empty, and skipping them would show the agent's answers with
        // none of the questions — so there, render them.
        if (m.kind === 'visitor' && !showOwn) return;
        var el = add(m.kind === 'visitor' ? 'user' : (m.kind === 'system' ? 'bot' : 'human'), m.text);
        if (m.kind === 'agent' && m.from) {
          var tag = document.createElement('span');
          tag.className = 'pps-asst-who';
          tag.textContent = m.from;
          el.prepend(tag);
        }
      }

      function pollOnce() {
        return fetch(CFG.poll + '?session=' + encodeURIComponent(sid) + '&cursor=' + cursor, {
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': CFG.nonce }
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d) return;
          if (typeof d.cursor === 'number') cursor = d.cursor;
          var own = firstPoll;
          firstPoll = false;
          (d.messages || []).forEach(function (m) { renderHuman(m, own); });

          var nowHuman = d.mode === 'human';
          if (nowHuman && !withHuman) setBanner('Connecting you to the team…');
          // Falling back out of human mode means nobody picked up; the server has already
          // said so in the log, so the banner just needs to stop claiming otherwise.
          if (!nowHuman && withHuman) setBanner('');
          withHuman = nowHuman;
          // An agent has actually spoken — stop saying "connecting".
          if (nowHuman && (d.messages || []).some(function (m) { return m.kind === 'agent'; })) {
            setBanner('You are chatting with our team.');
          }

          // Do NOT stop dead when the server hands the chat back to the bot. Someone who
          // was busy may still reply a minute later, and a stopped poller would drop that
          // message on the floor. Back off and keep listening for a bounded while.
          if (nowHuman) { graceLeft = 40; retime(4000); }
          else if (graceLeft > 0) { graceLeft--; retime(15000); }
          else stopPolling();
        })
        .catch(function () { /* a dropped poll is recoverable; the cursor did not move */ });
      }

      var pollEvery = 0;
      function retime(ms) {
        if (pollTimer && pollEvery === ms) return;   // already at this cadence
        if (pollTimer) clearInterval(pollTimer);
        pollEvery = ms;
        pollTimer = setInterval(pollOnce, ms);
      }
      function startPolling() {
        graceLeft = 40;
        if (!pollTimer) retime(4000);
        pollOnce();
      }
      function stopPolling() {
        if (!pollTimer) return;
        clearInterval(pollTimer); pollTimer = null; pollEvery = 0;
      }

      // Intake gate. Held in sessionStorage so a page navigation doesn't re-ask; the
      // server re-validates every field, this is only UI state.
      var intake = {};
      try { intake = JSON.parse(sessionStorage.getItem('ppsAsstIntake') || '{}'); } catch (e) { intake = {}; }
      var haveIntake = !!(intake.name && intake.email && intake.phone);

      function showChat() {
        document.getElementById('pps-asst-gate').hidden = true;
        document.getElementById('pps-asst-form').hidden = false;
        if (!log.childElementCount) add('bot', CFG.greeting);
        document.getElementById('pps-asst-input').focus();
        // A reload mid-handoff lands here with an empty window and a live agent waiting
        // on the other side. One poll rebuilds the human side from the server's log.
        pollOnce().then(function () { if (withHuman) startPolling(); });
      }

      document.getElementById('pps-asst-launch').onclick = function () {
        document.getElementById('pps-asst-panel').hidden = false;
        if (haveIntake) showChat();
        else document.getElementById('pps-asst-name').focus();
      };

      document.getElementById('pps-asst-gate').onsubmit = function (e) {
        e.preventDefault();
        var err = document.getElementById('pps-asst-gate').querySelector('.pps-asst-gate-err');
        var v = {
          name:    document.getElementById('pps-asst-name').value.trim(),
          company: document.getElementById('pps-asst-company').value.trim(),
          email:   document.getElementById('pps-asst-email').value.trim(),
          phone:   document.getElementById('pps-asst-phone').value.trim()
        };

        // Say which field is wrong rather than just refusing. A gate that silently does
        // nothing is the fastest way to lose someone who wanted to talk to you.
        var problem = '';
        if (!v.name) problem = 'Please add your name.';
        else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v.email)) problem = 'That email does not look right.';
        else if (v.phone.replace(/\D/g, '').length < 7) problem = 'That phone number looks too short.';

        if (problem) { err.textContent = problem; err.hidden = false; return; }
        err.hidden = true;

        intake = v;
        haveIntake = true;
        sessionStorage.setItem('ppsAsstIntake', JSON.stringify(intake));
        showChat();
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
          body: JSON.stringify({
            message: text, session: sid, nonce: CFG.nonce,
            name: intake.name || '', company: intake.company || '',
            email: intake.email || '', phone: intake.phone || ''
          })
        })
        .then(function (r) {
          return r.text().then(function (body) {
            if (!r.ok) throw new Error('HTTP ' + r.status + ' — ' + body.slice(0, 300));
            return JSON.parse(body);
          });
        })
        .then(function (d) {
          // A handed-off turn has no bot reply — the agent's words arrive via polling, so
          // drop the placeholder rather than rendering an empty bubble.
          if (d.with_human) { pending.remove(); }
          // Any OTHER empty reply is unexpected. Silently removing the bubble makes it
          // look like the message was never sent, which is the one impression to avoid.
          else if (!d.reply) {
            pending.className = 'pps-asst-msg pps-asst-bot';
            pending.textContent = 'Sorry — nothing came back that time. Try sending that again?';
          }
          else { pending.className = 'pps-asst-msg pps-asst-bot'; pending.textContent = d.reply; }
          if (d.with_human) startPolling();
          // The bot asks for a handoff by flipping mode server-side; the next poll
          // reports it. Poll once immediately so "connecting you" appears without delay.
          else if (d.escalated) pollOnce();
        })
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
      /* A live agent must not look like the bot — same bubble side, different identity. */
      .pps-asst-human{background:#ecfdf5;border:1px solid #a7f3d0;color:#064e3b;align-self:flex-start}
      .pps-asst-who{display:block;font-size:11.5px;font-weight:700;color:#047857;margin-bottom:3px;
        letter-spacing:.02em}
      #pps-asst-banner{padding:8px 14px;background:#ecfdf5;border-bottom:1px solid #a7f3d0;
        color:#065f46;font-size:12.5px;font-weight:600}
      #pps-asst-banner[hidden]{display:none}
      #pps-asst-gate{display:flex;flex-direction:column;gap:7px;padding:14px;border-top:1px solid #e3e8ef;background:#fff;
        max-height:100%;overflow-y:auto}
      #pps-asst-gate[hidden]{display:none}
      .pps-asst-gate-intro{margin:0;font-size:12.5px;color:#475569;line-height:1.4}
      .pps-asst-gate-err{margin:0;font-size:12.5px;color:#dc2626;line-height:1.4}
      .pps-asst-gate-err[hidden]{display:none}
      #pps-asst-gate input{border:1px solid #e3e8ef;border-radius:9px;padding:10px 12px;font:inherit;font-size:14px}
      #pps-asst-gate input:focus{outline:0;border-color:#007eff;box-shadow:0 0 0 3px rgba(0,126,255,.12)}
      #pps-asst-gate button{background:#007eff;color:#fff;border:0;border-radius:9px;padding:10px 16px;
        font:600 14px/1 system-ui,sans-serif;cursor:pointer}
      #pps-asst-form[hidden]{display:none}
      #pps-asst-form{display:flex;gap:8px;padding:10px;border-top:1px solid #e3e8ef;background:#fff}
      #pps-asst-input{flex:1;border:1px solid #e3e8ef;border-radius:9px;padding:9px 11px;font:inherit;background:#fff}
      #pps-asst-input:focus{outline:0;border-color:#007eff;box-shadow:0 0 0 3px rgba(0,126,255,.12)}
      #pps-asst-form button{background:#007eff;color:#fff;border:0;border-radius:9px;padding:9px 16px;
        font:600 14px/1 system-ui,sans-serif;cursor:pointer}
    </style>
    <?php
}, 99 );

// ═══════════════════════════════════════════════════════════════
// ADMIN
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
    add_submenu_page(
        'pps-calculators',                 // verified against pps-calculators.php add_menu_page()
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

        // Stamp when availability was switched on, so the settings screen can show how long
        // it has been on. A toggle left on overnight is the main failure mode of this design.
        // Same helper the bookmarkable URL uses — one rule, two front doors.
        $now_available = ! empty( $_POST['available_now'] );
        $cfg['available_since'] = pps_assistant_availability_stamp(
            ! empty( $cfg['available_now'] ), $cfg['available_since'], $now_available
        );
        $cfg['available_now']   = $now_available;
        $cfg['require_email']   = ! empty( $_POST['require_email'] );
        $cfg['api_key']   = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        $cfg['model']     = sanitize_text_field( wp_unslash( $_POST['model'] ?? 'claude-opus-5' ) );
        $cfg['effort']    = sanitize_key( wp_unslash( $_POST['effort'] ?? 'medium' ) );
        $cfg['daily_cap'] = max( 0, (int) ( $_POST['daily_cap'] ?? 300 ) );

        // Stage 2 — Missive custom channel. Credentials are stored, never echoed back into
        // a readable field (same treatment as the API key).
        $cfg['missive_channel_id'] = sanitize_text_field( wp_unslash( $_POST['missive_channel_id'] ?? '' ) );
        $cfg['missive_token']      = sanitize_text_field( wp_unslash( $_POST['missive_token'] ?? '' ) );
        $cfg['missive_channel_type'] = ( ( $_POST['missive_channel_type'] ?? '' ) === 'email' ) ? 'email' : 'text';
        $cfg['missive_alias']      = sanitize_text_field( wp_unslash( $_POST['missive_alias'] ?? '' ) );
        $cfg['missive_alias_name'] = sanitize_text_field( wp_unslash( $_POST['missive_alias_name'] ?? '' ) );
        $cfg['missive_show_agent_name'] = ! empty( $_POST['missive_show_agent_name'] );
        $cfg['handoff_timeout']    = max( 30, (int) ( $_POST['handoff_timeout'] ?? 180 ) );
        $cfg['customer_transcript'] = ! empty( $_POST['customer_transcript'] );

        // The webhook secret is ours to mint, not the operator's to invent — a weak one here
        // means anyone who finds the route can inject messages into a customer's chat.
        if ( empty( $cfg['missive_webhook_secret'] ) ) {
            $cfg['missive_webhook_secret'] = wp_generate_password( 48, false, false );
        }
        if ( ! empty( $_POST['pps_regen_secret'] ) ) {
            $cfg['missive_webhook_secret'] = wp_generate_password( 48, false, false );
        }
        if ( ! empty( $_POST['pps_regen_avail'] ) ) {
            $cfg['avail_secret'] = wp_generate_password( 32, false, false );
        }
        // NOT sanitize_textarea_field(): it strips angle-bracket tags, which is exactly how a
        // structured Claude system prompt is written, and an unmatched '<' ("runs < 500") makes
        // it esc_html() the entire remainder of the field. This value goes into a JSON API
        // body; it is escaped at render time by esc_textarea() below.
        $policy           = wp_unslash( $_POST['policy'] ?? '' );
        $policy           = wp_check_invalid_utf8( $policy, true );
        $policy           = str_replace( array( "\r\n", "\r" ), "\n", $policy );
        $cfg['policy']    = function_exists( 'mb_substr' ) ? mb_substr( $policy, 0, 20000 ) : substr( $policy, 0, 20000 );

        update_option( 'pps_assistant_config', $cfg );
        delete_transient( 'pps_assistant_catalog' );
        echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }

    $cfg   = pps_assistant_config();
    // Must read through the same accessor the counter writes through — under Object Cache
    // Pro the value lives in the object cache, not a transient, so a direct get_transient()
    // here would display 0 forever.
    $today = pps_assistant_counter_get( pps_assistant_budget_key() );
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
                <tr><th>Someone is available</th><td>
                    <label><input type="checkbox" name="available_now" <?php checked( $cfg['available_now'] ); ?>>
                        A human can take a live handoff right now</label>
                    <?php if ( ! empty( $cfg['available_now'] ) && ! empty( $cfg['available_since'] ) ) :
                        $mins = max( 0, (int) floor( ( time() - (int) $cfg['available_since'] ) / 60 ) );
                        $warn = $mins > 600; // ~10h — almost certainly left on overnight ?>
                        <p class="description" <?php echo $warn ? 'style="color:#dc2626;font-weight:600"' : ''; ?>>
                            On for <?php echo esc_html( $mins < 60 ? $mins . ' min' : floor( $mins / 60 ) . 'h ' . ( $mins % 60 ) . 'm' ); ?>.
                            <?php echo $warn ? 'That looks like it was left on — customers are being told a human is here.' : ''; ?>
                        </p>
                    <?php endif; ?>
                    <p class="description">Off: escalations tell the customer the team will follow up by
                    email, and the bot offers a text or call. On: the customer is told someone is picking
                    it up now — so only leave it on when that is true.</p>
                    <p class="description">The escalation email is sent either way. What this changes is
                    whether the chat is also handed into Missive for a live reply — and it only claims that
                    if the handoff genuinely posted. With the bridge below unconfigured, ticking this just
                    gets a politer version of the email answer.</p>
                </td></tr>
                <tr><th>Toggle from your phone</th><td>
                    <?php $avail_base = add_query_arg( 'k', rawurlencode( pps_assistant_avail_secret() ), rest_url( 'pps/v1/assistant/availability' ) ); ?>
                    <p style="margin:0 0 6px"><strong>Bookmark this one</strong> — it shows the current
                    state and has both buttons on it:</p>
                    <input type="text" readonly class="large-text code" onclick="this.select()"
                        value="<?php echo esc_attr( $avail_base ); ?>">
                    <p class="description" style="margin-top:8px">For two separate one-tap bookmarks, append
                    <code>&amp;set=on</code> or <code>&amp;set=off</code> to that URL.</p>
                    <p class="description">The key in the query string is the whole of the authentication,
                    so treat the URL as a credential — anyone holding it can flip this switch. Nothing else
                    is reachable through it and no customer data is exposed. It also works while logged into
                    wp-admin without the key.
                        <label style="display:block;margin-top:6px">
                            <input type="checkbox" name="pps_regen_avail" value="1"> Generate a new key on save
                            (invalidates any bookmark you already made)
                        </label>
                    </p>
                </td></tr>
                <tr><th>Require intake</th><td>
                    <label><input type="checkbox" name="require_email" <?php checked( $cfg['require_email'] ); ?>>
                        Collect name, email and phone before the chat starts</label>
                    <p class="description">Company is always optional. All of it is self-reported, so it
                    never unlocks order data — it exists so there is always somewhere to follow up.
                    Requiring a phone number is the highest-friction field on the form; untick this if
                    drop-off is worse than the missed contact details.</p>
                </td></tr>
                <tr><th>Send customers a transcript</th><td>
                    <label><input type="checkbox" name="customer_transcript" <?php checked( $cfg['customer_transcript'] ); ?>>
                        Email the customer a copy of their chat when it escalates</label>
                    <p class="description">Their side only — what they typed and what they were told.
                    None of the internal triage (reason, summary, routing) goes anywhere near it.
                    Off by default, because a receipt for a two-line conversation is noise.</p>
                </td></tr>
                <tr><th>Visible to</th><td>
                    <label><input type="radio" name="visible_to" value="admins" <?php checked( $cfg['visible_to'], 'admins' ); ?>>
                        <strong>Logged-in admins only</strong> — widget and chat endpoint both closed to customers</label><br>
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
                <tr><th colspan="2"><hr><h2 style="margin:0">Missive live handoff <span style="font-weight:400;color:#666">(check the connection below after saving)</span></h2></th></tr>
                <tr><th>Channel ID</th><td>
                    <input type="text" name="missive_channel_id" value="<?php echo esc_attr( $cfg['missive_channel_id'] ); ?>" class="regular-text">
                    <p class="description">Missive → Settings → Accounts → Add account → Custom.</p>
                </td></tr>
                <tr><th>API token</th><td>
                    <input type="password" name="missive_token" value="<?php echo esc_attr( $cfg['missive_token'] ); ?>" class="regular-text" autocomplete="off">
                    <p class="description">Missive → Settings → API.</p>
                </td></tr>
                <tr><th>Channel type</th><td>
                    <label><input type="radio" name="missive_channel_type" value="text" <?php checked( $cfg['missive_channel_type'], 'text' ); ?>>
                        <strong>Text</strong> — a chat-style custom channel</label><br>
                    <label><input type="radio" name="missive_channel_type" value="email" <?php checked( $cfg['missive_channel_type'], 'email' ); ?>>
                        Email — the channel carries subject lines</label>
                    <p class="description">Missive validates this. A subject line on a text channel is
                    rejected outright (<code>'subject' is not allowed for 'text' messages</code>), and an
                    HTML body reaches the agent as raw tags. Leave this on Text unless a send fails saying
                    otherwise.</p>
                </td></tr>
                <tr><th>Alias username</th><td>
                    <input type="text" name="missive_alias" value="<?php echo esc_attr( $cfg['missive_alias'] ); ?>" class="regular-text" placeholder="website-chat">
                    <input type="text" name="missive_alias_name" value="<?php echo esc_attr( $cfg['missive_alias_name'] ); ?>" class="regular-text" placeholder="Website chat" style="margin-top:4px">
                    <p class="description">Username first, then the display name — both must match an
                    alias you defined on the custom channel in Missive. This is the address the visitor's
                    message is addressed <em>to</em>, and the identity your team replies <em>as</em>.</p>
                </td></tr>
                <tr><th>Name on replies</th><td>
                    <label><input type="checkbox" name="missive_show_agent_name" <?php checked( $cfg['missive_show_agent_name'] ); ?>>
                        Show the name of whoever replied</label>
                    <p class="description">Off (default) the customer sees your alias —
                    <strong><?php echo esc_html( $cfg['missive_alias_name'] ?: ( $cfg['missive_alias'] ?: 'your alias' ) ); ?></strong>
                    — which is the point of having a shared one. On, they see the individual
                    team member's name as Missive reports it. Either way the bridge log records
                    who actually replied, so you never lose attribution internally.</p>
                </td></tr>
                <tr><th>Wait before giving up</th><td>
                    <input type="number" name="handoff_timeout" value="<?php echo (int) $cfg['handoff_timeout']; ?>" min="30" step="10"> seconds
                    <p class="description">How long the visitor sees “Connecting you to the team…” before
                    the bot takes the conversation back and points them at the email follow-up. The
                    escalation email is already sent by then, so nothing is lost — this only controls how
                    long an unanswered chat keeps promising a live reply.</p>
                </td></tr>
                <tr><th>Webhook URL</th><td>
                    <input type="text" readonly class="large-text code" onclick="this.select()"
                        value="<?php echo esc_attr( add_query_arg( 'k', rawurlencode( $cfg['missive_webhook_secret'] ), rest_url( 'pps/v1/assistant/missive-webhook' ) ) ); ?>">
                    <p class="description">
                        Paste into Missive's <em>Outgoing webhook · URL</em> field. The secret in the
                        query string is how the endpoint tells a real Missive delivery from a forged
                        one, so treat this whole URL as a credential.
                        <label style="display:block;margin-top:6px">
                            <input type="checkbox" name="pps_regen_secret" value="1"> Generate a new secret on save
                            (invalidates the URL already in Missive)
                        </label>
                    </p>
                    <p class="description">Requires the <strong>PPS Assistant — Missive Webhook</strong>
                    plugin to be active. Deliveries are routed into the visitor's chat and recorded
                    below either way.</p>
                </td></tr>
                <tr><th colspan="2"><hr></th></tr>
                <tr><th>Policy prompt</th><td>
                    <textarea name="policy" rows="16" class="large-text code" placeholder="Leave blank to use the built-in default"><?php echo esc_textarea( $cfg['policy'] ); ?></textarea>
                    <p class="description">Editing this changes the cached prefix — the next request pays a fresh cache write.</p>
                </td></tr>
            </table>

            <?php submit_button( 'Save settings' ); ?>
        </form>

        <?php
        // Outside the settings form on purpose: this posts to admin-post.php, and nesting
        // it would make the Save button submit a test send instead.
        if ( function_exists( 'pps_assistant_missive_ready' ) ) :
            $ready = pps_assistant_missive_ready();
            $test  = isset( $_GET['pps_test'] ) ? sanitize_key( wp_unslash( $_GET['pps_test'] ) ) : '';
            ?>
            <h2>Check the connection</h2>
            <?php if ( $test === 'ok' ) : ?>
                <div class="notice notice-success inline"><p>Message accepted by Missive. Look for
                <em>PPS Assistant — connection test</em> in your inbox, then reply to it — the reply
                should appear in the log below within a few seconds.</p></div>
            <?php elseif ( $test === 'fail' ) : ?>
                <div class="notice notice-error inline"><p>Missive rejected it. The exact request and
                response are in the log below — that response names what needs fixing.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="pps_assistant_missive_test">
                <?php wp_nonce_field( 'pps_assistant_missive_test' ); ?>
                <p><button type="submit" class="button" <?php disabled( ! $ready ); ?>>Send a test message</button>
                <?php if ( ! $ready ) : ?>
                    <span class="description">Needs a channel ID and an API token first.</span>
                <?php endif; ?>
                </p>
                <p class="description" style="max-width:46em">Missive blocks automated reads of its own
                API documentation, so the exact field names in both directions are reconstructed rather
                than copied from a spec. This button settles that in one round trip instead of finding
                out during a real customer's handoff — and every attempt, sent and received, is recorded
                verbatim below.</p>
            </form>

            <?php
            // Turned-away deliveries. Shown above the main log because a 401 is the one
            // failure the operator cannot diagnose from Missive's side — it just says
            // "401 Unauthorized" and stops.
            $rej = get_option( 'pps_assistant_webhook_rejects', array() );
            if ( is_string( $rej ) ) $rej = json_decode( $rej, true );
            if ( is_array( $rej ) && $rej ) : ?>
                <h3 style="color:#b32d2e">Rejected webhook deliveries
                    <span style="font-weight:400;color:#666">(last 5)</span></h3>
                <p class="description" style="max-width:46em"><strong>Read
                <code>k_present</code> first.</strong> False means the key never arrived —
                the sender dropped the query string, so use the <code>X-PPS-Key</code> header
                instead if Missive allows custom headers. True means it arrived but did not
                match, so the URL in Missive is from before a key regeneration — re-copy it.</p>
                <div style="max-height:280px;overflow:auto;border:1px solid #ccd0d4;background:#fff;padding:10px">
                <?php foreach ( $rej as $r ) : ?>
                    <pre style="margin:0 0 8px;white-space:pre-wrap;word-break:break-all;font-size:11px;color:#555"><?php
                        echo esc_html( wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                    ?></pre>
                    <hr style="margin:6px 0">
                <?php endforeach; ?>
                </div>
            <?php endif;

            $mlog = get_option( 'pps_assistant_missive_log', array() );
            if ( is_string( $mlog ) ) $mlog = json_decode( $mlog, true );
            if ( is_array( $mlog ) && $mlog ) : ?>
                <h3>Bridge log <span style="font-weight:400;color:#666">(newest first, last 15)</span></h3>
                <div style="max-height:340px;overflow:auto;border:1px solid #ccd0d4;background:#fff;padding:10px">
                <?php foreach ( $mlog as $row ) : ?>
                    <p style="margin:0 0 4px"><strong><?php echo esc_html( $row['event'] ?? '?' ); ?></strong>
                    <code><?php echo esc_html( $row['at'] ?? '' ); ?></code>
                    <?php if ( isset( $row['status'] ) ) : ?>
                        — HTTP <?php echo (int) $row['status']; ?>
                    <?php endif; ?></p>
                    <?php foreach ( array( 'request', 'response', 'raw', 'error' ) as $k ) :
                        if ( empty( $row[ $k ] ) ) continue; ?>
                        <pre style="margin:0 0 8px;white-space:pre-wrap;word-break:break-all;font-size:11px;color:#555"><?php
                            echo esc_html( $k . ': ' . ( is_string( $row[ $k ] ) ? $row[ $k ] : wp_json_encode( $row[ $k ] ) ) );
                        ?></pre>
                    <?php endforeach; ?>
                    <hr style="margin:6px 0">
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
