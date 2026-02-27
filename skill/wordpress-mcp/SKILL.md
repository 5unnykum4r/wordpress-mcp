---
name: wordpress-mcp
description: Manage WordPress sites via MCP. Use when the user asks to create, read, update, or delete posts, pages, categories, tags, media, comments, redirections, TablePress tables, synced patterns (reusable blocks), or site settings on a WordPress site connected through the MCP adapter. Covers Rank Math SEO, Gutenberg block discovery, reusable patterns, TablePress, plugin management, cache clearing, user listing, and bulk operations.
---

# WordPress MCP Site Management

Manage WordPress sites through a single Python MCP proxy server (`wordpress` in `~/.claude.json`). All tools require a `site` parameter identifying the target site. Sites are configured in `sites.json`.

## How It Works

A Python FastMCP server proxies tool calls to any WordPress site:
1. Every tool takes `site` as the first parameter (e.g. `site="myblog"`)
2. The proxy looks up credentials in `sites.json`
3. Sends JSON-RPC requests to the WordPress MCP adapter endpoint
4. Authentication uses WordPress Application Passwords via HTTP Basic Auth

Use `list_sites` to see all configured sites.

## Available Tools

See `references/posts.md`, `references/categories-tags.md`, `references/media.md`, `references/comments.md`, `references/redirections.md`, `references/admin.md`, and `references/tablepress.md` for full parameter details.

### Quick Reference

All tools require `site` as the first parameter.

| Tool | Action | Key Params |
|------|--------|------------|
| `list_posts` | List posts/pages | status, category, tag, search, post_type, page |
| `read_post` | Read full post + SEO | post_id |
| `create_post` | Create post/page | title, content, slug, status, categories, tags, rank_math |
| `update_post` | Update post/page | post_id + any fields to change |
| `delete_post` | Trash/delete | post_id, permanent |
| `bulk_update_status` | Bulk status change | post_ids[], status |
| `list_revisions` | Revision history | post_id |
| `restore_revision` | Restore revision | revision_id |
| `search_replace` | Find/replace in posts | search, replace, dry_run |
| `list_categories` | List categories + SEO | hide_empty, parent, search |
| `create_category` | Create category | name, slug, description, parent_id, rank_math |
| `update_category` | Edit category | category_id + fields, rank_math |
| `delete_category` | Delete category | category_id |
| `list_tags` | List tags | hide_empty, search |
| `create_tag` | Create tag | name, slug, description |
| `update_tag` | Edit tag | tag_id + fields |
| `delete_tag` | Delete tag | tag_id |
| `upload_image` | Upload from URL | image_url, title, alt_text, filename |
| `list_media` | Browse media library | mime_type, search, page |
| `update_media` | Edit media metadata | attachment_id, title, alt_text, caption |
| `delete_media` | Delete media item | attachment_id |
| `list_comments` | List comments | post_id, status, search |
| `update_comment` | Approve/spam/reply | comment_id, status, reply |
| `delete_comment` | Delete comment | comment_id |
| `list_redirections` | List Rank Math redirects | search |
| `create_redirection` | Create redirect | from_url, to, type (301/302) |
| `update_redirection` | Edit redirect | redirection_id + fields |
| `delete_redirection` | Delete redirect | redirection_id |
| `list_plugins` | List installed plugins | — |
| `toggle_plugin` | Activate/deactivate | plugin, action |
| `list_users` | List users | role, search |
| `manage_options` | Read/write WP settings | read[], write{} |
| `clear_cache` | Flush all caches | — |
| `get_info` | Site info + stats | — |
| `list_block_types` | List Gutenberg blocks | namespace, custom_only |
| `list_patterns` | List synced patterns | search |
| `read_pattern` | Read pattern content | pattern_id |
| `create_pattern` | Create synced pattern | title, content |
| `update_pattern` | Update pattern | pattern_id + fields |
| `delete_pattern` | Delete pattern | pattern_id |
| `list_tablepress_tables` | List TablePress tables | — |
| `read_tablepress_table` | Read table data + options | table_id |
| `create_tablepress_table` | Create new table | name, data[][], options |
| `update_tablepress_table` | Update table | table_id + any fields |
| `delete_tablepress_table` | Delete table | table_id |
| `list_sites` | Show configured sites | — |

## Rank Math SEO

Posts and categories support a `rank_math` object on create/update.

**Post fields:**
```
title, description, focus_keyword, canonical_url, robots[],
primary_category, pillar_content, breadcrumb_title, schema_type,
og_title, og_description, og_image, twitter_title, twitter_description
```

**Category fields:**
```
title, description, focus_keyword, canonical_url, robots[],
breadcrumb_title, og_title, og_description, og_image,
twitter_title, twitter_description
```

`read_post` and `list_categories` return all Rank Math fields.

## Gutenberg Blocks

Post content is stored as Gutenberg block markup. `read_post` returns the raw block HTML including `<!-- wp:block-name -->` delimiters. `create_post` and `update_post` accept full block markup in the `content` field — block comments are preserved as-is.

Use `list_block_types` to discover all registered blocks (core + custom). Pass `custom_only=True` to see only theme/plugin blocks, or `namespace="your-theme"` to filter by namespace. Each block entry includes its attribute schema so you can construct valid block markup.

Block markup format:
```
<!-- wp:namespace/block-name {"attribute":"value"} -->
<div class="wp-block-namespace-block-name">Inner HTML</div>
<!-- /wp:namespace/block-name -->
```

When updating an existing post's content, always `read_post` first to get the full block markup, modify the specific blocks, then pass the entire content back.

## Common Workflows

### Create an optimized post
1. `upload_image(site="myblog", image_url="...")` — upload featured image
2. `create_post(site="myblog", title="...", content="...", categories=["Tech"], rank_math={...})` — create with SEO
3. `read_post(site="myblog", post_id=123)` — verify
4. `update_post(site="myblog", post_id=123, status="publish")` — publish

### Bulk SEO audit
1. `list_posts(site="myblog", number=50)` — get all published posts
2. `read_post(site="myblog", post_id=X)` — check rank_math fields
3. `update_post(site="myblog", post_id=X, rank_math={...})` — fix missing SEO

### Manage redirects after slug change
1. `read_post(site="myblog", post_id=123)` — get current slug
2. `update_post(site="myblog", post_id=123, slug="new-slug")` — change slug
3. `create_redirection(site="myblog", from_url="/old-slug", to="/new-slug", type=301)` — redirect

### Search and replace
1. `search_replace(site="myblog", search="old text", replace="new text", dry_run=True)` — preview
2. `search_replace(site="myblog", search="old text", replace="new text", dry_run=False)` — execute

### Create a reusable synced pattern
1. `create_pattern(site="myblog", title="CTA Banner", content="<!-- wp:group -->...")` — returns ref block markup
2. Use the returned `ref_block` (e.g. `<!-- wp:block {"ref":456} /-->`) in any post content
3. Updating the pattern auto-updates every post that references it

### Create a post with custom Gutenberg blocks
1. `list_block_types(site="myblog", custom_only=True)` — discover custom blocks and their attributes
2. Build block markup: `<!-- wp:namespace/block-name {"attr":"value"} -->\n<div>...</div>\n<!-- /wp:namespace/block-name -->`
3. `create_post(site="myblog", title="...", content="...block markup...", rank_math={...})` — create post
4. `read_post(site="myblog", post_id=123)` — verify block content preserved

### Edit blocks in an existing post
1. `read_post(site="myblog", post_id=123)` — get current block markup from `content`
2. Modify the specific `<!-- wp:... -->` blocks in the content string
3. `update_post(site="myblog", post_id=123, content="...updated full content...")` — save back

### Create and embed a TablePress table
1. `create_tablepress_table(site="myblog", name="Pricing", data=[["Plan","Price"],["Basic","$9"],["Pro","$29"]])` — returns id + shortcode
2. `update_post(site="myblog", post_id=123, content="... [table id=5 /] ...")` — embed in post
3. `read_tablepress_table(site="myblog", table_id="5")` — verify

## Managing Sites

Sites are configured in `sites.json`:

```json
{
  "myblog": {
    "url": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
    "username": "AdminUsername",
    "password": "xxxx xxxx xxxx xxxx xxxx xxxx"
  }
}
```

To add a new site: install the MCP adapter on the WordPress server (see `SETUP.md`), create an Application Password, and add an entry to `sites.json`. No restart needed.

## Site-Specific Extensions

For sites with custom workflows (e.g. ACF custom fields, specific content templates, SEO recovery plans), create additional skill files alongside this one and reference them from your project's CLAUDE.md or `.claude/skills/` directory.

Example structure:
```
~/.claude/skills/
├── wordpress-mcp/         ← This skill (generic WordPress tools)
│   ├── SKILL.md
│   └── references/
└── my-site-workflow/      ← Your custom skill (site-specific context)
    └── SKILL.md
```
