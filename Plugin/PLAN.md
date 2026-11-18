# AI Chatbot for WordPress — Implementation Plan (PLAN.md)

*Specification Version: 1.4 | Target: WordPress 6.4+, PHP 8.0+*

---

## 1. Architecture Summary

The AI Chatbot plugin is an enterprise-grade, privacy-first, modular WordPress plugin designed to allow non-technical site owners to create, train, and deploy an AI chatbot in under 10 minutes. The architecture strictly separates concerns across six core layers:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            FRONTEND PRESENTATION                             │
│   Vanilla JS & CSS Widget  │  Shortcode [ai_chatbot]  │  Gutenberg Block    │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ Public REST Endpoints (/chat, /contact, /feedback)
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          WORDPRESS REST API CONTROLLER                       │
│ Nonces & Capabilities │ Rate Limiting │ Honeypot & Sanitization │ Session ID │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
         ┌─────────────────────────────┼─────────────────────────────┐
         ▼                             ▼                             ▼
┌─────────────────┐           ┌─────────────────┐           ┌─────────────────┐
│ PROMPT BUILDER  │           │   GUARDRAILS    │           │ KNOWLEDGE RAG   │
│ Identity, Tone, │           │ 3-Layer Defense:│           │ Vector Store,   │
│ Rules, Language │           │ Score, Prompt,  │           │ Cosine Ranking, │
│ & Context Inject│           │ Classifier      │           │ Q&A Priority    │
└────────┬────────┘           └────────┬────────┘           └────────┬────────┘
         │                             │                             │
         └─────────────────────────────┼─────────────────────────────┘
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            AI PROVIDER & KEY POOL                           │
│  AIProviderInterface │ OpenAIProvider │ GroqProvider (Fast Open Models)     │
│  KeyPool: Cooldown (429/retry-after), Rotation, Invalid (401/403), Failover │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
         ┌─────────────────────────────┴─────────────────────────────┐
         ▼                                                           ▼
┌─────────────────────────────────┐                 ┌─────────────────────────────────┐
│       EMAIL SUB-SYSTEM          │                 │     SMART BACKGROUND SERVICES   │
│ EmailProviderInterface          │                 │ Action Scheduler / WP-Cron:     │
│ ResendProvider / WpMailProvider │                 │ Webpage Crawler, File Extractor,│
│ Async Queue & Idempotency Keys  │                 │ Embeddings, Setup Wizard, Inbox │
└─────────────────────────────────┘                 └─────────────────────────────────┘
```

### Key Architectural Highlights
1. **Server-Side Security Isolation**: API keys (OpenAI, Groq, Resend) are encrypted at rest with libsodium (using keys derived from WordPress salts `AUTH_KEY`/`LOGGED_IN_KEY`) and are **never** exposed to the frontend, browser network requests, or debug logs.
2. **Provider & Storage Decoupling**: AI models (`AIProviderInterface`), Vector Stores (`VectorStoreInterface`), and Email Delivery (`EmailProviderInterface`) are coded against clean interfaces, enabling future extensions (Anthropic, Pinecone, Mailgun) without touching business logic.
3. **Decoupled Chat vs. Embeddings Providers**: Groq provides ultra-fast inference for chat generation, while embeddings are handled by OpenAI (`text-embedding-3-small` / `text-embedding-3-large`). The settings and wizard strictly validate and enforce this distinction.
4. **Resilient Key Pool with Atomic Cooldown**: Groq requests rotate through active keys in priority or round-robin order. On HTTP 429, the cooldown is atomically updated in WordPress transients/database utilizing the `retry-after` header or rate-limit reset timestamps. The user request immediately fails over to the next active key without returning an error to the visitor.
5. **3-Layer Topic Guardrails**:
   - *Layer 1 (Semantic Relevance)*: Filters out low-similarity queries against knowledge embeddings.
   - *Layer 2 (System Prompt)*: Strict system boundary instructions enforcing company scope, forbidding rule revelation, and treating inputs strictly as data.
   - *Layer 3 (Fast Scope Classifier)*: Pre-evaluates messages using a fast model (Groq) to label queries as `IN_SCOPE`, `OUT_OF_SCOPE`, or `ALWAYS_ALLOWED` (greetings, bot meta-questions, contact details, human escalation).
6. **Self-Improving Unanswered Inbox**: Automatically captures low-confidence queries, fallback triggers, and negative feedback. Groups semantically similar questions, scrubs PII, generates AI draft answers, and allows one-click conversion to Q&A knowledge entries with the highest retrieval priority.
7. **One-Paste Setup Wizard**: Crawls the site domain (respecting `robots.txt` and page limits), extracts text, and drafts business information, agent tone, greeting, suggested questions, allowed topics, and starter Q&A for admin review.

---

## 2. Final File and Folder Tree

```
c:\Users\linar\Downloads\Plugin\
├── ai-chatbot.php                         # Main plugin bootstrap file
├── uninstall.php                          # Clean uninstall handler (respects retention)
├── composer.json                          # Autoloader & test dependencies
├── package.json                           # React admin build dependencies (@wordpress/scripts)
├── webpack.config.js                      # Custom webpack build configuration
├── README.md                              # Developer documentation & extension guide
├── readme.txt                             # WordPress.org standard plugin readme
│
├── includes/                              # Core PHP classes (Namespaced: AIChatbot\...)
│   ├── class-autoloader.php               # PSR-4 dynamic autoloader
│   ├── class-plugin.php                   # Plugin lifecycle, hooks, and initialization
│   │
│   ├── Database/
│   │   ├── class-schema.php               # dbDelta table creation, indexes, upgrades
│   │   └── class-migrator.php             # Schema version migrations & seed data
│   │
│   ├── Security/
│   │   ├── class-encryption.php           # Libsodium encryption with salt derivation
│   │   └── class-sanitizer.php            # Honeypot, header-injection defense, PII scrubbing
│   │
│   ├── AI/
│   │   ├── interface-ai-provider.php      # AIProviderInterface contract
│   │   ├── class-openai-provider.php      # OpenAI chat & embeddings implementation
│   │   ├── class-groq-provider.php        # Groq OpenAI-compatible chat implementation
│   │   ├── class-key-pool.php             # API Key Pool, failover, cooldown & stats
│   │   └── class-model-registry.php       # Dynamic model discovery & param filtering
│   │
│   ├── VectorStore/
│   │   ├── interface-vector-store.php     # VectorStoreInterface contract
│   │   └── class-custom-table-store.php   # Custom table vector store with cosine math
│   │
│   ├── Knowledge/
│   │   ├── class-knowledge-manager.php    # Source CRUD, status state machine
│   │   ├── class-extractor.php            # Multi-format text extraction (Web, PDF, DOCX, etc.)
│   │   ├── class-chunker.php              # Clean token chunking (500-800 tokens + overlap)
│   │   ├── class-indexer.php              # Async chunking & embedding pipeline
│   │   ├── class-retriever.php            # Semantic search, Q&A boost, context assembly
│   │   └── class-unanswered-inbox.php     # Question capture, grouping, AI drafts, Q&A promote
│   │
│   ├── Guardrails/
│   │   ├── class-topic-guardrail.php      # Scope definition, 3-layer checks, session limiter
│   │   └── class-scope-classifier.php     # Fast LLM classifier for in/out-of-scope routing
│   │
│   ├── Prompt/
│   │   └── class-system-prompt-builder.php # Server-side prompt construction
│   │
│   ├── Wizard/
│   │   ├── class-setup-wizard.php         # One-paste URL crawler & config drafter
│   │   └── class-site-crawler.php         # Polite domain crawler respecting robots.txt
│   │
│   ├── Email/
│   │   ├── interface-email-provider.php   # EmailProviderInterface contract
│   │   ├── class-resend-provider.php      # Resend API client with idempotency
│   │   ├── class-wp-mail-provider.php     # Fallback wp_mail provider
│   │   └── class-email-queue.php          # Async queue, retry loop, email logging
│   │
│   ├── Skills/
│   │   ├── class-skills-manager.php       # Follow-up message & contact human coordinator
│   │   ├── class-follow-up.php            # Triggers, delays, frequency limiter
│   │   └── class-contact-human.php        # Lead generation & notification triggering
│   │
│   ├── Admin/
│   │   ├── class-admin-controller.php     # Admin menus, asset enqueuing, script localizations
│   │   └── class-privacy.php              # WP Privacy Tools personal data export & eraser
│   │
│   ├── Rest/
│   │   ├── class-rest-controller.php      # Base REST controller with permissions & nonces
│   │   ├── class-chat-controller.php      # Public chat endpoint with rate limiting
│   │   ├── class-contact-controller.php   # Public contact-human lead endpoint
│   │   ├── class-feedback-controller.php  # Public thumbs up/down endpoint
│   │   ├── class-agent-controller.php     # Public/Admin agent configuration endpoint
│   │   ├── class-knowledge-controller.php # Admin knowledge sources & Q&A endpoints
│   │   ├── class-settings-controller.php  # Admin settings, providers & key pool endpoints
│   │   ├── class-wizard-controller.php    # Admin wizard start & status endpoints
│   │   └── class-inbox-controller.php     # Admin unanswered questions & out-of-scope log
│   │
│   └── Frontend/
│       ├── class-frontend-controller.php  # Widget enqueue, visibility rule engine, shortcode
│       └── class-block.php                # Gutenberg block registration
│
├── admin/                                 # Admin React Application
│   ├── src/
│   │   ├── index.js                       # React entry point
│   │   ├── index.scss                     # Admin CSS styles
│   │   ├── api/                           # REST client helpers
│   │   ├── components/                    # Reusable UI (Cards, Badges, Tabs, Toggles, Modals)
│   │   ├── views/
│   │   │   ├── Agent/                     # Agent settings, suggested questions, guardrails
│   │   │   ├── Knowledge/                 # Sources dashboard, Webpages, Files, Q&A, Business Info
│   │   │   ├── Skills/                    # Follow-up message, Contact human configuration
│   │   │   ├── Settings/                  # Placement, Theme (Live Preview), Visibility, Providers, Key Pool
│   │   │   ├── Wizard/                    # One-paste setup wizard flow
│   │   │   └── Inbox/                     # Unanswered questions & Out-of-scope review
│   │   └── preview/                       # Live theme preview component
│   └── build/                             # Compiled bundle (index.js, index.css, asset-manifest)
│
├── public/                                # Lightweight Vanilla Frontend Widget
│   ├── js/
│   │   └── chatbot-widget.js              # Pure Vanilla JS widget (ARIA, RTL, responsive)
│   └── css/
│       └── chatbot-widget.css             # CSS custom properties, responsive & dark mode
│
└── tests/                                 # Automated Unit & Integration Tests (PHPUnit)
    ├── bootstrap.php                      # Test suite bootstrapper (WP mocks)
    ├── TestChunker.php                    # Text cleaning and token chunking tests
    ├── TestRetrievalRanking.php           # Cosine similarity and Q&A priority tests
    ├── TestSystemPromptBuilder.php        # Prompt structure and rule verification tests
    ├── TestGuardrails.php                 # Topic guardrails acceptance tests
    ├── TestKeyPool.php                    # 429 cooldown, invalid key, 400 no-rotate, exhaustion
    └── TestEmailQueue.php                 # Resend API, idempotency, retry and logging tests
```

---

## 3. Database Schema

All tables use the WordPress prefix (`{$wpdb->prefix}`). Every table maintains an `agent_id` column to guarantee future multi-agent readiness without schema migrations.

### Table 1: `{$wpdb->prefix}ai_agents`
Stores chatbot identity, personality parameters, and core behavior.
```sql
CREATE TABLE {$wpdb->prefix}ai_agents (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL DEFAULT 'AI Assistant',
    status VARCHAR(50) NOT NULL DEFAULT 'online', -- 'online', 'offline', 'away', 'custom'
    custom_status_text VARCHAR(255) NULL,
    role TEXT NOT NULL,
    tone VARCHAR(50) NOT NULL DEFAULT 'friendly', -- 'professional','friendly','casual','formal','helpful','concise','technical','sales','empathetic','custom'
    custom_tone TEXT NULL,
    answer_length VARCHAR(50) NOT NULL DEFAULT 'medium', -- 'short', 'medium', 'long', 'custom'
    custom_answer_length TEXT NULL,
    greeting TEXT NOT NULL,
    suggested_questions LONGTEXT NULL, -- JSON array of questions [{id, text, icon, enabled, sort_order}]
    max_questions_desktop INT(11) NOT NULL DEFAULT 4,
    max_questions_mobile INT(11) NOT NULL DEFAULT 2,
    show_on_welcome TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) {$charset_collate};
```

### Table 2: `{$wpdb->prefix}ai_knowledge_sources`
Tracks parent knowledge entries across all supported source types.
```sql
CREATE TABLE {$wpdb->prefix}ai_knowledge_sources (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
    type VARCHAR(50) NOT NULL, -- 'webpage', 'file', 'qa', 'text_block', 'business_info'
    title VARCHAR(255) NOT NULL,
    source_url TEXT NULL,
    content LONGTEXT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending', -- 'pending', 'processing', 'indexed', 'failed', 'disabled'
    error_message TEXT NULL,
    chunk_count INT(11) NOT NULL DEFAULT 0,
    metadata LONGTEXT NULL, -- JSON (file size, mime, category, tags, language, etc.)
    last_indexed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY agent_type (agent_id, type),
    KEY status (status)
) {$charset_collate};
```

### Table 3: `{$wpdb->prefix}ai_knowledge_chunks`
Stores text segments and their corresponding vector embeddings for RAG retrieval.
```sql
CREATE TABLE {$wpdb->prefix}ai_knowledge_chunks (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id BIGINT(20) UNSIGNED NOT NULL,
    agent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
    chunk_index INT(11) NOT NULL DEFAULT 0,
    chunk_text LONGTEXT NOT NULL,
    token_count INT(11) NOT NULL DEFAULT 0,
    embedding LONGTEXT NOT NULL, -- JSON float array or compact binary string
    source_type VARCHAR(50) NOT NULL,
    category VARCHAR(100) NULL,
    language VARCHAR(10) NULL DEFAULT 'en',
    metadata LONGTEXT NULL, -- JSON (page_url, file_name, dates, qa_boost)
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY source_id (source_id),
    KEY agent_enabled (agent_id, is_enabled),
    KEY source_type (source_type)
) {$charset_collate};
```

### Table 4: `{$wpdb->prefix}ai_conversations`
Maintains visitor chat threads and session state.
```sql
CREATE TABLE {$wpdb->prefix}ai_conversations (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
    session_id VARCHAR(128) NOT NULL,
    visitor_id VARCHAR(128) NOT NULL,
    off_topic_count INT(11) NOT NULL DEFAULT 0,
    is_throttled TINYINT(1) NOT NULL DEFAULT 0,
    throttled_until DATETIME NULL,
    metadata LONGTEXT NULL, -- JSON (user agent, language, ip anonymized)
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY session_id (session_id),
    KEY agent_created (agent_id, created_at)
) {$charset_collate};
```

### Table 5: `{$wpdb->prefix}ai_messages`
Stores individual chat messages in a conversation.
```sql
CREATE TABLE {$wpdb->prefix}ai_messages (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT(20) UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL, -- 'user', 'assistant', 'system'
    message LONGTEXT NOT NULL,
    tokens INT(11) NOT NULL DEFAULT 0,
    feedback VARCHAR(20) NULL, -- 'helpful', 'unhelpful'
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY conversation_role (conversation_id, role)
) {$charset_collate};
```

### Table 6: `{$wpdb->prefix}ai_settings`
Key-value configuration store for global and per-agent settings.
```sql
CREATE TABLE {$wpdb->prefix}ai_settings (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
    setting_group VARCHAR(50) NOT NULL, -- 'general', 'appearance', 'visibility', 'providers', 'skills', 'guardrails'
    setting_key VARCHAR(100) NOT NULL,
    setting_value LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY agent_group_key (agent_id, setting_group, setting_key)
) {$charset_collate};
```

### Table 7: `{$wpdb->prefix}ai_leads`
Captures "Contact Human" form submissions and generated leads.
```sql
CREATE TABLE {$wpdb->prefix}ai_leads (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
    conversation_id BIGINT(20) UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    company VARCHAR(255) NULL,
    message TEXT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'new', -- 'new', 'contacted', 'closed'
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY agent_status (agent_id, status)
) {$charset_collate};
```

### Table 8: `{$wpdb->prefix}ai_api_keys`
Manages the encrypted API key pool with cooldown tracking and usage analytics.
```sql
CREATE TABLE {$wpdb->prefix}ai_api_keys (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(50) NOT NULL, -- 'groq', 'openai'
    label VARCHAR(255) NOT NULL,
    encrypted_key TEXT NOT NULL,
    key_last_four VARCHAR(8) NOT NULL,
    priority INT(11) NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'active', -- 'active', 'cooling_down', 'disabled', 'invalid'
    cooldown_until DATETIME NULL,
    last_error TEXT NULL,
    requests_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    rate_limit_hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY provider_status_priority (provider, status, priority)
) {$charset_collate};
```

### Table 9: `{$wpdb->prefix}ai_email_log`
Logs every outbound email notification, idempotency status, and failure reason.
```sql
CREATE TABLE {$wpdb->prefix}ai_email_log (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(50) NOT NULL, -- 'resend', 'wp_mail'
    idempotency_key VARCHAR(255) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'queued', -- 'queued', 'sent', 'failed'
    retry_count INT(11) NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idempotency_key (idempotency_key),
    KEY status (status)
) {$charset_collate};
```

### Table 10: `{$wpdb->prefix}ai_unanswered_questions`
Captures low-relevance or fallback queries for the self-improving knowledge inbox.
```sql
CREATE TABLE {$wpdb->prefix}ai_unanswered_questions (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
    question TEXT NOT NULL,
    normalized_hash VARCHAR(64) NOT NULL,
    embedding LONGTEXT NULL,
    occurrences INT(11) NOT NULL DEFAULT 1,
    status VARCHAR(50) NOT NULL DEFAULT 'new', -- 'new', 'draft_ready', 'approved', 'dismissed'
    draft_answer TEXT NULL,
    resolved_qa_id BIGINT(20) UNSIGNED NULL,
    last_asked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY agent_status (agent_id, status),
    KEY normalized_hash (normalized_hash)
) {$charset_collate};
```

### Table 11: `{$wpdb->prefix}ai_out_of_scope_log`
Tracks off-topic visitor questions for guardrail tuning without polluting knowledge gaps.
```sql
CREATE TABLE {$wpdb->prefix}ai_out_of_scope_log (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
    question TEXT NOT NULL,
    normalized_hash VARCHAR(64) NOT NULL,
    occurrences INT(11) NOT NULL DEFAULT 1,
    last_asked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY agent_hash (agent_id, normalized_hash)
) {$charset_collate};
```

---

## 4. REST API Routes and Permission Rules

All endpoints reside under the namespace `ai-chatbot/v1`.

| Route | Method | Access | Permission & Security Rules |
|---|---|---|---|
| `/agent` | `GET` | **Public** | Validates optional `agent_id`; returns sanitized public config (name, status, tone, greeting, suggested questions, placement, theme tokens). No prompts, no internal settings, no keys. |
| `/chat` | `POST` | **Public** | Rate-limited (e.g. 20 req/min per IP/session). Validates session ID, message length. Executes guardrails, RAG retrieval, key-pool failover. Returns assistant reply, suggested questions, handover option. |
| `/contact` | `POST` | **Public** | Rate-limited (e.g. 5 req/hour per IP). Validates required fields, checks honeypot field `website_hp`, sanitizes email header injection, queues email via `EmailQueue`, saves to `wp_ai_leads`. |
| `/feedback` | `POST` | **Public** | Rate-limited. Captures message helpfulness (`helpful` / `unhelpful`). Links unhelpful messages to unanswered inbox. |
| `/agent` | `POST` | **Admin** | Requires `manage_options` capability + `X-WP-Nonce`. Updates agent identity, suggested questions, tone, and guardrails scope. |
| `/knowledge` | `GET` | **Admin** | Requires `manage_options`. Lists knowledge sources filtered by type and status. |
| `/knowledge` | `POST` | **Admin** | Requires `manage_options`. Adds or updates webpage, file, Q&A, text block, or business info. |
| `/knowledge/{id}` | `DELETE` | **Admin** | Requires `manage_options`. Deletes source and all associated vector chunks. |
| `/knowledge/{id}/index` | `POST` | **Admin** | Requires `manage_options`. Triggers manual background re-indexing of a knowledge source. |
| `/knowledge/scan` | `POST` | **Admin** | Requires `manage_options`. Initiates domain internal URL discovery. |
| `/settings` | `GET` | **Admin** | Requires `manage_options`. Returns system settings, provider configs, masked keys. |
| `/settings` | `POST` | **Admin** | Requires `manage_options`. Saves settings (theme, visibility, email, providers, sounds). |
| `/keys` | `GET` | **Admin** | Requires `manage_options`. Returns API key list with labels, masked keys, priority, status, cooldowns, stats. |
| `/keys` | `POST` | **Admin** | Requires `manage_options`. Adds/updates encrypted API keys in the key pool. |
| `/keys/{id}` | `DELETE` | **Admin** | Requires `manage_options`. Removes key from key pool. |
| `/keys/test` | `POST` | **Admin** | Requires `manage_options`. Tests specific provider API key connection and capabilities. |
| `/email/test` | `POST` | **Admin** | Requires `manage_options`. Tests Resend or WP Mail delivery and domain verification status. |
| `/wizard/start` | `POST` | **Admin** | Requires `manage_options`. Accepts target URL, launches async crawler and draft generator. |
| `/wizard/status` | `GET` | **Admin** | Requires `manage_options`. Returns live progress, discovered pages, and drafted configuration. |
| `/wizard/apply` | `POST` | **Admin** | Requires `manage_options`. Commits approved drafted settings to Agent and Knowledge tables. |
| `/unanswered` | `GET` | **Admin** | Requires `manage_options`. Lists unanswered questions grouped by frequency. |
| `/unanswered/{id}/approve` | `POST` | **Admin** | Requires `manage_options`. Converts draft answer into high-priority Q&A source and marks resolved. |
| `/unanswered/{id}/dismiss` | `POST` | **Admin** | Requires `manage_options`. Dismisses question from inbox. |
| `/guardrails/test` | `POST` | **Admin** | Requires `manage_options`. Tests a sample question through guardrail layers and returns verdict. |

---

## 5. Order of Work Mapped to Specification Phases

### Phase 1: Core Foundation & Chat Widget
- Set up plugin bootstrap `ai-chatbot.php`, autoloader, and DB schema creation via `dbDelta`.
- Implement secret encryption (`Security\Encryption`) using libsodium and WP salt derivation.
- Implement AI provider interface (`AIProviderInterface`), `OpenAIProvider`, and `GroqProvider`.
- Build the server-side `SystemPromptBuilder`.
- Implement `AgentController` and public `ChatController` with rate-limiting.
- Develop the lightweight frontend widget (`public/js/chatbot-widget.js`, `public/css/chatbot-widget.css`) supporting floating button, custom CSS variables, ARIA live region, and suggested questions.
- Build the initial React admin shell with sidebar navigation (Agent, Knowledge, Skills, Settings) and Agent configuration page with drag-and-drop suggested questions.
- **Verification**: Chatbot can be configured, appears on frontend, answers questions via Groq or OpenAI.

### Phase 2: Knowledge, RAG & Topic Guardrails
- Implement Knowledge source management for Webpages, Files (pure PHP text extraction), Q&A, Text blocks, and Business Information.
- Build the token `Chunker` (500–800 tokens, 10% overlap, metadata tags) with Q&A single-chunk boost.
- Implement `CustomTableVectorStore` with cosine similarity math in PHP.
- Build async `Indexer` and `Retriever` pipeline.
- Implement the 3-layer `TopicGuardrail` system:
  - Layer 1: Vector relevance threshold.
  - Layer 2: Prompt boundary defense.
  - Layer 3: Fast `ScopeClassifier` with Groq/OpenAI.
  - Session off-topic counter (stricter decline at 3, throttle at 6).
- Build the One-Paste Setup Wizard (`Wizard\SetupWizard`): domain crawler, robots.txt check, draft generator, and admin review screen.
- Add "Test Guardrails" panel in admin.
- **Verification**: Ingest business info, crawl URL, verify RAG answers accurately; verify all 7 acceptance test cases from Section 8 pass.

### Phase 3: Skills & Unanswered Questions Inbox
- Implement `FollowUpMessage` skill (triggers: 1st response, X messages, inactivity, delay, frequency controls).
- Implement `ContactHuman` skill (configurable fields, validation, honeypot, DB lead recording in `wp_ai_leads`).
- Implement the Self-Improving Unanswered Questions Inbox (`Knowledge\UnansweredInbox`):
  - Detects low relevance, fallback triggers, and thumbs-down.
  - Anonymizes PII, groups similar questions, generates AI drafts.
  - Admin UI for Approve (creates Q&A), Edit, Write Own, Dismiss.
  - Out of Scope log tab with "This is in scope" action.
- **Verification**: Trigger fallback -> appears in inbox -> approve AI draft -> immediately answered on next query.

### Phase 4: Settings, Key Pool, Email & Hardening
- Implement API Key Pool (`AI\KeyPool`) with priority and round-robin rotation, 429 cooldown with `retry-after` parser, 401/403 invalid key handling, 5xx retry once, 400 bad request protection, and org limit advisory.
- Implement `EmailProviderInterface`, `ResendProvider` (`POST https://api.resend.com/emails`), `WpMailProvider`, and `EmailQueue` with idempotency keys (`lead-notification/<id>`).
- Implement full Settings UI: placement, theme editor with live preview, visibility rules, retention settings (7/30/90/never), sounds (off by default), AI response language with "Test in this language" button, and RTL support.
- Integrate WP Privacy Tools (export and erase personal data).
- Create `uninstall.php` respecting data deletion settings.
- Build installable distribution zip and package automated PHPUnit test suite.

---

## 6. Risks, Ambiguities & Proposed Deviations

1. **Groq Organization Rate Limits**:
   - *Ambiguity/Risk*: Groq enforces rate limits per organization, not per key. Adding multiple keys under the same organization does not bypass 429 limits.
   - *Resolution*: Clearly display a prominent warning callout adjacent to the "Add Key" button in the admin UI explaining that keys must belong to separate organizations for failover capacity, and provide automatic fallback to an alternate provider (e.g., OpenAI) when all Groq keys are exhausted.
2. **File Extraction Without External Binaries**:
   - *Risk*: Shared hosting often lacks `pdftotext`, `antiword`, or python CLI tools.
   - *Resolution*: Implement pure PHP extractors for standard formats (TXT, CSV, MD, PDF text stream parser, DOCX/XLSX/PPTX via PHP's native `ZipArchive` + XML extraction). For scanned/raster PDFs without text streams, fail gracefully with an informative error message: *"Scanned image-only PDF detected. Please upload a text-searchable PDF."*
3. **Background Job Runner Compatibility**:
   - *Risk*: Action Scheduler may not be present on every WordPress installation.
   - *Resolution*: Bundle a lightweight async runner that utilizes Action Scheduler if available, and seamlessly falls back to non-blocking loopback REST requests and standard WP-Cron jobs.
4. **Cosine Similarity Performance on PHP**:
   - *Risk*: Scanning 100,000 embedding vectors in PHP memory can exhaust memory limits.
   - *Resolution*: Pre-filter vectors by `agent_id`, `is_enabled`, and source metadata (`source_type`, `category`) in the SQL query before passing candidate vectors to PHP for cosine similarity computation. Hard-cap search candidates to a maximum of 2,500 active chunks per agent, which executes in under 25ms in PHP 8.2. Document this threshold in the Vector Store architecture.
5. **Streaming vs. Non-Streaming**:
   - *Decision*: As specified in the prompt, responses will be non-streaming for v1 to guarantee complete guardrail and key failover evaluations before output begins, while keeping the `AIProviderInterface` methods and frontend message consumer ready for SSE streaming.

---

## 7. Approval to Proceed

This plan covers every aspect of Product & Development Specification v1.4. Let's proceed immediately to Phase 1 implementation.
