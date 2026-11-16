<?php
/**
 * TechenTronics single product layout.
 */
defined( 'ABSPATH' ) || exit;
global $product;
do_action( 'woocommerce_before_single_product' );
if ( post_password_required() ) { echo get_the_password_form(); return; } // phpcs:ignore
$id    = $product->get_id();
$cat   = tt_primary_cat( $id );
$pct   = tt_discount_pct( $product );
$save  = tt_saving_amount( $product );
$stock = tt_stock_state( $product );
$c     = function_exists( 'tt_contact' ) ? tt_contact() : array();
$phone = $c['phone'] ?? '';
$wa    = function_exists( 'tt_wa_link' ) ? tt_wa_link( ( $c['whatsapp'] ?? '' ) ? $c['whatsapp'] : $phone ) : '';
$wa_msg = rawurlencode( function_exists( 'tt_product_enquiry_message' ) ? tt_product_enquiry_message( $product ) : 'Hi TechenTronics, I am interested in: ' . $product->get_name() . ' ' . get_permalink( $id ) );
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'tt-sp-wrap', $product ); ?>>
	<div class="tt-sp">
		<div class="tt-sp-gallery">
			<?php do_action( 'woocommerce_before_single_product_summary' ); ?>
		</div>
		<div class="tt-sp-summary summary entry-summary">
			<?php if ( $cat ) : ?><a class="tt-eyebrow tt-sp-cat" href="<?php echo esc_url( get_term_link( $cat ) ); ?>"><?php echo esc_html( $cat->name ); ?></a><?php endif; ?>
			<h1 class="product_title entry-title tt-sp-title"><?php echo esc_html( $product->get_name() ); ?></h1>
			<?php if ( $product->get_rating_count() > 0 ) : ?>
				<div class="tt-sp-rating"><?php woocommerce_template_single_rating(); ?></div>
			<?php endif; ?>
			<div class="tt-sp-price-row">
				<div class="tt-sp-price price"><?php echo $product->get_price_html(); // phpcs:ignore ?></div>
				<?php if ( $save ) : ?><span class="tt-save">You save <?php echo wp_kses_post( wc_price( $save ) ); ?><?php echo $pct ? ' (' . (int) $pct . '%)' : ''; ?></span><?php endif; ?>
			</div>
			<div class="tt-sp-meta-row">
				<span class="tt-stock tt-stock-<?php echo esc_attr( $stock['key'] ); ?>"><i class="fas fa-circle"></i> <?php echo esc_html( $stock['label'] ); ?></span>
				<?php if ( $product->get_sku() ) : ?><span class="tt-sku">Model: <strong><?php echo esc_html( $product->get_sku() ); ?></strong></span><?php endif; ?>
			</div>
			<?php if ( $product->get_short_description() ) : ?>
				<div class="tt-sp-short"><?php echo wp_kses_post( apply_filters( 'woocommerce_short_description', $product->get_short_description() ) ); ?></div>
			<?php endif; ?>
			<?php do_action( 'tt_single_after_short', $product ); ?>
			<?php if ( function_exists( 'tt_has_no_price' ) && tt_has_no_price( $product ) ) : ?>
				<?php $ask = function_exists( 'tt_wa_store_url' ) ? tt_wa_store_url( tt_ask_price_message( $product ) ) : ''; ?>
				<?php if ( $ask ) : ?>
					<a class="tt-wa-btn tt-wa-btn-lg" href="<?php echo tt_wa_href( $ask ); // phpcs:ignore ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Ask for price on WhatsApp</a>
					<p class="tt-por-note">Prices change often, so we share the latest price and stock on WhatsApp.</p>
				<?php endif; ?>
			<?php else : ?>
				<div class="tt-sp-cart"><?php woocommerce_template_single_add_to_cart(); ?></div>
				<?php if ( $wa ) : ?>
					<a class="tt-wa-btn" href="<?php echo function_exists( 'tt_wa_href' ) ? tt_wa_href( $wa . '?text=' . $wa_msg ) : esc_url( $wa . '?text=' . $wa_msg ); // phpcs:ignore ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Ask about this product on WhatsApp</a>
				<?php endif; ?>
			<?php endif; ?>
			<ul class="tt-trust">
				<li><i class="fas fa-money-bill-wave"></i><div><strong>Cash on Delivery</strong><span>Order confirmed on WhatsApp, pay on arrival</span></div></li>
				<li><i class="fas fa-truck"></i><div><strong>Delivery across Pakistan</strong><span>Bykea in Karachi, courier to other cities</span></div></li>
				<li><i class="fas fa-check-circle"></i><div><strong>Genuine product</strong><span>Original <?php echo $cat ? esc_html( $cat->name ) : 'products'; ?> from trusted brands</span></div></li>
				<?php if ( $phone ) : ?><li><i class="fas fa-headset"></i><div><strong>Need help choosing?</strong><span>Call or WhatsApp <?php echo esc_html( $phone ); ?></span></div></li><?php endif; ?>
			</ul>
			<div class="tt-sp-meta"><?php woocommerce_template_single_meta(); ?></div>
		</div>
	</div>
	<div class="tt-sp-tabs"><?php woocommerce_output_product_data_tabs(); ?></div>
	<div class="tt-sp-related">
		<?php
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
		do_action( 'woocommerce_after_single_product_summary' );
		?>
	</div>
	<?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
	<div class="tt-sticky-buy" aria-hidden="true">
		<div class="tt-sticky-info"><span class="tt-sticky-name"><?php echo esc_html( $product->get_name() ); ?></span><span class="tt-sticky-price"><?php echo $product->get_price_html(); // phpcs:ignore ?></span></div>
		<button type="button" class="tt-sticky-btn" onclick="var b=document.querySelector('.tt-sp-cart .single_add_to_cart_button'); if(b){ b.scrollIntoView({behavior:'smooth',block:'center'}); <?php echo $product->is_type( 'simple' ) ? 'b.click();' : ''; ?> }"><?php echo esc_html( $product->is_type( 'simple' ) ? 'Add to cart' : 'Choose options' ); ?></button>
	</div>
	<?php endif; ?>
</div>
<?php do_action( 'woocommerce_after_single_product' ); ?>
