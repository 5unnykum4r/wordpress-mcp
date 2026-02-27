# TablePress Tables

Requires the TablePress plugin to be active. Returns an error if TablePress is not installed or deactivated.

Table IDs are strings (can be numeric like "1" or alphanumeric). Table data is always a 2D array of strings: each element is a row, each row is an array of cell values. The first row is typically used as the table header.

Embed tables in posts using the shortcode: `[table id=X /]`

---

### site/list-tablepress-tables

List all TablePress tables with summary metadata.

**Input:** No parameters required.

**Output:** `{ tables: [{ id, name, description, rows, columns, last_modified, author }], total: int }`

---

### site/read-tablepress-table

Read a table's full data, display options, and visibility settings.

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `table_id` | string | yes | TablePress table ID |

**Output:**
```
{
  id, name, description, author, last_modified,
  data: [["Header1","Header2"],["val1","val2"]],
  rows: int, columns: int,
  options: { table_head, table_foot, alternating_row_colors, row_hover, print_name, print_description, extra_css_classes },
  visibility: { rows: [1,1,...], columns: [1,1,...] }
}
```

---

### site/create-tablepress-table

Create a new TablePress table with data and display options.

**Input:**
| Param | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `name` | string | yes | — | Table name/title |
| `description` | string | no | "" | Table description |
| `data` | string[][] | yes | — | 2D array of rows. First row is typically headers |
| `options` | object | no | defaults | Display options (see below) |

**Output:** `{ id, name, rows, columns, shortcode }`

**Examples:**
- Simple:
  ```json
  {
    "name": "Pricing",
    "data": [
      ["Plan", "Monthly", "Annual"],
      ["Basic", "$9", "$90"],
      ["Pro", "$29", "$290"]
    ]
  }
  ```
- With options:
  ```json
  {
    "name": "Feature Comparison",
    "description": "Free vs Pro features",
    "data": [
      ["Feature", "Free", "Pro"],
      ["Storage", "5 GB", "100 GB"],
      ["Support", "Email", "24/7 Priority"],
      ["API Access", "No", "Yes"]
    ],
    "options": {
      "table_head": true,
      "alternating_row_colors": true,
      "row_hover": true
    }
  }
  ```

Use the returned `shortcode` (e.g. `[table id=5 /]`) to embed the table in any post or page content.

---

### site/update-tablepress-table

Update any fields on an existing table. Only provided fields change.

**Input:**
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `table_id` | string | yes | TablePress table ID |
| `name` | string | no | New name |
| `description` | string | no | New description |
| `data` | string[][] | no | New data (replaces all existing rows) |
| `options` | object | no | Display options to merge (only provided keys change) |
| `visibility` | object | no | Row/column visibility arrays |

When `data` is replaced, visibility resets to all-visible. If `visibility` is also provided, it takes precedence.

**Output:** `{ id, name, rows, columns, updated: true }`

**Examples:**
- Rename: `{ "table_id": "1", "name": "Updated Table" }`
- Replace data: `{ "table_id": "1", "data": [["A","B"],["1","2"]] }`
- Change display: `{ "table_id": "1", "options": { "row_hover": false } }`
- Hide column 2: `{ "table_id": "1", "visibility": { "columns": [1, 0, 1] } }`

---

### site/delete-tablepress-table

Permanently delete a TablePress table.

**Input:**
| Param | Type | Required |
|-------|------|----------|
| `table_id` | string | yes |

**Output:** `{ deleted: true, id, name }`

---

## Display Options

Available on `create-tablepress-table` and `update-tablepress-table`:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `table_head` | bool | true | First row as `<thead>` header |
| `table_foot` | bool | false | Last row as `<tfoot>` footer |
| `alternating_row_colors` | bool | true | Zebra-stripe row backgrounds |
| `row_hover` | bool | true | Highlight rows on mouse hover |
| `print_name` | bool | false | Show table name above the table |
| `print_description` | bool | false | Show description below the name |
| `extra_css_classes` | string | "" | Additional CSS classes on the `<table>` element |
