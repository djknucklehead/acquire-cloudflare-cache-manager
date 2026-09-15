<?php
// Disposable harness only. Never package this file in the plugin.
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( strpos( $url, 'https://api.cloudflare.com/client/v4/zones/' ) !== 0 || substr( $url, -12 ) !== '/purge_cache' ) {
        return new WP_Error( 'local_only', 'External HTTP is disabled in this harness.' );
    }
    $file = ACFCM_TEST_STATE . '/http.json';
    $fp = fopen( $file, 'c+' );
    flock( $fp, LOCK_EX );
    $state = json_decode( stream_get_contents( $fp ), true ) ?: array( 'calls' => array(), 'responses' => array() );
    $response = array_shift( $state['responses'] ) ?: array( 'code' => 200 );
    $state['calls'][] = array( 'site' => get_current_blog_id(), 'url' => $url, 'payload' => json_decode( $args['body'], true ), 'token' => $args['headers']['Authorization'], 'time' => microtime( true ) );
    ftruncate( $fp, 0 ); rewind( $fp ); fwrite( $fp, json_encode( $state ) ); fflush( $fp ); flock( $fp, LOCK_UN ); fclose( $fp );
    if ( ! empty( $response['sleep'] ) ) { usleep( $response['sleep'] * 1000000 ); }
    if ( ! empty( $response['crash'] ) ) { exit; }
    if ( ! empty( $response['transport'] ) ) { return new WP_Error( 'timeout', 'Synthetic timeout' ); }
    return array( 'response' => array( 'code' => $response['code'], 'message' => 'Synthetic response' ), 'headers' => array( 'retry-after' => $response['retry_after'] ?? '' ), 'body' => $response['body'] ?? json_encode( array( 'success' => 200 === $response['code'], 'errors' => array( array( 'code' => 1000, 'message' => 'Synthetic failure' ) ) ) ) );
}, PHP_INT_MAX, 3 );
add_filter( 'pre_schedule_event', function ( $pre, $event ) {
    return file_exists( ACFCM_TEST_STATE . '/schedule-fail' ) && strpos( $event->hook, 'acfcm_' ) === 0 ? false : $pre;
}, 10, 2 );

add_action( 'init', function () { if ( defined( 'DOING_CRON' ) && DOING_CRON && isset( $_GET['test_site'] ) && ( $_SERVER['HTTP_X_ACFCM_TEST'] ?? '' ) === 'disposable-local-only' && is_multisite() ) { switch_to_blog( (int) $_GET['test_site'] ); } }, 0 );
