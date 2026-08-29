/* CAShaadi UI — Discover
 * Migrated from the inline scripts in WPCode #11602 (tray like/pass) and #11681
 * (header compass entry point). Config comes from window.CASHAADI_DISCOVER
 * (ajaxUrl, nonce, discoverUrl) injected via wp_add_inline_script.
 */
(function () {
	var CFG = window.CASHAADI_DISCOVER || {};

	/* ---- header compass entry point (#11681 part 1) --------------------- */
	function addDiscoverIcon() {
		var wrap = document.querySelector('.buddyx-mobile-icon');
		if (!wrap || wrap.querySelector('.csm-discover-icon')) { return; }
		var div = document.createElement('div');
		div.className = 'csm-discover-icon';
		var a = document.createElement('a');
		a.className = 'bp-icon-wrap';
		a.href = CFG.discoverUrl || '/discover/';
		a.title = 'Discover';
		var s = document.createElement('span');
		s.className = 'fa fa-compass';
		a.appendChild(s);
		var t = document.createElement('span');
		t.className = 'csm-discover-label';
		t.appendChild(document.createTextNode('Discover'));
		a.appendChild(t);
		div.appendChild(a);
		wrap.insertBefore(div, wrap.firstElementChild);
	}

	/* ---- tray like/pass (#11602) ---------------------------------------- */
	function initTray() {
		var tray = document.getElementById('csm-discovery-tray');
		if (!tray) { return; }

		var ajaxUrl = CFG.ajaxUrl;
		var nonce = CFG.nonce;

		function showToast(msg) {
			var t = document.getElementById('csm-tray-toast');
			if (!t) { return; }
			t.textContent = msg;
			t.style.display = 'block';
			setTimeout(function () { t.style.display = 'none'; }, 4000);
		}

		function checkEmpty() {
			if (!tray.querySelector('.csm-card')) {
				var msg = document.createElement('div');
				msg.className = 'csm-tray-msg csm-tray-empty';
				msg.textContent = "You're all caught up! New profiles arrive every week. Check back soon.";
				tray.insertBefore(msg, tray.firstChild);
			}
		}

		function enable(card) {
			card.querySelectorAll('.csm-btn').forEach(function (b) { b.disabled = false; });
		}
		function disable(card) {
			card.querySelectorAll('.csm-btn').forEach(function (b) { b.disabled = true; });
		}

		function act(action, profileId, card, onDone) {
			var body = 'action=' + encodeURIComponent(action)
				+ '&nonce=' + encodeURIComponent(nonce)
				+ '&profile_id=' + encodeURIComponent(profileId);
			fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						card.classList.add('csm-removing');
						setTimeout(function () {
							card.parentNode && card.parentNode.removeChild(card);
							checkEmpty();
						}, 250);
						if (typeof onDone === 'function') { onDone(res.data || {}); }
					} else {
						enable(card);
						showToast('Something went wrong. Please try again.');
					}
				})
				.catch(function () {
					enable(card);
					showToast('Network error. Please try again.');
				});
		}

		tray.addEventListener('click', function (e) {
			var btn = e.target.closest('.csm-btn');
			if (!btn) { return; }
			var card = btn.closest('.csm-card');
			if (!card) { return; }
			var pid = card.getAttribute('data-profile-id');
			disable(card);
			if (btn.classList.contains('csm-like')) {
				act('csm_like_profile', pid, card, function (data) {
					if (data.is_mutual) {
						showToast('\u{1F389} It’s a match! You both liked each other.');
					}
				});
			} else if (btn.classList.contains('csm-pass')) {
				act('csm_pass_profile', pid, card, function () { /* passed */ });
			}
		});
	}

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else { fn(); }
	}
	ready(function () { addDiscoverIcon(); initTray(); });
})();
