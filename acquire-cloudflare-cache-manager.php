<?php
/**
 * Plugin Name: Acquire Cloudflare Cache Manager
 * Plugin URI:  https://acquiredigital.co
 * Description: Cloudflare cache manager for standalone WordPress and multisite networks, with optional per-site purging, update-triggered full-zone purges, recommended cache rule setup, and GitHub release update checks.
 * Version:     3.1.1
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

if ( ! class_exists( 'Acquire_Cloudflare_Cache_Manager' ) ) :

final class Acquire_Cloudflare_Cache_Manager {
    const VERSION       = '3.1.1';
    const DEFAULT_GITHUB_REPO = 'djknucklehead/acquire-cloudflare-cache-manager';
    const SLUG          = 'acquire-cloudflare-cache-manager';
    const BASENAME      = 'acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php';
    const MODE_AUTO     = 'auto';
    const MODE_ENABLED  = 'enabled';
    const MODE_DISABLED = 'disabled';
    const CACHE_RULE_PHASE = 'http_request_cache_settings';
    const CACHE_EVERYTHING_RULE_NAME = 'Cache Everything [Template]';
    const BYPASS_RULE_NAME = 'BYPASS';

    /** @var array|null */
    private static $release_cache = null;

    public static function init() {
        // Subsite-facing behavior. Each callback exits immediately unless the current site is enabled.
        add_action( 'send_headers', array( __CLASS__, 'send_logged_in_nocache_headers' ) );
        add_action( 'save_post', array( __CLASS__, 'purge_on_save_post' ), 10, 3 );
        add_action( 'future_to_publish', array( __CLASS__, 'purge_on_future_to_publish' ), 10, 1 );
        add_action( 'before_delete_post', array( __CLASS__, 'purge_on_before_delete_post' ), 10, 1 );

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

        $response = wp_remote_request(
            "https://api.cloudflare.com/client/v4/zones/{$zone_id}/{$path}",
            $args
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'code'    => 0,
                'message' => $response->get_error_message(),
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

    public static function purge_zone_everything( $zone_id ) {
        return self::cloudflare_post( $zone_id, array( 'purge_everything' => true ), 25 );
    }

    public static function install_recommended_cache_rules( $zone_id ) {
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

        $rules = self::recommended_cache_rules();
        $entry = self::cloudflare_request(
            'GET',
            $zone_id,
            'rulesets/phases/' . self::CACHE_RULE_PHASE . '/entrypoint',
            null,
            20
        );

        if ( ! $entry['success'] ) {
            if ( 404 === (int) $entry['code'] ) {
                return self::cloudflare_request(
                    'POST',
                    $zone_id,
                    'rulesets',
                    array(
                        'kind'        => 'zone',
                        'name'        => 'Cloudflare Cache Rules',
                        'phase'       => self::CACHE_RULE_PHASE,
                        'description' => 'Cache rules managed by Acquire Cloudflare Cache Manager.',
                        'rules'       => $rules,
                    ),
                    30
                );
            }

            return $entry;
        }

        $ruleset = is_array( $entry['result'] ) ? $entry['result'] : array();
        $ruleset_id = isset( $ruleset['id'] ) ? (string) $ruleset['id'] : '';
        $existing_rules = isset( $ruleset['rules'] ) && is_array( $ruleset['rules'] ) ? $ruleset['rules'] : array();
        $merged_rules = self::merge_recommended_cache_rules( $existing_rules, $rules );

        $payload = array(
            'kind'        => isset( $ruleset['kind'] ) ? (string) $ruleset['kind'] : 'zone',
            'name'        => isset( $ruleset['name'] ) ? (string) $ruleset['name'] : 'Cloudflare Cache Rules',
            'phase'       => self::CACHE_RULE_PHASE,
            'description' => isset( $ruleset['description'] ) ? (string) $ruleset['description'] : 'Cache rules managed by Acquire Cloudflare Cache Manager.',
            'rules'       => $merged_rules,
        );

        $path = $ruleset_id ? 'rulesets/' . rawurlencode( $ruleset_id ) : 'rulesets/phases/' . self::CACHE_RULE_PHASE . '/entrypoint';

        return self::cloudflare_request( 'PUT', $zone_id, $path, $payload, 30 );
    }

    public static function recommended_cache_rules() {
        return array(
            self::recommended_cache_everything_rule(),
            self::recommended_bypass_rule(),
        );
    }

    public static function recommended_cache_everything_rule() {
        return array(
            'description'       => self::CACHE_EVERYTHING_RULE_NAME,
            'expression'        => 'true',
            'action'            => 'set_cache_settings',
            'enabled'           => true,
            'action_parameters' => array(
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
                            'status_code_range' => array(
                                'from' => 300,
                            ),
                            'value'             => 0,
                        ),
                    ),
                ),
                'cache_key'                 => array(
                    'cache_deception_armor'     => false,
                    'ignore_query_strings_order' => false,
                    'custom_key'                => array(
                        'query_string' => array(
                            'exclude' => array( '*' ),
                        ),
                    ),
                ),
                'cache_reserve'             => array(
                    'eligible' => false,
                ),
                'origin_error_page_passthru' => true,
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
        $managed_names = array(
            self::CACHE_EVERYTHING_RULE_NAME,
            self::BYPASS_RULE_NAME,
        );
        $merged = $recommended_rules;

        foreach ( $existing_rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            $description = isset( $rule['description'] ) ? (string) $rule['description'] : '';
            if ( in_array( $description, $managed_names, true ) ) {
                continue;
            }

            $merged[] = self::prepare_ruleset_rule_for_update( $rule );
        }

        return $merged;
    }

    public static function prepare_ruleset_rule_for_update( array $rule ) {
        unset( $rule['last_updated'], $rule['version'] );
        return $rule;
    }

    public static function purge_urls_for_current_site( array $urls ) {
        $zone_id = self::get_zone_id();
        $urls    = self::normalize_urls( $urls );

        if ( empty( $zone_id ) || empty( $urls ) ) {
            return array();
        }

        $results = array();
        foreach ( array_chunk( $urls, 30 ) as $batch ) {
            $results[] = self::cloudflare_post( $zone_id, array( 'files' => array_values( $batch ) ), 20 );
        }
        return $results;
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

    public static function purge_on_save_post( $post_id, $post, $update ) {
        if ( ! self::should_purge_post( $post_id ) ) {
            return;
        }

        $current_ts = (int) strtotime( get_post_modified_time( 'Y-m-d H:i:s', true, $post_id ) );
        $last_ts    = (int) get_option( 'cloudflare_cache_timestamp_' . $post_id, 0 );

        if ( $current_ts <= $last_ts ) {
            return;
        }

        $urls    = self::post_related_urls( $post_id );
        $results = self::purge_urls_for_current_site( $urls );

        update_option( 'cloudflare_cache_timestamp_' . $post_id, $current_ts );
        self::log_site_purge( 'content_change', self::get_zone_id(), $urls, $results );
    }

    public static function purge_on_future_to_publish( $post ) {
        if ( empty( $post ) || empty( $post->ID ) || ! self::should_purge_post( $post->ID ) ) {
            return;
        }
        $urls    = self::post_related_urls( $post->ID );
        $results = self::purge_urls_for_current_site( $urls );
        update_option( 'cloudflare_cache_timestamp_' . (int) $post->ID, (int) current_time( 'timestamp', true ) );
        self::log_site_purge( 'scheduled_publish', self::get_zone_id(), $urls, $results );
    }

    public static function purge_on_before_delete_post( $post_id ) {
        if ( ! self::is_content_auto_purge_enabled() ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return;
        }
        $urls    = self::post_related_urls( $post_id );
        $results = self::purge_urls_for_current_site( $urls );
        self::log_site_purge( 'delete_post', self::get_zone_id(), $urls, $results );
    }

    /* -------------------------------------------------------------------------
     * Network-wide purge
     * ---------------------------------------------------------------------- */

    public static function get_configured_sites() {
        $sites = array();

        if ( is_multisite() ) {
            $blog_ids = get_sites( array(
                'number'   => 0,
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

            $zone_id = self::get_zone_id();
            $mode    = self::get_site_mode();
            $enabled = self::is_site_enabled();

            $sites[] = array(
                'blog_id'  => $blog_id,
                'name'     => get_bloginfo( 'name' ),
                'home_url' => home_url( '/' ),
                'zone_id'  => $zone_id,
                'mode'     => $mode,
                'enabled'  => $enabled,
            );

            if ( is_multisite() ) {
                restore_current_blog();
            }
        }

        return $sites;
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
        $lock_key = 'acfcm_network_purge_lock';
        if ( get_site_transient( $lock_key ) ) {
            return array(
                'locked'  => true,
                'reason'  => $reason,
                'results' => array(),
            );
        }

        set_site_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

        $zones   = self::get_enabled_zones();
        $results = array();

        foreach ( $zones as $zone_id => $zone_data ) {
            $result = self::purge_zone_everything( $zone_id );
            $results[] = array(
                'zone_id' => $zone_id,
                'sites'   => wp_list_pluck( $zone_data['sites'], 'home_url' ),
                'result'  => $result,
            );
        }

        delete_site_transient( $lock_key );
        self::log_network_purge( $reason, $results );

        return array(
            'locked'  => false,
            'reason'  => $reason,
            'results' => $results,
        );
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

        // Automatic updates can include mixed core/theme/plugin/translation work. One deduped purge is enough.
        self::purge_all_enabled_zones( 'automatic_updates_complete' );
    }

    public static function purge_network_after_external_cache_clear( $context = '' ) {
        if ( ! self::network_external_cache_purge_enabled() ) {
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

        if ( isset( $_POST['acfcm_save_site_settings'] ) || isset( $_POST['acfcm_save_site_settings_and_rules'] ) ) {
            check_admin_referer( 'acfcm_save_site_settings' );

            $mode = isset( $_POST['acfcm_cloudflare_mode'] ) ? sanitize_key( wp_unslash( $_POST['acfcm_cloudflare_mode'] ) ) : self::MODE_AUTO;
            if ( ! in_array( $mode, array( self::MODE_AUTO, self::MODE_ENABLED, self::MODE_DISABLED ), true ) ) {
                $mode = self::MODE_AUTO;
            }

            update_option( 'acfcm_cloudflare_mode', $mode );
            update_option( 'cloudflare_zone_id', sanitize_text_field( wp_unslash( $_POST['cloudflare_zone_id'] ?? '' ) ) );
            update_option( 'acfcm_content_auto_purge', isset( $_POST['acfcm_content_auto_purge'] ) ? '1' : '0' );
            update_option( 'acfcm_logged_in_nocache', isset( $_POST['acfcm_logged_in_nocache'] ) ? '1' : '0' );

            if ( ! defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ! defined( 'CLOUDFLARE_API_TOKEN' ) && ! get_site_option( 'acfcm_cloudflare_api_token', '' ) ) {
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
                self::render_cache_rules_result_notice( self::install_recommended_cache_rules( self::get_zone_id() ) );
            }
        }

        $mode            = self::get_site_mode();
        $zone_id         = self::get_zone_id();
        $enabled         = self::is_site_enabled();
        $token_source    = self::cf_token_source_label();
        $token_editable  = ! defined( 'ACFCM_CLOUDFLARE_API_TOKEN' ) && ! defined( 'CLOUDFLARE_API_TOKEN' ) && ! get_site_option( 'acfcm_cloudflare_api_token', '' );
        $update_types    = self::network_update_types();
        $github_repo_editable  = ! defined( 'ACFCM_GITHUB_REPO' );
        $github_token_editable = ! defined( 'ACFCM_GITHUB_TOKEN' );
        $log             = get_site_option( 'acfcm_purge_log', array() );
        ?>
        <div class="wrap">
            <h1>Cloudflare Cache</h1>

            <?php if ( $is_multisite ) : ?>
                <p>This subsite is currently <strong><?php echo $enabled ? 'enabled' : 'disabled'; ?></strong> for Cloudflare purge behavior.</p>
            <?php else : ?>
                <p>This standalone WordPress site is currently <strong><?php echo $enabled ? 'enabled' : 'disabled'; ?></strong> for Cloudflare purge behavior.</p>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'acfcm_save_site_settings' ); ?>

                <h2><?php echo $is_multisite ? 'Subsite Settings' : 'Site Settings'; ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="acfcm_cloudflare_mode">Site Mode</label></th>
                        <td>
                            <select name="acfcm_cloudflare_mode" id="acfcm_cloudflare_mode">
                                <option value="auto" <?php selected( $mode, self::MODE_AUTO ); ?>>Auto — enable only if a Zone ID exists</option>
                                <option value="enabled" <?php selected( $mode, self::MODE_ENABLED ); ?>>Enabled</option>
                                <option value="disabled" <?php selected( $mode, self::MODE_DISABLED ); ?>>Disabled</option>
                            </select>
                            <p class="description">Auto mode preserves older installs: if this site already has a saved Zone ID, purge features automatically engage.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cloudflare_zone_id">Cloudflare Zone ID</label></th>
                        <td><input type="text" name="cloudflare_zone_id" id="cloudflare_zone_id" class="regular-text" value="<?php echo esc_attr( $zone_id ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Automatic Content Purge</th>
                        <td><label><input type="checkbox" name="acfcm_content_auto_purge" value="1" <?php checked( get_option( 'acfcm_content_auto_purge', '1' ), '1' ); ?>> Purge related URLs when public content changes</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Logged-in No-cache Headers</th>
                        <td><label><input type="checkbox" name="acfcm_logged_in_nocache" value="1" <?php checked( get_option( 'acfcm_logged_in_nocache', '1' ), '1' ); ?>> Send no-cache headers for logged-in users</label></td>
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
                    <?php submit_button( 'Save Settings', 'primary', 'acfcm_save_site_settings', false ); ?>
                    <?php submit_button( 'Save & Install Recommended Cache Rules', 'secondary', 'acfcm_save_site_settings_and_rules', false ); ?>
                </p>
            </form>

            <hr>
            <h2>Recommended Cache Rules</h2>
            <p>Creates or updates the <code><?php echo esc_html( self::CACHE_EVERYTHING_RULE_NAME ); ?></code> and <code><?php echo esc_html( self::BYPASS_RULE_NAME ); ?></code> rules for the current site’s Zone ID. Existing Cloudflare cache rules with other names are preserved.</p>
            <p class="description">The Cloudflare API token needs Cache Rules and Rulesets edit permissions for this action.</p>
            <p>
                <?php if ( $zone_id ) : ?>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_install_cache_rules' ), 'acfcm_install_cache_rules' ) ); ?>" onclick="return confirm('Install or update the recommended Cloudflare cache rules for this zone?');">Install/Update Recommended Cache Rules</a>
                <?php else : ?>
                    Save a Cloudflare Zone ID before installing recommended cache rules.
                <?php endif; ?>
            </p>

            <hr>
            <h2>Purge Actions</h2>
            <p>These buttons use the current site’s Zone ID.</p>
            <p>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_home' ), 'acfcm_purge_home' ) ); ?>">Purge Homepage</a>
                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_site_everything' ), 'acfcm_purge_site_everything' ) ); ?>" onclick="return confirm('Purge EVERYTHING for this Cloudflare zone?');">Purge Everything</a>
            </p>

            <?php if ( ! $is_multisite ) : ?>
                <hr>
                <h2>Recent Purge Log</h2>
                <?php if ( empty( $log ) ) : ?>
                    <p>No purge log entries yet.</p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><th>Time</th><th>Reason</th><th>Zones</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ( array_slice( array_reverse( $log ), 0, 25 ) as $entry ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( $entry['reason'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( (string) ( $entry['zone_count'] ?? 0 ) ); ?></td>
                                    <td><?php echo esc_html( $entry['status'] ?? '' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_clear_log' ), 'acfcm_clear_log' ) ); ?>">Clear Log</a></p>
                <?php endif; ?>
            <?php endif; ?>
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

            foreach ( $site_modes as $blog_id => $mode ) {
                $blog_id = (int) $blog_id;
                $mode    = sanitize_key( $mode );
                if ( ! in_array( $mode, array( self::MODE_AUTO, self::MODE_ENABLED, self::MODE_DISABLED ), true ) ) {
                    $mode = self::MODE_AUTO;
                }
                switch_to_blog( $blog_id );
                update_option( 'acfcm_cloudflare_mode', $mode );
                if ( isset( $zone_ids[ $blog_id ] ) ) {
                    update_option( 'cloudflare_zone_id', sanitize_text_field( $zone_ids[ $blog_id ] ) );
                }
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
        <div class="wrap">
            <h1>Cloudflare Cache Manager</h1>
            <p>Network-activated Cloudflare cache management. Subsites can be Auto, Enabled, or Disabled.</p>

            <h2>Network Settings</h2>
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
                        <td><label><input type="checkbox" name="acfcm_network_auto_purge" value="1" <?php checked( self::network_auto_purge_enabled() ); ?>> Purge all enabled Cloudflare zones after selected WordPress updates</label></td>
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
            <h2>Network Purge</h2>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_network_everything' ), 'acfcm_purge_network_everything' ) ); ?>" onclick="return confirm('Purge EVERYTHING for every enabled Cloudflare zone on this network?');">Purge All Enabled Zones</a>
            </p>

            <hr>
            <h2>Subsites</h2>
            <form method="post">
                <?php wp_nonce_field( 'acfcm_save_sites' ); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Mode</th>
                            <th>Effective</th>
                            <th>Zone ID</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sites as $site ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $site['name'] ); ?></strong><br><a href="<?php echo esc_url( $site['home_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $site['home_url'] ); ?></a></td>
                                <td>
                                    <select name="acfcm_site_mode[<?php echo (int) $site['blog_id']; ?>]">
                                        <option value="auto" <?php selected( $site['mode'], self::MODE_AUTO ); ?>>Auto</option>
                                        <option value="enabled" <?php selected( $site['mode'], self::MODE_ENABLED ); ?>>Enabled</option>
                                        <option value="disabled" <?php selected( $site['mode'], self::MODE_DISABLED ); ?>>Disabled</option>
                                    </select>
                                </td>
                                <td><?php echo $site['enabled'] ? '<span style="color:#008a20;font-weight:600;">Enabled</span>' : '<span style="color:#8a0000;font-weight:600;">Disabled</span>'; ?></td>
                                <td><input type="text" class="regular-text" name="acfcm_zone_id[<?php echo (int) $site['blog_id']; ?>]" value="<?php echo esc_attr( $site['zone_id'] ); ?>"></td>
                                <td>
                                    <?php if ( ! empty( $site['zone_id'] ) ) : ?>
                                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_network_site&blog_id=' . (int) $site['blog_id'] ), 'acfcm_purge_network_site_' . (int) $site['blog_id'] ) ); ?>" onclick="return confirm('Purge EVERYTHING for this site’s Cloudflare zone?');">Purge Zone</a>
                                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_install_network_site_cache_rules&blog_id=' . (int) $site['blog_id'] ), 'acfcm_install_cache_rules_' . (int) $site['blog_id'] ) ); ?>" onclick="return confirm('Install or update the recommended Cloudflare cache rules for this site zone?');">Install Rules</a>
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

            <hr>
            <h2>Recent Purge Log</h2>
            <?php if ( empty( $log ) ) : ?>
                <p>No purge log entries yet.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th>Time</th><th>Reason</th><th>Zones</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ( array_slice( array_reverse( $log ), 0, 25 ) as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                <td><?php echo esc_html( $entry['reason'] ?? '' ); ?></td>
                                <td><?php echo esc_html( (string) ( $entry['zone_count'] ?? 0 ) ); ?></td>
                                <td><?php echo esc_html( $entry['status'] ?? '' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_clear_log' ), 'acfcm_clear_log' ) ); ?>">Clear Log</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* -------------------------------------------------------------------------
     * Toolbar and admin-post handlers
     * ---------------------------------------------------------------------- */

    public static function register_toolbar_menu( $wp_admin_bar ) {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) || ! self::is_site_enabled() ) {
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
            'title'  => 'Purge Everything',
            'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=acfcm_purge_site_everything' ), 'acfcm_purge_site_everything' ),
        ) );
    }

    public static function handle_purge_home() {
        if ( ! current_user_can( 'manage_options' ) ) {
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

    public static function handle_purge_site_everything() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'acfcm_purge_site_everything' );

        $zone_id = self::get_zone_id();
        $result  = self::purge_zone_everything( $zone_id );
        self::log_site_purge( 'manual_site_everything', $zone_id, array(), array( $result ) );

        wp_safe_redirect( add_query_arg( 'acfcm_notice', 'site_all', wp_get_referer() ?: admin_url() ) );
        exit;
    }

    public static function handle_purge_network_everything() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'acfcm_purge_network_everything' );

        $summary = self::purge_all_enabled_zones( 'manual_network' );
        $notice  = ! empty( $summary['locked'] ) ? 'network_locked' : 'network_all';

        wp_safe_redirect( add_query_arg( 'acfcm_notice', $notice, network_admin_url( 'settings.php?page=acfcm-network' ) ) );
        exit;
    }

    public static function handle_purge_network_site() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        $blog_id = isset( $_GET['blog_id'] ) ? (int) $_GET['blog_id'] : 0;
        check_admin_referer( 'acfcm_purge_network_site_' . $blog_id );

        $zone_id = self::get_zone_id( $blog_id );
        $result  = self::purge_zone_everything( $zone_id );
        self::log_network_purge( 'manual_single_site_' . $blog_id, array( array( 'zone_id' => $zone_id, 'sites' => array( $blog_id ), 'result' => $result ) ) );

        wp_safe_redirect( add_query_arg( 'acfcm_notice', 'network_site', network_admin_url( 'settings.php?page=acfcm-network' ) ) );
        exit;
    }

    public static function handle_install_cache_rules() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'acfcm_install_cache_rules' );

        $result = self::install_recommended_cache_rules( self::get_zone_id() );
        $redirect = wp_get_referer() ?: admin_url( 'options-general.php?page=acfcm-cloudflare-cache' );

        wp_safe_redirect( add_query_arg( self::cache_rules_redirect_args( $result ), $redirect ) );
        exit;
    }

    public static function handle_install_network_site_cache_rules() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $blog_id = isset( $_GET['blog_id'] ) ? (int) $_GET['blog_id'] : 0;
        check_admin_referer( 'acfcm_install_cache_rules_' . $blog_id );

        $result = self::install_recommended_cache_rules( self::get_zone_id( $blog_id ) );

        wp_safe_redirect( add_query_arg( self::cache_rules_redirect_args( $result ), network_admin_url( 'settings.php?page=acfcm-network' ) ) );
        exit;
    }

    public static function cache_rules_redirect_args( array $result ) {
        $args = array(
            'acfcm_notice' => ! empty( $result['success'] ) ? 'cache_rules' : 'cache_rules_failed',
        );

        if ( empty( $result['success'] ) && ! empty( $result['message'] ) ) {
            $args['acfcm_error'] = self::short_notice_message( $result['message'] );
        }

        return $args;
    }

    public static function render_cache_rules_result_notice( array $result ) {
        $success = ! empty( $result['success'] );
        $message = $success
            ? 'Cloudflare recommended cache rules installed or updated.'
            : 'Cloudflare recommended cache rules could not be installed or updated.';

        if ( ! $success && ! empty( $result['message'] ) ) {
            $message .= ' Cloudflare said: ' . self::short_notice_message( $result['message'] );
        }

        echo '<div class="notice ' . esc_attr( $success ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
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
            'home'           => 'Cloudflare homepage purge requested.',
            'site_all'       => 'Cloudflare purge everything requested for this site.',
            'network_all'    => 'Cloudflare purge everything requested for all enabled zones.',
            'network_locked' => 'A network purge is already running or just completed. Try again shortly if needed.',
            'network_site'   => 'Cloudflare purge everything requested for that site zone.',
            'cache_rules'    => 'Cloudflare recommended cache rules installed or updated.',
            'log_cleared'    => 'Cloudflare purge log cleared.',
        );
        if ( isset( $messages[ $notice ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
        }

        if ( 'cache_rules_failed' === $notice ) {
            $message = 'Cloudflare recommended cache rules could not be installed or updated.';
            if ( ! empty( $_GET['acfcm_error'] ) ) {
                $message .= ' Cloudflare said: ' . self::short_notice_message( wp_unslash( $_GET['acfcm_error'] ) );
            }
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    /* -------------------------------------------------------------------------
     * Logging
     * ---------------------------------------------------------------------- */

    public static function log_network_purge( $reason, array $results ) {
        $ok = 0;
        $fail = 0;
        foreach ( $results as $result ) {
            if ( ! empty( $result['result']['success'] ) ) {
                $ok++;
            } else {
                $fail++;
            }
        }

        $entry = array(
            'time'       => current_time( 'mysql' ),
            'reason'     => sanitize_text_field( $reason ),
            'zone_count' => count( $results ),
            'status'     => sprintf( '%d OK, %d failed', $ok, $fail ),
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
        self::log_network_purge( $reason, $formatted );
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
