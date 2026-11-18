<?php
/**
 * Autoloader for AIChatbot classes.
 *
 * @package AIChatbot
 */

namespace AIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader class.
 */
class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload handler.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public static function autoload( string $class ): void {
		$prefix   = 'AIChatbot\\';
		$base_dir = AI_CHATBOT_PATH . 'includes/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$parts          = explode( '\\', $relative_class );
		$class_name     = array_pop( $parts );
		$subpath        = ! empty( $parts ) ? implode( DIRECTORY_SEPARATOR, $parts ) . DIRECTORY_SEPARATOR : '';

		// Generate possible kebab slug variations.
		// Standard regex handles both single uppercase and consecutive acronyms:
		// e.g. "OpenAIProvider" -> "open-ai-provider", "AIProviderInterface" -> "ai-provider-interface"
		$kebab_standard = strtolower( (string) preg_replace( '/(?<=[a-z\d])([A-Z])|(?<=[A-Z])([A-Z][a-z])/', '-$1$2', $class_name ) );
		$kebab_simple   = strtolower( (string) preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) );

		$slugs = array_unique( array(
			$kebab_standard,
			$kebab_simple,
			str_replace( 'open-ai', 'openai', $kebab_standard ),
			str_replace( 'open-ai', 'openai', $kebab_simple ),
			str_replace( '_', '-', $kebab_standard ),
			str_replace( '_', '-', $kebab_simple ),
		) );

		// For each slug, if it ends in -interface, also add the version without -interface.
		$extra_slugs = array();
		foreach ( $slugs as $slug ) {
			if ( str_ends_with( $slug, '-interface' ) ) {
				$extra_slugs[] = substr( $slug, 0, -10 );
			}
		}
		$all_slugs = array_unique( array_merge( $slugs, $extra_slugs ) );

		$candidates = array();
		foreach ( $all_slugs as $slug ) {
			$candidates[] = $base_dir . $subpath . 'class-' . $slug . '.php';
			$candidates[] = $base_dir . $subpath . 'interface-' . $slug . '.php';
			$candidates[] = $base_dir . $subpath . 'trait-' . $slug . '.php';
		}
		$candidates[] = $base_dir . $subpath . $class_name . '.php';

		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
