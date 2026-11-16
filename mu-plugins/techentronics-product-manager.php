<?php
/**
 * Plugin Name: TechenTronics Product Manager
 * Description: A simple, phone-friendly product manager for the store owner: list, search, add, edit, delete and restore products, change prices and stock in bulk, and manage product photos — without the full WooCommerce editor.
 * Version: 1.0
 * Author: Coding Time Labs
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TTPM_LIST', 'tt-products' );
define( 'TTPM_EDIT', 'tt-product-edit' );

function ttpm_url( $page, $args = array() ) { return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ); }
function ttpm_money() { return function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : 'Rs'; }

/* =========================================================
 * Menu
 * ======================================================= */
add_action( 'admin_menu', function () {
	if ( ! function_exists( 'wc_get_product' ) ) { return; }
	add_menu_page( 'Store Products', 'Store Products', 'edit_products', TTPM_LIST, 'ttpm_list_page', 'dashicons-store', 3 );
	add_submenu_page( TTPM_LIST, 'All Products', 'All Products & Prices', 'edit_products', TTPM_LIST, 'ttpm_list_page' );
	add_submenu_page( TTPM_LIST, 'Add Product', 'Add New Product', 'edit_products', TTPM_EDIT, 'ttpm_edit_page' );
} );
// Point the old "Update Prices" screen and dashboard buttons to the new manager.
add_action( 'admin_page_access_denied', function () {
	if ( isset( $_GET['page'] ) && 'tt-prices' === $_GET['page'] ) { wp_safe_redirect( ttpm_url( TTPM_LIST ) ); exit; } // phpcs:ignore
} );

// "Edit in Store Products" link in the admin bar on a product page.
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() || ! current_user_can( 'edit_products' ) ) { return; }
	$bar->add_node( array( 'id' => 'ttpm-edit', 'title' => '<span class="ab-icon dashicons dashicons-edit" style="margin-top:2px"></span> Edit this product', 'href' => ttpm_url( TTPM_EDIT, array( 'id' => get_the_ID() ) ) ) );
}, 81 );
// Product list "Edit" row action → simple editor.
add_filter( 'post_row_actions', function ( $actions, $post ) {
	if ( 'product' === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
		$actions = array( 'ttpm' => '<a href="' . esc_url( ttpm_url( TTPM_EDIT, array( 'id' => $post->ID ) ) ) . '"><strong>Simple edit</strong></a>' ) + $actions;
	}
	return $actions;
}, 10, 2 );

/* =========================================================
 * Helpers: description parts
 * ======================================================= */
function ttpm_split_short( $html ) {
	$intro = ''; $features = array();
	if ( preg_match( '#<p>(.*?)</p>#s', $html, $m ) ) { $intro = trim( wp_strip_all_tags( $m[1] ) ); }
	if ( preg_match_all( '#<li>(.*?)</li>#s', $html, $mm ) ) { foreach ( $mm[1] as $li ) { $features[] = trim( wp_strip_all_tags( html_entity_decode( $li ) ) ); } }
	if ( ! $intro && ! $features ) { $intro = trim( wp_strip_all_tags( $html ) ); }
	return array( html_entity_decode( $intro ), $features );
}
function ttpm_build_short( $intro, $features ) {
	$h = '';
	if ( $intro !== '' ) { $h .= '<p>' . esc_html( $intro ) . '</p>'; }
	$features = array_filter( array_map( 'trim', $features ) );
	if ( $features ) { $h .= "\n" . '<ul class="tt-keys">' . implode( '', array_map( function ( $f ) { return '<li>' . esc_html( $f ) . '</li>'; }, $features ) ) . '</ul>'; }
	return $h;
}
function ttpm_stock_label( $p ) {
	if ( $p->managing_stock() ) { $q = (int) $p->get_stock_quantity(); return $q > 0 ? $q . ' in stock' : 'Out of stock'; }
	$m = array( 'instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'Coming soon' );
	return $m[ $p->get_stock_status() ] ?? $p->get_stock_status();
}

/* =========================================================
 * Price freshness: remember when each price was last changed or checked
 * ======================================================= */
define( 'TTPM_STALE_DAYS', 7 );
add_action( 'woocommerce_product_object_updated_props', function ( $product, $props ) {
	if ( array_intersect( array( 'regular_price', 'sale_price' ), (array) $props ) ) { update_post_meta( $product->get_id(), '_tt_price_updated', time() ); }
}, 10, 2 );
function ttpm_price_age_html( $id, $has_price ) {
	if ( ! $has_price ) { return ''; }
	$t = (int) get_post_meta( $id, '_tt_price_updated', true );
	if ( ! $t ) { return '<small class="ttpm-age is-old">Not checked since upload</small>'; }
	$days = (int) floor( ( time() - $t ) / DAY_IN_SECONDS );
	$txt  = 0 === $days ? 'Checked today' : ( 1 === $days ? 'Checked yesterday' : 'Checked ' . $days . ' days ago' );
	return '<small class="ttpm-age' . ( $days >= TTPM_STALE_DAYS ? ' is-old' : '' ) . '">' . esc_html( $txt ) . '</small>';
}

function ttpm_query_args( $s, $cat, $view ) {
	$status = 'trash' === $view ? array( 'trash' ) : ( 'hidden' === $view ? array( 'draft', 'private', 'pending' ) : array( 'publish', 'draft', 'private', 'pending' ) );
	$q = array( 'post_type' => 'product', 'post_status' => $status, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids' );
	if ( $s ) {
		$sku_ids = wc_get_products( array( 'sku' => $s, 'limit' => 50, 'return' => 'ids', 'status' => $status ) );
		$q['s'] = $s;
		if ( $sku_ids ) { unset( $q['s'] ); $name_ids = get_posts( array( 'post_type' => 'product', 'post_status' => $status, 's' => $s, 'fields' => 'ids', 'numberposts' => 200 ) ); $q['post__in'] = array_unique( array_merge( $sku_ids, $name_ids ) ); }
	}
	if ( $cat ) { $q['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $cat ) ); } // phpcs:ignore
	$mq = array();
	if ( 'noprice' === $view ) { $mq[] = array( 'relation' => 'OR', array( 'key' => '_price', 'compare' => 'NOT EXISTS' ), array( 'key' => '_price', 'value' => '' ) ); }
	if ( 'out' === $view ) { $mq[] = array( 'key' => '_stock_status', 'value' => 'outofstock' ); }
	if ( 'soon' === $view ) { $mq[] = array( 'key' => '_stock_status', 'value' => 'onbackorder' ); }
	if ( 'sale' === $view ) { $mq[] = array( 'key' => '_sale_price', 'value' => '', 'compare' => '!=' ); }
	if ( 'stale' === $view ) {
		$mq[] = array( 'key' => '_price', 'value' => '', 'compare' => '!=' );
		$mq[] = array( 'relation' => 'OR', array( 'key' => '_tt_price_updated', 'compare' => 'NOT EXISTS' ), array( 'key' => '_tt_price_updated', 'value' => time() - TTPM_STALE_DAYS * DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC' ) );
	}
	if ( $mq ) { $q['meta_query'] = $mq; } // phpcs:ignore
	return $q;
}

/* =========================================================
 * LIST PAGE: search, filter, inline prices & stock, actions
 * ======================================================= */
function ttpm_list_page() {
	if ( ! current_user_can( 'edit_products' ) ) { return; }
	$s      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
	$cat    = isset( $_GET['cat'] ) ? sanitize_title( wp_unslash( $_GET['cat'] ) ) : ''; // phpcs:ignore
	$view   = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'all'; // phpcs:ignore
	$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore
	$all    = ! empty( $_GET['all'] ); // phpcs:ignore
	$per    = $all ? 300 : 40;
	$q = ttpm_query_args( $s, $cat, $view );
	$q['posts_per_page'] = $per; $q['paged'] = $paged;
	$query = new WP_Query( $q );
	$ids   = $query->posts;
	$total = (int) $query->found_posts;
	$pages = max( 1, (int) ceil( $total / $per ) );
	$cur   = ttpm_money();
	$counts = array(
		'all'     => array( 'All', null ),
		'stale'   => array( 'Prices to check', null ),
		'noprice' => array( 'No price yet', null ),
		'sale'    => array( 'On sale', null ),
		'out'     => array( 'Out of stock', null ),
		'soon'    => array( 'Coming soon', null ),
		'hidden'  => array( 'Hidden', null ),
		'trash'   => array( 'Deleted', null ),
	);
	$base = array( 's' => $s, 'cat' => $cat, 'all' => $all ? 1 : null );
	?>
	<div class="wrap ttpm">
		<div class="ttpm-head">
			<div>
				<h1>Store Products</h1>
				<p class="ttpm-sub">Change prices and stock right here and press <b>Save all changes</b>. Use <b>Edit</b> to change a product's name, photos or description.</p>
			</div>
			<a class="button button-primary button-hero ttpm-add" href="<?php echo esc_url( ttpm_url( TTPM_EDIT ) ); ?>"><span class="dashicons dashicons-plus-alt2"></span> Add new product</a>
		</div>
		<?php ttpm_notices(); ?>
		<ul class="ttpm-views">
			<?php foreach ( $counts as $k => $c ) : ?>
				<li><a class="<?php echo $view === $k ? 'is-on' : ''; ?>" href="<?php echo esc_url( ttpm_url( TTPM_LIST, array_merge( $base, array( 'view' => $k ) ) ) ); ?>"><?php echo esc_html( $c[0] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
		<form class="ttpm-filter" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( TTPM_LIST ); ?>"><input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $s ); ?>" placeholder="Search name or model, e.g. A240 or Archer">
			<?php wc_product_dropdown_categories( array( 'show_option_none' => 'All categories', 'option_none_value' => '', 'name' => 'cat', 'selected' => $cat, 'value_field' => 'slug', 'hide_empty' => 0 ) ); ?>
			<button class="button">Search</button>
			<?php if ( $s || $cat ) : ?><a class="button-link" href="<?php echo esc_url( ttpm_url( TTPM_LIST, array( 'view' => $view ) ) ); ?>">Clear</a><?php endif; ?>
			<span class="ttpm-count"><?php echo (int) $total; ?> product<?php echo 1 === $total ? '' : 's'; ?>
				<?php if ( $total > 40 ) : ?> &middot; <a href="<?php echo esc_url( ttpm_url( TTPM_LIST, array_merge( $base, array( 'view' => $view, 'all' => $all ? null : 1 ) ) ) ); ?>"><?php echo $all ? 'Show 40 per page' : 'Show all on one page'; ?></a><?php endif; ?></span>
		</form>

		<?php if ( 'trash' !== $view && $total ) : ?>
		<details class="ttpm-tools" <?php echo 'stale' === $view ? 'open' : ''; ?>>
			<summary><span class="dashicons dashicons-chart-line"></span> Change many prices at once <em>(applies to the <?php echo (int) $total; ?> product<?php echo 1 === $total ? '' : 's'; ?> in this list<?php echo $cat || $s ? ' — use search / category above to narrow it' : ''; ?>)</em></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ttpm-tools-form">
				<input type="hidden" name="action" value="ttpm_bulk_adjust">
				<input type="hidden" name="s" value="<?php echo esc_attr( $s ); ?>"><input type="hidden" name="cat" value="<?php echo esc_attr( $cat ); ?>"><input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
				<input type="hidden" name="back" value="<?php echo esc_attr( remove_query_arg( array( 'ttpm_msg', 'ttpm_n', 'ttpm_err' ) ) ); ?>">
				<?php wp_nonce_field( 'ttpm_adjust', 'ttpm_nonce' ); ?>
				<div class="ttpm-tools-row">
					<select name="dir"><option value="up">Increase</option><option value="down">Decrease</option></select>
					<span>prices by</span>
					<input type="number" name="pct" min="0.1" max="90" step="0.1" value="5" inputmode="decimal"> <span>%</span>
					<select name="round"><option value="1">exact (nearest Rs 1)</option><option value="10">round to nearest Rs 10</option><option value="50">round to nearest Rs 50</option><option value="100">round to nearest Rs 100</option></select>
					<button class="button button-primary" name="do" value="adjust" onclick="return confirm('Change the prices of all <?php echo (int) $total; ?> products in this list?');">Apply to <?php echo (int) $total; ?> product<?php echo 1 === $total ? '' : 's'; ?></button>
				</div>
				<div class="ttpm-tools-row">
					<span>Prices still correct this week?</span>
					<button class="button" name="do" value="checked" onclick="return confirm('Mark the prices of these <?php echo (int) $total; ?> products as checked today?');">Mark these prices as checked today</button>
				</div>
				<p class="ttpm-hint">Products without a price are skipped. Sale prices change by the same %. Every price shows when it was last changed or checked; the <b>Prices to check</b> tab lists prices older than <?php echo (int) TTPM_STALE_DAYS; ?> days.</p>
			</form>
		</details>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ttpm-bulk">
			<input type="hidden" name="action" value="ttpm_bulk_save">
			<input type="hidden" name="back" value="<?php echo esc_attr( remove_query_arg( array( 'ttpm_msg', 'ttpm_n' ) ) ); ?>">
			<?php wp_nonce_field( 'ttpm_bulk', 'ttpm_nonce' ); ?>
			<table class="widefat ttpm-table">
				<thead><tr><th class="c-img"></th><th>Product</th><th class="c-num">Price (<?php echo esc_html( $cur ); ?>)</th><th class="c-num">Sale price</th><th class="c-stock">Stock</th><th class="c-act"></th></tr></thead>
				<tbody>
				<?php foreach ( $ids as $id ) :
					$p = wc_get_product( $id ); if ( ! $p ) { continue; }
					$cats = wp_list_pluck( (array) get_the_terms( $id, 'product_cat' ), 'name' );
					$hidden = 'publish' !== get_post_status( $id );
					$trash  = 'trash' === get_post_status( $id );
					?>
					<tr data-id="<?php echo (int) $id; ?>" class="<?php echo $hidden && ! $trash ? 'is-hidden' : ''; ?>">
						<td class="c-img"><a href="<?php echo esc_url( ttpm_url( TTPM_EDIT, array( 'id' => $id ) ) ); ?>"><?php echo $p->get_image( 'thumbnail', array( 'class' => 'ttpm-thumb' ) ); // phpcs:ignore ?></a></td>
						<td class="c-name">
							<a class="ttpm-name" href="<?php echo esc_url( ttpm_url( TTPM_EDIT, array( 'id' => $id ) ) ); ?>"><?php echo esc_html( $p->get_name() ); ?></a>
							<div class="ttpm-meta">
								<?php if ( $p->get_sku() ) : ?><span>Model: <b><?php echo esc_html( $p->get_sku() ); ?></b></span><?php endif; ?>
								<?php if ( $cats ) : ?><span><?php echo esc_html( implode( ', ', array_filter( $cats ) ) ); ?></span><?php endif; ?>
								<?php if ( $hidden && ! $trash ) : ?><span class="ttpm-badge">Hidden from website</span><?php endif; ?>
								<?php if ( $p->is_featured() ) : ?><span class="ttpm-badge ttpm-badge-star">On homepage</span><?php endif; ?>
							</div>
							<input type="hidden" class="ttpm-flag" name="p[<?php echo (int) $id; ?>][changed]" value="">
						</td>
						<?php if ( $trash ) : ?>
							<td colspan="3" class="ttpm-muted">Deleted product</td>
							<td class="c-act">
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ttpm_restore&id=' . $id ), 'ttpm_restore_' . $id ) ); ?>">Restore</a>
								<?php if ( current_user_can( 'delete_post', $id ) ) : ?><a class="ttpm-del" onclick="return confirm('Delete this product forever? This cannot be undone.');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ttpm_purge&id=' . $id ), 'ttpm_purge_' . $id ) ); ?>">Delete forever</a><?php endif; ?>
							</td>
						<?php elseif ( $p->is_type( 'simple' ) ) : ?>
							<td class="c-num"><input type="number" min="0" step="1" inputmode="numeric" name="p[<?php echo (int) $id; ?>][regular]" value="<?php echo esc_attr( $p->get_regular_price() ); ?>" placeholder="—"><?php echo ttpm_price_age_html( $id, '' !== $p->get_regular_price() ); // phpcs:ignore ?></td>
							<td class="c-num"><input type="number" min="0" step="1" inputmode="numeric" name="p[<?php echo (int) $id; ?>][sale]" value="<?php echo esc_attr( $p->get_sale_price() ); ?>" placeholder="—"></td>
							<td class="c-stock">
								<?php if ( $p->managing_stock() ) : ?>
									<input type="number" step="1" inputmode="numeric" name="p[<?php echo (int) $id; ?>][qty]" value="<?php echo esc_attr( $p->get_stock_quantity() ); ?>"> <small>pcs</small>
								<?php else : ?>
									<select name="p[<?php echo (int) $id; ?>][status]">
										<?php foreach ( array( 'instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'Coming soon' ) as $k => $l ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $p->get_stock_status(), $k ); ?>><?php echo esc_html( $l ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
							</td>
							<td class="c-act">
								<a class="button" href="<?php echo esc_url( ttpm_url( TTPM_EDIT, array( 'id' => $id ) ) ); ?>">Edit</a>
								<a class="ttpm-link" href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank">View</a>
							</td>
						<?php else : ?>
							<td colspan="3" class="ttpm-muted">This product has options (e.g. lengths). Use <a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">the full editor</a> for its prices.</td>
							<td class="c-act"><a class="button" href="<?php echo esc_url( ttpm_url( TTPM_EDIT, array( 'id' => $id ) ) ); ?>">Edit</a></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $ids ) : ?><tr><td colspan="6" class="ttpm-empty">No products found. <a href="<?php echo esc_url( ttpm_url( TTPM_EDIT ) ); ?>">Add a new product</a>.</td></tr><?php endif; ?>
				</tbody>
			</table>
			<div class="ttpm-savebar" id="ttpm-savebar">
				<span><b id="ttpm-changed-n">0</b> product(s) changed</span>
				<button type="submit" class="button button-primary button-large">Save all changes</button>
			</div>
		</form>
		<?php if ( $pages > 1 ) : ?>
			<div class="ttpm-pages">
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<a class="<?php echo $i === $paged ? 'is-on' : ''; ?>" href="<?php echo esc_url( ttpm_url( TTPM_LIST, array_merge( $base, array( 'view' => $view, 'paged' => $i ) ) ) ); ?>"><?php echo (int) $i; ?></a>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	</div>
	<script>
	(function(){
		var form=document.getElementById('ttpm-bulk'), bar=document.getElementById('ttpm-savebar'), n=document.getElementById('ttpm-changed-n');
		function count(){ var c=form.querySelectorAll('tr.is-changed').length; n.textContent=c; bar.classList.toggle('is-on', c>0); }
		form.querySelectorAll('tbody tr').forEach(function(tr){
			tr.querySelectorAll('input:not(.ttpm-flag),select').forEach(function(el){
				el.addEventListener('input', function(){ tr.classList.add('is-changed'); var f=tr.querySelector('.ttpm-flag'); if(f){f.value='1';} count(); });
				el.addEventListener('change', function(){ tr.classList.add('is-changed'); var f=tr.querySelector('.ttpm-flag'); if(f){f.value='1';} count(); });
			});
		});
		form.addEventListener('submit', function(){ form.querySelectorAll('tbody tr:not(.is-changed) input, tbody tr:not(.is-changed) select').forEach(function(el){ el.disabled=true; }); });
		window.addEventListener('beforeunload', function(e){ if(form.querySelectorAll('tr.is-changed').length && !form.dataset.submitting){ e.preventDefault(); e.returnValue=''; } });
		form.addEventListener('submit', function(){ form.dataset.submitting='1'; });
	})();
	</script>
	<?php
}

function ttpm_notices() {
	$m = isset( $_GET['ttpm_msg'] ) ? sanitize_key( $_GET['ttpm_msg'] ) : ''; // phpcs:ignore
	$n = isset( $_GET['ttpm_n'] ) ? absint( $_GET['ttpm_n'] ) : 0; // phpcs:ignore
	$e = isset( $_GET['ttpm_err'] ) ? sanitize_text_field( wp_unslash( $_GET['ttpm_err'] ) ) : ''; // phpcs:ignore
	$map = array(
		'bulk'     => $n . ' product(s) updated.',
		'checked'  => $n . ' price(s) marked as checked today.',
		'saved'    => 'Product saved.',
		'created'  => 'New product added.',
		'trashed'  => 'Product deleted. You can restore it from the “Deleted” tab.',
		'restored' => 'Product restored (it is hidden until you show it on the website).',
		'purged'   => 'Product deleted forever.',
	);
	if ( $m && isset( $map[ $m ] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $m ] ) . '</p></div>'; }
	if ( $e ) { echo '<div class="notice notice-error"><p>' . esc_html( $e ) . '</p></div>'; }
}

/* Bulk save prices / stock */
add_action( 'admin_post_ttpm_bulk_save', function () {
	if ( ! current_user_can( 'edit_products' ) || ! check_admin_referer( 'ttpm_bulk', 'ttpm_nonce' ) ) { wp_die( 'Not allowed' ); }
	$rows = isset( $_POST['p'] ) && is_array( $_POST['p'] ) ? wp_unslash( $_POST['p'] ) : array(); // phpcs:ignore
	$done = 0;
	foreach ( $rows as $id => $r ) {
		if ( empty( $r['changed'] ) ) { continue; }
		$p = wc_get_product( absint( $id ) );
		if ( ! $p || ! current_user_can( 'edit_post', $p->get_id() ) ) { continue; }
		ttpm_apply_price_stock( $p, $r );
		$p->save(); $done++;
	}
	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : ttpm_url( TTPM_LIST ); // phpcs:ignore
	wp_safe_redirect( add_query_arg( array( 'ttpm_msg' => 'bulk', 'ttpm_n' => $done ), $back ) ); exit;
} );
/* Bulk % change / mark checked for every product matching the current list */
add_action( 'admin_post_ttpm_bulk_adjust', function () {
	if ( ! current_user_can( 'edit_products' ) || ! check_admin_referer( 'ttpm_adjust', 'ttpm_nonce' ) ) { wp_die( 'Not allowed' ); }
	$in   = wp_unslash( $_POST ); // phpcs:ignore
	$back = ! empty( $in['back'] ) ? esc_url_raw( $in['back'] ) : ttpm_url( TTPM_LIST );
	$q    = ttpm_query_args( sanitize_text_field( $in['s'] ?? '' ), sanitize_title( $in['cat'] ?? '' ), sanitize_key( $in['view'] ?? 'all' ) );
	$q['posts_per_page'] = -1; $q['no_found_rows'] = true;
	$ids  = get_posts( $q );
	$done = 0;
	if ( 'checked' === ( $in['do'] ?? '' ) ) {
		foreach ( $ids as $id ) { $p = wc_get_product( $id ); if ( $p && '' !== $p->get_price() && current_user_can( 'edit_post', $id ) ) { update_post_meta( $id, '_tt_price_updated', time() ); $done++; } }
		wp_safe_redirect( add_query_arg( array( 'ttpm_msg' => 'checked', 'ttpm_n' => $done ), $back ) ); exit;
	}
	$pct = min( 90, max( 0, (float) ( $in['pct'] ?? 0 ) ) );
	if ( ! $pct ) { wp_safe_redirect( add_query_arg( 'ttpm_err', rawurlencode( 'Please enter a percentage.' ), $back ) ); exit; }
	$f     = 'down' === ( $in['dir'] ?? 'up' ) ? 1 - $pct / 100 : 1 + $pct / 100;
	$round = in_array( (int) ( $in['round'] ?? 1 ), array( 1, 10, 50, 100 ), true ) ? (int) $in['round'] : 1;
	$calc  = function ( $v ) use ( $f, $round ) { return (string) max( $round, round( ( (float) $v * $f ) / $round ) * $round ); };
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->is_type( 'simple' ) || '' === $p->get_regular_price() || ! current_user_can( 'edit_post', $id ) ) { continue; }
		$reg  = $calc( $p->get_regular_price() );
		$sale = '' !== $p->get_sale_price() ? $calc( $p->get_sale_price() ) : '';
		if ( '' !== $sale && (float) $sale >= (float) $reg ) { $sale = ''; }
		$p->set_regular_price( $reg ); $p->set_sale_price( $sale ); $p->save();
		update_post_meta( $id, '_tt_price_updated', time() );
		$done++;
	}
	wp_safe_redirect( add_query_arg( array( 'ttpm_msg' => 'bulk', 'ttpm_n' => $done ), $back ) ); exit;
} );

function ttpm_apply_price_stock( $p, $r ) {
	if ( array_key_exists( 'regular', $r ) ) {
		$reg  = wc_format_decimal( trim( (string) $r['regular'] ) );
		$sale = isset( $r['sale'] ) ? wc_format_decimal( trim( (string) $r['sale'] ) ) : '';
		if ( '' === $reg || ( '' !== $sale && (float) $sale >= (float) $reg ) ) { $sale = ''; }
		$p->set_regular_price( $reg );
		$p->set_sale_price( $sale );
	}
	if ( isset( $r['stock_mode'] ) ) {
		if ( 'qty' === $r['stock_mode'] ) { $p->set_manage_stock( true ); $p->set_backorders( 'no' ); }
		else { $p->set_manage_stock( false ); }
	}
	if ( $p->managing_stock() && isset( $r['qty'] ) && '' !== $r['qty'] ) { $p->set_stock_quantity( (int) $r['qty'] ); }
	if ( ! $p->managing_stock() && isset( $r['status'] ) && in_array( $r['status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
		$p->set_stock_status( $r['status'] );
		$p->set_backorders( 'onbackorder' === $r['status'] ? 'notify' : 'no' );
	}
}

/* Delete (trash), restore, delete forever */
add_action( 'admin_post_ttpm_trash', function () {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
	if ( ! $id || ! current_user_can( 'delete_post', $id ) || ! check_admin_referer( 'ttpm_trash_' . $id ) ) { wp_die( 'Not allowed' ); }
	wp_trash_post( $id );
	wp_safe_redirect( ttpm_url( TTPM_LIST, array( 'ttpm_msg' => 'trashed' ) ) ); exit;
} );
add_action( 'admin_post_ttpm_restore', function () {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
	if ( ! $id || ! current_user_can( 'delete_post', $id ) || ! check_admin_referer( 'ttpm_restore_' . $id ) ) { wp_die( 'Not allowed' ); }
	wp_untrash_post( $id );
	wp_safe_redirect( ttpm_url( TTPM_EDIT, array( 'id' => $id, 'ttpm_msg' => 'restored' ) ) ); exit;
} );
add_action( 'admin_post_ttpm_purge', function () {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
	if ( ! $id || ! current_user_can( 'delete_post', $id ) || ! check_admin_referer( 'ttpm_purge_' . $id ) ) { wp_die( 'Not allowed' ); }
	$p = wc_get_product( $id ); if ( $p ) { $p->delete( true ); }
	wp_safe_redirect( ttpm_url( TTPM_LIST, array( 'view' => 'trash', 'ttpm_msg' => 'purged' ) ) ); exit;
} );

/* =========================================================
 * ADD / EDIT PAGE
 * ======================================================= */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( (string) $hook, TTPM_EDIT ) && false === strpos( (string) $hook, TTPM_LIST ) ) { return; }
	if ( false !== strpos( (string) $hook, TTPM_EDIT ) ) { wp_enqueue_media(); wp_enqueue_script( 'jquery-ui-sortable' ); }
} );

function ttpm_cat_checklist( $selected, $parent = 0, $depth = 0 ) {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false, 'orderby' => 'name' ) );
	if ( ! $terms || is_wp_error( $terms ) ) { return; }
	foreach ( $terms as $t ) {
		if ( 'uncategorized' === $t->slug ) { continue; }
		printf( '<label class="ttpm-cat d%d"><input type="checkbox" name="cats[]" value="%d" %s> %s</label>', (int) $depth, (int) $t->term_id, checked( in_array( $t->term_id, $selected, true ), true, false ), esc_html( $t->name ) );
		ttpm_cat_checklist( $selected, $t->term_id, $depth + 1 );
	}
}

function ttpm_edit_page() {
	if ( ! current_user_can( 'edit_products' ) ) { return; }
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
	$p  = $id ? wc_get_product( $id ) : null;
	if ( $id && ! $p ) { echo '<div class="wrap"><p>Product not found.</p></div>'; return; }
	$is_new   = ! $p;
	$name     = $p ? $p->get_name() : '';
	$sku      = $p ? $p->get_sku() : '';
	list( $intro, $features ) = $p ? ttpm_split_short( $p->get_short_description() ) : array( '', array() );
	$desc     = $p ? $p->get_description() : '';
	$cats     = $p ? array_map( 'intval', $p->get_category_ids() ) : array();
	$img      = $p ? (int) $p->get_image_id() : 0;
	$gallery  = $p ? array_map( 'intval', $p->get_gallery_image_ids() ) : array();
	$status   = $p ? get_post_status( $id ) : 'publish';
	$brand    = '';
	if ( $p && taxonomy_exists( 'product_brand' ) ) { $bt = get_the_terms( $id, 'product_brand' ); if ( $bt && ! is_wp_error( $bt ) ) { $brand = $bt[0]->name; } }
	$brands   = taxonomy_exists( 'product_brand' ) ? get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false ) ) : array();
	$seo      = $p ? get_post_meta( $id, '_yoast_wpseo_metadesc', true ) : '';
	$cur      = ttpm_money();
	$simple   = ! $p || $p->is_type( 'simple' );
	?>
	<div class="wrap ttpm ttpm-edit">
		<div class="ttpm-head">
			<div>
				<a class="ttpm-back" href="<?php echo esc_url( ttpm_url( TTPM_LIST ) ); ?>">&larr; All products</a>
				<h1><?php echo $is_new ? 'Add new product' : esc_html( $name ); ?></h1>
				<?php if ( ! $is_new ) : ?><p class="ttpm-sub"><a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank">View on website &nearr;</a> &nbsp;·&nbsp; <a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">Open full WooCommerce editor</a></p><?php endif; ?>
			</div>
		</div>
		<?php ttpm_notices(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ttpm-form">
			<input type="hidden" name="action" value="ttpm_save">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'ttpm_save_' . $id, 'ttpm_nonce' ); ?>
			<div class="ttpm-cols">
				<div class="ttpm-main">
					<section class="ttpm-card">
						<h2>Product details</h2>
						<label class="ttpm-field"><span>Product name <i>*</i></span><input type="text" name="name" value="<?php echo esc_attr( $name ); ?>" required placeholder="e.g. Amaze 20000mAh Power Bank A233"></label>
						<div class="ttpm-row2">
							<label class="ttpm-field"><span>Model / SKU</span><input type="text" name="sku" value="<?php echo esc_attr( $sku ); ?>" placeholder="e.g. A233"><small>Must be unique. Customers see it on the product page.</small></label>
							<label class="ttpm-field"><span>Brand</span>
								<input type="text" name="brand" list="ttpm-brands" value="<?php echo esc_attr( $brand ); ?>" placeholder="e.g. Amaze or TP-Link">
								<datalist id="ttpm-brands"><?php foreach ( (array) $brands as $b ) { echo '<option value="' . esc_attr( $b->name ) . '">'; } ?></datalist>
							</label>
						</div>
						<?php $tt_cols = $p ? (array) get_post_meta( $id, '_tt_colours', true ) : array(); ?>
						<label class="ttpm-field"><span>Colours <em>(optional, separate with commas)</em></span><input type="text" name="colours" value="<?php echo esc_attr( implode( ', ', array_filter( $tt_cols ) ) ); ?>" placeholder="e.g. Black, White, Grey"><small>Customers pick a colour on the product page; it shows on the order and in WhatsApp.</small></label>
					</section>

					<section class="ttpm-card">
						<h2>Photos</h2>
						<p class="ttpm-hint">The first photo is the main one shown in the shop. Drag photos to change the order. Square photos on a white background look best (at least 800 × 800 px).</p>
						<ul class="ttpm-photos" id="ttpm-photos">
							<?php foreach ( array_filter( array_merge( array( $img ), $gallery ) ) as $aid ) : ?>
								<li data-id="<?php echo (int) $aid; ?>"><?php echo wp_get_attachment_image( $aid, 'thumbnail' ); ?><button type="button" class="ttpm-x" title="Remove photo">&times;</button></li>
							<?php endforeach; ?>
						</ul>
						<input type="hidden" name="photos" id="ttpm-photo-ids" value="<?php echo esc_attr( implode( ',', array_filter( array_merge( array( $img ), $gallery ) ) ) ); ?>">
						<button type="button" class="button button-secondary" id="ttpm-add-photos"><span class="dashicons dashicons-camera" style="margin-top:4px"></span> Add or upload photos</button>
					</section>

					<section class="ttpm-card">
						<h2>Description</h2>
						<label class="ttpm-field"><span>Short introduction</span><textarea name="intro" rows="3" placeholder="One or two sentences about what this product is and who it is for."><?php echo esc_textarea( $intro ); ?></textarea><small>Shown next to the price.</small></label>
						<label class="ttpm-field"><span>Key features <em>(one per line)</em></span><textarea name="features" rows="6" placeholder="e.g.&#10;20000mAh capacity&#10;22.5W fast charging&#10;USB-A + USB-C outputs"><?php echo esc_textarea( implode( "\n", $features ) ); ?></textarea><small>Shown as a ticked list next to the price.</small></label>
						<div class="ttpm-field"><span>Full details, specifications, what's in the box</span>
							<?php wp_editor( $desc, 'ttpm_desc', array( 'textarea_name' => 'desc', 'textarea_rows' => 12, 'media_buttons' => false, 'teeny' => false ) ); ?>
						</div>
					</section>
				</div>

				<aside class="ttpm-side">
					<section class="ttpm-card ttpm-sticky">
						<h2>Publish</h2>
						<label class="ttpm-switch"><input type="checkbox" name="visible" value="1" <?php checked( 'publish' === $status || $is_new ); ?>> <span>Show on website</span></label>
						<label class="ttpm-switch"><input type="checkbox" name="featured" value="1" <?php checked( $p && $p->is_featured() ); ?>> <span>Show on homepage (featured)</span></label>
						<button type="submit" class="button button-primary button-hero ttpm-save"><?php echo $is_new ? 'Add product' : 'Save changes'; ?></button>
						<?php if ( ! $is_new && current_user_can( 'delete_post', $id ) ) : ?>
							<a class="ttpm-del" onclick="return confirm('Delete this product? You can restore it later from the Deleted tab.');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ttpm_trash&id=' . $id ), 'ttpm_trash_' . $id ) ); ?>">Delete product</a>
						<?php endif; ?>
					</section>

					<section class="ttpm-card">
						<h2>Price</h2>
						<?php if ( $simple ) : ?>
							<label class="ttpm-field"><span>Price (<?php echo esc_html( $cur ); ?>)</span><input type="number" min="0" step="1" inputmode="numeric" name="regular" value="<?php echo esc_attr( $p ? $p->get_regular_price() : '' ); ?>" placeholder="Leave empty = “Price on WhatsApp”"></label>
							<label class="ttpm-field"><span>Sale price (<?php echo esc_html( $cur ); ?>) <em>optional</em></span><input type="number" min="0" step="1" inputmode="numeric" name="sale" value="<?php echo esc_attr( $p ? $p->get_sale_price() : '' ); ?>" placeholder="Only for a discount"></label>
							<?php if ( $p ) { echo ttpm_price_age_html( $id, '' !== $p->get_regular_price() ); } // phpcs:ignore ?>
							<p class="ttpm-hint">Without a price, customers see “Price on WhatsApp” and an Ask-price button. With a price they can order directly.</p>
						<?php else : ?>
							<p class="ttpm-hint">This product has options. Set its prices in the <a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">full editor</a>.</p>
						<?php endif; ?>
					</section>

					<section class="ttpm-card">
						<h2>Stock</h2>
						<?php $mode = ( $p && $p->managing_stock() ) ? 'qty' : 'status'; ?>
						<label class="ttpm-radio"><input type="radio" name="stock_mode" value="status" <?php checked( 'status', $mode ); ?>> Simple (In stock / Out / Coming soon)</label>
						<label class="ttpm-radio"><input type="radio" name="stock_mode" value="qty" <?php checked( 'qty', $mode ); ?>> Count pieces</label>
						<div class="ttpm-stock-status" <?php echo 'qty' === $mode ? 'hidden' : ''; ?>>
							<select name="status">
								<?php foreach ( array( 'instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'Coming soon' ) as $k => $l ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $p ? $p->get_stock_status() : 'instock', $k ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="ttpm-stock-qty" <?php echo 'qty' === $mode ? '' : 'hidden'; ?>>
							<label class="ttpm-field"><span>Pieces in stock</span><input type="number" step="1" inputmode="numeric" name="qty" value="<?php echo esc_attr( $p && $p->managing_stock() ? $p->get_stock_quantity() : '' ); ?>"></label>
							<small>Goes down automatically when an order is confirmed.</small>
						</div>
					</section>

					<section class="ttpm-card">
						<h2>Categories</h2>
						<div class="ttpm-cats"><?php ttpm_cat_checklist( $cats ); ?></div>
					</section>

					<section class="ttpm-card">
						<h2>Google description <em>optional</em></h2>
						<textarea name="seo" rows="3" maxlength="160" id="ttpm-seo" placeholder="Short summary shown in Google results (max 155 characters)."><?php echo esc_textarea( $seo ); ?></textarea>
						<small><span id="ttpm-seo-n"><?php echo (int) strlen( $seo ); ?></span>/155</small>
					</section>
				</aside>
			</div>
		</form>
	</div>
	<script>
	jQuery(function($){
		var $list=$('#ttpm-photos'), $ids=$('#ttpm-photo-ids'), frame;
		function sync(){ $ids.val($list.children('li').map(function(){ return $(this).data('id'); }).get().join(',')); $list.children('li').removeClass('is-main').first().addClass('is-main'); }
		$list.sortable({ update: sync, tolerance: 'pointer' }); sync();
		$list.on('click','.ttpm-x',function(){ $(this).closest('li').remove(); sync(); });
		$('#ttpm-add-photos').on('click', function(e){
			e.preventDefault();
			if(frame){ frame.open(); return; }
			frame = wp.media({ title: 'Choose or upload product photos', button: { text: 'Add to product' }, library: { type: 'image' }, multiple: 'add' });
			frame.on('select', function(){
				frame.state().get('selection').each(function(att){
					var a=att.toJSON(); if($list.find('li[data-id="'+a.id+'"]').length){ return; }
					var src=(a.sizes&&a.sizes.thumbnail)?a.sizes.thumbnail.url:a.url;
					$list.append('<li data-id="'+a.id+'"><img src="'+src+'" alt=""><button type="button" class="ttpm-x" title="Remove photo">&times;</button></li>');
				});
				sync();
			});
			frame.open();
		});
		$('input[name=stock_mode]').on('change', function(){ var q=$('input[name=stock_mode]:checked').val()==='qty'; $('.ttpm-stock-qty').prop('hidden',!q); $('.ttpm-stock-status').prop('hidden',q); });
		$('#ttpm-seo').on('input', function(){ $('#ttpm-seo-n').text(this.value.length); });
		var dirty=false; $('#ttpm-form').on('change input', function(){ dirty=true; }).on('submit', function(){ dirty=false; });
		$(window).on('beforeunload', function(){ if(dirty){ return 'You have unsaved changes.'; } });
	});
	</script>
	<?php
}

/* Save (add or edit) */
add_action( 'admin_post_ttpm_save', function () {
	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
	if ( ! current_user_can( 'edit_products' ) || ! check_admin_referer( 'ttpm_save_' . $id, 'ttpm_nonce' ) ) { wp_die( 'Not allowed' ); }
	if ( $id && ! current_user_can( 'edit_post', $id ) ) { wp_die( 'Not allowed' ); }
	$in = wp_unslash( $_POST ); // phpcs:ignore
	$p  = $id ? wc_get_product( $id ) : new WC_Product_Simple();
	if ( ! $p ) { wp_die( 'Product not found' ); }
	$back = $id ? ttpm_url( TTPM_EDIT, array( 'id' => $id ) ) : ttpm_url( TTPM_EDIT );
	$name = sanitize_text_field( $in['name'] ?? '' );
	if ( '' === $name ) { wp_safe_redirect( add_query_arg( 'ttpm_err', 'Please enter a product name.', $back ) ); exit; }
	try {
		$p->set_name( $name );
		$sku = sanitize_text_field( $in['sku'] ?? '' );
		$sku_warn = false;
		if ( $sku !== $p->get_sku() ) {
			if ( '' === $sku || wc_product_has_unique_sku( $p->get_id(), $sku ) ) { $p->set_sku( $sku ); }
			else { $sku_warn = true; } // keep the old model number, save everything else
		}
		$p->set_short_description( ttpm_build_short( sanitize_textarea_field( $in['intro'] ?? '' ), preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( $in['features'] ?? '' ) ) ) );
		$p->set_description( wp_kses_post( $in['desc'] ?? '' ) );
		$p->set_category_ids( array_map( 'absint', (array) ( $in['cats'] ?? array() ) ) );
		$photos = array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $in['photos'] ?? '' ) ) ) ) );
		$p->set_image_id( $photos ? array_shift( $photos ) : 0 );
		$p->set_gallery_image_ids( $photos );
		$p->set_featured( ! empty( $in['featured'] ) );
		$p->set_catalog_visibility( 'visible' );
		$want_public = ! empty( $in['visible'] );
		$p->set_status( $want_public && current_user_can( 'publish_products' ) ? 'publish' : 'draft' );
		if ( $p->is_type( 'simple' ) ) {
			ttpm_apply_price_stock( $p, array( 'regular' => $in['regular'] ?? '', 'sale' => $in['sale'] ?? '', 'stock_mode' => ( 'qty' === ( $in['stock_mode'] ?? '' ) ? 'qty' : 'status' ), 'qty' => $in['qty'] ?? '', 'status' => $in['status'] ?? 'instock' ) );
		}
		$new_id = $p->save();
	} catch ( Exception $e ) {
		$msg = false !== stripos( $e->getMessage(), 'sku' ) ? 'That Model / SKU is already used by another product. Please use a different one.' : $e->getMessage();
		wp_safe_redirect( add_query_arg( 'ttpm_err', rawurlencode( $msg ), $back ) ); exit;
	}
	// Brand
	if ( taxonomy_exists( 'product_brand' ) ) {
		$brand = sanitize_text_field( $in['brand'] ?? '' );
		wp_set_object_terms( $new_id, $brand ? array( $brand ) : array(), 'product_brand' );
	}
	// Photos belong to the product (keeps media library tidy) + alt text
	foreach ( array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) ) as $aid ) {
		if ( ! get_post_meta( $aid, '_wp_attachment_image_alt', true ) ) { update_post_meta( $aid, '_wp_attachment_image_alt', $name ); }
		if ( ! wp_get_post_parent_id( $aid ) ) { wp_update_post( array( 'ID' => $aid, 'post_parent' => $new_id ) ); }
	}
	// Colours
	$cols = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $in['colours'] ?? '' ) ) ) ) ) );
	if ( $cols ) { update_post_meta( $new_id, '_tt_colours', $cols ); $ci = (array) get_post_meta( $new_id, '_tt_colour_imgs', true ); update_post_meta( $new_id, '_tt_colour_imgs', array_intersect_key( $ci, array_flip( $cols ) ) ); }
	else { delete_post_meta( $new_id, '_tt_colours' ); }
	// Google description (Yoast)
	$seo = sanitize_text_field( $in['seo'] ?? '' );
	if ( '' !== $seo ) { update_post_meta( $new_id, '_yoast_wpseo_metadesc', $seo ); } else { delete_post_meta( $new_id, '_yoast_wpseo_metadesc' ); }
	if ( '' !== ( $in['regular'] ?? '' ) ) { update_post_meta( $new_id, '_tt_price_updated', time() ); }
	wc_delete_product_transients( $new_id );
	$args = array( 'id' => $new_id, 'ttpm_msg' => $id ? 'saved' : 'created' );
	if ( $sku_warn ) { $args['ttpm_err'] = rawurlencode( 'Model / SKU "' . $sku . '" is already used by another product, so the old one was kept. Everything else was saved.' ); }
	wp_safe_redirect( ttpm_url( TTPM_EDIT, $args ) ); exit;
} );

// Refresh the homepage product tabs whenever a product is added, hidden or deleted.
function ttpm_flush_home_picks( $post_id = 0 ) {
	if ( $post_id && 'product' !== get_post_type( $post_id ) ) { return; }
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tt\\_hp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tt\\_hp\\_%'" ); // phpcs:ignore
}
add_action( 'transition_post_status', function ( $new, $old, $post ) { if ( 'product' === $post->post_type && $new !== $old ) { ttpm_flush_home_picks(); } }, 10, 3 );
add_action( 'before_delete_post', 'ttpm_flush_home_picks' );
add_action( 'woocommerce_update_product', function () { ttpm_flush_home_picks(); delete_transient( 'ttom_stats' ); } );
add_action( 'woocommerce_new_product', function () { ttpm_flush_home_picks(); delete_transient( 'ttom_stats' ); } );

// Correct browser-tab title when editing.
add_filter( 'admin_title', function ( $t ) {
	if ( isset( $_GET['page'], $_GET['id'] ) && TTPM_EDIT === $_GET['page'] && absint( $_GET['id'] ) ) { // phpcs:ignore
		return 'Edit: ' . wp_strip_all_tags( get_the_title( absint( $_GET['id'] ) ) ) . ' ‹ ' . get_bloginfo( 'name' ); // phpcs:ignore
	}
	return $t;
} );

/* =========================================================
 * Styles
 * ======================================================= */
add_action( 'admin_head', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ( false === strpos( $screen->id, TTPM_LIST ) && false === strpos( $screen->id, TTPM_EDIT ) ) ) { return; }
	?>
	<style>
	.ttpm { max-width: 1280px; }
	.ttpm-head { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin:10px 0 14px; }
	.ttpm-head h1 { font-size:26px; font-weight:700; margin:4px 0 4px; padding:0; }
	.ttpm-sub { margin:0; color:#5b6b80; }
	.ttpm-back { text-decoration:none; font-weight:600; }
	.ttpm-add.button-hero { display:inline-flex; align-items:center; gap:6px; background:#0AA2F5; border-color:#0A8FDB; }
	.ttpm-add .dashicons { margin-top:2px; }
	.ttpm-views { display:flex; gap:6px; flex-wrap:wrap; margin:0 0 10px; }
	.ttpm-views a { display:inline-block; padding:6px 12px; border-radius:20px; background:#fff; border:1px solid #dbe3ee; text-decoration:none; color:#0b1a33; font-weight:600; font-size:13px; }
	.ttpm-views a.is-on { background:#07122b; color:#fff; border-color:#07122b; }
	.ttpm-filter { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
	.ttpm-filter input[type=search] { min-width:280px; padding:4px 10px; }
	.ttpm-count { margin-left:auto; color:#5b6b80; font-weight:600; }
	.ttpm-table { border-radius:10px; overflow:hidden; border-color:#dbe3ee; }
	.ttpm-table thead th { background:#07122b; color:#fff; font-weight:600; padding:10px; }
	.ttpm-table td { vertical-align:middle; padding:10px; border-top:1px solid #eef2f7; }
	.ttpm-table tr.is-changed td { background:#fff8e1; }
	.ttpm-table tr.is-hidden td { opacity:.75; }
	.ttpm-table .c-img { width:56px; }
	.ttpm-thumb, .ttpm-table .c-img img { width:52px !important; height:52px !important; object-fit:contain; border-radius:6px; border:1px solid #eef2f7; background:#fff; }
	.ttpm-name { font-weight:700; font-size:14px; text-decoration:none; color:#0b1a33; }
	.ttpm-name:hover { color:#0AA2F5; }
	.ttpm-meta { display:flex; gap:10px; flex-wrap:wrap; margin-top:3px; color:#5b6b80; font-size:12px; }
	.ttpm-badge { background:#fff4d6; color:#7a5600; border-radius:10px; padding:0 8px; font-weight:600; }
	.ttpm-badge-star { background:#e6f4ff; color:#0a5aa8; }
	.ttpm-table .c-num { width:120px; } .ttpm-table .c-num input { width:110px; }
	.ttpm-table .c-stock { width:170px; } .ttpm-table .c-stock input { width:80px; }
	.ttpm-table .c-act { width:130px; white-space:nowrap; }
	.ttpm-link { margin-left:8px; }
	.ttpm-muted { color:#5b6b80; font-style:italic; }
	.ttpm-empty { text-align:center; padding:30px !important; }
	.ttpm-del { color:#b32d2e; margin-left:8px; }
	.ttpm-savebar { position:sticky; bottom:0; display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:12px; padding:12px 16px; border-radius:10px; background:#07122b; color:#fff; transform:translateY(8px); opacity:.0; pointer-events:none; transition:all .2s; z-index:20; }
	.ttpm-savebar.is-on { transform:none; opacity:1; pointer-events:auto; }
	.ttpm-age { display:block; margin-top:3px; font-size:11px; color:#2e7d4f; }
	.ttpm-age.is-old { color:#b26a00; }
	.ttpm-tools { background:#fff; border:1px solid #dbe3ee; border-radius:10px; margin:0 0 12px; }
	.ttpm-tools summary { cursor:pointer; padding:11px 14px; font-weight:700; color:#07122b; list-style:none; }
	.ttpm-tools summary::-webkit-details-marker { display:none; }
	.ttpm-tools summary em { font-weight:400; color:#5b6b80; font-style:normal; }
	.ttpm-tools summary .dashicons { color:#0AA2F5; margin-top:-2px; }
	.ttpm-tools-form { padding:0 14px 12px; border-top:1px solid #eef2f7; }
	.ttpm-tools-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:12px; }
	.ttpm-tools-row input[type=number] { width:80px; }
	.ttpm-tools .ttpm-hint { margin-top:10px; font-size:12px; }
	.ttpm-pages { display:flex; gap:6px; margin:14px 0; flex-wrap:wrap; }
	.ttpm-pages a { padding:6px 11px; border:1px solid #dbe3ee; border-radius:6px; background:#fff; text-decoration:none; }
	.ttpm-pages a.is-on { background:#0AA2F5; color:#fff; border-color:#0AA2F5; }
	/* edit */
	.ttpm-cols { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:18px; align-items:start; }
	.ttpm-card { background:#fff; border:1px solid #dbe3ee; border-radius:12px; padding:16px 18px; margin-bottom:16px; }
	.ttpm-card h2 { font-size:15px; margin:0 0 12px; padding:0 0 10px; border-bottom:1px solid #eef2f7; }
	.ttpm-card h2 em, .ttpm-field em { font-weight:400; color:#5b6b80; font-style:normal; font-size:12px; }
	.ttpm-field { display:block; margin-bottom:14px; }
	.ttpm-field > span { display:block; font-weight:600; margin-bottom:5px; }
	.ttpm-field > span i { color:#d63638; font-style:normal; }
	.ttpm-field input[type=text], .ttpm-field input[type=number], .ttpm-field textarea, .ttpm-card select, .ttpm-card textarea { width:100%; max-width:100%; }
	.ttpm-field small, .ttpm-card small { color:#5b6b80; display:block; margin-top:4px; }
	.ttpm-row2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
	.ttpm-hint { color:#5b6b80; margin:0 0 10px; }
	.ttpm-photos { display:flex; flex-wrap:wrap; gap:10px; margin:0 0 12px; padding:0; list-style:none; min-height:20px; }
	.ttpm-photos li { position:relative; width:110px; height:110px; border:1px solid #dbe3ee; border-radius:10px; background:#fff; cursor:move; display:flex; align-items:center; justify-content:center; margin:0; }
	.ttpm-photos li img { max-width:100px; max-height:100px; object-fit:contain; }
	.ttpm-photos li.is-main { border:2px solid #0AA2F5; }
	.ttpm-photos li.is-main::after { content:"Main photo"; position:absolute; bottom:-1px; left:-1px; right:-1px; background:#0AA2F5; color:#fff; font-size:11px; text-align:center; border-radius:0 0 9px 9px; padding:2px 0; }
	.ttpm-x { position:absolute; top:-8px; right:-8px; width:24px; height:24px; border-radius:50%; border:0; background:#d63638; color:#fff; font-size:16px; line-height:24px; cursor:pointer; padding:0; }
	.ttpm-switch, .ttpm-radio { display:flex; align-items:center; gap:8px; margin:0 0 10px; font-weight:600; }
	.ttpm-save { width:100%; text-align:center; margin:6px 0 10px !important; background:#0AA2F5 !important; border-color:#0A8FDB !important; }
	.ttpm-side .ttpm-del { display:block; text-align:center; margin:0; }
	.ttpm-cats { max-height:280px; overflow:auto; border:1px solid #eef2f7; border-radius:8px; padding:8px 10px; }
	.ttpm-cat { display:block; margin:3px 0; } .ttpm-cat.d0 { font-weight:700; margin-top:8px; } .ttpm-cat.d1 { padding-left:18px; } .ttpm-cat.d2 { padding-left:36px; }
	@media (min-width: 1100px) { .ttpm-sticky { position:sticky; top:46px; } }
	@media (max-width: 960px) { .ttpm-cols { grid-template-columns:1fr; } .ttpm-row2 { grid-template-columns:1fr; } }
	@media (max-width: 782px) {
		.ttpm-table thead { display:none; }
		.ttpm-table tr { display:grid; grid-template-columns:60px 1fr 1fr; gap:6px 10px; padding:10px; border-top:1px solid #eef2f7; }
		.ttpm-table td { border:0; padding:0; width:auto !important; }
		.ttpm-table .c-img { grid-row:span 2; }
		.ttpm-table .c-name { grid-column:2 / 4; }
		.ttpm-table .c-num::before { content:"Price"; display:block; font-size:11px; color:#5b6b80; }
		.ttpm-table .c-num + .c-num::before { content:"Sale"; }
		.ttpm-table .c-num input { width:100%; }
		.ttpm-table .c-stock { grid-column:2 / 4; }
		.ttpm-table .c-act { grid-column:2 / 4; }
		.ttpm-filter input[type=search] { min-width:0; width:100%; }
		.ttpm-count { margin-left:0; }
	}
	</style>
	<?php
} );
