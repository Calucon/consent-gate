<?php
/**
 * Seeds the WordPress integration-test content. Idempotent — safe to run on
 * every stack start. Executed inside WordPress:
 *   Docker:      wp eval-file …/tests/wp/seed.php   (via tests/wp/setup.sh)
 *   Playground:  the runPHP step in tests/wp/blueprint.json
 *
 * The posts mirror the fixture corpus (PLAN.md §10.1) so the integration
 * layer is tested with the same shapes the unit fixtures pin down —
 * including the minified variant. No emoji anywhere: WordPress would fetch
 * twemoji images from s.w.org and taint the zero-third-party-request test.
 *
 * @package CaluconEmbedGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'kses_remove_filters' ) ) {
	kses_remove_filters(); // Seed raw markup exactly as authored.
}

// Poster attachment (§5.4): metadata is enough — wp_get_attachment_image_url()
// resolves a site-origin URL from _wp_attached_file without touching disk, and
// the poster test asserts markup, not pixels.
$cg_poster = get_page_by_path( 'cg-poster-image', OBJECT, 'attachment' );
if ( $cg_poster ) {
	$cg_poster_id = (int) $cg_poster->ID;
} else {
	$cg_poster_id = (int) wp_insert_post(
		array(
			'post_name'      => 'cg-poster-image',
			'post_title'     => 'Poster image',
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		)
	);
	update_post_meta( $cg_poster_id, '_wp_attached_file', '2026/08/cg-poster.jpg' );
}

$cg_seed_posts = array(
	'gated-classic'  => array(
		'title'   => 'Gated classic content',
		'content' => '<p>Intro paragraph.</p>' . "\n\n"
			. '<iframe title="Kolkja Cycling" width="500" height="281" src="https://www.youtube.com/embed/y_pjE_p1HwE?feature=oembed" frameborder="0" allow="accelerometer; autoplay; encrypted-media" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>' . "\n\n"
			. "<div\nclass=wp-block-embed__wrapper> <iframe\nloading=lazy title=\"Minified\" width=422 height=750 src=\"https://www.youtube-nocookie.com/embed/y_pjE_p1HwE\" frameborder=0></iframe> </div>" . "\n\n"
			. '<iframe src="https://widgets.example-partner.com/embed/9" title="Unknown widget" sandbox="allow-scripts" width="400" height="300"></iframe>' . "\n\n"
			. '<iframe src="/wp-json/" title="Same origin" width="300" height="100"></iframe>' . "\n\n"
			. '<p>Outro paragraph.</p>',
	),
	'gated-blocks'   => array(
		'title'   => 'Gated block content',
		'content' => "<!-- wp:paragraph -->\n<p>Block intro.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:html -->\n<figure class=\"wp-block-embed is-type-video wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\n<iframe title=\"Kolkja Cycling\" width=\"500\" height=\"281\" src=\"https://www.youtube.com/embed/y_pjE_p1HwE?feature=oembed\" frameborder=\"0\" allowfullscreen></iframe>\n</div></figure>\n<!-- /wp:html -->\n\n"
			. "<!-- wp:html -->\n<iframe src=\"https://player.vimeo.com/video/76979871?h=8272103f6e\" title=\"Vimeo\" width=\"640\" height=\"360\"></iframe>\n<!-- /wp:html -->",
	),
	'script-embeds'  => array(
		'title'   => 'Script embeds',
		'content' => '<blockquote class="twitter-tweet"><p lang="en" dir="ltr">Worth every kilometre.</p>&mdash; Calucon (@calucon) <a href="https://twitter.com/calucon/status/1234567890123456789">June 1, 2024</a></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>' . "\n\n"
			. '<div class="strava-embed-placeholder" data-embed-type="activity" data-embed-id="1234567890"></div><script src="https://strava-embeds.com/embed.js"></script>',
	),
	'poster-embed'   => array(
		'title'   => 'Poster embed',
		'content' => "<!-- wp:html {\"caluconEmbedGatePoster\":" . $cg_poster_id . "} -->\n<figure class=\"wp-block-embed is-type-video wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\n<iframe title=\"Kolkja Cycling\" width=\"500\" height=\"281\" src=\"https://www.youtube.com/embed/y_pjE_p1HwE?feature=oembed\" frameborder=\"0\" allowfullscreen></iframe>\n</div></figure>\n<!-- /wp:html -->",
	),
	'no-embeds'      => array(
		'title'   => 'No embeds here',
		'content' => '<p>Plain content without any third-party embed. The plugin must ship no assets on this page.</p>',
	),
	'escaped-markup' => array(
		'title'   => 'How to embed a video',
		'content' => '<p>To embed a video, paste this into your post:</p>' . "\n"
			. '<p><code>&lt;iframe src="https://www.youtube.com/embed/y_pjE_p1HwE" width="560" height="315"&gt;&lt;/iframe&gt;</code></p>',
	),
	'withdraw-page'  => array(
		'title'   => 'Privacy tools',
		'content' => '<p>Manage stored embed consents:</p>' . "\n" . '[calucon_embed_gate_withdraw]',
	),
	// Per-embed texts set in the block editor (RenderBlock): markup is
	// stripped and the texts are capped (button 120, notice 400).
	'per-embed-text' => array(
		'title'   => 'Per-embed text',
		'content' => "<!-- wp:html {\"caluconEmbedGateAction\":\"Load <b>the trailer</b>\",\"caluconEmbedGateNote\":\"<em>Own notice.</em> " . str_repeat( 'x', 500 ) . "\"} -->\n"
			. '<iframe src="https://player.vimeo.com/video/76979871" title="Trailer" width="640" height="360"></iframe>' . "\n<!-- /wp:html -->",
	),
);

foreach ( $cg_seed_posts as $cg_slug => $cg_post ) {
	if ( null !== get_page_by_path( $cg_slug, OBJECT, 'post' ) ) {
		continue;
	}
	wp_insert_post(
		array(
			'post_name'    => $cg_slug,
			'post_title'   => $cg_post['title'],
			'post_content' => $cg_post['content'],
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
}

// A performance-plugin emulator: adds a preconnect for a gated provider and
// a safe CDN through the wp_resource_hints filter (priority 5, before the
// plugin's own filter at 10), and prints literal <link> hint tags into
// wp_head the way Perfmatters-class plugins do — bypassing every filter
// (§9.14). The integration tests assert the filter path is always cleaned
// and the literal path is cleaned exactly when output buffering is on.
$cg_mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $cg_mu_dir ) ) {
	mkdir( $cg_mu_dir, 0755, true );
}
$cg_mu_source = <<<'MUPLUGIN'
<?php
add_filter( 'wp_resource_hints', function ( $urls, $rel ) {
	if ( 'preconnect' === $rel ) {
		$urls[] = 'https://platform.twitter.com';
		$urls[] = 'https://cdn.filter-safe.example';
	}
	return $urls;
}, 5, 2 );
// A code-registered provider (docs/customizing.md "Adding a provider"): the
// integration tests assert an owner-defined row cannot take its host.
add_filter( 'calucon_embed_gate_providers', function ( array $providers ): array {
	$providers[] = array(
		'id'          => 'partner-code',
		'label'       => 'Partner (code)',
		'match'       => array( 'iframe_host' => array( 'code.example-partner.com' ) ),
		'load_host'   => 'code-nocookie.example-partner.com',
		'privacy_url' => 'https://code.example-partner.com/privacy',
		'kind'        => 'document',
	);
	return $providers;
} );
add_action( 'wp_head', function () {
	echo '<link rel="preconnect" href="https://www.youtube.com">' . "\n";
	echo '<link rel="preconnect" href="https://cdn.literal-safe.example">' . "\n";
}, 99 );
MUPLUGIN;
file_put_contents( $cg_mu_dir . '/cg-test-hints.php', $cg_mu_source . "\n" );

// Pretty permalinks so the tests can address posts by slug. Flush
// unconditionally: newer Playground images pre-set the structure WITHOUT
// building the rules, so a structure-changed guard skips the flush and the
// first request after boot 404s (the old cause of a flaky first test).
$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
flush_rewrite_rules();

echo "calucon-third-party-embed-gate: seed complete\n";
