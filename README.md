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

## Tools (v1.0.0)

**WordPress**

| Tool | Description |
|------|-------------|
| `get_site_info` | Site name, URL, WP version, theme, Woo status |
| `search_content` | Keyword search across posts, pages, products |
| `list_posts` / `get_post` | Browse and read posts |
| `create_post` / `update_post` | Write content (create defaults to draft) |
| `list_pages` | Browse pages |

**WooCommerce** (auto-enabled when Woo is active)

| Tool | Description |
|------|-------------|
| `list_products` / `get_product` | Browse catalog with keyword/category filters |
| `update_product` | Prices, sale price, stock status, descriptions |
| `list_orders` / `get_order` | Orders with line items and customer details |
| `get_store_stats` | Revenue + order count over a lookback window |

## Adding tools

Each tool is two pieces in `aff-mcp-connector.php`:

1. A definition in `tool_definitions()` — name, description, JSON Schema input
2. A `tool_*()` method + a case in `run_tool()`

Roadmap ideas: media upload, coupons, Yoast SEO fields, Elementor element editing, categories/tags management, comments moderation.

## Per-site deployment

The plugin is generic — to deploy on another client site, optionally rename the plugin header, `SERVER_NAME` constant, and namespace to match the site, then install as above. Each site generates its own token.
