# Customizing Calucon Third-Party Embed Gate

A reference for developers and AI agents customizing this plugin on a site —
from `functions.php`, a small plugin, or WP-CLI. Everything here works
without touching plugin files (never edit those; updates overwrite them).

## The contract your customization must keep

Calucon Third-Party Embed Gate's entire product is: **nothing third-party loads before the
visitor clicks**. Not a script, not an iframe, not a thumbnail, not a
`preconnect`. When customizing:

- **Don't** load a preview image, poster, or favicon from the provider in a
  custom template or placeholder filter. If you want thumbnails, they must
  be served from this site. The supported way: every embed block has a
  **Set poster image** control (Calucon Third-Party Embed Gate panel in the block inspector)
  that takes a media-library image; the server refuses any poster URL that
  does not resolve to this site's own host, so a CDN-offloaded media
  library needs its CDN host declared under **Detection → Own hosts**.
- **Don't** add `autoplay` (a WCAG failure, and not what the visitor asked
  for), and don't widen `allow`/`sandbox` beyond what the original embed had.
- **Don't** write cookies/localStorage before the click "to remember
  something" — pre-consent storage is the problem this plugin removes.
- **Don't** put compliance claims ("GDPR compliant") into custom notes or
  button texts. The plugin is a technical measure; it makes no legal claims.
- **Don't** emit a literal `<iframe` inside placeholder markup — gated
  content may be re-processed, and raw iframes would be re-detected.
- Keep the placeholder's server-rendered fallback link working: a visitor
  with JavaScript disabled must still get a real link to the content.

The plugin fails closed: an unknown third-party iframe is gated by the
generic rule even if your descriptor is wrong. A broken customization shows
a generic panel — it never silently lets a tracker through.

## Verifying your changes

After any customization, verify from the shell (both commands are
read-only and make no outbound request):

```sh
wp calucon-embed-gate scan --format=json     # every embed found in recent
                                       # content + whether it is gated
wp calucon-embed-gate providers --format=json # providers as the gate resolves
                                        # them (builtins + your filter)
```

In `scan` output, every third-party row should read `status: gated`.
Other statuses mean "loads without consent, because a setting says so":
`rule-disabled` (detection rule off), `provider-disabled` (owner disabled
it), `own-host` (treated as this site). `no-usable-url` passes through
because there is nothing to gate.

Browser-level check: open the page in devtools, Network tab, filter out
your own domain — before any click the list must be empty.

## Settings

One option, `calucon_embed_gate_options`, sanitized against a schema on every
read (malformed input falls back to safe defaults — you cannot corrupt it):

```sh
wp option get calucon_embed_gate_options --format=json
wp option patch update calucon_embed_gate_options detection images 1
wp option patch update calucon_embed_gate_options appearance preset card
wp option patch update calucon_embed_gate_options consent memory session
```

Shape (see `src/Support/Options.php` for the authoritative schema):

- `custom_providers[]`: `id` (`custom-<slug>`, generated once), `label`, `hosts`,
  `script_hosts`, `kind` — the Providers tab's *Your own providers* rows
- `providers.{id}`: `enabled` (bool; ignored for `custom-*` ids, which are
  always gated), `privacy_variant` (bool), `note`, `action` (strings),
  `privacy_url` (https only; overrides the built-in policy link)
- `display`: `privacy_link` (bool, default false — the provider's
  privacy-policy link inside every panel)
- `detection`: `iframes`, `scripts`, `images` (bool; images off by
  default), `own_hosts`, `never_gate`, `always_gate` (host lists, `*.`
  wildcards allowed), `www_equivalence`, `output_buffer` (bool)
- `appearance`: `preset` (`default|minimal|card`), `corners`
  (`''|square|rounded|pill|custom`) + `radius` (0–48 px, with `custom`),
  `border_width` (`''` or `'0'`–`'10'`), `shadow` (`''|soft|strong`),
  `density` (`''|compact|airy`), `button_size` (`''|small|large`),
  `button_style` (`''|outline`), `button_width` (`''|full`), `hover`
  (`''|strong|none`), `play_icon` (bool; kind-aware glyph), `note_size`
  (`''|small`), `align` (`''|center`), `poster_panel` (`''|center|bar`),
  `poster_dim` (`''|light|strong`), `withdraw_style` (`''|outline|link`),
  `dark` (bool) + `dark_bg`/`dark_fg`/`dark_accent`/`dark_accent_fg`; the
  colours `bg`/`fg`/`accent`/`accent_fg`/`border_color`/`link` and the
  dark ones each take `''` (inherit the theme), a `#hex`, or
  `preset:<slug>` (follow that theme-palette colour by name) — see
  `Options::defaults()` for the authoritative list
- `consent`: `memory` (`off|session|persistent`), `scope`
  (`embed|provider|all`), `duration_days` (1–730)
- `cmp`: `bridge` (bool, off by default), `borlabs_group` (slug, default
  `external-media`), `tcf` (bool, experimental)

Cache plugins are flushed automatically when this option changes.

## Adding a provider

**No code needed for the common case.** Settings → Calucon Third-Party
Embed Gate → Providers → *Your own providers*: a name, the embed hosts (one
per line; pasted URLs are reduced to their host), optional script hosts,
and a kind for the button icon. After saving, the provider appears in the
table above with the same note, button-text and privacy-policy-link
controls as the built-ins. Adding one can never change *what* is gated: unknown hosts are gated
either way (a row only adds the name, icon and texts), hosts a built-in
provider handles are refused at save time (with a notice) and ignored at
run time, and owner-defined providers are always gated — there is no Gate
checkbox for them; exempting a host is the never-gate list's explicit job.
At most 100 rows of 50 hosts. With the consent-platform bridge on, your
own providers follow the same category consent as every other embed; the
experimental TCF bridge only recognises providers with a vendor id, so
they stay gated under it (fail closed). They never rewrite the load URL; for
`load_host`/`load_path`, path captures, companion classes or hint scrubbing,
register a descriptor in code:

Providers are **descriptor arrays**, not classes. Register via the
`calucon_embed_gate_providers` filter. Order of assembly: built-ins → this
filter → the owner's own providers (with every host a registered provider
handles stripped) → the per-provider settings from the Providers table, so
a site owner's note, button text, privacy URL or on/off choice applies to
code-registered providers too:

```php
add_filter( 'calucon_embed_gate_providers', function ( array $providers ): array {
	$providers[] = array(
		'id'          => 'example-videos',
		'label'       => 'ExampleVideos',
		'match'       => array(
			'iframe_host' => array( 'embed.example-videos.com', 'example-videos.com' ),
			// Optional: named captures interpolated (URL-encoded) into
			// load_path / fallback as {id}-style tokens.
			'iframe_path' => '#^/embed/(?<id>[A-Za-z0-9_-]+)#',
		),
		// Optional privacy-preserving load target used after the click.
		'load_host'   => 'embed-nocookie.example-videos.com',
		'load_path'   => '/embed/{id}',
		// The no-JS fallback link (and the error-state link).
		'fallback'    => 'https://example-videos.com/watch/{id}',
		'controller'  => 'Example Videos Inc., City, Country',
		'privacy_url' => 'https://example-videos.com/privacy', // Linked in the panel (Providers tab toggle) and shown by the CLI.
		'kind'        => 'video', // video | map | audio | social | form | calendar | document | image | 3d | '' — picks the optional button glyph.
		// Reserved: 'custom' (bool) marks owner-defined providers from the
		// settings screen — never set it on a code-registered descriptor.
		'aspect'      => '16/9',
	);
	return $providers;
} );
```

All keys and defaults: `src/Providers/Provider.php` (`normalize()`); the 21
built-in descriptors in `src/Providers/Builtin/Descriptors.php` are working
examples of every pattern, including script-strategy embeds
(`match.script_host`, `companion_class`, `companion_fallback`) and sibling
CDN hosts for resource-hint scrubbing (`scrub_hint_hosts`).

Notes:

- Without a descriptor, embeds from unknown hosts are still gated
  generically — a descriptor adds a proper label, a privacy-preserving
  load target, a working fallback link, and the provider row in settings.
- `load_query` merges query parameters into the original URL at load time
  (e.g. Vimeo's `dnt=1`); `autoplay` never survives, by design.
- Match hosts explicitly, including subdomains (`youtube.com` does not
  imply `www.youtube.com` in `match`).
- Then verify: `wp calucon-embed-gate providers` must list it;
  `wp calucon-embed-gate scan` must show its embeds `gated` with your label.

## Adjusting behaviour with filters

```php
// Change the note/button text in context (return plain text; it is escaped).
add_filter( 'calucon_embed_gate_note_text', fn( $note, $provider, $ctx ) =>
	'youtube' === $provider['id'] ? 'Video hosted by YouTube. Loads on click.' : $note, 10, 3 );

// Exempt a host entirely. WARNING: its requests then happen without
// consent on every page view — this is the owner's decision to defend.
add_filter( 'calucon_embed_gate_should_gate', fn( $gate, $url, $ctx ) =>
	false !== strpos( $url, 'widgets.already-covered.example' ) ? false : $gate, 10, 3 );
```

All hooks: `calucon_embed_gate_providers`, `calucon_embed_gate_provider_for_url`,
`calucon_embed_gate_should_gate`, `calucon_embed_gate_is_own_host`,
`calucon_embed_gate_own_hosts`, `calucon_embed_gate_placeholder_html`,
`calucon_embed_gate_payload`, `calucon_embed_gate_note_text`,
`calucon_embed_gate_action_text`, `calucon_embed_gate_fallback_url`,
`calucon_embed_gate_www_equivalence`, `calucon_embed_gate_cmp_config`,
`calucon_embed_gate_the_content_priority`, `calucon_embed_gate_render_block_priority`; actions
`calucon_embed_gate_before_render`, `calucon_embed_gate_embed_gated`, `calucon_embed_gate_flush_caches`.

## The consent platform bridge

With `cmp.bridge` enabled and a **tested** platform installed (WP Consent
API, Complianz, Cookiebot, CookieYes, Borlabs Cookie 3, Real Cookie
Banner), the front end loads `assets/js/cmp-bridge.js`, which reads the
platform's documented public JS API: an affirmative grant for the embeds'
category auto-activates gated embeds, a withdrawal re-gates what the
bridge (not a visitor's own click) activated. Everything is client-side
and read-only — the server always renders the gated placeholder (cache
safety), the bridge writes nothing to the platform, and any missing or
silent platform means gating stands.

`calucon_embed_gate_cmp_config` filters the config handed to the script (or
disables the bridge by returning `null`):

```php
// This site's CMP files embeds under a custom category.
add_filter( 'calucon_embed_gate_cmp_config', function ( $config ) {
	if ( is_array( $config ) ) {
		$config['category'] = 'external-media';
	}
	return $config;
} );

// Add a TCF Global Vendor List id for a custom provider (tcf flag on).
add_filter( 'calucon_embed_gate_cmp_config', function ( $config ) {
	if ( is_array( $config ) && isset( $config['tcf'] ) ) {
		$config['tcf']['vendors']['example-videos'] = 123;
	}
	return $config;
} );
```

If you prefer the platform's own content blocker for a specific provider,
disable that provider under **Providers** — Calucon Third-Party Embed Gate then passes its
embeds through and the platform's blocker is the only gate. Do not run
both gates plus the bridge for the same provider expecting them to stack;
one authority per embed is the design.

Google Consent Mode v2 is deliberately not a bridge source: it has no
public read API, it is written by consent platforms for Google tags, and
no consent-mode signal governs iframes. Bridge the platform instead — it
is where Consent Mode's state comes from. The plugin also never sends
`gtag('consent', …)` updates; a click on one embed is not a site-wide
marketing consent.

## Styling

Prefer the Appearance tab (presets, corners, colour pickers, live preview
with a built-in contrast check). For CSS, override the custom properties —
no `!important`, no specificity war:

```css
.cg-embed {
	--cg-bg: #f6f7f7;
	--cg-fg: #1d2327;
	--cg-accent: #2271b1;
	--cg-accent-fg: #ffffff; /* keep ≥ 4.5:1 against --cg-accent */
	--cg-radius: 8px;
}
```

## Replacing the placeholder markup

Copy `templates/placeholder.php` to
`{your-theme}/calucon-embed-gate/placeholder.php`. The template documents the
minimum contract it must keep (container classes/attributes, a real
`<button type="button">`, the server-rendered fallback link). Keep the
panel's name on `role="group"` + `aria-label` — do not substitute a
heading; the correct heading level cannot be known from inside an embed.

The template receives a `$poster` variable — the site-origin poster image
chosen in the block editor ('' when none). If your template renders it,
keep the `cg-embed--poster` container class and `cg-embed__poster` image
class (gate.js removes the image on activation by that class), keep
`alt="" aria-hidden="true"`, and never replace it with a provider-hosted
image URL.

## What you cannot do from a customization

Reordering: the gate runs late on content filters; it sees the output of
shortcodes and blocks. Page-builder output that bypasses content filters
needs the "Gate the whole page output" setting instead of custom code.
And there is no supported way to make the plugin fetch anything remote —
that boundary is the product.
