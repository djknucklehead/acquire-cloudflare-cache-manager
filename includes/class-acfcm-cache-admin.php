<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ACFCM_Cache_Admin {
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_acfcm_cache_policy', array( __CLASS__, 'handle' ) );
        add_action( 'wp_ajax_acfcm_cache_policy', array( __CLASS__, 'ajax' ) );
        add_action( 'wp_ajax_acfcm_network_zone', array( __CLASS__, 'network_ajax' ) );
        add_action( 'admin_post_acfcm_network_zone', array( __CLASS__, 'network_handle' ) );
        add_action( 'admin_post_acfcm_purge_url', array( __CLASS__, 'purge_url' ) );
        add_action( 'deactivate_acquire-cloudflare-cache-manager/acquire-cloudflare-cache-manager.php', array( __CLASS__, 'deactivate' ) );
    }
    public static function assets() {
        if ( ! in_array( $_GET['page'] ?? '', array( 'acfcm-cloudflare-cache', 'acfcm-network' ), true ) ) { return; }
        $url = plugins_url( 'assets/', dirname( __DIR__ ) . '/acquire-cloudflare-cache-manager.php' );
        // Candidate ZIPs can share a plugin version; changed assets must still
        // get a new URL so old handlers cannot run against new form markup.
        $dir = dirname( __DIR__ ) . '/assets/';
        $css_version = Acquire_Cloudflare_Cache_Manager::VERSION . '-' . substr( hash_file( 'sha256', $dir . 'admin.css' ), 0, 12 );
        $js_version = Acquire_Cloudflare_Cache_Manager::VERSION . '-' . substr( hash_file( 'sha256', $dir . 'admin.js' ), 0, 12 );
        wp_enqueue_style( 'acfcm-admin', $url . 'admin.css', array(), $css_version );
        wp_enqueue_script( 'acfcm-admin', $url . 'admin.js', array(), $js_version, true );
    }
    private static function authorize() {
        if ( ! Acquire_Cloudflare_Cache_Manager::current_user_can_manage_site_cloudflare() ) { wp_die( 'You do not have permission to manage this site’s Cloudflare configuration.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'acfcm_cache_policy' );
    }
    public static function execute( $action ) {
        $zone = Acquire_Cloudflare_Cache_Manager::get_zone_id();
        if ( 'security' === $action ) {
            $r = ACFCM_Defense::install( $zone, array( 'defense_baseline' => true ) );
            update_option( 'acfcm_security_result', array( 'success' => $r['success'], 'message' => $r['message'] ), false );
            if ( empty( $r['success'] ) ) { throw new RuntimeException( $r['message'] ); }
            return array( 'status' => 'complete', 'message' => $r['message'] );
        }
        if ( 'install' === $action ) {
            $preview = ACFCM_Cache_Policy::preview( $zone, true );
            $j = ACFCM_Cache_Policy::begin( $preview['id'] );
        } elseif ( 'preview' === $action ) {
            $result = Acquire_Cloudflare_Cache_Manager::install_recommended_cache_rules( $zone );
            if ( empty( $result['success'] ) ) { throw new RuntimeException( $result['message'] ); }
            return array( 'status' => 'preview' );
        }
        if ( 'rollback_preview' === $action ) { ACFCM_Cache_Policy::rollback_preview( $zone ); return array( 'status' => 'rollback_preview' ); }
        if ( in_array( $action, array( 'begin', 'rollback' ), true ) && empty( $_POST['profile_ack'] ) ) { throw new RuntimeException( 'Review the scope and prerequisites, then check the acknowledgment before applying.' ); }
        if ( 'install' === $action ) { /* Backup and guard prepared above; AJAX advances the saved plan. */ } elseif ( 'begin' === $action ) {
            $j = ACFCM_Cache_Policy::begin( sanitize_text_field( wp_unslash( $_POST['preview_id'] ?? '' ) ) );
            $j['defense_requested'] = ! empty( $_POST['also_defense'] ) && ! empty( $j['combined'] ) && isset( $j['defense_before'] ); ACFCM_Cache_Policy::store( $zone, $j );
        } elseif ( 'rollback' === $action ) { $j = ACFCM_Cache_Policy::begin_rollback( sanitize_text_field( wp_unslash( $_POST['preview_id'] ?? '' ) ) ); }
        elseif ( 'step' === $action ) { $j = ACFCM_Cache_Policy::step( $zone ); }
        else { throw new RuntimeException( 'Unknown cache-policy action.' ); }
        if ( 'complete' === $j['status'] && ! empty( $j['defense_requested'] ) && empty( $j['defense_done'] ) ) {
            $r = ACFCM_Defense::install( $zone, array( 'defense_baseline' => true ), $j['defense_before'] );
            $j['defense_done'] = true; $j['defense_result'] = array( 'success' => $r['success'], 'message' => $r['message'] ); ACFCM_Cache_Policy::store( $zone, $j );
        }
        return array( 'status' => $j['status'], 'cursor' => $j['cursor'], 'total' => count( $j['plan']['operations'] ), 'error' => $j['error'] ?? '', 'defense' => $j['defense_result'] ?? null );
    }
    public static function ajax() {
        self::authorize();
        try { wp_send_json_success( self::execute( sanitize_key( $_POST['policy_action'] ?? '' ) ) ); }
        catch ( RuntimeException $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 409 ); }
    }
    public static function handle() {
        self::authorize();
        if ( 'export' === ( $_POST['policy_action'] ?? '' ) ) {
            $journal = ACFCM_Cache_Policy::store( Acquire_Cloudflare_Cache_Manager::get_zone_id() );
            if ( ! $journal || $journal['site'] !== get_current_blog_id() ) { wp_die( 'No migration backup belongs to this site.', '', array( 'response' => 403 ) ); }
            nocache_headers(); header( 'Content-Type: application/json' ); header( 'Content-Disposition: attachment; filename="acfcm-cache-migration-backup.json"' );
            echo wp_json_encode( $journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); exit;
        }
        try { $r = self::execute( sanitize_key( $_POST['policy_action'] ?? '' ) ); update_option( 'acfcm_cache_notice', $r['error'] ?? 'Cache policy ' . $r['status'] . '.', false ); }
        catch ( RuntimeException $e ) { update_option( 'acfcm_cache_notice', $e->getMessage(), false ); }
        wp_safe_redirect( admin_url( 'options-general.php?page=acfcm-cloudflare-cache#acfcm-policy' ) ); exit;
    }
    public static function network_execute( array $request ) {
        if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) { throw new RuntimeException( 'Network Admin permission required.' ); }
        $id = absint( $request['blog_id'] ?? 0 );
        $site = get_site( $id );
        if ( ! $site || (int) $site->network_id !== (int) get_current_network_id() ) { throw new RuntimeException( 'Site is outside this network.' ); }
        $zone = Acquire_Cloudflare_Cache_Manager::get_zone_id( $id );
        if ( ! $zone || $zone !== ( $request['zone'] ?? '' ) || ! wp_verify_nonce( $request['_wpnonce'] ?? '', 'acfcm_network_zone_' . $id . '_' . $zone ) ) { throw new RuntimeException( 'Zone changed or request expired. Reload the network screen.' ); }
        $action = sanitize_key( $request['policy_action'] ?? '' );
        if ( ! in_array( $action, array( 'install', 'security', 'step', 'export' ), true ) ) { throw new RuntimeException( 'Unknown zone action.' ); }
        switch_to_blog( $id );
        try {
            if ( 'export' === $action ) {
                $j = ACFCM_Cache_Policy::store( $zone );
                if ( ! $j || $j['site'] !== $id ) { throw new RuntimeException( 'No cache backup belongs to this site.' ); }
                return array( 'backup' => $j );
            }
            return self::execute( $action );
        } finally { restore_current_blog(); }
    }
    public static function network_ajax() {
        try { $result = self::network_execute( wp_unslash( $_POST ) ); }
        catch ( RuntimeException $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 409 ); return; }
        wp_send_json_success( $result );
    }
    public static function network_handle() {
        try {
            $result = self::network_execute( wp_unslash( $_POST ) );
            if ( isset( $result['backup'] ) ) {
                nocache_headers(); header( 'Content-Type: application/json' ); header( 'Content-Disposition: attachment; filename="acfcm-cache-backup.json"' );
                echo wp_json_encode( $result['backup'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); exit;
            }
        } catch ( RuntimeException $e ) { wp_die( esc_html( $e->getMessage() ), '', array( 'back_link' => true ) ); }
        wp_safe_redirect( network_admin_url( 'settings.php?page=acfcm-network#acfcm-sites' ) ); exit;
    }
    public static function network_site_buttons( array $site ) {
        $id = (int) $site['blog_id']; $zone = $site['zone_id'];
        $j = ACFCM_Cache_Policy::store( $zone );
        $owned = $j && (int) $j['site'] === $id;
        $pending = $owned && ! in_array( $j['status'], array( 'complete', 'rolled_back' ), true );
        $actions = array( $pending ? 'step' : 'install' => $pending ? 'Resume cache installation' : 'Install cache rules', 'security' => 'Install security rules' );
        if ( $owned ) { $actions['export'] = 'Download cache backup'; }
        foreach ( $actions as $action => $label ) {
            $form = 'acfcm-zone-' . $id . '-' . $action;
            echo '<button type="submit" class="button" form="' . esc_attr( $form ) . '">' . esc_html( $label ) . '</button><p id="' . esc_attr( $form . '-status' ) . '" class="acfcm-progress" role="status" tabindex="-1" aria-live="polite"></p>';
        }
        if ( $owned ) { echo '<p>Cache: ' . esc_html( $j['status'] ) . '. ' . esc_html( $j['error'] ?? '' ) . '</p>'; }
        $security = get_blog_option( $id, 'acfcm_security_result', array() );
        if ( $security ) { echo '<p>Security: ' . esc_html( $security['message'] ) . '</p>'; }
    }
    public static function network_zones( array $sites ) {
        // External forms keep the row buttons out of the site's settings form.
        foreach ( $sites as $site ) {
            if ( empty( $site['zone_id'] ) ) { continue; }
            $id = (int) $site['blog_id']; $zone = $site['zone_id'];
            foreach ( array( 'install', 'step', 'security', 'export' ) as $action ) {
                $form = 'acfcm-zone-' . $id . '-' . $action;
                echo '<form id="' . esc_attr( $form ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( 'export' !== $action ? ' class="acfcm-migrate" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"' : '' ) . '>';
                wp_nonce_field( 'acfcm_network_zone_' . $id . '_' . $zone );
                echo '<input type="hidden" name="action" value="acfcm_network_zone"><input type="hidden" name="blog_id" value="' . $id . '"><input type="hidden" name="zone" value="' . esc_attr( $zone ) . '"><input type="hidden" name="policy_action" value="' . esc_attr( $action ) . '"></form>';
            }
        }
    }
    public static function validate_settings( $zone, $mode, $auto ) {
        $scope = get_option( 'acfcm_public_cache_scope', array() );
        if ( $scope && ( $zone !== $scope['zone'] || 'disabled' === $mode || ! $auto ) ) {
            wp_die( 'This site has an active managed cache policy. Keep its Zone ID and automatic content purging enabled until that policy is reviewed and rolled back or disabled in Cloudflare.' );
        }
    }
    public static function valid_purge_url( $url ) {
        $home = wp_parse_url( home_url( '/' ) ); $target = wp_parse_url( $url );
        if ( ! $target || ! in_array( $target['scheme'] ?? '', array( 'http', 'https' ), true ) || ( $target['host'] ?? '' ) !== $home['host'] || isset( $target['user'] ) || isset( $target['pass'] ) || ( $target['port'] ?? null ) !== ( $home['port'] ?? null ) ) { return false; }
        $path = rawurldecode( $target['path'] ?? '/' );
        // Reject ambiguous path traversal rather than purging a neighboring subsite.
        if ( preg_match( '~(?:^|/)\.{1,2}(?:/|$)|[\\\\\x00-\x1f]|%~', $path ) ) { return false; }
        return strpos( $path, $home['path'] ?? '/' ) === 0;
    }
    public static function purge_url() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Insufficient permissions.' ); }
        check_admin_referer( 'acfcm_purge_url' );
        $url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
        if ( ! self::valid_purge_url( $url ) ) { wp_die( 'Enter a public URL belonging to this site.' ); }
        $results = Acquire_Cloudflare_Cache_Manager::purge_urls_for_current_site( array( $url ) );
        Acquire_Cloudflare_Cache_Manager::log_site_purge( 'manual_url', Acquire_Cloudflare_Cache_Manager::get_zone_id(), array( $url ), $results );
        $ok = ! empty( $results ); foreach ( $results as $r ) { if ( empty( $r['success'] ) && empty( $r['queued'] ) ) { $ok = false; } }
        update_option( 'acfcm_cache_notice', $ok ? 'URL purge accepted or queued. WP Engine page cache runs before Cloudflare.' : 'URL purge failed. Review the purge log.', false );
        wp_safe_redirect( admin_url( 'options-general.php?page=acfcm-cloudflare-cache' ) ); exit;
    }
    public static function deactivate( $network_wide = false ) {
        $ids = is_multisite() && $network_wide ? get_sites( array( 'number' => 0, 'fields' => 'ids', 'network_id' => 0 ) ) : array( get_current_blog_id() );
        foreach ( $ids as $id ) {
            if ( is_multisite() ) { switch_to_blog( $id ); }
            $scope = get_option( 'acfcm_public_cache_scope', array() );
            if ( is_multisite() ) { restore_current_blog(); }
            if ( $scope ) { wp_die( 'A managed public-cache policy still depends on this plugin for content invalidation. Review/roll back or disable that policy before deactivating. The persistent MU guard is intentionally retained; do not delete it while the policy is active.', 'Cache policy still active', array( 'back_link' => true ) ); }
        }
    }
    public static function summary( $network = false ) {
        $p = 'Acquire_Cloudflare_Cache_Manager';
        echo '<div class="acfcm-intro"><p class="acfcm-eyebrow">ACQUIRE · CLOUDFLARE</p><p>Refresh content, review caching policies, and keep maintenance under control.</p></div><div class="acfcm-summary">';
        $items = $network ? array( 'Configured sites' => count( $p::get_configured_sites() ), 'Enabled zones' => count( $p::get_enabled_zones() ), 'Update maintenance' => $p::network_auto_purge_enabled() ? 'Automatic' : 'Manual' ) : array( 'Site caching' => $p::is_site_enabled() ? 'Enabled' : 'Disabled', 'Content purging' => $p::is_content_auto_purge_enabled() ? 'Automatic' : 'Manual', 'Connection' => $p::get_cf_api_token() ? 'Token configured' : 'Token needed' );
        foreach ( $items as $label => $value ) { echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>'; }
        echo '</div><nav class="acfcm-nav" aria-label="Cache manager sections">';
        foreach ( $network ? array( 'acfcm-maintenance' => 'Maintenance', 'acfcm-sites' => 'Sites', 'acfcm-connection' => 'Settings', 'acfcm-security' => 'Security', 'acfcm-activity' => 'Activity' ) : array( 'acfcm-refresh' => 'Refresh content', 'acfcm-policy' => 'Cache policy', 'acfcm-connection' => 'Settings', 'acfcm-security' => 'Security', 'acfcm-activity' => 'Activity' ) as $id => $label ) { echo '<a href="#' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>'; }
        echo '</nav>';
        $notice = get_option( 'acfcm_cache_notice', '' ); if ( $notice ) { echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>'; }
    }
    public static function refresh() {
        echo '<section class="acfcm-card" id="acfcm-refresh"><h2>Refresh a page</h2><p>Clear the page cache for one URL. WP Engine runs first, followed by Cloudflare.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'acfcm_purge_url' ); echo '<input type="hidden" name="action" value="acfcm_purge_url"><label for="acfcm-refresh-url">Public page URL</label><div class="acfcm-inline"><input id="acfcm-refresh-url" type="url" name="url" class="regular-text" required value="' . esc_attr( home_url( '/' ) ) . '"><button class="button button-primary">Refresh page</button></div></form></section>';
    }
    private static function form( $action, $label, $preview = null, $migration = false ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $migration ? ' class="acfcm-migrate" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"' : '' ) . '>';
        wp_nonce_field( 'acfcm_cache_policy' ); echo '<input type="hidden" name="action" value="acfcm_cache_policy"><input type="hidden" name="policy_action" value="' . esc_attr( $action ) . '">';
        if ( $preview ) {
            echo '<input type="hidden" name="preview_id" value="' . esc_attr( $preview['id'] ) . '"><p><label><input type="checkbox" name="profile_ack" value="1" required> I reviewed this site’s scope, the rules being replaced and the backup. This is a public informational site; personalized/server-side tracking and time-sensitive forms need separate review. The persistent MU guard must remain installed.</label></p>';
            if ( 'begin' === $action && ! empty( $preview['combined'] ) ) { echo '<p><label><input type="checkbox" name="also_defense" value="1"> Also explicitly verify/onboard the security defense baseline for this zone after caching completes (separate policy; not rolled back with caching).</label></p>'; }
        }
        echo '<button class="button ' . ( $migration ? 'button-primary' : 'button-secondary' ) . '">' . esc_html( $label ) . '</button><p class="acfcm-progress" role="status" tabindex="-1" aria-live="polite"></p></form>';
    }
    public static function policy() {
        echo '<section class="acfcm-card" id="acfcm-policy"><h2>Public-page cache policy</h2><p>Seven days at the edge for eligible anonymous public pages; 24 hours for pages rendering Gravity Forms. Submissions, private sessions and unknown queries bypass. Tracking URLs remain visible, but an edge hit does not run server-side tracking.</p>';
        if ( ! Acquire_Cloudflare_Cache_Manager::current_user_can_manage_site_cloudflare() ) { echo '<p>Network Admin manages this policy while the shared token is in use.</p></section>'; return; }
        $zone = Acquire_Cloudflare_Cache_Manager::get_zone_id(); $j = $zone ? ACFCM_Cache_Policy::store( $zone ) : array();
        if ( $j && $j['site'] !== get_current_blog_id() ) { echo '<p>This zone has a migration belonging to another WordPress site. Network Admin must review the shared scope before making changes.</p></section>'; return; }
        if ( $j ) {
            echo '<div class="acfcm-policy-status"><strong>Migration: ' . esc_html( $j['status'] ) . '</strong><p>Step ' . (int) $j['cursor'] . ' of ' . count( $j['plan']['operations'] ) . '. ' . esc_html( $j['error'] ?? '' ) . '</p><p>Scope: ' . esc_html( $j['host'] ) . ' · Zone ' . esc_html( $zone ) . '</p></div>';
            if ( ! empty( $j['defense_result'] ) ) { echo '<p>Security: ' . esc_html( $j['defense_result']['message'] ) . '</p>'; }
            if ( ! in_array( $j['status'], array( 'complete', 'rolled_back' ), true ) ) { self::form( 'step', 'Resume cache installation', null, true ); }
            self::form( 'export', 'Download complete migration backup' );
            if ( 'rollback' !== $j['direction'] ) { self::form( 'rollback_preview', 'Preview rollback' ); }
        }
        if ( ! $j || in_array( $j['status'], array( 'complete', 'rolled_back' ), true ) ) {
            echo '<p>Install backs up and replaces existing cache rules, including custom exceptions, with the standard public-page rules.</p>';
            self::form( 'install', 'Install cache rules', null, true );
        }
        self::form( 'security', 'Install security rules', null, true );
        $security = get_option( 'acfcm_security_result', array() );
        if ( $security ) { echo '<p role="status">Security: ' . esc_html( $security['message'] ) . '</p>'; }
        $rollback = get_option( 'acfcm_cache_rollback_preview', array() );
        if ( $rollback && $rollback['zone'] === $zone && $rollback['user'] === get_current_user_id() && $j && 'rollback' !== $j['direction'] ) {
            echo '<details open class="acfcm-preview"><summary>Rollback preview</summary><p>Restores the saved rule definitions and ordering under a temporary bypass. Recreated rules receive new IDs; newly created phase containers remain empty. Concurrent drift stops recovery. The MU guard is retained. Zone-level Tiered Cache settings and separate security additions are retained; their original configuration is in the backup for separate review.</p><pre>' . esc_html( wp_json_encode( $rollback['operations'], JSON_PRETTY_PRINT ) ) . '</pre>'; self::form( 'rollback', 'Apply reviewed rollback', $rollback, true ); echo '</details>';
        }
        echo '</section>';
    }
}
