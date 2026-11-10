<?php
/**
 * Non-destructive global queue controls.
 *
 * @package Jocky\ImageOptimizer\Queue
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Queue;

/** Stores pause state separately from durable job rows. */
final class QueueState {

	public const PAUSED_OPTION = 'jio_queue_paused';

	/** Returns whether workers should currently claim new jobs. */
	public static function is_paused(): bool {
		return '1' === (string) get_option( self::PAUSED_OPTION, '0' );
	}

	/** Pauses new work while preserving all queue state. */
	public static function pause(): void {
		update_option( self::PAUSED_OPTION, '1', false );
	}

	/** Resumes queued work. */
	public static function resume(): void {
		update_option( self::PAUSED_OPTION, '0', false );
	}
}
