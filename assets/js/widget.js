(() => {
	'use strict';
	if (!window.PROOPIN_CONFIG) return;
	const CFG = window.PROOPIN_CONFIG;
	const I = CFG.i18n;

	const STATUS_LABELS = {
		proopin_open: I.statusOpen,
		proopin_in_progress: I.statusInProgress,
		proopin_resolved: I.statusResolved,
		proopin_archived: I.statusArchived,
	};
	const STATUS_COLORS = {
		proopin_open: '#ef4444',
		proopin_in_progress: '#f59e0b',
		proopin_resolved: '#10b981',
		proopin_archived: '#9ca3af',
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
			sessionStorage.setItem('proopin_topOffset', JSON.stringify({ top, left, t: Date.now() }));
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
	.proopin-toggle {
		position: fixed; bottom: 24px; ${CFG.settings.position === 'left' ? 'left' : 'right'}: 24px;
		width: 52px; height: 52px; border-radius: 50%; border: none; cursor: pointer;
		background: var(--proopin-brand); color: #fff; box-shadow: 0 8px 24px rgba(0,0,0,0.18);
		display: flex; align-items: center; justify-content: center;
		z-index: 2147483646; transition: transform 0.15s ease, box-shadow 0.15s ease;
	}
	.proopin-toggle:hover { transform: scale(1.06); box-shadow: 0 12px 32px rgba(0,0,0,0.22); }
	.proopin-toggle.active { background: #111827; }
	.proopin-toggle svg { width: 22px; height: 22px; }
	.proopin-badge {
		position: absolute; top: -4px; right: -4px;
		background: #ef4444; color: #fff; font-size: 11px; font-weight: 700;
		min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px;
		display: flex; align-items: center; justify-content: center; border: 2px solid #fff;
	}
	.proopin-statusbar {
		position: fixed; top: 0; left: 0; right: 0;
		background: #111827; color: #fff; padding: 10px 16px; font-size: 13px;
		display: flex; align-items: center; justify-content: center; gap: 12px;
		z-index: 2147483645; transform: translateY(-100%); transition: transform 0.2s ease;
	}
	.proopin-statusbar.visible { transform: translateY(0); }
	.proopin-statusbar kbd {
		background: rgba(255,255,255,0.15); border-radius: 4px; padding: 2px 6px;
		font-family: ui-monospace, monospace; font-size: 11px;
	}
	.proopin-pin {
		position: absolute; width: 28px; height: 28px; border-radius: 50% 50% 50% 2px;
		transform: translate(-4px, -24px) rotate(-45deg);
		background: var(--pin-color, #ef4444); color: #fff; border: 2px solid #fff;
		box-shadow: 0 4px 12px rgba(0,0,0,0.25); cursor: pointer;
		display: flex; align-items: center; justify-content: center;
		font-size: 11px; font-weight: 700; z-index: 2147483640;
		transition: transform 0.15s ease;
	}
	.proopin-pin span { transform: rotate(45deg); }
	.proopin-pin:hover { transform: translate(-4px, -28px) rotate(-45deg) scale(1.1); }
	.proopin-pin.pending { opacity: 0.55; }
	.proopin-composer, .proopin-thread {
		position: fixed; background: #fff; border-radius: 12px;
		box-shadow: 0 20px 50px rgba(0,0,0,0.2), 0 0 0 1px rgba(0,0,0,0.05);
		z-index: 2147483641; overflow: hidden;
	}
	.proopin-composer { width: 320px; padding: 14px; }
	.proopin-composer-user { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 12px; color: #6b7280; }
	.proopin-composer-user img { width: 24px; height: 24px; border-radius: 50%; }
	.proopin-composer textarea {
		width: 100%; min-height: 80px; padding: 10px; border: 1px solid #e5e7eb;
		border-radius: 8px; font: inherit; font-size: 14px; resize: vertical;
	}
	.proopin-composer textarea:focus { outline: none; border-color: var(--proopin-brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--proopin-brand) 20%, transparent); }
	.proopin-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 10px; }
	.proopin-btn {
		padding: 8px 14px; border-radius: 7px; font-size: 13px; font-weight: 500;
		border: none; cursor: pointer; transition: background 0.12s ease;
	}
	.proopin-btn-primary { background: var(--proopin-brand); color: #fff; }
	.proopin-btn-primary:hover { filter: brightness(1.08); }
	.proopin-btn-primary:disabled { opacity: 0.6; cursor: wait; }
	.proopin-btn-ghost { background: transparent; color: #6b7280; }
	.proopin-btn-ghost:hover { background: #f3f4f6; }
	.proopin-status {
		font-size: 11px; font-weight: 600; text-transform: uppercase;
		letter-spacing: 0.04em; padding: 2px 8px; border-radius: 10px;
		color: #fff; display: inline-block;
	}
	.proopin-thread {
		right: 20px; top: 20px; bottom: 20px; width: 360px;
		display: flex; flex-direction: column;
	}
	.proopin-thread-header { padding: 16px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between; }
	.proopin-thread-meta { font-size: 12px; color: #6b7280; }
	.proopin-thread-close { background: transparent; border: none; cursor: pointer; font-size: 20px; color: #9ca3af; padding: 4px; }
	.proopin-thread-body { flex: 1; overflow-y: auto; padding: 14px 16px; }
	.proopin-msg { margin-bottom: 14px; }
	.proopin-msg-head { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
	.proopin-msg-head img { width: 24px; height: 24px; border-radius: 50%; }
	.proopin-msg-author { font-weight: 600; font-size: 13px; color: #111827; }
	.proopin-msg-time { font-size: 11px; color: #9ca3af; }
	.proopin-msg-body { font-size: 14px; line-height: 1.5; color: #374151; white-space: pre-wrap; word-wrap: break-word; padding-left: 32px; }
	.proopin-thread-reply { padding: 12px 16px; border-top: 1px solid #f3f4f6; }
	.proopin-thread-reply textarea {
		width: 100%; min-height: 60px; padding: 8px; border: 1px solid #e5e7eb;
		border-radius: 8px; font: inherit; font-size: 13px; resize: vertical;
	}
	.proopin-thread-reply textarea:focus { outline: none; border-color: var(--proopin-brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--proopin-brand) 20%, transparent); }
	.proopin-thread-status { padding: 10px 16px; border-top: 1px solid #f3f4f6; display: flex; align-items: center; gap: 8px; background: #fafafa; font-size: 12px; }
	.proopin-thread-status select { font: inherit; font-size: 12px; padding: 4px 8px; border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; }
	.proopin-toast {
		position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%) translateY(20px);
		background: #111827; color: #fff; padding: 10px 16px; border-radius: 8px;
		font-size: 13px; z-index: 2147483647; opacity: 0; transition: all 0.2s ease;
	}
	.proopin-toast.visible { opacity: 1; transform: translateX(-50%) translateY(0); }
	body.proopin-mode-active, body.proopin-mode-active * { cursor: crosshair !important; }
	.proopin-hp { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
	.proopin-guest-dot { width: 24px; height: 24px; border-radius: 50%; background: linear-gradient(135deg, var(--proopin-brand), #9333ea); flex-shrink: 0; }
	.proopin-identity-modal { position: fixed; inset: 0; z-index: 2147483647; display: flex; align-items: center; justify-content: center; }
	.proopin-identity-backdrop { position: absolute; inset: 0; background: rgba(15,23,42,0.55); backdrop-filter: blur(2px); }
	.proopin-identity-card { position: relative; width: 360px; background: #fff; border-radius: 12px; padding: 22px; box-shadow: 0 30px 80px rgba(0,0,0,0.35); }
	.proopin-identity-card h3 { margin: 0 0 14px; font-size: 16px; color: #0f172a; }
	.proopin-identity-card label { display: block; font-size: 12px; color: #6b7280; margin: 10px 0 4px; font-weight: 600; }
	.proopin-identity-card input { width: 100%; padding: 9px 11px; border: 1px solid #e5e7eb; border-radius: 7px; font: inherit; font-size: 14px; }
	.proopin-identity-card input:focus { outline: none; border-color: var(--proopin-brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--proopin-brand) 20%, transparent); }
	.proopin-identity-hint { font-size: 11px; color: #94a3b8; margin: 10px 0 14px; }
	`;

	// ---------- root setup ----------
	const rootEl = document.getElementById('proopin-root');
	if (!rootEl) return;
	const shadow = rootEl.attachShadow({ mode: 'open' });

	const styleEl = document.createElement('style');
	styleEl.textContent = css;
	shadow.appendChild(styleEl);

	const container = document.createElement('div');
	container.style.setProperty('--proopin-brand', CFG.settings.brand_color || '#2271b1');
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
	const COOKIE = 'proopin_guest_identity';
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

	// ---------- UI: toggle button ----------
	function buildToggle() {
		const btn = document.createElement('button');
		btn.className = 'proopin-toggle';
		btn.title = I.toggleOn;
		btn.setAttribute('aria-label', I.toggleOn);
		btn.innerHTML = `
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
			</svg>
			<span class="proopin-badge" hidden>0</span>
		`;
		btn.addEventListener('click', toggleMode);
		return btn;
	}

	// ---------- statusbar ----------
	function buildStatusbar() {
		const bar = document.createElement('div');
		bar.className = 'proopin-statusbar';
		bar.innerHTML = `<span>${I.prompt}</span> <kbd>Esc</kbd>`;
		return bar;
	}

	const toggleBtn = buildToggle();
	const statusbar = buildStatusbar();
	container.appendChild(toggleBtn);
	container.appendChild(statusbar);

	function updateBadge() {
		const open = state.pins.filter(p => p.status === 'proopin_open').length;
		const badge = toggleBtn.querySelector('.proopin-badge');
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
			node.className = 'proopin-pin';
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
		if (!pin.anchor_selector && !pin.anchor_xpath) return null;
		const el = findAnchor(pin.anchor_selector, pin.anchor_xpath, pin.anchor_text);
		if (!el) return null;
		const r = el.getBoundingClientRect();
		if (r.width <= 0 || r.height <= 0) return null;
		return {
			x: r.left + r.width  * (pin.offset_x_pct != null ? pin.offset_x_pct : 0.5) + window.scrollX,
			y: r.top  + r.height * (pin.offset_y_pct != null ? pin.offset_y_pct : 0.5) + window.scrollY,
		};
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
		document.body.classList.toggle('proopin-mode-active', state.active);
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
			modal.className = 'proopin-identity-modal';
			modal.innerHTML = `
				<div class="proopin-identity-backdrop"></div>
				<div class="proopin-identity-card">
					<h3>${escapeHtml(I.guestIntro)}</h3>
					<label>${escapeHtml(I.guestName)}</label>
					<input type="text" id="proopin-g-name" autocomplete="name">
					<label>${escapeHtml(I.guestEmail)}</label>
					<input type="email" id="proopin-g-email" autocomplete="email">
					<p class="proopin-identity-hint">${escapeHtml(I.guestRemembered)}</p>
					<div class="proopin-actions">
						<button class="proopin-btn proopin-btn-ghost" data-act="cancel">${escapeHtml(I.cancel)}</button>
						<button class="proopin-btn proopin-btn-primary" data-act="ok">${escapeHtml(I.guestContinue)}</button>
					</div>
				</div>
			`;
			container.appendChild(modal);
			const nameEl  = modal.querySelector('#proopin-g-name');
			const emailEl = modal.querySelector('#proopin-g-email');
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
		composer.className = 'proopin-composer';
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
			? `<div class="proopin-guest-dot"></div>`
			: `<img src="${CFG.user.avatar}" alt="">`;
		composer.innerHTML = `
			<div class="proopin-composer-user">
				${avatarHtml}
				<span>${I.postedBy} ${escapeHtml(who)}</span>
			</div>
			<textarea placeholder="${escapeHtml(I.placeholder)}" rows="3"></textarea>
			<input type="text" name="hp" tabindex="-1" autocomplete="off" class="proopin-hp" aria-hidden="true">
			<div class="proopin-actions">
				<button class="proopin-btn proopin-btn-ghost" data-act="cancel">${I.cancel}</button>
				<button class="proopin-btn proopin-btn-primary" data-act="submit">${I.submit}</button>
			</div>
		`;
		container.appendChild(composer);
		const ta = composer.querySelector('textarea');
		ta.focus();

		// provisional pin marker — document-absolute so it stays with content on scroll
		const docX = x + window.scrollX;
		const docY = y + window.scrollY;
		const marker = document.createElement('button');
		marker.className = 'proopin-pin pending';
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
		bakedPin.id = 'proopin-baked-pin';
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
			// Don't skip fonts — font fallback causes different character widths,
			// which shifts text horizontally inside its box and misaligns the pin.
			// Cross-origin stylesheet warnings (console noise) are acceptable cost.
			const fullCanvas = await window.htmlToImage.toCanvas(document.body, {
				backgroundColor: '#ffffff',
				pixelRatio: 1,
				filter: (node) => {
					if (!node || node.nodeType !== 1) return true;
					const id = node.id || '';
					if (id === 'wpadminbar' || id === 'proopin-root') return false;
					return true;
				},
			});

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
		} catch (err) {
			toast('Screenshot failed: ' + (err && err.message ? err.message : 'unknown'));
		} finally {
			bakedPin.remove();
			if (anchorRestore) {
				anchorRestore.el.style.position = anchorRestore.prev;
			}
		}

		submitBtn.textContent = I.posting;
		try {
			const hp = pc.composer.querySelector('.proopin-hp');
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
		threadEl.className = 'proopin-thread';
		threadEl.innerHTML = buildThreadHtml(pin);
		container.appendChild(threadEl);

		threadEl.querySelector('.proopin-thread-close').addEventListener('click', closeThread);
		const replyBtn = threadEl.querySelector('[data-act="reply"]');
		if (replyBtn) replyBtn.addEventListener('click', () => submitReply(pinId));
		const replyTa = threadEl.querySelector('.proopin-thread-reply textarea');
		if (replyTa) replyTa.addEventListener('keydown', (e) => { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') submitReply(pinId); });
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
			<div class="proopin-msg">
				<div class="proopin-msg-head">
					<img src="${r.avatar_url}" alt="">
					<span class="proopin-msg-author">${escapeHtml(r.author_name)}</span>
					<span class="proopin-msg-time">${formatTime(r.created_at)}</span>
				</div>
				<div class="proopin-msg-body">${escapeHtml(r.body)}</div>
			</div>
		`).join('');
		const statusSel = CFG.user.canManage ? `
			<div class="proopin-thread-status">
				<label>Status</label>
				<select data-act="status">
					${Object.entries(STATUS_LABELS).map(([k, v]) => `<option value="${k}" ${k === pin.status ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('')}
				</select>
				<button class="proopin-btn proopin-btn-ghost" data-act="delete" style="margin-left:auto;color:#ef4444">Delete</button>
			</div>` : '';
		const replyBox = CFG.user.isGuest ? '' : `
			<div class="proopin-thread-reply">
				<textarea placeholder="${escapeHtml(I.replyPlaceholder)}" rows="2"></textarea>
				<div class="proopin-actions"><button class="proopin-btn proopin-btn-primary" data-act="reply">${I.reply}</button></div>
			</div>`;
		const avatarOrDot = pin.avatar_url
			? `<img src="${pin.avatar_url}" alt="">`
			: `<div class="proopin-guest-dot" style="width:24px;height:24px;"></div>`;
		return `
			<div class="proopin-thread-header">
				<div>
					<div style="font-weight:600;font-size:14px;">${escapeHtml(pin.page_title || pin.page_url)}</div>
					<div class="proopin-thread-meta"><span class="proopin-status" style="background:${STATUS_COLORS[pin.status]}">${escapeHtml(STATUS_LABELS[pin.status])}</span></div>
				</div>
				<button class="proopin-thread-close" aria-label="Close">&times;</button>
			</div>
			<div class="proopin-thread-body">
				<div class="proopin-msg">
					<div class="proopin-msg-head">
						${avatarOrDot}
						<span class="proopin-msg-author">${escapeHtml(pin.author_name)}</span>
						<span class="proopin-msg-time">${formatTime(pin.created_at)}</span>
					</div>
					<div class="proopin-msg-body">${escapeHtml(pin.body)}</div>
				</div>
				${replies}
			</div>
			${replyBox}
			${statusSel}
		`;
	}

	async function submitReply(pinId) {
		const ta = threadEl.querySelector('.proopin-thread-reply textarea');
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
			const badge = threadEl.querySelector('.proopin-status');
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
		t.className = 'proopin-toast';
		t.textContent = msg;
		container.appendChild(t);
		requestAnimationFrame(() => t.classList.add('visible'));
		setTimeout(() => { t.classList.remove('visible'); setTimeout(() => t.remove(), 250); }, 2400);
	}

	// ---------- deep-link focus ----------
	(async () => {
		const params = new URLSearchParams(location.search);
		const focusId = parseInt(params.get('proopin_focus'), 10);
		if (focusId) {
			if (!state.active) await toggleMode();
			setTimeout(() => {
				openThread(focusId);
			}, 300);
		} else {
			// load count badge even when not active
			try {
				const list = await api('GET', `pins?page_url=${encodeURIComponent(CFG.pageUrl)}&status=proopin_open`);
				state.pins = list || [];
				updateBadge();
			} catch {}
		}
	})();
})();
