<?php

/*
用于管理媒体资源的library

负责接入数据库。
*/
namespace {
    defined('BASEPATH') OR exit('No Access!');
}

namespace Media {
/*
这是一座受到保护的垃圾山。
实际上是Media要使用到的工具函数库。
*/
class Lasting  {
    public function __construct ($ci) {
        $this->CI = &$ci;
    }
    public function get ($id) {
        $result = $this->CI->db->query('SELECT content FROM lasting WHERE
            id = '.$this->CI->db->escape($id)
        );
        # var_dump($result->result()[0]);
        return ($result->result()[0])->content;
    }
    public function exists ($id) {
        $result = $this->CI->db->query('SELECT content FROM lasting WHERE
            id = '.$this->CI->db->escape($id).
        '');
        return count($result->result()) != 0;
    }
    public function save ($id, $value) {
        if($this->exists($id)) {
            $this->CI->db->query('UPDATE lasting SET content = '.
                $this->CI->db->escape($value).
                ' WHERE id = '.
                $this->CI->db->escape($id)
            );
        } else {
            $this->CI->db->query('INSERT INTO lasting VALUES ('.
                $this->CI->db->escape($id).','.
                $this->CI->db->escape($value).')'
            );
        }
    }
}
/*
$buffer = array();

function clear_document (&$element) {
    if($element->getName() == 'a' || isset($element->children()['a']))
        return TRUE;
    $flag = FALSE;
    $buffer = array();
    foreach($element.children() as $ind=>&$val) {
        if($val instanceof SimpleXMLElement) {
            if(clear_document($element)) {
                $flag = TRUE;
                $buffer[] = $ind;
            }
        } else if(is_array($val)) {
            foreach($val as $subind=>&$subval) {
                if($subval instanceof SimpleXMLElement && clear_document($subval)) {
                    $flag = TRUE;
                    $buffer[] = array($ind, $subind);
                }
            }
        }
    }
    if(isset($element->attributes()['class']) && $element->attributes()['class'] == 'rich_media_content') {
        foreach(array_reverse($buffer) as $value) { // guarantee position data not being modified
            if(is_array($value)) {
                unset($element->{$value[0]}->value[1]);
            } else unset($element->{$value});
        }
        return FALSE;
    }
    return TRUE;
}
*/
function pulge ($node) {
    $lst = $node;
    # var_dump($node->nodeName);
    while($node->nodeName != 'body' && $node != FALSE) {
        $lst = $node;
        $node = $node->parentNode;
    }
    if($node != FALSE) $node->removeChild($lst);
}
function stw_divisor($chr) {
    /*
        别乱动。
        没错，编辑们使用的'|'就是有三种。
    */
    return strpos($chr, '|') === 0
        || strpos($chr, '丨') === 0
        || strpos($chr, '｜') === 0;
}
function dfs ($node, $fa = 0) {
    if($node->childNodes == FALSE) {
        if($node->textContent === '责任编辑') {
            return 1;
        }
        if($fa === 1 && stw_divisor($node->textContent) == TRUE) {
            return 2;
        }
        return 0;
    }
    $flag = 0;
    $to_remove = array();
    for($i = 0; $i<$node->childNodes->length; $i++) {
        if($flag == 2) $to_remove[] = $node->childNodes->item($i);
        $flag = max(dfs($node->childNodes->item($i), $flag), $flag);
    }
    foreach($to_remove as $single) {
        $node->removeChild($single);
    }
    return $flag;
}
function force_format ($str) {
    $doc = new \DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">'.$str, 
        # LIBXML_HTML_NOIMPLIED | # Make sure no extra BODY
        # LIBXML_HTML_NODEFDTD |  # or DOCTYPE is created
        LIBXML_NOERROR |        # Suppress any errors
        LIBXML_NOWARNING        # or warnings about prefixes.
    );
    $alist = $doc->getElementsByTagName('a');
    for($i = 0; $i<$alist->length; $i++)
        pulge($alist->item($i));
    $bd = $doc->getElementsByTagName('body')->item(0);
    $flag = 0;
    $to_remove = array();
    try {
        for($i = 0; $i<$bd->childNodes->length; $i++) {
            if($flag == 2) $to_remove[] = $bd->childNodes->item($i);
            if($flag != 2) $flag = max(dfs($bd->childNodes->item($i), $flag), $flag);
        }
    } catch(\Exception $e) {
        var_dump(bd);
    }
    foreach($to_remove as $element) $bd->removeChild($element);
    $text = $doc->saveHTML();
    $brk_pos = strpos($text, "\n");
    $ret = substr($text, $brk_pos+1);
    $ret = \str_replace('<html>', '', $ret);
    $ret = \str_replace('</html>', '', $ret);
    $ret = \str_replace('<body>', '', $ret);
    $ret = \str_replace('</body>', '', $ret);
    return $ret;
}

function format_json ($json) {
    $json->content = force_format($json->content);
    return $json;
}
//unicode processing
function char_len (&$str, $pos) {
    $num = \ord($str[$pos]);
    for($fir = 0; ((1<<(7-$fir))&$num) != 0; $fir++) ;
    return $fir ? $fir : $fir+1;
}
function next_pos (&$str, $pos, $len) {
    for($i = 0; $i<$len; $i++)
        $pos += char_len($str, $pos);
    return $pos;
}
function transform_compat ($str) {
    $long_ord = 0;
    $seg = 0;
    $ret = '000';
    $slen = strlen($str);
    for($i = 0; $i<$slen; $i++, $seg++) {
        $long_ord = 256*$long_ord+ord($str[$i]);
        if($seg >= 4) {
            $ret .= 'p'.$long_ord;
            $long_ord = 0;
            $seg = 0;
        }
    }
    if($seg) $ret .= 'p'.$long_ord;
    return $ret;
}
function make_fulltext ($str, $mx) {
    $ret = '';
    $len_w = 0;
    $len = strlen($str);
    $cur_pos = 0;
    while($cur_pos < $len) {
        $len_w ++;
        $cur_pos += char_len($str, $cur_pos);
    }
    # var_dump($len_w);
    for($cur_len = 1; $cur_len <= \min($mx, $len_w); $cur_len++) {
        for($i = 0, $p = 0; $i<$len_w-$cur_len+1; $i++, $p += char_len($str, $p)) {
            $ret .= ' '.transform_compat(substr($str, $p, next_pos($str, $p, $cur_len)-$p));
        }
    }
    # var_dump($ret);
    return $ret;
}
} // end of namespace Media

namespace {
class Media {
    public function __construct () {
        $this->CI = &get_instance();
        $this->CI->load->database();
        $this->lasting = new Media\Lasting($this->CI);
    }

    public function initialize () {
        $db = &$this->CI->db;
        $db->query('CREATE TABLE IF NOT EXISTS articles_attr (
            media_id CHAR(50),
            title CHAR(250),
            subtitle CHAR(100),
            excerpt VARCHAR(500),
            time DATE,
            cover_url CHAR(250),
            author CHAR(50),
            category CHAR(50),
            source CHAR(250),
            views INT NOT NULL DEFAULT 0,
            keywords VARCHAR(3000),
            PRIMARY KEY (media_id),
            FULLTEXT(keywords)
        ) DEFAULT charset=utf8 engine=myisam');
        $db->query('CREATE TABLE IF NOT EXISTS articles (
            media_id CHAR(50),
            content MEDIUMTEXT,
            PRIMARY KEY (media_id)
        ) DEFAULT charset=utf8');
        $db->query('CREATE TABLE IF NOT EXISTS lasting (
            id CHAR(100),
            content VARCHAR(65536),
            PRIMARY KEY (id)
        ) DEFAULT charset=utf8');
    }
    public function reset () {
        $this->CI->db->query('DROP TABLE IF EXISTS articles');
        $this->CI->db->query('DROP TABLE IF EXISTS articles_attr');
        $this->CI->db->query('DROP TABLE IF EXISTS lasting');
        $this->initialize();
    }
    public function insert_article ($data) {
        $this->CI->db->query(
            "INSERT INTO articles_attr VALUES (".
            $this->CI->db->escape($data->media_id).",".
            $this->CI->db->escape($data->title).",".
            $this->CI->db->escape($data->subtitle).",".
            $this->CI->db->escape($data->excerpt).",".
            $this->CI->db->escape($data->time).",".
            $this->CI->db->escape($data->cover_url).",".
            $this->CI->db->escape($data->author).",".
            $this->CI->db->escape($data->category).",".
            $this->CI->db->escape($data->source).",".
            $this->CI->db->escape($data->views).",".
            $this->CI->db->escape(Media\make_fulltext($data->title, 15)).")"
        );
        $this->CI->db->query(
            "INSERT INTO articles VALUES (".
            $this->CI->db->escape($data->media_id).",".
            $this->CI->db->escape($data->content).")"
        );
    }
    public function update_article ($data) {
        $this->CI->db->query(
            "UPDATE articles_attr SET ".
            "title = ".$this->CI->db->escape($data->title).",".
            "subtitle = ".$this->CI->db->escape($data->subtitle).",".
            "excerpt = ".$this->CI->db->escape($data->excerpt).",".
            "time = ".$this->CI->db->escape($data->time).",".
            "cover_url = ".$this->CI->db->escape($data->cover_url).",".
            "author = ".$this->CI->db->escape($data->author).",".
            "category = ".$this->CI->db->escape($data->category).",".
            "source = ".$this->CI->db->escape($data->source).",".
            "views = ".$this->CI->db->escape($data->views).",".
            "keywords = ".$this->CI->db->escape(Media\make_fulltext($data->title, 15)).
            " WHERE media_id = ".$this->CI->db->escape($data->media_id)
        );
        $this->CI->db->query(
            "UPDATE articles SET ".
            "content = ".$this->CI->db->escape($data->content).
            " WHERE media_id = ".$this->CI->db->escape($data->media_id)
        );
    }
    public function update_article_content ($data) {
        $this->CI->db->query(
            "UPDATE articles SET ".
            "content = ".$this->CI->db->escape($data->content).
            " WHERE media_id = ".$this->CI->db->escape($data->media_id)
        );
    }
    public function increase ($media_id) {
        # if($media_id, 'string') return FALSE;
        $this->CI->db->query('UPDATE articles_attr SET views = views+1
        WHERE media_id = '.$this->CI->db->escape($media_id));
        return TRUE;
    }
    public function attributes ($media_id) {
        $ret = $this->CI->db->query("SELECT * FROM articles_attr 
            WHERE media_id = ".$this->CI->db->escape($media_id)
        );
        return $ret->result()[0];
    }
    public function single ($media_id, $attr_name) {
         $ret = $this->CI->db->query("SELECT ".$this->CI->db->escape($attr_name).
            " FROM articles_attr WHERE media_id = ".
            $this->CI->db->escape($media_id)
        );
        return $ret->result()[0]->{$attr_name};
    }
    public function article ($media_id) {
        $data = $this->CI->db->query(
            'SELECT * FROM articles WHERE BINARY media_id = '.
            $this->CI->db->escape($media_id)
        );
        $ret = $this->attributes($media_id);
        unset($ret->keywords);
        $data = $data->result()[0];
        $ret->content = $data->content;
        return $ret;
    }
    public function exists ($media_id) {
        return count($this->CI->db->query(
            'SELECT media_id FROM articles WHERE BINARY media_id = '
            .$this->CI->db->escape($media_id)
        )->result()) != 0;
    }
    public function recent_articles_cat ($count, $offset, $category) {
        $result = $this->CI->db->query(
            'SELECT * FROM articles_attr WHERE category = '.
            $this->CI->db->escape($category).
            ' ORDER BY time DESC, title ASC'.
            ' LIMIT '.$offset.','.$count
        );
        $result = $result->result();
        foreach($result as &$single) $single->keywords = NULL;
        return $result;
    }
    public function recent_articles ($count, $offset) {
        $result = $this->CI->db->query(
            'SELECT * FROM articles_attr '.
            ' ORDER BY time DESC, title ASC'.
            ' LIMIT '.$offset.','.$count
        );
        $result = $result->result();
        foreach($result as &$single) $single->keywords = NULL;
        return $result;
    }
    public function search_cat ($seg, $count, $offset, $category) {
        $seg = Media\transform_compat($seg);
        $result = $this->CI->db->query(
            'SELECT * FROM articles_attr WHERE category = '.
            $this->CI->db->escape($category).
            ' AND Match(keywords) Against('.
            $this->CI->db->escape($seg).
            ') ORDER BY time DESC, title ASC'.
            ' LIMIT '.$offset.','.$count
        );
        $result = $result->result();
        foreach($result as &$single) $single->keywords = NULL;
        return $result;
    }
    public function search ($seg, $count, $offset) {
        $seg = Media\transform_compat($seg);
        $result = $this->CI->db->query(
            'SELECT * FROM articles_attr WHERE'.
            ' Match(keywords) Against('.
            $this->CI->db->escape($seg).
            ') ORDER BY time DESC, title ASC'.
            ' LIMIT '.$offset.','.$count
        );
        $result = $result->result();
        foreach($result as &$single) $single->keywords = NULL;
        return $result;
    }
    public function formated ($data) {
        return Media\format_json($data);
    }
    public function reformat () {
        $result = $this->CI->db->query('SELECT * FROM articles');
        $res_array = $result->result();
        foreach($res_array as $key=>&$value) $value = Media\format_json($value);
        foreach($res_array as $value) $this->update_article_content($value);
    }
    public function search_count ($seg) {
        $seg = Media\transform_compat($seg);
        $result = $this->CI->db->query(
            'SELECT COUNT(*) AS CNT FROM articles_attr WHERE'.
            ' Match(keywords) Against('.
            $this->CI->db->escape($seg).
            ')'
        );
        $result = $result->result()[0]->CNT;
        return $result;
    }
    public function search_count_cat ($seg, $cat) {
        $seg = Media\transform_compat($seg);
        $result = $this->CI->db->query(
            'SELECT COUNT(*) AS CNT FROM articles_attr WHERE category = '.
            $this->CI->db->escape($category).
            ' AND Match(keywords) Against('.
            $this->CI->db->escape($seg).
            ')'
        );
        $result = $result->result()[0]->CNT;
        return $result;
    }
}
}
