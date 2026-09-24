const { test, expect } = require( '@playwright/test' );

test.describe( 'Foliora library', () => {
	test( 'shows a grid of Media Library PDFs', async ( { page } ) => {
		await page.goto( '/foliora-e2e-library/' );
		await expect( page.locator( '.foliora-library' ) ).toBeVisible();
		await expect( page.locator( '.foliora-library-item' ).first() ).toBeVisible();
		await expect( page.locator( '.foliora-library-search' ) ).toBeVisible();
	} );
} );
