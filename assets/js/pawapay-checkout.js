(function () {
    'use strict';

    var LAST_MNO_KEY = 'pawapay_last_mno';
    var LAST_CURRENCY_KEY = 'pawapay_last_currency';
    var bound = false;

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

    function restoreSelection() {
        if ( ! document.getElementById( 'pawapay_mno' ) ) {
            return;
        }
        filterByCountry();
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
            selectCard( card, false );
        }, true );

        document.addEventListener( 'change', function ( event ) {
            if ( ! event.target ) {
                return;
            }
            if ( event.target.id === 'pawapay_country' ) {
                filterByCountry();
            }
            if ( event.target.id === 'pawapay_currency' ) {
                storageSet( LAST_CURRENCY_KEY, event.target.value );
            }
        }, true );

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
