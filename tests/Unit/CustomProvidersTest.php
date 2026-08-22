<?php
/**
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Tests\Unit;

use CaluconEmbedGate\Providers\Builtin\Descriptors;
use CaluconEmbedGate\Providers\CustomProviders;
use CaluconEmbedGate\Providers\Registry;
use CaluconEmbedGate\Support\Options;
use CaluconEmbedGate\Tests\Support\PipelineFactory;
use PHPUnit\Framework\TestCase;

final class CustomProvidersTest extends TestCase {

	private function rows(): array {
		return Options::sanitize(
			array(
				'custom_providers' => array(
					array( 'label' => 'Example Partner', 'hosts' => "widgets.example-partner.com\nhttps://www.example-partner.com/embed/1", 'kind' => 'video' ),
					array( 'label' => 'Widget SDK', 'script_hosts' => 'cdn.widget-sdk.example' ),
				),
			)
		)['custom_providers'];
	}

	public function test_descriptors_carry_label_hosts_kind_and_generic_wording(): void {
		$descriptors = CustomProviders::descriptors( $this->rows() );

		self::assertCount( 2, $descriptors );
		$partner = $descriptors[0];
		self::assertSame( 'custom-example-partner', $partner['id'] );
		self::assertSame( 'Example Partner', $partner['label'] );
		self::assertSame( array( 'widgets.example-partner.com', 'www.example-partner.com' ), $partner['match']['iframe_host'] );
		self::assertArrayNotHasKey( 'script_host', $partner['match'] );
		self::assertSame( 'video', $partner['kind'] );
		self::assertSame( 'iframe', $partner['strategy'] );
		self::assertTrue( $partner['custom'] );
		self::assertNull( $partner['load_host'], 'a custom provider never rewrites the load target' );
		self::assertSame( 'Load content from Example Partner', $partner['action'] );
		self::assertStringContainsString( 'connects your browser to Example Partner', $partner['note'] );

		$sdk = $descriptors[1];
		self::assertSame( 'script', $sdk['strategy'] );
		self::assertSame( array( 'cdn.widget-sdk.example' ), $sdk['match']['script_host'] );
	}

	public function test_translation_callable_is_applied_to_the_wording(): void {
		$descriptors = CustomProviders::descriptors(
			$this->rows(),
			static function ( string $text ): string {
				return 'Load content from %s' === $text ? 'Inhalt von %s laden' : $text;
			}
		);

		self::assertSame( 'Inhalt von Example Partner laden', $descriptors[0]['action'] );
	}

	public function test_gated_with_the_owner_label_end_to_end(): void {
		$providers = array_merge( CustomProviders::descriptors( $this->rows() ), Descriptors::all() );

		$html = PipelineFactory::gate(
			'<iframe src="https://widgets.example-partner.com/embed/9" title="W" sandbox="allow-scripts"></iframe>'
			. '<script src="https://cdn.widget-sdk.example/sdk.js"></script>',
			array( 'example.test' ),
			array(),
			$providers
		);

		self::assertStringContainsString( 'data-cg-provider="custom-example-partner"', $html );
		self::assertStringContainsString( 'Load content from Example Partner', $html );
		self::assertStringContainsString( 'data-cg-provider="custom-widget-sdk"', $html );
		// Privilege never widens: the sandbox survives in the payload.
		self::assertStringContainsString( 'allow-scripts', $html );
		self::assertStringNotContainsString( '<iframe', $html );
	}

	public function test_builtin_hosts_are_refused_at_save_time_and_reported(): void {
		$reserved = CustomProviders::reserved_hosts( Descriptors::all() );
		self::assertContains( 'www.youtube.com', $reserved );
		self::assertContains( 'platform.twitter.com', $reserved, 'script hosts are reserved too' );

		$report = Options::sanitize_report(
			array(
				'custom_providers' => array(
					array( 'label' => 'Tube Thief', 'hosts' => "www.youtube.com\nwww.youtube-nocookie.com" ),
					array( 'label' => 'Half Thief', 'hosts' => "www.youtube.com\nmine.example", 'script_hosts' => 'platform.twitter.com' ),
				),
			),
			$reserved
		);

		self::assertSame(
			array(
				'Tube Thief' => array( 'www.youtube.com', 'www.youtube-nocookie.com' ),
				'Half Thief' => array( 'www.youtube.com', 'platform.twitter.com' ),
			),
			$report['rejected_hosts']
		);
		// The all-reserved row vanishes; the mixed row keeps only its own host.
		self::assertCount( 1, $report['options']['custom_providers'] );
		self::assertSame( array( 'mine.example' ), $report['options']['custom_providers'][0]['hosts'] );
		self::assertSame( array(), $report['options']['custom_providers'][0]['script_hosts'] );
		// Plain sanitize() refuses nothing (no reserved set) — the admin wrapper supplies it.
		self::assertSame( array(), Options::sanitize_report( array( 'custom_providers' => array( array( 'label' => 'X', 'hosts' => 'www.youtube.com' ) ) ) )['rejected_hosts'] );
	}

	public function test_builtin_wins_at_runtime_even_for_a_stale_row(): void {
		// A row stored before a built-in claimed its host (or written straight
		// into the option): the built-in still resolves, nocookie and all.
		$stale    = array( array( 'id' => 'custom-my-tube', 'label' => 'My Tube', 'hosts' => array( 'www.youtube.com', 'mine.example' ), 'script_hosts' => array(), 'kind' => '' ) );
		$builtin  = Descriptors::all();
		$custom   = CustomProviders::descriptors( $stale, null, CustomProviders::reserved_hosts( $builtin ) );
		$registry = new Registry( array_merge( $builtin, $custom ) );

		self::assertSame( array( 'mine.example' ), $custom[0]['match']['iframe_host'] );
		$resolved = $registry->resolve_for_url( 'https://www.youtube.com/embed/y_pjE_p1HwE', 'www.youtube.com' );
		self::assertSame( 'youtube', $resolved['id'] );
		self::assertSame( 'www.youtube-nocookie.com', $resolved['load_host'] );
		self::assertSame( 'custom-my-tube', $registry->resolve_for_url( 'https://mine.example/x', 'mine.example' )['id'] );
	}

	public function test_a_custom_provider_is_always_gated_even_when_its_row_is_disabled(): void {
		$options   = Options::sanitize(
			array(
				'custom_providers' => array( array( 'label' => 'Example Partner', 'hosts' => 'widgets.example-partner.com' ) ),
				'providers'        => array(
					'custom-example-partner' => array( 'enabled' => '0' ),
					'youtube'                => array( 'enabled' => '0' ),
				),
			)
		);
		$providers = Options::apply_provider_overrides( array_merge( Descriptors::all(), CustomProviders::descriptors( $options['custom_providers'] ) ), $options );

		$html = PipelineFactory::gate(
			'<iframe src="https://widgets.example-partner.com/embed/9" title="W" sandbox="allow-scripts"></iframe>'
			. '<iframe src="https://www.youtube.com/embed/y_pjE_p1HwE" title="Y"></iframe>',
			array( 'example.test' ),
			array(),
			$providers
		);

		self::assertStringContainsString( 'data-cg-provider="custom-example-partner"', $html, 'the custom row cannot exempt its host' );
		self::assertStringContainsString( 'www.youtube.com/embed/y_pjE_p1HwE', $html );
		self::assertSame( 1, substr_count( $html, '<iframe' ), 'only the built-in the owner disabled passes through' );
	}

	/**
	 * The product claim under custom providers: with rows that do NOT touch
	 * any host in the corpus, every fixture renders byte-identically — a
	 * custom provider changes nothing it was not told about.
	 */
	public function test_fixture_corpus_is_byte_identical_with_unrelated_custom_providers(): void {
		$rows = Options::sanitize(
			array(
				'custom_providers' => array(
					array( 'label' => 'Unrelated', 'hosts' => "embed.unrelated.invalid\nwww.unrelated.invalid", 'script_hosts' => 'cdn.unrelated.invalid', 'kind' => 'video' ),
					array( 'label' => 'Another', 'hosts' => 'another.invalid', 'kind' => '3d' ),
				),
			)
		)['custom_providers'];
		$providers = array_merge( Descriptors::all(), CustomProviders::descriptors( $rows ) );

		$checked = 0;
		foreach ( $this->fixture_dirs() as $dir ) {
			$input = (string) file_get_contents( $dir . '/input.html' );
			$ctx   = PipelineFactory::fixture_ctx( $dir );
			self::assertSame(
				PipelineFactory::gate( $input, array( 'example.test' ), $ctx ),
				PipelineFactory::gate( $input, array( 'example.test' ), $ctx, $providers ),
				basename( $dir )
			);
			++$checked;
		}
		self::assertGreaterThan( 50, $checked );
	}

	/**
	 * Rows that claim EVERY built-in host (the worst an owner, or a stale
	 * option, can do) — the corpus still renders byte-identically, because
	 * the built-ins keep their hosts.
	 */
	public function test_fixture_corpus_is_byte_identical_when_custom_rows_claim_every_builtin_host(): void {
		$builtin  = Descriptors::all();
		$reserved = CustomProviders::reserved_hosts( $builtin );
		$stale    = array(
			array( 'id' => 'custom-everything', 'label' => 'Everything', 'hosts' => $reserved, 'script_hosts' => $reserved, 'kind' => 'video' ),
		);
		$custom   = CustomProviders::descriptors( $stale, null, $reserved );
		self::assertSame( array(), $custom, 'a row with only reserved hosts yields no descriptor' );

		// And through the real save path, with a second row that survives:
		$options   = Options::sanitize_report(
			array( 'custom_providers' => array( $stale[0] + array( 'hosts' => implode( "\n", $reserved ) ), array( 'label' => 'Mine', 'hosts' => 'mine.invalid' ) ) ),
			$reserved
		)['options'];
		$providers = array_merge( $builtin, CustomProviders::descriptors( $options['custom_providers'], null, $reserved ) );
		self::assertCount( count( $builtin ) + 1, $providers );

		foreach ( $this->fixture_dirs() as $dir ) {
			$input = (string) file_get_contents( $dir . '/input.html' );
			$ctx   = PipelineFactory::fixture_ctx( $dir );
			self::assertSame(
				PipelineFactory::gate( $input, array( 'example.test' ), $ctx ),
				PipelineFactory::gate( $input, array( 'example.test' ), $ctx, $providers ),
				basename( $dir )
			);
		}
	}

	/**
	 * Hostile and malformed rows, straight into the option (bypassing the
	 * form): nothing throws, nothing widens, the gate holds, output is
	 * idempotent.
	 */
	public function test_hostile_rows_cannot_break_the_gate(): void {
		$rows = array(
			'not a row',
			null,
			array(),
			array( 'id' => 'youtube', 'label' => 'Forged builtin id', 'hosts' => array( 'forged.invalid' ) ),
			array( 'id' => 'custom-x"]', 'label' => 'Selector breaker', 'hosts' => array( 'sel.invalid' ) ),
			array( 'id' => 'custom-ok', 'label' => '<script>alert(1)</script>"Quoted"', 'hosts' => array( 'widgets.example-partner.com', 42, null, '' ), 'script_hosts' => 'not-an-array', 'kind' => array( 'video' ) ),
			array( 'id' => 'custom-long', 'label' => str_repeat( 'x', 10000 ), 'hosts' => array_fill( 0, 5000, 'many.invalid' ) ),
		);
		$providers = array_merge( Descriptors::all(), CustomProviders::descriptors( $rows, null, CustomProviders::reserved_hosts( Descriptors::all() ) ) );

		$ids = array_column( $providers, 'id' );
		self::assertNotContains( 'custom-x"]', $ids );
		self::assertSame( 1, count( array_keys( $ids, 'youtube', true ) ), 'a forged built-in id never produces a second descriptor' );

		$input = '<iframe src="https://widgets.example-partner.com/embed/9" title="W" sandbox="allow-scripts" allow="autoplay; fullscreen"></iframe>'
			. '<iframe src="https://many.invalid/x" title="M"></iframe>'
			. '<iframe src="https://www.youtube.com/embed/y_pjE_p1HwE" title="Y"></iframe>';
		$once = PipelineFactory::gate( $input, array( 'example.test' ), array(), $providers );

		self::assertStringNotContainsString( '<iframe', $once );
		self::assertStringNotContainsString( '<script>alert', $once );
		self::assertStringContainsString( '&lt;script&gt;', $once, 'the label is escaped wherever it lands' );
		self::assertStringContainsString( 'data-cg-provider="custom-ok"', $once );
		self::assertStringContainsString( 'data-cg-provider="custom-long"', $once );
		self::assertStringContainsString( 'data-cg-provider="youtube"', $once );
		self::assertStringContainsString( 'youtube-nocookie.com', $once, 'the built-in keeps its privacy-preserving load' );
		self::assertStringContainsString( 'allow-scripts', $once, 'sandbox survives (invariant 7)' );
		self::assertStringNotContainsString( 'autoplay', $once, 'autoplay never survives (invariant 8)' );
		self::assertSame( $once, PipelineFactory::gate( $once, array( 'example.test' ), array(), $providers ), 'idempotent' );
	}

	public function test_caps_bound_the_option_size(): void {
		$rows = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$rows[] = array( 'label' => 'P' . $i, 'hosts' => implode( "\n", array_map( static fn( $n ) => "h$n-$i.invalid", range( 1, 80 ) ) ) );
		}
		$clean = Options::sanitize( array( 'custom_providers' => $rows ) )['custom_providers'];

		self::assertCount( Options::MAX_CUSTOM_PROVIDERS, $clean );
		self::assertCount( Options::MAX_CUSTOM_HOSTS, $clean[0]['hosts'] );
	}

	/**
	 * @return string[]
	 */
	private function fixture_dirs(): array {
		$root = dirname( __DIR__ ) . '/Fixtures';
		$dirs = array();
		foreach ( scandir( $root ) as $entry ) {
			if ( '.' !== $entry[0] && is_dir( $root . '/' . $entry ) ) {
				$dirs[] = $root . '/' . $entry;
			}
		}
		return $dirs;
	}

	public function test_per_provider_overrides_apply_to_custom_ids_too(): void {
		$options = Options::sanitize(
			array(
				'custom_providers' => array( array( 'label' => 'Example Partner', 'hosts' => 'widgets.example-partner.com' ) ),
				'providers'        => array( 'custom-example-partner' => array( 'note' => 'Partner rules.', 'privacy_url' => 'https://example-partner.com/privacy' ) ),
			)
		);
		$providers = Options::apply_provider_overrides( CustomProviders::descriptors( $options['custom_providers'] ), $options );

		self::assertSame( 'Partner rules.', $providers[0]['note'] );
		self::assertSame( 'https://example-partner.com/privacy', $providers[0]['privacy_url'] );
	}

	public function test_id_for_slugifies_and_disambiguates(): void {
		self::assertSame( 'custom-example-videos', CustomProviders::id_for( 'Example Videos!', array() ) );
		self::assertSame( 'custom-example-videos-2', CustomProviders::id_for( 'Example Videos', array( 'custom-example-videos' ) ) );
		self::assertSame( 'custom-example-videos-3', CustomProviders::id_for( 'Example Videos', array( 'custom-example-videos', 'custom-example-videos-2' ) ) );
		self::assertSame( 'custom-provider', CustomProviders::id_for( '!!!', array() ) );
		self::assertMatchesRegularExpression( '/^custom-[a-z0-9-]{1,40}$/', CustomProviders::id_for( str_repeat( 'Very long label ', 10 ), array() ) );
	}
}
