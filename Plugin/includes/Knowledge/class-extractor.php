<?php
/**
 * Multi-format text extractor for Knowledge Ingestion.
 *
 * @package AIChatbot\Knowledge
 */

namespace AIChatbot\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Class Extractor.
 */
class Extractor {

	/**
	 * Extract text from a URL (webpage).
	 *
	 * @param string $url Webpage URL.
	 * @return array{success: bool, text: string, error?: string}
	 */
	public static function extract_url( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'Mozilla/5.0 (compatible; AIChatbotCrawler/1.0; +' . home_url() . ')',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => $response->get_error_message(),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'HTTP response status: ' . $status,
			);
		}

		$html = wp_remote_retrieve_body( $response );
		$text = self::clean_html( $html );

		return array(
			'success' => true,
			'text'    => $text,
		);
	}

	/**
	 * Clean HTML into plain readable text.
	 *
	 * @param string $html Raw HTML.
	 * @return string Clean text.
	 */
	public static function clean_html( string $html ): string {
		// Remove script, style, noscript, and iframe tags and their contents.
		$html = (string) preg_replace( '/<(script|style|noscript|iframe)[^>]*?>.*?<\/\\1>/si', ' ', $html );

		// Remove common navigation, header, footer, and sidebar elements.
		$html = (string) preg_replace( '/<(nav|header|footer|aside)[^>]*?>.*?<\/\\1>/si', ' ', $html );

		// Strip partner logo carousels, client logo sliders, and sponsor grids to eliminate alt-text/logo noise.
		$html = (string) preg_replace( '/<[^>]+(class|id)=["\'][^"\']*(?:partner-logo|partners-slider|partner-carousel|clients-logo|sponsors|brand-carousel)[^"\']*["\'][^>]*>.*?<\/[^>]+>/si', ' ', $html );

		// Preserve hyperlinks as markdown links [label](url) so knowledge base chunks retain URLs.
		$html = (string) preg_replace_callback(
			'/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si',
			function ( $m ) {
				$href  = trim( $m[1] );
				$label = trim( wp_strip_all_tags( $m[2] ) );
				if ( empty( $href ) || str_starts_with( $href, '#' ) || str_starts_with( $href, 'javascript:' ) ) {
					return $label;
				}
				if ( empty( $label ) ) {
					// Discard bare external partner links (e.g. partner logo URLs) to avoid catalog confusion.
					$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
					if ( preg_match( '#^https?://#i', $href ) && ( '' === $home_host || ! str_contains( $href, $home_host ) ) ) {
						return '';
					}
					return $href;
				}
				return '[' . $label . '](' . $href . ')';
			},
			$html
		);

		// Replace block tags with newlines.
		$html = (string) preg_replace( '/<\/(p|div|h[1-6]|li|tr|blockquote|article|section)>/i', "\n", $html );
		$html = (string) preg_replace( '/<br\s*\/?>/i', "\n", $html );

		// Strip remaining tags.
		$text = wp_strip_all_tags( $html );

		// Decode entities and normalize whitespace.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+/", ' ', $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Extract text from an uploaded file based on its mime type or extension.
	 * Supports: TXT, MD, CSV, PDF, DOCX, XLSX, PPTX.
	 *
	 * @param string $file_path Absolute path to file on disk.
	 * @param string $extension Lowercase file extension.
	 * @return array{success: bool, text: string, error?: string}
	 */
	public static function extract_file( string $file_path, string $extension ): array {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => __( 'File does not exist or is not readable.', 'ai-chatbot' ),
			);
		}

		$ext = strtolower( $extension );

		switch ( $ext ) {
			case 'txt':
			case 'md':
				$content = file_get_contents( $file_path );
				return array(
					'success' => true,
					'text'    => trim( (string) $content ),
				);

			case 'csv':
				return self::extract_csv( $file_path );

			case 'docx':
				return self::extract_docx( $file_path );

			case 'xlsx':
				return self::extract_xlsx( $file_path );

			case 'pptx':
				return self::extract_pptx( $file_path );

			case 'pdf':
				return self::extract_pdf( $file_path );

			case 'doc':
			case 'ppt':
				// Legacy binary formats.
				return self::extract_binary_text( $file_path );

			default:
				return array(
					'success' => false,
					'text'    => '',
					'error'   => sprintf( __( 'Unsupported file format: .%s', 'ai-chatbot' ), esc_html( $ext ) ),
				);
		}
	}

	/**
	 * Extract formatted text from CSV file.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	protected static function extract_csv( string $path ): array {
		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return array( 'success' => false, 'text' => '', 'error' => 'Unable to open CSV file.' );
		}

		$lines   = array();
		$headers = fgetcsv( $handle );
		if ( ! $headers ) {
			fclose( $handle );
			return array( 'success' => true, 'text' => '' );
		}

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$row_parts = array();
			foreach ( $headers as $i => $col ) {
				$val = $row[ $i ] ?? '';
				if ( '' !== trim( $val ) ) {
					$row_parts[] = trim( $col ) . ': ' . trim( $val );
				}
			}
			if ( ! empty( $row_parts ) ) {
				$lines[] = implode( ' | ', $row_parts );
			}
		}

		fclose( $handle );

		return array(
			'success' => true,
			'text'    => implode( "\n", $lines ),
		);
	}

	/**
	 * Extract text from DOCX file using native ZipArchive + XML parsing.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	protected static function extract_docx( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'success' => false, 'text' => '', 'error' => 'PHP ZipArchive extension required for DOCX.' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array( 'success' => false, 'text' => '', 'error' => 'Failed to open DOCX archive.' );
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( ! $xml ) {
			return array( 'success' => false, 'text' => '', 'error' => 'No word/document.xml found in DOCX.' );
		}

		$xml  = str_replace( '</w:p>', "\n", $xml );
		$text = wp_strip_all_tags( $xml );

		return array(
			'success' => true,
			'text'    => trim( $text ),
		);
	}

	/**
	 * Extract text from XLSX spreadsheet using ZipArchive + sharedStrings.xml.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	protected static function extract_xlsx( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'success' => false, 'text' => '', 'error' => 'PHP ZipArchive extension required for XLSX.' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array( 'success' => false, 'text' => '', 'error' => 'Failed to open XLSX archive.' );
		}

		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		$zip->close();

		if ( ! $xml ) {
			return array( 'success' => true, 'text' => '' );
		}

		$text = wp_strip_all_tags( str_replace( '</t>', "\n", $xml ) );

		return array(
			'success' => true,
			'text'    => trim( $text ),
		);
	}

	/**
	 * Extract text from PPTX slides using ZipArchive.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	protected static function extract_pptx( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'success' => false, 'text' => '', 'error' => 'PHP ZipArchive extension required for PPTX.' );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array( 'success' => false, 'text' => '', 'error' => 'Failed to open PPTX archive.' );
		}

		$texts = array();
		for ( $i = 1; $i <= $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i - 1 );
			if ( str_starts_with( $name, 'ppt/slides/slide' ) && str_ends_with( $name, '.xml' ) ) {
				$xml     = $zip->getFromIndex( $i - 1 );
				$texts[] = wp_strip_all_tags( str_replace( '</a:p>', "\n", (string) $xml ) );
			}
		}
		$zip->close();

		return array(
			'success' => true,
			'text'    => trim( implode( "\n\n", $texts ) ),
		);
	}

	/**
	 * Pure PHP text stream extraction for searchable PDF files.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	protected static function extract_pdf( string $path ): array {
		$content = file_get_contents( $path );
		if ( empty( $content ) ) {
			return array( 'success' => false, 'text' => '', 'error' => 'Empty PDF file.' );
		}

		// Find streams.
		$text_output = '';
		if ( preg_match_all( '#stream[\r\n]+(.*?)[\r\n]+endstream#s', $content, $matches ) ) {
			foreach ( $matches[1] as $stream ) {
				// Attempt inflate if FlateDecode compressed.
				$decompressed = @gzuncompress( $stream );
				if ( false === $decompressed ) {
					$decompressed = @gzinflate( $stream );
				}
				$data = false !== $decompressed ? $decompressed : $stream;

				// Extract text chunks between BT ... ET.
				if ( preg_match_all( '#\((.*?)\)\s*Tj#s', $data, $tj_matches ) ) {
					$text_output .= ' ' . implode( ' ', $tj_matches[1] );
				} elseif ( preg_match_all( '#\[(.*?)\]\s*TJ#s', $data, $tj_matches ) ) {
					foreach ( $tj_matches[1] as $arr ) {
						if ( preg_match_all( '#\((.*?)\)#s', $arr, $sub ) ) {
							$text_output .= ' ' . implode( '', $sub[1] );
						}
					}
					$text_output .= "\n";
				}
			}
		}

		$clean = trim( (string) preg_replace( '/\s+/', ' ', $text_output ) );

		if ( strlen( $clean ) < 20 ) {
			// If text stream parsing found minimal text, check if standard raw text exists.
			preg_match_all( '#\(([a-zA-Z0-9\s.,!?-]{4,})\)#', $content, $raw_matches );
			if ( ! empty( $raw_matches[1] ) ) {
				$clean = implode( ' ', $raw_matches[1] );
			}
		}

		if ( strlen( $clean ) < 10 ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => __( 'Could not extract text. If this is a scanned image-only PDF, please upload a text-searchable PDF.', 'ai-chatbot' ),
			);
		}

		return array(
			'success' => true,
			'text'    => $clean,
		);
	}

	/**
	 * Extract ASCII text strings from legacy binary formats (.doc, .ppt).
	 *
	 * @param string $path Path.
	 * @return array
	 */
	protected static function extract_binary_text( string $path ): array {
		$content = file_get_contents( $path );
		if ( empty( $content ) ) {
			return array( 'success' => false, 'text' => '', 'error' => 'Empty file.' );
		}

		// Find contiguous printable text strings.
		preg_match_all( '/[\x20-\x7E\t\r\n]{4,}/', $content, $matches );
		$text = implode( ' ', $matches[0] ?? array() );

		return array(
			'success' => true,
			'text'    => trim( (string) preg_replace( '/\s+/', ' ', $text ) ),
		);
	}
}
