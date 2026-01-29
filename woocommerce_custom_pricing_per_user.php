<?php
/**
 * Plugin Name: WooCommerce Subscriptions - Custom Pricing Per User
 * Description: Allows administrators to set custom renewal prices for individual users' WooCommerce Subscriptions.
 * Version: 1.2.4
 * Author: FirstTracks Marketing
 * Author URI: https://firsttracksmarketing.com
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
     * Product ID to pricing field mapping
     */
    private $product_pricing_map = array(
        94543 => 'annual_membership_dues',
        94544 => 'bi_annual_membership_dues',
        // Can associate quarterly membership price to products here or others:
        // 12345 => 'quarterly_membership_dues',
    );
    

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
        add_filter('woocommerce_cart_item_price', array($this, 'display_custom_cart_price'), 10, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'display_custom_cart_price'), 10, 3);
        add_filter('woocommerce_get_price_html', array($this, 'custom_price_html'), 10, 2);
        add_action('woocommerce_checkout_create_subscription', array($this, 'apply_custom_price_to_new_subscription'), 10, 3);
        
        // Apply custom pricing to renewals
        add_action('woocommerce_scheduled_subscription_payment', array($this, 'apply_user_custom_renewal_price'), 1, 1);
        add_action('woocommerce_subscription_renewal_order_created', array($this, 'apply_custom_price_to_renewal_order'), 1, 2);
        
        // Additional hook to catch renewals created before scheduled payment
        add_filter('wcs_renewal_order_created', array($this, 'apply_custom_price_on_renewal_creation'), 1, 2);
        
        // Update subscription recurring total when renewal is created
        add_action('woocommerce_subscription_renewal_order_created', array($this, 'update_subscription_recurring_total'), 5, 2);
        
        // Set renewal date to last day of month (accounts for leap years)
        add_action('woocommerce_checkout_subscription_created', array($this, 'set_renewal_to_last_day_of_month'), 10, 1);
        add_action('woocommerce_subscription_payment_complete', array($this, 'set_next_renewal_to_last_day'), 10, 1);

        // Admin display
        add_action('woocommerce_subscription_details_after_subscription_table', array($this, 'display_custom_renewal_price_info'), 10, 1);
        
        // User list columns
        add_filter('manage_users_columns', array($this, 'add_custom_price_column'));
        add_action('manage_users_custom_column', array($this, 'show_custom_price_column'), 10, 3);
        
        // Force cart fragments refresh
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'refresh_cart_fragments'));
        
        // Hook into order creation to update prices
        add_action('woocommerce_checkout_order_created', array($this, 'update_order_prices_on_creation'), 10, 1);

        // Update subscription if we change price after order is created
        add_filter('woocommerce_subscription_get_total', array($this, 'filter_subscription_total_display'), 10, 2);
        add_filter('woocommerce_order_item_get_subtotal', array($this, 'filter_subscription_item_display_price'), 10, 3);
        add_filter('woocommerce_order_item_get_total', array($this, 'filter_subscription_item_display_price'), 10, 3);

        // By default, WooCommerce Subscriptions will calculate the next payment date for a subscription from the time of the last payment. 
        // This snippet changes it to calculate the next payment date from the scheduled payment date, not the time the payment was actually processed.
        add_filter( 'wcs_calculate_next_payment_from_last_payment', '__return_false' );
    }

    /**
     * Add custom price field to user profile
     */
    public function add_custom_renewal_price_field($user) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $custom_price = get_user_meta($user->ID, 'annual_membership_dues', true);
        $quarterly_price = get_user_meta($user->ID, 'quarterly_membership_dues', true);
        $bi_annual_price = get_user_meta($user->ID, 'bi_annual_membership_dues', true);
        ?>
        <style>
            /* Hide number input spinners */
            input[type=number].no-spinner::-webkit-outer-spin-button,
            input[type=number].no-spinner::-webkit-inner-spin-button {
                -webkit-appearance: none;
                margin: 0;
            }
            input[type=number].no-spinner {
                -moz-appearance: textfield;
            }
        </style>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            
            // Format number with commas
            function formatNumber(num) {
                if (!num || num === '') return '';
                // Remove any existing commas first
                var cleanNum = String(num).replace(/,/g, '');
                return parseInt(cleanNum).toLocaleString('en-US');
            }
            
            // Remove commas and parse number
            function parseNumber(str) {
                if (!str || str === '') return '';
                return str.replace(/,/g, '');
            }
            
            // Calculate and update derived fields
            function updateDerivedFields() {
                var annualInput = $('#annual_membership_dues');
                var annualValue = parseNumber(annualInput.val());
                
                if (annualValue && !isNaN(annualValue) && annualValue > 0) {
                    // Quarterly: annual / 4, rounded down to nearest 10
                    var quarterly = Math.floor((annualValue / 4) / 10) * 10;
                    $('#quarterly_membership_dues').val(formatNumber(quarterly));
                    
                    // Biennial: annual * 1.85, rounded down to nearest 10
                    var biAnnual = Math.floor((annualValue * 1.85) / 10) * 10;
                    $('#bi_annual_membership_dues').val(formatNumber(biAnnual));
                } else {
                    $('#quarterly_membership_dues').val('');
                    $('#bi_annual_membership_dues').val('');
                }
            }
            
            // Define annualInput FIRST
            var annualInput = $('#annual_membership_dues');
            
            // Format annual field on load
            var annualValue = annualInput.val();
            if (annualValue) {
                // Parse first to remove any existing formatting, then format
                var cleanValue = parseNumber(annualValue);
                if (cleanValue) {
                    annualInput.val(formatNumber(cleanValue));
                }
            }
            
            // Format existing quarterly and bi-annual values
            var quarterlyInput = $('#quarterly_membership_dues');
            var quarterlyValue = quarterlyInput.val();
            if (quarterlyValue) {
                var cleanQuarterly = parseNumber(quarterlyValue);
                if (cleanQuarterly) {
                    quarterlyInput.val(formatNumber(cleanQuarterly));
                }
            }
            
            var biAnnualInput = $('#bi_annual_membership_dues');
            var biAnnualValue = biAnnualInput.val();
            if (biAnnualValue) {
                var cleanBiAnnual = parseNumber(biAnnualValue);
                if (cleanBiAnnual) {
                    biAnnualInput.val(formatNumber(cleanBiAnnual));
                }
            }
            
            // Handle annual input changes
            annualInput.on('input', function() {
                // Remove any non-numeric characters except commas
                var value = $(this).val().replace(/[^\d,]/g, '');
                // Remove commas for calculation
                var numValue = parseNumber(value);
                
                // Update field with formatted value
                if (numValue) {
                    $(this).val(formatNumber(numValue));
                }
                
                // Update derived fields
                updateDerivedFields();
            });
            
            // Round down to nearest 10 on blur
            annualInput.on('blur', function() {
                var value = parseNumber($(this).val());
                if (value) {
                    value = Math.floor(value / 10) * 10;
                    $(this).val(formatNumber(value));
                    updateDerivedFields();
                }
            });
            
            // Before form submit, convert formatted values back to plain numbers
            $('form#your-profile').on('submit', function() {
                var annualVal = parseNumber(annualInput.val());
                if (annualVal) {
                    annualInput.val(annualVal);
                }
            });
        });
        </script>
        <h3><?php _e('Subscription Settings', 'wc-custom-renewal-pricing'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="annual_membership_dues"><?php _e('Annual Membership Dues', 'wc-custom-renewal-pricing'); ?></label></th>
                <td>
                    <input type="text" 
                           name="annual_membership_dues" 
                           id="annual_membership_dues" 
                           value="<?php echo esc_attr($custom_price); ?>" 
                           class="regular-text no-spinner" 
                           pattern="[0-9,]*"
                           inputmode="numeric" />
                    <p class="description">
                        <?php _e('Set the annual membership dues for this user\'s subscriptions. Whole numbers only. Leave empty to use default pricing.', 'wc-custom-renewal-pricing'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="bi_annual_membership_dues"><?php _e('Biennial Membership Dues', 'wc-custom-renewal-pricing'); ?></label></th>
                <td>
                    <input type="text" 
                           id="bi_annual_membership_dues" 
                           value="<?php echo esc_attr($bi_annual_price); ?>" 
                           class="regular-text" 
                           readonly 
                           style="background-color: #f0f0f1; cursor: not-allowed;" />
                    <p class="description">
                        <?php _e('Automatically calculated as annual dues times 1.85 and rounded down to nearest 10.', 'wc-custom-renewal-pricing'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="quarterly_membership_dues"><?php _e('Quarterly Membership Dues', 'wc-custom-renewal-pricing'); ?></label></th>
                <td>
                    <input type="text" 
                           id="quarterly_membership_dues" 
                           value="<?php echo esc_attr($quarterly_price); ?>" 
                           class="regular-text" 
                           readonly 
                           style="background-color: #f0f0f1; cursor: not-allowed;" />
                    <p class="description">
                        <?php _e('Automatically calculated as annual dues divided by four and rounded down to nearest 10.', 'wc-custom-renewal-pricing'); ?>
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
        
        if (isset($_POST['annual_membership_dues'])) {
            $custom_price = str_replace(',', '', sanitize_text_field($_POST['annual_membership_dues']));
            // Ensure it's a whole number
            $custom_price = intval($custom_price);
            // Round down to nearest 10
            $custom_price = floor($custom_price / 10) * 10;
            
            update_user_meta($user_id, 'annual_membership_dues', $custom_price);
            
            // Calculate and save related membership dues
            if ($custom_price > 0) {
                // Quarterly: annual / 4, rounded down to nearest 10
                $quarterly = floor(($custom_price / 4) / 10) * 10;
                update_user_meta($user_id, 'quarterly_membership_dues', $quarterly);
                
                // Bi-Annual: annual * 1.85, rounded down to nearest 10
                $bi_annual = floor(($custom_price * 1.85) / 10) * 10;
                update_user_meta($user_id, 'bi_annual_membership_dues', $bi_annual);
            } else {
                // Clear calculated values if annual is empty or invalid
                delete_user_meta($user_id, 'quarterly_membership_dues');
                delete_user_meta($user_id, 'bi_annual_membership_dues');
            }
            
            $subscriptions = wcs_get_users_subscriptions($user_id);
            
            // Update all active subscriptions
            foreach ($subscriptions as $subscription) {
                if (!$subscription->has_status(array('active', 'pending-cancel'))) {
                    continue;
                }
                
                $updated = false;
                
                foreach ($subscription->get_items() as $item_id => $item) {
                    $product_id = $item->get_product_id();
                    $variation_id = $item->get_variation_id();
                    
                    // Check both product ID and variation ID
                    $check_id = $variation_id ? $variation_id : $product_id;
                    
                    if (isset($this->product_pricing_map[$check_id]) || isset($this->product_pricing_map[$product_id])) {
                        $pricing_field = isset($this->product_pricing_map[$check_id]) 
                            ? $this->product_pricing_map[$check_id] 
                            : $this->product_pricing_map[$product_id];
                        
                        $new_price = get_user_meta($user_id, $pricing_field, true);
                        
                        if ($new_price && is_numeric($new_price) && $new_price >= 0) {
                            $item->set_subtotal($new_price);
                            $item->set_total($new_price);
                            $item->save();
                            $updated = true;
                        }
                    }
                }
                
                if ($updated) {
                    // Force recalculation
                    $subscription->calculate_totals();
                    
                    // Ensure the recurring total meta is updated
                    update_post_meta($subscription->get_id(), '_order_total', $subscription->get_total());
                    
                    // Save the subscription object to trigger internal hooks
                    $subscription->save();
                    
                    // Clear the specific subscription cache
                    wp_cache_delete($subscription->get_id(), 'posts');
                    wc_delete_shop_order_transients($subscription->get_id());
                }
            }
            
            // Update any pending renewal orders
            foreach ($subscriptions as $subscription) {
                // Get the last order
                $last_order = $subscription->get_last_order('all');
                
                if ($last_order && $last_order->has_status('pending')) {
                    $order_updated = false;
                    
                    foreach ($last_order->get_items() as $item_id => $item) {
                        $product_id = $item->get_product_id();
                        $variation_id = $item->get_variation_id();
                        
                        $check_id = $variation_id ? $variation_id : $product_id;
                        
                        if (isset($this->product_pricing_map[$check_id]) || isset($this->product_pricing_map[$product_id])) {
                            $pricing_field = isset($this->product_pricing_map[$check_id]) 
                                ? $this->product_pricing_map[$check_id] 
                                : $this->product_pricing_map[$product_id];
                            
                            $new_price = get_user_meta($user_id, $pricing_field, true);
                            
                            if ($new_price && is_numeric($new_price) && $new_price >= 0) {
                                $item->set_subtotal($new_price);
                                $item->set_total($new_price);
                                $item->save();
                                $order_updated = true;
                            }
                        }
                    }
                    
                    if ($order_updated) {
                        $last_order->calculate_totals();
                        $last_order->save();
                    }
                }
            }
        }
    }
    
    /**
     * Update order prices when order is created at checkout
     */
    public function update_order_prices_on_creation($order) {
        
        // Don't apply custom pricing to subscription switch orders
        if (function_exists('wcs_order_contains_switch') && wcs_order_contains_switch($order)) {
            return;
        }
        
        $user_id = $order->get_user_id();
        
        if (!$user_id) {
            return;
        }
        
        $updated = false;
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
            // Check both product ID and variation ID
            $check_id = $variation_id ? $variation_id : $product_id;
            
            if (isset($this->product_pricing_map[$check_id]) || isset($this->product_pricing_map[$product_id])) {
                $pricing_field = isset($this->product_pricing_map[$check_id]) 
                    ? $this->product_pricing_map[$check_id] 
                    : $this->product_pricing_map[$product_id];
                
                $custom_price = get_user_meta($user_id, $pricing_field, true);
                
                if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
                    $item->set_subtotal($custom_price);
                    $item->set_total($custom_price);
                    $item->save();
                    $updated = true;
                }
            }
        }
        
        if ($updated) {
            $order->calculate_totals();
            $order->save();
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
        $updated = false;
        
        // Update subscription line items with current custom pricing
        foreach ($subscription->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
            // Check both product ID and variation ID
            $check_id = $variation_id ? $variation_id : $product_id;
            
            if (isset($this->product_pricing_map[$check_id]) || isset($this->product_pricing_map[$product_id])) {
                $pricing_field = isset($this->product_pricing_map[$check_id]) 
                    ? $this->product_pricing_map[$check_id] 
                    : $this->product_pricing_map[$product_id];
                
                $custom_price = get_user_meta($user_id, $pricing_field, true);
                
                if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
                    $item->set_subtotal($custom_price);
                    $item->set_total($custom_price);
                    $item->save();
                    $updated = true;
                }
            }
        }
        
        if ($updated) {
            $subscription->calculate_totals();
            $subscription->save();
        }
    }

    /**
     * Apply custom price to renewal orders when they're created
     * This ensures the correct price is set before the email is generated
     */
    public function apply_custom_price_to_renewal_order($renewal_order, $subscription) {
        $user_id = $subscription->get_user_id();
        
        $updated = false;
        
        foreach ($renewal_order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
            // Check both product ID and variation ID
            $check_id = $variation_id ? $variation_id : $product_id;
            
            if (isset($this->product_pricing_map[$check_id]) || isset($this->product_pricing_map[$product_id])) {
                $pricing_field = isset($this->product_pricing_map[$check_id]) 
                    ? $this->product_pricing_map[$check_id] 
                    : $this->product_pricing_map[$product_id];
                
                // Get the current custom price from user meta
                $custom_price = get_user_meta($user_id, $pricing_field, true);
                
                if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
                    // Set all price components explicitly
                    $item->set_subtotal($custom_price);
                    $item->set_total($custom_price);
                    
                    // Also update the line item meta to ensure consistency
                    $item->update_meta_data('_line_subtotal', $custom_price);
                    $item->update_meta_data('_line_total', $custom_price);
                    
                    $item->save();
                    $updated = true;
                }
            }
        }
        
        if ($updated) {
            // Recalculate order totals to ensure everything is in sync
            $renewal_order->calculate_totals();
            $renewal_order->save();
        }
    }

    /**
     * Forces the subscription total display to match the custom user pricing.
     */
    public function filter_subscription_total_display($total, $subscription) {
        $user_id = $subscription->get_user_id();
        $custom_total = 0;
        $has_custom = false;

        foreach ($subscription->get_items() as $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $check_id = $variation_id ? $variation_id : $product_id;

            if (isset($this->product_pricing_map[$check_id])) {
                $field = $this->product_pricing_map[$check_id];
                $price = get_user_meta($user_id, $field, true);
                
                if ($price !== '' && is_numeric($price)) {
                    $custom_total += (float) $price * $item->get_quantity();
                    $has_custom = true;
                } else {
                    $custom_total += (float) $item->get_total();
                }
            } else {
                $custom_total += (float) $item->get_total();
            }
        }

        // Include taxes/fees if you want them to be part of the display total
        if ($has_custom) {
            return $custom_total + $subscription->get_total_tax() + $subscription->get_shipping_total();
        }

        return $total;
    }

    /**
     * Filters the individual line item subtotal and total display 
     * for the "My Account" subscription table.
     */
    public function filter_subscription_item_display_price($value, $item, $read_only = false) {
        // Only target line items (not shipping or taxes)
        if (!is_a($item, 'WC_Order_Item_Product')) {
            return $value;
        }

        // Get the parent object (the subscription)
        $order = $item->get_order();
        
        // Ensure we are dealing with a subscription object
        if (!$order || !is_a($order, 'WC_Subscription')) {
            return $value;
        }

        $user_id = $order->get_user_id();
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $check_id = $variation_id ? $variation_id : $product_id;

        // Check if this product has a custom price mapping
        if (isset($this->product_pricing_map[$check_id])) {
            $field = $this->product_pricing_map[$check_id];
            $custom_price = get_user_meta($user_id, $field, true);

            if ($custom_price !== '' && is_numeric($custom_price)) {
                // Return the custom price multiplied by quantity
                return (float) $custom_price * $item->get_quantity();
            }
        }

        return $value;
    }

    /**
     * Additional hook to catch renewal orders created via wcs_renewal_order_created filter
     */
    public function apply_custom_price_on_renewal_creation($renewal_order, $subscription) {
        // Call the same function to ensure consistency
        $this->apply_custom_price_to_renewal_order($renewal_order, $subscription);
        return $renewal_order;
    }

    /**
     * Ensure subscription line items are updated and totals recalculated.
     */
    public function update_subscription_recurring_total($renewal_order, $subscription) {
        $user_id = $subscription->get_user_id();
        $updated = false;
        
        foreach ($subscription->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $check_id = $variation_id ? $variation_id : $product_id;
            
            if (isset($this->product_pricing_map[$check_id]) || isset($this->product_pricing_map[$product_id])) {
                $pricing_field = isset($this->product_pricing_map[$check_id]) 
                    ? $this->product_pricing_map[$check_id] 
                    : $this->product_pricing_map[$product_id];
                
                $custom_price = get_user_meta($user_id, $pricing_field, true);
                
                if ($custom_price !== '' && is_numeric($custom_price) && $custom_price >= 0) {
                    $item->set_subtotal($custom_price);
                    $item->set_total($custom_price);
                    $item->save();
                    $updated = true;
                }
            }
        }
        
        if ($updated) {
            $subscription->calculate_totals();
            // Force update the meta directly
            update_post_meta($subscription->get_id(), '_order_total', $subscription->get_total());
            $subscription->save();
            
            // Clear caches
            wp_cache_delete($subscription->get_id(), 'posts');
            wc_delete_shop_order_transients($subscription->get_id());
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
        
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $variation_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
            
            // Check both product ID and variation ID
            $check_id = $variation_id ? $variation_id : $product_id;
            
            // Also check parent product ID for variations
            if (isset($this->product_pricing_map[$check_id]) || isset($this->product_pricing_map[$product_id])) {
                $pricing_field = isset($this->product_pricing_map[$check_id]) 
                    ? $this->product_pricing_map[$check_id] 
                    : $this->product_pricing_map[$product_id];
                
                $custom_price = get_user_meta($user_id, $pricing_field, true);
                
                if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
                    $cart_item['data']->set_price($custom_price);
                }
            }
        }
    }
    
    /**
     * Display custom price in cart and mini-cart
     */
    public function display_custom_cart_price($price, $cart_item, $cart_item_key) {
        if (!is_user_logged_in()) {
            return $price;
        }
        
        $user_id = get_current_user_id();
        $product_id = $cart_item['product_id'];
        $variation_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
        
        // Check both product ID and variation ID
        $check_id = $variation_id ? $variation_id : $product_id;
        
        if (isset($this->product_pricing_map[$check_id]) || isset($this->product_pricing_map[$product_id])) {
            $pricing_field = isset($this->product_pricing_map[$check_id]) 
                ? $this->product_pricing_map[$check_id] 
                : $this->product_pricing_map[$product_id];
            
            $custom_price = get_user_meta($user_id, $pricing_field, true);
            
            if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
                return wc_price($custom_price);
            }
        }
        
        return $price;
    }
    
    /**
     * Custom price HTML for products in cart
     */
    public function custom_price_html($price, $product) {
        if (!is_user_logged_in() || !is_cart() && !wp_doing_ajax()) {
            return $price;
        }
        
        $user_id = get_current_user_id();
        $product_id = $product->get_id();
        $parent_id = $product->get_parent_id();
        
        // Check both product ID and parent ID for variations
        $check_id = $parent_id ? $parent_id : $product_id;
        
        if (isset($this->product_pricing_map[$product_id]) || isset($this->product_pricing_map[$check_id])) {
            $pricing_field = isset($this->product_pricing_map[$product_id]) 
                ? $this->product_pricing_map[$product_id] 
                : $this->product_pricing_map[$check_id];
            
            $custom_price = get_user_meta($user_id, $pricing_field, true);
            
            if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
                return wc_price($custom_price);
            }
        }
        
        return $price;
    }
    
    /**
     * Force refresh cart fragments to update mini-cart
     */
    public function refresh_cart_fragments($fragments) {
        // This ensures the mini-cart refreshes with updated prices
        return $fragments;
    }

    /**
     * Apply custom price when subscription is created at checkout
     */
    public function apply_custom_price_to_new_subscription($subscription, $order, $recurring_cart) {
        $user_id = $subscription->get_user_id();
        
        foreach ($subscription->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
            // Check both product ID and variation ID
            $check_id = $variation_id ? $variation_id : $product_id;
            
            if (isset($this->product_pricing_map[$check_id]) || isset($this->product_pricing_map[$product_id])) {
                $pricing_field = isset($this->product_pricing_map[$check_id]) 
                    ? $this->product_pricing_map[$check_id] 
                    : $this->product_pricing_map[$product_id];
                
                $custom_price = get_user_meta($user_id, $pricing_field, true);
                
                if ($custom_price && is_numeric($custom_price) && $custom_price >= 0) {
                    $item->set_subtotal($custom_price);
                    $item->set_total($custom_price);
                    $item->save();
                }
            }
        }
        
        $subscription->calculate_totals();
        $subscription->save();
    }

    /**
     * Set subscription renewal to last day of the appropriate month based on interval
     */
    public function set_renewal_to_last_day_of_month($subscription_id) {
        $subscription = wcs_get_subscription($subscription_id);
        
        if (!$subscription) {
            return;
        }
        
        // Get subscription billing interval and period
        $interval = $subscription->get_billing_interval();
        $period = $subscription->get_billing_period(); // day, week, month, year
        
        // Set timezone to EST
        $timezone = new DateTimeZone('America/New_York');
        $current_date = new DateTime('now', $timezone);
        
        // Calculate next payment date based on period and interval
        if ($period == 'month') {
            // Add the interval number of months
            $current_date->modify("+$interval months");
        } elseif ($period == 'year') {
            // Add the interval number of years
            $current_date->modify("+$interval years");
        } else {
            // For day or week periods
            if ($period == 'week') {
                $days = $interval * 7;
            } else {
                $days = $interval;
            }
            $current_date->modify("+$days days");
        }
        
        // Get last day of the target month and set time to 9 AM EST
        $target_year = $current_date->format('Y');
        $target_month = $current_date->format('m');
        $last_day = $current_date->format('t');
        
        // Create the next payment date at 9 AM EST
        $next_payment_date_est = new DateTime("$target_year-$target_month-$last_day 09:00:00", $timezone);
        
        // Convert to GMT for WooCommerce
        $next_payment_date_est->setTimezone(new DateTimeZone('GMT'));
        $next_payment_date = $next_payment_date_est->format('Y-m-d H:i:s');
        
        // Update the subscription's next payment date
        $subscription->update_dates(array(
            'next_payment' => $next_payment_date,
        ));
        
        $subscription->save();
    }

    /**
     * Update next renewal date after a payment is processed
     */
    public function set_next_renewal_to_last_day($subscription) {
        if (is_numeric($subscription)) {
            $subscription = wcs_get_subscription($subscription);
        }
        
        if (!$subscription) {
            return;
        }
        
        // Get subscription billing interval and period
        $interval = $subscription->get_billing_interval();
        $period = $subscription->get_billing_period();
        
        // Set timezone to EST
        $timezone = new DateTimeZone('America/New_York');
        
        // Get the last payment date or current date as reference
        $last_payment = $subscription->get_date('last_order_date_created');
        if ($last_payment) {
            $reference_date = new DateTime($last_payment, new DateTimeZone('GMT'));
            $reference_date->setTimezone($timezone);
        } else {
            $reference_date = new DateTime('now', $timezone);
        }
        
        // Calculate next payment date based on period and interval
        if ($period == 'month') {
            $reference_date->modify("+$interval months");
        } elseif ($period == 'year') {
            $reference_date->modify("+$interval years");
        } else {
            // For day or week periods
            if ($period == 'week') {
                $days = $interval * 7;
            } else {
                $days = $interval;
            }
            $reference_date->modify("+$days days");
        }
        
        // Get last day of the target month and set time to 9 AM EST
        $target_year = $reference_date->format('Y');
        $target_month = $reference_date->format('m');
        $last_day = $reference_date->format('t');
        
        // Create the next payment date at 9 AM EST
        $next_payment_date_est = new DateTime("$target_year-$target_month-$last_day 09:00:00", $timezone);
        
        // Convert to GMT for WooCommerce
        $next_payment_date_est->setTimezone(new DateTimeZone('GMT'));
        $next_payment_date = $next_payment_date_est->format('Y-m-d H:i:s');
        
        // Update the subscription
        $subscription->update_dates(array(
            'next_payment' => $next_payment_date,
        ));
        
        $subscription->save();
    }

    /**
     * Display custom price in admin subscription view
     */
    public function display_custom_renewal_price_info($subscription) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $user_id = $subscription->get_user_id();
        $custom_price = get_user_meta($user_id, 'annual_membership_dues', true);
        
        if ($custom_price && is_numeric($custom_price)) {
            echo '<div class="woocommerce-message" style="margin-top: 20px;">';
            echo '<strong>' . __('Annual Membership Dues:', 'wc-custom-renewal-pricing') . '</strong> ';
            echo wc_price($custom_price);
            echo ' <a href="' . admin_url('user-edit.php?user_id=' . $user_id) . '">' . __('Edit User Profile', 'wc-custom-renewal-pricing') . '</a>';
            echo '</div>';
        }
    }
    
    /**
     * Add custom price column to users list
     */
    public function add_custom_price_column($columns) {
        $columns['annual_membership_dues'] = __('Annual Membership Dues', 'wc-custom-renewal-pricing');
        $columns['bi_annual_membership_dues'] = __('Bi-Annual Dues', 'wc-custom-renewal-pricing');
        $columns['quarterly_membership_dues'] = __('Quarterly Dues', 'wc-custom-renewal-pricing');
        return $columns;
    }
    
    /**
     * Show custom price in users list column
     */
    public function show_custom_price_column($value, $column_name, $user_id) {
        if ($column_name == 'annual_membership_dues') {
            $custom_price = get_user_meta($user_id, 'annual_membership_dues', true);
            if ($custom_price && is_numeric($custom_price)) {
                return wc_price($custom_price);
            }
            return '—';
        }
        
        if ($column_name == 'bi_annual_membership_dues') {
            $custom_price = get_user_meta($user_id, 'bi_annual_membership_dues', true);
            if ($custom_price && is_numeric($custom_price)) {
                return wc_price($custom_price);
            }
            return '—';
        }
        
        if ($column_name == 'quarterly_membership_dues') {
            $custom_price = get_user_meta($user_id, 'quarterly_membership_dues', true);
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
