// The headline test (PLAN.md §10.3): the entire product is that nothing
// third-party loads before the click. This file is never skipped and never
// marked flaky — if it is red, the product claim is false.
// @ts-check
const { test, expect } = require( '@playwright/test' );

const OWN_HOSTS = [ '127.0.0.1', 'localhost' ];

function trackThirdPartyRequests( page ) {
	const offenders = [];
	page.on( 'request', ( request ) => {
		const host = new URL( request.url() ).hostname;
		if ( ! OWN_HOSTS.includes( host ) ) {
			offenders.push( request.url() );
		}
	} );
	return offenders;
}

test( 'zero third-party requests before interaction', async ( { page } ) => {
	const offenders = trackThirdPartyRequests( page );

	await page.goto( '/page/gated' );
	await page.waitForLoadState( 'networkidle' );

	expect( offenders, 'INVARIANT 1 VIOLATED — third-party requests before any click' ).toEqual( [] );

	// Four cross-origin embeds gated, the same-origin iframe untouched.
	await expect( page.locator( '.cg-embed' ) ).toHaveCount( 4 );
	await expect( page.locator( 'iframe[src="/frame.html"]' ) ).toHaveCount( 1 );
	expect( await page.locator( 'iframe' ).count() ).toBe( 1 );
} );

test( 'the privacy-policy link is offered before the click and leaves with the panel', async ( { page } ) => {
	await page.goto( '/page/gated' );
	const container = page.locator( '.cg-embed' ).first();
	await expect( container ).toBeVisible();

	// Before any click: a plain link to the provider's policy — informing
	// the visitor is free, no request happens unless they follow it.
	const privacy = container.locator( '.cg-embed__privacy a' );
	await expect( privacy ).toHaveAttribute( 'href', /^https:\/\// );

	await page.route( '**', ( route ) => {
		const host = new URL( route.request().url() ).hostname;
		return [ '127.0.0.1', 'localhost' ].includes( host ) ? route.continue() : route.abort();
	} );
	await container.locator( '.cg-embed__button' ).click();

	// After activation the panel — privacy link included — is gone.
	await expect( container.locator( '.cg-embed__privacy' ) ).toHaveCount( 0 );
} );

test( 'nothing is stored before consent', async ( { page } ) => {
	// Invariant 3: the plugin itself must not write to terminal equipment.
	await page.goto( '/page/gated' );
	await page.waitForLoadState( 'networkidle' );
	// Guard: empty storage is also true of an error page — prove the gate
	// actually rendered before the negative assertions mean anything.
	await expect( page.locator( '.cg-embed' ).first() ).toBeVisible();

	const storage = await page.evaluate( () => ( {
		localStorage: window.localStorage.length,
		sessionStorage: window.sessionStorage.length,
		cookies: document.cookie,
	} ) );

	expect( storage ).toEqual( { localStorage: 0, sessionStorage: 0, cookies: '' } );
} );

test( 'click inserts the iframe, with safelisted attributes only, and moves focus to the container', async ( { page } ) => {
	// After the click the request to the provider is legitimate — but this
	// test only checks the built node, so abort those requests at the router.
	await page.route( '**', ( route ) => {
		const host = new URL( route.request().url() ).hostname;
		return OWN_HOSTS.includes( host ) ? route.continue() : route.abort();
	} );

	await page.goto( '/page/gated' );

	const first = page.locator( '.cg-embed' ).first();
	const button = first.locator( '.cg-embed__button' );

	// WCAG 2.5.8: hit area at least 24×24 CSS px.
	const box = await button.boundingBox();
	expect( box.width ).toBeGreaterThanOrEqual( 24 );
	expect( box.height ).toBeGreaterThanOrEqual( 24 );

	await button.click();

	const frame = first.locator( 'iframe' );
	await expect( frame ).toHaveCount( 1 );
	// Data minimisation: the post-consent load goes to the privacy-preserving
	// host (measured 0 cookies vs 5 on the default host).
	await expect( frame ).toHaveAttribute( 'src', 'https://www.youtube-nocookie.com/embed/y_pjE_p1HwE' );
	await expect( frame ).toHaveAttribute( 'title', 'Kolkja Cycling' );
	await expect( frame ).toHaveAttribute( 'allowfullscreen', '' );
	// Invariant 8: autoplay never survives the rebuild.
	await expect( frame ).toHaveAttribute( 'allow', 'accelerometer; encrypted-media' );
	// Invariant 7 / §5.2: style must not be carried over.
	await expect( frame ).not.toHaveAttribute( 'style', /./ );

	// §8: focus lands on the container, never falls back to <body>.
	const focused = await page.evaluate( () => document.activeElement && document.activeElement.className );
	expect( String( focused ) ).toContain( 'cg-embed' );

	// The panel is gone; the other embeds stay gated.
	await expect( first.locator( '.cg-embed__panel' ) ).toHaveCount( 0 );
	await expect( page.locator( '.cg-embed__panel' ) ).toHaveCount( 3 );
} );

test( 'placeholder works with JavaScript disabled: real fallback link, still zero third-party requests', async ( { browser } ) => {
	const context = await browser.newContext( { javaScriptEnabled: false } );
	const page = await context.newPage();
	const offenders = trackThirdPartyRequests( page );

	await page.goto( '/page/gated' );

	// Invariant 2: a visitor without JavaScript gets a real, working link —
	// a human page (watch URL), not an embed endpoint.
	const link = page.locator( '.cg-embed__fallback a' ).first();
	await expect( link ).toBeVisible();
	await expect( link ).toHaveAttribute( 'href', 'https://www.youtube.com/watch?v=y_pjE_p1HwE' );
	await expect( link ).toHaveAttribute( 'rel', 'noopener nofollow' );

	expect( offenders ).toEqual( [] );
	await context.close();
} );

test( 'owner-defined providers: zero third-party requests, built-ins keep their hosts, a disabled custom row still gates', async ( { page } ) => {
	const offenders = trackThirdPartyRequests( page );

	await page.goto( '/page/custom-provider' );
	await page.waitForLoadState( 'networkidle' );

	expect( offenders, 'INVARIANT 1 VIOLATED — third-party requests before any click' ).toEqual( [] );

	// The unknown widget is named by the custom row — and gated although its
	// row is "disabled" in the options (custom providers are always gated).
	const widget = page.locator( '.cg-embed[data-cg-host="widgets.example-partner.com"]' );
	await expect( widget ).toHaveAttribute( 'data-cg-provider', 'custom-example-partner' );
	await expect( widget.locator( 'button' ) ).toContainText( 'Load content from Example Partner' );
	// The row that tried to claim YouTube's hosts changed nothing.
	const video = page.locator( '.cg-embed[data-cg-provider="youtube"]' );
	await expect( video ).toHaveCount( 1 );
	await expect( page.locator( '.cg-embed[data-cg-provider="custom-tube-thief"]' ) ).toHaveCount( 0 );
	// The script-strategy custom row gates its SDK.
	await expect( page.locator( '.cg-embed[data-cg-provider="custom-widget-sdk"]' ) ).toHaveCount( 1 );
	await expect( page.locator( 'iframe' ) ).toHaveCount( 0 );
	await expect( page.locator( 'script[src*="widget-sdk"]' ) ).toHaveCount( 0 );

	// Activation still goes to the privacy-preserving host for the built-in.
	await page.route( '**/*', ( route ) => ( route.request().url().startsWith( 'http://127.0.0.1' ) ? route.continue() : route.fulfill( { contentType: 'text/html', body: '<p>frame</p>' } ) ) );
	await video.locator( 'button' ).click();
	await expect( video.locator( 'iframe' ) ).toHaveAttribute( 'src', /youtube-nocookie\.com/ );
} );
