<?php
/**
 * A script the blocker holds back must have its category in the banner, or it
 * can never be allowed. Before 1.0.20 a category was offered only when the
 * cookie list already held one of its cookies, and the cookies of a blocked
 * script are never set, so GA4 on tavoseimai.lt never ran.
 * Also: in wp-admin the registered settings sanitizer used to drop a
 * revision bump, so visitors were never asked again.
 * WordPress is stubbed; options live in memory.
 * Run: php tests/categories.php
 */

declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'AICC_URL', 'https://example.test/wp-content/plugins/adventistai-cookie-consent/' );

	$GLOBALS['options'] = [];
	$GLOBALS['writes']  = [];
	$GLOBALS['hooks']   = [];
	$GLOBALS['admin']   = false; // wp-admin: register_setting() sanitizes aicc_settings on save

	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( $key, $value, $autoload = null ) {
		if ( $GLOBALS['admin'] && 'aicc_settings' === $key ) {
			$value = Adventistai\CookieConsent\Settings::sanitize( $value );
		}
		$GLOBALS['options'][ $key ] = $value;
		$GLOBALS['writes'][]        = $key;
		return true;
	}
	function apply_filters( $hook, $value ) { return $value; }
	function do_action( $hook, ...$args ) { $GLOBALS['hooks'][] = $hook; }
	function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
	function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
	function sanitize_textarea_field( $text ) { return trim( strip_tags( (string) $text ) ); }
	function absint( $value ) { return abs( (int) $value ); }
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_url_raw( $url ) { return (string) $url; }
	function wp_json_encode( $data ) { return json_encode( $data ); }
	function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . $path; }
	function home_url( $path = '' ) { return 'https://example.test' . $path; }
	function get_permalink( $id ) { return ''; }
	function is_multisite() { return false; }
	function get_locale() { return 'lt_LT'; }
	function determine_locale() { return 'lt_LT'; }
	function wp_unslash( $value ) { return $value; }
	function sanitize_hex_color( $color ) { return preg_match( '/^#[0-9a-f]{6}$/i', (string) $color ) ? $color : ''; }
	function wp_clear_scheduled_hook( $hook ) {}
	function wp_schedule_event( $time, $recurrence, $hook ) {}
	define( 'MINUTE_IN_SECONDS', 60 );

	foreach ( [ 'Category', 'I18n', 'Settings', 'Registry', 'Scanner', 'Blocker', 'Frontend' ] as $class ) {
		require dirname( __DIR__ ) . "/includes/$class.php";
	}

	use Adventistai\CookieConsent\Blocker;
	use Adventistai\CookieConsent\Category;
	use Adventistai\CookieConsent\Registry;
	use Adventistai\CookieConsent\Settings;

	$failures = 0;
	function check( string $label, bool $condition ): void {
		global $failures;
		echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
		$failures += $condition ? 0 : 1;
	}

	/** Fresh site: only WordPress' own cookies recorded, revision 1. Registry caches reset. */
	function fresh_site(): void {
		$GLOBALS['options'] = [];
		$GLOBALS['writes']  = [];
		$GLOBALS['hooks']   = [];
		foreach ( [ 'stored_cache', 'seen_cache' ] as $prop ) {
			( new ReflectionProperty( Registry::class, $prop ) )->setValue( null, null );
		}
		Registry::record_service( 'wordpress' );
		$GLOBALS['writes'] = [];
	}

	function categories(): array {
		return array_map( static fn( Category $c ): string => $c->value, Registry::active_categories() );
	}

	function page( string $head ): string {
		$config = esc_attr( (string) wp_json_encode( [ 'categories' => [ [ 'slug' => 'necessary' ] ], 'revision' => 1 ] ) );
		return "<!doctype html><html><head>$head</head><body><div id=\"aicc-root\" data-aicc-config=\"$config\" hidden></div></body></html>";
	}

	/** The banner configuration as printed in a filtered page. */
	function printed_config( string $html ): array {
		preg_match( '#data-aicc-config="([^"]*)"#', $html, $m );
		return (array) json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true );
	}

	$ga4 = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-X291L8MRDH"></script>'
		. "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-X291L8MRDH');</script>";

	/* ---- GA4 blocked, no _ga recorded (tavoseimai.lt) */
	fresh_site();
	check( 'before: no analytics category on a site without GA cookies', [ 'necessary' ] === categories() );

	$html = ( new Blocker() )->filter( page( $ga4 ) );
	check( 'GA4 script is blocked as analytics', 2 === substr_count( $html, 'type="text/plain" data-aicc-cat="analytics" data-aicc-service="ga4"' ) );
	check( 'blocked GA4 makes analytics a banner category', in_array( 'analytics', categories(), true ) );
	$names = array_column( Registry::stored(), 'category', 'name' );
	check( 'GA4 cookies are recorded as analytics', 'analytics' === ( $names['_ga'] ?? null ) && 'analytics' === ( $names['_ga_*'] ?? null ) );
	check( 'new category asks visitors again (revision bumped)', 2 === (int) Settings::get( 'revision' ) );
	check( 'new-cookies hook fires', in_array( 'aicc_new_cookies_found', $GLOBALS['hooks'], true ) );
	$config = printed_config( $html );
	check( 'this page prints the new configuration', in_array( 'analytics', array_column( (array) ( $config['categories'] ?? [] ), 'slug' ), true ) && 2 === ( $config['revision'] ?? null ) );

	$GLOBALS['writes'] = [];
	$again             = ( new Blocker() )->filter( page( $ga4 ) );
	check( 'later page views write nothing', [] === $GLOBALS['writes'] );
	check( 'later page views keep the revision', 2 === (int) Settings::get( 'revision' ) );
	check( 'later page views leave the printed configuration alone', printed_config( $again ) === printed_config( page( '' ) ) );

	/* ---- Site Kit is not active: a scan must not prune GA4 found in the pages */
	Registry::prune_inactive();
	check( 'GA4 cookies survive pruning without Site Kit', in_array( '_ga', array_column( Registry::stored(), 'name' ), true ) );

	/* ---- Tag Manager defines no cookies, its category must still appear */
	fresh_site();
	( new Blocker() )->filter( page( '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-ABC123"></script>' ) );
	check( 'blocked GTM (no known cookies) makes analytics a banner category', in_array( 'analytics', categories(), true ) );
	check( 'GTM adds a category, so visitors are asked again', 2 === (int) Settings::get( 'revision' ) );

	/* ---- A service gone from the site stops keeping its category */
	( new ReflectionProperty( Registry::class, 'seen_cache' ) )->setValue( null, [ 'gtm' => time() - Registry::SEEN_TTL - 1 ] );
	check( 'a service unseen for 90 days no longer adds its category', [ 'necessary' ] === categories() );

	/* ---- Cookies already recorded: nothing changes for adventistai/dievozodis/sveikas */
	fresh_site();
	Registry::record_service( 'ga4' );
	Settings::set( 'revision', 7 );
	( new Blocker() )->filter( page( $ga4 ) );
	check( 'site that already listed analytics is not asked again', 7 === (int) Settings::get( 'revision' ) );

	/* ---- reask_on_new off: category shown, revision kept */
	fresh_site();
	Settings::set( 'reask_on_new', 0 );
	( new Blocker() )->filter( page( $ga4 ) );
	check( 'reask_on_new off: category shown, revision kept', in_array( 'analytics', categories(), true ) && 1 === (int) Settings::get( 'revision' ) );

	/* ---- wp-admin: the registered settings sanitizer must not swallow a revision bump */
	fresh_site();
	$GLOBALS['admin'] = true;
	Settings::set( 'revision', 41 );
	Settings::bump_revision();
	check( 'revision bump survives the settings sanitizer ("ask everyone again")', 42 === (int) Settings::get( 'revision' ) );
	Settings::set( 'accent', '#123456' );
	check( 'a settings save without a revision keeps the revision', 42 === (int) Settings::get( 'revision' ) );
	( new Blocker() )->filter( page( $ga4 ) );
	check( 'a new category re-asks in wp-admin requests too', 43 === (int) Settings::get( 'revision' ) );
	$GLOBALS['admin'] = false;

	/* ---- Allowlisted services are not blocked and add nothing */
	fresh_site();
	Settings::set( 'allow_services', [ 'ga4' ] );
	( new Blocker() )->filter( page( $ga4 ) );
	check( 'allowlisted GA4 adds no category', [ 'necessary' ] === categories() );

	echo $failures ? "$failures check(s) failed\n" : "All checks passed\n";
	exit( $failures ? 1 : 0 );
}
