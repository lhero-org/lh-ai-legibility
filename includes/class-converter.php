<?php
/**
 * LH_AI_Legibility_Converter
 *
 * Converts rendered HTML to Markdown. Wraps league/html-to-markdown if
 * available; falls back to a minimal built-in conversion sufficient for
 * standard WordPress content (paragraphs, headings, links, lists, code).
 *
 * Usage:
 *   $md = LH_AI_Legibility_Converter::convert( $html );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LH_AI_Legibility_Converter {

	/**
	 * Convert HTML string to Markdown.
	 *
	 * @param  string $html Rendered HTML.
	 * @return string       Markdown.
	 */
	public static function convert( string $html ): string {
		// Prefer league/html-to-markdown if loaded (e.g. via Composer in a
		// parent plugin or must-use plugin).
		if ( class_exists( '\League\HTMLToMarkdown\HtmlConverter' ) ) {
			return self::convert_via_league( $html );
		}

		return self::convert_builtin( $html );
	}

	/**
	 * Conversion via league/html-to-markdown.
	 */
	private static function convert_via_league( string $html ): string {
		$converter = new \League\HTMLToMarkdown\HtmlConverter( [
			'strip_tags'         => true,
			'use_autolinks'      => false,
			'hard_break'         => false,
			'header_style'       => 'atx',   // # H1 style
			'bold_style'         => '**',
			'italic_style'       => '_',
			'list_item_style'    => '-',
			'remove_nodes'       => 'script style nav footer header',
		] );

		return trim( $converter->convert( $html ) );
	}

	/**
	 * Minimal built-in conversion — handles standard WordPress block output.
	 * Not exhaustive, but covers: headings, paragraphs, bold, italic, links,
	 * unordered/ordered lists, blockquotes, inline code, pre/code blocks, hr.
	 */
	private static function convert_builtin( string $html ): string {
		// Strip scripts, styles, and nav/footer cruft first.
		$html = preg_replace( '#<(script|style|nav|footer|header)[^>]*>.*?</\1>#is', '', $html );

		// Decode HTML entities early so replacements work on clean text.
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Pre/code blocks — preserve before other replacements strip tags.
		$html = preg_replace_callback(
			'#<pre[^>]*><code[^>]*>(.*?)</code></pre>#is',
			function ( $m ) {
				$code = strip_tags( $m[1] );
				return "\n\n```\n" . trim( $code ) . "\n```\n\n";
			},
			$html
		);

		// Inline code.
		$html = preg_replace( '#<code[^>]*>(.*?)</code>#is', '`$1`', $html );

		// Headings.
		foreach ( range( 1, 6 ) as $n ) {
			$hashes = str_repeat( '#', $n );
			$html   = preg_replace( "#<h{$n}[^>]*>(.*?)</h{$n}>#is", "\n\n{$hashes} $1\n\n", $html );
		}

		// Bold / strong.
		$html = preg_replace( '#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', $html );

		// Italic / em.
		$html = preg_replace( '#<(em|i)[^>]*>(.*?)</\1>#is', '_$2_', $html );

		// Links — [text](href).
		$html = preg_replace_callback(
			'#<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#is',
			function ( $m ) {
				$text = trim( strip_tags( $m[2] ) );
				$href = trim( $m[1] );
				if ( $text === $href || $text === '' ) {
					return $href;
				}
				return "[{$text}]({$href})";
			},
			$html
		);

		// Images — ![alt](src). Capture src and alt regardless of attribute order.
		$html = preg_replace_callback(
			'#<img\s([^>]*)>#is',
			function ( $m ) {
				$attrs = $m[1];
				$src   = '';
				$alt   = '';
				if ( preg_match( '/src=["\']([^"\']*)["\']/', $attrs, $sm ) ) {
					$src = trim( $sm[1] );
				}
				if ( preg_match( '/alt=["\']([^"\']*)["\']/', $attrs, $am ) ) {
					$alt = trim( $am[1] );
				}
				if ( ! $src ) {
					return '';
				}
				return "![{$alt}]({$src})";
			},
			$html
		);

		// Blockquotes.
		$html = preg_replace_callback(
			'#<blockquote[^>]*>(.*?)</blockquote>#is',
			function ( $m ) {
				$inner = trim( strip_tags( $m[1] ) );
				$lines = explode( "\n", $inner );
				return "\n\n" . implode( "\n", array_map( fn( $l ) => '> ' . $l, $lines ) ) . "\n\n";
			},
			$html
		);

		// Unordered lists.
		$html = preg_replace_callback(
			'#<ul[^>]*>(.*?)</ul>#is',
			function ( $m ) {
				$items = [];
				preg_match_all( '#<li[^>]*>(.*?)</li>#is', $m[1], $li );
				foreach ( $li[1] as $item ) {
					$items[] = '- ' . trim( strip_tags( $item ) );
				}
				return "\n\n" . implode( "\n", $items ) . "\n\n";
			},
			$html
		);

		// Ordered lists.
		$html = preg_replace_callback(
			'#<ol[^>]*>(.*?)</ol>#is',
			function ( $m ) {
				$items = [];
				$n     = 1;
				preg_match_all( '#<li[^>]*>(.*?)</li>#is', $m[1], $li );
				foreach ( $li[1] as $item ) {
					$items[] = "{$n}. " . trim( strip_tags( $item ) );
					$n++;
				}
				return "\n\n" . implode( "\n", $items ) . "\n\n";
			},
			$html
		);

		// Horizontal rules.
		$html = preg_replace( '#<hr[^>]*/>#i', "\n\n---\n\n", $html );

		// Paragraphs and divs — become double newlines.
		$html = preg_replace( '#</?(p|div)[^>]*>#i', "\n\n", $html );

		// Line breaks.
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );

		// Strip remaining tags.
		$html = strip_tags( $html );

		// Normalise whitespace: collapse 3+ newlines to 2.
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );

		return trim( $html );
	}
}
