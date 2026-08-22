<?php
/**
 * Wiring; no logic (PLAN.md §2.2).
 *
 * WordPress globals may appear here and in Integration/, Admin/, Cli/ and
 * Support/CacheFlush.php. Detection/, Providers/ and Rendering/ receive
 * plain callables bridging to WordPress filters and i18n.
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CaluconEmbedGate\Admin\BlockEditor;
use CaluconEmbedGate\Admin\SettingsPage;
use CaluconEmbedGate\Cli\Commands as CliCommands;
use CaluconEmbedGate\Cmp\BridgeConfig;
use CaluconEmbedGate\Cmp\Detector;
use CaluconEmbedGate\Detection\EmbedObjectRule;
use CaluconEmbedGate\Detection\EmbedStripper;
use CaluconEmbedGate\Detection\HostMatcher;
use CaluconEmbedGate\Detection\HtmlScanner;
use CaluconEmbedGate\Detection\IframeRule;
use CaluconEmbedGate\Detection\ImageRule;
use CaluconEmbedGate\Detection\ScriptRule;
use CaluconEmbedGate\Integration\Assets;
use CaluconEmbedGate\Integration\Comments;
use CaluconEmbedGate\Integration\Descriptions;
use CaluconEmbedGate\Integration\Excerpt;
use CaluconEmbedGate\Integration\OutputBuffer;
use CaluconEmbedGate\Integration\RenderBlock;
use CaluconEmbedGate\Integration\ResourceHints as ResourceHintsIntegration;
use CaluconEmbedGate\Integration\TheContent;
use CaluconEmbedGate\Integration\Widgets;
use CaluconEmbedGate\Integration\WithdrawShortcode;
use CaluconEmbedGate\Providers\Builtin\Descriptors;
use CaluconEmbedGate\Providers\CustomProviders;
use CaluconEmbedGate\Providers\Registry;
use CaluconEmbedGate\Rendering\PlaceholderRenderer;
use CaluconEmbedGate\Rendering\TemplateLoader;
use CaluconEmbedGate\Support\CacheFlush;
use CaluconEmbedGate\Support\ContentScan;
use CaluconEmbedGate\Support\Options;
use CaluconEmbedGate\Support\Pipeline;
use CaluconEmbedGate\Support\ResourceHints;

/**
 * Builds the pipeline and registers the integrations.
 */
final class Plugin {

	/** @var Plugin|null */
	private static ?Plugin $instance = null;

	/** @var Pipeline|null Built lazily; see pipeline(). */
	private ?Pipeline $pipeline = null;

	/** @var array Sanitised option tree. */
	private array $options;

	/** @var Assets Front-end asset registration/enqueue. */
	private Assets $assets;

	/** @var array[]|null Filtered provider descriptors; resolved lazily. */
	private ?array $providers_cache = null;

	/** @var array<string,string>|null Lazily loaded $t() translation map. */
	private ?array $strings_map = null;

	/** @var bool True while render_ungated() runs; see should_bail(). */
	private bool $gating_suspended = false;

	/**
	 * Bootstraps the plugin once, on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	private function __construct() {
		$this->options = Options::sanitize( get_option( Options::OPTION, Options::defaults() ) );

		// No load_plugin_textdomain() call: WordPress ≥ 4.6 loads the
		// wordpress.org language packs for the plugin's text domain
		// automatically, and the plugin ships no .mo files of its own.

		$this->assets = new Assets(
			$this->options,
			function (): ?array {
				return $this->cmp_bridge_config();
			},
			function (): bool {
				return $this->should_bail();
			},
			function (): array {
				$kinds = array();
				foreach ( $this->providers() as $descriptor ) {
					if ( ! empty( $descriptor['id'] ) && is_string( $descriptor['id'] ) ) {
						$kinds[ $descriptor['id'] ] = isset( $descriptor['kind'] ) && is_string( $descriptor['kind'] ) ? $descriptor['kind'] : '';
					}
				}
				return $kinds;
			}
		);
		$this->assets->register();

		( new RenderBlock( $this ) )->register();
		( new TheContent( $this ) )->register();
		( new Widgets( $this ) )->register();
		( new Comments( $this ) )->register();
		( new Descriptions( $this ) )->register();
		( new Excerpt( $this ) )->register();
		$withdraw = new WithdrawShortcode(
			function (): void {
				// The withdrawal control's intended home is a privacy-policy
				// page with no embeds — without this enqueue the button is a
				// dead element there (invariant 2's spirit).
				$this->assets->enqueue_assets();
			},
			(string) $this->options['appearance']['withdraw_style']
		);
		$withdraw->register();
		( new BlockEditor( $withdraw ) )->register();
		( new SettingsPage(
			function (): array {
				return $this->providers();
			},
			function (): ContentScan {
				return $this->content_scanner();
			},
			function (): string {
				return $this->preview_placeholder_html();
			}
		) )->register();
		( new ResourceHintsIntegration(
			function (): ResourceHints {
				return $this->pipeline()->hint_scrubber;
			}
		) )->register();

		// Read-only inspection for shells, CI and AI agents (docs/customizing.md):
		// the Status screen's answers without wp-admin.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli = new CliCommands(
				function (): array {
					return $this->providers();
				},
				function (): ContentScan {
					return $this->content_scanner();
				},
				function ( string $content ): string {
					return $this->render_ungated( $content );
				}
			);
			\WP_CLI::add_command( 'calucon-embed-gate', $cli );
		}

		if ( $this->options['detection']['output_buffer'] ) {
			( new OutputBuffer( $this ) )->register();
		}

		// Cached pages keep serving pre-change markup after a settings save
		// (§9.12) — flush the caches we can reach.
		add_action(
			'update_option_' . Options::OPTION,
			static function (): void {
				CacheFlush::flush_all();
			}
		);

		// Deactivation must restore original behaviour immediately (§9.10);
		// a page cache still serving placeholders would reference assets
		// that no longer load. Flush what we can reach.
		register_deactivation_hook(
			CALUCON_EMBED_GATE_FILE,
			static function (): void {
				CacheFlush::flush_all();
			}
		);
		// Activation: cached pages hold ungated embeds until the cache
		// learns otherwise. Updates: cached pages hold the previous version's
		// placeholder markup (this hook runs in the code that was active
		// during the update, so it covers every update after the one that
		// introduced it).
		register_activation_hook(
			CALUCON_EMBED_GATE_FILE,
			static function (): void {
				CacheFlush::flush_all();
			}
		);
		add_action(
			'upgrader_process_complete',
			static function ( $upgrader, $extra ): void {
				if ( ! is_array( $extra ) || 'update' !== ( $extra['action'] ?? '' ) || 'plugin' !== ( $extra['type'] ?? '' ) ) {
					return;
				}
				if ( in_array( plugin_basename( CALUCON_EMBED_GATE_FILE ), (array) ( $extra['plugins'] ?? array() ), true ) ) {
					CacheFlush::flush_all();
				}
			},
			10,
			2
		);
	}

	/**
	 * The filtered provider set — the ONE set every consumer shares.
	 *
	 * Resolved lazily, not at plugins_loaded: the documented way to add a
	 * provider is "a ten-line filter in functions.php", and a theme's
	 * functions.php loads AFTER plugins_loaded. Resolving here (first use is
	 * during rendering or in the admin) means theme-registered providers
	 * reach the registry, the settings table, the CSP snippet and the
	 * resource-hint scrubber alike — previously the last three saw only the
	 * unfiltered builtins.
	 *
	 * @return array[]
	 */
	private function providers(): array {
		if ( null === $this->providers_cache ) {
			$translate = $this->translator();
			// 1. Built-ins, then code-registered ones via the filter.
			$registered = (array) apply_filters( 'calucon_embed_gate_providers', Descriptors::all( $translate ) );
			// 2. Owner-defined rows AFTER everything registered in code, with
			//    every host a registered provider handles stripped: a custom
			//    row can name an unknown host, never take a known one away
			//    from the provider that knows its privacy-preserving load
			//    target. Nothing here can stop a gate — an unknown host is
			//    gated generically with or without a row.
			$rows = isset( $this->options['custom_providers'] ) && is_array( $this->options['custom_providers'] )
				? $this->options['custom_providers'] : array();
			if ( array() !== $rows ) {
				$registered = array_merge(
					$registered,
					CustomProviders::descriptors( $rows, $translate, CustomProviders::reserved_hosts( $registered ) )
				);
			}
			// 3. The owner's per-provider settings last, so they apply to
			//    code-registered providers too (the settings table lists them).
			$this->providers_cache = Options::apply_provider_overrides( $registered, $this->options );
		}
		return $this->providers_cache;
	}

	/**
	 * Bridges the WordPress-free layers' English strings to the site
	 * language. Translations resolve through the generated
	 * languages/strings.php map, whose entries are literal __() calls keyed
	 * by msgid — so no gettext function in the plugin ever receives a
	 * variable argument, and the wp.org translation parser sees every
	 * string.
	 *
	 * @return callable
	 */
	private function translator(): callable {
		return function ( string $text ): string {
			if ( null === $this->strings_map ) {
				$map               = include CALUCON_EMBED_GATE_DIR . '/languages/strings.php';
				$this->strings_map = is_array( $map ) ? $map : array();
			}
			return isset( $this->strings_map[ $text ] ) ? (string) $this->strings_map[ $text ] : $text;
		};
	}

	/**
	 * Build the detection/render pipeline once, on first use. Lazy for the
	 * same reason as providers(), and because own_hosts() reads home_url()
	 * — at plugins_loaded, domain-mapping and multilingual plugins have not
	 * registered their host filters yet (§9.11).
	 *
	 * @return Pipeline
	 */
	private function pipeline(): Pipeline {
		if ( null !== $this->pipeline ) {
			return $this->pipeline;
		}

		$translate = $this->translator();

		$always_gate = $this->options['detection']['always_gate'];

		$hosts = new HostMatcher(
			$this->own_hosts(),
			(bool) apply_filters( 'calucon_embed_gate_www_equivalence', $this->options['detection']['www_equivalence'] ),
			static function ( bool $own, string $host ) use ( $always_gate ): bool {
				// The always-gate list wins over every own-host rule: a
				// subdomain of the site's own domain that serves trackers is
				// exactly what it exists for (§7.1).
				if ( HostMatcher::host_matches_list( $host, $always_gate ) ) {
					return false;
				}
				return (bool) apply_filters( 'calucon_embed_gate_is_own_host', $own, $host );
			}
		);

		$providers = $this->providers();

		$registry = new Registry(
			$providers,
			$translate,
			static function ( array $provider, string $url, string $host ): array {
				return (array) apply_filters( 'calucon_embed_gate_provider_for_url', $provider, $url, $host );
			}
		);

		$renderer = new PlaceholderRenderer(
			$translate,
			static function ( string $html, array $provider, array $ctx ): string {
				return (string) apply_filters( 'calucon_embed_gate_placeholder_html', $html, $provider, $ctx );
			},
			static function ( array $payload, array $provider ): array {
				return (array) apply_filters( 'calucon_embed_gate_payload', $payload, $provider );
			},
			new TemplateLoader(
				static function ( string $relative ): string {
					return function_exists( 'locate_template' ) ? (string) locate_template( $relative ) : '';
				}
			),
			array(
				'before'   => static function ( array $provider, array $ctx ): void {
					do_action( 'calucon_embed_gate_before_render', $provider, $ctx );
				},
				'note'     => static function ( string $note, array $provider, array $ctx ): string {
					return (string) apply_filters( 'calucon_embed_gate_note_text', $note, $provider, $ctx );
				},
				'action'   => static function ( string $action, array $provider, array $ctx ): string {
					return (string) apply_filters( 'calucon_embed_gate_action_text', $action, $provider, $ctx );
				},
				'fallback' => static function ( string $url, array $provider, array $ctx ): string {
					return (string) apply_filters( 'calucon_embed_gate_fallback_url', $url, $provider, $ctx );
				},
			),
			! empty( $this->options['display']['privacy_link'] )
		);

		$scanner     = new HtmlScanner();
		$should_gate = static function ( bool $gate, string $url, array $ctx ): bool {
			return (bool) apply_filters( 'calucon_embed_gate_should_gate', $gate, $url, $ctx );
		};
		$on_gated    = function ( array $provider, array $ctx ): void {
			$this->assets->enqueue_assets();
			do_action( 'calucon_embed_gate_embed_gated', $provider, $ctx );
		};

		$this->pipeline = new Pipeline(
			new IframeRule( $scanner, $hosts, $registry, $renderer, $should_gate, $on_gated ),
			new EmbedObjectRule( $scanner, $hosts, $registry, $renderer, $should_gate, $on_gated ),
			new ScriptRule( $scanner, $hosts, $registry, $renderer, $should_gate, $on_gated ),
			new ImageRule( $scanner, $hosts, $registry, $renderer, $should_gate, $on_gated ),
			$registry,
			$hosts,
			new EmbedStripper( $scanner, $hosts, $registry, $translate ),
			$scanner,
			new ResourceHints( $this->provider_hosts( $providers ), $hosts ),
			$renderer
		);
		return $this->pipeline;
	}

	/**
	 * A sample placeholder for the settings screen's live preview (§7.1).
	 *
	 * Rendered through the real pipeline — theme template overrides, text
	 * filters and all — so the preview cannot drift from what visitors see.
	 * The markup is inert data: gate.js is not enqueued in the admin, and
	 * admin-appearance.js suppresses the panel's link navigation.
	 *
	 * @return string
	 */
	public function preview_placeholder_html(): string {
		$pipeline = $this->pipeline();
		$url      = 'https://www.youtube.com/embed/preview';
		$provider = $pipeline->registry->resolve_for_url( $url, 'www.youtube.com' );

		return $pipeline->renderer->render(
			$provider,
			$url,
			array(
				'width'  => '480',
				'height' => '270',
				'title'  => __( 'Example embed', 'calucon-third-party-embed-gate' ),
			),
			array( 'integration' => 'admin-preview' )
		);
	}

	/**
	 * Render content through the_content with this plugin's gating
	 * suspended: what the front end WOULD serve without Calucon Third-Party Embed Gate.
	 *
	 * The scanner must see original markup to classify it — in wp-admin
	 * should_bail() already guarantees that, but WP-CLI is neither admin
	 * nor front end, and rendering there would gate the iframes into
	 * placeholders the scanner cannot see.
	 *
	 * @param string $content Raw post content.
	 * @return string Rendered HTML, ungated.
	 */
	public function render_ungated( string $content ): string {
		$this->gating_suspended = true;
		try {
			return (string) apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately rendering through core's own content pipeline so embeds appear as they would on the front end.
		} finally {
			$this->gating_suspended = false;
		}
	}

	/**
	 * The read-only content scanner behind the Status screen (§7.1).
	 *
	 * @return ContentScan
	 */
	public function content_scanner(): ContentScan {
		$pipeline = $this->pipeline();
		return new ContentScan(
			$pipeline->scanner,
			$pipeline->host_matcher,
			$pipeline->registry,
			array(
				'iframes' => $this->options['detection']['iframes'],
				'scripts' => $this->options['detection']['scripts'],
				'images'  => $this->options['detection']['images'],
			)
		);
	}

	/**
	 * Cheap pre-parse probe shared by every integration (PLAN.md §9.16):
	 * whether a fragment can contain anything gateable at all. Must name
	 * every tag a detection rule handles — a probe that misses a tag makes
	 * the integration skip the rule silently. '<img' joins only when the
	 * opt-in image rule is on: it is by far the most common tag, and probing
	 * it unconditionally would defeat the fast path everywhere.
	 *
	 * @param string $html Content.
	 * @return bool
	 */
	public function has_gateable_markup( string $html ): bool {
		// The single hottest line in the plugin: this runs for every
		// the_content/render_block/widget/comment fragment on every page
		// view, and most fragments contain no gateable tag. One combined
		// alternation scans the string once instead of once per tag name
		// (measured ~4x on a 60 KB text-only post).
		$pattern = $this->options['detection']['images']
			? '/<(?:iframe|script|embed|object|img)/i'
			: '/<(?:iframe|script|embed|object)/i';
		return 1 === preg_match( $pattern, $html );
	}

	/**
	 * Run the enabled detection rules over a fragment.
	 *
	 * @param string $html Content.
	 * @param array  $ctx  Integration context.
	 * @return string
	 */
	public function gate( string $html, array $ctx ): string {
		$pipeline = $this->pipeline();
		if ( $this->options['detection']['iframes'] ) {
			$html = $pipeline->iframe_rule->apply( $html, $ctx );
			// <embed>/<object> are frame-shaped embeds under the same toggle:
			// Flash-era markup requests third-party content on load too.
			$html = $pipeline->embed_object_rule->apply( $html, $ctx );
		}
		if ( $this->options['detection']['scripts'] ) {
			$html = $pipeline->script_rule->apply( $html, $ctx );
		}
		if ( $this->options['detection']['images'] ) {
			$html = $pipeline->image_rule->apply( $html, $ctx );
		}
		return $html;
	}

	/**
	 * Resolve a media-library attachment to a poster URL for the placeholder
	 * (PLAN.md §5.4, owner-supplied variant — the auto-fetch variant was
	 * rejected: no outbound requests, and a cached provider thumbnail goes
	 * stale silently).
	 *
	 * Fail closed: only a URL that classifies as the site's own host is
	 * returned. An offloading plugin that rewrites attachment URLs to a CDN
	 * the site has not declared as an own host would otherwise put a
	 * third-party request inside the placeholder — the exact request the
	 * placeholder exists to prevent (invariant 1). No poster beats that.
	 *
	 * @param int $attachment_id Media library attachment ID.
	 * @return string Poster URL, '' when unusable.
	 */
	public function poster_url( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $attachment_id, 'large' );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		if ( HostMatcher::OWN !== $this->pipeline()->host_matcher->classify( $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Remove third-party embeds entirely — for excerpts and feeds, where a
	 * placeholder is nonsense (§3.3, §9.3).
	 *
	 * @param string $html Content.
	 * @return string
	 */
	public function strip( string $html ): string {
		return $this->pipeline()->stripper->strip( $html );
	}

	/**
	 * Remove literal <link> hint tags for gated hosts — performance plugins
	 * and themes print these directly, bypassing wp_resource_hints (§9.14).
	 * Used by the output buffer, the only place the whole document exists.
	 *
	 * @param string $html Document HTML.
	 * @return string
	 */
	public function scrub_hint_tags( string $html ): string {
		$pipeline = $this->pipeline();
		return $pipeline->hint_scrubber->scrub_tags( $html, $pipeline->scanner );
	}

	/**
	 * Never gate where an editor is looking (invariant 4) or where a
	 * placeholder is nonsense (PLAN.md §9.2, §9.3).
	 *
	 * AJAX and REST are deliberately NOT blanket-bailed: infinite scroll,
	 * "load more" and AJAX product filters deliver front-end content over
	 * admin-ajax.php and /wp-json/ to anonymous visitors, and a blanket bail
	 * injects raw third-party iframes into live pages — page two of an
	 * infinite-scroll archive was simply unprotected. The discriminator is
	 * the requester: editors fetch raw content to edit it (the block
	 * renderer, page-builder edit modes), and every editor request is
	 * authenticated with edit capability. Anonymous requests are visitors,
	 * and visitors get gated markup (§9.2).
	 *
	 * @return bool
	 */
	public function should_bail(): bool {
		if ( $this->gating_suspended ) {
			return true;
		}
		if ( wp_doing_ajax() ) {
			return current_user_can( 'edit_posts' );
		}
		if ( is_admin() || is_customize_preview() ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return current_user_can( 'edit_posts' );
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return current_user_can( 'edit_posts' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context probe.
		if ( isset( $_GET['context'] ) && 'edit' === $_GET['context'] ) {
			return true;
		}
		if ( is_feed() || is_embed() ) {
			return true;
		}
		return false;
	}

	/**
	 * The §6.4 bridge config, or null when the bridge stays off — because
	 * the option is off, or because no platform from the tested list is
	 * installed (fail closed; an untested CMP gets no adapter).
	 *
	 * The filter exists for the documented escape hatches: overriding the
	 * category a site's CMP files embeds under, or adding TCF vendor ids
	 * for custom providers. Returning null (or anything non-array) from it
	 * disables the bridge entirely.
	 *
	 * @return array|null
	 */
	public function cmp_bridge_config(): ?array {
		$config = BridgeConfig::build( Detector::detected(), $this->options['cmp'] );
		$config = apply_filters( 'calucon_embed_gate_cmp_config', $config, $this->options['cmp'] );
		return is_array( $config ) ? $config : null;
	}

	/**
	 * Hosts that count as the site itself. Naive home_url() comparison is
	 * wrong on real sites (PLAN.md §3.4): include site_url() for
	 * WordPress-in-a-subdirectory, and let sites declare their CDN via the
	 * calucon_embed_gate_own_hosts filter.
	 *
	 * @return string[]
	 */
	private function own_hosts(): array {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = $host;
			}
		}
		// Multisite (§9.11): a cross-site embed inside one network is not a
		// third party. Mapped domains appear as each site's domain.
		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites( array( 'number' => 500 ) ) as $site ) {
				if ( isset( $site->domain ) && '' !== $site->domain ) {
					$hosts[] = $site->domain;
				}
			}
		}
		// The configured never-gate list has the same effect as an own host:
		// the embed passes through. Kept as a separate setting because the
		// meaning differs — the owner is accepting those requests.
		$extra = (array) apply_filters(
			'calucon_embed_gate_own_hosts',
			array_merge( $this->options['detection']['own_hosts'], $this->options['detection']['never_gate'] )
		);
		return array_values( array_unique( array_merge( $hosts, $extra ) ) );
	}

	/**
	 * Every host the provider match tables know — plus each provider's
	 * declared sibling CDN hosts (i.ytimg.com, pbs.twimg.com) — the set
	 * whose resource hints must not survive (§9.14).
	 *
	 * @param array[] $providers Descriptors.
	 * @return string[]
	 */
	private function provider_hosts( array $providers ): array {
		$hosts = array();
		foreach ( $providers as $descriptor ) {
			$match = isset( $descriptor['match'] ) && is_array( $descriptor['match'] ) ? $descriptor['match'] : array();
			foreach ( array( 'iframe_host', 'script_host' ) as $key ) {
				if ( isset( $match[ $key ] ) ) {
					$hosts = array_merge( $hosts, (array) $match[ $key ] );
				}
			}
			if ( isset( $descriptor['scrub_hint_hosts'] ) ) {
				$hosts = array_merge( $hosts, (array) $descriptor['scrub_hint_hosts'] );
			}
		}
		return array_values( array_unique( $hosts ) );
	}
}
