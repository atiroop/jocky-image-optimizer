<?php
/**
 * Default settings and input validation.
 *
 * @package Jocky\ImageOptimizer
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer;

/** Normalizes values stored through the WordPress Settings API. */
final class Settings {

	/** Returns safe initial values for all settings.
	 *
	 * @return array<string, mixed> Default settings.
	 */
	public static function defaults(): array {
		return array(
			'output_format'       => 'original',
			'image_quality'       => 82,
			'minimum_quality'     => 65,
			'max_width'           => 0,
			'max_height'          => 0,
			'auto_optimize'       => false,
			'keep_backup'         => true,
			'concurrency'         => 1,
			'frontend_delivery'   => false,
			'delivery_preference' => 'auto',
		);
	}

	/** Sanitizes untrusted settings input.
	 *
	 * @param mixed $input Submitted setting data.
	 * @return array<string, mixed> Normalized setting values.
	 */
	public static function sanitize( $input ): array {
		$input      = is_array( $input ) ? $input : array();
		$defaults   = self::defaults();
		$format     = isset( $input['output_format'] ) && is_string( $input['output_format'] ) ? $input['output_format'] : '';
		$formats    = array( 'original', 'webp', 'avif', 'both' );
		$quality    = self::bounded_int( $input['image_quality'] ?? null, 82, 1, 100 );
		$preference = isset( $input['delivery_preference'] ) && is_string( $input['delivery_preference'] ) ? $input['delivery_preference'] : '';

		return array(
			'output_format'       => in_array( $format, $formats, true ) ? $format : $defaults['output_format'],
			'image_quality'       => $quality,
			'minimum_quality'     => min( $quality, self::bounded_int( $input['minimum_quality'] ?? null, 65, 1, 100 ) ),
			'max_width'           => self::bounded_int( $input['max_width'] ?? null, 0, 0, 20000 ),
			'max_height'          => self::bounded_int( $input['max_height'] ?? null, 0, 0, 20000 ),
			'auto_optimize'       => self::normalize_boolean( $input['auto_optimize'] ?? false, false ),
			'keep_backup'         => self::normalize_boolean( $input['keep_backup'] ?? true, true ),
			'concurrency'         => self::bounded_int( $input['concurrency'] ?? null, 1, 1, 4 ),
			'frontend_delivery'   => self::normalize_boolean( $input['frontend_delivery'] ?? false, false ),
			'delivery_preference' => in_array( $preference, array( 'auto', 'prefer_webp', 'webp_only', 'avif_only' ), true ) ? $preference : 'auto',
		);
	}

	/** Returns a numeric value clamped to the requested bounds.
	 *
	 * @param mixed $value    Untrusted candidate value.
	 * @param int   $fallback Value used for non-numeric input.
	 * @param int   $min      Minimum allowed value.
	 * @param int   $max      Maximum allowed value.
	 * @return int Sanitized integer.
	 */
	private static function bounded_int( $value, int $fallback, int $min, int $max ): int {
		if ( ! is_scalar( $value ) || ! is_numeric( (string) $value ) ) {
			return $fallback;
		}
		return min( $max, max( $min, (int) $value ) );
	}

	/** Converts only recognized checkbox values to booleans.
	 *
	 * @param mixed $value    Untrusted candidate value.
	 * @param bool  $fallback Value used for unrecognized input.
	 * @return bool Sanitized boolean.
	 */
	private static function normalize_boolean( $value, bool $fallback ): bool {
		if ( true === $value || 1 === $value || '1' === $value ) {
			return true;
		}
		if ( false === $value || 0 === $value || '0' === $value ) {
			return false;
		}
		return $fallback;
	}
}
