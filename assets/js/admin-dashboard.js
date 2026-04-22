(() => {
	'use strict';
	if (!window.PP_ADMIN) return;

	async function api(method, path, body) {
		const res = await fetch(PP_ADMIN.restUrl + path.replace(/^\//, ''), {
			method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PP_ADMIN.nonce },
			credentials: 'same-origin',
			body: body ? JSON.stringify(body) : undefined,
		});
		if (!res.ok) throw new Error(`HTTP ${res.status}`);
		return res.json();
	}

	const side = document.querySelector('.pp-detail-side');
	if (side) {
		const pinId = side.getAttribute('data-pin-id');

		const replyBtn = document.getElementById('pp-reply-btn');
		if (replyBtn) {
			replyBtn.addEventListener('click', async () => {
				const ta = document.getElementById('pp-reply-body');
				const body = (ta.value || '').trim();
				if (!body) return;
				replyBtn.disabled = true;
				try {
					await api('POST', `pins/${pinId}/replies`, { body });
					location.reload();
				} catch (err) {
					alert('Reply failed');
					replyBtn.disabled = false;
				}
			});
		}

		const statusSel = document.getElementById('pp-status-select');
		if (statusSel) {
			statusSel.addEventListener('change', async (e) => {
				try {
					await api('PATCH', `pins/${pinId}`, { status: e.target.value });
				} catch (err) { alert('Status update failed'); }
			});
		}

		const deleteBtn = document.getElementById('pp-delete-btn');
		if (deleteBtn) {
			deleteBtn.addEventListener('click', async () => {
				if (!confirm('Delete this pin permanently?')) return;
				deleteBtn.disabled = true;
				try {
					await api('DELETE', `pins/${pinId}`);
					location.href = new URL('admin.php?page=proofing-pins', location.href).toString();
				} catch (err) {
					alert('Delete failed');
					deleteBtn.disabled = false;
				}
			});
		}
	}

	// --- AI suggestion: regenerate + auto-poll when queued/running ---
	const aiBlock = document.getElementById('pp-ai-block');
	if (aiBlock) {
		const aiPinId = aiBlock.getAttribute('data-pin-id');
		const regenBtn = document.getElementById('pp-ai-regen');
		const bodyEl   = aiBlock.querySelector('.pp-ai-body');

		async function regenerate() {
			if (regenBtn) { regenBtn.disabled = true; regenBtn.textContent = 'Generating…'; }
			bodyEl.innerHTML = '<p class="pp-ai-placeholder">Generating suggestion…</p>';
			try {
				const res = await fetch(PP_ADMIN.restUrl + `pins/${aiPinId}/ai-suggest`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PP_ADMIN.nonce },
					credentials: 'same-origin'
				});
				const json = await res.json();
				if (res.ok && json.status === 'ready') {
					location.reload();
				} else {
					bodyEl.innerHTML = `<p class="pp-ai-err">${(json.message || 'Generation failed')}</p>`;
					if (regenBtn) { regenBtn.disabled = false; regenBtn.textContent = 'Retry'; }
				}
			} catch (err) {
				bodyEl.innerHTML = `<p class="pp-ai-err">${err.message}</p>`;
				if (regenBtn) { regenBtn.disabled = false; regenBtn.textContent = 'Retry'; }
			}
		}

		if (regenBtn) regenBtn.addEventListener('click', regenerate);

		// If the pin is still queued/running when the page loads, try to kick it synchronously.
		const initialStatus = aiBlock.getAttribute('data-status');
		if (initialStatus === 'queued' || initialStatus === 'running') {
			setTimeout(regenerate, 500);
		}

		aiBlock.addEventListener('click', async (e) => {
			const btn = e.target.closest('.pp-ai-copy');
			if (!btn) return;
			const snippet = aiBlock.querySelector('.pp-ai-snippet code');
			if (!snippet) return;
			try {
				await navigator.clipboard.writeText(snippet.textContent);
				btn.textContent = 'Copied!';
				setTimeout(() => { btn.textContent = 'Copy snippet'; }, 1500);
			} catch {}
		});
	}

	// --- Apply / Revert (Elementor change_op) ---
	const applyCard = document.getElementById('pp-apply-card');
	if (applyCard) {
		const applyPinId = applyCard.getAttribute('data-pin-id');
		const applyBtn   = document.getElementById('pp-apply-btn');
		const revertBtn  = document.getElementById('pp-revert-btn');

		if (applyBtn) {
			applyBtn.addEventListener('click', async () => {
				if (!confirm('Apply this change to the live Elementor page? A revision will be saved so you can revert.')) return;
				applyBtn.disabled = true;
				applyBtn.textContent = 'Applying…';
				try {
					const res = await fetch(PP_ADMIN.restUrl + `pins/${applyPinId}/apply`, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PP_ADMIN.nonce },
						credentials: 'same-origin'
					});
					const json = await res.json();
					if (res.ok && json.ok) {
						location.reload();
					} else {
						alert('Apply failed: ' + (json.message || 'unknown'));
						applyBtn.disabled = false;
						applyBtn.textContent = 'Apply to Elementor';
					}
				} catch (err) {
					alert('Apply failed: ' + err.message);
					applyBtn.disabled = false;
					applyBtn.textContent = 'Apply to Elementor';
				}
			});
		}

		if (revertBtn) {
			revertBtn.addEventListener('click', async () => {
				if (!confirm('Revert this change? The original value will be restored on the Elementor page.')) return;
				revertBtn.disabled = true;
				revertBtn.textContent = 'Reverting…';
				try {
					const res = await fetch(PP_ADMIN.restUrl + `pins/${applyPinId}/revert`, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PP_ADMIN.nonce },
						credentials: 'same-origin'
					});
					const json = await res.json();
					if (res.ok && json.ok) {
						location.reload();
					} else {
						alert('Revert failed: ' + (json.message || 'unknown'));
						revertBtn.disabled = false;
						revertBtn.textContent = 'Revert change';
					}
				} catch (err) {
					alert('Revert failed: ' + err.message);
					revertBtn.disabled = false;
					revertBtn.textContent = 'Revert change';
				}
			});
		}
	}
})();
