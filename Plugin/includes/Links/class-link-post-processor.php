<?php
/**
 * Deterministic Link Post-Processor.
 *
 * Implements the deterministic pipeline for verified inline links:
 * 3a. Replace [[LINK_n]] placeholders with verified canonical links from registry.
 * 3b. Scan text for model-generated URLs/domains (keep registry matches, remove unverified).
 * 3c. Auto-link known phrases using curated, unambiguous phrase-to-URL map (first occurrence per URL).
 * 3d. Dangling-link cleanup (clean empty link slots, prepositions like "at .").
 * 3e. Verbatim email (mailto:) and phone (tel:) linking strictly if in KB verbatim.
 * 3f. Strip any trailing link blocks (Relevant page, Related pages, Mehr dazu, Learn more, etc.).
 * Step 5: Final safety assertion verifying every outgoing link exists in the registry.
 *
 * CORE RULE: Links appear ONLY inline in the sentence where they are relevant.
 * This processor NEVER appends links to the end of a message.
 *
 * @package AIChatbot\Links
 */

namespace AIChatbot\Links;

defined( 'ABSPATH' ) || exit;

/**
 * Class LinkPostProcessor.
 */
class LinkPostProcessor {

	/**
	 * Decision audit log for current processing run.
	 *
	 * @var array[]
	 */
	protected static array $decision_log = array();

	/**
	 * Custom mock registry for unit testing without database.
	 *
	 * @var array|null
	 */
	protected static ?array $custom_registry = null;

	/**
	 * Current allowed links for active run.
	 *
	 * @var array
	 */
	protected static array $current_allowed_links = array();

	/**
	 * Set a custom mock registry for unit testing.
	 *
	 * @param ?array $registry Array of registered link items.
	 * @return void
	 */
	public static function set_custom_registry( ?array $registry ): void {
		self::$custom_registry = $registry;
	}

	/**
	 * Get the decision log for the last run.
	 *
	 * @return array[] Array of decision records.
	 */
	public static function get_decision_log(): array {
		return self::$decision_log;
	}

	/**
	 * Clear the decision log.
	 *
	 * @return void
	 */
	public static function clear_decision_log(): void {
		self::$decision_log = array();
	}

	/**
	 * Log a link decision for auditing.
	 *
	 * @param string $action Action name.
	 * @param array  $details Details.
	 * @return void
	 */
	protected static function log_decision( string $action, array $details = array() ): void {
		self::$decision_log[] = array_merge(
			array(
				'action'    => $action,
				'timestamp' => microtime( true ),
			),
			$details
		);
	}

	/**
	 * Lookup canonical data from custom registry or LinkRegistry.
	 *
	 * @param string  $url Target URL.
	 * @param ?string $language Language code.
	 * @return array|null Record or null.
	 */
	protected static function lookup_canonical( string $url, ?string $language = null ): ?array {
		if ( null !== self::$custom_registry ) {
			$target_norm = LinkRegistry::normalize_for_comparison( $url );
			if ( empty( $target_norm ) ) {
				return null;
			}
			foreach ( self::$custom_registry as $item ) {
				$canon = $item['canonical_url'] ?? ( $item['url'] ?? '' );
				if ( LinkRegistry::normalize_for_comparison( $canon ) === $target_norm ) {
					if ( ! empty( $language ) && 'auto' !== $language && ! empty( $item['language'] ) ) {
						if ( strtolower( $item['language'] ) !== strtolower( $language ) ) {
							continue;
						}
					}
					return $item;
				}
			}
			return null;
		}

		$found = LinkRegistry::lookup_canonical( $url, $language );
		if ( null !== $found ) {
			return $found;
		}

		if ( ! empty( self::$current_allowed_links ) ) {
			$target_norm = LinkRegistry::normalize_for_comparison( $url );
			foreach ( self::$current_allowed_links as $item ) {
				$canon = $item['canonical_url'] ?? ( $item['url'] ?? '' );
				if ( LinkRegistry::normalize_for_comparison( $canon ) === $target_norm ) {
					return $item;
				}
			}
		}

		return null;
	}

	/**
	 * Get phrase-to-url map from custom registry or LinkRegistry.
	 *
	 * @param ?string $language Language code.
	 * @return array
	 */
	protected static function get_phrase_map( ?string $language = null ): array {
		if ( null !== self::$custom_registry ) {
			$phrase_map = array();
			$ambiguous  = array();

			foreach ( self::$custom_registry as $item ) {
				$canon = $item['canonical_url'] ?? ( $item['url'] ?? '' );
				if ( empty( $canon ) ) {
					continue;
				}
				if ( ! empty( $language ) && 'auto' !== $language && ! empty( $item['language'] ) ) {
					if ( strtolower( $item['language'] ) !== strtolower( $language ) ) {
						continue;
					}
				}

				$phrases = array();
				if ( ! empty( $item['title'] ) ) {
					$phrases[] = $item['title'];
				}
				if ( ! empty( $item['match_phrases'] ) ) {
					$mp = is_array( $item['match_phrases'] ) ? $item['match_phrases'] : json_decode( (string) $item['match_phrases'], true );
					if ( is_array( $mp ) ) {
						foreach ( $mp as $p ) {
							$phrases[] = (string) $p;
						}
					}
				}

				foreach ( $phrases as $p ) {
					$p_norm = mb_strtolower( trim( $p ) );
					if ( mb_strlen( $p_norm ) < 3 ) {
						continue;
					}
					if ( isset( $ambiguous[ $p_norm ] ) ) {
						continue;
					}
					if ( isset( $phrase_map[ $p_norm ] ) ) {
						if ( LinkRegistry::normalize_for_comparison( $phrase_map[ $p_norm ]['canonical_url'] ) !== LinkRegistry::normalize_for_comparison( $canon ) ) {
							$ambiguous[ $p_norm ] = true;
							unset( $phrase_map[ $p_norm ] );
						}
					} else {
						$phrase_map[ $p_norm ] = array(
							'phrase'        => $p,
							'canonical_url' => $canon,
							'title'         => $item['title'] ?? $p,
						);
					}
				}
			}

			uksort( $phrase_map, function( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			} );

			return $phrase_map;
		}

		return LinkRegistry::get_phrase_to_url_map( $language );
	}

	/**
	 * Process a bot message through the complete deterministic inline-linking pipeline.
	 *
	 * Order of execution (STRICT):
	 * a) Replace [[LINK_n]] with markdown link [title](canonical_url) from registry.
	 * b) Scan text for model-generated URLs/domains: exact match in registry -> keep & link; not in registry -> remove.
	 * c) Auto-link known phrases: exact whole-word matching, skip ambiguous phrases, first occurrence per URL only.
	 * d) Dangling-link cleanup: rewrite/clean sentences ending in empty link slots (e.g. "at .").
	 * e) Emails (mailto:) and phones (tel:) auto-linked ONLY if in knowledge base verbatim.
	 * f) Strip any trailing link blocks (Relevant page, Verwandte Seiten, Mehr dazu, etc.).
	 * Step 5: Final safety assertion verifying all outgoing links are in registry.
	 *
	 * NEVER appends links to the end of a message. Links are inline only.
	 *
	 * @param string $reply Raw model message text.
	 * @param array  $context Processing context (allowed_links, language, knowledge_chunks, business_info).
	 * @return string Deterministically linked and verified message text.
	 */
	public static function process( string $reply, array $context = array() ): string {
		self::clear_decision_log();

		if ( '' === trim( $reply ) ) {
			return $reply;
		}

		$allowed_links = $context['allowed_links'] ?? array();
		self::$current_allowed_links = $allowed_links;
		$language      = $context['language'] ?? 'de';
		$kb_chunks     = $context['knowledge_chunks'] ?? ( $context['retrieved_chunks'] ?? array() );
		$business_info = $context['business_info'] ?? array();

		if ( isset( $context['custom_registry'] ) ) {
			self::set_custom_registry( $context['custom_registry'] );
		}

		// Track URLs linked across this message to prevent duplicate links.
		$linked_urls_in_message = array();

		// Mask code blocks (``` ... ``` and ` ... `) to protect syntax during processing.
		$code_blocks = array();
		$code_idx    = 0;

		$reply = preg_replace_callback(
			'/```(?:[a-zA-Z0-9_\-]+)?\n?[\s\S]*?```/',
			function ( $m ) use ( &$code_blocks, &$code_idx ) {
				$key = '%%%CODE_BLOCK_' . ( $code_idx++ ) . '%%%';
				$code_blocks[ $key ] = $m[0];
				return $key;
			},
			$reply
		);

		$reply = preg_replace_callback(
			'/`[^`\n]+`/',
			function ( $m ) use ( &$code_blocks, &$code_idx ) {
				$key = '%%%INLINE_CODE_' . ( $code_idx++ ) . '%%%';
				$code_blocks[ $key ] = $m[0];
				return $key;
			},
			$reply
		);

		// ---------------------------------------------------------------------
		// STEP 3a: Replace [[LINK_n]] (and [[link:n]]) placeholders
		// ---------------------------------------------------------------------
		$resolved_link_tokens = array();
		$resolved_token_idx   = 0;

		$reply = preg_replace_callback(
			'/\[\[(?:LINK_|link:)(\d+)\]\]/i',
			function ( $m ) use ( $allowed_links, $language, &$linked_urls_in_message, &$resolved_link_tokens, &$resolved_token_idx ) {
				$idx = (int) $m[1];
				$link_def = $allowed_links[ $idx ] ?? null;

				if ( $link_def ) {
					$url = ! empty( $link_def['canonical_url'] ) ? $link_def['canonical_url'] : ( $link_def['url'] ?? '' );
					$verified = self::lookup_canonical( $url, $language );

					if ( $verified ) {
						$canonical = $verified['canonical_url'];
						$title     = ! empty( $link_def['title'] ) ? $link_def['title'] : ( $verified['title'] ?? 'Link' );
						$norm_key  = LinkRegistry::normalize_for_comparison( $canonical );

						// Deduplicate: if this URL was already linked in the message, just use the title text.
						if ( isset( $linked_urls_in_message[ $norm_key ] ) ) {
							self::log_decision( 'DEDUP_PLACEHOLDER', array(
								'placeholder_id' => $idx,
								'url'            => $canonical,
							) );
							return $title;
						}

						$linked_urls_in_message[ $norm_key ] = true;
						self::log_decision( 'INSERTED_PLACEHOLDER', array(
							'placeholder_id' => $idx,
							'url'            => $canonical,
							'title'          => $title,
						) );

						$token = '%%%RESOLVED_LINK_' . ( $resolved_token_idx++ ) . '%%%';
						$resolved_link_tokens[ $token ] = '[' . $title . '](' . $canonical . ')';
						return $token;
					}
				}

				// If placeholder does not resolve to an allowed verified registry link, strip it.
				self::log_decision( 'REMOVED_UNVERIFIED_PLACEHOLDER', array( 'placeholder_id' => $idx ) );
				return '';
			},
			$reply
		);

		// ---------------------------------------------------------------------
		// STEP 3b: Scan text for model-generated URLs/domains
		// ---------------------------------------------------------------------

		// 1. Markdown links [label](url)
		$reply = preg_replace_callback(
			'#\[([^\]\r\n]+)\]\(([^)\s]+)\)#i',
			function ( $m ) use ( $language, &$linked_urls_in_message ) {
				$label = trim( $m[1] );
				$url   = trim( $m[2] );

				// Skip mailto and tel links here (handled in 3e).
				if ( preg_match( '/^(mailto:|tel:)/i', $url ) ) {
					return $m[0];
				}

				$verified = self::lookup_canonical( $url, $language );

				if ( $verified ) {
					$canonical = $verified['canonical_url'];
					$norm_key  = LinkRegistry::normalize_for_comparison( $canonical );

					// Deduplicate: if already linked, unwrap to plain text label.
					if ( isset( $linked_urls_in_message[ $norm_key ] ) ) {
						$clean_label = ( ! empty( $label ) && ! preg_match( '#^https?://#i', $label ) ) ? $label : '';
						self::log_decision( 'DEDUP_MARKDOWN_LINK', array( 'url' => $canonical ) );
						return $clean_label;
					}

					$linked_urls_in_message[ $norm_key ] = true;

					$clean_title = ( ! empty( $label ) && ! preg_match( '#^https?://#i', $label ) )
						? $label
						: ( $verified['title'] ?? 'Link' );

					self::log_decision( 'KEPT_REGISTRY_URL', array(
						'original_url'  => $url,
						'canonical_url' => $canonical,
						'label'         => $clean_title,
					) );

					return '[' . $clean_title . '](' . $canonical . ')';
				}

				// Not in registry: remove it and log a warning (never keep an unverified URL).
				self::log_decision( 'REMOVED_UNVERIFIED_URL', array(
					'url'   => $url,
					'label' => $label,
				) );

				// If label was a URL itself, remove completely. Otherwise keep the label text unlinked.
				return preg_match( '#^https?://#i', $label ) ? '' : $label;
			},
			$reply
		);

		// 2. HTML links <a href="url">label</a>
		$reply = preg_replace_callback(
			'#<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>#is',
			function ( $m ) use ( $language, &$linked_urls_in_message ) {
				$url   = trim( $m[1] );
				$label = trim( strip_tags( $m[2] ) );

				// Skip mailto and tel here.
				if ( preg_match( '/^(mailto:|tel:)/i', $url ) ) {
					return $m[0];
				}

				$verified = self::lookup_canonical( $url, $language );

				if ( $verified ) {
					$canonical = $verified['canonical_url'];
					$norm_key  = LinkRegistry::normalize_for_comparison( $canonical );

					if ( isset( $linked_urls_in_message[ $norm_key ] ) ) {
						self::log_decision( 'DEDUP_HTML_LINK', array( 'url' => $canonical ) );
						return ! empty( $label ) ? $label : '';
					}

					$linked_urls_in_message[ $norm_key ] = true;
					$title     = ! empty( $label ) ? $label : ( $verified['title'] ?? 'Link' );

					self::log_decision( 'KEPT_REGISTRY_HTML_URL', array(
						'original_url'  => $url,
						'canonical_url' => $canonical,
					) );

					return '[' . $title . '](' . $canonical . ')';
				}

				self::log_decision( 'REMOVED_UNVERIFIED_HTML_LINK', array( 'url' => $url ) );
				return preg_match( '#^https?://#i', $label ) ? '' : $label;
			},
			$reply
		);

		// 3. Bare URLs or domains emitted by the model
		$reply = preg_replace_callback(
			'#(?<![\[\(<@])(https?://[^\s\)<>"\'`,]+)(?![\]\)>])#i',
			function ( $m ) use ( $language, &$linked_urls_in_message ) {
				$raw_url = trim( $m[1] );
				$trailing_punct = '';

				if ( preg_match( '/([.,;:!?]+)$/', $raw_url, $pm ) ) {
					$trailing_punct = $pm[1];
					$raw_url        = substr( $raw_url, 0, -strlen( $trailing_punct ) );
				}

				$verified = self::lookup_canonical( $raw_url, $language );

				if ( $verified ) {
					$canonical = $verified['canonical_url'];
					$norm_key  = LinkRegistry::normalize_for_comparison( $canonical );

					if ( isset( $linked_urls_in_message[ $norm_key ] ) ) {
						self::log_decision( 'DEDUP_BARE_URL', array( 'url' => $canonical ) );
						return $trailing_punct;
					}

					$linked_urls_in_message[ $norm_key ] = true;
					$title     = ! empty( $verified['title'] ) ? $verified['title'] : 'Website';

					self::log_decision( 'FORMATTED_BARE_REGISTRY_URL', array(
						'original_url'  => $raw_url,
						'canonical_url' => $canonical,
					) );

					return '[' . $title . '](' . $canonical . ')' . $trailing_punct;
				}

				self::log_decision( 'REMOVED_BARE_UNVERIFIED_URL', array( 'url' => $raw_url ) );
				return $trailing_punct;
			},
			$reply
		);

		// ---------------------------------------------------------------------
		// STEP 3c: Auto-link known phrases
		// ---------------------------------------------------------------------
		// Exact whole-word, case-insensitive matching against curated phrase map.
		// If phrase maps to multiple URLs, it was already discarded (avoid ambiguity).
		// Link only the first occurrence per URL per message.
		// Skip text already inside a link or code block.
		$phrase_map = self::get_phrase_map( $language );

		if ( ! empty( $phrase_map ) ) {
			// Mask existing markdown links to prevent double-linking inside [label](url).
			$link_tokens = array();
			$token_idx   = 0;

			$reply = preg_replace_callback(
				'/\[[^\]]+\]\([^\)]+\)/',
				function ( $m ) use ( &$link_tokens, &$token_idx ) {
					$token = '%%%LINK_TOKEN_' . ( $token_idx++ ) . '%%%';
					$link_tokens[ $token ] = $m[0];
					return $token;
				},
				$reply
			);

			$autolink_tokens = array();
			$autolink_idx    = 0;

			foreach ( $phrase_map as $phrase => $entry ) {
				$canonical = $entry['canonical_url'];
				$norm_key  = LinkRegistry::normalize_for_comparison( $canonical );

				// "Link only the first occurrence per URL per message."
				if ( isset( $linked_urls_in_message[ $norm_key ] ) ) {
					continue;
				}

				$quoted  = preg_quote( $phrase, '/' );
				$pattern = '/\b(' . $quoted . ')\b/ui';

				if ( preg_match( $pattern, $reply, $pm ) ) {
					$matched_text = $pm[1];
					$token        = '%%%AUTOLINK_TOKEN_' . ( $autolink_idx++ ) . '%%%';
					$autolink_tokens[ $token ] = '[' . $matched_text . '](' . $canonical . ')';

					// Replace first occurrence with placeholder token to prevent subsequent phrases from matching inside this URL!
					$reply = preg_replace( $pattern, $token, $reply, 1 );

					$linked_urls_in_message[ $norm_key ] = true;
					self::log_decision( 'AUTO_LINKED_PHRASE', array(
						'phrase' => $phrase,
						'url'    => $canonical,
					) );
				}
			}

			// Restore newly autolinked tokens.
			if ( ! empty( $autolink_tokens ) ) {
				$reply = strtr( $reply, $autolink_tokens );
			}

			// Restore masked markdown links.
			if ( ! empty( $link_tokens ) ) {
				$reply = strtr( $reply, $link_tokens );
			}
		}

		// Restore Step 3a resolved placeholder tokens.
		if ( ! empty( $resolved_link_tokens ) ) {
			$reply = strtr( $reply, $resolved_link_tokens );
		}

		// ---------------------------------------------------------------------
		// STEP 3d: Dangling-link cleanup
		// ---------------------------------------------------------------------
		// If a sentence ends up with an empty link slot (like "visit our contact page at ."),
		// rewrite or remove that fragment so a broken sentence never reaches the user.
		$reply = self::clean_dangling_links( $reply );

		// ---------------------------------------------------------------------
		// STEP 3e: Emails (mailto:) and phone numbers (tel:)
		// ---------------------------------------------------------------------
		// Auto-linked ONLY if they appear in the knowledge base / business info verbatim.
		$reply = self::process_verbatim_contacts( $reply, $kb_chunks, $business_info );

		// ---------------------------------------------------------------------
		// STEP 3f: Strip trailing link blocks
		// ---------------------------------------------------------------------
		// NEVER append links to the end of a message. Remove any trailing link
		// blocks like "Relevant page:", "Related pages:", "Verwandte Seiten:",
		// "Relevante Seite:", "Mehr dazu:", "Learn more:", etc.
		$reply = self::strip_trailing_link_blocks( $reply );

		// ---------------------------------------------------------------------
		// STEP 5: Safety and Accuracy Guarantees (Final Assertion)
		// ---------------------------------------------------------------------
		// A link may only be output if its exact canonical URL exists in the registry.
		$seen_final = array();
		$reply = preg_replace_callback(
			'#\[([^\]\r\n]+)\]\(([^)\s]+)\)#i',
			function ( $m ) use ( $language, &$seen_final ) {
				$label = trim( $m[1] );
				$url   = trim( $m[2] );

				// Allow mailto and tel if passed by step 3e.
				if ( preg_match( '/^(mailto:|tel:)/i', $url ) ) {
					return $m[0];
				}

				$verified = self::lookup_canonical( $url, $language );
				if ( ! $verified ) {
					error_log( sprintf( '[AI Chatbot Safety Assertion] FAILED: outgoing message contained unregistered URL: %s', $url ) );
					self::log_decision( 'REMOVED_FINAL_ASSERTION', array( 'url' => $url ) );
					return preg_match( '#^https?://#i', $label ) ? '' : $label;
				}

				$canonical = $verified['canonical_url'];
				$norm_key  = LinkRegistry::normalize_for_comparison( $canonical );

				// Final dedup: if this canonical URL already appeared, unwrap to text.
				if ( isset( $seen_final[ $norm_key ] ) ) {
					self::log_decision( 'DEDUP_FINAL_ASSERTION', array( 'url' => $canonical ) );
					return preg_match( '#^https?://#i', $label ) ? '' : $label;
				}
				$seen_final[ $norm_key ] = true;

				return '[' . $label . '](' . $canonical . ')';
			},
			$reply
		);

		// Restore masked code blocks.
		if ( ! empty( $code_blocks ) ) {
			$reply = strtr( $reply, $code_blocks );
		}

		return trim( $reply );
	}

	/**
	 * Strip trailing link blocks from the end of a message.
	 *
	 * Removes patterns like:
	 * - "Relevant page: [Title](url)"
	 * - "Related pages: ..."
	 * - "Verwandte Seiten: ..."
	 * - "Relevante Seite: ..."
	 * - "Mehr dazu: ..."
	 * - "Learn more: ..."
	 * - Any trailing line that is only a markdown link
	 *
	 * @param string $text Text to clean.
	 * @return string Cleaned text.
	 */
	public static function strip_trailing_link_blocks( string $text ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}

		// Pattern for trailing link block labels (multilingual).
		$block_labels = '(?:Relevant\s+page|Relevante?\s+Seite|Related\s+pages?|Verwandte\s+Seiten?|Mehr\s+dazu|Learn\s+more|More\s+info(?:rmation)?|Weitere\s+Informationen|Weitere\s+Infos|Mehr\s+erfahren|See\s+also|Siehe\s+auch|Más\s+información|En\s+savoir\s+plus|Per\s+saperne\s+di\s+più|Saiba\s+mais|थप\s+जानकारी|अधिक\s+जानकारी|Quellen?|Sources?|References?)';

		do {
			$prev = $text;

			// Strip "Label: [Title](url)" or "Label: url" at end of text.
			$text = preg_replace(
				'/(?:\r?\n)+\s*' . $block_labels . '\s*[:\-]?\s*(?:\[[^\]]*\]\([^\)]*\)|https?:\/\/[^\s\)\],<]+)(?:\s*[,;]\s*(?:\[[^\]]*\]\([^\)]*\)|https?:\/\/[^\s\)\],<]+))*\s*$/ui',
				'',
				$text
			);

			// Strip a line that is ONLY a markdown link at the end (e.g. "\n\n[Kontakt](url)")
			$text = preg_replace(
				'/(?:\r?\n)+\s*\[[^\]\r\n]+\]\(https?:\/\/[^\)\s]+\)\s*$/i',
				'',
				$text
			);

			// Strip trailing bare URL on its own line.
			$text = preg_replace(
				'/(?:\r?\n)+\s*https?:\/\/[^\s\)\],<]+\s*$/i',
				'',
				$text
			);

			// Strip trailing label alone left on a line (e.g. "Relevant page:")
			$text = preg_replace(
				'/(?:\r?\n)+\s*' . $block_labels . '\s*[:\-]?\s*$/ui',
				'',
				$text
			);

		} while ( $text !== $prev );

		return $text;
	}

	/**
	 * Dangling link cleanup.
	 *
	 * Cleans artifacts left behind when unverified URLs are stripped,
	 * such as "visit our contact page at .", "reach out at ,", "unter .",
	 * double punctuation, empty brackets, or severed sentences.
	 *
	 * @param string $text Text to clean.
	 * @return string Cleaned text.
	 */
	public static function clean_dangling_links( string $text ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}

		// 1. Sentences with specific prepositions followed by trailing punctuation
		// e.g. "visit our contact page at ." -> "visit our contact page."
		// e.g. "Sie finden uns unter ." -> "Sie finden uns."
		$prepositions = '(?:at|unter|auf|on|under|via|see|siehe|visit|besuchen Sie|check out|finden Sie unter)';

		$text = preg_replace(
			'/(?:,\s*|\s+)' . $prepositions . '\s*([.,;:!?])/ui',
			'$1',
			$text
		);

		// 2. Specific German & English broken sentence endings
		$text = preg_replace( '/\b(?:visit\s+(?:our\s+)?contact\s+page)\s+at\s*([.,;:!?])/ui', 'visit our contact page$1', $text );
		$text = preg_replace( '/\b(?:besuchen\s+Sie\s+(?:unsere\s+)?Kontaktseite)\s+unter\s*([.,;:!?])/ui', 'besuchen Sie unsere Kontaktseite$1', $text );
		$text = preg_replace( '/\b(?:reach\s+(?:out\s+)?to\s+us)\s+at\s*([.,;:!?])/ui', 'reach out to us$1', $text );
		$text = preg_replace( '/\b(?:kontaktieren\s+Sie\s+uns)\s+unter\s*([.,;:!?])/ui', 'kontaktieren Sie uns$1', $text );

		// 3. Remove empty brackets, parentheses, and broken link markers
		$text = preg_replace( '/\(\s*\)/', '', $text );
		$text = preg_replace( '/\[\s*\]/', '', $text );

		// 4. Clean trailing spaces before punctuation
		$text = preg_replace( '/\s+([.,;:!?])/', '$1', $text );

		// 5. Clean double/broken punctuation (e.g. "at , ." or ", .")
		$text = preg_replace( '/,\s*\./', '.', $text );
		$text = preg_replace( '/\b\.\s*\./', '.', $text );

		return trim( $text );
	}

	/**
	 * Process verbatim emails and phone numbers.
	 *
	 * Emails (mailto:) and phone numbers (tel:) may be auto-linked,
	 * but ONLY if they appear in the knowledge base or business info verbatim.
	 *
	 * @param string $text Message text.
	 * @param array  $kb_chunks Knowledge context chunks.
	 * @param array  $business_info Business profile info.
	 * @return string
	 */
	protected static function process_verbatim_contacts( string $text, array $kb_chunks, array $business_info ): string {
		$verbatim_emails = array();
		$verbatim_phones = array();

		// Collect from business info.
		if ( ! empty( $business_info['email'] ) && filter_var( $business_info['email'], FILTER_VALIDATE_EMAIL ) ) {
			$verbatim_emails[ strtolower( trim( $business_info['email'] ) ) ] = trim( $business_info['email'] );
		}
		if ( ! empty( $business_info['phone'] ) ) {
			$clean_ph = trim( $business_info['phone'] );
			$verbatim_phones[ self::normalize_phone( $clean_ph ) ] = $clean_ph;
		}

		// Collect verbatim contacts from knowledge base chunks.
		foreach ( $kb_chunks as $chunk ) {
			$chunk_text = is_array( $chunk ) ? ( $chunk['chunk_text'] ?? ( $chunk['content'] ?? '' ) ) : (string) $chunk;
			if ( empty( $chunk_text ) ) {
				continue;
			}

			// Extract emails from chunk.
			if ( preg_match_all( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $chunk_text, $em ) ) {
				foreach ( $em[0] as $e ) {
					$verbatim_emails[ strtolower( trim( $e ) ) ] = trim( $e );
				}
			}

			// Extract international/German phone numbers from chunk.
			if ( preg_match_all( '/(?:\+49|0049|0)[1-9][0-9\s\/\(\)\-]{6,20}[0-9]/', $chunk_text, $pm ) ) {
				foreach ( $pm[0] as $p ) {
					$p_trim = trim( $p );
					$norm_p = self::normalize_phone( $p_trim );
					if ( strlen( $norm_p ) >= 7 ) {
						$verbatim_phones[ $norm_p ] = $p_trim;
					}
				}
			}
		}

		// 1. Process mailto links or unlinked emails.
		// First check any model-generated mailto: links.
		$text = preg_replace_callback(
			'#\[([^\]]+)\]\(mailto:([^\)\s]+)\)#i',
			function ( $m ) use ( $verbatim_emails ) {
				$label = trim( $m[1] );
				$email = strtolower( trim( $m[2] ) );

				if ( isset( $verbatim_emails[ $email ] ) ) {
					self::log_decision( 'LINKED_EMAIL_VERBATIM', array( 'email' => $email ) );
					return '[' . $label . '](mailto:' . $email . ')';
				}

				// Unverified email: strip mailto link, keep plain text.
				self::log_decision( 'STRIPPED_UNVERIFIED_MAILTO', array( 'email' => $email ) );
				return $label;
			},
			$text
		);

		// Auto-link plain verbatim emails if present and not already inside a link.
		foreach ( $verbatim_emails as $norm_email => $raw_email ) {
			$escaped = preg_quote( $raw_email, '/' );
			// Match only if not preceded by (mailto: or [
			$pattern = '/(?<!\(mailto:)(?<!\[)\b(' . $escaped . ')\b(?![^\[]*\])/ui';
			if ( preg_match( $pattern, $text ) ) {
				$text = preg_replace( $pattern, '[' . $raw_email . '](mailto:' . $norm_email . ')', $text, 1 );
				self::log_decision( 'AUTO_LINKED_EMAIL', array( 'email' => $raw_email ) );
			}
		}

		// 2. Process tel links or unlinked phone numbers.
		$text = preg_replace_callback(
			'#\[([^\]]+)\]\(tel:([^\)\s]+)\)#i',
			function ( $m ) use ( $verbatim_phones ) {
				$label = trim( $m[1] );
				$phone = trim( $m[2] );
				$norm_ph = self::normalize_phone( $phone );

				if ( isset( $verbatim_phones[ $norm_ph ] ) ) {
					self::log_decision( 'LINKED_PHONE_VERBATIM', array( 'phone' => $phone ) );
					return '[' . $label . '](tel:' . $phone . ')';
				}

				self::log_decision( 'STRIPPED_UNVERIFIED_TEL', array( 'phone' => $phone ) );
				return $label;
			},
			$text
		);

		return $text;
	}

	/**
	 * Normalize phone number digits for comparison.
	 *
	 * @param string $phone Raw phone string.
	 * @return string Digits only with leading plus if present.
	 */
	protected static function normalize_phone( string $phone ): string {
		$has_plus = str_starts_with( trim( $phone ), '+' );
		$digits   = preg_replace( '/[^\d]/', '', $phone );
		return ( $has_plus ? '+' : '' ) . $digits;
	}
}
