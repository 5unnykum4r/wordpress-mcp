# WordPress MCP Setup Guide

How to connect a WordPress site to Claude via the MCP adapter. Works on any WordPress hosting — shared hosting (cPanel), VPS, managed WordPress, or local development.

---

## Prerequisites

- WordPress site with admin access
- File manager or SSH/SFTP access to the server
- PHP 7.4+ on the server
- Local machine with PHP and Composer (for building vendor packages)
- Python 3.10+ and `uv` installed locally (for the MCP proxy server)

---

## Step 1: Build Vendor Packages Locally

The MCP adapter and Abilities API are Composer packages. Build them locally, then upload to the server.

```bash
cd wordpress/
composer install
zip -r mcp-vendor.zip vendor/
```

This creates a `vendor/` directory with all dependencies and zips it for upload.

---

## Step 2: Upload Vendor to Server

1. Open your hosting **File Manager** (or use SFTP/SSH)
2. Navigate to your WordPress root directory (the folder containing `wp-config.php`, `wp-content/`, etc.)
   - On shared hosting this is often `/home/username/yourdomain.com/` or `/home/username/public_html/`
   - Check your actual path in the file manager breadcrumb
3. Upload `mcp-vendor.zip`
4. Extract the zip file
5. Verify `vendor/autoload.php` exists at the WordPress root level

---

## Step 3: Upload the MU-Plugin

The file `wordpress/load-mcp-adapter.php` is the must-use plugin that loads the adapter and registers all WordPress abilities as MCP tools. It uses `ABSPATH` to automatically find the `vendor/` directory at the WordPress root — no path editing needed.

1. Navigate to `wp-content/` on your server
2. If the `mu-plugins/` folder doesn't exist, create it
3. Navigate into `mu-plugins/`
4. Upload `load-mcp-adapter.php`

### Verify

- Go to WordPress Admin → **Plugins → Must-Use** tab
- The MCP adapter should appear in the list
- Visit `https://yourdomain.com/wp-json/mcp/mcp-adapter-default-server`
- Expected response: `401 Unauthorized` (means the endpoint is registered and requires auth)
- If you get `404`: flush permalinks at **Settings → Permalinks → Save Changes**

---

## Step 4: Create Application Password

1. Go to WordPress Admin → **Users → Profile** (your admin account)
2. Scroll to **Application Passwords**
3. Enter a name (e.g. `Claude MCP`)
4. Click **Add New Application Password**
5. Copy the generated password immediately (shown once, with spaces — keep the spaces)

---

## Step 5: Add Site to sites.json

Copy `sites.json.example` to `sites.json` and add your site:

```json
{
  "myblog": {
    "url": "https://yourdomain.com/wp-json/mcp/mcp-adapter-default-server",
    "username": "YourAdminUsername",
    "password": "xxxx xxxx xxxx xxxx xxxx xxxx"
  }
}
```

Replace:
- `myblog` — a short alias you'll use in tool calls
- `yourdomain.com` — your actual domain
- `YourAdminUsername` — the WordPress username that created the application password
- `xxxx xxxx...` — the application password from Step 4 (keep the spaces)

No restart needed — the proxy reads `sites.json` on each call.

---

## Step 6: Configure Claude Code

The MCP proxy doesn't run standalone — Claude Code starts it automatically via stdio.

**Quick way** — just ask Claude Code:

```
Add a wordpress MCP server to my config. The server command is:
uv run --with fastmcp --with httpx python /absolute/path/to/wordpress-mcp/server.py
```

**Manual way** — edit `~/.claude.json`:

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
        "/absolute/path/to/wordpress-mcp/server.py"
      ]
    }
  }
}
```

Replace the path with the actual absolute path to `server.py`.

---

## Step 7: Install the Skill (Optional but Recommended)

The skill gives Claude Code tool documentation and workflow guidance so it knows how to use the MCP tools effectively.

**Option A: Symlink (recommended — auto-updates)**

```bash
ln -s /absolute/path/to/wordpress-mcp/skill/wordpress-mcp ~/.claude/skills/wordpress-mcp
```

**Option B: Copy**

```bash
cp -r /absolute/path/to/wordpress-mcp/skill/wordpress-mcp ~/.claude/skills/wordpress-mcp
```

**Option C: Project-level skill**

```bash
mkdir -p .claude/skills
cp -r /path/to/wordpress-mcp/skill/wordpress-mcp .claude/skills/
```

---

## Step 8: Test the Connection

Restart Claude Code (or start a new session), then:

```
Use wordpress list_sites to see configured sites
```

Then test a specific site:

```
Use wordpress get_info for site myblog
```

If it returns site name, WP version, and theme info — everything is working.

---

## Usage Examples

### Create a post
```
Create a new draft post on myblog titled "Getting Started with WordPress MCP"
with a short intro paragraph, and set the focus keyword to "wordpress mcp"
```

### Bulk operations
```
List all draft posts on myblog and publish them
```

### SEO audit
```
Read post 123 on myblog and check if Rank Math SEO fields are properly set
```

### Content migration
```
Search and replace "old-domain.com" with "new-domain.com" across all posts on myblog (dry run first)
```

---

## Troubleshooting

### 404 on the MCP endpoint

- Flush permalinks: **Settings → Permalinks → Save Changes**
- Verify `mu-plugins/load-mcp-adapter.php` exists and loads (check **Plugins → Must-Use** tab)
- Verify `vendor/autoload.php` exists at the WordPress root (same folder as `wp-config.php`)

### "The Composer autoloader was not found"

The adapter's internal autoloader checks `WP_MCP_DIR . '/vendor/autoload.php'` which resolves incorrectly when vendor is at the WordPress root. The mu-plugin handles this by defining `WP_MCP_AUTOLOAD` as `false` before loading the adapter, then loading the real autoloader from the correct path.

If you still see this error:
- Confirm `define('WP_MCP_AUTOLOAD', false)` is in the mu-plugin (before the adapter loads)
- Confirm `vendor/` is at the WordPress root (the mu-plugin resolves it via `ABSPATH . 'vendor'`)

### 401 Unauthorized

This is actually correct when hitting the endpoint directly — it means the endpoint exists and requires authentication. The proxy handles auth automatically via credentials in `sites.json`.

If the proxy gets 401, verify:
- The username is correct (case-sensitive)
- The application password is correct (include the spaces)
- The user has Administrator role

### Empty abilities / tools

The MCP adapter only exposes abilities with `'mcp' => array('public' => true)` in their meta. The `load-mcp-adapter.php` mu-plugin registers all WordPress abilities as MCP tools with this flag. If you see empty abilities:
- Verify the mu-plugin is loading (check **Plugins → Must-Use** tab)
- Deactivate any old copies of the MCP adapter in the regular **Plugins** tab

### Old plugin copy in Plugins tab

If you previously uploaded the vendor zip through WordPress Plugin Installer (wrong place), an inactive "MCP Adapter" may show in the regular Plugins list. Deactivate and delete it — the mu-plugin is the correct one.

### Site not found error

If you get `Site 'xyz' not found`, the alias doesn't match any key in `sites.json`. Run `list_sites` to see available aliases.

---

## Adding Another WordPress Site

1. Upload `mcp-vendor.zip` to the new site's WordPress root and extract
2. Upload `load-mcp-adapter.php` to `wp-content/mu-plugins/` (same file — no editing needed, it uses `ABSPATH` automatically)
3. Create an Application Password on the new site
4. Add the site to `sites.json`
5. Flush permalinks on the new site

That's it — no proxy restart needed.
