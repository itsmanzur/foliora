const { test, expect } = require( '@playwright/test' );

test.describe( 'Foliora viewer', () => {
	test( 'renders toolbar controls for an embedded PDF', async ( { page } ) => {
		await page.goto( '/foliora-e2e-viewer/' );
		await expect( page.locator( '.foliora-viewer' ) ).toBeVisible();
		await expect( page.locator( '.foliora-toolbar' ) ).toBeVisible();
		await expect( page.locator( '.foliora-rotate' ) ).toBeVisible();
		await expect( page.locator( '.foliora-fit' ) ).toBeVisible();
		await expect( page.locator( '.foliora-page-input' ) ).toBeVisible();
	} );
} );
