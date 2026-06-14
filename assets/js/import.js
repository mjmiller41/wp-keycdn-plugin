jQuery( function ( $ ) {
	var $scanBtn   = $( '#keycdn-scan-cdn' );
	var $results   = $( '#keycdn-scan-results' );
	var $importBtn = $( '#keycdn-start-import' );
	var $actions   = $( '#keycdn-import-actions' );
	var $queued    = $( '#keycdn-import-queued' );
	var $error     = $( '#keycdn-scan-error' );
	var $errorMsg  = $( '#keycdn-scan-error-msg' );
	var unimported = 0;

	function resetResults() {
		$( '#keycdn-count-total' ).text( '—' );
		$( '#keycdn-count-imported' ).text( '—' );
		$( '#keycdn-count-unimported' ).html( '<strong>—</strong>' );
		$( '#keycdn-scan-truncated' ).hide();
		$( '#keycdn-sample-files' ).hide().text( '' );
		$actions.hide();
		$queued.hide();
		$error.hide();
	}

	$scanBtn.on( 'click', function () {
		$scanBtn.prop( 'disabled', true ).text( 'Scanning…' );
		resetResults();
		$results.show();

		$.post(
			keyCdnImport.ajaxUrl,
			{ action: 'keycdn_preview_cdn_import', nonce: keyCdnImport.nonce },
			function ( response ) {
				$scanBtn.prop( 'disabled', false ).text( 'Scan CDN' );

				if ( ! response.success ) {
					$errorMsg.text( response.data.message );
					$error.show();
					return;
				}

				var d = response.data;
				unimported = d.unimported;

				$( '#keycdn-count-total' ).text( d.total + ( d.truncated ? '+' : '' ) );
				$( '#keycdn-count-imported' ).text( d.imported );
				$( '#keycdn-count-unimported' ).html( '<strong>' + d.unimported + ( d.truncated ? '+' : '' ) + '</strong>' );

				if ( d.truncated ) {
					$( '#keycdn-scan-truncated' ).show();
				}

				if ( d.samples && d.samples.length ) {
					var label = d.unimported === 1 ? 'Sample file to import: ' : 'Sample files to import: ';
					var more  = d.unimported > d.samples.length ? '…' : '';
					$( '#keycdn-sample-files' ).text( label + d.samples.join( ', ' ) + more ).show();
				}

				if ( unimported > 0 ) {
					var label = 'Import ' + d.unimported + ( d.truncated ? '+' : '' ) + ' Image' + ( d.unimported !== 1 ? 's' : '' );
					$importBtn.text( label );
					$actions.show();
				}
			}
		).fail( function () {
			$scanBtn.prop( 'disabled', false ).text( 'Scan CDN' );
			$errorMsg.text( 'Request failed — check the browser console.' );
			$error.show();
		} );
	} );

	$importBtn.on( 'click', function () {
		$importBtn.prop( 'disabled', true ).text( 'Starting…' );

		$.post(
			keyCdnImport.ajaxUrl,
			{ action: 'keycdn_start_cdn_import', nonce: keyCdnImport.nonce },
			function ( response ) {
				if ( response.success ) {
					$actions.hide();
					$queued.show();
				} else {
					$importBtn.prop( 'disabled', false );
					$errorMsg.text( response.data.message );
					$error.show();
				}
			}
		).fail( function () {
			$importBtn.prop( 'disabled', false );
			$errorMsg.text( 'Request failed — check the browser console.' );
			$error.show();
		} );
	} );
} );
