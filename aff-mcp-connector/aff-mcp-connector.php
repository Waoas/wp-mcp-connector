<?php
/**
 * Plugin Name: AFF MCP Connector
 * Description: Exposes a Model Context Protocol (MCP) endpoint so Claude can connect to this site as a custom connector. Supports WordPress content + WooCommerce store tools.
 * Version: 1.2.0
 * Author: Waris Salawudeen
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AFF_MCP_Connector {

	const OPTION_TOKEN   = 'aff_mcp_token';
	const PROTOCOL       = '2025-03-26';
	const SERVER_NAME    = 'alpha-fresh-food-mcp';
	const SERVER_VERSION = '1.2.0';
	const UPDATE_URL     = 'https://raw.githubusercontent.com/Waoas/wp-mcp-connector/main/aff-mcp-connector/aff-mcp-connector.php';

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	public function on_activate() {
		if ( ! get_option( self::OPTION_TOKEN ) ) {
			update_option( self::OPTION_TOKEN, wp_generate_password( 40, false, false ) );
		}
	}

	/* ---------------------------------------------------------------
	 * REST route  —  /wp-json/aff-mcp/v1/mcp/{token}
	 * Token lives in the path because Claude custom connectors only
	 * take a URL (no header field for API keys).
	 * ------------------------------------------------------------- */
	public function register_routes() {
		register_rest_route( 'aff-mcp/v1', '/mcp/(?P<token>[A-Za-z0-9]+)', array(
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_token' ),
			),
		) );
	}

	public function check_token( $request ) {
		$token = get_option( self::OPTION_TOKEN );
		return $token && hash_equals( $token, (string) $request['token'] );
	}

	/* ---------------------------------------------------------------
	 * MCP Streamable-HTTP transport (JSON responses)
	 * ------------------------------------------------------------- */
	public function handle_request( $request ) {
		$method = $request->get_method();

		// GET = client probing for an SSE stream. We don't stream; say so.
		if ( 'GET' === $method ) {
			return new WP_REST_Response( null, 405 );
		}
		// DELETE = session teardown. Stateless server, nothing to do.
		if ( 'DELETE' === $method ) {
			return new WP_REST_Response( null, 200 );
		}

		$body = json_decode( $request->get_body(), true );
		if ( null === $body ) {
			return $this->jsonrpc_error( null, -32700, 'Parse error' );
		}

		// Batch or single message
		$messages = isset( $body[0] ) ? $body : array( $body );
		$responses = array();

		foreach ( $messages as $msg ) {
			$resp = $this->dispatch( $msg );
			if ( null !== $resp ) $responses[] = $resp;
		}

		// Pure notifications -> 202 Accepted, empty body
		if ( empty( $responses ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$payload = isset( $body[0] ) ? $responses : $responses[0];
		return new WP_REST_Response( $payload, 200 );
	}

	private function dispatch( $msg ) {
		$id     = isset( $msg['id'] ) ? $msg['id'] : null;
		$method = isset( $msg['method'] ) ? $msg['method'] : '';

		// Notifications never get a response
		if ( 0 === strpos( $method, 'notifications/' ) ) return null;

		switch ( $method ) {
			case 'initialize':
				return $this->jsonrpc_result( $id, array(
					'protocolVersion' => self::PROTOCOL,
					'capabilities'    => array( 'tools' => new stdClass() ),
					'serverInfo'      => array(
						'name'    => self::SERVER_NAME,
						'version' => self::SERVER_VERSION,
					),
				) );

			case 'ping':
				return $this->jsonrpc_result( $id, new stdClass() );

			case 'tools/list':
				return $this->jsonrpc_result( $id, array( 'tools' => $this->tool_definitions() ) );

			case 'tools/call':
				$name = isset( $msg['params']['name'] ) ? $msg['params']['name'] : '';
				$args = isset( $msg['params']['arguments'] ) ? $msg['params']['arguments'] : array();
				return $this->run_tool( $id, $name, $args );

			default:
				return $this->jsonrpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	private function jsonrpc_result( $id, $result ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	private function jsonrpc_error( $id, $code, $message ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
	}

	/* ---------------------------------------------------------------
	 * Tool definitions
	 * ------------------------------------------------------------- */
	private function tool_definitions() {
		$str  = array( 'type' => 'string' );
		$int  = array( 'type' => 'integer' );
		$num  = array( 'type' => 'number' );

		$tools = array(
			array(
				'name'        => 'get_site_info',
				'description' => 'Get site name, tagline, URL, WordPress version, active theme, and whether WooCommerce is active.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
			),
			array(
				'name'        => 'search_content',
				'description' => 'Search posts, pages, and products by keyword.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'keyword' => $str, 'limit' => $int ),
					'required'   => array( 'keyword' ),
				),
			),
			array(
				'name'        => 'list_posts',
				'description' => 'List blog posts (id, title, status, date, excerpt).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'description' => 'publish, draft, any (default publish)' ),
						'limit'  => $int,
						'page'   => $int,
					),
				),
			),
			array(
				'name'        => 'get_post',
				'description' => 'Get a single post or page by ID, including full content.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => $int ),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'create_post',
				'description' => 'Create a blog post. Defaults to draft status for safety.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'   => $str,
						'content' => array( 'type' => 'string', 'description' => 'HTML content' ),
						'status'  => array( 'type' => 'string', 'description' => 'draft or publish (default draft)' ),
						'excerpt' => $str,
					),
					'required'   => array( 'title', 'content' ),
				),
			),
			array(
				'name'        => 'update_post',
				'description' => 'Update an existing post or page. Only supplied fields change.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => $int,
						'title'   => $str,
						'content' => $str,
						'status'  => $str,
						'excerpt' => $str,
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'list_pages',
				'description' => 'List pages (id, title, status, slug).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'limit' => $int, 'page' => $int ),
				),
			),
			array(
				'name'        => 'upload_media_from_url',
				'description' => 'Download an image from a public URL and add it to the media library. Returns the attachment ID and URL.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'   => array( 'type' => 'string', 'description' => 'Public https image URL' ),
						'title' => $str,
						'alt'   => array( 'type' => 'string', 'description' => 'Alt text for SEO/accessibility' ),
					),
					'required'   => array( 'url' ),
				),
			),
			array(
				'name'        => 'list_media',
				'description' => 'List media library attachments (id, title, url, mime type, alt).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'search' => $str, 'limit' => $int, 'page' => $int ),
				),
			),
			array(
				'name'        => 'set_featured_image',
				'description' => 'Set the featured image on a post, page, or product.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => $int, 'media_id' => $int ),
					'required'   => array( 'post_id', 'media_id' ),
				),
			),
			array(
				'name'        => 'list_terms',
				'description' => 'List terms in a taxonomy (category, post_tag, product_cat, product_tag, or any custom taxonomy).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => array( 'type' => 'string', 'description' => 'Default: category' ),
						'search'   => $str,
						'limit'    => $int,
					),
				),
			),
			array(
				'name'        => 'create_term',
				'description' => 'Create a term (category, tag, product category, etc.) in any taxonomy.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'    => $str,
						'name'        => $str,
						'slug'        => $str,
						'description' => $str,
						'parent'      => array( 'type' => 'integer', 'description' => 'Parent term ID for hierarchical taxonomies' ),
					),
					'required'   => array( 'taxonomy', 'name' ),
				),
			),
			array(
				'name'        => 'update_term',
				'description' => 'Update a term. Only supplied fields change.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'    => $str,
						'term_id'     => $int,
						'name'        => $str,
						'slug'        => $str,
						'description' => $str,
						'parent'      => $int,
					),
					'required'   => array( 'taxonomy', 'term_id' ),
				),
			),
			array(
				'name'        => 'delete_term',
				'description' => 'Delete a term from a taxonomy.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'taxonomy' => $str, 'term_id' => $int ),
					'required'   => array( 'taxonomy', 'term_id' ),
				),
			),
			array(
				'name'        => 'set_post_terms',
				'description' => 'Assign terms to a post/page/product. Accepts term names (created if missing for non-hierarchical) or IDs.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => $int,
						'taxonomy' => $str,
						'terms'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Term names or numeric IDs' ),
						'append'   => array( 'type' => 'boolean', 'description' => 'true = add to existing terms, false = replace (default false)' ),
					),
					'required'   => array( 'post_id', 'taxonomy', 'terms' ),
				),
			),
			array(
				'name'        => 'get_seo_meta',
				'description' => 'Get SEO title, meta description, and focus keyword for a post/page/product (supports Yoast and Rank Math).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'post_id' => $int ),
					'required'   => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'update_seo_meta',
				'description' => 'Update SEO title, meta description, and/or focus keyword on a post/page/product (supports Yoast and Rank Math).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => $int,
						'seo_title'        => $str,
						'meta_description' => $str,
						'focus_keyword'    => $str,
					),
					'required'   => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'check_update',
				'description' => 'Check whether a newer version of this MCP connector plugin is available on GitHub.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
			),
			array(
				'name'        => 'self_update',
				'description' => 'Update this MCP connector plugin to the latest version from GitHub. Backs up the current file first, validates the download, then overwrites itself. New tools become available on the next request.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
			),
		);

		// WooCommerce tools only when Woo is active
		if ( class_exists( 'WooCommerce' ) ) {
			$tools = array_merge( $tools, array(
				array(
					'name'        => 'list_products',
					'description' => 'List WooCommerce products (id, name, sku, price, sale price, stock status, categories).',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'keyword'  => $str,
							'category' => array( 'type' => 'string', 'description' => 'category slug' ),
							'status'   => array( 'type' => 'string', 'description' => 'publish, draft, any' ),
							'limit'    => $int,
							'page'     => $int,
						),
					),
				),
				array(
					'name'        => 'get_product',
					'description' => 'Get full details for one product by ID.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array( 'id' => $int ),
						'required'   => array( 'id' ),
					),
				),
				array(
					'name'        => 'update_product',
					'description' => 'Update a product. Only supplied fields change.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'id'            => $int,
							'name'          => $str,
							'regular_price' => $num,
							'sale_price'    => array( 'type' => 'number', 'description' => 'Set to 0 to remove sale price' ),
							'description'   => $str,
							'short_description' => $str,
							'stock_status'  => array( 'type' => 'string', 'description' => 'instock | outofstock' ),
							'status'        => $str,
						),
						'required'   => array( 'id' ),
					),
				),
				array(
					'name'        => 'list_orders',
					'description' => 'List recent orders (id, status, total, customer, date).',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'status' => array( 'type' => 'string', 'description' => 'e.g. processing, completed, on-hold' ),
							'limit'  => $int,
							'page'   => $int,
						),
					),
				),
				array(
					'name'        => 'get_order',
					'description' => 'Get one order with line items, totals, and shipping/billing details.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array( 'id' => $int ),
						'required'   => array( 'id' ),
					),
				),
				array(
					'name'        => 'get_store_stats',
					'description' => 'Revenue and order counts over a recent period.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array( 'days' => array( 'type' => 'integer', 'description' => 'lookback window, default 30' ) ),
					),
				),
				array(
					'name'        => 'list_coupons',
					'description' => 'List WooCommerce coupons (id, code, type, amount, usage, expiry).',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array( 'search' => array( 'type' => 'string', 'description' => 'filter by code' ), 'limit' => $int, 'page' => $int ),
					),
				),
				array(
					'name'        => 'get_coupon',
					'description' => 'Get one coupon by ID or code.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array( 'id' => $int, 'code' => $str ),
					),
				),
				array(
					'name'        => 'create_coupon',
					'description' => 'Create a WooCommerce coupon.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'code'           => $str,
							'discount_type'  => array( 'type' => 'string', 'description' => 'percent | fixed_cart | fixed_product (default percent)' ),
							'amount'         => $num,
							'description'    => $str,
							'expiry_date'    => array( 'type' => 'string', 'description' => 'YYYY-MM-DD' ),
							'minimum_amount' => $num,
							'usage_limit'    => $int,
							'free_shipping'  => array( 'type' => 'boolean' ),
						),
						'required'   => array( 'code', 'amount' ),
					),
				),
				array(
					'name'        => 'update_coupon',
					'description' => 'Update an existing coupon. Only supplied fields change.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'id'             => $int,
							'code'           => $str,
							'discount_type'  => $str,
							'amount'         => $num,
							'description'    => $str,
							'expiry_date'    => array( 'type' => 'string', 'description' => 'YYYY-MM-DD, empty string to clear' ),
							'minimum_amount' => $num,
							'usage_limit'    => $int,
							'free_shipping'  => array( 'type' => 'boolean' ),
						),
						'required'   => array( 'id' ),
					),
				),
				array(
					'name'        => 'delete_coupon',
					'description' => 'Move a coupon to trash.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array( 'id' => $int ),
						'required'   => array( 'id' ),
					),
				),
			) );
		}

		return $tools;
	}

	/* ---------------------------------------------------------------
	 * Tool execution
	 * ------------------------------------------------------------- */
	private function run_tool( $id, $name, $args ) {
		try {
			switch ( $name ) {
				case 'get_site_info':   $data = $this->tool_site_info(); break;
				case 'search_content':  $data = $this->tool_search( $args ); break;
				case 'list_posts':      $data = $this->tool_list_posts( $args ); break;
				case 'get_post':        $data = $this->tool_get_post( $args ); break;
				case 'create_post':     $data = $this->tool_create_post( $args ); break;
				case 'update_post':     $data = $this->tool_update_post( $args ); break;
				case 'list_pages':      $data = $this->tool_list_pages( $args ); break;
				case 'list_products':   $data = $this->tool_list_products( $args ); break;
				case 'get_product':     $data = $this->tool_get_product( $args ); break;
				case 'update_product':  $data = $this->tool_update_product( $args ); break;
				case 'list_orders':     $data = $this->tool_list_orders( $args ); break;
				case 'get_order':       $data = $this->tool_get_order( $args ); break;
				case 'get_store_stats': $data = $this->tool_store_stats( $args ); break;
				case 'upload_media_from_url': $data = $this->tool_upload_media( $args ); break;
				case 'list_media':      $data = $this->tool_list_media( $args ); break;
				case 'set_featured_image': $data = $this->tool_set_featured_image( $args ); break;
				case 'list_terms':      $data = $this->tool_list_terms( $args ); break;
				case 'create_term':     $data = $this->tool_create_term( $args ); break;
				case 'update_term':     $data = $this->tool_update_term( $args ); break;
				case 'delete_term':     $data = $this->tool_delete_term( $args ); break;
				case 'set_post_terms':  $data = $this->tool_set_post_terms( $args ); break;
				case 'get_seo_meta':    $data = $this->tool_get_seo_meta( $args ); break;
				case 'update_seo_meta': $data = $this->tool_update_seo_meta( $args ); break;
				case 'check_update':    $data = $this->tool_check_update(); break;
				case 'self_update':     $data = $this->tool_self_update(); break;
				case 'list_coupons':    $data = $this->tool_list_coupons( $args ); break;
				case 'get_coupon':      $data = $this->tool_get_coupon( $args ); break;
				case 'create_coupon':   $data = $this->tool_create_coupon( $args ); break;
				case 'update_coupon':   $data = $this->tool_update_coupon( $args ); break;
				case 'delete_coupon':   $data = $this->tool_delete_coupon( $args ); break;
				default:
					return $this->jsonrpc_error( $id, -32602, 'Unknown tool: ' . $name );
			}
		} catch ( Exception $e ) {
			return $this->jsonrpc_result( $id, array(
				'content' => array( array( 'type' => 'text', 'text' => 'Error: ' . $e->getMessage() ) ),
				'isError' => true,
			) );
		}

		return $this->jsonrpc_result( $id, array(
			'content' => array( array(
				'type' => 'text',
				'text' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			) ),
		) );
	}

	/* ----------------------- WordPress tools ---------------------- */

	private function tool_site_info() {
		$theme = wp_get_theme();
		return array(
			'name'        => get_bloginfo( 'name' ),
			'tagline'     => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'theme'       => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'woocommerce' => class_exists( 'WooCommerce' ),
			'timezone'    => wp_timezone_string(),
		);
	}

	private function tool_search( $args ) {
		$q = new WP_Query( array(
			'post_type'      => array( 'post', 'page', 'product' ),
			's'              => sanitize_text_field( $args['keyword'] ),
			'posts_per_page' => min( 50, isset( $args['limit'] ) ? (int) $args['limit'] : 10 ),
			'post_status'    => 'any',
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'     => $p->ID,
				'type'   => $p->post_type,
				'title'  => $p->post_title,
				'status' => $p->post_status,
				'url'    => get_permalink( $p ),
			);
		}
		return array( 'total_found' => (int) $q->found_posts, 'results' => $out );
	}

	private function tool_list_posts( $args ) {
		$q = new WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'publish',
			'posts_per_page' => min( 50, isset( $args['limit'] ) ? (int) $args['limit'] : 10 ),
			'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'      => $p->ID,
				'title'   => $p->post_title,
				'status'  => $p->post_status,
				'date'    => $p->post_date,
				'excerpt' => wp_trim_words( $p->post_excerpt ? $p->post_excerpt : wp_strip_all_tags( $p->post_content ), 30 ),
				'url'     => get_permalink( $p ),
			);
		}
		return array( 'total' => (int) $q->found_posts, 'posts' => $out );
	}

	private function tool_get_post( $args ) {
		$p = get_post( (int) $args['id'] );
		if ( ! $p ) throw new Exception( 'Post not found' );
		return array(
			'id'      => $p->ID,
			'type'    => $p->post_type,
			'title'   => $p->post_title,
			'status'  => $p->post_status,
			'slug'    => $p->post_name,
			'date'    => $p->post_date,
			'content' => $p->post_content,
			'excerpt' => $p->post_excerpt,
			'url'     => get_permalink( $p ),
		);
	}

	private function tool_create_post( $args ) {
		$status = isset( $args['status'] ) && 'publish' === $args['status'] ? 'publish' : 'draft';
		$id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_title'   => sanitize_text_field( $args['title'] ),
			'post_content' => wp_kses_post( $args['content'] ),
			'post_excerpt' => isset( $args['excerpt'] ) ? sanitize_text_field( $args['excerpt'] ) : '',
			'post_status'  => $status,
		), true );
		if ( is_wp_error( $id ) ) throw new Exception( $id->get_error_message() );
		return array( 'id' => $id, 'status' => $status, 'url' => get_permalink( $id ) );
	}

	private function tool_update_post( $args ) {
		$update = array( 'ID' => (int) $args['id'] );
		if ( isset( $args['title'] ) )   $update['post_title']   = sanitize_text_field( $args['title'] );
		if ( isset( $args['content'] ) ) $update['post_content'] = wp_kses_post( $args['content'] );
		if ( isset( $args['status'] ) )  $update['post_status']  = sanitize_key( $args['status'] );
		if ( isset( $args['excerpt'] ) ) $update['post_excerpt'] = sanitize_text_field( $args['excerpt'] );
		$res = wp_update_post( $update, true );
		if ( is_wp_error( $res ) ) throw new Exception( $res->get_error_message() );
		return array( 'id' => (int) $args['id'], 'updated' => array_keys( $update ), 'url' => get_permalink( $res ) );
	}

	private function tool_list_pages( $args ) {
		$q = new WP_Query( array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => min( 100, isset( $args['limit'] ) ? (int) $args['limit'] : 25 ),
			'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'     => $p->ID,
				'title'  => $p->post_title,
				'status' => $p->post_status,
				'slug'   => $p->post_name,
				'url'    => get_permalink( $p ),
			);
		}
		return array( 'total' => (int) $q->found_posts, 'pages' => $out );
	}

	/* ----------------------- WooCommerce tools -------------------- */

	private function require_woo() {
		if ( ! class_exists( 'WooCommerce' ) ) throw new Exception( 'WooCommerce is not active on this site.' );
	}

	private function tool_list_products( $args ) {
		$this->require_woo();
		$query_args = array(
			'limit'  => min( 50, isset( $args['limit'] ) ? (int) $args['limit'] : 10 ),
			'page'   => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
			'status' => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'publish',
		);
		if ( ! empty( $args['keyword'] ) )  $query_args['s'] = sanitize_text_field( $args['keyword'] );
		if ( ! empty( $args['category'] ) ) $query_args['category'] = array( sanitize_title( $args['category'] ) );

		$products = wc_get_products( $query_args );
		$out = array();
		foreach ( $products as $p ) {
			$out[] = array(
				'id'            => $p->get_id(),
				'name'          => $p->get_name(),
				'sku'           => $p->get_sku(),
				'regular_price' => $p->get_regular_price(),
				'sale_price'    => $p->get_sale_price(),
				'stock_status'  => $p->get_stock_status(),
				'categories'    => wp_list_pluck( get_the_terms( $p->get_id(), 'product_cat' ) ?: array(), 'name' ),
				'url'           => get_permalink( $p->get_id() ),
			);
		}
		return array( 'products' => $out );
	}

	private function tool_get_product( $args ) {
		$this->require_woo();
		$p = wc_get_product( (int) $args['id'] );
		if ( ! $p ) throw new Exception( 'Product not found' );
		return array(
			'id'                => $p->get_id(),
			'name'              => $p->get_name(),
			'type'              => $p->get_type(),
			'status'            => $p->get_status(),
			'sku'               => $p->get_sku(),
			'regular_price'     => $p->get_regular_price(),
			'sale_price'        => $p->get_sale_price(),
			'stock_status'      => $p->get_stock_status(),
			'stock_quantity'    => $p->get_stock_quantity(),
			'description'       => $p->get_description(),
			'short_description' => $p->get_short_description(),
			'categories'        => wp_list_pluck( get_the_terms( $p->get_id(), 'product_cat' ) ?: array(), 'name' ),
			'image'             => wp_get_attachment_url( $p->get_image_id() ),
			'url'               => get_permalink( $p->get_id() ),
		);
	}

	private function tool_update_product( $args ) {
		$this->require_woo();
		$p = wc_get_product( (int) $args['id'] );
		if ( ! $p ) throw new Exception( 'Product not found' );

		$changed = array();
		if ( isset( $args['name'] ) )              { $p->set_name( sanitize_text_field( $args['name'] ) ); $changed[] = 'name'; }
		if ( isset( $args['regular_price'] ) )     { $p->set_regular_price( (string) floatval( $args['regular_price'] ) ); $changed[] = 'regular_price'; }
		if ( isset( $args['sale_price'] ) )        { $p->set_sale_price( floatval( $args['sale_price'] ) > 0 ? (string) floatval( $args['sale_price'] ) : '' ); $changed[] = 'sale_price'; }
		if ( isset( $args['description'] ) )       { $p->set_description( wp_kses_post( $args['description'] ) ); $changed[] = 'description'; }
		if ( isset( $args['short_description'] ) ) { $p->set_short_description( wp_kses_post( $args['short_description'] ) ); $changed[] = 'short_description'; }
		if ( isset( $args['stock_status'] ) )      { $p->set_stock_status( sanitize_key( $args['stock_status'] ) ); $changed[] = 'stock_status'; }
		if ( isset( $args['status'] ) )            { $p->set_status( sanitize_key( $args['status'] ) ); $changed[] = 'status'; }
		$p->save();

		return array( 'id' => $p->get_id(), 'updated' => $changed );
	}

	private function tool_list_orders( $args ) {
		$this->require_woo();
		$orders = wc_get_orders( array(
			'limit'  => min( 50, isset( $args['limit'] ) ? (int) $args['limit'] : 10 ),
			'paged'  => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
			'status' => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'any',
		) );
		$out = array();
		foreach ( $orders as $o ) {
			$out[] = array(
				'id'       => $o->get_id(),
				'status'   => $o->get_status(),
				'total'    => $o->get_total(),
				'currency' => $o->get_currency(),
				'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
				'email'    => $o->get_billing_email(),
				'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : null,
			);
		}
		return array( 'orders' => $out );
	}

	private function tool_get_order( $args ) {
		$this->require_woo();
		$o = wc_get_order( (int) $args['id'] );
		if ( ! $o ) throw new Exception( 'Order not found' );
		$items = array();
		foreach ( $o->get_items() as $item ) {
			$items[] = array(
				'product'  => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
			);
		}
		return array(
			'id'       => $o->get_id(),
			'status'   => $o->get_status(),
			'total'    => $o->get_total(),
			'shipping' => $o->get_shipping_total(),
			'currency' => $o->get_currency(),
			'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
			'email'    => $o->get_billing_email(),
			'phone'    => $o->get_billing_phone(),
			'address'  => $o->get_formatted_shipping_address() ?: $o->get_formatted_billing_address(),
			'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : null,
			'items'    => $items,
		);
	}

	private function tool_store_stats( $args ) {
		$this->require_woo();
		$days   = isset( $args['days'] ) ? max( 1, (int) $args['days'] ) : 30;
		$orders = wc_get_orders( array(
			'limit'        => -1,
			'status'       => array( 'wc-completed', 'wc-processing' ),
			'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
		) );
		$revenue = 0;
		foreach ( $orders as $o ) $revenue += (float) $o->get_total();
		return array(
			'period_days' => $days,
			'order_count' => count( $orders ),
			'revenue'     => round( $revenue, 2 ),
			'currency'    => get_woocommerce_currency(),
		);
	}

	/* ----------------------- Media tools -------------------------- */

	private function tool_upload_media( $args ) {
		$url = esc_url_raw( $args['url'] );
		if ( 0 !== strpos( $url, 'http' ) ) throw new Exception( 'A valid http(s) URL is required.' );

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_sideload_image( $url, 0, isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : null, 'id' );
		if ( is_wp_error( $id ) ) throw new Exception( $id->get_error_message() );

		if ( ! empty( $args['title'] ) ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( $args['title'] ) ) );
		}
		if ( ! empty( $args['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
		}
		return array( 'media_id' => $id, 'url' => wp_get_attachment_url( $id ) );
	}

	private function tool_list_media( $args ) {
		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '',
			'posts_per_page' => min( 50, isset( $args['limit'] ) ? (int) $args['limit'] : 20 ),
			'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'    => $p->ID,
				'title' => $p->post_title,
				'url'   => wp_get_attachment_url( $p->ID ),
				'mime'  => $p->post_mime_type,
				'alt'   => get_post_meta( $p->ID, '_wp_attachment_image_alt', true ),
			);
		}
		return array( 'total' => (int) $q->found_posts, 'media' => $out );
	}

	private function tool_set_featured_image( $args ) {
		$post_id  = (int) $args['post_id'];
		$media_id = (int) $args['media_id'];
		if ( ! get_post( $post_id ) )  throw new Exception( 'Post not found' );
		if ( ! wp_attachment_is_image( $media_id ) ) throw new Exception( 'Media ID is not an image attachment' );
		set_post_thumbnail( $post_id, $media_id );
		return array( 'post_id' => $post_id, 'featured_media_id' => $media_id );
	}

	/* ----------------------- Taxonomy tools ----------------------- */

	private function tool_list_terms( $args ) {
		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';
		if ( ! taxonomy_exists( $taxonomy ) ) throw new Exception( 'Taxonomy not found: ' . $taxonomy );
		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'search'     => isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '',
			'number'     => min( 200, isset( $args['limit'] ) ? (int) $args['limit'] : 100 ),
		) );
		if ( is_wp_error( $terms ) ) throw new Exception( $terms->get_error_message() );
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array(
				'id'     => $t->term_id,
				'name'   => $t->name,
				'slug'   => $t->slug,
				'count'  => $t->count,
				'parent' => $t->parent,
			);
		}
		return array( 'taxonomy' => $taxonomy, 'terms' => $out );
	}

	private function tool_create_term( $args ) {
		$taxonomy = sanitize_key( $args['taxonomy'] );
		if ( ! taxonomy_exists( $taxonomy ) ) throw new Exception( 'Taxonomy not found: ' . $taxonomy );
		$opts = array();
		if ( ! empty( $args['slug'] ) )        $opts['slug']        = sanitize_title( $args['slug'] );
		if ( ! empty( $args['description'] ) ) $opts['description'] = sanitize_text_field( $args['description'] );
		if ( ! empty( $args['parent'] ) )      $opts['parent']      = (int) $args['parent'];
		$res = wp_insert_term( sanitize_text_field( $args['name'] ), $taxonomy, $opts );
		if ( is_wp_error( $res ) ) throw new Exception( $res->get_error_message() );
		return array( 'term_id' => $res['term_id'], 'taxonomy' => $taxonomy );
	}

	private function tool_update_term( $args ) {
		$taxonomy = sanitize_key( $args['taxonomy'] );
		$update = array();
		if ( isset( $args['name'] ) )        $update['name']        = sanitize_text_field( $args['name'] );
		if ( isset( $args['slug'] ) )        $update['slug']        = sanitize_title( $args['slug'] );
		if ( isset( $args['description'] ) ) $update['description'] = sanitize_text_field( $args['description'] );
		if ( isset( $args['parent'] ) )      $update['parent']      = (int) $args['parent'];
		$res = wp_update_term( (int) $args['term_id'], $taxonomy, $update );
		if ( is_wp_error( $res ) ) throw new Exception( $res->get_error_message() );
		return array( 'term_id' => $res['term_id'], 'updated' => array_keys( $update ) );
	}

	private function tool_delete_term( $args ) {
		$res = wp_delete_term( (int) $args['term_id'], sanitize_key( $args['taxonomy'] ) );
		if ( is_wp_error( $res ) ) throw new Exception( $res->get_error_message() );
		if ( ! $res ) throw new Exception( 'Term not found or is a default term that cannot be deleted.' );
		return array( 'deleted' => true, 'term_id' => (int) $args['term_id'] );
	}

	private function tool_set_post_terms( $args ) {
		$post_id  = (int) $args['post_id'];
		$taxonomy = sanitize_key( $args['taxonomy'] );
		if ( ! get_post( $post_id ) ) throw new Exception( 'Post not found' );
		if ( ! taxonomy_exists( $taxonomy ) ) throw new Exception( 'Taxonomy not found: ' . $taxonomy );

		$terms = array();
		foreach ( (array) $args['terms'] as $t ) {
			$terms[] = is_numeric( $t ) ? (int) $t : sanitize_text_field( $t );
		}
		$res = wp_set_object_terms( $post_id, $terms, $taxonomy, ! empty( $args['append'] ) );
		if ( is_wp_error( $res ) ) throw new Exception( $res->get_error_message() );
		return array( 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'term_ids' => $res );
	}

	/* ----------------------- SEO meta tools ------------------------ */

	private function seo_meta_keys() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return array(
				'plugin'      => 'yoast',
				'title'       => '_yoast_wpseo_title',
				'description' => '_yoast_wpseo_metadesc',
				'keyword'     => '_yoast_wpseo_focuskw',
			);
		}
		if ( class_exists( 'RankMath' ) ) {
			return array(
				'plugin'      => 'rank_math',
				'title'       => 'rank_math_title',
				'description' => 'rank_math_description',
				'keyword'     => 'rank_math_focus_keyword',
			);
		}
		throw new Exception( 'No supported SEO plugin (Yoast or Rank Math) is active on this site.' );
	}

	private function tool_get_seo_meta( $args ) {
		$post_id = (int) $args['post_id'];
		if ( ! get_post( $post_id ) ) throw new Exception( 'Post not found' );
		$keys = $this->seo_meta_keys();
		return array(
			'post_id'          => $post_id,
			'seo_plugin'       => $keys['plugin'],
			'seo_title'        => get_post_meta( $post_id, $keys['title'], true ),
			'meta_description' => get_post_meta( $post_id, $keys['description'], true ),
			'focus_keyword'    => get_post_meta( $post_id, $keys['keyword'], true ),
		);
	}

	private function tool_update_seo_meta( $args ) {
		$post_id = (int) $args['post_id'];
		if ( ! get_post( $post_id ) ) throw new Exception( 'Post not found' );
		$keys    = $this->seo_meta_keys();
		$changed = array();
		if ( isset( $args['seo_title'] ) )        { update_post_meta( $post_id, $keys['title'], sanitize_text_field( $args['seo_title'] ) ); $changed[] = 'seo_title'; }
		if ( isset( $args['meta_description'] ) ) { update_post_meta( $post_id, $keys['description'], sanitize_text_field( $args['meta_description'] ) ); $changed[] = 'meta_description'; }
		if ( isset( $args['focus_keyword'] ) )    { update_post_meta( $post_id, $keys['keyword'], sanitize_text_field( $args['focus_keyword'] ) ); $changed[] = 'focus_keyword'; }
		return array( 'post_id' => $post_id, 'seo_plugin' => $keys['plugin'], 'updated' => $changed );
	}

	/* ----------------------- Self-update tools --------------------- */

	private function fetch_latest_plugin_code() {
		$resp = wp_remote_get( self::UPDATE_URL, array( 'timeout' => 20 ) );
		if ( is_wp_error( $resp ) ) throw new Exception( 'Could not reach GitHub: ' . $resp->get_error_message() );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			throw new Exception( 'GitHub returned HTTP ' . wp_remote_retrieve_response_code( $resp ) );
		}
		$code = wp_remote_retrieve_body( $resp );

		// Validate before trusting it
		if ( 0 !== strpos( $code, '<?php' ) )                       throw new Exception( 'Downloaded file is not a PHP file.' );
		if ( false === strpos( $code, 'class AFF_MCP_Connector' ) ) throw new Exception( 'Downloaded file is missing the plugin class.' );
		if ( false === strpos( $code, 'new AFF_MCP_Connector();' ) ) throw new Exception( 'Downloaded file appears truncated.' );
		if ( strlen( $code ) < 10000 )                              throw new Exception( 'Downloaded file is suspiciously small.' );

		return $code;
	}

	private function parse_plugin_version( $code ) {
		return preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/mi', $code, $m ) ? $m[1] : 'unknown';
	}

	private function tool_check_update() {
		$code   = $this->fetch_latest_plugin_code();
		$latest = $this->parse_plugin_version( $code );
		return array(
			'installed_version' => self::SERVER_VERSION,
			'latest_version'    => $latest,
			'update_available'  => version_compare( $latest, self::SERVER_VERSION, '>' ),
			'source'            => self::UPDATE_URL,
		);
	}

	private function tool_self_update() {
		$code   = $this->fetch_latest_plugin_code();
		$latest = $this->parse_plugin_version( $code );

		if ( ! version_compare( $latest, self::SERVER_VERSION, '>' ) ) {
			return array(
				'updated'           => false,
				'message'           => 'Already on the latest version.',
				'installed_version' => self::SERVER_VERSION,
				'latest_version'    => $latest,
			);
		}

		$file = __FILE__;
		if ( ! is_writable( $file ) ) throw new Exception( 'Plugin file is not writable by the web server: ' . $file );

		// Keep a rollback copy of the current version
		$backup = $file . '.bak';
		if ( ! copy( $file, $backup ) ) throw new Exception( 'Could not create backup copy before updating.' );

		if ( false === file_put_contents( $file, $code ) ) {
			copy( $backup, $file ); // attempt restore
			throw new Exception( 'Failed to write the updated plugin file. Original restored.' );
		}

		return array(
			'updated'      => true,
			'from_version' => self::SERVER_VERSION,
			'to_version'   => $latest,
			'backup_file'  => basename( $backup ),
			'note'         => 'New tools are live from the next request onward.',
		);
	}

	/* ----------------------- Coupon tools -------------------------- */

	private function coupon_summary( $c ) {
		$expires = $c->get_date_expires();
		return array(
			'id'             => $c->get_id(),
			'code'           => $c->get_code(),
			'discount_type'  => $c->get_discount_type(),
			'amount'         => $c->get_amount(),
			'description'    => $c->get_description(),
			'expiry_date'    => $expires ? $expires->date( 'Y-m-d' ) : null,
			'minimum_amount' => $c->get_minimum_amount(),
			'usage_limit'    => $c->get_usage_limit(),
			'usage_count'    => $c->get_usage_count(),
			'free_shipping'  => $c->get_free_shipping(),
		);
	}

	private function apply_coupon_fields( $c, $args ) {
		$changed = array();
		if ( isset( $args['code'] ) )           { $c->set_code( wc_format_coupon_code( $args['code'] ) ); $changed[] = 'code'; }
		if ( isset( $args['discount_type'] ) )  { $c->set_discount_type( sanitize_key( $args['discount_type'] ) ); $changed[] = 'discount_type'; }
		if ( isset( $args['amount'] ) )         { $c->set_amount( (string) floatval( $args['amount'] ) ); $changed[] = 'amount'; }
		if ( isset( $args['description'] ) )    { $c->set_description( sanitize_text_field( $args['description'] ) ); $changed[] = 'description'; }
		if ( isset( $args['expiry_date'] ) )    { $c->set_date_expires( '' !== $args['expiry_date'] ? sanitize_text_field( $args['expiry_date'] ) : null ); $changed[] = 'expiry_date'; }
		if ( isset( $args['minimum_amount'] ) ) { $c->set_minimum_amount( (string) floatval( $args['minimum_amount'] ) ); $changed[] = 'minimum_amount'; }
		if ( isset( $args['usage_limit'] ) )    { $c->set_usage_limit( (int) $args['usage_limit'] ); $changed[] = 'usage_limit'; }
		if ( isset( $args['free_shipping'] ) )  { $c->set_free_shipping( (bool) $args['free_shipping'] ); $changed[] = 'free_shipping'; }
		return $changed;
	}

	private function tool_list_coupons( $args ) {
		$this->require_woo();
		$query = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => min( 50, isset( $args['limit'] ) ? (int) $args['limit'] : 20 ),
			'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
		);
		if ( ! empty( $args['search'] ) ) $query['s'] = sanitize_text_field( $args['search'] );
		$q = new WP_Query( $query );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = $this->coupon_summary( new WC_Coupon( $p->ID ) );
		}
		return array( 'total' => (int) $q->found_posts, 'coupons' => $out );
	}

	private function tool_get_coupon( $args ) {
		$this->require_woo();
		$id = 0;
		if ( ! empty( $args['id'] ) ) {
			$id = (int) $args['id'];
		} elseif ( ! empty( $args['code'] ) ) {
			$id = wc_get_coupon_id_by_code( wc_format_coupon_code( $args['code'] ) );
		}
		if ( ! $id || 'shop_coupon' !== get_post_type( $id ) ) throw new Exception( 'Coupon not found' );
		return $this->coupon_summary( new WC_Coupon( $id ) );
	}

	private function tool_create_coupon( $args ) {
		$this->require_woo();
		$code = wc_format_coupon_code( $args['code'] );
		if ( wc_get_coupon_id_by_code( $code ) ) throw new Exception( 'A coupon with this code already exists.' );
		$c = new WC_Coupon();
		if ( ! isset( $args['discount_type'] ) ) $args['discount_type'] = 'percent';
		$this->apply_coupon_fields( $c, $args );
		$c->save();
		return $this->coupon_summary( $c );
	}

	private function tool_update_coupon( $args ) {
		$this->require_woo();
		$id = (int) $args['id'];
		if ( 'shop_coupon' !== get_post_type( $id ) ) throw new Exception( 'Coupon not found' );
		$c = new WC_Coupon( $id );
		$changed = $this->apply_coupon_fields( $c, $args );
		$c->save();
		return array( 'id' => $c->get_id(), 'updated' => $changed );
	}

	private function tool_delete_coupon( $args ) {
		$this->require_woo();
		$id = (int) $args['id'];
		if ( 'shop_coupon' !== get_post_type( $id ) ) throw new Exception( 'Coupon not found' );
		$res = wp_trash_post( $id );
		if ( ! $res ) throw new Exception( 'Failed to trash coupon' );
		return array( 'trashed' => true, 'id' => $id );
	}

	/* ---------------------------------------------------------------
	 * Admin settings page — shows connector URL + regenerate button
	 * ------------------------------------------------------------- */
	public function admin_menu() {
		add_options_page( 'MCP Connector', 'MCP Connector', 'manage_options', 'aff-mcp', array( $this, 'settings_page' ) );
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		if ( isset( $_POST['aff_mcp_regen'] ) && check_admin_referer( 'aff_mcp_regen' ) ) {
			update_option( self::OPTION_TOKEN, wp_generate_password( 40, false, false ) );
			echo '<div class="notice notice-success"><p>Token regenerated. Update the URL in Claude.</p></div>';
		}

		$token = get_option( self::OPTION_TOKEN );
		if ( ! $token ) { $this->on_activate(); $token = get_option( self::OPTION_TOKEN ); }
		$url = rest_url( 'aff-mcp/v1/mcp/' . $token );
		?>
		<div class="wrap">
			<h1>MCP Connector</h1>
			<p>Add this URL in <strong>Claude &rarr; Settings &rarr; Connectors &rarr; Add custom connector</strong>:</p>
			<p><code style="font-size:14px;padding:8px;display:inline-block;background:#f0f0f1;"><?php echo esc_html( $url ); ?></code></p>
			<p><em>Treat this URL like a password &mdash; anyone with it can read and edit site content.</em></p>
			<form method="post">
				<?php wp_nonce_field( 'aff_mcp_regen' ); ?>
				<p><button class="button" name="aff_mcp_regen" value="1">Regenerate token</button></p>
			</form>
		</div>
		<?php
	}
}

new AFF_MCP_Connector();
