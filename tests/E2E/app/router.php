<?php
/**
 * Router for the E2E test server (php -S).
 *
 * Serves pages whose embed markup went through the real PHP pipeline
 * (HtmlScanner → HostMatcher → Registry → PlaceholderRenderer via
 * IframeRule) plus the plugin's actual front-end assets — no WordPress, but
 * nothing mocked on the path the product claim depends on.
 *
 * @package CaluconEmbedGate
 */

declare( strict_types=1 );

// Sentinel for the plugin's direct-access guards, so the src/ classes load
// under the php -S test server without booting WordPress (mirrors
// tests/bootstrap.php and tests/wp/seed.php).
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$root = dirname( __DIR__, 3 );

spl_autoload_register(
	static function ( $class ) use ( $root ) {
		$prefixes = array(
			'CaluconEmbedGate\\Tests\\' => $root . '/tests/',
			'CaluconEmbedGate\\'        => $root . '/src/',
		);
		foreach ( $prefixes as $prefix => $dir ) {
			if ( 0 === strpos( $class, $prefix ) ) {
				$path = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
				if ( is_file( $path ) ) {
					require $path;
				}
				return;
			}
		}
	}
);

use CaluconEmbedGate\Tests\Support\PipelineFactory;

$uri = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

if ( '/healthz' === $uri ) {
	header( 'Content-Type: text/plain' );
	echo 'ok';
	return true;
}

if ( '/assets/gate.js' === $uri ) {
	header( 'Content-Type: application/javascript' );
	readfile( $root . '/assets/js/gate.js' );
	return true;
}

if ( '/assets/gate.css' === $uri ) {
	header( 'Content-Type: text/css' );
	readfile( $root . '/assets/css/gate.css' );
	return true;
}

if ( '/assets/cmp-bridge.js' === $uri ) {
	header( 'Content-Type: application/javascript' );
	readfile( $root . '/assets/js/cmp-bridge.js' );
	return true;
}

if ( '/frame.html' === $uri ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><meta charset="utf-8"><title>Same-origin frame</title><p>local frame</p>';
	return true;
}

if ( '/assets/poster.svg' === $uri ) {
	// The site-origin poster image for /page/poster — a real request the
	// zero-requests test sees, from the page's own host.
	header( 'Content-Type: image/svg+xml' );
	echo '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360"><rect width="640" height="360" fill="#3a6ea5"/></svg>';
	return true;
}

if ( '/assets/poster-tall.svg' === $uri ) {
	// A 4:3 poster for a 16:9 embed — the ratios an owner actually has.
	header( 'Content-Type: image/svg+xml' );
	echo '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="480"><rect width="640" height="480" fill="#8a6d3b"/></svg>';
	return true;
}

if ( '/page/poster-mismatch' === $uri ) {
	// Poster ratio differs from the embed's reserved box: the image must be
	// cropped into the box (object-fit), never overflow it — overflow: auto
	// would show a dead scrollbar.
	$content = '<iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE" frameborder="0"></iframe>';

	cg_e2e_page( $content, '', '', array( 'poster' => '/assets/poster-tall.svg' ) );
	return true;
}

if ( '/page/gated' === $uri ) {
	// Raw content as WordPress would render it, before gating: one embed per
	// authoring style from the fixture corpus, plus a same-origin iframe that
	// must survive untouched.
	$content = implode(
		"\n",
		array(
			'<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">',
			'<iframe title="Kolkja Cycling" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE?feature=oembed" frameborder="0" allow="accelerometer; autoplay; encrypted-media" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>',
			'</div></figure>',
			"<div\nclass=wp-block-embed__wrapper> <iframe\nloading=lazy title=\"Minified\" width=422 height=750 src=\"https://www.youtube-nocookie.com/embed/y_pjE_p1HwE\" frameborder=0></iframe> </div>",
			'<iframe src="//player.vimeo.com/video/76979871" title="Vimeo" width="640" height="360"></iframe>',
			'<iframe src="https://widgets.example-partner.com/embed/9" title="Unknown widget" sandbox="allow-scripts" width="400" height="300"></iframe>',
			'<iframe src="/frame.html" title="Same origin" width="300" height="100"></iframe>',
		)
	);

	cg_e2e_page( $content );
	return true;
}

if ( '/page/scripts' === $uri ) {
	// Script-strategy providers: companion element + SDK script tag.
	$content = implode(
		"\n",
		array(
			'<blockquote class="twitter-tweet"><p lang="en" dir="ltr">Worth every kilometre.</p>&mdash; Calucon (@calucon) <a href="https://twitter.com/calucon/status/1234567890123456789">June 1, 2024</a></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>',
			'<div class="strava-embed-placeholder" data-embed-type="activity" data-embed-id="1234567890"></div><script src="https://strava-embeds.com/embed.js"></script>',
		)
	);

	cg_e2e_page( $content );
	return true;
}

if ( '/page/scripts-multi' === $uri ) {
	// Two embeds of the SAME script-strategy provider: one SDK load renders
	// both companions, so clicking one clears the other's panel — but only
	// after the SDK actually loads. When the SDK is blocked, the sibling and
	// its fallback link must survive (see gate.js activateScript).
	$content = implode(
		"\n",
		array(
			'<blockquote class="twitter-tweet"><p>First.</p>&mdash; A <a href="https://twitter.com/calucon/status/1111111111111111111">t1</a></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>',
			'<blockquote class="twitter-tweet"><p>Second.</p>&mdash; A <a href="https://twitter.com/calucon/status/2222222222222222222">t2</a></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>',
		)
	);

	cg_e2e_page( $content );
	return true;
}

if ( '/page/memory' === $uri ) {
	// Consent memory enabled (provider scope, session lifetime) plus the
	// withdrawal control a site would place in its privacy policy.
	$content = '<iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE" frameborder="0"></iframe>'
		. "\n" . '<button type="button" class="cg-withdraw" data-cg-withdraw aria-controls="cg-withdraw-status">Withdraw embed consents</button>'
		. '<span id="cg-withdraw-status" class="cg-withdraw__status" role="status" aria-live="polite"></span>';

	cg_e2e_page(
		$content,
		'',
		'window.caluconEmbedGateConfig = {"memory":"session","scope":"provider","durationDays":180};'
	);
	return true;
}

if ( '/page/memory-persistent' === $uri ) {
	// Persistent memory with the widest scope and a short lifetime: pins
	// localStorage (not sessionStorage) selection, the identifier-free '*'
	// grant key, cross-provider restore, and lazy expiry of aged grants.
	$content = '<iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE" frameborder="0"></iframe>'
		. "\n" . '<iframe title="Other video" src="https://player.vimeo.com/video/76979871" width="500" height="281" frameborder="0"></iframe>';

	cg_e2e_page(
		$content,
		'',
		'window.caluconEmbedGateConfig = {"memory":"persistent","scope":"all","durationDays":1};'
	);
	return true;
}

if ( '/page/light' === $uri ) {
	// A light block theme: base/contrast presets defined, no accent-8 — the
	// panel inverts to light while the button keeps the green accent fallback.
	// This is the configuration where deriving the button text colour from
	// --cg-bg failed WCAG 1.4.3 (~3.1:1); axe's colour-contrast check on this
	// page is the regression test for the --cg-accent-fg pairing.
	$content = '<iframe title="Video on a light theme" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE" frameborder="0"></iframe>';

	$theme_css = ':root{--wp--preset--color--base:#f9f9f9;--wp--preset--color--contrast:#111111;}'
		. 'body{background:var(--wp--preset--color--base);color:var(--wp--preset--color--contrast);}';

	cg_e2e_page( $content, $theme_css );
	return true;
}

if ( '/page/aspect' === $uri ) {
	// The §5.3 layout-preservation cases: a core reserved aspect box
	// (wp-has-aspect-ratio + ::before spacer, iframe lifted out of flow),
	// and a bare iframe with only width/height attributes.
	$content = implode(
		"\n",
		array(
			'<figure class="wp-block-embed is-type-video wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">',
			'<iframe title="Reserved box" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE" frameborder="0" allowfullscreen></iframe>',
			'</div></figure>',
			'<div style="width: 640px;"><iframe src="https://widgets.example-partner.com/embed/9" title="Bare" width="640" height="360"></iframe></div>',
		)
	);

	// Equivalent of core's wp-embed-responsive rules, which real WordPress
	// themes ship; the harness must reproduce the trap to test the fix.
	$core_css = '.wp-block-embed{margin:0;max-width:600px;}'
		. '.wp-has-aspect-ratio .wp-block-embed__wrapper::before{content:"";display:block;padding-top:56.25%;}'
		. '.wp-has-aspect-ratio iframe{position:absolute;top:0;left:0;width:100%;height:100%;}';

	cg_e2e_page( $content, $core_css );
	return true;
}

if ( '/page/shapes' === $uri ) {
	// The detection-hardening shapes: attribute-swapped lazy loading, legacy
	// object/embed, the srcdoc lazy-YouTube snippet, and a GTM-style hidden
	// pixel that must vanish rather than become a visible dead panel.
	$content = implode(
		"\n",
		array(
			'<iframe class="lazyloaded" title="Lazy video" width="560" height="315" src="about:blank" data-lazy-src="https://www.youtube.com/embed/y_pjE_p1HwE" frameborder="0"></iframe>',
			'<object width="560" height="315"><param name="movie" value="https://www.youtube.com/v/y_pjE_p1HwE?version=3"><embed src="https://www.youtube.com/v/y_pjE_p1HwE?version=3" type="application/x-shockwave-flash" width="560" height="315"></object>',
			'<iframe width="560" height="315" title="Srcdoc video" srcdoc="&lt;a href=&quot;https://www.youtube.com/watch?v=y_pjE_p1HwE&quot;&gt;&lt;img src=&quot;https://img.youtube.com/vi/y_pjE_p1HwE/hqdefault.jpg&quot; alt=&quot;Poster&quot;&gt;&lt;/a&gt;" frameborder="0"></iframe>',
			'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TEST123" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>',
		)
	);

	cg_e2e_page( $content );
	return true;
}

if ( '/page/poster' === $uri ) {
	// Owner-supplied poster (§5.4): the integration layer resolved a
	// media-library image to a site-origin URL and put it in the context —
	// exactly what RenderBlock does for the caluconEmbedGatePoster attribute.
	$content = implode(
		"\n",
		array(
			'<figure class="wp-block-embed is-type-video wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">',
			'<iframe title="Kolkja Cycling" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE?feature=oembed" frameborder="0" allowfullscreen></iframe>',
			'</div></figure>',
		)
	);

	cg_e2e_page( $content, '', '', array( 'poster' => '/assets/poster.svg' ) );
	return true;
}

if ( '/page/custom-provider' === $uri ) {
	// Owner-defined providers exactly as Plugin::providers() assembles them:
	// built-ins first, then the option rows (sanitised, reserved hosts
	// refused). One row names the unknown widget host; one tries to claim
	// YouTube's host and must be ignored; one is script-strategy.
	$builtin   = \CaluconEmbedGate\Providers\Builtin\Descriptors::all();
	$reserved  = \CaluconEmbedGate\Providers\CustomProviders::reserved_hosts( $builtin );
	$options   = \CaluconEmbedGate\Support\Options::sanitize_report(
		array(
			'custom_providers' => array(
				array( 'label' => 'Example Partner', 'hosts' => 'widgets.example-partner.com', 'kind' => 'social' ),
				array( 'label' => 'Tube Thief', 'hosts' => "www.youtube.com\nwww.youtube-nocookie.com" ),
				array( 'label' => 'Widget SDK', 'script_hosts' => 'cdn.widget-sdk.example' ),
			),
			'providers'        => array( 'custom-example-partner' => array( 'enabled' => '0' ) ),
		),
		$reserved
	)['options'];
	$providers = \CaluconEmbedGate\Support\Options::apply_provider_overrides(
		array_merge( $builtin, \CaluconEmbedGate\Providers\CustomProviders::descriptors( $options['custom_providers'], null, $reserved ) ),
		$options
	);
	$content   = implode(
		"\n",
		array(
			'<iframe src="https://widgets.example-partner.com/embed/9" title="Unknown widget" sandbox="allow-scripts" width="400" height="300"></iframe>',
			'<iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE" frameborder="0"></iframe>',
			'<div class="widget-sdk" data-id="1"></div><script src="https://cdn.widget-sdk.example/sdk.js"></script>',
		)
	);

	cg_e2e_page( $content, '', '', array(), '', $providers );
	return true;
}

if ( '/page/collision' === $uri ) {
	// Two UNKNOWN third-party widgets (both resolve to the generic-script
	// provider) plus one unknown iframe: activating one widget must not
	// delete the other's placeholder or its fallback link.
	$content = implode(
		"\n",
		array(
			'<div class="booking-widget"><a href="https://booking.example-a.com/calucon">Book a tour</a></div><script src="https://cdn.example-a.com/widget.js"></script>',
			'<div class="reviews-widget"><a href="https://reviews.example-b.com/calucon">Our reviews</a></div><script src="https://cdn.example-b.com/reviews.js"></script>',
			'<iframe src="https://widgets.example-partner.com/embed/9" title="Unknown widget" width="400" height="300"></iframe>',
		)
	);

	cg_e2e_page( $content );
	return true;
}

if ( 0 === strpos( $uri, '/page/cmp-' ) ) {
	// §6.4 CMP-bridge pages: the real pipeline and the real bridge script
	// against SIMULATED consent-platform APIs — each stub implements the
	// documented public surface of its platform, with uniform test controls
	// (__cmpGrant / __cmpRevoke) driven from the spec.
	$cmp_case = substr( $uri, strlen( '/page/cmp-' ) );

	$cmp_content = '<iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE" frameborder="0"></iframe>'
		. "\n" . '<iframe src="//player.vimeo.com/video/76979871" title="Vimeo" width="640" height="360"></iframe>';

	$cmp_stubs = array(
		// Bridge configured for a platform that is NOT actually on the
		// page — a cached config outliving a deactivated CMP. Fail closed.
		'none'           => array(
			'config' => array( 'adapter' => 'complianz', 'category' => 'marketing' ),
			'stub'   => '',
		),
		// The WP Consent API's own fail-open shape: wp_has_consent()
		// answers true while NO consent type was ever set by a CMP. The
		// bridge must not trust it.
		'trap'           => array(
			'config' => array( 'adapter' => 'wp-consent-api', 'category' => 'marketing' ),
			'stub'   => 'window.wp_has_consent = function () { return true; };',
		),
		'wp-consent-api' => array(
			'config' => array( 'adapter' => 'wp-consent-api', 'category' => 'marketing' ),
			'stub'   => 'window.wp_consent_type = "optin";'
				. 'var cgGrants = {};'
				. 'window.wp_has_consent = function (cat) { return cgGrants[cat] === true; };'
				. 'function cgFire(cat, val) { cgGrants[cat] = (val === "allow");'
				. ' var detail = []; detail[cat] = val;'
				. ' document.dispatchEvent(new CustomEvent("wp_listen_for_consent_change", { detail: detail })); }'
				. 'window.__cmpGrant = function () { cgFire("marketing", "allow"); };'
				. 'window.__cmpRevoke = function () { cgFire("marketing", "deny"); };',
		),
		'complianz'      => array(
			'config' => array( 'adapter' => 'complianz', 'category' => 'marketing' ),
			'stub'   => 'var cgGranted = false;'
				. 'window.cmplz_has_consent = function (cat) { return cat === "marketing" && cgGranted; };'
				. 'window.__cmpGrant = function () { cgGranted = true;'
				. ' document.dispatchEvent(new CustomEvent("cmplz_enable_category", { detail: { category: "marketing", categories: ["marketing"], region: "eu" } })); };'
				. 'window.__cmpRevoke = function () { cgGranted = false;'
				. ' document.dispatchEvent(new CustomEvent("cmplz_status_change", { detail: { category: "marketing", value: "deny", region: "eu" } })); };',
		),
		'cookiebot'      => array(
			'config' => array( 'adapter' => 'cookiebot', 'category' => 'marketing' ),
			'stub'   => 'window.Cookiebot = { consent: { marketing: false }, hasResponse: false };'
				. 'window.__cmpGrant = function () { window.Cookiebot.consent.marketing = true; window.Cookiebot.hasResponse = true;'
				. ' window.dispatchEvent(new CustomEvent("CookiebotOnConsentReady")); };'
				. 'window.__cmpRevoke = function () { window.Cookiebot.consent.marketing = false;'
				. ' window.dispatchEvent(new CustomEvent("CookiebotOnConsentReady")); };',
		),
		'cookieyes'      => array(
			'config' => array( 'adapter' => 'cookieyes', 'category' => 'advertisement' ),
			'stub'   => 'var cgCky = { activeLaw: "gdpr", categories: { necessary: true, advertisement: false }, isUserActionCompleted: false };'
				. 'window.getCkyConsent = function () { return cgCky; };'
				. 'window.__cmpGrant = function () { cgCky.categories.advertisement = true; cgCky.isUserActionCompleted = true;'
				. ' document.dispatchEvent(new CustomEvent("cookieyes_consent_update", { detail: { accepted: ["advertisement"], rejected: [] } })); };'
				. 'window.__cmpRevoke = function () { cgCky.categories.advertisement = false;'
				. ' document.dispatchEvent(new CustomEvent("cookieyes_consent_update", { detail: { accepted: [], rejected: ["advertisement"] } })); };',
		),
		'borlabs'        => array(
			'config' => array( 'adapter' => 'borlabs', 'category' => 'marketing', 'borlabsGroup' => 'external-media' ),
			'stub'   => 'var cgGranted = false;'
				. 'window.BorlabsCookie = { Consents: { hasConsentForServiceGroup: function (g) { return g === "external-media" && cgGranted; } } };'
				. 'window.__cmpGrant = function () { cgGranted = true; window.dispatchEvent(new CustomEvent("borlabs-cookie-consent-saved")); };'
				. 'window.__cmpRevoke = function () { cgGranted = false; window.dispatchEvent(new CustomEvent("borlabs-cookie-consent-saved")); };',
		),
		'rcb'            => array(
			'config' => array( 'adapter' => 'real-cookie-banner', 'category' => 'marketing' ),
			'stub'   => 'var cgResolvers = [];'
				. 'window.consentApi = { unblock: function (url) { return new Promise(function (resolve) { cgResolvers.push(resolve); }); } };'
				. 'window.__cmpGrant = function () { var r = cgResolvers; cgResolvers = []; for (var i = 0; i < r.length; i++) { r[i](); } };',
		),
		'tcf'            => array(
			'config' => array(
				'adapter'  => null,
				'category' => 'marketing',
				'tcf'      => array( 'vendors' => array( 'youtube' => 755, 'google-maps' => 755 ) ),
			),
			'stub'   => 'var cgListeners = []; var cgCurrent = null;'
				. 'window.__tcfapi = function (command, version, callback) {'
				. ' if (command === "addEventListener") { cgListeners.push(callback); if (cgCurrent) { callback(cgCurrent, true); } } };'
				. 'function cgPush(data) { cgCurrent = data; for (var i = 0; i < cgListeners.length; i++) { cgListeners[i](data, true); } }'
				. 'window.__cmpGrant = function () { cgPush({ eventStatus: "useractioncomplete", gdprApplies: true, purpose: { consents: { 1: true } }, vendor: { consents: { 755: true } } }); };'
				. 'window.__cmpRevoke = function () { cgPush({ eventStatus: "useractioncomplete", gdprApplies: true, purpose: { consents: { 1: false } }, vendor: { consents: { 755: false } } }); };',
		),
	);

	if ( isset( $cmp_stubs[ $cmp_case ] ) ) {
		$cmp_page = $cmp_stubs[ $cmp_case ];
		cg_e2e_page(
			$cmp_content,
			'',
			'window.caluconEmbedGateConfig = ' . json_encode( array( 'cmp' => $cmp_page['config'] ) ) . ';',
			array(),
			( '' !== $cmp_page['stub'] ? '<script>' . $cmp_page['stub'] . '</script>' : '' )
				. '<script src="/assets/cmp-bridge.js"></script>'
		);
		return true;
	}
}

/**
 * Gate raw content through the real pipeline and emit a full page.
 *
 * @param string $content       Pre-gating content HTML.
 * @param string $extra_css     Page-specific CSS (theme/core simulation).
 * @param string $config_js     Inline config (what wp_add_inline_script emits).
 * @param array  $extra_ctx     Extra integration context (e.g. §5.4 poster).
 * @param string $extra_scripts Raw script tags after gate.js (CMP stubs + bridge).
 * @return void
 */
function cg_e2e_page( string $content, string $extra_css = '', string $config_js = '', array $extra_ctx = array(), string $extra_scripts = '', ?array $providers = null ) {
	$gated = PipelineFactory::gate(
		$content,
		array( '127.0.0.1', 'localhost' ),
		array_merge( array( 'integration' => 'e2e', 'privacy_link' => true ), $extra_ctx ),
		$providers
	);

	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<title>Calucon Third-Party Embed Gate E2E</title>'
		. '<link rel="stylesheet" href="/assets/gate.css">'
		. ( '' !== $extra_css ? '<style>' . $extra_css . '</style>' : '' )
		. '</head><body>'
		// A real theme provides the page scaffold (landmark + h1); the panel
		// itself deliberately adds neither (PLAN.md §5.1).
		. '<main><h1>Calucon Third-Party Embed Gate E2E</h1>'
		. $gated
		. '</main>'
		. ( '' !== $config_js ? '<script>' . $config_js . '</script>' : '' )
		. '<script src="/assets/gate.js"></script>'
		. $extra_scripts
		. '</body></html>';
}

http_response_code( 404 );
header( 'Content-Type: text/plain' );
echo 'not found';
return true;
