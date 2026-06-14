jQuery( function ( $ ) {
	var $btn    = $( '#keycdn-test-connection' );
	var $result = $( '#keycdn-test-result' );

	$btn.on( 'click', function () {
		$btn.prop( 'disabled', true ).text( 'Testing…' );
		$result.hide().removeClass( 'notice-success notice-error' );

		$.post(
			keyCdnSettings.ajaxUrl,
			{
				action: 'keycdn_test_connection',
				nonce:  keyCdnSettings.nonce,
			},
			function ( response ) {
				$btn.prop( 'disabled', false ).text( 'Test Connection' );
				var cls = response.success ? 'notice-success' : 'notice-error';
				$result.addClass( cls ).text( response.data.message ).show();
			}
		).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Test Connection' );
			$result.addClass( 'notice-error' ).text( 'Request failed — check the browser console.' ).show();
		} );
	} );
} );
