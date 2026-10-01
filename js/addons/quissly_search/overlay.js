/**
 * Immersive search overlay (quissly_search add-on for CS-Cart).
 * Port of the WooCommerce plugin's overlay.js (itself a port of the Magento plugin's
 * quissly-overlay.js); behavior kept identical. CS-Cart specifics: the config comes
 * from the [data-quissly-overlay] element's data-quissly-config JSON (rendered by
 * hooks/index/scripts.post.tpl, lib/Storefront.php), a submit keeps the theme search
 * form's own hidden fields (CS-Cart's native search needs them if Quissly falls back),
 * and the header anchors are CS-Cart's.
 *
 * Clicking the theme's search box opens a centered panel over a blurred page; typing
 * there and pressing Enter goes to the normal results URL. Framework-free vanilla JS,
 * no build step, no inline JS.
 *
 * PRESENTATION ONLY — it never changes which products are returned, and it never
 * touches the results page. Escape or a backdrop click closes it and returns focus to
 * the original input, so the native experience is always one key away.
 *
 */
( function () {
	'use strict';

	var host = document.querySelector( '[data-quissly-overlay]' );
	var config = null;
	try {
		config = host ? JSON.parse( host.getAttribute( 'data-quissly-config' ) || 'null' ) : null;
	} catch ( e ) {
		config = null;
	}
	if ( ! host || ! config ) {
		return;
	}

	var resultsUrl = config.resultsUrl || 'index.php?dispatch=products.search';
	var nativeInput = findNativeInput();

	var backdrop, panel, input, lastFocus;

	// Search bar suggestions (the Shopify app's typing animation): the merchant's list,
	// kept in Quissly, typed letter by letter into the EMPTY bar's placeholder - one
	// query, deleted, the next, in random order and never the same one twice in a row -
	// while the overlay is open. Typing stops the moment the shopper types; reduced
	// motion shows one, still.
	var suggestions = ( Array.isArray( config.suggestions ) ? config.suggestions : [] ).filter( function ( q ) {
		return typeof q === 'string' && q.trim() !== '';
	} );
	var typingTimer = null;
	var typingRun = 0;
	var typingIndex = -1;

	// A random suggestion, never the one shown last - so clearing the bar does not replay
	// the same one (the Shopify app's _nextTypingIndex).
	function nextTypingIndex() {
		if ( suggestions.length < 2 ) {
			return 0;
		}
		var next = Math.floor( Math.random() * ( suggestions.length - 1 ) );
		return next >= typingIndex ? next + 1 : next;
	}

	function stopTyping() {
		typingRun++;
		clearTimeout( typingTimer );
		if ( input ) {
			input.placeholder = config.labelPlaceholder || 'Search products…';
		}
	}

	function startTyping() {
		stopTyping();
		if ( ! suggestions.length || ! input || input.value ) {
			return;
		}
		typingIndex = nextTypingIndex();
		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			input.placeholder = suggestions[ typingIndex ];
			return;
		}
		var run = typingRun;
		var length = 0;
		var deleting = false;
		( function tick() {
			if ( run !== typingRun ) {
				return;
			}
			var text = suggestions[ typingIndex ];
			length += deleting ? -1 : 1;
			input.placeholder = text.slice( 0, length );
			var delay = deleting ? 35 : 80;
			if ( ! deleting && length >= text.length ) {
				deleting = true;
				delay = 1600;
			} else if ( deleting && length <= 0 ) {
				deleting = false;
				typingIndex = nextTypingIndex();
				delay = 400;
			}
			typingTimer = setTimeout( tick, delay );
		} )();
	}


	/**
	 * The theme's own search input, tried in priority order (config.searchSelectors,
	 * lib/Storefront.php SEARCH_SELECTORS), or null if none is present.
	 *
	 * @return {Element|null}
	 */
	function findNativeInput() {
		var selectors = config.searchSelectors || [];
		for ( var i = 0; i < selectors.length; i++ ) {
			try {
				var el = document.querySelector( selectors[ i ] );
				if ( el ) {
					return el;
				}
			} catch ( e ) {
				// A stored selector that's no longer valid CSS is a stale merchant setting,
				// not a reason to stop trying the rest of the list.
			}
		}
		return null;
	}

	function build() {
		backdrop = document.createElement( 'div' );
		backdrop.className = 'quissly-overlay';
		backdrop.setAttribute( 'role', 'dialog' );
		backdrop.setAttribute( 'aria-modal', 'true' );
		backdrop.setAttribute( 'aria-label', config.labelTitle || 'Search' );

		panel = document.createElement( 'div' );
		panel.className = 'quissly-overlay__panel';

		var bar = document.createElement( 'div' );
		bar.className = 'quissly-overlay__bar';

		var field = document.createElement( 'div' );
		field.className = 'quissly-overlay__field';

		// Decorative magnifier; aria-hidden so screen readers announce only the input.
		var icon = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		icon.setAttribute( 'class', 'quissly-overlay__icon' );
		icon.setAttribute( 'viewBox', '0 0 24 24' );
		icon.setAttribute( 'aria-hidden', 'true' );
		var ring = document.createElementNS( 'http://www.w3.org/2000/svg', 'circle' );
		ring.setAttribute( 'cx', '11' );
		ring.setAttribute( 'cy', '11' );
		ring.setAttribute( 'r', '7' );
		var handle = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		handle.setAttribute( 'd', 'M20 20l-4.2-4.2' );
		icon.appendChild( ring );
		icon.appendChild( handle );

		input = document.createElement( 'input' );
		input.type = 'search';
		input.className = 'quissly-overlay__input';
		input.setAttribute( 'autocomplete', 'off' );
		input.placeholder = config.labelPlaceholder || 'Search products…';

		// Mount point future voice/image controls could attach buttons to, so every
		// input mode lives on one surface instead of floating in the header.
		var actions = document.createElement( 'div' );
		actions.className = 'quissly-overlay__actions';
		actions.setAttribute( 'data-quissly-overlay-actions', '' );

		field.appendChild( icon );
		field.appendChild( input );
		field.appendChild( actions );
		bar.appendChild( field );
		bar.appendChild( closeButton() );

		// The space under the bar where suggestions go (Quick, in the WooCommerce and
		// Magento plugins). Nothing fills it in this add-on yet, so it stays hidden.
		var results = document.createElement( 'div' );
		results.className = 'quissly-overlay__results';
		results.setAttribute( 'data-quissly-overlay-results', '' );

		panel.appendChild( bar );
		panel.appendChild( results );
		backdrop.appendChild( panel );
		document.body.appendChild( backdrop );

		backdrop.addEventListener( 'click', function ( e ) {
			// The panel spans the page width, so its empty stretches are backdrop too as
			// far as the shopper is concerned.
			if ( e.target === backdrop || e.target === panel ) {
				close();
			}
		} );
		input.addEventListener( 'input', function () {
			if ( input.value ) {
				stopTyping();
			} else if ( backdrop.classList.contains( 'is-open' ) ) {
				startTyping();
			}
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				submit();
			} else if ( e.key === 'Escape' ) {
				e.preventDefault();
				close();
			}
		} );
	}

	/**
	 * The round close button to the right of the bar.
	 *
	 * @return {Element}
	 */
	function closeButton() {
		var ns = 'http://www.w3.org/2000/svg';
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'quissly-overlay__close';
		button.setAttribute( 'aria-label', config.labelClose || 'Close' );
		var icon = document.createElementNS( ns, 'svg' );
		icon.setAttribute( 'viewBox', '0 0 24 24' );
		icon.setAttribute( 'aria-hidden', 'true' );
		var cross = document.createElementNS( ns, 'path' );
		cross.setAttribute( 'd', 'M6 6l12 12M18 6L6 18' );
		icon.appendChild( cross );
		button.appendChild( icon );
		button.addEventListener( 'click', close );
		return button;
	}

	function open() {
		if ( ! backdrop ) {
			build();
		}
		lastFocus = document.activeElement;
		// Carry over whatever the shopper already typed in the native box.
		input.value = ( nativeInput && nativeInput.value ) || '';
		document.documentElement.classList.add( 'quissly-overlay-open' );
		backdrop.classList.add( 'is-open' );
		input.focus();
		input.select();
		if ( ! input.value ) {
			startTyping();
		}
	}

	function close() {
		if ( ! backdrop ) {
			return;
		}
		stopTyping();
		backdrop.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'quissly-overlay-open' );
		if ( lastFocus && lastFocus.blur ) {
			lastFocus.blur(); // do not re-trigger the overlay on return.
		}
	}

	function submit() {
		var q = ( input.value || '' ).trim();
		if ( q === '' ) {
			return;
		}
		window.location.assign( searchUrl( q ) );
	}

	/**
	 * The results URL for q: the theme's own search form (its action and hidden fields,
	 * minus the security hash a GET does not need) when there is one, else the default
	 * results URL - so CS-Cart's native search, should Quissly fall back, gets exactly
	 * what the theme's box would have sent.
	 *
	 * @param {string} q
	 * @return {string}
	 */
	function searchUrl( q ) {
		var form = nativeInput && nativeInput.form;
		if ( form && form.action ) {
			var url = new URL( form.action, window.location.href );
			[].forEach.call( form.elements, function ( el ) {
				if ( el.name && el.type === 'hidden' && el.name !== 'security_hash' ) {
					url.searchParams.set( el.name, el.value );
				}
			} );
			url.searchParams.set( nativeInput.name || 'q', q );
			return url.toString();
		}
		var sep = resultsUrl.indexOf( '?' ) === -1 ? '?' : '&';
		return resultsUrl + sep + 'q=' + encodeURIComponent( q );
	}

	// The merchant's toggle governs ONE thing: whether an existing search box gets
	// taken over. A theme with a box of its own and the toggle off is left exactly as
	// it was — that is the merchant declining a presentation change.
	//
	// A theme with NO search box is a different question entirely. There the overlay
	// is not decoration, it is the only way to search the shop, so it is built whatever
	// the toggle says. Switching off the immersive panel is not a request to remove
	// search from the storefront.
	var immersive = !! config.immersive;
	if ( nativeInput && ! immersive ) {
		return;
	}

	// Built eagerly (hidden) so future input modes can mount into the actions slot.
	build();

	if ( nativeInput ) {
		// The theme has a search box: it becomes the trigger and never focuses.
		[ 'focus', 'click' ].forEach( function ( evt ) {
			nativeInput.addEventListener( evt, function ( e ) {
				e.preventDefault();
				nativeInput.blur();
				open();
			} );
		} );
	} else {
		// The theme has NO search box. Rather than doing nothing, offer one: a button
		// beside a header control we can positively identify, with a floating fallback
		// for when that placement is hidden or does not fit.
		buildTrigger();
	}

	/**
	 * Search button, used ONLY when the theme exposes no search input.
	 */
	function buildTrigger() {
		var trigger = document.createElement( 'button' );
		trigger.type = 'button';
		trigger.className = 'quissly-overlay-trigger';
		trigger.setAttribute( 'aria-label', config.labelTitle || 'Search' );

		var ns = 'http://www.w3.org/2000/svg';
		var icon = document.createElementNS( ns, 'svg' );
		icon.setAttribute( 'viewBox', '0 0 24 24' );
		icon.setAttribute( 'aria-hidden', 'true' );
		var ring = document.createElementNS( ns, 'circle' );
		ring.setAttribute( 'cx', '11' );
		ring.setAttribute( 'cy', '11' );
		ring.setAttribute( 'r', '7' );
		var handle = document.createElementNS( ns, 'path' );
		handle.setAttribute( 'd', 'M20 20l-4.2-4.2' );
		icon.appendChild( ring );
		icon.appendChild( handle );
		trigger.appendChild( icon );

		trigger.addEventListener( 'click', open );

		// Where a shopper already looks for search: the header, to the LEFT of the
		// utility controls (cart, account, language). We only ever insert BESIDE an
		// existing control we can positively identify — never into a layout we are
		// guessing about. The merchant can name that control (mountSelector);
		// otherwise we try the usual suspects in order.
		var anchor = findAnchor();
		if ( anchor ) {
			trigger.classList.add( 'quissly-overlay-trigger--inline' );
			placeLeftOf( trigger, anchor );
		}

		// Inline placement can be invisible — most themes collapse the header controls
		// into a drawer on small screens, and a button inside a hidden container is no
		// search at all. So a floating button exists as well, shown only while the
		// inline one has no box. Re-checked on resize.
		var floating = trigger.cloneNode( true );
		floating.classList.remove( 'quissly-overlay-trigger--inline' );
		floating.classList.add( 'quissly-overlay-trigger--floating' );
		floating.addEventListener( 'click', open );
		document.body.appendChild( floating );

		function syncFallback() {
			// Inline counts as usable only when it has a box AND it fits: a header can
			// be narrow enough that the button, pushed left along the row, runs into
			// whatever sits before the row — the logo, on most themes — or shoves the
			// row itself off the right edge. Either is worse than no inline button; the
			// floating one takes over. Un-hide before measuring: a button this function
			// hid on the last pass has no box, and a no-box reading would leave it
			// hidden (or shown) on stale grounds instead of re-deciding for the new width.
			trigger.hidden = false;
			var box = trigger.getBoundingClientRect();
			var inlineVisible = !! anchor && box.width > 0 && ! collides( trigger, box ) && ! overflows( trigger, box ) && ! covered( trigger, box );
			trigger.hidden = !! anchor && box.width > 0 && ! inlineVisible;
			floating.hidden = inlineVisible;
			if ( ! floating.hidden ) {
				clearFixedBars( floating );
			}
		}

		function clearFixedBars( el ) {
			// Many mobile themes pin a bar to the bottom of the screen (Storefront's has
			// account / search / cart icons) that sits over this corner. Under it, a tap
			// meant for search opens the theme's control instead; above it (z-index), our
			// button hides the theme's icon. So move it up to just above whatever fixed
			// element covers its spot. Re-measured from the stylesheet position each time.
			el.style.bottom = '';
			var box = el.getBoundingClientRect();
			var hit = document.elementFromPoint( box.left + box.width / 2, box.top + box.height / 2 );
			for ( var node = hit; node && node !== document.body; node = node.parentElement ) {
				if ( node === el ) {
					return;
				}
				if ( 'fixed' === window.getComputedStyle( node ).position ) {
					var bar = node.getBoundingClientRect();
					el.style.bottom = Math.max( 0, window.innerHeight - bar.top ) + 12 + 'px';
					return;
				}
			}
		}

		function covered( el, box ) {
			// Having a box isn't being seen: a collapsed mobile menu (the cart link we sit
			// beside often lives in one) clips or overlays its contents while they keep
			// their size, so the shopper has no search button at all. Hit-test the centre;
			// only judge a button that is inside the viewport - off-screen (e.g. the header
			// scrolled away) says nothing about whether it is hidden.
			var x = box.left + box.width / 2;
			var y = box.top + box.height / 2;
			if ( x < 0 || y < 0 || x >= window.innerWidth || y >= window.innerHeight ) {
				return false;
			}
			var hit = document.elementFromPoint( x, y );
			return ! hit || ( hit !== el && ! el.contains( hit ) );
		}

		function overflows( el, box ) {
			// The other way a tight header breaks: nothing overlaps, the row simply
			// grows past the viewport and the cart is pushed off-screen.
			var edge = document.documentElement.clientWidth + 1;
			var row = el.parentNode.getBoundingClientRect();
			return box.right > edge || row.right > edge;
		}

		function collides( el, box ) {
			// The nearest visible element BEFORE the button's row, in DOM order.
			var row = el.parentNode;
			var prev = row && row.previousElementSibling;
			while ( prev && prev.getBoundingClientRect().width === 0 ) {
				prev = prev.previousElementSibling;
			}
			if ( ! prev ) {
				return false;
			}
			var p = prev.getBoundingClientRect();
			var sameLine = box.top < p.bottom && box.bottom > p.top;
			// Real overlap only (1px of sub-pixel tolerance): the inline margin already
			// keeps the gap, so a touching edge is a fit, not a clash.
			return sameLine && box.left < p.right - 1;
		}
		syncFallback();
		// The first pass can run before the page has settled (styles, web fonts, images still
		// moving the header), when a hit-test sees whatever was there a moment ago. Decide
		// again once everything has loaded.
		if ( 'complete' !== document.readyState ) {
			window.addEventListener( 'load', syncFallback );
		}
		var resizeTimer = null;
		window.addEventListener( 'resize', function () {
			window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( syncFallback, 150 );
		} );
	}

	/**
	 * The header control the search button should sit beside, or null.
	 *
	 * Order: the merchant's own selector; then the leftmost visible of the cart /
	 * account / language switcher that share a container with the cart (so search
	 * leads the utility row rather than splitting it); then the account link or
	 * language switcher on their own.
	 *
	 * @return {Element|null}
	 */
	function findAnchor() {
		var custom = ( config.mountSelector || '' ).trim();
		if ( custom ) {
			try {
				var el = document.querySelector( custom );
				if ( el && el.parentNode ) {
					return el;
				}
			} catch ( e ) {
				// An invalid selector is a merchant typo, not a reason to draw nothing.
			}
		}

		// CS-Cart responsive-family header blocks.
		var CART = '.ty-dropdown-box[id^="cart_status"], [id^="cart_status_"], .ty-minicart-title, a[href*="checkout.cart"]';
		var ACCOUNT = '.ty-account-info__title, [id^="account_info_"], a[href*="profiles.update"]';
		var LANGUAGE = '.ty-select-wrapper, .ty-languages';

		// First VISIBLE match, not first in document order: themes routinely ship a
		// hidden mobile cart ahead of the visible desktop one, and a button placed
		// beside a hidden control is no search at all.
		function firstVisible( selector ) {
			var all = document.querySelectorAll( selector );
			for ( var i = 0; i < all.length; i++ ) {
				if ( all[ i ].getBoundingClientRect().width > 0 ) {
					return all[ i ];
				}
			}
			return all[ 0 ] || null;
		}

		var cart = firstVisible( CART );
		if ( cart && cart.parentNode ) {
			// Everything in the cart's row, leftmost first, so the button leads the
			// whole group instead of wedging between two of its icons.
			var row = cart.parentNode;
			var siblings = [].slice.call( row.querySelectorAll( ACCOUNT + ', ' + LANGUAGE + ', ' + CART ) )
				.filter( function ( el ) { return el.parentNode === row && el.getBoundingClientRect().width > 0; } )
				.sort( function ( a, b ) { return a.getBoundingClientRect().left - b.getBoundingClientRect().left; } );
			return siblings[ 0 ] || cart;
		}

		return firstVisible( ACCOUNT ) || firstVisible( LANGUAGE );
	}

	/**
	 * Insert the button so it renders to the visual LEFT of the anchor.
	 *
	 * DOM order alone cannot promise that: in a flex or inline header "before" is
	 * left, but a float-based header lays floated siblings out right-to-left, where
	 * "before" is RIGHT. So insert, measure, and move to the other side if it landed
	 * wrong.
	 *
	 * @param {Element} trigger
	 * @param {Element} anchor
	 */
	function placeLeftOf( trigger, anchor ) {
		var anchorFloat = window.getComputedStyle( anchor ).cssFloat || window.getComputedStyle( anchor ).float;
		if ( anchorFloat === 'right' || anchorFloat === 'left' ) {
			trigger.style.cssFloat = anchorFloat;
		}

		anchor.parentNode.insertBefore( trigger, anchor );
		var a = anchor.getBoundingClientRect();
		var t = trigger.getBoundingClientRect();
		if ( t.width > 0 && a.width > 0 && t.left > a.left ) {
			anchor.parentNode.insertBefore( trigger, anchor.nextSibling );
		}
	}

	// Keyboard shortcut: "/" opens search, the way search-first sites do.
	document.addEventListener( 'keydown', function ( e ) {
		var tag = ( e.target && e.target.tagName ) || '';
		if ( e.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA' ) {
			e.preventDefault();
			open();
		}
	} );
} )();
