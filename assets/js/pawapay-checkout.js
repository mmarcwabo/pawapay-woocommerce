(function () {
    'use strict';

    var LAST_MNO_KEY = 'pawapay_last_mno';
    var LAST_CURRENCY_KEY = 'pawapay_last_currency';
    var bound = false;
    var userPickedMno = false;

    function config() {
        return window.wcPawapayCheckout || {};
    }

    function storageGet( key ) {
        try {
            return window.sessionStorage.getItem( key );
        } catch ( e ) {
            return null;
        }
    }

    function storageSet( key, value ) {
        try {
            if ( value ) {
                window.sessionStorage.setItem( key, value );
            }
        } catch ( e ) {}
    }

    function parseCurrencies( card ) {
        if ( ! card ) {
            return [];
        }

        var raw = card.getAttribute( 'data-currencies' );
        if ( raw ) {
            try {
                var parsed = JSON.parse( raw );
                if ( Array.isArray( parsed ) ) {
                    return parsed;
                }
            } catch ( e ) {}
        }

        var providers = config().providers || {};
        return providers[ card.getAttribute( 'data-mno' ) ] || [];
    }

    function currencyLabel( code ) {
        var select = document.getElementById( 'pawapay_currency' );
        if ( select && select.classList.contains( 'pawapay-currency-select' ) ) {
            return code;
        }
        var names = config().currencyNames || {};
        return names[ code ] ? code + ' — ' + names[ code ] : code;
    }

    function fillCurrencySelect( codes, preferred ) {
        var select = document.getElementById( 'pawapay_currency' );
        if ( ! select ) {
            return;
        }

        var enabled = config().enabledCurrencies || [];
        var list = codes && codes.length ? codes.slice() : enabled.slice();

        if ( enabled.length && list.length ) {
            var intersected = list.filter( function ( code ) {
                return enabled.indexOf( code ) !== -1;
            } );
            if ( intersected.length ) {
                list = intersected;
            }
        }

        var current = preferred || select.value || storageGet( LAST_CURRENCY_KEY ) || config().shopCurrency || '';
        select.innerHTML = '';

        list.forEach( function ( code ) {
            var option = document.createElement( 'option' );
            option.value = code;
            option.textContent = currencyLabel( code );
            if ( code === current ) {
                option.selected = true;
            }
            select.appendChild( option );
        } );

        if ( ! select.value && list.length ) {
            select.value = list[ 0 ];
        }

        if ( select.value ) {
            storageSet( LAST_CURRENCY_KEY, select.value );
        }

        var row = document.getElementById( 'pawapay-currency-row' );
        if ( row ) {
            row.style.display = list.length ? '' : 'none';
        }
        if ( select ) {
            select.hidden = list.length < 2;
        }
    }

    function currentCountry() {
        var select = document.getElementById( 'pawapay_country' );
        if ( select && select.value ) {
            return select.value;
        }
        var hidden = document.querySelector( '#pawapay-fields input[name="pawapay_country"]' );
        return hidden ? hidden.value : '';
    }

    function syncPrefix() {
        var chip = document.getElementById( 'pawapay-prefix' );
        if ( ! chip ) {
            return;
        }
        var info = ( config().prefixes || {} )[ currentCountry() ];
        if ( ! info ) {
            return;
        }
        chip.setAttribute( 'data-prefix', info.prefix || '' );
        var flag = document.getElementById( 'pawapay-flag' );
        var text = document.getElementById( 'pawapay-prefix-text' );
        if ( flag ) {
            flag.textContent = info.flag || '';
        }
        if ( text ) {
            text.textContent = info.prefix ? '+' + info.prefix : '';
        }
        composePhone();
    }

    function composePhone() {
        var hidden = document.getElementById( 'pawapay_phone' );
        var local = document.getElementById( 'pawapay_phone_local' );
        var chip = document.getElementById( 'pawapay-prefix' );
        if ( ! hidden ) {
            return;
        }

        var digits = local ? String( local.value || '' ).replace( /\D+/g, '' ) : '';
        var prefix = chip ? String( chip.getAttribute( 'data-prefix' ) || '' ) : '';
        if ( digits && prefix && digits.indexOf( prefix ) === 0 ) {
            hidden.value = digits;
            return;
        }
        if ( digits.indexOf( '0' ) === 0 ) {
            digits = digits.slice( 1 );
        }
        hidden.value = prefix && digits ? prefix + digits : digits;
        hintOperator();
    }

    function hintOperator() {
        if ( userPickedMno ) {
            return;
        }
        var hidden = document.getElementById( 'pawapay_phone' );
        var msisdn = hidden ? String( hidden.value || '' ) : '';
        var hints = config().operatorHints || {};
        var match = '';
        var hits = 0;
        Object.keys( hints ).forEach( function ( code ) {
            ( hints[ code ] || [] ).forEach( function ( prefix ) {
                if ( prefix && msisdn.indexOf( prefix ) === 0 ) {
                    hits += 1;
                    match = code;
                }
            } );
        } );
        if ( hits !== 1 || ! match ) {
            return;
        }
        var card = document.querySelector( '.pawapay-mno-card[data-mno="' + match + '"]' );
        if ( card && card.style.display !== 'none' ) {
            selectCard( card, true );
        }
    }

    function selectCard( card, keepCurrency ) {
        var input = document.getElementById( 'pawapay_mno' );
        if ( ! input || ! card ) {
            return;
        }

        document.querySelectorAll( '.pawapay-mno-card' ).forEach( function ( other ) {
            other.classList.remove( 'pawapay-selected' );
        } );
        card.classList.add( 'pawapay-selected' );
        input.value = card.getAttribute( 'data-mno' ) || '';
        storageSet( LAST_MNO_KEY, input.value );

        fillCurrencySelect(
            parseCurrencies( card ),
            keepCurrency ? storageGet( LAST_CURRENCY_KEY ) : undefined
        );
    }

    function filterByCountry() {
        var countrySelect = document.getElementById( 'pawapay_country' );
        var country = countrySelect ? countrySelect.value : '';
        var firstVisible = null;
        var saved = storageGet( LAST_MNO_KEY );
        var savedCard = null;

        document.querySelectorAll( '.pawapay-mno-card' ).forEach( function ( card ) {
            var match = ! country || card.getAttribute( 'data-country' ) === country;
            card.style.display = match ? '' : 'none';
            if ( match && ! firstVisible ) {
                firstVisible = card;
            }
            if ( match && saved && card.getAttribute( 'data-mno' ) === saved ) {
                savedCard = card;
            }
        } );

        if ( savedCard ) {
            selectCard( savedCard, true );
            return;
        }

        var selected = document.querySelector( '.pawapay-mno-card.pawapay-selected' );
        if ( ! selected || selected.style.display === 'none' ) {
            selectCard( firstVisible, true );
            return;
        }

        fillCurrencySelect( parseCurrencies( selected ), storageGet( LAST_CURRENCY_KEY ) );
    }

    function isPawapaySelected() {
        var selected = document.querySelector( 'input[name="payment_method"]:checked' );
        return ! selected || selected.value === 'pawapay';
    }

    function disablePlaceOrder() {
        document.querySelectorAll( '#place_order, form.checkout button[type="submit"], form#order_review button[type="submit"]' ).forEach( function ( btn ) {
            btn.disabled = true;
            btn.setAttribute( 'aria-busy', 'true' );
            btn.classList.add( 'pawapay-paying' );
        } );
    }

    function enablePlaceOrder() {
        document.querySelectorAll( '#place_order, .pawapay-paying' ).forEach( function ( btn ) {
            btn.disabled = false;
            btn.removeAttribute( 'aria-busy' );
            btn.classList.remove( 'pawapay-paying' );
        } );
    }

    function restoreSelection() {
        if ( ! document.getElementById( 'pawapay_mno' ) ) {
            return;
        }
        filterByCountry();
        syncPrefix();
        composePhone();
    }

    function onCardActivate( event ) {
        var card = event.target.closest ? event.target.closest( '.pawapay-mno-card' ) : null;
        if ( ! card || ! document.getElementById( 'pawapay_mno' ) ) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        if ( event.stopImmediatePropagation ) {
            event.stopImmediatePropagation();
        }

        userPickedMno = true;
        selectCard( card, false );
    }

    function bindOnce() {
        if ( bound ) {
            return;
        }
        bound = true;

        // Capture-phase so Woo's form refresh / first-card default cannot steal the click.
        document.addEventListener( 'click', onCardActivate, true );
        document.addEventListener( 'keydown', function ( event ) {
            if ( event.key !== 'Enter' && event.key !== ' ' ) {
                return;
            }
            var card = event.target.closest ? event.target.closest( '.pawapay-mno-card' ) : null;
            if ( ! card ) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            userPickedMno = true;
            selectCard( card, false );
        }, true );

        document.addEventListener( 'change', function ( event ) {
            if ( ! event.target ) {
                return;
            }
            if ( event.target.id === 'pawapay_country' ) {
                filterByCountry();
                syncPrefix();
            }
            if ( event.target.id === 'pawapay_currency' ) {
                storageSet( LAST_CURRENCY_KEY, event.target.value );
            }
        }, true );

        document.addEventListener( 'input', function ( event ) {
            if ( event.target && event.target.id === 'pawapay_phone_local' ) {
                composePhone();
            }
        }, true );

        if ( window.jQuery ) {
            window.jQuery( 'form.checkout' ).on( 'checkout_place_order_pawapay', function () {
                composePhone();
                disablePlaceOrder();
                return true;
            } );
            window.jQuery( document.body ).on( 'checkout_error', enablePlaceOrder );
            window.jQuery( 'form#order_review' ).on( 'submit', function () {
                if ( ! isPawapaySelected() ) {
                    return;
                }
                if ( ! document.getElementById( 'pawapay_mno' ) ) {
                    return;
                }
                composePhone();
                disablePlaceOrder();
            } );
        }

        document.addEventListener( 'DOMContentLoaded', restoreSelection );

        if ( window.jQuery ) {
            window.jQuery( document.body ).on( 'updated_checkout updated_wc_div payment_method_selected', restoreSelection );
        } else if ( document.body ) {
            document.body.addEventListener( 'updated_checkout', restoreSelection );
            document.body.addEventListener( 'payment_method_selected', restoreSelection );
        }
    }

    bindOnce();
    if ( document.readyState !== 'loading' ) {
        restoreSelection();
    }
})();
