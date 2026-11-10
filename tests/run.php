<?php
/**
 * Standalone engine tests (no PHPUnit/WordPress needed).
 * Run: php tests/run.php
 */
declare( strict_types=1 );

require_once __DIR__ . '/../src/autoload.php';

use PadelCamps\Engine\Bracket;
use PadelCamps\Engine\Draw;
use PadelCamps\Engine\SeededRandom;

$fail  = 0;
$total = 0;

function check( string $name, bool $ok ): void {
	global $fail, $total;
	++$total;
	if ( ! $ok ) {
		++$fail;
		echo "FAIL: $name\n";
	}
}

// --- SeededRandom ---
$a = ( new SeededRandom( 'x' ) )->shuffle( range( 1, 20 ) );
$b = ( new SeededRandom( 'x' ) )->shuffle( range( 1, 20 ) );
$c = ( new SeededRandom( 'y' ) )->shuffle( range( 1, 20 ) );
check( 'shuffle deterministic', $a === $b );
check( 'shuffle differs by seed', $a !== $c );
check( 'shuffle keeps elements', count( $a ) === 20 && array_sum( $a ) === 210 );

// --- Bracket seed order ---
check( 'seed order 4', Bracket::seed_order( 4 ) === array( 1, 4, 2, 3 ) );
check( 'seed order 8', Bracket::seed_order( 8 ) === array( 1, 8, 4, 5, 2, 7, 3, 6 ) );
check( 'size_for', Bracket::size_for( 5 ) === 8 && Bracket::size_for( 8 ) === 8 && Bracket::size_for( 9 ) === 16 );

// --- Bracket, no byes (8) ---
$m8 = Bracket::generate( 8 );
check( '8 entrants => 7 matches', count( $m8 ) === 7 );
check( '8 entrants => no byes', 0 === count( array_filter( $m8, fn( $m ) => $m['is_bye'] ) ) );

// --- Bracket with byes (5 => size 8, 3 byes for seeds 1,2,3) ---
$m5   = Bracket::generate( 5 );
$byes = array_values( array_filter( $m5, fn( $m ) => $m['is_bye'] ) );
check( '5 entrants => 7 matches', count( $m5 ) === 7 );
check( '5 entrants => 3 byes', count( $byes ) === 3 );
$bye_seeds = array_map( fn( $m ) => $m['seed_a'] ?? $m['seed_b'], $byes );
sort( $bye_seeds );
check( 'byes go to seeds 1,2,3', $bye_seeds === array( 1, 2, 3 ) );
$r2 = array_values( array_filter( $m5, fn( $m ) => 2 === $m['round'] ) );
check( 'bye seeds advanced to round 2', null !== $r2[0]['seed_a'] && null !== $r2[1]['seed_a'] );

// --- Bracket: seeds 1 and 2 are in opposite halves (only meet in the final) ---
$m16  = Bracket::generate( 16 );
$r1   = array_values( array_filter( $m16, fn( $m ) => 1 === $m['round'] ) );
$half = fn( $seed ) => (int) floor( array_search( $seed, array_merge( ...array_map( fn( $m ) => array( $m['seed_a'], $m['seed_b'] ), $r1 ) ), true ) / 8 );
check( 'seeds 1 & 2 in different halves', $half( 1 ) !== $half( 2 ) );

// --- Bracket errors ---
$threw = false;
try {
	Bracket::generate( 1 );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
check( '1 entrant throws', $threw );

// --- Draw ---
$teams = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$teams[] = array( 'id' => $i, 'rating' => (float) $i, 'club' => null );
}
$d1 = Draw::seed_teams( $teams, 4, 'torneig-1' );
$d2 = Draw::seed_teams( $teams, 4, 'torneig-1' );
check( 'draw deterministic', $d1 === $d2 );
check( 'top 4 seeded by rating', array_column( array_slice( $d1, 0, 4 ), 'id' ) === array( 12, 11, 10, 9 ) );
check( 'seed numbers 1..12', array_column( $d1, 'seed' ) === range( 1, 12 ) );

$g = Draw::groups( $teams, 4, 'torneig-1' );
check( '4 groups', count( $g ) === 4 );
check( 'groups of 3', array_unique( array_map( 'count', $g ) ) === array( 3 ) );
check( 'groups deterministic', $g === Draw::groups( $teams, 4, 'torneig-1' ) );
$sums = array_map( fn( $grp ) => array_sum( array_column( $grp, 'rating' ) ), $g );
check( 'groups balanced (spread <= 6)', ( max( $sums ) - min( $sums ) ) <= 6 );
$gu = Draw::groups( array_slice( $teams, 0, 10 ), 4, 's' );
$counts = array_map( 'count', $gu );
check( 'uneven groups differ by <= 1', ( max( $counts ) - min( $counts ) ) <= 1 );

// --- TournamentStatus ---
use PadelCamps\Engine\FormatTemplates;
use PadelCamps\Engine\TournamentStatus;

check( 'status order', TournamentStatus::all()[0] === 'draft' && TournamentStatus::all()[5] === 'finished' );
check( 'status next', TournamentStatus::next( 'draft' ) === 'registration_open' && TournamentStatus::next( 'in_progress' ) === 'finished' );
check( 'status next of last is null', TournamentStatus::next( 'finished' ) === null );
check( 'status prev', TournamentStatus::prev( 'drawn' ) === 'registration_closed' && TournamentStatus::prev( 'draft' ) === null );
check( 'status unknown', TournamentStatus::next( 'bogus' ) === null && ! TournamentStatus::is_valid( 'bogus' ) );
check( 'draft is not public', ! TournamentStatus::is_public( 'draft' ) && TournamentStatus::is_public( 'registration_open' ) );

// --- FormatTemplates ---
$ph = FormatTemplates::build_phases( 'groups_knockout', array( 'advance' => 3, 'consolation' => true ) );
check( 'groups_knockout has 2 phases', count( $ph ) === 2 && $ph[0]['type'] === 'groups' && $ph[1]['type'] === 'knockout' );
check( 'groups advance applied', $ph[0]['settings'] === array( 'advance' => 3 ) );
check( 'consolation applied', $ph[1]['settings'] === array( 'consolation' => true ) );
check( 'phase sort order', $ph[0]['sort_order'] === 1 && $ph[1]['sort_order'] === 2 );
$ph = FormatTemplates::build_phases( 'groups_knockout', array( 'advance' => 99 ) );
check( 'advance clamped to 4', $ph[0]['settings']['advance'] === 4 );
check( 'default advance 2, no consolation', FormatTemplates::build_phases( 'groups_knockout' )[0]['settings']['advance'] === 2 && FormatTemplates::build_phases( 'groups_knockout' )[1]['settings']['consolation'] === false );
check( 'round robin ignores options', FormatTemplates::build_phases( 'round_robin', array( 'advance' => 3, 'consolation' => true ) )[0]['settings'] === array() );
check( 'knockout ignores advance', FormatTemplates::build_phases( 'knockout', array( 'advance' => 3 ) )[0]['settings'] === array( 'consolation' => false ) );
check( 'americana unavailable', ! FormatTemplates::is_available( 'americana' ) && FormatTemplates::all()['americana']['registration_mode'] === 'individual' );
$threw = false;
try {
	FormatTemplates::build_phases( 'americana' );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
check( 'building unavailable template throws', $threw );
$threw = false;
try {
	FormatTemplates::build_phases( 'nope' );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
check( 'building unknown template throws', $threw );

// --- RegistrationForm ---
use PadelCamps\Engine\RegistrationForm as RF;

$d = RF::defaults();
check( 'defaults: consents data+health required', $d['consents']['data']['required'] && $d['consents']['health']['required'] && ! $d['consents']['image']['enabled'] );
check( 'normalize empty keeps defaults', RF::normalize( array() ) === $d );
$n = RF::normalize( array( 'fields' => array( 'dni' => 'required', 'club' => 'bogus', 'phone2' => 'off' ), 'notify_email' => 'not-an-email', 'opens_at' => '2026-12-01T09:00', 'closes_at' => 'bad', 'privacy_url' => 'javascript:alert(1)' ) );
check( 'normalize: valid state applied', $n['fields']['dni'] === 'required' && $n['fields']['phone2'] === 'off' );
check( 'normalize: invalid state ignored', $n['fields']['club'] === 'off' );
check( 'defaults: welcome pack disabled and empty', $d['welcome_pack'] === array( 'enabled' => false, 'title' => '', 'description' => '', 'items' => array() ) && ! isset( $d['shirt_sizes'] ) && ! isset( $d['fields']['shirt'] ) );

// Welcome pack: free-form, written per tournament.
$wp = RF::normalize( array( 'welcome_pack' => array(
	'enabled'     => '1',
	'title'       => '  <b>Welcome</b> Pack ',
	'description' => "Dessuadora\n\n\n\n2 boles",
	'items'       => array(
		array( 'label' => 'Talla de dessuadora', 'type' => 'select', 'options' => 'XS, S, M,  L,M ,', 'required' => '1' ),
		array( 'label' => 'Color de gorra', 'type' => 'select', 'options' => '' ),
		array( 'label' => 'Al·lèrgies', 'type' => 'text', 'options' => 'ignored' ),
		array( 'label' => '   ', 'type' => 'text' ),
		'not-an-array',
		array( 'label' => 'Tipus raro', 'type' => 'bogus', 'options' => 'A, B' ),
	),
) ) )['welcome_pack'];
check( 'pack: enabled + title cleaned', $wp['enabled'] === true && $wp['title'] === 'Welcome Pack' );
check( 'pack: description keeps paragraphs, collapses blank lines', $wp['description'] === "Dessuadora\n\n2 boles" );
check( 'pack: empty label / non-array skipped', count( $wp['items'] ) === 4 );
check( 'pack: select options parsed + deduped', $wp['items'][0]['options'] === array( 'XS', 'S', 'M', 'L' ) && $wp['items'][0]['required'] === true );
check( 'pack: select without options becomes text', $wp['items'][1]['type'] === 'text' && $wp['items'][1]['options'] === array() );
check( 'pack: text item drops options', $wp['items'][2]['type'] === 'text' && $wp['items'][2]['options'] === array() && $wp['items'][2]['required'] === false );
check( 'pack: unknown type falls back to select', $wp['items'][3]['type'] === 'select' && $wp['items'][3]['options'] === array( 'A', 'B' ) );
$many = array_fill( 0, 15, array( 'label' => 'X', 'type' => 'text' ) );
check( 'pack: capped at MAX_PACK items', count( RF::normalize( array( 'welcome_pack' => array( 'enabled' => 1, 'items' => $many ) ) )['welcome_pack']['items'] ) === RF::MAX_PACK );
// Legacy configs (fixed shirt size) are migrated into a pack item.
$legacy = RF::normalize( array( 'fields' => array( 'shirt' => 'required' ), 'shirt_sizes' => 'S, M, L' ) )['welcome_pack'];
check( 'legacy: shirt becomes a required pack item', $legacy['enabled'] && $legacy['items'][0]['label'] === 'Talla de dessuadora' && $legacy['items'][0]['options'] === array( 'S', 'M', 'L' ) && $legacy['items'][0]['required'] === true );
check( 'legacy: optional shirt => not required', RF::normalize( array( 'fields' => array( 'shirt' => 'optional' ) ) )['welcome_pack']['items'][0]['required'] === false );
check( 'legacy: shirt off => no pack', RF::normalize( array( 'fields' => array( 'shirt' => 'off' ) ) )['welcome_pack']['enabled'] === false );
check( 'legacy: explicit pack wins over legacy keys', RF::normalize( array( 'fields' => array( 'shirt' => 'required' ), 'welcome_pack' => array( 'enabled' => false ) ) )['welcome_pack']['items'] === array() );
check( 'normalize is idempotent', RF::normalize( RF::normalize( array( 'welcome_pack' => $wp ) ) ) === RF::normalize( array( 'welcome_pack' => $wp ) ) );
check( 'normalize: bad email dropped', $n['notify_email'] === '' );
check( 'normalize: window dates', $n['opens_at'] === '2026-12-01T09:00' && $n['closes_at'] === '' );
check( 'normalize: javascript: url rejected', $n['privacy_url'] === '' );
check( 'normalize: https url kept', RF::normalize( array( 'privacy_url' => 'https://x.cat/privacitat' ) )['privacy_url'] === 'https://x.cat/privacitat' );
check( 'normalize: html stripped', RF::normalize( array( 'intro' => '<script>x</script>Hola <b>món</b>' ) )['intro'] === 'xHola món' );

$s = RF::normalize( array( 'availability' => array( 'enabled' => '1', 'slots' => array(
	array( 'date' => '2026-12-05', 'label' => 'Matí', 'mode' => 'yesno' ),
	array( 'date' => '2026-12-06', 'label' => 'Tarda', 'mode' => 'from', 'times' => '18:00, 19:00, =22:15, 25:00, abc' ),
	array( 'date' => '2026-13-45', 'label' => 'Bad date' ),
	array( 'date' => '2026-12-07', 'mode' => 'from', 'times' => '' ),
	'not-an-array',
) ) ) );
$slots = $s['availability']['slots'];
check( 'slots: invalid date/non-array skipped', count( $slots ) === 3 );
check( 'slots: from times parsed', $slots[1]['times'] === array( '18:00', '19:00', '=22:15' ) );
check( 'slots: from without times falls back to yesno', $slots[2]['mode'] === 'yesno' );
check( 'slot options yesno', RF::slot_options( $slots[0] ) === array( 'yes', 'no' ) );
check( 'slot options from', RF::slot_options( $slots[1] ) === array( 'from:18:00', 'from:19:00', 'only:22:15', 'no' ) );

$cfg = RF::normalize( array(
	'fields'       => array( 'club' => 'optional', 'dni' => 'optional', 'notes' => 'optional' ),
	'welcome_pack' => array( 'enabled' => true, 'items' => array(
		array( 'label' => 'Talla de dessuadora', 'type' => 'select', 'options' => 'S, M, L', 'required' => true ),
		array( 'label' => 'Al·lèrgies', 'type' => 'text', 'required' => false ),
	) ),
	'availability' => array( 'enabled' => true, 'slots' => array(
		array( 'date' => '2026-12-05', 'label' => 'Matí', 'mode' => 'yesno' ),
		array( 'date' => '2026-12-06', 'label' => 'Tarda', 'mode' => 'from', 'times' => '18:00,19:00' ),
	) ),
) );
$good = array(
	'category_id' => '7',
	'p1_name' => '  Anna   Puig ', 'p1_email' => 'ANNA@Example.com', 'p1_phone' => '+34 600 111 222', 'p1_pack_0' => 'M', 'p1_pack_1' => '  Cacauets <i>molt</i> ',
	'p2_name' => 'Pere Soler', 'p2_email' => 'pere@example.com', 'p2_pack_0' => 'L', 'p2_dni' => '12345678z',
	'notes' => '<b>Prefereixo</b> matins',
	'av' => array( 'yes', 'from:19:00' ),
	'consent' => array( 'data' => '1', 'health' => '1' ),
);
$r = RF::validate( $cfg, array( 7, 8 ), $good );
check( 'validate: good submission has no errors', $r['errors'] === array() );
check( 'validate: names/email cleaned', $r['clean']['players'][0]['name'] === 'Anna Puig' && $r['clean']['players'][0]['email'] === 'anna@example.com' );
check( 'validate: dni uppercased', $r['clean']['players'][1]['dni'] === '12345678Z' );
check( 'validate: pack answers stored with their label', $r['clean']['players'][0]['pack'] === array( array( 'label' => 'Talla de dessuadora', 'value' => 'M' ), array( 'label' => 'Al·lèrgies', 'value' => 'Cacauets molt' ) ) );
check( 'validate: optional empty pack item stored as empty', $r['clean']['players'][1]['pack'][1] === array( 'label' => 'Al·lèrgies', 'value' => '' ) );
check( 'validate: notes stripped', $r['clean']['notes'] === 'Prefereixo matins' );
check( 'validate: availability stored self-contained', $r['clean']['availability'][1] === array( 'date' => '2026-12-06', 'label' => 'Tarda', 'value' => 'from:19:00' ) );
check( 'validate: consents stored', $r['clean']['consents'] === array( 'data' => true, 'health' => true ) );

$bad = RF::validate( $cfg, array( 7 ), array( 'category_id' => '99', 'p1_name' => '', 'p1_email' => 'nope', 'p1_phone' => 'abc', 'p2_name' => 'X', 'p2_email' => '', 'p1_pack_0' => 'XXXL', 'av' => array( 'maybe' ), 'consent' => array( 'data' => '1' ) ) );
$e = $bad['errors'];
check( 'validate: unknown category', ( $e['category_id'] ?? '' ) === 'required' );
check( 'validate: empty name required, short name invalid', ( $e['p1_name'] ?? '' ) === 'required' && ( $e['p2_name'] ?? '' ) === 'invalid' );
check( 'validate: invalid email/phone', ( $e['p1_email'] ?? '' ) === 'invalid_email' && ( $e['p1_phone'] ?? '' ) === 'invalid_phone' );
check( 'validate: required partner email', ( $e['p2_email'] ?? '' ) === 'required' );
check( 'validate: pack choice not in list / required missing', ( $e['p1_pack_0'] ?? '' ) === 'invalid_choice' && ( $e['p2_pack_0'] ?? '' ) === 'required' );
check( 'validate: optional pack text needs nothing', ! isset( $e['p1_pack_1'] ) && ! isset( $e['p2_pack_1'] ) );
$nopack = RF::validate( RF::normalize( array( 'welcome_pack' => array( 'enabled' => false, 'items' => array( array( 'label' => 'X', 'type' => 'text', 'required' => true ) ) ) ) ), array( 1 ), array( 'category_id' => 1, 'p1_name' => 'Anna', 'p1_email' => 'a@b.cat', 'p1_phone' => '600111222', 'p2_name' => 'Pere', 'p2_email' => 'p@b.cat', 'consent' => array( 'data' => 1, 'health' => 1 ) ) );
check( 'validate: disabled pack asks nothing', $nopack['errors'] === array() && $nopack['clean']['players'][0]['pack'] === array() );
check( 'validate: availability invalid/missing', ( $e['av_0'] ?? '' ) === 'invalid' || ( $e['av_0'] ?? '' ) === 'required' );
check( 'validate: availability missing slot', ( $e['av_1'] ?? '' ) === 'required' );
check( 'validate: required consent missing', ( $e['consent_health'] ?? '' ) === 'required' && ! isset( $e['consent_data'] ) );

$same = RF::validate( RF::defaults(), array( 1 ), array( 'category_id' => 1, 'p1_name' => 'Anna', 'p1_email' => 'a@b.cat', 'p1_phone' => '600111222', 'p2_name' => 'Pere', 'p2_email' => 'A@b.cat', 'consent' => array( 'data' => 1, 'health' => 1 ) ) );
check( 'validate: same email for both players', ( $same['errors']['p2_email'] ?? '' ) === 'same_email' );
$opt = RF::normalize( array( 'fields' => array( 'email2' => 'off', 'phone1' => 'off', 'notes' => 'off' ) ) );
$ok = RF::validate( $opt, array( 1 ), array( 'category_id' => 1, 'p1_name' => 'Anna', 'p1_email' => 'a@b.cat', 'p2_name' => 'Pere', 'consent' => array( 'data' => 1, 'health' => 1 ) ) );
check( 'validate: off fields are not required', $ok['errors'] === array() );
check( 'validate: availability disabled => not required', ! isset( $ok['errors']['av_0'] ) && $ok['clean']['availability'] === array() );

// Window / status / capacity.
$tz = new DateTimeZone( 'Europe/Madrid' );
$t0 = RF::window_ts( '2026-12-01T09:00', $tz );
check( 'window_ts parses', is_int( $t0 ) && RF::window_ts( '', $tz ) === null && RF::window_ts( 'x', $tz ) === null );
check( 'open_state: tournament not open', RF::open_state( 'draft', null, null, 100 ) === 'not_accepting' && RF::open_state( 'registration_closed', null, null, 100 ) === 'not_accepting' );
check( 'open_state: open without window', RF::open_state( 'registration_open', null, null, 100 ) === 'open' );
check( 'open_state: before opens', RF::open_state( 'registration_open', 200, null, 100 ) === 'not_open_yet' );
check( 'open_state: after closes', RF::open_state( 'registration_open', null, 50, 100 ) === 'closed' );
check( 'open_state: inside window', RF::open_state( 'registration_open', 50, 150, 100 ) === 'open' );
check( 'initial_status: no limit', RF::initial_status( null, 999 ) === 'pending' && RF::initial_status( 0, 999 ) === 'pending' );
check( 'initial_status: free place', RF::initial_status( 16, 15 ) === 'pending' );
check( 'initial_status: full => waitlist', RF::initial_status( 16, 16 ) === 'waitlist' && RF::initial_status( 16, 20 ) === 'waitlist' );

// --- RegistrationStatus ---
use PadelCamps\Engine\Csv;
use PadelCamps\Engine\RegistrationStatus as RS;

check( 'RS: statuses', RS::all() === array( 'pending', 'confirmed', 'waitlist', 'cancelled' ) && RS::is_valid( 'confirmed' ) && ! RS::is_valid( 'paid' ) );
check( 'RS: payments', RS::is_valid_payment( 'refunded' ) && RS::is_valid_payment( 'free' ) && ! RS::is_valid_payment( 'confirmed' ) );
check( 'RS: pending+confirmed hold a place', RS::occupies_place( 'pending' ) && RS::occupies_place( 'confirmed' ) && ! RS::occupies_place( 'waitlist' ) && ! RS::occupies_place( 'cancelled' ) );
check( 'RS: over capacity', RS::over_capacity( 2, 3 ) && ! RS::over_capacity( 2, 2 ) && ! RS::over_capacity( null, 99 ) && ! RS::over_capacity( 0, 99 ) );

$sum = RS::summarize( array(
	array( 'status' => 'confirmed', 'payment_status' => 'paid', 'n' => '2', 'total' => '50.00' ),
	array( 'status' => 'pending', 'payment_status' => 'pending', 'n' => '3', 'total' => '75.00' ),
	array( 'status' => 'waitlist', 'payment_status' => 'pending', 'n' => '1', 'total' => '25.00' ),
	array( 'status' => 'cancelled', 'payment_status' => 'pending', 'n' => '1', 'total' => '25.00' ),
	array( 'status' => 'confirmed', 'payment_status' => 'refunded', 'n' => '1', 'total' => '25.00' ),
	array( 'status' => 'pending', 'payment_status' => 'free', 'n' => '4', 'total' => '0.00' ),
) );
check( 'RS summary: counts', $sum['count'] === 12 && $sum['status']['pending'] === 7 && $sum['status']['confirmed'] === 3 && $sum['status']['waitlist'] === 1 && $sum['status']['cancelled'] === 1 );
check( 'RS summary: payment counts', $sum['payment']['paid'] === 2 && $sum['payment']['pending'] === 5 && $sum['payment']['free'] === 4 && $sum['payment']['refunded'] === 1 );
check( 'RS summary: collected money', abs( $sum['paid_total'] - 50.0 ) < 0.001 );
check( 'RS summary: due money ignores waitlist/cancelled', abs( $sum['due_total'] - 75.0 ) < 0.001 );
$empty = RS::summarize( array() );
check( 'RS summary: empty', $empty['count'] === 0 && $empty['paid_total'] === 0.0 && $empty['status']['pending'] === 0 );

// --- Csv ---
check( 'csv: plain', Csv::cell( 'Anna Puig' ) === 'Anna Puig' );
check( 'csv: separator quoted', Csv::cell( 'a;b' ) === '"a;b"' );
check( 'csv: quotes doubled', Csv::cell( 'di "hola"' ) === '"di ""hola"""' );
check( 'csv: newline quoted', Csv::cell( "a\nb" ) === "\"a\nb\"" );
check( 'csv: formula =', Csv::cell( '=HYPERLINK("http://x")' ) === '"\'=HYPERLINK(""http://x"")"' );
check( 'csv: formula @', Csv::cell( '@SUM(A1)' ) === "'@SUM(A1)" );
check( 'csv: formula + with text', Csv::cell( '+cmd|calc' ) === "'+cmd|calc" );
check( 'csv: formula - with text', Csv::cell( '-2+3+cmd' ) === "'-2+3+cmd" );
check( 'csv: phone number kept', Csv::cell( '+34 600 111 222' ) === '+34 600 111 222' && Csv::cell( '-12' ) === '-12' );
check( 'csv: non-scalar => empty', Csv::cell( array( 1 ) ) === '' && Csv::cell( null ) === '' );
check( 'csv: row', Csv::row( array( 'a', 'b;c', 3 ) ) === "a;\"b;c\";3\r\n" );

// --- MatchFormat ---
use PadelCamps\Engine\Groups;
use PadelCamps\Engine\MatchFormat as MF;
use PadelCamps\Engine\Qualification;
use PadelCamps\Engine\RoundRobin;
use PadelCamps\Engine\Standings;

$fmt = MF::defaults();
check( 'MF defaults: best of 3 + super tie-break to 10', $fmt === array( 'best_of' => 3, 'games' => 6, 'deciding' => 'super_tiebreak', 'points' => 10 ) );
check( 'MF normalize: bad values ignored', MF::normalize( array( 'best_of' => 2, 'games' => 20, 'deciding' => 'x', 'points' => 3 ) ) === $fmt );
check( 'MF normalize: best of 1 never super tie-break', MF::normalize( array( 'best_of' => 1 ) )['deciding'] === 'set' );
foreach ( array( array( 6, 0 ), array( 6, 4 ), array( 7, 5 ), array( 7, 6 ), array( 4, 6 ), array( 6, 7 ) ) as $s ) {
	check( "MF set valid {$s[0]}-{$s[1]}", MF::is_complete_set( $s[0], $s[1], $fmt, false ) );
}
foreach ( array( array( 6, 5 ), array( 7, 4 ), array( 8, 6 ), array( 5, 3 ), array( 6, 6 ), array( -1, 6 ) ) as $s ) {
	check( "MF set invalid {$s[0]}-{$s[1]}", ! MF::is_complete_set( $s[0], $s[1], $fmt, false ) );
}
check( 'MF super tie-break valid', MF::is_complete_set( 10, 8, $fmt, true ) && MF::is_complete_set( 12, 10, $fmt, true ) && MF::is_complete_set( 3, 10, $fmt, true ) );
check( 'MF super tie-break invalid', ! MF::is_complete_set( 10, 9, $fmt, true ) && ! MF::is_complete_set( 13, 10, $fmt, true ) && ! MF::is_complete_set( 9, 7, $fmt, true ) );

$e = MF::evaluate( array( 'sets' => array( array( 6, 4 ), array( 6, 3 ) ) ), $fmt );
check( 'MF 2-0', $e['valid'] && $e['winner'] === 'a' && $e['sets'] === array( 2, 0 ) && $e['games'] === array( 12, 7 ) );
$e = MF::evaluate( array( 'sets' => array( array( 6, 4 ), array( 3, 6 ), array( 8, 10 ) ) ), $fmt );
check( 'MF 1-2 with super tie-break (counts 0-1 in games)', $e['valid'] && $e['winner'] === 'b' && $e['sets'] === array( 1, 2 ) && $e['games'] === array( 9, 11 ) );
check( 'MF third set as 6-4 is invalid when super tie-break', MF::evaluate( array( 'sets' => array( array( 6, 4 ), array( 3, 6 ), array( 6, 4 ) ) ), $fmt )['error'] === 'invalid_set' );
check( 'MF third set normal when deciding=set', MF::evaluate( array( 'sets' => array( array( 6, 4 ), array( 3, 6 ), array( 6, 4 ) ) ), MF::normalize( array( 'deciding' => 'set' ) ) )['winner'] === 'a' );
check( 'MF too many sets', MF::evaluate( array( 'sets' => array( array( 6, 4 ), array( 6, 3 ), array( 6, 1 ) ) ), $fmt )['error'] === 'too_many_sets' );
check( 'MF incomplete', MF::evaluate( array( 'sets' => array( array( 6, 4 ) ) ), $fmt )['error'] === 'incomplete' );
check( 'MF invalid set', MF::evaluate( array( 'sets' => array( array( 6, 5 ), array( 6, 3 ) ) ), $fmt )['error'] === 'invalid_set' );
check( 'MF empty', MF::evaluate( array( 'sets' => array() ), $fmt )['error'] === 'empty' );
$e = MF::evaluate( array( 'walkover' => 'b' ), $fmt );
check( 'MF walkover = 0-2, 0-12', $e['valid'] && $e['winner'] === 'b' && $e['sets'] === array( 0, 2 ) && $e['games'] === array( 0, 12 ) );
$e = MF::evaluate( array( 'sets' => array( array( 6, 4 ), array( 2, 3 ) ), 'retired' => 'a' ), $fmt );
check( 'MF retirement: unfinished last set counted in games, winner = other side', $e['valid'] && $e['winner'] === 'b' && $e['sets'] === array( 1, 0 ) && $e['games'] === array( 8, 7 ) );
check( 'MF retirement before playing', MF::evaluate( array( 'sets' => array(), 'retired' => 'b' ), $fmt )['winner'] === 'a' );
check( 'MF retirement after match decided is invalid', MF::evaluate( array( 'sets' => array( array( 6, 4 ), array( 6, 4 ) ), 'retired' => 'b' ), $fmt )['error'] === 'too_many_sets' );
check( 'MF retirement: unfinished set only allowed last', MF::evaluate( array( 'sets' => array( array( 2, 3 ), array( 6, 4 ) ), 'retired' => 'b' ), $fmt )['error'] === 'invalid_set' );
check( 'MF bad side', MF::evaluate( array( 'walkover' => 'c' ), $fmt )['error'] === 'bad_side' );

// --- RoundRobin ---
foreach ( array( 3, 4, 5, 6, 7, 8 ) as $n ) {
	$ids    = range( 1, $n );
	$rounds = RoundRobin::rounds( $ids );
	$pairs  = array();
	$ok     = true;
	foreach ( $rounds as $round ) {
		$seen = array();
		foreach ( $round as $p ) {
			$ok = $ok && ! in_array( $p[0], $seen, true ) && ! in_array( $p[1], $seen, true ) && $p[0] !== $p[1];
			$seen[] = $p[0];
			$seen[] = $p[1];
			$key    = min( $p ) . '-' . max( $p );
			$pairs[ $key ] = ( $pairs[ $key ] ?? 0 ) + 1;
		}
	}
	check( "RR n=$n: every pair exactly once", count( $pairs ) === $n * ( $n - 1 ) / 2 && max( $pairs ) === 1 );
	check( "RR n=$n: nobody plays twice in a round", $ok );
	check( "RR n=$n: round count", count( $rounds ) === ( 0 === $n % 2 ? $n - 1 : $n ) );
}
check( 'RR deterministic', RoundRobin::rounds( array( 5, 9, 2, 7 ) ) === RoundRobin::rounds( array( 5, 9, 2, 7 ) ) );
check( 'RR fewer than 2 teams', RoundRobin::rounds( array( 1 ) ) === array() );

// --- Groups ---
foreach ( array( 2 => 1, 5 => 1, 6 => 2, 7 => 2, 8 => 2, 10 => 3, 12 => 3, 16 => 4, 17 => 4 ) as $teams => $expected ) {
	check( "Groups::count_for($teams) = $expected", Groups::count_for( $teams ) === $expected );
}
check( 'Groups::count_for(1) = 0', Groups::count_for( 1 ) === 0 );
check( 'Groups::count_for(9, 3) = 3', Groups::count_for( 9, 3 ) === 3 );
$gm = Groups::matches( array( 0 => array( 1, 2, 3, 4 ), 1 => array( 5, 6, 7 ) ) );
check( 'Groups::matches count (6 + 3)', count( $gm ) === 9 );
check( 'Groups::matches tagged with group', count( array_filter( $gm, fn( $m ) => 1 === $m['group'] ) ) === 3 );

// --- Standings ---
$r = static fn( array ...$sets ) => array( 'sets' => $sets );
// Clear order: 1 beats all, 2 beats 3 and 4, 3 beats 4.
$st = Standings::compute( array( 4, 3, 2, 1 ), array(
	array( 'a' => 1, 'b' => 2, 'result' => $r( array( 6, 2 ), array( 6, 2 ) ) ),
	array( 'a' => 1, 'b' => 3, 'result' => $r( array( 6, 1 ), array( 6, 1 ) ) ),
	array( 'a' => 1, 'b' => 4, 'result' => $r( array( 6, 0 ), array( 6, 0 ) ) ),
	array( 'a' => 2, 'b' => 3, 'result' => $r( array( 6, 4 ), array( 6, 4 ) ) ),
	array( 'a' => 2, 'b' => 4, 'result' => $r( array( 6, 3 ), array( 6, 3 ) ) ),
	array( 'a' => 3, 'b' => 4, 'result' => $r( array( 6, 4 ), array( 4, 6 ), array( 10, 7 ) ) ),
), $fmt );
check( 'Standings: order by wins', array_column( $st, 'team' ) === array( 1, 2, 3, 4 ) );
check( 'Standings: positions', array_column( $st, 'position' ) === array( 1, 2, 3, 4 ) );
check( 'Standings: stats of team 3', $st[2]['won'] === 1 && $st[2]['lost'] === 2 && $st[2]['sets_for'] === 2 && $st[2]['sets_against'] === 5 && $st[2]['games_for'] === 2 + 8 + 11 );
// Two tied on wins and diffs: head-to-head decides.
$st = Standings::compute( array( 1, 2, 3 ), array(
	array( 'a' => 1, 'b' => 2, 'result' => $r( array( 4, 6 ), array( 4, 6 ) ) ),
	array( 'a' => 1, 'b' => 3, 'result' => $r( array( 6, 4 ), array( 6, 4 ) ) ),
	array( 'a' => 2, 'b' => 3, 'result' => $r( array( 4, 6 ), array( 4, 6 ) ) ),
), $fmt );
check( 'Standings: 3-way tie on wins/diffs, then fallback by seed', array_column( $st, 'team' ) === array( 1, 2, 3 ) );
$st = Standings::compute( array( 1, 2 ), array(
	array( 'a' => 1, 'b' => 2, 'result' => $r( array( 4, 6 ), array( 4, 6 ) ) ),
), $fmt, array( 'head_to_head' ) );
check( 'Standings: head-to-head beats seed order', array_column( $st, 'team' ) === array( 2, 1 ) );
// Two teams with 2 wins, better set difference first.
$st = Standings::compute( array( 1, 2, 3, 4 ), array(
	array( 'a' => 1, 'b' => 2, 'result' => $r( array( 6, 4 ), array( 3, 6 ), array( 10, 8 ) ) ), // 1 beats 2 (2-1)
	array( 'a' => 1, 'b' => 3, 'result' => $r( array( 3, 6 ), array( 3, 6 ) ) ),                 // 3 beats 1
	array( 'a' => 1, 'b' => 4, 'result' => $r( array( 6, 4 ), array( 3, 6 ), array( 10, 5 ) ) ), // 1 beats 4 (2-1)
	array( 'a' => 2, 'b' => 3, 'result' => $r( array( 6, 0 ), array( 6, 0 ) ) ),                 // 2 beats 3
	array( 'a' => 2, 'b' => 4, 'result' => $r( array( 6, 0 ), array( 6, 0 ) ) ),                 // 2 beats 4
	array( 'a' => 3, 'b' => 4, 'result' => $r( array( 0, 6 ), array( 0, 6 ) ) ),                 // 4 beats 3
), $fmt );
check( 'Standings: wins tie (1 and 2 have 2) decided by set diff', array_column( $st, 'team' )[0] === 2 && array_column( $st, 'team' )[1] === 1 );
// Walkover and invalid results.
$st = Standings::compute( array( 1, 2 ), array(
	array( 'a' => 1, 'b' => 2, 'result' => array( 'walkover' => 'b' ) ),
	array( 'a' => 1, 'b' => 2, 'result' => $r( array( 6, 5 ) ) ), // invalid → ignored
	array( 'a' => 1, 'b' => 9, 'result' => $r( array( 6, 0 ), array( 6, 0 ) ) ), // team not in group → ignored
	array( 'a' => 1, 'b' => 2, 'result' => null ), // not played
), $fmt );
check( 'Standings: walkover counted, invalid/foreign/unplayed ignored', $st[0]['team'] === 2 && $st[0]['played'] === 1 && $st[0]['games_for'] === 12 && $st[1]['played'] === 1 );
check( 'Standings: no matches keeps seed order', array_column( Standings::compute( array( 7, 3, 5 ), array(), $fmt ), 'team' ) === array( 7, 3, 5 ) );

// --- Qualification ---
$row = static fn( int $team, int $pos, int $played, int $won, int $sd = 0, int $gd = 0 ) => array( 'team' => $team, 'position' => $pos, 'played' => $played, 'won' => $won, 'set_diff' => $sd, 'game_diff' => $gd );
$tables = array(
	0 => array( $row( 101, 1, 3, 3, 6 ), $row( 102, 2, 3, 2, 2 ), $row( 103, 3, 3, 1 ), $row( 104, 4, 3, 0 ) ),
	1 => array( $row( 201, 1, 3, 3, 5 ), $row( 202, 2, 3, 2, 1 ), $row( 203, 3, 3, 1 ), $row( 204, 4, 3, 0 ) ),
	2 => array( $row( 301, 1, 3, 2, 3 ), $row( 302, 2, 3, 2, 3 ), $row( 303, 3, 3, 2 ), $row( 304, 4, 3, 0 ) ),
	3 => array( $row( 401, 1, 3, 3, 4 ), $row( 402, 2, 3, 1, 0 ), $row( 403, 3, 3, 1 ), $row( 404, 4, 3, 1 ) ),
);
$q = Qualification::knockout_entrants( $tables, 2 );
check( 'Qualification: 8 entrants', count( $q['entrants'] ) === 8 );
check( 'Qualification: winners are seeds 1-4, best record first', array_slice( $q['entrants'], 0, 4 ) === array( 101, 201, 401, 301 ) );
$no_clash = static function ( array $entrants, array $group_of ): bool {
	$size  = \PadelCamps\Engine\Bracket::size_for( count( $entrants ) );
	$order = \PadelCamps\Engine\Bracket::seed_order( $size );
	for ( $p = 0; $p < $size; $p += 2 ) {
		$a = $entrants[ $order[ $p ] - 1 ] ?? null;
		$b = $entrants[ $order[ $p + 1 ] - 1 ] ?? null;
		if ( null !== $a && null !== $b && $group_of[ $a ] === $group_of[ $b ] ) {
			return false;
		}
	}
	return true;
};
$opposite_halves = static function ( array $entrants, array $group_of ): bool {
	$size  = \PadelCamps\Engine\Bracket::size_for( count( $entrants ) );
	$slot  = array_flip( \PadelCamps\Engine\Bracket::seed_order( $size ) );
	$halfs = array();
	foreach ( $entrants as $i => $team ) {
		$halfs[ $group_of[ $team ] ][] = $slot[ $i + 1 ] < $size / 2 ? 0 : 1;
	}
	foreach ( $halfs as $h ) {
		if ( count( $h ) === 2 && $h[0] === $h[1] ) {
			return false;
		}
	}
	return true;
};
check( 'Qualification 4x2: no same-group match in round 1', $no_clash( $q['entrants'], $q['group_of'] ) );
check( 'Qualification 4x2: same group in opposite halves', $opposite_halves( $q['entrants'], $q['group_of'] ) );
$q3 = Qualification::knockout_entrants( array_slice( $tables, 0, 3, true ), 2 );
check( 'Qualification 3x2: 6 entrants (2 byes)', count( $q3['entrants'] ) === 6 );
check( 'Qualification 3x2: no same-group match in round 1', $no_clash( $q3['entrants'], $q3['group_of'] ) );
check( 'Qualification 3x2: same group in opposite halves', $opposite_halves( $q3['entrants'], $q3['group_of'] ) );
$q2 = Qualification::knockout_entrants( array_slice( $tables, 0, 2, true ), 2 );
check( 'Qualification 2x2: 1A-2B and 1B-2A', $no_clash( $q2['entrants'], $q2['group_of'] ) && $opposite_halves( $q2['entrants'], $q2['group_of'] ) );
$q1 = Qualification::knockout_entrants( $tables, 1 );
check( 'Qualification advance 1: only winners', $q1['entrants'] === array( 101, 201, 401, 301 ) );
// Groups of different sizes: compare per match played.
$uneven = array(
	0 => array( $row( 1, 1, 2, 2, 4 ), $row( 2, 2, 2, 1 ), $row( 3, 3, 2, 0 ) ),
	1 => array( $row( 4, 1, 3, 2, 2 ), $row( 5, 2, 3, 2, 1 ), $row( 6, 3, 3, 1 ), $row( 7, 4, 3, 1 ) ),
);
check( 'Qualification uneven groups: 2/2 wins beats 2/3', Qualification::knockout_entrants( $uneven, 1 )['entrants'] === array( 1, 4 ) );

// --- Full simulated tournament: groups → matches → standings → qualification → bracket ---
$teams = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$teams[] = array( 'id' => $i, 'rating' => (float) $i, 'club' => null );
}
$gcount = Groups::count_for( count( $teams ) );
$drawn  = Draw::groups( $teams, $gcount, 'sim-2026' );
$ids    = array_map( fn( $g ) => array_column( $g, 'id' ), $drawn );
$rating = array_column( $teams, 'rating', 'id' );
$played = array();
foreach ( Groups::matches( $ids ) as $m ) {
	$a_wins          = $rating[ $m['a'] ] > $rating[ $m['b'] ];
	$m['result']     = $a_wins ? $r( array( 6, 3 ), array( 6, 4 ) ) : $r( array( 3, 6 ), array( 4, 6 ) );
	$played[ $m['group'] ][] = $m;
}
$tables = array();
foreach ( $ids as $g => $group_ids ) {
	$tables[ $g ] = Standings::compute( $group_ids, $played[ $g ], $fmt );
}
$best_per_group = array_map( fn( $g ) => max( $g ), $ids );
$winners        = array_map( fn( $t ) => $t[0]['team'], $tables );
sort( $best_per_group );
sort( $winners );
check( 'Simulation: strongest team of each group wins it', $winners === $best_per_group );
$q   = Qualification::knockout_entrants( $tables, 2 );
$brk = Bracket::generate( count( $q['entrants'] ) );
check( 'Simulation: 3 groups x 2 = 6 entrants, 7 bracket matches', count( $q['entrants'] ) === 6 && count( $brk ) === 7 );
check( 'Simulation: no same-group clash in round 1', $no_clash( $q['entrants'], $q['group_of'] ) );
$top3 = array_slice( $q['entrants'], 0, 3 );
sort( $top3 );
check( 'Simulation: the 3 group winners are seeds 1-3', $top3 === $winners );
// Identical records: the original seed (rating rank) decides, not the group order.
$seed_rank = array();
foreach ( Draw::seed_teams( $teams, 0, 'x' ) as $t ) {
	$seed_rank[ $t['id'] ] = array_search( $t['id'], array_column( array_reverse( $teams ), 'id' ), true ) + 1;
}
$qs = Qualification::knockout_entrants( $tables, 2, $seed_rank );
check( 'Simulation: with seed rank, rating 12 is top seed', $qs['entrants'][0] === 12 );
check( 'Simulation: with seed rank, still no same-group clash', $no_clash( $qs['entrants'], $qs['group_of'] ) );

// --- ScoreParser ---
use PadelCamps\Engine\DrawPlan;
use PadelCamps\Engine\ScoreParser;

check( 'ScoreParser basic', ScoreParser::parse( '6-4 3-6 10-8' ) === array( array( 6, 4 ), array( 3, 6 ), array( 10, 8 ) ) );
check( 'ScoreParser separators', ScoreParser::parse( '6/4, 7:5;' ) === array( array( 6, 4 ), array( 7, 5 ) ) );
check( 'ScoreParser spaces', ScoreParser::parse( ' 6 - 4   6 -3 ' ) === array( array( 6, 4 ), array( 6, 3 ) ) );
check( 'ScoreParser empty', ScoreParser::parse( '   ' ) === array() );
check( 'ScoreParser garbage', ScoreParser::parse( 'hola' ) === null && ScoreParser::parse( '6-4 i 6-3' ) === null && ScoreParser::parse( '6-4 x' ) === null );
check( 'ScoreParser format', ScoreParser::format( array( array( 6, 4 ), array( 10, 8 ) ) ) === '6-4 10-8' );

// --- DrawPlan ---
$ent = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$ent[] = array( 'id' => 100 + $i, 'rating' => $i );
}
check( 'DrawPlan seeded_count', DrawPlan::seeded_count( 3 ) === 2 && DrawPlan::seeded_count( 6 ) === 4 && DrawPlan::seeded_count( 24 ) === 8 && DrawPlan::seeded_count( 1 ) === 1 );
check( 'DrawPlan by_rating', DrawPlan::by_rating( $ent )[0] === 110 && DrawPlan::by_rating( $ent )[9] === 101 );
check( 'DrawPlan by_rating: null ratings, tie by id', DrawPlan::by_rating( array( array( 'id' => 5, 'rating' => null ), array( 'id' => 2, 'rating' => null ) ) ) === array( 2, 5 ) );
$ag = DrawPlan::assign_groups( $ent, 4, 'seed-x' );
check( 'DrawPlan groups: 10 → 3 groups', count( $ag ) === 3 && array_sum( array_map( 'count', $ag ) ) === 10 );
check( 'DrawPlan groups: deterministic', $ag === DrawPlan::assign_groups( $ent, 4, 'seed-x' ) );
check( 'DrawPlan groups: different seed can differ', DrawPlan::assign_groups( $ent, 4, 'seed-y' ) !== $ag || DrawPlan::assign_groups( $ent, 4, 'seed-z' ) !== $ag );
check( 'DrawPlan groups: top 3 in different groups', count( array_unique( array_map( fn( $g ) => in_array( 110, $g, true ) ? 'a' : ( in_array( 109, $g, true ) ? 'b' : ( in_array( 108, $g, true ) ? 'c' : 'x' ) ), $ag ) ) ) === 3 );
check( 'DrawPlan groups: each group strongest first', array_reduce( $ag, fn( $ok, $g ) => $ok && $g === DrawPlan::by_rating( array_map( fn( $id ) => array( 'id' => $id, 'rating' => $id - 100 ), $g ) ), true ) );
$as = DrawPlan::assign_seeds( $ent, 'seed-x' );
check( 'DrawPlan seeds: top 4 by rating', array_slice( $as, 0, 4 ) === array( 110, 109, 108, 107 ) && count( $as ) === 10 );
$ko = DrawPlan::knockout( $as );
check( 'DrawPlan knockout: 10 entrants → 15 matches, 6 byes', count( $ko ) === 15 && count( array_filter( $ko, fn( $m ) => $m['is_bye'] ) ) === 6 );
$byes = array_filter( $ko, fn( $m ) => $m['is_bye'] );
check( 'DrawPlan knockout: bye winners are the top 6 seeds', array_values( array_map( fn( $m ) => $m['winner'], $byes ) ) == array_values( array_intersect( array_map( fn( $m ) => $m['winner'], $byes ), array_slice( $as, 0, 6 ) ) ) );
$r2 = array_filter( $ko, fn( $m ) => 2 === $m['round'] );
$r2_ids = array_filter( array_merge( array_column( $r2, 'a' ), array_column( $r2, 'b' ) ) );
check( 'DrawPlan knockout: bye winners already in round 2', count( array_intersect( array_map( fn( $m ) => $m['winner'], $byes ), $r2_ids ) ) === 6 );
check( 'DrawPlan knockout: <2 entrants', DrawPlan::knockout( array( 1 ) ) === array() );
check( 'DrawPlan round keys', DrawPlan::round_key( 4, 4 ) === 'final' && DrawPlan::round_key( 3, 4 ) === 'semi' && DrawPlan::round_key( 2, 4 ) === 'quarter' && DrawPlan::round_key( 1, 4 ) === 'r16' && DrawPlan::round_key( 1, 6 ) === 'round' );
$rank = array_flip( DrawPlan::by_rating( $ent ) );
$sw   = DrawPlan::swap_in_groups( array( 0 => array( 110, 105 ), 1 => array( 109, 104 ) ), 105, 104, $rank );
check( 'DrawPlan swap in groups', $sw === array( 0 => array( 110, 104 ), 1 => array( 109, 105 ) ) );
check( 'DrawPlan swap in seeds', DrawPlan::swap_in_seeds( array( 1, 2, 3 ), 1, 3 ) === array( 3, 2, 1 ) && DrawPlan::swap_in_seeds( array( 1, 2 ), 1, 9 ) === array( 1, 2 ) );

// --- PublicName ---
use PadelCamps\Engine\BracketView;
use PadelCamps\Engine\PublicName;

check( 'PublicName full', PublicName::format( '  Anna   Puig Serra ', 'full' ) === 'Anna Puig Serra' );
check( 'PublicName initials', PublicName::format( 'Anna Puig Serra', 'initials' ) === 'Anna P.' && PublicName::format( 'Núria Àlvarez', 'initials' ) === 'Núria À.' );
check( 'PublicName single word', PublicName::format( 'Anna', 'initials' ) === 'Anna' && PublicName::format( '', 'initials' ) === '' );
check( 'PublicName pair', PublicName::pair( array( 'Anna Puig', 'Pere Soler' ), 'initials' ) === 'Anna P. / Pere S.' );

// --- BracketView ---
$rows = array();
foreach ( DrawPlan::knockout( array( 11, 12, 13, 14, 15, 16 ) ) as $m ) {
	$rows[] = array( 'round' => $m['round'], 'position' => $m['position'], 'a' => $m['a'], 'b' => $m['b'], 'winner' => $m['winner'], 'status' => $m['is_bye'] ? 'bye' : 'scheduled', 'score' => null );
}
$bv = BracketView::build( array_reverse( $rows ) );
check( 'BracketView: rounds ordered', array_keys( $bv ) === array( 1, 2, 3 ) );
check( 'BracketView: matches numbered 1..7', array_merge( ...array_map( fn( $r ) => array_column( $r, 'number' ), $bv ) ) === range( 1, 7 ) );
check( 'BracketView: round 1 has no sources', $bv[1][0]['from_a'] === null && $bv[1][0]['from_b'] === null );
check( 'BracketView: semi 1 fed by P1/P2, final by P5/P6', $bv[2][0]['from_a'] === 1 && $bv[2][0]['from_b'] === 2 && $bv[3][0]['from_a'] === 5 && $bv[3][0]['from_b'] === 6 );
check( 'BracketView encode/decode', BracketView::decode( BracketView::encode( 2, 1 ) ) === array( 2, 1 ) && BracketView::encode( 0, 2 ) === 102 );
$pv = BracketView::provisional( array( 4, 3, 3 ), 2 );
$pr = BracketView::build( $pv );
check( 'Provisional: 3 groups x 2 → 7 matches, 2 byes', count( $pv ) === 7 && count( array_filter( $pv, fn( $m ) => 'bye' === $m['status'] ) ) === 2 );
$r1 = array_merge( ...array_map( fn( $m ) => array( array( $m['a'], $m['b'] ) ), $pr[1] ) );
$clash = false;
foreach ( $r1 as $pair ) {
	if ( $pair[0] && $pair[1] && BracketView::decode( $pair[0] )[0] === BracketView::decode( $pair[1] )[0] ) {
		$clash = true;
	}
}
check( 'Provisional: no same-group clash in round 1', ! $clash );
check( 'Provisional: byes go to group winners', count( array_filter( $pv, fn( $m ) => 'bye' === $m['status'] && 1 === BracketView::decode( (int) $m['winner'] )[1] ) ) === 2 );
check( 'Provisional: group smaller than advance', count( BracketView::provisional( array( 1, 1 ), 2 ) ) === 1 );
check( 'Provisional: not enough', BracketView::provisional( array( 1 ), 2 ) === array() );

// --- Scheduler ---
use PadelCamps\Engine\Scheduler as SC;

$sc = SC::normalize( array(
	'duration'    => 60,
	'rest'        => 30,
	'max_per_day' => 0,
	'windows'     => array(
		array( 'date' => '2026-12-05', 'label' => 'Tarda', 'from' => '16:00', 'to' => '19:00' ),
		array( 'date' => '2026-12-05', 'label' => 'Matí', 'from' => '09:00', 'to' => '11:00' ),
		array( 'date' => '2026-13-05', 'label' => 'x', 'from' => '09:00', 'to' => '11:00' ),
		array( 'date' => '2026-12-06', 'label' => 'x', 'from' => '12:00', 'to' => '11:00' ),
		'nope',
	),
) );
check( 'SC normalize: invalid windows dropped, sorted by time', count( $sc['windows'] ) === 2 && $sc['windows'][0]['label'] === 'Matí' );
check( 'SC normalize: defaults', SC::normalize( array() ) === SC::defaults() && SC::normalize( array( 'duration' => 5, 'rest' => -1 ) )['duration'] === 90 );
check( 'SC hm', SC::hm( '09:30' ) === 570 && SC::hm( '24:00' ) === null && SC::hm( 'x' ) === null );
check( 'SC parse/fmt roundtrip', SC::fmt( (int) SC::parse( '2026-12-05 16:30:00' ) ) === '2026-12-05 16:30' && SC::parse( 'bad' ) === null );
$st = SC::starts( $sc );
check( 'SC starts: 2 in morning + 3 in afternoon', count( $st ) === 5 && SC::fmt( $st[0]['start'] ) === '2026-12-05 09:00' && SC::fmt( $st[4]['start'] ) === '2026-12-05 18:00' );

$w = array( 'date' => '2026-12-05', 'label' => 'Tarda' );
check( 'SC available: no answer = yes', SC::available( array(), $w, 960 ) );
check( 'SC available: no', ! SC::available( array( array( 'date' => '2026-12-05', 'label' => 'tarda', 'value' => 'no' ) ), $w, 960 ) );
check( 'SC available: other slot ignored', SC::available( array( array( 'date' => '2026-12-05', 'label' => 'Matí', 'value' => 'no' ) ), $w, 960 ) );
check( 'SC available: from', SC::available( array( array( 'date' => '2026-12-05', 'label' => 'Tarda', 'value' => 'from:17:00' ) ), $w, 1020 ) && ! SC::available( array( array( 'date' => '2026-12-05', 'label' => 'Tarda', 'value' => 'from:17:00' ) ), $w, 960 ) );
check( 'SC available: only', SC::available( array( array( 'date' => '2026-12-05', 'label' => 'Tarda', 'value' => 'only:18:00' ) ), $w, 1080 ) && ! SC::available( array( array( 'date' => '2026-12-05', 'label' => 'Tarda', 'value' => 'only:18:00' ) ), $w, 1020 ) );

// 4 pairs round robin (6 matches), 2 courts, 60' + 30' rest.
$mm = array();
$i  = 0;
foreach ( RoundRobin::rounds( array( 1, 2, 3, 4 ) ) as $round ) {
	foreach ( $round as $p ) {
		$mm[] = array( 'id' => ++$i, 'a' => $p[0], 'b' => $p[1] );
	}
}
$res = SC::schedule( $sc, $mm, array( 10, 11 ), array() );
check( 'SC schedule: all 6 matches placed', count( $res['assigned'] ) === 6 && $res['unscheduled'] === array() );
$sched = array();
foreach ( $mm as $m ) {
	$sched[] = $m + $res['assigned'][ $m['id'] ];
}
check( 'SC schedule: result has no conflicts', SC::conflicts( $sc, $sched, array() ) === array() );
check( 'SC schedule: first round fills both courts at 09:00', $res['assigned'][1]['start'] === '2026-12-05 09:00' && $res['assigned'][2]['start'] === '2026-12-05 09:00' && $res['assigned'][1]['court'] !== $res['assigned'][2]['court'] );
check( 'SC schedule: rest respected (round 2 not at 10:00)', $res['assigned'][3]['start'] !== '2026-12-05 10:00' );

// Availability: pair 1 cannot play in the morning.
$av  = array( 1 => array( array( 'date' => '2026-12-05', 'label' => 'Matí', 'value' => 'no' ) ) );
$res = SC::schedule( $sc, array( array( 'id' => 1, 'a' => 1, 'b' => 2 ) ), array( 10 ), $av );
check( 'SC schedule: availability moves match to the afternoon', $res['assigned'][1]['start'] === '2026-12-05 16:00' );
$av  = array( 1 => array( array( 'date' => '2026-12-05', 'label' => 'Matí', 'value' => 'no' ), array( 'date' => '2026-12-05', 'label' => 'Tarda', 'value' => 'no' ) ) );
$res = SC::schedule( $sc, array( array( 'id' => 1, 'a' => 1, 'b' => 2 ) ), array( 10 ), $av );
check( 'SC schedule: impossible availability reported', $res['unscheduled'] === array( 1 => 'availability' ) );
$av  = array( 2 => array( array( 'date' => '2026-12-05', 'label' => 'Tarda', 'value' => 'only:18:00' ), array( 'date' => '2026-12-05', 'label' => 'Matí', 'value' => 'no' ) ) );
$res = SC::schedule( $sc, array( array( 'id' => 1, 'a' => 1, 'b' => 2 ) ), array( 10 ), $av );
check( 'SC schedule: "only at 18:00" honoured', $res['assigned'][1]['start'] === '2026-12-05 18:00' );

// Dependencies (knockout): final after both semis + rest.
$ko  = array(
	array( 'id' => 5, 'a' => 1, 'b' => 2 ),
	array( 'id' => 6, 'a' => 3, 'b' => 4 ),
	array( 'id' => 7, 'a' => null, 'b' => null, 'after' => array( 5, 6 ) ),
);
$res = SC::schedule( $sc, $ko, array( 10 ), array() );
check( 'SC schedule: semis 09:00 and 10:00 on one court, final after the last + rest', $res['assigned'][5]['start'] === '2026-12-05 09:00' && $res['assigned'][6]['start'] === '2026-12-05 10:00' && $res['assigned'][7]['start'] === '2026-12-05 16:00' );
$res = SC::schedule( $sc, array( array( 'id' => 7, 'a' => null, 'b' => null, 'after' => array( 99 ) ) ), array( 10 ), array() );
check( 'SC schedule: unscheduled dependency reported', $res['unscheduled'] === array( 7 => 'dependency' ) );
check( 'SC schedule: no courts', SC::schedule( $sc, array( array( 'id' => 1, 'a' => 1, 'b' => 2 ) ), array(), array() )['unscheduled'] === array( 1 => 'no_courts' ) );
// Fixed matches are kept and block their court/time.
$res = SC::schedule( $sc, array( array( 'id' => 1, 'a' => 1, 'b' => 2, 'fixed' => array( 'start' => '2026-12-05 09:00', 'court' => 10 ) ), array( 'id' => 2, 'a' => 3, 'b' => 4 ) ), array( 10 ), array() );
check( 'SC schedule: fixed match respected', ! isset( $res['assigned'][1] ) && $res['assigned'][2]['start'] === '2026-12-05 10:00' );
// Max matches per pair and day.
$one = SC::normalize( array_merge( $sc, array( 'max_per_day' => 1 ) ) );
$res = SC::schedule( $one, array( array( 'id' => 1, 'a' => 1, 'b' => 2 ), array( 'id' => 2, 'a' => 1, 'b' => 3 ) ), array( 10, 11 ), array() );
check( 'SC schedule: max per day', isset( $res['assigned'][1] ) && $res['unscheduled'] === array( 2 => 'no_slot' ) );
// Not enough room.
$res = SC::schedule( $sc, array_map( fn( $k ) => array( 'id' => $k, 'a' => 100 + $k, 'b' => 200 + $k ), range( 1, 7 ) ), array( 10 ), array() );
check( 'SC schedule: 5 slots on 1 court → 2 left out', count( $res['assigned'] ) === 5 && count( $res['unscheduled'] ) === 2 );

// Conflicts.
$cf = SC::conflicts( $sc, array(
	array( 'id' => 1, 'a' => 1, 'b' => 2, 'start' => '2026-12-05 09:00', 'court' => 10 ),
	array( 'id' => 2, 'a' => 3, 'b' => 4, 'start' => '2026-12-05 09:30', 'court' => 10 ),
	array( 'id' => 3, 'a' => 1, 'b' => 5, 'start' => '2026-12-05 10:00', 'court' => 11 ),
	array( 'id' => 4, 'a' => 6, 'b' => 7, 'start' => '2026-12-05 13:00', 'court' => 11 ),
	array( 'id' => 5, 'a' => 8, 'b' => 9, 'start' => '2026-12-05 16:00', 'court' => 11 ),
), array( 8 => array( array( 'date' => '2026-12-05', 'label' => 'Tarda', 'value' => 'no' ) ) ) );
$types = array_column( $cf, 'type' );
sort( $types );
check( 'SC conflicts: court clash, short rest, outside window, unavailable', $types === array( 'court_clash', 'outside_window', 'short_rest', 'unavailable' ) );

// Windows from the registration form availability.
$wf = SC::windows_from_availability( array(
	array( 'date' => '2026-12-05', 'label' => 'Matí', 'mode' => 'yesno', 'times' => array() ),
	array( 'date' => '2026-12-05', 'label' => 'Tarda', 'mode' => 'yesno', 'times' => array() ),
	array( 'date' => '2026-12-07', 'label' => 'Dilluns', 'mode' => 'from', 'times' => array( '18:00', '19:00', '=22:15' ) ),
), 90 );
check( 'SC windows from availability', $wf[0]['from'] === '09:00' && $wf[0]['to'] === '14:00' && $wf[1]['from'] === '16:00' && $wf[2]['from'] === '18:00' && $wf[2]['to'] === '23:45' );

// --- ResultReport ---
use PadelCamps\Engine\ResultReport as RR;

$x = RR::from_inputs( array( '6', '3', '10' ), array( '4', '6', '8' ), '' );
check( 'RR from inputs', $x['error'] === null && $x['result'] === array( 'sets' => array( array( 6, 4 ), array( 3, 6 ), array( 10, 8 ) ) ) );
check( 'RR empty rows skipped', RR::from_inputs( array( '6', '6', '' ), array( '4', '3', '' ), '' )['result'] === array( 'sets' => array( array( 6, 4 ), array( 6, 3 ) ) ) );
check( 'RR incomplete set', RR::from_inputs( array( '6', '6' ), array( '4', '' ), '' )['error'] === 'incomplete_set' );
check( 'RR invalid', RR::from_inputs( array( '6', 'x' ), array( '4', '3' ), '' )['error'] === 'invalid' && RR::from_inputs( array( '-1' ), array( '4' ), '' )['error'] === 'invalid' && RR::from_inputs( array( '100' ), array( '4' ), '' )['error'] === 'invalid' );
check( 'RR empty', RR::from_inputs( array( '', '' ), array( '', '' ), '' )['error'] === 'empty' );
check( 'RR walkover ignores sets', RR::from_inputs( array( '6' ), array( '0' ), 'wo_b' )['result'] === array( 'walkover' => 'b' ) );
check( 'RR retirement with partial set', RR::from_inputs( array( '6', '2' ), array( '4', '3' ), 'ret_a' )['result'] === array( 'sets' => array( array( 6, 4 ), array( 2, 3 ) ), 'retired' => 'a' ) );
check( 'RR retirement before playing', RR::from_inputs( array(), array(), 'ret_b' )['result'] === array( 'sets' => array(), 'retired' => 'b' ) );
check( 'RR bad special', RR::from_inputs( array(), array(), 'hack' )['error'] === 'bad_special' );
check( 'RR same', RR::same( array( 'sets' => array( array( '6', '4' ) ) ), array( 'sets' => array( array( 6, 4 ) ) ) ) && ! RR::same( array( 'sets' => array( array( 6, 4 ) ) ), array( 'sets' => array( array( 4, 6 ) ) ) ) );
check( 'RR same walkover', RR::same( array( 'walkover' => 'a', 'sets' => array( array( 1, 1 ) ) ), array( 'walkover' => 'a' ) ) && ! RR::same( array( 'walkover' => 'a' ), array( 'walkover' => 'b' ) ) );
check( 'RR to_text / special', RR::to_text( array( 'sets' => array( array( 6, 4 ), array( 7, 5 ) ) ) ) === '6-4 7-5' && RR::special( array( 'walkover' => 'b' ) ) === 'wo_b' && RR::special( array( 'sets' => array(), 'retired' => 'a' ) ) === 'ret_a' && RR::special( array( 'sets' => array() ) ) === '' );

// --- Club separation in groups ---
$club_teams = array();
for ( $i = 1; $i <= 8; $i++ ) {
	// 4 clubs, two pairs each, and both pairs of a club in the same pot (adjacent ratings).
	$club_teams[] = array( 'id' => $i, 'rating' => (float) ( 10 - $i ), 'clubs' => array( 'Club ' . intdiv( $i - 1, 2 ) ) );
}
$plain = Draw::groups( array_map( fn( $t ) => array( 'id' => $t['id'], 'rating' => $t['rating'], 'club' => null ), $club_teams ), 2, 'clubs-seed' );
$sep   = Draw::groups( $club_teams, 2, 'clubs-seed' );
check( 'Clubs: same group sizes as plain deal', array_map( 'count', $sep ) === array_map( 'count', $plain ) );
check( 'Clubs: no same-club pairs share a group', Draw::club_clashes( $sep ) === 0 );
check( 'Clubs: without clubs the deal is unchanged', array_map( fn( $g ) => array_column( $g, 'id' ), Draw::groups( array_map( fn( $t ) => array( 'id' => $t['id'], 'rating' => $t['rating'], 'club' => null ), $club_teams ), 2, 'clubs-seed' ) ) === array_map( fn( $g ) => array_column( $g, 'id' ), $plain ) );
check( 'Clubs: case/space insensitive', Draw::club_clashes( Draw::groups( array(
	array( 'id' => 1, 'rating' => 2.0, 'clubs' => array( 'CP Vic' ) ),
	array( 'id' => 2, 'rating' => 1.0, 'clubs' => array( 'cp vic ' ) ),
	array( 'id' => 3, 'rating' => 2.0, 'clubs' => array( 'Altre' ) ),
	array( 'id' => 4, 'rating' => 1.0, 'clubs' => array() ),
), 2, 'x' ) ) === 0 );
$imp = Draw::groups( array(
	array( 'id' => 1, 'rating' => 3.0, 'clubs' => array( 'A' ) ),
	array( 'id' => 2, 'rating' => 2.0, 'clubs' => array( 'A' ) ),
	array( 'id' => 3, 'rating' => 1.0, 'clubs' => array( 'A' ) ),
), 1, 'x' );
check( 'Clubs: impossible separation still draws (soft rule)', count( $imp[0] ) === 3 );
check( 'Clubs: deterministic', Draw::groups( $club_teams, 2, 'clubs-seed' ) === $sep );
$ag_clubs = DrawPlan::assign_groups( array_map( fn( $t ) => array( 'id' => $t['id'], 'rating' => $t['rating'], 'clubs' => $t['clubs'] ), $club_teams ), 4, 'clubs-seed' );
$by_id    = array_column( $club_teams, null, 'id' );
check( 'Clubs: DrawPlan passes clubs through', Draw::club_clashes( array_map( fn( $g ) => array_map( fn( $id ) => $by_id[ $id ], $g ), $ag_clubs ) ) === 0 );

// --- Consolation bracket ---
$main6 = DrawPlan::knockout( array( 1, 2, 3, 4, 5, 6 ) );         // 8-bracket, 2 byes → 2 real R1 matches
$cons6 = DrawPlan::consolation( $main6 );
check( 'Consolation 6: 2 R1 losers → 1 match', count( $cons6['matches'] ) === 1 && count( $cons6['feeds'] ) === 2 );
$main8 = DrawPlan::knockout( range( 1, 8 ) );
$cons8 = DrawPlan::consolation( $main8 );
check( 'Consolation 8: 4 R1 losers → 3 matches', count( $cons8['matches'] ) === 3 && count( $cons8['feeds'] ) === 4 );
check( 'Consolation 8: every R1 main position feeds a distinct slot', count( array_unique( array_map( fn( $f ) => $f['position'] . $f['slot'], $cons8['feeds'] ) ) ) === 4 && count( array_intersect( array_keys( $cons8['feeds'] ), array( 0, 1, 2, 3 ) ) ) === 4 );
check( 'Consolation: nobody known yet', array_filter( array_merge( array_column( $cons8['matches'], 'a' ), array_column( $cons8['matches'], 'b' ), array_column( $cons8['matches'], 'winner' ) ) ) === array() );
$main10 = DrawPlan::knockout( range( 1, 10 ) );                   // 16-bracket, 6 byes → 2 real R1 matches
check( 'Consolation 10: 2 real R1 matches → 1 match', count( DrawPlan::consolation( $main10 )['matches'] ) === 1 );
$main12 = DrawPlan::knockout( range( 1, 12 ) );                   // 16-bracket, 4 byes → 4 real R1 matches
check( 'Consolation 12: 4 losers', count( DrawPlan::consolation( $main12 )['feeds'] ) === 4 );
$main5  = DrawPlan::knockout( range( 1, 5 ) );                    // 8-bracket, 3 byes → 1 real R1 match
check( 'Consolation 5: 1 loser → no consolation', DrawPlan::consolation( $main5 ) === array( 'matches' => array(), 'feeds' => array() ) );
$main7 = DrawPlan::knockout( range( 1, 7 ) );                     // 3 real R1 matches → 3 losers, 1 bye in consolation
$cons7 = DrawPlan::consolation( $main7 );
check( 'Consolation 7: 3 losers, 1 bye, 3 matches', count( $cons7['feeds'] ) === 3 && count( array_filter( $cons7['matches'], fn( $m ) => $m['is_bye'] ) ) === 1 && count( $cons7['matches'] ) === 3 );

// --- MessageTemplate ---
use PadelCamps\Engine\MessageTemplate as MT;
check( 'tpl render', MT::render( 'Hola {nom}, {torneig}!', array( 'nom' => 'Anna', 'torneig' => 'Open' ) ) === 'Hola Anna, Open!' );
check( 'tpl unknown kept', MT::render( 'Hola {nomm}', array( 'nom' => 'Anna' ) ) === 'Hola {nomm}' );
check( 'tpl repeated', MT::render( '{nom}{nom}', array( 'nom' => 'x' ) ) === 'xx' );
check( 'tpl no recursion', MT::render( '{nom}', array( 'nom' => '{torneig}', 'torneig' => 'T' ) ) === '{torneig}' );
check( 'tpl keys', MT::keys( '{a} {nom} {a}' ) === array( 'a', 'nom' ) );
check( 'tpl unknown', MT::unknown( '{nom} {enllac} {link} {Nom}' ) === array( 'link' ) );
check( 'tpl braces untouched', MT::render( '{ nom } {}', array( 'nom' => 'x' ) ) === '{ nom } {}' );
check( 'first name', MT::first_name( '  Anna Puig Roca ' ) === 'Anna' && MT::first_name( 'Pau' ) === 'Pau' && MT::first_name( '' ) === '' );

// --- CommsSettings ---
use PadelCamps\Engine\CommsSettings as CS;
$cs = CS::normalize( null );
check( 'comms defaults all on', count( array_filter( $cs['auto'] ) ) === count( CS::EVENTS ) && $cs['both_players'] );
$cs = CS::normalize( array( 'auto' => array( 'paid' => '', 'bogus' => true ), 'from_name' => "Club\r\nBcc: x@y.z", 'reply_to' => 'not an email', 'signature' => "Línia 1\r\nLínia 2", 'both_players' => 0 ) );
check( 'comms toggle off', ! CS::enabled( $cs, 'paid' ) && CS::enabled( $cs, 'confirmed' ) );
check( 'comms unknown event ignored', ! isset( $cs['auto']['bogus'] ) );
check( 'comms from_name one line', false === strpos( $cs['from_name'], "\n" ) && false === strpos( $cs['from_name'], "\r" ) );
check( 'comms bad reply_to dropped', '' === $cs['reply_to'] );
check( 'comms signature no CR', "Línia 1\nLínia 2" === $cs['signature'] );
check( 'comms both_players off', false === $cs['both_players'] );
check( 'comms good reply_to', 'org@club.cat' === CS::normalize( array( 'reply_to' => ' org@club.cat ' ) )['reply_to'] );

// --- ScheduleDigest ---
use PadelCamps\Engine\ScheduleDigest as SD;
$items = array(
	array( 'id' => 5, 'at' => '2026-12-05 09:00:00', 'court' => 1, 'rival' => 7 ),
	array( 'id' => 9, 'at' => null, 'court' => 0, 'rival' => 0 ),
);
$fp = SD::fingerprint( $items );
check( 'digest empty', '' === SD::fingerprint( array() ) );
check( 'digest length', 16 === strlen( $fp ) );
check( 'digest order independent', SD::fingerprint( array_reverse( $items ) ) === $fp );
$moved          = $items;
$moved[0]['at'] = '2026-12-05 10:30:00';
check( 'digest time change', SD::fingerprint( $moved ) !== $fp );
$court             = $items;
$court[0]['court'] = 2;
check( 'digest court change', SD::fingerprint( $court ) !== $fp );
$rival             = $items;
$rival[1]['rival'] = 12;
check( 'digest rival known', SD::fingerprint( $rival ) !== $fp );
check( 'digest match played (removed)', SD::fingerprint( array( $items[1] ) ) !== $fp );
check( 'state none', SD::NONE === SD::state( '', 'abc' ) );
check( 'state new', SD::NEW === SD::state( $fp, null ) && SD::NEW === SD::state( $fp, '' ) );
check( 'state same', SD::SAME === SD::state( $fp, $fp ) );
check( 'state changed', SD::CHANGED === SD::state( $fp, 'x' ) );
check( 'needs sending', SD::needs_sending( SD::NEW ) && SD::needs_sending( SD::CHANGED ) && ! SD::needs_sending( SD::SAME ) && ! SD::needs_sending( SD::NONE ) );

// --- ManualRegistration ---
use PadelCamps\Engine\ManualRegistration as MR;
$ok = MR::validate( array( 'category_id' => '7', 'p1_name' => ' Anna  Puig ', 'p2_name' => 'Pau Roca', 'p1_email' => 'ANNA@x.cat ', 'p2_phone' => '600 11 22 33', 'p1_club' => '<b>CT</b> Vic', 'status' => 'pending', 'payment' => 'paid', 'lang' => 'es', 'rating' => '7,5', 'notes' => "Per telèfon\n<script>x</script>" ), array( 7, 8 ), array( 'ca', 'es', 'en' ) );
check( 'manual ok', array() === $ok['errors'] );
check( 'manual clean names', 'Anna Puig' === $ok['clean']['players'][0]['name'] && 'Pau Roca' === $ok['clean']['players'][1]['name'] );
check( 'manual email lowercased', 'anna@x.cat' === $ok['clean']['players'][0]['email'] && '' === $ok['clean']['players'][1]['email'] );
check( 'manual phone kept', '600 11 22 33' === $ok['clean']['players'][1]['phone'] );
check( 'manual club stripped', 'CT Vic' === $ok['clean']['players'][0]['club'] );
check( 'manual status/payment/lang', 'pending' === $ok['clean']['status'] && 'paid' === $ok['clean']['payment'] && 'es' === $ok['clean']['lang'] );
check( 'manual rating comma', 7.5 === $ok['clean']['rating'] );
check( 'manual notes stripped', false === strpos( $ok['clean']['notes'], '<script>' ) );
check( 'manual player shape for create()', array_key_exists( 'dni', $ok['clean']['players'][0] ) && array() === $ok['clean']['players'][0]['pack'] );
$bad = MR::validate( array( 'category_id' => '9', 'p1_name' => '', 'p2_name' => 'X', 'p1_email' => 'nope', 'p2_phone' => 'abc', 'status' => 'hacked', 'payment' => 'free', 'lang' => 'fr', 'rating' => 'molt' ), array( 7 ), array( 'ca', 'es' ) );
check( 'manual category not allowed', 'required' === ( $bad['errors']['category_id'] ?? '' ) );
check( 'manual name required', 'required' === ( $bad['errors']['p1_name'] ?? '' ) );
check( 'manual bad email', 'invalid_email' === ( $bad['errors']['p1_email'] ?? '' ) );
check( 'manual bad phone', 'invalid_phone' === ( $bad['errors']['p2_phone'] ?? '' ) );
check( 'manual bad rating', 'invalid' === ( $bad['errors']['rating'] ?? '' ) );
check( 'manual defaults', 'confirmed' === $bad['clean']['status'] && 'pending' === $bad['clean']['payment'] && 'ca' === $bad['clean']['lang'] );
$same = MR::players( array( 'p1_name' => 'A', 'p2_name' => 'B', 'p1_email' => 'a@b.cat', 'p2_email' => 'A@B.cat' ) );
check( 'manual same email twice', 'same_email' === ( $same['errors']['p2_email'] ?? '' ) );
check( 'manual no email ok', array() === MR::players( array( 'p1_name' => 'A', 'p2_name' => 'B' ) )['errors'] );

// --- CategoryBulk ---
use PadelCamps\Engine\CategoryBulk as CB;
$presets = array( 'sets' => array( 'wins', 'set_diff', 'game_diff', 'head_to_head' ), 'direct' => array( 'wins', 'head_to_head', 'set_diff', 'game_diff' ) );
$none    = CB::changes( array(), $presets );
check( 'bulk nothing', ! CB::has_changes( $none ) && null === $none['consolation'] && array() === $none['format'] );
$ch = CB::changes( array( 'consolation' => 'on', 'advance' => '3', 'best_of' => '1', 'games' => '', 'criteria' => 'direct', 'max_teams' => '0', 'price' => '12,5' ), $presets );
check( 'bulk parse', true === $ch['consolation'] && 3 === $ch['advance'] && array( 'best_of' => 1 ) === $ch['format'] && 'direct' === $ch['criteria'] && 0 === $ch['max_teams'] && 12.5 === $ch['price'] && CB::has_changes( $ch ) );
check( 'bulk parse off / junk', false === CB::changes( array( 'consolation' => 'off' ), $presets )['consolation'] && null === CB::changes( array( 'advance' => '9', 'criteria' => 'x', 'max_teams' => '-1', 'price' => 'abc' ), $presets )['advance'] );
$gk  = array(
	array( 'id' => 1, 'type' => 'groups', 'settings' => array( 'advance' => 2, 'format' => array( 'best_of' => 3, 'games' => 6, 'deciding' => 'super_tiebreak', 'points' => 10 ) ) ),
	array( 'id' => 2, 'type' => 'knockout', 'settings' => array( 'consolation' => false ) ),
);
$free = array( 'matches' => false, 'knockout' => false );
$r    = CB::apply( 'groups_knockout', $gk, CB::changes( array( 'consolation' => 'on' ), $presets ), $free, $presets );
check( 'bulk consolation on', true === $r['settings'][2]['consolation'] && ! isset( $r['settings'][1] ) && array( 'consolation' ) === $r['applied'] );
$r = CB::apply( 'groups_knockout', $gk, CB::changes( array( 'consolation' => 'on', 'advance' => '1' ), $presets ), array( 'matches' => true, 'knockout' => false ), $presets );
check( 'bulk consolation allowed with group matches', true === $r['settings'][2]['consolation'] && 1 === $r['settings'][1]['advance'] && array() === $r['locked'] );
$r = CB::apply( 'groups_knockout', $gk, CB::changes( array( 'consolation' => 'on', 'best_of' => '1' ), $presets ), array( 'matches' => true, 'knockout' => true ), $presets );
check( 'bulk locked by knockout', array() === $r['settings'] && array( 'consolation', 'format' ) === $r['locked'] );
$r = CB::apply( 'groups_knockout', $gk, CB::changes( array( 'best_of' => '1' ), $presets ), array( 'matches' => true, 'knockout' => false ), $presets );
check( 'bulk format locked by any match', array( 'format' ) === $r['locked'] && array() === $r['settings'] );
$r = CB::apply( 'groups_knockout', $gk, CB::changes( array( 'games' => '4', 'criteria' => 'direct' ), $presets ), $free, $presets );
check( 'bulk format merged', 4 === $r['settings'][1]['format']['games'] && 3 === $r['settings'][1]['format']['best_of'] && 4 === $r['settings'][2]['format']['games'] );
check( 'bulk criteria groups only', $presets['direct'] === $r['settings'][1]['criteria'] && ! isset( $r['settings'][2]['criteria'] ) );
$rr = CB::apply( 'round_robin', array( array( 'id' => 5, 'type' => 'round_robin', 'settings' => array() ) ), CB::changes( array( 'consolation' => 'on', 'advance' => '2', 'criteria' => 'sets' ), $presets ), $free, $presets );
check( 'bulk n/a for round robin', array( 'consolation', 'advance' ) === $rr['na'] && $presets['sets'] === $rr['settings'][5]['criteria'] );
$same = CB::apply( 'knockout', array( array( 'id' => 7, 'type' => 'knockout', 'settings' => array( 'consolation' => true ) ) ), CB::changes( array( 'consolation' => 'on' ), $presets ), $free, $presets );
check( 'bulk unchanged not returned', array() === $same['settings'] && array( 'consolation' ) === $same['applied'] );

// --- DashboardStats ---
use PadelCamps\Engine\DashboardStats as DS;
$ts = array(
	array( 'id' => 1, 'status' => 'finished', 'starts_on' => '2026-11-01' ),
	array( 'id' => 2, 'status' => 'registration_open', 'starts_on' => '2027-03-01' ),
	array( 'id' => 3, 'status' => 'registration_open', 'starts_on' => '2026-12-05' ),
	array( 'id' => 4, 'status' => 'draft', 'starts_on' => '2026-11-10' ),
);
check( 'active: closest open', 3 === DS::pick_active( $ts, '2026-11-10' ) );
$ts[] = array( 'id' => 5, 'status' => 'in_progress', 'starts_on' => null );
check( 'active: in progress wins', 5 === DS::pick_active( $ts, '2026-11-10' ) );
check( 'active: none', null === DS::pick_active( array(), '2026-11-10' ) );
check( 'active: only drafts', 9 === DS::pick_active( array( array( 'id' => 8, 'status' => 'draft', 'starts_on' => null ), array( 'id' => 9, 'status' => 'draft', 'starts_on' => null ) ), '2026-11-10' ) );
check( 'active: finished before draft', 1 === DS::pick_active( array( array( 'id' => 1, 'status' => 'finished', 'starts_on' => '2026-01-01' ), array( 'id' => 2, 'status' => 'draft', 'starts_on' => '2026-11-10' ) ), '2026-11-10' ) );
check( 'countdown before', array( 'state' => 'before', 'days' => 25, 'day' => 0, 'total' => 2 ) === DS::countdown( '2026-12-05', '2026-12-06', '2026-11-10' ) );
check( 'countdown during day 2', array( 'state' => 'during', 'days' => 0, 'day' => 2, 'total' => 2 ) === DS::countdown( '2026-12-05', '2026-12-06', '2026-12-06' ) );
check( 'countdown one-day today', 'during' === DS::countdown( '2026-11-10', null, '2026-11-10' )['state'] );
check( 'countdown after', array( 'state' => 'after', 'days' => 3, 'day' => 0, 'total' => 2 ) === DS::countdown( '2026-12-05', '2026-12-06', '2026-12-09' ) );
check( 'countdown bad end ignored', 1 === DS::countdown( '2026-12-05', '2026-12-01', '2026-11-10' )['total'] );
check( 'countdown unknown', 'unknown' === DS::countdown( null, null, '2026-11-10' )['state'] );
check( 'countdown across DST', 1 === DS::countdown( '2026-10-26', null, '2026-10-25' )['days'] );
check( 'percent', 33 === DS::percent( 1, 3 ) && 0 === DS::percent( 5, 0 ) && 100 === DS::percent( 9, 8 ) && 100 === DS::percent( 8, 8 ) );

// --- I18n: Catalog::scan_source ---
use PadelCamps\I18n\Catalog;

$sample = <<<'PHP'
<?php
echo __( 'Hola', 'padelcamps' ); _e( "Adéu", 'padelcamps' ); esc_html__( 'Altre', 'other-domain' );
$x = _n( 'Un', 'Molts', $n, 'padelcamps' ); _x( 'Pistes', 'menu', 'padelcamps' );
__( 'a' . 'b', 'padelcamps' ); $o->__( 'Mètode', 'padelcamps' );
printf( esc_html__( 'Dins %s', 'padelcamps' ), __( 'niat', 'padelcamps' ) );
__( 'It\'s', 'padelcamps' );
PHP;
$found = Catalog::scan_source( $sample, 'x.php' );
$ids   = array_map( fn( $f ) => Catalog::make_key( $f['msgid'], $f['context'] ), $found );
check( 'scan finds 7 strings', count( $found ) === 7 );
check( 'scan: __ and _e', in_array( 'Hola', $ids, true ) && in_array( 'Adéu', $ids, true ) );
check( 'scan: ignores other domain', ! in_array( 'Altre', $ids, true ) );
check( 'scan: ignores concatenation', ! in_array( 'ab', $ids, true ) );
check( 'scan: ignores method calls', ! in_array( 'Mètode', $ids, true ) );
check( 'scan: context key', in_array( "menu\x04Pistes", $ids, true ) );
check( 'scan: nested calls', in_array( 'Dins %s', $ids, true ) && in_array( 'niat', $ids, true ) );
check( 'scan: escaped quote', in_array( "It's", $ids, true ) );
$plural = array_values( array_filter( $found, fn( $f ) => 'Un' === $f['msgid'] ) );
check( 'scan: plural form', 'Molts' === $plural[0]['plural'] );

// --- I18n: helpers ---
check( 'lang_of', Catalog::lang_of( 'ca_ES' ) === 'ca' && Catalog::lang_of( 'es-ES' ) === 'es' && Catalog::lang_of( 'en' ) === 'en' );
check( 'placeholders equal', Catalog::placeholders_match( 'Hi %s, %d', 'Hola %s, %d' ) );
check( 'placeholders positional reorder', Catalog::placeholders_match( '%1$d of %2$s', '%2$s de %1$d' ) );
check( 'placeholders missing', ! Catalog::placeholders_match( 'Hi %s', 'Hola' ) );
check( 'placeholders extra', ! Catalog::placeholders_match( 'Hi', 'Hola %s' ) );
check( 'placeholders type differs', ! Catalog::placeholders_match( '%d', '%s' ) );
check( 'escaped percent ignored', Catalog::placeholders_match( '100%% %s', '100%% %s' ) );

// --- I18n: every string of the plugin is translated in the shipped languages ---
$catalog = Catalog::scan( dirname( __DIR__ ) );
check( 'catalog not empty', count( $catalog ) > 0 );
foreach ( array( 'es', 'en' ) as $lang ) {
	$map = require dirname( __DIR__ ) . "/languages/$lang.php";
	foreach ( $catalog as $key => $e ) {
		$t = $map[ $key ] ?? null;
		if ( null !== $e['plural'] ) {
			check( "[$lang] plural translated: {$e['msgid']}", is_array( $t ) && '' !== ( $t[0] ?? '' ) && '' !== ( $t[1] ?? '' ) );
			check( "[$lang] plural placeholders: {$e['msgid']}", is_array( $t ) && Catalog::placeholders_match( $e['msgid'], $t[0] ?? '' ) && Catalog::placeholders_match( $e['plural'], $t[1] ?? '' ) );
		} else {
			check( "[$lang] translated: {$e['msgid']}", is_string( $t ) && '' !== $t );
			check( "[$lang] placeholders: {$e['msgid']}", is_string( $t ) && Catalog::placeholders_match( $e['msgid'], $t ) );
		}
	}
	foreach ( array_keys( $map ) as $key ) {
		check( "[$lang] no stale entry: " . substr( $key, 0, 40 ), isset( $catalog[ $key ] ) );
	}
}

echo $fail ? "\n$fail of $total FAILED\n" : "OK: $total checks passed\n";
exit( $fail ? 1 : 0 );
