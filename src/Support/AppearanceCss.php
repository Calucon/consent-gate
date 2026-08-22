<?php
/**
 * Appearance CSS (PLAN.md §7.1): the settings screen's preset and colour
 * choices as a stylesheet fragment. WordPress-free pure logic — a sanitised
 * appearance array in, a CSS string out — so the emitted rules can be pinned
 * byte-for-byte in the unit suite without booting WordPress.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the inline style attached to the front-end stylesheet.
 */
final class AppearanceCss {

	/**
	 * Button glyphs by provider kind — inline SVG paths on a 16×16 box,
	 * drawn through a CSS mask in the button's own text colour. Bundled
	 * bytes only (invariant 9). The settings preview mirrors the 'video'
	 * glyph in assets/css/admin-appearance.css — change both together.
	 */
	private const GLYPHS = array(
		'generic'  => 'M3 3h5v2H5v6h6V8h2v5H3zM9 2h5v5h-2V5.4l-4.3 4.3-1.4-1.4L10.6 4H9z',
		'video'    => 'M4 2l10 6-10 6z',
		'map'      => 'M8 0a5 5 0 0 0-5 5c0 3.5 5 11 5 11s5-7.5 5-11a5 5 0 0 0-5-5zm0 7a2 2 0 1 1 0-4 2 2 0 0 1 0 4z',
		'audio'    => 'M5 2v9.2A2.5 2.5 0 1 0 7 13.5V6l6-2V1z',
		// Glyphs below rely on the evenodd fill rule set in mask(): a
		// subpath drawn inside another cuts a hole (frames, text lines).
		'social'   => 'M1 2h14v9H6.5L2.5 15v-4H1z',
		'form'     => 'M3 1h10v14H3zM5 4h6v1.5H5zM5 7.25h6v1.5H5zM5 10.5h4v1.5H5z',
		'calendar' => 'M2 3h12v11H2zM3.5 7.5h9v5h-9zM4 1h2v1.5H4zM10 1h2v1.5h-2z',
		'document' => 'M3 1h7l4 4v10H3zM5 8h6v1.5H5zM5 11h6v1.5H5z',
		'image'    => 'M1 2.5h14v11H1zM2.5 4h11v8h-11zM3.5 11l3-4.5 2 2.5 1.5-1.5 2.5 3.5zM10.2 5.2a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4z',
		'3d'       => 'M8 1l6 3.4L8 7.8 2 4.4zM1.5 5.4l5.7 3.2v6.2L1.5 11.6zM14.5 5.4 8.8 8.6v6.2l5.7-3.2z',
	);

	/** Kinds a provider may declare, in the order the settings list them. Generic is ''. */
	public const KINDS = array( 'video', 'map', 'audio', 'social', 'form', 'calendar', 'document', 'image', '3d' );

	/**
	 * CSS for the Appearance settings (§7.1): preset + colour overrides of
	 * the §7.3 custom properties. '' when everything is at defaults.
	 *
	 * @param array $appearance Sanitised appearance option subtree.
	 * @param array $kinds      Provider id => kind (one of self::KINDS or ''),
	 *                          for the kind-aware button glyph. Unknown and
	 *                          generic providers get the generic glyph.
	 * @param array $palette    Theme palette slug => hex, for the fallback in
	 *                          emitted var(--wp--preset--color--…) references.
	 * @return string
	 */
	public static function build( array $appearance, array $kinds = array(), array $palette = array() ): string {
		// Tolerate pre-0.10 subtrees (missing keys) so the builder stays
		// callable with any sanitised snapshot, old or new.
		$a    = $appearance + array(
			'preset'         => 'default',
			'corners'        => '',
			'bg'             => '',
			'fg'             => '',
			'accent'         => '',
			'accent_fg'      => '',
			'radius'         => 12,
			'border_width'   => '',
			'border_color'   => '',
			'shadow'         => '',
			'density'        => '',
			'button_size'    => '',
			'play_icon'      => false,
			'note_size'      => '',
			'align'          => '',
			'dark'           => false,
			'dark_bg'        => '',
			'dark_fg'        => '',
			'dark_accent'    => '',
			'dark_accent_fg' => '',
			'button_style'   => '',
			'button_width'   => '',
			'hover'          => '',
			'poster_panel'   => '',
			'poster_dim'     => '',
			'link'           => '',
		);
		$vars = '';
		foreach ( array(
			'bg'        => '--cg-bg',
			'fg'        => '--cg-fg',
			'accent'    => '--cg-accent',
			'accent_fg' => '--cg-accent-fg',
		) as $option_key => $property ) {
			if ( '' !== $a[ $option_key ] ) {
				$vars .= $property . ':' . self::color( $a[ $option_key ], $palette ) . ';';
			}
		}

		$css = '';
		if ( '' !== $vars ) {
			$css .= '.cg-embed,.cg-withdraw{' . $vars . '}';
		}
		if ( 'minimal' === $a['preset'] ) {
			// Transparent panel on the page's own background; --cg-fg
			// defaults to the theme's contrast preset, so text keeps its
			// ratio against the page.
			$css .= '.cg-embed:not(.cg-embed--active){background:transparent;border:1px solid var(--cg-fg);}';
		} elseif ( 'card' === $a['preset'] ) {
			$css .= '.cg-embed:not(.cg-embed--active){border:1px solid rgba(0,0,0,0.12);border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,0.18);}';
		}

		// Emitted after the preset rules at equal specificity, so an explicit
		// corner choice beats the card preset's radius. The admin preview
		// (assets/js/admin-appearance.js) mirrors these values inline —
		// change them in both places.
		$radii  = array(
			'square'  => '0',
			'rounded' => '12px',
			'pill'    => '12px',
		);
		$radius = null;
		if ( isset( $radii[ $a['corners'] ] ) ) {
			$radius = $radii[ $a['corners'] ];
		} elseif ( 'custom' === $a['corners'] ) {
			// Sanitised to an int (0–48) in Options; the cast is belt-and-braces.
			$radius = (int) $a['radius'] . 'px';
		}
		if ( null !== $radius ) {
			$css .= '.cg-embed,.cg-withdraw{--cg-radius:' . $radius . ';}.cg-embed:not(.cg-embed--active){border-radius:' . $radius . ';}';
			if ( 'pill' === $a['corners'] ) {
				$css .= '.cg-embed .cg-embed__button{border-radius:999px;}';
			}
		}

		// Border, shadow and spacing follow the same rule as corners: emitted
		// after the preset at equal specificity, so an explicit choice always
		// beats the preset's own border/shadow. An empty value means "let the
		// preset decide" — the pre-0.10 behaviour, byte for byte.
		if ( '' !== (string) $a['border_width'] ) {
			$width = (int) $a['border_width'];
			$color = '' !== $a['border_color'] ? self::color( $a['border_color'], $palette ) : 'var(--cg-fg)';
			$css  .= '.cg-embed:not(.cg-embed--active){border:'
				. ( 0 === $width ? 'none' : $width . 'px solid ' . $color )
				. ';}';
		} elseif ( '' !== $a['border_color'] ) {
			// Colour without a width recolours whatever border the preset
			// draws (minimal, card); with no preset border it does nothing.
			$css .= '.cg-embed:not(.cg-embed--active){border-color:' . self::color( $a['border_color'], $palette ) . ';}';
		}

		$shadows = array(
			'none'   => 'none',
			'soft'   => '0 1px 4px rgba(0,0,0,0.18)',
			'strong' => '0 6px 24px rgba(0,0,0,0.35)',
		);
		if ( isset( $shadows[ $a['shadow'] ] ) ) {
			$css .= '.cg-embed:not(.cg-embed--active){box-shadow:' . $shadows[ $a['shadow'] ] . ';}';
		}

		$gaps = array(
			'compact'  => '0.5rem',
			'spacious' => '1.25rem',
		);
		if ( isset( $gaps[ $a['density'] ] ) ) {
			$css .= '.cg-embed{--cg-gap:' . $gaps[ $a['density'] ] . ';}';
		}

		$sizes = array(
			'small' => 'font-size:0.875em;padding:0.375em 0.75em;',
			'large' => 'font-size:1.125em;padding:0.625em 1.25em;',
		);
		if ( isset( $sizes[ $a['button_size'] ] ) ) {
			$css .= '.cg-embed .cg-embed__button,.cg-withdraw{' . $sizes[ $a['button_size'] ] . '}';
		}

		if ( 'outline' === $a['button_style'] ) {
			// Ghost button: the panel text colour on the panel background,
			// the accent kept as the outline — the contrast report measures
			// exactly this pair.
			$css .= '.cg-embed .cg-embed__button{background:transparent;color:var(--cg-fg);border-color:var(--cg-accent);}';
		}
		if ( 'full' === $a['button_width'] ) {
			$css .= '.cg-embed .cg-embed__button{width:100%;}';
		}
		$hovers = array(
			'none'   => 'none',
			'strong' => 'brightness(1.25)',
		);
		if ( isset( $hovers[ $a['hover'] ] ) ) {
			// Beats the stylesheet's reduced-motion-gated subtle hover at
			// higher specificity; a static filter needs no transition.
			$css .= '.cg-embed .cg-embed__button:hover{filter:' . $hovers[ $a['hover'] ] . ';}';
		}

		if ( $a['play_icon'] ) {
			// A decorative glyph, drawn in the button's own text colour via a
			// CSS mask over an inline data: SVG — bundled bytes, no request
			// (invariant 9), and invisible to accessibility APIs (the button
			// keeps its text name). The generic glyph is the base rule; known
			// kinds override it by provider id, so a map never gets a play
			// triangle and unknown embeds never pretend to be videos.
			$css    .= '.cg-embed .cg-embed__button::before{content:\'\';display:inline-block;width:0.75em;height:0.75em;margin-inline-end:0.5em;background:currentColor;'
				. self::mask( 'generic' ) . '}';
			$by_kind = array();
			foreach ( $kinds as $provider_id => $kind ) {
				if ( is_string( $kind ) && isset( self::GLYPHS[ $kind ] ) && 'generic' !== $kind && preg_match( '/^[a-z0-9_-]+$/', (string) $provider_id ) ) {
					$by_kind[ $kind ][] = '.cg-embed[data-cg-provider="' . $provider_id . '"] .cg-embed__button::before';
				}
			}
			foreach ( self::KINDS as $kind ) {
				if ( ! empty( $by_kind[ $kind ] ) ) {
					$css .= implode( ',', $by_kind[ $kind ] ) . '{' . self::mask( $kind ) . '}';
				}
			}
		}

		if ( 'small' === $a['note_size'] ) {
			$css .= '.cg-embed .cg-embed__note{font-size:0.875em;}';
		}

		if ( 'center' === $a['align'] ) {
			$css .= '.cg-embed .cg-embed__panel{align-items:center;text-align:center;}';
		}

		$placements = array(
			'center' => 'align-self:center;justify-self:center;',
			'bar'    => 'align-self:end;justify-self:stretch;margin:0;max-width:none;border-radius:0 0 var(--cg-radius) var(--cg-radius);',
		);
		if ( isset( $placements[ $a['poster_panel'] ] ) ) {
			$css .= '.cg-embed--poster:not(.cg-embed--active) .cg-embed__panel{' . $placements[ $a['poster_panel'] ] . '}';
		}
		$dims = array(
			'light'  => 'brightness(0.75)',
			'strong' => 'brightness(0.5) blur(2px)',
		);
		if ( isset( $dims[ $a['poster_dim'] ] ) ) {
			$css .= '.cg-embed--poster:not(.cg-embed--active) .cg-embed__poster{filter:' . $dims[ $a['poster_dim'] ] . ';}';
		}
		if ( '' !== $a['link'] ) {
			$css .= '.cg-embed .cg-embed__fallback a,.cg-embed .cg-embed__privacy a{color:' . self::color( $a['link'], $palette ) . ';}';
		}

		if ( $a['dark'] ) {
			$dark_vars = '';
			foreach ( array(
				'dark_bg'        => '--cg-bg',
				'dark_fg'        => '--cg-fg',
				'dark_accent'    => '--cg-accent',
				'dark_accent_fg' => '--cg-accent-fg',
			) as $option_key => $property ) {
				if ( '' !== $a[ $option_key ] ) {
					$dark_vars .= $property . ':' . self::color( $a[ $option_key ], $palette ) . ';';
				}
			}
			if ( '' !== $dark_vars ) {
				$css .= '@media (prefers-color-scheme:dark){.cg-embed,.cg-withdraw{' . $dark_vars . '}}';
			}
		}

		return $css;
	}

	/**
	 * A stored colour as CSS: a hex verbatim, or a theme reference
	 * "preset:<slug>" as var(--wp--preset--color--<slug>[, #fallback]) so the
	 * panel follows the theme's palette, with the current hex as the safety
	 * net should the slug disappear. Both grammars were enforced by
	 * Options::sanitize(); anything else is defensively dropped to inherit.
	 *
	 * @param string $value   Stored colour value.
	 * @param array  $palette slug => hex.
	 * @return string
	 */
	public static function color( string $value, array $palette = array() ): string {
		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^preset:([a-z0-9-]{1,64})$/', $value, $m ) ) {
			$slug     = $m[1];
			$fallback = isset( $palette[ $slug ] ) && preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $palette[ $slug ] )
				? ',' . $palette[ $slug ]
				: '';
			return 'var(--wp--preset--color--' . $slug . $fallback . ')';
		}
		return 'inherit';
	}

	/**
	 * The mask declarations for one glyph.
	 *
	 * @param string $kind GLYPHS key.
	 * @return string
	 */
	private static function mask( string $kind ): string {
		$mask = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='" . self::GLYPHS[ $kind ] . "'/%3E%3C/svg%3E\") center/contain no-repeat";
		return '-webkit-mask:' . $mask . ';mask:' . $mask . ';';
	}

	/**
	 * Does any colour in this appearance subtree follow the theme palette
	 * (a `preset:<slug>` value)? Lets the caller skip resolving the palette
	 * when no emitted rule would reference it.
	 *
	 * @param array $appearance Sanitised appearance option subtree.
	 * @return bool
	 */
	public static function uses_theme_palette( array $appearance ): bool {
		foreach ( $appearance as $value ) {
			if ( is_string( $value ) && 0 === strpos( $value, 'preset:' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mask rules for every glyph, keyed by a data attribute — the settings
	 * screen uses them to show each kind's icon next to its name.
	 *
	 * @param string $selector Element selector, e.g. '.cg-kind-glyph'.
	 * @return string CSS: {selector}[data-cg-kind="video"]{mask…} per kind ('' = generic).
	 */
	public static function kind_icon_rules( string $selector ): string {
		$css = '';
		foreach ( array_keys( self::GLYPHS ) as $kind ) {
			$value = 'generic' === $kind ? '' : $kind;
			$css  .= $selector . '[data-cg-kind="' . $value . '"]{' . self::mask( $kind ) . '}';
		}
		return $css;
	}
}
