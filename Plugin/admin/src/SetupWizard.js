/**
 * AI Chatbot Advanced 2-Minute Onboarding & Setup Wizard
 *
 * Professional, beginner-friendly, tutorial-style setup wizard.
 * Gates the plugin until completed and securely persists progress.
 */

import { createElement, useState, useEffect, useRef } from '@wordpress/element';

const AdminData = window.aiChatbotAdminData || {
  apiUrl: '/wp-json/ai-chatbot/v1',
  nonce: '',
  agentId: 1,
  siteName: 'AI Chatbot',
  homeUrl: 'https://example.com',
  adminEmail: 'admin@example.com',
  pluginVersion: '1.10.6',
  onboardingStatus: 'not_started',
  onboardingState: {},
  isSetupPage: true,
  setupWizardUrl: '',
  dashboardUrl: ''
};

// Supported languages mirroring Settings::LANGUAGE_LABELS
const LANGUAGE_CHOICES = [
  { code: 'auto', label: 'Auto-detect visitor language (Recommended)' },
  { code: 'en', label: 'English' },
  { code: 'de', label: 'German (Deutsch)' },
  { code: 'es', label: 'Spanish (Español)' },
  { code: 'fr', label: 'French (Français)' },
  { code: 'it', label: 'Italian (Italiano)' },
  { code: 'pt', label: 'Portuguese (Português)' },
  { code: 'hi', label: 'Hindi (हिन्दी)' },
  { code: 'ne', label: 'Nepali (नेपाली)' }
];

// Color palette presets
const COLOR_PRESETS = [
  { label: 'Modern Blue', hex: '#2563eb' },
  { label: 'Emerald Green', hex: '#059669' },
  { label: 'Deep Violet', hex: '#7c3aed' },
  { label: 'Rose Crimson', hex: '#e11d48' },
  { label: 'Midnight Slate', hex: '#0f172a' }
];

// Launcher icon options
const ICON_OPTIONS = [
  { id: 'chat', label: 'Chat Bubble', icon: '💬' },
  { id: 'bot', label: 'AI Robot', icon: '🤖' },
  { id: 'sparkles', label: 'Sparkles', icon: '✨' },
  { id: 'help', label: 'Help Desk', icon: '💁' }
];

// Helper to make authenticated REST calls
async function wizardApi(endpoint, method = 'GET', data = null) {
  const url = `${AdminData.apiUrl}${endpoint}`;
  const options = {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': AdminData.nonce
    }
  };
  if (data && method !== 'GET') {
    options.body = JSON.stringify(data);
  }
  const res = await fetch(url, options);
  const json = await res.json();
  return json;
}

export default function SetupWizardApp({ onFinished = null }) {
  // Wizard step (1 to 7)
  const [step, setStep] = useState(1);
  const [completedSteps, setCompletedSteps] = useState([]);
  const [isResumed, setIsResumed] = useState(false);
  const [loadingInitial, setLoadingInitial] = useState(true);

  // Form Configuration
  const [formData, setFormData] = useState({
    provider: 'groq',
    apiKey: '',
    showApiKey: false,
    embeddingsProvider: 'mistral',
    embeddingsModel: 'mistral-embed',
    embeddingsApiKey: '',
    showEmbeddingsApiKey: false,
    useSameKey: true,
    agentName: 'AI Assistant',
    greeting: 'Hi! How can I help you today?',
    tone: 'friendly',
    role: 'I am a helpful AI assistant for this website. My goal is to answer visitor questions accurately and guide them to the right resources.',
    language: 'auto',
    primaryColor: '#2563eb',
    launcherIcon: 'chat',
    position: 'bottom_right',
    windowWidth: 380,
    borderRadius: 16,
    answerLength: 'medium',
    strictness: 'strict',
    allowedTopics: 'Our services, products, pricing, hours, contact information, and support.',
    declineMessage: 'I can only help with questions about this website. How can I assist you with our services?',
    notifyLead: true
  });

  // UI States
  const [detected, setDetected] = useState({});
  const [testingKey, setTestingKey] = useState(false);
  const [keyTestResult, setKeyTestResult] = useState(null); // { valid: bool, message: string }
  const [testingEmbeddingsKey, setTestingEmbeddingsKey] = useState(false);
  const [embeddingsKeyTestResult, setEmbeddingsKeyTestResult] = useState(null); // { valid: bool, message: string }
  const [scanning, setScanning] = useState(false);
  const [scanResult, setScanResult] = useState(null); // { count, chunks, message }
  const [savingStep, setSavingStep] = useState(false);
  const [stepError, setStepError] = useState('');
  const [showAdvancedIdentity, setShowAdvancedIdentity] = useState(false);
  const [showAdvancedAppearance, setShowAdvancedAppearance] = useState(false);

  // Step 7 Test Chat States
  const [testQuestion, setTestQuestion] = useState('What can you help visitors with?');
  const [chatMessages, setChatMessages] = useState([
    { role: 'assistant', content: 'Hi! How can I help you today?' }
  ]);
  const [chatLoading, setChatLoading] = useState(false);

  // Completion state
  const [isComplete, setIsComplete] = useState(false);
  const [launching, setLaunching] = useState(false);
  const chatBottomRef = useRef(null);

  // Load existing state on mount
  useEffect(() => {
    async function loadState() {
      try {
        const res = await wizardApi('/wizard/state');
        if (res.success && res.data) {
          const d = res.data;
          setDetected(d.detected || {});

          // Merge saved config if present
          const saved = d.saved_state || {};
          const cfg = saved.config || {};

          const resolvedEmbProvider = cfg.embeddingsProvider || d.detected?.embeddings_provider || 'mistral';
          const resolvedEmbModel = cfg.embeddingsModel || d.detected?.embeddings_model || (resolvedEmbProvider === 'openai' ? 'text-embedding-3-small' : 'mistral-embed');

          setFormData((prev) => ({
            ...prev,
            ...cfg,
            agentName: cfg.agentName || d.detected?.agent?.name || prev.agentName,
            greeting: cfg.greeting || d.detected?.agent?.greeting || prev.greeting,
            tone: cfg.tone || d.detected?.agent?.tone || prev.tone,
            role: cfg.role || d.detected?.agent?.role || prev.role,
            provider: cfg.provider || d.detected?.active_provider || prev.provider,
            embeddingsProvider: resolvedEmbProvider,
            embeddingsModel: resolvedEmbModel,
            primaryColor: cfg.primaryColor || d.detected?.settings?.theme?.primary_color || prev.primaryColor
          }));

          if (saved.completed_steps && saved.completed_steps.length > 0) {
            setCompletedSteps(saved.completed_steps);
          }

          // Restore step if returning mid-wizard
          if (saved.current_step && saved.current_step > 1 && d.status !== 'completed') {
            setStep(saved.current_step);
            setIsResumed(true);
          }
        }
      } catch (err) {
        console.warn('Could not load wizard state from server', err);
      } finally {
        setLoadingInitial(false);
      }
    }
    loadState();
  }, []);

  // Update form field
  const updateField = (key, val) => {
    setFormData((prev) => ({ ...prev, [key]: val }));
    setStepError('');
  };

  // Scroll chat preview to bottom on new message
  useEffect(() => {
    if (chatBottomRef.current) {
      chatBottomRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [chatMessages, chatLoading]);

  // Test AI Key in Step 2
  const handleTestConnection = async () => {
    const rawKey = formData.apiKey.trim();
    if (!rawKey && !detected[`${formData.provider}_configured`]) {
      setStepError('Please enter an API key to test the connection.');
      return;
    }

    setTestingKey(true);
    setKeyTestResult(null);
    setStepError('');

    try {
      const res = await wizardApi('/keys/test', 'POST', {
        provider: formData.provider,
        key: rawKey
      });

      if (res.success) {
        setKeyTestResult({ valid: true, message: '✓ Connected successfully! Chat provider is active and ready.' });
      } else {
        setKeyTestResult({
          valid: false,
          message: res.message || 'We could not connect to your AI provider. Please check your API key and try again.'
        });
      }
    } catch (e) {
      setKeyTestResult({
        valid: false,
        message: 'Network error communicating with AI provider. Please try again.'
      });
    } finally {
      setTestingKey(false);
    }
  };

  // Test Embeddings Connection in Step 2
  const handleTestEmbeddingsConnection = async () => {
    const isSameProvider = formData.provider === formData.embeddingsProvider;
    const canReuse = isSameProvider && formData.useSameKey;
    const rawKey = canReuse ? formData.apiKey.trim() : formData.embeddingsApiKey.trim();
    const hasDetected = detected[`${formData.embeddingsProvider}_configured`];

    if (!rawKey && !hasDetected) {
      setStepError(`Please enter an API key for ${formData.embeddingsProvider === 'mistral' ? 'Mistral AI' : 'OpenAI'} to test the embeddings connection.`);
      return;
    }

    setTestingEmbeddingsKey(true);
    setEmbeddingsKeyTestResult(null);
    setStepError('');

    try {
      const res = await wizardApi('/wizard/validate-key', 'POST', {
        provider: formData.embeddingsProvider,
        api_key: rawKey
      });

      if (res.success) {
        setEmbeddingsKeyTestResult({ valid: true, message: '✓ Embeddings connection verified! Ready for semantic knowledge search.' });
      } else {
        setEmbeddingsKeyTestResult({
          valid: false,
          message: res.message || 'Could not connect to the embeddings provider. Please check your API key and try again.'
        });
      }
    } catch (e) {
      setEmbeddingsKeyTestResult({
        valid: false,
        message: 'Network error communicating with embeddings provider. Please try again.'
      });
    } finally {
      setTestingEmbeddingsKey(false);
    }
  };

  // Step 4: Scan Website Knowledge
  const handleScanWebsite = async () => {
    setScanning(true);
    setScanResult(null);
    setStepError('');

    try {
      const res = await wizardApi('/wizard/scan-knowledge', 'POST', { scope: 'site' });
      if (res.success && res.data) {
        setScanResult(res.data);
      } else {
        setStepError(res.message || 'Website scan encountered an issue. You can continue and configure knowledge later.');
      }
    } catch (e) {
      setStepError('Could not connect to scanner. You can configure knowledge later from the Knowledge menu.');
    } finally {
      setScanning(false);
    }
  };

  // Step 7: Send Live Test Chat Question
  const handleSendTestChat = async (questionText = null) => {
    const q = (questionText || testQuestion).trim();
    if (!q || chatLoading) return;

    const userMsg = { role: 'user', content: q };
    setChatMessages((prev) => [...prev, userMsg]);
    setTestQuestion('');
    setChatLoading(true);

    try {
      const res = await wizardApi('/wizard/test-chat', 'POST', {
        message: q,
        apiKey: formData.apiKey.trim()
      });

      if (res.success && res.data?.reply) {
        setChatMessages((prev) => [...prev, { role: 'assistant', content: res.data.reply }]);
      } else {
        setChatMessages((prev) => [
          ...prev,
          { role: 'assistant', content: res.message || 'Unable to generate reply. Please verify your AI key in Step 2.' }
        ]);
      }
    } catch (e) {
      setChatMessages((prev) => [
        ...prev,
        { role: 'assistant', content: 'Could not connect to the chat endpoint. Please check your network or try again.' }
      ]);
    } finally {
      setChatLoading(false);
    }
  };

  // Validate step before advancing
  const validateCurrentStep = () => {
    if (step === 2) {
      const hasChatDetected = detected[`${formData.provider}_configured`];
      if (!formData.apiKey.trim() && !hasChatDetected) {
        setStepError('An API key is required to connect your Chat AI provider. Please enter your key to continue.');
        return false;
      }

      const isSameProvider = formData.provider === formData.embeddingsProvider;
      const canReuse = isSameProvider && formData.useSameKey;
      const effectiveEmbKey = canReuse ? formData.apiKey.trim() : formData.embeddingsApiKey.trim();
      const hasEmbDetected = detected[`${formData.embeddingsProvider}_configured`];

      if (!effectiveEmbKey && !hasEmbDetected) {
        setStepError(
          `An API key is required for Embeddings Provider (${formData.embeddingsProvider === 'mistral' ? 'Mistral AI' : 'OpenAI'}). Without embeddings, your chatbot cannot index website knowledge or answer visitor questions.`
        );
        return false;
      }
    }

    if (step === 3) {
      if (!formData.agentName.trim()) {
        setStepError('Please provide a name for your chatbot.');
        return false;
      }
      if (!formData.greeting.trim()) {
        setStepError('Please provide a welcome greeting for visitors.');
        return false;
      }
    }

    return true;
  };

  // Save current step and go to next
  const goToNextStep = async () => {
    if (!validateCurrentStep()) return;

    setSavingStep(true);
    setStepError('');

    try {
      await wizardApi('/wizard/save-step', 'POST', {
        step,
        config: formData,
        completed: true
      });

      if (!completedSteps.includes(step)) {
        setCompletedSteps((prev) => [...prev, step]);
      }

      setStep((s) => Math.min(7, s + 1));
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
      setStepError('Could not save progress. Please try again.');
    } finally {
      setSavingStep(false);
    }
  };

  // Back button
  const goToPrevStep = () => {
    if (step > 1) {
      setStepError('');
      setStep((s) => s - 1);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  // Complete & Launch
  const handleLaunchChatbot = async () => {
    if (!validateCurrentStep()) return;

    setLaunching(true);
    setStepError('');

    try {
      const res = await wizardApi('/wizard/complete', 'POST', { config: formData });
      if (res.success) {
        setIsComplete(true);
        if (onFinished) {
          onFinished();
        }
      } else {
        setStepError(res.message || 'Failed to complete setup. Please check your settings.');
      }
    } catch (e) {
      setStepError('Server error completing setup. Please try again.');
    } finally {
      setLaunching(false);
    }
  };

  if (loadingInitial) {
    return (
      <div className="ai-wizard-loading-screen">
        <div className="ai-wizard-spinner"></div>
        <p>Loading your AI Chatbot setup...</p>
      </div>
    );
  }

  // Celebration view upon completion
  if (isComplete) {
    return (
      <div className="ai-wizard-full-wrap">
        <div className="ai-wizard-container">
          <div className="ai-wizard-card ai-wizard-celebration-card">
            <div className="ai-wizard-celebration-icon">🎉</div>
            <h1 className="ai-wizard-celebration-title">Your AI Chatbot is Ready!</h1>
            <p className="ai-wizard-celebration-desc">
              Congratulations! Your chatbot has been configured successfully and is now active on your website. Visitors can start chatting with your AI assistant right away.
            </p>

            <div className="ai-wizard-summary-box">
              <div className="ai-wizard-summary-item">
                <span className="ai-summary-icon">✓</span>
                <div>
                  <strong>AI Chat Provider:</strong> {formData.provider === 'groq' ? 'Groq (Ultra-fast Llama 3.3)' : formData.provider === 'openai' ? 'OpenAI (GPT-4o mini)' : 'Mistral AI'}
                </div>
              </div>
              <div className="ai-wizard-summary-item">
                <span className="ai-summary-icon">✓</span>
                <div>
                  <strong>Knowledge Search:</strong> {formData.embeddingsProvider === 'mistral' ? 'Mistral AI (mistral-embed)' : 'OpenAI (text-embedding-3)'}
                </div>
              </div>
              <div className="ai-wizard-summary-item">
                <span className="ai-summary-icon">✓</span>
                <div>
                  <strong>Assistant Identity:</strong> {formData.agentName} ({formData.tone} tone)
                </div>
              </div>
              <div className="ai-wizard-summary-item">
                <span className="ai-summary-icon">✓</span>
                <div>
                  <strong>Primary Color:</strong> <span className="ai-color-dot" style={{ backgroundColor: formData.primaryColor }}></span> {formData.primaryColor}
                </div>
              </div>
              <div className="ai-wizard-summary-item">
                <span className="ai-summary-icon">✓</span>
                <div>
                  <strong>Chatbot Status:</strong> <span className="ai-status-online">● Active & Live</span>
                </div>
              </div>
            </div>

            <div className="ai-wizard-celebration-actions">
              <a
                href={AdminData.dashboardUrl || 'admin.php?page=ai-chatbot'}
                className="ai-wizard-btn ai-wizard-btn-primary ai-wizard-btn-lg"
              >
                Go to Dashboard →
              </a>
            </div>
          </div>
        </div>
      </div>
    );
  }

  const progressPercent = Math.round((step / 7) * 100);

  return (
    <div className="ai-wizard-full-wrap">
      {/* Wizard Header */}
      <header className="ai-wizard-header">
        <div className="ai-wizard-header-inner">
          <div className="ai-wizard-branding">
            <div className="ai-wizard-logo-icon">🤖</div>
            <div>
              <div className="ai-wizard-title-row">
                <span className="ai-wizard-brand-name">AI Chatbot</span>
                <span className="ai-wizard-badge">Setup Wizard</span>
              </div>
              <p className="ai-wizard-tagline">Let's get your AI chatbot ready in about 2 minutes.</p>
            </div>
          </div>

          <div className="ai-wizard-header-right">
            <span className="ai-wizard-step-label">Step {step} of 7</span>
            {AdminData.wizardCompleted ? (
              <a href={AdminData.dashboardUrl || 'admin.php?page=ai-chatbot'} className="ai-wizard-exit-link">
                Return to Dashboard ✕
              </a>
            ) : (
              <a href="index.php" className="ai-wizard-exit-link" title="Setup remains required to unlock chatbot dashboard">
                Exit to WordPress ✕
              </a>
            )}
          </div>
        </div>

        {/* Visual Progress Trail */}
        <div className="ai-wizard-progress-bar-wrap">
          <div className="ai-wizard-progress-bar-fill" style={{ width: `${progressPercent}%` }}></div>
        </div>

        <div className="ai-wizard-steps-track">
          {[
            { n: 1, label: 'Welcome' },
            { n: 2, label: 'Connect AI' },
            { n: 3, label: 'Identity' },
            { n: 4, label: 'Knowledge' },
            { n: 5, label: 'Appearance' },
            { n: 6, label: 'Behavior' },
            { n: 7, label: 'Test & Launch' }
          ].map((s) => {
            const isDone = completedSteps.includes(s.n) || step > s.n;
            const isCurrent = step === s.n;
            return (
              <div
                key={s.n}
                className={`ai-wizard-step-node ${isCurrent ? 'active' : ''} ${isDone ? 'done' : ''}`}
              >
                <div className="ai-wizard-step-circle">
                  {isDone ? '✓' : s.n}
                </div>
                <span className="ai-wizard-step-text">{s.label}</span>
              </div>
            );
          })}
        </div>
      </header>

      {/* Main Container */}
      <main className="ai-wizard-container">
        {isResumed && (
          <div className="ai-wizard-resume-banner">
            👋 Welcome back! Let's continue where you left off at Step {step}.
          </div>
        )}

        {stepError && (
          <div className="ai-wizard-error-banner" role="alert">
            <span>⚠️ {stepError}</span>
            <button type="button" className="ai-wizard-btn-inline" onClick={() => setStepError('')}>✕</button>
          </div>
        )}

        {/* STEP 1: WELCOME */}
        {step === 1 && (
          <div className="ai-wizard-card">
            <div className="ai-wizard-hero">
              <span className="ai-wizard-hero-icon">👋</span>
              <h2>Welcome to AI Chatbot</h2>
              <p className="ai-wizard-hero-lead">
                Let's configure your AI chatbot in about 2 minutes. We'll guide you through the essential settings so your chatbot is ready to engage website visitors.
              </p>
            </div>

            <div className="ai-wizard-checklist-box">
              <h3 className="ai-checklist-title">What we'll set up together:</h3>
              <ul className="ai-checklist-items">
                <li>
                  <span className="ai-check-icon">✓</span>
                  <div>
                    <strong>AI Provider & Knowledge Search</strong>
                    <p>Connect Groq, OpenAI, or Mistral for chat, plus embeddings to search website facts.</p>
                  </div>
                </li>
                <li>
                  <span className="ai-check-icon">✓</span>
                  <div>
                    <strong>Chatbot Identity</strong>
                    <p>Give your assistant a friendly name, personality, and welcome message.</p>
                  </div>
                </li>
                <li>
                  <span className="ai-check-icon">✓</span>
                  <div>
                    <strong>Website Knowledge</strong>
                    <p>Scan your website so your chatbot answers with real facts.</p>
                  </div>
                </li>
                <li>
                  <span className="ai-check-icon">✓</span>
                  <div>
                    <strong>Appearance & Live Preview</strong>
                    <p>Match your brand colors and style with an interactive preview.</p>
                  </div>
                </li>
                <li>
                  <span className="ai-check-icon">✓</span>
                  <div>
                    <strong>Behavior & Guardrails</strong>
                    <p>Keep responses on-topic and protect your company reputation.</p>
                  </div>
                </li>
                <li>
                  <span className="ai-check-icon">✓</span>
                  <div>
                    <strong>Test & Launch</strong>
                    <p>Ask a real test question, verify responses, and launch on your site.</p>
                  </div>
                </li>
              </ul>
            </div>

            <div className="ai-wizard-tutorial-callout">
              <span className="ai-callout-badge">Beginner-Friendly</span>
              <p>Keep the recommended default settings if you're not sure. You can customize every option anytime from the dashboard.</p>
            </div>
          </div>
        )}

        {/* STEP 2: CONNECT AI */}
        {step === 2 && (
          <div className="ai-wizard-card">
            <div className="ai-wizard-card-header">
              <h2>Connect your AI</h2>
              <span className="ai-badge-required">Required</span>
            </div>

            <div className="ai-wizard-tutorial-box">
              <div className="ai-tutorial-block">
                <h4>What is this?</h4>
                <p>Your chatbot uses two AI services: a <strong>Chat Provider</strong> to converse naturally, and an <strong>Embeddings Provider</strong> to search your website knowledge.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>Why does it matter?</h4>
                <p>Without an embeddings provider, the AI cannot read or search your website facts, which prevents it from answering visitor inquiries.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>What should I choose?</h4>
                <p>Use <strong>Groq</strong> for ultra-fast chat combined with <strong>Mistral AI</strong> (free tier) or <strong>OpenAI</strong> for embeddings knowledge search.</p>
              </div>
            </div>

            {/* PART 1: CHAT PROVIDER */}
            <div className="ai-wizard-subheading-row">
              <div>
                <h3 className="ai-wizard-subheading">1. Chat Provider (Conversations)</h3>
                <p className="ai-wizard-subheading-desc">Understands visitor inquiries and generates instant conversational replies.</p>
              </div>
              <span className="ai-badge-required">Required</span>
            </div>

            {detected[`${formData.provider}_configured`] && (
              <div className="ai-wizard-detected-alert">
                <span className="ai-alert-check">✓</span>
                <div>
                  <strong>Chat connection already detected!</strong>
                  <p>You have an active API connection configured for {formData.provider}. You can use your existing connection or enter a new key below.</p>
                </div>
              </div>
            )}

            <div className="ai-form-group">
              <label className="ai-label">Choose Chat Provider</label>
              <div className="ai-provider-pill-group">
                {[
                  { id: 'groq', name: 'Groq', desc: 'Ultra-fast & free tier (Recommended)', badge: 'Recommended' },
                  { id: 'openai', name: 'OpenAI', desc: 'GPT-4o mini standard', badge: 'Popular' },
                  { id: 'mistral', name: 'Mistral', desc: 'European privacy-focused' }
                ].map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    className={`ai-provider-pill ${formData.provider === p.id ? 'active' : ''}`}
                    onClick={() => {
                      updateField('provider', p.id);
                      setKeyTestResult(null);
                    }}
                  >
                    <div className="ai-pill-top">
                      <strong>{p.name}</strong>
                      {p.badge && <span className="ai-pill-badge">{p.badge}</span>}
                    </div>
                    <span className="ai-pill-desc">{p.desc}</span>
                  </button>
                ))}
              </div>
            </div>

            <div className="ai-form-group">
              <div className="ai-label-row">
                <label className="ai-label" htmlFor="ai-api-key">
                  {formData.provider === 'groq' ? 'Groq API Key' : formData.provider === 'openai' ? 'OpenAI API Key' : 'Mistral API Key'}
                </label>
                <a
                  href={
                    formData.provider === 'groq'
                      ? 'https://console.groq.com/keys'
                      : formData.provider === 'openai'
                      ? 'https://platform.openai.com/api-keys'
                      : 'https://console.mistral.ai/api-keys'
                  }
                  target="_blank"
                  rel="noopener noreferrer"
                  className="ai-help-link"
                >
                  Get a free {formData.provider.toUpperCase()} key ↗
                </a>
              </div>

              <div className="ai-input-password-wrap">
                <input
                  id="ai-api-key"
                  type={formData.showApiKey ? 'text' : 'password'}
                  className="ai-input"
                  placeholder={
                    formData.provider === 'groq'
                      ? 'gsk_...'
                      : formData.provider === 'openai'
                      ? 'sk-...'
                      : 'Enter API key'
                  }
                  value={formData.apiKey}
                  onChange={(e) => {
                    updateField('apiKey', e.target.value);
                    setKeyTestResult(null);
                  }}
                  autoComplete="off"
                />
                <button
                  type="button"
                  className="ai-password-toggle"
                  onClick={() => updateField('showApiKey', !formData.showApiKey)}
                  title={formData.showApiKey ? 'Hide key' : 'Show key'}
                >
                  {formData.showApiKey ? '🙈' : '👁️'}
                </button>
              </div>
              <p className="ai-field-hint">Your API key is stored securely server-side with WordPress encryption and never exposed in browser HTML.</p>
            </div>

            <div className="ai-test-connection-row">
              <button
                type="button"
                className="ai-wizard-btn ai-wizard-btn-secondary"
                onClick={handleTestConnection}
                disabled={testingKey || (!formData.apiKey.trim() && !detected[`${formData.provider}_configured`])}
              >
                {testingKey ? 'Testing Connection...' : '⚡ Test Chat Connection'}
              </button>

              {keyTestResult && (
                <div className={`ai-key-test-feedback ${keyTestResult.valid ? 'success' : 'error'}`}>
                  {keyTestResult.message}
                </div>
              )}
            </div>

            {/* PART 2: EMBEDDINGS PROVIDER (KNOWLEDGE SEARCH) */}
            <div className="ai-wizard-section-divider"></div>

            <div className="ai-wizard-subheading-row">
              <div>
                <h3 className="ai-wizard-subheading">2. Embeddings Provider (Knowledge Search)</h3>
                <p className="ai-wizard-subheading-desc">Required for RAG semantic search across your website facts and documents.</p>
              </div>
              <span className="ai-badge-required">Required</span>
            </div>

            <div className="ai-callout-info-box">
              <div className="ai-callout-icon">💡</div>
              <div className="ai-callout-text">
                <strong>Why do chat and embeddings use separate providers?</strong>
                <p>
                  Groq delivers lightning-fast chat replies but does not host embedding models. An embeddings provider (Mistral AI or OpenAI) converts your site's articles into vector coordinates so the AI can retrieve accurate facts. Without embeddings, the chatbot cannot answer website questions.
                </p>
              </div>
            </div>

            {detected[`${formData.embeddingsProvider}_configured`] && (
              <div className="ai-wizard-detected-alert">
                <span className="ai-alert-check">✓</span>
                <div>
                  <strong>Embeddings connection already detected!</strong>
                  <p>You have an active API connection configured for {formData.embeddingsProvider === 'mistral' ? 'Mistral AI' : 'OpenAI'}. You can use your existing connection or update your key below.</p>
                </div>
              </div>
            )}

            <div className="ai-form-group">
              <label className="ai-label">Choose Embeddings Provider</label>
              <div className="ai-provider-pill-group ai-provider-pill-group-2">
                {[
                  { id: 'mistral', name: 'Mistral AI', desc: 'mistral-embed — Generous free tier', badge: 'Recommended' },
                  { id: 'openai', name: 'OpenAI', desc: 'text-embedding-3 — Industry standard', badge: 'Popular' }
                ].map((ep) => (
                  <button
                    key={ep.id}
                    type="button"
                    className={`ai-provider-pill ${formData.embeddingsProvider === ep.id ? 'active' : ''}`}
                    onClick={() => {
                      updateField('embeddingsProvider', ep.id);
                      updateField('embeddingsModel', ep.id === 'mistral' ? 'mistral-embed' : 'text-embedding-3-small');
                      setEmbeddingsKeyTestResult(null);
                    }}
                  >
                    <div className="ai-pill-top">
                      <strong>{ep.name}</strong>
                      {ep.badge && <span className="ai-pill-badge">{ep.badge}</span>}
                    </div>
                    <span className="ai-pill-desc">{ep.desc}</span>
                  </button>
                ))}
              </div>
            </div>

            <div className="ai-form-group">
              <label className="ai-label" htmlFor="ai-embeddings-model">Embeddings Model</label>
              <select
                id="ai-embeddings-model"
                className="ai-select"
                value={formData.embeddingsModel}
                onChange={(e) => updateField('embeddingsModel', e.target.value)}
              >
                {formData.embeddingsProvider === 'mistral' ? (
                  <>
                    <option value="mistral-embed">mistral-embed (1024 dim, multilingual - Recommended)</option>
                    <option value="codestral-embed">codestral-embed (1536 dim, for code)</option>
                  </>
                ) : (
                  <>
                    <option value="text-embedding-3-small">text-embedding-3-small (1536 dim - Recommended standard)</option>
                    <option value="text-embedding-3-large">text-embedding-3-large (Highest accuracy)</option>
                  </>
                )}
              </select>
            </div>

            {formData.provider === formData.embeddingsProvider && (
              <div className="ai-checkbox-wrap">
                <label className="ai-checkbox-label">
                  <input
                    type="checkbox"
                    checked={formData.useSameKey}
                    onChange={(e) => updateField('useSameKey', e.target.checked)}
                  />
                  <span>
                    Use the same {formData.embeddingsProvider === 'mistral' ? 'Mistral' : 'OpenAI'} API key as Chat Provider above (Recommended)
                  </span>
                </label>
              </div>
            )}

            {(formData.provider !== formData.embeddingsProvider || !formData.useSameKey) && (
              <div className="ai-form-group">
                <div className="ai-label-row">
                  <label className="ai-label" htmlFor="ai-embeddings-key">
                    {formData.embeddingsProvider === 'mistral' ? 'Mistral API Key' : 'OpenAI API Key'}
                  </label>
                  <a
                    href={
                      formData.embeddingsProvider === 'mistral'
                        ? 'https://console.mistral.ai/api-keys'
                        : 'https://platform.openai.com/api-keys'
                    }
                    target="_blank"
                    rel="noopener noreferrer"
                    className="ai-help-link"
                  >
                    Get a free {formData.embeddingsProvider === 'mistral' ? 'Mistral' : 'OpenAI'} key ↗
                  </a>
                </div>

                <div className="ai-input-password-wrap">
                  <input
                    id="ai-embeddings-key"
                    type={formData.showEmbeddingsApiKey ? 'text' : 'password'}
                    className="ai-input"
                    placeholder={
                      formData.embeddingsProvider === 'mistral'
                        ? 'Enter Mistral API key'
                        : 'sk-...'
                    }
                    value={formData.embeddingsApiKey}
                    onChange={(e) => {
                      updateField('embeddingsApiKey', e.target.value);
                      setEmbeddingsKeyTestResult(null);
                    }}
                    autoComplete="off"
                  />
                  <button
                    type="button"
                    className="ai-password-toggle"
                    onClick={() => updateField('showEmbeddingsApiKey', !formData.showEmbeddingsApiKey)}
                    title={formData.showEmbeddingsApiKey ? 'Hide key' : 'Show key'}
                  >
                    {formData.showEmbeddingsApiKey ? '🙈' : '👁️'}
                  </button>
                </div>
                <p className="ai-field-hint">Used securely to generate embeddings vectors for your website pages and search queries.</p>
              </div>
            )}

            <div className="ai-test-connection-row">
              <button
                type="button"
                className="ai-wizard-btn ai-wizard-btn-secondary"
                onClick={handleTestEmbeddingsConnection}
                disabled={
                  testingEmbeddingsKey ||
                  (!(formData.provider === formData.embeddingsProvider && formData.useSameKey ? formData.apiKey.trim() : formData.embeddingsApiKey.trim()) &&
                    !detected[`${formData.embeddingsProvider}_configured`])
                }
              >
                {testingEmbeddingsKey ? 'Testing Connection...' : '⚡ Test Embeddings Connection'}
              </button>

              {embeddingsKeyTestResult && (
                <div className={`ai-key-test-feedback ${embeddingsKeyTestResult.valid ? 'success' : 'error'}`}>
                  {embeddingsKeyTestResult.message}
                </div>
              )}
            </div>
          </div>
        )}

        {/* STEP 3: CHATBOT IDENTITY */}
        {step === 3 && (
          <div className="ai-wizard-card">
            <div className="ai-wizard-card-header">
              <h2>Give your chatbot a personality</h2>
              <span className="ai-badge-required">Required</span>
            </div>

            <div className="ai-wizard-tutorial-box">
              <div className="ai-tutorial-block">
                <h4>What is this?</h4>
                <p>Customize how your chatbot introduces itself and converses with your visitors.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>Why does it matter?</h4>
                <p>A friendly, welcoming persona builds visitor trust and represents your brand positively.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>What should I choose?</h4>
                <p>A <strong>Friendly</strong> tone works best for most sites. Keep the default welcome message if you're unsure.</p>
              </div>
            </div>

            <div className="ai-form-group">
              <label className="ai-label" htmlFor="ai-agent-name">Chatbot Name *</label>
              <input
                id="ai-agent-name"
                type="text"
                className="ai-input"
                placeholder="AI Assistant"
                value={formData.agentName}
                onChange={(e) => updateField('agentName', e.target.value)}
              />
              <p className="ai-field-hint">Displayed in the chat widget header and greeting.</p>
            </div>

            <div className="ai-form-group">
              <label className="ai-label" htmlFor="ai-greeting">Welcome Message *</label>
              <textarea
                id="ai-greeting"
                rows="2"
                className="ai-textarea"
                placeholder="Hi! How can I help you today?"
                value={formData.greeting}
                onChange={(e) => updateField('greeting', e.target.value)}
              />
              <p className="ai-field-hint">The first greeting shown when a visitor opens the chat widget.</p>
            </div>

            <div className="ai-form-grid-2">
              <div className="ai-form-group">
                <label className="ai-label" htmlFor="ai-tone">Personality / Tone</label>
                <select
                  id="ai-tone"
                  className="ai-select"
                  value={formData.tone}
                  onChange={(e) => updateField('tone', e.target.value)}
                >
                  <option value="friendly">Friendly (Warm & welcoming - Recommended)</option>
                  <option value="professional">Professional (Formal & polite)</option>
                  <option value="helpful">Helpful (Concise & direct)</option>
                  <option value="casual">Casual (Relaxed & conversational)</option>
                </select>
              </div>

              <div className="ai-form-group">
                <label className="ai-label" htmlFor="ai-language">Language</label>
                <select
                  id="ai-language"
                  className="ai-select"
                  value={formData.language}
                  onChange={(e) => updateField('language', e.target.value)}
                >
                  {LANGUAGE_CHOICES.map((l) => (
                    <option key={l.code} value={l.code}>{l.label}</option>
                  ))}
                </select>
              </div>
            </div>

            <div className="ai-collapsible-section">
              <button
                type="button"
                className="ai-collapsible-toggle"
                onClick={() => setShowAdvancedIdentity(!showAdvancedIdentity)}
              >
                <span>{showAdvancedIdentity ? '▼' : '▶'} Advanced personality settings</span>
                <span className="ai-badge-optional">Optional</span>
              </button>

              {showAdvancedIdentity && (
                <div className="ai-collapsible-body">
                  <div className="ai-form-group">
                    <label className="ai-label" htmlFor="ai-role">Assistant Role & Mission</label>
                    <textarea
                      id="ai-role"
                      rows="3"
                      className="ai-textarea"
                      value={formData.role}
                      onChange={(e) => updateField('role', e.target.value)}
                    />
                    <p className="ai-field-hint">Internal system instruction guiding how the chatbot behaves.</p>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* STEP 4: KNOWLEDGE & WEBSITE CONTENT */}
        {step === 4 && (
          <div className="ai-wizard-card">
            <div className="ai-wizard-card-header">
              <h2>What should your chatbot know?</h2>
              <span className="ai-badge-optional">Recommended</span>
            </div>

            <div className="ai-wizard-tutorial-box">
              <div className="ai-tutorial-block">
                <h4>What is this?</h4>
                <p>Train your chatbot using your website's published content so it can answer questions with real facts about your company, products, and services.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>Why does it matter?</h4>
                <p>Grounding the AI in your real website information prevents hallucinations and provides accurate customer support.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>What should I choose?</h4>
                <p>Click <strong>Build Knowledge Base</strong> to scan your website's published content now.</p>
              </div>
            </div>

            <div className="ai-knowledge-action-card">
              <div className="ai-knowledge-card-icon">📚</div>
              <div className="ai-knowledge-card-info">
                <h3>Build Knowledge Base from Website Content</h3>
                <p>Automatically scans published WordPress pages and posts on <code>{AdminData.siteName}</code> to create instant Q&A knowledge.</p>
                <div className="ai-knowledge-embeddings-note">
                  <span>⚡ Semantic Vector Search:</span>
                  <strong>{formData.embeddingsProvider === 'mistral' ? 'Mistral AI (mistral-embed)' : 'OpenAI (text-embedding-3)'}</strong>
                </div>

                <div className="ai-knowledge-btn-row">
                  <button
                    type="button"
                    className="ai-wizard-btn ai-wizard-btn-primary"
                    onClick={handleScanWebsite}
                    disabled={scanning}
                  >
                    {scanning ? 'Building Knowledge Base...' : '🔍 Scan Website & Build Knowledge Base'}
                  </button>
                  <span className="ai-safe-skip" onClick={goToNextStep} role="button" tabIndex={0}>
                    I'll configure knowledge later →
                  </span>
                </div>
              </div>
            </div>

            {scanning && (
              <div className="ai-scan-progress-box">
                <div className="ai-wizard-spinner"></div>
                <p>Scanning website pages and generating knowledge chunks...</p>
              </div>
            )}

            {scanResult && (
              <div className="ai-scan-result-box">
                <div className="ai-scan-result-header">
                  <span className="ai-check-icon">✓</span>
                  <strong>{scanResult.message}</strong>
                </div>

                {scanResult.sources && scanResult.sources.length > 0 && (
                  <div className="ai-scanned-pages-list">
                    <h4>Indexed Pages:</h4>
                    <ul>
                      {scanResult.sources.map((src) => (
                        <li key={src.id}>
                          <span className="ai-source-title">{src.title}</span>
                          <span className="ai-source-chunks">{src.chunks} chunk(s)</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            )}
          </div>
        )}

        {/* STEP 5: APPEARANCE WITH LIVE PREVIEW */}
        {step === 5 && (
          <div className="ai-wizard-card">
            <div className="ai-wizard-card-header">
              <h2>Make it match your website</h2>
              <span className="ai-badge-optional">Customizable</span>
            </div>

            <div className="ai-wizard-tutorial-box">
              <div className="ai-tutorial-block">
                <h4>What is this?</h4>
                <p>Choose the colors and placement for the chat widget on your website.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>Why does it matter?</h4>
                <p>Matching your brand colors makes the widget feel natural, inviting, and professional.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>Live Preview</h4>
                <p>Watch the preview widget on the right update instantly as you change settings.</p>
              </div>
            </div>

            <div className="ai-appearance-layout">
              {/* Controls Column */}
              <div className="ai-appearance-controls">
                <div className="ai-form-group">
                  <label className="ai-label">Brand Color</label>
                  <div className="ai-color-preset-group">
                    {COLOR_PRESETS.map((c) => (
                      <button
                        key={c.hex}
                        type="button"
                        className={`ai-color-preset-btn ${formData.primaryColor === c.hex ? 'active' : ''}`}
                        style={{ backgroundColor: c.hex }}
                        onClick={() => updateField('primaryColor', c.hex)}
                        title={c.label}
                      >
                        {formData.primaryColor === c.hex && '✓'}
                      </button>
                    ))}

                    <div className="ai-custom-color-picker">
                      <input
                        type="color"
                        value={formData.primaryColor}
                        onChange={(e) => updateField('primaryColor', e.target.value)}
                        title="Pick custom color"
                      />
                    </div>
                  </div>
                  <span className="ai-hex-label">{formData.primaryColor}</span>
                </div>

                <div className="ai-form-group">
                  <label className="ai-label">Launcher Icon</label>
                  <div className="ai-icon-picker-group">
                    {ICON_OPTIONS.map((ico) => (
                      <button
                        key={ico.id}
                        type="button"
                        className={`ai-icon-btn ${formData.launcherIcon === ico.id ? 'active' : ''}`}
                        onClick={() => updateField('launcherIcon', ico.id)}
                      >
                        <span className="ai-icon-emoji">{ico.icon}</span>
                        <span className="ai-icon-name">{ico.label}</span>
                      </button>
                    ))}
                  </div>
                </div>

                <div className="ai-form-group">
                  <label className="ai-label">Widget Position</label>
                  <div className="ai-position-toggle">
                    <button
                      type="button"
                      className={`ai-pos-btn ${formData.position === 'bottom_right' ? 'active' : ''}`}
                      onClick={() => updateField('position', 'bottom_right')}
                    >
                      Bottom Right (Recommended)
                    </button>
                    <button
                      type="button"
                      className={`ai-pos-btn ${formData.position === 'bottom_left' ? 'active' : ''}`}
                      onClick={() => updateField('position', 'bottom_left')}
                    >
                      Bottom Left
                    </button>
                  </div>
                </div>

                <div className="ai-collapsible-section">
                  <button
                    type="button"
                    className="ai-collapsible-toggle"
                    onClick={() => setShowAdvancedAppearance(!showAdvancedAppearance)}
                  >
                    <span>{showAdvancedAppearance ? '▼' : '▶'} Advanced appearance settings</span>
                  </button>

                  {showAdvancedAppearance && (
                    <div className="ai-collapsible-body">
                      <div className="ai-form-group">
                        <label className="ai-label">Border Radius ({formData.borderRadius}px)</label>
                        <input
                          type="range"
                          min="4"
                          max="28"
                          value={formData.borderRadius}
                          onChange={(e) => updateField('borderRadius', parseInt(e.target.value, 10))}
                        />
                      </div>
                    </div>
                  )}
                </div>
              </div>

              {/* LIVE PREVIEW COLUMN */}
              <div className="ai-appearance-preview-column">
                <div className="ai-preview-card-header">
                  <span>LIVE PREVIEW</span>
                  <span className="ai-preview-chip">Simulated Widget</span>
                </div>

                <div className="ai-live-preview-box">
                  {/* Mock Chat Panel */}
                  <div
                    className="ai-mock-chat-window"
                    style={{ borderRadius: `${formData.borderRadius}px` }}
                  >
                    {/* Header */}
                    <div
                      className="ai-mock-header"
                      style={{ backgroundColor: formData.primaryColor }}
                    >
                      <div className="ai-mock-avatar">🤖</div>
                      <div className="ai-mock-title-area">
                        <span className="ai-mock-bot-name">{formData.agentName || 'AI Assistant'}</span>
                        <span className="ai-mock-status">● Online</span>
                      </div>
                      <span className="ai-mock-close">✕</span>
                    </div>

                    {/* Messages Body */}
                    <div className="ai-mock-body">
                      <div className="ai-mock-msg ai-mock-msg-bot">
                        <div className="ai-mock-bubble ai-mock-bubble-bot">
                          {formData.greeting || 'Hi! How can I help you today?'}
                        </div>
                      </div>
                      <div className="ai-mock-msg ai-mock-msg-user">
                        <div
                          className="ai-mock-bubble ai-mock-bubble-user"
                          style={{ backgroundColor: formData.primaryColor }}
                        >
                          What are your business hours?
                        </div>
                      </div>
                      <div className="ai-mock-msg ai-mock-msg-bot">
                        <div className="ai-mock-bubble ai-mock-bubble-bot">
                          We're open Monday through Friday from 9 AM to 5 PM! Feel free to ask any other questions.
                        </div>
                      </div>
                    </div>

                    {/* Input Area */}
                    <div className="ai-mock-footer">
                      <input type="text" placeholder="Type a message..." disabled className="ai-mock-input" />
                      <button
                        type="button"
                        className="ai-mock-send"
                        style={{ backgroundColor: formData.primaryColor }}
                      >
                        ➤
                      </button>
                    </div>
                  </div>

                  {/* Mock Launcher Button */}
                  <div className={`ai-mock-launcher-row pos-${formData.position}`}>
                    <div
                      className="ai-mock-launcher"
                      style={{ backgroundColor: formData.primaryColor }}
                    >
                      <span>
                        {formData.launcherIcon === 'chat'
                          ? '💬'
                          : formData.launcherIcon === 'bot'
                          ? '🤖'
                          : formData.launcherIcon === 'sparkles'
                          ? '✨'
                          : '💁'}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* STEP 6: BEHAVIOR & GUARDRAILS */}
        {step === 6 && (
          <div className="ai-wizard-card">
            <div className="ai-wizard-card-header">
              <h2>Choose how your chatbot behaves</h2>
              <span className="ai-badge-optional">Recommended</span>
            </div>

            <div className="ai-wizard-tutorial-box">
              <div className="ai-tutorial-block">
                <h4>What is this?</h4>
                <p>Guardrails instruct your assistant on what questions it should answer and how to gracefully deflect off-topic questions.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>Why does it matter?</h4>
                <p>Keeps conversations strictly focused on your website, prevents misinformation, and protects your brand.</p>
              </div>
              <div className="ai-tutorial-block">
                <h4>Recommended Defaults</h4>
                <p>We've pre-configured safe industry defaults so your chatbot stays professional and truthful.</p>
              </div>
            </div>

            <div className="ai-form-group">
              <label className="ai-label">Response Style & Length</label>
              <div className="ai-length-selector">
                {[
                  { id: 'short', title: 'Concise', desc: 'Short, direct answers for quick support' },
                  { id: 'medium', title: 'Medium (Recommended)', desc: 'Balanced, conversational, and helpful' },
                  { id: 'long', title: 'Detailed', desc: 'Comprehensive explanations with in-depth context' }
                ].map((l) => (
                  <button
                    key={l.id}
                    type="button"
                    className={`ai-length-card ${formData.answerLength === l.id ? 'active' : ''}`}
                    onClick={() => updateField('answerLength', l.id)}
                  >
                    <strong>{l.title}</strong>
                    <span>{l.desc}</span>
                  </button>
                ))}
              </div>
            </div>

            <div className="ai-form-group">
              <label className="ai-label">Topic Strictness</label>
              <div className="ai-strictness-toggle">
                <button
                  type="button"
                  className={`ai-strict-btn ${formData.strictness === 'strict' ? 'active' : ''}`}
                  onClick={() => updateField('strictness', 'strict')}
                >
                  Strict (Only answer website topics - Recommended)
                </button>
                <button
                  type="button"
                  className={`ai-strict-btn ${formData.strictness === 'balanced' ? 'active' : ''}`}
                  onClick={() => updateField('strictness', 'balanced')}
                >
                  Balanced (Permit general conversational inquiries)
                </button>
              </div>
            </div>

            <div className="ai-form-group">
              <label className="ai-label" htmlFor="ai-decline-msg">Off-Topic Decline Message</label>
              <textarea
                id="ai-decline-msg"
                rows="2"
                className="ai-textarea"
                value={formData.declineMessage}
                onChange={(e) => updateField('declineMessage', e.target.value)}
              />
              <p className="ai-field-hint">Sent to visitors when they ask about off-topic or restricted subjects.</p>
            </div>

            <div className="ai-wizard-email-ready-box" style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 10, padding: '14px 16px', marginTop: 18 }}>
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10 }}>
                <span style={{ color: '#16a34a', fontSize: 18, lineHeight: 1 }}>✓</span>
                <div>
                  <strong style={{ color: '#15803d', display: 'block', fontSize: 14, marginBottom: 2 }}>
                    Email notifications are ready.
                  </strong>
                  <p style={{ margin: 0, color: '#166534', fontSize: 13, lineHeight: 1.4 }}>
                    Notifications will be sent to your WordPress Administration Email (<code>{AdminData.adminEmail}</code>).
                  </p>
                </div>
              </div>
            </div>

            <div className="ai-form-checkbox-row" style={{ marginTop: 12 }}>
              <input
                id="ai-notify-lead"
                type="checkbox"
                checked={formData.notifyLead !== false}
                onChange={(e) => updateField('notifyLead', e.target.checked)}
              />
              <label htmlFor="ai-notify-lead">
                <strong>Receive lead & contact notifications by email</strong>
                <p>Sends notifications directly to your WordPress Administration Email whenever visitors submit inquiries.</p>
              </label>
            </div>
          </div>
        )}

        {/* STEP 7: TEST & LAUNCH */}
        {step === 7 && (
          <div className="ai-wizard-card">
            <div className="ai-wizard-card-header">
              <h2>You're ready to go! 🎉</h2>
              <span className="ai-badge-online">Ready to Launch</span>
            </div>

            <div className="ai-launch-checklist-grid">
              <div className="ai-checklist-pill">
                <span className="ai-check-icon">✓</span>
                <span>AI Connection ({formData.provider.toUpperCase()})</span>
              </div>
              <div className="ai-checklist-pill">
                <span className="ai-check-icon">✓</span>
                <span>Identity ({formData.agentName})</span>
              </div>
              <div className="ai-checklist-pill">
                <span className="ai-check-icon">✓</span>
                <span>Appearance & Colors</span>
              </div>
              <div className="ai-checklist-pill">
                <span className="ai-check-icon">✓</span>
                <span>Guardrails & Behavior</span>
              </div>
            </div>

            {/* LIVE TEST CHAT AREA */}
            <div className="ai-test-console-card">
              <div className="ai-test-console-head">
                <div>
                  <h3>Ask a Test Question</h3>
                  <p>Send a real test inquiry to your chatbot right now using your actual configuration.</p>
                </div>
                <span className="ai-test-live-indicator">● LIVE TEST</span>
              </div>

              {/* Chat Log */}
              <div className="ai-test-chat-log">
                {chatMessages.map((m, idx) => (
                  <div key={idx} className={`ai-test-msg ai-test-msg-${m.role}`}>
                    <div
                      className="ai-test-bubble"
                      style={m.role === 'user' ? { backgroundColor: formData.primaryColor } : {}}
                    >
                      {m.content}
                    </div>
                  </div>
                ))}

                {chatLoading && (
                  <div className="ai-test-msg ai-test-msg-assistant">
                    <div className="ai-test-bubble ai-typing-bubble">
                      <span className="ai-typing-dot"></span>
                      <span className="ai-typing-dot"></span>
                      <span className="ai-typing-dot"></span>
                    </div>
                  </div>
                )}
                <div ref={chatBottomRef}></div>
              </div>

              {/* Quick suggestion chips */}
              <div className="ai-test-chips-row">
                <span>Try asking:</span>
                <button
                  type="button"
                  className="ai-test-chip"
                  onClick={() => handleSendTestChat('What can you help visitors with?')}
                >
                  "What can you help visitors with?"
                </button>
                <button
                  type="button"
                  className="ai-test-chip"
                  onClick={() => handleSendTestChat('How do I contact support?')}
                >
                  "How do I contact support?"
                </button>
              </div>

              {/* Chat Input */}
              <form
                className="ai-test-input-row"
                onSubmit={(e) => {
                  e.preventDefault();
                  handleSendTestChat();
                }}
              >
                <input
                  type="text"
                  className="ai-test-input"
                  placeholder="Type a test question here..."
                  value={testQuestion}
                  onChange={(e) => setTestQuestion(e.target.value)}
                  disabled={chatLoading}
                />
                <button
                  type="submit"
                  className="ai-wizard-btn ai-wizard-btn-primary"
                  disabled={chatLoading || !testQuestion.trim()}
                >
                  {chatLoading ? 'Thinking...' : 'Send Test ➤'}
                </button>
              </form>
            </div>

            {/* Launch Banner */}
            <div className="ai-launch-cta-box">
              <div className="ai-launch-cta-text">
                <h3>Ready to make your chatbot live?</h3>
                <p>Click Launch Chatbot to finalize settings, permanently unlock your plugin dashboard, and start greeting your visitors.</p>
              </div>
              <button
                type="button"
                className="ai-wizard-btn ai-wizard-btn-success ai-wizard-btn-xl"
                onClick={handleLaunchChatbot}
                disabled={launching}
              >
                {launching ? 'Launching Chatbot...' : '🚀 Finish & Launch Chatbot'}
              </button>
            </div>
          </div>
        )}

        {/* Wizard Footer Navigation */}
        <footer className="ai-wizard-footer">
          <button
            type="button"
            className="ai-wizard-btn ai-wizard-btn-secondary"
            onClick={goToPrevStep}
            disabled={step === 1 || savingStep || launching}
          >
            ← Back
          </button>

          <div className="ai-wizard-footer-right">
            {step < 7 ? (
              <button
                type="button"
                className="ai-wizard-btn ai-wizard-btn-primary"
                onClick={goToNextStep}
                disabled={savingStep}
              >
                {savingStep ? 'Saving...' : 'Continue →'}
              </button>
            ) : (
              <button
                type="button"
                className="ai-wizard-btn ai-wizard-btn-success"
                onClick={handleLaunchChatbot}
                disabled={launching}
              >
                {launching ? 'Launching...' : '🚀 Finish & Launch Chatbot'}
              </button>
            )}
          </div>
        </footer>
      </main>
    </div>
  );
}
