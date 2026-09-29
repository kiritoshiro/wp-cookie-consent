<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum Category: string {

	case Necessary  = 'necessary';
	case Functional = 'functional';
	case Analytics  = 'analytics';
	case Marketing  = 'marketing';
	case Unknown    = 'unknown';

	/** Categories the visitor can switch on and off. */
	public static function optional(): array {
		return [ self::Functional, self::Analytics, self::Marketing ];
	}

	/** All categories shown in the settings panel, in display order. */
	public static function displayed(): array {
		return [ self::Necessary, self::Functional, self::Analytics, self::Marketing ];
	}

	public static function from_slug( string $slug ): self {
		return self::tryFrom( $slug ) ?? self::Unknown;
	}

	public function is_optional(): bool {
		return in_array( $this, self::optional(), true );
	}

	public function label( string $lang ): string {
		return match ( $this ) {
			self::Necessary  => match ( $lang ) {
				'en'    => 'Strictly necessary',
				'ru'    => 'Необходимые',
				default => 'Būtinieji',
			},
			self::Functional => match ( $lang ) {
				'en'    => 'Functional',
				'ru'    => 'Функциональные',
				default => 'Funkciniai',
			},
			self::Analytics  => match ( $lang ) {
				'en'    => 'Analytics',
				'ru'    => 'Аналитические',
				default => 'Analitiniai',
			},
			self::Marketing  => match ( $lang ) {
				'en'    => 'Marketing',
				'ru'    => 'Маркетинговые',
				default => 'Rinkodaros',
			},
			self::Unknown    => match ( $lang ) {
				'en'    => 'Unclassified',
				'ru'    => 'Не классифицировано',
				default => 'Nepriskirti',
			},
		};
	}

	public function description( string $lang ): string {
		return match ( $this ) {
			self::Necessary  => match ( $lang ) {
				'en'    => 'Needed for the site to work: security, session handling, and remembering your cookie choice. They cannot be switched off and never identify you for advertising.',
				'ru'    => 'Нужны для работы сайта: безопасность, сессия и хранение вашего выбора о cookie. Их нельзя отключить, и они не используются для рекламы.',
				default => 'Reikalingi, kad svetainė veiktų: saugumui, seansui ir jūsų pasirinkimui dėl slapukų įsiminti. Jų išjungti negalima ir jie nenaudojami reklamai.',
			},
			self::Functional => match ( $lang ) {
				'en'    => 'Remember your preferences — language, font size, closed notices — and let embedded players such as YouTube or Spotify load.',
				'ru'    => 'Запоминают ваши настройки — язык, размер шрифта, закрытые уведомления — и позволяют загружать встроенные плееры, например YouTube или Spotify.',
				default => 'Įsimena jūsų pasirinkimus — kalbą, šrifto dydį, uždarytus pranešimus — ir leidžia užsikrauti įterptiems grotuvams, pvz., „YouTube“ ar „Spotify“.',
			},
			self::Analytics  => match ( $lang ) {
				'en'    => 'Help us count visits and see which pages are read, so we can improve the site. The data is aggregated and not used to contact you.',
				'ru'    => 'Помогают считать посещения и видеть, какие страницы читают, чтобы улучшать сайт. Данные обобщаются и не используются для связи с вами.',
				default => 'Padeda suskaičiuoti apsilankymus ir matyti, kurie puslapiai skaitomi, kad galėtume tobulinti svetainę. Duomenys apibendrinami ir nenaudojami su jumis susisiekti.',
			},
			self::Marketing  => match ( $lang ) {
				'en'    => 'Used by third parties to build an advertising profile and measure campaigns across sites. Off by default.',
				'ru'    => 'Используются третьими сторонами для рекламного профиля и оценки кампаний на разных сайтах. По умолчанию выключены.',
				default => 'Trečiųjų šalių naudojami reklamos profiliui sudaryti ir kampanijoms tarp svetainių matuoti. Pagal nutylėjimą išjungti.',
			},
			self::Unknown    => match ( $lang ) {
				'en'    => 'Found by the scanner but not yet assigned to a category.',
				'ru'    => 'Обнаружены сканером, но ещё не отнесены к категории.',
				default => 'Rasti skenuojant, bet dar nepriskirti kategorijai.',
			},
		};
	}
}
