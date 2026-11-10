<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\TournamentStatus;
use PadelCamps\Frontend\DrawShortcode;
use PadelCamps\Frontend\RegistrationShortcode;
use PadelCamps\Frontend\ScheduleShortcode;
use PadelCamps\Frontend\TvPage;

defined( 'ABSPATH' ) || exit;

/**
 * "Públic" tab: everything needed to publish a tournament (shortcodes, blocks,
 * club TV screen, public API), ready to copy.
 */
final class PublicTab {

	private TournamentRepository $tournaments;

	public function __construct( TournamentRepository $tournaments ) {
		$this->tournaments = $tournaments;
	}

	private static function code( string $text ): string {
		return '<input type="text" readonly class="large-text code" value="' . esc_attr( $text ) . '" onclick="this.select()">';
	}

	public function render( array $t ): void {
		$tid        = (int) $t['id'];
		$categories = $this->tournaments->categories( $tid );

		if ( ! TournamentStatus::is_public( $t['status'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'El torneig és en esborrany: les pàgines públiques, la pantalla de TV i l’API no el mostraran fins que el passis a «Inscripcions obertes».', 'padelcamps' ) . '</p></div>';
		}

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Quadres i classificacions', 'padelcamps' ) . '</th><td>';
		echo self::code( '[' . DrawShortcode::TAG . ' id="' . $tid . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in code().
		foreach ( $categories as $c ) {
			/* translators: %s: category name */
			echo '<p class="description">' . esc_html( sprintf( __( 'Només %s:', 'padelcamps' ), $c['name'] ) ) . '</p>' . self::code( '[' . DrawShortcode::TAG . ' id="' . $tid . '" category="' . (int) $c['id'] . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p class="description">' . esc_html__( 'Es refresca sol cada 30 segons i inclou el cercador «Busca la teva parella». Afegeix live="0" o find="0" per desactivar-los.', 'padelcamps' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Ordre de joc', 'padelcamps' ) . '</th><td>';
		echo self::code( '[' . ScheduleShortcode::TAG . ' id="' . $tid . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">' . esc_html__( 'Partits per dia, hora i pista, amb els resultats. Admet també category="…".', 'padelcamps' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Formulari d’inscripció', 'padelcamps' ) . '</th><td>';
		echo self::code( '[' . RegistrationShortcode::TAG . ' id="' . $tid . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Blocs de l’editor', 'padelcamps' ) . '</th><td><p>' . esc_html__( 'A l’editor de blocs de WordPress, cerca «PadelCamps»: hi ha un bloc per als quadres, un per a l’ordre de joc i un per al formulari. A Elementor, fes servir el widget «Shortcode» amb els codis d’aquesta pàgina.', 'padelcamps' ) . '</p></td></tr>';

		$tv = TvPage::url( $tid );
		echo '<tr><th scope="row">' . esc_html__( 'Pantalla per al club (TV)', 'padelcamps' ) . '</th><td>';
		echo self::code( $tv ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><a class="button" href="' . esc_url( $tv ) . '" target="_blank" rel="noopener">' . esc_html__( 'Obre la pantalla', 'padelcamps' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Obre-la al navegador de la TV i posa-la a pantalla completa (F11). Mostra el rellotge, els partits d’ara i els propers, i va passant per les categories. S’actualitza sola.', 'padelcamps' ) . '</p></td></tr>';

		$api = rest_url( 'padelcamps/v1/tournaments/' . $tid );
		echo '<tr><th scope="row">' . esc_html__( 'API pública (JSON)', 'padelcamps' ) . '</th><td>';
		echo self::code( $api . '/draw' ) . self::code( $api . '/schedule' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">' . esc_html__( 'Per connectar una altra web o aplicació. Només dades públiques: mai correus, telèfons ni pagaments.', 'padelcamps' ) . '</p></td></tr>';

		$mode = $this->tournaments->public_names( $t );
		echo '<tr><th scope="row">' . esc_html__( 'Noms als quadres públics', 'padelcamps' ) . '</th><td><p>';
		echo esc_html( 'initials' === $mode ? __( 'Nom i inicial del primer cognom (Anna P.)', 'padelcamps' ) : __( 'Nom i cognoms complets', 'padelcamps' ) );
		echo ' — <a href="' . esc_url( TournamentsPage::url( array( 'action' => 'edit', 'id' => $tid, 'tab' => 'config' ) ) ) . '">' . esc_html__( 'Canvia-ho a Configuració', 'padelcamps' ) . '</a></p></td></tr>';

		echo '</table>';
	}
}
