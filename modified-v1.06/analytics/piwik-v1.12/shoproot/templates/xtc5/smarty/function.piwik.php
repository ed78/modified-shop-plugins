<?php

function smarty_function_piwik($params, &$smarty) {
  
	$url = isset($params['url']) ? $params['url'] : false;
	$id = isset($params['id']) ? (int)$params['id'] : false;
	
	if (!$url || !$id)
		return false;

	$urls = getPiwikTrackingUrls($url);
	
	$beginTrackingCode = '
		<script type="text/javascript">
		var _paq = _paq || [];
		(function(){
		var u=(("https:" == document.location.protocol) ? "'
			.$urls['HTTPS'].'" : "'.$urls['HTTP'].'");
		_paq.push([\'setSiteId\', '.$id.']);
		_paq.push([\'setTrackerUrl\', u+\'piwik.php\']);      
	';
	
	$endTrackingCode = 
		'
		_paq.push([\'trackPageView\']);
		_paq.push([\'enableLinkTracking\']);
		var d=document,
        g=d.createElement(\'script\'),
        s=d.getElementsByTagName(\'script\')[0];
        g.type=\'text/javascript\';
        g.defer=true;
        g.async=true;
        g.src=u+\'piwik.js\';
        s.parentNode.insertBefore(g,s);
		})();
		</script>
		<noscript><p><img src="'.$urls['HTTPS'].'/piwik.php?idsite='
			.$id.'&rec=1" style="border:0" alt="" /></p></noscript>
	';
	    
	global $PHP_SELF; $ecTrackingCode = null;

	if (strpos($PHP_SELF, FILENAME_PRODUCT_INFO))
		$ecTrackingCode .= getPiwikProductTrackingCode();
	
	if (isset($_GET['cPath']))
		$ecTrackingCode .= getPiwikCategoryTrackingCode();
	
	if (strpos($PHP_SELF, FILENAME_SHOPPING_CART))
		$ecTrackingCode .= getPiwikCartTrackingCode();
  	
	if (strpos($PHP_SELF, FILENAME_CHECKOUT_SUCCESS))
		$ecTrackingCode .= getPiwikOrderTrackingCode();
  
	return $beginTrackingCode.$ecTrackingCode.$endTrackingCode;
}

function getPiwikTrackingUrls($url) {
	// e.g. admin backend piwik url -> www.domain.com/piwik/
	$url = trim(str_replace(array('http://', 'https://'), '', $url), '/'); // $url = www.domain.com/piwik
	$host = trim(substr($url, 0, strrpos($url, '/')), '/'); // $host = www.domain.com
	$path = substr($url, strrpos($url, '/')+1); // $path = piwik
	$surl = 'http://'.$url; // $surl = http://www.domain.com/piwik
	if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] === true)) // if SSL enabled
		$surl = 'https://'.$url; // $surl = https://www.domain.com/piwik
	if ('http://'.$host == trim(HTTP_SERVER, '/')) // if same host as in configure.php...
		if (defined('ENABLE_SSL') && defined('HTTPS_SERVER')) // and SSL enabled...
			$surl = trim(HTTPS_SERVER, '/').'/'.$path.'/'; // use defined SSL (proxy) host
	$urls['HTTP'] = 'http://'.trim($url, '/').'/';
	$urls['HTTPS'] = trim($surl, '/').'/';
	return $urls;
}

function getPiwikProductItemSku($id, $model) {
	return (isset($model) && !empty($model)) ? "$model" : "[id:$id]";
}

function getPiwikProductCategoriesCode($product_id) {	
	$query = xtc_db_query(
		"SELECT cd.categories_name name 
			FROM ".TABLE_PRODUCTS_TO_CATEGORIES." p2c
			JOIN ".TABLE_PRODUCTS." p 
				ON p2c.products_id = p.products_id 					
			JOIN ".TABLE_CATEGORIES_DESCRIPTION." cd 
				ON cd.categories_id = p2c.categories_id 
				AND p.products_id = ".xtc_db_input($product_id)." 
				AND cd.language_id = ".$_SESSION['languages_id']);
	$categories = array(); $count = 0;
	while($categories[$count++] = xtc_db_fetch_array($query)['name']);
	unset($categories[--$count]);
	if ($count <= 0) 
		return null;
	$categoriesCode = '["'.$categories[0].'"';
	for($i = 1; $i < 5; $i++) // up to 5, see piwik docs
		if (isset($categories[$i]))
			$categoriesCode .= ', "'.$categories[$i].'"';
	$categoriesCode .= ']';
	return $categoriesCode;
}

function getPiwikProductItemsCode(&$products) {
	$itemsCode = null;
	foreach($products as $item) {		
		$itemsCode .= 
		' _paq.push([\'addEcommerceItem\', 
			'.getPiwikProductItemSku(
				$item['id'], $item['model']).',	
			"'.$item['name'].'",	
			'.getPiwikProductCategoriesCode(
				$item['id']).',
			'.$item['final_price'].',
			'.$item['quantity'].']);';
	}
	return $itemsCode;
}

function getPiwikProductTrackingCode() { 
	global $product; // from product_info.php
	if (!is_object($product) || !$product->isProduct())
		return null;
	$products_price = null;
	if ($_SESSION['customers_status']['customers_status_show_price'] != '0')
		$products_price = $product->data['products_price'];	
	return
		' _paq.push([\'setEcommerceView\', 
			'.getPiwikProductItemSku(
				$product->data['products_id'], 
				$product->data['products_model']).',
			"'.$product->data['products_name'].'",	
			'.getPiwikProductCategoriesCode(
				$product->data['products_id']).',
			'.$products_price.']);';
}

function getPiwikCategoryTrackingCode() {
	global $current_category_id;
	if (!isset($current_category_id))
		return null;	
	$query = xtc_db_query(
		"SELECT cd.categories_name name 
			FROM ".TABLE_CATEGORIES_DESCRIPTION." cd
			WHERE cd.categories_id = ".
				xtc_db_input($current_category_id)."			
			AND cd.language_id = ".$_SESSION['languages_id']);
	$category = xtc_db_fetch_array($query)['name'];
	return
		' _paq.push([\'setEcommerceView\', 
			productSku = false,	
			productName = false,	
			category = "'.$category.'"]);';
}

function getPiwikCartTrackingCode() {
	$itemsCode = null;
	if ($_SESSION['cart']->count_contents() > 0)
		$itemsCode = getPiwikProductItemsCode(
			$_SESSION['cart']->get_products());
	return 
		$itemsCode 
		.' _paq.push([\'trackEcommerceCartUpdate\', '
		.$_SESSION['cart']->show_total().']);';
}

function getPiwikOrderTrackingCode() {
	global $last_order; // id of last order 
				// from checkout_success.php
	
	// check if id of last order exists
	if (!isset($last_order) || empty($last_order))
		return null;
		
	// check if logged in customer's order
	$order_check = xtc_db_fetch_array(
		xtc_db_query("SELECT customers_id
                      FROM ".TABLE_ORDERS."
                      WHERE orders_id=".$last_order));
	if (!isset($_SESSION['customer_id']) || isset($_SESSION['customer_id']) && 
		$_SESSION['customer_id'] != $order_check['customers_id'])
		return null;
	
	// get order data
	include (DIR_WS_CLASSES.'order.php');
	$order = new order($last_order);
	$data = $order->getTotalData($last_order);

	$subtotal = $tax = null;
	foreach($data['data'] as $d)
		if ($d['CLASS'] == 'ot_subtotal') $subtotal = $d['VALUE'];
		else if ($d['CLASS'] == 'ot_tax') $tax = $d['VALUE'];
	
	$discount = 'false';
	if ($_SESSION['customers_status']['customers_status_ot_discount_flag'] == '1')
		$discount = $_SESSION['customers_status']['customers_status_ot_discount'];
        
	return getPiwikProductItemsCode($order->products).
			' _paq.push([\'trackEcommerceOrder\',
				"'.$last_order.'",
				'.$data['total'].',
				'.$subtotal.',
				'.$tax.',
				'.$data['shipping'].',				
				'.$discount.']);';
}
