# AI Chatbot for WordPress — Developer Documentation

> **Plugin Version:** 1.1.9 | **Requires:** WordPress 6.4+, PHP 8.0+, MySQL 5.7+

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Installation & Setup](#installation--setup)
3. [Configuration](#configuration)
4. [Directory Structure](#directory-structure)
5. [Database Schema](#database-schema)
6. [REST API Reference](#rest-api-reference)
7. [Extending the Plugin](#extending-the-plugin)
8. [Security Model](#security-model)
9. [Building the Admin App](#building-the-admin-app)
10. [Running Tests](#running-tests)

---

## Architecture Overview

The plugin is organized into six decoupled layers:

```
Frontend Widget (Vanilla JS)
    │ REST API (ai-chatbot/v1)
    ▼
REST Controllers → Prompt Builder + Guardrails + Knowledge RAG
    │
    ▼
AI Provider & Key Pool (Groq ↔ OpenAI failover)
    │
    ├── Email Sub-system (WordPress wp_mail)
    └── Background Services (WP-Cron indexing, inbox, retention)
```

**Key design decisions:**
- API keys are encrypted at rest with `libsodium` derived from WordPress authentication salts — they never appear in browser requests, page source, or logs.
- The chat and embeddings provider are separately configurable (Groq for speed, OpenAI for embeddings).
- The `AIProviderInterface`, `VectorStoreInterface`, and `EmailProviderInterface` are clean contracts, making it straightforward to swap implementations (e.g., Anthropic, Pinecone, Mailgun).
- All responses are non-streaming in v1 to guarantee complete guardrail evaluation before output.

---

## Installation & Setup

### Requirements
- PHP 8.0+ with `sodium` extension (included in PHP 7.2+)
- WordPress 6.4+
- MySQL 5.7+ or MariaDB 10.3+
- At least one AI API key: **Groq** (free tier available) or **OpenAI**

### Manual Installation
1. Clone or download this repository into `wp-content/plugins/ai-chatbot/`
2. Build the admin assets (see [Building the Admin App](#building-the-admin-app))
3. Activate the plugin from **Plugins → Installed Plugins**
4. Navigate to **AI Chatbot → Settings → Providers** to add your first API key

### One-Paste Setup Wizard
After entering an API key, use the **Setup Wizard** tab:
1. Paste your site URL
2. The plugin crawls your domain (respecting `robots.txt`), extracts text, and drafts:
   - Agent name, role, and tone
   - Greeting message
   - Suggested questions
   - Knowledge topics and starter Q&A
3. Review the AI-generated draft and click **Apply** to activate

---

## Configuration

All settings are stored in `{prefix}ai_settings` as grouped key-value pairs. Key groups:

| Group | Purpose |
|-------|---------|
| `placement` | Widget position, shape, size, display mode |
| `theme` | Color palette, border radius, avatar, fonts |
| `visibility` | Page conditions, user roles, device rules |
| `providers` | Chat/embeddings provider, models, temperature |
| `guardrails` | Strictness, allowed topics, decline message |
| `session` | Duration, max messages, retention days |
| `sounds` | Enable/disable, volume, event triggers |
| `language` | Auto-detect, fixed locale, RTL |
| `email` | WordPress Administration Email notifications, custom recipient |

Retrieve all settings programmatically:
```php
$settings = \AIChatbot\Settings::get_all( $agent_id = 1, $mask_keys = false );
```

Save a group:
```php
\AIChatbot\Settings::save_all( 1, [
    'theme' => [ 'primary_color' => '#6366f1' ],
] );
```

---

## Directory Structure

```
ai-chatbot/
├── ai-chatbot.php              # Plugin bootstrap & constants
├── uninstall.php               # Uninstall handler (respects retention)
├── composer.json               # PHP autoloader & PHPUnit dependency
├── package.json                # NPM build dependencies
├── webpack.config.js           # Custom webpack configuration
│
├── includes/                   # PHP source (namespace AIChatbot\...)
│   ├── class-autoloader.php
│   ├── class-plugin.php
│   ├── class-settings.php
│   ├── AI/                     # Provider implementations & key pool
│   ├── Admin/                  # WP Admin menu & Privacy Tools
│   ├── Database/               # Schema & Migrator
│   ├── Email/                  # WordPress wp_mail provider + queue
│   ├── Frontend/               # Widget enqueue, shortcode, block
│   ├── Guardrails/             # 3-layer topic guardrail
│   ├── Knowledge/              # RAG pipeline (extract → chunk → index → retrieve)
│   ├── Prompt/                 # System prompt builder
│   ├── Rest/                   # REST API controllers
│   ├── Security/               # Encryption & sanitization
│   ├── Skills/                 # Follow-up & Contact Human
│   ├── VectorStore/            # Custom table vector store
│   └── Wizard/                 # Setup wizard & site crawler
│
├── admin/                      # React admin application
│   ├── src/
│   │   ├── index.js            # React entry point (all views inline)
│   │   └── index.scss          # Admin SCSS styles
│   └── build/                  # Compiled output (git-ignored)
│
├── public/                     # Lightweight frontend widget
│   ├── js/chatbot-widget.js
│   └── css/chatbot-widget.css
│
└── tests/                      # PHPUnit test suite
    ├── bootstrap.php
    ├── TestChunker.php
    ├── TestRetrievalRanking.php
    ├── TestSystemPromptBuilder.php
    ├── TestGuardrails.php
    ├── TestKeyPool.php
    └── TestEmailQueue.php
```

---

## Database Schema

The plugin creates 11 tables, all prefixed with the WordPress table prefix:

| Table | Purpose |
|-------|---------|
| `ai_agents` | Agent identity, tone, greeting, suggested questions |
| `ai_knowledge_sources` | Knowledge entries (URL, file, Q&A, text, business info) |
| `ai_knowledge_chunks` | Text segments with embeddings (vector store) |
| `ai_conversations` | Session-level conversation tracking |
| `ai_messages` | Individual chat messages |
| `ai_settings` | Plugin and agent configuration |
| `ai_leads` | Contact form submissions |
| `ai_api_keys` | Encrypted API key pool |
| `ai_email_log` | Email delivery log with idempotency |
| `ai_unanswered_questions` | Self-improving inbox, with manual draft overrides |
| `ai_out_of_scope_log` | Off-topic query tracking and scope rulings |
| `ai_answer_overrides` | Audit log of every manual correction to an AI draft |

Tables are created/upgraded via `dbDelta()` on activation and migrated via `Database\Migrator::run()` on every plugin load.

---

## REST API Reference

Base namespace: `ai-chatbot/v1`

### Public Endpoints (no authentication required)
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/agent` | GET | Returns sanitized public agent config (name, greeting, theme) |
| `/chat` | POST | Sends a message; returns AI reply. Rate-limited: 20 req/min/IP |
| `/chat/edit` | POST | Rewrites one of the caller's own messages and regenerates the branch that followed it. Rate-limited: 10 req/min/IP |
| `/contact` | POST | Submits contact form lead. Rate-limited: 5 req/hr/IP |
| `/feedback` | POST | Records thumbs up/down for a message |

### Admin Endpoints (require `manage_options` + `X-WP-Nonce`)
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/agent` | POST | Update agent identity, tone, guardrails |
| `/knowledge` | GET, POST | List / add knowledge sources |
| `/knowledge/{id}` | DELETE | Remove a source and its chunks |
| `/knowledge/{id}/index` | POST | Trigger manual re-indexing |
| `/settings` | GET, POST | Read / write system settings |
| `/keys` | GET, POST | Manage API key pool |
| `/keys/{id}` | DELETE | Remove a key |
| `/keys/test` | POST | Test a specific API key |
| `/email/test` | POST | Test email delivery |
| `/wizard/start` | POST | Launch site crawler |
| `/wizard/status` | GET | Poll wizard progress |
| `/wizard/apply` | POST | Commit drafted config |
| `/unanswered` | GET | List unanswered inbox |
| `/unanswered/{id}/draft` | POST | Save a corrected draft (does not publish) |
| `/unanswered/{id}/draft/regenerate` | POST | Request a fresh AI draft, keeping the first |
| `/unanswered/{id}/draft/revert` | POST | Restore the original AI draft |
| `/unanswered/{id}/approve` | POST | Approve → promote to Q&A (empty body uses the stored draft) |
| `/unanswered/{id}/edit` | POST | Correct the logged question |
| `/unanswered/{id}/dismiss` | POST | Dismiss from inbox |
| `/unanswered/{id}/reopen` | POST | Reopen a dismissed question |
| `/out-of-scope` | GET | List deflected questions and their rulings |
| `/out-of-scope/{id}/draft` | POST | Save a corrected draft for a deflected question |
| `/out-of-scope/{id}/draft/regenerate` | POST | Request a fresh AI holding reply |
| `/out-of-scope/{id}/draft/revert` | POST | Restore the original AI holding reply |
| `/out-of-scope/{id}/in-scope` | POST | Rule in scope → publish and index a Q&A entry |
| `/out-of-scope/{id}/rule` | POST | Rule out of scope (keeps the row for audit) |
| `/out-of-scope/{id}/edit` | POST | Correct the logged question |
| `/out-of-scope/{id}/reopen` | POST | Reopen a ruled entry |
| `/drafts/accuracy` | GET | Draft accuracy stats and insights |
| `/drafts/overrides` | GET | Raw override audit entries |
| `/guardrails/test` | POST | Test a question through guardrails |

---

## Extending the Plugin

### Adding a New AI Provider
1. Implement `AIChatbot\AI\AIProviderInterface`:
   ```php
   class MyProvider implements AIProviderInterface {
       public function chat( array $messages, array $options = [] ): array { ... }
       public function embed( array $texts, string $model ): array { ... }
       public function list_models(): array { ... }
   }
   ```
2. Register via `ModelRegistry::register( 'my_provider', MyProvider::class )`.
3. Add the provider slug to the Settings defaults under `providers.chat_provider`.

### Adding a New Vector Store
1. Implement `AIChatbot\VectorStore\VectorStoreInterface`.
2. Inject your implementation into `Knowledge\Indexer` and `Knowledge\Retriever`.

### Adding a New Email Provider
1. Implement `AIChatbot\Email\EmailProviderInterface`.
2. Inject via `Email\EmailQueue::set_provider( $provider )`.

### Using WordPress Hooks
```php
// Filter the system prompt before it is sent to the AI provider.
add_filter( 'ai_chatbot_system_prompt', function( string $prompt, int $agent_id ): string {
    return $prompt . "\n\nExtra instruction injected by extension.";
}, 10, 2 );

// Action fired after a lead (contact form) is saved.
add_action( 'ai_chatbot_lead_saved', function( int $lead_id, array $data ): void {
    // e.g. push to CRM
}, 10, 2 );

// Filter retrieved knowledge chunks before assembling the context.
add_filter( 'ai_chatbot_retrieved_chunks', function( array $chunks, string $query ): array {
    // re-rank or add custom chunks
    return $chunks;
}, 10, 2 );
```

---

## Editing Sent Messages

Visitors can rewrite any of their own messages from the widget. The interaction follows ChatGPT and Gemini:

| Action | Result |
|--------|--------|
| Hover a message | A pencil appears (always visible on touch devices, where there is no hover) |
| Click the pencil | The bubble becomes a textarea in place, with Cancel and Send beneath it |
| Enter | Save and resend |
| Shift + Enter | Insert a newline |
| Escape | Cancel and restore the original |

### Editing discards the branch

Editing an earlier message **deletes every message that followed it** and generates a fresh answer. Keeping the stale replies would leave the model reasoning over a history that answers a question the visitor is no longer asking, and would show the visitor a reply to wording they just replaced.

### What cannot be edited

**Assistant messages are not editable.** An assistant reply is the record of what the bot actually said. Allowing a visitor to rewrite it would let them put words in the bot's mouth and then feed that text back as context on the next turn, which is a prompt-injection surface rather than a correction.

### Security model

`/chat/edit` is a public endpoint, because the widget is unauthenticated. Three properties contain it:

- **Session-scoped ownership.** The conversation is resolved from the caller's `session_id` and the target message must belong to it. Message ids are sequential and therefore guessable, so a bare `WHERE id = ?` would let anyone rewrite or delete any conversation on the site.
- **Role check.** Only `role = 'user'` rows are accepted; assistant messages return 403.
- **Separate rate limit.** 10 edits/minute against the chat limit of 25, since each edit costs a full regeneration.

An edit also clears the conversation's off-topic throttle, because the turns that earned it were part of the branch just discarded.

### Storage

`ai_messages` stores conversation text verbatim, on send and on edit alike. Scrubbing only on edit would mean a reworded message was stored differently from an original one, which would both surprise the visitor and corrupt the transcript the model reads back.

The privacy boundary stays where it was: `UnansweredInbox::log_question()` scrubs PII before the question reaches the admin-facing inbox, and because an edit re-enters the same generation path, a reworded question arrives there scrubbed too.

---

## Editing AI-Drafted Answers

The **Unanswered Questions & Scope Tuning** screen lets you correct the model's draft answer instead of accepting or discarding it. Both review queues share one editor; what differs is the final action.

### Two review queues

| Queue | Source | What it means |
|-------|--------|---------------|
| Unanswered Questions | `UnansweredInbox::log_question()` | The bot hit its "not enough information" fallback, or retrieval scored below the relevance floor |
| Scope Tuning | `TopicGuardrail::log_out_of_scope()` | A guardrail deflected the message as off-topic |

Both also capture thumbs-down feedback (`Rest\FeedbackController::submit_feedback()` walks back to the preceding user message).

### Editor actions

| Action | Effect |
|--------|--------|
| **Save draft** | Stores the correction in the review queue. Does **not** publish it |
| **Approve to Q&A** | Publishes as a curated Q&A entry and indexes it for retrieval |
| **Rule in scope & publish** | Scope Tuning only. Same as above, and marks the question in-scope |
| **Regenerate with AI** | Requests a replacement draft. The *first* AI draft is kept as the comparison baseline |
| **Revert to AI draft** | Restores that baseline, discarding your correction |
| **Confirmed off-topic** | Scope Tuning only. Marks the question out of scope and keeps the row for audit |
| **Reopen** | Reverses a dismissal or a ruling so the row can be worked on again |

The original AI text is stored in `ai_draft_answer` and never overwritten, which is what makes the diff view and Revert possible.

### How an edit changes future answers

The plugin has **no model training and no fine-tuning**. A correction affects future behaviour through exactly one channel:

```
edit draft → approve → Q&A chunk → 1.25x retrieval boost → cited more often in answers
```

Approving writes a `qa` source into `ai_knowledge_sources`; indexing embeds it into `ai_knowledge_chunks`. `CustomTableStore::query_similar()` multiplies the cosine score by `1.25` for `source_type = 'qa'`, so a verified answer is retrieved ahead of the page content that made the bot unsure.

Note that the system prompt deliberately treats curated Q&A as *lower* authority than page content (rule 5 in `SystemPromptBuilder::build()`), so an edit that contradicts a page is still surfaced as a fallback rather than an override.

### Measuring whether editing helps

`DraftAccuracy::stats()` aggregates the `ai_answer_overrides` audit log:

| Metric | Reads as |
|--------|----------|
| `published_answers` | Answers published from drafts |
| `major_corrections` | Of those, how many were substantially rewritten (AI similarity below 0.5) |
| `mean_ai_similarity` | How much of the AI text was kept. Low values mean confident errors, not phrasing differences |
| `heavily_edited` | Questions needing two or more corrections. Usually a missing knowledge topic |
| `scope_yield` | Share of deflected questions ruled back in scope. High values mean the classifier is too aggressive |

Similarity is word-level Jaccard with stop words removed, computed locally. Embeddings would be more semantic but cost an API call per edit; these are read as a rough signal, not a benchmark.

---

## Security Model

| Concern | Implementation |
|---------|---------------|
| API key storage | `libsodium` (`sodium_crypto_secretbox`) with 32-byte key derived from WordPress `AUTH_KEY` + `LOGGED_IN_KEY` salts |
| API key exposure | Keys are never returned to the browser; masked (`sk-••••••••abcd`) in admin GET responses |
| Chat rate limiting | IP + session transient counter; 20 req/min for chat, 5 req/hr for contact |
| Honeypot | Hidden `website_hp` field in contact form; any non-empty value silently discards submission |
| Nonces | All admin POST endpoints validate `X-WP-Nonce` issued to `wp_rest` |
| Capability check | All admin endpoints require `manage_options`; verified with `current_user_can()` |
| Input sanitization | All user inputs sanitized with `sanitize_text_field`, `wp_kses_post`, `sanitize_email` |
| PII scrubbing | Email addresses and phone numbers anonymized before storage in unanswered inbox |
| SQL queries | All queries use `$wpdb->prepare()` with `%d`, `%s`, `%f` placeholders |
| ABSPATH guard | All PHP files begin with `defined('ABSPATH') || exit;` |

---

## Building the Admin App

The admin UI is built with `@wordpress/scripts` (Webpack + Babel + React).

```bash
# Install Node.js dependencies (only needed once)
npm install

# Development mode (watch + hot reload)
npm start

# Production build
npm run build
```

The compiled files are written to `admin/build/`. The build directory is loaded by `Admin\AdminController::enqueue_scripts()`.

> **Note:** Node.js v18+ is required. The portable Node.js binary at `C:\Users\linar\node-v20.18.0-win-x64\node.exe` can be used if Node is not in `PATH`.

---

## Running Tests

PHPUnit tests require WordPress to be loaded. Use the WP test framework or mock stubs.

```bash
# Install Composer dependencies (PHPUnit)
composer install

# Run all tests
composer test

# Run a specific test class
./vendor/bin/phpunit tests/TestKeyPool.php --testdox
```

### Test Coverage

| Test File | What It Verifies |
|-----------|-----------------|
| `TestChunker.php` | Token-aware chunking, overlap, Q&A single-chunk |
| `TestRetrievalRanking.php` | Cosine similarity math, Q&A boost priority |
| `TestSystemPromptBuilder.php` | Prompt structure, placeholder substitution |
| `TestGuardrails.php` | 3-layer guardrail acceptance tests (7 spec cases) |
| `TestKeyPool.php` | 429 cooldown, invalid key, 5xx retry, exhaustion |
| `TestEmailQueue.php` | WordPress wp_mail delivery, idempotency, retry, logging |

---

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

© 2024 IQronix & Antigravity
