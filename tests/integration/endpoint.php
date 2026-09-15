<?php
if ( PHP_SAPI !== 'cli' && ( ! in_array( $_SERVER['REMOTE_ADDR'], array( '127.0.0.1', '::1' ), true ) || ( $_SERVER['HTTP_X_ACFCM_TEST'] ?? '' ) !== 'disposable-local-only' ) ) { http_response_code( 403 ); exit; }
require __DIR__ . '/wp-load.php';
wp_set_current_user( 1 );
$input = PHP_SAPI === 'cli' ? json_decode( $argv[1], true ) : json_decode( file_get_contents( 'php://input' ), true );
if ( ! empty( $input['site'] ) && is_multisite() ) { switch_to_blog( (int) $input['site'] ); }
$class = 'Acquire_Cloudflare_Cache_Manager';
$out = array();
switch ( $input['action'] ) {
    case 'newsite': $out['id'] = get_blog_id_from_url( '127.0.0.1:18892', '/second/' ) ?: wpmu_create_blog( '127.0.0.1:18892', '/second/', 'Second synthetic site', 1 ); break;
    case 'attachment': $out['id'] = wp_insert_attachment( array( 'post_title' => 'Synthetic image', 'post_mime_type' => 'image/jpeg', 'guid' => home_url( '/' . $input['name'] . '.jpg' ) ) ); break;
    case 'author': $out['id'] = wp_create_user( 'author' . wp_generate_password( 6, false ), 'fake-password', 'author' . wp_rand() . '@example.invalid' ); break;
    case 'bulk':
        for ( $i = 0; $i < $input['count']; $i++ ) { wp_insert_post( array( 'post_title' => 'Bulk ' . wp_generate_uuid4(), 'post_status' => 'publish' ) ); }
        break;
    case 'setup':
        update_option( 'cloudflare_zone_id', 'fake-zone-' . get_current_blog_id() );
        update_option( 'cloudflare_api_token', 'fake-token-' . get_current_blog_id() );
        update_option( 'permalink_structure', '/%postname%/' );
        if ( is_multisite() ) { update_site_option( 'active_sitewide_plugins', array( 'acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php' => time() ) ); }
        else { update_option( 'active_plugins', array( 'acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php' ) ); }
        break;
    case 'create':
    case 'update':
        $post = $input['post'];
        if ( ! empty( $input['rest'] ) ) {
            $request = new WP_REST_Request( 'POST', '/wp/v2/posts' . ( isset( $post['ID'] ) ? '/' . $post['ID'] : '' ) );
            $map = array( 'post_title' => 'title', 'post_content' => 'content', 'post_status' => 'status', 'post_name' => 'slug', 'post_author' => 'author' );
            foreach ( $post as $key => $value ) { $request->set_param( $map[$key] ?? $key, $value ); }
            $result = rest_do_request( $request );
            $out = array( 'status' => $result->get_status(), 'data' => $result->get_data() );
            $id = $out['data']['id'] ?? 0;
        } else { $id = isset( $post['ID'] ) ? wp_update_post( $post, true ) : wp_insert_post( $post, true ); $out['id'] = is_wp_error( $id ) ? $id->get_error_message() : $id; }
        if ( ! empty( $input['late_term'] ) ) { wp_set_post_terms( $id, array( (int) $input['late_term'] ), 'category' ); }
        if ( ! empty( $input['late_thumb'] ) ) { update_post_meta( $id, '_thumbnail_id', $input['late_thumb'] ); }
        if ( isset( $input['late_meta'] ) ) { update_post_meta( $id, 'late_value', $input['late_meta'] ); }
        break;
    case 'term': $out = wp_insert_term( $input['name'], 'category' ); break;
    case 'trash': wp_trash_post( $input['id'] ); break;
    case 'delete': wp_delete_post( $input['id'], true ); break;
    case 'schedule-now':
        global $wpdb;
        $wpdb->update( $wpdb->posts, array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'ID' => $input['id'] ) );
        clean_post_cache( $input['id'] );
        wp_clear_scheduled_hook( 'publish_future_post', array( $input['id'] ) );
        wp_schedule_single_event( time() - 1, 'publish_future_post', array( $input['id'] ) );
        break;
    case 'publish': wp_publish_post( $input['id'] ); break;
    case 'purge': $out = $class::purge_urls_for_current_site( $input['urls'] ); break;
    case 'advance':
        global $wpdb;
        foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'acfcm_purge_job_%'" ) as $row ) {
            $job = maybe_unserialize( $row->option_value );
            $job['due'] = time() - 1;
            if ( isset( $input['attempt'] ) ) { $job['attempt'] = $input['attempt']; }
            if ( isset( $input['age'] ) ) { $job['created'] = time() - $input['age']; }
            update_option( $row->option_name, $job, false );
            wp_clear_scheduled_hook( 'acfcm_retry_purge', array( $row->option_name, $job['id'] ) );
            wp_schedule_single_event( time() - 1, 'acfcm_retry_purge', array( $row->option_name, $job['id'] ) );
        }
        break;
    case 'state':
        global $wpdb;
        $out['jobs'] = array();
        foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'acfcm_purge_job_%'" ) as $row ) { $out['jobs'][$row->option_name] = maybe_unserialize( $row->option_value ); }
        $out['cron'] = _get_cron_array(); $out['log'] = get_site_option( 'acfcm_purge_log', array() );
        $out['version'] = $GLOBALS['wp_version'];
        $out['persistent_cache'] = wp_using_ext_object_cache();
        $out['cache_connected'] = method_exists( $GLOBALS['wp_object_cache'], 'redis_status' ) ? $GLOBALS['wp_object_cache']->redis_status() : false;
        if ( isset( $input['id'] ) ) { $out['permalink'] = get_permalink( $input['id'] ); $out['meta'] = get_post_meta( $input['id'], 'late_value', true ); }
        break;
    case 'reset':
        global $wpdb;
        foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'acfcm_purge_job_%'" ) as $key ) { delete_option( $key ); }
        foreach ( _get_cron_array() as $time => $hooks ) { foreach ( $hooks as $hook => $events ) { if ( strpos( $hook, 'acfcm_' ) === 0 ) { wp_clear_scheduled_hook( $hook ); } } }
        delete_site_option( 'acfcm_purge_log' );
        delete_site_transient( 'acfcm_purge_cooldown_' . substr( hash( 'sha256', $class::get_cf_api_token() ), 0, 24 ) );
        break;
}
header( 'Content-Type: application/json' );
echo wp_json_encode( $out );
