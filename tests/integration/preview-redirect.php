<?php
// Real WordPress safe-redirect coverage. Never follow URLs or contact providers.
$root = realpath( $argv[1] ?? '' );
if ( ! $root || ! preg_match( '#^/(private/)?tmp/acfcm-#', $root ) ) { exit( 'Disposable fixture required.' ); }
require $root . '/wp-load.php';
if ( ! is_multisite() ) { exit( 'Multisite fixture required.' ); }
class_alias( 'Acquire_Cloudflare_Cache_Manager', 'PreviewCM' );
class PreviewRedirect extends RuntimeException {}
function verify_redirect( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS $message\n"; }
wp_set_current_user( 1 );
$original = get_current_blog_id();
$target = 2;
$mapped = true;
$requests = 0;
add_filter( 'site_url', function( $url, $path, $scheme, $blog_id ) use ( $target, &$mapped ) {
    if ( (int) $blog_id === $target ) {
        return ( $mapped ? 'https://mapped.example.test' : 'http://127.0.0.1:18892/subsite' ) . '/' . ltrim( $path, '/' );
    }
    return $url;
}, 999, 4 );
add_filter( 'pre_http_request', function() use ( &$requests ) { $requests++; return new WP_Error( 'offline', 'Synthetic preview failure.' ); }, PHP_INT_MAX, 3 );
add_filter( 'wp_redirect', function( $url ) { throw new PreviewRedirect( $url ); } );
add_filter( 'wp_die_handler', function() { return function( $message ) { throw new RuntimeException( strip_tags( $message ) ); }; } );
$destination = get_admin_url( $target, 'options-general.php?page=acfcm-cloudflare-cache' );
verify_redirect( wp_validate_redirect( $destination, 'blocked' ) === 'blocked', 'reproduces mapped-domain rejection in WordPress before fix' );
foreach ( array( true, false ) as $mapped_case ) {
    $mapped = $mapped_case;
    foreach ( array( 'cache_rules', 'cache_security_rules' ) as $kind ) {
        $_GET['blog_id'] = $target;
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'acfcm_install_' . $kind . '_' . $target );
        try {
            call_user_func( array( 'PreviewCM', 'handle_install_network_site_' . $kind ) );
            throw new RuntimeException( 'Expected redirect.' );
        } catch ( PreviewRedirect $e ) {
            $url = $e->getMessage();
            $expected = get_admin_url( $target, 'options-general.php?page=acfcm-cloudflare-cache' );
            verify_redirect( strpos( $url, $expected . '&' ) === 0 && substr( $url, -13 ) === '#acfcm-policy', $kind . ' opens selected site policy section (' . ( $mapped ? 'mapped' : 'subdirectory' ) . ')' );
            verify_redirect( strpos( $url, 'acfcm_notice=' ) !== false, 'preview outcome notice survives redirect' );
        }
        verify_redirect( get_current_blog_id() === $original && ! ms_is_switched(), 'handler restores original blog context' );
        verify_redirect( ! in_array( 'mapped.example.test', apply_filters( 'allowed_redirect_hosts', array(), '' ), true ), 'temporary redirect allowance removed' );
    }
}
$mapped = true;
try { PreviewCM::redirect_to_site_cache_preview( $target, array( 'acfcm_notice' => 'cache_rules' ) ); }
catch ( PreviewRedirect $e ) { verify_redirect( strpos( $e->getMessage(), 'acfcm_notice=cache_rules#acfcm-policy' ) !== false, 'success notice also opens mapped preview' ); }
verify_redirect( wp_validate_redirect( 'https://untrusted.example.test/wp-admin/', 'blocked' ) === 'blocked', 'unrelated redirect hosts remain blocked' );
try { PreviewCM::redirect_to_site_cache_preview( 999999, array() ); throw new LogicException( 'Invalid site accepted.' ); }
catch ( RuntimeException $e ) { verify_redirect( ! ( $e instanceof PreviewRedirect ), 'unknown site rejected' ); }
$_GET['blog_id'] = $target;
$_REQUEST['_wpnonce'] = 'invalid';
try { PreviewCM::handle_install_network_site_cache_rules(); throw new LogicException( 'Bad nonce accepted.' ); }
catch ( RuntimeException $e ) { verify_redirect( ! ( $e instanceof PreviewRedirect ), 'invalid nonce rejected' ); }
wp_set_current_user( 0 );
try { PreviewCM::redirect_to_site_cache_preview( $target, array() ); throw new LogicException( 'Unauthorized request accepted.' ); }
catch ( RuntimeException $e ) { verify_redirect( ! ( $e instanceof PreviewRedirect ), 'unauthorized redirect rejected' ); }
echo "All real WordPress preview redirect checks passed.\n";
