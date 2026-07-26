# WP MCP Connector

A single-file WordPress plugin that exposes a **Model Context Protocol (MCP)** endpoint, letting Claude connect to any WordPress / WooCommerce site as a **custom connector**.

Currently deployed on: alphafreshfood.com

## How it works

- Registers a REST route at `/wp-json/aff-mcp/v1/mcp/{token}` speaking MCP JSON-RPC 2.0 over Streamable HTTP (`initialize`, `tools/list`, `tools/call`, `ping`, notifications)
- Auth token is generated on plugin activation and embedded in the connector URL (Claude custom connectors take a URL only)
- Stateless — no sessions, no database tables, just a single option for the token
- WooCommerce tools register only when WooCommerce is active, so the plugin works on plain WP sites too

## Install

1. Download `aff-mcp-connector/aff-mcp-connector.php` (or zip the folder) and install via **Plugins → Add New → Upload**
2. Activate
3. Go to **Settings → MCP Connector** and copy the connector URL
4. In Claude: **Settings → Connectors → Add custom connector** → paste the URL

⚠️ Treat the connector URL like a password — anyone with it can read and edit site content. Use the **Regenerate token** button on the settings page if it ever leaks.

## Tools (v1.1.0 — 28 tools)

**Content**

| Tool | Description |
|------|-------------|
| `get_site_info` | Site name, URL, WP version, theme, Woo status |
| `search_content` | Keyword search across posts, pages, products |
| `list_posts` / `get_post` | Browse and read posts |
| `create_post` / `update_post` | Write content (create defaults to draft) |
| `list_pages` | Browse pages |

**Media**

| Tool | Description |
|------|-------------|
| `upload_media_from_url` | Sideload an image from a public URL, with title + alt text |
| `list_media` | Browse the media library |
| `set_featured_image` | Set featured image on any post/page/product |

**Taxonomies**

| Tool | Description |
|------|-------------|
| `list_terms` | Terms in any taxonomy (category, post_tag, product_cat…) |
| `create_term` / `update_term` / `delete_term` | Full term CRUD |
| `set_post_terms` | Assign categories/tags to content |

**SEO** (auto-detects Yoast or Rank Math)

| Tool | Description |
|------|-------------|
| `get_seo_meta` / `update_seo_meta` | SEO title, meta description, focus keyword |

**WooCommerce** (auto-enabled when Woo is active)

| Tool | Description |
|------|-------------|
| `list_products` / `get_product` | Browse catalog with keyword/category filters |
| `update_product` | Prices, sale price, stock status, descriptions |
| `list_orders` / `get_order` | Orders with line items and customer details |
| `get_store_stats` | Revenue + order count over a lookback window |
| `list_coupons` / `get_coupon` | Browse coupons, lookup by ID or code |
| `create_coupon` / `update_coupon` / `delete_coupon` | Coupon CRUD (delete = trash) |

## Adding tools

Each tool is two pieces in `aff-mcp-connector.php`:

1. A definition in `tool_definitions()` — name, description, JSON Schema input
2. A `tool_*()` method + a case in `run_tool()`

Roadmap ideas: Elementor element editing, comments moderation, product creation, order status updates, plugin/theme listing.

## Changelog

- **1.1.0** — Media tools (URL sideload, library, featured image), taxonomy CRUD + assignment, SEO meta (Yoast/Rank Math auto-detect), coupon CRUD
- **1.0.0** — Initial release: MCP transport, WP content tools, Woo products/orders/stats

## Per-site deployment

The plugin is generic — to deploy on another client site, optionally rename the plugin header, `SERVER_NAME` constant, and namespace to match the site, then install as above. Each site generates its own token.
