=== Foliora ===
Contributors: itsmanzur
Tags: pdf, pdf viewer, ebook, document viewer, gutenberg
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fast, accessible PDF viewer for WordPress. Bundled PDF.js, shortcode + block, no CDN.

== Description ==

Foliora embeds PDF documents in posts and pages using a bundled, locally hosted PDF.js build. There is no external rendering service, no CDN, and no API key.

**Free features**

* Shortcode: `[foliora file="https://example.com/document.pdf"]`
* Gutenberg “Foliora Viewer” block with a Media Library picker and first-page preview
* Page-by-page, continuous scroll, two-page spread, or realistic 3D FlipBook mode (with zero-asset Web Audio paper turn sound); zoom, fit page / fit width, rotate, find (N of M), print, download, fullscreen, and presentation mode
* Automatic oEmbed / direct PDF URL auto-embed: paste any .pdf URL to embed instantly
* WordPress standard attachment page auto-embed (configurable under Settings)
* Smart CORS error diagnostics and user-friendly fallback card with direct download link for cross-origin PDFs
* Optional title caption, start page, guest last-page resume, and `#page=4` deep links
* Hide download or print per embed, or site-wide under Foliora → Settings
* Text layer and annotation links so visitors can select text and follow PDF links
* Keyboard shortcuts (arrows, Page Up/Down, plus/minus, R to rotate, Home/End, Ctrl/Cmd+F, Escape)
* Password-protected PDFs, document outline, page thumbnails, pinch/swipe, and a hand/pan tool
* Streamlined, edge-to-edge responsive toolbar with a sleek More Tools (⋮) popup menu
* Dashboard with setup checklist and shortcode builder
* Documents library of PDFs already in the Media Library
* `[foliora_library]` shortcode and “Foliora Library” block: a grid of site PDFs with first-page thumbnails and search
* PDF text indexing so WordPress site search (and the library search) can find phrases inside embedded documents
* Open Graph / Twitter preview using the PDF’s first-page thumbnail when a page is shared
* Native Elementor, Divi, and Beaver Builder widgets for the viewer and the document library
* WPML / Polylang: translated Media Library PDFs swap automatically for the current language
* Caching-plugin exclusions so PDF.js is not minified, combined, or delayed
* Warns on Foliora → Documents when a PDF is not tagged for accessibility
* Bengali (bn_BD) translation bundled
* Live preview of default width and height on the settings screen

**Foliora Pro** (separate add-on) unlocks:

* EPUB reader mode
* Bookmarks and reading-progress sync for logged-in users
* Light / sepia / dark reader themes
* Password-protected and expiring share links
* WooCommerce paid-content gating
* Reading analytics
* Removal of the “Powered by Foliora” credit

== Installation ==

1. Upload the `foliora` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen. You will land on the Foliora dashboard.
3. Upload a PDF in Media, or open Foliora → Documents.
4. Copy a shortcode, create a test page, or insert the Foliora Viewer block.

== Frequently Asked Questions ==

= Does this send my documents to a third-party server? =

No. Rendering happens in the visitor’s browser with the PDF.js library bundled in this plugin.

= How do I enable 3D FlipBook mode? =

Use `[foliora file="https://example.com/document.pdf" view="flip"]` in shortcodes, choose "3D FlipBook" from the View dropdown in the Gutenberg block settings, or click "View Mode" inside the toolbar More Tools (⋮) menu.

= Does it support EPUB? =

EPUB rendering is a Foliora Pro feature. The free plugin handles PDF only.

= Shortcode or block — which should I use? =

They render the same viewer. Use the block in the editor if you want a file picker and first-page preview. Use `[foliora file="…"]` in classic editor, widgets, or PHP templates. Elementor, Divi, and Beaver Builder each have Foliora Viewer and Foliora Library widgets that use the same renderer.

= Does it work with WPML or Polylang? =

Yes. Translate the PDF in the Media Library (WPML Media Translation or Polylang’s media option). Foliora swaps the file URL to the current language’s attachment. You can also put a different PDF on each language’s page. `wpml-config.xml` ships with the plugin so the viewer block `file` and `title` attributes are translatable.

= The viewer is blank after enabling a cache plugin =

Foliora excludes its PDF.js scripts from minify/combine/delay in WP Rocket, W3 Total Cache, Autoptimize, LiteSpeed Cache, and SiteGround Optimizer, and marks those tags `data-no-optimize`. If another optimizer still concatenates them, add `foliora-viewer.js`, `pdf.min.js`, and `pdf.worker.min.js` to that plugin’s exclude list.

= A PDF loads on my own site but fails from another domain =

The file URL must allow the page origin to fetch it. Cross-origin PDFs need CORS headers (`Access-Control-Allow-Origin`) on the file host. Files in your own Media Library work without extra setup.

= Large PDFs feel slow =

Foliora draws the visible page (or a two-page spread). Continuous scroll lazy-renders nearby pages. Very large files still need to be downloaded once. Keep uploads reasonably sized when you can.

= How do I show a gallery of all my PDFs? =

Add `[foliora_library]` to a page, or insert the Foliora Library block. Visitors see a grid of Media Library PDFs and can open any document in the Foliora viewer without leaving the page. Optional attributes: `columns`, `per_page`, `orderby` (`date`, `title`, or `modified`), `order` (`ASC` or `DESC`), and `search` (`true` or `false`).

= How do library thumbnails get generated? =

The first time you open Foliora → Documents, the plugin draws each PDF’s first page in the browser (bundled PDF.js) and saves a PNG in the Media Library, parented to that PDF. After that, the gallery reuses the stored image. Visit Documents at least once per new PDF so its thumbnail can be created. Thumbnails are not regenerated on every page load.

= What does “Untagged” mean on Documents? =

The PDF has no accessibility tags (its MarkInfo dictionary is not marked). Screen readers may miss headings and reading order. Export a tagged PDF from Word, InDesign, or Acrobat. Foliora does not rewrite the file. You can turn the check off under Foliora → Settings → Search and sharing.

= Does Foliora include translations? =

Yes. A Bengali (`bn_BD`) translation ships in the plugin. If a language pack from WordPress.org is installed, that pack takes precedence.

= Does WordPress search find text inside PDFs? =

Yes. Opening Foliora → Documents extracts text from each PDF (same visit that generates thumbnails) and stores it so posts and pages that embed those files match in the site search. The document library search uses that text too. Password-protected or scanned image-only PDFs are skipped. Turn it off under Foliora → Settings → Search and sharing.

= Why does a shared page show a PDF cover image? =

If the page embeds a Foliora PDF and a first-page thumbnail exists, Foliora adds Open Graph and Twitter image tags using that cover. If Yoast, Rank Math, or a similar SEO plugin already set an image, Foliora does not override it. You can disable this under Settings.

= How do I hide download or print? =

Foliora → Settings has checkboxes for the site default. A single embed can override with `[foliora file="…" download="false" print="false"]`, or the matching toggles on the Foliora Viewer block.

= Can I open the viewer on a specific page? =

Yes. Use `[foliora file="…" page="4"]`, set Start page on the block, or link to the page with `#page=4`.

= How do I hide “Powered by Foliora”? =

That credit is part of the free plugin. Foliora Pro can turn it off under Foliora → Settings.

= Does the free plugin collect analytics? =

No. There is no tracking pixel, no remote API, and no phone-home. Foliora Pro has an optional reading-analytics feature; the free plugin does not.

== Developer Hooks ==

Foliora Pro (and any third-party add-on) extends the viewer through public WordPress filters and actions — no Pro code lives in this plugin. The full list, parameters, and copy-paste examples are in `docs/HOOKS.md` inside the plugin folder.

== Screenshots ==

1. Front-end viewer with a compact toolbar (page, zoom, find, download, print, fullscreen).
2. Foliora dashboard: setup checklist and shortcode builder.
3. Documents screen listing Media Library PDFs, with first-page preview thumbnails.
4. Settings with a live width/height preview of the viewer.
5. Gutenberg “Foliora Viewer” block with a Media Library picker and first-page canvas preview.
6. Front-end document library grid (`[foliora_library]`) expanding a PDF in place.

== Changelog ==

= 1.0.0 =
* Initial release: PDF viewer with bundled PDF.js, shortcode, and Gutenberg block.
* Page, continuous scroll, two-page spread, and 3D FlipBook mode with realistic paper turn sound effect.
* Direct PDF URL oEmbed auto-embed handler and attachment page auto-embed.
* Smart CORS error diagnostics and fallback card.
* Streamlined toolbar with More Tools (⋮) popup menu.
* Compact mobile toolbar, guest last-page resume, `#page=` deep links, and password-protected PDFs.
* Documents library, `[foliora_library]` grid, first-page thumbnails, and site search inside PDFs.
* Elementor, Divi, and Beaver Builder widgets; WPML / Polylang PDF swapping; Bengali (bn_BD) translation.
* Public hooks so Foliora Pro (and other add-ons) can extend the viewer without modifying this plugin.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
