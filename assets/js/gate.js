/**
 * Calucon Third-Party Embed Gate front end.
 *
 * Dependency-free, ES5-compatible by design (PLAN.md §11): must run before
 * any framework and on old browsers. Does nothing until the visitor clicks —
 * the placeholder itself is server-rendered (invariant 2) and this script
 * stores nothing, ever (invariant 3).
 */
( function () {
	'use strict';

	// Mirror of the server-side safelist (PLAN.md §5.2). Never style, never
	// on* — and autoplay never survives the rebuild (invariant 8). 'type'
	// only ever arrives in <embed>/<object> payloads, whose server safelist
	// is narrower still. The identity attributes (id, name, class,
	// data-secret, security) carry no capability but real integrations key
	// on them — the YouTube JS API, <form target>, wp-embed.js resizing.
	var SAFELIST = [ 'title', 'width', 'height', 'sandbox', 'loading', 'allow', 'allowfullscreen', 'referrerpolicy', 'type', 'id', 'name', 'class', 'data-secret', 'security', 'alt' ];

	function hasClass( el, name ) {
		return el && el.nodeType === 1 && ( ' ' + el.className + ' ' ).indexOf( ' ' + name + ' ' ) !== -1;
	}

	function closestByClass( el, name ) {
		while ( el && el !== document ) {
			if ( hasClass( el, name ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function findByClass( root, tagName, className ) {
		var els = root.getElementsByTagName( tagName );
		for ( var i = 0; i < els.length; i++ ) {
			if ( hasClass( els[ i ], className ) ) {
				return els[ i ];
			}
		}
		return null;
	}

	function stripAutoplay( allow ) {
		var parts = String( allow ).split( ';' );
		var kept = [];
		for ( var i = 0; i < parts.length; i++ ) {
			var feature = parts[ i ].replace( /^\s+|\s+$/g, '' );
			if ( feature && feature.toLowerCase().indexOf( 'autoplay' ) !== 0 ) {
				kept.push( feature );
			}
		}
		return kept.join( '; ' );
	}

	function buildFrame( payload ) {
		var src = typeof payload.src === 'string' ? payload.src : '';
		var srcdoc = typeof payload.srcdoc === 'string' ? payload.srcdoc : '';
		var tag = payload.tag === 'embed' || payload.tag === 'object' || payload.tag === 'img' ? payload.tag : 'iframe';

		// Only http(s) or protocol-relative URLs may be loaded. Anything else
		// in the payload is treated as hostile and ignored. A srcdoc payload
		// carries the embed's original inline document instead of a URL —
		// restoring it verbatim is the same privilege the page already had.
		if ( ! srcdoc && ! /^(https?:)?\/\//i.test( src ) ) {
			return null;
		}

		var frame = document.createElement( tag );
		var attrs = payload.attrs || {};
		for ( var i = 0; i < SAFELIST.length; i++ ) {
			var name = SAFELIST[ i ];
			if ( ! Object.prototype.hasOwnProperty.call( attrs, name ) ) {
				continue;
			}
			var value = attrs[ name ];
			if ( name === 'allowfullscreen' ) {
				if ( value ) {
					frame.setAttribute( 'allowfullscreen', '' );
				}
				continue;
			}
			if ( name === 'allow' ) {
				value = stripAutoplay( value );
				if ( ! value ) {
					continue;
				}
			}
			frame.setAttribute( name, String( value ) );
		}
		if ( srcdoc ) {
			frame.setAttribute( 'srcdoc', srcdoc );
		} else {
			// An <object> spells its target 'data'; iframe and embed use 'src'.
			frame.setAttribute( tag === 'object' ? 'data' : 'src', src );
		}
		return frame;
	}

	// Consent memory (PLAN.md §6.2): OFF unless the site enabled it. Nothing
	// is ever written before the first click (invariant 3) — page-load code
	// only READS storage. Client-side only (§6.3): server-side state would
	// make every page uncacheable.
	var STORAGE_KEY = 'calucon-embed-gate';

	function memoryConfig() {
		var config = window.caluconEmbedGateConfig || {};
		var memory = config.memory === 'session' || config.memory === 'persistent' ? config.memory : 'off';
		return {
			memory: memory,
			scope: config.scope === 'embed' || config.scope === 'all' ? config.scope : 'provider',
			durationDays: typeof config.durationDays === 'number' && config.durationDays > 0 ? config.durationDays : 180
		};
	}

	function memoryStore( config ) {
		try {
			return config.memory === 'session' ? window.sessionStorage : window.localStorage;
		} catch ( e ) {
			return null; // Storage blocked: memory silently degrades to off.
		}
	}

	function readGrants( config ) {
		var store = memoryStore( config );
		if ( ! store ) {
			return {};
		}
		var grants;
		try {
			grants = JSON.parse( store.getItem( STORAGE_KEY ) || '{}' ).g || {};
		} catch ( e ) {
			return {};
		}
		// Lazily expire persistent grants past their lifetime.
		var cutoff = config.memory === 'persistent' ? Date.now() - config.durationDays * 86400000 : 0;
		var live = {};
		for ( var key in grants ) {
			if ( Object.prototype.hasOwnProperty.call( grants, key ) && grants[ key ] >= cutoff ) {
				live[ key ] = grants[ key ];
			}
		}
		return live;
	}

	function writeGrants( config, grants ) {
		var store = memoryStore( config );
		if ( ! store ) {
			return;
		}
		try {
			store.setItem( STORAGE_KEY, JSON.stringify( { v: 1, g: grants } ) );
		} catch ( e ) {
			// Full or blocked storage: the click still works, just unremembered.
		}
	}

	// Grant keys carry no identifier — only what was consented to (§6.2):
	// the embed URL, the provider id, or everything. The generic providers
	// cover EVERY unknown third party, so their keys carry the host too —
	// consenting to one unknown widget must never auto-load another.
	function grantKeys( config, container, payload ) {
		if ( config.scope === 'all' ) {
			return [ '*' ];
		}
		if ( config.scope === 'embed' ) {
			return [ 'e:' + String( payload.src || payload.srcdoc || '' ) ];
		}
		var providerId = String( container.getAttribute( 'data-cg-provider' ) || '' );
		var key = 'p:' + providerId;
		if ( providerId.indexOf( 'generic' ) === 0 ) {
			key += '@' + String( container.getAttribute( 'data-cg-host' ) || '' );
		}
		return [ key ];
	}

	function rememberConsent( container, payload ) {
		var config = memoryConfig();
		if ( config.memory === 'off' ) {
			return;
		}
		var grants = readGrants( config );
		var keys = grantKeys( config, container, payload );
		for ( var i = 0; i < keys.length; i++ ) {
			grants[ keys[ i ] ] = Date.now();
		}
		writeGrants( config, grants );
	}

	// config and grants are passed in so the restore pass reads storage once
	// for the whole page instead of once per placeholder.
	function hasStoredConsent( config, grants, container, payload ) {
		if ( Object.prototype.hasOwnProperty.call( grants, '*' ) ) {
			return true;
		}
		var keys = grantKeys( config, container, payload );
		for ( var i = 0; i < keys.length; i++ ) {
			if ( Object.prototype.hasOwnProperty.call( grants, keys[ i ] ) ) {
				return true;
			}
		}
		return false;
	}

	function withdrawConsent() {
		// Art. 7(3): withdrawal must be as easy as giving consent. Clear the
		// plugin's key from both storages; embeds are gated again from the
		// next page load.
		try {
			window.sessionStorage.removeItem( STORAGE_KEY );
		} catch ( e ) { /* Storage blocked: nothing was stored. */ }
		try {
			window.localStorage.removeItem( STORAGE_KEY );
		} catch ( e ) { /* Storage blocked: nothing was stored. */ }
	}

	// Script load state per URL, so a provider SDK is fetched exactly once no
	// matter how many embeds it serves (PLAN.md §9.6).
	var scriptStates = {};

	// Invoked after a provider script loads AND after each later activation:
	// SDKs like Strava's embed.js only render the placeholders present when
	// they run (PLAN.md §9.6). Sites can add hooks for custom providers via
	// window.caluconEmbedGateReadyHooks before or after this script loads.
	var readyHooks = {
		strava: function () {
			if ( window.__STRAVA_EMBED_BOOTSTRAP__ ) {
				window.__STRAVA_EMBED_BOOTSTRAP__();
			}
		},
		twitter: function () {
			if ( window.twttr && window.twttr.widgets && window.twttr.widgets.load ) {
				window.twttr.widgets.load();
			}
		},
		instagram: function () {
			if ( window.instgrm && window.instgrm.Embeds ) {
				window.instgrm.Embeds.process();
			}
		},
		facebook: function () {
			if ( window.FB && window.FB.XFBML ) {
				window.FB.XFBML.parse();
			}
		}
	};

	function runReadyHook( providerId ) {
		var custom = window.caluconEmbedGateReadyHooks || {};
		var hook = custom[ providerId ] || readyHooks[ providerId ];
		if ( hook ) {
			try {
				hook();
			} catch ( e ) {
				// A broken provider hook must not break the page.
			}
		}
	}

	function loadScriptOnce( src, done, fail ) {
		var state = scriptStates[ src ];
		if ( state && state.loaded ) {
			done();
			return;
		}
		if ( state ) {
			state.callbacks.push( done );
			if ( fail ) {
				state.failures.push( fail );
			}
			return;
		}
		state = scriptStates[ src ] = { loaded: false, callbacks: [ done ], failures: fail ? [ fail ] : [] };
		var el = document.createElement( 'script' );
		el.async = true;
		el.src = src;
		el.onload = function () {
			state.loaded = true;
			var callbacks = state.callbacks;
			state.callbacks = [];
			state.failures = [];
			for ( var i = 0; i < callbacks.length; i++ ) {
				callbacks[ i ]();
			}
		};
		el.onerror = function () {
			// A blocked or unreachable SDK must be reportable (§8), and a
			// retry must be possible: forget the state so the next click
			// creates a fresh script element — and remove the dead element
			// so retries do not accumulate them in <head>.
			var failures = state.failures;
			delete scriptStates[ src ];
			if ( el.parentNode ) {
				el.parentNode.removeChild( el );
			}
			for ( var i = 0; i < failures.length; i++ ) {
				failures[ i ]();
			}
		};
		document.head.appendChild( el );
	}

	function i18n( key, fallback ) {
		var config = window.caluconEmbedGateConfig || {};
		return ( config.i18n && config.i18n[ key ] ) || fallback;
	}

	// Loading state announced politely (PLAN.md §8): find-or-create the
	// container's live region. It must exist before its text changes or
	// screen readers may not announce the change.
	function setStatus( container, message ) {
		var status = findByClass( container, 'span', 'cg-embed__status' );
		if ( ! status ) {
			status = document.createElement( 'span' );
			status.className = 'cg-embed__status';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			container.appendChild( status );
		}
		status.textContent = message;
	}

	// Error state announced via role="alert" with a route to the fallback
	// (PLAN.md §8): silent failure leaves a keyboard user on a dead button.
	function showError( container ) {
		if ( container.getElementsByClassName( 'cg-embed__error' ).length ) {
			return;
		}
		setStatus( container, '' );
		var error = document.createElement( 'p' );
		error.className = 'cg-embed__error';
		error.setAttribute( 'role', 'alert' );
		error.appendChild( document.createTextNode(
			i18n( 'error', 'The embedded content could not be loaded.' ) + ' '
		) );
		var href = container.getAttribute( 'data-cg-fallback' ) || '';
		if ( /^(https?:)?\/\//i.test( href ) ) {
			var link = document.createElement( 'a' );
			link.setAttribute( 'href', href );
			link.setAttribute( 'rel', 'noopener nofollow' );
			link.appendChild( document.createTextNode(
				i18n( 'errorLink', 'Open it on the provider’s site.' )
			) );
			error.appendChild( link );
		}
		container.appendChild( error );
	}

	function removePanel( container ) {
		container.setAttribute( 'data-cg-activated', '1' );
		if ( ! hasClass( container, 'cg-embed--active' ) ) {
			container.className += ' cg-embed--active';
		}
		var panel = findByClass( container, 'div', 'cg-embed__panel' );
		if ( panel && panel.parentNode === container ) {
			// Keep the fallback destination reachable for the error state
			// before the panel (and its link) is removed. Target the §5.1
			// fallback wrapper by class — "the last link in the panel" would
			// pick the privacy-policy link when the site shows one.
			var fallbackWrap = findByClass( panel, 'p', 'cg-embed__fallback' );
			var links = ( fallbackWrap || panel ).getElementsByTagName( 'a' );
			if ( links.length && ! container.getAttribute( 'data-cg-fallback' ) ) {
				container.setAttribute( 'data-cg-fallback', links[ 0 ].getAttribute( 'href' ) || '' );
			}
		}
		// The panel goes, and the poster image goes with it — left in place
		// it would sit behind (or, grid-stacked, on top of) the activated
		// embed. Removed nodes are stashed IN DOCUMENT ORDER so a CMP-bridge
		// revocation (§6.4) can restore the placeholder exactly as the
		// server rendered it.
		var stash    = [];
		var children = container.childNodes;
		for ( var j = 0; j < children.length; j++ ) {
			var child = children[ j ];
			if ( child === panel || ( child.nodeType === 1 && child.nodeName === 'IMG' && hasClass( child, 'cg-embed__poster' ) ) ) {
				stash.push( child );
			}
		}
		for ( var k = 0; k < stash.length; k++ ) {
			container.removeChild( stash[ k ] );
		}
		// A retried activation after a failed SDK load finds no panel in the
		// DOM (it is already stashed); overwriting the stash with the empty
		// result would orphan the original nodes forever.
		if ( ! container._cgStash || ! container._cgStash.length ) {
			container._cgStash = stash;
		}
	}

	// Undo a bridge activation (§6.4): remove the built frame, restore the
	// stashed panel and poster, and hand the container back to the gate.
	// Only ever called for containers the BRIDGE activated — a visitor's own
	// click is a separate, more specific consent that a category withdrawal
	// in the CMP does not override.
	function restorePanel( container ) {
		var nodes = container._cgStash || [];
		var frames = [];
		var children = container.childNodes;
		for ( var i = 0; i < children.length; i++ ) {
			var child = children[ i ];
			if ( child.nodeType !== 1 ) {
				continue;
			}
			var name = child.nodeName;
			if ( name === 'IFRAME' || name === 'EMBED' || name === 'OBJECT'
				|| ( name === 'IMG' && ! hasClass( child, 'cg-embed__poster' ) )
				// A failed load may have appended the §8 error alert; a
				// restored panel must not sit next to a stale one.
				|| ( name === 'P' && hasClass( child, 'cg-embed__error' ) ) ) {
				frames.push( child );
			}
		}
		for ( var j = 0; j < frames.length; j++ ) {
			container.removeChild( frames[ j ] );
		}
		// Re-insert before the live-region status span (appended during
		// activation), keeping the restored DOM in server order.
		var status = findByClass( container, 'span', 'cg-embed__status' );
		for ( var l = 0; l < nodes.length; l++ ) {
			container.insertBefore( nodes[ l ], status );
		}
		container._cgStash = null;
		container.removeAttribute( 'data-cg-activated' );
		container.removeAttribute( 'data-cg-bridged' );
		container.removeAttribute( 'tabindex' ); // Added by a focusing activation; the restored panel manages its own focus.
		container.className = ( ' ' + container.className + ' ' )
			.replace( ' cg-embed--active ', ' ' )
			.replace( /^\s+|\s+$/g, '' );
		setStatus( container, '' );
	}

	function activateScript( container, payload, focus ) {
		var src = typeof payload.src === 'string' ? payload.src : '';
		if ( ! /^(https?:)?\/\//i.test( src ) ) {
			showError( container );
			return;
		}
		var providerId = container.getAttribute( 'data-cg-provider' ) || '';
		var host = container.getAttribute( 'data-cg-host' ) || '';

		removePanel( container );
		setStatus( container, i18n( 'loading', 'Loading embedded content…' ) );
		if ( focus ) {
			container.setAttribute( 'tabindex', '-1' );
			container.focus();
		}

		loadScriptOnce( src, function () {
			// One SDK renders every companion element on the page, so the
			// other panels for the SAME provider are now redundant — clear
			// them, but only AFTER the SDK actually loaded. Clearing them up
			// front would delete their fallback links too if the script were
			// blocked (ad/tracker blockers hit exactly these SDKs), stranding
			// every sibling embed until a reload. Compared by attribute value,
			// never by selector interpolation, and the host must match too:
			// 'generic-script' spans every unknown third party, so clicking
			// one widget must not delete another provider's placeholder.
			var all = document.querySelectorAll
				? document.querySelectorAll( '.cg-embed[data-cg-provider]' )
				: [];
			for ( var i = 0; i < all.length; i++ ) {
				if ( all[ i ] !== container
					&& all[ i ].getAttribute( 'data-cg-provider' ) === providerId
					&& ( all[ i ].getAttribute( 'data-cg-host' ) || '' ) === host
					&& all[ i ].parentNode ) {
					all[ i ].parentNode.removeChild( all[ i ] );
				}
			}
			setStatus( container, '' );
			runReadyHook( providerId );
		}, function () {
			container.removeAttribute( 'data-cg-activated' );
			showError( container );
		} );
	}

	function activate( container, options ) {
		options = options || {};
		if ( container.getAttribute( 'data-cg-activated' ) === '1' ) {
			return;
		}

		var payload;
		try {
			payload = JSON.parse( container.getAttribute( 'data-cg-payload' ) || '' );
		} catch ( e ) {
			// Malformed payload: announce it (§8); the panel and its
			// fallback link are still in place.
			if ( options.focus ) {
				showError( container );
			}
			return;
		}

		// Storage is written AFTER the click, never before (invariant 3);
		// a memory-restored activation only reads and re-stores nothing.
		if ( options.remember ) {
			rememberConsent( container, payload );
		}

		// A CMP-bridge activation (§6.4) is marked so a later withdrawal in
		// the CMP can re-gate exactly what the bridge loaded — and nothing
		// the visitor activated by their own click.
		if ( options.bridged ) {
			container.setAttribute( 'data-cg-bridged', '1' );
		}

		if ( payload.strategy === 'script' ) {
			activateScript( container, payload, !! options.focus );
			return;
		}

		var frame = buildFrame( payload );
		if ( ! frame ) {
			// Unloadable src: announce it (§8); panel and fallback link stay.
			if ( options.focus ) {
				showError( container );
			}
			return;
		}

		removePanel( container );
		setStatus( container, i18n( 'loading', 'Loading embedded content…' ) );
		frame.onload = function () {
			setStatus( container, '' );
		};
		frame.onerror = function () {
			showError( container );
		};
		container.appendChild( frame );

		// Focus the container, not the inserted node: if a provider script
		// later replaces the node, focus would silently fall back to <body>
		// and throw the keyboard user to the top of the page (PLAN.md §8).
		if ( options.focus ) {
			container.setAttribute( 'tabindex', '-1' );
			container.focus();
		}
	}

	// With memory enabled (§6.2), re-activate what the visitor already
	// consented to. Read-only: no write happens on page load, and no focus
	// moves — there was no user gesture.
	function restoreFromMemory() {
		var config = memoryConfig();
		if ( config.memory === 'off' || ! document.querySelectorAll ) {
			return;
		}
		// One storage read + parse for the whole page; nothing writes during
		// this read-only pass, so the snapshot cannot go stale.
		var grants = readGrants( config );
		var containers = document.querySelectorAll( '.cg-embed[data-cg-payload]' );
		for ( var i = 0; i < containers.length; i++ ) {
			var container = containers[ i ];
			var payload;
			try {
				payload = JSON.parse( container.getAttribute( 'data-cg-payload' ) || '' );
			} catch ( e ) {
				continue;
			}
			if ( hasStoredConsent( config, grants, container, payload ) ) {
				activate( container, { focus: false, remember: false } );
			}
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var withdraw = closestByAttribute( event.target, 'data-cg-withdraw' );
		if ( withdraw ) {
			event.preventDefault();
			withdrawConsent();
			announceWithdrawal( withdraw );
			return;
		}

		var button = closestByClass( event.target, 'cg-embed__button' );
		if ( ! button ) {
			return;
		}
		var container = closestByClass( button, 'cg-embed' );
		if ( container ) {
			event.preventDefault();
			activate( container, { focus: true, remember: true } );
		}
	}, false );

	function closestByAttribute( el, name ) {
		while ( el && el !== document ) {
			if ( el.nodeType === 1 && el.hasAttribute && el.hasAttribute( name ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function announceWithdrawal( trigger ) {
		var status = document.getElementById( trigger.getAttribute( 'aria-controls' ) || '' );
		if ( status ) {
			status.textContent = i18n( 'withdrawn', 'Stored embed consents have been removed. Embeds will ask again.' );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', restoreFromMemory, false );
	} else {
		restoreFromMemory();
	}

	// ---- CMP bridge surface (§6.4) ------------------------------------
	// Consumed by assets/js/cmp-bridge.js, which is only enqueued when the
	// site enabled the bridge AND a tested consent platform is installed.
	// Grants activate without moving focus (no user gesture on this page)
	// and without writing consent memory — the CMP owns that state, and the
	// plugin stores nothing (invariant 3). Everything here fails closed:
	// no querySelectorAll, no payload, no stash — nothing happens.

	function bridgeEach( callback ) {
		if ( ! document.querySelectorAll ) {
			return;
		}
		var containers = document.querySelectorAll( '.cg-embed[data-cg-payload]' );
		for ( var i = 0; i < containers.length; i++ ) {
			var container = containers[ i ];
			if ( container.getAttribute( 'data-cg-activated' ) === '1' ) {
				continue;
			}
			var payload;
			try {
				payload = JSON.parse( container.getAttribute( 'data-cg-payload' ) || '' );
			} catch ( e ) {
				continue;
			}
			callback( container, payload );
		}
	}

	function bridgeGrant( container ) {
		activate( container, { focus: false, remember: false, bridged: true } );
	}

	function bridgeRegate( predicate ) {
		if ( ! document.querySelectorAll ) {
			return;
		}
		var bridged = document.querySelectorAll( '.cg-embed[data-cg-bridged="1"]' );
		var reload  = false;
		for ( var i = 0; i < bridged.length; i++ ) {
			var container = bridged[ i ];
			if ( predicate && ! predicate( container ) ) {
				continue;
			}
			var payload;
			try {
				payload = JSON.parse( container.getAttribute( 'data-cg-payload' ) || '' );
			} catch ( e ) {
				payload = {};
			}
			if ( payload.strategy === 'script' ) {
				// A loaded SDK cannot be unloaded, and activating it removed
				// the companion placeholders from the DOM. Reloading is the
				// only honest revocation — the same conclusion the CMPs
				// themselves reached (Complianz forces a reload on revoke).
				reload = true;
				continue;
			}
			restorePanel( container );
		}
		if ( reload ) {
			window.location.reload();
		}
	}

	window.caluconEmbedGateBridge = {
		each: bridgeEach,
		grant: bridgeGrant,
		grantAll: function () {
			bridgeEach( function ( container ) {
				bridgeGrant( container );
			} );
		},
		regate: bridgeRegate
	};
}() );
