<?php
/**
 * Plugin Name: TechenTronics Store Design
 * Description: TechenTronics product cards, product page layout and shop heading. Keeps working if the Radios theme is updated.
 * Version: 1.1
 * Author: Coding Time Labs
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TT_TPL_DIR', __DIR__ . '/tt-templates/' );

/* Use our templates for the product card and the single product layout. */
add_filter( 'wc_get_template_part', function ( $template, $slug, $name ) {
	if ( 'content' === $slug && in_array( $name, array( 'product', 'single-product' ), true ) && file_exists( TT_TPL_DIR . "content-{$name}.php" ) ) {
		return TT_TPL_DIR . "content-{$name}.php";
	}
	return $template;
}, 50, 3 );

/* ---------- helpers ---------- */
function tt_primary_cat( $product_id ) {
	$terms = get_the_terms( $product_id, 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) { return null; }
	// Prefer the most specific (child) category.
	usort( $terms, function ( $a, $b ) { return ( $b->parent ? 1 : 0 ) - ( $a->parent ? 1 : 0 ); } );
	foreach ( $terms as $t ) { if ( 'uncategorized' !== $t->slug ) { return $t; } }
	return null;
}
function tt_discount_pct( $product ) {
	if ( ! $product->is_on_sale() ) { return 0; }
	if ( $product->is_type( 'variable' ) ) {
		$best = 0;
		foreach ( $product->get_children() as $cid ) {
			$v = wc_get_product( $cid );
			if ( $v && $v->is_on_sale() && (float) $v->get_regular_price() > 0 ) {
				$best = max( $best, round( ( (float) $v->get_regular_price() - (float) $v->get_sale_price() ) / (float) $v->get_regular_price() * 100 ) );
			}
		}
		return $best;
	}
	$r = (float) $product->get_regular_price(); $s = (float) $product->get_sale_price();
	return ( $r > 0 && $s > 0 && $s < $r ) ? round( ( $r - $s ) / $r * 100 ) : 0;
}
function tt_saving_amount( $product ) {
	if ( ! $product->is_on_sale() || ! $product->is_type( 'simple' ) ) { return 0; }
	$r = (float) $product->get_regular_price(); $s = (float) $product->get_sale_price();
	return ( $r > $s && $s > 0 ) ? $r - $s : 0;
}
function tt_stock_state( $product ) {
	if ( $product->is_on_backorder() ) { return array( 'key' => 'soon', 'label' => 'Coming soon' ); }
	if ( ! $product->is_in_stock() ) { return array( 'key' => 'out', 'label' => 'Out of stock' ); }
	$q = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
	if ( null !== $q && $q > 0 && $q <= 3 ) { return array( 'key' => 'low', 'label' => 'Only ' . $q . ' left' ); }
	return array( 'key' => 'in', 'label' => 'In stock' );
}
function tt_card_cart_button( $product ) {
	if ( function_exists( 'tt_has_no_price' ) && tt_has_no_price( $product ) && function_exists( 'tt_wa_store_url' ) && ( $u = tt_wa_store_url( tt_ask_price_message( $product ) ) ) ) {
		printf( '<a class="tt-pc-btn tt-pc-btn-wa" href="%s" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Ask price</a>', function_exists( 'tt_wa_href' ) ? tt_wa_href( $u ) : esc_url( $u ) );
		return;
	}
	if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		printf( '<a class="tt-pc-btn tt-pc-btn-ghost" href="%s">View details</a>', esc_url( get_permalink( $product->get_id() ) ) );
		return;
	}
	if ( $product->is_type( 'simple' ) ) {
		printf(
			'<a href="%s" data-quantity="1" data-product_id="%d" data-product_sku="%s" class="tt-pc-btn button add_to_cart_button ajax_add_to_cart product_type_simple" aria-label="%s" rel="nofollow"><i class="fas fa-cart-plus"></i> Add to cart</a>',
			esc_url( $product->add_to_cart_url() ), (int) $product->get_id(), esc_attr( $product->get_sku() ), esc_attr( 'Add ' . $product->get_name() . ' to cart' )
		);
		return;
	}
	printf( '<a class="tt-pc-btn" href="%s"><i class="fas fa-sliders-h"></i> Choose options</a>', esc_url( get_permalink( $product->get_id() ) ) );
}

/* ---------- Shop / category heading band ---------- */
add_action( 'woocommerce_before_main_content', function () {
	$is_brand = is_tax( 'product_brand' );
	if ( ! is_shop() && ! is_product_category() && ! is_product_tag() && ! is_search() && ! $is_brand ) { return; }
	static $done = false; if ( $done ) { return; } $done = true;
	echo '<div class="container tt-shop-head-wrap">';
	$title = 'Shop all products';
	$desc  = 'Genuine networking gear, computer accessories and mobile essentials, delivered across Pakistan with Cash on Delivery.';
	$chips = array();
	$current = 0;
	if ( is_product_category() ) {
		$term = get_queried_object();
		$current = $term->term_id;
		$title = $term->name;
		if ( $term->description ) { $desc = wp_strip_all_tags( $term->description ); }
		else { $desc = 'Browse our range of ' . strtolower( $term->name ) . '. Cash on Delivery across Pakistan.'; }
		$kids = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $term->term_id, 'hide_empty' => false ) );
		if ( $kids && ! is_wp_error( $kids ) ) { $chips = $kids; }
		elseif ( $term->parent ) { $chips = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $term->parent, 'hide_empty' => false ) ); }
	} elseif ( $is_brand ) {
		$term  = get_queried_object();
		$title = $term->name . ' products';
		$desc  = $term->description ? wp_strip_all_tags( $term->description ) : '';
	} elseif ( is_search() ) {
		$title = 'Search results for “' . get_search_query() . '”';
		$desc  = '';
	}
	if ( ! $chips ) {
		$chips = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false, 'slug' => array( 'networking', 'computer-accessories', 'mobile-accessories', 'audio', 'bags-sleeves' ) ) );
	}
	echo '<div class="tt-shop-head"><div class="tt-shop-head-text"><h1>' . esc_html( $title ) . '</h1>' . ( $desc ? '<p>' . esc_html( $desc ) . '</p>' : '' ) . '</div>';
	if ( $chips && ! is_wp_error( $chips ) ) {
		echo '<div class="tt-chips">';
		if ( is_product_category() ) {
			$up = get_queried_object()->parent ? get_term_link( get_queried_object()->parent, 'product_cat' ) : wc_get_page_permalink( 'shop' );
			echo '<a class="tt-chip tt-chip-back" href="' . esc_url( $up ) . '"><i class="fas fa-arrow-left"></i> Back</a>';
		}
		foreach ( $chips as $c ) {
			printf( '<a class="tt-chip%s" href="%s">%s</a>', $c->term_id === $current ? ' is-active' : '', esc_url( get_term_link( $c ) ), esc_html( $c->name ) );
		}
		echo '</div>';
	}
	echo '</div></div>';
}, 5 );
// Don't print the default category description twice.
remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );

/* Friendlier empty state */
add_filter( 'wc_get_template', function ( $located, $template_name ) {
	if ( 'loop/no-products-found.php' === $template_name && file_exists( TT_TPL_DIR . 'no-products-found.php' ) ) { return TT_TPL_DIR . 'no-products-found.php'; }
	return $located;
}, 50, 2 );

/* 12 products per page, 3 columns */
add_filter( 'loop_shop_per_page', function () { return 12; }, 30 );

/* Related products: 4 */
add_filter( 'woocommerce_output_related_products_args', function ( $a ) { $a['posts_per_page'] = 4; $a['columns'] = 4; return $a; } );

/* Keep the header cart counter in sync after AJAX add-to-cart. */
add_filter( 'woocommerce_add_to_cart_fragments', function ( $f ) {
	$n = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	$f['#mini-cart-count'] = '<span class="count" id="mini-cart-count">' . (int) $n . '</span>';
	return $f;
} );

/* ---------- Homepage: "Shop by category" tabbed product grid ---------- */
function tt_home_pick_ids( $slugs, $limit = 8 ) {
	$key = 'tt_hp_' . md5( implode( ',', $slugs ) . $limit );
	$ids = get_transient( $key );
	if ( false === $ids ) {
		$base = array( 'status' => 'publish', 'category' => $slugs, 'return' => 'ids', 'orderby' => 'rand', 'visibility' => 'catalog' );
		$ids  = wc_get_products( array_merge( $base, array( 'limit' => $limit, 'stock_status' => 'instock' ) ) );
		if ( count( $ids ) < $limit ) {
			$more = wc_get_products( array_merge( $base, array( 'limit' => $limit, 'exclude' => $ids ) ) );
			$ids  = array_slice( array_merge( $ids, $more ), 0, $limit );
		}
		set_transient( $key, $ids, 6 * HOUR_IN_SECONDS );
	}
	return $ids;
}
add_shortcode( 'tt_home_picks', function () {
	$tabs = array(
		'net'   => array( 'Networking & Wi-Fi', 'fas fa-wifi', array( 'networking' ), 'networking' ),
		'comp'  => array( 'Computer accessories', 'fas fa-keyboard', array( 'computer-accessories' ), 'computer-accessories' ),
		'mob'   => array( 'Mobile & power', 'fas fa-battery-three-quarters', array( 'mobile-accessories' ), 'mobile-accessories' ),
		'audio' => array( 'Audio & bags', 'fas fa-headphones', array( 'audio', 'bags-sleeves' ), 'audio' ),
	);
	ob_start();
	echo '<section class="tt-hp"><div class="tt-hp-head"><div class="tt-hp-title"><span class="tt-hp-eyebrow">Explore the range</span><h2>Shop by category</h2><p>Genuine TP-Link and Amaze products, hand-picked for home, office and on the go.</p></div><div class="tt-hp-tabs" role="tablist">';
	$i = 0;
	foreach ( $tabs as $k => $t ) {
		printf( '<button type="button" role="tab" class="tt-hp-tab%s" data-tt-tab="%s" aria-selected="%s"><i class="%s"></i><span>%s</span></button>', 0 === $i ? ' is-active' : '', esc_attr( $k ), 0 === $i ? 'true' : 'false', esc_attr( $t[1] ), esc_html( $t[0] ) );
		$i++;
	}
	echo '</div></div>';
	$i = 0;
	foreach ( $tabs as $k => $t ) {
		$ids  = tt_home_pick_ids( $t[2] );
		$term = get_term_by( 'slug', $t[3], 'product_cat' );
		$cq   = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => false, 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $t[2], 'include_children' => true ) ) ) ); // phpcs:ignore
		$cnt  = (int) $cq->found_posts;
		printf( '<div class="tt-hp-panel%s" data-tt-panel="%s" role="tabpanel"><div class="woocommerce"><ul class="products tt-hp-grid">', 0 === $i ? ' is-active' : '', esc_attr( $k ) );
		foreach ( $ids as $pid ) {
			$GLOBALS['post'] = get_post( $pid ); // phpcs:ignore
			setup_postdata( $GLOBALS['post'] );
			wc_get_template_part( 'content', 'product' );
		}
		wp_reset_postdata();
		echo '</ul></div>';
		if ( 'audio' === $k ) {
			printf( '<div class="tt-hp-more"><a href="%s">Browse the full shop <i class="fas fa-arrow-right"></i></a></div>', esc_url( wc_get_page_permalink( 'shop' ) ) );
		} elseif ( $term ) {
			printf( '<div class="tt-hp-more"><a href="%s">View all %d %s products <i class="fas fa-arrow-right"></i></a></div>', esc_url( get_term_link( $term ) ), (int) $cnt, esc_html( strtolower( $t[0] ) ) );
		}
		echo '</div>';
		$i++;
	}
	echo '</section>';
	?>
	<script>
	(function(){
		document.querySelectorAll('.tt-hp').forEach(function(root){
			root.querySelectorAll('.tt-hp-tab').forEach(function(btn){
				btn.addEventListener('click',function(){
					var k=btn.getAttribute('data-tt-tab');
					root.querySelectorAll('.tt-hp-tab').forEach(function(b){ var on=b===btn; b.classList.toggle('is-active',on); b.setAttribute('aria-selected',on?'true':'false'); });
					root.querySelectorAll('.tt-hp-panel').forEach(function(p){ p.classList.toggle('is-active',p.getAttribute('data-tt-panel')===k); });
				});
			});
		});
	})();
	</script>
	<?php
	return ob_get_clean();
} );

/* ---------- Homepage rows ("Trending Products", "Networking Essentials") using the same cards as the shop ---------- */
// Old theme widgets printed a compare button; keep any leftover shortcode silent.
if ( ! shortcode_exists( 'yith_compare_button' ) ) { add_shortcode( 'yith_compare_button', '__return_empty_string' ); }
add_shortcode( 'tt_product_row', function ( $a ) {
	$a     = shortcode_atts( array( 'mode' => 'featured', 'cat' => '', 'limit' => 4, 'more' => '' ), $a );
	$limit = max( 1, min( 12, (int) $a['limit'] ) );
	$cats  = array_filter( array_map( 'trim', explode( ',', $a['cat'] ) ) );
	$key   = 'tt_hp_row_' . md5( wp_json_encode( $a ) );
	$ids   = get_transient( $key );
	if ( false === $ids ) {
		$q = array( 'status' => 'publish', 'visibility' => 'catalog', 'limit' => 60, 'return' => 'ids', 'orderby' => 'rand', 'stock_status' => array( 'instock', 'onbackorder' ) );
		if ( $cats ) { $q['category'] = $cats; }
		if ( 'featured' === $a['mode'] ) { $q['featured'] = true; }
		$pool = wc_get_products( $q );
		if ( count( $pool ) < $limit && 'featured' === $a['mode'] ) { unset( $q['featured'] ); $pool = array_values( array_unique( array_merge( $pool, wc_get_products( $q ) ) ) ); }
		// Products marked "Show on homepage" first, then priced ones (customers can order them directly).
		$feat = array_flip( wc_get_featured_product_ids() );
		usort( $pool, function ( $x, $y ) use ( $feat ) {
			$fx = isset( $feat[ $x ] ); $fy = isset( $feat[ $y ] );
			if ( $fx !== $fy ) { return $fx ? -1 : 1; }
			$px = '' !== get_post_meta( $x, '_price', true ); $py = '' !== get_post_meta( $y, '_price', true );
			return $px === $py ? 0 : ( $px ? -1 : 1 );
		} );
		$ids = array_slice( $pool, 0, $limit );
		shuffle( $ids );
		set_transient( $key, $ids, 6 * HOUR_IN_SECONDS );
	}
	if ( ! $ids ) { return ''; }
	ob_start();
	echo '<div class="tt-row"><div class="woocommerce"><ul class="products tt-hp-grid">';
	foreach ( $ids as $pid ) {
		$GLOBALS['post'] = get_post( $pid ); // phpcs:ignore
		setup_postdata( $GLOBALS['post'] );
		wc_get_template_part( 'content', 'product' );
	}
	wp_reset_postdata();
	echo '</ul></div>';
	if ( $a['more'] && $cats && ( $term = get_term_by( 'slug', $cats[0], 'product_cat' ) ) ) {
		printf( '<div class="tt-hp-more"><a href="%s">%s <i class="fas fa-arrow-right"></i></a></div>', esc_url( get_term_link( $term ) ), esc_html( $a['more'] ) );
	} elseif ( $a['more'] ) {
		printf( '<div class="tt-hp-more"><a href="%s">%s <i class="fas fa-arrow-right"></i></a></div>', esc_url( wc_get_page_permalink( 'shop' ) ), esc_html( $a['more'] ) );
	}
	echo '</div>';
	static $css = false;
	if ( ! $css ) { $css = true; echo '<style>.tt-row{padding:6px 0 10px}@media (min-width:992px) and (max-width:1199px){.tt-row .woocommerce ul.products.tt-hp-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:14px}}@media (min-width:768px) and (max-width:991px){.tt-row .woocommerce ul.products.tt-hp-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}</style>'; }
	return ob_get_clean();
} );

/* ---------- Homepage: "How ordering works" band ---------- */
add_shortcode( 'tt_order_steps', function () {
	$wa   = function_exists( 'tt_wa_store_url' ) ? tt_wa_store_url( 'Hi TechenTronics, I would like to place an order.' ) : '';
	$shop = wc_get_page_permalink( 'shop' );
	$steps = array(
		array( 'fas fa-search', 'Pick your product', 'Browse genuine TP-Link and Amaze gear, or ask us what fits your setup.' ),
		array( 'fab fa-whatsapp', 'Order or ask on WhatsApp', 'Order online with your details, or message us for a price on WhatsApp.' ),
		array( 'fas fa-clipboard-check', 'We confirm everything', 'Stock, delivery charges and your final total, all on WhatsApp before dispatch.' ),
		array( 'fas fa-truck', 'Delivered, pay cash', 'Bykea in Karachi, trusted couriers nationwide. Pay when it arrives.' ),
	);
	ob_start();
	echo '<section class="tt-sb"><div class="tt-sb-intro"><span class="tt-hp-eyebrow">How ordering works</span><h2>Order in minutes, pay when it arrives</h2><p>Create a free account when you order to keep track of every purchase. We confirm each order with you personally on WhatsApp, and you pay cash when it arrives.</p><div class="tt-sb-actions">';
	if ( $wa ) { printf( '<a class="tt-sb-btn tt-sb-btn-wa" href="%s" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Chat on WhatsApp</a>', function_exists( 'tt_wa_href' ) ? tt_wa_href( $wa ) : esc_url( $wa ) ); }
	printf( '<a class="tt-sb-btn tt-sb-btn-ghost" href="%s">%s</a>', esc_url( is_user_logged_in() ? $shop : wc_get_page_permalink( 'myaccount' ) ), is_user_logged_in() ? 'Browse the shop' : '<i class="fas fa-user-plus"></i> Create free account' );
	echo '</div></div><ol class="tt-sb-steps">';
	foreach ( $steps as $n => $s ) {
		printf( '<li><span class="tt-sb-num">%02d</span><i class="%s"></i><h3>%s</h3><p>%s</p></li>', $n + 1, esc_attr( $s[0] ), esc_html( $s[1] ), esc_html( $s[2] ) );
	}
	echo '</ol></section>';
	return ob_get_clean();
} );

/* ---------- SEO helpers ---------- */
// Homepage hero: only the first slide title is the page's H1; the others become H2 (same look).
add_filter( 'elementor/widget/render_content', function ( $content, $widget ) {
	if ( 'radios-slide-banner' !== $widget->get_name() ) { return $content; }
	$n = 0;
	return preg_replace_callback( '#<h1(\s[^>]*class="slider-one_heading"[^>]*)>(.*?)</h1>#s', function ( $m ) use ( &$n ) {
		$n++;
		return 1 === $n ? $m[0] : '<h2' . $m[1] . '>' . $m[2] . '</h2>';
	}, $content );
}, 10, 2 );
// Our custom product layout skips the summary hook WooCommerce uses for Product structured data, so add it back.
add_action( 'woocommerce_after_single_product', function () {
	if ( function_exists( 'WC' ) && isset( WC()->structured_data ) && is_object( WC()->structured_data ) ) { WC()->structured_data->generate_product_data(); }
} );
// Product images: fall back to the product name as alt text.
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment ) {
	if ( empty( $attr['alt'] ) && $attachment && $attachment->post_parent && 'product' === get_post_type( $attachment->post_parent ) ) { $attr['alt'] = get_the_title( $attachment->post_parent ); }
	return $attr;
}, 10, 2 );

/* ---------- Mobile side menu: product-only search + WhatsApp / call buttons ---------- */
add_action( 'wp_footer', function () {
	if ( is_admin() ) { return; }
	$wa    = function_exists( 'tt_wa_store_url' ) ? tt_wa_store_url( 'Hi TechenTronics, I have a question.' ) : '';
	$phone = function_exists( 'tt_contact' ) ? tt_contact( 'phone' ) : '';
	$tel   = $phone ? 'tel:' . preg_replace( '/[^\d+]/', '', $phone ) : '';
	?>
	<script>
	(function(){
		var nav = document.querySelector('nav.side-mobile-menu');
		if (!nav) { return; }
		var f = nav.querySelector('.header-mobile-search form');
		if (f && !f.querySelector('input[name="post_type"]')) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'post_type'; h.value = 'product'; f.appendChild(h); var t = f.querySelector('input[name="s"]'); if (t) { t.placeholder = 'Search products'; } }
		var menu = nav.querySelector('#mobile-menu-active');
		if (menu && !nav.querySelector('.tt-sm-contact')) {
			var box = document.createElement('div'); box.className = 'tt-sm-contact';
			<?php if ( $wa ) : ?>box.innerHTML += '<a class="tt-sm-wa" href="<?php echo esc_js( function_exists( 'tt_wa_href' ) ? tt_wa_href( $wa ) : esc_url( $wa ) ); ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Chat on WhatsApp</a>';<?php endif; ?>
			<?php if ( $tel ) : ?>box.innerHTML += '<a class="tt-sm-call" href="<?php echo esc_js( $tel ); ?>"><i class="fas fa-phone"></i> Call <?php echo esc_js( $phone ); ?></a>';<?php endif; ?>
			menu.parentNode.insertBefore(box, menu.nextSibling);
		}
	})();
	</script>
	<?php
}, 30 );

/* ---------- Show prices as "Rs 1,234" everywhere (the ₨ sign looks different on some phones and in emails) ---------- */
add_filter( 'woocommerce_currency_symbol', function ( $symbol, $currency ) { return 'PKR' === $currency ? 'Rs' : $symbol; }, 10, 2 );

/* =========================================================
 * Colours: one product, several colours (chips on the product page, colour carried to WhatsApp, cart and order)
 * Meta: _tt_colours = array of names; _tt_colour_imgs = name => attachment id
 * ======================================================= */
function tt_colours( $product ) {
	$id = is_object( $product ) ? $product->get_id() : (int) $product;
	$c = get_post_meta( $id, '_tt_colours', true );
	return is_array( $c ) ? array_values( array_filter( $c ) ) : array();
}
add_action( 'tt_single_after_short', function ( $product ) {
	$cols = tt_colours( $product ); if ( ! $cols ) { return; }
	$imgs = (array) get_post_meta( $product->get_id(), '_tt_colour_imgs', true );
	$prices = tt_colour_prices( $product );
	echo '<div class="tt-colours" data-colours><span class="tt-colours-label">Colour: <b class="tt-colours-current">' . esc_html( $cols[0] ) . '</b></span><div class="tt-colours-list">';
	foreach ( $cols as $i => $c ) {
		$src = ! empty( $imgs[ $c ] ) ? wp_get_attachment_image_url( $imgs[ $c ], 'woocommerce_gallery_thumbnail' ) : '';
		$full = ! empty( $imgs[ $c ] ) ? wp_get_attachment_image_url( $imgs[ $c ], 'woocommerce_single' ) : '';
		printf( '<button type="button" class="tt-colour%s" data-colour="%s" data-img="%s" data-price="%s" aria-pressed="%s">%s<span>%s</span></button>', 0 === $i ? ' is-on' : '', esc_attr( $c ), esc_url( $full ), isset( $prices[ $c ] ) ? esc_attr( wc_price( $prices[ $c ] ) ) : '', 0 === $i ? 'true' : 'false', $src ? '<img src="' . esc_url( $src ) . '" alt="">' : '', esc_html( $c ) );
	}
	echo '</div></div>';
} );
// Hidden field inside the add-to-cart form so the choice travels with "Add to cart" and "Buy now".
add_action( 'woocommerce_before_add_to_cart_button', function () {
	global $product; if ( ! $product || ! ( $c = tt_colours( $product ) ) ) { return; }
	echo '<input type="hidden" name="tt_colour" class="tt-colour-input" value="' . esc_attr( $c[0] ) . '">';
} );
add_filter( 'woocommerce_add_cart_item_data', function ( $data, $product_id ) {
	$cols = tt_colours( $product_id ); if ( ! $cols ) { return $data; }
	$want = isset( $_REQUEST['tt_colour'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tt_colour'] ) ) : ''; // phpcs:ignore
	$data['tt_colour'] = in_array( $want, $cols, true ) ? $want : $cols[0];
	return $data;
}, 10, 2 );
add_filter( 'woocommerce_get_item_data', function ( $rows, $item ) {
	if ( ! empty( $item['tt_colour'] ) ) { $rows[] = array( 'key' => 'Colour', 'value' => esc_html( $item['tt_colour'] ) ); }
	return $rows;
}, 10, 2 );
add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $key, $values ) {
	if ( ! empty( $values['tt_colour'] ) ) { $item->add_meta_data( 'Colour', $values['tt_colour'], true ); }
}, 10, 3 );
// Small "N colours" note on shop cards.
add_action( 'woocommerce_after_shop_loop_item_title', function () {
	global $product; if ( $product && ( $n = count( tt_colours( $product ) ) ) > 1 ) { echo '<span class="tt-pc-colours">' . (int) $n . ' colours</span>'; }
}, 6 );
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) { return; }
	?>
	<style>
	.tt-colours{margin:14px 0 16px}.tt-colours-label{display:block;font-size:14px;color:var(--tt-muted,#5b6b80);margin-bottom:8px}.tt-colours-label b{color:var(--tt-ink,#0b1a33)}
	.tt-colours-list{display:flex;flex-wrap:wrap;gap:8px}
	.tt-colour{display:inline-flex;align-items:center;gap:7px;padding:5px 12px 5px 5px;border:1.5px solid var(--tt-line,#dbe3ee);border-radius:24px;background:#fff;font:600 13px/1 inherit;color:var(--tt-ink,#0b1a33);cursor:pointer;transition:border-color .15s}
	.tt-colour:not(:has(img)){padding:8px 14px}.tt-colour img{width:30px;height:30px;border-radius:50%;object-fit:cover;background:#f3f6fb}
	.tt-colour:hover{border-color:#9fc9ef}.tt-colour.is-on{border-color:var(--tt-blue,#0AA2F5);box-shadow:0 0 0 3px rgba(10,162,245,.15)}
	</style>
	<script>
	(function(){
		var box=document.querySelector('[data-colours]'); if(!box){return;}
		function setWa(c){ document.querySelectorAll('.tt-sp-summary a[href*="wa.me"], .tt-sticky-buy a[href*="wa.me"]').forEach(function(a){ try{ var u=new URL(a.href); var t=u.searchParams.get('text'); if(!t){return;} t=t.replace(/(\*Colour:\* )[^\n]*/, '$1'+c); u.searchParams.set('text',t); a.href=u.toString().replace(/\+/g,'%20'); }catch(e){} }); }
		box.addEventListener('click',function(e){
			var b=e.target.closest('.tt-colour'); if(!b){return;}
			box.querySelectorAll('.tt-colour').forEach(function(x){ x.classList.toggle('is-on',x===b); x.setAttribute('aria-pressed',x===b?'true':'false'); });
			var c=b.dataset.colour; box.querySelector('.tt-colours-current').textContent=c;
			document.querySelectorAll('.tt-colour-input').forEach(function(i){ i.value=c; });
			setWa(c);
			if(b.dataset.price){ document.querySelectorAll('.tt-sp-summary .price, .summary .price, .tt-sticky-buy .price, .tt-sticky-price').forEach(function(p){ p.innerHTML=b.dataset.price; }); }
			var img=b.dataset.img; if(img){ var file=img.split('/').pop().replace(/-\d+x\d+(?=\.)/,'').replace(/\.\w+$/,''); var thumbs=document.querySelectorAll('.flex-control-nav img, .woocommerce-product-gallery__image img'); for(var i=0;i<thumbs.length;i++){ var s=(thumbs[i].getAttribute('src')||'')+(thumbs[i].getAttribute('data-src')||''); if(s.indexOf(file)>-1 && thumbs[i].closest('.flex-control-nav')){ thumbs[i].click(); return; } } }
		});
	})();
	</script>
	<?php
}, 40 );

/* Per-colour prices: _tt_colour_prices = colour => price. Used only while the first colour's price still equals the product price,
 * so if the owner edits the price in Store Products, every colour simply uses the new single price. */
function tt_colour_prices( $product ) {
	$p = is_object( $product ) ? $product : wc_get_product( $product ); if ( ! $p ) { return array(); }
	$map = get_post_meta( $p->get_id(), '_tt_colour_prices', true ); $cols = tt_colours( $p );
	if ( ! is_array( $map ) || ! $map || ! $cols || ! isset( $map[ $cols[0] ] ) ) { return array(); }
	if ( (float) $map[ $cols[0] ] !== (float) $p->get_regular_price() ) { return array(); }
	return array_intersect_key( $map, array_flip( $cols ) );
}
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( is_admin() && ! wp_doing_ajax() ) { return; }
	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['tt_colour'] ) || empty( $item['data'] ) ) { continue; }
		$map = tt_colour_prices( $item['product_id'] );
		if ( isset( $map[ $item['tt_colour'] ] ) && $map[ $item['tt_colour'] ] > 0 ) { $item['data']->set_price( (float) $map[ $item['tt_colour'] ] ); }
	}
}, 20 );

/* Top-bar About / Contact links (desktop menu keeps one line; mobile menu still lists them) */
add_action( 'wp_footer', function () {
	$a = get_page_by_path( 'about' ); $c = get_page_by_path( 'contact' );
	$links = array(); if ( $a ) { $links[] = array( get_permalink( $a ), 'About us' ); } if ( $c ) { $links[] = array( get_permalink( $c ), 'Contact' ); }
	if ( ! $links ) { return; }
	$html = '<nav class="tt-toplinks" aria-label="Company">'; foreach ( $links as $l ) { $html .= '<a href="' . esc_url( $l[0] ) . '">' . esc_html( $l[1] ) . '</a>'; } $html .= '</nav>';
	echo '<script>(function(){var b=document.querySelector(".header-top .right-box");if(b&&!b.querySelector(".tt-toplinks")){b.insertAdjacentHTML("afterbegin",' . wp_json_encode( $html ) . ');}})();</script>';
}, 5 );

/* Cart / checkout thumbnail shows the colour the customer picked. */
add_filter( 'woocommerce_cart_item_thumbnail', function ( $img, $item ) {
	if ( empty( $item['tt_colour'] ) || empty( $item['product_id'] ) ) { return $img; }
	$map = (array) get_post_meta( $item['product_id'], '_tt_colour_imgs', true );
	return ! empty( $map[ $item['tt_colour'] ] ) ? wp_get_attachment_image( (int) $map[ $item['tt_colour'] ], 'woocommerce_thumbnail' ) : $img;
}, 10, 2 );
