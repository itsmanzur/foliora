/**
 * Generate persistent first-page PNG thumbnails and extract searchable
 * PDF text on the Documents admin screen. One PDF at a time: load once,
 * then save a thumbnail (foliora_save_thumbnail), text
 * (foliora_save_extracted_text), and/or tagged-PDF flag
 * (foliora_save_a11y).
 */
( function () {
	'use strict';

	var config = window.FolioraThumbGen || {};
	var statusEl = document.querySelector( '.foliora-thumb-status' );
	var jobs = [];
	var rows = document.querySelectorAll( '.foliora-docs-table tbody tr[data-pdf-id]' );
	var i;

	if ( typeof window.pdfjsLib === 'undefined' ) {
		return;
	}

	if ( config.workerSrc ) {
		window.pdfjsLib.GlobalWorkerOptions.workerSrc = config.workerSrc;
	}

	for ( i = 0; i < rows.length; i++ ) {
		var row = rows[ i ];
		var needsThumb = row.getAttribute( 'data-needs-thumb' ) === '1';
		var needsIndex = row.getAttribute( 'data-needs-index' ) === '1';
		var needsA11y = row.getAttribute( 'data-needs-a11y' ) === '1';
		if ( ! needsThumb && ! needsIndex && ! needsA11y ) {
			continue;
		}
		jobs.push( {
			id: row.getAttribute( 'data-pdf-id' ),
			url: row.getAttribute( 'data-pdf-url' ),
			title: row.getAttribute( 'data-pdf-title' ) || '',
			needsThumb: needsThumb,
			needsIndex: needsIndex,
			needsA11y: needsA11y,
			placeholder: row.querySelector( '.foliora-thumb-placeholder' ),
			statusEl: row.querySelector( '.foliora-index-status' ),
			a11yEl: row.querySelector( '.foliora-a11y-status' ),
		} );
	}

	if ( ! jobs.length ) {
		return;
	}

	if ( statusEl ) {
		statusEl.hidden = false;
	}

	function parseJson( text ) {
		var clean = String( text || '' ).replace( /^\uFEFF+/g, '' ).trim();
		var brace = clean.indexOf( '{' );
		if ( brace > 0 ) {
			clean = clean.slice( brace );
		}
		return JSON.parse( clean );
	}

	function isPasswordError( err ) {
		var name = err && err.name ? String( err.name ) : '';
		var msg = err && err.message ? String( err.message ) : '';
		return name === 'PasswordException' || /password/i.test( msg );
	}

	function loadPdf( url ) {
		var task = window.pdfjsLib.getDocument( {
			url: url,
			onPassword: function () {
				if ( task && task.destroy ) {
					task.destroy();
				}
			},
		} );
		return task.promise;
	}

	function renderFirstPage( page ) {
		var targetW = 400;
		var base = page.getViewport( { scale: 1 } );
		var scale = targetW / base.width;
		var viewport = page.getViewport( { scale: scale } );
		var canvas = document.createElement( 'canvas' );
		canvas.width = Math.floor( viewport.width );
		canvas.height = Math.floor( viewport.height );
		return page
			.render( {
				canvasContext: canvas.getContext( '2d' ),
				viewport: viewport,
			} )
			.promise.then( function () {
				return canvas.toDataURL( 'image/png' );
			} );
	}

	function extractText( pdf ) {
		var maxPages = parseInt( config.maxPages, 10 ) || 40;
		var maxChars = parseInt( config.maxChars, 10 ) || 80000;
		var num = Math.min( pdf.numPages, maxPages );
		var parts = [];
		var chain = Promise.resolve();
		var pageNum;

		for ( pageNum = 1; pageNum <= num; pageNum++ ) {
			chain = chain.then(
				( function ( n ) {
					return function () {
						return pdf.getPage( n ).then( function ( page ) {
							return page.getTextContent().then( function ( content ) {
								var items = content && content.items ? content.items : [];
								var text = items
									.map( function ( item ) {
										return item.str || '';
									} )
									.join( ' ' );
								parts.push( text );
							} );
						} );
					};
				} )( pageNum )
			);
		}

		return chain.then( function () {
			var all = parts.join( '\n' ).replace( /\s+/g, ' ' ).trim();
			if ( all.length > maxChars ) {
				all = all.slice( 0, maxChars );
			}
			return { text: all, pages: num };
		} );
	}

	function postForm( fields ) {
		var body = new window.FormData();
		var key;
		body.append( 'nonce', config.nonce || '' );
		for ( key in fields ) {
			if ( Object.prototype.hasOwnProperty.call( fields, key ) ) {
				body.append( key, fields[ key ] );
			}
		}
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( response ) {
				return response.text();
			} )
			.then( parseJson );
	}

	function saveThumbnail( pdfId, dataUrl ) {
		return postForm( {
			action: 'foliora_save_thumbnail',
			attachment_id: String( pdfId ),
			thumbnail_data: dataUrl,
		} );
	}

	function saveIndex( pdfId, text, pages ) {
		return postForm( {
			action: 'foliora_save_extracted_text',
			attachment_id: String( pdfId ),
			text: text,
			pages: String( pages ),
		} );
	}

	function saveA11y( pdfId, tagged ) {
		return postForm( {
			action: 'foliora_save_a11y',
			attachment_id: String( pdfId ),
			tagged: tagged ? '1' : '0',
		} );
	}

	function checkTagged( pdf ) {
		if ( ! pdf || typeof pdf.getMarkInfo !== 'function' ) {
			return Promise.resolve( false );
		}
		return pdf.getMarkInfo().then( function ( info ) {
			return !!( info && info.marked );
		} ).catch( function () {
			return false;
		} );
	}

	function markA11y( job, tagged ) {
		if ( ! job.a11yEl ) {
			return;
		}
		job.a11yEl.classList.remove( 'is-pending', 'is-ready', 'is-warn' );
		if ( tagged ) {
			job.a11yEl.classList.add( 'is-ready' );
			job.a11yEl.textContent = config.i18n && config.i18n.tagged
				? config.i18n.tagged
				: 'Tagged';
			job.a11yEl.removeAttribute( 'title' );
			return;
		}
		job.a11yEl.classList.add( 'is-warn' );
		job.a11yEl.textContent = config.i18n && config.i18n.untagged
			? config.i18n.untagged
			: 'Untagged';
		job.a11yEl.setAttribute(
			'title',
			config.i18n && config.i18n.untaggedHint
				? config.i18n.untaggedHint
				: 'This PDF has no accessibility tags. Export it from Word, InDesign, or Acrobat as a tagged PDF so screen readers can follow headings and reading order.'
		);
	}

	function swapInImage( placeholder, url, alt ) {
		if ( ! placeholder ) {
			return;
		}
		var img = document.createElement( 'img' );
		img.src = url;
		img.alt = alt || '';
		img.className = 'foliora-docs-thumb-img';
		img.loading = 'lazy';
		if ( typeof placeholder.replaceWith === 'function' ) {
			placeholder.replaceWith( img );
		} else if ( placeholder.parentNode ) {
			placeholder.parentNode.replaceChild( img, placeholder );
		}
	}

	function markIndexed( job, empty ) {
		if ( ! job.statusEl ) {
			return;
		}
		job.statusEl.classList.remove( 'is-pending' );
		if ( empty ) {
			job.statusEl.classList.add( 'is-empty' );
			job.statusEl.textContent = config.i18n && config.i18n.noText
				? config.i18n.noText
				: 'No text';
			return;
		}
		job.statusEl.classList.add( 'is-ready' );
		job.statusEl.textContent = config.i18n && config.i18n.indexed
			? config.i18n.indexed
			: 'Indexed';
	}

	function processNext() {
		var job = jobs.shift();
		if ( ! job ) {
			if ( statusEl ) {
				statusEl.hidden = true;
			}
			return;
		}

		if ( ! job.id || ! job.url ) {
			processNext();
			return;
		}

		loadPdf( job.url )
			.then( function ( pdf ) {
				var chain = Promise.resolve();

				if ( job.needsThumb ) {
					chain = chain.then( function () {
						return pdf.getPage( 1 ).then( renderFirstPage ).then( function ( dataUrl ) {
							return saveThumbnail( job.id, dataUrl ).then( function ( json ) {
								if ( json && json.success && json.data && json.data.url ) {
									swapInImage( job.placeholder, json.data.url, job.title );
								}
							} );
						} );
					} );
				}

				if ( job.needsIndex ) {
					chain = chain.then( function () {
						return extractText( pdf ).then( function ( result ) {
							return saveIndex( job.id, result.text, result.pages ).then( function ( json ) {
								if ( json && json.success ) {
									markIndexed( job, !!( json.data && json.data.empty ) );
								}
							} );
						} );
					} );
				}

				if ( job.needsA11y ) {
					chain = chain.then( function () {
						return checkTagged( pdf ).then( function ( tagged ) {
							return saveA11y( job.id, tagged ).then( function ( json ) {
								if ( json && json.success ) {
									markA11y( job, !!( json.data && json.data.tagged ) );
								}
							} );
						} );
					} );
				}

				return chain;
			} )
			.catch( function ( err ) {
				if ( job.needsIndex && isPasswordError( err ) ) {
					return saveIndex( job.id, '', 0 ).then( function () {
						markIndexed( job, true );
					} );
				}
				return undefined;
			} )
			.then( processNext );
	}

	processNext();
} )();
