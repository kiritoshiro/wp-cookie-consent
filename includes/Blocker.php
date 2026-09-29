<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites tracking scripts and embeds so nothing loads before consent.
 * Output is identical for every visitor, so page caching keeps working —
 * the unblocking happens in the browser.
 */
final class Blocker {

	public function hooks(): void {
		add_action( 'wp_head', [ $this, 'consent_mode_defaults' ], 1 );
		add_action( 'template_redirect', [ $this, 'start_buffer' ], 0 );
	}

	public function consent_mode_defaults(): void {
		if ( ! Settings::get( 'enabled' ) || ! Settings::get( 'consent_mode' ) ) {
			return;
		}
		?>
<script data-aicc-ignore>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:500});
</script>
		<?php
	}

	public function start_buffer(): void {
		if ( ! $this->should_filter() ) {
			return;
		}

		ob_start( [ $this, 'filter' ] );
	}

	private function should_filter(): bool {
		if ( ! Settings::get( 'enabled' ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || is_trackback() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		if ( Scanner::is_scan_request() ) {
			return false; // the scanner needs to see everything load.
		}

		return (bool) apply_filters( 'aicc_should_block', true );
	}

	public function filter( string $html ): string {
		if ( stripos( $html, '<html' ) === false && stripos( $html, '<body' ) === false ) {
			return $html;
		}

		$html = (string) preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			[ $this, 'script_callback' ],
			$html
		);

		if ( Settings::get( 'block_embeds' ) ) {
			$html = (string) preg_replace_callback(
				'#<iframe\b([^>]*)>(.*?)</iframe>#is',
				[ $this, 'iframe_callback' ],
				$html
			);
			$html = (string) preg_replace_callback(
				'#<iframe\b([^>]*?)/>#is',
				[ $this, 'iframe_callback' ],
				$html
			);
		}

		return $html;
	}

	/** Services the site owner has chosen to load without prior consent. */
	private function allowlisted( string $service ): bool {
		return in_array( $service, (array) Settings::get( 'allow_services' ), true );
	}

	/** Swap YouTube embeds for the privacy-enhanced host, which sets nothing until playback. */
	private function privacy_mode( string $html, string $service ): string {
		if ( $service !== 'youtube' || ! Settings::get( 'yt_nocookie' ) ) {
			return $html;
		}

		return str_ireplace(
			[ 'www.youtube.com/embed', 'youtube.com/embed' ],
			[ 'www.youtube-nocookie.com/embed', 'youtube-nocookie.com/embed' ],
			$html
		);
	}

	/** Read a quoted or unquoted HTML attribute without changing its value. */
	private function attribute( string $attrs, string $name ): ?string {
		$name = preg_quote( $name, '#' );
		if ( preg_match(
			'#(?:^|\s)' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i',
			$attrs,
			$match,
			PREG_UNMATCHED_AS_NULL
		) !== 1 ) {
			return null;
		}

		foreach ( [ 1, 2, 3 ] as $index ) {
			if ( isset( $match[ $index ] ) ) {
				return html_entity_decode( (string) $match[ $index ], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return '';
	}

	private function remove_attribute( string $attrs, string $name ): string {
		$name = preg_quote( $name, '#' );

		return (string) preg_replace(
			'#\s+' . $name . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?#i',
			'',
			$attrs
		);
	}

	private function has_aicc_attribute( string $attrs ): bool {
		return preg_match( '#(?:^|\s)data-aicc(?:-[a-z0-9_-]+)?(?:\s*=|\s|$)#i', $attrs ) === 1;
	}

	private function script_callback( array $m ): string {
		$full  = $m[0];
		$attrs = $m[1];
		$body  = $m[2] ?? '';

		if ( $this->has_aicc_attribute( $attrs ) ) {
			return $full;
		}

		$type = strtolower( trim( (string) $this->attribute( $attrs, 'type' ) ) );
		if ( in_array( $type, [ 'text/plain', 'application/ld+json', 'application/json', 'text/template', 'text/x-template' ], true ) ) {
			return $full;
		}

		$src = (string) ( $this->attribute( $attrs, 'src' ) ?? '' );

		$service = $src !== '' ? Registry::match_url( $src ) : Registry::match_inline( $body );
		if ( $service === null ) {
			return $full;
		}

		if ( $this->allowlisted( $service ) ) {
			return $this->privacy_mode( $full, $service );
		}

		$svc = Registry::service( $service );
		$cat = Category::from_slug( (string) ( $svc['category'] ?? 'unknown' ) );
		if ( ! $cat->is_optional() ) {
			return $full;
		}

		$attrs = $this->remove_attribute( $attrs, 'src' );
		$attrs = $this->remove_attribute( $attrs, 'type' );

		$extra = sprintf(
			' type="text/plain" data-aicc-cat="%s" data-aicc-service="%s"',
			esc_attr( $cat->value ),
			esc_attr( $service )
		);
		if ( $src !== '' ) {
			$extra .= sprintf( ' data-aicc-src="%s"', esc_attr( $src ) );
		}
		if ( $type !== '' ) {
			$extra .= sprintf( ' data-aicc-type="%s"', esc_attr( $type ) );
		}

		return '<script' . rtrim( $attrs ) . $extra . '>' . $body . '</script>';
	}

	private function iframe_callback( array $m ): string {
		$full  = $m[0];
		$attrs = $m[1];

		if ( $this->has_aicc_attribute( $attrs ) ) {
			return $full;
		}
		$src = $this->attribute( $attrs, 'src' );
		if ( $src === null || $src === '' ) {
			return $full;
		}

		$service = Registry::match_url( $src );
		if ( $service === null ) {
			return $full;
		}

		if ( $this->allowlisted( $service ) ) {
			return $this->privacy_mode( $full, $service );
		}

		$svc = Registry::service( $service );
		$cat = Category::from_slug( (string) ( $svc['category'] ?? 'unknown' ) );
		if ( ! $cat->is_optional() ) {
			return $full;
		}

		$lang = I18n::current();
		$text = sprintf(
			I18n::t( 'embed_text', $lang ),
			(string) ( $svc['name'] ?? $service ),
			$cat->label( $lang )
		);

		return sprintf(
			'<div class="aicc-embed" data-aicc-cat="%1$s" data-aicc-service="%2$s" data-aicc-embed="%3$s">'
			. '<div class="aicc-embed__inner">'
			. '<p class="aicc-embed__title" data-aicc-i18n="embed_title">%4$s</p>'
			. '<p class="aicc-embed__text" data-aicc-i18n-embed="1">%5$s</p>'
			. '<div class="aicc-embed__actions">'
			. '<button type="button" class="aicc-btn aicc-btn--primary" data-aicc-embed-allow data-aicc-i18n="embed_button">%6$s</button>'
			. '<button type="button" class="aicc-btn aicc-btn--ghost" data-aicc-embed-once data-aicc-i18n="embed_once">%7$s</button>'
			. '</div></div></div>',
			esc_attr( $cat->value ),
			esc_attr( (string) ( $svc['name'] ?? $service ) ),
			esc_attr( base64_encode( $this->privacy_mode( $full, $service ) ) ),
			esc_html( I18n::t( 'embed_title', $lang ) ),
			esc_html( $text ),
			esc_html( I18n::t( 'embed_button', $lang ) ),
			esc_html( I18n::t( 'embed_once', $lang ) )
		);
	}
}
