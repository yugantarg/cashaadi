/* ==========================================================================
   CAShaadi UI — Signup
   --------------------------------------------------------------------------
   Auto-fill + hide the redundant Username field on the register page so the
   required field submits a valid value (discarded server-side; the real
   username is the email hash). Extracted verbatim from WPCode #11842.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', function () {
	var u = document.getElementById('signup_username');
	if (u) {
		if (!u.value) { u.value = 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }
		u.removeAttribute('required');
		u.style.display = 'none';
		var l = document.querySelector('label[for=signup_username]');
		if (l) { l.style.display = 'none'; }
	}
});

/* ==========================================================================
   Gender as radio buttons on the register form (Wave 2).
   Owner: "gender should be a radio on sign up page." Non-destructive — the
   xProfile field stays a selectbox; we swap its rendering to radios so the two
   options are a single tap, and submit the same field_299 value BuddyPress
   expects.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', function () {
	var sel = document.getElementById('field_299');
	if (!sel || sel.tagName !== 'SELECT') { return; }

	var wrap = document.createElement('div');
	wrap.className = 'csm-gender-radios';
	var required = sel.hasAttribute('required') || sel.getAttribute('aria-required') === 'true';

	Array.prototype.forEach.call(sel.options, function (opt) {
		var v = opt.value;
		var text = (opt.text || '').trim();
		if (!v || v === '----' || text === '----') { return; } // skip placeholder

		var label = document.createElement('label');
		label.className = 'csm-gender-radio';
		var input = document.createElement('input');
		input.type = 'radio';
		input.name = 'field_299';
		input.value = v;
		if (opt.selected) { input.checked = true; }
		if (required) { input.required = true; }
		label.appendChild(input);
		label.appendChild(document.createTextNode(' ' + text));
		wrap.appendChild(label);
	});

	if (wrap.children.length) { sel.parentNode.replaceChild(wrap, sel); }
});
