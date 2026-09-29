<?php
declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	private const SLUG = 'aicc';

	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		add_action( 'admin_post_aicc_save_cookies', [ $this, 'handle_save_cookies' ] );
		add_action( 'admin_post_aicc_scan_now', [ $this, 'handle_scan_now' ] );
		add_action( 'admin_post_aicc_export_log', [ $this, 'handle_export_log' ] );
	}

	public function menu(): void {
		add_menu_page(
			__( 'Slapukų sutikimas', 'aicc' ),
			__( 'Slapukai', 'aicc' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-privacy',
			80
		);
	}

	public function register(): void {
		register_setting(
			'aicc_settings_group',
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::defaults(),
			]
		);
	}

	public function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$unknown = 0;
		foreach ( Registry::stored() as $row ) {
			if ( ( $row['category'] ?? '' ) === Category::Unknown->value ) {
				++$unknown;
			}
		}

		if ( $unknown === 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of cookies */
					_n(
						'Skeneris rado %d dar nepriskirtą slapuką.',
						'Skeneris rado %d dar nepriskirtų slapukų.',
						$unknown,
						'aicc'
					),
					$unknown
				)
			),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=cookies' ) ),
			esc_html__( 'Priskirti kategorijas', 'aicc' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public function handle_scan_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Neturite teisių.', 'aicc' ) );
		}
		check_admin_referer( 'aicc_scan_now' );

		( new Scanner() )->run();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=cookies&scanned=1' ) );
		exit;
	}

	public function handle_save_cookies(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Neturite teisių.', 'aicc' ) );
		}
		check_admin_referer( 'aicc_save_cookies' );

		$rows = isset( $_POST['cookie'] ) && is_array( $_POST['cookie'] ) ? array_slice( wp_unslash( $_POST['cookie'] ), 0, 5000 ) : [];
		$out  = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}
			$name = sanitize_text_field( self::scalar( $row['name'] ?? '' ) );
			if ( $name === '' ) {
				continue;
			}
			$purpose = is_array( $row['purpose'] ?? null ) ? $row['purpose'] : [];

			$out[] = [
				'name'       => $name,
				'service'    => sanitize_key( self::scalar( $row['service'] ?? '' ) ),
				'provider'   => sanitize_text_field( self::scalar( $row['provider'] ?? '' ) ),
				'category'   => Category::from_slug( sanitize_key( self::scalar( $row['category'] ?? '' ) ) )->value,
				'duration'   => sanitize_text_field( self::scalar( $row['duration'] ?? 'session', 'session' ) ),
				'storage'    => ( $row['storage'] ?? 'cookie' ) === 'local' ? 'local' : 'cookie',
				'purpose'    => [
					'lt' => sanitize_textarea_field( self::scalar( $purpose['lt'] ?? '' ) ),
					'en' => sanitize_textarea_field( self::scalar( $purpose['en'] ?? '' ) ),
					'ru' => sanitize_textarea_field( self::scalar( $purpose['ru'] ?? '' ) ),
				],
				'source'     => sanitize_key( self::scalar( $row['source'] ?? 'manual', 'manual' ) ),
				'first_seen' => absint( self::scalar( $row['first_seen'] ?? time(), (string) time() ) ),
				'last_seen'  => absint( self::scalar( $row['last_seen'] ?? time(), (string) time() ) ),
			];
		}

		Registry::save( $out );

		if ( ! empty( $_POST['bump_revision'] ) ) {
			Settings::bump_revision();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=cookies&saved=1' ) );
		exit;
	}

	public function handle_export_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Neturite teisių.', 'aicc' ) );
		}
		check_admin_referer( 'aicc_export_log' );

		global $wpdb;
		$table = $wpdb->prefix . 'aicc_consents';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=slapuku-sutikimai-' . gmdate( 'Y-m-d' ) . '.csv' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		if ( $out === false ) {
			wp_die( esc_html__( 'Nepavyko sukurti eksporto.', 'aicc' ) );
		}
		fputcsv( $out, [ 'id', 'consent_id', 'categories', 'lang', 'revision', 'url', 'ip_hash', 'ua_hash', 'created_at' ], ',', '"', '' );

		$batch_size = 1000;
		$last_id    = PHP_INT_MAX;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, consent_id, categories, lang, revision, source_url, ip_hash, ua_hash, created_at FROM %i WHERE id < %d ORDER BY id DESC LIMIT %d',
					$table,
					$last_id,
					$batch_size
				),
				ARRAY_A
			) ?: [];

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				// A leading =, +, - or @ turns a cell into a formula in Excel and Sheets.
				$row = array_map(
					static function ( mixed $value ): string {
						$value = (string) $value;

						return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
					},
					$row
				);
				fputcsv( $out, $row, ',', '"', '' );
			}
		} while ( count( $rows ) === $batch_size );

		if ( function_exists( 'flush' ) ) {
			flush();
		}
		fclose( $out );
		exit;
	}

	private static function scalar( mixed $value, string $default = '' ): string {
		return is_scalar( $value ) ? (string) $value : $default;
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	public function render(): void {
		$raw_tab = $_GET['tab'] ?? 'settings';
		$tab     = is_string( $raw_tab ) ? sanitize_key( wp_unslash( $raw_tab ) ) : 'settings';
		$tabs    = [
			'settings' => __( 'Nustatymai', 'aicc' ),
			'cookies'  => __( 'Slapukai ir skenavimas', 'aicc' ),
			'log'      => __( 'Sutikimų žurnalas', 'aicc' ),
			'help'     => __( 'Kaip naudoti', 'aicc' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Slapukų sutikimas', 'aicc' ); ?> <span style="font-size:13px;color:#666;">adventistai</span></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab<?php echo $tab === $slug ? ' nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			match ( $tab ) {
				'cookies' => $this->tab_cookies(),
				'log'     => $this->tab_log(),
				'help'    => $this->tab_help(),
				default   => $this->tab_settings(),
			};
			?>
		</div>
		<?php
	}

	private function tab_settings(): void {
		$s = Settings::all();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'aicc_settings_group' ); ?>
			<?php $name = Settings::OPTION; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Įjungta', 'aicc' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Rodyti sutikimo juostą ir blokuoti sekimo scenarijus', 'aicc' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="aicc-lang"><?php esc_html_e( 'Numatytoji kalba', 'aicc' ); ?></label></th>
					<td>
						<select id="aicc-lang" name="<?php echo esc_attr( $name ); ?>[default_lang]">
							<?php foreach ( I18n::lang_names() as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['default_lang'], $code ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Naudojama, kai svetainės kalba nėra lietuvių, anglų ar rusų.', 'aicc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kalbų perjungiklis', 'aicc' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[lang_switcher]" value="1" <?php checked( $s['lang_switcher'] ); ?>> <?php esc_html_e( 'Rodyti LT / EN / RU pasirinkimą juostoje ir politikos puslapyje', 'aicc' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="aicc-pos"><?php esc_html_e( 'Padėtis', 'aicc' ); ?></label></th>
					<td>
						<select id="aicc-pos" name="<?php echo esc_attr( $name ); ?>[position]">
							<option value="bottom-left" <?php selected( $s['position'], 'bottom-left' ); ?>><?php esc_html_e( 'Kortelė apačioje kairėje (mažiausiai įkyru)', 'aicc' ); ?></option>
							<option value="bottom-right" <?php selected( $s['position'], 'bottom-right' ); ?>><?php esc_html_e( 'Kortelė apačioje dešinėje', 'aicc' ); ?></option>
							<option value="bottom-bar" <?php selected( $s['position'], 'bottom-bar' ); ?>><?php esc_html_e( 'Juosta per visą apačią', 'aicc' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicc-theme"><?php esc_html_e( 'Tema', 'aicc' ); ?></label></th>
					<td>
						<select id="aicc-theme" name="<?php echo esc_attr( $name ); ?>[theme]">
							<option value="auto" <?php selected( $s['theme'], 'auto' ); ?>><?php esc_html_e( 'Automatinė', 'aicc' ); ?></option>
							<option value="light" <?php selected( $s['theme'], 'light' ); ?>><?php esc_html_e( 'Šviesi', 'aicc' ); ?></option>
							<option value="dark" <?php selected( $s['theme'], 'dark' ); ?>><?php esc_html_e( 'Tamsi', 'aicc' ); ?></option>
						</select>
						<input type="text" class="regular-text" style="max-width:120px;margin-left:12px;" name="<?php echo esc_attr( $name ); ?>[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>" placeholder="#1f5c3d">
						<span class="description"><?php esc_html_e( 'akcento spalva', 'aicc' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicc-page"><?php esc_html_e( 'Slapukų politikos puslapis', 'aicc' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages(
							[
								'name'              => $name . '[policy_page]',
								'id'                => 'aicc-page',
								'selected'          => (int) $s['policy_page'],
								'show_option_none'  => __( '— nepasirinkta —', 'aicc' ),
								'option_none_value' => 0,
							]
						);
						?>
						<p class="description"><?php esc_html_e( 'Puslapyje turi būti trumpinys [aicc_cookie_policy].', 'aicc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicc-days"><?php esc_html_e( 'Sutikimo galiojimas', 'aicc' ); ?></label></th>
					<td>
						<input type="number" id="aicc-days" name="<?php echo esc_attr( $name ); ?>[consent_days]" value="<?php echo esc_attr( (string) $s['consent_days'] ); ?>" min="30" max="365" class="small-text"> <?php esc_html_e( 'd.', 'aicc' ); ?>
						<p class="description"><?php esc_html_e( 'Po tiek dienų klausiama iš naujo. Rekomenduojama 180.', 'aicc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Blokavimas', 'aicc' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[block_embeds]" value="1" <?php checked( $s['block_embeds'] ); ?>> <?php esc_html_e( 'Blokuoti įterptą turinį (YouTube, Vimeo, Facebook, žemėlapiai) iki sutikimo', 'aicc' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[consent_mode]" value="1" <?php checked( $s['consent_mode'] ); ?>> <?php esc_html_e( 'Naudoti „Google Consent Mode v2“ signalus', 'aicc' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sutikimų įrašai', 'aicc' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[log_consent]" value="1" <?php checked( $s['log_consent'] ); ?>> <?php esc_html_e( 'Kaupti sutikimo įrodymus (IP ir naršyklė saugomi tik maišos pavidalu)', 'aicc' ); ?></label><br>
						<label><?php esc_html_e( 'Saugoti', 'aicc' ); ?>
							<input type="number" name="<?php echo esc_attr( $name ); ?>[log_months]" value="<?php echo esc_attr( (string) $s['log_months'] ); ?>" min="6" max="60" class="small-text"> <?php esc_html_e( 'mėn., paskui įrašai ištrinami automatiškai', 'aicc' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicc-fab"><?php esc_html_e( 'Mygtukas kampe', 'aicc' ); ?></label></th>
					<td>
						<select id="aicc-fab" name="<?php echo esc_attr( $name ); ?>[floating_mode]">
							<option value="pending" <?php selected( $s['floating_mode'], 'pending' ); ?>><?php esc_html_e( 'Tik kol lankytojas nepasirinko', 'aicc' ); ?></option>
							<option value="always" <?php selected( $s['floating_mode'], 'always' ); ?>><?php esc_html_e( 'Visada', 'aicc' ); ?></option>
							<option value="never" <?php selected( $s['floating_mode'], 'never' ); ?>><?php esc_html_e( 'Niekada', 'aicc' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Pasirinkus „Tik kol nepasirinko“, po lankytojo sprendimo ekrane nelieka nieko. Tokiu atveju poraštėje palikite nuorodą į slapukų politikos puslapį arba nuorodą #aicc-settings – to reikalauja teisė atšaukti sutikimą.', 'aicc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicc-autohide"><?php esc_html_e( 'Juostos pasitraukimas', 'aicc' ); ?></label></th>
					<td>
						<input type="number" id="aicc-autohide" name="<?php echo esc_attr( $name ); ?>[auto_hide]" value="<?php echo esc_attr( (string) $s['auto_hide'] ); ?>" min="0" max="120" class="small-text"> <?php esc_html_e( 's.', 'aicc' ); ?>
						<p class="description"><?php esc_html_e( 'Jei lankytojas juostos neliečia, ji pasitraukia po tiek sekundžių ir lieka tik mažas mygtukas kampe. Sutikimas NĖRA suteikiamas – neprivalomi slapukai lieka išjungti, o kito apsilankymo metu bus paklausta iš naujo. 0 = niekada nesitraukia.', 'aicc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Iš anksto pažymėta', 'aicc' ); ?></th>
					<td>
						<?php foreach ( Category::optional() as $cat ) : ?>
							<label style="margin-right:14px;"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[precheck][]" value="<?php echo esc_attr( $cat->value ); ?>" <?php checked( in_array( $cat->value, (array) $s['precheck'], true ) ); ?>> <?php echo esc_html( $cat->label( 'lt' ) ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Kurios grupės nustatymų lange bus jau pažymėtos. Dėmesio: iš anksto pažymėtas langelis nėra galiojantis sutikimas (ESTT byla Planet49), todėl slapukai vis tiek įsijungs tik lankytojui paspaudus „Išsaugoti pasirinkimą“.', 'aicc' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Veikia be sutikimo (opt-out)', 'aicc' ); ?></th>
					<td>
						<?php foreach ( Category::optional() as $cat ) : ?>
							<label style="margin-right:14px;"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[implied][]" value="<?php echo esc_attr( $cat->value ); ?>" <?php checked( in_array( $cat->value, (array) $s['implied'], true ) ); ?>> <?php echo esc_html( $cat->label( 'lt' ) ); ?></label>
						<?php endforeach; ?>
						<p class="description" style="color:#b32d2e;">
							<strong><?php esc_html_e( 'Teisinė rizika.', 'aicc' ); ?></strong>
							<?php esc_html_e( 'Pažymėtos grupės veikia iš karto, kol lankytojas jų neišjungia. Tai „opt-out“ modelis: ePrivacy direktyva ir BDAR reikalauja išankstinio sutikimo neprivalomiems slapukams, o VDAI to laikosi. Rekomenduojama palikti tuščią ir vietoje to naudoti žemiau esantį „Neblokuojamos paslaugos“ sąrašą su youtube-nocookie.com – taip vaizdo įrašai veikia iš karto ir slapukų neįrašoma.', 'aicc' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Neblokuojamos paslaugos', 'aicc' ); ?></th>
					<td>
						<?php foreach ( Registry::services() as $key => $svc ) : ?>
							<?php if ( ! Category::from_slug( (string) $svc['category'] )->is_optional() ) { continue; } ?>
							<label style="display:inline-block;min-width:190px;margin-bottom:4px;">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[allow_services][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['allow_services'], true ) ); ?>>
								<?php echo esc_html( (string) $svc['name'] ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Pažymėtos paslaugos krausis iš karto, be sutikimo. Naudokite tik ten, kur slapukų neįrašoma iki paleidimo.', 'aicc' ); ?></p>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[yt_nocookie]" value="1" <?php checked( $s['yt_nocookie'] ); ?>> <?php esc_html_e( '„YouTube“ įterpinius keisti į youtube-nocookie.com (privatumo režimas)', 'aicc' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatinis skenavimas', 'aicc' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[scan_enabled]" value="1" <?php checked( $s['scan_enabled'] ); ?>> <?php esc_html_e( 'Reguliariai tikrinti, ar neatsirado naujų slapukų', 'aicc' ); ?></label>
						<select name="<?php echo esc_attr( $name ); ?>[scan_frequency]" style="margin-left:12px;">
							<option value="daily" <?php selected( $s['scan_frequency'], 'daily' ); ?>><?php esc_html_e( 'kasdien', 'aicc' ); ?></option>
							<option value="weekly" <?php selected( $s['scan_frequency'], 'weekly' ); ?>><?php esc_html_e( 'kas savaitę', 'aicc' ); ?></option>
							<option value="monthly" <?php selected( $s['scan_frequency'], 'monthly' ); ?>><?php esc_html_e( 'kas mėnesį', 'aicc' ); ?></option>
						</select>
						<br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[reask_on_new]" value="1" <?php checked( $s['reask_on_new'] ); ?>> <?php esc_html_e( 'Radus naują neprivalomą slapuką, sutikimo klausti iš naujo', 'aicc' ); ?></label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function tab_cookies(): void {
		$state   = Scanner::state();
		$cookies = Registry::stored();
		$next    = wp_next_scheduled( Scanner::CRON_HOOK );
		usort( $cookies, static fn( array $a, array $b ): int => strcasecmp( (string) $a['category'] . $a['name'], (string) $b['category'] . $b['name'] ) );

		$deep = wp_nonce_url( add_query_arg( 'aicc_deep_scan', '1', home_url( '/' ) ), 'aicc_deep_scan' );
		?>
		<?php if ( ! empty( $_GET['scanned'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Skenavimas baigtas.', 'aicc' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Slapukų sąrašas išsaugotas.', 'aicc' ); ?></p></div>
		<?php endif; ?>

		<div class="card" style="max-width:none;padding:12px 16px;">
			<p style="margin:0 0 10px;">
				<strong><?php esc_html_e( 'Paskutinis skenavimas:', 'aicc' ); ?></strong>
				<?php echo esc_html( (int) $state['last_run'] > 0 ? wp_date( 'Y-m-d H:i', (int) $state['last_run'] ) : __( 'dar nevykdytas', 'aicc' ) ); ?>
				&nbsp;·&nbsp;
				<strong><?php esc_html_e( 'Kitas:', 'aicc' ); ?></strong>
				<?php echo esc_html( $next ? wp_date( 'Y-m-d H:i', (int) $next ) : __( 'neplanuotas', 'aicc' ) ); ?>
				<?php if ( ! empty( $state['services'] ) ) : ?>
					<br><strong><?php esc_html_e( 'Aptiktos paslaugos:', 'aicc' ); ?></strong> <?php echo esc_html( implode( ', ', (array) $state['services'] ) ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $state['last_error'] ) ) : ?>
					<br><span style="color:#b32d2e;"><?php echo esc_html( $state['last_error'] ); ?></span>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="aicc_scan_now">
				<?php wp_nonce_field( 'aicc_scan_now' ); ?>
				<?php submit_button( __( 'Skenuoti dabar', 'aicc' ), 'secondary', 'submit', false ); ?>
			</form>
			<a class="button" href="<?php echo esc_url( $deep ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Gilus skenavimas naršyklėje', 'aicc' ); ?></a>
			<p class="description"><?php esc_html_e( 'Gilus skenavimas atidaro svetainę be blokavimo, leidžia visiems scenarijams pasileisti ir surašo realiai įrašytus slapukus. Atlikite jį po didesnių pakeitimų.', 'aicc' ); ?></p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aicc_save_cookies">
			<?php wp_nonce_field( 'aicc_save_cookies' ); ?>

			<table class="widefat striped" style="margin-top:16px;">
				<thead>
					<tr>
						<th style="width:18%"><?php esc_html_e( 'Slapukas', 'aicc' ); ?></th>
						<th style="width:14%"><?php esc_html_e( 'Kategorija', 'aicc' ); ?></th>
						<th style="width:16%"><?php esc_html_e( 'Tiekėjas', 'aicc' ); ?></th>
						<th style="width:10%"><?php esc_html_e( 'Galiojimas', 'aicc' ); ?></th>
						<th><?php esc_html_e( 'Paskirtis (LT / EN / RU)', 'aicc' ); ?></th>
						<th style="width:6%"><?php esc_html_e( 'Šalinti', 'aicc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $cookies as $i => $row ) : ?>
						<tr<?php echo ( $row['category'] ?? '' ) === 'unknown' ? ' style="background:#fff6e5"' : ''; ?>>
							<td>
								<input type="text" name="cookie[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( (string) $row['name'] ); ?>" class="regular-text" style="width:100%">
								<input type="hidden" name="cookie[<?php echo (int) $i; ?>][service]" value="<?php echo esc_attr( (string) ( $row['service'] ?? '' ) ); ?>">
								<input type="hidden" name="cookie[<?php echo (int) $i; ?>][source]" value="<?php echo esc_attr( (string) ( $row['source'] ?? 'manual' ) ); ?>">
								<input type="hidden" name="cookie[<?php echo (int) $i; ?>][first_seen]" value="<?php echo esc_attr( (string) ( $row['first_seen'] ?? time() ) ); ?>">
								<input type="hidden" name="cookie[<?php echo (int) $i; ?>][last_seen]" value="<?php echo esc_attr( (string) ( $row['last_seen'] ?? time() ) ); ?>">
								<label style="font-size:11px;color:#666;"><input type="checkbox" name="cookie[<?php echo (int) $i; ?>][storage]" value="local" <?php checked( ( $row['storage'] ?? 'cookie' ), 'local' ); ?>> <?php esc_html_e( 'vietinė saugykla', 'aicc' ); ?></label>
							</td>
							<td>
								<select name="cookie[<?php echo (int) $i; ?>][category]">
									<?php foreach ( Category::cases() as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->value ); ?>" <?php selected( (string) ( $row['category'] ?? '' ), $cat->value ); ?>><?php echo esc_html( $cat->label( 'lt' ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input type="text" name="cookie[<?php echo (int) $i; ?>][provider]" value="<?php echo esc_attr( (string) ( $row['provider'] ?? '' ) ); ?>" style="width:100%"></td>
							<td><input type="text" name="cookie[<?php echo (int) $i; ?>][duration]" value="<?php echo esc_attr( (string) ( $row['duration'] ?? 'session' ) ); ?>" style="width:100%" placeholder="2y"></td>
							<td>
								<?php foreach ( I18n::LANGS as $code ) : ?>
									<input type="text" name="cookie[<?php echo (int) $i; ?>][purpose][<?php echo esc_attr( $code ); ?>]"
										value="<?php echo esc_attr( (string) ( $row['purpose'][ $code ] ?? '' ) ); ?>"
										placeholder="<?php echo esc_attr( strtoupper( $code ) ); ?>" style="width:100%;margin-bottom:3px;">
								<?php endforeach; ?>
							</td>
							<td style="text-align:center;"><input type="checkbox" name="cookie[<?php echo (int) $i; ?>][delete]" value="1"></td>
						</tr>
					<?php endforeach; ?>

					<?php $new = count( $cookies ); ?>
					<tr style="background:#f0f6fc">
						<td>
							<input type="text" name="cookie[<?php echo (int) $new; ?>][name]" value="" placeholder="<?php esc_attr_e( 'naujas slapukas', 'aicc' ); ?>" style="width:100%">
							<input type="hidden" name="cookie[<?php echo (int) $new; ?>][source]" value="manual">
						</td>
						<td>
							<select name="cookie[<?php echo (int) $new; ?>][category]">
								<?php foreach ( Category::cases() as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->value ); ?>"><?php echo esc_html( $cat->label( 'lt' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="text" name="cookie[<?php echo (int) $new; ?>][provider]" style="width:100%"></td>
						<td><input type="text" name="cookie[<?php echo (int) $new; ?>][duration]" style="width:100%" placeholder="session"></td>
						<td>
							<?php foreach ( I18n::LANGS as $code ) : ?>
								<input type="text" name="cookie[<?php echo (int) $new; ?>][purpose][<?php echo esc_attr( $code ); ?>]" placeholder="<?php echo esc_attr( strtoupper( $code ) ); ?>" style="width:100%;margin-bottom:3px;">
							<?php endforeach; ?>
						</td>
						<td></td>
					</tr>
				</tbody>
			</table>

			<p>
				<label><input type="checkbox" name="bump_revision" value="1"> <?php esc_html_e( 'Klausti visų lankytojų sutikimo iš naujo (sąrašas iš esmės pasikeitė)', 'aicc' ); ?></label>
			</p>
			<p class="description"><?php esc_html_e( 'Galiojimo formatas: 30min, 24h, 14d, 3m, 2y, session, persistent, ls.', 'aicc' ); ?></p>
			<?php submit_button( __( 'Išsaugoti slapukų sąrašą', 'aicc' ) ); ?>
		</form>
		<?php
	}

	private function tab_log(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'aicc_consents';
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 100', $table ), ARRAY_A ) ?: [];
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		?>
		<p>
			<?php
			printf(
				/* translators: %d: number of records */
				esc_html__( 'Iš viso įrašų: %d. Rodomi naujausi 100.', 'aicc' ),
				$total
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aicc_export_log">
			<?php wp_nonce_field( 'aicc_export_log' ); ?>
			<?php submit_button( __( 'Atsisiųsti CSV', 'aicc' ), 'secondary', 'submit', false ); ?>
		</form>

		<table class="widefat striped" style="margin-top:16px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Data (UTC)', 'aicc' ); ?></th>
					<th><?php esc_html_e( 'Pasirinkimas', 'aicc' ); ?></th>
					<th><?php esc_html_e( 'Kalba', 'aicc' ); ?></th>
					<th><?php esc_html_e( 'Versija', 'aicc' ); ?></th>
					<th><?php esc_html_e( 'Puslapis', 'aicc' ); ?></th>
					<th><?php esc_html_e( 'Žymuo', 'aicc' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $rows === [] ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Įrašų dar nėra.', 'aicc' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$cats    = json_decode( (string) $row['categories'], true );
					$allowed = is_array( $cats ) ? array_keys( array_filter( $cats ) ) : [];
					?>
					<tr>
						<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
						<td><?php echo esc_html( $allowed === [] ? __( 'tik būtinieji', 'aicc' ) : implode( ', ', $allowed ) ); ?></td>
						<td><?php echo esc_html( (string) $row['lang'] ); ?></td>
						<td><?php echo esc_html( (string) $row['revision'] ); ?></td>
						<td><?php echo esc_html( (string) $row['source_url'] ); ?></td>
						<td><code><?php echo esc_html( substr( (string) $row['consent_id'], 0, 8 ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function tab_help(): void {
		?>
		<div class="card" style="max-width:840px;">
			<h2><?php esc_html_e( 'Trumpai', 'aicc' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Pagal nutylėjimą svetainė neįrašo jokio neprivalomo slapuko. Sutikimas saugomas naršyklės vietinėje saugykloje, todėl iki pasirinkimo nesukuriamas net sutikimo slapukas.', 'aicc' ); ?></li>
				<li><?php esc_html_e( 'Analitikos ir rinkodaros scenarijai perrašomi į type="text/plain", o įterptas turinys pakeičiamas užsklanda. Tas pats HTML tinka visiems lankytojams, todėl puslapių podėlis (cache) veikia įprastai.', 'aicc' ); ?></li>
				<li><?php esc_html_e( 'Skeneris reguliariai patikrina svetainės kodą ir papildo slapukų sąrašą. Naujus slapukus rasite skiltyje „Slapukai ir skenavimas“ pažymėtus geltonai.', 'aicc' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Trumpiniai', 'aicc' ); ?></h2>
			<p><code>[aicc_cookie_policy]</code> — <?php esc_html_e( 'visas slapukų sąrašas ir paaiškinimai (politikos puslapiui).', 'aicc' ); ?></p>
			<p><code>[aicc_cookie_settings]</code> — <?php esc_html_e( 'mygtukas „Keisti pasirinkimą“ bet kurioje vietoje.', 'aicc' ); ?></p>
			<p><code>[aicc_cookie_policy lang="ru"]</code> — <?php esc_html_e( 'sąrašas konkrečia kalba.', 'aicc' ); ?></p>

			<h2><?php esc_html_e( 'Nuorodos meniu', 'aicc' ); ?></h2>
			<p><?php esc_html_e( 'Meniu ar poraštėje naudokite nuorodą į #aicc-settings — paspaudus atsidarys nustatymų langas.', 'aicc' ); ?></p>

			<h2><?php esc_html_e( 'Kaip nebūti įkyriems', 'aicc' ); ?></h2>
			<p><?php esc_html_e( 'Juosta nedengia turinio ir neužtemdo ekrano, mygtukai „Sutikti su visais“ ir „Tik būtinieji“ vienodo svorio, o pasirinkimas galioja 180 dienų. Tai atitinka VDAI rekomendacijas ir nekankina skaitytojo.', 'aicc' ); ?></p>
		</div>
		<?php
	}
}
