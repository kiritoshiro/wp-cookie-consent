<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knows which third-party services exist, how to recognise them in page markup,
 * and which cookies they set. Also owns the stored (scanned + manual) cookie list.
 */
final class Registry {

	public const COOKIES_OPTION = 'aicc_cookies';

	private static ?array $stored_cache = null;

	/**
	 * Known services. Patterns are plain substrings, matched case-insensitively.
	 */
	public static function services(): array {
		$s = [
			'wordpress'   => [
				'name'     => 'WordPress',
				'provider' => 'adventistai',
				'category' => 'necessary',
				'always'   => true,
				'purpose'  => [
					'lt' => 'Svetainės variklio slapukai: prisijungimo seansas, komentarų autoriaus duomenys ir naršyklės patikra.',
					'en' => 'Core site cookies: login session, comment author details and a browser capability test.',
					'ru' => 'Служебные cookie сайта: сессия входа, данные автора комментария и проверка браузера.',
				],
				'cookies'  => [
					[ 'name' => 'wordpress_test_cookie', 'duration' => 'session' ],
					[ 'name' => 'wordpress_logged_in_*', 'duration' => '14d' ],
					[ 'name' => 'wordpress_sec_*', 'duration' => '14d' ],
					[ 'name' => 'wp-settings-*', 'duration' => '1y' ],
					[ 'name' => 'wp-settings-time-*', 'duration' => '1y' ],
					[ 'name' => 'comment_author_*', 'duration' => '1y' ],
					[ 'name' => 'comment_author_email_*', 'duration' => '1y' ],
					[ 'name' => 'comment_author_url_*', 'duration' => '1y' ],
				],
			],
			'aicc'        => [
				'name'     => 'Adventistai – slapukų sutikimas',
				'provider' => 'adventistai',
				'category' => 'necessary',
				'always'   => true,
				'purpose'  => [
					'lt' => 'Įsimena jūsų pasirinkimą dėl slapukų. Saugoma naršyklės vietinėje saugykloje, ne slapuke, todėl iki sutikimo neįrašomas joks slapukas.',
					'en' => 'Remembers your cookie choice. Stored in the browser’s local storage rather than a cookie, so nothing is written before you decide.',
					'ru' => 'Хранит ваш выбор о cookie. Записывается в локальное хранилище браузера, а не в cookie, поэтому до вашего решения ничего не сохраняется.',
				],
				'cookies'  => [
					[ 'name' => 'aicc_consent', 'duration' => 'ls', 'storage' => 'local' ],
				],
			],
			'woocommerce' => [
				'name'     => 'WooCommerce',
				'provider' => 'Automattic Inc.',
				'category' => 'necessary',
				'plugins'  => [ 'woocommerce/woocommerce.php' ],
				'purpose'  => [
					'lt' => 'Krepšelio turinys ir pirkėjo seansas parduotuvėje.',
					'en' => 'Shopping cart contents and the shopper’s session.',
					'ru' => 'Содержимое корзины и сессия покупателя.',
				],
				'cookies'  => [
					[ 'name' => 'woocommerce_cart_hash', 'duration' => 'session' ],
					[ 'name' => 'woocommerce_items_in_cart', 'duration' => 'session' ],
					[ 'name' => 'wp_woocommerce_session_*', 'duration' => '2d' ],
				],
			],
			'cloudflare'  => [
				'name'     => 'Cloudflare',
				'provider' => 'Cloudflare Inc.',
				'category' => 'necessary',
				'url'      => [ 'cloudflare.com', 'cdn-cgi/challenge-platform' ],
				'privacy'  => 'https://www.cloudflare.com/privacypolicy/',
				'purpose'  => [
					'lt' => 'Apsauga nuo robotų ir atakų prieš svetainę.',
					'en' => 'Bot and attack protection for the site.',
					'ru' => 'Защита сайта от ботов и атак.',
				],
				'cookies'  => [
					[ 'name' => '__cf_bm', 'duration' => '30min' ],
					[ 'name' => 'cf_clearance', 'duration' => '1y' ],
				],
			],
			'recaptcha'   => [
				'name'     => 'Google reCAPTCHA',
				'provider' => 'Google Ireland Ltd.',
				'category' => 'functional',
				'url'      => [ 'google.com/recaptcha', 'gstatic.com/recaptcha' ],
				'privacy'  => 'https://policies.google.com/privacy',
				'purpose'  => [
					'lt' => 'Atskiria žmones nuo robotų formose ir komentaruose.',
					'en' => 'Tells humans from bots in forms and comments.',
					'ru' => 'Отличает людей от ботов в формах и комментариях.',
				],
				'cookies'  => [
					[ 'name' => '_GRECAPTCHA', 'duration' => '6m' ],
				],
			],
			'google-fonts' => [
				'name'     => 'Google Fonts',
				'provider' => 'Google Ireland Ltd.',
				'category' => 'functional',
				'url'      => [ 'fonts.googleapis.com', 'fonts.gstatic.com' ],
				'privacy'  => 'https://policies.google.com/privacy',
				'purpose'  => [
					'lt' => 'Įkelia svetainės šriftus iš „Google“ serverių. Slapukų nenaudoja, bet perduoda jūsų IP adresą.',
					'en' => 'Loads the site’s fonts from Google servers. Sets no cookies but does transmit your IP address.',
					'ru' => 'Загружает шрифты сайта с серверов Google. Не использует cookie, но передаёт ваш IP-адрес.',
				],
				'cookies'  => [],
			],
			'ga4'         => [
				'name'     => 'Google Analytics 4',
				'provider' => 'Google Ireland Ltd.',
				'category' => 'analytics',
				'url'      => [ 'google-analytics.com', 'googletagmanager.com/gtag/js', 'analytics.js', 'gtag/js' ],
				'inline'   => [ 'gtag(', 'GoogleAnalyticsObject', "ga('create'" ],
				'privacy'  => 'https://policies.google.com/privacy',
				'plugins'  => [ 'google-site-kit/google-site-kit.php' ],
				'purpose'  => [
					'lt' => 'Renka apibendrintą lankomumo statistiką: kurie puslapiai skaitomi, iš kur atėjote, kiek laiko užtrukote.',
					'en' => 'Collects aggregated visit statistics: which pages are read, where you came from, how long you stayed.',
					'ru' => 'Собирает обобщённую статистику посещений: какие страницы читают, откуда вы пришли, сколько времени провели.',
				],
				'cookies'  => [
					[ 'name' => '_ga', 'duration' => '2y' ],
					[ 'name' => '_ga_*', 'duration' => '2y' ],
					[ 'name' => '_gid', 'duration' => '24h' ],
					[ 'name' => '_gat', 'duration' => '1min' ],
					[ 'name' => '_gat_gtag_*', 'duration' => '1min' ],
				],
			],
			'gtm'         => [
				'name'     => 'Google Tag Manager',
				'provider' => 'Google Ireland Ltd.',
				'category' => 'analytics',
				'url'      => [ 'googletagmanager.com/gtm.js', 'googletagmanager.com/ns.html' ],
				'inline'   => [ 'GTM-' ],
				'privacy'  => 'https://policies.google.com/privacy',
				'purpose'  => [
					'lt' => 'Žymų tvarkytuvė, per kurią įkeliami kiti analitikos ir rinkodaros scenarijai.',
					'en' => 'Tag manager that loads other analytics and marketing scripts.',
					'ru' => 'Менеджер тегов, через который загружаются другие скрипты аналитики и маркетинга.',
				],
				'cookies'  => [],
			],
			'jetpack'     => [
				'name'     => 'Jetpack Stats',
				'provider' => 'Automattic Inc.',
				'category' => 'analytics',
				'url'      => [ 'stats.wp.com', 'pixel.wp.com' ],
				'plugins'  => [ 'jetpack/jetpack.php' ],
				'privacy'  => 'https://automattic.com/privacy/',
				'purpose'  => [
					'lt' => 'Skaičiuoja peržiūras ir lankytojus „WordPress.com“ statistikoje.',
					'en' => 'Counts views and visitors for WordPress.com statistics.',
					'ru' => 'Считает просмотры и посетителей для статистики WordPress.com.',
				],
				'cookies'  => [
					[ 'name' => 'tk_ai', 'duration' => 'session' ],
					[ 'name' => 'tk_qs', 'duration' => 'session' ],
				],
			],
			'clarity'     => [
				'name'     => 'Microsoft Clarity',
				'provider' => 'Microsoft Ireland Ltd.',
				'category' => 'analytics',
				'url'      => [ 'clarity.ms' ],
				'privacy'  => 'https://privacy.microsoft.com/privacystatement',
				'purpose'  => [
					'lt' => 'Įrašo apibendrintus naršymo įrašus ir „karštąsias zonas“, kad matytume, kaip naudojamasi puslapiais.',
					'en' => 'Records aggregated session replays and heatmaps to show how pages are used.',
					'ru' => 'Записывает обобщённые сессии и тепловые карты, чтобы понять, как используются страницы.',
				],
				'cookies'  => [
					[ 'name' => '_clck', 'duration' => '1y' ],
					[ 'name' => '_clsk', 'duration' => '1d' ],
					[ 'name' => 'CLID', 'duration' => '1y' ],
				],
			],
			'hotjar'      => [
				'name'     => 'Hotjar',
				'provider' => 'Hotjar Ltd.',
				'category' => 'analytics',
				'url'      => [ 'hotjar.com', 'hotjar.io' ],
				'privacy'  => 'https://www.hotjar.com/legal/policies/privacy/',
				'purpose'  => [
					'lt' => 'Naudojimosi analizė: pelės judesiai, slinkimas, apklausos.',
					'en' => 'Usage analysis: mouse movement, scrolling, on-site surveys.',
					'ru' => 'Анализ использования: движения мыши, прокрутка, опросы.',
				],
				'cookies'  => [
					[ 'name' => '_hjSessionUser_*', 'duration' => '1y' ],
					[ 'name' => '_hjSession_*', 'duration' => '30min' ],
				],
			],
			'meta-pixel'  => [
				'name'     => 'Meta Pixel',
				'provider' => 'Meta Platforms Ireland Ltd.',
				'category' => 'marketing',
				'url'      => [ 'connect.facebook.net', 'facebook.com/tr' ],
				'inline'   => [ 'fbq(' ],
				'privacy'  => 'https://www.facebook.com/privacy/policy/',
				'purpose'  => [
					'lt' => 'Matuoja „Facebook“ ir „Instagram“ reklamos rezultatus ir sudaro auditorijas.',
					'en' => 'Measures Facebook and Instagram ad performance and builds audiences.',
					'ru' => 'Измеряет результаты рекламы в Facebook и Instagram и формирует аудитории.',
				],
				'cookies'  => [
					[ 'name' => '_fbp', 'duration' => '3m' ],
					[ 'name' => '_fbc', 'duration' => '3m' ],
					[ 'name' => 'fr', 'duration' => '3m' ],
				],
			],
			'google-ads'  => [
				'name'     => 'Google Ads',
				'provider' => 'Google Ireland Ltd.',
				'category' => 'marketing',
				'url'      => [ 'googleadservices.com', 'doubleclick.net', 'googlesyndication.com' ],
				'privacy'  => 'https://policies.google.com/privacy',
				'purpose'  => [
					'lt' => 'Reklamos rodymas ir konversijų matavimas „Google“ tinkle.',
					'en' => 'Ad delivery and conversion measurement across the Google network.',
					'ru' => 'Показ рекламы и измерение конверсий в сети Google.',
				],
				'cookies'  => [
					[ 'name' => 'IDE', 'duration' => '13m' ],
					[ 'name' => 'test_cookie', 'duration' => '15min' ],
					[ 'name' => '_gcl_au', 'duration' => '3m' ],
				],
			],
			'youtube'     => [
				'name'     => 'YouTube',
				'provider' => 'Google Ireland Ltd.',
				'category' => 'functional',
				'embed'    => true,
				'url'      => [ 'youtube.com/embed', 'youtube-nocookie.com', 'youtube.com/iframe_api', 'ytimg.com' ],
				'privacy'  => 'https://policies.google.com/privacy',
				'purpose'  => [
					'lt' => 'Įterpti vaizdo įrašai — pamokslai, transliacijos. „YouTube“ įrašo peržiūrų nuostatas ir gali sekti jus reklamai.',
					'en' => 'Embedded video — sermons and livestreams. YouTube stores playback preferences and may track you for advertising.',
					'ru' => 'Встроенное видео — проповеди и трансляции. YouTube сохраняет настройки просмотра и может отслеживать вас для рекламы.',
				],
				'cookies'  => [
					[ 'name' => 'VISITOR_INFO1_LIVE', 'duration' => '6m' ],
					[ 'name' => 'YSC', 'duration' => 'session' ],
					[ 'name' => 'VISITOR_PRIVACY_METADATA', 'duration' => '6m' ],
					[ 'name' => 'PREF', 'duration' => '2y' ],
				],
			],
			'vimeo'       => [
				'name'     => 'Vimeo',
				'provider' => 'Vimeo Inc.',
				'category' => 'functional',
				'embed'    => true,
				'url'      => [ 'player.vimeo.com', 'vimeocdn.com' ],
				'privacy'  => 'https://vimeo.com/privacy',
				'purpose'  => [
					'lt' => 'Įterptas „Vimeo“ grotuvas ir jo peržiūros nuostatos.',
					'en' => 'Embedded Vimeo player and its playback preferences.',
					'ru' => 'Встроенный плеер Vimeo и его настройки просмотра.',
				],
				'cookies'  => [
					[ 'name' => 'vuid', 'duration' => '2y' ],
					[ 'name' => 'player', 'duration' => '1y' ],
				],
			],
			'spotify'     => [
				'name'     => 'Spotify',
				'provider' => 'Spotify AB',
				'category' => 'functional',
				'embed'    => true,
				'url'      => [ 'open.spotify.com/embed', 'spotify.com/embed' ],
				'privacy'  => 'https://www.spotify.com/legal/privacy-policy/',
				'purpose'  => [
					'lt' => 'Įterptas garso grotuvas giesmėms ir podkastams.',
					'en' => 'Embedded audio player for hymns and podcasts.',
					'ru' => 'Встроенный аудиоплеер для песнопений и подкастов.',
				],
				'cookies'  => [
					[ 'name' => 'sp_t', 'duration' => '1y' ],
					[ 'name' => 'sp_landing', 'duration' => '1d' ],
				],
			],
			'soundcloud'  => [
				'name'     => 'SoundCloud',
				'provider' => 'SoundCloud Ltd.',
				'category' => 'functional',
				'embed'    => true,
				'url'      => [ 'w.soundcloud.com', 'soundcloud.com/player' ],
				'privacy'  => 'https://soundcloud.com/pages/privacy',
				'purpose'  => [
					'lt' => 'Įterptas „SoundCloud“ garso grotuvas.',
					'en' => 'Embedded SoundCloud audio player.',
					'ru' => 'Встроенный аудиоплеер SoundCloud.',
				],
				'cookies'  => [
					[ 'name' => 'sc_anonymous_id', 'duration' => '10y' ],
				],
			],
			'google-maps' => [
				'name'     => 'Google Maps',
				'provider' => 'Google Ireland Ltd.',
				'category' => 'functional',
				'embed'    => true,
				'url'      => [ 'google.com/maps/embed', 'maps.googleapis.com', 'maps.google.com' ],
				'privacy'  => 'https://policies.google.com/privacy',
				'purpose'  => [
					'lt' => 'Įterptas žemėlapis su bendruomenės adresu.',
					'en' => 'Embedded map showing the congregation’s address.',
					'ru' => 'Встроенная карта с адресом общины.',
				],
				'cookies'  => [
					[ 'name' => 'NID', 'duration' => '6m' ],
				],
			],
			'facebook'    => [
				'name'     => 'Facebook plugins',
				'provider' => 'Meta Platforms Ireland Ltd.',
				'category' => 'marketing',
				'embed'    => true,
				'url'      => [ 'facebook.com/plugins', 'facebook.com/v1', 'fbcdn.net' ],
				'privacy'  => 'https://www.facebook.com/privacy/policy/',
				'purpose'  => [
					'lt' => 'Įterpti „Facebook“ įrašai, vaizdo įrašai ir puslapio blokas.',
					'en' => 'Embedded Facebook posts, videos and the page box.',
					'ru' => 'Встроенные записи Facebook, видео и блок страницы.',
				],
				'cookies'  => [
					[ 'name' => 'fr', 'duration' => '3m' ],
					[ 'name' => 'datr', 'duration' => '2y' ],
				],
			],
			'instagram'   => [
				'name'     => 'Instagram',
				'provider' => 'Meta Platforms Ireland Ltd.',
				'category' => 'marketing',
				'embed'    => true,
				'url'      => [ 'instagram.com/embed', 'cdninstagram.com' ],
				'privacy'  => 'https://privacycenter.instagram.com/policy',
				'purpose'  => [
					'lt' => 'Įterpti „Instagram“ įrašai.',
					'en' => 'Embedded Instagram posts.',
					'ru' => 'Встроенные записи Instagram.',
				],
				'cookies'  => [
					[ 'name' => 'ig_did', 'duration' => '1y' ],
				],
			],
			'x-twitter'   => [
				'name'     => 'X (Twitter)',
				'provider' => 'X Corp.',
				'category' => 'marketing',
				'embed'    => true,
				'url'      => [ 'platform.twitter.com', 'twitter.com/widgets', 'x.com/widgets' ],
				'privacy'  => 'https://x.com/privacy',
				'purpose'  => [
					'lt' => 'Įterpti „X“ įrašai ir dalijimosi mygtukai.',
					'en' => 'Embedded X posts and share buttons.',
					'ru' => 'Встроенные записи X и кнопки «поделиться».',
				],
				'cookies'  => [
					[ 'name' => 'guest_id', 'duration' => '2y' ],
					[ 'name' => 'personalization_id', 'duration' => '2y' ],
				],
			],
			'tiktok'      => [
				'name'     => 'TikTok',
				'provider' => 'TikTok Technology Ltd.',
				'category' => 'marketing',
				'embed'    => true,
				'url'      => [ 'tiktok.com/embed', 'analytics.tiktok.com' ],
				'privacy'  => 'https://www.tiktok.com/legal/privacy-policy',
				'purpose'  => [
					'lt' => 'Įterpti „TikTok“ vaizdo įrašai ir reklamos matavimas.',
					'en' => 'Embedded TikTok video and ad measurement.',
					'ru' => 'Встроенные видео TikTok и измерение рекламы.',
				],
				'cookies'  => [
					[ 'name' => '_ttp', 'duration' => '13m' ],
					[ 'name' => 'tt_csrf_token', 'duration' => 'session' ],
				],
			],
			'linkedin'    => [
				'name'     => 'LinkedIn Insight',
				'provider' => 'LinkedIn Ireland Ltd.',
				'category' => 'marketing',
				'url'      => [ 'snap.licdn.com', 'platform.linkedin.com' ],
				'privacy'  => 'https://www.linkedin.com/legal/privacy-policy',
				'purpose'  => [
					'lt' => 'Reklamos matavimas ir auditorijų sudarymas „LinkedIn“.',
					'en' => 'Ad measurement and audience building on LinkedIn.',
					'ru' => 'Измерение рекламы и формирование аудиторий в LinkedIn.',
				],
				'cookies'  => [
					[ 'name' => 'li_sugr', 'duration' => '3m' ],
					[ 'name' => 'bcookie', 'duration' => '1y' ],
				],
			],
			'mailchimp'   => [
				'name'     => 'Mailchimp',
				'provider' => 'Intuit Inc.',
				'category' => 'marketing',
				'url'      => [ 'chimpstatic.com', 'list-manage.com' ],
				'privacy'  => 'https://www.intuit.com/privacy/statement/',
				'purpose'  => [
					'lt' => 'Naujienlaiškio prenumeratos forma ir jos matavimas.',
					'en' => 'Newsletter sign-up form and its measurement.',
					'ru' => 'Форма подписки на рассылку и её измерение.',
				],
				'cookies'  => [
					[ 'name' => '_mcid', 'duration' => '1y' ],
				],
			],
			'disqus'      => [
				'name'     => 'Disqus',
				'provider' => 'Disqus Inc.',
				'category' => 'functional',
				'embed'    => true,
				'url'      => [ 'disqus.com/embed', 'disquscdn.com' ],
				'privacy'  => 'https://disqus.com/privacy-policy/',
				'purpose'  => [
					'lt' => 'Įterpta komentarų sistema.',
					'en' => 'Embedded commenting system.',
					'ru' => 'Встроенная система комментариев.',
				],
				'cookies'  => [
					[ 'name' => 'disqus_unique', 'duration' => '1y' ],
				],
			],
		];

		/**
		 * Filter: add or change known services.
		 *
		 * @param array $s
		 */
		return (array) apply_filters( 'aicc_services', $s );
	}

	public static function service( string $key ): ?array {
		return self::services()[ $key ] ?? null;
	}

	/** Which service does this script/iframe URL belong to? */
	public static function match_url( string $url ): ?string {
		$url = strtolower( $url );
		foreach ( self::services() as $key => $svc ) {
			foreach ( (array) ( $svc['url'] ?? [] ) as $needle ) {
				if ( str_contains( $url, strtolower( $needle ) ) ) {
					return $key;
				}
			}
		}

		return null;
	}

	/** Which service does this inline script body belong to? */
	public static function match_inline( string $code ): ?string {
		foreach ( self::services() as $key => $svc ) {
			foreach ( (array) ( $svc['inline'] ?? [] ) as $needle ) {
				if ( str_contains( $code, $needle ) ) {
					return $key;
				}
			}
		}

		return null;
	}

	/** Flat list of every cookie definition shipped with the plugin. */
	public static function known_cookies(): array {
		$out = [];
		foreach ( self::services() as $key => $svc ) {
			foreach ( (array) ( $svc['cookies'] ?? [] ) as $cookie ) {
				$out[] = [
					'name'     => $cookie['name'],
					'service'  => $key,
					'provider' => $svc['provider'],
					'category' => $svc['category'],
					'duration' => $cookie['duration'] ?? 'session',
					'storage'  => $cookie['storage'] ?? 'cookie',
				];
			}
		}

		return $out;
	}

	/** Match a raw cookie name against a definition that may contain a * wildcard. */
	public static function match_known( string $name ): ?array {
		foreach ( self::known_cookies() as $def ) {
			$pattern = '/^' . str_replace( '\*', '.*', preg_quote( $def['name'], '/' ) ) . '$/i';
			if ( preg_match( $pattern, $name ) ) {
				return $def;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Stored cookie list                                                  */
	/* ------------------------------------------------------------------ */

	public static function stored(): array {
		if ( self::$stored_cache !== null ) {
			return self::$stored_cache;
		}

		$list = get_option( self::COOKIES_OPTION, [] );

		self::$stored_cache = is_array( $list ) ? self::sanitize_list( $list ) : [];

		return self::$stored_cache;
	}

	public static function save( array $list ): void {
		self::$stored_cache = self::sanitize_list( $list );
		update_option( self::COOKIES_OPTION, self::$stored_cache );
	}

	/** Treat the option as untrusted: other plugins and imports can write it directly. */
	private static function sanitize_list( array $list ): array {
		$out      = [];
		$seen     = [];
		$services = array_keys( self::services() );

		foreach ( array_slice( $list, 0, 5000 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = self::clean_text( $row['name'] ?? '', 120 );
			if ( $name === '' ) {
				continue;
			}

			$storage = ( $row['storage'] ?? 'cookie' ) === 'local' ? 'local' : 'cookie';
			$key     = $storage . "\0" . strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$service = sanitize_key( is_scalar( $row['service'] ?? '' ) ? (string) $row['service'] : '' );
			if ( $service !== '' && ! in_array( $service, $services, true ) ) {
				$service = '';
			}

			$purpose = is_array( $row['purpose'] ?? null ) ? $row['purpose'] : [];
			$source  = sanitize_key( is_scalar( $row['source'] ?? '' ) ? (string) $row['source'] : '' );
			$source  = in_array( $source, [ 'manual', 'scan', 'known' ], true ) ? $source : 'manual';

			$out[] = [
				'name'       => $name,
				'service'    => $service,
				'provider'   => self::clean_text( $row['provider'] ?? '', 200 ),
				'category'   => Category::from_slug( sanitize_key( is_scalar( $row['category'] ?? '' ) ? (string) $row['category'] : '' ) )->value,
				'duration'   => self::clean_text( $row['duration'] ?? 'session', 40 ) ?: 'session',
				'storage'    => $storage,
				'purpose'    => [
					'lt' => self::clean_textarea( $purpose['lt'] ?? '', 1000 ),
					'en' => self::clean_textarea( $purpose['en'] ?? '', 1000 ),
					'ru' => self::clean_textarea( $purpose['ru'] ?? '', 1000 ),
				],
				'source'     => $source,
				'first_seen' => absint( is_scalar( $row['first_seen'] ?? null ) ? $row['first_seen'] : time() ),
				'last_seen'  => absint( is_scalar( $row['last_seen'] ?? null ) ? $row['last_seen'] : time() ),
			];
		}

		return $out;
	}

	private static function clean_text( mixed $value, int $limit ): string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return self::limit( $value, $limit );
	}

	private static function clean_textarea( mixed $value, int $limit ): string {
		$value = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';

		return self::limit( $value, $limit );
	}

	private static function limit( string $value, int $bytes ): string {
		return function_exists( 'mb_strcut' ) ? mb_strcut( $value, 0, $bytes, 'UTF-8' ) : substr( $value, 0, $bytes );
	}

	public static function blank_entry( string $name ): array {
		return [
			'name'       => $name,
			'service'    => '',
			'provider'   => '',
			'category'   => Category::Unknown->value,
			'duration'   => 'session',
			'storage'    => 'cookie',
			'purpose'    => [ 'lt' => '', 'en' => '', 'ru' => '' ],
			'source'     => 'manual',
			'first_seen' => time(),
			'last_seen'  => time(),
		];
	}

	/**
	 * Add or refresh entries. Returns the names that were genuinely new.
	 *
	 * @param array<int,array{name:string,storage?:string}> $found
	 */
	public static function record( array $found, string $source ): array {
		$list   = self::stored();
		$source = sanitize_key( $source );
		$source = in_array( $source, [ 'manual', 'scan', 'known' ], true ) ? $source : 'scan';
		$index  = [];
		foreach ( $list as $i => $row ) {
			$index[ (string) ( $row['storage'] ?? 'cookie' ) . "\0" . strtolower( $row['name'] ) ] = $i;
		}

		$new = [];
		foreach ( $found as $item ) {
			$name = self::clean_text( is_array( $item ) ? ( $item['name'] ?? '' ) : $item, 120 );
			if ( $name === '' ) {
				continue;
			}
			$storage = is_array( $item ) && ( $item['storage'] ?? 'cookie' ) === 'local' ? 'local' : 'cookie';
			$key     = $storage . "\0" . strtolower( $name );

			if ( isset( $index[ $key ] ) ) {
				$list[ $index[ $key ] ]['last_seen'] = time();
				continue;
			}

			$entry            = self::blank_entry( $name );
			$entry['source']  = $source;
			$entry['storage'] = $storage;

			$known = self::match_known( $name );
			if ( $known !== null ) {
				$svc                 = self::service( $known['service'] );
				$entry['service']    = $known['service'];
				$entry['provider']   = $known['provider'];
				$entry['category']   = $known['category'];
				$entry['duration']   = $known['duration'];
				$entry['storage']    = $known['storage'];
				$entry['purpose']    = $svc['purpose'] ?? $entry['purpose'];
				$entry['source']     = $source === 'manual' ? 'manual' : 'known';
			}

			$list[]                          = $entry;
			$index[ $key ]                   = count( $list ) - 1;
			$new[]                           = $entry;
		}

		self::save( $list );

		return $new;
	}

	/** Register every cookie belonging to a detected service. */
	public static function record_service( string $serviceKey ): array {
		$svc = self::service( $serviceKey );
		if ( $svc === null ) {
			return [];
		}

		$items = [];
		foreach ( (array) ( $svc['cookies'] ?? [] ) as $cookie ) {
			$items[] = [
				'name'    => $cookie['name'],
				'storage' => $cookie['storage'] ?? 'cookie',
			];
		}

		return self::record( $items, 'scan' );
	}

	/**
	 * Drop stored cookies whose service depends on a plugin that is not active.
	 * Returns the removed cookie names.
	 */
	public static function prune_inactive(): array {
		$active = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		$removed = [];
		$kept    = [];

		foreach ( self::stored() as $row ) {
			$svc = $row['service'] ? self::service( (string) $row['service'] ) : null;
			$req = (array) ( $svc['plugins'] ?? [] );

			if ( $svc !== null && $req !== [] && array_intersect( $req, $active ) === [] && ( $row['source'] ?? '' ) !== 'manual' ) {
				$removed[] = (string) $row['name'];
				continue;
			}

			$kept[] = $row;
		}

		if ( $removed !== [] ) {
			self::save( $kept );
		}

		return $removed;
	}

	/** Cookie list grouped by category, ready for display. */
	public static function grouped( string $lang ): array {
		$grouped = [];
		foreach ( Category::cases() as $cat ) {
			$grouped[ $cat->value ] = [];
		}

		foreach ( self::stored() as $row ) {
			$cat = Category::from_slug( (string) ( $row['category'] ?? 'unknown' ) );
			$svc = $row['service'] ? self::service( $row['service'] ) : null;

			$purpose = trim( (string) ( $row['purpose'][ $lang ] ?? '' ) );
			if ( $purpose === '' && $svc !== null ) {
				$purpose = (string) ( $svc['purpose'][ $lang ] ?? $svc['purpose']['lt'] ?? '' );
			}

			$grouped[ $cat->value ][] = [
				'name'     => (string) $row['name'],
				'provider' => (string) ( $row['provider'] ?: ( $svc['provider'] ?? '—' ) ),
				'service'  => (string) ( $svc['name'] ?? '' ),
				'purpose'  => $purpose,
				'duration' => I18n::duration( (string) ( $row['duration'] ?? 'session' ), $lang ),
				'storage'  => (string) ( $row['storage'] ?? 'cookie' ),
			];
		}

		foreach ( $grouped as $slug => $rows ) {
			usort( $rows, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
			$grouped[ $slug ] = $rows;
		}

		return $grouped;
	}

	/** Categories that actually have something to consent to. */
	public static function active_categories(): array {
		$grouped = [];
		foreach ( self::stored() as $row ) {
			$grouped[ (string) ( $row['category'] ?? 'unknown' ) ] = true;
		}

		$out = [];
		foreach ( Category::displayed() as $cat ) {
			if ( ! $cat->is_optional() || isset( $grouped[ $cat->value ] ) ) {
				$out[] = $cat;
			}
		}

		return $out;
	}
}
