/**
 * CA Verify — admin Queue page interactions (migrated from WPCode #11815).
 *
 * Wires the "Run AI check", "Approve" and "Reject" buttons on the CA Verify
 * queue to admin-ajax. Config (ajax url + nonce) is provided by PHP via
 * window.CSM_AV (wp_add_inline_script). Behaviour is identical to the inline
 * script the snippet printed.
 */
(function () {
	var CFG = window.CSM_AV || {};
	var AJAX = CFG.ajax || '', NONCE = CFG.nonce || '';

	function post( action, uid, extra ) {
		var b = new URLSearchParams();
		b.append( 'action', action );
		b.append( '_wpnonce', NONCE );
		b.append( 'uid', uid );
		if ( extra ) {
			for ( var k in extra ) {
				b.append( k, extra[ k ] );
			}
		}
		return fetch( AJAX, { method: 'POST', credentials: 'same-origin', body: b } ).then( function ( r ) {
			return r.json();
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var c = e.target.closest( '.csm-av-check' );
		if ( c ) {
			e.preventDefault();
			var uid = c.getAttribute( 'data-uid' );
			c.disabled = true;
			c.textContent = 'Checking…';
			var cell = document.querySelector( '#csm-av-row-' + uid + ' .csm-av-result' );
			post( 'csm_av_check', uid ).then( function ( res ) {
				c.disabled = false;
				c.textContent = 'Run AI check';
				if ( res && res.success ) {
					var v = res.data.verdict || {};
					cell.innerHTML = '<small><b>' + ( v.recommendation || '?' ) + '</b> (' + ( v.authenticity_confidence != null ? v.authenticity_confidence : '?' ) + ') '
						+ ( v.document_type || '' ) + ' — ' + ( v.full_name || '' ) + ' ' + ( v.membership_number || '' ) + '<br>' + ( v.reason || '' ) + '</small>';
				} else {
					cell.innerHTML = '<small style="color:#b3261e">' + ( ( res && res.data && res.data.error ) || 'Error' ) + '</small>';
				}
			} );
		}
		var d = e.target.closest( '.csm-av-decide' );
		if ( d ) {
			e.preventDefault();
			var uid2 = d.getAttribute( 'data-uid' );
			var dec = d.getAttribute( 'data-decision' );
			post( 'csm_av_decide', uid2, { decision: dec } ).then( function ( res ) {
				if ( res && res.success ) {
					var st = document.querySelector( '#csm-av-row-' + uid2 + ' .csm-av-status' );
					st.innerHTML = dec === 'approved' ? '<span style="color:#137333;font-weight:600">Approved</span>' : '<span style="color:#b3261e;font-weight:600">Rejected</span>';
				} else {
					window.csmToast( ( res && res.data && res.data.error ) || 'Error', 'bad' );
				}
			} );
		}
	} );
})();
