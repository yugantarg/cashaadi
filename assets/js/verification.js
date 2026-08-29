/* ==========================================================================
   CAShaadi UI — Verification
   --------------------------------------------------------------------------
   Verified-CA badge injection via the csm/v1/verified REST route (#11701) and
   the OTP checklist patch on the own-profile completion meter (#11682).
   Reads window.CASHAADI_VERIFY (set server-side).
   ========================================================================== */
(function () {
	"use strict";
	var C = window.CASHAADI_VERIFY || {};

	/* ---- verified badge (#11701) ---- */
	function svgFor(color) {
		return '<svg viewBox="0 0 24 24" fill="none"><path d="M12 2l2.4 1.7 2.9-.3 1.2 2.7 2.6 1.4-.6 2.9 1 2.8-2.2 1.9-.4 2.9-2.9.4-1.9 2.2-2.7-1.1-2.7 1.1-1.9-2.2-2.9-.4-.4-2.9L1 12.6l1-2.8-.6-2.9L4 5.5 5.2 2.8l2.9.3z" fill="' + color + '"/><path d="M9.3 12.2l1.9 1.9 3.6-3.9" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}
	function badgeConf(level) {
		if (level === "inter") { return { color: "#d9a441", label: "Verified CA Inter" }; }
		if (level === "ca") { return { color: "#1d9bf0", label: "Verified CA" }; }
		return { color: "#1d9bf0", label: "Verified" };
	}
	function makeBadge(level) {
		var conf = badgeConf(level);
		var s = document.createElement("span");
		s.className = "csm-verified";
		s.innerHTML = svgFor(conf.color) + '<span class="csm-tip">' + conf.label + "</span>";
		s.addEventListener("click", function (e) { e.stopPropagation(); s.classList.toggle("csm-open"); });
		return s;
	}
	function inject(el, level) {
		if (!el) { return; }
		el.querySelectorAll(".csm-verified").forEach(function (n) { n.remove(); });
		el.appendChild(makeBadge(level));
	}
	function collect() {
		var map = {};
		document.querySelectorAll(".csm-card[data-profile-id]").forEach(function (c) {
			var id = c.getAttribute("data-profile-id");
			var t = c.querySelector(".csm-card-name .csm-card-name-link, .csm-card-name");
			if (id && t) { (map[id] = map[id] || []).push(t); }
		});
		var hdr = document.querySelector("#item-header");
		if (hdr) {
			var name = hdr.querySelector("h2.user-nicename");
			var av = hdr.querySelector('[class*="-avatar"]');
			var m = av && av.className.match(/user-(\d+)-avatar/);
			if (name && m) { (map[m[1]] = map[m[1]] || []).push(name); }
		}
		return map;
	}
	var lastFetch = 0;
	function runBadges() {
		if (!C.rest || !C.nonce) { return; }
		var map = collect();
		var ids = Object.keys(map).map(Number).filter(Boolean);
		if (!ids.length) { return; }
		var now = Date.now();
		if (now - lastFetch < 400) { return; }
		lastFetch = now;
		fetch(C.rest, {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/json", "X-WP-Nonce": C.nonce },
			body: JSON.stringify({ ids: ids })
		}).then(function (r) { return r.json(); }).then(function (res) {
			Object.keys(map).forEach(function (id) {
				if (res && res[id]) { map[id].forEach(function (t) { inject(t, res[id]); }); }
			});
		}).catch(function () {});
	}

	/* ---- OTP checklist patch (#11682) ---- */
	function patchOtp() {
		if (!C.otp || !C.otp.editUrl) { return; }
		var wrap = document.querySelector(".csm-pc-wrap");
		if (!wrap) { return; }
		var pctEl = wrap.querySelector(".csm-pc-pct");
		var barEl = wrap.querySelector(".csm-pc-bar-full");
		var msgEl = wrap.querySelector(".csm-pc-msg");
		var list = wrap.querySelector(".csm-pc-missing");
		if (!list) {
			list = document.createElement("ul");
			list.className = "csm-pc-missing";
			if (msgEl && msgEl.parentNode) { msgEl.parentNode.insertBefore(list, msgEl.nextSibling); }
			else { wrap.appendChild(list); }
		}
		if (!list.querySelector(".csm-pc-otp-item")) {
			var li = document.createElement("li");
			li.className = "csm-pc-otp-item";
			li.innerHTML = 'Verify your phone number &mdash; <a href="' + C.otp.editUrl + '">Verify now</a>';
			list.appendChild(li);
		}
		if (pctEl && barEl) {
			var raw = parseInt((pctEl.textContent || "").replace(/[^0-9]/g, ""), 10);
			if (!isNaN(raw)) {
				var adjusted = Math.round(raw * 0.9);
				pctEl.textContent = adjusted + "%";
				barEl.style.width = adjusted + "%";
			}
		}
		if (msgEl) { msgEl.textContent = "Verify your phone number to finish your profile and unlock Discover."; }
	}

	function run() { runBadges(); patchOtp(); }
	if (document.readyState !== "loading") { run(); }
	else { document.addEventListener("DOMContentLoaded", run); }
	var mo = new MutationObserver(function () { runBadges(); });
	mo.observe(document.body, { childList: true, subtree: true });
})();
