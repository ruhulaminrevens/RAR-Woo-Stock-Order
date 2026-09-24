<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RAR_WSO_Ajax {
    public function __construct() {
        foreach ( array(
            'stats'=>'stats','products'=>'products','stock_update'=>'stock_update',
            'create_order'=>'create_order','add_product'=>'add_product','delete_product'=>'delete_product'
        ) as $action=>$method ) {
            add_action( 'wp_ajax_rar_wso_' . $action, array( $this, $method ) );
        }
    }

    private function guard( $cap='rar_wso_access' ) {
        check_ajax_referer( 'rar_wso_nonce', 'nonce' );
        if ( ! is_user_logged_in() || ! RAR_WSO_Plugin::can( $cap ) ) {
            wp_send_json_error( array( 'message'=>__( 'You do not have permission to perform this action.', 'rar-woo-stock-order' ) ), 403 );
        }
    }

    private function product_payload( $product ) {
        if ( ! $product ) return null;
        $image_id=$product->get_image_id();
        $image=$image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
        $name=$product->get_name();
        if ( $product->is_type( 'variation' ) ) {
            $parent=wc_get_product( $product->get_parent_id() );
            if ( $parent ) $name=$parent->get_name().' — '.wc_get_formatted_variation( $product, true, false, false );
        }
        $stock=$product->get_manage_stock() ? $product->get_stock_quantity() : null;
        return array(
            'id'=>$product->get_id(),'name'=>wp_strip_all_tags($name),'sku'=>$product->get_sku(),
            'price'=>(float) wc_get_price_to_display($product),'price_html'=>wp_strip_all_tags($product->get_price_html()),
            'stock_qty'=>is_null($stock)?'':(float)$stock,'manage_stock'=>(bool)$product->get_manage_stock(),
            'stock_status'=>$product->get_stock_status(),'image'=>$image,'type'=>$product->get_type()
        );
    }

    public function stats() {
        $this->guard();
        $today=wp_date( 'Y-m-d', current_time('timestamp') );
        $orders=wc_get_orders(array('limit'=>-1,'return'=>'objects','date_created'=>'>='.$today.' 00:00:00','status'=>array_keys(wc_get_order_statuses())));
        $count=0;$sales=0.0;
        foreach($orders as $order){
            if($order->has_status(array('cancelled','failed','refunded'))) continue;
            $count++; $sales+=(float)$order->get_total();
        }
        global $wpdb;
        $lookup=$wpdb->wc_product_meta_lookup;
        $low=max(0,(int)get_option('woocommerce_notify_low_stock_amount',2));
        $low_stock=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(product_id) FROM {$lookup} WHERE stock_quantity IS NOT NULL AND stock_quantity > 0 AND stock_quantity <= %d AND stock_status='instock'",$low));
        $out_stock=(int)$wpdb->get_var("SELECT COUNT(product_id) FROM {$lookup} WHERE stock_status='outofstock'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        wp_send_json_success(array('orders'=>$count,'sales'=>wc_price($sales),'low_stock'=>$low_stock,'out_stock'=>$out_stock));
    }

    public function products() {
        $this->guard();
        $search=isset($_POST['search'])?sanitize_text_field(wp_unslash($_POST['search'])):'';
        $args=array('status'=>'publish','limit'=>30,'orderby'=>'name','order'=>'ASC','return'=>'objects');
        if($search!=='') $args['search']=$search;
        $products=wc_get_products($args);

        if($search!=='' && count($products)<30){
            global $wpdb;
            $ids=$wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE %s LIMIT %d",'%'.$wpdb->esc_like($search).'%',30));
            foreach($ids as $id){$p=wc_get_product((int)$id);if($p && 'publish'===get_post_status($p->get_id()))$products[]=$p;}
        }

        $seen=array();$items=array();
        foreach($products as $product){
            if(!$product)continue;
            $expand=array($product);
            if($product->is_type('variable')){
                $expand=array();
                foreach($product->get_children() as $vid){$v=wc_get_product($vid);if($v && 'publish'===get_post_status($vid))$expand[]=$v;}
            }
            foreach($expand as $candidate){
                if(isset($seen[$candidate->get_id()]))continue;
                $seen[$candidate->get_id()]=true;
                $payload=$this->product_payload($candidate);
                if($payload)$items[]=$payload;
                if(count($items)>=30)break 2;
            }
        }
        wp_send_json_success(array('items'=>$items));
    }

    public function stock_update() {
        $this->guard('rar_wso_manage_stock');
        $id=isset($_POST['product_id'])?absint($_POST['product_id']):0;
        $raw=isset($_POST['qty'])?wc_format_decimal(wp_unslash($_POST['qty'])):'';
        if(!$id || $raw==='' || !is_numeric($raw))wp_send_json_error(array('message'=>__('A valid product and stock quantity are required.','rar-woo-stock-order')),422);
        $product=wc_get_product($id);
        if(!$product)wp_send_json_error(array('message'=>__('Product not found.','rar-woo-stock-order')),404);

        $qty=max(0,(float)$raw);$old=$product->get_stock_quantity();
        $product->set_manage_stock(true);$product->set_stock_quantity($qty);$product->set_stock_status($qty>0?'instock':'outofstock');$product->save();
        update_post_meta($id,'_rar_wso_last_stock_update',wp_json_encode(array('user_id'=>get_current_user_id(),'time'=>current_time('mysql'),'from'=>is_null($old)?null:(float)$old,'to'=>$qty)));
        if(function_exists('wc_get_logger'))wc_get_logger()->info(sprintf('Stock update product #%d: %s -> %s by user #%d',$id,is_null($old)?'unmanaged':$old,$qty,get_current_user_id()),array('source'=>'rar-wso'));
        wp_send_json_success(array('message'=>__('Stock updated.','rar-woo-stock-order'),'product'=>$this->product_payload($product)));
    }

    public function create_order() {
        $this->guard('rar_wso_create_orders');
        $payload=isset($_POST['payload'])?json_decode(wp_unslash($_POST['payload']),true):null;
        if(!is_array($payload))wp_send_json_error(array('message'=>__('Invalid order data.','rar-woo-stock-order')),422);

        $name=sanitize_text_field($payload['name']??'');$phone=sanitize_text_field($payload['phone']??'');
        $email=sanitize_email($payload['email']??'');$address=sanitize_text_field($payload['address']??'');
        $city=sanitize_text_field($payload['city']??'');$district=sanitize_text_field($payload['district']??'');
        $note=sanitize_textarea_field($payload['note']??'');$shipping=max(0,(float)wc_format_decimal($payload['shipping']??0));
        $items=isset($payload['items'])&&is_array($payload['items'])?$payload['items']:array();
        if($name===''||$phone===''||$address===''||$city===''||$district===''||empty($items))wp_send_json_error(array('message'=>__('Name, phone, address, town/city, district and at least one item are required.','rar-woo-stock-order')),422);
        if($email && !is_email($email))wp_send_json_error(array('message'=>__('Please enter a valid email address.','rar-woo-stock-order')),422);

        $settings=RAR_WSO_Plugin::settings();
        $can_override='yes'===$settings['allow_price_override'] && RAR_WSO_Plugin::can('rar_wso_adjust_price');
        try{
            $order=wc_create_order(array('status'=>'pending'));
            if(is_wp_error($order))throw new Exception($order->get_error_message());
            $parts=preg_split('/\s+/',trim($name),2);$first=$parts[0]??$name;$last=$parts[1]??'';
            $billing=array('first_name'=>$first,'last_name'=>$last,'phone'=>$phone,'email'=>$email,'address_1'=>$address,'city'=>$city,'state'=>$this->district_to_state_code($district),'country'=>'BD');
            $order->set_address($billing,'billing');$order->set_address($billing,'shipping');

            foreach($items as $raw){
                $pid=absint($raw['id']??0);$qty=max(1,absint($raw['qty']??1));$product=wc_get_product($pid);
                if(!$product||!$product->is_purchasable())throw new Exception(sprintf(__('Product #%d is not available for ordering.','rar-woo-stock-order'),$pid));
                $base=(float)$product->get_price();$price=$base;
                if($can_override && isset($raw['price']) && is_numeric($raw['price']))$price=max(0,(float)wc_format_decimal($raw['price']));
                $item_id=$order->add_product($product,$qty,array('subtotal'=>$price*$qty,'total'=>$price*$qty));
                if($can_override && abs($price-$base)>0.0001)wc_add_order_item_meta($item_id,'_rar_wso_price_override',wc_format_decimal($price),true);
            }

            if($shipping>0){
                $ship=new WC_Order_Item_Shipping();$ship->set_method_title(__('Staff order delivery','rar-woo-stock-order'));$ship->set_method_id('rar_wso_manual_shipping');$ship->set_total($shipping);$order->add_item($ship);
            }
            $order->set_payment_method('cod');$order->set_payment_method_title(__('Cash on delivery','woocommerce'));$order->set_created_via('rar-wso-staff');
            $order->update_meta_data('_rar_wso_created_by',get_current_user_id());$order->update_meta_data('_rar_wso_channel','staff-pwa');
            if($note)$order->set_customer_note($note);
            $order->calculate_totals(true);$order->save();
            $user=wp_get_current_user();$order->add_order_note(sprintf('Created via RAR Woo Stock & Order by %s (#%d).',$user->display_name,$user->ID),false,true);
            $target=sanitize_key($settings['default_order_status']);
            if(!isset(wc_get_order_statuses()['wc-'.$target]))$target='processing';
            $order->update_status($target,__('Staff order created from RAR Woo Stock & Order.','rar-woo-stock-order'),true);
            wc_maybe_reduce_stock_levels($order->get_id());
            wp_send_json_success(array('message'=>__('Order created successfully.','rar-woo-stock-order'),'order_id'=>$order->get_id(),'order_num'=>$order->get_order_number(),'total'=>$order->get_formatted_order_total(),'admin_url'=>$order->get_edit_order_url()));
        }catch(Throwable $e){
            if(isset($order)&&$order instanceof WC_Order&&$order->get_id())$order->delete(true);
            wp_send_json_error(array('message'=>$e->getMessage()),500);
        }
    }

    public function add_product() {
        $this->guard();
        $s=RAR_WSO_Plugin::settings();
        if('yes'!==$s['allow_product_add']&&!current_user_can('manage_woocommerce'))wp_send_json_error(array('message'=>__('Product creation is disabled by the administrator.','rar-woo-stock-order')),403);
        $name=isset($_POST['name'])?sanitize_text_field(wp_unslash($_POST['name'])):'';$sku=isset($_POST['sku'])?sanitize_text_field(wp_unslash($_POST['sku'])):'';
        $price=isset($_POST['price'])?wc_format_decimal(wp_unslash($_POST['price'])):'';$qty=isset($_POST['qty'])?wc_format_decimal(wp_unslash($_POST['qty'])):'0';
        if($name===''||$price==='')wp_send_json_error(array('message'=>__('Product name and price are required.','rar-woo-stock-order')),422);
        if($sku&&wc_get_product_id_by_sku($sku))wp_send_json_error(array('message'=>__('That SKU is already in use.','rar-woo-stock-order')),409);
        $p=new WC_Product_Simple();$p->set_name($name);$p->set_status('publish');$p->set_regular_price(max(0,(float)$price));if($sku)$p->set_sku($sku);
        $p->set_manage_stock(true);$p->set_stock_quantity(max(0,(float)$qty));$p->set_stock_status((float)$qty>0?'instock':'outofstock');$p->save();
        wp_send_json_success(array('message'=>__('Product created.','rar-woo-stock-order'),'product'=>$this->product_payload($p)));
    }

    public function delete_product() {
        $this->guard();
        $s=RAR_WSO_Plugin::settings();
        if('yes'!==$s['allow_product_delete']&&!current_user_can('manage_woocommerce'))wp_send_json_error(array('message'=>__('Product deletion is disabled by the administrator.','rar-woo-stock-order')),403);
        $id=isset($_POST['product_id'])?absint($_POST['product_id']):0;$p=wc_get_product($id);
        if(!$p)wp_send_json_error(array('message'=>__('Product not found.','rar-woo-stock-order')),404);
        if(!wp_trash_post($id))wp_send_json_error(array('message'=>__('Could not move the product to Trash.','rar-woo-stock-order')),500);
        wp_send_json_success(array('message'=>__('Product moved to Trash.','rar-woo-stock-order')));
    }

    private function district_to_state_code($district){
        $states=WC()->countries->get_states('BD');
        foreach($states as $code=>$label){if(0===strcasecmp(trim(wp_strip_all_tags($label)),trim($district))||0===strcasecmp($code,trim($district)))return $code;}
        return sanitize_text_field($district);
    }
}
