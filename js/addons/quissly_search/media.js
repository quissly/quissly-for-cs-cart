/**
 * Voice and image search buttons (quissly_search add-on for CS-Cart).
 *
 * Ported from the voice/image half of the WooCommerce plugin's quick.js (which
 * mirrors the Magento plugin's quissly-voice.js / quissly-image.js). Voice is
 * captured in the browser and encoded as 16 kHz mono WAV (hard 5 s cap); an image
 * is resized to <= 1024 px JPEG. Either is POSTed to this store's own endpoint
 * (quissly_search.voice / quissly_search.image), which signs and forwards it to
 * Quissly server-side and answers with a short-lived token; the browser then opens
 * the normal search results page carrying that token.
 *
 * Where the search overlay exists it is the search surface, so the buttons mount in
 * its bar ([data-quissly-overlay-actions]); otherwise beside the theme's own search
 * box. Config: the [data-quissly-media] element's data-quissly-config JSON
 * (lib/Storefront.php). Framework-free vanilla JS, no build step, no inline JS.
 * Pure encode/resize helpers are exposed on window.quisslyTest for testing.
 */
( function () {
	'use strict';

	var host = document.querySelector( '[data-quissly-media]' );
	var CFG = null;
	try {
		CFG = host ? JSON.parse( host.getAttribute( 'data-quissly-config' ) || 'null' ) : null;
	} catch ( e ) {
		CFG = null;
	}
	if ( ! CFG ) {
		return;
	}
	var T = CFG.i18n || {};

	// ---------------------------------------------------------------------------------
	// Pure helpers (testable): WAV encode + image resize.
	// ---------------------------------------------------------------------------------

	/** Resample mono Float32 PCM to 16 kHz (linear interpolation). */
	function resampleTo16kMono( input, inputRate ) {
		var outRate = 16000;
		if ( inputRate === outRate ) {
			return input;
		}
		var ratio = inputRate / outRate;
		var outLength = Math.floor( input.length / ratio );
		var out = new Float32Array( outLength );
		for ( var i = 0; i < outLength; i++ ) {
			var pos = i * ratio;
			var left = Math.floor( pos );
			var right = Math.min( left + 1, input.length - 1 );
			var frac = pos - left;
			out[ i ] = input[ left ] * ( 1 - frac ) + input[ right ] * frac;
		}
		return out;
	}

	/** Encode mono Float32 PCM to a 16 kHz, 16-bit, mono WAV ArrayBuffer. */
	function encodeWav( samples, inputRate ) {
		var pcm = resampleTo16kMono( samples, inputRate );
		var sampleRate = 16000;
		var bytesPerSample = 2;
		var dataSize = pcm.length * bytesPerSample;
		var buffer = new ArrayBuffer( 44 + dataSize );
		var view = new DataView( buffer );

		function writeString( offset, str ) {
			for ( var i = 0; i < str.length; i++ ) {
				view.setUint8( offset + i, str.charCodeAt( i ) );
			}
		}

		writeString( 0, 'RIFF' );
		view.setUint32( 4, 36 + dataSize, true );
		writeString( 8, 'WAVE' );
		writeString( 12, 'fmt ' );
		view.setUint32( 16, 16, true ); // fmt chunk size
		view.setUint16( 20, 1, true ); // PCM
		view.setUint16( 22, 1, true ); // mono
		view.setUint32( 24, sampleRate, true );
		view.setUint32( 28, sampleRate * bytesPerSample, true ); // byte rate
		view.setUint16( 32, bytesPerSample, true ); // block align
		view.setUint16( 34, 16, true ); // bits per sample
		writeString( 36, 'data' );
		view.setUint32( 40, dataSize, true );

		var offset = 44;
		for ( var i = 0; i < pcm.length; i++ ) {
			var s = Math.max( -1, Math.min( 1, pcm[ i ] ) );
			view.setInt16( offset, s < 0 ? s * 0x8000 : s * 0x7fff, true );
			offset += 2;
		}
		return buffer;
	}

	/**
	 * Resize an image source so its longest edge is <= maxEdge, and export JPEG.
	 * Returns { dataUrl, width, height }.
	 */
	function resizeImageToJpeg( source, maxEdge, quality ) {
		maxEdge = maxEdge || 1024;
		quality = quality || 0.85;
		var sw = source.naturalWidth || source.width;
		var sh = source.naturalHeight || source.height;
		var scale = Math.min( 1, maxEdge / Math.max( sw, sh ) );
		var w = Math.max( 1, Math.round( sw * scale ) );
		var h = Math.max( 1, Math.round( sh * scale ) );
		var canvas = document.createElement( 'canvas' );
		canvas.width = w;
		canvas.height = h;
		canvas.getContext( '2d' ).drawImage( source, 0, 0, w, h );
		return { dataUrl: canvas.toDataURL( 'image/jpeg', quality ), width: w, height: h };
	}

	function base64FromArrayBuffer( buffer ) {
		var bytes = new Uint8Array( buffer ), bin = '';
		for ( var i = 0; i < bytes.length; i++ ) { bin += String.fromCharCode( bytes[ i ] ); }
		return btoa( bin );
	}

	window.quisslyTest = window.quisslyTest || {};
	window.quisslyTest.resampleTo16kMono = resampleTo16kMono;
	window.quisslyTest.encodeWav = encodeWav;
	window.quisslyTest.resizeImageToJpeg = resizeImageToJpeg;

	// ---------------------------------------------------------------------------------
	// Talking to the store.
	// ---------------------------------------------------------------------------------

	function el( tag, cls ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		return n;
	}

	/**
	 * POST form fields to the store's endpoint. Form-encoded (not JSON) so CS-Cart
	 * reads the fields - and its security_hash - like any storefront form. Resolves
	 * with the decoded JSON, or { error } when there is none.
	 */
	function post( url, fields ) {
		var body = new URLSearchParams();
		body.set( 'security_hash', CFG.securityHash || '' );
		Object.keys( fields ).forEach( function ( k ) { body.set( k, fields[ k ] ); } );
		return fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json().catch( function () { return { error: 'http_' + r.status }; } );
		}, function () {
			return { error: 'network' };
		} );
	}

	/** Open the results page for a token: the normal search URL, plus the token. */
	function openResults( queryVar, token, term ) {
		var url = new URL( CFG.resultsUrl, window.location.href );
		url.searchParams.set( 'q', term );
		url.searchParams.set( queryVar, token );
		window.location.href = url.toString();
	}

	/** @param {function(string):void} say */
	function handleAnswer( data, fallbackTerm, say ) {
		if ( ! data || ! data.token ) {
			say( T.failed || 'Search is unavailable right now. Please try again.' );
			return;
		}
		openResults( data.query_var, data.token, ( data.transcription || '' ).trim() || fallbackTerm );
	}

	// ---------------------------------------------------------------------------------
	// Icons (stroked, to match the overlay's magnifier).
	// ---------------------------------------------------------------------------------

	function buildIcon( paths, circles ) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.9' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		( circles || [] ).forEach( function ( c ) {
			var parts = c.split( ',' );
			var circle = document.createElementNS( ns, 'circle' );
			circle.setAttribute( 'cx', parts[ 0 ] );
			circle.setAttribute( 'cy', parts[ 1 ] );
			circle.setAttribute( 'r', parts[ 2 ] );
			svg.appendChild( circle );
		} );
		( paths || [] ).forEach( function ( d ) {
			var path = document.createElementNS( ns, 'path' );
			path.setAttribute( 'd', d );
			svg.appendChild( path );
		} );
		return svg;
	}

	function micIcon() {
		return buildIcon(
			[ 'M12 3.5a2.6 2.6 0 0 1 2.6 2.6v5a2.6 2.6 0 0 1-5.2 0v-5A2.6 2.6 0 0 1 12 3.5z',
				'M5.5 11.2a6.5 6.5 0 0 0 13 0', 'M12 17.7V20.5', 'M9 20.5h6' ],
			[]
		);
	}

	function cameraIcon() {
		return buildIcon( [ 'M3.5 8.8h3.2l1.5-2.3h7.6l1.5 2.3h3.2v9.7H3.5z' ], [ '12,13.4,3.1' ] );
	}

	// ---------------------------------------------------------------------------------
	// Voice.
	// ---------------------------------------------------------------------------------

	/**
	 * @param {function(string):void} say    Status line.
	 * @param {Element}               button Gets a "recording" class while capturing.
	 */
	function startVoice( say, button ) {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			// getUserMedia does not exist at all on a non-secure origin: worth telling
			// apart from a declined microphone prompt (handled below).
			say( window.isSecureContext === false
				? ( T.micInsecure || 'Voice search needs a secure (https) connection.' )
				: ( T.micUnsupported || 'Voice search is not supported in this browser.' ) );
			return;
		}
		navigator.mediaDevices.getUserMedia( { audio: true } ).then( function ( stream ) {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			var ctx = new Ctx();
			var source = ctx.createMediaStreamSource( stream );
			var processor = ctx.createScriptProcessor( 4096, 1, 1 );
			var chunks = [];
			processor.onaudioprocess = function ( ev ) {
				chunks.push( new Float32Array( ev.inputBuffer.getChannelData( 0 ) ) );
			};
			source.connect( processor );
			processor.connect( ctx.destination );
			button.classList.add( 'recording' );
			say( T.listening || 'Listening…' );

			setTimeout( function () { // hard cap
				processor.disconnect();
				source.disconnect();
				stream.getTracks().forEach( function ( t ) { t.stop(); } );
				button.classList.remove( 'recording' );
				var total = chunks.reduce( function ( n, c ) { return n + c.length; }, 0 );
				var merged = new Float32Array( total ), o = 0;
				chunks.forEach( function ( c ) { merged.set( c, o ); o += c.length; } );
				var wav = encodeWav( merged, ctx.sampleRate );
				ctx.close();
				say( T.searching || 'Searching…' );
				post( CFG.voiceUrl, { audio: base64FromArrayBuffer( wav ) } ).then( function ( data ) {
					handleAnswer( data, T.voiceFallbackQuery || 'your voice search', say );
				} );
			}, CFG.voiceMaxMs || 5000 );
		} ).catch( function () {
			say( T.micDenied || 'Microphone unavailable.' );
		} );
	}

	// ---------------------------------------------------------------------------------
	// Image.
	// ---------------------------------------------------------------------------------

	/** One path for both the file picker and a pasted image. */
	function useImageFile( file, say ) {
		if ( ! file ) { return; }
		say( T.searching || 'Searching…' );
		var img = new Image();
		img.onload = function () {
			var out = resizeImageToJpeg( img, CFG.imageMaxEdge || 1024 );
			URL.revokeObjectURL( img.src );
			post( CFG.imageUrl, { image: out.dataUrl.split( ',' )[ 1 ] } ).then( function ( data ) {
				handleAnswer( data, T.imageFallbackQuery || 'your image search', say );
			} );
		};
		img.onerror = function () { say( T.failed || 'Search is unavailable right now. Please try again.' ); };
		img.src = URL.createObjectURL( file );
	}

	function startImage( say ) {
		var input = el( 'input' );
		input.type = 'file';
		input.accept = 'image/*';
		input.addEventListener( 'change', function () {
			var file = input.files && input.files[ 0 ];
			input.value = ''; // allow re-picking the same file
			useImageFile( file, say );
		} );
		input.click();
	}

	// ---------------------------------------------------------------------------------
	// Mounting.
	// ---------------------------------------------------------------------------------

	function findInputs() {
		var found = [];
		( CFG.searchSelectors || [] ).forEach( function ( sel ) {
			try {
				document.querySelectorAll( sel ).forEach( function ( n ) {
					if ( found.indexOf( n ) === -1 && ! n.classList.contains( 'quissly-overlay__input' ) ) { found.push( n ); }
				} );
			} catch ( e ) {}
		} );
		return found;
	}

	/**
	 * The buttons, in `holder`, each with its own status line. `inBar` picks the
	 * overlay-bar styling (see styles.less) over the beside-the-box styling.
	 */
	function mountButtons( holder, inBar ) {
		var prefix = inBar ? 'quissly' : 'quissly-control quissly-control';
		if ( CFG.enableVoice ) {
			var mic = el( 'button', inBar ? 'quissly-voice-button' : prefix + '--voice' );
			mic.type = 'button';
			mic.setAttribute( 'aria-label', T.voice || 'Voice search' );
			mic.title = T.voice || 'Voice search';
			mic.appendChild( micIcon() );
			var voiceNotice = el( 'span', 'quissly-voice-notice' );
			voiceNotice.setAttribute( 'role', 'status' );
			mic.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				voiceNotice.textContent = '';
				startVoice( function ( text ) { voiceNotice.textContent = text; }, mic );
			} );
			holder.appendChild( mic );
			holder.appendChild( voiceNotice );
		}
		if ( CFG.enableImage ) {
			var cam = el( 'button', inBar ? 'quissly-image-button' : prefix + '--image' );
			cam.type = 'button';
			cam.setAttribute( 'aria-label', T.image || 'Search by image' );
			cam.title = T.image || 'Search by image';
			cam.appendChild( cameraIcon() );
			var imageNotice = el( 'span', 'quissly-image-notice' );
			imageNotice.setAttribute( 'role', 'status' );
			cam.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				imageNotice.textContent = '';
				startImage( function ( text ) { imageNotice.textContent = text; } );
			} );
			holder.appendChild( cam );
			holder.appendChild( imageNotice );
		}
	}

	/** Into the overlay's bar. Returns whether the bar exists. */
	function attachToOverlay() {
		var actions = document.querySelector( '[data-quissly-overlay-actions]' );
		if ( ! actions ) { return false; }
		// Scripts load in either order: buttons put beside a box that now only opens
		// the overlay would be duplicates.
		document.querySelectorAll( '.quissly-controls' ).forEach( function ( n ) { n.remove(); } );
		if ( ! actions.dataset.quisslyControls ) {
			actions.dataset.quisslyControls = '1';
			mountButtons( actions, true );
		}
		return true;
	}

	/** Beside each theme search box (no overlay on this page). */
	function attachBesideInputs() {
		findInputs().forEach( function ( input ) {
			if ( input.dataset.quisslyControls ) { return; }
			input.dataset.quisslyControls = '1';
			var holder = el( 'span', 'quissly-controls' );
			mountButtons( holder, false );
			if ( holder.childNodes.length && input.parentNode ) {
				input.parentNode.insertBefore( holder, input.nextSibling );
				input.parentNode.classList.add( 'quissly-has-controls' );
			}
		} );
	}

	/**
	 * On a voice/image results page the q in the URL is a placeholder, not what the
	 * shopper typed - so it is not left in the search box. A real transcription stays.
	 */
	function clearPlaceholderTerm() {
		var params = new URLSearchParams( window.location.search );
		var image = params.has( CFG.imageQueryVar );
		var voice = params.has( CFG.voiceQueryVar );
		if ( ! image && ! voice ) { return; }
		findInputs().forEach( function ( input ) {
			if ( image || input.value === ( T.voiceFallbackQuery || 'your voice search' ) ) {
				input.value = '';
			}
		} );
	}

	/** Paste an image straight into the search box (or the page). Text pastes untouched. */
	function initPaste() {
		if ( ! CFG.enableImage ) { return; }
		document.addEventListener( 'paste', function ( event ) {
			var target = event.target;
			var ours = ! target || target === document.body || findInputs().indexOf( target ) !== -1
				|| ( target.classList && target.classList.contains( 'quissly-overlay__input' ) );
			if ( ! ours ) { return; }
			var items = ( event.clipboardData || {} ).items;
			var file = null;
			for ( var i = 0; items && i < items.length && ! file; i++ ) {
				if ( items[ i ].kind === 'file' && String( items[ i ].type ).indexOf( 'image/' ) === 0 ) {
					file = items[ i ].getAsFile();
				}
			}
			if ( ! file ) { return; }
			event.preventDefault();
			var notice = document.querySelector( '.quissly-image-notice' );
			useImageFile( file, notice ? function ( text ) { notice.textContent = text; } : function () {} );
		} );
	}

	function init() {
		clearPlaceholderTerm();
		initPaste();
		if ( attachToOverlay() ) { return; }
		// The overlay script may build its bar after this runs; until it does, the
		// buttons sit beside the theme's box, and move into the bar if it appears.
		attachBesideInputs();
		if ( document.querySelector( '[data-quissly-overlay]' ) && window.MutationObserver ) {
			var obs = new MutationObserver( function () {
				if ( attachToOverlay() ) { obs.disconnect(); }
			} );
			obs.observe( document.body, { childList: true } );
			setTimeout( function () { obs.disconnect(); }, 10000 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
