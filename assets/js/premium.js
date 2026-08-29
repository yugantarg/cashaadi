/* ==========================================================================
   CAShaadi UI — Premium
   --------------------------------------------------------------------------
   Real-asset replacement for the inline scripts in the premium snippets:
     - #11795 pricing label: for members who are already premium, replace the
       premium add-to-cart link with a plain label (reads window.CASHAADI_PREMIUM).
     - #11807 insights panel: tab switching for [csm_rejection_insights].
   ========================================================================== */
(function () {
	"use strict";
	var C = window.CASHAADI_PREMIUM || {};

	/* ---- #11795 pricing label ---- */
	function pricingLabel() {
		if (!C.isPremium || !C.productId) { return; }
		var links = document.querySelectorAll('a[href*="add-to-cart=' + C.productId + '"]');
		Array.prototype.forEach.call(links, function (a) {
			var tag = document.createElement("span");
			tag.className = "cashaadi-already-premium";
			tag.textContent = "You are already a Premium member";
			if (a.parentNode) { a.parentNode.replaceChild(tag, a); }
		});
	}

	/* ---- #11807 insights tabs ---- */
	function insightsTabs() {
		Array.prototype.forEach.call(document.querySelectorAll(".csm-rv"), function (root) {
			var tabs = root.querySelectorAll(".csm-rv-tab");
			Array.prototype.forEach.call(tabs, function (tab) {
				tab.addEventListener("click", function () {
					var p = tab.getAttribute("data-p");
					Array.prototype.forEach.call(root.querySelectorAll(".csm-rv-tab"), function (t) {
						t.classList.toggle("active", t === tab);
					});
					Array.prototype.forEach.call(root.querySelectorAll(".csm-rv-panel"), function (pl) {
						pl.classList.toggle("active", pl.getAttribute("data-p") === p);
					});
				});
			});
		});
	}

	function run() { pricingLabel(); insightsTabs(); }
	if (document.readyState !== "loading") { run(); }
	else { document.addEventListener("DOMContentLoaded", run); }
})();
