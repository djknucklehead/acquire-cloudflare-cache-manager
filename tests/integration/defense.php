<?php
// Disposable WordPress fixture only. Invoke: php defense.php /tmp/.../single
$root = realpath( $argv[1] ?? '' );
if ( ! $root || strpos( $root, '/private/tmp/acfcm-' ) !== 0 && strpos( $root, '/tmp/acfcm-' ) !== 0 ) { exit( 'Disposable fixture required.' ); }
require $root . '/wp-load.php';
function defend_check( $v, $text ) { if ( ! $v ) { throw new RuntimeException( $text ); } echo 'PASS ' . $text . "\n"; }
$fixtures = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/defense/rollout.json' ), true );
$states = array(); foreach ( $fixtures as $f ) { $states[$f['zone']] = $f['after']; }
$writes = array(); $requests = array();
add_filter( 'pre_http_request', function( $pre, $args, $url ) use ( &$states, &$writes, &$requests ) {
    $requests[] = array( $args['method'], $url, json_decode( $args['body'] ?? 'null', true ) );
    if ( preg_match( '#/zones/([a-f0-9]{32})/rulesets/phases/([^/]+)/entrypoint$#', $url, $m ) && 'GET' === $args['method'] ) {
        $state = $states[$m[1]][$m[2]] ?? null;
        return array( 'response' => array( 'code' => $state ? 200 : 404 ), 'body' => json_encode( array( 'success' => (bool) $state, 'result' => $state ) ), 'headers' => array() );
    }
    if ( false !== strpos( $url, '/rulesets' ) ) { $writes[] = $url; return new WP_Error( 'blocked_write', 'Unexpected firewall write in read-only fixture.' ); }
    if ( substr( $url, -12 ) === '/purge_cache' ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}', 'headers' => array() ); }
    return new WP_Error( 'offline', 'All external HTTP blocked.' );
}, PHP_INT_MAX, 3 );
update_option( 'cloudflare_api_token', 'disposable-token' );
foreach ( $fixtures as $f ) {
    $r = Acquire_Cloudflare_Cache_Manager::install_recommended_hardening_rules( $f['zone'], array( 'defense_baseline' => true ) );
    defend_check( $r['success'], 'real WordPress adoption ' . $f['name'] );
}
defend_check( ! $writes, 'all rollout adoption uses reads only in WordPress HTTP API' );
$zone = $fixtures[0]['zone'];
$lock = 'acfcm_defense_lock_' . $zone;
add_option( $lock, time(), '', false );
$r = Acquire_Cloudflare_Cache_Manager::install_recommended_hardening_rules( $zone, array( 'defense_baseline' => true ) );
defend_check( ! $r['success'], 'real SQL lock refuses interrupted installer' );delete_option( $lock );
if ( is_multisite() ) {
    switch_to_blog( 2 ); update_option( 'cloudflare_api_token', 'disposable-second-token' );
    $r = Acquire_Cloudflare_Cache_Manager::install_recommended_hardening_rules( $zone, array( 'defense_baseline' => true ) );
    defend_check( $r['success'] && get_current_blog_id() === 2, 'real multisite lock preserves subsite context' ); restore_current_blog();
}
update_option( 'cloudflare_zone_id', $zone );
update_site_option( 'acfcm_network_auto_purge', '0' );
$count = count( $requests );
do_action( 'upgrader_process_complete', null, array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php' ) ) );
defend_check( ! $writes && count( $requests ) === $count, 'plugin upgrade hook makes no firewall writes or immediate cache flush' );
update_site_option( 'acfcm_network_auto_purge', '1' );
do_action( 'upgrader_process_complete', null, array( 'action' => 'update', 'type' => 'plugin' ) );
defend_check( ! $writes && count( $requests ) === $count, 'enabled update maintenance still queues without firewall writes or immediate broad flush' );
update_site_option( 'acfcm_network_auto_purge', '0' );
$post = wp_insert_post( array( 'post_title' => 'Defense fixture', 'post_status' => 'publish' ) );
wp_update_post( array( 'ID' => $post, 'post_content' => 'Local edit' ) );
Acquire_Cloudflare_Cache_Manager::flush_content_purges();
defend_check( ! $writes, 'real content edit never installs firewall rules' );
Acquire_Cloudflare_Cache_Manager::purge_urls_for_current_site( array( home_url( '/defense-fixture/' ) ) );
defend_check( ! $writes, 'manual URL purge never installs firewall rules' );
$purges = array_filter( $requests, function( $r ) { return substr( $r[1], -12 ) === '/purge_cache'; } );
defend_check( count( $purges ) > 0, 'manual purge exercised actual mocked HTTP request' );
foreach ( $purges as $r ) { defend_check( empty( $r[2]['purge_everything'] ), 'content/manual URL purge remains targeted' ); }
// Leave the disposable fixture at the maintenance suite's documented default.
update_site_option( 'acfcm_network_auto_purge', '1' );
echo "Real WordPress defense integration passed.\n";
