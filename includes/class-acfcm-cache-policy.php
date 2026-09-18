<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Explicit, journaled migration. No upgrade, edit or purge hook invokes this class. */
final class ACFCM_Cache_Policy {
    const REQUEST = 'http_request_cache_settings';
    const RESPONSE = 'http_response_cache_settings';
    const TEMP = 'acfcm_public_migration_hold';
    const LIMIT = 10;
    public static function phases() { return array( self::REQUEST, self::RESPONSE ); }
    public static function clean( array $r, $identity = true ) {
        unset( $r['version'], $r['last_updated'] );
        if ( ! $identity ) { unset( $r['id'], $r['ref'] ); }
        return $r;
    }
    public static function hash( array $s ) {
        $out = array();
        foreach ( self::phases() as $phase ) { $out[$phase] = array( $s[$phase]['id'] ?? null, $s[$phase]['version'] ?? null, ACFCM_Defense::fingerprint( $s[$phase]['rules'] ?? array() ) ); }
        return hash( 'sha256', wp_json_encode( $out ) );
    }
    private static function api( $method, $zone, $path, $body = null ) { return Acquire_Cloudflare_Cache_Manager::cloudflare_request( $method, $zone, $path, $body, 10 ); }
    public static function read( $zone ) {
        $state = array();
        foreach ( self::phases() as $phase ) {
            $r = self::api( 'GET', $zone, 'rulesets/phases/' . $phase . '/entrypoint' );
            if ( empty( $r['success'] ) && 404 === (int) $r['code'] ) { $state[$phase] = null; continue; }
            $s = $r['result'] ?? null;
            if ( empty( $r['success'] ) || ! is_array( $s ) || empty( $s['id'] ) || empty( $s['version'] ) || ( $s['phase'] ?? '' ) !== $phase || ( $s['kind'] ?? '' ) !== 'zone' || ! isset( $s['rules'] ) || ! is_array( $s['rules'] ) ) { throw new RuntimeException( 'Could not read complete cache rulesets. Check Cache Rules/Rulesets permissions; no safety fallback will be used.' ); }
            foreach ( $s['rules'] as $rule ) { if ( ! is_array( $rule ) || empty( $rule['id'] ) || empty( $rule['ref'] ) || ! isset( $rule['expression'], $rule['action'] ) ) { throw new RuntimeException( 'Incomplete cache-rule identity; review the zone.' ); } }
            $state[$phase] = $s;
        }
        if ( strlen( wp_json_encode( $state ) ) > 262144 ) { throw new RuntimeException( 'Cache configuration exceeds the 256 KB backup limit. Use a separately reviewed migration.' ); }
        return $state;
    }
    public static function store( $zone, $value = null ) {
        $switched = false;
        if ( is_multisite() && get_current_blog_id() !== get_main_site_id( get_main_network_id() ) ) { switch_to_blog( get_main_site_id( get_main_network_id() ) ); $switched = true; }
        try {
            $key = 'acfcm_cache_migration_' . $zone;
            if ( null !== $value ) { update_option( $key, $value, false ); }
            return get_option( $key, array() );
        } finally { if ( $switched ) { restore_current_blog(); } }
    }
    public static function host() {
        $u = wp_parse_url( home_url( '/' ) );
        $host = strtolower( $u['host'] ?? '' );
        if ( ! preg_match( '/^[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z]{2,}$/', $host ) || ! empty( $u['port'] ) || ( $u['path'] ?? '/' ) !== '/' || ( $u['scheme'] ?? '' ) !== 'https' ) { throw new RuntimeException( 'This profile requires a canonical HTTPS hostname at the domain root. Subdirectory, port and shared-host installs need individual review.' ); }
        return $host;
    }
    public static function desired( $host, $reserve = false ) {
        $base = require __DIR__ . '/cache-template.php';
        $canonical = preg_replace( '/^www\./', '', $host );
        $base = json_decode( str_replace( 'ACFCM_HOST', $canonical, wp_json_encode( $base ) ), true );
        if ( $reserve ) {
            foreach ( array( 0, 1 ) as $i ) { $base['request']['rules'][$i]['action_parameters']['cache_reserve'] = array( 'eligible' => true, 'minimum_file_size' => 50000 ); }
        }
        return array( self::REQUEST => $base['request']['rules'], self::RESPONSE => $base['response']['rules'] );
    }
    private static function same_rules( array $a, array $b, $identity = true ) {
        return ACFCM_Defense::fingerprint( array_map( function( $r ) use ( $identity ) { return self::clean( $r, $identity ); }, $a ) ) === ACFCM_Defense::fingerprint( array_map( function( $r ) use ( $identity ) { return self::clean( $r, $identity ); }, $b ) );
    }
    private static function legacy( array $r, $host ) {
        $p = 'Acquire_Cloudflare_Cache_Manager';
        $candidates = array( $p::recommended_cache_everything_rule( true ), $p::recommended_cache_everything_rule( false ), $p::recommended_bypass_rule(), $p::recommended_cache_reserve_rule( $host ) );
        foreach ( $candidates as $c ) { if ( $c && self::same_rules( array( $r ), array( $c ), false ) ) { return true; } }
        return false;
    }
    private static function safe_external( array $r, $phase, $host ) {
        if ( preg_match( '/^(?:ACFCM|Public pages|Public static|BYPASS|Cache Everything|Gravity Forms HTML|Form HTML|Preserve private)/', $r['description'] ?? '' ) ) { return false; }
        if ( self::REQUEST === $phase && ( $r['action_parameters'] ?? array() ) === array( 'cache' => false ) ) { return true; }
        if ( self::RESPONSE === $phase && ( $r['action_parameters'] ?? array() ) === array( 'no-store' => array( 'cloudflare_only' => true, 'operation' => 'set' ) ) ) { return true; }
        // Only a complete, simple disjoint-host predicate is proven disjoint here.
        if ( preg_match( '/^http\.host eq "([a-z0-9.-]+)"$/', $r['expression'], $m ) ) { return ! in_array( $m[1], array( $host, 'www.' . preg_replace( '/^www\./', '', $host ), preg_replace( '/^www\./', '', $host ) ), true ); }
        return false;
    }
    public static function plan( $zone, $host, array $state, $reserve = false, array $trusted = array() ) {
        $map = require __DIR__ . '/cache-adoptions.php';
        $wanted = self::desired( $host, $reserve );
        $known = $map[$zone] ?? null;
        if ( $known && $known['host'] !== preg_replace( '/^www\./', '', $host ) ) { throw new RuntimeException( 'Reviewed zone/hostname mapping does not match this site.' ); }
        $match = function( $hashes ) use ( $state ) { foreach ( self::phases() as $p ) { if ( ACFCM_Defense::fingerprint( $state[$p]['rules'] ?? array() ) !== $hashes[$p] ) { return false; } } return true; };
        if ( $known && isset( $known['after'] ) && $match( $known['after'] ) ) { return array( 'kind' => 'adopt', 'desired' => array(), 'remove' => array(), 'operations' => array() ); }
        $reviewed_legacy = $known && $match( $known['before'] );
        $previous = ! empty( $trusted['expected'] ) && self::hash( $trusted['expected'] ) === self::hash( $state ) && 'complete' === ( $trusted['status'] ?? '' );
        $remove = array(); $external = array(); $owned = array();
        foreach ( self::phases() as $p ) {
            foreach ( $state[$p]['rules'] ?? array() as $r ) {
                if ( ( $r['ref'] ?? '' ) === self::TEMP ) { throw new RuntimeException( 'A migration hold is present. Resume or review the saved migration instead of starting another.' ); }
                $is_new = strpos( $r['ref'], 'acfcm_public_v1_' ) === 0;
                $valid_new = false;
                foreach ( $wanted[$p] as $w ) { if ( $r['ref'] === $w['ref'] && self::same_rules( array( $r ), array( $w ), false ) ) { $valid_new = true; } }
                if ( $reviewed_legacy || ( $is_new && ( $valid_new || $previous ) ) || ( self::REQUEST === $p && self::legacy( $r, $host ) ) ) { $remove[$p][] = $r; if ( $is_new ) { $owned[$p][] = $r; } }
                elseif ( self::safe_external( $r, $p, $host ) ) { $external[$p][] = $r; }
                else { throw new RuntimeException( 'Unknown or modified cache rule: ' . sanitize_text_field( $r['description'] ?? $r['ref'] ) . '. Review ownership and intentional exceptions before migration.' ); }
            }
        }
        if ( $previous && isset( $trusted['plan']['desired'][self::REQUEST][0]['action_parameters'] ) ) {
            $wanted[self::REQUEST][0]['action_parameters'] = $trusted['plan']['desired'][self::REQUEST][0]['action_parameters'];
            if ( $reserve ) { $wanted[self::REQUEST][0]['action_parameters']['cache_reserve'] = array( 'eligible' => true, 'minimum_file_size' => 50000 ); }
        }
        $complete = true;
        foreach ( self::phases() as $p ) { if ( ! self::same_rules( $owned[$p] ?? array(), $wanted[$p], false ) || count( $remove[$p] ?? array() ) !== 3 ) { $complete = false; } }
        if ( $complete ) { return array( 'kind' => 'adopt', 'desired' => $wanted, 'remove' => array(), 'operations' => array() ); }
        // Preserve the old static action settings, but never its HTML tracking-key override.
        foreach ( $remove[self::REQUEST] ?? array() as $r ) {
            if ( ( $r['description'] ?? '' ) === Acquire_Cloudflare_Cache_Manager::CACHE_EVERYTHING_RULE_NAME ) {
                $static = $r['action_parameters']; unset( $static['cache_key'] );
                $wanted[self::REQUEST][0]['action_parameters'] = $static;
                if ( $reserve ) { $wanted[self::REQUEST][0]['action_parameters']['cache_reserve'] = array( 'eligible' => true, 'minimum_file_size' => 50000 ); }
                break;
            }
        }
        // Hold first; remove only proven owned definitions, then add coherent policy before exceptions.
        $ops = array( array( 'method' => 'POST', 'phase' => self::REQUEST, 'rule' => self::hold( $host ) ) );
        foreach ( array( self::RESPONSE, self::REQUEST ) as $p ) {
            foreach ( $remove[$p] ?? array() as $r ) { $ops[] = array( 'method' => 'DELETE', 'phase' => $p, 'ref' => $r['ref'], 'id' => $r['id'] ); }
            foreach ( $wanted[$p] as $i => $r ) { $ops[] = array( 'method' => 'POST', 'phase' => $p, 'rule' => $r, 'index' => $i + 1 ); }
            if ( count( $state[$p]['rules'] ?? array() ) + ( self::REQUEST === $p ? 1 : 0 ) > self::LIMIT || count( $external[$p] ?? array() ) + 3 + ( self::REQUEST === $p ? 1 : 0 ) > self::LIMIT ) { throw new RuntimeException( 'Insufficient cache-rule quota for the temporary migration hold and complete policy. Nothing will be dropped or silently downgraded.' ); }
        }
        $ops[] = array( 'method' => 'DELETE', 'phase' => self::REQUEST, 'ref' => self::TEMP );
        return array( 'kind' => empty( $remove ) ? 'new' : 'migrate', 'desired' => $wanted, 'remove' => $remove, 'operations' => $ops );
    }
    public static function replacement_plan( $host, array $state, $reserve = false ) {
        $wanted = self::desired( $host, $reserve );
        $remove = array(); $same = true;
        foreach ( self::phases() as $phase ) {
            $rules = $state[$phase]['rules'] ?? array();
            foreach ( $rules as $rule ) {
                if ( $rule['ref'] === self::TEMP ) { throw new RuntimeException( 'A migration hold is present. Resume or roll back the saved migration first.' ); }
            }
            $remove[$phase] = $rules;
            if ( ! self::same_rules( $rules, $wanted[$phase], false ) ) { $same = false; }
            if ( count( $rules ) + ( self::REQUEST === $phase ? 1 : 0 ) > self::LIMIT ) { throw new RuntimeException( 'Insufficient cache-rule quota for the temporary migration hold.' ); }
        }
        if ( $same ) { return array( 'kind' => 'adopt', 'desired' => $wanted, 'remove' => array(), 'operations' => array() ); }
        $ops = array( array( 'method' => 'POST', 'phase' => self::REQUEST, 'rule' => self::hold( $host ) ) );
        foreach ( array( self::RESPONSE, self::REQUEST ) as $phase ) {
            foreach ( $remove[$phase] as $rule ) { $ops[] = array( 'method' => 'DELETE', 'phase' => $phase, 'ref' => $rule['ref'], 'id' => $rule['id'] ); }
            foreach ( $wanted[$phase] as $i => $rule ) { $ops[] = array( 'method' => 'POST', 'phase' => $phase, 'rule' => $rule, 'index' => $i + 1 ); }
        }
        $ops[] = array( 'method' => 'DELETE', 'phase' => self::REQUEST, 'ref' => self::TEMP );
        return array( 'kind' => 'replace', 'desired' => $wanted, 'remove' => $remove, 'operations' => $ops );
    }
    public static function hold( $host ) { return array( 'ref' => self::TEMP, 'description' => 'ACFCM migration hold - review saved migration before removing', 'action' => 'set_cache_settings', 'action_parameters' => array( 'cache' => false, 'browser_ttl' => array( 'mode' => 'respect_origin' ) ), 'enabled' => true, 'expression' => 'http.host in {"' . preg_replace( '/^www\./', '', $host ) . '" "www.' . preg_replace( '/^www\./', '', $host ) . '"}' ); }
    public static function tiered_settings( $zone ) {
        $settings = array();
        foreach ( array( Acquire_Cloudflare_Cache_Manager::TIERED_CACHE_SETTING_PATH, Acquire_Cloudflare_Cache_Manager::SMART_TIERED_CACHE_SETTING_PATH ) as $path ) {
            $r = self::api( 'GET', $zone, $path );
            if ( empty( $r['success'] ) || ! in_array( $r['result']['value'] ?? '', array( 'on', 'off' ), true ) ) { throw new RuntimeException( 'Could not back up Tiered Cache settings. Check zone permissions before applying this option.' ); }
            $settings[$path] = $r['result'];
        }
        return $settings;
    }
    public static function preview( $zone, $replace_existing = false ) {
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $zone ) ) { throw new RuntimeException( 'Save a valid Cloudflare Zone ID first.' ); }
        $host = self::host();
        if ( ! Acquire_Cloudflare_Cache_Manager::is_content_auto_purge_enabled() ) { throw new RuntimeException( 'Enable this site and automatic content purging before applying a public cache policy.' ); }
        foreach ( Acquire_Cloudflare_Cache_Manager::get_configured_sites() as $site ) {
            if ( $site['zone_id'] === $zone && (int) $site['blog_id'] !== get_current_blog_id() ) { throw new RuntimeException( 'This zone is configured on more than one WordPress site. Review a coordinated zone policy before migrating.' ); }
        }
        if ( is_multisite() ) {
            foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids', 'network_id' => 0 ) ) as $blog_id ) {
                if ( (int) $blog_id !== get_current_blog_id() && Acquire_Cloudflare_Cache_Manager::get_zone_id( $blog_id ) === $zone ) { throw new RuntimeException( 'This zone is also configured on another site or network. A coordinated zone review is required.' ); }
            }
        }
        $guard = ACFCM_Runtime_Guard::inspect();
        $journal = self::store( $zone );
        if ( $journal && ( $journal['site'] !== get_current_blog_id() || $journal['host'] !== $host ) ) { throw new RuntimeException( 'This zone has a migration journal owned by another site or hostname. Review that scope first.' ); }
        if ( $journal && ! in_array( $journal['status'], array( 'complete', 'rolled_back' ), true ) ) { throw new RuntimeException( 'An unfinished migration exists. Review its status, resume or prepare rollback before creating a new preview.' ); }
        $state = self::read( $zone );
        $plan = $replace_existing ? self::replacement_plan( $host, $state, Acquire_Cloudflare_Cache_Manager::is_cache_reserve_enabled() ) : self::plan( $zone, $host, $state, Acquire_Cloudflare_Cache_Manager::is_cache_reserve_enabled(), $journal );
        $preview = array( 'id' => wp_generate_uuid4(), 'created' => time(), 'user' => get_current_user_id(), 'site' => get_current_blog_id(), 'zone' => $zone, 'host' => $host, 'before' => $state, 'plan' => $plan, 'guard' => $guard, 'tiered' => Acquire_Cloudflare_Cache_Manager::is_smart_tiered_cache_enabled(), 'reserve' => Acquire_Cloudflare_Cache_Manager::is_cache_reserve_enabled() );
        if ( $preview['tiered'] ) { $preview['tiered_before'] = self::tiered_settings( $zone ); }
        if ( strlen( wp_json_encode( $preview ) ) > 1048576 ) { throw new RuntimeException( 'Preview exceeds the 1 MB limit; use a separately reviewed migration.' ); }
        update_option( 'acfcm_cache_preview', $preview, false );
        return $preview;
    }
    private static function save( $zone, array $journal ) {
        if ( strlen( wp_json_encode( $journal ) ) > 2097152 ) { throw new RuntimeException( 'Migration backup exceeds the 2 MB per-zone journal limit. Use a separately reviewed migration.' ); }
        if ( self::store( $zone, $journal ) !== $journal ) { throw new RuntimeException( 'Migration journal could not be saved; no further writes are safe.' ); }
    }
    private static function locked( $zone, callable $callback ) {
        $main = is_multisite() ? get_main_site_id( get_main_network_id() ) : get_current_blog_id();
        $switch = get_current_blog_id() !== $main;
        if ( $switch ) { switch_to_blog( $main ); }
        $key = 'acfcm_cache_lock_' . $zone;
        // add_option can upsert after a stale negative cache read. Never overwrite an owner.
        global $wpdb;
        $ok = 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, maybe_serialize( array( 'started' => time() ) ) ) );
        wp_cache_delete( $key, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        if ( $switch ) { restore_current_blog(); }
        if ( ! $ok ) { throw new RuntimeException( 'Migration is busy or interrupted. Confirm no worker is active before removing the main-site lock option ' . $key . '.' ); }
        try { return $callback(); }
        finally { if ( $switch ) { switch_to_blog( $main ); } delete_option( $key ); if ( $switch ) { restore_current_blog(); } }
    }
    public static function begin( $id ) {
        $p = get_option( 'acfcm_cache_preview', array() );
        if ( empty( $p['id'] ) || ! hash_equals( $p['id'], (string) $id ) || $p['created'] < time() - 900 || $p['user'] !== get_current_user_id() || $p['site'] !== get_current_blog_id() || $p['zone'] !== Acquire_Cloudflare_Cache_Manager::get_zone_id() || $p['host'] !== self::host() || $p['reserve'] !== Acquire_Cloudflare_Cache_Manager::is_cache_reserve_enabled() || $p['tiered'] !== Acquire_Cloudflare_Cache_Manager::is_smart_tiered_cache_enabled() ) { throw new RuntimeException( 'Preview expired or settings changed. Create a fresh preview.' ); }
        if ( ! Acquire_Cloudflare_Cache_Manager::is_content_auto_purge_enabled() ) { throw new RuntimeException( 'Automatic content purging must remain enabled for this policy.' ); }
        return self::locked( $p['zone'], function() use ( $p ) {
            $old = self::store( $p['zone'] );
            if ( $old && ( $old['site'] !== $p['site'] || $old['host'] !== $p['host'] ) ) { throw new RuntimeException( 'Migration ownership changed since preview.' ); }
            if ( $old && ! in_array( $old['status'], array( 'complete', 'rolled_back' ), true ) ) { throw new RuntimeException( 'An unfinished migration must be reviewed first.' ); }
            if ( self::hash( self::read( $p['zone'] ) ) !== self::hash( $p['before'] ) ) { throw new RuntimeException( 'Rules changed since preview. Create a new preview; no migration was started.' ); }
            if ( 'adopt' === $p['plan']['kind'] && $old && 'complete' === $old['status'] && $old['site'] === $p['site'] && empty( $p['combined'] ) && ! $p['tiered'] ) {
                // An idempotent revisit must not replace the original rollback point.
                ACFCM_Runtime_Guard::install( $p['host'], $p['zone'] ); return $old;
            }
            $j = $p + array( 'expected' => $p['before'], 'status' => 'running', 'cursor' => 0, 'direction' => 'apply', 'error' => '', 'guard_before' => get_option( 'acfcm_public_cache_scope', array() ) );
            // Two generations maximum; never nest journals/history.
            $j['previous'] = $old ? array( 'before' => $old['before'], 'expected' => $old['expected'], 'status' => $old['status'] ) : null;
            self::save( $p['zone'], $j );
            try { ACFCM_Runtime_Guard::install( $p['host'], $p['zone'] ); }
            catch ( RuntimeException $e ) { $j['status'] = 'paused'; $j['error'] = $e->getMessage(); self::save( $p['zone'], $j ); throw $e; }
            if ( ! $j['plan']['operations'] && ! $j['tiered'] ) { $j['status'] = 'complete'; self::save( $p['zone'], $j ); }
            return $j;
        } );
    }
    private static function rule_index( array $rules, $ref ) {
        $matches = array(); foreach ( $rules as $i => $r ) { if ( $r['ref'] === $ref ) { $matches[] = $i; } }
        if ( count( $matches ) !== 1 ) { throw new RuntimeException( 'Expected exactly one owned rule reference. Review current rules.' ); }
        return $matches[0];
    }
    private static function predicted( array $state, array $op ) {
        $p = $op['phase']; $rules = $state[$p]['rules'] ?? array();
        if ( 'DELETE' === $op['method'] ) {
            $i = self::rule_index( $rules, $op['ref'] );
            if ( isset( $op['id'] ) && $rules[$i]['id'] !== $op['id'] ) { throw new RuntimeException( 'Owned rule ID changed; migration stopped.' ); }
            array_splice( $rules, $i, 1 );
        } else {
            foreach ( $rules as $r ) { if ( $r['ref'] === $op['rule']['ref'] ) { throw new RuntimeException( 'Rule reference already exists; refusing a duplicate.' ); } }
            array_splice( $rules, isset( $op['index'] ) ? $op['index'] - 1 : count( $rules ), 0, array( $op['rule'] ) );
        }
        return $rules;
    }
    private static function applied( array $before, array $after, array $op ) {
        $p = $op['phase']; $expected = self::predicted( $before, $op ); $actual = $after[$p]['rules'] ?? array();
        if ( ! empty( $before[$p] ) && ( $before[$p]['id'] !== ( $after[$p]['id'] ?? '' ) ) ) { return false; }
        if ( 'POST' === $op['method'] ) { foreach ( $actual as &$r ) { if ( $r['ref'] === $op['rule']['ref'] ) { unset( $r['id'] ); } } unset( $r ); }
        if ( ! self::same_rules( $expected, $actual ) ) { return false; }
        $other = self::REQUEST === $p ? self::RESPONSE : self::REQUEST;
        return self::hash( array( $p => null, $other => $before[$other] ) ) === self::hash( array( $p => null, $other => $after[$other] ) );
    }
    /** One provider mutation per request, with a durable intent before dispatch. */
    public static function step( $zone ) {
        return self::locked( $zone, function() use ( $zone ) {
            $j = self::store( $zone );
            if ( ! $j || $j['site'] !== get_current_blog_id() || $j['host'] !== self::host() || $zone !== Acquire_Cloudflare_Cache_Manager::get_zone_id() ) { throw new RuntimeException( 'Migration scope no longer matches this site.' ); }
            if ( in_array( $j['status'], array( 'complete', 'rolled_back' ), true ) ) { return $j; }
            try { ACFCM_Runtime_Guard::install( $j['host'], $zone ); }
            catch ( RuntimeException $e ) { $j['status'] = 'paused'; $j['error'] = $e->getMessage(); self::save( $zone, $j ); return $j; }
            $fresh = self::read( $zone );
            if ( ! empty( $j['intent'] ) && self::applied( $j['expected'], $fresh, $j['intent'] ) ) {
                $j['expected'] = $fresh; $j['cursor']++; unset( $j['intent'] ); $j['status'] = 'running'; $j['error'] = ''; self::save( $zone, $j ); return $j;
            }
            if ( self::hash( $fresh ) !== self::hash( $j['expected'] ) ) { $j['status'] = 'conflict'; $j['error'] = 'Concurrent or unknown change. Review the saved backup and live rules; nothing will be overwritten.'; self::save( $zone, $j ); return $j; }
            $op = $j['plan']['operations'][$j['cursor']] ?? null;
            if ( ! $op ) {
                $j['status'] = 'rollback' === $j['direction'] ? 'rolled_back' : 'complete'; $j['error'] = ''; unset( $j['intent'] );
                if ( 'complete' === $j['status'] && $j['tiered'] ) {
                    try {
                        $settings = self::tiered_settings( $zone );
                        if ( ACFCM_Defense::fingerprint( $settings ) !== ACFCM_Defense::fingerprint( $j['tiered_before'] ) ) { throw new RuntimeException( 'Tiered Cache settings changed since preview; no zone-setting writes were sent.' ); }
                        foreach ( $settings as $path => $setting ) {
                            if ( 'on' === $setting['value'] ) { continue; }
                            // Saved originals remain in the backup; do not silently alter policy on failure.
                            $r = self::api( 'PATCH', $zone, $path, array( 'value' => 'on' ) );
                            $j['tiered_after'] = self::tiered_settings( $zone );
                            if ( empty( $r['success'] ) || 'on' !== $j['tiered_after'][$path]['value'] ) { throw new RuntimeException( 'Tiered Cache setting was not confirmed. Review the saved settings before retrying; no automatic fallback or rollback was sent.' ); }
                        }
                    } catch ( RuntimeException $e ) { $j['error'] = 'Cache policy completed. ' . $e->getMessage(); }
                }
                if ( 'rolled_back' === $j['status'] ) {
                    if ( $j['guard_before'] ) { update_option( 'acfcm_public_cache_scope', $j['guard_before'], false ); } else { delete_option( 'acfcm_public_cache_scope' ); }
                }
                self::save( $zone, $j ); return $j;
            }
            self::predicted( $fresh, $op ); // Validate identity before persisting intent.
            $j['intent'] = $op; $j['status'] = 'running'; $j['error'] = ''; self::save( $zone, $j );
            $p = $op['phase']; $s = $fresh[$p];
            if ( 'DELETE' === $op['method'] ) { $i = self::rule_index( $s['rules'], $op['ref'] ); $path = 'rulesets/' . rawurlencode( $s['id'] ) . '/rules/' . rawurlencode( $s['rules'][$i]['id'] ); $body = null; }
            elseif ( ! $s ) { $path = 'rulesets'; $body = array( 'kind' => 'zone', 'name' => 'Acquire public cache policy', 'phase' => $p, 'rules' => array( $op['rule'] ) ); }
            else { $path = 'rulesets/' . rawurlencode( $s['id'] ) . '/rules'; $body = $op['rule']; if ( isset( $op['index'] ) ) { $body['position'] = array( 'index' => $op['index'] ); } }
            $r = self::api( $op['method'], $zone, $path, $body );
            try { $after = self::read( $zone ); } catch ( RuntimeException $e ) { $j['status'] = 'paused'; $j['error'] = 'Readback unavailable after a possibly applied change. Resume will reconcile the saved intent; no automatic retry was sent.'; self::save( $zone, $j ); return $j; }
            if ( self::applied( $fresh, $after, $op ) ) { $j['expected'] = $after; $j['cursor']++; unset( $j['intent'] ); }
            elseif ( self::hash( $after ) !== self::hash( $fresh ) ) { $j['status'] = 'conflict'; $j['error'] = 'Unexpected readback or concurrent change. Review both phases; automatic rollback would be unsafe.'; self::save( $zone, $j ); return $j; }
            else { if ( (int) $r['code'] >= 400 && (int) $r['code'] < 500 ) { unset( $j['intent'] ); } $j['status'] = 'paused'; $j['error'] = 'Cloudflare did not apply this step (HTTP ' . (int) $r['code'] . '). Check quota, entitlement and token permissions. Migration hold remains if installed; no safety downgrade.'; }
            if ( empty( $r['success'] ) && 'running' === $j['status'] ) { $j['status'] = 'paused'; $j['error'] = 'API response was uncertain, but readback confirmed this step. Review and resume to continue.'; }
            self::save( $zone, $j ); return $j;
        } );
    }
    public static function rollback_preview( $zone ) {
        $j = self::store( $zone );
        if ( ! $j || $j['site'] !== get_current_blog_id() || $j['host'] !== self::host() || 'rollback' === $j['direction'] ) { throw new RuntimeException( 'No matching forward migration is available for rollback.' ); }
        $state = self::read( $zone );
        if ( self::hash( $state ) !== self::hash( $j['expected'] ) || ! empty( $j['intent'] ) ) { throw new RuntimeException( 'Reconcile the pending intent or concurrent changes before rollback. The saved backup will not overwrite live drift.' ); }
        if ( 'adopt' === $j['plan']['kind'] ) { throw new RuntimeException( 'Adoption made no Cloudflare changes. The already-optimized policy still needs its persistent guard; disabling it requires a separately reviewed cache policy.' ); }
        $ops = array(); $has_hold = false;
        foreach ( $state[self::REQUEST]['rules'] ?? array() as $r ) { if ( $r['ref'] === self::TEMP ) { $has_hold = true; } }
        if ( ! $has_hold ) { $ops[] = array( 'method' => 'POST', 'phase' => self::REQUEST, 'rule' => self::hold( $j['host'] ) ); }
        foreach ( array( self::REQUEST, self::RESPONSE ) as $p ) {
            $old = $j['before'][$p]['rules'] ?? array();
            $originals = array(); foreach ( $old as $r ) { $originals[$r['ref']] = $r; }
            $current_refs = array();
            foreach ( $state[$p]['rules'] ?? array() as $r ) {
                if ( self::TEMP === $r['ref'] ) { continue; }
                // A setting migration may reuse a ref with a different definition.
                if ( ! isset( $originals[$r['ref']] ) || ! self::same_rules( array( $r ), array( $originals[$r['ref']] ), false ) ) {
                    $ops[] = array( 'method' => 'DELETE', 'phase' => $p, 'ref' => $r['ref'], 'id' => $r['id'] );
                } else { $current_refs[] = $r['ref']; }
            }
            foreach ( $old as $i => $r ) { if ( ! in_array( $r['ref'], $current_refs, true ) ) { $r = self::clean( $r ); unset( $r['id'] ); $ops[] = array( 'method' => 'POST', 'phase' => $p, 'rule' => $r, 'index' => $i + 1 ); } }
            if ( count( $old ) + ( self::REQUEST === $p ? 1 : 0 ) > self::LIMIT ) { throw new RuntimeException( 'Rollback requires additional quota for the safety hold; use a separately reviewed recovery.' ); }
        }
        $ops[] = array( 'method' => 'DELETE', 'phase' => self::REQUEST, 'ref' => self::TEMP );
        $p = array( 'id' => wp_generate_uuid4(), 'created' => time(), 'user' => get_current_user_id(), 'zone' => $zone, 'expected_hash' => self::hash( $state ), 'operations' => $ops );
        update_option( 'acfcm_cache_rollback_preview', $p, false ); return $p;
    }
    public static function begin_rollback( $id ) {
        $p = get_option( 'acfcm_cache_rollback_preview', array() );
        if ( empty( $p['id'] ) || ! hash_equals( $p['id'], (string) $id ) || $p['user'] !== get_current_user_id() || $p['created'] < time() - 900 || $p['zone'] !== Acquire_Cloudflare_Cache_Manager::get_zone_id() ) { throw new RuntimeException( 'Rollback preview expired or changed.' ); }
        return self::locked( $p['zone'], function() use ( $p ) {
            $j = self::store( $p['zone'] );
            if ( $j['site'] !== get_current_blog_id() || $j['host'] !== self::host() || self::hash( self::read( $p['zone'] ) ) !== $p['expected_hash'] ) { throw new RuntimeException( 'Live rules changed; generate a fresh rollback preview.' ); }
            $j['forward_plan'] = $j['plan']; $j['plan']['operations'] = $p['operations']; $j['direction'] = 'rollback'; $j['cursor'] = 0; $j['status'] = 'running'; $j['error'] = ''; self::save( $p['zone'], $j ); return $j;
        } );
    }
}
