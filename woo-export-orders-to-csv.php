<?php
/*
Plugin Name: Woo Export Orders To CSV
Plugin URI: http://damoiseau.me
Description: Export all the orders from Woo Commerce to a CSV file
Version: 0.1
Author: Mike Damoiseau
Author URI: http://damoiseau.me
*/
class WooExportOrdersToCsv {

    private $products;
    private $orders;

    public function __construct() {
        $products = array();
        $orders = array();

        add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
    } // __construct


    public function admin_menu() {
        add_submenu_page(
            // parent slug
            'tools.php',
            // page title
            __("Woo Export Orders To CSV", "woo_export_orders_to_csv"),
            // menu title
            __("Woo Export Orders To CSV", "woo_export_orders_to_csv"),
            // capability
            'edit_users',
            // menu_slug
            'woo_export_orders_to_csv',
            // function
            array( &$this, 'woo_export_orders_to_csv' ) );
    } // admin_menu

    // ********* Get all products and variations and sort alphbetically, return in array (title, sku, id)*******
    private function load_products() {

        $full_product_list = array();
        $loop = new WP_Query( array( 'post_type' => array('product', 'product_variation'), 'posts_per_page' => -1 ) );

        while ( $loop->have_posts() ) : $loop->the_post();
            $theid = get_the_ID();

            $product = new WC_Product($theid);
            // its a variable product
            if( get_post_type() == 'product_variation' ){
                $parent_id = wp_get_post_parent_id($theid );
                $sku = get_post_meta($theid, '_sku', true );
                $thetitle = get_the_title( $parent_id);

                // ****** Some error checking for product database *******
                // check if variation sku is set
                if ($sku == '') {
                    if ($parent_id == 0) {
                        // Remove unexpected orphaned variations.. set to auto-draft
                        $false_post = array();
                        $false_post['ID'] = $theid;
                        $false_post['post_status'] = 'auto-draft';
                        wp_update_post( $false_post );
                        if (function_exists(add_to_debug)) add_to_debug('false post_type set to auto-draft. id='.$theid);
                    } else {
                        // there's no sku for this variation > copy parent sku to variation sku
                        // & remove the parent sku so the parent check below triggers
                        $sku = get_post_meta($parent_id, '_sku', true );
                        if (function_exists(add_to_debug)) add_to_debug('empty sku id='.$theid.'parent='.$parent_id.'setting sku to '.$sku);
                        update_post_meta($theid, '_sku', $sku );
                        update_post_meta($parent_id, '_sku', '' );
                    }
                }
                // ****************** end error checking *****************

            // its a simple product
            } else {
                $sku = get_post_meta($theid, '_sku', true );
                $thetitle = get_the_title();
            }
            // add product to array but don't add the parent of product variations
            // if (!empty($sku)) {
            //     $full_product_list[] = array($thetitle, $sku, $theid);
            // }
            $full_product_list[$theid] = array(
                'id'    => $theid,
                'title' => $thetitle,
                'sku'   => $sku,
            );
        endwhile; wp_reset_query();

        // sort into alphabetical order, by title
        // sort($full_product_list);

        $this->products = $full_product_list;

        /**
         * Load the extra fields here
         */
        foreach( $this->products as $productId => $product ) {
            $all_fields = apply_filters( 'wcff/load/all_fields', $productId, 'wccpf', "any" );

            $this->products[$productId]['extra_fields'] = $all_fields;
        }

    }

    /**
     * Load all orders
     *
     * @return [type] [description]
     *
     * @todo filter by `wc-completed` status only
     */
    private function load_orders( $filters = array() ) {

        $args = array(
            'numberposts' => -1,
            // 'meta_key'    => '_customer_user',
            // 'meta_value'  => get_current_user_id(),
            'post_type'   => wc_get_order_types(),
            // 'post_status' => array_keys( wc_get_order_statuses() ),
            'post_status' => 'wc-completed', // ok ?
        );

        // order ID
        if( !empty( $filters['order_id'] ) ) {
            if( empty( $filters['order_id_max'] ) ) {
                $value = ( int ) $filters['order_id'];
            } else {
                $value = range(
                    min( ( int ) $filters['order_id'], ( int ) $filters['order_id_max'] ),
                    max( ( int ) $filters['order_id'], ( int ) $filters['order_id_max'] )
                );
            }
            $args['include'] = $value;
        }

        // Date (start / end)
        if( !empty( $filters['order_date_start'] ) ) {
            if( empty( $filters['order_date_end'] ) ) {
                // @todo
                $args['date_query'] = array(
                    array(
                        'after'     => $filters['order_date_start'],
                        'before'    => $filters['order_date_start'],
                        'inclusive' => true,
                    ),
                );
            } else {
                $args['date_query'] = array(
                    array(
                        'after'     => $filters['order_date_start'],
                        'before'    => $filters['order_date_end'],
                        'inclusive' => true,
                    ),
                );
            }
        }

        $orders = get_posts( $args );
        foreach( $orders as $order ) {

            $o = new WC_Order( $order->ID );

            /**
             * Filters
             */
            // first name
            if( !empty( $filters['first_name'] ) ) {
                $value = strtolower( $filters['first_name'] );
                if( strpos( strtolower( $o->shipping_first_name ), $value ) === false ) {
                    continue;
                }
            }
            // last name
            if( !empty( $filters['last_name'] ) ) {
                $value = strtolower( $filters['last_name'] );
                if( strpos( strtolower( $o->shipping_last_name ), $value ) === false ) {
                    continue;
                }
            }
            // address
            if( !empty( $filters['address'] ) ) {
                $value = strtolower( $filters['address'] );
                if(
                    ( strpos( strtolower( $o->shipping_address_1 ), $value ) === false ) &&
                    ( strpos( strtolower( $o->shipping_address_2 ), $value ) === false )
                ) {
                    continue;
                }
            }
            // city
            if( !empty( $filters['city'] ) ) {
                $value = strtolower( $filters['city'] );
                if( strpos( strtolower( $o->shipping_city ), $value ) === false ) {
                    continue;
                }
            }
            // state
            if( !empty( $filters['state'] ) ) {
                $value = strtolower( $filters['state'] );
                if( strpos( strtolower( $o->shipping_state ), $value ) === false ) {
                    continue;
                }
            }
            // postcode
            if( !empty( $filters['postcode'] ) ) {
                $value = strtolower( $filters['postcode'] );
                if( strpos( strtolower( $o->shipping_postcode ), $value ) === false ) {
                    continue;
                }
            }
            // country
            if( !empty( $filters['country'] ) ) {
                $value = strtolower( $filters['country'] );
                if( strpos( strtolower( $o->shipping_country ), $value ) === false ) {
                    continue;
                }
            }
            // email
            if( !empty( $filters['email'] ) ) {
                $value = strtolower( $filters['email'] );
                if( strpos( strtolower( $o->billing_email ), $value ) === false ) {
                    continue;
                }
            }

            $this->orders[] = array(
                'id'       => $order->ID,
                'name'     => $order->post_title,
                'date'     => $order->post_date,
                'shipping' => array(
                    'first_name' => $o->shipping_first_name,
                    'last_name' => $o->shipping_last_name,
                    'company' => $o->shipping_company,
                    'address_1' => $o->shipping_address_1,
                    'address_2' => $o->shipping_address_2,
                    'city' => $o->shipping_city,
                    'state' => $o->shipping_state,
                    'postcode' => $o->shipping_postcode,
                    'country' => $o->shipping_country,
                ),
                'billing' => array(
                    'first_name' => $o->billing_first_name,
                    'last_name' => $o->billing_last_name,
                    'company' => $o->billing_company,
                    'address_1' => $o->billing_address_1,
                    'address_2' => $o->billing_address_2,
                    'city' => $o->billing_city,
                    'state' => $o->billing_state,
                    'postcode' => $o->billing_postcode,
                    'country' => $o->billing_country,
                    'email' => $o->billing_email,
                ),
            );
        }

    }

    /**
     * Load the details of a given order
     * @param  [type] $orderId [description]
     * @return [type]          [description]
     *
     * @see http://stackoverflow.com/a/39409201
     */
    private function load_order_items( $order_id ) {
        $order = wc_get_order( $order_id );

        // $order_meta = get_post_meta( $order_id );

        $items = $order->get_items();

        return $items;
    }

    /**
     * [load_order_item_extra_fields description]
     * @param  [type] $order_item_id [description]
     * @param  [type] $product_id [description]
     * @return [type]           [description]
     *
     * @todo check how to get all the extra fields:
     * wc-fields-factory/classes/wcff-admin-form.php
     */
    private function load_order_item_extra_fields( $order_item_id, $product_id ) {
        global $wpdb;

        $all_fields = array();

        $product = $this->products[ $product_id ];
        foreach( $product['extra_fields'] as $extra_field_section => $extra_fields ) {
            foreach( $extra_fields as $extra_field_slug => $fields ) {
                $key = $fields['label'];
                $value = wc_get_order_item_meta( $order_item_id, $key, true );

                $all_fields[$key] = $value;
            }
        }

        return $all_fields;
    }

    public function woo_export_orders_to_csv() {
        global $wpdb;

        // $values = array_map('trim', $_GET);
        $values = $_GET;
        $table = array();

        $this->load_products();

        $table_titles = array(
            'order ID',
            'order date',
            'First name',
            'Last name',
            'Address',
            'Address 2',
            'City',
            'State / Province',
            'Country',
            'Post Code',
            'Email',

            // 'Anything else?',

            'Product',
            'Quantity',
            'Product price',
        );

        // $_GET

        $this->load_orders( $values );

        $max_extra_fields_count = 0;

        foreach( (array)$this->orders as $order ) {

            $order_row = array(
                $order['id'],
                $order['date'],
                $order['shipping']['first_name'],
                $order['shipping']['last_name'],
                $order['shipping']['address_1'],
                $order['shipping']['address_2'],
                $order['shipping']['city'],
                $order['shipping']['state'],
                $order['shipping']['country'],
                $order['shipping']['postcode'],
                $order['billing']['email'],
            );

            $order_items = $this->load_order_items( $order['id'] );

            foreach( $order_items as $order_item_id => $order_item ) {
                $product_id = $order_item['product_id'];

                /**
                 * now we can filter by product
                 */
                if( !empty( $values['products'] ) ) {
                    $value = $values['products'];
                    if( !in_array( $product_id, $value ) ) {
                        continue;
                    }
                }

                $extra_fields = $this->load_order_item_extra_fields( $order_item_id, $product_id );

                $order_item_row = $order_row;
                $order_item_row[] = $order_item['name'];
                $order_item_row[] = $order_item['qty'];
                $order_item_row[] = $order_item['line_total'];

                foreach( $extra_fields as $extra_field_name => $extra_field_value ) {
                    $order_item_row[] = $extra_field_name;
                    $order_item_row[] = $extra_field_value;
                }

                $table[] = $order_item_row;

                if( count( $extra_fields ) > $max_extra_fields_count ) {
                    $max_extra_fields_count = count( $extra_fields );
                }
            }
        }

        for( $i = 0; $i < $max_extra_fields_count; $i++ ) {
            $table_titles[] = sprintf( 'field name %d', ( $i + 1 ) );
            $table_titles[] = sprintf( 'field value %d', ( $i + 1 ) );
        }

        $upload_dir = wp_upload_dir();

        if ( $upload_dir['error'] === false ) {
            // $csv_name = date( 'Ymd_His' );
            $csv_name = 'wcf_csv.csv';
            $csv_path = rtrim( $upload_dir['path'], '/' ) . '/';

            $fd = fopen( $csv_path . $csv_name, 'w' );
            if( $fd !== FALSE ) {
                fputcsv( $fd, $table_titles );
                foreach( $table as $row ) {
                    fputcsv( $fd, $row );
                }
                fclose($fd);

                $csv_url = rtrim( $upload_dir['url'], '/' ) . '/';
            }
        }

        ob_start();
        ?>
        <h2><?php _e( "Woo Export Orders To CSV", 'woo_export_orders_to_csv' ); ?></h2>
        <form id="frm_woo_export_orders_to_csv"
              name="frm_woo_export_orders_to_csv"
              echo action="<?php echo admin_url('tools.php'); ?>"
              method="get">
            <input type="hidden" name="page" value="woo_export_orders_to_csv" />
            <!-- <input type="hidden" name="woo_export_orders_to_csv" value="1" /> -->

            <table>
                <tr>
                    <td>
                        <label>
                            <span>Order ID</span>
                            <input type="text" name="order_id" value="<?php echo isset( $values['order_id'] ) ? $values['order_id'] : ''; ?>">
                        </label>
                    </td>
                    <td>
                        <label>
                            <span>Order ID max</span>
                            <input type="text" name="order_id_max" value="<?php echo isset( $values['order_id_max'] ) ? $values['order_id_max'] : ''; ?>">
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label>
                            <span>Date start</span>
                            <input type="date" name="order_date_start" value="<?php echo isset( $values['order_date_start'] ) ? $values['order_date_start'] : ''; ?>">
                        </label>
                    </td>
                    <td>
                        <label>
                            <span>Date end</span>
                            <input type="date" name="order_date_end" value="<?php echo isset( $values['order_date_end'] ) ? $values['order_date_end'] : ''; ?>">
                        </label>
                    </td>
                    <td>
                        (Format 'yyyy-mm-dd')
                    </td>
                </tr>
                <tr>
                    <td>
                        <label>
                            <span>Products</span><br>
                            <?php foreach( $this->products as $product_id => $product ) : ?>
                                <label>
                                    <input type="checkbox"
                                           id="product-<?php echo $product_id; ?>"
                                           name="products[]"
                                           value="<?php echo $product_id; ?>"
                                           <?php if( isset( $values['products'] ) && in_array( $product_id, $values['products'] ) ) : ?>
                                            checked="checked"
                                           <?php endif; ?>
                                           >
                                    <span><?php echo $product['title']; ?></span>
                                </label><br>
                            <?php endforeach; ?>
                        </label>
                    </td>
                </tr>
<?php /*
                <tr>
                    <td>
                        <label>
                            <span>First Name</span>
                            <input type="text" name="first_name" value="<?php echo isset( $values['first_name'] ) ? $values['first_name'] : ''; ?>">
                        </label>
                    </td>
                    <td>
                        <label>
                            <span>Last Name</span>
                            <input type="text" name="last_name" value="<?php echo isset( $values['last_name'] ) ? $values['last_name'] : ''; ?>">
                        </label>
                    </td>
                    <td>
                        <label>
                            <span>Email</span>
                            <input type="text" name="email" value="<?php echo isset( $values['email'] ) ? $values['email'] : ''; ?>">
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label>
                            <span>Address</span>
                            <input type="text" name="address" value="<?php echo isset( $values['address'] ) ? $values['address'] : ''; ?>">
                        </label>
                    </td>
                    <td>
                        <label>
                            <span>City</span>
                            <input type="text" name="city" value="<?php echo isset( $values['city'] ) ? $values['city'] : ''; ?>">
                        </label>
                    </td>
                    <td>
                        <label>
                            <span>State / Province</span>
                            <input type="text" name="state" value="<?php echo isset( $values['state'] ) ? $values['state'] : ''; ?>">
                        </label>
                    </td>
                    <td>
                        <label>
                            <span>Post Code</span>
                            <input type="text" name="postcode" value="<?php echo isset( $values['postcode'] ) ? $values['postcode'] : ''; ?>">
                        </label>
                    </td>
                    <td>
                        <label>
                            <span>Country</span>
                            <input type="text" name="country" value="<?php echo isset( $values['country'] ) ? $values['country'] : ''; ?>">
                        </label>
                    </td>
                </tr>
*/ ?>
                <tr>
                    <td>
                        <input type="submit" value="Filter" class="button-primary" />
                    </td>
                </tr>
            </table>
        </form>

        <?php if( isset( $csv_url ) ) : ?>
            <a href="<?php echo $csv_url . $csv_name; ?>">Download as csv (Right click, Save as...)</a>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped posts">
        <thead>
            <tr>
                <?php foreach( $table_titles as $value ) : ?>
                    <th scope="col" class="manage-column"><?php echo htmlentities( $value ); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
            <tbody>
            <?php foreach( $table as $row ) : ?>
                <tr>
                    <?php foreach( $row as $value ) : ?>
                        <td><?php echo htmlentities( $value ); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        ob_end_flush();
    } // woo_export_orders_to_csv

};

new WooExportOrdersToCsv;