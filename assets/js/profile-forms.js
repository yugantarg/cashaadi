/* ==========================================================================
   CAShaadi UI — Profile form field UX
   --------------------------------------------------------------------------
   Real-asset replacement for the inline <script> in WPCode #11797 (height
   guard), #11621 (gender lock UX) and #11625 (email lock). Behaviour is driven
   by window.CASHAADI_FORMS, set server-side by the profile-edit module.

   Double-safe: it reuses the SAME guard markers as the original snippets
   (data-csm-hg, dataset.csmLocked), so while a snippet is still active only the
   first of the two to run does the work — the other sees the marker and stops.
   ========================================================================== */
(function () {
	"use strict";
	var CFG = window.CASHAADI_FORMS || {};

	function ready(fn) {
		if (document.readyState === "loading") {
			document.addEventListener("DOMContentLoaded", fn);
		} else {
			fn();
		}
	}

	/* ---- Height guard (field 228) — cm validation, 100–260 --------------- */
	function heightGuard() {
		if (!CFG.heightGuard) { return; }
		var id = CFG.height || 228;
		var MIN = 100, MAX = 260;
		var inp = document.getElementById("field_" + id) || document.querySelector('input[name="field_' + id + '"]');
		if (!inp || inp.getAttribute("data-csm-hg")) { return; } // shared marker with #11797
		inp.setAttribute("data-csm-hg", "1");
		inp.setAttribute("min", MIN);
		inp.setAttribute("max", MAX);
		inp.setAttribute("step", "1");
		inp.setAttribute("inputmode", "numeric");

		var warn = document.createElement("div");
		warn.className = "csm-hg-warn";
		warn.textContent = "Please enter your height in centimetres (e.g. 170). It looks like you may have entered feet.";
		if (inp.parentNode) { inp.parentNode.appendChild(warn); }

		function check() {
			var raw = (inp.value || "").trim();
			if (raw === "") {
				warn.classList.remove("show");
				inp.classList.remove("csm-hg-bad");
				inp.setCustomValidity("");
				return true;
			}
			var v = parseFloat(raw);
			var ok = !isNaN(v) && v >= MIN && v <= MAX;
			if (ok) {
				warn.classList.remove("show");
				inp.classList.remove("csm-hg-bad");
				inp.setCustomValidity("");
			} else {
				warn.classList.add("show");
				inp.classList.add("csm-hg-bad");
				inp.setCustomValidity("Enter your height in centimetres (100-260).");
			}
			return ok;
		}

		inp.addEventListener("input", check);
		inp.addEventListener("blur", check);
		var form = inp.closest("form");
		if (form) {
			form.addEventListener("submit", function (e) {
				if (!check()) { e.preventDefault(); inp.focus(); }
			}, true);
		}
		check();
	}

	/* ---- Gender lock UX (field 299) — disable select when already set ---- */
	function genderLock() {
		if (!CFG.genderLocked) { return; }
		var id = CFG.gender || 299;
		function lock() {
			var sel = document.getElementById("field_" + id) ||
				document.querySelector(".editfield.field_" + id + " select");
			if (!sel) { return setTimeout(lock, 400); }
			if (sel.dataset.csmLocked) { return; } // shared marker with #11621
			sel.dataset.csmLocked = "1";
			sel.disabled = true;
			// A disabled select is not submitted; mirror its value in a hidden input
			// so it survives save (the server-side filter is the real guard).
			var hid = document.createElement("input");
			hid.type = "hidden"; hid.name = sel.name; hid.value = sel.value;
			sel.parentNode.insertBefore(hid, sel.nextSibling);
			var note = document.createElement("span");
			note.className = "csm-gender-lock-note";
			note.textContent = "Gender is set during sign-up and cannot be changed.";
			hid.parentNode.insertBefore(note, hid.nextSibling);
			note.style.display = "block";
		}
		lock();
	}

	/* ---- Email lock (settings/general) — key account field -------------- */
	function emailLock() {
		if (!CFG.emailLock) { return; }
		var f = document.getElementById("email");
		if (!f) { return; }
		// If a snippet already locked it, don't add a second note.
		if (f.readOnly || f.disabled || document.querySelector(".csm-email-lock-note")) { return; }
		f.setAttribute("disabled", "disabled");
		f.setAttribute("readonly", "readonly");
		f.style.background = "#f0f0f0";
		f.style.cursor = "not-allowed";
		var note = document.createElement("p");
		note.className = "csm-email-lock-note";
		note.textContent = "Your email address is linked to your account and cannot be changed here. Contact support if you need to update it.";
		if (f.parentNode) { f.parentNode.appendChild(note); }
	}

	ready(function () {
		try { heightGuard(); } catch (e) {}
		try { genderLock(); } catch (e) {}
		try { emailLock(); } catch (e) {}
	});
})();
