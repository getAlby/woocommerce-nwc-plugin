const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement } = window.wp.element;

const settings = getSetting( 'nwc_data', {} );

const label = decodeEntities( settings.title ) || 'NWC Payment Gateway';

const Content = () => {
    return decodeEntities( settings.description || '' );
};

const Label = ( props ) => {
    const { PaymentMethodLabel } = props.components;
    return createElement( PaymentMethodLabel, { text: label } );
};

registerPaymentMethod( {
    name: 'nwc',
    label: createElement( Label ),
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || [],
    },
} );