// Owner-supplied poster images (PLAN.md §5.4, media-library variant): the
// poster is served from the site's own origin — it must never add a
// third-party request, must stay decorative, and must vanish on activation.
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

test( 'poster renders site-origin and decorative, with zero third-party requests', async ( { page } ) => {
	const offenders = trackThirdPartyRequests( page );

	await page.goto( '/page/poster' );
	await page.waitForLoadState( 'networkidle' );

	// The poster request itself happened — to the page's own host only.
	expect( offenders, 'a poster must never contact a third party' ).toEqual( [] );

	const container = page.locator( '.cg-embed--poster' );
	await expect( container ).toHaveCount( 1 );

	const poster = container.locator( 'img.cg-embed__poster' );
	await expect( poster ).toHaveCount( 1 );
	await expect( poster ).toHaveAttribute( 'alt', '' );
	await expect( poster ).toHaveAttribute( 'aria-hidden', 'true' );

	// The panel still offers everything the contract requires on top of the
	// image: note, real button, working fallback link.
	await expect( container.locator( '.cg-embed__note' ) ).toBeVisible();
	await expect( container.locator( '.cg-embed__button' ) ).toBeVisible();
	await expect( container.locator( '.cg-embed__fallback a' ) ).toBeVisible();
} );

test( 'a poster never overflows its reserved box — no dead scrollbar, any ratio, any width', async ( { page } ) => {
	for ( const path of [ '/page/poster', '/page/poster-mismatch' ] ) {
		for ( const width of [ 360, 800, 1280 ] ) {
			await page.setViewportSize( { width, height: 800 } );
			await page.goto( path );
			await page.waitForLoadState( 'networkidle' );
			const box = page.locator( '.cg-embed--poster' );
			const metrics = await box.evaluate( ( el ) => ( {
				sh: el.scrollHeight, ch: el.clientHeight, sw: el.scrollWidth, cw: el.clientWidth,
				img: el.querySelector( '.cg-embed__poster' ).getBoundingClientRect().height,
			} ) );
			expect( metrics.sh, `${ path } @${ width }: vertical overflow` ).toBeLessThanOrEqual( metrics.ch );
			expect( metrics.sw, `${ path } @${ width }: horizontal overflow` ).toBeLessThanOrEqual( metrics.cw );
			expect( Math.abs( metrics.img - metrics.ch ), `${ path } @${ width }: poster fills the box` ).toBeLessThanOrEqual( 1 );
		}
	}
} );

test( 'activation removes the poster along with the panel', async ( { page } ) => {
	await page.route( '**', ( route ) => {
		const host = new URL( route.request().url() ).hostname;
		return OWN_HOSTS.includes( host ) ? route.continue() : route.abort();
	} );

	await page.goto( '/page/poster' );

	const container = page.locator( '.cg-embed--poster' );
	await container.locator( '.cg-embed__button' ).click();

	await expect( container.locator( 'iframe' ) ).toHaveCount( 1 );
	// The image must not linger under (or grid-stacked, over) the embed.
	await expect( container.locator( 'img.cg-embed__poster' ) ).toHaveCount( 0 );
	await expect( container.locator( '.cg-embed__panel' ) ).toHaveCount( 0 );
} );
