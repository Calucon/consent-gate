<?php
/**
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Tests\Unit;

use CaluconEmbedGate\Providers\Builtin\Descriptors;
use CaluconEmbedGate\Support\Csp;
use PHPUnit\Framework\TestCase;

final class CspTest extends TestCase {

	public function test_load_host_replaces_match_hosts_in_frame_src(): void {
		$directives = Csp::directives( Descriptors::all() );

		// YouTube rewrites to nocookie: only the load host is needed.
		self::assertContains( 'www.youtube-nocookie.com', $directives['frame-src'] );
		self::assertNotContains( 'www.youtube.com', $directives['frame-src'] );
		// Vimeo keeps its original host (dnt merge, no host rewrite).
		self::assertContains( 'player.vimeo.com', $directives['frame-src'] );
	}

	public function test_script_hosts_land_in_script_src(): void {
		$directives = Csp::directives( Descriptors::all() );

		self::assertContains( 'strava-embeds.com', $directives['script-src'] );
		self::assertContains( 'platform.twitter.com', $directives['script-src'] );
	}

	public function test_disabled_providers_are_excluded(): void {
		$providers = array(
			array(
				'id'      => 'x',
				'enabled' => false,
				'match'   => array( 'iframe_host' => array( 'x.example' ) ),
			),
			array(
				'id'    => 'y',
				'match' => array( 'iframe_host' => array( 'y.example' ) ),
			),
		);

		self::assertSame(
			array( 'frame-src' => array( 'y.example' ), 'script-src' => array() ),
			Csp::directives( $providers )
		);
	}

	public function test_snippet_renders_https_hosts_per_directive(): void {
		$snippet = Csp::snippet(
			array(
				array( 'id' => 'a', 'match' => array( 'iframe_host' => array( 'a.example' ) ) ),
				array( 'id' => 'b', 'match' => array( 'script_host' => array( 'b.example' ) ), 'strategy' => 'script' ),
			)
		);

		self::assertSame(
			"frame-src https://a.example;\nscript-src https://b.example;",
			$snippet
		);
	}

	public function test_hosts_attribute_each_host_to_the_providers_that_need_it(): void {
		$rows = Csp::hosts(
			array(
				array( 'id' => 'a', 'label' => 'Alpha', 'match' => array( 'iframe_host' => array( 'shared.example', 'a.example' ) ) ),
				array( 'id' => 'b', 'label' => 'Beta', 'match' => array( 'iframe_host' => array( 'shared.example' ) ) ),
				array( 'id' => 'c', 'label' => 'Gamma', 'match' => array( 'iframe_host' => array( 'c.example' ) ), 'load_host' => 'c-nocookie.example' ),
				array( 'id' => 'd', 'match' => array( 'script_host' => array( 'sdk.example' ) ), 'strategy' => 'script' ),
				array( 'id' => 'off', 'label' => 'Off', 'enabled' => false, 'match' => array( 'iframe_host' => array( 'off.example' ) ) ),
			)
		);

		self::assertSame(
			array(
				array( 'directive' => 'frame-src', 'host' => 'shared.example', 'providers' => array( 'Alpha', 'Beta' ) ),
				array( 'directive' => 'frame-src', 'host' => 'a.example', 'providers' => array( 'Alpha' ) ),
				// load_host replaces the matched host, as in directives().
				array( 'directive' => 'frame-src', 'host' => 'c-nocookie.example', 'providers' => array( 'Gamma' ) ),
				// No label: the id stands in.
				array( 'directive' => 'script-src', 'host' => 'sdk.example', 'providers' => array( 'd' ) ),
			),
			$rows
		);
	}

	public function test_hosts_and_directives_agree_on_the_builtin_set(): void {
		$directives = Csp::directives( Descriptors::all() );
		$by_directive = array( 'frame-src' => array(), 'script-src' => array() );
		foreach ( Csp::hosts( Descriptors::all() ) as $row ) {
			$by_directive[ $row['directive'] ][] = $row['host'];
			self::assertNotSame( array(), $row['providers'], $row['host'] . ' has no provider' );
		}
		self::assertSame( $directives, $by_directive );
	}

	public function test_by_provider_groups_hosts_per_label(): void {
		$grouped = Csp::by_provider(
			array(
				array( 'id' => 'a', 'label' => 'Alpha', 'match' => array( 'iframe_host' => array( 'a.example' ), 'script_host' => array( 'sdk.a.example' ) ) ),
				array( 'id' => 'b', 'label' => 'Beta', 'match' => array( 'iframe_host' => array( 'a.example', 'b.example' ) ) ),
			)
		);

		self::assertSame(
			array(
				'Alpha' => array( 'frame-src' => array( 'a.example' ), 'script-src' => array( 'sdk.a.example' ) ),
				'Beta'  => array( 'frame-src' => array( 'a.example', 'b.example' ), 'script-src' => array() ),
			),
			$grouped
		);
	}
}
