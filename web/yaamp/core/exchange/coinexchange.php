<?php

// http://coinexchangeio.github.io/slate/

function coinexchange_api_query($method, $params='')
{
	$exchange = 'coinexchange';

	$mt = explode(' ', microtime());
	$nonce = $mt[1].substr($mt[0], 2, 6);

	$uri = "https://www.coinexchange.io/api/v1/$method?nonce=$nonce";
	if (!empty($params)) $uri .= '&'.$params;

	$ch = curl_init($uri);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSLVERSION, 1 /*CURL_SSLVERSION_TLSv1*/);
	curl_setopt($ch, CURLOPT_SSL_SESSIONID_CACHE, 0);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, 20);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; CoinExchange API PHP client; '.php_uname('s').'; PHP/'.phpversion().')');
	curl_setopt($ch, CURLOPT_ENCODING , '');

	$data = curl_exec($ch);
	$obj = json_decode($data);

	if(!is_object($obj)) {
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		debuglog("$exchange: $method failed ($status) ".strip_data($data).' '.curl_error($ch));
	}

	curl_close($ch);

	return $obj;
}
