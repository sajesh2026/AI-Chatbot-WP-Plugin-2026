<?php
/**
 * Mistral AI Provider implementation.
 *
 * Mistral is used here for embeddings. Groq serves no embedding model, so a
 * site that keeps Groq for chat still needs a second provider to build its
 * knowledge index. Mistral's embeddings endpoint mirrors OpenAI's request and
 * response shape, which keeps the integration small.
 *
 * @package AIChatbot\AI
 */

namespace AIChatbot\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Class MistralProvider.
 */
class MistralProvider implements AIProviderInterface {

	/**
	 * Base URL for the Mistral API.
	 */
	private const BASE_URL = 'https://api.mistral.ai/v1';

	/**
	 * Description of the most recent API failure.
	 *
	 * @var string
	 */
	protected string $last_error = '';

	/**
	 * Get the reason the most recent API call failed.
	 *
	 * @return string
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
		return 'mistral';
	}

	/**
	 * Provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Mistral AI', 'ai-chatbot' );
	}

	/**
	 * Whether chat completions are supported.
	 *
	 * @return bool
	 */
	public function supports_chat(): bool {
		return true;
	}

	/**
	 * Whether embeddings are supported.
	 *
	 * @return bool
	 */
	public function supports_embeddings(): bool {
		return true;
	}

	/**
	 * Perform a request against the Mistral API.
	 *
	 * @param string $endpoint Endpoint path relative to BASE_URL.
	 * @param array  $body     JSON body.
	 * @param string $api_key  Plaintext API key.
	 * @param int    $timeout  Timeout in seconds.
	 * @return array|\WP_Error Decoded response or transport error.
	 */
	private function request( string $endpoint, array $body, string $api_key, int $timeout = 40 ) {
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . trim( $api_key ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => max( 35, $timeout ),
		);

		// Execute with auto-retry on HTTP 429 (Too Many Requests) or 503 (Overloaded): retry up to 2 times.
		$max_retries = 2;
		$attempt     = 0;
		$response    = null;

		while ( $attempt <= $max_retries ) {
			$response = wp_remote_post( self::BASE_URL . $endpoint, $args );

			if ( is_wp_error( $response ) ) {
				$attempt++;
				if ( $attempt <= $max_retries ) {
					sleep( 2 );
					continue;
				}
				break;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			// If HTTP 429 (Rate Limit) or 503 (Overloaded), wait 2 seconds and retry automatically.
			if ( ( 429 === $status || 503 === $status ) && $attempt < $max_retries ) {
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

		return $response;
	}

	/**
	 * Test the API connection.
	 *
	 * @param string $api_key Plaintext API key.
	 * @return array{success: bool, message: string, models?: array}
	 */
	public function test_connection( string $api_key ): array {
		if ( '' === trim( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'No Mistral API key configured.', 'ai-chatbot' ),
			);
		}

		$response = $this->request( '/embeddings', array( 'model' => 'mistral-embed', 'input' => array( 'ping' ) ), $api_key, 30 );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $status ) {
			return array(
				'success' => true,
				'message' => __( 'Mistral API key is valid and embeddings are reachable.', 'ai-chatbot' ),
				'models'  => $this->list_models( $api_key ),
			);
		}

		return array(
			'success' => false,
			'message' => $this->describe_error( $status, (string) wp_remote_retrieve_body( $response ), $api_key ),
		);
	}

	/**
	 * List the models this key can use.
	 *
	 * @param string $api_key Plaintext API key.
	 * @return array{chat: array, embeddings: array}
	 */
	public function list_models( string $api_key ): array {
		$chat       = array();
		$embeddings = array();

		$response = wp_remote_get(
			self::BASE_URL . '/models',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . trim( $api_key ) ),
				'timeout' => 20,
			)
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			foreach ( (array) ( $body['data'] ?? array() ) as $model ) {
				$id = (string) ( $model['id'] ?? '' );

				if ( '' === $id ) {
					continue;
				}

				// Mistral embedding models are named "mistral-embed" / "codestral-embed".
				if ( str_contains( strtolower( $id ), 'embed' ) ) {
					$embeddings[] = $id;
				} else {
					$chat[] = $id;
				}
			}
		}

		sort( $chat );
		sort( $embeddings );

		// Known-good defaults, used when the API cannot be reached.
		if ( empty( $embeddings ) ) {
			$embeddings = array( 'mistral-embed', 'codestral-embed' );
		}

		if ( empty( $chat ) ) {
			$chat = array( 'mistral-small-latest', 'mistral-large-latest', 'open-mistral-nemo' );
		}

		return array(
			'chat'       => $chat,
			'embeddings' => $embeddings,
		);
	}

	/**
	 * Execute a chat completion.
	 *
	 * @param array  $messages Message list.
	 * @param array  $options  Options (model, temperature, max_tokens, top_p).
	 * @param string $api_key  Plaintext API key.
	 * @return array
	 */
	public function chat( array $messages, array $options, string $api_key ): array {
		$body = array(
			'model'    => $options['model'] ?? 'mistral-small-latest',
			'messages' => $messages,
		);

		foreach ( array( 'temperature', 'max_tokens', 'top_p' ) as $field ) {
			if ( isset( $options[ $field ] ) ) {
				$body[ $field ] = $options[ $field ];
			}
		}

		if ( isset( $body['max_tokens'] ) ) {
			$body['max_tokens'] = max( 100, min( 800, (int) $body['max_tokens'] ) );
		}

		$response = $this->request( '/chat/completions', $body, $api_key, 60 );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return array(
				'content'     => '',
				'tokens'      => 0,
				'error'       => $this->last_error,
				'status_code' => 0,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$this->last_error = $this->describe_error( $status, $raw, $api_key );
			return array(
				'content'     => '',
				'tokens'      => 0,
				'error'       => $this->last_error,
				'status_code' => $status,
				'headers'     => wp_remote_retrieve_headers( $response ),
			);
		}

		$decoded = json_decode( $raw, true );

		return array(
			'content'  => (string) ( $decoded['choices'][0]['message']['content'] ?? '' ),
			'tokens'   => (int) ( $decoded['usage']['total_tokens'] ?? 0 ),
			'raw'      => $decoded,
			'error'    => null,
		);
	}

	/**
	 * Generate text embeddings.
	 *
	 * Mistral mirrors the OpenAI embeddings contract, so this mirrors
	 * OpenAIProvider::generate_embeddings() including its error reporting.
	 *
	 * @param string|array $input   Single string or array of strings.
	 * @param string       $model   Embedding model ID.
	 * @param string       $api_key Plaintext API key.
	 * @return array List of float vectors.
	 */
	public function generate_embeddings( string|array $input, string $model, string $api_key ): array {
		$this->last_error = '';

		if ( '' === trim( $api_key ) ) {
			$this->last_error = __( 'No Mistral API key was configured. Set one under Settings → Providers to build the knowledge index.', 'ai-chatbot' );
			return array();
		}

		if ( '' === trim( $model ) ) {
			$model = 'mistral-embed';
		}

		$response = $this->request( '/embeddings', array( 'model' => $model, 'input' => $input ), $api_key, 45 );

		if ( is_wp_error( $response ) ) {
			$this->last_error = sprintf(
				/* translators: %s: transport error message. */
				__( 'Could not reach the Mistral API: %s', 'ai-chatbot' ),
				$response->get_error_message()
			);
			return array();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			$this->last_error = $this->describe_error( $status, $raw, $api_key, $model );
			return array();
		}

		$body = json_decode( $raw, true );

		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			$this->last_error = __( 'Mistral returned a successful response but no embedding vectors.', 'ai-chatbot' );
			return array();
		}

		$embeddings = array();

		foreach ( $body['data'] as $item ) {
			if ( ! empty( $item['embedding'] ) && is_array( $item['embedding'] ) ) {
				$embeddings[] = $item['embedding'];
			}
		}

		if ( empty( $embeddings ) ) {
			$this->last_error = __( 'Mistral returned no usable embedding vectors for the supplied text.', 'ai-chatbot' );
		}

		return $embeddings;
	}

	/**
	 * Turn a Mistral error response into an actionable message.
	 *
	 * @param int    $status  HTTP status code.
	 * @param string $raw     Raw response body.
	 * @param string $api_key The key that was used.
	 * @param string $model   Requested model.
	 * @return string
	 */
	protected function describe_error( int $status, string $raw, string $api_key, string $model = '' ): string {
		$decoded = json_decode( $raw, true );

		// Mistral reports errors either at the root or nested under "detail".
		$api_msg = $decoded['message'] ?? ( $decoded['detail'][0]['msg'] ?? ( is_string( $decoded['detail'] ?? null ) ? $decoded['detail'] : '' ) );

		if ( 401 === $status || 403 === $status ) {
			if ( str_starts_with( strtolower( trim( $api_key ) ), 'gsk' ) ) {
				return __( 'Mistral rejected the key because it is a Groq key (starts with "gsk_"). Add a real Mistral key, which starts with a character other than "gsk_".', 'ai-chatbot' );
			}

			return sprintf(
				/* translators: 1: HTTP status, 2: API message. */
				__( 'Mistral rejected the API key (HTTP %1$d): %2$s', 'ai-chatbot' ),
				$status,
				'' !== (string) $api_msg ? (string) $api_msg : __( 'unauthorized', 'ai-chatbot' )
			);
		}

		if ( 404 === $status ) {
			return sprintf(
				/* translators: %s: model identifier. */
				__( 'Mistral does not recognise the model "%s" for this endpoint. Use "mistral-embed" for the knowledge index.', 'ai-chatbot' ),
				$model
			);
		}

		if ( 422 === $status ) {
			return __( 'Mistral rejected the request (HTTP 422). This usually means the input batch is too large or contains empty text.', 'ai-chatbot' );
		}

		if ( 429 === $status ) {
			return __( 'Mistral rate limit reached (HTTP 429). Wait a moment and re-index.', 'ai-chatbot' );
		}

		if ( 413 === $status ) {
			return __( 'Mistral rejected the request as too large (HTTP 413). Reduce the number of pages imported per batch.', 'ai-chatbot' );
		}

		return sprintf(
			/* translators: 1: HTTP status, 2: API message. */
			__( 'Mistral request failed (HTTP %1$d): %2$s', 'ai-chatbot' ),
			$status,
			'' !== (string) $api_msg ? (string) $api_msg : __( 'unknown error', 'ai-chatbot' )
		);
	}
}