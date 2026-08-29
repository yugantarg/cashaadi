/* ==========================================================================
   CAShaadi UI — Premium
   --------------------------------------------------------------------------
   Real-asset replacement for the inline pricing-label script in WPCode #11795.
   For members who are already premium, replace the premium add-to-cart link
   with a plain label. Driven by window.CASHAADI_PREMIUM (set server-side by the
   premium module, only for premium members).
   ========================================================================== */
(function () {
	"use strict";
	var C = window.CASHAADI_PREMIUM || {};
	if (!C.isPremium || !C.productId) { return; }

	function run() {
		var links = document.querySelectorAll('a[href*="add-to-cart=' + C.productId + '"]');
		Array.prototype.forEach.call(links, function (a) {
			var tag = document.createElement("span");
			tag.className = "cashaadi-already-premium";
			tag.textContent = "You are already a Premium member";
			if (a.parentNode) { a.parentNode.replaceChild(tag, a); }
		});
	}

	if (document.readyState !== "loading") { run(); }
	else { document.addEventListener("DOMContentLoaded", run); }
})();
