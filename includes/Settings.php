<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPTION = 'aicc_settings';

	public static function defaults(): array {
		return [
			'enabled'          => 1,
			'default_lang'     => 'lt',
			'lang_switcher'    => 1,
			'position'         => 'bottom-left',   // bottom-left | bottom-right | bottom-bar
			'theme'            => 'auto',          // auto | light | dark
			'accent'           => '#1f5c3d',
			'policy_page'      => 0,
			'consent_days'     => 180,
			'block_embeds'     => 1,
			'consent_mode'     => 1,               // Google Consent Mode v2 defaults
			'log_consent'      => 1,
			'log_months'       => 24,               // how long consent records are kept
			'reask_on_new'     => 1,               // re-ask when the scanner finds new optional cookies
			'scan_enabled'     => 1,
			'scan_frequency'   => 'weekly',        // daily | weekly | monthly
			'floating_mode'    => 'pending',       // pending | always | never
			'auto_hide'        => 20,               // seconds until the banner steps aside (0 = never)
			'precheck'         => [ 'functional' ], // categories already ticked when the panel opens
			'implied'          => [ 'functional' ], // OPT-OUT: allowed until the visitor objects
			'allow_services'   => [],               // services never blocked (e.g. youtube)
			'yt_nocookie'      => 1,                // rewrite YouTube embeds to youtube-nocookie.com
			'revision'         => 1,
		];
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		return wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	public static function set( string $key, mixed $value ): void {
		$all         = self::all();
		$all[ $key ] = $value;
		update_option( self::OPTION, $all );
	}

	public static function bump_revision(): void {
		$current = max( 1, (int) self::get( 'revision' ) );
		self::set( 'revision', $current >= 65000 ? 2 : $current + 1 );
	}

	public static function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = self::defaults();
		$out      = self::all();

		foreach ( [ 'enabled', 'lang_switcher', 'block_embeds', 'consent_mode', 'log_consent', 'reask_on_new', 'scan_enabled', 'yt_nocookie' ] as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$lang                = sanitize_key( self::scalar( $input['default_lang'] ?? 'lt', 'lt' ) );
		$out['default_lang'] = in_array( $lang, I18n::LANGS, true ) ? $lang : 'lt';

		$position        = sanitize_key( self::scalar( $input['position'] ?? '' ) );
		$out['position'] = in_array( $position, [ 'bottom-left', 'bottom-right', 'bottom-bar' ], true ) ? $position : $defaults['position'];

		$theme        = sanitize_key( self::scalar( $input['theme'] ?? '' ) );
		$out['theme'] = in_array( $theme, [ 'auto', 'light', 'dark' ], true ) ? $theme : $defaults['theme'];

		$accent        = sanitize_hex_color( self::scalar( $input['accent'] ?? '' ) );
		$out['accent'] = $accent ?: $defaults['accent'];

		$out['auto_hide'] = max( 0, min( 120, absint( $input['auto_hide'] ?? 20 ) ) );

		$mode                 = sanitize_key( self::scalar( $input['floating_mode'] ?? '' ) );
		$out['floating_mode'] = in_array( $mode, [ 'pending', 'always', 'never' ], true ) ? $mode : $defaults['floating_mode'];

		$valid            = array_map( static fn( Category $c ): string => $c->value, Category::optional() );
		$out['precheck']  = array_values( array_intersect( $valid, self::sanitize_keys( $input['precheck'] ?? [] ) ) );
		$out['implied']   = array_values( array_intersect( $valid, self::sanitize_keys( $input['implied'] ?? [] ) ) );

		$services              = array_keys( Registry::services() );
		$out['allow_services'] = array_values( array_intersect( $services, self::sanitize_keys( $input['allow_services'] ?? [] ) ) );

		$out['policy_page']  = absint( $input['policy_page'] ?? 0 );
		$out['consent_days'] = max( 30, min( 365, absint( $input['consent_days'] ?? 180 ) ) );
		$out['log_months']   = max( 6, min( 60, absint( $input['log_months'] ?? 24 ) ) );

		$freq                  = sanitize_key( self::scalar( $input['scan_frequency'] ?? '' ) );
		$out['scan_frequency'] = in_array( $freq, [ 'daily', 'weekly', 'monthly' ], true ) ? $freq : $defaults['scan_frequency'];

		Scanner::reschedule( $out['scan_frequency'], (bool) $out['scan_enabled'] );

		return $out;
	}

	private static function scalar( mixed $value, string $default = '' ): string {
		return is_scalar( $value ) ? (string) $value : $default;
	}

	private static function sanitize_keys( mixed $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = sanitize_key( (string) $item );
			}
		}

		return $out;
	}
}
