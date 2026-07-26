<?php
/**
 * Plugin Name: AFF MCP Connector
 * Description: Exposes a Model Context Protocol (MCP) endpoint so Claude can connect to this site as a custom connector. Supports WordPress content + WooCommerce store tools.
 * Version: 1.0.0
 * Author: Waris Salawudeen
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AFF_MCP_Connector {

	const OPTION_TOKEN   = 'aff_mcp_token';
	const PROTOCOL       = '2025-03-26';
	const SERVER_NAME    = 'alpha-fresh-food-mcp';
	const SERVER_VERSION = '1.0.0';

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
