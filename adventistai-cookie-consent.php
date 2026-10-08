<?php
/**
 * Plugin Name:       WP Cookie Consent
 * Plugin URI:        https://github.com/kiritoshiro/wp-cookie-consent
 * Description:       Neįkyrus GDPR slapukų sutikimo sprendimas: pagal nutylėjimą veikia tik būtinieji slapukai, sekimo scenarijai blokuojami iki sutikimo, slapukai periodiškai nuskenuojami automatiškai. Sąsaja lietuvių, anglų ir rusų kalbomis.
 * Version:           1.0.14
 * Requires at least: 6.4
 * Requires PHP:      8.4
 * Update URI:        https://github.com/kiritoshiro/wp-cookie-consent
 * Author:            adventistai
 * Author URI:        https://adventistai.lt/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aicc
 */

declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.0.14';

define( 'AICC_FILE', __FILE__ );
define( 'AICC_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICC_URL', plugin_dir_url( __FILE__ ) );

require_once AICC_DIR . 'includes/Category.php';
require_once AICC_DIR . 'includes/I18n.php';
require_once AICC_DIR . 'includes/Settings.php';
require_once AICC_DIR . 'includes/Registry.php';
require_once AICC_DIR . 'includes/Blocker.php';
require_once AICC_DIR . 'includes/Scanner.php';
require_once AICC_DIR . 'includes/Frontend.php';
require_once AICC_DIR . 'includes/Rest.php';
require_once AICC_DIR . 'includes/Admin.php';
require_once AICC_DIR . 'includes/Installer.php';
require_once AICC_DIR . 'includes/Updater.php';

register_activation_hook( __FILE__, [ Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Installer::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		( new Frontend() )->hooks();
		( new Blocker() )->hooks();
		( new Scanner() )->hooks();
		( new Rest() )->hooks();

		if ( is_admin() ) {
			( new Admin() )->hooks();
		}

		Installer::maybe_upgrade();
		( new Updater() )->hooks();
	}
);
