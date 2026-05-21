jQuery( document ).ready( function ( $ ) {
    // Hide the native WooCommerce Refund button when the Void button is present on this order.
    if ( $( '.givepayments-void-payment' ).length ) {
        $( '.refund-items' ).hide();
    }

    $( document ).on( 'click', '.givepayments-void-payment', function ( e ) {
        e.preventDefault();

        var $btn    = $( this );
        var label   = $btn.data( 'label' ) || givepayments_void_params.label;
        var confirm = $btn.data( 'confirm' ) || givepayments_void_params.confirm;
        var working = $btn.data( 'working' ) || givepayments_void_params.voiding;

        if ( ! window.confirm( confirm ) ) {
            return;
        }

        var orderId = $btn.data( 'order-id' );
        var nonce   = $btn.data( 'nonce' );

        $btn.prop( 'disabled', true ).text( working );

        $.ajax( {
            url:  givepayments_void_params.ajax_url,
            type: 'POST',
            data: {
                action:   'givepayments_void_payment',
                order_id: orderId,
                nonce:    nonce,
            },
            success: function ( response ) {
                if ( response.success ) {
                    window.location.reload();
                } else {
                    var msg = ( response.data && response.data.message )
                        ? response.data.message
                        : label + ' failed.';
                    window.alert( msg );
                    $btn.prop( 'disabled', false ).text( label );
                }
            },
            error: function () {
                window.alert( label + ' request failed. Please try again.' );
                $btn.prop( 'disabled', false ).text( label );
            },
        } );
    } );
} );
