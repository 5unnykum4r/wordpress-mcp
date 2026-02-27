"""
WordPress Multi-Site MCP Proxy Server

A single FastMCP server that proxies tool calls to any number of WordPress
sites configured in sites.json. Each tool takes a `site` parameter to
identify the target WordPress installation.

Usage:
    uv run --with fastmcp --with httpx python server.py
"""

import json
import base64
from pathlib import Path
from typing import Annotated

import httpx
from fastmcp import FastMCP
from pydantic import Field

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

SITES_FILE = Path(__file__).parent / "sites.json"
MCP_PROTOCOL_VERSION = "2025-06-18"
REQUEST_TIMEOUT = 60.0
SITE_DESC = "Site alias from sites.json (e.g. 'myblog')"

# ---------------------------------------------------------------------------
# Site configuration loader
# ---------------------------------------------------------------------------


def load_sites() -> dict[str, dict]:
    """Load site configurations from sites.json."""
    if not SITES_FILE.exists():
        return {}
    with open(SITES_FILE) as f:
        return json.load(f)


def get_site_config(site: str) -> dict:
    """Get config for a site alias. Raises ValueError if not found."""
    sites = load_sites()
    if site not in sites:
        available = ", ".join(sorted(sites.keys())) or "(none)"
        raise ValueError(f"Site '{site}' not found. Available: {available}")
    return sites[site]


# ---------------------------------------------------------------------------
# HTTP transport with JSON-RPC 2.0 and session management
# ---------------------------------------------------------------------------

_sessions: dict[str, str] = {}
_http_client: httpx.Client | None = None


def _client() -> httpx.Client:
    global _http_client
    if _http_client is None:
        _http_client = httpx.Client(timeout=REQUEST_TIMEOUT)
    return _http_client


def _auth_header(username: str, password: str) -> str:
    encoded = base64.b64encode(f"{username}:{password}".encode()).decode()
    return f"Basic {encoded}"


def _init_session(site: str) -> str:
    """Initialize MCP session with a WordPress site, return session ID."""
    if site in _sessions:
        return _sessions[site]

    config = get_site_config(site)
    headers = {
        "Content-Type": "application/json",
        "Authorization": _auth_header(config["username"], config["password"]),
    }
    payload = {
        "jsonrpc": "2.0",
        "id": 1,
        "method": "initialize",
        "params": {
            "protocolVersion": MCP_PROTOCOL_VERSION,
            "capabilities": {},
            "clientInfo": {"name": "wordpress-mcp-proxy", "version": "1.0.0"},
        },
    }

    resp = _client().post(config["url"], json=payload, headers=headers)
    resp.raise_for_status()

    session_id = resp.headers.get("Mcp-Session-Id", "")
    if not session_id:
        body = resp.json()
        session_id = body.get("sessionId", f"fallback-{site}")

    _sessions[site] = session_id
    return session_id


def call_wordpress(site: str, ability: str, params: dict) -> dict:
    """Execute a WordPress ability via JSON-RPC over HTTP."""
    config = get_site_config(site)
    session_id = _init_session(site)

    headers = {
        "Content-Type": "application/json",
        "Authorization": _auth_header(config["username"], config["password"]),
        "Mcp-Session-Id": session_id,
    }

    clean_params = {k: v for k, v in params.items() if v is not None}

    payload = {
        "jsonrpc": "2.0",
        "id": 2,
        "method": "tools/call",
        "params": {
            "name": "mcp-adapter-execute-ability",
            "arguments": {
                "ability_name": ability,
                "parameters": clean_params,
            },
        },
    }

    resp = _client().post(config["url"], json=payload, headers=headers)

    # Retry once if session expired
    if resp.status_code in (400, 401):
        _sessions.pop(site, None)
        session_id = _init_session(site)
        headers["Mcp-Session-Id"] = session_id
        resp = _client().post(config["url"], json=payload, headers=headers)

    resp.raise_for_status()
    body = resp.json()

    if "error" in body:
        msg = body["error"].get("message", "Unknown error")
        raise ValueError(f"WordPress error: {msg}")

    result = body.get("result", {})

    # Unwrap MCP content blocks: {"content": [{"type": "text", "text": "..."}]}
    if isinstance(result, dict) and "content" in result:
        blocks = result["content"]
        if isinstance(blocks, list) and blocks:
            text = blocks[0].get("text", "")
            try:
                parsed = json.loads(text)
                # Unwrap {"success": true, "data": {...}} wrapper
                if isinstance(parsed, dict) and "data" in parsed:
                    return parsed["data"]
                return parsed
            except (json.JSONDecodeError, TypeError):
                return {"result": text}

    return result


# ---------------------------------------------------------------------------
# FastMCP server
# ---------------------------------------------------------------------------

mcp = FastMCP(
    name="wordpress",
    instructions=(
        "Multi-site WordPress management proxy. Every tool requires a 'site' "
        "parameter identifying the target site. Use list_sites to see available sites."
    ),
)

# ---------------------------------------------------------------------------
# Utility
# ---------------------------------------------------------------------------


@mcp.tool
def list_sites() -> dict:
    """List all configured WordPress sites with their URLs."""
    sites = load_sites()
    return {
        alias: {"url": cfg["url"], "username": cfg["username"]}
        for alias, cfg in sites.items()
    }


# ---------------------------------------------------------------------------
# Posts & Pages
# ---------------------------------------------------------------------------


@mcp.tool
def list_posts(
    site: Annotated[str, Field(description=SITE_DESC)],
    number: Annotated[int, Field(description="Posts per page, max 50")] = 10,
    page: Annotated[int, Field(description="Page number")] = 1,
    status: Annotated[str, Field(description="publish, draft, pending, private, future, any")] = "publish",
    post_type: Annotated[str, Field(description="post, page, or custom type")] = "post",
    search: Annotated[str | None, Field(description="Search title and content")] = None,
    category: Annotated[str | None, Field(description="Category slug")] = None,
    tag: Annotated[str | None, Field(description="Tag slug")] = None,
    author: Annotated[int | None, Field(description="Author user ID")] = None,
    orderby: Annotated[str, Field(description="date, title, modified, ID, menu_order")] = "date",
    order: Annotated[str, Field(description="ASC or DESC")] = "DESC",
) -> dict:
    """List posts or pages with filtering, pagination, and sorting."""
    return call_wordpress(site, "site/list-posts", {
        "number": number, "page": page, "status": status, "post_type": post_type,
        "search": search, "category": category, "tag": tag, "author": author,
        "orderby": orderby, "order": order,
    })


@mcp.tool
def read_post(
    site: Annotated[str, Field(description=SITE_DESC)],
    post_id: Annotated[int, Field(description="Post or page ID")],
) -> dict:
    """Read full content, metadata, categories, tags, featured image, and Rank Math SEO."""
    return call_wordpress(site, "site/read-post", {"post_id": post_id})


@mcp.tool
def create_post(
    site: Annotated[str, Field(description=SITE_DESC)],
    title: Annotated[str, Field(description="Post title")],
    content: Annotated[str, Field(description="HTML content")] = "",
    excerpt: Annotated[str, Field(description="Post excerpt")] = "",
    slug: Annotated[str | None, Field(description="URL slug, auto-generated if omitted")] = None,
    status: Annotated[str, Field(description="publish, draft, pending, private, future")] = "draft",
    post_type: Annotated[str, Field(description="post or page")] = "post",
    date: Annotated[str | None, Field(description="Y-m-d H:i:s, required for future")] = None,
    parent_id: Annotated[int | None, Field(description="Parent page ID")] = None,
    menu_order: Annotated[int | None, Field(description="Sort order for pages")] = None,
    categories: Annotated[list[str] | None, Field(description="Category names, auto-created if missing")] = None,
    tags: Annotated[list[str] | None, Field(description="Tag names")] = None,
    featured_image_id: Annotated[int | None, Field(description="Attachment ID from upload_image")] = None,
    custom_fields: Annotated[dict | None, Field(description="Key-value custom meta")] = None,
    rank_math: Annotated[dict | None, Field(description="SEO: title, description, focus_keyword, canonical_url, robots, primary_category, pillar_content, breadcrumb_title, schema_type, og_title, og_description, og_image, twitter_title, twitter_description")] = None,
) -> dict:
    """Create a new post or page with content, SEO, categories, tags, and featured image."""
    return call_wordpress(site, "site/create-post", {
        "title": title, "content": content, "excerpt": excerpt, "slug": slug,
        "status": status, "post_type": post_type, "date": date,
        "parent_id": parent_id, "menu_order": menu_order,
        "categories": categories, "tags": tags,
        "featured_image_id": featured_image_id,
        "custom_fields": custom_fields, "rank_math": rank_math,
    })


@mcp.tool
def update_post(
    site: Annotated[str, Field(description=SITE_DESC)],
    post_id: Annotated[int, Field(description="Post ID to update")],
    title: Annotated[str | None, Field(description="New title")] = None,
    content: Annotated[str | None, Field(description="New HTML content")] = None,
    excerpt: Annotated[str | None, Field(description="New excerpt")] = None,
    slug: Annotated[str | None, Field(description="New URL slug")] = None,
    status: Annotated[str | None, Field(description="publish, draft, pending, private, future")] = None,
    date: Annotated[str | None, Field(description="Y-m-d H:i:s")] = None,
    parent_id: Annotated[int | None, Field(description="Parent page ID")] = None,
    menu_order: Annotated[int | None, Field(description="Sort order")] = None,
    categories: Annotated[list[str] | None, Field(description="Category names, replaces all")] = None,
    tags: Annotated[list[str] | None, Field(description="Tag names, replaces all")] = None,
    featured_image_id: Annotated[int | None, Field(description="Attachment ID, 0 removes image")] = None,
    custom_fields: Annotated[dict | None, Field(description="Key-value pairs, null value deletes")] = None,
    rank_math: Annotated[dict | None, Field(description="Rank Math SEO fields to update")] = None,
) -> dict:
    """Update any fields on an existing post/page. Only provided fields change."""
    return call_wordpress(site, "site/update-post", {
        "post_id": post_id, "title": title, "content": content, "excerpt": excerpt,
        "slug": slug, "status": status, "date": date,
        "parent_id": parent_id, "menu_order": menu_order,
        "categories": categories, "tags": tags,
        "featured_image_id": featured_image_id,
        "custom_fields": custom_fields, "rank_math": rank_math,
    })


@mcp.tool
def delete_post(
    site: Annotated[str, Field(description=SITE_DESC)],
    post_id: Annotated[int, Field(description="Post ID")],
    permanent: Annotated[bool, Field(description="True = permanent delete, False = trash")] = False,
) -> dict:
    """Trash or permanently delete a post/page."""
    return call_wordpress(site, "site/delete-post", {"post_id": post_id, "permanent": permanent})


# ---------------------------------------------------------------------------
# Bulk & Revisions
# ---------------------------------------------------------------------------


@mcp.tool
def bulk_update_status(
    site: Annotated[str, Field(description=SITE_DESC)],
    post_ids: Annotated[list[int], Field(description="Array of post IDs")],
    status: Annotated[str, Field(description="publish, draft, pending, private")],
) -> dict:
    """Change the status of multiple posts at once."""
    return call_wordpress(site, "site/bulk-update-status", {"post_ids": post_ids, "status": status})


@mcp.tool
def list_revisions(
    site: Annotated[str, Field(description=SITE_DESC)],
    post_id: Annotated[int, Field(description="Post ID")],
) -> dict:
    """List all saved revisions of a post."""
    return call_wordpress(site, "site/list-revisions", {"post_id": post_id})


@mcp.tool
def restore_revision(
    site: Annotated[str, Field(description=SITE_DESC)],
    revision_id: Annotated[int, Field(description="Revision ID to restore")],
) -> dict:
    """Restore a post to a previous revision."""
    return call_wordpress(site, "site/restore-revision", {"revision_id": revision_id})


@mcp.tool
def search_replace(
    site: Annotated[str, Field(description=SITE_DESC)],
    search: Annotated[str, Field(description="Text to find")],
    replace: Annotated[str, Field(description="Replacement text")],
    in_field: Annotated[str, Field(description="content, title, or both")] = "content",
    post_type: Annotated[str, Field(description="Post type to search")] = "post",
    status: Annotated[str, Field(description="Post status filter")] = "any",
    dry_run: Annotated[bool, Field(description="True = preview only, False = execute")] = True,
) -> dict:
    """Find and replace text across post titles and/or content. Use dry_run=True first."""
    return call_wordpress(site, "site/search-replace", {
        "search": search, "replace": replace, "in": in_field,
        "post_type": post_type, "status": status, "dry_run": dry_run,
    })


# ---------------------------------------------------------------------------
# Categories
# ---------------------------------------------------------------------------


@mcp.tool
def list_categories(
    site: Annotated[str, Field(description=SITE_DESC)],
    hide_empty: Annotated[bool, Field(description="Hide categories with 0 posts")] = False,
    parent: Annotated[int | None, Field(description="Parent ID, 0 for top-level only")] = None,
    search: Annotated[str | None, Field(description="Search by name")] = None,
    orderby: Annotated[str, Field(description="name, id, count, slug")] = "name",
) -> dict:
    """List all categories with ID, name, slug, parent, post count, and Rank Math SEO."""
    return call_wordpress(site, "site/list-categories", {
        "hide_empty": hide_empty, "parent": parent, "search": search, "orderby": orderby,
    })


@mcp.tool
def create_category(
    site: Annotated[str, Field(description=SITE_DESC)],
    name: Annotated[str, Field(description="Category name")],
    slug: Annotated[str | None, Field(description="URL slug")] = None,
    description: Annotated[str, Field(description="Category description")] = "",
    parent_id: Annotated[int | None, Field(description="Parent category ID, 0 for top-level")] = None,
    rank_math: Annotated[dict | None, Field(description="SEO: title, description, focus_keyword, canonical_url, robots, breadcrumb_title, og_title, og_description, og_image, twitter_title, twitter_description")] = None,
) -> dict:
    """Create a new category with optional Rank Math SEO."""
    return call_wordpress(site, "site/create-category", {
        "name": name, "slug": slug, "description": description, "parent_id": parent_id,
        "rank_math": rank_math,
    })


@mcp.tool
def update_category(
    site: Annotated[str, Field(description=SITE_DESC)],
    category_id: Annotated[int, Field(description="Category term ID")],
    name: Annotated[str | None, Field(description="New name")] = None,
    slug: Annotated[str | None, Field(description="New slug")] = None,
    description: Annotated[str | None, Field(description="New description")] = None,
    parent_id: Annotated[int | None, Field(description="New parent ID")] = None,
    rank_math: Annotated[dict | None, Field(description="Rank Math SEO fields to update")] = None,
) -> dict:
    """Update a category's name, slug, description, parent, or Rank Math SEO."""
    return call_wordpress(site, "site/update-category", {
        "category_id": category_id, "name": name, "slug": slug,
        "description": description, "parent_id": parent_id,
        "rank_math": rank_math,
    })


@mcp.tool
def delete_category(
    site: Annotated[str, Field(description=SITE_DESC)],
    category_id: Annotated[int, Field(description="Category ID")],
) -> dict:
    """Delete a category. Posts move to the default category."""
    return call_wordpress(site, "site/delete-category", {"category_id": category_id})


# ---------------------------------------------------------------------------
# Tags
# ---------------------------------------------------------------------------


@mcp.tool
def list_tags(
    site: Annotated[str, Field(description=SITE_DESC)],
    hide_empty: Annotated[bool, Field(description="Hide tags with 0 posts")] = False,
    search: Annotated[str | None, Field(description="Search by name")] = None,
    number: Annotated[int, Field(description="Max tags, max 200")] = 100,
    orderby: Annotated[str, Field(description="name, id, count, slug")] = "name",
) -> dict:
    """List all tags with ID, name, slug, and post count."""
    return call_wordpress(site, "site/list-tags", {
        "hide_empty": hide_empty, "search": search, "number": number, "orderby": orderby,
    })


@mcp.tool
def create_tag(
    site: Annotated[str, Field(description=SITE_DESC)],
    name: Annotated[str, Field(description="Tag name")],
    slug: Annotated[str | None, Field(description="URL slug")] = None,
    description: Annotated[str, Field(description="Tag description")] = "",
) -> dict:
    """Create a new tag."""
    return call_wordpress(site, "site/create-tag", {
        "name": name, "slug": slug, "description": description,
    })


@mcp.tool
def update_tag(
    site: Annotated[str, Field(description=SITE_DESC)],
    tag_id: Annotated[int, Field(description="Tag term ID")],
    name: Annotated[str | None, Field(description="New name")] = None,
    slug: Annotated[str | None, Field(description="New slug")] = None,
    description: Annotated[str | None, Field(description="New description")] = None,
) -> dict:
    """Update a tag."""
    return call_wordpress(site, "site/update-tag", {
        "tag_id": tag_id, "name": name, "slug": slug, "description": description,
    })


@mcp.tool
def delete_tag(
    site: Annotated[str, Field(description=SITE_DESC)],
    tag_id: Annotated[int, Field(description="Tag ID")],
) -> dict:
    """Delete a tag. Posts are untagged but not deleted."""
    return call_wordpress(site, "site/delete-tag", {"tag_id": tag_id})


# ---------------------------------------------------------------------------
# Media
# ---------------------------------------------------------------------------


@mcp.tool
def upload_image(
    site: Annotated[str, Field(description=SITE_DESC)],
    image_url: Annotated[str, Field(description="URL of image to download and upload")],
    title: Annotated[str | None, Field(description="Attachment title")] = None,
    alt_text: Annotated[str | None, Field(description="Image alt text for SEO")] = None,
    caption: Annotated[str | None, Field(description="Image caption")] = None,
    description: Annotated[str | None, Field(description="Image description")] = None,
    filename: Annotated[str | None, Field(description="Override saved filename")] = None,
) -> dict:
    """Download an image from a URL and add it to the WordPress media library."""
    return call_wordpress(site, "site/upload-image", {
        "image_url": image_url, "title": title, "alt_text": alt_text,
        "caption": caption, "description": description, "filename": filename,
    })


@mcp.tool
def list_media(
    site: Annotated[str, Field(description=SITE_DESC)],
    number: Annotated[int, Field(description="Items per page, max 50")] = 20,
    page: Annotated[int, Field(description="Page number")] = 1,
    mime_type: Annotated[str, Field(description="image, image/jpeg, application/pdf, video, audio")] = "image",
    search: Annotated[str | None, Field(description="Search by title")] = None,
) -> dict:
    """Browse the media library with filtering and pagination."""
    return call_wordpress(site, "site/list-media", {
        "number": number, "page": page, "mime_type": mime_type, "search": search,
    })


@mcp.tool
def update_media(
    site: Annotated[str, Field(description=SITE_DESC)],
    attachment_id: Annotated[int, Field(description="Media attachment ID")],
    title: Annotated[str | None, Field(description="New title")] = None,
    alt_text: Annotated[str | None, Field(description="New alt text for SEO")] = None,
    caption: Annotated[str | None, Field(description="New caption")] = None,
    description: Annotated[str | None, Field(description="New description")] = None,
) -> dict:
    """Update an existing media item's title, alt text, caption, or description."""
    return call_wordpress(site, "site/update-media", {
        "attachment_id": attachment_id, "title": title, "alt_text": alt_text,
        "caption": caption, "description": description,
    })


@mcp.tool
def delete_media(
    site: Annotated[str, Field(description=SITE_DESC)],
    attachment_id: Annotated[int, Field(description="Media attachment ID")],
) -> dict:
    """Permanently delete a media attachment and its files."""
    return call_wordpress(site, "site/delete-media", {"attachment_id": attachment_id})


# ---------------------------------------------------------------------------
# Comments
# ---------------------------------------------------------------------------


@mcp.tool
def list_comments(
    site: Annotated[str, Field(description=SITE_DESC)],
    post_id: Annotated[int | None, Field(description="Filter to a specific post")] = None,
    status: Annotated[str, Field(description="approve, hold, spam, trash, all")] = "all",
    number: Annotated[int, Field(description="Comments per page, max 50")] = 20,
    page: Annotated[int, Field(description="Page number")] = 1,
    search: Annotated[str | None, Field(description="Search comment content")] = None,
) -> dict:
    """List comments with filtering by post, status, and search."""
    return call_wordpress(site, "site/list-comments", {
        "post_id": post_id, "status": status, "number": number, "page": page, "search": search,
    })


@mcp.tool
def update_comment(
    site: Annotated[str, Field(description=SITE_DESC)],
    comment_id: Annotated[int, Field(description="Comment ID")],
    status: Annotated[str | None, Field(description="approve, hold, spam, trash")] = None,
    reply: Annotated[str | None, Field(description="Reply content, creates new comment as reply")] = None,
) -> dict:
    """Approve, spam, or trash a comment. Optionally reply."""
    return call_wordpress(site, "site/update-comment", {
        "comment_id": comment_id, "status": status, "reply": reply,
    })


@mcp.tool
def delete_comment(
    site: Annotated[str, Field(description=SITE_DESC)],
    comment_id: Annotated[int, Field(description="Comment ID")],
) -> dict:
    """Permanently delete a comment."""
    return call_wordpress(site, "site/delete-comment", {"comment_id": comment_id})


# ---------------------------------------------------------------------------
# Rank Math Redirections
# ---------------------------------------------------------------------------


@mcp.tool
def list_redirections(
    site: Annotated[str, Field(description=SITE_DESC)],
    number: Annotated[int, Field(description="Max redirections, max 100")] = 50,
    search: Annotated[str | None, Field(description="Search source or destination URLs")] = None,
) -> dict:
    """List Rank Math redirect rules."""
    return call_wordpress(site, "site/list-redirections", {"number": number, "search": search})


@mcp.tool
def create_redirection(
    site: Annotated[str, Field(description=SITE_DESC)],
    from_url: Annotated[str, Field(description="Source URL path, e.g. /old-page")],
    to: Annotated[str, Field(description="Destination URL or path")],
    type: Annotated[int, Field(description="301 permanent, 302 temporary, 307, 410 gone, 451")] = 301,
) -> dict:
    """Create a Rank Math redirect rule."""
    return call_wordpress(site, "site/create-redirection", {
        "from": from_url, "to": to, "type": type,
    })


@mcp.tool
def delete_redirection(
    site: Annotated[str, Field(description=SITE_DESC)],
    redirection_id: Annotated[int, Field(description="Redirection ID")],
) -> dict:
    """Delete a Rank Math redirection."""
    return call_wordpress(site, "site/delete-redirection", {"redirection_id": redirection_id})


@mcp.tool
def update_redirection(
    site: Annotated[str, Field(description=SITE_DESC)],
    redirection_id: Annotated[int, Field(description="Redirection ID to update")],
    from_url: Annotated[str | None, Field(description="New source URL path")] = None,
    to: Annotated[str | None, Field(description="New destination URL")] = None,
    type: Annotated[int | None, Field(description="New redirect type: 301, 302, 307, 410, 451")] = None,
    status: Annotated[str | None, Field(description="active or inactive")] = None,
) -> dict:
    """Update an existing Rank Math redirection's source, destination, type, or status."""
    return call_wordpress(site, "site/update-redirection", {
        "redirection_id": redirection_id, "from": from_url, "to": to,
        "type": type, "status": status,
    })


# ---------------------------------------------------------------------------
# Administration
# ---------------------------------------------------------------------------


@mcp.tool
def list_plugins(
    site: Annotated[str, Field(description=SITE_DESC)],
) -> dict:
    """List all installed plugins with name, version, and active status."""
    return call_wordpress(site, "site/list-plugins", {})


@mcp.tool
def toggle_plugin(
    site: Annotated[str, Field(description=SITE_DESC)],
    plugin: Annotated[str, Field(description="Plugin file path, e.g. akismet/akismet.php")],
    action: Annotated[str, Field(description="activate or deactivate")],
) -> dict:
    """Activate or deactivate a WordPress plugin."""
    return call_wordpress(site, "site/toggle-plugin", {"plugin": plugin, "action": action})


@mcp.tool
def list_users(
    site: Annotated[str, Field(description=SITE_DESC)],
    role: Annotated[str | None, Field(description="administrator, editor, author, contributor, subscriber")] = None,
    number: Annotated[int, Field(description="Users per page, max 100")] = 50,
    search: Annotated[str | None, Field(description="Search by name or email")] = None,
) -> dict:
    """List WordPress users with ID, name, email, role, and post count."""
    return call_wordpress(site, "site/list-users", {"role": role, "number": number, "search": search})


@mcp.tool
def manage_options(
    site: Annotated[str, Field(description=SITE_DESC)],
    read: Annotated[list[str] | None, Field(description="Option names to read")] = None,
    write: Annotated[dict | None, Field(description="Key-value pairs to update")] = None,
) -> dict:
    """Read or update WordPress options. Common: blogname, blogdescription, posts_per_page, permalink_structure."""
    return call_wordpress(site, "site/manage-options", {"read": read, "write": write})


@mcp.tool
def clear_cache(
    site: Annotated[str, Field(description=SITE_DESC)],
) -> dict:
    """Flush all caches (LiteSpeed, WP Super Cache, W3TC, WP Fastest Cache, object cache)."""
    return call_wordpress(site, "site/clear-cache", {})


@mcp.tool
def get_info(
    site: Annotated[str, Field(description=SITE_DESC)],
) -> dict:
    """Get site name, URL, WP version, PHP version, theme, language, timezone, and post/comment counts."""
    return call_wordpress(site, "site/get-info", {})


# ---------------------------------------------------------------------------
# Gutenberg Blocks
# ---------------------------------------------------------------------------


@mcp.tool
def list_block_types(
    site: Annotated[str, Field(description=SITE_DESC)],
    namespace: Annotated[str | None, Field(description="Filter by namespace prefix (e.g. 'core', 'theme-name', 'acf')")] = None,
    custom_only: Annotated[bool, Field(description="Only return non-core blocks (excludes core/ namespace)")] = False,
) -> dict:
    """List all registered Gutenberg block types with their attributes schema, category, and supported features."""
    return call_wordpress(site, "site/list-block-types", {
        "namespace": namespace, "custom_only": custom_only,
    })


# ---------------------------------------------------------------------------
# Reusable Blocks (Synced Patterns)
# ---------------------------------------------------------------------------


@mcp.tool
def list_patterns(
    site: Annotated[str, Field(description=SITE_DESC)],
    search: Annotated[str | None, Field(description="Search by title")] = None,
    number: Annotated[int, Field(description="Max patterns to return")] = 50,
) -> dict:
    """List all reusable blocks (synced patterns) with ID, title, content, and dates."""
    return call_wordpress(site, "site/list-patterns", {"search": search, "number": number})


@mcp.tool
def read_pattern(
    site: Annotated[str, Field(description=SITE_DESC)],
    pattern_id: Annotated[int, Field(description="Synced pattern ID")],
) -> dict:
    """Read a reusable block (synced pattern) with full block content."""
    return call_wordpress(site, "site/read-pattern", {"pattern_id": pattern_id})


@mcp.tool
def create_pattern(
    site: Annotated[str, Field(description=SITE_DESC)],
    title: Annotated[str, Field(description="Pattern name")],
    content: Annotated[str, Field(description="Block markup content")],
    status: Annotated[str, Field(description="publish or draft")] = "publish",
) -> dict:
    """Create a new reusable block (synced pattern). Returns the ref block markup for embedding."""
    return call_wordpress(site, "site/create-pattern", {
        "title": title, "content": content, "status": status,
    })


@mcp.tool
def update_pattern(
    site: Annotated[str, Field(description=SITE_DESC)],
    pattern_id: Annotated[int, Field(description="Pattern ID to update")],
    title: Annotated[str | None, Field(description="New title")] = None,
    content: Annotated[str | None, Field(description="New block markup content")] = None,
    status: Annotated[str | None, Field(description="publish or draft")] = None,
) -> dict:
    """Update a reusable block (synced pattern) title, content, or status."""
    return call_wordpress(site, "site/update-pattern", {
        "pattern_id": pattern_id, "title": title, "content": content, "status": status,
    })


@mcp.tool
def delete_pattern(
    site: Annotated[str, Field(description=SITE_DESC)],
    pattern_id: Annotated[int, Field(description="Pattern ID to delete")],
) -> dict:
    """Permanently delete a reusable block (synced pattern)."""
    return call_wordpress(site, "site/delete-pattern", {"pattern_id": pattern_id})


# ---------------------------------------------------------------------------
# TablePress
# ---------------------------------------------------------------------------


@mcp.tool
def list_tablepress_tables(
    site: Annotated[str, Field(description=SITE_DESC)],
) -> dict:
    """List all TablePress tables with ID, name, description, row/column counts, and last modified date."""
    return call_wordpress(site, "site/list-tablepress-tables", {})


@mcp.tool
def read_tablepress_table(
    site: Annotated[str, Field(description=SITE_DESC)],
    table_id: Annotated[str, Field(description="TablePress table ID")],
) -> dict:
    """Read a TablePress table with full data (2D array), display options, visibility, and metadata."""
    return call_wordpress(site, "site/read-tablepress-table", {"table_id": table_id})


@mcp.tool
def create_tablepress_table(
    site: Annotated[str, Field(description=SITE_DESC)],
    name: Annotated[str, Field(description="Table name/title")],
    data: Annotated[list[list[str]], Field(description='2D array of rows. First row is typically headers, e.g. [["Name","Price"],["Widget","$10"]]')],
    description: Annotated[str, Field(description="Table description")] = "",
    options: Annotated[dict | None, Field(description="Display options: table_head, table_foot, alternating_row_colors, row_hover, print_name, print_description, extra_css_classes")] = None,
) -> dict:
    """Create a new TablePress table. Returns the table ID and shortcode for embedding."""
    return call_wordpress(site, "site/create-tablepress-table", {
        "name": name, "description": description, "data": data, "options": options,
    })


@mcp.tool
def update_tablepress_table(
    site: Annotated[str, Field(description=SITE_DESC)],
    table_id: Annotated[str, Field(description="TablePress table ID to update")],
    name: Annotated[str | None, Field(description="New table name")] = None,
    description: Annotated[str | None, Field(description="New table description")] = None,
    data: Annotated[list[list[str]] | None, Field(description="New 2D data array, replaces all existing rows")] = None,
    options: Annotated[dict | None, Field(description="Display options to merge: table_head, table_foot, alternating_row_colors, row_hover, print_name, print_description, extra_css_classes")] = None,
    visibility: Annotated[dict | None, Field(description="Row/column visibility: {rows: [1,0,...], columns: [1,1,...]}, 1=visible, 0=hidden")] = None,
) -> dict:
    """Update a TablePress table. Only provided fields change. Data replaces all existing rows."""
    return call_wordpress(site, "site/update-tablepress-table", {
        "table_id": table_id, "name": name, "description": description,
        "data": data, "options": options, "visibility": visibility,
    })


@mcp.tool
def delete_tablepress_table(
    site: Annotated[str, Field(description=SITE_DESC)],
    table_id: Annotated[str, Field(description="TablePress table ID to delete")],
) -> dict:
    """Permanently delete a TablePress table."""
    return call_wordpress(site, "site/delete-tablepress-table", {"table_id": table_id})


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    mcp.run()
