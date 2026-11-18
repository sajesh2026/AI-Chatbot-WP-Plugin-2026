/**
 * AI Chatbot Admin React Application
 * Built with @wordpress/element and WordPress Component conventions.
 */

import { createElement, useState, useEffect, useRef, useCallback, memo } from '@wordpress/element';
import { render } from '@wordpress/element';
import './index.scss';
import SetupWizardApp from './SetupWizard';

const AdminData = window.aiChatbotAdminData || {
  apiUrl: '/wp-json/ai-chatbot/v1',
  nonce: '',
  agentId: 1,
  siteName: 'AI Chatbot',
  homeUrl: 'https://example.com',
  pluginVersion: '1.2.1',
  launchWizard: false,
  wizardCompleted: false
};

/**
 * Languages the chatbot can answer in. Mirrors the PHP
 * Settings::ALLOWED_LANGUAGES list so the dashboard and the
 * server always agree on the selectable set.
 */
const LANGUAGE_OPTIONS = [
  { code: 'en', label: 'English' },
  { code: 'de', label: 'German (Deutsch)' },
  { code: 'ne', label: 'Nepali (नेपाली)' },
  { code: 'es', label: 'Spanish (Español)' },
  { code: 'fr', label: 'French (Français)' },
  { code: 'it', label: 'Italian (Italiano)' },
  { code: 'pt', label: 'Portuguese (Português)' },
  { code: 'hi', label: 'Hindi (हिन्दी)' }
];

/**
 * Render light Markdown as React elements.
 *
 * The chatbot replies in Markdown (bold labels, bullet lists, cited URLs), so
 * the live previews used to show raw asterisks and plain text URLs. Output is
 * built as React nodes rather than injected HTML, so model text can never
 * introduce markup into the admin page.
 *
 * @param {string} text Raw reply text.
 * @return {Array} React nodes.
 */
function renderMarkdownNodes(text) {
  if (text == null) return [];
  const raw = String(text).trim();
  if (!raw) return [];

  // Inline formatting, applied on plain strings so text stays escaped by React.
  const inline = (value, keyPrefix) => {
    const nodes = [];
    // Order matters: code, then bold, then italic, then URLs.
    const pattern = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)|(\*[^*\n]+\*)|(https?:\/\/[^\s]+)/g;
    let last = 0;
    let match;
    let i = 0;

    while ((match = pattern.exec(value)) !== null) {
      if (match.index > last) nodes.push(value.slice(last, match.index));
      const token = match[0];
      const key = `${keyPrefix}-i${i++}`;

      if (token.startsWith('`')) {
        nodes.push(createElement('code', { key, style: { background: 'rgba(15,23,42,0.07)', padding: '1px 5px', borderRadius: 4, fontSize: '0.92em' } }, token.slice(1, -1)));
      } else if (token.startsWith('**')) {
        nodes.push(createElement('strong', { key, style: { fontWeight: 650 } }, token.slice(2, -2)));
      } else if (token.startsWith('*')) {
        nodes.push(createElement('em', { key }, token.slice(1, -1)));
      } else {
        let url = token;
        let tail = '';
        const trailing = token.match(/[.,;:!?)\]]+$/);
        if (trailing) {
          url = token.slice(0, token.length - trailing[0].length);
          tail = trailing[0];
        }
        nodes.push(
          createElement('a', { key, href: url, target: '_blank', rel: 'noopener noreferrer nofollow', style: { color: 'inherit', textDecoration: 'underline', overflowWrap: 'anywhere' } }, url)
        );

        // Keep trailing punctuation (",", ".") outside the link.
        if (tail) nodes.push(tail);
      }
      last = match.index + token.length;
    }

    if (last < value.length) nodes.push(value.slice(last));
    return nodes;
  };

  const blocks = raw.split(/\n{2,}/).filter((b) => b.trim() !== '');

  return blocks.map((block, blockIndex) => {
    const lines = block.split('\n').map((l) => l.trim()).filter((l) => l !== '');

    if (lines.length > 1 && lines.every((l) => /^[*-]\s+/.test(l))) {
      return createElement(
        'ul',
        { key: `b${blockIndex}`, style: { margin: '4px 0', paddingLeft: 18 } },
        lines.map((line, lineIndex) =>
          createElement('li', { key: `l${lineIndex}`, style: { marginBottom: 3 } }, inline(line.replace(/^[*-]\s+/, ''), `b${blockIndex}l${lineIndex}`))
        )
      );
    }

    if (lines.length > 1 && lines.every((l) => /^\d+[.)]\s+/.test(l))) {
      return createElement(
        'ol',
        { key: `b${blockIndex}`, style: { margin: '4px 0', paddingLeft: 18 } },
        lines.map((line, lineIndex) =>
          createElement('li', { key: `l${lineIndex}`, style: { marginBottom: 3 } }, inline(line.replace(/^\d+[.)]\s+/, ''), `b${blockIndex}l${lineIndex}`))
        )
      );
    }

    return createElement(
      'p',
      { key: `b${blockIndex}`, style: { margin: '0 0 4px' } },
      lines.map((line, lineIndex) =>
        createElement(
          'span',
          { key: `s${lineIndex}` },
          ...inline(line, `b${blockIndex}s${lineIndex}`),
          lineIndex < lines.length - 1 ? createElement('br', { key: 'br' }) : null
        )
      )
    );
  });
}

// API Fetch Helper
//
// Always resolves to the parsed response body, even when the request
// fails. A non-JSON body (PHP fatal, proxy error page) or a network
// outage used to make res.json() throw, so save handlers received no
// response at all and could not tell the user whether the change was
// applied. Callers check res.success and read res.message on failure.
async function apiCall(endpoint, method = 'GET', body = null) {
  const options = {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': AdminData.nonce
    }
  };
  if (body) {
    options.body = JSON.stringify(body);
  }
  try {
    const res = await fetch(`${AdminData.apiUrl}${endpoint}`, options);
    let data;
    try {
      data = await res.json();
    } catch (e) {
      return { success: false, message: `Request failed (HTTP ${res.status}).` };
    }
    if (!res.ok && data.success !== false) {
      return { success: false, message: data.message || `Request failed (HTTP ${res.status}).` };
    }
    return data;
  } catch (e) {
    return { success: false, message: 'Network error. Please check your connection and try again.' };
  }
}

// Curated presets shipped with the plugin. Custom presets created in
// the dashboard are stored in the "theme_presets" settings group and
// rendered alongside these in the theme tab.
const BUILTIN_THEME_PRESETS = [
  { id: 'modern', label: 'Modern Blue', color: '#2563eb' },
  { id: 'minimal', label: 'Minimalist', color: '#18181b' },
  { id: 'emerald', label: 'Business Emerald', color: '#059669' },
  { id: 'purple', label: 'Royal Purple', color: '#6366f1' },
  { id: 'sunset', label: 'Warm Sunset', color: '#ea580c' },
  { id: 'dark', label: 'Sleek Dark', color: '#0f172a' }
];

// Darken a #rrggbb colour by a factor (0-1). Used to derive a
// cohesive secondary colour from the primary so the chat header
// gradient (primary -> secondary) stays single-hue when only the
// primary colour is changed.
const darkenColor = (hex, factor = 0.75) => {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex).trim());
  if (!m) return hex;
  const n = parseInt(m[1], 16);
  const r = Math.round(((n >> 16) & 255) * factor);
  const g = Math.round(((n >> 8) & 255) * factor);
  const b = Math.round((n & 255) * factor);
  const to = (v) => v.toString(16).padStart(2, '0');
  return `#${to(r)}${to(g)}${to(b)}`;
};

/*
 * Toast notification system.
 *
 * A fixed, non-blocking stack that replaces the old single badge in
 * the topbar. Toasts are plain data ({ id, type, title, message,
 * duration, updatedAt }) held in one array; ToastItem owns its own
 * auto-dismiss timer, hover-pause and exit animation, so adding or
 * updating a toast never re-renders or resets the others.
 *
 * Positioning is driven by TOAST_POSITION below: the corner class and
 * the CSS custom properties are the only things the stylesheet reads,
 * so the stack can be repositioned without touching the component
 * logic or the stylesheet rules.
 */
const TOAST_MAX_VISIBLE = 4;
const TOAST_DURATION = 4000;
const TOAST_ERROR_DURATION = 6000;
const TOAST_ANIM_MS = 220;

const TOAST_POSITION = {
  corner: 'bottom-right', // 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left'
  offset: 24,             // desktop spacing from the edges (px)
  offsetMobile: 14        // mobile spacing from the edges (px)
};

// Honour the OS reduced-motion setting: toasts appear and disappear
// instantly and the progress sweep is hidden.
const REDUCED_MOTION = typeof window !== 'undefined'
  && window.matchMedia
  && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let toastId = 0;

/**
 * Inline SVG icon set, matching the stroke style already used by the
 * admin navigation so toasts feel native to the plugin rather than a
 * third-party library.
 */
function ToastIcon({ type }) {
  const common = {
    width: 16,
    height: 16,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 2,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    'aria-hidden': 'true'
  };

  switch (type) {
    case 'success':
      return <svg { ...common }><path d="M20 6L9 17l-5-5" /></svg>;
    case 'error':
      return <svg { ...common }><circle cx="12" cy="12" r="9" /><path d="M15 9l-6 6M9 9l6 6" /></svg>;
    case 'warning':
      return <svg { ...common }><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><path d="M12 9v4M12 17h.01" /></svg>;
    case 'info':
      return <svg { ...common }><circle cx="12" cy="12" r="9" /><path d="M12 16v-4M12 8h.01" /></svg>;
    case 'loading':
      return <svg { ...common } className="ai-toast-spinner"><path d="M21 12a9 9 0 1 1-6.219-8.56" /></svg>;
    default:
      return null;
  }
}

/**
 * A single toast. Owns its auto-dismiss timer so the timer can be
 * paused on hover and resumed on leave, and so an update() restarts
 * the countdown with the new duration.
 */
function ToastItem({ toast, onDismiss }) {
  const [exiting, setExiting] = useState(false);
  const [paused, setPaused] = useState(false);
  const timerRef = useRef(null);
  const removeRef = useRef(null);
  const remainingRef = useRef(toast.duration);
  const startedRef = useRef(0);

  const clearTimer = useCallback(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  const finish = useCallback(() => {
    clearTimer();
    setExiting(true);
    removeRef.current = setTimeout(
      () => onDismiss(toast.id),
      REDUCED_MOTION ? 0 : TOAST_ANIM_MS
    );
  }, [clearTimer, onDismiss, toast.id]);

  const arm = useCallback((ms) => {
    clearTimer();
    if (ms <= 0) return;
    startedRef.current = Date.now();
    remainingRef.current = ms;
    timerRef.current = setTimeout(finish, ms);
  }, [clearTimer, finish]);

  // (Re)start the countdown when the toast is created or updated.
  useEffect(() => {
    arm(toast.duration);
    return () => {
      clearTimer();
      if (removeRef.current) {
        clearTimeout(removeRef.current);
        removeRef.current = null;
      }
    };
  }, [toast.id, toast.updatedAt, toast.duration, arm, clearTimer]);

  const handleMouseEnter = () => {
    if (toast.duration <= 0 || exiting) return;
    clearTimer();
    remainingRef.current = Math.max(0, remainingRef.current - (Date.now() - startedRef.current));
    setPaused(true);
  };

  const handleMouseLeave = () => {
    if (toast.duration <= 0 || exiting) return;
    setPaused(false);
    arm(remainingRef.current);
  };

  // Errors and warnings are assertive; everything else is polite.
  const role = toast.type === 'error' || toast.type === 'warning' ? 'alert' : 'status';

  return (
    <div
      className={ `ai-toast ai-toast-${ toast.type }${ exiting ? ' ai-toast-exiting' : '' }${ REDUCED_MOTION ? ' ai-toast-reduced' : '' }` }
      role={ role }
      onMouseEnter={ handleMouseEnter }
      onMouseLeave={ handleMouseLeave }
    >
      <span className="ai-toast-icon">
        <ToastIcon type={ toast.type } />
      </span>

      <div className="ai-toast-content">
        { toast.title && <div className="ai-toast-title">{ toast.title }</div> }
        { toast.message && <div className="ai-toast-message">{ toast.message }</div> }
      </div>

      <button
        type="button"
        className="ai-toast-close"
        aria-label="Dismiss notification"
        onClick={ finish }
      >
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" aria-hidden="true">
          <path d="M18 6L6 18M6 6l12 12" />
        </svg>
      </button>

      { toast.duration > 0 && (
        <span className="ai-toast-progress" key={ toast.updatedAt } aria-hidden="true">
          <span
            className={ `ai-toast-progress-bar ai-toast-progress-${ toast.type }` }
            style={ {
              animationDuration: `${ toast.duration }ms`,
              animationPlayState: paused ? 'paused' : 'running'
            } }
          />
        </span>
      ) }
    </div>
  );
}

// Memoised so a new toast entering the stack does not re-render (or
// reset the timer of) the toasts already showing.
const MemoisedToastItem = memo(ToastItem);

/**
 * Fixed stack container. pointer-events:none on the container keeps
 * the page behind clickable; each card re-enables pointer events.
 */
function ToastContainer({ toasts, onDismiss }) {
  const pos = TOAST_POSITION;
  return (
    <div
      className={ `ai-toast-container ai-toast-container--${ pos.corner }` }
      style={ {
        '--ai-toast-offset': `${ pos.offset }px`,
        '--ai-toast-offset-mobile': `${ pos.offsetMobile }px`
      } }
    >
      { toasts.map((t) => (
        <MemoisedToastItem key={ t.id } toast={ t } onDismiss={ onDismiss } />
      )) }
    </div>
  );
}

function App() {
  const [currentTab, setCurrentTab] = useState('agent');
  const [wizardCompleted, setWizardCompleted] = useState(!!AdminData.wizardCompleted);
  const [toasts, setToasts] = useState([]);
  const [agent, setAgent] = useState(null);
  const [settings, setSettings] = useState(null);
  const [sources, setSources] = useState([]);
  const [keys, setKeys] = useState([]);
  const [unanswered, setUnanswered] = useState([]);
  const [outOfScope, setOutOfScope] = useState([]);
  const [loading, setLoading] = useState(true);

  // Sync hash with currentTab on mount
  useEffect(() => {
    const hash = window.location.hash.replace('#/', '');
    if (['agent', 'knowledge', 'skills', 'settings', 'wizard', 'inbox'].includes(hash)) {
      setCurrentTab(hash);
    }

    // Auto-launch the setup wizard after plugin activation. Guarded by
    // wizardCompleted so a refresh or reactivation never re-opens it
    // once setup has finished.
    if (AdminData.launchWizard && !wizardCompleted) {
      switchTab('wizard');
    }

    loadAllData();
  }, []);

  const switchTab = (tab) => {
    setCurrentTab(tab);
    window.location.hash = `#/${tab}`;
  };

  const pushToast = useCallback((toast) => {
    const id = ++toastId;
    const entry = {
      id,
      updatedAt: Date.now(),
      duration: TOAST_DURATION,
      ...toast
    };

    setToasts((prev) => {
      const next = [...prev, entry];
      // Keep the stack within the visible maximum. The oldest
      // toast is dropped so the newest is always seen.
      return next.length > TOAST_MAX_VISIBLE
        ? next.slice(next.length - TOAST_MAX_VISIBLE)
        : next;
    });

    return id;
  }, []);

  const dismissToast = useCallback((id) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const notifySave = useCallback((msg = 'Changes have been applied') => {
    pushToast({ type: 'success', title: msg });
  }, [pushToast]);

  const notifyError = useCallback((msg = 'Failed to save changes.') => {
    pushToast({ type: 'error', title: msg, duration: TOAST_ERROR_DURATION });
  }, [pushToast]);

  /*
   * Persistent toast for in-flight work. Returns a handle so the
   * caller can transform it in place when the work resolves — a
   * "Saving..." spinner becomes "Saved" without a second toast.
   */
  const notifyLoading = useCallback((msg = 'Working...') => {
    const id = pushToast({ type: 'loading', title: msg, duration: 0 });

    return {
      update: (patch = {}) => {
        setToasts((prev) => prev.map((t) => {
          if (t.id !== id) return t;
          const type = patch.type || t.type;
          return {
            ...t,
            ...patch,
            type,
            updatedAt: Date.now(),
            duration: patch.duration != null
              ? patch.duration
              : (type === 'loading' ? 0 : (type === 'error' ? TOAST_ERROR_DURATION : TOAST_DURATION))
          };
        }));
      },
      dismiss: () => dismissToast(id)
    };
  }, [pushToast, dismissToast]);

  const loadAllData = async () => {
    setLoading(true);
    try {
      const [agentRes, settingsRes, sourcesRes, keysRes, unansRes, oosRes] = await Promise.all([
        apiCall(`/agent?agent_id=${AdminData.agentId}`),
        apiCall(`/settings?agent_id=${AdminData.agentId}`),
        apiCall(`/knowledge?agent_id=${AdminData.agentId}`),
        apiCall(`/keys?provider=groq`),
        apiCall(`/unanswered?agent_id=${AdminData.agentId}`),
        apiCall(`/out-of-scope?agent_id=${AdminData.agentId}`)
      ]);

      if (agentRes.success) setAgent(agentRes.data);
      if (settingsRes.success) setSettings(settingsRes.data.settings);
      if (sourcesRes.success) setSources(sourcesRes.data.sources);
      if (keysRes.success) setKeys(keysRes.data.keys);
      if (unansRes.success) setUnanswered(unansRes.data.questions);
      if (oosRes.success) setOutOfScope(oosRes.data.items);
    } catch (e) {
      console.error('Failed to load initial data:', e);
    }
    setLoading(false);
  };

  if (loading || !agent || !settings) {
    return (
      <div className="ai-admin-app" style={{ alignItems: 'center', justifyContent: 'center' }}>
        <p style={{ fontSize: 16, color: '#64748b' }}>Loading AI Chatbot configuration...</p>
      </div>
    );
  }

  return (
    <div className="ai-admin-app">
      {/* Sidebar Navigation */}
      <aside className="ai-sidebar">
        <div className="ai-brand">
          <div className="ai-logo-icon">AI</div>
          <div>
            <h2>AI Chatbot</h2>
            <span>v{AdminData.pluginVersion} • Ready</span>
          </div>
        </div>

        <nav className="ai-nav">
          <button className={`ai-nav-item ${currentTab === 'agent' ? 'active' : ''}`} onClick={() => switchTab('agent')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a8.5 8.5 0 0 1 13 0"/></svg>
            Agent Persona
          </button>
          <button className={`ai-nav-item ${currentTab === 'knowledge' ? 'active' : ''}`} onClick={() => switchTab('knowledge')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            Knowledge Base
          </button>
          <button className={`ai-nav-item ${currentTab === 'skills' ? 'active' : ''}`} onClick={() => switchTab('skills')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            Skills & Leads
          </button>
          <button className={`ai-nav-item ${currentTab === 'settings' ? 'active' : ''}`} onClick={() => switchTab('settings')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings & Keys
          </button>
          <button className={`ai-nav-item ${currentTab === 'wizard' ? 'active' : ''}`} onClick={() => switchTab('wizard')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"/><polygon points="12 8 8 12 12 16 12 8"/></svg>
            Setup Wizard
            {wizardCompleted && (
              <span className="ai-nav-badge ai-nav-badge-success">✓</span>
            )}
          </button>
          <button className={`ai-nav-item ${currentTab === 'inbox' ? 'active' : ''}`} onClick={() => switchTab('inbox')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
            Unanswered Inbox
            {unanswered.filter(q => q.status === 'new').length > 0 && (
              <span className="ai-nav-badge">{unanswered.filter(q => q.status === 'new').length}</span>
            )}
          </button>
        </nav>

        <div className="ai-sidebar-footer">
          <span>Targeting Agent #{AdminData.agentId}</span>
        </div>
      </aside>

      {/* Main Content Pane */}
      <main className="ai-main-content">
        <header className="ai-topbar">
          <div className="ai-topbar-title">
            <h1>
              {currentTab === 'agent' && 'Agent Personality & Starter Questions'}
              {currentTab === 'knowledge' && 'Knowledge Base & Retrieval Sources'}
              {currentTab === 'skills' && 'Skills, Follow-ups & Captured Leads'}
              {currentTab === 'settings' && 'Settings, AI Keys & Email Notifications'}
              {currentTab === 'wizard' && 'One-Paste 2-Minute Setup Wizard'}
              {currentTab === 'inbox' && 'Unanswered Questions & Scope Tuning'}
            </h1>
            <p>Non-technical configuration for {agent.name || 'AI Assistant'}</p>
          </div>

          <div className="ai-topbar-actions" />
        </header>

        <div className="ai-page-body">
          {AdminData.onboardingSuccess && (
            <div className="ai-callout ai-callout-info" style={{ marginBottom: 20, borderColor: '#10b981', background: '#ecfdf5', color: '#065f46' }}>
              🎉 <strong>Your AI Chatbot is ready!</strong> Your chatbot has been configured successfully and is now ready for your website visitors.
            </div>
          )}

          <div className="ai-dashboard-status-banner">
            <div className="ai-status-banner-left">
              <div className="ai-status-indicator-group">
                <div className="ai-status-badge ai-status-badge-complete">
                  <span className="ai-status-icon">✓</span>
                  <span>Setup completed</span>
                </div>
                <div className="ai-status-badge ai-status-badge-active">
                  <span className="ai-status-dot">●</span>
                  <span>Chatbot Active</span>
                </div>
              </div>
              <p className="ai-status-banner-desc">
                Your chatbot is live and answering visitor questions using your configured knowledge, personality, and brand styling.
              </p>
            </div>
            <div className="ai-status-banner-actions">
              <a href={AdminData.setupWizardUrl || 'admin.php?page=ai-chatbot-setup'} className="ai-btn ai-btn-primary ai-btn-sm" style={{ textDecoration: 'none' }}>
                ⚡ Setup Wizard →
              </a>
            </div>
          </div>
          {currentTab === 'agent' && (
            <AgentView
              agent={agent}
              settings={settings}
              setAgent={setAgent}
              setSettings={setSettings}
              notifySave={notifySave}
              notifyError={notifyError}
            />
          )}

          {currentTab === 'knowledge' && (
            <KnowledgeView
              sources={sources}
              setSources={setSources}
              notifySave={notifySave}
            />
          )}

          {currentTab === 'skills' && (
            <SkillsView
              settings={settings}
              setSettings={setSettings}
              notifySave={notifySave}
              notifyError={notifyError}
            />
          )}

          {currentTab === 'settings' && (
            <SettingsView
              agent={agent}
              settings={settings}
              keys={keys}
              setSettings={setSettings}
              setKeys={setKeys}
              notifySave={notifySave}
              notifyError={notifyError}
              notifyLoading={notifyLoading}
            />
          )}

          {currentTab === 'wizard' && (
            <WizardView
              onCompleted={() => {
                setWizardCompleted(true);
                loadAllData();
                switchTab('agent');
                notifySave('Setup Wizard applied successfully!');
              }}
            />
          )}

          {currentTab === 'inbox' && (
            <InboxView
              unanswered={unanswered}
              outOfScope={outOfScope}
              setUnanswered={setUnanswered}
              setOutOfScope={setOutOfScope}
              notifySave={notifySave}
            />
          )}
        </div>
      </main>

      <ToastContainer toasts={toasts} onDismiss={dismissToast} />
    </div>
  );
}

// -------------------------------------------------------------
// VIEW 1: AGENT VIEW
// -------------------------------------------------------------
function AgentView({ agent, settings, setAgent, setSettings, notifySave, notifyError }) {
  const [formData, setFormData] = useState({ ...agent });
  const [guardrails, setGuardrails] = useState({ ...settings.guardrails });
  const [questions, setQuestions] = useState(
    Array.isArray(agent.suggested_questions) ? agent.suggested_questions : []
  );
  const [savingPersona, setSavingPersona] = useState(false);
  const [savingQuestions, setSavingQuestions] = useState(false);
  const [testQuery, setTestQuery] = useState('');
  const [testResult, setTestResult] = useState(null);
  const [testing, setTesting] = useState(false);

  useEffect(() => {
    if (Array.isArray(agent.suggested_questions)) {
      setQuestions(agent.suggested_questions);
    }
    setFormData({ ...agent });
  }, [agent]);

  const validateQuestions = (qs) => {
    for (let i = 0; i < qs.length; i++) {
      const trimmed = (qs[i].text || '').trim();
      if (!trimmed) {
        if (notifyError) {
          notifyError(`Question #${i + 1} cannot be empty.`);
        } else {
          alert(`Question #${i + 1} cannot be empty.`);
        }
        return false;
      }
      if (trimmed.length > 255) {
        if (notifyError) {
          notifyError(`Question #${i + 1} is too long (maximum 255 characters).`);
        } else {
          alert(`Question #${i + 1} is too long (maximum 255 characters).`);
        }
        return false;
      }
    }

    const seen = new Set();
    for (let i = 0; i < qs.length; i++) {
      const lower = qs[i].text.trim().toLowerCase();
      if (seen.has(lower)) {
        const msg = `Duplicate question: "${qs[i].text.trim()}". Each starter question must be unique.`;
        if (notifyError) {
          notifyError(msg);
        } else {
          alert(msg);
        }
        return false;
      }
      seen.add(lower);
    }
    return true;
  };

  const saveQuestions = async () => {
    if (!validateQuestions(questions)) return;
    setSavingQuestions(true);

    const cleanedQuestions = questions.map((q, idx) => ({
      ...q,
      text: q.text.trim(),
      enabled: q.enabled !== false,
      sort_order: idx
    }));

    const payload = {
      ...formData,
      suggested_questions: cleanedQuestions
    };

    try {
      const res = await apiCall('/agent', 'POST', payload);
      if (res && res.success) {
        const freshAgent = res.data?.agent || { ...agent, ...payload, suggested_questions: cleanedQuestions };
        setAgent(freshAgent);
        setFormData({ ...freshAgent });
        setQuestions(Array.isArray(freshAgent.suggested_questions) ? freshAgent.suggested_questions : cleanedQuestions);
        notifySave('Starter questions saved successfully!');
      } else {
        const err = res?.message || 'Failed to save starter questions.';
        if (notifyError) notifyError(err);
        else alert(err);
      }
    } catch (e) {
      const err = 'Failed to save starter questions. Please try again.';
      if (notifyError) notifyError(err);
      else alert(err);
    } finally {
      setSavingQuestions(false);
    }
  };

  const saveAgent = async () => {
    if (!validateQuestions(questions)) return;
    setSavingPersona(true);

    const cleanedQuestions = questions.map((q, idx) => ({
      ...q,
      text: q.text.trim(),
      enabled: q.enabled !== false,
      sort_order: idx
    }));

    const payload = {
      ...formData,
      suggested_questions: cleanedQuestions
    };

    try {
      const [res, setRes] = await Promise.all([
        apiCall('/agent', 'POST', payload),
        apiCall('/settings', 'POST', { settings: { guardrails } })
      ]);

      if (res && res.success && setRes && setRes.success) {
        const freshAgent = res.data?.agent || { ...agent, ...payload, suggested_questions: cleanedQuestions };
        setAgent(freshAgent);
        setFormData({ ...freshAgent });
        setQuestions(Array.isArray(freshAgent.suggested_questions) ? freshAgent.suggested_questions : cleanedQuestions);
        setSettings(prev => ({ ...prev, guardrails }));
        notifySave('Agent persona and guardrails updated!');
      } else if (res && res.success) {
        const freshAgent = res.data?.agent || { ...agent, ...payload, suggested_questions: cleanedQuestions };
        setAgent(freshAgent);
        setFormData({ ...freshAgent });
        setQuestions(Array.isArray(freshAgent.suggested_questions) ? freshAgent.suggested_questions : cleanedQuestions);
        notifySave('Agent updated!');
        if (notifyError) notifyError(setRes?.message || 'Guardrails could not be saved.');
      } else if (setRes && setRes.success) {
        setSettings(prev => ({ ...prev, guardrails }));
        notifySave('Guardrails updated!');
        if (notifyError) notifyError(res?.message || 'Agent could not be saved.');
      } else {
        const err = res?.message || setRes?.message || 'Failed to save. Please try again.';
        if (notifyError) notifyError(err);
        else alert(err);
      }
    } catch (e) {
      if (notifyError) notifyError('Failed to save. Please try again.');
    } finally {
      setSavingPersona(false);
    }
  };

  const addQuestion = () => {
    const newQ = {
      id: 'q_' + Date.now(),
      text: 'New question?',
      icon: 'chat',
      enabled: true,
      sort_order: questions.length
    };
    setQuestions([...questions, newQ]);
  };

  const updateQuestion = (idx, field, val) => {
    const copy = [...questions];
    copy[idx] = { ...copy[idx], [field]: val };
    setQuestions(copy);
  };

  const moveQuestion = (idx, dir) => {
    if ((dir === -1 && idx === 0) || (dir === 1 && idx === questions.length - 1)) return;
    const copy = [...questions];
    const target = copy[idx];
    copy[idx] = copy[idx + dir];
    copy[idx + dir] = target;
    const reordered = copy.map((q, i) => ({ ...q, sort_order: i }));
    setQuestions(reordered);
  };

  const deleteQuestion = (idx) => {
    const filtered = questions.filter((_, i) => i !== idx).map((q, i) => ({ ...q, sort_order: i }));
    setQuestions(filtered);
  };

  const runTestGuardrails = async () => {
    if (!testQuery) return;
    setTesting(true);
    setTestResult(null);
    try {
      const res = await apiCall('/guardrails/test', 'POST', { message: testQuery });
      if (res.success) {
        setTestResult(res.data);
      }
    } catch (e) {
      alert('Guardrail test failed.');
    }
    setTesting(false);
  };

  return (
    <div>
      <div className="ai-grid-2">
        {/* Left Column: Identity & Personality */}
        <div className="ai-card">
          <div className="ai-card-header">
            <div>
              <h3>Agent Identity & Greeting</h3>
              <p>Define how your AI assistant introduces itself to visitors.</p>
            </div>
          </div>

          <div className="ai-form-row">
            <label>Agent Name</label>
            <input
              type="text"
              value={formData.name || ''}
              onChange={e => setFormData({ ...formData, name: e.target.value })}
              placeholder="e.g. IQronix Assistant"
            />
          </div>

          <div className="ai-form-row">
            <label>Online Status Indicator</label>
            <select
              value={formData.status || 'online'}
              onChange={e => setFormData({ ...formData, status: e.target.value })}
            >
              <option value="online">Online (Green Dot)</option>
              <option value="away">Away (Yellow Dot)</option>
              <option value="offline">Offline (Grey Dot)</option>
              <option value="custom">Custom Text Message</option>
            </select>
          </div>

          <div className="ai-form-row">
            <label>Custom Status Text</label>
            <input
              type="text"
              value={formData.custom_status_text || ''}
              onChange={e => setFormData({ ...formData, custom_status_text: e.target.value })}
              placeholder="e.g. Available 24/7 or Typically replies in seconds"
            />
          </div>

          <div className="ai-form-row">
            <label>Tone of Voice</label>
            <select
              value={formData.tone || 'friendly'}
              onChange={e => setFormData({ ...formData, tone: e.target.value })}
            >
              <option value="friendly">Friendly (Warm & Approachable)</option>
              <option value="professional">Professional (Polished & Objective)</option>
              <option value="casual">Casual (Relaxed & Conversational)</option>
              <option value="formal">Formal (Dignified & Precise)</option>
              <option value="concise">Concise (Direct, minimal fluff)</option>
              <option value="technical">Technical (Detailed & Structured)</option>
              <option value="sales">Sales-Oriented (Persuasive)</option>
              <option value="empathetic">Empathetic (Reassuring)</option>
              <option value="custom">Custom Tone</option>
            </select>
          </div>

          {formData.tone === 'custom' && (
            <div className="ai-form-row">
              <label>Custom Tone Description</label>
              <input
                type="text"
                value={formData.custom_tone || ''}
                onChange={e => setFormData({ ...formData, custom_tone: e.target.value })}
                placeholder="Describe your desired tone..."
              />
            </div>
          )}

          <div className="ai-form-row">
            <label>Answer Length</label>
            <select
              value={formData.answer_length || 'medium'}
              onChange={e => setFormData({ ...formData, answer_length: e.target.value })}
            >
              <option value="short">Short (under 100 words)</option>
              <option value="medium">Medium (100 - 250 words)</option>
              <option value="long">Long (Comprehensive)</option>
              <option value="custom">Custom Length</option>
            </select>
          </div>

          {formData.answer_length === 'custom' && (
            <div className="ai-form-row">
              <label>Custom Answer Length Instructions</label>
              <input
                type="text"
                value={formData.custom_answer_length || ''}
                onChange={e => setFormData({ ...formData, custom_answer_length: e.target.value })}
                placeholder="e.g. Limit responses to 1-2 bullet points or under 50 words"
              />
            </div>
          )}

          <div className="ai-form-row">
            <label>Role & Purpose</label>
            <textarea
              rows="3"
              value={formData.role || ''}
              onChange={e => setFormData({ ...formData, role: e.target.value })}
              placeholder="What does the AI act as?"
            />
            <div className="ai-help-text">Directs the agent who it represents and how it should assist visitors. Variables supported: <code>{`{{company_name}}`}</code>, <code>{`{{agent_name}}`}</code>, <code>{`{{industry}}`}</code></div>
          </div>

          <div className="ai-form-row">
            <label>Greeting Message</label>
            <textarea
              rows="2"
              value={formData.greeting || ''}
              onChange={e => setFormData({ ...formData, greeting: e.target.value })}
              placeholder="Hello! Welcome to {{company_name}}."
            />
            <div className="ai-help-text">Variables supported: <code>{`{{agent_name}}`}</code>, <code>{`{{company_name}}`}</code></div>
          </div>

          <button className="ai-btn ai-btn-primary" onClick={saveAgent} disabled={savingPersona}>
            {savingPersona ? 'Saving Persona...' : 'Save Persona'}
          </button>
        </div>

        {/* Right Column: Suggested Questions & Guardrails */}
        <div>
          {/* Suggested Questions */}
          <div className="ai-card">
            <div className="ai-card-header">
              <div>
                <h3>Suggested Starter Questions</h3>
                <p>Clickable buttons on the welcome screen that submit questions in one click.</p>
              </div>
              <button className="ai-btn ai-btn-secondary ai-btn-sm" onClick={addQuestion}>+ Add Question</button>
            </div>

            <div style={{ display: 'flex', gap: 16, marginBottom: 14 }}>
              <label style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 6 }}>
                <input
                  type="checkbox"
                  checked={formData.show_on_welcome !== 0}
                  onChange={e => setFormData({ ...formData, show_on_welcome: e.target.checked ? 1 : 0 })}
                />
                Show on Welcome Screen
              </label>
              <div style={{ marginLeft: 'auto', display: 'flex', gap: 12 }}>
                <span style={{ fontSize: 12, color: '#64748b' }}>
                  Max Desktop:
                  <input
                    type="number"
                    style={{ width: 45, marginLeft: 4, padding: 3 }}
                    value={formData.max_questions_desktop || 4}
                    onChange={e => setFormData({ ...formData, max_questions_desktop: parseInt(e.target.value, 10) })}
                  />
                </span>
                <span style={{ fontSize: 12, color: '#64748b' }}>
                  Max Mobile:
                  <input
                    type="number"
                    style={{ width: 45, marginLeft: 4, padding: 3 }}
                    value={formData.max_questions_mobile || 2}
                    onChange={e => setFormData({ ...formData, max_questions_mobile: parseInt(e.target.value, 10) })}
                  />
                </span>
              </div>
            </div>

            <div className="ai-questions-list">
              {questions.map((q, idx) => (
                <div key={q.id || idx} className="ai-question-item">
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <button className="ai-btn ai-btn-secondary ai-btn-sm" style={{ padding: '1px 5px' }} onClick={() => moveQuestion(idx, -1)}>▲</button>
                    <button className="ai-btn ai-btn-secondary ai-btn-sm" style={{ padding: '1px 5px' }} onClick={() => moveQuestion(idx, 1)}>▼</button>
                  </div>
                  <input
                    type="checkbox"
                    checked={q.enabled !== false}
                    onChange={e => updateQuestion(idx, 'enabled', e.target.checked)}
                    title="Enable / Disable"
                  />
                  <input
                    type="text"
                    className="ai-question-text"
                    value={q.text || ''}
                    maxLength={255}
                    onChange={e => updateQuestion(idx, 'text', e.target.value)}
                  />
                  <button className="ai-btn ai-btn-danger ai-btn-sm" onClick={() => deleteQuestion(idx)}>✕</button>
                </div>
              ))}
              {questions.length === 0 && (
                <p style={{ color: '#94a3b8', fontSize: 13, textAlign: 'center' }}>No suggested questions added yet.</p>
              )}
            </div>

            <div style={{ marginTop: 16 }}>
              <button className="ai-btn ai-btn-primary" onClick={saveQuestions} disabled={savingQuestions}>
                {savingQuestions ? 'Saving Questions...' : 'Save Questions'}
              </button>
            </div>
          </div>

          {/* Topic Guardrails & Scope */}
          <div className="ai-card">
            <div className="ai-card-header">
              <div>
                <h3>Topic Guardrails (Scope Protection)</h3>
                <p>Prevent off-topic use, jailbreaks, and prompt extraction.</p>
              </div>
            </div>

            <div className="ai-form-row">
              <label>Strictness Level</label>
              <select
                value={guardrails.strictness || 'strict'}
                onChange={e => setGuardrails({ ...guardrails, strictness: e.target.value })}
              >
                <option value="strict">Strict (Default - Only scope answered, everything else declined)</option>
                <option value="balanced">Balanced (Allows short general industry concepts, steers back)</option>
                <option value="off">Off (Not recommended - No scope checks)</option>
              </select>
            </div>

            <div className="ai-form-row">
              <label>Allowed Topics</label>
              <textarea
                rows="2"
                value={guardrails.allowed_topics || ''}
                onChange={e => setGuardrails({ ...guardrails, allowed_topics: e.target.value })}
                placeholder="e.g. Our services, pricing, business hours, contact info"
              />
            </div>

            <div className="ai-form-row">
              <label>Never Discuss (Hard Off-Limits)</label>
              <textarea
                rows="2"
                value={guardrails.never_discuss || ''}
                onChange={e => setGuardrails({ ...guardrails, never_discuss: e.target.value })}
                placeholder="e.g. Competitor comparisons, medical advice, legal advice, politics"
              />
            </div>

            <div className="ai-form-row">
              <label>Decline Message</label>
              <textarea
                rows="2"
                value={guardrails.decline_message || ''}
                onChange={e => setGuardrails({ ...guardrails, decline_message: e.target.value })}
                placeholder="I can only help with questions about {{company_name}}..."
              />
            </div>

            <div className="ai-form-row">
              <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <input
                  type="checkbox"
                  checked={guardrails.classifier_enabled !== false}
                  onChange={e => setGuardrails({ ...guardrails, classifier_enabled: e.target.checked })}
                />
                Enable Fast Scope Classifier (Pre-screens messages with small fast model)
              </label>
            </div>

            <div className="ai-form-row">
              <label>&quot;Not On This Website&quot; Reply</label>
              <textarea
                rows="2"
                value={guardrails.not_found_message || ''}
                onChange={e => setGuardrails({ ...guardrails, not_found_message: e.target.value })}
                placeholder="Leave blank to use the built-in message. This is sent when no indexed page matches the visitor's question."
              />
              <small style={{ color: '#64748b' }}>
                Used instead of an invented answer when the knowledge base has no relevant page.
              </small>
            </div>

            {/* Test Guardrails Panel */}
            <div style={{ background: '#f8fafc', padding: 16, borderRadius: 8, border: '1px solid #e2e8f0', marginTop: 16 }}>
              <label style={{ fontSize: 13, fontWeight: 600 }}>Test Guardrails Tool</label>
              <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
                <input
                  type="text"
                  placeholder="e.g. Write me a poem about the moon"
                  value={testQuery}
                  onChange={e => setTestQuery(e.target.value)}
                />
                <button className="ai-btn ai-btn-secondary" onClick={runTestGuardrails} disabled={testing}>
                  {testing ? 'Testing...' : 'Test'}
                </button>
              </div>

              {testResult && (
                <div style={{ marginTop: 12, padding: 10, background: '#fff', borderRadius: 6, border: '1px solid #cbd5e1' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <strong>Verdict:</strong>
                    <span className={`ai-badge ${testResult.is_allowed ? 'ai-badge-success' : 'ai-badge-danger'}`}>
                      {testResult.verdict}
                    </span>
                  </div>
                  <p style={{ margin: '6px 0 0', fontSize: 12.5, color: '#334155' }}>
                    <strong>Simulated Response:</strong> {testResult.simulated_reply}
                  </p>
                </div>
              )}
            </div>

            <div style={{ marginTop: 16 }}>
              <button className="ai-btn ai-btn-primary" onClick={saveAgent}>Save Guardrails</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// -------------------------------------------------------------
// VIEW 2: KNOWLEDGE VIEW
// -------------------------------------------------------------
function KnowledgeView({ sources, setSources, notifySave }) {
  const [activeTab, setActiveTab] = useState('all');
  const [modalOpen, setModalOpen] = useState(false);
  const [modalType, setModalType] = useState('webpage');
  const [newTitle, setNewTitle] = useState('');
  const [newUrl, setNewUrl] = useState('');
  const [newText, setNewText] = useState('');
  const [qaQuestion, setQaQuestion] = useState('');
  const [qaAnswer, setQaAnswer] = useState('');
  const [isScanning, setIsScanning] = useState(false);
  const [scannedPages, setScannedPages] = useState([]);
  const [selectedIds, setSelectedIds] = useState([]);
  const [isBulkDeleting, setIsBulkDeleting] = useState(false);
  const [isSyncing, setIsSyncing] = useState(false);
  const [syncStatus, setSyncStatus] = useState('');
  const [scanSitemaps, setScanSitemaps] = useState([]);
  const [importUrl, setImportUrl] = useState(AdminData.homeUrl || '');
  const [isImporting, setIsImporting] = useState(false);
  const [importProgress, setImportProgress] = useState('');

  // Business info state
  const bizSource = sources.find(s => s.type === 'business_info');
  const [bizInfo, setBizInfo] = useState(
    bizSource?.content ? JSON.parse(bizSource.content) : {}
  );

  const reloadSources = async () => {
    const res = await apiCall(`/knowledge?agent_id=${AdminData.agentId}`);
    if (res.success) setSources(res.data.sources);
  };

  const handleAddSource = async () => {
    let payload = {
      agent_id: AdminData.agentId,
      type: modalType,
      title: newTitle || 'Source',
      auto_index: true
    };

    if (modalType === 'webpage') {
      payload.source_url = newUrl;
      payload.title = newTitle || newUrl;
    } else if (modalType === 'text_block') {
      payload.content = newText;
    } else if (modalType === 'qa') {
      payload.title = qaQuestion;
      payload.content = JSON.stringify({ question: qaQuestion, answer: qaAnswer });
    }

    const res = await apiCall('/knowledge', 'POST', payload);
    if (res.success) {
      setModalOpen(false);
      notifySave('Knowledge source added and indexing queued!');
      reloadSources();
    } else {
      alert(res.message || 'Failed to add source.');
    }
  };

  const deleteSource = async (id) => {
    if (!confirm('Are you sure you want to delete this knowledge source and its vector embeddings?')) return;
    const res = await apiCall(`/knowledge/${id}`, 'DELETE');
    if (res.success) {
      notifySave('Source deleted.');
      reloadSources();
    }
  };

  // Re-read the sitemap: adds new pages, re-indexes changed ones, and drops
  // pages that are no longer listed so stale answers stop being served.
  const handleSyncSitemap = async (force = false) => {
    setIsSyncing(true);
    setSyncStatus('Reading sitemap…');

    try {
      const res = await apiCall('/knowledge/sync-sitemap', 'POST', {
        agent_id: AdminData.agentId,
        url: importUrl || AdminData.homeUrl,
        force: force,
        max_pages: 100
      });

      if (res.success) {
        const d = res.data?.data || {};
        setSyncStatus(res.data?.message || 'Sitemap refreshed.');
        notifySave(res.data?.message || 'Sitemap refreshed.');
        reloadSources();
        return d;
      }

      setSyncStatus(res.message || 'Sitemap refresh failed.');
      alert(res.message || 'Sitemap refresh failed.');
    } catch (e) {
      setSyncStatus('Sitemap refresh failed: ' + (e.message || 'network error'));
      alert('Sitemap refresh failed: ' + (e.message || 'network error'));
    } finally {
      setIsSyncing(false);
    }
  };

  const toggleSourceSelected = (id) => {
    setSelectedIds(prev =>
      prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
    );
  };

  const toggleSelectAllVisible = () => {
    const visibleIds = filtered.map(s => s.id);
    const allSelected = visibleIds.length > 0 && visibleIds.every(id => selectedIds.includes(id));
    setSelectedIds(prev =>
      allSelected
        ? prev.filter(id => !visibleIds.includes(id))
        : Array.from(new Set([...prev, ...visibleIds]))
    );
  };

  const handleDeleteSelected = async () => {
    if (selectedIds.length === 0) return;
    if (!confirm(`Delete ${selectedIds.length} selected knowledge source(s) and their vector chunks? This cannot be undone.`)) return;

    setIsBulkDeleting(true);
    const res = await apiCall('/knowledge/bulk-delete', 'POST', {
      agent_id: AdminData.agentId,
      ids: selectedIds
    });
    setIsBulkDeleting(false);

    if (res.success) {
      notifySave(res.data?.message || `Deleted ${res.data?.deleted || 0} source(s).`);
      setSelectedIds([]);
      reloadSources();
    } else {
      alert(res.message || 'Bulk delete failed.');
    }
  };

  const handleDeleteAll = async () => {
    const count = sources.length;
    const typed = window.prompt(
      `This permanently deletes ALL ${count} knowledge source(s) and every vector chunk they own.\n\n` +
      `The chatbot will lose all of this knowledge immediately and there is no undo.\n\n` +
      `Type DELETE ALL to confirm:`
    );

    if (typed === null) return;
    if (typed.trim().toUpperCase() !== 'DELETE ALL') {
      if (typed.trim() !== '') alert('Confirmation text did not match. Nothing was deleted.');
      return;
    }

    setIsBulkDeleting(true);
    const res = await apiCall('/knowledge/bulk-delete', 'POST', {
      agent_id: AdminData.agentId,
      all: true,
      confirm: 'DELETE ALL'
    });
    setIsBulkDeleting(false);

    if (res.success) {
      notifySave(res.data?.message || 'All knowledge sources deleted.');
      setSelectedIds([]);
      reloadSources();
    } else {
      alert(res.message || 'Delete all failed.');
    }
  };

  const reindexSource = async (id) => {
    notifySave('Indexing in progress...');
    const res = await apiCall(`/knowledge/${id}/index`, 'POST');
    if (res.success) {
      notifySave(res.data.message);
      reloadSources();
    } else {
      alert(res.message || 'Indexing failed.');
    }
  };

  const handleScanWebsite = async () => {
    setIsScanning(true);
    const res = await apiCall('/knowledge/scan', 'POST', { url: AdminData.homeUrl });
    setIsScanning(false);
    if (res.success && res.data.pages) {
      setScannedPages(res.data.pages);
      setScanSitemaps(res.data.sitemaps || []);
    } else {
      alert(res.message || 'Website scan failed.');
    }
  };

  // One-step: crawl via sitemaps, extract content, and index the pages.
  const handleImportWebsite = async () => {
    const url = (importUrl || '').trim();
    if (!url) {
      alert('Please enter a website URL to import.');
      return;
    }

    setIsImporting(true);
    setImportProgress(`Crawling ${url} ...`);

    try {
      const res = await apiCall('/knowledge/import-website', 'POST', {
        agent_id: AdminData.agentId,
        url
      });

      if (res.success) {
        const { imported = [], skipped = [], failed = [], chunks = 0 } = res.data || {};
        const parts = [`${imported.length} page(s) indexed (${chunks} chunks)`];
        if (skipped.length) parts.push(`${skipped.length} already imported`);
        if (failed.length) parts.push(`${failed.length} failed`);

        notifySave(res.data?.message || `Website imported: ${parts.join(', ')}.`);
        setImportUrl('');
        setImportProgress('');
        reloadSources();
      } else {
        setImportProgress('');
        alert(res.message || 'Website import failed.');
      }
    } catch (e) {
      setImportProgress('');
      alert('Website import failed: ' + (e.message || 'network error'));
    }
    setIsImporting(false);
  };

  const addScannedPage = async (page) => {
    await apiCall('/knowledge', 'POST', {
      agent_id: AdminData.agentId,
      type: 'webpage',
      title: page.title,
      source_url: page.url,
      auto_index: true
    });
    notifySave(`Added ${page.title}`);
    reloadSources();
  };

  const saveBizInfo = async () => {
    const payload = {
      agent_id: AdminData.agentId,
      type: 'business_info',
      title: 'Business Profile & Facts',
      content: JSON.stringify(bizInfo),
      auto_index: true
    };

    if (bizSource) {
      await apiCall(`/knowledge/${bizSource.id}`, 'PUT', { content: JSON.stringify(bizInfo) });
      await apiCall(`/knowledge/${bizSource.id}/index`, 'POST');
    } else {
      await apiCall('/knowledge', 'POST', payload);
    }

    notifySave('Business profile saved and indexed!');
    reloadSources();
  };

  // Filter sources
  const filtered = activeTab === 'all' ? sources : sources.filter(s => s.type === activeTab);

  return (
    <div>
      {/* Stats Counter Bar */}
      <div className="ai-grid-stats">
        <div className="ai-stat-card">
          <span className="ai-stat-label">Total Sources</span>
          <span className="ai-stat-value">{sources.length}</span>
        </div>
        <div className="ai-stat-card">
          <span className="ai-stat-label">Indexed Chunks</span>
          <span className="ai-stat-value">{sources.reduce((acc, s) => acc + (parseInt(s.chunk_count, 10) || 0), 0)}</span>
        </div>
        <div className="ai-stat-card">
          <span className="ai-stat-label">Webpages</span>
          <span className="ai-stat-value">{sources.filter(s => s.type === 'webpage').length}</span>
        </div>
        <div className="ai-stat-card">
          <span className="ai-stat-label">Curated Q&A</span>
          <span className="ai-stat-value">{sources.filter(s => s.type === 'qa').length}</span>
        </div>
      </div>

      <div className="ai-card">
        <div className="ai-card-header">
          <div>
            <h3>Knowledge Sources Dashboard</h3>
            <p>Every piece of verified content your chatbot references when writing replies.</p>
          </div>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <button
              className="ai-btn ai-btn-secondary"
              onClick={() => handleSyncSitemap(true)}
              disabled={isSyncing}
              title="Re-read the sitemap: add new pages, refresh changed pages, remove pages no longer listed"
            >
              {isSyncing ? 'Refreshing...' : 'Refresh Sitemap'}
            </button>
            <button className="ai-btn ai-btn-secondary" onClick={handleScanWebsite} disabled={isScanning}>
              {isScanning ? 'Scanning...' : 'Scan Website'}
            </button>
            <button className="ai-btn ai-btn-primary" onClick={() => setModalOpen(true)}>+ Add Knowledge</button>
            {selectedIds.length > 0 && (
              <button className="ai-btn ai-btn-danger" onClick={handleDeleteSelected} disabled={isBulkDeleting}>
                {isBulkDeleting ? 'Deleting...' : `Delete Selected (${selectedIds.length})`}
              </button>
            )}
            {sources.length > 0 && (
              <button
                className="ai-btn ai-btn-danger"
                onClick={handleDeleteAll}
                disabled={isBulkDeleting}
                title="Permanently delete every knowledge source and its vector chunks"
              >
                {isBulkDeleting ? 'Deleting...' : 'Delete All'}
              </button>
            )}
          </div>
        </div>

        {/* Subtabs */}
        <div style={{ display: 'flex', gap: 8, marginBottom: 18, borderBottom: '1px solid #f1f5f9', paddingBottom: 10 }}>
          {['all', 'webpage', 'qa', 'text_block', 'file', 'business_info'].map(t => (
            <button
              key={t}
              className={`ai-btn ai-btn-sm ${activeTab === t ? 'ai-btn-primary' : 'ai-btn-secondary'}`}
              onClick={() => setActiveTab(t)}
            >
              {t === 'all' && 'All Sources'}
              {t === 'webpage' && 'Webpages'}
              {t === 'qa' && 'Q&A (High Priority)'}
              {t === 'text_block' && 'Text Blocks'}
              {t === 'file' && 'Uploaded Files'}
              {t === 'business_info' && 'Business Profile Form'}
            </button>
          ))}
        </div>

        {activeTab === 'business_info' ? (
          <div>
            <h4>Structured Business Facts</h4>
            <p style={{ color: '#64748b', fontSize: 13, marginBottom: 16 }}>
              The AI references these facts to answer questions about hours, address, services, and contact out of the box.
            </p>

            <div className="ai-grid-2">
              <div className="ai-form-row">
                <label>Company / Business Name</label>
                <input
                  type="text"
                  value={bizInfo.business_name || ''}
                  onChange={e => setBizInfo({ ...bizInfo, business_name: e.target.value })}
                />
              </div>

              <div className="ai-form-row">
                <label>Industry</label>
                <input
                  type="text"
                  value={bizInfo.industry || ''}
                  onChange={e => setBizInfo({ ...bizInfo, industry: e.target.value })}
                />
              </div>

              <div className="ai-form-row">
                <label>Business Hours</label>
                <input
                  type="text"
                  value={bizInfo.business_hours || ''}
                  onChange={e => setBizInfo({ ...bizInfo, business_hours: e.target.value })}
                  placeholder="e.g. Mon-Fri 9:00 AM - 5:00 PM"
                />
              </div>

              <div className="ai-form-row">
                <label>Phone Number</label>
                <input
                  type="text"
                  value={bizInfo.phone || ''}
                  onChange={e => setBizInfo({ ...bizInfo, phone: e.target.value })}
                />
              </div>

              <div className="ai-form-row">
                <label>Contact Email</label>
                <input
                  type="email"
                  value={bizInfo.email || ''}
                  onChange={e => setBizInfo({ ...bizInfo, email: e.target.value })}
                />
              </div>

              <div className="ai-form-row">
                <label>Physical Address</label>
                <input
                  type="text"
                  value={bizInfo.address || ''}
                  onChange={e => setBizInfo({ ...bizInfo, address: e.target.value })}
                />
              </div>

              <div className="ai-form-row" style={{ gridColumn: 'span 2' }}>
                <label>Services Offered</label>
                <textarea
                  rows="2"
                  value={bizInfo.services || ''}
                  onChange={e => setBizInfo({ ...bizInfo, services: e.target.value })}
                  placeholder="List your core services..."
                />
              </div>
            </div>

            <button className="ai-btn ai-btn-primary" onClick={saveBizInfo}>Save Business Facts</button>
          </div>
        ) : (
          <table className="ai-table">
            <thead>
              <tr>
                <th style={{ width: 34 }}>
                  <input
                    type="checkbox"
                    aria-label="Select all visible sources"
                    checked={filtered.length > 0 && filtered.every(s => selectedIds.includes(s.id))}
                    onChange={toggleSelectAllVisible}
                  />
                </th>
                <th>Title / Source</th>
                <th>Type</th>
                <th>Status</th>
                <th>Chunks</th>
                <th>Last Indexed</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map(s => (
                <tr key={s.id}>
                  <td>
                    <input
                      type="checkbox"
                      aria-label={`Select ${s.title}`}
                      checked={selectedIds.includes(s.id)}
                      onChange={() => toggleSourceSelected(s.id)}
                    />
                  </td>
                  <td>
                    <strong>{s.title}</strong>
                    {s.source_url && <div style={{ fontSize: 11, color: '#64748b' }}>{s.source_url}</div>}
                    {s.error_message && <div style={{ fontSize: 11, color: '#dc2626' }}>Error: {s.error_message}</div>}
                  </td>
                  <td><span className="ai-badge ai-badge-neutral">{s.type}</span></td>
                  <td>
                    <span className={`ai-badge ${
                      s.status === 'indexed' ? 'ai-badge-success' :
                      s.status === 'processing' ? 'ai-badge-info' :
                      s.status === 'failed' ? 'ai-badge-danger' : 'ai-badge-warning'
                    }`}>
                      {s.status}
                    </span>
                  </td>
                  <td>{s.chunk_count || 0}</td>
                  <td>{s.last_indexed_at ? new Date(s.last_indexed_at).toLocaleDateString() : 'Never'}</td>
                  <td>
                    <div style={{ display: 'flex', gap: 6 }}>
                      <button className="ai-btn ai-btn-secondary ai-btn-sm" onClick={() => reindexSource(s.id)}>Re-index</button>
                      <button className="ai-btn ai-btn-danger ai-btn-sm" onClick={() => deleteSource(s.id)}>Delete</button>
                    </div>
                  </td>
                </tr>
              ))}
              {filtered.length === 0 && (
                <tr>
                  <td colSpan="6" style={{ textAlign: 'center', padding: 24, color: '#94a3b8' }}>
                    No knowledge sources found in this category. Click "+ Add Knowledge" above.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {syncStatus && (
          <div className="ai-callout ai-callout-info" style={{ marginTop: 12 }}>{syncStatus}</div>
        )}

        {/* Website Import: sitemap crawl + content extraction + indexing in one step */}
      <div className="ai-card">
        <div className="ai-card-header">
          <div>
            <h3>Import a Website</h3>
            <p>Enter a website URL. The plugin reads its XML sitemaps, crawls every page it lists, extracts the readable text, and indexes it into the knowledge base. Large sites can take several minutes.</p>
          </div>
        </div>

        <div style={{ display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="ai-form-row" style={{ flex: '1 1 320px', marginBottom: 0 }}>
            <label>Website URL</label>
            <input
              type="url"
              placeholder="https://example.com"
              value={importUrl}
              onChange={e => setImportUrl(e.target.value)}
              disabled={isImporting}
            />
          </div>

          <button className="ai-btn ai-btn-primary" onClick={handleImportWebsite} disabled={isImporting}>
            {isImporting ? 'Importing…' : 'Import & Index Website'}
          </button>
        </div>

        {importProgress && (
          <div className="ai-callout ai-callout-info" style={{ marginTop: 12 }}>{importProgress}</div>
        )}
      </div>

      {/* Discovered Pages from Scan */}
      {scannedPages.length > 0 && (
        <div className="ai-card">
          <h4>Discovered Pages on Your Site</h4>
          <p style={{ fontSize: 13, color: '#64748b' }}>
            {scanSitemaps.length > 0
              ? `Found via ${scanSitemaps.length} XML sitemap(s). Use "Import & Index Website" above to add them all at once.`
              : 'No XML sitemap was found, so these pages were discovered by following links.'}
          </p>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 12 }}>
            {scannedPages.map((p, idx) => (
              <div key={idx} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '8px 12px', background: '#f8fafc', borderRadius: 6, border: '1px solid #e2e8f0' }}>
                <div>
                  <strong>{p.title}</strong>
                  <div style={{ fontSize: 11, color: '#64748b' }}>{p.url}</div>
                </div>
                {p.imported
                  ? <span className="ai-badge ai-badge-success">Already indexed</span>
                  : <button className="ai-btn ai-btn-secondary ai-btn-sm" onClick={() => addScannedPage(p)}>+ Add</button>}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Add Knowledge Modal */}
      {modalOpen && (
        <div className="ai-admin-modal-backdrop">
          <div className="ai-admin-modal">
            <div className="ai-modal-head">
              <h3>Add New Knowledge Source</h3>
              <button style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 18 }} onClick={() => setModalOpen(false)}>✕</button>
            </div>
            <div className="ai-modal-body">
              <div className="ai-form-row">
                <label>Source Type</label>
                <select value={modalType} onChange={e => setModalType(e.target.value)}>
                  <option value="webpage">Webpage URL (Crawled & Cleaned)</option>
                  <option value="qa">Curated Q&A Pair (Highest Priority)</option>
                  <option value="text_block">Plain Text Block</option>
                </select>
              </div>

              {modalType === 'webpage' && (
                <>
                  <div className="ai-form-row">
                    <label>Webpage URL</label>
                    <input type="url" placeholder="https://example.com/about" value={newUrl} onChange={e => setNewUrl(e.target.value)} />
                  </div>
                  <div className="ai-form-row">
                    <label>Title (Optional)</label>
                    <input type="text" placeholder="About Us" value={newTitle} onChange={e => setNewTitle(e.target.value)} />
                  </div>
                </>
              )}

              {modalType === 'qa' && (
                <>
                  <div className="ai-form-row">
                    <label>Question</label>
                    <input type="text" placeholder="e.g. Do you offer emergency 24/7 service?" value={qaQuestion} onChange={e => setQaQuestion(e.target.value)} />
                  </div>
                  <div className="ai-form-row">
                    <label>Answer</label>
                    <textarea rows="3" placeholder="Yes, our on-call technicians are available 24/7..." value={qaAnswer} onChange={e => setQaAnswer(e.target.value)} />
                  </div>
                </>
              )}

              {modalType === 'text_block' && (
                <>
                  <div className="ai-form-row">
                    <label>Title</label>
                    <input type="text" placeholder="Company Background" value={newTitle} onChange={e => setNewTitle(e.target.value)} />
                  </div>
                  <div className="ai-form-row">
                    <label>Text Content</label>
                    <textarea rows="5" placeholder="Enter text..." value={newText} onChange={e => setNewText(e.target.value)} />
                  </div>
                </>
              )}
            </div>
            <div className="ai-modal-foot">
              <button className="ai-btn ai-btn-secondary" onClick={() => setModalOpen(false)}>Cancel</button>
              <button className="ai-btn ai-btn-primary" onClick={handleAddSource}>Save & Index</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// -------------------------------------------------------------
// VIEW 3: SKILLS VIEW
// -------------------------------------------------------------
function SkillsView({ settings, setSettings, notifySave, notifyError }) {
  const [followUp, setFollowUp] = useState(settings.skills_follow_up || {});
  const [contact, setContact] = useState(settings.skills_contact || {});
  const [leads, setLeads] = useState([]);

  useEffect(() => {
    // Load leads
    apiCall('/leads').then(res => {
      if (res?.success) setLeads(res.data.leads || []);
    });
  }, []);

  const saveSkills = async () => {
    const res = await apiCall('/settings', 'POST', {
      settings: {
        skills_follow_up: followUp,
        skills_contact: contact
      }
    });
    if (res.success) {
      notifySave('Skills configuration updated!');
    } else {
      notifyError(res.message || 'Failed to save skills configuration.');
    }
  };

  return (
    <div className="ai-grid-2">
      {/* Follow-up message */}
      <div className="ai-card">
        <div className="ai-card-header">
          <div>
            <h3>Follow-up Message Skill</h3>
            <p>Proactively engage visitors after defined triggers.</p>
          </div>
        </div>

        <div className="ai-form-row">
          <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <input
              type="checkbox"
              checked={followUp.enabled !== false}
              onChange={e => setFollowUp({ ...followUp, enabled: e.target.checked })}
            />
            Enable Proactive Follow-up
          </label>
        </div>

        <div className="ai-form-row">
          <label>Trigger Event</label>
          <select
            value={followUp.trigger || 'first_response'}
            onChange={e => setFollowUp({ ...followUp, trigger: e.target.value })}
          >
            <option value="first_response">After First Response</option>
            <option value="after_x_messages">After X Messages</option>
            <option value="time_delay">After X Seconds of Inactivity</option>
          </select>
        </div>

        <div className="ai-form-row">
          <label>Delay (Seconds)</label>
          <input
            type="number"
            value={followUp.delay || 8}
            onChange={e => setFollowUp({ ...followUp, delay: parseInt(e.target.value, 10) })}
          />
        </div>

        <div className="ai-form-row">
          <label>Proactive Message</label>
          <textarea
            rows="2"
            value={followUp.message || ''}
            onChange={e => setFollowUp({ ...followUp, message: e.target.value })}
            placeholder="e.g. Would you like to speak with one of our experts?"
          />
        </div>

        <button className="ai-btn ai-btn-primary" onClick={saveSkills}>Save Follow-up</button>
      </div>

      {/* Contact human */}
      <div className="ai-card">
        <div className="ai-card-header">
          <div>
            <h3>Contact Human Skill & Handover</h3>
            <p>Allow visitors to reach a real team member.</p>
          </div>
        </div>

        <div className="ai-form-row">
          <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <input
              type="checkbox"
              checked={contact.enabled !== false}
              onChange={e => setContact({ ...contact, enabled: e.target.checked })}
            />
            Enable "Contact a Human" Handover
          </label>
        </div>

        <div className="ai-form-row">
          <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <input
              type="checkbox"
              checked={contact.send_email !== false}
              onChange={e => setContact({ ...contact, send_email: e.target.checked })}
            />
            Send Notification Email on Submission
          </label>
        </div>

        <div className="ai-form-row">
          <label>Notification Recipient</label>
          <input
            type="email"
            value={contact.notification_recipient || AdminData.adminEmail}
            onChange={e => setContact({ ...contact, notification_recipient: e.target.value })}
          />
        </div>

        <div className="ai-form-row">
          <label>Confirmation Message</label>
          <textarea
            rows="2"
            value={contact.confirmation_message || ''}
            onChange={e => setContact({ ...contact, confirmation_message: e.target.value })}
            placeholder="Thanks! Your request has been received..."
          />
        </div>

        <button className="ai-btn ai-btn-primary" onClick={saveSkills}>Save Contact Settings</button>
      </div>
    </div>
  );
}

// -------------------------------------------------------------
// VIEW 4: SETTINGS & KEY POOL WITH LIVE PREVIEW
// -------------------------------------------------------------
function SettingsView({ agent, settings, keys, setSettings, setKeys, notifySave, notifyError, notifyLoading }) {
  const [subTab, setSubTab] = useState('appearance');
  const [theme, setTheme] = useState({ ...settings.theme });
  const [placement, setPlacement] = useState({ ...settings.placement });
  const [providers, setProviders] = useState({ ...settings.providers });
  const [email, setEmail] = useState({ ...settings.email });
  const [session, setSession] = useState({ ...settings.session });
  const [sounds, setSounds] = useState({ ...settings.sounds });
  const [language, setLanguage] = useState({ ...settings.language });
  const [knowledge, setKnowledge] = useState({ ...(settings.knowledge || {}) });
  const [responseBehavior, setResponseBehavior] = useState(() => ({
    auto_links_verified: false,
    auto_links_citations: false,
    allow_requested_links: true,
    link_style: 'inline',
    max_links_per_answer: 2,
    link_relevance_threshold: 0.60,
    rebuild_interval: 'daily',
    deduplicate_conversation_links: true,
    sitemap_urls: '',
    allowed_domains: '',
    excluded_patterns: "draft\ntest\nstaging\nai-chatbot\npreview\n/wp-admin\n/wp-json\n/feed\n/cart\n/checkout\n/my-account\n?replytocom",
    cron_revalidation_enabled: true,
    contact_url: '',
    ...(settings.response_behavior || settings.response || {})
  }));

  const [linkStatus, setLinkStatus] = useState(null);
  const [isLoadingLinkStatus, setIsLoadingLinkStatus] = useState(false);
  const [isRebuildingLinks, setIsRebuildingLinks] = useState(false);
  const [linkRebuildNotice, setLinkRebuildNotice] = useState('');
  const [showLinkLogs, setShowLinkLogs] = useState(false);

  const fetchLinkStatus = useCallback(async () => {
    setIsLoadingLinkStatus(true);
    try {
      const res = await apiCall('/links/status', 'GET');
      if (res.success && res.data) {
        setLinkStatus(res.data);
      }
    } catch (e) {
      // silent
    } finally {
      setIsLoadingLinkStatus(false);
    }
  }, []);

  const handleRebuildLinks = async () => {
    setIsRebuildingLinks(true);
    setLinkRebuildNotice('');
    try {
      const res = await apiCall('/links/rebuild', 'POST', { agent_id: AdminData.agentId || 1 });
      if (res.success && res.data) {
        setLinkStatus(res.data);
        setLinkRebuildNotice(`Rebuild complete! Found ${res.data.valid_count || 0} valid URLs, ${res.data.failed_count || 0} failed.`);
      } else {
        setLinkRebuildNotice(res.message || 'Failed to rebuild links.');
      }
    } catch (e) {
      setLinkRebuildNotice('Error during rebuild. Please check network logs.');
    } finally {
      setIsRebuildingLinks(false);
    }
  };

  // Custom theme presets created by the administrator. Stored in the
  // "theme_presets" settings group and persisted through the same
  // /settings endpoint as every other group.
  const [customPresets, setCustomPresets] = useState(() => {
    const stored = settings.theme_presets && settings.theme_presets.custom;
    return Array.isArray(stored) ? stored : [];
  });
  const [presetModalOpen, setPresetModalOpen] = useState(false);
  const [newPresetName, setNewPresetName] = useState('');

  // Live model catalogue for the selected chat provider. The previous hardcoded
  // list offered models a given API key cannot use, which only surfaced later
  // as a model_not_found error at chat time.
  const [modelCatalogue, setModelCatalogue] = useState({ chat: [], embeddings: [], live: false, notice: '', provider: '' });
  const [isLoadingModels, setIsLoadingModels] = useState(false);
  // Guards the settings save so a rapid double-click cannot fire
  // duplicate POST /settings requests.
  const [isSaving, setIsSaving] = useState(false);

  // Dedicated key visibility & live test statuses
  const [showOpenAIKey, setShowOpenAIKey] = useState(false);
  const [showMistralKey, setShowMistralKey] = useState(false);
  const [testingProvider, setTestingProvider] = useState(null);
  const [providerTestResult, setProviderTestResult] = useState({});

  const testDirectProviderKey = async (providerId, customKey) => {
    setTestingProvider(providerId);
    const toast = typeof notifyLoading === 'function' ? notifyLoading(`Testing ${providerId === 'openai' ? 'OpenAI' : 'Mistral'} connection...`) : null;
    try {
      const res = await apiCall('/keys/test', 'POST', {
        provider: providerId,
        key: customKey || (providerId === 'openai' ? providers.openai_api_key : providers.mistral_api_key) || '',
        agent_id: AdminData.agentId
      });
      if (res.success) {
        const msg = res.data?.message || `${providerId === 'openai' ? 'OpenAI' : 'Mistral'} API connected successfully!`;
        if (toast) {
          toast.update({ type: 'success', title: 'Connected', message: msg });
        } else {
          notifySave(msg);
        }
        setProviderTestResult(prev => ({
          ...prev,
          [providerId]: { success: true, message: msg }
        }));
        if (providers.chat_provider === providerId) {
          loadProviderModels(providerId);
        }
      } else {
        const errMsg = res.message || 'Connection failed. Please check the API key.';
        if (toast) {
          toast.update({ type: 'error', title: 'Connection failed', message: errMsg });
        } else {
          notifyError(errMsg);
        }
        setProviderTestResult(prev => ({
          ...prev,
          [providerId]: { success: false, message: errMsg }
        }));
      }
    } catch (e) {
      const errMsg = e.message || 'Connection error.';
      if (toast) {
        toast.update({ type: 'error', title: 'Connection error', message: errMsg });
      } else {
        notifyError(errMsg);
      }
      setProviderTestResult(prev => ({
        ...prev,
        [providerId]: { success: false, message: errMsg }
      }));
    } finally {
      setTestingProvider(null);
    }
  };

  const loadProviderModels = async (providerId) => {
    if (!providerId) return;
    setIsLoadingModels(true);
    try {
      const res = await apiCall(`/providers/models?provider=${encodeURIComponent(providerId)}&agent_id=${AdminData.agentId}`);
      if (res.success && res.data) {
        setModelCatalogue({
          chat: res.data.chat || [],
          embeddings: res.data.embeddings || [],
          supportsEmbeddings: !!res.data.supports_embeddings,
          live: !!res.data.live,
          notice: res.data.notice || '',
          provider: res.data.provider || providerId
        });
      } else {
        setModelCatalogue({ chat: [], embeddings: [], live: false, notice: res.message || 'Could not load models.', provider: providerId });
      }
    } catch (e) {
      setModelCatalogue({ chat: [], embeddings: [], live: false, notice: 'Could not load models.', provider: providerId });
    }
    setIsLoadingModels(false);
  };

  // Load the catalogue whenever the selected provider changes, and on mount.
  useEffect(() => {
    loadProviderModels(providers.chat_provider || 'groq');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [providers.chat_provider]);

  useEffect(() => {
    if (subTab === 'response_behavior') {
      fetchLinkStatus();
    }
  }, [subTab, fetchLinkStatus]);

  // Live Interactive Preview State
  const [previewDevice, setPreviewDevice] = useState('desktop'); // 'desktop' | 'mobile'
  const [previewOpen, setPreviewOpen] = useState(true);
  const [previewInput, setPreviewInput] = useState('');
  const [previewTyping, setPreviewTyping] = useState(false);
  const [previewMessages, setPreviewMessages] = useState([
    {
      id: 1,
      role: 'assistant',
      text: agent.greeting?.replace('{{company_name}}', AdminData.siteName).replace('{{agent_name}}', agent.name) || 'Hi there! 👋 How can I help you today?'
    },
    {
      id: 2,
      role: 'user',
      text: 'What services or products do you offer?'
    },
    {
      id: 3,
      role: 'assistant',
      text: 'We provide specialized solutions, 24/7 client support, and custom integrations tailored to your business needs.'
    }
  ]);

  const handlePreviewSend = (e) => {
    if (e) e.preventDefault();
    const text = previewInput.trim();
    if (!text) return;
    const newMsg = { id: Date.now(), role: 'user', text };
    setPreviewMessages(prev => [...prev, newMsg]);
    setPreviewInput('');
    setPreviewTyping(true);
    setTimeout(() => {
      setPreviewTyping(false);
      setPreviewMessages(prev => [
        ...prev,
        {
          id: Date.now() + 1,
          role: 'assistant',
          text: `Thanks for testing! Your custom colors, bubbles, and typography look pixel-perfect in both Desktop and Phone preview.`
        }
      ]);
    }, 650);
  };

  const handlePreviewQuestionClick = (qText) => {
    const newMsg = { id: Date.now(), role: 'user', text: qText };
    setPreviewMessages(prev => [...prev, newMsg]);
    setPreviewTyping(true);
    setTimeout(() => {
      setPreviewTyping(false);
      setPreviewMessages(prev => [
        ...prev,
        {
          id: Date.now() + 1,
          role: 'assistant',
          text: `Here is details about "${qText}": Everything is dynamically styled according to your active theme settings!`
        }
      ]);
    }, 650);
  };

  const resetPreviewChat = () => {
    setPreviewMessages([
      {
        id: 1,
        role: 'assistant',
        text: agent.greeting?.replace('{{company_name}}', AdminData.siteName).replace('{{agent_name}}', agent.name) || 'Hi there! 👋 How can I help you today?'
      }
    ]);
    setPreviewTyping(false);
    setPreviewOpen(true);
  };

  const mediaFrameRef = useRef(null);

  // Avatar selection. Stored in theme.avatar_url, which the widget already
  // prefers over the initial-letter fallback.
  const openAvatarPicker = useCallback(() => {
    if (!window.wp || !window.wp.media) {
      alert('The WordPress media library is not available on this screen.');
      return;
    }

    if (!mediaFrameRef.current) {
      mediaFrameRef.current = window.wp.media({
        title: 'Choose chatbot avatar',
        multiple: false,
        library: { type: 'image' },
        button: { text: 'Use this image' }
      });

      mediaFrameRef.current.on('select', () => {
        const selection = mediaFrameRef.current.state().get('selection').first();
        if (!selection) return;
        const att = selection.toJSON();
        const url =
          (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) ||
          (att.sizes && att.sizes.medium && att.sizes.medium.url) ||
          att.url;
        setTheme({ ...theme, avatar_url: url });
        notifySave('Avatar selected. Remember to save settings.');
      });
    }

    mediaFrameRef.current.open();
  }, [theme]);

  const clearAvatar = useCallback(() => {
    setTheme({ ...theme, avatar_url: '' });
    notifySave('Avatar removed. Remember to save settings.');
  }, [theme]);

  const applyPreset = (presetKey) => {
    let p = { preset: presetKey };
    if (presetKey === 'modern') {
      p = { preset: 'modern', primary_color: '#2563eb', secondary_color: '#1d4ed8', bg_color: '#ffffff', text_color: '#0f172a', bot_bubble_bg: '#f1f5f9', bot_bubble_text: '#0f172a', user_bubble_bg: '#2563eb', user_bubble_text: '#ffffff', border_radius: 16, font_family: 'system-ui, -apple-system, sans-serif' };
    } else if (presetKey === 'minimal') {
      p = { preset: 'minimal', primary_color: '#18181b', secondary_color: '#09090b', bg_color: '#ffffff', text_color: '#18181b', bot_bubble_bg: '#f4f4f5', bot_bubble_text: '#18181b', user_bubble_bg: '#18181b', user_bubble_text: '#ffffff', border_radius: 8, font_family: 'Inter, sans-serif' };
    } else if (presetKey === 'emerald') {
      p = { preset: 'emerald', primary_color: '#059669', secondary_color: '#047857', bg_color: '#ffffff', text_color: '#064e3b', bot_bubble_bg: '#ecfdf5', bot_bubble_text: '#064e3b', user_bubble_bg: '#059669', user_bubble_text: '#ffffff', border_radius: 14, font_family: 'Outfit, sans-serif' };
    } else if (presetKey === 'purple') {
      p = { preset: 'purple', primary_color: '#6366f1', secondary_color: '#4f46e5', bg_color: '#ffffff', text_color: '#1e1b4b', bot_bubble_bg: '#eef2ff', bot_bubble_text: '#1e1b4b', user_bubble_bg: '#6366f1', user_bubble_text: '#ffffff', border_radius: 18, font_family: 'Poppins, sans-serif' };
    } else if (presetKey === 'sunset') {
      p = { preset: 'sunset', primary_color: '#ea580c', secondary_color: '#c2410c', bg_color: '#ffffff', text_color: '#431407', bot_bubble_bg: '#fff7ed', bot_bubble_text: '#431407', user_bubble_bg: '#ea580c', user_bubble_text: '#ffffff', border_radius: 16, font_family: 'Roboto, sans-serif' };
    } else if (presetKey === 'dark') {
      p = { preset: 'dark', primary_color: '#3b82f6', secondary_color: '#2563eb', bg_color: '#0f172a', text_color: '#f8fafc', bot_bubble_bg: '#1e293b', bot_bubble_text: '#f1f5f9', user_bubble_bg: '#3b82f6', user_bubble_text: '#ffffff', border_radius: 16, font_family: 'Inter, sans-serif' };
    }
    setTheme({ ...theme, ...p });
  };

  // Apply a user-created preset. Custom presets carry a full colour
  // snapshot, so applying one is a plain state merge keyed by the
  // preset id (which is also how the chip list marks it active).
  const applyCustomPreset = (preset) => {
    setTheme({
      ...theme,
      preset: preset.id,
      primary_color: preset.primary_color,
      secondary_color: preset.secondary_color,
      bg_color: preset.bg_color,
      text_color: preset.text_color,
      bot_bubble_bg: preset.bot_bubble_bg,
      bot_bubble_text: preset.bot_bubble_text,
      user_bubble_bg: preset.user_bubble_bg,
      user_bubble_text: preset.user_bubble_text,
      border_radius: preset.border_radius,
      font_family: preset.font_family
    });
  };

  // Persist only the theme_presets group. save_all() writes the
  // groups present in the payload, so this never touches the rest
  // of the settings and bypasses provider/language validation.
  const persistCustomPresets = async (updated) => {
    const res = await apiCall('/settings', 'POST', {
      settings: { theme_presets: { custom: updated } }
    });
    return !!res.success;
  };

  // Update theme fields. When a custom preset is active, the changed
  // fields are written back into that preset's stored snapshot so the
  // preset chip dot always shows the latest colors. Built-in presets
  // carry no stored snapshot, so editing any field marks the theme as
  // the unsaved "custom" preset instead.
  const updateTheme = (partial) => {
    // Derive a cohesive secondary from the primary so the chat
    // header gradient (primary -> secondary) stays single-hue.
    // Without this, changing only the primary leaves the previous
    // secondary in the gradient, which reads as the default colour
    // still being present instead of fully overridden.
    if (
      partial.primary_color &&
      !partial.secondary_color &&
      /^#?[0-9a-f]{6}$/i.test(String(partial.primary_color).trim())
    ) {
      partial = { ...partial, secondary_color: darkenColor(partial.primary_color) };
    }
    const active = customPresets.find(p => p.id === theme.preset);
    if (active) {
      setCustomPresets(customPresets.map(p =>
        p.id === active.id ? { ...p, ...partial } : p
      ));
      setTheme({ ...theme, ...partial });
    } else {
      setTheme({ ...theme, ...partial, preset: 'custom' });
    }
  };

  const createCustomPreset = async () => {
    const name = newPresetName.trim();
    if (!name) return;

    // A new preset starts from the plugin's default palette rather
    // than whichever preset happens to be active, so every preset
    // begins from the same baseline instead of inheriting the
    // current theme.
    const preset = {
      id: 'custom_' + Date.now(),
      name,
      primary_color: '#2563eb',
      secondary_color: '#1d4ed8',
      bg_color: '#ffffff',
      text_color: '#0f172a',
      bot_bubble_bg: '#f1f5f9',
      bot_bubble_text: '#0f172a',
      user_bubble_bg: '#2563eb',
      user_bubble_text: '#ffffff',
      border_radius: 16,
      font_family: 'system-ui, -apple-system, sans-serif'
    };

    const updated = [...customPresets, preset];
    setCustomPresets(updated);
    // Apply the new preset so the live preview matches the snapshot
    // stored on the preset (and the chip that is now active).
    setTheme({
      ...theme,
      primary_color: preset.primary_color,
      secondary_color: preset.secondary_color,
      bg_color: preset.bg_color,
      text_color: preset.text_color,
      bot_bubble_bg: preset.bot_bubble_bg,
      bot_bubble_text: preset.bot_bubble_text,
      user_bubble_bg: preset.user_bubble_bg,
      user_bubble_text: preset.user_bubble_text,
      border_radius: preset.border_radius,
      font_family: preset.font_family,
      preset: preset.id
    });
    setPresetModalOpen(false);
    setNewPresetName('');

    if (await persistCustomPresets(updated)) {
      notifySave(`Preset "${name}" created.`);
    } else {
      notifyError('Failed to save the new preset.');
    }
  };

  const deleteCustomPreset = async (id) => {
    const preset = customPresets.find(p => p.id === id);
    const updated = customPresets.filter(p => p.id !== id);
    setCustomPresets(updated);

    // Fall back to a built-in preset when the active one is removed.
    if (theme.preset === id) {
      setTheme({ ...theme, preset: 'modern' });
    }

    if (await persistCustomPresets(updated)) {
      notifySave(`Preset "${preset ? preset.name : 'Preset'}" deleted.`);
    } else {
      notifyError('Failed to delete the preset.');
    }
  };

  // Key Pool Modal
  const [keyModalOpen, setKeyModalOpen] = useState(false);
  const [newKeyLabel, setNewKeyLabel] = useState('');
  const [newKeyValue, setNewKeyValue] = useState('');
  const [newKeyPriority, setNewKeyPriority] = useState(0);
  const [badge, setBadge] = useState({
    enabled: true,
    mode: 'dot',
    show_on_load: true,
    load_delay_ms: 1200,
    nudge_after_ms: 0,
    max_per_day: 1,
    ...(settings.badge || {})
  });

  const saveAllSettings = async () => {
    if (isSaving) return;
    setIsSaving(true);

    const cleanProviders = { ...providers };
    if (cleanProviders.openai_api_key) {
      let keyVal = String(cleanProviders.openai_api_key).trim();
      if (!keyVal.includes('•') && !keyVal.includes('****')) {
        keyVal = keyVal.replace(/^["']|["']$/g, '');
        if (keyVal.toLowerCase().startsWith('bearer ')) {
          keyVal = keyVal.substring(7).trim();
        }
        cleanProviders.openai_api_key = keyVal;
      }
    }
    if (cleanProviders.mistral_api_key) {
      let keyVal = String(cleanProviders.mistral_api_key).trim();
      if (!keyVal.includes('•') && !keyVal.includes('****')) {
        keyVal = keyVal.replace(/^["']|["']$/g, '');
        if (keyVal.toLowerCase().startsWith('bearer ')) {
          keyVal = keyVal.substring(7).trim();
        }
        cleanProviders.mistral_api_key = keyVal;
      }
    }

    const payload = {
      theme,
      placement,
      providers: cleanProviders,
      email,
      session,
      sounds,
      language,
      theme_presets: { custom: customPresets },
      badge: {
        ...badge,
        enabled: !!badge.enabled,
        show_on_load: !!badge.show_on_load,
        load_delay_ms: parseInt(badge.load_delay_ms, 10) || 0,
        nudge_after_ms: parseInt(badge.nudge_after_ms, 10) || 0,
        max_per_day: parseInt(badge.max_per_day, 10) || 1
      },
      response_behavior: {
        auto_links_verified: !!responseBehavior.auto_links_verified,
        auto_links_citations: !!responseBehavior.auto_links_citations,
        allow_requested_links: responseBehavior.allow_requested_links !== false,
        link_style: responseBehavior.link_style || 'inline',
        max_links_per_answer: Math.max(1, Math.min(5, parseInt(responseBehavior.max_links_per_answer, 10) || 2)),
        link_relevance_threshold: parseFloat(responseBehavior.link_relevance_threshold) || 0.60,
        rebuild_interval: responseBehavior.rebuild_interval || 'daily',
        deduplicate_conversation_links: responseBehavior.deduplicate_conversation_links !== false,
        sitemap_urls: String(responseBehavior.sitemap_urls || '').trim(),
        allowed_domains: String(responseBehavior.allowed_domains || '').trim(),
        excluded_patterns: String(responseBehavior.excluded_patterns || '').trim(),
        cron_revalidation_enabled: responseBehavior.cron_revalidation_enabled !== false,
        contact_url: String(responseBehavior.contact_url || '').trim()
      },
      response: {
        auto_links_verified: !!responseBehavior.auto_links_verified,
        auto_links_citations: !!responseBehavior.auto_links_citations,
        allow_requested_links: responseBehavior.allow_requested_links !== false,
        link_style: responseBehavior.link_style || 'inline',
        max_links_per_answer: Math.max(1, Math.min(5, parseInt(responseBehavior.max_links_per_answer, 10) || 2)),
        link_relevance_threshold: parseFloat(responseBehavior.link_relevance_threshold) || 0.60,
        rebuild_interval: responseBehavior.rebuild_interval || 'daily',
        deduplicate_conversation_links: responseBehavior.deduplicate_conversation_links !== false,
        sitemap_urls: String(responseBehavior.sitemap_urls || '').trim(),
        allowed_domains: String(responseBehavior.allowed_domains || '').trim(),
        excluded_patterns: String(responseBehavior.excluded_patterns || '').trim(),
        cron_revalidation_enabled: responseBehavior.cron_revalidation_enabled !== false,
        contact_url: String(responseBehavior.contact_url || '').trim()
      },
      knowledge: {
        ...knowledge,
        auto_sync: !!knowledge.auto_sync,
        sync_frequency_hours: parseInt(knowledge.sync_frequency_hours, 10) || 24,
        min_relevance_score: parseFloat(knowledge.min_relevance_score)
      }
    };

    try {
      const res = await apiCall('/settings', 'POST', { settings: payload });
      if (res.success) {
        setSettings(prev => ({ ...prev, ...payload }));
        notifySave('Settings saved successfully.');
        if (cleanProviders.chat_provider === 'openai') {
          loadProviderModels('openai');
        }
      } else {
        notifyError(res.message || 'Failed to save. Please review the settings and try again.');
      }
    } finally {
      setIsSaving(false);
    }
  };

  const handleAddKey = async () => {
    if (!newKeyValue) return;
    const res = await apiCall('/keys', 'POST', {
      provider: 'groq',
      label: newKeyLabel || 'Groq Account Key',
      key: newKeyValue,
      priority: parseInt(newKeyPriority, 10)
    });
    if (res.success) {
      setKeyModalOpen(false);
      setNewKeyValue('');
      notifySave('Groq API Key added to pool!');
      // Reload keys
      const updated = await apiCall('/keys?provider=groq');
      if (updated.success) setKeys(updated.data.keys);
    } else {
      notifyError(res.message || 'Failed to add API key.');
    }
  };

  const deleteKey = async (id) => {
    if (!confirm('Delete this key from the pool?')) return;
    const res = await apiCall(`/keys/${id}`, 'DELETE');
    if (res.success) {
      setKeys(keys.filter(k => k.id !== id));
      notifySave('Key removed.');
    } else {
      notifyError(res.message || 'Failed to remove key.');
    }
  };

  const testKey = async (id, provider) => {
    const toast = notifyLoading('Testing key connection...');
    const res = await apiCall('/keys/test', 'POST', { key_id: id, provider });
    if (res.success) {
      toast.update({ type: 'success', title: 'Connected', message: res.data.message });
    } else {
      toast.update({ type: 'error', title: 'Connection failed', message: res.message });
    }
  };

  const testEmailDelivery = async () => {
    const toast = notifyLoading('Sending test email via WordPress mail...');
    const targetRecipient = (email.recipient_type || 'admin') === 'custom'
      ? (email.custom_email || '').trim()
      : (email.admin_email || AdminData.adminEmail || '');

    if ((email.recipient_type || 'admin') === 'custom' && (!targetRecipient || !targetRecipient.includes('@'))) {
      toast.update({
        type: 'error',
        title: 'Invalid Email',
        message: 'Please enter a valid custom email address before sending a test.'
      });
      return;
    }

    const res = await apiCall('/email/test', 'POST', {
      recipient_type: email.recipient_type || 'admin',
      custom_email: email.custom_email || '',
      to_email: targetRecipient
    });

    if (res.success) {
      toast.update({
        type: 'success',
        title: '✓ Test Email Sent',
        message: res.data?.message || `✓ Test email sent successfully to ${targetRecipient}.`
      });
    } else {
      toast.update({
        type: 'error',
        title: '⚠ Delivery Notice',
        message: res.message || '⚠ Unable to send the test email. Your WordPress hosting/mail configuration may need attention.'
      });
    }
  };

  return (
    <div>
      {/* Subtabs Header */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 20, borderBottom: '1px solid #e2e8f0', paddingBottom: 10 }}>
        {[
          { id: 'providers', label: 'AI Providers & Groq Key Pool' },
          { id: 'appearance', label: 'Theme & Live Preview' },
          { id: 'response_behavior', label: 'Response Behavior' },
          { id: 'email', label: 'Email Notifications' },
          { id: 'session', label: 'Sessions & Retention' },
          { id: 'sounds', label: 'Sounds' },
          { id: 'language', label: 'Language & RTL' },
          { id: 'knowledge', label: 'Knowledge & Sitemap' }
        ].map(t => (
          <button
            key={t.id}
            className={`ai-btn ai-btn-sm ${subTab === t.id ? 'ai-btn-primary' : 'ai-btn-secondary'}`}
            onClick={() => setSubTab(t.id)}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* SUBTAB 1: AI PROVIDERS & GROQ KEY POOL */}
      {subTab === 'providers' && (
        <div>
          <div className="ai-grid-2">
            <div className="ai-card">
              <div className="ai-card-header">
                <div>
                  <h3>Chat Provider Configuration</h3>
                  <p>Choose the model that generates responses for visitors.</p>
                </div>
              </div>

              <div className="ai-form-row">
                <label>Chat Provider</label>
                <select value={providers.chat_provider || 'groq'} onChange={e => {
                  const nextProv = e.target.value;
                  setProviders({ ...providers, chat_provider: nextProv });
                }}>
                  <option value="groq">Groq (Ultra-Fast Inference - Recommended)</option>
                  <option value="openai">OpenAI (Direct GPT-4o)</option>
                </select>
              </div>

              {(providers.chat_provider === 'openai' || providers.fallback_enabled) && (
                <div className="ai-form-row" style={{ background: '#f8fafc', padding: 14, borderRadius: 8, border: '1px solid #e2e8f0', marginBottom: 16 }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
                    <label style={{ margin: 0, fontWeight: 600 }}>
                      OpenAI API Key (Stored Encrypted)
                      {providers.openai_api_key && (
                        <span className="ai-badge ai-badge-success" style={{ marginLeft: 8, fontSize: 11 }}>
                          {providerTestResult.openai?.success ? '✓ Verified Live' : '✓ Configured'}
                        </span>
                      )}
                      {!providers.openai_api_key && providers.chat_provider === 'openai' && (
                        <span className="ai-badge ai-badge-warning" style={{ marginLeft: 8, fontSize: 11 }}>
                          Required for OpenAI
                        </span>
                      )}
                    </label>
                  </div>
                  <div style={{ display: 'flex', gap: 8 }}>
                    <input
                      type={showOpenAIKey ? 'text' : 'password'}
                      placeholder="sk-proj-... or sk-..."
                      value={providers.openai_api_key || ''}
                      onChange={e => setProviders({ ...providers, openai_api_key: e.target.value })}
                      style={{ flex: 1 }}
                    />
                    <button
                      type="button"
                      className="ai-btn ai-btn-secondary ai-btn-sm"
                      onClick={() => setShowOpenAIKey(!showOpenAIKey)}
                    >
                      {showOpenAIKey ? 'Hide' : 'Show'}
                    </button>
                    <button
                      type="button"
                      className="ai-btn ai-btn-secondary ai-btn-sm"
                      onClick={() => testDirectProviderKey('openai', providers.openai_api_key)}
                      disabled={testingProvider === 'openai'}
                    >
                      {testingProvider === 'openai' ? 'Testing…' : 'Test Key'}
                    </button>
                  </div>
                  <small style={{ color: '#64748b', display: 'block', marginTop: 5 }}>
                    Powers OpenAI chat models (GPT-4o, GPT-4o-mini). Stored encrypted with libsodium.
                  </small>
                </div>
              )}

              <div className="ai-form-row">
                <label>Chat Model</label>
                <select
                  value={providers.chat_model || ''}
                  onChange={e => setProviders({ ...providers, chat_model: e.target.value })}
                  disabled={isLoadingModels}
                >
                  {!providers.chat_model && <option value="">Select a model…</option>}
                  {modelCatalogue.provider === (providers.chat_provider || 'groq') &&
                    modelCatalogue.chat.map(m => (
                      <option key={m} value={m}>{m}</option>
                    ))}
                </select>
                {isLoadingModels && (
                  <small style={{ color: '#64748b' }}>Loading models available to your key…</small>
                )}
                {!isLoadingModels && modelCatalogue.chat.length > 0 && !modelCatalogue.live && (
                  <div className="ai-callout ai-callout-warning" style={{ marginTop: 6 }}>
                    {modelCatalogue.notice || 'Showing a generic model list. Some of these models may not work with your key.'}
                  </div>
                )}
                {!isLoadingModels && modelCatalogue.chat.length === 0 && (
                  <div className="ai-callout ai-callout-info" style={{ marginTop: 6 }}>
                    {providers.chat_provider === 'openai'
                      ? 'No active OpenAI API key configured. Enter and test your key above (starts with "sk-") to load models.'
                      : (modelCatalogue.notice || 'No active API key found for this provider. Add a key in the Key Pool below.')}
                  </div>
                )}
                {!isLoadingModels && modelCatalogue.chat.length > 0 && providers.chat_model && !modelCatalogue.chat.includes(providers.chat_model) && (
                  <div className="ai-callout ai-callout-warning" style={{ marginTop: 6 }}>
                    {`The saved model "${providers.chat_model}" is not available to your current key. Please pick one from the list.`}
                  </div>
                )}
              </div>

              <div className="ai-form-row">
                <label>Rotation Strategy (Key Pool)</label>
                <select value={providers.rotation_strategy || 'priority'} onChange={e => setProviders({ ...providers, rotation_strategy: e.target.value })}>
                  <option value="priority">Priority Order (Uses highest priority until limited)</option>
                  <option value="round_robin">Round-Robin (Spreads requests evenly across active keys)</option>
                </select>
              </div>

              <div className="ai-form-row">
                <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <input
                    type="checkbox"
                    checked={providers.fallback_enabled !== false}
                    onChange={e => setProviders({ ...providers, fallback_enabled: e.target.checked })}
                  />
                  Fallback to OpenAI if all Groq keys are exhausted
                </label>
              </div>

              <button className="ai-btn ai-btn-primary" onClick={saveAllSettings} disabled={isSaving}>{isSaving ? 'Saving…' : 'Save Chat Model'}</button>
            </div>

            {/* Embeddings Provider */}
            <div className="ai-card">
              <div className="ai-card-header">
                <div>
                  <h3>Embeddings Provider (Knowledge Search)</h3>
                  <p>Required for RAG semantic search across your content.</p>
                </div>
              </div>

              <div className="ai-callout ai-callout-info" style={{ marginBottom: 14 }}>
                Chat and embeddings use <strong>separate providers</strong>. Groq has no embedding
                model, so your Groq key powers chat while a Mistral or OpenAI key builds the
                knowledge index. Pick the provider whose key you have configured below.
              </div>

              <div className="ai-form-row">
                <label>Embeddings Provider</label>
                <select
                  value={providers.embeddings_provider || 'openai'}
                  onChange={e => {
                    const next = e.target.value;
                    setProviders({
                      ...providers,
                      embeddings_provider: next,
                      // Each provider has its own default embedding model.
                      embeddings_model: next === 'mistral' ? 'mistral-embed' : 'text-embedding-3-small'
                    });
                  }}
                >
                  <option value="mistral">Mistral AI (mistral-embed) — Recommended, free tier</option>
                  <option value="openai">OpenAI (text-embedding-3)</option>
                </select>
                <small style={{ color: '#64748b' }}>
                  Groq is not listed because it serves no embedding model. Chat and embeddings use separate providers.
                </small>
              </div>

              <div className="ai-form-row">
                <label>Embeddings Model</label>
                {(providers.embeddings_provider || 'openai') === 'mistral' ? (
                  <select value={providers.embeddings_model || 'mistral-embed'} onChange={e => setProviders({ ...providers, embeddings_model: e.target.value })}>
                    <option value="mistral-embed">mistral-embed (1024 dim, multilingual)</option>
                    <option value="codestral-embed">codestral-embed (1536 dim, for code)</option>
                  </select>
                ) : (
                  <select value={providers.embeddings_model || 'text-embedding-3-small'} onChange={e => setProviders({ ...providers, embeddings_model: e.target.value })}>
                    <option value="text-embedding-3-small">text-embedding-3-small (Standard - 1536 dim)</option>
                    <option value="text-embedding-3-large">text-embedding-3-large (Highest accuracy)</option>
                  </select>
                )}
              </div>

              {(providers.embeddings_provider || 'openai') === 'mistral' ? (
                <div className="ai-form-row" style={{ background: '#f8fafc', padding: 14, borderRadius: 8, border: '1px solid #e2e8f0', marginBottom: 16 }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
                    <label style={{ margin: 0, fontWeight: 600 }}>
                      Mistral API Key (Stored Encrypted)
                      {providers.mistral_api_key && (
                        <span className="ai-badge ai-badge-success" style={{ marginLeft: 8, fontSize: 11 }}>
                          {providerTestResult.mistral?.success ? '✓ Verified Live' : '✓ Configured'}
                        </span>
                      )}
                    </label>
                  </div>
                  <div style={{ display: 'flex', gap: 8 }}>
                    <input
                      type={showMistralKey ? 'text' : 'password'}
                      placeholder="Mistral API key"
                      value={providers.mistral_api_key || ''}
                      onChange={e => setProviders({ ...providers, mistral_api_key: e.target.value })}
                      style={{ flex: 1 }}
                    />
                    <button
                      type="button"
                      className="ai-btn ai-btn-secondary ai-btn-sm"
                      onClick={() => setShowMistralKey(!showMistralKey)}
                    >
                      {showMistralKey ? 'Hide' : 'Show'}
                    </button>
                    <button
                      type="button"
                      className="ai-btn ai-btn-secondary ai-btn-sm"
                      onClick={() => testDirectProviderKey('mistral', providers.mistral_api_key)}
                      disabled={testingProvider === 'mistral'}
                    >
                      {testingProvider === 'mistral' ? 'Testing…' : 'Test Key'}
                    </button>
                  </div>
                  <div className="ai-help-text" style={{ marginTop: 5 }}>Get a key at console.mistral.ai → API Keys for mistral-embed.</div>
                </div>
              ) : (
                <div className="ai-form-row" style={{ background: '#f8fafc', padding: 14, borderRadius: 8, border: '1px solid #e2e8f0', marginBottom: 16 }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
                    <label style={{ margin: 0, fontWeight: 600 }}>
                      OpenAI API Key (Stored Encrypted)
                      {providers.openai_api_key && (
                        <span className="ai-badge ai-badge-success" style={{ marginLeft: 8, fontSize: 11 }}>
                          {providerTestResult.openai?.success ? '✓ Verified Live' : '✓ Configured'}
                        </span>
                      )}
                      {!providers.openai_api_key && (
                        <span className="ai-badge ai-badge-warning" style={{ marginLeft: 8, fontSize: 11 }}>
                          Required for Embeddings
                        </span>
                      )}
                    </label>
                  </div>
                  <div style={{ display: 'flex', gap: 8 }}>
                    <input
                      type={showOpenAIKey ? 'text' : 'password'}
                      placeholder="sk-proj-... or sk-..."
                      value={providers.openai_api_key || ''}
                      onChange={e => setProviders({ ...providers, openai_api_key: e.target.value })}
                      style={{ flex: 1 }}
                    />
                    <button
                      type="button"
                      className="ai-btn ai-btn-secondary ai-btn-sm"
                      onClick={() => setShowOpenAIKey(!showOpenAIKey)}
                    >
                      {showOpenAIKey ? 'Hide' : 'Show'}
                    </button>
                    <button
                      type="button"
                      className="ai-btn ai-btn-secondary ai-btn-sm"
                      onClick={() => testDirectProviderKey('openai', providers.openai_api_key)}
                      disabled={testingProvider === 'openai'}
                    >
                      {testingProvider === 'openai' ? 'Testing…' : 'Test Key'}
                    </button>
                  </div>
                  <div className="ai-help-text" style={{ marginTop: 5 }}>Used by text-embedding-3 to index your content into searchable vectors. Stored securely encrypted.</div>
                </div>
              )}

              <div style={{ display: 'flex', gap: 10 }}>
                <button className="ai-btn ai-btn-primary" onClick={saveAllSettings} disabled={isSaving}>{isSaving ? 'Saving…' : 'Save Credentials'}</button>
              </div>
            </div>
          </div>

          {/* Groq Key Pool Table */}
          <div className="ai-card">
            <div className="ai-card-header">
              <div>
                <h3>Groq API Key Pool & Automatic Failover</h3>
                <p>Add multiple keys. On HTTP 429, requests seamlessly failover to the next active key without visitor error.</p>
              </div>
              <button className="ai-btn ai-btn-primary" onClick={() => setKeyModalOpen(true)}>+ Add Groq Key</button>
            </div>

            {/* Prominent Org Limits Callout as mandated by Section 7 */}
            <div className="ai-callout ai-callout-warning">
              <div>
                <strong>Important Rate Limit Notice:</strong> Groq rate limits apply per <em>organization</em>, not per key. Multiple keys created within the same Groq account share the same limit. For true failover capacity, add keys from separate Groq organizations or enable the OpenAI fallback provider.
              </div>
            </div>

            <table className="ai-table">
              <thead>
                <tr>
                  <th>Label</th>
                  <th>Key</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Requests</th>
                  <th>Limit Hits (429)</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {keys.map(k => (
                  <tr key={k.id}>
                    <td><strong>{k.label}</strong></td>
                    <td><code>••••{k.key_last_four}</code></td>
                    <td>{k.priority}</td>
                    <td>
                      <span className={`ai-badge ${
                        k.status === 'active' ? 'ai-badge-success' :
                        k.status === 'cooling_down' ? 'ai-badge-warning' :
                        k.status === 'invalid' ? 'ai-badge-danger' : 'ai-badge-neutral'
                      }`}>
                        {k.status === 'cooling_down' ? `Cooling down until ${new Date(k.cooldown_until).toLocaleTimeString()}` : k.status}
                      </span>
                    </td>
                    <td>{k.requests_count || 0}</td>
                    <td>{k.rate_limit_hits || 0}</td>
                    <td>
                      <div style={{ display: 'flex', gap: 6 }}>
                        <button className="ai-btn ai-btn-secondary ai-btn-sm" onClick={() => testKey(k.id, 'groq')}>Test</button>
                        <button className="ai-btn ai-btn-danger ai-btn-sm" onClick={() => deleteKey(k.id)}>Delete</button>
                      </div>
                    </td>
                  </tr>
                ))}
                {keys.length === 0 && (
                  <tr>
                    <td colSpan="7" style={{ textAlign: 'center', padding: 24, color: '#94a3b8' }}>
                      No Groq API keys added yet. Click "+ Add Groq Key" to add your first key.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {/* Add Key Modal */}
          {keyModalOpen && (
            <div className="ai-admin-modal-backdrop">
              <div className="ai-admin-modal">
                <div className="ai-modal-head">
                  <h3>Add Groq API Key to Pool</h3>
                  <button style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 18 }} onClick={() => setKeyModalOpen(false)}>✕</button>
                </div>
                <div className="ai-modal-body">
                  <div className="ai-form-row">
                    <label>Label</label>
                    <input type="text" placeholder="e.g. Primary Groq Account" value={newKeyLabel} onChange={e => setNewKeyLabel(e.target.value)} />
                  </div>
                  <div className="ai-form-row">
                    <label>Groq API Key (gsk_...)</label>
                    <input type="password" placeholder="gsk_..." value={newKeyValue} onChange={e => setNewKeyValue(e.target.value)} />
                  </div>
                  <div className="ai-form-row">
                    <label>Priority (Lower number = higher priority)</label>
                    <input type="number" value={newKeyPriority} onChange={e => setNewKeyPriority(e.target.value)} />
                  </div>
                </div>
                <div className="ai-modal-foot">
                  <button className="ai-btn ai-btn-secondary" onClick={() => setKeyModalOpen(false)}>Cancel</button>
                  <button className="ai-btn ai-btn-primary" onClick={handleAddKey}>Save Key</button>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* SUBTAB 2: THEME & LIVE PREVIEW */}
      {subTab === 'appearance' && (
        <div className="ai-preview-container">
          {/* Left: Theme & Customization Settings */}
          <div className="ai-settings-panel">
            <div className="ai-card">
              <div className="ai-card-header">
                <div>
                  <h3>Theme Presets & Typography</h3>
                  <p>Choose a curated palette or customize every color to match your brand.</p>
                </div>
              </div>

              <div className="ai-form-row">
                <label>Chatbot Avatar</label>
                <div className="ai-avatar-picker">
                  <div className="ai-avatar-preview" style={{ background: theme.primary_color || '#2563eb' }}>
                    {theme.avatar_url ? (
                      <img src={theme.avatar_url} alt="Current chatbot avatar" />
                    ) : (
                      <span>{(agent?.name || 'A').charAt(0).toUpperCase()}</span>
                    )}
                  </div>

                  <div className="ai-avatar-actions">
                    <button type="button" className="ai-btn ai-btn-secondary ai-btn-sm" onClick={openAvatarPicker}>
                      Choose from Media Library
                    </button>

                    {theme.avatar_url && (
                      <button type="button" className="ai-btn ai-btn-secondary ai-btn-sm" onClick={clearAvatar}>
                        Remove
                      </button>
                    )}

                    <span className="ai-help-text" style={{ marginTop: 0 }}>
                      Used in the chat header. Leave empty to show the agent&rsquo;s initial.
                    </span>
                  </div>
                </div>
              </div>

              {/* Presets Chips */}
              <div className="ai-form-row">
                <label>Theme Preset</label>
                <div className="ai-preset-grid">
                  {BUILTIN_THEME_PRESETS.map(p => (
                    <button
                      key={p.id}
                      type="button"
                      className={`ai-preset-chip ${(theme.preset || 'modern') === p.id ? 'active' : ''}`}
                      onClick={() => applyPreset(p.id)}
                    >
                      <span className="ai-preset-color-dot" style={{ background: p.color }}></span>
                      <span>{p.label}</span>
                    </button>
                  ))}
                  {customPresets.map(p => (
                    <div
                      key={p.id}
                      className={`ai-preset-chip ai-preset-chip-custom ${theme.preset === p.id ? 'active' : ''}`}
                    >
                      <button
                        type="button"
                        className="ai-preset-chip-apply"
                        onClick={() => applyCustomPreset(p)}
                      >
                        <span className="ai-preset-color-dot" style={{ background: p.primary_color }}></span>
                        <span>{p.name}</span>
                      </button>
                      <button
                        type="button"
                        className="ai-preset-delete"
                        title="Delete preset"
                        aria-label={`Delete preset ${p.name}`}
                        onClick={() => deleteCustomPreset(p.id)}
                      >×</button>
                    </div>
                  ))}
                  <button
                    type="button"
                    className="ai-preset-chip ai-preset-create"
                    onClick={() => { setNewPresetName(''); setPresetModalOpen(true); }}
                  >
                    <span className="ai-preset-plus" aria-hidden="true">+</span>
                    <span>Create new preset</span>
                  </button>
                </div>
              </div>

              {/* Create Preset Modal */}
              {presetModalOpen && (
                <div className="ai-admin-modal-backdrop" onClick={() => setPresetModalOpen(false)}>
                  <div className="ai-admin-modal" onClick={(e) => e.stopPropagation()}>
                    <div className="ai-modal-head">
                      <h3>Create New Theme Preset</h3>
                      <button style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 18 }} onClick={() => setPresetModalOpen(false)}>✕</button>
                    </div>
                    <div className="ai-modal-body">
                      <p className="ai-help-text" style={{ marginTop: 0 }}>
                        Saves your current color palette, corner radius, and font as a reusable preset.
                      </p>
                      <div className="ai-form-row">
                        <label>Preset Name</label>
                        <input
                          type="text"
                          autoFocus
                          placeholder="e.g. Brand Primary, Weekend Sale, Dark Pro"
                          value={newPresetName}
                          onChange={e => setNewPresetName(e.target.value)}
                          onKeyDown={e => { if (e.key === 'Enter') createCustomPreset(); }}
                        />
                      </div>
                    </div>
                    <div className="ai-modal-foot">
                      <button className="ai-btn ai-btn-secondary" onClick={() => setPresetModalOpen(false)}>Cancel</button>
                      <button className="ai-btn ai-btn-primary" onClick={createCustomPreset} disabled={!newPresetName.trim()}>Create Preset</button>
                    </div>
                  </div>
                </div>
              )}

              <div className="ai-grid-2">
                <div className="ai-form-row">
                  <label>Font Family</label>
                  <select
                    value={theme.font_family || 'system-ui, -apple-system, sans-serif'}
                    onChange={e => updateTheme({ font_family: e.target.value })}
                  >
                    <option value='system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'>System Default (Fastest)</option>
                    <option value='"Inter", -apple-system, BlinkMacSystemFont, sans-serif'>Inter (Clean Modern SaaS)</option>
                    <option value='"Roboto", sans-serif'>Roboto (Google Material)</option>
                    <option value='"Outfit", sans-serif'>Outfit (Friendly Modern)</option>
                    <option value='"Poppins", sans-serif'>Poppins (Geometric Bold)</option>
                  </select>
                </div>

                <div className="ai-form-row">
                  <label>Corner Radius: {theme.border_radius ?? 16}px</label>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <input
                      type="range"
                      min="4"
                      max="28"
                      value={theme.border_radius ?? 16}
                      onChange={e => updateTheme({ border_radius: parseInt(e.target.value, 10) })}
                      style={{ flex: 1 }}
                    />
                    <span style={{ fontSize: 13, fontWeight: 600, width: 36, color: '#475569' }}>
                      {theme.border_radius ?? 16}px
                    </span>
                  </div>
                </div>
              </div>
            </div>

            {/* Colors Palette with Swatches */}
            <div className="ai-card">
              <div className="ai-card-header">
                <div>
                  <h3>Color Palette</h3>
                  <p>Click any color box to use the visual color picker or enter hex codes.</p>
                </div>
              </div>

              <div className="ai-grid-2">
                <div className="ai-form-row">
                  <label>Primary Brand Color</label>
                  <div className="ai-color-input-wrapper">
                    <input
                      type="color"
                      value={theme.primary_color || '#2563eb'}
                      onChange={e => updateTheme({ primary_color: e.target.value })}
                      className="ai-color-picker-box"
                    />
                    <input
                      type="text"
                      value={theme.primary_color || '#2563eb'}
                      onChange={e => updateTheme({ primary_color: e.target.value })}
                      className="ai-color-hex-text"
                    />
                  </div>
                </div>

                <div className="ai-form-row">
                  <label>Background Color</label>
                  <div className="ai-color-input-wrapper">
                    <input
                      type="color"
                      value={theme.bg_color || '#ffffff'}
                      onChange={e => updateTheme({ bg_color: e.target.value })}
                      className="ai-color-picker-box"
                    />
                    <input
                      type="text"
                      value={theme.bg_color || '#ffffff'}
                      onChange={e => updateTheme({ bg_color: e.target.value })}
                      className="ai-color-hex-text"
                    />
                  </div>
                </div>

                <div className="ai-form-row">
                  <label>Text / Title Color</label>
                  <div className="ai-color-input-wrapper">
                    <input
                      type="color"
                      value={theme.text_color || '#0f172a'}
                      onChange={e => updateTheme({ text_color: e.target.value })}
                      className="ai-color-picker-box"
                    />
                    <input
                      type="text"
                      value={theme.text_color || '#0f172a'}
                      onChange={e => updateTheme({ text_color: e.target.value })}
                      className="ai-color-hex-text"
                    />
                  </div>
                </div>

                <div className="ai-form-row">
                  <label>User Message Bubble</label>
                  <div className="ai-color-input-wrapper">
                    <input
                      type="color"
                      value={theme.user_bubble_bg || '#2563eb'}
                      onChange={e => updateTheme({ user_bubble_bg: e.target.value })}
                      className="ai-color-picker-box"
                    />
                    <input
                      type="text"
                      value={theme.user_bubble_bg || '#2563eb'}
                      onChange={e => updateTheme({ user_bubble_bg: e.target.value })}
                      className="ai-color-hex-text"
                    />
                  </div>
                </div>

                <div className="ai-form-row">
                  <label>User Bubble Text Color</label>
                  <div className="ai-color-input-wrapper">
                    <input
                      type="color"
                      value={theme.user_bubble_text || '#ffffff'}
                      onChange={e => updateTheme({ user_bubble_text: e.target.value })}
                      className="ai-color-picker-box"
                    />
                    <input
                      type="text"
                      value={theme.user_bubble_text || '#ffffff'}
                      onChange={e => updateTheme({ user_bubble_text: e.target.value })}
                      className="ai-color-hex-text"
                    />
                  </div>
                </div>

                <div className="ai-form-row">
                  <label>Bot Message Bubble</label>
                  <div className="ai-color-input-wrapper">
                    <input
                      type="color"
                      value={theme.bot_bubble_bg || '#f1f5f9'}
                      onChange={e => updateTheme({ bot_bubble_bg: e.target.value })}
                      className="ai-color-picker-box"
                    />
                    <input
                      type="text"
                      value={theme.bot_bubble_bg || '#f1f5f9'}
                      onChange={e => updateTheme({ bot_bubble_bg: e.target.value })}
                      className="ai-color-hex-text"
                    />
                  </div>
                </div>

                <div className="ai-form-row">
                  <label>Bot Bubble Text Color</label>
                  <div className="ai-color-input-wrapper">
                    <input
                      type="color"
                      value={theme.bot_bubble_text || '#0f172a'}
                      onChange={e => updateTheme({ bot_bubble_text: e.target.value })}
                      className="ai-color-picker-box"
                    />
                    <input
                      type="text"
                      value={theme.bot_bubble_text || '#0f172a'}
                      onChange={e => updateTheme({ bot_bubble_text: e.target.value })}
                      className="ai-color-hex-text"
                    />
                  </div>
                </div>
              </div>
            </div>

            {/* Placement & Launcher Button */}
            <div className="ai-card">
              <div className="ai-card-header">
                <div>
                  <h3>Launcher Button & Placement</h3>
                  <p>Configure screen position and launcher appearance for desktop & mobile.</p>
                </div>
              </div>

              <div className="ai-grid-2">
                <div className="ai-form-row">
                  <label>Screen Position</label>
                  <select
                    value={placement.position || 'bottom_right'}
                    onChange={e => setPlacement({ ...placement, position: e.target.value })}
                  >
                    <option value="bottom_right">Bottom Right (Recommended Standard)</option>
                    <option value="bottom_left">Bottom Left</option>
                    <option value="top_right">Top Right</option>
                    <option value="top_left">Top Left</option>
                  </select>
                </div>

                <div className="ai-form-row">
                  <label>Launcher Button Shape</label>
                  <select
                    value={placement.launcher_shape || 'circle'}
                    onChange={e => setPlacement({ ...placement, launcher_shape: e.target.value })}
                  >
                    <option value="circle">Circle (Compact 60px Round)</option>
                    <option value="pill">Pill (Icon with Text Label)</option>
                    <option value="rounded">Rounded Square (Modern)</option>
                  </select>
                </div>

                <div className="ai-form-row" style={{ gridColumn: 'span 2' }}>
                  <div className="ai-card" style={{ marginBottom: 0, background: '#fafbfd' }}>
                    <div className="ai-card-header" style={{ marginBottom: 14 }}>
                      <div>
                        <h3>Notification Badge</h3>
                        <p>A small dot or number on the launcher icon, to draw attention to the chat.</p>
                      </div>
                    </div>

                    <div className="ai-form-row">
                      <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <input
                          type="checkbox"
                          checked={badge.enabled !== false}
                          onChange={e => setBadge({ ...badge, enabled: e.target.checked })}
                        />
                        Show a notification badge
                      </label>
                    </div>

                    <div className="ai-grid-2">
                      <div className="ai-form-row">
                        <label>Badge Style</label>
                        <select
                          value={badge.mode || 'dot'}
                          disabled={badge.enabled === false}
                          onChange={e => setBadge({ ...badge, mode: e.target.value })}
                        >
                          <option value="dot">Dot only (least intrusive)</option>
                          <option value="count">Unread count</option>
                          <option value="off">Disabled</option>
                        </select>
                      </div>

                      <div className="ai-form-row">
                        <label>Appear After (ms)</label>
                        <input
                          type="number"
                          min="0"
                          max="30000"
                          step="100"
                          style={{ width: 120 }}
                          value={badge.load_delay_ms ?? 1200}
                          disabled={badge.enabled === false || !badge.show_on_load}
                          onChange={e => setBadge({ ...badge, load_delay_ms: e.target.value })}
                        />
                        <small style={{ color: '#64748b' }}>
                          Delay after page load, so the badge does not compete with the hero.
                        </small>
                      </div>
                    </div>

                    <div className="ai-form-row">
                      <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <input
                          type="checkbox"
                          checked={badge.show_on_load !== false}
                          disabled={badge.enabled === false}
                          onChange={e => setBadge({ ...badge, show_on_load: e.target.checked })}
                        />
                        Show it automatically when a visitor first lands on the page
                      </label>
                    </div>

                    <div className="ai-grid-2">
                      <div className="ai-form-row">
                        <label>Max Impressions Per Day</label>
                        <input
                          type="number"
                          min="1"
                          max="20"
                          style={{ width: 120 }}
                          value={badge.max_per_day ?? 1}
                          disabled={badge.enabled === false}
                          onChange={e => setBadge({ ...badge, max_per_day: e.target.value })}
                        />
                        <small style={{ color: '#64748b' }}>
                          Stops the badge from nagging a returning visitor.
                        </small>
                      </div>

                      <div className="ai-form-row">
                        <label>Nudge After (ms, 0 = off)</label>
                        <input
                          type="number"
                          min="0"
                          max="600000"
                          step="1000"
                          style={{ width: 140 }}
                          value={badge.nudge_after_ms ?? 0}
                          disabled={badge.enabled === false}
                          onChange={e => setBadge({ ...badge, nudge_after_ms: e.target.value })}
                        />
                        <small style={{ color: '#64748b' }}>
                          Brings the badge back later if the panel was never opened.
                        </small>
                      </div>
                    </div>
                  </div>
                </div>

                {placement.launcher_shape === 'pill' && (
                  <div className="ai-form-row" style={{ gridColumn: 'span 2' }}>
                    <label>Pill Button Label</label>
                    <input
                      type="text"
                      value={placement.launcher_label || 'Chat with us'}
                      onChange={e => setPlacement({ ...placement, launcher_label: e.target.value })}
                      placeholder="e.g. Chat with us, Ask AI, Need Help?"
                    />
                  </div>
                )}
              </div>

              <div style={{ marginTop: 12 }}>
                <button className="ai-btn ai-btn-primary" onClick={saveAllSettings} disabled={isSaving}>{isSaving ? 'Saving…' : 'Save Theme & Settings'}</button>
              </div>
            </div>
          </div>

          {/* Right: Dual-Mode Live Preview Panel (Phone & Desktop Responsive) */}
          <div className="ai-live-preview-wrapper">
            <div className="ai-live-preview-card">
              {/* Preview Controls Bar */}
              <div className="ai-preview-controls-bar">
                <div className="ai-device-toggle-group">
                  <button
                    type="button"
                    className={`ai-device-btn ${previewDevice === 'desktop' ? 'active' : ''}`}
                    onClick={() => setPreviewDevice('desktop')}
                    title="Desktop Preview"
                  >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                      <line x1="8" y1="21" x2="16" y2="21"></line>
                      <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                    <span>Desktop</span>
                  </button>
                  <button
                    type="button"
                    className={`ai-device-btn ${previewDevice === 'mobile' ? 'active' : ''}`}
                    onClick={() => setPreviewDevice('mobile')}
                    title="Phone / Mobile Preview"
                  >
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <rect x="5" y="2" width="14" height="20" rx="3" ry="3"></rect>
                      <line x1="12" y1="18" x2="12.01" y2="18"></line>
                    </svg>
                    <span>Phone</span>
                  </button>
                </div>

                <div className="ai-preview-action-group">
                  <button
                    type="button"
                    className="ai-btn ai-btn-secondary ai-btn-sm"
                    onClick={() => setPreviewOpen(!previewOpen)}
                  >
                    {previewOpen ? 'View Closed' : 'View Open'}
                  </button>
                  <button
                    type="button"
                    className="ai-btn ai-btn-secondary ai-btn-sm"
                    onClick={resetPreviewChat}
                    title="Reset Interactive Chat"
                  >
                    ↺ Reset
                  </button>
                </div>
              </div>

              {/* MODE 1: PHONE PREVIEW (Realistic Smartphone Frame) */}
              {previewDevice === 'mobile' && (
                <div className="ai-phone-preview-stage">
                  <div className="ai-phone-mockup">
                    {/* Phone Dynamic Island / Camera & Speaker */}
                    <div className="ai-phone-notch">
                      <div className="ai-notch-speaker"></div>
                      <div className="ai-notch-lens"></div>
                    </div>

                    {/* Phone Status Bar */}
                    <div className="ai-phone-statusbar">
                      <span className="ai-phone-time">9:41</span>
                      <div className="ai-phone-icons">
                        <span style={{ fontSize: 10 }}>●●●●</span>
                        <svg width="12" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3c-4.97 0-9 4.03-9 9 0 2.12.74 4.07 1.97 5.61L12 22l7.03-4.39C20.26 16.07 21 14.12 21 12c0-4.97-4.03-9-9-9z"/></svg>
                        <span style={{ fontSize: 11 }}>100% 🔋</span>
                      </div>
                    </div>

                    {/* Phone Screen Display */}
                    <div className="ai-phone-screen">
                      {previewOpen ? (
                        <div
                          className="ai-phone-chat-screen"
                          style={{
                            background: theme.bg_color || '#ffffff',
                            fontFamily: theme.font_family || 'sans-serif'
                          }}
                        >
                          {/* Chat Header on Phone */}
                          <div
                            className="ai-phone-chat-header"
                            style={{
                              background: theme.primary_color || '#2563eb',
                              color: '#ffffff'
                            }}
                          >
                            <div className="ai-phone-chat-header-info">
<div className="ai-phone-avatar">
                            {theme.avatar_url
                              ? <img src={theme.avatar_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
                              : (agent.name?.charAt(0) || 'A')}
                          </div>
                              <div>
                                <div className="ai-phone-agent-name">{agent.name || 'AI Assistant'}</div>
                                <div className="ai-phone-agent-status">
                                  <span className="ai-status-indicator-dot"></span>
                                  <span>{agent.custom_status_text || 'Online'}</span>
                                </div>
                              </div>
                            </div>
                            <button
                              type="button"
                              className="ai-phone-header-close-btn"
                              onClick={() => setPreviewOpen(false)}
                              title="Close Chat"
                            >
                              ✕
                            </button>
                          </div>

                          {/* Message List on Phone */}
                          <div className="ai-phone-chat-messages">
                            {previewMessages.map((m, idx) => (
                              <div
                                key={idx}
                                className={`ai-preview-msg ${m.role === 'user' ? 'ai-preview-user' : 'ai-preview-bot'}`}
                              >
                                <div
                                  className="ai-preview-bubble"
                                  style={{
                                    background: m.role === 'user' ? (theme.user_bubble_bg || '#2563eb') : (theme.bot_bubble_bg || '#f1f5f9'),
                                    color: m.role === 'user' ? (theme.user_bubble_text || '#ffffff') : (theme.bot_bubble_text || '#0f172a'),
                                    borderRadius: `${theme.border_radius || 16}px`,
                                    borderBottomRightRadius: m.role === 'user' ? 4 : `${theme.border_radius || 16}px`,
                                    borderBottomLeftRadius: m.role === 'assistant' ? 4 : `${theme.border_radius || 16}px`
                                  }}
                                >
                                  {renderMarkdownNodes(m.text)}
                                </div>
                              </div>
                            ))}

                            {previewTyping && (
                              <div className="ai-preview-msg ai-preview-bot">
                                <div
                                  className="ai-preview-typing"
                                  style={{
                                    background: theme.bot_bubble_bg || '#f1f5f9',
                                    borderRadius: `${theme.border_radius || 16}px`
                                  }}
                                >
                                  <span></span><span></span><span></span>
                                </div>
                              </div>
                            )}
                          </div>

                          {/* Suggested Question Chips on Phone */}
                          {agent.suggested_questions && agent.suggested_questions.length > 0 && (
                            <div className="ai-phone-suggested-row">
                              {agent.suggested_questions.slice(0, 2).map((q, i) => (
                                <button
                                  key={i}
                                  type="button"
                                  className="ai-phone-suggested-chip"
                                  style={{
                                    borderColor: `${theme.primary_color || '#2563eb'}40`,
                                    color: theme.primary_color || '#2563eb'
                                  }}
                                  onClick={() => handlePreviewQuestionClick(q.text)}
                                >
                                  {q.icon || '💬'} {q.text}
                                </button>
                              ))}
                            </div>
                          )}

                          {/* Phone Chat Input Bar */}
                          <form className="ai-phone-input-bar" onSubmit={handlePreviewSend}>
                            <input
                              type="text"
                              value={previewInput}
                              onChange={e => setPreviewInput(e.target.value)}
                              placeholder="Type a test message..."
                            />
                            <button
                              type="submit"
                              style={{ background: theme.primary_color || '#2563eb' }}
                            >
                              ➤
                            </button>
                          </form>

                          {/* iPhone Bottom Home Indicator Bar */}
                          <div className="ai-phone-home-bar"></div>
                        </div>
                      ) : (
                        /* Closed Launcher Button View on Phone Screen */
                        <div className="ai-phone-web-screen">
                          <div className="ai-phone-mock-site">
                            <div className="ai-phone-mock-header">
                              <span style={{ fontWeight: 700, fontSize: 13 }}>{AdminData.siteName}</span>
                              <span>☰</span>
                            </div>
                            <div className="ai-phone-mock-hero">
                              <h6>Welcome to our Site</h6>
                              <p>Modern mobile responsive experience.</p>
                            </div>
                            <div className="ai-mock-content-bars">
                              <div></div>
                              <div></div>
                              <div style={{ width: '60%' }}></div>
                            </div>
                          </div>

                          {/* Floating Launcher Button at Bottom on Phone */}
                          <div className={`ai-phone-launcher-dock ${placement.position || 'bottom_right'}`}>
                            <button
                              type="button"
                              onClick={() => setPreviewOpen(true)}
                              className={`ai-preview-launcher-btn ${placement.launcher_shape || 'circle'}`}
                              style={{ background: theme.primary_color || '#2563eb', color: '#fff' }}
                            >
                              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                              </svg>
                              {placement.launcher_shape === 'pill' && (
                                <span style={{ fontSize: 12, fontWeight: 600 }}>{placement.launcher_label || 'Chat with us'}</span>
                              )}
                            </button>
                          </div>

                          <div className="ai-phone-home-bar"></div>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              )}

              {/* MODE 2: DESKTOP PREVIEW (Browser Chrome & Simulated Web Canvas) */}
              {previewDevice === 'desktop' && (
                <div className="ai-desktop-preview-stage">
                  <div className="ai-desktop-mockup">
                    {/* Browser Chrome Header */}
                    <div className="ai-desktop-browser-bar">
                      <div className="ai-browser-traffic-lights">
                        <span style={{ background: '#ef4444' }}></span>
                        <span style={{ background: '#f59e0b' }}></span>
                        <span style={{ background: '#10b981' }}></span>
                      </div>
                      <div className="ai-browser-url-pill">
                        🔒 {AdminData.homeUrl.replace(/^https?:\/\//, '')}
                      </div>
                    </div>

                    {/* Simulated Webpage Canvas */}
                    <div className="ai-desktop-canvas">
                      <div className="ai-desktop-site-wireframe">
                        <div className="ai-wire-nav">
                          <div className="ai-wire-logo"></div>
                          <div className="ai-wire-links">
                            <span></span><span></span><span></span>
                          </div>
                        </div>
                        <div className="ai-wire-hero">
                          <div className="ai-wire-title"></div>
                          <div className="ai-wire-sub"></div>
                          <div className="ai-wire-btn"></div>
                        </div>
                      </div>

                      {/* Anchored Chatbot Area inside desktop canvas */}
                      <div className={`ai-desktop-chatbot-area ${placement.position || 'bottom_right'}`}>
                        {/* Open Widget Window */}
                        {previewOpen && (
                          <div
                            className="ai-desktop-preview-window"
                            style={{
                              background: theme.bg_color || '#ffffff',
                              color: theme.text_color || '#0f172a',
                              borderRadius: `${theme.border_radius || 16}px`,
                              fontFamily: theme.font_family || 'sans-serif'
                            }}
                          >
                            {/* Window Header */}
                            <div
                              className="ai-desktop-preview-header"
                              style={{
                                background: theme.primary_color || '#2563eb',
                                color: '#ffffff',
                                borderTopLeftRadius: `${theme.border_radius || 16}px`,
                                borderTopRightRadius: `${theme.border_radius || 16}px`
                              }}
                            >
                              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
<div className="ai-desktop-avatar">
                            {theme.avatar_url
                              ? <img src={theme.avatar_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
                              : (agent.name?.charAt(0) || 'A')}
                          </div>
                                <div>
                                  <div style={{ fontWeight: 600, fontSize: 13.5 }}>{agent.name || 'AI Assistant'}</div>
                                  <div style={{ fontSize: 11, opacity: 0.88, display: 'flex', alignItems: 'center', gap: 5 }}>
                                    <span className="ai-status-indicator-dot"></span>
                                    <span>{agent.custom_status_text || 'Online'}</span>
                                  </div>
                                </div>
                              </div>
                              <button
                                type="button"
                                onClick={() => setPreviewOpen(false)}
                                className="ai-desktop-close-btn"
                                title="Close"
                              >
                                ✕
                              </button>
                            </div>

                            {/* Message Feed */}
                            <div className="ai-desktop-preview-messages">
                              {previewMessages.map((m, idx) => (
                                <div
                                  key={idx}
                                  className={`ai-preview-msg ${m.role === 'user' ? 'ai-preview-user' : 'ai-preview-bot'}`}
                                >
                                  <div
                                    className="ai-preview-bubble"
                                    style={{
                                      background: m.role === 'user' ? (theme.user_bubble_bg || '#2563eb') : (theme.bot_bubble_bg || '#f1f5f9'),
                                      color: m.role === 'user' ? (theme.user_bubble_text || '#ffffff') : (theme.bot_bubble_text || '#0f172a'),
                                      borderRadius: `${theme.border_radius || 16}px`,
                                      borderBottomRightRadius: m.role === 'user' ? 4 : `${theme.border_radius || 16}px`,
                                      borderBottomLeftRadius: m.role === 'assistant' ? 4 : `${theme.border_radius || 16}px`
                                    }}
                                  >
                                    {renderMarkdownNodes(m.text)}
                                  </div>
                                </div>
                              ))}

                              {previewTyping && (
                                <div className="ai-preview-msg ai-preview-bot">
                                  <div
                                    className="ai-preview-typing"
                                    style={{
                                      background: theme.bot_bubble_bg || '#f1f5f9',
                                      borderRadius: `${theme.border_radius || 16}px`
                                    }}
                                  >
                                    <span></span><span></span><span></span>
                                  </div>
                                </div>
                              )}
                            </div>

                            {/* Suggested Questions */}
                            {agent.suggested_questions && agent.suggested_questions.length > 0 && (
                              <div className="ai-desktop-suggested-row">
                                {agent.suggested_questions.slice(0, 3).map((q, i) => (
                                  <button
                                    key={i}
                                    type="button"
                                    className="ai-desktop-suggested-chip"
                                    style={{
                                      borderColor: `${theme.primary_color || '#2563eb'}40`,
                                      color: theme.primary_color || '#2563eb'
                                    }}
                                    onClick={() => handlePreviewQuestionClick(q.text)}
                                  >
                                    {q.icon || '💬'} {q.text}
                                  </button>
                                ))}
                              </div>
                            )}

                            {/* Input Form */}
                            <form className="ai-desktop-preview-input-form" onSubmit={handlePreviewSend}>
                              <input
                                type="text"
                                value={previewInput}
                                onChange={e => setPreviewInput(e.target.value)}
                                placeholder="Type a test message..."
                              />
                              <button
                                type="submit"
                                style={{ background: theme.primary_color || '#2563eb' }}
                              >
                                ➤
                              </button>
                            </form>
                          </div>
                        )}

                        {/* Floating Launcher Button on Desktop Canvas */}
                        <button
                          type="button"
                          onClick={() => setPreviewOpen(!previewOpen)}
                          className={`ai-preview-launcher-btn ${placement.launcher_shape || 'circle'}`}
                          style={{ background: theme.primary_color || '#2563eb', color: '#ffffff' }}
                        >
                          {previewOpen ? (
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2">
                              <line x1="18" y1="6" x2="6" y2="18"></line>
                              <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                          ) : (
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                          )}
                          {placement.launcher_shape === 'pill' && !previewOpen && (
                            <span style={{ fontSize: 13, fontWeight: 600 }}>{placement.launcher_label || 'Chat with us'}</span>
                          )}
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* SUBTAB 3: EMAIL NOTIFICATIONS */}
      {subTab === 'email' && (
        <div>
          <div className="ai-card">
            <div className="ai-card-header">
              <div>
                <h3>Email Notifications</h3>
                <p>Automatically receive important chatbot notifications by email using WordPress native mail.</p>
              </div>
              <button
                type="button"
                className="ai-btn ai-btn-secondary"
                onClick={testEmailDelivery}
                title="Send a sample email to verify your email delivery"
              >
                Send Test Email
              </button>
            </div>

            <div style={{ padding: '4px 0 20px 0' }}>
              {/* Human Handoff / Support Email Selection */}
              <div className="ai-form-row" style={{ marginBottom: 24 }}>
                <label style={{ fontSize: 14, fontWeight: 600, color: '#1e293b', marginBottom: 4, display: 'block' }}>
                  Human Handoff / Support Email
                </label>
                <p style={{ margin: '0 0 14px 0', fontSize: 12.5, color: '#64748b', lineHeight: 1.45 }}>
                  Configure the recipient email address for human support handoff and lead notifications. When the AI determines human assistance is needed, visitors can click <strong>✉ Email Our Team</strong> to open their email client pre-addressed to this inbox.
                </p>

                <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                  {/* Option 1: WordPress Administration Email (Recommended) */}
                  <label
                    style={{
                      display: 'flex',
                      alignItems: 'flex-start',
                      gap: 12,
                      padding: '14px 16px',
                      borderRadius: 8,
                      border: (email.recipient_type || 'admin') === 'admin' ? '2px solid #2563eb' : '1px solid #e2e8f0',
                      backgroundColor: (email.recipient_type || 'admin') === 'admin' ? '#eff6ff' : '#ffffff',
                      cursor: 'pointer',
                      transition: 'all 0.15s ease'
                    }}
                  >
                    <input
                      type="radio"
                      name="email_recipient_type"
                      value="admin"
                      checked={(email.recipient_type || 'admin') === 'admin'}
                      onChange={() => setEmail({
                        ...email,
                        recipient_type: 'admin',
                        support_email: email.admin_email || AdminData.adminEmail,
                        notification_to: email.admin_email || AdminData.adminEmail
                      })}
                      style={{ marginTop: 3 }}
                    />
                    <div style={{ flex: 1 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                        <strong style={{ color: '#1e293b', fontSize: 14 }}>WordPress Administration Email</strong>
                        <span style={{ fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 999, backgroundColor: '#dbeafe', color: '#1d4ed8' }}>
                          Recommended
                        </span>
                      </div>
                      <div style={{ marginTop: 6, display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                        <code style={{ fontSize: 13, padding: '4px 8px', background: '#ffffff', border: '1px solid #cbd5e1', borderRadius: 4, color: '#0f172a', fontWeight: 600 }}>
                          {email.admin_email || AdminData.adminEmail || 'admin@example.com'}
                        </code>
                        <span style={{ fontSize: 12, color: '#16a34a', fontWeight: 500 }}>
                          ✓ Using WordPress Administration Email
                        </span>
                      </div>
                      <p style={{ margin: '6px 0 0 0', fontSize: 12, color: '#64748b', lineHeight: 1.4 }}>
                        Support handoffs are routed to your WordPress Administration Email Address. You can change this address anytime in <strong>WordPress → Settings → General</strong>.
                      </p>
                    </div>
                  </label>

                  {/* Option 2: Custom Support Email Address */}
                  <label
                    style={{
                      display: 'flex',
                      alignItems: 'flex-start',
                      gap: 12,
                      padding: '14px 16px',
                      borderRadius: 8,
                      border: email.recipient_type === 'custom' ? '2px solid #2563eb' : '1px solid #e2e8f0',
                      backgroundColor: email.recipient_type === 'custom' ? '#eff6ff' : '#ffffff',
                      cursor: 'pointer',
                      transition: 'all 0.15s ease'
                    }}
                  >
                    <input
                      type="radio"
                      name="email_recipient_type"
                      value="custom"
                      checked={email.recipient_type === 'custom'}
                      onChange={() => setEmail({
                        ...email,
                        recipient_type: 'custom',
                        support_email: email.custom_email || email.support_email || '',
                        notification_to: email.custom_email || email.support_email || ''
                      })}
                      style={{ marginTop: 3 }}
                    />
                    <div style={{ flex: 1 }}>
                      <strong style={{ color: '#1e293b', fontSize: 14 }}>Custom Support Email Address</strong>
                      <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                        Specify a dedicated support inbox for chatbot human handoff requests and inquiries.
                      </p>

                      {email.recipient_type === 'custom' && (
                        <div style={{ marginTop: 10 }}>
                          <input
                            type="email"
                            placeholder="e.g. support@example.com"
                            value={email.custom_email || email.support_email || ''}
                            onChange={e => {
                              const val = e.target.value;
                              setEmail({
                                ...email,
                                custom_email: val,
                                support_email: val,
                                notification_to: val
                              });
                            }}
                            style={{
                              width: '100%',
                              maxWidth: 420,
                              padding: '8px 12px',
                              borderRadius: 6,
                              border: '1px solid #cbd5e1',
                              fontSize: 13
                            }}
                          />
                          <div className="ai-help-text" style={{ marginTop: 4 }}>
                            Enter a valid support email address (validated natively via WordPress).
                          </div>
                        </div>
                      )}
                    </div>
                  </label>
                </div>

                {/* Validation Status & Fallback Guidance */}
                {(() => {
                  const resolvedEmail = (email.recipient_type === 'custom'
                    ? (email.custom_email || email.support_email || '')
                    : (email.admin_email || AdminData.adminEmail || email.support_email || '')
                  ).trim();
                  const isValid = resolvedEmail.length > 3 && resolvedEmail.includes('@') && resolvedEmail.includes('.');

                  if (!isValid) {
                    return (
                      <div style={{ marginTop: 14, padding: '12px 16px', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 8, display: 'flex', alignItems: 'flex-start', gap: 10 }}>
                        <span style={{ fontSize: 16, lineHeight: 1 }}>⚠️</span>
                        <div style={{ fontSize: 12.5, color: '#92400e', lineHeight: 1.5 }}>
                          <strong>No Support Email Configured:</strong> A valid email address is required for the Human Handoff direct email action. Until configured, the chatbot will remain fully functional and continue answering visitor questions safely without generating broken mailto links.
                        </div>
                      </div>
                    );
                  }

                  return (
                    <div style={{ marginTop: 14, padding: '10px 14px', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8, display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: '#166534' }}>
                      <span style={{ fontWeight: 'bold' }}>✓</span>
                      <span>Active Human Handoff recipient: <strong>{resolvedEmail}</strong></span>
                    </div>
                  );
                })()}
              </div>

              {/* Notification Triggers / Preferences */}
              <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 18, marginTop: 18 }}>
                <label style={{ fontSize: 14, fontWeight: 600, color: '#1e293b', marginBottom: 12, display: 'block' }}>
                  Notification Triggers
                </label>

                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={email.enabled !== false}
                      onChange={e => setEmail({ ...email, enabled: e.target.checked })}
                    />
                    <span style={{ fontSize: 13, color: '#1e293b' }}>
                      <strong>Enable Email Notifications</strong> (Master switch for all chatbot email alerts)
                    </span>
                  </label>

                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={email.notify_on_lead !== false}
                      onChange={e => setEmail({ ...email, notify_on_lead: e.target.checked })}
                    />
                    <span style={{ fontSize: 13, color: '#1e293b' }}>
                      Notify on new leads & contact form submissions
                    </span>
                  </label>

                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={email.notify_on_fail !== false}
                      onChange={e => setEmail({ ...email, notify_on_fail: e.target.checked })}
                    />
                    <span style={{ fontSize: 13, color: '#1e293b' }}>
                      Notify on important chatbot alerts & failed interactions
                    </span>
                  </label>
                </div>
              </div>

              {/* Delivery Engine Details */}
              <div style={{ marginTop: 20, padding: '12px 16px', background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, color: '#475569' }}>
                  <span style={{ color: '#2563eb', fontWeight: 600 }}>ℹ Native Delivery:</span>
                  <span>
                    Emails are delivered natively through WordPress <code>wp_mail()</code> without requiring any external API keys or third-party service verification.
                  </span>
                </div>
              </div>
            </div>

            <button className="ai-btn ai-btn-primary" onClick={saveAllSettings} disabled={isSaving}>
              {isSaving ? 'Saving…' : 'Save Email Settings'}
            </button>
          </div>
        </div>
      )}

      {/* SUBTAB 4: SESSIONS & PRIVACY */}
      {subTab === 'session' && (
        <div className="ai-card">
          <div className="ai-card-header">
            <div>
              <h3>Chat Session & Privacy Retention</h3>
              <p>Control conversation lifecycle and compliance retention periods.</p>
            </div>
          </div>

          <div className="ai-grid-2">
            <div className="ai-form-row">
              <label>Conversation Retention Policy</label>
              <select value={session.retention_days || 30} onChange={e => setSession({ ...session, retention_days: parseInt(e.target.value, 10) })}>
                <option value={7}>Delete conversations after 7 days</option>
                <option value={30}>Delete conversations after 30 days (Default)</option>
                <option value={90}>Delete conversations after 90 days</option>
                <option value={0}>Never delete automatically</option>
              </select>
            </div>

            <div className="ai-form-row">
              <label>Session Timeout (Minutes)</label>
              <input
                type="number"
                value={session.session_duration_minutes || 30}
                onChange={e => setSession({ ...session, session_duration_minutes: parseInt(e.target.value, 10) })}
              />
            </div>

            <div className="ai-form-row">
              <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <input
                  type="checkbox"
                  checked={session.delete_data_on_uninstall === true}
                  onChange={e => setSession({ ...session, delete_data_on_uninstall: e.target.checked })}
                />
                Completely erase all plugin tables and data on plugin uninstall
              </label>
            </div>
          </div>

          <button className="ai-btn ai-btn-primary" onClick={saveAllSettings} disabled={isSaving}>{isSaving ? 'Saving…' : 'Save Retention Settings'}</button>
        </div>
      )}

      {/* SUBTAB 5: SOUNDS */}
      {subTab === 'sounds' && (
        <div className="ai-card">
          <div className="ai-card-header">
            <div>
              <h3>Sound Effects</h3>
              <p>Auditory feedback for visitors. Off by default for non-intrusive browsing.</p>
            </div>
          </div>

          <div className="ai-form-row">
            <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <input
                type="checkbox"
                checked={sounds.enabled === true}
                onChange={e => setSounds({ ...sounds, enabled: e.target.checked })}
              />
              Enable Sound Effects
            </label>
          </div>

          {sounds.enabled && (
            <div className="ai-grid-2">
              <div className="ai-form-row">
                <label>Sound Volume</label>
                <select value={sounds.volume || 'medium'} onChange={e => setSounds({ ...sounds, volume: e.target.value })}>
                  <option value="low">Low</option>
                  <option value="medium">Medium</option>
                  <option value="high">High</option>
                </select>
              </div>

              <div className="ai-form-row">
                <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <input
                    type="checkbox"
                    checked={sounds.message_sound !== false}
                    onChange={e => setSounds({ ...sounds, message_sound: e.target.checked })}
                  />
                  Play sound on incoming message
                </label>
              </div>
            </div>
          )}

          <button className="ai-btn ai-btn-primary" onClick={saveAllSettings} disabled={isSaving}>{isSaving ? 'Saving…' : 'Save Sounds'}</button>
        </div>
      )}

      {/* SUBTAB 6: LANGUAGE & RTL */}
      {subTab === 'language' && (
        <div className="ai-card">
          <div className="ai-card-header">
            <div>
              <h3>AI Response Language</h3>
              <p>Manage multi-language answering behavior and text directions.</p>
            </div>
          </div>

          <div className="ai-form-row">
            <label>Response Language Mode</label>
            <select value={language.mode || 'auto'} onChange={e => setLanguage({ ...language, mode: e.target.value })}>
              <option value="auto">Auto-detect (Bot replies in visitor's language - Recommended)</option>
              <option value="fixed">Fixed Language (Always reply in fixed language)</option>
              <option value="auto_fallback">Auto-detect with Fallback to Default</option>
            </select>
            <small style={{ color: '#64748b' }}>
              Auto-detect replies in the visitor's language and follows them when they switch languages.
            </small>
          </div>

          <div className="ai-form-row">
            <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <input
                type="checkbox"
                checked={language.detect_on_input !== false}
                onChange={e => setLanguage({ ...language, detect_on_input: e.target.checked })}
              />
              Automatically detect the visitor's language
            </label>
            <small style={{ color: '#64748b' }}>
              When off, the bot always answers in the default language and never switches.
            </small>
          </div>

          <div className="ai-form-row">
            <label>Default / Preferred Response Language</label>
            <select value={language.default_language || 'en'} onChange={e => setLanguage({ ...language, default_language: e.target.value })}>
              {LANGUAGE_OPTIONS.map(l => <option key={l.code} value={l.code}>{l.label}</option>)}
            </select>
            <small style={{ color: '#64748b' }}>
              Used when automatic detection is off, on the first message, and as the language of first resort.
            </small>
          </div>

          <div className="ai-form-row">
            <label>Fallback Language</label>
            <select value={language.fallback_language || language.default_language || 'en'} onChange={e => setLanguage({ ...language, fallback_language: e.target.value })}>
              {LANGUAGE_OPTIONS.map(l => <option key={l.code} value={l.code}>{l.label}</option>)}
            </select>
            <small style={{ color: '#64748b' }}>
              Used in &ldquo;Auto-detect with Fallback&rdquo; mode when the language is not detected confidently.
            </small>
          </div>

          <div className="ai-form-row">
            <label>Language Switching Behavior</label>
            <select value={language.switching_behavior || 'auto_switch'} onChange={e => setLanguage({ ...language, switching_behavior: e.target.value })}>
              <option value="auto_switch">Follow the visitor (switch when they change language)</option>
              <option value="sticky">Sticky (keep the conversation's language until a confident switch)</option>
              <option value="fixed">Fixed (never switch away from the first language)</option>
            </select>
            <small style={{ color: '#64748b' }}>
              Controls how the bot reacts when a visitor writes in a different language mid-conversation.
            </small>
          </div>

          <div className="ai-form-row">
            <label>Supported Languages</label>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12 }}>
              {LANGUAGE_OPTIONS.map(l => {
                const enabled = Array.isArray(language.enabled_languages)
                  ? language.enabled_languages.includes(l.code)
                  : true;
                return (
                  <label key={l.code} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 14 }}>
                    <input
                      type="checkbox"
                      checked={!!enabled}
                      onChange={e => {
                        const current = Array.isArray(language.enabled_languages)
                          ? [...language.enabled_languages]
                          : LANGUAGE_OPTIONS.map(o => o.code);
                        const next = e.target.checked
                          ? [...new Set([...current, l.code])]
                          : current.filter(c => c !== l.code);
                        setLanguage({ ...language, enabled_languages: next });
                      }}
                    />
                    {l.label}
                  </label>
                );
              })}
            </div>
            <small style={{ color: '#64748b' }}>
              Languages the bot may answer in. At least one must stay enabled.
            </small>
          </div>

          <button className="ai-btn ai-btn-primary" onClick={saveAllSettings}>Save Language Settings</button>
        </div>
      )}

      {subTab === 'knowledge' && (
        <div className="ai-card">
          <div className="ai-card-header">
            <div>
              <h3>Knowledge &amp; Sitemap</h3>
              <p>Controls which website pages the chatbot may answer from, and how strictly it must match them.</p>
            </div>
          </div>

          <div className="ai-callout ai-callout-info" style={{ marginBottom: 16 }}>
            The chatbot answers only from indexed page content. If a visitor asks something the
            website does not state, the bot says so instead of answering from general knowledge.
          </div>

          <div className="ai-form-row">
            <label>Sitemap / Website URL</label>
            <input
              type="url"
              placeholder={AdminData.homeUrl || 'https://example.com'}
              value={knowledge.sitemap_url || ''}
              onChange={e => setKnowledge({ ...knowledge, sitemap_url: e.target.value })}
            />
            <small style={{ color: '#64748b' }}>
              Leave blank to use this site&rsquo;s own address. Use &ldquo;Refresh Sitemap&rdquo; on the
              Knowledge dashboard to apply changes immediately.
            </small>
          </div>

          <div className="ai-form-row">
            <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <input
                type="checkbox"
                checked={!!knowledge.auto_sync}
                onChange={e => setKnowledge({ ...knowledge, auto_sync: e.target.checked })}
              />
              Automatically refresh the knowledge base on a schedule
            </label>
            <small style={{ color: '#64748b' }}>
              Adds new pages, re-indexes updated ones, and drops pages removed from the sitemap.
            </small>
          </div>

          <div className="ai-form-row">
            <label>Sync Frequency (hours)</label>
            <input
              type="number"
              min="1"
              max="720"
              style={{ width: 110 }}
              value={knowledge.sync_frequency_hours || 24}
              disabled={!knowledge.auto_sync}
              onChange={e => setKnowledge({ ...knowledge, sync_frequency_hours: e.target.value })}
            />
            <small style={{ color: '#64748b' }}>
              The scheduled job runs daily and only acts once this interval has elapsed.
            </small>
          </div>

          <div className="ai-form-row">
            <label>Minimum Relevance Score: {parseFloat(knowledge.min_relevance_score || 0.32).toFixed(2)}</label>
            <input
              type="range"
              min="0"
              max="0.9"
              step="0.01"
              value={knowledge.min_relevance_score ?? 0.32}
              onChange={e => setKnowledge({ ...knowledge, min_relevance_score: e.target.value })}
            />
            <small style={{ color: '#64748b' }}>
              Below this similarity the bot replies &ldquo;not on this website&rdquo; instead of guessing.
              Raise it toward 0.5&ndash;0.6 for stricter answers; lower it if legitimate questions
              are being refused.
            </small>
          </div>

          <button className="ai-btn ai-btn-primary" onClick={saveAllSettings} disabled={isSaving}>{isSaving ? 'Saving…' : 'Save Knowledge Settings'}</button>
        </div>
      )}

      {/* SUBTAB: RESPONSE BEHAVIOR & SMART SOURCE LINKS */}
      {subTab === 'response_behavior' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
          {/* CARD 1: SMART SOURCE LINKS SETTINGS */}
          <div className="ai-card">
            <div className="ai-card-header">
              <div>
                <h3>Smart Source Links</h3>
                <p>Add helpful, relevant links to answers using only the plugin&rsquo;s Knowledge Base &amp; Retrieval Sources and the website sitemap(s).</p>
              </div>
            </div>

            <div className="ai-callout ai-callout-info" style={{ marginBottom: 20 }}>
              <strong>Whitelist-Only Source Links:</strong> Only URLs in the internal link index (KB sources and XML sitemaps) may ever appear in a response. The model is given structured candidate placeholders and is prohibited from guessing or inventing URLs. Any unverified URL is automatically removed.
            </div>

            <div className="ai-form-row" style={{ marginBottom: 20, paddingBottom: 16, borderBottom: '1px solid #f1f5f9' }}>
              <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  checked={!!responseBehavior.auto_links_verified}
                  onChange={e => setResponseBehavior({
                    ...responseBehavior,
                    auto_links_verified: e.target.checked,
                    auto_links_citations: e.target.checked
                  })}
                />
                Enable Smart Source Links (Automatic Links and Citations)
              </label>
              <small style={{ color: '#64748b', display: 'block', marginTop: 6, marginLeft: 24, lineHeight: 1.5 }}>
                Disabled by default. When off, block auto-generated links and citations. When on, allow helpful, verified source links matching user questions.
              </small>
            </div>

            <div className="ai-form-row" style={{ marginBottom: 20, paddingBottom: 16, borderBottom: '1px solid #f1f5f9' }}>
              <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  checked={responseBehavior.allow_requested_links !== false}
                  onChange={e => setResponseBehavior({ ...responseBehavior, allow_requested_links: e.target.checked })}
                />
                Allow Links When Explicitly Requested
              </label>
              <small style={{ color: '#64748b', display: 'block', marginTop: 6, marginLeft: 24, lineHeight: 1.5 }}>
                Enabled by default. When the visitor explicitly asks for a website, contact page, product page or URL, the chatbot provides the verified link even if automatic links are disabled.
              </small>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20, marginBottom: 20 }}>
              <div className="ai-form-row">
                <label style={{ fontWeight: 600, display: 'block', marginBottom: 6 }}>
                  Link Style
                </label>
                <select
                  value={responseBehavior.link_style || 'inline'}
                  onChange={e => setResponseBehavior({ ...responseBehavior, link_style: e.target.value })}
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1' }}
                >
                  <option value="inline">Inline (e.g. [Services](url))</option>
                  <option value="block">Related links block (e.g. &quot;Mehr dazu: [Services](url)&quot;)</option>
                  <option value="both">Both (Inline links + Related links block)</option>
                </select>
                <small style={{ color: '#64748b', display: 'block', marginTop: 4 }}>
                  Controls how verified source links appear in replies.
                </small>
              </div>

              <div className="ai-form-row">
                <label style={{ fontWeight: 600, display: 'block', marginBottom: 6 }}>
                  Relevance Threshold (0.00 – 1.00)
                </label>
                <input
                  type="number"
                  step="0.05"
                  min="0"
                  max="1"
                  value={responseBehavior.link_relevance_threshold !== undefined ? responseBehavior.link_relevance_threshold : 0.60}
                  onChange={e => setResponseBehavior({ ...responseBehavior, link_relevance_threshold: parseFloat(e.target.value) || 0 })}
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1' }}
                />
                <small style={{ color: '#64748b', display: 'block', marginTop: 4 }}>
                  Minimum score required before a sitemap link is added (default: 0.60).
                </small>
              </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20, marginBottom: 20 }}>
              <div className="ai-form-row">
                <label style={{ fontWeight: 600, display: 'block', marginBottom: 6 }}>
                  Index Refresh Interval
                </label>
                <select
                  value={responseBehavior.rebuild_interval || 'daily'}
                  onChange={e => setResponseBehavior({ ...responseBehavior, rebuild_interval: e.target.value })}
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1' }}
                >
                  <option value="hourly">Hourly</option>
                  <option value="daily">Daily (Recommended)</option>
                  <option value="weekly">Weekly</option>
                </select>
                <small style={{ color: '#64748b', display: 'block', marginTop: 4 }}>
                  How often WP-Cron rebuilds and revalidates the Link Index.
                </small>
              </div>

              <div className="ai-form-row">
                <label style={{ fontWeight: 600, display: 'block', marginBottom: 6 }}>
                  Maximum Links Per Answer (1–5)
                </label>
                <input
                  type="number"
                  min="1"
                  max="5"
                  value={responseBehavior.max_links_per_answer !== undefined ? responseBehavior.max_links_per_answer : 2}
                  onChange={e => setResponseBehavior({ ...responseBehavior, max_links_per_answer: parseInt(e.target.value, 10) || 1 })}
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1' }}
                />
                <small style={{ color: '#64748b', display: 'block', marginTop: 4 }}>
                  Limits maximum verified citations per reply (default: 2).
                </small>
              </div>
            </div>

            <div className="ai-form-row" style={{ marginBottom: 20, paddingBottom: 16, borderBottom: '1px solid #f1f5f9' }}>
              <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  checked={responseBehavior.deduplicate_conversation_links !== false}
                  onChange={e => setResponseBehavior({ ...responseBehavior, deduplicate_conversation_links: e.target.checked })}
                />
                Do Not Link Same Page Twice in Same Conversation
              </label>
              <small style={{ color: '#64748b', display: 'block', marginTop: 6, marginLeft: 24, lineHeight: 1.5 }}>
                Prevents repeating the same link across consecutive turns unless the visitor explicitly asks for it again.
              </small>
            </div>

            <div className="ai-form-row" style={{ marginBottom: 20 }}>
              <label style={{ fontWeight: 600, display: 'block', marginBottom: 6 }}>
                Verified Contact Page URL
              </label>
              <input
                type="url"
                placeholder="https://example.com/contact"
                value={responseBehavior.contact_url || ''}
                onChange={e => setResponseBehavior({ ...responseBehavior, contact_url: e.target.value })}
                style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1' }}
              />
              <small style={{ color: '#64748b', display: 'block', marginTop: 4 }}>
                URL provided when visitor asks for human contact or support.
              </small>
            </div>
          </div>

          {/* CARD 2: SITEMAP & PATTERNS */}
          <div className="ai-card">
            <div className="ai-card-header">
              <div>
                <h3>Sitemaps &amp; URL Exclusions</h3>
                <p>Manage sitemap sources and exclusion patterns for the Verified Link Registry.</p>
              </div>
            </div>

            <div className="ai-form-row" style={{ marginBottom: 20 }}>
              <label style={{ fontWeight: 600, display: 'block', marginBottom: 6 }}>
                Custom Sitemap URLs
              </label>
              <textarea
                rows="3"
                placeholder="https://example.com/custom-sitemap.xml"
                value={responseBehavior.sitemap_urls || ''}
                onChange={e => setResponseBehavior({ ...responseBehavior, sitemap_urls: e.target.value })}
                style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1', fontFamily: 'monospace', fontSize: 13 }}
              />
              <small style={{ color: '#64748b', display: 'block', marginTop: 4 }}>
                XML sitemaps (/sitemap.xml, /wp-sitemap.xml, /sitemap_index.xml, robots.txt) are auto-detected. Add extra sitemaps here, one per line.
              </small>
            </div>

            <div className="ai-form-row" style={{ marginBottom: 20 }}>
              <label style={{ fontWeight: 600, display: 'block', marginBottom: 6 }}>
                Allowed Extra Domains
              </label>
              <input
                type="text"
                placeholder="example.com, partner-domain.com"
                value={responseBehavior.allowed_domains || ''}
                onChange={e => setResponseBehavior({ ...responseBehavior, allowed_domains: e.target.value })}
                style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1' }}
              />
              <small style={{ color: '#64748b', display: 'block', marginTop: 4 }}>
                Comma-separated list of external domains permitted in responses (default: site host only).
              </small>
            </div>

            <div className="ai-form-row" style={{ marginBottom: 20 }}>
              <label style={{ fontWeight: 600, display: 'block', marginBottom: 6 }}>
                Excluded URL Patterns (One per line)
              </label>
              <textarea
                rows="5"
                value={responseBehavior.excluded_patterns || ''}
                onChange={e => setResponseBehavior({ ...responseBehavior, excluded_patterns: e.target.value })}
                style={{ width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #cbd5e1', fontFamily: 'monospace', fontSize: 13 }}
              />
              <small style={{ color: '#64748b', display: 'block', marginTop: 4 }}>
                URLs containing these substrings (e.g. draft, staging, /wp-admin, /cart, /feed) are excluded from the registry.
              </small>
            </div>

            <div className="ai-form-row" style={{ marginBottom: 10 }}>
              <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  checked={responseBehavior.cron_revalidation_enabled !== false}
                  onChange={e => setResponseBehavior({ ...responseBehavior, cron_revalidation_enabled: e.target.checked })}
                />
                Enable Daily WP-Cron Link Revalidation
              </label>
              <small style={{ color: '#64748b', display: 'block', marginTop: 6, marginLeft: 24, lineHeight: 1.5 }}>
                Automatically revalidates all URLs in the registry once daily in the background. Failed URLs are safely removed after 2 consecutive failures.
              </small>
            </div>
          </div>

          {/* CARD 3: LINK INDEX STATUS & REBUILD */}
          <div className="ai-card">
            <div className="ai-card-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <h3>Smart Source Links Index Status &amp; Health</h3>
                <p>Live status of the internal link index built from Knowledge Base sources and website XML sitemaps.</p>
              </div>
              <div style={{ display: 'flex', gap: 10 }}>
                <button
                  type="button"
                  className="ai-btn ai-btn-secondary ai-btn-sm"
                  onClick={fetchLinkStatus}
                  disabled={isLoadingLinkStatus}
                >
                  {isLoadingLinkStatus ? 'Refreshing…' : 'Refresh Status'}
                </button>
                <button
                  type="button"
                  className="ai-btn ai-btn-primary ai-btn-sm"
                  onClick={handleRebuildLinks}
                  disabled={isRebuildingLinks}
                  style={{ background: '#059669', borderColor: '#059669' }}
                >
                  {isRebuildingLinks ? 'Rebuilding Index…' : '⚡ Rebuild Link Index'}
                </button>
              </div>
            </div>

            {linkRebuildNotice && (
              <div className="ai-callout ai-callout-success" style={{ marginBottom: 16 }}>
                {linkRebuildNotice}
              </div>
            )}

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 20 }}>
              <div style={{ padding: '16px', background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0', textAlign: 'center' }}>
                <div style={{ fontSize: 28, fontWeight: 700, color: '#059669' }}>
                  {linkStatus ? (linkStatus.url_count || linkStatus.valid_count || 0) : '–'}
                </div>
                <div style={{ fontSize: 13, color: '#64748b', fontWeight: 600, marginTop: 4 }}>Total Indexed URLs</div>
              </div>

              <div style={{ padding: '16px', background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0', textAlign: 'center' }}>
                <div style={{ fontSize: 28, fontWeight: 700, color: '#dc2626' }}>
                  {linkStatus ? (linkStatus.failed_count || linkStatus.invalid || 0) : '–'}
                </div>
                <div style={{ fontSize: 13, color: '#64748b', fontWeight: 600, marginTop: 4 }}>Failed / Excluded URLs</div>
              </div>

              <div style={{ padding: '16px', background: '#f8fafc', borderRadius: 8, border: '1px solid #e2e8f0', textAlign: 'center' }}>
                <div style={{ fontSize: 14, fontWeight: 600, color: '#1e293b', marginTop: 6 }}>
                  {linkStatus && linkStatus.last_rebuild ? linkStatus.last_rebuild : 'Never'}
                </div>
                <div style={{ fontSize: 13, color: '#64748b', fontWeight: 600, marginTop: 4 }}>Last Built Time</div>
              </div>
            </div>

            <div style={{ borderTop: '1px solid #f1f5f9', paddingTop: 16 }}>
              <button
                type="button"
                className="ai-btn ai-btn-secondary ai-btn-sm"
                onClick={() => setShowLinkLogs(!showLinkLogs)}
              >
                {showLinkLogs ? 'Hide Validation Logs' : 'View Validation Logs (Last 100 Entries)'}
              </button>

              {showLinkLogs && (
                <div style={{ marginTop: 16, maxHeight: 350, overflowY: 'auto', border: '1px solid #e2e8f0', borderRadius: 6 }}>
                  {linkStatus && linkStatus.logs && linkStatus.logs.length > 0 ? (
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, textAlign: 'left' }}>
                      <thead>
                        <tr style={{ background: '#f1f5f9', borderBottom: '1px solid #cbd5e1' }}>
                          <th style={{ padding: '8px 12px' }}>Timestamp</th>
                          <th style={{ padding: '8px 12px' }}>URL</th>
                          <th style={{ padding: '8px 12px' }}>Status</th>
                          <th style={{ padding: '8px 12px' }}>HTTP</th>
                          <th style={{ padding: '8px 12px' }}>Reason</th>
                        </tr>
                      </thead>
                      <tbody>
                        {linkStatus.logs.map((log, idx) => (
                          <tr key={idx} style={{ borderBottom: '1px solid #f1f5f9' }}>
                            <td style={{ padding: '8px 12px', whiteSpace: 'nowrap', color: '#64748b' }}>{log.timestamp || log.time}</td>
                            <td style={{ padding: '8px 12px', maxWidth: 300, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                              <a href={log.url} target="_blank" rel="noreferrer" style={{ color: '#2563eb' }}>{log.url}</a>
                            </td>
                            <td style={{ padding: '8px 12px' }}>
                              <span style={{
                                padding: '2px 6px',
                                borderRadius: 4,
                                fontSize: 11,
                                fontWeight: 600,
                                background: log.status === 'valid' ? '#dcfce7' : '#fee2e2',
                                color: log.status === 'valid' ? '#166534' : '#991b1b'
                              }}>
                                {log.status ? log.status.toUpperCase() : 'UNKNOWN'}
                              </span>
                            </td>
                            <td style={{ padding: '8px 12px' }}>{log.http_code || '–'}</td>
                            <td style={{ padding: '8px 12px', color: '#64748b' }}>{log.reason}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  ) : (
                    <div style={{ padding: 20, textAlign: 'center', color: '#64748b' }}>
                      No validation log entries recorded yet. Click &ldquo;Rebuild &amp; Validate Links Now&rdquo; to test your site links.
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>

          <div>
            <button className="ai-btn ai-btn-primary" onClick={saveAllSettings} disabled={isSaving} style={{ padding: '10px 24px', fontSize: 15 }}>
              {isSaving ? 'Saving…' : 'Save Response Behavior Settings'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

// -------------------------------------------------------------
// VIEW 5: SETUP WIZARD (ONE-PASTE 2-MINUTE ONBOARDING)
// -------------------------------------------------------------
function WizardView({ onCompleted }) {
  const [url, setUrl] = useState(AdminData.homeUrl);
  const [apiKey, setApiKey] = useState('');
  const [embeddingProvider, setEmbeddingProvider] = useState('openai');
  const [embeddingApiKey, setEmbeddingApiKey] = useState('');
  const [embeddingKeyValid, setEmbeddingKeyValid] = useState(null);
  const [validatingKey, setValidatingKey] = useState(false);
  const [step, setStep] = useState('input'); // 'input', 'scanning', 'review'
  const [progressMsg, setProgressMsg] = useState('');
  const [draft, setDraft] = useState(null);

  const validateEmbeddingKey = async () => {
    const key = embeddingApiKey.trim();
    if (!key) {
      setEmbeddingKeyValid(false);
      return;
    }

    setValidatingKey(true);
    setEmbeddingKeyValid(null);

    try {
      const res = await apiCall('/wizard/validate-key', 'POST', {
        api_key: key,
        provider: embeddingProvider,
      });

      if (res.success && res.data?.valid) {
        setEmbeddingKeyValid(true);
      } else {
        setEmbeddingKeyValid(false);
        alert(res.message || 'Invalid embedding API key. Please check and try again.');
      }
    } catch (e) {
      setEmbeddingKeyValid(false);
      alert('Could not validate the embedding API key. Please try again.');
    } finally {
      setValidatingKey(false);
    }
  };

  const startWizard = async () => {
    if (!embeddingApiKey.trim()) {
      alert('Please enter an Embedding API Key to enable semantic search.');
      return;
    }

    if (embeddingKeyValid !== true) {
      const confirmed = confirm(
        'The embedding API key has not been validated. The chatbot may not work correctly without it. Do you want to continue anyway?'
      );
      if (!confirmed) return;
    }

    setStep('scanning');
    setProgressMsg('Scanning domain and crawling key pages...');

    try {
      const res = await apiCall('/wizard/start', 'POST', { url, api_key: apiKey });
      if (res.success && res.data.draft) {
        setDraft(res.data.draft);
        setStep('review');
      } else {
        alert(res.message || 'Wizard failed to extract content from the website.');
        setStep('input');
      }
    } catch (e) {
      alert('Error communicating with wizard.');
      setStep('input');
    }
  };

  const applyDraft = async () => {
    const res = await apiCall('/wizard/apply', 'POST', {
      approved: draft,
      embedding_api_key: embeddingApiKey.trim(),
      embedding_provider: embeddingProvider,
    });
    if (res.success) {
      onCompleted();
    } else {
      alert(res.message || 'Failed to apply settings.');
    }
  };

  return (
    <div className="ai-card" style={{ maxWidth: 800, margin: '0 auto' }}>
      <div className="ai-card-header">
        <div>
          <h3>One-Paste Chatbot Setup Wizard</h3>
          <p>Paste your site URL and let AI draft your business facts, persona, starter questions, and FAQ in 2 minutes.</p>
        </div>
      </div>

      {step === 'input' && (
        <div>
          <div className="ai-form-row">
            <label>Website URL *</label>
            <input type="url" value={url} onChange={e => setUrl(e.target.value)} placeholder="https://example.com" />
          </div>

          <div className="ai-form-row">
            <label>AI Chat API Key (for analysis)</label>
            <input type="password" value={apiKey} onChange={e => setApiKey(e.target.value)} placeholder="gsk_... or sk-..." />
            <small>Used only during setup to analyze your website.</small>
          </div>

          <div className="ai-form-row">
            <label>Embedding Provider *</label>
            <select value={embeddingProvider} onChange={e => setEmbeddingProvider(e.target.value)}>
              <option value="openai">OpenAI (text-embedding-3)</option>
              <option value="mistral">Mistral (mistral-embed)</option>
            </select>
          </div>

          <div className="ai-form-row">
            <label>Embedding API Key *</label>
            <input
              type="password"
              value={embeddingApiKey}
              onChange={e => {
                setEmbeddingApiKey(e.target.value);
                setEmbeddingKeyValid(null);
              }}
              placeholder={
                embeddingProvider === 'openai'
                  ? 'sk-...'
                  : 'Enter your Mistral API key'
              }
            />
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 4 }}>
              <button
                type="button"
                className="ai-btn ai-btn-secondary ai-btn-sm"
                onClick={validateEmbeddingKey}
                disabled={validatingKey || !embeddingApiKey.trim()}
              >
                {validatingKey ? 'Validating...' : 'Validate Key'}
              </button>
              {embeddingKeyValid === true && (
                <span style={{ color: '#059669', fontSize: 13 }}>✓ Valid</span>
              )}
              {embeddingKeyValid === false && (
                <span style={{ color: '#dc2626', fontSize: 13 }}>✗ Invalid</span>
              )}
            </div>
            <small>Required for semantic search. This key is saved securely and used to power your chatbot's knowledge retrieval.</small>
          </div>

          <button className="ai-btn ai-btn-primary" onClick={startWizard} style={{ padding: '12px 24px', fontSize: 15 }}>
            ⚡ Start 2-Minute Setup
          </button>
        </div>
      )}

      {step === 'scanning' && (
        <div style={{ textAlign: 'center', padding: '40px 20px' }}>
          <div style={{ fontSize: 32, marginBottom: 16 }}>🤖</div>
          <h4>Reading and Analyzing Your Website...</h4>
          <p style={{ color: '#64748b' }}>{progressMsg}</p>
        </div>
      )}

      {step === 'review' && draft && (
        <div>
          <div className="ai-callout ai-callout-info">
            Nothing has been published yet. Review and edit any drafted values before applying.
          </div>

          <h4>Drafted Persona</h4>
          <div className="ai-form-row">
            <label>Agent Name</label>
            <input type="text" value={draft.agent?.name || ''} onChange={e => setDraft({ ...draft, agent: { ...draft.agent, name: e.target.value } })} />
          </div>

          <div className="ai-form-row">
            <label>Greeting</label>
            <input type="text" value={draft.agent?.greeting || ''} onChange={e => setDraft({ ...draft, agent: { ...draft.agent, greeting: e.target.value } })} />
          </div>

          <h4>Drafted Suggested Questions</h4>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 20 }}>
            {draft.suggested_questions?.map((q, i) => (
              <input
                key={i}
                type="text"
                value={q}
                onChange={e => {
                  const copy = [...draft.suggested_questions];
                  copy[i] = e.target.value;
                  setDraft({ ...draft, suggested_questions: copy });
                }}
              />
            ))}
          </div>

          <div style={{ display: 'flex', gap: 12 }}>
            <button className="ai-btn ai-btn-primary" style={{ padding: '12px 28px', fontSize: 15 }} onClick={applyDraft}>
              ✓ Approve & Publish Chatbot
            </button>
            <button className="ai-btn ai-btn-secondary" onClick={() => setStep('input')}>Cancel</button>
          </div>
        </div>
      )}
    </div>
  );
}

// -------------------------------------------------------------
// STATUS FILTER (SEGMENTED BUTTONS)
// -------------------------------------------------------------

/**
 * Segmented status filter.
 *
 * This started as a native <select>, which rendered as a plain OS control in
 * the middle of a row of .ai-btn buttons and read as a different kind of
 * thing entirely. It is now built from the same button classes the sub-tab
 * toggles use, so it sits in the header without looking bolted on.
 *
 * Buttons rather than a dropdown also means every filter and its count are
 * visible at once. On a review queue the counts are the point: they tell you
 * whether the filter is worth changing before you change it.
 *
 * @param {Object} props Component props.
 * @return {Object} React element.
 */
function StatusFilter({ options, value, onChange, ariaLabel }) {
  if (!options || options.length === 0) return null;

  return (
    <div className="ai-filter-group" role="group" aria-label={ariaLabel}>
      {options.map(opt => {
        const active = opt.value === value;

        return createElement(
          'button',
          {
            key: opt.value,
            type: 'button',
            className: `ai-btn ai-btn-sm ${active ? 'ai-btn-primary' : 'ai-btn-secondary'}`,
            'aria-pressed': active,
            onClick: () => onChange(opt.value),
            title: opt.title || opt.label
          },
          opt.label,
          typeof opt.count === 'number' && (
            createElement('span', { key: 'c', className: 'ai-filter-count' }, opt.count)
          )
        );
      })}
    </div>
  );
}

// -------------------------------------------------------------
// DIFF: WORD-LEVEL COMPARISON OF AN AI DRAFT VS A MANUAL EDIT
// -------------------------------------------------------------

/**
 * Split text into comparable tokens, keeping whitespace as its own token so
 * rejoining the result reproduces the original string exactly.
 *
 * @param {string} text Input text.
 * @return {Array<string>} Tokens.
 */
function diffTokens(text) {
  if (text == null) return [];
  return String(text).match(/\s+|[^\s]+/g) || [];
}

/**
 * Longest common subsequence table walk, used to mark which words changed.
 *
 * A full LCS is quadratic in token count. Answers here run to a few hundred
 * words, so the table stays small; the length guard only exists so a pasted
 * wall of text cannot lock the editor.
 *
 * @param {Array<string>} a Original tokens.
 * @param {Array<string>} b Edited tokens.
 * @return {Array<Array<number>>} Lengths table.
 */
function diffMatrix(a, b) {
  const rows = a.length + 1;
  const cols = b.length + 1;
  const m = [];

  for (let i = 0; i < rows; i++) m.push(new Array(cols).fill(0));

  for (let i = a.length - 1; i >= 0; i--) {
    for (let j = b.length - 1; j >= 0; j--) {
      m[i][j] = a[i] === b[j] ? m[i + 1][j + 1] + 1 : Math.max(m[i + 1][j], m[i][j + 1]);
    }
  }

  return m;
}

/**
 * Compare two texts and describe the change as a list of operations.
 *
 * @param {string} original Text the AI produced.
 * @param {string} edited Text the administrator produced.
 * @return {Array<{type: 'same'|'added'|'removed', text: string}>}
 */
function diffWords(original, edited) {
  const a = diffTokens(original);
  const b = diffTokens(edited);

  if (a.length === 0 && b.length === 0) return [];
  if (a.length === 0) return [{ type: 'added', text: edited }];
  if (b.length === 0) return [{ type: 'removed', text: original }];

  const m = diffMatrix(a, b);
  const ops = [];
  let i = 0;
  let j = 0;

  const push = (type, text) => {
    const last = ops[ops.length - 1];
    if (last && last.type === type) last.text += text;
    else ops.push({ type, text });
  };

  while (i < a.length && j < b.length) {
    if (a[i] === b[j]) {
      push('same', a[i]);
      i++;
      j++;
    } else if (m[i + 1][j] >= m[i][j + 1]) {
      push('removed', a[i]);
      i++;
    } else {
      push('added', b[j]);
      j++;
    }
  }

  while (i < a.length) { push('removed', a[i]); i++; }
  while (j < b.length) { push('added', b[j]); j++; }

  return ops;
}

/**
 * Count added and removed words so the editor can show how much was rewritten.
 *
 * @param {Array<{type: string, text: string}>} ops Output of diffWords().
 * @return {{added: number, removed: number, changed: boolean}}
 */
function diffCounts(ops) {
  let added = 0;
  let removed = 0;

  ops.forEach(op => {
    const words = op.text.trim() ? op.text.trim().split(/\s+/).length : 0;
    if (op.type === 'added') added += words;
    if (op.type === 'removed') removed += words;
  });

  return { added, removed, changed: added > 0 || removed > 0 };
}

/**
 * Render the word-level diff as React nodes.
 *
 * Built as nodes rather than injected HTML, so model or admin text can never
 * introduce markup into the admin page.
 *
 * @param {Array<{type: string, text: string}>} ops Output of diffWords().
 * @return {Array} React nodes.
 */
function renderDiffNodes(ops) {
  return ops.map((op, idx) => {
    if (op.type === 'same') return op.text;

    return createElement(
      op.type === 'added' ? 'ins' : 'del',
      { key: `d${idx}`, className: `ai-diff-${op.type}` },
      op.text
    );
  });
}

// -------------------------------------------------------------
// DRAFT EDITOR MODAL (Unanswered Questions & Scope Tuning)
// -------------------------------------------------------------

/**
 * Modal for correcting an AI-drafted answer.
 *
 * The AI text is never destroyed. It is kept as the diff baseline and stays
 * reachable through Revert, because an administrator comparing their wording
 * against the model's is the whole point of the screen: it is how a bad prompt
 * or a missing knowledge source becomes visible.
 *
 * Saving and publishing are deliberately separate buttons. Save keeps the work
 * in the review queue; Approve publishes it to the knowledge base where
 * retrieval will start using it.
 *
 * @param {Object} props Component props.
 * @return {Object} React element.
 */
function DraftEditorModal({
  mode,               // 'unanswered' | 'out_of_scope'
  item,               // Row being edited.
  answer,             // Current editor value.
  onChange,           // Editor change handler.
  onClose,            // Close handler.
  onSave,             // Save draft handler.
  onRegenerate,       // Regenerate AI draft handler.
  onRevert,           // Revert to original AI draft handler.
  onRule,             // Ruling handler (out_of_scope only).
  onApprove,          // Publish handler.
  saving,
  regenerating
}) {
  if (!item) return null;

  const aiDraft = item.ai_draft_answer || null;
  const humanEdited = item.draft_source === 'human';
  const ops = diffWords(aiDraft || '', answer || '');
  const counts = diffCounts(ops);
  const showDiff = Boolean(aiDraft) && Boolean(answer);
  const isScope = mode === 'out_of_scope';

  const sourceBadge = humanEdited
    ? { cls: 'ai-badge-warning', label: 'Edited by you' }
    : item.draft_source === 'ai'
      ? { cls: 'ai-badge-neutral', label: 'AI draft, not yet edited' }
      : { cls: 'ai-badge-neutral', label: 'No draft yet' };

  return (
    <div className="ai-admin-modal-backdrop">
      <div className="ai-admin-modal" style={{ maxWidth: 860 }}>
        <div className="ai-modal-head">
          <h3>{isScope ? 'Draft Answer for Deflected Question' : 'Edit AI Drafted Answer'}</h3>
          <button
            style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 18 }}
            onClick={onClose}
            aria-label="Close draft editor"
          >
            ✕
          </button>
        </div>

        <div className="ai-modal-body">
          <div className="ai-question-context">
            <div className="ai-question-context-label">Visitor asked {item.occurrences || 1} time(s)</div>
            <div className="ai-question-context-text">{item.question}</div>
          </div>

          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 10, flexWrap: 'wrap' }}>
            <span className={`ai-badge ${sourceBadge.cls}`}>{sourceBadge.label}</span>
            {item.edit_count > 0 && (
              <span className="ai-badge ai-badge-neutral">
                {item.edit_count} edit{item.edit_count === 1 ? '' : 's'}
              </span>
            )}
            {item.draft_edited_at && (
              <span style={{ fontSize: 12, color: '#64748b' }}>
                Last edited {new Date(item.draft_edited_at.replace(' ', 'T')).toLocaleString()}
              </span>
            )}
          </div>

          <div className="ai-form-row">
            <label htmlFor="ai-draft-answer">
              {isScope
                ? 'Corrected answer (used when you rule this question in scope)'
                : 'Corrected answer'}
            </label>
            <textarea
              id="ai-draft-answer"
              rows="10"
              value={answer}
              autoFocus
              onChange={e => onChange(e.target.value)}
              placeholder="Write the verified answer here. The AI draft is preserved so you can compare and revert."
            />
          </div>

          {showDiff && (
            <div className="ai-diff-block">
              <div className="ai-diff-head">
                <strong>Change vs. original AI draft</strong>
                <span className="ai-diff-summary">
                  {counts.changed
                    ? `+${counts.added} / -${counts.removed} words`
                    : 'unchanged so far'}
                </span>
              </div>
              <div className="ai-diff-body">{renderDiffNodes(ops)}</div>
            </div>
          )}

          {aiDraft && !answer && (
            <p className="ai-hint-text">
              The original AI draft is kept. Use Revert below to restore it, or start typing your own answer.
            </p>
          )}

          <p className="ai-hint-text">
            Saving keeps this answer in the review queue. It only reaches visitors once you approve it, at which point
            it becomes a curated Q&amp;A entry and retrieval starts preferring it.
          </p>
        </div>

        <div className="ai-modal-foot">
          <div className="ai-modal-foot-left">
            {aiDraft && (
              <button
                className="ai-btn ai-btn-secondary ai-btn-sm"
                onClick={onRevert}
                disabled={saving || regenerating || !humanEdited}
                title={humanEdited ? 'Restore the original AI draft' : 'No manual edit to undo'}
              >
                Revert to AI draft
              </button>
            )}
            <button
              className="ai-btn ai-btn-secondary ai-btn-sm"
              onClick={onRegenerate}
              disabled={saving || regenerating}
            >
              {regenerating ? 'Generating...' : 'Regenerate with AI'}
            </button>
          </div>

          <div className="ai-modal-foot-right">
            <button className="ai-btn ai-btn-secondary" onClick={onClose} disabled={saving}>
              Close
            </button>
            <button
              className="ai-btn ai-btn-secondary"
              onClick={onSave}
              disabled={saving || regenerating || !answer.trim()}
              title="Keep the correction in the review queue"
            >
              {saving ? 'Saving...' : 'Save draft'}
            </button>
            <button
              className="ai-btn ai-btn-primary"
              onClick={onApprove}
              disabled={saving || regenerating || !answer.trim()}
            >
              {isScope ? 'Rule in scope & publish' : 'Approve to Q&A'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

// -------------------------------------------------------------
// VIEW 6: UNANSWERED INBOX & OUT OF SCOPE
// -------------------------------------------------------------
function InboxView({ unanswered, outOfScope, setUnanswered, setOutOfScope, notifySave }) {
  const [subTab, setSubTab] = useState('gaps');
  const [statusFilter, setStatusFilter] = useState('active');
  const [scopeStatusFilter, setScopeStatusFilter] = useState('pending');
  const [accuracy, setAccuracy] = useState(null);
  // Draft editor state. 'mode' selects which table the row belongs to, because
  // the two review queues share one editor and differ only in the final action.
  const [editor, setEditor] = useState(null);
  const [editorAnswer, setEditorAnswer] = useState('');
  const [editorSaving, setEditorSaving] = useState(false);
  const [editorRegenerating, setEditorRegenerating] = useState(false);
  // Question-rewording modal, separate from the answer editor: that one edits
  // the answer, this one edits what the visitor actually asked.
  const [questionModal, setQuestionModal] = useState(null);
  const [questionDraft, setQuestionDraft] = useState('');
  const [questionSaving, setQuestionSaving] = useState(false);

  useEffect(() => {
    loadAccuracy();
  }, []);

  const loadAccuracy = async () => {
    const res = await apiCall(`/drafts/accuracy?agent_id=${AdminData.agentId}`);
    if (res.success) setAccuracy(res.data);
  };

  /**
   * Open the draft editor for a row.
   *
   * @param {string} mode 'unanswered' or 'out_of_scope'.
   * @param {Object} item Row.
   */
  const openEditor = (mode, item) => {
    setEditor({ mode, item });
    setEditorAnswer(item.draft_answer || '');
  };

  /**
   * Merge a saved row back into the local list.
   *
   * Patching from the response rather than refetching keeps the editor open on
   * the row it is working on, which matters because the regenerate and revert
   * actions return a fresh row for the same question.
   *
   * @param {string} mode 'unanswered' or 'out_of_scope'.
   * @param {Object} item Updated row from the API.
   */
  const patchRow = (mode, item) => {
    if (mode === 'unanswered') {
      setUnanswered(unanswered.map(u => (u.id === item.id ? { ...u, ...item } : u)));
    } else {
      setOutOfScope(outOfScope.map(o => (o.id === item.id ? { ...o, ...item } : o)));
    }
    setEditor(prev => (prev ? { ...prev, item } : prev));
  };

  /**
   * Persist the editor contents as a draft, without publishing.
   *
   * @return {Promise<void>}
   */
  const saveEditorDraft = async () => {
    if (!editor || editorSaving) return;

    const answer = editorAnswer.trim();
    if (!answer) return;

    const { mode, item } = editor;
    const path = mode === 'unanswered' ? 'unanswered' : 'out-of-scope';
    setEditorSaving(true);

    try {
      const res = await apiCall(`/${path}/${item.id}/draft`, 'POST', { answer });

      if (!res.success) {
        alert(res.message || 'Could not save the draft.');
        return;
      }

      patchRow(mode, res.data.item);
      notifySave(res.data.message || 'Draft saved.');
    } catch (e) {
      alert('Could not reach the server.');
    } finally {
      setEditorSaving(false);
    }
  };

  /**
   * Ask the AI for a replacement draft.
   *
   * The first AI draft is kept server-side, so this never destroys the
   * baseline the diff is drawn against.
   *
   * @return {Promise<void>}
   */
  const regenerateEditorDraft = async () => {
    if (!editor || editorRegenerating) return;

    const { mode, item } = editor;
    const path = mode === 'unanswered' ? 'unanswered' : 'out-of-scope';

    if (!window.confirm('Replace the current draft with a newly generated AI draft? Your saved edit will be overwritten (the original AI draft is kept for comparison).')) return;

    setEditorRegenerating(true);

    try {
      const res = await apiCall(`/${path}/${item.id}/draft/regenerate`, 'POST', {
        agent_id: AdminData.agentId
      });

      if (!res.success) {
        alert(res.message || 'Could not generate a new draft.');
        return;
      }

      patchRow(mode, res.data.item);
      setEditorAnswer(res.data.item.draft_answer || '');
      notifySave(res.data.message || 'New AI draft generated.');
    } catch (e) {
      alert('Could not reach the server.');
    } finally {
      setEditorRegenerating(false);
    }
  };

  /**
   * Restore the original AI draft.
   *
   * @return {Promise<void>}
   */
  const revertEditorDraft = async () => {
    if (!editor) return;

    const { mode, item } = editor;
    const path = mode === 'unanswered' ? 'unanswered' : 'out-of-scope';
    setEditorSaving(true);

    try {
      const res = await apiCall(`/${path}/${item.id}/draft/revert`, 'POST');

      if (!res.success) {
        alert(res.message || 'Could not revert the draft.');
        return;
      }

      patchRow(mode, res.data.item);
      setEditorAnswer(res.data.item.draft_answer || '');
      notifySave(res.data.message || 'Restored the original AI draft.');
    } catch (e) {
      alert('Could not reach the server.');
    } finally {
      setEditorSaving(false);
    }
  };

  /**
   * Publish the edited answer to the knowledge base.
   *
   * For the scope queue this doubles as the in-scope ruling, which is the only
   * place an out-of-scope entry becomes live knowledge.
   *
   * @return {Promise<void>}
   */
  const approveEditorDraft = async () => {
    if (!editor || editorSaving) return;

    const { mode, item } = editor;
    const answer = editorAnswer.trim();
    if (!answer) return;

    setEditorSaving(true);

    try {
      const res = mode === 'unanswered'
        ? await apiCall(`/unanswered/${item.id}/approve`, 'POST', { answer, agent_id: AdminData.agentId })
        : await apiCall(`/out-of-scope/${item.id}/in-scope`, 'POST', { answer });

      if (!res.success) {
        alert(res.message || 'Could not publish the answer.');
        return;
      }

      if (mode === 'unanswered') {
        setUnanswered(unanswered.map(u => (u.id === item.id ? { ...u, status: 'approved', draft_answer: answer } : u)));
      } else {
        setOutOfScope(outOfScope.map(o => (o.id === item.id ? { ...o, ...(res.data.item || { status: 'in_scope' }), draft_answer: answer } : o)));
      }

      setEditor(null);
      loadAccuracy();
      notifySave(res.data.message || 'Published to the Q&A knowledge base!');
    } catch (e) {
      alert('Could not reach the server.');
    } finally {
      setEditorSaving(false);
    }
  };

  /**
   * Confirm a question was correctly deflected.
   *
   * @param {number} id Out of scope entry ID.
   * @return {Promise<void>}
   */
  const ruleOutOfScope = async (id) => {
    const res = await apiCall(`/out-of-scope/${id}/rule`, 'POST', { ruling: 'out_of_scope' });

    if (res.success) {
      setOutOfScope(outOfScope.map(o => (o.id === id ? { ...o, ...res.data.item } : o)));
      loadAccuracy();
      notifySave(res.data.message || 'Confirmed out of scope.');
    } else {
      alert(res.message || 'Could not record the ruling.');
    }
  };

  /**
   * Reopen a ruled or dismissed row so it can be worked on again.
   *
   * @param {string} mode 'unanswered' or 'out_of_scope'.
   * @param {number} id Row ID.
   * @return {Promise<void>}
   */
  const reopenRow = async (mode, id) => {
    const path = mode === 'unanswered' ? 'unanswered' : 'out-of-scope';
    const res = await apiCall(`/${path}/${id}/reopen`, 'POST');

    if (res.success) {
      const item = res.data.item;
      if (mode === 'unanswered') {
        setUnanswered(unanswered.map(u => (u.id === id ? { ...u, ...item } : u)));
      } else {
        setOutOfScope(outOfScope.map(o => (o.id === id ? { ...o, ...item } : o)));
      }
      notifySave(res.data.message || 'Reopened.');
    } else {
      alert(res.message || 'Could not reopen.');
    }
  };

  /**
   * Save a reworded question.
   *
   * The API recomputes normalized_hash and clears the AI draft, so the row is
   * patched from the response rather than refetching the whole inbox.
   */
  const saveQuestionText = async () => {
    if (!questionModal || questionSaving) return;

    const text = questionDraft.trim();
    if (!text) {
      alert('Question cannot be empty.');
      return;
    }

    setQuestionSaving(true);

    try {
      const path = questionModal.mode === 'out_of_scope' ? 'out-of-scope' : 'unanswered';
      const res = await apiCall(`/${path}/${questionModal.id}/edit`, 'POST', { question: text });

      if (!res.success) {
        alert(res.message || 'Could not update the question.');
        return;
      }

      const updated = res.data.question || res.data.item;
      if (questionModal.mode === 'out_of_scope') {
        setOutOfScope(outOfScope.map(o => (o.id === questionModal.id ? { ...o, ...updated } : o)));
      } else {
        setUnanswered(unanswered.map(u => (u.id === questionModal.id ? { ...u, ...updated } : u)));
      }
      setQuestionModal(null);
      notifySave(res.data.message || 'Question updated. The AI draft was cleared.');
    } catch (e) {
      alert('Could not reach the server.');
    } finally {
      setQuestionSaving(false);
    }
  };

  const dismissQuestion = async (id) => {
    const res = await apiCall(`/unanswered/${id}/dismiss`, 'POST');
    if (res.success) {
      setUnanswered(unanswered.map(u => (u.id === id ? { ...u, status: 'dismissed' } : u)));
      notifySave(res.data?.message || 'Dismissed.');
    }
  };

  // Permanently remove a single logged question from the inbox.
  const deleteQuestion = async (id, question) => {
    if (!window.confirm(`Permanently delete this logged question?\n\n"${question}"\n\nThis cannot be undone.`)) return;

    const res = await apiCall(`/unanswered/${id}/delete`, 'POST');
    if (res.success) {
      setUnanswered(unanswered.filter(u => u.id !== id));
      notifySave('Question deleted.');
    } else {
      alert(res.message || 'Delete failed.');
    }
  };

  // Clear the whole inbox. The API demands confirm = "DELETE ALL".
  const deleteAllQuestions = async () => {
    if (unanswered.length === 0) return;

    const typed = window.prompt(
      `This permanently deletes all ${unanswered.length} logged question(s).\n\nThis cannot be undone. Type DELETE ALL to confirm:`
    );

    if (typed === null) return;
    if (typed.trim().toUpperCase() !== 'DELETE ALL') {
      if (typed.trim() !== '') alert('Confirmation text did not match. Nothing was deleted.');
      return;
    }

    const res = await apiCall('/unanswered/delete-all', 'POST', {
      agent_id: AdminData.agentId,
      confirm: 'DELETE ALL'
    });

    if (res.success) {
      setUnanswered([]);
      notifySave(res.data?.message || 'All logged question(s) deleted.');
    } else {
      alert(res.message || 'Delete all failed.');
    }
  };

  // Client-side status filter so the review queues stay usable once the log grows.
  // Each bucket is counted independently so the filter can show how much is
  // behind it; a filter that hides its own total is hard to reason about.
  const isOpenGap = u => u.status !== 'approved' && u.status !== 'dismissed';
  const isOpenScope = o => o.status !== 'in_scope' && o.status !== 'out_of_scope';

  const gapFilters = [
    {
      value: 'active',
      label: 'Needs work',
      count: unanswered.filter(isOpenGap).length,
      title: 'Everything still waiting for an answer'
    },
    { value: 'new', label: 'No draft yet', count: unanswered.filter(u => u.status === 'new').length },
    { value: 'draft_ready', label: 'Draft ready', count: unanswered.filter(u => u.status === 'draft_ready').length },
    { value: 'approved', label: 'Published', count: unanswered.filter(u => u.status === 'approved').length },
    { value: 'dismissed', label: 'Dismissed', count: unanswered.filter(u => u.status === 'dismissed').length },
    { value: 'all', label: 'All', count: unanswered.length },
  ];

  const scopeFilters = [
    {
      value: 'open',
      label: 'Needs review',
      count: outOfScope.filter(isOpenScope).length,
      title: 'Deflected questions with no ruling yet'
    },
    { value: 'pending', label: 'No draft yet', count: outOfScope.filter(o => o.status === 'pending').length },
    { value: 'draft_ready', label: 'Answer drafted', count: outOfScope.filter(o => o.status === 'draft_ready').length },
    { value: 'in_scope', label: 'Ruled in scope', count: outOfScope.filter(o => o.status === 'in_scope').length },
    { value: 'out_of_scope', label: 'Confirmed off-topic', count: outOfScope.filter(o => o.status === 'out_of_scope').length },
    { value: 'all', label: 'All', count: outOfScope.length },
  ];

  const visibleGaps = unanswered.filter(item => {
    if (statusFilter === 'active') return isOpenGap(item);
    if (statusFilter === 'all') return true;
    return item.status === statusFilter;
  });

  const visibleScope = outOfScope.filter(o => {
    if (scopeStatusFilter === 'all') return true;
    if (scopeStatusFilter === 'open') return isOpenScope(o);
    return o.status === scopeStatusFilter;
  });

  const editedCount = unanswered.filter(u => u.draft_source === 'human').length;

  const statusBadge = status => {
    if (status === 'approved') return 'ai-badge-success';
    if (status === 'draft_ready') return 'ai-badge-info';
    if (status === 'dismissed') return 'ai-badge-neutral';
    return 'ai-badge-warning';
  };

  const scopeBadge = status => {
    if (status === 'in_scope') return 'ai-badge-success';
    if (status === 'out_of_scope') return 'ai-badge-neutral';
    if (status === 'draft_ready') return 'ai-badge-info';
    return 'ai-badge-warning';
  };

  const scopeLabel = status => {
    if (status === 'in_scope') return 'ruled in scope';
    if (status === 'out_of_scope') return 'confirmed off-topic';
    if (status === 'draft_ready') return 'answer drafted';
    return 'needs review';
  };

  const openQuestionModal = (mode, item) => {
    setQuestionModal({ ...item, mode });
    setQuestionDraft(item.question);
  };

  return (
    <div>
      <div style={{ display: 'flex', gap: 8, marginBottom: 20, flexWrap: 'wrap' }}>
        <button className={`ai-btn ai-btn-sm ${subTab === 'gaps' ? 'ai-btn-primary' : 'ai-btn-secondary'}`} onClick={() => setSubTab('gaps')}>
          Knowledge Gaps ({unanswered.length})
        </button>
        <button className={`ai-btn ai-btn-sm ${subTab === 'oos' ? 'ai-btn-primary' : 'ai-btn-secondary'}`} onClick={() => setSubTab('oos')}>
          Scope Tuning ({outOfScope.length})
        </button>
      </div>

      {accuracy && accuracy.insights && accuracy.insights.length > 0 && (
        <div className="ai-accuracy-panel">
          <div className="ai-accuracy-head">
            <strong>Draft Accuracy</strong>
            {accuracy.stats && accuracy.stats.mean_ai_similarity != null && (
              <span className="ai-badge ai-badge-neutral">
                {Math.round(accuracy.stats.mean_ai_similarity * 100)}% text kept from AI drafts
              </span>
            )}
            {editedCount > 0 && (
              <span className="ai-badge ai-badge-info">{editedCount} corrected by hand</span>
            )}
          </div>
          <ul className="ai-accuracy-list">
            {accuracy.insights.map((insight, idx) => (
              <li key={idx} className={`ai-accuracy-insight ai-insight-${insight.tone}`}>{insight.text}</li>
            ))}
          </ul>
        </div>
      )}

      {subTab === 'gaps' ? (
        <div className="ai-card">
          <div className="ai-card-header">
            <div>
              <h3>Unanswered Questions</h3>
              <p>
                Visitor questions the chatbot could not answer. Open any draft to correct the AI text, then approve it to
                publish a curated Q&amp;A entry the chatbot will prefer at retrieval time.
              </p>
            </div>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', justifyContent: 'flex-end' }}>
              <StatusFilter
                options={gapFilters}
                value={statusFilter}
                onChange={setStatusFilter}
                ariaLabel="Filter unanswered questions by status"
              />
              {unanswered.length > 0 && (
                <button
                  className="ai-btn ai-btn-danger ai-btn-sm"
                  onClick={deleteAllQuestions}
                  title="Permanently delete every logged question"
                >
                  Delete All ({unanswered.length})
                </button>
              )}
            </div>
          </div>

          <table className="ai-table">
            <thead>
              <tr>
                <th>Visitor Question</th>
                <th>Frequency</th>
                <th>Status</th>
                <th>Drafted Answer</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {visibleGaps.map(item => (
                <tr key={item.id} className={item.status === 'approved' ? 'ai-row-done' : ''}>
                  <td><strong>{item.question}</strong></td>
                  <td><span className="ai-badge ai-badge-neutral">{item.occurrences}x</span></td>
                  <td>
                    <span className={`ai-badge ${statusBadge(item.status)}`}>{item.status}</span>
                  </td>
                  <td style={{ maxWidth: 320 }}>
                    {item.draft_answer ? (
                      <>
                        {item.draft_source === 'human' ? (
                          <span className="ai-inline-flag ai-inline-flag-edited">Edited by you{item.edit_count > 1 ? ` · ${item.edit_count}x` : ''}</span>
                        ) : (
                          <span className="ai-inline-flag">AI draft</span>
                        )}
                        <div className="ai-cell-preview">{item.draft_answer}</div>
                      </>
                    ) : (
                      <em>Needs your answer</em>
                    )}
                  </td>
                   <td>
                     <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                       {item.status !== 'approved' && item.status !== 'dismissed' && (
                         <>
                           <button
                             className="ai-btn ai-btn-primary ai-btn-sm"
                             onClick={() => openEditor('unanswered', item)}
                             title="Edit the AI drafted answer, compare it with the original, then approve"
                           >
                             Edit draft
                           </button>
                           <button
                             className="ai-btn ai-btn-secondary ai-btn-sm"
                             onClick={() => openQuestionModal('unanswered', item)}
                             title="Correct what the visitor actually asked"
                           >
                             Edit question
                           </button>
                           <button className="ai-btn ai-btn-secondary ai-btn-sm" onClick={() => dismissQuestion(item.id)}>Dismiss</button>
                         </>
                       )}
                       {item.status === 'approved' && (
                         <>
                           <button
                             className="ai-btn ai-btn-secondary ai-btn-sm"
                             onClick={() => openEditor('unanswered', item)}
                             title="Edit the approved answer (will replace the published Q&A)"
                           >
                             Edit draft
                           </button>
                           <button
                             className="ai-btn ai-btn-secondary ai-btn-sm"
                             onClick={() => openQuestionModal('unanswered', item)}
                             title="Correct what the visitor actually asked"
                           >
                             Edit question
                           </button>
                         </>
                       )}
                       {item.status === 'dismissed' && (
                         <button className="ai-btn ai-btn-secondary ai-btn-sm" onClick={() => reopenRow('unanswered', item.id)}>
                           Reopen
                         </button>
                       )}
                       <button
                         className="ai-btn ai-btn-danger ai-btn-sm"
                         onClick={() => deleteQuestion(item.id, item.question)}
                         title="Permanently remove this logged question"
                       >
                         Delete
                       </button>
                     </div>
                   </td>
                 </tr>
               ))}
              {visibleGaps.length === 0 && (
                <tr>
                  <td colSpan="5" style={{ textAlign: 'center', padding: 24, color: '#94a3b8' }}>
                    {unanswered.length === 0
                      ? 'No knowledge gaps logged yet. Your chatbot is answering all inquiries confidently!'
                      : 'No questions match this status filter.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="ai-card">
          <div className="ai-card-header">
            <div>
              <h3>Scope Tuning</h3>
              <p>
                Visitor questions deflected by topic guardrails (anonymized, no PII). Draft an answer and rule a question
                in scope to publish it, or confirm it is genuinely off-topic.
              </p>
            </div>
            <StatusFilter
              options={scopeFilters}
              value={scopeStatusFilter}
              onChange={setScopeStatusFilter}
              ariaLabel="Filter scope tuning by ruling"
            />
          </div>

          <table className="ai-table">
            <thead>
              <tr>
                <th>Question</th>
                <th>Times Asked</th>
                <th>Last Asked</th>
                <th>Ruling</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {visibleScope.map(o => (
                <tr key={o.id} className={o.status === 'in_scope' || o.status === 'out_of_scope' ? 'ai-row-done' : ''}>
                  <td><strong>{o.question}</strong></td>
                  <td><span className="ai-badge ai-badge-neutral">{o.occurrences}x</span></td>
                  <td>{new Date(o.last_asked_at.replace(' ', 'T')).toLocaleDateString()}</td>
                  <td>
                    <span className={`ai-badge ${scopeBadge(o.status)}`}>{scopeLabel(o.status)}</span>
                    {o.draft_source === 'human' && (
                      <div className="ai-inline-flag ai-inline-flag-edited">Edited by you</div>
                    )}
                  </td>
                  <td>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      {o.status !== 'in_scope' && o.status !== 'out_of_scope' && (
                        <>
                          <button
                            className="ai-btn ai-btn-primary ai-btn-sm"
                            onClick={() => openEditor('out_of_scope', o)}
                            title="Draft or correct an answer, then rule the question in scope"
                          >
                            Edit draft
                          </button>
                          <button
                            className="ai-btn ai-btn-secondary ai-btn-sm"
                            onClick={() => openQuestionModal('out_of_scope', o)}
                            title="Correct the wording of this deflected question"
                          >
                            Edit question
                          </button>
                          <button
                             className="ai-btn ai-btn-secondary ai-btn-sm"
                             onClick={() => ruleOutOfScope(o.id)}
                             title="The chatbot is right to deflect this question"
                           >
                             Confirmed off-topic
                           </button>
                         </>
                       )}
                       {(o.status === 'in_scope' || o.status === 'out_of_scope') && (
                         <>
                           <button
                             className="ai-btn ai-btn-secondary ai-btn-sm"
                             onClick={() => openEditor('out_of_scope', o)}
                             title="Edit the published ruling (will replace the published answer)"
                           >
                             Edit draft
                           </button>
                           <button className="ai-btn ai-btn-secondary ai-btn-sm" onClick={() => reopenRow('out_of_scope', o.id)}>
                             Reopen
                           </button>
                         </>
                       )}
                    </div>
                  </td>
                </tr>
              ))}
              {visibleScope.length === 0 && (
                <tr>
                  <td colSpan="5" style={{ textAlign: 'center', padding: 24, color: '#94a3b8' }}>
                    {outOfScope.length === 0
                      ? 'No off-topic questions recorded.'
                      : 'No entries match this status filter.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      <DraftEditorModal
        mode={editor ? editor.mode : 'unanswered'}
        item={editor ? editor.item : null}
        answer={editorAnswer}
        onChange={setEditorAnswer}
        onClose={() => setEditor(null)}
        onSave={saveEditorDraft}
        onRegenerate={regenerateEditorDraft}
        onRevert={revertEditorDraft}
        onRule={ruleOutOfScope}
        onApprove={approveEditorDraft}
        saving={editorSaving}
        regenerating={editorRegenerating}
      />

      {/* Reword Question Modal */}
      {questionModal && (
        <div className="ai-admin-modal-backdrop">
          <div className="ai-admin-modal">
            <div className="ai-modal-head">
              <h3>Edit Logged Question</h3>
              <button
                style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 18 }}
                onClick={() => setQuestionModal(null)}
                aria-label="Close"
              >
                ✕
              </button>
            </div>
            <div className="ai-modal-body">
              <div className="ai-form-row">
                <label htmlFor="ai-question-reword">Question as it should read</label>
                <textarea
                  id="ai-question-reword"
                  rows="3"
                  value={questionDraft}
                  autoFocus
                  onChange={e => setQuestionDraft(e.target.value)}
                />
              </div>
              <p className="ai-hint-text">
                Asked {questionModal.occurrences} time(s). Saving recomputes the duplicate-detection
                hash and clears the AI draft, because that draft was written for the current wording.
              </p>
            </div>
            <div className="ai-modal-foot">
              <button className="ai-btn ai-btn-secondary" onClick={() => setQuestionModal(null)}>Cancel</button>
              <button
                className="ai-btn ai-btn-primary"
                onClick={saveQuestionText}
                disabled={questionSaving || !questionDraft.trim()}
              >
                {questionSaving ? 'Saving...' : 'Save question'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// Mount React Root
document.addEventListener('DOMContentLoaded', () => {
  const mount = document.getElementById('ai-chatbot-admin-root');
  if (mount) {
    render(createElement(App), mount);
  }
  const wizardMount = document.getElementById('ai-chatbot-wizard-root');
  if (wizardMount) {
    render(createElement(SetupWizardApp), wizardMount);
  }
});

