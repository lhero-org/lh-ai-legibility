<?php
/**
 * LH_AI_Legibility_Llms_Txt
 *
 * Registers the llms_txt_document CPT and generates /llms.txt and
 * /llms-full.txt from the published document.
 *
 * Document structure (block editor):
 *   - Post excerpt              → blockquote (site description)
 *   - core/paragraph blocks     → free-form detail text between blockquote and sections
 *   - core/list blocks          → free-form lists in the detail zone
 *   - llms-txt/section blocks   → each link section (## heading + list of links)
 *
 * Each llms-txt/section block contains:
 *   - sectionTitle attribute  → ## Section heading
 *   - core/list inner block   → list items, each a linked item with optional description
 *
 * Only one published llms_txt_document is expected per site. If multiple
 * exist, the most recently modified is used.
 *
 * Filters:
 *   lh_ai_legibility_llms_txt_output   — filter the final /llms.txt string
 *   lh_ai_legibility_llms_full_output  — filter the final /llms-full.txt string
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LH_AI_Legibility_Llms_Txt {

	const POST_TYPE         = 'llms_txt_document';
	const REWRITE_TAG_INDEX = 'lh_llms_txt';
	const REWRITE_TAG_FULL  = 'lh_llms_full_txt';
	const OPTION_KEY        = 'lh_ai_legibility_document_id';

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init',              [ $this, 'register_post_type' ] );
		add_action( 'init',              [ $this, 'register_rewrite_rules' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ], 1 );

		// Singleton creation and rewrite flush on activation.
		register_activation_hook(
			LH_AI_LEGIBILITY_PATH . 'lh-ai-legibility.php',
			[ $this, 'on_activation' ]
		);

		// Flush rewrite rules when the document is saved.
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'flush_rewrite_rules' ] );

		// Guard: prevent a second document from being published.
		add_filter( 'wp_insert_post_data', [ $this, 'guard_duplicate_publish' ], 10, 2 );

		// Show admin notice if the guard fired.
		add_action( 'admin_notices', [ $this, 'show_duplicate_notice' ] );

		// Replace the CPT list-table menu link with a direct edit link.
		add_action( 'admin_menu', [ $this, 'replace_menu_link' ] );
	}

	// -------------------------------------------------------------------------
	// CPT
	// -------------------------------------------------------------------------

	public function register_post_type(): void {
		register_post_type( self::POST_TYPE, [
			'label'          => __( 'LLMs.txt Document', 'lh-ai-legibility' ),
			'labels'         => [
				'name'          => __( 'LLMs.txt Documents', 'lh-ai-legibility' ),
				'singular_name' => __( 'LLMs.txt Document', 'lh-ai-legibility' ),
				'add_new'       => __( 'Add Document', 'lh-ai-legibility' ),
				'add_new_item'  => __( 'Add LLMs.txt Document', 'lh-ai-legibility' ),
				'edit_item'     => __( 'Edit LLMs.txt Document', 'lh-ai-legibility' ),
				'view_item'     => __( 'Preview LLMs.txt', 'lh-ai-legibility' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,   // We add our own direct-edit link via replace_menu_link()
			'show_in_rest'    => true,
			'supports'        => [ 'title', 'editor', 'excerpt', 'revisions' ],
			'rewrite'         => false,
			'has_archive'     => false,
			'capability_type' => 'page',
			'description'     => __( 'Defines the content of /llms.txt for AI crawler discovery.', 'lh-ai-legibility' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Rewrite rules
	// -------------------------------------------------------------------------

	public function register_rewrite_rules(): void {
		add_rewrite_tag( '%' . self::REWRITE_TAG_INDEX . '%', '1' );
		add_rewrite_tag( '%' . self::REWRITE_TAG_FULL  . '%', '1' );

		add_rewrite_rule( '^llms\.txt$',      'index.php?' . self::REWRITE_TAG_INDEX . '=1', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?' . self::REWRITE_TAG_FULL  . '=1', 'top' );
	}

	public function flush_rewrite_rules(): void {
		$this->register_rewrite_rules();
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Singleton management
	// -------------------------------------------------------------------------

	/**
	 * Activation handler — create the singleton draft and flush rewrite rules.
	 */
	public function on_activation(): void {
		$this->maybe_create_singleton();
		$this->flush_rewrite_rules();
	}

	/**
	 * Create the singleton document post if it doesn't exist yet.
	 * Always created as draft so nothing goes live until explicitly published.
	 */
	public function maybe_create_singleton(): void {
		// Already exists — nothing to do.
		if ( $this->get_singleton_id() ) {
			return;
		}

		$post_id = wp_insert_post( [
			'post_type'    => self::POST_TYPE,
			'post_title'   => get_bloginfo( 'name' ) . ' — LLMs.txt',
			'post_status'  => 'draft',
			'post_content' => '',
			'post_excerpt' => '',
		] );

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			update_option( self::OPTION_KEY, $post_id, true );
		}
	}

	/**
	 * Get the stored singleton post ID, or 0 if not set / post no longer exists.
	 */
	public function get_singleton_id(): int {
		$id   = (int) get_option( self::OPTION_KEY, 0 );
		$post = $id ? get_post( $id ) : null;

		// Validate the stored ID still points to a real post of the right type.
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			delete_option( self::OPTION_KEY );
			return 0;
		}

		return $id;
	}

	/**
	 * Guard: if someone tries to publish a llms_txt_document that isn't the
	 * singleton, force it back to draft and set a transient notice.
	 */
	public function guard_duplicate_publish( array $data, array $postarr ): array {
		if ( self::POST_TYPE !== $data['post_type'] ) {
			return $data;
		}

		if ( 'publish' !== $data['post_status'] ) {
			return $data;
		}

		$singleton_id = $this->get_singleton_id();
		$saving_id    = (int) ( $postarr['ID'] ?? 0 );

		// Allow the singleton itself to publish freely.
		if ( $saving_id && $saving_id === $singleton_id ) {
			return $data;
		}

		// Block any other document from publishing.
		$data['post_status'] = 'draft';

		set_transient(
			'lh_ai_legibility_duplicate_notice_' . get_current_user_id(),
			true,
			30
		);

		return $data;
	}

	/**
	 * Show an admin notice if the duplicate guard fired for this user.
	 */
	public function show_duplicate_notice(): void {
		$transient_key = 'lh_ai_legibility_duplicate_notice_' . get_current_user_id();

		if ( ! get_transient( $transient_key ) ) {
			return;
		}

		delete_transient( $transient_key );

		echo '<div class="notice notice-warning is-dismissible"><p>';
		printf(
			esc_html__( 'LH AI Legibility: Only one LLMs.txt document can be published at a time. This document has been saved as a draft. To publish it, first unpublish the existing document or %sedit the existing document%s instead.', 'lh-ai-legibility' ),
			'<a href="' . esc_url( $this->get_edit_url() ) . '">',
			'</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Replace the auto-generated CPT list-table entry in the Settings menu
	 * with a direct link to edit the singleton document.
	 */
	public function replace_menu_link(): void {
		$edit_url = $this->get_edit_url();

		add_submenu_page(
			'options-general.php',
			__( 'LLMs.txt', 'lh-ai-legibility' ),
			__( 'LLMs.txt', 'lh-ai-legibility' ),
			'manage_options',
			esc_url( $edit_url ),  // slug = URL causes WP to treat this as an external link
			''
		);
	}

	/**
	 * Get the wp-admin edit URL for the singleton document.
	 * Returns new-post URL if singleton hasn't been created yet.
	 */
	private function get_edit_url(): string {
		$id = $this->get_singleton_id();

		if ( $id ) {
			return admin_url( 'post.php?post=' . $id . '&action=edit' );
		}

		return admin_url( 'post-new.php?post_type=' . self::POST_TYPE );
	}

	// -------------------------------------------------------------------------
	// Request handling
	// -------------------------------------------------------------------------

	public function maybe_serve(): void {
		$is_index = get_query_var( self::REWRITE_TAG_INDEX );
		$is_full  = get_query_var( self::REWRITE_TAG_FULL );

		if ( ! $is_index && ! $is_full ) {
			return;
		}

		$document = $this->get_document();

		if ( ! $document ) {
			$this->send( $this->minimal_fallback() );
			return; // send() calls exit; this guards against future refactors.
		}

		if ( $is_full ) {
			$output = $this->build_full( $document );
			$output = apply_filters( 'lh_ai_legibility_llms_full_output', $output );
		} else {
			$output = $this->build_index( $document );
			$output = apply_filters( 'lh_ai_legibility_llms_txt_output', $output );
		}

		$this->send( $output );
	}

	// -------------------------------------------------------------------------
	// Generators
	// -------------------------------------------------------------------------

	public function build_index( WP_Post $document ): string {
		$lines   = [];
		$lines[] = '# ' . get_bloginfo( 'name' );
		$lines[] = '';

		$excerpt = $this->get_excerpt( $document );
		if ( $excerpt ) {
			foreach ( explode( "\n", trim( $excerpt ) ) as $line ) {
				$lines[] = '> ' . $line;
			}
			$lines[] = '';
		}

		$blocks = parse_blocks( $document->post_content );

		foreach ( $blocks as $block ) {
			$rendered = $this->render_block_for_index( $block );
			if ( $rendered !== '' ) {
				$lines[] = $rendered;
				$lines[] = '';
			}
		}

		return trim( implode( "\n", $lines ) ) . "\n";
	}

	public function build_full( WP_Post $document ): string {
		$lines  = [ $this->build_index( $document ) ];
		$blocks = parse_blocks( $document->post_content );

		foreach ( $blocks as $block ) {
			if ( 'llms-txt/section' !== $block['blockName'] ) {
				continue;
			}

			foreach ( $this->extract_links_from_section( $block ) as $link ) {
				$content = $this->fetch_page_markdown( $link['url'] );
				if ( ! $content ) {
					continue;
				}
				$lines[] = '---';
				$lines[] = '';
				$lines[] = $content;
				$lines[] = '';
			}
		}

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Block rendering
	// -------------------------------------------------------------------------

	private function render_block_for_index( array $block ): string {
		switch ( $block['blockName'] ) {
			case 'llms-txt/section':
				return $this->render_section_block( $block );
			case 'core/paragraph':
				return trim( wp_strip_all_tags( render_block( $block ) ) );
			case 'core/list':
				return LH_AI_Legibility_Converter::convert( render_block( $block ) );
			default:
				return '';
		}
	}

	private function render_section_block( array $block ): string {
		$title = trim( $block['attrs']['sectionTitle'] ?? '' );
		if ( ! $title ) {
			return '';
		}

		$lines   = [];
		$lines[] = '## ' . $title;
		$lines[] = '';

		foreach ( $this->extract_links_from_section( $block ) as $link ) {
			$url   = $link['url'];
			$label = $link['label'];
			$desc  = $link['description'];

			$lines[] = $desc
				? "- [{$label}]({$url}): {$desc}"
				: "- [{$label}]({$url})";
		}

		return implode( "\n", $lines );
	}

	/**
	 * Extract links from an llms-txt/section block's inner list.
	 *
	 * @return array<int, array{url: string, label: string, description: string}>
	 */
	private function extract_links_from_section( array $block ): array {
		$links = [];

		foreach ( $block['innerBlocks'] as $inner ) {
			if ( 'core/list' !== $inner['blockName'] ) {
				continue;
			}

			$html = render_block( $inner );
			$dom  = new DOMDocument();

			libxml_use_internal_errors( true );
			$dom->loadHTML(
				'<?xml encoding="utf-8"?>' . $html,
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
			);
			libxml_clear_errors();

			foreach ( $dom->getElementsByTagName( 'li' ) as $li ) {
				$link_data = $this->parse_list_item( $li );
				if ( $link_data ) {
					$links[] = $link_data;
				}
			}
		}

		return $links;
	}

	/**
	 * Parse a <li> DOM node into link data.
	 *
	 * Supported formats:
	 *   <li><a href="url">Label</a></li>
	 *   <li><a href="url">Label</a>: Description</li>
	 *   <li><a href="url">Label</a> — Description</li>
	 */
	private function parse_list_item( DOMElement $li ): ?array {
		$anchors = $li->getElementsByTagName( 'a' );

		if ( $anchors->length === 0 ) {
			return null;
		}

		$anchor = $anchors->item( 0 );
		$url    = trim( $anchor->getAttribute( 'href' ) );
		$label  = trim( $anchor->textContent );

		if ( ! $url || ! $label ) {
			return null;
		}

		$full_text   = trim( $li->textContent );
		$after_link  = trim( substr( $full_text, strlen( $label ) ) );
		// Strip leading separator characters. Use preg_replace so the UTF-8
		// em-dash (—) is matched correctly rather than byte-by-byte via ltrim.
		$description = trim( preg_replace( '/^[\s:—\-]+/u', '', $after_link ) );

		return compact( 'url', 'label', 'description' );
	}

	// -------------------------------------------------------------------------
	// llms-full.txt fetching
	// -------------------------------------------------------------------------

	private function fetch_page_markdown( string $url ): string {
		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status ) {
				$html = apply_filters( 'the_content', $post->post_content );
				return get_the_title( $post ) . "\n\n" .
				       LH_AI_Legibility_Converter::convert( $html );
			}
		}

		$response = wp_remote_get( $url, [
			'timeout'    => 10,
			'user-agent' => 'LocalHero-LLMs-Full/1.0',
			'headers'    => [ 'Accept' => 'text/markdown, text/html' ],
		] );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body         = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( empty( $body ) ) {
			return '';
		}

		if ( str_contains( $content_type, 'text/markdown' ) ) {
			return $body;
		}

		return LH_AI_Legibility_Converter::convert( $body );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_document(): ?WP_Post {
		$id = $this->get_singleton_id();

		if ( ! $id ) {
			return null;
		}

		$post = get_post( $id );

		// Only serve if published.
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		return $post;
	}

	private function get_excerpt( WP_Post $post ): string {
		return trim( wp_strip_all_tags( $post->post_excerpt ) );
	}

	private function minimal_fallback(): string {
		return '# ' . get_bloginfo( 'name' ) . "\n\n" .
		       '> ' . get_bloginfo( 'description' ) . "\n";
	}

	// -------------------------------------------------------------------------
	// HTTP output
	// -------------------------------------------------------------------------

	private function send( string $content ): void {
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Pragma: public' );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
