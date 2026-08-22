/**
 * Generate the WordPress.org listing screenshots from a running WordPress.
 *
 * Boot the same backend the WP integration suite uses, then run this:
 *
 *   bash tests/wp/serve-playground.sh &      # or npm run test:wp backend
 *   node bin/capture-screenshots.cjs
 *
 * Writes .wordpress-org/screenshot-{1..5}.png, matching the readme's
 * == Screenshots == captions in order (the fifth drives a real editing
 * session for the block-inspector control). Not part of CI — this is a
 * one-off asset generator.
 */
const { chromium } = require( '@playwright/test' );
const path = require( 'path' );

const BASE = process.env.WP_BASE_URL || 'http://127.0.0.1:8890';
const OUT = path.join( __dirname, '..', '.wordpress-org' );

async function login( page ) {
	await page.goto( BASE + '/wp-login.php', { waitUntil: 'networkidle' } );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );
}

async function settings( page ) {
	await page.goto( BASE + '/wp-admin/options-general.php?page=calucon-embed-gate', { waitUntil: 'networkidle' } );
	await page.waitForSelector( '.cg-tabs' );
	// The fixed admin bar rides along while Playwright scrolls to stitch an
	// element taller than the viewport and ends up pasted mid-image.
	await page.addStyleTag( { content: '#wpadminbar{display:none!important}html.wp-toolbar{padding-top:0!important}' } );
}

( async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage( { viewport: { width: 1360, height: 1000 }, deviceScaleFactor: 2 } );

	// 1 — front-end placeholder (the product in one image). Use the
	// no-poster page so no media-library image is expected (Playground has
	// none, which would render a broken-image icon).
	await page.goto( BASE + '/gated-classic/', { waitUntil: 'networkidle' } );
	const embed = page.locator( '.cg-embed' ).first();
	await embed.scrollIntoViewIfNeeded();
	await embed.screenshot( { path: path.join( OUT, 'screenshot-1.png' ) } );

	await login( page );

	// 2 — Appearance: pickers + live preview + contrast report.
	await settings( page );
	await page.click( '#cg-tabbtn-appearance' );
	// The advanced sections start collapsed; open them so the listing image
	// shows what is there.
	await page.evaluate( () => document.querySelectorAll( '#cg-tab-appearance details.cg-section' ).forEach( ( d ) => { d.open = true; } ) );
	// Show the 0.10 controls doing something in the live preview instead of
	// an all-default form: nothing is saved, the preview mirrors the form.
	const choose = async ( id, value ) => {
		await page.evaluate( ( [ cid, v ] ) => {
			const radio = document.querySelector( `#${ cid } input[type="radio"][value="${ v }"]` );
			if ( radio ) { radio.checked = true; radio.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }
		}, [ id, value ] );
	};
	await choose( 'cg-corners', 'custom' );
	await page.fill( '#cg-radius', '16' );
	await page.fill( '#cg-border-width', '2' );
	await page.evaluate( () => window.jQuery( '#cg-color-border-color' ).wpColorPicker( 'color', '#5c9e00' ) );
	await page.check( '#cg-play-icon' );
	await choose( 'cg-withdraw-style', 'outline' );
	// The preview column is sticky on desktop; a scroll-and-stitch element
	// capture would paste it mid-image. Unstick it for the shot only.
	// The sticky unsaved-changes bar would be pasted mid-image the same way.
	await page.addStyleTag( { content: '.cg-appearance-preview{position:static!important}#cg-unsaved{display:none!important}' } );
	// No hover/focus highlight or open menus in the listing image.
	await page.mouse.move( 0, 0 );
	await page.evaluate( () => {
		if ( document.activeElement ) { document.activeElement.blur(); }
		document.querySelectorAll( '.cg-preview-hl' ).forEach( ( el ) => el.classList.remove( 'cg-preview-hl' ) );
		document.querySelectorAll( '.cg-color[open]' ).forEach( ( el ) => el.removeAttribute( 'open' ) );
	} );
	await page.waitForTimeout( 700 );
	await page.locator( '#cg-tab-appearance' ).screenshot( { path: path.join( OUT, 'screenshot-2.png' ) } );

	// 3 — Compatibility overview (under Status & tools).
	await page.click( '#cg-tabbtn-status' );
	await page.evaluate( () => { document.getElementById( 'cg-csp' ).open = true; } );
	await page.waitForTimeout( 500 );
	await page.locator( '#cg-tab-status' ).screenshot( { path: path.join( OUT, 'screenshot-3.png' ) } );

	// 4 — Providers tab. The full table is very tall (20+ providers); clip to
	// the top so the shot shows the columns and the first several providers.
	await page.click( '#cg-tabbtn-providers' );
	await page.waitForTimeout( 400 );
	const box = await page.locator( '#cg-tab-providers' ).boundingBox();
	await page.screenshot( {
		path: path.join( OUT, 'screenshot-4.png' ),
		clip: { x: box.x, y: box.y, width: box.width, height: Math.min( box.height, 900 ) },
	} );

	// 5 — the per-block control in the block editor. A core/html block with
	// an iframe needs no oEmbed fetch (Playground has no network), and it is
	// one of the gated block types, so the inspector panel attaches to it.
	await page.goto( BASE + '/wp-admin/post-new.php', { waitUntil: 'networkidle' } );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.blocks && window.wp.data.select( 'core/block-editor' ) );
	await page.evaluate( () => {
		try {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		} catch ( e ) {}
		try {
			var editPost = window.wp.data.select( 'core/edit-post' );
			if ( editPost && editPost.isFeatureActive && editPost.isFeatureActive( 'welcomeGuide' ) ) {
				window.wp.data.dispatch( 'core/edit-post' ).toggleFeature( 'welcomeGuide' );
			}
		} catch ( e ) {}
		var block = window.wp.blocks.createBlock( 'core/html', {
			content: '<iframe width="560" height="315" src="https://www.youtube.com/embed/y_pjE_p1HwE" title="Kolkja Cycling" frameborder="0" allowfullscreen></iframe>'
		} );
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
		window.wp.data.dispatch( 'core/block-editor' ).selectBlock( block.clientId );
	} );
	await page.waitForTimeout( 1500 );
	// The store action that opens the block inspector has moved between
	// WordPress versions; the sidebar's own "Block" tab is stable UI.
	const guide = page.getByRole( 'dialog', { name: /welcome/i } );
	await guide.waitFor( { timeout: 8000 } ).catch( () => {} );
	if ( await guide.isVisible() ) {
		await guide.getByRole( 'button', { name: /close/i } ).first().click();
		await guide.waitFor( { state: 'hidden' } );
	}
	const sidebarEl = page.locator( '.interface-interface-skeleton__sidebar' );
	if ( ! ( await sidebarEl.isVisible() ) ) {
		await page.locator( '.editor-header__settings, .edit-post-header__settings' ).getByRole( 'button', { name: 'Settings', exact: true } ).click();
		await sidebarEl.waitFor();
	}
	const blockTab = sidebarEl.getByRole( 'tab', { name: 'Block' } );
	if ( await blockTab.count() ) {
		await blockTab.click();
	} else {
		await page.locator( '.interface-interface-skeleton__sidebar' ).getByRole( 'button', { name: 'Block', exact: true } ).click();
	}
	await page.waitForTimeout( 800 );
	// WordPress 7.x opens the inspector on a "List View" tab for some blocks;
	// inspector panels live under "Settings".
	const settingsTab = page.locator( '.interface-interface-skeleton__sidebar' ).getByRole( 'tab', { name: 'Settings' } );
	await settingsTab.waitFor( { timeout: 15000 } ).catch( () => {} );
	if ( await settingsTab.isVisible() ) {
		await settingsTab.click();
		await page.waitForTimeout( 500 );
	}
	const panelToggle = page.getByRole( 'button', { name: 'Calucon Third-Party Embed Gate' } ).first();
	await panelToggle.waitFor( { timeout: 15000 } );
	if ( 'false' === await panelToggle.getAttribute( 'aria-expanded' ) ) {
		await panelToggle.click();
	}
	await page.waitForTimeout( 600 );
	await page.locator( '.interface-interface-skeleton__sidebar' ).screenshot( { path: path.join( OUT, 'screenshot-5.png' ) } );

	await browser.close();
	console.log( 'Wrote screenshot-1..5.png to .wordpress-org/' );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
