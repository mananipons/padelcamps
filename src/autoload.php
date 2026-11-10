<?php
/**
 * Minimal PSR-4 autoloader for the PadelCamps\ namespace (maps to /src).
 * Shared by the plugin bootstrap and the standalone test runner.
 */

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'PadelCamps\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
