/**
 * Settings screen: "Your own providers" (admin only, dependency-free ES5).
 *
 * The PHP always renders one blank row, so adding a provider works with
 * JavaScript off (save once per provider). This script only adds an
 * "Add another provider" button that clones that blank row, so several
 * can be added in one save.
 */
( function () {
	'use strict';

	function init() {
		var table = document.getElementById( 'cg-custom-providers' );
		var wrap = document.getElementById( 'cg-custom-add-wrap' );
		var button = document.getElementById( 'cg-custom-add' );
		if ( ! table || ! wrap || ! button ) {
			return;
		}
		var body = table.tBodies[ 0 ];
		wrap.hidden = false;

		// The kind's icon next to its select follows the selection.
		table.addEventListener( 'change', function ( event ) {
			var select = event.target;
			if ( ! select || 'SELECT' !== select.tagName ) {
				return;
			}
			var glyph = select.parentNode.querySelector( '.cg-kind-glyph' );
			if ( glyph ) {
				glyph.setAttribute( 'data-cg-kind', select.value );
			}
		} );

		button.addEventListener( 'click', function () {
			var rows = body.querySelectorAll( 'tr' );
			var blank = body.querySelector( 'tr[data-cg-blank]' ) || rows[ rows.length - 1 ];
			var clone = blank.cloneNode( true );
			var index = rows.length;
			clone.removeAttribute( 'data-cg-blank' );
			Array.prototype.forEach.call( clone.querySelectorAll( 'input, textarea, select' ), function ( field ) {
				field.name = field.name.replace( /\[custom_providers\]\[\d+\]/, '[custom_providers][' + index + ']' );
				if ( 'checkbox' === field.type ) {
					field.checked = false;
				} else if ( 'SELECT' === field.tagName ) {
					field.selectedIndex = 0;
				} else if ( 'hidden' !== field.type ) {
					field.value = '';
				}
			} );
			// Keep the blank row last: the clone becomes a regular row above it.
			body.insertBefore( clone, blank );
			var first = clone.querySelector( 'input[type="text"]' );
			if ( first ) {
				first.focus();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
