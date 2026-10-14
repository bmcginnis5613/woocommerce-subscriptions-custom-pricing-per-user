<?php

define('ABSPATH', __DIR__);

function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
$test_annual_dues = 100;
function get_user_meta() { global $test_annual_dues; return $test_annual_dues; }
function wc_get_price_decimals() { return 2; }
function wcs_order_contains_subscription($order, $types) { return false; }

class WC_Order_Item_Product {
    private $subtotal = 0;
    private $total = 0;
    private $meta = array();

    public function __construct(private $product, private $product_id = 95067) {}
    public function get_product_id() { return $this->product_id; }
    public function get_variation_id() { return 0; }
    public function get_product() { return $this->product; }
    public function get_quantity() { return 1; }
    public function set_subtotal($subtotal) { $this->subtotal = $subtotal; }
    public function set_total($total) { $this->total = $total; }
    public function get_subtotal() { return $this->subtotal; }
    public function get_total() { return $this->total; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function save() {}
}

class WC_Subscription {
    public function __construct(private $item, private $coupon_item) {}
    public function get_items($type = 'line_item') {
        return 'coupon' === $type ? array(2 => $this->coupon_item) : array(1 => $this->item);
    }
}

class Test_Product {
    public function get_price($context = 'view') { return 25; }
}

class WC_Subscriptions_Product {
    public static function is_subscription($product) { return true; }
    public static function get_sign_up_fee($product) { return 250; }
    public static function get_trial_length($product) { return 0; }
}

class Test_Coupon_Item {
    private $discount = 0;

    public function __construct(private $code) {}
    public function get_code() { return $this->code; }
    public function set_discount($discount) { $this->discount = $discount; }
    public function get_discount() { return $this->discount; }
    public function set_discount_tax($tax) {}
    public function save() {}
}

class WC_Coupon {
    private $type;
    private $amount;

    public function __construct($code) {
        list($this->type, $this->amount) = explode(':', $code);
    }

    public function get_id() { return 1; }
    public function get_discount_type() { return $this->type; }
    public function get_amount() { return $this->amount; }
    public function is_valid_for_product() { return 'fixed_cart' !== $this->type; }
    public function is_valid_for_cart() { return 'fixed_cart' === $this->type; }
}

require dirname(__DIR__) . '/woocommerce_custom_pricing_per_user.php';

function assert_totals($type, $amount, $expected_subtotal, $expected_total, $subscription = false, $annual_dues = 100, $product_id = 95067) {
    global $test_annual_dues;
    $test_annual_dues = $annual_dues;
    $item = new WC_Order_Item_Product(new Test_Product(), $product_id);
    $coupon_item = new Test_Coupon_Item($type . ':' . $amount);
    $order = $subscription
        ? new WC_Subscription($item, $coupon_item)
        : new class($item, $coupon_item) {
            public function __construct(private $item, private $coupon_item) {}
            public function get_items($type = 'line_item') {
                return 'coupon' === $type ? array(2 => $this->coupon_item) : array(1 => $this->item);
            }
        };

    $plugin = new WC_Custom_Renewal_Pricing();
    $method = new ReflectionMethod($plugin, 'update_order_items_price');
    $method->invoke($plugin, $order, 123);

    if ((float) $item->get_subtotal() !== (float) $expected_subtotal) {
        throw new RuntimeException($type . ' subtotal failed');
    }

    if ((float) $item->get_total() !== (float) $expected_total) {
        throw new RuntimeException($type . ' total failed: ' . $item->get_total());
    }

    if ((float) $coupon_item->get_discount() !== (float) ($expected_subtotal - $expected_total)) {
        throw new RuntimeException($type . ' coupon row failed: ' . $coupon_item->get_discount());
    }
}

assert_totals('sign_up_fee', 200, 275, 75);
assert_totals('sign_up_fee_percent', 50, 275, 150);
assert_totals('fixed_cart', 200, 275, 75);
assert_totals('fixed_cart', 200, 2250, 2050, false, 2000, 94543);
assert_totals('percent', 10, 275, 250);
assert_totals('recurring_fee', 20, 275, 255);
assert_totals('recurring_percent', 20, 275, 270);
assert_totals('recurring_fee', 20, 25, 5, true);
assert_totals('recurring_percent', 20, 25, 20, true);
assert_totals('sign_up_fee', 200, 25, 25, true);

echo "Coupon pricing tests passed.\n";
