<?php
/**
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Tests\Unit;

use CaluconEmbedGate\Detection\HostMatcher;
use PHPUnit\Framework\TestCase;

final class HostMatcherTest extends TestCase {

	public function test_foreign_host_is_foreign(): void {
		$matcher = new HostMatcher( array( 'example.test' ) );

		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( 'https://www.youtube.com/embed/x' ) );
	}

	public function test_own_host_and_www_equivalence(): void {
		$matcher = new HostMatcher( array( 'example.test' ) );

		self::assertSame( HostMatcher::OWN, $matcher->classify( 'https://example.test/player' ) );
		self::assertSame( HostMatcher::OWN, $matcher->classify( 'https://www.example.test/player' ) );
	}

	public function test_www_equivalence_can_be_disabled(): void {
		$matcher = new HostMatcher( array( 'example.test' ), false );

		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( 'https://www.example.test/player' ) );
	}

	public function test_relative_urls_are_own(): void {
		$matcher = new HostMatcher( array( 'example.test' ) );

		self::assertSame( HostMatcher::OWN, $matcher->classify( '/frame.html' ) );
		self::assertSame( HostMatcher::OWN, $matcher->classify( 'frame.html' ) );
	}

	public function test_non_loading_schemes_are_skipped(): void {
		$matcher = new HostMatcher( array( 'example.test' ) );

		self::assertSame( HostMatcher::SKIP, $matcher->classify( '' ) );
		self::assertSame( HostMatcher::SKIP, $matcher->classify( 'about:blank' ) );
		self::assertSame( HostMatcher::SKIP, $matcher->classify( 'data:text/html,hi' ) );
		self::assertSame( HostMatcher::SKIP, $matcher->classify( 'blob:https://a.example/uuid' ) );
		self::assertSame( HostMatcher::SKIP, $matcher->classify( 'javascript:void(0)' ) );
	}

	public function test_protocol_relative_urls_resolve_by_host(): void {
		$matcher = new HostMatcher( array( 'example.test' ) );

		self::assertSame( HostMatcher::OWN, $matcher->classify( '//example.test/frame' ) );
		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( '//player.vimeo.com/video/1' ) );
	}

	public function test_wildcard_own_hosts(): void {
		$matcher = new HostMatcher( array( 'example.test', '*.cdn.example.test' ) );

		self::assertSame( HostMatcher::OWN, $matcher->classify( 'https://eu1.cdn.example.test/frame' ) );
		self::assertSame( HostMatcher::OWN, $matcher->classify( 'https://cdn.example.test/frame' ) );
		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( 'https://cdn.example.evil/frame' ) );
	}

	public function test_idn_and_punycode_compare_equal(): void {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			self::markTestSkipped( 'ext-intl is not available; IDN equivalence needs idn_to_ascii()' );
		}
		$matcher = new HostMatcher( array( 'münchen.example' ) );

		self::assertTrue( $matcher->is_own_host( 'xn--mnchen-3ya.example' ) );
		self::assertTrue( $matcher->is_own_host( 'MÜNCHEN.example.' ) );
	}

	/**
	 * parse_url() and the browser must not disagree on the authority: a URL
	 * whose real (browser) host is a third party must never be classified OWN
	 * (invariant 6). Browsers treat a backslash as a slash for special schemes
	 * and ignore extra/missing authority slashes, so these all connect to
	 * evil.example even though naive parse_url() reads the own host after '@'.
	 */
	public function test_authority_confusion_is_gated_not_own(): void {
		$matcher = new HostMatcher( array( 'example.test' ) );

		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( 'https://evil.example\\@example.test/track' ) );
		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( '//evil.example\\@example.test/track' ) );
		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( 'https:/\\/evil.example/track' ) );
		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( 'https:\\\\evil.example/track' ) );
		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( 'https:evil.example/track' ) );
		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( '/\\evil.example/track' ) );
		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( '///evil.example/track' ) );
	}

	/**
	 * The mirror of the above: a backslash/irregular-slash URL whose real host
	 * IS the own host must stay OWN, and a genuine same-origin absolute path
	 * (single leading slash) must never be mistaken for protocol-relative.
	 */
	public function test_authority_normalisation_keeps_own_and_paths_own(): void {
		$matcher = new HostMatcher( array( 'example.test' ) );

		self::assertSame( HostMatcher::OWN, $matcher->classify( 'https:\\\\example.test/frame' ) );
		self::assertSame( HostMatcher::OWN, $matcher->classify( 'https://evil.example%5C@example.test/frame' ) );
		self::assertSame( HostMatcher::OWN, $matcher->classify( "https://example.test\t/frame" ) );
		self::assertSame( HostMatcher::OWN, $matcher->classify( '/frame.html' ) );
	}

	public function test_host_of_matches_classify_normalisation(): void {
		$matcher = new HostMatcher( array( 'example.test' ) );

		self::assertSame( 'evil.example', $matcher->host_of( 'https://evil.example\\@example.test/track' ) );
		self::assertSame( 'evil.example', $matcher->host_of( 'https:/\\/evil.example/track' ) );
	}

	public function test_is_own_filter_can_veto_and_approve(): void {
		$matcher = new HostMatcher(
			array( 'example.test' ),
			true,
			static function ( bool $own, string $host ): bool {
				return 'trusted.example' === $host ? true : $own;
			}
		);

		self::assertSame( HostMatcher::OWN, $matcher->classify( 'https://trusted.example/frame' ) );
		self::assertSame( HostMatcher::FOREIGN, $matcher->classify( 'https://other.example/frame' ) );
	}
}
