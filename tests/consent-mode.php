<?php
/**
 * The Consent Mode default in <head> must run before banner.js releases GA4.
 * Up to 1.0.18 it carried no opt-out attributes, so Cloudflare Rocket Loader
 * (on sveikas.info, tavoseimai.lt, dievozodis.lt) delayed it past banner.js:
 * a returning visitor who had allowed analytics got the GA4 config first and
 * the "denied" default after it, and no update ever followed.
 * Part 1 checks the PHP output; part 2 runs banner.js in Node (tests/consent-order.js)
 * with the default delayed, as Rocket Loader leaves it.
 * WordPress is stubbed; options live in memory.
 * Run: php tests/consent-mode.php
 */

declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['options'] = [];

	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }

	foreach ( [ 'Settings', 'Blocker' ] as $class ) {
		require dirname( __DIR__ ) . "/includes/$class.php";
	}

	use Adventistai\CookieConsent\Blocker;

	$failures = 0;
	function check( string $label, bool $condition ): void {
		global $failures;
		echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
		$failures += $condition ? 0 : 1;
	}

	function defaults_output(): string {
		ob_start();
		( new Blocker() )->consent_mode_defaults();
		return (string) ob_get_clean();
	}

	$html = defaults_output();
	check( 'consent default is printed', str_contains( $html, "gtag('consent','default'" ) );

	preg_match( '#<script\b([^>]*)>#', $html, $tag );
	$attrs = $tag[1] ?? '';
	check( 'blocker leaves it alone (data-aicc-ignore)', str_contains( $attrs, 'data-aicc-ignore' ) );
	foreach ( [ 'data-cfasync="false"', 'data-no-optimize="1"', 'data-no-defer="1"', 'data-nowprocket' ] as $opt_out ) {
		check( "opted out of script delay: $opt_out", str_contains( $attrs, $opt_out ) );
	}
	check( 'no type, async or defer that would delay it', preg_match( '#(?:^|\s)(?:type|async|defer)(?:\s|=|$)#', $attrs ) !== 1 );

	$GLOBALS['options']['aicc_settings'] = [ 'consent_mode' => 0 ];
	check( 'nothing printed with Consent Mode off', defaults_output() === '' );

	// Part 2: banner.js with the default delayed.
	$node = trim( (string) shell_exec( PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null' ) );
	if ( $node === '' ) {
		echo "FAIL node not found; part 2 needs Node.js\n";
		$failures++;
	} else {
		$output = [];
		exec( 'node ' . escapeshellarg( __DIR__ . '/consent-order.js' ) . ' 2>&1', $output, $code );
		echo implode( "\n", $output ) . "\n";
		check( 'banner.js consent order (node)', $code === 0 );
	}

	echo $failures === 0 ? "All passed\n" : "$failures failed\n";
	exit( $failures === 0 ? 0 : 1 );
}
