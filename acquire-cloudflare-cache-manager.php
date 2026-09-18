<?php
/**
 * Plugin Name: Acquire Cloudflare Cache Manager
 * Plugin URI:  https://acquiredigital.co
 * Description: Cloudflare cache manager for standalone WordPress and multisite networks, with per-site purging, optional Cache Reserve and Smart Tiered Cache support, cache and hardening rule setup, and GitHub release update checks.
 * Version:     3.7.4
 * Author:      Kyle Burns
 * Author URI:  https://acquiredigital.co
 * Network:     true
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Text Domain: acquire-cloudflare-cache-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-acfcm-network-queue.php';
require_once __DIR__ . '/includes/class-acfcm-site-object-cache.php';
require_once __DIR__ . '/includes/class-acfcm-defense.php';
require_once __DIR__ . '/includes/class-acfcm-runtime-guard.php';
require_once __DIR__ . '/includes/class-acfcm-cache-policy.php';
require_once __DIR__ . '/includes/class-acfcm-cache-admin.php';

if ( ! class_exists( 'Acquire_Cloudflare_Cache_Manager' ) ) :

final class Acquire_Cloudflare_Cache_Manager {
    const VERSION       = '3.7.4';
    const DEFAULT_GITHUB_REPO = 'djknucklehead/acquire-cloudflare-cache-manager';
    const SLUG          = 'acquire-cloudflare-cache-manager';
    const BASENAME      = 'acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php';
    const MODE_AUTO     = 'auto';
    const MODE_ENABLED  = 'enabled';
    const MODE_DISABLED = 'disabled';
    const CACHE_RULE_PHASE = 'http_request_cache_settings';
    const WAF_CUSTOM_RULE_PHASE = 'http_request_firewall_custom';
    const RATE_LIMIT_RULE_PHASE = 'http_ratelimit';
    const CACHE_EVERYTHING_RULE_NAME = 'Cache Everything [Template]';
    const CACHE_RESERVE_RULE_PREFIX = 'ACFCM - Cache Reserve: ';
    const CACHE_RESERVE_MINIMUM_FILE_SIZE = 50000;
    const TIERED_CACHE_SETTING_PATH = 'argo/tiered_caching';
    const SMART_TIERED_CACHE_SETTING_PATH = 'cache/tiered_cache_smart_topology_enable';
    const BYPASS_RULE_NAME = 'BYPASS';
    const HARDENING_WP_PROBES_RULE_NAME = 'ACFCM - Block WordPress exploit probes';
    const HARDENING_XMLRPC_RULE_NAME = 'ACFCM - Block XML-RPC';
    const HARDENING_LEGAL_QUERY_CHALLENGE_RULE_NAME = 'ACFCM - Challenge legal-page query strings';
    const HARDENING_LEGAL_QUERY_RATE_LIMIT_RULE_NAME = 'ACFCM - Rate limit legal-page query strings';

    /** @var array|null */
    private static $release_cache = null;

    private static $pending_content = array();
    private static $clearing_wpengine = false;

    public static function init() {
        ACFCM_Network_Queue::init();
        ACFCM_Cache_Admin::init();
        // Subsite-facing behavior. Each callback exits immediately unless the current site is enabled.
        add_action( 'send_headers', array( __CLASS__, 'send_logged_in_nocache_headers' ) );
        add_action( 'save_post', array( __CLASS__, 'purge_on_save_post' ), 10, 3 );
        add_action( 'future_to_publish', array( __CLASS__, 'purge_on_future_to_publish' ), 10, 1 );
        add_action( 'before_delete_post', array( __CLASS__, 'purge_on_before_delete_post' ), 10, 1 );

        add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'capture_before_post_write' ), 10, 2 );
        add_action( 'init', array( __CLASS__, 'recover_purge_schedule' ) );
        add_action( 'pre_post_update', array( __CLASS__, 'capture_public_post' ), 10, 1 );
        add_action( 'pre_delete_term', array( __CLASS__, 'capture_term_posts' ), 10, 2 );
        add_action( 'delete_term_relationships', array( __CLASS__, 'capture_public_post' ), 10, 1 );
        add_action( 'add_term_relationship', array( __CLASS__, 'capture_public_post' ), 10, 1 );
        add_action( 'shutdown', array( __CLASS__, 'flush_content_purges' ), PHP_INT_MAX );
        add_action( 'acfcm_retry_purge', array( __CLASS__, 'retry_purge' ), 10, 2 );

        // Admin UI and actions.
        add_action( 'admin_menu', array( __CLASS__, 'register_subsite_settings_page' ) );
        add_action( 'network_admin_menu', array( __CLASS__, 'register_network_settings_page' ) );
        add_action( 'admin_bar_menu', array( __CLASS__, 'register_toolbar_menu' ), 100 );

        add_action( 'admin_post_acfcm_purge_home', array( __CLASS__, 'handle_purge_home' ) );
        add_action( 'admin_post_acfcm_purge_site_everything', array( __CLASS__, 'handle_purge_site_everything' ) );
        add_action( 'admin_post_acfcm_purge_network_everything', array( __CLASS__, 'handle_purge_network_everything' ) );
        add_action( 'admin_post_acfcm_purge_network_site', array( __CLASS__, 'handle_purge_network_site' ) );
        add_action( 'admin_post_acfcm_install_cache_rules', array( __CLASS__, 'handle_install_cache_rules' ) );
        add_action( 'admin_post_acfcm_install_network_site_cache_rules', array( __CLASS__, 'handle_install_network_site_cache_rules' ) );
        add_action( 'admin_post_acfcm_install_network_site_cache_security_rules', array( __CLASS__, 'handle_install_network_site_cache_security_rules' ) );
        add_action( 'admin_post_acfcm_install_hardening_rules', array( __CLASS__, 'handle_install_hardening_rules' ) );
        add_action( 'admin_post_acfcm_install_network_hardening_rules', array( __CLASS__, 'handle_install_network_hardening_rules' ) );
        add_action( 'admin_post_acfcm_clear_log', array( __CLASS__, 'handle_clear_log' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
        add_action( 'network_admin_notices', array( __CLASS__, 'admin_notices' ) );

        // Network update purge hooks.
        add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_network_after_wp_update' ), 10, 2 );
        add_action( 'automatic_updates_complete', array( __CLASS__, 'purge_network_after_automatic_updates' ), 10, 1 );

        // Optional best-effort hooks other tools can fire after clearing server/WPEngine cache.
        add_action( 'acfcm_external_cache_cleared', array( __CLASS__, 'purge_network_after_external_cache_clear' ), 10, 1 );
        add_action( 'wpe_cache_flush', array( __CLASS__, 'purge_network_after_external_cache_clear' ), 10, 1 );
        add_action( 'wpe_purge_cache', array( __CLASS__, 'purge_network_after_external_cache_clear' ), 10, 1 );

        // GitHub release updater.
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'github_check_for_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'github_plugins_api' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( __CLASS__, 'github_fix_source_folder' ), 10, 4 );
    }

    public static function activation_check( $network_wide ) {
        if ( is_multisite() && ! $network_wide ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die(
                esc_html__( 'Acquire Cloudflare Cache Manager is intended to be Network Activated on multisite installs.', 'acquire-cloudflare-cache-manager' ),
                esc_html__( 'Network Activation Required', 'acquire-cloudflare-cache-manager' ),
                array( 'back_link' => true )
            );
        }
    }

    /* -------------------------------------------------------------------------
     * Configuration helpers
     * ---------------------------------------------------------------------- */

    public static function get_cf_api_token() {
        // New preferred constant.
        if ( defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ACFCM_CLOUDFLARE_API_TOKEN ) {
            return ACFCM_CLOUDFLARE_API_TOKEN;
        }

        // Backward-compatible constant from older plugin versions.
        if ( defined( 'CLOUDFLARE_API_TOKEN' ) && CLOUDFLARE_API_TOKEN ) {
            return CLOUDFLARE_API_TOKEN;
        }

        // Network-wide fallback.
        $network_token = get_site_option( 'acfcm_cloudflare_api_token', '' );
        if ( ! empty( $network_token ) ) {
            return $network_token;
        }

        // Backward-compatible per-site fallback from older plugin versions.
        $site_token = get_option( 'cloudflare_api_token', '' );
        return $site_token ? $site_token : '';
    }

    public static function cf_token_source_label() {
        if ( defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ACFCM_CLOUDFLARE_API_TOKEN ) {
            return 'wp-config.php constant ACFCM_CLOUDFLARE_API_TOKEN';
        }
        if ( defined( 'CLOUDFLARE_API_TOKEN' ) && CLOUDFLARE_API_TOKEN ) {
            return 'wp-config.php constant CLOUDFLARE_API_TOKEN';
        }
        if ( get_site_option( 'acfcm_cloudflare_api_token', '' ) ) {
            return 'network option';
        }
        if ( get_option( 'cloudflare_api_token', '' ) ) {
            return 'current site option';
        }
        return 'not set';
    }

    public static function has_network_cf_api_token() {
        if ( defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ACFCM_CLOUDFLARE_API_TOKEN ) {
            return true;
        }
        if ( defined( 'CLOUDFLARE_API_TOKEN' ) && CLOUDFLARE_API_TOKEN ) {
            return true;
        }
        return is_multisite() && (bool) get_site_option( 'acfcm_cloudflare_api_token', '' );
    }

    public static function is_subsite_cloudflare_management_locked() {
        return is_multisite() && self::has_network_cf_api_token() && ! current_user_can( 'manage_network_options' );
    }

    public static function current_user_can_manage_site_cloudflare() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        if ( is_multisite() && self::has_network_cf_api_token() && ! current_user_can( 'manage_network_options' ) ) {
            return false;
        }
        return true;
    }

    public static function current_user_can_purge_site_cloudflare() {
        return current_user_can( 'manage_options' );
    }

    public static function cloudflare_permission_error_result() {
        return array(
            'success' => false,
            'code'    => 0,
            'message' => 'Network Admin permission is required while this network is using a shared Cloudflare API token.',
            'body'    => '',
            'json'    => null,
            'result'  => null,
        );
    }

    public static function get_zone_id( $blog_id = 0 ) {
        if ( $blog_id && is_multisite() && (int) get_current_blog_id() !== (int) $blog_id ) {
            switch_to_blog( $blog_id );
            $zone_id = get_option( 'cloudflare_zone_id', '' );
            restore_current_blog();
            return trim( (string) $zone_id );
        }

        return trim( (string) get_option( 'cloudflare_zone_id', '' ) );
    }

    public static function get_site_mode( $blog_id = 0 ) {
        if ( $blog_id && is_multisite() && (int) get_current_blog_id() !== (int) $blog_id ) {
            switch_to_blog( $blog_id );
            $mode = get_option( 'acfcm_cloudflare_mode', self::MODE_AUTO );
            restore_current_blog();
        } else {
            $mode = get_option( 'acfcm_cloudflare_mode', self::MODE_AUTO );
        }

        $allowed = array( self::MODE_AUTO, self::MODE_ENABLED, self::MODE_DISABLED );
        return in_array( $mode, $allowed, true ) ? $mode : self::MODE_AUTO;
    }

    public static function is_site_enabled( $blog_id = 0 ) {
        $mode = self::get_site_mode( $blog_id );

        if ( self::MODE_DISABLED === $mode ) {
            return false;
        }

        if ( self::MODE_ENABLED === $mode ) {
            return true;
        }

        // Auto mode: engage only if an existing/new Zone ID is present.
        return (bool) self::get_zone_id( $blog_id );
    }

    public static function is_content_auto_purge_enabled() {
        if ( ! self::is_site_enabled() ) {
            return false;
        }
        return '0' !== (string) get_option( 'acfcm_content_auto_purge', '1' );
    }

    public static function is_cache_reserve_enabled( $blog_id = 0 ) {
        if ( $blog_id && is_multisite() && (int) get_current_blog_id() !== (int) $blog_id ) {
            switch_to_blog( $blog_id );
            $enabled = get_option( 'acfcm_cache_reserve_enabled', '0' );
            restore_current_blog();
        } else {
            $enabled = get_option( 'acfcm_cache_reserve_enabled', '0' );
        }

        return '1' === (string) $enabled;
    }

    public static function is_smart_tiered_cache_enabled( $blog_id = 0 ) {
        if ( $blog_id && is_multisite() && (int) get_current_blog_id() !== (int) $blog_id ) {
            switch_to_blog( $blog_id );
            $enabled = get_option( 'acfcm_smart_tiered_cache_enabled', '0' );
            restore_current_blog();
        } else {
            $enabled = get_option( 'acfcm_smart_tiered_cache_enabled', '0' );
        }

        return '1' === (string) $enabled;
    }

    public static function get_site_hostname( $blog_id = 0 ) {
        $url      = $blog_id ? get_home_url( $blog_id, '/' ) : home_url( '/' );
        $hostname = strtolower( rtrim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) );

        if ( ! $hostname || ! preg_match( '/^[a-z0-9.-]+$/', $hostname ) ) {
            return '';
        }

        return $hostname;
    }

    public static function is_logged_in_nocache_enabled() {
        if ( ! self::is_site_enabled() ) {
            return false;
        }
        return '0' !== (string) get_option( 'acfcm_logged_in_nocache', '1' );
    }

    public static function network_auto_purge_enabled() {
        return '0' !== (string) get_site_option( 'acfcm_network_auto_purge', '1' );
    }

    public static function network_update_types() {
        $types = get_site_option( 'acfcm_network_update_types', array( 'core', 'plugin', 'theme' ) );
        if ( ! is_array( $types ) ) {
            $types = array( 'core', 'plugin', 'theme' );
        }
        return array_values( array_intersect( $types, array( 'core', 'plugin', 'theme', 'translation' ) ) );
    }

    public static function network_external_cache_purge_enabled() {
        return '0' !== (string) get_site_option( 'acfcm_network_external_cache_purge', '1' );
    }

    public static function github_repo() {
        if ( defined( 'ACFCM_GITHUB_REPO' ) && ACFCM_GITHUB_REPO ) {
            return trim( (string) ACFCM_GITHUB_REPO );
        }

        $saved_repo = trim( (string) get_site_option( 'acfcm_github_repo', '' ) );
        if ( $saved_repo ) {
            return $saved_repo;
        }

        // Baked-in default for this plugin's update source.
        return self::DEFAULT_GITHUB_REPO;
    }

    public static function github_asset_url( $path, $ref = 'main' ) {
        $repo = self::github_repo();
        if ( ! preg_match( '#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $repo, $matches ) ) {
            return '';
        }

        $owner = rawurlencode( $matches[1] );
        $name  = rawurlencode( $matches[2] );
        $ref   = rawurlencode( $ref ? $ref : 'main' );
        $path  = ltrim( (string) $path, '/' );
        $parts = array_map( 'rawurlencode', explode( '/', $path ) );

        return 'https://raw.githubusercontent.com/' . $owner . '/' . $name . '/' . $ref . '/' . implode( '/', $parts );
    }

    public static function github_plugin_icons() {
        $icon_svg = self::github_asset_url( 'assets/icon.svg' );
        if ( ! $icon_svg ) {
            return array();
        }

        return array(
            'svg'     => esc_url_raw( $icon_svg ),
            'default' => esc_url_raw( $icon_svg ),
        );
    }

    public static function github_token() {
        if ( defined( 'ACFCM_GITHUB_TOKEN' ) && ACFCM_GITHUB_TOKEN ) {
            return ACFCM_GITHUB_TOKEN;
        }
        return trim( (string) get_site_option( 'acfcm_github_token', '' ) );
    }

    /* -------------------------------------------------------------------------
     * Cloudflare API
     * ---------------------------------------------------------------------- */

    public static function cloudflare_request( $method, $zone_id, $path, $payload = null, $timeout = 20 ) {
        $zone_id   = trim( (string) $zone_id );
        $api_token = self::get_cf_api_token();
        $path      = ltrim( (string) $path, '/' );

        if ( empty( $zone_id ) || empty( $api_token ) ) {
            return array(
                'success' => false,
                'code'    => 0,
                'message' => 'Missing Cloudflare Zone ID or API token.',
                'body'    => '',
            );
        }

        $args = array(
            'method'  => strtoupper( (string) $method ),
            'timeout' => $timeout,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type'  => 'application/json',
            ),
        );

        if ( null !== $payload ) {
            $args['body'] = wp_json_encode( $payload );
        }

        $zone_id_path = rawurlencode( $zone_id );

        $response = wp_remote_request(
            "https://api.cloudflare.com/client/v4/zones/{$zone_id_path}" . ( '' !== $path ? '/' . $path : '' ),
            $args
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'code'    => 0,
                'message' => $response->get_error_message(),
                'transport_error' => true,
                'retryable' => true,
                'body'    => '',
                'json'    => null,
                'result'  => null,
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        $success = ( $code >= 200 && $code < 300 && is_array( $json ) && ! empty( $json['success'] ) );
        $message = $success ? 'OK' : wp_remote_retrieve_response_message( $response );

        if ( ! $success && is_array( $json ) && ! empty( $json['errors'] ) ) {
            $message = wp_json_encode( $json['errors'] );
        }

        return array(
            'retry_after' => self::retry_after_seconds( wp_remote_retrieve_header( $response, 'retry-after' ) ),
            'retryable' => 408 === $code || 429 === $code || $code >= 500 || ( $code >= 200 && $code < 300 && ! $success ),
            'success' => $success,
            'code'    => $code,
            'message' => $message,
            'body'    => $body,
            'json'    => is_array( $json ) ? $json : null,
            'result'  => is_array( $json ) && array_key_exists( 'result', $json ) ? $json['result'] : null,
        );
    }

    public static function cloudflare_post( $zone_id, array $payload, $timeout = 20 ) {
        return self::cloudflare_request( 'POST', $zone_id, 'purge_cache', $payload, $timeout );
    }

    public static function purge_zone_everything( $zone_id, $immediate_edge = false ) {
        return self::reliable_purge( $zone_id, array( 'purge_everything' => true ), false, $immediate_edge );
    }

    /** Explicit site button only; maintenance/network purges retain their own semantics. */
    public static function purge_current_site_everything() {
        if ( ! ACFCM_Site_Object_Cache::clear() ) {
            return array( 'success' => false, 'code' => 0, 'message' => 'Site WordPress object-cache invalidation could not be verified; page/CDN purge was not sent.' );
        }
        // Read configuration again after invalidating potentially stale options.
        return self::purge_zone_everything( self::get_zone_id(), true );
    }

    public static function enable_smart_tiered_cache( $zone_id ) {
        $zone_id = trim( (string) $zone_id );
        if ( empty( $zone_id ) ) {
            return array(
                'success' => false,
                'code'    => 0,
                'message' => 'Missing Cloudflare Zone ID.',
                'body'    => '',
                'json'    => null,
                'result'  => null,
            );
        }

        $payload = array( 'value' => 'on' );
        $tiered_cache_result = self::cloudflare_request( 'PATCH', $zone_id, self::TIERED_CACHE_SETTING_PATH, $payload, 20 );

        if ( empty( $tiered_cache_result['success'] ) ) {
            return self::cloudflare_setting_error_result( $tiered_cache_result, 'Tiered Cache could not be enabled' );
        }

        $result  = self::cloudflare_request( 'PATCH', $zone_id, self::SMART_TIERED_CACHE_SETTING_PATH, $payload, 20 );

        if ( ! empty( $result['success'] ) ) {
            return $result;
        }

        if ( in_array( (int) $result['code'], array( 404, 405 ), true ) ) {
            $result = self::cloudflare_request( 'POST', $zone_id, self::SMART_TIERED_CACHE_SETTING_PATH, $payload, 20 );
        }

        if ( empty( $result['success'] ) ) {
            return self::cloudflare_setting_error_result( $result, 'Smart Tiered Cache topology could not be selected' );
        }

        return $result;
    }

    public static function cloudflare_setting_error_result( array $result, $prefix ) {
        $detail = ! empty( $result['message'] ) ? self::short_notice_message( $result['message'] ) : 'Cloudflare request failed.';
        $result['success'] = false;
        $result['message'] = trim( (string) $prefix ) . ': ' . $detail;
        return $result;
    }

    public static function install_recommended_cache_rules( $zone_id ) {
        delete_option( 'acfcm_cache_preview' );
        delete_option( 'acfcm_cache_preview_error' );
        try {
            ACFCM_Cache_Policy::preview( trim( (string) $zone_id ), true );
            return ACFCM_Defense::result( true, 'Read-only preview prepared. Review the cache policy section and explicitly apply it; no Cloudflare rules were changed.' );
        } catch ( RuntimeException $e ) {
            update_option( 'acfcm_cache_preview_error', array( 'user' => get_current_user_id(), 'message' => $e->getMessage() ), false );
            return ACFCM_Defense::result( false, $e->getMessage() );
        }
    }

    public static function install_recommended_cache_rules_with_fallbacks( $zone_id, array $cache_reserve_hostnames ) {
        return self::install_recommended_cache_rules( $zone_id );
    }

    public static function append_cache_rules_message( $message, $addition ) {
        $message  = trim( (string) $message );
        $addition = trim( (string) $addition );

        if ( '' === $addition ) {
            return '' === $message ? 'OK' : $message;
        }

        if ( '' === $message || 'OK' === $message ) {
            return $addition;
        }

        return $message . ' ' . $addition;
    }

    public static function cache_rules_success_message_for_attempt( array $attempt, $cache_reserve_requested = false ) {
        $messages = array();

        if ( empty( $attempt['custom_key'] ) ) {
            $messages[] = 'Installed without the marketing query-string cache key override because Cloudflare does not entitle this zone to that setting.';
        }

        if ( $cache_reserve_requested && empty( $attempt['cache_reserve'] ) ) {
            $messages[] = 'Installed without Cache Reserve eligibility because Cloudflare does not entitle this zone to that setting or Cache Reserve is not enabled.';
        }

        return empty( $messages ) ? 'OK' : 'OK. ' . implode( ' ', $messages );
    }

    public static function upsert_recommended_cache_rules( $zone_id, array $rules ) {
        return ACFCM_Defense::result( false, 'Direct legacy cache writes are disabled. Generate and approve a controlled cache-policy preview.' );
    }

    public static function is_custom_cache_key_entitlement_error( array $result ) {
        $message = isset( $result['message'] ) ? (string) $result['message'] : '';
        return false !== stripos( $message, 'custom cache key' ) || false !== stripos( $message, 'custom_key' );
    }

    public static function is_cache_reserve_entitlement_error( array $result ) {
        $message = isset( $result['message'] ) ? (string) $result['message'] : '';
        if ( false === stripos( $message, 'cache reserve' ) && false === stripos( $message, 'cache_reserve' ) ) {
            return false;
        }

        foreach ( array( 'not entitled', 'not enabled', 'not allowed', 'requires', 'subscription', 'plan', 'permission' ) as $needle ) {
            if ( false !== stripos( $message, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    public static function recommended_cache_rules( $include_custom_cache_key = true, array $cache_reserve_hostnames = array() ) {
        $rules = array( self::recommended_cache_everything_rule( $include_custom_cache_key ) );

        foreach ( array_unique( array_filter( $cache_reserve_hostnames ) ) as $hostname ) {
            $rule = self::recommended_cache_reserve_rule( $hostname );
            if ( $rule ) {
                $rules[] = $rule;
            }
        }

        // Keep the WordPress bypass last among plugin-managed cache rules so it
        // overrides cache eligibility for admin, preview, API, and logged-in traffic.
        $rules[] = self::recommended_bypass_rule();

        return $rules;
    }

    public static function marketing_query_parameters_to_ignore() {
        $parameters = array(
            '_gl',
            'dclid',
            'epik',
            'fbclid',
            'gad_source',
            'gbraid',
            'gclid',
            'igshid',
            'li_fat_id',
            'mc_cid',
            'mc_eid',
            'msclkid',
            'ttclid',
            'twclid',
            'utm_campaign',
            'utm_content',
            'utm_creative_format',
            'utm_id',
            'utm_marketing_tactic',
            'utm_medium',
            'utm_source',
            'utm_source_platform',
            'utm_term',
            'wbraid',
            'yclid',
        );

        $parameters = apply_filters( 'acfcm_marketing_query_parameters_to_ignore', $parameters );
        if ( ! is_array( $parameters ) ) {
            return array();
        }

        $parameters = array_map( 'sanitize_key', $parameters );
        $parameters = array_filter( $parameters );

        return array_values( array_unique( $parameters ) );
    }

    public static function recommended_cache_everything_rule( $include_custom_cache_key = true ) {
        $action_parameters = array(
            'cache'                     => true,
            'edge_ttl'                  => array(
                'mode'            => 'override_origin',
                'default'         => 604800,
                'status_code_ttl' => array(
                    array(
                        'status_code_range' => array(
                            'to' => 299,
                        ),
                        'value'             => 86400,
                    ),
                    array(
                        'status_code' => 300,
                        'value'       => 0,
                    ),
                    array(
                        'status_code' => 301,
                        'value'       => 86400,
                    ),
                    array(
                        'status_code_range' => array(
                            'from' => 302,
                            'to'   => 303,
                        ),
                        'value'             => 0,
                    ),
                    array(
                        'status_code' => 304,
                        'value'       => 86400,
                    ),
                    array(
                        'status_code_range' => array(
                            'from' => 305,
                            'to'   => 403,
                        ),
                        'value'             => 0,
                    ),
                    array(
                        'status_code' => 404,
                        'value'       => 7200,
                    ),
                    array(
                        'status_code_range' => array(
                            'from' => 405,
                            'to'   => 409,
                        ),
                        'value'             => 0,
                    ),
                    array(
                        'status_code' => 410,
                        'value'       => 7200,
                    ),
                    array(
                        'status_code_range' => array(
                            'from' => 411,
                        ),
                        'value'             => 0,
                    ),
                ),
            ),
            'cache_reserve'             => array(
                'eligible' => false,
            ),
            'origin_error_page_passthru' => true,
        );

        if ( $include_custom_cache_key ) {
            $marketing_query_parameters = self::marketing_query_parameters_to_ignore();
            if ( ! empty( $marketing_query_parameters ) ) {
                $action_parameters['cache_key'] = array(
                    'cache_deception_armor'     => false,
                    'ignore_query_strings_order' => false,
                    'custom_key'                => array(
                        'query_string' => array(
                            'exclude' => array(
                                'list' => $marketing_query_parameters,
                            ),
                        ),
                    ),
                );
            }
        }

        return array(
            'description'       => self::CACHE_EVERYTHING_RULE_NAME,
            'expression'        => 'true',
            'action'            => 'set_cache_settings',
            'enabled'           => true,
            'action_parameters' => $action_parameters,
        );
    }

    public static function recommended_cache_reserve_rule( $hostname ) {
        $hostname = strtolower( rtrim( (string) $hostname, '.' ) );
        if ( ! $hostname || ! preg_match( '/^[a-z0-9.-]+$/', $hostname ) ) {
            return null;
        }

        return array(
            'description'       => self::CACHE_RESERVE_RULE_PREFIX . $hostname,
            'expression'        => 'http.host eq "' . $hostname . '"',
            'action'            => 'set_cache_settings',
            'enabled'           => true,
            'action_parameters' => array(
                'cache'         => true,
                'cache_reserve' => array(
                    'eligible'          => true,
                    'minimum_file_size' => self::CACHE_RESERVE_MINIMUM_FILE_SIZE,
                ),
            ),
        );
    }

    public static function recommended_bypass_rule() {
        return array(
            'description'       => self::BYPASS_RULE_NAME,
            'expression'        => self::recommended_bypass_expression(),
            'action'            => 'set_cache_settings',
            'enabled'           => true,
            'action_parameters' => array(
                'cache'       => false,
                'browser_ttl' => array(
                    'mode' => 'bypass_by_default',
                ),
            ),
        );
    }

    public static function recommended_bypass_expression() {
        $conditions = array(
            'starts_with(http.request.uri.path, "/wp-login")',
            'starts_with(http.request.uri.path, "/wp-admin")',
            'http.request.uri.path eq "/xmlrpc.php"',
            'starts_with(http.request.uri.path, "/wp-json/")',
            'http.request.uri.query contains "preview=true"',
            'http.cookie contains "wordpress_logged_in_"',
            'http.cookie contains "wp-postpass_"',
            'http.cookie contains "wordpress_sec_"',
        );

        $guards = array(
            'not starts_with(http.request.uri.path, "/wp-content/")',
            'not starts_with(http.request.uri.path, "/wp-includes/")',
        );

        foreach ( array( 'css', 'js', 'map', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm' ) as $extension ) {
            $guards[] = 'not ends_with(lower(http.request.uri.path), ".' . $extension . '")';
        }

        return "(\n    " . implode( "\n    or ", $conditions ) . "\n)\n    and " . implode( "\n    and ", $guards );
    }

    public static function merge_recommended_cache_rules( array $existing_rules, array $recommended_rules ) {
        throw new RuntimeException( 'Cache rules require an ownership-checked migration preview; description-based merging is disabled.' );
    }

    public static function hardening_options_from_request( array $request ) {
        return array(
            'defense_baseline'       => ! empty( $request['acfcm_hardening_defense_baseline'] ),
            'wordpress_probes'       => ! empty( $request['acfcm_hardening_wordpress_probes'] ),
            'xmlrpc'                 => ! empty( $request['acfcm_hardening_xmlrpc'] ),
            'legal_query_challenge'  => ! empty( $request['acfcm_hardening_legal_query_challenge'] ),
            'legal_query_rate_limit' => ! empty( $request['acfcm_hardening_legal_query_rate_limit'] ),
        );
    }

    public static function has_selected_hardening_options( array $options ) {
        foreach ( $options as $enabled ) {
            if ( ! empty( $enabled ) ) {
                return true;
            }
        }
        return false;
    }

    public static function default_network_site_security_options() {
        return array( 'defense_baseline' => true );
    }

    public static function install_cache_and_default_security_rules( $zone_id ) {
        $r = self::install_recommended_cache_rules( $zone_id );
        if ( ! empty( $r['success'] ) ) {
            try {
                $p = get_option( 'acfcm_cache_preview', array() );
                $p['defense_before'] = ACFCM_Defense::read( $zone_id );
                $p['defense_plan'] = ACFCM_Defense::plan( $zone_id, $p['defense_before'], array( 'defense_baseline' => true ) );
                if ( strlen( wp_json_encode( $p ) ) > 1048576 ) { throw new RuntimeException( 'Combined preview exceeds the 1 MB backup limit.' ); }
                $p['combined'] = true; update_option( 'acfcm_cache_preview', $p, false );
            } catch ( RuntimeException $e ) {
                delete_option( 'acfcm_cache_preview' );
                update_option( 'acfcm_cache_preview_error', array( 'user' => get_current_user_id(), 'message' => $e->getMessage() ), false );
                return ACFCM_Defense::result( false, $e->getMessage() );
            }
            $r['message'] .= ' Combined setup also requires explicit security-defense approval in the preview.';
        }
        return $r;
    }

    public static function combine_cache_security_results( array $cache_result, array $security_result ) {
        $success = ! empty( $cache_result['success'] ) && ! empty( $security_result['success'] );
        $warning = ! empty( $cache_result['warning'] ) || ! empty( $security_result['warning'] );
        $messages = array();

        if ( empty( $cache_result['success'] ) ) {
            $messages[] = 'Cache rules: ' . ( ! empty( $cache_result['message'] ) ? $cache_result['message'] : 'Cloudflare request failed.' );
        } elseif ( ! empty( $cache_result['message'] ) && 'OK' !== $cache_result['message'] ) {
            $messages[] = 'Cache rules: ' . $cache_result['message'];
        }

        if ( empty( $security_result['success'] ) ) {
            $messages[] = 'Basic security rules: ' . ( ! empty( $security_result['message'] ) ? $security_result['message'] : 'Cloudflare request failed.' );
        } elseif ( ! empty( $security_result['message'] ) && 'OK' !== $security_result['message'] ) {
            $messages[] = 'Basic security rules: ' . $security_result['message'];
        }

        return array(
            'success' => $success,
            'warning' => $warning,
            'code'    => 0,
            'message' => empty( $messages ) ? 'OK' : implode( ' ', $messages ),
            'body'    => '',
            'json'    => null,
            'result'  => null,
        );
    }

    public static function install_recommended_hardening_rules( $zone_id, array $options ) {
        $zone_id = trim( (string) $zone_id );
        if ( empty( $zone_id ) ) {
            return array(
                'success' => false,
                'code'    => 0,
                'message' => 'Missing Cloudflare Zone ID.',
                'body'    => '',
                'json'    => null,
                'result'  => null,
            );
        }

        if ( ! self::has_selected_hardening_options( $options ) ) {
            return array(
                'success' => false,
                'code'    => 0,
                'message' => 'Select defense baseline verification/onboarding. Legacy recommendations are review-only.',
                'body'    => '',
                'json'    => null,
                'result'  => null,
            );
        }

        return ACFCM_Defense::install( $zone_id, $options );
    }

    public static function install_recommended_hardening_rules_for_enabled_zones( array $options ) {
        if ( ! self::has_selected_hardening_options( $options ) ) {
            return array(
                'success' => false,
                'code'    => 0,
                'message' => 'Select defense baseline verification/onboarding. Legacy recommendations are review-only.',
                'body'    => '',
                'json'    => null,
                'result'  => null,
            );
        }

        $zones = self::get_enabled_zones();
        if ( empty( $zones ) ) {
            return array(
                'success' => false,
                'code'    => 0,
                'message' => 'No enabled Cloudflare zones found.',
                'body'    => '',
                'json'    => null,
                'result'  => null,
            );
        }

        $ok = 0;
        $failed = 0;
        $messages = array();

        foreach ( $zones as $zone_id => $zone_data ) {
            $result = self::install_recommended_hardening_rules( $zone_id, $options );
            if ( ! empty( $result['success'] ) ) {
                $ok++;
                continue;
            }

            $failed++;
            if ( ! empty( $result['message'] ) ) {
                $messages[] = $zone_id . ': ' . $result['message'];
            }
        }

        $message = sprintf( '%d zone(s) verified/onboarded, %d need review.', $ok, $failed );
        if ( ! empty( $messages ) ) {
            $message .= ' ' . implode( ' ', array_slice( $messages, 0, 3 ) );
        }

        return array(
            'success' => ( 0 === $failed && $ok > 0 ),
            'code'    => 0,
            'message' => $message,
            'body'    => '',
            'json'    => null,
            'result'  => null,
        );
    }

    // Compatibility entry point for old callers: review only, never replace a ruleset.
    public static function upsert_hardening_ruleset( $zone_id, $phase, $ruleset_name, $ruleset_description, array $rules ) {
        $entry = self::cloudflare_request( 'GET', $zone_id, 'rulesets/phases/' . $phase . '/entrypoint', null, 20 );
        if ( empty( $entry['success'] ) ) { return ACFCM_Defense::result( false, 'Legacy policy could not be verified. Use explicit defense baseline onboarding or review the zone in Cloudflare.' ); }
        try {
            self::merge_hardening_rules( $entry['result']['rules'] ?? array(), $rules );
        } catch ( RuntimeException $e ) {
            return ACFCM_Defense::result( false, $e->getMessage() );
        }
        return ACFCM_Defense::result( true, 'Verified existing legacy rules without firewall writes.' );
    }

    public static function install_hardening_rate_limit_rules( $zone_id, array $options ) {
        return self::install_recommended_hardening_rules( $zone_id, array( 'legal_query_rate_limit' => ! empty( $options['legal_query_rate_limit'] ) ) );
    }

    public static function is_rate_limit_query_field_entitlement_error( array $result ) {
        $message = isset( $result['message'] ) ? (string) $result['message'] : '';
        return false !== stripos( $message, 'http.request.uri.query' )
            && ( false !== stripos( $message, 'not entitled' ) || false !== stripos( $message, 'Advanced Rate Limiting' ) );
    }

    public static function merge_hardening_rules( array $existing_rules, array $recommended_rules ) {
        foreach ( $recommended_rules as $wanted ) {
            $matches = 0;
            foreach ( $existing_rules as $live ) {
                unset( $live['id'], $live['ref'], $live['last_updated'], $live['version'] );
                $comparison = $wanted;
                unset( $comparison['id'], $comparison['ref'], $comparison['last_updated'], $comparison['version'] );
                if ( ACFCM_Defense::fingerprint( array( $live ) ) === ACFCM_Defense::fingerprint( array( $comparison ) ) ) { $matches++; }
            }
            if ( 1 !== $matches ) { throw new RuntimeException( 'Legacy rule is missing, modified or duplicated. Description matching does not establish ownership. Review the zone; no rules were replaced.' ); }
        }
        return $existing_rules;
    }

    public static function combine_hardening_results( array $results ) {
        if ( empty( $results ) ) {
            return array(
                'success' => false,
                'code'    => 0,
                'message' => 'No hardening rules were selected.',
                'body'    => '',
                'json'    => null,
                'result'  => null,
            );
        }

        $success = true;
        $messages = array();

        foreach ( $results as $label => $result ) {
            if ( empty( $result['success'] ) ) {
                $success = false;
                $messages[] = $label . ': ' . ( ! empty( $result['message'] ) ? $result['message'] : 'Cloudflare request failed.' );
                continue;
            }

            if ( ! empty( $result['message'] ) && 'OK' !== $result['message'] ) {
                $messages[] = $label . ': ' . $result['message'];
            }
        }

        return array(
            'success' => $success,
            'code'    => 0,
            'message' => empty( $messages ) ? 'OK' : implode( ' ', $messages ),
            'body'    => '',
            'json'    => null,
            'result'  => null,
        );
    }

    public static function recommended_hardening_waf_rules( array $options ) {
        $rules = array();

        if ( ! empty( $options['wordpress_probes'] ) ) {
            $rules[] = self::recommended_wordpress_probe_rule();
        }

        if ( ! empty( $options['xmlrpc'] ) ) {
            $rules[] = self::recommended_xmlrpc_block_rule();
        }

        if ( ! empty( $options['legal_query_challenge'] ) ) {
            $rules[] = self::recommended_legal_query_challenge_rule();
        }

        return $rules;
    }

    public static function recommended_hardening_rate_limit_rules( array $options, $require_query_string = true ) {
        if ( empty( $options['legal_query_rate_limit'] ) ) {
            return array();
        }

        return array(
            self::recommended_legal_query_rate_limit_rule( $require_query_string ),
        );
    }

    public static function recommended_wordpress_probe_rule() {
        $conditions = array(
            self::root_php_probe_expression(),
            self::wp_core_php_probe_expression(),
            self::fake_wp_admin_probe_expression(),
            self::old_install_probe_expression(),
        );

        return array(
            'description' => self::HARDENING_WP_PROBES_RULE_NAME,
            'expression'  => self::verified_bot_guarded_expression( "(\n    " . implode( "\n    or ", $conditions ) . "\n)" ),
            'action'      => 'block',
            'enabled'     => true,
        );
    }

    public static function recommended_xmlrpc_block_rule() {
        return array(
            'description' => self::HARDENING_XMLRPC_RULE_NAME,
            'expression'  => self::verified_bot_guarded_expression( 'http.request.uri.path eq "/xmlrpc.php"' ),
            'action'      => 'block',
            'enabled'     => true,
        );
    }

    public static function recommended_legal_query_challenge_rule() {
        return array(
            'description' => self::HARDENING_LEGAL_QUERY_CHALLENGE_RULE_NAME,
            'expression'  => self::legal_page_query_expression(),
            'action'      => 'managed_challenge',
            'enabled'     => true,
        );
    }

    public static function recommended_legal_query_rate_limit_rule( $require_query_string = true ) {
        $expression = self::legal_page_rate_limit_expression( $require_query_string );

        return array(
            'description' => self::HARDENING_LEGAL_QUERY_RATE_LIMIT_RULE_NAME,
            'expression'  => $expression,
            'action'      => 'managed_challenge',
            'enabled'     => true,
            'ratelimit'   => array(
                'characteristics'      => array( 'cf.colo.id', 'ip.src' ),
                'period'               => 10,
                'requests_per_period'  => 10,
                'mitigation_timeout'   => 600,
                'counting_expression'  => $expression,
            ),
        );
    }

    public static function legal_page_query_expression() {
        return 'not cf.client.bot and http.request.uri.query ne "" and ' . self::legal_page_path_expression();
    }

    public static function legal_page_rate_limit_expression( $require_query_string = true ) {
        $conditions = array(
            'not cf.client.bot',
        );

        if ( $require_query_string ) {
            $conditions[] = 'http.request.uri.query ne ""';
        }

        $conditions[] = self::legal_page_path_expression();

        return implode( ' and ', $conditions );
    }

    public static function verified_bot_guarded_expression( $expression ) {
        return "not cf.client.bot and (\n    " . str_replace( "\n", "\n    ", trim( (string) $expression ) ) . "\n)";
    }

    public static function root_php_probe_expression() {
        $allowed_paths = array(
            '/index.php',
            '/wp-login.php',
            '/wp-cron.php',
            '/xmlrpc.php',
            '/wp-comments-post.php',
            '/wp-signup.php',
            '/wp-activate.php',
        );

        $guards = array(
            self::php_like_path_expression( 'http.request.uri.path' ),
            'not starts_with(lower(http.request.uri.path), "/wp-admin/")',
            'not starts_with(lower(http.request.uri.path), "/wp-content/")',
            'not starts_with(lower(http.request.uri.path), "/wp-includes/")',
        );

        foreach ( $allowed_paths as $path ) {
            $guards[] = 'lower(http.request.uri.path) ne "' . $path . '"';
        }

        return "(\n    " . implode( "\n    and ", $guards ) . "\n)";
    }

    public static function wp_core_php_probe_expression() {
        return "(\n    (starts_with(lower(http.request.uri.path), \"/wp-content/\") or starts_with(lower(http.request.uri.path), \"/wp-includes/\"))\n    and " . self::php_like_path_expression( 'http.request.uri.path' ) . "\n)";
    }

    public static function fake_wp_admin_probe_expression() {
        $paths = array(
            '/wp-admin/a.php',
            '/wp-admin/alfa.php',
            '/wp-admin/wp.php',
            '/wp-admin/classwithtostring.php',
            '/wp-admin/js/index.php',
            '/wp-admin/maint/index.php',
        );

        $conditions = array();
        foreach ( $paths as $path ) {
            $conditions[] = 'lower(http.request.uri.path) eq "' . $path . '"';
        }

        return "(\n    " . implode( "\n    or ", $conditions ) . "\n)";
    }

    public static function old_install_probe_expression() {
        $paths = array( 'old', 'new', 'wp', 'wordpress', 'backup' );
        $conditions = array();

        foreach ( $paths as $path ) {
            $conditions[] = 'lower(http.request.uri.path) eq "/' . $path . '"';
            $conditions[] = 'starts_with(lower(http.request.uri.path), "/' . $path . '/")';
        }

        return "(\n    " . implode( "\n    or ", $conditions ) . "\n)";
    }

    public static function php_like_path_expression( $path_expression ) {
        return 'lower(' . $path_expression . ') contains ".php"';
    }

    public static function legal_page_path_expression() {
        $conditions = array(
            'lower(http.request.uri.path) eq "/privacy-policy"',
            'lower(http.request.uri.path) eq "/privacy-policy/"',
            'lower(http.request.uri.path) eq "/terms-and-conditions"',
            'lower(http.request.uri.path) eq "/terms-and-conditions/"',
        );

        return "(\n    " . implode( "\n    or ", $conditions ) . "\n)";
    }

    public static function prepare_ruleset_rule_for_update( array $rule ) {
        unset( $rule['last_updated'], $rule['version'] );
        return $rule;
    }

    public static function purge_urls_for_current_site( array $urls, $defer = false ) {
        $zone_id = self::get_zone_id();
        $urls    = self::normalize_urls( $urls );

        if ( empty( $zone_id ) || empty( $urls ) ) {
            return array();
        }

        $results = array();
        foreach ( array_chunk( $urls, 30 ) as $batch ) {
            $results[] = self::reliable_purge( $zone_id, array( 'files' => array_values( $batch ) ), $defer );
        }
        return $results;
    }

    // WP-Cron's shared option can lose an event during concurrent scheduling.
    // Reconcile durable jobs on origin requests, including explicit cron requests.
    private static function read_purge_jobs() {
        global $wpdb;
        $jobs = array();
        $rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'acfcm\_purge\_job\_%' LIMIT 100" );
        foreach ( $rows as $row ) {
            $job = maybe_unserialize( $row->option_value );
            if ( is_array( $job ) && isset( $job['id'], $job['due'], $job['created'] ) ) {
                $jobs[ $row->option_name ] = $job;
            }
        }
        return $jobs;
    }

    public static function recover_purge_schedule() {
        foreach ( self::read_purge_jobs() as $key => $job ) {
            self::schedule_purge_job( $key, $job );
        }
    }

    public static function retry_after_seconds( $value ) {
        if ( is_numeric( $value ) ) {
            return max( 0, (int) $value );
        }
        return $value ? max( 0, (int) strtotime( $value ) - time() ) : 0;
    }

    // add_option() uses an upsert and can overwrite a concurrent slot winner.
    // A unique INSERT is required here; duplicate keys must never update a job.
    private static function insert_purge_job( $key, array $job ) {
        global $wpdb;
        $inserted = 1 === $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key,
            maybe_serialize( $job )
        ) );
        wp_cache_delete( $key, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        return $inserted;
    }

    // Compare-and-swap prevents concurrent saves/cron workers from losing newer work.
    private static function replace_purge_job( $key, $old, $new = null ) {
        global $wpdb;
        if ( null === $new ) {
            $sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", $key, maybe_serialize( $old ) );
        } else {
            $sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", maybe_serialize( $new ), $key, maybe_serialize( $old ) );
        }
        $changed = 1 === $wpdb->query( $sql );
        wp_cache_delete( $key, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        return $changed;
    }

    private static function schedule_purge_job( $key, array $job ) {
        $args = array( $key, $job['id'] );
        if ( wp_next_scheduled( 'acfcm_retry_purge', $args ) ) {
            return true;
        }
        return true === wp_schedule_single_event( max( time() + 1, min( $job['due'], $job['created'] + DAY_IN_SECONDS ) ), 'acfcm_retry_purge', $args, true );
    }

    public static function reliable_purge( $zone_id, array $payload, $defer = false, $immediate_edge = false ) {
        if ( self::$clearing_wpengine ) {
            return array( 'success' => false, 'code' => 0, 'message' => 'WP Engine purge already in progress.' );
        }
        if ( isset( $payload['files'] ) ) {
            sort( $payload['files'], SORT_STRING );
        }
        $fingerprint = hash( 'sha256', $zone_id . wp_json_encode( $payload ) );
        $result = array( 'success' => false, 'code' => 0, 'message' => 'Purge queue full or busy; request not sent.', 'urls' => $payload['files'] ?? array() );
        // Fixed slots bound both storage and scheduled events to 100 jobs per site.
        for ( $try = 0; $try < 100; $try++ ) {
            $jobs = self::read_purge_jobs();
            $key = null;
            $old = false;
            for ( $slot = 0; $slot < 100; $slot++ ) {
                $candidate = 'acfcm_purge_job_' . $slot;
                $existing = $jobs[ $candidate ] ?? false;
                if ( $existing && $existing['fingerprint'] === $fingerprint ) {
                    $key = $candidate;
                    $old = $existing;
                    break;
                }
                if ( ! $existing && null === $key ) {
                    $key = $candidate;
                }
            }
            if ( null === $key ) {
                return $result;
            }
            if ( $old ) {
                $job = $old;
                // An edit during an HTTP request must survive that request's completion.
                $job['generation'] = wp_generate_uuid4();
                if ( ! self::replace_purge_job( $key, $old, $job ) ) {
                    continue;
                }
            } else {
                $job = array( 'id' => wp_generate_uuid4(), 'fingerprint' => $fingerprint, 'generation' => wp_generate_uuid4(), 'zone' => $zone_id, 'payload' => $payload, 'attempt' => 0, 'created' => time(), 'due' => time() );
                wp_cache_delete( $key, 'options' );
                wp_cache_delete( 'notoptions', 'options' );
                if ( ! self::insert_purge_job( $key, $job ) ) {
                    continue;
                }
            }
            if ( ! self::schedule_purge_job( $key, $job ) ) {
                $result['message'] = 'Unable to schedule purge recovery; pending job retained.';
                return $result;
            }
            if ( $old || $defer ) {
                $result['message'] = 'Matching purge already pending.';
                $result['queued'] = true;
                return $result;
            }
            return self::run_purge_job( $key, $job['id'], $immediate_edge );
        }
        return $result;
    }

    public static function retry_purge( $key, $id ) {
        if ( ! preg_match( '/^acfcm_purge_job_[0-9]{1,2}$/', $key ) ) {
            return;
        }
        $result = self::run_purge_job( $key, $id );
        if ( ! empty( $result['zone'] ) ) {
            self::log_site_purge( 'purge_retry', $result['zone'], $result['urls'] ?? array(), array( $result ) );
        }
    }

    public static function is_clearing_wpengine() { return self::$clearing_wpengine; }

    public static function maintenance_origin_purge() {
        return is_callable( array( 'WpeCommon', 'http_to_varnish' ) )
            ? self::purge_wpengine_page_cache( array( 'purge_everything' => true ) )
            : array( 'success' => true );
    }

    /** Dispatch only this site's page-cache purge through the installed WP Engine transport. */
    private static function purge_wpengine_page_cache( array $payload ) {
        $failure = array( 'success' => false, 'code' => 0, 'retryable' => true, 'message' => 'WP Engine page-cache purge failed; Cloudflare not sent.' );
        if ( defined( 'WPE_DISABLE_CACHE_PURGING' ) && WPE_DISABLE_CACHE_PURGING ) {
            return $failure;
        }
        $home = wp_parse_url( home_url( '/' ) );
        $host = strtolower( $home['host'] ?? '' );
        if ( ! $host || ! preg_match( '/^[a-z0-9.-]+$/D', $host ) ) {
            return $failure;
        }
        $paths = array();
        if ( ! empty( $payload['purge_everything'] ) ) {
            // A subdirectory network shares a host: retain the current site's path boundary.
            $base = rtrim( $home['path'] ?? '', '/' );
            $paths[] = $base ? '^' . preg_quote( $base, '/' ) . '(/.*|\\?.*)?$' : '.*';
        } else {
            foreach ( $payload['files'] ?? array() as $url ) {
                $parts = wp_parse_url( $url );
                // Never turn a CDN/attachment URL into a purge of an unrelated origin host.
                if ( strtolower( $parts['host'] ?? '' ) !== $host ) {
                    continue;
                }
                $path = $parts['path'] ?? '/';
                $paths[] = '^' . preg_quote( $path, '/' ) . '(\\?.*)?$';
            }
        }
        if ( ! $paths ) {
            return array( 'success' => true ); // No URLs on this origin in this batch.
        }
        self::$clearing_wpengine = true;
        try {
            // Same transport used by WpeCommon::purge_varnish_cache, with explicit host/path scope.
            // It reports dispatch errors, but cannot acknowledge edge-wide completion.
            $response = WpeCommon::http_to_varnish( 'PURGE', $host, array(
                'X-Purge-Host' => '^' . preg_quote( $host, '/' ) . '$',
                'X-Purge-Path' => '(' . implode( '|', array_unique( $paths ) ) . ')',
            ) );
            if ( false === $response || is_wp_error( $response ) ) {
                return $failure;
            }
            return array( 'success' => true );
        } catch ( Throwable $error ) {
            return $failure; // Do not expose platform exception text or credentials.
        } finally {
            self::$clearing_wpengine = false;
        }
    }

    private static function run_purge_job( $key, $id, $immediate_edge = false ) {
        $job = get_option( $key );
        $result = array( 'success' => false, 'code' => 0, 'message' => 'Purge no longer pending.' );
        if ( ! $job || $job['id'] !== $id ) {
            return $result;
        }
        $result['site_id'] = get_current_blog_id();
        $result['zone'] = $job['zone'];
        if ( ! self::is_site_enabled() || self::get_zone_id() !== $job['zone'] ) {
            self::replace_purge_job( $key, $job );
            wp_clear_scheduled_hook( 'acfcm_retry_purge', array( $key, $id ) );
            $result['message'] = 'Purge cancelled: site disabled or zone changed.';
            return $result;
        }
        $result['urls'] = $job['payload']['files'] ?? array();
        $result['attempt'] = $job['attempt'];
        // Shared-token cooldown also slows other sites after an account-level 429.
        $cooldown_key = 'acfcm_purge_cooldown_' . substr( hash( 'sha256', self::get_cf_api_token() ), 0, 24 );
        $due = max( $job['due'], (int) get_site_transient( $cooldown_key ) );
        if ( time() - $job['created'] >= DAY_IN_SECONDS || $job['attempt'] >= 5 ) {
            self::replace_purge_job( $key, $job );
            wp_clear_scheduled_hook( 'acfcm_retry_purge', array( $key, $id ) );
            $result['message'] = 'Purge exhausted (five attempts or 24-hour lifetime).';
            return $result;
        }
        if ( $due > time() ) {
            wp_clear_scheduled_hook( 'acfcm_retry_purge', array( $key, $id ) );
            $job['due'] = $due;
            $result['queued'] = self::schedule_purge_job( $key, $job );
            $result['message'] = $result['queued'] ? 'Purge waiting for backoff or active request.' : 'Unable to schedule purge recovery.';
            return $result;
        }
        $running = $job;
        $running['attempt']++;
        $running['due'] = time() + 120; // Crash recovery lease exceeds the HTTP timeout.
        if ( ! self::replace_purge_job( $key, $job, $running ) ) {
            $result['queued'] = true;
            $result['message'] = 'Another worker claimed this purge.';
            return $result;
        }
        wp_clear_scheduled_hook( 'acfcm_retry_purge', array( $key, $id ) );
        if ( ! self::schedule_purge_job( $key, $running ) ) {
            $result['message'] = 'Unable to schedule purge recovery; pending job retained.';
            return $result;
        }
        $needs_origin = is_callable( array( 'WpeCommon', 'http_to_varnish' ) )
            && ( $job['origin_generation'] ?? '' ) !== $job['generation'];
        if ( $needs_origin ) {
            $response = self::purge_wpengine_page_cache( $job['payload'] );
            if ( ! empty( $response['success'] ) ) {
                $next = $running;
                $next['attempt'] = $job['attempt'];
                $next['origin_generation'] = $job['generation'];
                $next['due'] = time() + ( $immediate_edge ? 0 : 5 );
                $result['message'] = 'WP Engine page-cache purge dispatched; Cloudflare queued.';
                $result['queued'] = true;
                $result['retry_at'] = $next['due'];
                if ( self::replace_purge_job( $key, $running, $next ) ) {
                    wp_clear_scheduled_hook( 'acfcm_retry_purge', array( $key, $id ) );
                    if ( ! self::schedule_purge_job( $key, $next ) ) {
                        $result['message'] = 'Unable to schedule purge recovery; pending job retained.';
                    } elseif ( $immediate_edge ) {
                        // Manual combined action: origin first, then edge in this request.
                        // Re-read/claim the job so concurrent edits and cooldowns still apply.
                        return self::run_purge_job( $key, $id );
                    }
                }
                return $result;
            }
        } else {
            $response = self::cloudflare_post( $job['zone'], $job['payload'], 25 );
        }
        $result = array_merge( $result, $response, array( 'attempt' => $running['attempt'] ) );
        $retry = empty( $response['success'] ) && ! empty( $response['retryable'] );
        if ( $retry && $running['attempt'] < 5 ) {
            $delay = max( 60 * ( 2 ** ( $running['attempt'] - 1 ) ) + wp_rand( 0, 30 ), (int) ( $response['retry_after'] ?? 0 ) );
            if ( 429 === (int) $response['code'] || ! empty( $response['retry_after'] ) ) {
                set_site_transient( $cooldown_key, time() + $delay, $delay );
            }
            $next = $running;
            $next['due'] = time() + $delay;
            if ( self::replace_purge_job( $key, $running, $next ) ) {
                wp_clear_scheduled_hook( 'acfcm_retry_purge', array( $key, $id ) );
                $result['queued'] = self::schedule_purge_job( $key, $next );
            } else {
                $result['queued'] = true; // Newer edit remains behind the recovery lease.
            }
            $result['retry_at'] = $next['due'];
        } else {
            if ( self::replace_purge_job( $key, $running ) ) {
                wp_clear_scheduled_hook( 'acfcm_retry_purge', array( $key, $id ) );
            } else {
                $result['queued'] = true;
            }
            if ( $retry ) {
                $result['message'] = 'Retry attempts exhausted.';
            }
        }
        if ( ! empty( $result['queued'] ) && ! empty( $result['success'] ) ) {
            $result['accepted'] = true;
            $result['success'] = false;
        }
        // Never retain raw API responses or credentials in persistent jobs/logs.
        return $result;
    }

    public static function normalize_urls( array $urls ) {
        $urls = array_filter( array_map( 'esc_url_raw', $urls ) );
        $urls = array_unique( $urls );
        return array_values( $urls );
    }

    /* -------------------------------------------------------------------------
     * URL collection and content-change purge
     * ---------------------------------------------------------------------- */

    public static function post_related_urls( $post_id ) {
        $post_id = (int) $post_id;
        $urls    = array();

        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            $urls[] = $permalink;
        }

        $home = home_url( '/' );
        if ( $home ) {
            $urls[] = $home;
            $urls[] = trailingslashit( $home ) . 'feed/';
        }

        $page_for_posts = (int) get_option( 'page_for_posts' );
        if ( $page_for_posts ) {
            $posts_page = get_permalink( $page_for_posts );
            if ( $posts_page ) {
                $urls[] = $posts_page;
            }
        }

        $post_type = get_post_type( $post_id );
        if ( $post_type ) {
            $archive = get_post_type_archive_link( $post_type );
            if ( $archive ) {
                $urls[] = $archive;
                $urls[] = trailingslashit( $archive ) . 'feed/';
            }
        }

        $author_id = (int) get_post_field( 'post_author', $post_id );
        if ( $author_id ) {
            $author_url = get_author_posts_url( $author_id );
            if ( $author_url ) {
                $urls[] = $author_url;
                $urls[] = trailingslashit( $author_url ) . 'feed/';
            }
        }

        if ( $post_type ) {
            $taxonomies = get_object_taxonomies( $post_type, 'objects' );
            if ( is_array( $taxonomies ) ) {
                foreach ( $taxonomies as $tax ) {
                    $terms = get_the_terms( $post_id, $tax->name );
                    if ( empty( $terms ) || is_wp_error( $terms ) ) {
                        continue;
                    }
                    foreach ( $terms as $term ) {
                        $term_link = get_term_link( $term );
                        if ( ! is_wp_error( $term_link ) ) {
                            $urls[] = $term_link;
                            $urls[] = trailingslashit( $term_link ) . 'feed/';
                        }
                    }
                }
            }
        }

        if ( $post_type ) {
            $urls[] = rest_url( 'wp/v2/' . $post_type . '/' . $post_id );
        } else {
            $urls[] = rest_url( 'wp/v2/posts/' . $post_id );
        }

        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $thumb_url = wp_get_attachment_url( $thumb_id );
            if ( $thumb_url ) {
                $urls[] = $thumb_url;
            }
        }

        return self::normalize_urls( $urls );
    }

    public static function should_purge_post( $post_id ) {
        if ( ! self::is_content_auto_purge_enabled() ) {
            return false;
        }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return false;
        }
        if ( 'publish' !== get_post_status( $post_id ) ) {
            return false;
        }
        $post_type_object = get_post_type_object( get_post_type( $post_id ) );
        if ( ! $post_type_object || empty( $post_type_object->public ) ) {
            return false;
        }
        return true;
    }

    // This filter runs before core can rewrite a published slug with __trashed.
    public static function capture_before_post_write( $empty, $postarr ) {
        if ( ! empty( $postarr['ID'] ) ) {
            self::capture_public_post( (int) $postarr['ID'] );
        }
        return $empty;
    }

    // Capture while the database still contains the public slug, author and terms.
    public static function capture_public_post( $post_id ) {
        if ( self::should_purge_post( $post_id ) ) {
            self::queue_content_post( $post_id, self::post_related_urls( $post_id ) );
        }
    }

    public static function capture_term_posts( $term_id, $taxonomy ) {
        $ids = get_objects_in_term( $term_id, $taxonomy );
        if ( ! is_wp_error( $ids ) ) {
            foreach ( $ids as $id ) {
                self::capture_public_post( $id );
            }
        }
    }

    private static function queue_content_post( $post_id, array $urls = array() ) {
        $blog_id = get_current_blog_id();
        $old = self::$pending_content[ $blog_id ][ $post_id ] ?? array();
        self::$pending_content[ $blog_id ][ $post_id ] = self::normalize_urls( array_merge( $old, $urls ) );
    }

    public static function purge_on_save_post( $post_id, $post, $update ) {
        if ( self::should_purge_post( $post_id ) ) {
            self::queue_content_post( $post_id );
        }
    }

    public static function purge_on_future_to_publish( $post ) {
        if ( $post && ! empty( $post->ID ) ) {
            self::purge_on_save_post( $post->ID, $post, true );
        }
    }

    public static function purge_on_before_delete_post( $post_id ) {
        self::capture_public_post( $post_id );
    }

    public static function flush_content_purges() {
        $pending = self::$pending_content;
        self::$pending_content = array();
        foreach ( $pending as $blog_id => $posts ) {
            $switched = is_multisite() && (int) get_current_blog_id() !== (int) $blog_id;
            if ( $switched ) {
                switch_to_blog( $blog_id );
            }
            try {
                if ( ! self::is_content_auto_purge_enabled() ) {
                    continue;
                }
                $urls = array();
                foreach ( $posts as $post_id => $old_urls ) {
                    $urls = array_merge( $urls, $old_urls );
                    if ( self::should_purge_post( $post_id ) ) {
                        $urls = array_merge( $urls, self::post_related_urls( $post_id ) );
                    }
                }
                $urls = self::normalize_urls( $urls );
                if ( $urls ) {
                    $results = self::purge_urls_for_current_site( $urls, true );
                    self::log_site_purge( 'content_change', self::get_zone_id(), $urls, $results );
                }
            } finally {
                if ( $switched ) {
                    restore_current_blog();
                }
            }
        }
    }

    /* -------------------------------------------------------------------------
     * Network-wide purge
     * ---------------------------------------------------------------------- */

    public static function get_configured_sites() {
        $sites = array();

        if ( is_multisite() ) {
            $blog_ids = get_sites( array(
                'number'   => 0,
                'network_id' => get_current_network_id(),
                'fields'   => 'ids',
                'archived' => 0,
                'deleted'  => 0,
                'spam'     => 0,
            ) );
        } else {
            $blog_ids = array( get_current_blog_id() );
        }

        foreach ( $blog_ids as $blog_id ) {
            $blog_id = (int) $blog_id;
            if ( is_multisite() ) {
                switch_to_blog( $blog_id );
            }

            $zone_id  = self::get_zone_id();
            $mode     = self::get_site_mode();
            $enabled  = self::is_site_enabled();
            $hostname = self::get_site_hostname();

            $sites[] = array(
                'blog_id'            => $blog_id,
                'name'               => get_bloginfo( 'name' ),
                'home_url'           => home_url( '/' ),
                'hostname'           => $hostname,
                'zone_id'            => $zone_id,
                'mode'               => $mode,
                'enabled'            => $enabled,
                'cache_reserve'      => self::is_cache_reserve_enabled(),
                'smart_tiered_cache' => self::is_smart_tiered_cache_enabled(),
            );

            if ( is_multisite() ) {
                restore_current_blog();
            }
        }

        return $sites;
    }

    public static function cache_reserve_hostnames_for_zone( $zone_id ) {
        $zone_id   = trim( (string) $zone_id );
        $hostnames = array();

        if ( ! $zone_id ) {
            return $hostnames;
        }

        foreach ( self::get_configured_sites() as $site ) {
            if (
                empty( $site['enabled'] ) ||
                empty( $site['cache_reserve'] ) ||
                empty( $site['hostname'] ) ||
                $zone_id !== (string) $site['zone_id']
            ) {
                continue;
            }

            $hostnames[] = (string) $site['hostname'];
        }

        return array_values( array_unique( $hostnames ) );
    }

    public static function smart_tiered_cache_enabled_for_zone( $zone_id ) {
        $zone_id = trim( (string) $zone_id );

        if ( ! $zone_id ) {
            return false;
        }

        foreach ( self::get_configured_sites() as $site ) {
            if (
                empty( $site['enabled'] ) ||
                empty( $site['smart_tiered_cache'] ) ||
                $zone_id !== (string) $site['zone_id']
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    public static function get_enabled_zones() {
        $zones = array();
        foreach ( self::get_configured_sites() as $site ) {
            if ( empty( $site['enabled'] ) || empty( $site['zone_id'] ) ) {
                continue;
            }
            $zone = $site['zone_id'];
            if ( ! isset( $zones[ $zone ] ) ) {
                $zones[ $zone ] = array(
                    'zone_id' => $zone,
                    'sites'   => array(),
                );
            }
            $zones[ $zone ]['sites'][] = $site;
        }
        return $zones;
    }

    public static function purge_all_enabled_zones( $reason = 'manual_network' ) {
        return ACFCM_Network_Queue::enqueue( $reason );
    }

    public static function purge_network_after_wp_update( $upgrader, $hook_extra ) {
        if ( ! self::network_auto_purge_enabled() ) {
            return;
        }

        $action = isset( $hook_extra['action'] ) ? $hook_extra['action'] : '';
        $type   = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';

        if ( 'update' !== $action || empty( $type ) ) {
            return;
        }

        if ( ! in_array( $type, self::network_update_types(), true ) ) {
            return;
        }

        self::purge_all_enabled_zones( 'wp_update_' . sanitize_key( $type ) );
    }

    public static function purge_network_after_automatic_updates( $update_results ) {
        if ( ! self::network_auto_purge_enabled() ) {
            return;
        }

        foreach ( self::network_update_types() as $type ) {
            if ( ! empty( $update_results[ $type ] ) ) {
                self::purge_all_enabled_zones( 'automatic_updates_complete' );
                break;
            }
        }
    }

    public static function purge_network_after_external_cache_clear( $context = '' ) {
        if ( self::$clearing_wpengine || ! self::network_external_cache_purge_enabled() ) {
            return;
        }
        self::purge_all_enabled_zones( 'external_cache_clear' );
    }

    /* -------------------------------------------------------------------------
     * Headers
     * ---------------------------------------------------------------------- */

    public static function send_logged_in_nocache_headers() {
        if ( ! self::is_logged_in_nocache_enabled() || ! is_user_logged_in() ) {
            return;
        }
        nocache_headers();
        header( 'Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0' );
    }

    /* -------------------------------------------------------------------------
     * Subsite admin UI
     * ---------------------------------------------------------------------- */

    public static function register_subsite_settings_page() {
        add_submenu_page(
            'options-general.php',
            'Cloudflare Cache',
            'Cloudflare Cache',
            'manage_options',
            'acfcm-cloudflare-cache',
            array( __CLASS__, 'render_subsite_settings_page' )
        );
    }

    public static function render_subsite_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $is_multisite = is_multisite();
        $site_cloudflare_locked = self::is_subsite_cloudflare_management_locked();
        $can_manage_site_cloudflare = self::current_user_can_manage_site_cloudflare();
        $can_purge_site_cloudflare = self::current_user_can_purge_site_cloudflare();

        if ( isset( $_POST['acfcm_save_site_settings'] ) || isset( $_POST['acfcm_save_site_settings_and_rules'] ) ) {
            check_admin_referer( 'acfcm_save_site_settings' );

            if ( ! $can_manage_site_cloudflare ) {
                wp_die( 'Insufficient permissions.' );
            }

            if ( ! $site_cloudflare_locked ) {
                $mode = isset( $_POST['acfcm_cloudflare_mode'] ) ? sanitize_key( wp_unslash( $_POST['acfcm_cloudflare_mode'] ) ) : self::MODE_AUTO;
                if ( ! in_array( $mode, array( self::MODE_AUTO, self::MODE_ENABLED, self::MODE_DISABLED ), true ) ) {
                    $mode = self::MODE_AUTO;
                }

                ACFCM_Cache_Admin::validate_settings( sanitize_text_field( wp_unslash( $_POST['cloudflare_zone_id'] ?? '' ) ), $mode, isset( $_POST['acfcm_content_auto_purge'] ) );
                update_option( 'acfcm_cloudflare_mode', $mode );
                update_option( 'cloudflare_zone_id', sanitize_text_field( wp_unslash( $_POST['cloudflare_zone_id'] ?? '' ) ) );
            }

            ACFCM_Cache_Admin::validate_settings( self::get_zone_id(), self::get_site_mode(), isset( $_POST['acfcm_content_auto_purge'] ) );
            update_option( 'acfcm_content_auto_purge', isset( $_POST['acfcm_content_auto_purge'] ) ? '1' : '0' );
            update_option( 'acfcm_logged_in_nocache', isset( $_POST['acfcm_logged_in_nocache'] ) ? '1' : '0' );
            update_option( 'acfcm_cache_reserve_enabled', isset( $_POST['acfcm_cache_reserve_enabled'] ) ? '1' : '0' );
            update_option( 'acfcm_smart_tiered_cache_enabled', isset( $_POST['acfcm_smart_tiered_cache_enabled'] ) ? '1' : '0' );

            if ( ! $site_cloudflare_locked && ! defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ! defined( 'CLOUDFLARE_API_TOKEN' ) && ! get_site_option( 'acfcm_cloudflare_api_token', '' ) ) {
                if ( isset( $_POST['cloudflare_api_token'] ) && '' !== $_POST['cloudflare_api_token'] ) {
                    update_option( 'cloudflare_api_token', sanitize_text_field( wp_unslash( $_POST['cloudflare_api_token'] ) ) );
                }
            }

            /*
             * Standalone installs do not have a Network Admin settings screen.
             * Expose the global/update settings here so the same plugin works cleanly
             * on both single-site WordPress and multisite networks.
             */
            if ( ! $is_multisite ) {
                update_site_option( 'acfcm_network_auto_purge', isset( $_POST['acfcm_network_auto_purge'] ) ? '1' : '0' );
                update_site_option( 'acfcm_network_external_cache_purge', isset( $_POST['acfcm_network_external_cache_purge'] ) ? '1' : '0' );

                $types = isset( $_POST['acfcm_network_update_types'] ) && is_array( $_POST['acfcm_network_update_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['acfcm_network_update_types'] ) ) : array();
                $types = array_values( array_intersect( $types, array( 'core', 'plugin', 'theme', 'translation' ) ) );
                update_site_option( 'acfcm_network_update_types', $types );

                if ( ! defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ! defined( 'CLOUDFLARE_API_TOKEN' ) && isset( $_POST['acfcm_cloudflare_api_token'] ) && '' !== $_POST['acfcm_cloudflare_api_token'] ) {
                    update_site_option( 'acfcm_cloudflare_api_token', sanitize_text_field( wp_unslash( $_POST['acfcm_cloudflare_api_token'] ) ) );
                }

                if ( ! defined( 'ACFCM_GITHUB_REPO' ) ) {
                    update_site_option( 'acfcm_github_repo', sanitize_text_field( wp_unslash( $_POST['acfcm_github_repo'] ?? '' ) ) );
                }

                if ( ! defined( 'ACFCM_GITHUB_TOKEN' ) && isset( $_POST['acfcm_github_token'] ) && '' !== $_POST['acfcm_github_token'] ) {
                    update_site_option( 'acfcm_github_token', sanitize_text_field( wp_unslash( $_POST['acfcm_github_token'] ) ) );
                }

                delete_site_transient( 'acfcm_github_release_cache' );
            }

            echo '<div class="notice notice-success is-dismissible"><p>Cloudflare cache settings saved.</p></div>';

            if ( isset( $_POST['acfcm_save_site_settings_and_rules'] ) ) {
                self::render_cache_rules_result_notice( $can_manage_site_cloudflare ? self::install_recommended_cache_rules( self::get_zone_id() ) : self::cloudflare_permission_error_result() );
            }
        }

        $mode            = self::get_site_mode();
        $zone_id         = self::get_zone_id();
        $enabled         = self::is_site_enabled();
        $token_source    = self::cf_token_source_label();
        $token_editable  = $can_manage_site_cloudflare && ! defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ! defined( 'CLOUDFLARE_API_TOKEN' ) && ! get_site_option( 'acfcm_cloudflare_api_token', '' );
        $update_types    = self::network_update_types();
        $github_repo_editable  = ! defined( 'ACFCM_GITHUB_REPO' );
        $github_token_editable = ! defined( 'ACFCM_GITHUB_TOKEN' );
        $log             = get_site_option( 'acfcm_purge_log', array() );
        $log = array_filter( (array) $log, static function ( $entry ) {
            return isset( $entry['site_id'] ) && (int) $entry['site_id'] === (int) get_current_blog_id();
        } );
        $save_settings_attrs = $can_manage_site_cloudflare ? array() : array( 'disabled' => 'disabled' );
        $save_rules_attrs = $can_manage_site_cloudflare ? array() : array( 'disabled' => 'disabled' );
        ?>
        <div class="wrap acfcm-admin">
            <h1>Cloudflare Cache</h1>
            <?php ACFCM_Cache_Admin::summary(); ACFCM_Cache_Admin::refresh(); ACFCM_Cache_Admin::policy(); ?>
            <?php if ( ! is_multisite() ) { echo '<section class="acfcm-card" id="acfcm-maintenance">'; ACFCM_Network_Queue::render(); echo '</section>'; } ?>

            <?php if ( $is_multisite ) : ?>
                <p>This subsite is currently <strong><?php echo $enabled ? 'enabled' : 'disabled'; ?></strong> for Cloudflare purge behavior.</p>
                <?php if ( $site_cloudflare_locked ) : ?>
                    <p class="description">Cloudflare settings and rule installation are managed in Network Admin because this network is using a shared Cloudflare API token. Site admins can still run manual purge actions for this subsite.</p>
                <?php endif; ?>
            <?php else : ?>
                <p>This standalone WordPress site is currently <strong><?php echo $enabled ? 'enabled' : 'disabled'; ?></strong> for Cloudflare purge behavior.</p>
            <?php endif; ?>

            <details class="acfcm-card" id="acfcm-connection"><summary>Site settings, connection &amp; updates</summary>
            <form method="post">
                <?php wp_nonce_field( 'acfcm_save_site_settings' ); ?>

                <h2><?php echo $is_multisite ? 'Subsite Settings' : 'Site Settings'; ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="acfcm_cloudflare_mode">Site Mode</label></th>
                        <td>
                            <select name="acfcm_cloudflare_mode" id="acfcm_cloudflare_mode" <?php disabled( $site_cloudflare_locked || ! $can_manage_site_cloudflare ); ?>>
                                <option value="auto" <?php selected( $mode, self::MODE_AUTO ); ?>>Auto — enable only if a Zone ID exists</option>
                                <option value="enabled" <?php selected( $mode, self::MODE_ENABLED ); ?>>Enabled</option>
                                <option value="disabled" <?php selected( $mode, self::MODE_DISABLED ); ?>>Disabled</option>
                            </select>
                            <p class="description"><?php echo $site_cloudflare_locked ? 'Network Admin controls this while a shared Cloudflare API token is active.' : 'Auto mode preserves older installs: if this site already has a saved Zone ID, purge features automatically engage.'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cloudflare_zone_id">Cloudflare Zone ID</label></th>
                        <td>
                            <input <?php disabled( $site_cloudflare_locked || ! $can_manage_site_cloudflare ); ?> type="text" name="cloudflare_zone_id" id="cloudflare_zone_id" class="regular-text" value="<?php echo esc_attr( $zone_id ); ?>">
                            <?php if ( $site_cloudflare_locked ) : ?>
                                <p class="description">Ask a Network Admin to change this Zone ID from the network settings screen.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Automatic Content Purge</th>
                        <td><label><input type="checkbox" name="acfcm_content_auto_purge" value="1" <?php checked( get_option( 'acfcm_content_auto_purge', '1' ), '1' ); ?> <?php disabled( ! $can_manage_site_cloudflare ); ?>> Purge related URLs when public content changes</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Logged-in No-cache Headers</th>
                        <td><label><input type="checkbox" name="acfcm_logged_in_nocache" value="1" <?php checked( get_option( 'acfcm_logged_in_nocache', '1' ), '1' ); ?> <?php disabled( ! $can_manage_site_cloudflare ); ?>> Send no-cache headers for logged-in users</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Cache Reserve</th>
                        <td>
                            <label><input type="checkbox" name="acfcm_cache_reserve_enabled" value="1" <?php checked( self::is_cache_reserve_enabled() ); ?> <?php disabled( ! $can_manage_site_cloudflare ); ?>> Make this site’s hostname eligible for Cloudflare Cache Reserve</label>
                            <p class="description">Cache Reserve storage sync must also be enabled for this Cloudflare zone. The reviewed policy uses a 50 KB minimum file size while retaining privacy bypasses. Entitlement failures stop migration.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Smart Tiered Cache</th>
                        <td>
                            <label><input type="checkbox" name="acfcm_smart_tiered_cache_enabled" value="1" <?php checked( self::is_smart_tiered_cache_enabled() ); ?> <?php disabled( ! $can_manage_site_cloudflare ); ?>> Enable Tiered Cache with Smart topology for this Cloudflare zone when installing recommended cache rules</label>
                            <p class="description">Tiered Cache and Smart topology are zone-level Cloudflare settings. Unchecking this option stops the plugin from enabling them, but does not turn them off in Cloudflare.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cloudflare_api_token">API Token</label></th>
                        <td>
                            <input <?php disabled( ! $token_editable ); ?> type="password" name="cloudflare_api_token" id="cloudflare_api_token" class="regular-text" value="" autocomplete="new-password">
                            <p class="description">Token source: <code><?php echo esc_html( $token_source ); ?></code>. Prefer defining the token in wp-config.php<?php echo $is_multisite ? ' or on the Network settings page' : ''; ?>.</p>
                        </td>
                    </tr>
                </table>

                <?php if ( ! $is_multisite ) : ?>
                    <h2>WordPress Update Purge</h2>
                    <p>These settings replace the Network Admin settings screen on standalone WordPress installs.</p>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Auto-purge After Updates</th>
                            <td><label><input type="checkbox" name="acfcm_network_auto_purge" value="1" <?php checked( self::network_auto_purge_enabled() ); ?>> Purge this site’s Cloudflare zone after selected WordPress updates</label></td>
                        </tr>
                        <tr>
                            <th scope="row">Update Types</th>
                            <td>
                                <?php foreach ( array( 'core' => 'Core', 'plugin' => 'Plugins', 'theme' => 'Themes', 'translation' => 'Translations' ) as $type => $label ) : ?>
                                    <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="acfcm_network_update_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $update_types, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">External Cache Clear Hook</th>
                            <td>
                                <label><input type="checkbox" name="acfcm_network_external_cache_purge" value="1" <?php checked( self::network_external_cache_purge_enabled() ); ?>> Purge Cloudflare when an external/server cache clear hook fires</label>
                                <p class="description">Best-effort compatibility. This plugin listens for <code>acfcm_external_cache_cleared</code>, <code>wpe_cache_flush</code>, and <code>wpe_purge_cache</code>.</p>
                            </td>
                        </tr>
                    </table>

                    <h2>Plugin Updates</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="acfcm_cloudflare_api_token">Global Cloudflare API Token</label></th>
                            <td>
                                <input <?php disabled( ! ( ! defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ! defined( 'CLOUDFLARE_API_TOKEN' ) ) ); ?> type="password" name="acfcm_cloudflare_api_token" id="acfcm_cloudflare_api_token" class="regular-text" value="" autocomplete="new-password">
                                <p class="description">Optional. Prefer <code>define('ACFCM_CLOUDFLARE_API_TOKEN', '...');</code> in <code>wp-config.php</code>. This field is disabled if a token constant is set.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="acfcm_github_repo">GitHub Repo</label></th>
                            <td>
                                <input <?php disabled( ! $github_repo_editable ); ?> type="text" name="acfcm_github_repo" id="acfcm_github_repo" class="regular-text" value="<?php echo esc_attr( self::github_repo() ); ?>" placeholder="owner/repo">
                                <p class="description">Used for plugin update checks. Public repos do not require a GitHub token.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="acfcm_github_token">GitHub Token</label></th>
                            <td>
                                <input <?php disabled( ! $github_token_editable ); ?> type="password" name="acfcm_github_token" id="acfcm_github_token" class="regular-text" value="" autocomplete="new-password">
                                <p class="description">Optional. Needed only for private repo release checks.</p>
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>

                <p class="submit">
                    <?php submit_button( 'Save Settings', 'primary', 'acfcm_save_site_settings', false, $save_settings_attrs ); ?>
                </p>
            </form>

            <hr>
</details>
            <details class="acfcm-card" id="acfcm-security"><summary>Security defenses</summary>
            <h2>Verify or onboard security defenses</h2>
            <p>Verifies recognized deployed defenses without changing them. Explicit onboarding adds sensitive-file protection and the public-page rate policy only when ownership and rule capacity are clear.</p>
            <p class="description">Requires Rulesets read and WAF edit permissions. Free-plan baseline: five custom-rule slots and one rate-rule slot; public pages are blocked for 10 seconds after 30 matching requests per 10 seconds per IP and data center. Submissions and WP sessions skip only rate limiting. Existing policies are never replaced or consolidated; drift and quota conflicts require review. No firewall changes run on upgrade, edits or purges.</p>
            <?php if ( ! $can_manage_site_cloudflare ) : ?>
                <p>Network Admin permission is required to install Cloudflare hardening rules while a shared Cloudflare API token is active.</p>
            <?php elseif ( $zone_id ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="acfcm_install_hardening_rules">
                    <?php wp_nonce_field( 'acfcm_install_hardening_rules' ); ?>
                    <fieldset>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="acfcm_hardening_defense_baseline" value="1"> Verify deployed defenses / explicitly onboard the defense baseline</label>
                    </fieldset>
                    <p><?php submit_button( 'Verify / Onboard Defense Baseline', 'secondary', 'acfcm_install_hardening_rules_submit', false, array( 'onclick' => "return confirm('Explicitly verify or onboard the defense baseline for this zone?');" ) ); ?></p>
                </form>
            <?php else : ?>
                <p>Save a Cloudflare Zone ID before installing hardening rules.</p>
            <?php endif; ?>

            <hr>
            </details><section class="acfcm-card"><h2>Clear cache</h2>
            <p>Clears WP Engine’s page cache for this domain, then Cloudflare’s cache for this site’s configured zone.</p>
            <p>
                <?php if ( ! $can_purge_site_cloudflare ) : ?>
                    Site Admin permission is required to run manual Cloudflare purges.
                <?php elseif ( ! $zone_id ) : ?>
                    <?php echo esc_html( $can_manage_site_cloudflare ? 'Save a Cloudflare Zone ID before running manual purges.' : 'Ask a Network Admin to save a Cloudflare Zone ID before running manual purges.' ); ?>
                <?php else : ?>
                    <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_site_everything' ), 'acfcm_purge_site_everything' ) ); ?>">Clear WP Engine + Cloudflare cache</a>
                <?php endif; ?>
            </p>

            </section><section class="acfcm-card" id="acfcm-activity">
                <h2>Recent activity</h2>
                <?php if ( empty( $log ) ) : ?>
                    <p>No purge log entries yet.</p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><th>Time</th><th>Reason</th><th>Requests</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ( array_slice( array_reverse( $log ), 0, 25 ) as $entry ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( $entry['reason'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( (string) ( $entry['zone_count'] ?? 0 ) ); ?></td>
                                    <td><?php echo esc_html( $entry['status'] ?? '' ); ?><?php if ( ! empty( $entry['details'] ) ) : ?><details><summary>Details</summary><pre style="white-space:pre-wrap"><?php echo esc_html( wp_json_encode( $entry['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></details><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ( current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) : ?><p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_clear_log' ), 'acfcm_clear_log' ) ); ?>"><?php echo is_multisite() ? 'Clear Network Log' : 'Clear Log'; ?></a></p><?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    /* -------------------------------------------------------------------------
     * Network admin UI
     * ---------------------------------------------------------------------- */

    public static function register_network_settings_page() {
        add_submenu_page(
            'settings.php',
            'Cloudflare Cache Manager',
            'Cloudflare Cache Manager',
            'manage_network_options',
            'acfcm-network',
            array( __CLASS__, 'render_network_settings_page' )
        );
    }

    public static function render_network_settings_page() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            return;
        }

        if ( isset( $_POST['acfcm_save_network_settings'] ) ) {
            check_admin_referer( 'acfcm_save_network_settings' );

            update_site_option( 'acfcm_network_auto_purge', isset( $_POST['acfcm_network_auto_purge'] ) ? '1' : '0' );
            update_site_option( 'acfcm_network_external_cache_purge', isset( $_POST['acfcm_network_external_cache_purge'] ) ? '1' : '0' );

            $types = isset( $_POST['acfcm_network_update_types'] ) && is_array( $_POST['acfcm_network_update_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['acfcm_network_update_types'] ) ) : array();
            $types = array_values( array_intersect( $types, array( 'core', 'plugin', 'theme', 'translation' ) ) );
            update_site_option( 'acfcm_network_update_types', $types );

            if ( ! defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ! defined( 'CLOUDFLARE_API_TOKEN' ) && isset( $_POST['acfcm_cloudflare_api_token'] ) && '' !== $_POST['acfcm_cloudflare_api_token'] ) {
                update_site_option( 'acfcm_cloudflare_api_token', sanitize_text_field( wp_unslash( $_POST['acfcm_cloudflare_api_token'] ) ) );
            }

            if ( ! defined( 'ACFCM_GITHUB_REPO' ) ) {
                update_site_option( 'acfcm_github_repo', sanitize_text_field( wp_unslash( $_POST['acfcm_github_repo'] ?? '' ) ) );
            }
            if ( ! defined( 'ACFCM_GITHUB_TOKEN' ) && isset( $_POST['acfcm_github_token'] ) && '' !== $_POST['acfcm_github_token'] ) {
                update_site_option( 'acfcm_github_token', sanitize_text_field( wp_unslash( $_POST['acfcm_github_token'] ) ) );
            }

            delete_site_transient( 'acfcm_github_release_cache' );
            echo '<div class="notice notice-success is-dismissible"><p>Network settings saved.</p></div>';
        }

        if ( isset( $_POST['acfcm_save_sites'] ) ) {
            check_admin_referer( 'acfcm_save_sites' );
            $site_modes = isset( $_POST['acfcm_site_mode'] ) && is_array( $_POST['acfcm_site_mode'] ) ? wp_unslash( $_POST['acfcm_site_mode'] ) : array();
            $zone_ids   = isset( $_POST['acfcm_zone_id'] ) && is_array( $_POST['acfcm_zone_id'] ) ? wp_unslash( $_POST['acfcm_zone_id'] ) : array();
            $cache_reserve_sites = isset( $_POST['acfcm_cache_reserve'] ) && is_array( $_POST['acfcm_cache_reserve'] ) ? wp_unslash( $_POST['acfcm_cache_reserve'] ) : array();
            $smart_tiered_cache_sites = isset( $_POST['acfcm_smart_tiered_cache'] ) && is_array( $_POST['acfcm_smart_tiered_cache'] ) ? wp_unslash( $_POST['acfcm_smart_tiered_cache'] ) : array();

            foreach ( $site_modes as $blog_id => $mode ) {
                $blog_id = (int) $blog_id;
                $site_record = get_site( $blog_id );
                if ( ! $site_record || (int) $site_record->network_id !== get_current_network_id() ) { wp_die( 'Site is outside this network.' ); }
                $mode    = sanitize_key( $mode );
                if ( ! in_array( $mode, array( self::MODE_AUTO, self::MODE_ENABLED, self::MODE_DISABLED ), true ) ) {
                    $mode = self::MODE_AUTO;
                }
                switch_to_blog( $blog_id );
                ACFCM_Cache_Admin::validate_settings( isset( $zone_ids[$blog_id] ) ? sanitize_text_field( $zone_ids[$blog_id] ) : self::get_zone_id(), $mode, '0' !== (string) get_option( 'acfcm_content_auto_purge', '1' ) );
                update_option( 'acfcm_cloudflare_mode', $mode );
                if ( isset( $zone_ids[ $blog_id ] ) ) {
                    update_option( 'cloudflare_zone_id', sanitize_text_field( $zone_ids[ $blog_id ] ) );
                }
                update_option( 'acfcm_cache_reserve_enabled', isset( $cache_reserve_sites[ $blog_id ] ) ? '1' : '0' );
                update_option( 'acfcm_smart_tiered_cache_enabled', isset( $smart_tiered_cache_sites[ $blog_id ] ) ? '1' : '0' );
                restore_current_blog();
            }
            echo '<div class="notice notice-success is-dismissible"><p>Site settings saved.</p></div>';
        }

        $token_source  = self::cf_token_source_label();
        $cf_token_editable = ! defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ! defined( 'CLOUDFLARE_API_TOKEN' );
        $github_repo_editable = ! defined( 'ACFCM_GITHUB_REPO' );
        $github_token_editable = ! defined( 'ACFCM_GITHUB_TOKEN' );
        $update_types = self::network_update_types();
        $sites = self::get_configured_sites();
        $log = get_site_option( 'acfcm_purge_log', array() );
        ?>
        <div class="wrap acfcm-admin">
            <h1>Network cache manager</h1>
            <?php ACFCM_Cache_Admin::summary( true ); ?>
            <p>Network-activated Cloudflare cache management. Subsites can be Auto, Enabled, or Disabled.</p>

            <details class="acfcm-card" id="acfcm-connection"><summary>Network settings, connection &amp; updates</summary><h2>Network defaults</h2>
            <form method="post">
                <?php wp_nonce_field( 'acfcm_save_network_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="acfcm_cloudflare_api_token">Cloudflare API Token</label></th>
                        <td>
                            <input <?php disabled( ! $cf_token_editable ); ?> type="password" name="acfcm_cloudflare_api_token" id="acfcm_cloudflare_api_token" class="regular-text" value="" autocomplete="new-password">
                            <p class="description">Token source: <code><?php echo esc_html( $token_source ); ?></code>. Recommended wp-config.php constant: <code>define('ACFCM_CLOUDFLARE_API_TOKEN', '...');</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-purge After Updates</th>
                        <td><label><input type="checkbox" name="acfcm_network_auto_purge" value="1" <?php checked( self::network_auto_purge_enabled() ); ?>> Queue paced maintenance purges after selected WordPress updates</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Update Types</th>
                        <td>
                            <?php foreach ( array( 'core' => 'Core', 'plugin' => 'Plugins', 'theme' => 'Themes', 'translation' => 'Translations' ) as $type => $label ) : ?>
                                <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="acfcm_network_update_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $update_types, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">External Cache Clear Hook</th>
                        <td>
                            <label><input type="checkbox" name="acfcm_network_external_cache_purge" value="1" <?php checked( self::network_external_cache_purge_enabled() ); ?>> Purge Cloudflare when an external/server cache clear hook fires</label>
                            <p class="description">Best-effort compatibility. This plugin listens for <code>acfcm_external_cache_cleared</code>, <code>wpe_cache_flush</code>, and <code>wpe_purge_cache</code>. WP Engine does not consistently document a universal WordPress hook for every dashboard cache clear.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acfcm_github_repo">GitHub Repo</label></th>
                        <td>
                            <input <?php disabled( ! $github_repo_editable ); ?> type="text" name="acfcm_github_repo" id="acfcm_github_repo" class="regular-text" value="<?php echo esc_attr( self::github_repo() ); ?>" placeholder="owner/repo">
                            <p class="description">Used for plugin update checks. Example: <code>AcquireDigital/acquire-cloudflare-cache-manager</code>. Recommended: public repo with release zip asset.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acfcm_github_token">GitHub Token</label></th>
                        <td>
                            <input <?php disabled( ! $github_token_editable ); ?> type="password" name="acfcm_github_token" id="acfcm_github_token" class="regular-text" value="" autocomplete="new-password">
                            <p class="description">Optional. Needed only for private repo release checks. Public repos do not require this. For private repos, downloading the update package is safest when the release asset is publicly reachable or served from a private updater endpoint.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Network Settings', 'primary', 'acfcm_save_network_settings' ); ?>
            </form>

            <hr>
            </details><section class="acfcm-card" id="acfcm-maintenance"><?php ACFCM_Network_Queue::render(); ?>
            <h3>Start a maintenance batch</h3>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_network_everything' ), 'acfcm_purge_network_everything' ) ); ?>" onclick="return confirm('Queue paced cache purges for enabled sites on this network?');">Queue Maintenance Purge</a>
            </p>

            <hr>
            </section><details class="acfcm-card" id="acfcm-security"><summary>Network-wide security defenses</summary><h2>Verify or onboard enabled zones</h2>
            <p>Explicitly verify or onboard the defense baseline for every enabled Cloudflare zone on this network. Recognized deployed policies and exceptions are retained. Each zone is handled separately; failures do not roll back completed zones.</p>
            <p class="description">Requires Rulesets read and WAF edit permissions. Free-plan baseline: five custom-rule slots and one rate-rule slot; public pages are blocked for 10 seconds after 30 matching requests per 10 seconds per IP and data center. Submissions and WP sessions skip only rate limiting. Existing policies are never replaced or consolidated; drift and quota conflicts require review. No firewall changes run on upgrade, edits or purges.</p>
            <p class="description">For one subsite at a time, use the per-site actions in the Subsites table below.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="acfcm_install_network_hardening_rules">
                <?php wp_nonce_field( 'acfcm_install_network_hardening_rules' ); ?>
                <fieldset>
                    <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="acfcm_hardening_defense_baseline" value="1"> Verify deployed defenses / explicitly onboard the defense baseline</label>
                </fieldset>
                <p><?php submit_button( 'Verify / Onboard Defense Baseline', 'secondary', 'acfcm_install_network_hardening_rules_submit', false, array( 'onclick' => "return confirm('Explicitly verify or onboard the defense baseline for every enabled zone?');" ) ); ?></p>
            </form>

            <hr>
            </details><section class="acfcm-card" id="acfcm-sites"><h2>Sites on this network</h2>
            <p class="description">Cache Reserve storage sync must be enabled in Cloudflare for the applicable zone. Eligible hostname rules use a 50 KB minimum file size. Tiered Cache and Smart topology are zone-level Cloudflare settings. Save settings, then use Install cache rules for the affected zone. Saving settings alone does not install rules.</p>
            <form method="post">
                <?php wp_nonce_field( 'acfcm_save_sites' ); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Mode</th>
                            <th>Effective</th>
                            <th>Zone ID</th>
                            <th>Cache Reserve</th>
                            <th>Smart Tiered Cache</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sites as $site ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $site['name'] ); ?></strong><br><a href="<?php echo esc_url( $site['home_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $site['home_url'] ); ?></a></td>
                                <td>
                                    <select aria-label="Site mode for <?php echo esc_attr( $site['name'] ); ?>" name="acfcm_site_mode[<?php echo (int) $site['blog_id']; ?>]">
                                        <option value="auto" <?php selected( $site['mode'], self::MODE_AUTO ); ?>>Auto</option>
                                        <option value="enabled" <?php selected( $site['mode'], self::MODE_ENABLED ); ?>>Enabled</option>
                                        <option value="disabled" <?php selected( $site['mode'], self::MODE_DISABLED ); ?>>Disabled</option>
                                    </select>
                                </td>
                                <td><?php echo $site['enabled'] ? '<span style="color:#008a20;font-weight:600;">Enabled</span>' : '<span style="color:#8a0000;font-weight:600;">Disabled</span>'; ?></td>
                                <td><input aria-label="Cloudflare Zone ID for <?php echo esc_attr( $site['name'] ); ?>" type="text" class="regular-text" name="acfcm_zone_id[<?php echo (int) $site['blog_id']; ?>]" value="<?php echo esc_attr( $site['zone_id'] ); ?>"></td>
                                <td><label><input type="checkbox" name="acfcm_cache_reserve[<?php echo (int) $site['blog_id']; ?>]" value="1" <?php checked( ! empty( $site['cache_reserve'] ) ); ?>> Eligible</label></td>
                                <td><label><input type="checkbox" name="acfcm_smart_tiered_cache[<?php echo (int) $site['blog_id']; ?>]" value="1" <?php checked( ! empty( $site['smart_tiered_cache'] ) ); ?>> Enable</label></td>
                                <td>
                                    <?php if ( ! empty( $site['zone_id'] ) ) : ?>
                                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_network_site&blog_id=' . (int) $site['blog_id'] ), 'acfcm_purge_network_site_' . (int) $site['blog_id'] ) ); ?>">Clear WP Engine + Cloudflare cache</a>
                                        <?php ACFCM_Cache_Admin::network_site_buttons( $site ); ?>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( 'Save Site Settings', 'secondary', 'acfcm_save_sites' ); ?>
            </form>
            <?php ACFCM_Cache_Admin::network_zones( $sites ); ?>

            <hr>
            </section><section class="acfcm-card" id="acfcm-activity"><h2>Recent activity</h2>
            <?php if ( empty( $log ) ) : ?>
                <p>No purge log entries yet.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th>Time</th><th>Reason</th><th>Requests</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ( array_slice( array_reverse( $log ), 0, 25 ) as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                <td><?php echo esc_html( $entry['reason'] ?? '' ); ?></td>
                                <td><?php echo esc_html( (string) ( $entry['zone_count'] ?? 0 ) ); ?></td>
                                <td><?php echo esc_html( $entry['status'] ?? '' ); ?><?php if ( ! empty( $entry['details'] ) ) : ?><details><summary>Details</summary><pre style="white-space:pre-wrap"><?php echo esc_html( wp_json_encode( $entry['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></details><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_clear_log' ), 'acfcm_clear_log' ) ); ?>">Clear Log</a></p>
            <?php endif; ?>
        </section></div>
        <?php
    }

    /* -------------------------------------------------------------------------
     * Toolbar and admin-post handlers
     * ---------------------------------------------------------------------- */

    public static function register_toolbar_menu( $wp_admin_bar ) {
        if ( ! is_admin_bar_showing() || ! self::current_user_can_purge_site_cloudflare() || ! self::is_site_enabled() ) {
            return;
        }

        $wp_admin_bar->add_node( array(
            'id'    => 'acfcm-cf-purge',
            'title' => 'Cloudflare Purge',
            'href'  => false,
        ) );

        $wp_admin_bar->add_node( array(
            'id'     => 'acfcm-cf-purge-home',
            'parent' => 'acfcm-cf-purge',
            'title'  => 'Purge Homepage',
            'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_home' ), 'acfcm_purge_home' ),
        ) );

        $wp_admin_bar->add_node( array(
            'id'     => 'acfcm-cf-purge-all',
            'parent' => 'acfcm-cf-purge',
            'title'  => 'Clear WP Engine + Cloudflare cache',
            'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_site_everything' ), 'acfcm_purge_site_everything' ),
        ) );
    }

    public static function handle_purge_home() {
        if ( ! self::current_user_can_purge_site_cloudflare() ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'acfcm_purge_home' );

        $home = home_url( '/' );
        $urls = self::normalize_urls( array( $home, trailingslashit( $home ) . 'feed/' ) );
        $results = self::purge_urls_for_current_site( $urls );
        self::log_site_purge( 'manual_home', self::get_zone_id(), $urls, $results );

        wp_safe_redirect( add_query_arg( 'acfcm_notice', 'home', wp_get_referer() ?: admin_url() ) );
        exit;
    }

    private static function manual_purge_notice( array $result ) {
        return ! empty( $result['success'] ) ? 'combined_sent' : ( ! empty( $result['queued'] ) ? 'combined_queued' : 'combined_failed' );
    }

    public static function handle_purge_site_everything() {
        if ( ! self::current_user_can_purge_site_cloudflare() ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'acfcm_purge_site_everything' );

        $zone_id = self::get_zone_id();
        $result  = self::purge_current_site_everything();
        self::log_site_purge( 'manual_site_everything', $zone_id, array(), array( $result ) );

        wp_safe_redirect( add_query_arg( 'acfcm_notice', self::manual_purge_notice( $result ), wp_get_referer() ?: admin_url() ) );
        exit;
    }

    public static function handle_purge_network_everything() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'acfcm_purge_network_everything' );

        $summary = self::purge_all_enabled_zones( 'manual_network' );
        $notice  = empty( $summary['queued'] ) ? 'network_locked' : 'network_all';

        wp_safe_redirect( add_query_arg( 'acfcm_notice', $notice, network_admin_url( 'settings.php?page=acfcm-network' ) ) );
        exit;
    }

    public static function handle_purge_network_site() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        $blog_id = isset( $_GET['blog_id'] ) ? (int) $_GET['blog_id'] : 0;
        check_admin_referer( 'acfcm_purge_network_site_' . $blog_id );

        $site = is_multisite() ? get_site( $blog_id ) : null;
        if ( ! $site || (int) $site->network_id !== (int) get_current_network_id() ) {
            wp_die( 'Invalid site.' );
        }
        $zone_id = self::get_zone_id( $blog_id );
        $switched = is_multisite() && (int) get_current_blog_id() !== $blog_id;
        if ( $switched ) {
            switch_to_blog( $blog_id );
        }
        try {
            $result = self::purge_current_site_everything();
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
        self::log_network_purge( 'manual_single_site_' . $blog_id, array( array( 'zone_id' => $zone_id, 'sites' => array( get_home_url( $blog_id, '/' ) ), 'result' => $result ) ) );

        wp_safe_redirect( add_query_arg( 'acfcm_notice', self::manual_purge_notice( $result ), network_admin_url( 'settings.php?page=acfcm-network' ) ) );
        exit;
    }

    public static function handle_install_cache_rules() {
        if ( ! self::current_user_can_manage_site_cloudflare() ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'acfcm_install_cache_rules' );

        $result = self::install_recommended_cache_rules( self::get_zone_id() );
        $redirect = wp_get_referer() ?: admin_url( 'options-general.php?page=acfcm-cloudflare-cache' );

        wp_safe_redirect( add_query_arg( self::cache_rules_redirect_args( $result ), $redirect ) );
        exit;
    }

    public static function redirect_to_site_cache_preview( $blog_id, array $args ) {
        $site = get_site( $blog_id );
        if ( ! current_user_can( 'manage_network_options' ) || ! $site || (int) $site->network_id !== (int) get_current_network_id() ) {
            wp_die( 'Site is not on this network or permission is missing.' );
        }
        // Derive the destination from WordPress, never from a request URL.
        $redirect = get_admin_url( $blog_id, 'options-general.php?page=acfcm-cloudflare-cache' );
        $host = wp_parse_url( $redirect, PHP_URL_HOST );
        $allow_site = static function( $hosts ) use ( $host ) {
            if ( $host ) { $hosts[] = $host; }
            return $hosts;
        };
        // Mapped subsite domains are otherwise rejected after restoring the network context.
        add_filter( 'allowed_redirect_hosts', $allow_site );
        try {
            wp_safe_redirect( add_query_arg( $args, $redirect ) . '#acfcm-policy' );
        } finally {
            remove_filter( 'allowed_redirect_hosts', $allow_site );
        }
    }

    public static function handle_install_network_site_cache_rules() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $blog_id = isset( $_GET['blog_id'] ) ? (int) $_GET['blog_id'] : 0;
        check_admin_referer( 'acfcm_install_cache_rules_' . $blog_id );

        if ( ! get_site( $blog_id ) || (int) get_site( $blog_id )->network_id !== get_current_network_id() ) { wp_die( 'Site is not on this network.' ); }
        switch_to_blog( $blog_id );
        try {
            $result = self::install_recommended_cache_rules( self::get_zone_id() );
        } finally {
            restore_current_blog();
        }

        self::redirect_to_site_cache_preview( $blog_id, self::cache_rules_redirect_args( $result ) );
        exit;
    }

    public static function handle_install_network_site_cache_security_rules() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $blog_id = isset( $_GET['blog_id'] ) ? (int) $_GET['blog_id'] : 0;
        check_admin_referer( 'acfcm_install_cache_security_rules_' . $blog_id );

        if ( ! get_site( $blog_id ) || (int) get_site( $blog_id )->network_id !== get_current_network_id() ) { wp_die( 'Site is not on this network.' ); }
        switch_to_blog( $blog_id );
        try {
            $result = self::install_cache_and_default_security_rules( self::get_zone_id() );
        } finally {
            restore_current_blog();
        }

        self::redirect_to_site_cache_preview( $blog_id, self::cache_security_rules_redirect_args( $result ) );
        exit;
    }

    public static function handle_install_hardening_rules() {
        if ( ! self::current_user_can_manage_site_cloudflare() ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'acfcm_install_hardening_rules' );

        $request = wp_unslash( $_POST );
        $result = self::install_recommended_hardening_rules( self::get_zone_id(), self::hardening_options_from_request( $request ) );
        $redirect = wp_get_referer() ?: admin_url( 'options-general.php?page=acfcm-cloudflare-cache' );

        wp_safe_redirect( add_query_arg( self::hardening_rules_redirect_args( $result ), $redirect ) );
        exit;
    }

    public static function handle_install_network_hardening_rules() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'acfcm_install_network_hardening_rules' );

        $request = wp_unslash( $_POST );
        $result = self::install_recommended_hardening_rules_for_enabled_zones( self::hardening_options_from_request( $request ) );

        wp_safe_redirect( add_query_arg( self::hardening_rules_redirect_args( $result ), network_admin_url( 'settings.php?page=acfcm-network' ) ) );
        exit;
    }

    public static function cache_rules_redirect_args( array $result ) {
        $args = array(
            'acfcm_notice' => ! empty( $result['success'] )
                ? ( ! empty( $result['warning'] ) ? 'cache_rules_warning' : 'cache_rules' )
                : 'cache_rules_failed',
        );

        if ( empty( $result['success'] ) && ! empty( $result['message'] ) ) {
            $args['acfcm_error'] = self::short_notice_message( $result['message'] );
        }

        if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) && 'OK' !== $result['message'] ) {
            $args['acfcm_info'] = self::short_notice_message( $result['message'] );
        }

        return $args;
    }

    public static function cache_security_rules_redirect_args( array $result ) {
        $args = array(
            'acfcm_notice' => ! empty( $result['success'] )
                ? ( ! empty( $result['warning'] ) ? 'cache_security_rules_warning' : 'cache_security_rules' )
                : 'cache_security_rules_failed',
        );

        if ( empty( $result['success'] ) && ! empty( $result['message'] ) ) {
            $args['acfcm_error'] = self::short_notice_message( $result['message'] );
        }

        if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) && 'OK' !== $result['message'] ) {
            $args['acfcm_info'] = self::short_notice_message( $result['message'] );
        }

        return $args;
    }

    public static function hardening_rules_redirect_args( array $result ) {
        $args = array(
            'acfcm_notice' => ! empty( $result['success'] ) ? 'hardening_rules' : 'hardening_rules_failed',
        );

        if ( empty( $result['success'] ) && ! empty( $result['message'] ) ) {
            $args['acfcm_error'] = self::short_notice_message( $result['message'] );
        }

        if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) && 'OK' !== $result['message'] ) {
            $args['acfcm_info'] = self::short_notice_message( $result['message'] );
        }

        return $args;
    }

    public static function render_cache_rules_result_notice( array $result ) {
        $success = ! empty( $result['success'] );
        $warning = $success && ! empty( $result['warning'] );
        $message = $success
            ? 'Cloudflare recommended cache rules installed or updated.'
            : 'Cloudflare recommended cache rules could not be installed or updated.';

        if ( ! $success && ! empty( $result['message'] ) ) {
            $message .= ' Cloudflare said: ' . self::short_notice_message( $result['message'] );
        }

        if ( $success && ! empty( $result['message'] ) && 'OK' !== $result['message'] ) {
            $message .= ' ' . self::short_notice_message( $result['message'] );
        }

        $notice_class = $warning ? 'notice-warning' : ( $success ? 'notice-success' : 'notice-error' );

        echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
    }

    public static function short_notice_message( $message ) {
        $message = sanitize_text_field( wp_strip_all_tags( (string) $message ) );
        if ( strlen( $message ) > 280 ) {
            $message = substr( $message, 0, 277 ) . '...';
        }
        return $message;
    }

    public static function handle_clear_log() {
        if ( is_multisite() ) {
            if ( ! current_user_can( 'manage_network_options' ) ) {
                wp_die( 'Insufficient permissions.' );
            }
            $redirect = network_admin_url( 'settings.php?page=acfcm-network' );
        } else {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'Insufficient permissions.' );
            }
            $redirect = admin_url( 'options-general.php?page=acfcm-cloudflare-cache' );
        }

        check_admin_referer( 'acfcm_clear_log' );
        delete_site_option( 'acfcm_purge_log' );
        wp_safe_redirect( add_query_arg( 'acfcm_notice', 'log_cleared', $redirect ) );
        exit;
    }

    public static function admin_notices() {
        if ( empty( $_GET['acfcm_notice'] ) ) {
            return;
        }
        $notice = sanitize_key( wp_unslash( $_GET['acfcm_notice'] ) );
        $messages = array(
            'combined_sent'   => 'Known WordPress object-cache entries cleared for this site; WP Engine and Cloudflare purges dispatched. Opaque third-party object-cache keys are not included. See Recent activity for details.',
            'combined_queued' => 'Cache purge is pending retry or an existing request. See Recent activity for details.',
            'combined_failed' => 'Cache purge could not complete. See Recent activity for details.',
            'home'           => 'Cloudflare homepage purge requested.',
            'site_all'       => 'Cloudflare purge everything requested for this site.',
            'network_all'    => 'Paced maintenance purge queued for enabled sites. Review progress below.',
            'network_locked' => 'Maintenance request could not be queued. Check the queue inventory and try again.',
            'network_site'   => 'Cloudflare purge everything requested for that site zone.',
            'cache_rules'    => 'Read-only cache preview prepared. Review it below before applying.',
            'cache_rules_warning' => 'Cloudflare recommended cache rules installed or updated, but one related setting needs attention.',
            'cache_security_rules'         => 'Read-only cache and security preview prepared. Review it below before applying.',
            'cache_security_rules_warning' => 'Cloudflare cache and basic security rules installed or updated, but one related setting needs attention.',
            'hardening_rules' => 'Cloudflare defense verification or onboarding completed.',
            'log_cleared'    => 'Cloudflare purge log cleared.',
        );
        if ( isset( $messages[ $notice ] ) ) {
            $message = $messages[ $notice ];
            if ( in_array( $notice, array( 'cache_rules', 'cache_rules_warning' ), true ) && ! empty( $_GET['acfcm_info'] ) ) {
                $message .= ' ' . self::short_notice_message( wp_unslash( $_GET['acfcm_info'] ) );
            }
            if ( in_array( $notice, array( 'cache_security_rules', 'cache_security_rules_warning' ), true ) && ! empty( $_GET['acfcm_info'] ) ) {
                $message .= ' ' . self::short_notice_message( wp_unslash( $_GET['acfcm_info'] ) );
            }
            if ( 'hardening_rules' === $notice && ! empty( $_GET['acfcm_info'] ) ) {
                $message .= ' ' . self::short_notice_message( wp_unslash( $_GET['acfcm_info'] ) );
            }
            $notice_class = in_array( $notice, array( 'cache_rules_warning', 'cache_security_rules_warning', 'network_locked', 'combined_queued', 'combined_failed' ), true ) ? 'notice-warning' : 'notice-success';
            echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        if ( 'cache_rules_failed' === $notice ) {
            $message = 'Cache preview could not be prepared. No rules were installed.';
            if ( ! empty( $_GET['acfcm_error'] ) ) {
                $message .= ' Cloudflare said: ' . self::short_notice_message( wp_unslash( $_GET['acfcm_error'] ) );
            }
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        if ( 'cache_security_rules_failed' === $notice ) {
            $message = 'Cache and security preview could not be prepared. No rules were installed.';
            if ( ! empty( $_GET['acfcm_error'] ) ) {
                $message .= ' Details: ' . self::short_notice_message( wp_unslash( $_GET['acfcm_error'] ) );
            }
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        if ( 'hardening_rules_failed' === $notice ) {
            $message = 'Cloudflare hardening rules could not be installed or updated.';
            if ( ! empty( $_GET['acfcm_error'] ) ) {
                $message .= ' Details: ' . self::short_notice_message( wp_unslash( $_GET['acfcm_error'] ) );
            }
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    /* -------------------------------------------------------------------------
     * Logging
     * ---------------------------------------------------------------------- */

    public static function log_url( $url ) {
        $parts = wp_parse_url( (string) $url );
        return ! empty( $parts['host'] ) ? substr( ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( $parts['path'] ?? '/' ), 0, 300 ) : '';
    }

    private static function purge_log_cause( array $result ) {
        if ( ! empty( $result['accepted'] ) && ! empty( $result['queued'] ) ) {
            return 'Attempt accepted; newer content still pending';
        }
        if ( ! empty( $result['success'] ) ) {
            return 'Cloudflare accepted purge';
        }
        if ( ! empty( $result['transport_error'] ) ) {
            return 'WordPress transport failure';
        }
        $code = (int) ( $result['code'] ?? 0 );
        if ( $code ) {
            return 'HTTP ' . $code . ( ! empty( $result['queued'] ) ? '; retry pending' : '; failed or exhausted' );
        }
        // Only internal fixed messages are safe; never copy API/transport text.
        $message = (string) ( $result['message'] ?? '' );
        $safe = array( 'WP Engine page-cache purge failed; Cloudflare not sent.', 'WP Engine page-cache purge dispatched; Cloudflare queued.', 'WP Engine purge already in progress.', 'Purge cancelled: site disabled or zone changed.', 'Purge queue full or busy; request not sent.', 'Unable to schedule purge recovery; pending job retained.', 'Matching purge already pending.', 'Purge exhausted (five attempts or 24-hour lifetime).', 'Purge waiting for backoff or active request.', 'Unable to schedule purge recovery.', 'Another worker claimed this purge.', 'Missing Cloudflare Zone ID or API token.', 'WordPress transport failure.', 'Retry attempts exhausted.' );
        return in_array( $message, $safe, true ) ? $message : 'No request sent or transport failure';
    }

    public static function log_network_purge( $reason, array $results, $site_only = false ) {
        $ok = 0;
        $fail = 0;
        $pending = 0;
        foreach ( $results as $result ) {
            if ( ! empty( $result['result']['success'] ) ) {
                $ok++;
            } elseif ( ! empty( $result['result']['queued'] ) ) {
                $pending++;
            } else {
                $fail++;
            }
        }

        $details = array();
        foreach ( array_slice( $results, 0, 25 ) as $item ) {
            $r = $item['result'];
            $codes = array();
            foreach ( array_slice( $r['json']['errors'] ?? array(), 0, 5 ) as $error ) {
                $codes[] = (int) ( $error['code'] ?? 0 );
            }
            $urls = $r['urls'] ?? array();
            $details[] = array(
                'site_id' => (int) ( $r['site_id'] ?? 0 ),
                'zone' => sanitize_text_field( $item['zone_id'] ),
                'sites' => array_map( array( __CLASS__, 'log_url' ), array_slice( $item['sites'] ?? array(), 0, 10 ) ),
                'url_count' => count( $urls ),
                'urls' => array_map( array( __CLASS__, 'log_url' ), array_slice( $urls, 0, 10 ) ),
                'http' => (int) ( $r['code'] ?? 0 ),
                'errors' => $codes,
                'attempt' => (int) ( $r['attempt'] ?? 0 ),
                'outcome' => ! empty( $r['success'] ) ? 'success' : ( ! empty( $r['queued'] ) ? 'pending' : 'failed' ),
                'cause' => self::purge_log_cause( $r ),
                'retry_at' => (int) ( $r['retry_at'] ?? 0 ),
            );
        }
        $entry = array(
            'site_id'    => $site_only ? get_current_blog_id() : 0,
            'details'    => $details,
            'time'       => current_time( 'mysql' ),
            'reason'     => sanitize_text_field( $reason ),
            'zone_count' => count( $results ),
            'status'     => sprintf( '%d OK, %d pending, %d failed', $ok, $pending, $fail ),
        );

        $log = get_site_option( 'acfcm_purge_log', array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        $log[] = $entry;
        $log = array_slice( $log, -100 );
        update_site_option( 'acfcm_purge_log', $log );
    }

    public static function log_site_purge( $reason, $zone_id, array $urls, array $results ) {
        $formatted = array();
        foreach ( $results as $result ) {
            $formatted[] = array(
                'zone_id' => $zone_id,
                'sites'   => array( home_url( '/' ) ),
                'result'  => $result,
            );
        }
        if ( empty( $formatted ) ) {
            $formatted[] = array(
                'zone_id' => $zone_id,
                'sites'   => array( home_url( '/' ) ),
                'result'  => array( 'success' => false, 'message' => 'No request sent.' ),
            );
        }
        self::log_network_purge( $reason, $formatted, true );
    }

    /* -------------------------------------------------------------------------
     * GitHub release updater
     * ---------------------------------------------------------------------- */

    public static function github_headers() {
        $headers = array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Acquire-Cloudflare-Cache-Manager/' . self::VERSION,
        );
        $token = self::github_token();
        if ( $token ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    public static function github_latest_release() {
        if ( null !== self::$release_cache ) {
            return self::$release_cache;
        }

        $cached = get_site_transient( 'acfcm_github_release_cache' );
        if ( is_array( $cached ) ) {
            self::$release_cache = $cached;
            return $cached;
        }

        $repo = self::github_repo();
        if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
            return null;
        }

        $response = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', array(
            'timeout' => 15,
            'headers' => self::github_headers(),
        ) );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
            return null;
        }

        set_site_transient( 'acfcm_github_release_cache', $release, 6 * HOUR_IN_SECONDS );
        self::$release_cache = $release;
        return $release;
    }

    public static function github_release_version( array $release ) {
        $tag = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
        return ltrim( $tag, "vV \t\n\r\0\x0B" );
    }

    public static function github_package_url( array $release ) {
        // Prefer a manually attached release asset named acquire-cloudflare-cache-manager.zip.
        if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
                    continue;
                }
                if ( preg_match( '/\.zip$/i', $asset['name'] ) ) {
                    return esc_url_raw( $asset['browser_download_url'] );
                }
            }
        }

        // Fallback for public repos. A release asset zip with the correct root folder is more reliable.
        return ! empty( $release['zipball_url'] ) ? esc_url_raw( $release['zipball_url'] ) : '';
    }

    public static function github_check_for_update( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) ) {
            return $transient;
        }

        $release = self::github_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $new_version = self::github_release_version( $release );
        if ( ! $new_version || ! version_compare( $new_version, self::VERSION, '>' ) ) {
            return $transient;
        }

        $package = self::github_package_url( $release );
        if ( ! $package ) {
            return $transient;
        }

        $obj = (object) array(
            'slug'        => self::SLUG,
            'plugin'      => self::BASENAME,
            'new_version' => $new_version,
            'url'         => ! empty( $release['html_url'] ) ? esc_url_raw( $release['html_url'] ) : '',
            'package'     => $package,
            'tested'      => get_bloginfo( 'version' ),
            'requires'    => '5.9',
            'icons'       => self::github_plugin_icons(),
        );

        $transient->response[ self::BASENAME ] = $obj;
        return $transient;
    }

    public static function github_plugins_api( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
            return $result;
        }

        $release = self::github_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $new_version = self::github_release_version( $release );
        $body = ! empty( $release['body'] ) ? wp_kses_post( wpautop( $release['body'] ) ) : '<p>No release notes provided.</p>';

        return (object) array(
            'name'          => 'Acquire Cloudflare Cache Manager',
            'slug'          => self::SLUG,
            'version'       => $new_version,
            'author'        => '<a href="https://acquiredigital.co">Kyle Burns</a>',
            'homepage'      => ! empty( $release['html_url'] ) ? esc_url_raw( $release['html_url'] ) : 'https://acquiredigital.co',
            'requires'      => '5.9',
            'tested'        => get_bloginfo( 'version' ),
            'download_link' => self::github_package_url( $release ),
            'icons'         => self::github_plugin_icons(),
            'sections'      => array(
                'description' => '<p>Network-activated multisite Cloudflare cache manager.</p>',
                'changelog'   => $body,
            ),
        );
    }

    public static function github_fix_source_folder( $source, $remote_source, $upgrader, $hook_extra ) {
        global $wp_filesystem;

        if ( empty( $hook_extra['plugin'] ) || self::BASENAME !== $hook_extra['plugin'] ) {
            return $source;
        }

        if ( ! $wp_filesystem || ! $wp_filesystem->exists( $source ) ) {
            return $source;
        }

        $desired = trailingslashit( $remote_source ) . self::SLUG;
        if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
            return $source;
        }

        if ( $wp_filesystem->exists( $desired ) ) {
            $wp_filesystem->delete( $desired, true );
        }

        if ( $wp_filesystem->move( $source, $desired, true ) ) {
            return $desired;
        }

        return $source;
    }
}

endif;

register_activation_hook( __FILE__, array( 'Acquire_Cloudflare_Cache_Manager', 'activation_check' ) );
Acquire_Cloudflare_Cache_Manager::init();
