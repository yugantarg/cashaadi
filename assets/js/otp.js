/* CAShaadi UI — OTP widget (migrated from the inline script in WPCode #11618).
 * Depends on MSG91's otp-provider.js (enqueued as a dependency). Config comes
 * from window.CASHAADI_OTP (verified state, nonce, ajax url, MSG91 widget/token).
 */
(function () {
	var CFGROOT = window.CASHAADI_OTP || {};
	var VERIFIED = CFGROOT.verified ? 1 : 0;
	var VERIFIED_NUMBER = CFGROOT.verifiedNumber || '';
	var CFG = {
		nonce: CFGROOT.nonce || '',
		ajax: CFGROOT.ajax || '',
		widgetId: CFGROOT.widgetId || '',
		tokenAuth: CFGROOT.tokenAuth || ''
	};

	function setStatus(msg, color) {
		var s = document.getElementById('csm-otp-status');
		if (s) { s.textContent = msg; s.style.color = color || '#555'; }
	}
	function getPhone() {
		var tel = document.querySelector('input[type="tel"]') || document.getElementById('field_277');
		var v = (tel && tel.value || '').replace(/[^0-9]/g, '');
		if (v.length === 10) { v = '91' + v; }
		return v;
	}
	function isCurrentVerified() {
		return VERIFIED && VERIFIED_NUMBER && getPhone() === VERIFIED_NUMBER;
	}
	function renderVerified(box) {
		box.className = 'csm-verified';
		box.innerHTML = '<strong>&#10003; Mobile number verified</strong>';
	}
	function renderUnverified(box) {
		box.className = '';
		box.innerHTML = '<div>Please verify your mobile number to continue.</div>'
			+ '<div class="csm-row">'
			+ '<button type="button" id="csm-otp-send" class="button">Verify mobile via OTP</button>'
			+ '<span id="csm-otp-status"></span></div>'
			+ '<div class="csm-row" id="csm-otp-verify-row" style="display:none">'
			+ '<input type="text" id="csm-otp-input" inputmode="numeric" placeholder="Enter OTP" />'
			+ '<button type="button" id="csm-otp-verify" class="button button-primary">Submit OTP</button>'
			+ '<button type="button" id="csm-otp-resend" class="button">Resend</button>'
			+ '</div>';
	}
	function build() {
		var tel = document.querySelector('input[type="tel"]') || document.getElementById('field_277');
		if (!tel) { return false; }
		var box = document.getElementById('csm-otp-box');
		if (!box) {
			var wrap = tel.closest('.editfield') || tel.parentElement;
			box = document.createElement('div');
			box.id = 'csm-otp-box';
			wrap.parentNode.insertBefore(box, wrap.nextSibling);
		}
		if (isCurrentVerified()) { renderVerified(box); }
		else { renderUnverified(box); wireButtons(); initWidget(); }
		return true;
	}
	function refresh() {
		var box = document.getElementById('csm-otp-box');
		if (!box) { return; }
		if (isCurrentVerified()) { renderVerified(box); }
		else if (!document.getElementById('csm-otp-send')) { renderUnverified(box); wireButtons(); initWidget(); }
	}
	function initWidget() {
		if (typeof window.initSendOTP !== 'function') { return setTimeout(initWidget, 400); }
		window.initSendOTP({
			widgetId: CFG.widgetId,
			tokenAuth: CFG.tokenAuth,
			exposeMethods: true,
			success: function (data) { onVerified(data); },
			failure: function (err) { setStatus('Verification failed. Try again.', '#c00'); console.log('MSG91 failure', err); }
		});
	}
	function onVerified(data) {
		var token = (data && (data.message || data.accessToken || data['access-token'] || data));
		setStatus('Confirming…', '#555');
		var body = 'action=csm_mark_phone_verified&nonce=' + encodeURIComponent(CFG.nonce)
			+ '&access_token=' + encodeURIComponent(token) + '&phone=' + encodeURIComponent(getPhone());
		fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (j && j.success) {
					VERIFIED = 1;
					VERIFIED_NUMBER = getPhone();
					var b = document.getElementById('csm-otp-box');
					if (b) { renderVerified(b); }
				} else { setStatus('Could not save verification. Contact support.', '#c00'); console.log('save failed', j); }
			})
			.catch(function () { setStatus('Network error saving verification.', '#c00'); });
	}
	function wireButtons() {
		var sendBtn = document.getElementById('csm-otp-send');
		if (!sendBtn) { return; }
		sendBtn.addEventListener('click', function () {
			var phone = getPhone();
			if (phone.length < 11) { setStatus('Enter a valid 10-digit mobile number first.', '#c00'); return; }
			if (typeof window.sendOtp !== 'function') { setStatus('OTP service loading… try again in a moment.', '#c00'); initWidget(); return; }
			setStatus('Sending OTP…');
			window.sendOtp(phone, function () { setStatus('OTP sent to ' + phone, '#1a7'); document.getElementById('csm-otp-verify-row').style.display = 'flex'; },
				function (e) { setStatus('Failed to send OTP.', '#c00'); console.log('sendOtp err', e); });
		});
		var vBtn = document.getElementById('csm-otp-verify');
		if (vBtn) {
			vBtn.addEventListener('click', function () {
				var otp = (document.getElementById('csm-otp-input').value || '').replace(/[^0-9]/g, '');
				if (!otp) { setStatus('Enter the OTP.', '#c00'); return; }
				if (typeof window.verifyOtp !== 'function') { setStatus('OTP service not ready.', '#c00'); return; }
				setStatus('Verifying…');
				window.verifyOtp(otp, function (data) { onVerified(data); }, function (e) { setStatus('Incorrect OTP. Try again.', '#c00'); });
			});
		}
		var reBtn = document.getElementById('csm-otp-resend');
		if (reBtn) {
			reBtn.addEventListener('click', function () {
				if (typeof window.retryOtp === 'function') { window.retryOtp('11', function () { setStatus('OTP resent.', '#1a7'); }, function () { setStatus('Resend failed.', '#c00'); }); }
			});
		}
	}
	function wireField() {
		var tel = document.querySelector('input[type="tel"]') || document.getElementById('field_277');
		if (!tel || tel.dataset.csmBound) { return; }
		tel.dataset.csmBound = '1';
		tel.addEventListener('input', refresh);
		tel.addEventListener('change', refresh);
	}
	function boot() { if (build()) { wireField(); } else { setTimeout(boot, 400); } }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
