<?php

function generate_word()
{
    // Get a random word
    $nb = rand(3, 10);
    $w = '';
    for ($i = 1; $i <= $nb; $i++)
        $w .= chr(rand(ord('a'), ord('z')));
    return $w;
}

function region_number ($num, $min, $min_limit, $max, $max_limit)
{
    return $num < $min ? $min_limit : ($num > $max ? $max_limit : $num);
}

function get_diff_time ($start_time, $end_time, array $modify = null)
{
	if (!$start_time || !$end_time) {
		return '';
	}
	$start_time = new \DateTime(date('Y-m-d H:i:s', $start_time));
	$end_time   = new \DateTime(date('Y-m-d H:i:s', $end_time));
	$interval   = $end_time->diff($start_time);
	$modify = $modify ? $modify : ['y'=>'年', 'm'=>'月', 'd'=>'天', 'h'=>'小时', 'i'=>'分', 's'=>'秒'];
	$str = [];
	foreach ($modify as $k => $v) {
		if (isset($interval->{$k}) && $interval->{$k}) {
			$str[] = $interval->{$k} . $v;
		}
	}
	return implode('', $str);
}

function var_exists ($obj, $var, $default = '')
{
    return isset($obj[$var]) ? $obj[$var] : $default;
}

function concat (...$args)
{
    if (isset($args[0])) {
        if (is_array($args[0])) {
            return implode('', $args[0]);
        } else {
            return implode('', $args);
        }
    }
    return '';
}

function byte_convert ($byte)
{
    if ($byte < 1024) {
        return $byte . 'byte';
    } elseif (($size = round($byte / 1024, 2)) < 1024) {
        return $size . 'KB';
    } elseif (($size = round($byte / (1024 * 1024), 2)) < 1024) {
        return $size . 'MB';
    } else {
        return round($byte / (1024 * 1024 * 1024), 2) . 'GB';
    }
}

function round_dollar ($fen, $suffix = false)
{
    if (!$fen) {
        return 0;
    }
    $fen /= 100;
    return $suffix ? sprintf("%01.2f", $fen) : round($fen, 2);
}

function get_real_val (...$args)
{
    if (is_array($args[0])) {
        foreach ($args[0] as $v) {
            if ($v) {
                return $v;
            }
        }
    } else {
        foreach ($args as $v) {
            if ($v) {
                return $v;
            }
        }
    }
    return '';
}

function import_library ($path)
{
    $path = trim($path, DIRECTORY_SEPARATOR) . '.php';
    require_once implode(DIRECTORY_SEPARATOR, [
        APPLICATION_PATH, 'application', DIRECTORY_SEPARATOR, 'library', $path
    ]);
}

function getSysConfig ($key = null, $target = 'config')
{
    static $sys_config = [];
    if (!isset($sys_config[$target])) {
        $sys_config[$target] = include APPLICATION_PATH . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . $target . '.php';
    }
    if (!isset($key)) {
        return $sys_config[$target];
    }
    return isset($sys_config[$target][$key]) ? $sys_config[$target][$key] : null;
}

function getConfig ($app = null, $name = null, $default = null)
{
    if (false === F('config')) {
        $result = \app\library\DB::getInstance()->table('__tablepre__config')->field('app,name,value,type,min,max')->select();
        $config = array();
        foreach ($result as $k => $v) {
            if ($v['type'] == 'textarea') {
                $v['value'] = htmlspecialchars_decode($v['value'], ENT_QUOTES);
            } else if ($v['type'] == 'number') {
                $v['value'] = intval($v['value']);
                if (isset($v['min'])) {
                    $v['value'] = $v['value'] < $v['min'] ? $v['min'] : $v['value'];
                }
                if (isset($v['max'])) {
                    $v['value'] = $v['value'] > $v['max'] ? $v['max'] : $v['value'];
                }
            } else if ($v['type'] == 'bool') {
                $v['value'] = $v['value'] ? 1 : 0;
            }
            $config[$v['app']][$v['name']] = $v['value'];
        }
        F('config', $config);
    }
    $config = F('config');
    if (isset($app)) {
        $config = isset($config[$app]) ? $config[$app] : null;
    }
    if (isset($name)) {
        $config = isset($config[$name]) ? $config[$name] : null;
    }
    return isset($config) ? $config : $default;
}

function set_cookie ($name, $value, $expire = 0)
{
    setcookie($name, $value, $expire, '/');
    $_COOKIE[$name] = $value;
}

function template_replace ($template, array $value)
{
    if (empty($template)) {
        return '';
    }
    foreach ($value as $k => $v) {
        $template = str_replace('{$' . $k . '}', $v, $template);
    }
    if (false !== strpos($template, '{$')) {
        $template = preg_replace('/(\{\$.+\})/', '', $template);
    }
    return $template;
}

function pass_string ($str)
{
    return !empty($str) ? preg_replace('/^(.+)(.{4})?(.{4})?$/Us', '\\1****\\3', $str) : $str;
}

function trim_space ($string, int $start = null, int $length = null, $default = null)
{
    $string = $string ? str_replace(['　', ' ', "\r", "\n", "\t"], '', trim($string)) : $default;
    if ($string && !is_null($start)) {
        $string = mb_substr($string, $start, $length);
    }
    return $string;
}

function ishttp ($url)
{
    return (strpos($url, 'http:') === 0 || strpos($url, 'https:') === 0);
}

function islocal ($url)
{
    static $_local = [];
    if (!$url) {
        return false;
    }
    if (isset($_local[$url])) {
        return $_local[$url];
    }
    $_local[$url] = file_exists(APPLICATION_PATH . DIRECTORY_SEPARATOR . $url);
    return $_local[$url];
}

function avatar ($uid, $size = 'mid', $parent = '')
{
    if (!$uid) {
        return '';
    }
    $url = [
        'upload/a',
        $uid % 512,
        crc32($uid) % 512,
        $uid,
        $size
    ];
    return $parent . implode('/', $url) . '.jpg';
}

function httpurl ($url, $default = true)
{
    if (!$url) {
        return '';
    }
    if (!ishttp($url)) {
        // 判断用户本地头像地址是否存在
        if (0 === strpos($url, 'upload/a')) {
            if (!is_dir(dirname($url))) {
                if (false == $default) {
                    return '';
                }
                $url = 'public/img/offline.png';
            } else {
                $url .= '?' . substr(filemtime(APPLICATION_PATH . DIRECTORY_SEPARATOR . $url), -3);
            }
        }
        $url = APPLICATION_URL . '/' . $url;
    }
    return $url;
}

function mkdirm ($path)
{
    if ($path && !is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}

function burl ($param = null)
{
    $output = array();
    is_string($param) && parse_str($param, $output);
    $_url = $_GET;
    if ($output) {
        $_url = array_diff_key($_url, $output);
        $_url = array_merge($_url, $output);
    }
    return http_build_query($_url);
}

function gurl($url, $param = [])
{
    if (0 !== strpos($url, 'http')) {
        $url = APPLICATION_URL . '/' . ltrim($url, '/');
    }
    $output = [];
    is_string($param) && parse_str($param, $output);
    if ($output) {
        $param = $output;
    }
    return $url . ($param ? '?' . http_build_query($param) : '');
}

function weixin_version_number ($version_number = false)
{
    if (false === stripos($_SERVER['HTTP_USER_AGENT'], 'MicroMessenger/')) return false;
    if (false === $version_number) return true;
    preg_match("/MicroMessenger\/([0-9\.]+)/i", $_SERVER['HTTP_USER_AGENT'], $matches);
    $version = sprintf("%01.1f", floatval($matches[1]));
    return intval($version) ? $version : false;
}

function check_client ()
{
    // 微信
    if (weixin_version_number()) {return 'wx';}
    if (stripos($_SERVER['HTTP_USER_AGENT'], 'windows nt')) {return 'pc';}
    if (stripos($_SERVER['HTTP_USER_AGENT'], 'iPhone') || stripos($_SERVER['HTTP_USER_AGENT'], 'ipad')) {return 'mobile';}
    if (stripos($_SERVER['HTTP_USER_AGENT'], 'mac os')) {return 'pc';}
    return 'mobile';
}

function get_ip ()
{
    $ip = false;
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(', ', $_SERVER['HTTP_X_FORWARDED_FOR']);
        if ($ip) {
            array_unshift($ips, $ip);
            $ip = FALSE;
        }
        for ($i = 0; $i < count($ips); $i++) {
            if (!preg_match("/^(10|172\.16|192\.168)\./", $ips[$i], $match)) {
                $ip = $ips[$i];
                break;
            }
        }
    }
    $ip = $ip ? $ip : $_SERVER['REMOTE_ADDR'];
    $long = ip2long($ip);
    return ($long != -1 && $long !== FALSE) ? $ip : '';
}

function F ($name, $value = '')
{
    static $_cache = array();
    $filename = concat(APPLICATION_PATH, DIRECTORY_SEPARATOR, 'cache', DIRECTORY_SEPARATOR, $name, '.php');
    if ('' !== $value) {
        if (is_null($value)) {
            return unlink($filename);
        } else {
            $_cache[$name] = $value;
            return file_put_contents($filename, ("<?php\nreturn " . var_export($value, true) . ";\n?>"));
        }
    }
    if (isset($_cache[$name])) {
        return $_cache[$name];
    }
    if (!file_exists($filename)) {
        return false;
    }
    $value = include $filename;
    $_cache[$name] = $value;
    return $value;
}

function md5_mini ($a)
{
    $a = md5($a, true);
    $s = '0123456789ABCDEFGHIJKLMNOPQRSTUV';
    $d = '';
    for ($f = 0; $f < 8; $f++) {
        $g = ord($a[$f]);
        $d .= $s[($g ^ ord($a[$f + 8])) - $g & 0x1F];
    }
    return $d;
}

function str_conver ($str, $in_charset = 'GBK', $out_charset = 'UTF-8')
{
    if (empty($str)) {
        return $str;
    }
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($str, $out_charset, $in_charset);
    }
    if ($in_charset == 'GBK') {
        $in_charset = 'GBK//IGNORE';
    }
    return iconv($in_charset, $out_charset, $str);
}

function https_request (array $data)
{
    $data['timeout'] = isset($data['timeout']) ? $data['timeout'] : 3;
    $data['encode'] = isset($data['encode']) ? $data['encode'] : 'json';
    $data['reload'] = isset($data['reload']) ? $data['reload'] : 1;
    $data['st'] = isset($data['st']) ? $data['st'] : microtime(true);

    $curl = \curl_init();
    curl_setopt($curl, CURLOPT_URL, $data['url']);
    curl_setopt($curl, CURLOPT_TIMEOUT, $data['timeout']);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    if (isset($data['headers'])) {
        if (!isset($data['headers'][0])) {
            foreach ($data['headers'] as $k => $v) {
                $data['headers'][$k] = $k . ':' . $v;
            }
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $data['headers']);
    }
    if (isset($data['post'])) {
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($data['post']) ? http_build_query($data['post']) : $data['post']);
    }

    $reponse = curl_exec($curl);

    if (curl_errno($curl)) {
        if ($data['reload'] > 0) {
            curl_close($curl);
            $data['reload'] = $data['reload'] - 1;
            return https_request($data);
        }
        $error = curl_error($curl);
        \DebugLog::_log([
            '[Args] ' . json_unicode_encode(func_get_args()),
            '[Info] ' . json_unicode_encode(curl_getinfo($curl)),
            '[Fail] ' . $error,
            '[Time] ' . round(microtime(true) - $data['st'], 3) . 's'
        ], 'curlerror');
        curl_close($curl);
        throw new \Exception($error);
    }

    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    \DebugLog::_curl($data['url'], $data['headers'], $data['post'], round(microtime(true) - $data['st'], 3), $reponse);
    
    if ($reponse) {
        if ($data['encode'] == 'json') {
            $reponse = json_decode($reponse, true);
        } else if ($data['encode'] == 'xml') {
            $reponse = simplexml_load_string($reponse);
        } else if ($data['encode'] == 'object') {
            $reponse = json_decode($reponse);
        }
    }
    
    return [
        'http_code' => $httpCode,
        'data' => $reponse
    ];
}

function http_multi_exec (Closure $addHandle, $return)
{
    $mh = \curl_multi_init();
    $curls = $addHandle($mh);

    if (empty($curls)) {
        return null;
    }

    $active = null;

    if (!$return) {
        curl_multi_exec($mh,$active);
    } else {
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }
    }

    $reponse = [];
    foreach ($curls as $k => $v) {
        if ($return) {
            $reponse[$k]['http_code'] = curl_getinfo($v, CURLINFO_HTTP_CODE);
            $reponse[$k]['errno'] = curl_errno($v);
            if ($reponse[$k]['errno']) {
                $reponse[$k]['error'] = curl_error($v);
            } else {
                $reponse[$k]['content'] = curl_multi_getcontent($v);
            }
        }
        curl_multi_remove_handle($mh, $v);
    }

    unset($curls);
    curl_multi_close($mh);

    return $reponse;
}

function http_multi_request (array $urls, $return = true)
{
    return http_multi_exec(function($mh) use($urls, $return) {
        foreach ($urls as $k => $v) {
            if (empty($v['url'])) {
                unset($urls[$k]);
                continue;
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $v['url']);
            curl_setopt($ch, CURLOPT_TIMEOUT, $v['timeout'] ? $v['timeout'] : 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            if (!$return) {
                $v['header'] = $v['header'] ? $v['header'] : [];
                $v['header'][] = 'Connection: Close';
            }
            if ($v['header']) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $v['header']);
            }
            if ($v['post']) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($v['post']) ? http_build_query($v['post']) : $v['post']);
            }
            $urls[$k] = $ch;
            curl_multi_add_handle($mh, $ch);
        }
        return $urls;
    }, $return);
}

function getgpc ($k, $type = 'GP')
{
    switch ($type) {
        case 'G':
            $var = &$_GET;
            break;
        case 'P':
            $var = &$_POST;
            break;
        case 'C':
            $var = &$_COOKIE;
            break;
        default:
            isset($_POST[$k]) ? $var = &$_POST : $var = &$_GET;
            break;
    }
    return isset($var[$k]) ? $var[$k] : NULL;
}

function safepost (&$data)
{
    if (!empty($data)) {
        array_walk($data, function (&$v) {
            if (is_array($v)) {
                safepost($v);
            } else {
                $v = htmlspecialchars(rtrim($v, "\0"), ENT_QUOTES);
            }
        });
    }
}

function success ($data, $message = '', $errorcode = 0)
{
    if (empty($message)) {
        $message = !is_array($data) ? $data : $message;
    }
    $result = [
        'errorcode' => $errorcode,
        'message'   => $message,
        'data'    => is_array($data) ? $data : []
    ];
    if (isset($_POST['statuscode'])) {
        $result['statuscode'] = $_POST['statuscode'];
    }
    return $result;
}

function error ($data, $message = '', $errorcode = -1)
{
    if (empty($message)) {
        $message = !is_array($data) ? $data : $message;
    }
    $result = [
        'errorcode' => $errorcode,
        'message'   => $message,
        'data'    =>  is_array($data) ? $data : []
    ];
    if (isset($_POST['statuscode'])) {
        $result['statuscode'] = $_POST['statuscode'];
    }
    return $result;
}

function json ($data, $message = '', $errorcode = 0, $httpcode = 200) {
    if ($httpcode) {
        http_response_code($httpcode);
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($errorcode === 0) {
        echo json_unicode_encode(success($data, $message, $errorcode));
    } else {
        echo json_unicode_encode(error($data, $message, $errorcode));
    }
    exit(0);
}

function json_unicode_encode ($data, $default = '')
{
    // JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE
    return empty($data) ? $default : json_encode($data, JSON_UNESCAPED_UNICODE);
}

function json_mysql_encode ($data)
{
    $data = json_unicode_encode($data);
    $data = str_replace([
        '\\\\\\\\\'',
        '\\\\\\\\\\\\"',
        '\\\\\\\\\\\\\\\\'
    ], [
        '\'',
        '\\"',
        '\\\\\\\\'
    ], addslashes($data));
    return $data;
}

function uploadfile ($upfile, $allow_type = 'jpg,jpeg,gif,png,bmp', $width = 80, $height = 0, $rotate = 0)
{
    $upload_path = APPLICATION_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'upload';
    if (!$file_exe = strtolower(substr(strrchr($upfile['name'], '.'), 1))) {
        return error('文件格式错误');
    }
    if ($allow_type && $allow_type != '*') {
        if (false === strpos($allow_type, $file_exe)) {
            return error('文件类型不允许');
        }
    } else {
        if (false !== strpos('php,js,css,exe,asp,aspx,vbs', $file_exe)) {
            return error('文件类型不允许');
        }
    }
    $file_type = 0;
    if (false !== strpos('jpg,jpeg,gif,png,bmp', $file_exe)) {
        $file_type = 1;
    } elseif (false !== strpos('mp3,mid,wav,ape,flac,amr', $file_exe)) {
        $file_type = 2;
    } elseif (false !== strpos('3gp,3g2,avi,mp4,mpeg,mov,tts,asx,wm,wmv,wmx,wvx,flv,mkv,rm,asf', $file_exe)) {
        $file_type = 3;
    }
    $file_name = uniqid() . '.' . $file_exe;
    $file_path = date('Ym', TIMESTAMP);
    $url = $file_path . DIRECTORY_SEPARATOR . $file_name;
    mkdirm($upload_path . DIRECTORY_SEPARATOR . $file_path);
    if (is_uploaded_file($upfile['tmp_name'])) {
        if (!move_uploaded_file($upfile['tmp_name'], $upload_path . DIRECTORY_SEPARATOR . $url)) {
            return error('上传失败');
        }
    } else {
        rename($upfile['tmp_name'], $upload_path . DIRECTORY_SEPARATOR . $url);
    }
    unlink($upfile['tmp_name']);
    // 图片旋转
    if ($rotate > 0) {
        image_rotate($upload_path . DIRECTORY_SEPARATOR . $url, $rotate);
    }
    if ($file_type == 1 && ($width > 0 || $height > 0)) {
        $thumburl = thumb($upload_path . DIRECTORY_SEPARATOR . $url, $upload_path . DIRECTORY_SEPARATOR . getthumburl($url), '', $width, $height);
    }
    $files = $upfile;
    $files['url'] = str_replace('\\', '/', 'upload/' . $url); // 本地路径转成HTPP地址
    $files['file_ext'] = $file_exe;
    $files['type'] = $file_type;
    $files['thumburl'] = $thumburl ? getthumburl($files['url']) : '';
    return success($files);
}

function getthumburl ($url)
{
    $pos = strrpos($url, '.');
    return str_replace('.', '_', $url) . substr($url, $pos);
}

function image_rotate($image_file, int $degrees = 90) {
    $image_info = getimagesize($image_file);
    $image_type = image_type_to_extension($image_info[2], false);

    $createFun = 'imagecreatefrom' . $image_type;
    if (!$resource = @$createFun($image_file)) {
        return false;
    }
    if ($image_type === 'png') {
        // 透明背景
        imagealphablending($resource, false);
        imagesavealpha($resource, true);
        $pngTransparency = imagecolorallocatealpha($resource, 0, 0, 0, 127);
        imagefill($resource, 0, 0, $pngTransparency);
        if ($res = imagerotate($resource, $degrees, $pngTransparency)) {
            imagealphablending($res, false);
            imagesavealpha($res, true);
            imagepng($res, $image_file);
        }
    } else {
        if ($res = imagerotate($resource, $degrees, 0)) {
            imagejpeg($res, $image_file);
        }
    }
    imagedestroy($resource);
    imagedestroy($res);
    return true;
}

function thumb ($src_img, $thumbname, $image_type = null, $dst_w = 120, $dst_h = 120)
{
    // $dst_w>0 && $dst_h>0 为抠图方式，否则为缩放方式 
    if ($dst_w <= 0 && $dst_h <= 0) {
        return false;
    }

    $image_info = getimagesize($src_img);
    if (false === $image_info) {
        return false;
    }

    mkdirm(dirname($thumbname));

    $dst_w = intval($dst_w);
    $dst_h = intval($dst_h);
    $src_w = $image_info[0];
    $src_h = $image_info[1];

    $image_type = $image_type ? $image_type : image_type_to_extension($image_info[2], false);
    $image_type = strtolower($image_type);
    $image_type = $image_type == 'jpg' ? 'jpeg' : $image_type;
    $image_type = $image_type == 'bmp' ? 'wbmp' : $image_type;

    // 计算缩放比例
    if ($dst_w == 0) {
        $scale = $dst_h / $src_h;
    } else if ($dst_h == 0) {
        $scale = $dst_w / $src_w;
    } else {
        // 不缩放
        $scale = 0;
    }

    // 计算缩略图尺寸
    if ($scale > 0) {
        $width = intval($src_w * $scale);
        $height = intval($src_h * $scale);
    } else {
        $width = $dst_w > $src_w ? $src_w : $dst_w;
        $height = $dst_h > $src_h ? $src_h : $dst_h;
    }

    if ($width >= $src_w && $height >= $src_h) {
        // 原图尺寸小于缩略图
        return false;
    }

    // 定位原图
    if ($scale > 0) {
        // 缩放方式
        $x = 0;
        $y = 0;
        $w = $src_w;
        $h = $src_h;
        if ($height > $width) {
            if ($height / $width > 6) {
                // 过高
                $w = $src_w;
                $h = $height;
                $width = $dst_w > 0 ? min($src_w, $dst_w) : $src_w;
                $x = 0;
                $y = ($src_h - $h) / 3;
            }
        } else {
            if ($width / $height > 6) {
                // 过宽
                $w = $dst_w > 0 ? $dst_w : $src_w;
                $h = $src_h;
                $height = min($src_h, $dst_h > 0 ? $dst_h : $dst_w);
                $x = ($src_w - $w) / 2;
                $y = 0;
            }
        }
    } else {
        // 抠图方式
        $x = 0;
        $y = 0;
        $w = $width;
        $h = $height;
        if ($width < $src_w) {
            $x = intval(($src_w - $width) / 2);
        }
        if ($height < $src_h) {
            $y = intval(($src_h - $height) / 2);
        }
    }
    
    $createFun = 'imagecreatefrom' . $image_type;
    $imageFun = 'image' . $image_type;
    
    $source = $createFun($src_img);
    $target = imagecreatetruecolor($width, $height); // 新建一个真彩色图像
    imagecopyresampled($target, $source, 0, 0, $x, $y, $width, $height, $w, $h); // 重采样拷贝部分图像并调整大小
    if ($image_type === 'png') {
        imagealphablending($target, false);
        imagesavealpha($target, true);
    }
    $imageFun($target, $thumbname); // 保存
    imagedestroy($target);
    imagedestroy($source);
    return $thumbname;
}

function auto_page_arr ($page, $maxpage, $left = 3)
{
    $j = 0;
    for ($i = $page; $i > 0; $i--) {
        $arr[] = $i;
        $j++;
        if ($j > $left) break;
    }
    $j = 0;
    for ($i = $page; $i <= $maxpage; $i++) {
        $arr[] = $i;
        $j++;
        if ($j > $left) break;
    }
    $arr = array_filter($arr);
    $arr = array_unique($arr);
    sort($arr);
    return $arr;
}

/**
 * 获取分页参数
 * @param $page 当前页
 * @param $totalcount 总记录数
 * @param $pagecount 一页显示数
 * @param $left
 */
function getPageParams ($page, $totalcount, $pagecount = 10, $left = 3)
{
    $page = intval($page);
    $totalcount = intval($totalcount);
    $pagecount = intval($pagecount);
    $left = intval($left);
    $page = $page < 1 ? 1 : $page;
    $totalpage = ($totalcount % $pagecount) > 0 ? (intval($totalcount / $pagecount) + 1) : intval($totalcount / $pagecount);
    $arr = [];
    if (!isset($_GET['ajax'])) {
        $page > 1 && $arr[1] = '首页';
        $j = 0;
        for ($i = $page; $i > 0; $i--) {
            $arr[$i] = $i;
            $j++;
            if ($j > $left) {
                break;
            }
        }
        $j = 0;
        for ($i = $page; $i <= $totalpage; $i++) {
            $arr[$i] = $i;
            $j++;
            if ($j > $left) {
                break;
            }
        }
        asort($arr);
        ($page < $totalpage - $left) && $arr[$totalpage] = '尾页';
    }
    return array(
            'page' => $page,
            'totalcount' => $totalcount,
            'totalpage' => $totalpage,
            'scrollpage' => $arr,
            'limitstr' => intval(($page - 1) * $pagecount) . ',' . $pagecount
    );
}

function check_car_license($license)
{
    if (empty($license)) {
        return false;
    }

    //匹配民用车牌和使馆车牌
    //判断标准
    //1，第一位为汉字省份缩写
    //2，第二位为大写字母城市编码
    //3，后面是5位仅含字母和数字的组合
    $regular = "/[京津冀晋蒙辽吉黑沪苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云渝藏陕甘青宁新使]{1}[0|A-Z]{1}[0-9a-zA-Z]{5,6}$/u";
    preg_match($regular, $license, $match);
    if (isset($match[0])) {
        return true;
    }

    //匹配特种车牌(挂,警,学,领,港,澳)
    //参考 https://wenku.baidu.com/view/4573909a964bcf84b9d57bc5.html
    $regular = '/[京津冀晋蒙辽吉黑沪苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云渝藏陕甘青宁新]{1}[A-Z]{1}[0-9a-zA-Z]{4}[挂警学领港澳]{1}$/u';
    preg_match($regular, $license, $match);
    if (isset($match[0])) {
        return true;
    }

    //匹配武警车牌
    //参考 https://wenku.baidu.com/view/7fe0b333aaea998fcc220e48.html
    $regular = '/^WJ[京津冀晋蒙辽吉黑沪苏浙皖闽赣鲁豫鄂湘粤桂琼川贵云渝藏陕甘青宁新]?[0-9a-zA-Z]{5}$/ui';
    preg_match($regular, $license, $match);
    if (isset($match[0])) {
        return true;
    }

    //匹配军牌
    //参考 http://auto.sina.com.cn/service/2013-05-03/18111149551.shtml
    $regular = "/[A-Z]{2}[0-9]{5}$/";
    preg_match($regular, $license, $match);
    if (isset($match[0])) {
        return true;
    }

    return false;
}

function validate_telephone ($telephone)
{
    if (empty($telephone)) {
        return false;
    }
    if (!preg_match('/^1[0-9]{10}$/', $telephone)) {
        return false;
    }
    return true;
}

function only ($keys)
{
    $keys = is_array($keys) ? $keys : func_get_args();

    $results = array_merge($_GET, $_POST);

    foreach ($results as $k => $v) {
        if (!in_array($k, $keys)) {
            unset($results[$k]);
        }
    }

    return $results;
}

function except ($keys)
{
    $keys = is_array($keys) ? $keys : func_get_args();

    $results = array_merge($_GET, $_POST);

    foreach ($results as $k => $v) {
        if (in_array($k, $keys)) {
            unset($results[$k]);
        }
    }

    return $results;
}

function array_key_clean (array $input, array $only = [], array $except = [])
{
    foreach ($input as $k => $v) {
        if (is_array($v)) {
            $input[$k] = array_key_clean($input[$k], $only, $except);
        } else {
            if ($only && in_array($k, $only)) {
                unset($input[$k]);
            }
            if ($except && !in_array($k, $except)) {
                unset($input[$k]);
            }
        }
    }
    return $input;
}

function get_short_array ($input, $delimiter = ',', $length = 200)
{
    return $input ? explode($delimiter, trim(mb_substr($input, 0, $length), $delimiter)) : [];
}

function get_list_dir ($root, $paths = [])
{
    $root = trim_space(rtrim($root, DIRECTORY_SEPARATOR));
    if (empty($root)) {
        return [];
    }
    $files = (array) glob($root);
    foreach ($files as $path) {
        if (is_dir($path)) {
            $paths = get_list_dir($path . '/*', $paths);
        } else {
            $paths[] = $path;
        }
    }
    return $paths;
}

function array_curd (array $exists, array $posts)
{
    return [
        'add' => array_diff($posts, $exists),
        'delete' => array_diff($exists, $posts),
        'update' => array_intersect($exists, $posts)
    ];
}

function export_csv_data ($fileName, $header, array $list = [])
{
    $fileName = $fileName . date('Ymd', TIMESTAMP);
    $fileName = preg_match('/(Chrome|Firefox)/i', $_SERVER['HTTP_USER_AGENT']) && !preg_match('/edge/i', $_SERVER['HTTP_USER_AGENT']) ? $fileName : urlencode($fileName);

    header('cache-control:public');
    header('content-type:application/octet-stream');
    header('content-disposition:attachment; filename=' . $fileName . '.csv');

    $input = [$header];
    foreach ($list as $k => $v) {
        foreach ($v as $kk => $vv) {
            if (is_numeric($vv)) {
                $v[$kk] = $vv . "\t";
            } else if (false !== strpos($vv, ',')) {
                $v[$kk] = '"' . $vv . '"';
            }
        }
        $input[] = implode(',', $v);
    }
    unset($list);

    echo mb_convert_encoding(implode("\n", $input), 'GB2312', 'UTF-8');
    exit(0);
}

function conver_chinese_dollar (float $num)
{
    $zh_num = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
    $zh_unit = ['分', '角', '元', '拾', '佰', '仟', '万', '拾', '佰', '仟', '亿', '拾', '佰', '仟'];
    if (!is_numeric(str_replace(',', '', $num))) {
        return $num;
    }
    $number = strrev(round(str_replace(',', '', $num), 2) * 100);
    $length = strlen($number);
    $ch_str = '';
    for ($length; $length > 0; $length--) {
        $index = $length - 1;
        if ($number[$index] == '0' && !in_array($zh_unit[$index], ['万', '元', '亿'])) {
            $ch_str.=$zh_num[$number[$index]];
        } elseif ($number[$index] == '0' && in_array($zh_unit[$index], ['万', '元', '亿'])) {
            $ch_str.= $zh_unit[$index];
        } else {
            $ch_str.=$zh_num[$number[$index]] . $zh_unit[$index];
        }
    }
    $format_str = preg_replace(['/零{2,}/u', '/零万/', '/零元/', '/零亿/'], ['零', '万', '元', '亿'], $ch_str);
    $format_str = preg_replace('/(^零)|(零$)/', '', $format_str);
    if (preg_match('/(分|角)/', $format_str) === 0) {
        $format_str.='整';
    }
    return $format_str;
}
