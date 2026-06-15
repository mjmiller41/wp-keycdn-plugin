<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'KeyCDN Offload — Bulk Offload', 'wp-keycdn-offload' ); ?></h1>
    <p><?php esc_html_e( 'Queue all existing Media Library items for offload to KeyCDN. This runs in the background via Action Scheduler.', 'wp-keycdn-offload' ); ?></p>

    <div id="keycdn-bulk-controls">
        <button id="keycdn-start-bulk" class="button button-primary"><?php esc_html_e( 'Start Bulk Offload', 'wp-keycdn-offload' ); ?></button>
    </div>

    <div id="keycdn-progress-wrap" style="margin-top:20px; display:none;">
        <div style="background:#e0e0e0; border-radius:4px; height:24px; width:100%; max-width:600px;">
            <div id="keycdn-progress-bar" style="background:#0073aa; height:24px; border-radius:4px; width:0%; transition:width 0.4s;"></div>
        </div>
        <p id="keycdn-progress-label" style="margin-top:8px;"></p>
    </div>
</div>

<script>
(function($){
    var polling = null;

    function renderProgress( d ) {
        $('#keycdn-progress-bar').css('width', d.percent + '%');
        $('#keycdn-progress-label').text(
            d.completed + ' / ' + d.total + ' files — ' + d.percent + '%' +
            ( d.failed > 0 ? ' (' + d.failed + ' failed)' : '' )
        );
    }

    function startPolling() {
        if ( polling ) return;
        polling = setInterval(function(){
            $.post(keyCdnOffload.ajaxUrl, {
                action: 'keycdn_bulk_progress',
                nonce:  keyCdnOffload.nonce
            }, function(resp){
                if ( ! resp.success ) return;
                var d = resp.data;
                renderProgress( d );
                if ( d.status === 'complete' ) {
                    clearInterval(polling);
                    polling = null;
                    $('#keycdn-start-bulk').prop('disabled', false);
                    $('#keycdn-progress-label').append(' <strong><?php echo esc_js( __( 'Done!', 'wp-keycdn-offload' ) ); ?></strong>');
                }
            });
        }, 3000);
    }

    // Called by admin-status.js when it detects a running bulk job from any page.
    window.keyCdnResumeBulk = function( progress ) {
        if ( polling ) return;
        $('#keycdn-start-bulk').prop('disabled', true);
        $('#keycdn-progress-wrap').show();
        renderProgress( progress );
        startPolling();
    };

    $('#keycdn-start-bulk').on('click', function(){
        $(this).prop('disabled', true);
        $('#keycdn-progress-wrap').show();
        $.post(keyCdnOffload.ajaxUrl, {
            action: 'keycdn_start_bulk',
            nonce:  keyCdnOffload.nonce
        }, function(resp){
            if ( resp.success ) {
                startPolling();
            }
        });
    });

    // Auto-resume: check on page load whether a bulk job is already running.
    $.post(keyCdnOffload.ajaxUrl, {
        action: 'keycdn_bulk_progress',
        nonce:  keyCdnOffload.nonce
    }, function(resp){
        if ( ! resp.success ) return;
        var d = resp.data;
        if ( d.total > 0 && d.status === 'running' ) {
            window.keyCdnResumeBulk( d );
        }
    });
}(jQuery));
</script>
