<?php
/**
 * Fast, zero-API language detection, conversation language resolution,
 * and comprehensive multilingual consistency for the chatbot.
 *
 * Detection is deliberately heuristic and runs entirely in PHP: no
 * network call, no model round trip, and no per-message translation overhead.
 * Follows the 5-tier Language Priority System:
 *   1. Explicit language requested by the user
 *   2. Language of the latest user message
 *   3. Active conversation language (language lock)
 *   4. Dominant language in a mixed-language message
 *   5. English only as a final fallback
 *
 * @package AIChatbot\Language
 */

namespace AIChatbot\Language;

use AIChatbot\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class LanguageService.
 */
class LanguageService {

	/**
	 * Language used when nothing more specific is known.
	 */
	public const DEFAULT_LANGUAGE = 'en';

	/**
	 * Confidence needed to switch the conversation to a newly detected language.
	 */
	public const SWITCH_CONFIDENCE = 0.5;

	/**
	 * Confidence needed to override a stored language in "sticky" mode.
	 */
	public const STICKY_CONFIDENCE = 0.7;

	/**
	 * Minimum word count for a marker-less message to be treated as a
	 * substantive sentence in the default language rather than a terse,
	 * ambiguous follow-up that should keep the conversation's language.
	 */
	public const SUBSTANTIVE_WORDS = 2;

	/**
	 * Confidence below which "auto_fallback" mode falls back to the default.
	 */
	public const FALLBACK_CONFIDENCE = 0.5;

	/**
	 * Distinctive markers, weighted double.
	 *
	 * English markers are tracked to accurately compare code-switching
	 * and mixed-language inputs without assuming English wins by default.
	 */
	protected static $strong = array(
		'de' => array(
			'hallo', 'servus', 'moin', 'danke', 'dankeschön', 'dankeschoen',
			'dankeschon', 'bitte', 'gerne', 'gern', 'guten', 'grüß', 'gruss',
			'kostet', 'kosten', 'preis', 'preise', 'leistungen', 'leistung',
			'dienstleistung', 'dienstleistungen', 'welche', 'welcher', 'welches',
			'bietet', 'bieten', 'anbieten', 'kontaktieren', 'brauche', 'brauchen',
			'möchte', 'moechte', 'kontaktformular', 'wie viel', 'wie teuer', 'was kostet',
			'was kosten', 'angebot', 'angebote', 'beratung', 'vielen dank', 'guten tag',
			'guten morgen', 'guten abend', 'grüß gott', 'gruss gott',
			'nicht', 'keine', 'unser', 'unsere', 'öffnungszeiten', 'geschäftszeiten',
			'funktioniert', 'funktionieren', 'wie funktioniert', 'telefonnummer',
		),
		'es' => array(
			'hola', 'gracias', 'usted', 'ustedes', 'cuáles', 'cuales',
			'necesito', 'necesitan', 'podrían', 'podrian', 'nuestro',
			'nuestra', 'nuestros', 'por favor', 'buenos días', 'buenos dias',
			'buenas tardes', 'buenas noches', 'cuánto', 'cuanto', 'cuesta',
			'cuestan', 'precio', 'precios', 'servicios', 'servicio',
			'contacto', 'horario', 'horarios', 'porfa', 'dame', 'quiero', 'podemos', 'pueden',
			'presupuesto', 'funciona', 'cómo funciona', 'como funciona', 'información', 'informacion',
		),
		'ne' => array(
			'namaskar', 'namaste', 'dhanyabad', 'kripaya', 'kripya', 'kun',
			'ke ho', 'ke cha', 'ke chha', 'timro', 'hamilai', 'hamro', 'mero',
			'tapai', 'hajur', 'garne', 'garna', 'chha', 'cha', 'chaina',
			'kati', 'parcha', 'parchha', 'kasto', 'bhayo', 'ko',
			'nepali', 'nepal', 'sahab', 'ji', 'jankari', 'kasari',
			'laagcha', 'lagcha', 'bhaneko', 'bhannus', 'bolnus', 'sodhnu',
			'sewa', 'shulka', 'samparka',
		),
		'fr' => array(
			'bonjour', 'bonsoir', 'merci', 'combien', 'coûte', 'coutent',
			'coute', 'coûtent', 'prix', 'devis',
			'facture', "s'il vous plaît", 'sil vous plait', 'svp', 'pourquoi', 'comment',
			'notre', 'nos', 'votre', 'vos', 'pouvez',
			'pouvez-vous', 'voulez', 'souhaite', 'voudrais', 'renseignement',
			'horaires', 'contact', 'adresse',
		),
		'it' => array(
			'ciao', 'grazie', 'buongiorno', 'buonasera', 'quanto', 'costa',
			'costano', 'prezzo', 'prezzi', 'preventivo', 'per favore',
			'perche', 'perché', 'come', 'servizi', 'servizio', 'nostro',
			'nostra', 'nostri', 'vostro', 'vostra', 'posso', 'possiamo',
			'vorrei', 'informazioni', 'orari', 'apertura', 'contatto',
		),
		'pt' => array(
			'olá', 'ola', 'obrigado', 'obrigada', 'quanto', 'custa', 'custam',
			'preço', 'preco', 'preços', 'orçamento', 'orcamento', 'por favor', 'por que',
			'porque', 'como', 'serviços', 'servicos', 'nosso', 'nossa',
			'nossos', 'você', 'voce', 'vocês', 'podemos', 'gostaria',
			'bom dia', 'boa tarde', 'boa noite', 'contato', 'horário', 'horario',
		),
		'hi' => array(
			'namaste', 'dhanyawad', 'dhanyavad', 'kaise', 'kya', 'kitna',
			'kitni', 'kitne', 'hoga', 'hogi', 'honge', 'karta', 'karti',
			'karte', 'chahiye', 'batao', 'bataiye', 'kripya', 'sahab', 'ji', 'aapka', 'aapki',
			'hamara', 'mera', 'meri', 'shukriya', 'jankari', 'sampark', 'samay',
		),
		'en' => array(),
	);

	/**
	 * Corroborating markers, weighted single.
	 */
	protected static $weak = array(
		'de' => array(
			'ich', 'sie', 'und', 'wie', 'wo', 'sind', 'haben', 'der', 'die',
			'das', 'den', 'dem', 'ein', 'eine', 'du', 'er', 'es', 'wir',
			'ihr', 'ja', 'nein', 'aber', 'auch', 'mit', 'ohne', 'muss',
			'können', 'kann', 'habe', 'hat', 'ist', 'war', 'für', 'fuer',
			'auf', 'aus', 'bei', 'zur', 'zum', 'uber', 'nach', 'von',
			'an', 'am', 'im', 'in',
		),
		'es' => array(
			'el', 'la', 'los', 'las', 'de', 'que', 'en', 'y', 'o', 'un',
			'una', 'por', 'con', 'para', 'como', 'pero', 'más', 'mas', 'muy',
			'ya', 'sí', 'si', 'no', 'lo', 'se', 'su', 'sus', 'yo', 'tu', 'te',
			'me', 'mi', 'nos', 'es', 'son', 'hay', 'está', 'esta', 'tienen',
			'tiene', 'tienes', 'al', 'del',
		),
		'ne' => array(
			'ma', 'mi', 'hami', 'timi', 'tyo', 'van', 'chau', 'thiyo', 'bhanu',
			'bhanubhakta', 'ni', 'pani', 'bhan', 'xa', 'xaina', 'ke', 'la',
			'ho', 'k', 'ra', 'baata', 'le',
		),
		'fr' => array(
			'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'ou',
			'est', 'sont', 'avec', 'dans', 'sur', 'pour', 'ce', 'cette',
			'ces', 'qui', 'que', 'je', 'tu', 'il', 'elle', 'nous', 'vous',
			'ils', 'elles', 'oui', 'non', 'plus', 'pas', 'ne', 'se', 'y',
			'en', 'au', 'aux', 'par',
		),
		'it' => array(
			'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'di',
			'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'e', 'ed',
			'o', 'è', 'sono', 'ha', 'hanno', 'che', 'chi', 'io', 'tu',
			'lui', 'lei', 'noi', 'voi', 'loro', 'si', 'no', 'non', 'più',
			'piu', 'ma', 'anche', 'del', 'della', 'dello', 'dei',
		),
		'pt' => array(
			'o', 'a', 'os', 'as', 'um', 'uma', 'uns', 'umas', 'de', 'em',
			'para', 'com', 'por', 'e', 'ou', 'é', 'são', 'sao', 'tem',
			'têm', 'que', 'quem', 'eu', 'tu', 'ele', 'ela', 'nós', 'nos',
			'eles', 'elas', 'sim', 'não', 'nao', 'mais', 'mas', 'também',
			'tambem', 'já', 'ja', 'da', 'dos', 'das',
		),
		'hi' => array(
			'hai', 'hain', 'hoon', 'hun', 'ho', 'ka', 'ki', 'ke', 'ko',
			'se', 'me', 'mein', 'par', 'aur', 'ya', 'nahi', 'nahin', 'bhi',
			'toh', 'to', 'ab', 'ye', 'yeh', 'wo', 'woh', 'hum', 'aap',
		),
		'en' => array(
			'is', 'are', 'the', 'a', 'an', 'of', 'to', 'in', 'for', 'on',
			'with', 'at', 'by', 'from', 'about', 'and', 'or', 'but', 'so',
			'if', 'it', 'its', 'this', 'that', 'i', 'you', 'he', 'she',
			'we', 'they', 'my', 'your', 'our', 'do', 'does', 'did', 'have',
			'has', 'will', 'would', 'can', 'could', 'should', 'into', 'using',
			'also', 'than', 'as', 'there', 'their',
		),
	);

	/**
	 * Devanagari distinctive marker words for distinguishing Nepali from Hindi.
	 */
	protected static $devanagari_markers = array(
		'ne' => array(
			'गर्छ', 'गर्दछ', 'गर्नुहोस्', 'गर्नुस्', 'गर्न', 'गरेको', 'गर्दै',
			'पर्छ', 'पर्दछ', 'कति', 'लाग्छ', 'लाग्दछ', 'प्यानल', 'प्यानलले',
			'बारेमा', 'चाहिन्छ', 'भन्नुहोस्', 'भन्नुस्', 'भयो', 'तपाईं', 'तपाईंको',
			'हाम्रो', 'हामीलाई', 'मेरो', 'यसको', 'कसरी', 'काम', 'छ', 'छन्',
			'छैन', 'छैनन्', 'हो', 'होइन', 'हुनेछ', 'सकिन्छ', 'दिन्छ', 'दक्षता',
			'जडान', 'अनुदान', 'सोलार', 'सोध्दै', 'के', 'कुन', 'कस्तो', 'कहिले',
			'किन', 'हुन्छ', 'मैले', 'लागि', 'मलाई',
		),
		'hi' => array(
			'करता', 'करते', 'करती', 'करें', 'करना', 'होगी', 'होगा', 'होंगे',
			'कितनी', 'कितना', 'कितने', 'कैसे', 'इसका', 'इसकी', 'इसके',
			'बारे में', 'चाहिए', 'बताइए', 'बताओ', 'है', 'हैं', 'हूँ', 'था',
			'थी', 'थे', 'नहीं', 'सकते', 'सकता', 'सकती', 'पैनल', 'लागत',
			'दक्षता', 'स्थापना', 'जानकारी', 'नमस्ते', 'कृपया', 'धन्यवाद',
			'क्या', 'कौन', 'कहाँ', 'कब', 'क्यों', 'होता', 'होती', 'मुझे',
			'हमें', 'आपका', 'सोलर', 'लगवाना', 'लगवाने',
		),
	);

	/**
	 * Detect if the message contains an explicit request to switch or use a specific language.
	 *
	 * Priority 1 in the Language Priority System.
	 *
	 * @param string $message Visitor message.
	 * @return string Target ISO-639-1 code if detected, or empty string.
	 */
	public static function detect_explicit_language_request( string $message ): string {
		$text = strtolower( trim( $message ) );

		if ( '' === $text ) {
			return '';
		}

		// English requests.
		if ( preg_match( '/\b(?:explain\s+(?:that\s+)?in|speak\s+(?:in\s+)?|reply\s+in|answer\s+in|switch\s+to|write\s+in|can\s+you\s+(?:speak|use)|talk\s+in|now\s+in|in)\s+english\b/ui', $text )
			|| preg_match( '/\b(?:auf\s+englisch|en\s+anglais|en\s+ingl[eé]s|in\s+inglese|em\s+ingl[eê]s|english\s+ma\s+bhannus)\b/ui', $text )
			|| preg_match( '/(?:अंग्रेजी\s*मा|अंग्रेजी\s*में|अंग्रेज़ी\s*में)/u', $message )
		) {
			return 'en';
		}

		// German requests.
		if ( preg_match( '/\b(?:auf\s+deutsch|antworte\s+auf\s+deutsch|bitte\s+auf\s+deutsch|kannst\s+du\s+deutsch|sprich\s+deutsch|in\s+german|speak\s+german|reply\s+in\s+german|explain\s+in\s+german|en\s+allemand|en\s+alem[aá]n|in\s+tedesco)\b/ui', $text ) ) {
			return 'de';
		}

		// Spanish requests.
		if ( preg_match( '/\b(?:en\s+espa[nñ]ol|habla\s+(?:en\s+)?espa[nñ]ol|responde\s+en\s+espa[nñ]ol|in\s+spanish|speak\s+spanish|reply\s+in\s+spanish|explain\s+in\s+spanish|auf\s+spanisch|en\s+espagnol|in\s+spagnolo)\b/ui', $text ) ) {
			return 'es';
		}

		// Nepali requests.
		if ( preg_match( '/\b(?:nepali\s+ma(?:\s+bhannus|\s+bolnus)?|in\s+nepali|speak\s+nepali|reply\s+in\s+nepali|explain\s+in\s+nepali|auf\s+nepalesisch)\b/ui', $text )
			|| preg_match( '/(?:नेपालीमा(?:\s*भन्नुहोस्|\s*भन्नुस्)?|नेपाली\s*मा)/u', $message )
		) {
			return 'ne';
		}

		// Hindi requests.
		if ( preg_match( '/\b(?:hindi\s+me(?:\s+batao|\s+boliye)?|in\s+hindi|speak\s+hindi|reply\s+in\s+hindi|explain\s+in\s+hindi|auf\s+hindi)\b/ui', $text )
			|| preg_match( '/(?:हिंदी\s*में(?:\s*बताइए|\s*बोलिए)?|हिन्दी\s*में)/u', $message )
		) {
			return 'hi';
		}

		// French requests.
		if ( preg_match( '/\b(?:en\s+fran[cç]ais|parle\s+en\s+fran[cç]ais|r[eé]ponds\s+en\s+fran[cç]ais|in\s+french|speak\s+french|reply\s+in\s+french|explain\s+in\s+french|auf\s+franz[oö]sisch)\b/ui', $text ) ) {
			return 'fr';
		}

		// Italian requests.
		if ( preg_match( '/\b(?:in\s+italiano|parla\s+in\s+italiano|rispondi\s+in\s+italiano|in\s+italian|speak\s+italian|reply\s+in\s+italian|explain\s+in\s+italian|auf\s+italienisch)\b/ui', $text ) ) {
			return 'it';
		}

		// Portuguese requests.
		if ( preg_match( '/\b(?:em\s+portugu[eê]s|fale\s+em\s+portugu[eê]s|responda\s+em\s+portugu[eê]s|in\s+portuguese|speak\s+portuguese|reply\s+in\s+portuguese|explain\s+in\s+portuguese|auf\s+portugiesisch)\b/ui', $text ) ) {
			return 'pt';
		}

		return '';
	}

	/**
	 * Detect the language of a message.
	 *
	 * @param string $text Visitor message.
	 * @return array{language: string, confidence: float, script: string}
	 *         `language` is a supported ISO-639-1 code, or an empty
	 *         string when the script is not one the plugin supports;
	 *         `confidence` is 0.0-1.0; `script` names the dominant
	 *         writing system.
	 */
	public static function detect( string $text ): array {
		$trimmed = trim( $text );

		if ( '' === $trimmed ) {
			return array(
				'language'   => self::DEFAULT_LANGUAGE,
				'confidence' => 0.0,
				'script'     => 'latin',
			);
		}

		// Priority 1: Check for explicit language requests first.
		$explicit = self::detect_explicit_language_request( $trimmed );
		if ( '' !== $explicit ) {
			return array(
				'language'   => $explicit,
				'confidence' => 0.99,
				'script'     => 'latin',
			);
		}

		/*
		 * Script analysis.
		 * Devanagari script is used by Nepali and Hindi.
		 * Unsupported scripts (CJK, Cyrillic, Arabic, Greek) report an empty language.
		 */
		$script = self::dominant_script( $trimmed );

		if ( 'devanagari' === $script ) {
			$dev_lang = self::detect_devanagari_language( $trimmed );
			return array(
				'language'   => $dev_lang,
				'confidence' => 0.95,
				'script'     => 'devanagari',
			);
		}

		if ( 'latin' !== $script ) {
			return array(
				'language'   => '',
				'confidence' => 0.9,
				'script'     => $script,
			);
		}

		$normalized = self::normalize( $trimmed );

		// Compute score for all supported Latin-script languages plus English.
		$scores = array(
			'en' => 0,
			'de' => 0,
			'es' => 0,
			'ne' => 0,
			'fr' => 0,
			'it' => 0,
			'pt' => 0,
			'hi' => 0,
		);

		foreach ( array_keys( $scores ) as $code ) {
			$strong = self::match_count( $code, $normalized, true );
			$weak   = self::match_count( $code, $normalized, false );

			$scores[ $code ] = ( $strong * 2 ) + $weak;
		}

		arsort( $scores );
		$best_score = (int) current( $scores );

		// No markers matched: the message is English (the default) or
		// marker-less text, so report English with 0.0 confidence.
		if ( $best_score < 1 ) {
			return array(
				'language'   => self::DEFAULT_LANGUAGE,
				'confidence' => 0.0,
				'script'     => 'latin',
			);
		}

		$best_code = (string) key( $scores );

		// Confidence scales with score.
		if ( $best_score >= 6 ) {
			$confidence = 0.9;
		} elseif ( $best_score >= 4 ) {
			$confidence = 0.8;
		} elseif ( $best_score >= 2 ) {
			$confidence = 0.6;
		} else {
			$confidence = 0.35;
		}

		// Dampen a lone marker inside a long sentence if not corroborating.
		$word_count = count( preg_split( '/\s+/', $normalized ) );
		if ( $best_score < 2 && $word_count > 6 ) {
			$confidence = min( $confidence, 0.3 );
		}

		return array(
			'language'   => $best_code,
			'confidence' => $confidence,
			'script'     => 'latin',
		);
	}

	/**
	 * Distinguish between Nepali and Hindi for Devanagari text.
	 *
	 * @param string $text Raw Devanagari text.
	 * @return string 'ne' or 'hi'.
	 */
	protected static function detect_devanagari_language( string $text ): string {
		$ne_matches = 0;
		$hi_matches = 0;

		foreach ( self::$devanagari_markers['ne'] as $word ) {
			if ( str_contains( $text, $word ) ) {
				$ne_matches++;
			}
		}

		foreach ( self::$devanagari_markers['hi'] as $word ) {
			if ( str_contains( $text, $word ) ) {
				$hi_matches++;
			}
		}

		if ( $hi_matches > $ne_matches ) {
			return 'hi';
		}

		if ( $ne_matches > $hi_matches ) {
			return 'ne';
		}

		// Suffix and frequent word heuristics if tied.
		$has_hi_aux = preg_match( '/(?:है|हैं|था|थी|थे|में|का|की|के)\b/u', $text );
		$has_ne_aux = preg_match( '/(?:छ|छन्|थियो|मा|को|पर्छ|गर्छ)\b/u', $text );

		if ( $has_hi_aux && ! $has_ne_aux ) {
			return 'hi';
		}

		// Default to Nepali when ambiguous in Devanagari.
		return 'ne';
	}

	/**
	 * Resolve the effective response language for one conversation turn.
	 *
	 * Follows the 5-tier Language Priority System:
	 *   1. Explicit language requested by user
	 *   2. Language of latest user message (if confident)
	 *   3. Active conversation language (reused for ambiguous follow-ups)
	 *   4. Dominant language in mixed-language message
	 *   5. English only as a final fallback
	 *
	 * @param string $message          Visitor message.
	 * @param string $stored_language  Language carried by the conversation so far.
	 * @param array  $language_settings The `language` settings group.
	 * @return string A supported language code, or `auto` when genuinely unknown.
	 */
	public static function resolve( string $message, string $stored_language, array $language_settings ): string {
		$mode     = (string) ( $language_settings['mode'] ?? 'auto' );
		$default  = self::sanitize( (string) ( $language_settings['default_language'] ?? self::DEFAULT_LANGUAGE ) ) ?: self::DEFAULT_LANGUAGE;
		$fixed    = self::sanitize( (string) ( $language_settings['fixed_language'] ?? self::DEFAULT_LANGUAGE ) );

		// A configured fixed language always wins when fixed mode is enabled.
		if ( 'fixed' === $mode && ! empty( $fixed ) ) {
			return $fixed;
		}

		// When detection on input is disabled, always use default.
		if ( isset( $language_settings['detect_on_input'] ) && false === (bool) $language_settings['detect_on_input'] ) {
			return $default;
		}

		$stored = self::sanitize( $stored_language );

		// Fixed switching behavior never leaves established conversation language.
		$switching_behavior = (string) ( $language_settings['switching_behavior'] ?? 'auto_switch' );
		if ( 'fixed' === $switching_behavior && '' !== $stored && 'auto' !== $stored ) {
			return $stored;
		}

		// Priority 1: Explicit language request in latest message (e.g. "reply in German", "answer in English").
		$explicit = self::detect_explicit_language_request( $message );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		$detected_res  = self::detect( $message );
		$detected_lang = $detected_res['language'] ?? '';
		$confidence    = (float) ( $detected_res['confidence'] ?? 0.0 );

		// Auto fallback mode: fall back to configured fallback language if confidence is too low.
		if ( 'auto_fallback' === $mode ) {
			$fallback = self::sanitize( (string) ( $language_settings['fallback_language'] ?? $default ) ) ?: $default;
			if ( empty( $detected_lang ) || $confidence < 0.35 ) {
				return $fallback;
			}
			return $detected_lang;
		}

		// Sticky switching behavior: keep existing language unless high confidence switch.
		if ( 'sticky' === $switching_behavior && '' !== $stored && 'auto' !== $stored ) {
			if ( ! empty( $detected_lang ) && $confidence >= 0.55 && $detected_lang !== $stored ) {
				return $detected_lang;
			}
			return $stored;
		}

		// Normal auto mode:
		if ( ! empty( $detected_lang ) && $confidence >= 0.25 ) {
			return $detected_lang;
		}

		// If ambiguous or no confident detection, preserve stored language if available.
		if ( '' !== $stored && 'auto' !== $stored ) {
			return $stored;
		}

		return $default;
	}

	/**
	 * The language to carry forward after a turn.
	 *
	 * @param string $resolved  Language returned by resolve().
	 * @param string $message   Visitor message.
	 * @param string $default   Default language.
	 * @return string Supported language code.
	 */
	public static function persist_language( string $resolved, string $message, string $default = self::DEFAULT_LANGUAGE ): string {
		if ( 'auto' !== $resolved && '' !== $resolved ) {
			return self::sanitize( $resolved );
		}

		$detected = self::detect( $message );
		if ( ! empty( $detected['language'] ) && $detected['confidence'] >= 0.25 ) {
			return $detected['language'];
		}

		return $default;
	}

	/**
	 * Perform lightweight internal language validation on a generated response.
	 *
	 * @param string $reply Generated AI response.
	 * @param string $intended_language The language resolved for this turn.
	 * @return array{valid: bool, detected: string, reason: string}
	 */
	public static function validate_response( string $reply, string $intended_language ): array {
		$intended = self::sanitize( $intended_language );

		if ( '' === $intended || 'auto' === $intended ) {
			return array(
				'valid'    => true,
				'detected' => 'en',
				'reason'   => 'Auto intended',
			);
		}

		$trimmed = trim( $reply );
		if ( '' === $trimmed ) {
			return array(
				'valid'    => true,
				'detected' => $intended,
				'reason'   => 'Empty response',
			);
		}

		// When English is intended, verify the model didn't accidentally respond in German or another language.
		if ( 'en' === $intended ) {
			$det = self::detect( $trimmed );
			if ( 'de' === $det['language'] && $det['confidence'] >= 0.45 ) {
				return array(
					'valid'    => false,
					'detected' => 'de',
					'reason'   => 'Model responded in German when English was expected',
				);
			}

			// Check accidental German introductory phrases in English responses.
			$german_intros = array(
				'hier sind die informationen',
				'basierend auf der website',
				'leider habe ich keine',
				'wir bieten',
				'ich kann ihnen gerne',
				'vielen dank für',
				'unsere leistungen',
			);
			$lower_reply = mb_strtolower( $trimmed );
			foreach ( $german_intros as $g_intro ) {
				if ( str_starts_with( $lower_reply, $g_intro ) ) {
					return array(
						'valid'    => false,
						'detected' => 'de',
						'reason'   => 'Accidental German introductory phrase in English response',
					);
				}
			}

			return array(
				'valid'    => true,
				'detected' => 'en',
				'reason'   => 'English response validated',
			);
		}

		// For Devanagari languages (ne, hi): check Devanagari presence.
		if ( in_array( $intended, array( 'ne', 'hi' ), true ) ) {
			$devanagari_chars = 0;
			$total_letters    = 0;
			$len              = mb_strlen( $trimmed );

			for ( $i = 0; $i < $len; $i++ ) {
				$char = mb_substr( $trimmed, $i, 1 );
				$cp   = self::codepoint( $char );
				if ( self::in_range( $cp, array( array( 0x0900, 0x097F ) ) ) ) {
					$devanagari_chars++;
				}
				if ( preg_match( '/\p{L}/u', $char ) ) {
					$total_letters++;
				}
			}

			// If response is substantial (> 20 letters) and has almost no Devanagari (< 15%),
			// the model incorrectly fell back to English/Latin.
			if ( $total_letters > 20 && ( $devanagari_chars / $total_letters ) < 0.15 ) {
				return array(
					'valid'    => false,
					'detected' => 'en',
					'reason'   => 'Response is missing Devanagari script for ' . $intended,
				);
			}

			return array(
				'valid'    => true,
				'detected' => $intended,
				'reason'   => 'Devanagari verified',
			);
		}

		// For Latin-script languages (de, es, fr, it, pt):
		// Check for accidental English introductions that models sometimes inject.
		$english_intros = array(
			'here is the information',
			'based on the website',
			'i cannot find',
			"i couldn't find",
			'i do not have enough information',
			"i don't have enough information",
			'according to our records',
			'thank you for reaching out',
			'as an ai assistant',
		);
		$lower_reply = strtolower( $trimmed );
		foreach ( $english_intros as $intro ) {
			if ( str_starts_with( $lower_reply, $intro ) ) {
				return array(
					'valid'    => false,
					'detected' => 'en',
					'reason'   => 'Accidental English introductory phrase',
				);
			}
		}

		$detected = self::detect( $trimmed );

		if ( '' !== $detected['language'] && $detected['language'] !== $intended ) {
			$min_conf = ( 'en' === $detected['language'] ) ? 0.5 : 0.7;
			if ( $detected['confidence'] >= $min_conf ) {
				return array(
					'valid'    => false,
					'detected' => $detected['language'],
					'reason'   => 'Detected language mismatch',
				);
			}
		}

		return array(
			'valid'    => true,
			'detected' => $intended,
			'reason'   => 'Validated',
		);
	}

	/**
	 * Get the prompt to quickly re-translate/correct a reply into the target language.
	 *
	 * @param string $target_language ISO-639-1 code.
	 * @return string System prompt for translation correction.
	 */
	public static function get_translation_system_prompt( string $target_language ): string {
		$label = Settings::LANGUAGE_LABELS[ $target_language ] ?? $target_language;

		return "You are a professional native translator. Translate the text completely into {$label}. "
			. "Do NOT output English headings, English notes, or commentary. "
			. "Maintain natural, fluent native phrasing. Preserve technical terms, URLs, numbers, units, and markdown formatting. "
			. "Output ONLY the translated text.";
	}

	/**
	 * Count words in a message.
	 *
	 * @param string $text Input text.
	 * @return int Word count.
	 */
	protected static function word_count( string $text ): int {
		$trimmed = trim( $text );

		if ( '' === $trimmed ) {
			return 0;
		}

		return (int) count( preg_split( '/\s+/', $trimmed ) );
	}

	/**
	 * Determine dominant writing system of a text.
	 *
	 * @param string $text Input text.
	 * @return string 'latin', 'devanagari', 'cjk', 'cyrillic', 'arabic', 'greek', or 'other'.
	 */
	protected static function dominant_script( string $text ): string {
		$counts = array(
			'latin'      => 0,
			'devanagari' => 0,
			'cjk'        => 0,
			'cyrillic'   => 0,
			'arabic'     => 0,
			'greek'      => 0,
		);

		$length = mb_strlen( $text );

		for ( $i = 0; $i < $length; $i++ ) {
			$cp = self::codepoint( mb_substr( $text, $i, 1 ) );

			if ( self::in_range( $cp, array( array( 0x0900, 0x097F ) ) ) ) {
				$counts['devanagari']++;
			} elseif ( self::in_range( $cp, array( array( 0x0400, 0x04FF ) ) ) ) {
				$counts['cyrillic']++;
			} elseif ( self::in_range( $cp, array( array( 0x0600, 0x06FF ) ) ) ) {
				$counts['arabic']++;
			} elseif ( self::in_range( $cp, array( array( 0x0370, 0x03FF ) ) ) ) {
				$counts['greek']++;
			} elseif ( self::in_range( $cp, array( array( 0x4E00, 0x9FFF ), array( 0x3040, 0x30FF ), array( 0xAC00, 0xD7AF ) ) ) ) {
				$counts['cjk']++;
			} elseif ( self::in_range( $cp, array( array( 0x0041, 0x005A ), array( 0x0061, 0x007A ), array( 0x00C0, 0x024F ), array( 0x1E00, 0x1EFF ) ) ) ) {
				$counts['latin']++;
			}
		}

		arsort( $counts );
		$best = (string) key( $counts );

		if ( (int) $counts[ $best ] < 2 ) {
			return 'latin';
		}

		return $best;
	}

	/**
	 * Count distinct markers of one language in text.
	 *
	 * @param string $code Language code.
	 * @param string $normalized Lowercased, diacritic-folded text.
	 * @param bool   $strong Count strong markers only, or weak ones.
	 * @return int Match count.
	 */
	protected static function match_count( string $code, string $normalized, bool $strong ): int {
		$source = $strong ? ( self::$strong[ $code ] ?? array() ) : ( self::$weak[ $code ] ?? array() );

		if ( empty( $source ) ) {
			return 0;
		}

		$pattern = self::word_pattern( $source );

		if ( '' === $pattern ) {
			return 0;
		}

		if ( ! preg_match_all( $pattern, $normalized, $matches ) ) {
			return 0;
		}

		return count( array_unique( $matches[0] ) );
	}

	/**
	 * Build regex alternation for marker list.
	 *
	 * @param array $words Marker list.
	 * @return string Regex pattern.
	 */
	protected static function word_pattern( array $words ): string {
		$words = array_filter( array_map( 'trim', $words ), static fn( $w ): bool => '' !== $w );

		if ( empty( $words ) ) {
			return '';
		}

		usort( $words, static fn( $a, $b ): int => strlen( $b ) <=> strlen( $a ) );

		$escaped = array_map( 'preg_quote', $words );

		return '/(?<![\p{L}\p{N}])(' . implode( '|', $escaped ) . ')(?![\p{L}\p{N}])/u';
	}

	/**
	 * Lowercase and fold Latin diacritics.
	 *
	 * @param string $text Input text.
	 * @return string Normalized text.
	 */
	protected static function normalize( string $text ): string {
		$text = mb_strtolower( trim( $text ) );

		$fold = array(
			'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
			'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
			'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
			'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
			'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
			'ú' => 'u', 'ù' => 'u', 'û' => 'u',
			'ñ' => 'n', 'ç' => 'c', 'å' => 'a',
		);

		return strtr( $text, $fold );
	}

	/**
	 * Check if codepoint is in ranges.
	 *
	 * @param int   $cp Code point.
	 * @param array $ranges Range pairs.
	 * @return bool
	 */
	protected static function in_range( int $cp, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( $cp >= $range[0] && $cp <= $range[1] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read Unicode code point.
	 *
	 * @param string $char Single character.
	 * @return int Code point.
	 */
	protected static function codepoint( string $char ): int {
		$hex = bin2hex( mb_convert_encoding( $char, 'UTF-32BE', 'UTF-8' ) );

		return (int) hexdec( (string) $hex );
	}

	/**
	 * Keep language code within supported set.
	 *
	 * @param string $code Candidate code.
	 * @return string Supported code or empty string.
	 */
	public static function sanitize( string $code ): string {
		$code = strtolower( trim( $code ) );

		return in_array( $code, Settings::ALLOWED_LANGUAGES, true ) ? $code : '';
	}

	/**
	 * Return localized strict grounding (not found) reply.
	 *
	 * @param string $language     Language code.
	 * @param string $company_name Company name.
	 * @return string
	 */
	public static function get_not_found_reply( string $language, string $company_name ): string {
		$lang = self::sanitize( $language ) ?: 'en';

		switch ( $lang ) {
			case 'de':
				return sprintf(
					'Diese Information habe ich auf der Website von %s leider nicht gefunden, deshalb möchte ich nichts raten. Sie können sich gerne direkt an unser Team wenden.',
					$company_name
				);
			case 'es':
				return sprintf(
					'No encontré esa información en el sitio web de %s, así que no quiero inventarla. Puedes comunicarte directamente con nuestro equipo a continuación.',
					$company_name
				);
			case 'ne':
				return sprintf(
					'मैले %s को वेबसाइटमा यो जानकारी फेला पार्न सकिनँ, त्यसैले म अनुमान गर्न चाहन्नँ। तपाईं तलबाट सिधै हाम्रो टोलीसँग सम्पर्क गर्न सक्नुहुन्छ।',
					$company_name
				);
			case 'hi':
				return sprintf(
					'मुझे %s की वेबसाइट पर यह जानकारी नहीं मिली, इसलिए मैं कोई गलत अनुमान नहीं लगाना चाहता। आप नीचे दिए गए विकल्प से सीधे हमारी टीम से संपर्क कर सकते हैं।',
					$company_name
				);
			case 'fr':
				return sprintf(
					"Je n'ai pas trouvé cette information sur le site de %s, je préfère donc ne pas deviner. Vous pouvez contacter directement notre équipe ci-dessous.",
					$company_name
				);
			case 'it':
				return sprintf(
					'Non ho trovato questa informazione sul sito di %s, quindi preferisco non tirare a indovinare. Puoi contattare direttamente il nostro team qui sotto.',
					$company_name
				);
			case 'pt':
				return sprintf(
					'Não encontrei essa informação no site da %s, por isso prefiro não adivinhar. Você pode entrar em contato diretamente com nossa equipe abaixo.',
					$company_name
				);
			default:
				return sprintf(
					'I couldn\'t find that on the %s website, so I don\'t want to guess. You can reach out directly to our team below.',
					$company_name
				);
		}
	}

	/**
	 * Return localized guardrail decline message.
	 *
	 * @param string $language        Language code.
	 * @param string $company_name    Company name.
	 * @param int    $off_topic_count Current session off-topic count.
	 * @param string $contact_info    Customer service contact details or placeholder.
	 * @return string
	 */
	public static function get_decline_message( string $language, string $company_name, int $off_topic_count = 1, string $contact_info = '[CUSTOMER_SERVICE_CONTACT]' ): string {
		$lang = self::sanitize( $language ) ?: 'en';
		$contact = ! empty( $contact_info ) ? $contact_info : '[CUSTOMER_SERVICE_CONTACT]';

		if ( $off_topic_count >= 3 ) {
			switch ( $lang ) {
				case 'de':
					return sprintf( 'Ich kann Ihnen nur bei Fragen zu %s helfen. Wenn Sie weitere Unterstützung benötigen oder Fragen zu anderen Themen oder Unternehmen haben, wenden Sie sich bitte direkt an unseren Kundenservice: %s.', $company_name, $contact );
				case 'es':
					return sprintf( 'Solo puedo ayudarte con consultas sobre %s. Si necesitas otra asistencia o tienes preguntas sobre otras empresas, comunícate directamente con nuestro servicio al cliente: %s.', $company_name, $contact );
				case 'ne':
					return sprintf( 'म केवल %s सम्बन्धी सोधपुछमा मात्र मद्दत गर्न सक्छु। यदि तपाईंलाई अन्य सहयोग चाहिन्छ वा अन्य कम्पनीका बारेमा सोधपुछ गर्नुपर्ने भएमा, कृपया हाम्रो ग्राहक सेवामा सिधै सम्पर्क गर्नुहोस्: %s।', $company_name, $contact );
				case 'hi':
					return sprintf( 'मैं केवल %s से संबंधित प्रश्नों में ही आपकी सहायता कर सकता हूँ। यदि आपको अन्य सहायता चाहिए या अन्य कंपनियों से जुड़े प्रश्न हैं, तो कृपया हमारी ग्राहक सेवा से सीधे संपर्क करें: %s।', $company_name, $contact );
				case 'fr':
					return sprintf( "Je ne peux vous aider que pour les demandes concernant %s. Si vous avez besoin d'une autre assistance ou pour toute question sur d'autres entreprises, veuillez contacter directement notre service client : %s.", $company_name, $contact );
				case 'it':
					return sprintf( 'Posso aiutarti solo per richieste relative a %s. Se hai bisogno di altra assistenza o per informazioni su altre società, contatta direttamente il nostro servizio clienti: %s.', $company_name, $contact );
				case 'pt':
					return sprintf( 'Posso apenas ajudar com dúvidas relacionadas à %s. Se precisar de outro suporte ou tiver dúvidas sobre outras empresas, entre em contato diretamente com nosso atendimento ao cliente: %s.', $company_name, $contact );
				default:
					return sprintf( 'I am only able to assist with inquiries regarding %s. For questions about other companies or further assistance, please contact our customer service directly: %s.', $company_name, $contact );
			}
		}

		switch ( $lang ) {
			case 'de':
				return sprintf( 'Ich kann Ihnen nur bei Fragen zu %s helfen. Bei Fragen zu anderen Unternehmen, allgemeinen Themen oder für weitere Unterstützung wenden Sie sich bitte an unseren Kundenservice: %s.', $company_name, $contact );
			case 'es':
				return sprintf( 'Solo puedo ayudarte con preguntas sobre %s. Para consultas sobre otras empresas o más asistencia, por favor contacta a nuestro servicio de atención al cliente: %s.', $company_name, $contact );
			case 'ne':
				return sprintf( 'म केवल %s सम्बन्धी प्रश्नहरू, जस्तै हाम्रा सेवाहरू र सम्पर्क विवरणहरूमा मात्र मद्दत गर्न सक्छु। अन्य विषय वा थप सहयोगका लागि कृपया हाम्रो ग्राहक सेवामा सम्पर्क गर्नुहोस्: %s।', $company_name, $contact );
			case 'hi':
				return sprintf( 'मैं केवल %s से जुड़े प्रश्नों में सहायता कर सकता हूँ। अन्य संस्थाओं या अधिक सहायता के लिए कृपया हमारी ग्राहक सेवा से संपर्क करें: %s।', $company_name, $contact );
			case 'fr':
				return sprintf( 'Je ne peux répondre qu\'aux questions concernant %s. Pour toute demande sur d\'autres entités ou pour une assistance supplémentaire, veuillez contacter notre service client : %s.', $company_name, $contact );
			case 'it':
				return sprintf( 'Posso aiutarti solo con domande su %s. Per richieste relative ad altre aziende o per ulteriore assistenza, ti invitiamo a contattare il nostro servizio clienti: %s.', $company_name, $contact );
			case 'pt':
				return sprintf( 'Só posso ajudar com perguntas sobre a %s. Para dúvidas sobre outras empresas ou suporte adicional, entre em contato com nosso atendimento ao cliente: %s.', $company_name, $contact );
			default:
				return sprintf( 'I can only help with questions about %s. For inquiries about other companies or further assistance, please contact our customer service: %s.', $company_name, $contact );
		}
	}

	/**
	 * Return localized session throttled message.
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	public static function get_throttled_message( string $language ): string {
		$lang = self::sanitize( $language ) ?: 'en';

		switch ( $lang ) {
			case 'de':
				return 'Diese Sitzung wurde aufgrund wiederholter themenfremder Anfragen vorübergehend pausiert. Bitte versuchen Sie es später erneut oder wenden Sie sich an unser Team.';
			case 'es':
				return 'Esta sesión se ha pausado temporalmente debido a repetidas consultas fuera de tema. Inténtalo más tarde o contacta a nuestro equipo humano.';
			case 'ne':
				return 'बारम्बार विषय बाहिरका प्रश्नहरूका कारण यो सत्र अस्थायी रूपमा रोकिएको छ। कृपया केही समयपछि पुन: प्रयास गर्नुहोस् वा हाम्रो मानव टोलीसँग सम्पर्क गर्नुहोस्।';
			case 'hi':
				return 'बार-बार अप्रासंगिक प्रश्न पूछे जाने के कारण यह सत्र अस्थायी रूप से रोक दिया गया है। कृपया बाद में प्रयास करें या हमारी टीम से संपर्क करें।';
			case 'fr':
				return 'Cette session est temporairement suspendue en raison de demandes répétées hors sujet. Veuillez réessayer plus tard ou contacter notre équipe.';
			case 'it':
				return 'Questa sessione è temporaneamente sospesa a causa di ripetute richieste fuori tema. Riprova più tardi o contatta il nostro team umano.';
			case 'pt':
				return 'Esta sessão foi temporariamente pausada devido a repetidas perguntas fora do tema. Tente novamente mais tarde ou contate nossa equipe humana.';
			default:
				return 'This session is temporarily paused due to repeated off-topic inquiries. Please try again later or contact our human team.';
		}
	}

	/**
	 * Return localized provider error messages.
	 *
	 * @param string $type     'no_keys_available', 'rate_limited', 'auth_error', or 'generic'.
	 * @param string $language Language code.
	 * @return string
	 */
	public static function get_error_message( string $type, string $language ): string {
		$lang = self::sanitize( $language ) ?: 'en';

		$messages = array(
			'no_keys_available' => array(
				'en' => "I'm unable to connect to an assistant right now. You can email our team directly below and we'll help you promptly.",
				'de' => 'Ich kann derzeit keine Verbindung zu einem Assistenten herstellen. Sie können unser Team gerne direkt per E-Mail kontaktieren.',
				'es' => 'No puedo conectarme con un asistente en este momento. Puedes enviar un correo a nuestro equipo directamente a continuación.',
				'ne' => 'म यस समयमा सहायकसँग सम्पर्क गर्न असमर्थ छु। तपाईं तलबाट सिधै हाम्रो टोलीलाई इमेल गर्न सक्नुहुन्छ।',
				'hi' => 'मैं इस समय किसी सहायक से कनेक्ट नहीं हो पा रहा हूँ। आप नीचे दिए गए विकल्प से सीधे हमारी टीम को ईमेल कर सकते हैं।',
				'fr' => "Je ne peux pas me connecter à un assistant pour le moment. Vous pouvez envoyer un e-mail directement à notre équipe ci-dessous.",
				'it' => 'Al momento non riesco a collegarmi a un assistente. Puoi inviare un\'e-mail direttamente al nostro team qui sotto.',
				'pt' => 'Não consigo me conectar a um assistente no momento. Você pode enviar um e-mail diretamente para nossa equipe abaixo.',
			),
			'rate_limited' => array(
				'en' => "I'm temporarily unable to answer. Please try again in a moment, or reach out to our team directly below.",
				'de' => 'Ich kann vorübergehend nicht antworten. Bitte versuchen Sie es gleich noch einmal oder kontaktieren Sie direkt unser Team.',
				'es' => 'Temporalmente no puedo responder. Inténtalo de nuevo en un momento o comunícate con nuestro equipo a continuación.',
				'ne' => 'म अस्थायी रूपमा जवाफ दिन असमर्थ छु। कृपया केही क्षणपछि पुन: प्रयास गर्नुहोस् वा तलबाट हाम्रो टोलीसँग सम्पर्क गर्नुहोस्।',
				'hi' => 'मैं अस्थायी रूप से उत्तर देने में असमर्थ हूँ। कृपया कुछ क्षण बाद पुन: प्रयास करें या नीचे से हमारी टीम से संपर्क करें।',
				'fr' => "Je ne peux temporairement pas répondre. Veuillez réessayer dans un instant ou contacter notre équipe ci-dessous.",
				'it' => 'Al momento non posso rispondere. Riprova tra poco o contatta direttamente il nostro team qui sotto.',
				'pt' => 'Estou temporariamente impossibilitado de responder. Tente novamente em instantes ou fale com nossa equipe abaixo.',
			),
			'auth_error' => array(
				'en' => "My connection to the AI service needs attention. You can email our team directly below and we'll help you promptly.",
				'de' => 'Die Verbindung zum KI-Dienst benötigt Aufmerksamkeit. Sie können sich gerne direkt per E-Mail an unser Team wenden.',
				'es' => 'La conexión con el servicio de IA necesita atención. Puedes comunicarte directamente con nuestro equipo a continuación.',
				'ne' => 'एआई सेवासँगको सम्पर्कमा समस्या आएको छ। तपाईं तलबाट सिधै हाम्रो टोलीलाई सम्पर्क गर्न सक्नुहुन्छ।',
				'hi' => 'एआई सेवा से कनेक्शन में समस्या है। आप नीचे दिए गए विकल्प से सीधे हमारी टीम से संपर्क कर सकते हैं।',
				'fr' => "La connexion au service IA nécessite une attention particulière. Vous pouvez contacter directement notre équipe ci-dessous.",
				'it' => 'La connessione al servizio IA richiede attenzione. Puoi contattare direttamente il nostro team qui sotto.',
				'pt' => 'A conexão com o serviço de IA precisa de atenção. Você pode entrar em contato diretamente com nossa equipe abaixo.',
			),
			'generic' => array(
				'en' => "I'm sorry, I couldn't process that just now. You can email our team directly below and we'll help you promptly.",
				'de' => 'Entschuldigung, das konnte ich gerade nicht verarbeiten. Sie können sich gerne direkt per E-Mail an unser Team wenden.',
				'es' => 'Lo siento, no pude procesar eso ahora mismo. Puedes comunicarte con nuestro equipo a continuación.',
				'ne' => 'माफ गर्नुहोस्, मैले अहिले यसलाई प्रशोधन गर्न सकिनँ। तपाईं तलबाट सिधै हाम्रो टोलीलाई सम्पर्क गर्न सक्नुहुन्छ।',
				'hi' => 'क्षमा करें, मैं इसे अभी प्रोसेस नहीं कर सका। आप नीचे से सीधे हमारी टीम से संपर्क कर सकते हैं।',
				'fr' => "Désolé, je n'ai pas pu traiter cette demande pour le moment. Vous pouvez contacter notre équipe directement ci-dessous.",
				'it' => 'Mi dispiace, non sono riuscito a elaborare la richiesta in questo momento. Puoi contattare il nostro team qui sotto.',
				'pt' => 'Desculpe, não consegui processar isso agora. Você pode entrar em contato com nossa equipe abaixo.',
			),
		);

		return $messages[ $type ][ $lang ] ?? $messages['generic'][ $lang ] ?? $messages['generic']['en'];
	}

	/**
	 * Return localized default greeting.
	 *
	 * @param string $language     Language code.
	 * @param string $company_name Company name.
	 * @param string $agent_name   Agent name.
	 * @return string
	 */
	public static function get_default_greeting( string $language, string $company_name, string $agent_name = 'AI Assistant' ): string {
		$lang = self::sanitize( $language ) ?: 'en';

		switch ( $lang ) {
			case 'de':
				return sprintf( 'Hallo! 👋 Willkommen bei %s. Wie kann ich Ihnen heute helfen?', $company_name );
			case 'es':
				return sprintf( '¡Hola! 👋 Te damos la bienvenida a %s. ¿Cómo puedo ayudarte hoy?', $company_name );
			case 'ne':
				return sprintf( 'नमस्ते! 👋 %s मा स्वागत छ। म तपाईंलाई आज कसरी मद्दत गर्न सक्छु?', $company_name );
			case 'hi':
				return sprintf( 'नमस्ते! 👋 %s में आपका स्वागत है। आज मैं आपकी क्या मदद कर सकता हूँ?', $company_name );
			case 'fr':
				return sprintf( 'Bonjour ! 👋 Bienvenue chez %s. Comment puis-je vous aider aujourd\'hui ?', $company_name );
			case 'it':
				return sprintf( 'Ciao! 👋 Benvenuto in %s. Come posso aiutarti oggi?', $company_name );
			case 'pt':
				return sprintf( 'Olá! 👋 Boas-vindas à %s. Como posso ajudar você hoje?', $company_name );
			default:
				return sprintf( 'Hello! 👋 Welcome to %s. How can I help you today?', $company_name );
		}
	}

	/**
	 * Return localized standard starter questions.
	 *
	 * @param string $language     Language code.
	 * @param string $company_name Company name.
	 * @return array List of question items.
	 */
	public static function get_starter_questions( string $language, string $company_name = '' ): array {
		$lang = self::sanitize( $language ) ?: 'en';

		$questions_map = array(
			'en' => array(
				array( 'id' => 'q1', 'text' => 'What services do you provide?', 'icon' => 'chat' ),
				array( 'id' => 'q2', 'text' => 'How much do your services cost?', 'icon' => 'sparkles' ),
				array( 'id' => 'q3', 'text' => 'What are your business hours?', 'icon' => 'clock' ),
				array( 'id' => 'q4', 'text' => 'How can I contact your team?', 'icon' => 'user' ),
			),
			'de' => array(
				array( 'id' => 'q1', 'text' => 'Welche Dienstleistungen bieten Sie an?', 'icon' => 'chat' ),
				array( 'id' => 'q2', 'text' => 'Was kosten Ihre Dienstleistungen?', 'icon' => 'sparkles' ),
				array( 'id' => 'q3', 'text' => 'Wie sind Ihre Geschäftszeiten?', 'icon' => 'clock' ),
				array( 'id' => 'q4', 'text' => 'Wie kann ich Ihr Team kontaktieren?', 'icon' => 'user' ),
			),
			'es' => array(
				array( 'id' => 'q1', 'text' => '¿Qué servicios ofrecen?', 'icon' => 'chat' ),
				array( 'id' => 'q2', 'text' => '¿Cuánto cuestan sus servicios?', 'icon' => 'sparkles' ),
				array( 'id' => 'q3', 'text' => '¿Cuál es su horario de atención?', 'icon' => 'clock' ),
				array( 'id' => 'q4', 'text' => '¿Cómo puedo contactar a su equipo?', 'icon' => 'user' ),
			),
			'ne' => array(
				array( 'id' => 'q1', 'text' => 'तपाईंहरूले के-कस्ता सेवाहरू प्रदान गर्नुहुन्छ?', 'icon' => 'chat' ),
				array( 'id' => 'q2', 'text' => 'तपाईंको सेवाहरूको शुल्क कति पर्छ?', 'icon' => 'sparkles' ),
				array( 'id' => 'q3', 'text' => 'तपाईंको कार्यालय समय के हो?', 'icon' => 'clock' ),
				array( 'id' => 'q4', 'text' => 'तपाईंको टोलीसँग कसरी सम्पर्क गर्न सकिन्छ?', 'icon' => 'user' ),
			),
			'hi' => array(
				array( 'id' => 'q1', 'text' => 'आप कौन-सी सेवाएं प्रदान करते हैं?', 'icon' => 'chat' ),
				array( 'id' => 'q2', 'text' => 'आपकी सेवाओं की लागत कितनी है?', 'icon' => 'sparkles' ),
				array( 'id' => 'q3', 'text' => 'आपके काम करने का समय क्या है?', 'icon' => 'clock' ),
				array( 'id' => 'q4', 'text' => 'मैं आपकी टीम से कैसे संपर्क कर सकता हूँ?', 'icon' => 'user' ),
			),
			'fr' => array(
				array( 'id' => 'q1', 'text' => 'Quels services proposez-vous ?', 'icon' => 'chat' ),
				array( 'id' => 'q2', 'text' => 'Combien coûtent vos services ?', 'icon' => 'sparkles' ),
				array( 'id' => 'q3', 'text' => 'Quels sont vos horaires d\'ouverture ?', 'icon' => 'clock' ),
				array( 'id' => 'q4', 'text' => 'Comment puis-je contacter votre équipe ?', 'icon' => 'user' ),
			),
			'it' => array(
				array( 'id' => 'q1', 'text' => 'Quali servizi offrite?', 'icon' => 'chat' ),
				array( 'id' => 'q2', 'text' => 'Quanto costano i vostri servizi?', 'icon' => 'sparkles' ),
				array( 'id' => 'q3', 'text' => 'Quali sono i vostri orari di apertura?', 'icon' => 'clock' ),
				array( 'id' => 'q4', 'text' => 'Come posso contattare il vostro team?', 'icon' => 'user' ),
			),
			'pt' => array(
				array( 'id' => 'q1', 'text' => 'Quais serviços vocês oferecem?', 'icon' => 'chat' ),
				array( 'id' => 'q2', 'text' => 'Quanto custam os seus serviços?', 'icon' => 'sparkles' ),
				array( 'id' => 'q3', 'text' => 'Qual é o horário de funcionamento?', 'icon' => 'clock' ),
				array( 'id' => 'q4', 'text' => 'Como posso entrar em contato com a equipe?', 'icon' => 'user' ),
			),
		);

		return $questions_map[ $lang ] ?? $questions_map['en'];
	}

	/**
	 * Localize suggested questions to match the active conversation language.
	 *
	 * Language fallback hierarchy:
	 *   target language -> default language -> built-in defaults ('en').
	 *
	 * @param array  $questions        Questions array from database or configuration.
	 * @param string $language         Target language code.
	 * @param string $company_name     Company name for placeholders.
	 * @param string $default_language Default configured language for agent.
	 * @return array Localized questions.
	 */
	public static function localize_suggested_questions( array $questions, string $language, string $company_name = '', string $default_language = 'en' ): array {
		$lang = self::sanitize( $language ) ?: 'en';
		$def  = self::sanitize( $default_language ) ?: 'en';

		// Extract any valid, non-empty questions from input, preserving all fields.
		$valid_questions = array();
		foreach ( $questions as $index => $q ) {
			if ( is_array( $q ) && ! empty( $q['text'] ) ) {
				$valid_questions[] = array(
					'id'         => ! empty( $q['id'] ) ? (string) $q['id'] : 'q_' . ( $index + 1 ),
					'text'       => trim( (string) $q['text'] ),
					'icon'       => (string) ( $q['icon'] ?? 'chat' ),
					'enabled'    => isset( $q['enabled'] ) ? (bool) $q['enabled'] : true,
					'sort_order' => isset( $q['sort_order'] ) ? (int) $q['sort_order'] : $index,
				);
			}
		}

		// If configured questions exist, return them.
		if ( ! empty( $valid_questions ) ) {
			return $valid_questions;
		}

		// If no questions exist, fall back: target language -> default language -> 'en'
		$starter = self::get_starter_questions( $lang, $company_name );
		if ( empty( $starter ) && $lang !== $def ) {
			$starter = self::get_starter_questions( $def, $company_name );
		}
		if ( empty( $starter ) ) {
			$starter = self::get_starter_questions( 'en', $company_name );
		}
		return $starter;
	}

	/**
	 * Return UI translations dictionary for the frontend widget.
	 *
	 * @param string $language ISO-639-1 code.
	 * @return array Key-value UI strings.
	 */
	public static function get_ui_strings( string $language ): array {
		$lang = self::sanitize( $language ) ?: 'en';
		$all  = self::get_all_ui_strings();

		return array_merge( $all['en'], $all[ $lang ] ?? array() );
	}

	/**
	 * Return UI translations for all supported languages.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_all_ui_strings(): array {
		return array(
			'en' => array(
				'new_chat_label'    => 'New Chat',
				'input_placeholder' => 'Type your message...',
				'send_label'        => 'Send message',
				'stop_label'        => 'Stop generating',
				'status_online'     => 'Online',
				'status_offline'    => 'Offline',
				'status_away'       => 'Away',
				'feedback_question' => 'Was this helpful?',
				'feedback_thanks'   => 'Thanks!',
				'contact_offer'     => 'Would you like to connect with a member of our human team?',
				'contact_button'    => 'Contact a Human',
				'contact_title'     => 'Contact a Human',
				'contact_name'      => 'Your Name *',
				'contact_email'     => 'Your Email *',
				'contact_phone'     => 'Phone Number',
				'contact_company'   => 'Company',
				'contact_message'   => 'Message *',
				'contact_cancel'    => 'Cancel',
				'contact_send'      => 'Send Request',
				'contact_sending'   => 'Sending...',
				'contact_success'   => 'Thanks! Your request has been received.',
				'contact_error'     => 'Failed to send contact request. Please try again.',
				'response_stopped'  => 'Response stopped.',
				'server_error'      => 'Server error. Please try again.',
				'connection_error'  => 'Unable to reach the server. Please check your connection.',
				'edit_label'        => 'Edit message',
				'edit_hint'         => 'Enter to send, Shift + Enter for a new line, Escape to cancel.',
				'edit_cancel'       => 'Cancel',
				'edit_send'         => 'Send',
				'edit_error'        => 'Sorry, the message could not be edited. Please try again.',
				'handoff_title'        => 'Need more help?',
				'handoff_desc'         => 'Our team is here to help. Feel free to reach out to us directly.',
				'handoff_email_btn'    => '✉ Email Us',
				'handoff_continue_btn' => 'Continue Chatting',
				'handoff_copied'       => '✓ Email Copied',
				'handoff_subject'      => 'AI Chatbot Support Request',
			),
			'de' => array(
				'new_chat_label'    => 'Neuer Chat',
				'input_placeholder' => 'Nachricht eingeben...',
				'send_label'        => 'Nachricht senden',
				'stop_label'        => 'Generierung stoppen',
				'status_online'     => 'Online',
				'status_offline'    => 'Offline',
				'status_away'       => 'Abwesend',
				'feedback_question' => 'War das hilfreich?',
				'feedback_thanks'   => 'Vielen Dank!',
				'contact_offer'     => 'Möchten Sie sich mit einem Mitarbeiter unseres Teams verbinden?',
				'contact_button'    => 'Mit einem Menschen sprechen',
				'contact_title'     => 'Mit einem Menschen sprechen',
				'contact_name'      => 'Ihr Name *',
				'contact_email'     => 'Ihre E-Mail-Adresse *',
				'handoff_title'        => 'Brauchen Sie weitere Hilfe?',
				'handoff_desc'         => 'Unser Team ist gerne für Sie da. Wenden Sie sich jederzeit direkt an uns.',
				'handoff_email_btn'    => '✉ E-Mail senden',
				'handoff_continue_btn' => 'Weiter chatten',
				'handoff_copied'       => '✓ E-Mail kopiert',
				'handoff_subject'      => 'AI Chatbot Support-Anfrage',
				'contact_phone'     => 'Telefonnummer',
				'contact_company'   => 'Unternehmen',
				'contact_message'   => 'Nachricht *',
				'contact_cancel'    => 'Abbrechen',
				'contact_send'      => 'Anfrage senden',
				'contact_sending'   => 'Wird gesendet...',
				'contact_success'   => 'Vielen Dank! Ihre Anfrage ist eingegangen.',
				'contact_error'     => 'Anfrage konnte nicht gesendet werden. Bitte versuchen Sie es erneut.',
				'response_stopped'  => 'Antwort abgebrochen.',
				'server_error'      => 'Serverfehler. Bitte versuchen Sie es erneut.',
				'connection_error'  => 'Server nicht erreichbar. Bitte überprüfen Sie Ihre Internetverbindung.',
				'edit_label'        => 'Nachricht bearbeiten',
				'edit_hint'         => 'Enter zum Senden, Shift + Enter für neue Zeile, Esc zum Abbrechen.',
				'edit_cancel'       => 'Abbrechen',
				'edit_send'         => 'Senden',
				'edit_error'        => 'Nachricht konnte nicht bearbeitet werden. Bitte erneut versuchen.',
			),
			'es' => array(
				'new_chat_label'    => 'Nueva conversación',
				'input_placeholder' => 'Escribe tu mensaje...',
				'send_label'        => 'Enviar mensaje',
				'stop_label'        => 'Detener generación',
				'status_online'     => 'En línea',
				'status_offline'    => 'Desconectado',
				'status_away'       => 'Ausente',
				'feedback_question' => '¿Te resultó útil?',
				'feedback_thanks'   => '¡Gracias!',
				'contact_offer'     => '¿Te gustaría conectar con un miembro de nuestro equipo?',
				'contact_button'    => 'Hablar con una persona',
				'contact_title'     => 'Hablar con una persona',
				'contact_name'      => 'Tu nombre *',
				'contact_email'     => 'Tu correo *',
				'handoff_title'        => '¿Necesitas ayuda personalizada?',
				'handoff_desc'         => 'Nuestro equipo está listo para ayudarte directamente con asesoramiento personalizado y soporte.',
				'handoff_email_btn'    => '✉ Enviar correo',
				'handoff_continue_btn' => 'Continuar chateando',
				'handoff_subject'      => 'Consulta de soporte de AI Chatbot',
				'contact_phone'     => 'Teléfono',
				'contact_company'   => 'Empresa',
				'contact_message'   => 'Mensaje *',
				'contact_cancel'    => 'Cancelar',
				'contact_send'      => 'Enviar solicitud',
				'contact_sending'   => 'Enviando...',
				'contact_success'   => '¡Gracias! Hemos recibido tu solicitud.',
				'contact_error'     => 'No se pudo enviar la solicitud. Por favor, inténtalo de nuevo.',
				'response_stopped'  => 'Respuesta detenida.',
				'server_error'      => 'Error del servidor. Por favor, inténtalo de nuevo.',
				'connection_error'  => 'No se puede conectar con el servidor. Comprueba tu conexión.',
				'edit_label'        => 'Editar mensaje',
				'edit_hint'         => 'Enter para enviar, Shift + Enter para nueva línea, Esc para cancelar.',
				'edit_cancel'       => 'Cancelar',
				'edit_send'         => 'Enviar',
				'edit_error'        => 'No se pudo editar el mensaje. Inténtalo de nuevo.',
			),
			'ne' => array(
				'new_chat_label'    => 'नयाँ कुराकानी',
				'input_placeholder' => 'तपाईंको सन्देश लेख्नुहोस्...',
				'send_label'        => 'सन्देश पठाउनुहोस्',
				'stop_label'        => 'रोक्नुहोस्',
				'status_online'     => 'अनलाइन',
				'status_offline'    => 'अफलाइन',
				'status_away'       => 'अनुपलब्ध',
				'feedback_question' => 'के यो उपयोगी थियो?',
				'feedback_thanks'   => 'धन्यवाद!',
				'contact_offer'     => 'के तपाईं हाम्रो मानव टोलीका सदस्यसँग कुरा गर्न चाहनुहुन्छ?',
				'contact_button'    => 'मानव टोलीसँग सम्पर्क गर्नुहोस्',
				'contact_title'     => 'मानव टोलीसँग सम्पर्क गर्नुहोस्',
				'contact_name'      => 'तपाईंको नाम *',
				'contact_email'     => 'तपाईंको इमेल *',
				'handoff_title'        => 'थप व्यक्तिगत सहायता चाहिन्छ?',
				'handoff_desc'         => 'हाम्रो टोली तपाईंलाई प्रत्यक्ष व्यक्तिगत सल्लाह र सहायता प्रदान गर्न तयार छ।',
				'handoff_email_btn'    => '✉ इमेल पठाउनुहोस्',
				'handoff_continue_btn' => 'कुराकानी जारी राख्नुहोस्',
				'handoff_subject'      => 'एआई च्याटबोट सहायता अनुरोध',
				'contact_phone'     => 'फोन नम्बर',
				'contact_company'   => 'कम्पनी',
				'contact_message'   => 'सन्देश *',
				'contact_cancel'    => 'रद्द गर्नुहोस्',
				'contact_send'      => 'अनुरोध पठाउनुहोस्',
				'contact_sending'   => 'पठाउँदै...',
				'contact_success'   => 'धन्यवाद! तपाईंको अनुरोध प्राप्त भयो।',
				'contact_error'     => 'अनुरोध पठाउन असफल भयो। कृपया पुन: प्रयास गर्नुहोस्।',
				'response_stopped'  => 'प्रतिक्रिया रोकियो।',
				'server_error'      => 'सर्भर त्रुटि। कृपया पुन: प्रयास गर्नुहोस्।',
				'connection_error'  => 'सर्भरमा सम्पर्क हुन सकेन। कृपया आफ्नो इन्टरनेट जाँच्नुहोस्।',
				'edit_label'        => 'सन्देश सम्पादन गर्नुहोस्',
				'edit_hint'         => 'पठाउन Enter, नयाँ हरफका लागि Shift + Enter, रद्द गर्न Esc।',
				'edit_cancel'       => 'रद्द गर्नुहोस्',
				'edit_send'         => 'पठाउनुहोस्',
				'edit_error'        => 'माफ गर्नुहोस्, सन्देश सम्पादन हुन सकेन।',
			),
			'hi' => array(
				'new_chat_label'    => 'नई बातचीत',
				'input_placeholder' => 'अपना संदेश लिखें...',
				'send_label'        => 'संदेश भेजें',
				'stop_label'        => 'रोकें',
				'status_online'     => 'ऑनलाइन',
				'status_offline'    => 'ऑफ़लाइन',
				'status_away'       => 'अनुपलब्ध',
				'feedback_question' => 'क्या यह मददगार था?',
				'feedback_thanks'   => 'धन्यवाद!',
				'contact_offer'     => 'क्या आप हमारी टीम के किसी सदस्य से संपर्क करना चाहते हैं?',
				'contact_button'    => 'मानव प्रतिनिधि से बात करें',
				'contact_title'     => 'मानव प्रतिनिधि से बात करें',
				'contact_name'      => 'आपका नाम *',
				'contact_email'     => 'आपका ईमेल *',
				'handoff_title'        => 'क्या आपको व्यक्तिगत सहायता चाहिए?',
				'handoff_desc'         => 'हमारी टीम आपको व्यक्तिगत सलाह और सहायता प्रदान करने के लिए सीधे उपलब्ध है।',
				'handoff_email_btn'    => '✉ ईमेल भेजें',
				'handoff_continue_btn' => 'बातचीत जारी रखें',
				'handoff_subject'      => 'एआई चैटबॉट सहायता अनुरोध',
				'contact_phone'     => 'फ़ोन नंबर',
				'contact_company'   => 'कंपनी',
				'contact_message'   => 'संदेश *',
				'contact_cancel'    => 'रद्द करें',
				'contact_send'      => 'अनुरोध भेजें',
				'contact_sending'   => 'भेजा जा रहा है...',
				'contact_success'   => 'धन्यवाद! आपका अनुरोध प्राप्त हो गया है।',
				'contact_error'     => 'अनुरोध भेजने में विफल। कृपया पुनः प्रयास करें।',
				'response_stopped'  => 'प्रतिक्रिया रोकी गई।',
				'server_error'      => 'सर्वर त्रुटि। कृपया पुनः प्रयास करें।',
				'connection_error'  => 'सर्वर से कनेक्ट नहीं हो सका। कृपया अपना कनेक्शन जांचें।',
				'edit_label'        => 'संदेश संपादित करें',
				'edit_hint'         => 'भेजने के लिए Enter, नई पंक्ति के लिए Shift + Enter, रद्द करने के लिए Esc दबाएं।',
				'edit_cancel'       => 'रद्द करें',
				'edit_send'         => 'भेजें',
				'edit_error'        => 'क्षमा करें, संदेश संपादित नहीं किया जा सका।',
			),
			'fr' => array(
				'new_chat_label'    => 'Nouvelle discussion',
				'input_placeholder' => 'Écrivez votre message...',
				'send_label'        => 'Envoyer le message',
				'stop_label'        => 'Arrêter la génération',
				'status_online'     => 'En ligne',
				'status_offline'    => 'Hors ligne',
				'status_away'       => 'Absent',
				'feedback_question' => 'Était-ce utile ?',
				'feedback_thanks'   => 'Merci !',
				'contact_offer'     => 'Souhaitez-vous échanger avec un membre de notre équipe ?',
				'contact_button'    => 'Contacter un humain',
				'contact_title'     => 'Contacter un humain',
				'contact_name'      => 'Votre nom *',
				'contact_email'     => 'Votre e-mail *',
				'handoff_title'        => 'Besoin d\'une assistance personnalisée ?',
				'handoff_desc'         => 'Notre équipe est à votre disposition pour vous conseiller et vous accompagner directement.',
				'handoff_email_btn'    => '✉ Nous écrire',
				'handoff_continue_btn' => 'Continuer à discuter',
				'handoff_subject'      => 'Demande d\'assistance AI Chatbot',
				'contact_phone'     => 'Numéro de téléphone',
				'contact_company'   => 'Entreprise',
				'contact_message'   => 'Message *',
				'contact_cancel'    => 'Annuler',
				'contact_send'      => 'Envoyer la demande',
				'contact_sending'   => 'Envoi en cours...',
				'contact_success'   => 'Merci ! Votre demande a bien été reçue.',
				'contact_error'     => 'Échec de l\'envoi de la demande. Veuillez réessayer.',
				'response_stopped'  => 'Réponse arrêtée.',
				'server_error'      => 'Erreur du serveur. Veuillez réessayer.',
				'connection_error'  => 'Impossible de joindre le serveur. Vérifiez votre connexion.',
				'edit_label'        => 'Modifier le message',
				'edit_hint'         => 'Entrée pour envoyer, Maj + Entrée pour un saut de ligne, Échap pour annuler.',
				'edit_cancel'       => 'Annuler',
				'edit_send'         => 'Envoyer',
				'edit_error'        => 'Impossible de modifier le message. Veuillez réessayer.',
			),
			'it' => array(
				'new_chat_label'    => 'Nuova chat',
				'input_placeholder' => 'Scrivi un messaggio...',
				'send_label'        => 'Invia messaggio',
				'stop_label'        => 'Interrompi',
				'status_online'     => 'Online',
				'status_offline'    => 'Non in linea',
				'status_away'       => 'Assente',
				'feedback_question' => 'È stato utile?',
				'feedback_thanks'   => 'Grazie!',
				'contact_offer'     => 'Vuoi metterti in contatto con un membro del nostro team?',
				'contact_button'    => 'Parla con un operatore',
				'contact_title'     => 'Parla con un operatore',
				'contact_name'      => 'Il tuo nome *',
				'contact_email'     => 'La tua email *',
				'handoff_title'        => 'Desideri assistenza personalizzata?',
				'handoff_desc'         => 'Il nostro team è a tua disposizione per offrirti consulenza e supporto personalizzato.',
				'handoff_email_btn'    => '✉ Invia un\'email',
				'handoff_continue_btn' => 'Continua a chattare',
				'handoff_subject'      => 'Richiesta di supporto AI Chatbot',
				'contact_phone'     => 'Numero di telefono',
				'contact_company'   => 'Azienda',
				'contact_message'   => 'Messaggio *',
				'contact_cancel'    => 'Annulla',
				'contact_send'      => 'Invia richiesta',
				'contact_sending'   => 'Invio in corso...',
				'contact_success'   => 'Grazie! La tua richiesta è stata ricevuta.',
				'contact_error'     => 'Invio della richiesta non riuscito. Riprova.',
				'response_stopped'  => 'Risposta interrotta.',
				'server_error'      => 'Errore del server. Riprova.',
				'connection_error'  => 'Impossibile raggiungere il server. Verifica la tua connessione.',
				'edit_label'        => 'Modifica messaggio',
				'edit_hint'         => 'Invio per inviare, Maiusc + Invio per nuova riga, Esc per annullare.',
				'edit_cancel'       => 'Annulla',
				'edit_send'         => 'Invia',
				'edit_error'        => 'Impossibile modificare il messaggio. Riprova.',
			),
			'pt' => array(
				'new_chat_label'    => 'Nova conversa',
				'input_placeholder' => 'Digite sua mensagem...',
				'send_label'        => 'Enviar mensagem',
				'stop_label'        => 'Parar geração',
				'status_online'     => 'Online',
				'status_offline'    => 'Offline',
				'status_away'       => 'Ausente',
				'feedback_question' => 'Isso foi útil?',
				'feedback_thanks'   => 'Obrigado!',
				'contact_offer'     => 'Gostaria de falar com um membro da nossa equipe?',
				'contact_button'    => 'Falar com um humano',
				'contact_title'     => 'Falar com um humano',
				'contact_name'      => 'Seu nome *',
				'contact_email'     => 'Seu e-mail *',
				'handoff_title'        => 'Procura atendimento personalizado?',
				'handoff_desc'         => 'Nossa equipe está à disposição para ajudar você diretamente com suporte personalizado.',
				'handoff_email_btn'    => '✉ Enviar e-mail',
				'handoff_continue_btn' => 'Continuar conversando',
				'handoff_subject'      => 'Solicitação de suporte AI Chatbot',
				'contact_phone'     => 'Telefone',
				'contact_company'   => 'Empresa',
				'contact_message'   => 'Mensagem *',
				'contact_cancel'    => 'Cancelar',
				'contact_send'      => 'Enviar solicitação',
				'contact_sending'   => 'Enviando...',
				'contact_success'   => 'Obrigado! Sua solicitação foi recebida.',
				'contact_error'     => 'Falha ao enviar a solicitação. Tente novamente.',
				'response_stopped'  => 'Resposta interrompida.',
				'server_error'      => 'Erro no servidor. Tente novamente.',
				'connection_error'  => 'Não foi possível conectar ao servidor. Verifique sua conexão.',
				'edit_label'        => 'Editar mensagem',
				'edit_hint'         => 'Enter para enviar, Shift + Enter para nova linha, Esc para cancelar.',
				'edit_cancel'       => 'Cancelar',
				'edit_send'         => 'Enviar',
				'edit_error'        => 'Não foi possível editar a mensagem. Tente novamente.',
			),
		);
	}
}
