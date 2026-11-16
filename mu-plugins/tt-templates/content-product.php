<?php
/**
 * TechenTronics product card (shop, category, related products).
 */
defined( 'ABSPATH' ) || exit;
global $product;
if ( empty( $product ) || ! $product->is_visible() ) { return; }
$id    = $product->get_id();
$link  = get_permalink( $id );
$cat   = tt_primary_cat( $id );
$pct   = tt_discount_pct( $product );
$stock = tt_stock_state( $product );
$gal   = $product->get_gallery_image_ids();
?>
<li <?php wc_product_class( 'tt-pc-item', $product ); ?>>
	<div class="tt-pc">
		<div class="tt-pc-media">
			<a class="tt-pc-img<?php echo $gal ? ' has-hover' : ''; ?>" href="<?php echo esc_url( $link ); ?>" aria-label="<?php echo esc_attr( $product->get_name() ); ?>">
				<?php echo woocommerce_get_product_thumbnail( 'woocommerce_thumbnail' ); // phpcs:ignore ?>
				<?php if ( $gal ) { echo wp_get_attachment_image( $gal[0], 'woocommerce_thumbnail', false, array( 'class' => 'tt-pc-img-alt', 'alt' => '' ) ); } ?>
			</a>
			<div class="tt-pc-badges">
				<?php if ( $pct ) : ?><span class="tt-badge tt-badge-sale">-<?php echo (int) $pct; ?>%</span><?php endif; ?>
				<?php if ( 'out' === $stock['key'] ) : ?><span class="tt-badge tt-badge-out">Out of stock</span><?php elseif ( 'soon' === $stock['key'] ) : ?><span class="tt-badge tt-badge-soon">Coming soon</span><?php endif; ?>
			</div>
			<?php if ( shortcode_exists( 'yith_wcwl_add_to_wishlist' ) ) : ?>
				<div class="tt-pc-wish"><?php echo do_shortcode( '[yith_wcwl_add_to_wishlist product_id="' . $id . '"]' ); ?></div>
			<?php endif; ?>
		</div>
		<div class="tt-pc-body">
			<?php if ( $cat ) : ?><a class="tt-pc-cat" href="<?php echo esc_url( get_term_link( $cat ) ); ?>"><?php echo esc_html( $cat->name ); ?></a><?php endif; ?>
			<h2 class="tt-pc-title woocommerce-loop-product__title"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h2>
			<?php if ( $product->get_rating_count() > 0 ) : ?>
				<div class="tt-pc-rating"><?php echo wc_get_rating_html( $product->get_average_rating() ); // phpcs:ignore ?><span>(<?php echo (int) $product->get_review_count(); ?>)</span></div>
			<?php endif; ?>
			<div class="tt-pc-price"><?php echo $product->get_price_html(); // phpcs:ignore ?></div>
			<div class="tt-pc-foot">
				<?php tt_card_cart_button( $product ); ?>
			</div>
		</div>
	</div>
</li>
