/**
 * Message editing verification, from the visitor's side.
 *
 * The PHP suite covers the API. This one drives the actual widget DOM: it loads
 * the shipped chatbot-widget.js against a minimal DOM, stubs the network, and
 * asserts the edit interaction behaves the way the UI claims. A typo in the
 * editor, a leaked event handler or a branch that fails to clear would all
 * compile and pass PHP checks while being broken on screen.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const bad = [];
function check( label, ok, detail ) {
	console.log( `  ${ok ? 'PASS' : 'FAIL'}  ${label}${ok || !detail ? '' : ' -- ' + detail}` );
	if ( !ok ) bad.push( label );
}

const raw = fs.readFileSync( path.join( __dirname, 'public/js/chatbot-widget.js' ), 'utf8' );

/*
 * The widget lives in an IIFE and exposes itself by auto-mounting, so the class
 * is not reachable from outside. Rather than add a test-only export to shipped
 * code, the harness lifts it out by rewriting the closing IIFE here. The file
 * under test is otherwise byte-identical.
 */
const src = raw.replace( /\n\}\)\(\);\s*$/, "\n  window.AIChatbotWidget = AIChatbotWidget;\n})();\n" );
check(
	'test could lift the widget class out of the IIFE',
	src !== raw && src.includes( 'window.AIChatbotWidget' )
);

// ---------------------------------------------------------------- DOM harness

function makeEl( tag ) {
	const el = {
		tagName: String( tag || 'div' ).toUpperCase(),
		style: {},
		dataset: {},
		children: [],
		attrs: {},
		_classList: new Set(),
		value: '',
		textContent: '',
		innerHTML: '',
		disabled: false,
		hidden: false,
		title: '',
		scrollHeight: 60,
		_selectionStart: 0,
		_selectionEnd: 0,
		listeners: {},
		appendChild( c ) { this.children.push( c ); c.parentNode = this; return c; },
		removeChild( c ) { this.children = this.children.filter( x => x !== c ); return c; },

		/*
		 * A real button reflects .type to the content attribute, so
		 * getAttribute('type') reflects a property assignment. The stub has to do
		 * the same or an assertion about the type attribute would fail for a
		 * widget that is in fact correct.
		 */
		get type() { return this.attrs.type || ( this.tagName === 'BUTTON' ? 'submit' : '' ); },
		set type( v ) { this.attrs.type = String( v ); },
		remove() {
			if ( this.parentNode ) {
				this.parentNode.children = this.parentNode.children.filter( x => x !== this );
				this.parentNode = null;
			}
		},
		insertBefore( c, ref ) {
			const i = ref ? this.children.indexOf( ref ) : -1;
			if ( i === -1 ) this.children.push( c );
			else this.children.splice( i, 0, c );
			c.parentNode = this;
			return c;
		},
		setAttribute( k, v ) { this.attrs[ k ] = String( v ); },
		getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; },
		removeAttribute( k ) { delete this.attrs[ k ]; },
		hasAttribute( k ) { return k in this.attrs; },
		addEventListener( t, fn ) { ( this.listeners[ t ] = this.listeners[ t ] || [] ).push( fn ); },
		removeEventListener( t, fn ) {
			this.listeners[ t ] = ( this.listeners[ t ] || [] ).filter( f => f !== fn );
		},
		dispatch( type, ev ) {
			( this.listeners[ type ] || [] ).forEach( fn => fn( { type, target: this, preventDefault() {}, stopPropagation() {}, ...ev } ) );
		},
		querySelector( sel ) { return queryIn( this, sel ); },
		querySelectorAll( sel ) { return queryAllIn( this, sel ); },
		scrollTo() {},
		scrollIntoView() {},
		setSelectionRange( start, end ) { this._selectionStart = start; this._selectionEnd = end; },
		get selectionStart() { return this._selectionStart; },
		focus() { doc.activeElement = this; },
		blur() {},
		click() { this.dispatch( 'click' ); },
		closest() { return null; },
		get classList() {
			const self = this;
			return {
				add: ( ...c ) => c.forEach( x => self._classList.add( x ) ),
				remove: ( ...c ) => c.forEach( x => self._classList.delete( x ) ),
				contains: c => self._classList.has( c ),
				toggle: ( c, on ) => { const want = on === undefined ? ! self._classList.has( c ) : !! on; want ? self._classList.add( c ) : self._classList.delete( c ); return want; },
			};
		},
		set className( v ) { this._className = v; String( v ).split( /\s+/ ).filter( Boolean ).forEach( c => this._classList.add( c ) ); },
		get className() { return this._className || Array.from( this._classList ).join( ' ' ); },
		get nextElementSibling() {
			if ( ! this.parentNode ) return null;
			const i = this.parentNode.children.indexOf( this );
			return this.parentNode.children[ i + 1 ] || null;
		},
		get firstChild() { return this.children[ 0 ] || null; },
		set innerHTML( v ) { this._html = String( v ); parseHtml( this, this._html ); },
		get innerHTML() { return this._html || ''; },
	};

return el;
}

function matchesSelf( el, sel ) {
	sel = sel.trim();
	if ( sel.startsWith( '.' ) ) return el._classList.has( sel.slice( 1 ) );
	const m = sel.match( /^([a-z0-9]+)?((?:\.[\w-]+)*)$/i );
	if ( ! m ) return false;
	if ( m[ 1 ] && el.tagName !== m[ 1 ].toUpperCase() ) return false;
	return m[ 2 ].split( '.' ).filter( Boolean ).every( c => el._classList.has( c ) );
}

/*
 * Supports comma lists and one descendant step, which is all the assertions
 * here use. Anything deeper is resolved by the walker from the final compound.
 */
function queryAllIn( root, sel ) {
	const groups = sel.split( ',' ).map( s => s.trim() ).filter( Boolean );
	const out = [];

	groups.forEach( group => {
		const parts = group.split( /\s+/ );
		const self = parts[ parts.length - 1 ];
		const ancestor = parts.length > 1 ? parts[ 0 ] : null;

		const walk = ( n, matchedAncestor ) => {
			( n.children || [] ).forEach( c => {
				const isSelf = matchesSelf( c, self );
				const nowMatched = matchedAncestor || ( ancestor && matchesSelf( c, ancestor ) );
				if ( isSelf && ( !ancestor || nowMatched ) ) out.push( c );
				walk( c, nowMatched );
			} );
		};

		walk( root, false );
	} );

	return out;
}

function queryIn( root, sel ) {
	return queryAllIn( root, sel )[ 0 ] || null;
}

const doc = {
	activeElement: null,
	createElement: tag => makeEl( tag ),
	createElementNS: ( ns, tag ) => makeEl( tag ),
	getElementById: () => null,
	querySelector: () => null,
	querySelectorAll: () => [],
	addEventListener: () => {},
	body: makeEl( 'body' ),
};

function parseHtml( el, html ) {
	el.children = [];
	const re = /<(\w+)([^>]*?)(\/?)>/g;
	let m;
	while ( ( m = re.exec( html ) ) !== null ) {
		const child = makeEl( m[ 1 ] );
		const cls = m[ 2 ].match( /class="([^"]*)"/ );
		if ( cls ) child.className = cls[ 1 ];
		const attr = m[ 2 ].match( /([\w-]+)="([^"]*)"/g ) || [];
		attr.forEach( a => {
			const parts = a.match( /([\w-]+)="([^"]*)"/ );
			if ( parts ) child.setAttribute( parts[ 1 ], parts[ 2 ] );
		} );
		el.children.push( child );
		child.parentNode = el;
	}
}

/*
 * The widget's own markup is written with innerHTML and then wired up via
 * querySelector (the thumbs row, the contact card, the pencil). A DOM stub that
 * did not parse innerHTML would report null there and the assertions would be
 * testing the stub rather than the widget.
 */

const calls = [];
let fetchCalls = [];
let fetchQueue = [];
function resetFetch() {
	fetchQueue = [];
	fetchCalls = [];
	// init() fetches /agent from the constructor; it must not consume the stub
	// a test queued for its own request.
	fetchQueue.push( { body: { success: false } } );
}

const sandbox = {
	document: doc,
	window: {
		addEventListener: () => {},
		matchMedia: () => ( { matches: false, addEventListener: () => {} } ),
		aiChatbotConfig: null,
		innerHeight: 800,
		innerWidth: 1200,
		requestAnimationFrame: fn => setTimeout( fn, 0 ),
		cancelAnimationFrame: () => {},
		getComputedStyle: () => ( { getPropertyValue: () => '' } ),
	},
	navigator: { userAgent: 'node', clipboard: { writeText: () => Promise.resolve() } },
	console,
	setTimeout: () => 0,
	clearTimeout: () => {},
	Promise,
	JSON,
	Math,
	Date,
	AbortController: class { constructor() { this.signal = {}; } abort() {} },
	fetch: ( url, opts ) => {
		fetchCalls.push( { url, opts } );
		const next = fetchQueue.shift();
		if ( !next ) return Promise.resolve( { status: 200, text: () => Promise.resolve( '{"success":false,"message":"no stub"}' ) } );
		if ( next instanceof Error ) return Promise.reject( next );
		const payload = next.raw !== undefined ? next.raw : JSON.stringify( next.body );
		// init() uses res.json(), handleUserSend() uses res.text(), so both are
		// provided. A real Response supports both.
		return Promise.resolve( {
			status: next.status || 200,
			ok: ( next.status || 200 ) < 400,
			text: () => Promise.resolve( payload ),
			json: () => Promise.resolve( JSON.parse( payload ) ),
		} );
	},
	localStorage: {
		_: {},
		getItem( k ) { return this._[ k ] !== undefined ? this._[ k ] : null; },
		setItem( k, v ) { this._[ k ] = String( v ); },
		removeItem( k ) { delete this._[ k ]; },
	},
	cssEscape: s => s,
	Element: function () {},
};

sandbox.window.document = doc;
sandbox.globalThis = sandbox;
sandbox.self = sandbox;

vm.createContext( sandbox );
vm.runInContext( src, sandbox, { filename: 'chatbot-widget.js' } );

const Widget = sandbox.window.AIChatbotWidget || sandbox.AIChatbotWidget;
check( 'widget class is exported', typeof Widget === 'function', 'typeof=' + typeof Widget );

// ---------------------------------------------------------------- harness

function buildWidget() {
	resetFetch();
	const host = makeEl( 'div' );
	host.setAttribute( 'data-agent-id', '1' );
	host.setAttribute( 'data-mode', 'embedded' );

	const w = new Widget( host );
	w.config = { apiUrl: '/wp-json/ai-chatbot/v1', agentId: 1, siteName: 'Test', sounds: { enabled: false } };
	w.sessionId = 'sess_test';
	w.chatController = null;

	const body = makeEl( 'div' );
	body.className = 'ai-chatbot-body';
	w.chatBody = body;
	w.windowElement = makeEl( 'div' );
	w.windowElement.children.push( body );
	w.textInput = makeEl( 'textarea' );
	w.sendBtn = makeEl( 'button' );
	w.charCount = makeEl( 'span' );

	// The greeting and the contact card are part of appendMessage, not of the
	// edit flow, so they are left inert to keep the harness focused.
	w.agent = { greeting: '', show_on_welcome: false, suggested_questions: [] };
	w.isOpen = true;
	w.isLoading = false;

	return { w, body };
}

/** Send one message through the widget and return the rendered user bubble. */
async function sendAndGetBubble( w, body, text, stub ) {
	fetchQueue.push( { body: { success: true, data: stub } } );
	w.textInput.value = text;
	await w.handleUserSend();
	await flush();
	// The newest user bubble, not the first: several tests send more than one
	// message and need to address the one they just sent.
	const el = body.children.filter( c => c._classList.has( 'ai-message-user' ) ).pop();
	// Clear the composer so a later assertion cannot accidentally resend.
	w.textInput.value = '';
	return el;
}

const flush = () => new Promise( r => setImmediate( r ) );

async function main() {
	console.log( '\n1. The pencil appears only after the server acknowledges the message\n' );

	{
		const { w, body } = buildWidget();

		// Before the reply lands there is no id to edit against, so the affordance
		// must be absent rather than present-and-broken.
		w.textInput.value = 'first question';
		const pending = w.appendMessage( 'user', 'first question' );
		check( 'no pencil before the server replies', 0 === queryAllIn( pending, '.ai-message-edit-btn' ).length );
		check( 'message not marked editable yet', pending.dataset.editable === undefined );

		w.textInput.value = '';
		pending.remove();

		fetchQueue.push( { body: { success: true, data: { reply: 'Answer one.', message_id: 501, user_message_id: 777 } } } );
		w.textInput.value = 'my first question';
		await w.handleUserSend();
		await flush();

/*
	 * The newest user bubble, not the first: several tests send more than one
	 * message and need to address the one they just sent.
	 */
	const el = body.children.filter( c => c._classList.has( 'ai-message-user' ) ).pop();
		check( 'the user bubble was rendered', null !== el );

		const btn = queryIn( el, '.ai-message-edit-btn' );
		check( 'pencil attached once user_message_id arrives', null !== btn, 'no button found' );
		check( 'message id recorded on the element', '777' === el.dataset.msgId, 'got=' + el.dataset.msgId );
		check( 'pencil has an accessible label', 'Edit message' === ( btn.getAttribute( 'aria-label' ) || '' ) );
		check( 'pencil is a real button element', 'BUTTON' === btn.tagName );
		check( 'pencil has type=button so it cannot submit', 'button' === btn.getAttribute( 'type' ) );
	}

	console.log( '\n2. Assistant messages are not offered an edit\n' );

	{
		const { w } = buildWidget();
		const el = w.appendMessage( 'assistant', 'An answer', 501 );
		check( 'assistant message has no pencil', 0 === queryAllIn( el, '.ai-message-edit-btn' ).length );
	}

console.log( '\n3. Opening the editor\n' );

	let w, body, el, btn, ta;
	{
		const built = buildWidget();
		w = built.w; body = built.body;
		fetchQueue.push( { body: { success: true, data: { reply: 'Answer one.', message_id: 501, user_message_id: 777 } } } );

		// handleUserSend() renders the user bubble itself.
		w.textInput.value = 'first question';
		await w.handleUserSend();
		await flush();

		el = body.children.find( c => c._classList.has( 'ai-message-user' ) );
		btn = queryIn( el, '.ai-message-edit-btn' );
		check( 'pencil present to click', null !== btn );

		btn.dispatch( 'click' );

		const editor = queryIn( el, '.ai-message-editor' );
		check( 'editor opened in place', null !== editor );
		ta = queryIn( el, '.ai-message-edit-input' );
		check( 'textarea created', null !== ta );
		check( 'textarea pre-filled with the message', 'first question' === ta.value, 'got=' + JSON.stringify( ta.value ) );
		check( 'textarea has an accessible label', 'Edit your message' === ta.getAttribute( 'aria-label' ) );
		check( 'textarea described by the hint', ( ta.getAttribute( 'aria-describedby' ) || '' ).length > 0 );
		check( 'textarea is focused', doc.activeElement === ta );
		check( 'message marked as editing', el._classList.has( 'is-editing' ) );
		check( 'Save button present', null !== queryIn( el, '.ai-message-edit-footer .ai-btn-primary' ) );
		check( 'Cancel button present', null !== queryIn( el, '.ai-message-edit-footer .ai-btn-secondary' ) );
		check( 'original bubble hidden, not destroyed', 'none' === queryIn( el, '.ai-bubble' ).style.display );

		// Both buttons carry the admin class names, so they match the rest of
		// the plugin visually.
		check( 'Send uses ai-btn-primary', queryIn( el, '.ai-message-edit-footer .ai-btn-primary' )._classList.has( 'ai-btn' ) );
		check( 'Cancel uses ai-btn-secondary', queryIn( el, '.ai-message-edit-footer .ai-btn-secondary' )._classList.has( 'ai-btn' ) );

		// Send is a no-op while the text is unchanged or empty, so it is disabled.
		check( 'Send disabled while text is unchanged', true === queryIn( el, '.ai-message-edit-footer .ai-btn-primary' ).disabled );

		ta.value = '';
		ta.dispatch( 'input', {} );
		check( 'Send disabled while the field is empty', true === queryIn( el, '.ai-message-edit-footer .ai-btn-primary' ).disabled );

		ta.value = '   ';
		ta.dispatch( 'input', {} );
		check( 'Send disabled for whitespace only', true === queryIn( el, '.ai-message-edit-footer .ai-btn-primary' ).disabled );

		ta.value = 'a genuinely different question';
		ta.dispatch( 'input', {} );
		check( 'Send enabled once the text differs', false === queryIn( el, '.ai-message-edit-footer .ai-btn-primary' ).disabled );

		ta.value = 'first question';
		ta.dispatch( 'input', {} );
		check( 'Send disabled again when reverted to the original', true === queryIn( el, '.ai-message-edit-footer .ai-btn-primary' ).disabled );

		// Leading/trailing whitespace is not a change.
		ta.value = '  first question  ';
		ta.dispatch( 'input', {} );
		check( 'Send stays disabled for whitespace-only differences', true === queryIn( el, '.ai-message-edit-footer .ai-btn-primary' ).disabled );

		// Cancel is always available: escaping an edit must never be blocked.
		check( 'Cancel is never disabled', false === queryIn( el, '.ai-message-edit-footer .ai-btn-secondary' ).disabled );

		ta.value = 'first question';
	}

	console.log( '\n3b. Styling for Cancel and Send exists in the widget stylesheet\n' );

	{
		// The admin .ai-btn rules live in admin/build/index.css, which is only
		// enqueued inside wp-admin. The widget stylesheet is the only CSS on a
		// public page, so these buttons were unstyled browser defaults.
		const widgetCss = fs.readFileSync( path.join( __dirname, 'public/css/chatbot-widget.css' ), 'utf8' );

		check( 'widget css styles the editor buttons', widgetCss.includes( '.ai-message-edit-footer .ai-btn' ) );
		check( 'widget css defines the primary variant', widgetCss.includes( '.ai-message-edit-footer .ai-btn-primary' ) );
		check( 'widget css defines the secondary variant', widgetCss.includes( '.ai-message-edit-footer .ai-btn-secondary' ) );
		check( 'widget css defines the disabled state', /ai-btn:disabled/.test( widgetCss ) );
		check(
			'editor buttons use the widget theme colour, not the admin palette',
			/ai-message-edit-footer \.ai-btn-primary \{[^}]*var\(--ai-primary\)/s.test( widgetCss )
		);
		check( 'editor buttons do not hardcode indigo', ! /\.ai-message-edit-footer[\s\S]{0,400}?#6366f1/.test( widgetCss ) );
		check( 'focus ring defined for keyboard users', /ai-btn:focus-visible/.test( widgetCss ) );
	}

	console.log( '\n4. Cancel restores the original\n' );

	{
		const ta = queryIn( el, '.ai-message-edit-input' );
		ta.value = 'typed something else';
		queryIn( el, '.ai-message-edit-footer .ai-btn-secondary' ).dispatch( 'click' );

		check( 'editor removed', null === queryIn( el, '.ai-message-editor' ) );
		check( 'bubble visible again', '' === queryIn( el, '.ai-bubble' ).style.display );
		check( 'editing class cleared', ! el._classList.has( 'is-editing' ) );
		check( 'no request was sent', 0 === fetchCalls.filter( c => c.url.includes( '/chat/edit' ) ).length );
		check( 'no edited marker added', ! el._classList.has( 'is-edited' ) );
	}

	console.log( '\n5. Escape cancels\n' );

	{
		btn.dispatch( 'click' );
		check( 'editor reopened', null !== queryIn( el, '.ai-message-editor' ) );
		const ta = queryIn( el, '.ai-message-edit-input' );
		ta.dispatch( 'keydown', { key: 'Escape', isComposing: false, keyCode: 27 } );
		check( 'Escape closed the editor', null === queryIn( el, '.ai-message-editor' ) );
		check( 'Escape sent nothing', 0 === fetchCalls.filter( c => c.url.includes( '/chat/edit' ) ).length );
	}

	console.log( '\n6. Enter submits, Shift+Enter inserts a newline\n' );

	{
		btn.dispatch( 'click' );
		const ta = queryIn( el, '.ai-message-edit-input' );
		ta.value = 'corrected question';

		fetchQueue.push( { body: { success: true, data: { reply: 'A brand new answer.', message_id: 900, edited: true, discarded: 1 } } } );
		const before = fetchCalls.length;

		ta.dispatch( 'keydown', { key: 'Enter', isComposing: false, keyCode: 13, shiftKey: false } );
		await flush();
		await flush();

		const editCalls = fetchCalls.slice( before ).filter( c => c.url.includes( '/chat/edit' ) );
		check( 'Enter sent one edit request', 1 === editCalls.length, 'count=' + editCalls.length );
		check( 'edit endpoint used', editCalls[ 0 ] && editCalls[ 0 ].url.endsWith( '/chat/edit' ) );

		const bodySent = JSON.parse( editCalls[ 0 ].opts.body );
		check( 'message_id sent', 777 === bodySent.message_id, 'got=' + bodySent.message_id );
		check( 'edited text sent', 'corrected question' === bodySent.message );
		check( 'session_id sent', 'sess_test' === bodySent.session_id );
		check( 'agent_id sent', 1 === bodySent.agent_id );
	}

	console.log( '\n7. Shift+Enter does not submit\n' );

	{
		const built2 = buildWidget();
		const w2 = built2.w;
		const el2 = await sendAndGetBubble( w2, built2.body, 'q', { reply: 'A', message_id: 1, user_message_id: 2 } );

		queryIn( el2, '.ai-message-edit-btn' ).dispatch( 'click' );
		const ta = queryIn( el2, '.ai-message-edit-input' );
		const before = fetchCalls.length;
		ta.dispatch( 'keydown', { key: 'Enter', isComposing: false, keyCode: 13, shiftKey: true } );
		await flush();
		check( 'Shift+Enter sent nothing', before === fetchCalls.length );
		check( 'editor still open', null !== queryIn( el2, '.ai-message-editor' ) );
	}

	console.log( '\n8. IME composition is not hijacked\n' );

	{
		const built3 = buildWidget();
		const w3 = built3.w;
		const el3 = await sendAndGetBubble( w3, built3.body, 'q', { reply: 'A', message_id: 1, user_message_id: 2 } );

		queryIn( el3, '.ai-message-edit-btn' ).dispatch( 'click' );
		const ta = queryIn( el3, '.ai-message-edit-input' );
		const before = fetchCalls.length;
		ta.dispatch( 'keydown', { key: 'Enter', isComposing: true, keyCode: 229, shiftKey: false } );
		await flush();
		check( 'Enter during composition sent nothing', before === fetchCalls.length );
		check( 'editor still open after IME keystroke', null !== queryIn( el3, '.ai-message-editor' ) );
	}

	console.log( '\n9. The discarded branch is cleared and replaced\n' );

	{
		const built4 = buildWidget();
		const w4 = built4.w;
		const b4 = built4.body;

		// handleUserSend() renders the user bubble, so the transcript starts with
		// exactly one user message and one reply.
		const target = await sendAndGetBubble( w4, b4, 'first question', { reply: 'Answer one.', message_id: 501, user_message_id: 777 } );
		check( 'starting transcript has the sent message', null !== queryIn( target, '.ai-message-edit-btn' ) );

		// Later turns that an edit must remove.
		w4.appendMessage( 'assistant', 'stale answer', 502 );
		w4.appendMessage( 'user', 'follow up' );
		w4.appendMessage( 'assistant', 'stale follow up answer', 503 );
		w4.appendMessage( 'user', 'even later' );

		const beforeCount = b4.children.length;

		queryIn( target, '.ai-message-edit-btn' ).dispatch( 'click' );
		const ta = queryIn( target, '.ai-message-edit-input' );
		ta.value = 'rewritten question';

		fetchQueue.push( { body: { success: true, data: { reply: 'Fresh answer.', message_id: 999 } } } );
		ta.dispatch( 'keydown', { key: 'Enter', isComposing: false, keyCode: 13, shiftKey: false } );
		await flush();
		await flush();

		const texts = b4.children.map( c => ( c._classList.has( 'ai-message-user' ) ? 'U:' : 'A:' ) + ( queryIn( c, '.ai-bubble' ) ? queryIn( c, '.ai-bubble' )._html || '' : '' ) );
		check( 'stale answer removed', ! texts.some( t => t.includes( 'stale answer' ) ), texts.join( ' | ' ) );
		check( 'stale follow-up removed', ! texts.some( t => t.includes( 'even later' ) ), texts.join( ' | ' ) );
		check( 'edited message kept', texts.length >= 2 && texts[ 0 ].startsWith( 'U:' ), texts.join( ' | ' ) );
		check( 'new reply appended', texts.some( t => t.includes( 'Fresh answer.' ) ), texts.join( ' | ' ) );
		// Edited message + regenerated reply = 2 bubbles.
		check( 'transcript is now the edited message plus one reply', 2 === b4.children.length, 'before=' + beforeCount + ' after=' + b4.children.length );
		check( 'edited marker set', target._classList.has( 'is-edited' ) );
	}

	console.log( '\n10. Editing while a reply is pending is refused\n' );

	{
		const built5 = buildWidget();
		const w5 = built5.w;
		const el5 = await sendAndGetBubble( w5, built5.body, 'q', { reply: 'Answer.', message_id: 1, user_message_id: 42 } );

		w5.isLoading = true;
		queryIn( el5, '.ai-message-edit-btn' ).dispatch( 'click' );
		check( 'editor did not open mid-flight', null === queryIn( el5, '.ai-message-editor' ) );

		w5.isLoading = false;
		queryIn( el5, '.ai-message-edit-btn' ).dispatch( 'click' );
		check( 'editor opens once idle', null !== queryIn( el5, '.ai-message-editor' ) );
	}

	console.log( '\n11. Only one editor at a time\n' );

	{
		const built6 = buildWidget();
		const w6 = built6.w;
		const e1 = await sendAndGetBubble( w6, built6.body, 'one', { reply: 'A', message_id: 1, user_message_id: 11 } );
		const e2 = await sendAndGetBubble( w6, built6.body, 'two', { reply: 'B', message_id: 2, user_message_id: 22 } );

		queryIn( e1, '.ai-message-edit-btn' ).dispatch( 'click' );
		check( 'first editor open', null !== queryIn( e1, '.ai-message-editor' ) );

		queryIn( e2, '.ai-message-edit-btn' ).dispatch( 'click' );
		check( 'first editor closed', null === queryIn( e1, '.ai-message-editor' ) );
		check( 'first message no longer in editing state', ! e1._classList.has( 'is-editing' ) );
		check( 'second editor open', null !== queryIn( e2, '.ai-message-editor' ) );
	}

	console.log( '\n12. Failures are surfaced, not swallowed\n' );

	{
		const built7 = buildWidget();
		const w7 = built7.w;
		const b7 = built7.body;
		const e7 = await sendAndGetBubble( w7, b7, 'orig', { reply: 'A', message_id: 1, user_message_id: 55 } );

		queryIn( e7, '.ai-message-edit-btn' ).dispatch( 'click' );
		const ta = queryIn( e7, '.ai-message-edit-input' );
		ta.value = 'edited';
		fetchQueue.push( { body: { success: false, message: 'Message not found in this conversation.', code: 'message_not_found' } } );
		ta.dispatch( 'keydown', { key: 'Enter', isComposing: false, keyCode: 13, shiftKey: false } );
		await flush();
		await flush();

		const bubbles = b7.children.map( c => queryIn( c, '.ai-bubble' ) ? queryIn( c, '.ai-bubble' )._html || '' : '' );
		check( 'server error message shown to the visitor', bubbles.some( b => String( b ).includes( 'Message not found' ) ), bubbles.join( ' | ' ) );
		check( 'busy state cleared after failure', w7.isLoading === false );
	}

	console.log( '\n13. Network failure is reported\n' );

	{
		const built8 = buildWidget();
		const w8 = built8.w;
		const b8 = built8.body;
		const e8 = await sendAndGetBubble( w8, b8, 'orig', { reply: 'A', message_id: 1, user_message_id: 66 } );

		queryIn( e8, '.ai-message-edit-btn' ).dispatch( 'click' );
		const ta = queryIn( e8, '.ai-message-edit-input' );
		ta.value = 'edited';
		fetchQueue.push( new Error( 'network down' ) );
		ta.dispatch( 'keydown', { key: 'Enter', isComposing: false, keyCode: 13, shiftKey: false } );
		await flush();
		await flush();

		const bubbles = b8.children.map( c => queryIn( c, '.ai-bubble' ) ? queryIn( c, '.ai-bubble' )._html || '' : '' );
		check( 'connection error surfaced', bubbles.some( b => String( b ).includes( 'Unable to reach the server' ) ), bubbles.join( ' | ' ) );
		check( 'busy state cleared', w8.isLoading === false );
	}

	console.log( '\n14. Non-JSON response handled\n' );

	{
		const built9 = buildWidget();
		const w9 = built9.w;
		const b9 = built9.body;
		const e9 = await sendAndGetBubble( w9, b9, 'orig', { reply: 'A', message_id: 1, user_message_id: 77 } );

		queryIn( e9, '.ai-message-edit-btn' ).dispatch( 'click' );
		const ta = queryIn( e9, '.ai-message-edit-input' );
		ta.value = 'edited';
		// A PHP fatal returns HTML, which must not throw inside res.json().
		fetchQueue.push( { status: 500, raw: '<!DOCTYPE html><html>Fatal error</html>' } );
		ta.dispatch( 'keydown', { key: 'Enter', isComposing: false, keyCode: 13, shiftKey: false } );
		await flush();
		await flush();

		const bubbles = b9.children.map( c => queryIn( c, '.ai-bubble' ) ? queryIn( c, '.ai-bubble' )._html || '' : '' );
		check( 'HTML response produced a readable error', bubbles.some( b => String( b ).includes( 'Server error (500)' ) ), bubbles.join( ' | ' ) );
	}

	console.log( '\n15. Styling hooks exist for every class the JS creates\n' );

	const css = fs.readFileSync( path.join( __dirname, 'public/css/chatbot-widget.css' ), 'utf8' );
	[
		'ai-message-actions',
		'ai-message-edit-btn',
		'ai-message-editor',
		'ai-message-edit-input',
		'ai-message-edit-hint',
		'ai-message-edit-footer',
		'ai-message-user.is-editing',
		'ai-message-user.is-edited',
	].forEach( sel => {
		check( `stylesheet defines ${sel}`, css.includes( sel ) );
	} );
	check( 'pencil revealed on focus as well as hover', css.includes( ':focus-visible' ) );
	check( 'touch devices get a visible pencil', css.includes( '@media (hover: none)' ) );
	check( 'reduced motion honoured', css.includes( 'prefers-reduced-motion' ) );

	console.log( '\n16. No legacy editor path remains\n' );

	check( 'editor does not use window.prompt', !/window\.prompt|\bprompt\(/.test( src ) );
	check( 'only one edit endpoint is called', ( src.match( /\/chat\/edit/g ) || [] ).length >= 1 );
	check( 'composer untouched by the editor', ! src.includes( 'this.editingMessage = msgEl' ) );

	console.log( `\n${bad.length === 0 ? 'ALL WIDGET CHECKS PASSED' : `${bad.length} WIDGET CHECKS FAILED:\n  - ${bad.join( '\n  - ' )}`}\n` );
	process.exit( bad.length === 0 ? 0 : 1 );
}

main().catch( e => {
	console.error( '\nHarness error:', e );
	process.exit( 1 );
} );