<?php
namespace wptelegrampro;

if (!defined('ABSPATH')) exit;
if (!class_exists('WooCommerce')) return;

class WooCommerceWPTP extends WPTelegramPro
{
    protected $tabID = 'woocommerce-wptp-tab', $default_products_keyboard;
    public static $instance = null;

    public function __construct()
    {
        parent::__construct();

        $this->default_products_keyboard = array(array(
            array('text' => __('Detail', $this->plugin_key), 'callback_data' => 'product_detail')
        ));

        add_filter('wptelegrampro_words', [$this, 'words']);
        add_filter('wptelegrampro_patterns_tags', [$this, 'patterns_tags']);
        add_filter('wptelegrampro_query_args', [$this, 'query_args'], 10, 2);
        add_filter('wptelegrampro_post_info', [$this, 'product_info'], 10, 3);
        add_filter('wptelegrampro_default_keyboard', [$this, 'default_keyboard'], 9);
        add_filter('wptelegrampro_settings_tabs', [$this, 'settings_tab'], 30);
        add_action('wptelegrampro_settings_content', [$this, 'settings_content']);
        add_action('wptelegrampro_inline_keyboard_response', [$this, 'inline_keyboard_response']);
        add_action('wptelegrampro_keyboard_response', [$this, 'keyboard_response']);
        add_action('wptelegrampro_keyboard_response_location', [$this, 'keyboard_response']);
        add_action('wptelegrampro_keyboard_response_contact', [$this, 'keyboard_response']);
        add_filter('wptelegrampro_default_commands', [$this, 'default_commands'], 20);
        add_filter('wctgdeliv_paysystems', [$this, 'default_paysystems'],1);
        add_action('wptelegrampro_inline_query', [$this, 'inline_query']);

        add_action('init', [$this, 'cart_init'], 99999);
        add_action('woocommerce_payment_complete', [$this, 'woocommerce_payment_complete']);
        add_action('woocommerce_account_edit-account_endpoint', [$this, 'woocommerce_edit_account'], 1);
        add_action('template_redirect', [$this, 'user_disconnect']);

        if ($this->get_option('wc_admin_new_order_notification', false))
            add_action('woocommerce_thankyou', [$this, 'admin_new_order_notification']);
        if ($this->get_option('wc_admin_order_status_notification', false))
            add_action('woocommerce_order_status_changed', [$this, 'admin_order_status_notification'], 10, 4);
        if ($this->get_option('wc_admin_product_low_stock_notification', false))
            add_action('woocommerce_low_stock', [$this, 'admin_product_stock_change_notification']);
        if ($this->get_option('wc_admin_product_no_stock_notification', false))
            add_action('woocommerce_no_stock', [$this, 'admin_product_stock_change_notification']);
        if ($this->get_option('wc_order_status_notification', false))
            add_action('woocommerce_order_status_changed', [$this, 'user_order_status_notification'], 10, 4);
        if ($this->get_option('wc_order_note_customer_notification', false))
            add_action('woocommerce_new_customer_note', [$this, 'user_order_note_customer_notification'], 10, 1);
        if ($this->get_option('wc_admin_order_note_notification', false))
            add_action('wp_insert_comment', [$this, 'admin_order_note_notification'], 10, 2);

        add_action('delete_comment', [$this, 'order_note_delete_notification'], 10, 2);

        $this->words = apply_filters('wptelegrampro_words', $this->words);
    }

    /**
     * Send notification to admin and shop manager users when product stock changed
     *
     * @param WC_Product|null|false $product
     */
    public function admin_product_stock_change_notification($product)
    {
        if (!$product) return;

        $users = $this->get_users(['Administrator', 'shop_manager']);
        if ($users) {
            if ($product->is_type('variation'))
                $product = wc_get_product($product->get_parent_id());

            $keyboard = array(array(
                array(
                    'text' => '📝',
                    'url' => admin_url('post.php?action=edit&post=' . $product->get_id())
                ),
                array(
                    'text' => '📂',
                    'url' => admin_url('edit.php?post_type=product')
                )
            ));
            $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');

            $no_stock_amount = absint(get_option('woocommerce_notify_no_stock_amount', 0));
            $low_stock_amount = absint(wc_get_low_stock_amount($product));
            $product_stock_amount = absint($product->get_stock_quantity());

            $text = "*" . __('Product stock status', $this->plugin_key) . "*\n\n";
            $text .= __('Product', $this->plugin_key) . ': ' . $product->get_title() . "\n";
            $text .= __('Stock status', $this->plugin_key) . ': ';

            if ($product_stock_amount <= $no_stock_amount) {
                $text .= __('No stock', $this->plugin_key) . "\n";

            } elseif ($product_stock_amount <= $low_stock_amount) {
                $text .= __('Low stock', $this->plugin_key) . "\n";
                $text .= __('Current quantity', $this->plugin_key) . ': ' . $product_stock_amount . "\n";
            }
            $text .= __('Date', $this->plugin_key) . ': ' . HelpersWPTP::localeDate() . "\n";

            $text = apply_filters('wptelegrampro_wc_admin_product_stock_change_notification_text', $text, $product);

            foreach ($users as $user)
                $this->telegram->sendMessage($text, $keyboards, $user['user_id'], 'Markdown');
        }
    }

    /**
     * Send notification to admin users when order status changed
     *
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     * @param WC_Order  Actual order
     */
    public function admin_order_status_notification($order_id, $old_status, $new_status, $order)
    {
        $users = $this->get_users(['Administrator', 'shop_manager']);
        if ($users) {
            $keyboard = array(array(
                array(
                    'text' => '📝',
                    'url' => admin_url('post.php?post=' . $order_id . '&action=edit')
                ),
                array(
                    'text' => '📂',
                    'url' => admin_url('edit.php?post_type=shop_order')
                )
            ));
            $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');

            $text = "*" . __('Order status changed', $this->plugin_key) . "*\n\n";
            $text .= __('Order number', $this->plugin_key) . ': ' . $order_id . "\n";
            $text .= __('Old status', $this->plugin_key) . ': ' . wc_get_order_status_name($old_status) . "\n";
            $text .= __('New status', $this->plugin_key) . ': ' . wc_get_order_status_name($new_status) . "\n";
            $text .= __('Date', $this->plugin_key) . ': ' . HelpersWPTP::localeDate($order->get_date_modified()) . "\n";

            $text = apply_filters('wptelegrampro_wc_admin_order_status_notification_text', $text, $order, $order_id);

            foreach ($users as $user)
                $this->telegram->sendMessage($text, $keyboards, $user['user_id'], 'Markdown');
        }
    }

    /**
     * Send notification to customer when order status changed
     *
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     * @param WC_Order  Actual order
     */
    public function user_order_status_notification($order_id, $old_status, $new_status, $order)
    {
        $user_id = $order->get_customer_id();
        if ($user_id) {
            $user = $this->set_user(array('wp_id' => $user_id));
            if ($user) {
                $orders_endpoint = get_option('woocommerce_myaccount_orders_endpoint', 'orders');
                if (!empty($orders_endpoint)) {
                    $keyboard = array(array(
                        array(
                            'text' => '👁️',
                            'url' => $order->get_view_order_url()
                        ),
                        array(
                            'text' => '📂',
                            'url' => esc_url_raw(wc_get_account_endpoint_url($orders_endpoint))
                        )
                    ));
                    $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
                } else {
                    $keyboards = null;
                }

                $text = "*" . __('Order status changed', $this->plugin_key) . "*\n\n";
                $text .= __('Order number', $this->plugin_key) . ': ' . $order_id . "\n";
                $text .= __('New status', $this->plugin_key) . ': ' . wc_get_order_status_name($new_status) . "\n";
                $text .= __('Date', $this->plugin_key) . ': ' . HelpersWPTP::localeDate($order->get_date_modified()) . "\n";
                $text = apply_filters('wptelegrampro_wc_user_order_status_notification_text', $text, $order, $order_id);

                $this->telegram->sendMessage($text, $keyboards, $user['user_id'], 'Markdown');
            }
        }
    }

    /**
     * Send notification to admin and shop manager when add order note
     *
     * @param int $commentID The comment ID.
     * @param WP_Comment $comment Comment object.
     */
    function admin_order_note_notification($commentID, $comment)
    {
        if ($comment->comment_type != 'order_note')
            return;

        $content = $comment->comment_content;
        $order_id = intval($comment->comment_post_ID);

        $users = $this->get_users(['Administrator', 'shop_manager']);
        if ($users) {
            $keyboard = array(array(
                array(
                    'text' => '📝',
                    'url' => admin_url('post.php?post=' . $order_id . '&action=edit')
                ),
                array(
                    'text' => '📂',
                    'url' => admin_url('edit.php?post_type=shop_order')
                )
            ));
            $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');

            $text = "*" . __('New order note', $this->plugin_key) . "*\n\n";
            $text .= __('Order number', $this->plugin_key) . ': ' . $order_id . "\n";
            $text .= __('Note', $this->plugin_key) . ': ' . "\n" . $content . "\n";
            $text .= __('Date', $this->plugin_key) . ': ' . HelpersWPTP::localeDate() . "\n";

            $text = apply_filters('wptelegrampro_wc_admin_order_note_notification_text', $text, $content, $order_id);

            foreach ($users as $user) {
                $this->telegram->sendMessage($text, $keyboards, $user['user_id'], 'Markdown');
                $message_id = $this->telegram->get_last_result()['result']['message_id'];
                $this->save_message_id_order_note($commentID, $user['user_id'], $message_id);
            }
        }
    }

    /**
     * Send notification to customer when add order note
     *
     * @param array $data
     */
    function user_order_note_customer_notification($data)
    {
        $order_id = $data['order_id'];
        $customer_note = $data['customer_note'];
        $order = wc_get_order($order_id);
        $user_id = $order->get_customer_id();

        if ($user_id) {
            $user = $this->set_user(array('wp_id' => $user_id));
            if ($user) {
                $customer_order_notes = $order->get_customer_order_notes();
                $customer_order_note = current($customer_order_notes);
                $commentID = intval($customer_order_note->comment_ID);

                $orders_endpoint = get_option('woocommerce_myaccount_orders_endpoint', 'orders');
                if (!empty($orders_endpoint)) {
                    $keyboard = array(array(
                        array(
                            'text' => '👁️',
                            'url' => $order->get_view_order_url()
                        ),
                        array(
                            'text' => '📂',
                            'url' => esc_url_raw(wc_get_account_endpoint_url($orders_endpoint))
                        )
                    ));
                    $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
                } else {
                    $keyboards = null;
                }

                $text = "*" . __('New order note', $this->plugin_key) . "*\n\n";
                $text .= __('Order number', $this->plugin_key) . ': ' . $order_id . "\n";
                $text .= __('Note', $this->plugin_key) . ': ' . "\n" . $customer_note . "\n";
                $text .= __('Date', $this->plugin_key) . ': ' . HelpersWPTP::localeDate() . "\n";
                $text = apply_filters('wptelegrampro_wc_user_order_note_customer_notification_text', $text, $customer_note, $order_id);

                $this->telegram->sendMessage($text, $keyboards, $user['user_id'], 'Markdown');
                $message_id = $this->telegram->get_last_result()['result']['message_id'];
                $this->save_message_id_order_note($commentID, $user['user_id'], $message_id);
            }
        }
    }

    /**
     * Delete Telegram notification when delete order note
     *
     * @param int $commentID The comment ID.
     * @param WP_Comment $comment The comment to be deleted.
     */
    function order_note_delete_notification($commentID, $comment)
    {
        if ($comment->comment_type != 'order_note')
            return;

        $meta = get_comment_meta($commentID, 'order_note_message_wptp', true);
        if (!$meta || empty($meta)) return;

        $messages = explode('|', $meta);
        if (count($messages) == 0) return;

        foreach ($messages as $message) {
            $message = explode('-', $message);
            $userID = $message[0];
            $messageID = $message[1];
            $this->telegram->deleteMessage($messageID, $userID);
        }
    }

    private function save_message_id_order_note($commentID, $userID, $messageID)
    {
        $meta = get_comment_meta($commentID, 'order_note_message_wptp', true);
        $messages = array();
        if ($meta && !empty($meta))
            $messages = explode('|', $meta);
        $messages[] = $userID . '-' . $messageID;
        $meta = implode('|', $messages);
        update_comment_meta($commentID, 'order_note_message_wptp', $meta);
    }

    /**
     * Send notification to admin users when new order received
     *
     * @param int $order_id
     */
    function admin_new_order_notification($order_id)
    {
        if (!$order_id) return;
        $users = $this->get_users(['Administrator', 'shop_manager']);
        if ($users) {
            $order = wc_get_order($order_id);
            if (!$this->get_option('dont_display_links',0)) {
	            $keyboard  = array(
		            array(
			            array(
				            'text' => '📝',
				            'url'  => admin_url( 'post.php?post=' . $order_id . '&action=edit' )
			            ),
			            array(
				            'text' => '📂',
				            'url'  => admin_url( 'edit.php?post_type=shop_order' )
			            )
		            )
	            );
	            $keyboards = $this->telegram->keyboard( $keyboard, 'inline_keyboard' );
            }

            $text = "*" . __('New Order', $this->plugin_key) . "*\n\n";
            $text .= __('Order number', $this->plugin_key) . ': *' . $order_id . "*\n";
            $text .= __('Status', $this->plugin_key) . ': ' . wc_get_order_status_name($order->get_status()) . "\n";
            $text .= __('Date', $this->plugin_key) . ': ' . HelpersWPTP::localeDate($order->get_date_modified()) . "\n";
            $text .= __('Email', $this->plugin_key) . ': ' . $order->get_billing_email() . "\n";
            $text .= __('Name', $this->plugin_key) . ': *' . $order->get_billing_first_name() . "*\n";
            $text .= __('Phone', $this->plugin_key) . ': ' . $order->get_billing_phone() . "\n";
            if (empty($order->get_shipping_address_2()))
	            $text .= __('Address', $this->plugin_key) . ': *' . $order->get_shipping_address_1() . "*\n";
            else
	            $text .= __('Address', $this->plugin_key) . ': ' . __('Location', $this->plugin_key) . ': '. $order->get_shipping_address_1() . "\n";
            $text .= __('Total price', $this->plugin_key) . ': *' . $this->wc_price($order->get_total()) . "*\n";
            $text .= __('Payment method', $this->plugin_key) . ': ' . $this->get_paysystem($order->get_payment_method())['name'] . "\n";
            $text .= __('Order time', $this->plugin_key) . ': ' .$order->get_customer_note() . "\n";
            $text .= "\n" . __('Items', $this->plugin_key) . ':' . "\n";

            foreach ($order->get_items() as $item_id => $item_data) {
                $product = $item_data->get_product();
                $product_name = $product->get_name();
                $item_quantity = $item_data->get_quantity();
                $item_total = $this->wc_price($item_data->get_total());
                $text .= $product_name . ' × ' . $item_quantity . ' = ' . $item_total . "\n";
            }

            $text = apply_filters('wptelegrampro_wc_new_order_notification_text', $text, $order, $order_id);

            foreach ($users as $user) {
	            $this->telegram->sendMessage( $text, $keyboards, $user['user_id'], 'Markdown' );
                if (!empty($order->get_shipping_address_2()))
                    $this->telegram->sendLocation( unserialize($order->get_shipping_address_2()), null, $user['user_id'] );
            }
        }
    }

    function user_disconnect()
    {
        if (isset($_GET['user-disconnect-wptp']) && $this->disconnect_telegram_wp_user()) {
            $disconnect_message = $this->get_option('telegram_connectivity_disconnect_message', $this->words['profile_disconnect']);
            if (!empty($disconnect_message))
                wc_add_notice($disconnect_message);
        }
    }

    function woocommerce_edit_account()
    {
        if (!$this->get_option('telegram_connectivity', false)) return;
        $user_id = get_current_user_id();
        $bot_user = $this->set_user(array('wp_id' => $user_id));
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <?php if ($bot_user) { ?>
                <?php echo __('Your profile has been linked to this Telegram account:', $this->plugin_key) . ' ' . $bot_user['first_name'] . ' ' . $bot_user['last_name'] . ' <a href="https://t.me/' . $bot_user['username'] . '" target="_blank">@' . $bot_user['username'] . '</a> (<a href="' . $this->get_bot_disconnect_link($user_id) . '">' . __('Disconnect', $this->plugin_key) . '</a>)'; ?>
            <?php } else {
                $code = $this->get_user_random_code($user_id);
                ?>
                <label for="telegram_user_code"><?php _e('Connect to Telegram', $this->plugin_key) ?></label>
                <span class="description"><em><?php _e('Send this code from telegram bot to identify the your user.', $this->plugin_key) ?></em></span>
                <br>
                <input type="text" id="telegram_user_code" class="woocommerce-Input woocommerce-Input--text input-text"
                       value="<?php echo $code ?>"
                       onfocus="this.select();" onmouseup="return false;"
                       readonly> <?php echo __('Or', $this->plugin_key) . ' <a href="' . $this->get_bot_connect_link($user_id) . '" target="_blank">' . __('Request Connect', $this->plugin_key) . '</a>' ?>
            <?php } ?>
        </p>
        <?php
    }

    function default_paysystems($paysystems)
    {
	    $paysystems[] = array(
		    'id'          => 'cash',
		    'name'        => __( "Cash payment 💴", $this->plugin_key ),
		    'description' => __( "Cash payment upon receipt", $this->plugin_key ),
	    );
	    if ($this->get_option('payment_card', 0))
	        $paysystems[] = array(
		    'id'          => 'card',
		    'name'        => __( "Card payment 💳", $this->plugin_key ),
		    'description' => __( "Card payment upon receipt", $this->plugin_key ),
	    );
	    if ($this->get_option('payment_payme_invoice', 0))
		    $paysystems[] = array(
			    'id'          => 'paymeinvoice',
			    'name'        => __( "Payme 💰", $this->plugin_key ),
			    'description' => __( "Payment via Paycom invoice", $this->plugin_key ),
		    );
	    return $paysystems;
    }

    function get_available_paysystems()
    {
       return apply_filters('wctgdeliv_paysystems', array());
    }

    function get_paysystem($id)
    {
	    $paysystems = $this->get_available_paysystems();
	    foreach ($paysystems as $paysystem)
		    if ($paysystem['id'] == $id)
			    return $paysystem;
	    return null;
    }

    function get_paysystem_id($paysystemname)
    {
        $paysystems = $this->get_available_paysystems();
        foreach ($paysystems as $paysystem)
            if ($paysystem['name'] == $paysystemname)
                return $paysystem['id'];
        return null;
    }

    function default_commands($commands)
    {
        $commands = array_merge($commands,
            array(
                'products' => $this->words['products'],
                'product_categories' => $this->words['product_categories'],
                'cart' => $this->words['cart']
            ));
        return $commands;
    }

    function default_keyboard($keyboard)
    {
        $this->words = apply_filters('wptelegrampro_words', $this->words);
        $new_keyboard = array();
        if ($this->get_option('products_button',0))
            $new_keyboard[] = $this->words['products'];
//        if ($this->get_option('categories_button',1)) {
//            if ($this->get_option('restaurant_mode', 0))
//                $new_keyboard[] = $this->words['menu'];
//            else
//                $new_keyboard[] = $this->words['product_categories'];
//        }
	    $new_keyboard[] = $this->words['order'];
//        if ($this->get_option('cart_button',1))
//	        $new_keyboard[] = $this->words['cart'];
//        if ($this->get_option('checkout_orders_in_chat',0))
//            $new_keyboard[] = $this->words['checkout'];
        $keyboard[] = is_rtl() ? array_reverse($new_keyboard) : $new_keyboard;
        return $keyboard;
    }

    function words($words)
    {
        $new_words = array(
            'products' => __('Products 📑', $this->plugin_key),
            'product_categories' => __('Product Categories 📖', $this->plugin_key),
            'select_category' => __('Select Category', $this->plugin_key),
            'cart' => __('Cart 📥', $this->plugin_key),
            'checkout' => __('Checkout 🏁', $this->plugin_key),
            'cart_empty_message' => __('Your cart is empty.', $this->plugin_key),
            'confirm_empty_cart' => __('Empty Cart?', $this->plugin_key),
            'cart_emptied' => __('Cart has been empty.', $this->plugin_key),
            'refresh_cart' => __('Refresh Cart', $this->plugin_key),
            'instock' => __('In stock', $this->plugin_key),
            'outofstock' => __('Out of stock', $this->plugin_key),
            'menu' => __('Menu 🍽', $this->plugin_key),
            'order' => __('🛍 Order', $this->plugin_key),
            'delivery' => __('Delivery 🚛', $this->plugin_key),
            'takeoff' => __('Takeoff 📦', $this->plugin_key),
        );
        $words = array_merge($words, $new_words);

        return $words;
    }

    function query_args($args, $query)
    {
        $product_type_valid = array('simple', 'variable');

        if (!isset($query['p']) && $query['post_type'] == 'product') {
            if ($query['category_id'] !== null)
                $args['tax_query'][] = array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => intval($query['category_id'])
                );
            $args['tax_query'][] = array(
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => $product_type_valid
            );

            // If in Stock
            $args['meta_query'] = array(
                array(
                    'key' => '_stock_status',
                    'value' => 'instock',
                    'compare' => '=',
                ),
            );
        }

        return $args;
    }

    function product_info($item, $product_id, $query)
    {
        if (!is_array($query['post_type']) && $query['post_type'] == 'product' && $this->check_plugin_active('woocommerce')) {
            $product_type = 'simple';
            $args = array(
                'post_type' => 'product_variation',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'orderby' => 'menu_order',
                'order' => 'asc',
                'post_parent' => $product_id
            );
            $variations = get_posts($args);
            $product_variation_id = null;
            if ($variations)
                $product_variation_id = $variations[0]->ID;

            $_product = new \WC_Product($product_id);
            $product_type_ = get_the_terms($product_id, 'product_type');
            if ($product_type_)
                $product_type = $product_type_[0]->slug;

            $item['content'] = $_product->get_description();
            $item['excerpt'] = empty($_product->get_short_description()) ? get_the_excerpt() : $_product->get_short_description();
            $item['title'] = $_product->get_name();
            $dimensions = wc_format_dimensions($_product->get_dimensions(false));
            $price = $_product->get_price();
            $regularprice = $_product->get_regular_price();
            $saleprice = $_product->get_sale_price();
            $average_rating = $_product->get_average_rating();
            // Check Sale Price Dates
            if (!empty($_product->get_date_on_sale_from()) || !empty($_product->get_date_on_sale_to())) {
                if ((!empty($_product->get_date_on_sale_from()) && strtotime($_product->get_date_on_sale_from()) > current_time('U')) ||
                    (!empty($_product->get_date_on_sale_to()) && strtotime($_product->get_date_on_sale_to()) < current_time('U')))
                    $saleprice = null;
            }
            // Get Product Attribute
            $_attributes = array_keys($_product->get_attributes());
            $attributes = array();
            if (count($_attributes)) {
                foreach ($_attributes as $key) {
                    $attributes[$key] = $_product->get_attribute($key);
                }
            }
            $variation = get_post_meta($product_id, '_product_attributes', true);
            $categories = $_product->get_category_ids();
            $galleries = $_product->get_gallery_image_ids();

            $item['tags'] = $this->get_taxonomy_terms('product_tag', $product_id);
            $item['categories'] = $this->get_taxonomy_terms('product_cat', $product_id);

            $product_args = array(
                'slug' => $_product->get_slug(),
                'currency-symbol' => html_entity_decode(get_woocommerce_currency_symbol()),
                'price' => $price,
                'regularprice' => $regularprice,
                'saleprice' => $saleprice,
                'weight' => $_product->get_weight(),
                'width' => $_product->get_width(),
                'height' => $_product->get_height(),
                'length' => $_product->get_length(),
                'dimensions' => $dimensions,
                'sku' => $_product->get_sku(),
                'stock' => $_product->get_stock_quantity(),
                'stock_status' => $_product->get_stock_status(),
                'downloadable' => $_product->get_downloadable(),
                'virtual' => $_product->get_virtual(),
                'sold-individually' => $_product->get_sold_individually(),
                'tax-status' => $_product->get_tax_status(),
                'tax-class' => $_product->get_tax_class(),
                'back-orders' => $_product->get_backorders(),
                'featured' => $_product->get_featured(),
                'visibility' => $_product->get_catalog_visibility(),
                'attributes' => $attributes,
                'variations' => $variation,
                'categories_ids' => $categories,
                'galleries' => $galleries,
                'average_rating' => $average_rating,
                'product_variation_id' => $product_variation_id,
                'product_type' => $product_type
            );

            $item = array_merge($item, $product_args);
        }
        return $item;
    }

    function patterns_tags($tags)
    {
        $tags['WooCommerce'] = array(
            'title' => __('WooCommerce Tags', $this->plugin_key),
            'plugin' => 'woocommerce',
            'tags' => array(
                'currency-symbol' => __('The currency symbol', $this->plugin_key),
                'price' => __('The price of this product', $this->plugin_key),
                'regularprice' => __('The regular price of this product', $this->plugin_key),
                'saleprice' => __('The sale price of this product', $this->plugin_key),
                'width' => __('The width of this product', $this->plugin_key),
                'length' => __('The length of this product', $this->plugin_key),
                'height' => __('The height of this product', $this->plugin_key),
                'weight' => __('The weight of this product', $this->plugin_key),
                'dimensions' => __('The dimensions of this product', $this->plugin_key),
                'average_rating' => __('The average rating of this product', $this->plugin_key),
                'sku' => __('The SKU (Stock Keeping Unit) of this product', $this->plugin_key),
                'downloadable' => __('Is this product downloadable? (Yes or No)', $this->plugin_key),
                'virtual' => __('Is this product virtual? (Yes or No)', $this->plugin_key),
                'sold-individually' => __('Is this product sold individually? (Yes or No)', $this->plugin_key),
                'tax-status' => __('The tax status of this product', $this->plugin_key),
                'tax-class' => __('The tax class of this product', $this->plugin_key),
                'stock' => __('The stock amount of this product', $this->plugin_key),
                'stock-status' => __('The stock status of this product', $this->plugin_key),
                'back-orders' => __('Whether or not backorders allowed?', $this->plugin_key),
                'featured' => __('Is this a featured product? (Yes or No)', $this->plugin_key),
                'visibility' => __('Is this product visible? (Yes or No)', $this->plugin_key)
            )
        );
        return $tags;
    }

    function inline_query($query) {
        $inline_query_id = $query['id'];
        $terms =         $terms = get_terms('product_cat', [
            'hide_empty' => true,
            'orderby' => 'parent',
            'order' => 'DESC',
            'exclude' => $this->get_option('wc_exclude_display_categories')
        ]);
        $product_category_id = 0;
        foreach ($terms as $term)
            if ($term->description == $query['query'])
                $product_category_id = $term->term_id;

        if ($product_category_id > 0) {
            $products = $this->query(array('category_id' => $product_category_id, 'per_page' => 50, 'post_type' => 'product'));

            $items = array();
            foreach ( $products['product'] as $product ) {
                $title = $product['title'];
                $price  = $this->product_price( $product );
                $img = $product['image'];
                $prid = $product['ID'];
                $item = array('type'=>'article', 'id'=>$prid, 'title' => $title, 'description' => $price, 'thumb_url' => $img,
                    'input_message_content' => array('message_text' => 'product_detail_'.$prid));

                $items[] = $item;
            }


        $this->telegram->sendAnswerInlineQuery($inline_query_id, $items, $this->get_option('inline_cache_time', 300));
        }
    }

    function inline_product_detail($usertext) {
        $msg_id = $this->telegram_input['message_id'];
        $chat_id = $this->telegram_input['chat_id'];
        $this->telegram->deleteMessage($msg_id, $chat_id);

        $product_id = intval(end(explode('_', $usertext)));
        if (get_post_status($product_id) === 'publish') {
            $product = $this->query(array('p' => $product_id, 'post_type' => 'product'));
            if ($product['product_type'] == 'variable')
                $product = $this->load_product_variations($product);
            $this->send_product($product);
        } else {
            $this->telegram->answerCallbackQuery(__('The product does not exist', $this->plugin_key));
        }


    }

    function display_product_cats()
    {
        $inline = $this->get_option( 'inline_search', 0 );
        $action_keyboard = array(array(
            array(
                'text' => $this->words['cart']
            ),
            array(
                'text' => $this->words['checkout'],
            )
        ));
        $product_category = $this->get_tax_keyboard('product_category', 'product_cat', 'parent', $this->get_option('wc_exclude_display_categories'), $inline);
        $keyboard = $this->telegram->keyboard(array_merge($action_keyboard, $product_category));
        $this->telegram->sendMessage($this->words['select_category'] . ":", $keyboard);

    }

    function keyboard_response($user_text)
    {
        $words = $this->words;
        $this->words = apply_filters('wptelegrampro_words', $this->words);
        if ($user_text == '/start') {
            $this->update_user( array('cart' => array()));
        } elseif ($user_text == '/products' || $user_text == $words['products']) {
            $this->update_user(array('page' => 1));
            $this->update_user_meta('product_category_id', null);
            $args = array(
                'post_type' => 'product',
                'per_page' => $this->get_option('products_per_page', 1),
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $this->get_option('wc_exclude_categories', []),
                        'operator' => 'NOT IN',
                    )
                )
            );

            $products = $this->query($args);
            if ($this->get_option('simple_display', 0))
                $this->send_products($products,0, __('All products:'));
            else
                $this->send_products($products);

        } elseif ($user_text == '/product_categories' || $user_text == $words['product_categories'] || $user_text == $words['menu']) {
            $this->display_product_cats();
        } elseif ($user_text == '/cart' || $user_text == $words['cart']) {
            $this->cart();
        } elseif ($user_text == '/checkout' || $user_text == $words['checkout']) {
            $this->checkout(0, false);
        } elseif ($user_text == '/order' || $user_text == $words['order']) {
            $this->beginOrder(0, false);
        } elseif (substr($user_text, 0, strlen('product_detail')) === 'product_detail') {
            $this->inline_product_detail($user_text);
        } elseif ($this->get_cart()['order_state'] > 0) {
	        $this->checkout_process_message($user_text, $this->get_cart()['order_state']);
        }
    }

    function inline_keyboard_response($data)
    {
        $this->words = apply_filters('wptelegrampro_words', $this->words);
        $button_data = $data['data'];

        if ($this->button_data_check($button_data, 'product_variation_back')) {
            $button_data = explode('_', $button_data);
            $product = $this->query(array('p' => $button_data['3'], 'post_type' => 'product'));
            $keyboard = $this->product_keyboard($product, $button_data['4']);
            $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
            $this->telegram->editMessageReplyMarkup($keyboards, $button_data['4']);

        } elseif ($this->button_data_check($button_data, 'product_variation_header')) {
            $button_data = explode('_', $button_data);
            $this->telegram->answerCallbackQuery($button_data['5']);
            $product = $this->query(array('p' => $button_data['3'], 'post_type' => 'product'));
            $this->product_keyboard_variations($product, $button_data['5'], $button_data['4']);

        } elseif ($this->button_data_check($button_data, 'product_variation_select')) {
	        $button_data = explode('_', $button_data);
	        $cart = $this->get_cart();
	        $this->telegram->answerCallbackQuery(__('Selected'));
	        $product = $this->query(array('p' => $button_data['3'], 'post_type' => 'product'));
	        $product = $this->load_product_variations($product);
	        if (!isset($cart['items'][$product['ID']]))
	            $cart['items'][$product['ID']] = array();
	        $cart['items'][$product['ID']]['variation_selected'] = $button_data[5];
	        $this->update_user(array('cart' => serialize($cart)));
	        $keyboard = $this->product_keyboard($product, $button_data['4']);
	        $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
	        $this->telegram->editMessageReplyMarkup($keyboards, $button_data['4']);

        } elseif ($this->button_data_check($button_data, 'select_product_variation')) {
            $button_data_ = explode('||', $button_data);
            $button_data = explode('_', $button_data_[0]);
            $taxonomy = isset($button_data_[1]) ? $button_data_[1] : '';
            $product = $this->query(array('p' => $button_data['3'], 'post_type' => 'product'));
            $this->select_product_variation($product, $button_data['5'], $button_data['6'], $button_data['7'], $button_data['4'], $taxonomy);

        } elseif ($this->button_data_check($button_data, 'image_galleries')) {
            $image_send_mode = apply_filters('wptelegrampro_image_send_mode', 'image_path');

            $product_id = intval(end(explode('_', $button_data)));
            if (get_post_status($product_id) === 'publish') {
                $image_size = $this->get_option('image_size');
                $this->telegram->answerCallbackQuery(__('Galleries', $this->plugin_key) . ': ' . get_the_title($product_id));
                $_product = new \WC_Product($product_id);
                $galleries = $_product->get_gallery_image_ids();
                if (is_array($galleries) && count($galleries)) {
                    $keyboards = null;
                    $i = 1;
                    foreach ($galleries as $image_id) {
                        $meta_data = wp_get_attachment_metadata($image_id);
                        if (is_array($meta_data)) {
                            if ($image_send_mode === 'image_path') {
                                $upload_dir = wp_upload_dir();
                                $image_path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $meta_data['file'];
                                if ($image_size != 'full' && isset($meta_data['sizes'][$image_size])) {
                                    $file_name = pathinfo($image_path, PATHINFO_BASENAME);
                                    $image_path = str_replace($file_name, $meta_data['sizes'][$image_size]['file'], $image_path);
                                }
                            } else {
                                $image_path = wp_get_attachment_image_src($image_id, $image_size);
                                $image_path = $image_path[0];
                            }

                            if ($i == count($galleries)) {
                                $keyboard = array(array(
                                    array('text' => __('Back to Product', $this->plugin_key), 'callback_data' => 'product_detail_' . $product_id)
                                ));
                                $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
                            }
                            $this->telegram->sendFile('sendPhoto', $image_path, get_the_title($image_id), $keyboards);
                            $i++;
                        }
                    }
                }
            } else {
                $this->telegram->answerCallbackQuery(__('The product does not exist', $this->plugin_key));
            }

        } elseif ($this->button_data_check($button_data, 'add_to_cart')) {
            $button_data = explode('_', $button_data);
            $add_del = $button_data[5] == '+';
            if (get_post_status($button_data['3']) === 'publish') {
                $in_cart = $this->check_cart($button_data['3']);
                $can_to_cart = $this->can_to_cart($button_data['3']);
                $can_to_cart_ = $this->can_to_cart($button_data['3'], true);
                $alert = false;
                if (!$add_del)
                    $message = __('Remove from Cart:', $this->plugin_key) . ' ' . get_the_title($button_data['3']);
                else
                    if ($can_to_cart)
                        $message = __('Add to Cart:', $this->plugin_key) . ' ' . get_the_title($button_data['3']);
                    else {
                        $message = __('Please select product variations:', $this->plugin_key) . ' ' . $can_to_cart_;
                        $alert = true;
                    }
                $this->telegram->answerCallbackQuery($message, null, $alert);

                // Add or Remove form Cart
                if ($can_to_cart)
                    $this->add_to_cart($button_data['3'], $add_del);

                $product = $this->query(array('p' => $button_data['3'], 'post_type' => 'product'));
	            if ( $product['product_type'] == 'variable' )
	                $product = $this->load_product_variations($product);
	            $keyboard = $this->product_keyboard($product, $button_data['4']);
                $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
                $this->telegram->editMessageReplyMarkup($keyboards, $button_data['4']);
            } else {
                $this->telegram->answerCallbackQuery(__('The product does not exist', $this->plugin_key));
            }

        } elseif ($this->button_data_check($button_data, 'product_detail')) {
            $product_id = intval(end(explode('_', $button_data)));
            if (get_post_status($product_id) === 'publish') {
                $this->telegram->answerCallbackQuery(__('Product', $this->plugin_key) . ': ' . get_the_title($product_id));
                $product = $this->query(array('p' => $product_id, 'post_type' => 'product'));
                if ($product['product_type'] == 'variable')
                    $product = $this->load_product_variations($product);
                $this->send_product($product);
            } else {
                $this->telegram->answerCallbackQuery(__('The product does not exist', $this->plugin_key));
            }

        } elseif ($this->button_data_check($button_data, 'product_page_')) {
            $current_page = intval($this->user['page']) == 0 ? 1 : intval($this->user['page']);
            if ($this->button_data_check($button_data, 'product_page_next'))
                $current_page++;
            elseif ($this->button_data_check($button_data, 'product_page_prev'))
                $current_page--;
            $message_id = intval(end(explode('_', $button_data)));
            $this->update_user(array('page' => $current_page));
            $args = array(
                'category_id' => $this->get_user_meta('product_category_id'),
                'post_type' => 'product',
                'per_page' => $this->get_option('products_per_page', 1),
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $this->get_option('wc_exclude_categories'),
                        'operator' => 'NOT IN',
                    )
                )
            );
            $products = $this->query($args);
	        $this->telegram->answerCallbackQuery(__('Page') . ': ' . $current_page . ' of ' .$products['max_num_pages']);
            $this->send_products($products, $message_id);

        } elseif ($this->button_data_check($button_data, 'product_category')) {
            $this->update_user(array('page' => 1));
            $product_category_id = intval(end(explode('_', $button_data)));
            $this->update_user_meta('product_category_id', $product_category_id);
            $product_category = get_term($product_category_id, 'product_cat');
            if ($product_category) {
                $this->telegram->answerCallbackQuery(__('Category') . ': ' . $product_category->name);
                $products = $this->query(array('category_id' => $product_category_id, 'per_page' => $this->get_option('products_per_page', 1), 'post_type' => 'product'));
                if ($this->get_option('simple_display',0))
                    $this->send_products($products,0, $product_category->name.':');
                else
                    $this->send_products($products);
            } else {
                $this->telegram->answerCallbackQuery(__('Product Category Invalid!', $this->plugin_key));
            }

        } elseif ($this->button_data_check($button_data, 'confirm_empty_cart')) {
            $message_id = intval(end(explode('_', $button_data)));
            $this->telegram->answerCallbackQuery($this->words['confirm_empty_cart']);
            $keyboard = array(array(
                array(
                    'text' => $this->words['yes'],
                    'callback_data' => 'empty_cart_yes_' . $message_id
                ),
                array(
                    'text' => $this->words['no'],
                    'callback_data' => 'empty_cart_no_' . $message_id
                )
            ));
            $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
            $this->telegram->editMessageReplyMarkup($keyboards, $message_id);

        } elseif ($this->button_data_check($button_data, 'empty_cart_no')) {
            $message_id = intval(end(explode('_', $button_data)));
            $this->cart($message_id);

        } elseif ($this->button_data_check($button_data, 'empty_cart_yes')) {
            $message_id = intval(end(explode('_', $button_data)));
            $this->telegram->answerCallbackQuery($this->words['cart_emptied']);
            $this->telegram->editMessageText($this->words['cart_emptied'], $message_id);
            $this->update_user(array('cart' => serialize(array())));

        } elseif ($this->button_data_check($button_data, 'refresh_cart')) {
            $message_id = intval(end(explode('_', $button_data)));
            $this->telegram->answerCallbackQuery($this->words['refresh_cart']);
            $this->cart($message_id, $refresh = true);
        } elseif ($this->button_data_check($button_data, 'process_checkout_')) {
	        $message_id = intval(end(explode('_', $button_data)));
	        $this->checkout($message_id);
        } elseif ($this->button_data_check($button_data, 'display_cart')) {
            $this->cart();
        } elseif ($this->button_data_check($button_data, 'cart_delete')) {
            $button_data_ = explode('_', $button_data);
            $product_id = $button_data_[2];
            $message_id = $button_data_[3];
            $cart = $this->get_cart();
            if (isset($cart['items'][ $product_id ]))
                unset($cart['items'][ $product_id ]);
            $this->update_user( array( 'cart' => serialize( $cart ) ) );
            $this->cart($message_id);
            $this->telegram->answerCallbackQuery($this->words['refresh_cart']);
        }
    }

    function settings_tab($tabs)
    {
        $tabs[$this->tabID] = __('WooCommerce', $this->plugin_key);
        return $tabs;
    }

    function load_product_variations($product)
    {
        $minprice = 99999999999;
        $maxprice = 0;
        $prod = new \WC_Product_Variable($product['ID']);
        $children = $prod->get_children();
        $variations = array();
        foreach ($children as $child_id)
        {
            $child_prod = wc_get_product($child_id);
            $child_var = $prod->get_available_variation($child_prod);
            $variation = array('ID'=>$child_id, 'title'=>reset($child_var['attributes']), 'price'=>$child_var['display_price']);
            if ( $child_var['display_price'] < $minprice)
	            $minprice = $child_var['display_price'];
            if ($child_var['display_price'] > $maxprice )
                $maxprice = $child_var['display_price'];
            $variations[] = $variation;
        }
        if (count($variations)) {
	        $product['product_variations'] = $variations;
	        $product['product_variations_min_price'] = $minprice;
	        $product['product_variations_max_price'] = $maxprice;
        }
        return $product;
    }

    function settings_content()
    {
        $this->options = get_option($this->plugin_key);
        ?>
        <div id="<?php echo $this->tabID ?>-content" class="wptp-tab-content hidden">
            <table>
                <tr>
                    <td>
                        <label for="restaurant_mode"><?php _e('Restaurant mode', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="restaurant_mode"
                                      name="restaurant_mode" <?php checked($this->get_option('restaurant_mode', 0)) ?>> <?php _e('Enabled', $this->plugin_key) ?>
                        </label>

                </tr>
                <tr>
                    <td>
                        <label for="enable_location"><?php _e('Enable location', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="enable_location"
                                      name="enable_location" <?php checked($this->get_option('enable_location', 0)) ?>> <?php _e('Enabled', $this->plugin_key) ?>
                        </label>

                </tr>
                <tr>
                    <td>
                        <label for="opencage_apikey"><?php _e('OpenCage APIKEY', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <input type="text" name="opencage_apikey" id="opencage_apikey"
                               value="<?php echo $this->get_option('opencage_apikey', '') ?>">

                </tr>
                <tr>
                    <td>
                        <label for="prod_keyboard_reply"><?php _e('Products in reply keyboard', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="prod_keyboard_reply"
                                      name="prod_keyboard_reply" <?php checked($this->get_option('prod_keyboard_reply', 0)) ?>> <?php _e('Enabled', $this->plugin_key) ?>
                        </label>

                </tr>
                <tr>
                    <td>
                        <label for="inline_search"><?php _e('Inline search menu', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="inline_search"
                                      name="inline_search" <?php checked($this->get_option('inline_search', 0)) ?>> <?php _e('Enabled', $this->plugin_key) ?>
                        </label>

                </tr>
                <tr>
                    <td>
                        <label for="products_per_page"><?php _e('Inline cache time', $this->plugin_key) ?></label>
                    </td>
                    <td><input type="number" name="inline_cache_time" id="inline_cache_time"
                               value="<?php echo $this->get_option('inline_cache_time', 300) ?>"
                               class="small-text ltr" min="1"></td>
                </tr>
                <tr>
                    <td>
                        <label for="products_per_page"><?php _e('Products Per Page', $this->plugin_key) ?></label>
                    </td>
                    <td><input type="number" name="products_per_page" id="products_per_page"
                               value="<?php echo $this->get_option('products_per_page', $this->per_page) ?>"
                               class="small-text ltr" min="1"></td>
                </tr>
                <tr>
                    <td>
                        <label for="wc_exclude_categories"><?php _e('Exclude Categories', $this->plugin_key) ?></label>
                    </td>
                    <td><?php echo $this->dropdown_categories('wc_exclude_categories[]', 'product_cat', $this->get_option('wc_exclude_categories'), array('blank' => __('None', $this->plugin_key), 'class' => 'multi_select_none_wptp')); ?></td>
                </tr>
                <tr>
                    <td>
                        <label for="wc_exclude_display_categories"><?php _e('Exclude Display Categories', $this->plugin_key) ?></label>
                    </td>
                    <td><?php echo $this->dropdown_categories('wc_exclude_display_categories[]', 'product_cat', $this->get_option('wc_exclude_display_categories'), array('blank' => __('None', $this->plugin_key), 'class' => 'multi_select_none_wptp')); ?></td>
                </tr>
                <tr>
                    <th colspan="2"><?php _e('Notification', $this->plugin_key) ?></th>
                </tr>
                <tr>
                    <td>
                        <?php _e('Administrators', $this->plugin_key); ?>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="wc_admin_new_order_notification"
                                      name="wc_admin_new_order_notification" <?php checked($this->get_option('wc_admin_new_order_notification', 0), 1) ?>> <?php _e('New order', $this->plugin_key) ?>
                        </label><br>
                        <label><input type="checkbox" value="1" id="wc_admin_order_status_notification"
                                      name="wc_admin_order_status_notification" <?php checked($this->get_option('wc_admin_order_status_notification', 0), 1) ?>> <?php _e('Order status change', $this->plugin_key) ?>
                        </label><br>
                        <label><input type="checkbox" value="1" id="wc_admin_product_low_stock_notification"
                                      name="wc_admin_product_low_stock_notification" <?php checked($this->get_option('wc_admin_product_low_stock_notification', 0), 1) ?>> <?php _e('Product low stock', $this->plugin_key) ?>
                        </label><br>
                        <label><input type="checkbox" value="1" id="wc_admin_product_no_stock_notification"
                                      name="wc_admin_product_no_stock_notification" <?php checked($this->get_option('wc_admin_product_no_stock_notification', 0), 1) ?>> <?php _e('Product no stock', $this->plugin_key) ?>
                        </label><br>
                        <label><input type="checkbox" value="1" id="wc_admin_order_note_notification"
                                      name="wc_admin_order_note_notification" <?php checked($this->get_option('wc_admin_order_note_notification', 0), 1) ?>> <?php _e('New order note', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <?php _e('Customers', $this->plugin_key); ?>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="wc_order_status_notification"
                                      name="wc_order_status_notification" <?php checked($this->get_option('wc_order_status_notification', 0), 1) ?>> <?php _e('Order status change', $this->plugin_key) ?>
                        </label><br>
                        <label><input type="checkbox" value="1" id="wc_order_note_customer_notification"
                                      name="wc_order_note_customer_notification" <?php checked($this->get_option('wc_order_note_customer_notification', 0), 1) ?>> <?php _e('New order note', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th colspan="2"><?php _e('Interactive chat', $this->plugin_key) ?></th>
                </tr>
                <tr>
                    <td>
                        <label for="checkout_orders_in_chat"><?php _e('Checkout orders inside chat', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="checkout_orders_in_chat"
                                      name="checkout_orders_in_chat" <?php checked($this->get_option('checkout_orders_in_chat', 0)) ?>> <?php _e('Active', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="dont_display_links"><?php _e('Dont display links in chat ', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="dont_display_links"
                                      name="dont_display_links" <?php checked($this->get_option('dont_display_links', 0)) ?>> <?php _e('Active', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="order_confirm_message"><?php _e('Order confirmation message', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <textarea name="order_confirm_message" id="order_confirm_message" cols="50" class="emoji"
                                  rows="4"><?php echo $this->get_option('order_confirm_message', '') ?></textarea>

                    </td>
                </tr>
                <tr>
                    <th colspan="2"><?php _e('Payments', $this->plugin_key) ?></th>
                </tr>
                <tr>
                    <td>
                        <label for="payment_card"><?php _e('Card payment', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="payment_card"
                                      name="payment_card" <?php checked($this->get_option('payment_card', 0)) ?>> <?php _e('Active', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="payment_payme_invoice"><?php _e('Paycom Invoice payment', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="payment_payme_invoice"
                                      name="payment_payme_invoice" <?php checked($this->get_option('payment_payme_invoice', 0)) ?>> <?php _e('Active', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th colspan="2"><?php _e('Empty the cart', $this->plugin_key) ?></th>
                </tr>
                <tr>
                    <td>
                        <label for="empty_cart_after_wc_redirect"><?php _e('After Redirect to Cart Page', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="empty_cart_after_wc_redirect"
                                      name="empty_cart_after_wc_redirect" <?php checked($this->get_option('empty_cart_after_wc_redirect'), 1) ?>> <?php _e('Active', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="empty_wc_cart_before_redirect"><?php _e('Empty WC cart before redirect', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="empty_wc_cart_before_redirect"
                                      name="empty_wc_cart_before_redirect" <?php checked($this->get_option('empty_wc_cart_before_redirect',0)) ?>> <?php _e('Active', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="empty_cart_after_wc_payment_complete"><?php _e('After Payment Complete', $this->plugin_key) ?></label>
                    </td>
                    <td>
                        <label><input type="checkbox" value="1" id="empty_cart_after_wc_payment_complete"
                                      name="empty_cart_after_wc_payment_complete" <?php checked($this->get_option('empty_cart_after_wc_payment_complete'), 1) ?>> <?php _e('Active', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th colspan="2"><?php _e('Display', $this->plugin_key) ?></th>
                </tr>
                <tr>
                    <td><?php _e('Meta Data', $this->plugin_key) ?></td>
                    <td>
                        <label><input type="checkbox" value="1"
                                      name="weight_display" <?php checked($this->get_option('weight_display'), 1) ?>><?php _e('Weight', $this->plugin_key) ?>
                        </label>
                        <label><input type="checkbox" value="1"
                                      name="dimensions_display" <?php checked($this->get_option('dimensions_display'), 1) ?>><?php _e('Dimensions', $this->plugin_key) ?>
                        </label>
                        <label><input type="checkbox" value="1"
                                      name="attributes_display" <?php checked($this->get_option('attributes_display'), 1) ?>><?php _e('Attributes', $this->plugin_key) ?>
                        </label>
                        <label><input type="checkbox" value="1"
                                      name="rating_display" <?php checked($this->get_option('rating_display'), 1) ?>><?php _e('Rating', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td><?php _e('Keyboard', $this->plugin_key) ?></td>
                    <td>
                        <label><input type="checkbox" value="1"
                                      name="gallery_keyboard" <?php checked($this->get_option('gallery_keyboard'), 1) ?>><?php _e('Gallery Button', $this->plugin_key) ?>
                        </label>
                        <label><input type="checkbox" value="1"
                                      name="category_keyboard" <?php checked($this->get_option('category_keyboard'), 1) ?>><?php _e('Category Buttons', $this->plugin_key) ?>
                        </label>
                        <label><input type="checkbox" value="1"
                                      name="products_button" <?php checked($this->get_option('products_button',0)) ?>><?php _e('All products Button', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td><?php _e('Products', $this->plugin_key) ?></td>
                    <td>
                        <label><input type="checkbox" value="1"
                                      name="simple_display" <?php checked($this->get_option('simple_display',0)) ?>><?php _e('Simple list', $this->plugin_key) ?>
                        </label>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    function product_keyboard($product, $message_id)
    {
        /*$terms = get_the_terms($product['ID'], 'product_type');
        if ($terms) {
            $product_type = $terms[0]->slug;
        }*/

        $selected_variation = $this->get_selected_variation($product['ID']);
        if (count($product['product_variations']))
            $in_cart = $this->check_cart($product['ID'], $selected_variation);
        else
            $in_cart = $this->check_cart($product['ID']);
        $txtincart = $in_cart>0?$in_cart:"";
        $keybuttons = array();
        if ($this->get_option('dont_display_links',0) != 1)
            $keybuttons[] = array('text' => '🔗️', 'url' => $product['link']);
//        $keybuttons[] = array('text' => __("⬅️", $this->plugin_key), 'callback_data' => 'product_page_current');
//        $keybuttons[] = array('text' => '➕', 'callback_data' => 'add_to_cart_' . $product['ID'] . '_' . $message_id.'_+');
        $keybuttons[] = array('text' => $txtincart.' 🛒', 'callback_data' => 'display_cart');
//        $keybuttons[] = array('text' => '➖', 'callback_data' => 'add_to_cart_' . $product['ID'] . '_' . $message_id.'_-');
        $keyboard = array($keybuttons);

        // Gallery Emoji Button
        if ($this->get_option('gallery_keyboard') == 1 && is_array($product['galleries']) && count($product['galleries'])) {
            $keyboard[0][] = array('text' => '🖼️', 'callback_data' => 'image_galleries_' . $product['ID']);
        }

        // Variations
        if (is_array($product['product_variations']) && count($product['product_variations'])) {
            $terms_r = $terms_d = $temps = array();
            foreach ($product['product_variations'] as $variation) {
                $var_head = $variation['title'];
                $temps[] = $var_head.' - '.$variation['price'];
            }

            $max_lengths = max(array_map('mb_strlen', $temps));
            $columns = $this->keyboard_columns($max_lengths, count($temps));
            $c = 1;
            foreach ($product['product_variations'] as $variation) {
                $terms_d[] = array(
                    'text' => ($selected_variation == $variation['ID'] ? '✔️ ':'') . ucwords($variation['title'].' - '.$variation['price']),
                    'callback_data' => 'product_variation_select_' . $product['ID'] . '_' . $message_id . '_' . $variation['ID'],
                );
                if ($c % $columns == 0) {
                    $terms_r[] = $terms_d;
                    $terms_d = array();
                }
                $c++;
            }
            if (count($terms_d))
                $terms_r[] = $terms_d;
            $keyboard = array_merge($keyboard, $terms_r);
        }

        // Category Button
        if ($this->get_option('category_keyboard') == 1 && is_array($product['categories_ids']) && count($product['categories_ids'])) {
            //$max_lengths = max(array_map('strlen', count($product['categories_ids'])));
            //$columns = $this->keyboard_columns($max_lengths, count($product['categories_ids']));
            $terms_r = $terms_d = array();
            $c = 1;
            $exclude = $this->get_option('wc_exclude_display_categories');
            foreach ($product['categories_ids'] as $category) {
                if (in_array(intval($category), $exclude))
                    continue;
                $term = get_term(intval($category));
                $terms_d[] = array(
                    'text' => '📁 ' . $term->name,
                    'callback_data' => 'product_category_' . $term->term_id
                );
                if ($c % 3 == 0) {
                    $terms_r[] = $terms_d;
                    $terms_d = array();
                }
                $c++;
            }
            if (count($terms_d))
                $terms_r[] = $terms_d;

            $keyboard = array_merge($keyboard, $terms_r);
        }

        return $keyboard;
    }


    function select_product_variation($product, $variation_type, $variation_name, $variation_value, $message_id, $taxonomy)
    {
        if ($variation_type == 'text')
            $this->telegram->answerCallbackQuery(__('Select', $this->plugin_key) . ' ' . $variation_name . ': ' . $variation_value);
        elseif ($variation_type == 'tax' && !empty($taxonomy)) {
            $term = get_term_by('slug', $variation_value, $taxonomy);
            if ($term)
                $this->telegram->answerCallbackQuery(__('Select', $this->plugin_key) . ' ' . $variation_name . ': ' . $term->name);
        }
        $this->add_to_cart($product['ID'], null, $variation_name, $variation_value);
        //$this->product_keyboard_variations($product, $variation_name, $message_id);
        $keyboard = $this->product_keyboard($product, $message_id);
        $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
        $this->telegram->editMessageReplyMarkup($keyboards, $message_id);
    }

    function product_keyboard_variations($product, $variation_name, $message_id)
    {
        $keyboard = array();
        $attributes = wc_get_product_variation_attributes($product['product_variation_id']);
        if (is_array($product['variations']) && count($product['variations'])) {
            foreach ($product['variations'] as $name => $variation) {
                if ($variation['is_variation'] != 1)
                    continue;
                $var_head = urldecode($name);
                if ($variation['is_taxonomy'] == 1) {
                    $tax = get_taxonomy($var_head);
                    $var_head = $tax->labels->singular_name;
                }

                if ($var_head != $variation_name)
                    continue;

                $in_cart = $this->check_cart($product['ID'], $var_head);

                $c = 1;
                // is custom variation
                if ($variation['is_taxonomy'] == 0 && !empty($variation['value'])) {
                    $items = explode('|', $variation['value']);
                    $items = array_map('urldecode', array_map('trim', $items));
                    $terms_r = $terms_d = array();
                    $max_lengths = max(array_map('mb_strlen', $items));
                    $columns = $this->keyboard_columns($max_lengths, count($items));

                    foreach ($items as $item) {
                        if ($attributes) {
                            $attributes_ = array_keys($attributes);
                            if (in_array('attribute_' . $name, $attributes_)) {
                                $value = get_post_meta($product['product_variation_id'], 'attribute_' . $name, true);
                                if (!empty($value) && $value != $item)
                                    continue;
                            }
                        }

                        $terms_d[] = array(
                            'text' => ($in_cart === $item ? '✔️ ' : '') . $item,
                            'callback_data' => 'select_product_variation_' . $product['ID'] . '_' . $message_id . '_text_' . $var_head . '_' . $item
                        );
                        if ($c % $columns == 0) {
                            $terms_r[] = $terms_d;
                            $terms_d = array();
                        }
                        $c++;
                    }
                    if (count($terms_d))
                        $terms_r[] = $terms_d;
                    $keyboard = array_merge($keyboard, $terms_r);
                    // is taxonomy variation
                } elseif ($variation['is_taxonomy'] == 1) {
                    $terms = get_the_terms($product['ID'], $variation['name']);
                    if ($terms) {
                        $temps = array();
                        foreach ($terms as $term)
                            $temps[] = $term->name;
                        $max_lengths = max(array_map('mb_strlen', $temps));
                        $columns = $this->keyboard_columns($max_lengths, count($terms));
                        $terms_r = $terms_d = array();
                        /*if ($first) {
                            $terms_d[] = array(
                                'text' => '🔙',
                                'callback_data' => 'product_variation_back_' . $product['ID'] . '_' . $message_id
                            );
                            $first = false;
                        }*/
                        foreach ($terms as $term) {
                            $terms_d[] = array(
                                'text' => ($in_cart == $term->slug ? '✔️ ' : '') . $term->name,
                                'callback_data' => 'select_product_variation_' . $product['ID'] . '_' . $message_id . '_tax_' . $var_head . '_' . $term->slug . '||' . $variation['name']
                            );
                            if ($c % $columns == 0) {
                                $terms_r[] = $terms_d;
                                $terms_d = array();
                            }
                            $c++;
                        }
                        if (count($terms_d))
                            $terms_r[] = $terms_d;

                        $keyboard = array_merge($keyboard, $terms_r);
                    }
                }

                break;
            }
        }

        if (count($keyboard)) {
            $keyboard[][] = array(
                'text' => '🔙',
                'callback_data' => 'product_variation_back_' . $product['ID'] . '_' . $message_id
            );
            $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
            $this->telegram->editMessageReplyMarkup($keyboards, $message_id);
        }
    }

    function add_to_cart($product_id, $add = null, $variation_key = null, $variation_value = null, $quantity = 1)
    {
        $cart = $this->get_cart();

	    if ( ! isset( $cart['items'][ $product_id ] ) ) {
		    $cart['items'][ $product_id ] = array();
	    }

	    $product = $this->query( array( 'p' => $product_id, 'post_type' => 'product' ) );
	    if ( $product['product_type'] == 'variable' ) {
		    $selected_variation = $cart['items'][ $product_id ]['variation_selected'];
		    if ( $add ) {
			    $cart['items'][ $product_id ]['variations'][ $selected_variation ] += $quantity;
		    } else {
			    $cart['items'][ $product_id ]['variations'][ $selected_variation ] -= $quantity;
		    }
		    if ( $cart['items'][ $product_id ]['variations'][ $selected_variation ] < 0 ) {
			    $cart['items'][ $product_id ]['variations'][ $selected_variation ] = 0;
		    }
	    } else {
		    if ( is_bool( $add ) === true ) {
			    if ( $add ) {
				    $cart['items'][ $product_id ]['count'] += $quantity;
			    } else {
				    $cart['items'][ $product_id ]['count'] -= $quantity;
			    }
		    }
		    if ( $cart['items'][ $product_id ]['count'] < 0 ) {
			    $cart['items'][ $product_id ]['count'] = 0;
		    }
	    }
	    $this->update_user( array( 'cart' => serialize( $cart ) ) );
	    return $cart;
    }

    function get_cart()
    {
        $cart = $this->user['cart'];
        if (empty($cart)) {
	        $cart = array();
	        $cart['items'] = array();
	        $cart['order_state'] = 0;
        }
        else
            $cart = unserialize($cart);
        return $cart;
    }

    function can_to_cart($product_id, $return_var = false)
    {
        $cart = $this->get_cart();
        $product = $this->query(array('p' => $product_id, 'post_type' => 'product'));
        if ($product) {
            if ($product['product_type'] == 'variable') {
                if (isset($cart['items'][$product_id]['variation_selected'])) {
                    return $cart['items'][$product_id]['variation_selected'] > 0;
                }
                else
                    return false;
            } else
                return true;
        }
        return false;
    }

    function check_cart($product_id, $variation_key = null)
    {
        $cart = $this->get_cart();
        if (isset($cart['items'][$product_id])) {
            if ($variation_key !== null) {
                if (isset($cart['items'][$product_id]['variations'][$variation_key]))
                    return $cart['items'][$product_id]['variations'][$variation_key];
            } else {
                    return $cart['items'][$product_id]['count'];
            }
        }
        return 0;
    }

    function get_selected_variation($product_id)
    {
        $cart = $this->get_cart();
        if (isset($cart['items'][$product_id]['variation_selected']))
            return $cart['items'][$product_id]['variation_selected'];
        return 0;
    }

    function send_products($products, $message_id = 0, $caption=null)
    {
        $simple_mode = $this->get_option('simple_display',0);
        if (count($products['product'])) {
            $image_send_mode = apply_filters('wptelegrampro_image_send_mode', 'image_path');

            $this->words = apply_filters('wptelegrampro_words', $this->words);
            $keyboard = $this->default_products_keyboard;
            $i = 1;
            $current_page = $this->user['page'];
	        if ( $simple_mode ) {
		        if ( $caption == null ) {
			        $caption = __( 'Selected products:' );
		        }
		        $keyboard = array(array(array('text' => $this->words['back']), array('text' => $this->words['cart'])));
		        $columns  = 2;
		        $temp     = array();
		        foreach ( $products['product'] as $product ) {
//			        $price  = $this->product_price( $product );
			        $text   = $product['title'];
			        $temp[] = array( 'text' => $text, 'callback_data' => 'product_detail_' . $product['ID'] );
			        if ( $i % $columns == 0 ) {
				        $keyboard[] = $temp;
				        $temp       = array();
			        }
			        $i ++;
		        }
		        if ( count( $temp ) ) {
			        $keyboard[] = $temp;
		        }
		        $keyboards = $this->telegram->keyboard( $keyboard, 'inline_keyboard' );
		        if ( $message_id == 0 ) {
			        $this->telegram->sendMessage( $caption, $keyboards );
		        }
		        if ( $products['max_num_pages'] > 1 ) {
			        if ( $message_id == 0 ) {
				        $message_id = $this->telegram->get_last_result()['result']['message_id'];
			        }
			        $keyboardn = array();
			        if ( $current_page > 1 ) {
				        $keyboardn[] = array( 'text'          => $this->words['prev_page'],
				                              'callback_data' => 'product_page_prev_' . $message_id
				        );
			        }
			        if ( $current_page < $products['max_num_pages'] ) {
				        $keyboardn[] = array( 'text'          => $this->words['next_page'],
				                              'callback_data' => 'product_page_next_' . $message_id
				        );
			        }
			        if ( is_rtl() ) {
				        $keyboardn = array_reverse( $keyboardn );
			        }
			        $keyboard[] = $keyboardn;
			        $keyboards  = $this->telegram->keyboard( $keyboard );
			        $this->telegram->sendMessage( $caption, $keyboards );
		        } else {
                    $keyboards = $this->telegram->keyboard( $keyboard );
                    $this->telegram->sendMessage( $caption, $keyboards );
                }

	        }
            else
            foreach ($products['product'] as $product) {
                $price = $this->product_price($product);
                $text = $product['title'] . "\n" . $price . "\n" . $product['excerpt'];
                $keyboard[0][0]['callback_data'] = 'product_detail_' . $product['ID'];
                if ($products['max_num_pages'] > 1 && $i == count($products['product'])) {
                    $keyboard[1] = array();
                    if ($current_page > 1)
                        $keyboard[1][] = array('text' => $this->words['prev_page'], 'callback_data' => 'product_page_prev');
                    if ($current_page < $products['max_num_pages'])
                        $keyboard[1][] = array('text' => $this->words['next_page'], 'callback_data' => 'product_page_next');
                    if (is_rtl())
                        $keyboard[1] = array_reverse($keyboard[1]);
                }
                $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
                if ($product[$image_send_mode] !== null) {
                    $this->telegram->sendFile('sendPhoto', $product[$image_send_mode], $text, $keyboards);
                } else
                    $this->telegram->sendMessage($text, $keyboards);
                $i++;
            }
        } else {
            $this->telegram->sendMessage(__('Your request without result!', $this->plugin_key));
        }
    }

    function send_product($product)
    {
        $image_send_mode = apply_filters('wptelegrampro_image_send_mode', 'image_path');
        $price = $this->product_price($product);
        $add_info = '';
        $metas = array();

        // Weight
        if ($this->get_option('weight_display') == 1 && !empty($product['weight']))
            $metas[] = __('Weight', $this->plugin_key) . ': ' . $product['weight'] . ' ' . get_option('woocommerce_weight_unit');

        // Dimensions
        if ($this->get_option('dimensions_display') == 1 && !empty($product['dimensions']) && $product['dimensions'] != __('N/A', 'woocommerce'))
            $metas[] = __('Dimensions', $this->plugin_key) . ': ' . $product['dimensions'];

        // Attribute
        if ($this->get_option('attributes_display') == 1 && is_array($product['variations']) && count($product['variations'])) {
            foreach ($product['variations'] as $name => $variation) {
                if ($variation['is_visible'] == 0 || empty($variation['value']))
                    continue;
                $var_head = urldecode($name);
                if ($variation['is_variation'] == 1 && $variation['is_taxonomy'] == 1) {
                    $tax = get_taxonomy($var_head);
                    $var_head = $tax->labels->singular_name;
                }
                $items = array();
                if ($variation['is_taxonomy'] == 0) {
                    $items = array_map('urldecode', array_map('trim', explode('|', $variation['value'])));

                } elseif ($variation['is_taxonomy'] == 1) {
                    $terms = get_the_terms($product['ID'], $variation['name']);
                    foreach ($terms as $term)
                        $items[] = $term->name;
                }
                $items = implode(', ', $items);
                $metas[] = $var_head . ': ' . $items;
            }
        }

        if ($this->get_option('rating_display') == 1 && !empty($product['average_rating']) && intval($product['average_rating']) > 0) {
            $star = '';
            for ($i = 1; $i <= intval($product['average_rating']); $i++)
                $star .= "⭐️"; // star ✰
            $metas[] = $star;
        }

        if (count($metas))
            $add_info = "\n" . implode(' / ', $metas);

        $text = $product['title'] . "\n" . $price . $add_info . "\n" . strip_tags($product['content']);

        if ($product[$image_send_mode] !== null)
            $this->telegram->sendFile('sendPhoto', $product[$image_send_mode], $text);
        else
            $this->telegram->sendMessage($text);

        // Keyboard
        $message_id = $this->telegram->get_last_result()['result']['message_id'];
        $keyboard = $this->product_keyboard($product, $message_id);
        $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
        $this->telegram->editMessageReplyMarkup($keyboards, $message_id);

        $numkeyboard = array (
            array(
                array('text' => '1'),
                array('text' => '2'),
                array('text' => '3')
            ),
            array(
                array('text' => '4'),
                array('text' => '5'),
                array('text' => '6'),
            ),
            array(
                array('text' => '7'),
                array('text' => '8'),
                array('text' => '9')
            ),
            array(
                array('text' => $this->words['back']),
                array('text' => $this->words['cart'])
            )
        );

        $keyboard = $this->telegram->keyboard($numkeyboard);
        $this->telegram->sendMessage(__('Select or input quantity', $this->plugin_key), $keyboard);
    }

    function product_price($product, $variation = 0)
    {
        $price = (!empty($product['saleprice']) ? $product['saleprice'] : $product['price']);
        if ($product['product_type'] == 'variable') {
            if (!isset($product['product_variations']))
                $product = $this->load_product_variations($product);
	        if ($variation)
            {
                foreach ($product['product_variations'] as $prodvar)
                    if ($prodvar['ID'] == $variation)
                        $price = $prodvar['price'];
            }
	        else
		        $price = $product['product_variations_min_price'].'-'.$product['product_variations_max_price'];
        }
        $price = !empty($price) ? $price :0;
        return $price;
    }

    function wc_price($price)
    {
        return strip_tags(html_entity_decode(wc_price($price)));
    }

    function checkout_request_phone() {
	    $contact_keyboard = array(
		    array(
			    array(
				    'text'            => __( 'Send contact 📲', $this->plugin_key ),
				    'request_contact' => true,
			    )
		    )
	    );
	    $contact_keyboard = $this->telegram->keyboard( $contact_keyboard );
	    $this->telegram->sendMessage( __( 'Input your phone or send your contact', $this->plugin_key ), $contact_keyboard );
    }

    function checkout_request_ordertype() {
	    $ordertype_keyboard = array(
		    array(
			    array(
				    'text'            => $this->words['delivery']
			    ),
			    array(
				    'text'            => $this->words['takeoff']
			    )
		    )
	    );
	    $ordertype_keyboard = $this->telegram->keyboard( $ordertype_keyboard );
	    $this->telegram->sendMessage( __( 'Select order type: Takeoff or Delivery', $this->plugin_key ), $ordertype_keyboard );
    }

    function checkout_request_ordertime(){
	    $ordertime_keyboard = array(
		    array(
			    array(
				    'text'            => __( 'As soon as possible', $this->plugin_key )
			    ),
			    array(
				    'text'            => __( 'In a hour', $this->plugin_key )
			    ),
                array(
				    'text'            => __( 'In a 3 hours', $this->plugin_key )
			    ),
			    array(
				    'text'            => __( 'Tomorrow', $this->plugin_key )
			    )
		    )
	    );
	    $ordertime_keyboard = $this->telegram->keyboard( $ordertime_keyboard );
	    $this->telegram->sendMessage( __( 'Select time for your order, or type your preffered time', $this->plugin_key ), $ordertime_keyboard );
    }

    function checkout_request_location() {
	    if ( $this->get_option( 'enable_location', 0 ) ) {
		    $location_keyboard = array(
			    array(
				    array(
					    'text'             => __( 'Send location 📍', $this->plugin_key ),
					    'request_location' => true,
				    )
			    )
		    );
		    $location_keyboard = $this->telegram->keyboard( $location_keyboard );
		    $this->telegram->sendMessage( __( 'Input your address or send location', $this->plugin_key ), $location_keyboard );
	    } else {
		    $default_keyboard = apply_filters( 'wptelegrampro_default_keyboard', array() );
		    $default_keyboard = $this->telegram->keyboard( $default_keyboard );
		    $this->telegram->sendMessage( __( 'Input your address', $this->plugin_key ), $default_keyboard );
	    }
    }

    function checkout_request_paysystem() {
	    $paysystems       = $this->get_available_paysystems();
	    $payment_keyboard = array();
	    $temp_row         = array();
	    $c                = 1;
	    foreach ( $paysystems as $paysystem ) {
		    $temp_row[] = $paysystem['name'];
		    if ( $c % 2 == 0 ) {
			    $payment_keyboard[] = $temp_row;
			    $temp_row           = array();
		    }
	    }
	    if ( count( $temp_row ) ) {
		    $payment_keyboard[] = $temp_row;
	    }

	    $payment_keyboard = $this->telegram->keyboard( $payment_keyboard );
	    $this->telegram->sendMessage( __( 'Select payment method', $this->plugin_key ), $payment_keyboard );
    }

    function checkout_request_confirm()
    {
        global $woocommerce;
        $cart = $this->get_cart();
        $text = __('Your order:', $this->plugin_key) ."\n";

        $order   = wc_create_order();
        foreach ( $cart['items'] as $product_id => $item ) {
            if ( $item['count'] > 0 ) {
                $order->add_product( wc_get_product( $product_id ), $item['count'] );
            } elseif ( isset( $item['variations'] ) ) {
                foreach ( $item['variations'] as $variation_id => $variation ) {
                    $order->add_product( wc_get_product( $variation_id ), $variation );
                }
            }
        }

        $paysystem = $this->get_paysystem($cart['paysystem']);
        $order->set_payment_method($paysystem['name']);
        $order->calculate_totals();

        $text .= __('Name', $this->plugin_key) . ': *' . $cart['name'] . "*\n";
        $text .= __('Phone', $this->plugin_key) . ': *' . $cart['phone'] . "*\n";
        if (empty($cart['address2']))
            $text .= __('Address', $this->plugin_key) . ': *' . $cart['address1'] . "*\n";
        else
            $text .= __('Address', $this->plugin_key) . ': ' . __('Location', $this->plugin_key) . ': '.$cart['address1'] . "\n";
        $text .= __('Total price', $this->plugin_key) . ': *' . $this->wc_price($order->get_total()) . "*\n";
        $text .= __('Payment method', $this->plugin_key) . ': ' . $this->get_paysystem($cart['paysystem'])['name'] . "\n";
        $text .= __('Order time', $this->plugin_key) . ': *' .$cart['ordertime'] . "*\n";
        $text .= "\n" . __('Items', $this->plugin_key) . ':' . "\n";

        foreach ($order->get_items() as $item_id => $item_data) {
            $product = $item_data->get_product();
            $product_name = $product->get_name();
            $item_quantity = $item_data->get_quantity();
            $item_total = $this->wc_price($item_data->get_total());
            $text .= $product_name . ' × ' . $item_quantity . ' = ' . $item_total . "\n";
        }

        $text .= "*".__('Do you confirm your order?', $this->plugin_key)."*";
        $text .= "\n" . $this->get_option('order_confirm_message', '');
        $confirm_keyboard = array(
            array(
                array(
                    'text' => $this->words['yes']
                ),
                array(
                    'text' => $this->words['no']
                )
            )
        );
        $confirm_keyboard = $this->telegram->keyboard($confirm_keyboard);
        $this->telegram->sendMessage($text, $confirm_keyboard, null, 'Markdown' );

    }

    function checkout_process_message( $message, $state ) {
		global $woocommerce;
		$cart = $this->get_cart();
        if ($cart['order_state'] == 1) {
            if ($message == $this->words['delivery']) {
                $this->checkout_request_location();
                $cart['order_state'] = 2;
            } else if ($message == $this->words['takeoff']) {
                $cart['order_state'] = 4;
                $this->checkout_request_phone();
            } else
                $this->checkout_request_ordertype();
        } elseif ($cart['order_state'] == 2) {
            if (is_array($message)) {
                $cart['address1'] = $this->openCageGetAddress($message['latitude'], $message['longitude']);
                $cart['address2'] = serialize($message);
//                $cart['address2'] = 'https://www.google.ru/maps/@' . $message['latitude'] . ',' . $message['longitude'] . ',15z';
            } else {
                $cart['address1'] = $message;
                $cart['address2'] = '';
            }
            $confirm_keyboard = array(
                array(
                    array(
                        'text'            => $this->words['yes']
                    ),
                    array(
                        'text'            => $this->words['no']
                    )
                )
            );
            $confirm_keyboard = $this->telegram->keyboard($confirm_keyboard);
            $this->telegram->sendMessage(__('Your address: ', $this->plugin_key).$cart['address1']."\n".__('Is it correct?', $this->plugin_key), $confirm_keyboard);
            $cart['order_state'] = 3;
        } elseif ($cart['order_state'] == 3) {
            if ($message == $this->words['yes']) {
                $cart['order_state'] = 4;
                $this->checkout_request_phone();
            } else
            {
                $this->checkout_request_location();
                $cart['order_state'] = 2;
            }
        } elseif ($cart['order_state'] == 4) {
            if (is_array($message))
            {
                $cart['phone'] = $message['phone_number'];
            }
            else
                $cart['phone'] = $message;
            $cart['order_state'] = 11;
            $this->display_product_cats();
        } elseif ($cart['order_state'] == 11) {
            $terms =         $terms = get_terms('product_cat', [
                    'name' => $message,
                'hide_empty' => true,
                'orderby' => 'parent',
                'order' => 'DESC',
                'exclude' => $this->get_option('wc_exclude_display_categories')
            ]);
            $product_category_id = 0;
            $product_category = null;
            if (sizeof($terms) > 0)
            {
                $product_category = $terms[0];
                $product_category_id = $terms[0]->term_id;
            }

            if ($product_category) {
                $cart['order_state'] = 13;
                $this->user['page'] = 1;
                $this->update_user(array('page' => $this->user['page']));
                $this->update_user_meta('product_category_id', $product_category_id);
                $products = $this->query(array('category_id' => $product_category_id, 'per_page' => $this->get_option('products_per_page', 1), 'post_type' => 'product'));
                if ($this->get_option('simple_display',0))
                    $this->send_products($products,0, $product_category->name.':');
                else
                    $this->send_products($products);
            } else {
                $this->telegram->sendMessage(__('Product Category Invalid!', $this->plugin_key));
            }
        } elseif ($cart['order_state'] == 13) {
            $product_category_id = $this->get_user_meta('product_category_id');

            if ($this->words['next_page'] == $message || $this->words['prev_page'] == $message) {
                if ($this->words['next_page'] == $message) {
                    $this->user['page']++;
                } elseif ($this->words['prev_page'] == $message) {
                    $this->user['page']--;
                }
                if ($this->user['page'] < 1)
                    $this->user['page'] = 1;
                $this->update_user(array('page' => $this->user['page']));
                $this->display_category($product_category_id);
            } elseif ($this->words['back'] == $message)
            {
                $cart['order_state'] = 11;
                $this->display_product_cats();
            } else {

                $product = null;
                $productfind = $this->query(array('title' => $message, 'category_id' => $product_category_id, 'per_page' => 1000, 'post_type' => 'product'), 1);

                if (isset($productfind['product']) && isset($productfind['product'][0]))
                    $product = $productfind['product'][0];
                if ($product) {
                    if ($product['product_type'] == 'variable')
                        $product = $this->load_product_variations($product);
                    $this->update_user_meta('product_id', $product['ID']);
                    $this->send_product($product);
                    $cart['order_state'] = 15;
                } else {
                    $this->telegram->sendMessage(__('The product does not exist', $this->plugin_key));
                }


            }
        } elseif ($cart['order_state'] == 15) {
            if ($this->words['back'] == $message) {
                $cart['order_state'] = 13;
                $this->display_category($this->get_user_meta('product_category_id'));
            } elseif (intval($message) > 0) {
                $quaadd = intval($message);
                $prod_id = $this->get_user_meta('product_id');

                $can_to_cart = $this->can_to_cart($prod_id);
                if ($can_to_cart) {
                    $cart = $this->add_to_cart($prod_id, true, null, null, $quaadd);
                    $cart['order_state'] = 13;
                    $this->display_category($this->get_user_meta('product_category_id'), __('Continue order? ', $this->plugin_key));
                }
                else {
                    $this->telegram->sendMessage( __('Please select product variations:', $this->plugin_key));
                    $alert = true;
                }
            }
        } elseif ($cart['order_state'] == 20) {
            $cart['ordertime'] = $message;
            $this->checkout_request_paysystem();
            $cart['order_state'] = 23;
        } elseif ($cart['order_state'] == 23) {
            $paysystem_id = $this->get_paysystem_id($message);
            if ($paysystem_id) {
                $cart['paysystem'] = $paysystem_id;
                $this->checkout_request_confirm();
                $cart['order_state'] = 29;
            }
        } elseif ($cart['order_state'] == 29) {
                if ($message == $this->words['yes']) {

                $paysystem_id = $cart['paysystem'];
                $address = array(
                    'first_name' => $cart['name'],
                    'last_name' => '',
                    'company' => '',
                    'email' => '',
                    'phone' => $cart['phone'],
                    'address_1' => $cart['address1'],
                    'address_2' => $cart['address2'],
                    'city' => '',
                    'state' => '',
                    'postcode' => '',
                    'country' => ''
                );
                $order = wc_create_order();
                $order->set_address($address, 'billing');
                $order->set_address($address, 'shipping');
                $order->set_customer_note($cart['ordertime']);
                foreach ($cart['items'] as $product_id => $item) {
                    if ($item['count'] > 0) {
                        $order->add_product(wc_get_product($product_id), $item['count']);
                    } elseif (isset($item['variations'])) {
                        foreach ($item['variations'] as $variation_id => $variation) {
                            $order->add_product(wc_get_product($variation_id), $variation);
                        }
                    }
                }
                $order->set_payment_method($paysystem_id);
                $order->calculate_totals();
                $order->save();
                $default_keyboard = apply_filters('wptelegrampro_default_keyboard', array());
                $default_keyboard = $this->telegram->keyboard($default_keyboard);

                $this->telegram->sendMessage(sprintf(__('Thank you. Your order has been placed. Order number #%s We will contact You as soon as possible', $this->plugin_key), $order->get_id()), $default_keyboard);
                do_action('wctgdeliv_request_payment', $paysystem_id, $order);
                do_action('woocommerce_thankyou', $order->get_id());
                $cart['order_state'] = 0;
                $cart['items'] = array();
            } else {
                $cart['order_state'] = 11;
                $this->cart();
                $this->display_product_cats();
            }
        }

        $this->update_user( array( 'cart' => serialize( $cart ) ) );
	}

	function beginOrder($message_id = null, $refresh = false)
    {
        $cart['order_state'] = 1;
        $this->update_user(array('cart' => serialize($cart)));
        $this->checkout_request_ordertype();
    }

    function display_category($product_category_id, $title = null) {
        $product_category = get_term($product_category_id, 'product_cat');
        $products = $this->query(array('category_id' => $product_category_id, 'per_page' => $this->get_option('products_per_page', 1), 'post_type' => 'product'));
        if ($this->get_option('simple_display',0))
            $this->send_products($products,0, $title?$title:$product_category->name.':');
        else
            $this->send_products($products);

    }

    function checkout($message_id = null, $refresh = false)
    {
        $cart = $this->get_cart();
        if (!empty($cart['items'])) {
            $this->cart(null, true, false);
            $cart['order_state'] = 20;
            $this->update_user(array('cart' => serialize($cart)));
            $this->checkout_request_ordertime();
        }
        else
            $this->telegram->sendMessage($this->words['cart_empty_message']);

    }

    function cart($message_id = null, $refresh = false, $edit = true)
    {
        $cart = $this->get_cart();
        $result = __('Your cart:', $this->plugin_key);
        $c = 0;
        $columns = 1;
        $keyboard = $product_d = array();
        $total_amount = 0;
//        if ($message_id == null) {
//            $this->telegram->sendMessage($result);
//            $message_id = $this->telegram->get_last_result()['result']['message_id'];
//        }
        if (count($cart['items'])) {
            foreach ($cart['items'] as $product_id => $item) {
                if (isset($item['count']) && $item['count'] >0 ) {
                    $c++;
                    $product = $this->query(array('p' => $product_id, 'post_type' => 'product'));
                    $price = $this->product_price($product);
                    $amount = $price*$item['count'];
                    $total_amount += $amount;
                    if ($edit) {
                        $product_d[] = array(
                            'text' => '❌ ' . $c . '. ' . $product['title'] . ' ' . $item['count'] . " x " . $price . " = " . $this->wc_price($amount) . ' ❌',
                            'callback_data' => 'cart_delete_' . $product_id . '_' . $message_id
                        );
                    }
                    else {
                        $product_d[] = array(
                            'text' => $c . '. ' . $product['title'] . ' ' . $item['count'] . " x " . $price . " = " . $this->wc_price($amount),
                            'callback_data' => 'null'
                        );
                    }
                    if ($c % $columns == 0) {
                        $keyboard[] = $product_d;
                        $product_d = array();
                    }
                } elseif (isset($item['variations'])) {
	                $product = $this->query(array('p' => $product_id, 'post_type' => 'product'));
	                $product = $this->load_product_variations($product);
	                foreach ($item['variations'] as $variation_id => $variation)
                    {
	                    $c++;
                        $price = $this->product_price($product, $variation_id);
                        $amount = $price*$variation;
                        $variation_name = '';
                        foreach ($product['product_variations'] as $prodvar)
                            if ($prodvar['ID'] == $variation_id)
                                $variation_name = $variation_name = $prodvar['title'];
	                    $total_amount += $amount;
	                    $product_d[] = array(
		                    'text' => '❌ '.$c . '. ' . $product['title'] . ' - '. $variation_name.'  ' . $variation." x ".$price." = ".$this->wc_price($amount). ' ❌',
                            'callback_data' => 'cart_delete_' . $product_id . '_' . $message_id
	                    );
	                    if ($c % $columns == 0) {
		                    $keyboard[] = $product_d;
		                    $product_d = array();
	                    }

                    }
                }
            }
	        $product_d[] = array(
		        'text' => __('Total', $this->plugin_key) .': '. $this->wc_price($total_amount),
		        'callback_data' => 'display_cart'
	        );
            if (!empty($keyboard)) {
                $result = __('Your cart:', $this->plugin_key);
            } else
                $result = $this->words['cart_empty_message'];
        } else
            $result = $this->words['cart_empty_message'];

        if (count($product_d))
            $keyboard[] = $product_d;

        if ($message_id == null)
            $this->telegram->sendMessage($result);
        elseif ($message_id != null && $refresh)
            $this->telegram->editMessageText($result, $message_id);

        if (count($keyboard)) {
            if ($message_id == null)
                $message_id = $this->telegram->get_last_result()['result']['message_id'];
            if ($edit) {
                $keybuttons = array();
                $keybuttons[] = array('text' => '🚮', 'callback_data' => 'confirm_empty_cart_' . $message_id);
                $keybuttons[] = array('text' => '🔄', 'callback_data' => 'refresh_cart_' . $message_id);
                if ($this->get_option('checkout_orders_in_chat', 0) != 1)
                    $keybuttons[] = array('text' => '🛒', 'url' => $this->cart_url());
                else
                    $keybuttons[] = array('text' => '🛒', 'callback_data' => 'process_checkout_' . $message_id);
                $keyboard[] = $keybuttons;
            }
            $keyboards = $this->telegram->keyboard($keyboard, 'inline_keyboard');
            $this->telegram->editMessageReplyMarkup($keyboards, $message_id);
        }
    }

    function cart_url()
    {
        $url = wc_get_cart_url();
        $url .= strpos($url, '?') === false ? '?' : '&';
        $url .= 'wptpwc=' . $this->user_field('rand_id');
        return $url;
    }

    function openCageGetAddress($lat, $lon)
    {
        $apikey = $this->get_option('opencage_apikey', '');
        if (empty($apikey))
            return $lat.', '.$lon;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.opencagedata.com/geocode/v1/json?'.
            'q='.$lat.'+'.$lon.
            '&key='.$apikey.
        '&language='.$this->user['locale']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        $ocresult = json_decode($output, true);
        $ocresults = $ocresult['results'];
        if (!empty($ocresults))
        {
            return $ocresults[0]['formatted'];
        }
        else
            return $lat.', '.$lon;

    }

    function cart_init()
    {
        if (!is_ajax() && isset($_GET['wptpwc']) && is_numeric($_GET['wptpwc']) && function_exists('wc')) {
            $user = $this->set_user(array('rand_id' => $_GET['wptpwc']));
            if ($user === null) return;
            if (!is_user_logged_in())
                setcookie('wptpwc_user_id', $_GET['wptpwc'], current_time('U') + (60 * 60 * 12 * 7));
            elseif (empty($user['wp_id'])) {
                $wp_id = get_current_user_id();
                $this->update_user(array('wp_id' => $wp_id));
                $success_connect_message = $this->get_option('telegram_connectivity_success_connect_message', $this->words['profile_success_connect']);
                if (!empty($success_connect_message))
                    $this->telegram->sendMessage($success_connect_message, null, $user['user_id']);
            }
            $cart_item_id = false;
            $cart = $this->get_cart();
            if ($this->get_option('empty_wc_cart_before_redirect' ,0))
                WC()->cart->empty_cart();
            $wc_cart = WC()->cart->get_cart();
            if (count($cart['items'])) {
                foreach ($cart['items'] as $product_id => $item) {
                    $found = false;
                    if (!WC()->cart->is_empty())
                        foreach ($wc_cart as $cart_item_key => $values) {
                            $_product = $values['data'];
                            if ($_product->id == $product_id) {
                                $found = true;
                                $cart_item_id = $cart_item_key;
                            }
                        }
                    if (isset($item['count']) && $item['count'] > 0) {
                        if (isset($cart['items'][$product_id]['variations'])) {
                            if ($found)
                                WC()->cart->remove_cart_item($cart_item_id);
                            $product_variation = array();
                            $product = $this->query(array('p' => $product_id, 'post_type' => 'product'));
                            $variation_id = $product['product_variation_id'];
                            if (is_array($product['variations']) && count($product['variations'])) {
                                foreach ($product['variations'] as $name => $variation) {
                                    if ($variation['is_variation'] != 1)
                                        continue;
                                    $var_name = urldecode($name);
                                    if ($variation['is_taxonomy'] == 1) {
                                        $tax = get_taxonomy($var_name);
                                        $var_name = $tax->labels->singular_name;
                                    }
                                    if (isset($cart['items'][$product_id]['variations'][$var_name]))
                                        $product_variation['attribute_' . $name] = $cart['items'][$product_id]['variations'][$var_name];
                                }
                                WC()->cart->add_to_cart($product_id, 1, $variation_id, $product_variation);
                            }
                        } elseif (!$found) {
                            WC()->cart->add_to_cart($product_id, $item['count']);
                        }
                    } elseif (isset($item['added']) && $item['added'] == false && !empty($cart_item_id) && $found) {
                        WC()->cart->remove_cart_item($cart_item_id);
                    }
                }

                if (isset($this->options['empty_cart_after_wc_redirect']))
                    $this->update_user(array('cart' => serialize(array())));
            }
            wp_redirect(wc_get_cart_url());
        }
    }

    function woocommerce_payment_complete($order_id)
    {
        $order = wc_get_order($order_id);
        $user = $order->get_user();
        if ($user) {
            $user = $this->set_user(array('wp_id' => $user->ID));
            if ($user === null)
                return;
            if (isset($this->options['empty_cart_after_wc_payment_complete']))
                $this->update_user(array('cart' => serialize(array())));
        }
    }

    /**
     * Returns an instance of class
     * @return WooCommerceWPTP
     */
    static function getInstance()
    {
        if (self::$instance == null)
            self::$instance = new WooCommerceWPTP();
        return self::$instance;
    }
}

$WooCommerceWPTP = WooCommerceWPTP::getInstance();