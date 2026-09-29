<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the cookie list current: a scheduled server-side scan of the site's own
 * markup, plus an optional deep scan run in an administrator's browser.
 */
final class Scanner {

	public const CRON_HOOK    = 'aicc_scheduled_scan';
	public const STATE_OPTION = 'aicc_scan_state';
	public const PROBE_ARG    = 'aicc_probe';

	public function hooks(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedules' ] );
		add_action( self::CRON_HOOK, [ $this, 'run' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_deep_scan' ], 20 );
	}

	public function add_schedules( array $schedules ): array {
		$schedules['monthly'] ??= [
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once a month', 'aicc' ),
		];

		return $schedules;
	}

	public static function state(): array {
		$state = get_option( self::STATE_OPTION, [] );

		return wp_parse_args(
			is_array( $state ) ? $state : [],
			[
				'last_run'   => 0,
				'services'   => [],
				'pages'      => 0,
				'new'        => [],
				'last_error' => '',
			]
		);
	}

	public static function reschedule( string $frequency, bool $enabled ): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( $enabled ) {
			$frequency = in_array( $frequency, [ 'daily', 'weekly', 'monthly' ], true ) ? $frequency : 'weekly';
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, $frequency, self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Probe token — lets the scanner fetch unblocked markup               */
	/* ------------------------------------------------------------------ */

	private static function issue_probe_token(): string {
		$token = wp_generate_password( 32, false, false );
		set_transient( 'aicc_probe_' . $token, 1, 5 * MINUTE_IN_SECONDS );

		return $token;
	}

	public static function is_scan_request(): bool {
		// Authenticated by the short-lived random server-issued token below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$probe = isset( $_GET[ self::PROBE_ARG ] ) && is_string( $_GET[ self::PROBE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PROBE_ARG ] ) ) : '';
		if ( preg_match( '/^[A-Za-z0-9]{32}$/D', $probe ) === 1 && get_transient( 'aicc_probe_' . $probe ) ) {
			return true;
		}

		return self::is_deep_scan_request();
	}

	public static function is_deep_scan_request(): bool {
		if ( empty( $_GET['aicc_deep_scan'] ) || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		return (bool) wp_verify_nonce( $nonce, 'aicc_deep_scan' );
	}

	public function maybe_enqueue_deep_scan(): void {
		if ( ! self::is_deep_scan_request() ) {
			return;
		}

		wp_enqueue_script( 'aicc-scanner', AICC_URL . 'assets/js/scanner.js', [], VERSION, true );
		wp_localize_script(
			'aicc-scanner',
			'AICC_SCAN',
			[
				'endpoint' => esc_url_raw( rest_url( 'aicc/v1/report' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'wait'     => 6000,
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* The scan itself                                                     */
	/* ------------------------------------------------------------------ */

	public function run(): array {
		$state               = self::state();
		$state['new']        = [];
		$detected            = [];
		$fetched             = 0;
		$state['last_error'] = '';

		foreach ( $this->detect_from_plugins() as $service ) {
			$detected[ $service ] = true;
		}

		$token = self::issue_probe_token();

		foreach ( $this->urls_to_scan() as $url ) {
			$response = wp_safe_remote_get(
				add_query_arg( self::PROBE_ARG, $token, $url ),
				[
					'timeout'             => 20,
					'redirection'         => 0,
					'limit_response_size' => MB_IN_BYTES,
					'user-agent'          => 'AdventistaiCookieScanner/' . VERSION . '; ' . home_url(),
					'headers'             => [ 'Accept' => 'text/html' ],
				]
			);

			if ( is_wp_error( $response ) ) {
				$state['last_error'] = $response->get_error_message();
				continue;
			}
			if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
				continue;
			}

			$body = (string) wp_remote_retrieve_body( $response );
			++$fetched;

			foreach ( $this->detect_in_html( $body ) as $service ) {
				$detected[ $service ] = true;
			}
		}
		delete_transient( 'aicc_probe_' . $token );

		$new = [];
		foreach ( array_keys( $detected ) as $service ) {
			foreach ( Registry::record_service( $service ) as $entry ) {
				$new[] = $entry;
			}
		}

		Registry::prune_inactive();

		$state['last_run'] = time();
		$state['services'] = array_keys( $detected );
		$state['pages']    = $fetched;
		$state['new']      = array_map( static fn( array $e ): string => $e['name'], $new );

		update_option( self::STATE_OPTION, $state );

		$this->after_new_cookies( $new );

		return $state;
	}

	/** Merge cookie names reported by a browser deep scan. */
	public static function ingest_report( array $cookies, array $storage ): array {
		$items = [];
		foreach ( $cookies as $name ) {
			$items[] = [ 'name' => (string) $name, 'storage' => 'cookie' ];
		}
		foreach ( $storage as $name ) {
			$items[] = [ 'name' => (string) $name, 'storage' => 'local' ];
		}

		$new = Registry::record( $items, 'scan' );

		$state             = self::state();
		$state['last_run'] = time();
		$state['new']      = array_values(
			array_unique(
				array_merge(
					(array) $state['new'],
					array_map( static fn( array $e ): string => $e['name'], $new )
				)
			)
		);
		update_option( self::STATE_OPTION, $state );

		( new self() )->after_new_cookies( $new );

		return $new;
	}

	/**
	 * If the scan turned up new optional cookies, ask visitors again —
	 * consent given before a new tracker existed does not cover it.
	 */
	private function after_new_cookies( array $new ): void {
		if ( $new === [] ) {
			return;
		}

		$needs_reask = false;
		foreach ( $new as $entry ) {
			$cat = Category::from_slug( (string) $entry['category'] );
			if ( $cat->is_optional() || $cat === Category::Unknown ) {
				$needs_reask = true;
				break;
			}
		}

		if ( $needs_reask && Settings::get( 'reask_on_new' ) ) {
			Settings::bump_revision();
		}

		do_action( 'aicc_new_cookies_found', $new );
	}

	private function urls_to_scan(): array {
		$urls = [ home_url( '/' ) ];

		$posts = get_posts(
			[
				'numberposts'      => 3,
				'post_status'      => 'publish',
				'suppress_filters' => false,
			]
		);
		foreach ( $posts as $post ) {
			$urls[] = get_permalink( $post );
		}

		$pages = get_posts(
			[
				'post_type'        => 'page',
				'numberposts'      => 2,
				'post_status'      => 'publish',
				'orderby'          => 'menu_order',
				'order'            => 'ASC',
				'suppress_filters' => false,
			]
		);
		foreach ( $pages as $page ) {
			$urls[] = get_permalink( $page );
		}

		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );

		return array_values( array_filter( $urls, [ self::class, 'is_same_site_url' ] ) );
	}

	public static function is_same_site_url( string $url ): bool {
		$candidate = wp_parse_url( $url );
		$site      = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $candidate ) || ! is_array( $site ) ) {
			return false;
		}

		$candidate_scheme = strtolower( (string) ( $candidate['scheme'] ?? '' ) );
		$site_scheme      = strtolower( (string) ( $site['scheme'] ?? '' ) );
		$candidate_host   = strtolower( rtrim( (string) ( $candidate['host'] ?? '' ), '.' ) );
		$site_host        = strtolower( rtrim( (string) ( $site['host'] ?? '' ), '.' ) );
		$candidate_port   = isset( $candidate['port'] ) ? (int) $candidate['port'] : ( $candidate_scheme === 'https' ? 443 : 80 );
		$site_port        = isset( $site['port'] ) ? (int) $site['port'] : ( $site_scheme === 'https' ? 443 : 80 );

		return in_array( $candidate_scheme, [ 'http', 'https' ], true )
			&& $candidate_scheme === $site_scheme
			&& $candidate_host !== ''
			&& $candidate_host === $site_host
			&& $candidate_port === $site_port;
	}

	private function detect_in_html( string $html ): array {
		// Ignore this plugin's own snippets, or we would detect ourselves.
		$html = (string) preg_replace( '#<script[^>]*data-aicc[^>]*>.*?</script>#is', '', $html );

		$found = [];
		$lower = strtolower( $html );

		foreach ( Registry::services() as $key => $svc ) {
			if ( ! empty( $svc['always'] ) ) {
				$found[] = $key; // always present: WordPress core, this plugin
				continue;
			}
			foreach ( (array) ( $svc['url'] ?? [] ) as $needle ) {
				if ( str_contains( $lower, strtolower( $needle ) ) ) {
					$found[] = $key;
					continue 2;
				}
			}
			foreach ( (array) ( $svc['inline'] ?? [] ) as $needle ) {
				if ( str_contains( $html, $needle ) ) {
					$found[] = $key;
					continue 2;
				}
			}
		}

		return array_unique( $found );
	}

	private function detect_from_plugins(): array {
		$active = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		$found = [];
		foreach ( Registry::services() as $key => $svc ) {
			foreach ( (array) ( $svc['plugins'] ?? [] ) as $file ) {
				if ( in_array( $file, $active, true ) ) {
					$found[] = $key;
					break;
				}
			}
		}

		return $found;
	}
}
