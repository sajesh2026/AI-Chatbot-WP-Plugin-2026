# Changelog

All notable changes to the "AI Chatbot" plugin are documented in this file.

## [1.3.5] - 2026-11-16

### Added & Enhanced
- **Unlimited Conversation Support & Sliding Window Memory:**
  - Guaranteed continuous, uninhibited conversations without artificial session or IP message limits.
  - Implemented automatic sliding context window retaining system prompt + recent message turns to strictly prevent token overflow and TPM spikes.
  - Added resilient exponential retry backoff (up to 2 retries on HTTP 429 / 503) and detailed API error logging to `error_log()` for transparent debugging.
- **Dynamic Scoping & Theme UI Compatibility:**
  - Ensured non-hardcoded page visibility and guardrail scoping via configurable Admin Settings (`visibility.mode`, `visibility.page_ids`, `guardrails.allowed_topics`, `guardrails.never_discuss`).
  - Hardened floating widget z-index stacking (`z-index: 999990`) to prevent collisions with third-party floating buttons (WhatsApp, phone icons) and header CTAs across mobile and desktop.
  - Preserved native theme navigation links, telephone click-to-call actions, and contact routing.

## [1.3.4] - 2026-11-06

### Refactored & Enhanced
- **White-Label & Decoupled Architecture:**
  - Decoupled all prompts, starter questions, topic guardrails, and knowledge retrieval logic from industry-specific implementations into a generic, white-label architecture.
  - Neutralized default conversation starter questions across all 8 supported languages to universal service, pricing, hours, and contact inquiries.
  - Converted competitor blocking and hardware/partner validation into dynamic settings configurable per website persona.
  - Universalized cross-lingual retrieval dictionary to standard business concepts.
  - Decoupled URL registry and brand title cleaner to dynamically resolve from active site configuration.

## [1.3.3] - 2026-11-05

### Fixed & Enhanced
- **Guaranteed Cross-Lingual RAG Knowledge Retrieval:**
  - Implemented `Retriever::get_core_knowledge_chunks( $agent_id, 6 )` fallback to query balanced core chunks across primary company pages (`Services`, `Products`, `Contact`, `About Us`).
  - Added guaranteed context fallback in `Retriever::retrieve()` and `ChatController::handle_chat()` so RAG context is never empty when users ask questions in English against multilingual Knowledge Base sources.
  - Stopped keyword search from wiping out semantic embedding candidates when similarity is close to the calibrated floor.
- **Dynamic Language Mirroring & Post-Prompt Injection:**
  - Filtered out initial assistant greetings from the `$recent_msgs` history array to prevent language context pollution.
  - Injected hidden system instruction directly into the final user message payload: `[SYSTEM INSTRUCTION: Respond in the exact same language used in the user's message above. If the user wrote in English, you MUST reply 100% in English, translating any Knowledge Base facts into English.]`.
- **Language Heuristic Detection Tuning:**
  - Added key question markers and verbs to `LanguageService` to prevent false-positive English classification of international inputs.
- **Real-Time RAG Verification & Debug Return:**
  - Added `_debug_rag` field in REST response payload (`sources_found_count`, `context_length`, `context_preview`) and server error logs for DevTools Network inspection.

## [1.3.2] - 2026-11-04

### Added
- **Strict Business Topic Scoping & Guardrails:**
  - Enforced assistant restriction strictly to business services, products, warranties, and pricing.
  - Implemented multi-tier competitor detection blocking third-party companies and corporate consumer entities.
  - Added smart co-mention handling: queries comparing the company with another gracefully pivot to the business's advantages without false rejection.
  - Added partner/vendor allowlist: verified partner components pass through without being blocked as third-party entities.
  - Added comprehensive off-topic filtering for unrelated services (*plumbing, automotive, hotels, real estate*), personal advice (*dating, health/diet, crypto speculation*), and world trivia.
  - Localized polite decline messages with explicit customer service guidance and dynamic contact placeholders (`[CUSTOMER_SERVICE_CONTACT]`) across all 8 supported languages.
  - Enhanced system prompt generation in `SystemPromptBuilder` with chat-friendly brevity (< 3 short paragraphs), scannable structures, edge-case protocols (clarifying questions for vague inputs, frustration validation), and grounded scenario playbooks.

## [1.3.1] - 2026-10-15

### Fixed
- **Inline-Only Link Architecture & Zero Trailing Link Blocks:**
  - Removed trailing link footers ("Relevant page:", "Related pages:", "Verwandte Seiten:", "Mehr dazu:", "Learn more:").
  - Fixed undefined variable bug (`$inline_links`) in `sanitize_response_urls`.
  - Fixed protected fragments restoration bug where headings (`# Heading`) and bold lines (`**...**`) were left as placeholder tokens (`%%%HEADING_0%%%`).
  - Fixed Step 3a placeholder deduplication collision in `LinkPostProcessor` by protecting resolved tokens during Step 3b and Step 3c.
  - Excluded template builders (`btf_builder`, `elementor`, `/template/`) from the Link Registry.
  - Prioritized direct query phrase matches (e.g., questions about customer service or contact) in `find_relevant_links()` candidate ranking.
  - Enhanced system prompt builder with strict inline placeholder directives and explicit URL hallucination prohibitions.

## [1.3.0] - 2026-10-14

### Added
- **Smart Source Links & Strict Whitelist Link Index:**
  - Whitelist-only internal Link Index compiled from Knowledge Base & Retrieval Sources and configured XML Sitemaps (supporting nested `<sitemapindex>` hierarchies, `<urlset>` leaves, and `<lastmod>`).
  - Prohibits hallucinated, invented, or third-party URLs. Any URL not in the verified Link Index or admin-allowed trusted domains list is automatically stripped or unwrapped into plain text.
  - Automatic exclusion of drafts, password-protected pages, `noindex` robots meta (Yoast / Rank Math), `/wp-admin/`, `/cart/`, `/checkout/`, `/wp-login.php`, and custom admin patterns.
  - Rebuilds index automatically on scheduled intervals (hourly, daily, weekly via WP-Cron), on Knowledge Base modifications (`ai_chatbot_knowledge_sources_changed`), or manually via the "⚡ Rebuild Link Index" admin button.
- **Configurable Link Styles:**
  - `Inline`: naturally links relevant service and page names within answers (e.g. `[Services](url)`).
  - `Related Links Block`: appends a clean trailing source block at the end (e.g. `Mehr dazu: [Services](url)`).
  - `Both`: combines in-sentence inline links with a trailing related links block.
- **Relevance & Delivery Controls:**
  - Configurable maximum links per response (range 1–5, default 2).
  - Configurable relevance threshold (default 0.60) matching retrieved KB source first, then sitemap slug/title keywords.
  - Conversational deduplication prevents linking the same target page multiple times in a single conversation session.
  - Contextual link suppression: links are strictly prevented on greetings, clarifying questions, errors, and fallback responses.
  - Markdown protection: prevents links inside headings (`#`), bold fragments, and code blocks.
- **Multilingual Support & Language Matching:**
  - Prioritizes links in the exact language of the visitor query (German, English, Nepali, Spanish).
  - Automatically discovers translated alternate pages and falls back gracefully to default site language.
- **Streaming & Cache Integrity:**
  - Frontend Markdown renderer protects streaming chunks against broken partial Markdown brackets.
  - Enforces safe protocol whitelist (`http:`, `https:`, `mailto:`, `tel:`) and `target="_blank" rel="noopener noreferrer"`.
  - Cryptographic settings hash (`link_settings_hash`) integrated into response cache keys so settings updates immediately invalidate stale cached answers.

## [1.2.1] - 2026-10-14

### Fixed
- **Starter Questions Multiple Questions Persistence:**
  - Resolved bug where saving questions caused older questions to be dropped, resulting in only 1 question remaining.
  - Preserved full question structure (`id`, `text`, `icon`, `enabled`, `sort_order`) across database updates and REST payload construction.
  - Sanitized questions with `sanitize_text_field`, capped text length at 255 characters, and prevented case-insensitive duplicate questions.
- **Live Website Starter Questions Priority:**
  - Resolved issue where generic hardcoded questions were overriding custom configured starter questions on the live website.
  - Updated frontend widget `getStarterQuestions()` and `renderSuggestedQuestions()` to prioritize `agent.suggested_questions` and filter inactive questions.
  - Overrode `$all_starter_questions` in `AgentController::build_public_agent_payload` and `FrontendController::enqueue_assets` with configured questions.
- **Full-Page Cache Invalidation:**
  - Integrated automatic domain cache purging for caching plugins (WP Rocket `rocket_clean_domain()`, WP Super Cache, LiteSpeed, W3TC) and chat transients on question save.
- **Admin Dashboard Validation & Messaging:**
  - Added question validation (rejecting blank or duplicate questions), loading state spinners, and real error toast messaging.

## [1.2.0] - 2026-10-14

### Added
- **Smart Source Links & Whitelist Link Index:**
  - Internal server-side Link Index compiled strictly from indexed Knowledge Base sources and XML sitemaps.
  - Multi-tiered nested XML sitemap parser (`parse_sitemap_recursive()`) supporting `<sitemapindex>` hierarchies, `<urlset>` leaf nodes, and `<lastmod>` extraction.
  - Strict URL whitelist validation (`is_url_in_link_index()`): any URL not in the verified Link Index or domain whitelist is immediately stripped or unwrapped.
  - Periodic Link Index rebuild with configurable intervals (`hourly`, `daily`, `weekly`) and dynamic WP-Cron registration.
  - Automatic cache invalidation hooked into Knowledge Base source modifications (`save_post`, `transition_post_status`, KB source changes).
- **Link Appearance Styles (`link_style`):**
  - `inline`: In-sentence markdown links placed naturally within generated answers.
  - `block`: Trailing "Mehr dazu: [Title](url)" / "Related links:" block appended cleanly at the bottom.
  - `both`: Combination of in-sentence markdown links and a trailing source links section.
- **Configurable Relevance & Frequency Controls:**
  - Maximum links per answer (`max_links_per_answer`, 1–5, default 2).
  - Link relevance threshold (`link_relevance_threshold`, 0.00–1.00, default 0.60).
  - Conversation link deduplication (`deduplicate_conversation_links`, default true) preventing the same URL from being repeatedly linked within the same conversation session.
  - Link index rebuild interval selector in Admin Response Behavior settings.
- **Deterministic Link Matching & Fallback:**
  - Dynamic keyword extraction and relevance scoring with language-specific weights (German, English, Nepali, Spanish).
  - Deterministic fallback: when model outputs no link on an informative topic but a high-confidence candidate exists, the post-processor deterministically appends the link according to the configured Link Style.
  - Contextual link suppression: links are strictly prevented on greetings ("Hallo", "Hello"), clarifying questions, errors, and fallback responses.
  - Markdown protection: prevents hyperlinking text within headings (`#`), bold-only lines (`**...**`), inline code, and code blocks (` ``` `).
- **Frontend Widget & Security Enhancements:**
  - Streaming protection in frontend Markdown parser preventing broken partial Markdown brackets/links during chunked responses.
  - Protocol whitelist limited to safe schemes (`http:`, `https:`, `mailto:`, `tel:`).
  - Enforced `rel="noopener noreferrer"` and `target="_blank"` on all rendered anchors.
- **Live Cache Invalidation:**
  - Response caching transient key now integrates a cryptographic hash of link behavior settings (`link_settings_hash`), ensuring changes to link settings take immediate effect on previously cached answers.

## [1.1.9] - 2026-10-14

### Fixed
- **Bug 1: Automatic Links & Source Citation Prevention:**
  - Disabled automatic links and citations by default (`auto_links_verified: false`, `auto_links_citations: false`) across plugin defaults and React admin settings.
  - Eliminated prompt-level source URL and page title leaks by stripping `[Page: {$title}]` and source URLs from retrieved chunk headers when automatic links are disabled, preventing model hallucination of URLs based on page titles (e.g. `draft-page-for-ai-chatbot`).
  - Added strict zero-links directives to `SystemPromptBuilder` ensuring answers are delivered strictly in natural plain text and Markdown (headings, bold, lists).
  - Enhanced server-side post-processing in `ChatController::sanitize_response_urls()` to reliably strip trailing citations ("Weitere Informationen: ...", "Quelle: ...", "Sources: ...", and bare trailing URLs) and unwrap keyword/service links to clean text.
  - Preserved explicit link fulfillment when visitors explicitly request the website URL, contact page, or product link.
- **Bug 2: Starter Questions Intermittent Missing Bug:**
  - Fixed race condition by embedding complete starter question dictionaries for all 8 supported languages (`de`, `en`, `es`, `ne`, `fr`, `it`, `pt`, `hi`) directly into localized page data (`wp_localize_script`), ensuring instant availability without waiting for REST requests.
  - Implemented 3-tier language fallback hierarchy: target visitor language -> agent/site default language -> built-in defaults.
  - Fixed `LanguageService::localize_suggested_questions()` returning empty arrays when custom questions were not set.
  - Fixed widget initialization logic in `chatbot-widget.js`: starter questions now reliably render whenever the chat window opens and the conversation is empty.
  - Added dedicated "New Chat" button (`.ai-new-chat-btn`) in the widget header with multilingual tooltips, allowing users to restart conversations and restore starter questions seamlessly.
  - Added 1x retry with nonce headers on REST agent fetch to prevent silent failures caused by cached/stale nonces.
  - Ensured responsive display on desktop and mobile viewports with smooth appearance transitions and no CSS overflow clipping.

## [1.1.8] - 2026-10-14

### Added
- **Verified Link Registry (`ai_verified_links`):** Server-side URL verification engine and dedicated database table created non-destructively via `dbDelta`.
- **Automated Sitemap Discovery:** Recursively detects and parses `/sitemap.xml`, `/wp-sitemap.xml`, `/sitemap_index.xml`, `robots.txt` Sitemap directives, published WordPress posts/pages, and admin-entered custom sitemaps.
- **Multilingual Link Matching:** Language detection and strict per-URL language tagging (German answers link only German pages; Nepali, Spanish, and English queries link only language-matched pages or none).
- **Post Lifecycle Hooks:** Real-time registry synchronization hooked to `save_post`, `transition_post_status`, and `deleted_post` ensuring unpublished or trashed pages disappear immediately from answers.
- **Admin Response Behavior Settings:**
  - Automatic Links (Verified Sources Only) toggle (ON by default).
  - Allow Links When Explicitly Requested toggle (ON by default).
  - Maximum links per answer input (0–5, default 2).
  - Custom Sitemap URLs manager.
  - Allowed extra domains allowlist.
  - Excluded URL patterns editor (prefilled with `draft`, `test`, `staging`, `ai-chatbot`, `preview`, `/wp-admin`, `/wp-json`, `/feed`, `/cart`, `/checkout`, `/my-account`, `?replytocom`, `/attachment/`, `/tag/`).
  - Daily WP-Cron link revalidation toggle with rate-limited, non-blocking 5s execution.
  - Verified Contact Page URL field with real-time validation.
  - Verified Link Registry Health card with counts (valid/failed), last rebuild timestamp, "⚡ Rebuild & Validate Links Now" button, and circular 100-entry validation log viewer.
- **REST Endpoints:** `GET /ai-chatbot/v1/links/status` and `POST /ai-chatbot/v1/links/rebuild`.
- **SSRF Defense:** IP filter and DNS rebinding protection against loopback (`127.0.0.0/8`), private subnets (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), and cloud metadata (`169.254.169.254`).
- **Automated Smoke Test Suite:** `smoke-verified-links.php` testing all 11 acceptance requirements.

### Changed
- **Zero-Hallucination Prompt Architecture:** Model receives only structured placeholders (`[[link:N]]`) for retrieved knowledge chunks; raw URL injection instructions completely eliminated.
- **Chat Controller Post-Processor:** Mandatory server-side URL sanitizer swapping `[[link:N]]` placeholders with verified canonical URLs, unwrapping unverified links to natural text, stripping hallucinated domains, and dropping stale citation lines.
- **Frontend Widget (`chatbot-widget.js`):** Completely removed bare URL auto-linking regular expressions; only sanitized markdown links from the server are converted to HTML anchors (`target="_blank"`, `rel="noopener noreferrer nofollow"` for external, plain rel for internal); added support for Markdown headings H1–H4.
- **Database Version:** Bumped `AI_CHATBOT_DB_VERSION` to `1.4.0` with non-destructive automatic migration via `Migrator::migrate_to_1_4_0()`.

## [1.1.7] - 2026-10-14
- Strict RAG-only autolinking.
- Hallucinated & external link elimination.
- Contact form injection removal.

## [1.10.7] - 2026-10-13
- Link control and citation prevention settings.
- Prompt-side link suppression.
