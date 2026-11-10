<?php
namespace PadelCamps\Engine;

/**
 * Validation of registrations entered by the organizer from the admin
 * (phone, WhatsApp, at the club) and of edits to a pair's player data.
 * Looser than the public form: only the two names are required, there are
 * no consents or welcome pack, and the organizer chooses status and payment.
 * Pure PHP, no WordPress.
 */
final class ManualRegistration {

	public const STATUSES = array( 'confirmed', 'pending', 'waitlist' );
	public const PAYMENTS = array( 'pending', 'paid' );

	/**
	 * Players p1_* / p2_*: name (required), email, phone, club (optional).
	 *
	 * @return array{errors: array<string,string>, players: array<int, array{name:string, email:string, phone:string, club:string, dni:string, pack:array}>}
	 */
	public static function players( array $input ): array {
		$errors  = array();
		$players = array();
		foreach ( array( 1, 2 ) as $n ) {
			$name      = RegistrationForm::clean_text( $input[ "p{$n}_name" ] ?? '', 120 );
			$raw_email = is_string( $input[ "p{$n}_email" ] ?? null ) ? trim( $input[ "p{$n}_email" ] ) : '';
			$email     = RegistrationForm::clean_email( $raw_email );
			$raw_phone = is_string( $input[ "p{$n}_phone" ] ?? null ) ? trim( $input[ "p{$n}_phone" ] ) : '';
			$phone     = RegistrationForm::clean_phone( $raw_phone );
			if ( '' === $name ) {
				$errors[ "p{$n}_name" ] = 'required';
			}
			if ( '' !== $raw_email && null === $email ) {
				$errors[ "p{$n}_email" ] = 'invalid_email';
			}
			if ( '' !== $raw_phone && null === $phone ) {
				$errors[ "p{$n}_phone" ] = 'invalid_phone';
			}
			$players[] = array(
				'name'  => $name,
				'email' => $email ?? '',
				'phone' => $phone ?? '',
				'club'  => RegistrationForm::clean_text( $input[ "p{$n}_club" ] ?? '', 120 ),
				'dni'   => '',
				'pack'  => array(),
			);
		}
		if ( '' !== $players[0]['email'] && $players[0]['email'] === $players[1]['email'] ) {
			$errors['p2_email'] = 'same_email';
		}
		return array( 'errors' => $errors, 'players' => $players );
	}

	/**
	 * A new registration from the admin.
	 *
	 * @param int[]    $category_ids categories the organizer may use
	 * @param string[] $langs        accepted email languages
	 * @return array{errors: array<string,string>, clean: array}
	 */
	public static function validate( array $input, array $category_ids, array $langs ): array {
		$p        = self::players( $input );
		$errors   = $p['errors'];
		$category = (int) ( $input['category_id'] ?? 0 );
		if ( ! in_array( $category, array_map( 'intval', $category_ids ), true ) ) {
			$errors['category_id'] = 'required';
		}
		$status  = in_array( $input['status'] ?? '', self::STATUSES, true ) ? $input['status'] : 'confirmed';
		$payment = in_array( $input['payment'] ?? '', self::PAYMENTS, true ) ? $input['payment'] : 'pending';
		$lang    = in_array( $input['lang'] ?? '', $langs, true ) ? $input['lang'] : ( $langs[0] ?? '' );

		$rating_raw = trim( str_replace( ',', '.', (string) ( $input['rating'] ?? '' ) ) );
		$rating     = null;
		if ( '' !== $rating_raw ) {
			if ( is_numeric( $rating_raw ) ) {
				$rating = max( 0.0, min( 99.99, round( (float) $rating_raw, 2 ) ) );
			} else {
				$errors['rating'] = 'invalid';
			}
		}

		return array(
			'errors' => $errors,
			'clean'  => array(
				'category_id' => $category,
				'players'     => $p['players'],
				'status'      => $status,
				'payment'     => $payment,
				'lang'        => $lang,
				'rating'      => $rating,
				'notes'       => RegistrationForm::clean_multiline( $input['notes'] ?? '', 2000 ),
			),
		);
	}
}
