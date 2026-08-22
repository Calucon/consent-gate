<?php
/**
 * Front-end assets: registration, conditional enqueue and the inline
 * config/style payloads. Registration is unconditional; the enqueue happens
 * only when a placeholder was actually rendered (Plugin's on-gated callback
 * delegates here), so pages without embeds ship no extra bytes.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CaluconEmbedGate\Support\AppearanceCss;
use CaluconEmbedGate\Support\ThemePalette;

/**
 * Hooks wp_enqueue_scripts and owns the gate.js/gate.css/cmp-bridge.js handles.
 */
final class Assets {

	/** @var array Sanitised option tree. */
	private array $options;

	/** @var callable Returns the §6.4 bridge config, or null when the bridge
	 *                stays off — resolved per call because the config runs
	 *                through a filter (docs/customizing.md). */
	private $cmp_config_source;

	/** @var callable Whether gating is bailed for this request — Plugin's
	 *                should_bail(), the single editing-context decision
	 *                (invariant 4), injected so it is never duplicated here. */
	private $should_bail;

	/**
	 * @param array    $options           Sanitised option tree.
	 * @param callable $cmp_config_source fn(): ?array — Plugin::cmp_bridge_config().
	 * @param callable $should_bail       fn(): bool — Plugin::should_bail().
	 */
	/** @var callable|null fn(): array — provider id => kind, for the button glyph. */
	private $kinds_source;

	public function __construct( array $options, callable $cmp_config_source, callable $should_bail, ?callable $kinds_source = null ) {
		$this->kinds_source      = $kinds_source;
		$this->options           = $options;
		$this->cmp_config_source = $cmp_config_source;
		$this->should_bail       = $should_bail;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register front-end assets; they are only enqueued when a placeholder
	 * was actually rendered, so pages without embeds ship no extra bytes.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_script(
			'calucon-embed-gate',
			plugins_url( 'assets/js/gate.js', CALUCON_EMBED_GATE_FILE ),
			array(),
			CALUCON_EMBED_GATE_VERSION,
			true
		);
		wp_register_style(
			'calucon-embed-gate',
			plugins_url( 'assets/css/gate.css', CALUCON_EMBED_GATE_FILE ),
			array(),
			CALUCON_EMBED_GATE_VERSION
		);
		// The §6.4 bridge is a separate file so the default build (bridge
		// off) ships not a byte of CMP code to visitors.
		wp_register_script(
			'calucon-embed-gate-cmp',
			plugins_url( 'assets/js/cmp-bridge.js', CALUCON_EMBED_GATE_FILE ),
			array( 'calucon-embed-gate' ),
			CALUCON_EMBED_GATE_VERSION,
			true
		);

		// Consent-memory config (§6.2): only shipped when the site enabled
		// memory. The default build stores nothing and needs no config.
		$config = $this->inline_config_json();
		if ( null !== $config ) {
			wp_add_inline_script(
				'calucon-embed-gate',
				'window.caluconEmbedGateConfig = ' . $config . ';',
				'before'
			);
		}

		// Resolve providers (fires the providers filter) and the theme
		// palette only when the CSS will actually use them — both cost a
		// little on every page view otherwise, embeds or not.
		$a          = $this->options['appearance'];
		$kinds      = ! empty( $a['play_icon'] ) && null !== $this->kinds_source ? (array) call_user_func( $this->kinds_source ) : array();
		$palette    = AppearanceCss::uses_theme_palette( $a ) ? ThemePalette::map() : array();
		$appearance = AppearanceCss::build( $a, $kinds, $palette );
		if ( '' !== $appearance ) {
			wp_add_inline_style( 'calucon-embed-gate', $appearance );
		}

		// Whole-page buffering (§3.3) gates on shutdown, long after this hook
		// — too late for a conditional enqueue, and printing tags from the
		// buffer callback would bypass the enqueue API. So when that option
		// is on, enqueue the (small, local, cacheable) assets on every
		// front-end page: the buffer may gate any of them.
		if ( $this->options['detection']['output_buffer'] && ! call_user_func( $this->should_bail ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * @return void
	 */
	public function enqueue_assets(): void {
		wp_enqueue_script( 'calucon-embed-gate' );
		wp_enqueue_style( 'calucon-embed-gate' );
		if ( null !== call_user_func( $this->cmp_config_source ) ) {
			wp_enqueue_script( 'calucon-embed-gate-cmp' );
		}
	}

	/**
	 * The caluconEmbedGateConfig JSON, attached to the front-end script as an
	 * inline before-script. Always present: the loading/error announcements
	 * (§8) must be translatable even when consent memory is off.
	 *
	 * @return string|null
	 */
	private function inline_config_json(): ?string {
		$consent = $this->options['consent'];
		$config  = array(
			'i18n' => array(
				'withdrawn' => __( 'Stored embed consents have been removed. Embeds will ask again.', 'calucon-third-party-embed-gate' ),
				'loading'   => __( 'Loading embedded content…', 'calucon-third-party-embed-gate' ),
				'error'     => __( 'The embedded content could not be loaded.', 'calucon-third-party-embed-gate' ),
				'errorLink' => __( 'Open it on the provider’s site.', 'calucon-third-party-embed-gate' ),
			),
		);
		if ( 'off' !== $consent['memory'] ) {
			$config['memory']       = $consent['memory'];
			$config['scope']        = $consent['scope'];
			$config['durationDays'] = $consent['duration_days'];
		}
		$cmp = call_user_func( $this->cmp_config_source );
		if ( null !== $cmp ) {
			$config['cmp'] = $cmp;
		}
		// Emitted verbatim inside an inline <script> via wp_add_inline_script.
		// Default json_encode already escapes
		// '/', so '</script>' cannot break out; JSON_HEX_TAG|APOS|QUOT|AMP is
		// belt-and-braces consistency with the data-cg-payload path (§9.1) so
		// no config string — i18n, a filtered CMP category — can ever inject
		// markup, matching esc_json()'s guarantees.
		return (string) wp_json_encode(
			$config,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
	}
}
