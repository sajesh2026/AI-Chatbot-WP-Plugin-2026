<?php
/**
 * API Key Pool with automatic failover, cooldown management, and atomic state.
 *
 * @package AIChatbot\AI
 */

namespace AIChatbot\AI;

use AIChatbot\Security\Encryption;

defined( 'ABSPATH' ) || exit;

/**
 * Class KeyPool.
 */
class KeyPool {

	/**
	 * Table name without prefix.
	 */
	public const TABLE_NAME = 'ai_api_keys';

	/**
	 * Get all keys registered for a provider with safe masked keys.
	 *
	 * @param string $provider Provider identifier (e.g. 'groq', 'openai').
	 * @return array List of key records.
	 */
	public static function get_keys( string $provider ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// Clean up expired cooldowns before retrieving.
		self::refresh_cooldowns( $provider );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, provider, label, key_last_four, priority, status, cooldown_until, last_error, requests_count, rate_limit_hits, last_used_at, created_at
				 FROM {$table}
				 WHERE provider = %s
				 ORDER BY priority ASC, id ASC",
				$provider
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Add a new API key to the pool.
	 *
	 * @param string $provider Provider slug.
	 * @param string $label Friendly label (e.g. 'Main Org Account').
	 * @param string $raw_key Plaintext API key.
	 * @param int    $priority Lower number = higher priority.
	 * @return int|false New key ID or false on failure.
	 */
	public static function add_key( string $provider, string $label, string $raw_key, int $priority = 0 ): int|false {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$raw_key = trim( $raw_key );
		if ( empty( $raw_key ) ) {
			return false;
		}

		$encrypted = Encryption::encrypt( $raw_key );
		$last_four = Encryption::get_last_four( $raw_key );

		$inserted = $wpdb->insert(
			$table,
			array(
				'provider'       => $provider,
				'label'          => sanitize_text_field( $label ),
				'encrypted_key'  => $encrypted,
				'key_last_four'  => $last_four,
				'priority'       => $priority,
				'status'         => 'active',
				'cooldown_until' => null,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update key metadata (label, priority, status).
	 *
	 * @param int   $id Key ID.
	 * @param array $data Key fields to update.
	 * @return bool
	 */
	public static function update_key( int $id, array $data ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$allowed = array( 'label', 'priority', 'status' );
		$update  = array();
		$format  = array();

		if ( isset( $data['label'] ) ) {
			$update['label'] = sanitize_text_field( $data['label'] );
			$format[]        = '%s';
		}
		if ( isset( $data['priority'] ) ) {
			$update['priority'] = (int) $data['priority'];
			$format[]           = '%d';
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'active', 'disabled' ), true ) ) {
			$update['status'] = $data['status'];
			$format[]         = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$updated = $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );
		return false !== $updated;
	}

	/**
	 * Delete a key from the pool.
	 *
	 * @param int $id Key ID.
	 * @return bool
	 */
	public static function delete_key( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$res   = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		return false !== $res;
	}

	/**
	 * Automatically bring cooling-down keys back to active once cooldown time has elapsed.
	 * Uses an atomic DB update query.
	 *
	 * @param string $provider Provider.
	 * @return void
	 */
	public static function refresh_cooldowns( string $provider ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$now   = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'active', cooldown_until = NULL
				 WHERE provider = %s AND status = 'cooling_down' AND cooldown_until <= %s",
				$provider,
				$now
			)
		);
	}

	/**
	 * Get list of currently active keys with decrypted API values for execution.
	 *
	 * @param string $provider Provider slug.
	 * @param string $strategy 'priority' or 'round_robin'.
	 * @return array Array of keys with decrypted 'key' field.
	 */
	public static function get_active_keys_for_request( string $provider, string $strategy = 'priority' ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		self::refresh_cooldowns( $provider );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, provider, label, encrypted_key, key_last_four, priority, requests_count, rate_limit_hits
				 FROM {$table}
				 WHERE provider = %s AND status = 'active'
				 ORDER BY priority ASC, id ASC",
				$provider
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$active = array();
		foreach ( $rows as $row ) {
			$decrypted = Encryption::decrypt( $row['encrypted_key'] );
			if ( ! empty( $decrypted ) ) {
				$row['key'] = $decrypted;
				$active[]   = $row;
			}
		}

		if ( 'round_robin' === $strategy && count( $active ) > 1 ) {
			$counter_key = 'ai_key_rr_' . $provider;
			$last_idx    = (int) get_transient( $counter_key );
			$idx         = ( $last_idx + 1 ) % count( $active );
			set_transient( $counter_key, $idx, DAY_IN_SECONDS );

			// Rotate array starting from chosen index.
			$active = array_merge( array_slice( $active, $idx ), array_slice( $active, 0, $idx ) );
		}

		return $active;
	}

	/**
	 * Whether the provider has at least one usable key configured.
	 *
	 * Used to grandfather sites that configured a key before the setup
	 * wizard existed. Cooling-down keys count as configured: they are
	 * only temporarily rate-limited, not absent, so a brief 429 storm
	 * must not make a working install look unconfigured.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function has_configured_key( string $provider ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE provider = %s AND status IN ('active', 'cooling_down')",
				$provider
			)
		);

		return $count > 0;
	}

	/**
	 * Record a cooldown for a rate-limited key (HTTP 429).
	 *
	 * @param int   $key_id Key record ID.
	 * @param array $headers Response headers from provider.
	 * @param int   $current_hits Existing hits for exponential backoff if header missing.
	 * @return int Seconds cooled down.
	 */
	public static function mark_cooldown( int $key_id, array $headers = array(), int $current_hits = 0 ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$wait_seconds = 5; // Fast recovery default for burst rate limits.

		// Check retry-after header.
		if ( ! empty( $headers['retry-after'] ) ) {
			$val = is_array( $headers['retry-after'] ) ? $headers['retry-after'][0] : $headers['retry-after'];
			if ( is_numeric( $val ) ) {
				$wait_seconds = max( 2, (int) $val );
			} elseif ( strtotime( $val ) ) {
				$wait_seconds = max( 2, strtotime( $val ) - time() );
			}
		} elseif ( ! empty( $headers['x-ratelimit-reset-tokens'] ) ) {
			// Token-bucket reset header (e.g. Groq "2.1s", "150ms", "3s").
			$val = is_array( $headers['x-ratelimit-reset-tokens'] ) ? $headers['x-ratelimit-reset-tokens'][0] : $headers['x-ratelimit-reset-tokens'];
			if ( preg_match( '/([\d\.]+)\s*s/i', $val, $matches ) ) {
				$wait_seconds = max( 2, (int) ceil( (float) $matches[1] ) );
			} elseif ( preg_match( '/([\d\.]+)\s*ms/i', $val, $matches ) ) {
				$wait_seconds = max( 1, (int) ceil( (float) $matches[1] / 1000 ) );
			} elseif ( is_numeric( $val ) ) {
				$wait_seconds = max( 2, (int) ceil( (float) $val ) );
			}
		} elseif ( ! empty( $headers['x-ratelimit-reset-requests'] ) ) {
			$val = is_array( $headers['x-ratelimit-reset-requests'] ) ? $headers['x-ratelimit-reset-requests'][0] : $headers['x-ratelimit-reset-requests'];
			if ( preg_match( '/(\d+)m(?:(\d+)s)?/', $val, $matches ) ) {
				$wait_seconds = ( (int) $matches[1] * 60 ) + (int) ( $matches[2] ?? 0 );
			} elseif ( is_numeric( $val ) ) {
				$wait_seconds = max( 2, (int) $val );
			}
		} else {
			// Progressive backoff if no header provided: 5s, 15s, 30s, 60s, 120s.
			$steps        = array( 5, 15, 30, 60, 120 );
			$idx          = min( $current_hits, count( $steps ) - 1 );
			$wait_seconds = $steps[ $idx ];
		}

		$cooldown_time = gmdate( 'Y-m-d H:i:s', time() + $wait_seconds );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'cooling_down',
				     cooldown_until = %s,
				     rate_limit_hits = rate_limit_hits + 1,
				     last_error = %s,
				     updated_at = %s
				 WHERE id = %d",
				$cooldown_time,
				sprintf( 'HTTP 429 rate limit exceeded. Cooling down for %d seconds.', $wait_seconds ),
				current_time( 'mysql' ),
				$key_id
			)
		);

		return $wait_seconds;
	}

	/**
	 * Mark key permanently Invalid (HTTP 401 or 403).
	 *
	 * @param int    $key_id Key record ID.
	 * @param string $error_message Error description.
	 * @return void
	 */
	public static function mark_invalid( int $key_id, string $error_message ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->update(
			$table,
			array(
				'status'     => 'invalid',
				'last_error' => sanitize_text_field( $error_message ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $key_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Increment usage counters after a successful request.
	 *
	 * @param int $key_id Key ID.
	 * @return void
	 */
	public static function record_success( int $key_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET requests_count = requests_count + 1,
				     last_used_at = %s
				 WHERE id = %d",
				current_time( 'mysql' ),
				$key_id
			)
		);
	}

	/**
	 * Execute a chat request with automatic failover across keys in the pool.
	 *
	 * @param AIProviderInterface $provider AI Provider instance.
	 * @param array               $messages Message list.
	 * @param array               $options Model parameters.
	 * @param string              $strategy 'priority' or 'round_robin'.
	 * @param ?AIProviderInterface $fallback_provider Optional fallback provider if all keys fail.
	 * @param ?string             $fallback_key Plaintext key for fallback provider.
	 * @return array Result of chat call with content or error.
	 */
	public static function execute_with_failover(
		AIProviderInterface $provider,
		array $messages,
		array $options,
		string $strategy = 'priority',
		?AIProviderInterface $fallback_provider = null,
		?string $fallback_key = null
	): array {
		$keys = self::get_active_keys_for_request( $provider->get_id(), $strategy );

		if ( empty( $keys ) ) {
			// Attempt fallback provider if available.
			if ( $fallback_provider && ! empty( $fallback_key ) ) {
				$fallback_options = $options;
				if ( 'openai' === $fallback_provider->get_id() && ( empty( $options['model'] ) || str_contains( $options['model'], 'llama' ) || str_contains( $options['model'], 'qwen' ) || str_contains( $options['model'], 'mixtral' ) ) ) {
					$fallback_options['model'] = 'gpt-4o-mini';
				} elseif ( 'mistral' === $fallback_provider->get_id() && ( empty( $options['model'] ) || ! str_contains( $options['model'], 'mistral' ) ) ) {
					$fallback_options['model'] = 'mistral-small-latest';
				}
				return $fallback_provider->chat( $messages, $fallback_options, $fallback_key );
			}

			/*
			 * No usable key right now. That is either temporary (every
			 * key is cooling down after a rate limit) or permanent (no
			 * keys configured, or all invalid). find_minimum_cooldown()
			 * is greater than zero only while at least one key is
			 * cooling down, so it separates the two: a temporary
			 * shortage is reported as a rate limit the visitor can
			 * retry, a permanent one as a service outage that retrying
			 * cannot fix.
			 */
			$min_wait = self::find_minimum_cooldown( $provider->get_id() );

			if ( $min_wait > 0 ) {
				return array(
					'content'     => '',
					'tokens'      => 0,
					'error'       => 'rate_limited',
					'status_code' => 429,
					'is_error'    => true,
				);
			}

			return array(
				'content'     => '',
				'tokens'      => 0,
				'error'       => 'no_keys_available',
				'status_code' => 503,
				'is_error'    => true,
			);
		}

		foreach ( $keys as $key_record ) {
			$key_id    = (int) $key_record['id'];
			$plain_key = $key_record['key'];

			// Execute call.
			$result = $provider->chat( $messages, $options, $plain_key );

			$status_code = $result['status_code'] ?? 200;

			// Success.
			if ( 200 === $status_code ) {
				self::record_success( $key_id );
				return $result;
			}

			// Case 1: Rate limit (HTTP 429) -> Cool down and try NEXT key immediately!
			if ( 429 === $status_code ) {
				self::mark_cooldown( $key_id, $result['headers'] ?? array(), (int) ( $key_record['rate_limit_hits'] ?? 0 ) );
				continue;
			}

			// Case 2: Invalid or revoked key (HTTP 401, 403) -> Mark Invalid and try NEXT key.
			if ( 401 === $status_code || 403 === $status_code ) {
				self::mark_invalid( $key_id, $result['error'] ?? 'Unauthorized or invalid key' );
				continue;
			}

			// Case 3: Bad request (HTTP 400) -> NEVER rotate! Another key would fail the same way.
			if ( 400 === $status_code ) {
				return $result;
			}

			// Case 4: Server error or timeout (5xx) -> Retry once with this key, then try next key without marking invalid.
			if ( $status_code >= 500 ) {
				$retry_result = $provider->chat( $messages, $options, $plain_key );
				if ( 200 === ( $retry_result['status_code'] ?? 0 ) ) {
					self::record_success( $key_id );
					return $retry_result;
				}
				// Retry failed, move to next key.
				continue;
			}
		}

		// If loop completes without success, try fallback provider if provided.
		if ( $fallback_provider && ! empty( $fallback_key ) ) {
			$fallback_options = $options;
			if ( 'openai' === $fallback_provider->get_id() && ( empty( $options['model'] ) || str_contains( $options['model'], 'llama' ) || str_contains( $options['model'], 'qwen' ) || str_contains( $options['model'], 'mixtral' ) ) ) {
				$fallback_options['model'] = 'gpt-4o-mini';
			} elseif ( 'mistral' === $fallback_provider->get_id() && ( empty( $options['model'] ) || ! str_contains( $options['model'], 'mistral' ) ) ) {
				$fallback_options['model'] = 'mistral-small-latest';
			}
			return $fallback_provider->chat( $messages, $fallback_options, $fallback_key );
		}

		/*
		 * Wait briefly for a key to cool down rather than immediately returning
		 * "I'm busy". Rate-limit windows are often a few seconds, so a short
		 * sleep can turn a guaranteed failure into a successful retry without
		 * making visitors wait long.
		 */
		$min_wait = self::find_minimum_cooldown( $provider->get_id() );

		if ( $min_wait > 0 && $min_wait <= 15 ) {
			sleep( min( 5, $min_wait ) );
			self::refresh_cooldowns( $provider->get_id() );

			$retry_keys = self::get_active_keys_for_request( $provider->get_id(), $strategy );

			foreach ( $retry_keys as $key_record ) {
				$retry_result = $provider->chat( $messages, $options, $key_record['key'] );

				if ( 200 === ( $retry_result['status_code'] ?? 0 ) ) {
					self::record_success( (int) $key_record['id'] );
					return $retry_result;
				}

				if ( 429 === ( $retry_result['status_code'] ?? 0 ) ) {
					self::mark_cooldown( (int) $key_record['id'], $retry_result['headers'] ?? array(), (int) ( $key_record['rate_limit_hits'] ?? 0 ) );
				}
			}
		}

		return array(
			'content'     => '',
			'tokens'      => 0,
			'error'       => 'rate_limited',
			'status_code' => 429,
			'is_error'    => true,
		);
	}

	/**
	 * Find the shortest remaining cooldown among cooling-down keys.
	 *
	 * @param string $provider Provider slug.
	 * @return int Seconds until the next key becomes available, or 0 if none.
	 */
	private static function find_minimum_cooldown( string $provider ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$now = current_time( 'mysql' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cooldown_until FROM {$table}
				 WHERE provider = %s AND status = 'cooling_down' AND cooldown_until > %s
				 ORDER BY cooldown_until ASC LIMIT 1",
				$provider,
				$now
			),
			ARRAY_A
		);

		if ( ! $row || empty( $row['cooldown_until'] ) ) {
			return 0;
		}

		$cooldown_ts = strtotime( $row['cooldown_until'] );
		$wait        = $cooldown_ts - time();

		return max( 0, (int) $wait );
	}
}
