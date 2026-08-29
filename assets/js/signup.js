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
