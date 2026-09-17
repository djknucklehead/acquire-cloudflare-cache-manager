<?php
/** Explicit defense onboarding. Existing policies are never replaced or deleted. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ACFCM_Defense {
    const CUSTOM = 'http_request_firewall_custom';
    const RATE = 'http_ratelimit';

    public static function result( $success, $message ) {
        return array( 'success' => $success, 'code' => 0, 'message' => $message, 'body' => '', 'json' => null, 'result' => null );
    }
    private static function canonical( $value ) {
        if ( ! is_array( $value ) ) { return $value; }
        if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value ); }
        return array_map( array( __CLASS__, 'canonical' ), $value );
    }
    public static function fingerprint( array $rules ) {
        foreach ( $rules as &$rule ) { unset( $rule['last_updated'], $rule['version'] ); }
        unset( $rule );
        return hash( 'sha256', json_encode( self::canonical( $rules ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
    private static function equivalent( array $live, array $wanted ) {
        unset( $live['id'], $live['ref'], $live['last_updated'], $live['version'] );
        unset( $wanted['id'], $wanted['ref'], $wanted['last_updated'], $wanted['version'] );
        return self::fingerprint( array( $live ) ) === self::fingerprint( array( $wanted ) );
    }
    private static function request( $method, $zone, $path, $payload = null ) {
        return Acquire_Cloudflare_Cache_Manager::cloudflare_request( $method, $zone, $path, $payload, 20 );
    }
    private static function read( $zone ) {
        $states = array();
        foreach ( array( self::CUSTOM, self::RATE ) as $phase ) {
            $r = self::request( 'GET', $zone, 'rulesets/phases/' . $phase . '/entrypoint' );
            if ( empty( $r['success'] ) ) {
                if ( 404 === (int) $r['code'] ) { $states[$phase] = null; continue; }
                throw new RuntimeException( 'Could not read ' . $phase . '. Check token permissions and retry the review; no further writes were attempted.' );
            }
            $s = $r['result'] ?? null;
            if ( ! is_array( $s ) || empty( $s['id'] ) || empty( $s['version'] ) || ( $s['phase'] ?? '' ) !== $phase || ( $s['kind'] ?? '' ) !== 'zone' || ! isset( $s['rules'] ) || ! is_array( $s['rules'] ) ) {
                throw new RuntimeException( 'Incomplete ruleset response. Review the zone in Cloudflare before retrying.' );
            }
            foreach ( $s['rules'] as $rule ) {
                if ( ! is_array( $rule ) || empty( $rule['id'] ) || empty( $rule['ref'] ) || empty( $rule['action'] ) || ! isset( $rule['expression'] ) ) { throw new RuntimeException( 'Incomplete rule identity. Review the zone before onboarding.' ); }
            }
            $states[$phase] = $s;
        }
        return $states;
    }
    /** Pure preflight: recognized rollout states are immutable, including legacy exceptions. */
    public static function plan( $zone, array $states, array $options ) {
        $adoptions = require __DIR__ . '/defense-adoptions.php';
        if ( isset( $adoptions['_excluded'][$zone] ) ) { throw new RuntimeException( 'This zone was excluded from the verified rollout because it was pending, paused or moved. Review its current status and explicitly approve a new adoption mapping before onboarding.' ); }
        if ( isset( $adoptions[$zone] ) ) {
            foreach ( $adoptions[$zone] as $phase => $hash ) {
                if ( ! isset( $states[$phase]['rules'] ) || self::fingerprint( $states[$phase]['rules'] ) !== $hash ) {
                    throw new RuntimeException( 'Deployed defense policy has changed. Review the current custom and rate rules against the September 17 rollout before adopting a new baseline. Nothing will be overwritten.' );
                }
            }
            return array();
        }
        // Legacy controls only recognize exact definitions. Descriptions never confer ownership.
        $legacy = Acquire_Cloudflare_Cache_Manager::recommended_hardening_waf_rules( $options );
        if ( ! empty( $options['legal_query_rate_limit'] ) ) {
            $legacy = array_merge( $legacy, Acquire_Cloudflare_Cache_Manager::recommended_hardening_rate_limit_rules( $options ) );
        }
        foreach ( $legacy as $wanted ) {
            $phase = isset( $wanted['ratelimit'] ) ? self::RATE : self::CUSTOM;
            $matches = array_filter( $states[$phase]['rules'] ?? array(), function( $r ) use ( $wanted ) { return self::equivalent( $r, $wanted ); } );
            if ( 1 !== count( $matches ) ) { throw new RuntimeException( 'Legacy recommendation is missing or modified. Legacy rules are review-only; use explicit defense baseline onboarding or review this policy in Cloudflare.' ); }
        }
        if ( empty( $options['defense_baseline'] ) ) { return array(); }
        $b = require __DIR__ . '/defense-baseline.php';
        $wanted = array( self::CUSTOM => array( $b['probe'], $b['guard'] ), self::RATE => array( $b['rate'] ) );
        $add = array();
        foreach ( $wanted as $phase => $rules ) {
            $live = $states[$phase]['rules'] ?? array();
            foreach ( $rules as $rule ) {
                $matches = array_values( array_filter( $live, function( $r ) use ( $rule ) { return ( $r['ref'] ?? '' ) === $rule['ref']; } ) );
                if ( count( $matches ) > 1 || ( $matches && ! self::equivalent( $matches[0], $rule ) ) ) { throw new RuntimeException( 'Baseline reference has drifted or is duplicated. Review it in Cloudflare; the installer will not replace it.' ); }
                foreach ( $live as $r ) {
                    if ( ( $r['ref'] ?? '' ) !== $rule['ref'] && ( ( $r['description'] ?? '' ) === $rule['description'] || self::equivalent( $r, $rule ) ) ) { throw new RuntimeException( 'Unrecognized or duplicate rule ownership. Review the existing rule ID/reference before onboarding; no duplicate will be added.' ); }
                }
                if ( ! $matches ) { $add[$phase][] = $rule; }
            }
            if ( count( $live ) + count( $add[$phase] ?? array() ) > ( self::CUSTOM === $phase ? 5 : 1 ) ) { throw new RuntimeException( 'Insufficient Free-plan rule capacity (five custom rules, one rate rule). Existing policies will not be consolidated, disabled or replaced automatically.' ); }
            foreach ( $live as $r ) {
                if ( self::RATE === $phase && ( $r['ref'] ?? '' ) !== $b['rate']['ref'] ) { throw new RuntimeException( 'An external rate policy already exists. Review it explicitly; its scope and threshold will be retained.' ); }
                if ( self::CUSTOM === $phase && in_array( $r['action'] ?? '', array( 'skip', 'execute' ), true ) && ( $r['ref'] ?? '' ) !== $b['guard']['ref'] ) { throw new RuntimeException( 'An external skip/execute policy needs review before baseline onboarding.' ); }
            }
        }
        return $add;
    }
    private static function same_state( array $a, array $b ) {
        foreach ( array( self::CUSTOM, self::RATE ) as $phase ) {
            if ( ( $a[$phase]['id'] ?? null ) !== ( $b[$phase]['id'] ?? null ) || ( $a[$phase]['version'] ?? null ) !== ( $b[$phase]['version'] ?? null ) || self::fingerprint( $a[$phase]['rules'] ?? array() ) !== self::fingerprint( $b[$phase]['rules'] ?? array() ) ) { return false; }
        }
        return true;
    }
    public static function install( $zone, array $options ) {
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $zone ) ) { return self::result( false, 'A valid Cloudflare Zone ID is required.' ); }
        // One lock per zone across this WordPress installation, including multiple networks.
        $switched = false;
        if ( is_multisite() ) {
            $main = get_main_site_id( get_main_network_id() );
            if ( get_current_blog_id() !== $main ) { switch_to_blog( $main ); $switched = true; }
        }
        $lock = 'acfcm_defense_lock_' . $zone;
        $locked = add_option( $lock, time(), '', false );
        if ( $switched ) { restore_current_blog(); }
        if ( ! $locked ) { return self::result( false, 'Another defense installation is running or was interrupted. After confirming no installer is active, an administrator can remove option ' . $lock . ' on the main site of the main network and retry.' ); }
        $writes = 0;
        try {
            $state = self::read( $zone );
            $plan = self::plan( $zone, $state, $options );
            if ( ! $plan ) { return self::result( true, 'Verified existing policies; no firewall writes. Deployed exceptions and legacy policies are retained, not replaced with new recommendations.' ); }
            // Both phases are preflighted before the first write. Guard precedes new rate rule.
            foreach ( $plan as $phase => $rules ) {
                foreach ( $rules as $rule ) {
                    $fresh = self::read( $zone );
                    if ( ! self::same_state( $state, $fresh ) ) { throw new RuntimeException( 'Concurrent ruleset change detected. Review current rules before retrying.' ); }
                    $path = empty( $state[$phase] ) ? 'rulesets' : 'rulesets/' . rawurlencode( $state[$phase]['id'] ) . '/rules';
                    $payload = empty( $state[$phase] ) ? array( 'kind' => 'zone', 'phase' => $phase, 'name' => 'AD Defense baseline', 'rules' => array( $rule ) ) : $rule;
                    // Never PUT/PATCH/DELETE an existing ruleset or rule, even during recovery.
                    $r = self::request( 'POST', $zone, $path, $payload );
                    $writes++;
                    if ( empty( $r['success'] ) ) { throw new RuntimeException( 'Cloudflare did not confirm the addition. Check the zone and token permissions; the request may have applied. No automatic retry or rollback was attempted.' ); }
                    $after = self::read( $zone );
                    $expected = $state[$phase]['rules'] ?? array();
                    $actual = $after[$phase]['rules'] ?? array();
                    $added = array_pop( $actual );
                    $other = self::CUSTOM === $phase ? self::RATE : self::CUSTOM;
                    if ( self::fingerprint( $actual ) !== self::fingerprint( $expected ) || ! is_array( $added ) || ( $added['ref'] ?? '' ) !== $rule['ref'] || ! self::equivalent( $added, $rule ) || ! self::same_state( array( $phase => null, $other => $state[$other] ), array( $phase => null, $other => $after[$other] ) ) || ( ! empty( $state[$phase] ) && $state[$phase]['id'] !== $after[$phase]['id'] ) ) {
                        throw new RuntimeException( 'Readback differs from the expected additive change. Stop and review both phases; existing rules will not be restored over concurrent changes.' );
                    }
                    $state = $after;
                }
            }
            return self::result( true, 'Defense baseline added and verified. Existing rules were preserved. No cache purge was requested.' );
        } catch ( RuntimeException $e ) {
            return self::result( false, ( $writes ? 'Partial or uncertain installation: ' : '' ) . $e->getMessage() );
        } finally {
            if ( $switched ) { switch_to_blog( $main ); }
            delete_option( $lock );
            if ( $switched ) { restore_current_blog(); }
        }
    }
}
