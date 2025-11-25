const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement } = window.wp.element;

const settings = getSetting( 'mywc_data', {} );

const label = decodeEntities( settings.title ) || 'MyWC Gateway';

const Content = () => {
    return decodeEntities( settings.description || '' );
};

const Label = ( props ) => {
    const { PaymentMethodLabel } = props.components;
    return createElement( PaymentMethodLabel, { text: label } );
};

registerPaymentMethod( {
    name: 'mywc',
    label: createElement( Label ),
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || [],
    },
} );