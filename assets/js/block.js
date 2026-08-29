/**
 * Block module (#11810) — delegated click handler for the Block/Unblock buttons
 * (profile header + Settings -> Blocked list). Behaviour is identical to the
 * snippet's wp_footer inline script; config comes from window.CASHAADI_BLOCK
 * (set via wp_add_inline_script: { ajax, membersUrl }).
 */
(function () {
	var CFG = window.CASHAADI_BLOCK || {};
	var AJAX = CFG.ajax || '';

	document.addEventListener('click', function (e) {
		var b = e.target.closest('.csm-bl-btn');
		if (!b) { return; }
		e.preventDefault();

		var doAct = b.getAttribute('data-do');
		if (doAct === 'block' && !window.confirm('Block this member? You will no longer see each other, and any match or messages between you will be removed.')) {
			return;
		}

		b.disabled = true;
		var body = 'action=csm_bl_toggle&do=' + encodeURIComponent(doAct) +
			'&target=' + encodeURIComponent(b.getAttribute('data-target')) +
			'&nonce=' + encodeURIComponent(b.getAttribute('data-nonce'));

		fetch(AJAX, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					if (res.data.state === 'blocked') {
						window.location = CFG.membersUrl || '/';
					} else {
						window.location.reload();
					}
				} else {
					b.disabled = false;
					alert((res && res.data && res.data.message) || 'Something went wrong.');
				}
			})
			.catch(function () { b.disabled = false; });
	});
})();
