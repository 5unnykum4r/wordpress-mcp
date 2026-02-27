# Media Management

## site/upload-image

Download an image from a URL and add it to the WordPress media library.

**Input:**
| Param | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `image_url` | string | yes | — | URL of the image to download |
| `title` | string | no | filename | Attachment title |
| `alt_text` | string | no | "" | Image alt text for accessibility and SEO |
| `caption` | string | no | "" | Image caption |
| `description` | string | no | "" | Image description |
| `filename` | string | no | auto | Override the saved filename (e.g. "hero-banner.jpg") |

**Output:** `{ attachment_id, url, title, filename }`

**Examples:**
- Basic upload:
  ```json
  { "image_url": "https://example.com/photo.jpg" }
  ```
- With full metadata:
  ```json
  {
    "image_url": "https://example.com/dashboard.png",
    "title": "AI Dashboard Screenshot",
    "alt_text": "Screenshot of the AI tools dashboard showing analytics",
    "caption": "The new AI dashboard with real-time analytics",
    "filename": "ai-dashboard-2026.png"
  }
  ```

**Use the returned `attachment_id` with:**
- `site/create-post` → `featured_image_id`
- `site/update-post` → `featured_image_id`

---

## site/list-media

Browse the media library with filtering and pagination.

**Input:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `number` | int | 20 | Items per page (max 50) |
| `page` | int | 1 | Page number |
| `mime_type` | string | image | Filter: image, image/jpeg, image/png, image/webp, application/pdf, video, audio |
| `search` | string | — | Search by title |

**Output:** `{ media: [...], total: int, pages: int }`

Each item: `ID, title, url, alt_text, caption, date, filename, filesize`

**Examples:**
- All images: `{}`
- PDFs only: `{ mime_type: "application/pdf" }`
- Search for logos: `{ search: "logo", number: 10 }`
- Page 2 of all media: `{ page: 2 }`

---

## site/update-media

Update an existing media item's title, alt text, caption, or description. Only provided fields change.

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `attachment_id` | int | yes | Media attachment ID |
| `title` | string | no | New title |
| `alt_text` | string | no | New alt text for SEO |
| `caption` | string | no | New caption |
| `description` | string | no | New description |

**Output:** `{ attachment_id, title, alt_text, url, updated: true }`

**Examples:**
- Fix alt text: `{ "attachment_id": 567, "alt_text": "Dashboard showing real-time analytics" }`
- Update title and caption: `{ "attachment_id": 567, "title": "New Title", "caption": "Updated caption" }`

---

## site/delete-media

Permanently delete a media attachment and its files from the server.

**Input:**
| Param | Type | Required |
|-------|------|----------|
| `attachment_id` | int | yes |

**Output:** `{ deleted: true, attachment_id, title }`

---

## Workflow: Upload and Attach to Post

```
1. Upload:    site/upload-image { image_url: "...", alt_text: "..." }
              → returns { attachment_id: 567, url: "https://..." }

2. Create:    site/create-post { title: "...", featured_image_id: 567, ... }

3. Or embed in content:
              site/update-post {
                post_id: 123,
                content: "... <img src='https://yoursite.com/wp-content/uploads/2026/02/image.jpg' alt='...' /> ..."
              }
```
