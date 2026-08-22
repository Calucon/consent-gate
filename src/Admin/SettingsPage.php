<?php
/**
 * Settings screen (PLAN.md §7.1, M3 subset: Providers + Detection).
 *
 * Admin/ is allowed to use WordPress globals (PLAN.md §2.2). Everything
 * user-submitted goes through Options::sanitize(); everything printed goes
 * through esc_*(); the form is nonce-protected by the Settings API.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CaluconEmbedGate\Cmp\Detector;
use CaluconEmbedGate\Providers\CustomProviders;
use CaluconEmbedGate\Support\AppearanceCss;
use CaluconEmbedGate\Support\Csp;
use CaluconEmbedGate\Support\Options;
use CaluconEmbedGate\Support\ThemePalette;

/**
 * Settings > Calucon Third-Party Embed Gate.
 */
final class SettingsPage {

	/** @var callable Returns the provider descriptors; resolved lazily so
	 *                providers registered by the theme's functions.php (which
	 *                loads after plugins_loaded) appear in the table and the
	 *                CSP snippet. */
	private $providers_source;

	/** @var callable|null Returns the ContentScan behind the Status screen. */
	private $scanner_source;

	/** @var callable|null Returns sample placeholder HTML for the live preview. */
	private $preview_source;

	/**
	 * @param callable      $providers_source fn(): array[] — builtins + filtered.
	 * @param callable|null $scanner_source   fn(): \CaluconEmbedGate\Support\ContentScan.
	 * @param callable|null $preview_source   fn(): string — rendered sample placeholder.
	 */
	public function __construct( callable $providers_source, ?callable $scanner_source = null, ?callable $preview_source = null ) {
		$this->providers_source = $providers_source;
		$this->scanner_source   = $scanner_source;
		$this->preview_source   = $preview_source;
	}

	/**
	 * @return array[]
	 */
	private function providers(): array {
		return (array) call_user_func( $this->providers_source );
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_footer_text', array( $this, 'footer_support_link' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CALUCON_EMBED_GATE_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * A "Support development" link in the plugin's row meta — where
	 * WordPress convention puts donate/support links, next to "View
	 * details" — not among the action links. A plain link: nothing loads
	 * until the owner clicks it (the plugin's no-outbound rule applies to
	 * its admin UI too).
	 *
	 * @param array  $links Row meta links.
	 * @param string $file  Plugin basename the row is for.
	 * @return array
	 */
	public function row_meta( $links, $file ): array {
		if ( plugin_basename( CALUCON_EMBED_GATE_FILE ) === $file ) {
			$links[] = '<a href="https://ko-fi.com/calucon" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'Support development', 'calucon-third-party-embed-gate' ) . '</a>';
		}
		return (array) $links;
	}

	/**
	 * "Settings" next to Deactivate on the Plugins screen — the standard
	 * shortcut to a plugin's settings page.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$settings = '<a href="' . esc_url( admin_url( 'options-general.php?page=calucon-embed-gate' ) ) . '">'
			. esc_html__( 'Settings', 'calucon-third-party-embed-gate' ) . '</a>';
		array_unshift( $links, $settings );
		return (array) $links;
	}

	/**
	 * A single, unobtrusive support link in the admin footer — shown only on
	 * this plugin's own settings screen, never elsewhere in wp-admin. A plain
	 * link, not a Ko-fi widget or remote badge: nothing off-site loads (the
	 * plugin's no-outbound-request principle applies to its own admin UI too),
	 * the browser only contacts Ko-fi if the owner clicks.
	 *
	 * @param string $text The default footer text.
	 * @return string
	 */
	public function footer_support_link( $text ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'settings_page_calucon-embed-gate' !== $screen->id ) {
			return (string) $text;
		}

		// No emoji here: WordPress's emoji script would replace it with an
		// <img> fetched from s.w.org — an outbound request, which this plugin
		// does not make, not even from its own admin screen.
		$link = '<a href="https://ko-fi.com/calucon" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'support its development', 'calucon-third-party-embed-gate' ) . '</a>';

		/* translators: %s: link reading "support its development", to the developer's Ko-fi page. */
		return sprintf( esc_html__( 'Calucon Third-Party Embed Gate is free and open source — you can %s.', 'calucon-third-party-embed-gate' ), $link );
	}

	/**
	 * Assets for the Appearance controls: WordPress's own colour picker, the
	 * front-end panel stylesheet (so the live preview IS the real panel) and
	 * the preview/contrast script. Settings screen only — everything is
	 * bundled or core, nothing remote (invariant 9).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_calucon-embed-gate' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'calucon-embed-gate',
			plugins_url( 'assets/css/gate.css', CALUCON_EMBED_GATE_FILE ),
			array(),
			CALUCON_EMBED_GATE_VERSION
		);
		wp_enqueue_style(
			'calucon-embed-gate-admin',
			plugins_url( 'assets/css/admin-appearance.css', CALUCON_EMBED_GATE_FILE ),
			array( 'calucon-embed-gate' ),
			CALUCON_EMBED_GATE_VERSION
		);
		wp_enqueue_script(
			'calucon-embed-gate-admin',
			plugins_url( 'assets/js/admin-appearance.js', CALUCON_EMBED_GATE_FILE ),
			array( 'jquery', 'wp-color-picker' ),
			CALUCON_EMBED_GATE_VERSION,
			true
		);
		wp_enqueue_script(
			'calucon-embed-gate-admin-tabs',
			plugins_url( 'assets/js/admin-tabs.js', CALUCON_EMBED_GATE_FILE ),
			array(),
			CALUCON_EMBED_GATE_VERSION,
			true
		);
		wp_add_inline_style( 'calucon-embed-gate-admin', AppearanceCss::kind_icon_rules( '.cg-kind-glyph' ) );
		wp_enqueue_script(
			'calucon-embed-gate-admin-custom-providers',
			plugins_url( 'assets/js/admin-custom-providers.js', CALUCON_EMBED_GATE_FILE ),
			array(),
			CALUCON_EMBED_GATE_VERSION,
			true
		);
		wp_enqueue_script(
			'calucon-embed-gate-admin-csp',
			plugins_url( 'assets/js/admin-csp.js', CALUCON_EMBED_GATE_FILE ),
			array(),
			CALUCON_EMBED_GATE_VERSION,
			true
		);
		wp_add_inline_script(
			'calucon-embed-gate-admin-csp',
			'window.caluconEmbedGateCsp = ' . wp_json_encode(
				array(
					// The owner's browser loads this once, same-origin, on an
					// explicit click, to read the site's own CSP header. The
					// server never requests anything (invariant 9).
					'home'       => home_url( '/' ),
					'required'   => Csp::directives( $this->providers() ),
					'directives' => $this->csp_directive_labels(),
					'i18n'       => array(
						'checking'          => __( 'Checking your home page…', 'calucon-third-party-embed-gate' ),
						'error'             => __( 'Could not load your home page from this browser, so nothing could be checked. Try again; if it keeps failing, open the home page in a new tab and look for a Content-Security-Policy header in the browser\'s developer tools (Network panel).', 'calucon-third-party-embed-gate' ),
						'none'              => __( 'Your home page sends no Content-Security-Policy. You can skip this section.', 'calucon-third-party-embed-gate' ),
						'noneHint'          => __( 'Checked just now, as your browser sees the page. If you are sure a policy is set somewhere (some setups only send it on certain pages), add the lines below to it anyway — listing a host that is never loaded is harmless.', 'calucon-third-party-embed-gate' ),
						'clean'             => __( 'Your site sends a Content-Security-Policy, and it already allows every enabled provider. Nothing to do.', 'calucon-third-party-embed-gate' ),
						'missing'           => __( 'Your site sends a Content-Security-Policy that does not yet allow these hosts — their embeds would stay empty after the visitor clicks Load:', 'calucon-third-party-embed-gate' ),
						'missingHint'       => __( 'Add the lines below to your policy, where it is defined, and run the check again.', 'calucon-third-party-embed-gate' ),
						'reportOnly'        => __( 'Your site sends a report-only policy (Content-Security-Policy-Report-Only). It logs violations but blocks nothing, so embeds still load.', 'calucon-third-party-embed-gate' ),
						'reportOnlyMissing' => __( 'If you later switch it to an enforced policy, it would need these hosts:', 'calucon-third-party-embed-gate' ),
						'reportOnlyClean'   => __( 'It already lists every enabled provider, so switching it to enforced would be safe for the embeds.', 'calucon-third-party-embed-gate' ),
						'copied'            => __( 'Copied to the clipboard.', 'calucon-third-party-embed-gate' ),
						'copyFailed'        => __( 'Could not copy — select the text and copy it by hand.', 'calucon-third-party-embed-gate' ),
					),
				)
			) . ';',
			'before'
		);
		wp_add_inline_script(
			'calucon-embed-gate-admin',
			'window.caluconEmbedGateAdminPalette = ' . wp_json_encode( $this->theme_palette() ) . ';',
			'before'
		);
		wp_add_inline_script(
			'calucon-embed-gate-admin',
			'window.caluconEmbedGateAdminI18n = ' . wp_json_encode(
				array(
					/* translators: contrast-report line. 1: which colour pair, 2: measured ratio like "4.9:1", 3: verdict. */
					'line'         => __( '%1$s: %2$s — %3$s', 'calucon-third-party-embed-gate' ),
					'panelText'    => __( 'Panel text on the panel background', 'calucon-third-party-embed-gate' ),
					'buttonText'   => __( 'Button text on the button background', 'calucon-third-party-embed-gate' ),
					'linkText'     => __( 'Fallback link on the panel background', 'calucon-third-party-embed-gate' ),
					'withdrawText' => __( 'Withdraw button text on its background', 'calucon-third-party-embed-gate' ),
					'fixText'      => __( 'Make readable', 'calucon-third-party-embed-gate' ),
					'fixedText'    => __( 'Colour adjusted for readability.', 'calucon-third-party-embed-gate' ),
					'applied'      => __( 'Style applied.', 'calucon-third-party-embed-gate' ),
					'resetDone'    => __( 'Appearance reset to theme defaults.', 'calucon-third-party-embed-gate' ),
					'undone'       => __( 'Undone.', 'calucon-third-party-embed-gate' ),
					'resetRow'     => __( 'Reset', 'calucon-third-party-embed-gate' ),
					/* translators: %s: the setting's row label, e.g. "Icon". */
					'resetRowAria' => __( 'Reset %s to its default', 'calucon-third-party-embed-gate' ),
					/* translators: %s: the setting's row label, e.g. "Icon". */
					'rowReset'     => __( '%s reset to its default.', 'calucon-third-party-embed-gate' ),
					/* translators: %d: number of further customised settings not named in the section badge. */
					'moreCount'    => __( '+%d more', 'calucon-third-party-embed-gate' ),
					'leaveWarning' => __( 'You have unsaved appearance changes.', 'calucon-third-party-embed-gate' ),
					'pass'         => __( 'readable (meets the 4.5:1 minimum)', 'calucon-third-party-embed-gate' ),
					'fail'         => __( 'hard to read — below the 4.5:1 minimum. Pick a lighter or darker colour for this pair.', 'calucon-third-party-embed-gate' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The active theme's palette for the pickers' swatches and the Theme
	 * colour selects. See Support\ThemePalette.
	 *
	 * @return array<int,array{name:string,slug:string,color:string}>
	 */
	private function theme_palette(): array {
		return ThemePalette::entries();
	}

	/**
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Calucon Third-Party Embed Gate', 'calucon-third-party-embed-gate' ),
			__( 'Calucon Third-Party Embed Gate', 'calucon-third-party-embed-gate' ),
			'manage_options',
			'calucon-embed-gate',
			array( $this, 'render' )
		);
	}

	/**
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			'calucon_embed_gate',
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => Options::defaults(),
			)
		);
	}

	/**
	 * Sanitise a submitted option tree, refusing custom-provider hosts the
	 * built-in (or code-registered) providers handle — and telling the owner
	 * which ones, so the refusal never looks like data loss.
	 *
	 * @param mixed $raw Submitted option tree.
	 * @return array
	 */
	public function sanitize_options( $raw ): array {
		$report = Options::sanitize_report( $raw, CustomProviders::reserved_hosts( $this->providers() ) );
		foreach ( $report['rejected_hosts'] as $label => $hosts ) {
			add_settings_error(
				Options::OPTION,
				'calucon_embed_gate_reserved_host_' . md5( (string) $label ),
				sprintf(
					/* translators: 1: custom provider label, 2: comma-separated host names. */
					__( '%1$s: %2$s skipped — a built-in provider already handles these hosts, with its privacy-preserving load target and texts. Adjust that provider in the table instead.', 'calucon-third-party-embed-gate' ),
					esc_html( (string) $label ),
					esc_html( implode( ', ', $hosts ) )
				),
				'warning'
			);
		}
		return $report['options'];
	}

	/**
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options   = Options::sanitize( get_option( Options::OPTION, Options::defaults() ) );
		$providers = $options['providers'];
		$detection = $options['detection'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Calucon Third-Party Embed Gate', 'calucon-third-party-embed-gate' ); ?></h1>
			<p><?php esc_html_e( 'Third-party embeds are replaced with a placeholder until the visitor clicks to load them. Nothing is contacted, and nothing is stored, before that click.', 'calucon-third-party-embed-gate' ); ?></p>

			<?php
			// The tab bar starts hidden and is revealed by admin-tabs.js:
			// without JavaScript the page renders as one long document, every
			// panel visible — tabs are an enhancement, never a gate.
			$tabs = array(
				'providers'  => __( 'Providers', 'calucon-third-party-embed-gate' ),
				'detection'  => __( 'Detection', 'calucon-third-party-embed-gate' ),
				'appearance' => __( 'Appearance', 'calucon-third-party-embed-gate' ),
				'consent'    => __( 'Consent memory', 'calucon-third-party-embed-gate' ),
				'status'     => __( 'Status & tools', 'calucon-third-party-embed-gate' ),
			);
			?>
			<div class="nav-tab-wrapper cg-tabs" role="tablist" aria-label="<?php echo esc_attr( __( 'Calucon Third-Party Embed Gate settings sections', 'calucon-third-party-embed-gate' ) ); ?>" hidden>
				<?php $first = true; ?>
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<button type="button" id="cg-tabbtn-<?php echo esc_attr( $tab_key ); ?>" class="nav-tab<?php echo $first ? ' nav-tab-active' : ''; ?>" role="tab" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" aria-controls="cg-tab-<?php echo esc_attr( $tab_key ); ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>"><?php echo esc_html( $tab_label ); ?></button>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</div>

			<form action="options.php" method="post">
				<?php settings_fields( 'calucon_embed_gate' ); ?>

				<?php $this->render_providers_tab( $providers, $options['display'], $options['custom_providers'] ); ?>

				<?php $this->render_detection_tab( $detection ); ?>

				<?php $this->render_appearance_tab( $options['appearance'] ); ?>

				<?php $this->render_consent_tab( $options ); ?>

				<?php submit_button(); ?>

				<?php
				// Sticky status bar (admin-appearance.js): shown while the form
				// holds unsaved changes, with Save and a short-lived Undo after a
				// quick style or reset. Hidden markup without JavaScript — the
				// normal Save button above still works.
				?>
				<div id="cg-unsaved" class="cg-unsaved" role="status" aria-live="polite" hidden>
					<span class="cg-unsaved__text"><?php esc_html_e( 'You have unsaved changes.', 'calucon-third-party-embed-gate' ); ?></span>
					<button type="button" id="cg-undo" class="button" hidden><?php esc_html_e( 'Undo all changes', 'calucon-third-party-embed-gate' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'calucon-third-party-embed-gate' ); ?></button>
				</div>
			</form>

			<?php
			// Read-only diagnostics and generated snippets: admin-tabs.js hides
			// the form's Save button while this panel is active (data-cg-readonly).
			?>
			<div id="cg-tab-status" class="cg-tab-panel" role="tabpanel" aria-labelledby="cg-tabbtn-status" data-cg-readonly="1">
			<?php $this->render_compatibility( $options ); ?>
			<?php $this->render_status(); ?>
			<?php $this->render_csp(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The Providers tab: per-provider gate, privacy-variant and text
	 * overrides (§7.1).
	 *
	 * @param array $providers Sanitised per-provider option rows.
	 * @param array $display   Sanitised display option subtree.
	 * @param array $custom    Sanitised owner-defined provider rows.
	 * @return void
	 */
	private function render_providers_tab( array $providers, array $display, array $custom ): void {
		?>
<div id="cg-tab-providers" class="cg-tab-panel" role="tabpanel" aria-labelledby="cg-tabbtn-providers">
				<h2><?php esc_html_e( 'Providers', 'calucon-third-party-embed-gate' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Disabling a provider stops gating its embeds — they load exactly as WordPress renders them. Unknown third-party iframes and scripts are always gated by the generic entries. The privacy policy URL column shows the built-in link greyed out; enter your own (https) to point at a localised or moved policy page.', 'calucon-third-party-embed-gate' ); ?></p>
				<p>
					<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[display][privacy_link]" value="0">
					<label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION ); ?>[display][privacy_link]" value="1" <?php checked( $display['privacy_link'] ); ?>> <?php esc_html_e( 'Link each provider\'s privacy policy in the placeholder panel, so visitors can read it before loading anything. Applies to the providers listed below; unknown embeds have no known policy to link.', 'calucon-third-party-embed-gate' ); ?></label>
				</p>
				<table class="widefat striped" style="max-width: 60rem;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Provider', 'calucon-third-party-embed-gate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Gate', 'calucon-third-party-embed-gate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Privacy-preserving load', 'calucon-third-party-embed-gate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Custom note (optional)', 'calucon-third-party-embed-gate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Custom button text (optional)', 'calucon-third-party-embed-gate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Privacy policy URL (optional)', 'calucon-third-party-embed-gate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $this->providers() as $descriptor ) : ?>
						<?php
						$id = isset( $descriptor['id'] ) ? (string) $descriptor['id'] : '';
						if ( '' === $id ) {
							continue;
						}
						$row         = isset( $providers[ $id ] ) ? $providers[ $id ] : array();
						$enabled     = ! isset( $row['enabled'] ) || $row['enabled'];
						$privacy     = ! isset( $row['privacy_variant'] ) || $row['privacy_variant'];
						$has_variant = ! empty( $descriptor['load_host'] ) || ! empty( $descriptor['load_query'] );
						$name_prefix = esc_attr( Options::OPTION . '[providers][' . $id . ']' );
						$label       = isset( $descriptor['label'] ) ? $descriptor['label'] : $id;

						// Accessible names for the row's bare table-cell inputs
						// (WCAG 1.3.1, 4.1.2): the column header alone names
						// nothing in a screen reader's forms mode.
						/* translators: %s: provider label. */
						$aria_gate = sprintf( __( 'Gate %s embeds', 'calucon-third-party-embed-gate' ), $label );
						/* translators: %s: provider label. */
						$aria_privacy = sprintf( __( 'Use the privacy-preserving load for %s', 'calucon-third-party-embed-gate' ), $label );
						/* translators: %s: provider label. */
						$aria_note = sprintf( __( 'Custom note for %s', 'calucon-third-party-embed-gate' ), $label );
						/* translators: %s: provider label. */
						$aria_action = sprintf( __( 'Custom button text for %s', 'calucon-third-party-embed-gate' ), $label );
						/* translators: %s: provider label. */
						$aria_policy = sprintf( __( 'Privacy policy URL for %s', 'calucon-third-party-embed-gate' ), $label );
						$builtin_url = isset( $descriptor['privacy_url'] ) && is_string( $descriptor['privacy_url'] ) ? $descriptor['privacy_url'] : '';
						?>
						<tr>
							<td><span class="cg-provider-name"><span class="cg-kind-glyph" data-cg-kind="<?php echo esc_attr( $descriptor['kind'] ?? '' ); ?>" title="<?php echo esc_attr( $this->kind_labels()[ $descriptor['kind'] ?? '' ] ?? '' ); ?>"></span><span><?php echo esc_html( $label ); ?>
							<?php
							if ( ! empty( $descriptor['custom'] ) ) :
								?>
								<span class="cg-tag"><?php esc_html_e( 'added by you', 'calucon-third-party-embed-gate' ); ?></span><?php endif; ?></span></span></td>
							<td>
								<?php if ( ! empty( $descriptor['custom'] ) ) : ?>
									<span title="<?php esc_attr_e( 'Your own providers are always gated. To let a host through, use the never-gate list under Detection.', 'calucon-third-party-embed-gate' ); ?>"><?php esc_html_e( 'always', 'calucon-third-party-embed-gate' ); ?></span>
								<?php else : ?>
									<input type="hidden" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>[enabled]" value="0">
									<input type="checkbox" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[enabled]" value="1" aria-label="<?php echo esc_attr( $aria_gate ); ?>" <?php checked( $enabled ); ?>>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $has_variant ) : ?>
									<input type="hidden" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[privacy_variant]" value="0">
									<input type="checkbox" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[privacy_variant]" value="1" aria-label="<?php echo esc_attr( $aria_privacy ); ?>" <?php checked( $privacy ); ?>>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td><input type="text" class="regular-text" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[note]" aria-label="<?php echo esc_attr( $aria_note ); ?>" value="<?php echo esc_attr( isset( $row['note'] ) ? $row['note'] : '' ); ?>"></td>
							<td><input type="text" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[action]" aria-label="<?php echo esc_attr( $aria_action ); ?>" value="<?php echo esc_attr( isset( $row['action'] ) ? $row['action'] : '' ); ?>"></td>
							<td><input type="url" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[privacy_url]" aria-label="<?php echo esc_attr( $aria_policy ); ?>" value="<?php echo esc_attr( isset( $row['privacy_url'] ) ? $row['privacy_url'] : '' ); ?>" placeholder="<?php echo esc_attr( $builtin_url ); ?>" pattern="https://.*" inputmode="url"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php $this->render_custom_providers( $custom ); ?>
				</div>
<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- the close tag sits at column 0 so the method emits the moved block byte-identically, with no stray indentation.
	}

	/**
	 * Human names for the provider kinds (AppearanceCss::KINDS), generic first.
	 *
	 * @return array<string,string> kind => label.
	 */
	private function kind_labels(): array {
		return array(
			''         => __( 'Generic', 'calucon-third-party-embed-gate' ),
			'video'    => __( 'Video', 'calucon-third-party-embed-gate' ),
			'map'      => __( 'Map', 'calucon-third-party-embed-gate' ),
			'audio'    => __( 'Audio / podcast', 'calucon-third-party-embed-gate' ),
			'social'   => __( 'Social post', 'calucon-third-party-embed-gate' ),
			'form'     => __( 'Form / survey', 'calucon-third-party-embed-gate' ),
			'calendar' => __( 'Calendar / booking', 'calucon-third-party-embed-gate' ),
			'document' => __( 'Document / slides', 'calucon-third-party-embed-gate' ),
			'image'    => __( 'Image / GIF', 'calucon-third-party-embed-gate' ),
			'3d'       => __( '3D / virtual tour', 'calucon-third-party-embed-gate' ),
		);
	}

	/**
	 * "Your own providers": owner-defined descriptors (Providers\CustomProviders).
	 *
	 * One row per saved provider plus one blank row, so adding works with
	 * JavaScript off (save once per provider); admin-custom-providers.js
	 * adds an "Add another" button that clones the blank row. Everything
	 * per-provider beyond name/hosts/kind lives in the table above, where
	 * a saved custom provider appears like any built-in.
	 *
	 * @param array $custom Sanitised owner-defined provider rows.
	 * @return void
	 */
	private function render_custom_providers( array $custom ): void {
		// Hosts the built-ins claim, to warn about precedence at a glance.
		$builtin_hosts = array();
		foreach ( $this->providers() as $descriptor ) {
			if ( ! empty( $descriptor['custom'] ) ) {
				continue;
			}
			foreach ( array( 'iframe_host', 'script_host' ) as $key ) {
				foreach ( (array) ( $descriptor['match'][ $key ] ?? array() ) as $host ) {
					$builtin_hosts[ $host ] = isset( $descriptor['label'] ) ? (string) $descriptor['label'] : (string) $descriptor['id'];
				}
			}
		}
		$kinds       = $this->kind_labels();
		$rows        = array_values( $custom );
		$rows[]      = array(
			'id'           => '',
			'label'        => '',
			'hosts'        => array(),
			'script_hosts' => array(),
			'kind'         => '',
		);
		$blank_index = count( $rows ) - 1;
		?>
				<h3 id="cg-custom-providers-heading"><?php esc_html_e( 'Your own providers', 'calucon-third-party-embed-gate' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Embeds from hosts nobody listed are already gated, under their host name. Add a provider here to give such a host a proper name and a kind (for the button icon); after saving it appears in the table above, where you can set its note, button text and privacy-policy link like for any other provider. Hosts must match exactly — list www. and bare variants separately. Adding a provider never changes what is gated: unknown hosts are gated either way, and hosts a built-in provider handles stay with that provider (they are skipped here). Your own providers are always gated; to let a host through, use the never-gate list under Detection.', 'calucon-third-party-embed-gate' ); ?></p>
				<table class="widefat striped cg-custom-providers" id="cg-custom-providers" style="max-width: 60rem;" aria-labelledby="cg-custom-providers-heading">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'calucon-third-party-embed-gate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Embed hosts (one per line)', 'calucon-third-party-embed-gate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Script hosts (optional)', 'calucon-third-party-embed-gate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Kind', 'calucon-third-party-embed-gate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Remove', 'calucon-third-party-embed-gate' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php
						$is_blank    = $i === $blank_index;
						$name_prefix = esc_attr( Options::OPTION . '[custom_providers][' . $i . ']' );
						$row_label   = '' !== $row['label'] ? $row['label'] : __( 'new provider', 'calucon-third-party-embed-gate' );
						/* translators: %s: provider label. */
						$aria_name = sprintf( __( 'Name of %s', 'calucon-third-party-embed-gate' ), $row_label );
						/* translators: %s: provider label. */
						$aria_hosts = sprintf( __( 'Embed hosts of %s', 'calucon-third-party-embed-gate' ), $row_label );
						/* translators: %s: provider label. */
						$aria_scripts = sprintf( __( 'Script hosts of %s', 'calucon-third-party-embed-gate' ), $row_label );
						/* translators: %s: provider label. */
						$aria_kind = sprintf( __( 'Kind of %s', 'calucon-third-party-embed-gate' ), $row_label );
						/* translators: %s: provider label. */
						$aria_remove = sprintf( __( 'Remove %s', 'calucon-third-party-embed-gate' ), $row_label );
						$overlaps    = array();
						foreach ( array_merge( $row['hosts'], $row['script_hosts'] ) as $host ) {
							if ( isset( $builtin_hosts[ $host ] ) ) {
								$overlaps[ $host ] = $builtin_hosts[ $host ];
							}
						}
						?>
						<tr<?php echo $is_blank ? ' data-cg-blank="1"' : ''; ?>>
							<td>
								<input type="hidden" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>[id]" value="<?php echo esc_attr( $row['id'] ); ?>">
								<input type="text" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[label]" aria-label="<?php echo esc_attr( $aria_name ); ?>" value="<?php echo esc_attr( $row['label'] ); ?>" maxlength="80" placeholder="<?php echo esc_attr( $is_blank ? __( 'e.g. Example Videos', 'calucon-third-party-embed-gate' ) : '' ); ?>">
							</td>
							<td>
								<textarea name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[hosts]" rows="2" class="code" aria-label="<?php echo esc_attr( $aria_hosts ); ?>" placeholder="<?php echo esc_attr( $is_blank ? "embed.example.com\nexample.com" : '' ); ?>"><?php echo esc_textarea( implode( "\n", $row['hosts'] ) ); ?></textarea>
								<?php foreach ( $overlaps as $host => $builtin_label ) : ?>
									<p class="description cg-custom-overlap">
										<?php
										printf(
											/* translators: 1: host name, 2: built-in provider label. */
											esc_html__( '%1$s is handled by the built-in %2$s provider, which takes precedence — this entry is ignored for that host. Remove it here to clear this note.', 'calucon-third-party-embed-gate' ),
											'<code>' . esc_html( $host ) . '</code>',
											esc_html( $builtin_label )
										);
										?>
									</p>
								<?php endforeach; ?>
							</td>
							<td><textarea name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[script_hosts]" rows="2" class="code" aria-label="<?php echo esc_attr( $aria_scripts ); ?>"><?php echo esc_textarea( implode( "\n", $row['script_hosts'] ) ); ?></textarea></td>
							<td class="cg-kind-cell">
								<span class="cg-kind-glyph" data-cg-kind="<?php echo esc_attr( $row['kind'] ); ?>" aria-hidden="true"></span>
								<select name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[kind]" aria-label="<?php echo esc_attr( $aria_kind ); ?>">
									<?php foreach ( $kinds as $value => $kind_label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['kind'], $value ); ?>><?php echo esc_html( $kind_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<?php if ( ! $is_blank ) : ?>
									<input type="checkbox" name="<?php echo $name_prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[remove]" value="1" aria-label="<?php echo esc_attr( $aria_remove ); ?>">
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p id="cg-custom-add-wrap" hidden><button type="button" class="button" id="cg-custom-add"><?php esc_html_e( 'Add another provider', 'calucon-third-party-embed-gate' ); ?></button></p>
		<?php
	}

	/**
	 * The Detection tab: rule toggles and the host lists (§7.1).
	 *
	 * @param array $detection Sanitised detection option subtree.
	 * @return void
	 */
	private function render_detection_tab( array $detection ): void {
		?>
<div id="cg-tab-detection" class="cg-tab-panel" role="tabpanel" aria-labelledby="cg-tabbtn-detection">
				<h2><?php esc_html_e( 'Detection', 'calucon-third-party-embed-gate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Rules', 'calucon-third-party-embed-gate' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][iframes]" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][iframes]" value="1" <?php checked( $detection['iframes'] ); ?>> <?php esc_html_e( 'Gate third-party iframes', 'calucon-third-party-embed-gate' ); ?></label><br>
							<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][scripts]" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][scripts]" value="1" <?php checked( $detection['scripts'] ); ?>> <?php esc_html_e( 'Gate third-party scripts in content', 'calucon-third-party-embed-gate' ); ?></label><br>
							<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][images]" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][images]" value="1" <?php checked( $detection['images'] ); ?>> <?php esc_html_e( 'Gate third-party images (hotlinked images request the third party with the visitor\'s IP attached; can affect layouts)', 'calucon-third-party-embed-gate' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cg-always-gate"><?php esc_html_e( 'Always gate these hosts', 'calucon-third-party-embed-gate' ); ?></label></th>
						<td>
							<textarea id="cg-always-gate" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][always_gate]" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $detection['always_gate'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One host per line. These are gated even when they would otherwise count as the site itself — for example a subdomain of your own domain that serves third-party widgets.', 'calucon-third-party-embed-gate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cg-own-hosts"><?php esc_html_e( 'Additional own hosts', 'calucon-third-party-embed-gate' ); ?></label></th>
						<td>
							<textarea id="cg-own-hosts" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][own_hosts]" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $detection['own_hosts'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One host per line, e.g. cdn.example.com or *.example.com. These are treated as the site itself and never gated.', 'calucon-third-party-embed-gate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cg-never-gate"><?php esc_html_e( 'Never gate these hosts', 'calucon-third-party-embed-gate' ); ?></label></th>
						<td>
							<textarea id="cg-never-gate" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][never_gate]" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $detection['never_gate'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Embeds from these hosts load without a placeholder. Use only for third parties you have covered elsewhere — this plugin then no longer prevents their requests.', 'calucon-third-party-embed-gate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page builders', 'calucon-third-party-embed-gate' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][output_buffer]" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][output_buffer]" value="1" <?php checked( $detection['output_buffer'] ); ?>> <?php esc_html_e( 'Gate the whole page output (for Elementor, Divi, WPBakery, Bricks)', 'calucon-third-party-embed-gate' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only enable this if embeds from a page builder are not being gated. It buffers the entire page, which can conflict with other buffering or streaming plugins. Any error inside the buffer returns the page unmodified.', 'calucon-third-party-embed-gate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Host matching', 'calucon-third-party-embed-gate' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][www_equivalence]" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION ); ?>[detection][www_equivalence]" value="1" <?php checked( $detection['www_equivalence'] ); ?>> <?php esc_html_e( 'Treat www.example.com and example.com as the same site', 'calucon-third-party-embed-gate' ); ?></label>
						</td>
					</tr>
				</table>
				</div>
<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- the close tag sits at column 0 so the method emits the moved block byte-identically, with no stray indentation.
	}

	/**
	 * Bundled 24×24 glyphs for the choice menus (inline SVG, no requests).
	 * Keyed by "<option key>:<value>"; '*' is the per-key fallback. Drawn in
	 * currentColor so they follow the admin text colour.
	 *
	 * @return array<string,string> key => SVG inner markup.
	 */
	private static function choice_icons(): array {
		$rect       = '<rect x="3" y="5" width="18" height="14" rx="%s" fill="currentColor" opacity="0.85"/>';
		$outline    = '<rect x="3.75" y="5.75" width="16.5" height="12.5" rx="%s" fill="none" stroke="currentColor" stroke-width="1.5"/>';
		$pill       = '<rect x="%s" y="9" width="%s" height="6" rx="3" fill="currentColor"/>';
		$pill_out   = '<rect x="4.75" y="9.75" width="14.5" height="4.5" rx="2.25" fill="none" stroke="currentColor" stroke-width="1.5"/>';
		$lines_left = '<rect x="4" y="7" width="12" height="2" fill="currentColor"/><rect x="4" y="11" width="16" height="2" fill="currentColor"/><rect x="4" y="15" width="9" height="2" fill="currentColor"/>';
		$lines_ctr  = '<rect x="6" y="7" width="12" height="2" fill="currentColor"/><rect x="4" y="11" width="16" height="2" fill="currentColor"/><rect x="7.5" y="15" width="9" height="2" fill="currentColor"/>';
		$inherit    = '<rect x="3.75" y="5.75" width="16.5" height="12.5" rx="2" fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="3 2"/>';
		$dim        = '<rect x="3" y="5" width="18" height="14" rx="2" fill="currentColor" opacity="%s"/><rect x="6" y="13" width="8" height="3" rx="1" fill="currentColor"/>';

		return array(
			'preset:default'         => sprintf( $rect, 2 ),
			'preset:minimal'         => sprintf( $outline, 2 ),
			'preset:card'            => '<rect x="5" y="7" width="16" height="13" rx="3" fill="currentColor" opacity="0.25"/>' . sprintf( $outline, 3 ),
			'corners:'               => sprintf( $rect, 2 ),
			'corners:square'         => sprintf( $rect, 0 ),
			'corners:rounded'        => sprintf( $rect, 5 ),
			'corners:pill'           => sprintf( $rect, 7 ),
			'corners:custom'         => sprintf( $outline, 4 ) . '<path d="M8 13l2.5 2.5L16 10" fill="none" stroke="currentColor" stroke-width="1.5"/>',
			'shadow:'                => $inherit,
			'shadow:none'            => sprintf( $outline, 2 ),
			'shadow:soft'            => '<rect x="5" y="7" width="16" height="13" rx="2" fill="currentColor" opacity="0.2"/>' . sprintf( $rect, 2 ),
			'shadow:strong'          => '<rect x="6" y="8" width="16" height="13" rx="2" fill="currentColor" opacity="0.45"/>' . sprintf( $rect, 2 ),
			'density:'               => sprintf( $outline, 2 ) . '<rect x="7" y="9" width="10" height="6" rx="1" fill="currentColor"/>',
			'density:compact'        => sprintf( $outline, 2 ) . '<rect x="5.5" y="7.5" width="13" height="9" rx="1" fill="currentColor"/>',
			'density:spacious'       => sprintf( $outline, 2 ) . '<rect x="9" y="10" width="6" height="4" rx="1" fill="currentColor"/>',
			'align:'                 => $lines_left,
			'align:center'           => $lines_ctr,
			'note_size:'             => '<rect x="4" y="6" width="16" height="3" fill="currentColor"/><rect x="4" y="11" width="16" height="3" fill="currentColor"/><rect x="4" y="16" width="10" height="3" fill="currentColor"/>',
			'note_size:small'        => '<rect x="4" y="8" width="16" height="2" fill="currentColor"/><rect x="4" y="12" width="16" height="2" fill="currentColor"/><rect x="4" y="16" width="10" height="2" fill="currentColor"/>',
			'button_style:'          => sprintf( $pill, 4, 16 ),
			'button_style:outline'   => $pill_out,
			'button_size:'           => sprintf( $pill, 5, 14 ),
			'button_size:small'      => sprintf( $pill, 7, 10 ),
			'button_size:large'      => '<rect x="3" y="8" width="18" height="8" rx="4" fill="currentColor"/>',
			'button_width:'          => sprintf( $pill, 7, 10 ),
			'button_width:full'      => '<rect x="3" y="9" width="18" height="6" rx="3" fill="currentColor"/>',
			'hover:'                 => sprintf( $pill, 5, 14 ) . '<rect x="3.5" y="7.5" width="17" height="9" rx="4.5" fill="none" stroke="currentColor" stroke-width="1" opacity="0.4"/>',
			'hover:none'             => sprintf( $pill, 5, 14 ),
			'hover:strong'           => sprintf( $pill, 5, 14 ) . '<rect x="2.75" y="6.75" width="18.5" height="10.5" rx="5.25" fill="none" stroke="currentColor" stroke-width="1.5"/>',
			'poster_panel:'          => sprintf( $outline, 2 ) . '<rect x="5.5" y="12" width="8" height="4.5" rx="1" fill="currentColor"/>',
			'poster_panel:center'    => sprintf( $outline, 2 ) . '<rect x="8" y="9.75" width="8" height="4.5" rx="1" fill="currentColor"/>',
			'poster_panel:bar'       => sprintf( $outline, 2 ) . '<rect x="3.75" y="13.5" width="16.5" height="4.75" rx="1" fill="currentColor"/>',
			'poster_dim:'            => sprintf( $dim, '0.25' ),
			'poster_dim:light'       => sprintf( $dim, '0.5' ),
			'poster_dim:strong'      => sprintf( $dim, '0.8' ),
			'withdraw_style:'        => sprintf( $pill, 4, 16 ),
			'withdraw_style:outline' => $pill_out,
			'withdraw_style:link'    => '<rect x="5" y="10" width="14" height="2" fill="currentColor"/><rect x="5" y="14" width="14" height="1.5" fill="currentColor"/>',
		);
	}

	/**
	 * One choice row of the Appearance tab: the same compact disclosure as
	 * the colour rows — the summary shows a glyph and the current label, the
	 * menu lists every option with its glyph. Real radios; no-JS safe.
	 *
	 * @param string $id          Element id of the control (the <details>).
	 * @param string $key         appearance option key.
	 * @param string $label       Row label.
	 * @param array  $choices     value => label.
	 * @param array  $appearance  Sanitised appearance subtree.
	 * @param string $description Optional description line.
	 * @return void
	 */
	private function select_row( string $id, string $key, string $label, array $choices, array $appearance, string $description = '' ): void {
		$icons    = self::choice_icons();
		$label_id = $id . '-label';
		$current  = (string) $appearance[ $key ];
		if ( ! array_key_exists( $current, $choices ) ) {
			$current = (string) array_key_first( $choices );
		}
		$icon_of = static function ( string $value ) use ( $icons, $key ): string {
			$svg = isset( $icons[ $key . ':' . $value ] ) ? $icons[ $key . ':' . $value ] : ( isset( $icons[ $key . ':*' ] ) ? $icons[ $key . ':*' ] : '' );
			return '<svg class="cg-choice__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">' . $svg . '</svg>';
		};
		?>
					<tr>
						<th scope="row"><span id="<?php echo esc_attr( $label_id ); ?>"><?php echo esc_html( $label ); ?></span></th>
						<td>
							<details id="<?php echo esc_attr( $id ); ?>" class="cg-color cg-choice" data-cg-choice="<?php echo esc_attr( $key ); ?>">
								<summary class="cg-color__summary" id="<?php echo esc_attr( $id ); ?>-summary" aria-labelledby="<?php echo esc_attr( $label_id ); ?> <?php echo esc_attr( $id ); ?>-summary">
									<?php echo $icon_of( $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static bundled SVG from choice_icons(). ?>
									<span class="cg-color__name"><?php echo esc_html( $choices[ $current ] ); ?></span>
								</summary>
								<div class="cg-color__menu cg-choice__menu" role="radiogroup" aria-labelledby="<?php echo esc_attr( $label_id ); ?>">
									<?php foreach ( $choices as $value => $choice_label ) : ?>
										<label class="cg-color__option">
											<input type="radio" name="<?php echo esc_attr( Options::OPTION . '[appearance][' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" data-cg-name="<?php echo esc_attr( $choice_label ); ?>" <?php checked( (string) $value, $current ); ?>>
											<?php echo $icon_of( (string) $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static bundled SVG from choice_icons(). ?>
											<span class="cg-color__label"><?php echo esc_html( $choice_label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</details>
							<?php if ( '' !== $description ) : ?>
								<p class="description"><?php echo esc_html( $description ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
		<?php
	}

	/**
	 * What a cleared colour resolves to, for showing "Default" honestly:
	 * the theme's base/contrast/accent-8 presets when the palette has them
	 * (the stylesheet's own fallbacks), else the plugin's built-in colours.
	 *
	 * @param string $key     appearance colour key.
	 * @param array  $palette Theme palette entries.
	 * @return array{hex:string,name:string}
	 */
	private function default_color( string $key, array $palette ): array {
		$base = (string) preg_replace( '/^dark_/', '', $key );
		if ( 'link' === $base || 'border_color' === $base ) {
			$fg = $this->default_color( 'fg', $palette );
			return array(
				'hex'  => $fg['hex'],
				'name' => __( 'Default — same as panel text', 'calucon-third-party-embed-gate' ),
			);
		}
		$map  = array(
			'bg'        => array( 'base', '#1b1b1b' ),
			'fg'        => array( 'contrast', '#f0f0f0' ),
			'accent'    => array( 'accent-8', '#5c9e00' ),
			'accent_fg' => array( '', '#1b1b1b' ),
		);
		$slug = isset( $map[ $base ] ) ? $map[ $base ][0] : '';
		$hex  = isset( $map[ $base ] ) ? $map[ $base ][1] : '';
		foreach ( $palette as $entry ) {
			if ( '' !== $slug && $entry['slug'] === $slug ) {
				return array(
					'hex'  => $entry['color'],
					/* translators: %s: the theme's colour name. */
					'name' => sprintf( __( 'Default — theme %s', 'calucon-third-party-embed-gate' ), $entry['name'] ),
				);
			}
		}
		return array(
			'hex'  => $hex,
			'name' => __( 'Default — built-in', 'calucon-third-party-embed-gate' ),
		);
	}

	/**
	 * Whether any option in a group differs from its default — an advanced
	 * section starts open only when the owner has already used it.
	 *
	 * @param array $appearance Sanitised subtree.
	 * @param array $keys       Option keys in the section.
	 * @return bool
	 */
	private function section_touched( array $appearance, array $keys ): bool {
		$defaults = Options::defaults()['appearance'];
		foreach ( $keys as $key ) {
			if ( isset( $appearance[ $key ], $defaults[ $key ] ) && $appearance[ $key ] !== $defaults[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One colour row of the Appearance tab: a compact disclosure showing the
	 * current colour and its name, opening a menu of Default · the theme's
	 * palette (named) · Custom (reveals the picker). Native <details>, real
	 * radios — it works without JavaScript and reads right in forms mode.
	 * Submits "<key>" = '' | preset:<slug> | custom and "<key>_custom" = hex.
	 *
	 * @param string $key         appearance option key.
	 * @param string $label       Row label.
	 * @param array  $appearance  Sanitised appearance subtree.
	 * @param string $description Optional description line.
	 * @param string $row_attrs   Extra attributes for the <tr> (class/hidden).
	 * @return void
	 */
	private function color_row( string $key, string $label, array $appearance, string $description = '', string $row_attrs = '' ): void {
		$dashed    = str_replace( '_', '-', $key );
		$id        = 'cg-color-' . $dashed;
		$label_id  = 'cg-label-' . $dashed;
		$name      = Options::OPTION . '[appearance][' . $key . ']';
		$stored    = (string) $appearance[ $key ];
		$is_preset = 0 === strpos( $stored, 'preset:' );
		$slug      = $is_preset ? substr( $stored, 7 ) : '';
		$is_custom = '' !== $stored && ! $is_preset;
		$palette   = $this->theme_palette();
		$default   = $this->default_color( $key, $palette );
		$current   = $default;
		$known     = false;
		foreach ( $palette as $entry ) {
			if ( $is_preset && $entry['slug'] === $slug ) {
				$current = array(
					'hex'  => $entry['color'],
					'name' => $entry['name'],
				);
				$known   = true;
			}
		}
		if ( $is_custom ) {
			$current = array(
				'hex'  => $stored,
				/* translators: %s: hex colour. */
				'name' => sprintf( __( 'Custom %s', 'calucon-third-party-embed-gate' ), $stored ),
			);
		} elseif ( $is_preset && ! $known ) {
			$current = array(
				'hex'  => '',
				/* translators: %s: theme colour slug no longer in the theme's palette. */
				'name' => sprintf( __( '%s (not in the current theme)', 'calucon-third-party-embed-gate' ), $slug ),
			);
		}
		?>
					<tr <?php echo $row_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal attribute strings from this class. ?>>
						<th scope="row"><span id="<?php echo esc_attr( $label_id ); ?>"><?php echo esc_html( $label ); ?></span></th>
						<td>
							<details class="cg-color" data-cg-color-key="<?php echo esc_attr( $key ); ?>">
								<summary class="cg-color__summary" id="cg-color-<?php echo esc_attr( $key ); ?>-summary" aria-labelledby="<?php echo esc_attr( $label_id ); ?> cg-color-<?php echo esc_attr( $key ); ?>-summary">
									<span class="cg-color__dot<?php echo '' === $current['hex'] ? ' cg-color__dot--missing' : ''; ?>"<?php echo '' !== $current['hex'] ? ' style="background:' . esc_attr( $current['hex'] ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline. ?>></span>
									<span class="cg-color__name"><?php echo esc_html( $current['name'] ); ?></span>
								</summary>
								<div class="cg-color__menu" role="radiogroup" aria-labelledby="<?php echo esc_attr( $label_id ); ?>">
									<label class="cg-color__option">
										<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="" data-cg-hex="<?php echo esc_attr( $default['hex'] ); ?>" data-cg-name="<?php echo esc_attr( $default['name'] ); ?>" <?php checked( ! $is_preset && ! $is_custom ); ?>>
										<span class="cg-color__dot" style="background:<?php echo esc_attr( $default['hex'] ); ?>"></span>
										<span class="cg-color__label"><?php echo esc_html( $default['name'] ); ?></span>
									</label>
									<?php foreach ( $palette as $entry ) : ?>
										<label class="cg-color__option">
											<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="preset:<?php echo esc_attr( $entry['slug'] ); ?>" data-cg-hex="<?php echo esc_attr( $entry['color'] ); ?>" data-cg-name="<?php echo esc_attr( $entry['name'] ); ?>" <?php checked( $is_preset && $entry['slug'] === $slug ); ?>>
											<span class="cg-color__dot" style="background:<?php echo esc_attr( $entry['color'] ); ?>"></span>
											<span class="cg-color__label"><?php echo esc_html( $entry['name'] ); ?></span>
										</label>
									<?php endforeach; ?>
									<?php if ( $is_preset && ! $known ) : ?>
										<label class="cg-color__option">
											<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $stored ); ?>" data-cg-hex="" data-cg-name="<?php echo esc_attr( $current['name'] ); ?>" checked>
											<span class="cg-color__dot cg-color__dot--missing"></span>
											<span class="cg-color__label"><?php echo esc_html( $current['name'] ); ?></span>
										</label>
									<?php endif; ?>
									<label class="cg-color__option cg-color__option--custom">
										<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="custom" data-cg-hex="<?php echo esc_attr( $is_custom ? $stored : '' ); ?>" data-cg-name="<?php esc_attr_e( 'Custom', 'calucon-third-party-embed-gate' ); ?>" <?php checked( $is_custom ); ?>>
										<span class="cg-color__dot cg-color__dot--spectrum"></span>
										<span class="cg-color__label"><?php esc_html_e( 'Custom colour…', 'calucon-third-party-embed-gate' ); ?></span>
									</label>
									<div class="cg-color__custom" <?php echo $is_custom ? '' : 'hidden'; ?>>
										<input type="text" id="<?php echo esc_attr( $id ); ?>" class="cg-color-field" data-cg-color="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( Options::OPTION . '[appearance][' . $key . '_custom]' ); ?>" value="<?php echo esc_attr( $is_custom ? $stored : '' ); ?>">
									</div>
								</div>
							</details>
							<?php if ( '' !== $description ) : ?>
								<p class="description"><?php echo esc_html( $description ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
		<?php
	}

	/**
	 * The Appearance tab (§7.1): sections of a single form, a live preview
	 * and the readability report. Every control maps 1:1 to an appearance
	 * option; the emitted CSS lives in Support\AppearanceCss.
	 *
	 * @param array $appearance Sanitised appearance subtree.
	 * @return void
	 */
	private function render_appearance_tab( array $appearance ): void {
		?>
<div id="cg-tab-appearance" class="cg-tab-panel" role="tabpanel" aria-labelledby="cg-tabbtn-appearance">
				<h2><?php esc_html_e( 'Appearance', 'calucon-third-party-embed-gate' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Style the placeholder without writing CSS. Pick a starting point, change what you like, and watch the preview. Colours can follow your theme\'s palette or be your own; the readability check flags any pair that would be hard to read.', 'calucon-third-party-embed-gate' ); ?></p>

				<div class="cg-appearance-layout">
				<div class="cg-appearance-controls">
				<fieldset class="cg-quick-styles">
					<legend><?php esc_html_e( 'Start from a style', 'calucon-third-party-embed-gate' ); ?></legend>
					<div class="cg-quick-styles__grid">
					<?php
					$quick_styles = array(
						'cinema'  => __( 'Dark cinema', 'calucon-third-party-embed-gate' ),
						'minimal' => __( 'Light minimal', 'calucon-third-party-embed-gate' ),
						'card'    => __( 'Brand card', 'calucon-third-party-embed-gate' ),
						'pastel'  => __( 'Soft pastel', 'calucon-third-party-embed-gate' ),
					);
					foreach ( $quick_styles as $style_key => $style_label ) :
						?>
						<button type="button" class="button cg-quick-style" data-cg-quick-style="<?php echo esc_attr( $style_key ); ?>"><span class="cg-quick-style__name"><?php echo esc_html( $style_label ); ?></span></button>
					<?php endforeach; ?>
						<button type="button" id="cg-appearance-reset" class="button cg-quick-style cg-quick-style--reset"><span class="cg-quick-style__name"><?php esc_html_e( 'Theme default', 'calucon-third-party-embed-gate' ); ?></span></button>
					</div>
					<p class="description"><?php esc_html_e( 'A style fills in every control below; "Theme default" clears them all. Nothing changes on your site until you save.', 'calucon-third-party-embed-gate' ); ?></p>
				</fieldset>

				<details class="cg-section" open>
					<summary><h3><?php esc_html_e( 'Colours', 'calucon-third-party-embed-gate' ); ?></h3></summary>
				<table class="form-table" role="presentation">
					<?php
					$this->color_row( 'bg', __( 'Panel background', 'calucon-third-party-embed-gate' ), $appearance );
					$this->color_row( 'fg', __( 'Panel text', 'calucon-third-party-embed-gate' ), $appearance );
					$this->color_row( 'accent', __( 'Button background', 'calucon-third-party-embed-gate' ), $appearance );
					$this->color_row( 'accent_fg', __( 'Button text', 'calucon-third-party-embed-gate' ), $appearance );
					$this->color_row( 'link', __( 'Links', 'calucon-third-party-embed-gate' ), $appearance, __( 'The "Open on …" and privacy-policy links. Default: the panel text colour.', 'calucon-third-party-embed-gate' ) );
					$this->color_row( 'border_color', __( 'Border', 'calucon-third-party-embed-gate' ), $appearance, __( 'Used when a border is shown (see Shape). Default: the panel text colour.', 'calucon-third-party-embed-gate' ) );
					?>
				</table>
				</details>

				<details class="cg-section" open>
					<summary><h3><?php esc_html_e( 'Shape and layout', 'calucon-third-party-embed-gate' ); ?></h3></summary>
				<table class="form-table" role="presentation">
					<?php
					$this->select_row(
						'cg-preset',
						'preset',
						__( 'Panel style', 'calucon-third-party-embed-gate' ),
						array(
							'default' => __( 'Filled panel', 'calucon-third-party-embed-gate' ),
							'minimal' => __( 'Minimal — transparent with a border', 'calucon-third-party-embed-gate' ),
							'card'    => __( 'Card — border, rounded corners, shadow', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					$this->select_row(
						'cg-corners',
						'corners',
						__( 'Corners', 'calucon-third-party-embed-gate' ),
						array(
							''        => __( 'Slightly rounded (default)', 'calucon-third-party-embed-gate' ),
							'square'  => __( 'Square', 'calucon-third-party-embed-gate' ),
							'rounded' => __( 'Rounded', 'calucon-third-party-embed-gate' ),
							'pill'    => __( 'Rounded, with a pill-shaped button', 'calucon-third-party-embed-gate' ),
							'custom'  => __( 'Custom radius…', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					?>
					<tr id="cg-radius-row" <?php echo 'custom' === $appearance['corners'] ? '' : 'hidden'; ?>>
						<th scope="row"><label for="cg-radius"><?php esc_html_e( 'Corner radius (px)', 'calucon-third-party-embed-gate' ); ?></label></th>
						<td><input type="number" id="cg-radius" name="<?php echo esc_attr( Options::OPTION ); ?>[appearance][radius]" value="<?php echo esc_attr( (string) $appearance['radius'] ); ?>" min="0" max="48" step="1" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="cg-border-width"><?php esc_html_e( 'Border width (px)', 'calucon-third-party-embed-gate' ); ?></label></th>
						<td>
							<input type="number" id="cg-border-width" name="<?php echo esc_attr( Options::OPTION ); ?>[appearance][border_width]" value="<?php echo esc_attr( (string) $appearance['border_width'] ); ?>" min="0" max="10" step="1" class="small-text" placeholder="—">
							<p class="description"><?php esc_html_e( 'Empty: the panel style decides. 0 removes the border.', 'calucon-third-party-embed-gate' ); ?></p>
						</td>
					</tr>
					<?php
					$this->select_row(
						'cg-shadow',
						'shadow',
						__( 'Shadow', 'calucon-third-party-embed-gate' ),
						array(
							''       => __( 'Panel style decides', 'calucon-third-party-embed-gate' ),
							'none'   => __( 'None', 'calucon-third-party-embed-gate' ),
							'soft'   => __( 'Soft', 'calucon-third-party-embed-gate' ),
							'strong' => __( 'Strong', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					$this->select_row(
						'cg-density',
						'density',
						__( 'Spacing', 'calucon-third-party-embed-gate' ),
						array(
							''         => __( 'Default', 'calucon-third-party-embed-gate' ),
							'compact'  => __( 'Compact', 'calucon-third-party-embed-gate' ),
							'spacious' => __( 'Spacious', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					$this->select_row(
						'cg-align',
						'align',
						__( 'Alignment', 'calucon-third-party-embed-gate' ),
						array(
							''       => __( 'Left', 'calucon-third-party-embed-gate' ),
							'center' => __( 'Centred', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					$this->select_row(
						'cg-note-size',
						'note_size',
						__( 'Notice text size', 'calucon-third-party-embed-gate' ),
						array(
							''      => __( 'Default', 'calucon-third-party-embed-gate' ),
							'small' => __( 'Small', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					?>
				</table>
				</details>

				<details class="cg-section" <?php echo $this->section_touched( $appearance, array( 'button_style', 'button_size', 'button_width', 'hover', 'play_icon' ) ) ? 'open' : ''; ?>>
					<summary><h3><?php esc_html_e( 'Button', 'calucon-third-party-embed-gate' ); ?></h3></summary>
				<table class="form-table" role="presentation">
					<?php
					$this->select_row(
						'cg-button-style',
						'button_style',
						__( 'Style', 'calucon-third-party-embed-gate' ),
						array(
							''        => __( 'Filled', 'calucon-third-party-embed-gate' ),
							'outline' => __( 'Outline', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					$this->select_row(
						'cg-button-size',
						'button_size',
						__( 'Size', 'calucon-third-party-embed-gate' ),
						array(
							''      => __( 'Default', 'calucon-third-party-embed-gate' ),
							'small' => __( 'Small', 'calucon-third-party-embed-gate' ),
							'large' => __( 'Large', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					$this->select_row(
						'cg-button-width',
						'button_width',
						__( 'Width', 'calucon-third-party-embed-gate' ),
						array(
							''     => __( 'Fits its text', 'calucon-third-party-embed-gate' ),
							'full' => __( 'Full panel width', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					$this->select_row(
						'cg-hover',
						'hover',
						__( 'Hover effect', 'calucon-third-party-embed-gate' ),
						array(
							''       => __( 'Subtle', 'calucon-third-party-embed-gate' ),
							'none'   => __( 'None', 'calucon-third-party-embed-gate' ),
							'strong' => __( 'Strong', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Icon', 'calucon-third-party-embed-gate' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[appearance][play_icon]" value="0">
							<label><input type="checkbox" id="cg-play-icon" name="<?php echo esc_attr( Options::OPTION ); ?>[appearance][play_icon]" value="1" <?php checked( $appearance['play_icon'] ); ?>> <?php esc_html_e( 'Show an icon that matches what the embed is — play for videos, a pin for maps, a note for audio, and so on', 'calucon-third-party-embed-gate' ); ?></label>
						</td>
					</tr>
				</table>
				</details>

				<details class="cg-section" <?php echo $this->section_touched( $appearance, array( 'poster_panel', 'poster_dim' ) ) ? 'open' : ''; ?>>
					<summary><h3><?php esc_html_e( 'Poster image', 'calucon-third-party-embed-gate' ); ?></h3></summary>
				<p class="description"><?php esc_html_e( 'For embeds with a poster image set in the block editor. Tick "Preview with a poster image" to see these.', 'calucon-third-party-embed-gate' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->select_row(
						'cg-poster-panel',
						'poster_panel',
						__( 'Panel position', 'calucon-third-party-embed-gate' ),
						array(
							''       => __( 'Card, bottom-left', 'calucon-third-party-embed-gate' ),
							'center' => __( 'Card, centred', 'calucon-third-party-embed-gate' ),
							'bar'    => __( 'Bar along the bottom', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					$this->select_row(
						'cg-poster-dim',
						'poster_dim',
						__( 'Dim the poster', 'calucon-third-party-embed-gate' ),
						array(
							''       => __( 'No', 'calucon-third-party-embed-gate' ),
							'light'  => __( 'A little', 'calucon-third-party-embed-gate' ),
							'strong' => __( 'A lot, softened', 'calucon-third-party-embed-gate' ),
						),
						$appearance
					);
					?>
				</table>
				</details>

				<details class="cg-section" <?php echo $this->section_touched( $appearance, array( 'withdraw_style' ) ) ? 'open' : ''; ?>>
					<summary><h3><?php esc_html_e( 'Withdraw button', 'calucon-third-party-embed-gate' ); ?></h3></summary>
				<table class="form-table" role="presentation">
					<?php
					$this->select_row(
						'cg-withdraw-style',
						'withdraw_style',
						__( 'Style', 'calucon-third-party-embed-gate' ),
						array(
							''        => __( 'Filled — like the load button', 'calucon-third-party-embed-gate' ),
							'outline' => __( 'Outline', 'calucon-third-party-embed-gate' ),
							'link'    => __( 'Text link', 'calucon-third-party-embed-gate' ),
						),
						$appearance,
						__( 'The "Withdraw embed consents" block or shortcode. It uses the colours and corners above.', 'calucon-third-party-embed-gate' )
					);
					?>
				</table>
				</details>

				<details class="cg-section" <?php echo $this->section_touched( $appearance, array( 'dark', 'dark_bg', 'dark_fg', 'dark_accent', 'dark_accent_fg' ) ) ? 'open' : ''; ?>>
					<summary><h3><?php esc_html_e( 'Dark mode', 'calucon-third-party-embed-gate' ); ?></h3></summary>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Dark mode colours', 'calucon-third-party-embed-gate' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[appearance][dark]" value="0">
							<label><input type="checkbox" id="cg-dark-enabled" name="<?php echo esc_attr( Options::OPTION ); ?>[appearance][dark]" value="1" <?php checked( $appearance['dark'] ); ?>> <?php esc_html_e( 'Use different colours for visitors who prefer a dark colour scheme', 'calucon-third-party-embed-gate' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only the colours you set here change; the rest keep the values above. Tick "Preview on a dark page background" to check them.', 'calucon-third-party-embed-gate' ); ?></p>
						</td>
					</tr>
					<?php
					$dark_rows = $appearance['dark'] ? 'class="cg-dark-row"' : 'class="cg-dark-row" hidden';
					$this->color_row( 'dark_bg', __( 'Panel background (dark)', 'calucon-third-party-embed-gate' ), $appearance, '', $dark_rows );
					$this->color_row( 'dark_fg', __( 'Panel text (dark)', 'calucon-third-party-embed-gate' ), $appearance, '', $dark_rows );
					$this->color_row( 'dark_accent', __( 'Button background (dark)', 'calucon-third-party-embed-gate' ), $appearance, '', $dark_rows );
					$this->color_row( 'dark_accent_fg', __( 'Button text (dark)', 'calucon-third-party-embed-gate' ), $appearance, '', $dark_rows );
					?>
				</table>
				</details>
				</div>
				<div class="cg-appearance-preview">
				<?php $this->render_preview(); ?>
				</div>
				</div>
				</div>
<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- the close tag sits at column 0 so the method emits the moved block byte-identically, with no stray indentation.
	}

	/**
	 * The Consent memory tab (§6.2), including the §6.4 bridge section.
	 *
	 * @param array $options Sanitised option tree.
	 * @return void
	 */
	private function render_consent_tab( array $options ): void {
		?>
<div id="cg-tab-consent" class="cg-tab-panel" role="tabpanel" aria-labelledby="cg-tabbtn-consent">
				<h2><?php esc_html_e( 'Consent memory', 'calucon-third-party-embed-gate' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Off by default: consent applies to the one embed clicked and is stored nowhere. When enabled, the choice is stored in the visitor\'s browser only — after their first click, never before — and a withdrawal control becomes available via the [calucon_embed_gate_withdraw] shortcode for your privacy policy page.', 'calucon-third-party-embed-gate' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cg-memory"><?php esc_html_e( 'Remember consent', 'calucon-third-party-embed-gate' ); ?></label></th>
						<td>
							<select id="cg-memory" name="<?php echo esc_attr( Options::OPTION ); ?>[consent][memory]">
								<option value="off" <?php selected( $options['consent']['memory'], 'off' ); ?>><?php esc_html_e( 'No (default) — ask on every page view', 'calucon-third-party-embed-gate' ); ?></option>
								<option value="session" <?php selected( $options['consent']['memory'], 'session' ); ?>><?php esc_html_e( 'For this browser session', 'calucon-third-party-embed-gate' ); ?></option>
								<option value="persistent" <?php selected( $options['consent']['memory'], 'persistent' ); ?>><?php esc_html_e( 'Persistently, with an expiry', 'calucon-third-party-embed-gate' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cg-scope"><?php esc_html_e( 'Scope', 'calucon-third-party-embed-gate' ); ?></label></th>
						<td>
							<select id="cg-scope" name="<?php echo esc_attr( Options::OPTION ); ?>[consent][scope]">
								<option value="embed" <?php selected( $options['consent']['scope'], 'embed' ); ?>><?php esc_html_e( 'This embed only', 'calucon-third-party-embed-gate' ); ?></option>
								<option value="provider" <?php selected( $options['consent']['scope'], 'provider' ); ?>><?php esc_html_e( 'All embeds of the same provider', 'calucon-third-party-embed-gate' ); ?></option>
								<option value="all" <?php selected( $options['consent']['scope'], 'all' ); ?>><?php esc_html_e( 'All embeds', 'calucon-third-party-embed-gate' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cg-duration"><?php esc_html_e( 'Persistent lifetime (days)', 'calucon-third-party-embed-gate' ); ?></label></th>
						<td><input type="number" id="cg-duration" min="1" max="730" name="<?php echo esc_attr( Options::OPTION ); ?>[consent][duration_days]" value="<?php echo esc_attr( (string) $options['consent']['duration_days'] ); ?>"></td>
					</tr>
				</table>

				<?php $this->render_cmp_bridge( $options ); ?>

				</div>
<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- the close tag sits at column 0 so the method emits the moved block byte-identically, with no stray indentation.
	}

	/**
	 * Live preview of the placeholder panel, driven by admin-appearance.js:
	 * the sample is real renderer output styled by the real front-end
	 * stylesheet, so what the owner sees here is what visitors get. Inert by
	 * design — gate.js is not loaded in the admin and the script suppresses
	 * link navigation inside the stage.
	 *
	 * @return void
	 */
	private function render_preview(): void {
		if ( null === $this->preview_source ) {
			return;
		}
		$sample = (string) call_user_func( $this->preview_source );
		if ( '' === $sample ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Preview', 'calucon-third-party-embed-gate' ); ?></h3>
		<div id="cg-preview-stage" class="cg-preview-stage">
			<?php echo $sample; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- placeholder HTML escaped by the renderer, same output as the front end. ?>
			<p class="cg-preview-withdraw-wrap"><button type="button" class="cg-withdraw" id="cg-preview-withdraw"><?php esc_html_e( 'Withdraw embed consents', 'calucon-third-party-embed-gate' ); ?></button></p>
		</div>
		<div class="cg-preview-toggles">
			<label>
				<input type="checkbox" id="cg-preview-dark">
				<?php esc_html_e( 'Preview on a dark page background', 'calucon-third-party-embed-gate' ); ?>
			</label>
			<label>
				<input type="checkbox" id="cg-preview-poster">
				<?php esc_html_e( 'Preview with a poster image', 'calucon-third-party-embed-gate' ); ?>
			</label>
			<label>
				<input type="checkbox" id="cg-preview-narrow">
				<?php esc_html_e( 'Preview at phone width', 'calucon-third-party-embed-gate' ); ?>
			</label>
		</div>
		<p class="description"><?php esc_html_e( 'Readability (WCAG 4.5:1):', 'calucon-third-party-embed-gate' ); ?></p>
		<div id="cg-contrast-report" class="cg-contrast-report" role="status" aria-live="polite"></div>
		<?php
	}

	/**
	 * The §6.4 consent-platform bridge settings, inside the Consent tab.
	 *
	 * The bridge is offered only for platforms on the tested list; the list
	 * itself is printed so the promise is explicit — an untested platform
	 * is simply not bridged (fail closed), never half-bridged.
	 *
	 * @param array $options Sanitised option tree.
	 * @return void
	 */
	private function render_cmp_bridge( array $options ): void {
		$detected = Detector::detected();
		$labels   = array();
		foreach ( Detector::bridgeable() as $row ) {
			$labels[] = $row['label'];
		}
		?>
		<h2><?php esc_html_e( 'Consent platform bridge', 'calucon-third-party-embed-gate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'If a consent platform (cookie banner) runs on this site, Calucon Third-Party Embed Gate can honour its decision: once the platform reports consent for the embeds\' category, gated embeds load without a second click — and a withdrawal there re-gates them. The bridge only reads the platform\'s state, stores nothing itself, and works only with platforms it was tested against; with any other platform, and whenever the platform gives no answer, gating stands unchanged (fail closed).', 'calucon-third-party-embed-gate' ); ?>
		</p>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: comma-separated list of consent platforms. */
					__( 'Tested and interoperable: %s.', 'calucon-third-party-embed-gate' ),
					implode( ', ', $labels )
				)
			);
			?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Bridge', 'calucon-third-party-embed-gate' ); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[cmp][bridge]" value="0">
					<label for="cg-cmp-bridge">
						<input type="checkbox" id="cg-cmp-bridge" name="<?php echo esc_attr( Options::OPTION ); ?>[cmp][bridge]" value="1" <?php checked( $options['cmp']['bridge'] ); ?>>
						<?php esc_html_e( 'Load embeds automatically when the detected consent platform reports consent for them', 'calucon-third-party-embed-gate' ); ?>
					</label>
					<p class="description">
						<?php
						if ( array() === $detected ) {
							esc_html_e( 'No tested consent platform is currently detected. The setting can stay enabled; it takes effect as soon as one is installed.', 'calucon-third-party-embed-gate' );
						} else {
							$names = array();
							foreach ( $detected as $cmp ) {
								$names[] = $cmp['label'];
							}
							echo esc_html(
								sprintf(
									/* translators: %s: comma-separated list of detected consent platforms. */
									__( 'Detected now: %s.', 'calucon-third-party-embed-gate' ),
									implode( ', ', $names )
								)
							);
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cg-cmp-borlabs-group"><?php esc_html_e( 'Borlabs Cookie service group', 'calucon-third-party-embed-gate' ); ?></label></th>
				<td>
					<input type="text" id="cg-cmp-borlabs-group" name="<?php echo esc_attr( Options::OPTION ); ?>[cmp][borlabs_group]" value="<?php echo esc_attr( $options['cmp']['borlabs_group'] ); ?>" class="regular-text" pattern="[a-z0-9_-]{1,64}">
					<p class="description"><?php esc_html_e( 'Only used with Borlabs Cookie, whose consent groups are defined per site: the ID of the group that covers embedded content. The default installation calls it "external-media".', 'calucon-third-party-embed-gate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'IAB TCF (experimental)', 'calucon-third-party-embed-gate' ); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr( Options::OPTION ); ?>[cmp][tcf]" value="0">
					<label for="cg-cmp-tcf">
						<input type="checkbox" id="cg-cmp-tcf" name="<?php echo esc_attr( Options::OPTION ); ?>[cmp][tcf]" value="1" <?php checked( $options['cmp']['tcf'] ); ?>>
						<?php esc_html_e( 'Also honour an IAB TCF v2.2 signal (sites running an ad-industry consent framework)', 'calucon-third-party-embed-gate' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Grants require both the storage purpose and the provider\'s registered vendor consent; providers without a Global Vendor List entry always keep the click. Leave this off unless your site serves programmatic advertising.', 'calucon-third-party-embed-gate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Plain-language names for the CSP directives the snippet uses.
	 *
	 * @return array<string,string> directive => label.
	 */
	private function csp_directive_labels(): array {
		return array(
			'frame-src'  => __( 'frame-src (embedded players, maps and other iframes)', 'calucon-third-party-embed-gate' ),
			'script-src' => __( 'script-src (provider scripts, e.g. for social-media posts)', 'calucon-third-party-embed-gate' ),
		);
	}

	/**
	 * Content-Security-Policy helper (PLAN.md §9.13), on the Status & tools
	 * tab. Collapsed by default: most sites send no policy and never need
	 * this. Leads with "do I need this?", offers a browser-side self-check
	 * (admin-csp.js — same-origin, on click; the server requests nothing),
	 * then the snippet with a copy button, merge instructions and a table
	 * saying which provider needs which host.
	 *
	 * @return void
	 */
	private function render_csp(): void {
		$providers = $this->providers();
		?>
		<details class="cg-section cg-csp" id="cg-csp">
			<summary><h3><?php esc_html_e( 'Content-Security-Policy (advanced)', 'calucon-third-party-embed-gate' ); ?></h3></summary>

			<p><?php esc_html_e( 'A Content-Security-Policy (CSP) is a security setting some sites send to browsers. It lists which other websites a page is allowed to load anything from — and blocks the rest. Most WordPress sites do not send one. If you never set one up in a security plugin, your hosting panel or the web server, you can skip this section.', 'calucon-third-party-embed-gate' ); ?></p>
			<p><?php esc_html_e( 'Why it matters here: when a policy does not list a provider, that provider\'s embed stays empty after the visitor clicks Load, and the browser console reports “Refused to frame …”. Listing a host only grants permission; it does not load anything — nothing is contacted before the click either way.', 'calucon-third-party-embed-gate' ); ?></p>

			<p id="cg-csp-check-wrap" hidden>
				<button type="button" class="button" id="cg-csp-check"><?php esc_html_e( 'Check my site for a policy', 'calucon-third-party-embed-gate' ); ?></button>
				<span class="description"><?php esc_html_e( 'Loads your home page once, in this browser, and reads whether it sends a policy and what it allows. Nothing leaves your site.', 'calucon-third-party-embed-gate' ); ?></span>
			</p>
			<div id="cg-csp-result" class="cg-csp-result" role="status" aria-live="polite" hidden></div>

			<h4><?php esc_html_e( 'Lines to add', 'calucon-third-party-embed-gate' ); ?></h4>
			<textarea readonly rows="6" id="cg-csp-snippet" class="large-text code" aria-label="<?php echo esc_attr( __( 'Content-Security-Policy snippet', 'calucon-third-party-embed-gate' ) ); ?>"><?php echo esc_textarea( Csp::snippet( $providers ) ); ?></textarea>
			<p id="cg-csp-copy-wrap" hidden>
				<button type="button" class="button" id="cg-csp-copy"><?php esc_html_e( 'Copy', 'calucon-third-party-embed-gate' ); ?></button>
				<span id="cg-csp-copied" role="status" aria-live="polite" class="description"></span>
			</p>
			<p class="description"><?php esc_html_e( 'Add them wherever your policy is defined — a security plugin, your hosting panel or the web server configuration. Merge, do not replace: if the policy already has a frame-src line, add these hosts to that line instead of adding a second one. If it has neither frame-src nor script-src, the browser falls back to default-src — add the hosts there.', 'calucon-third-party-embed-gate' ); ?></p>

			<details class="cg-csp__providers">
				<summary><?php esc_html_e( 'Show which provider needs which host', 'calucon-third-party-embed-gate' ); ?></summary>
				<p class="description"><?php esc_html_e( 'Only enabled providers are listed; disable a provider under Providers and its hosts disappear from the lines above. A host can differ from the embed address when the plugin loads a privacy-preserving variant (YouTube loads from youtube-nocookie.com, for example).', 'calucon-third-party-embed-gate' ); ?></p>
				<table class="widefat striped cg-csp-table" style="max-width: 60rem;">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Provider', 'calucon-third-party-embed-gate' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Embeds load from (frame-src)', 'calucon-third-party-embed-gate' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Scripts load from (script-src)', 'calucon-third-party-embed-gate' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( Csp::by_provider( $providers ) as $label => $hosts ) : ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo $hosts['frame-src'] ? '<code>' . implode( '</code><br><code>', array_map( 'esc_html', $hosts['frame-src'] ) ) : '—'; ?></td>
							<td><?php echo $hosts['script-src'] ? '<code>' . implode( '</code><br><code>', array_map( 'esc_html', $hosts['script-src'] ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		</details>
		<?php
	}

	/**
	 * Compatibility (§7.1): the detected CMP, cache plugin and page builder,
	 * and what the plugin decided to do about each.
	 *
	 * @param array $options Sanitised option tree, as render() already read it.
	 * @return void
	 */
	private function render_compatibility( array $options ): void {
		$found    = Compatibility::detect();
		$messages = array(
			'cache'   => __( 'Detected. Its page cache is flushed automatically when Calucon Third-Party Embed Gate settings change and when the plugin is activated, deactivated or updated. If pages still look stale, clear it once by hand.', 'calucon-third-party-embed-gate' ),
			'builder' => $options['detection']['output_buffer']
				? __( 'Detected. Whole-page gating is enabled, so this builder\'s embeds are covered.', 'calucon-third-party-embed-gate' )
				: __( 'Detected. Page builders render outside the content filters — if its embeds are not being gated, enable "Gate the whole page output" under Detection.', 'calucon-third-party-embed-gate' ),
		);
		// CMP rows (§6.4) depend on the row itself: tested platforms can be
		// bridged; anything else keeps the fail-closed default.
		$cmp_messages = array(
			'active'    => __( 'Detected, bridge active: when this platform reports consent for the embeds\' category, gated embeds load without a second click, and a withdrawal re-gates them. If the platform does not answer, gating stands (fail closed). Prefer its own blocker for a provider? Disable that provider under Providers and Calucon Third-Party Embed Gate steps aside for it.', 'calucon-third-party-embed-gate' ),
			'available' => __( 'Detected and tested for interoperation. Gating currently ignores its choices — the fail-closed default. Enable the consent platform bridge under Consent to load embeds automatically once this platform reports consent for them.', 'calucon-third-party-embed-gate' ),
			'untested'  => __( 'Detected. Calucon Third-Party Embed Gate has no tested bridge to this consent platform and keeps gating regardless of its choices — the fail-closed default. Nothing loads before the embed-level click.', 'calucon-third-party-embed-gate' ),
		);
		?>
		<h2 id="cg-compatibility"><?php esc_html_e( 'Compatibility', 'calucon-third-party-embed-gate' ); ?></h2>
		<?php if ( array() === $found ) : ?>
			<p><?php esc_html_e( 'No cache plugin, consent platform or page builder detected.', 'calucon-third-party-embed-gate' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width: 60rem;">
				<thead><tr><th scope="col"><?php esc_html_e( 'Detected', 'calucon-third-party-embed-gate' ); ?></th><th scope="col"><?php esc_html_e( 'What Calucon Third-Party Embed Gate does', 'calucon-third-party-embed-gate' ); ?></th></tr></thead>
				<tbody>
				<?php
				foreach ( $found as $row ) :
					if ( 'cmp' === $row['kind'] ) {
						if ( empty( $row['tested'] ) ) {
							$message = $cmp_messages['untested'];
						} else {
							$message = $options['cmp']['bridge'] ? $cmp_messages['active'] : $cmp_messages['available'];
						}
					} else {
						$message = $messages[ $row['kind'] ];
					}
					?>
					<tr><td><?php echo esc_html( $row['name'] ); ?></td><td><?php echo esc_html( $message ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php
		$theme_findings = Compatibility::theme_asset_findings();
		if ( array() !== $theme_findings ) :
			?>
			<h3><?php esc_html_e( 'Third-party assets in your theme', 'calucon-third-party-embed-gate' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Your theme references these third-party asset hosts (found by reading its files — nothing was fetched). Fonts and CDN assets load on every page view without consent, outside what an embed gate can cover. Consider serving them locally; your theme or a localisation plugin can usually do this.', 'calucon-third-party-embed-gate' ); ?></p>
			<ul>
				<?php foreach ( $theme_findings as $finding ) : ?>
					<li><code><?php echo esc_html( $finding['file'] ); ?></code> — <?php echo esc_html( implode( ', ', $finding['hosts'] ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Status (§7.1): a read-only scan of recent content — which third-party
	 * hosts appear and whether each is currently gated. Runs only on demand:
	 * rendering 50 posts through the content filters is not free.
	 *
	 * @return void
	 */
	private function render_status(): void {
		if ( null === $this->scanner_source ) {
			return;
		}
		?>
		<h2 id="cg-status"><?php esc_html_e( 'Status', 'calucon-third-party-embed-gate' ); ?></h2>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only scan, no state changes; capability-gated by the page.
		if ( ! isset( $_GET['calucon-embed-gate-scan'] ) ) {
			?>
			<p>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'calucon-embed-gate-scan', '1' ) . '#cg-status' ); ?>"><?php esc_html_e( 'Scan recent content', 'calucon-third-party-embed-gate' ); ?></a>
				<span class="description"><?php esc_html_e( 'Renders your latest posts and pages in memory and reports every embed found and whether it is gated. Read-only; no outbound requests.', 'calucon-third-party-embed-gate' ); ?></span>
			</p>
			<?php
			return;
		}

		$scanner = call_user_func( $this->scanner_source );
		$posts   = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => 50,
				'suppress_filters' => false,
			)
		);

		$status_labels = array(
			\CaluconEmbedGate\Support\ContentScan::GATED => __( 'Gated', 'calucon-third-party-embed-gate' ),
			\CaluconEmbedGate\Support\ContentScan::OWN_HOST => __( 'Own host — not gated', 'calucon-third-party-embed-gate' ),
			\CaluconEmbedGate\Support\ContentScan::NO_USABLE_URL => __( 'No usable URL — passes through', 'calucon-third-party-embed-gate' ),
			\CaluconEmbedGate\Support\ContentScan::RULE_DISABLED => __( 'NOT gated — its detection rule is disabled', 'calucon-third-party-embed-gate' ),
			\CaluconEmbedGate\Support\ContentScan::PROVIDER_DISABLED => __( 'NOT gated — provider disabled in the table above', 'calucon-third-party-embed-gate' ),
		);

		$scanned = array();
		foreach ( $posts as $post ) {
			$rendered  = (string) apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately rendering through core's own content pipeline so embeds appear as they would on the front end.
			$scanned[] = array(
				'source' => get_the_title( $post ),
				'rows'   => $scanner->scan( $rendered ),
			);
		}
		$rows = \CaluconEmbedGate\Support\ContentScan::aggregate( $scanned );
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %d: number of posts scanned. */
				esc_html__( 'Scanned the %d most recent published posts and pages. Widgets, template parts and builder-rendered layouts are not part of this scan.', 'calucon-third-party-embed-gate' ),
				count( $posts )
			);
			?>
		</p>
		<?php if ( array() === $rows ) : ?>
			<p><?php esc_html_e( 'No third-party embeds found in the scanned content.', 'calucon-third-party-embed-gate' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width: 60rem;">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Host', 'calucon-third-party-embed-gate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'calucon-third-party-embed-gate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Count', 'calucon-third-party-embed-gate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'calucon-third-party-embed-gate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'First seen in', 'calucon-third-party-embed-gate' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( '' !== $row['host'] ? $row['host'] : '—' ); ?></td>
						<td><code><?php echo esc_html( $row['tag'] ); ?></code><?php echo '' !== $row['label'] ? ' ' . esc_html( '(' . $row['label'] . ')' ) : ''; ?></td>
						<td><?php echo esc_html( (string) $row['count'] ); ?></td>
						<td><?php echo esc_html( isset( $status_labels[ $row['status'] ] ) ? $status_labels[ $row['status'] ] : $row['status'] ); ?></td>
						<td><?php echo esc_html( $row['first_seen'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
	}
}
