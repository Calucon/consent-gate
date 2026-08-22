// WordPress integration tests (PLAN.md §10.2, run against a real install).
//
// The site under test is seeded by tests/wp/seed.php — the same content on
// both backends (Docker stack or WordPress Playground). These tests assert
// the plugin's behaviour where it actually runs: real hooks, real theme,
// real enqueue pipeline, real feeds and REST.
// @ts-check
const { test, expect } = require( '@playwright/test' );

const OWN_HOSTS = [ '127.0.0.1', 'localhost' ];

function isOwnRequest( url ) {
	// blob:/data: URLs carry no network host — they can only exist because
	// the page's own scripts created them (WordPress core does).
	if ( url.startsWith( 'blob:' ) || url.startsWith( 'data:' ) ) {
		return true;
	}
	return OWN_HOSTS.includes( new URL( url ).hostname );
}

function trackThirdPartyRequests( page ) {
	const offenders = [];
	page.on( 'request', ( request ) => {
		if ( ! isOwnRequest( request.url() ) ) {
			offenders.push( request.url() );
		}
	} );
	return offenders;
}

async function abortThirdParty( page ) {
	await page.route( '**', ( route ) => {
		return isOwnRequest( route.request().url() )
			? route.continue()
			: route.fulfill( { contentType: 'text/html', body: '<!doctype html><p>frame</p>' } );
	} );
}

test( 'classic content: gated on the_content, zero third-party requests', async ( { page } ) => {
	const offenders = trackThirdPartyRequests( page );

	await page.goto( '/gated-classic/' );
	await page.waitForLoadState( 'networkidle' );

	expect( offenders, 'INVARIANT 1 VIOLATED on a real WordPress page' ).toEqual( [] );

	// Three foreign iframes gated — including the minified one — the
	// same-origin iframe untouched.
	await expect( page.locator( '.cg-embed' ) ).toHaveCount( 3 );
	await expect( page.locator( 'iframe[src="/wp-json/"]' ) ).toHaveCount( 1 );
	expect( await page.locator( 'iframe' ).count() ).toBe( 1 );

	// Nothing stored by the plugin before consent (invariant 3). WordPress
	// core itself writes wpEmojiSettingsSupports to sessionStorage — that is
	// core's doing on any theme, with or without this plugin — so the
	// assertion is scoped to what the plugin controls.
	const storage = await page.evaluate( () => ( {
		local: window.localStorage.length,
		sessionKeys: Object.keys( window.sessionStorage ).filter( ( k ) => k !== 'wpEmojiSettingsSupports' ),
		caluconEmbedGate: window.sessionStorage.getItem( 'calucon-embed-gate' ) || window.localStorage.getItem( 'calucon-embed-gate' ),
		cookie: document.cookie,
	} ) );
	expect( storage ).toEqual( { local: 0, sessionKeys: [], caluconEmbedGate: null, cookie: '' } );
} );

test( 'block content: gated via render_block', async ( { page } ) => {
	const offenders = trackThirdPartyRequests( page );

	await page.goto( '/gated-blocks/' );
	await page.waitForLoadState( 'networkidle' );

	expect( offenders ).toEqual( [] );
	await expect( page.locator( '[data-cg-provider="youtube"]' ) ).toHaveCount( 1 );
	await expect( page.locator( '[data-cg-provider="vimeo"]' ) ).toHaveCount( 1 );
	expect( await page.locator( 'iframe' ).count() ).toBe( 0 );
} );

test( 'click activates through the plugin-enqueued gate.js, loading the privacy-preserving host', async ( { page } ) => {
	await abortThirdParty( page );
	await page.goto( '/gated-blocks/' );

	const youtube = page.locator( '[data-cg-provider="youtube"]' );
	await youtube.locator( '.cg-embed__button' ).click();

	const frame = youtube.locator( 'iframe' );
	await expect( frame ).toHaveCount( 1 );
	await expect( frame ).toHaveAttribute( 'src', 'https://www.youtube-nocookie.com/embed/y_pjE_p1HwE' );

	// Focus stays inside the container (§8), and the Vimeo panel is intact.
	const focused = await page.evaluate( () => document.activeElement && document.activeElement.className );
	expect( String( focused ) ).toContain( 'cg-embed' );
	await expect( page.locator( '[data-cg-provider="vimeo"] .cg-embed__panel' ) ).toHaveCount( 1 );
} );

test( 'script embeds: SDKs gated, companions kept, fallbacks harvested', async ( { page } ) => {
	const offenders = trackThirdPartyRequests( page );

	await page.goto( '/script-embeds/' );
	await page.waitForLoadState( 'networkidle' );

	expect( offenders, 'script SDK loaded before any click' ).toEqual( [] );
	await expect( page.locator( '.cg-embed[data-cg-provider="twitter"]' ) ).toHaveCount( 1 );
	await expect( page.locator( '.cg-embed[data-cg-provider="strava"]' ) ).toHaveCount( 1 );
	await expect( page.locator( 'blockquote.twitter-tweet' ) ).toHaveCount( 1 );
	await expect( page.locator( 'div.strava-embed-placeholder' ) ).toHaveCount( 1 );
	await expect( page.locator( '[data-cg-provider="strava"] .cg-embed__fallback a' ) )
		.toHaveAttribute( 'href', 'https://www.strava.com/activities/1234567890' );
} );

test( 'poster block attribute renders a site-origin poster, gone after activation', async ( { page } ) => {
	const offenders = trackThirdPartyRequests( page );

	await page.goto( '/poster-embed/' );
	await page.waitForLoadState( 'networkidle' );

	// The poster request goes to the site's own host — never the provider.
	expect( offenders, 'a poster must never contact a third party' ).toEqual( [] );

	const container = page.locator( '.cg-embed--poster' );
	// The poster must fit its reserved box: overflow: auto would otherwise
	// show a dead scrollbar (Simon's report).
	const overflow = await container.evaluate( ( el ) => ( { sh: el.scrollHeight, ch: el.clientHeight } ) );
	expect( overflow.sh ).toBeLessThanOrEqual( overflow.ch );
	await expect( container ).toHaveCount( 1 );
	const poster = container.locator( 'img.cg-embed__poster' );
	await expect( poster ).toHaveAttribute( 'alt', '' );
	await expect( poster ).toHaveAttribute( 'aria-hidden', 'true' );
	const src = await poster.getAttribute( 'src' );
	expect( OWN_HOSTS ).toContain( new URL( String( src ), page.url() ).hostname );

	await abortThirdParty( page );
	await container.locator( '.cg-embed__button' ).click();
	await expect( container.locator( 'iframe' ) ).toHaveCount( 1 );
	await expect( container.locator( 'img.cg-embed__poster' ) ).toHaveCount( 0 );
} );

test( 'assets ship only on pages where something was gated', async ( { page } ) => {
	await page.goto( '/gated-classic/' );
	await expect( page.locator( 'script[src*="calucon-third-party-embed-gate/assets/js/gate.js"]' ) ).toHaveCount( 1 );
	await expect( page.locator( 'link[href*="calucon-third-party-embed-gate/assets/css/gate.css"]' ) ).toHaveCount( 1 );

	await page.goto( '/no-embeds/' );
	await expect( page.locator( '.cg-embed' ) ).toHaveCount( 0 );
	await expect( page.locator( 'script[src*="calucon-third-party-embed-gate/assets/js/gate.js"]' ) ).toHaveCount( 0 );
	await expect( page.locator( 'link[href*="calucon-third-party-embed-gate/assets/css/gate.css"]' ) ).toHaveCount( 0 );
} );

test( 'escaped markup in a tutorial post survives untouched', async ( { page } ) => {
	await page.goto( '/escaped-markup/' );

	await expect( page.locator( '.cg-embed' ) ).toHaveCount( 0 );
	// The code sample still shows the literal markup to the reader.
	await expect( page.locator( 'code' ) ).toContainText( '<iframe src="https://www.youtube.com/embed/y_pjE_p1HwE"' );
} );

test( 'feeds strip embeds instead of gating them', async ( { request } ) => {
	const response = await request.get( '/feed/' );
	expect( response.ok() ).toBe( true );
	const xml = await response.text();

	// No live embed, no placeholder — neither belongs in RSS (§9.3). The
	// escaped tutorial sample (&#60;iframe …) legitimately remains.
	expect( xml ).not.toMatch( /<iframe[^>]*youtube\.com\/embed/ );
	expect( xml ).not.toMatch( /<script[^>]*platform\.twitter\.com/ );
	expect( xml ).not.toContain( 'cg-embed' );
	expect( xml ).toContain( 'Intro paragraph.' );
} );

test( 'anonymous REST content is gated — headless/load-more consumers get placeholders', async ( { request } ) => {
	const response = await request.get( '/wp-json/wp/v2/posts?slug=gated-classic' );
	expect( response.ok() ).toBe( true );
	const [ post ] = await response.json();

	// §9.2: themes fetch archive pages over /wp-json for "load more" and
	// render them into the live page. An anonymous requester is a visitor,
	// and visitors get gated markup — the blanket REST bail was a bypass.
	expect( post.content.rendered ).toContain( 'cg-embed' );
	expect( post.content.rendered ).not.toMatch( /<iframe[^>]*youtube\.com\/embed/ );
} );

test( 'editor REST content is NOT gated — invariant 4', async ( { page, request } ) => {
	// A cookie fetch without a nonce is anonymous to the REST API, so this
	// goes through the block editor itself: its wp.apiFetch carries the
	// authenticated nonce, exactly like real editor traffic.
	const listResponse = await request.get( '/wp-json/wp/v2/posts?slug=gated-classic' );
	const [ { id } ] = await listResponse.json();

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ ); // Login sets the auth cookie via redirect; navigating before it lands races back to wp-login.
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	await page.waitForFunction( () => window.wp && window.wp.apiFetch );

	const post = await page.evaluate( ( postId ) => {
		return window.wp.apiFetch( { path: `/wp/v2/posts/${ postId }` } );
	}, id );

	// Invariant 4: an editor fetching through REST must get the original
	// embed, or gating looks like data loss.
	expect( post.content.rendered ).toContain( 'youtube.com/embed' );
	expect( post.content.rendered ).not.toContain( 'cg-embed' );
} );

test( 'withdraw shortcode renders a real button with its live region', async ( { page } ) => {
	await page.goto( '/withdraw-page/' );

	const button = page.locator( 'button.cg-withdraw[data-cg-withdraw]' );
	await expect( button ).toHaveCount( 1 );
	const statusId = await button.getAttribute( 'aria-controls' );
	expect( statusId ).toBeTruthy();
	await expect( page.locator( `#${ statusId }[aria-live="polite"]` ) ).toHaveCount( 1 );
} );


test( 'admin: appearance controls are novice-usable — pickers, live preview, contrast report', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ ); // Login sets the auth cookie via redirect; navigating before it lands races back to wp-login.

	const offenders = trackThirdPartyRequests( page );
	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate' );
	await page.waitForLoadState( 'networkidle' );

	// The preview sample carries a YouTube payload, but it is inert data:
	// the plugin must add no request to the settings screen (invariant 9).
	// Core's own admin toolbar fetches the user's Gravatar on every wp-admin
	// page, plugin or no plugin — that is outside what the plugin controls.
	const pluginOffenders = () => offenders.filter( ( url ) => ! url.includes( 'gravatar.com' ) );
	expect( pluginOffenders(), 'settings screen made a third-party request' ).toEqual( [] );

	// Every colour — six base ones (incl. border and link) and the four
	// dark-mode ones — got a real WordPress colour picker; no hex typing.
	await expect( page.locator( '.wp-picker-container' ) ).toHaveCount( 10 );

	// The Appearance panel lives behind its tab.
	await page.click( '#cg-tabbtn-appearance' );

	// Regression: nothing has been touched, so the unsaved bar must not
	// show — the colour pickers rewrite their fields after load and that
	// once counted as a change.
	await page.waitForTimeout( 600 );
	await expect( page.locator( '#cg-unsaved' ) ).toBeHidden();

	// Choice controls are disclosure menus with radios (glyph + label).
	const choose = async ( id, value ) => {
		const control = page.locator( `#${ id }` );
		if ( ! ( await control.getAttribute( 'open' ) ) ) {
			await control.locator( ':scope > summary' ).click();
		}
		await control.locator( `input[type="radio"][value="${ value }"]` ).check( { force: true } );
	};
	const expectChoice = ( id, value ) => expect( page.locator( `#${ id } input[type="radio"]:checked` ) ).toHaveValue( value );

	// Every section is a collapsible container: Colours and Shape start
	// open, the advanced four collapsed on an untouched site. Open those
	// so every control below is reachable.
	const sections = page.locator( '#cg-tab-appearance details.cg-section' );
	await expect( sections ).toHaveCount( 6 );
	await expect( page.locator( '#cg-tab-appearance details.cg-section[open]' ) ).toHaveCount( 2 );
	for ( let i = 2; i < 6; i++ ) {
		await sections.nth( i ).locator( ':scope > summary' ).click();
	}
	await expect( page.locator( '#cg-tab-appearance details.cg-section[open]' ) ).toHaveCount( 6 );

	// The live preview is the real placeholder markup, and the contrast
	// report measured every colour pair.
	const sample = page.locator( '#cg-preview-stage .cg-embed' );
	await expect( sample ).toBeVisible();
	await expect( sample.locator( '.cg-embed__button' ) ).toBeVisible();
	await expect( page.locator( '#cg-contrast-report' ) ).toContainText( ':1' );

	// Switching the panel style restyles the preview immediately.
	await choose( 'cg-preset', 'minimal' );
	await expect( page.locator( '#cg-preview-stage.cg-preview--minimal' ) ).toHaveCount( 1 );

	// The 0.10 fine-grained controls mirror into the preview: a custom
	// radius reveals its input and rounds the sample; a border width draws.
	await choose( 'cg-corners', 'custom' );
	await expect( page.locator( '#cg-radius-row' ) ).toBeVisible();
	await page.fill( '#cg-radius', '24' );
	await expect( sample ).toHaveCSS( 'border-radius', '24px' );
	await page.fill( '#cg-border-width', '4' );
	await expect( sample ).toHaveCSS( 'border-top-width', '4px' );
	await choose( 'cg-shadow', 'none' );
	await expect( sample ).toHaveCSS( 'box-shadow', 'none' );

	// Every choice control shows a glyph for the current option and one per
	// option in its menu.
	await expect( page.locator( '#cg-corners > summary .cg-choice__icon' ) ).toHaveCount( 1 );
	expect( await page.locator( '#cg-corners .cg-color__option .cg-choice__icon' ).count() ).toBe( 5 );

	// Round 3: the tab is sectioned, and Reset returns every field to
	// "inherit" (custom radius set above → back to default, row hidden).
	// Six option sections plus the Preview heading.
	await expect( page.locator( '#cg-tab-appearance h3' ) ).toHaveCount( 7 );
	await page.click( '#cg-appearance-reset' );
	await expectChoice( 'cg-corners', '' );
	await expect( page.locator( '#cg-radius-row' ) ).toBeHidden();
	await expect( page.locator( '#cg-border-width' ) ).toHaveValue( '' );
	await expect( sample ).not.toHaveCSS( 'border-top-width', '4px' );
	// Outline button style mirrors into the preview; the poster preview
	// injects a bundled data: image and the placement select moves the
	// panel over it.
	await choose( 'cg-button-style', 'outline' );
	await expect( sample.locator( '.cg-embed__button' ) ).toHaveCSS( 'background-color', 'rgba(0, 0, 0, 0)' );
	await page.check( '#cg-preview-poster' );
	await expect( sample.locator( 'img.cg-embed__poster' ) ).toHaveAttribute( 'src', /^data:image\/svg\+xml/ );
	await choose( 'cg-poster-panel', 'bar' );
	await expect( sample.locator( '.cg-embed__panel' ) ).toHaveCSS( 'justify-self', 'stretch' );
	await page.uncheck( '#cg-preview-poster' );
	await expect( sample.locator( 'img.cg-embed__poster' ) ).toHaveCount( 0 );

	// Colour rows are compact disclosures: the summary names the current
	// colour; the menu lists Default · theme colours (named) · Custom.
	const bgControl = page.locator( '.cg-color[data-cg-color-key="bg"]' );
	const bgRadios = bgControl.locator( 'input[type="radio"]' );
	await expect( bgControl.locator( '.cg-color__name' ) ).toHaveText( /^Default/ );
	await bgControl.locator( 'summary' ).click();
	await expect( bgControl ).toHaveAttribute( 'open', '' );
	expect( await bgRadios.count() ).toBeGreaterThan( 3 );
	await expect( bgRadios.first() ).toHaveValue( '' );
	await expect( bgRadios.last() ).toHaveValue( 'custom' );
	// Every option shows its name, not just a dot.
	await expect( bgControl.locator( '.cg-color__label' ).nth( 1 ) ).not.toBeEmpty();
	// A theme colour: reference stored, summary names it, preview painted,
	// menu closes, picker stays hidden.
	const themeRadio = bgRadios.nth( 1 );
	const themeSlug = await themeRadio.getAttribute( 'value' );
	const themeHex = await themeRadio.getAttribute( 'data-cg-hex' );
	const themeName = await themeRadio.getAttribute( 'data-cg-name' );
	expect( themeSlug ).toMatch( /^preset:[a-z0-9-]+$/ );
	await themeRadio.check( { force: true } );
	await expect( bgControl.locator( '.cg-color__name' ) ).toHaveText( themeName );
	await expect( bgControl ).not.toHaveAttribute( 'open', '' );
	await expect( page.locator( '#cg-color-bg' ) ).toBeHidden();
	// Normalise #abc to #aabbcc so the assertion never silently skips.
	const hex6 = themeHex.length === 4 ? '#' + themeHex.slice( 1 ).split( '' ).map( ( c ) => c + c ).join( '' ) : themeHex;
	expect( hex6 ).toMatch( /^#[0-9a-f]{6}$/i );
	await expect( sample ).toHaveCSS( 'background-color', `rgb(${ parseInt( hex6.slice( 1, 3 ), 16 ) }, ${ parseInt( hex6.slice( 3, 5 ), 16 ) }, ${ parseInt( hex6.slice( 5, 7 ), 16 ) })` );
	// Custom: the picker appears inside the menu (with the theme palette as
	// named swatches in it too); a picked colour keeps Custom and shows hex.
	await bgControl.locator( 'summary' ).click();
	await bgRadios.last().check( { force: true } );
	await expect( page.locator( '#cg-color-bg' ) ).toBeVisible();
	const bgPicker = page.locator( '#cg-color-bg' ).locator( 'xpath=ancestor::*[contains(@class,"wp-picker-container")]' );
	const irisSwatches = bgPicker.locator( '.iris-palette' );
	expect( await irisSwatches.count() ).toBeGreaterThan( 2 );
	await expect( irisSwatches.first() ).toHaveAttribute( 'title', /.+/ );
	await irisSwatches.first().click();
	await expect( page.locator( '#cg-color-bg' ) ).toHaveValue( /^#[0-9a-f]{3,6}$/ );
	await expect( bgRadios.last() ).toBeChecked();
	await expect( bgControl.locator( '.cg-color__name' ) ).toHaveText( /^Custom #/ );
	await page.keyboard.press( 'Escape' );
	await expect( bgControl ).not.toHaveAttribute( 'open', '' );

	// Round 4: a quick style fills in the controls AND the preview in one
	// click; poster dimming and the phone-width preview mirror too.
	await page.click( '.cg-quick-style[data-cg-quick-style="cinema"]' );
	await expectChoice( 'cg-corners', 'rounded' );
	await expect( page.locator( '#cg-play-icon' ) ).toBeChecked();
	await expect( page.locator( '[data-cg-color="bg"]' ) ).toHaveValue( '#101418' );
	await expect( bgRadios.last() ).toBeChecked();
	// Every quick-style button carries a miniature drawn in its colours.
	await expect( page.locator( '.cg-quick-style .cg-quick-card' ) ).toHaveCount( 5 );
	await expect( sample ).toHaveCSS( 'background-color', 'rgb(16, 20, 24)' );
	await expect( page.locator( '#cg-contrast-report' ) ).not.toContainText( 'hard to read' );
	await page.check( '#cg-preview-poster' );
	await expect( sample.locator( 'img.cg-embed__poster' ) ).toHaveCSS( 'filter', /brightness\(0\.5\)/ );
	await page.uncheck( '#cg-preview-poster' );
	await page.check( '#cg-preview-narrow' );
	await expect( page.locator( '#cg-preview-stage' ) ).toHaveCSS( 'max-width', '360px' );
	await page.uncheck( '#cg-preview-narrow' );
	await page.click( '#cg-appearance-reset' );
	await expect( page.locator( '[data-cg-color="bg"]' ) ).toHaveValue( '' );
	await expect( bgRadios.first() ).toBeChecked();
	// The readability report is marked pass/fail per line.
	expect( await page.locator( '#cg-contrast-report .cg-contrast-line--pass' ).count() ).toBeGreaterThan( 0 );

	// UX polish: the sticky bar shows unsaved state; a bulk action offers
	// Undo and Undo really restores; quick-style cards are live miniatures
	// (real panel clones); collapsed sections count their changes.
	await expect( page.locator( '.cg-quick-style .cg-quick-card .cg-embed' ) ).toHaveCount( 5 );
	await page.click( '.cg-quick-style[data-cg-quick-style="pastel"]' );
	await expect( page.locator( '#cg-unsaved' ) ).toBeVisible();
	await expect( page.locator( '#cg-undo' ) ).toBeVisible();
	await expectChoice( 'cg-corners', 'pill' );
	await expect( page.locator( 'details.cg-section .cg-section__badge:visible' ).first() ).not.toContainText( 'customised' );
	await expect( page.locator( 'details.cg-section .cg-section__badge:visible' ).first() ).toHaveText( /[A-Za-z]/ );
	await page.click( '#cg-undo' );
	await expectChoice( 'cg-corners', '' );
	await expect( page.locator( '[data-cg-color="bg"]' ) ).toHaveValue( '' );

	// Hover a row → its preview target is outlined.
	await page.locator( '.cg-color[data-cg-color-key="link"]' ).locator( 'xpath=ancestor::tr' ).hover();
	await expect( sample.locator( '.cg-embed__fallback a' ) ).toHaveClass( /cg-preview-hl/ );
	await page.locator( '#cg-preset' ).locator( 'xpath=ancestor::tr' ).hover();
	await expect( sample.locator( '.cg-embed__fallback a' ) ).not.toHaveClass( /cg-preview-hl/ );
	await expect( sample ).toHaveClass( /cg-preview-hl/ );
	await page.mouse.move( 0, 0 );

	// Readability auto-fix: paint the panel text in the panel colour (1:1),
	// then let the fix pick a passing colour.
	const fgControl = page.locator( '.cg-color[data-cg-color-key="fg"]' );
	await fgControl.locator( 'summary' ).click();
	await fgControl.locator( 'input[type="radio"][value="custom"]' ).check( { force: true } );
	// Same colour as the panel background (whatever the theme makes it).
	await page.evaluate( () => {
		const rgb = getComputedStyle( document.querySelector( '#cg-preview-stage .cg-embed' ) ).backgroundColor.match( /\d+/g ).slice( 0, 3 );
		const hex = '#' + rgb.map( ( n ) => Number( n ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
		window.jQuery( '#cg-color-fg' ).wpColorPicker( 'color', hex );
	} );
	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '#cg-contrast-report .cg-contrast-line--fail .cg-contrast-fix' ).first() ).toBeVisible();
	await page.locator( '#cg-contrast-report .cg-contrast-line--fail .cg-contrast-fix' ).first().click();
	await expect( page.locator( '#cg-contrast-report .cg-contrast-line--fail' ) ).toHaveCount( 0 );
	await page.click( '#cg-appearance-reset' );

	// Regression (Simon): tick the icon, save through the bar, come back —
	// a saved change is "customised", not "unsaved": no bar, no leave
	// warning, even after interacting with the page.
	await page.check( '#cg-play-icon' );
	await page.locator( '#cg-unsaved button[type="submit"]' ).click();
	await page.waitForURL( /options-general\.php/ );
	await page.waitForLoadState( 'load' );
	await expect( page.locator( '#cg-play-icon' ) ).toBeChecked();
	await page.locator( '#cg-tab-appearance details.cg-section' ).first().locator( ':scope > summary' ).click();
	await page.waitForTimeout( 300 );
	await expect( page.locator( 'body' ) ).not.toHaveClass( /cg-has-unsaved/ );
	await expect( page.locator( '#cg-unsaved' ) ).toBeHidden();
	// The badge names the customised row ("Icon"), and the row offers a
	// one-click Reset that clears it (and counts as an unsaved change).
	const iconBadge = page.locator( 'details.cg-section', { has: page.locator( '#cg-play-icon' ) } ).locator( '.cg-section__badge' );
	await expect( iconBadge ).toHaveText( 'Icon' );
	const iconRow = page.locator( 'tr', { has: page.locator( '#cg-play-icon' ) } );
	await expect( iconRow ).toHaveClass( /cg-row--customised/ );
	await iconRow.locator( '.cg-row-reset' ).click();
	await expect( page.locator( '#cg-play-icon' ) ).not.toBeChecked();
	await expect( iconRow ).not.toHaveClass( /cg-row--customised/ );
	await expect( iconBadge ).toBeHidden();
	await expect( page.locator( 'body' ) ).toHaveClass( /cg-has-unsaved/ );
	await page.check( '#cg-play-icon' );
	// Restore the saved state for the tests that follow.
	await page.locator( '#cg-tab-appearance details.cg-section' ).first().locator( ':scope > summary' ).click();
	await page.uncheck( '#cg-play-icon' );
	await page.locator( '#cg-unsaved button[type="submit"]' ).click();
	await page.waitForURL( /options-general\.php/ );
	await page.waitForLoadState( 'load' );
	await expect( page.locator( '#cg-play-icon' ) ).not.toBeChecked();
	// The reload collapsed the untouched sections again; the steps below
	// need every control reachable.
	for ( let i = 0; i < 6; i++ ) {
		const section = page.locator( '#cg-tab-appearance details.cg-section' ).nth( i );
		if ( ! ( await section.getAttribute( 'open' ) ) ) {
			await section.locator( ':scope > summary' ).click();
		}
	}

	// Round-2 controls: the withdraw sample restyles with its variant, the
	// dark colour rows reveal behind their toggle, and the play icon class
	// lands on the stage.
	const withdrawSample = page.locator( '#cg-preview-withdraw' );
	await expect( withdrawSample ).toBeVisible();
	// The contrast report measures the withdraw pair only for the filled
	// style — outline/link inherit the page's own text colour.
	await expect( page.locator( '#cg-contrast-report' ) ).toContainText( 'Withdraw' );
	await choose( 'cg-withdraw-style', 'outline' );
	await expect( withdrawSample ).toHaveClass( 'cg-withdraw cg-withdraw--outline' );
	await expect( page.locator( '#cg-contrast-report' ) ).not.toContainText( 'Withdraw' );
	await expect( page.locator( '.cg-dark-row' ).first() ).toBeHidden();
	await page.check( '#cg-dark-enabled' );
	await expect( page.locator( '.cg-dark-row' ).first() ).toBeVisible();
	await page.check( '#cg-play-icon' );
	await expect( page.locator( '#cg-preview-stage.cg-preview--icon' ) ).toHaveCount( 1 );

	// And the privacy link the front end now renders: preview panel shows
	// it (the sample is a real described-provider panel), and its toggle
	// lives on the Providers tab.
	await page.click( '#cg-tabbtn-providers' );
	await expect( page.locator( 'input[name="calucon_embed_gate_options[display][privacy_link]"][type="checkbox"]' ) ).toBeVisible();
	await expect( page.locator( 'input[name="calucon_embed_gate_options[display][privacy_link]"][type="checkbox"]' ) ).not.toBeChecked();
	// Per-provider policy URL override: the built-in link sits in the
	// placeholder so the owner sees what they are replacing.
	await expect( page.locator( 'input[name="calucon_embed_gate_options[providers][vimeo][privacy_url]"]' ) ).toHaveAttribute( 'placeholder', 'https://vimeo.com/privacy' );
	await page.click( '#cg-tabbtn-appearance' );

	// The preview's fallback link is defused — clicking it must not
	// navigate the owner away (nor toward the provider).
	await sample.locator( '.cg-embed__fallback a' ).click();
	expect( page.url() ).toContain( 'options-general.php?page=calucon-embed-gate' );
	expect( pluginOffenders() ).toEqual( [] );

	// Deep link: a panel id in the hash opens that tab directly. Leave the
	// page first — a hash-only goto would be a same-document navigation and
	// prove nothing.
	await page.goto( '/wp-admin/index.php' );
	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate#cg-tab-appearance' );
	await expect( page.locator( '#cg-preview-stage .cg-embed' ) ).toBeVisible();
	await expect( page.locator( '#cg-tabbtn-appearance' ) ).toHaveAttribute( 'aria-selected', 'true' );
} );

test( 'admin: settings screen is tabbed — providers, detection, consent, status', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ ); // Login sets the auth cookie via redirect; navigating before it lands races back to wp-login.

	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate' );

	await expect( page.locator( 'h1' ) ).toContainText( 'Calucon Third-Party Embed Gate' );

	// Providers is the default tab; the other panels are behind their tabs.
	await expect( page.locator( 'td', { hasText: 'YouTube' } ).first() ).toBeVisible();
	await expect( page.locator( '#cg-own-hosts' ) ).toBeHidden();

	await page.click( '#cg-tabbtn-detection' );
	await expect( page.locator( '#cg-own-hosts' ) ).toBeVisible();
	await expect( page.locator( 'td', { hasText: 'YouTube' } ).first() ).toBeHidden();

	await page.click( '#cg-tabbtn-consent' );
	await expect( page.locator( '#cg-memory' ) ).toBeVisible();

	// Status & tools is read-only: the CSP snippet shows, the Save button
	// does not.
	await page.click( '#cg-tabbtn-status' );
	await expect( page.locator( '#cg-compatibility' ) ).toBeVisible();
	// The CSP helper is an advanced, collapsed section at the end.
	await expect( page.locator( '#cg-csp-snippet' ) ).toBeHidden();
	await page.locator( '#cg-csp > summary' ).click();
	await expect( page.locator( 'textarea[aria-label="Content-Security-Policy snippet"]' ) ).toBeVisible();
	await expect( page.locator( 'textarea[aria-label="Content-Security-Policy snippet"]' ) ).toContainText( 'frame-src' );
	await expect( page.locator( 'form p.submit' ) ).toBeHidden();
	await page.click( '#cg-tabbtn-providers' );
	await expect( page.locator( 'form p.submit' ) ).toBeVisible();

	// The Status scan's legacy anchor still lands on the right tab.
	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate&calucon-embed-gate-scan=1#cg-status' );
	await expect( page.locator( '#cg-tabbtn-status' ) ).toHaveAttribute( 'aria-selected', 'true' );
	await expect( page.locator( '#cg-status' ) ).toBeVisible();
} );

test( 'editor: the per-block gate control appears in the block inspector', async ( { page } ) => {
	// editor.js had no coverage on real WordPress: a Gutenberg API change
	// (the inspector moved stores and gained tabs in 7.x) could silently
	// drop the §7.5 control. Drive a real editing session.
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	await page.goto( '/wp-admin/post-new.php' );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.hooks );
	expect( await page.evaluate( () => window.wp.hooks.hasFilter( 'editor.BlockEdit', 'calucon-embed-gate/inspector' ) ) ).toBe( true );

	await page.evaluate( () => {
		try {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		} catch ( e ) {}
		const block = window.wp.blocks.createBlock( 'core/html', { content: '<iframe src="https://www.youtube.com/embed/y_pjE_p1HwE" title="T"></iframe>' } );
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
		window.wp.data.dispatch( 'core/block-editor' ).selectBlock( block.clientId );
	} );

	// A brand-new install opens the welcome guide as a modal over the
	// inspector — and on a cold server it appears a few seconds AFTER the
	// editor is usable. An aria-modal hides everything behind it from role
	// queries, so a too-early "is it visible?" check lets it eat the test.
	// Wait for it, then close it; the preference write above only prevents
	// the NEXT one.
	const guide = page.getByRole( 'dialog', { name: /welcome/i } );
	await guide.waitFor( { timeout: 8000 } ).catch( () => {} );
	if ( await guide.isVisible() ) {
		await guide.getByRole( 'button', { name: /close/i } ).first().click();
		await expect( guide ).toBeHidden();
	}

	const sidebar = page.locator( '.interface-interface-skeleton__sidebar' );
	// Gutenberg remembers the sidebar state per user: open it through the
	// header's Settings toggle when a fresh editor starts with it closed.
	if ( ! ( await sidebar.isVisible() ) ) {
		await page.locator( '.editor-header__settings, .edit-post-header__settings' ).getByRole( 'button', { name: 'Settings', exact: true } ).click();
		await expect( sidebar ).toBeVisible();
	}
	await sidebar.getByRole( 'tab', { name: 'Block' } ).click();
	// A cold Playground renders the inspector in stages: the block card
	// (heading) first, then the inner List View / Settings tablist. Wait
	// for each stage explicitly instead of sampling once.
	await expect( sidebar.getByRole( 'heading', { name: 'Custom HTML' } ) ).toBeVisible( { timeout: 30000 } );
	const settingsTab = sidebar.getByRole( 'tab', { name: 'Settings' } );
	await settingsTab.waitFor( { timeout: 15000 } ).catch( () => {} );
	if ( await settingsTab.isVisible() ) {
		await settingsTab.click();
		await expect( settingsTab ).toHaveAttribute( 'aria-selected', 'true' );
	}
	const panel = page.getByRole( 'button', { name: 'Calucon Third-Party Embed Gate' } ).first();
	await expect( panel ).toBeVisible( { timeout: 20000 } );
	if ( 'false' === await panel.getAttribute( 'aria-expanded' ) ) {
		await panel.click();
	}
	// The override select (site default / always / never) and the poster
	// picker are the whole §7.5 control.
	const gateSelect = sidebar.locator( 'select' ).filter( { hasText: 'Site default' } ).first();
	await expect( gateSelect ).toBeVisible();
	await gateSelect.selectOption( 'always' );
	expect( await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getSelectedBlock().attributes.caluconEmbedGate ) ).toBe( 'always' );
	// The poster picker is offered while the block is gated (it hides for
	// "never" — nothing to poster).
	await expect( sidebar.getByRole( 'button', { name: /poster/i } ) ).toBeVisible();

	// Per-embed text (round 3): the fields write their block attributes.
	await sidebar.getByLabel( 'Button text for this embed' ).fill( 'Load the trailer' );
	await sidebar.getByLabel( 'Notice text for this embed' ).fill( 'Custom notice.' );
	const attrs = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getSelectedBlock().attributes );
	expect( attrs.caluconEmbedGateAction ).toBe( 'Load the trailer' );
	expect( attrs.caluconEmbedGateNote ).toBe( 'Custom notice.' );
} );

test( 'admin: Plugins screen links to Settings; a tab hash does not scroll the tab bar away', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	// "Settings" next to Deactivate on the Plugins screen.
	await page.goto( '/wp-admin/plugins.php' );
	const row = page.locator( 'tr[data-slug="calucon-third-party-embed-gate"], tr[data-plugin*="calucon-third-party-embed-gate"]' ).first();
	await expect( row.locator( '.row-actions a', { hasText: 'Settings' } ) ).toHaveAttribute( 'href', /options-general\.php\?page=calucon-embed-gate/ );
	// Support link in the row meta (convention), never among the actions.
	await expect( row.locator( '.plugin-version-author-uri a', { hasText: 'Support development' } ) ).toHaveAttribute( 'href', 'https://ko-fi.com/calucon' );
	await expect( row.locator( '.row-actions a', { hasText: 'Support' } ) ).toHaveCount( 0 );

	// The post-save redirect carries the tab as a hash that is also the
	// panel's id; the page must still show the tab bar at the top.
	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate#cg-tab-appearance' );
	await page.waitForLoadState( 'load' );
	await expect( page.locator( '#cg-tabbtn-appearance' ) ).toHaveAttribute( 'aria-selected', 'true' );
	await page.waitForTimeout( 300 );
	expect( await page.evaluate( () => window.scrollY ) ).toBe( 0 );
	await expect( page.locator( '.cg-tabs' ) ).toBeInViewport();
} );

test( 'resource hints to gated providers are removed; harmless hints survive', async ( { page } ) => {
	// The seed's mu-plugin emulates a performance plugin: one preconnect to
	// a gated provider and one to a safe CDN via the wp_resource_hints
	// filter, plus two literal <link> tags printed straight into wp_head.
	await page.goto( '/gated-classic/' );
	await expect( page.locator( '.cg-embed' ).first() ).toBeVisible();

	// Filter path: the gated host is stripped, the safe one is proof the
	// hint actually flowed through the filter (not a vacuous zero).
	await expect( page.locator( 'link[rel="preconnect"][href*="platform.twitter.com"]' ) ).toHaveCount( 0 );
	await expect( page.locator( 'link[rel="preconnect"][href*="cdn.filter-safe.example"]' ) ).toHaveCount( 1 );

	// Literal tags bypass every filter — WITHOUT output buffering they are
	// out of the plugin's reach. This pins the documented boundary; the
	// buffering test below asserts the same tag IS scrubbed when it's on.
	await expect( page.locator( 'link[rel="preconnect"][href="https://www.youtube.com"]' ) ).toHaveCount( 1 );
	await expect( page.locator( 'link[rel="preconnect"][href*="cdn.literal-safe.example"]' ) ).toHaveCount( 1 );
} );

test( 'whole-page buffering: gates, enqueues everywhere, and restores cleanly', async ( { page } ) => {
	// The output-buffer path had no integration coverage while it was
	// refactored twice (0.9.1 asset delivery, 0.9.4 structure) — this test
	// closes that gap. It restores the option in finally so the
	// assets-only-when-gated assertions elsewhere stay true on re-runs.
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	const OPTION = 'input[name="calucon_embed_gate_options[detection][output_buffer]"][type="checkbox"]';
	const save = async () => {
		await page.click( 'form p.submit input[type="submit"], form input#submit' );
		await page.waitForURL( /options-general\.php/ );
	};

	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate' );
	await page.click( '#cg-tabbtn-detection' );
	await page.check( OPTION );
	await save();

	try {
		// With buffering on, the (local) assets are delivered on every page —
		// the buffer gates on shutdown, far too late to enqueue conditionally.
		const offenders = [];
		page.on( 'request', ( request ) => {
			const host = new URL( request.url() ).hostname;
			if ( ! OWN_HOSTS.includes( host ) && ! request.url().includes( 'gravatar.com' ) ) {
				offenders.push( request.url() );
			}
		} );

		await page.goto( '/no-embeds/' );
		await expect( page.locator( 'script[src*="assets/js/gate.js"]' ) ).toHaveCount( 1 );
		await expect( page.locator( 'link[href*="assets/css/gate.css"]' ) ).toHaveCount( 1 );

		// With the whole document in hand, the literal hint tag printed by
		// the mu-plugin (unfilterable, see the hints test above) IS scrubbed
		// for the gated host — and only for the gated host (§9.14).
		await expect( page.locator( 'link[rel="preconnect"][href="https://www.youtube.com"]' ) ).toHaveCount( 0 );
		await expect( page.locator( 'link[rel="preconnect"][href*="cdn.literal-safe.example"]' ) ).toHaveCount( 1 );

		// Gating itself still holds end to end with the buffer active.
		await page.goto( '/gated-classic/' );
		await page.waitForLoadState( 'networkidle' );
		await expect( page.locator( '.cg-embed .cg-embed__button' ).first() ).toBeVisible();
		expect( await page.locator( 'iframe[src*="youtube"]' ).count() ).toBe( 0 );
		expect( offenders, 'INVARIANT 1 VIOLATED with output buffering active' ).toEqual( [] );
	} finally {
		await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate' );
		await page.click( '#cg-tabbtn-detection' );
		await page.uncheck( OPTION );
		await save();
	}

	await page.goto( '/no-embeds/' );
	await expect( page.locator( 'script[src*="assets/js/gate.js"]' ) ).toHaveCount( 0 );
} );

test( 'admin: the CSP helper says whether the site sends a policy and which hosts it still lacks', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate#cg-tab-status' );
	await page.locator( '#cg-csp > summary' ).click();
	const check = page.locator( '#cg-csp-check' );
	const result = page.locator( '#cg-csp-result' );
	await expect( check ).toBeVisible();
	await expect( result ).toBeHidden();

	// The evaluation is pure; drive it with a table before touching the network.
	const required = { 'frame-src': [ 'www.youtube-nocookie.com', 'player.vimeo.com' ], 'script-src': [ 'platform.twitter.com' ] };
	const cases = await page.evaluate( ( req ) => {
		const m = window.caluconEmbedGateCspCheck.missing;
		return {
			unrestricted: m( "img-src 'self'", req ),
			none: m( "default-src 'none'", req ),
			self: m( "default-src 'self'; frame-src 'self' https://www.youtube-nocookie.com", req ),
			wildcardHost: m( 'default-src *.vimeo.com https://*.twitter.com; frame-src https://www.youtube-nocookie.com https://*.vimeo.com', req ),
			scheme: m( 'default-src https:', req ),
			childSrc: m( "default-src 'self'; child-src https://www.youtube-nocookie.com https://player.vimeo.com; script-src 'self' platform.twitter.com", req ),
			// CSP3: an http: source also matches the https: form of the host.
			httpOnly: m( 'default-src http://www.youtube-nocookie.com http://player.vimeo.com http://platform.twitter.com', req ),
			// Several CSP headers arrive comma-joined; every one must allow the host.
			multi: m( "upgrade-insecure-requests, default-src 'self'; frame-src 'self' https://www.youtube-nocookie.com", req ),
			multiAllAllow: m( "default-src *, frame-src https://www.youtube-nocookie.com https://player.vimeo.com; script-src https://platform.twitter.com", req ),
			port: m( 'default-src https://www.youtube-nocookie.com:443 https://player.vimeo.com:8443 https://platform.twitter.com:*', req ),
			firstWins: m( "default-src 'none'; default-src *", req ),
			meta: window.caluconEmbedGateCspCheck.metaPolicy( '<html><head><meta\ncontent="default-src &#039;self&#039;" http-equiv=Content-Security-Policy></head></html>' ),
		};
	}, required );
	expect( cases.unrestricted ).toEqual( {} );
	expect( cases.none ).toEqual( required );
	expect( cases.self ).toEqual( { 'frame-src': [ 'player.vimeo.com' ], 'script-src': [ 'platform.twitter.com' ] } );
	expect( cases.wildcardHost ).toEqual( {} );
	expect( cases.scheme ).toEqual( {} );
	expect( cases.childSrc ).toEqual( {} );
	expect( cases.httpOnly ).toEqual( {} );
	expect( cases.multi ).toEqual( { 'frame-src': [ 'player.vimeo.com' ], 'script-src': [ 'platform.twitter.com' ] } );
	expect( cases.multiAllAllow ).toEqual( {} );
	expect( cases.port ).toEqual( { 'frame-src': [ 'player.vimeo.com' ] } );
	expect( cases.firstWins ).toEqual( required );
	expect( cases.meta ).toBe( "default-src 'self'" );

	// 1. No policy on the home page: "skip this section".
	await check.click();
	await expect( result ).toContainText( 'sends no Content-Security-Policy' );
	await expect( result ).toHaveClass( /cg-csp-result--ok/ );

	// 2. A policy that allows only YouTube: the others are listed as missing.
	await page.route( '**/*', ( route ) => {
		const req = route.request();
		if ( 'fetch' === req.resourceType() && ! req.url().includes( '/wp-admin/' ) ) {
			return route.fulfill( {
				status: 200,
				headers: { 'content-type': 'text/html', 'content-security-policy': "default-src 'self'; frame-src 'self' https://www.youtube-nocookie.com" },
				body: '<html><body>home</body></html>',
			} );
		}
		return route.continue();
	} );
	await check.click();
	await expect( result ).toHaveClass( /cg-csp-result--todo/ );
	await expect( result ).toContainText( 'does not yet allow' );
	await expect( result.locator( 'code', { hasText: 'player.vimeo.com' } ) ).toBeVisible();
	await expect( result.locator( 'code', { hasText: 'www.youtube-nocookie.com' } ) ).toHaveCount( 0 );
	await page.unroute( '**/*' );

	// 3. A policy that allows everything the snippet lists: nothing to do.
	const snippet = await page.locator( '#cg-csp-snippet' ).inputValue();
	await page.route( '**/*', ( route ) => {
		const req = route.request();
		if ( 'fetch' === req.resourceType() && ! req.url().includes( '/wp-admin/' ) ) {
			return route.fulfill( {
				status: 200,
				headers: { 'content-type': 'text/html', 'content-security-policy': "default-src 'self'; " + snippet.replace( /\n/g, ' ' ) },
				body: '<html><body>home</body></html>',
			} );
		}
		return route.continue();
	} );
	await check.click();
	await expect( result ).toHaveClass( /cg-csp-result--ok/ );
	await expect( result ).toContainText( 'already allows every enabled provider' );
	await page.unroute( '**/*' );

	// 4. Report-only: informational, never "todo".
	await page.route( '**/*', ( route ) => {
		const req = route.request();
		if ( 'fetch' === req.resourceType() && ! req.url().includes( '/wp-admin/' ) ) {
			return route.fulfill( {
				status: 200,
				headers: { 'content-type': 'text/html', 'content-security-policy-report-only': "default-src 'self'" },
				body: '<html><body>home</body></html>',
			} );
		}
		return route.continue();
	} );
	await check.click();
	await expect( result ).toHaveClass( /cg-csp-result--info/ );
	await expect( result ).toContainText( 'report-only' );
	await expect( result.locator( 'code', { hasText: 'player.vimeo.com' } ) ).toBeVisible();
	await page.unroute( '**/*' );

	// The "which provider needs which host" table explains the snippet.
	await page.locator( '.cg-csp__providers > summary' ).click();
	const youtubeRow = page.locator( '.cg-csp-table tbody tr', { hasText: 'YouTube' } ).first();
	await expect( youtubeRow ).toContainText( 'www.youtube-nocookie.com' );
	await expect( youtubeRow ).not.toContainText( 'www.youtube.com' );
	await expect( page.locator( '.cg-csp-table thead' ) ).toContainText( 'frame-src' );

	// Copy puts the snippet on the clipboard and says so.
	await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await page.click( '#cg-csp-copy' );
	await expect( page.locator( '#cg-csp-copied' ) ).toContainText( 'Copied' );
	expect( await page.evaluate( () => navigator.clipboard.readText() ) ).toBe( await page.locator( '#cg-csp-snippet' ).inputValue() );
} );

test( 'admin: an owner-defined provider names an unknown host, takes its own note, and can be removed', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	// Before: the seeded unknown widget is gated generically, under its host.
	await page.goto( '/gated-classic/' );
	const widget = page.locator( '.cg-embed[data-cg-host="widgets.example-partner.com"]' );
	await expect( widget ).toHaveAttribute( 'data-cg-provider', 'generic' );
	await expect( widget.locator( 'button' ) ).toContainText( 'widgets.example-partner.com' );

	// Add it on the Providers tab. The blank row is always there; "Add
	// another provider" clones it so two can be added in one save.
	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate' );
	const blank = page.locator( '#cg-custom-providers tr[data-cg-blank]' );
	await expect( blank ).toHaveCount( 1 );
	await page.click( '#cg-custom-add' );
	await expect( page.locator( '#cg-custom-providers tbody tr' ) ).toHaveCount( 2 );
	const added = page.locator( '#cg-custom-providers tbody tr' ).first();
	await added.locator( 'input[type="text"]' ).fill( 'Example Partner' );
	await added.locator( 'textarea' ).first().fill( 'https://widgets.example-partner.com/embed/9\nexample-partner.com' );
	// Ten kinds, each with its icon shown next to the select and in the main table.
	await expect( added.locator( 'select option' ) ).toHaveCount( 10 );
	await added.locator( 'select' ).selectOption( 'social' );
	await expect( added.locator( '.cg-kind-glyph' ) ).toHaveAttribute( 'data-cg-kind', 'social' );
	await expect( page.locator( '#cg-tab-providers table' ).first().locator( 'tbody tr', { hasText: 'YouTube' } ).locator( '.cg-kind-glyph' ) ).toHaveAttribute( 'data-cg-kind', 'video' );
	const glyphMask = await added.locator( '.cg-kind-glyph' ).evaluate( ( el ) => getComputedStyle( el ).maskImage || getComputedStyle( el ).webkitMaskImage );
	expect( glyphMask ).toContain( 'data:image/svg+xml' );
	await blank.locator( 'input[type="text"]' ).fill( 'Widget SDK' );
	await blank.locator( 'textarea' ).nth( 1 ).fill( 'cdn.widget-sdk.example' );
	await page.click( '#submit' );
	await expect( page.locator( '#setting-error-settings_updated, .notice-success' ).first() ).toBeVisible();

	// Saved rows: stable ids, pasted URL reduced to its host; the blank row is back.
	const rows = page.locator( '#cg-custom-providers tbody tr' );
	await expect( rows ).toHaveCount( 3 );
	await expect( rows.nth( 0 ).locator( 'input[type="hidden"]' ) ).toHaveValue( 'custom-example-partner' );
	await expect( rows.nth( 0 ).locator( 'textarea' ).first() ).toHaveValue( 'widgets.example-partner.com\nexample-partner.com' );
	await expect( rows.nth( 1 ).locator( 'input[type="hidden"]' ) ).toHaveValue( 'custom-widget-sdk' );
	await expect( rows.nth( 2 ) ).toHaveAttribute( 'data-cg-blank', '1' );

	// …and both appear in the main table, marked, always gated (no Gate
	// checkbox: a custom row can name a host, never exempt it), with the
	// usual note / button text / privacy-URL controls.
	const mainRow = page.locator( '#cg-tab-providers table' ).first().locator( 'tbody tr', { hasText: 'Example Partner' } );
	await expect( mainRow.locator( '.cg-tag' ) ).toHaveText( 'added by you' );
	await expect( mainRow.locator( 'input[name$="[enabled]"]' ) ).toHaveCount( 0 );
	await expect( mainRow ).toContainText( 'always' );

	// A row claiming a built-in's hosts is refused with a notice; what it
	// does not claim survives. YouTube keeps its host and nocookie load.
	const thief = page.locator( '#cg-custom-providers tr[data-cg-blank]' );
	await thief.locator( 'input[type="text"]' ).fill( 'Tube Thief' );
	await thief.locator( 'textarea' ).first().fill( 'www.youtube.com\nwww.youtube-nocookie.com\ncode.example-partner.com\nthief.example' );
	await page.click( '#submit' );
	const notice = page.locator( '.notice-warning, .notice.notice-warning', { hasText: 'Tube Thief' } );
	await expect( notice ).toContainText( 'www.youtube.com' );
	// …including hosts of providers registered in code (seed.php's mu-plugin).
	await expect( notice ).toContainText( 'code.example-partner.com' );
	await expect( notice ).toContainText( 'already handles' );
	// Input values are not row text: match the row by its name field's value.
	const thiefRow = page.locator( '#cg-custom-providers tbody tr', { has: page.locator( 'input[type="text"][value="Tube Thief"]' ) } );
	await expect( thiefRow.locator( 'textarea' ).first() ).toHaveValue( 'thief.example' );
	await thiefRow.locator( 'input[type="checkbox"][name$="[remove]"]' ).check();
	await page.click( '#submit' );
	await expect( page.locator( '#cg-custom-providers input[type="text"][value="Tube Thief"]' ) ).toHaveCount( 0 );
	await expect( mainRow.locator( '.cg-kind-glyph' ) ).toHaveAttribute( 'data-cg-kind', 'social' );
	await mainRow.locator( 'input[name$="[note]"]' ).fill( 'Partner rules apply.' );
	await mainRow.locator( 'input[name$="[privacy_url]"]' ).fill( 'https://example-partner.com/privacy' );
	await page.check( 'input[name$="[display][privacy_link]"][type="checkbox"]' ); // the link is off by default
	await page.click( '#submit' );
	await expect( page.locator( '#setting-error-settings_updated, .notice-success' ).first() ).toBeVisible();

	// Front end: named, with the owner's note and privacy link; still a real gate.
	const offenders = trackThirdPartyRequests( page );
	await page.goto( '/gated-classic/' );
	await expect( widget ).toHaveAttribute( 'data-cg-provider', 'custom-example-partner' );
	await expect( widget.locator( 'button' ) ).toContainText( 'Load content from Example Partner' );
	await expect( widget ).toContainText( 'Partner rules apply.' );
	await expect( widget.locator( 'a[href="https://example-partner.com/privacy"]' ) ).toBeVisible();
	// Logged in, so core's admin bar loads the user's Gravatar — WordPress's
	// request, not the plugin's. Nothing else may leave the site.
	expect( offenders.filter( ( url ) => ! /(^|\.)gravatar\.com$/.test( new URL( url ).hostname ) ) ).toEqual( [] );

	// Remove both: the gate falls back to generic and the override rows are pruned.
	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate' );
	await page.locator( '#cg-custom-providers input[type="checkbox"][name$="[remove]"]' ).first().check();
	await page.locator( '#cg-custom-providers input[type="checkbox"][name$="[remove]"]' ).last().check();
	await page.uncheck( 'input[name$="[display][privacy_link]"][type="checkbox"]' );
	await page.click( '#submit' );
	await expect( page.locator( '#cg-custom-providers tbody tr' ) ).toHaveCount( 1 );
	await expect( page.locator( '#cg-tab-providers .cg-tag' ) ).toHaveCount( 0 );
	await page.goto( '/gated-classic/' );
	await expect( widget ).toHaveAttribute( 'data-cg-provider', 'generic' );
} );

async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test( 'front end: appearance settings reach the page — colours, theme-palette fallback, kind glyphs, dark mode; privacy link toggle; withdraw style', async ( { page } ) => {
	await login( page );
	await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate#cg-tab-appearance' );
	await page.evaluate( () => document.querySelectorAll( '#cg-tab-appearance details.cg-section' ).forEach( ( d ) => { d.open = true; } ) );

	// A custom panel colour, a theme-palette button colour, the glyph, dark mode, the outline withdraw style.
	await page.locator( 'details[data-cg-color-key="bg"] > summary' ).click();
	await page.locator( 'details[data-cg-color-key="bg"] input[value="custom"]' ).check();
	await page.locator( 'details[data-cg-color-key="bg"] .cg-color__custom input[type="text"]' ).fill( '#101418' );
	await page.keyboard.press( 'Escape' ); // close the open colour menu — it overlays the next control
	const accentTheme = page.locator( 'details[data-cg-color-key="accent"] input[value^="preset:"]' ).first();
	await page.locator( 'details[data-cg-color-key="accent"] > summary' ).click();
	await accentTheme.check();
	const accentSlug = ( await accentTheme.getAttribute( 'value' ) ).replace( 'preset:', '' );
	const accentHex = await accentTheme.getAttribute( 'data-cg-hex' );
	await page.keyboard.press( 'Escape' );
	await page.check( '#cg-play-icon' );
	await page.check( '#cg-dark-enabled' );
	await page.locator( 'details[data-cg-color-key="dark_bg"] > summary' ).click();
	await page.locator( 'details[data-cg-color-key="dark_bg"] input[value="custom"]' ).check();
	await page.locator( 'details[data-cg-color-key="dark_bg"] .cg-color__custom input[type="text"]' ).fill( '#000000' );
	await page.keyboard.press( 'Escape' );
	await page.locator( '#cg-withdraw-style > summary' ).click();
	await page.locator( '#cg-withdraw-style input[value="outline"]' ).check();
	await page.keyboard.press( 'Escape' );
	// And the privacy link ON (off by default), on the Providers tab (same form).
	await page.click( '#cg-tabbtn-providers' );
	await page.check( 'input[name$="[display][privacy_link]"][type="checkbox"]' );
	await page.click( '#submit' );
	await expect( page.locator( '#setting-error-settings_updated, .notice-success' ).first() ).toBeVisible();

	try {
		await page.goto( '/gated-classic/' );
		const css = await page.locator( 'style#calucon-embed-gate-inline-css' ).textContent();
		expect( css ).toContain( '--cg-bg:#101418' );
		expect( css ).toContain( `--cg-accent:var(--wp--preset--color--${ accentSlug },` );
		expect( css.toLowerCase() ).toContain( accentHex.toLowerCase() );
		expect( css ).toContain( '[data-cg-provider="youtube"] .cg-embed__button::before' );
		expect( css ).toContain( '@media (prefers-color-scheme:dark)' );
		expect( css ).toMatch( /url\("data:/ );
		expect( css ).not.toMatch( /url\("?https?:/ );
		const video = page.locator( '.cg-embed[data-cg-provider="youtube"]' ).first();
		await expect( video ).toHaveCSS( 'background-color', 'rgb(16, 20, 24)' );
		expect( await video.locator( '.cg-embed__button' ).evaluate( ( el ) => getComputedStyle( el, '::before' ).maskImage || getComputedStyle( el, '::before' ).webkitMaskImage ) ).toContain( 'data:image/svg+xml' );
		await expect( page.locator( '.cg-embed[data-cg-provider="youtube"] .cg-embed__privacy a' ).first() ).toHaveAttribute( 'href', /youtube\.com|google\.com/ );
		await page.emulateMedia( { colorScheme: 'dark' } );
		await expect( video ).toHaveCSS( 'background-color', 'rgb(0, 0, 0)' );
		await page.emulateMedia( { colorScheme: 'light' } );

		await page.goto( '/withdraw-page/' );
		await expect( page.locator( 'button.cg-withdraw.cg-withdraw--outline[data-cg-withdraw]' ) ).toHaveCount( 1 );
	} finally {
		await page.goto( '/wp-admin/options-general.php?page=calucon-embed-gate#cg-tab-appearance' );
		await page.click( '#cg-appearance-reset' );
		await page.click( '#cg-tabbtn-providers' );
		await page.uncheck( 'input[name$="[display][privacy_link]"][type="checkbox"]' );
		await page.click( '#submit' );
		await expect( page.locator( '#setting-error-settings_updated, .notice-success' ).first() ).toBeVisible();
	}
	await page.goto( '/gated-classic/' );
	await expect( page.locator( '.cg-embed__privacy' ) ).toHaveCount( 0 );
} );

test( 'front end: per-embed block texts are stripped of markup and capped', async ( { page } ) => {
	await page.goto( '/per-embed-text/' );
	const panel = page.locator( '.cg-embed[data-cg-provider="vimeo"]' );
	await expect( panel.locator( '.cg-embed__button' ) ).toHaveText( 'Load the trailer' );
	await expect( panel.locator( '.cg-embed__button b' ) ).toHaveCount( 0 );
	const note = await panel.locator( '.cg-embed__note' ).textContent();
	expect( note.startsWith( 'Own notice.' ) ).toBe( true );
	expect( note ).not.toContain( '<em>' );
	expect( note.length ).toBe( 400 );
} );
