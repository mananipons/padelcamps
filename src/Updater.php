<?php
/**
 * Self-updater: lets WordPress update the plugin from GitHub Releases.
 *
 * Flow: the latest release of the repository is read from the GitHub API
 * (cached), its tag is compared with the installed version and, when newer, the
 * release ZIP asset is offered as a normal update (Plugins / Dashboard → Updates).
 *
 * Private repository: the API and the asset download need a token. Define it in
 * wp-config.php (fine-grained token, repository "padelcamps", permission
 * "Contents: read-only"):
 *
 *     define( 'PADELCAMPS_GITHUB_TOKEN', 'github_pat_xxx' );
 *
 * It is only ever sent to api.github.com. For a public repository no token is
 * needed. The repository can be overridden with PADELCAMPS_GITHUB_REPO.
 */

namespace PadelCamps;

defined( 'ABSPATH' ) || exit;

class Updater {

	private const DEFAULT_REPO = 'mananipons/padelcamps';
	private const CACHE_KEY    = 'padelcamps_latest_release';
	private const CACHE_TTL    = 6 * HOUR_IN_SECONDS;
	private const API_HOST     = 'api.github.com';

	private string $basename;

	public function __construct() {
		$this->basename = plugin_basename( PADELCAMPS_FILE );
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download_package' ), 10, 3 );
		// "Check again" on Dashboard → Updates clears the update transient: drop our cache too.
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );
	}

	public function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	private function repo(): string {
		$repo = defined( 'PADELCAMPS_GITHUB_REPO' ) ? (string) PADELCAMPS_GITHUB_REPO : self::DEFAULT_REPO;
		return preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ? $repo : self::DEFAULT_REPO;
	}

	private function token(): string {
		$token = defined( 'PADELCAMPS_GITHUB_TOKEN' ) ? (string) PADELCAMPS_GITHUB_TOKEN : '';
		return (string) apply_filters( 'padelcamps_github_token', $token );
	}

	/**
	 * Latest release as array( version, package, url, notes, published ), or null.
	 * Failures are cached briefly so a broken token does not slow every admin page.
	 */
	private function latest(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached['release'] ?? null;
		}

		$release = $this->fetch_latest();
		set_site_transient(
			self::CACHE_KEY,
			array( 'release' => $release ),
			null === $release ? 15 * MINUTE_IN_SECONDS : self::CACHE_TTL
		);
		return $release;
	}

	private function fetch_latest(): ?array {
		$response = wp_remote_get(
			'https://' . self::API_HOST . '/repos/' . $this->repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $this->api_headers( 'application/vnd.github+json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		$package = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( preg_match( '/^padelcamps-.+\.zip$/', $name ) ) {
				// The API URL (not browser_download_url) works for private repositories.
				$package = (string) ( $asset['url'] ?? '' );
				break;
			}
		}
		if ( '' === $version || '' === $package ) {
			return null;
		}

		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => (string) ( $data['html_url'] ?? '' ),
			'notes'     => (string) ( $data['body'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
	}

	/** @return array<string,string> */
	private function api_headers( string $accept ): array {
		$headers = array(
			'Accept'     => $accept,
			'User-Agent' => 'PadelCamps-Updater',
		);
		$token   = $this->token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	private function is_our_package( $package ): bool {
		return is_string( $package )
			&& 0 === strpos( $package, 'https://' . self::API_HOST . '/repos/' . $this->repo() . '/releases/assets/' );
	}

	/** Adds the update to the transient WordPress checks. */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = $this->latest();
		if ( ! $release ) {
			return $transient;
		}

		$item = (object) array(
			'id'            => 'github.com/' . $this->repo(),
			'slug'          => dirname( $this->basename ),
			'plugin'        => $this->basename,
			'new_version'   => $release['version'],
			'url'           => $release['url'],
			'package'       => $release['package'],
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'tested'        => '',
		);

		if ( version_compare( $release['version'], PADELCAMPS_VERSION, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			$item->package                          = '';
			$transient->no_update[ $this->basename ] = $item;
			unset( $transient->response[ $this->basename ] );
		}
		return $transient;
	}

	/** Data for the "View version details" popup. */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || dirname( $this->basename ) !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = $this->latest();
		if ( ! $release ) {
			return $result;
		}

		$notes = '' !== trim( $release['notes'] ) ? wp_kses_post( wpautop( esc_html( $release['notes'] ) ) ) : '';

		return (object) array(
			'name'          => 'PadelCamps',
			'slug'          => dirname( $this->basename ),
			'version'       => $release['version'],
			'author'        => 'Finismedia',
			'homepage'      => $release['url'],
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array( 'changelog' => $notes ),
		);
	}

	/**
	 * Downloads the release asset ourselves so the token goes only to the GitHub API.
	 *
	 * @param bool|\WP_Error|string $reply   Short-circuit value (false = not handled).
	 * @param string                $package Package URL.
	 */
	public function download_package( $reply, $package, $upgrader = null ) {
		if ( ! $this->is_our_package( $package ) ) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = wp_tempnam( $package );
		if ( ! $file ) {
			return new \WP_Error( 'padelcamps_tmp', 'Could not create a temporary file.' );
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $file,
				'headers'  => $this->api_headers( 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $file );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $file );
			return new \WP_Error( 'padelcamps_download', 'GitHub download failed (HTTP ' . $code . ').' );
		}
		return $file;
	}
}
