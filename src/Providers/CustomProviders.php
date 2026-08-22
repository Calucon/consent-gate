<?php
/**
 * Owner-defined providers (settings screen), as descriptors.
 *
 * WordPress-free by design (PLAN.md §2.2): rows from the sanitised option
 * tree in, descriptor arrays out. The rows are deliberately small — a
 * label, the hosts, a kind. Everything else an owner may want per
 * provider (gate on/off, note, button text, privacy-policy link) is the
 * same per-provider override row the built-ins use, so the Providers
 * table treats both alike.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns `custom_providers` option rows into provider descriptors.
 */
final class CustomProviders {

	/** Id prefix that marks a descriptor as owner-defined. */
	public const ID_PREFIX = 'custom-';

	/**
	 * @param array[]       $rows      Sanitised `custom_providers` rows
	 *                                 (id, label, hosts, script_hosts, kind).
	 * @param callable|null $translate Maps an English string to the site
	 *                                 language; identity when null.
	 * @return array[] Descriptors, in row order. Never rewrites the load
	 *                 target: a custom provider loads exactly the URL the
	 *                 embed carries, after the click.
	 */
	public static function descriptors( array $rows, ?callable $translate = null, array $reserved_hosts = array() ): array {
		$t = $translate ?? static function ( string $text ): string {
			return $text;
		};

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['label'] ) || ! is_string( $row['id'] ) || ! is_string( $row['label'] )
				|| '' === $row['label'] || ! preg_match( '/^custom-[a-z0-9-]{1,48}$/', $row['id'] ) ) {
				continue;
			}
			// Belt and braces for rows saved before a built-in claimed one of
			// their hosts: the built-in wins at runtime too (Options refuses
			// such hosts at save time and the settings screen says so).
			$hosts   = self::clean_hosts( $row['hosts'] ?? array(), $reserved_hosts );
			$scripts = self::clean_hosts( $row['script_hosts'] ?? array(), $reserved_hosts );
			if ( array() === $hosts && array() === $scripts ) {
				continue;
			}
			$label  = $row['label'];
			$script = array() === $hosts;
			$match  = array();
			if ( array() !== $hosts ) {
				$match['iframe_host'] = $hosts;
			}
			if ( array() !== $scripts ) {
				$match['script_host'] = $scripts;
			}

			$out[] = Provider::normalize(
				array(
					'id'       => (string) $row['id'],
					'label'    => $label,
					'match'    => $match,
					'kind'     => isset( $row['kind'] ) && is_string( $row['kind'] ) ? $row['kind'] : '',
					// Same wording the generic fallbacks use for an unknown
					// host, with the owner's label in place of the host — the
					// script wording for script-only rows, as in Registry.
					'note'     => $script
						/* translators: %s: host name of the third-party script. */
						? sprintf( $t( 'Loading this content runs a script from %s, which receives your IP address and which page you are on, and may set cookies.' ), $label )
						/* translators: %s: host name of the third-party embed. */
						: sprintf( $t( 'Loading this content connects your browser to %s, which receives your IP address and which page you are on, and may set cookies.' ), $label ),
					/* translators: %s: host name of the third-party embed. */
					'action'   => sprintf( $t( 'Load content from %s' ), $label ),
					'strategy' => $script ? 'script' : 'iframe',
					'custom'   => true,
				)
			);
		}

		return $out;
	}

	/**
	 * Hosts of a stored row, minus anything that is not a plain host string
	 * and minus the reserved set.
	 *
	 * @param mixed    $hosts          Stored host list.
	 * @param string[] $reserved_hosts Hosts built-in providers handle.
	 * @return string[]
	 */
	private static function clean_hosts( $hosts, array $reserved_hosts ): array {
		$out = array();
		foreach ( (array) $hosts as $host ) {
			if ( is_string( $host ) && '' !== $host && ! in_array( $host, $reserved_hosts, true ) ) {
				$out[] = $host;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Every host the given descriptors handle (iframe and script) — the
	 * reserved set custom providers may not claim.
	 *
	 * @param array[] $providers Descriptors (custom ones are skipped).
	 * @return string[]
	 */
	public static function reserved_hosts( array $providers ): array {
		$hosts = array();
		foreach ( $providers as $descriptor ) {
			if ( ! is_array( $descriptor ) || ! empty( $descriptor['custom'] ) ) {
				continue;
			}
			$match = isset( $descriptor['match'] ) && is_array( $descriptor['match'] ) ? $descriptor['match'] : array();
			foreach ( array( 'iframe_host', 'script_host' ) as $key ) {
				if ( isset( $match[ $key ] ) ) {
					$hosts = array_merge( $hosts, (array) $match[ $key ] );
				}
			}
		}
		return array_values( array_unique( array_filter( $hosts, 'is_string' ) ) );
	}

	/**
	 * Stable id for a new row: the label slugified under the custom prefix,
	 * unique against the ids already taken.
	 *
	 * @param string   $label Owner-typed label.
	 * @param string[] $taken Ids already in use.
	 * @return string
	 */
	public static function id_for( string $label, array $taken ): string {
		$slug = strtolower( $label );
		if ( function_exists( 'iconv' ) ) {
			$ascii = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv notices on glibc for untransliterable input; the fallback below covers it.
			if ( is_string( $ascii ) && '' !== $ascii ) {
				$slug = strtolower( $ascii );
			}
		}
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $slug ), '-' );
		$slug = substr( $slug, 0, 40 );
		$slug = trim( $slug, '-' );
		if ( '' === $slug ) {
			$slug = 'provider';
		}
		$base = self::ID_PREFIX . $slug;
		$id   = $base;
		$n    = 2;
		while ( in_array( $id, $taken, true ) ) {
			$id = $base . '-' . $n;
			++$n;
		}
		return $id;
	}
}
