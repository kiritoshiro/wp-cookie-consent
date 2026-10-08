<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Frontend {

	public function hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
		// Footer scripts are normally printed at priority 20. The root must exist first.
		add_action( 'wp_footer', [ $this, 'render' ], 5 );
		add_filter( 'script_loader_tag', [ $this, 'script_tag' ], 10, 2 );
		add_shortcode( 'aicc_cookie_policy', [ $this, 'shortcode_policy' ] );
		add_shortcode( 'aicc_cookie_settings', [ $this, 'shortcode_settings_link' ] );
	}

	/** Keep the consent controller out of script-delay and cookie auto-blocking tools. */
	public function script_tag( string $tag, string $handle ): string {
		if ( ! in_array( $handle, [ 'aicc', 'aicc-scanner' ], true ) || str_contains( $tag, 'data-aicc-ignore' ) ) {
			return $tag;
		}

		$original = $tag;
		$tag      = preg_replace(
			'/<script\b/',
			'<script data-aicc-ignore data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-nowprocket data-cookieconsent="ignore" data-cookieyes="ignore"',
			$tag,
			1
		);

		return is_string( $tag ) ? $tag : $original;
	}

	private function enabled(): bool {
		return (bool) Settings::get( 'enabled' ) && ! Scanner::is_deep_scan_request();
	}

	public function assets(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		wp_enqueue_style( 'aicc', AICC_URL . 'assets/css/banner.css', [], VERSION );
		// Deferred: the banner starts on DOMContentLoaded anyway, so it no longer
		// stops the browser from parsing the page. The data-no-defer attributes in
		// script_tag() still keep optimisation plugins from delaying it further.
		wp_enqueue_script( 'aicc', AICC_URL . 'assets/js/banner.js', [], VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );

		wp_localize_script( 'aicc', 'AICC', $this->config() );
	}

	/**
	 * Everything the browser needs. Printed twice on purpose: once via
	 * wp_localize_script, once as an attribute on the root element. Caching and
	 * optimisation plugins reorder, defer, concatenate and occasionally drop the
	 * inline `var AICC` block, and when that happened the whole consent UI went
	 * silent. The attribute travels with the markup, so it cannot be separated.
	 */
	private function config(): array {
		$strings = [];
		foreach ( I18n::LANGS as $code ) {
			$strings[ $code ] = I18n::bundle( $code );
		}

		$categories = [];
		foreach ( Registry::active_categories() as $cat ) {
			$labels = [];
			foreach ( I18n::LANGS as $code ) {
				$labels[ $code ] = [
					'label'       => $cat->label( $code ),
					'description' => $cat->description( $code ),
				];
			}
			$categories[] = [
				'slug'     => $cat->value,
				'optional' => $cat->is_optional(),
				'i18n'     => $labels,
			];
		}

		return [
			'lang'        => I18n::current(),
			'langs'       => I18n::lang_names(),
			'strings'     => $strings,
			'categories'  => $categories,
			'revision'    => (int) Settings::get( 'revision' ),
			'days'        => (int) Settings::get( 'consent_days' ),
			'switcher'    => (bool) Settings::get( 'lang_switcher' ) && ! $this->multilingual_site(),
			'floating'    => (string) Settings::get( 'floating_mode' ),
			'autoHide'    => (int) Settings::get( 'auto_hide' ) * 1000,
			'precheck'    => array_values( (array) Settings::get( 'precheck' ) ),
			'implied'     => array_values( (array) Settings::get( 'implied' ) ),
			'consentMode' => (bool) Settings::get( 'consent_mode' ),
			'log'         => (bool) Settings::get( 'log_consent' ),
			'endpoint'    => esc_url_raw( rest_url( 'aicc/v1/consent' ) ),
			'policyUrl'   => $this->policy_url(),
			'home'        => esc_url_raw( home_url( '/' ) ),
		];
	}

	private function multilingual_site(): bool {
		return function_exists( 'pll_current_language' ) || defined( 'ICL_LANGUAGE_CODE' );
	}

	private function policy_url(): string {
		$page = (int) Settings::get( 'policy_page' );

		return $page > 0 ? (string) get_permalink( $page ) : '';
	}

	/* ------------------------------------------------------------------ */
	/* Banner + settings panel                                             */
	/* ------------------------------------------------------------------ */

	public function render(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$lang     = I18n::current();
		$position = (string) Settings::get( 'position' );
		$theme    = (string) Settings::get( 'theme' );
		$accent   = (string) Settings::get( 'accent' );
		$policy   = $this->policy_url();
		?>
		<div id="aicc-root" class="aicc aicc--<?php echo esc_attr( $position ); ?> aicc--theme-<?php echo esc_attr( $theme ); ?>"
			style="--aicc-accent: <?php echo esc_attr( $accent ); ?>"
			data-aicc-config="<?php echo esc_attr( (string) wp_json_encode( $this->config() ) ); ?>" hidden>

			<section class="aicc-banner" role="dialog" aria-modal="false"
				aria-labelledby="aicc-banner-title" aria-describedby="aicc-banner-text" hidden>
				<h2 class="aicc-banner__title" id="aicc-banner-title" data-aicc-i18n="banner_title"><?php echo esc_html( I18n::t( 'banner_title', $lang ) ); ?></h2>
				<p class="aicc-banner__text" id="aicc-banner-text" data-aicc-i18n="banner_text"><?php echo esc_html( I18n::t( 'banner_text', $lang ) ); ?></p>

				<div class="aicc-banner__actions">
					<button type="button" class="aicc-btn aicc-btn--primary" data-aicc-action="accept" data-aicc-i18n="accept_all"><?php echo esc_html( I18n::t( 'accept_all', $lang ) ); ?></button>
					<button type="button" class="aicc-btn aicc-btn--primary aicc-btn--outline" data-aicc-action="reject" data-aicc-i18n="reject_all"><?php echo esc_html( I18n::t( 'reject_all', $lang ) ); ?></button>
					<button type="button" class="aicc-btn aicc-btn--ghost" data-aicc-action="open" data-aicc-i18n="customize"><?php echo esc_html( I18n::t( 'customize', $lang ) ); ?></button>
				</div>

				<div class="aicc-banner__foot">
					<?php if ( $policy !== '' ) : ?>
						<a class="aicc-link" href="<?php echo esc_url( $policy ); ?>" data-aicc-i18n="policy_link"><?php echo esc_html( I18n::t( 'policy_link', $lang ) ); ?></a>
					<?php endif; ?>
					<div class="aicc-langs" data-aicc-langs hidden></div>
				</div>
			</section>

			<div class="aicc-modal" data-aicc-modal hidden>
				<div class="aicc-modal__backdrop" data-aicc-action="close"></div>
				<div class="aicc-modal__panel" role="dialog" aria-modal="true" aria-labelledby="aicc-modal-title">
					<header class="aicc-modal__head">
						<h2 id="aicc-modal-title" data-aicc-i18n="modal_title"><?php echo esc_html( I18n::t( 'modal_title', $lang ) ); ?></h2>
						<button type="button" class="aicc-close" data-aicc-action="close" aria-label="<?php echo esc_attr( I18n::t( 'close', $lang ) ); ?>">&times;</button>
					</header>
					<p class="aicc-modal__intro" data-aicc-i18n="modal_intro"><?php echo esc_html( I18n::t( 'modal_intro', $lang ) ); ?></p>

					<div class="aicc-groups" data-aicc-groups></div>

					<footer class="aicc-modal__foot">
						<button type="button" class="aicc-btn aicc-btn--primary" data-aicc-action="save" data-aicc-i18n="save_choice"><?php echo esc_html( I18n::t( 'save_choice', $lang ) ); ?></button>
						<button type="button" class="aicc-btn aicc-btn--ghost" data-aicc-action="accept" data-aicc-i18n="accept_all"><?php echo esc_html( I18n::t( 'accept_all', $lang ) ); ?></button>
						<?php if ( $policy !== '' ) : ?>
							<a class="aicc-link aicc-modal__policy" href="<?php echo esc_url( $policy ); ?>" data-aicc-i18n="policy_link"><?php echo esc_html( I18n::t( 'policy_link', $lang ) ); ?></a>
						<?php endif; ?>
					</footer>
				</div>
			</div>

			<button type="button" class="aicc-fab" data-aicc-action="open" hidden>
				<span class="aicc-fab__icon" aria-hidden="true"></span>
				<span class="aicc-fab__label" data-aicc-i18n="reopen"><?php echo esc_html( I18n::t( 'reopen', $lang ) ); ?></span>
			</button>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Shortcodes                                                          */
	/* ------------------------------------------------------------------ */

	public function shortcode_settings_link( mixed $atts = [] ): string {
		$atts = shortcode_atts( [ 'text' => '', 'lang' => '' ], is_array( $atts ) ? $atts : [], 'aicc_cookie_settings' );
		$lang = I18n::current( $atts['lang'] !== '' ? $atts['lang'] : null );
		$text = $atts['text'] !== '' ? $atts['text'] : I18n::t( 'change_choice', $lang );

		return sprintf(
			'<button type="button" class="aicc-inline-btn" data-aicc-action="open">%s</button>',
			esc_html( $text )
		);
	}

	public function shortcode_policy( mixed $atts = [] ): string {
		$atts = shortcode_atts( [ 'lang' => '' ], is_array( $atts ) ? $atts : [], 'aicc_cookie_policy' );
		$lang = I18n::current( $atts['lang'] !== '' ? $atts['lang'] : null );

		$grouped = Registry::grouped( $lang );
		$state   = Scanner::state();

		ob_start();
		?>
		<div class="aicc-policy" style="--aicc-accent: <?php echo esc_attr( (string) Settings::get( 'accent' ) ); ?>">

			<?php if ( Settings::get( 'lang_switcher' ) && ! $this->multilingual_site() ) : ?>
				<nav class="aicc-policy__langs" aria-label="<?php echo esc_attr( I18n::t( 'lang_label', $lang ) ); ?>">
					<?php foreach ( I18n::lang_names() as $code => $name ) : ?>
						<a class="aicc-policy__lang<?php echo $code === $lang ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( add_query_arg( 'aicc_lang', $code, get_permalink() ) ); ?>"><?php echo esc_html( $name ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<p class="aicc-policy__intro"><?php echo esc_html( I18n::t( 'policy_intro', $lang ) ); ?></p>

			<div class="aicc-policy__state">
				<h3><?php echo esc_html( I18n::t( 'policy_your_state', $lang ) ); ?></h3>
				<p data-aicc-status><?php echo esc_html( I18n::t( 'status_none', $lang ) ); ?></p>
				<p>
					<button type="button" class="aicc-btn aicc-btn--primary" data-aicc-action="open"><?php echo esc_html( I18n::t( 'change_choice', $lang ) ); ?></button>
					<button type="button" class="aicc-btn aicc-btn--ghost" data-aicc-action="reject"><?php echo esc_html( I18n::t( 'withdraw', $lang ) ); ?></button>
				</p>
			</div>

			<?php foreach ( Category::displayed() as $cat ) : ?>
				<?php $rows = $grouped[ $cat->value ] ?? []; ?>
				<section class="aicc-policy__group">
					<h3><?php echo esc_html( $cat->label( $lang ) ); ?></h3>
					<p><?php echo esc_html( $cat->description( $lang ) ); ?></p>

					<?php if ( $rows === [] ) : ?>
						<p class="aicc-policy__empty"><?php echo esc_html( I18n::t( 'no_cookies', $lang ) ); ?></p>
					<?php else : ?>
						<div class="aicc-policy__tablewrap">
							<table class="aicc-policy__table">
								<thead>
									<tr>
										<th scope="col"><?php echo esc_html( I18n::t( 'col_name', $lang ) ); ?></th>
										<th scope="col"><?php echo esc_html( I18n::t( 'col_provider', $lang ) ); ?></th>
										<th scope="col"><?php echo esc_html( I18n::t( 'col_purpose', $lang ) ); ?></th>
										<th scope="col"><?php echo esc_html( I18n::t( 'col_duration', $lang ) ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $rows as $row ) : ?>
										<tr>
											<td><code><?php echo esc_html( $row['name'] ); ?></code>
												<?php if ( $row['storage'] === 'local' ) : ?>
													<small>(<?php echo esc_html( I18n::t( 'local_storage', $lang ) ); ?>)</small>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( $row['provider'] ); ?><?php echo $row['service'] !== '' ? '<br><small>' . esc_html( $row['service'] ) . '</small>' : ''; ?></td>
											<td><?php echo esc_html( $row['purpose'] ); ?></td>
											<td><?php echo esc_html( $row['duration'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<?php if ( (int) $state['last_run'] > 0 ) : ?>
				<p class="aicc-policy__stamp">
					<?php
					echo esc_html(
						sprintf(
							I18n::t( 'policy_updated', $lang ),
							wp_date( 'Y-m-d', (int) $state['last_run'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
