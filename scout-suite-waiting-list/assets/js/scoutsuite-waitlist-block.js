/**
 * Scout Suite Waiting List block.
 *
 * Dynamic block with no build step: the editor shows a simple placeholder
 * and the real form is rendered in PHP on the front end.
 */
( function ( blocks, element, blockEditor, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'scoutsuite/waitlist', {
		title: __( 'Scout Suite Waiting List', 'scoutsuite-waitlist' ),
		description: __( 'A public form for parents to join your group\'s waiting list in Scout Suite.', 'scoutsuite-waitlist' ),
		icon: 'groups',
		category: 'widgets',
		keywords: [ 'scout', 'waiting list', 'waitlist', 'signup' ],
		supports: {
			html: false,
			multiple: false
		},

		edit: function () {
			var blockProps = blockEditor.useBlockProps
				? blockEditor.useBlockProps( { className: 'sswl-block-placeholder' } )
				: { className: 'sswl-block-placeholder' };

			return el(
				'div',
				blockProps,
				el( 'strong', {}, __( 'Scout Suite Waiting List', 'scoutsuite-waitlist' ) ),
				el(
					'p',
					{},
					__( 'The waiting list form appears here on the published page. Configure it under Settings, Scout Suite. Use this block on a Group site, not as a District Group picker.', 'scoutsuite-waitlist' )
				)
			);
		},

		// Dynamic block: nothing is saved to post content.
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.i18n );
