<?php
/**
 * PPS Google Business Profile rating sync.
 *
 * The LocalBusiness schema's aggregateRating is mirrored from the Google
 * Business Profile into seo.gbp_rating_value / seo.gbp_review_count. Until now
 * that mirror was copied by hand, so it was accurate on the day somebody
 * remembered and drifting every day after.
 *
 * This fetches it daily from the Places API and writes it back.
 *
 * ── Why the Places API and not the Business Profile API ──
 *
 * The Business Profile API manages listings and needs an access request
 * approved by Google. We want two numbers. Places returns both in one call
 * with an API key and no OAuth:
 *
 *   GET https://places.googleapis.com/v1/places/{PLACE_ID}
 *   X-Goog-Api-Key:    <key>
 *   X-Goog-FieldMask:  rating,userRatingCount
 *
 * One call a day is ~30/month, inside the free tier.
 *
 * ── The failure mode this file is built around ──
 *
 * pps_emit_localbusiness_schema() only emits aggregateRating when BOTH the
 * rating and the count are non-zero. So a naive "fetch and store" that writes
 * zeros on a timeout would silently strip the rating from every page on the
 * site, and nothing would report it. Therefore: a failed fetch changes
 * nothing. It records the error and leaves the last good values exactly where
 * they were.
 *
 * Setup (one time):
 *   1. Google Cloud project → enable "Places API (New)" → create an API key,
 *      restricted to that API and to the server's IP.
 *   2. Find the Place ID for the business (Google's Place ID Finder).
 *   3. PPS Calculators → Config → SEO: paste both. The sync starts next run;
 *      "Sync now" runs it immediately.
 *
 * The key lives in pps_calc_config alongside the Shippo token, so it inherits
 * the standing rule that the option is never bulk-copied between sites.
 *
 * Loaded by pps-calculators.php (file_exists-guarded require).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_GBP_CRON_HOOK', 'pps_gbp_refresh_rating' );

/* ─────────────────────────────────────────────────────────────
 * Schedule
 * ───────────────────────────────────────────────────────────── */

add_action( 'init', function () {
    if ( ! wp_next_scheduled( PPS_GBP_CRON_HOOK ) ) {
        // Off-peak, and offset from the top of the hour so it does not pile in
        // with every other daily job on the host.
        wp_schedule_event( time() + 300, 'daily', PPS_GBP_CRON_HOOK );
    }
} );

// A deactivated plugin should not leave a job behind that fires forever.
register_deactivation_hook( PPS_CALC_DIR . 'pps-calculators.php', function () {
    $ts = wp_next_scheduled( PPS_GBP_CRON_HOOK );
    if ( $ts ) wp_unschedule_event( $ts, PPS_GBP_CRON_HOOK );
} );

add_action( PPS_GBP_CRON_HOOK, 'pps_gbp_sync_rating' );

/* ─────────────────────────────────────────────────────────────
 * Fetch
 * ───────────────────────────────────────────────────────────── */

/**
 * Ask the Places API for the current rating.
 *
 * @return array|null ['rating' => float, 'count' => int] or null on any
 *                    failure. Null is deliberately indistinguishable between
 *                    "no key", "network down" and "bad response" — every one
 *                    of them means "change nothing".
 */
function pps_gbp_fetch_rating( &$error = '' ) {
    $seo = pps_gbp_seo_settings();
    $key = trim( (string) ( $seo['places_api_key'] ?? '' ) );
    $pid = trim( (string) ( $seo['place_id'] ?? '' ) );

    if ( $key === '' || $pid === '' ) {
        $error = 'Places API key or Place ID not set';
        return null;
    }

    $resp = wp_remote_get(
        'https://places.googleapis.com/v1/places/' . rawurlencode( $pid ),
        array(
            'timeout' => 15,
            'headers' => array(
                'X-Goog-Api-Key'   => $key,
                'X-Goog-FieldMask' => 'rating,userRatingCount',
            ),
        )
    );

    if ( is_wp_error( $resp ) ) {
        $error = 'request failed: ' . $resp->get_error_message();
        return null;
    }

    $code = (int) wp_remote_retrieve_response_code( $resp );
    if ( $code !== 200 ) {
        // Google puts a readable reason in the body; keep the first part of it,
        // because "HTTP 403" alone never tells you it was the key restriction.
        $body  = wp_remote_retrieve_body( $resp );
        $error = 'HTTP ' . $code . ' — ' . substr( wp_strip_all_tags( (string) $body ), 0, 200 );
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( ! is_array( $data ) ) {
        $error = 'response was not JSON';
        return null;
    }

    $rating = isset( $data['rating'] ) ? (float) $data['rating'] : 0.0;
    $count  = isset( $data['userRatingCount'] ) ? (int) $data['userRatingCount'] : 0;

    // A profile with no reviews yet returns a valid response with nothing
    // usable in it. Treat that as "nothing to mirror", not as data.
    if ( $rating <= 0 || $rating > 5 || $count <= 0 ) {
        $error = 'no usable rating in the response (rating ' . $rating . ', count ' . $count . ')';
        return null;
    }

    return array( 'rating' => round( $rating, 1 ), 'count' => $count );
}

/* ─────────────────────────────────────────────────────────────
 * Store
 * ───────────────────────────────────────────────────────────── */

/**
 * Read the SEO block from the raw stored option.
 *
 * Deliberately not pps_get_config(): that merges in every default, and writing
 * the merged array back would bake today's defaults into stored values, so a
 * later change to a default would no longer take effect.
 */
function pps_gbp_seo_settings() {
    $cfg = get_option( PPS_CONFIG_OPTION, array() );
    if ( is_string( $cfg ) ) {
        $decoded = json_decode( $cfg, true );
        $cfg = is_array( $decoded ) ? $decoded : array();
    }
    return ( isset( $cfg['seo'] ) && is_array( $cfg['seo'] ) ) ? $cfg['seo'] : array();
}

/**
 * Run the sync. Safe to call at any time; called daily by cron.
 *
 * @return array ['ok' => bool, 'message' => string, 'changed' => bool]
 */
function pps_gbp_sync_rating() {
    $error = '';
    $new   = pps_gbp_fetch_rating( $error );

    $cfg = get_option( PPS_CONFIG_OPTION, array() );
    if ( is_string( $cfg ) ) {
        $decoded = json_decode( $cfg, true );
        $cfg = is_array( $decoded ) ? $decoded : array();
    }
    if ( ! isset( $cfg['seo'] ) || ! is_array( $cfg['seo'] ) ) $cfg['seo'] = array();

    if ( $new === null ) {
        // The whole point: record the failure, touch nothing else. The last
        // good rating keeps being emitted.
        $cfg['seo']['gbp_sync_error']    = $error;
        $cfg['seo']['gbp_sync_error_at'] = current_time( 'mysql' );
        update_option( PPS_CONFIG_OPTION, $cfg, false );
        return array( 'ok' => false, 'message' => $error, 'changed' => false );
    }

    $old_rating = (float) ( $cfg['seo']['gbp_rating_value'] ?? 0 );
    $old_count  = (int) ( $cfg['seo']['gbp_review_count'] ?? 0 );
    $changed    = ( abs( $old_rating - $new['rating'] ) >= 0.05 || $old_count !== $new['count'] );

    $cfg['seo']['gbp_rating_value'] = $new['rating'];
    $cfg['seo']['gbp_review_count'] = $new['count'];
    $cfg['seo']['gbp_synced_at']    = current_time( 'mysql' );
    unset( $cfg['seo']['gbp_sync_error'], $cfg['seo']['gbp_sync_error_at'] );

    update_option( PPS_CONFIG_OPTION, $cfg, false );

    // The rating is baked into page-cached HTML via LocalBusiness schema, so a
    // real change has to invalidate the cache — but only a real change. Purging
    // daily because the sync ran is a lot of cache churn for no new information.
    if ( $changed && function_exists( 'pps_purge_page_cache' ) ) {
        pps_purge_page_cache();
    }

    return array(
        'ok'      => true,
        'changed' => $changed,
        'message' => sprintf( '%s from %d reviews%s',
            number_format( $new['rating'], 1 ), $new['count'],
            $changed ? ' (updated)' : ' (unchanged)' ),
    );
}

/* ─────────────────────────────────────────────────────────────
 * Admin surface
 * ───────────────────────────────────────────────────────────── */

/**
 * "Sync now" — so the operator can prove the key works without waiting a day.
 */
add_action( 'admin_post_pps_gbp_sync_now', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_gbp_sync_now' );

    $r = pps_gbp_sync_rating();
    set_transient( 'pps_gbp_sync_notice', $r, 60 );

    wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=pps-config' ) );
    exit;
} );

add_action( 'admin_notices', function () {
    $r = get_transient( 'pps_gbp_sync_notice' );
    if ( ! $r || ! is_array( $r ) ) return;
    delete_transient( 'pps_gbp_sync_notice' );

    printf(
        '<div class="notice notice-%s is-dismissible"><p><strong>Business Profile rating:</strong> %s</p></div>',
        $r['ok'] ? 'success' : 'error',
        esc_html( $r['ok'] ? $r['message'] : $r['message'] . ' — the previously stored rating was left unchanged.' )
    );
} );

/**
 * Status line for the SEO tab: when it last synced, or why it hasn't.
 * Rendered by pps-config-admin.php next to the rating fields.
 *
 * @return string HTML
 */
function pps_gbp_sync_status_html() {
    $seo = pps_gbp_seo_settings();
    $btn = '<a href="' . esc_url( wp_nonce_url(
                admin_url( 'admin-post.php?action=pps_gbp_sync_now' ), 'pps_gbp_sync_now' ) )
         . '" class="button button-small">Sync now</a>';

    if ( ! empty( $seo['gbp_sync_error'] ) ) {
        return '<p style="color:#b32d2e;margin:6px 0 0">✕ Last sync failed: '
             . esc_html( $seo['gbp_sync_error'] )
             . ( ! empty( $seo['gbp_sync_error_at'] ) ? ' <em>(' . esc_html( $seo['gbp_sync_error_at'] ) . ')</em>' : '' )
             . '<br><span style="color:#666">The stored rating above is still being published.</span></p>'
             . '<p style="margin:6px 0 0">' . $btn . '</p>';
    }
    if ( ! empty( $seo['gbp_synced_at'] ) ) {
        return '<p style="color:#1f7a5c;margin:6px 0 0">✓ Synced automatically from the Places API — last run '
             . esc_html( $seo['gbp_synced_at'] ) . '</p>'
             . '<p style="margin:6px 0 0">' . $btn . '</p>';
    }
    return '<p style="color:#666;margin:6px 0 0">Not syncing yet — set a Places API key and Place ID below to '
         . 'keep these two numbers current automatically. Until then they are whatever was typed here.</p>'
         . '<p style="margin:6px 0 0">' . $btn . '</p>';
}
