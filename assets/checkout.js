/**
 * Adds Komerza to the WooCommerce block checkout.
 *
 * Choosing it shows the description from the plugin's settings. Placing the order hands over to
 * the gateway on the server, which sends the customer on to Komerza to pay.
 */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'komerza_data', {} );
	const title = decodeEntities( settings.title || '' );
	const description = decodeEntities( settings.description || '' );

	const Description = () => ( description ? createElement( 'p', null, description ) : null );

	registerPaymentMethod( {
		name: 'komerza',
		label: title,
		ariaLabel: title,
		content: createElement( Description ),
		edit: createElement( Description ),
		canMakePayment: () => true,
		supports: { features: [ 'products' ] },
	} );
} )();
