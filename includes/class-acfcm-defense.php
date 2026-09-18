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
    public static function read( $zone ) {
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
    public static function excluded_identity( $zone ) {
        $names = array( '6b1cb01e4d40da8b389fbff05002b822' => '59pac.com', '899904e844a1246a10e039c97925c370' => '75pac.com', '4207d744a1ac97c6166ba56532681b90' => 'morriseyemail.com' );
        return isset( $names[$zone] ) ? array( 'id' => $zone, 'name' => $names[$zone], 'account' => '63ef3a537b1cffce309b6a7645491560' ) : null;
    }
    private static function verify_excluded_zone( $zone ) {
        $expected = self::excluded_identity( $zone );
        if ( ! $expected ) { throw new RuntimeException( 'No reviewed zone identity is available.' ); }
        $r = self::request( 'GET', $zone, '' );
        $z = $r['result'] ?? null;
        if ( empty( $r['success'] ) || ! is_array( $z ) ) { throw new RuntimeException( 'Could not verify current zone status. Check Zone Read permission and retry security review. Cache rules are unchanged.' ); }
        $host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( ( $z['id'] ?? '' ) !== $expected['id'] || ( $z['name'] ?? '' ) !== $expected['name'] || ( $z['account']['id'] ?? '' ) !== $expected['account'] || ! in_array( $host, array( $expected['name'], 'www.' . $expected['name'] ), true ) || Acquire_Cloudflare_Cache_Manager::get_zone_id() !== $zone ) {
            throw new RuntimeException( 'Zone, account or site hostname does not match the reviewed security scope. Cache rules are unchanged.' );
        }
        if ( ( $z['status'] ?? '' ) !== 'active' || ! array_key_exists( 'paused', $z ) || false !== $z['paused'] ) { throw new RuntimeException( 'Security onboarding requires a currently active, unpaused zone. Cache rules are unchanged.' ); }
        return $expected;
    }
    public static function review_excluded( $zone ) {
        delete_option( 'acfcm_security_review' );
        $identity = self::verify_excluded_zone( $zone );
        $state = self::read( $zone );
        $plan = self::plan( $zone, $state, array( 'defense_baseline' => true ), true );
        $review = array( 'id' => wp_generate_uuid4(), 'zone' => $zone, 'site' => get_current_blog_id(), 'user' => get_current_user_id(), 'created' => time(), 'identity' => $identity, 'before' => $state, 'state' => $state, 'plan' => $plan );
        if ( strlen( wp_json_encode( $review ) ) > 1048576 ) { throw new RuntimeException( 'Security review exceeds the backup limit.' ); }
        update_option( 'acfcm_security_review', $review, false );
        if ( get_option( 'acfcm_security_review' ) !== $review ) { throw new RuntimeException( 'Could not save security review.' ); }
        return $review;
    }
    private static function approved_review( $zone, $id ) {
        $r = get_option( 'acfcm_security_review', array() );
        if ( ! $id || empty( $r['id'] ) || ! hash_equals( $r['id'], (string) $id ) || $r['zone'] !== $zone || $r['site'] !== get_current_blog_id() || $r['user'] !== get_current_user_id() || $r['created'] < time() - 900 ) { throw new RuntimeException( 'Security approval expired or scope changed. Click Install security rules to review again.' ); }
        self::verify_excluded_zone( $zone );
        return $r;
    }
    /** Pure preflight: recognized rollout states are immutable, including legacy exceptions. */
    public static function plan( $zone, array $states, array $options, $reviewed_exclusion = false ) {
        $adoptions = require __DIR__ . '/defense-adoptions.php';
        if ( isset( $adoptions['_excluded'][$zone] ) && ! $reviewed_exclusion ) { throw new RuntimeException( 'This zone was excluded from the verified rollout because it was pending, paused or moved. Review its current status and explicitly approve a new adoption mapping before onboarding.' ); }
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
    /** One-click full baseline; exact existing rules are retained with their IDs. */
    private static function complete_plan( array $states ) {
        $b = require __DIR__ . '/defense-baseline.php';
        $legacy = array(
            'acfcm_wordpress_probes_v1' => Acquire_Cloudflare_Cache_Manager::recommended_wordpress_probe_rule(),
            'acfcm_xmlrpc_v1' => Acquire_Cloudflare_Cache_Manager::recommended_xmlrpc_block_rule(),
            'acfcm_legal_query_v1' => Acquire_Cloudflare_Cache_Manager::recommended_legal_query_challenge_rule(),
        );
        $custom = array();
        foreach ( $legacy as $ref => $rule ) { $rule['ref'] = $ref; $custom[] = $rule; }
        $custom[] = $b['probe']; $custom[] = $b['guard'];
        $add = array();
        foreach ( array( self::CUSTOM => $custom, self::RATE => array( $b['rate'] ) ) as $phase => $wanted ) {
            $live = $states[$phase]['rules'] ?? array();
            foreach ( $wanted as $rule ) {
                $matches = array();
                foreach ( $live as $existing ) {
                    if ( self::equivalent( $existing, $rule ) ) { $matches[] = $existing; }
                    elseif ( ( $existing['ref'] ?? '' ) === $rule['ref'] || ( $existing['description'] ?? '' ) === $rule['description'] ) {
                        throw new RuntimeException( 'An existing recommended security rule has custom changes. It was preserved; resolve the conflict in Cloudflare before retrying.' );
                    }
                }
                if ( count( $matches ) > 1 ) { throw new RuntimeException( 'Duplicate security rules found. Remove the duplicate in Cloudflare before retrying.' ); }
                if ( ! $matches ) { $add[$phase][] = $rule; }
            }
            if ( count( $live ) + count( $add[$phase] ?? array() ) > ( self::CUSTOM === $phase ? 5 : 1 ) ) {
                throw new RuntimeException( 'Not enough rule slots for the complete security set. Existing custom rules were preserved; free capacity or consolidate them in Cloudflare before retrying.' );
            }
            foreach ( $live as $existing ) {
                if ( self::CUSTOM === $phase && in_array( $existing['action'], array( 'skip', 'execute' ), true ) && ! self::equivalent( $existing, $b['guard'] ) ) {
                    throw new RuntimeException( 'An existing skip/execute rule could bypass the recommended protections. Resolve that conflict in Cloudflare before retrying.' );
                }
            }
        }
        return $add;
    }
    private static function verify_install_zone( $zone ) {
        if ( self::excluded_identity( $zone ) ) { self::verify_excluded_zone( $zone ); return; }
        $r = self::request( 'GET', $zone, '' ); $z = $r['result'] ?? array();
        $name = strtolower( (string) ( $z['name'] ?? '' ) );
        $host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( empty( $r['success'] ) || ! $name || ( $z['id'] ?? '' ) !== $zone || Acquire_Cloudflare_Cache_Manager::get_zone_id() !== $zone
            || ( $host !== $name && substr( $host, -strlen( '.' . $name ) ) !== '.' . $name )
            || ( $z['status'] ?? '' ) !== 'active' || ! isset( $z['paused'] ) || false !== $z['paused'] ) {
            throw new RuntimeException( 'Could not verify an active, unpaused Cloudflare zone for this site. Check the zone mapping and Zone Read permission.' );
        }
    }
    private static function same_state( array $a, array $b ) {
        foreach ( array( self::CUSTOM, self::RATE ) as $phase ) {
            if ( ( $a[$phase]['id'] ?? null ) !== ( $b[$phase]['id'] ?? null ) || ( $a[$phase]['version'] ?? null ) !== ( $b[$phase]['version'] ?? null ) || self::fingerprint( $a[$phase]['rules'] ?? array() ) !== self::fingerprint( $b[$phase]['rules'] ?? array() ) ) { return false; }
        }
        return true;
    }
    public static function install( $zone, array $options, $reviewed_state = null, $approval_id = null ) {
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
            $complete = ! empty( $options['complete_security'] );
            if ( $complete ) { self::verify_install_zone( $zone ); }
            $review = ! $complete && self::excluded_identity( $zone ) ? self::approved_review( $zone, $approval_id ) : null;
            $state = self::read( $zone );
            if ( $review && ! self::same_state( $review['state'], $state ) ) { throw new RuntimeException( 'Security rules changed after review. Click Install security rules to review again; no changes were sent.' ); }
            if ( null !== $reviewed_state && ! self::same_state( $reviewed_state, $state ) ) { throw new RuntimeException( 'Security rules changed since the combined preview. Review a fresh security setup; no firewall changes were sent.' ); }
            $plan = $complete ? self::complete_plan( $state ) : self::plan( $zone, $state, $options, null !== $review );
            if ( $complete && $plan ) {
                $backup = array( 'zone' => $zone, 'site' => get_current_blog_id(), 'created' => time(), 'state' => $state );
                if ( strlen( wp_json_encode( $backup ) ) > 1048576 ) { throw new RuntimeException( 'Security backup exceeds the storage limit.' ); }
                update_option( 'acfcm_security_backup', $backup, false );
                if ( get_option( 'acfcm_security_backup' ) !== $backup ) { throw new RuntimeException( 'Could not save the security backup; no changes were sent.' ); }
            }
            if ( ! $plan ) { return self::result( true, $complete ? 'All recommended security rules are already installed and verified.' : 'Verified existing policies; no firewall writes. Deployed exceptions and legacy policies are retained, not replaced with new recommendations.' ); }
            // Both phases are preflighted before the first write. Guard precedes new rate rule.
            foreach ( $plan as $phase => $rules ) {
                foreach ( $rules as $rule ) {
                    if ( $complete ) { self::verify_install_zone( $zone ); }
                    if ( $review ) { self::approved_review( $zone, $approval_id ); }
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
                    if ( $review ) {
                        $review['state'] = $after;
                        update_option( 'acfcm_security_review', $review, false );
                        if ( get_option( 'acfcm_security_review' ) !== $review ) { throw new RuntimeException( 'Could not save security progress. Review again before retrying.' ); }
                    }
                }
            }
            return self::result( true, $complete ? 'All recommended security rules installed and verified. Existing rules preserved; backup saved.' : 'Defense baseline added and verified. Existing rules were preserved. No cache purge was requested.' );
        } catch ( RuntimeException $e ) {
            return self::result( false, ( $writes ? 'Partial or uncertain installation: ' : '' ) . $e->getMessage() );
        } finally {
            if ( $switched ) { switch_to_blog( $main ); }
            delete_option( $lock );
            if ( $switched ) { restore_current_blog(); }
        }
    }
}
