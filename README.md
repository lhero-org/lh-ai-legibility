# LH AI Legibility

Makes LocalHero content legible to AI systems via two complementary mechanisms:

1. **Content negotiation** — any singular post or page served with `Accept: text/markdown` returns Markdown instead of HTML, with YAML front matter for document context.
2. **llms.txt generation** — a block-editor–authored document generates `/llms.txt` and `/llms-full.txt` endpoints per the [llms.txt specification](https://llmstxt.org/).

Part of the [LocalHero](https://lhero.org) open-source platform for community organisations.

---

## Features

### Markdown content negotiation

Any AI agent or crawler that sends `Accept: text/markdown` on a singular post or page request receives:

- A YAML front matter block with `title`, `url`, `date`, `modified`, `type`, and optionally `excerpt` and `category`.
- The post content converted to clean Markdown.
- A `Content-Type: text/markdown` response header.
- A `Vary: Accept` header on all HTML responses so CDN/proxy caches handle the two representations correctly.

Password-protected and private posts are excluded. Per-post opt-out is available via the `lh_ai_legibility_markdown_enabled` filter.

### llms.txt and llms-full.txt

Activate the plugin and a draft `LLMs.txt Document` post is created automatically. Publish it to make the following endpoints live:

| URL | Content |
|---|---|
| `/llms.txt` | Site name, excerpt (as blockquote), free-form paragraphs and lists, then `## Section` headings with linked items. |
| `/llms-full.txt` | Everything in `/llms.txt`, then the full Markdown content of each linked page appended inline. |

The document is edited using the block editor with a restricted inserter — only the following blocks are available:

- **LLMs.txt Section** (`llms-txt/section`) — a `## heading` plus a linked list, which maps directly to a section in the spec.
- **Paragraph** — free-form detail text.
- **List** — free-form bullet lists.

Only one `LLMs.txt Document` can be published at a time. Attempting to publish a second one saves it as a draft instead, with an admin notice.

### HTML-to-Markdown converter

The `LH_AI_Legibility_Converter` static class handles HTML→Markdown conversion throughout the plugin. It prefers [`league/html-to-markdown`](https://github.com/thephpleague/html-to-markdown) if it is already loaded (e.g. by a Composer-managed parent plugin), and falls back to a built-in converter that handles standard WordPress block output:

- Headings (h1–h6), paragraphs, divs, line breaks
- Bold, italic, inline code, fenced code blocks
- Links, images
- Unordered and ordered lists
- Blockquotes
- Horizontal rules

---

## Requirements

- WordPress 6.5+
- PHP 8.1+
- No required Composer dependencies (league/html-to-markdown is an optional enhancement)

---

## Installation

1. Upload the `lh-ai-legibility` folder to `/wp-content/plugins/`.
2. Activate via **Plugins › Installed Plugins**.
3. On activation, a draft `LLMs.txt Document` is created and a **Settings › LLMs.txt** menu link appears.
4. Edit the document, add sections and links, then publish it to bring `/llms.txt` live.
5. Visit `/llms.txt` in your browser to verify the output.

---

## Filters

| Filter | Arguments | Description |
|---|---|---|
| `lh_ai_legibility_markdown_enabled` | `bool $enabled`, `WP_Post $post` | Return `false` to disable Markdown serving for a specific post or post type. |
| `lh_ai_legibility_markdown_output` | `string $markdown`, `WP_Post $post` | Filter the final Markdown string before it is sent for content-negotiation responses. |
| `lh_ai_legibility_llms_txt_output` | `string $output` | Filter the final `/llms.txt` output string. |
| `lh_ai_legibility_llms_full_output` | `string $output` | Filter the final `/llms-full.txt` output string. |

---

## Document structure

The `LLMs.txt Document` is a standard block-editor post with constrained block usage:

```
Post excerpt                → blockquote in llms.txt
core/paragraph blocks       → free-form text between blockquote and sections
core/list blocks            → free-form lists in the detail zone
llms-txt/section blocks     → each ## section heading + link list
  └── core/list inner block → list items, format: [Label](url): Description
```

List item link format inside a section block:

```
- [Label](https://example.com): Optional description text
- [Label](https://example.com) — Also works with an em-dash separator
- [Label](https://example.com)
```

---

## llms-full.txt content fetching

For internal URLs, `llms-full.txt` fetches content directly from the database (no HTTP round-trip) and passes it through `the_content` filters. For external URLs it issues a `wp_remote_get` request with `Accept: text/markdown, text/html` — so external sites that implement content negotiation themselves will return clean Markdown directly.

---

## Caching

Both `/llms.txt` and Markdown responses send `Cache-Control: public, max-age=3600`. Rewrite rules are flushed automatically whenever the `LLMs.txt Document` is saved. If you use a full-page cache, exclude `/llms.txt` and `/llms-full.txt` from it, or ensure they are invalidated on post save.

---

## Plugin architecture

```
lh-ai-legibility/
├── lh-ai-legibility.php          # Bootstrap, autoloader, init hooks
├── includes/
│   ├── class-llms-txt.php        # CPT, rewrite rules, /llms.txt generation
│   ├── class-markdown-server.php # Accept: text/markdown content negotiation
│   ├── class-converter.php       # HTML → Markdown (league or built-in)
│   └── class-section-block.php   # Block registration + allowed-blocks filter
└── blocks/
    └── section/
        ├── block.json            # Block metadata
        ├── index.js              # Edit/save components (vanilla wp.element)
        └── editor.css            # Editor-only styles
```

Classes are autoloaded from `includes/` using the `LH_AI_Legibility_` prefix convention.

---

## Changelog

### 0.3 — 2026-05-31
- Add missing `index.php` silence files to plugin root, `includes/`, `blocks/`, and `blocks/section/`.

### 0.2 — 2026-05-31
- Fix missing `return` after fallback `send()` in `maybe_serve()` — prevents potential fatal if `send()` is ever refactored to not exit.
- Fix `nocache_headers()` conflicting with `Cache-Control: public` in both `class-llms-txt.php` and `class-markdown-server.php` — headers are now set directly.
- Fix `addslashes()` incorrectly escaping single quotes in YAML scalars — replaced with targeted `str_replace` for `\` and `"` only.
- Fix `ltrim()` em-dash byte-corruption in `parse_list_item()` — replaced with `preg_replace` with `u` flag for correct UTF-8 handling.
- Fix `img` alt attribute dropped when `alt=` appears before `src=` in converter — regex now captures both attributes independently.

### 0.1
- Initial release.
- Markdown content negotiation for singular posts and pages with YAML front matter.
- `llms_txt_document` CPT with singleton enforcement and admin menu integration.
- `/llms.txt` and `/llms-full.txt` rewrite endpoints.
- `llms-txt/section` block with restricted inserter inside the CPT editor.
- Built-in HTML→Markdown converter with optional league/html-to-markdown delegation.

---

## License

GPL-2.0+. See [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

Peter Shaw — [shawfactor.com](https://shawfactor.com)
