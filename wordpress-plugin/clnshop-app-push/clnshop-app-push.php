<?php
/**
 * Plugin Name: Clnshop App Push
 * Description: ارسال نوتیفیکیشن (Firebase FCM) به اپ اندروید فروشگاه کلکسیون: وضعیت سفارش، تخفیف، محصول جدید، موجود شدن و سبد رهاشده. با پنل مدیریت.
 * Version: 1.0.1
 * Author: Clnshop
 * Text Domain: clnshop-app-push
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Clnshop_App_Push {

	const OPT        = 'clnapp_settings';
	const SA_OPT     = 'clnapp_service_account';
	const DB_VERSION = '1';
	const CRON_HOOK  = 'clnapp_abandoned_cart_check';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_clnapp_send', array( $this, 'handle_send' ) );
		add_action( 'admin_post_clnapp_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_clnapp_test', array( $this, 'handle_test' ) );

		// رویدادها.
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status' ), 10, 4 );
		add_action( 'transition_post_status', array( $this, 'on_post_transition' ), 10, 3 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_cart_updated', array( $this, 'on_cart_updated' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_created' ), 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'check_abandoned_carts' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
	}

	/* ------------------------------------------------------------------ */
	/* نصب و دیتابیس                                                       */
	/* ------------------------------------------------------------------ */

	private function devices_table() {
		global $wpdb;
		return $wpdb->prefix . 'clnapp_devices';
	}

	private function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'clnapp_log';
	}

	public function activate() {
		$this->create_tables();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'clnapp_30min', self::CRON_HOOK );
		}
		if ( false === get_option( self::OPT, false ) ) {
			add_option( self::OPT, $this->default_settings(), '', 'no' );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function maybe_upgrade() {
		if ( get_option( 'clnapp_db_version' ) !== self::DB_VERSION ) {
			$this->create_tables();
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'clnapp_30min', self::CRON_HOOK );
		}
	}

	private function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$d       = $this->devices_table();
		$l       = $this->log_table();

		dbDelta( "CREATE TABLE $d (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token VARCHAR(255) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			platform VARCHAR(20) NOT NULL DEFAULT 'android',
			app_version VARCHAR(30) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY user_id (user_id)
		) $charset;" );

		dbDelta( "CREATE TABLE $l (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			kind VARCHAR(30) NOT NULL DEFAULT '',
			audience VARCHAR(120) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			body TEXT NULL,
			sent INT NOT NULL DEFAULT 0,
			failed INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) $charset;" );

		update_option( 'clnapp_db_version', self::DB_VERSION, false );
	}

	public function cron_schedules( $schedules ) {
		$schedules['clnapp_30min'] = array(
			'interval' => 1800,
			'display'  => 'هر ۳۰ دقیقه',
		);
		return $schedules;
	}

	/* ------------------------------------------------------------------ */
	/* تنظیمات                                                              */
	/* ------------------------------------------------------------------ */

	private function default_settings() {
		return array(
			'topic_all'          => 'all',
			'ev_order'           => 1,
			'ev_product'         => 1,
			'ev_stock'           => 0,
			'ev_coupon'          => 1,
			'ev_cart'            => 1,
			'cart_delay_hours'   => 2,
			'order_statuses'     => array(
				'processing' => array(
					'on'    => 1,
					'title' => 'سفارش شما ثبت شد',
					'body'  => 'سفارش شماره {order_number} در حال پردازش است.',
				),
				'on-hold'    => array(
					'on'    => 1,
					'title' => 'سفارش در انتظار بررسی',
					'body'  => 'سفارش شماره {order_number} در انتظار بررسی پرداخت است.',
				),
				'completed'  => array(
					'on'    => 1,
					'title' => 'سفارش شما ارسال شد',
					'body'  => 'سفارش شماره {order_number} تکمیل شد. از خرید شما سپاسگزاریم.',
				),
				'cancelled'  => array(
					'on'    => 1,
					'title' => 'سفارش لغو شد',
					'body'  => 'سفارش شماره {order_number} لغو شد.',
				),
				'refunded'   => array(
					'on'    => 1,
					'title' => 'سفارش مرجوع شد',
					'body'  => 'مبلغ سفارش شماره {order_number} بازگردانده شد.',
				),
				'failed'     => array(
					'on'    => 1,
					'title' => 'پرداخت ناموفق',
					'body'  => 'پرداخت سفارش شماره {order_number} ناموفق بود. می‌توانید دوباره تلاش کنید.',
				),
			),
			'tpl_product_title'  => 'محصول جدید: {name}',
			'tpl_product_body'   => 'همین حالا در فروشگاه کلکسیون ببینید.',
			'tpl_stock_title'    => 'موجود شد: {name}',
			'tpl_stock_body'     => 'محصول مورد علاقه شما دوباره موجود شد.',
			'tpl_coupon_title'   => 'کد تخفیف جدید',
			'tpl_coupon_body'    => 'با کد {code} تخفیف بگیرید.',
			'tpl_cart_title'     => 'سبد خرید شما منتظر است',
			'tpl_cart_body'      => 'خرید خود را کامل کنید، کالاهای سبد شما ممکن است تمام شوند.',
		);
	}

	private function settings() {
		$saved = get_option( self::OPT, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$def = $this->default_settings();
		$out = array_merge( $def, $saved );
		// ادغام عمیق وضعیت‌های سفارش.
		$out['order_statuses'] = array_merge(
			$def['order_statuses'],
			isset( $saved['order_statuses'] ) && is_array( $saved['order_statuses'] ) ? $saved['order_statuses'] : array()
		);
		return $out;
	}

	private function order_status_labels() {
		return array(
			'processing' => 'در حال انجام',
			'on-hold'    => 'در انتظار بررسی',
			'completed'  => 'تکمیل شده',
			'cancelled'  => 'لغو شده',
			'refunded'   => 'مرجوع شده',
			'failed'     => 'ناموفق',
		);
	}

	/* ------------------------------------------------------------------ */
	/* REST: ثبت توکن دستگاه                                              */
	/* ------------------------------------------------------------------ */

	public function register_routes() {
		register_rest_route(
			'clnshop-app/v1',
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_register' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'       => array( 'required' => true, 'type' => 'string' ),
					'app_version' => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			'clnshop-app/v1',
			'/unregister',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_unregister' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	private function valid_token( $token ) {
		return is_string( $token ) && strlen( $token ) >= 20 && strlen( $token ) <= 255 && preg_match( '/^[A-Za-z0-9_\-:\.]+$/', $token );
	}

	private function is_app_request() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		return false !== strpos( $ua, 'ClnShopApp/' );
	}

	/**
	 * کاربر واردشده را مستقیم از کوکی ورود می‌خواند (بدون nonce که در صفحه‌های کش‌شده قدیمی می‌شود).
	 * فقط همراه هدر مخصوص اپ استفاده می‌شود؛ مرورگر در درخواست‌های بین‌سایتی نمی‌تواند آن هدر را بفرستد.
	 */
	private function cookie_user_id() {
		$uid = wp_validate_auth_cookie( '', 'logged_in' );
		return $uid ? (int) $uid : 0;
	}

	public function rest_register( WP_REST_Request $req ) {
		global $wpdb;
		$token = trim( (string) $req->get_param( 'token' ) );
		if ( ! $this->valid_token( $token ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$ver     = substr( sanitize_text_field( (string) $req->get_param( 'app_version' ) ), 0, 30 );
		$from_app = ( '1' === (string) $req->get_header( 'X-ClnShop-App' ) ) && $this->is_app_request();
		$user_id  = $from_app ? $this->cookie_user_id() : 0;
		$now     = current_time( 'mysql', true );
		$t       = $this->devices_table();

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM $t WHERE token = %s", $token ) );
		if ( $existing ) {
			// فقط درخواست خود اپ (با هدر مخصوص) وضعیت ورود را تعیین می‌کند؛ ثبت ناشناس بومی ارتباط قبلی را حفظ می‌کند.
			$new_user = $from_app ? $user_id : (int) $existing->user_id;
			$wpdb->update(
				$t,
				array(
					'user_id'     => $new_user,
					'app_version' => $ver,
					'last_seen'   => $now,
				),
				array( 'id' => $existing->id )
			);
		} else {
			$wpdb->insert(
				$t,
				array(
					'token'       => $token,
					'user_id'     => $user_id,
					'platform'    => 'android',
					'app_version' => $ver,
					'created_at'  => $now,
					'last_seen'   => $now,
				)
			);
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public function rest_unregister( WP_REST_Request $req ) {
		global $wpdb;
		$token = trim( (string) $req->get_param( 'token' ) );
		if ( $this->valid_token( $token ) ) {
			// فقط ارتباط کاربر قطع می‌شود تا نوتیفیکیشن عمومی همچنان برسد.
			$wpdb->update( $this->devices_table(), array( 'user_id' => 0 ), array( 'token' => $token ) );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ------------------------------------------------------------------ */
	/* FCM HTTP v1                                                          */
	/* ------------------------------------------------------------------ */

	private function service_account() {
		$json = get_option( self::SA_OPT, '' );
		if ( ! $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) || empty( $data['project_id'] ) ) {
			return null;
		}
		return $data;
	}

	private function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private function access_token() {
		$cached = get_transient( 'clnapp_fcm_token' );
		if ( $cached ) {
			return $cached;
		}
		$sa = $this->service_account();
		if ( ! $sa ) {
			return new WP_Error( 'clnapp_no_sa', 'فایل Service Account فایربیس هنوز ذخیره نشده است.' );
		}
		$now    = time();
		$header = $this->b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claim  = $this->b64url(
			wp_json_encode(
				array(
					'iss'   => $sa['client_email'],
					'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
					'aud'   => 'https://oauth2.googleapis.com/token',
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$sig = '';
		if ( ! openssl_sign( $header . '.' . $claim, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'clnapp_sign', 'امضای JWT با کلید خصوصی ناموفق بود.' );
		}
		$jwt = $header . '.' . $claim . '.' . $this->b64url( $sig );

		$res = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'clnapp_token', 'دریافت توکن دسترسی گوگل ناموفق بود.' );
		}
		set_transient( 'clnapp_fcm_token', $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
		return $body['access_token'];
	}

	/**
	 * ارسال یک پیام داده‌ای به یک target (token یا topic).
	 *
	 * @return true|string|WP_Error  true موفق، 'dead' توکن نامعتبر، WP_Error خطا.
	 */
	private function fcm_send( $target_type, $target, $title, $body, $url = '', $image = '' ) {
		$sa    = $this->service_account();
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$data = array(
			'title' => (string) $title,
			'body'  => (string) $body,
			'url'   => (string) $url,
			'image' => (string) $image,
		);
		$message = array(
			'data'    => $data,
			'android' => array( 'priority' => 'HIGH' ),
		);
		$message[ $target_type ] = $target;

		$res = wp_remote_post(
			'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $sa['project_id'] ) . '/messages:send',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode( array( 'message' => $message ), JSON_UNESCAPED_UNICODE ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) {
			return true;
		}
		$resp = json_decode( wp_remote_retrieve_body( $res ), true );
		$st   = isset( $resp['error']['status'] ) ? $resp['error']['status'] : '';
		if ( 404 === $code || 'NOT_FOUND' === $st || 'UNREGISTERED' === $st ) {
			return 'dead';
		}
		$msg = isset( $resp['error']['message'] ) ? $resp['error']['message'] : ( 'HTTP ' . $code );
		return new WP_Error( 'clnapp_fcm', $msg );
	}

	private function tokens_for_users( array $user_ids ) {
		global $wpdb;
		$user_ids = array_filter( array_map( 'intval', $user_ids ) );
		if ( ! $user_ids ) {
			return array();
		}
		$t            = $this->devices_table();
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col( $wpdb->prepare( "SELECT token FROM $t WHERE user_id IN ($placeholders)", $user_ids ) );
	}

	/**
	 * ارسال به لیستی از توکن‌ها. خروجی: [sent, failed, last_error]
	 */
	private function send_to_tokens( array $tokens, $title, $body, $url = '', $image = '' ) {
		global $wpdb;
		$sent   = 0;
		$failed = 0;
		$err    = '';
		foreach ( $tokens as $tk ) {
			$r = $this->fcm_send( 'token', $tk, $title, $body, $url, $image );
			if ( true === $r ) {
				++$sent;
			} elseif ( 'dead' === $r ) {
				++$failed;
				$wpdb->delete( $this->devices_table(), array( 'token' => $tk ) );
			} else {
				++$failed;
				if ( is_wp_error( $r ) ) {
					$err = $r->get_error_message();
				}
			}
		}
		return array( $sent, $failed, $err );
	}

	private function send_to_topic( $title, $body, $url = '', $image = '' ) {
		$s     = $this->settings();
		$topic = preg_replace( '/[^a-zA-Z0-9\-_.~%]/', '', (string) $s['topic_all'] );
		if ( '' === $topic ) {
			$topic = 'all';
		}
		$r = $this->fcm_send( 'topic', $topic, $title, $body, $url, $image );
		if ( true === $r ) {
			return array( 1, 0, '' );
		}
		return array( 0, 1, is_wp_error( $r ) ? $r->get_error_message() : 'خطای ناشناخته' );
	}

	private function log( $kind, $audience, $title, $body, $sent, $failed ) {
		global $wpdb;
		$wpdb->insert(
			$this->log_table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'kind'       => substr( $kind, 0, 30 ),
				'audience'   => substr( $audience, 0, 120 ),
				'title'      => substr( $title, 0, 255 ),
				'body'       => $body,
				'sent'       => (int) $sent,
				'failed'     => (int) $failed,
			)
		);
		// نگه داشتن حداکثر ۵۰۰ رکورد.
		$wpdb->query( 'DELETE FROM ' . $this->log_table() . ' WHERE id < (SELECT m FROM (SELECT MAX(id) - 500 AS m FROM ' . $this->log_table() . ') x)' );
	}

	private function fill( $tpl, array $vars ) {
		$search  = array();
		$replace = array();
		foreach ( $vars as $k => $v ) {
			$search[]  = '{' . $k . '}';
			$replace[] = (string) $v;
		}
		return str_replace( $search, $replace, (string) $tpl );
	}

	/* ------------------------------------------------------------------ */
	/* رویدادها                                                             */
	/* ------------------------------------------------------------------ */

	public function on_order_status( $order_id, $from, $to, $order ) {
		$s = $this->settings();
		if ( empty( $s['ev_order'] ) || ! $this->service_account() ) {
			return;
		}
		if ( empty( $s['order_statuses'][ $to ]['on'] ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order || ! $order->get_customer_id() ) {
			return;
		}
		$tokens = $this->tokens_for_users( array( $order->get_customer_id() ) );
		if ( ! $tokens ) {
			return;
		}
		$cfg   = $s['order_statuses'][ $to ];
		$vars  = array(
			'order_number' => $order->get_order_number(),
			'total'        => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'name'         => $order->get_billing_first_name(),
		);
		$title = $this->fill( $cfg['title'], $vars );
		$body  = $this->fill( $cfg['body'], $vars );
		$url   = $order->get_view_order_url();

		list( $sent, $failed ) = $this->send_to_tokens( $tokens, $title, $body, $url );
		$this->log( 'order', 'سفارش ' . $order->get_order_number(), $title, $body, $sent, $failed );
	}

	public function on_post_transition( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || ! $post instanceof WP_Post ) {
			return;
		}
		$s = $this->settings();
		if ( ! $this->service_account() ) {
			return;
		}

		if ( 'product' === $post->post_type && ! empty( $s['ev_product'] ) ) {
			// جلوگیری از ارسال تکراری برای یک محصول.
			if ( get_post_meta( $post->ID, '_clnapp_notified_new', true ) ) {
				return;
			}
			update_post_meta( $post->ID, '_clnapp_notified_new', 1 );
			$vars  = array( 'name' => get_the_title( $post ) );
			$title = $this->fill( $s['tpl_product_title'], $vars );
			$body  = $this->fill( $s['tpl_product_body'], $vars );
			$img   = get_the_post_thumbnail_url( $post, 'large' );
			list( $sent, $failed ) = $this->send_to_topic( $title, $body, get_permalink( $post ), $img ? $img : '' );
			$this->log( 'product', 'همه', $title, $body, $sent, $failed );
		}

		if ( 'shop_coupon' === $post->post_type && ! empty( $s['ev_coupon'] ) ) {
			if ( get_post_meta( $post->ID, '_clnapp_notified_new', true ) ) {
				return;
			}
			update_post_meta( $post->ID, '_clnapp_notified_new', 1 );
			$vars  = array( 'code' => $post->post_title );
			$title = $this->fill( $s['tpl_coupon_title'], $vars );
			$body  = $this->fill( $s['tpl_coupon_body'], $vars );
			list( $sent, $failed ) = $this->send_to_topic( $title, $body, home_url( '/' ) );
			$this->log( 'coupon', 'همه', $title, $body, $sent, $failed );
		}
	}

	public function on_stock_status( $product_id, $status, $product = null ) {
		if ( 'instock' !== $status ) {
			return;
		}
		$s = $this->settings();
		if ( empty( $s['ev_stock'] ) || ! $this->service_account() ) {
			return;
		}
		$post = get_post( $product_id );
		if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		// حداکثر یک بار در ۱۲ ساعت برای هر محصول.
		$key = 'clnapp_stock_' . $product_id;
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 12 * HOUR_IN_SECONDS );

		$vars  = array( 'name' => get_the_title( $post ) );
		$title = $this->fill( $s['tpl_stock_title'], $vars );
		$body  = $this->fill( $s['tpl_stock_body'], $vars );
		$img   = get_the_post_thumbnail_url( $post, 'large' );
		list( $sent, $failed ) = $this->send_to_topic( $title, $body, get_permalink( $post ), $img ? $img : '' );
		$this->log( 'stock', 'همه', $title, $body, $sent, $failed );
	}

	/* --- سبد رهاشده --- */

	public function on_cart_updated() {
		if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$uid = get_current_user_id();
		if ( WC()->cart->is_empty() ) {
			delete_user_meta( $uid, '_clnapp_cart_ts' );
			return;
		}
		update_user_meta( $uid, '_clnapp_cart_ts', time() );
		delete_user_meta( $uid, '_clnapp_cart_notified' );
	}

	public function on_order_created( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && $order->get_customer_id() ) {
			delete_user_meta( $order->get_customer_id(), '_clnapp_cart_ts' );
		}
	}

	public function check_abandoned_carts() {
		$s = $this->settings();
		if ( empty( $s['ev_cart'] ) || ! $this->service_account() ) {
			return;
		}
		$hours  = max( 1, (int) $s['cart_delay_hours'] );
		$limit  = time() - $hours * HOUR_IN_SECONDS;
		$oldest = time() - 48 * HOUR_IN_SECONDS;

		$users = get_users(
			array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => '_clnapp_cart_ts',
						'value'   => array( $oldest, $limit ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_clnapp_cart_notified',
						'compare' => 'NOT EXISTS',
					),
				),
				'fields'     => 'ID',
				'number'     => 100,
			)
		);
		foreach ( $users as $uid ) {
			$tokens = $this->tokens_for_users( array( $uid ) );
			if ( ! $tokens ) {
				// هنوز دستگاهی به این کاربر وصل نیست؛ علامت نمی‌زنیم تا بعد از اتصال دستگاه (تا ۴۸ ساعت) ارسال شود.
				continue;
			}
			update_user_meta( $uid, '_clnapp_cart_notified', time() );
			$title = $s['tpl_cart_title'];
			$body  = $s['tpl_cart_body'];
			list( $sent, $failed ) = $this->send_to_tokens( $tokens, $title, $body, function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) );
			$this->log( 'cart', 'کاربر ' . $uid, $title, $body, $sent, $failed );
		}
	}

	/* ------------------------------------------------------------------ */
	/* پنل مدیریت                                                          */
	/* ------------------------------------------------------------------ */

	public function admin_menu() {
		add_menu_page(
			'اپ کلکسیون',
			'اپ کلکسیون',
			'manage_woocommerce',
			'clnapp',
			array( $this, 'render_admin' ),
			'dashicons-smartphone',
			58
		);
	}

	private function notice_from_query() {
		if ( empty( $_GET['clnapp_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$type = ( isset( $_GET['clnapp_ok'] ) && '1' === $_GET['clnapp_ok'] ) ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification
		$msg  = sanitize_text_field( wp_unslash( $_GET['clnapp_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $msg ) );
	}

	private function redirect_back( $ok, $msg ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'clnapp',
					'clnapp_ok'  => $ok ? '1' : '0',
					'clnapp_msg' => rawurlencode( $msg ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		global $wpdb;
		$s       = $this->settings();
		$sa      = $this->service_account();
		$devices = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->devices_table() );
		$logs    = $wpdb->get_results( 'SELECT * FROM ' . $this->log_table() . ' ORDER BY id DESC LIMIT 30' );
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'send'; // phpcs:ignore WordPress.Security.NonceVerification
		$base    = admin_url( 'admin.php?page=clnapp' );
		$labels  = $this->order_status_labels();
		?>
		<div class="wrap" dir="rtl">
			<h1>اپ فروشگاه کلکسیون</h1>
			<?php $this->notice_from_query(); ?>
			<p>
				دستگاه‌های ثبت‌شده: <strong><?php echo esc_html( number_format_i18n( $devices ) ); ?></strong>
				&nbsp;|&nbsp;
				اتصال فایربیس:
				<?php echo $sa ? '<span style="color:#1a7f37">برقرار (' . esc_html( $sa['project_id'] ) . ')</span>' : '<span style="color:#b32d2e">تنظیم نشده</span>'; ?>
			</p>

			<h2 class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'send' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'send', $base ) ); ?>">ارسال نوتیفیکیشن</a>
				<a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base ) ); ?>">تنظیمات و رویدادها</a>
				<a class="nav-tab <?php echo 'log' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'log', $base ) ); ?>">تاریخچه</a>
			</h2>

			<?php if ( 'send' === $tab ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;margin-top:16px">
					<input type="hidden" name="action" value="clnapp_send">
					<?php wp_nonce_field( 'clnapp_send' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="t">عنوان</label></th><td><input id="t" name="title" type="text" class="regular-text" required maxlength="120"></td></tr>
						<tr><th><label for="b">متن پیام</label></th><td><textarea id="b" name="body" rows="3" class="large-text" required maxlength="500"></textarea></td></tr>
						<tr><th><label for="u">لینک مقصد</label></th><td><input id="u" name="url" type="url" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" dir="ltr"><p class="description">خالی = صفحه اصلی فروشگاه. می‌توانید آدرس محصول یا کوپن را بگذارید.</p></td></tr>
						<tr><th><label for="i">لینک تصویر</label></th><td><input id="i" name="image" type="url" class="regular-text" dir="ltr"><p class="description">اختیاری. آدرس تصویر (حتماً https).</p></td></tr>
						<tr><th>مخاطبان</th><td>
							<label><input type="radio" name="audience" value="all" checked> همه کاربران اپ</label><br>
							<label><input type="radio" name="audience" value="customers"> فقط کاربران واردشده (دارای حساب)</label>
						</td></tr>
					</table>
					<?php submit_button( 'ارسال' ); ?>
				</form>
				<hr>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="clnapp_test">
					<?php wp_nonce_field( 'clnapp_test' ); ?>
					<?php submit_button( 'تست اتصال فایربیس (دریافت توکن)', 'secondary', 'submit', false ); ?>
				</form>
			<?php elseif ( 'settings' === $tab ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:820px;margin-top:16px">
					<input type="hidden" name="action" value="clnapp_save">
					<?php wp_nonce_field( 'clnapp_save' ); ?>

					<h2>اتصال فایربیس</h2>
					<p>محتوای فایل JSON مربوط به Service Account (Firebase Console ← Project settings ← Service accounts ← Generate new private key) را اینجا بچسبانید.
					<?php echo $sa ? ' <em>(یک کلید ذخیره شده است؛ برای تغییر، کلید جدید را بچسبانید. خالی بگذارید تا تغییر نکند.)</em>' : ''; ?></p>
					<textarea name="service_account" rows="6" class="large-text code" dir="ltr" placeholder='{"type":"service_account", ...}'></textarea>
					<p><label>نام Topic عمومی: <input type="text" name="topic_all" value="<?php echo esc_attr( $s['topic_all'] ); ?>" dir="ltr" class="regular-text"></label><br>
					<span class="description">باید با مقدار داخل اپ یکی باشد (پیش‌فرض all).</span></p>

					<h2>رویدادهای خودکار</h2>
					<p><label><input type="checkbox" name="ev_order" value="1" <?php checked( $s['ev_order'] ); ?>> تغییر وضعیت سفارش</label></p>
					<table class="widefat striped" style="max-width:800px">
						<thead><tr><th>وضعیت</th><th>فعال</th><th>عنوان</th><th>متن</th></tr></thead>
						<tbody>
						<?php foreach ( $labels as $key => $label ) : ?>
							<?php $row = $s['order_statuses'][ $key ]; ?>
							<tr>
								<td><?php echo esc_html( $label ); ?></td>
								<td><input type="checkbox" name="os[<?php echo esc_attr( $key ); ?>][on]" value="1" <?php checked( ! empty( $row['on'] ) ); ?>></td>
								<td><input type="text" class="regular-text" name="os[<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $row['title'] ); ?>"></td>
								<td><input type="text" class="large-text" name="os[<?php echo esc_attr( $key ); ?>][body]" value="<?php echo esc_attr( $row['body'] ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">متغیرها: {order_number} {total} {name}</p>

					<p><label><input type="checkbox" name="ev_product" value="1" <?php checked( $s['ev_product'] ); ?>> انتشار محصول جدید</label><br>
					عنوان: <input type="text" name="tpl_product_title" value="<?php echo esc_attr( $s['tpl_product_title'] ); ?>" class="regular-text"> متن: <input type="text" name="tpl_product_body" value="<?php echo esc_attr( $s['tpl_product_body'] ); ?>" class="regular-text"><br><span class="description">{name}</span></p>

					<p><label><input type="checkbox" name="ev_stock" value="1" <?php checked( $s['ev_stock'] ); ?>> موجود شدن محصول</label><br>
					عنوان: <input type="text" name="tpl_stock_title" value="<?php echo esc_attr( $s['tpl_stock_title'] ); ?>" class="regular-text"> متن: <input type="text" name="tpl_stock_body" value="<?php echo esc_attr( $s['tpl_stock_body'] ); ?>" class="regular-text"></p>

					<p><label><input type="checkbox" name="ev_coupon" value="1" <?php checked( $s['ev_coupon'] ); ?>> ساخت کوپن جدید</label><br>
					عنوان: <input type="text" name="tpl_coupon_title" value="<?php echo esc_attr( $s['tpl_coupon_title'] ); ?>" class="regular-text"> متن: <input type="text" name="tpl_coupon_body" value="<?php echo esc_attr( $s['tpl_coupon_body'] ); ?>" class="regular-text"><br><span class="description">{code}</span></p>

					<p><label><input type="checkbox" name="ev_cart" value="1" <?php checked( $s['ev_cart'] ); ?>> یادآوری سبد خرید رهاشده</label>
					بعد از <input type="number" min="1" max="72" name="cart_delay_hours" value="<?php echo esc_attr( (int) $s['cart_delay_hours'] ); ?>" style="width:70px"> ساعت<br>
					عنوان: <input type="text" name="tpl_cart_title" value="<?php echo esc_attr( $s['tpl_cart_title'] ); ?>" class="regular-text"> متن: <input type="text" name="tpl_cart_body" value="<?php echo esc_attr( $s['tpl_cart_body'] ); ?>" class="regular-text"></p>

					<?php submit_button( 'ذخیره تنظیمات' ); ?>
				</form>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:16px">
					<thead><tr><th>زمان (UTC)</th><th>نوع</th><th>مخاطب</th><th>عنوان</th><th>موفق</th><th>ناموفق</th></tr></thead>
					<tbody>
					<?php if ( ! $logs ) : ?>
						<tr><td colspan="6">هنوز نوتیفیکیشنی ارسال نشده است.</td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><?php echo esc_html( $row->kind ); ?></td>
								<td><?php echo esc_html( $row->audience ); ?></td>
								<td><?php echo esc_html( $row->title ); ?></td>
								<td><?php echo esc_html( $row->sent ); ?></td>
								<td><?php echo esc_html( $row->failed ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'clnapp_save' );

		$cur = $this->settings();
		$new = $cur;

		foreach ( array( 'ev_order', 'ev_product', 'ev_stock', 'ev_coupon', 'ev_cart' ) as $k ) {
			$new[ $k ] = empty( $_POST[ $k ] ) ? 0 : 1;
		}
		$new['cart_delay_hours'] = isset( $_POST['cart_delay_hours'] ) ? min( 72, max( 1, (int) $_POST['cart_delay_hours'] ) ) : 2;
		$new['topic_all']        = isset( $_POST['topic_all'] ) ? preg_replace( '/[^a-zA-Z0-9\-_.~%]/', '', wp_unslash( $_POST['topic_all'] ) ) : 'all';
		if ( '' === $new['topic_all'] ) {
			$new['topic_all'] = 'all';
		}
		foreach ( array( 'tpl_product_title', 'tpl_product_body', 'tpl_stock_title', 'tpl_stock_body', 'tpl_coupon_title', 'tpl_coupon_body', 'tpl_cart_title', 'tpl_cart_body' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$new[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		$os = isset( $_POST['os'] ) && is_array( $_POST['os'] ) ? wp_unslash( $_POST['os'] ) : array();
		foreach ( array_keys( $this->order_status_labels() ) as $key ) {
			$row                        = isset( $os[ $key ] ) && is_array( $os[ $key ] ) ? $os[ $key ] : array();
			$new['order_statuses'][ $key ] = array(
				'on'    => empty( $row['on'] ) ? 0 : 1,
				'title' => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : $cur['order_statuses'][ $key ]['title'],
				'body'  => isset( $row['body'] ) ? sanitize_text_field( $row['body'] ) : $cur['order_statuses'][ $key ]['body'],
			);
		}
		update_option( self::OPT, $new, false );

		$msg = 'تنظیمات ذخیره شد.';
		$ok  = true;
		$raw = isset( $_POST['service_account'] ) ? trim( (string) wp_unslash( $_POST['service_account'] ) ) : '';
		if ( '' !== $raw ) {
			$data = json_decode( $raw, true );
			if ( is_array( $data ) && ! empty( $data['client_email'] ) && ! empty( $data['private_key'] ) && ! empty( $data['project_id'] ) ) {
				update_option( self::SA_OPT, wp_json_encode( $data ), false );
				delete_transient( 'clnapp_fcm_token' );
				$msg .= ' کلید فایربیس ذخیره شد.';
			} else {
				$ok  = false;
				$msg = 'تنظیمات ذخیره شد ولی JSON فایربیس معتبر نبود (client_email, private_key, project_id لازم است).';
			}
		}
		$this->redirect_back( $ok, $msg );
	}

	public function handle_test() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'clnapp_test' );
		delete_transient( 'clnapp_fcm_token' );
		$t = $this->access_token();
		if ( is_wp_error( $t ) ) {
			$this->redirect_back( false, 'اتصال ناموفق: ' . $t->get_error_message() );
		}
		$this->redirect_back( true, 'اتصال به فایربیس موفق بود.' );
	}

	public function handle_send() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'clnapp_send' );

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$body  = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$image = isset( $_POST['image'] ) ? esc_url_raw( wp_unslash( $_POST['image'] ) ) : '';
		$aud   = isset( $_POST['audience'] ) ? sanitize_key( wp_unslash( $_POST['audience'] ) ) : 'all';

		if ( '' === $title || '' === $body ) {
			$this->redirect_back( false, 'عنوان و متن الزامی است.' );
		}
		if ( ! $this->service_account() ) {
			$this->redirect_back( false, 'ابتدا کلید فایربیس را در تنظیمات ذخیره کنید.' );
		}
		// فقط لینک‌های همین دامنه یا تصویر https.
		if ( $url && wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$this->redirect_back( false, 'لینک مقصد باید مربوط به همین سایت باشد.' );
		}
		if ( $image && 'https' !== wp_parse_url( $image, PHP_URL_SCHEME ) ) {
			$image = '';
		}

		if ( 'customers' === $aud ) {
			global $wpdb;
			$tokens = $wpdb->get_col( 'SELECT token FROM ' . $this->devices_table() . ' WHERE user_id > 0' );
			list( $sent, $failed, $err ) = $this->send_to_tokens( $tokens, $title, $body, $url, $image );
			$label = 'کاربران واردشده';
		} else {
			list( $sent, $failed, $err ) = $this->send_to_topic( $title, $body, $url, $image );
			$label = 'همه';
		}
		$this->log( 'manual', $label, $title, $body, $sent, $failed );

		if ( $failed && ! $sent ) {
			$this->redirect_back( false, 'ارسال ناموفق: ' . $err );
		}
		$this->redirect_back( true, 'ارسال انجام شد. موفق: ' . $sent . '، ناموفق: ' . $failed );
	}
}

Clnshop_App_Push::instance();
