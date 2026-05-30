<?php
/**
 * LH_AI_Legibility_Markdown_Server
 *
 * Intercepts singular page/post requests where the client has sent
 * Accept: text/markdown and responds with the post content converted
 * to Markdown instead of the normal HTML template.
 *
 * Respects:
 *  - Only runs on singular posts/pages (is_singular()).
 *  - Skips password-protected and private posts.
 *  - Honours the lh_ai_legibility_markdown_enabled filter so other
 *    plugins or post meta can opt specific posts out.
 *  - Adds a Vary: Accept header on normal HTML responses so caches
 *    know the representation varies by Accept.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LH_AI_Legibility_Markdown_Server {

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Content negotiation — fires after WP has determined the query
		// but before any template is loaded.
		add_action( 'template_redirect', [ $this, 'maybe_serve_markdown' ], 1 );

		// Add Vary header to HTML responses so CDNs/caches handle it correctly.
		add_action( 'send_headers', [ $this, 'add_vary_header' ] );
	}

	/**
	 * Check the Accept header and serve Markdown if requested.
	 */
	public function maybe_serve_markdown(): void {
		if ( ! $this->client_accepts_markdown() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		// Skip protected or private content.
		if ( post_password_required( $post ) ) {
			return;
		}

		if ( 'private' === $post->post_status ) {
			return;
		}

		/**
		 * Allow opt-out per post or post type.
		 *
		 * @param bool    $enabled Whether to serve Markdown for this post.
		 * @param WP_Post $post    The current post.
		 */
		$enabled = apply_filters( 'lh_ai_legibility_markdown_enabled', true, $post );

		if ( ! $enabled ) {
			return;
		}

		$this->serve_markdown( $post );
	}

	/**
	 * Render the post as Markdown and exit.
	 */
	private function serve_markdown( WP_Post $post ): void {
		// Run the content through the full WordPress filter stack so blocks,
		// shortcodes, and embeds are resolved before conversion.
		$html = apply_filters( 'the_content', $post->post_content );

		$markdown = LH_AI_Legibility_Converter::convert( $html );

		/**
		 * Filter the final Markdown output before it is sent.
		 *
		 * @param string  $markdown Converted Markdown.
		 * @param WP_Post $post     The current post.
		 */
		$markdown = apply_filters( 'lh_ai_legibility_markdown_output', $markdown, $post );

		// Prepend a YAML-style front matter block with key post metadata so
		// the AI has context without needing a separate request.
		$front_matter = $this->build_front_matter( $post );

		$output = $front_matter . $markdown;

		// Send headers and output.
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );

		// Allow the response to be cached for a short time by AI crawlers.
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Pragma: public' );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Build a YAML front matter block with post metadata.
	 *
	 * Provides title, URL, date, and modified date so an AI has document
	 * context from the first lines without parsing the content.
	 */
	private function build_front_matter( WP_Post $post ): string {
		$lines = [
			'---',
			'title: '    . $this->yaml_scalar( get_the_title( $post ) ),
			'url: '      . get_permalink( $post ),
			'date: '     . get_the_date( 'c', $post ),
			'modified: ' . get_the_modified_date( 'c', $post ),
			'type: '     . $post->post_type,
		];

		// Include excerpt if one exists.
		$excerpt = get_the_excerpt( $post );
		if ( $excerpt ) {
			$lines[] = 'excerpt: ' . $this->yaml_scalar( $excerpt );
		}

		// Include primary category/taxonomy term if present.
		$terms = get_the_terms( $post, 'category' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$lines[] = 'category: ' . $this->yaml_scalar( $terms[0]->name );
		}

		$lines[] = '---';
		$lines[] = '';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Wrap a string in YAML quotes if it contains special characters.
	 */
	private function yaml_scalar( string $value ): string {
		// Quote if contains colon, hash, quote, or leading/trailing whitespace.
		if ( preg_match( '/[:#"\']|^\s|\s$/', $value ) ) {
			// Escape only double-quotes and backslashes for YAML double-quoted scalars.
			// addslashes() also escapes single quotes which is incorrect for YAML.
			$escaped = str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value );
			return '"' . $escaped . '"';
		}
		return $value;
	}

	/**
	 * Add Vary: Accept to HTML responses so proxies/CDNs cache correctly.
	 */
	public function add_vary_header(): void {
		if ( is_singular() ) {
			header( 'Vary: Accept', false );
		}
	}

	/**
	 * Check whether the client's Accept header includes text/markdown.
	 *
	 * Handles quality factors (q=) and wildcard matching per RFC 7231.
	 * Returns true only if text/markdown has a non-zero q value and is
	 * preferred over text/html (or text/html is absent).
	 */
	private function client_accepts_markdown(): bool {
		$accept = $_SERVER['HTTP_ACCEPT'] ?? '';

		if ( empty( $accept ) ) {
			return false;
		}

		$types = $this->parse_accept_header( $accept );

		$markdown_q = $types['text/markdown'] ?? $types['text/*'] ?? $types['*/*'] ?? null;
		$html_q     = $types['text/html']     ?? $types['text/*'] ?? $types['*/*'] ?? null;

		if ( null === $markdown_q ) {
			return false;
		}

		// Explicit text/markdown with q=0 means "not acceptable".
		if ( isset( $types['text/markdown'] ) && $types['text/markdown'] <= 0 ) {
			return false;
		}

		// If both are present, only serve Markdown if it is preferred.
		if ( null !== $html_q && null !== $markdown_q ) {
			// text/markdown must be explicitly present and strictly preferred.
			if ( ! isset( $types['text/markdown'] ) ) {
				return false;
			}
			return $types['text/markdown'] > $html_q;
		}

		return true;
	}

	/**
	 * Parse an Accept header into [ media-type => q-value ] pairs.
	 *
	 * @param  string $header Raw Accept header value.
	 * @return array<string, float>
	 */
	private function parse_accept_header( string $header ): array {
		$types = [];

		foreach ( explode( ',', $header ) as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}

			$segments  = explode( ';', $part );
			$mime      = strtolower( trim( $segments[0] ) );
			$q         = 1.0;

			foreach ( array_slice( $segments, 1 ) as $param ) {
				$param = trim( $param );
				if ( stripos( $param, 'q=' ) === 0 ) {
					$q = (float) substr( $param, 2 );
				}
			}

			$types[ $mime ] = $q;
		}

		return $types;
	}
}
