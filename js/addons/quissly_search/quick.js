/**
 * Quick Recommendations (quissly_search add-on): products as the shopper types.
 *
 * The WooCommerce plugin's quick.js dropdown (itself the Magento plugin's Quick). Each pause
 * in typing POSTs the text to this store's own endpoint, which asks Quissly server-side and
 * answers rows filled from the store's products ({id, title, url, image_url, price_label,
 * original_price_label}) - never Quissly's copy. In the search overlay's bar the rows fill the
 * space under it ([data-quissly-overlay-results]); in the theme's own search box (overlay
 * switched off) they float under the box. A row opens its product; Enter with none chosen,
 * or "See all results", runs the search as usual.
 *
 * Two styles, the merchant's: a list (thumbnail, name and price per row) or a carousel of
 * larger cards; phones always get the list. Config: the [data-quissly-quick] element's
 * data-quissly-config JSON (lib/Storefront.php). Framework-free vanilla JS, no inline JS.
 */
( function () {
	'use strict';

	var host = document.querySelector( '[data-quissly-quick]' );
	var CFG = null;
	try {
		CFG = host ? JSON.parse( host.getAttribute( 'data-quissly-config' ) || 'null' ) : null;
	} catch ( e ) {
		CFG = null;
	}
	if ( ! CFG || ! CFG.url ) {
		return;
	}

	var OVERLAY_INPUT = 'quissly-overlay__input';
	var minChars = CFG.minChars || 2;
	var box = null;      // the dropdown
	var input = null;    // the field the shopper is typing in
	var rows = [];
	var active = -1;
	var timer = null;
	var asked = 0;       // the latest request; older answers are dropped

	function el( tag, cls ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		return n;
	}

	// Only http(s) and same-site paths: a product's link is merchant data on its way back.
	function safeUrl( url ) {
		return typeof url === 'string' && /^(https?:\/\/|\/(?!\/))/i.test( url );
	}

	function isOverlayInput( node ) {
		return !! ( node && node.classList && node.classList.contains( OVERLAY_INPUT ) );
	}

	function isThemeInput( node ) {
		if ( ! node || node.tagName !== 'INPUT' || isOverlayInput( node ) ) {
			return false;
		}
		return ( CFG.searchSelectors || [] ).some( function ( sel ) {
			try {
				return node.matches( sel );
			} catch ( e ) {
				return false;
			}
		} );
	}

	/** POST the text, form-encoded with the store's security hash (as media.js); rows or none. */
	function ask( term ) {
		var body = new URLSearchParams();
		if ( CFG.securityHash ) { body.set( 'security_hash', CFG.securityHash ); }
		body.set( 'q', term );
		return fetch( CFG.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.ok ? r.json() : { suggestions: [] };
		} ).then( function ( data ) {
			return data && Array.isArray( data.suggestions ) ? data.suggestions : [];
		}, function () {
			return [];
		} );
	}

	function ensureBox() {
		if ( ! box ) {
			box = el( 'div', 'quissly-quick' );
			box.setAttribute( 'role', 'listbox' );
			box.hidden = true;
			if ( CFG.style === 'carousel' ) { box.classList.add( 'quissly-quick--carousel' ); }
		}
		return box;
	}

	function hide() {
		if ( box ) {
			box.hidden = true;
			box.innerHTML = '';
		}
		rows = [];
		active = -1;
	}

	/** Under the overlay's bar, or floating under the theme's box. */
	function place() {
		var slot = isOverlayInput( input ) ? document.querySelector( '[data-quissly-overlay-results]' ) : null;
		if ( slot ) {
			if ( box.parentNode !== slot ) { slot.appendChild( box ); }
			box.classList.add( 'quissly-quick--in-overlay' );
			box.style.left = box.style.top = box.style.width = '';
			return;
		}
		if ( box.parentNode !== document.body ) { document.body.appendChild( box ); }
		box.classList.remove( 'quissly-quick--in-overlay' );
		position();
	}

	function position() {
		if ( ! box || box.hidden || ! input || box.classList.contains( 'quissly-quick--in-overlay' ) ) {
			return;
		}
		var r = input.getBoundingClientRect();
		box.style.left = ( window.pageXOffset + r.left ) + 'px';
		box.style.top = ( window.pageYOffset + r.bottom + 4 ) + 'px';
		box.style.width = Math.max( r.width, 320 ) + 'px';
	}

	function render( list ) {
		ensureBox();
		box.innerHTML = '';
		rows = [];
		active = -1;
		if ( ! list.length ) {
			hide();
			return;
		}
		var strip = el( 'div', 'quissly-quick__rows' );
		list.forEach( function ( s ) {
			var row = el( 'a', 'quissly-quick__row' );
			row.setAttribute( 'role', 'option' );
			row.href = safeUrl( s.url ) ? s.url : '#';
			var media = el( 'span', 'quissly-quick__thumb' );
			if ( safeUrl( s.image_url ) ) {
				var img = el( 'img' );
				img.src = s.image_url;
				img.alt = '';
				img.loading = 'lazy';
				media.appendChild( img );
			}
			row.appendChild( media );
			var text = el( 'span', 'quissly-quick__text' );
			var title = el( 'span', 'quissly-quick__title' );
			title.textContent = s.title || '';
			text.appendChild( title );
			if ( s.price_label ) {
				var price = el( 'span', 'quissly-quick__price' );
				if ( s.original_price_label ) {
					var was = el( 'del' );
					was.textContent = s.original_price_label;
					price.appendChild( was );
					price.appendChild( document.createTextNode( ' ' ) );
				}
				price.appendChild( document.createTextNode( s.price_label ) );
				text.appendChild( price );
			}
			row.appendChild( text );
			strip.appendChild( row );
			rows.push( row );
		} );
		box.appendChild( strip );

		var all = el( 'button', 'quissly-quick__all' );
		all.type = 'button';
		all.textContent = CFG.labelSeeAll || 'See all results';
		all.addEventListener( 'click', seeAll );
		box.appendChild( all );

		box.hidden = false;
		place();
	}

	/** The search for what is typed, as Enter would run it. */
	function seeAll() {
		if ( ! input ) { return; }
		active = -1;
		if ( isOverlayInput( input ) ) {
			input.focus();
			input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true, cancelable: true } ) );
		} else if ( input.form ) {
			input.form.submit();
		}
	}

	function suggest( node ) {
		input = node;
		var term = ( node.value || '' ).trim();
		clearTimeout( timer );
		if ( term.length < minChars ) {
			asked++;
			hide();
			return;
		}
		timer = setTimeout( function () {
			var mine = ++asked;
			ask( term ).then( function ( list ) {
				// A late answer for text that has changed since is dropped.
				if ( mine === asked && input === node && ( node.value || '' ).trim() === term ) {
					render( list );
				}
			} );
		}, CFG.debounceMs || 200 );
	}

	function move( delta ) {
		if ( ! rows.length ) { return; }
		if ( rows[ active ] ) { rows[ active ].classList.remove( 'is-active' ); }
		active = ( active + delta + rows.length ) % rows.length;
		rows[ active ].classList.add( 'is-active' );
		rows[ active ].scrollIntoView( { block: 'nearest', inline: 'nearest' } );
	}

	document.addEventListener( 'focusin', function ( e ) {
		var t = e.target;
		if ( isThemeInput( t ) ) {
			t.setAttribute( 'autocomplete', 'off' );
		}
		if ( isOverlayInput( t ) || isThemeInput( t ) ) {
			suggest( t ); // the overlay opens with the theme box's text: rows for it, or none
		}
	} );

	document.addEventListener( 'input', function ( e ) {
		if ( isOverlayInput( e.target ) || isThemeInput( e.target ) ) {
			suggest( e.target );
		}
	} );

	// Captured on the document, so a chosen row opens before the overlay runs the search.
	document.addEventListener( 'keydown', function ( e ) {
		if ( ! box || box.hidden || e.target !== input ) {
			return;
		}
		if ( e.key === 'ArrowDown' || ( e.key === 'ArrowRight' && CFG.style === 'carousel' && active >= 0 ) ) {
			e.preventDefault();
			move( 1 );
		} else if ( e.key === 'ArrowUp' || ( e.key === 'ArrowLeft' && CFG.style === 'carousel' && active >= 0 ) ) {
			e.preventDefault();
			move( -1 );
		} else if ( e.key === 'Enter' && rows[ active ] ) {
			e.preventDefault();
			e.stopPropagation();
			window.location.href = rows[ active ].href;
		} else if ( e.key === 'Escape' && ! isOverlayInput( input ) ) {
			hide();
		}
	}, true );

	document.addEventListener( 'click', function ( e ) {
		if ( box && ! box.hidden && ! box.contains( e.target ) && e.target !== input && ! isOverlayInput( input ) ) {
			hide();
		}
	} );
	window.addEventListener( 'resize', position );
	window.addEventListener( 'scroll', position, true );
}() );
