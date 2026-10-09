<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class I18n {

	public const LANGS = [ 'lt', 'en', 'ru' ];

	public static function lang_names(): array {
		return [
			'lt' => 'Lietuvių',
			'en' => 'English',
			'ru' => 'Русский',
		];
	}

	/**
	 * Resolve the language for the current request.
	 * Order: explicit override -> multilingual plugin -> site locale -> default setting.
	 */
	public static function current( ?string $override = null ): string {
		if ( $override !== null && in_array( $override, self::LANGS, true ) ) {
			return $override;
		}

		// Read-only language preference, checked against LANGS below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['aicc_lang'] ) && is_string( $_GET['aicc_lang'] ) ? sanitize_key( wp_unslash( $_GET['aicc_lang'] ) ) : '';
		if ( in_array( $requested, self::LANGS, true ) ) {
			return $requested;
		}

		$locale = '';
		if ( function_exists( 'pll_current_language' ) ) {
			$locale = (string) \pll_current_language( 'slug' );
		} elseif ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			$locale = (string) ICL_LANGUAGE_CODE;
		}

		if ( $locale === '' ) {
			$locale = determine_locale();
		}

		$short = strtolower( substr( $locale, 0, 2 ) );
		if ( in_array( $short, self::LANGS, true ) ) {
			return $short;
		}

		$default = Settings::get( 'default_lang' );

		return in_array( $default, self::LANGS, true ) ? $default : 'lt';
	}

	public static function t( string $key, string $lang ): string {
		$strings = self::strings();
		$lang    = in_array( $lang, self::LANGS, true ) ? $lang : 'lt';

		return $strings[ $key ][ $lang ] ?? $strings[ $key ]['lt'] ?? $key;
	}

	/** All frontend strings for one language — handed to JavaScript. */
	public static function bundle( string $lang ): array {
		$out = [];
		foreach ( self::strings() as $key => $set ) {
			$out[ $key ] = $set[ $lang ] ?? $set['lt'];
		}

		return $out;
	}

	public static function strings(): array {
		return [
			'banner_title'      => [
				'lt' => 'Gerbiame jūsų privatumą',
				'en' => 'We respect your privacy',
				'ru' => 'Мы уважаем вашу конфиденциальность',
			],
			'banner_text'       => [
				'lt' => 'Šiuo metu veikia tik būtinieji slapukai. Analitikos ir įterpto turinio slapukus įjungsime tik jums sutikus.',
				'en' => 'Only strictly necessary cookies are active right now. Analytics and embedded content load only if you agree.',
				'ru' => 'Сейчас работают только необходимые файлы cookie. Аналитика и встроенный контент загрузятся только с вашего согласия.',
			],
			'banner_text_implied' => [
				'lt' => 'Naudojame būtinuosius ir funkcinius slapukus, kad veiktų įterpti vaizdo įrašai ir grotuvai. Analitikos bei rinkodaros slapukai neįjungti. Savo pasirinkimą galite pakeisti bet kada.',
				'en' => 'We use necessary and functional cookies so embedded video and players work. Analytics and marketing cookies are off. You can change this at any time.',
				'ru' => 'Мы используем необходимые и функциональные файлы cookie, чтобы работали встроенные видео и плееры. Аналитические и маркетинговые отключены. Вы можете изменить выбор в любое время.',
			],
			'accept_all'        => [
				'lt' => 'Sutikti su visais',
				'en' => 'Accept all',
				'ru' => 'Принять все',
			],
			'reject_all'        => [
				'lt' => 'Tik būtinieji',
				'en' => 'Necessary only',
				'ru' => 'Только необходимые',
			],
			'customize'         => [
				'lt' => 'Pasirinkti',
				'en' => 'Choose',
				'ru' => 'Настроить',
			],
			'save_choice'       => [
				'lt' => 'Išsaugoti pasirinkimą',
				'en' => 'Save my choice',
				'ru' => 'Сохранить выбор',
			],
			'modal_title'       => [
				'lt' => 'Slapukų nustatymai',
				'en' => 'Cookie settings',
				'ru' => 'Настройки cookie',
			],
			'modal_intro'       => [
				'lt' => 'Įjunkite tik tas slapukų grupes, kurias norite leisti. Pasirinkimą galite pakeisti bet kada.',
				'en' => 'Switch on only the groups you want to allow. You can change this at any time.',
				'ru' => 'Включите только те группы, которые хотите разрешить. Изменить выбор можно в любое время.',
			],
			'always_on'         => [
				'lt' => 'Visada įjungta',
				'en' => 'Always on',
				'ru' => 'Всегда включены',
			],
			'close'             => [
				'lt' => 'Uždaryti',
				'en' => 'Close',
				'ru' => 'Закрыть',
			],
			'policy_link'       => [
				'lt' => 'Slapukų politika',
				'en' => 'Cookie policy',
				'ru' => 'Политика cookie',
			],
			'reopen'            => [
				'lt' => 'Slapukai',
				'en' => 'Cookies',
				'ru' => 'Cookie',
			],
			'cookies_used'      => [
				'lt' => 'Naudojami slapukai',
				'en' => 'Cookies used',
				'ru' => 'Используемые файлы cookie',
			],
			'no_cookies'        => [
				'lt' => 'Ši grupė šiuo metu nenaudoja jokių slapukų.',
				'en' => 'This group currently uses no cookies.',
				'ru' => 'В этой группе сейчас нет файлов cookie.',
			],
			'col_name'          => [
				'lt' => 'Pavadinimas',
				'en' => 'Name',
				'ru' => 'Название',
			],
			'col_provider'      => [
				'lt' => 'Tiekėjas',
				'en' => 'Provider',
				'ru' => 'Поставщик',
			],
			'col_purpose'       => [
				'lt' => 'Paskirtis',
				'en' => 'Purpose',
				'ru' => 'Назначение',
			],
			'col_duration'      => [
				'lt' => 'Galiojimas',
				'en' => 'Retention',
				'ru' => 'Срок хранения',
			],
			'col_type'          => [
				'lt' => 'Tipas',
				'en' => 'Type',
				'ru' => 'Тип',
			],
			'embed_title'       => [
				'lt' => 'Turinys užblokuotas',
				'en' => 'Content is blocked',
				'ru' => 'Контент заблокирован',
			],
			'embed_text'        => [
				'lt' => 'Kad būtų parodytas šis įterptas turinys (%1$s), reikia jūsų sutikimo su grupe „%2$s“.',
				'en' => 'To show this embedded content (%1$s) we need your consent for the “%2$s” group.',
				'ru' => 'Чтобы показать этот встроенный контент (%1$s), нужно ваше согласие на группу «%2$s».',
			],
			'embed_button'      => [
				'lt' => 'Rodyti turinį',
				'en' => 'Show content',
				'ru' => 'Показать контент',
			],
			'embed_once'        => [
				'lt' => 'Rodyti tik šį kartą',
				'en' => 'Show this time only',
				'ru' => 'Показать только сейчас',
			],
			'status_none'       => [
				'lt' => 'Jūs dar nepasirinkote. Veikia tik būtinieji slapukai.',
				'en' => 'You have not chosen yet. Only necessary cookies are active.',
				'ru' => 'Вы ещё не выбрали. Работают только необходимые cookie.',
			],
			'status_saved'      => [
				'lt' => 'Jūsų pasirinkimas išsaugotas %s.',
				'en' => 'Your choice was saved on %s.',
				'ru' => 'Ваш выбор сохранён %s.',
			],
			'status_gpc'        => [
				'lt' => 'Pritaikytas jūsų naršyklės privatumo signalas (Global Privacy Control).',
				'en' => 'Your browser\'s privacy signal (Global Privacy Control) was applied.',
				'ru' => 'Применён сигнал конфиденциальности вашего браузера (Global Privacy Control).',
			],
			'status_allowed'    => [
				'lt' => 'Leidžiama:',
				'en' => 'Allowed:',
				'ru' => 'Разрешено:',
			],
			'status_only_nec'   => [
				'lt' => 'tik būtinieji',
				'en' => 'necessary only',
				'ru' => 'только необходимые',
			],
			'change_choice'     => [
				'lt' => 'Keisti pasirinkimą',
				'en' => 'Change my choice',
				'ru' => 'Изменить выбор',
			],
			'withdraw'          => [
				'lt' => 'Atšaukti sutikimą',
				'en' => 'Withdraw consent',
				'ru' => 'Отозвать согласие',
			],
			'policy_intro'      => [
				'lt' => 'Slapukai — tai nedideli tekstiniai failai, kuriuos svetainė įrašo jūsų naršyklėje. Šiame puslapyje surašyti visi slapukai, kuriuos naudoja ši svetainė. Sąrašas atnaujinamas automatiškai — svetainė reguliariai nuskenuojama.',
				'en' => 'Cookies are small text files a website stores in your browser. This page lists every cookie this site uses. The list is kept up to date automatically — the site is scanned on a regular schedule.',
				'ru' => 'Файлы cookie — это небольшие текстовые файлы, которые сайт сохраняет в вашем браузере. На этой странице перечислены все cookie, используемые сайтом. Список обновляется автоматически — сайт регулярно сканируется.',
			],
			'policy_updated'    => [
				'lt' => 'Sąrašas paskutinį kartą tikrintas: %s',
				'en' => 'List last verified: %s',
				'ru' => 'Список последний раз проверен: %s',
			],
			'policy_your_state' => [
				'lt' => 'Jūsų dabartinis pasirinkimas',
				'en' => 'Your current choice',
				'ru' => 'Ваш текущий выбор',
			],
			'session'           => [
				'lt' => 'Seansas',
				'en' => 'Session',
				'ru' => 'Сессия',
			],
			'persistent'        => [
				'lt' => 'Neribotas',
				'en' => 'Persistent',
				'ru' => 'Бессрочно',
			],
			'local_storage'     => [
				'lt' => 'Vietinė saugykla',
				'en' => 'Local storage',
				'ru' => 'Локальное хранилище',
			],
			'lang_label'        => [
				'lt' => 'Kalba',
				'en' => 'Language',
				'ru' => 'Язык',
			],
		];
	}

	/**
	 * Turn a compact duration spec (2y, 13m, 30d, 24h, 1min, session, persistent, ls)
	 * into a human string with correct Lithuanian/Russian plural forms.
	 */
	public static function duration( string $spec, string $lang ): string {
		$spec = strtolower( trim( $spec ) );

		if ( $spec === '' || $spec === 'session' ) {
			return self::t( 'session', $lang );
		}
		if ( $spec === 'persistent' ) {
			return self::t( 'persistent', $lang );
		}
		if ( $spec === 'ls' ) {
			return self::t( 'local_storage', $lang );
		}

		if ( ! preg_match( '/^(\d+)\s*(min|h|d|m|y)$/', $spec, $m ) ) {
			return $spec;
		}

		$n     = (int) $m[1];
		$unit  = $m[2];
		$forms = match ( $unit ) {
			'min' => [
				'lt' => [ 'minutė', 'minutės', 'minučių' ],
				'en' => [ 'minute', 'minutes', 'minutes' ],
				'ru' => [ 'минута', 'минуты', 'минут' ],
			],
			'h'   => [
				'lt' => [ 'valanda', 'valandos', 'valandų' ],
				'en' => [ 'hour', 'hours', 'hours' ],
				'ru' => [ 'час', 'часа', 'часов' ],
			],
			'd'   => [
				'lt' => [ 'diena', 'dienos', 'dienų' ],
				'en' => [ 'day', 'days', 'days' ],
				'ru' => [ 'день', 'дня', 'дней' ],
			],
			'm'   => [
				'lt' => [ 'mėnuo', 'mėnesiai', 'mėnesių' ],
				'en' => [ 'month', 'months', 'months' ],
				'ru' => [ 'месяц', 'месяца', 'месяцев' ],
			],
			'y'   => [
				'lt' => [ 'metai', 'metai', 'metų' ],
				'en' => [ 'year', 'years', 'years' ],
				'ru' => [ 'год', 'года', 'лет' ],
			],
		};

		return $n . ' ' . self::plural( $n, $forms[ $lang ] ?? $forms['lt'], $lang );
	}

	private static function plural( int $n, array $forms, string $lang ): string {
		$mod10  = $n % 10;
		$mod100 = $n % 100;

		return match ( $lang ) {
			'lt'    => match ( true ) {
				$mod10 === 1 && $mod100 !== 11                             => $forms[0],
				$mod10 >= 2 && $mod10 <= 9 && ( $mod100 < 11 || $mod100 > 19 ) => $forms[1],
				default                                                    => $forms[2],
			},
			'ru'    => match ( true ) {
				$mod10 === 1 && $mod100 !== 11                             => $forms[0],
				$mod10 >= 2 && $mod10 <= 4 && ( $mod100 < 12 || $mod100 > 14 ) => $forms[1],
				default                                                    => $forms[2],
			},
			default => $n === 1 ? $forms[0] : $forms[1],
		};
	}
}
