<?php
/**
 * Calmara front page — serves Shopify clone thera.html as homepage.
 * Patched 2026-06-09 by Boris — strip all broken Shopify refs + WC ADD TO CART.
 *
 * @package calmara
 */

$clone_dir   = get_template_directory()     . '/assets/calmara-clone';
$clone_uri   = get_template_directory_uri() . '/assets/calmara-clone';
$source_file = $clone_dir . '/site/products/thera.html';

if ( ! file_exists( $source_file ) ) {
    status_header( 500 );
    echo 'Clone source missing: ' . esc_html( $source_file );
    exit;
}

$html = file_get_contents( $source_file );

// === Asset path rewrites ===
$replacements = array(
    '../../cdn-assets/'  => $clone_uri . '/cdn-assets/',
    '../../fonts-google/'=> $clone_uri . '/fonts-google/',
    '../../fonts-static/'=> $clone_uri . '/fonts-static/',
    'https://cdn-assets/' => $clone_uri . '/cdn-assets/',
    '"//cdn-assets/'      => '"' . $clone_uri . '/cdn-assets/',
    '../cdn/'            => $clone_uri . '/site/cdn/',
    '../checkouts/'      => $clone_uri . '/site/checkouts/',
    'href="thera.html'   => 'href="' . esc_url( home_url( '/' ) ),
    'href="thera-back.html' => 'href="' . $clone_uri . '/site/products/thera-back.html',
    'href="thera-wrap.html' => 'href="' . $clone_uri . '/site/products/thera-wrap.html',
    'href="thera.oembed'    => 'href="' . $clone_uri . '/site/products/thera.oembed',
);
$html = strtr( $html, $replacements );

// === Strip ALL Shopify external script/link tags (CSP-friendly clean) ===
$strip_patterns = array(
    // External Shopify infrastructure
    '#<link[^>]*(href|src)="(https?:)?//(fonts\.shopifycdn\.com|monorail-edge\.shopifysvc\.com|cdn\.shopify\.com|shop\.app|shopifycloud|judge\.me|cdn\.judge\.me|shrinetheme\.com|d1um8515vdn9kb\.cloudfront\.net|tag\.segmetrics\.io|subscription-admin\.appstle\.com|cdn-shop-cdn\.shopify\.com|shopify\.jsdeliver\.cloud|calmara\.com)[^"]*"[^>]*>#i',
    '#<script[^>]*src="(https?:)?//(fonts\.shopifycdn\.com|monorail-edge\.shopifysvc\.com|cdn\.shopify\.com|shop\.app|shopifycloud|judge\.me|cdn\.judge\.me|shrinetheme\.com|d1um8515vdn9kb\.cloudfront\.net|tag\.segmetrics\.io|subscription-admin\.appstle\.com|shopify\.jsdeliver\.cloud|calmara\.com)[^"]*"[^>]*></script>#i',
    // Inline-by-pattern broken refs
    '#<script[^>]*src="[^"]*shopifycloud[^"]*"[^>]*></script>#i',
    '#<link[^>]*href="[^"]*shopifycloud[^"]*"[^>]*>#i',
    '#<script[^>]*src="[^"]*compiled_assets/scripts\.js[^"]*"[^>]*></script>#i',
    '#<script[^>]*src="[^"]*checkouts/internal/preloads\.js[^"]*"[^>]*></script>#i',
    '#<script[^>]*src="[^"]*gempagev2\.js[^"]*"[^>]*></script>#i',
    '#<script[^>]*src="[^"]*/cdn/wpm/[^"]*"[^>]*></script>#is',
    '#<script[^>]*data-trekkie-shim[^>]*></script>#is',
    '#<script[^>]*src="[^"]*judge\.me[^"]*"[^>]*></script>#i',
    '#<link[^>]*href="[^"]*judge\.me[^"]*"[^>]*>#i',
    '#<noscript>\s*<link[^>]*judge\.me[^"]*>\s*</noscript>#is',
    '#<link[^>]*(href|src)="(https?:)?//shop\.app[^"]*"[^>]*>#i',
    '#<script[^>]*data-source-attribution="shopify\.dynamic_checkout\.dynamic\.init"[^>]*>[\s\S]*?</script>#i',
    '#<script[^>]*src="/cdn/[^"]*"[^>]*></script>#i',
    '#<link[^>]*href="/cdn/[^"]*"[^>]*>#i',
    // Inline scripts that call Shopify.PaymentButton / window.ShopifyAnalytics — too complex to fix, just strip
    '#<script[^>]*>\s*window\.Shopify\.PaymentButton[^<]*</script>#is',
    '#<script[^>]*>\s*\(function\(\)\{[^<]*ShopifyAnalytics[^<]*</script>#is',
);
$html = preg_replace( $strip_patterns, "\n", $html );

// === Stub out window.Shopify so inline refs don't error ===
$shopify_stub = '<script>window.Shopify=window.Shopify||{};Shopify.shop=Shopify.shop||"calmara.local";Shopify.locale=Shopify.locale||"en";Shopify.currency=Shopify.currency||{active:"EUR",rate:"1.0"};Shopify.country=Shopify.country||"SI";Shopify.routes=Shopify.routes||{root:"/"};Shopify.theme=Shopify.theme||{name:"calmara",id:1,handle:"calmara",style:{id:null,handle:null},role:"main"};Shopify.cdnHost=Shopify.cdnHost||"";Shopify.designMode=false;window.ShopifyAnalytics=window.ShopifyAnalytics||{meta:{},lib:{track:function(){},page:function(){},identify:function(){}},merchantGoogleAnalytics:function(){}};window.ShopifyPay=window.ShopifyPay||{apiHost:"",redirectState:null};window.trekkie=window.trekkie||{ready:function(){},load:function(){},config:function(){},methods:["identify","page","ready","track","trackForm","trackLink"]};</script>';
$html = preg_replace( '#<head[^>]*>#i', '$0' . $shopify_stub, $html, 1 );

// === Per-market title + lang fix ===
$market_titles = array(
    'si-calmara' => array( 'lang' => 'sl-SI', 'title' => 'Calmara — Slovenija' ),
    'hr-calmara' => array( 'lang' => 'hr-HR', 'title' => 'Calmara — Hrvatska' ),
    'hu-calmara' => array( 'lang' => 'hu-HU', 'title' => 'Calmara — Magyarország' ),
    'pl-calmara' => array( 'lang' => 'pl-PL', 'title' => 'Calmara — Polska' ),
    'sk-calmara' => array( 'lang' => 'sk-SK', 'title' => 'Calmara — Slovensko' ),
    'cz-calmara' => array( 'lang' => 'cs-CZ', 'title' => 'Calmara — Česko' ),
    'gr-calmara' => array( 'lang' => 'el-GR', 'title' => 'Calmara — Ελλάδα' ),
);
$host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
$market_key = '';
foreach ( array_keys( $market_titles ) as $k ) {
    if ( strpos( $host, $k ) !== false ) { $market_key = $k; break; }
}
if ( $market_key !== '' ) {
    $mt = $market_titles[ $market_key ];
    $html = preg_replace( '#<html([^>]*)lang="[^"]*"#i', '<html$1lang="' . $mt['lang'] . '"', $html, 1 );
    $html = preg_replace( '#<title>[^<]*</title>#i', '<title>' . esc_html( $mt['title'] ) . '</title>', $html, 1 );
    $html = preg_replace( '#<meta property="og:title" content="[^"]*"#i', '<meta property="og:title" content="' . esc_attr( $mt['title'] ) . '"', $html, 1 );
}

// === Inject WC ADD TO CART handler before </body> ===
$wc_product_id = (int) get_option( 'calmara_test_product_id', 0 );
if ( $wc_product_id < 1 ) { $wc_product_id = 14; }
$wc_cart_url   = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/cart/';
$wc_handler = '<script>(function(){
  var PID = ' . intval( $wc_product_id ) . ';
  var CART = ' . wp_json_encode( $wc_cart_url ) . ';
  function bind(){
    var sel = [
      "button[name=\"add\"]","button.add-to-cart","button[data-add-to-cart]",
      "form[action*=cart] button[type=submit]",
      "a.add-to-cart","[data-product-add-to-cart]","button.product-form__submit",
      "button#AddToCart","button[id*=AddToCart]","button[class*=add-to-cart]",
      "button[class*=AddToCart]","button[class*=product-form__cart]",
      "button[class*=cart-btn]","button[class*=buy-now]",
      "input[name=add]","[data-buy-now]","[data-add-to-cart-button]"
    ];
    var btns = document.querySelectorAll(sel.join(","));
    btns.forEach(function(b){
      if (b.dataset.wcBound) return;
      b.dataset.wcBound = "1";
      b.addEventListener("click", function(e){
        e.preventDefault(); e.stopPropagation();
        var qtyEl = document.querySelector("input[name=quantity],input.qty,[data-quantity]");
        var qty = qtyEl ? (parseInt(qtyEl.value,10)||1) : 1;
        var orig = b.innerHTML;
        try { b.innerHTML = "Dodajam…"; b.disabled = true; } catch(_){}
        var body = new URLSearchParams();
        body.append("product_id", PID);
        body.append("quantity", qty);
        body.append("add-to-cart", PID);
        fetch("/?wc-ajax=add_to_cart", {
          method: "POST",
          credentials: "include",
          headers: {"Content-Type": "application/x-www-form-urlencoded"},
          body: body.toString()
        }).then(function(r){return r.text();})
          .then(function(){ window.location.href = CART; })
          .catch(function(){ window.location.href = "/?add-to-cart=" + PID + "&quantity=" + qty; });
      }, true);
    });
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bind);
  } else { bind(); }
  setTimeout(bind, 1500); setTimeout(bind, 3500);
})();</script>';
$html = preg_replace( '#</body>#i', $wc_handler . '</body>', $html, 1 );

header( 'Content-Type: text/html; charset=UTF-8' );
echo $html;
exit;
