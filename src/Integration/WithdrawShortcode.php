<?php
/**
 * Withdrawal control (PLAN.md §6.2): storing consent creates an Art. 7(3)
 * withdrawal obligation that the stateless default does not have. This
 * shortcode gives the site a visible, keyboard-reachable control to place
 * in the privacy policy.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [calucon_embed_gate_withdraw] — renders a real button; gate.js clears the
 * plugin's storage key when it is pressed and announces the result via the
 * companion live region.
 */
final class WithdrawShortcode {

	/** @var callable|null Enqueues the plugin's front-end assets. */
	private $enqueue;

	/** @var string Appearance variant: '' (filled) | 'outline' | 'link'. */
	private string $style;

	/**
	 * @param callable|null $enqueue Called on render so gate.js is present
	 *                               even on pages without a single embed —
	 *                               the privacy-policy page this control is
	 *                               made for.
	 * @param string        $style   appearance.withdraw_style: the sanitised
	 *                               variant ('' renders the filled default
	 *                               with no extra class — the pre-0.10
	 *                               markup, byte for byte).
	 */
	public function __construct( ?callable $enqueue = null, string $style = '' ) {
		$this->enqueue = $enqueue;
		$this->style   = in_array( $style, array( 'outline', 'link' ), true ) ? $style : '';
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'calucon_embed_gate_withdraw', array( $this, 'render' ) );
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts = array() ): string {
		if ( null !== $this->enqueue ) {
			call_user_func( $this->enqueue );
		}

		$atts = shortcode_atts(
			array(
				'label' => __( 'Withdraw embed consents', 'calucon-third-party-embed-gate' ),
			),
			is_array( $atts ) ? $atts : array(),
			'calucon_embed_gate_withdraw'
		);

		$status_id = 'cg-withdraw-status-' . wp_unique_id();

		$class = 'cg-withdraw' . ( '' !== $this->style ? ' cg-withdraw--' . $this->style : '' );

		return '<button type="button" class="' . esc_attr( $class ) . '" data-cg-withdraw aria-controls="' . esc_attr( $status_id ) . '">'
			. esc_html( $atts['label'] )
			. '</button>'
			. '<span id="' . esc_attr( $status_id ) . '" class="cg-withdraw__status" role="status" aria-live="polite"></span>';
	}
}
