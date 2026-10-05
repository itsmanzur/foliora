# Foliora developer hooks

Foliora (free) is a complete PDF viewer. Foliora Pro is a **separate** plugin. The free plugin never ships Pro code; it only exposes WordPress filters and actions. Pro — or any third-party add-on — hooks those same points to replace rendering, inject a toolbar, hide branding, or gate licensed features.

You can build your own “Foliora Pro”-style extension with this public contract. You do not need undocumented internals.

Hook names use a slash namespace (`foliora/did_thing`) by design. All live PHP hooks follow that style.

**Viewer pipeline (in order):** `foliora/viewer_atts` → empty-file / EPUB-lock checks → `foliora/pre_render_viewer` → `foliora/before_viewer` → `foliora/show_branding` → `foliora/viewer_html`.

---

## `foliora/loaded`

**Type:** action
**Fired from:** `Foliora::run()` in `includes/class-foliora.php`

Runs once after the free plugin has constructed its viewer, thumbnails, library, block, and (on admin screens) admin classes, and after each of those has `init()`’d.

**Parameters**

None.

Use this when your add-on needs Foliora’s classes to already exist, instead of racing `plugins_loaded`.

```php
add_action( 'foliora/loaded', function () {
	if ( class_exists( 'Foliora_Viewer' ) ) {
		// Safe to call Foliora APIs or enqueue companion assets.
	}
} );
```

---

## `foliora/pro_loaded`

**Type:** action (fired by the add-on, **consumed** by free)
**Consumed in:** `Foliora_Loader::is_pro_active()` via `did_action( 'foliora/pro_loaded' )`

The free plugin does **not** fire this. A Pro-style add-on must fire it on `plugins_loaded` **before** Foliora boots (Foliora free runs at priority `20`). Also define `FOLIORA_PRO_VERSION`. Both are required for `Foliora_Loader::is_pro_active()` to return true (unless overridden by `foliora/is_pro_active`).

```php
add_action( 'plugins_loaded', function () {
	if ( ! defined( 'FOLIORA_PRO_VERSION' ) ) {
		define( 'FOLIORA_PRO_VERSION', '1.0.0' );
	}
	do_action( 'foliora/pro_loaded' );
}, 5 );
```

---

## `foliora/is_pro_active`

**Type:** filter
**Fired from:** `Foliora_Loader::is_pro_active()` in `includes/class-foliora-loader.php`

Overrides Pro detection. The default is `defined( 'FOLIORA_PRO_VERSION' ) && did_action( 'foliora/pro_loaded' )`.

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$pro_confirmed` | `bool` | Built-in detection result |

The result is **cached** on the first call for the rest of the request. Hook this filter before anything that calls `Foliora_Loader::is_pro_active()` (for example on `plugins_loaded` priority `15`, before Foliora’s `20`).

```php
add_filter( 'foliora/is_pro_active', function ( $pro_confirmed ) {
	// Force “Pro UI” on a staging site without the add-on installed.
	if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE ) {
		return true;
	}
	return $pro_confirmed;
} );
```

---

## `foliora/feature_enabled`

**Type:** filter
**Fired from:** `Foliora_Loader::is_feature_enabled()` in `includes/class-foliora-loader.php`

Per-feature license gate. If Pro is not active, this filter never runs — the method returns `false`. If Pro **is** active, the default passed in is `true`; your add-on should return `false` for features the current license does not include.

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$enabled` | `bool` | Default `true` when Pro is active |
| `$feature_key` | `string` | One of: `epub_reader`, `bookmarks`, `reader_themes`, `protected_links`, `woocommerce_gate`, `analytics`, `remove_branding` |

```php
add_filter( 'foliora/feature_enabled', function ( $enabled, $feature_key ) {
	$licensed = array( 'bookmarks', 'reader_themes', 'remove_branding' );
	return in_array( $feature_key, $licensed, true );
}, 10, 2 );
```

---

## `foliora/upgrade_url`

**Type:** filter
**Fired from:** `Foliora_Loader::upgrade_url()` in `includes/class-foliora-loader.php`

URL used by locked-feature UI (“Upgrade to Pro”).

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$url` | `string` | Default `https://thereadscope.com/foliora/pricing` |

```php
add_filter( 'foliora/upgrade_url', function ( $url ) {
	return 'https://example.com/your-addon/pricing';
} );
```

---

## `foliora/viewer_atts`

**Type:** filter
**Fired from:** `Foliora_Viewer::render_shortcode()` in `includes/class-foliora-viewer.php`

Runs immediately after `shortcode_atts()` (defaults from Foliora → Settings for width/height). Swap `file`, `width`, `height`, or `mode` without replacing the whole viewer. Empty-file and EPUB-lock checks run **after** this filter, so you can inject a missing file URL or change `mode`.

Used by `[foliora]` and by anything that calls `render_shortcode()` (the viewer block, the library’s in-place embeds).

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$atts` | `array` | Keys: `file` (URL), `width`, `height`, `mode` (`pdf` or `epub`), `page`, `title`, `download`, `print`, `hash`, `view` (`page`, `scroll`, or `spread`), `resume` |

Return an array. Non-array values are discarded and treated as empty attributes.

```php
add_filter( 'foliora/viewer_atts', function ( $atts ) {
	if ( function_exists( 'pll_current_language' ) && 'fr' === pll_current_language() ) {
		$atts['file'] = 'https://example.com/docs/guide-fr.pdf';
	}
	return $atts;
} );
```

The free plugin already swaps Media Library attachments to the current WPML / Polylang translation (priority `5` on this same filter). Hook at `10` or later to override that.

---

## `foliora/translate_attachment_id`

**Type:** filter
**Fired from:** `Foliora_Compat::translate_attachment_id()` in `includes/class-foliora-compat.php`

Last chance to pick a different PDF attachment for the current language.

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$attachment_id` | `int` | ID after WPML / Polylang |
| `$original` | `int` | ID parsed from the file URL |

---

## `foliora/pre_render_viewer`

**Type:** filter
**Fired from:** `Foliora_Viewer::render_shortcode()` in `includes/class-foliora-viewer.php`

Return any non-`null` value to **replace** the default PDF.js markup entirely (EPUB viewer, a custom canvas, etc.). Returning `null` (the default) lets the free plugin render.

If you only need to change the file URL or wrap the HTML, use `foliora/viewer_atts` or `foliora/viewer_html` instead.

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$output` | `string\|null` | Default `null` (free plugin renders) |
| `$atts` | `array` | Attributes after `foliora/viewer_atts` |

Non-null output still passes through `foliora/viewer_html` before it is returned.

```php
add_filter( 'foliora/pre_render_viewer', function ( $output, $atts ) {
	if ( isset( $atts['mode'] ) && 'epub' === $atts['mode'] ) {
		return '<div class="my-epub-viewer" data-file="' . esc_url( $atts['file'] ) . '"></div>';
	}
	return $output;
}, 10, 2 );
```

---

## `foliora/before_viewer`

**Type:** action
**Fired from:** `Foliora_Viewer::render_shortcode()` in `includes/class-foliora-viewer.php`

Fires only on the free PDF path (skipped when `pre_render_viewer` short-circuits). Echo markup; it is captured and inserted into `.foliora-chrome` **before** the free toolbar.

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$instance_id` | `string` | Unique viewer element id (`foliora-viewer-{n}`) |
| `$atts` | `array` | Resolved attributes |

```php
add_action( 'foliora/before_viewer', function ( $instance_id, $atts ) {
	echo '<div class="my-reader-bar" data-for="' . esc_attr( $instance_id ) . '">';
	echo '<button type="button">' . esc_html__( 'Bookmark', 'my-foliora-addon' ) . '</button>';
	echo '</div>';
}, 10, 2 );
```

---

## `foliora/show_branding`

**Type:** filter
**Fired from:** `Foliora_Viewer::render_shortcode()` in `includes/class-foliora-viewer.php`

Whether to print the “Powered by Foliora” credit under the viewer. Default `true`. Foliora Pro typically returns `false` when the site has the `remove_branding` feature and the matching setting enabled.

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$show` | `bool` | Default `true` |

```php
add_filter( 'foliora/show_branding', function ( $show ) {
	return false;
} );
```

---

## `foliora/viewer_html`

**Type:** filter
**Fired from:** `Foliora_Viewer::render_shortcode()` in `includes/class-foliora-viewer.php`

Last chance to wrap or post-process the finished viewer HTML. Applied to the free plugin’s container **and** to any non-`null` `foliora/pre_render_viewer` return. Not applied to the empty-file admin notice or the locked-EPUB notice.

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$html` | `string` | Rendered markup |
| `$atts` | `array` | Resolved attributes |

Escape anything you concatenate. Return valid HTML.

```php
add_filter( 'foliora/viewer_html', function ( $html, $atts ) {
	$label = isset( $atts['file'] ) ? basename( wp_parse_url( $atts['file'], PHP_URL_PATH ) ) : '';
	return '<figure class="my-doc"><figcaption>' . esc_html( $label ) . '</figcaption>' . $html . '</figure>';
}, 10, 2 );
```

---

## Related: DOM event `foliora:pagechange`

This is **not** a WordPress hook. The front-end viewer dispatches a bubbling `CustomEvent` on the `.foliora-viewer` element whenever the current page changes (`assets/js/foliora-viewer.js`).

`event.detail` is `{ page: number, numPages: number }`. Useful for reading-progress UIs without replacing the renderer.

```js
document.addEventListener( 'foliora:pagechange', function ( event ) {
	console.log( event.detail.page, 'of', event.detail.numPages );
} );
```

---

## `foliora/extracted_text`

**Type:** filter
**Fired from:** `Foliora_SEO::ajax_save_extracted_text()` in `includes/class-foliora-seo.php`

Alters plain text extracted from a PDF before it is stored on the attachment. Runs after sanitizing and clipping to `foliora/extracted_text_max_chars` (default 80,000).

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$text` | `string` | Sanitized PDF text |
| `$pdf_id` | `int` | Attachment ID |

```php
add_filter( 'foliora/extracted_text', function ( $text, $pdf_id ) {
	return str_replace( 'CONFIDENTIAL', '', $text );
}, 10, 2 );
```

---

## `foliora/search_index`

**Type:** filter
**Fired from:** `Foliora_SEO` when rebuilding `_foliora_search_index` on a post/page

Combined text copied onto a host post so core search can match PDF contents.

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$blob` | `string` | Combined extracted text |
| `$post_id` | `int` | Host post ID |
| `$ids` | `int[]` | Embedded PDF attachment IDs |

---

## `foliora/og_image`

**Type:** filter
**Fired from:** `Foliora_SEO` when resolving a social-card image

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$url` | `string` | First-page thumbnail URL, or empty |
| `$post_id` | `int` | Host post ID |
| `$pdf_id` | `int` | First embedded PDF attachment ID |

---

## `foliora/extracted_text_max_chars`

**Type:** filter
**Fired from:** `Foliora_SEO` when clipping extracted text

Default `80000`. Return a positive integer.

---

## Naming

Every **live** `apply_filters()` / `do_action()` in this plugin uses slash-style names (`foliora/…`). There is no live underscore-style `foliora_*` hook.

A commented-out Freemius stub in `foliora.php` contains `do_action( 'foliora_fs_loaded' )`. That line is not executed.

The library shortcode (`[foliora_library]`) still goes through the viewer hooks above when it renders an embed. Thumbnail and text-index AJAX have no additional PHP filters beyond `foliora/extracted_text`. Tagged-PDF detection is stored via `foliora_save_a11y` and can be overridden with `foliora/pdf_tagged`.

---

## `foliora/pdf_tagged`

**Type:** filter
**Fired from:** `Foliora_A11y::is_tagged()` in `includes/class-foliora-a11y.php`

Overrides whether an attachment is treated as a tagged (accessible) PDF. The default is the MarkInfo `/Marked` flag stored from Foliora → Documents.

**Parameters**

| Name | Type | Meaning |
|------|------|---------|
| `$tagged` | `bool` | Stored MarkInfo result |
| `$attachment_id` | `int` | PDF attachment ID |

```php
add_filter( 'foliora/pdf_tagged', function ( $tagged, $attachment_id ) {
	if ( 123 === (int) $attachment_id ) {
		return true;
	}
	return $tagged;
}, 10, 2 );
```
