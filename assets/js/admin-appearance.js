/**
 * Settings-screen Appearance controls: WordPress colour pickers, a live
 * preview of the placeholder panel, and a plain-language readability
 * (contrast) report so owners who cannot write CSS still end up with a
 * WCAG-readable panel.
 *
 * Admin-only. Unlike gate.js this file may depend on jQuery and
 * wp-color-picker — both ship with WordPress; nothing here is remote and
 * nothing here runs on the front end.
 */
( function ( $ ) {
	'use strict';

	// Option key -> the §7.3 custom property it overrides.
	var VARS = {
		bg: '--cg-bg',
		fg: '--cg-fg',
		accent: '--cg-accent',
		accent_fg: '--cg-accent-fg'
	};

	// Mirrors Plugin::appearance_css() — change corner values in both places.
	var RADII = {
		square: '0',
		rounded: '12px',
		pill: '12px'
	};

	$( function () {
		var stage = document.getElementById( 'cg-preview-stage' );
		var sample = stage ? stage.querySelector( '.cg-embed' ) : null;
		var report = document.getElementById( 'cg-contrast-report' );
		var i18n = window.caluconEmbedGateAdminI18n || {};
		var button = sample ? sample.querySelector( '.cg-embed__button' ) : null;
		var note = sample ? sample.querySelector( '.cg-embed__note' ) : null;
		var link = sample ? sample.querySelector( '.cg-embed__fallback a' ) : null;
		var panel = sample ? sample.querySelector( '.cg-embed__panel' ) : null;
		var withdraw = document.getElementById( 'cg-preview-withdraw' );

		// Choice controls (the former <select>s) are <details> with radios,
		// like the colour controls. Read/write them by the control's id.
		function choiceValue( id ) {
			var el = document.getElementById( id );
			if ( ! el ) {
				return '';
			}
			if ( el.classList && el.classList.contains( 'cg-choice' ) ) {
				var checked = el.querySelector( 'input[type="radio"]:checked' );
				return checked ? checked.value : '';
			}
			return el.value;
		}
		function setChoice( id, value ) {
			var el = document.getElementById( id );
			if ( ! el ) {
				return;
			}
			if ( el.classList && el.classList.contains( 'cg-choice' ) ) {
				var radios = el.querySelectorAll( 'input[type="radio"]' );
				for ( var i = 0; i < radios.length; i++ ) {
					radios[ i ].checked = radios[ i ].value === String( value );
				}
				reflectChoice( el );
				return;
			}
			if ( 'checkbox' === el.type ) {
				el.checked = !! value;
			} else {
				el.value = value;
			}
		}
		function reflectChoice( control ) {
			var checked = control.querySelector( 'input[type="radio"]:checked' );
			var name = control.querySelector( '.cg-color__name' );
			var icon = control.querySelector( ':scope > summary .cg-choice__icon' );
			if ( ! checked ) {
				return;
			}
			if ( name ) {
				name.textContent = checked.getAttribute( 'data-cg-name' ) || checked.value;
			}
			var optionIcon = checked.parentNode.querySelector( '.cg-choice__icon' );
			if ( icon && optionIcon ) {
				icon.innerHTML = optionIcon.innerHTML;
			}
		}

		// A colour control is a <details>: the summary shows the current dot
		// and name; the menu holds real radios (Default | the theme's colours
		// | Custom) and, for Custom, the picker. The checked radio IS the
		// submitted value, so none of this is needed for the form to work —
		// it keeps summary, picker and preview in step.
		function colorControl( key ) {
			return document.querySelector( '.cg-color[data-cg-color-key="' + key + '"]' );
		}
		function checkedSwatch( key ) {
			var control = colorControl( key );
			return control ? control.querySelector( 'input[type="radio"]:checked' ) : null;
		}
		function pickerField( key ) {
			return document.querySelector( '.cg-color-field[data-cg-color="' + key + '"]' );
		}
		function effectiveColor( key ) {
			var radio = checkedSwatch( key );
			if ( ! radio ) {
				var field = pickerField( key );
				return field ? field.value : '';
			}
			if ( 'custom' === radio.value ) {
				var picker = pickerField( key );
				return picker ? picker.value : '';
			}
			return radio.getAttribute( 'data-cg-hex' ) || '';
		}
		function reflectSwatch( key ) {
			var control = colorControl( key );
			var radio = checkedSwatch( key );
			if ( ! control || ! radio ) {
				return;
			}
			var custom = control.querySelector( '.cg-color__custom' );
			if ( custom ) {
				custom.hidden = 'custom' !== radio.value;
			}
			var hex = effectiveColor( key );
			var dot = control.querySelector( '.cg-color__summary .cg-color__dot' );
			var name = control.querySelector( '.cg-color__name' );
			if ( dot ) {
				dot.style.background = hex || '';
				dot.classList.toggle( 'cg-color__dot--missing', ! hex );
			}
			if ( name ) {
				var text = radio.getAttribute( 'data-cg-name' ) || '';
				name.textContent = 'custom' === radio.value && hex ? text + ' ' + hex : text;
			}
		}
		function checkSwatch( key, value ) {
			var control = colorControl( key );
			if ( ! control ) {
				return;
			}
			var radios = control.querySelectorAll( 'input[type="radio"]' );
			for ( var i = 0; i < radios.length; i++ ) {
				radios[ i ].checked = radios[ i ].value === value;
			}
			reflectSwatch( key );
		}
		function closeColorMenus( except ) {
			var open = document.querySelectorAll( '.cg-color[open]' );
			for ( var i = 0; i < open.length; i++ ) {
				if ( open[ i ] !== except ) {
					open[ i ].removeAttribute( 'open' );
				}
			}
		}

		function colorChanged( key ) {
			var value = effectiveColor( key );
			if ( 'border_color' === key ) {
				applyBorder();
				return;
			}
			if ( 'link' === key ) {
				applyLinkColor( value );
				return;
			}
			if ( 0 === key.indexOf( 'dark_' ) ) {
				palette.dark[ key.slice( 5 ) ] = value;
			} else if ( VARS[ key ] ) {
				palette.base[ key ] = value;
			}
			applyPalette();
		}

		// One palette store feeds the preview: the base colours, overlaid by
		// the dark set when both the dark option and the dark-preview toggle
		// are on — mirroring the @media (prefers-color-scheme: dark) emission.
		var palette = { base: {}, dark: {} };

		function darkPreviewActive() {
			var previewToggle = document.getElementById( 'cg-preview-dark' );
			var darkEnabled = document.getElementById( 'cg-dark-enabled' );
			return !! ( previewToggle && previewToggle.checked && darkEnabled && darkEnabled.checked );
		}

		function applyPalette() {
			if ( ! sample ) {
				return;
			}
			var dark = darkPreviewActive();
			for ( var key in VARS ) {
				if ( ! Object.prototype.hasOwnProperty.call( VARS, key ) ) {
					continue;
				}
				var value = ( dark && palette.dark[ key ] ) || palette.base[ key ] || '';
				if ( value ) {
					sample.style.setProperty( VARS[ key ], value );
					if ( withdraw ) {
						withdraw.style.setProperty( VARS[ key ], value );
					}
				} else {
					sample.style.removeProperty( VARS[ key ] );
					if ( withdraw ) {
						withdraw.style.removeProperty( VARS[ key ] );
					}
				}
			}
			refresh();
		}

		function setColor( key, value ) {
			var picker = pickerField( key );
			if ( picker && picker.value !== value ) {
				// Iris fires change before it writes the field; keep the
				// field (the submitted value) and the summary in step.
				picker.value = value;
			}
			if ( value ) {
				checkSwatch( key, 'custom' );
			} else {
				reflectSwatch( key );
			}
			colorChanged( key );
		}

		function applyPreset( preset ) {
			if ( ! stage ) {
				return;
			}
			stage.className = stage.className.replace( /\s*cg-preview--(?:minimal|card)/g, '' );
			if ( 'minimal' === preset || 'card' === preset ) {
				stage.className += ' cg-preview--' + preset;
			}
			refresh();
		}

		function applyCorners( corners ) {
			if ( ! sample ) {
				return;
			}
			var radiusRow = document.getElementById( 'cg-radius-row' );
			var radiusInput = document.getElementById( 'cg-radius' );
			if ( radiusRow ) {
				radiusRow.hidden = 'custom' !== corners;
			}
			// Inline styles beat the preview's preset class rules, matching
			// the front end where the corner CSS is emitted after the preset.
			var radius = RADII[ corners ] || null;
			if ( 'custom' === corners && radiusInput ) {
				radius = ( parseInt( radiusInput.value, 10 ) || 0 ) + 'px';
			}
			if ( null !== radius ) {
				sample.style.setProperty( '--cg-radius', radius );
				sample.style.borderRadius = radius;
				if ( withdraw ) {
					withdraw.style.setProperty( '--cg-radius', radius );
				}
			} else {
				sample.style.removeProperty( '--cg-radius' );
				sample.style.borderRadius = '';
				if ( withdraw ) {
					withdraw.style.removeProperty( '--cg-radius' );
				}
			}
			if ( button ) {
				button.style.borderRadius = 'pill' === corners ? '999px' : '';
			}
			refresh();
		}

		// Mirrors AppearanceCss::build() — border/shadow/spacing values live
		// in both places; change them together.
		function applyBorder() {
			if ( ! sample ) {
				return;
			}
			var widthInput = document.getElementById( 'cg-border-width' );
			var width = widthInput ? widthInput.value : '';
			var color = effectiveColor( 'border_color' );
			if ( '' === width ) {
				sample.style.border = '';
				sample.style.borderColor = color;
			} else if ( 0 === ( parseInt( width, 10 ) || 0 ) ) {
				sample.style.border = 'none';
			} else {
				sample.style.border = ( parseInt( width, 10 ) || 0 ) + 'px solid '
					+ ( color || 'var( --cg-fg )' );
			}
			refresh();
		}

		var SHADOWS = {
			none: 'none',
			soft: '0 1px 4px rgba(0,0,0,0.18)',
			strong: '0 6px 24px rgba(0,0,0,0.35)'
		};
		function applyShadow( shadow ) {
			if ( sample ) {
				sample.style.boxShadow = SHADOWS[ shadow ] || '';
				refresh();
			}
		}

		// Mirrors AppearanceCss::build() — sizes live in both places.
		var SIZES = {
			small: { fontSize: '0.875em', padding: '0.375em 0.75em' },
			large: { fontSize: '1.125em', padding: '0.625em 1.25em' }
		};
		function applyButtonSize( size ) {
			var config = SIZES[ size ] || { fontSize: '', padding: '' };
			var targets = [ button, withdraw ];
			for ( var i = 0; i < targets.length; i++ ) {
				if ( targets[ i ] ) {
					targets[ i ].style.fontSize = config.fontSize;
					targets[ i ].style.padding = config.padding;
				}
			}
			refresh();
		}

		function applyPlayIcon( on ) {
			if ( stage ) {
				stage.classList.toggle( 'cg-preview--icon', !! on );
			}
		}

		function applyNoteSize( size ) {
			if ( note ) {
				note.style.fontSize = 'small' === size ? '0.875em' : '';
				refresh();
			}
		}

		function applyAlign( align ) {
			if ( panel ) {
				panel.style.alignItems = 'center' === align ? 'center' : '';
				panel.style.textAlign = 'center' === align ? 'center' : '';
			}
		}

		function applyWithdrawStyle( style ) {
			if ( withdraw ) {
				withdraw.className = 'cg-withdraw' + ( 'outline' === style || 'link' === style ? ' cg-withdraw--' + style : '' );
				refresh();
			}
		}

		function applyButtonStyle( style ) {
			if ( ! button ) {
				return;
			}
			if ( 'outline' === style ) {
				button.style.background = 'transparent';
				button.style.color = 'var( --cg-fg )';
				button.style.borderColor = 'var( --cg-accent )';
			} else {
				button.style.background = '';
				button.style.color = '';
				button.style.borderColor = '';
			}
			refresh();
		}

		function applyButtonWidth( width ) {
			if ( button ) {
				button.style.width = 'full' === width ? '100%' : '';
			}
		}

		// Hover is a state, not a property: mirrored by stage classes whose
		// rules live in admin-appearance.css (same values as AppearanceCss).
		function applyHover( hover ) {
			if ( ! stage ) {
				return;
			}
			stage.classList.remove( 'cg-preview--hover-none', 'cg-preview--hover-strong' );
			if ( 'none' === hover || 'strong' === hover ) {
				stage.classList.add( 'cg-preview--hover-' + hover );
			}
		}

		// Poster preview: a bundled gradient stands in for the owner's image
		// (a data: URI — nothing is fetched), so the placement option can be
		// seen without uploading anything.
		var POSTER_SRC = 'data:image/svg+xml,' + encodeURIComponent(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 9"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6b8e9f"/><stop offset="1" stop-color="#2d3e4f"/></linearGradient></defs><rect width="16" height="9" fill="url(#g)"/></svg>'
		);
		function applyPosterPreview( on ) {
			if ( ! sample ) {
				return;
			}
			var existing = sample.querySelector( '.cg-embed__poster' );
			if ( on && ! existing ) {
				var img = document.createElement( 'img' );
				img.className = 'cg-embed__poster';
				img.setAttribute( 'alt', '' );
				img.setAttribute( 'aria-hidden', 'true' );
				img.src = POSTER_SRC;
				sample.insertBefore( img, sample.firstChild );
				sample.classList.add( 'cg-embed--poster' );
			} else if ( ! on && existing ) {
				existing.parentNode.removeChild( existing );
				sample.classList.remove( 'cg-embed--poster' );
			}
			applyPosterPanel( choiceValue( 'cg-poster-panel' ) );
			applyPosterDim( choiceValue( 'cg-poster-dim' ) );
		}

		// Mirrors AppearanceCss::build() poster placements.
		function applyPosterPanel( placement ) {
			if ( ! panel ) {
				return;
			}
			var hasPoster = sample && sample.classList.contains( 'cg-embed--poster' );
			panel.style.alignSelf = '';
			panel.style.justifySelf = '';
			panel.style.margin = '';
			panel.style.maxWidth = '';
			panel.style.borderRadius = '';
			if ( ! hasPoster ) {
				refresh();
				return;
			}
			if ( 'center' === placement ) {
				panel.style.alignSelf = 'center';
				panel.style.justifySelf = 'center';
			} else if ( 'bar' === placement ) {
				panel.style.alignSelf = 'end';
				panel.style.justifySelf = 'stretch';
				panel.style.margin = '0';
				panel.style.maxWidth = 'none';
				panel.style.borderRadius = '0 0 var( --cg-radius ) var( --cg-radius )';
			}
			refresh();
		}

		function applyLinkColor( value ) {
			var links = sample ? sample.querySelectorAll( '.cg-embed__fallback a, .cg-embed__privacy a' ) : [];
			for ( var i = 0; i < links.length; i++ ) {
				links[ i ].style.color = value || '';
			}
			refresh();
		}

		// Mirrors AppearanceCss::build() poster dimming.
		var DIMS = { light: 'brightness(0.75)', strong: 'brightness(0.5) blur(2px)' };
		function applyPosterDim( dim ) {
			var poster = sample ? sample.querySelector( '.cg-embed__poster' ) : null;
			if ( poster ) {
				poster.style.filter = DIMS[ dim ] || '';
			}
		}

		function applyNarrow( on ) {
			if ( stage ) {
				stage.classList.toggle( 'cg-preview-stage--narrow', !! on );
				refresh();
			}
		}

		// Quick styles: bundles that fill in every control as a starting
		// point. Values are plain option values — Options::sanitize() still
		// bounds them on save. Every colour pair here clears 4.5:1.
		var QUICK_STYLES = {
			cinema: {
				'cg-preset': 'default', 'cg-corners': 'rounded', 'cg-shadow': 'strong', 'cg-density': 'spacious',
				'cg-align': 'center', 'cg-button-size': 'large', 'cg-button-style': '', 'cg-button-width': '',
				'cg-hover': 'strong', 'cg-play-icon': true, 'cg-poster-panel': 'center', 'cg-poster-dim': 'strong',
				'cg-withdraw-style': 'outline', 'cg-note-size': '',
				colors: { bg: '#101418', fg: '#f3f4f6', accent: '#c62828', accent_fg: '#ffffff', link: '#f3f4f6', border_color: '' }
			},
			minimal: {
				'cg-preset': 'minimal', 'cg-corners': 'square', 'cg-shadow': 'none', 'cg-density': '',
				'cg-align': '', 'cg-button-size': '', 'cg-button-style': 'outline', 'cg-button-width': '',
				'cg-hover': '', 'cg-play-icon': false, 'cg-poster-panel': '', 'cg-poster-dim': '',
				'cg-withdraw-style': 'link', 'cg-note-size': 'small',
				// The panel is transparent here, so the text sits on the page
				// itself: a dark text colour is set explicitly rather than
				// inherited — a theme without a "contrast" preset would
				// otherwise fall back to the built-in light panel text.
				colors: { bg: '', fg: '#1b1b1b', accent: '#1b1b1b', accent_fg: '#ffffff', link: '#1b1b1b', border_color: '' }
			},
			card: {
				'cg-preset': 'card', 'cg-corners': 'custom', 'cg-radius': '16', 'cg-shadow': 'soft', 'cg-density': '',
				'cg-align': '', 'cg-button-size': '', 'cg-button-style': '', 'cg-button-width': 'full',
				'cg-hover': '', 'cg-play-icon': true, 'cg-poster-panel': 'bar', 'cg-poster-dim': 'light',
				'cg-withdraw-style': '', 'cg-note-size': '', 'cg-border-width': '1',
				colors: { bg: '#ffffff', fg: '#1f2937', accent: '#1d4ed8', accent_fg: '#ffffff', link: '#1d4ed8', border_color: '#d1d5db' }
			},
			pastel: {
				'cg-preset': 'default', 'cg-corners': 'pill', 'cg-shadow': 'none', 'cg-density': 'spacious',
				'cg-align': 'center', 'cg-button-size': '', 'cg-button-style': '', 'cg-button-width': '',
				'cg-hover': '', 'cg-play-icon': false, 'cg-poster-panel': 'center', 'cg-poster-dim': 'light',
				'cg-withdraw-style': 'outline', 'cg-note-size': '',
				colors: { bg: '#f4f1ea', fg: '#2b2b2b', accent: '#2f6f73', accent_fg: '#ffffff', link: '#2f6f73', border_color: '' }
			}
		};

		function applyQuickStyle( name ) {
			var bundle = QUICK_STYLES[ name ];
			if ( ! bundle ) {
				return;
			}
			// Start from a clean slate (reset clears the pickers AND the
			// Theme colour selects) so a bundle means the same thing
			// whatever was there before.
			resetAppearance();
			for ( var id in bundle ) {
				if ( ! Object.prototype.hasOwnProperty.call( bundle, id ) || 'colors' === id ) {
					continue;
				}
				setChoice( id, bundle[ id ] );
			}
			for ( var key in bundle.colors ) {
				if ( Object.prototype.hasOwnProperty.call( bundle.colors, key ) ) {
					var field = $( '.cg-color-field[data-cg-color="' + key + '"]' );
					if ( bundle.colors[ key ] ) {
						checkSwatch( key, 'custom' );
						field.wpColorPicker( 'color', bundle.colors[ key ] );
					} else {
						checkSwatch( key, '' );
						field.closest( '.wp-picker-container' ).find( '.wp-picker-clear' ).trigger( 'click' );
					}
				}
			}
			syncFromForm();
			// Iris paints (and fires change) on its own timer: resolve from
			// the form once more after it settles, so the preview and the
			// readability report never show a half-applied bundle.
			window.setTimeout( syncFromForm, 60 );
		}

		// Each quick-style button carries a true miniature: a scaled clone of
		// the real preview panel with the bundle's values applied, so the card
		// IS what you get. Inert and aria-hidden — the name is the label.
		function miniatureFor( bundle ) {
			if ( ! sample ) {
				return null;
			}
			var card = document.createElement( 'span' );
			card.className = 'cg-quick-card';
			card.setAttribute( 'aria-hidden', 'true' );
			var scaler = document.createElement( 'span' );
			scaler.className = 'cg-quick-card__scale';
			var clone = sample.cloneNode( true );
			var withIds = clone.querySelectorAll( '[id]' );
			for ( var i = 0; i < withIds.length; i++ ) {
				withIds[ i ].removeAttribute( 'id' );
			}
			clone.removeAttribute( 'id' );
			clone.setAttribute( 'inert', '' );
			clone.style.cssText = '';
			var poster = clone.querySelector( '.cg-embed__poster' );
			if ( poster ) {
				poster.parentNode.removeChild( poster );
				clone.classList.remove( 'cg-embed--poster' );
			}
			var map = { bg: '--cg-bg', fg: '--cg-fg', accent: '--cg-accent', accent_fg: '--cg-accent-fg' };
			if ( ! bundle ) {
				// "Theme default": the colours a cleared setting resolves to on
				// this site — the Default radio carries them.
				for ( var d in map ) {
					if ( Object.prototype.hasOwnProperty.call( map, d ) ) {
						var control = colorControl( d );
						var radio = control ? control.querySelector( 'input[type="radio"][value=""]' ) : null;
						var hex = radio ? radio.getAttribute( 'data-cg-hex' ) : '';
						if ( hex ) {
							clone.style.setProperty( map[ d ], hex );
						}
					}
				}
			}
			if ( bundle ) {
				var c = bundle.colors || {};
				for ( var key in map ) {
					if ( Object.prototype.hasOwnProperty.call( map, key ) && c[ key ] ) {
						clone.style.setProperty( map[ key ], c[ key ] );
					}
				}
				if ( 'minimal' === bundle[ 'cg-preset' ] ) {
					clone.style.background = 'transparent';
					clone.style.border = '1px solid ' + ( c.fg || '#333' );
				}
				var radius = RADII[ bundle[ 'cg-corners' ] ] || ( 'custom' === bundle[ 'cg-corners' ] ? ( bundle[ 'cg-radius' ] || 12 ) + 'px' : '' );
				if ( radius ) {
					clone.style.setProperty( '--cg-radius', radius );
					clone.style.borderRadius = radius;
				}
				if ( bundle[ 'cg-border-width' ] ) {
					clone.style.border = bundle[ 'cg-border-width' ] + 'px solid ' + ( c.border_color || c.fg || '#333' );
				}
				if ( SHADOWS[ bundle[ 'cg-shadow' ] ] ) {
					clone.style.boxShadow = SHADOWS[ bundle[ 'cg-shadow' ] ];
				}
				var panelEl = clone.querySelector( '.cg-embed__panel' );
				if ( panelEl && 'center' === bundle[ 'cg-align' ] ) {
					panelEl.style.alignItems = 'center';
					panelEl.style.textAlign = 'center';
				}
				var btn = clone.querySelector( '.cg-embed__button' );
				if ( btn ) {
					if ( 'pill' === bundle[ 'cg-corners' ] ) {
						btn.style.borderRadius = '999px';
					}
					if ( 'outline' === bundle[ 'cg-button-style' ] ) {
						btn.style.background = 'transparent';
						btn.style.color = 'var( --cg-fg )';
					}
					if ( 'full' === bundle[ 'cg-button-width' ] ) {
						btn.style.width = '100%';
					}
				}
				if ( bundle[ 'cg-play-icon' ] ) {
					scaler.classList.add( 'cg-preview--icon' );
				}
				var links = clone.querySelectorAll( '.cg-embed__fallback a, .cg-embed__privacy a' );
				for ( var l = 0; l < links.length; l++ ) {
					links[ l ].style.color = c.link || '';
				}
			}
			scaler.appendChild( clone );
			card.appendChild( scaler );
			return card;
		}

		function drawQuickCards() {
			$( '.cg-quick-style[data-cg-quick-style]' ).each( function () {
				var bundle = QUICK_STYLES[ this.getAttribute( 'data-cg-quick-style' ) ];
				if ( ! bundle || this.querySelector( '.cg-quick-card' ) ) {
					return;
				}
				var card = miniatureFor( bundle );
				if ( card ) {
					this.insertBefore( card, this.firstChild );
				}
			} );
			var reset = document.getElementById( 'cg-appearance-reset' );
			if ( reset && ! reset.querySelector( '.cg-quick-card' ) ) {
				var plain = miniatureFor( null );
				if ( plain ) {
					reset.insertBefore( plain, reset.firstChild );
				}
			}
		}

		var GAPS = { compact: '0.5rem', spacious: '1.25rem' };
		function applyDensity( density ) {
			if ( ! sample ) {
				return;
			}
			if ( GAPS[ density ] ) {
				sample.style.setProperty( '--cg-gap', GAPS[ density ] );
			} else {
				sample.style.removeProperty( '--cg-gap' );
			}
			refresh();
		}

		// --- Contrast (WCAG 2.x relative luminance), computed from what the
		// --- preview actually renders, so theme-inherited values count too.

		function parseColor( value ) {
			var m = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+%?))?\s*\)/.exec( value || '' );
			if ( ! m ) {
				return null;
			}
			var alpha = 1;
			if ( undefined !== m[ 4 ] ) {
				alpha = parseFloat( m[ 4 ] );
				if ( /%$/.test( m[ 4 ] ) ) {
					alpha /= 100;
				}
			}
			return { r: +m[ 1 ], g: +m[ 2 ], b: +m[ 3 ], a: alpha };
		}

		// Composite a (possibly translucent) colour over an opaque backdrop.
		function over( top, backdrop ) {
			return {
				r: top.r * top.a + backdrop.r * ( 1 - top.a ),
				g: top.g * top.a + backdrop.g * ( 1 - top.a ),
				b: top.b * top.a + backdrop.b * ( 1 - top.a ),
				a: 1
			};
		}

		// The backdrop an element's text really sits on: walk up through
		// transparent ancestors, compositing translucent layers.
		function effectiveBackground( el ) {
			var layers = [];
			while ( el && 1 === el.nodeType ) {
				var parsed = parseColor( getComputedStyle( el ).backgroundColor );
				if ( parsed && parsed.a > 0 ) {
					layers.push( parsed );
					if ( 1 === parsed.a ) {
						break;
					}
				}
				el = el.parentNode;
			}
			var result = { r: 255, g: 255, b: 255, a: 1 };
			for ( var i = layers.length - 1; i >= 0; i-- ) {
				result = over( layers[ i ], result );
			}
			return result;
		}

		function luminance( c ) {
			var f = function ( v ) {
				v /= 255;
				return v <= 0.04045 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
			};
			return 0.2126 * f( c.r ) + 0.7152 * f( c.g ) + 0.0722 * f( c.b );
		}

		function ratio( fg, bg ) {
			var l1 = luminance( fg );
			var l2 = luminance( bg );
			var hi = Math.max( l1, l2 );
			var lo = Math.min( l1, l2 );
			return ( hi + 0.05 ) / ( lo + 0.05 );
		}

		// Each pair names the colour key that paints its text, so a failing
		// line can offer a fix. The withdraw control only has its own text
		// colour when filled (outline/link inherit the page).
		function pairs() {
			var out = [];
			if ( note ) {
				out.push( { label: i18n.panelText, el: note, key: 'fg' } );
			}
			if ( button ) {
				var outline = 'outline' === choiceValue( 'cg-button-style' );
				out.push( { label: i18n.buttonText, el: button, key: outline ? 'fg' : 'accent_fg' } );
			}
			if ( link ) {
				out.push( { label: i18n.linkText, el: link, key: 'link' } );
			}
			// Outline and text-link withdraw styles inherit the page's own
			// text colour, which the preview cannot know — only the filled
			// style has a pair this report can measure and fix.
			if ( withdraw && '' === choiceValue( 'cg-withdraw-style' ) ) {
				out.push( { label: i18n.withdrawText, el: withdraw, key: 'accent_fg' } );
			}
			return out;
		}

		// The fix: among the theme's colours (plus black and white) pick the
		// one that passes 4.5:1 against the pair's real backdrop and is
		// closest to the current colour — a theme colour becomes a reference,
		// black/white a custom hex.
		function hexToRgb( hex ) {
			var h = hex.replace( '#', '' );
			if ( 3 === h.length ) {
				h = h[ 0 ] + h[ 0 ] + h[ 1 ] + h[ 1 ] + h[ 2 ] + h[ 2 ];
			}
			var n = parseInt( h, 16 );
			return { r: ( n >> 16 ) & 255, g: ( n >> 8 ) & 255, b: n & 255, a: 1 };
		}
		function fixPair( pair, bg ) {
			var key = pair.key;
			if ( darkPreviewActive() && VARS[ key ] ) {
				key = 'dark_' + key;
			}
			var control = colorControl( key );
			if ( ! control ) {
				return;
			}
			var current = parseColor( getComputedStyle( pair.el ).color ) || { r: 0, g: 0, b: 0, a: 1 };
			var candidates = [];
			var radios = control.querySelectorAll( 'input[type="radio"][data-cg-hex]' );
			for ( var i = 0; i < radios.length; i++ ) {
				var hex = radios[ i ].getAttribute( 'data-cg-hex' );
				if ( hex && 'custom' !== radios[ i ].value ) {
					candidates.push( { value: radios[ i ].value, hex: hex } );
				}
			}
			candidates.push( { value: 'custom', hex: '#000000' } );
			candidates.push( { value: 'custom', hex: '#ffffff' } );
			var best = null;
			for ( var c = 0; c < candidates.length; c++ ) {
				var rgb = hexToRgb( candidates[ c ].hex );
				if ( ratio( rgb, bg ) < 4.5 ) {
					continue;
				}
				var d = Math.pow( rgb.r - current.r, 2 ) + Math.pow( rgb.g - current.g, 2 ) + Math.pow( rgb.b - current.b, 2 );
				if ( ! best || d < best.d ) {
					best = { d: d, value: candidates[ c ].value, hex: candidates[ c ].hex };
				}
			}
			if ( ! best ) {
				return;
			}
			if ( 'custom' === best.value ) {
				checkSwatch( key, 'custom' );
				$( pickerField( key ) ).wpColorPicker( 'color', best.hex );
			} else {
				checkSwatch( key, best.value );
			}
			colorChanged( key );
			notify( i18n.fixedText );
		}

		function refresh() {
			if ( ! report || ! sample ) {
				return;
			}
			var template = i18n.line || '%1$s: %2$s — %3$s';
			while ( report.firstChild ) {
				report.removeChild( report.firstChild );
			}
			pairs().forEach( function ( pair ) {
				var fg = parseColor( getComputedStyle( pair.el ).color );
				var bg = effectiveBackground( pair.el );
				if ( ! fg || ! bg ) {
					return;
				}
				if ( fg.a < 1 ) {
					fg = over( fg, bg );
				}
				var r = ratio( fg, bg );
				var ok = r >= 4.5;
				var line = document.createElement( 'span' );
				line.className = 'cg-contrast-line ' + ( ok ? 'cg-contrast-line--pass' : 'cg-contrast-line--fail' );
				line.appendChild( document.createTextNode(
					template
						.replace( '%1$s', pair.label || '' )
						.replace( '%2$s', r.toFixed( 1 ) + ':1' )
						.replace( '%3$s', ( ok ? i18n.pass : i18n.fail ) || '' )
				) );
				if ( ! ok && pair.key ) {
					var fix = document.createElement( 'button' );
					fix.type = 'button';
					fix.className = 'button button-small cg-contrast-fix';
					fix.textContent = i18n.fixText || 'Make readable';
					( function ( thePair, theBg ) {
						fix.addEventListener( 'click', function () {
							fixPair( thePair, theBg );
						} );
					}( pair, bg ) );
					line.appendChild( document.createTextNode( ' ' ) );
					line.appendChild( fix );
				}
				report.appendChild( line );
			} );
		}

		// --- Unsaved-changes bar, Undo, change badges, hover highlight ---

		var form = stage ? stage.closest( 'form' ) : null;
		var unsavedBar = document.getElementById( 'cg-unsaved' );
		var undoButton = document.getElementById( 'cg-undo' );
		var baseline = '';
		var noticeShowing = false;
		var undoTimer = null;
		var submitting = false;
		// "Unsaved" means the OWNER changed something: the colour pickers
		// rewrite their fields on their own after load, and programmatic
		// syncs fire change events too — neither counts. A real interaction
		// (pointer/keyboard in the form, or a bulk action) arms the check;
		// the serialised form vs the settled baseline is the arbiter.
		var interacted = false;

		// WordPress's own hidden fields are not the owner's changes:
		// admin-tabs rewrites _wp_http_referer on every tab switch, which
		// would otherwise read as "unsaved changes" after a mere tab click.
		var BOOKKEEPING = { _wp_http_referer: 1, _wpnonce: 1, option_page: 1, action: 1 };
		function snapshot() {
			if ( ! form ) {
				return '';
			}
			return $.param( $( form ).serializeArray().filter( function ( field ) {
				return ! BOOKKEEPING[ field.name ];
			} ) );
		}
		function restoreSnapshot( serialized ) {
			var values = {};
			serialized.split( '&' ).forEach( function ( pair ) {
				if ( ! pair ) {
					return;
				}
				var parts = pair.split( '=' );
				var name = decodeURIComponent( parts[ 0 ].replace( /\+/g, ' ' ) );
				var value = decodeURIComponent( ( parts[ 1 ] || '' ).replace( /\+/g, ' ' ) );
				values[ name ] = value;
			} );
			var fields = form.querySelectorAll( 'input, select, textarea' );
			for ( var i = 0; i < fields.length; i++ ) {
				var field = fields[ i ];
				if ( ! field.name || 'submit' === field.type || 'hidden' === field.type && /nonce|_wp_http_referer|option_page|action/.test( field.name ) ) {
					continue;
				}
				if ( 'checkbox' === field.type ) {
					field.checked = values[ field.name ] === field.value;
				} else if ( 'radio' === field.type ) {
					field.checked = values[ field.name ] === field.value;
				} else if ( 'hidden' !== field.type ) {
					field.value = Object.prototype.hasOwnProperty.call( values, field.name ) ? values[ field.name ] : '';
				}
			}
			$( '.cg-color-field' ).each( function () {
				$( this ).wpColorPicker( 'color', this.value || '' );
				if ( ! this.value ) {
					$( this ).closest( '.wp-picker-container' ).find( '.wp-picker-clear' ).trigger( 'click' );
				}
			} );
			$( '.cg-color[data-cg-color-key]' ).each( function () {
				reflectSwatch( this.getAttribute( 'data-cg-color-key' ) );
			} );
			$( '.cg-choice' ).each( function () {
				reflectChoice( this );
			} );
			syncFromForm();
			window.setTimeout( syncFromForm, 60 );
		}

		function updateDirty() {
			if ( ! unsavedBar ) {
				return;
			}
			var dirty = interacted && '' !== baseline && snapshot() !== baseline;
			unsavedBar.hidden = ! dirty && ! noticeShowing;
			if ( undoButton ) {
				undoButton.hidden = ! dirty;
			}
			document.body.classList.toggle( 'cg-has-unsaved', dirty );
			updateBadges();
		}

		// A short message in the bar (status region) with an Undo for the
		// bulk actions that overwrite everything at once.
		// A short status message in the bar; the bar's Undo always reverts
		// to the state the form was loaded in (Simon's mental model), so it
		// simply shows whenever there is something to revert.
		function notify( text ) {
			if ( ! unsavedBar ) {
				return;
			}
			var label = unsavedBar.querySelector( '.cg-unsaved__text' );
			if ( label ) {
				label.textContent = text;
			}
			noticeShowing = true;
			unsavedBar.hidden = false;
			window.clearTimeout( undoTimer );
			undoTimer = window.setTimeout( function () {
				noticeShowing = false;
				if ( label ) {
					label.textContent = unsavedBar.getAttribute( 'data-cg-default-text' ) || '';
				}
				updateDirty();
			}, 6000 );
		}

		function bulkAction( run, message ) {
			interacted = true;
			run();
			notify( message );
			updateDirty();
		}

		// Change badges: how many controls in a collapsed section differ
		// from their defaults, so customisations are easy to find again.
		function controlChanged( el ) {
			if ( 'SELECT' === el.tagName ) {
				return el.selectedIndex > 0;
			}
			if ( 'checkbox' === el.type ) {
				return el.checked;
			}
			if ( 'radio' === el.type ) {
				var first = el.form ? el.form.querySelector( 'input[type="radio"][name="' + el.name.replace( /"/g, '\\"' ) + '"]' ) : null;
				return el.checked && first !== el;
			}
			if ( 'number' === el.type ) {
				return 'cg-radius' === el.id ? '12' !== el.value && '' !== el.value : '' !== el.value;
			}
			return false;
		}
		// A section's badge NAMES what differs from the defaults ("Icon,
		// Size"), and every such row gets a one-click Reset — a count alone
		// tells the owner that something was customised, not what.
		function rowLabel( row ) {
			var th = row.querySelector( 'th' );
			if ( ! th ) {
				return '';
			}
			// The label only — not the Reset button this script appends.
			var text = '';
			for ( var i = 0; i < th.childNodes.length; i++ ) {
				var node = th.childNodes[ i ];
				if ( ! ( node.classList && node.classList.contains( 'cg-row-reset' ) ) ) {
					text += node.textContent;
				}
			}
			return text.replace( /\s+/g, ' ' ).trim();
		}
		function rowChanged( row ) {
			var fields = row.querySelectorAll( 'select, input[type="checkbox"], input[type="radio"], input[type="number"]' );
			for ( var i = 0; i < fields.length; i++ ) {
				if ( controlChanged( fields[ i ] ) ) {
					return true;
				}
			}
			return false;
		}
		function resetRow( row ) {
			$( row ).find( '.cg-choice' ).each( function () {
				var first = this.querySelector( 'input[type="radio"]' );
				if ( first ) {
					setChoice( this.id, first.value );
				}
			} );
			$( row ).find( '.cg-color[data-cg-color-key]' ).each( function () {
				var key = this.getAttribute( 'data-cg-color-key' );
				var clear = this.querySelector( '.wp-picker-clear' );
				if ( clear ) {
					clear.click();
				}
				checkSwatch( key, '' );
				if ( 0 === key.indexOf( 'dark_' ) ) {
					delete palette.dark[ key.slice( 5 ) ];
				} else {
					delete palette.base[ key ];
				}
			} );
			$( row ).find( 'select' ).each( function () {
				this.selectedIndex = 0;
			} );
			$( row ).find( 'input[type="checkbox"]' ).each( function () {
				if ( this.checked ) {
					this.checked = false;
					$( this ).trigger( 'change' );
				}
			} );
			$( row ).find( 'input[type="number"]' ).each( function () {
				this.value = 'cg-radius' === this.id ? '12' : '';
			} );
			syncFromForm();
			window.setTimeout( syncFromForm, 60 );
		}
		function updateBadges() {
			$( '#cg-tab-appearance details.cg-section' ).each( function () {
				var names = [];
				$( this ).find( 'tr' ).each( function () {
					var changed = rowChanged( this );
					this.classList.toggle( 'cg-row--customised', changed );
					var reset = this.querySelector( '.cg-row-reset' );
					if ( changed ) {
						if ( ! reset ) {
							var th = this.querySelector( 'th' );
							if ( th ) {
								reset = document.createElement( 'button' );
								reset.type = 'button';
								reset.className = 'button-link cg-row-reset';
								reset.textContent = i18n.resetRow || 'Reset';
								reset.setAttribute( 'aria-label', ( i18n.resetRowAria || 'Reset %s to its default' ).replace( '%s', rowLabel( this ) ) );
								th.appendChild( reset );
							}
						}
						names.push( rowLabel( this ) );
					} else if ( reset ) {
						reset.parentNode.removeChild( reset );
					}
				} );
				var badge = this.querySelector( '.cg-section__badge' );
				if ( ! badge ) {
					badge = document.createElement( 'span' );
					badge.className = 'cg-section__badge';
					this.querySelector( ':scope > summary' ).appendChild( badge );
				}
				badge.hidden = 0 === names.length;
				var shown = names.slice( 0, 3 );
				if ( names.length > 3 ) {
					shown.push( ( i18n.moreCount || '+%d more' ).replace( '%d', String( names.length - 3 ) ) );
				}
				badge.textContent = shown.join( ', ' );
				badge.title = names.join( ', ' );
			} );
		}
		$( '#cg-tab-appearance' ).on( 'click', '.cg-row-reset', function () {
			var row = this.closest ? this.closest( 'tr' ) : $( this ).closest( 'tr' )[ 0 ];
			if ( ! row ) {
				return;
			}
			var label = rowLabel( row );
			bulkAction( function () {
				resetRow( row );
			}, ( i18n.rowReset || '%s reset to its default.' ).replace( '%s', label ) );
			updateBadges();
		} );

		// Hover/focus a row → outline what it changes in the preview.
		var HIGHLIGHT = {
			bg: '.cg-embed', fg: '.cg-embed__note', accent: '.cg-embed__button', accent_fg: '.cg-embed__button',
			link: '.cg-embed__fallback a, .cg-embed__privacy a', border_color: '.cg-embed', dark_bg: '.cg-embed',
			dark_fg: '.cg-embed__note', dark_accent: '.cg-embed__button', dark_accent_fg: '.cg-embed__button',
			preset: '.cg-embed', corners: '.cg-embed', radius: '.cg-embed', border_width: '.cg-embed', shadow: '.cg-embed',
			density: '.cg-embed__panel', align: '.cg-embed__panel', note_size: '.cg-embed__note',
			button_style: '.cg-embed__button', button_size: '.cg-embed__button', button_width: '.cg-embed__button',
			hover: '.cg-embed__button', play_icon: '.cg-embed__button', poster_panel: '.cg-embed__panel', poster_dim: '.cg-embed__poster',
			withdraw_style: '#cg-preview-withdraw'
		};
		function rowKey( row ) {
			var field = row.querySelector( '[name*="[appearance]["]' );
			if ( ! field ) {
				return null;
			}
			var m = /\[appearance\]\[([a-z_]+?)(?:_custom)?\]/.exec( field.name );
			return m ? m[ 1 ] : null;
		}
		function setHighlight( row, on ) {
			var key = rowKey( row );
			var selector = key && HIGHLIGHT[ key ];
			if ( ! selector || ! stage ) {
				return;
			}
			var targets = stage.querySelectorAll( selector );
			for ( var i = 0; i < targets.length; i++ ) {
				targets[ i ].classList.toggle( 'cg-preview-hl', on );
			}
		}

		// --- Wiring ---

		// The theme's palette as swatches under every picker (theme.json or
		// the classic editor palette, resolved server-side). Iris shows
		// swatches without names; label them so hover and screen readers
		// get the theme's own colour names.
		var paletteEntries = window.caluconEmbedGateAdminPalette || [];
		var paletteColors = [];
		for ( var p = 0; p < paletteEntries.length; p++ ) {
			if ( paletteEntries[ p ] && paletteEntries[ p ].color ) {
				paletteColors.push( paletteEntries[ p ].color );
			}
		}
		$( '.cg-color-field' ).each( function () {
			var key = this.getAttribute( 'data-cg-color' );
			$( this ).wpColorPicker( {
				palettes: paletteColors.length ? paletteColors : true,
				change: function ( event, ui ) {
					setColor( key, ui.color.toString() );
				},
				clear: function () {
					setColor( key, '' );
				}
			} );
			if ( paletteColors.length ) {
				$( this ).closest( '.wp-picker-container' ).find( '.iris-palette' ).each( function ( i ) {
					if ( paletteEntries[ i ] && paletteEntries[ i ].name ) {
						this.setAttribute( 'title', paletteEntries[ i ].name );
						this.setAttribute( 'aria-label', paletteEntries[ i ].name );
					}
				} );
			}
		} );

		if ( ! stage || ! sample ) {
			return;
		}

		// The preview is a picture of the panel, not a working embed: its
		// fallback link must never navigate the owner away from the settings.
		stage.addEventListener( 'click', function ( event ) {
			event.preventDefault();
		} );

		$( '#cg-preset' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-preset' ) );
			applyPreset( this.value );
		} );
		$( '#cg-corners' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-corners' ) );
			applyCorners( this.value );
		} );
		$( '#cg-radius' ).on( 'input change', function () {
			applyCorners( choiceValue( 'cg-corners' ) );
		} );
		$( '#cg-border-width' ).on( 'input change', applyBorder );
		$( '#cg-shadow' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-shadow' ) );
			applyShadow( this.value );
		} );
		$( '#cg-density' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-density' ) );
			applyDensity( this.value );
		} );
		$( '#cg-button-size' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-button-size' ) );
			applyButtonSize( this.value );
		} );
		$( '#cg-play-icon' ).on( 'change', function () {
			applyPlayIcon( this.checked );
		} );
		$( '#cg-note-size' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-note-size' ) );
			applyNoteSize( this.value );
		} );
		$( '#cg-align' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-align' ) );
			applyAlign( this.value );
		} );
		$( '#cg-withdraw-style' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-withdraw-style' ) );
			applyWithdrawStyle( this.value );
		} );
		$( '#cg-button-style' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-button-style' ) );
			applyButtonStyle( this.value );
		} );
		$( '#cg-button-width' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-button-width' ) );
			applyButtonWidth( this.value );
		} );
		$( '#cg-hover' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-hover' ) );
			applyHover( this.value );
		} );
		$( '#cg-poster-panel' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-poster-panel' ) );
			applyPosterPanel( this.value );
		} );
		$( '#cg-preview-poster' ).on( 'change', function () {
			applyPosterPreview( this.checked );
		} );
		$( '#cg-poster-dim' ).on( 'change', 'input[type="radio"]', function () {
			reflectChoice( document.getElementById( 'cg-poster-dim' ) );
			applyPosterDim( this.value );
		} );
		$( '#cg-preview-narrow' ).on( 'change', function () {
			applyNarrow( this.checked );
		} );
		$( '.cg-color[data-cg-color-key] input[type="radio"]' ).on( 'change', function () {
			var control = this.closest( '.cg-color' );
			var key = control.getAttribute( 'data-cg-color-key' );
			reflectSwatch( key );
			if ( 'custom' === this.value ) {
				var picker = pickerField( key );
				if ( picker && ! picker.value ) {
					// Open the picker straight away: "Custom" with nothing
					// picked yet would preview as inherit.
					$( picker ).closest( '.wp-picker-container' ).find( '.wp-color-result' ).trigger( 'click' );
				}
			} else {
				// A named choice is final: close the menu, hand focus back.
				control.removeAttribute( 'open' );
				control.querySelector( 'summary' ).focus();
			}
			colorChanged( key );
		} );
		$( '.cg-choice input[type="radio"]' ).on( 'change', function () {
			var control = this.closest( '.cg-choice' );
			control.removeAttribute( 'open' );
			control.querySelector( 'summary' ).focus();
		} );
		// One colour menu at a time; Escape closes; a click elsewhere closes.
		$( '.cg-color' ).on( 'toggle', function () {
			if ( this.open ) {
				closeColorMenus( this );
			}
		} );
		$( document ).on( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				closeColorMenus( null );
			}
		} );
		$( document ).on( 'mousedown', function ( event ) {
			if ( ! $( event.target ).closest( '.cg-color, .wp-picker-holder, .iris-picker' ).length ) {
				closeColorMenus( null );
			}
		} );
		$( '.cg-quick-style[data-cg-quick-style]' ).on( 'click', function () {
			var name = this.getAttribute( 'data-cg-quick-style' );
			bulkAction( function () {
				applyQuickStyle( name );
			}, i18n.applied || 'Style applied.' );
		} );
		if ( undoButton ) {
			undoButton.addEventListener( 'click', function () {
				if ( '' !== baseline ) {
					restoreSnapshot( baseline );
					notify( i18n.undone || 'Undone.' );
					updateDirty();
				}
			} );
		}
		if ( form ) {
			$( form ).on( 'mousedown keydown touchstart', function () {
				if ( ! interacted ) {
					// The first real interaction: everything the page did on
					// its own (picker normalisation, preview sync) is done, and
					// the edit this event precedes has not happened yet — the
					// exact "as loaded" state to compare against.
					baseline = snapshot();
				}
				interacted = true;
				updateDirty();
			} );
			$( form ).on( 'change input', function () {
				updateDirty();
			} );
			$( form ).on( 'submit', function () {
				submitting = true;
			} );
			window.addEventListener( 'beforeunload', function ( event ) {
				if ( ! submitting && interacted && '' !== baseline && snapshot() !== baseline ) {
					event.preventDefault();
					event.returnValue = i18n.leaveWarning || '';
				}
			} );
		}
		$( '#cg-tab-appearance' ).on( 'mouseenter focusin', 'tr', function () {
			setHighlight( this, true );
		} ).on( 'mouseleave focusout', 'tr', function () {
			setHighlight( this, false );
		} );
		function resetAppearance() {
			// Back to "inherit everything": selects to their first option,
			// numbers to their defaults, checkboxes off, every colour cleared
			// through the picker's own Clear so its swatch resets too.
			$( '#cg-tab-appearance .cg-choice' ).each( function () {
				var first = this.querySelector( 'input[type="radio"]' );
				if ( first ) {
					setChoice( this.id, first.value );
				}
			} );
			$( '#cg-radius' ).val( '12' );
			$( '#cg-border-width' ).val( '' );
			$( '#cg-play-icon, #cg-dark-enabled' ).prop( 'checked', false );
			$( '#cg-tab-appearance .wp-picker-clear' ).each( function () {
				this.click();
			} );
			$( '.cg-color[data-cg-color-key]' ).each( function () {
				checkSwatch( this.getAttribute( 'data-cg-color-key' ), '' );
			} );
			$( '.cg-dark-row' ).prop( 'hidden', true );
			palette = { base: {}, dark: {} };
			syncFromForm();
			window.setTimeout( syncFromForm, 60 );
		}
		$( '#cg-appearance-reset' ).on( 'click', function () {
			bulkAction( resetAppearance, i18n.resetDone || 'Appearance reset to theme defaults.' );
		} );
		$( '#cg-dark-enabled' ).on( 'change', function () {
			var rows = document.querySelectorAll( '.cg-dark-row' );
			for ( var i = 0; i < rows.length; i++ ) {
				rows[ i ].hidden = ! this.checked;
			}
			applyPalette();
		} );
		$( '#cg-preview-dark' ).on( 'change', function () {
			stage.classList.toggle( 'cg-preview-stage--dark', this.checked );
			applyPalette();
		} );

		// Recompute when admin-tabs.js reveals a panel: computed styles of a
		// display:none subtree can be unreliable across engines.
		document.addEventListener( 'cg-tab-shown', function () {
			refresh();
		} );

		// Mirror whatever the form currently holds — saved, half-edited or
		// just reset — so the preview and the controls can never disagree.
		function syncFromForm() {
		$( '.cg-color-field' ).each( function () {
			var key = this.getAttribute( 'data-cg-color' );
			var value = effectiveColor( key );
			if ( ! value || 'border_color' === key || 'link' === key ) {
				return;
			}
			if ( 0 === key.indexOf( 'dark_' ) ) {
				palette.dark[ key.slice( 5 ) ] = value;
			} else if ( VARS[ key ] ) {
				palette.base[ key ] = value;
			}
		} );
		applyPalette();
		applyCorners( choiceValue( 'cg-corners' ) );
		applyBorder();
		applyShadow( choiceValue( 'cg-shadow' ) );
		applyDensity( choiceValue( 'cg-density' ) );
		applyButtonSize( choiceValue( 'cg-button-size' ) );
		applyPlayIcon( !! ( document.getElementById( 'cg-play-icon' ) || {} ).checked );
		applyNoteSize( choiceValue( 'cg-note-size' ) );
		applyAlign( choiceValue( 'cg-align' ) );
		applyWithdrawStyle( choiceValue( 'cg-withdraw-style' ) );
		applyButtonStyle( choiceValue( 'cg-button-style' ) );
		applyButtonWidth( choiceValue( 'cg-button-width' ) );
		applyHover( choiceValue( 'cg-hover' ) );
		applyPosterPanel( choiceValue( 'cg-poster-panel' ) );
		applyPosterDim( choiceValue( 'cg-poster-dim' ) );
		applyLinkColor( effectiveColor( 'link' ) );
		applyPreset( choiceValue( 'cg-preset' ) );
		}
		$( '.cg-color[data-cg-color-key]' ).each( function () {
			reflectSwatch( this.getAttribute( 'data-cg-color-key' ) );
		} );
		$( '.cg-choice' ).each( function () {
			reflectChoice( this );
		} );
		drawQuickCards();
		syncFromForm();
		if ( unsavedBar ) {
			var defaultText = unsavedBar.querySelector( '.cg-unsaved__text' );
			unsavedBar.setAttribute( 'data-cg-default-text', defaultText ? defaultText.textContent : '' );
		}
		// Baseline once the pickers have settled (Iris normalises its fields
		// on a timer); until then nothing can read as dirty.
		window.setTimeout( function () {
			baseline = snapshot();
			updateDirty();
		}, 250 );
		updateBadges();
	} );
}( window.jQuery ) );
