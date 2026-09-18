<?php
// Standalone loopback-only HTTP fixture for actual PHP headers/output buffering.
if ( PHP_SAPI !== 'cli-server' || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) { http_response_code( 403 ); exit; }
define( 'ABSPATH', __DIR__ );
$case = $_GET['case'] ?? 'public';
$GLOBALS['guard_hooks'] = array();
function add_action( $hook, $call, ...$args ) { $GLOBALS['guard_hooks'][$hook][] = $call; }
function add_filter( $hook, $call, ...$args ) { add_action( $hook, $call ); }
function get_current_blog_id() { return 'legacy' === $GLOBALS['case'] ? 18 : 999; }
function home_url( $path ) { return 'https://' . ( 'legacy' === $GLOBALS['case'] ? 'meettroyjackson.com' : 'example.test' ) . $path; }
function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
function get_option( $key, $default = null ) { return 'unscoped' === $GLOBALS['case'] ? array() : array( 'host' => 'legacy' === $GLOBALS['case'] ? 'meettroyjackson.com' : 'example.test' ); }
function is_admin() { return 'admin' === $GLOBALS['case']; }
function wp_doing_ajax() { return 'ajax' === $GLOBALS['case']; }
function is_feed() { return 'feed' === $GLOBALS['case']; }
function is_user_logged_in() { return 'logged-in' === $GLOBALS['case']; }
function is_preview() { return 'preview' === $GLOBALS['case']; }
function nocache_headers() { header( 'Cache-Control: no-cache' ); }
class WP_Post { public $post_password = ''; }
class GFForms {}
function get_queried_object() { $post = new WP_Post; if ( in_array( $GLOBALS['case'], array( 'password', 'unlocked-password' ), true ) ) { $post->post_password = 'test'; } return $post; }
if ( 'rest' === $case ) { define( 'REST_REQUEST', true ); }
if ( in_array( $case, array( 'legacy', 'legacy-new-site' ), true ) ) { require __DIR__ . '/fixtures/cache/guard-1.0.3.php'; }
require dirname( __DIR__ ) . '/includes/public-cache-guard.php';
$before = ob_get_level(); foreach ( $GLOBALS['guard_hooks']['template_redirect'] as $call ) { $call(); }
header( 'X-Test-Buffers: ' . ( ob_get_level() - $before ) );
header( 'X-Test-No-Cache: ' . ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ? 'yes' : 'no' ) );
header( 'Content-Type: text/html; charset=utf-8' );
$html = "<!doctype html>\n<html><body>Literal &amp; bytes 🦉\n";
if ( in_array( $case, array( 'form-hook', 'legacy', 'legacy-new-site' ), true ) ) { foreach ( $GLOBALS['guard_hooks']['gform_pre_render'] as $call ) { $call( array( 'id' => 1 ) ); } }
if ( 'form-fragment' === $case ) { $html .= '<form id="gform_7"><input value="unchanged"></form>'; }
$html .= '</body></html>';
echo $html;
