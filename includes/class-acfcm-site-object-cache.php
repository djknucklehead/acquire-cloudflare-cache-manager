<?php
/** Targeted WordPress cache invalidation after a current-site database search/replace. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ACFCM_Site_Object_Cache {
    /** No backend/group flush: WPE's generation is shared by the whole customer. */
    public static function clear() {
        global $wpdb, $wp_object_cache;
        $groups = array( 'options', 'posts', 'post_meta', 'terms', 'term_meta', 'comments', 'comment_meta', 'comment' );
        $relationships = array();
        foreach ( get_taxonomies() as $taxonomy ) {
            $relationships[] = $taxonomy . '_relationships';
        }
        // Refuse a drop-in that explicitly makes any target group network-global.
        $global_groups = isset( $wp_object_cache->global_groups ) ? (array) $wp_object_cache->global_groups : array();
        foreach ( array_merge( $groups, $relationships ) as $group ) {
            if ( in_array( $group, $global_groups, true ) || isset( $global_groups[ $group ] ) ) {
                return false;
            }
        }
        // The installed WPE Memcached drop-in exposes its actual blog prefix. Verify
        // switch_to_blog has selected this blog before touching any persistent key.
        if ( is_multisite() && isset( $wp_object_cache->customer, $wp_object_cache->blog_prefix, $wp_object_cache->generation )
            && 0 !== strpos( (string) $wp_object_cache->blog_prefix, get_current_blog_id() . ':' ) ) {
            return false;
        }
        try {
            // Include formerly autoloaded options that may no longer exist in SQL.
            $autoload = wp_cache_get( 'alloptions', 'options' );
            foreach ( is_array( $autoload ) ? array_keys( $autoload ) : array() as $name ) {
                if ( ! self::remove( $name, 'options' ) ) { return false; }
            }
            $tables = array(
                array( $wpdb->options, 'option_id', 'option_name', array( 'options' ) ),
                array( $wpdb->posts, 'ID', 'ID', array_merge( array( 'posts', 'post_meta' ), $relationships ) ),
                array( $wpdb->terms, 'term_id', 'term_id', array( 'terms', 'term_meta' ) ),
                array( $wpdb->comments, 'comment_ID', 'comment_ID', array( 'comments', 'comment_meta' ) ),
            );
            foreach ( $tables as list( $table, $id, $key, $target_groups ) ) {
                $cursor = 0;
                do {
                    // Identifiers come only from the current blog's wpdb tables and
                    // the fixed column list above. Never query network/user tables.
                    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT `$id` AS cursor_id, `$key` AS cache_key FROM `$table` WHERE `$id` > %d ORDER BY `$id` ASC LIMIT 500", $cursor ) );
                    if ( ! is_array( $rows ) || $wpdb->last_error ) { return false; }
                    foreach ( $rows as $row ) {
                        foreach ( $target_groups as $group ) {
                            if ( ! self::remove( $row->cache_key, $group ) ) { return false; }
                        }
                        $cursor = (int) $row->cursor_id;
                    }
                } while ( count( $rows ) === 500 );
            }
            foreach ( array( 'alloptions', 'notoptions' ) as $key ) {
                if ( ! self::remove( $key, 'options' ) ) { return false; }
            }
            // Core query caches include these site-scoped versions in their keys.
            foreach ( array( 'posts', 'terms', 'comment' ) as $group ) {
                if ( in_array( $group, $global_groups, true ) || isset( $global_groups[ $group ] ) ) { return false; }
                if ( ! wp_cache_set( 'last_changed', microtime(), $group ) ) { return false; }
            }
            return true;
        } catch ( Throwable $error ) {
            return false; // Never expose backend connection details.
        }
    }

    private static function remove( $key, $group ) {
        // A missing key normally returns false from delete, so verify absence.
        wp_cache_delete( $key, $group );
        $found = false;
        wp_cache_get( $key, $group, true, $found );
        return ! $found;
    }
}
