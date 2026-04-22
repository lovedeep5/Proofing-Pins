(() => {
	'use strict';
	if (!window.PP_CONFIG) return;
	const CFG = window.PP_CONFIG;
	const I = CFG.i18n;

	const STATUS_LABELS = {
		pp_open: I.statusOpen,
		pp_in_progress: I.statusInProgress,
		pp_resolved: I.statusResolved,
		pp_archived: I.statusArchived,
	};
	const STATUS_COLORS = {
		pp_open: '#ef4444',
		pp_in_progress: '#f59e0b',
		pp_resolved: '#10b981',
		pp_archived: '#9ca3af',
	};

	const state = {
		active: false,
		pins: [],
		openPinId: null,
		pendingComposer: null,
		submitting: false,
		// Offset between the doc coordinate origin and the viewport top. Non-zero
		// when something (WP admin bar, a custom sticky header using html margin,
		// etc.) pushes the positioning containing block down. Measured at runtime.
		topOffset: 0,
	};

	// Measure how much an absolute-positioned element at top:0/left:0 is visually
	// offset from the viewport top-left. That's the shift our baked pin needs to
	// compensate for, regardless of where the shift comes from.
	function measureTopOffset() {
		const probe = document.createElement('div');
		probe.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:1px;visibility:hidden;pointer-events:none;';
		document.body.appendChild(probe);
		const rect = probe.getBoundingClientRect();
		const top  = rect.top  + window.scrollY;
		const left = rect.left + window.scrollX;
		probe.remove();
		// Persist in sessionStorage so it survives tab navigations within the session.
		try {
			sessionStorage.setItem('pp_topOffset', JSON.stringify({ top, left, t: Date.now() }));
		} catch {}
		return { top, left };
	}

	function refreshTopOffset() {
		const m = measureTopOffset();
		state.topOffset = m.top;
		state.leftOffset = m.left;
	}

	const css = `
	:host { all: initial; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
	* { box-sizing: border-box; }
	.pp-toggle {
		position: fixed; bottom: 24px; ${CFG.settings.position === 'left' ? 'left' : 'right'}: 24px;
		width: 52px; height: 52px; border-radius: 50%; border: none; cursor: pointer;
		background: var(--pp-brand); color: #fff; box-shadow: 0 8px 24px rgba(0,0,0,0.18);
		display: flex; align-items: center; justify-content: center;
		z-index: 2147483646; transition: transform 0.15s ease, box-shadow 0.15s ease;
	}
	.pp-toggle:hover { transform: scale(1.06); box-shadow: 0 12px 32px rgba(0,0,0,0.22); }
	.pp-toggle.active { background: #111827; }
	.pp-toggle svg { width: 22px; height: 22px; }
	.pp-badge {
		position: absolute; top: -4px; right: -4px;
		background: #ef4444; color: #fff; font-size: 11px; font-weight: 700;
		min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px;
		display: flex; align-items: center; justify-content: center; border: 2px solid #fff;
	}
	.pp-statusbar {
		position: fixed; top: 0; left: 0; right: 0;
		background: #111827; color: #fff; padding: 10px 16px; font-size: 13px;
		display: flex; align-items: center; justify-content: center; gap: 12px;
		z-index: 2147483645; transform: translateY(-100%); transition: transform 0.2s ease;
	}
	.pp-statusbar.visible { transform: translateY(0); }
	.pp-statusbar kbd {
		background: rgba(255,255,255,0.15); border-radius: 4px; padding: 2px 6px;
		font-family: ui-monospace, monospace; font-size: 11px;
	}
	.pp-pin {
		position: absolute; width: 28px; height: 28px; border-radius: 50% 50% 50% 2px;
		transform: translate(-4px, -24px) rotate(-45deg);
		background: var(--pin-color, #ef4444); color: #fff; border: 2px solid #fff;
		box-shadow: 0 4px 12px rgba(0,0,0,0.25); cursor: pointer;
		display: flex; align-items: center; justify-content: center;
		font-size: 11px; font-weight: 700; z-index: 2147483640;
		transition: transform 0.15s ease;
	}
	.pp-pin span { transform: rotate(45deg); }
	.pp-pin:hover { transform: translate(-4px, -28px) rotate(-45deg) scale(1.1); }
	.pp-pin.pending { opacity: 0.55; }
	.pp-composer, .pp-thread {
		position: fixed; background: #fff; border-radius: 12px;
		box-shadow: 0 20px 50px rgba(0,0,0,0.2), 0 0 0 1px rgba(0,0,0,0.05);
		z-index: 2147483641; overflow: hidden;
	}
	.pp-composer { width: 320px; padding: 14px; }
	.pp-composer-user { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 12px; color: #6b7280; }
	.pp-composer-user img { width: 24px; height: 24px; border-radius: 50%; }
	.pp-composer textarea {
		width: 100%; min-height: 80px; padding: 10px; border: 1px solid #e5e7eb;
		border-radius: 8px; font: inherit; font-size: 14px; resize: vertical;
	}
	.pp-composer textarea:focus { outline: none; border-color: var(--pp-brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--pp-brand) 20%, transparent); }
	.pp-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 10px; }
	.pp-btn {
		padding: 8px 14px; border-radius: 7px; font-size: 13px; font-weight: 500;
		border: none; cursor: pointer; transition: background 0.12s ease;
	}
	.pp-btn-primary { background: var(--pp-brand); color: #fff; }
	.pp-btn-primary:hover { filter: brightness(1.08); }
	.pp-btn-primary:disabled { opacity: 0.6; cursor: wait; }
	.pp-btn-ghost { background: transparent; color: #6b7280; }
	.pp-btn-ghost:hover { background: #f3f4f6; }
	.pp-status {
		font-size: 11px; font-weight: 600; text-transform: uppercase;
		letter-spacing: 0.04em; padding: 2px 8px; border-radius: 10px;
		color: #fff; display: inline-block;
	}
	.pp-thread {
		right: 20px; top: 20px; bottom: 20px; width: 360px;
		display: flex; flex-direction: column;
	}
	.pp-thread-header { padding: 16px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between; }
	.pp-thread-meta { font-size: 12px; color: #6b7280; }
	.pp-thread-close { background: transparent; border: none; cursor: pointer; font-size: 20px; color: #9ca3af; padding: 4px; }
	.pp-thread-body { flex: 1; overflow-y: auto; padding: 14px 16px; }
	.pp-msg { margin-bottom: 14px; }
	.pp-msg-head { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
	.pp-msg-head img { width: 24px; height: 24px; border-radius: 50%; }
	.pp-msg-author { font-weight: 600; font-size: 13px; color: #111827; }
	.pp-msg-time { font-size: 11px; color: #9ca3af; }
	.pp-msg-body { font-size: 14px; line-height: 1.5; color: #374151; white-space: pre-wrap; word-wrap: break-word; padding-left: 32px; }
	.pp-thread-reply { padding: 12px 16px; border-top: 1px solid #f3f4f6; }
	.pp-thread-reply textarea {
		width: 100%; min-height: 60px; padding: 8px; border: 1px solid #e5e7eb;
		border-radius: 8px; font: inherit; font-size: 13px; resize: vertical;
	}
	.pp-thread-reply textarea:focus { outline: none; border-color: var(--pp-brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--pp-brand) 20%, transparent); }
	.pp-thread-status { padding: 10px 16px; border-top: 1px solid #f3f4f6; display: flex; align-items: center; gap: 8px; background: #fafafa; font-size: 12px; }
	.pp-thread-status select { font: inherit; font-size: 12px; padding: 4px 8px; border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; }
	.pp-toast {
		position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%) translateY(20px);
		background: #111827; color: #fff; padding: 10px 16px; border-radius: 8px;
		font-size: 13px; z-index: 2147483647; opacity: 0; transition: all 0.2s ease;
	}
	.pp-toast.visible { opacity: 1; transform: translateX(-50%) translateY(0); }
	body.pp-mode-active, body.pp-mode-active * { cursor: crosshair !important; }
	.pp-hp { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
	.pp-guest-dot { width: 24px; height: 24px; border-radius: 50%; background: linear-gradient(135deg, var(--pp-brand), #9333ea); flex-shrink: 0; }
	.pp-identity-modal { position: fixed; inset: 0; z-index: 2147483647; display: flex; align-items: center; justify-content: center; }
	.pp-identity-backdrop { position: absolute; inset: 0; background: rgba(15,23,42,0.55); backdrop-filter: blur(2px); }
	.pp-identity-card { position: relative; width: 360px; background: #fff; border-radius: 12px; padding: 22px; box-shadow: 0 30px 80px rgba(0,0,0,0.35); }
	.pp-identity-card h3 { margin: 0 0 14px; font-size: 16px; color: #0f172a; }
	.pp-identity-card label { display: block; font-size: 12px; color: #6b7280; margin: 10px 0 4px; font-weight: 600; }
	.pp-identity-card input { width: 100%; padding: 9px 11px; border: 1px solid #e5e7eb; border-radius: 7px; font: inherit; font-size: 14px; }
	.pp-identity-card input:focus { outline: none; border-color: var(--pp-brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--pp-brand) 20%, transparent); }
	.pp-identity-hint { font-size: 11px; color: #94a3b8; margin: 10px 0 14px; }
	`;

	// ---------- root setup ----------
	const rootEl = document.getElementById('pp-root');
	if (!rootEl) return;
	const shadow = rootEl.attachShadow({ mode: 'open' });

	const styleEl = document.createElement('style');
	styleEl.textContent = css;
	shadow.appendChild(styleEl);

	const container = document.createElement('div');
	container.style.setProperty('--pp-brand', CFG.settings.brand_color || '#2271b1');
	shadow.appendChild(container);

	// ---------- REST helpers ----------
	async function api(method, path, body) {
		const res = await fetch(CFG.restUrl + path.replace(/^\//, ''), {
			method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			credentials: 'same-origin',
			body: body ? JSON.stringify(body) : undefined,
		});
		if (!res.ok) {
			let msg = `HTTP ${res.status}`;
			try { const j = await res.json(); if (j && j.message) msg = j.message; } catch {}
			const err = new Error(msg); err.status = res.status; throw err;
		}
		return res.json();
	}

	// ---------- guest identity (cookie) ----------
	const COOKIE = 'pp_guest_identity';
	function readCookie(name) {
		const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}
	function writeCookie(name, value, days) {
		const exp = new Date(Date.now() + days * 864e5).toUTCString();
		document.cookie = `${name}=${encodeURIComponent(value)};expires=${exp};path=/;SameSite=Lax`;
	}
	function getGuestIdentity() {
		try {
			const raw = readCookie(COOKIE);
			if (!raw) return null;
			const j = JSON.parse(raw);
			if (j && j.name && j.email) return j;
		} catch {}
		return null;
	}
	function saveGuestIdentity(name, email) {
		writeCookie(COOKIE, JSON.stringify({ name, email, set_at: Date.now() }), 30);
	}
	const guestState = { identity: CFG.user.isGuest ? getGuestIdentity() : null };

	// ---------- element HTML snapshot (small, for AI context) ----------
	function elementHtml(el) {
		if (!(el instanceof Element)) return '';
		// Grab a useful slice: the element itself, not a huge parent subtree.
		// Trim noisy attributes we don't need (style long strings, inline event handlers).
		try {
			const clone = el.cloneNode(true);
			// shallow strip of scripts and svg children to keep small
			clone.querySelectorAll('script,style,svg,noscript').forEach(n => n.remove());
			let html = clone.outerHTML || '';
			if (html.length > 2500) html = html.slice(0, 2500) + '…';
			return html;
		} catch { return ''; }
	}

	// ---------- CSS selector helper ----------
	function cssPath(el) {
		if (!(el instanceof Element)) return '';
		if (el.id) return `#${CSS.escape(el.id)}`;
		const path = [];
		let cur = el;
		while (cur && cur.nodeType === 1 && path.length < 6) {
			let sel = cur.nodeName.toLowerCase();
			if (cur.className && typeof cur.className === 'string') {
				const classes = cur.className.trim().split(/\s+/).filter(Boolean);
				const cls = classes.slice(0, 2).map(c => `.${CSS.escape(c)}`).join('');
				sel += cls;
			}
			const parent = cur.parentNode;
			if (parent) {
				const siblings = Array.from(parent.children).filter(n => n.nodeName === cur.nodeName);
				if (siblings.length > 1) sel += `:nth-of-type(${siblings.indexOf(cur) + 1})`;
			}
			path.unshift(sel);
			cur = cur.parentElement;
		}
		return path.join(' > ');
	}

	// ---------- XPath helper (fallback anchor) ----------
	function xpathOf(el) {
		if (!(el instanceof Element)) return '';
		if (el.id) return `//*[@id="${el.id.replace(/"/g, '\\"')}"]`;
		const parts = [];
		let cur = el;
		while (cur && cur.nodeType === 1 && cur !== document.documentElement) {
			const tag = cur.nodeName.toLowerCase();
			const parent = cur.parentNode;
			if (!parent) break;
			const siblings = Array.from(parent.children).filter(n => n.nodeName === cur.nodeName);
			const idx = siblings.length > 1 ? `[${siblings.indexOf(cur) + 1}]` : '';
			parts.unshift(tag + idx);
			cur = cur.parentElement;
		}
		return '/' + parts.join('/');
	}

	// ---------- Anchor resolution (selector -> xpath -> text fallback) ----------
	function findAnchor(selector, xpath, text) {
		if (selector) {
			try {
				const el = document.querySelector(selector);
				if (el) return el;
			} catch {}
		}
		if (xpath) {
			try {
				const res = document.evaluate(xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null);
				if (res && res.singleNodeValue) return res.singleNodeValue;
			} catch {}
		}
		if (text && text.length >= 3) {
			const needle = text.toLowerCase();
			const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
				acceptNode: (n) => n.nodeValue && n.nodeValue.toLowerCase().includes(needle)
					? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT,
			});
			const hit = walker.nextNode();
			if (hit && hit.parentElement) return hit.parentElement;
		}
		return null;
	}

	// ---------- debounce ----------
	function debounce(fn, ms) {
		let t = 0;
		return function (...args) {
			clearTimeout(t);
			t = setTimeout(() => fn.apply(this, args), ms);
		};
	}

	// ---------- Canvas 2D pin drawing (bakes pin into final image) ----------
	function drawPinCircle(ctx, x, y) {
		// Outer soft halo (slight offset for depth)
		ctx.beginPath();
		ctx.arc(x, y + 1, 17, 0, Math.PI * 2);
		ctx.fillStyle = 'rgba(0,0,0,0.18)';
		ctx.fill();
		// White outer ring
		ctx.beginPath();
		ctx.arc(x, y, 16, 0, Math.PI * 2);
		ctx.fillStyle = '#ffffff';
		ctx.fill();
		// Red pin body
		ctx.beginPath();
		ctx.arc(x, y, 12, 0, Math.PI * 2);
		ctx.fillStyle = '#ef4444';
		ctx.fill();
	}

	// ---------- UI: toggle button ----------
	function buildToggle() {
		const btn = document.createElement('button');
		btn.className = 'pp-toggle';
		btn.title = I.toggleOn;
		btn.setAttribute('aria-label', I.toggleOn);
		btn.innerHTML = `
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
			</svg>
			<span class="pp-badge" hidden>0</span>
		`;
		btn.addEventListener('click', toggleMode);
		return btn;
	}

	// ---------- statusbar ----------
	function buildStatusbar() {
		const bar = document.createElement('div');
		bar.className = 'pp-statusbar';
		bar.innerHTML = `<span>${I.prompt}</span> <kbd>Esc</kbd>`;
		return bar;
	}

	const toggleBtn = buildToggle();
	const statusbar = buildStatusbar();
	container.appendChild(toggleBtn);
	container.appendChild(statusbar);

	function updateBadge() {
		const open = state.pins.filter(p => p.status === 'pp_open').length;
		const badge = toggleBtn.querySelector('.pp-badge');
		if (open > 0) { badge.textContent = open; badge.hidden = false; } else { badge.hidden = true; }
	}

	// ---------- pin rendering ----------
	const pinNodes = new Map();

	function renderPins() {
		pinNodes.forEach(n => n.remove());
		pinNodes.clear();
		if (!state.active) return;
		state.pins.forEach((pin, idx) => {
			const pos = computePinPosition(pin);
			if (!pos) return; // unanchored — no overlay (screenshot still shows it)
			const node = document.createElement('button');
			node.className = 'pp-pin';
			node.style.left = pos.x + 'px';
			node.style.top  = pos.y + 'px';
			node.style.setProperty('--pin-color', STATUS_COLORS[pin.status] || '#ef4444');
			node.innerHTML = `<span>${idx + 1}</span>`;
			node.addEventListener('click', (e) => { e.stopPropagation(); openThread(pin.id); });
			container.appendChild(node);
			pinNodes.set(pin.id, node);
		});
	}

	function computePinPosition(pin) {
		// New anchor model (responsive)
		if (pin.anchor_selector || pin.anchor_xpath) {
			const el = findAnchor(pin.anchor_selector, pin.anchor_xpath, pin.anchor_text);
			if (el) {
				const r = el.getBoundingClientRect();
				if (r.width > 0 && r.height > 0) {
					return {
						x: r.left + r.width  * (pin.offset_x_pct != null ? pin.offset_x_pct : 0.5) + window.scrollX,
						y: r.top  + r.height * (pin.offset_y_pct != null ? pin.offset_y_pct : 0.5) + window.scrollY,
					};
				}
			}
			// Anchor missing — old pin might also still have legacy fields; fall through.
		}
		// Legacy pin (pre-v2): doc_x/doc_y pixels, or pin_x/pin_y as % of body
		if (pin.doc_x != null && pin.doc_y != null && (pin.doc_x || pin.doc_y)) {
			return { x: pin.doc_x, y: pin.doc_y };
		}
		if (pin.pin_x != null && pin.pin_y != null) {
			return {
				x: (pin.pin_x / 100) * document.body.scrollWidth + window.scrollX,
				y: (pin.pin_y / 100) * document.body.scrollHeight + window.scrollY,
			};
		}
		return null;
	}

	// Re-render on resize so pins track element moves (responsive by construction).
	window.addEventListener('resize', debounce(() => { if (state.active) renderPins(); }, 100));

	// ---------- mode toggle ----------
	async function toggleMode() {
		// Guest: require identity before entering proofing mode.
		if (!state.active && CFG.user.isGuest && !guestState.identity) {
			await promptGuestIdentity();
			if (!guestState.identity) return; // user cancelled
		}
		state.active = !state.active;
		toggleBtn.classList.toggle('active', state.active);
		statusbar.classList.toggle('visible', state.active);
		document.body.classList.toggle('pp-mode-active', state.active);
		toggleBtn.title = state.active ? I.toggleOff : I.toggleOn;
		if (state.active) {
			// Measure the page's absolute-positioning offset (admin bar, sticky
			// header, etc.) so baked pins land on the right pixel during capture.
			refreshTopOffset();
			await loadPins();
			document.addEventListener('click', onPageClick, true);
			document.addEventListener('keydown', onKeydown);
		} else {
			document.removeEventListener('click', onPageClick, true);
			document.removeEventListener('keydown', onKeydown);
			closeComposer();
			closeThread();
			renderPins();
		}
	}

	// Re-measure when the layout can shift (resize → admin bar height can change
	// breakpoints, sticky headers may resize, etc.).
	window.addEventListener('resize', debounce(() => { if (state.active) refreshTopOffset(); }, 150));

	function promptGuestIdentity() {
		return new Promise((resolve) => {
			const modal = document.createElement('div');
			modal.className = 'pp-identity-modal';
			modal.innerHTML = `
				<div class="pp-identity-backdrop"></div>
				<div class="pp-identity-card">
					<h3>${escapeHtml(I.guestIntro)}</h3>
					<label>${escapeHtml(I.guestName)}</label>
					<input type="text" id="pp-g-name" autocomplete="name">
					<label>${escapeHtml(I.guestEmail)}</label>
					<input type="email" id="pp-g-email" autocomplete="email">
					<p class="pp-identity-hint">${escapeHtml(I.guestRemembered)}</p>
					<div class="pp-actions">
						<button class="pp-btn pp-btn-ghost" data-act="cancel">${escapeHtml(I.cancel)}</button>
						<button class="pp-btn pp-btn-primary" data-act="ok">${escapeHtml(I.guestContinue)}</button>
					</div>
				</div>
			`;
			container.appendChild(modal);
			const nameEl  = modal.querySelector('#pp-g-name');
			const emailEl = modal.querySelector('#pp-g-email');
			nameEl.focus();
			function close(saved) { modal.remove(); resolve(saved); }
			modal.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
			modal.querySelector('[data-act="ok"]').addEventListener('click', () => {
				const name  = nameEl.value.trim();
				const email = emailEl.value.trim();
				if (!name) { nameEl.focus(); return; }
				if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { emailEl.focus(); return; }
				saveGuestIdentity(name, email);
				guestState.identity = { name, email };
				close({ name, email });
			});
		});
	}

	function onKeydown(e) {
		if (e.key === 'Escape') {
			if (state.pendingComposer) closeComposer();
			else if (state.openPinId) closeThread();
			else if (state.active) toggleMode();
		}
	}

	async function loadPins() {
		try {
			const list = await api('GET', `pins?page_url=${encodeURIComponent(CFG.pageUrl)}`);
			state.pins = list || [];
			updateBadge();
			renderPins();
		} catch (err) { toast('Failed to load pins'); }
	}

	// ---------- click handler ----------
	function onPageClick(e) {
		// ignore clicks inside our shadow root
		if (e.composedPath().includes(rootEl)) return;
		if (state.pendingComposer || state.openPinId || state.submitting) return;
		e.preventDefault();
		e.stopPropagation();
		openComposer(e.clientX, e.clientY, e.target);
	}

	// ---------- composer ----------
	function openComposer(x, y, target) {
		closeComposer();
		const composer = document.createElement('div');
		composer.className = 'pp-composer';
		const vw = window.innerWidth;
		const vh = window.innerHeight;
		const W = 320, H = 180;
		let left = x + 12;
		let top = y + 12;
		if (left + W > vw - 16) left = x - W - 12;
		if (top + H > vh - 16) top = y - H - 12;
		composer.style.left = Math.max(8, left) + 'px';
		composer.style.top = Math.max(8, top) + 'px';
		const who = CFG.user.isGuest
			? (guestState.identity ? guestState.identity.name : 'Guest')
			: CFG.user.name;
		const avatarHtml = CFG.user.isGuest
			? `<div class="pp-guest-dot"></div>`
			: `<img src="${CFG.user.avatar}" alt="">`;
		composer.innerHTML = `
			<div class="pp-composer-user">
				${avatarHtml}
				<span>${I.postedBy} ${escapeHtml(who)}</span>
			</div>
			<textarea placeholder="${escapeHtml(I.placeholder)}" rows="3"></textarea>
			<input type="text" name="hp" tabindex="-1" autocomplete="off" class="pp-hp" aria-hidden="true">
			<div class="pp-actions">
				<button class="pp-btn pp-btn-ghost" data-act="cancel">${I.cancel}</button>
				<button class="pp-btn pp-btn-primary" data-act="submit">${I.submit}</button>
			</div>
		`;
		container.appendChild(composer);
		const ta = composer.querySelector('textarea');
		ta.focus();

		// provisional pin marker — document-absolute so it stays with content on scroll
		const docX = x + window.scrollX;
		const docY = y + window.scrollY;
		const marker = document.createElement('button');
		marker.className = 'pp-pin pending';
		marker.style.left = docX + 'px';
		marker.style.top = docY + 'px';
		marker.style.setProperty('--pin-color', '#ef4444');
		marker.innerHTML = `<span>•</span>`;
		container.appendChild(marker);

		// Element anchor — the source of truth. Pin stored as offset % within the
		// clicked element. Responsive by construction: on any viewport the pin
		// follows the element.
		let anchorSelector = '', anchorXPath = '', anchorText = '';
		let offsetXPct = 0.5, offsetYPct = 0.5;
		if (target instanceof Element) {
			const er = target.getBoundingClientRect();
			if (er.width > 0 && er.height > 0) {
				offsetXPct = Math.max(0, Math.min(1, (x - er.left) / er.width));
				offsetYPct = Math.max(0, Math.min(1, (y - er.top) / er.height));
			}
			anchorSelector = cssPath(target);
			anchorXPath    = xpathOf(target);
			anchorText     = ((target.innerText || target.textContent || '').trim()).slice(0, 40);
		}

		// Elementor widget detection — walk up from the clicked element to find
		// the nearest widget wrapper. Needed because data-widget_type lives on
		// the wrapper, not on the leaf element the user actually clicks.
		let elementorWidgetType = '', elementorWidgetId = '';
		if (target instanceof Element) {
			const widget = target.closest('[data-element_type="widget"]');
			if (widget) {
				const rawType = widget.getAttribute('data-widget_type') || '';
				elementorWidgetType = rawType.split('.')[0]; // strip ".default" skin suffix
				elementorWidgetId   = widget.getAttribute('data-id') || '';
			}
		}

		state.pendingComposer = {
			composer, marker,
			x, y,
			// Anchor (primary)
			anchor_selector: anchorSelector,
			anchor_xpath:    anchorXPath,
			anchor_text:     anchorText,
			offset_x_pct:    offsetXPct,
			offset_y_pct:    offsetYPct,
			viewport_w: vw,
			viewport_h: vh,
			element_tag: target && target.nodeName ? target.nodeName.toLowerCase() : '',
			// Small element HTML snippet for AI context
			element_html: elementHtml(target),
			scroll_y_pct: (window.scrollY / Math.max(1, document.documentElement.scrollHeight - vh)) * 100,
			// Elementor widget identity (empty strings when not an Elementor page)
			elementor_widget_type: elementorWidgetType,
			elementor_widget_id:   elementorWidgetId,
		};

		composer.querySelector('[data-act="cancel"]').addEventListener('click', closeComposer);
		composer.querySelector('[data-act="submit"]').addEventListener('click', submitComposer);
		ta.addEventListener('keydown', (e) => {
			if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') submitComposer();
		});
	}

	function closeComposer() {
		if (!state.pendingComposer) return;
		state.pendingComposer.composer.remove();
		state.pendingComposer.marker.remove();
		state.pendingComposer = null;
	}

	async function submitComposer() {
		if (!state.pendingComposer || state.submitting) return;
		const pc = state.pendingComposer;
		const ta = pc.composer.querySelector('textarea');
		const body = ta.value.trim();
		if (!body) { ta.focus(); return; }
		const submitBtn = pc.composer.querySelector('[data-act="submit"]');
		submitBtn.disabled = true;
		submitBtn.textContent = I.capturing;
		state.submitting = true;

		// Capture strategy:
		// 1) Widget stays visible on page. html-to-image's `filter` excludes it.
		// 2) Inject a red-circle pin at doc coords (doc_x, doc_y) on document.body
		//    BEFORE capture — it becomes part of what html-to-image renders, so any
		//    vertical drift in the clone carries the pin with it.
		// 3) toCanvas at natural body size; crop to viewport slice.
		// 4) skipFonts kills the CORS-fetch delay + SecurityError console spam.

		const vw = window.innerWidth;
		const vh = window.innerHeight;
		const sx = window.scrollX;
		const sy = window.scrollY;

		// Anchor the baked pin INSIDE the clicked element so any rendering drift in
		// the clone carries the pin with the content. Falls back to doc-coord
		// absolute positioning if we can't attach to the element.
		const anchorEl = findAnchor(pc.anchor_selector, pc.anchor_xpath, pc.anchor_text);
		const bakedPin = document.createElement('div');
		bakedPin.id = 'pp-baked-pin';
		let anchorRestore = null;
		if (anchorEl && anchorEl.getBoundingClientRect().width > 0) {
			const computed = getComputedStyle(anchorEl);
			if (computed.position === 'static') {
				anchorRestore = { el: anchorEl, prop: 'position', prev: anchorEl.style.position };
				anchorEl.style.position = 'relative';
			}
			bakedPin.style.cssText = [
				'position:absolute',
				'left:' + (pc.offset_x_pct * 100) + '%',
				'top:'  + (pc.offset_y_pct * 100) + '%',
				'width:24px','height:24px',
				'margin-left:-12px','margin-top:-12px',
				'border-radius:50%',
				'background:#ef4444',
				'border:3px solid #ffffff',
				'box-shadow:0 3px 10px rgba(0,0,0,0.4)',
				'z-index:2147483646',
				'pointer-events:none',
			].join(';');
			anchorEl.appendChild(bakedPin);
		} else {
			// Fallback: absolute at doc coords with runtime-measured offsets.
			const docX = pc.x + sx - (state.leftOffset || 0);
			const docY = pc.y + sy - (state.topOffset  || 0);
			bakedPin.style.cssText = [
				'position:absolute',
				'left:' + docX + 'px',
				'top:'  + docY + 'px',
				'width:24px','height:24px',
				'margin-left:-12px','margin-top:-12px',
				'border-radius:50%',
				'background:#ef4444',
				'border:3px solid #ffffff',
				'box-shadow:0 3px 10px rgba(0,0,0,0.4)',
				'z-index:2147483646',
				'pointer-events:none',
			].join(';');
			document.body.appendChild(bakedPin);
		}
		await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

		let screenshot = '';
		try {
			console.log('[proofing-pins] capturing; viewport', vw, 'x', vh, 'scroll', sx, sy);
			// Don't skip fonts — font fallback causes different character widths,
			// which shifts text horizontally inside its box and misaligns the pin.
			// Cross-origin stylesheet warnings (console noise) are acceptable cost.
			const fullCanvas = await window.htmlToImage.toCanvas(document.body, {
				backgroundColor: '#ffffff',
				pixelRatio: 1,
				filter: (node) => {
					if (!node || node.nodeType !== 1) return true;
					const id = node.id || '';
					if (id === 'wpadminbar' || id === 'pp-root') return false;
					return true;
				},
			});
			console.log('[proofing-pins] full capture:', fullCanvas.width, 'x', fullCanvas.height,
				'vs body', document.body.scrollWidth, 'x', document.body.scrollHeight);

			// Crop to viewport at scroll position. CRITICAL: destination dims MUST
			// equal source-crop dims so drawImage doesn't scale (any scale => stretch
			// when srcW/srcH aspect ≠ vw/vh aspect, e.g., scrollbar subtracts 15px).
			const scaleX = fullCanvas.width  / Math.max(1, document.body.scrollWidth);
			const scaleY = fullCanvas.height / Math.max(1, document.body.scrollHeight);
			const srcX = Math.max(0, Math.round(sx * scaleX));
			const srcY = Math.max(0, Math.round(sy * scaleY));
			const srcW = Math.min(fullCanvas.width  - srcX, Math.round(vw * scaleX));
			const srcH = Math.min(fullCanvas.height - srcY, Math.round(vh * scaleY));

			const out = document.createElement('canvas');
			out.width  = Math.max(1, srcW);
			out.height = Math.max(1, srcH);
			const ctx = out.getContext('2d');
			ctx.fillStyle = '#ffffff';
			ctx.fillRect(0, 0, out.width, out.height);
			if (srcW > 0 && srcH > 0) {
				// 1:1 copy — source rect and destination rect have identical dims.
				ctx.drawImage(fullCanvas, srcX, srcY, srcW, srcH, 0, 0, srcW, srcH);
			}

			screenshot = out.toDataURL('image/jpeg', 0.85);
			console.log('[proofing-pins] final data URL length:', screenshot.length);
		} catch (err) {
			console.error('[proofing-pins] screenshot failed:', err && err.message, err);
			toast('Screenshot failed: ' + (err && err.message ? err.message : 'unknown'));
		} finally {
			bakedPin.remove();
			if (anchorRestore) {
				anchorRestore.el.style.position = anchorRestore.prev;
			}
		}

		submitBtn.textContent = I.posting;
		try {
			const hp = pc.composer.querySelector('.pp-hp');
			const payload = {
				body,
				page_url: CFG.pageUrl,
				page_title: CFG.pageTitle,
				// New anchor model (source of truth)
				anchor_selector: pc.anchor_selector,
				anchor_xpath:    pc.anchor_xpath,
				anchor_text:     pc.anchor_text,
				offset_x_pct:    pc.offset_x_pct,
				offset_y_pct:    pc.offset_y_pct,
				// Context
				element_html: pc.element_html,
				element_tag:  pc.element_tag,
				viewport_w:   pc.viewport_w,
				viewport_h:   pc.viewport_h,
				scroll_y_pct: pc.scroll_y_pct,
				device_type:  pc.viewport_w < 768 ? 'mobile' : pc.viewport_w < 1024 ? 'tablet' : 'desktop',
				screenshot_data_url: screenshot,
				// Elementor widget identity (explicit, resolved from nearest wrapper)
				elementor_widget_type: pc.elementor_widget_type || '',
				elementor_widget_id:   pc.elementor_widget_id || '',
				hp: hp ? hp.value : '',
			};
			if (CFG.user.isGuest && guestState.identity) {
				payload.guest_name  = guestState.identity.name;
				payload.guest_email = guestState.identity.email;
			}
			const created = await api('POST', 'pins', payload);
			state.pins.push(created);
			closeComposer();
			updateBadge();
			renderPins();
			toast('Pin added');
		} catch (err) {
			const msg = err.status === 429 ? I.rateLimited : ('Failed to save pin: ' + (err.message || 'unknown'));
			toast(msg);
			submitBtn.disabled = false;
			submitBtn.textContent = I.submit;
		}
		state.submitting = false;
	}

	// ---------- thread panel ----------
	let threadEl = null;
	async function openThread(pinId) {
		closeThread();
		state.openPinId = pinId;
		const pin = await api('GET', `pins/${pinId}`).catch(() => null);
		if (!pin) return;
		threadEl = document.createElement('div');
		threadEl.className = 'pp-thread';
		threadEl.innerHTML = buildThreadHtml(pin);
		container.appendChild(threadEl);

		threadEl.querySelector('.pp-thread-close').addEventListener('click', closeThread);
		threadEl.querySelector('[data-act="reply"]').addEventListener('click', () => submitReply(pinId));
		const replyTa = threadEl.querySelector('.pp-thread-reply textarea');
		replyTa.addEventListener('keydown', (e) => { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') submitReply(pinId); });
		if (CFG.user.canManage) {
			const sel = threadEl.querySelector('[data-act="status"]');
			if (sel) sel.addEventListener('change', (e) => updateStatus(pinId, e.target.value));
			const del = threadEl.querySelector('[data-act="delete"]');
			if (del) del.addEventListener('click', () => deletePin(pinId));
		}
	}

	function closeThread() {
		if (threadEl) { threadEl.remove(); threadEl = null; }
		state.openPinId = null;
	}

	function buildThreadHtml(pin) {
		const replies = (pin.replies || []).map(r => `
			<div class="pp-msg">
				<div class="pp-msg-head">
					<img src="${r.avatar_url}" alt="">
					<span class="pp-msg-author">${escapeHtml(r.author_name)}</span>
					<span class="pp-msg-time">${formatTime(r.created_at)}</span>
				</div>
				<div class="pp-msg-body">${escapeHtml(r.body)}</div>
			</div>
		`).join('');
		const statusSel = CFG.user.canManage ? `
			<div class="pp-thread-status">
				<label>Status</label>
				<select data-act="status">
					${Object.entries(STATUS_LABELS).map(([k, v]) => `<option value="${k}" ${k === pin.status ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('')}
				</select>
				<button class="pp-btn pp-btn-ghost" data-act="delete" style="margin-left:auto;color:#ef4444">Delete</button>
			</div>` : '';
		const replyBox = CFG.user.isGuest ? '' : `
			<div class="pp-thread-reply">
				<textarea placeholder="${escapeHtml(I.replyPlaceholder)}" rows="2"></textarea>
				<div class="pp-actions"><button class="pp-btn pp-btn-primary" data-act="reply">${I.reply}</button></div>
			</div>`;
		const avatarOrDot = pin.avatar_url
			? `<img src="${pin.avatar_url}" alt="">`
			: `<div class="pp-guest-dot" style="width:24px;height:24px;"></div>`;
		return `
			<div class="pp-thread-header">
				<div>
					<div style="font-weight:600;font-size:14px;">${escapeHtml(pin.page_title || pin.page_url)}</div>
					<div class="pp-thread-meta"><span class="pp-status" style="background:${STATUS_COLORS[pin.status]}">${escapeHtml(STATUS_LABELS[pin.status])}</span></div>
				</div>
				<button class="pp-thread-close" aria-label="Close">&times;</button>
			</div>
			<div class="pp-thread-body">
				<div class="pp-msg">
					<div class="pp-msg-head">
						${avatarOrDot}
						<span class="pp-msg-author">${escapeHtml(pin.author_name)}</span>
						<span class="pp-msg-time">${formatTime(pin.created_at)}</span>
					</div>
					<div class="pp-msg-body">${escapeHtml(pin.body)}</div>
				</div>
				${replies}
			</div>
			${replyBox}
			${statusSel}
		`;
	}

	async function submitReply(pinId) {
		const ta = threadEl.querySelector('.pp-thread-reply textarea');
		const body = ta.value.trim();
		if (!body) return;
		const btn = threadEl.querySelector('[data-act="reply"]');
		btn.disabled = true;
		try {
			await api('POST', `pins/${pinId}/replies`, { body });
			ta.value = '';
			btn.disabled = false;
			openThread(pinId);
		} catch (err) { btn.disabled = false; toast('Reply failed'); }
	}

	async function updateStatus(pinId, status) {
		try {
			const updated = await api('PATCH', `pins/${pinId}`, { status });
			const idx = state.pins.findIndex(p => p.id === pinId);
			if (idx >= 0) state.pins[idx] = { ...state.pins[idx], ...updated };
			renderPins();
			updateBadge();
			const badge = threadEl.querySelector('.pp-status');
			if (badge) {
				badge.textContent = STATUS_LABELS[status];
				badge.style.background = STATUS_COLORS[status];
			}
			toast('Status updated');
		} catch (err) { toast('Status update failed'); }
	}

	async function deletePin(pinId) {
		if (!confirm(I.deleteConfirm)) return;
		try {
			await api('DELETE', `pins/${pinId}`);
			state.pins = state.pins.filter(p => p.id !== pinId);
			closeThread();
			renderPins();
			updateBadge();
			toast('Pin deleted');
		} catch (err) { toast('Delete failed'); }
	}

	// ---------- utils ----------
	function escapeHtml(s) {
		if (s == null) return '';
		return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
	}
	function formatTime(iso) {
		try { return new Date(iso).toLocaleString(); } catch { return ''; }
	}
	function toast(msg) {
		const t = document.createElement('div');
		t.className = 'pp-toast';
		t.textContent = msg;
		container.appendChild(t);
		requestAnimationFrame(() => t.classList.add('visible'));
		setTimeout(() => { t.classList.remove('visible'); setTimeout(() => t.remove(), 250); }, 2400);
	}

	// ---------- deep-link focus ----------
	(async () => {
		const params = new URLSearchParams(location.search);
		const focusId = parseInt(params.get('pp_focus'), 10);
		if (focusId) {
			if (!state.active) await toggleMode();
			setTimeout(() => {
				const pin = state.pins.find(p => p.id === focusId);
				if (pin && pin.doc_y) {
					window.scrollTo({ top: Math.max(0, pin.doc_y - window.innerHeight / 3), behavior: 'smooth' });
				}
				openThread(focusId);
			}, 300);
		} else {
			// load count badge even when not active
			try {
				const list = await api('GET', `pins?page_url=${encodeURIComponent(CFG.pageUrl)}&status=pp_open`);
				state.pins = list || [];
				updateBadge();
			} catch {}
		}
	})();
})();
