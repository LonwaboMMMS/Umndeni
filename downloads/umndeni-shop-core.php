<?php
/**
 * Plugin Name: Umndeni Shop Core
 * Description: WordPress customer accounts, Google sign-in, customer profiles and persistent shop orders for the Umndeni static shop.
 * Version: 1.0.0
 * Author: Reverb Daily Media
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

final class Umndeni_Shop_Core {
    const VERSION = '1.0.0';
    const REST_NS = 'umndeni/v1';
    const OPTION = 'umndn_shop_settings';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('init', array($this, 'google_oauth_routes'));
    }

    private function tables() {
        global $wpdb;
        return array(
            'customers' => $wpdb->prefix . 'umndn_customers',
            'orders' => $wpdb->prefix . 'umndn_orders',
            'items' => $wpdb->prefix . 'umndn_order_items',
        );
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::instance()->tables();
        $collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$t['customers']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            phone varchar(40) NOT NULL DEFAULT '',
            business varchar(190) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_id (user_id)
        ) $collate;");

        dbDelta("CREATE TABLE {$t['orders']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_number varchar(32) NOT NULL,
            user_id bigint(20) unsigned NULL,
            customer_name varchar(190) NOT NULL,
            customer_email varchar(190) NOT NULL,
            customer_phone varchar(40) NOT NULL DEFAULT '',
            business varchar(190) NOT NULL DEFAULT '',
            delivery_address text NULL,
            delivery_method varchar(80) NOT NULL DEFAULT '',
            subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
            delivery_fee decimal(12,2) NOT NULL DEFAULT 0.00,
            total decimal(12,2) NOT NULL DEFAULT 0.00,
            status varchar(30) NOT NULL DEFAULT 'pending',
            payment_status varchar(30) NOT NULL DEFAULT 'unpaid',
            payment_reference varchar(100) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_number (order_number),
            KEY user_id (user_id),
            KEY customer_email (customer_email),
            KEY status (status)
        ) $collate;");

        dbDelta("CREATE TABLE {$t['items']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            product_id varchar(100) NOT NULL,
            product_name varchar(190) NOT NULL,
            variant varchar(190) NOT NULL DEFAULT '',
            quantity int(10) unsigned NOT NULL DEFAULT 1,
            unit_price decimal(12,2) NOT NULL DEFAULT 0.00,
            line_total decimal(12,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY  (id),
            KEY order_id (order_id)
        ) $collate;");

        if (!get_option(self::OPTION)) {
            add_option(self::OPTION, array('google_client_id' => '', 'google_client_secret' => ''));
        }
    }

    public function register_routes() {
        register_rest_route(self::REST_NS, '/config', array(
            'methods' => 'GET', 'callback' => array($this, 'config'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route(self::REST_NS, '/register', array(
            'methods' => 'POST', 'callback' => array($this, 'register_customer'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route(self::REST_NS, '/login', array(
            'methods' => 'POST', 'callback' => array($this, 'login_customer'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route(self::REST_NS, '/logout', array(
            'methods' => 'POST', 'callback' => array($this, 'logout_customer'),
            'permission_callback' => 'is_user_logged_in'
        ));
        register_rest_route(self::REST_NS, '/me', array(
            'methods' => 'GET', 'callback' => array($this, 'current_customer'),
            'permission_callback' => 'is_user_logged_in'
        ));
        register_rest_route(self::REST_NS, '/account', array(
            'methods' => 'POST', 'callback' => array($this, 'save_account'),
            'permission_callback' => array($this, 'valid_logged_in_request')
        ));
        register_rest_route(self::REST_NS, '/orders', array(
            array('methods' => 'GET', 'callback' => array($this, 'customer_orders'), 'permission_callback' => 'is_user_logged_in'),
            array('methods' => 'POST', 'callback' => array($this, 'create_order'), 'permission_callback' => '__return_true')
        ));
        register_rest_route(self::REST_NS, '/orders/(?P<id>\d+)', array(
            'methods' => 'GET', 'callback' => array($this, 'get_order'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route(self::REST_NS, '/admin/orders/(?P<id>\d+)', array(
            'methods' => 'POST', 'callback' => array($this, 'admin_update_order'),
            'permission_callback' => function() { return current_user_can('manage_woocommerce') || current_user_can('manage_options'); }
        ));
    }

    public function valid_logged_in_request($request) {
        if (!is_user_logged_in()) return false;
        $nonce = $request->get_header('X-WP-Nonce');
        return $nonce && wp_verify_nonce($nonce, 'wp_rest');
    }

    public function config() {
        $settings = get_option(self::OPTION, array());
        return rest_ensure_response(array(
            'logged_in' => is_user_logged_in(),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'google_enabled' => !empty($settings['google_client_id']) && !empty($settings['google_client_secret']),
            'google_start_url' => home_url('/?umndn_google_start=1'),
            'currency' => 'ZAR'
        ));
    }

    private function customer_role() { return get_role('customer') ? 'customer' : 'subscriber'; }

    private function input_text($request, $key, $max = 190) {
        return sanitize_text_field((string) $request->get_param($key));
    }

    public function register_customer($request) {
        $name = $this->input_text($request, 'name');
        $email = sanitize_email((string) $request->get_param('email'));
        $phone = $this->input_text($request, 'phone', 40);
        $business = $this->input_text($request, 'business');
        $password = (string) $request->get_param('password');

        if (!$name || !is_email($email) || strlen($password) < 10) {
            return new WP_Error('invalid_registration', 'Enter your name, a valid email and a password of at least 10 characters.', array('status' => 400));
        }
        if (email_exists($email)) return new WP_Error('email_exists', 'An account already exists for this email. Please log in.', array('status' => 409));

        $username = sanitize_user(current(explode('@', $email)), true);
        if (!$username) $username = 'customer';
        $base = $username; $i = 1;
        while (username_exists($username)) { $username = $base . $i; $i++; }

        $user_id = wp_insert_user(array(
            'user_login' => $username, 'user_pass' => $password, 'user_email' => $email,
            'display_name' => $name, 'first_name' => $name, 'role' => $this->customer_role()
        ));
        if (is_wp_error($user_id)) return $user_id;

        $this->save_customer_row($user_id, $phone, $business);
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());

        return rest_ensure_response(array('success' => true, 'customer' => $this->customer_data($user_id)));
    }

    public function login_customer($request) {
        $email = sanitize_email((string) $request->get_param('email'));
        $password = (string) $request->get_param('password');
        $user = get_user_by('email', $email);
        if (!$user) return new WP_Error('login_failed', 'Email or password is incorrect.', array('status' => 401));
        $signed = wp_signon(array('user_login' => $user->user_login, 'user_password' => $password, 'remember' => true), is_ssl());
        if (is_wp_error($signed)) return new WP_Error('login_failed', 'Email or password is incorrect.', array('status' => 401));
        return rest_ensure_response(array('success' => true, 'customer' => $this->customer_data($signed->ID)));
    }

    public function logout_customer() {
        wp_logout();
        return rest_ensure_response(array('success' => true));
    }

    private function save_customer_row($user_id, $phone, $business) {
        global $wpdb;
        $t = $this->tables();
        $now = current_time('mysql');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['customers']} WHERE user_id = %d", $user_id));
        $data = array('phone' => $phone, 'business' => $business, 'updated_at' => $now);
        if ($exists) $wpdb->update($t['customers'], $data, array('user_id' => $user_id));
        else $wpdb->insert($t['customers'], $data + array('user_id' => $user_id, 'created_at' => $now));
    }

    private function customer_data($user_id) {
        global $wpdb;
        $user = get_userdata($user_id);
        if (!$user) return null;
        $t = $this->tables();
        $row = $wpdb->get_row($wpdb->prepare("SELECT phone, business FROM {$t['customers']} WHERE user_id = %d", $user_id), ARRAY_A);
        return array(
            'id' => (int) $user_id, 'name' => $user->display_name, 'email' => $user->user_email,
            'phone' => $row ? $row['phone'] : '', 'business' => $row ? $row['business'] : ''
        );
    }

    public function current_customer() {
        return rest_ensure_response(array('customer' => $this->customer_data(get_current_user_id())));
    }

    public function save_account($request) {
        $user_id = get_current_user_id();
        $name = $this->input_text($request, 'name');
        $email = sanitize_email((string) $request->get_param('email'));
        if (!$name || !is_email($email)) return new WP_Error('invalid_details', 'Please enter a name and valid email.', array('status' => 400));
        $existing = get_user_by('email', $email);
        if ($existing && (int) $existing->ID !== $user_id) return new WP_Error('email_in_use', 'That email is already used by another account.', array('status' => 409));
        $updated = wp_update_user(array('ID' => $user_id, 'display_name' => $name, 'first_name' => $name, 'user_email' => $email));
        if (is_wp_error($updated)) return $updated;
        $this->save_customer_row($user_id, $this->input_text($request, 'phone', 40), $this->input_text($request, 'business'));
        return rest_ensure_response(array('success' => true, 'customer' => $this->customer_data($user_id)));
    }

    private function catalog() {
        return array(
            'umndeni-petroleum-jelly-100g' => array('name' => 'Umndeni Petroleum Jelly', 'variant' => '80 units × 100g', 'price' => 1800.00),
            'umndeni-petroleum-jelly-250g' => array('name' => 'Umndeni Petroleum Jelly', 'variant' => '48 units × 250g', 'price' => 1920.00)
        );
    }

    public function create_order($request) {
        global $wpdb;
        $items = $request->get_param('items');
        if (!is_array($items) || empty($items) || count($items) > 30) return new WP_Error('invalid_items', 'Your order does not contain valid items.', array('status' => 400));
        $name = $this->input_text($request, 'name');
        $email = sanitize_email((string) $request->get_param('email'));
        if (!$name || !is_email($email)) return new WP_Error('customer_required', 'A name and valid email are required.', array('status' => 400));

        $catalog = $this->catalog();
        $clean_items = array(); $subtotal = 0;
        foreach ($items as $item) {
            $pid = isset($item['product_id']) ? sanitize_key($item['product_id']) : '';
            $qty = isset($item['quantity']) ? absint($item['quantity']) : 0;
            if (!isset($catalog[$pid]) || $qty < 1 || $qty > 100) return new WP_Error('invalid_item', 'One or more order items are invalid.', array('status' => 400));
            $p = $catalog[$pid]; $line = $p['price'] * $qty; $subtotal += $line;
            $clean_items[] = array('product_id' => $pid, 'product_name' => $p['name'], 'variant' => $p['variant'], 'quantity' => $qty, 'unit_price' => $p['price'], 'line_total' => $line);
        }
        $delivery_fee = max(0, (float) $request->get_param('delivery_fee'));
        if ($delivery_fee > 5000) return new WP_Error('invalid_delivery', 'Delivery fee is invalid.', array('status' => 400));
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $t = $this->tables(); $now = current_time('mysql');
        $number = 'UMN-' . gmdate('ymd') . '-' . strtoupper(wp_generate_password(6, false, false));
        $wpdb->insert($t['orders'], array(
            'order_number' => $number, 'user_id' => $user_id, 'customer_name' => $name, 'customer_email' => $email,
            'customer_phone' => $this->input_text($request, 'phone', 40), 'business' => $this->input_text($request, 'business'),
            'delivery_address' => sanitize_textarea_field((string) $request->get_param('delivery_address')),
            'delivery_method' => $this->input_text($request, 'delivery_method', 80),
            'subtotal' => $subtotal, 'delivery_fee' => $delivery_fee, 'total' => $subtotal + $delivery_fee,
            'status' => 'pending', 'payment_status' => 'unpaid', 'created_at' => $now, 'updated_at' => $now
        ));
        $order_id = (int) $wpdb->insert_id;
        if (!$order_id) return new WP_Error('order_failed', 'The order could not be saved. Please try again.', array('status' => 500));
        foreach ($clean_items as $item) $wpdb->insert($t['items'], $item + array('order_id' => $order_id));
        return new WP_REST_Response(array('success' => true, 'order_id' => $order_id, 'order_number' => $number, 'total' => $subtotal + $delivery_fee, 'payment_status' => 'unpaid'), 201);
    }

    private function order_data($order) {
        global $wpdb;
        $t = $this->tables();
        $items = $wpdb->get_results($wpdb->prepare("SELECT product_id, product_name, variant, quantity, unit_price, line_total FROM {$t['items']} WHERE order_id = %d", $order->id), ARRAY_A);
        return array(
            'id' => (int) $order->id, 'order_number' => $order->order_number,
            'name' => $order->customer_name, 'email' => $order->customer_email,
            'phone' => $order->customer_phone, 'business' => $order->business,
            'delivery_address' => $order->delivery_address, 'delivery_method' => $order->delivery_method,
            'subtotal' => (float) $order->subtotal, 'delivery_fee' => (float) $order->delivery_fee,
            'total' => (float) $order->total, 'status' => $order->status,
            'payment_status' => $order->payment_status, 'created_at' => $order->created_at, 'items' => $items
        );
    }

    public function customer_orders() {
        global $wpdb;
        $t = $this->tables();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['orders']} WHERE user_id = %d ORDER BY id DESC LIMIT 100", get_current_user_id()));
        return rest_ensure_response(array_map(array($this, 'order_data'), $rows));
    }

    public function get_order($request) {
        global $wpdb;
        $t = $this->tables();
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['orders']} WHERE id = %d", absint($request['id'])));
        if (!$order) return new WP_Error('not_found', 'Order not found.', array('status' => 404));
        if (!current_user_can('manage_options') && (!is_user_logged_in() || (int) $order->user_id !== get_current_user_id())) {
            return new WP_Error('forbidden', 'You are not allowed to view this order.', array('status' => 403));
        }
        return rest_ensure_response($this->order_data($order));
    }

    public function admin_update_order($request) {
        global $wpdb;
        $allowed_status = array('pending', 'processing', 'packed', 'shipped', 'completed', 'cancelled');
        $allowed_payment = array('unpaid', 'pending', 'paid', 'failed', 'refunded');
        $status = sanitize_key((string) $request->get_param('status'));
        $payment = sanitize_key((string) $request->get_param('payment_status'));
        if (!in_array($status, $allowed_status, true) || !in_array($payment, $allowed_payment, true)) {
            return new WP_Error('invalid_status', 'Invalid order or payment status.', array('status' => 400));
        }
        $t = $this->tables();
        $wpdb->update($t['orders'], array('status' => $status, 'payment_status' => $payment, 'updated_at' => current_time('mysql')), array('id' => absint($request['id'])));
        return rest_ensure_response(array('success' => true));
    }

    public function register_settings() {
        register_setting('umndn_shop_settings_group', self::OPTION, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input) {
        return array(
            'google_client_id' => isset($input['google_client_id']) ? sanitize_text_field($input['google_client_id']) : '',
            'google_client_secret' => isset($input['google_client_secret']) ? sanitize_text_field($input['google_client_secret']) : ''
        );
    }

    public function admin_menu() {
        add_options_page('Umndeni Shop', 'Umndeni Shop', 'manage_options', 'umndeni-shop', array($this, 'settings_page'));
        add_menu_page('Umndeni Orders', 'Umndeni Orders', 'manage_options', 'umndeni-orders', array($this, 'orders_page'), 'dashicons-store',  56);
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $settings = get_option(self::OPTION, array());
        $redirect = esc_url(home_url('/?umndn_google_callback=1'));
        echo '<div class="wrap"><h1>Umndeni Shop Settings</h1><form method="post" action="options.php">';
        settings_fields('umndn_shop_settings_group');
        echo '<table class="form-table"><tr><th>Google OAuth Client ID</th><td><input class="regular-text" name="' . esc_attr(self::OPTION) . '[google_client_id]" value="' . esc_attr($settings['google_client_id'] ?? '') . '"></td></tr>';
        echo '<tr><th>Google OAuth Client Secret</th><td><input type="password" autocomplete="new-password" class="regular-text" name="' . esc_attr(self::OPTION) . '[google_client_secret]" value="' . esc_attr($settings['google_client_secret'] ?? '') . '"></td></tr></table>';
        echo '<p><strong>Google authorised redirect URI:</strong><br><code>' . $redirect . '</code></p>';
        submit_button(); echo '</form><hr><p>REST API base: <code>' . esc_html(rest_url(self::REST_NS . '/')) . '</code></p></div>';
    }

    public function orders_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb; $t = $this->tables();
        $orders = $wpdb->get_results("SELECT * FROM {$t['orders']} ORDER BY id DESC LIMIT 100");
        echo '<div class="wrap"><h1>Umndeni Orders</h1><table class="widefat striped"><thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Order status</th><th>Payment</th><th>Date</th></tr></thead><tbody>';
        foreach ($orders as $o) {
            echo '<tr><td>' . esc_html($o->order_number) . '</td><td>' . esc_html($o->customer_name . ' — ' . $o->customer_email) . '</td><td>R' . esc_html(number_format((float) $o->total, 2)) . '</td><td>' . esc_html($o->status) . '</td><td>' . esc_html($o->payment_status) . '</td><td>' . esc_html($o->created_at) . '</td></tr>';
        }
        if (!$orders) echo '<tr><td colspan="6">No orders yet.</td></tr>';
        echo '</tbody></table><p>Order status updates are available through the secured REST endpoint; an editable admin screen will be added in a later version.</p></div>';
    }

    public function google_oauth_routes() {
        if (isset($_GET['umndn_google_start'])) $this->google_start();
        if (isset($_GET['umndn_google_callback'])) $this->google_callback();
    }

    private function google_start() {
        $s = get_option(self::OPTION, array());
        if (empty($s['google_client_id']) || empty($s['google_client_secret'])) wp_die('Google sign-in has not been configured yet.');
        $state = wp_generate_password(32, false, false);
        set_transient('umndn_google_state_' . $state, 1, 10 * MINUTE_IN_SECONDS);
        $redirect = home_url('/?umndn_google_callback=1');
        $url = add_query_arg(array(
            'client_id' => $s['google_client_id'], 'redirect_uri' => $redirect,
            'response_type' => 'code', 'scope' => 'openid email profile',
            'state' => $state, 'prompt' => 'select_account'
        ), 'https://accounts.google.com/o/oauth2/v2/auth');
        wp_redirect($url); exit;
    }

    private function google_callback() {
        if (isset($_GET['error'])) wp_die('Google sign-in was cancelled or denied.');
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!$state || !get_transient('umndn_google_state_' . $state)) wp_die('Google sign-in could not be verified. Please try again.');
        delete_transient('umndn_google_state_' . $state);
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $s = get_option(self::OPTION, array());
        $redirect = home_url('/?umndn_google_callback=1');
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'body' => array('code' => $code, 'client_id' => $s['google_client_id'], 'client_secret' => $s['google_client_secret'], 'redirect_uri' => $redirect, 'grant_type' => 'authorization_code')
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) wp_die('Google sign-in failed while verifying your account.');
        $token = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($token['id_token'])) wp_die('Google did not return a valid identity token.');
        $verify = wp_remote_get('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($token['id_token']), array('timeout' => 20));
        if (is_wp_error($verify) || wp_remote_retrieve_response_code($verify) !== 200) wp_die('Google identity verification failed.');
        $claims = json_decode(wp_remote_retrieve_body($verify), true);
        if (empty($claims['email']) || empty($claims['email_verified']) || $claims['aud'] !== $s['google_client_id']) wp_die('Google account could not be verified.');
        $email = sanitize_email($claims['email']);
        $user = get_user_by('email', $email);
        if (!$user) {
            $base = sanitize_user(current(explode('@', $email)), true); if (!$base) $base = 'googleuser';
            $username = $base; $i = 1; while (username_exists($username)) { $username = $base . $i; $i++; }
            $user_id = wp_insert_user(array('user_login' => $username, 'user_pass' => wp_generate_password(40, true, true), 'user_email' => $email, 'display_name' => sanitize_text_field($claims['name'] ?? $email), 'role' => $this->customer_role()));
            if (is_wp_error($user_id)) wp_die('Could not create your customer account.');
            $user = get_user_by('id', $user_id);
        }
        wp_set_current_user($user->ID); wp_set_auth_cookie($user->ID, true, is_ssl());
        wp_safe_redirect(home_url('/shop/account.html?google=success')); exit;
    }
}

Umndeni_Shop_Core::instance();
