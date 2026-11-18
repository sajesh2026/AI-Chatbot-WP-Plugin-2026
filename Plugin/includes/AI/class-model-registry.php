<?php
/**
 * Model and Provider Registry.
 *
 * @package AIChatbot\AI
 */

namespace AIChatbot\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Class ModelRegistry.
 */
class ModelRegistry {

	/**
	 * Active registered providers.
	 *
	 * @var array<string, AIProviderInterface>
	 */
	protected static array $providers = array();

	/**
	 * Register available AI providers.
	 *
	 * @return void
	 */
	public static function init(): void {
		self::register( new OpenAIProvider() );
		self::register( new GroqProvider() );
		self::register( new MistralProvider() );
	}

	/**
	 * Register a provider instance.
	 *
	 * @param AIProviderInterface $provider Provider instance.
	 * @return void
	 */
	public static function register( AIProviderInterface $provider ): void {
		self::$providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get provider by ID.
	 *
	 * @param string $id Provider ID.
	 * @return ?AIProviderInterface
	 */
	public static function get( string $id ): ?AIProviderInterface {
		if ( empty( self::$providers ) ) {
			self::init();
		}
		return self::$providers[ $id ] ?? null;
	}

	/**
	 * Get all registered providers.
	 *
	 * @return array<string, AIProviderInterface>
	 */
	public static function all(): array {
		if ( empty( self::$providers ) ) {
			self::init();
		}
		return self::$providers;
	}

	/**
	 * Get chat-capable providers.
	 *
	 * @return array<string, AIProviderInterface>
	 */
	public static function get_chat_providers(): array {
		$all = self::all();
		return array_filter(
			$all,
			fn( $p ) => $p->supports_chat()
		);
	}

	/**
	 * Get embeddings-capable providers.
	 *
	 * @return array<string, AIProviderInterface>
	 */
	public static function get_embeddings_providers(): array {
		$all = self::all();
		return array_filter(
			$all,
			fn( $p ) => $p->supports_embeddings()
		);
	}
}
