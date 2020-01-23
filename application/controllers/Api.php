	<?php

	/*
	api/mobile
	api/update
	api/media
	*/

	defined('BASEPATH') OR exit('No Access!');

	class Api extends CI_Controller {

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

		private static $invoke_pass = 'insidePKU2012';

		public function __construct () {
			parent::__construct();
			$this->load->library('api/fetcher');
			$this->load->library('utils/media');
		}
		private function param ($name) {
			if(isset($this->params[$name])) return $this->params[$name];
			return FALSE;
		}
		private function set_params ($args) {
			$this->params = $args;
		}
		private function prepare () {
			if($_SERVER['REQUEST_METHOD'] == 'GET') $args = $_GET;
			else $args = $_POST;
			$n_args = array();
			foreach($args as $key=>$value) {
				if(strpos($key, 'encoded_') === 0) {
					$n_args[substr($key, strlen('encoded_'))] = $this->urlsafe_b64decode($value);
				} else $n_args[$key] = $value;
			}
			$this->set_params($n_args);
			if($this->param('key') !== self::$invoke_pass) {
				$this->output->set_output('Wrong pass, yours:'.$this->param('key'));
				return FALSE;
			}
			return TRUE;
		}

		public function lasting () {
			if(!$this->prepare()) return ;
			$meth = $this->param('method');
			$output = 'Method not found:'.$meth;
			if($meth == 'get' || $meth == 'save') {
				if($this->param('id') === FALSE) {
					$output = array('msg' => 'give me ur id!');
					goto end;
				}
				$id = $this->param('id');
				if($meth == 'get') {
					$output = $this->lasting->get($id);
				} else {
					$this->media->lasting->save($id, $this->param('value'));
					$output = 'success!';
				}
			} else {
				if($this->media->lasting->exists($meth)) {
					# var_dump($meth);
					$output = $this->media->lasting->get($meth);
					
				} else $output = 'id not found:'.$meth;
			}
			/*switch ($meth) {
				case 'categories':
					$output = array(
	"特别","调查","人物","专题","镜像","对谈","报告","此外","玩味","特写","none");
					break ;
				default: 
			}*/
			end:;
			$this->output->set_output($output); // 这里没有使用json编码了。
		}

		public function media () {
			if(!$this->prepare()) return ;
			$meth = $this->param('method');
			$output = new stdClass;
			$output->msg = 'Method not found:'.$meth;
			switch($meth) {
				case 'attributes':
					$mid = $this->param('media_id');
					$raw = $this->param('raw');
					if($raw === 'true') $raw = TRUE;
					else $raw = FALSE;
					if(!$this->media->exists($mid)) {
						$output = array('msg'=>'media not found:'.$mid);
						break ;
					}
					$data = $this->media->attributes($mid);
					if(!$raw) 
						$data->excerpt = base64_encode($data->excerpt);
					$output = $data;
					break ;
				case 'article':
					$mid = $this->param('media_id');
					$raw = $this->param('raw');
					if($raw === 'true') $raw = TRUE;
					else $raw = FALSE;
					if($mid === FALSE || !$this->media->exists($mid)) {
						$output = array('msg'=>'media not found:'.$mid);
						break ;
					}
					$data = $this->media->article($mid);
					$this->media->increase($mid); // 统计访问量
					if($raw === FALSE) {
						$data->excerpt = base64_encode($data->excerpt);
						$data->content = base64_encode($data->content);
					}
					$output = $data;
					break ;
				case 'recent':
					$count = 10;
					$offset = 0;
					$category = 'none';
					$raw = $this->param('raw');
					if($raw === FALSE || $raw === 'true') $raw = TRUE;
					else $raw = FALSE;
					if($this->param('count')) $count = $this->param('count');
					if($this->param('offset')) $offset = $this->param('offset');
					if($this->param('category')) $category = $this->param('category');
					if($category == 'none') {
						$data = $this->media->recent_articles($count, $offset);
					} else {
						$data = $this->media->recent_articles_cat($count, $offset, $category);
					}
					if($raw === FALSE) {
						foreach($data as &$single) {
							$single->excerpt = base64_encode($single->excerpt);
						}
					}
					$output = $data;
					break ;
				case 'search':
					$seg = $this->param('keyword');
					if($seg === FALSE) {
						$output = array('msg'=>'provide keywords plz');
						break ;
					}
					$count = 10;
					$offset = 0;
					$category = 'none';
					if($this->param('count')) $count = $this->param('count');
					if($this->param('offset')) $offset = $this->param('offset');
					if($this->param('category')) $category = $this->param('category');
					$output = new stdClass;
					if($category == 'none') $output->content = $this->media->search($seg, $count, $offset);
					else $output->content = $this->media->search_cat($seg, $count, $offset, $category);
					if($category == 'none')	$output->article_related_count = $this->media->search_count($seg);
					else $output->article_related_count = $this->media->search_count_cat($seg, $category);
					break ;
				default:
			}
			$this->output->set_output(json_encode($output));
		}
		
		public function update () {
			if(!$this->prepare()) return ;
			$meth = 'insert';
			if($this->param('method') != FALSE) 
				$meth = $this->param('method');
			
			function classify ($subtitle) {
				$short = explode('·', $subtitle);
				if(count($short) != 2) return 'none';
				$short = $short[1];
				if(strlen($short) <= 6) return $short;
				return 'none';
			}
			
			switch($meth) {
				case 'insert':
					$counts = json_decode($this->fetcher->materialCountFull());
					$articles = $this->fetcher->requestRawList();
					$ins_count = 0;
					$cur_cat = json_decode($this->media->lasting->get('categories'));
					foreach($articles as $single) {
						if(!$this->media->exists($single->media_id)) {
							$intr = $this->fetcher->compile($single->content->news_item[0]);
							$intr->media_id = $single->media_id;
							$intr->time = date('Y.m.d', $single->update_time);
							if($intr->title == '') continue ;
							if(strpos($intr->title, '(') === 0) continue ;//过滤掉奇怪的文章
							if(strpos($intr->title, '（') === 0) continue ;
							$intr->category = classify($intr->subtitle);
							$intr = $this->media->formated($intr);
							$this->media->insert_article($intr);
							array_push($cur_cat, $intr->category);
							$ins_count ++;
						}
					}
					$cur_cat = array_values(array_unique($cur_cat));
					$this->media->lasting->save('categories', json_encode($cur_cat));
					$this->output->set_output(''.$ins_count.' items added.');
					break ;
				case 'restore':
					$this->media->reset();
					$this->media->lasting->save('categories', json_encode(array())); // 缺省值
					$this->media->lasting->save('cover', '"http://insidepku.cn/images/cover.jpg"'); // 缺省值
					$counts = json_decode($this->fetcher->materialCountFull());
					$articles = $this->fetcher->requestRawList(); // 卧槽，我忘记这个函数是拿来干嘛的了，最好别乱动
					$ins_count = 0;
					$cur_cat = json_decode($this->media->lasting->get('categories'));
					foreach($articles as $single) {
						if(!$this->media->exists($single->media_id)) {
							$intr = $this->fetcher->compile($single->content->news_item[0]);
							$intr->media_id = $single->media_id;
							$intr->time = date('Y.m.d', $single->update_time);
							if($intr->title == '') continue ;
							if(strpos($intr->title, '(') === 0) continue ;//过滤掉奇怪的文章
							if(strpos($intr->title, '（') === 0) continue ;
							$intr->category = classify($intr->subtitle);
							$intr = $this->media->formated($intr);
							$this->media->insert_article($intr);
							array_push($cur_cat, $intr->category);
							$ins_count ++;
						}
					}
					$cur_cat = array_values(array_unique($cur_cat));
					$this->media->lasting->save('categories', json_encode($cur_cat));
					$this->output->set_output('Restore, '.$ins_count.' items added.');
					break ;
				case 'increase':
					$mid = $this->param('media_id');
					if($mid === FALSE) {
						$this->output->set_output('Give your media_id plz.');
					} else {
						if(!$this->media->exists($mid)) {
							$this->output->set_output('invalid media id:'.$mid);
						} else {
							$this->media->increase($mid);
						}
					}
					break ;
				case 'modify';
					$this->output->set_output('Ive not finished this yet qwq (Hineven)');
					break ;
				case 'reformat':
					# $this->media->reformat();
					$this->media->reformat();
					$this->output->set_output('success qwq!');
					break ;
				#case 'debug':
				#	$this->output->set_output(json_decode($this->media->formated($this->param('media_id')))->content);
				default:
					$this->output->set_output('method not found:'.$meth);
			}
		}

		// Deprived
		public function mobile () {
			#$this->output->set_output(print_r($_GET));
			$args = array();
			if($_SERVER['REQUEST_METHOD'] == 'GET') $args = $_GET;
			else $args = $_POST;
			$this->output->set_output($this->fetcher->run_command($args));
		}
		# App测试调用函数
		public function testdata () {
			$data = array('中文1', '中文二', '郭俊毅'/*array('item1'=>'JeremyGuo', 'item2'=>'Is', 'item3'=>'Dickey')*/, '中文测试', '很吊');
			$this->output->set_output(json_encode($data));
		}
	}
