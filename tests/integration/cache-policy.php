<?php
// Only disposable local WordPress; provider requests are intercepted, never sent.
$root = realpath( $argv[1] ?? '' );
if ( ! $root || ! preg_match( '#^/(private/)?tmp/acfcm-#', $root ) ) { exit( 'Disposable fixture required.' ); }
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
class_alias( 'ACFCM_Cache_Policy', 'CP' );
class_alias( 'Acquire_Cloudflare_Cache_Manager', 'CM' );
function verify_cp( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS $message\n"; }
function rejects_cp( $callback, $message ) { try { $callback(); } catch ( RuntimeException $e ) { verify_cp( true, $message ); return; } throw new RuntimeException( $message ); }
$zone = 'cccccccccccccccccccccccccccccccc'; $state = array(); $writes = 0; $fail = 0; $lost_read = false; $once = false;
wp_set_current_user( 1 );
$site = is_multisite() ? 2 : 1;
if ( is_multisite() ) { switch_to_blog( $site ); }
add_filter( 'home_url', function( $url, $path ) { return 'https://site' . get_current_blog_id() . '.test/' . ltrim( (string) $path, '/' ); }, 999, 2 );
add_filter( 'wp_die_handler', function() { return function( $message ) { throw new RuntimeException( strip_tags( $message ) ); }; } );
add_filter( 'pre_http_request', function( $pre, $args, $url ) use ( &$state, &$writes, &$fail, &$lost_read, &$once ) {
    if ( ! preg_match( '#^https://api.cloudflare.com/client/v4/zones/[a-f0-9]{32}/(.*)$#', $url, $m ) ) { return new WP_Error( 'offline', 'No external requests.' ); }
    $method = $args['method']; $path = $m[1]; $body = json_decode( $args['body'] ?? 'null', true ); $code = 200; $result = null;
    if ( 'GET' === $method ) {
        if ( $lost_read && $once ) { $lost_read = false; return new WP_Error( 'lost_read', 'Synthetic failed readback.' ); }
        $phase = explode( '/', $path )[2]; $result = $state[$phase] ?? null; $code = $result ? 200 : 404;
    } else {
        if ( ! in_array( $method, array( 'POST', 'DELETE' ), true ) || strpos( $path, 'purge' ) !== false ) { throw new RuntimeException( 'Forbidden mutation ' . $method . ' ' . $path ); }
        $writes++;
        if ( $fail === $writes ) { $code = 403; }
        else {
            if ( 'rulesets' === $path ) { $phase = $body['phase']; $state[$phase] = array( 'id' => 'set-' . $phase, 'phase' => $phase, 'kind' => 'zone', 'version' => '1', 'rules' => array() ); $rule = $body['rules'][0]; }
            else { $phase = null; foreach ( $state as $p => $s ) { if ( $s && strpos( $path, 'rulesets/' . $s['id'] . '/rules' ) === 0 ) { $phase = $p; } } if ( ! $phase ) { throw new RuntimeException( 'Unexpected path.' ); } $rule = $body; }
            if ( 'DELETE' === $method ) { $id = basename( $path ); $state[$phase]['rules'] = array_values( array_filter( $state[$phase]['rules'], function( $r ) use ( $id ) { return $r['id'] !== $id; } ) ); }
            else { $index = $rule['position']['index'] ?? count( $state[$phase]['rules'] ) + 1; unset( $rule['position'] ); $rule['id'] = 'rule-' . $writes; array_splice( $state[$phase]['rules'], $index - 1, 0, array( $rule ) ); }
            $state[$phase]['version'] = (string) ( 1 + (int) $state[$phase]['version'] ); $result = $state[$phase]; $once = true;
        }
    }
    return array( 'response' => array( 'code' => $code, 'message' => 'Synthetic response' ), 'body' => wp_json_encode( array( 'success' => $code < 300, 'result' => $result ) ), 'headers' => array() );
}, PHP_INT_MAX, 3 );
$reset = function() use ( &$state, &$writes, &$fail, &$lost_read, &$once, $zone ) {
    $state = array( CP::REQUEST => null, CP::RESPONSE => null ); $writes = 0; $fail = 0; $lost_read = false; $once = false;
    update_option( 'cloudflare_zone_id', $zone ); update_option( 'cloudflare_api_token', 'fake-test-token' ); update_option( 'acfcm_cloudflare_mode', 'enabled' ); update_option( 'acfcm_content_auto_purge', '1' ); update_option( 'acfcm_smart_tiered_cache_enabled', '0' ); update_option( 'acfcm_cache_reserve_enabled', '0' );
    delete_option( 'acfcm_public_cache_scope' ); delete_option( 'acfcm_cache_preview' ); delete_option( 'acfcm_cache_rollback_preview' ); CP::store( $zone, array() );
};
$finish = function() use ( $zone ) { for ( $i = 0; $i < 40; $i++ ) { $j = CP::step( $zone ); if ( 'running' !== $j['status'] ) { return $j; } } throw new RuntimeException( 'Unbounded migration' ); };
$reset();
try {
    verify_cp( CM::current_user_can_manage_site_cloudflare(), 'administrator can manage policy' );
    $auth = new ReflectionMethod( 'ACFCM_Cache_Admin', 'authorize' ); $auth->setAccessible( true );
    $_REQUEST['_wpnonce'] = wp_create_nonce( 'acfcm_cache_policy' ); $auth->invoke( null ); verify_cp( true, 'valid real WordPress nonce accepted' );
    $_REQUEST['_wpnonce'] = 'invalid-fixture-nonce'; rejects_cp( function() use ( $auth ) { $auth->invoke( null ); }, 'invalid nonce rejected before migration writes' );
    if ( is_multisite() ) {
        $user = get_user_by( 'login', 'cache_fixture_site_admin' );
        $uid = $user ? $user->ID : wp_insert_user( array( 'user_login' => 'cache_fixture_site_admin', 'user_pass' => wp_generate_password(), 'user_email' => 'cache-admin@example.invalid' ) );
        add_user_to_blog( get_current_blog_id(), $uid, 'administrator' );
        $old_shared = get_site_option( 'acfcm_cloudflare_api_token', '' ); update_site_option( 'acfcm_cloudflare_api_token', 'fake-shared-token' ); wp_set_current_user( $uid );
        verify_cp( current_user_can( 'manage_options' ) && ! CM::current_user_can_manage_site_cloudflare(), 'shared-token subsite administrator cannot manage network policy' );
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'acfcm_cache_policy' ); rejects_cp( function() use ( $auth ) { $auth->invoke( null ); }, 'valid nonce cannot bypass shared-token capability restriction' );
        wp_set_current_user( 1 ); update_site_option( 'acfcm_cloudflare_api_token', $old_shared );
    }
    unset( $_REQUEST['_wpnonce'] );

    $p = CP::preview( $zone ); verify_cp( ! $writes && $p === get_option( 'acfcm_cache_preview' ), 'real SQL preview is persisted without provider writes' );
    $j = CP::begin( $p['id'] ); verify_cp( $j['before'] === $state && ! $writes, 'full backup saved before mutation' );
    verify_cp( hash_file( 'sha256', ACFCM_Runtime_Guard::path() ) === hash_file( 'sha256', dirname( __DIR__, 2 ) . '/includes/public-cache-guard.php' ), 'persistent MU guard installed byte for byte' );
    verify_cp( get_current_blog_id() === $site, 'journal and lock restore original blog context' );
    $lost_read = true; $j = CP::step( $zone ); verify_cp( 'paused' === $j['status'] && isset( $j['intent'] ) && $writes === 1, 'lost readback leaves durable intent and pauses' );
    $j = CP::step( $zone ); verify_cp( $j['cursor'] === 1 && $writes === 1 && ! isset( $j['intent'] ), 'fresh request reconciles applied intent without duplicate mutation' );
    $fail = 3; $j = $finish(); verify_cp( 'paused' === $j['status'] && ! isset( $j['intent'] ), 'definitive entitlement failure pauses without weakening safety' );
    $fail = 0; $j = $finish(); verify_cp( 'complete' === $j['status'] && count( $state[CP::REQUEST]['rules'] ) === 3 && count( $state[CP::RESPONSE]['rules'] ) === 3, 'complete coordinated 3+3 migration on real WordPress' );
    foreach ( array( array( 'different', 'enabled', true ), array( $zone, 'disabled', true ), array( $zone, 'enabled', false ) ) as $v ) { rejects_cp( function() use ( $v ) { ACFCM_Cache_Admin::validate_settings( ...$v ); }, 'active policy blocks purge-breaking settings change' ); }
    rejects_cp( function() { ACFCM_Cache_Admin::deactivate( is_multisite() ); }, 'active policy blocks deactivation' );
    $count = $writes; $again = CP::preview( $zone ); CP::begin( $again['id'] ); verify_cp( 'adopt' === $again['plan']['kind'] && $writes === $count, 'repeat setup adopts existing rules without accumulation' );
    // Starting adoption replaces the current journal, retaining one bounded prior generation.
    $stored = CP::store( $zone ); verify_cp( ! isset( $stored['previous']['previous'] ) && strlen( wp_json_encode( $stored ) ) < 2097152, 'backup retention is bounded and nonrecursive' );
    // New migration for explicit rollback (adoption intentionally does not undo preexisting policy).
    $reset(); $p = CP::preview( $zone ); CP::begin( $p['id'] ); $finish();
    $p = CP::rollback_preview( $zone ); CP::begin_rollback( $p['id'] ); $j = $finish();
    verify_cp( 'rolled_back' === $j['status'] && ! $state[CP::REQUEST]['rules'] && ! $state[CP::RESPONSE]['rules'], 'real SQL rollback removes new policy and hold' );
    verify_cp( ! get_option( 'acfcm_public_cache_scope' ) && file_exists( ACFCM_Runtime_Guard::path() ), 'rollback removes only its scope and retains persistent file' );
    $reset(); $p = CP::preview( $zone ); wp_set_current_user( 0 ); rejects_cp( function() use ( $p ) { CP::begin( $p['id'] ); }, 'another user cannot apply administrator preview' ); verify_cp( ! CM::current_user_can_manage_site_cloudflare(), 'anonymous user denied policy management' ); wp_set_current_user( 1 );
    $reset(); update_option( 'acfcm_content_auto_purge', '0' ); rejects_cp( function() use ( $zone ) { CP::preview( $zone ); }, 'disabled automatic invalidation blocks policy preview' );
    $reset(); $p = CP::preview( $zone ); CP::begin( $p['id'] );
    $original = get_current_blog_id(); if ( is_multisite() ) { switch_to_blog( get_main_site_id( get_main_network_id() ) ); }
    $key = 'acfcm_cache_lock_' . $zone; add_option( $key, array( 'owner' => 'other-worker' ), '', false );
    // Force a stale negative options cache; INSERT IGNORE must still retain the SQL owner.
    wp_cache_set( 'notoptions', array( $key => true ), 'options' ); wp_cache_delete( $key, 'options' );
    if ( is_multisite() ) { restore_current_blog(); }
    rejects_cp( function() use ( $zone ) { CP::step( $zone ); }, 'SQL mutex survives stale negative object-cache entry' );
    verify_cp( ! $writes && get_current_blog_id() === $original, 'blocked concurrent step makes no writes and restores context' );
    if ( is_multisite() ) { switch_to_blog( get_main_site_id( get_main_network_id() ) ); } delete_option( $key ); if ( is_multisite() ) { restore_current_blog(); }
    verify_cp( ACFCM_Cache_Admin::valid_purge_url( home_url( '/public/' ) ), 'own URL accepted' );
    foreach ( array( 'https://evil.test/', home_url( '/a/../private/' ), home_url( '/%2e%2e/private/' ), home_url( '/%252e%252e/private/' ), 'https://user:pass@site' . $site . '.test/' ) as $url ) { verify_cp( ! ACFCM_Cache_Admin::valid_purge_url( $url ), 'unsafe or ambiguous manual URL rejected' ); }
    $reset(); $_POST = array();
    $state[CP::REQUEST] = array( 'id' => 'old-cache', 'phase' => CP::REQUEST, 'kind' => 'zone', 'version' => '1', 'rules' => array(
        array( 'id' => 'custom-old', 'ref' => 'custom-old', 'action' => 'set_cache_settings', 'action_parameters' => array( 'cache' => true ), 'expression' => 'true', 'description' => 'Unfamiliar custom cache rule', 'enabled' => true )
    ) );
    $original_rules = $state[CP::REQUEST]['rules'];
    $request = array( 'blog_id' => $site, 'zone' => $zone, '_wpnonce' => wp_create_nonce( 'acfcm_network_zone_' . $site . '_' . $zone ), 'policy_action' => 'install' );
    $dispatch = function( $action ) use ( &$request ) { $request['policy_action'] = $action; return is_multisite() ? ACFCM_Cache_Admin::network_execute( $request ) : ACFCM_Cache_Admin::execute( $action ); };
    if ( is_multisite() ) {
        $bad = $request; $bad['_wpnonce'] = 'bad'; rejects_cp( function() use ( $bad ) { ACFCM_Cache_Admin::network_execute( $bad ); }, 'network installer rejects invalid nonce' );
        $bad = $request; $bad['zone'] = str_repeat( 'd', 32 ); rejects_cp( function() use ( $bad ) { ACFCM_Cache_Admin::network_execute( $bad ); }, 'network installer rejects changed zone' );
        wp_set_current_user( 0 ); rejects_cp( function() use ( $request ) { ACFCM_Cache_Admin::network_execute( $request ); }, 'network installer rejects missing capability' ); wp_set_current_user( 1 );
    }
    $r = $dispatch( 'install' );
    verify_cp( $r['status'] === 'running' && ! $writes && CP::store( $zone )['before'][CP::REQUEST]['rules'] === $original_rules, 'one click saves original cache backup before provider writes' );
    for ( $i = 0; $i < 30 && $r['status'] === 'running'; $i++ ) { $r = $dispatch( 'step' ); }
    verify_cp( $r['status'] === 'complete' && count( $state[CP::REQUEST]['rules'] ) === 3 && count( $state[CP::RESPONSE]['rules'] ) === 3, 'one-click installer replaces unfamiliar rules with standard six rules' );
    verify_cp( get_current_blog_id() === $site, 'network installer restores calling blog context' );
    $before_security = $state;
    $r = $dispatch( 'security' );
    verify_cp( $r['status'] === 'complete' && $state[CP::REQUEST] === $before_security[CP::REQUEST] && $state[CP::RESPONSE] === $before_security[CP::RESPONSE], 'security installer leaves cache rules unchanged' );
    $count = $writes; $dispatch( 'install' ); verify_cp( $writes === $count, 'repeated cache install adopts standard rules without rewriting' );
    // Capability and rendered-field parity, with real WordPress functions and nonces.
    $_POST = array(); ob_start(); CM::render_subsite_settings_page(); $html = ob_get_clean();
    foreach ( array( 'Refresh a page', 'Install security rules', 'acfcm_cache_policy', 'acfcm_content_auto_purge', 'acfcm_logged_in_nocache', 'acfcm_cache_reserve_enabled', 'acfcm_smart_tiered_cache_enabled', 'Security', 'Recent activity', '_wpnonce' ) as $control ) { verify_cp( strpos( $html, $control ) !== false, 'site UI retains ' . $control ); }
    verify_cp( strpos( $html, 'value="fake-test-token"' ) === false, 'secret token is never rendered into inputs' );
    if ( is_multisite() ) { restore_current_blog(); ob_start(); CM::render_network_settings_page(); $html = ob_get_clean(); foreach ( array( 'acfcm_site_mode', 'acfcm_zone_id', 'acfcm_cache_reserve', 'acfcm_smart_tiered_cache', 'Clear WP Engine + Cloudflare cache', 'Install cache rules', 'Install security rules', 'acfcm_maintenance_control' ) as $control ) { verify_cp( strpos( $html, $control ) !== false, 'network UI retains ' . $control ); } switch_to_blog( $site ); }
} finally {
    $reset(); update_option( 'cloudflare_zone_id', 'fake-zone-' . $site );
    if ( is_multisite() ) { restore_current_blog(); }
}
echo "Real WordPress cache policy integration passed.\n";
