<?php
/**
 * render_block integration: block themes and Gutenberg content.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CaluconEmbedGate\Plugin;

/**
 * Hooks render_block. Fires for nested blocks and again for their parent,
 * whose content already contains the rendered children (PLAN.md §9.1) —
 * safe here because placeholders contain no '<iframe' substring, so the
 * probe in IframeRule skips already-gated children.
 */
final class RenderBlock {

	/** @var Plugin */
	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter(
			'render_block',
			array( $this, 'filter' ),
			(int) apply_filters( 'calucon_embed_gate_render_block_priority', 10 ),
			2
		);
	}

	/**
	 * @param string $content Rendered block HTML.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public function filter( $content, $block ): string {
		$content = (string) $content;

		if ( ! $this->plugin->has_gateable_markup( $content ) || $this->plugin->should_bail() ) {
			return $content;
		}

		// Per-block override (PLAN.md §7.5), stored as a block attribute by
		// the editor integration: 'never' skips gating for this block (the
		// editor made an explicit call); 'always' forces gating past the
		// should_gate filter and disabled providers.
		$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$override = isset( $attrs['caluconEmbedGate'] ) && is_string( $attrs['caluconEmbedGate'] ) ? $attrs['caluconEmbedGate'] : '';
		if ( 'never' === $override ) {
			return $content;
		}

		$ctx = array(
			'integration' => 'render_block',
			'block'       => isset( $block['blockName'] ) ? $block['blockName'] : null,
			'post_id'     => get_the_ID(),
			'force_gate'  => 'always' === $override,
		);

		// Owner-supplied poster (§5.4): stored as an attachment ID by the
		// editor integration, resolved and own-host-validated here — the
		// renderer only ever sees a vetted site-origin URL.
		if ( isset( $attrs['caluconEmbedGatePoster'] ) && is_numeric( $attrs['caluconEmbedGatePoster'] ) ) {
			$poster = $this->plugin->poster_url( (int) $attrs['caluconEmbedGatePoster'] );
			if ( '' !== $poster ) {
				$ctx['poster'] = $poster;
			}
		}

		// Per-block text (§7.5): plain text only, capped like the settings
		// overrides, and empty means "inherit". The renderer escapes on
		// output; this layer just keeps markup and length out of the story.
		foreach ( array(
			'caluconEmbedGateAction' => 'action_text',
			'caluconEmbedGateNote'   => 'note_text',
		) as $attr => $ctx_key ) {
			if ( isset( $attrs[ $attr ] ) && is_string( $attrs[ $attr ] ) ) {
				$text = trim( wp_strip_all_tags( $attrs[ $attr ], true ) );
				if ( '' !== $text ) {
					$ctx[ $ctx_key ] = mb_substr( $text, 0, 'note_text' === $ctx_key ? 400 : 120 );
				}
			}
		}

		return $this->plugin->gate( $content, $ctx );
	}
}
