<?php
// Called only by the disposable test provisioner.
define( 'WP_INSTALLING', true );
require $argv[1] . '/wp-load.php';
require ABSPATH . 'wp-admin/includes/upgrade.php';
wp_install( 'Disposable integration', 'localadmin', 'nobody@example.invalid', true, '', 'fake-local-password' );
if ( $argv[2] === 'multi' ) {
    require_once ABSPATH . 'wp-admin/includes/network.php';
    foreach ( $wpdb->tables( 'ms_global' ) as $table => $name ) { $wpdb->$table = $name; }
    install_network();
    $result = populate_network( 1, '127.0.0.1:18892', 'nobody@example.invalid', 'Disposable network', '/', false );
    if ( is_wp_error( $result ) ) { fwrite( STDERR, $result->get_error_message() ); exit( 1 ); }
}
