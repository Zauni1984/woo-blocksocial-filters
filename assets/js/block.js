/**
 * Gutenberg block registration for the filter panel.
 */
( function ( blocks, element, components, blockEditor, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var data = window.bsfBlockData || { sets: [] };

	blocks.registerBlockType( 'blocksocial/filters', {
		edit: function ( props ) {
			var attributes = props.attributes;

			var inspector = el(
				blockEditor.InspectorControls,
				{},
				el(
					components.PanelBody,
					{ title: __( 'Filter panel', 'woo-blocksocial-filters' ), initialOpen: true },
					el( components.SelectControl, {
						label: __( 'Filter set', 'woo-blocksocial-filters' ),
						value: attributes.set,
						options: [ { value: '', label: __( 'Default', 'woo-blocksocial-filters' ) } ].concat( data.sets ),
						onChange: function ( value ) {
							props.setAttributes( { set: value } );
						}
					} ),
					el( components.SelectControl, {
						label: __( 'Layout', 'woo-blocksocial-filters' ),
						value: attributes.layout,
						options: [
							{ value: '', label: __( 'Use the set default', 'woo-blocksocial-filters' ) },
							{ value: 'vertical', label: __( 'Vertical panel', 'woo-blocksocial-filters' ) },
							{ value: 'horizontal', label: __( 'Horizontal toolbar', 'woo-blocksocial-filters' ) }
						],
						onChange: function ( value ) {
							props.setAttributes( { layout: value } );
						}
					} ),
					el( components.SelectControl, {
						label: __( 'Mode', 'woo-blocksocial-filters' ),
						value: attributes.mode,
						options: [
							{ value: '', label: __( 'Use the set default', 'woo-blocksocial-filters' ) },
							{ value: 'auto', label: __( 'Auto submit', 'woo-blocksocial-filters' ) },
							{ value: 'apply', label: __( 'Select and apply', 'woo-blocksocial-filters' ) },
							{ value: 'step', label: __( 'Step by step', 'woo-blocksocial-filters' ) }
						],
						onChange: function ( value ) {
							props.setAttributes( { mode: value } );
						}
					} ),
					el( components.RangeControl, {
						label: __( 'Columns', 'woo-blocksocial-filters' ),
						value: attributes.columns || 1,
						min: 1,
						max: 4,
						onChange: function ( value ) {
							props.setAttributes( { columns: value } );
						}
					} )
				)
			);

			var preview = el( serverSideRender, {
				block: 'blocksocial/filters',
				attributes: attributes
			} );

			return el( 'div', blockEditor.useBlockProps ? blockEditor.useBlockProps() : {}, inspector, preview );
		},

		save: function () {
			return null;
		}
	} );
}(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.serverSideRender,
	window.wp.i18n
) );
