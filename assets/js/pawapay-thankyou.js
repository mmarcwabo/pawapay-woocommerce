(function () {
    'use strict';

    var cfg = window.wcPawapayThankyou;
    if ( ! cfg || ! cfg.ajaxUrl ) {
        return;
    }

    var attempts = 0;
    var maxAttempts = 40;

    function poll() {
        attempts += 1;
        var body = new FormData();
        body.append( 'action', 'wc_pawapay_poll' );
        body.append( 'order_id', cfg.orderId );
        body.append( 'order_key', cfg.orderKey );
        body.append( 'nonce', cfg.nonce );

        fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
            .then( function ( res ) { return res.json(); } )
            .then( function ( json ) {
                if ( json && json.success && json.data && json.data.reload ) {
                    window.location.reload();
                    return;
                }
                if ( attempts < maxAttempts ) {
                    setTimeout( poll, 3000 );
                    return;
                }
                stillWaiting();
            } )
            .catch( function () {
                if ( attempts < maxAttempts ) {
                    setTimeout( poll, 5000 );
                    return;
                }
                stillWaiting();
            } );
    }

    function stillWaiting() {
        var box = document.getElementById( 'pawapay-waiting' );
        if ( box && cfg.timeoutMessage ) {
            box.textContent = cfg.timeoutMessage;
        }
    }

    setTimeout( poll, 2500 );
})();
