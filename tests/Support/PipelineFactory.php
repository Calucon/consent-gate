<?php
/**
 * Builds the WordPress-free pipeline exactly as the plugin wires it,
 * minus the WordPress bridges. Shared by unit tests, fixture generation
 * and the E2E test server.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Tests\Support;

use CaluconEmbedGate\Detection\EmbedObjectRule;
use CaluconEmbedGate\Detection\HostMatcher;
use CaluconEmbedGate\Detection\HtmlScanner;
use CaluconEmbedGate\Detection\IframeRule;
use CaluconEmbedGate\Detection\ScriptRule;
use CaluconEmbedGate\Providers\Builtin\Descriptors;
use CaluconEmbedGate\Providers\Registry;
use CaluconEmbedGate\Rendering\PlaceholderRenderer;

final class PipelineFactory {

	/**
	 * Run content through both rules, as Plugin::gate() does. The fixture
	 * corpus treats example.test as the site's own host.
	 *
	 * @param string       $html      Content.
	 * @param string[]     $own_hosts Own hosts.
	 * @param array        $ctx       Context.
	 * @param array[]|null $providers Descriptor set; builtins by default.
	 * @return string
	 */
	public static function gate( string $html, array $own_hosts = array( 'example.test' ), array $ctx = array(), ?array $providers = null ): string {
		$scanner  = new HtmlScanner();
		$hosts    = new HostMatcher( $own_hosts );
		$registry = new Registry( null === $providers ? Descriptors::all() : $providers );
		// The provider privacy link is off by default; a fixture opts in
		// through ctx.json {"privacy_link": true} (the E2E app turns it on
		// for every page).
		$renderer = new PlaceholderRenderer( null, null, null, null, array(), ! empty( $ctx['privacy_link'] ) );

		$iframe = new IframeRule( $scanner, $hosts, $registry, $renderer );
		$embed  = new EmbedObjectRule( $scanner, $hosts, $registry, $renderer );
		$script = new ScriptRule( $scanner, $hosts, $registry, $renderer );

		return $script->apply( $embed->apply( $iframe->apply( $html, $ctx ), $ctx ), $ctx );
	}

	/**
	 * The integration context for a fixture case. A fixture may carry an
	 * optional ctx.json with extra context — e.g. the §5.4 poster URL an
	 * integration would resolve. Shared by FixtureTest and the generator so
	 * both build fixtures identically.
	 *
	 * @param string $dir Fixture directory.
	 * @return array
	 */
	public static function fixture_ctx( string $dir ): array {
		$ctx = array( 'integration' => 'test' );
		if ( is_file( $dir . '/ctx.json' ) ) {
			$extra = json_decode( (string) file_get_contents( $dir . '/ctx.json' ), true );
			if ( is_array( $extra ) ) {
				$ctx = array_merge( $ctx, $extra );
			}
		}
		return $ctx;
	}
}
