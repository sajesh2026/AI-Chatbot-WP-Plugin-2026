<?php
/**
 * Groq Provider implementation.
 *
 * @package AIChatbot\AI
 */

namespace AIChatbot\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Class GroqProvider.
 */
class GroqProvider implements AIProviderInterface {

	/**
	 * Provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'groq';
	}

	/**
	 * Provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Groq';
	}

	/**
	 * Supports chat completions.
	 *
	 * @return bool
	 */
	public function supports_chat(): bool {
		return true;
	}

	/**
	 * Groq is chat-only and does not support embeddings.
	 *
	 * @return bool
	 */
	public function supports_embeddings(): bool {
		return false;
	}

	/**
	 * Test API connection.
	 *
	 * @param string $api_key Plaintext API Key.
	 * @return array{success: bool, message: string, models?: array}
	 */
	public function test_connection( string $api_key ): array {
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Groq API Key cannot be empty.', 'ai-chatbot' ),
			);
		}

		$response = wp_remote_get(
			'https://api.groq.com/openai/v1/models',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . trim( $api_key ),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$err_msg = $body['error']['message'] ?? ( __( 'Groq connection failed with HTTP status: ', 'ai-chatbot' ) . $status );
			return array(
				'success' => false,
				'message' => $err_msg,
			);
		}

		$models = $this->parse_models( $body['data'] ?? array() );

		return array(
			'success' => true,
			'message' => __( 'Groq connection verified successfully. Ultra-fast chat inference is ready.', 'ai-chatbot' ),
			'models'  => $models,
		);
	}

	/**
	 * List models dynamically.
	 *
	 * @param string $api_key API key.
	 * @return array List of model identifiers.
	 */
	public function list_models( string $api_key ): array {
		$test = $this->test_connection( $api_key );
		if ( ! empty( $test['models'] ) ) {
			return $test['models'];
		}

		// Fallback fast models.
		return array(
			'chat'       => array(
				'llama-3.3-70b-versatile',
				'llama-3.1-8b-instant',
				'mixtral-8x7b-32768',
				'gemma2-9b-it',
			),
			'embeddings' => array(),
		);
	}

	/**
	 * Parse model list from Groq API response.
	 *
	 * @param array $raw_data Raw model records.
	 * @return array{chat: array, embeddings: array}
	 */
	protected function parse_models( array $raw_data ): array {
		$chat = array();

		foreach ( $raw_data as $m ) {
			$id = $m['id'] ?? '';
			if ( empty( $id ) ) {
				continue;
			}
			// Filter out whisper or audio models.
			if ( str_contains( $id, 'whisper' ) ) {
				continue;
			}
			$chat[] = $id;
		}

		sort( $chat );

		if ( empty( $chat ) ) {
			$chat = array( 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768' );
		}

		return array(
			'chat'       => array_values( array_unique( $chat ) ),
			'embeddings' => array(), // Explicitly empty for Groq.
		);
	}

	/**
	 * Execute chat completion request.
	 *
	 * CRITICAL: Sends ONLY temperature, max_tokens, and top_p.
	 * Does not send frequency/presence penalties or logprobs.
	 *
	 * @param array  $messages Conversation messages.
	 * @param array  $options Model options.
	 * @param string $api_key API key.
	 * @return array
	 */
	public function chat( array $messages, array $options, string $api_key ): array {
		$model       = $options['model'] ?? 'llama-3.3-70b-versatile';
		$temperature = isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.7;
		$max_tokens  = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 800;
		$max_tokens  = max( 100, min( 800, $max_tokens ) ); // Enforce safe 500-800 limit to prevent TPM exhaustion.
		$top_p       = isset( $options['top_p'] ) ? (float) $options['top_p'] : 1.0;

		$payload = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => $temperature,
			'max_tokens'  => $max_tokens,
			'top_p'       => $top_p,
		);

		$timeout = 40;
		$args    = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . trim( $api_key ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $timeout,
		);

		// Execute with auto-retry on HTTP 429 (Too Many Requests) or 503 (Overloaded): retry up to 2 times.
		$max_retries = 2;
		$attempt     = 0;
		$response    = null;

		while ( $attempt <= $max_retries ) {
			$response = wp_remote_post( 'https://api.groq.com/openai/v1/chat/completions', $args );

			if ( is_wp_error( $response ) ) {
				$attempt++;
				if ( $attempt <= $max_retries ) {
					sleep( 2 );
					continue;
				}
				break;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );

			// If HTTP 429 (Rate Limit) or 503 (Overloaded), wait 2 seconds and retry automatically.
			if ( ( 429 === $status_code || 503 === $status_code ) && $attempt < $max_retries ) {
				$attempt++;
				$resp_headers = wp_remote_retrieve_headers( $response )->getAll();
				$wait_sec     = 2;
				if ( ! empty( $resp_headers['retry-after'] ) ) {
					$ra = is_array( $resp_headers['retry-after'] ) ? $resp_headers['retry-after'][0] : $resp_headers['retry-after'];
					if ( is_numeric( $ra ) ) {
						$wait_sec = max( 2, min( 5, (int) $ra ) );
					}
				}
				sleep( $wait_sec );
				continue;
			}

			break;
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'content'     => '',
				'tokens'      => 0,
				'error'       => $response->get_error_message(),
				'status_code' => 500,
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$headers     = wp_remote_retrieve_headers( $response )->getAll();
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$error_msg = $body['error']['message'] ?? ( 'Groq HTTP error ' . $status_code );
			return array(
				'content'     => '',
				'tokens'      => 0,
				'error'       => $error_msg,
				'status_code' => $status_code,
				'headers'     => $headers,
				'raw'         => $body,
			);
		}

		$content = $body['choices'][0]['message']['content'] ?? '';
		$tokens  = $body['usage']['total_tokens'] ?? 0;

		return array(
			'content'     => trim( $content ),
			'tokens'      => (int) $tokens,
			'status_code' => 200,
			'headers'     => $headers,
			'raw'         => $body,
		);
	}

	/**
	 * Embeddings not supported by Groq.
	 *
	 * @param string|array $input Input text.
	 * @param string       $model Model ID.
	 * @param string       $api_key API key.
	 * @return array Always empty.
	 */
	public function generate_embeddings( string|array $input, string $model, string $api_key ): array {
		return array();
	}
}
