<?php
/*
Plugin Name: cmeboss_split_order_by_thermospheric_transport 
Description: This plugin allows customers to split orders at checkout based on their preferred shipping method (frozen or ambient), improving inventory management.
Version: 1.0.0
Author: CMEBOSS
Author URI: https://magiccloud.i234.me
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: woo-temp-note
Domain Path: /languages/
*/


if (!defined('ABSPATH')) {
    exit; // 直接存取時退出
}


if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    // WooCommerce is not active
    add_action('admin_notices', 'cmeboss_woocommerce_required_notice');
    return;
}

function cmeboss_woocommerce_required_notice() {
    echo '<div class="error"><p>本插件需要 WooCommerce 插件才能正常運行。請安裝並激活 WooCommerce。</p></div>';
}


function cmeboss_add_shipping_categories_to_woocommerce() {
    $shipping_categories = array(
        array(
            'name' => '冷凍宅配',
            'slug' => 'cold',
        ),
        array(
            'name' => '常溫宅配',
            'slug' => 'normal',
        ),
        array(
            'name' => '贈品',
            'slug' => 'free',
        ),
    );

    foreach ($shipping_categories as $category) {
        if (term_exists($category['slug'], 'product_shipping_class')) {
            continue;
        }

        wp_insert_term(
            $category['name'],
            'product_shipping_class',
            array(
                'slug' => $category['slug'],
            )
        );
    }
}

add_action('init', 'cmeboss_add_shipping_categories_to_woocommerce');

add_action('woocommerce_check_cart_items', 'cmeboss_check_shipping_methods');

function cmeboss_check_shipping_methods() {
    $shipping_methods = array();
    $different_shipping = false;

    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $shipping_method = $cart_item['data']->get_shipping_class();

        if ($shipping_method === 'free') {
            continue;
        }

        if (!in_array($shipping_method, $shipping_methods)) {
            $shipping_methods[] = $shipping_method;
        }
    }

    if (count($shipping_methods) > 1) {
        $different_shipping = true;
    }

    if ($different_shipping) {
        remove_action('woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20);
        wc_add_notice('請注意，您的購物車中存在不同溫層運送的產品，需分開包裝。', 'error');
        add_action('woocommerce_before_cart_totals', 'cmeboss_add_shipping_option_form');
    }
}

function cmeboss_add_shipping_option_form() {
    echo '<form id="shipping-option-form" method="post" action="">';
    wp_nonce_field('cmeboss_shipping_option_nonce', 'cmeboss_shipping_option_nonce');
    echo '<label for="shipping-choice">請問您想先包裝哪個溫層的產品：</label>';
    echo '<select id="shipping-choice" name="shipping_choice">';
    echo '<option value="cold">冷凍產品</option>';
    echo '<option value="normal">常溫產品</option>';
    echo '</select>';
    echo '<input type="submit" name="submit_shipping_choice" value="選擇">';
    echo '</form>';
}

add_action('init', 'cmeboss_process_shipping_choice');

function cmeboss_process_shipping_choice() {
    if (isset($_POST['submit_shipping_choice']) && isset($_POST['cmeboss_shipping_option_nonce'])) {
        if (wp_verify_nonce($_POST['cmeboss_shipping_option_nonce'], 'cmeboss_shipping_option_nonce')) {
            // nonce 驗證通過，繼續處理表單數據
            $selected_option = sanitize_text_field($_POST['shipping_choice']);
            $items_to_move_to_wishlist = array();

            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $shipping_method = $cart_item['data']->get_shipping_class();

                if ($shipping_method === 'free') {
                    continue;
                }

                if (($selected_option === 'normal' && $shipping_method === 'cold') || 
                    ($selected_option === 'cold' && $shipping_method === 'normal')) {
                    $items_to_move_to_wishlist[] = $cart_item;
                    WC()->cart->remove_cart_item($cart_item_key);
                }
            }

            if (!empty($items_to_move_to_wishlist)) {
                WC()->session->set('wishlist_items', $items_to_move_to_wishlist);
            }
        } else {
            // nonce 驗證失敗，拋出錯誤或者執行其他動作
            wp_die('Nonce 驗證失敗！請聯繫網站管理員。');
        }
    }
}

function cmeboss_custom_cart_item_name($item_name, $cart_item, $cart_item_key) {
    $product = $cart_item['data'];
    $shipping_method = $product->get_shipping_class();

    if ($shipping_method && $shipping_method !== 'free') {
        $shipping_class = get_term_by('slug', $shipping_method, 'product_shipping_class');
        if ($shipping_class) {
            $shipping_method_name = $shipping_class->name;
            $item_name .= '<br><small>運輸方式: ' . esc_html($shipping_method_name) . '</small>';
        }
    }

    return $item_name;
}
add_filter('woocommerce_cart_item_name', 'cmeboss_custom_cart_item_name', 10, 3);

// 添加購物車中願望清單項目數量的顯示和取消訂單按鈕
add_action('woocommerce_before_cart', 'cmeboss_display_wishlist_items_details_and_cancel_order_button');
function cmeboss_display_wishlist_items_details_and_cancel_order_button() {
    $wishlist_items = WC()->session->get('wishlist_items');
    $wishlist_items_count = !empty($wishlist_items) ? count($wishlist_items) : 0;

    if ($wishlist_items_count > 0) {
        echo '<p>您的願望清單中還有 ' . $wishlist_items_count . ' 張訂單待處理：</p>';
        echo '<ul>';
        foreach ($wishlist_items as $item) {
            $product = $item['data'];
            $product_name = $product->get_name();
            $product_quantity = $item['quantity'];
            $product_price = wc_price($product->get_price() * $product_quantity);

            echo '<li>';
            echo '' . $product_name . '(' . $product_quantity . ')' . $product_price;
            echo '</li>';
        }
        echo '</ul>';

        echo '<form method="post">';
        echo '<input type="hidden" name="cancel_order" value="true" />';
        echo '<button type="submit" class="button" name="cancel_order_button">取消願望清單</button>';
        echo '</form>';
    }
}
// 處理取消訂單按鈕的操作
add_action('init', 'cmeboss_process_cancel_order_button');
function cmeboss_process_cancel_order_button() {
    if (isset($_POST['cancel_order_button']) && isset($_POST['cancel_order'])) {
        WC()->session->set('wishlist_items', array());
        wc_add_notice('訂單已取消！', 'success');
        wp_redirect(wc_get_cart_url());
        exit;
    }
}

add_action('woocommerce_thankyou', 'cmeboss_move_wishlist_items_to_cart_and_display_popup');

function cmeboss_move_wishlist_items_to_cart_and_display_popup($order_id) {
    $wishlist_items = WC()->session->get('wishlist_items');

    if (!empty($wishlist_items)) {
        foreach ($wishlist_items as $wishlist_item) {
            WC()->cart->add_to_cart($wishlist_item['product_id'], $wishlist_item['quantity'], $wishlist_item['variation_id'], $wishlist_item['variation'], $wishlist_item);
        }
        WC()->session->set('wishlist_items', array()); 

        echo '<script>
            var confirm_result = confirm(' . wp_json_encode("親愛的貴賓!提醒您還有一張待結帳的訂單，是否立即前往結帳?") . ');
            if (confirm_result) {
                window.location.href = ' . wp_json_encode(esc_url(wc_get_cart_url())) . ';
            }
        </script>';
    }
}