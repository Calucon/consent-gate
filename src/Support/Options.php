<?php
/**
 * Options: typed, defaulted, sanitised — schema and defaults in one place
 * (PLAN.md §7.1). Stored as a single option array.
 *
 * The schema and every transform here are WordPress-free pure functions;
 * only the option NAME constant is WordPress-facing. Reading and writing
 * the option happens in Plugin.php and Admin/.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Support;

use CaluconEmbedGate\Providers\CustomProviders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the option shape.
 */
final class Options {

	public const OPTION = 'calucon_embed_gate_options';

	/** Upper bound on owner-defined provider rows (registry matching is linear). */
	public const MAX_CUSTOM_PROVIDERS = 100;

	/** Upper bound on hosts per list in one owner-defined provider row. */
	public const MAX_CUSTOM_HOSTS = 50;

	/**
	 * @return array Complete default option tree.
	 */
	public static function defaults(): array {
		return array(
			'providers'        => array(
				// '<provider id>' => array(
				//     'enabled'         => true,   gate embeds of this provider
				//     'privacy_variant' => true,   load via nocookie/dnt target
				//     'note'            => '',     override panel note text
				//     'action'          => '',     override button text
				//     'privacy_url'     => '',     override the linked privacy policy (https only)
				// ),
			),
			// Owner-defined providers (Providers tab). Each row: a stable id
			// (custom-<slug>, generated once from the label and kept so the
			// per-provider override row above stays attached), the label,
			// iframe hosts, script hosts, and a kind for the button glyph.
			// Never a load-target rewrite: a custom provider loads the URL
			// the embed carries. See Providers\CustomProviders.
			'custom_providers' => array(),
			'detection'        => array(
				'iframes'         => true,
				'scripts'         => true,
				// Third-party <img> gating (§3.5): opt-in — replacing every
				// remote image with a panel can break layouts, and images
				// are content more often than embeds are.
				'images'          => false,
				'own_hosts'       => array(), // Site's own CDN / media hosts.
				'never_gate'      => array(), // Hosts the owner exempts (their responsibility).
				'always_gate'     => array(), // Hosts gated even when own-host logic would pass them.
				'www_equivalence' => true,
				// Whole-document buffer for page builders (§3.3). Invasive;
				// off by default, behind a warning in the UI.
				'output_buffer'   => false,
			),
			'display'          => array(
				// The provider's privacy-policy link inside the panel, shown
				// before any click for providers that declare a privacy_url
				// (generic/unknown embeds have none). Off by default (Simon's
				// call for 0.10.0): the panel keeps its pre-0.10 shape unless
				// the owner opts in on the Providers tab.
				'privacy_link' => false,
			),
			'appearance'       => array(
				// Preset styles (§7.1). 'default' is the shipped look;
				// 'minimal' drops the panel background; 'card' adds border
				// and shadow. Colours override the CSS custom properties;
				// '' means "inherit the theme's presets".
				'preset'         => 'default', // default | minimal | card.
				'bg'             => '',
				'fg'             => '',
				'accent'         => '',
				'accent_fg'      => '',
				// Corner style: '' inherits the stylesheet default (slightly
				// rounded); the named values override panel and button;
				// 'custom' uses the radius value below.
				'corners'        => '', // '' | square | rounded | pill | custom.
				'radius'         => 12, // px, used when corners = custom.
				// Border, shadow and spacing: empty means the chosen preset
				// decides (the pre-0.10 behaviour). A zero border width
				// removes the border outright; a border colour alone
				// recolours the preset's own border.
				'border_width'   => '', // Empty, or 0-10 (px, stored as a string).
				'border_color'   => '',
				'shadow'         => '', // '' | none | soft | strong.
				'density'        => '', // '' | compact | spacious.
				// The withdrawal control's look: filled follows the load
				// button; outline and link are quieter fits for a privacy-
				// policy page.
				'withdraw_style' => '', // '' (filled) | outline | link.
				'button_size'    => '', // '' | small | large.
				// A decorative play glyph on the load button — bundled
				// inline SVG via CSS mask, never a fetched asset.
				'play_icon'      => false,
				'note_size'      => '', // '' | small.
				'align'          => '', // '' | center.
				// Optional dark-scheme palette: applied only under
				// prefers-color-scheme: dark, and only for the colours set.
				'dark'           => false,
				'dark_bg'        => '',
				'dark_fg'        => '',
				'dark_accent'    => '',
				'dark_accent_fg' => '',
				// Load-button polish and poster layout (0.10 round 3).
				'button_style'   => '', // '' (filled) | outline.
				'button_width'   => '', // '' | full.
				'hover'          => '', // '' (subtle) | none | strong.
				'poster_panel'   => '', // '' (bottom-left card) | center | bar.
				'poster_dim'     => '', // '' | light | strong — darkens the poster behind the panel.
				'link'           => '', // Link colour (hex); '' inherits the panel text colour.
			),
			'consent'          => array(
				// Consent memory (§6.2). Off by default: out of the box,
				// consent applies to the one embed clicked and is stored
				// nowhere. Client-side only (§6.3) — a server-side state
				// would make every page uncacheable.
				'memory'        => 'off',      // off | session | persistent.
				'scope'         => 'provider', // embed | provider | all.
				'duration_days' => 180,        // Persistent lifetime.
			),
			'cmp'              => array(
				// Consent platform bridge (§6.4). Off by default: without it,
				// an installed CMP is detected but ignored — gating stands
				// regardless of its choices (fail closed). Enabled, a grant
				// for the embeds' category in a TESTED platform auto-loads
				// gated embeds client-side; withdrawal re-gates them.
				'bridge'        => false,
				// Borlabs Cookie service groups are site-defined; this names
				// the group whose consent covers embedded content.
				'borlabs_group' => 'external-media',
				// IAB TCF v2.2 generic bridge, experimental — only providers
				// with a Global Vendor List entry can ever be granted.
				'tcf'           => false,
			),
		);
	}

	/**
	 * Sanitise a raw (user-submitted or stored) option tree against the schema.
	 *
	 * @param mixed $raw Anything.
	 * @return array Safe, complete option tree.
	 */
	public static function sanitize( $raw ): array {
		return self::sanitize_report( $raw )['options'];
	}

	/**
	 * sanitize() plus what it refused, for the settings screen's notices.
	 *
	 * @param mixed    $raw            Submitted option tree.
	 * @param string[] $reserved_hosts Hosts the built-in (and code-registered)
	 *                                 providers handle: a custom provider may
	 *                                 not claim them — the built-in keeps its
	 *                                 privacy-preserving load and its texts.
	 * @return array{options:array,rejected_hosts:array<string,string[]>} rejected_hosts: label => hosts dropped.
	 */
	public static function sanitize_report( $raw, array $reserved_hosts = array() ): array {
		$rejected = array();
		$options  = self::sanitize_tree( $raw, $reserved_hosts, $rejected );
		return array(
			'options'        => $options,
			'rejected_hosts' => $rejected,
		);
	}

	/**
	 * @param mixed    $raw            Submitted option tree.
	 * @param string[] $reserved_hosts See sanitize_report().
	 * @param array    $rejected       Out: label => reserved hosts dropped from custom rows.
	 * @return array
	 */
	private static function sanitize_tree( $raw, array $reserved_hosts, array &$rejected ): array {
		$defaults = self::defaults();
		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		$clean = $defaults;

		if ( isset( $raw['providers'] ) && is_array( $raw['providers'] ) ) {
			foreach ( $raw['providers'] as $id => $row ) {
				if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $id ) || ! is_array( $row ) ) {
					continue;
				}
				$entry = array();
				if ( array_key_exists( 'enabled', $row ) ) {
					$entry['enabled'] = self::truthy( $row['enabled'] );
				}
				if ( array_key_exists( 'privacy_variant', $row ) ) {
					$entry['privacy_variant'] = self::truthy( $row['privacy_variant'] );
				}
				foreach ( array( 'note', 'action' ) as $text_key ) {
					if ( isset( $row[ $text_key ] ) && is_string( $row[ $text_key ] ) ) {
						$text = trim( strip_tags( $row[ $text_key ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- sanitize() must run WordPress-free for the unit suite; output is escaped at render time regardless.
						// A panel note is a sentence; cap it so a pathological
						// value can't be stored. Output is escaped regardless.
						if ( function_exists( 'mb_substr' ) ) {
							$text = mb_substr( $text, 0, 500 );
						} else {
							$text = substr( $text, 0, 500 );
						}
						if ( '' !== $text ) {
							$entry[ $text_key ] = $text;
						}
					}
				}
				// Privacy-policy URL override: a localised or moved policy
				// page. https only — the renderer's scheme guard is the second
				// layer, this is the first — and bounded in length.
				if ( isset( $row['privacy_url'] ) && is_string( $row['privacy_url'] ) ) {
					$url = trim( $row['privacy_url'] );
					if ( strlen( $url ) <= 500
						&& preg_match( '~^https://[^\s"\'<>]+$~i', $url )
						&& false !== filter_var( $url, FILTER_VALIDATE_URL ) ) {
						$entry['privacy_url'] = $url;
					}
				}
				if ( array() !== $entry ) {
					$clean['providers'][ $id ] = $entry;
				}
			}
		}

		if ( isset( $raw['custom_providers'] ) && is_array( $raw['custom_providers'] ) ) {
			$clean['custom_providers'] = self::sanitize_custom_providers( $raw['custom_providers'], $reserved_hosts, $rejected );
		}
		// Override rows for custom providers that no longer exist are
		// dropped — otherwise a removed provider's note would resurface if
		// a new one ever got the same id.
		$custom_ids = array_column( $clean['custom_providers'], 'id' );
		foreach ( array_keys( $clean['providers'] ) as $id ) {
			if ( 0 === strpos( (string) $id, CustomProviders::ID_PREFIX ) && ! in_array( $id, $custom_ids, true ) ) {
				unset( $clean['providers'][ $id ] );
			}
		}

		if ( isset( $raw['detection'] ) && is_array( $raw['detection'] ) ) {
			$d = $raw['detection'];
			foreach ( array( 'iframes', 'scripts', 'images', 'www_equivalence', 'output_buffer' ) as $flag ) {
				if ( array_key_exists( $flag, $d ) ) {
					$clean['detection'][ $flag ] = self::truthy( $d[ $flag ] );
				}
			}
			foreach ( array( 'own_hosts', 'never_gate', 'always_gate' ) as $list ) {
				if ( isset( $d[ $list ] ) ) {
					$clean['detection'][ $list ] = self::sanitize_host_list( $d[ $list ] );
				}
			}
		}

		if ( isset( $raw['display'] ) && is_array( $raw['display'] ) ) {
			if ( array_key_exists( 'privacy_link', $raw['display'] ) ) {
				$clean['display']['privacy_link'] = self::truthy( $raw['display']['privacy_link'] );
			}
		}

		if ( isset( $raw['appearance'] ) && is_array( $raw['appearance'] ) ) {
			$a = $raw['appearance'];
			if ( isset( $a['preset'] ) && in_array( $a['preset'], array( 'default', 'minimal', 'card' ), true ) ) {
				$clean['appearance']['preset'] = $a['preset'];
			}
			if ( isset( $a['corners'] ) && in_array( $a['corners'], array( '', 'square', 'rounded', 'pill', 'custom' ), true ) ) {
				$clean['appearance']['corners'] = $a['corners'];
			}
			if ( isset( $a['radius'] ) && is_numeric( $a['radius'] ) ) {
				$clean['appearance']['radius'] = max( 0, min( 48, (int) $a['radius'] ) );
			}
			if ( isset( $a['border_width'] ) && '' !== $a['border_width'] && is_numeric( $a['border_width'] ) ) {
				$clean['appearance']['border_width'] = (string) max( 0, min( 10, (int) $a['border_width'] ) );
			}
			if ( isset( $a['shadow'] ) && in_array( $a['shadow'], array( '', 'none', 'soft', 'strong' ), true ) ) {
				$clean['appearance']['shadow'] = $a['shadow'];
			}
			if ( isset( $a['density'] ) && in_array( $a['density'], array( '', 'compact', 'spacious' ), true ) ) {
				$clean['appearance']['density'] = $a['density'];
			}
			if ( isset( $a['withdraw_style'] ) && in_array( $a['withdraw_style'], array( '', 'outline', 'link' ), true ) ) {
				$clean['appearance']['withdraw_style'] = $a['withdraw_style'];
			}
			if ( isset( $a['button_size'] ) && in_array( $a['button_size'], array( '', 'small', 'large' ), true ) ) {
				$clean['appearance']['button_size'] = $a['button_size'];
			}
			if ( isset( $a['note_size'] ) && in_array( $a['note_size'], array( '', 'small' ), true ) ) {
				$clean['appearance']['note_size'] = $a['note_size'];
			}
			if ( isset( $a['align'] ) && in_array( $a['align'], array( '', 'center' ), true ) ) {
				$clean['appearance']['align'] = $a['align'];
			}
			foreach ( array(
				'button_style' => array( '', 'outline' ),
				'button_width' => array( '', 'full' ),
				'hover'        => array( '', 'none', 'strong' ),
				'poster_panel' => array( '', 'center', 'bar' ),
				'poster_dim'   => array( '', 'light', 'strong' ),
			) as $enum_key => $allowed ) {
				if ( isset( $a[ $enum_key ] ) && in_array( $a[ $enum_key ], $allowed, true ) ) {
					$clean['appearance'][ $enum_key ] = $a[ $enum_key ];
				}
			}
			foreach ( array( 'play_icon', 'dark' ) as $appearance_flag ) {
				if ( array_key_exists( $appearance_flag, $a ) ) {
					$clean['appearance'][ $appearance_flag ] = self::truthy( $a[ $appearance_flag ] );
				}
			}
			foreach ( array( 'bg', 'fg', 'accent', 'accent_fg', 'border_color', 'link', 'dark_bg', 'dark_fg', 'dark_accent', 'dark_accent_fg' ) as $color_key ) {
				// One swatch row per colour (the settings UI): '' = inherit,
				// 'preset:<slug>' = follow a theme colour, 'custom' = the hex
				// in "<key>_custom". A raw hex in the main key is accepted too
				// (re-sanitising a stored tree, or programmatic updates). The
				// slug grammar is the only thing ever interpolated into a
				// custom-property name; the hex grammar is the only thing ever
				// emitted as a colour.
				$value = isset( $a[ $color_key ] ) && is_string( $a[ $color_key ] ) ? strtolower( trim( $a[ $color_key ] ) ) : '';
				if ( 'custom' === $value ) {
					$value = isset( $a[ $color_key . '_custom' ] ) && is_string( $a[ $color_key . '_custom' ] )
						? strtolower( trim( $a[ $color_key . '_custom' ] ) )
						: '';
				}
				if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value ) || preg_match( '/^preset:[a-z0-9-]{1,64}$/', $value ) ) {
					$clean['appearance'][ $color_key ] = $value;
				}
			}
		}

		if ( isset( $raw['consent'] ) && is_array( $raw['consent'] ) ) {
			$c = $raw['consent'];
			if ( isset( $c['memory'] ) && in_array( $c['memory'], array( 'off', 'session', 'persistent' ), true ) ) {
				$clean['consent']['memory'] = $c['memory'];
			}
			if ( isset( $c['scope'] ) && in_array( $c['scope'], array( 'embed', 'provider', 'all' ), true ) ) {
				$clean['consent']['scope'] = $c['scope'];
			}
			if ( isset( $c['duration_days'] ) && is_numeric( $c['duration_days'] ) ) {
				$clean['consent']['duration_days'] = max( 1, min( 730, (int) $c['duration_days'] ) );
			}
		}

		if ( isset( $raw['cmp'] ) && is_array( $raw['cmp'] ) ) {
			$b = $raw['cmp'];
			foreach ( array( 'bridge', 'tcf' ) as $flag ) {
				if ( array_key_exists( $flag, $b ) ) {
					$clean['cmp'][ $flag ] = self::truthy( $b[ $flag ] );
				}
			}
			if ( isset( $b['borlabs_group'] ) && is_string( $b['borlabs_group'] ) ) {
				$group = strtolower( trim( $b['borlabs_group'] ) );
				// Borlabs group ids are slugs; anything else would end up
				// quoted into the inline config JSON.
				if ( preg_match( '/^[a-z0-9_-]{1,64}$/', $group ) ) {
					$clean['cmp']['borlabs_group'] = $group;
				}
			}
		}

		return $clean;
	}

	/**
	 * Apply per-provider overrides to the descriptor set. Pure.
	 *
	 * Disabling a provider means its embeds pass through (the owner's
	 * explicit decision) — the descriptor still matches, so the generic
	 * fallback cannot re-gate what the owner exempted.
	 *
	 * @param array[] $providers Descriptors.
	 * @param array   $options   Sanitised option tree.
	 * @return array[]
	 */
	public static function apply_provider_overrides( array $providers, array $options ): array {
		$overrides = isset( $options['providers'] ) ? $options['providers'] : array();
		if ( array() === $overrides ) {
			return $providers;
		}

		foreach ( $providers as $i => $descriptor ) {
			$id = isset( $descriptor['id'] ) ? $descriptor['id'] : null;
			if ( null === $id || ! isset( $overrides[ $id ] ) ) {
				continue;
			}
			$row = $overrides[ $id ];

			// Owner-defined providers are always gated: a custom row can
			// name a host, never exempt it (that is the never-gate list's
			// job, explicitly). Built-ins keep the owner's on/off choice.
			if ( array_key_exists( 'enabled', $row ) && empty( $descriptor['custom'] ) ) {
				$descriptor['enabled'] = (bool) $row['enabled'];
			}
			if ( isset( $row['privacy_variant'] ) && false === $row['privacy_variant'] ) {
				// Load from the original host: drop the rewrite, keep the gate.
				$descriptor['load_host']  = null;
				$descriptor['load_path']  = null;
				$descriptor['load_query'] = array();
			}
			foreach ( array( 'note', 'action', 'privacy_url' ) as $text_key ) {
				if ( isset( $row[ $text_key ] ) && '' !== $row[ $text_key ] ) {
					$descriptor[ $text_key ] = $row[ $text_key ];
				}
			}

			$providers[ $i ] = $descriptor;
		}

		return $providers;
	}

	/**
	 * @param mixed $value Checkbox-ish input.
	 * @return bool
	 */
	private static function truthy( $value ): bool {
		return in_array( $value, array( true, 1, '1', 'on', 'yes', 'true' ), true );
	}

	/**
	 * One host per entry; accepts an array or a newline-separated string.
	 * Entries may use a leading '*.' wildcard (PLAN.md §3.4).
	 *
	 * @param mixed $value Raw list.
	 * @return string[]
	 */
	/**
	 * Owner-defined provider rows (Providers tab).
	 *
	 * A row needs a label and at least one host; a blank row (the always-
	 * present empty line in the form) is simply ignored, and a row with
	 * the remove flag is dropped. Ids are generated from the label once
	 * and kept across saves; wildcards are not accepted because the
	 * registry matches hosts exactly.
	 *
	 * Bounded: at most self::MAX_CUSTOM_PROVIDERS rows and
	 * self::MAX_CUSTOM_HOSTS hosts per list — the registry matches linearly
	 * on every embed. Hosts a built-in provider handles are refused (and
	 * reported), so adding a provider can never take a host away from the
	 * built-in that knows its privacy-preserving load target.
	 *
	 * @param array    $raw            Submitted rows.
	 * @param string[] $reserved_hosts Hosts built-in providers handle.
	 * @param array    $rejected       Out: label => reserved hosts dropped.
	 * @return array[]
	 */
	private static function sanitize_custom_providers( array $raw, array $reserved_hosts, array &$rejected ): array {
		$rows  = array();
		$taken = array();
		$raw   = array_slice( array_values( $raw ), 0, self::MAX_CUSTOM_PROVIDERS );
		// Keep ids that already exist before generating new ones, so a new
		// row can never steal an existing row's id.
		foreach ( $raw as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && is_string( $row['id'] ) && preg_match( '/^custom-[a-z0-9-]{1,48}$/', $row['id'] ) ) {
				$taken[] = $row['id'];
			}
		}
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || ( ! empty( $row['remove'] ) && self::truthy( $row['remove'] ) ) ) {
				continue;
			}
			$label = isset( $row['label'] ) && is_string( $row['label'] ) ? trim( strip_tags( $row['label'] ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress-free sanitize; escaped at render time regardless.
			if ( function_exists( 'mb_substr' ) ) {
				$label = mb_substr( $label, 0, 80 );
			} else {
				$label = substr( $label, 0, 80 );
			}
			if ( '' === $label ) {
				continue;
			}
			$no_wildcards     = static function ( string $host ): bool {
				return 0 !== strpos( $host, '*.' );
			};
			$hosts            = array_slice( array_values( array_filter( self::sanitize_host_list( $row['hosts'] ?? '' ), $no_wildcards ) ), 0, self::MAX_CUSTOM_HOSTS );
			$scripts          = array_slice( array_values( array_filter( self::sanitize_host_list( $row['script_hosts'] ?? '' ), $no_wildcards ) ), 0, self::MAX_CUSTOM_HOSTS );
			$taken_by_builtin = array_values( array_intersect( array_merge( $hosts, $scripts ), $reserved_hosts ) );
			if ( array() !== $taken_by_builtin ) {
				$rejected[ $label ] = array_values( array_unique( array_merge( $rejected[ $label ] ?? array(), $taken_by_builtin ) ) );
				$hosts              = array_values( array_diff( $hosts, $reserved_hosts ) );
				$scripts            = array_values( array_diff( $scripts, $reserved_hosts ) );
			}
			if ( array() === $hosts && array() === $scripts ) {
				continue;
			}
			$kind = isset( $row['kind'] ) && in_array( $row['kind'], AppearanceCss::KINDS, true ) ? $row['kind'] : '';

			$id = isset( $row['id'] ) && is_string( $row['id'] ) && preg_match( '/^custom-[a-z0-9-]{1,48}$/', $row['id'] ) ? $row['id'] : '';
			if ( '' === $id || in_array( $id, array_column( $rows, 'id' ), true ) ) {
				$id      = CustomProviders::id_for( $label, $taken );
				$taken[] = $id;
			}

			$rows[] = array(
				'id'           => $id,
				'label'        => $label,
				'hosts'        => $hosts,
				'script_hosts' => $scripts,
				'kind'         => $kind,
			);
		}
		return $rows;
	}

	private static function sanitize_host_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$hosts = array();
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$entry = strtolower( trim( $entry ) );
			// Tolerate pasted URLs: keep only the host part.
			$entry = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $entry );
			$entry = preg_replace( '#[/:].*$#', '', (string) $entry );
			$entry = rtrim( (string) $entry, '.' ); // FQDN trailing dot.
			if ( '' === $entry ) {
				continue;
			}
			if ( preg_match( '/^(\*\.)?[a-z0-9\x80-\xff]([a-z0-9\x80-\xff.-]*[a-z0-9\x80-\xff])?$/', $entry ) ) {
				$hosts[] = $entry;
			}
		}

		return array_values( array_unique( $hosts ) );
	}
}
