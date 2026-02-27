# Categories & Tags

## Categories

### site/list-categories

List all categories with metadata and Rank Math SEO.

**Input:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `hide_empty` | bool | false | Hide categories with 0 posts |
| `parent` | int | — | Filter by parent ID. 0 = top-level only |
| `search` | string | — | Search by name |
| `orderby` | string | name | name, id, count, slug |

**Output:** `{ categories: [{ id, name, slug, description, parent_id, count, rank_math }], total: int }`

Each category's `rank_math` object contains: `title, description, focus_keyword, canonical_url, robots, breadcrumb_title, og_title, og_description, og_image, twitter_title, twitter_description`

**Examples:**
- All categories: `{}`
- Top-level only: `{ parent: 0 }`
- Non-empty, sorted by count: `{ hide_empty: true, orderby: "count" }`

---

### site/create-category

**Input:**
| Param | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `name` | string | yes | — | Category name |
| `slug` | string | no | auto | URL slug |
| `description` | string | no | "" | Category description |
| `parent_id` | int | no | 0 | Parent category ID (0 = top-level) |
| `rank_math` | object | no | — | Rank Math SEO settings (see below) |

**Output:** `{ id, name, slug, link }`

**Examples:**
- Simple: `{ name: "Machine Learning" }`
- With SEO:
  ```json
  {
    "name": "AI Tools",
    "slug": "ai-tools",
    "description": "Reviews and guides for AI-powered tools",
    "rank_math": {
      "title": "Best AI Tools - Reviews & Guides",
      "description": "Discover top AI tools for productivity, coding, and content creation.",
      "focus_keyword": "ai tools"
    }
  }
  ```

---

### site/update-category

Update any field. Only provided fields change.

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `category_id` | int | yes | Category term ID |
| `name` | string | no | New name |
| `slug` | string | no | New slug |
| `description` | string | no | New description |
| `parent_id` | int | no | New parent (0 for top-level) |
| `rank_math` | object | no | Rank Math SEO fields to update |

**Output:** `{ id, name, slug, updated: true }`

**Examples:**
- Rename: `{ category_id: 5, name: "Artificial Intelligence" }`
- Update SEO: `{ category_id: 5, rank_math: { description: "New meta description for this category" } }`

---

### site/delete-category

Delete a category. Posts in it move to the default category.

**Input:**
| Param | Type | Required |
|-------|------|----------|
| `category_id` | int | yes |

Cannot delete the default category.

**Output:** `{ deleted: true, id, name }`

---

## Category Rank Math SEO Fields

Available on `create_category` and `update_category`, returned by `list_categories`:

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | SEO title (shown in search results) |
| `description` | string | Meta description |
| `focus_keyword` | string | Focus keyword(s), comma-separated |
| `canonical_url` | string | Canonical URL override |
| `robots` | string[] | index, noindex, follow, nofollow, noarchive |
| `breadcrumb_title` | string | Custom breadcrumb title |
| `og_title` | string | Facebook/Open Graph title |
| `og_description` | string | Facebook/Open Graph description |
| `og_image` | string | Facebook/Open Graph image URL |
| `twitter_title` | string | Twitter card title |
| `twitter_description` | string | Twitter card description |

---

## Tags

### site/list-tags

List all tags with metadata.

**Input:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `hide_empty` | bool | false | Hide tags with 0 posts |
| `search` | string | — | Search by name |
| `number` | int | 100 | Max tags to return (max 200) |
| `orderby` | string | name | name, id, count, slug |

**Output:** `{ tags: [{ id, name, slug, description, count }], total: int }`

---

### site/create-tag

**Input:**
| Param | Type | Required | Default |
|-------|------|----------|---------|
| `name` | string | yes | — |
| `slug` | string | no | auto |
| `description` | string | no | "" |

**Output:** `{ id, name, slug }`

---

### site/update-tag

**Input:**
| Param | Type | Required |
|-------|------|----------|
| `tag_id` | int | yes |
| `name` | string | no |
| `slug` | string | no |
| `description` | string | no |

**Output:** `{ id, name, slug, updated: true }`

---

### site/delete-tag

Delete a tag. Posts are untagged but not deleted.

**Input:**
| Param | Type | Required |
|-------|------|----------|
| `tag_id` | int | yes |

**Output:** `{ deleted: true, id, name }`
