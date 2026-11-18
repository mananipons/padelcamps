<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Validation of what the organizer types on the Players screen: a player's data and a
 * manual points adjustment. Pure PHP, no WordPress.
 */
final class PlayerEdit {

	public const MAX_ADJUSTMENT = 100000;

	/**
	 * A player's data. Name and DNI/NIE are required (the DNI is the identity of the player);
	 * the DNI may stay empty only for players that never had one (`$dni_required` = false),
	 * so editing an old player never forces an unrelated field. Email, phone and club are optional.
	 *
	 * @return array{errors: array<string,string>, clean: array{name:string, dni:string, email:string, phone:string, club:string}}
	 */
	public static function validate( array $input, bool $dni_required = true ): array {
		$errors = array();

		$name = RegistrationForm::clean_text( $input['name'] ?? '', 120 );
		if ( '' === $name ) {
			$errors['name'] = 'required';
		}

		$raw_dni = is_string( $input['dni'] ?? null ) ? trim( $input['dni'] ) : '';
		$dni     = RegistrationForm::clean_dni( $raw_dni );
		if ( '' === $raw_dni ) {
			if ( $dni_required ) {
				$errors['dni'] = 'required';
			}
		} elseif ( null === $dni ) {
			$errors['dni'] = 'invalid_dni';
		}

		$raw_email = is_string( $input['email'] ?? null ) ? trim( $input['email'] ) : '';
		$email     = RegistrationForm::clean_email( $raw_email );
		if ( '' !== $raw_email && null === $email ) {
			$errors['email'] = 'invalid_email';
		}

		$raw_phone = is_string( $input['phone'] ?? null ) ? trim( $input['phone'] ) : '';
		$phone     = RegistrationForm::clean_phone( $raw_phone );
		if ( '' !== $raw_phone && null === $phone ) {
			$errors['phone'] = 'invalid_phone';
		}

		return array(
			'errors' => $errors,
			'clean'  => array(
				'name'  => $name,
				'dni'   => $dni ?? '',
				'email' => $email ?? '',
				'phone' => $phone ?? '',
				'club'  => RegistrationForm::clean_text( $input['club'] ?? '', 190 ),
			),
		);
	}

	/**
	 * A manual points adjustment: a non-zero whole number (negative = penalty) and a note
	 * saying why (it is the audit trail).
	 *
	 * @return array{errors: array<string,string>, clean: array{points:int, note:string}}
	 */
	public static function adjustment( array $input ): array {
		$errors = array();
		$raw    = is_string( $input['points'] ?? null ) ? trim( $input['points'] ) : ( is_int( $input['points'] ?? null ) ? (string) $input['points'] : '' );
		$points = 0;
		if ( '' === $raw ) {
			$errors['points'] = 'required';
		} elseif ( ! preg_match( '/^[+-]?\d{1,6}$/', $raw ) ) {
			$errors['points'] = 'invalid';
		} else {
			$points = (int) $raw;
			if ( 0 === $points || abs( $points ) > self::MAX_ADJUSTMENT ) {
				$errors['points'] = 'invalid';
			}
		}

		$note = RegistrationForm::clean_text( $input['note'] ?? '', 190 );
		if ( '' === $note ) {
			$errors['note'] = 'required';
		}

		return array(
			'errors' => $errors,
			'clean'  => array(
				'points' => $points,
				'note'   => $note,
			),
		);
	}
}
