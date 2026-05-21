( function() {
    const settings = window.wc.wcSettings.getSetting( 'givepayments_data', {} );
    const label = window.wp.htmlEntities.decodeEntities( settings.title || 'GivePayments' );
    const description = window.wp.htmlEntities.decodeEntities( settings.description || '' );
    const __ = window.wp.i18n.__;
    const el = window.wp.element.createElement;
    const useState = window.wp.element.useState;
    const useEffect = window.wp.element.useEffect;

    const digitsOnly = ( value ) => ( value || '' ).replace( /\D+/g, '' );
    const formatCardNumber = ( value ) => digitsOnly( value ).slice( 0, 19 ).replace( /(.{4})/g, '$1 ' ).trim();
    const formatExpiry = ( value ) => {
        const raw = digitsOnly( value ).slice( 0, 4 );
        return raw.length <= 2 ? raw : raw.slice( 0, 2 ) + '/' + raw.slice( 2 );
    };

    const CardFields = ( props ) => {
        const [ cardName, setCardName ] = useState( '' );
        const [ cardNumber, setCardNumber ] = useState( '' );
        const [ cardExpiry, setCardExpiry ] = useState( '' );
        const [ cardCvv, setCardCvv ] = useState( '' );

        // Extract stable references in component scope so they are accessible in
        // both the useEffect callback body and its dependency array. Declaring them
        // inside useEffect would put them out of scope for the dep array and throw
        // a ReferenceError. Depending on the full `props` object instead causes the
        // effect to re-run on every render (WC Blocks creates a new props reference
        // each time), which produces a brief cleanup window where no
        // onPaymentProcessing handler is registered, exactly when the event fires.
        const onPaymentProcessing = ( props && props.eventRegistration ) ? props.eventRegistration.onPaymentProcessing : undefined;
        const responseTypes = ( props && props.emitResponse ) ? props.emitResponse.responseTypes : undefined;

        useEffect( () => {
            if ( ! onPaymentProcessing || ! responseTypes ) {
                return undefined;
            }

            return onPaymentProcessing( () => {
                const sanitizedName = ( cardName || '' ).trim();
                const sanitizedNumber = digitsOnly( cardNumber );
                const sanitizedExpiry = formatExpiry( cardExpiry );
                const sanitizedCvv = digitsOnly( cardCvv );

                if ( sanitizedName.length < 2 || sanitizedNumber.length < 12 || !/^\d{2}\/\d{2,4}$/.test( sanitizedExpiry ) || sanitizedCvv.length < 3 ) {
                    return {
                        type: responseTypes.ERROR,
                        message: __( 'Please enter valid card details.', 'givepayments-for-woocommerce' ),
                    };
                }

                return {
                    type: responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            'givepayments-card-name': sanitizedName,
                            'givepayments-card-number': sanitizedNumber,
                            'givepayments-card-expiration': sanitizedExpiry,
                            'givepayments-card-cvv': sanitizedCvv,
                        },
                    },
                };
            } );
        }, [ onPaymentProcessing, responseTypes, cardName, cardNumber, cardExpiry, cardCvv ] );

        return el(
            'div',
            { className: 'givepayments-card-ui' },
            el(
                'div',
                { className: 'givepayments-card-ui__body' },
                description ? el( 'p', { className: 'givepayments-card-ui__description' }, description ) : null,
                el(
                    'div',
                    { className: 'givepayments-card-fields' },
                    el(
                        'p',
                        { className: 'form-row form-row-wide givepayments-field' },
                        el( 'label', { htmlFor: 'givepayments-card-number-block' }, __( 'Card Number', 'givepayments-for-woocommerce' ) ),
                        el( 'input', {
                            id: 'givepayments-card-number-block',
                            type: 'tel',
                            inputMode: 'numeric',
                            autoComplete: 'cc-number',
                            placeholder: '1234 5678 9123 4567',
                            value: cardNumber,
                            onChange: ( e ) => setCardNumber( formatCardNumber( e.target.value ) ),
                        } )
                    ),
                    el(
                        'div',
                        { className: 'givepayments-card-row' },
                        el(
                            'p',
                            { className: 'form-row form-row-first givepayments-field' },
                            el( 'label', { htmlFor: 'givepayments-card-expiration-block' }, __( 'Expiration Date (MM/YY)', 'givepayments-for-woocommerce' ) ),
                            el( 'input', {
                                id: 'givepayments-card-expiration-block',
                                type: 'tel',
                                inputMode: 'numeric',
                                autoComplete: 'cc-exp',
                                placeholder: '08/27',
                                value: cardExpiry,
                                onChange: ( e ) => setCardExpiry( formatExpiry( e.target.value ) ),
                            } )
                        ),
                        el(
                            'p',
                            { className: 'form-row form-row-last givepayments-field' },
                            el( 'label', { htmlFor: 'givepayments-card-cvv-block' }, __( 'Security Code', 'givepayments-for-woocommerce' ) ),
                            el( 'input', {
                                id: 'givepayments-card-cvv-block',
                                type: 'tel',
                                inputMode: 'numeric',
                                autoComplete: 'cc-csc',
                                placeholder: '424',
                                value: cardCvv,
                                onChange: ( e ) => setCardCvv( digitsOnly( e.target.value ).slice( 0, 4 ) ),
                            } )
                        )
                    ),
                    el(
                        'p',
                        { className: 'form-row form-row-wide givepayments-field' },
                        el( 'label', { htmlFor: 'givepayments-card-name-block' }, __( 'Name on Card', 'givepayments-for-woocommerce' ) ),
                        el( 'input', {
                            id: 'givepayments-card-name-block',
                            type: 'text',
                            inputMode: 'text',
                            autoComplete: 'cc-name',
                            placeholder: 'John Doe',
                            value: cardName,
                            onChange: ( e ) => setCardName( e.target.value ),
                        } )
                    )
                )
            )
        );
    };

    const BlockGateway = {
        name: 'givepayments',
        label,
        content: el( CardFields, null ),
        edit: el( CardFields, null ),
        canMakePayment: () => true,
        ariaLabel: label,
        supports: {
            features: settings.supports || [],
        },
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod( BlockGateway );
}() );