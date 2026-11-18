<?php
/**
 * Text Chunking and Token Management.
 *
 * @package AIChatbot\Knowledge
 */

namespace AIChatbot\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Class Chunker.
 */
class Chunker {

	/**
	 * Target chunk size in characters (~500 to 800 tokens).
	 */
	public const TARGET_CHUNK_CHARS = 2400;

	/**
	 * Overlap in characters (~50 to 80 tokens).
	 */
	public const OVERLAP_CHARS = 300;

	/**
	 * Clean text for chunking.
	 *
	 * @param string $text Raw text.
	 * @return string Normalized clean text.
	 */
	public static function clean( string $text ): string {
		// Strip zero-width characters and control codes except newline/tab.
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Estimate token count from text length.
	 *
	 * @param string $text Input text.
	 * @return int Estimated tokens.
	 */
	public static function estimate_tokens( string $text ): int {
		$len = mb_strlen( $text, 'UTF-8' );
		return max( 1, (int) ceil( $len / 4 ) );
	}

	/**
	 * Split text into semantic overlapping chunks.
	 *
	 * @param string $text Cleaned text.
	 * @param array  $base_metadata Metadata to attach to each chunk.
	 * @return array List of chunk items [{chunk_text, token_count, metadata, chunk_index}].
	 */
	public static function chunk_text( string $text, array $base_metadata = array() ): array {
		$clean_text = self::clean( $text );
		if ( empty( $clean_text ) ) {
			return array();
		}

		// If text is already under target chunk size, return single chunk.
		if ( mb_strlen( $clean_text, 'UTF-8' ) <= self::TARGET_CHUNK_CHARS ) {
			return array(
				array(
					'chunk_text'  => $clean_text,
					'token_count' => self::estimate_tokens( $clean_text ),
					'metadata'    => array_merge( $base_metadata, array( 'chunk_index' => 0 ) ),
					'chunk_index' => 0,
				),
			);
		}

		$chunks = array();
		$length = mb_strlen( $clean_text, 'UTF-8' );
		$start  = 0;
		$index  = 0;

		while ( $start < $length ) {
			$end = min( $length, $start + self::TARGET_CHUNK_CHARS );

			// Try to break at paragraph boundary, sentence boundary, or space.
			if ( $end < $length ) {
				$window = mb_substr( $clean_text, $start, self::TARGET_CHUNK_CHARS, 'UTF-8' );

				$break_pos = false;

				// 1. Paragraph boundary.
				$last_para = mb_strrpos( $window, "\n\n", 0, 'UTF-8' );
				if ( false !== $last_para && $last_para > ( self::TARGET_CHUNK_CHARS * 0.5 ) ) {
					$break_pos = $last_para + 2;
				}

				// 2. Sentence boundary.
				if ( false === $break_pos ) {
					if ( preg_match_all( '/[.!?]\s+/u', $window, $matches, PREG_OFFSET_CAPTURE ) ) {
						$last_match = end( $matches[0] );
						if ( $last_match[1] > ( self::TARGET_CHUNK_CHARS * 0.5 ) ) {
							$break_pos = $last_match[1] + mb_strlen( $last_match[0], 'UTF-8' );
						}
					}
				}

				// 3. Line break or space.
				if ( false === $break_pos ) {
					$last_space = mb_strrpos( $window, ' ', 0, 'UTF-8' );
					if ( false !== $last_space && $last_space > ( self::TARGET_CHUNK_CHARS * 0.5 ) ) {
						$break_pos = $last_space + 1;
					}
				}

				if ( false !== $break_pos ) {
					$end = $start + $break_pos;
				}
			}

			$chunk_segment = trim( mb_substr( $clean_text, $start, $end - $start, 'UTF-8' ) );

			if ( ! empty( $chunk_segment ) ) {
				$chunks[] = array(
					'chunk_text'  => $chunk_segment,
					'token_count' => self::estimate_tokens( $chunk_segment ),
					'metadata'    => array_merge( $base_metadata, array( 'chunk_index' => $index ) ),
					'chunk_index' => $index,
				);
				$index++;
			}

			if ( $end >= $length ) {
				break;
			}

			// Advance start point with overlap.
			$start = max( $start + 1, $end - self::OVERLAP_CHARS );
		}

		return $chunks;
	}

	/**
	 * Format and chunk a curated Q&A pair as a single high-priority chunk.
	 *
	 * @param string $question Question text.
	 * @param string $answer Answer text.
	 * @param array  $metadata Additional metadata.
	 * @return array Single chunk item with Q&A boost metadata.
	 */
	public static function chunk_qa( string $question, string $answer, array $metadata = array() ): array {
		$text = "Question: " . trim( $question ) . "\nAnswer: " . trim( $answer );

		$qa_meta = array_merge(
			$metadata,
			array(
				'source_type' => 'qa',
				'qa_boost'    => 1.25,
				'question'    => trim( $question ),
				'chunk_index' => 0,
			)
		);

		return array(
			array(
				'chunk_text'  => $text,
				'token_count' => self::estimate_tokens( $text ),
				'metadata'    => $qa_meta,
				'chunk_index' => 0,
			),
		);
	}
}
