/**
 * Foliora front-end viewer.
 *
 * Canvas + text layer + annotation layer, with page / scroll / spread
 * layouts. Kept dependency-free so visitors only download this plus the
 * bundled pdf.min.js.
 */
( function () {
	'use strict';

	if ( typeof window.pdfjsLib === 'undefined' ) {
		return;
	}

	var config = window.FolioraConfig || {};
	window.pdfjsLib.GlobalWorkerOptions.workerSrc = config.workerSrc;
	var VIEW_MODES = [ 'page', 'scroll', 'spread', 'flip' ];

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	function $$( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}

	function clamp( n, min, max ) {
		return Math.min( max, Math.max( min, n ) );
	}

	function passwordReasonIncorrect() {
		var responses = window.pdfjsLib.PasswordResponses;
		return ( responses && responses.INCORRECT_PASSWORD ) || 2;
	}

	function touchDistance( a, b ) {
		var dx = a.clientX - b.clientX;
		var dy = a.clientY - b.clientY;
		return Math.sqrt( dx * dx + dy * dy );
	}

	function countNeedle( text, needle ) {
		if ( ! needle ) {
			return 0;
		}
		var hay = ( text || '' ).toLowerCase();
		var n = needle.toLowerCase();
		var count = 0;
		var from = 0;
		var at;
		while ( ( at = hay.indexOf( n, from ) ) !== -1 ) {
			count += 1;
			from = at + n.length;
		}
		return count;
	}

	function clearChildren( el ) {
		if ( ! el ) {
			return;
		}
		if ( typeof el.replaceChildren === 'function' ) {
			el.replaceChildren();
		} else {
			while ( el.firstChild ) {
				el.removeChild( el.firstChild );
			}
		}
	}

	function setChildren( el, child ) {
		if ( ! el ) {
			return;
		}
		clearChildren( el );
		if ( child ) {
			el.appendChild( child );
		}
	}

	function renderTextLayer( page, viewport, layerEl ) {
		clearChildren( layerEl );
		layerEl.style.setProperty( '--scale-factor', String( viewport.scale ) );
		layerEl.style.width = viewport.width + 'px';
		layerEl.style.height = viewport.height + 'px';

		if ( typeof window.pdfjsLib.TextLayer === 'function' && page.streamTextContent ) {
			var layer = new window.pdfjsLib.TextLayer( {
				textContentSource: page.streamTextContent(),
				container: layerEl,
				viewport: viewport,
			} );
			return Promise.resolve( layer.render() ).catch( function () {
				return undefined;
			} );
		}

		if ( typeof window.pdfjsLib.renderTextLayer !== 'function' ) {
			return Promise.resolve();
		}

		return page.getTextContent().then( function ( textContent ) {
			var task = window.pdfjsLib.renderTextLayer( {
				textContent: textContent,
				textContentSource: textContent,
				container: layerEl,
				viewport: viewport,
			} );
			return task && task.promise ? task.promise : task;
		} ).catch( function () {
			return undefined;
		} );
	}

	
	var flipAudioCtx = null;
	function playPageTurnSound() {
		try {
			var AC = window.AudioContext || window.webkitAudioContext;
			if ( ! AC ) {
				return;
			}
			if ( ! flipAudioCtx ) {
				flipAudioCtx = new AC();
			}
			if ( flipAudioCtx.state === 'suspended' ) {
				flipAudioCtx.resume();
			}
			var bufferSize = Math.floor( flipAudioCtx.sampleRate * 0.15 );
			var buffer = flipAudioCtx.createBuffer( 1, bufferSize, flipAudioCtx.sampleRate );
			var data = buffer.getChannelData( 0 );
			for ( var i = 0; i < bufferSize; i++ ) {
				data[ i ] = ( Math.random() * 2 - 1 ) * Math.exp( -i / ( bufferSize * 0.4 ) );
			}
			var noise = flipAudioCtx.createBufferSource();
			noise.buffer = buffer;

			var filter = flipAudioCtx.createBiquadFilter();
			filter.type = 'bandpass';
			filter.frequency.setValueAtTime( 1200, flipAudioCtx.currentTime );
			filter.frequency.exponentialRampToValueAtTime( 3200, flipAudioCtx.currentTime + 0.12 );
			filter.Q.value = 2.2;

			var gain = flipAudioCtx.createGain();
			gain.gain.setValueAtTime( 0.09, flipAudioCtx.currentTime );
			gain.gain.exponentialRampToValueAtTime( 0.001, flipAudioCtx.currentTime + 0.15 );

			noise.connect( filter );
			filter.connect( gain );
			gain.connect( flipAudioCtx.destination );

			noise.start();
			noise.stop( flipAudioCtx.currentTime + 0.15 );
		} catch ( e ) {
			// AudioContext fallback
		}
	}

	function bindViewer( container, toolbar ) {
		if ( container.getAttribute( 'data-foliora-bound' ) === '1' ) {
			return;
		}

		var fileUrl = container.getAttribute( 'data-file' );
		if ( ! fileUrl ) {
			return;
		}
		container.setAttribute( 'data-foliora-bound', '1' );

		var i18n = config.i18n || {};
		var status = document.createElement( 'div' );
		status.className = 'foliora-status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.textContent = i18n.loading || 'Loading document…';
		container.appendChild( status );

		var passwordForm = null;
		var passwordInput = null;
		var passwordError = null;
		var passwordUpdate = null;

		var pagesEl = document.createElement( 'div' );
		pagesEl.className = 'foliora-pages is-page';

		pagesEl.addEventListener( 'click', function ( e ) {
			if ( viewMode() !== 'flip' || isNarrow() ) {
				return;
			}
			if ( e.target && e.target.closest && ( e.target.closest( 'a' ) || e.target.closest( 'button' ) || e.target.closest( '.foliora-text-layer' ) ) ) {
				var sel = window.getSelection();
				if ( sel && sel.toString && sel.toString().trim().length > 0 ) {
					return;
				}
			}
			var rect = pagesEl.getBoundingClientRect();
			var clickX = e.clientX - rect.left;
			if ( clickX > rect.width * 0.55 ) {
				if ( state.page < state.numPages ) {
					nextPage();
				}
			} else if ( clickX < rect.width * 0.45 ) {
				if ( state.page > 1 ) {
					prevPage();
				}
			}
		} );

		container.appendChild( pagesEl );

		var side = document.createElement( 'aside' );
		side.className = 'foliora-side';
		side.hidden = true;
		side.setAttribute( 'data-mode', '' );

		var sideHead = document.createElement( 'div' );
		sideHead.className = 'foliora-side-head';
		var sideTitle = document.createElement( 'span' );
		sideTitle.className = 'foliora-side-title';
		var sideClose = document.createElement( 'button' );
		sideClose.type = 'button';
		sideClose.className = 'foliora-side-close';
		sideClose.setAttribute( 'aria-label', i18n.closePanel || 'Close panel' );
		sideClose.textContent = '\u00d7';
		sideHead.appendChild( sideTitle );
		sideHead.appendChild( sideClose );

		var tocPanel = document.createElement( 'div' );
		tocPanel.className = 'foliora-toc-panel';
		tocPanel.setAttribute( 'role', 'navigation' );
		tocPanel.setAttribute( 'aria-label', i18n.outlineNav || 'Document outline' );
		tocPanel.hidden = true;

		var thumbsPanel = document.createElement( 'div' );
		thumbsPanel.className = 'foliora-thumbs-panel';
		thumbsPanel.setAttribute( 'role', 'navigation' );
		thumbsPanel.setAttribute( 'aria-label', i18n.thumbsNav || i18n.thumbs || 'Page thumbnails' );
		thumbsPanel.hidden = true;

		side.appendChild( sideHead );
		side.appendChild( tocPanel );
		side.appendChild( thumbsPanel );
		container.appendChild( side );

		var initialView = container.getAttribute( 'data-view' ) || 'page';
		if ( VIEW_MODES.indexOf( initialView ) === -1 ) {
			initialView = 'page';
		}

		var state = {
			pdf: null,
			page: 1,
			numPages: 1,
			zoom: 1,
			fitMode: 'page',
			rotation: 0,
			textCache: {},
			query: '',
			findHits: [],
			findAt: 0,
			findQuery: '',
			thumbCache: {},
			thumbPending: {},
			viewMode: initialView,
			tool: 'select',
			presentation: false,
			slotSize: null,
		};

		var slots = [];
		var pageObserver = null;
		var thumbObserver = null;
		var persistTimer = null;

		var panelGroup = toolbar ? $( '.foliora-panel-group', toolbar ) : null;
		var tocBtn = toolbar ? $( '.foliora-toc', toolbar ) : null;
		var thumbsBtn = toolbar ? $( '.foliora-thumbs', toolbar ) : null;
		if ( container.id ) {
			tocPanel.id = container.id + '-toc';
			thumbsPanel.id = container.id + '-thumbs';
			if ( tocBtn ) {
				tocBtn.setAttribute( 'aria-controls', tocPanel.id );
			}
			if ( thumbsBtn ) {
				thumbsBtn.setAttribute( 'aria-controls', thumbsPanel.id );
			}
		}
		var themeToggleBtn = toolbar ? $( '.foliora-theme-toggle', toolbar ) : null;
		var shareBtn = toolbar ? $( '.foliora-share-btn', toolbar ) : null;
		var shortcutsBtn = toolbar ? $( '.foliora-shortcuts-btn', toolbar ) : null;
		var prevBtn = toolbar ? $( '.foliora-prev', toolbar ) : null;
		var nextBtn = toolbar ? $( '.foliora-next', toolbar ) : null;
		var pageInput = toolbar ? $( '.foliora-page-input', toolbar ) : null;
		var pageCount = toolbar ? $( '.foliora-page-count', toolbar ) : null;
		var zoomInBtn = toolbar ? $( '.foliora-zoom-in', toolbar ) : null;
		var zoomOutBtn = toolbar ? $( '.foliora-zoom-out', toolbar ) : null;
		var zoomLabel = toolbar ? $( '.foliora-zoom-label', toolbar ) : null;
		var fitBtn = toolbar ? $( '.foliora-fit', toolbar ) : null;
		var downloadBtn = toolbar ? $( '.foliora-download', toolbar ) : null;
		var rotateBtn = toolbar ? $( '.foliora-rotate', toolbar ) : null;
		var viewBtn = toolbar ? $( '.foliora-view', toolbar ) : null;
		var panBtn = toolbar ? $( '.foliora-pan', toolbar ) : null;
		var printBtn = toolbar ? $( '.foliora-print', toolbar ) : null;
		var presentBtn = toolbar ? $( '.foliora-present', toolbar ) : null;
		var fsBtn = toolbar ? $( '.foliora-fs', toolbar ) : null;
		var moreBtn = toolbar ? $( '.foliora-more', toolbar ) : null;
		var findInput = toolbar ? $( '.foliora-find-input', toolbar ) : null;
		var findPrev = toolbar ? $( '.foliora-find-prev', toolbar ) : null;
		var findNext = toolbar ? $( '.foliora-find-next', toolbar ) : null;
		var findStatus = toolbar ? $( '.foliora-find-status', toolbar ) : null;
		var pageLive = toolbar ? $( '.foliora-page-live', toolbar ) : null;
		var shell = container.closest ? container.closest( '.foliora-shell' ) : container.parentNode;

		var shortcutsModal = shell ? $( '.foliora-shortcuts-modal', shell ) : null;
		var shareModal = shell ? $( '.foliora-share-modal', shell ) : null;
		var shareInput = shareModal ? $( '.foliora-share-input', shareModal ) : null;
		var shareCopyBtn = shareModal ? $( '.foliora-share-copy', shareModal ) : null;
		var sharePageCheck = shareModal ? $( '.foliora-share-page-check', shareModal ) : null;
		var shareCurrPage = shareModal ? $( '.foliora-share-curr-page', shareModal ) : null;

		var THEMES = [ 'light', 'dark', 'sepia' ];
		var currentTheme = ( function () {
			try {
				var saved = window.localStorage.getItem( 'foliora_theme' );
				if ( saved && THEMES.indexOf( saved ) !== -1 ) {
					return saved;
				}
			} catch ( e ) {}
			return container.getAttribute( 'data-theme' ) || 'light';
		} )();

		function applyTheme( t ) {
			if ( ! shell ) {
				return;
			}
			THEMES.forEach( function ( name ) {
				shell.classList.remove( 'foliora-theme-' + name );
			} );
			shell.classList.add( 'foliora-theme-' + t );
			currentTheme = t;
			try {
				window.localStorage.setItem( 'foliora_theme', t );
			} catch ( e ) {}
		}
		applyTheme( currentTheme );

		function cycleTheme() {
			var idx = THEMES.indexOf( currentTheme );
			var next = THEMES[ ( idx + 1 ) % THEMES.length ];
			applyTheme( next );
		}

		function openModal( modal ) {
			if ( ! modal ) {
				return;
			}
			modal.hidden = false;
			var closeBtn = $( '.foliora-modal-close', modal );
			if ( closeBtn ) {
				closeBtn.focus();
			}
		}

		function closeModal( modal ) {
			if ( ! modal ) {
				return;
			}
			modal.hidden = true;
		}

		function closeAllModals() {
			closeModal( shortcutsModal );
			closeModal( shareModal );
		}

		function getShareUrl() {
			var base = window.location.origin + window.location.pathname + window.location.search;
			var incPage = sharePageCheck ? sharePageCheck.checked : true;
			return incPage ? base + '#page=' + state.page : base;
		}

		function updateShareModal() {
			if ( ! shareModal ) {
				return;
			}
			if ( shareCurrPage ) {
				shareCurrPage.textContent = String( state.page );
			}
			var url = getShareUrl();
			if ( shareInput ) {
				shareInput.value = url;
			}
			var encoded = encodeURIComponent( url );
			var docTitle = encodeURIComponent( document.title || 'PDF Document' );
			var xBtn = $( '.foliora-share-x', shareModal );
			var fbBtn = $( '.foliora-share-fb', shareModal );
			var liBtn = $( '.foliora-share-linkedin', shareModal );
			var waBtn = $( '.foliora-share-wa', shareModal );
			var emBtn = $( '.foliora-share-email', shareModal );
			if ( xBtn ) {
				xBtn.href = 'https://twitter.com/intent/tweet?url=' + encoded + '&text=' + docTitle;
			}
			if ( fbBtn ) {
				fbBtn.href = 'https://www.facebook.com/sharer/sharer.php?u=' + encoded;
			}
			if ( liBtn ) {
				liBtn.href = 'https://www.linkedin.com/sharing/share-offsite/?url=' + encoded;
			}
			if ( waBtn ) {
				waBtn.href = 'https://api.whatsapp.com/send?text=' + docTitle + '%20' + encoded;
			}
			if ( emBtn ) {
				emBtn.href = 'mailto:?subject=' + docTitle + '&body=' + encoded;
			}
		}

		function copyShareLink() {
			if ( ! shareInput ) {
				return;
			}
			var text = shareInput.value;
			var btnText = shareCopyBtn ? $( '.foliora-share-copy-text', shareCopyBtn ) : null;
			var oldText = btnText ? btnText.textContent : 'Copy';

			function showSuccess() {
				if ( btnText ) {
					btnText.textContent = i18n.copied || 'Copied!';
					window.setTimeout( function () {
						btnText.textContent = oldText;
					}, 2000 );
				}
			}

			if ( window.navigator && window.navigator.clipboard && window.isSecureContext ) {
				window.navigator.clipboard.writeText( text ).then( showSuccess ).catch( function () {
					fallbackCopy( text, showSuccess );
				} );
			} else {
				fallbackCopy( text, showSuccess );
			}
		}

		function fallbackCopy( text, cb ) {
			try {
				shareInput.focus();
				shareInput.select();
				var ok = document.execCommand( 'copy' );
				if ( ok && cb ) {
					cb();
				}
			} catch ( e ) {}
		}

		function preferredView() {
			return state.viewMode === 'scroll' || state.viewMode === 'spread' ? state.viewMode : 'page';
		}

		function isNarrow() {
			return container.clientWidth > 0 && container.clientWidth < 640;
		}

		function viewMode() {
			var mode = preferredView();
			if ( mode === 'spread' && isNarrow() ) {
				return 'page';
			}
			return mode;
		}

		function resumeKey() {
			return 'foliora:page:' + fileUrl;
		}

		function pageFromHash() {
			var hash = window.location.hash || '';
			var match = hash.match( /^#(?:foliora-)?page=(\d+)/i );
			if ( match ) {
				return parseInt( match[ 1 ], 10 );
			}
			return 0;
		}

		function persistLocation() {
			window.clearTimeout( persistTimer );
			persistTimer = window.setTimeout( function () {
				if ( container.getAttribute( 'data-hash' ) !== '0' ) {
					var cur = window.location.hash || '';
					var allowed = ! cur || /^#(?:foliora-)?page=\d+/i.test( cur );
					if ( allowed && window.history && window.history.replaceState ) {
						var next = '#page=' + state.page;
						if ( cur !== next ) {
							window.history.replaceState( null, '', window.location.pathname + window.location.search + next );
						}
					}
				}
				if ( container.getAttribute( 'data-resume' ) !== '0' ) {
					try {
						window.localStorage.setItem( resumeKey(), String( state.page ) );
					} catch ( err ) {
						// Private mode or blocked storage.
					}
				}
			}, 80 );
		}

		function dispatchEvent( name, detail ) {
			var evDetail = { fileUrl: fileUrl };
			if ( detail && typeof detail === 'object' ) {
				for ( var k in detail ) {
					if ( Object.prototype.hasOwnProperty.call( detail, k ) ) {
						evDetail[ k ] = detail[ k ];
					}
				}
			}
			container.dispatchEvent(
				new CustomEvent( name, {
					bubbles: true,
					detail: evDetail,
				} )
			);
		}

		function dispatchPage() {
			dispatchEvent( 'foliora:pagechange', { page: state.page, numPages: state.numPages } );
		}

		function highlightCurrentThumb() {
			$$( '.foliora-thumb', thumbsPanel ).forEach( function ( el ) {
				var current = Number( el.getAttribute( 'data-page' ) ) === state.page;
				el.classList.toggle( 'is-current', current );
				if ( current ) {
					el.setAttribute( 'aria-current', 'page' );
				} else {
					el.removeAttribute( 'aria-current' );
				}
			} );
		}

		function viewLabel() {
			var mode = preferredView();
			if ( mode === 'scroll' ) {
				return i18n.viewScroll || 'Continuous scroll';
			}
			if ( mode === 'spread' || mode === 'flip' ) {
				return i18n.viewSpread || 'Two-page spread';
			}
			if ( mode === 'flip' ) {
				return i18n.viewFlip || '3D FlipBook';
			}
			return i18n.viewPage || 'Single page';
		}

		function updateViewButton() {
			if ( ! viewBtn ) {
				return;
			}
			var label = viewLabel();
			viewBtn.setAttribute( 'title', label );
			viewBtn.setAttribute( 'aria-label', label );
			viewBtn.classList.toggle( 'is-active', preferredView() !== 'page' );
		}

		function updateFindStatus() {
			if ( ! findStatus ) {
				return;
			}
			if ( ! state.query ) {
				findStatus.hidden = true;
				findStatus.textContent = '';
				return;
			}
			findStatus.hidden = false;
			if ( ! state.findHits.length ) {
				findStatus.textContent = i18n.findNone || 'No matches';
				return;
			}
			var tpl = i18n.findStatus || '%1$s of %2$s';
			findStatus.textContent = tpl
				.replace( '%1$s', String( state.findAt + 1 ) )
				.replace( '%2$s', String( state.findHits.length ) );
		}

		function updateToolbar() {
			if ( pageInput ) {
				pageInput.value = String( state.page );
				pageInput.max = String( state.numPages );
			}
			if ( pageCount ) {
				pageCount.textContent = String( state.numPages );
			}
			if ( prevBtn ) {
				prevBtn.disabled = state.page <= 1;
			}
			if ( nextBtn ) {
				nextBtn.disabled = state.page >= state.numPages;
			}
			if ( zoomLabel ) {
				if ( state.fitMode === 'page' ) {
					zoomLabel.textContent = i18n.fitPage || 'Fit';
				} else if ( state.fitMode === 'width' ) {
					zoomLabel.textContent = i18n.fitWidth || 'Width';
				} else {
					zoomLabel.textContent = Math.round( state.zoom * 100 ) + '%';
				}
			}
			if ( fitBtn ) {
				var widthMode = state.fitMode === 'width';
				// Label describes what clicking will switch TO, matching cycleFit():
				// from 'page' it goes to width, from anything else (including a
				// manual zoom left in fitMode 'none') it goes to page.
				var nextIsWidth = state.fitMode === 'page';
				fitBtn.classList.toggle( 'is-width', widthMode );
				fitBtn.setAttribute( 'title', nextIsWidth ? ( i18n.fitWidth || 'Fit width' ) : ( i18n.fitPage || 'Fit page' ) );
				fitBtn.setAttribute( 'aria-label', fitBtn.getAttribute( 'title' ) );
			}
			if ( pageLive ) {
				var tpl = i18n.pageStatus || 'Page %1$s of %2$s';
				var label = tpl.replace( '%1$s', String( state.page ) ).replace( '%2$s', String( state.numPages ) );
				pageLive.textContent = label;
				container.setAttribute( 'aria-label', label );
				slots.forEach( function ( slot ) {
					slot.canvas.setAttribute( 'aria-label', label );
				} );
			}
			updateViewButton();
			updateFindStatus();
			highlightCurrentThumb();
		}

		function pageText( n ) {
			if ( state.textCache[ n ] ) {
				return Promise.resolve( state.textCache[ n ] );
			}
			return state.pdf.getPage( n ).then( function ( page ) {
				return page.getTextContent().then( function ( tc ) {
					var text = ( tc.items || [] )
						.map( function ( item ) {
							return item.str || '';
						} )
						.join( ' ' );
					state.textCache[ n ] = text;
					return text;
				} );
			} );
		}

		function highlightQuery( query, layerEl ) {
			if ( ! layerEl ) {
				return false;
			}
			var spans = layerEl.querySelectorAll( 'span' );
			var q = ( query || '' ).toLowerCase();
			var first = null;
			for ( var i = 0; i < spans.length; i++ ) {
				var hit = q && spans[ i ].textContent.toLowerCase().indexOf( q ) !== -1;
				spans[ i ].classList.toggle( 'foliora-hl', !! hit );
				if ( hit && ! first ) {
					first = spans[ i ];
				}
			}
			if ( first && first.scrollIntoView ) {
				first.scrollIntoView( { block: 'center', inline: 'nearest' } );
			}
			return !! first;
		}

		function highlightAll( query ) {
			var q = query == null ? state.query : query;
			var any = false;
			slots.forEach( function ( slot ) {
				if ( highlightQuery( q, slot.textLayer ) ) {
					any = true;
				}
			} );
			return any;
		}

		function availableBox() {
			var cs = window.getComputedStyle( pagesEl );
			var padX = ( parseFloat( cs.paddingLeft ) || 0 ) + ( parseFloat( cs.paddingRight ) || 0 );
			var padY = ( parseFloat( cs.paddingTop ) || 0 ) + ( parseFloat( cs.paddingBottom ) || 0 );
			var baseW = ( pagesEl && pagesEl.clientWidth ) ? pagesEl.clientWidth : container.clientWidth;
			var baseH = ( pagesEl && pagesEl.clientHeight ) ? pagesEl.clientHeight : container.clientHeight;
			var w = Math.max( 40, baseW - padX );
			var h = Math.max( 40, baseH - padY );
			var currentMode = viewMode();
			if ( ( currentMode === 'spread' || currentMode === 'flip' ) && ! isNarrow() ) {
				w = Math.max( 40, ( w - 16 ) / 2 );
			}
			return { width: w, height: h };
		}

		function scaleForPage( page ) {
			var box = availableBox();
			var base = page.getViewport( { scale: 1, rotation: state.rotation } );
			var scale;
			var mode = viewMode();
			if ( mode === 'scroll' ) {
				var fitW = box.width / base.width;
				scale = state.fitMode === 'none' ? fitW * state.zoom : fitW;
			} else if ( state.fitMode === 'width' ) {
				scale = box.width / base.width;
			} else if ( state.fitMode === 'page' ) {
				scale = Math.min( box.width / base.width, box.height / base.height );
			} else {
				var fitScale = Math.min( box.width / base.width, box.height / base.height );
				if ( ! fitScale || fitScale <= 0 ) {
					fitScale = 1;
				}
				scale = fitScale * state.zoom;
			}
			if ( ! scale || scale <= 0 ) {
				scale = 1;
			}
			return scale;
		}

		function goToDest( dest ) {
			if ( ! dest || ! state.pdf ) {
				return Promise.resolve();
			}
			var ready = typeof dest === 'string' ? state.pdf.getDestination( dest ) : Promise.resolve( dest );
			return ready.then( function ( destArray ) {
				if ( ! destArray || ! destArray[ 0 ] ) {
					return;
				}
				return state.pdf.getPageIndex( destArray[ 0 ] ).then( function ( index ) {
					return goTo( index + 1 );
				} );
			} ).catch( function () {
				return undefined;
			} );
		}

		var linkService = {
			externalLinkTarget: 2,
			externalLinkRel: 'noopener noreferrer nofollow',
			externalLinkEnabled: true,
			isInPDF: true,
			addLinkAttributes: function ( link, url ) {
				if ( ! link || ! url ) {
					return;
				}
				link.href = url;
				link.target = '_blank';
				link.rel = 'noopener noreferrer nofollow';
			},
			getDestinationHash: function () {
				return '#';
			},
			getAnchorUrl: function ( hash ) {
				return hash || '#';
			},
			goToDestination: function ( dest ) {
				return goToDest( dest );
			},
			goToPage: function ( pageNumber ) {
				return goTo( pageNumber );
			},
			executeNamedAction: function ( action ) {
				var a = String( action || '' ).toLowerCase();
				if ( a === 'nextpage' ) {
					nextPage();
				} else if ( a === 'prevpage' ) {
					prevPage();
				} else if ( a === 'firstpage' ) {
					goTo( 1 );
				} else if ( a === 'lastpage' ) {
					goTo( state.numPages );
				}
			},
			executeSetOCGState: function () {},
		};

		function renderAnnotations( page, viewport, layerEl ) {
			clearChildren( layerEl );
			layerEl.hidden = false;
			layerEl.style.setProperty( '--scale-factor', String( viewport.scale ) );
			layerEl.style.width = viewport.width + 'px';
			layerEl.style.height = viewport.height + 'px';
			var AL = window.pdfjsLib.AnnotationLayer;
			if ( typeof AL !== 'function' ) {
				return Promise.resolve();
			}
			return page.getAnnotations( { intent: 'display' } ).then( function ( annotations ) {
				if ( ! annotations || ! annotations.length ) {
					return;
				}
				var vp = viewport;
				if ( viewport.clone ) {
					try {
						vp = viewport.clone( { dontFlip: true } );
					} catch ( err ) {
						vp = viewport;
					}
				}
				var layer = new AL( {
					div: layerEl,
					page: page,
					viewport: vp,
					accessibilityManager: null,
					annotationCanvasMap: null,
				} );
				return layer.render( {
					annotations: annotations,
					linkService: linkService,
					downloadManager: null,
					renderForms: false,
					enableScripting: false,
				} );
			} ).catch( function () {
				return undefined;
			} );
		}

		function makeSlot( pageNum ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'foliora-page-wrap';
			wrap.setAttribute( 'data-page', String( pageNum ) );
			var canvas = document.createElement( 'canvas' );
			canvas.className = 'foliora-page';
			canvas.setAttribute( 'role', 'img' );
			var textLayer = document.createElement( 'div' );
			textLayer.className = 'foliora-text-layer';
			var annotLayer = document.createElement( 'div' );
			annotLayer.className = 'annotationLayer foliora-annotation-layer';
			wrap.appendChild( canvas );
			wrap.appendChild( textLayer );
			wrap.appendChild( annotLayer );
			if ( state.slotSize ) {
				wrap.style.width = state.slotSize.w + 'px';
				wrap.style.height = state.slotSize.h + 'px';
			}
			return {
				wrap: wrap,
				canvas: canvas,
				textLayer: textLayer,
				annotLayer: annotLayer,
				pageNum: pageNum,
				rendered: false,
				task: null,
			};
		}

		function spreadPages( page ) {
			if ( page <= 1 ) {
				return [ 1 ];
			}
			var left = page % 2 === 0 ? page : page - 1;
			var pages = [ left ];
			if ( left + 1 <= state.numPages ) {
				pages.push( left + 1 );
			}
			return pages;
		}

		function visibleNums() {
			var mode = viewMode();
			if ( mode === 'scroll' ) {
				var nums = [];
				var i;
				for ( i = 1; i <= state.numPages; i++ ) {
					nums.push( i );
				}
				return nums;
			}
			if ( mode === 'spread' ) {
				return spreadPages( state.page );
			}
			return [ state.page ];
		}

		function renderSlot( slot ) {
			if ( ! state.pdf || ! slot ) {
				return Promise.resolve();
			}
			if ( slot.task && slot.task.cancel ) {
				try {
					slot.task.cancel();
				} catch ( err ) {
					// Stale task is replaced below.
				}
			}
			return state.pdf.getPage( slot.pageNum ).then( function ( page ) {
				var scale = scaleForPage( page );
				var viewport = page.getViewport( { scale: scale, rotation: state.rotation } );
				var outputScale = window.devicePixelRatio || 1;
				slot.canvas.width = Math.floor( viewport.width * outputScale );
				slot.canvas.height = Math.floor( viewport.height * outputScale );
				slot.canvas.style.width = Math.floor( viewport.width ) + 'px';
				slot.canvas.style.height = Math.floor( viewport.height ) + 'px';
				slot.wrap.style.width = Math.floor( viewport.width ) + 'px';
				slot.wrap.style.height = Math.floor( viewport.height ) + 'px';
				state.slotSize = {
					w: Math.floor( viewport.width ),
					h: Math.floor( viewport.height ),
				};
				var ctx = slot.canvas.getContext( '2d' );
				var transform = outputScale !== 1 ? [ outputScale, 0, 0, outputScale, 0, 0 ] : null;
				slot.task = page.render( {
					canvasContext: ctx,
					viewport: viewport,
					transform: transform,
				} );
				return slot.task.promise
					.then( function () {
						slot.rendered = true;
						Promise.resolve( renderTextLayer( page, viewport, slot.textLayer ) )
							.then( function () {
								return renderAnnotations( page, viewport, slot.annotLayer );
							} )
							.then( function () {
								if ( state.query ) {
									highlightQuery( state.query, slot.textLayer );
								}
							} )
							.catch( function () {
								return undefined;
							} );
					} )
					.catch( function ( err ) {
						if ( err && err.name === 'RenderingCancelledException' ) {
							return;
						}
						throw err;
					} );
			} );
		}

		function releaseSlotCanvas( slot ) {
			if ( ! slot ) {
				return;
			}
			if ( slot.task && slot.task.cancel ) {
				try {
					slot.task.cancel();
				} catch ( err ) {
					// Ignore task cancellation error.
				}
				slot.task = null;
			}
			if ( slot.rendered ) {
				slot.rendered = false;
				slot.canvas.width = 1;
				slot.canvas.height = 1;
				var ctx = slot.canvas.getContext( '2d' );
				if ( ctx ) {
					ctx.clearRect( 0, 0, 1, 1 );
				}
				if ( slot.textLayer ) {
					slot.textLayer.replaceChildren();
				}
				if ( slot.annotLayer ) {
					slot.annotLayer.replaceChildren();
				}
			}
		}

		function disconnectPageObserver() {
			if ( pageObserver && pageObserver.disconnect ) {
				pageObserver.disconnect();
			}
			pageObserver = null;
		}

		function unloadSlot( slot ) {
			if ( ! slot || ! slot.rendered ) {
				return;
			}
			if ( slot.task && slot.task.cancel ) {
				try {
					slot.task.cancel();
				} catch ( err ) {
					// Ignore cancel error.
				}
			}
			slot.task = null;
			slot.rendered = false;
			var ctx = slot.canvas.getContext( '2d' );
			if ( ctx && slot.canvas.width && slot.canvas.height ) {
				ctx.clearRect( 0, 0, slot.canvas.width, slot.canvas.height );
			}
			slot.canvas.width = 1;
			slot.canvas.height = 1;
			clearChildren( slot.textLayer );
			clearChildren( slot.annotLayer );
		}

		function rebuildSlots() {
			disconnectPageObserver();
			slots.forEach( function ( slot ) {
				releaseSlotCanvas( slot );
			} );
			slots = [];
			clearChildren( pagesEl );
			var mode = viewMode();
			pagesEl.classList.toggle( 'is-scroll', mode === 'scroll' );
			pagesEl.classList.toggle( 'is-spread', mode === 'spread' );
			pagesEl.classList.toggle( 'is-page', mode === 'page' );
			pagesEl.classList.toggle( 'is-flip', mode === 'flip' );
			visibleNums().forEach( function ( n ) {
				var slot = makeSlot( n );
				slots.push( slot );
				pagesEl.appendChild( slot.wrap );
			} );
			if ( mode === 'scroll' && typeof window.IntersectionObserver === 'function' ) {
				pageObserver = new window.IntersectionObserver(
					function ( entries ) {
						entries.forEach( function ( entry ) {
							var n = Number( entry.target.getAttribute( 'data-page' ) );
							var slot = slots.filter( function ( s ) {
								return s.pageNum === n;
							} )[ 0 ];
							if ( ! slot ) {
								return;
							}
							if ( entry.isIntersecting ) {
								if ( ! slot.rendered ) {
									renderSlot( slot );
								}
							} else {
								// Memory virtualization: release canvas memory if the page is far
								// from the user's active viewport (prevents iOS Safari memory crashes).
								if ( slot.rendered && Math.abs( slot.pageNum - state.page ) > 3 ) {
									releaseSlotCanvas( slot );
								}
							}
						} );
					},
					{ root: pagesEl, rootMargin: '300px 0px', threshold: 0.01 }
				);
				slots.forEach( function ( slot ) {
					pageObserver.observe( slot.wrap );
				} );
			}
		}

		function slotsMatch( expected ) {
			if ( slots.length !== expected.length ) {
				return false;
			}
			return slots.every( function ( slot, i ) {
				return slot.pageNum === expected[ i ];
			} );
		}

		function scrollSlotIntoView( pageNum ) {
			var slot = slots.filter( function ( s ) {
				return s.pageNum === pageNum;
			} )[ 0 ];
			if ( slot && slot.wrap.scrollIntoView ) {
				slot.wrap.scrollIntoView( { block: 'start', inline: 'nearest' } );
			}
		}

		function updateVisibleScrollPage() {
			if ( viewMode() !== 'scroll' || ! slots.length ) {
				return;
			}
			var box = pagesEl.getBoundingClientRect();
			var targetY = box.top + Math.min( box.height, 80 );
			var best = state.page;
			var bestDist = Infinity;
			slots.forEach( function ( slot ) {
				var r = slot.wrap.getBoundingClientRect();
				var d = Math.abs( r.top - targetY );
				if ( d < bestDist ) {
					bestDist = d;
					best = slot.pageNum;
				}
			} );
			if ( best !== state.page ) {
				state.page = best;
				updateToolbar();
				persistLocation();
				dispatchPage();
			}
		}

		function showRenderError( err ) {
			if ( err && err.name === 'RenderingCancelledException' ) {
				return;
			}
			if ( window.console && window.console.error ) {
				window.console.error( 'Foliora: page render failed', err );
			}
			if ( ! status.parentNode ) {
				container.appendChild( status );
			}
			status.hidden = false;
			status.classList.add( 'foliora-error' );
			status.textContent = i18n.error || 'This document could not be loaded.';
			dispatchEvent( 'foliora:error', { stage: 'render' } );
		}

		function renderCurrent() {
			if ( ! state.pdf ) {
				return Promise.resolve();
			}
			container.classList.toggle( 'is-narrow', isNarrow() );
			var mode = viewMode();
			var expected = visibleNums();
			if ( ! slotsMatch( expected ) ) {
				rebuildSlots();
			}
			container.style.overflow = 'hidden';
			pagesEl.style.overflow = ( mode === 'scroll' || state.fitMode === 'none' || state.tool === 'pan' ) ? 'auto' : 'hidden';

			if ( mode === 'scroll' ) {
				slots.forEach( function ( slot ) {
					slot.rendered = false;
				} );
				var nearby = slots.filter( function ( slot ) {
					return Math.abs( slot.pageNum - state.page ) <= 1;
				} );
				var chain = Promise.resolve();
				nearby.forEach( function ( slot ) {
					chain = chain.then( function () {
						return renderSlot( slot );
					} );
				} );
				return chain.then( function () {
					scrollSlotIntoView( state.page );
					updateToolbar();
				} ).catch( showRenderError );
			}

			var all = Promise.resolve();
			slots.forEach( function ( slot ) {
				all = all.then( function () {
					return renderSlot( slot );
				} );
			} );
			return all.then( function () {
				updateToolbar();
			} ).catch( showRenderError );
		}

		function goTo( page, opts ) {
			var silent = opts && opts.silent;
			page = clamp( parseInt( page, 10 ) || 1, 1, state.numPages );
			var changed = page !== state.page;
			state.page = page;
			var mode = viewMode();
			var after = function () {
				persistLocation();
				if ( changed ) {
					var currentThumb = $( '.foliora-thumb.is-current', thumbsPanel );
					if ( currentThumb && currentThumb.scrollIntoView && ! thumbsPanel.hidden ) {
						currentThumb.scrollIntoView( { block: 'nearest' } );
					}
				}
				if ( changed && ! silent ) {
					if ( mode === 'flip' ) {
						playPageTurnSound();
					}
					dispatchPage();
				}
			};
			if ( mode === 'scroll' && slots.length === state.numPages ) {
				var slot = slots.filter( function ( s ) {
					return s.pageNum === page;
				} )[ 0 ];
				return ( slot ? renderSlot( slot ) : Promise.resolve() ).then( function () {
					scrollSlotIntoView( page );
					updateToolbar();
					after();
				} ).catch( showRenderError );
			}
			return renderCurrent().then( after );
		}

		function nextPage() {
			if ( viewMode() === 'spread' ) {
				if ( state.page <= 1 ) {
					return goTo( 2 );
				}
				var left = state.page % 2 === 0 ? state.page : state.page - 1;
				return goTo( left + 2 );
			}
			return goTo( state.page + 1 );
		}

		function prevPage() {
			if ( viewMode() === 'spread' ) {
				if ( state.page <= 2 ) {
					return goTo( 1 );
				}
				var left = state.page % 2 === 0 ? state.page : state.page - 1;
				return goTo( Math.max( 1, left - 2 ) );
			}
			return goTo( state.page - 1 );
		}

		function setZoom( next ) {
			state.fitMode = 'none';
			state.zoom = clamp( next, 0.4, 3 );
			renderCurrent();
			dispatchEvent( 'foliora:zoom', { zoom: state.zoom, fitMode: 'none' } );
		}

		function zoomBy( delta ) {
			setZoom( ( state.fitMode === 'none' ? state.zoom : 1 ) + delta );
		}

		function fitPage() {
			state.fitMode = 'page';
			state.zoom = 1;
			renderCurrent();
			dispatchEvent( 'foliora:zoom', { zoom: 1, fitMode: 'page' } );
		}

		function fitWidth() {
			state.fitMode = 'width';
			state.zoom = 1;
			renderCurrent();
			dispatchEvent( 'foliora:zoom', { zoom: 1, fitMode: 'width' } );
		}

		function cycleFit() {
			if ( state.fitMode === 'page' ) {
				fitWidth();
			} else {
				fitPage();
			}
		}

		function rotatePage() {
			state.rotation = ( state.rotation + 90 ) % 360;
			renderCurrent();
		}

		function cycleView() {
			var i = VIEW_MODES.indexOf( preferredView() );
			var next = VIEW_MODES[ ( i + 1 ) % VIEW_MODES.length ];
			state.viewMode = next;
			container.setAttribute( 'data-view', next );
			slots = [];
			updateViewButton();
			renderCurrent();
		}

		function setTool( tool ) {
			state.tool = tool === 'pan' ? 'pan' : 'select';
			container.classList.toggle( 'is-pan', state.tool === 'pan' );
			if ( panBtn ) {
				panBtn.classList.toggle( 'is-active', state.tool === 'pan' );
				panBtn.setAttribute( 'aria-pressed', state.tool === 'pan' ? 'true' : 'false' );
				var label = state.tool === 'pan' ? ( i18n.select || 'Text select' ) : ( i18n.pan || 'Hand tool' );
				panBtn.setAttribute( 'title', label );
				panBtn.setAttribute( 'aria-label', label );
			}
			pagesEl.style.overflow = ( viewMode() === 'scroll' || state.fitMode === 'none' || state.tool === 'pan' ) ? 'auto' : 'hidden';
		}

		function isCoarsePointer() {
			return !!( window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches );
		}

		function isFs() {
			return !!( document.fullscreenElement || document.webkitFullscreenElement );
		}

		function requestFs( node ) {
			var fn = node.requestFullscreen || node.webkitRequestFullscreen || node.msRequestFullscreen;
			if ( ! fn ) {
				return;
			}
			var req = fn.call( node );
			if ( req && req.catch ) {
				req.catch( function () {
					return undefined;
				} );
			}
		}

		function exitFs() {
			var fn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
			if ( fn ) {
				fn.call( document );
			}
		}

		function setPresentation( on ) {
			state.presentation = !! on;
			if ( shell ) {
				shell.classList.toggle( 'is-present', state.presentation );
				if ( ! state.presentation ) {
					shell.classList.remove( 'is-present-show' );
				} else if ( isCoarsePointer() ) {
					shell.classList.add( 'is-present-show' );
				}
			}
			if ( presentBtn ) {
				presentBtn.classList.toggle( 'is-active', state.presentation );
				presentBtn.setAttribute( 'aria-pressed', state.presentation ? 'true' : 'false' );
			}
			var node = shell || container;
			if ( state.presentation ) {
				if ( ! isFs() ) {
					requestFs( node );
				}
			} else if ( isFs() ) {
				exitFs();
			}
		}

		function toggleFullscreen() {
			var node = shell || container;
			if ( ! isFs() ) {
				requestFs( node );
			} else {
				exitFs();
			}
		}

		/**
		 * Pages to print for the current view mode: both sides of a spread,
		 * every rendered page near the viewport in scroll mode, or just the
		 * current page otherwise. Falls back to whatever slot is actually
		 * rendered so a virtualized (released) canvas is never printed blank.
		 */
		function printPageNums() {
			var mode = viewMode();
			if ( mode === 'spread' ) {
				return spreadPages( state.page );
			}
			if ( mode === 'scroll' ) {
				var rendered = slots
					.filter( function ( s ) {
						return s.rendered;
					} )
					.map( function ( s ) {
						return s.pageNum;
					} );
				return rendered.length ? rendered : [ state.page ];
			}
			return [ state.page ];
		}

		function printCurrent() {
			var printSlots = printPageNums()
				.map( function ( n ) {
					return slots.filter( function ( s ) {
						return s.pageNum === n;
					} )[ 0 ];
				} )
				.filter( function ( s ) {
					return s && s.rendered;
				} );

			if ( ! printSlots.length ) {
				printSlots = slots.filter( function ( s ) {
					return s.rendered;
				} );
			}
			if ( ! printSlots.length ) {
				return;
			}

			dispatchEvent( 'foliora:print', {
				page: state.page,
				pages: printSlots.map( function ( s ) {
					return s.pageNum;
				} ),
			} );

			try {
				var images = printSlots
					.map( function ( s ) {
						return '<img src="' + s.canvas.toDataURL( 'image/png' ) + '" style="max-width:100%;display:block;page-break-after:always;" />';
					} )
					.join( '' );
				var frame = window.open( '', '_blank' );
				if ( ! frame ) {
					return;
				}
				frame.document.write(
					'<!DOCTYPE html><title>Print</title>' +
						images +
						'<script>window.onload=function(){window.focus();window.print();};</script>'
				);
				frame.document.close();
			} catch ( err ) {
				window.open( fileUrl, '_blank', 'noopener,noreferrer' );
			}
		}

		function collectHits( query ) {
			var needle = query.toLowerCase();
			var hits = [];
			var chain = Promise.resolve();
			var n;
			for ( n = 1; n <= state.numPages; n++ ) {
				( function ( pageNum ) {
					chain = chain.then( function () {
						return pageText( pageNum ).then( function ( text ) {
							var c = countNeedle( text, needle );
							var i;
							for ( i = 0; i < c; i++ ) {
								hits.push( pageNum );
							}
						} );
					} );
				} )( n );
			}
			return chain.then( function () {
				return hits;
			} );
		}

		function findInDocument( dir ) {
			var query = findInput ? findInput.value.trim() : '';
			state.query = query;
			if ( ! query || ! state.pdf ) {
				state.findHits = [];
				state.findAt = 0;
				state.findQuery = '';
				highlightAll( '' );
				updateFindStatus();
				return;
			}

			function jump() {
				if ( ! state.findHits.length ) {
					highlightAll( query );
					updateFindStatus();
					dispatchEvent( 'foliora:search', { query: query, hitsCount: 0 } );
					return Promise.resolve();
				}
				if ( dir ) {
					state.findAt = ( state.findAt + dir + state.findHits.length ) % state.findHits.length;
				}
				var page = state.findHits[ state.findAt ];
				return goTo( page ).then( function () {
					highlightAll( query );
					updateFindStatus();
					dispatchEvent( 'foliora:search', { query: query, hitsCount: state.findHits.length, currentHit: state.findAt + 1 } );
				} );
			}

			if ( state.findQuery !== query ) {
				state.findQuery = query;
				state.findAt = 0;
				return collectHits( query ).then( function ( hits ) {
					state.findHits = hits;
					var i = 0;
					for ( ; i < hits.length; i++ ) {
						if ( hits[ i ] >= state.page ) {
							break;
						}
					}
					state.findAt = i < hits.length ? i : 0;
					dir = 0;
					return jump();
				} );
			}
			return jump();
		}

		function clearFind() {
			if ( findInput ) {
				findInput.value = '';
			}
			state.query = '';
			state.findHits = [];
			state.findAt = 0;
			state.findQuery = '';
			highlightAll( '' );
			updateFindStatus();
		}

		function focusFind() {
			if ( toolbar && toolbar.classList.contains && window.matchMedia && window.matchMedia( '(max-width: 720px)' ).matches ) {
				toolbar.classList.add( 'is-more-open' );
				if ( moreBtn ) {
					moreBtn.setAttribute( 'aria-expanded', 'true' );
				}
			}
			if ( findInput ) {
				findInput.focus();
				if ( findInput.select ) {
					findInput.select();
				}
			}
		}

		function revealPanelButton( btn ) {
			if ( ! btn ) {
				return;
			}
			btn.hidden = false;
			if ( panelGroup ) {
				panelGroup.hidden = false;
			}
		}

		function closePanel() {
			side.hidden = true;
			side.setAttribute( 'data-mode', '' );
			container.classList.remove( 'has-side' );
			tocPanel.hidden = true;
			thumbsPanel.hidden = true;
			if ( tocBtn ) {
				tocBtn.setAttribute( 'aria-expanded', 'false' );
			}
			if ( thumbsBtn ) {
				thumbsBtn.setAttribute( 'aria-expanded', 'false' );
			}
			if ( state.pdf ) {
				renderCurrent();
			}
		}

		function openPanel( mode ) {
			side.hidden = false;
			side.setAttribute( 'data-mode', mode );
			container.classList.add( 'has-side' );
			tocPanel.hidden = mode !== 'toc';
			thumbsPanel.hidden = mode !== 'thumbs';
			sideTitle.textContent = mode === 'toc'
				? ( i18n.toc || 'Table of contents' )
				: ( i18n.thumbs || 'Page thumbnails' );
			if ( tocBtn ) {
				tocBtn.setAttribute( 'aria-expanded', mode === 'toc' ? 'true' : 'false' );
			}
			if ( thumbsBtn ) {
				thumbsBtn.setAttribute( 'aria-expanded', mode === 'thumbs' ? 'true' : 'false' );
			}
			if ( mode === 'thumbs' ) {
				var currentThumb = $( '.foliora-thumb.is-current', thumbsPanel );
				if ( currentThumb && currentThumb.scrollIntoView ) {
					currentThumb.scrollIntoView( { block: 'nearest' } );
				}
			}
			if ( state.pdf ) {
				renderCurrent();
			}
		}

		function togglePanel( mode ) {
			if ( ! side.hidden && side.getAttribute( 'data-mode' ) === mode ) {
				closePanel();
			} else {
				openPanel( mode );
			}
		}

		function addOutlineItem( item, depth ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'foliora-toc-item';
			btn.setAttribute( 'data-depth', String( depth ) );
			btn.textContent = item.title || '';
			btn.addEventListener( 'click', function () {
				goToDest( item.dest );
			} );
			tocPanel.appendChild( btn );
		}

		function fillOutline( items ) {
			clearChildren( tocPanel );
			( items || [] ).forEach( function ( item ) {
				addOutlineItem( item, 0 );
				if ( item.items && item.items.length ) {
					item.items.forEach( function ( child ) {
						addOutlineItem( child, 1 );
					} );
				}
			} );
		}

		function renderThumbCanvas( btn ) {
			var pageNum = Number( btn.getAttribute( 'data-page' ) );
			var slot = $( '.foliora-thumb-slot', btn );
			if ( ! pageNum || ! slot || ! state.pdf ) {
				return;
			}
			if ( state.thumbCache[ pageNum ] ) {
				if ( ! slot.contains( state.thumbCache[ pageNum ] ) ) {
					setChildren( slot, state.thumbCache[ pageNum ] );
				}
				return;
			}
			if ( state.thumbPending[ pageNum ] ) {
				return;
			}
			state.thumbPending[ pageNum ] = true;
			state.pdf.getPage( pageNum ).then( function ( page ) {
				var targetW = 96;
				var base = page.getViewport( { scale: 1 } );
				var scale = targetW / base.width;
				var viewport = page.getViewport( { scale: scale } );
				var thumbCanvas = document.createElement( 'canvas' );
				thumbCanvas.width = Math.floor( viewport.width );
				thumbCanvas.height = Math.floor( viewport.height );
				thumbCanvas.setAttribute( 'aria-hidden', 'true' );
				return page.render( {
					canvasContext: thumbCanvas.getContext( '2d' ),
					viewport: viewport,
				} ).promise.then( function () {
					state.thumbCache[ pageNum ] = thumbCanvas;
					setChildren( slot, thumbCanvas );
				} );
			} ).catch( function () {
				state.thumbPending[ pageNum ] = false;
			} );
		}

		function buildThumbs() {
			clearChildren( thumbsPanel );
			if ( thumbObserver && thumbObserver.disconnect ) {
				thumbObserver.disconnect();
			}
			thumbObserver = null;
			if ( typeof window.IntersectionObserver === 'function' ) {
				thumbObserver = new window.IntersectionObserver(
					function ( entries ) {
						entries.forEach( function ( entry ) {
							if ( entry.isIntersecting ) {
								renderThumbCanvas( entry.target );
							}
						} );
					},
					{ root: thumbsPanel, rootMargin: '80px 0px', threshold: 0.01 }
				);
			}
			var n;
			for ( n = 1; n <= state.numPages; n++ ) {
				( function ( pageNum ) {
					var btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'foliora-thumb';
					btn.setAttribute( 'data-page', String( pageNum ) );
					btn.setAttribute( 'aria-label', ( i18n.pageStatus || 'Page %1$s of %2$s' )
						.replace( '%1$s', String( pageNum ) )
						.replace( '%2$s', String( state.numPages ) ) );
					var slot = document.createElement( 'div' );
					slot.className = 'foliora-thumb-slot';
					var label = document.createElement( 'span' );
					label.className = 'foliora-thumb-label';
					label.textContent = String( pageNum );
					btn.appendChild( slot );
					btn.appendChild( label );
					btn.addEventListener( 'click', function () {
						goTo( pageNum );
					} );
					thumbsPanel.appendChild( btn );
					if ( thumbObserver ) {
						thumbObserver.observe( btn );
					} else {
						renderThumbCanvas( btn );
					}
				} )( n );
			}
			highlightCurrentThumb();
		}

		function afterDocumentReady( pdf ) {
			state.pdf = pdf;
			state.numPages = pdf.numPages || 1;
			updateToolbar();
			if ( passwordForm ) {
				passwordForm.remove();
				passwordForm = null;
				passwordInput = null;
				passwordError = null;
				passwordUpdate = null;
			}
			status.remove();
			pagesEl.hidden = false;
			container.setAttribute( 'aria-busy', 'false' );
			buildThumbs();
			revealPanelButton( thumbsBtn );
			if ( pdf.getOutline ) {
				pdf.getOutline().then( function ( outline ) {
					if ( outline && outline.length ) {
						fillOutline( outline );
						revealPanelButton( tocBtn );
					}
				} ).catch( function () {
					return undefined;
				} );
			}
			dispatchEvent( 'foliora:ready', {
				numPages: state.numPages,
				viewMode: viewMode(),
			} );
			return goTo( initialPage(), { silent: true } );
		}

		function initialPage() {
			var fromAttr = parseInt( container.getAttribute( 'data-page' ), 10 ) || 1;
			if ( container.getAttribute( 'data-hash' ) !== '0' ) {
				var fromHash = pageFromHash();
				if ( fromHash > 0 ) {
					return fromHash;
				}
			}
			if ( fromAttr > 1 ) {
				return fromAttr;
			}
			if ( container.getAttribute( 'data-resume' ) !== '0' ) {
				try {
					var saved = parseInt( window.localStorage.getItem( resumeKey() ), 10 );
					if ( saved > 0 ) {
						return saved;
					}
				} catch ( err ) {
					return fromAttr;
				}
			}
			return fromAttr;
		}

		function onHashChange() {
			if ( container.getAttribute( 'data-hash' ) === '0' || ! state.pdf ) {
				return;
			}
			var p = pageFromHash();
			if ( p > 0 && p !== state.page ) {
				goTo( p );
			}
		}
		window.addEventListener( 'hashchange', onHashChange );

		function showPasswordForm( updatePassword, reason ) {
			passwordUpdate = updatePassword;
			status.hidden = true;
			pagesEl.hidden = true;
			if ( ! passwordForm ) {
				passwordForm = document.createElement( 'form' );
				passwordForm.className = 'foliora-password';
				passwordForm.setAttribute( 'role', 'form' );

				var prompt = document.createElement( 'p' );
				prompt.className = 'foliora-password-prompt';
				prompt.textContent = i18n.passwordPrompt || 'This document is password-protected.';

				var fieldId = ( container.id || 'foliora' ) + '-password';
				var label = document.createElement( 'label' );
				label.className = 'foliora-sr-only';
				label.setAttribute( 'for', fieldId );
				label.textContent = i18n.passwordLabel || 'Password';

				passwordInput = document.createElement( 'input' );
				passwordInput.type = 'password';
				passwordInput.id = fieldId;
				passwordInput.className = 'foliora-password-input';
				passwordInput.setAttribute( 'autocomplete', 'off' );
				passwordInput.setAttribute( 'aria-required', 'true' );

				var submit = document.createElement( 'button' );
				submit.type = 'submit';
				submit.className = 'foliora-password-submit';
				submit.textContent = i18n.passwordUnlock || 'Unlock';

				passwordError = document.createElement( 'p' );
				passwordError.className = 'foliora-password-error';
				passwordError.setAttribute( 'role', 'alert' );
				passwordError.hidden = true;
				passwordError.textContent = i18n.passwordWrong || 'Incorrect password.';

				passwordForm.appendChild( prompt );
				passwordForm.appendChild( label );
				passwordForm.appendChild( passwordInput );
				passwordForm.appendChild( submit );
				passwordForm.appendChild( passwordError );
				container.appendChild( passwordForm );

				passwordForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					if ( ! passwordUpdate || ! passwordInput ) {
						return;
					}
					var value = passwordInput.value;
					if ( ! value ) {
						return;
					}
					passwordUpdate( value );
				} );
			}
			if ( passwordError ) {
				passwordError.hidden = reason !== passwordReasonIncorrect();
			}
			if ( passwordInput ) {
				passwordInput.value = '';
				passwordInput.focus();
			}
		}

		container.__folioraGoToPage = function ( page ) {
			return goTo( page );
		};
		container.__folioraGetPage = function () {
			return state.page;
		};

		if ( downloadBtn ) {
			downloadBtn.addEventListener( 'click', function () {
				dispatchEvent( 'foliora:download', { fileUrl: fileUrl } );
			} );
		}

		if ( tocBtn ) {
			tocBtn.addEventListener( 'click', function () {
				togglePanel( 'toc' );
			} );
		}
		if ( thumbsBtn ) {
			thumbsBtn.addEventListener( 'click', function () {
				togglePanel( 'thumbs' );
			} );
		}
		sideClose.addEventListener( 'click', closePanel );
		if ( themeToggleBtn ) {
			themeToggleBtn.addEventListener( 'click', cycleTheme );
		}
		if ( shortcutsBtn ) {
			shortcutsBtn.addEventListener( 'click', function () {
				openModal( shortcutsModal );
			} );
		}
		if ( shareBtn ) {
			shareBtn.addEventListener( 'click', function () {
				updateShareModal();
				openModal( shareModal );
			} );
		}
		if ( shareCopyBtn ) {
			shareCopyBtn.addEventListener( 'click', copyShareLink );
		}
		if ( sharePageCheck ) {
			sharePageCheck.addEventListener( 'change', updateShareModal );
		}

		if ( shortcutsModal ) {
			var scClose = $( '.foliora-modal-close', shortcutsModal );
			var scBackdrop = $( '.foliora-modal-backdrop', shortcutsModal );
			if ( scClose ) {
				scClose.addEventListener( 'click', function () {
					closeModal( shortcutsModal );
				} );
			}
			if ( scBackdrop ) {
				scBackdrop.addEventListener( 'click', function () {
					closeModal( shortcutsModal );
				} );
			}
		}

		if ( shareModal ) {
			var shClose = $( '.foliora-modal-close', shareModal );
			var shBackdrop = $( '.foliora-modal-backdrop', shareModal );
			if ( shClose ) {
				shClose.addEventListener( 'click', function () {
					closeModal( shareModal );
				} );
			}
			if ( shBackdrop ) {
				shBackdrop.addEventListener( 'click', function () {
					closeModal( shareModal );
				} );
			}
		}

		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', prevPage );
		}
		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', nextPage );
		}
		if ( pageInput ) {
			pageInput.addEventListener( 'change', function () {
				goTo( pageInput.value );
			} );
		}
		if ( zoomInBtn ) {
			zoomInBtn.addEventListener( 'click', function () {
				zoomBy( 0.15 );
			} );
		}
		if ( zoomOutBtn ) {
			zoomOutBtn.addEventListener( 'click', function () {
				zoomBy( -0.15 );
			} );
		}
		if ( fitBtn ) {
			fitBtn.addEventListener( 'click', cycleFit );
		}
		if ( rotateBtn ) {
			rotateBtn.addEventListener( 'click', rotatePage );
		}
		if ( viewBtn ) {
			viewBtn.addEventListener( 'click', cycleView );
		}
		if ( panBtn ) {
			panBtn.addEventListener( 'click', function () {
				setTool( state.tool === 'pan' ? 'select' : 'pan' );
			} );
		}
		if ( printBtn ) {
			printBtn.addEventListener( 'click', printCurrent );
		}
		if ( presentBtn ) {
			presentBtn.addEventListener( 'click', function () {
				setPresentation( ! state.presentation );
			} );
		}
		if ( fsBtn ) {
			fsBtn.addEventListener( 'click', toggleFullscreen );
		}
		var moreMenu = toolbar ? $( '.foliora-more-menu', toolbar ) : null;
		if ( moreBtn && moreMenu ) {
			moreBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var isOpen = ! moreMenu.hidden;
				moreMenu.hidden = isOpen;
				moreBtn.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );
				moreBtn.classList.toggle( 'is-active', ! isOpen );
			} );
			document.addEventListener( 'click', function ( e ) {
				if ( ! moreMenu.hidden && ! e.target.closest( '.foliora-more-wrap' ) ) {
					moreMenu.hidden = true;
					moreBtn.setAttribute( 'aria-expanded', 'false' );
					moreBtn.classList.remove( 'is-active' );
				}
			} );
			$$( '.foliora-more-item', moreMenu ).forEach( function ( item ) {
				item.addEventListener( 'click', function () {
					moreMenu.hidden = true;
					moreBtn.setAttribute( 'aria-expanded', 'false' );
					moreBtn.classList.remove( 'is-active' );
				} );
			} );
		}
		var presentTap = null;
		if ( shell ) {
			shell.addEventListener( 'pointerdown', function ( e ) {
				if ( ! state.presentation ) {
					return;
				}
				presentTap = { x: e.clientX, y: e.clientY };
			} );
			shell.addEventListener( 'pointerup', function ( e ) {
				if ( ! state.presentation || ! presentTap ) {
					return;
				}
				var dx = Math.abs( e.clientX - presentTap.x );
				var dy = Math.abs( e.clientY - presentTap.y );
				presentTap = null;
				if ( dx > 12 || dy > 12 ) {
					return;
				}
				if ( e.target && e.target.closest && e.target.closest( '.foliora-chrome, .foliora-side' ) ) {
					return;
				}
				shell.classList.toggle( 'is-present-show' );
			} );
		}
		if ( findInput ) {
			findInput.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					findInDocument( e.shiftKey ? -1 : 1 );
				} else if ( e.key === 'Escape' ) {
					e.preventDefault();
					if ( state.query ) {
						clearFind();
					} else {
						findInput.blur();
					}
				}
			} );
		}
		if ( findNext ) {
			findNext.addEventListener( 'click', function () {
				findInDocument( 1 );
			} );
		}
		if ( findPrev ) {
			findPrev.addEventListener( 'click', function () {
				findInDocument( -1 );
			} );
		}

		var swipeStart = null;
		var didPinch = false;
		var pinchStartDist = 0;
		var pinchStartZoom = 1;
		var pinchRatio = 1;
		var pinchCenter = { x: 0, y: 0 };
		var pan = { active: false, x: 0, y: 0, sl: 0, st: 0 };

		pagesEl.addEventListener( 'pointerdown', function ( e ) {
			if ( state.tool !== 'pan' || e.button !== 0 ) {
				return;
			}
			pan.active = true;
			pan.x = e.clientX;
			pan.y = e.clientY;
			pan.sl = pagesEl.scrollLeft;
			pan.st = pagesEl.scrollTop;
			container.classList.add( 'is-panning' );
			if ( pagesEl.setPointerCapture ) {
				pagesEl.setPointerCapture( e.pointerId );
			}
			e.preventDefault();
		} );
		pagesEl.addEventListener( 'pointermove', function ( e ) {
			if ( ! pan.active ) {
				return;
			}
			pagesEl.scrollLeft = pan.sl - ( e.clientX - pan.x );
			pagesEl.scrollTop = pan.st - ( e.clientY - pan.y );
		} );
		function endPan() {
			pan.active = false;
			container.classList.remove( 'is-panning' );
		}
		pagesEl.addEventListener( 'pointerup', endPan );
		pagesEl.addEventListener( 'pointercancel', endPan );

		pagesEl.addEventListener( 'touchstart', function ( e ) {
			if ( e.touches.length >= 2 ) {
				didPinch = true;
				swipeStart = null;
				pinchStartDist = touchDistance( e.touches[ 0 ], e.touches[ 1 ] );
				pinchStartZoom = state.fitMode === 'none' ? state.zoom : 1;
				pinchRatio = 1;
				var rect = pagesEl.getBoundingClientRect();
				pinchCenter = {
					x: ( ( e.touches[ 0 ].clientX + e.touches[ 1 ].clientX ) / 2 ) - rect.left,
					y: ( ( e.touches[ 0 ].clientY + e.touches[ 1 ].clientY ) / 2 ) - rect.top,
				};
				pagesEl.style.transformOrigin = pinchCenter.x + 'px ' + pinchCenter.y + 'px';
				pagesEl.style.willChange = 'transform';
			} else if ( e.touches.length === 1 && ! didPinch && viewMode() !== 'scroll' && state.tool !== 'pan' ) {
				swipeStart = { x: e.touches[ 0 ].clientX, y: e.touches[ 0 ].clientY };
			}
		}, { passive: true } );

		pagesEl.addEventListener( 'touchmove', function ( e ) {
			if ( e.touches.length >= 2 && pinchStartDist > 0 ) {
				e.preventDefault();
				var dist = touchDistance( e.touches[ 0 ], e.touches[ 1 ] );
				pinchRatio = dist / pinchStartDist;
				if ( pinchRatio && isFinite( pinchRatio ) ) {
					// Phase 1: Hardware-accelerated GPU transform (smooth 60/120 fps, no canvas redraws)
					pagesEl.style.transform = 'scale(' + pinchRatio + ')';
				}
			}
		}, { passive: false } );

		pagesEl.addEventListener( 'touchend', function ( e ) {
			if ( e.touches.length > 0 ) {
				return;
			}
			if ( didPinch ) {
				// Phase 2: Clear GPU transform and perform a single high-resolution canvas redraw
				pagesEl.style.transform = '';
				pagesEl.style.transformOrigin = '';
				pagesEl.style.willChange = '';
				if ( pinchRatio && isFinite( pinchRatio ) && Math.abs( pinchRatio - 1 ) > 0.04 ) {
					setZoom( pinchStartZoom * pinchRatio );
				}
				didPinch = false;
				pinchStartDist = 0;
				pinchRatio = 1;
				swipeStart = null;
				return;
			}
			if ( swipeStart && viewMode() !== 'scroll' && state.tool !== 'pan' ) {
				var endX = ( e.changedTouches[ 0 ] && e.changedTouches[ 0 ].clientX ) || swipeStart.x;
				var endY = ( e.changedTouches[ 0 ] && e.changedTouches[ 0 ].clientY ) || swipeStart.y;
				var dx = endX - swipeStart.x;
				var dy = endY - swipeStart.y;
				if ( Math.abs( dx ) >= 50 && Math.abs( dy ) < 40 ) {
					if ( dx < 0 ) {
						nextPage();
					} else {
						prevPage();
					}
				}
			}
			swipeStart = null;
			didPinch = false;
			pinchStartDist = 0;
			pinchRatio = 1;
		}, { passive: true } );

		pagesEl.addEventListener( 'scroll', function () {
			if ( viewMode() === 'scroll' ) {
				updateVisibleScrollPage();
			}
		}, { passive: true } );

		container.addEventListener( 'keydown', function ( e ) {
			if ( ( e.key === 'f' || e.key === 'F' ) && ( e.ctrlKey || e.metaKey ) ) {
				e.preventDefault();
				focusFind();
				return;
			}
			var tag = ( e.target && e.target.tagName ) || '';
			if ( tag === 'INPUT' || tag === 'TEXTAREA' ) {
				return;
			}
			var mode = viewMode();
			if ( e.key === 'Escape' ) {
				if ( shortcutsModal && ! shortcutsModal.hidden ) {
					e.preventDefault();
					closeModal( shortcutsModal );
					return;
				}
				if ( shareModal && ! shareModal.hidden ) {
					e.preventDefault();
					closeModal( shareModal );
					return;
				}
				if ( state.query ) {
					e.preventDefault();
					clearFind();
					return;
				}
				if ( ! side.hidden ) {
					e.preventDefault();
					closePanel();
					return;
				}
				if ( state.presentation ) {
					e.preventDefault();
					setPresentation( false );
					return;
				}
				if ( isFs() ) {
					e.preventDefault();
					exitFs();
				}
				return;
			}
			if ( e.key === '?' ) {
				e.preventDefault();
				if ( shortcutsModal ) {
					if ( shortcutsModal.hidden ) {
						openModal( shortcutsModal );
					} else {
						closeModal( shortcutsModal );
					}
				}
				return;
			}
			if ( e.key === '/' && ! e.ctrlKey && ! e.metaKey ) {
				e.preventDefault();
				focusFind();
				return;
			}
			if ( e.key === 'd' || e.key === 'D' ) {
				e.preventDefault();
				cycleTheme();
				return;
			}
			if ( ( e.key === 'f' || e.key === 'F' ) && ! e.ctrlKey && ! e.metaKey ) {
				e.preventDefault();
				toggleFullscreen();
				return;
			}
			if ( ( e.key === 'p' || e.key === 'P' ) && ! e.ctrlKey && ! e.metaKey ) {
				e.preventDefault();
				setPresentation( ! state.presentation );
				return;
			}
			if ( state.presentation && e.key === ' ' ) {
				e.preventDefault();
				if ( e.shiftKey ) {
					prevPage();
				} else {
					nextPage();
				}
				return;
			}
			if ( e.key === 'PageDown' || e.key === 'j' || e.key === 'J' ) {
				e.preventDefault();
				nextPage();
			} else if ( e.key === 'PageUp' || e.key === 'k' || e.key === 'K' ) {
				e.preventDefault();
				prevPage();
			} else if ( e.key === 'ArrowLeft' || ( mode !== 'scroll' && e.key === 'ArrowUp' ) ) {
				e.preventDefault();
				prevPage();
			} else if ( e.key === 'ArrowRight' || ( mode !== 'scroll' && e.key === 'ArrowDown' ) ) {
				e.preventDefault();
				nextPage();
			} else if ( e.key === '+' || e.key === '=' ) {
				e.preventDefault();
				zoomBy( 0.15 );
			} else if ( e.key === '-' ) {
				e.preventDefault();
				zoomBy( -0.15 );
			} else if ( e.key === '0' ) {
				e.preventDefault();
				cycleFit();
			} else if ( e.key === 'r' || e.key === 'R' ) {
				e.preventDefault();
				rotatePage();
			} else if ( ( e.key === 'h' || e.key === 'H' ) && ! e.ctrlKey && ! e.metaKey ) {
				e.preventDefault();
				setTool( state.tool === 'pan' ? 'select' : 'pan' );
			} else if ( e.key === 'Home' ) {
				e.preventDefault();
				goTo( 1 );
			} else if ( e.key === 'End' ) {
				e.preventDefault();
				goTo( state.numPages );
			}
		} );

		if ( typeof window.ResizeObserver === 'function' ) {
			var resizeTimer = null;
			new window.ResizeObserver( function () {
				window.clearTimeout( resizeTimer );
				resizeTimer = window.setTimeout( function () {
					if ( state.pdf ) {
						renderCurrent();
					}
				}, 120 );
			} ).observe( container );
		}

		function onFsChange() {
			if ( ! isFs() && state.presentation ) {
				state.presentation = false;
				if ( shell ) {
					shell.classList.remove( 'is-present' );
					shell.classList.remove( 'is-present-show' );
				}
				if ( presentBtn ) {
					presentBtn.classList.remove( 'is-active' );
					presentBtn.setAttribute( 'aria-pressed', 'false' );
				}
			}
			if ( state.pdf ) {
				renderCurrent();
			}
		}
		document.addEventListener( 'fullscreenchange', onFsChange );
		document.addEventListener( 'webkitfullscreenchange', onFsChange );

		function startLoadingDocument() {
			var loadingTask = window.pdfjsLib.getDocument( {
				url: fileUrl,
				onPassword: function ( updatePassword, reason ) {
					showPasswordForm( updatePassword, reason );
				},
			} );
			if ( loadingTask.onProgress !== undefined ) {
				loadingTask.onProgress = function ( ev ) {
					if ( ! status || ! ev || ! ev.total ) {
						return;
					}
					var pct = Math.min( 99, Math.round( ( ev.loaded / ev.total ) * 100 ) );
					var tpl = i18n.loadingPct || 'Loading %s…';
					status.textContent = tpl.replace( '%s', String( pct ) + '%' );
				};
			}
			loadingTask.promise.then( afterDocumentReady ).catch( function ( err ) {
				if ( passwordForm ) {
					passwordForm.remove();
					passwordForm = null;
				}
				status.hidden = false;
				pagesEl.hidden = false;

				var isCrossOrigin = false;
				var remoteDomain = '';
				try {
					var parsedUrl = new URL( fileUrl, window.location.href );
					isCrossOrigin = parsedUrl.origin !== window.location.origin;
					remoteDomain = parsedUrl.hostname;
				} catch ( e ) {
					isCrossOrigin = false;
				}

				var isCorsOrNetwork = isCrossOrigin || ( err && ( /network|cors|fetch|cross-origin|failed to fetch/i.test( ( err && err.message ) || '' ) || ( err && err.name === 'MissingPDFException' ) || ( err && err.name === 'UnknownErrorException' ) ) );

				if ( isCrossOrigin && isCorsOrNetwork ) {
					status.textContent = '';
					status.classList.add( 'foliora-error', 'foliora-cors-error' );

					var corsCard = document.createElement( 'div' );
					corsCard.className = 'foliora-cors-card';

					var icon = document.createElement( 'div' );
					icon.className = 'foliora-cors-icon';
					icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
					corsCard.appendChild( icon );

					var title = document.createElement( 'h4' );
					title.className = 'foliora-cors-title';
					title.textContent = i18n.corsTitle || 'Unable to load document (Cross-Origin Restriction)';
					corsCard.appendChild( title );

					var desc = document.createElement( 'p' );
					desc.className = 'foliora-cors-desc';
					desc.textContent = ( i18n.corsDesc || 'This PDF is hosted on an external domain (%s) which restricts cross-origin access.' ).replace( '%s', remoteDomain || 'remote host' );
					corsCard.appendChild( desc );

					var actionBtn = document.createElement( 'a' );
					actionBtn.className = 'foliora-cors-btn';
					actionBtn.href = fileUrl;
					actionBtn.target = '_blank';
					actionBtn.rel = 'noopener noreferrer';
					actionBtn.textContent = i18n.corsDownload || 'Open / Download PDF directly';
					corsCard.appendChild( actionBtn );

					var adminHint = document.createElement( 'div' );
					adminHint.className = 'foliora-cors-admin-hint';
					adminHint.textContent = i18n.corsAdminHint || 'Site Admin: To enable direct embedding, configure Access-Control-Allow-Origin headers on the host server or upload the PDF to your WordPress Media Library.';
					corsCard.appendChild( adminHint );

					status.appendChild( corsCard );
				} else {
					status.textContent = i18n.error || 'This document could not be loaded.';
					status.classList.add( 'foliora-error' );
				}

				try {
					container.dispatchEvent( new CustomEvent( 'foliora:error', {
						bubbles: true,
						detail: { error: err, isCors: isCrossOrigin, url: fileUrl }
					} ) );
				} catch ( e ) {
					// Fallback if CustomEvent fails
				}

				container.setAttribute( 'aria-busy', 'false' );
			} );
		}

		var isLazy = container.getAttribute( 'data-loading' ) === 'lazy';
		if ( isLazy && typeof window.IntersectionObserver === 'function' ) {
			var lazyPlaceholder = document.createElement( 'div' );
			lazyPlaceholder.className = 'foliora-lazy-placeholder';
			var lazyBtn = document.createElement( 'button' );
			lazyBtn.type = 'button';
			lazyBtn.className = 'foliora-lazy-btn';
			lazyBtn.textContent = i18n.clickToLoad || 'Click to load document';
			lazyPlaceholder.appendChild( lazyBtn );
			container.appendChild( lazyPlaceholder );

			var lazyObserver = new window.IntersectionObserver( function ( entries ) {
				if ( entries[ 0 ] && entries[ 0 ].isIntersecting ) {
					lazyObserver.disconnect();
					if ( lazyPlaceholder.parentNode ) {
						lazyPlaceholder.remove();
					}
					startLoadingDocument();
				}
			}, { rootMargin: '200px' } );

			lazyObserver.observe( container );

			lazyBtn.addEventListener( 'click', function () {
				lazyObserver.disconnect();
				if ( lazyPlaceholder.parentNode ) {
					lazyPlaceholder.remove();
				}
				startLoadingDocument();
			} );
		} else {
			startLoadingDocument();
		}
	}

	var viewerObserver = null;

	function init() {
		var containers = Array.prototype.slice.call(
			document.querySelectorAll( '.foliora-viewer[data-file]:not([data-foliora-defer]):not([data-foliora-bound])' )
		);
		if ( ! containers.length ) {
			return;
		}

		if ( typeof window.IntersectionObserver === 'function' ) {
			if ( ! viewerObserver ) {
				viewerObserver = new window.IntersectionObserver(
					function ( entries ) {
						entries.forEach( function ( entry ) {
							if ( entry.isIntersecting ) {
								viewerObserver.unobserve( entry.target );
								var container = entry.target;
								var toolbar = container.id
									? document.querySelector( '.foliora-toolbar[data-target="' + container.id + '"]' )
									: null;
								bindViewer( container, toolbar );
							}
						} );
					},
					{ rootMargin: '300px 0px', threshold: 0.01 }
				);
			}
			containers.forEach( function ( container ) {
				viewerObserver.observe( container );
			} );
		} else {
			containers.forEach( function ( container ) {
				var toolbar = container.id
					? document.querySelector( '.foliora-toolbar[data-target="' + container.id + '"]' )
					: null;
				bindViewer( container, toolbar );
			} );
		}
	}

	window.FolioraViewer = {
		bind: function ( container ) {
			if ( ! container ) {
				return;
			}
			if ( viewerObserver ) {
				try {
					viewerObserver.unobserve( container );
				} catch ( err ) {
					// Ignore observer error.
				}
			}
			var toolbar = container.id
				? document.querySelector( '.foliora-toolbar[data-target="' + container.id + '"]' )
				: null;
			bindViewer( container, toolbar );
		},
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
