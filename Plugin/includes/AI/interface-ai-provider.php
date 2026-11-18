<?php
/**
 * AI Provider Interface.
 *
 * @package AIChatbot\AI
 */

namespace AIChatbot\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Interface AIProviderInterface.
 */
interface AIProviderInterface {

	/**
	 * Unique provider slug.
	 *
	 * @return string Slug (e.g. 'openai', 'groq').
	 */
	public function get_id(): string;

	/**
	 * Provider display name.
	 *
	 * @return string Display name.
	 */
	public function get_name(): string;

	/**
	 * Whether this provider supports chat completions.
	 *
	 * @return bool
	 */
	public function supports_chat(): bool;

	/**
	 * Whether this provider supports text embeddings.
	 *
	 * @return bool
	 */
	public function supports_embeddings(): bool;

	/**
	 * Test API connection and return available models or status.
	 *
	 * @param string $api_key Plaintext API key to test.
	 * @return array{success: bool, message: string, models?: array}
	 */
	public function test_connection( string $api_key ): array;

	/**
	 * Fetch available models dynamically from provider endpoint.
	 *
	 * @param string $api_key API key.
	 * @return array List of model IDs.
	 */
	public function list_models( string $api_key ): array;

	/**
	 * Execute chat completion request.
	 *
	 * @param array  $messages Array of messages [['role' => 'user', 'content' => '...']].
	 * @param array  $options Configuration options (model, temperature, max_tokens, top_p).
	 * @param string $api_key Plaintext API key.
	 * @return array{content: string, tokens: int, raw?: array, error?: string, status_code?: int, headers?: array}
	 */
	public function chat( array $messages, array $options, string $api_key ): array;

	/**
	 * Generate text embeddings for one or more strings.
	 *
	 * @param string|array $input Single string or array of strings.
	 * @param string       $model Embedding model ID.
	 * @param string       $api_key Plaintext API key.
	 * @return array Array of float arrays (or empty on failure).
	 */
	public function generate_embeddings( string|array $input, string $model, string $api_key ): array;
}
