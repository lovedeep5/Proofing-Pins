(() => {
	'use strict';
	if (!window.PROOPIN_ADMIN) return;

	async function api(method, path, body) {
		const res = await fetch(PROOPIN_ADMIN.restUrl + path.replace(/^\//, ''), {
			method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PROOPIN_ADMIN.nonce },
			credentials: 'same-origin',
			body: body ? JSON.stringify(body) : undefined,
		});
		if (!res.ok) throw new Error(`HTTP ${res.status}`);
		return res.json();
	}

	const side = document.querySelector('.proopin-detail-side');
	if (side) {
		const pinId = side.getAttribute('data-pin-id');

		const replyBtn = document.getElementById('proopin-reply-btn');
		if (replyBtn) {
			replyBtn.addEventListener('click', async () => {
				const ta = document.getElementById('proopin-reply-body');
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

		const statusSel = document.getElementById('proopin-status-select');
		if (statusSel) {
			statusSel.addEventListener('change', async (e) => {
				try {
					await api('PATCH', `pins/${pinId}`, { status: e.target.value });
				} catch (err) { alert('Status update failed'); }
			});
		}

		const deleteBtn = document.getElementById('proopin-delete-btn');
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
	const aiBlock = document.getElementById('proopin-ai-block');
	if (aiBlock) {
		const aiPinId = aiBlock.getAttribute('data-pin-id');
		const regenBtn = document.getElementById('proopin-ai-regen');
		const bodyEl   = aiBlock.querySelector('.proopin-ai-body');

		async function regenerate() {
			if (regenBtn) { regenBtn.disabled = true; regenBtn.textContent = 'Generating…'; }
			bodyEl.innerHTML = '<p class="proopin-ai-placeholder">Generating suggestion…</p>';
			try {
				const res = await fetch(PROOPIN_ADMIN.restUrl + `pins/${aiPinId}/ai-suggest`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PROOPIN_ADMIN.nonce },
					credentials: 'same-origin'
				});
				const json = await res.json();
				if (res.ok && json.status === 'ready') {
					location.reload();
				} else {
					bodyEl.innerHTML = `<p class="proopin-ai-err">${(json.message || 'Generation failed')}</p>`;
					if (regenBtn) { regenBtn.disabled = false; regenBtn.textContent = 'Retry'; }
				}
			} catch (err) {
				bodyEl.innerHTML = `<p class="proopin-ai-err">${err.message}</p>`;
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
			const btn = e.target.closest('.proopin-ai-copy');
			if (!btn) return;
			const snippet = aiBlock.querySelector('.proopin-ai-snippet code');
			if (!snippet) return;
			try {
				await navigator.clipboard.writeText(snippet.textContent);
				btn.textContent = 'Copied!';
				setTimeout(() => { btn.textContent = 'Copy snippet'; }, 1500);
			} catch {}
		});
	}

	// --- Apply / Revert (Elementor change_op) ---
	const applyCard = document.getElementById('proopin-apply-card');
	if (applyCard) {
		const applyPinId = applyCard.getAttribute('data-pin-id');
		const applyBtn   = document.getElementById('proopin-apply-btn');
		const revertBtn  = document.getElementById('proopin-revert-btn');

		if (applyBtn) {
			applyBtn.addEventListener('click', async () => {
				if (!confirm('Apply this change to the live Elementor page? A revision will be saved so you can revert.')) return;
				applyBtn.disabled = true;
				applyBtn.textContent = 'Applying…';
				try {
					const res = await fetch(PROOPIN_ADMIN.restUrl + `pins/${applyPinId}/apply`, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PROOPIN_ADMIN.nonce },
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
					const res = await fetch(PROOPIN_ADMIN.restUrl + `pins/${applyPinId}/revert`, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': PROOPIN_ADMIN.nonce },
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
