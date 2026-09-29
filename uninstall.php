<?php
/**
 * Removes plugin data when the plugin is deleted from WordPress.
 * The consent log is kept unless the site owner asked for a full wipe,
 * because it is the record proving consent was given.
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'aicc_settings' );
delete_option( 'aicc_cookies' );
delete_option( 'aicc_scan_state' );
delete_option( 'aicc_version' );

foreach ( [ 'aicc_scheduled_scan', 'aicc_purge_log' ] as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

if ( defined( 'AICC_DROP_CONSENT_LOG' ) && AICC_DROP_CONSENT_LOG ) {
	global $wpdb;
	$table = $wpdb->prefix . 'aicc_consents';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}
