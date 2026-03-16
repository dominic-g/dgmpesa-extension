const settings = window.wc.wcSettings.getSetting( 'dg_mpesa_checkout_data', {} );
const label = window.wp.i18n.__( 'Lipa Na Mpesa', 'dgmpesa-extension' );
const Content = () => {
	return window.wp.element.createElement(
		'div',
		null,
		window.wp.i18n.__( 'Enter your M-Pesa phone number on the next step or check your phone for the STK push prompt after placing the order.', 'dgmpesa-extension' )
	);
};

const Block_Gateway = {
	name: 'dg_mpesa_checkout',
	label: label,
	content: Object( window.wp.element.createElement )( Content, null ),
	edit: Object( window.wp.element.createElement )( Content, null ),
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
};

window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway );
