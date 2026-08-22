<?php
/**
 * CSP snippet generator (PLAN.md §9.13). WordPress-free pure logic.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sites with a Content-Security-Policy need frame-src / script-src entries
 * for each enabled provider's load host. The whole point of the plugin is
 * that those hosts are NOT contacted until consent — the CSP entry is
 * permission, not traffic.
 */
final class Csp {

	/**
	 * Build directive host lists from the provider set.
	 *
	 * @param array[] $providers Descriptors (disabled ones are skipped).
	 * @return array{frame-src:string[],script-src:string[]}
	 */
	public static function directives( array $providers ): array {
		$frame  = array();
		$script = array();

		foreach ( $providers as $descriptor ) {
			if ( isset( $descriptor['enabled'] ) && false === $descriptor['enabled'] ) {
				continue;
			}
			$match = isset( $descriptor['match'] ) && is_array( $descriptor['match'] ) ? $descriptor['match'] : array();

			// Post-consent frames load from load_host when the descriptor
			// rewrites, otherwise from the matched iframe hosts themselves.
			if ( isset( $descriptor['load_host'] ) && is_string( $descriptor['load_host'] ) && '' !== $descriptor['load_host'] ) {
				$frame[] = $descriptor['load_host'];
			} elseif ( isset( $match['iframe_host'] ) ) {
				$frame = array_merge( $frame, (array) $match['iframe_host'] );
			}

			if ( isset( $match['script_host'] ) ) {
				$script = array_merge( $script, (array) $match['script_host'] );
			}
		}

		return array(
			'frame-src'  => array_values( array_unique( $frame ) ),
			'script-src' => array_values( array_unique( $script ) ),
		);
	}

	/**
	 * The same hosts, one row each, with the providers that need them —
	 * so the settings screen can explain WHY a host is in the snippet.
	 *
	 * @param array[] $providers Descriptors (disabled ones are skipped).
	 * @return array<int,array{directive:string,host:string,providers:string[]}>
	 */
	public static function hosts( array $providers ): array {
		$rows = array();

		foreach ( $providers as $descriptor ) {
			if ( isset( $descriptor['enabled'] ) && false === $descriptor['enabled'] ) {
				continue;
			}
			$match = isset( $descriptor['match'] ) && is_array( $descriptor['match'] ) ? $descriptor['match'] : array();
			$label = isset( $descriptor['label'] ) && '' !== $descriptor['label'] ? (string) $descriptor['label'] : (string) ( $descriptor['id'] ?? '' );

			$frame = array();
			if ( isset( $descriptor['load_host'] ) && is_string( $descriptor['load_host'] ) && '' !== $descriptor['load_host'] ) {
				$frame[] = $descriptor['load_host'];
			} elseif ( isset( $match['iframe_host'] ) ) {
				$frame = (array) $match['iframe_host'];
			}
			$script = isset( $match['script_host'] ) ? (array) $match['script_host'] : array();

			foreach ( array(
				'frame-src'  => $frame,
				'script-src' => $script,
			) as $directive => $hosts ) {
				foreach ( $hosts as $host ) {
					$key = $directive . ' ' . $host;
					if ( ! isset( $rows[ $key ] ) ) {
						$rows[ $key ] = array(
							'directive' => $directive,
							'host'      => (string) $host,
							'providers' => array(),
						);
					}
					if ( ! in_array( $label, $rows[ $key ]['providers'], true ) ) {
						$rows[ $key ]['providers'][] = $label;
					}
				}
			}
		}

		return array_values( $rows );
	}

	/**
	 * hosts() regrouped one row per provider — the readable form for a
	 * table: "YouTube loads frames from …, scripts from …".
	 *
	 * @param array[] $providers Descriptors (disabled ones are skipped).
	 * @return array<string,array{frame-src:string[],script-src:string[]}> label => hosts per directive, in provider order.
	 */
	public static function by_provider( array $providers ): array {
		$out = array();
		foreach ( self::hosts( $providers ) as $row ) {
			foreach ( $row['providers'] as $label ) {
				if ( ! isset( $out[ $label ] ) ) {
					$out[ $label ] = array(
						'frame-src'  => array(),
						'script-src' => array(),
					);
				}
				$out[ $label ][ $row['directive'] ][] = $row['host'];
			}
		}
		return $out;
	}

	/**
	 * Render the snippet a site owner appends to their existing policy.
	 *
	 * @param array[] $providers Descriptors.
	 * @return string e.g. "frame-src https://a https://b;\nscript-src https://c;"
	 */
	public static function snippet( array $providers ): string {
		$lines = array();
		foreach ( self::directives( $providers ) as $directive => $hosts ) {
			if ( array() === $hosts ) {
				continue;
			}
			$lines[] = $directive . ' ' . implode(
				' ',
				array_map(
					static function ( $host ): string {
						return 'https://' . (string) $host;
					},
					$hosts
				)
			) . ';';
		}
		return implode( "\n", $lines );
	}
}
