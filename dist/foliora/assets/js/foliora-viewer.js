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
	var VIEW_MODES = [ 'page', 'scroll', 'spread' ];

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

	function renderTextLayer( page, viewport, layerEl ) {
		layerEl.replaceChildren();
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
		var prevBtn = toolbar ? $( '.foliora-prev', toolbar ) : null;
		var nextBtn = toolbar ? $( '.foliora-next', toolbar ) : null;
		var pageInput = toolbar ? $( '.foliora-page-input', toolbar ) : null;
		var pageCount = toolbar ? $( '.foliora-page-count', toolbar ) : null;
		var zoomInBtn = toolbar ? $( '.foliora-zoom-in', toolbar ) : null;
		var zoomOutBtn = toolbar ? $( '.foliora-zoom-out', toolbar ) : null;
		var zoomLabel = toolbar ? $( '.foliora-zoom-label', toolbar ) : null;
		var fitBtn = toolbar ? $( '.foliora-fit', toolbar ) : null;
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

		function persistLocation() {
			window.clearTimeout( persistTimer );
			persistTimer = window.setTimeout( function () {
				if ( container.getAttribute( 'data-hash' ) !== '0' ) {
					var cur = window.location.hash || '';
					var allowed = ! cur || /^#page=\d+/i.test( cur );
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

		function dispatchPage() {
			container.dispatchEvent(
				new CustomEvent( 'foliora:pagechange', {
					bubbles: true,
					detail: { page: state.page, numPages: state.numPages },
				} )
			);
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
			if ( mode === 'spread' ) {
				return i18n.viewSpread || 'Two-page spread';
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
				fitBtn.classList.toggle( 'is-width', widthMode );
				fitBtn.setAttribute( 'title', widthMode ? ( i18n.fitPage || 'Fit page' ) : ( i18n.fitWidth || 'Fit width' ) );
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
			var w = Math.max( 40, container.clientWidth - padX );
			var h = Math.max( 40, container.clientHeight - padY );
			if ( viewMode() === 'spread' ) {
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
			layerEl.replaceChildren();
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

		function disconnectPageObserver() {
			if ( pageObserver && pageObserver.disconnect ) {
				pageObserver.disconnect();
			}
			pageObserver = null;
		}

		function rebuildSlots() {
			disconnectPageObserver();
			slots.forEach( function ( slot ) {
				if ( slot.task && slot.task.cancel ) {
					try {
						slot.task.cancel();
					} catch ( err ) {
						// Ignore cancelled work.
					}
				}
			} );
			slots = [];
			pagesEl.replaceChildren();
			var mode = viewMode();
			pagesEl.classList.toggle( 'is-scroll', mode === 'scroll' );
			pagesEl.classList.toggle( 'is-spread', mode === 'spread' );
			pagesEl.classList.toggle( 'is-page', mode === 'page' );
			visibleNums().forEach( function ( n ) {
				var slot = makeSlot( n );
				slots.push( slot );
				pagesEl.appendChild( slot.wrap );
			} );
			if ( mode === 'scroll' && typeof window.IntersectionObserver === 'function' ) {
				pageObserver = new window.IntersectionObserver(
					function ( entries ) {
						entries.forEach( function ( entry ) {
							if ( ! entry.isIntersecting ) {
								return;
							}
							var n = Number( entry.target.getAttribute( 'data-page' ) );
							var slot = slots.filter( function ( s ) {
								return s.pageNum === n;
							} )[ 0 ];
							if ( slot && ! slot.rendered ) {
								renderSlot( slot );
							}
						} );
					},
					{ root: pagesEl, rootMargin: '240px 0px', threshold: 0.01 }
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
				} );
			}

			var all = Promise.resolve();
			slots.forEach( function ( slot ) {
				all = all.then( function () {
					return renderSlot( slot );
				} );
			} );
			return all.then( function () {
				updateToolbar();
			} );
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
				} );
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
		}

		function zoomBy( delta ) {
			setZoom( ( state.fitMode === 'none' ? state.zoom : 1 ) + delta );
		}

		function fitPage() {
			state.fitMode = 'page';
			state.zoom = 1;
			renderCurrent();
		}

		function fitWidth() {
			state.fitMode = 'width';
			state.zoom = 1;
			renderCurrent();
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

		function printCurrent() {
			var slot = slots.filter( function ( s ) {
				return s.pageNum === state.page;
			} )[ 0 ] || slots[ 0 ];
			if ( ! slot ) {
				return;
			}
			try {
				var url = slot.canvas.toDataURL( 'image/png' );
				var frame = window.open( '', '_blank' );
				if ( ! frame ) {
					return;
				}
				frame.document.write(
					'<!DOCTYPE html><title>Print</title><img src="' +
						url +
						'" style="max-width:100%;" onload="window.focus();window.print();" />'
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
					return Promise.resolve();
				}
				if ( dir ) {
					state.findAt = ( state.findAt + dir + state.findHits.length ) % state.findHits.length;
				}
				var page = state.findHits[ state.findAt ];
				return goTo( page ).then( function () {
					highlightAll( query );
					updateFindStatus();
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
			tocPanel.hidden = true;
			thumbsPanel.hidden = true;
			if ( tocBtn ) {
				tocBtn.setAttribute( 'aria-expanded', 'false' );
			}
			if ( thumbsBtn ) {
				thumbsBtn.setAttribute( 'aria-expanded', 'false' );
			}
		}

		function openPanel( mode ) {
			side.hidden = false;
			side.setAttribute( 'data-mode', mode );
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
			tocPanel.replaceChildren();
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
					slot.replaceChildren( state.thumbCache[ pageNum ] );
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
					slot.replaceChildren( thumbCanvas );
				} );
			} ).catch( function () {
				state.thumbPending[ pageNum ] = false;
			} );
		}

		function buildThumbs() {
			thumbsPanel.replaceChildren();
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
			return goTo( initialPage(), { silent: true } );
		}

		function initialPage() {
			var fromAttr = parseInt( container.getAttribute( 'data-page' ), 10 ) || 1;
			if ( container.getAttribute( 'data-hash' ) !== '0' ) {
				var match = ( window.location.hash || '' ).match( /^#page=(\d+)/i );
				if ( match ) {
					return parseInt( match[ 1 ], 10 ) || fromAttr;
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
		if ( moreBtn && toolbar ) {
			moreBtn.addEventListener( 'click', function () {
				toolbar.classList.toggle( 'is-more-open' );
				moreBtn.setAttribute( 'aria-expanded', toolbar.classList.contains( 'is-more-open' ) ? 'true' : 'false' );
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
			} else if ( e.touches.length === 1 && ! didPinch && viewMode() !== 'scroll' && state.tool !== 'pan' ) {
				swipeStart = { x: e.touches[ 0 ].clientX, y: e.touches[ 0 ].clientY };
			}
		}, { passive: true } );

		pagesEl.addEventListener( 'touchmove', function ( e ) {
			if ( e.touches.length >= 2 && pinchStartDist > 0 ) {
				e.preventDefault();
				var ratio = touchDistance( e.touches[ 0 ], e.touches[ 1 ] ) / pinchStartDist;
				if ( ratio && isFinite( ratio ) ) {
					setZoom( pinchStartZoom * ratio );
				}
			}
		}, { passive: false } );

		pagesEl.addEventListener( 'touchend', function ( e ) {
			if ( e.touches.length > 0 ) {
				return;
			}
			if ( ! didPinch && swipeStart && viewMode() !== 'scroll' && state.tool !== 'pan' ) {
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
			if ( state.presentation && e.key === ' ' ) {
				e.preventDefault();
				if ( e.shiftKey ) {
					prevPage();
				} else {
					nextPage();
				}
				return;
			}
			if ( e.key === 'PageDown' ) {
				e.preventDefault();
				nextPage();
			} else if ( e.key === 'PageUp' ) {
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
			} else if ( e.key === 'r' || e.key === 'R' ) {
				e.preventDefault();
				rotatePage();
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
		loadingTask.promise.then( afterDocumentReady ).catch( function () {
			if ( passwordForm ) {
				passwordForm.remove();
				passwordForm = null;
			}
			status.hidden = false;
			pagesEl.hidden = false;
			status.textContent = i18n.error || 'This document could not be loaded.';
			status.classList.add( 'foliora-error' );
			container.setAttribute( 'aria-busy', 'false' );
		} );
	}

	function init() {
		document.querySelectorAll( '.foliora-viewer[data-file]:not([data-foliora-defer]):not([data-foliora-bound])' ).forEach( function ( container ) {
			var toolbar = container.id
				? document.querySelector( '.foliora-toolbar[data-target="' + container.id + '"]' )
				: null;
			bindViewer( container, toolbar );
		} );
	}

	window.FolioraViewer = {
		bind: function ( container ) {
			if ( ! container ) {
				return;
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
