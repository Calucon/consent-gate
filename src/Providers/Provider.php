<?php
/**
 * Provider descriptor helpers.
 *
 * A provider is data, not a class hierarchy (PLAN.md §4.1). This class only
 * normalises descriptor arrays so the rest of the code can rely on the keys
 * existing. WordPress-free by design.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fills a provider descriptor with defaults and interpolates {placeholders}.
 */
final class Provider {

	/**
	 * Descriptor keys every provider carries after normalisation.
	 *
	 * @param array $descriptor Partial descriptor.
	 * @return array Complete descriptor.
	 */
	public static function normalize( array $descriptor ): array {
		return array_merge(
			array(
				'id'                 => 'generic',
				'enabled'            => true,
				'label'              => '',
				'match'              => array(),
				'load_host'          => null,
				'load_path'          => null,
				// Query parameters merged into the original src at load time
				// (e.g. Vimeo dnt=1). Used when rebuilding from load_path
				// would lose parameters the embed needs (unlisted hashes).
				'load_query'         => array(),
				'fallback'           => '',
				'privacy_url'        => null,
				// What the embed is, for the optional button glyph: one of
				// Support\AppearanceCss::KINDS (video, map, audio, social,
				// form, calendar, document, image, 3d) or '' (generic).
				// Purely presentational.
				'kind'               => '',
				'controller'         => null,
				'note'               => '',
				'action'             => '',
				'aspect'             => null,
				'iframe_allow'       => null,
				'strategy'           => 'iframe',
				// Script strategy only: derives a human fallback URL from the
				// attributes of the companion element preceding the script
				// tag (e.g. Strava's data-embed-type/data-embed-id).
				// fn( array $companion_attributes ): ?string
				'companion_fallback' => null,
				// Script strategy only: class names identifying the provider's
				// companion element (blockquote.twitter-tweet, div.fb-post).
				// Fallback links are harvested ONLY from a matching companion —
				// never from whatever element happens to precede the script.
				'companion_class'    => array(),
				// Sibling CDN hosts performance plugins preconnect/preload for
				// this provider (i.ytimg.com, pbs.twimg.com). Not gated
				// themselves — used to scrub resource hints (§9.14).
				'scrub_hint_hosts'   => array(),
			),
			$descriptor
		);
	}

	/**
	 * Interpolate named captures into a URL template. Every value is
	 * URL-encoded at substitution time, never at template-authoring time
	 * (PLAN.md §4.1).
	 *
	 * @param string $template Template containing {name} placeholders.
	 * @param array  $values   name => raw value.
	 * @return string
	 */
	public static function interpolate( string $template, array $values ): string {
		return preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static function ( array $m ) use ( $values ) {
				return isset( $values[ $m[1] ] ) ? rawurlencode( (string) $values[ $m[1] ] ) : $m[0];
			},
			$template
		);
	}
}
