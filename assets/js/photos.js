/* ==========================================================================
   CAShaadi UI — Photos
   --------------------------------------------------------------------------
   Photo-request interactions (migrated from WPCode #11798's inline <script>):
   send a request, and approve/deny from the owner inbox. Reads the AJAX URL +
   nonce from window.CASHAADI_PR (set server-side).
   ========================================================================== */
(function () {
	"use strict";
	var C = window.CASHAADI_PR || {};
	if (!C.ajax || !C.nonce) { return; }

	function post(data, cb) {
		data.nonce = C.nonce;
		var body = Object.keys(data).map(function (k) {
			return encodeURIComponent(k) + "=" + encodeURIComponent(data[k]);
		}).join("&");
		fetch(C.ajax, {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/x-www-form-urlencoded" },
			body: body
		}).then(function (r) { return r.json(); }).then(cb).catch(function () {});
	}

	document.addEventListener("click", function (e) {
		var t = e.target;

		if (t.classList.contains("csm-pr-btn")) {
			t.disabled = true;
			post({ action: "csm_pr_submit", owner: t.getAttribute("data-owner") }, function (res) {
				var msg = t.parentNode.querySelector(".csm-pr-msg");
				if (res && res.success) {
					t.style.display = "none";
					if (msg) { msg.textContent = "Photo request sent"; msg.style.color = "#b9822b"; }
				} else {
					t.disabled = false;
					if (msg) { msg.textContent = (res && res.data && res.data.message) || "Error"; msg.style.color = "#b23b3b"; }
				}
			});
		}

		if (t.classList.contains("csm-pr-approve") || t.classList.contains("csm-pr-deny")) {
			var item = t.closest(".csm-pr-item");
			if (!item) { return; }
			var decision = t.classList.contains("csm-pr-approve") ? "approve" : "deny";
			t.disabled = true;
			post({ action: "csm_pr_act", requester: item.getAttribute("data-requester"), decision: decision }, function (res) {
				if (res && res.success) {
					item.innerHTML = '<span style="color:#2f7d4f;font-weight:600">' + ((res.data && res.data.message) || "Done") + "</span>";
				} else {
					t.disabled = false;
				}
			});
		}
	});
})();
