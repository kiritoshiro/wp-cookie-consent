<?php
/**
 * Updater::force_check(): "Check again" on Dashboard → Updates skips the
 * release cache. Updater::download_private_package(): a release package is
 * installed only when its SHA-256 matches the digest GitHub's release API
 * reports for the asset. WordPress and GitHub are stubbed.
 * Run: php tests/updater.php
 */

declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'AICC_FILE', '/plugins/adventistai-cookie-consent/adventistai-cookie-consent.php' );
	define( 'VERSION', '1.0.17' );
	define( 'MINUTE_IN_SECONDS', 60 );

	$GLOBALS['can']        = true;
	$GLOBALS['deleted']    = [];
	$GLOBALS['actions']    = [];
	$GLOBALS['transients'] = [];
	$GLOBALS['requests']   = [];

	class WP_Error {
		public string $code;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = (string) $code; }
	}

	function add_filter() {}
	function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['actions'][ $hook ] = $priority; }
	function current_user_can( $cap ) { return 'update_plugins' === $cap && $GLOBALS['can']; }
	function delete_transient( $key ) { $GLOBALS['deleted'][] = 'transient:' . $key; }
	function delete_site_transient( $key ) { $GLOBALS['deleted'][] = 'site:' . $key; }
	function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; }
	function apply_filters( $hook, $value ) { return $value; }
	function plugin_basename( $file ) { return 'adventistai-cookie-consent/adventistai-cookie-consent.php'; }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_parse_url( $url ) { return parse_url( $url ); }
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
	function __( $text, $domain = '' ) { return $text; }
	function wp_tempnam( $name ) { return $GLOBALS['temp'] = tempnam( sys_get_temp_dir(), 'aicc' ); }
	function wp_delete_file( $file ) { @unlink( $file ); }
	function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
	function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; }
	function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }

	// GitHub API: the release metadata, then a 302 from the asset endpoint to the CDN.
	function wp_remote_get( $url, $args ) {
		$GLOBALS['requests'][] = $url;
		if ( 'https://api.github.com/repos/kiritoshiro/wp-cookie-consent/releases/latest' === $url ) {
			return [ 'code' => 200, 'body' => json_encode( $GLOBALS['release'] ) ];
		}
		return [ 'code' => 302, 'headers' => [ 'location' => $GLOBALS['cdn'] ] ];
	}
	// Release CDN: streams the package into the temporary file.
	function wp_safe_remote_get( $url, $args ) {
		$GLOBALS['requests'][] = $url;
		file_put_contents( $args['filename'], $GLOBALS['zip'] );
		return [ 'code' => 200 ];
	}

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

	/* ---- package digest */
	$package = 'https://api.github.com/repos/kiritoshiro/wp-cookie-consent/releases/assets/123';
	$extra   = [ 'plugin' => 'adventistai-cookie-consent/adventistai-cookie-consent.php' ];
	$zip     = "PK\x03\x04 release package";

	// A fresh updater and empty cache per scenario; returns [ update, download ].
	function scenario( ?string $digest, string $zip, string $cdn = 'https://release-assets.githubusercontent.com/github-production-release-asset/1/x' ): array {
		$asset = [ 'id' => 123, 'name' => 'wp-cookie-consent.zip', 'state' => 'uploaded' ];
		if ( null !== $digest ) {
			$asset['digest'] = $digest;
		}
		$GLOBALS['release']    = [ 'tag_name' => 'v1.0.18', 'assets' => [ $asset ] ];
		$GLOBALS['transients'] = [];
		$GLOBALS['requests']   = [];
		$GLOBALS['zip']        = $zip;
		$GLOBALS['cdn']        = $cdn;
		$updater = new Adventistai\CookieConsent\Updater();
		$update  = $updater->provide_update( false, [ 'Version' => '1.0.17' ], 'adventistai-cookie-consent/adventistai-cookie-consent.php', [] );
		$file    = $updater->download_private_package( false, $GLOBALS['package'], null, $GLOBALS['extra'] );
		return [ $update, $file ];
	}

	[ $update, $file ] = scenario( 'sha256:' . hash( 'sha256', $zip ), $zip );
	check( 'release with a digest is offered', is_array( $update ) && $package === $update['package'] );
	check( 'matching digest installs the package', is_string( $file ) && $zip === file_get_contents( $file ) );
	if ( is_string( $file ) ) {
		unlink( $file );
	}

	[ $update, $file ] = scenario( null, $zip );
	check( 'release without a digest is not offered', false === $update );
	check( 'missing digest is refused before downloading', $file instanceof WP_Error && 'aicc_github_release_unverified' === $file->code && ! in_array( $package, $GLOBALS['requests'], true ) );

	[ , $file ] = scenario( 'sha1:' . sha1( $zip ), $zip );
	check( 'malformed digest is refused', $file instanceof WP_Error && 'aicc_github_release_unverified' === $file->code );

	[ , $file ] = scenario( 'sha256:' . hash( 'sha256', 'other package' ), $zip );
	check( 'mismatched digest is refused', $file instanceof WP_Error && 'aicc_github_digest' === $file->code );
	check( 'mismatched package is deleted', ! file_exists( $GLOBALS['temp'] ) );

	[ , $file ] = scenario( 'sha256:' . hash( 'sha256', $zip ), $zip, 'https://evil.s3.amazonaws.com/wp-cookie-consent.zip' );
	check( 'download redirect outside the GitHub release CDN is refused', $file instanceof WP_Error && 'aicc_github_redirect' === $file->code );

	$GLOBALS['package'] = 'https://api.github.com/repos/kiritoshiro/wp-cookie-consent/releases/assets/999';
	[ , $file ] = scenario( 'sha256:' . hash( 'sha256', $zip ), $zip );
	check( 'asset not in the verified release is refused', $file instanceof WP_Error && 'aicc_github_release_unverified' === $file->code );

	echo $failures ? "$failures check(s) failed\n" : "All checks passed\n";
	exit( $failures ? 1 : 0 );
}
