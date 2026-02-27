# Posts & Pages

## site/list-posts

List posts or pages with filtering, pagination, and sorting.

**Input:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `number` | int | 10 | Posts per page (max 50) |
| `page` | int | 1 | Page number |
| `status` | string | publish | publish, draft, pending, private, future, any |
| `post_type` | string | post | post, page, or custom post type |
| `search` | string | — | Search title and content |
| `category` | string | — | Category slug |
| `tag` | string | — | Tag slug |
| `author` | int | — | Author user ID |
| `orderby` | string | date | date, title, modified, ID, menu_order |
| `order` | string | DESC | ASC or DESC |

**Output:** `{ posts: [...], total: int, pages: int }`

Each post: `ID, title, slug, status, type, date, modified, link, excerpt, author`

**Examples:**
- List 20 drafts: `{ number: 20, status: "draft" }`
- Search published posts: `{ search: "AI tools", status: "publish" }`
- Posts in a category: `{ category: "technology", number: 50 }`
- Pages sorted by title: `{ post_type: "page", orderby: "title", order: "ASC" }`

---

## site/read-post

Read full content, metadata, and SEO of a single post or page.

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `post_id` | int | yes | The post or page ID |

**Output:**
```
ID, title, slug, content (full HTML), excerpt, status, post_type,
date, modified, link, author, author_id, parent_id, menu_order,
categories: [{ id, name, slug }],
tags: [{ id, name, slug }],
featured_image: { id, url },
custom_fields: { key: value, ... },
rank_math: { title, description, focus_keyword, canonical_url, robots,
             primary_category, pillar_content, breadcrumb_title, schema_type,
             og_title, og_description, og_image, twitter_title, twitter_description }
```

---

## site/create-post

Create a new post or page with all fields and SEO.

**Input:**
| Param | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `title` | string | yes | — | Post title |
| `content` | string | no | "" | HTML content |
| `excerpt` | string | no | "" | Excerpt |
| `slug` | string | no | auto | URL slug |
| `status` | string | no | draft | publish, draft, pending, private, future |
| `post_type` | string | no | post | post or page |
| `date` | string | no | now | Y-m-d H:i:s (required for future) |
| `parent_id` | int | no | — | Parent page ID |
| `menu_order` | int | no | — | Sort order for pages |
| `categories` | string[] | no | — | Category names (auto-created if missing) |
| `tags` | string[] | no | — | Tag names |
| `featured_image_id` | int | no | — | Attachment ID |
| `custom_fields` | object | no | — | Key-value pairs |
| `rank_math` | object | no | — | See Rank Math section below |

**Output:** `{ ID, slug, link, edit_link }`

**Example:**
```json
{
  "title": "Best AI Tools 2026",
  "content": "<p>Full article HTML here...</p>",
  "status": "draft",
  "slug": "best-ai-tools-2026",
  "categories": ["Technology", "AI"],
  "tags": ["ai", "tools", "2026"],
  "featured_image_id": 1234,
  "rank_math": {
    "title": "Best AI Tools 2026 - Complete Guide",
    "description": "Discover the top AI tools for productivity, coding, and content creation in 2026.",
    "focus_keyword": "best ai tools 2026",
    "schema_type": "article",
    "robots": ["index", "follow"]
  }
}
```

---

## site/update-post

Update any fields on an existing post. Only provided fields are changed.

**Input:** Same as create-post, but with `post_id` (required) instead of `title` being required. All other fields are optional.

**Special behaviors:**
- `categories` replaces all existing categories
- `tags` replaces all existing tags
- `featured_image_id: 0` removes the featured image
- `custom_fields: { "key": null }` deletes that custom field
- `rank_math` fields are merged (only provided keys are updated)

**Output:** `{ ID, slug, link, edit_link, updated: true }`

**Examples:**
- Publish a draft: `{ post_id: 123, status: "publish" }`
- Change slug: `{ post_id: 123, slug: "new-better-slug" }`
- Update SEO only: `{ post_id: 123, rank_math: { description: "New meta description" } }`

---

## site/delete-post

Move to trash or permanently delete.

**Input:**
| Param | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `post_id` | int | yes | — | Post ID |
| `permanent` | bool | no | false | true = permanent delete, false = trash |

**Output:** `{ deleted: true, ID, permanent }`

---

## site/bulk-update-status

Change status of multiple posts at once.

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `post_ids` | int[] | yes | Array of post IDs |
| `status` | string | yes | publish, draft, pending, private |

**Output:** `{ updated: [IDs], failed: [IDs], count: int }`

**Example:** `{ post_ids: [10, 11, 12, 13], status: "publish" }`

---

## site/list-revisions

List all saved revisions of a post.

**Input:**
| Param | Type | Required |
|-------|------|----------|
| `post_id` | int | yes |

**Output:** `{ revisions: [{ ID, date, author, title, excerpt }], total: int }`

---

## site/restore-revision

Restore a post to a previous revision.

**Input:**
| Param | Type | Required |
|-------|------|----------|
| `revision_id` | int | yes |

**Output:** `{ restored: true, post_id, revision_id }`

---

## site/search-replace

Find and replace text across post titles and/or content.

**Input:**
| Param | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `search` | string | yes | — | Text to find |
| `replace` | string | yes | — | Replacement text |
| `in` | string | no | content | content, title, or both |
| `post_type` | string | no | post | Post type to search |
| `status` | string | no | any | Post status filter |
| `dry_run` | bool | no | true | Preview only, no changes |

**Output (dry run):** `{ dry_run: true, matches: [{ ID, title }], count: int }`

**Output (execute):** `{ dry_run: false, matches: [...], posts_affected, title_updates, content_updates }`

**Always run with dry_run: true first to preview before executing.**

---

## Rank Math SEO Object

Used in `site/create-post` and `site/update-post`, returned by `site/read-post`.

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | SEO title (overrides post title in search results) |
| `description` | string | Meta description |
| `focus_keyword` | string | Focus keyword(s), comma-separated for multiple |
| `canonical_url` | string | Canonical URL override |
| `robots` | string[] | Directives: index, noindex, follow, nofollow, noarchive, noimageindex, nosnippet |
| `primary_category` | int | Primary category term ID |
| `pillar_content` | string | "on" or "off" |
| `breadcrumb_title` | string | Custom breadcrumb title |
| `schema_type` | string | Schema: article, news_article, faq, howto, product, review, etc. |
| `og_title` | string | Facebook/Open Graph title |
| `og_description` | string | Facebook/Open Graph description |
| `og_image` | string | Facebook/Open Graph image URL |
| `twitter_title` | string | Twitter card title |
| `twitter_description` | string | Twitter card description |
