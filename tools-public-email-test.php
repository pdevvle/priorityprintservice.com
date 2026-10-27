<?php
/**
 * The public site shows Office@, never the WordPress admin email (owner 2026-10-27).
 *
 * admin_email is the owner's personal inbox. It was printed in the LocalBusiness schema,
 * both noscript fallbacks and /llms.txt. This lifts pps_public_contact_email() and
 * pps_build_localbusiness_schema() out of pps-calculators.php and runs them with
 * admin_email AND a saved seo.email set to a personal address, and checks every other
 * public output reads the helper. The live crawl after deploy is the other half.
 *
 *   php tools-public-email-test.php [path/to/pps-calculators.php]
 */
$src  = file_get_contents( $argv[1] ?? __DIR__ . '/pps-calculators.php' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "ok   $m\n"; } else { $fail++; echo "FAIL $m\n"; } }

const PERSONAL = 'someone.personal@gmail.com';
function get_option( $k, $d = false ) { return $k === 'admin_email' ? PERSONAL : $d; }
function get_bloginfo( $k ) { return 'Priority Print Service'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function esc_url_raw( $u ) { return $u; }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }

function lift( $src, $name ) {
    if ( ! preg_match( '/\nfunction ' . $name . '\s*\(.*?\n}\n/s', $src, $m ) ) return '';
    return $m[0];
}
$helper = lift( $src, 'pps_public_contact_email' );
$schema = lift( $src, 'pps_build_localbusiness_schema' );
ok( $helper !== '', 'pps_public_contact_email() exists' );
ok( $schema !== '', 'pps_build_localbusiness_schema() exists' );
ok( preg_match( "/define\\( 'PPS_PUBLIC_CONTACT_EMAIL', 'Office@priorityprintservice\\.com' \\)/", $src ) === 1, 'default is Office@priorityprintservice.com' );
define( 'PPS_PUBLIC_CONTACT_EMAIL', 'Office@priorityprintservice.com' );
eval( $helper . $schema );

ok( pps_public_contact_email() === 'Office@priorityprintservice.com', 'helper answers Office@' );
$out = json_encode( pps_build_localbusiness_schema( array() ) );
ok( strpos( $out, 'Office@priorityprintservice.com' ) !== false, 'schema carries Office@ with no seo.email' );
ok( strpos( $out, PERSONAL ) === false, 'schema never falls back to admin_email' );
$out = json_encode( pps_build_localbusiness_schema( array( 'email' => 'another.personal@gmail.com' ) ) );
ok( strpos( $out, 'gmail' ) === false && strpos( $out, 'Office@' ) !== false, 'a saved seo.email cannot put another address in the schema' );

// Every remaining admin_email read must be a mail RECIPIENT, never output.
preg_match_all( "/^.*get_option\\( *'admin_email' *\\).*$/m", $src, $m );
$left = array_values( array_filter( $m[0], function( $l ) { return strpos( ltrim( $l ), '*' ) !== 0; } ) );
ok( count( $left ) === 2, 'only two admin_email reads remain (found ' . count( $left ) . ')' );
foreach ( $left as $l ) ok( preg_match( '/\$(recipient|to) = get_option/', $l ) === 1, 'remaining read is a recipient: ' . trim( $l ) );

// The noscript fallbacks and llms.txt read the helper.
ok( substr_count( $src, "\$email     = pps_public_contact_email();" ) === 1, 'preset noscript uses the helper' );
ok( strpos( $src, "esc_html( pps_public_contact_email() )" ) !== false, 'product noscript text uses the helper' );
ok( strpos( $src, 'mailto:<?php echo esc_attr( pps_public_contact_email() ); ?>' ) !== false, 'product noscript mailto uses the helper' );
ok( preg_match( "/pps_llms_txt.*?\\\$email    = pps_public_contact_email\\(\\);.*?echo \"- Email: \\{\\\$email\\}/s", $src ) === 1, 'llms.txt uses the helper' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
