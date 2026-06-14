/* global keyCdnAdmin */
(function ($) {
	if ( typeof keyCdnAdmin === 'undefined' ) return;

	var dot   = null;
	var label = null;
	var timer = null;

	function getElements() {
		if ( ! dot )   dot   = document.getElementById( 'keycdn-bar-dot' );
		if ( ! label ) label = document.getElementById( 'keycdn-bar-label' );
	}

	function update() {
		$.post( keyCdnAdmin.ajaxUrl, {
			action: 'keycdn_admin_status',
			nonce:  keyCdnAdmin.nonce,
		}, function ( resp ) {
			getElements();
			if ( ! resp.success || ! dot || ! label ) return;

			var d     = resp.data;
			var color = '#999999';
			if ( d.ftp.ok === true  ) color = '#46b450';
			if ( d.ftp.ok === false ) color = '#dc3232';
			dot.style.background = color;

			var jobs = ( d.bulk_jobs || 0 ) + ( d.scan_jobs || 0 );
			label.textContent = jobs > 0
				? 'KeyCDN — ' + jobs + ' job' + ( jobs !== 1 ? 's' : '' ) + ' running'
				: 'KeyCDN';

			// Resume bulk progress bar on the bulk page if a job is running
			if ( d.bulk_jobs > 0 && typeof window.keyCdnResumeBulk === 'function' ) {
				window.keyCdnResumeBulk( d.bulk_progress );
			}

			clearTimeout( timer );
			timer = setTimeout( update, jobs > 0 ? 5000 : 30000 );
		} ).fail( function () {
			clearTimeout( timer );
			timer = setTimeout( update, 60000 );
		} );
	}

	// Initial check after a short delay so the page finishes loading first.
	timer = setTimeout( update, 3000 );
}(jQuery));
