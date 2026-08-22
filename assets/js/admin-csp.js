/**
 * Settings screen: the Content-Security-Policy helper (admin only,
 * dependency-free ES5).
 *
 * Two conveniences over the server-rendered snippet:
 *
 * 1. "Check this site": loads the site's own home page ONCE, from the
 *    owner's browser, same-origin, on an explicit click — and reads the
 *    Content-Security-Policy header (or the <meta http-equiv> equivalent)
 *    off the answer. It then says whether the enabled providers' hosts are
 *    already allowed. No third party is involved and the server makes no
 *    request: this is the admin's browser asking its own site, exactly as
 *    opening the home page in a tab would.
 * 2. "Copy" for the snippet.
 *
 * The policy evaluation (parse / governing directive / source matching) is
 * exposed as window.caluconEmbedGateCspCheck for the integration tests.
 */
( function () {
	'use strict';

	var cfg = window.caluconEmbedGateCsp;
	var i18n = ( cfg && cfg.i18n ) || {};

	// Policy string → { directive: [ sources ] }. Per the spec the first
	// occurrence of a directive wins; later duplicates are ignored.
	function parse( policy ) {
		var map = {};
		String( policy || '' ).split( ';' ).forEach( function ( part ) {
			var tokens = part.replace( /^\s+|\s+$/g, '' ).split( /\s+/ );
			var name = ( tokens.shift() || '' ).toLowerCase();
			if ( name && ! Object.prototype.hasOwnProperty.call( map, name ) ) {
				map[ name ] = tokens;
			}
		} );
		return map;
	}

	// The source list that governs a fetch directive when the directive
	// itself is absent (CSP Level 3 fallback chain).
	var FALLBACK = {
		'frame-src': [ 'frame-src', 'child-src', 'default-src' ],
		'script-src': [ 'script-src', 'default-src' ]
	};

	function governing( map, directive ) {
		var chain = FALLBACK[ directive ] || [ directive, 'default-src' ];
		for ( var i = 0; i < chain.length; i++ ) {
			if ( Object.prototype.hasOwnProperty.call( map, chain[ i ] ) ) {
				return map[ chain[ i ] ];
			}
		}
		return null;
	}

	// Does one source expression permit https://host/…? Keyword sources
	// ('self', 'none', nonces, hashes) never match a third-party host. A
	// path part is ignored: a path-restricted source may still block some
	// embed URLs, which is more than this check claims to know. Per CSP3
	// an http: source also matches the https: form of the same host.
	function permits( source, host ) {
		var s = String( source ).toLowerCase();
		if ( '*' === s || 'https:' === s || 'http:' === s ) {
			return true;
		}
		if ( "'" === s.charAt( 0 ) ) {
			return false;
		}
		var m = /^(?:([a-z][a-z0-9+.-]*):\/\/)?([^/:]+)(?::(\*|\d+))?(\/.*)?$/.exec( s );
		if ( ! m ) {
			return false;
		}
		if ( m[ 1 ] && 'https' !== m[ 1 ] && 'http' !== m[ 1 ] ) {
			return false;
		}
		if ( m[ 3 ] && '*' !== m[ 3 ] && '443' !== m[ 3 ] ) {
			return false;
		}
		var h = m[ 2 ];
		if ( 0 === h.indexOf( '*.' ) ) {
			var parent = h.slice( 2 );
			return host.length > parent.length && host.slice( -( parent.length + 1 ) ) === '.' + parent;
		}
		return h === host;
	}

	// { directive: [ hosts the policy does NOT allow ] } — empty when the
	// policy allows everything required (or does not restrict it at all).
	// A site may send several Content-Security-Policy headers; the browser
	// enforces ALL of them, and Headers.get() joins them with commas (a
	// comma cannot occur inside one serialised policy), so every policy in
	// the value has to permit a host for it to count as allowed.
	function missing( header, required ) {
		var policies = String( header || '' ).split( ',' ).map( parse );
		var out = {};
		Object.keys( required || {} ).forEach( function ( directive ) {
			var miss = required[ directive ].filter( function ( host ) {
				return policies.some( function ( map ) {
					var list = governing( map, directive );
					return null !== list && ! list.some( function ( source ) {
						return permits( source, host );
					} );
				} );
			} );
			if ( miss.length ) {
				out[ directive ] = miss;
			}
		} );
		return out;
	}

	// <meta http-equiv="Content-Security-Policy" content="…"> in the home
	// page, attributes in any order, quotes optional (§3.2 tolerance).
	function metaPolicy( html ) {
		var tag = /<meta\b[^>]*\bhttp-equiv\s*=\s*["']?content-security-policy["']?[^>]*>/i.exec( String( html || '' ) );
		if ( ! tag ) {
			return '';
		}
		var content = /\bcontent\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i.exec( tag[ 0 ] );
		if ( ! content ) {
			return '';
		}
		var decoder = document.createElement( 'textarea' );
		decoder.innerHTML = content[ 1 ] || content[ 2 ] || content[ 3 ] || '';
		return decoder.value;
	}

	window.caluconEmbedGateCspCheck = { parse: parse, missing: missing, metaPolicy: metaPolicy };

	function init() {
		if ( ! cfg ) {
			return;
		}
		var checkWrap = document.getElementById( 'cg-csp-check-wrap' );
		var checkButton = document.getElementById( 'cg-csp-check' );
		var result = document.getElementById( 'cg-csp-result' );
		var copyWrap = document.getElementById( 'cg-csp-copy-wrap' );
		var copyButton = document.getElementById( 'cg-csp-copy' );
		var copied = document.getElementById( 'cg-csp-copied' );
		var snippet = document.getElementById( 'cg-csp-snippet' );

		function text( tag, value, className ) {
			var el = document.createElement( tag );
			el.textContent = value;
			if ( className ) {
				el.className = className;
			}
			return el;
		}

		function hostList( miss ) {
			var ul = document.createElement( 'ul' );
			Object.keys( miss ).forEach( function ( directive ) {
				var li = document.createElement( 'li' );
				li.appendChild( text( 'strong', ( cfg.directives && cfg.directives[ directive ] ) || directive ) );
				li.appendChild( document.createTextNode( ': ' ) );
				miss[ directive ].forEach( function ( host, i ) {
					if ( i ) {
						li.appendChild( document.createTextNode( ', ' ) );
					}
					li.appendChild( text( 'code', host ) );
				} );
				ul.appendChild( li );
			} );
			return ul;
		}

		function show( state, nodes ) {
			// Unhide before filling: a live region announces additions, and
			// some screen readers skip content added while it was hidden.
			result.innerHTML = '';
			result.className = 'cg-csp-result cg-csp-result--' + state;
			result.hidden = false;
			nodes.forEach( function ( node ) {
				result.appendChild( node );
			} );
		}

		function report( enforced, reportOnly ) {
			if ( enforced ) {
				var miss = missing( enforced, cfg.required );
				if ( Object.keys( miss ).length ) {
					show( 'todo', [ text( 'p', i18n.missing, 'cg-csp-result__verdict' ), hostList( miss ), text( 'p', i18n.missingHint, 'description' ) ] );
				} else {
					show( 'ok', [ text( 'p', i18n.clean, 'cg-csp-result__verdict' ) ] );
				}
				return;
			}
			if ( reportOnly ) {
				var would = missing( reportOnly, cfg.required );
				var nodes = [ text( 'p', i18n.reportOnly, 'cg-csp-result__verdict' ) ];
				if ( Object.keys( would ).length ) {
					nodes.push( text( 'p', i18n.reportOnlyMissing ), hostList( would ) );
				} else {
					nodes.push( text( 'p', i18n.reportOnlyClean ) );
				}
				show( 'info', nodes );
				return;
			}
			show( 'ok', [ text( 'p', i18n.none, 'cg-csp-result__verdict' ), text( 'p', i18n.noneHint, 'description' ) ] );
		}

		if ( checkWrap && checkButton && result && window.fetch && cfg.home ) {
			checkWrap.hidden = false;
			checkButton.addEventListener( 'click', function () {
				checkButton.disabled = true;
				show( 'busy', [ text( 'p', i18n.checking ) ] );
				window.fetch( cfg.home, { credentials: 'same-origin', cache: 'no-store', redirect: 'follow' } )
					.then( function ( response ) {
						var enforced = response.headers.get( 'content-security-policy' ) || '';
						var reportOnly = response.headers.get( 'content-security-policy-report-only' ) || '';
						if ( enforced ) {
							return report( enforced, reportOnly );
						}
						// Only read the body when the header is absent — the
						// <meta> form is the other place a policy can live.
						return response.text().then( function ( html ) {
							report( metaPolicy( html ), reportOnly );
						} );
					} )
					.then( null, function () {
						show( 'error', [ text( 'p', i18n.error, 'cg-csp-result__verdict' ) ] );
					} )
					.then( function () {
						checkButton.disabled = false;
					} );
			} );
		}

		if ( copyWrap && copyButton && snippet ) {
			copyWrap.hidden = false;
			copyButton.addEventListener( 'click', function () {
				// Clear first so a repeat copy is announced again.
				if ( copied ) {
					copied.textContent = '';
				}
				var done = function () {
					if ( copied ) {
						copied.textContent = i18n.copied;
					}
				};
				var fallback = function () {
					try {
						snippet.focus();
						snippet.select();
						if ( document.execCommand( 'copy' ) ) {
							done();
							return;
						}
					} catch ( e ) {
						// fall through
					}
					if ( copied ) {
						copied.textContent = i18n.copyFailed;
					}
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( snippet.value ).then( done, fallback );
				} else {
					fallback();
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
