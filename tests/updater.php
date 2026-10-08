<?php
/**
 * Updater::force_check(): "Check again" on Dashboard → Updates skips the
 * release cache. WordPress is stubbed. Run: php tests/updater.php
 */

declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['can']     = true;
	$GLOBALS['deleted'] = [];
	$GLOBALS['actions'] = [];

	function add_filter() {}
	function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['actions'][ $hook ] = $priority; }
	function current_user_can( $cap ) { return 'update_plugins' === $cap && $GLOBALS['can']; }
	function delete_transient( $key ) { $GLOBALS['deleted'][] = 'transient:' . $key; }
	function delete_site_transient( $key ) { $GLOBALS['deleted'][] = 'site:' . $key; }

	require dirname( __DIR__ ) . '/includes/Updater.php';

	$failures = 0;
	function check( string $label, bool $condition ): void {
		global $failures;
		echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
		$failures += $condition ? 0 : 1;
	}

	$updater = new Adventistai\CookieConsent\Updater();
	$updater->hooks();
	check( 'runs before wp_update_plugins', 9 === ( $GLOBALS['actions']['load-update-core.php'] ?? null ) );

	$updater->force_check();
	check( 'plain Updates screen keeps the cache', [] === $GLOBALS['deleted'] );

	$_GET['force-check'] = '1';
	$GLOBALS['can']      = false;
	$updater->force_check();
	check( 'Check again needs update_plugins', [] === $GLOBALS['deleted'] );

	$GLOBALS['can'] = true;
	$updater->force_check();
	check( 'Check again drops the release cache and plugin update data', [ 'transient:aicc_github_latest_release', 'site:update_plugins' ] === $GLOBALS['deleted'] );

	echo $failures ? "$failures check(s) failed\n" : "All checks passed\n";
	exit( $failures ? 1 : 0 );
}
