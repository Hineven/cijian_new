<?php

/*

这是一个危险的脚本

使用服务器访问目标并返回。

*/

	//辅助板块



	//利用curl发送GET请求并获取response

	function curl_get_contents($durl){

		$ch = curl_init();

	    curl_setopt($ch, CURLOPT_URL, $durl);

        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        curl_setopt($ch, CURLOPT_USERAGENT, '_USERAGENT_');

        curl_setopt($ch, CURLOPT_REFERER, '_REFERER_');

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($ch);

        curl_close($ch);

	   	return $result;

	}



	//利用curl发送POST请求并获取response

	function curl_post($curlHttp, $postdata){

		$curl = curl_init();

	    curl_setopt($curl, CURLOPT_URL, $curlHttp);

	    curl_setopt($curl, CURLOPT_HEADER, false);

	    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); //不显示

	    curl_setopt($curl, CURLOPT_TIMEOUT, 60); //60秒，超时

	    curl_setopt($curl, CURLOPT_POST, true);

	    curl_setopt($curl, CURLOPT_POSTFIELDS, $postdata);

	    $data = curl_exec($curl);

	    curl_close($curl);

	    return $data;

	}

	

	// var_dump($_POST);

	

	$meth = $_POST['request'];

	if($meth == 'get') {

		echo(curl_get_contents($_POST['url']));

	} else if($meth == 'post') {
		echo('lol!');
		var_dump($_POST['postdata']);
		echo(curl_get_contents($_POST['url'], $_POST['postdata']));

	}