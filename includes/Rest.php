<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest {

	public const NS = 'aicc/v1';

	public const PURGE_HOOK = 'aicc_purge_log';

	private const MAX_BODY_BYTES   = 16384;
	private const MAX_REPORT_ITEMS = 200;

	public function hooks(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( self::PURGE_HOOK, [ $this, 'purge_old_records' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/consent',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'log_consent' ],
				'permission_callback' => [ $this, 'can_log_consent' ],
				'args'                => [
					'cid'        => [
						'required'  => true,
						'type'      => 'string',
						'minLength' => 8,
						'maxLength' => 40,
						'pattern'   => '^[A-Za-z0-9-]+$',
					],
					'categories' => [
						'required'          => true,
						'validate_callback' => [ self::class, 'validate_categories' ],
					],
					'lang'       => [
						'default' => 'lt',
						'type'    => 'string',
						'enum'    => I18n::LANGS,
					],
					'url'        => [
						'required'          => true,
						'validate_callback' => [ self::class, 'validate_source_url' ],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/report',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'report' ],
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => [
					'cookies' => [
						'default'           => [],
						'validate_callback' => [ self::class, 'validate_report_list' ],
					],
					'storage' => [
						'default'           => [],
						'validate_callback' => [ self::class, 'validate_report_list' ],
					],
				],
			]
		);
	}

	/** The public endpoint must work on cached pages, so protect it without a nonce. */
	public function can_log_consent( WP_REST_Request $request ): bool|WP_Error {
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( $_SERVER['CONTENT_LENGTH'] ) : 0;
		if ( $length > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'aicc_request_too_large', __( 'Request body is too large.', 'aicc' ), [ 'status' => 413 ] );
		}

		$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
		if ( ! str_contains( $content_type, 'application/json' ) ) {
			return new WP_Error( 'aicc_json_required', __( 'JSON request required.', 'aicc' ), [ 'status' => 415 ] );
		}

		$origin = trim( (string) $request->get_header( 'origin' ) );
		if ( $origin !== '' && ! self::same_origin( $origin ) ) {
			return new WP_Error( 'aicc_invalid_origin', __( 'Cross-origin consent logging is not allowed.', 'aicc' ), [ 'status' => 403 ] );
		}

		return true;
	}

	public static function validate_categories( mixed $value ): bool {
		if ( ! is_array( $value ) || count( $value ) > count( Category::optional() ) ) {
			return false;
		}

		$allowed = array_map( static fn( Category $cat ): string => $cat->value, Category::optional() );
		foreach ( $value as $key => $enabled ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				return false;
			}
			if ( ! is_bool( $enabled ) && $enabled !== 0 && $enabled !== 1 ) {
				return false;
			}
		}

		return true;
	}

	public static function validate_source_url( mixed $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 2048 && self::same_origin( $value );
	}

	public static function validate_report_list( mixed $value ): bool {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_REPORT_ITEMS ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || strlen( $item ) > 120 ) {
				return false;
			}
		}

		return true;
	}

	/** Store a minimal, pseudonymous record of the choice — proof of consent under Art. 7(1). */
	public function log_consent( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Settings::get( 'log_consent' ) ) {
			return new WP_REST_Response( [ 'logged' => false ], 200 );
		}

		global $wpdb;

		$salt = wp_salt( 'nonce' );
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ), 0, 64 ) : '';
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
		$cid  = sanitize_text_field( (string) $request->get_param( 'cid' ) );

		// A client-ID limit stops repeated writes without treating every visitor
		// behind Cloudflare or another shared proxy as the same person.
		$client_key = hash_hmac( 'sha256', $cid . "\0" . $ip, $salt );
		$ip_key     = hash_hmac( 'sha256', $ip, $salt );
		if ( ! self::consume_rate_limit( 'client_' . $client_key, 30 ) || ! self::consume_rate_limit( 'ip_' . $ip_key, 1000 ) ) {
			return new WP_REST_Response( [ 'logged' => false, 'throttled' => true ], 429 );
		}

		$categories = (array) $request->get_param( 'categories' );
		$clean      = [];
		foreach ( Category::optional() as $cat ) {
			$clean[ $cat->value ] = ! empty( $categories[ $cat->value ] );
		}

		$lang = sanitize_key( (string) $request->get_param( 'lang' ) );
		$lang = in_array( $lang, I18n::LANGS, true ) ? $lang : 'lt';

		$url = substr( esc_url_raw( (string) $request->get_param( 'url' ), [ 'http', 'https' ] ), 0, 255 );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'aicc_consents',
			[
				'consent_id' => $cid,
				'categories' => (string) wp_json_encode( $clean ),
				'lang'       => $lang,
				'revision'   => (int) Settings::get( 'revision' ),
				'source_url' => $url,
				'ip_hash'    => hash_hmac( 'sha256', $ip, $salt ),
				'ua_hash'    => hash_hmac( 'sha256', $ua, $salt ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
		if ( $inserted === false ) {
			return new WP_REST_Response( [ 'logged' => false ], 500 );
		}

		return new WP_REST_Response( [ 'logged' => true ], 201 );
	}

	/**
	 * Consent records are pseudonymous personal data, so they do not live forever.
	 * Runs daily; keeps the window set in the settings (default 24 months).
	 */
	public function purge_old_records(): void {
		global $wpdb;

		$months = max( 6, min( 60, (int) Settings::get( 'log_months' ) ) );
		$table  = $wpdb->prefix . 'aicc_consents';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $months * 30 * DAY_IN_SECONDS );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff ) );
	}

	/** Cookie names discovered by the deep scan running in an admin's browser. */
	public function report( WP_REST_Request $request ): WP_REST_Response {
		$cookies = array_values( array_unique( array_map( 'sanitize_text_field', (array) $request->get_param( 'cookies' ) ) ) );
		$storage = array_values( array_unique( array_map( 'sanitize_text_field', (array) $request->get_param( 'storage' ) ) ) );

		$new = Scanner::ingest_report( $cookies, $storage );

		return new WP_REST_Response(
			[
				'received' => count( $cookies ) + count( $storage ),
				'new'      => array_column( $new, 'name' ),
			],
			200
		);
	}

	private static function consume_rate_limit( string $fingerprint, int $limit ): bool {
		$bucket = 'aicc_rl_' . substr( $fingerprint, 0, 40 );
		$hits   = (int) get_transient( $bucket );
		if ( $hits >= $limit ) {
			return false;
		}

		set_transient( $bucket, $hits + 1, HOUR_IN_SECONDS );

		return true;
	}

	private static function same_origin( string $url ): bool {
		$candidate = wp_parse_url( $url );
		if ( ! is_array( $candidate ) || empty( $candidate['scheme'] ) || empty( $candidate['host'] ) ) {
			return false;
		}

		$candidate_origin = self::origin_parts( $candidate );
		foreach ( [ home_url( '/' ), site_url( '/' ), rest_url() ] as $base_url ) {
			$base = wp_parse_url( $base_url );
			if ( is_array( $base ) && $candidate_origin === self::origin_parts( $base ) ) {
				return true;
			}
		}

		return false;
	}

	private static function origin_parts( array $parts ): string {
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( $scheme === 'https' ? 443 : 80 );

		return $scheme . '://' . $host . ':' . $port;
	}
}
