<?php
/**
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Tests\Unit;

use CaluconEmbedGate\Providers\Provider;
use CaluconEmbedGate\Rendering\PlaceholderRenderer;
use PHPUnit\Framework\TestCase;

final class PlaceholderRendererTest extends TestCase {

	private function render( array $attributes, string $src = 'https://www.youtube.com/embed/x' ): string {
		$provider = Provider::normalize(
			array(
				'id'       => 'generic',
				'label'    => 'www.youtube.com',
				'note'     => 'Note text.',
				'action'   => 'Load it',
				'fallback' => $src,
			)
		);

		return ( new PlaceholderRenderer() )->render( $provider, $src, $attributes );
	}

	private function payload_of( string $html ): array {
		self::assertSame( 1, preg_match( '/data-cg-payload="([^"]*)"/', $html, $m ) );
		return json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
	}

	public function test_markup_contract(): void {
		$html = $this->render( array( 'title' => 'T' ) );

		self::assertStringContainsString( '<div class="cg-embed" role="group" aria-label="', $html );
		self::assertStringContainsString( 'data-cg-provider="generic"', $html );
		self::assertStringContainsString( '<button type="button" class="cg-embed__button">', $html );
		self::assertStringContainsString( '<p class="cg-embed__fallback"><a href="https://www.youtube.com/embed/x" rel="noopener nofollow">', $html );
	}

	public function test_output_never_contains_a_raw_iframe_substring(): void {
		// PLAN.md §9.1: re-processing protection depends on this.
		$html = $this->render( array( 'title' => '<iframe>', 'width' => '500' ) );

		self::assertStringNotContainsStringIgnoringCase( '<iframe', $html );
	}

	public function test_payload_carries_only_safelisted_attributes(): void {
		$payload = $this->payload_of(
			$this->render(
				array(
					'title'   => 'T',
					'width'   => '500',
					'style'   => 'position:absolute;visibility:hidden',
					'srcdoc'  => '<p>x</p>',
					'onload'  => 'evil()',
					'class'   => 'wp-embedded-content',
					'sandbox' => 'allow-scripts',
				)
			)
		);

		// 'class' is safelisted (identity, no capability — wp-embed.js keys
		// its resize handshake on it); style/srcdoc/on* must never survive.
		self::assertSame( array( 'title', 'width', 'sandbox', 'class' ), array_keys( $payload['attrs'] ) );
		self::assertSame( 'allow-scripts', $payload['attrs']['sandbox'] );
		self::assertSame( 'wp-embedded-content', $payload['attrs']['class'] );
	}

	public function test_autoplay_never_survives_the_rebuild(): void {
		$payload = $this->payload_of(
			$this->render( array( 'allow' => 'accelerometer; autoplay; encrypted-media' ) )
		);

		self::assertSame( 'accelerometer; encrypted-media', $payload['attrs']['allow'] );
	}

	public function test_boolean_allowfullscreen_round_trips(): void {
		$payload = $this->payload_of( $this->render( array( 'allowfullscreen' => true ) ) );

		self::assertTrue( $payload['attrs']['allowfullscreen'] );
	}

	public function test_autoplay_stripped_from_a_provider_supplied_allow(): void {
		// The descriptor's iframe_allow is a second injection point for the
		// allow attribute, separate from the original tag's copied allow. A
		// provider (own registry or the consent_gate_providers filter) that
		// lists autoplay must not get it back on the rebuilt frame (invariant
		// 8) — the original embed carried no allow here, so this value can
		// only come from the descriptor path.
		$provider = Provider::normalize(
			array(
				'id'           => 'generic',
				'label'        => 'www.youtube.com',
				'note'         => 'Note text.',
				'action'       => 'Load it',
				'fallback'     => 'https://www.youtube.com/embed/x',
				'iframe_allow' => 'autoplay; encrypted-media; picture-in-picture',
			)
		);

		$html    = ( new PlaceholderRenderer() )->render( $provider, 'https://www.youtube.com/embed/x', array( 'title' => 'T' ) );
		$payload = $this->payload_of( $html );

		self::assertSame( 'encrypted-media; picture-in-picture', $payload['attrs']['allow'] );
	}

	public function test_fallback_url_with_a_non_navigable_scheme_is_dropped(): void {
		// safe_url guards the fallback href against schemes htmlspecialchars
		// does not neutralise. A javascript:/data:/vbscript: fallback — from a
		// provider descriptor or the calucon_embed_gate_fallback filter — must
		// yield no link at all, never a live hostile href (invariant 2 gives a
		// real link or none, never a trap).
		foreach ( array( 'javascript:alert(1)', 'data:text/html,<script>x</script>', 'vbscript:msgbox', 'JavaScript:alert(1)' ) as $bad ) {
			$provider = Provider::normalize(
				array(
					'id'       => 'generic',
					'label'    => 'www.youtube.com',
					'note'     => 'Note text.',
					'action'   => 'Load it',
					'fallback' => $bad,
				)
			);

			// src is '' so the fallback URL is the only navigable-URL source.
			$html = ( new PlaceholderRenderer() )->render( $provider, '', array( 'title' => 'T' ) );

			self::assertStringNotContainsStringIgnoringCase( 'javascript:', $html, $bad );
			self::assertStringNotContainsStringIgnoringCase( 'vbscript:', $html, $bad );
			self::assertStringNotContainsString( 'data:text/html', $html, $bad );
			self::assertStringNotContainsString( 'cg-embed__fallback', $html, $bad );
		}
	}

	public function test_privacy_link_renders_for_described_providers(): void {
		$provider = Provider::normalize(
			array(
				'id'          => 'vimeo',
				'label'       => 'Vimeo',
				'note'        => 'Note text.',
				'action'      => 'Load it',
				'fallback'    => 'https://vimeo.com/1',
				'privacy_url' => 'https://vimeo.com/privacy',
			)
		);

		$html = ( new PlaceholderRenderer( null, null, null, null, array(), true ) )->render( $provider, 'https://player.vimeo.com/video/1', array( 'title' => 'T' ) );

		self::assertStringContainsString( '<p class="cg-embed__privacy"><a href="https://vimeo.com/privacy" rel="noopener nofollow">Vimeo privacy policy</a></p>', $html );
	}

	public function test_privacy_link_absent_without_a_url_and_when_disabled(): void {
		// Generic providers carry no privacy_url — nothing to link.
		self::assertStringNotContainsString( 'cg-embed__privacy', $this->render( array( 'title' => 'T' ) ) );

		// Off unless the display.privacy_link option turns it on (the default
		// constructor argument mirrors the option default).
		$provider = Provider::normalize(
			array(
				'id'          => 'vimeo',
				'label'       => 'Vimeo',
				'note'        => 'Note text.',
				'action'      => 'Load it',
				'fallback'    => 'https://vimeo.com/1',
				'privacy_url' => 'https://vimeo.com/privacy',
			)
		);
		$off      = new PlaceholderRenderer( null, null, null, null, array(), false );

		self::assertStringNotContainsString( 'cg-embed__privacy', $off->render( $provider, 'https://player.vimeo.com/video/1', array( 'title' => 'T' ) ) );
		self::assertStringNotContainsString( 'cg-embed__privacy', ( new PlaceholderRenderer() )->render( $provider, 'https://player.vimeo.com/video/1', array( 'title' => 'T' ) ), 'default = off' );
	}

	public function test_privacy_link_url_is_scheme_guarded(): void {
		// A filtered descriptor could carry anything; same rule as every
		// other URL sink — a non-http(s) privacy URL yields no link.
		$provider = Provider::normalize(
			array(
				'id'          => 'vimeo',
				'label'       => 'Vimeo',
				'note'        => 'Note text.',
				'action'      => 'Load it',
				'fallback'    => 'https://vimeo.com/1',
				'privacy_url' => 'javascript:alert(1)',
			)
		);

		$html = ( new PlaceholderRenderer( null, null, null, null, array(), true ) )->render( $provider, 'https://player.vimeo.com/video/1', array( 'title' => 'T' ) );

		self::assertStringNotContainsString( 'cg-embed__privacy', $html );
		self::assertStringNotContainsStringIgnoringCase( 'javascript:', $html );
	}

	public function test_per_embed_text_from_ctx_overrides_provider_text_and_is_escaped(): void {
		// §7.5 block attributes arrive as ctx; they beat the provider's text
		// (default or settings override) and go through the same escaping.
		$html = $this->render_with_ctx(
			array(
				'action_text' => 'Load the <trailer>',
				'note_text'   => 'Custom "notice" & text',
			)
		);

		self::assertStringContainsString( '<button type="button" class="cg-embed__button">Load the &lt;trailer&gt;</button>', $html );
		self::assertStringContainsString( '<p class="cg-embed__note">Custom &quot;notice&quot; &amp; text</p>', $html );
		self::assertStringNotContainsString( 'Load it', $html );
		self::assertStringNotContainsString( 'Note text.', $html );

		// Empty strings mean "inherit".
		$inherit = $this->render_with_ctx( array( 'action_text' => '', 'note_text' => '' ) );
		self::assertStringContainsString( '>Load it</button>', $inherit );
	}

	public function test_https_fallback_url_survives(): void {
		// The negative test above must fail for the right reason: a real https
		// fallback still renders a link.
		$html = $this->render( array( 'title' => 'T' ) );

		self::assertStringContainsString( 'cg-embed__fallback', $html );
		self::assertStringContainsString( 'href="https://www.youtube.com/embed/x"', $html );
	}

	private function render_with_ctx( array $ctx ): string {
		$provider = Provider::normalize(
			array(
				'id'       => 'generic',
				'label'    => 'www.youtube.com',
				'note'     => 'Note text.',
				'action'   => 'Load it',
				'fallback' => 'https://www.youtube.com/embed/x',
			)
		);

		return ( new PlaceholderRenderer() )->render( $provider, 'https://www.youtube.com/embed/x', array( 'title' => 'T' ), $ctx );
	}

	public function test_poster_renders_decorative_site_origin_image(): void {
		$html = $this->render_with_ctx( array( 'poster' => 'https://example.test/wp-content/uploads/p.jpg' ) );

		self::assertStringContainsString( '<div class="cg-embed cg-embed--poster"', $html );
		self::assertStringContainsString(
			'<img class="cg-embed__poster" src="https://example.test/wp-content/uploads/p.jpg" alt="" aria-hidden="true" loading="lazy">',
			$html
		);
	}

	public function test_no_poster_context_leaves_the_contract_untouched(): void {
		$html = $this->render_with_ctx( array() );

		self::assertStringContainsString( '<div class="cg-embed" role="group"', $html );
		self::assertStringNotContainsString( 'cg-embed__poster', $html );
	}

	public function test_poster_rejects_non_url_schemes(): void {
		// Fail closed (invariant 1): a script- or data-scheme "poster" from a
		// misbehaving filter must vanish, not render.
		foreach ( array( 'javascript:alert(1)', 'data:image/png;base64,x', 'blob:https://x', '   ' ) as $bad ) {
			self::assertStringNotContainsString(
				'cg-embed__poster',
				$this->render_with_ctx( array( 'poster' => $bad ) ),
				$bad
			);
		}
	}

	public function test_poster_url_is_attribute_escaped(): void {
		$html = $this->render_with_ctx( array( 'poster' => 'https://example.test/p.jpg?a=1&b="x"' ) );

		self::assertStringContainsString( 'src="https://example.test/p.jpg?a=1&amp;b=&quot;x&quot;"', $html );
	}
}
