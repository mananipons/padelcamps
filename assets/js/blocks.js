/**
 * PadelCamps blocks (editor side). Titles/attributes come from PHP
 * (register_block_type); this only provides the inspector and a live preview.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;
	var data = window.padelcampsBlocks || { tournaments: [], labels: {} };
	var L = data.labels;

	function tournament( id ) {
		for ( var i = 0; i < data.tournaments.length; i++ ) {
			if ( data.tournaments[ i ].id === id ) {
				return data.tournaments[ i ];
			}
		}
		return null;
	}

	function edit( name, opts ) {
		return function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var controls = [
				el( SelectControl, {
					key: 'id',
					label: L.tournament,
					value: a.id || 0,
					options: [ { label: '—', value: 0 } ].concat(
						data.tournaments.map( function ( t ) {
							return { label: t.name, value: t.id };
						} )
					),
					onChange: function ( v ) {
						set( { id: parseInt( v, 10 ) || 0, category: 0 } );
					},
				} ),
			];
			var t = tournament( a.id );
			if ( opts.category && t && t.categories.length > 1 ) {
				controls.push(
					el( SelectControl, {
						key: 'category',
						label: L.category,
						value: a.category || 0,
						options: [ { label: L.all, value: 0 } ].concat(
							t.categories.map( function ( c ) {
								return { label: c.name, value: c.id };
							} )
						),
						onChange: function ( v ) {
							set( { category: parseInt( v, 10 ) || 0 } );
						},
					} )
				);
			}
			if ( opts.live ) {
				controls.push(
					el( ToggleControl, { key: 'live', label: L.live, checked: a.live !== false, onChange: function ( v ) { set( { live: v } ); } } ),
					el( ToggleControl, { key: 'find', label: L.find, checked: a.find !== false, onChange: function ( v ) { set( { find: v } ); } } )
				);
			}
			return [
				el( InspectorControls, { key: 'inspector' }, el( PanelBody, { title: L.settings }, controls ) ),
				a.id
					? el( ServerSideRender, { key: 'preview', block: name, attributes: a } )
					: el( 'p', { key: 'empty', style: { padding: '1em', border: '1px dashed #ccc' } }, L.choose ),
			];
		};
	}

	wp.blocks.registerBlockType( 'padelcamps/draw', { edit: edit( 'padelcamps/draw', { category: true, live: true } ), save: function () { return null; } } );
	wp.blocks.registerBlockType( 'padelcamps/schedule', { edit: edit( 'padelcamps/schedule', { category: true, live: true } ), save: function () { return null; } } );
	wp.blocks.registerBlockType( 'padelcamps/registration', { edit: edit( 'padelcamps/registration', {} ), save: function () { return null; } } );
} )( window.wp );
