# Rank Math Redirections

Requires the Rank Math SEO plugin with redirections enabled.

## site/list-redirections

List all configured redirects.

**Input:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `number` | int | 50 | Max redirections (max 100) |
| `search` | string | — | Search source or destination URLs |

**Output:** `{ redirections: [{ id, from, to, type, active }], total: int }`

**Examples:**
- All redirections: `{}`
- Search for old URLs: `{ search: "/old-page" }`

---

## site/create-redirection

Create a new redirect rule.

**Input:**
| Param | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `from` | string | yes | — | Source URL path (e.g. `/old-page` or `/blog/old-slug`) |
| `to` | string | yes | — | Destination URL (full URL or relative path) |
| `type` | int | no | 301 | Redirect type: 301 (permanent), 302 (temporary), 307 (temp strict), 410 (gone), 451 (unavailable) |

**Output:** `{ id, from, to, type }`

**Examples:**
- Permanent redirect:
  ```json
  { "from": "/old-article", "to": "https://yoursite.com/new-article", "type": 301 }
  ```
- Temporary redirect:
  ```json
  { "from": "/sale", "to": "/summer-sale-2026", "type": 302 }
  ```
- Gone (deleted page):
  ```json
  { "from": "/discontinued-product", "to": "", "type": 410 }
  ```

---

## site/update-redirection

Update an existing redirect. Only provided fields change.

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `redirection_id` | int | yes | Redirection ID to update |
| `from` | string | no | New source URL path |
| `to` | string | no | New destination URL |
| `type` | int | no | New redirect type (301, 302, 307, 410, 451) |
| `status` | string | no | "active" or "inactive" |

**Output:** `{ id, updated: true }`

**Examples:**
- Change destination: `{ "redirection_id": 5, "to": "https://yoursite.com/new-destination" }`
- Disable a redirect: `{ "redirection_id": 5, "status": "inactive" }`
- Change type from 302 to 301: `{ "redirection_id": 5, "type": 301 }`

---

## site/delete-redirection

Remove a redirect by its ID.

**Input:**
| Param | Type | Required |
|-------|------|----------|
| `redirection_id` | int | yes |

**Output:** `{ deleted: true, id }`

---

## Common Redirect Types

| Code | Name | When to use |
|------|------|-------------|
| 301 | Permanent | Slug changed, page moved permanently, passes SEO |
| 302 | Temporary | Temporary move, A/B testing, seasonal content |
| 307 | Temporary Strict | Like 302 but preserves HTTP method |
| 410 | Gone | Page intentionally deleted, tells search engines to deindex |
| 451 | Unavailable | Legal reasons |

---

## Workflow: Change Slug with Redirect

```
1. Read current:      site/read-post { post_id: 123 }
                      → slug: "old-article-name"

2. Update slug:       site/update-post { post_id: 123, slug: "better-slug" }

3. Create redirect:   site/create-redirection {
                        from: "/old-article-name",
                        to: "https://yoursite.com/better-slug",
                        type: 301
                      }
```
