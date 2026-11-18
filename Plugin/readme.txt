=== AI Chatbot ===
Contributors: iqronix, antigravity
Tags: chatbot, ai, groq, openai, chat, customer service, rag
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Production-ready AI Chatbot for WordPress. Build, train, and deploy an AI assistant in 5 to 10 minutes with RAG, Groq failover, and WordPress email notifications.

== Description ==

**AI Chatbot** lets any WordPress site owner create, train, and deploy a custom AI assistant in 5 to 10 minutes without touching a line of code. Connect your choice of AI provider (Groq or OpenAI), train the chatbot on your website content, and deploy it anywhere with smart topic guardrails and email lead capture.

### Key Features

* **One-Paste Setup Wizard**: Paste your site URL, let AI scan your pages, and draft your persona, business facts, starter questions, and FAQ in 2 minutes.
* **Ultra-Fast Chat with Groq**: Delivers sub-second conversational answers using hosted open models (Llama 3.3 70B, Llama 3.1 8B, Mixtral).
* **Multiple API Keys & Automatic Failover**: Add multiple Groq API keys to a resilient key pool. When a key hits an HTTP 429 rate limit, the plugin cools down the key using the `retry-after` header and immediately retries with the next active key.
* **Retrieval-Augmented Generation (RAG)**: Ingest webpages, PDF, DOCX, XLSX, CSV, plain text, and curated Q&A pairs with fast semantic vector search.
* **Curated Q&A Priority Boost**: Direct Q&A entries receive the highest retrieval priority to guarantee exact answers for critical FAQs.
* **3-Layer Topic Guardrails**: Protects your brand by strictly confining conversations to company business, politely deflecting off-topic queries, and resisting prompt injection attacks.
* **Self-Improving Unanswered Inbox**: Automatically captures low-confidence questions or negative feedback, groups similar questions, generates AI draft answers, and lets you promote answers to verified Q&A in one click.
* **WordPress Email Notifications**: Delivers new lead notifications and contact requests seamlessly using WordPress native wp_mail() to your WordPress Administration Email.
* **Customizable Floating & Embedded Chat Widget**: Lightweight vanilla JS & CSS widget with CSS custom property theming, responsive mobile layout, ARIA live regions, and RTL support.

== Installation ==

1. Upload the `ai-chatbot` folder to the `/wp-content/plugins/` directory, or install the .zip directly via Plugins -> Add New -> Upload Plugin.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **AI Chatbot** in your WordPress admin menu.
4. Go to **Settings -> AI Providers** and add your Groq API key and OpenAI API key.
5. Use the **Setup Wizard** to paste your website URL and generate your initial chatbot configuration in under 2 minutes.
6. Customize the appearance in **Settings -> Theme & Live Preview** and publish!

== Frequently Asked Questions ==

= Does Groq support embeddings? =
Groq is an ultra-fast chat completion provider and does not provide an embeddings endpoint. The plugin pairs Groq for chat generation with OpenAI for vector embeddings.

= How does Groq key failover work? =
When a Groq key receives an HTTP 429 rate limit response, the plugin reads the `retry-after` header, puts the key into a temporary cooldown state in WordPress transients, and immediately retries the request using the next available active key in the pool.

= Are my API keys safe? =
Yes. All API keys are encrypted at rest using libsodium with keys derived from WordPress salts. Keys are used strictly server-side and never exposed to the browser, JavaScript, or logs.

= Does the plugin slow down my website? =
No. The frontend widget is written in pure vanilla JavaScript and CSS with no heavy external libraries, weighing under 30KB total.

== Screenshots ==

1. Modern SaaS Admin Dashboard - Agent persona, greeting, and suggested questions.
2. Knowledge Base Dashboard - Source management for webpages, files, and Q&A.
3. Groq API Key Pool - Real-time cooldown status and failover tracking.
4. Setup Wizard - Automated 2-minute site crawl and review screen.
5. Frontend Chat Widget - Floating responsive chat widget with live preview.

== Changelog ==

= 1.3.5 =
* Unlimited Conversation & Sliding Context Window: Implemented automatic sliding context window retaining recent turns to prevent token overflow and model TPM limits, allowing visitors to chat without hitches.
* Resilient API Retry Backoff: Added automatic retry with backoff for HTTP 429 (Too Many Requests) and 503 (Overloaded) responses, with transparent error logging to error_log().
* Dynamic Visibility & Guardrails Scoping: Ensured page-specific visibility and topic guardrails operate entirely through dynamic Admin Settings without hardcoding.
* Theme & Floating Widget Compatibility: Optimized widget container z-index and spacing to guarantee seamless coexistence with floating contact buttons, telephone actions, and mobile header CTAs.


= 1.3.3 =
* Cross-Lingual RAG Knowledge Retrieval: Guaranteed injection of core Knowledge Base documentation (services, products, pricing, customer service contact, and company overview) even when users query in English against foreign-language documents.
* Semantic Fallback Preservation: Stopped keyword search from wiping out semantic candidates when similarity is close to the threshold.
* Post-Prompt Instruction Injection: Appended dynamic system instruction directly to the final user payload to override initial assistant greetings and guarantee English responses for English prompts.
* Initial Greeting Sanitization: Stripped initial German assistant greetings from the conversation history array sent to the LLM to prevent cross-turn language pollution.
* Language Marker Heuristic Optimization: Enhanced German question and verb markers (`welche`, `dienstleistungen`, `bietet`, `bieten`, `kontaktieren`, `an`, etc.) to prevent false-positive English classification of German inputs.
* Real-Time RAG Verification & Debug Return: Added `_debug_rag` field in REST response payload (`sources_found_count`, `context_length`, `context_preview`) and server error logs for real-time DevTools inspection.

= 1.3.4 =
* White-Label & Decoupled Architecture: Decoupled all prompts, starter questions, topic guardrails, and knowledge retrieval logic from industry-specific implementations into a generic, white-label architecture.
* Dynamic Personas & Guardrails: Converted competitor blocking and partner validation into dynamic settings configurable per website persona.
* Universal Starter Questions: Neutralized default conversation starter questions across all 8 supported languages to universal service, pricing, hours, and contact inquiries.

= 1.3.2 =
* Strict Company Scoping & Guardrails: Restricts assistant responses exclusively to configured business topics, products, services, warranties, and quotes.
* Competitor & Entity Defense: Automatically declines inquiries regarding third-party companies, competitor utilities, and external entities. Co-mention inquiries gracefully pivot to the business without false rejection.
* Unrelated Topic Filtering: Politely declines unrelated services (plumbing, car repair, hotels, real estate), personal advice (dating, diet, crypto speculation), and general trivia.
* Customer Service Guidance: Rejection messages across all supported languages direct visitors to customer service for further assistance with contact details or placeholders.
* System Prompt Directives & Playbooks: Integrated chat-friendly brevity (< 3 short paragraphs), scannable structures, edge-case protocols (clarifications on ambiguous inputs, frustrated customer validation), and scenario playbooks.

= 1.3.1 =
* Inline-Only Link Architecture & Zero Trailing Link Blocks: Removed trailing link footers ("Relevant page:", "Related pages:", "Verwandte Seiten:"). Fixed Step 3a placeholder deduplication collision in `LinkPostProcessor`. Prioritized direct query phrase matches in candidate ranking. Enhanced system prompt builder with strict inline placeholder directives and explicit URL hallucination prohibitions.

= 1.3.0 =
* Smart Source Links & Whitelist Link Index: Grounded chatbot responses with verified links compiled exclusively from Knowledge Base sources and XML sitemaps (including nested sitemap indexes with recursive `<sitemapindex>` resolution and `<lastmod>` tracking). URLs not present in the internal Link Index or allowed domains whitelist are strictly stripped or unwrapped server-side.
* Three Link Appearance Styles: Added configurable Link Style setting (Inline, Related Links Block, or Both) allowing customizable presentation of hyperlinks.
* Configurable Relevance & Frequency Controls: Admin settings for Maximum Links per Answer (1–5), Link Relevance Threshold (0.00–1.00), Rebuild Interval (hourly, daily, weekly), and Conversation Deduplication.
* Multilingual Language Preference: Matches response language (German, English, Nepali, Spanish), prioritizing localized pages when translations exist and falling back cleanly to site default.
* Safe Frontend Markdown Rendering: Streaming protection prevents broken partial Markdown brackets/links during chunked streaming. Enforces protocol whitelist (http, https, mailto, tel) and `rel="noopener noreferrer" target="_blank"` on all rendered links.
* Dynamic Cache Invalidation: Response caching transient key includes a hash of link behavior settings (`link_settings_hash`) so configuration updates immediately apply to cached responses.

= 1.2.1 =
* Starter Questions Multiple Questions Persistence: Resolved critical bug where saving starter questions overwrote the list with only 1 question. Full question metadata (`id`, `text`, `icon`, `enabled`, and `sort_order`) is now preserved through serialization and round-trips.
* Live Website Starter Questions Priority: Live chatbot widget and server-side script localization now prioritize custom configured starter questions over generic default questions, ensuring custom questions immediately render on the live website.
* Full-Page Caching & Transients Invalidation: Automatic purge of page cache plugins (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache) and chat response transients upon saving agent questions, reflecting changes instantly on the live website.
* Admin Validation & User Experience: Added validation preventing blank or duplicate questions and capping length at 255 characters. Provided loading states and feedback toasts with real error messages.

= 1.2.0 =
* Smart Source Links & Whitelist Link Index: Chatbot grounds answers with helpful, relevant links exclusively from verified Knowledge Base sources and XML sitemaps (including nested sitemap indexes with recursive `<sitemapindex>` resolution and `<lastmod>` tracking). URLs not present in the internal Link Index are strictly prohibited and unwrapped or stripped server-side.
* Link Appearance Styles: Added configurable Link Style setting with three display modes:
  - Inline: natural in-sentence Markdown links (`[Title](url)`).
  - Block: appended "Mehr dazu:" / "Related links:" section at the end of the response with bulleted source links.
  - Both: natural inline links plus trailing source links block.
* Configurable Relevance & Frequency Controls: Admin settings for Maximum Links per Answer (1–5), Link Relevance Threshold (0.00–1.00), Index Refresh Interval (hourly, daily, weekly), and Conversation Deduplication (prevents repeatedly linking the same target URL across turns).
* Deterministic Relevance Scoring & Fallback: Automatic keyword extraction and relevance scoring with language-specific weighting. If the AI model omits a link on an informative topic but a high-confidence candidate exists, the post-processor deterministically inserts the verified link.
* Context-Aware Link Guardrails: Automatic links are strictly suppressed on greetings, clarifying questions, error messages, and fallback responses. Heading lines (`#`), bold-only lines (`**...**`), code blocks, and already-linked text are never corrupted.
* Response Cache Invalidation: Response caching now incorporates a hash of all link configuration settings, guaranteeing instant live updates when settings are altered without serving stale link-disabled answers.
* Frontend Streaming & Protocol Hardening: Hardened Markdown parser with streaming protection preventing broken partial Markdown brackets/links during chunked delivery. Whitelisted protocols (`http:`, `https:`, `mailto:`, `tel:`) with enforced `rel="noopener noreferrer"`.

= 1.1.9 =
* Bug 1 Fix - Automatic Links & Citation Prevention: Disabled automatic links and citations by default (`auto_links_verified: false`, `auto_links_citations: false`). Completely eliminated model hallucination of URLs from page titles by stripping internal titles/URLs from retrieved knowledge headers unless explicitly requested. Added robust server-side sanitization that purges unsolicited citations ("Weitere Informationen: ...", "Quelle: ...", trailing URLs) and unwraps keyword links to clean text while preserving bold, lists, and headings. Retained links when visitors explicitly ask for website, contact, or service URLs.
* Bug 2 Fix - Guaranteed Starter Questions: Fixed intermittent missing starter questions across desktop and mobile. Localized starter question sets for all 8 languages directly into in-page data (`wp_localize_script`), eliminating network race conditions. Implemented full 3-tier language fallback hierarchy (target language -> default language -> built-in defaults) in PHP and JS. Added 1x retry on REST agent fetch with nonces. Ensured starter questions show 100% of the time on empty conversations and re-render on "New Chat". Added dedicated "New Chat" button with multilingual tooltips.
* Admin Settings Alignment: Updated React Settings UI with "Automatic Links and Citations" (disabled by default) and "Allow Links When Explicitly Requested" (enabled by default) with seamless persistence and backward compatibility.

= 1.1.8 =
* Verified-Links-Only System: Strict server-side Link Registry (`ai_verified_links`) aggregating URLs strictly from XML sitemaps (/sitemap.xml, /wp-sitemap.xml, /sitemap_index.xml, robots.txt, custom sitemaps) and retrieved Knowledge Base chunks. Zero model link hallucination or memory guessing.
* Zero-Hallucination Prompt Architecture: Structured placeholders (`[[link:N]]`) delivered to model; PHP post-processor validates canonical target, HTTP 200, non-draft status, and exact query language before emitting markdown links.
* Multilingual Language Matching: German answers only link German pages; Nepali, Spanish, and English answers only receive language-matched links or none.
* Real-Time Post Sync: Instant link addition/removal on post publish, unpublish, trash, or status transition (`save_post`, `transition_post_status`, `deleted_post`).
* Admin Response Behavior Suite: Added settings for Verified Links toggle, requested link permission, maximum links per answer (0-5), sitemap URLs, domain allowlists, excluded pattern manager, daily WP-Cron revalidation, and live Registry Health Dashboard with "Rebuild & Validate Now" and 100-entry log inspector.
* Frontend Autolinker Elimination: Completely stripped bare URL autolinking from frontend JS; sanitized HTML anchors with secure target/rel attributes (internal vs external) and Markdown heading support (H1-H4).
* SSRF Protection: Hardened link verification engine against Server-Side Request Forgery with DNS resolution checks against private, loopback, and cloud metadata IP ranges.

= 1.1.7 =
* Strict RAG-Only Autolinking: Refactored prompt directives and URL sanitization so links are strictly included only if present in the retrieved Knowledge Base (RAG) context for the current query.
* Hallucinated & External Link Elimination: Model instructed never to guess or insert external URLs. Unsolicited trailing citations and bare URL lists are stripped, and unverified links are cleanly unwrapped.
* Contact Form Injection Removal: Completely removed embedded contact form and handoff card component injection. Chatbot delivers contact details strictly in plain text/Markdown using Knowledge Base details.

= 1.10.7 =
* Link Control & Citation Prevention: Added dedicated Link Control settings in Settings -> Response Behavior with "Automatic Links and Citations" (disabled by default) and "Allow Links When Explicitly Requested" (enabled by default).
* Permanent URL Leakage Elimination: Permanently stopped the AI chatbot from automatically appending website URLs, source links, or citations (e.g. "Weitere Informationen: [url]" or bare trailing URLs) to AI-generated answers.
* Knowledge Base Citation Suppression: Verified that RAG knowledge retrieval suppresses source URL headers from prompt context when automatic links are disabled, eliminating model citation cues.
* Markdown Link & Keyword Protection: Prevented automatic hyperlinking of company names, service names, and keywords. Unsolicited keyword links are unwrapped to natural plain text while preserving bold formatting, bullet points, and headings.
* Explicit Link Requests Preserved: When a visitor explicitly asks for a website link, contact URL, or page address (e.g. "What is your website URL?" or "Was ist Ihre Website-URL?"), the verified URL is cleanly provided.
* Frontend Markdown Link Rendering: Enhanced widget JavaScript Markdown parser to cleanly render legitimate markdown links (`[label](url)`) as secure HTML anchors with rel="noopener noreferrer nofollow" without tag corruption.

= 1.10.6 =
* PC Desktop Email Us & Webmail Compatibility: Resolved issue on desktop PCs (Windows and macOS) where clicking "Email Us" failed or appeared unresponsive when no local desktop email client (such as Outlook desktop or Windows Mail) was registered in the OS. Implemented immediate support email copying to clipboard with visual toast confirmation (`✓ Email Copied`), reliable fallback clipboard mechanisms, and an interactive Webmail Options Tray providing direct 1-click links for Gmail (`mail.google.com`), Outlook Web (`outlook.live.com`), and the OS Default App.
* Side-by-Side Action Button UI: Redesigned the Human Handoff buttons ("Email Us" and "Continue Chatting") into an elegant, balanced side-by-side 2-column grid layout across desktop and mobile screens. Ensures equal button height, responsive button sizing, centered icons, and seamless theme styling in light and dark modes.
* Full Container Width Handoff Expansion: Updated `.ai-message.has-handoff` container width to 100% on all viewports, ensuring comfortable breathing room for contact actions without awkward line wrapping.
* Cache Invalidation & Minification Refresh: Hardened frontend asset serving against aggressive site caching (WP Rocket) to guarantee immediate delivery of current widget scripts and styles.
* Comprehensive Verification & Production Packaging: Fully verified all PHP 8.x code, JavaScript widget behavior, REST API security, and packaged the production-ready ai-chatbot-v1.10.6.zip archive.

= 1.10.5 =
* Direct Email Human Handoff: Replaced legacy contact form modal and lead submission system with an elegant direct email action card. Visitors needing personal assistance can click "✉ Email Us" to immediately open their default email client (mailto:) with a privacy-conscious, pre-filled context summary.
* Dynamic Email Notification Linking: Linked the recipient of the handoff email directly to the plugin's configured Email Notifications setting (Settings::get_support_email()), dynamically prioritizing custom email, notification recipient, support email, and WordPress administrator email (admin_email) without hardcoded addresses.
* Human Handoff UI & Text Re-phrasing: Polished and rephrased handoff card copy for warm, professional engagement across all 8 supported languages (English, German, Spanish, Nepali, Hindi, French, Italian, Portuguese). Guaranteed zero leaking of internal triggers or fake button text like "(button)" or "[button]".
* Mobile Responsiveness & Viewport Enhancements: Fixed mobile bubble squeezing on handoff cards (.has-handoff container width expanded to 96%), optimized stacked action buttons on mobile screens (max-width: 480px), enhanced touch target heights (min 42px), and smooth launcher animations.
* Setup Wizard & Onboarding Stabilization: Fully validated and stabilized the 2-minute setup wizard; verified step persistence, non-blocking navigation gating, safe resume capability, and preserved existing settings upon upgrade.
* Multilingual Consistency: Verified language detection hierarchy and conversation language lock across multi-turn chats in English, German, Spanish, Nepali, Hindi, French, Italian, and Portuguese with strict identifier preservation for URLs, emails, code, and pricing.
* Reliability, Security & Performance: Full PHP 8.x syntax and lint audit, WordPress security standards compliance (nonces, capability checks, input sanitization, output escaping, prepared SQL queries), and production ZIP packaging.

= 1.10.4 =
* Intelligent Onboarding Wizard Enhancements: Added dedicated Embeddings Provider (Knowledge Search) configuration in Step 2 of the Onboarding Wizard alongside Chat Completion AI. Users can configure and test their Mistral or OpenAI embeddings key independently or reuse existing credentials, ensuring vector retrieval is operational from initial setup.
* Setup Wizard Resilience: Resolved fatal retriever type error during test conversations by properly passing configured embedding credentials and vector store context into retriever routines.
* Multilingual AI Consistency: Enhanced 5-tier language detection hierarchy ensuring seamless responses in English, Nepali, German, Spanish, French, Hindi, Portuguese, Italian, and model-supported languages with strict entity preservation for code, URLs, brand names, and emails.
* Frontend & Animation Optimizations: Immediate launcher button responsiveness with touch-action optimization, GPU-accelerated window transitions, and full compliance with prefers-reduced-motion media query.
* Admin Dashboard Responsiveness: Scoped script and style registration restricted exclusively to plugin administration screens, preventing theme/admin conflicts and double submissions.
* AI Response Reliability & Guardrails: Hardened untrusted data handling across visitor prompts and scraped content, with graceful fallbacks for rate limits and upstream timeouts.
* Security & Compatibility: Sodium encryption for API keys at rest, nonces and strict capability checks on all REST endpoints, zero breaking database migrations.

= 1.10.3 =
* Advanced First-Time Setup Wizard: Seamless 2-minute onboarding wizard for new installations with interactive step progress, instant AI connection testing, and gating.
* WordPress-Native Email Notifications: Replaced third-party Resend dependency with native wp_mail() sending directly to the WordPress Administration Email (get_option('admin_email')).
* Multilingual AI Response Optimization: Enhanced 4-tier language resolution hierarchy (explicit user request > latest user message > established conversation language > default language) supporting English, Nepali, German, Spanish, French, Italian, Portuguese, Hindi, and more.
* Strict Entity Preservation: Guarantees company names, product names, URLs, email addresses, code, and technical identifiers remain untranslated and intact across all languages.
* Faster Chatbot Frontend: Instant launcher button response with touch-action manipulation, GPU-accelerated window transitions with will-change and containment, zero layout shift, and robust duplicate mounting protection.
* AI Guardrails & Prompt Injection Resistance: Hardened untrusted input handling treating website content, retrieved documents, and visitor queries strictly as untrusted data; enhanced error fallbacks without internal server disclosure.
* Admin Dashboard Optimization: Scoped asset loading ensuring admin scripts and styles only load on plugin admin pages, duplicate submission prevention, and reactive settings status feedback.

= 1.10.2 =
* Improved multilingual response consistency
* Improved language detection
* Improved chatbot response handling
* Improved frontend performance
* Improved chatbot opening animation
* Improved error handling
* Security and stability improvements
* General performance optimizations

= 1.10.0 =
* Visitors can edit their own messages from the chat widget. A pencil appears on each of your messages; clicking it turns the bubble into an inline editor with Cancel and Send, matching the workflow in ChatGPT and Gemini.
* Editing an earlier message discards the replies that followed it and generates a fresh answer, rather than leaving the conversation answering a question the visitor is no longer asking. Enter sends, Shift+Enter adds a newline, Escape cancels.
* Assistant messages are not editable. An assistant reply is the record of what the bot actually said, and allowing it to be rewritten would let a visitor put words in the bot's mouth and then feed that back as context on the next turn.
* The server now returns the id of the visitor's own message. It was previously only known internally, which is why the visitor's bubbles had no edit affordance at all.
* Edits are scoped to the caller's own session. Message ids are sequential and therefore guessable, so a request must present the session that owns the message; without that check any visitor could rewrite or delete any conversation on the site.
* Editing is rate limited separately from sending, at 10 per minute against the chat limit of 25, because each edit costs a full regeneration and would otherwise be the cheapest way to exhaust the provider quota.
* Saving an edit resets the off-topic throttle for that conversation. The turns that earned the throttle were part of the discarded branch, so carrying the count forward would let a visitor edit their way out of a penalty they had already served.
* Rewriting a message that asks for nothing in the knowledge base now logs the edited question to the Unanswered Questions inbox, with PII scrubbed as usual, so a reworded question is still captured as a knowledge gap.
* An edit that changes nothing is a no-op and does not spend a regeneration.
* Added is_edited / edited_at to the messages table so a reworded message is distinguishable from a first-time ask.

= 1.9.0 =
* Added an editor for AI-drafted answers in the Unanswered Questions and Scope Tuning screens. Correct the model's wording, see a word-level diff against the original, then approve it to publish.
* The AI draft is now preserved as `ai_draft_answer` instead of being overwritten, so every correction is reversible from the same screen.
* Saving a correction and publishing it are separate actions. A saved draft stays in the review queue and reaches visitors only once approved.
* Added Regenerate with AI, which asks for a replacement draft while keeping the first one as the comparison baseline.
* Scope Tuning is now a full review queue. Deflected questions can be given a draft, corrected, ruled in scope to publish a Q&A entry, or confirmed off-topic. Previously the only action was a browser prompt() that deleted the log row, losing the decision entirely.
* Ruling a deflected question in scope now indexes the new Q&A entry immediately. It previously created the entry but left it unindexed, so the promoted answer was invisible to retrieval until a manual reindex.
* Added a Draft Accuracy panel. It reports how many published answers were corrected before publishing, how much of the AI text was kept, how many questions needed repeated corrections, and what share of deflected questions were ruled back in scope.
* Every manual override is recorded in a new `ai_answer_overrides` table so the accuracy figures survive a question being approved, dismissed or deleted.
* Fixed out-of-scope questions being logged twice when they differed only in punctuation or capitalisation. The hash now strips punctuation first, matching how the unanswered inbox deduplicates, so occurrence counts are accurate.
* A repeat deflection no longer clears a draft or a ruling you already made on that question.
* Fixed the Scope Tuning tab dropping unanswered drafts: approving or ruling in scope with an empty answer used the stored draft where it should have, and now reports a clear error instead of silently doing nothing.

= 1.8.1 =
* Fixed the notification badge rendering inconsistently between launcher shapes. Only the circle shape pinned its width, height and radius with !important, so the rounded and pill buttons could be resized by theme CSS. Because the badge is positioned against the button's padding box, a button that drifted in size or padding moved the badge with it.
* Rounded and pill now pin the same set of box properties as the circle.
* The badge offset is defined once for all shapes. Rounded and pill previously used -4px while the circle used -3px, so the badge visibly jumped when the shape was changed.
* The launcher now declares overflow: visible, guaranteeing the badge is never clipped, and the rule was consolidated into the base launcher block instead of three separate ones.
* An avatar launcher on the pill shape no longer loses the capsule padding, which the avatar rule was overriding to zero.

= 1.8.0 =
* Incoming assistant messages now trigger the message alert, the notification sound and the badge update together, from a single handler (handleIncomingMessage) called once per message, so the three can no longer drift apart.
* Added the missing incoming message alert: the new bubble flashes and the launcher rings while a message waits behind a closed panel.
* The badge is now actually incremented by incoming messages. It previously only reflected the page-load badge, because bumpBadge() was never called.
* The page title shows an unread counter while the tab is in the background, and restores cleanly when the panel is opened or the widget closes.
* The greeting no longer counts as an unread message, so it does not flash the alert or bump the badge.
* The alert and attention animations respect prefers-reduced-motion.

= 1.7.4 =
* Removed the box-shadow ring around the notification badge. The ring was built from theme colours, so on a dark theme (this site's primary colour is #18181b) it rendered as a black border around the badge.
* The avatar launcher no longer clips its own overflow, matching the badge rendering above it.

= 1.7.3 =
* Fixed the notification badge being sliced. The launcher button carried overflow:hidden, added earlier to contain the avatar, which also clipped the badge because it is deliberately positioned outside the button (top/right -3px). The avatar is now contained by its own border-radius instead, so the badge renders whole.
* Badge text no longer wraps, and a two or three digit number grows the pill instead of being squeezed.
* Added Delete and Delete All to the Self-Improving Knowledge Inbox. Delete removes a single logged question permanently; Delete All clears the inbox and requires typing DELETE ALL, matching the confirmation used elsewhere in the plugin.

= 1.7.2 =
* Fixed badge numbers never appearing in count mode. The numeral was only rendered for counts above 1 while the load path passed 1, so count mode always showed a bare dot.
* The badge now shows the running impression count for the day instead of a hardcoded 1.
* The inactivity nudge no longer overwrites the counter captured before the load badge incremented it, which discarded that increment.
* Fixed a settings type bug that turned any stored integer 0 or 1 into a boolean. This corrupted the badge settings (load_delay_ms became true, so the badge appeared after 1ms; nudge_after_ms became false; max_per_day became true, so the badge was hidden after a single impression) and could affect any integer setting with a value of 0 or 1. The stored type is now taken from the setting's default.
* Settings that were stored as an empty string now fall back to their default instead of leaking an empty value where a boolean is expected.
* Raised the default max-per-day from 1 to 3.

= 1.7.1 =
* Fixed badge numbers never appearing in count mode. The number was only rendered for counts above 1, while the load path passed 1, so count mode showed a bare dot.
* The badge now shows the running impression count for the day instead of a hardcoded 1, so the numeral matches how many times it has appeared.
* The inactivity nudge no longer overwrites the counter captured before the load badge incremented it, which silently discarded that increment.
* Raised the default max-per-day from 1 to 3, so the badge no longer disappears for the rest of the day after a single impression.

= 1.7.0 =
* Added an optional notification badge to the launcher icon. It ships inside the button markup but hidden by CSS, so revealing it is a single class toggle with no layout shift, and it appears shortly after page load.
* Badge can show a plain dot or an unread count, clears when the visitor opens the panel, and can re-appear later via an optional inactivity nudge.
* Impressions are counted per day in local storage so the badge does not nag returning visitors.
* The badge markup, reveal transition, dot and count styles, and pulse animation respect prefers-reduced-motion.
* Added a Notification Badge section to Settings so the badge can be turned off or switched between dot and count.

= 1.6.2 =
* The welcome message now animates in when the panel opens, with the starter question chips following in a short stagger. Later replies keep the lighter default fade so the conversation does not feel bouncy.
* The entrance animation respects prefers-reduced-motion.

= 1.6.1 =
* Starter questions are now shown only once, on the welcome screen. They used to be re-appended after every reply, so the same chips kept returning for the rest of the conversation. The API still returns suggested_questions, but the widget ignores it after the first screen.

= 1.6.0 =
* Fixed answers being served from a stale, invented curated answer. A single wizard-generated Q&A entry claiming the company offered "hydropower and electromechanical equipment" was overriding the real page content, because curated Q&A was explicitly given precedence over the knowledge base in the prompt. Q&A is now a cached answer that page content overrides when the two conflict.
* The setup wizard no longer stores a drafted starter Q&A unless its answer is traceable to the crawled page text, so invented services cannot be saved in the first place.
* Wizard rules now require starter Q&A answers to use only terms that appear on the site, and prefer returning fewer entries over a speculative one.
* Removed the invented hydropower Q&A entry and its orphaned vector chunks.
* The relevance gate no longer refuses correct answers. It previously rejected English questions about this German-language site (scoring 0.72-0.75 against a 0.75 threshold) while admitting unrelated German questions (0.7506), so visitors asking in English were told the information was not on the site. The margin is now zero and the model makes the final relevance judgement.
* The "not found on this website" reply is now written in the visitor's language (German, French, Spanish or English) instead of always English. A configured guardrails reply_language still overrides detection.

= 1.5.1 =
* The chat launcher now displays the configured avatar image instead of the default chat icon, so the button and the chat header show the same face. The built-in icon remains as the fallback when no avatar is set.
* The avatar is clipped to whichever launcher shape is selected (circle, rounded or pill), with its size pinned so theme CSS cannot distort the button.
* The avatar URL is escaped before it reaches the markup.

= 1.5.0 =
* Added an avatar option under Settings → Theme. Pick any image from the WordPress media library, paste a URL directly, or remove it to fall back to the agent's initial. The image appears in the live chat header and in both desktop and phone previews.
* The media library is now loaded on the plugin's admin screens, which it previously was not, so the picker had nothing to open.
* The admin live preview now renders replies as Markdown instead of raw text, matching what visitors see on the site.
* The system prompt now requires every reply to be written in Markdown, with bold for key terms, bullet lists for multiple items, and the source URL on its own final line as a bare URL so it renders as a clickable link.

= 1.4.0 =
* Admin dashboard refreshed: new indigo design tokens, layered shadows, sticky frosted-glass top bar and sidebar, consistent 10-16px radii, gradient primary buttons with press feedback, softer badges and tables, modern thin scrollbars, and visible keyboard focus rings throughout.
* Admin dashboard gained responsive breakpoints at 1100px, 860px and 560px, including a horizontal navigation layout on tablets and single-column modal actions on phones.
* Admin dashboard now respects prefers-reduced-motion.
* Chat replies are now rendered as light Markdown instead of raw text, so bold labels, bullet lists and inline code display correctly and the cited source page becomes a clickable link. All model output is HTML-escaped before formatting and links carry rel="noopener noreferrer nofollow".
* Chat window modernised: 20px radius, gradient header, larger bubble radii with soft shadows, refined suggested-question pills, hover states on feedback controls, and smooth scrolling that respects prefers-reduced-motion.
* Launcher, send button, avatar, status dot and typing dots keep their pinned dimensions so the modernised styling cannot reintroduce icon distortion or an oval launcher.

= 1.3.1 =
* Retrieval now spreads the context across several pages. Previously one long page could supply every chunk, so the page actually holding the answer never reached the prompt.
* The relevance threshold is now calibrated per embeddings provider and model instead of relying on a fixed number. An unrelated question scores about 0.29 against OpenAI's text-embedding-3 but about 0.75 against Mistral's mistral-embed, so a single constant either filtered nothing or everything. The calibrated floor is cached, costing two embedding calls per week rather than per message.
* Knowledge retrieval now embeds the question together with the previous visitor turn.

= 1.3.0 =
* Fixed the launcher icon being resized and mis-centred by theme CSS such as a bare `svg { width: 30px; height: 30px }`. Icon size and stroke are now set in the widget stylesheet.
* Fixed the launcher deforming into an ellipse: width, height, min/max size, aspect-ratio, radius, padding, line-height and flex-shrink are all pinned, and box-sizing is applied to the widget subtree so theme button padding can no longer distort it.
* Hardened the send button, header avatar, status dot and typing dots against the same distortion.
* Fixed long conversations overflowing the chat panel instead of scrolling: the message body now has min-height:0 and contains its own scroll.
* Fixed the composer being pushed out of shape by long pasted text in the input field.
* Fixed the message body allowing long German compound words and bare URLs to widen the panel.
* Mobile fullscreen mode no longer uses 100vw/100vh, which caused horizontal overflow and hid the composer behind browser chrome; it now uses dvh with a fallback.
* Added short-viewport and wide-desktop refinements plus prefers-reduced-motion support.
* Fixed the composer staying permanently disabled if a chat request failed, added aria-busy loading state, and made non-JSON error responses report a clear server error instead of a false network message.
* Improved knowledge retrieval so differently phrased questions and context-dependent follow-ups now find the right page, by also embedding the question together with the previous visitor turn.
* Added prompt guidance for follow-up continuity and for concise, non-repetitive answers.

= 1.2.1 =
* Fixed a credential mix-up where the embeddings key was also reused for chat calls, handing a Mistral key to OpenAI and vice versa. Chat and embeddings credentials are now resolved separately.
* Fixed the chat model defaulting to a Groq-only model when a different chat provider was selected.
* AI service failures no longer all return one generic sentence: rate limits, bad credentials and other errors are now distinguished, the visitor is pointed to "Contact a Human", the button is actually offered, and the real cause is recorded in the error log and the ai_chatbot_last_chat_error option.
* Added a "Knowledge & Sitemap" settings tab for the sitemap URL, scheduled auto-sync, sync frequency and the minimum relevance score. These settings previously existed but could not be edited anywhere.
* Added an editable "Not On This Website" reply to the guardrails panel.

= 1.2.0 =
* The chatbot now answers strictly from the website's own pages: answers must be grounded in retrieved content, and it refuses with a clear "not on this website" message instead of guessing.
* Retrieved chunks now carry their page title and source URL, and answers end with the URL of the page the information came from.
* Added a relevance threshold (knowledge.min_relevance_score) so weak matches are treated as "not found" rather than answered loosely.
* Added SitemapSync: "Refresh Sitemap" adds newly published pages, re-indexes changed ones, and removes pages that are no longer listed so deleted content stops being answered from.
* Added a daily scheduled sync (knowledge.auto_sync) plus a manual "Refresh Sitemap" button on the Knowledge dashboard.
* Fixed sitemap discovery on subdirectory installs, which previously found nothing.
* Fixed discovery collapsing a whole sitemap down to a single page.
* Fixed retrieval silently returning nothing when a non-OpenAI embeddings provider was selected.
* Retrieval now ignores vector chunks whose source has been deleted, and orphaned chunks are purged on sync.
* Added the Mistral AI provider for chat and embeddings, so the knowledge base no longer requires an OpenAI key.

= 1.1.0 =
* Website import now discovers pages from XML sitemaps (sitemap_index.xml, sitemap.xml, wp-sitemap.xml, sitemap-index.xml), follows nested sitemap indexes, and falls back to HTML link crawling only when a sitemap is missing or thin.
* Added a one-step "Import & Index Website" action that crawls, extracts text, and indexes a site in a single request.
* Removed the Max Pages limit from website import; the crawler now follows every page a sitemap lists, bounded by a safety ceiling.
* Fixed the setup wizard silently discarding crawled pages, so approved sites are actually indexed.
* Crawled text is reused during indexing instead of fetching each page a second time.
* Embeddings are generated in batches, so a long page no longer fails on a single oversized request.
* Embedding and indexing failures now report the underlying API error instead of an opaque count.
* Settings no longer overwrite a stored API key when the field is submitted blank or still masked.
* Saving settings rejects a Groq key in the OpenAI field and rejects a model that does not belong to the selected provider.
* Restored the missing frontend widget assets (chatbot-widget.js / .css), which were returning 404.

= 1.0.0 =
* Initial production release.
