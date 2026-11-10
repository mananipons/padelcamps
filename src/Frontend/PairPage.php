<?php
namespace PadelCamps\Frontend;

use PadelCamps\Admin\DrawTab;
use PadelCamps\Db\DrawRepository;
use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\ResultRepository;
use PadelCamps\Db\ScheduleRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\MatchFormat;
use PadelCamps\Engine\ResultReport;
use PadelCamps\Engine\ScoreParser;
use PadelCamps\Engine\TournamentStatus;
use PadelCamps\Service\DrawService;
use PadelCamps\Service\ResultService;

defined( 'ABSPATH' ) || exit;

/**
 * "My matches" page of a pair, opened with its magic link: https://site/?padelcamps=TOKEN
 * Standalone (does not depend on the theme), mobile-first. Shows the pair's
 * matches with time and court, and lets them report and confirm results.
 */
final class PairPage {

	public const PARAM = 'padelcamps';

	private const BAD_TOKEN_LIMIT  = 20;
	private const BAD_TOKEN_WINDOW = 600;

	public static function url( string $token ): string {
		return add_query_arg( self::PARAM, $token, home_url( '/' ) );
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
	}

	private static function message( string $code ): array {
		$ok = array(
			'reported'  => __( 'Resultat enviat. Ara l’ha de confirmar la parella rival.', 'padelcamps' ),
			'confirmed' => __( 'Resultat confirmat. Gràcies!', 'padelcamps' ),
			'disputed'  => __( 'Hem avisat l’organització: revisarà el resultat.', 'padelcamps' ),
		);
		if ( isset( $ok[ $code ] ) ) {
			return array( 'ok', $ok[ $code ] );
		}
		$err = array(
			'closed'         => __( 'L’organització no permet entrar resultats des d’aquest enllaç.', 'padelcamps' ),
			'played'         => __( 'Aquest partit ja té resultat.', 'padelcamps' ),
			'empty'          => __( 'Escriu el resultat de cada set.', 'padelcamps' ),
			'incomplete_set' => __( 'Cada set necessita els jocs de les dues parelles.', 'padelcamps' ),
			'invalid'        => __( 'Els jocs han de ser números.', 'padelcamps' ),
			'nonce'          => __( 'La sessió ha caducat. Torna-ho a provar.', 'padelcamps' ),
		);
		return array( 'error', $err[ $code ] ?? DrawTab::error( $code ) );
	}

	// --- Request handling -------------------------------------------------------

	public function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$token = strtolower( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $this->too_many_bad_tokens() ) {
			$this->error_page( __( 'Massa intents. Torna-ho a provar d’aquí a una estona.', 'padelcamps' ), 429 );
		}

		$results    = new ResultRepository();
		$reg        = $results->find_by_token( $token );
		$tournament = $reg ? ( new TournamentRepository() )->find( (int) $reg['tournament_id'] ) : null;
		if ( ! $reg || ! $tournament || ! TournamentStatus::is_public( $tournament['status'] ) ) {
			$this->count_bad_token();
			$this->error_page( __( 'Aquest enllaç no és vàlid o ja no està actiu. Demana’n un de nou a l’organització.', 'padelcamps' ), 404 );
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post( $tournament, $reg, $token );
		}
		$this->render( $tournament, $reg );
		exit;
	}

	private function ip_key(): string {
		return 'padelcamps_badtok_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}

	private function too_many_bad_tokens(): bool {
		return (int) get_transient( $this->ip_key() ) >= self::BAD_TOKEN_LIMIT;
	}

	private function count_bad_token(): void {
		set_transient( $this->ip_key(), (int) get_transient( $this->ip_key() ) + 1, self::BAD_TOKEN_WINDOW );
	}

	private function handle_post( array $tournament, array $reg, string $token ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below.
		$post = static fn( string $k ) => isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
		$back = static function ( string $code, int $match = 0 ) use ( $token ): void {
			wp_safe_redirect( add_query_arg( array( 'pm' => $code ), self::url( $token ) ) . ( $match ? '#m' . $match : '' ) );
			exit;
		};

		$match = absint( $post( 'match' ) );
		if ( ! wp_verify_nonce( (string) $post( '_wpnonce' ), 'padelcamps_pair_' . (int) $reg['id'] ) ) {
			$back( 'nonce', $match );
			exit;
		}
		$service = new ResultService();
		switch ( sanitize_key( (string) $post( 'op' ) ) ) {
			case 'report':
				$ga      = is_array( $post( 'ga' ) ) ? array_values( $post( 'ga' ) ) : array();
				$gb      = is_array( $post( 'gb' ) ) ? array_values( $post( 'gb' ) ) : array();
				$special = sanitize_key( (string) $post( 'special' ) );
				$built   = ResultReport::from_inputs( array_map( 'strval', $ga ), array_map( 'strval', $gb ), $special );
				$code    = null !== $built['error'] ? $built['error'] : $service->report( $tournament, $reg, $match, $built['result'] );
				break;
			case 'confirm':
				$code = $service->confirm( $reg, $match, absint( $post( 'report' ) ) );
				break;
			case 'dispute':
				$code = $service->dispute( $reg, $match, absint( $post( 'report' ) ) );
				break;
			default:
				$code = 'not_found';
		}
		// phpcs:enable
		$back( $code, $match );
		exit;
	}

	// --- Rendering --------------------------------------------------------------------

	private function head( string $title ): void {
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<link rel="stylesheet" href="' . esc_url( PADELCAMPS_URL . 'assets/css/pair.css?ver=' . PADELCAMPS_VERSION ) . '">';
		echo '</head><body class="pc-pair">';
	}

	private function error_page( string $message, int $status ): void {
		status_header( $status );
		$this->head( get_bloginfo( 'name' ) );
		echo '<main class="pc-wrap"><p class="pc-msg is-error">' . esc_html( $message ) . '</p></main></body></html>';
		exit;
	}

	private static function score_text( ?array $score ): string {
		if ( ! $score ) {
			return '';
		}
		if ( ! empty( $score['walkover'] ) ) {
			return __( 'W.O.', 'padelcamps' );
		}
		// Non-breaking hyphens: "6-4" never splits across lines on small screens.
		$text = str_replace( '-', "\u{2011}", ScoreParser::format( $score['sets'] ?? array() ) );
		return ! empty( $score['retired'] ) ? trim( $text . ' ' . __( '(ret.)', 'padelcamps' ) ) : $text;
	}

	private function render( array $tournament, array $reg ): void {
		$draws    = new DrawRepository();
		$results  = new ResultRepository();
		$service  = new ResultService();
		$rid      = (int) $reg['id'];
		$matches  = $results->matches_of( $rid );
		$phases   = $draws->phases( (int) $reg['category_id'] );
		$format   = ( new DrawService( $draws ) )->format( $phases );
		$can      = $service->players_can_report( $tournament );
		$courts   = array_column( ( new ScheduleRepository() )->courts( (int) $tournament['id'] ), 'name', 'id' );
		$ko_total = array( 'knockout' => 0, 'consolation' => 0 ); // Rounds of each bracket, for round names.
		foreach ( $draws->matches( (int) $reg['category_id'] ) as $m ) {
			foreach ( array_keys( $ko_total ) as $key ) {
				if ( $phases[ $key ] && (int) $m['phase_id'] === (int) $phases[ $key ]['id'] ) {
					$ko_total[ $key ] = max( $ko_total[ $key ], (int) $m['round'] );
				}
			}
		}

		$ids   = array_merge( array( $rid ), wp_list_pluck( $matches, 'reg_a_id' ), wp_list_pluck( $matches, 'reg_b_id' ) );
		$names = array();
		foreach ( ( new RegistrationRepository() )->players_for( array_filter( $ids ) ) as $id => $players ) {
			$names[ $id ] = implode( ' / ', wp_list_pluck( $players, 'name' ) );
		}

		$this->head( $tournament['name'] . ' · ' . ( $names[ $rid ] ?? '' ) );
		echo '<main class="pc-wrap">';
		echo '<header class="pc-head"><p class="pc-tournament">' . esc_html( $tournament['name'] ) . ' · ' . esc_html( $reg['category_name'] ) . '</p>';
		echo '<h1>' . esc_html( $names[ $rid ] ?? '' ) . '</h1></header>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$pm = isset( $_GET['pm'] ) ? sanitize_key( wp_unslash( $_GET['pm'] ) ) : '';
		if ( '' !== $pm ) {
			list( $type, $text ) = self::message( $pm );
			echo '<p class="pc-msg is-' . esc_attr( $type ) . '" role="status">' . esc_html( $text ) . '</p>';
		}

		if ( ! $matches ) {
			echo '<p class="pc-empty">' . esc_html__( 'Encara no teniu partits assignats. Torneu-hi quan s’hagi fet el sorteig.', 'padelcamps' ) . '</p>';
		}
		foreach ( $matches as $m ) {
			$this->card( $m, $rid, $names, $courts, $ko_total, $format, $can, $results );
		}
		echo '<p class="pc-foot">' . esc_html__( 'Aquest enllaç és personal de la vostra parella: no el compartiu.', 'padelcamps' ) . '</p>';
		echo '</main></body></html>';
	}

	private function card( array $m, int $rid, array $names, array $courts, array $ko_total, array $format, bool $can, ResultRepository $results ): void {
		$id   = (int) $m['id'];
		$a    = (int) $m['reg_a_id'];
		$b    = (int) $m['reg_b_id'];
		$mine = $a === $rid ? 'a' : 'b';

		if ( in_array( $m['phase_type'], array( 'groups', 'round_robin' ), true ) ) {
			$title = 'groups' === $m['phase_type']
				? sprintf( /* translators: 1: group letter, 2: round */ __( 'Grup %1$s · Ronda %2$d', 'padelcamps' ), DrawTab::letter( (int) $m['group_no'] - 1 ), (int) $m['round'] )
				: sprintf( __( 'Ronda %d', 'padelcamps' ), (int) $m['round'] );
		} else {
			$key   = 'consolation' === $m['phase_type'] ? 'consolation' : 'knockout';
			$title = DrawTab::round_label( (int) $m['round'], max( $ko_total[ $key ], (int) $m['round'] ) );
			if ( 'consolation' === $key ) {
				$title = __( 'Consolació', 'padelcamps' ) . ' · ' . $title;
			}
		}

		echo '<article class="pc-card" id="m' . $id . '"><h2>' . esc_html( $title ) . '</h2>';
		$when = array();
		if ( $m['scheduled_at'] ) {
			$when[] = mysql2date( 'l j M · H:i', $m['scheduled_at'] );
		}
		if ( $m['court_id'] && isset( $courts[ (int) $m['court_id'] ] ) ) {
			$when[] = $courts[ (int) $m['court_id'] ];
		}
		echo '<p class="pc-when">' . esc_html( $when ? implode( ' · ', $when ) : __( 'Horari per confirmar', 'padelcamps' ) ) . '</p>';

		$name = function ( int $id ) use ( $names, $rid ): string {
			if ( ! $id ) {
				return '<span class="pc-tbd">' . esc_html__( 'Rival per determinar', 'padelcamps' ) . '</span>';
			}
			$n = esc_html( $names[ $id ] ?? '' );
			return $id === $rid ? '<strong>' . $n . '</strong> <span class="pc-you">' . esc_html__( '(vosaltres)', 'padelcamps' ) . '</span>' : $n;
		};
		echo '<p class="pc-vs">' . $name( $a ) . '<span class="pc-vs-sep">' . esc_html__( 'contra', 'padelcamps' ) . '</span>' . $name( $b ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $name.

		if ( 'played' === $m['status'] ) {
			$won = (int) $m['winner_reg_id'] === $rid;
			echo '<p class="pc-result ' . ( $won ? 'is-win' : 'is-loss' ) . '">' . esc_html( self::score_text( $m['score'] ) ) . ' · ' . esc_html( $won ? __( 'Heu guanyat', 'padelcamps' ) : __( 'Heu perdut', 'padelcamps' ) ) . '</p></article>';
			return;
		}
		if ( ! $a || ! $b ) {
			echo '</article>';
			return;
		}

		$open     = $results->open_reports( $id );
		$disputed = array_filter( $open, static fn( $r ) => 'disputed' === $r['status'] );
		$pending  = array_values( array_filter( $open, static fn( $r ) => 'pending' === $r['status'] ) );

		if ( $disputed ) {
			echo '<p class="pc-state">' . esc_html__( 'Resultat en revisió per l’organització.', 'padelcamps' ) . '</p></article>';
			return;
		}
		if ( ! $can ) {
			echo '</article>';
			return;
		}

		if ( $pending && (int) $pending[0]['reg_id'] === $rid ) {
			/* translators: %s: score */
			echo '<p class="pc-state">' . esc_html( sprintf( __( 'Heu enviat: %s. Pendent que la parella rival el confirmi.', 'padelcamps' ), self::score_text( $pending[0]['result'] ) ) ) . '</p>';
			echo '<details><summary>' . esc_html__( 'Corregeix el resultat', 'padelcamps' ) . '</summary>';
			$this->report_form( $m, $rid, $names, $format );
			echo '</details>';
		} elseif ( $pending ) {
			/* translators: %s: score */
			echo '<p class="pc-state">' . esc_html( sprintf( __( 'La parella rival ha enviat aquest resultat: %s', 'padelcamps' ), self::score_text( $pending[0]['result'] ) ) ) . '</p>';
			echo '<div class="pc-actions">';
			foreach ( array( 'confirm' => __( 'És correcte', 'padelcamps' ), 'dispute' => __( 'No és correcte', 'padelcamps' ) ) as $op => $label ) {
				echo '<form method="post">';
				$this->hidden( $rid, $id, $op );
				echo '<input type="hidden" name="report" value="' . (int) $pending[0]['id'] . '"><button class="pc-btn' . ( 'confirm' === $op ? ' is-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
			}
			echo '</div><details><summary>' . esc_html__( 'Envia un resultat diferent', 'padelcamps' ) . '</summary>';
			$this->report_form( $m, $rid, $names, $format );
			echo '</details>';
		} else {
			$this->report_form( $m, $rid, $names, $format );
		}
		echo '</article>';
	}

	private function hidden( int $rid, int $match_id, string $op ): void {
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="match" value="' . $match_id . '">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'padelcamps_pair_' . $rid ) ) . '">';
	}

	private function report_form( array $m, int $rid, array $names, array $format ): void {
		$id    = (int) $m['id'];
		$sets  = (int) $format['best_of'];
		$short = static fn( int $reg ) => $names[ $reg ] ?? '';
		echo '<form method="post" class="pc-report">';
		$this->hidden( $rid, $id, 'report' );
		echo '<table class="pc-sets"><thead><tr><th></th><th>' . esc_html( $short( (int) $m['reg_a_id'] ) ) . '</th><th>' . esc_html( $short( (int) $m['reg_b_id'] ) ) . '</th></tr></thead><tbody>';
		for ( $i = 0; $i < $sets; $i++ ) {
			$label = ( $i === $sets - 1 && 'super_tiebreak' === $format['deciding'] && $sets > 1 )
				? __( 'Super tie-break', 'padelcamps' )
				: sprintf( /* translators: %d: set number */ __( 'Set %d', 'padelcamps' ), $i + 1 );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( array( 'ga', 'gb' ) as $side ) {
				printf( '<td><input type="number" inputmode="numeric" min="0" max="99" name="%s[%d]" aria-label="%s"></td>', esc_attr( $side ), $i, esc_attr( $label ) );
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<label class="pc-special">' . esc_html__( 'Final especial', 'padelcamps' ) . ' <select name="special"><option value="">' . esc_html__( 'Partit acabat normalment', 'padelcamps' ) . '</option>';
		foreach ( array( 'a', 'b' ) as $side ) {
			$n = $short( (int) $m[ 'reg_' . $side . '_id' ] );
			printf( '<option value="wo_%s">%s</option>', esc_attr( $side ), esc_html( sprintf( __( 'W.O. a favor de %s', 'padelcamps' ), $n ) ) );
			printf( '<option value="ret_%s">%s</option>', esc_attr( $side ), esc_html( sprintf( __( 'Es retira %s', 'padelcamps' ), $n ) ) );
		}
		echo '</select></label>';
		echo '<button class="pc-btn is-primary">' . esc_html__( 'Envia el resultat', 'padelcamps' ) . '</button></form>';
	}
}
