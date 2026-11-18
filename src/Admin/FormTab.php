<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\RegistrationForm;
use PadelCamps\Frontend\RegistrationShortcode;

defined( 'ABSPATH' ) || exit;

/**
 * "Formulari" tab of a tournament: configure the public registration form
 * (texts, opening window, which fields to ask, availability slots, consents).
 */
final class FormTab {

	private TournamentRepository $repo;

	public function __construct( TournamentRepository $repo ) {
		$this->repo = $repo;
	}

	public function register(): void {
		add_action( 'admin_post_padelcamps_save_form', array( $this, 'handle_save' ) );
	}

	private static function state_labels(): array {
		return array(
			'off'      => __( 'No es demana', 'padelcamps' ),
			'optional' => __( 'Opcional', 'padelcamps' ),
			'required' => __( 'Obligatori', 'padelcamps' ),
		);
	}

	private static function field_labels(): array {
		return array(
			'phone1' => __( 'Telèfon del jugador 1', 'padelcamps' ),
			'phone2' => __( 'Telèfon del jugador 2', 'padelcamps' ),
			'email2' => __( 'Correu del jugador 2', 'padelcamps' ),
			'club'   => __( 'Club', 'padelcamps' ),
			'notes'  => __( 'Observacions', 'padelcamps' ),
		);
	}

	public static function consent_labels(): array {
		return array(
			'data'   => __( 'Tractament de dades', 'padelcamps' ),
			'health' => __( 'Condició física i riscos', 'padelcamps' ),
			'image'  => __( 'Drets d’imatge', 'padelcamps' ),
		);
	}

	/** @return array<string, string> sub-page key => label (existing strings). */
	private static function subs(): array {
		return array(
			'texts'        => __( 'Textos', 'padelcamps' ),
			'window'       => __( 'Obertura i avisos', 'padelcamps' ),
			'fields'       => __( 'Camps del formulari', 'padelcamps' ),
			'pack'         => __( 'Welcome pack', 'padelcamps' ),
			'availability' => __( 'Disponibilitat', 'padelcamps' ),
			'consents'     => __( 'Consentiments', 'padelcamps' ),
		);
	}

	private function pane_open( string $key, string $current ): void {
		echo '<div class="pc-pane" data-pane="' . esc_attr( $key ) . '"' . ( $key === $current ? '' : ' hidden' ) . '>';
	}

	public function render( array $t ): void {
		$tid    = (int) $t['id'];
		$subs   = self::subs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, whitelisted.
		$sub    = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : '';
		$sub    = isset( $subs[ $sub ] ) ? $sub : 'texts';
		$config = $this->repo->form_config( $t );
		$regs   = $this->repo->registrations_count( $tid );

		echo '<h2>' . esc_html__( 'Formulari d’inscripció', 'padelcamps' ) . '</h2>';
		echo '<p>' . esc_html__( 'Enganxa aquest shortcode en una pàgina per mostrar el formulari:', 'padelcamps' ) . ' ';
		echo '<code>[' . esc_html( RegistrationShortcode::TAG ) . ' id="' . esc_html( (string) $tid ) . '"]</code></p>';
		echo '<p class="description">' . esc_html__( 'El formulari només accepta inscripcions quan el torneig és a «Inscripcions obertes» i dins de la finestra d’obertura, si n’has definit una.', 'padelcamps' ) . '</p>';
		/* translators: %d: number of registrations */
		echo '<p>' . esc_html( sprintf( __( 'Inscripcions rebudes fins ara: %d', 'padelcamps' ), $regs ) ) . '</p>';

		// One form for every sub-page: panes are only shown/hidden, so nothing typed is lost when switching.
		$items = array();
		foreach ( $subs as $key => $label ) {
			$items[] = array(
				'label'   => $label,
				'url'     => TournamentsPage::url( array( 'action' => 'edit', 'id' => $tid, 'tab' => 'form', 'sub' => $key ) ),
				'current' => $key === $sub,
				'key'     => $key,
			);
		}
		SubNav::render( $items, __( 'Formulari', 'padelcamps' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="pc-form-config">';
		echo '<input type="hidden" name="action" value="padelcamps_save_form">';
		echo '<input type="hidden" name="sub" id="pc-form-sub" value="' . esc_attr( $sub ) . '">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $tid ) . '">';
		wp_nonce_field( 'padelcamps_save_form' );

		$panes = array(
			'texts'        => fn() => $this->section_texts( $config ),
			'window'       => fn() => $this->section_window( $config ),
			'fields'       => fn() => $this->section_fields( $config ),
			'pack'         => fn() => $this->section_pack( $config ),
			'availability' => fn() => $this->section_availability( $t, $config ),
			'consents'     => fn() => $this->section_consents( $config ),
		);
		foreach ( $panes as $key => $draw ) {
			$this->pane_open( $key, $sub );
			$draw();
			echo '</div>';
		}

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Desa el formulari', 'padelcamps' ) . '</button></p>';
		echo '</form>';
		$this->panes_script();
	}

	/** Switch sub-pages without reloading (keeps unsaved edits) and remember the current one in the URL. */
	private function panes_script(): void {
		?>
<script>
(function () {
	var form = document.getElementById('pc-form-config');
	var links = document.querySelectorAll('.pc-subnav__item[data-sub]');
	if (!form || !links.length) { return; }
	var hidden = document.getElementById('pc-form-sub');
	function show(key) {
		form.querySelectorAll('.pc-pane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== key; });
		links.forEach(function (a) {
			var on = a.getAttribute('data-sub') === key;
			a.classList.toggle('is-current', on);
			if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
		});
		if (hidden) { hidden.value = key; }
	}
	links.forEach(function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var key = a.getAttribute('data-sub');
			show(key);
			if (window.history && history.replaceState) { history.replaceState(null, '', a.href); }
		});
	});
})();
</script>
		<?php
	}

	private function textarea_row( string $name, string $label, string $value, string $help = '', int $rows = 3 ): void {
		echo '<tr><th scope="row"><label for="pc-f-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf( '<textarea id="pc-f-%1$s" name="form[%1$s]" rows="%2$d" class="large-text">%3$s</textarea>', esc_attr( $name ), (int) $rows, esc_textarea( $value ) );
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function section_texts( array $c ): void {
		echo '<h3>' . esc_html__( 'Textos', 'padelcamps' ) . '</h3><table class="form-table" role="presentation">';
		$this->textarea_row( 'intro', __( 'Text d’introducció', 'padelcamps' ), $c['intro'], __( 'Es mostra damunt del formulari (normativa, dates, informació útil).', 'padelcamps' ) );
		$this->textarea_row( 'payment_instructions', __( 'Instruccions de pagament', 'padelcamps' ), $c['payment_instructions'], __( 'Es mostren en acabar la inscripció i al correu de confirmació quan la categoria té preu. Per exemple: IBAN, Bizum o pagament al club.', 'padelcamps' ) );
		$this->textarea_row( 'success_message', __( 'Missatge de confirmació', 'padelcamps' ), $c['success_message'], __( 'Text addicional que es mostra quan la inscripció s’ha enviat correctament.', 'padelcamps' ) );
		echo '</table>';
	}

	private function section_window( array $c ): void {
		echo '<h3>' . esc_html__( 'Obertura i avisos', 'padelcamps' ) . '</h3><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="pc-f-opens_at">' . esc_html__( 'Les inscripcions s’obren', 'padelcamps' ) . '</label></th><td>';
		printf( '<input type="datetime-local" id="pc-f-opens_at" name="form[opens_at]" value="%s"></td></tr>', esc_attr( $c['opens_at'] ) );
		echo '<tr><th scope="row"><label for="pc-f-closes_at">' . esc_html__( 'Les inscripcions es tanquen', 'padelcamps' ) . '</label></th><td>';
		printf( '<input type="datetime-local" id="pc-f-closes_at" name="form[closes_at]" value="%s"><p class="description">%s</p></td></tr>', esc_attr( $c['closes_at'] ), esc_html__( 'Deixa-ho buit per no limitar-ho per data.', 'padelcamps' ) );
		echo '<tr><th scope="row"><label for="pc-f-notify_email">' . esc_html__( 'Correu per als avisos', 'padelcamps' ) . '</label></th><td>';
		printf( '<input type="email" id="pc-f-notify_email" name="form[notify_email]" value="%s" class="regular-text" placeholder="%s"><p class="description">%s</p></td></tr>', esc_attr( $c['notify_email'] ), esc_attr( (string) get_option( 'admin_email' ) ), esc_html__( 'Rep un avís per cada inscripció nova. Si el deixes buit, s’usa el correu de l’administrador del web.', 'padelcamps' ) );
		echo '</table>';
	}

	private function section_fields( array $c ): void {
		echo '<h3>' . esc_html__( 'Camps del formulari', 'padelcamps' ) . '</h3>';
		echo '<h4>' . esc_html__( 'Camps sempre obligatoris', 'padelcamps' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'No es poden canviar: el DNI o NIE és la identitat del jugador a tots els tornejos i al rànquing.', 'padelcamps' ) . '</p>';
		echo '<table class="widefat striped pc-narrow pc-fixed-fields"><tbody>';
		$fixed = array(
			__( 'Categoria', 'padelcamps' ),
			__( 'Nom del jugador 1', 'padelcamps' ),
			__( 'Correu del jugador 1', 'padelcamps' ),
			__( 'Nom del jugador 2', 'padelcamps' ),
			__( 'DNI o NIE dels dos jugadors', 'padelcamps' ),
		);
		foreach ( $fixed as $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td><span class="pc-badge pc-badge--muted"><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html__( 'Obligatori', 'padelcamps' ) . '</span></td></tr>';
		}
		echo '</tbody></table>';
		echo '<h4>' . esc_html__( 'Camps configurables', 'padelcamps' ) . '</h4>';
		echo '<table class="form-table" role="presentation">';
		foreach ( self::field_labels() as $key => $label ) {
			echo '<tr><th scope="row"><label for="pc-f-field-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<select id="pc-f-field-' . esc_attr( $key ) . '" name="form[fields][' . esc_attr( $key ) . ']">';
			foreach ( self::state_labels() as $state => $state_label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $state ), selected( $c['fields'][ $key ], $state, false ), esc_html( $state_label ) );
			}
			echo '</select></td></tr>';
		}
		echo '</table>';
	}

	private function section_pack( array $c ): void {
		$pack  = $c['welcome_pack'];
		$types = array(
			'select' => __( 'Desplegable', 'padelcamps' ),
			'text'   => __( 'Text lliure', 'padelcamps' ),
		);

		echo '<h3>' . esc_html__( 'Welcome pack', 'padelcamps' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Cada torneig té el seu welcome pack. Escriu-ne el contingut i, si cada jugador ha de triar alguna cosa (talla, color…), afegeix-hi opcions.', 'padelcamps' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Mostra el welcome pack', 'padelcamps' ) . '</th><td><label>';
		printf( '<input type="checkbox" name="form[welcome_pack][enabled]" value="1"%s> ', checked( ! empty( $pack['enabled'] ), true, false ) );
		echo esc_html__( 'Mostra’l al formulari', 'padelcamps' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="pc-f-pack-title">' . esc_html__( 'Títol', 'padelcamps' ) . '</label></th><td>';
		printf( '<input type="text" id="pc-f-pack-title" name="form[welcome_pack][title]" value="%s" class="regular-text" maxlength="80" placeholder="%s"></td></tr>', esc_attr( $pack['title'] ), esc_attr__( 'Welcome pack', 'padelcamps' ) );
		echo '<tr><th scope="row"><label for="pc-f-pack-description">' . esc_html__( 'Contingut', 'padelcamps' ) . '</label></th><td>';
		printf( '<textarea id="pc-f-pack-description" name="form[welcome_pack][description]" rows="4" class="large-text">%s</textarea><p class="description">%s</p></td></tr>', esc_textarea( $pack['description'] ), esc_html__( 'Text lliure que veuran els inscrits. Per exemple: «Dessuadora, 2 boles de pàdel i ampolla d’aigua».', 'padelcamps' ) );
		echo '</table>';

		echo '<p><strong>' . esc_html__( 'Opcions que ha de triar cada jugador', 'padelcamps' ) . '</strong></p>';
		echo '<table class="widefat" id="pc-pack-items"><thead><tr>';
		echo '<th>' . esc_html__( 'Nom de l’opció', 'padelcamps' ) . '</th><th>' . esc_html__( 'Tipus', 'padelcamps' ) . '</th><th>' . esc_html__( 'Valors possibles', 'padelcamps' ) . '</th><th>' . esc_html__( 'Obligatori', 'padelcamps' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $pack['items'] as $i => $item ) {
			$this->pack_row( (string) $i, $item, $types );
		}
		echo '</tbody></table>';
		echo '<template id="pc-pack-template">';
		$this->pack_row( '__i__', array( 'label' => '', 'type' => 'select', 'options' => array(), 'required' => false ), $types );
		echo '</template>';
		echo '<p class="description">' . esc_html__( 'Valors separats per comes, per exemple XS, S, M, L, XL. Només per als desplegables.', 'padelcamps' ) . '</p>';
		echo '<p><button type="button" class="button" id="pc-pack-add">' . esc_html__( 'Afegeix una opció', 'padelcamps' ) . '</button></p>';
		$this->repeater_script( 'pc-pack-items', 'pc-pack-template', 'pc-pack-add', 'pc-pack-remove' );
	}

	private function pack_row( string $i, array $item, array $types ): void {
		$n = esc_attr( $i );
		echo '<tr>';
		printf( '<td><input type="text" name="form[welcome_pack][items][%s][label]" value="%s" maxlength="80" placeholder="%s"></td>', $n, esc_attr( $item['label'] ), esc_attr__( 'Talla de dessuadora', 'padelcamps' ) );
		echo '<td><select name="form[welcome_pack][items][' . $n . '][type]">';
		foreach ( $types as $type => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $item['type'], $type, false ), esc_html( $text ) );
		}
		echo '</select></td>';
		printf( '<td><input type="text" name="form[welcome_pack][items][%s][options]" value="%s" placeholder="XS, S, M, L, XL"></td>', $n, esc_attr( implode( ', ', $item['options'] ) ) );
		printf( '<td><input type="checkbox" name="form[welcome_pack][items][%s][required]" value="1"%s></td>', $n, checked( ! empty( $item['required'] ), true, false ) );
		echo '<td><button type="button" class="button-link button-link-delete pc-pack-remove">' . esc_html__( 'Elimina', 'padelcamps' ) . '</button></td>';
		echo '</tr>';
	}

	/** Minimal add/remove rows behaviour for a table whose rows come from a <template>. */
	private function repeater_script( string $table_id, string $template_id, string $add_id, string $remove_class ): void {
		?>
<script>
(function () {
	var body = document.querySelector('#<?php echo esc_js( $table_id ); ?> tbody');
	var tpl = document.getElementById('<?php echo esc_js( $template_id ); ?>');
	var add = document.getElementById('<?php echo esc_js( $add_id ); ?>');
	if (!body || !tpl || !add) { return; }
	var next = body.querySelectorAll('tr').length + 1000;
	add.addEventListener('click', function () {
		var holder = document.createElement('tbody');
		holder.innerHTML = tpl.innerHTML.replace(/__i__/g, String(next++));
		body.appendChild(holder.querySelector('tr'));
	});
	body.addEventListener('click', function (e) {
		if (e.target.classList.contains('<?php echo esc_js( $remove_class ); ?>')) { e.target.closest('tr').remove(); }
	});
})();
</script>
		<?php
	}

	private function section_availability( array $t, array $c ): void {
		$av = $c['availability'];
		echo '<h3>' . esc_html__( 'Disponibilitat', 'padelcamps' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Cada parella indica en quines franges pot jugar. Més endavant servirà per generar els horaris dels partits.', 'padelcamps' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Demana la disponibilitat', 'padelcamps' ) . '</th><td><label>';
		printf( '<input type="checkbox" name="form[availability][enabled]" value="1"%s> ', checked( ! empty( $av['enabled'] ), true, false ) );
		echo esc_html__( 'Mostra la graella de disponibilitat al formulari', 'padelcamps' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="pc-f-av-help">' . esc_html__( 'Normes de disponibilitat', 'padelcamps' ) . '</label></th><td>';
		printf( '<textarea id="pc-f-av-help" name="form[availability][help]" rows="3" class="large-text">%s</textarea><p class="description">%s</p></td></tr>', esc_textarea( $av['help'] ), esc_html__( 'Per exemple: «Com a màxim es pot no estar disponible 2 dies».', 'padelcamps' ) );
		echo '</table>';

		$modes = array(
			'yesno' => __( 'Disponible / No disponible', 'padelcamps' ),
			'from'  => __( 'A partir d’una hora', 'padelcamps' ),
		);

		echo '<table class="widefat" id="pc-slots"><thead><tr>';
		echo '<th>' . esc_html__( 'Dia', 'padelcamps' ) . '</th><th>' . esc_html__( 'Etiqueta', 'padelcamps' ) . '</th><th>' . esc_html__( 'Tipus de resposta', 'padelcamps' ) . '</th><th>' . esc_html__( 'Hores', 'padelcamps' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $av['slots'] as $i => $slot ) {
			$this->slot_row( (string) $i, $slot, $modes );
		}
		echo '</tbody></table>';
		echo '<template id="pc-slot-template">';
		$this->slot_row( '__i__', array( 'date' => '', 'label' => '', 'mode' => 'yesno', 'times' => array() ), $modes );
		echo '</template>';
		echo '<p class="description">' . esc_html__( 'Hores: separades per comes, per exemple 18:00, 19:00. Posa un = davant d’una hora perquè sigui «només a aquesta hora» (=22:15).', 'padelcamps' ) . '</p>';
		printf(
			'<p><button type="button" class="button" id="pc-slot-add">%1$s</button> <button type="button" class="button" id="pc-slot-days" data-start="%2$s" data-end="%3$s" data-morning="%4$s" data-afternoon="%5$s">%6$s</button></p>',
			esc_html__( 'Afegeix una franja', 'padelcamps' ),
			esc_attr( (string) $t['starts_on'] ),
			esc_attr( (string) ( $t['ends_on'] ?: $t['starts_on'] ) ),
			esc_attr__( 'Matí', 'padelcamps' ),
			esc_attr__( 'Tarda', 'padelcamps' ),
			esc_html__( 'Omple amb els dies del torneig (matí i tarda)', 'padelcamps' )
		);
		$this->slots_script();
	}

	private function slot_row( string $i, array $slot, array $modes ): void {
		$n = esc_attr( $i );
		echo '<tr>';
		printf( '<td><input type="date" name="form[availability][slots][%s][date]" value="%s"></td>', $n, esc_attr( $slot['date'] ) );
		printf( '<td><input type="text" name="form[availability][slots][%s][label]" value="%s" maxlength="60"></td>', $n, esc_attr( $slot['label'] ) );
		echo '<td><select name="form[availability][slots][' . $n . '][mode]">';
		foreach ( $modes as $mode => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $mode ), selected( $slot['mode'], $mode, false ), esc_html( $text ) );
		}
		echo '</select></td>';
		printf( '<td><input type="text" name="form[availability][slots][%s][times]" value="%s" placeholder="18:00, 19:00, =22:15"></td>', $n, esc_attr( implode( ', ', $slot['times'] ) ) );
		echo '<td><button type="button" class="button-link button-link-delete pc-slot-remove">' . esc_html__( 'Elimina', 'padelcamps' ) . '</button></td>';
		echo '</tr>';
	}

	private function slots_script(): void {
		?>
<script>
(function () {
	var body = document.querySelector('#pc-slots tbody');
	var tpl = document.getElementById('pc-slot-template');
	if (!body || !tpl) { return; }
	var next = body.querySelectorAll('tr').length + 1000;

	function addRow(date, label) {
		var html = tpl.innerHTML.replace(/__i__/g, String(next++));
		var holder = document.createElement('tbody');
		holder.innerHTML = html;
		var row = holder.querySelector('tr');
		if (date) { row.querySelector('input[type=date]').value = date; }
		if (label) { row.querySelector('input[type=text]').value = label; }
		body.appendChild(row);
	}
	document.getElementById('pc-slot-add').addEventListener('click', function () { addRow('', ''); });
	body.addEventListener('click', function (e) {
		if (e.target.classList.contains('pc-slot-remove')) { e.target.closest('tr').remove(); }
	});
	document.getElementById('pc-slot-days').addEventListener('click', function () {
		var s = this.dataset.start, e = this.dataset.end;
		if (!s) { return; }
		var d = new Date(s + 'T12:00:00'), end = new Date((e || s) + 'T12:00:00'), guard = 0;
		while (d <= end && guard++ < 31) {
			var iso = d.toISOString().slice(0, 10);
			addRow(iso, this.dataset.morning);
			addRow(iso, this.dataset.afternoon);
			d.setDate(d.getDate() + 1);
		}
	});
})();
</script>
		<?php
	}

	private function section_consents( array $c ): void {
		echo '<h3>' . esc_html__( 'Consentiments', 'padelcamps' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Si deixes el text buit, s’usa el text per defecte (que es tradueix a l’idioma del visitant). Si l’escrius, es mostrarà tal qual.', 'padelcamps' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		foreach ( self::consent_labels() as $key => $label ) {
			$consent = $c['consents'][ $key ];
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			echo '<label><input type="checkbox" name="form[consents][' . esc_attr( $key ) . '][enabled]" value="1"' . checked( ! empty( $consent['enabled'] ), true, false ) . '> ' . esc_html__( 'Mostra’l', 'padelcamps' ) . '</label> &nbsp; ';
			echo '<label><input type="checkbox" name="form[consents][' . esc_attr( $key ) . '][required]" value="1"' . checked( ! empty( $consent['required'] ), true, false ) . '> ' . esc_html__( 'Obligatori', 'padelcamps' ) . '</label>';
			printf(
				'<textarea name="form[consents][%s][text]" rows="3" class="large-text" placeholder="%s">%s</textarea>',
				esc_attr( $key ),
				esc_attr( RegistrationShortcode::default_consent_text( $key ) ),
				esc_textarea( $consent['text'] )
			);
			echo '</td></tr>';
		}
		echo '<tr><th scope="row"><label for="pc-f-privacy_url">' . esc_html__( 'Enllaç a la política de privacitat', 'padelcamps' ) . '</label></th><td>';
		printf( '<input type="url" id="pc-f-privacy_url" name="form[privacy_url]" value="%s" class="regular-text" placeholder="%s"><p class="description">%s</p></td></tr>', esc_attr( $c['privacy_url'] ), esc_attr( (string) get_privacy_policy_url() ), esc_html__( 'Si el deixes buit, s’usa la pàgina de privacitat de WordPress, si n’hi ha.', 'padelcamps' ) );
		echo '</table>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( 'padelcamps_save_form' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id || ! $this->repo->find( $id ) ) {
			wp_safe_redirect( TournamentsPage::url( array( 'msg' => 'not_found' ) ) );
			exit;
		}
		$raw = isset( $_POST['form'] ) && is_array( $_POST['form'] ) ? wp_unslash( $_POST['form'] ) : array();
		// phpcs:enable

		$this->repo->save_form_config( $id, RegistrationForm::normalize( $raw ) );

		$sub  = isset( $_POST['sub'] ) ? sanitize_key( wp_unslash( $_POST['sub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$args = array( 'action' => 'edit', 'id' => $id, 'tab' => 'form', 'msg' => 'form_saved' );
		if ( isset( self::subs()[ $sub ] ) ) {
			$args['sub'] = $sub;
		}
		wp_safe_redirect( TournamentsPage::url( $args ) );
		exit;
	}
}
