<?php
/**
 * Private GitHub release updater for WP Cookie Consent.
 *
 * @package Adventistai\CookieConsent
 */

declare( strict_types=1 );

namespace Adventistai\CookieConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks private GitHub releases and downloads authenticated release assets.
 */
final class Updater {
	private const REPOSITORY_URL = 'https://github.com/kiritoshiro/wp-cookie-consent';
	private const RELEASE_API    = 'https://api.github.com/repos/kiritoshiro/wp-cookie-consent/releases/latest';
	private const ASSET_NAME     = 'wp-cookie-consent.zip';
	private const CACHE_KEY      = 'aicc_github_latest_release';

	/**
	 * Release data cached for the lifetime of this request.
	 *
	 * @var array|null
	 */
	private ?array $release_cache = null;

	/**
	 * Register WordPress update hooks.
	 */
	public function hooks(): void {
		add_filter( 'update_plugins_github.com', [ $this, 'provide_update' ], 10, 4 );
		add_filter( 'upgrader_pre_download', [ $this, 'download_private_package' ], 10, 4 );
		add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );
	}

	/**
	 * Return release metadata to WordPress when a newer version is available.
	 *
	 * @param array|false $update Existing update data.
	 * @param array       $plugin_data Installed plugin headers.
	 * @param string      $plugin_file Plugin path relative to the plugins directory.
	 * @param string[]    $locales Installed site locales.
	 * @return array|false
	 */
	public function provide_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $locales );

		if ( plugin_basename( AICC_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->get_latest_release();

		if ( is_wp_error( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return false;
		}

		$version = $this->release_version( $release );

		if ( '' === $version || version_compare( $version, $plugin_data['Version'] ?? VERSION, '<=' ) ) {
			return false;
		}

		$asset = $this->find_release_asset( $release );

		if ( ! $asset ) {
			return false;
		}

		return [
			'slug'         => 'wp-cookie-consent',
			'version'      => $version,
			'url'          => self::REPOSITORY_URL,
			'package'      => $this->asset_api_url( $asset ),
			'requires'     => '6.4',
			'tested'       => '6.8',
			'requires_php' => '8.4',
			'autoupdate'   => false,
		];
	}

	/**
	 * Serve plugin details for WordPress' version-details modal.
	 *
	 * @param object|false $result Existing plugin information.
	 * @param string       $action Requested plugin API action.
	 * @param object|array $args Request arguments.
	 * @return object|false
	 */
	public function plugin_information( $result, $action, $args ) {
		$slug = '';
		if ( is_object( $args ) ) {
			$slug = $args->slug ?? '';
		} elseif ( is_array( $args ) ) {
			$slug = $args['slug'] ?? '';
		}
		if ( 'plugin_information' !== $action || 'wp-cookie-consent' !== $slug ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( is_wp_error( $release ) ) {
			return $result;
		}

		$asset   = $this->find_release_asset( $release );
		$version = $this->release_version( $release );

		if ( ! $asset || '' === $version ) {
			return $result;
		}

		$notes = isset( $release['body'] ) ? wpautop( wp_kses_post( (string) $release['body'] ) ) : '';

		return (object) [
			'name'          => 'WP Cookie Consent',
			'slug'          => 'wp-cookie-consent',
			'version'       => $version,
			'author'        => 'adventistai',
			'homepage'      => self::REPOSITORY_URL,
			'requires'      => '6.4',
			'tested'        => '6.8',
			'requires_php'  => '8.4',
			'last_updated'  => (string) ( $release['published_at'] ?? '' ),
			'download_link' => $this->asset_api_url( $asset ),
			'sections'      => [
				'description' => '<p>' . esc_html__( 'A GDPR cookie consent plugin with automatic cookie scanning and Lithuanian, English, and Russian interfaces.', 'aicc' ) . '</p>',
				'changelog'   => $notes,
			],
		];
	}

	/**
	 * Download this plugin's private GitHub asset with its configured token.
	 *
	 * @param false|string|\WP_Error $reply Existing pre-download result.
	 * @param string                 $package Package URL.
	 * @param \WP_Upgrader           $upgrader Upgrader instance.
	 * @param array                  $hook_extra Upgrade context.
	 * @return false|string|\WP_Error
	 */
	public function download_private_package( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader );

		if ( false !== $reply ) {
			return $reply;
		}

		if (
			empty( $hook_extra['plugin'] )
			|| plugin_basename( AICC_FILE ) !== $hook_extra['plugin']
			|| ! $this->is_release_asset_url( $package )
		) {
			return false;
		}

		$token = $this->github_token();

		if ( '' === $token ) {
			return new \WP_Error(
				'aicc_github_token_missing',
				__( 'Configure WP_COOKIE_CONSENT_GITHUB_TOKEN in wp-config.php to download private updates.', 'aicc' )
			);
		}

		$temp_file = wp_tempnam( self::ASSET_NAME );

		if ( ! $temp_file ) {
			return new \WP_Error( 'aicc_github_temp_file', __( 'Could not create a temporary file for the plugin update.', 'aicc' ) );
		}

		$response = wp_remote_get(
			$package,
			[
				'headers'     => $this->github_headers( $token, 'application/octet-stream' ),
				'timeout'     => 300,
				'redirection' => 0,
				'stream'      => true,
				'filename'    => $temp_file,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $temp_file );
			return new \WP_Error( 'aicc_github_download', __( 'GitHub could not provide the private plugin update.', 'aicc' ), $response );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 302 === $status ) {
			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( ! is_string( $location ) || ! $this->is_trusted_asset_url( $location ) ) {
				wp_delete_file( $temp_file );
				return new \WP_Error( 'aicc_github_redirect', __( 'GitHub returned an unexpected release download URL.', 'aicc' ) );
			}

			if ( false === file_put_contents( $temp_file, '' ) ) {
				wp_delete_file( $temp_file );
				return new \WP_Error( 'aicc_github_temp_file', __( 'Could not prepare the temporary file for the plugin update.', 'aicc' ) );
			}

			// GitHub's redirect contains a short-lived signed URL; do not send the token to that host.
			$response = wp_safe_remote_get(
				$location,
				[
					'timeout'     => 300,
					'redirection' => 0,
					'stream'      => true,
					'filename'    => $temp_file,
				]
			);

			if ( is_wp_error( $response ) ) {
				wp_delete_file( $temp_file );
				return new \WP_Error( 'aicc_github_download', __( 'The private plugin release asset could not be downloaded.', 'aicc' ), $response );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
		}

		if ( 200 !== $status || ! $this->is_zip_file( $temp_file ) ) {
			wp_delete_file( $temp_file );
			return new \WP_Error( 'aicc_github_invalid_package', __( 'GitHub did not return a valid WP Cookie Consent ZIP package.', 'aicc' ) );
		}

		return $temp_file;
	}

	/**
	 * Retrieve the latest release using the configured repository token.
	 *
	 * @return array|\WP_Error
	 */
	private function get_latest_release() {
		$token = $this->github_token();

		if ( '' === $token ) {
			return new \WP_Error( 'aicc_github_token_missing', __( 'Private GitHub updates require WP_COOKIE_CONSENT_GITHUB_TOKEN in wp-config.php.', 'aicc' ) );
		}

		if ( null !== $this->release_cache ) {
			return $this->release_cache;
		}

		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && ! empty( $cached['tag_name'] ) ) {
			$this->release_cache = $cached;
			return $cached;
		}

		$response = wp_remote_get(
			self::RELEASE_API,
			[
				'headers' => $this->github_headers( $token, 'application/vnd.github+json' ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'aicc_github_api', __( 'Could not contact GitHub to check for plugin updates.', 'aicc' ), $response );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return new \WP_Error( 'aicc_github_api', __( 'GitHub did not return a release for this plugin.', 'aicc' ), [ 'status' => $status ] );
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return new \WP_Error( 'aicc_github_release_invalid', __( 'GitHub returned invalid release information for this plugin.', 'aicc' ) );
		}

		$this->release_cache = $release;
		$cache_ttl           = $this->find_release_asset( $release ) ? 15 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS;
		set_transient( self::CACHE_KEY, $release, $cache_ttl );

		return $release;
	}
	/**
	 * Return the GitHub token without storing or exposing it.
	 *
	 * @return string
	 */
	private function github_token(): string {
		$token = defined( 'WP_COOKIE_CONSENT_GITHUB_TOKEN' ) ? (string) WP_COOKIE_CONSENT_GITHUB_TOKEN : '';
		$token = apply_filters( 'aicc_github_token', $token );

		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Build headers for GitHub API requests.
	 *
	 * @param string $token GitHub token.
	 * @param string $accept Accept header.
	 * @return array
	 */
	private function github_headers( string $token, string $accept ): array {
		return [
			'Accept'               => $accept,
			'Authorization'        => 'Bearer ' . $token,
			'User-Agent'           => 'WP-Cookie-Consent/' . VERSION,
			'X-GitHub-Api-Version' => '2026-03-10',
		];
	}

	/**
	 * Locate the ZIP asset attached to the release.
	 *
	 * @param array $release GitHub release response.
	 * @return array|false
	 */
	private function find_release_asset( array $release ) {
		foreach ( $release['assets'] ?? [] as $asset ) {
			if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) && ! empty( $asset['id'] ) && is_numeric( $asset['id'] ) ) {
				return $asset;
			}
		}

		return false;
	}

	/**
	 * Build the authenticated GitHub API URL for an asset.
	 *
	 * @param array $asset GitHub release asset data.
	 * @return string
	 */
	private function asset_api_url( array $asset ): string {
		return 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/' . absint( $asset['id'] );
	}

	/**
	 * Normalize a release tag to a WordPress-comparable version.
	 *
	 * @param array $release GitHub release response.
	 * @return string
	 */
	private function release_version( array $release ): string {
		$version = preg_replace( '/^v/i', '', (string) $release['tag_name'] );

		if ( ! is_string( $version ) || ! preg_match( '/^\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?$/D', $version ) ) {
			return '';
		}

		return $version;
	}

	/**
	 * Restrict intercepted package URLs to release assets from this repository.
	 *
	 * @param string $package Package URL.
	 * @return bool
	 */
	private function is_release_asset_url( string $package ): bool {
		return (bool) preg_match(
			'~^https://api\.github\.com/repos/kiritoshiro/wp-cookie-consent/releases/assets/[0-9]+$~D',
			$package
		);
	}

	/**
	 * Allow only secure GitHub release CDN redirects.
	 *
	 * @param string $url Redirect URL.
	 * @return bool
	 */
	private function is_trusted_asset_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return false;
		}

		return in_array( $host, [ 'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com' ], true )
			|| str_ends_with( $host, '.githubusercontent.com' )
			|| str_ends_with( $host, '.amazonaws.com' );
	}

	/**
	 * Confirm the temporary download is a ZIP archive before passing it to WordPress.
	 *
	 * @param string $filename Temporary package path.
	 * @return bool
	 */
	private function is_zip_file( string $filename ): bool {
		$handle = fopen( $filename, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$signature = fread( $handle, 4 );
		fclose( $handle );

		return in_array( $signature, [ "PK\x03\x04", "PK\x05\x06", "PK\x07\x08" ], true );
	}
}