/**
 * Settings-screen tabs (admin only, dependency-free).
 *
 * Progressive enhancement over the single settings form: the PHP renders one
 * long document with every panel visible and the tab bar hidden; this script
 * reveals the bar, hides the inactive panels, and implements the ARIA tabs
 * pattern (arrow keys, Home/End, roving tabindex).
 *
 * All panels stay inside the ONE form (where they belong to it) — tabs only
 * show and hide. Splitting the form per tab would make Options::sanitize()
 * rebuild unsent sections from defaults: silent data loss.
 *
 * Deep links: '#cg-tab-appearance' selects a tab; a hash pointing INSIDE a
 * panel (the Status scan uses '?calucon-embed-gate-scan=1#cg-status') selects that panel's
 * tab. The current tab is appended to _wp_http_referer so saving returns to
 * the tab the owner was on.
 */
( function () {
	'use strict';

	function init() {
		var tablist = document.querySelector( '.cg-tabs' );
		if ( ! tablist ) {
			return;
		}
		var tabs = Array.prototype.slice.call( tablist.querySelectorAll( '[role="tab"]' ) );
		var panels = tabs.map( function ( tab ) {
			return document.getElementById( tab.getAttribute( 'aria-controls' ) );
		} );
		if ( ! panels.length || panels.indexOf( null ) !== -1 ) {
			return;
		}
		var submit = document.querySelector( 'form p.submit' );

		function select( index, focus ) {
			tabs.forEach( function ( tab, i ) {
				var active = i === index;
				tab.classList.toggle( 'nav-tab-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				tab.tabIndex = active ? 0 : -1;
				panels[ i ].hidden = ! active;
			} );
			// The read-only panel has nothing to save — a lone visible Save
			// button under it would suggest otherwise.
			if ( submit ) {
				submit.hidden = '1' === panels[ index ].getAttribute( 'data-cg-readonly' );
			}
			if ( focus ) {
				tabs[ index ].focus();
			}
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#' + panels[ index ].id );
			}
			// options.php redirects to _wp_http_referer after save; carry the
			// tab so the owner lands back where they were.
			var referer = document.querySelector( 'input[name="_wp_http_referer"]' );
			if ( referer ) {
				referer.value = referer.value.split( '#' )[ 0 ] + '#' + panels[ index ].id;
			}
			// Panel scripts (the Appearance contrast report) may want to
			// recompute now that their panel is rendered.
			var shown;
			try {
				shown = new CustomEvent( 'cg-tab-shown', { detail: { panel: panels[ index ] } } );
			} catch ( e ) {
				shown = document.createEvent( 'CustomEvent' );
				shown.initCustomEvent( 'cg-tab-shown', true, false, { panel: panels[ index ] } );
			}
			document.dispatchEvent( shown );
		}

		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () {
				select( i, false );
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				var next = null;
				if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
					next = ( i + 1 ) % tabs.length;
				} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
					next = ( i - 1 + tabs.length ) % tabs.length;
				} else if ( 'Home' === event.key ) {
					next = 0;
				} else if ( 'End' === event.key ) {
					next = tabs.length - 1;
				}
				if ( null !== next ) {
					event.preventDefault();
					select( next, true );
				}
			} );
		} );

		// Initial tab: a panel id in the hash, or the panel containing the
		// hash target (legacy anchors like #cg-status keep working).
		var initial = 0;
		var hash = window.location.hash.slice( 1 );
		if ( hash ) {
			var target = document.getElementById( hash );
			if ( target ) {
				// closest() matches the element itself, so a panel id and an
				// anchor inside a panel both resolve to the right tab.
				var panel = target.closest ? target.closest( '.cg-tab-panel' ) : target;
				var index = panels.indexOf( panel );
				if ( index > -1 ) {
					initial = index;
				}
			}
		}

		tablist.hidden = false;
		select( initial, false );

		// The hash carries the tab (so a save lands back on it), but it is
		// also the panel's id — the browser honours it as a fragment and
		// scrolls the panel to the top, hiding the tab bar. When the hash is
		// a panel (not an anchor inside one), undo that: once now and once
		// after load, when browsers retry the fragment scroll.
		if ( panels.some( function ( panel ) { return panel.id === hash; } ) ) {
			var toTop = function () {
				window.scrollTo( 0, 0 );
			};
			toTop();
			window.addEventListener( 'load', toTop, { once: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
