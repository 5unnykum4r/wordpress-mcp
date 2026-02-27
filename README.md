# WordPress MCP

A Model Context Protocol (MCP) server that gives AI agents full control over WordPress sites. Manage posts, pages, media, categories, tags, comments, SEO, redirects, Gutenberg blocks, reusable patterns, TablePress tables, plugins, users, and site settings — all through 46 MCP tools.

Built for **Claude Code**. Also compatible with the Claude Agent SDK and any MCP-capable client.

## How It Works

```
┌─────────────┐     JSON-RPC      ┌──────────────────┐     HTTP/Auth     ┌─────────────────┐
│  Claude /    │ ──────────────▶  │  Python MCP      │ ──────────────▶  │  WordPress Site  │
│  AI Agent    │                  │  Proxy Server    │                  │  (MCP Adapter)   │
│              │ ◀──────────────  │  (server.py)     │ ◀──────────────  │  (mu-plugin)     │
└─────────────┘     MCP Tools     └──────────────────┘     JSON-RPC     └─────────────────┘
                                         │
                                    sites.json
                                  (multi-site config)
```

A single Python proxy handles **unlimited WordPress sites**. Each tool call includes a `site` parameter to target a specific site. Authentication uses WordPress Application Passwords (built into WP 5.6+).

## Features

| Category | Tools | Capabilities |
|----------|-------|-------------|
| **Posts & Pages** | 9 tools | CRUD, bulk status change, revisions, search & replace |
| **Categories & Tags** | 8 tools | CRUD with Rank Math SEO support |
| **Media** | 4 tools | Upload from URL, browse library, update metadata, delete |
| **Comments** | 3 tools | List, moderate (approve/spam/trash), reply, delete |
| **Rank Math SEO** | — | Full SEO metadata on posts and categories (title, description, focus keyword, Open Graph, Twitter, schema, robots) |
| **Redirections** | 4 tools | Rank Math redirect CRUD (301/302/307/410/451) |
| **Gutenberg Blocks** | 1 tool | Discover all registered block types with attribute schemas |
| **Synced Patterns** | 5 tools | Create, read, update, delete reusable blocks |
| **TablePress** | 5 tools | Full table CRUD with display options and visibility |
| **Plugins** | 2 tools | List installed plugins, activate/deactivate |
| **Users** | 1 tool | List users with role filtering |
| **Settings** | 3 tools | Read/write options, flush caches, get site info |
| **Utility** | 1 tool | List all configured sites |

**Total: 46 tools** covering the full WordPress management surface.

## Quick Start

### 1. Install the WordPress adapter

```bash
# Build PHP dependencies locally
cd wordpress/
composer install

# Upload vendor/ and load-mcp-adapter.php to your WordPress server
# See SETUP.md for detailed instructions
```

### 2. Configure the proxy

```bash
# Copy the example config
cp sites.json.example sites.json

# Edit with your site details
```

```json
{
  "myblog": {
    "url": "https://yourdomain.com/wp-json/mcp/mcp-adapter-default-server",
    "username": "YourAdmin",
    "password": "xxxx xxxx xxxx xxxx xxxx xxxx"
  }
}
```

### 3. Connect to Claude

**Claude Code** — add to `~/.claude.json` (or ask your Claude Code agent to set it up for you):

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "stdio",
      "command": "uv",
      "args": [
        "run",
        "--with", "fastmcp",
        "--with", "httpx",
        "python",
        "/path/to/wordpress-mcp/server.py"
      ]
    }
  }
}
```

Then install the skill for tool documentation:

```bash
ln -s /path/to/wordpress-mcp/skill/wordpress-mcp ~/.claude/skills/wordpress-mcp
```

See the full [setup guide](SETUP.md) for detailed instructions, troubleshooting, and usage examples.

## Tool Reference

All tools require `site` as the first parameter.

<details>
<summary><strong>Posts & Pages</strong> (9 tools)</summary>

| Tool | Action |
|------|--------|
| `list_posts` | List/filter posts and pages |
| `read_post` | Read full content, metadata, SEO |
| `create_post` | Create with content, categories, tags, SEO |
| `update_post` | Partial update any fields |
| `delete_post` | Trash or permanently delete |
| `bulk_update_status` | Change status of multiple posts |
| `list_revisions` | View revision history |
| `restore_revision` | Revert to a previous revision |
| `search_replace` | Find/replace across content (dry run supported) |

</details>

<details>
<summary><strong>Categories & Tags</strong> (8 tools)</summary>

| Tool | Action |
|------|--------|
| `list_categories` | List with Rank Math SEO data |
| `create_category` | Create with optional SEO |
| `update_category` | Update name, slug, parent, SEO |
| `delete_category` | Delete (posts move to default) |
| `list_tags` | List and search tags |
| `create_tag` | Create a new tag |
| `update_tag` | Update tag name, slug, description |
| `delete_tag` | Remove a tag (posts are untagged) |

</details>

<details>
<summary><strong>Media</strong> (4 tools)</summary>

| Tool | Action |
|------|--------|
| `upload_image` | Download from URL and add to media library |
| `list_media` | Browse with mime type filtering |
| `update_media` | Update title, alt text, caption |
| `delete_media` | Permanently delete |

</details>

<details>
<summary><strong>Comments</strong> (3 tools)</summary>

| Tool | Action |
|------|--------|
| `list_comments` | Filter by post, status, search |
| `update_comment` | Approve, spam, trash, or reply |
| `delete_comment` | Permanently delete |

</details>

<details>
<summary><strong>Redirections</strong> (4 tools)</summary>

| Tool | Action |
|------|--------|
| `list_redirections` | List Rank Math redirects |
| `create_redirection` | Create 301/302/307/410/451 redirect |
| `update_redirection` | Modify source, destination, type |
| `delete_redirection` | Remove a redirect rule |

</details>

<details>
<summary><strong>Gutenberg & Patterns</strong> (6 tools)</summary>

| Tool | Action |
|------|--------|
| `list_block_types` | Discover registered blocks + schemas |
| `list_patterns` | List reusable/synced patterns |
| `read_pattern` | Read pattern block content |
| `create_pattern` | Create synced pattern |
| `update_pattern` | Update pattern content |
| `delete_pattern` | Delete a pattern |

</details>

<details>
<summary><strong>TablePress</strong> (5 tools)</summary>

| Tool | Action |
|------|--------|
| `list_tablepress_tables` | List all tables |
| `read_tablepress_table` | Read data, options, visibility |
| `create_tablepress_table` | Create with 2D data array |
| `update_tablepress_table` | Update data, options, visibility |
| `delete_tablepress_table` | Permanently delete |

</details>

<details>
<summary><strong>Administration</strong> (7 tools)</summary>

| Tool | Action |
|------|--------|
| `list_plugins` | List installed plugins + status |
| `toggle_plugin` | Activate or deactivate |
| `list_users` | List users by role |
| `manage_options` | Read/write WordPress settings |
| `clear_cache` | Flush all caches |
| `get_info` | Site info, versions, theme |
| `list_sites` | Show configured sites |

</details>

## Project Structure

```
wordpress-mcp/
├── README.md                  ← This file
├── SETUP.md                   ← Full setup guide + troubleshooting
├── LICENSE                    ← MIT license
├── server.py                  ← Python MCP proxy (FastMCP + httpx)
├── sites.json.example         ← Site configuration template
├── wordpress/
│   ├── load-mcp-adapter.php   ← WordPress MU-plugin (server-side)
│   ├── composer.json          ← PHP dependencies
│   └── composer.lock
└── skill/
    └── wordpress-mcp/         ← Claude Code skill
        ├── SKILL.md
        └── references/        ← Tool documentation (7 files)
```

## Requirements

**Local (proxy server):**
- Python 3.10+
- [uv](https://docs.astral.sh/uv/) package manager
- FastMCP and httpx (installed automatically by `uv run`)

**WordPress server:**
- WordPress 5.6+ (for Application Passwords)
- PHP 7.4+
- Composer packages: `wordpress/abilities-api` and `wordpress/mcp-adapter`

## Multi-Site Management

Add as many WordPress sites as you need to `sites.json`:

```json
{
  "blog": {
    "url": "https://blog.example.com/wp-json/mcp/mcp-adapter-default-server",
    "username": "admin",
    "password": "xxxx xxxx xxxx xxxx xxxx xxxx"
  },
  "shop": {
    "url": "https://shop.example.com/wp-json/mcp/mcp-adapter-default-server",
    "username": "admin",
    "password": "yyyy yyyy yyyy yyyy yyyy yyyy"
  },
  "docs": {
    "url": "https://docs.example.com/wp-json/mcp/mcp-adapter-default-server",
    "username": "editor",
    "password": "zzzz zzzz zzzz zzzz zzzz zzzz"
  }
}
```

Each site gets its own alias. Target any site with `site="blog"`, `site="shop"`, etc. No proxy restart needed when adding new sites.

## Contributing

Contributions are welcome. Please open an issue or pull request.

## License

[MIT](LICENSE)
