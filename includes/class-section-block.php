<?php
/**
 * LH_AI_Legibility_Section_Block
 *
 * Registers the llms-txt/section block on the PHP side and restricts
 * which blocks are allowed inside the llms_txt_document CPT editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LH_AI_Legibility_Section_Block {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init',                        [ $this, 'register_block' ] );
		add_filter( 'allowed_block_types_all',     [ $this, 'restrict_blocks' ], 10, 2 );
	}

	/**
	 * Register the block from block.json.
	 */
	public function register_block(): void {
		register_block_type( LH_AI_LEGIBILITY_PATH . 'blocks/section' );
	}

	/**
	 * Restrict the block inserter inside the llms_txt_document CPT to only
	 * allow blocks that have meaning in an llms.txt document:
	 *
	 *   - llms-txt/section     → link sections (## Heading + list)
	 *   - core/paragraph       → free-form detail text (between blockquote and sections)
	 *   - core/list            → free-form lists in the detail zone
	 *
	 * Everything else — images, embeds, columns, etc. — is excluded because
	 * it has no representation in a plain-text llms.txt file.
	 */
	public function restrict_blocks( $allowed_blocks, $editor_context ): array|bool {
		if (
			! isset( $editor_context->post ) ||
			'llms_txt_document' !== $editor_context->post->post_type
		) {
			return $allowed_blocks;
		}

		return [
			'llms-txt/section',
			'core/paragraph',
			'core/list',
			'core/list-item',
		];
	}
}
