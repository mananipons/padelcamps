<?php
/**
 * Self-updater: lets WordPress update the plugin from GitHub Releases.
 *
 * Flow: the latest release of the repository is read from the GitHub API
 * (cached), its tag is compared with the installed version and, when newer, the
 * release ZIP asset is offered as a normal update (Plugins / Dashboard → Updates).
 *
 * The repository is public, so no configuration is needed. If it ever becomes
 * private, define a read-only token in wp-config.php (fine-grained token,
 * repository "padelcamps", permission "Contents: read-only"):
 *
 *     define( 'PADELCAMPS_GITHUB_TOKEN', 'github_pat_xxx' );
 *
 * The token is only ever sent to api.github.com, and if GitHub rejects it the
 * request is retried without it (so a stale token cannot break a public repo).
 * The repository can be overridden with PADELCAMPS_GITHUB_REPO.
 *
 * "Comprova actualitzacions" in the plugin row forces a check and reports what
 * GitHub answered, to diagnose sites where no update shows up.
 */

namespace PadelCamps;

defined( 'ABSPATH' ) || exit;

class Updater {

	private const DEFAULT_REPO = 'mananipons/padelcamps';
	private const CACHE_KEY    = 'padelcamps_latest_release';
	private const STATUS_OPT   = 'padelcamps_update_status';
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
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'action_links' ) );
		add_action( 'admin_post_padelcamps_check_update', array( $this, 'handle_check' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
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
	 * Failures are cached briefly so a broken connection does not slow every admin page.
	 */
	private function latest(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached['release'] ?? null;
		}

		$status  = array();
		$release = $this->fetch_latest( $status );
		update_option( self::STATUS_OPT, $status, false );
		set_site_transient(
			self::CACHE_KEY,
			array( 'release' => $release ),
			null === $release ? 15 * MINUTE_IN_SECONDS : self::CACHE_TTL
		);
		return $release;
	}

	/**
	 * Reads the latest release. $status receives what happened (for diagnostics):
	 * time, code (HTTP), error (technical text), version, token_rejected.
	 *
	 * @param array<string,mixed> $status Filled in by reference.
	 */
	private function fetch_latest( array &$status ): ?array {
		$status    = array(
			'time'           => time(),
			'code'           => 0,
			'error'          => '',
			'version'        => '',
			'token_rejected' => false,
		);
		$use_token = '' !== $this->token();

		$response = $this->request_latest( $use_token );
		// A wrong or expired token must not break a public repository: retry without it.
		if ( $use_token && ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), array( 401, 403, 404 ), true ) ) {
			$status['token_rejected'] = true;
			$response                 = $this->request_latest( false );
		}

		if ( is_wp_error( $response ) ) {
			$status['error'] = $response->get_error_message();
			return null;
		}
		$status['code'] = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status['code'] ) {
			$remaining       = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			$status['error'] = ( 403 === $status['code'] && '0' === (string) $remaining ) ? 'rate limit exceeded' : 'HTTP ' . $status['code'];
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			$status['error'] = 'unexpected response';
			return null;
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		$package = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( preg_match( '/^padelcamps-.+\.zip$/', $name ) ) {
				// The API URL (not browser_download_url) works for private repositories too.
				$package = (string) ( $asset['url'] ?? '' );
				break;
			}
		}
		if ( '' === $version || '' === $package ) {
			$status['error'] = 'release has no ZIP asset';
			return null;
		}
		$status['version'] = $version;

		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => (string) ( $data['html_url'] ?? '' ),
			'notes'     => (string) ( $data['body'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
	}

	/** @return array|\WP_Error */
	private function request_latest( bool $with_token ) {
		return wp_remote_get(
			'https://' . self::API_HOST . '/repos/' . $this->repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $this->api_headers( 'application/vnd.github+json', $with_token ),
			)
		);
	}

	/** @return array<string,string> */
	private function api_headers( string $accept, bool $with_token = true ): array {
		$headers = array(
			'Accept'     => $accept,
			'User-Agent' => 'PadelCamps-Updater',
		);
		$token   = $with_token ? $this->token() : '';
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	/** True when the last check showed that the configured token is rejected (then it is not sent). */
	private function token_rejected(): bool {
		$status = get_option( self::STATUS_OPT );
		return is_array( $status ) && ! empty( $status['token_rejected'] );
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
			'id'           => 'github.com/' . $this->repo(),
			'slug'         => dirname( $this->basename ),
			'plugin'       => $this->basename,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.0',
			'requires_php' => '8.0',
			'tested'       => '',
		);

		if ( version_compare( $release['version'], PADELCAMPS_VERSION, '>' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$item->package                           = '';
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
	 * Downloads the release asset ourselves so any token goes only to the GitHub API.
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
				'headers'  => $this->api_headers( 'application/octet-stream', ! $this->token_rejected() ),
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

	/** "Check for updates" link in the plugin row. */
	public function action_links( $links ) {
		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=padelcamps_check_update' ), 'padelcamps_check_update' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Comprova actualitzacions', 'padelcamps' ) . '</a>';
		return $links;
	}

	/** Forces a fresh check and returns to the Plugins screen with the result. */
	public function handle_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'padelcamps_check_update' );
		$this->clear_cache();
		delete_site_transient( 'update_plugins' ); // Forces WordPress to rebuild it (and run our filter).
		wp_update_plugins();
		wp_safe_redirect( add_query_arg( 'padelcamps_checked', '1', self_admin_url( 'plugins.php' ) ) );
		exit;
	}

	/** Result of the manual check, shown on the Plugins screen. */
	public function notice(): void {
		if ( empty( $_GET['padelcamps_checked'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			return;
		}
		$status = get_option( self::STATUS_OPT );
		$status = is_array( $status ) ? $status : array();

		if ( ! empty( $status['error'] ) ) {
			/* translators: %s: technical reason */
			$text  = sprintf( __( 'PadelCamps: no s’ha pogut consultar GitHub (%s).', 'padelcamps' ), (string) $status['error'] );
			$class = 'notice-error';
		} elseif ( ! empty( $status['version'] ) && version_compare( (string) $status['version'], PADELCAMPS_VERSION, '>' ) ) {
			/* translators: %s: version number */
			$text  = sprintf( __( 'PadelCamps: hi ha la versió %s disponible.', 'padelcamps' ), (string) $status['version'] );
			$class = 'notice-warning';
		} else {
			/* translators: %s: version number */
			$text  = sprintf( __( 'PadelCamps: tens l’última versió (%s).', 'padelcamps' ), PADELCAMPS_VERSION );
			$class = 'notice-success';
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
