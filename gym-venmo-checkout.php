<?php
/**
 * Plugin Name: Gym Venmo Checkout
 * Description: Mobile-friendly gym checkout with Venmo handoff, staff verification, discounts, and sales reporting.
 * Version: 2.2.0
 * Author: Community contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

final class CSW_Store {
    const VERSION = '2.2.0';
    const CAP = 'csw_manage_store';

    public static function products_table() { global $wpdb; return $wpdb->prefix . 'csw_products'; }
    public static function sales_table() { global $wpdb; return $wpdb->prefix . 'csw_sales'; }
    public static function items_table() { global $wpdb; return $wpdb->prefix . 'csw_sale_items'; }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::products_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            price_cents int unsigned NOT NULL,
            stock_qty int unsigned NOT NULL DEFAULT 0,
            sort_order int unsigned NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), UNIQUE KEY name (name)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::sales_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subtotal_cents int unsigned NOT NULL,
            tax_cents int unsigned NOT NULL DEFAULT 0,
            total_cents int unsigned NOT NULL,
            payment_method varchar(30) NOT NULL DEFAULT 'venmo',
            payment_status varchar(30) NOT NULL DEFAULT 'confirmed',
            venmo_username varchar(100) NOT NULL,
            buyer_name varchar(190) NOT NULL DEFAULT '',
            discount_code varchar(50) NOT NULL DEFAULT '',
            discount_percent decimal(5,2) NOT NULL DEFAULT 0,
            discount_cents int unsigned NOT NULL DEFAULT 0,
            confirmation_token char(64) NOT NULL DEFAULT '',
            customer_submitted_at datetime NULL,
            is_test tinyint(1) NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), KEY created_at (created_at), KEY payment_status (payment_status)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::items_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sale_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            product_name varchar(190) NOT NULL,
            unit_price_cents int unsigned NOT NULL,
            quantity int unsigned NOT NULL,
            line_total_cents int unsigned NOT NULL,
            PRIMARY KEY (id), KEY sale_id (sale_id)
        ) $charset;");

        $role = get_role('administrator');
        if ($role) $role->add_cap(self::CAP);
        add_role('csw_cashier', 'Gym Store Cashier', ['read' => true, self::CAP => true]);
        add_option('csw_venmo_username_2', '');
        add_option('csw_contact_email', get_option('admin_email'));
        add_option('csw_tax_rate', '0');
        add_option('csw_gym_name', 'Your Gym');
        add_option('csw_test_mode', '0');
        add_option('csw_coach_discount_code', '');

        self::sync_catalog();
        update_option('csw_store_version', self::VERSION);
    }

    public static function catalog() {
        $path = plugin_dir_path(__FILE__) . 'config/catalog.php';
        $configured = file_exists($path) ? include $path : [];
        $catalog = [];
        foreach (is_array($configured) ? $configured : [] as $product) {
            if (!is_array($product) || count($product) < 2) continue;
            $name = trim(sanitize_text_field($product[0]));
            $price_cents = absint($product[1]);
            if ($name !== '' && $price_cents > 0) $catalog[] = [$name, $price_cents];
        }
        usort($catalog, function($a, $b) { return strcasecmp($a[0], $b[0]); });
        return $catalog;
    }

    public static function sync_catalog() {
        global $wpdb;
        $table = self::products_table();
        $wpdb->query("UPDATE $table SET active=0");
        foreach (self::catalog() as $index => $product) {
            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE name=%s", $product[0]));
            if (!$existing_id && $product[0] === 'Jocko Molk 2lb') {
                $existing_id = $wpdb->get_var("SELECT id FROM $table WHERE name='Jocko Molk 2 lb'");
            }
            if ($existing_id) {
                $wpdb->update($table, ['name'=>$product[0], 'price_cents'=>$product[1], 'sort_order'=>$index, 'active'=>1], ['id'=>$existing_id], ['%s','%d','%d','%d'], ['%d']);
            } else {
                $wpdb->insert($table, ['name'=>$product[0], 'price_cents'=>$product[1], 'stock_qty'=>0, 'sort_order'=>$index, 'active'=>1], ['%s','%d','%d','%d','%d']);
            }
        }
    }

    public static function product_image_url($name) {
        $path = plugin_dir_path(__FILE__) . 'config/product-images.php';
        $images = file_exists($path) ? include $path : [];
        if (!is_array($images) || empty($images[$name])) return '';
        $file = basename((string)$images[$name]);
        $local_path = plugin_dir_path(__FILE__) . 'assets/products/' . $file;
        return is_file($local_path) ? plugin_dir_url(__FILE__) . 'assets/products/' . rawurlencode($file) : '';
    }

    public static function product_payload($product) {
        return [
            'id'=>(int)$product['id'],
            'name'=>$product['name'],
            'priceCents'=>(int)$product['price_cents'],
            'stockQty'=>(int)$product['stock_qty'],
            'sortOrder'=>(int)$product['sort_order'],
            'imageUrl'=>self::product_image_url($product['name']),
        ];
    }

    public static function maybe_upgrade() {
        if (get_option('csw_store_version') !== self::VERSION) self::activate();
    }

    public static function init() {
        self::maybe_upgrade();
        add_shortcode('gym_venmo_checkout', [__CLASS__, 'shortcode']);
        add_shortcode('crossfit_second_wave_store', [__CLASS__, 'shortcode']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
    }

    public static function allowed() { return current_user_can(self::CAP); }
    public static function permission() { return self::allowed(); }

    public static function routes() {
        register_rest_route('csw-store/v1', '/catalog', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'get_catalog'], 'permission_callback' => '__return_true'
        ]);
        register_rest_route('csw-store/v1', '/order', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'create_order'], 'permission_callback' => '__return_true'
        ]);
        register_rest_route('csw-store/v1', '/submit-payment', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'submit_payment'], 'permission_callback' => '__return_true'
        ]);
        register_rest_route('csw-store/v1', '/validate-discount', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'validate_discount'], 'permission_callback' => '__return_true'
        ]);
        register_rest_route('csw-store/v1', '/data', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'get_data'], 'permission_callback' => [__CLASS__, 'permission']
        ]);
        register_rest_route('csw-store/v1', '/sale', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'create_sale'], 'permission_callback' => [__CLASS__, 'permission']
        ]);
        register_rest_route('csw-store/v1', '/confirm', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'confirm_order'], 'permission_callback' => [__CLASS__, 'permission']
        ]);
        register_rest_route('csw-store/v1', '/edit-sale', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'edit_sale'], 'permission_callback' => [__CLASS__, 'permission']
        ]);
        register_rest_route('csw-store/v1', '/delete-sale', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'delete_sale'], 'permission_callback' => [__CLASS__, 'permission']
        ]);
        register_rest_route('csw-store/v1', '/refund-sale', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'refund_sale'], 'permission_callback' => [__CLASS__, 'permission']
        ]);
    }

    public static function get_catalog() {
        global $wpdb;
        $products = $wpdb->get_results("SELECT id, name, price_cents, stock_qty, sort_order FROM " . self::products_table() . " WHERE active = 1 ORDER BY sort_order ASC", ARRAY_A);
        return rest_ensure_response([
            'products' => array_map([__CLASS__, 'product_payload'], $products),
            'sales' => [],
            'settings' => self::public_settings()
        ]);
    }

    public static function get_data() {
        global $wpdb;
        $products = $wpdb->get_results("SELECT id, name, price_cents, stock_qty, sort_order FROM " . self::products_table() . " WHERE active = 1 ORDER BY sort_order ASC", ARRAY_A);
        $sales = $wpdb->get_results("SELECT s.id, s.subtotal_cents, s.discount_code, s.discount_percent, s.discount_cents, s.total_cents, s.payment_status, s.venmo_username, s.buyer_name, s.customer_submitted_at, s.is_test, s.created_at, COALESCE(SUM(i.quantity),0) item_count FROM " . self::sales_table() . " s LEFT JOIN " . self::items_table() . " i ON i.sale_id=s.id GROUP BY s.id ORDER BY s.created_at DESC LIMIT 50", ARRAY_A);
        $timezone = wp_timezone();
        $month_start = new DateTimeImmutable('first day of this month 00:00:00', $timezone);
        $next_month = $month_start->modify('+1 month');
        $month_start_sql = $month_start->format('Y-m-d H:i:s');
        $next_month_sql = $next_month->format('Y-m-d H:i:s');
        $month_summary = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) paid_sales, COALESCE(SUM(total_cents),0) total_cents FROM " . self::sales_table() . " WHERE payment_status='confirmed' AND is_test=0 AND created_at >= %s AND created_at < %s",
            $month_start_sql,
            $next_month_sql
        ), ARRAY_A);
        $best_sellers = $wpdb->get_results($wpdb->prepare(
            "SELECT i.product_name, SUM(i.quantity) quantity FROM " . self::items_table() . " i INNER JOIN " . self::sales_table() . " s ON s.id=i.sale_id WHERE s.payment_status='confirmed' AND s.is_test=0 AND s.created_at >= %s AND s.created_at < %s GROUP BY i.product_name ORDER BY quantity DESC, i.product_name ASC LIMIT 5",
            $month_start_sql,
            $next_month_sql
        ), ARRAY_A);
        $sale_items = [];
        if ($sales) {
            $sale_ids = implode(',', array_map('absint', array_column($sales, 'id')));
            $rows = $wpdb->get_results("SELECT id,sale_id,product_name,unit_price_cents,quantity,line_total_cents FROM " . self::items_table() . " WHERE sale_id IN ($sale_ids) ORDER BY id ASC", ARRAY_A);
            foreach ($rows as $row) {
                $sale_items[(int)$row['sale_id']][] = ['id'=>(int)$row['id'],'name'=>$row['product_name'],'unitPriceCents'=>(int)$row['unit_price_cents'],'quantity'=>(int)$row['quantity'],'lineTotalCents'=>(int)$row['line_total_cents']];
            }
        }
        return rest_ensure_response([
            'products' => array_map([__CLASS__, 'product_payload'], $products),
            'sales' => array_map(function($s) use ($sale_items) { $id=(int)$s['id']; return ['id'=>$id,'subtotalCents'=>(int)$s['subtotal_cents'],'discountCode'=>$s['discount_code'],'discountPercent'=>(float)$s['discount_percent'],'discountCents'=>(int)$s['discount_cents'],'totalCents'=>(int)$s['total_cents'],'paymentStatus'=>$s['payment_status'],'venmoUsername'=>$s['venmo_username'],'buyerName'=>$s['buyer_name'],'createdAt'=>$s['created_at'],'customerSubmittedAt'=>$s['customer_submitted_at'],'isTest'=>(bool)$s['is_test'],'itemCount'=>(int)$s['item_count'],'items'=>$sale_items[$id] ?? []]; }, $sales),
            'monthlyStats' => [
                'label'=>$month_start->format('F Y'),
                'totalCents'=>(int)($month_summary['total_cents'] ?? 0),
                'paidSales'=>(int)($month_summary['paid_sales'] ?? 0),
                'bestSellers'=>array_map(function($row) { return ['name'=>$row['product_name'],'quantity'=>(int)$row['quantity']]; }, $best_sellers),
            ],
            'settings' => self::public_settings()
        ]);
    }

    public static function venmo_usernames() {
        return array_values(array_unique(array_filter([
            get_option('csw_venmo_username_2', ''),
        ])));
    }

    public static function public_settings() {
        return [
            'gymName'=>get_option('csw_gym_name', 'Your Gym'),
            'venmoUsernames'=>self::venmo_usernames(),
            'contactEmail'=>sanitize_email(get_option('csw_contact_email', get_option('admin_email'))),
            'taxRate'=>(float)get_option('csw_tax_rate', 0),
            'testMode'=>(bool)get_option('csw_test_mode', false),
            'coachDiscountPercent'=>30,
        ];
    }

    public static function selected_venmo(WP_REST_Request $request) {
        $selected = sanitize_text_field($request->get_param('venmoUsername'));
        $usernames = self::venmo_usernames();
        if (!$usernames) return '';
        return in_array($selected, $usernames, true) ? $selected : $usernames[0];
    }

    public static function normalize_discount_code($value) {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', sanitize_text_field((string)$value)));
    }

    public static function valid_discount_code($value) {
        $submitted = self::normalize_discount_code($value);
        $configured = self::normalize_discount_code(get_option('csw_coach_discount_code', ''));
        return $submitted !== '' && $configured !== '' && hash_equals($configured, $submitted);
    }

    public static function validate_discount(WP_REST_Request $request) {
        $valid = self::valid_discount_code($request->get_param('code'));
        return rest_ensure_response(['valid'=>$valid,'discountPercent'=>$valid ? 30 : 0,'message'=>$valid ? 'Coach discount applied.' : 'That coach discount code is not valid.']);
    }

    public static function create_sale(WP_REST_Request $request) {
        global $wpdb;
        $items = $request->get_param('items');
        if (!is_array($items) || !$items) return new WP_Error('empty_cart', 'Add at least one item.', ['status'=>400]);
        $requested = [];
        foreach ($items as $item) {
            $id = absint($item['productId'] ?? 0); $qty = absint($item['quantity'] ?? 0);
            if (!$id || !$qty) return new WP_Error('bad_item', 'The cart contains an invalid item.', ['status'=>400]);
            $requested[$id] = ($requested[$id] ?? 0) + $qty;
        }
        $ids = implode(',', array_map('absint', array_keys($requested)));
        $products = $wpdb->get_results("SELECT id,name,price_cents,stock_qty FROM " . self::products_table() . " WHERE active=1 AND id IN ($ids)", ARRAY_A);
        if (count($products) !== count($requested)) return new WP_Error('missing_item', 'One or more items are unavailable.', ['status'=>409]);
        $subtotal = 0;
        foreach ($products as $product) {
            $qty = $requested[(int)$product['id']];
            $subtotal += (int)$product['price_cents'] * $qty;
        }
        $tax = (int)round($subtotal * ((float)get_option('csw_tax_rate', 0) / 100));
        $total = $subtotal + $tax;
        $venmo_username = self::selected_venmo($request);
        if ($venmo_username === '') return new WP_Error('venmo_not_configured', 'The Venmo account has not been configured.', ['status'=>503]);
        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->insert(self::sales_table(), ['subtotal_cents'=>$subtotal,'tax_cents'=>$tax,'total_cents'=>$total,'payment_method'=>'venmo','payment_status'=>'confirmed','venmo_username'=>$venmo_username,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')], ['%d','%d','%d','%s','%s','%s','%d','%s']);
        $sale_id = (int)$wpdb->insert_id;
        foreach ($products as $product) {
            $qty = $requested[(int)$product['id']];
            $wpdb->insert(self::items_table(), ['sale_id'=>$sale_id,'product_id'=>(int)$product['id'],'product_name'=>$product['name'],'unit_price_cents'=>(int)$product['price_cents'],'quantity'=>$qty,'line_total_cents'=>(int)$product['price_cents']*$qty], ['%d','%d','%s','%d','%d','%d']);
        }
        if (!$ok || $wpdb->last_error) { $wpdb->query('ROLLBACK'); return new WP_Error('sale_failed', 'The sale could not be recorded.', ['status'=>500]); }
        $wpdb->query('COMMIT');
        return rest_ensure_response(['sale'=>['id'=>$sale_id,'subtotalCents'=>$subtotal,'taxCents'=>$tax,'totalCents'=>$total]]);
    }

    public static function create_order(WP_REST_Request $request) {
        global $wpdb;
        $items = $request->get_param('items');
        if (!is_array($items) || !$items) return new WP_Error('empty_cart', 'Add at least one item.', ['status'=>400]);
        $buyer_name = trim(sanitize_text_field($request->get_param('buyerName')));
        if (strlen($buyer_name) < 2) return new WP_Error('buyer_name_required', 'Please enter the buyer name.', ['status'=>400]);
        $buyer_name = substr($buyer_name, 0, 190);
        $requested = [];
        foreach ($items as $item) {
            $id = absint($item['productId'] ?? 0); $qty = absint($item['quantity'] ?? 0);
            if (!$id || !$qty) return new WP_Error('bad_item', 'The cart contains an invalid item.', ['status'=>400]);
            $requested[$id] = ($requested[$id] ?? 0) + $qty;
        }
        $ids = implode(',', array_map('absint', array_keys($requested)));
        $products = $wpdb->get_results("SELECT id,name,price_cents,stock_qty FROM " . self::products_table() . " WHERE active=1 AND id IN ($ids)", ARRAY_A);
        if (count($products) !== count($requested)) return new WP_Error('missing_item', 'One or more items are unavailable.', ['status'=>409]);
        $subtotal = 0;
        foreach ($products as $product) {
            $qty = $requested[(int)$product['id']];
            $subtotal += (int)$product['price_cents'] * $qty;
        }
        $submitted_code = self::normalize_discount_code($request->get_param('discountCode'));
        if ($submitted_code !== '' && !self::valid_discount_code($submitted_code)) return new WP_Error('invalid_discount', 'That coach discount code is not valid.', ['status'=>400]);
        $discount_percent = $submitted_code !== '' ? 30 : 0;
        $discount = (int)round($subtotal * ($discount_percent / 100));
        $discounted_subtotal = max(0, $subtotal - $discount);
        $tax = (int)round($discounted_subtotal * ((float)get_option('csw_tax_rate', 0) / 100));
        $total = $discounted_subtotal + $tax;
        $is_test = (bool)get_option('csw_test_mode', false);
        $venmo_username = self::selected_venmo($request);
        if (!$is_test && $venmo_username === '') return new WP_Error('venmo_not_configured', 'Checkout is temporarily unavailable because the Venmo account has not been configured.', ['status'=>503]);
        $wpdb->query('START TRANSACTION');
        $confirmation_token = wp_generate_password(32, false, false);
        $ok = $wpdb->insert(self::sales_table(), ['subtotal_cents'=>$subtotal,'discount_code'=>$submitted_code,'discount_percent'=>$discount_percent,'discount_cents'=>$discount,'tax_cents'=>$tax,'total_cents'=>$total,'payment_method'=>'venmo','payment_status'=>'pending','venmo_username'=>$venmo_username,'buyer_name'=>$buyer_name,'confirmation_token'=>hash('sha256', $confirmation_token),'is_test'=>$is_test ? 1 : 0,'created_by'=>0,'created_at'=>current_time('mysql')], ['%d','%s','%f','%d','%d','%d','%s','%s','%s','%s','%s','%d','%d','%s']);
        $sale_id = (int)$wpdb->insert_id;
        foreach ($products as $product) {
            $qty = $requested[(int)$product['id']];
            if (!$wpdb->insert(self::items_table(), ['sale_id'=>$sale_id,'product_id'=>(int)$product['id'],'product_name'=>$product['name'],'unit_price_cents'=>(int)$product['price_cents'],'quantity'=>$qty,'line_total_cents'=>(int)$product['price_cents']*$qty], ['%d','%d','%s','%d','%d','%d'])) $ok = false;
        }
        if (!$ok || $wpdb->last_error) { $wpdb->query('ROLLBACK'); return new WP_Error('order_failed', 'The order could not be submitted.', ['status'=>500]); }
        $wpdb->query('COMMIT');
        return rest_ensure_response(['order'=>['id'=>$sale_id,'buyerName'=>$buyer_name,'subtotalCents'=>$subtotal,'discountPercent'=>$discount_percent,'discountCents'=>$discount,'taxCents'=>$tax,'totalCents'=>$total,'confirmationToken'=>$confirmation_token,'testMode'=>$is_test]]);
    }

    public static function submit_payment(WP_REST_Request $request) {
        global $wpdb;
        $sale_id = absint($request->get_param('sale_id'));
        $token = sanitize_text_field($request->get_param('confirmation_token'));
        if (!$sale_id || !$token) return new WP_Error('bad_confirmation', 'This payment confirmation link is invalid.', ['status'=>400]);
        $sale = $wpdb->get_row($wpdb->prepare("SELECT id,payment_status,confirmation_token,is_test FROM " . self::sales_table() . " WHERE id=%d", $sale_id), ARRAY_A);
        if (!$sale || !$sale['confirmation_token'] || !hash_equals($sale['confirmation_token'], hash('sha256', $token))) {
            return new WP_Error('bad_confirmation', 'This payment confirmation link is invalid.', ['status'=>403]);
        }
        if ($sale['payment_status'] === 'submitted' || $sale['payment_status'] === 'confirmed') {
            return rest_ensure_response(['ok'=>true,'saleId'=>$sale_id,'paymentStatus'=>$sale['payment_status'],'isTest'=>(bool)$sale['is_test']]);
        }
        if ($sale['payment_status'] !== 'pending') return new WP_Error('bad_order', 'This order cannot be submitted.', ['status'=>409]);
        $changed = $wpdb->update(self::sales_table(), ['payment_status'=>'submitted','customer_submitted_at'=>current_time('mysql')], ['id'=>$sale_id,'payment_status'=>'pending'], ['%s','%s'], ['%d','%s']);
        if ($changed !== 1 || $wpdb->last_error) return new WP_Error('submit_failed', 'The payment confirmation could not be recorded.', ['status'=>409]);
        return rest_ensure_response(['ok'=>true,'saleId'=>$sale_id,'paymentStatus'=>'submitted','isTest'=>(bool)$sale['is_test']]);
    }

    public static function confirm_order(WP_REST_Request $request) {
        global $wpdb;
        $sale_id = absint($request->get_param('sale_id'));
        $sale = $wpdb->get_row($wpdb->prepare("SELECT id,payment_status FROM " . self::sales_table() . " WHERE id=%d", $sale_id), ARRAY_A);
        if (!$sale || !in_array($sale['payment_status'], ['pending','submitted'], true)) return new WP_Error('bad_order', 'This order is not awaiting confirmation.', ['status'=>409]);
        $ok = $wpdb->query($wpdb->prepare("UPDATE " . self::sales_table() . " SET payment_status='confirmed', created_by=%d WHERE id=%d AND payment_status IN ('pending','submitted')", get_current_user_id(), $sale_id)) === 1;
        if (!$ok || $wpdb->last_error) return new WP_Error('confirm_failed', 'The order could not be confirmed.', ['status'=>409]);
        return rest_ensure_response(['ok'=>true,'saleId'=>$sale_id]);
    }

    public static function edit_sale(WP_REST_Request $request) {
        global $wpdb;
        $sale_id = absint($request->get_param('sale_id'));
        $updates = $request->get_param('items');
        if (!$sale_id || !is_array($updates) || !$updates) return new WP_Error('bad_edit', 'The transaction edit is invalid.', ['status'=>400]);
        $sale = $wpdb->get_row($wpdb->prepare("SELECT id,payment_status,discount_percent FROM " . self::sales_table() . " WHERE id=%d", $sale_id), ARRAY_A);
        if (!$sale) return new WP_Error('missing_sale', 'Transaction not found.', ['status'=>404]);
        if ($sale['payment_status'] === 'refunded') return new WP_Error('refunded_sale', 'A refunded transaction cannot be edited.', ['status'=>409]);
        $existing = $wpdb->get_results($wpdb->prepare("SELECT id,unit_price_cents FROM " . self::items_table() . " WHERE sale_id=%d", $sale_id), ARRAY_A);
        $prices = [];
        foreach ($existing as $item) $prices[(int)$item['id']] = (int)$item['unit_price_cents'];
        if (count($updates) !== count($prices)) return new WP_Error('bad_edit', 'Every transaction item must be included.', ['status'=>400]);
        $subtotal = 0;
        $seen = [];
        foreach ($updates as $item) {
            $item_id = absint($item['id'] ?? 0); $quantity = absint($item['quantity'] ?? 0);
            if (!$item_id || !$quantity || !isset($prices[$item_id]) || isset($seen[$item_id])) return new WP_Error('bad_item', 'Each quantity must be at least 1.', ['status'=>400]);
            $seen[$item_id] = true;
            $subtotal += $prices[$item_id] * $quantity;
        }
        if (count($seen) !== count($prices)) return new WP_Error('bad_edit', 'Every transaction item must be included once.', ['status'=>400]);
        $discount_percent = max(0, min(100, (float)$sale['discount_percent']));
        $discount = (int)round($subtotal * ($discount_percent / 100));
        $discounted_subtotal = max(0, $subtotal - $discount);
        $tax = (int)round($discounted_subtotal * ((float)get_option('csw_tax_rate', 0) / 100));
        $total = $discounted_subtotal + $tax;
        $wpdb->query('START TRANSACTION'); $ok = true;
        foreach ($updates as $item) {
            $item_id = absint($item['id']); $quantity = absint($item['quantity']);
            if ($wpdb->update(self::items_table(), ['quantity'=>$quantity,'line_total_cents'=>$prices[$item_id]*$quantity], ['id'=>$item_id,'sale_id'=>$sale_id], ['%d','%d'], ['%d','%d']) === false) $ok = false;
        }
        if ($wpdb->update(self::sales_table(), ['subtotal_cents'=>$subtotal,'discount_cents'=>$discount,'tax_cents'=>$tax,'total_cents'=>$total], ['id'=>$sale_id], ['%d','%d','%d','%d'], ['%d']) === false) $ok = false;
        if (!$ok || $wpdb->last_error) { $wpdb->query('ROLLBACK'); return new WP_Error('edit_failed', 'The transaction could not be updated.', ['status'=>500]); }
        $wpdb->query('COMMIT');
        return rest_ensure_response(['ok'=>true,'saleId'=>$sale_id,'totalCents'=>$total]);
    }

    public static function delete_sale(WP_REST_Request $request) {
        global $wpdb;
        $sale_id = absint($request->get_param('sale_id'));
        if (!$sale_id) return new WP_Error('bad_sale', 'Transaction not found.', ['status'=>404]);
        $wpdb->query('START TRANSACTION');
        $items_ok = $wpdb->delete(self::items_table(), ['sale_id'=>$sale_id], ['%d']) !== false;
        $sale_ok = $wpdb->delete(self::sales_table(), ['id'=>$sale_id], ['%d']) === 1;
        if (!$items_ok || !$sale_ok || $wpdb->last_error) { $wpdb->query('ROLLBACK'); return new WP_Error('delete_failed', 'The transaction could not be deleted.', ['status'=>500]); }
        $wpdb->query('COMMIT');
        return rest_ensure_response(['ok'=>true,'saleId'=>$sale_id]);
    }

    public static function refund_sale(WP_REST_Request $request) {
        global $wpdb;
        $sale_id = absint($request->get_param('sale_id'));
        $changed = $wpdb->update(self::sales_table(), ['payment_status'=>'refunded','created_by'=>get_current_user_id()], ['id'=>$sale_id,'payment_status'=>'confirmed'], ['%s','%d'], ['%d','%s']);
        if ($changed !== 1) return new WP_Error('refund_failed', 'Only a paid transaction can be marked refunded.', ['status'=>409]);
        return rest_ensure_response(['ok'=>true,'saleId'=>$sale_id]);
    }

    public static function shortcode() {
        wp_enqueue_style('csw-store', plugin_dir_url(__FILE__) . 'assets/app.css', [], self::VERSION);
        wp_enqueue_style('csw-store-product-images', plugin_dir_url(__FILE__) . 'assets/product-images.css', ['csw-store'], self::VERSION);
        wp_enqueue_script('csw-store', plugin_dir_url(__FILE__) . 'assets/app.js', [], self::VERSION, true);
        wp_localize_script('csw-store', 'CSW_STORE', ['api'=>esc_url_raw(rest_url('csw-store/v1')),'nonce'=>wp_create_nonce('wp_rest'),'canManage'=>self::allowed()]);
        return '<div id="csw-store-app"><div class="csw-loading">Loading gym store…</div></div>';
    }

    public static function admin_menu() {
        add_options_page('Gym Venmo Checkout', 'Gym Store', 'manage_options', 'csw-store-settings', [__CLASS__, 'settings_page']);
    }
    public static function settings() {
        register_setting('csw_store', 'csw_gym_name', ['sanitize_callback'=>'sanitize_text_field']);
        register_setting('csw_store', 'csw_venmo_username_2', ['sanitize_callback'=>function($v){ $clean=ltrim(sanitize_text_field($v), '@'); return $clean === '' ? '' : '@' . $clean; }]);
        register_setting('csw_store', 'csw_contact_email', ['sanitize_callback'=>'sanitize_email']);
        register_setting('csw_store', 'csw_tax_rate', ['sanitize_callback'=>function($v){ return max(0, min(25, (float)$v)); }]);
        register_setting('csw_store', 'csw_test_mode', ['sanitize_callback'=>function($v){ return $v ? '1' : '0'; }]);
        register_setting('csw_store', 'csw_coach_discount_code', ['sanitize_callback'=>[__CLASS__, 'normalize_discount_code']]);
    }
    public static function settings_page() { ?>
        <div class="wrap"><h1>Gym Venmo Checkout</h1><form method="post" action="options.php"><?php settings_fields('csw_store'); ?>
        <table class="form-table"><tr><th><label for="csw_gym_name">Gym name</label></th><td><input class="regular-text" id="csw_gym_name" name="csw_gym_name" value="<?php echo esc_attr(get_option('csw_gym_name', 'Your Gym')); ?>"></td></tr>
        <tr><th><label for="csw_venmo_username_2">Venmo username</label></th><td><input class="regular-text" id="csw_venmo_username_2" name="csw_venmo_username_2" placeholder="@your-account" value="<?php echo esc_attr(get_option('csw_venmo_username_2', '')); ?>"><p class="description">Required before accepting production payments.</p></td></tr>
        <tr><th><label for="csw_contact_email">Support email</label></th><td><input type="email" class="regular-text" id="csw_contact_email" name="csw_contact_email" value="<?php echo esc_attr(get_option('csw_contact_email', get_option('admin_email'))); ?>"></td></tr>
        <tr><th><label for="csw_tax_rate">Sales tax percentage</label></th><td><input type="number" min="0" max="25" step="0.01" id="csw_tax_rate" name="csw_tax_rate" value="<?php echo esc_attr(get_option('csw_tax_rate', 0)); ?>"></td></tr>
        <tr><th><label for="csw_coach_discount_code">Coach discount code</label></th><td><input class="regular-text" id="csw_coach_discount_code" name="csw_coach_discount_code" value="<?php echo esc_attr(get_option('csw_coach_discount_code', '')); ?>"><p class="description">Optional. This code applies a 30% coach discount. Share it only with coaches.</p></td></tr>
        <tr><th>Testing</th><td><input type="hidden" name="csw_test_mode" value="0"><label><input type="checkbox" name="csw_test_mode" value="1" <?php checked(get_option('csw_test_mode', '0'), '1'); ?>> Enable Test Mode</label><p class="description">Test Mode never opens Venmo and clearly labels all test transactions.</p></td></tr></table><?php submit_button(); ?></form>
        <p>Add <code>[gym_venmo_checkout]</code> to a WordPress page. Assign staff the <strong>Gym Store Cashier</strong> role.</p></div>
    <?php }
}

register_activation_hook(__FILE__, ['CSW_Store', 'activate']);
add_action('init', ['CSW_Store', 'init']);
