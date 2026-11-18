/**
 * AI Chatbot Frontend Widget
 * Pure Vanilla JavaScript with zero external runtime dependencies.
 */

(function () {
  'use strict';

  /** Hard cap on message length, mirrored by the textarea's maxlength. */
  const MAX_LENGTH = 2000;

  /** Reveal the character counter once this many characters remain. */
  const COUNTER_THRESHOLD = 200;

  /** Monotonic counter used to keep widget ids unique on one page. */
  let instanceCount = 0;

  /** Built-in fallback starter questions per supported language. */
  const DEFAULT_STARTER_QUESTIONS = {
    de: [
      { id: 'q1', text: 'Welche Dienstleistungen bieten Sie an?', icon: 'chat' },
      { id: 'q2', text: 'Was kosten Ihre Dienstleistungen?', icon: 'sparkles' },
      { id: 'q3', text: 'Wie sind Ihre Geschäftszeiten?', icon: 'clock' },
      { id: 'q4', text: 'Wie kann ich Ihr Team kontaktieren?', icon: 'user' }
    ],
    en: [
      { id: 'q1', text: 'What services do you provide?', icon: 'chat' },
      { id: 'q2', text: 'How much do your services cost?', icon: 'sparkles' },
      { id: 'q3', text: 'What are your business hours?', icon: 'clock' },
      { id: 'q4', text: 'How can I contact your team?', icon: 'user' }
    ],
    es: [
      { id: 'q1', text: '¿Qué servicios ofrecen?', icon: 'chat' },
      { id: 'q2', text: '¿Cuánto cuestan sus servicios?', icon: 'sparkles' },
      { id: 'q3', text: '¿Cuál es su horario de atención?', icon: 'clock' },
      { id: 'q4', text: '¿Cómo puedo contactar a su equipo?', icon: 'user' }
    ],
    ne: [
      { id: 'q1', text: 'तपाईंहरूले के-कस्ता सेवाहरू प्रदान गर्नुहुन्छ?', icon: 'chat' },
      { id: 'q2', text: 'तपाईंको सेवाहरूको शुल्क कति पर्छ?', icon: 'sparkles' },
      { id: 'q3', text: 'तपाईंको कार्यालय समय के हो?', icon: 'clock' },
      { id: 'q4', text: 'तपाईंको टोलीसँग कसरी सम्पर्क गर्न सकिन्छ?', icon: 'user' }
    ],
    fr: [
      { id: 'q1', text: 'Quels services proposez-vous ?', icon: 'chat' },
      { id: 'q2', text: 'Combien coûtent vos services ?', icon: 'sparkles' },
      { id: 'q3', text: 'Quels sont vos horaires d\'ouverture ?', icon: 'clock' },
      { id: 'q4', text: 'Comment puis-je contacter votre équipe ?', icon: 'user' }
    ],
    it: [
      { id: 'q1', text: 'Quali servizi offrite?', icon: 'chat' },
      { id: 'q2', text: 'Quanto costano i vostri servizi?', icon: 'sparkles' },
      { id: 'q3', text: 'Quali sono i vostri orari di apertura?', icon: 'clock' },
      { id: 'q4', text: 'Come posso contattare il vostro team?', icon: 'user' }
    ],
    pt: [
      { id: 'q1', text: 'Quais serviços vocês oferecem?', icon: 'chat' },
      { id: 'q2', text: 'Quanto custam os seus serviços?', icon: 'sparkles' },
      { id: 'q3', text: 'Qual é o horário de funcionamento?', icon: 'clock' },
      { id: 'q4', text: 'Como posso entrar em contato com a equipe?', icon: 'user' }
    ],
    hi: [
      { id: 'q1', text: 'आप कौन-सी सेवाएं प्रदान करते हैं?', icon: 'chat' },
      { id: 'q2', text: 'आपकी सेवाओं की लागत कितनी है?', icon: 'sparkles' },
      { id: 'q3', text: 'आपके काम करने का समय क्या है?', icon: 'clock' },
      { id: 'q4', text: 'मैं आपकी टीम से कैसे संपर्क कर सकता हूँ?', icon: 'user' }
    ]
  };

  // Global sound synthesizer using Web Audio API (Zero audio file downloads!).
  const SoundFx = {
    ctx: null,
    init() {
      if (!this.ctx && (window.AudioContext || window.webkitAudioContext)) {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        this.ctx = new AudioCtx();
      }
    },
    playTone(freq = 600, type = 'sine', duration = 0.08) {
      if (!this.ctx) this.init();
      if (!this.ctx) return;
      if (this.ctx.state === 'suspended') this.ctx.resume();

      const osc = this.ctx.createOscillator();
      const gain = this.ctx.createGain();

      osc.type = type;
      osc.frequency.setValueAtTime(freq, this.ctx.currentTime);
      gain.gain.setValueAtTime(0.08, this.ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.0001, this.ctx.currentTime + duration);

      osc.connect(gain);
      gain.connect(this.ctx.destination);

      osc.start();
      osc.stop(this.ctx.currentTime + duration);
    },
    playMessageSound() {
      this.playTone(520, 'sine', 0.1);
      setTimeout(() => this.playTone(780, 'sine', 0.12), 80);
    },
    playOpenSound() {
      this.playTone(440, 'triangle', 0.1);
    }
  };

  class AIChatbotWidget {
    constructor(container) {
      this.container = container;
      this.agentId = parseInt(container.getAttribute('data-agent-id') || '1', 10);
      this.mode = container.getAttribute('data-mode') || 'floating'; // 'floating' | 'embedded'

      this.config = window.aiChatbotConfig || {
        apiUrl: '/wp-json/ai-chatbot/v1',
        agentId: 1,
        siteName: 'AI Assistant',
        sounds: { enabled: false }
      };

      /*
       * Every widget on the page gets its own id suffix. Two widgets used to
       * emit the same `id="ai-chatbot-title"` / `id="ai-chatbot-input"`, which
       * silently breaks aria-labelledby, aria-describedby and <label for>,
       * since those resolve to the first match in the document.
       */
      this.uid = ++instanceCount;

      this.sessionId = this.getOrCreateSessionId();
      this.isOpen = this.mode === 'embedded';
      this.agent = null;
      this.messages = [];
      this.lastUserMessage = '';
      this.lastResponseSupportEmail = '';
      this.isLoading = false;
      // Holds the AbortController for the in-flight /chat request, so Stop can
      // cancel it. Null whenever nothing is pending.
      this.chatController = null;
      // Starter questions belong to the welcome screen only. They used to be
      // re-appended after every reply, so the same chips kept coming back for
      // the whole conversation.
      this.hasShownSuggestions = false;
      // The greeting is a welcome-screen affordance too, so it is shown
      // once from the inline profile and never duplicated by the
      // background profile refresh.
      this.hasShownGreeting = false;

      // Track visual viewport for keyboard-aware layout on mobile.
      this._visualViewport = window.visualViewport || null;
      this._keyboardOpen = false;
      this._keyboardHeight = 0;
      this._viewportHeight = 0;
      // Guards the one-time load badge so a slow /agent response
      // cannot trigger a second impression after the bootstrap
      // default already fired.
      this._loadBadgeShown = false;

      this.currentLanguage = (this.config.language && this.config.language.default_language) || 'en';
      if (this.config.language && this.config.language.mode === 'fixed') {
        this.currentLanguage = this.config.language.fixed_language || this.currentLanguage;
      }
      this.allUiStrings = this.config.allUiStrings || (this.config.agent && this.config.agent.all_ui_strings) || {};
      this.uiStrings = this.config.uiStrings || (this.config.agent && this.config.agent.ui_strings) || (this.allUiStrings[this.currentLanguage] || {});

      /*
       * Paint the launcher and window immediately. The server
       * localizes placement, theme, sounds and language — and the
       * full public agent profile — into aiChatbotConfig, so the
       * chatbot is clickable and already shows the right name,
       * status, greeting and starter questions without waiting for
       * a network round-trip. loadAgent() still revalidates in the
       * background so changes saved in the dashboard are picked up
       * on the next fetch.
       */
      this.agent = this.buildInitialAgent();

      this.applyTheme(this.agent.theme);
      this.render();
      this.addInitialGreeting();
      this.loadAgent();
    }

    /**
     * Build the agent profile used for the first paint.
     *
     * The server inlines the public profile into aiChatbotConfig.agent,
     * so the launcher, window shell, theme, greeting and starter
     * questions are all correct without a fetch. When that inline
     * data is absent (a page cached before it existed), a minimal
     * profile built from the localized theme/placement settings and
     * the site name keeps the widget usable until loadAgent() fills
     * in the details.
     *
     * @return {object} Agent profile.
     */
    buildInitialAgent() {
      const cfg = this.config || {};
      const inline = cfg.agent && typeof cfg.agent === 'object' ? cfg.agent : null;
      const lang = (this.currentLanguage || 'en').toLowerCase().trim();
      const defaultQuestions = this.getStarterQuestions(lang);

      const base = {
        id: this.agentId,
        name: cfg.siteName || 'AI Assistant',
        status: 'online',
        custom_status_text: '',
        greeting: '',
        suggested_questions: defaultQuestions,
        max_questions_desktop: 4,
        max_questions_mobile: 2,
        show_on_welcome: true,
        company_name: cfg.siteName || '',
        placement: cfg.placement || {},
        theme: cfg.theme || {},
        sounds: cfg.sounds || {},
        badge: cfg.badge || {},
        skills: { contact_human: false, follow_up: {} }
      };

      if (!inline) return base;

      const merged = Object.assign(base, inline);
      if (!Array.isArray(merged.suggested_questions) || !merged.suggested_questions.length) {
        merged.suggested_questions = defaultQuestions;
      }
      return merged;
    }

    isConversationEmpty() {
      if (!this.messages || this.messages.length === 0) return true;
      return !this.messages.some(m => m.role === 'user');
    }

    getStarterQuestions(targetLang) {
      const lang = (targetLang || this.currentLanguage || 'en').toLowerCase().trim();
      const defaultLang = ((this.config?.language?.default_language) || 'en').toLowerCase().trim();

      // 1. If agent has custom suggested questions configured, prioritize them!
      if (Array.isArray(this.agent?.suggested_questions) && this.agent.suggested_questions.length) {
        const enabled = this.agent.suggested_questions.filter(q => q && q.text && q.enabled !== false);
        if (enabled.length) {
          return enabled;
        }
      }

      // 2. Check allStarterQuestions / starterQuestions localized config for target language
      const allLocalized = this.config?.allStarterQuestions || this.config?.starterQuestions || this.agent?.all_starter_questions || {};
      if (Array.isArray(allLocalized[lang]) && allLocalized[lang].length) {
        return allLocalized[lang];
      }

      // 3. Fallback to default language in localized config
      if (Array.isArray(allLocalized[defaultLang]) && allLocalized[defaultLang].length) {
        return allLocalized[defaultLang];
      }

      // 4. Check built-in defaults for target language
      if (DEFAULT_STARTER_QUESTIONS[lang] && DEFAULT_STARTER_QUESTIONS[lang].length) {
        return DEFAULT_STARTER_QUESTIONS[lang];
      }

      // 5. Fallback to default language built-in
      if (DEFAULT_STARTER_QUESTIONS[defaultLang] && DEFAULT_STARTER_QUESTIONS[defaultLang].length) {
        return DEFAULT_STARTER_QUESTIONS[defaultLang];
      }

      // 6. Final fallback to English built-in
      return DEFAULT_STARTER_QUESTIONS.en;
    }

    createNewSessionId() {
      const storageKey = 'ai_chatbot_sess_' + this.agentId;
      const id = 'sess_' + Math.random().toString(36).substring(2, 12) + '_' + Date.now();
      localStorage.setItem(storageKey, id);
      return id;
    }

    startNewChat() {
      this.cancelPendingReply();
      this.messages = [];
      this.lastUserMessage = '';
      this.hasShownGreeting = false;
      this.hasShownSuggestions = false;

      if (this.chatBody) {
        this.chatBody.innerHTML = '';
      }

      this.sessionId = this.createNewSessionId();
      this.addInitialGreeting();
      this.clearInput();

      if (this.textInput && window.innerWidth > 600) {
        this.textInput.focus();
      }
    }

    getOrCreateSessionId() {
      const storageKey = 'ai_chatbot_sess_' + this.agentId;
      let id = localStorage.getItem(storageKey);
      if (!id) {
        id = this.createNewSessionId();
      }
      return id;
    }

    /**
     * Fetch the live agent configuration without blocking the UI.
     *
     * The widget is already on screen from the constructor; this only
     * fills in agent-specific content. Merging over the bootstrap
     * defaults (rather than replacing) keeps the on-screen UI intact
     * if the payload is ever partial.
     */
    async loadAgent(retryCount = 0) {
      try {
        const res = await fetch(`${this.config.apiUrl}/agent?agent_id=${this.agentId}`, {
          cache: 'no-store',
          headers: this.config.nonce ? { 'X-WP-Nonce': this.config.nonce } : {}
        });
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}`);
        }
        const data = await res.json();
        if (data.success && data.data) {
          if (data.data.all_ui_strings) {
            this.allUiStrings = Object.assign(this.allUiStrings || {}, data.data.all_ui_strings);
          }
          if (data.data.ui_strings) {
            this.uiStrings = data.data.ui_strings;
          }
          if (data.data.all_starter_questions) {
            this.config.allStarterQuestions = data.data.all_starter_questions;
          }
          this.agent = Object.assign(this.agent, data.data);
          this.applyTheme(this.agent.theme);
          this.updateHeader();
          this.updateLocalizedUi();
          this.addInitialGreeting();
          this.initBadge();

          // Refresh starter questions if conversation is empty
          if (this.isConversationEmpty()) {
            this.renderSuggestedQuestions(this.agent.suggested_questions);
          }
        }
      } catch (err) {
        console.warn('AI Chatbot: loadAgent notice:', err.message);
        if (retryCount < 1) {
          setTimeout(() => this.loadAgent(retryCount + 1), 1500);
        } else {
          if (this.isConversationEmpty() && !this.chatBody?.querySelector('.ai-suggested-questions')) {
            this.renderSuggestedQuestions();
          }
        }
      }
    }

    /**
     * Patch the header in place once the real agent data arrives.
     *
     * Re-rendering the whole window would wipe the transcript and drop
     * the open state, so only the name, status and avatar are updated.
     */
    updateHeader() {
      if (!this.windowElement) return;

      const title = this.windowElement.querySelector('.ai-chatbot-header-text h3');
      if (title) title.textContent = this.agent.name;

      const statusDot = this.windowElement.querySelector('.ai-chatbot-status-dot');
      const statusText = this.windowElement.querySelector('.ai-chatbot-status-row span:last-child');
      const dotClass = this.agent.status === 'away' ? 'ai-chatbot-status-away' : (this.agent.status === 'offline' ? 'ai-chatbot-status-offline' : '');
      const statusKey = this.agent.status === 'away' ? 'status_away' : (this.agent.status === 'offline' ? 'status_offline' : 'status_online');
      const defaultStatusLabel = this.t(statusKey, this.agent.status === 'online' ? 'Online' : (this.agent.status === 'away' ? 'Away' : 'Offline'));
      const label = this.agent.custom_status_text || defaultStatusLabel;
      if (statusDot) statusDot.className = `ai-chatbot-status-dot ${dotClass}`;
      if (statusText) statusText.textContent = label;

      const avatar = this.windowElement.querySelector('.ai-chatbot-avatar');
      if (avatar) {
        if (this.agent.theme?.avatar_url) {
          avatar.innerHTML = `<img src="${this.escapeAttr(this.agent.theme.avatar_url)}" alt="">`;
        } else {
          avatar.textContent = this.escapeHtml(this.agent.name.charAt(0));
        }
      }

      if (this.launcherBtn) {
        this.launcherBtn.setAttribute('aria-label', `Chat with ${this.agent.name}`);

        // Keep the launcher face in sync with the header when an
        // avatar is configured after first paint.
        const launcherAvatarUrl = (this.agent.theme?.avatar_url || '').trim();
        const launcherAvatar = this.launcherBtn.querySelector('.ai-chatbot-launcher-avatar');
        if (launcherAvatarUrl) {
          if (launcherAvatar) {
            launcherAvatar.src = launcherAvatarUrl;
          } else if (!this.launcherBtn.classList.contains('ai-chatbot-launcher-has-avatar')) {
            this.launcherBtn.classList.add('ai-chatbot-launcher-has-avatar');
            const badge = this.launcherBtn.querySelector('.ai-chatbot-badge');
            this.launcherBtn.innerHTML =
              `<img class="ai-chatbot-launcher-avatar" src="${this.escapeAttr(launcherAvatarUrl)}" alt="" />` +
              (badge ? badge.outerHTML : '<span class="ai-chatbot-badge" aria-hidden="true"></span>');
            this.badgeEl = this.launcherBtn.querySelector('.ai-chatbot-badge');
          }
        }
      }
    }

    /* ---------------------------------------------------------------
       Mobile virtual-keyboard handling via the Visual Viewport API.
       --------------------------------------------------------------- */
    initKeyboardHandling() {
      if (this._keyboardInitialized) return;
      this._keyboardInitialized = true;

      const updateMetrics = () => {
        if (!this.windowElement || this.mode === 'embedded') return;

        const isMobile = window.innerWidth <= 600 || (window.innerHeight <= 500 && window.innerWidth <= 960);
        const vv = window.visualViewport;
        const root = document.documentElement;

        if (vv) {
          const height = Math.round(vv.height);
          const top = Math.max(0, Math.round(vv.offsetTop || 0));

          root.style.setProperty('--ai-viewport-height', `${height}px`);
          root.style.setProperty('--ai-viewport-top', `${top}px`);

          // Reliable keyboard detection:
          // Visual viewport significantly smaller than layout viewport,
          // and focus is inside the chatbot window.
          const isLandscape = window.innerWidth > window.innerHeight;
          const threshold = isLandscape ? 70 : 130;
          const heightLoss = window.innerHeight - height;
          const hasChatFocus = !!(this.windowElement && this.windowElement.contains(document.activeElement));
          const isKeyboard = heightLoss > threshold && hasChatFocus;

          if (isKeyboard !== this._keyboardOpen) {
            this._keyboardOpen = isKeyboard;
            this._keyboardHeight = isKeyboard ? heightLoss : 0;
            this.windowElement.classList.toggle('ai-keyboard-open', isKeyboard);

            if (isKeyboard) {
              window.setTimeout(() => this.scrollToBottom(true), 50);
            }
          }

          // If mobile keyboard is open and focus is inside chatbot, prevent the background page
          // from scrolling unexpectedly
          if (this.isOpen && isMobile && hasChatFocus) {
            window.scrollTo(0, 0);
          }
        } else {
          root.style.setProperty('--ai-viewport-height', `${window.innerHeight}px`);
          root.style.setProperty('--ai-viewport-top', '0px');
        }
      };

      this._updateViewportMetrics = updateMetrics;

      if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', updateMetrics);
        window.visualViewport.addEventListener('scroll', updateMetrics);
      }
      window.addEventListener('resize', updateMetrics);
      window.addEventListener('orientationchange', () => {
        window.setTimeout(updateMetrics, 100);
      });

      updateMetrics();
    }

    handleViewportResize() {
      this._updateViewportMetrics?.();
    }

    handleViewportScroll() {
      this._updateViewportMetrics?.();
    }

    /**
     * Scroll the composer and chat into view above the virtual keyboard.
     */
    scrollInputIntoView() {
      const isMobile = window.innerWidth <= 600 || (window.innerHeight <= 500 && window.innerWidth <= 960);
      if (isMobile) {
        window.scrollTo(0, 0);
      }
      this._updateViewportMetrics?.();
      this.scrollToBottom(true);
    }

    applyTheme(theme) {
      if (!theme) return;
      const root = document.documentElement;
      if (theme.primary_color) root.style.setProperty('--ai-primary', theme.primary_color);
      if (theme.secondary_color) root.style.setProperty('--ai-secondary', theme.secondary_color);
      if (theme.bg_color) root.style.setProperty('--ai-bg', theme.bg_color);
      if (theme.text_color) root.style.setProperty('--ai-text', theme.text_color);
      if (theme.bot_bubble_bg) root.style.setProperty('--ai-bot-bubble-bg', theme.bot_bubble_bg);
      if (theme.bot_bubble_text) root.style.setProperty('--ai-bot-bubble-text', theme.bot_bubble_text);
      if (theme.user_bubble_bg) root.style.setProperty('--ai-user-bubble-bg', theme.user_bubble_bg);
      if (theme.user_bubble_text) root.style.setProperty('--ai-user-bubble-text', theme.user_bubble_text);
      // border_radius can legitimately be 0; a truthy check would keep
      // the stylesheet default (16px) instead of applying sharp corners.
      if (theme.border_radius !== undefined && theme.border_radius !== null) {
        root.style.setProperty('--ai-radius', `${theme.border_radius}px`);
      }
      if (theme.font_family) root.style.setProperty('--ai-font', theme.font_family);

      // The dark class re-declares the bg/text/bubble variables on <body>,
      // which beats the inline values on <html>. It must be removed when
      // switching to any other preset, or the dark palette sticks.
      if (theme.preset === 'dark') {
        document.body.classList.add('ai-chatbot-dark');
      } else {
        document.body.classList.remove('ai-chatbot-dark');
      }
    }

    render() {
      this.container.innerHTML = '';

      if (this.mode === 'floating') {
        this.renderLauncher();
      }

      this.renderWindow();
    }

    renderLauncher() {
      const posClass = `ai-chatbot-pos-${this.agent.placement?.position || 'bottom_right'}`;
      const shapeClass = `ai-chatbot-shape-${this.agent.placement?.launcher_shape || 'circle'}`;

      const wrapper = document.createElement('div');
      wrapper.className = `ai-chatbot-launcher-wrapper ${posClass}`;

      const btn = document.createElement('button');
      btn.className = `ai-chatbot-launcher-btn ${shapeClass}`;
      btn.setAttribute('aria-label', `Chat with ${this.agent.name}`);

      /*
       * When an avatar is configured the launcher shows it instead of the
       * default chat bubble, so the button and the chat header carry the same
       * face. The built-in SVG is kept as the fallback so an avatar is never
       * required, and the URL is escaped because it comes from settings.
       */
      const avatarUrl = (this.agent.theme?.avatar_url || '').trim();

      // The badge is always part of the markup so the initial hidden state is
      // purely CSS. JavaScript only adds or removes the has-badge class.
      const badge = `<span class="ai-chatbot-badge" aria-hidden="true"></span>`;

      if (avatarUrl) {
        btn.classList.add('ai-chatbot-launcher-has-avatar');
        btn.innerHTML = `<img class="ai-chatbot-launcher-avatar" src="${this.escapeAttr(avatarUrl)}" alt="" />${badge}`;
      } else {
        btn.innerHTML = `
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
          </svg>
        ` + badge;
      }

      btn.addEventListener('click', () => this.toggleWindow());
      wrapper.appendChild(btn);
      document.body.appendChild(wrapper);
      this.launcherBtn = btn;
      this.badgeEl = btn.querySelector('.ai-chatbot-badge');

      this.initBadge();
    }

    /* ---------------------------------------------------------------
       Notification badge
       --------------------------------------------------------------- */

    /** Badge settings, normalised so callers never deal with undefined. */
    get badgeConfig() {
      const cfg = this.agent?.badge || {};
      return {
        enabled: cfg.enabled !== false,
        mode: cfg.mode || 'dot',
        showOnLoad: cfg.show_on_load !== false,
        loadDelayMs: Number.isFinite(+cfg.load_delay_ms) ? Math.max(0, +cfg.load_delay_ms) : 1200,
        nudgeAfterMs: Number.isFinite(+cfg.nudge_after_ms) ? Math.max(0, +cfg.nudge_after_ms) : 0,
        maxPerDay: Number.isFinite(+cfg.max_per_day) ? Math.max(1, +cfg.max_per_day) : 1
      };
    }

    /** localStorage key holding today's badge impressions. */
    get badgeStorageKey() {
      return `ai_chatbot_badge_${this.agentId}`;
    }

    /**
     * Read the stored impression state for today.
     *
     * @return {{date: string, count: number}} Stored state, or today's zero state.
     */
    readBadgeState() {
      const today = new Date().toISOString().slice(0, 10);

      try {
        const raw = window.localStorage.getItem(this.badgeStorageKey);
        const parsed = raw ? JSON.parse(raw) : null;

        if (parsed && parsed.date === today) {
          return parsed;
        }
      } catch (e) {
        // Private browsing or a blocked storage area: fall through to defaults.
      }

      return { date: today, count: 0 };
    }

    /**
     * Persist today's impression count.
     *
     * @param {number} count Impression count.
     */
    writeBadgeState(count) {
      try {
        window.localStorage.setItem(
          this.badgeStorageKey,
          JSON.stringify({ date: new Date().toISOString().slice(0, 10), count })
        );
      } catch (e) {
        // Storage unavailable: the badge still works for this page view.
      }
    }

    /**
     * Show, increment or hide the badge.
     *
     * @param {number|null} count  Number to display, or null to hide. 0 and 1
     *                            render as a dot.
     * @param {boolean}    pulse  Play the attention pulse.
     */
    setBadge(count = null, pulse = false) {
      const cfg = this.badgeConfig;

      if (!this.badgeEl || !cfg.enabled || cfg.mode === 'off') {
        return;
      }

      if (count === null || count <= 0) {
        this.badgeEl.classList.remove('ai-badge-dot', 'ai-badge-pulse');
        this.badgeEl.textContent = '';
        this.launcherBtn?.classList.remove('has-badge');
        this.badgeEl.setAttribute('aria-hidden', 'true');
        return;
      }

      // 'dot' mode never shows a number; 'count' falls back to a dot for 1.
      // 'dot' mode never shows a number. 'count' always shows one, including 1:
      // requiring count > 1 meant the load path (which passes the running
      // impression count) rendered a bare dot on the first visit of the day, so
      // "count" mode never actually showed a number.
      const showNumber = cfg.mode === 'count';

      this.badgeEl.textContent = showNumber ? (count > 99 ? '99+' : String(count)) : '';
      this.badgeEl.classList.toggle('ai-badge-dot', !showNumber);
      this.badgeEl.classList.remove('ai-badge-pulse');
      this.launcherBtn?.classList.add('has-badge');
      this.badgeEl.setAttribute('aria-hidden', 'true');

      // Announce the count for assistive tech rather than the empty badge.
      if (this.launcherBtn) {
        const base = `Chat with ${this.agent?.name || 'us'}`;
        this.launcherBtn.setAttribute('aria-label', showNumber ? `${base} (${count} unread)` : base);
      }

      if (pulse && !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        // Force a reflow so the animation restarts on consecutive bumps.
        void this.badgeEl.offsetWidth;
        this.badgeEl.classList.add('ai-badge-pulse');
      }
    }

    /** Hide the badge and reset today's impression counter. */
    clearBadge() {
      this.setBadge(null);
      this.writeBadgeState(0);
    }

    /**
     * Schedule the badge appearances.
     *
     * The load badge appears after a short delay so it does not compete with the
     * page hero on first paint. The optional nudge reappears later, but only if
     * the visitor has not already opened the panel, and only max_per_day times.
     */
    initBadge() {
      const cfg = this.badgeConfig;

      if (!cfg.enabled || cfg.mode === 'off') return;

      // The badge is initialised from the bootstrap defaults at first
      // paint and again once the real agent config arrives. Clear any
      // pending timers first so the second call does not stack a
      // duplicate load badge or nudge.
      window.clearTimeout(this.badgeTimer);
      window.clearTimeout(this.nudgeTimer);

      const state = this.readBadgeState();

      // The load badge is a one-time impression per page load. The flag
      // stops a slow /agent response from scheduling a second one after
      // the bootstrap default already fired.
      if (cfg.showOnLoad && state.count < cfg.maxPerDay && !this._loadBadgeShown) {
        // Show the running impression count rather than a hardcoded 1, so the
        // numeral reflects how many times the badge has appeared today.
        this.badgeTimer = window.setTimeout(() => {
          this._loadBadgeShown = true;
          const next = state.count + 1;
          this.setBadge(next);
          this.writeBadgeState(next);
        }, cfg.loadDelayMs);
      }

      if (cfg.nudgeAfterMs > 0) {
        this.nudgeTimer = window.setTimeout(() => {
          if (!this.isOpen) {
            // Re-read the counter: the load badge above may have already
            // incremented it, and using the stale closure value lost that.
            const current = this.readBadgeState();
            const next = (current.count || 0) + 1;
            this.setBadge(next, true);
            this.writeBadgeState(next);
          }
        }, cfg.nudgeAfterMs);
      }
    }

    /**
     * Public entry point for other code to raise the badge, for example when a
     * message arrives over a websocket or the page detects the user is idle.
     *
     * @param {number} [by] Amount to add. Defaults to 1.
     */
    bumpBadge(by = 1) {
      const cfg = this.badgeConfig;
      if (!cfg.enabled || cfg.mode === 'off') return;
      if (this.isOpen) return;

      const state = this.readBadgeState();
      this.setBadge((state.count || 0) + Math.max(1, by), true);
      this.writeBadgeState((state.count || 0) + Math.max(1, by));
    }

    /**
     * Escape a value used inside an HTML attribute.
     *
     * @param {string} value Raw value.
     * @return {string} Escaped value.
     */
    escapeAttr(value) {
      return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[c]));
    }

    /**
     * Escape a value used as HTML text.
     *
     * Agent name, status text and the avatar URL all come from the database and
     * were interpolated into innerHTML unescaped, so a value containing markup
     * would render as HTML.
     *
     * @param {string} value Raw value.
     * @return {string} Escaped value.
     */
    escapeHtml(value) {
      return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[c]));
    }

    t(key, fallback = '') {
      if (this.uiStrings && this.uiStrings[key]) {
        return this.uiStrings[key];
      }
      const langDict = this.allUiStrings && this.allUiStrings[this.currentLanguage];
      if (langDict && langDict[key]) {
        return langDict[key];
      }
      const enDict = this.allUiStrings && this.allUiStrings['en'];
      if (enDict && enDict[key]) {
        return enDict[key];
      }
      return fallback;
    }

    setLanguage(lang) {
      if (!lang || typeof lang !== 'string') return;
      const normalized = lang.toLowerCase().trim();
      this.currentLanguage = normalized;
      if (this.allUiStrings && this.allUiStrings[normalized]) {
        this.uiStrings = this.allUiStrings[normalized];
      }
      this.updateLocalizedUi();

      if (this.isConversationEmpty()) {
        const questions = this.getStarterQuestions(normalized);
        this.renderSuggestedQuestions(questions);
      }
    }

    updateLocalizedUi() {
      if (!this.windowElement) return;

      if (this.textInput) {
        this.textInput.placeholder = this.t('input_placeholder', 'Type your message...');
      }

      if (this.sendBtn) {
        const isBusy = this.isLoading;
        const label = isBusy ? this.t('stop_label', 'Stop generating') : this.t('send_label', 'Send message');
        this.sendBtn.setAttribute('aria-label', label);
        this.sendBtn.title = label;
      }

      const statusText = this.windowElement.querySelector('.ai-chatbot-status-row span:last-child');
      if (statusText && !this.agent?.custom_status_text) {
        const statusKey = this.agent?.status === 'away' ? 'status_away' : (this.agent?.status === 'offline' ? 'status_offline' : 'status_online');
        statusText.textContent = this.t(statusKey, this.agent?.status === 'online' ? 'Online' : (this.agent?.status === 'away' ? 'Away' : 'Offline'));
      }

      const hint = this.windowElement.querySelector('.ai-chatbot-sr-only[id^="ai-chatbot-input-hint"]');
      if (hint) {
        hint.textContent = this.t('edit_hint', 'Press Enter to send, Shift plus Enter for a new line.');
      }
    }

    renderWindow() {
      const isEmbedded = this.mode === 'embedded';
      const posClass = isEmbedded ? '' : `ai-chatbot-pos-${this.agent.placement?.position || 'bottom_right'}`;

      const titleId = `ai-chatbot-title-${this.uid}`;
      const bodyId = `ai-chatbot-body-${this.uid}`;
      const formId = `ai-input-form-${this.uid}`;
      const inputId = `ai-chatbot-input-${this.uid}`;
      const btnId = `ai-chatbot-send-btn-${this.uid}`;
      const hintId = `ai-chatbot-input-hint-${this.uid}`;
      const countId = `ai-chatbot-char-count-${this.uid}`;

      const win = document.createElement('div');
      win.className = `ai-chatbot-window ${posClass} ${isEmbedded ? 'ai-chatbot-embedded ai-active' : ''}`;
      win.setAttribute('role', 'dialog');
      win.setAttribute('aria-modal', 'true');
      win.setAttribute('aria-labelledby', titleId);

      const statusDotClass = this.agent.status === 'away' ? 'ai-chatbot-status-away' : (this.agent.status === 'offline' ? 'ai-chatbot-status-offline' : '');
      const statusKey = this.agent.status === 'away' ? 'status_away' : (this.agent.status === 'offline' ? 'status_offline' : 'status_online');
      const defaultStatusLabel = this.t(statusKey, this.agent.status === 'online' ? 'Online' : (this.agent.status === 'away' ? 'Away' : 'Offline'));
      const statusText = this.agent.custom_status_text || defaultStatusLabel;

      win.innerHTML = `
        <div class="ai-chatbot-header">
          <div class="ai-chatbot-header-info">
            <div class="ai-chatbot-avatar">
              ${this.agent.theme?.avatar_url ? `<img src="${this.escapeAttr(this.agent.theme.avatar_url)}" alt="">` : this.escapeHtml(this.agent.name.charAt(0))}
            </div>
            <div class="ai-chatbot-header-text">
              <h3 id="${titleId}">${this.escapeHtml(this.agent.name)}</h3>
              <div class="ai-chatbot-status-row">
                <span class="ai-chatbot-status-dot ${statusDotClass}" aria-hidden="true"></span>
                <span>${this.escapeHtml(statusText)}</span>
              </div>
            </div>
          </div>
          <div class="ai-chatbot-header-actions">
            <button type="button" class="ai-chatbot-action-btn ai-new-chat-btn" aria-label="${this.escapeAttr(this.t('new_chat_label', 'New Chat'))}" title="${this.escapeAttr(this.t('new_chat_label', 'New Chat'))}">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path>
                <path d="M21 3v5h-5"></path>
                <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path>
                <path d="M3 21v-5h5"></path>
              </svg>
            </button>
            ${!isEmbedded ? `
              <button class="ai-chatbot-action-btn ai-close-btn" aria-label="Close Chat">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="18" y1="6" x2="6" y2="18"></line>
                  <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
              </button>
            ` : ''}
          </div>
        </div>
        <div class="ai-chatbot-body" id="${bodyId}" role="log" aria-live="polite" aria-atomic="false"></div>
        <div class="ai-chatbot-footer">
          <form class="ai-input-form" id="${formId}" novalidate>
            <label class="ai-chatbot-sr-only" for="${inputId}">Your message</label>
            <textarea
              class="ai-chatbot-input"
              id="${inputId}"
              name="message"
              rows="1"
              placeholder="${this.escapeAttr(this.t('input_placeholder', 'Type your message...'))}"
              autocomplete="off"
              autocapitalize="sentences"
              spellcheck="true"
              enterkeyhint="send"
              maxlength="${MAX_LENGTH}"
              aria-describedby="${hintId}"
              required
            ></textarea>
            <button type="submit" class="ai-chatbot-send-btn" id="${btnId}" aria-label="${this.escapeAttr(this.t('send_label', 'Send message'))}" disabled>
              <svg class="ai-send-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <line x1="22" y1="2" x2="11" y2="13"></line>
                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
              </svg>
              <svg class="ai-stop-icon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false">
                <rect x="6" y="6" width="12" height="12" rx="2"></rect>
              </svg>
            </button>
          </form>
          <p class="ai-chatbot-sr-only" id="${hintId}">${this.escapeHtml(this.t('edit_hint', 'Press Enter to send, Shift plus Enter for a new line.'))}</p>
          <div class="ai-chatbot-composer-meta">
            <span class="ai-chatbot-char-count" id="${countId}" hidden></span>
          </div>
        </div>
      `;

      if (!isEmbedded) {
        document.body.appendChild(win);
        const closeBtn = win.querySelector('.ai-close-btn');
        if (closeBtn) {
          closeBtn.addEventListener('click', () => this.toggleWindow(false));
        }
      } else {
        this.container.appendChild(win);
      }

      const newChatBtn = win.querySelector('.ai-new-chat-btn');
      if (newChatBtn) {
        newChatBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          this.startNewChat();
        });
      }

      this.windowElement = win;
      // Classes, not ids: several widgets can be embedded on one page and an id
      // selector would return whichever one happens to be first in the
      // document.
      this.chatBody = win.querySelector('.ai-chatbot-body');
      this.charCount = win.querySelector('.ai-chatbot-char-count');

      // Bubble images are injected via innerHTML, so clicks are
      // delegated from the message log rather than bound per image.
      if (this.chatBody) {
        this.chatBody.addEventListener('click', (e) => {
          const img = e.target.closest('.ai-bubble-image');
          if (img && this.chatBody.contains(img)) {
            this.openLightbox(img.getAttribute('src'));
          }
        });
      }

      this.setupComposer();
    }

    /**
     * Wire up the message composer.
     *
     * All composer behaviour lives here so it can be read in one place:
     *   - submit (button click, or Enter in the field)
     *   - Enter sends, Shift+Enter inserts a newline
     *   - the send button is disabled only while the field is empty
     *   - while a reply is pending the same button becomes Stop
     *   - the field auto-grows and the counter updates as the value changes
     *   - Escape cancels a pending reply, otherwise it blurs the field
     */
    setupComposer() {
      const win = this.windowElement;

      this.inputForm = win.querySelector('.ai-input-form');
      this.textInput = win.querySelector('.ai-chatbot-input');
      this.sendBtn = win.querySelector('.ai-chatbot-send-btn');

      if (!this.inputForm || !this.textInput || !this.sendBtn) return;

      this.initKeyboardHandling();
      this.textInput.addEventListener('focus', () => {
        const isMobile = window.innerWidth <= 600 || (window.innerHeight <= 500 && window.innerWidth <= 960);
        if (isMobile) {
          window.scrollTo(0, 0);
        }
        // Defer so the browser has time to open the keyboard first.
        window.setTimeout(() => this.scrollInputIntoView(), 80);
      });

      // Scoped by querySelector inside this window, so several widgets on one
      // page each keep their own composer state.
      this.inputForm.addEventListener('submit', (e) => {
        e.preventDefault();

        // Enter while a reply is pending is a no-op rather than a cancel. The
        // visitor is most likely trying to queue a follow-up, and silently
        // discarding the in-flight answer would be surprising.
        if (this.isLoading) return;

        this.handleUserSend();
      });

      /*
       * While a reply is pending the send button doubles as Stop, so the click
       * has to cancel instead of submitting. preventDefault stops the button's
       * native submit, which would otherwise be swallowed by the guard above.
       */
      this.sendBtn.addEventListener('click', (e) => {
        if (!this.isLoading) return;

        e.preventDefault();
        this.cancelPendingReply();
      });

      /*
       * Enter submits, Shift+Enter keeps a newline.
       *
       * A <textarea> does not submit on Enter, so the submit event alone would
       * make the keyboard hint a lie. Paste, autofill and mobile "Go"/"Send"
       * keys all still arrive as a real submit event on the form above.
       */
      this.textInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
          // Never hijack Enter while an IME is composing, or it swallows the
          // keystroke that confirms a candidate in Japanese/Chinese input.
          if (e.isComposing || e.keyCode === 229) return;

          e.preventDefault();
          this.handleUserSend();
          return;
        }

        if (e.key === 'Escape') {
          // Escape is the keyboard route to the same action as the Stop button,
          // so the control is reachable without a pointer.
          if (this.isLoading) {
            e.preventDefault();
            this.cancelPendingReply();
            return;
          }

          this.textInput.blur();
        }
      });

      // One handler for every value change: typing, paste, cut, drag-drop,
      // undo, dictation and autofill all fire 'input'.
      this.textInput.addEventListener('input', () => {
        this.autoGrowInput();
        this.updateSendState();
        this.updateCharCount();
      });

      this.autoGrowInput();
      this.updateSendState();
      this.updateCharCount();
    }

    /**
     * Grow the field with its content, up to the CSS max-height.
     *
     * The reset-then-assign sequence is required: shrinking to auto first lets
     * the box collapse back down when text is deleted.
     */
    autoGrowInput() {
      const el = this.textInput;
      if (!el) return;

      el.style.height = 'auto';
      // scrollHeight already stops at max-height because the CSS caps the box
      // and overflow-y is set to auto.
      el.style.height = `${el.scrollHeight}px`;
    }

    /** Enable the send button only when there is something to send. */
    updateSendState() {
      if (!this.sendBtn) return;

      /*
       * While busy the button is Stop, not Send, so it has to stay enabled.
       * Gating it on isLoading here would make cancelling impossible through
       * the only visible control.
       */
      const hasText = Boolean(this.textInput && this.textInput.value.trim());
      this.sendBtn.disabled = this.isLoading ? false : !hasText;
    }

    /** Show a live character counter, but only once the limit is in reach. */
    updateCharCount() {
      if (!this.charCount || !this.textInput) return;

      const max = parseInt(this.textInput.getAttribute('maxlength'), 10) || MAX_LENGTH;
      const length = this.textInput.value.length;
      const remaining = max - length;

      // Hidden until the visitor is close to the limit, so the footer stays
      // quiet during normal use.
      this.charCount.hidden = remaining > COUNTER_THRESHOLD;
      this.charCount.textContent = `${remaining}`;
      this.charCount.classList.toggle('ai-warn', remaining <= 10);
    }

    /**
     * Clear the composer.
     *
     * Centralised because the value is also written by the starter-question
     * chips; resetting the height and the send state here keeps every path in
     * sync.
     */
    clearInput() {
      if (!this.textInput) return;

      this.textInput.value = '';
      this.autoGrowInput();
      this.updateSendState();
      this.updateCharCount();
    }

    toggleWindow(force) {
      if (this.mode === 'embedded') return;
      this.isOpen = typeof force === 'boolean' ? force : !this.isOpen;

      const isMobile = window.innerWidth <= 600 || (window.innerHeight <= 500 && window.innerWidth <= 960);

      if (this.isOpen) {
        this.windowElement.classList.add('ai-active');
        this._updateViewportMetrics?.();

        if (isMobile) {
          this._savedScrollY = window.scrollY || window.pageYOffset || 0;
          document.documentElement.classList.add('ai-chatbot-mobile-open');
          document.body.classList.add('ai-chatbot-mobile-open');
          document.body.style.top = `-${this._savedScrollY}px`;
        }

        // Ensure starter questions are visible whenever chat opens on an empty conversation
        if (this.isConversationEmpty() && !this.chatBody?.querySelector('.ai-suggested-questions')) {
          this.renderSuggestedQuestions(this.agent?.suggested_questions);
        }

        // Opening the panel counts as having seen the badge and the alert.
        this.clearBadge();
        this.launcherBtn?.classList.remove('has-attention');
        this.stopTitleFlash();
        window.clearTimeout(this.badgeTimer);
        window.clearTimeout(this.nudgeTimer);

        if (this.config.sounds?.enabled && this.config.sounds?.open_sound) {
          SoundFx.playOpenSound();
        }

        /*
         * Auto-focus the input field on desktop for keyboard users.
         * On mobile touch devices, skip auto-focus to prevent the virtual
         * keyboard from immediately popping up, shifting the viewport, and
         * obscuring the greeting and suggested questions.
         */
        if (!isMobile) {
          this.focusTimer = window.setTimeout(() => {
            this.textInput?.focus();
          }, 80);
        }
      } else {
        this.windowElement.classList.remove('ai-active');
        this.windowElement.classList.remove('ai-keyboard-open');
        this._keyboardOpen = false;

        document.documentElement.classList.remove('ai-chatbot-mobile-open');
        document.body.classList.remove('ai-chatbot-mobile-open');
        document.body.style.top = '';
        if (typeof this._savedScrollY === 'number') {
          window.scrollTo(0, this._savedScrollY);
        }

        window.clearTimeout(this.focusTimer);

        // Hand focus back to the launcher so keyboard users are not dropped on
        // <body> when the panel closes.
        this.launcherBtn?.focus();
      }
    }

    addInitialGreeting() {
      if (this.agent.greeting && !this.hasShownGreeting) {
        this.hasShownGreeting = true;
        this.captureTitle();

        // Tagged so the welcome message gets its own, slightly richer entrance
        // animation. Later replies keep the subtle default fade.
        // isGreeting keeps the greeting from counting as an unread message.
        const el = this.appendMessage('assistant', this.agent.greeting, null, false, { isGreeting: true });
        if (el) {
          el.classList.add('ai-greeting');
        }
      }

      // Render Suggested Questions on welcome screen.
      if (this.agent.show_on_welcome !== false && this.isConversationEmpty()) {
        this.renderSuggestedQuestions(this.agent.suggested_questions);
      }
    }

    /**
     * Render one message into the transcript.
     *
     * @param {string}  role         'user' or 'assistant'.
     * @param {string}  text         Message text (light Markdown).
     * @param {?number} id           Stored message id, enables feedback.
     * @param {boolean} offerContact Show the contact card.
     * @param {object}  options      { isGreeting } skips alert and badge.
     * @return {HTMLElement} The rendered message element.
     */
    appendMessage(role, text, id = null, offerContact = false, options = {}) {
      const msgDiv = document.createElement('div');
      msgDiv.className = `ai-message ai-message-${role}`;
      if (id) msgDiv.setAttribute('data-msg-id', id);

      const strText = String(text == null ? '' : text);
      this.messages.push({ role, text: strText });
      if (role === 'user') {
        this.lastUserMessage = strText;
      }

      const bubble = document.createElement('div');
      bubble.className = 'ai-bubble';
      this.renderRichText(bubble, text);
      msgDiv.appendChild(bubble);

      // Thumbs feedback on assistant replies.
      if (role === 'assistant' && id) {
        const feedback = document.createElement('div');
        feedback.className = 'ai-feedback-row';
        feedback.innerHTML = `
          <span>${this.escapeHtml(this.t('feedback_question', 'Was this helpful?'))}</span>
          <button class="ai-feedback-btn ai-fb-up" aria-label="👍">👍</button>
          <button class="ai-feedback-btn ai-fb-down" aria-label="👎">👎</button>
        `;

        feedback.querySelector('.ai-fb-up').addEventListener('click', () => this.sendFeedback(id, 'helpful', feedback));
        feedback.querySelector('.ai-fb-down').addEventListener('click', () => this.sendFeedback(id, 'unhelpful', feedback));
        msgDiv.appendChild(feedback);
      }

      // Contact form component injection is completely disabled per specification.
      // Bot responds strictly in plain text/Markdown without rendering form UI.

      this.chatBody.appendChild(msgDiv);
      this.scrollToBottom();

      /*
       * Every assistant message goes through one handler so the visual alert,
       * the notification sound and the badge update always happen together,
       * in the same tick, from a single call site.
       */
      if (role === 'assistant') {
        this.handleIncomingMessage(msgDiv, options);
      }

      // Returned so callers can tag the element, e.g. to give the greeting
      // its own entrance animation.
      return msgDiv;
    }

    /**
     * React to an incoming assistant message.
     *
     * All three reactions are triggered from this one function so they cannot
     * drift apart:
     *   1. the incoming message alert (bubble flash + launcher attention ring)
     *   2. the notification sound
     *   3. the notification badge
     *
     * They are started in the same synchronous block, so the CSS animations and
     * the badge pulse start on the same frame as the sound. The badge and the
     * alert are suppressed while the panel is open, because the visitor is
     * already looking at the message; the sound is not, so behaviour matches
     * what shipped before.
     *
     * @param {HTMLElement} msgDiv  The rendered message element.
     * @param {object}       options { isGreeting } to skip alert and badge.
     */
    handleIncomingMessage(msgDiv, options = {}) {
      const sounds = this.config?.sounds || {};

      // 1. Alert.
      if (!options.isGreeting) {
        this.playIncomingAlert(msgDiv);
      }

      // 2. Sound.
      if (sounds.enabled && sounds.message_sound) {
        SoundFx.playMessageSound();
      }

      // 3. Badge.
      if (!options.isGreeting && !this.isOpen) {
        this.bumpBadge();
      }
    }

    /**
     * Visual alert for an incoming message.
     *
     * Flashes the new bubble so the eye lands on it, and marks the launcher so
     * the panel is findable while it is closed.
     *
     * @param {HTMLElement} msgDiv The rendered message element.
     */
    playIncomingAlert(msgDiv) {
      const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

      if (msgDiv && !reduceMotion) {
        msgDiv.classList.remove('ai-message-alert');
        // Force a reflow so the animation restarts on consecutive messages.
        void msgDiv.offsetWidth;
        msgDiv.classList.add('ai-message-alert');
      }

      // The launcher rings whether or not motion is allowed, because this is a
      // state cue rather than decoration; it just does not animate.
      this.launcherBtn?.classList.add('has-attention');

      // A brief highlight if the visitor has the tab in the background.
      if (document.hidden) {
        this.flashTitle();
      }
    }

    /**
     * Prefix the page title while the tab is hidden, then restore it.
     *
     * @param {number} [limit] Maximum number of unread markers to show.
     */
    flashTitle(limit = 9) {
      if (this._titleFlashing) return;

      /*
       * Capture the pristine title here rather than relying on the greeting
       * path, otherwise stopTitleFlash() had nothing to restore and the tab
       * could stay stuck showing "(3) Page Title".
       */
      if (this._titleOriginal === undefined || this._titleOriginal === null) {
        this._titleOriginal = document.title;
      }

      const original = this._titleOriginal;
      let pending = 0;

      this._titleFlashing = window.setInterval(() => {
        pending += 1;

        document.title = pending > limit
          ? original
          : `(${pending}) ${original}`;

        if (pending > limit) {
          this.stopTitleFlash();
        }
      }, 1000);
    }

    /** Restore the page title and stop the unread marker. */
    stopTitleFlash() {
      if (!this._titleFlashing) return;

      window.clearInterval(this._titleFlashing);
      this._titleFlashing = null;

      if (this._titleOriginal !== undefined) {
        document.title = this._titleOriginal;
      }
    }

    /** Track the pristine title so it can be restored after flashing. */
    captureTitle() {
      if (this._titleOriginal === undefined) {
        this._titleOriginal = document.title;
      }
    }

    renderSuggestedQuestions(questions) {
      // Welcome screen only: never render if user has already sent a message
      if (!this.isConversationEmpty()) return;
      if (this.agent && this.agent.show_on_welcome === false) return;

      // Clean existing wrapper to prevent duplicate rendering
      this.chatBody?.querySelectorAll('.ai-suggested-questions').forEach(el => el.remove());

      const list = (Array.isArray(questions) && questions.length)
        ? questions
        : (Array.isArray(this.agent?.suggested_questions) && this.agent.suggested_questions.length
            ? this.agent.suggested_questions
            : this.getStarterQuestions(this.currentLanguage));

      const usable = (list || []).filter(q => q && q.text && q.enabled !== false);
      if (!usable.length) return;

      this.hasShownSuggestions = true;

      const isMobile = window.innerWidth <= 600;
      const limit = isMobile ? (this.agent.max_questions_mobile || 2) : (this.agent.max_questions_desktop || 4);
      const items = usable.slice(0, limit);

      const wrapper = document.createElement('div');
      wrapper.className = 'ai-suggested-questions';
      wrapper.setAttribute('role', 'group');
      wrapper.setAttribute('aria-label', this.t('suggested_questions_label', 'Suggested questions'));

      items.forEach((q, index) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ai-suggested-btn';
        btn.textContent = q.text;
        // Small stagger so the chips arrive one after another rather than
        // appearing as one flat block. Kept short so the welcome
        // screen feels instant even on slower devices.
        btn.style.animationDelay = `${40 + index * 35}ms`;
        btn.addEventListener('click', () => {
          // Routed through the input so maxlength and the send state stay
          // consistent with a typed message.
          this.textInput.value = String(q.text).slice(0, MAX_LENGTH);
          this.handleUserSend();
          wrapper.remove(); // Remove suggestions after click
        });
        wrapper.appendChild(btn);
      });

      this.chatBody.appendChild(wrapper);
      this.scrollToBottom();
    }

    showTyping() {
      if (this.typingDiv) return;
      const t = document.createElement('div');
      t.className = 'ai-typing-indicator';
      t.innerHTML = `
        <span class="ai-typing-dot"></span>
        <span class="ai-typing-dot"></span>
        <span class="ai-typing-dot"></span>
      `;
      this.chatBody.appendChild(t);
      this.typingDiv = t;
      this.scrollToBottom();
    }

    hideTyping() {
      if (this.typingDiv) {
        this.typingDiv.remove();
        this.typingDiv = null;
      }
    }

    scrollToBottom(forceInstant = false) {
      if (!this.chatBody) return;
      const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
      const isMobile = window.innerWidth <= 600 || (window.innerHeight <= 500 && window.innerWidth <= 960);

      if (forceInstant || reduce || isMobile) {
        this.chatBody.scrollTop = this.chatBody.scrollHeight;
      } else {
        this.chatBody.scrollTo({
          top: this.chatBody.scrollHeight,
          behavior: 'smooth'
        });
      }

      // Second tick ensures any pending browser reflow (e.g. font loading, textarea autogrow, keyboard shift)
      // finishes before locking the final scroll position.
      window.requestAnimationFrame(() => {
        if (this.chatBody) {
          this.chatBody.scrollTop = this.chatBody.scrollHeight;
        }
      });
    }

    /**
     * Render assistant text as light Markdown.
     *
     * Replies arrive as Markdown (bold labels, bullet lists and the source
     * URL the model is asked to cite), so showing it as raw text printed
     * literal asterisks and made cited pages unclickable.
     *
     * Everything is HTML-escaped before any formatting is applied, and links
     * are emitted as real anchors with rel="noopener", so model output can
     * never inject markup or script into the page.
     *
     * @param {HTMLElement} el   Target element.
     * @param {string}      text Raw reply text.
     */
    renderRichText(el, text) {
      const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[c]));

      // Clean raw text to ensure no placeholder artifacts, mock buttons or internal tokens appear
      // Also protect against broken partial Markdown while streaming
      let cleanRaw = String(text == null ? '' : text)
        .replace(/\[\[(?:LINK_|link:)\d+\]\]/gi, '')
        .replace(/\[\[(?:LINK_|link:)[^\]]*$/gi, '')
        .replace(/\[HUMAN_HANDOFF\]/gi, '')
        .replace(/\s*\(button\)/gi, '')
        .replace(/\s*\[button\]/gi, '')
        .replace(/\[(?:Contact\s+a\s+Human|Mit\s+einem\s+Menschen\s+sprechen)\]/gi, '')
        .replace(/(?:Contact\s+a\s+Human|Mit\s+einem\s+Menschen\s+sprechen)\s*\(button\)/gi, '')
        .replace(/\[([^\]\n]+)\]\([^)\n]*$/i, '$1') // prevent broken streaming link [label](http...
        .replace(/\[([^\]\n]+)$/i, '$1')            // prevent broken streaming link [label...
        .replace(/(?:\b(?:at|unter|auf|on|visit|besuchen\s+sie|siehe|check\s+out|finden\s+sie\s+unter)\s+)+([.,;:!?])/gi, '$1')
        .replace(/\s+([.,;:!?])/g, '$1')
        .replace(/\(\s*\)/g, '')
        .replace(/\[\s*\]/g, '')
        .trim();



      // Safe URL validation: only http(s), relative paths, mailto, or tel.
      // Explicitly block dangerous schemes (javascript:, data:, vbscript:).
      const isSafeLinkUrl = (url) => {
        const trimmed = String(url || '').trim().toLowerCase();
        if (/^(javascript:|data:|vbscript:)/i.test(trimmed)) {
          return false;
        }
        return /^(https?:\/\/|\/|\.\/|\.\.\/|mailto:|tel:)/i.test(trimmed);
      };

      const isSafeImageUrl = (url) => {
        const trimmed = String(url || '').trim().toLowerCase();
        if (/^(javascript:|vbscript:)/i.test(trimmed)) {
          return false;
        }
        return /^(https?:\/\/|\/|\.\/|\.\.\/|data:image\/)/i.test(trimmed);
      };

      const isInternalUrl = (url) => {
        if (/^(\/|\.\/|\.\.\/)/.test(url)) {
          return true;
        }
        try {
          const parsed = new URL(url, window.location.origin);
          return parsed.hostname === window.location.hostname;
        } catch (e) {
          return false;
        }
      };

      const isImageFile = (url) => /\.(png|jpe?g|gif|webp|svg|bmp|avif)(\?|#|$)/i.test(url);

      let html = escapeHtml(cleanRaw).trim();
      if (!html) {
        el.textContent = '';
        return;
      }

      // Fenced code blocks: ```lang\ncode\n```
      html = html.replace(/```(?:[a-zA-Z0-9_-]+)?\n?([\s\S]*?)```/g, (match, code) => {
        return `<pre><code>${code.trim()}</code></pre>`;
      });

      // Inline code, then bold, then italic.
      html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');
      html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
      html = html.replace(/(^|[^*\w])\*([^*\n]+)\*/g, '$1<em>$2</em>');

      // Markdown images: ![alt](url).
      html = html.replace(/!\[([^\]]*)\]\(((?:[^()\s]|\([^()\s]*\))+)(?:\s+[^)]*)?\)/g, (match, alt, url) => {
        if (!isSafeImageUrl(url)) {
          return alt;
        }
        return `<img class="ai-bubble-image" src="${url}" alt="${alt}" loading="lazy">`;
      });

      // Markdown links: [label](url). Handled strictly for verified markdown links.
      // All links open in a new tab with rel="noopener noreferrer".
      html = html.replace(/\[([^\]\n]+)\]\(((?:https?:\/\/|\/|\.\/|\.\.\/|mailto:|tel:)[^)\s]+)\)/gi, (match, label, url) => {
        if (!isSafeLinkUrl(url)) {
          return label;
        }
        return `<a class="ai-bubble-link" href="${url}" target="_blank" rel="noopener noreferrer">${label}</a>`;
      });

      // Auto-link bare URLs if safe, or render inline images if image file.
      // All links open in a new tab with rel="noopener noreferrer".
      html = html.replace(/(^|[^"'>])(https?:\/\/[^\s<]+)/g, (match, prefix, url) => {
        const trailing = url.match(/[.,;:!?)\]]+$/);
        let cleanUrl = url;
        let tail = '';

        if (trailing) {
          cleanUrl = url.slice(0, url.length - trailing[0].length);
          tail = trailing[0];
        }

        if (isSafeImageUrl(cleanUrl) && isImageFile(cleanUrl)) {
          return prefix + `<img class="ai-bubble-image" src="${cleanUrl}" alt="" loading="lazy">` + tail;
        }

        if (isSafeLinkUrl(cleanUrl)) {
          return prefix + `<a class="ai-bubble-link" href="${cleanUrl}" target="_blank" rel="noopener noreferrer">${cleanUrl}</a>` + tail;
        }

        return match;
      });

      // Block level: bullet / numbered lists, tables, headings (h1-h4), pre and paragraphs.
      const blocks = html.split(/\n{2,}/).filter((b) => b.trim() !== '');

      el.innerHTML = blocks.map((block) => {
        if (block.startsWith('<pre>') && block.endsWith('</pre>')) {
          return block;
        }

        const lines = block.split('\n').map((l) => l.trim()).filter((l) => l !== '');

        // Markdown Headings (h1 - h4)
        if (lines.length === 1) {
          const hMatch = lines[0].match(/^(#{1,4})\s+(.+)$/);
          if (hMatch) {
            const level = hMatch[1].length;
            return `<h${level}>${hMatch[2]}</h${level}>`;
          }
        }

        if (lines.length > 1 && lines.every((l) => /^[*-]\s+/.test(l))) {
          return '<ul>' + lines
            .map((l) => `<li>${l.replace(/^[*-]\s+/, '')}</li>`)
            .join('') + '</ul>';
        }

        if (lines.length > 1 && lines.every((l) => /^\d+[.)]\s+/.test(l))) {
          return '<ol>' + lines
            .map((l) => `<li>${l.replace(/^\d+[.)]\s+/, '')}</li>`)
            .join('') + '</ol>';
        }

        // Markdown tables: | col1 | col2 |
        if (lines.length >= 2 && lines.every((l) => /^\|.*\|$/.test(l))) {
          const contentRows = lines.filter((l) => !/^\|[\s\-:|]+\|$/.test(l));
          if (contentRows.length > 0) {
            const headerCells = contentRows[0]
              .split('|')
              .slice(1, -1)
              .map((c) => `<th>${c.trim()}</th>`)
              .join('');
            const bodyRows = contentRows
              .slice(1)
              .map((r) => {
                const cells = r
                  .split('|')
                  .slice(1, -1)
                  .map((c) => `<td>${c.trim()}</td>`)
                  .join('');
                return `<tr>${cells}</tr>`;
              })
              .join('');
            return `<table><thead><tr>${headerCells}</tr></thead><tbody>${bodyRows}</tbody></table>`;
          }
        }

        return `<p>${lines.join('<br>')}</p>`;
      }).join('');
    }

    async handleUserSend() {
      const text = this.textInput ? this.textInput.value.trim() : '';

      /*
       * Two guards, both needed:
       *   - empty/whitespace-only input must not produce a request
       *   - isLoading blocks a second send while a reply is pending, so Enter
       *     plus a click, or a repeated keypress, cannot fire duplicates
       */
      if (!text || this.isLoading) {
        // Focus the field so the visitor can keep typing instead of hitting a
        // dead button.
        if (!text) this.textInput?.focus();
        return;
      }

      // Cleanly remove any welcome suggestions when conversation starts
      this.chatBody?.querySelectorAll('.ai-suggested-questions').forEach(el => el.remove());

      // Read the value before clearing it: this is the only copy that survives
      // the reset.
      const payloadText = text.slice(0, MAX_LENGTH);

      this.clearInput();
      this.lastUserMessage = payloadText;
      const userEl = this.appendMessage('user', payloadText);
      this.setBusy(true);
      this.showTyping();

      /*
       * One controller per request, stored so the Stop button can abort it.
       * Created fresh each time because a controller cannot be reused once
       * aborted, and a cancelled request must not poison the next one.
       */
      this.chatController = new AbortController();
      const signal = this.chatController.signal;

      try {
        const res = await fetch(`${this.config.apiUrl}/chat`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          signal,
          body: JSON.stringify({
            message: payloadText,
            session_id: this.sessionId,
            agent_id: this.agentId
          })
        });

        // A PHP fatal or a caching layer can return HTML, which makes
        // res.json() throw and previously surfaced as a misleading
        // "cannot reach the server" message.
        const raw = await res.text();
        let data;
        try {
          data = JSON.parse(raw);
        } catch (parseErr) {
          console.error('AI Chatbot: non-JSON response from /chat', res.status, raw.slice(0, 200));
          data = { success: false, message: this.t('server_error', `Server error (${res.status}). Please try again.`) };
        }

        if (data.success && data.data) {
          const { reply, message_id, user_message_id, offer_contact, language, support_email } = data.data;
          if (support_email) {
            this.lastResponseSupportEmail = support_email;
          }
          if (language) {
            this.setLanguage(language);
          }
          this.appendMessage('assistant', reply, message_id, offer_contact, { userMessage: payloadText });

          /*
           * The visitor's own message id arrives with the reply, because it is
           * only known once the row has been inserted. Without it the pencil
           * cannot be wired to the bubble it belongs to, and the message would
           * be permanently uneditable.
           */
          if (user_message_id && userEl) {
            this.enableMessageEdit(userEl, user_message_id, payloadText);
          }

          /*
           * suggested_questions is intentionally ignored here. The API still
           * returns it, but the starter chips are a welcome-screen affordance;
           * re-adding them after every answer cluttered the transcript and
           * pushed the conversation down the panel.
           */
        } else {
          this.appendMessage('assistant', data.message || this.t('server_error', 'Sorry, an error occurred. Please try again.'));
        }
      } catch (err) {
        /*
         * Abort is a deliberate user action, not a failure, so it must not be
         * reported as one. Falling through to the generic error message would
         * tell the visitor their connection dropped at the moment they asked
         * for the request to stop.
         */
        if (err && err.name === 'AbortError') {
          this.showCancelledNotice();
        } else {
          console.error('AI Chatbot: /chat request failed', err);
          this.appendMessage('assistant', this.t('connection_error', 'Unable to reach the server. Please check your connection.'));
        }
      } finally {
        // Always clear the busy state, otherwise a failed or cancelled request
        // left the composer stuck showing the Stop button.
        this.hideTyping();
        this.chatController = null;
        this.setBusy(false);
      }
    }

    /**
     * Attach the edit affordance to one of the visitor's messages.
     *
     * The id is only known after the server has stored the message, so this is
     * called once the reply comes back rather than when the bubble is first
     * rendered. Until then the message is not editable, which is why the pencil
     * is hidden rather than shown disabled: an edit affordance on a message the
     * server has not acknowledged would fail on submit.
     *
     * @param {HTMLElement} msgEl  The user message wrapper.
     * @param {number}      msgId  Stored message id.
     * @param {string}      text   Current message text.
     */
    enableMessageEdit(msgEl, msgId, text) {
      if (!msgEl || msgEl.dataset.editable === '1') return;

      msgEl.dataset.editable = '1';
      msgEl.dataset.msgId = String(msgId);

      const bubble = msgEl.querySelector('.ai-bubble');
      if (!bubble) return;

      const actions = document.createElement('div');
      actions.className = 'ai-message-actions';

      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'ai-message-edit-btn';
      const editLabel = this.t('edit_label', 'Edit message');
      editBtn.setAttribute('aria-label', editLabel);
      editBtn.title = editLabel;
      editBtn.innerHTML = `
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
          <path d="M12 20h9"></path>
          <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
        </svg>
      `;

      editBtn.addEventListener('click', () => {
        // Never edit mid-flight: the pending reply is about to be appended, and
        // discarding a branch underneath an in-flight request would leave the
        // transcript and the server disagreeing about what exists.
        if (this.isLoading) return;
        this.openMessageEditor(msgEl, msgId, text);
      });

      actions.appendChild(editBtn);
      msgEl.appendChild(actions);

      msgEl._aiText = text;
    }

    /**
     * Replace a message bubble with an inline editor.
     *
     * Modelled on ChatGPT and Gemini: the bubble becomes a textarea in place, with
     * Cancel and Send beneath it. Editing in place rather than in the composer
     * keeps the message being edited visually anchored, which matters when the
     * edited message is several turns back.
     *
     * @param {HTMLElement} msgEl  The user message wrapper.
     * @param {number}      msgId  Stored message id.
     * @param {string}      text   Current message text.
     */
    openMessageEditor(msgEl, msgId, text) {
      // Only one editor at a time: a second would leave the first message stuck
      // in its editing state with no visible way back.
      if (this.editingMessage) this.editingMessage.close();

      const bubble = msgEl.querySelector('.ai-bubble');
      const actions = msgEl.querySelector('.ai-message-actions');
      if (!bubble) return;

      msgEl.classList.add('is-editing');

      const editor = document.createElement('div');
      editor.className = 'ai-message-editor';

      const ta = document.createElement('textarea');
      ta.className = 'ai-message-edit-input';
      ta.value = text;
      ta.rows = Math.min(8, Math.max(1, text.split('\n').length + 1));
      ta.maxLength = MAX_LENGTH;
      ta.setAttribute('aria-label', 'Edit your message');
      ta.spellcheck = true;

      const hint = document.createElement('div');
      hint.className = 'ai-message-edit-hint';
      hint.id = `ai-edit-hint-${this.uid}`;
      ta.setAttribute('aria-describedby', hint.id);
      hint.textContent = this.t('edit_hint', 'Enter to send, Shift + Enter for a new line, Escape to cancel.');

      const footer = document.createElement('div');
      footer.className = 'ai-message-edit-footer';

      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'ai-btn ai-btn-secondary ai-btn-sm';
      cancelBtn.textContent = this.t('edit_cancel', 'Cancel');

      const sendBtn = document.createElement('button');
      sendBtn.type = 'button';
      sendBtn.className = 'ai-btn ai-btn-primary ai-btn-sm';
      sendBtn.textContent = this.t('edit_send', 'Send');
      sendBtn.setAttribute('aria-label', 'Save and resend message');

      footer.appendChild(cancelBtn);
      footer.appendChild(sendBtn);
      editor.appendChild(ta);
      editor.appendChild(hint);
      editor.appendChild(footer);

      // Hide the bubble rather than removing it, so cancelling can restore the
      // original rendered HTML exactly, including any links and lists.
      bubble.style.display = 'none';
      if (actions) actions.style.display = 'none';
      msgEl.appendChild(editor);

      const syncHeight = () => {
        ta.style.height = 'auto';
        ta.style.height = `${Math.min(ta.scrollHeight, 220)}px`;
      };

      /*
       * Send is disabled while the field is empty or the text is unchanged from
       * the original, mirroring how the main composer disables its send button.
       * Both are no-ops server-side as well; disabling here means the visitor is
       * not invited to click something that will do nothing.
       *
       * Note this compares against the live `text` argument, so re-editing after
       * a failed request compares against the message as it now stands.
       */
      const syncSendState = () => {
        const current = ta.value.trim();
        sendBtn.disabled = current === '' || current === text.trim();
      };

      const onEditInput = () => {
        syncHeight();
        syncSendState();
      };

      syncHeight();
      syncSendState();

      const finish = (commit) => {
        ta.removeEventListener('input', onEditInput);
        editor.remove();
        bubble.style.display = '';
        if (actions) actions.style.display = '';
        msgEl.classList.remove('is-editing');
        this.editingMessage = null;
        if (!commit) sendBtn.focus();
      };

      // Registered after finish exists, so both the guard above and the Escape
      // handler can reach it.
      this.editingMessage = { el: msgEl, close: () => finish(false) };

      cancelBtn.addEventListener('click', () => finish(false));

      sendBtn.addEventListener('click', () => this.submitMessageEdit(msgEl, msgId, ta.value, finish));

      ta.addEventListener('input', onEditInput);

      ta.addEventListener('keydown', (e) => {
        // Same IME guard as the main composer: hijacking Enter mid-composition
        // would swallow the keystroke that confirms a candidate.
        if (e.key === 'Enter' && !e.shiftKey) {
          if (e.isComposing || e.keyCode === 229) return;
          e.preventDefault();
          this.submitMessageEdit(msgEl, msgId, ta.value, finish);
          return;
        }

if (e.key === 'Escape') {
          e.preventDefault();
          // An open inline editor takes precedence: Escape abandons the edit
          // rather than blurring the composer behind it.
          if (this.editingMessage) {
            this.editingMessage.close();
            return;
          }
          this.textInput?.blur();
        }
      });

      ta.focus();
      // Place the caret at the end rather than selecting: visitors usually
      // append a word or fix the tail, they do not rewrite the whole question.
      ta.setSelectionRange(ta.value.length, ta.value.length);
    }

    /**
     * Persist an edited message and regenerate the discarded branch.
     *
     * The messages after this one are removed from the DOM first, because the
     * server does the same: the replies that followed were answers to the old
     * wording. Doing it in the same tick as the request keeps the transcript
     * from briefly showing a stale answer to a question that no longer exists.
     *
     * @param {HTMLElement} msgEl   The user message wrapper.
     * @param {number}      msgId   Stored message id.
     * @param {string}      value   Edited text.
     * @param {Function}    finish  Closes the editor.
     */
    async submitMessageEdit(msgEl, msgId, value, finish) {
      const text = String(value || '').trim();

      if (!text) {
        // Leave the editor open so the text can be corrected, rather than
        // closing it and losing the edit.
        return;
      }

      // The server also short-circuits identical text, so a no-op edit costs
      // no regeneration either way.

      // Discard the branch locally.
      let node = msgEl.nextElementSibling;
      while (node) {
        const next = node.nextElementSibling;
        // The typing indicator is not part of the branch.
        if (!node.classList.contains('ai-typing')) node.remove();
        node = next;
      }

      this.renderRichText(msgEl.querySelector('.ai-bubble'), text);
      msgEl._aiText = text;
      msgEl.classList.add('is-edited');

      finish(true);
      this.setBusy(true);
      this.showTyping();

      this.chatController = new AbortController();

      try {
        const res = await fetch(`${this.config.apiUrl}/chat/edit`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          signal: this.chatController.signal,
          body: JSON.stringify({
            message_id: msgId,
            message: text.slice(0, MAX_LENGTH),
            session_id: this.sessionId,
            agent_id: this.agentId
          })
        });

        const raw = await res.text();
        let data;
        try {
          data = JSON.parse(raw);
        } catch (parseErr) {
          console.error('AI Chatbot: non-JSON response from /chat/edit', res.status, raw.slice(0, 200));
          data = { success: false, message: `Server error (${res.status}). Please try again.` };
        }

        if (data.success && data.data) {
          if (data.data.support_email) {
            this.lastResponseSupportEmail = data.data.support_email;
          }
          if (data.data.language) {
            this.setLanguage(data.data.language);
          }
          // unchanged means the server short-circuited an identical edit, so
          // there is nothing new to render.
          if (!data.data.unchanged && data.data.reply) {
            this.appendMessage('assistant', data.data.reply, data.data.message_id, data.data.offer_contact, { userMessage: text });
          }
        } else {
          this.appendMessage('assistant', data.message || this.t('edit_error', 'Sorry, the message could not be edited. Please try again.'));
        }
      } catch (err) {
        if (err && err.name === 'AbortError') {
          this.showCancelledNotice();
        } else {
          console.error('AI Chatbot: /chat/edit request failed', err);
          this.appendMessage('assistant', this.t('connection_error', 'Unable to reach the server. Please check your connection.'));
        }
      } finally {
        this.hideTyping();
        this.chatController = null;
        this.setBusy(false);
      }
    }

    setBusy(isBusy) {
      this.isLoading = isBusy;

      /*
       * The send button becomes Stop while a reply is pending. Handled here
       * rather than at each call site so the icon, the label and the enabled
       * state can never disagree about what the button currently does.
       */
      if (this.sendBtn) {
        this.sendBtn.classList.toggle('is-busy', isBusy);
        this.sendBtn.setAttribute('aria-busy', isBusy ? 'true' : 'false');
        // The label has to track the swap, otherwise a screen reader announces
        // "Send message" on a control that stops the request.
        const label = isBusy ? this.t('stop_label', 'Stop generating') : this.t('send_label', 'Send message');
        this.sendBtn.setAttribute('aria-label', label);
        this.sendBtn.title = label;
      }

      if (this.textInput) {
        this.textInput.setAttribute('aria-busy', isBusy ? 'true' : 'false');
      }

      // The composer stays enabled while a reply is pending, so the visitor can
      // line up the next message.
      this.updateSendState();
    }

    /**
     * Abort the in-flight reply.
     *
     * /chat is a single blocking request that resolves with the finished reply,
     * so there is no partial output to discard: this cancels the wait, it does
     * not stop token generation halfway. Real generation control would need a
     * streaming endpoint on the PHP side.
     *
     * Safe to call when nothing is pending.
     */
    cancelPendingReply() {
      if (!this.isLoading || !this.chatController) return;

      this.chatController.abort();
    }

    /**
     * Render the outcome of a cancelled request.
     *
     * Deliberately not an assistant bubble: nothing was actually said, so
     * putting it in the transcript as a reply would misrepresent what the
     * assistant said. It is also skipped by the alert/sound/badge path, since a
     * cancellation is not an incoming message.
     */
    showCancelledNotice() {
      if (!this.chatBody) return;

      const note = document.createElement('div');
      note.className = 'ai-cancelled-notice';
      note.textContent = this.t('response_stopped', 'Response stopped.');
      this.chatBody.appendChild(note);
      this.scrollToBottom();
    }

    async sendFeedback(messageId, rating, rowElement) {
      rowElement.innerHTML = `<span>${this.escapeHtml(this.t('feedback_thanks', 'Thanks!'))}</span>`;
      try {
        await fetch(`${this.config.apiUrl}/feedback`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message_id: messageId, rating })
        });
      } catch (err) {
        // silent
      }
    }

    /**
     * Open a full-screen preview of an image from a chat bubble.
     *
     * renderRichText only ever emits http(s), relative or
     * data:image URLs, but the scheme is re-checked here so the
     * lightbox cannot be used to navigate anywhere else. The
     * overlay lives on document.body so it covers the whole
     * viewport regardless of the widget's own stacking context.
     *
     * @param {string} src Image URL.
     */
    openLightbox(src) {
      if (!src || !/^(https?:\/\/|\/|\.\/|\.\.\/|data:image\/)/i.test(src)) return;

      const overlay = document.createElement('div');
      overlay.className = 'ai-image-lightbox';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-label', 'Image preview');

      const img = document.createElement('img');
      img.src = src;
      img.alt = '';
      // Clicking the picture itself keeps the preview open; only
      // the backdrop, or Escape, dismisses it.
      img.addEventListener('click', (e) => e.stopPropagation());
      overlay.appendChild(img);

      const close = () => {
        overlay.remove();
        document.removeEventListener('keydown', onKey, true);
      };
      const onKey = (e) => {
        if (e.key === 'Escape') close();
      };

      overlay.addEventListener('click', close);
      document.addEventListener('keydown', onKey, true);
      document.body.appendChild(overlay);
    }

    /**
     * Resolve the configured support / human-handoff email address.
     * Sanitized by server Settings::get_support_email() or frontend config.
     *
     * @return {string} Valid recipient email or empty string if not configured.
     */
    getSupportEmail() {
      const candidates = [
        this.lastResponseSupportEmail,
        this.config?.supportEmail,
        this.config?.support_email,
        this.config?.email,
        this.agent?.skills?.support_email,
        this.agent?.support_email,
        window.aiChatbotConfig?.supportEmail,
        window.aiChatbotConfig?.support_email,
        'semanticcreation@gmail.com'
      ];
      for (const c of candidates) {
        if (typeof c === 'string' && c.includes('@') && c.includes('.')) {
          const trimmed = c.trim();
          if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
            return trimmed;
          }
        }
      }
      return 'semanticcreation@gmail.com';
    }

    /**
     * Extract recent conversation context for human handoff email pre-fill.
     * Follows strict privacy rules: only minimum useful context (last 2-3 turns,
     * max 120 chars per turn, max 350 chars total), stripping any mock button artifacts.
     *
     * @param {string} currentUserMessage
     * @return {string}
     */
    getRecentContextForEmail(currentUserMessage) {
      if (!Array.isArray(this.messages) || this.messages.length === 0) {
        return '';
      }

      const turns = [];
      const userNorm = (currentUserMessage || '').trim();

      for (let i = this.messages.length - 1; i >= 0 && turns.length < 4; i--) {
        const m = this.messages[i];
        if (!m || !m.text) continue;
        if (m.role === 'user' && m.text.trim() === userNorm) {
          continue;
        }
        const clean = m.text
          .replace(/\[HUMAN_HANDOFF\]/gi, '')
          .replace(/\s*\(button\)/gi, '')
          .replace(/\s*\[button\]/gi, '')
          .replace(/\[(?:Contact\s+a\s+Human|Mit\s+einem\s+Menschen\s+sprechen)\]/gi, '')
          .replace(/\s+/g, ' ')
          .trim();
        if (!clean) continue;
        const truncated = clean.length > 120 ? clean.slice(0, 117) + '...' : clean;
        const label = m.role === 'user' ? 'User' : 'Chatbot';
        turns.unshift(`${label}: ${truncated}`);
      }

      const selected = turns.slice(-3);
      if (selected.length === 0) return '';
      const text = selected.join('\n');
      return text.length > 350 ? text.slice(0, 347) + '...' : text;
    }

    /**
     * Build the pre-filled mailto: URI for the support team.
     *
     * @param {string} [userMessage]
     * @return {string} mailto URI or empty string if no recipient configured.
     */
    buildHandoffMailtoUrl(userMessage) {
      const email = this.getSupportEmail();
      if (!email) {
        return '';
      }

      const question = (userMessage || this.lastUserMessage || '').trim() || 'Assistance requested with chatbot services.';
      const context = this.getRecentContextForEmail(question);
      const subject = this.t('handoff_subject', 'AI Chatbot Support Request');

      let body = 'Hello,\n\nI would like assistance from your team.\n\nMy question:\n' + question + '\n\n';
      if (context) {
        body += 'Chatbot conversation context:\n' + context + '\n\n';
      }
      body += 'Thank you.';

      return `mailto:${email}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
    }

    /**
     * Contact form component injection is completely disabled.
     * The bot replies strictly in plain text/Markdown using Knowledge Base contact details.
     */
    renderHandoffCard(parentEl, userMessage) {
      // Disabled completely: no form or card UI is rendered.
    }

    /**
     * Contact modal is completely disabled.
     */
    showContactModal() {
      // Disabled completely.
    }
  }

  // Mount widgets immediately if DOM is ready, or on DOMContentLoaded.
  // Guard against duplicate mounting and memory leaks.
  function initWidgets() {
    const containers = document.querySelectorAll('.ai-chatbot-root, #ai-chatbot-root');
    containers.forEach((el) => {
      if (el._aiChatbotMounted) return;
      el._aiChatbotMounted = true;
      const widget = new AIChatbotWidget(el);
      window.aiChatbot = widget;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWidgets);
  } else {
    initWidgets();
  }

})();
