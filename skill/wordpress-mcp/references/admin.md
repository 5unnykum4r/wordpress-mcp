# Administration

## site/get-info

Get general site information and environment details.

**Input:** `{}` (no parameters)

**Output:**
```
name, description, url, admin_email, language, timezone,
wp_version, php_version, theme, is_multisite,
active_plugins: [{ name, version }],
writing_settings: { default_category, default_post_format }
```

**Use cases:**
- Verify site connectivity
- Check WordPress/PHP versions
- See active theme and plugins
- Get default category for new posts

---

## site/list-plugins

List all installed plugins with status and version info.

**Input:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `status` | string | all | active, inactive, all |

**Output:** `{ plugins: [{ name, slug, version, status, description, author, update_available }], total: int }`

**Examples:**
- All plugins: `{}`
- Active only: `{ status: "active" }`
- Inactive only: `{ status: "inactive" }`

---

## site/toggle-plugin

Activate or deactivate a plugin by its slug.

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `plugin` | string | yes | Plugin slug (e.g. `rank-math-seo`, `wordpress-seo`) |
| `action` | string | yes | activate or deactivate |

**Output:** `{ plugin, action, success: true }`

**Examples:**
- Activate: `{ plugin: "rank-math-seo", action: "activate" }`
- Deactivate: `{ plugin: "classic-editor", action: "deactivate" }`

**Warning:** Deactivating critical plugins (SEO, caching, security) can affect site functionality. Always confirm before toggling plugins on a live site.

---

## site/list-users

List WordPress users with role filtering.

**Input:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `role` | string | — | administrator, editor, author, contributor, subscriber |
| `number` | int | 20 | Users per page (max 50) |
| `search` | string | — | Search by name or email |

**Output:** `{ users: [{ ID, username, display_name, email, role, registered_date, post_count }], total: int }`

**Examples:**
- All users: `{}`
- Admins only: `{ role: "administrator" }`
- Search by name: `{ search: "john" }`

---

## site/manage-options

Read or update WordPress site options (settings).

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | string | yes | get or update |
| `option` | string | yes | Option name |
| `value` | mixed | for update | New value (string, number, or boolean) |

**Output (get):** `{ option, value }`

**Output (update):** `{ option, old_value, new_value, updated: true }`

**Common options:**
| Option | Description |
|--------|-------------|
| `blogname` | Site title |
| `blogdescription` | Site tagline |
| `posts_per_page` | Posts shown per page |
| `default_category` | Default category ID for new posts |
| `date_format` | Date display format |
| `time_format` | Time display format |
| `permalink_structure` | Permalink pattern (e.g. `/%postname%/`) |
| `blog_public` | Search engine visibility (1 = visible, 0 = discouraged) |

**Examples:**
- Read site title: `{ action: "get", option: "blogname" }`
- Update tagline: `{ action: "update", option: "blogdescription", value: "Your new tagline here" }`
- Check permalink structure: `{ action: "get", option: "permalink_structure" }`

---

## site/clear-cache

Flush site caches. Supports popular caching plugins and server-level cache.

**Input:** `{}` (no parameters)

**Output:** `{ cleared: [list of caches flushed], message: string }`

Attempts to clear:
- WordPress object cache (`wp_cache_flush`)
- WP Super Cache
- W3 Total Cache
- LiteSpeed Cache
- WP Rocket
- Autoptimize
- Transients

**When to use:**
- After bulk content updates
- After changing site settings or permalinks
- After plugin activation/deactivation
- When the site shows stale content

---

## Admin Workflows

### Health Check
```
1. Site info:       site/get-info {}
                    → Check WP version, PHP version, active theme

2. Plugin audit:    site/list-plugins {}
                    → Review active/inactive plugins, check for updates

3. User audit:      site/list-users { role: "administrator" }
                    → Verify admin accounts
```

### After Major Changes
```
1. Make changes:    (update posts, change settings, toggle plugins)

2. Clear cache:     site/clear-cache {}
                    → Flush all caches

3. Verify:          site/get-info {}
                    → Confirm site is still operational
```
