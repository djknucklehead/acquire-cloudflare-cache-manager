<?php
// Copy ONLY into the disposable fixture MU directory. Blocked synthetic zone only.
if ( ! defined( 'ACFCM_TEST_STATE' ) || ! preg_match( '#^/(private/)?tmp/acfcm-#', ACFCM_TEST_STATE ) ) { exit; }
// Synthetic scope is visible ONLY to the pure migration host validator. Never
// change WordPress navigation, redirects, script origins, form actions or links.
add_filter( 'home_url', function( $url, $path ) {
    foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 ) as $frame ) {
        if ( ( $frame['class'] ?? '' ) === 'ACFCM_Cache_Policy' && ( $frame['function'] ?? '' ) === 'host' ) { return 'https://site' . get_current_blog_id() . '.test/'; }
    }
    return $url;
}, 999, 2 );
add_filter( 'pre_http_request', function( $pre, $args, $url ) {
    if ( ! preg_match( '#^https://api.cloudflare.com/client/v4/zones/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/(.*)$#', $url, $m ) ) { return $pre; }
    $state = get_option( 'acfcm_test_cache_state', array() ); $path = $m[1]; $body = json_decode( $args['body'] ?? 'null', true ); $code = 200; $result = null;
    if ( 'GET' === $args['method'] ) { $phase = explode( '/', $path )[2] ?? ''; $result = $state[$phase] ?? null; $code = $result ? 200 : 404; }
    elseif ( get_option( 'acfcm_test_cache_fail', false ) ) { $code = 403; }
    elseif ( in_array( $args['method'], array( 'POST', 'DELETE' ), true ) && strpos( $path, 'rulesets' ) === 0 ) {
        if ( 'rulesets' === $path ) { $phase = $body['phase']; $state[$phase] = array( 'id' => 'set-' . $phase, 'version' => '1', 'kind' => 'zone', 'phase' => $phase, 'rules' => array() ); $rule = $body['rules'][0]; }
        else { $phase = null; foreach ( $state as $p => $s ) { if ( strpos( $path, 'rulesets/' . $s['id'] . '/rules' ) === 0 ) { $phase = $p; } } $rule = $body; }
        if ( ! $phase ) { return new WP_Error( 'offline', 'Unexpected synthetic request.' ); }
        if ( 'DELETE' === $args['method'] ) { $id = basename( $path ); $state[$phase]['rules'] = array_values( array_filter( $state[$phase]['rules'], function( $r ) use ( $id ) { return $r['id'] !== $id; } ) ); }
        else { $index = $rule['position']['index'] ?? count( $state[$phase]['rules'] ) + 1; unset( $rule['position'] ); $rule['id'] = wp_generate_uuid4(); array_splice( $state[$phase]['rules'], $index - 1, 0, array( $rule ) ); }
        $state[$phase]['version'] = (string) ( 1 + (int) $state[$phase]['version'] ); update_option( 'acfcm_test_cache_state', $state, false ); $result = $state[$phase];
    } else { return new WP_Error( 'offline', 'Unexpected synthetic request.' ); }
    return array( 'response' => array( 'code' => $code, 'message' => 'Synthetic response' ), 'body' => wp_json_encode( array( 'success' => $code < 300, 'result' => $result ) ), 'headers' => array() );
}, PHP_INT_MAX, 3 );
