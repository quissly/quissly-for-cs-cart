/**
 * QChat "Add to Cart" -> CS-Cart's real cart (quissly_search add-on).
 *
 * Quissly's chat widget (universal_cscart.js) has no CS-Cart cart of its own: for stores
 * without a dedicated widget build it uses a generic cart that only counts
 * quantities inside the widget ("1 in Cart") and announces every change as a
 * `quissly:generic-cart-sync` window event, detail = { productId: quantity }
 * (chat-preact src/utils/genericCartConfig.js). This script applies each change
 * to the store's cart:
 *
 *  - an increase is sent as CS-Cart's own add-to-cart request (checkout.add, the
 *    same one the theme's buttons send), through CS-Cart's ajax so the mini-cart
 *    updates and CS-Cart shows its usual notification - including its own
 *    refusals (out of stock, options required);
 *  - a decrease or a removal goes to the add-on's quissly_search.cart_adjust
 *    endpoint (CS-Cart has no "change quantity by product id" request), then the
 *    mini-cart blocks are re-rendered.
 *
 * Changes are applied as differences from the last event, so a store cart that
 * already held the product simply gains or loses the difference.
 *
 * The widget names products by Quissly's own id (a UUID, e.g.
 * 18da8a23-4d29-53b6-9550-87b5fc88765d), not CS-Cart's product_id. Those are
 * translated through quissly_search.resolve, from the pairs the catalog sync
 * records; an id that cannot be translated is left alone (never guessed). A plain
 * number is taken as a CS-Cart product_id. Config: the [data-quissly-cart]
 * element's data-quissly-config JSON (lib/Storefront.php).
 */
( function () {
	'use strict';

	var host = document.querySelector( '[data-quissly-cart]' );
	var CFG = null;
	try {
		CFG = host ? JSON.parse( host.getAttribute( 'data-quissly-config' ) || 'null' ) : null;
	} catch ( e ) {
		CFG = null;
	}
	if ( ! CFG ) {
		return;
	}

	var CART_BLOCKS = 'cart_status*,wish_list*,checkout*,account_info*';
	var last = {};
	var queue = Promise.resolve();

	function ceAjax() {
		return window.Tygh && window.Tygh.$ && window.Tygh.$.ceAjax ? window.Tygh.$.ceAjax : null;
	}

	/** One CS-Cart ajax request, as a promise (resolves either way). */
	function csRequest( url, method, data ) {
		return new Promise( function ( resolve ) {
			var ajax = ceAjax();
			if ( ! ajax ) {
				// No CS-Cart ajax on the page: plain request, then reload so the cart shows.
				var body = new URLSearchParams( data || {} );
				fetch( url, { method: method, credentials: 'same-origin', body: method === 'post' ? body : undefined,
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' } } )
					.then( function () { resolve(); } , function () { resolve(); } );
				return;
			}
			ajax( 'request', url, {
				method: method,
				data: data || {},
				result_ids: CART_BLOCKS,
				full_render: true,
				callback: function () { resolve(); }
			} );
		} );
	}

	function add( productId, amount ) {
		var data = { security_hash: CFG.securityHash || '' };
		data[ 'product_data[' + productId + '][product_id]' ] = productId;
		data[ 'product_data[' + productId + '][amount]' ] = amount;
		return csRequest( CFG.addUrl, 'post', data );
	}

	function reduce( productId, amount ) {
		return fetch( CFG.adjustUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams( { security_hash: CFG.securityHash || '', product_id: productId, delta: -amount } ).toString()
		} ).then( function () {
			// Re-render the mini-cart from the current page.
			return csRequest( window.location.href, 'get', {} );
		}, function () {} );
	}

	var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
	var resolved = {};

	/** Translate Quissly ids to CS-Cart product ids (cached for the page). */
	function resolve( ids ) {
		var unknown = ids.filter( function ( id ) { return UUID.test( id ) && ! ( id.toLowerCase() in resolved ); } );
		if ( ! unknown.length || ! CFG.resolveUrl ) {
			return Promise.resolve();
		}
		var url = new URL( CFG.resolveUrl, window.location.href );
		url.searchParams.set( 'ids', unknown.join( ',' ) );
		return fetch( url.toString(), { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( map ) {
				unknown.forEach( function ( id ) { resolved[ id.toLowerCase() ] = ( map && map[ id.toLowerCase() ] ) || null; } );
			}, function () {} );
	}

	/** The CS-Cart product_id for a widget id, or null. */
	function productId( id ) {
		if ( /^[0-9]+$/.test( id ) ) {
			return id;
		}
		var found = resolved[ String( id ).toLowerCase() ];
		return found ? String( found ) : null;
	}

	window.addEventListener( 'quissly:generic-cart-sync', function ( event ) {
		var now = ( event && event.detail ) || {};
		var deltas = {};
		Object.keys( last ).concat( Object.keys( now ) ).forEach( function ( id ) {
			var delta = ( parseInt( now[ id ], 10 ) || 0 ) - ( parseInt( last[ id ], 10 ) || 0 );
			if ( delta !== 0 ) {
				deltas[ id ] = delta;
			}
		} );
		last = {};
		Object.keys( now ).forEach( function ( id ) { last[ id ] = parseInt( now[ id ], 10 ) || 0; } );

		// One change at a time, in order: CS-Cart's cart lives in the session.
		queue = queue.then( function () { return resolve( Object.keys( deltas ) ); } ).then( function () {
			return Object.keys( deltas ).reduce( function ( chain, id ) {
				var pid = productId( id );
				if ( ! pid ) {
					if ( window.console ) { console.warn( '[quissly] chat product ' + id + ' is not known to this store yet (sync it again from the Quissly Dashboard)' ); }
					return chain;
				}
				return chain.then( function () { return deltas[ id ] > 0 ? add( pid, deltas[ id ] ) : reduce( pid, -deltas[ id ] ); } );
			}, Promise.resolve() );
		} );
	} );
} )();
