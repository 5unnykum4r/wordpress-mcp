# Comments

## site/list-comments

List comments with filtering by post, status, and search.

**Input:**
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `post_id` | int | — | Filter to a specific post |
| `status` | string | all | approve, hold, spam, trash, all |
| `number` | int | 20 | Comments per page (max 50) |
| `page` | int | 1 | Page number |
| `search` | string | — | Search comment content |

**Output:** `{ comments: [...], total: int }`

Each comment: `ID, post_id, author, email, content, date, status, parent`

**Examples:**
- Pending moderation: `{ status: "hold" }`
- Comments on a post: `{ post_id: 123 }`
- Spam comments: `{ status: "spam" }`
- Search comments: `{ search: "great article" }`

---

## site/update-comment

Change comment status or reply to a comment.

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `comment_id` | int | yes | Comment ID |
| `status` | string | no | approve, hold, spam, trash |
| `reply` | string | no | Reply content (creates a new comment as a reply) |

**Output:** `{ comment_id, status?, reply_id? }`

**Examples:**
- Approve: `{ comment_id: 45, status: "approve" }`
- Mark as spam: `{ comment_id: 45, status: "spam" }`
- Reply and approve: `{ comment_id: 45, status: "approve", reply: "Thanks for your feedback!" }`

---

## site/delete-comment

Permanently delete a comment.

**Input:**
| Param | Type | Required |
|-------|------|----------|
| `comment_id` | int | yes |

**Output:** `{ deleted: true, comment_id }`

---

## Moderation Workflow

```
1. List pending:    site/list-comments { status: "hold" }
2. Review each:     Read the content and decide
3. Approve good:    site/update-comment { comment_id: X, status: "approve" }
4. Spam bad:        site/update-comment { comment_id: Y, status: "spam" }
5. Reply to some:   site/update-comment { comment_id: X, reply: "Thanks!" }
6. Delete junk:     site/delete-comment { comment_id: Z }
```
