( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var RichText           = wp.blockEditor.RichText;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var el                 = wp.element.createElement;
	var __                 = wp.i18n.__;

	/**
	 * The section block allows exactly one core/list as an inner block.
	 * The section heading is a RichText attribute (plain text only).
	 */
	registerBlockType( 'llms-txt/section', {

		edit: function ( props ) {
			var blockProps = useBlockProps( {
				className: 'llms-txt-section',
			} );

			return el(
				'div',
				blockProps,

				// Section heading — styled to look like an H2 in the editor.
				el(
					'div',
					{ className: 'llms-txt-section__heading-wrap' },
					el(
						'span',
						{ className: 'llms-txt-section__prefix' },
						'## '
					),
					el( RichText, {
						tagName:          'span',
						className:        'llms-txt-section__heading',
						value:            props.attributes.sectionTitle,
						onChange:         function ( val ) {
							// Strip any HTML — section titles are plain text only.
							props.setAttributes( {
								sectionTitle: val.replace( /<[^>]+>/g, '' ),
							} );
						},
						placeholder:      __( 'Section heading…', 'lh-ai-legibility' ),
						allowedFormats:   [],   // no bold, italic, links — plain text
						disableLineBreaks: true,
					} )
				),

				// Inner blocks — only core/list is allowed.
				el( InnerBlocks, {
					allowedBlocks: [ 'core/list' ],
					template: [
						[ 'core/list', {
							ordered: false,
							values:  '<li><a href="">Link title</a>: Link description</li>',
						} ],
					],
					templateLock: 'insert',
					renderAppender: false,
				} )
			);
		},

		save: function ( props ) {
			var blockProps = useBlockProps.save( {
				className: 'llms-txt-section',
			} );

			return el(
				'div',
				blockProps,
				el( RichText.Content, {
					tagName:   'h2',
					className: 'llms-txt-section__heading',
					value:     props.attributes.sectionTitle,
				} ),
				el( InnerBlocks.Content )
			);
		},
	} );

} )( window.wp );
