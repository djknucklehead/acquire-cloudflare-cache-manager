<?php
/** Durable maintenance coordinator. Ordinary content jobs do not use this queue. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ACFCM_Network_Queue {
    const KEY = 'acfcm_maintenance_queue';
    const HOOK = 'acfcm_maintenance_tick';
    const LEASE = 300;
    const MAX_TARGETS = 1000;
    private static $update_token = null;

    public static function init() {
        add_action( self::HOOK, array( __CLASS__, 'tick' ) );
        add_action( 'init', array( __CLASS__, 'recover' ) );
        add_filter( 'upgrader_pre_download', array( __CLASS__, 'before_download' ), 10, 4 );
        add_filter( 'upgrader_pre_install', array( __CLASS__, 'before_install' ), 10, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'update_notice' ) );
        add_action( 'network_admin_notices', array( __CLASS__, 'update_notice' ) );
        add_action( 'admin_post_acfcm_maintenance_control', array( __CLASS__, 'handle_control' ) );
    }
    public static function quiet_seconds() { return max( 60, min( 1800, (int) get_site_option( 'acfcm_maintenance_quiet', 180 ) ) ); }
    private static function session_due( $s ) {
        $due = max( $s['quiet_until'], ( $s['overflow_until'] ?? 0 ) ? $s['overflow_until'] + self::quiet_seconds() : 0 );
        foreach ( $s['updating'] as $until ) { $due = max( $due, $until + self::quiet_seconds() ); }
        return $due;
    }
    private static function update_start( $type ) {
        if ( ! Acquire_Cloudflare_Cache_Manager::network_auto_purge_enabled() || ! in_array( $type, Acquire_Cloudflare_Cache_Manager::network_update_types(), true ) ) { return; }
        if ( ! self::$update_token ) { self::$update_token = wp_generate_uuid4(); }
        self::enqueue( 'update_start_' . $type, 'start' );
    }
    public static function before_download( $reply, $package, $upgrader, $extra ) {
        if ( isset( $extra['action'] ) && 'update' !== $extra['action'] ) { return $reply; }
        foreach ( array( 'Plugin_Upgrader' => 'plugin', 'Theme_Upgrader' => 'theme', 'Core_Upgrader' => 'core', 'Language_Pack_Upgrader' => 'translation' ) as $class => $type ) {
            if ( is_a( $upgrader, $class ) ) { self::update_start( $type ); break; }
        }
        return $reply;
    }
    public static function before_install( $reply, $extra ) {
        if ( ! empty( $extra['plugin'] ) ) { self::update_start( 'plugin' ); }
        elseif ( ! empty( $extra['theme'] ) ) { self::update_start( 'theme' ); }
        return $reply;
    }
    public static function update_notice() {
        global $pagenow;
        if ( 'update-core.php' !== $pagenow || ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) { return; }
        echo '<div class="notice notice-info"><p>Cache maintenance waits for update activity to settle. For plugins → themes → core with long breaks, hold purges now and select Updates finished when done. Ordinary content edits continue.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'acfcm_maintenance_control' );
        echo '<input type="hidden" name="action" value="acfcm_maintenance_control"><input type="hidden" name="return_to" value="updates"><button class="button" name="queue_action" value="pause">Hold for updates</button> <button class="button" name="queue_action" value="resume">Updates finished</button></form></div>';
    }
    private static function main_id() { return is_multisite() ? (int) get_main_site_id() : get_current_blog_id(); }
    private static function table() { global $wpdb; return $wpdb->get_blog_prefix( self::main_id() ) . 'options'; }
    public static function state() {
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . self::table() . ' WHERE option_name = %s', self::KEY ) );
        return null === $raw ? false : maybe_unserialize( $raw );
    }
    private static function cas( $old, $new ) {
        global $wpdb;
        $table = self::table();
        if ( false === $old ) {
            $n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::KEY, maybe_serialize( $new ) ) );
        } else {
            $n = $wpdb->query( $wpdb->prepare( "UPDATE $table SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", maybe_serialize( $new ), self::KEY, maybe_serialize( $old ) ) );
        }
        // The coordinator always reads SQL; invalidate ordinary readers too.
        if ( self::main_id() === get_current_blog_id() ) { wp_cache_delete( self::KEY, 'options' ); }
        return 1 === $n;
    }
    public static function interval() { return max( 60, min( 3600, (int) get_site_option( 'acfcm_maintenance_interval', 120 ) ) ); }
    private static function pending( $t ) { return in_array( $t['stage'], array( 'origin', 'edge' ), true ); }
    private static function empty_state() {
        return array( 'id' => wp_generate_uuid4(), 'paused' => false, 'cancelled' => false, 'next_origin' => 0, 'next_edge' => 0, 'hold_until' => 0, 'failures' => 0, 'lease' => null, 'targets' => array(), 'cursor' => '', 'revision' => 0, 'quiet_until' => 0, 'updating' => array(), 'overflow_until' => 0 );
    }
    public static function enqueue( $reason, $activity = 'complete' ) {
        if ( Acquire_Cloudflare_Cache_Manager::is_clearing_wpengine() ) { return array( 'queued' => false ); }
        $sites = Acquire_Cloudflare_Cache_Manager::get_configured_sites();
        $hosts = array();
        foreach ( $sites as $site ) { $hosts[ strtolower( $site['hostname'] ) ][] = $site['blog_id']; }
        // Reject oversize inventories visibly, never silently discard targets.
        if ( count( $sites ) > self::MAX_TARGETS ) { return array( 'queued' => false, 'error' => 'Maintenance inventory exceeds 1000 sites.' ); }
        for ( $try = 0; $try < 20; $try++ ) {
            $old = self::state(); $s = $old ?: self::empty_state();
            $s['revision']++; $s['reason'] = sanitize_key( $reason ); $s['requested_at'] = time();
            $s['quiet_until'] = time() + self::quiet_seconds();
            foreach ( $s['updating'] as $key => $until ) { if ( $until < time() ) { unset( $s['updating'][$key] ); } }
            if ( 'start' === $activity ) { $s['updating'][ self::$update_token ] = time() + 900; }
            elseif ( self::$update_token ) { unset( $s['updating'][ self::$update_token ] ); }
            if ( count( $s['updating'] ) > 64 ) { $s['overflow_until'] = max( $s['updating'] ); $s['updating'] = array_slice( $s['updating'], -64, null, true ); }
            // Pause/cancel are sticky: new hooks accumulate work but never resume dispatch.
            foreach ( $sites as $site ) {
                if ( empty( $site['enabled'] ) || empty( $site['zone_id'] ) ) { continue; }
                $id = (string) $site['blog_id']; $home = wp_parse_url( $site['home_url'] );
                $host = strtolower( $home['host'] ?? '' );
                $identity = hash( 'sha256', $site['zone_id'] . '|' . $site['home_url'] );
                $safe = $host && preg_match( '/^[a-z0-9.-]+$/D', $host ) && empty( $home['port'] )
                    && ( $home['path'] ?? '/' ) === '/' && count( $hosts[ $host ] ?? array() ) === 1;
                $existing = $s['targets'][ $id ] ?? null;
                if ( $existing && $existing['identity'] === $identity && self::pending( $existing ) ) {
                    // Supersede edge readiness: a newer update must refresh origin again first.
                    $s['targets'][ $id ]['generation'] = $s['revision'];
                    $s['targets'][ $id ]['stage'] = 'origin';
                    $s['targets'][ $id ]['due'] = $s['quiet_until'];
                    if ( 'edge' === $existing['stage'] ) { $s['targets'][ $id ]['attempt'] = 0; }
                    elseif ( ( $s['lease']['site'] ?? '' ) === $id && ( $s['lease']['generation'] ?? null ) === $existing['generation'] ) { $s['targets'][ $id ]['attempt'] = max( 0, $existing['attempt'] - 1 ); }
                    continue;
                }
                // Failed targets require explicit retry; repeated hooks must not reset their retry budget.
                if ( $existing && $existing['identity'] === $identity && 'failed' === $existing['stage'] && ! ( 'scope_review_required' === $existing['error'] && $safe ) ) { continue; }
                $s['targets'][ $id ] = array( 'incarnation' => wp_generate_uuid4(), 'generation' => $s['revision'], 'site' => (int) $id, 'home' => $site['home_url'], 'host' => $host, 'zone' => $site['zone_id'], 'identity' => $identity,
                    'stage' => $safe ? 'origin' : 'failed', 'due' => $s['quiet_until'], 'created' => time(), 'attempt' => 0,
                    'error' => $safe ? '' : 'scope_review_required', 'origin_dispatched_at' => 0, 'completed_at' => 0 );
            }
            if ( count( $s['targets'] ) > self::MAX_TARGETS ) { return array( 'queued' => false, 'error' => 'Maintenance inventory exceeds 1000 sites.' ); }
            if ( self::cas( $old, $s ) ) { self::recover(); return array( 'queued' => true, 'locked' => false, 'reason' => $reason, 'results' => array() ); }
        }
        return array( 'queued' => false, 'locked' => true, 'error' => 'Maintenance queue busy; request not saved.' );
    }
    private static function next_due( $s ) {
        if ( ! $s || $s['paused'] || $s['cancelled'] ) { return 0; }
        $next = null;
        foreach ( $s['targets'] as $t ) {
            if ( ! self::pending( $t ) ) { continue; }
            $due = max( $t['due'], self::session_due( $s ), $s['hold_until'], 'origin' === $t['stage'] ? $s['next_origin'] : $s['next_edge'], $s['lease']['until'] ?? 0 );
            $next = null === $next ? $due : min( $next, $due );
        }
        return null === $next ? 0 : max( time() + 1, $next );
    }
    public static function recover() {
        $due = self::next_due( self::state() );
        if ( ! $due ) { return; }
        $switched = is_multisite() && get_current_blog_id() !== self::main_id();
        if ( $switched ) { switch_to_blog( self::main_id() ); }
        try {
            $scheduled = wp_next_scheduled( self::HOOK, array() );
            if ( ! $scheduled || $scheduled > $due ) {
                if ( $scheduled ) { wp_clear_scheduled_hook( self::HOOK, array() ); }
                wp_schedule_single_event( $due, self::HOOK, array() );
            }
        } finally { if ( $switched ) { restore_current_blog(); } }
    }
    public static function tick() {
        $claim = null;
        for ( $try = 0; $try < 20; $try++ ) {
            $old = self::state();
            if ( ! $old || $old['paused'] || $old['cancelled'] || ( $old['lease']['until'] ?? 0 ) > time() || $old['hold_until'] > time() || self::session_due( $old ) > time() ) { self::recover(); return; }
            $s = $old;
            // Rotate selection so repeated edits cannot monopolize the first slot.
            $ids = array_keys( $s['targets'] ); $at = array_search( $s['cursor'], $ids, true );
            if ( false !== $at ) { $ids = array_merge( array_slice( $ids, $at + 1 ), array_slice( $ids, 0, $at + 1 ) ); }
            foreach ( $ids as $id ) {
                $t = $s['targets'][ $id ];
                if ( ! self::pending( $t ) || max( $t['due'], 'origin' === $t['stage'] ? $s['next_origin'] : $s['next_edge'] ) > time() ) { continue; }
                if ( time() - $t['created'] >= 7 * DAY_IN_SECONDS || $t['attempt'] >= 5 ) {
                    $s['targets'][ $id ]['stage'] = 'failed'; $s['targets'][ $id ]['error'] = 'retry_budget_exhausted'; continue;
                }
                $s['targets'][ $id ]['attempt']++;
                $s['cursor'] = $id;
                $s['lease'] = array( 'token' => wp_generate_uuid4(), 'site' => (string) $id, 'generation' => $t['generation'], 'until' => time() + self::LEASE );
                // Reserve from actual claim time, not an overdue scheduled time: no catch-up burst.
                if ( 'origin' === $t['stage'] ) { $s['next_origin'] = time() + self::interval(); }
                else { $s['next_edge'] = time() + self::interval(); }
                $claim = array( 'target' => $s['targets'][ $id ], 'token' => $s['lease']['token'] ); break;
            }
            if ( $s === $old ) { self::recover(); return; }
            if ( ! self::cas( $old, $s ) ) { $claim = null; continue; }
            break;
        }
        self::recover(); // Persist recovery event before any provider request.
        if ( ! $claim ) { return; }
        $t = $claim['target']; $response = array( 'success' => false, 'retryable' => false, 'code' => 0 ); $error = 'scope_changed';
        $switched = is_multisite() && get_current_blog_id() !== $t['site'];
        if ( $switched ) { switch_to_blog( $t['site'] ); }
        try {
            $s = self::state();
            if ( ( $s['lease']['token'] ?? '' ) !== $claim['token'] || $s['paused'] || $s['cancelled'] || self::session_due( $s ) > time() || ( $s['targets'][(string)$t['site']]['generation'] ?? 0 ) !== $t['generation'] ) { $error = 'interrupted'; }
            elseif ( self::scope_valid( $t ) && Acquire_Cloudflare_Cache_Manager::is_site_enabled() && Acquire_Cloudflare_Cache_Manager::get_zone_id() === $t['zone'] && home_url( '/' ) === $t['home'] ) {
                $latest = self::state();
                if ( ( $latest['lease']['token'] ?? '' ) !== $claim['token'] || ( $latest['lease']['until'] ?? 0 ) <= time() || $latest['paused'] || $latest['cancelled'] || self::session_due( $latest ) > time() || ( $latest['targets'][(string)$t['site']]['generation'] ?? 0 ) !== $t['generation'] ) {
                    self::finish( $claim, $response, 'interrupted' ); self::recover(); return;
                }
                $error = '';
                if ( 'origin' === $t['stage'] ) {
                    $response = Acquire_Cloudflare_Cache_Manager::maintenance_origin_purge();
                } else {
                    $cooldown = 'acfcm_purge_cooldown_' . substr( hash( 'sha256', Acquire_Cloudflare_Cache_Manager::get_cf_api_token() ), 0, 24 );
                    $until = (int) get_site_transient( $cooldown );
                    if ( $until > time() ) { $response = array( 'success' => false, 'retryable' => true, 'code' => 429, 'retry_after' => $until - time() ); }
                    else { $response = Acquire_Cloudflare_Cache_Manager::cloudflare_post( $t['zone'], array( 'hosts' => array( $t['host'] ) ), 25 ); }
                    if ( 429 === (int) ( $response['code'] ?? 0 ) || ! empty( $response['retry_after'] ) ) {
                        set_site_transient( $cooldown, time() + max( 60, (int) ( $response['retry_after'] ?? 0 ) ), DAY_IN_SECONDS );
                    }
                }
            }
        } catch ( Throwable $e ) { $response = array( 'success' => false, 'retryable' => true, 'code' => 0 ); $error = 'transport_exception'; }
        finally { if ( $switched ) { restore_current_blog(); } }
        self::finish( $claim, $response, $error );
        self::recover();
    }
    private static function scope_valid( $target ) {
        $matches = array();
        foreach ( Acquire_Cloudflare_Cache_Manager::get_configured_sites() as $site ) {
            if ( strtolower( $site['hostname'] ) === $target['host'] ) { $matches[] = $site; }
        }
        return count( $matches ) === 1 && (int) $matches[0]['blog_id'] === $target['site'] && ! empty( $matches[0]['enabled'] );
    }
    private static function finish( $claim, $response, $error ) {
        for ( $try = 0; $try < 20; $try++ ) {
            $old = self::state();
            if ( ! $old || ( $old['lease']['token'] ?? '' ) !== $claim['token'] ) { return; }
            $s = $old; $s['lease'] = null; $id = (string) $claim['target']['site']; $t = $s['targets'][ $id ];
            if ( $s['cancelled'] || $t['incarnation'] !== $claim['target']['incarnation'] ) { /* New scope/cancel wins over an old completion. */ }
            elseif ( $t['generation'] !== $claim['target']['generation'] ) { /* New session owns this target; old dispatch cannot make its edge ready. */ }
            elseif ( 'interrupted' === $error ) { $t['attempt']--; $s['targets'][ $id ] = $t; }
            elseif ( ! empty( $response['success'] ) ) {
                $s['failures'] = 0; $t['error'] = ''; $t['attempt'] = 0;
                if ( 'origin' === $t['stage'] ) { $t['origin_dispatched_at'] = time(); $t['stage'] = 'edge'; $t['due'] = time() + 5; }
                else { $t['stage'] = 'done'; $t['completed_at'] = time(); }
                $s['targets'][ $id ] = $t;
            } else {
                $t['error'] = $error ?: ( 'origin' === $t['stage'] ? 'origin_dispatch_failed' : 'cloudflare_' . (int) ( $response['code'] ?? 0 ) );
                $s['failures']++;
                $delay = max( self::interval(), min( 3600, 60 * ( 2 ** min( 6, $s['failures'] ) ) ), (int) ( $response['retry_after'] ?? 0 ) );
                $s['hold_until'] = time() + $delay; // Passive circuit backoff: no probe traffic or crawler.
                $t['due'] = $s['hold_until'];
                if ( empty( $response['retryable'] ) || $t['attempt'] >= 5 ) { $t['stage'] = 'failed'; }
                $s['targets'][ $id ] = $t;
            }
            if ( self::cas( $old, $s ) ) { return; }
        }
        // Lease recovery will retry; never overwrite concurrent controls/enqueues.
    }
    public static function control( $action ) {
        for ( $try = 0; $try < 20; $try++ ) {
            $old = self::state(); if ( ! $old && 'pause' !== $action ) { return false; } $s = $old ?: self::empty_state();
            if ( 'pause' === $action ) { $s['paused'] = true; }
            elseif ( 'resume' === $action ) { $s['paused'] = false; $s['cancelled'] = false; $s['quiet_until'] = time() + self::quiet_seconds(); }
            elseif ( 'cancel' === $action ) { $s['cancelled'] = true; foreach ( $s['targets'] as &$t ) { if ( self::pending( $t ) ) { $t['stage'] = 'cancelled'; } } unset( $t ); }
            elseif ( 'retry_failed' === $action ) { foreach ( $s['targets'] as &$t ) { if ( 'failed' === $t['stage'] && 'scope_review_required' !== $t['error'] ) { $t['stage'] = 'origin'; $t['attempt'] = 0; $t['created'] = time(); $t['due'] = time() + self::interval(); } } unset( $t ); }
            else { return false; }
            // In-flight lease remains: resume/cancel cannot release another worker's claim.
            if ( $s === $old || self::cas( $old, $s ) ) { self::recover(); return true; }
        }
        return false;
    }
    public static function handle_control() {
        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) { wp_die( 'Insufficient permissions.' ); }
        check_admin_referer( 'acfcm_maintenance_control' );
        $action = sanitize_key( wp_unslash( $_POST['queue_action'] ?? '' ) );
        if ( 'interval' === $action ) { update_site_option( 'acfcm_maintenance_interval', max( 60, min( 3600, (int) ( $_POST['interval'] ?? 120 ) ) ) ); update_site_option( 'acfcm_maintenance_quiet', max( 60, min( 1800, (int) ( $_POST['quiet'] ?? 180 ) ) ) ); }
        else { self::control( $action ); }
        if ( 'updates' === ( $_POST['return_to'] ?? '' ) ) { $redirect = is_multisite() ? network_admin_url( 'update-core.php' ) : admin_url( 'update-core.php' ); }
        else { $redirect = is_multisite() ? network_admin_url( 'settings.php?page=acfcm-network' ) : admin_url( 'options-general.php?page=acfcm-cloudflare-cache' ); }
        wp_safe_redirect( $redirect ); exit;
    }
    public static function render() {
        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) { return; }
        $s = self::state();
        echo '<h2>Paced maintenance purges</h2><p>Paced site maintenance. Page-cache dispatches and Cloudflare requests are each spaced by the configured interval. Native WP Engine purges are outside this control.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'acfcm_maintenance_control' );
        echo '<input type="hidden" name="action" value="acfcm_maintenance_control"><label>Interval (seconds) <input type="number" min="60" max="3600" name="interval" value="' . esc_attr( self::interval() ) . '"></label> <label>Quiet period (seconds) <input type="number" min="60" max="1800" name="quiet" value="' . esc_attr( self::quiet_seconds() ) . '"></label> <button class="button" name="queue_action" value="interval">Save timing</button> ';
        foreach ( array( 'pause' => 'Hold for updates / pause', 'resume' => 'Updates finished / resume', 'cancel' => 'Cancel pending', 'retry_failed' => 'Retry failed' ) as $key => $label ) { echo '<button class="button" name="queue_action" value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button> '; }
        echo '</form><p>Pause/cancel cannot recall a request already dispatched. Cancel remains stopped until Resume; later update hooks may accumulate new work while stopped.</p>';
        if ( ! $s ) { echo '<p>No maintenance queue yet.</p>'; return; }
        $counts = array_count_values( array_column( $s['targets'], 'stage' ) );
        echo '<p><strong>Queue: ' . esc_html( $s['cancelled'] ? 'Cancelled' : ( $s['paused'] ? 'Paused' : 'Active / idle' ) ) . '</strong></p><div class="acfcm-queue-counts">';
        foreach ( array( 'origin' => 'Origin pending', 'edge' => 'Cloudflare pending', 'done' => 'Accepted', 'failed' => 'Failed', 'cancelled' => 'Cancelled' ) as $stage => $label ) {
            echo '<div><strong>' . (int) ( $counts[$stage] ?? 0 ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
        }
        echo '</div><p>Updates/quiet period until: ' . esc_html( self::session_due( $s ) ? gmdate( 'Y-m-d H:i:s', self::session_due( $s ) ) . ' UTC' : 'none' ) . '<br>Next eligible: ' . esc_html( self::next_due( $s ) ? gmdate( 'Y-m-d H:i:s', self::next_due( $s ) ) . ' UTC' : 'none' ) . '</p>';
        echo '<table class="widefat"><thead><tr><th>Site</th><th>Stage</th><th>Next due (UTC)</th><th>Failure</th></tr></thead><tbody>';
        foreach ( $s['targets'] as $t ) { echo '<tr><td>' . esc_html( $t['home'] ) . '</td><td>' . esc_html( array( 'origin' => 'Origin pending', 'edge' => 'Cloudflare pending', 'done' => 'Cloudflare accepted', 'failed' => 'Failed', 'cancelled' => 'Cancelled' )[$t['stage']] ?? $t['stage'] ) . '</td><td>' . esc_html( self::pending( $t ) ? gmdate( 'Y-m-d H:i:s', max( $t['due'], self::session_due( $s ), $s['hold_until'], 'origin' === $t['stage'] ? $s['next_origin'] : $s['next_edge'] ) ) : '—' ) . '</td><td>' . esc_html( $t['error'] ) . '</td></tr>'; }
        echo '</tbody></table><p>Origin stage means dispatched, not confirmed invalidation. Failed scope checks require review; no zone-wide fallback is used.</p>';
    }
}
