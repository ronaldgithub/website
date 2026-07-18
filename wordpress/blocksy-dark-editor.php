<?php
/**
 * dbaronald.nl — dark editor canvas for the Gutenberg post editor.
 *
 * Companion to blocksy-dark.css (Customizer → Additional CSS). That skin
 * turns Blocksy's palette text colors near-white, and Blocksy applies its
 * palette inside the block editor too — but the editor canvas stays white,
 * making posts unreadable while writing. This snippet darkens the editor
 * canvas to match the site.
 *
 * Install via the WPCode (Code Snippets) plugin in wp-admin:
 *   Plugins → Add New → "WPCode" → install & activate
 *   Code Snippets → + Add Snippet → "Add Your Custom Code" → PHP Snippet
 *   Paste everything below this comment (without the opening <?php line),
 *   set Location: Admin Only, then Activate.
 * Deactivate the snippet to restore the white editor.
 */

add_action( 'enqueue_block_assets', function () {
	if ( ! is_admin() ) {
		return;
	}
	// enqueue_block_assets (not enqueue_block_editor_assets) so the CSS is
	// also copied into the iframed editor canvas on WordPress 6.3+.
	wp_register_style( 'dbaronald-editor-dark', false );
	wp_enqueue_style( 'dbaronald-editor-dark' );
	wp_add_inline_style( 'dbaronald-editor-dark', '
		.editor-styles-wrapper {
			background: #0a0e14 !important;
			color: #dfe7f1 !important;
		}
		.editor-styles-wrapper .wp-block-post-title,
		.editor-styles-wrapper h1,
		.editor-styles-wrapper h2,
		.editor-styles-wrapper h3,
		.editor-styles-wrapper h4 { color: #dfe7f1 !important; }
		.editor-styles-wrapper .wp-block-post-title::placeholder,
		.editor-styles-wrapper [data-rich-text-placeholder]::after { color: #93a1b5 !important; }
		.editor-styles-wrapper a { color: #38bdf8; }
		.editor-styles-wrapper code {
			color: #22d3ee;
			background: #121927;
			border-radius: 4px;
			padding: .1em .35em;
		}
		.editor-styles-wrapper pre,
		.editor-styles-wrapper .wp-block-code,
		.editor-styles-wrapper .wp-block-preformatted {
			background: #121927 !important;
			color: #dfe7f1;
			border: 1px solid #223047;
			border-radius: 10px;
			padding: 16px;
		}
		.editor-styles-wrapper pre code { background: none; padding: 0; }
		.editor-styles-wrapper blockquote,
		.editor-styles-wrapper .wp-block-quote {
			border-left: 3px solid #38bdf8;
			background: #0e1420;
			color: #93a1b5;
		}
		.editor-styles-wrapper table th { background: #121927; color: #dfe7f1; }
		.editor-styles-wrapper table td,
		.editor-styles-wrapper table th { border-color: #223047; }
	' );
} );
