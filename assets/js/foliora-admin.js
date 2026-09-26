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
		$$( '.foliora-copy-btn' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				copyText( button.getAttribute( 'data-foliora-copy' ) || '', button, markCopiedStep );
			} );
		} );
		$$( '.foliora-docs-shortcode' ).forEach( function ( input ) {
			input.addEventListener( 'click', function () {
				input.select();
			} );
		} );
	}

	function bindLivePreview() {
		var widthInput = $( '#foliora-default-width' );
		var heightInput = $( '#foliora-default-height' );
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
		}

		if ( widthInput ) {
			widthInput.addEventListener( 'input', apply );
			widthInput.addEventListener( 'change', apply );
		}
		if ( heightInput ) {
			heightInput.addEventListener( 'input', apply );
			heightInput.addEventListener( 'change', apply );
		}
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
		bindLivePreview();
		bindWelcome();
	} );
} )();
