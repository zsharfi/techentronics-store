<?php
/**
 * Plugin Name: TechenTronics WhatsApp Orders
 * Description: Short order form + WhatsApp confirmation flow. Order statuses mirror the TechenTronics delivery process (Awaiting WhatsApp → Confirmed → Packed → Dispatched → Completed). Unpriced products show "Price on request" with an Ask-on-WhatsApp button.
 * Version: 1.0
 * Author: Coding Time Labs
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* =========================================================
 * Helpers
 * ======================================================= */
function tt_store_wa_number() {
	$c = function_exists( 'tt_contact' ) ? tt_contact() : array();
	return ! empty( $c['whatsapp'] ) ? $c['whatsapp'] : ( $c['phone'] ?? '' );
}
/** wa.me link to the store with a prefilled message. */
function tt_wa_store_url( $text = '' ) {
	$n = tt_store_wa_number();
	if ( ! $n || ! function_exists( 'tt_wa_link' ) ) { return ''; }
	return tt_wa_link( $n ) . ( $text !== '' ? '?text=' . rawurlencode( $text ) : '' );
}
/** wa.me link to a customer number (03xx… or +92…). */
function tt_wa_customer_url( $phone, $text = '' ) {
	$d = preg_replace( '/\D+/', '', (string) $phone );
	if ( 0 === strpos( $d, '0092' ) ) { $d = substr( $d, 2 ); }
	if ( 0 === strpos( $d, '0' ) ) { $d = '92' . substr( $d, 1 ); }
	if ( strlen( $d ) < 10 ) { return ''; }
	return 'https://wa.me/' . $d . ( $text !== '' ? '?text=' . rawurlencode( $text ) : '' );
}
function tt_plain_price( $amount ) {
	$a = (float) $amount;
	return 'Rs ' . number_format( $a, ( floor( $a ) == $a ) ? 0 : 2 );
}
/**
 * Safe href for a wa.me link. esc_url() strips line breaks (%0A) from the prefilled message,
 * which squashed every WhatsApp message onto one line, so wa.me links are escaped as attributes instead.
 */
function tt_wa_href( $url ) {
	return ( 0 === strpos( (string) $url, 'https://wa.me/' ) ) ? esc_attr( $url ) : esc_url( $url );
}
function tt_is_karachi( $order ) {
	$area = $order->get_meta( '_billing_tt_area' );
	if ( $area ) { return 'karachi' === $area; }
	return false !== stripos( (string) $order->get_billing_city(), 'karachi' );
}
function tt_order_items_text( $order ) {
	$lines = array();
	$i = 0;
	foreach ( $order->get_items() as $item ) {
		$i++;
		$p   = $item->get_product();
		$row = $i . '. ' . $item->get_name() . "\n";
		$row .= '    Qty: ' . $item->get_quantity() . ( $p && $p->get_sku() ? '  |  Model: ' . $p->get_sku() : '' ) . "\n";
		if ( $item->get_meta( 'Colour' ) ) { $row .= '    Colour: ' . $item->get_meta( 'Colour' ) . "\n"; }
		$row .= '    Price: ' . tt_plain_price( $item->get_total() );
		$lines[] = $row;
	}
	return implode( "\n\n", $lines );
}
function tt_delivery_fee( $order ) {
	foreach ( $order->get_fees() as $fee ) { if ( $fee->get_meta( '_tt_delivery' ) ) { return (float) $fee->get_total(); } }
	return null;
}
function tt_courier_label( $order ) {
	$c = $order->get_meta( '_tt_courier' );
	if ( $c ) { return $c; }
	return tt_is_karachi( $order ) ? 'Bykea / local rider' : 'courier (Leopards / TCS / M&P / PostEx)';
}

/* =========================================================
 * Order statuses — mirror the WhatsApp delivery flow
 * ======================================================= */
function tt_custom_statuses() {
	return array(
		'tt-await-wa'   => 'Awaiting WhatsApp confirmation',
		'tt-confirmed'  => 'Confirmed',
		'tt-packed'     => 'Packed',
		'tt-dispatched' => 'Dispatched',
	);
}
add_filter( 'woocommerce_register_shop_order_post_statuses', function ( $s ) {
	foreach ( tt_custom_statuses() as $k => $label ) {
		$s[ 'wc-' . $k ] = array(
			'label'                     => $label,
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: count */
			'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>' ),
		);
	}
	return $s;
} );
add_filter( 'wc_order_statuses', function ( $s ) {
	$out = array();
	foreach ( $s as $k => $v ) {
		$out[ $k ] = $v;
		if ( 'wc-pending' === $k ) {
			foreach ( tt_custom_statuses() as $ck => $cl ) { $out[ 'wc-' . $ck ] = $cl; }
		}
	}
	$out['wc-completed'] = 'Completed (delivered & paid)';
	return $out;
} );
// Orders can still be edited (add delivery fee, change qty) until they are packed.
add_filter( 'wc_order_is_editable', function ( $editable, $order ) {
	return $editable || in_array( $order->get_status(), array( 'tt-await-wa', 'tt-confirmed' ), true );
}, 10, 2 );
// Reserve stock once the customer has confirmed on WhatsApp.
add_action( 'woocommerce_order_status_tt-confirmed', 'wc_maybe_reduce_stock_levels' );
add_action( 'woocommerce_order_status_tt-packed', 'wc_maybe_reduce_stock_levels' );
add_action( 'woocommerce_order_status_tt-dispatched', 'wc_maybe_reduce_stock_levels' );
// New orders wait for WhatsApp confirmation instead of going to "Processing".
add_filter( 'woocommerce_cod_process_payment_order_status', function () { return 'tt-await-wa'; } );
// Still email the store about each new order.
add_action( 'woocommerce_order_status_pending_to_tt-await-wa', function ( $order_id ) {
	$m = WC()->mailer()->get_emails();
	if ( isset( $m['WC_Email_New_Order'] ) ) { $m['WC_Email_New_Order']->trigger( $order_id ); }
} );
// Show the custom statuses in the "My account" order list nicely.
add_filter( 'woocommerce_my_account_my_orders_actions', function ( $actions, $order ) {
	if ( 'tt-await-wa' === $order->get_status() ) {
		$u = tt_wa_store_url( tt_customer_order_message( $order ) );
		if ( $u ) { $actions['tt_wa'] = array( 'url' => $u, 'name' => 'Confirm on WhatsApp' ); }
	}
	return $actions;
}, 10, 2 );

/* =========================================================
 * Price on request (products without a price yet)
 * ======================================================= */
function tt_has_no_price( $product ) {
	return $product && ! $product->is_type( 'variable' ) && '' === (string) $product->get_price();
}
add_filter( 'woocommerce_get_price_html', function ( $html, $product ) {
	if ( tt_has_no_price( $product ) ) { return '<span class="tt-por">Price on WhatsApp</span>'; }
	return $html;
}, 20, 2 );
add_filter( 'woocommerce_empty_price_html', function () { return '<span class="tt-por">Price on WhatsApp</span>'; } );
function tt_ask_price_message( $product ) {
	$m  = "Hi TechenTronics!\n\n*PRICE ENQUIRY*\n\n";
	$m .= '*Product:* ' . $product->get_name() . "\n";
	if ( $product->get_sku() ) { $m .= '*Model:* ' . $product->get_sku() . "\n"; }
	if ( function_exists( 'tt_colours' ) && ( $c = tt_colours( $product ) ) ) { $m .= '*Colour:* ' . $c[0] . "\n"; }
	$m .= '*Link:* ' . get_permalink( $product->get_id() ) . "\n\n";
	$m .= 'Please share the latest price and availability. Thank you!';
	return $m;
}
function tt_product_enquiry_message( $product ) {
	$m  = "Hi TechenTronics!\n\n*PRODUCT ENQUIRY*\n\n";
	$m .= '*Product:* ' . $product->get_name() . "\n";
	if ( $product->get_sku() ) { $m .= '*Model:* ' . $product->get_sku() . "\n"; }
	if ( function_exists( 'tt_colours' ) && ( $c = tt_colours( $product ) ) ) { $m .= '*Colour:* ' . $c[0] . "\n"; }
	if ( '' !== (string) $product->get_price() ) { $m .= '*Price on website:* ' . tt_plain_price( $product->get_price() ) . "\n"; }
	$m .= '*Link:* ' . get_permalink( $product->get_id() ) . "\n\n";
	$m .= 'I have a question about this product.';
	return $m;
}

/* =========================================================
 * Buy now (straight to the short order form)
 * ======================================================= */
add_action( 'woocommerce_after_add_to_cart_button', function () {
	global $product;
	if ( ! $product || ! $product->is_type( 'simple' ) ) { return; }
	echo '<button type="submit" name="tt_buy_now" value="' . (int) $product->get_id() . '" class="button alt tt-buy-now"><i class="fas fa-bolt"></i> Buy now</button>';
} );
// The theme's Add-to-cart button carries the product id, so a Buy-now click has to pass it on itself.
add_action( 'wp_loaded', function () {
	if ( ! empty( $_POST['tt_buy_now'] ) && empty( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore
		$_REQUEST['add-to-cart'] = absint( $_POST['tt_buy_now'] ); // phpcs:ignore
	}
}, 5 );
add_filter( 'woocommerce_add_to_cart_redirect', function ( $url ) {
	if ( ! empty( $_REQUEST['tt_buy_now'] ) ) { wc_clear_notices(); return wc_get_checkout_url(); } // phpcs:ignore
	return $url;
}, 99 );

/* =========================================================
 * Short order form (classic checkout)
 * ======================================================= */
// Only Pakistan, no postcode / province needed.
add_filter( 'woocommerce_get_country_locale', function ( $l ) {
	$l['PK']['state']     = array( 'required' => false, 'hidden' => true );
	$l['PK']['postcode']  = array( 'required' => false, 'hidden' => true );
	$l['PK']['address_2'] = array( 'label' => 'Nearby landmark', 'label_class' => array(), 'placeholder' => 'e.g. near Hyderi Market, opposite the mosque', 'required' => false );
	$l['PK']['city']      = array( 'label' => 'City', 'required' => true, 'priority' => 40 );
	$l['PK']['address_1'] = array( 'label' => 'Full address', 'placeholder' => 'House / flat no., street, block, area', 'required' => true, 'priority' => 50 );
	$l['PK']['address_2']['priority'] = 60;
	$l['PK']['phone']     = array( 'label' => 'WhatsApp number', 'placeholder' => '03XX XXXXXXX', 'required' => true, 'priority' => 20 );
	return $l;
} );
add_filter( 'woocommerce_cart_needs_shipping', '__return_false' );
add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false' );
add_filter( 'woocommerce_enable_order_notes_field', '__return_true' );

add_filter( 'woocommerce_checkout_fields', function ( $f ) {
	$b = array();
	$b['billing_first_name'] = array( 'label' => 'Full name', 'required' => true, 'class' => array( 'form-row-wide' ), 'autocomplete' => 'name', 'priority' => 10 );
	$b['billing_phone']      = array( 'label' => 'WhatsApp number', 'type' => 'tel', 'required' => true, 'placeholder' => '03XX XXXXXXX', 'class' => array( 'form-row-wide' ), 'validate' => array( 'phone' ), 'autocomplete' => 'tel', 'priority' => 20 );
	$b['billing_tt_area']    = array( 'label' => 'Where should we deliver?', 'type' => 'radio', 'required' => true, 'class' => array( 'form-row-wide', 'tt-area' ), 'options' => array( 'karachi' => 'Inside Karachi', 'other' => 'Outside Karachi' ), 'default' => 'karachi', 'priority' => 30 );
	$b['billing_city']       = array( 'label' => 'City', 'required' => true, 'class' => array( 'form-row-wide' ), 'default' => 'Karachi', 'autocomplete' => 'address-level2', 'priority' => 40 );
	$b['billing_address_1']  = array( 'label' => 'Full address', 'required' => true, 'placeholder' => 'House / flat no., street, block, area', 'class' => array( 'form-row-wide' ), 'autocomplete' => 'street-address', 'priority' => 50 );
	$b['billing_address_2']  = array( 'label' => 'Nearby landmark', 'required' => false, 'placeholder' => 'e.g. near Hyderi Market, opposite the mosque', 'class' => array( 'form-row-wide' ), 'priority' => 60 );
	$b['billing_email']      = array( 'label' => 'Email', 'type' => 'email', 'required' => false, 'class' => array( 'form-row-wide' ), 'validate' => array( 'email' ), 'autocomplete' => 'email', 'priority' => 70 );
	$b['billing_country']    = isset( $f['billing']['billing_country'] ) ? array_merge( $f['billing']['billing_country'], array( 'priority' => 80, 'default' => 'PK', 'class' => array( 'form-row-wide', 'tt-hidden-field' ) ) ) : array( 'type' => 'hidden', 'default' => 'PK' );
	$f['billing']  = $b;
	$f['shipping'] = array();
	if ( isset( $f['order']['order_comments'] ) ) {
		$f['order']['order_comments']['label']       = 'Anything we should know?';
		$f['order']['order_comments']['placeholder'] = 'Preferred delivery time, colour preference, etc.';
	}
	return $f;
}, 99 );
add_filter( 'default_checkout_billing_country', function () { return 'PK'; } );

// Validate & tidy the WhatsApp number.
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	$d = preg_replace( '/\D+/', '', (string) ( $data['billing_phone'] ?? '' ) );
	if ( 0 === strpos( $d, '92' ) ) { $d = '0' . substr( $d, 2 ); }
	if ( ! preg_match( '/^03\d{9}$/', $d ) ) {
		$errors->add( 'validation', 'Please enter a valid Pakistani WhatsApp number, for example 0318 2493571.' );
	}
}, 10, 2 );
add_action( 'woocommerce_checkout_create_order', function ( $order, $data ) {
	$d = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
	if ( 0 === strpos( $d, '92' ) ) { $d = '0' . substr( $d, 2 ); }
	$order->set_billing_phone( $d );
	if ( ! $order->get_billing_country() ) { $order->set_billing_country( 'PK' ); }
	$area = $order->get_meta( '_billing_tt_area' ) ?: ( false !== stripos( $order->get_billing_city(), 'karachi' ) ? 'karachi' : 'other' );
	$order->update_meta_data( '_billing_tt_area', $area );
	$order->update_meta_data( '_tt_courier', 'karachi' === $area ? 'Bykea' : '' );
}, 10, 2 );

// Checkout wording.
add_filter( 'woocommerce_order_button_text', function () { return 'Place order & confirm on WhatsApp'; } );
add_filter( 'woocommerce_gateway_title', function ( $title, $id ) { return 'cod' === $id ? 'Cash on Delivery' : $title; }, 10, 2 );
add_filter( 'woocommerce_gateway_description', function ( $d, $id ) {
	return 'cod' === $id ? 'Pay in cash when your parcel arrives. We will confirm stock, delivery charges and your final total on WhatsApp before dispatch.' : $d;
}, 10, 2 );
add_action( 'woocommerce_review_order_before_order_total', function () {
	echo '<tr class="tt-delivery-row"><th>Delivery</th><td>Confirmed on WhatsApp<small>Karachi: Bykea / local rider · Other cities: Leopards, TCS, M&amp;P or PostEx</small></td></tr>';
} );
add_action( 'woocommerce_before_checkout_form', function () {
	echo '<div class="tt-steps"><div class="tt-step is-on"><b>1</b><span>Your details</span></div><div class="tt-step"><b>2</b><span>Confirm on WhatsApp</span></div><div class="tt-step"><b>3</b><span>We pack &amp; dispatch</span></div><div class="tt-step"><b>4</b><span>Pay on delivery</span></div></div>';
}, 5 );
add_action( 'woocommerce_cart_totals_before_order_total', function () {
	echo '<tr class="tt-cart-delivery"><th>Delivery</th><td>Confirmed on WhatsApp (Bykea in Karachi, courier to other cities)</td></tr>';
} );
add_action( 'woocommerce_before_customer_login_form', function () {
	echo '<div class="tt-acc-intro"><div><span class="tt-hp-eyebrow">Your account</span><h2>Log in or create a free account</h2><p>See all your orders and their status, keep your delivery address saved and reorder in seconds.</p></div><ul><li><i class="fas fa-receipt"></i> Order history</li><li><i class="fas fa-map-marker-alt"></i> Saved address</li><li><i class="fab fa-whatsapp"></i> WhatsApp updates</li></ul></div>';
} );
add_action( 'woocommerce_before_cart', function () {
	echo '<div class="tt-cart-note"><i class="fab fa-whatsapp"></i> No online payment needed. Place your order, confirm it with us on WhatsApp and pay cash on delivery.</div>';
} );
// Customer accounts: offered at checkout and ticked by default, so every order is kept in the customer's account.
add_filter( 'woocommerce_create_account_default_checked', '__return_true' );
add_filter( 'gettext', function ( $t, $text, $domain ) {
	if ( 'woocommerce' === $domain && 'Create an account?' === $text ) { return 'Create a free account to track your orders and reorder faster'; }
	return $t;
}, 20, 3 );
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( ! is_user_logged_in() && ! empty( $data['createaccount'] ) && empty( $data['billing_email'] ) ) {
		$errors->add( 'validation', 'Please add your email so we can create your account (we send your login link there). Or untick “Create a free account”.' );
	}
}, 20, 2 );
remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
add_filter( 'gettext', function ( $t, $text, $domain ) {
	if ( 'woocommerce' === $domain && function_exists( 'is_checkout' ) && is_checkout() ) {
		if ( 'Billing details' === $text ) { return 'Your details'; }
		if ( 'Additional information' === $text ) { return 'Notes'; }
	}
	return $t;
}, 10, 3 );

add_filter( 'woocommerce_checkout_login_message', function () { return 'Ordered before?'; } );
// Area toggle: fill "Karachi" automatically.
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) { return; }
	?>
	<script>
	(function(){
		function sync(){
			var k=document.querySelector('input[name="billing_tt_area"]:checked'); var c=document.getElementById('billing_city'); if(!k||!c){return;}
			if(k.value==='karachi'){ if(!c.value||c.value!=='Karachi'){ c.dataset.prev=c.value; } c.value='Karachi'; c.readOnly=true; c.closest('.form-row').classList.add('tt-locked'); }
			else { if(c.value==='Karachi'){ c.value=c.dataset.prev&&c.dataset.prev!=='Karachi'?c.dataset.prev:''; } c.readOnly=false; c.closest('.form-row').classList.remove('tt-locked'); c.placeholder='e.g. Lahore, Hyderabad, Islamabad'; }
		}
		document.addEventListener('change',function(e){ if(e.target&&e.target.name==='billing_tt_area'){ sync(); } });
		document.addEventListener('DOMContentLoaded',sync); sync();
		function acct(){
			var cb=document.getElementById('createaccount'); var lab=document.querySelector('#billing_email_field label'); if(!lab){return;}
			var opt=lab.querySelector('.optional'); var on=cb&&cb.checked;
			if(opt){ opt.textContent= on ? '(needed for your account)' : '(optional)'; }
		}
		document.addEventListener('change',function(e){ if(e.target&&e.target.id==='createaccount'){ acct(); } });
		document.addEventListener('DOMContentLoaded',acct); acct();
	})();
	</script>
	<?php
}, 50 );

/* =========================================================
 * Thank-you page → open WhatsApp with the order
 * ======================================================= */
function tt_customer_order_message( $order ) {
	$fee  = tt_delivery_fee( $order );
	$line = '------------------------------';
	$m  = 'Hi TechenTronics! I just placed an order on your website.' . "\n\n";
	$m .= '*NEW ORDER #' . $order->get_order_number() . '*' . "\n" . $line . "\n\n";
	$m .= '*ITEMS*' . "\n" . tt_order_items_text( $order ) . "\n\n" . $line . "\n";
	$m .= '*Subtotal:* ' . tt_plain_price( $order->get_subtotal() ) . "\n";
	$m .= '*Delivery:* ' . ( null === $fee ? 'please confirm' : tt_plain_price( $fee ) ) . "\n";
	$m .= '*Payment:* Cash on Delivery' . "\n" . $line . "\n\n";
	$m .= '*DELIVERY DETAILS*' . "\n";
	$m .= '*Name:* ' . $order->get_formatted_billing_full_name() . "\n";
	$m .= '*Phone:* ' . $order->get_billing_phone() . "\n";
	$m .= '*City:* ' . $order->get_billing_city() . ( tt_is_karachi( $order ) ? ' (inside Karachi)' : ' (outside Karachi)' ) . "\n";
	$m .= '*Address:* ' . $order->get_billing_address_1() . "\n";
	if ( $order->get_billing_address_2() ) { $m .= '*Landmark:* ' . $order->get_billing_address_2() . "\n"; }
	if ( $order->get_customer_note() ) { $m .= '*Note:* ' . $order->get_customer_note() . "\n"; }
	$m .= "\n" . 'Please confirm stock and my final total. Thank you!';
	return $m;
}
add_filter( 'woocommerce_thankyou_order_received_text', function ( $text, $order ) {
	return $order ? 'Thank you! Your order has been received. One last step: send it to us on WhatsApp so we can confirm stock, delivery charges and your final total.' : $text;
}, 10, 2 );
add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) { return; }
	$u = tt_wa_store_url( tt_customer_order_message( $order ) );
	if ( ! $u ) { return; }
	$auto = ! $order->get_meta( '_tt_wa_opened' );
	if ( $auto ) { $order->update_meta_data( '_tt_wa_opened', time() ); $order->save(); }
	?>
	<div class="tt-wa-confirm">
		<div class="tt-wa-confirm-icon"><i class="fab fa-whatsapp"></i></div>
		<div class="tt-wa-confirm-body">
			<h3>Confirm order #<?php echo esc_html( $order->get_order_number() ); ?> on WhatsApp</h3>
			<p>Your order details are ready in a WhatsApp message. Just press <strong>Send</strong>. We reply with stock confirmation, delivery charges and your final total.</p>
			<a class="tt-wa-btn tt-wa-btn-lg" id="tt-wa-go" href="<?php echo tt_wa_href( $u ); // phpcs:ignore ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Send order on WhatsApp</a>
			<?php if ( $auto ) : ?><p class="tt-wa-auto" id="tt-wa-auto">Opening WhatsApp in <span>3</span>…</p><?php endif; ?>
			<ol class="tt-flow">
				<li>We check stock and reply on WhatsApp</li>
				<li>We confirm delivery charges: Bykea in Karachi, or a courier (Leopards / TCS / M&amp;P / PostEx) to other cities</li>
				<li>You confirm the final total</li>
				<li>We pack and dispatch your order</li>
				<li>You pay cash on delivery</li>
			</ol>
		</div>
	</div>
	<?php if ( $auto ) : ?>
	<script>(function(){var n=3,el=document.querySelector('#tt-wa-auto span');var t=setInterval(function(){n--;if(el){el.textContent=n;}if(n<=0){clearInterval(t);var a=document.getElementById('tt-wa-auto');if(a){a.textContent='If WhatsApp did not open, tap the green button above.';}window.location.href=<?php echo wp_json_encode( $u ); ?>;}},1000);})();</script>
	<?php endif;
}, 1 );

/* =========================================================
 * Admin: WhatsApp order desk on the order screen
 * ======================================================= */
add_action( 'add_meta_boxes', function () {
	$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	add_meta_box( 'tt-wa-desk', 'WhatsApp order desk', 'tt_wa_desk_box', $screen, 'side', 'high' );
} );
function tt_admin_templates( $order ) {
	$name  = $order->get_billing_first_name() ?: 'there';
	$n     = $order->get_order_number();
	$fee   = tt_delivery_fee( $order );
	$tot   = tt_plain_price( $order->get_total() );
	$via   = tt_courier_label( $order );
	$track = $order->get_meta( '_tt_tracking' );
	$addr  = $order->get_billing_address_1() . ', ' . $order->get_billing_city() . ( $order->get_billing_address_2() ? ' (landmark: ' . $order->get_billing_address_2() . ')' : '' );
	return array(
		'confirm'    => array( 'Send total for confirmation', "Assalam o Alaikum {$name}!\nThank you for your order with TechenTronics.\n\n*ORDER #{$n}*\n------------------------------\n\n" . tt_order_items_text( $order ) . "\n\n------------------------------\n*Subtotal:* " . tt_plain_price( $order->get_subtotal() ) . "\n*Delivery ({$via}):* " . ( null === $fee ? 'to be confirmed' : tt_plain_price( $fee ) ) . "\n*TOTAL: {$tot}*\n*Payment:* Cash on Delivery\n------------------------------\n\n*Deliver to:* {$addr}\n\nPlease reply *YES* to confirm your order." ),
		'outofstock' => array( 'Item out of stock', "Assalam o Alaikum {$name},\n\nThank you for order *#{$n}*. Sorry, one or more items are currently out of stock.\n\nWould you like us to suggest an alternative, or should we cancel the order?" ),
		'packed'     => array( 'Order packed', "Hi {$name},\n\nYour TechenTronics order *#{$n}* is packed and will be dispatched shortly. We will send you the delivery details soon." ),
		'dispatched' => array( 'Order dispatched', tt_is_karachi( $order )
			? "Hi {$name},\n\nYour order *#{$n}* is on its way with our rider ({$via}).\n\n*Amount to pay:* {$tot} (Cash on Delivery)\n\nThank you for shopping with TechenTronics!"
			: "Hi {$name},\n\nYour order *#{$n}* has been dispatched.\n\n*Courier:* {$via}" . ( $track ? "\n*Tracking number:* {$track}" : '' ) . "\n*Amount to pay:* {$tot} (Cash on Delivery)\n\nThank you for shopping with TechenTronics!" ),
		'completed'  => array( 'Delivered — thank you', "Thank you {$name}!\n\nYour order *#{$n}* has been delivered. We hope you enjoy it.\n\nNeed help with your product? Just message us here.\n— TechenTronics" ),
	);
}
function tt_wa_desk_box( $post_or_order ) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
	if ( ! $order ) { return; }
	$kar     = tt_is_karachi( $order );
	$courier = $order->get_meta( '_tt_courier' );
	$fee     = tt_delivery_fee( $order );
	$opts    = $kar ? array( 'Bykea', 'Local rider', 'Leopards', 'TCS', 'M&P', 'PostEx' ) : array( 'Leopards', 'TCS', 'M&P', 'PostEx', 'Bykea', 'Local rider' );
	echo '<div class="tt-desk">';
	echo '<p class="tt-desk-area"><span class="tt-pill ' . ( $kar ? 'tt-pill-k' : 'tt-pill-o' ) . '">' . ( $kar ? 'Inside Karachi' : 'Outside Karachi' ) . '</span> ' . esc_html( $order->get_billing_city() ) . '</p>';
	echo '<label>Delivery by</label><select name="tt_courier"><option value="">— choose —</option>';
	foreach ( $opts as $o ) { printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $o ), selected( $courier, $o, false ) ); }
	echo '</select>';
	echo '<label>Delivery charge (Rs)</label><input type="number" min="0" step="1" name="tt_delivery_fee" value="' . esc_attr( null === $fee ? '' : (string) (int) $fee ) . '" placeholder="e.g. 250">';
	echo '<label>Tracking number</label><input type="text" name="tt_tracking" value="' . esc_attr( $order->get_meta( '_tt_tracking' ) ) . '" placeholder="Courier tracking no.">';
	echo '<p class="description">Save the order (Update) to add the delivery charge to the total, then send the confirmation below.</p>';
	$phone = $order->get_billing_phone();
	if ( $phone ) {
		echo '<div class="tt-desk-btns">';
		foreach ( tt_admin_templates( $order ) as $k => $t ) {
			$u = tt_wa_customer_url( $phone, $t[1] );
			if ( $u ) { printf( '<a class="button tt-desk-btn tt-desk-%s" href="%s" target="_blank" rel="noopener">%s</a>', esc_attr( $k ), tt_wa_href( $u ), esc_html( $t[0] ) ); }
		}
		echo '</div><p class="description">Each button opens WhatsApp with a ready message to ' . esc_html( $phone ) . '. Then change the order status to match.</p>';
	}
	echo '</div>';
	wp_nonce_field( 'tt_wa_desk', 'tt_wa_desk_nonce' );
}
add_action( 'woocommerce_process_shop_order_meta', function ( $order_id ) {
	if ( empty( $_POST['tt_wa_desk_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tt_wa_desk_nonce'] ) ), 'tt_wa_desk' ) ) { return; }
	$order = wc_get_order( $order_id );
	if ( ! $order ) { return; }
	$courier = sanitize_text_field( wp_unslash( $_POST['tt_courier'] ?? '' ) );
	$order->update_meta_data( '_tt_courier', $courier );
	$order->update_meta_data( '_tt_tracking', sanitize_text_field( wp_unslash( $_POST['tt_tracking'] ?? '' ) ) );
	$raw = trim( (string) wp_unslash( $_POST['tt_delivery_fee'] ?? '' ) );
	foreach ( $order->get_fees() as $item_id => $fee ) { if ( $fee->get_meta( '_tt_delivery' ) ) { $order->remove_item( $item_id ); } }
	if ( '' !== $raw && is_numeric( $raw ) ) {
		$item = new WC_Order_Item_Fee();
		$item->set_name( 'Delivery' . ( $courier ? ' (' . $courier . ')' : '' ) );
		$item->set_amount( (float) $raw );
		$item->set_total( (float) $raw );
		$item->set_tax_status( 'none' );
		$item->add_meta_data( '_tt_delivery', 1, true );
		$order->add_item( $item );
	}
	$order->calculate_totals( false );
	$order->save();
}, 60 );
// WhatsApp quick link in the orders list.
$tt_cols = function ( $cols ) {
	$out = array();
	foreach ( $cols as $k => $v ) { $out[ $k ] = $v; if ( 'order_status' === $k ) { $out['tt_wa'] = 'WhatsApp'; $out['tt_area'] = 'Area'; } }
	return $out;
};
$tt_col_val = function ( $col, $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( ! $order ) { return; }
	if ( 'tt_wa' === $col ) {
		$u = tt_wa_customer_url( $order->get_billing_phone() );
		if ( $u ) { printf( '<a class="tt-col-wa" href="%s" target="_blank" rel="noopener" title="Chat on WhatsApp">%s</a>', tt_wa_href( $u ), esc_html( $order->get_billing_phone() ) ); }
	}
	if ( 'tt_area' === $col ) { echo tt_is_karachi( $order ) ? 'Karachi' : esc_html( $order->get_billing_city() ); }
};
add_filter( 'manage_woocommerce_page_wc-orders_columns', $tt_cols );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', $tt_col_val, 10, 2 );
add_filter( 'manage_edit-shop_order_columns', $tt_cols );
add_action( 'manage_shop_order_posts_custom_column', function ( $col, $post_id ) use ( $tt_col_val ) { $tt_col_val( $col, $post_id ); }, 10, 2 );

add_action( 'admin_head', function () {
	?>
	<style>
	.order-status.status-tt-await-wa{background:#fff4d6;color:#7a5600}
	.order-status.status-tt-confirmed{background:#dff1ff;color:#0a5aa8}
	.order-status.status-tt-packed{background:#ece6ff;color:#4b2fa0}
	.order-status.status-tt-dispatched{background:#d9f7e6;color:#126b3a}
	.tt-desk label{display:block;font-weight:600;margin:10px 0 4px}
	.tt-desk select,.tt-desk input{width:100%}
	.tt-desk-btns{display:flex;flex-direction:column;gap:6px;margin-top:12px}
	.tt-desk-btn.button{background:#25d366;border-color:#1eb558;color:#fff;text-align:center}
	.tt-desk-btn.button:hover{background:#1eb558;color:#fff}
	.tt-desk-outofstock.button{background:#fff;color:#b32d2e;border-color:#b32d2e}
	.tt-pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
	.tt-pill-k{background:#dff1ff;color:#0a5aa8}.tt-pill-o{background:#fff4d6;color:#7a5600}
	.tt-col-wa{color:#128c4b;font-weight:600}
	</style>
	<?php
} );

/* =========================================================
 * Floating WhatsApp button
 * ======================================================= */
add_action( 'wp_footer', function () {
	if ( is_admin() || ( function_exists( 'is_checkout' ) && is_checkout() ) ) { return; }
	$text = 'Hi TechenTronics, I have a question.';
	if ( function_exists( 'is_product' ) && is_product() ) {
		$p = wc_get_product( get_the_ID() );
		if ( $p ) { $text = tt_has_no_price( $p ) ? tt_ask_price_message( $p ) : tt_product_enquiry_message( $p ); }
	}
	$u = tt_wa_store_url( $text );
	if ( ! $u ) { return; }
	printf( '<a class="tt-wa-float" href="%s" target="_blank" rel="noopener" aria-label="Chat with us on WhatsApp"><i class="fab fa-whatsapp"></i><span>Chat with us</span></a>', tt_wa_href( $u ) );
}, 60 );

/* Android: open WhatsApp links straight in the app (WhatsApp or WhatsApp Business) instead of the wa.me web page; falls back to wa.me when no app is installed. */
add_action( 'wp_footer', function () {
	?>
	<script>
	(function(){
		if(!/Android/i.test(navigator.userAgent||'')){return;}
		function toIntent(href){
			try{
				var u=new URL(href, location.href), phone='', text='';
				if(/(^|\.)wa\.me$/i.test(u.hostname)){ phone=u.pathname.replace(/\D/g,''); text=u.searchParams.get('text')||''; }
				else if(/(^|\.)whatsapp\.com$/i.test(u.hostname) && /\/send/.test(u.pathname)){ phone=(u.searchParams.get('phone')||'').replace(/\D/g,''); text=u.searchParams.get('text')||''; }
				else { return null; }
				return 'intent://send/?phone='+phone+(text?'&text='+encodeURIComponent(text):'')+'#Intent;scheme=whatsapp;S.browser_fallback_url='+encodeURIComponent(u.toString())+';end';
			}catch(e){ return null; }
		}
		window.ttWaGo=function(h){ var i=toIntent(h); window.location.href=i||h; };
		document.addEventListener('click',function(e){
			var a=e.target&&e.target.closest?e.target.closest('a[href]'):null; if(!a){return;}
			var i=toIntent(a.href); if(!i){return;}
			e.preventDefault(); e.stopPropagation(); window.location.href=i;
		},true);
	})();
	</script>
	<?php
}, 99 );
