// Accessibility scan (PLAN.md §10.4): axe-core on the gated pages at a
// narrow and a wide viewport, failing the build on any violation.
// @ts-check
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const PAGES = [ '/page/gated', '/page/scripts', '/page/aspect', '/page/light', '/page/shapes', '/page/collision', '/page/poster', '/page/custom-provider' ];
const VIEWPORTS = [
	{ width: 360, height: 740 },
	{ width: 1280, height: 800 },
];

for ( const path of PAGES ) {
	for ( const viewport of VIEWPORTS ) {
		test( `axe: ${ path } at ${ viewport.width }px`, async ( { page } ) => {
			await page.setViewportSize( viewport );
			await page.goto( path );
			// Guard: axe finds nothing on an error page either — require the
			// panels this scan exists to audit.
			await expect( page.locator( '.cg-embed' ).first() ).toBeVisible();

			const results = await new AxeBuilder( { page } ).analyze();

			expect(
				results.violations.map( ( v ) => ( {
					id: v.id,
					impact: v.impact,
					nodes: v.nodes.map( ( n ) => n.target ),
				} ) )
			).toEqual( [] );
		} );
	}
}
