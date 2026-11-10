<?php
/**
 * Runs encoder processes without shell interpolation.
 *
 * @package Jocky\ImageOptimizer\System
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\System;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Process pipes and private bounded capture files are native local resources managed alongside proc_open().
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The bounded adapter API requires argv-based local execution with shell bypass; no WordPress alternative provides that contract.

use Jocky\ImageOptimizer\Optimization\OptimizationException;

/** Executes a validated absolute executable using argv form and bounded capture. */
final class ProcessRunner {
	private const CAPTURE_LIMIT = 65536;

	/** Runs one process with bounded capture and execution time.
	 *
	 * @param string   $executable     Absolute executable path.
	 * @param string[] $arguments      Argument vector excluding executable.
	 * @param float    $timeout_seconds Maximum runtime in seconds.
	 * @return ProcessResult Captured process result.
	 * @throws OptimizationException When the process cannot start, times out, or arguments are invalid.
	 */
	public function run( string $executable, array $arguments, float $timeout_seconds ): ProcessResult {
		if ( ! is_file( $executable ) || ! is_readable( $executable ) || ( 'Windows' !== PHP_OS_FAMILY && ! is_executable( $executable ) ) || $timeout_seconds <= 0 || $timeout_seconds > 600 ) {
			throw new OptimizationException( 'process_precondition_failed', 'The executable or timeout is invalid.' );
		}
		foreach ( $arguments as $argument ) {
			if ( ! is_string( $argument ) || str_contains( $argument, "\0" ) ) {
				throw new OptimizationException( 'invalid_process_argument', 'An encoder argument is invalid.' );
			}
		}
		$is_windows        = 'Windows' === PHP_OS_FAMILY;
		$capture_directory = $is_windows ? $this->create_capture_directory() : null;
		$stdout_path       = null === $capture_directory ? null : $capture_directory . DIRECTORY_SEPARATOR . 'stdout.log';
		$stderr_path       = null === $capture_directory ? null : $capture_directory . DIRECTORY_SEPARATOR . 'stderr.log';
		$descriptors       = array(
			0 => array( 'pipe', 'r' ),
			1 => $is_windows ? array( 'file', (string) $stdout_path, 'w' ) : array( 'pipe', 'w' ),
			2 => $is_windows ? array( 'file', (string) $stderr_path, 'w' ) : array( 'pipe', 'w' ),
		);
		$environment       = array();
		foreach ( array( 'PATH', 'TEMP', 'TMP', 'TMPDIR', 'SystemRoot', 'WINDIR' ) as $key ) {
			$value = getenv( $key );
			if ( is_string( $value ) ) {
				$environment[ $key ] = $value;
			}
		}
		$command = array_merge( array( $executable ), $arguments );
		$process = proc_open( $command, $descriptors, $pipes, null, $environment, array( 'bypass_shell' => true ) );
		if ( ! is_resource( $process ) ) {
			if ( null !== $capture_directory ) {
				$this->remove_capture_directory( $capture_directory );
			}
			throw new OptimizationException( 'process_start_failed', 'The image encoder process could not be started.' );
		}
		fclose( $pipes[0] );
		if ( ! $is_windows ) {
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );
		}
		$stdout          = '';
		$stderr          = '';
		$start           = microtime( true );
		$timed_out       = false;
		$output_exceeded = false;
		try {
			while ( true ) {
				if ( $is_windows ) {
					$stdout          = $this->read_capture_file( (string) $stdout_path );
					$stderr          = $this->read_capture_file( (string) $stderr_path );
					$output_exceeded = $this->capture_file_size( (string) $stdout_path ) > self::CAPTURE_LIMIT || $this->capture_file_size( (string) $stderr_path ) > self::CAPTURE_LIMIT;
				} else {
					$stdout = $this->read_bounded( $pipes[1], $stdout );
					$stderr = $this->read_bounded( $pipes[2], $stderr );
				}
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) {
					break;
				}
				if ( $output_exceeded ) {
					proc_terminate( $process, 9 );
					break;
				}
				if ( microtime( true ) - $start > $timeout_seconds ) {
					$timed_out = true;
					proc_terminate( $process, 9 );
					break;
				}
				usleep( $is_windows ? 1000 : 20000 );
			}
			if ( $is_windows ) {
				$stdout          = $this->read_capture_file( (string) $stdout_path );
				$stderr          = $this->read_capture_file( (string) $stderr_path );
				$output_exceeded = $output_exceeded || $this->capture_file_size( (string) $stdout_path ) > self::CAPTURE_LIMIT || $this->capture_file_size( (string) $stderr_path ) > self::CAPTURE_LIMIT;
			} else {
				$stdout = $this->read_bounded( $pipes[1], $stdout );
				$stderr = $this->read_bounded( $pipes[2], $stderr );
			}
		} finally {
			if ( ! $is_windows ) {
				fclose( $pipes[1] );
				fclose( $pipes[2] );
			}
			$closed_code = proc_close( $process );
			if ( null !== $capture_directory ) {
				$this->remove_capture_directory( $capture_directory );
			}
		}
		if ( $output_exceeded ) {
			throw new OptimizationException( 'encoder_output_limit', 'The image encoder exceeded the output capture limit.' );
		}
		if ( $timed_out ) {
			throw new OptimizationException( 'encoder_timeout', 'The image encoder exceeded its time limit.' );
		}
		$exit_code = $closed_code >= 0 ? $closed_code : ( isset( $status['exitcode'] ) ? (int) $status['exitcode'] : 1 );
		return new ProcessResult( $exit_code, $stdout, $stderr, microtime( true ) - $start );
	}

	/** Drains a pipe while retaining at most CAPTURE_LIMIT bytes.
	 *
	 * @param resource $pipe   Non-blocking process pipe.
	 * @param string   $buffer Existing bounded capture.
	 * @return string Updated bounded capture.
	 */
	private function read_bounded( $pipe, string $buffer ): string {
		$data = stream_get_contents( $pipe, 8192 );
		if ( is_string( $data ) && strlen( $buffer ) < self::CAPTURE_LIMIT ) {
			$buffer .= substr( $data, 0, self::CAPTURE_LIMIT - strlen( $buffer ) );
		}
		return $buffer;
	}

	/** Creates a private temporary directory for Windows process output files.
	 *
	 * @return string New capture directory path.
	 * @throws OptimizationException When a private directory cannot be created.
	 */
	private function create_capture_directory(): string {
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$directory = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.jio-process-' . bin2hex( random_bytes( 12 ) );
			if ( mkdir( $directory, 0700 ) ) {
				if ( chmod( $directory, 0700 ) ) {
					return $directory;
				}
				rmdir( $directory );
				throw new OptimizationException( 'process_capture_failed', 'A private process capture directory could not be secured.' );
			}
		}
		throw new OptimizationException( 'process_capture_failed', 'A private process capture directory could not be created.' );
	}

	/** Reads the bounded prefix of a Windows process capture file.
	 *
	 * @param string $path Process capture path.
	 * @return string Captured bytes, limited to CAPTURE_LIMIT.
	 * @throws OptimizationException When capture cannot be read.
	 */
	private function read_capture_file( string $path ): string {
		$output = file_get_contents( $path, false, null, 0, self::CAPTURE_LIMIT );
		if ( ! is_string( $output ) ) {
			throw new OptimizationException( 'process_capture_failed', 'The process output could not be read.' );
		}
		return $output;
	}

	/** Measures the latest Windows capture-file size.
	 *
	 * @param string $path Process capture path.
	 * @return int Current capture size in bytes.
	 * @throws OptimizationException When the capture file cannot be measured.
	 */
	private function capture_file_size( string $path ): int {
		clearstatcache( true, $path );
		$size = filesize( $path );
		if ( false === $size ) {
			throw new OptimizationException( 'process_capture_failed', 'The process output size could not be measured.' );
		}
		return $size;
	}

	/** Removes only the two known process capture files and their private directory.
	 *
	 * @param string $directory Capture directory created by this runner.
	 * @throws OptimizationException When capture cleanup fails.
	 */
	private function remove_capture_directory( string $directory ): void {
		foreach ( array( 'stdout.log', 'stderr.log' ) as $name ) {
			$file = $directory . DIRECTORY_SEPARATOR . $name;
			if ( is_file( $file ) && ! is_link( $file ) && ! unlink( $file ) ) {
				throw new OptimizationException( 'process_capture_cleanup_failed', 'A process capture file could not be removed.' );
			}
		}
		if ( is_dir( $directory ) && ! is_link( $directory ) && ! rmdir( $directory ) ) {
			throw new OptimizationException( 'process_capture_cleanup_failed', 'A process capture directory could not be removed.' );
		}
	}
}
