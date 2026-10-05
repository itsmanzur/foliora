/**
 * Foliora Viewer block — editor registration (no build step).
 *
 * Uses wp.blockEditor (not the deprecated wp.editor package) and draws
 * the first PDF page onto a canvas so editors see a real preview.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var __ = i18n.__;
	var sprintf = i18n.sprintf || function ( fmt, arg ) { return fmt.replace( '%d', arg ); };
	var registerBlockType = blocks.registerBlockType;
	var InspectorControls = blockEditor.InspectorControls;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var RangeControl = components.RangeControl;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var Button = components.Button;
	var config = window.FolioraBlock || {};

	function heightToPx( value ) {
		var n = parseInt( value, 10 );
		return isNaN( n ) ? 600 : n;
	}

	function PdfFirstPage( props ) {
		var canvasRef = useRef( null );

		useEffect(
			function () {
				var canvas = canvasRef.current;
				if ( ! props.file || ! canvas || typeof window.pdfjsLib === 'undefined' ) {
					return undefined;
				}

				var cancelled = false;
				window.pdfjsLib.GlobalWorkerOptions.workerSrc = config.workerSrc;

				window.pdfjsLib
					.getDocument( props.file )
					.promise.then( function ( pdf ) {
						return pdf.getPage( 1 );
					} )
					.then( function ( page ) {
						if ( cancelled || ! canvasRef.current ) {
							return;
						}
						var target = canvasRef.current;
						var parentWidth = ( target.parentNode && target.parentNode.clientWidth ) || 640;
						var unscaled = page.getViewport( { scale: 1 } );
						var scale = parentWidth / unscaled.width;
						var viewport = page.getViewport( { scale: scale } );
						target.width = Math.floor( viewport.width );
						target.height = Math.floor( viewport.height );
						return page.render( {
							canvasContext: target.getContext( '2d' ),
							viewport: viewport,
						} ).promise;
					} )
					.catch( function () {
						return undefined;
					} );

				return function () {
					cancelled = true;
				};
			},
			[ props.file ]
		);

		return el( 'canvas', {
			ref: canvasRef,
			className: 'foliora-block-preview-canvas',
			'aria-hidden': 'true',
		} );
	}

	registerBlockType( 'foliora/viewer', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var heightPx = heightToPx( attributes.height );
			var embedId = parseInt( attributes.id, 10 ) || 0;

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Viewer Settings', 'foliora' ), initialOpen: true },
						embedId > 0
							? el( 'p', { style: { fontStyle: 'italic', marginBottom: '12px' } }, sprintf( __( 'Using saved embed #%d. Attributes below override saved defaults.', 'foliora' ), embedId ) )
							: null,
						el( TextControl, {
							label: __( 'Width', 'foliora' ),
							help: __( 'CSS width, for example 100% or 720px.', 'foliora' ),
							value: attributes.width,
							onChange: function ( value ) {
								setAttributes( { width: value } );
							},
						} ),
						el( RangeControl, {
							label: __( 'Height (px)', 'foliora' ),
							value: heightPx,
							min: 280,
							max: 1200,
							step: 20,
							onChange: function ( value ) {
								setAttributes( { height: String( value ) + 'px' } );
							},
						} ),
						el( TextControl, {
							label: __( 'Title', 'foliora' ),
							help: __( 'Optional caption above the viewer.', 'foliora' ),
							value: attributes.title || '',
							onChange: function ( value ) {
								setAttributes( { title: value } );
							},
						} ),
						el( RangeControl, {
							label: __( 'Start page', 'foliora' ),
							value: attributes.page || 1,
							min: 1,
							max: 999,
							onChange: function ( value ) {
								setAttributes( { page: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'View', 'foliora' ),
							value: attributes.view || 'page',
							options: [
								{ label: __( 'Single page', 'foliora' ), value: 'page' },
								{ label: __( 'Continuous scroll', 'foliora' ), value: 'scroll' },
								{ label: __( 'Two-page spread', 'foliora' ), value: 'spread' },
								{ label: __( '3D FlipBook', 'foliora' ), value: 'flip' },
							],
							onChange: function ( value ) {
								setAttributes( { view: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Remember last page on this device', 'foliora' ),
							checked: false !== attributes.resume,
							onChange: function ( value ) {
								setAttributes( { resume: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Show download', 'foliora' ),
							checked: false !== attributes.download,
							onChange: function ( value ) {
								setAttributes( { download: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Show print', 'foliora' ),
							checked: false !== attributes.print,
							onChange: function ( value ) {
								setAttributes( { print: value } );
							},
						} )
					)
				),
				embedId > 0 && ! attributes.file
					? el(
							'div',
							{
								className: 'foliora-block-editor-preview is-saved-embed',
								style: {
									textAlign: 'center',
									padding: '36px 20px',
									background: '#f6f7f7',
									border: '1px dashed #c3c4c7',
									borderRadius: '4px',
								},
							},
							el( 'span', {
								className: 'dashicons dashicons-media-document',
								style: {
									fontSize: '36px',
									width: '36px',
									height: '36px',
									color: '#2271b1',
									margin: '0 auto 8px',
									display: 'block',
								},
							} ),
							el(
								'p',
								{
									style: {
										fontWeight: '600',
										margin: '0 0 6px',
										fontSize: '14px',
										color: '#1d2327',
									},
								},
								sprintf( __( 'Saved embed #%d', 'foliora' ), embedId )
							),
							el(
								'p',
								{
									className: 'description',
									style: { margin: 0, color: '#646970' },
								},
								__( 'Configured in Foliora → Embed Builder. The front end will display the full interactive viewer.', 'foliora' )
							)
					  )
					: el(
							'div',
							{
								className: 'foliora-block-editor-preview' + ( attributes.file ? ' is-selected-file' : '' ),
							},
							el(
								MediaUploadCheck,
								null,
								el( MediaUpload, {
									onSelect: function ( media ) {
										if ( media && media.url ) {
											setAttributes( { file: media.url } );
										}
									},
									allowedTypes: [ 'application/pdf' ],
									value: attributes.file,
									render: function ( obj ) {
										return el(
											'div',
											{ className: 'foliora-block-editor-bar' },
											el(
												Button,
												{
													onClick: obj.open,
													variant: 'secondary',
												},
												attributes.file
													? __( 'Replace PDF', 'foliora' )
													: __( 'Select PDF', 'foliora' )
											),
											attributes.file
												? el(
														'p',
														{ className: 'foliora-block-editor-filename' },
														attributes.file
												  )
												: null
										);
									},
								} )
							),
							attributes.file
								? el( PdfFirstPage, { file: attributes.file } )
								: el(
										'p',
										{ className: 'foliora-block-editor-placeholder' },
										__( 'Select a PDF to preview the first page here. Visitors will see the full Foliora viewer.', 'foliora' )
								  )
					  )
			);
		},

		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
