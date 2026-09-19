(function () {
    'use strict';

    var cfg = window.wcPawapayThankyou;
    if ( ! cfg || ! cfg.ajaxUrl ) {
        return;
    }

    var attempts = 0;
    var schedule = cfg.schedule || { maxAttempts: 28, tiers: [] };

    applyDto( cfg.initial || {}, false );

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
                var data = json && json.success ? json.data : null;
                var next = applyDto( data || {}, true );
                if ( next === 'stop' ) {
                    return;
                }
                if ( canContinue() ) {
                    setTimeout( poll, delayMs( attempts, false ) );
                    return;
                }
                stillWaiting();
            } )
            .catch( function () {
                if ( canContinue() ) {
                    setTimeout( poll, delayMs( attempts, true ) );
                    return;
                }
                stillWaiting();
            } );
    }

    function applyDto( data, fromPoll ) {
        var phase = data.phase || 'waiting';
        var box = document.getElementById( 'pawapay-waiting' );
        var statusEl = document.getElementById( 'pawapay-waiting-status' );

        if ( box ) {
            box.setAttribute( 'data-phase', phase );
        }
        if ( statusEl && data.message ) {
            statusEl.textContent = data.message;
        }

        document.body.classList.toggle( 'pawapay-is-waiting', phase === 'waiting' );

        if ( fromPoll && data.reload ) {
            window.location.reload();
            return 'stop';
        }

        if ( data.can_retry ) {
            if ( ! cfg.onPayPage ) {
                showRetry( data.pay_url || '' );
            }
            return 'stop';
        }

        return 'continue';
    }

    function showRetry( payUrl ) {
        var box = document.getElementById( 'pawapay-waiting' );
        if ( ! box || ! isSafePayUrl( payUrl ) || document.getElementById( 'pawapay-retry-link' ) ) {
            return;
        }

        var wrap = document.createElement( 'p' );
        wrap.className = 'pawapay-waiting-actions';
        var link = document.createElement( 'a' );
        link.id = 'pawapay-retry-link';
        link.className = 'button';
        link.href = payUrl;
        link.textContent = cfg.retryLabel || 'Try another number';
        wrap.appendChild( link );
        box.appendChild( wrap );
    }

    function stillWaiting() {
        var statusEl = document.getElementById( 'pawapay-waiting-status' );
        if ( statusEl && cfg.timeoutMessage ) {
            statusEl.textContent = cfg.timeoutMessage;
        }
    }

    function isSafePayUrl( url ) {
        if ( ! url ) {
            return false;
        }
        try {
            var parsed = new URL( url, window.location.origin );
            return ( parsed.protocol === 'http:' || parsed.protocol === 'https:' )
                && parsed.origin === window.location.origin
                && parsed.pathname.indexOf( 'order-pay' ) !== -1;
        } catch ( e ) {
            return false;
        }
    }

    function canContinue() {
        return attempts < ( schedule.maxAttempts || 28 );
    }

    function delayMs( n, errored ) {
        var ms = 15000;
        var tiers = schedule.tiers || [];
        var i;
        for ( i = 0; i < tiers.length; i++ ) {
            if ( n <= tiers[ i ].until ) {
                ms = tiers[ i ].ms;
                break;
            }
        }
        if ( errored ) {
            ms = Math.min( 20000, Math.round( ms * 1.5 ) );
        }
        return ms;
    }

    if ( ! cfg.initial || cfg.initial.phase === 'waiting' ) {
        setTimeout( poll, 2500 );
    }
})();
