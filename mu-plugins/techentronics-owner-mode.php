<?php
/**
 * Plugin Name: TechenTronics Owner Mode
 * Description: What the store owner sees when logged in: one-click "Edit" on every product, a Manage-store dock, a clean branded toolbar, and a TechenTronics admin colour scheme. Customers never see any of it.
 * Version: 1.0
 * Author: Coding Time Labs
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function ttom_is_staff() { return is_user_logged_in() && current_user_can( 'edit_products' ); }
function ttom_url( $page, $args = array() ) { return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ); }
function ttom_waiting_orders() {
	static $n = null;
	if ( null === $n ) {
		$n = function_exists( 'wc_get_orders' ) && current_user_can( 'edit_shop_orders' ) ? count( wc_get_orders( array( 'status' => 'tt-await-wa', 'limit' => 50, 'return' => 'ids' ) ) ) : 0;
	}
	return $n;
}

/* =========================================================
 * 1. Toolbar: store shortcuts first, clutter removed for staff
 * ======================================================= */
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ! ttom_is_staff() ) { return; }
	$bar->add_node( array( 'id' => 'ttom-products', 'title' => '<span class="ab-icon dashicons dashicons-store"></span><span class="ab-label">Products &amp; Prices</span>', 'href' => ttom_url( 'tt-products' ), 'meta' => array( 'class' => 'ttom-primary' ) ) );
	$dev = current_user_can( 'manage_options' ); // developer account: keep the busy toolbar compact
	$bar->add_node( array( 'id' => 'ttom-add', 'title' => '<span class="ab-icon dashicons dashicons-plus-alt2"></span>' . ( $dev ? '' : '<span class="ab-label">Add product</span>' ), 'href' => ttom_url( 'tt-product-edit' ), 'meta' => array( 'title' => 'Add a new product' ) ) );
	if ( current_user_can( 'edit_shop_orders' ) ) {
		$n = ttom_waiting_orders();
		$bar->add_node( array( 'id' => 'ttom-orders', 'title' => '<span class="ab-icon dashicons dashicons-cart"></span>' . ( $dev ? '' : '<span class="ab-label">Orders</span>' ) . ( $n ? ' <span class="ttom-count" title="Waiting for WhatsApp confirmation">' . (int) $n . '</span>' : '' ), 'href' => admin_url( 'admin.php?page=wc-orders' ) ) );
	}
	if ( ! is_admin() && ! $dev ) {
		$bar->add_node( array( 'id' => 'ttom-mode', 'parent' => 'top-secondary', 'title' => '<span class="ttom-dot"></span> Owner view', 'meta' => array( 'title' => 'Only you see the edit buttons and this toolbar. Customers see the normal shop.' ) ) );
	}
}, 35 );

// Store staff (not the developer/admin account) get a clean toolbar with only what they use.
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ! ttom_is_staff() ) { return; }
	if ( current_user_can( 'manage_options' ) ) { $bar->remove_node( 'ttpm-edit' ); return; } // developer: core "Edit product" + the dock are enough
	$keep = array( 'site-name', 'my-account', 'menu-toggle', 'ttom-products', 'ttom-add', 'ttom-orders', 'ttom-mode', 'ttpm-edit', 'top-secondary', 'root-default' );
	foreach ( (array) $bar->get_nodes() as $node ) {
		$top = empty( $node->parent ) || 'top-secondary' === $node->parent || 'root-default' === $node->parent;
		if ( $top && ! in_array( $node->id, $keep, true ) ) { $bar->remove_node( $node->id ); }
	}
}, 10001 );

/* =========================================================
 * 2. Front end (staff only): Edit buttons on every product + dock
 * ======================================================= */
add_action( 'wp_head', function () {
	if ( ! ttom_is_staff() ) { return; }
	?>
	<style id="ttom-front">
	/* Branded owner toolbar */
	#wpadminbar { background: linear-gradient(90deg, #07122b 0%, #0b2350 100%) !important; box-shadow: inset 0 -2px 0 #0AA2F5; }
	#wpadminbar #wp-admin-bar-woocommerce-site-visibility-badge .ab-item { background: #00b37e !important; color: #fff !important; }
	#wpadminbar .ab-item, #wpadminbar a.ab-item, #wpadminbar > #wp-toolbar span.ab-label, #wpadminbar #adminbarsearch:before, #wpadminbar .ab-icon:before, #wpadminbar .ab-item:before { color: #dfe9f7 !important; }
	#wpadminbar .ab-top-menu > li:hover > .ab-item, #wpadminbar .ab-top-menu > li > .ab-item:focus { background: #0b2a5c !important; color: #fff !important; }
	#wpadminbar .ab-submenu, #wpadminbar .ab-sub-wrapper { background: #0b1a3a !important; }
	#wpadminbar li.ttom-primary > .ab-item { background: #0AA2F5 !important; color: #fff !important; font-weight: 600; }
	#wpadminbar li.ttom-primary > .ab-item .ab-icon:before, #wpadminbar li.ttom-primary .ab-label { color: #fff !important; }
	#wpadminbar li.ttom-primary:hover > .ab-item { background: #0A8FDB !important; }
	#wpadminbar #wp-admin-bar-ttpm-edit > .ab-item { background: #00b37e !important; color: #fff !important; font-weight: 600; }
	#wpadminbar .ttom-count { display: inline-block; min-width: 18px; height: 18px; line-height: 18px; margin-left: 4px; padding: 0 5px; border-radius: 9px; background: #ff9f1a; color: #07122b; font-size: 11px; font-weight: 700; text-align: center; }
	#wpadminbar #wp-admin-bar-ttom-mode > .ab-item { color: #8fd8ff !important; font-weight: 600; cursor: help; }
	.ttom-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #2ee59d; box-shadow: 0 0 0 3px rgba(46,229,157,.25); margin-right: 4px; vertical-align: middle; }
	/* Edit chip on product cards */
	.ttom-card { position: relative; }
	.ttom-edit { position: absolute; top: 10px; left: 10px; z-index: 30; display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: 20px; background: #07122b; color: #fff !important; font: 600 12px/1.2 system-ui, -apple-system, "Segoe UI", sans-serif; text-decoration: none !important; box-shadow: 0 4px 12px rgba(7,18,43,.25); border: 1.5px solid #0AA2F5; transition: transform .15s, background .15s; }
	.ttom-edit:hover { background: #0AA2F5; transform: translateY(-1px); }
	.ttom-card .tt-pc-badges { top: 46px; }
	.ttom-edit.is-noprice { background: #ff9f1a; border-color: #ff9f1a; color: #07122b !important; }
	.ttom-edit svg { width: 13px; height: 13px; fill: currentColor; }
	/* Manage-store dock */
	.ttom-dock { position: fixed; left: 16px; bottom: 16px; z-index: 99990; display: flex; align-items: center; gap: 6px; padding: 6px; border-radius: 40px; background: #07122b; box-shadow: 0 10px 30px rgba(7,18,43,.35); border: 1px solid rgba(10,162,245,.5); font: 600 13px/1 system-ui, -apple-system, "Segoe UI", sans-serif; }
	.ttom-dock a { display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 16px; border-radius: 30px; color: #fff !important; text-decoration: none !important; white-space: nowrap; }
	.ttom-dock a.ttom-go { background: #0AA2F5; }
	.ttom-dock a.ttom-go.is-product { background: #00b37e; }
	.ttom-dock a.ttom-plus { width: 38px; padding: 0; justify-content: center; background: rgba(255,255,255,.08); font-size: 20px; }
	.ttom-dock a:hover { filter: brightness(1.1); }
	.ttom-dock svg { width: 15px; height: 15px; fill: currentColor; }
	@media (max-width: 600px) { .ttom-dock { left: 10px; bottom: 10px; } .ttom-dock a { height: 36px; padding: 0 13px; font-size: 12px; } .ttom-edit { top: 6px; left: 6px; padding: 4px 9px; font-size: 11px; } }
	@media print { .ttom-dock, .ttom-edit { display: none !important; } }
	</style>
	<?php
}, 99 );

add_action( 'wp_footer', function () {
	if ( ! ttom_is_staff() ) { return; }
	$edit_base = ttom_url( 'tt-product-edit' );
	$on_product = function_exists( 'is_product' ) && is_product();
	$pencil = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M13.6 2.6a2 2 0 0 1 2.8 0l1 1a2 2 0 0 1 0 2.8L7.3 16.5l-4.3 1 1-4.3L13.6 2.6z"/></svg>';
	?>
	<div class="ttom-dock" role="navigation" aria-label="Store owner shortcuts">
		<?php if ( $on_product ) : ?>
			<a class="ttom-go is-product" href="<?php echo esc_url( add_query_arg( 'id', get_the_ID(), $edit_base ) ); ?>"><?php echo $pencil; // phpcs:ignore ?> Edit this product</a>
			<a class="ttom-plus" href="<?php echo esc_url( ttom_url( 'tt-products' ) ); ?>" title="All products &amp; prices"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h14v2H3zM3 9h14v2H3zM3 14h14v2H3z"/></svg></a>
		<?php else : ?>
			<a class="ttom-go" href="<?php echo esc_url( ttom_url( 'tt-products' ) ); ?>"><?php echo $pencil; // phpcs:ignore ?> Manage products</a>
			<a class="ttom-plus" href="<?php echo esc_url( $edit_base ); ?>" title="Add a new product">+</a>
		<?php endif; ?>
	</div>
	<script>
	(function(){
		var base = <?php echo wp_json_encode( $edit_base ); ?>, pencil = <?php echo wp_json_encode( $pencil ); ?>;
		function idOf(card){
			var m = (card.className || '').match(/\bpost-(\d+)\b/); if (m) { return m[1]; }
			var el = card.querySelector('[data-product-id],[data-product_id]');
			return el ? (el.getAttribute('data-product-id') || el.getAttribute('data-product_id')) : null;
		}
		function addChips(root){
			var cards = [];
			root.querySelectorAll('li.product, .product-block_one-inner, .product-block_two-inner, .product-block_three-inner, .product-block-inner').forEach(function(c){ cards.push(c); });
			root.querySelectorAll('[data-product-id]').forEach(function(el){
				if (el.closest('li.product')) { return; }
				var c = el.closest('[class*="product-block"][class*="inner"]') || el.closest('[class*="product-block"]');
				if (c && cards.indexOf(c) < 0) { cards.push(c); }
			});
			cards.forEach(function(card){
				if (card.dataset.ttom || card.closest('.single-product-summary, .summary')) { return; }
				var id = idOf(card); if (!id) { return; }
				card.dataset.ttom = '1'; card.classList.add('ttom-card');
				var noPrice = /Price on WhatsApp/i.test(card.textContent);
				var a = document.createElement('a');
				a.className = 'ttom-edit' + (noPrice ? ' is-noprice' : '');
				a.href = base + '&id=' + encodeURIComponent(id);
				a.title = noPrice ? 'This product has no price yet. Click to add one.' : 'Edit price, photos and details';
				a.innerHTML = pencil + (noPrice ? ' Add price' : ' Edit');
				a.addEventListener('click', function(e){ e.stopPropagation(); });
				card.appendChild(a);
			});
		}
		function run(){ addChips(document); }
		if (document.readyState !== 'loading') { run(); } else { document.addEventListener('DOMContentLoaded', run); }
		// Homepage tabs, "load more" and AJAX filters add cards later.
		var t; new MutationObserver(function(){ clearTimeout(t); t = setTimeout(run, 150); }).observe(document.body, { childList: true, subtree: true });
	})();
	</script>
	<?php
}, 99 );

/* =========================================================
 * 3. "TechenTronics" admin colour scheme (default for store staff)
 * ======================================================= */
add_action( 'admin_init', function () {
	wp_admin_css_color( 'techentronics', 'TechenTronics', false, array( '#07122b', '#0b1a3a', '#0AA2F5', '#2ee59d' ), array( 'base' => '#9fb3cc', 'focus' => '#ffffff', 'current' => '#ffffff' ) );
}, 1 );
add_filter( 'get_user_option_admin_color', function ( $color ) {
	if ( ( empty( $color ) || in_array( $color, array( 'fresh', 'modern' ), true ) ) && ttom_is_staff() && ! get_user_meta( get_current_user_id(), 'ttom_scheme_chosen', true ) ) { return 'techentronics'; }
	return $color;
} );
// If someone picks a different scheme on their profile, respect it.
add_action( 'personal_options_update', function ( $uid ) { update_user_meta( $uid, 'ttom_scheme_chosen', 1 ); } );

function ttom_admin_scheme_css() {
	return '
	#adminmenuback, #adminmenuwrap, #adminmenu { background: #07122b; }
	#adminmenu a { color: #dfe9f7; }
	#adminmenu div.wp-menu-image:before { color: #9fb3cc; }
	#adminmenu a:hover, #adminmenu li.menu-top:hover, #adminmenu li.opensub > a.menu-top, #adminmenu li > a.menu-top:focus { color: #fff; background-color: #0b2a5c; }
	#adminmenu li.menu-top:hover div.wp-menu-image:before, #adminmenu li.opensub > a.menu-top div.wp-menu-image:before { color: #0AA2F5; }
	#adminmenu .wp-submenu, #adminmenu .wp-has-current-submenu .wp-submenu, #adminmenu .wp-has-current-submenu.opensub .wp-submenu, #adminmenu a.wp-has-current-submenu:focus + .wp-submenu, .folded #adminmenu .wp-has-current-submenu .wp-submenu { background: #0b1a3a; }
	#adminmenu .wp-submenu a { color: #b9c9de; }
	#adminmenu .wp-submenu a:focus, #adminmenu .wp-submenu a:hover, #adminmenu a:hover, #adminmenu .wp-submenu li.current a:hover { color: #0AA2F5; }
	#adminmenu .wp-submenu li.current a, #adminmenu .wp-submenu li.current a:focus { color: #fff; font-weight: 600; }
	#adminmenu li.current a.menu-top, #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu, #adminmenu li.wp-has-current-submenu .wp-submenu .wp-submenu-head, .folded #adminmenu li.current.menu-top { background: #0AA2F5; color: #fff; }
	#adminmenu li.wp-has-current-submenu div.wp-menu-image:before, #adminmenu .current div.wp-menu-image:before, #adminmenu a.current:hover div.wp-menu-image:before { color: #fff; }
	#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub:hover:after, #adminmenu li.wp-has-submenu.wp-not-current-submenu:focus-within:after { border-right-color: #0b1a3a; }
	#adminmenu .awaiting-mod, #adminmenu .update-plugins { background: #ff9f1a; color: #07122b; }
	#collapse-button { color: #9fb3cc; }
	#collapse-button:hover { color: #0AA2F5; }
	#toplevel_page_tt-products > a { background: rgba(10,162,245,.14); }
	#toplevel_page_tt-products > a .wp-menu-image:before { color: #0AA2F5; }
	#wpadminbar { background: linear-gradient(90deg, #07122b 0%, #0b2350 100%) !important; box-shadow: inset 0 -2px 0 #0AA2F5 !important; }
	#wpadminbar #wp-admin-bar-woocommerce-site-visibility-badge .ab-item { background: #00b37e !important; color: #fff !important; }
	#wpadminbar .ab-item, #wpadminbar a.ab-item, #wpadminbar > #wp-toolbar span.ab-label, #wpadminbar .ab-icon:before, #wpadminbar .ab-item:before { color: #dfe9f7 !important; }
	#wpadminbar .ab-top-menu > li:hover > .ab-item, #wpadminbar .ab-top-menu > li.hover > .ab-item, #wpadminbar .ab-top-menu > li > .ab-item:focus { background: #0b2a5c !important; color: #fff !important; }
	#wpadminbar .menupop .ab-sub-wrapper { background: #0b1a3a !important; }
	#wpadminbar li.ttom-primary > .ab-item { background: #0AA2F5 !important; color: #fff !important; font-weight: 600; }
	#wpadminbar li.ttom-primary > .ab-item .ab-icon:before, #wpadminbar li.ttom-primary .ab-label { color: #fff !important; }
	#wpadminbar .ttom-count { display: inline-block !important; min-width: 18px !important; height: 18px !important; line-height: 18px !important; margin-left: 4px !important; padding: 0 5px !important; border-radius: 9px !important; background: #ff9f1a !important; color: #07122b !important; font-size: 11px !important; font-weight: 700 !important; text-align: center !important; }
	.wp-core-ui .button-primary { background: #0AA2F5; border-color: #0A8FDB; }
	.wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus { background: #0A8FDB; border-color: #0878bb; }
	.wp-core-ui .button-primary, .wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus { color: #fff; }
	.wp-core-ui .button-secondary, .wp-core-ui .button:not(.button-primary):not(.button-link) { color: #0A6FB0; border-color: #0A8FDB; }
	.wp-core-ui .button-secondary:hover, .wp-core-ui .button:not(.button-primary):not(.button-link):hover { color: #07122b; border-color: #07122b; }
	a { color: #0A7FC4; }
	a:hover, a:active { color: #07122b; }
	.wrap .page-title-action { color: #0A6FB0; border-color: #0A8FDB; }
	input[type=checkbox]:checked::before { content: url("data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%2020%2020%27%3E%3Cpath%20d%3D%27M14.83%204.89l1.34.94-5.81%208.38H9.02L5.78%209.67l1.34-1.25%202.57%202.4z%27%20fill%3D%27%230AA2F5%27%2F%3E%3C%2Fsvg%3E"); }
	input[type=radio]:checked::before { background: #0AA2F5; }
	input:focus, select:focus, textarea:focus { border-color: #0AA2F5 !important; box-shadow: 0 0 0 1px #0AA2F5 !important; }
	';
}
add_action( 'admin_head', function () {
	if ( 'techentronics' === get_user_option( 'admin_color' ) ) { echo '<style id="ttom-admin-scheme">' . ttom_admin_scheme_css() . '</style>'; } // phpcs:ignore
}, 99 );

/* =========================================================
 * 4. Owner strip: a visible "owner view" bar with today's to-do list
 * ======================================================= */
function ttom_stats() {
	$st = get_transient( 'ttom_stats' );
	if ( false !== $st ) { return $st; }
	$count = function ( $view ) {
		if ( ! function_exists( 'ttpm_query_args' ) ) { return 0; }
		$q = ttpm_query_args( '', '', $view );
		$q['post_status'] = array( 'publish' ); $q['posts_per_page'] = 1;
		return (int) ( new WP_Query( $q ) )->found_posts;
	};
	$st = array( 'noprice' => $count( 'noprice' ), 'stale' => $count( 'stale' ), 'out' => $count( 'out' ) );
	set_transient( 'ttom_stats', $st, 10 * MINUTE_IN_SECONDS );
	return $st;
}
add_action( 'woocommerce_order_status_changed', function () { delete_transient( 'ttom_stats' ); } );

add_action( 'wp_footer', function () {
	if ( ! ttom_is_staff() || is_admin() ) { return; }
	$st   = ttom_stats();
	$wait = ttom_waiting_orders();
	$list = ttom_url( 'tt-products' );
	$items = array();
	if ( $wait && current_user_can( 'edit_shop_orders' ) ) { $items[] = array( 'is-hot', admin_url( 'admin.php?page=wc-orders&status=wc-tt-await-wa' ), $wait . ' order' . ( 1 === $wait ? '' : 's' ) . ' to confirm' ); }
	if ( $st['stale'] ) { $items[] = array( 'is-warn', add_query_arg( 'view', 'stale', $list ), $st['stale'] . ' price' . ( 1 === $st['stale'] ? '' : 's' ) . ' to check' ); }
	if ( $st['noprice'] ) { $items[] = array( '', add_query_arg( 'view', 'noprice', $list ), $st['noprice'] . ' without a price' ); }
	if ( $st['out'] ) { $items[] = array( '', add_query_arg( 'view', 'out', $list ), $st['out'] . ' out of stock' ); }
	?>
	<div class="ttom-strip" id="ttom-strip" role="region" aria-label="Store owner view">
		<div class="ttom-strip-in">
			<span class="ttom-strip-label"><span class="ttom-dot"></span> Owner view <em>customers don't see this bar</em></span>
			<span class="ttom-strip-items">
				<?php foreach ( $items as $it ) : ?><a class="ttom-chip <?php echo esc_attr( $it[0] ); ?>" href="<?php echo esc_url( $it[1] ); ?>"><?php echo esc_html( $it[2] ); ?></a><?php endforeach; ?>
				<?php if ( ! $items ) : ?><span class="ttom-chip is-ok">All caught up ✓</span><?php endif; ?>
			</span>
			<a class="ttom-strip-go" href="<?php echo esc_url( $list ); ?>">Manage products &rarr;</a>
		</div>
	</div>
	<style>
	.ttom-strip { position: relative; z-index: 9990; background: linear-gradient(90deg, #0AA2F5 0%, #0866c9 55%, #07122b 100%); color: #fff; font: 600 13px/1.2 system-ui, -apple-system, "Segoe UI", sans-serif; }
	.ttom-strip-in { display: flex; align-items: center; gap: 14px; max-width: 1320px; margin: 0 auto; padding: 8px 16px; overflow-x: auto; scrollbar-width: none; }
	.ttom-strip-in::-webkit-scrollbar { display: none; }
	.ttom-strip-label { white-space: nowrap; letter-spacing: .02em; text-transform: uppercase; font-size: 12px; }
	.ttom-strip-label em { font-style: normal; text-transform: none; font-weight: 400; opacity: .85; margin-left: 6px; }
	.ttom-strip-items { display: flex; gap: 8px; flex: 1; }
	.ttom-chip { white-space: nowrap; padding: 5px 11px; border-radius: 20px; background: rgba(255,255,255,.16); color: #fff !important; text-decoration: none !important; border: 1px solid rgba(255,255,255,.25); }
	.ttom-chip:hover { background: rgba(255,255,255,.28); }
	.ttom-chip.is-hot { background: #ff9f1a; border-color: #ff9f1a; color: #07122b !important; }
	.ttom-chip.is-warn { background: #fff4d6; border-color: #fff4d6; color: #7a5600 !important; }
	.ttom-chip.is-ok { background: rgba(46,229,157,.2); border-color: rgba(46,229,157,.5); }
	.ttom-strip-go { white-space: nowrap; padding: 6px 14px; border-radius: 20px; background: #fff; color: #07122b !important; text-decoration: none !important; }
	@media (max-width: 782px) { .ttom-strip-label em { display: none; } }
	</style>
	<script>(function(){ var s=document.getElementById('ttom-strip'); if(s && document.body.firstChild){ document.body.insertBefore(s, document.body.firstChild); } })();</script>
	<?php
}, 5 );

/* =========================================================
 * 5. Logging in as store staff goes straight to the product manager
 * ======================================================= */
add_filter( 'woocommerce_login_redirect', function ( $redirect, $user ) {
	return ( $user && user_can( $user, 'edit_products' ) && ! user_can( $user, 'manage_options' ) ) ? admin_url( 'admin.php?page=tt-products' ) : $redirect;
}, 20, 2 );
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && user_can( $user, 'edit_products' ) && ! user_can( $user, 'manage_options' ) && ( ! $requested || false !== strpos( $requested, 'wp-admin' ) && false === strpos( $requested, 'page=' ) ) ) {
		return admin_url( 'admin.php?page=tt-products' );
	}
	return $redirect;
}, 20, 3 );

/* =========================================================
 * 6. Dashboard: sales at a glance
 * ======================================================= */
add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( 'edit_shop_orders' ) ) { return; }
	wp_add_dashboard_widget( 'ttom_sales', 'Sales at a glance', 'ttom_sales_widget' );
	global $wp_meta_boxes; // move to the top
	$w = $wp_meta_boxes['dashboard']['normal']['core']['ttom_sales'] ?? null;
	if ( $w ) { unset( $wp_meta_boxes['dashboard']['normal']['core']['ttom_sales'] ); $wp_meta_boxes['dashboard']['normal']['high']['ttom_sales'] = $w; }
}, 20 );
function ttom_sales_widget() {
	$tz    = wp_timezone();
	$today = ( new DateTime( 'today', $tz ) )->getTimestamp();
	$ranges = array( 'Today' => $today, 'Last 7 days' => $today - 6 * DAY_IN_SECONDS, 'This month' => ( new DateTime( 'first day of this month 00:00', $tz ) )->getTimestamp() );
	$ok = array( 'tt-confirmed', 'tt-packed', 'tt-dispatched', 'completed', 'processing' );
	$orders = wc_get_orders( array( 'status' => array_merge( $ok, array( 'tt-await-wa' ) ), 'date_created' => '>=' . $ranges['This month'], 'limit' => -1 ) );
	echo '<div class="ttom-sales">';
	foreach ( $ranges as $label => $from ) {
		$n = 0; $sum = 0;
		foreach ( $orders as $o ) { if ( $o->get_date_created() && $o->get_date_created()->getTimestamp() >= $from && in_array( $o->get_status(), $ok, true ) ) { $n++; $sum += (float) $o->get_total(); } }
		printf( '<div><span>%s</span><b>%s</b><small>%d confirmed order%s</small></div>', esc_html( $label ), wp_kses_post( wc_price( $sum ) ), (int) $n, 1 === $n ? '' : 's' );
	}
	echo '</div>';
	$wait = ttom_waiting_orders();
	if ( $wait ) { printf( '<p class="ttom-sales-wait"><a href="%s">%d order%s waiting for WhatsApp confirmation &rarr;</a></p>', esc_url( admin_url( 'admin.php?page=wc-orders&status=wc-tt-await-wa' ) ), (int) $wait, 1 === $wait ? '' : 's' ); }
	$st = ttom_stats();
	printf( '<p class="ttom-sales-todo"><a href="%s">%d prices to check</a> &middot; <a href="%s">%d without a price</a> &middot; <a href="%s">%d out of stock</a></p>', esc_url( ttom_url( 'tt-products', array( 'view' => 'stale' ) ) ), (int) $st['stale'], esc_url( ttom_url( 'tt-products', array( 'view' => 'noprice' ) ) ), (int) $st['noprice'], esc_url( ttom_url( 'tt-products', array( 'view' => 'out' ) ) ), (int) $st['out'] );
	echo '<p class="description">Totals count orders you have confirmed (not ones still waiting on WhatsApp). Delivery fees are included.</p>';
	echo '<style>.ttom-sales{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px}.ttom-sales>div{background:#f4f8fd;border:1px solid #dbe3ee;border-radius:10px;padding:12px}.ttom-sales span{display:block;color:#5b6b80;font-size:12px}.ttom-sales b{display:block;font-size:20px;color:#07122b;margin:4px 0}.ttom-sales small{color:#5b6b80}.ttom-sales-wait a{display:inline-block;background:#fff4d6;color:#7a5600;padding:7px 12px;border-radius:20px;font-weight:600;text-decoration:none}@media(max-width:600px){.ttom-sales{grid-template-columns:1fr}}</style>';
}
