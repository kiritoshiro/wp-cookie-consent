<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	public const VERSION_OPTION = 'aicc_version';

	public static function activate(): void {
		$installed_version = (string) get_option( self::VERSION_OPTION, '' );
		$stored_settings   = get_option( Settings::OPTION, null );

		self::create_table();

		if ( $stored_settings === null ) {
			$defaults             = Settings::defaults();
			$defaults['revision'] = self::fresh_revision();
			add_option( Settings::OPTION, $defaults );
		} elseif ( self::needs_revision_refresh( $installed_version ) ) {
			Settings::bump_revision();
		}

		self::seed_cookies();
		self::ensure_policy_page();

		Scanner::reschedule( (string) Settings::get( 'scan_frequency' ), (bool) Settings::get( 'scan_enabled' ) );

		if ( ! wp_next_scheduled( Rest::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Rest::PURGE_HOOK );
		}

		update_option( self::VERSION_OPTION, VERSION );
	}

	public static function deactivate(): void {
		foreach ( [ Scanner::CRON_HOOK, Rest::PURGE_HOOK ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public static function maybe_upgrade(): void {
		$installed_version = (string) get_option( self::VERSION_OPTION, '' );
		if ( $installed_version === VERSION ) {
			return;
		}

		self::create_table();
		if ( get_option( Settings::OPTION, null ) !== null && self::needs_revision_refresh( $installed_version ) ) {
			Settings::bump_revision();
		}
		self::seed_cookies();
		Registry::prune_inactive();

		if ( ! wp_next_scheduled( Rest::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Rest::PURGE_HOOK );
		}
		update_option( self::VERSION_OPTION, VERSION );
	}

	/** Existing 1.0.6 installations reused revision 1 after reinstalling. */
	private static function needs_revision_refresh( string $installed_version ): bool {
		return $installed_version === '' || version_compare( $installed_version, '1.0.7', '<' );
	}

	/** A fresh install must not accidentally accept localStorage left by an older install. */
	private static function fresh_revision(): int {
		return wp_rand( 2, 60000 );
	}

	private static function create_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'aicc_consents';
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			consent_id VARCHAR(40) NOT NULL DEFAULT '',
			categories TEXT NOT NULL,
			lang VARCHAR(5) NOT NULL DEFAULT 'lt',
			revision SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			source_url VARCHAR(255) NOT NULL DEFAULT '',
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			ua_hash CHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY consent_id (consent_id),
			KEY created_at (created_at)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Make sure the site's own baseline cookies are listed before the first scan. */
	private static function seed_cookies(): void {
		Registry::record_service( 'wordpress' );
		Registry::record_service( 'aicc' );

		if ( class_exists( 'WooCommerce' ) ) {
			Registry::record_service( 'woocommerce' );
		}
	}

	private static function ensure_policy_page(): void {
		$existing = (int) Settings::get( 'policy_page' );
		if ( $existing > 0 && get_post_status( $existing ) === 'publish' ) {
			return;
		}

		$titles = [
			'lt' => 'Slapukų politika',
			'en' => 'Cookie policy',
			'ru' => 'Политика использования cookie',
		];
		$lang   = (string) Settings::get( 'default_lang' );
		$title  = $titles[ $lang ] ?? $titles['lt'];

		$found = get_page_by_path( 'slapuku-politika', OBJECT, 'page' );
		if ( $found instanceof \WP_Post ) {
			Settings::set( 'policy_page', $found->ID );

			return;
		}

		$page_id = wp_insert_post(
			[
				'post_title'   => $title,
				'post_name'    => 'slapuku-politika',
				'post_content' => '<!-- wp:shortcode -->[aicc_cookie_policy]<!-- /wp:shortcode -->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'comment_status' => 'closed',
				'ping_status'  => 'closed',
			]
		);

		if ( ! is_wp_error( $page_id ) ) {
			Settings::set( 'policy_page', (int) $page_id );
		}
	}
}
