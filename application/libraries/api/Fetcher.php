<?php


/*
这是一座垃圾山。

请小心。

——一位不愿透露姓名的程序员
*/

defined('BASEPATH') OR exit('No Access!');


class Fetcher {
	
	private static $APPID = 'wxc91f8d9aebfa76c9';
	private static $APPSECRET = '2d7cffcbc90931212a75f9c304173327';
	private static $GETLISTLIMIT = 200;
	private static $PASSWD = 'insidePKU2012';
	private static $CACHE_TIMEOUT = 10*60*60; // cache expires in 10 hours (automatically)
	
	private static $ACCESS = array('refreshCache', 'articleList', 'materialCount', 'article',
'articleListBrief', 'articleBrief', 'image', 'categories', 'token', 'checkCache', 'debug', 'cover');

    private $current_param;
	
    private function urlsafe_b64encode ($string) {
        $data = base64_encode($string);
        $data = str_replace(array('+', '/', '='), array('-', '_', ''), $data);
        return $data;
	}
    private function urlsafe_b64decode($string) {
        $data = str_replace(array('-','_'),array('+','/'),$string);
        $mod4 = strlen($data) % 4;
        if ($mod4) {
            $data .= substr('====', $mod4);
        }
        return base64_decode($data);
    }

	public function __construct () {
        $this->CI = &get_instance(); // 获取加载该library类的CI资源
		$this->CI->load->driver('cache', array('adapter' => $this->CI->config->item('cache_driver'), 'key_prefix' => 'api.mobile.'));
		$this->current_param = array();
	}
	
	public function token () {
		echo($this->getToken());
	}

	public function run_command ($params) {
		$this->loadParams($params);

		if($this->param('key') == FALSE || $this->param('key') != self::$PASSWD) {
			return ('Key error, your key:'.$this->param('key'));
		}
		$method_name = $this->param('method');
		if($method_name == FALSE || !method_exists($this, $method_name)) {
			return ('Method not exists:'.$method_name);
		}
		if(!in_array($method_name, self::$ACCESS)) {
			return ('Method not allowed:'.$method_name);
		}
		return json_encode($this->{$method_name}());
	}
	protected function loadParams ($args) {
		$n_args = array();
		foreach($args as $key=>$value) {
			if(strpos($key, 'encoded_') === 0) {
				$n_args[substr($key, strlen('encoded_'))] = $this->urlsafe_b64decode($value);
			} else {
				$n_args[$key] = $value;
			}
		}
		$this->current_param = $n_args;
    }
	/*
		返回一个有效的微信API token
		force: 是否强制刷新token
	*/
	protected function getToken ($force = false) {
		$tok = $this->CI->cache->get('wechat-token');
		if($tok == FALSE || $force) { // 缓存已经过期 或者强制刷新token
			$appid = self::$APPID;
			$appsecret = self::$APPSECRET;
			$url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appid}&secret={$appsecret}";
			$result = $this->curl_get_contents($url);
			$return_data = json_decode($result, TRUE);
			# var_dump($result);
			if(array_key_exists('errcode', $return_data)) {
				throw new Exception('[api.mobile] token fetching error:'.$return_data); 
				return FALSE;
			}
			else{
				$access_token = $return_data['access_token'];
				$this->CI->cache->save('wechat-token', $access_token, $return_data['expires_in'] - 60);
			}
		}
		return $tok;
	}
	protected function debug () {
		/*$list = $this->CI->cache->get('articleList');
		$excerpt = array();
		foreach($list as $single) {
			foreach($single->content->news_item as $key=>&$value)
				unset($value->content);
			array_push($excerpt, $single);
		}
		$this->CI->cache->save('excerpt', $excerpt, self::$CACHE_TIMEOUT);*/
		eval($this->param('command'));
		return 'Yes!';
	}
	// 获取API参数, 
	protected function param ($pname) {
		if(!isset($this->current_param[$pname])) return FALSE;
		return $this->current_param[$pname];
	}
	/*
		从微信获取materialCount
	*/
	public function materialCountFull () {
		return $this->curl_get_contents("https://api.weixin.qq.com/cgi-bin/material/get_materialcount?access_token={$this->getToken()}");
	}
	
	public function materialListFull ($ncnt, $type) {
		$ret = array();
		for($cur_offset = 0; $cur_offset < $ncnt && $cur_offset <= 500 ; $cur_offset += 20) {
			$post = json_encode(array(
				'type' => $type,
				'offset' => $cur_offset,
				'count' => 20
				));
			$cur_data = $this->curl_post(
				"https://api.weixin.qq.com/cgi-bin/material/batchget_material?access_token={$this->getToken()}",
				$post
				);
			$cur_data = json_decode($cur_data);
			$seg_cnt = $cur_data->item_count;
			for($i = 0; $i<$seg_cnt; $i++) {
				array_push($ret, $cur_data->item[$i]);
			}
		}
		return $ret;
	}

	protected function materialFull ($media_id) {
		var_dump($media_id);
		$cur_data = $this->curl_post(
			"https://api.weixin.qq.com/cgi-bin/material/get_material?access_token={$this->getToken()}",
			json_encode(array(
				'media_id' => $media_id
			)));
		return $cur_data;
	}

	/*
		刷新列表缓存
	*/

	protected function refreshCache () {
		$this->CI->cache->save('materialCount', json_decode($this->materialCountFull()), self::$CACHE_TIMEOUT);
		# var_dump($this->CI->cache->get('materialCount'));
		# 缓存新闻内容
		$ncount = $this->CI->cache->get('materialCount')->news_count;
		$list = $this->materialListFull($ncount, 'news');
		$this->CI->cache->save('article.list', $list, self::$CACHE_TIMEOUT);
		$excerpt = array();
		foreach($list as &$single)
			$this->CI->cache->save('article.'.($single->media_id), $single, self::$CACHE_TIMEOUT);
		# 整理分类标签
		/*$catg = array();
		$catg_name = array();
		foreach($list as $single) {
			$cur_title = $single->content->news_item[0]->title;
			if(strpos($title, '此间·') === FALSE) continue ;
			array_push($catg_name, );

		}*/
		# 缓存摘要列表
		foreach($list as $single) {
			foreach($single->content->news_item as $key=>&$value)
				unset($value->content);
			array_push($excerpt, $single);
		}
		$this->CI->cache->save('excerpt', $excerpt, self::$CACHE_TIMEOUT);
		# 缓存图片素材
		$ncount = $this->CI->cache->get('materialCount')->image_count;
		$list = $this->materialListFull($ncount, 'image');
		$n_list = array();
		foreach($list as $single) 
			$this->CI->cache->save('image.'.($single->media_id), $single, self::$CACHE_TIMEOUT);
		# 打个标记
		$this->CI->cache->save('cached', TRUE, self::$CACHE_TIMEOUT);
		return array('msg' => 'Success');
	}

	public function requestRawList () {
		return $this->CI->cache->get('article.list');
	}

	/*
		检查cache是否过期
		记得在访问cache前调用此函数，否然将无法判断是否是cache过期还是给api传入的参数指向的资源不存在
	*/
	protected function checkCache () {
		return $this->CI->cache->get('cached') !== FALSE;
	}
	
	protected function formatTitle ($str) {
		$intr = str_replace('丨', '|', $str);
		$intr = str_replace('｜', '|', $intr);
		$intr = str_replace('|', '|', $intr);
		$tuple = explode('|', $intr, 2);
		if(count($tuple) != 2) return FALSE;
		$tuple[0] = ltrim($tuple[0]);
		$tuple[1] = ltrim($tuple[1]);
		return $tuple;
	}
	/*
		返回资源总数
		json请查阅https://developers.weixin.qq.com/doc/offiaccount/Asset_Management/
	*/
	protected function materialCount () {
		if(!$this->checkCache()) $this->refreshCache();
		$cached = $this->CI->cache->get('materialCount');
		if($cached == FALSE) {
			return array('errmsg' => 'Fail(not cached)');
		}
		return $cached;
	}
	/*
		返回最近文章
		注意，此处offset从0开始。
		如果offset为负数，或者count为负数，或者出界，参见PHP手册中array_slice函数
		返回的json格式请查阅https://developers.weixin.qq.com/doc/offiaccount/Asset_Management/Get_materials_list.html
	*/
	protected function articleList () {
		if(!($this->checkCache())) $this->refreshCache();
		$cached = $this->CI->cache->get('excerpt');
		if($cached == FALSE) {
			return array('errmsg' => 'Fail(not cached)');
		}
		$offset = 0;
		$count = 10;
		if($this->param('offset') != FALSE) $offset = (int)$this->param('offset');
		if($this->param('count') != FALSE) $count = (int)$this->param('count');
		return array_slice($cached, $offset, $count);
	}
	
	/*
		返回单个article
		参数是media_id
		media_id查阅https://developers.weixin.qq.com/doc/offiaccount/Asset_Management/Get_materials_list.html
	*/
	protected function article ($mid = FALSE) {
		if($mid === FALSE) $mid = $this->param('media_id');
		$extension = $this->param('path');
		if($mid === FALSE) return array('errmsg' => 'Fail(no param "media_id")');

		if(!$this->checkCache()) $this->refreshCache();
		$cached = $this->CI->cache->get('article.'.$mid);
		if($cached == FALSE) {
			$cached = json_decode($this->materialFull($mid));
			if(isset($cached->errcode)) return array('errmgs' => "API fail(probably media_id:[$mid] does not exist.\n err:".json_encode($cached));
			$this->CI->cache->save('article.'.$mid, $cached, self::$CACHE_TIMEOUT);
		}
		foreach($cached->content->news_item as &$subject)
			$subject->content = base64_encode($subject->content);
		if($extension != FALSE) $cached = $cached->{$extension};
		return $cached;
	}
	protected function image ($mid = FALSE) {
		if($mid === FALSE) $mid = $this->param('media_id');
		$extension = $this->param('path');
		if($mid === FALSE) return array('errmsg' => 'Fail(no param "media_id")');
		$cached = $this->CI->cache->get('image.'.$mid);
		if($cached == FALSE) {
			$cached = $this->materialFull($mid);
			if(isset($cached->errcode)) return array('errmsg' => "API fail(probably media_id:[$mid] does not exist.\n err:".json_encode($cached));
			$this->CI->cache->save('image.'.$mid, $cached, self::$CACHE_TIMEOUT);
		}
		# var_dump($cached);
		return $cached;
	}
	
	public function compile ($data) {
		$ret = new stdClass;
		$ret->content = $data->content;
		$tuple = $this->formatTitle($data->title);
		$ret->title = $tuple[0];
		$ret->subtitle = $tuple[1];
		$ret->author = $data->author;
		$ret->excerpt = $data->digest;
		$ret->category = 'none'; //TODO
		$ret->views = 0; //TODO
		$ret->cover_url = $data->thumb_url;
		$ret->source = $data->content_source_url;
		return $ret;
	}

	protected function articleBrief ($mid = FALSE) {
		$data = $this->article($mid)->content->news_item[0];
		$ret = new stdClass;
		$ret->content = $data->content;
		$tuple = $this->formatTitle($data->title);
		$ret->title = $tuple[0];
		$ret->subtitle = $tuple[1];
		$ret->author = $data->author;
		$ret->excerpt = $data->digest;
		$ret->category = ''; //TODO
		$ret->views = 0; //TODO
		$ret->cover_url = $data->thumb_url;
		$ret->source = $data->content_source_url;
		if($this->param('raw') == 'false' || $this->param('raw') === FALSE) {
			$ret->excerpt = base64_encode($ret->excerpt);
			# $ret->content = base64_encode($ret->content);
		}
		return $ret;
	}

	// title, cover_url, media_id, excerpt
	protected function articleListBrief () {
		if(!($this->checkCache())) $this->refreshCache();
		$cached = $this->CI->cache->get('excerpt');
		if($cached == FALSE) {
			return 'Fail(not cached)';
		}
		$offset = 0;
		$count = 10;
		if($this->param('offset') != FALSE) $offset = (int)$this->param('offset');
		if($this->param('count') != FALSE) $count = (int)$this->param('count');
		$data = array_slice($cached, $offset, $count);
		$n_data = array();
		foreach($data as $single) {
			$n_single = new stdClass;
			$tuple = $this->formatTitle($single->content->news_item[0]->title);
			if($tuple !== FALSE) {
				$n_single->title = $tuple[0];
				$n_single->subtitle = $tuple[1];
			} else {
				$n_single->title = $single->content->news_item[0]->title;
				$n_single->subtitle = '我吃柠檬!';
			}
			#var_dump($single->content->news_item);
			$n_single->cover_url = $single->content->news_item[0]->thumb_url; # $this->image($single->content->news_item[0]->thumb_media_id)->url;
			$n_single->media_id = $single->media_id;
			$n_single->excerpt = $single->content->news_item[0]->digest;
			$n_single->category = ''; // TODO

			$n_single->time = date('Y.m.d', $single->update_time);
			if($this->param('raw') == 'false' || $this->param('raw') == FALSE)
				$n_single->excerpt = base64_encode($n_single->excerpt);
			array_push($n_data, $n_single);
		}
		return $n_data;
	}

	protected function cover () {
		return "http://insidepku.cn/images/cover.jpg";
	}

	protected function categories () {
		return array("精选", "调查", "人物", "专题", "镜像", "特写", "未归类");
	}

	//辅助板块
	protected function rcurl_get_contents($durl){
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
	protected function rcurl_post ($curlHttp, $postdata) {
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
	//利用curl发送GET请求并获取response
	protected function curl_get_contents ($durl) {
		return $this->rcurl_post('http://insidepku.cn/access.php', 
			array('meth' => 'agent_get', 'encoded_url' => $this->urlsafe_b64encode($durl), 'passwd' => 'iwannapasswd!'));
	}
	protected function curl_post ($durl, $dat) {
		return $this->rcurl_post('http://insidepku.cn/access.php', 
			array('meth' => 'agent_post', 'encoded_url' => $this->urlsafe_b64encode($durl), 
			'encoded_postdata' => $this->urlsafe_b64encode($dat), 'passwd' => 'iwannapasswd!'));
	}
}