<?php
/**
 * Plugin Name: TechenTronics Store Panel
 * Description: Branded login, a simple Store Panel on the dashboard for managing products, prices and orders, and a cleaner admin for the Shop Manager.
 * Version: 1.0
 * Author: Coding Time Labs
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Never print PHP notices/warnings on the public site (they are still written to the error log).
if ( ! is_admin() ) { @ini_set( 'display_errors', '0' ); }

/* ---------- Login page branding ---------- */
add_action( 'login_enqueue_scripts', function () {
	$logo = function_exists( 'cs_get_option' ) ? cs_get_option( 'theme_logo' ) : array();
	$url  = ! empty( $logo['url'] ) ? $logo['url'] : '';
	?>
	<style>
		body.login { background: radial-gradient(circle at 50% 0%, #0d2a5c 0%, #07122b 60%); }
		body.login #login h1 a { <?php if ( $url ) : ?>background-image: url('<?php echo esc_url( $url ); ?>');<?php endif; ?> background-size: contain; background-position: center; width: 300px; height: 70px; }
		body.login form { border-radius: 10px; border: 1px solid #12305f; box-shadow: 0 10px 30px rgba(0,0,0,.35); }
		body.login #backtoblog a, body.login #nav a, body.login .privacy-policy-page-link a { color: #9fd8ff !important; }
		.wp-core-ui .button-primary { background: #0AA2F5; border-color: #0A6CD8; }
		.wp-core-ui .button-primary:hover { background: #0A6CD8; }
	</style>
	<?php
} );
add_filter( 'login_headerurl', function () { return home_url( '/' ); } );
add_filter( 'login_headertext', function () { return 'TechenTronics'; } );

/* ---------- Store Panel dashboard widget ---------- */
function tt_panel_buttons() {
	$b = array(
		array( 'Orders', 'admin.php?page=wc-orders', 'dashicons-cart', 'See and process new orders' ),
		array( 'Products & Prices', 'admin.php?page=tt-products', 'dashicons-store', 'Change prices, stock, photos, details' ),
		array( 'Add Product', 'admin.php?page=tt-product-edit', 'dashicons-plus-alt', 'List a new item with photos' ),
		array( 'No Price Yet', 'admin.php?page=tt-products&view=noprice', 'dashicons-tag', 'Products showing “Price on WhatsApp”' ),
		array( 'Import from Excel', 'edit.php?post_type=product&page=product_importer', 'dashicons-upload', 'Upload a CSV price list' ),
		array( 'Export to Excel', 'edit.php?post_type=product&page=product_exporter', 'dashicons-download', 'Download all products as CSV' ),
		array( 'Categories', 'edit-tags.php?taxonomy=product_cat&post_type=product', 'dashicons-category', 'Organise the shop' ),
		array( 'Customers', 'users.php?role=customer', 'dashicons-groups', 'Registered customers' ),
		array( 'Contact Info', 'admin.php?page=tt-contact', 'dashicons-phone', 'Phone, WhatsApp, social links' ),
	);
	return $b;
}
add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( 'edit_products' ) ) { return; }
	wp_add_dashboard_widget( 'tt_store_panel', 'TechenTronics Store Panel', function () {
		$processing = function_exists( 'wc_orders_count' ) ? wc_orders_count( 'processing' ) : 0;
		$pending    = function_exists( 'wc_orders_count' ) ? wc_orders_count( 'pending' ) : 0;
		$low = 0;
		if ( function_exists( 'wc_get_products' ) ) {
			$low = count( wc_get_products( array( 'limit' => 200, 'stock_status' => 'outofstock', 'return' => 'ids' ) ) );
		}
		echo '<div class="tt-stats"><div><strong>' . (int) $processing . '</strong><span>Orders to ship</span></div><div><strong>' . (int) $pending . '</strong><span>Awaiting payment</span></div><div><strong>' . (int) $low . '</strong><span>Out of stock</span></div></div>';
		echo '<div class="tt-grid">';
		foreach ( tt_panel_buttons() as $x ) {
			printf( '<a class="tt-btn" href="%s"><span class="dashicons %s"></span><b>%s</b><small>%s</small></a>', esc_url( admin_url( $x[1] ) ), esc_attr( $x[2] ), esc_html( $x[0] ), esc_html( $x[3] ) );
		}
		echo '</div>';
	} );
	// Put it first.
	global $wp_meta_boxes;
	$w = $wp_meta_boxes['dashboard']['normal']['core']['tt_store_panel'] ?? null;
	if ( $w ) {
		unset( $wp_meta_boxes['dashboard']['normal']['core']['tt_store_panel'] );
		$wp_meta_boxes['dashboard']['normal']['high']['tt_store_panel'] = $w;
	}
	// Less clutter for the shop manager.
	if ( ! current_user_can( 'manage_options' ) ) {
		foreach ( array( 'dashboard_primary', 'dashboard_quick_press', 'dashboard_site_health', 'dashboard_activity', 'e-dashboard-overview', 'yith_dashboard_products_news', 'yith_dashboard_blog_news' ) as $id ) {
			remove_meta_box( $id, 'dashboard', 'normal' );
			remove_meta_box( $id, 'dashboard', 'side' );
		}
	}
}, 99 );

add_action( 'admin_head', function () {
	?>
	<style>
		#tt_store_panel .postbox-header { background: #07122b; }
		#tt_store_panel .postbox-header h2 { color: #fff; }
		#tt_store_panel .tt-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 4px 0 14px; }
		#tt_store_panel .tt-stats div { background: #eef7ff; border: 1px solid #cfe8ff; border-radius: 8px; padding: 10px; text-align: center; }
		#tt_store_panel .tt-stats strong { display: block; font-size: 24px; color: #0A6CD8; line-height: 1.2; }
		#tt_store_panel .tt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
		#tt_store_panel .tt-btn { display: flex; flex-direction: column; gap: 4px; padding: 12px; border: 1px solid #dbe3ee; border-radius: 8px; text-decoration: none; color: #0b1a33; background: #fff; }
		#tt_store_panel .tt-btn:hover { border-color: #0AA2F5; box-shadow: 0 2px 8px rgba(10,162,245,.2); }
		#tt_store_panel .tt-btn .dashicons { color: #0AA2F5; font-size: 24px; width: 24px; height: 24px; }
		#tt_store_panel .tt-btn small { color: #5b6b80; }
		.tt-prices-table input.tt-num { width: 110px; }
		.tt-prices-table td, .tt-prices-table th { vertical-align: middle; }
		.tt-prices-table tr.tt-changed { background: #fff8e1; }
	</style>
	<?php
} );

add_filter( 'admin_footer_text', function () { return 'TechenTronics Store Panel &middot; built by Coding Time Labs'; } );

/* ---------- Update Prices screen: every product in one editable table ---------- */
add_action( 'admin_menu', function () {
	// Replaced by Store Products (techentronics-product-manager.php); old URL redirects there.
} );

function tt_prices_page() {
	if ( ! current_user_can( 'edit_products' ) || ! function_exists( 'wc_get_products' ) ) { return; }
	$saved = 0;
	if ( isset( $_POST['tt_prices_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['tt_prices_nonce'] ), 'tt_prices' ) ) {
		$rows = isset( $_POST['p'] ) && is_array( $_POST['p'] ) ? wp_unslash( $_POST['p'] ) : array();
		foreach ( $rows as $id => $r ) {
			if ( empty( $r['changed'] ) ) { continue; }
			$product = wc_get_product( absint( $id ) );
			if ( ! $product || ! current_user_can( 'edit_post', $product->get_id() ) ) { continue; }
			$reg  = isset( $r['regular'] ) ? wc_format_decimal( $r['regular'] ) : '';
			$sale = isset( $r['sale'] ) ? wc_format_decimal( $r['sale'] ) : '';
			if ( $sale !== '' && $reg !== '' && (float) $sale >= (float) $reg ) { $sale = ''; }
			$product->set_regular_price( $reg );
			$product->set_sale_price( $sale );
			if ( isset( $r['stock'] ) && $r['stock'] !== '' && $product->managing_stock() ) {
				$product->set_stock_quantity( (int) $r['stock'] );
			}
			if ( isset( $r['status'] ) && in_array( $r['status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) && ! $product->managing_stock() ) {
				$product->set_stock_status( $r['status'] );
			}
			$product->save();
			$saved++;
		}
	}
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$cat    = isset( $_GET['cat'] ) ? sanitize_title( wp_unslash( $_GET['cat'] ) ) : '';
	$args   = array( 'limit' => 500, 'orderby' => 'title', 'order' => 'ASC', 'type' => array( 'simple' ), 'status' => array( 'publish', 'draft', 'private' ) );
	if ( $search ) { $args['s'] = $search; }
	if ( $cat ) { $args['category'] = array( $cat ); }
	$products = wc_get_products( $args );
	// Include variations of variable products so each length/colour can be priced.
	$var_parents = wc_get_products( array_merge( $args, array( 'type' => array( 'variable' ) ) ) );
	foreach ( $var_parents as $vp ) {
		foreach ( $vp->get_children() as $cid ) { $c = wc_get_product( $cid ); if ( $c ) { $products[] = $c; } }
	}
	usort( $products, function ( $a, $b ) { return strcasecmp( $a->get_name(), $b->get_name() ); } );
	$cur = get_woocommerce_currency_symbol();
	?>
	<div class="wrap">
		<h1>Update Prices &amp; Stock</h1>
		<p>Change any price or stock number, then press <strong>Save changes</strong>. Only the rows you change are saved. Leave <em>Sale price</em> empty if there is no discount.</p>
		<?php if ( $saved ) : ?><div class="notice notice-success"><p><?php echo (int) $saved; ?> product(s) updated.</p></div><?php endif; ?>
		<form method="get" style="margin:10px 0">
			<input type="hidden" name="post_type" value="product"><input type="hidden" name="page" value="tt-prices">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by name or model">
			<?php wc_product_dropdown_categories( array( 'show_option_none' => 'All categories', 'option_none_value' => '', 'name' => 'cat', 'selected' => $cat, 'value_field' => 'slug' ) ); ?>
			<button class="button">Filter</button>
		</form>
		<form method="post">
			<?php wp_nonce_field( 'tt_prices', 'tt_prices_nonce' ); ?>
			<table class="widefat striped tt-prices-table">
				<thead><tr><th>Product</th><th>SKU / Model</th><th>Regular price (<?php echo esc_html( $cur ); ?>)</th><th>Sale price (<?php echo esc_html( $cur ); ?>)</th><th>Stock</th></tr></thead>
				<tbody>
				<?php foreach ( $products as $p ) : $id = $p->get_id(); ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $p->get_parent_id() ? $p->get_parent_id() : $id ) ); ?>"><?php echo esc_html( $p->get_name() ); ?></a><input type="hidden" name="p[<?php echo (int) $id; ?>][changed]" value="" class="tt-flag"></td>
						<td><?php echo esc_html( $p->get_sku() ); ?></td>
						<td><input class="tt-num" type="number" min="0" step="1" name="p[<?php echo (int) $id; ?>][regular]" value="<?php echo esc_attr( $p->get_regular_price() ); ?>"></td>
						<td><input class="tt-num" type="number" min="0" step="1" name="p[<?php echo (int) $id; ?>][sale]" value="<?php echo esc_attr( $p->get_sale_price() ); ?>"></td>
						<td>
						<?php if ( $p->managing_stock() ) : ?>
							<input class="tt-num" type="number" step="1" name="p[<?php echo (int) $id; ?>][stock]" value="<?php echo esc_attr( $p->get_stock_quantity() ); ?>">
						<?php else : ?>
							<select name="p[<?php echo (int) $id; ?>][status]">
								<?php foreach ( array( 'instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'Coming soon / backorder' ) as $k => $l ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $p->get_stock_status(), $k ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $products ) : ?><tr><td colspan="5">No products found.</td></tr><?php endif; ?>
				</tbody>
			</table>
			<p><button class="button button-primary button-large">Save changes</button></p>
		</form>
	</div>
	<script>
	var ttForm = document.querySelector('.tt-prices-table').closest('form');
	ttForm.addEventListener('submit', function(){
		// Only send the rows that were changed (keeps big catalogues within server limits).
		ttForm.querySelectorAll('tbody tr:not(.tt-changed) input, tbody tr:not(.tt-changed) select').forEach(function(el){ el.disabled = true; });
	});
	document.querySelectorAll('.tt-prices-table tbody tr').forEach(function(tr){
		tr.querySelectorAll('input:not(.tt-flag),select').forEach(function(el){
			el.addEventListener('change', function(){ tr.classList.add('tt-changed'); tr.querySelector('.tt-flag').value = '1'; });
		});
	});
	</script>
	<?php
}

/* ---------- Store Contact Info: one place that updates header, footer and Contact page ---------- */
function tt_contact( $key = null ) {
	$d = wp_parse_args( (array) get_option( 'tt_contact', array() ), array(
		'phone' => '', 'whatsapp' => '', 'email' => 'info@techentronics.com', 'address' => 'Karachi, Pakistan',
		'hours' => '', 'facebook' => '', 'instagram' => '', 'tiktok' => '',
	) );
	return $key ? ( $d[ $key ] ?? '' ) : $d;
}
function tt_wa_link( $num ) {
	$n = preg_replace( '/\D+/', '', $num );
	if ( 0 === strpos( $n, '0' ) ) { $n = '92' . substr( $n, 1 ); }
	return $n ? 'https://wa.me/' . $n : '';
}
function tt_sync_contact_to_theme() {
	$c = tt_contact();
	$o = get_option( 'radios_theme_options', array() );
	if ( ! is_array( $o ) ) { $o = array(); }
	$phone = $c['phone'] ? $c['phone'] : '';
	$o['email_h_address'] = $phone ? '<strong>Call / WhatsApp</strong> ' . esc_html( $phone ) . ' <br>' : '<strong>Customer Support</strong> <br>';
	$o['phone_h_address'] = '<strong>E-mail:</strong> ' . esc_html( $c['email'] );
	$o['call_us']         = $phone ? 'Call / WhatsApp: ' . esc_html( $phone ) : 'Contact Us';
	$o['call_us_phone']   = $phone ? preg_replace( '/[^\d+]/', '', $phone ) : '';
	$o['email_address']   = $c['email'];
	$info = array( array( 'icon' => 'fas fa-map-marker-alt', 'contact_title' => $c['address'] ) );
	if ( $phone ) { $info[] = array( 'icon' => 'fas fa-phone', 'contact_title' => $phone ); }
	$info[] = array( 'icon' => 'fas fa-envelope', 'contact_title' => $c['email'] );
	$o['footer_contact_info'] = $info;
	$soc = array();
	if ( $c['facebook'] ) { $soc[] = array( 'icon' => 'fab fa-facebook-f', 'link' => $c['facebook'], 'social_link' => $c['facebook'], 'title' => '' ); }
	if ( $c['instagram'] ) { $soc[] = array( 'icon' => 'fab fa-instagram', 'link' => $c['instagram'], 'social_link' => $c['instagram'], 'title' => '' ); }
	if ( $c['tiktok'] ) { $soc[] = array( 'icon' => 'fab fa-tiktok', 'link' => $c['tiktok'], 'social_link' => $c['tiktok'], 'title' => '' ); }
	$wa = tt_wa_link( $c['whatsapp'] ? $c['whatsapp'] : $c['phone'] );
	if ( $wa ) { $soc[] = array( 'icon' => 'fab fa-whatsapp', 'link' => $wa, 'social_link' => $wa, 'title' => '' ); }
	$o['top_bar_social'] = $soc;
	$o['footer_social']  = $soc;
	update_option( 'radios_theme_options', $o );
}
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Store Contact Info', 'Store Contact Info', 'manage_woocommerce', 'tt-contact', 'tt_contact_page', 2 );
} );
function tt_contact_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
	$fields = array(
		'phone' => array( 'Phone number', '03XX-XXXXXXX', 'Shown in the header, footer and Contact page. Leave empty to hide the Call button.' ),
		'whatsapp' => array( 'WhatsApp number', '03XX-XXXXXXX', 'Leave empty to use the phone number.' ),
		'email' => array( 'Email', 'info@techentronics.com', '' ),
		'address' => array( 'Address / city', 'Karachi, Pakistan', 'Shown in the footer and Contact page.' ),
		'hours' => array( 'Opening hours', 'e.g. Mon to Sat, 11 AM to 8 PM', 'Leave empty to hide.' ),
		'facebook' => array( 'Facebook page link', 'https://facebook.com/...', 'Icons only appear for links you fill in.' ),
		'instagram' => array( 'Instagram link', 'https://instagram.com/...', '' ),
		'tiktok' => array( 'TikTok link', 'https://tiktok.com/@...', '' ),
	);
	if ( isset( $_POST['tt_contact_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['tt_contact_nonce'] ), 'tt_contact' ) ) {
		$v = array();
		foreach ( $fields as $k => $f ) {
			$raw = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
			$v[ $k ] = in_array( $k, array( 'facebook', 'instagram', 'tiktok' ), true ) ? esc_url_raw( $raw ) : ( 'email' === $k ? sanitize_email( $raw ) : sanitize_text_field( $raw ) );
		}
		update_option( 'tt_contact', $v );
		tt_sync_contact_to_theme();
		echo '<div class="notice notice-success"><p>Saved. The header, footer and Contact page now show these details.</p></div>';
	}
	$c = tt_contact();
	echo '<div class="wrap"><h1>Store Contact Info</h1><p>These details appear across the whole website.</p><form method="post"><table class="form-table">';
	wp_nonce_field( 'tt_contact', 'tt_contact_nonce' );
	foreach ( $fields as $k => $f ) {
		printf( '<tr><th><label for="%1$s">%2$s</label></th><td><input class="regular-text" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s"><p class="description">%5$s</p></td></tr>', esc_attr( $k ), esc_html( $f[0] ), esc_attr( $c[ $k ] ), esc_attr( $f[1] ), esc_html( $f[2] ) );
	}
	echo '</table><p><button class="button button-primary button-large">Save contact info</button></p></form></div>';
}
add_filter( 'tt_panel_extra_buttons', '__return_empty_array' );

// [tt_contact_cards] — contact details + action buttons (buttons only appear when the detail exists)
add_shortcode( 'tt_contact_cards', function () {
	$c = tt_contact();
	$wa = tt_wa_link( $c['whatsapp'] ? $c['whatsapp'] : $c['phone'] );
	$cards = array();
	if ( $c['phone'] ) { $cards[] = array( 'fas fa-phone', 'Call us', esc_html( $c['phone'] ), 'tel:' . preg_replace( '/[^\d+]/', '', $c['phone'] ), 'Call now' ); }
	if ( $wa ) { $cards[] = array( 'fab fa-whatsapp', 'WhatsApp', esc_html( $c['whatsapp'] ? $c['whatsapp'] : $c['phone'] ), $wa, 'Chat on WhatsApp' ); }
	if ( $c['email'] ) { $cards[] = array( 'fas fa-envelope', 'Email', esc_html( $c['email'] ), 'mailto:' . antispambot( $c['email'] ), 'Send an email' ); }
	$cards[] = array( 'fas fa-map-marker-alt', 'Location', esc_html( $c['address'] ), '', '' );
	if ( $c['hours'] ) { $cards[] = array( 'fas fa-clock', 'Opening hours', esc_html( $c['hours'] ), '', '' ); }
	$h = '<div class="tt-contact-grid">';
	foreach ( $cards as $x ) {
		$h .= '<div class="tt-contact-card"><span class="tt-ic"><i class="' . esc_attr( $x[0] ) . '"></i></span><h3>' . esc_html( $x[1] ) . '</h3><p>' . $x[2] . '</p>';
		if ( $x[3] ) { $h .= '<a class="tt-btn-sm" href="' . esc_url( $x[3], array( 'http', 'https', 'tel', 'mailto' ) ) . '"' . ( 0 === strpos( $x[3], 'http' ) ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $x[4] ) . '</a>'; }
		$h .= '</div>';
	}
	return $h . '</div>';
} );
add_shortcode( 'tt_shop_url', function ( $a ) { $a = shortcode_atts( array( 'cat' => '' ), $a ); if ( $a['cat'] ) { $l = get_term_link( $a['cat'], 'product_cat' ); if ( ! is_wp_error( $l ) ) { return esc_url( $l ); } } return esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) ); } );
add_shortcode( 'tt_my_account_url', function () { return esc_url( wc_get_page_permalink( 'myaccount' ) ); } );
