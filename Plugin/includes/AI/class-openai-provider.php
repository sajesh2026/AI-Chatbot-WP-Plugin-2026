<?php
/**
 * OpenAI Provider implementation.
 *
 * @package AIChatbot\AI
 */

namespace AIChatbot\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Class OpenAIProvider.
 */
class OpenAIProvider implements AIProviderInterface {

	/**
	 * Description of the most recent API failure.
	 *
	 * @var string
	 */
	protected string $last_error = '';

	/**
	 * Get the reason the most recent API call failed.
	 *
	 * Without this, a rejected key is indistinguishable from an empty result and
	 * indexing only reports "0 of N chunks were embedded".
	 *
	 * @return string Human-readable error, or an empty string when the last call succeeded.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'openai';
	}

	/**
	 * Provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'OpenAI';
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
	 * Supports embeddings.
	 *
	 * @return bool
	 */
	public function supports_embeddings(): bool {
		return true;
	}

	/**
	 * Test API connection.
	 *
	 * @param string $api_key API Key.
	 * @return array{success: bool, message: string, models?: array}
	 */
	public function test_connection( string $api_key ): array {
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'API Key cannot be empty.', 'ai-chatbot' ),
			);
		}

		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
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
			$err_msg = $body['error']['message'] ?? __( 'Connection failed with status code: ', 'ai-chatbot' ) . $status;
			return array(
				'success' => false,
				'message' => $err_msg,
			);
		}

		$models = $this->parse_models( $body['data'] ?? array() );

		return array(
			'success' => true,
			'message' => __( 'Connection successful. Verified chat and embedding capabilities.', 'ai-chatbot' ),
			'models'  => $models,
		);
	}

	/**
	 * List models dynamically.
	 *
	 * @param string $api_key API key.
	 * @return array
	 */
	public function list_models( string $api_key ): array {
		$test = $this->test_connection( $api_key );
		if ( ! empty( $test['models'] ) ) {
			return $test['models'];
		}

		// Fallback defaults.
		return array(
			'chat'       => array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo' ),
			'embeddings' => array( 'text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002' ),
		);
	}

	/**
	 * Parse model list into chat and embedding models.
	 *
	 * @param array $raw_data Raw model objects.
	 * @return array{chat: array, embeddings: array}
	 */
	protected function parse_models( array $raw_data ): array {
		$chat       = array();
		$embeddings = array();

		foreach ( $raw_data as $m ) {
			$id = $m['id'] ?? '';
			if ( empty( $id ) ) {
				continue;
			}

			if ( str_contains( $id, 'embedding' ) ) {
				$embeddings[] = $id;
			} elseif ( str_starts_with( $id, 'gpt-' ) || str_starts_with( $id, 'o1-' ) || str_starts_with( $id, 'chatgpt-' ) ) {
				if ( ! str_contains( $id, 'realtime' ) && ! str_contains( $id, 'audio' ) ) {
					$chat[] = $id;
				}
			}
		}

		sort( $chat );
		sort( $embeddings );

		if ( empty( $chat ) ) {
			$chat = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo' );
		}
		if ( empty( $embeddings ) ) {
			$embeddings = array( 'text-embedding-3-small', 'text-embedding-3-large' );
		}

		return array(
			'chat'       => array_values( array_unique( $chat ) ),
			'embeddings' => array_values( array_unique( $embeddings ) ),
		);
	}

	/**
	 * Execute chat completion request.
	 *
	 * @param array  $messages Messages array.
	 * @param array  $options Model options.
	 * @param string $api_key API key.
	 * @return array
	 */
	public function chat( array $messages, array $options, string $api_key ): array {
		$model       = $options['model'] ?? 'gpt-4o-mini';
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
			$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', $args );

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

		$status_code = wp_remote_retrieve_response_code( $response );
		$headers     = wp_remote_retrieve_headers( $response )->getAll();
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$error_msg = $body['error']['message'] ?? ( 'OpenAI HTTP ' . $status_code );
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
	 * Generate embeddings vector(s).
	 *
	 * @param string|array $input Text or array of texts.
	 * @param string       $model Embeddings model.
	 * @param string       $api_key API key.
	 * @return array Array of float vectors.
	 */
	public function generate_embeddings( string|array $input, string $model, string $api_key ): array {
		$this->last_error = '';

		if ( empty( $model ) ) {
			$model = 'text-embedding-3-small';
		}

		$payload = array(
			'model' => $model,
			'input' => $input,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/embeddings',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . trim( $api_key ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = sprintf(
				/* translators: %s: transport error message. */
				__( 'Could not reach the OpenAI API: %s', 'ai-chatbot' ),
				$response->get_error_message()
			);
			return array();
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$this->last_error = $this->describe_error( $status, $raw, $api_key, $model );
			return array();
		}

		$body = json_decode( $raw, true );
		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			$this->last_error = __( 'OpenAI returned a successful response but no embedding vectors.', 'ai-chatbot' );
			return array();
		}

		$embeddings = array();
		foreach ( $body['data'] as $item ) {
			if ( ! empty( $item['embedding'] ) && is_array( $item['embedding'] ) ) {
				$embeddings[] = $item['embedding'];
			}
		}

		if ( empty( $embeddings ) ) {
			$this->last_error = __( 'OpenAI returned no usable embedding vectors for the supplied text.', 'ai-chatbot' );
		}

		return $embeddings;
	}

	/**
	 * Turn an OpenAI error response into a message an administrator can act on.
	 *
	 * @param int    $status  HTTP status code.
	 * @param string $raw     Raw response body.
	 * @param string $api_key The key that was used, for misconfiguration hints.
	 * @param string $model   The embeddings model that was requested.
	 * @return string
	 */
	protected function describe_error( int $status, string $raw, string $api_key, string $model = '' ): string {
		$decoded  = json_decode( $raw, true );
		$api_msg  = $decoded['error']['message'] ?? '';
		$api_code = $decoded['error']['code'] ?? '';

		if ( 401 === $status || 403 === $status ) {
			// A Groq key pasted into the OpenAI field is by far the most common
			// cause, and the raw API message does not say which key was used.
			if ( str_starts_with( strtolower( trim( $api_key ) ), 'gsk' ) ) {
				return __( 'OpenAI rejected the API key because it is a Groq key (starts with "gsk_"). Groq does not provide embeddings. Add a real OpenAI key (starts with "sk-") here, and move the Groq key into the Groq Key Pool.', 'ai-chatbot' );
			}

			if ( '' === trim( $api_key ) ) {
				return __( 'No OpenAI API key was configured. Knowledge indexing requires a valid OpenAI key (starts with "sk-").', 'ai-chatbot' );
			}

			return sprintf(
				/* translators: 1: HTTP status, 2: API error message. */
				__( 'OpenAI rejected the API key (HTTP %1$d): %2$s', 'ai-chatbot' ),
				$status,
				'' !== $api_msg ? $api_msg : __( 'unauthorized', 'ai-chatbot' )
			);
		}

		if ( 429 === $status ) {
			return __( 'OpenAI rate limit reached (HTTP 429). Wait a moment and re-index.', 'ai-chatbot' );
		}

		if ( 404 === $status ) {
			return sprintf(
				/* translators: %s: model identifier. */
				__( 'OpenAI does not recognise the embeddings model "%s". Choose text-embedding-3-small or text-embedding-3-large.', 'ai-chatbot' ),
				$model
			);
		}

		return sprintf(
			/* translators: 1: HTTP status, 2: API error message or code. */
			__( 'Embeddings request failed (HTTP %1$d): %2$s', 'ai-chatbot' ),
			$status,
			'' !== $api_msg ? $api_msg : ( '' !== $api_code ? (string) $api_code : __( 'unknown error', 'ai-chatbot' ) )
		);
	}
}
