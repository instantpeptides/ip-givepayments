( function() {
    function digitsOnly( value ) {
        return ( value || '' ).replace( /\D+/g, '' );
    }

    function formatCardNumber( value ) {
        var raw = digitsOnly( value ).slice( 0, 19 );
        return raw.replace( /(.{4})/g, '$1 ' ).trim();
    }

    function formatExpiry( value ) {
        var raw = digitsOnly( value ).slice( 0, 4 );
        if ( raw.length <= 2 ) {
            return raw;
        }
        return raw.slice( 0, 2 ) + '/' + raw.slice( 2 );
    }

    function isNumericInsertion( event ) {
        if ( event.data === null || typeof event.data === 'undefined' ) {
            return true;
        }
        return /^\d+$/.test( event.data );
    }

    document.addEventListener( 'beforeinput', function( event ) {
        var target = event.target;
        if ( !target || !target.id ) {
            return;
        }

        if (
            target.id !== 'givepayments-card-number' &&
            target.id !== 'givepayments-card-cvv' &&
            target.id !== 'givepayments-card-expiration'
        ) {
            return;
        }

        if (
            event.inputType &&
            event.inputType.indexOf( 'insert' ) === 0 &&
            event.inputType !== 'insertFromPaste' &&
            event.inputType !== 'insertFromDrop' &&
            !isNumericInsertion( event )
        ) {
            event.preventDefault();
        }
    } );

    // Delegated listener works even when Woo re-renders checkout fragments.
    document.addEventListener( 'input', function( event ) {
        var target = event.target;
        if ( !target || !target.id ) {
            return;
        }

        if ( target.id === 'givepayments-card-number' ) {
            target.value = formatCardNumber( target.value );
            return;
        }

        if ( target.id === 'givepayments-card-cvv' ) {
            target.value = digitsOnly( target.value ).slice( 0, 4 );
            return;
        }

        if ( target.id === 'givepayments-card-expiration' ) {
            target.value = formatExpiry( target.value );
        }
    } );
}() );
