/**
 * Foliora admin: dashboard shortcode builder, documents copy,
 * and settings live preview.
 */
( function () {
	'use strict';

	var config = window.FolioraAdmin || {};

	function $( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function $$( selector, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) );
	}

	function onReady( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function copiedLabel() {
		return ( config.i18n && config.i18n.copied ) || 'Copied!';
	}

	function post( action, data, onOk, onErr ) {
		var body = new window.URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', config.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.set( key, data[ key ] );
		} );

		window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} )
			.then( function ( res ) {
				return res.text();
			} )
			.then( function ( text ) {
				var clean = String( text || '' ).replace( /^\uFEFF+/g, '' ).trim();
				var start = clean.indexOf( '{' );
				if ( start > 0 ) {
					clean = clean.slice( start );
				}
				return JSON.parse( clean );
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					onOk( json.data || {} );
				} else if ( onErr ) {
					onErr( json && json.data ? json.data : {} );
				}
			} )
			.catch( function () {
				if ( onErr ) {
					onErr( {} );
				}
			} );
	}

	function t( key, fallback ) {
		return ( config.i18n && config.i18n[ key ] ) ? config.i18n[ key ] : fallback;
	}

	function toast( message ) {
		var el = $( '#foliora-toast' );
		if ( ! el || ! message ) {
			return;
		}
		el.textContent = message;
		el.hidden = false;
		el.classList.add( 'is-on' );
		window.clearTimeout( toast._timer );
		toast._timer = window.setTimeout( function () {
			el.classList.remove( 'is-on' );
		}, 1800 );
	}

	function flashCopied( button ) {
		var original = button ? button.textContent : '';
		if ( button ) {
			button.textContent = copiedLabel();
			window.setTimeout( function () {
				button.textContent = original;
			}, 1500 );
		}
		var code = $( '#foliora-code' );
		if ( code ) {
			code.classList.add( 'is-flash' );
			window.setTimeout( function () {
				code.classList.remove( 'is-flash' );
			}, 700 );
		}
		toast( t( 'copiedToast', 'Shortcode copied' ) );
	}

	function copyText( text, button, onCopied ) {
		if ( ! text ) {
			return;
		}
		var done = function () {
			flashCopied( button );
			if ( onCopied ) {
				onCopied();
			}
		};

		var fallbackCopy = function () {
			try {
				var textarea = document.createElement( 'textarea' );
				textarea.value = text;
				textarea.setAttribute( 'readonly', '' );
				textarea.style.position = 'fixed';
				textarea.style.left = '-9999px';
				textarea.style.top = '0';
				textarea.style.opacity = '0';
				document.body.appendChild( textarea );
				textarea.focus();
				textarea.select();
				var successful = document.execCommand( 'copy' );
				document.body.removeChild( textarea );
				if ( successful ) {
					done();
					return;
				}
			} catch ( err ) {
				// Fallback failed.
			}
			done();
		};

		if ( window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
			navigator.clipboard
				.writeText( text )
				.then( done )
				.catch( function () {
					fallbackCopy();
				} );
		} else {
			fallbackCopy();
		}
	}

	function markCopiedStep() {
		post( 'foliora_mark_setup_copied', {}, function () {
			var step = $( '[data-setup-step="copied"]' );
			if ( step ) {
				step.classList.add( 'is-done' );
			}
			refreshSetupUi();
		} );
	}

	function refreshSetupUi() {
		var items = $$( '.foliora-setup li' );
		if ( ! items.length ) {
			return;
		}
		var done = items.filter( function ( li ) {
			return li.classList.contains( 'is-done' );
		} ).length;
		var total = items.length;
		var complete = done === total;
		var bar = $( '.foliora-progress span' );
		if ( bar ) {
			bar.style.width = Math.round( ( done / total ) * 100 ) + '%';
		}
		var metric = $( '#foliora-metric-setup .foliora-metric-value' );
		if ( metric ) {
			metric.textContent = done + '/' + total;
		}
		var ringFg = $( '#foliora-ring-fg' );
		var ringLabel = $( '#foliora-ring-label' );
		var ringWrap = $( '#foliora-ring-wrap' );
		if ( ringFg ) {
			ringFg.setAttribute( 'stroke-dasharray', Math.round( ( done / total ) * 100 ) + ' 100' );
		}
		if ( ringLabel ) {
			ringLabel.textContent = done + '/' + total;
		}
		if ( ringWrap ) {
			ringWrap.classList.toggle( 'is-complete', complete );
		}
		var panel = $( '#foliora-setup-panel' );
		var status = $( '#foliora-setup-status' );
		if ( panel ) {
			panel.classList.toggle( 'is-complete', complete );
		}
		if ( status ) {
			if ( complete && config.i18n && config.i18n.setupDone ) {
				status.textContent = config.i18n.setupDone;
			} else if ( config.i18n && config.i18n.stepsComplete ) {
				status.textContent = config.i18n.stepsComplete
					.replace( '%1$s', String( done ) )
					.replace( '%2$s', String( total ) );
			}
		}
	}

	function pulsePanel( id ) {
		var panel = $( id );
		if ( ! panel ) {
			return;
		}
		panel.classList.add( 'is-pulse' );
		if ( panel.scrollIntoView ) {
			panel.scrollIntoView( { block: 'nearest' } );
		}
		window.setTimeout( function () {
			panel.classList.remove( 'is-pulse' );
		}, 900 );
	}

	function setShortcode( url, title, thumbUrl ) {
		var input = $( '#foliora-shortcode-output' );
		var fileLabel = $( '#foliora-selected-file' );
		var createBtn = $( '#foliora-create-test-page' );
		var copyBtn = $( '#foliora-copy-shortcode' );
		if ( ! input ) {
			return;
		}
		input.value = url ? '[foliora file="' + url + '"]' : '';
		if ( fileLabel ) {
			fileLabel.textContent = title || url || t( 'selectPdf', 'Select PDF' );
		}
		if ( createBtn ) {
			createBtn.disabled = ! url;
		}
		if ( copyBtn ) {
			copyBtn.disabled = ! url;
		}
		var stage = $( '#foliora-stage' );
		if ( stage ) {
			stage.classList.toggle( 'is-empty', ! url );
		}
		var thumb = $( '#foliora-stage-thumb' );
		var fallback = $( '#foliora-stage-fallback' );
		if ( thumb ) {
			if ( thumbUrl ) {
				thumb.src = thumbUrl;
				thumb.hidden = false;
				if ( fallback ) {
					fallback.hidden = true;
				}
			} else {
				thumb.removeAttribute( 'src' );
				thumb.hidden = true;
				if ( fallback ) {
					fallback.hidden = false;
				}
			}
		}
		$$( '.foliora-recent-item' ).forEach( function ( item ) {
			var selected = !!( url && item.getAttribute( 'data-url' ) === url );
			item.classList.toggle( 'is-selected', selected );
			item.setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
		} );
	}

	function openMedia() {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var frame = window.wp.media( {
			title: config.i18n && config.i18n.selectPdf ? config.i18n.selectPdf : 'Select PDF',
			button: { text: config.i18n && config.i18n.usePdf ? config.i18n.usePdf : 'Use this PDF' },
			library: { type: 'application/pdf' },
			multiple: false,
		} );
		frame.on( 'select', function () {
			var file = frame.state().get( 'selection' ).first().toJSON();
			if ( file && file.url ) {
				setShortcode( file.url, file.filename || file.title || '', ( file.image && file.image.src ) || '' );
				pulsePanel( '#foliora-embed-panel' );
			}
		} );
		frame.open();
	}

	function createTestPage( button ) {
		var input = $( '#foliora-shortcode-output' );
		var match = input && input.value ? input.value.match( /file="([^"]+)"/ ) : null;
		var file = match ? match[ 1 ] : '';
		if ( ! file ) {
			return;
		}
		button.disabled = true;
		var original = button.textContent;
		button.textContent = t( 'creating', 'Creating page…' );
		post(
			'foliora_create_test_page',
			{ file: file },
			function ( data ) {
				button.disabled = false;
				button.textContent = original;
				var step = $( '[data-setup-step="embedded"]' );
				if ( step ) {
					step.classList.add( 'is-done' );
				}
				var embeds = $( '#foliora-metric-embeds .foliora-metric-value' );
				if ( embeds ) {
					embeds.textContent = String( ( parseInt( embeds.textContent, 10 ) || 0 ) + 1 );
				}
				refreshSetupUi();
				if ( data.url ) {
					window.open( data.url, '_blank', 'noopener,noreferrer' );
				}
			},
			function () {
				button.disabled = false;
				button.textContent = original;
			}
		);
	}

	function bindDashboard() {
		var pick = $( '#foliora-pick-pdf' );
		var copy = $( '#foliora-copy-shortcode' );
		var create = $( '#foliora-create-test-page' );
		if ( pick ) {
			pick.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				openMedia();
			} );
		}
		if ( copy ) {
			copy.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var input = $( '#foliora-shortcode-output' );
				copyText( input ? input.value : '', copy, markCopiedStep );
			} );
		}
		if ( create ) {
			create.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				createTestPage( create );
			} );
		}
		$$( '.foliora-recent-item' ).forEach( function ( item ) {
			item.addEventListener( 'click', function () {
				setShortcode(
					item.getAttribute( 'data-url' ) || '',
					item.getAttribute( 'data-title' ) || '',
					item.getAttribute( 'data-thumb' ) || ''
				);
			} );
		} );
		var code = $( '#foliora-code' );
		if ( code ) {
			code.addEventListener( 'click', function ( e ) {
				if ( e.target && e.target.closest && e.target.closest( '#foliora-copy-shortcode' ) ) {
					return;
				}
				var input = $( '#foliora-shortcode-output' );
				copyText( input ? input.value : '', copy, markCopiedStep );
			} );
		}
		$$( '[data-foliora-focus]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var target = button.getAttribute( 'data-foliora-focus' );
				pulsePanel( '#foliora-embed-panel' );
				if ( 'embed-create' === target && create && ! create.disabled ) {
					create.focus();
				} else if ( pick ) {
					pick.focus();
				}
			} );
		} );
		bindDrop();
		bindProTiles();
		bindCountUp();
	}

	function isPdfFile( file ) {
		if ( ! file ) {
			return false;
		}
		if ( file.type === 'application/pdf' ) {
			return true;
		}
		return /\.pdf$/i.test( file.name || '' );
	}

	function parseAjax( text ) {
		var clean = String( text || '' ).replace( /^\uFEFF+/g, '' ).trim();
		var start = clean.indexOf( '{' );
		if ( start > 0 ) {
			clean = clean.slice( start );
		}
		return JSON.parse( clean );
	}

	function uploadPdf( file ) {
		toast( t( 'uploading', 'Uploading…' ) );
		var body = new window.FormData();
		body.append( 'async-upload', file );
		body.append( 'name', file.name );
		body.append( 'action', 'upload-attachment' );
		body.append( '_wpnonce', config.uploadNonce || '' );

		window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( res ) {
				return res.text();
			} )
			.then( parseAjax )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.url ) {
					setShortcode( json.data.url, json.data.filename || json.data.title || file.name, '' );
					var uploaded = $( '[data-setup-step="uploaded"]' );
					if ( uploaded ) {
						uploaded.classList.add( 'is-done' );
					}
					var pdfs = $( 'a.foliora-metric .foliora-metric-value[data-count]' );
					if ( pdfs ) {
						pdfs.textContent = String( ( parseInt( pdfs.textContent, 10 ) || 0 ) + 1 );
					}
					refreshSetupUi();
					pulsePanel( '#foliora-embed-panel' );
				} else {
					toast( t( 'uploadFail', 'Upload failed. Try the Media Library.' ) );
				}
			} )
			.catch( function () {
				toast( t( 'uploadFail', 'Upload failed. Try the Media Library.' ) );
			} );
	}

	function bindDrop() {
		var panel = $( '#foliora-embed-panel' );
		var overlay = $( '#foliora-drop' );
		if ( ! panel || ! overlay ) {
			return;
		}
		var dragCount = 0;
		panel.addEventListener( 'dragenter', function ( e ) {
			e.preventDefault();
			dragCount += 1;
			overlay.hidden = false;
		} );
		panel.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			if ( e.dataTransfer ) {
				e.dataTransfer.dropEffect = 'copy';
			}
		} );
		panel.addEventListener( 'dragleave', function ( e ) {
			e.preventDefault();
			dragCount -= 1;
			if ( dragCount <= 0 ) {
				dragCount = 0;
				overlay.hidden = true;
			}
		} );
		panel.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			dragCount = 0;
			overlay.hidden = true;
			var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ];
			if ( ! file ) {
				return;
			}
			if ( ! isPdfFile( file ) ) {
				toast( t( 'notPdf', 'Please drop a PDF file.' ) );
				return;
			}
			if ( ! config.canUpload ) {
				openMedia();
				return;
			}
			uploadPdf( file );
		} );
	}

	function bindProTiles() {
		$$( '.foliora-pro-tile' ).forEach( function ( tile ) {
			tile.addEventListener( 'toggle', function () {
				if ( ! tile.open ) {
					return;
				}
				$$( '.foliora-pro-tile' ).forEach( function ( other ) {
					if ( other !== tile ) {
						other.open = false;
					}
				} );
			} );
		} );
	}

	function bindCountUp() {
		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}
		$$( '.foliora-metric-value[data-count]' ).forEach( function ( el ) {
			var to = parseInt( el.getAttribute( 'data-count' ), 10 );
			if ( ! to ) {
				return;
			}
			var steps = Math.min( 18, Math.max( 8, to ) );
			var i = 0;
			el.textContent = '0';
			var timer = window.setInterval( function () {
				i += 1;
				el.textContent = String( Math.round( ( to * i ) / steps ) );
				if ( i >= steps ) {
					el.textContent = String( to );
					window.clearInterval( timer );
				}
			}, 28 );
		} );
	}

	function bindDocuments() {
		$$( '.foliora-copy-btn, .foliora-copy-preset-btn, .foliora-copy-snippet-btn' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var text = button.getAttribute( 'data-foliora-copy' ) || '';
				copyText( text, button, markCopiedStep );
			} );
		} );
		$$( '.foliora-code-chip-copy' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var text = chip.getAttribute( 'data-foliora-copy' ) || '';
				copyText( text, chip, function () {
					toast( copiedLabel() );
				} );
			} );
		} );
		$$( '.foliora-docs-shortcode' ).forEach( function ( input ) {
			input.addEventListener( 'click', function () {
				input.select();
			} );
		} );
	}

	function bindShortcodeBuilder() {
		var builder = $( '.foliora-builder-wrap' );
		if ( ! builder ) {
			return;
		}

		var fileInput = $( '#foliora-builder-file' );
		var nameInput = $( '#foliora-builder-name' );
		var idInput = $( '#foliora-builder-id' );
		var titleInput = $( '#foliora-builder-title' );
		var widthInput = $( '#foliora-builder-width' );
		var heightInput = $( '#foliora-builder-height' );
		var pageInput = $( '#foliora-builder-page' );
		var lazyCheck = $( '#foliora-builder-lazy' );
		var hashCheck = $( '#foliora-builder-hash' );
		var resumeCheck = $( '#foliora-builder-resume' );
		var outputArea = $( '#foliora-builder-output' );
		var saveBtn = $( '#foliora-builder-save' );
		var saveBtnText = $( '#foliora-save-btn-text' );
		var copyBtn = $( '#foliora-builder-copy' );
		var createPageBtn = $( '#foliora-builder-create-page' );
		var rawShortcodeToggle = $( '#foliora-toggle-raw-shortcode' );
		var frame = $( '#foliora-builder-frame' );
		var stage = $( '#foliora-builder-stage' );
		var currentFormat = 'shortcode';
		var debounceTimer = null;

		function getSelectedTheme() {
			var checked = $( 'input[name="foliora_b_theme"]:checked' );
			return checked ? checked.value : 'light';
		}

		function getSelectedView() {
			var checked = $( 'input[name="foliora_b_view"]:checked' );
			return checked ? checked.value : 'page';
		}

		function getHiddenTools() {
			var hidden = [];
			$$( 'input.foliora-tool-toggle' ).forEach( function ( cb ) {
				if ( ! cb.checked ) {
					var tool = cb.getAttribute( 'data-tool' );
					if ( tool ) {
						hidden.push( tool );
					}
				}
			} );
			return hidden;
		}

		function buildRawShortcodeString() {
			var file = fileInput ? fileInput.value.trim() : '';
			if ( ! file ) {
				return '';
			}
			var parts = [ 'foliora', 'file="' + file + '"' ];

			var width = widthInput ? widthInput.value.trim() : '';
			if ( width && width !== '100%' ) {
				parts.push( 'width="' + width + '"' );
			}

			var height = heightInput ? heightInput.value.trim() : '';
			if ( height && height !== '600px' && height !== '520px' ) {
				parts.push( 'height="' + height + '"' );
			}

			var theme = getSelectedTheme();
			if ( theme && theme !== 'light' ) {
				parts.push( 'theme="' + theme + '"' );
			}

			var title = titleInput ? titleInput.value.trim() : '';
			if ( title ) {
				parts.push( 'title="' + title.replace( /"/g, '&quot;' ) + '"' );
			}

			var page = pageInput ? parseInt( pageInput.value, 10 ) : 1;
			if ( page && page > 1 ) {
				parts.push( 'page="' + page + '"' );
			}

			var view = getSelectedView();
			if ( view && view !== 'page' ) {
				parts.push( 'view="' + view + '"' );
			}

			var hidden = getHiddenTools();
			if ( hidden.length > 0 ) {
				parts.push( 'hide="' + hidden.join( ',' ) + '"' );
			}

			// Force-enable tools checked here but disabled in global settings
			$$( 'input.foliora-tool-toggle[data-attr]' ).forEach( function ( cb ) {
				if ( cb.checked && cb.getAttribute( 'data-global' ) === '0' ) {
					parts.push( cb.getAttribute( 'data-attr' ) + '="true"' );
				}
			} );

			if ( lazyCheck && lazyCheck.checked ) {
				parts.push( 'loading="lazy"' );
			}

			if ( hashCheck && ! hashCheck.checked ) {
				parts.push( 'hash="false"' );
			}

			if ( resumeCheck && ! resumeCheck.checked ) {
				parts.push( 'resume="false"' );
			}

			return '[' + parts.join( ' ' ) + ']';
		}

		function getEmbedId() {
			return idInput ? parseInt( idInput.value, 10 ) || 0 : 0;
		}

		function buildActiveShortcode() {
			var currentId = getEmbedId();
			if ( rawShortcodeToggle && rawShortcodeToggle.checked ) {
				return buildRawShortcodeString();
			}
			if ( currentId > 0 ) {
				return '[foliora id="' + currentId + '"]';
			}
			return buildRawShortcodeString();
		}

		function updateOutput() {
			if ( ! outputArea ) {
				return;
			}
			var code = buildActiveShortcode();
			if ( ! code ) {
				outputArea.value = '';
				return;
			}

			if ( currentFormat === 'php' ) {
				outputArea.value = "<?php echo do_shortcode( '" + code + "' ); ?>";
			} else if ( currentFormat === 'block' ) {
				outputArea.value = "<!-- wp:foliora/viewer -->\n" + code + "\n<!-- /wp:foliora/viewer -->";
			} else {
				outputArea.value = code;
			}
		}

		function updateLivePreview() {
			var wrap = $( '.foliora-wrap', stage );
			var shell = $( '.foliora-shell', stage );
			var viewer = $( '.foliora-viewer', stage );
			var file = fileInput ? fileInput.value.trim() : '';

			if ( ! file ) {
				stage.innerHTML = '<div class="foliora-builder-empty-state"><span class="dashicons dashicons-pdf" aria-hidden="true"></span><p>' + t( 'selectPdf', 'Select a PDF above to see live interactive preview.' ) + '</p></div>';
				return;
			}

			if ( wrap && shell && viewer ) {
				var width = widthInput ? widthInput.value.trim() : '100%';
				var height = heightInput ? heightInput.value.trim() : '520px';
				wrap.style.width = width || '100%';
				shell.style.height = height || '520px';

				var theme = getSelectedTheme();
				[ 'light', 'dark', 'sepia' ].forEach( function ( tName ) {
					shell.classList.remove( 'foliora-theme-' + tName );
				} );
				shell.classList.add( 'foliora-theme-' + theme );

				var toolbar = $( '.foliora-toolbar', shell );
				if ( toolbar ) {
					var hideTools = getHiddenTools();
					var dl = $( '.foliora-download', toolbar );
					if ( dl ) { dl.style.display = hideTools.indexOf( 'download' ) !== -1 ? 'none' : ''; }
					var pr = $( '.foliora-print', toolbar );
					if ( pr ) { pr.style.display = hideTools.indexOf( 'print' ) !== -1 ? 'none' : ''; }
					var se = $( '.foliora-find-group', toolbar );
					if ( se ) { se.style.display = hideTools.indexOf( 'search' ) !== -1 ? 'none' : ''; }
					var th = $( '.foliora-theme-toggle', toolbar );
					if ( th ) { th.style.display = hideTools.indexOf( 'theme' ) !== -1 ? 'none' : ''; }
					var sh = $( '.foliora-share-btn', toolbar );
					if ( sh ) { sh.style.display = hideTools.indexOf( 'share' ) !== -1 ? 'none' : ''; }
					var sc = $( '.foliora-shortcuts-btn', toolbar );
					if ( sc ) { sc.style.display = hideTools.indexOf( 'shortcuts' ) !== -1 ? 'none' : ''; }
					var fs = $( '.foliora-fs', toolbar );
					if ( fs ) { fs.style.display = hideTools.indexOf( 'fullscreen' ) !== -1 ? 'none' : ''; }
					var ps = $( '.foliora-present', toolbar );
					if ( ps ) { ps.style.display = hideTools.indexOf( 'presentation' ) !== -1 ? 'none' : ''; }
					var zm = $( '.foliora-zoom-in, .foliora-zoom-out, .foliora-zoom-label', toolbar );
					if ( zm ) {
						$$( '.foliora-zoom-in, .foliora-zoom-out, .foliora-zoom-label', toolbar ).forEach( function ( el ) {
							el.style.display = hideTools.indexOf( 'zoom' ) !== -1 ? 'none' : '';
						} );
					}
					var ro = $( '.foliora-rotate', toolbar );
					if ( ro ) { ro.style.display = hideTools.indexOf( 'rotate' ) !== -1 ? 'none' : ''; }
					var pa = $( '.foliora-pan', toolbar );
					if ( pa ) { pa.style.display = hideTools.indexOf( 'pan' ) !== -1 ? 'none' : ''; }
				}

				var currentFile = viewer.getAttribute( 'data-file' );
				var currentView = viewer.getAttribute( 'data-view' ) || 'page';
				var selectedView = getSelectedView();

				if ( currentFile !== file ) {
					viewer.setAttribute( 'data-file', file );
					viewer.setAttribute( 'data-view', selectedView );
					if ( window.FolioraViewer && window.FolioraViewer.rebind ) {
						window.FolioraViewer.rebind( viewer );
					}
				} else if ( currentView !== selectedView ) {
					if ( window.FolioraViewer && window.FolioraViewer.setViewMode ) {
						window.FolioraViewer.setViewMode( viewer, selectedView );
					}
				}
			}
		}

		function triggerChange() {
			window.clearTimeout( debounceTimer );
			debounceTimer = window.setTimeout( function () {
				updateOutput();
				updateLivePreview();
			}, 50 );
		}

		[ fileInput, titleInput, nameInput, widthInput, heightInput, pageInput ].forEach( function ( input ) {
			if ( input ) {
				input.addEventListener( 'input', triggerChange );
				input.addEventListener( 'change', triggerChange );
			}
		} );

		$$( 'input[name="foliora_b_theme"], input[name="foliora_b_view"], input.foliora-tool-toggle, #foliora-builder-lazy, #foliora-builder-hash, #foliora-builder-resume' ).forEach( function ( el ) {
			el.addEventListener( 'change', triggerChange );
		} );

		if ( rawShortcodeToggle ) {
			rawShortcodeToggle.addEventListener( 'change', updateOutput );
		}

		$$( '.foliora-quick-chip' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				var targetSel = chip.getAttribute( 'data-target' );
				var val = chip.getAttribute( 'data-val' );
				var target = $( targetSel );
				if ( target && val ) {
					target.value = val;
					triggerChange();
				}
			} );
		} );

		var mediaFrame = null;
		function openBuilderMedia( uploadOnly ) {
			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}
			mediaFrame = wp.media( {
				title: t( 'selectPdf', 'Select PDF' ),
				button: { text: t( 'usePdf', 'Use this PDF' ) },
				multiple: false,
				library: { type: 'application/pdf' },
			} );
			mediaFrame.on( 'select', function () {
				var attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
				if ( attachment && attachment.url ) {
					if ( fileInput ) {
						fileInput.value = attachment.url;
					}
					if ( nameInput && ( ! nameInput.value || nameInput.value === '' ) ) {
						nameInput.value = attachment.title || attachment.filename || '';
					}
					if ( titleInput && ( ! titleInput.value || titleInput.value === '' ) ) {
						titleInput.value = attachment.title || attachment.filename || '';
					}
					$$( '.foliora-recent-btn' ).forEach( function ( btn ) {
						btn.classList.toggle( 'is-active', btn.getAttribute( 'data-url' ) === attachment.url );
					} );
					triggerChange();
				}
			} );
			mediaFrame.open();
		}

		var pickBtn = $( '#foliora-builder-pick-pdf' );
		var uploadBtn = $( '#foliora-builder-upload-pdf' );
		if ( pickBtn ) {
			pickBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				openBuilderMedia( false );
			} );
		}
		if ( uploadBtn ) {
			uploadBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				openBuilderMedia( true );
			} );
		}

		$$( '.foliora-recent-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				$$( '.foliora-recent-btn' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
				btn.classList.add( 'is-active' );
				var url = btn.getAttribute( 'data-url' ) || '';
				var title = btn.getAttribute( 'data-title' ) || '';
				if ( fileInput ) { fileInput.value = url; }
				if ( titleInput && ! titleInput.value ) { titleInput.value = title; }
				if ( nameInput && ! nameInput.value ) { nameInput.value = title; }
				triggerChange();
			} );
		} );

		var presets = {
			flipbook: {
				theme: 'light',
				view: 'flip',
				width: '100%',
				height: '620px',
				tools: { download: true, print: true, search: true, theme: true, share: true, shortcuts: true, fullscreen: true, presentation: true, zoom: true, rotate: false, pan: false },
				lazy: false
			},
			ebook: {
				theme: 'sepia',
				view: 'scroll',
				width: '100%',
				height: '650px',
				tools: { download: false, print: false, search: true, theme: true, share: true, shortcuts: true, fullscreen: true, presentation: false, zoom: true, rotate: false, pan: false },
				lazy: true
			},
			corporate: {
				theme: 'light',
				view: 'page',
				width: '100%',
				height: '600px',
				tools: { download: true, print: true, search: true, theme: false, share: true, shortcuts: true, fullscreen: true, presentation: true, zoom: true, rotate: true, pan: true },
				lazy: false
			},
			night: {
				theme: 'dark',
				view: 'scroll',
				width: '100%',
				height: '600px',
				tools: { download: true, print: true, search: true, theme: true, share: true, shortcuts: true, fullscreen: true, presentation: true, zoom: true, rotate: true, pan: true },
				lazy: false
			},
			viewonly: {
				theme: 'light',
				view: 'page',
				width: '100%',
				height: '550px',
				tools: { download: false, print: false, search: true, theme: true, share: true, shortcuts: true, fullscreen: true, presentation: false, zoom: true, rotate: false, pan: true },
				lazy: false
			},
			minimal: {
				theme: 'light',
				view: 'page',
				width: '100%',
				height: '500px',
				tools: { download: false, print: false, search: false, theme: false, share: false, shortcuts: false, fullscreen: true, presentation: false, zoom: true, rotate: false, pan: false },
				lazy: true
			}
		};

		$$( '.foliora-preset-chip' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var pKey = chip.getAttribute( 'data-preset' );
				var p = presets[ pKey ];
				if ( ! p ) {
					return;
				}

				var themeRadio = $( 'input[name="foliora_b_theme"][value="' + p.theme + '"]' );
				if ( themeRadio ) { themeRadio.checked = true; }

				var viewRadio = $( 'input[name="foliora_b_view"][value="' + p.view + '"]' );
				if ( viewRadio ) { viewRadio.checked = true; }

				if ( widthInput ) { widthInput.value = p.width; }
				if ( heightInput ) { heightInput.value = p.height; }

				$$( 'input.foliora-tool-toggle' ).forEach( function ( cb ) {
					var tool = cb.getAttribute( 'data-tool' );
					if ( typeof p.tools[ tool ] !== 'undefined' ) {
						cb.checked = p.tools[ tool ];
					}
				} );

				if ( lazyCheck ) { lazyCheck.checked = p.lazy; }

				$$( '.foliora-preset-chip' ).forEach( function ( c ) { c.classList.remove( 'is-active' ); } );
				chip.classList.add( 'is-active' );

				toast( t( 'presetApplied', 'Preset applied' ) + ': ' + chip.textContent.trim() );
				triggerChange();
			} );
		} );

		function switchControlTab( tabKey ) {
			$$( '.foliora-ctrl-tab' ).forEach( function ( b ) {
				var match = b.getAttribute( 'data-tab' ) === tabKey;
				b.classList.toggle( 'is-active', match );
				b.setAttribute( 'aria-selected', match ? 'true' : 'false' );
			} );
			$$( '.foliora-ctrl-pane' ).forEach( function ( pane ) {
				pane.style.display = ( pane.id === 'foliora-pane-' + tabKey ) ? '' : 'none';
				pane.classList.toggle( 'is-active', pane.id === 'foliora-pane-' + tabKey );
			} );
		}

		$$( '.foliora-ctrl-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var tabKey = tab.getAttribute( 'data-tab' );
				if ( tabKey ) {
					switchControlTab( tabKey );
				}
			} );
		} );

		$$( '.foliora-tab-step' ).forEach( function ( stepBtn ) {
			stepBtn.addEventListener( 'click', function () {
				var targetKey = stepBtn.getAttribute( 'data-step-to' );
				if ( targetKey ) {
					switchControlTab( targetKey );
				}
			} );
		} );

		function switchMainView( viewName ) {
			$$( '.foliora-view-tab-btn' ).forEach( function ( btn ) {
				var match = btn.getAttribute( 'data-view' ) === viewName;
				btn.classList.toggle( 'is-active', match );
			} );
			var builderView = $( '#foliora-view-builder' );
			var embedsView = $( '#foliora-view-embeds' );
			if ( builderView && embedsView ) {
				builderView.style.display = ( viewName === 'builder' ) ? '' : 'none';
				embedsView.style.display = ( viewName === 'embeds' ) ? '' : 'none';
			}
		}

		$$( '.foliora-view-tab-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var v = btn.getAttribute( 'data-view' );
				if ( v ) {
					switchMainView( v );
				}
			} );
		} );

		$$( '.foliora-switch-to-builder' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				switchMainView( 'builder' );
			} );
		} );

		$$( '.foliora-output-tabs .foliora-tab-btn' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				$$( '.foliora-output-tabs .foliora-tab-btn' ).forEach( function ( tBtn ) { tBtn.classList.remove( 'is-active' ); } );
				tab.classList.add( 'is-active' );
				currentFormat = tab.getAttribute( 'data-format' ) || 'shortcode';
				updateOutput();
			} );
		} );

		$$( '.foliora-device-switcher .foliora-device-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				$$( '.foliora-device-switcher .foliora-device-btn' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
				btn.classList.add( 'is-active' );
				var device = btn.getAttribute( 'data-device' ) || 'desktop';
				if ( frame ) {
					frame.classList.remove( 'is-desktop', 'is-tablet', 'is-mobile' );
					frame.classList.add( 'is-' + device );
				}
			} );
		} );

		if ( copyBtn && outputArea ) {
			copyBtn.addEventListener( 'click', function () {
				copyText( outputArea.value, copyBtn, markCopiedStep );
			} );
		}

		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var file = fileInput ? fileInput.value.trim() : '';
				if ( ! file ) {
					alert( t( 'selectPdf', 'Please select a PDF document first.' ) );
					return;
				}

				var curId = getEmbedId();
				var nameVal = nameInput ? nameInput.value.trim() : '';
				if ( ! nameVal ) {
					nameVal = ( titleInput && titleInput.value.trim() ) || 'PDF Embed';
					if ( nameInput ) {
						nameInput.value = nameVal;
					}
				}

				var payload = {
					embed_id: curId,
					name: nameVal,
					file: file,
					title: titleInput ? titleInput.value.trim() : '',
					width: widthInput ? widthInput.value.trim() : '100%',
					height: heightInput ? heightInput.value.trim() : '520px',
					theme: getSelectedTheme(),
					view: getSelectedView(),
					page: pageInput ? ( parseInt( pageInput.value, 10 ) || 1 ) : 1,
					hide: getHiddenTools().join( ',' ),
					loading: ( lazyCheck && lazyCheck.checked ) ? 'lazy' : '',
					hash: ( hashCheck && ! hashCheck.checked ) ? 'false' : 'true',
					resume: ( resumeCheck && ! resumeCheck.checked ) ? 'false' : 'true'
				};

				saveBtn.disabled = true;
				var originalLabel = saveBtnText ? saveBtnText.textContent : 'Save Embed';
				if ( saveBtnText ) {
					saveBtnText.textContent = t( 'saving', 'Saving…' );
				}

				post(
					'foliora_save_embed',
					payload,
					function ( data ) {
						saveBtn.disabled = false;
						if ( data && data.embed_id ) {
							if ( idInput ) {
								idInput.value = data.embed_id;
							}
							if ( saveBtnText ) {
								saveBtnText.textContent = '✓ Saved! Copied';
							}

							updateOutput();

							var codeToCopy = data.shortcode || ( '[foliora id="' + data.embed_id + '"]' );
							copyText( codeToCopy, saveBtn, markCopiedStep );
							toast( 'Saved! ' + codeToCopy + ' copied to clipboard.' );

							window.setTimeout( function () {
								if ( saveBtnText ) {
									saveBtnText.textContent = t( 'updateEmbed', 'Update Embed & Copy' );
								}
							}, 2200 );
						}
					},
					function ( err ) {
						saveBtn.disabled = false;
						if ( saveBtnText ) {
							saveBtnText.textContent = originalLabel;
						}
						alert( ( err && err.message ) || 'Could not save embed.' );
					}
				);
			} );
		}

		if ( createPageBtn ) {
			createPageBtn.addEventListener( 'click', function () {
				var file = fileInput ? fileInput.value.trim() : '';
				if ( ! file ) {
					alert( t( 'selectPdf', 'Select a PDF first.' ) );
					return;
				}
				var shortcode = buildActiveShortcode();
				var originalContent = createPageBtn.innerHTML;
				createPageBtn.disabled = true;
				createPageBtn.textContent = t( 'creating', 'Creating…' );

				post(
					'foliora_create_test_page',
					{ file: file, shortcode: shortcode },
					function ( data ) {
						createPageBtn.disabled = false;
						createPageBtn.innerHTML = originalContent;
						if ( data && data.url ) {
							window.open( data.url, '_blank' );
						}
					},
					function ( err ) {
						createPageBtn.disabled = false;
						createPageBtn.innerHTML = originalContent;
						alert( ( err && err.message ) || 'Could not create test page.' );
					}
				);
			} );
		}

		var searchInput = $( '#foliora-embeds-search' );
		if ( searchInput ) {
			searchInput.addEventListener( 'input', function () {
				var term = searchInput.value.toLowerCase().trim();
				$$( '#foliora-embeds-tbody tr' ).forEach( function ( tr ) {
					var text = tr.getAttribute( 'data-search-text' ) || '';
					tr.style.display = ( ! term || text.indexOf( term ) !== -1 ) ? '' : 'none';
				} );
			} );
		}

		$$( '.foliora-shortcode-chip' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var text = chip.getAttribute( 'data-copy' ) || chip.textContent.trim();
				copyText( text, chip, function () {
					toast( 'Copied: ' + text );
				} );
			} );
		} );

		$$( '.foliora-embed-duplicate-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var embId = btn.getAttribute( 'data-id' );
				if ( ! embId ) {
					return;
				}
				btn.disabled = true;
				post(
					'foliora_duplicate_embed',
					{ embed_id: embId },
					function ( data ) {
						toast( 'Embed duplicated successfully!' );
						window.location.reload();
					},
					function ( err ) {
						btn.disabled = false;
						alert( ( err && err.message ) || 'Could not duplicate embed.' );
					}
				);
			} );
		} );

		$$( '.foliora-embed-delete-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var embId = btn.getAttribute( 'data-id' );
				if ( ! embId ) {
					return;
				}
				if ( ! window.confirm( 'Are you sure you want to delete this embed? Any shortcode using id="' + embId + '" will no longer work.' ) ) {
					return;
				}
				btn.disabled = true;
				post(
					'foliora_delete_embed',
					{ embed_id: embId },
					function () {
						var tr = btn.closest( 'tr' );
						if ( tr ) {
							tr.remove();
						}
						var badge = $( '#foliora-embeds-count-badge' );
						if ( badge ) {
							var currentCount = parseInt( badge.textContent, 10 ) || 0;
							badge.textContent = String( Math.max( 0, currentCount - 1 ) );
						}
						toast( 'Embed #' + embId + ' deleted.' );
					},
					function ( err ) {
						btn.disabled = false;
						alert( ( err && err.message ) || 'Could not delete embed.' );
					}
				);
			} );
		} );

		updateOutput();
	}

	function bindLivePreview() {
		var widthInput = $( '#foliora-default-width' );
		var heightInput = $( '#foliora-default-height' );
		var themeSelect = $( '#foliora-default-theme' );
		var wrap = $( '.foliora-live-preview-stage .foliora-wrap' );
		var shell = $( '.foliora-live-preview-stage .foliora-shell' );
		if ( ! wrap || ! shell ) {
			return;
		}

		function apply() {
			if ( widthInput && widthInput.value ) {
				wrap.style.width = widthInput.value;
			}
			if ( heightInput && heightInput.value ) {
				shell.style.height = heightInput.value;
			}
			if ( themeSelect ) {
				var currentTheme = themeSelect.value || 'light';
				[ 'light', 'dark', 'sepia' ].forEach( function ( name ) {
					shell.classList.remove( 'foliora-theme-' + name );
				} );
				shell.classList.add( 'foliora-theme-' + currentTheme );
			}

			// Synchronize toolbar toggles in settings live preview
			var toolbar = $( '.foliora-toolbar', shell );
			if ( toolbar ) {
				var dlCb = $( 'input[name="foliora_settings[allow_download]"]' );
				var dlBtn = $( '.foliora-download', toolbar );
				if ( dlCb && dlBtn ) { dlBtn.style.display = dlCb.checked ? '' : 'none'; }

				var prCb = $( 'input[name="foliora_settings[allow_print]"]' );
				var prBtn = $( '.foliora-print', toolbar );
				if ( prCb && prBtn ) { prBtn.style.display = prCb.checked ? '' : 'none'; }

				var seCb = $( 'input[name="foliora_settings[allow_search]"]' );
				var seBtn = $( '.foliora-find-group', toolbar );
				if ( seCb && seBtn ) { seBtn.style.display = seCb.checked ? '' : 'none'; }

				var thCb = $( 'input[name="foliora_settings[allow_theme]"]' );
				var thBtn = $( '.foliora-theme-toggle', toolbar );
				if ( thCb && thBtn ) { thBtn.style.display = thCb.checked ? '' : 'none'; }

				var shCb = $( 'input[name="foliora_settings[allow_share]"]' );
				var shBtn = $( '.foliora-share-btn', toolbar );
				if ( shCb && shBtn ) { shBtn.style.display = shCb.checked ? '' : 'none'; }

				var scCb = $( 'input[name="foliora_settings[allow_shortcuts]"]' );
				var scBtn = $( '.foliora-shortcuts-btn', toolbar );
				if ( scCb && scBtn ) { scBtn.style.display = scCb.checked ? '' : 'none'; }

				var fsCb = $( 'input[name="foliora_settings[allow_fullscreen]"]' );
				var fsBtn = $( '.foliora-fs', toolbar );
				if ( fsCb && fsBtn ) { fsBtn.style.display = fsCb.checked ? '' : 'none'; }

				var psCb = $( 'input[name="foliora_settings[allow_presentation]"]' );
				var psBtn = $( '.foliora-present', toolbar );
				if ( psCb && psBtn ) { psBtn.style.display = psCb.checked ? '' : 'none'; }
			}
		}

		if ( widthInput ) {
			widthInput.addEventListener( 'input', apply );
			widthInput.addEventListener( 'change', apply );
		}
		if ( heightInput ) {
			heightInput.addEventListener( 'input', apply );
			heightInput.addEventListener( 'change', apply );
		}
		if ( themeSelect ) {
			themeSelect.addEventListener( 'change', apply );
		}
		$$( '.foliora-settings-layout input[type="checkbox"]' ).forEach( function ( cb ) {
			cb.addEventListener( 'change', apply );
		} );

		// Initial sync
		apply();
	}

	function bindWelcome() {
		var wrap = $( '#foliora-welcome' );
		var btn = $( '#foliora-welcome-dismiss' );
		if ( ! wrap || ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			wrap.style.display = 'none';
			post( 'foliora_dismiss_welcome', {}, function () {} );
		} );
	}

	onReady( function () {
		bindDashboard();
		bindDocuments();
		bindShortcodeBuilder();
		bindLivePreview();
		bindWelcome();
	} );
} )();

