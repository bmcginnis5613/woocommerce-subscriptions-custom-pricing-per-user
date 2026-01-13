<?php
/**
 * Plugin Name: WooCommerce Subscriptions - Custom Pricing Per User
 * Description: Allows administrators to set custom renewal prices for individual users' WooCommerce Subscriptions.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Requires Plugins: woocommerce, woocommerce-subscriptions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce and WooCommerce Subscriptions are active
function wc_crp_check_dependencies() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wc_crp_woocommerce_missing_notice');
        return false;
    }
    
    if (!class_exists('WC_Subscriptions')) {
        add_action('admin_notices', 'wc_crp_subscriptions_missing_notice');
        return false;
    }
    
    return true;
}

function wc_crp_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('WooCommerce Custom Renewal Pricing requires WooCommerce to be installed and active.', 'wc-custom-renewal-pricing'); ?></p>
    </div>
    <?php
}

function wc_crp_subscriptions_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('WooCommerce Custom Renewal Pricing requires WooCommerce Subscriptions to be installed and active.', 'wc-custom-renewal-pricing'); ?></p>
    </div>
    <?php
}

// Initialize plugin only if dependencies are met
add_action('plugins_loaded', 'wc_crp_init');

function wc_crp_init() {
    if (!wc_crp_check_dependencies()) {
        return;
    }
    
    // Load text domain for translations
    load_plugin_textdomain('wc-custom-renewal-pricing', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Initialize plugin features
    new WC_Custom_Renewal_Pricing();
}

/**
 * Main Plugin Class
 */
class WC_Custom_Renewal_Pricing {
    
    /**
     * Constructor
     */
    public function __construct() {
        // User profile fields
        add_action('show_user_profile', array($this, 'add_custom_renewal_price_field'));
        add_action('edit_user_profile', array($this, 'add_custom_renewal_price_field'));
        add_action('personal_options_update', array($this, 'save_custom_renewal_price_field'));
        add_action('edit_user_profile_update', array($this, 'save_custom_renewal_price_field'));
        
        // Apply custom pricing to initial purchase
        add_action('woocommerce_before_calculate_totals', array($this, 'apply_custom_price_to_cart'), 10, 1);
        add_action('woocommerce_checkout_create_subscription', array($this, 'apply_custom_price_to_new_subscription'), 10, 3);
        
        // Apply custom pricing to renewals
        add_action('woocommerce_scheduled_subscription_payment', array($this, 'apply_user_custom_renewal_price'), 5, 1);
        add_action('woocommerce_subscription_renewal_order_created', array($this, 'apply_custom_price_to_renewal_order'), 10, 2);
        
        // Admin display
        add_action('woocommerce_subscription_details_after_subscription_table', array($this, 'display_custom_renewal_price_info'), 10, 1);
        
        // User list columns
        add_filter('manage_users_columns', array($this, 'add_custom_price_column'));
        add_action('manage_users_custom_column', array($this, 'show_custom_price_column'), 10, 3);
    }
    
    /**
     * Add custom price field to user profile
     */
    public function add_custom_renewal_price_field($user) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $custom_price = get_user_meta($user->ID, 'custom_renewal_price', true);
        ?>
        <h3><?php _e('Subscription Settings', 'wc-custom-renewal-pricing'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="custom_renewal_price"><?php _e('Custom Renewal Price', 'wc-custom-renewal-pricing'); ?></label></th>
                <td>
                    <input type="number" 
                           name="custom_renewal_price" 
                           id="custom_renewal_price" 
                           value="<?php echo esc_attr($custom_price); ?>" 
                           step="0.01" 
                           min="0"
                           class="regular-text" />
                    <p class="description">
                        <?php _e('Set a custom renewal price for this user\'s subscriptions. Leave empty to use default pricing.', 'wc-custom-renewal-pricing'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Save custom price field
     */
    public function save_custom_renewal_price_field($user_id) {
        if (!current_user_can('manage_woocommerce')) {
            return false;
        }
        
        if (isset($_POST['custom_renewal_price'])) {
            $custom_price = sanitize_text_field($_POST['custom_renewal_price']);
            update_user_meta($user_id, 'custom_renewal_price', $custom_price);
        }
    }
    
    /**
     * Apply custom price before renewal payment is processed
     */
    public function apply_user_custom_renewal_price($subscription_id) {
        $subscription = wcs_get_subscription($subscription_id);
        
        if (!$subscription) {
            return;
        }
        
        $user_id = $subscription->get_user_id();
        $custom_price = get_user_meta($user_id, 'custom_renewal_price', true);
        
        if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
            foreach ($subscription->get_items() as $item_id => $item) {
                $item->set_subtotal($custom_price);
                $item->set_total($custom_price);
                $item->save();
            }
            
            $subscription->calculate_totals();
            $subscription->save();
            
            $subscription->add_order_note(
                sprintf(__('Custom renewal price applied: %s', 'wc-custom-renewal-pricing'), 
                wc_price($custom_price))
            );
        }
    }
    
    /**
     * Apply custom price to renewal orders when they're created
     */
    public function apply_custom_price_to_renewal_order($renewal_order, $subscription) {
        $user_id = $subscription->get_user_id();
        $custom_price = get_user_meta($user_id, 'custom_renewal_price', true);
        
        if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
            foreach ($renewal_order->get_items() as $item_id => $item) {
                $item->set_subtotal($custom_price);
                $item->set_total($custom_price);
                $item->save();
            }
            
            $renewal_order->calculate_totals();
            $renewal_order->save();
        }
    }

    /**
     * Apply custom price to cart items
     */
    public function apply_custom_price_to_cart($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }
        
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $custom_price = get_user_meta($user_id, 'custom_renewal_price', true);
        
        if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
            foreach ($cart->get_cart() as $cart_item) {
                // Only apply to subscription products
                if (class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::is_subscription($cart_item['product_id'])) {
                    $cart_item['data']->set_price($custom_price);
                }
            }
        }
    }

    /**
     * Apply custom price when subscription is created at checkout
     */
    public function apply_custom_price_to_new_subscription($subscription, $order, $recurring_cart) {
        $user_id = $subscription->get_user_id();
        $custom_price = get_user_meta($user_id, 'custom_renewal_price', true);
        
        if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
            foreach ($subscription->get_items() as $item_id => $item) {
                $item->set_subtotal($custom_price);
                $item->set_total($custom_price);
                $item->save();
            }
            
            $subscription->calculate_totals();
            $subscription->save();
        }
    }

    /**
     * Display custom price in admin subscription view
     */
    public function display_custom_renewal_price_info($subscription) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $user_id = $subscription->get_user_id();
        $custom_price = get_user_meta($user_id, 'custom_renewal_price', true);
        
        if ($custom_price && is_numeric($custom_price)) {
            echo '<div class="woocommerce-message" style="margin-top: 20px;">';
            echo '<strong>' . __('Custom Renewal Price:', 'wc-custom-renewal-pricing') . '</strong> ';
            echo wc_price($custom_price);
            echo ' <a href="' . admin_url('user-edit.php?user_id=' . $user_id) . '">' . __('Edit User Profile', 'wc-custom-renewal-pricing') . '</a>';
            echo '</div>';
        }
    }
    
    /**
     * Add custom price column to users list
     */
    public function add_custom_price_column($columns) {
        $columns['custom_renewal_price'] = __('Custom Renewal Price', 'wc-custom-renewal-pricing');
        return $columns;
    }
    
    /**
     * Show custom price in users list column
     */
    public function show_custom_price_column($value, $column_name, $user_id) {
        if ($column_name == 'custom_renewal_price') {
            $custom_price = get_user_meta($user_id, 'custom_renewal_price', true);
            if ($custom_price && is_numeric($custom_price)) {
                return wc_price($custom_price);
            }
            return '—';
        }
        return $value;
    }
}

/**
 * Activation hook
 */
register_activation_hook(__FILE__, 'wc_crp_activate');

function wc_crp_activate() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires WooCommerce to be installed and active.', 'wc-custom-renewal-pricing'));
    }
    
    if (!class_exists('WC_Subscriptions')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires WooCommerce Subscriptions to be installed and active.', 'wc-custom-renewal-pricing'));
    }
}

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, 'wc_crp_deactivate');

function wc_crp_deactivate() {
    // Cleanup if needed
}