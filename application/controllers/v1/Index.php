<?php

namespace app\controllers;

use ActionPDO;

class Index extends ActionPDO {

    public function _init ()
    {
        header('Access-Control-Allow-Origin: *'); // 允许任意域名发起的跨域请求
        header('Access-Control-Allow-Headers: X-Requested-With,X_Requested_With');
        if (!isset($_SERVER['PHP_AUTH_USER']) ||
            !isset($_SERVER['PHP_AUTH_PW']) ||
            $_SERVER['PHP_AUTH_USER'] != 'admin' ||
            $_SERVER['PHP_AUTH_PW'] != '12345678') {
            header('HTTP/1.1 401 Unauthorized');
            http_response_code(401);
            header('WWW-Authenticate: Basic realm="Administrator Secret"');
            exit('Administrator Secret!');
        }
    }

    public function index () 
    {
        die();
    }

    public function setmap ()
    {
        if (!$_GET['direction']) {
            $this->_render('setmap.html', null, 'default');
        }

        // https://lbs.qq.com/service/webService/webServiceGuide/webServiceRoute

        // heading [from辅助参数]在起点位置时的车头方向，
        // 数值型，取值范围0至360（0度代表正北，顺时针一周360度）传入车头方向，
        // 对于车辆所在道路的判断非常重要，直接影响路线计算的效果

        //[road_type] 起点道路类型，可选值：
        //0 [默认]不考虑起点道路类型
        //1 在桥上；2 在桥下；3 在主路；4 在辅路；5 在对面；6 桥下主路；7 桥下辅路
        $params = [
            'from' => $_GET['from'],
            'speed' => 10,
            'heading' => intval($_GET['heading']),
            'to' => $_GET['to'],
            'road_type' => 3,
            //'policy' => 'TRIP',
            'key' => 'RJABZ-M7ZWS-MVAOZ-6X3DM-27WKO-D4F5G'
        ];
        $data = https_request([
            'url' => 'https://apis.map.qq.com/ws/direction/v1/driving/?' . http_build_query($params)
        ]);
        $coors = $data['data']['result']['routes'][0]['polyline'];
        $distance = $data['data']['result']['routes'][0]['distance'] / 1000;
        $pl = [];
        if (!$coors) {
            return error($data);
        }
        unset($data);
        //坐标解压（返回的点串坐标，通过前向差分进行压缩）
        $kr = 1000000;
        $len = count($coors);
        for ($i = 2; $i < $len; $i++) {
          $coors[$i] = floatval($coors[$i - 2]) + floatval($coors[$i]) / $kr;
        }
        //将解压后的坐标放入点串数组pl中
        for ($i = 0; $i < $len; $i += 2) {
          $pl[] = [ $coors[$i + 1], $coors[$i] ];
        }
        unset($coors);
        //减少路径
        $len = count($pl);
        for ($i = 1; $i < $len - 1; $i++) {
            if ($i % 2 == 1) {
                unset($pl[$i]);
            }
        }
        $pl = array_values($pl);
        // 获取途经区域
        $arr = [
            $pl[0],
            $pl[count($pl) - 1],
            $pl[intval(count($pl) * (1/5))],
            $pl[intval(count($pl) * (2/5))],
            $pl[intval(count($pl) * (3/5))],
            $pl[intval(count($pl) * (4/5))]
        ];
        $params = [
            'key' => 'RJABZ-M7ZWS-MVAOZ-6X3DM-27WKO-D4F5G'
        ];
        $alldistrict = [];
        foreach ($arr as $k => $v) {
            $params['location'] = implode(',', [$v[1], $v[0]]);
            $data = https_request([
                'url' => 'https://apis.map.qq.com/ws/geocoder/v1/?' . http_build_query($params)
            ]);
            $district = $data['data']['result']['ad_info']['district'];
            if (!$district) {
                return error($data);
            }
            $alldistrict[] = $district;
            usleep(500000); // 防止秒级限流
        }
        $alldistrict = array_unique($alldistrict);
        return success([
            'distance' => $distance,
            'district' => implode(',', $alldistrict),
            'pl' => $pl
        ]);
    }

    public function logger ()
    {
        \DebugLog::_debug(false);
        $path = trim_space(ltrim($_GET['path'], '/'));
        $path = ltrim(str_replace('.', '', $path), '/');
        $path = $path ? $path : (date('Ym') . '/' . date('Ymd') . '_debug');
        $path = APPLICATION_PATH . '/log/' . $path . '.log';
        if ($_GET['dir']) {
            $list = get_list_dir(APPLICATION_PATH . '/log');
            if (count($list) > 30) {
                $list = array_slice($list, count($list) - 30);
            }
            foreach ($list as $k => $v) {
                $list[$k] =  '<a href="' . (APPLICATION_URL . '/index/logger?path=' . str_replace(APPLICATION_PATH . '/log/', '', substr($v, 0, -4)) . '&dir=1') . '">' . str_replace(APPLICATION_PATH . '/log', '', $v) . '</a> ' . byte_convert(filesize($v)) . ' <a href="' . APPLICATION_URL . '/index/logger?path=' . str_replace([APPLICATION_PATH . '/log', '.log'], '', $v) . '&dir=1&clear=1">DEL</a>';
            }
        }
        if ($_GET['clear']) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        ?>
        <!doctype html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title></title>
            <meta name="viewport" content="width=device-width,user-scalable=yes, minimum-scale=1, initial-scale=1"/>
        </head>
        <body>
            <pre><?=implode("\n",$list)?></pre>
            <pre><?=file_exists($path)?file_get_contents($path):'404'?></pre>
        </body>
        </html>
        <?php
        exit(0);
    }

}
