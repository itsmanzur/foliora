/**
 * Foliora Library block — editor registration (no build step).
 *
 * Grid is server-rendered so the editor matches the front-end shortcode.
 */
( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var registerBlockType = blocks.registerBlockType;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var ServerSideRender = serverSideRender;

	registerBlockType( 'foliora/library', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Library Settings', 'foliora' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Columns', 'foliora' ),
							value: attributes.columns,
							min: 1,
							max: 6,
							onChange: function ( value ) {
								setAttributes( { columns: value } );
							},
						} ),
						el( RangeControl, {
							label: __( 'Documents per page', 'foliora' ),
							value: attributes.per_page,
							min: 1,
							max: 48,
							onChange: function ( value ) {
								setAttributes( { per_page: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Order by', 'foliora' ),
							value: attributes.orderby,
							options: [
								{ label: __( 'Date', 'foliora' ), value: 'date' },
								{ label: __( 'Title', 'foliora' ), value: 'title' },
								{ label: __( 'Last modified', 'foliora' ), value: 'modified' },
							],
							onChange: function ( value ) {
								setAttributes( { orderby: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Order', 'foliora' ),
							value: attributes.order,
							options: [
								{ label: __( 'Newest first', 'foliora' ), value: 'DESC' },
								{ label: __( 'Oldest first', 'foliora' ), value: 'ASC' },
							],
							onChange: function ( value ) {
								setAttributes( { order: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Show search', 'foliora' ),
							checked: false !== attributes.search,
							onChange: function ( value ) {
								setAttributes( { search: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'foliora/library',
					attributes: attributes,
				} )
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
	window.wp.i18n,
	window.wp.serverSideRender
);
