<?php
// TechenTronics product importer v2: uses pre-optimised photos in tt-setup/data/img-opt and only the image sizes the shop needs.
function tt_import_batch2( $from, $count ) {
	$data = json_decode( file_get_contents( TT_SETUP_DIR . '/products-v1.json' ), true );
	$list = array_slice( $data['products'], $from, $count );
	$opt  = TT_SETUP_DIR . '/data/img-opt';
	$keep = array( 'thumbnail', 'woocommerce_thumbnail', 'woocommerce_single', 'woocommerce_gallery_thumbnail' );
	$f1 = function ( $sizes ) use ( $keep ) { return array_intersect_key( $sizes, array_flip( $keep ) ); };
	add_filter( 'intermediate_image_sizes_advanced', $f1, 999 );
	add_filter( 'big_image_size_threshold', '__return_false', 999 );
	$cat_ids = array();
	foreach ( $list as $p ) {
		touch( TT_SETUP_DIR . '/.lock' ); // keep other page loads from starting a second runner
		$id = wc_get_product_id_by_sku( $p['sku'] );
		$prod = $id ? wc_get_product( $id ) : new WC_Product_Simple();
		$prod->set_name( $p['name'] );
		$prod->set_sku( $p['sku'] );
		$prod->set_catalog_visibility( 'visible' );
		$prod->set_short_description( $p['short'] );
		$prod->set_description( $p['desc'] );
		$prod->set_regular_price( '' ); $prod->set_sale_price( '' );
		$cids = array();
		foreach ( $p['cats'] as $slug ) {
			if ( ! isset( $cat_ids[ $slug ] ) ) { $t = get_term_by( 'slug', $slug, 'product_cat' ); $cat_ids[ $slug ] = $t ? (int) $t->term_id : 0; }
			if ( $cat_ids[ $slug ] ) { $cids[] = $cat_ids[ $slug ]; } else { echo "missing category {$slug}\n"; }
		}
		$prod->set_category_ids( $cids );
		if ( ! empty( $p['stock']['manage'] ) ) {
			$prod->set_manage_stock( true ); $prod->set_stock_quantity( (int) $p['stock']['qty'] ); $prod->set_backorders( 'no' );
		} else {
			$prod->set_manage_stock( false ); $prod->set_stock_status( $p['stock']['status'] );
			$prod->set_backorders( 'onbackorder' === $p['stock']['status'] ? 'notify' : 'no' );
		}
		$id = $prod->save();
		$att = array();
		foreach ( $p['images'] as $n => $u ) {
			$base = sanitize_title( $p['sku'] ) . '-' . ( $n + 1 );
			$file = $opt . '/' . $base . '.jpg';
			if ( ! file_exists( $file ) ) { continue; }
			$aid = tt_sideload( $file, 'tt-' . $base );
			if ( $aid ) {
				$att[] = $aid;
				update_post_meta( $aid, '_wp_attachment_image_alt', $p['name'] );
				wp_update_post( array( 'ID' => $aid, 'post_parent' => $id ) );
			}
		}
		$n_img = count( $att );
		if ( $att ) { $prod->set_image_id( array_shift( $att ) ); $prod->set_gallery_image_ids( $att ); }
		$prod->set_status( $n_img ? 'publish' : 'draft' );
		$prod->save();
		if ( taxonomy_exists( 'product_brand' ) ) { wp_set_object_terms( $id, $p['brand'], 'product_brand' ); }
		update_post_meta( $id, '_tt_import', 1 );
		update_post_meta( $id, '_tt_source', $p['source'] );
		update_post_meta( $id, '_tt_sheet_name', $p['sheet'] );
		echo "{$p['sku']} -> #{$id} ({$n_img} img)\n";
	}
	remove_filter( 'intermediate_image_sizes_advanced', $f1, 999 );
}
