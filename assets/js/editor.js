/**
 * Calucon Third-Party Embed Gate block editor integration (PLAN.md §7.5).
 *
 * Dependency-free of any build step: plain JS against the wp.* globals.
 * Two jobs: the per-block "Gate this embed" override on blocks that carry
 * embeds, and the withdrawal-control block.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.blocks || ! wp.i18n
		|| ! wp.compose || ! wp.blockEditor || ! wp.components ) {
		return;
	}

	var __ = wp.i18n.__;
	var el = wp.element.createElement;

	// Blocks that can carry third-party embeds. core/html because pasted
	// iframe markup lives there; the rest are the embed-bearing cores.
	var GATED_BLOCKS = [ 'core/embed', 'core/html', 'core/video', 'core/audio' ];

	function isGatedBlock( name ) {
		return GATED_BLOCKS.indexOf( name ) !== -1;
	}

	wp.hooks.addFilter(
		'blocks.registerBlockType',
		'calucon-embed-gate/attribute',
		function ( settings, name ) {
			if ( ! isGatedBlock( name ) ) {
				return settings;
			}
			settings.attributes = Object.assign( {}, settings.attributes, {
				caluconEmbedGate: { type: 'string', default: '' },
				// Owner-supplied poster (§5.4): the ID is what the server
				// renders from; the URL exists only for the inspector preview.
				caluconEmbedGatePoster: { type: 'number', default: 0 },
				caluconEmbedGatePosterUrl: { type: 'string', default: '' },
				caluconEmbedGateAction: { type: 'string', default: '' },
				caluconEmbedGateNote: { type: 'string', default: '' }
			} );
			return settings;
		}
	);

	wp.hooks.addFilter(
		'editor.BlockEdit',
		'calucon-embed-gate/inspector',
		wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
			return function ( props ) {
				if ( ! isGatedBlock( props.name ) ) {
					return el( BlockEdit, props );
				}
				var value = props.attributes.caluconEmbedGate || '';
				var posterUrl = props.attributes.caluconEmbedGatePosterUrl || '';

				// Poster picker (§5.4, owner-supplied variant): images come
				// from the media library so the placeholder stays site-origin.
				var posterControls = el(
					wp.blockEditor.MediaUploadCheck,
					null,
					el( wp.blockEditor.MediaUpload, {
						allowedTypes: [ 'image' ],
						value: props.attributes.caluconEmbedGatePoster || 0,
						onSelect: function ( media ) {
							var large = media && media.sizes && media.sizes.large ? media.sizes.large.url : '';
							props.setAttributes( {
								caluconEmbedGatePoster: media && media.id ? media.id : 0,
								caluconEmbedGatePosterUrl: large || ( media && media.url ? media.url : '' )
							} );
						},
						render: function ( obj ) {
							return el(
								'div',
								{ className: 'cg-poster-control', style: { marginBottom: '16px' } },
								posterUrl ? el( 'img', {
									className: 'cg-poster-control__preview',
									src: posterUrl,
									alt: ''
								} ) : null,
								el( wp.components.Button, {
									variant: 'secondary',
									onClick: obj.open
								}, posterUrl
									? __( 'Replace poster image', 'calucon-third-party-embed-gate' )
									: __( 'Set poster image', 'calucon-third-party-embed-gate' ) ),
								posterUrl ? el( wp.components.Button, {
									variant: 'link',
									isDestructive: true,
									onClick: function () {
										props.setAttributes( { caluconEmbedGatePoster: 0, caluconEmbedGatePosterUrl: '' } );
									}
								}, __( 'Remove poster image', 'calucon-third-party-embed-gate' ) ) : null,
								el(
									'p',
									{ className: 'cg-poster-control__help' },
									__( 'Shown behind the consent panel until the visitor loads the embed. The image is served from your own media library, never fetched from the provider.', 'calucon-third-party-embed-gate' )
								)
							);
						}
					} )
				);

				return el(
					wp.element.Fragment,
					null,
					el( BlockEdit, props ),
					el(
						wp.blockEditor.InspectorControls,
						null,
						el(
							wp.components.PanelBody,
							{ title: __( 'Calucon Third-Party Embed Gate', 'calucon-third-party-embed-gate' ), initialOpen: false },
							el( wp.components.SelectControl, {
								label: __( 'Gate this embed', 'calucon-third-party-embed-gate' ),
								value: value,
								options: [
									{ value: '', label: __( 'Site default', 'calucon-third-party-embed-gate' ) },
									{ value: 'always', label: __( 'Always gate', 'calucon-third-party-embed-gate' ) },
									{ value: 'never', label: __( 'Never gate', 'calucon-third-party-embed-gate' ) }
								],
								onChange: function ( next ) {
									props.setAttributes( { caluconEmbedGate: next } );
								},
								help: value === 'never'
									? __( 'This block’s embeds will load immediately for every visitor, without a consent click.', 'calucon-third-party-embed-gate' )
									: __( 'Overrides the site-wide setting for this block only.', 'calucon-third-party-embed-gate' )
							} ),
							value === 'never' ? null : posterControls,
							value === 'never' ? null : el( wp.components.TextControl, {
								label: __( 'Button text for this embed', 'calucon-third-party-embed-gate' ),
								value: props.attributes.caluconEmbedGateAction || '',
								placeholder: __( 'Site default', 'calucon-third-party-embed-gate' ),
								onChange: function ( next ) {
									props.setAttributes( { caluconEmbedGateAction: next } );
								},
								help: __( 'Plain text, for example “Load the trailer”. Empty keeps the provider’s default.', 'calucon-third-party-embed-gate' )
							} ),
							value === 'never' ? null : el( wp.components.TextareaControl, {
								label: __( 'Notice text for this embed', 'calucon-third-party-embed-gate' ),
								value: props.attributes.caluconEmbedGateNote || '',
								placeholder: __( 'Site default', 'calucon-third-party-embed-gate' ),
								rows: 3,
								onChange: function ( next ) {
									props.setAttributes( { caluconEmbedGateNote: next } );
								},
								help: __( 'Replaces the notice above the button for this embed only. Keep it honest: the notice tells visitors what loading the content does.', 'calucon-third-party-embed-gate' )
							} )
						)
					)
				);
			};
		}, 'withCaluconEmbedGateInspector' )
	);

	// Editor-canvas badge (§7.5): the override is otherwise invisible, and
	// an editor who set "never" months ago deserves to see it at a glance.
	wp.hooks.addFilter(
		'editor.BlockListBlock',
		'calucon-embed-gate/badge',
		wp.compose.createHigherOrderComponent( function ( BlockListBlock ) {
			return function ( props ) {
				if ( ! isGatedBlock( props.name ) || ! props.attributes.caluconEmbedGate ) {
					return el( BlockListBlock, props );
				}
				var wrapperProps = Object.assign( {}, props.wrapperProps, {
					'data-calucon-embed-gate': props.attributes.caluconEmbedGate
				} );
				return el( BlockListBlock, Object.assign( {}, props, { wrapperProps: wrapperProps } ) );
			};
		}, 'withCaluconEmbedGateBadge' )
	);

	// The withdrawal control as a block (§6.2): same server-side renderer as
	// the [calucon_embed_gate_withdraw] shortcode.
	var withdrawBlock = {
		title: __( 'Withdraw embed consents', 'calucon-third-party-embed-gate' ),
		icon: 'unlock',
		category: 'widgets',
		description: __( 'A button visitors use to clear their stored embed consents. Place it on your privacy policy page. It only has an effect when consent memory is enabled.', 'calucon-third-party-embed-gate' ),
		attributes: {
			label: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			return el(
				'div',
				{ className: 'cg-withdraw-editor-preview' },
				el(
					'button',
					{ type: 'button', className: 'cg-withdraw', disabled: true },
					props.attributes.label || __( 'Withdraw embed consents', 'calucon-third-party-embed-gate' )
				),
				el(
					wp.blockEditor.InspectorControls,
					null,
					el(
						wp.components.PanelBody,
						{ title: __( 'Calucon Third-Party Embed Gate', 'calucon-third-party-embed-gate' ) },
						el( wp.components.TextControl, {
							label: __( 'Button label', 'calucon-third-party-embed-gate' ),
							value: props.attributes.label,
							onChange: function ( next ) {
								props.setAttributes( { label: next } );
							}
						} )
					)
				)
			);
		},
		save: function () {
			return null; // Dynamic block: rendered server-side (invariant 2).
		}
	};

	wp.blocks.registerBlockType( 'calucon-embed-gate/withdraw', withdrawBlock );
}( window.wp ) );
