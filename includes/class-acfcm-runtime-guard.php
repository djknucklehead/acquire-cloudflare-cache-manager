<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class ACFCM_Runtime_Guard {
    public static function path() { return WPMU_PLUGIN_DIR . '/acfcm-public-cache-guard.php'; }
    public static function inspect() {
        $source = __DIR__ . '/public-cache-guard.php';
        if ( file_exists( self::path() ) && hash_file( 'sha256', self::path() ) !== hash_file( 'sha256', $source ) ) {
            throw new RuntimeException( 'The persistent cache guard differs from the bundled reviewed version. Review the MU-plugin before migration; it will not be overwritten.' );
        }
        if ( class_exists( 'Acquire_Public_Cache_Guard', false ) ) {
            $reflection = new ReflectionClass( 'Acquire_Public_Cache_Guard' );
            $hash = hash_file( 'sha256', $reflection->getFileName() );
            $approved = require __DIR__ . '/cache-guard-hashes.php';
            if ( ! in_array( $hash, $approved, true ) ) { throw new RuntimeException( 'An unrecognized Acquire runtime helper is active. Review its source before onboarding; duplicate hooks will not be installed.' ); }
        }
        if ( ! file_exists( self::path() ) && ! is_writable( is_dir( WPMU_PLUGIN_DIR ) ? WPMU_PLUGIN_DIR : dirname( WPMU_PLUGIN_DIR ) ) ) {
            throw new RuntimeException( 'The MU-plugin directory is not writable. Install the reviewed persistent guard before applying Cloudflare cache rules.' );
        }
        return file_exists( self::path() ) ? 'Reviewed persistent guard present' : 'Persistent MU guard will be installed on Apply';
    }
    public static function install( $host, $zone ) {
        self::inspect();
        $scope = get_option( 'acfcm_public_cache_scope', array() );
        if ( $scope && ( $scope['host'] !== $host || $scope['zone'] !== $zone ) ) { throw new RuntimeException( 'An existing runtime scope targets another host or zone. Review the prior policy before changing its guard.' ); }
        if ( ! file_exists( self::path() ) ) {
            if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) { throw new RuntimeException( 'Could not create MU-plugin directory.' ); }
            // Some managed filesystems reject hard links. Publish a complete file
            // with same-directory rename, serialized across our installers.
            $lock = @fopen( WPMU_PLUGIN_DIR . '/.acfcm-guard-install.lock', 'c' );
            if ( ! $lock ) { throw new RuntimeException( 'Could not open the cache guard installation lock. Check MU-plugin directory permissions.' ); }
            $temp = false;
            try {
                if ( ! flock( $lock, LOCK_EX ) ) { throw new RuntimeException( 'Could not lock cache guard installation.' ); }
                self::inspect();
                if ( ! file_exists( self::path() ) ) {
                    $temp = tempnam( WPMU_PLUGIN_DIR, '.acfcm-' );
                    if ( ! $temp || realpath( dirname( $temp ) ) !== realpath( WPMU_PLUGIN_DIR ) ) { throw new RuntimeException( 'Could not prepare cache guard in the MU-plugin directory.' ); }
                    $bytes = file_get_contents( __DIR__ . '/public-cache-guard.php' );
                    if ( false === $bytes || file_put_contents( $temp, $bytes, LOCK_EX ) !== strlen( $bytes ) ) { throw new RuntimeException( 'Could not write the complete cache guard.' ); }
                    @chmod( $temp, 0644 );
                    if ( ! is_readable( $temp ) || hash_file( 'sha256', $temp ) !== hash( 'sha256', $bytes ) ) { throw new RuntimeException( 'Cache guard verification failed before installation.' ); }
                    self::inspect();
                    if ( ! file_exists( self::path() ) && ! @rename( $temp, self::path() ) ) { throw new RuntimeException( 'Could not publish the cache guard. Check MU-plugin directory permissions; no Cloudflare migration was started.' ); }
                    clearstatcache( true, self::path() );
                    if ( ! is_readable( self::path() ) || hash_file( 'sha256', self::path() ) !== hash( 'sha256', $bytes ) ) { throw new RuntimeException( 'Installed cache guard verification failed; no Cloudflare migration was started.' ); }
                }
            } finally {
                if ( $temp && file_exists( $temp ) ) { unlink( $temp ); }
                flock( $lock, LOCK_UN ); fclose( $lock );
            }
        }
        update_option( 'acfcm_public_cache_scope', array( 'host' => $host, 'zone' => $zone, 'version' => 1 ), false );
        if ( ( get_option( 'acfcm_public_cache_scope', array() )['host'] ?? '' ) !== $host ) { throw new RuntimeException( 'Could not persist runtime scope; migration stopped.' ); }
    }
}
