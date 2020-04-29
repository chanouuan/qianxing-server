<?php

namespace app\models;

use Crud;

class ImportModel extends Crud {

    /**
     * 下载模板
     * @return array
     */
    public function downloadCsvTemplate ($type)
    {
        if ($type == 'user') {
            export_csv_data('用户导入模板', '单位,姓名,手机号,密码,职位,执法证号,角色');
        }
    }

    /**
     * 导入数据
     * @return array
     */
    public function importCsv ($user_id, $type)
    {
        set_time_limit(600);

        if ($_FILES['upfile']['error'] !== 0) {
            return error('上传文件为空');
        }
        if (strtolower(substr(strrchr($_FILES['upfile']['name'], '.'), 1)) != 'csv') {
            unlink($_FILES['upfile']['tmp_name']);
            return error('上传文件格式错误');
        }
        if ($_FILES['upfile']['size'] > 10000000) {
            unlink($_FILES['upfile']['tmp_name']);
            return error('上传文件太大');
        }

        // 转码
        if (false === file_put_contents($_FILES['upfile']['tmp_name'], $this->upEncodeUTF(file_get_contents($_FILES['upfile']['tmp_name'])))) {
            unlink($_FILES['upfile']['tmp_name']);
            return error($_FILES['upfile']['name'] . '转码失败');
        }

        if (false === ($handle = fopen($_FILES['upfile']['tmp_name'], "r"))) {
            unlink($_FILES['upfile']['tmp_name']);
            return error($_FILES['upfile']['name'] . '文件读取失败');
        }

        $field = [];
        if ($type == 'user') {
            $field = ['group','name','telephone','pwd','title','law_num','role'];
        }
        
        $list = [];
        while(($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (empty($data)) {
                continue;
            }
            $arr = [];
            foreach ($field as $k => $v) {
                $arr[$v] = $this->upFilterData($data[$k]);
            }
            $list[] = $arr;
        }
        unset($arr, $list[0]);
        fclose($handle);
        unlink($_FILES['upfile']['tmp_name']);

        if (!count($list)) {
            return error('导入数据为空');
        }

        if ($type == 'user') {
            $result = $this->importAdmin($user_id, $list);
        }

        $list = null;
        return $result;
    }

    /**
     * 单位人员导入
     * @return array
     */
    private function importAdmin($user_id, &$list)
    {
        // 效验数据
        foreach ($list as $k => $v) {
            if (!$v['group']) {
                return error('[第' . ($k + 1) . '行] 单位不能为空！');
            }
            if (!$v['name']) {
                return error('[第' . ($k + 1) . '行] 姓名不能为空！');
            }
            if (!validate_telephone($v['telephone'])) {
                return error('[第' . ($k + 1) . '行] 手机号错误！');
            }
        }

        // 合并重复数据
        $list = array_values(array_column($list, null, 'telephone'));

        $groups = (new GroupModel())->select(['level' => 3], 'id,name');
        $groups = array_column($groups, 'id', 'name');
        $res = $this->getDb()
            ->table('admin_roles')
            ->field('id,group_id,name')
            ->where(['group_id' => ['in', array_values($groups)]])
            ->select();
        $roles = [];
        foreach ($res as $k => $v) {
            $roles[$v['group_id']][$v['name']] = $v['id'];
        }
        unset($res);

        $adminModel = new AdminModel();
        foreach ($list as $k => $v) {
            $data = [
                'group_id' => $groups[$v['group']],
                'full_name' => $v['name'],
                'telephone' => $v['telephone'],
                'password' => $v['pwd'],
                'title' => $v['title'],
                'law_num' => $v['law_num']
            ];
            if (!$data['group_id']) {
                return error('[第' . ($k + 1) . '行] ' . $v['group'] . '未找到该单位');
            }
            if ($v['role']) {
                if (!isset($roles[$data['group_id']])) {
                    return error('[第' . ($k + 1) . '行] ' . $v['group'] . '未找到角色');
                }
                $data['role_id'] = strtr($v['role'], $roles[$data['group_id']]);
            }
            $result = $adminModel->savePeople($user_id, $data, $data['group_id']);
            if ($result['errorcode'] !== 0) {
                return error('[第' . ($k + 1) . '行] ' . $result['message']);
            }
            if ($k % 10 == 0) {
                usleep(20000);
            }
        }

        unset($groups, $roles);
        return success('新增单位人员 ' . count($list) . ' 条');
    }

    /**
     * 上传数据转码 UTF-8
     * @return string
     */
    private function upEncodeUTF($text)
    {
        if (!$encode = mb_detect_encoding($text, array('UTF-8','GB2312','GBK','ASCII','BIG5'))) {
            return '';
        }
        if($encode != 'UTF-8') {
            return mb_convert_encoding($text, 'UTF-8', $encode);
        } else {
            return $text;
        }
    }

    /**
     * 过滤上传数据
     * @return string
     */
    private function upFilterData($data)
    {
        $data = trim_space($data, 0, 200);
        $data = str_replace(["\r", "\n", "\t", '"', '\''], '', $data);
        $data = htmlspecialchars($data, ENT_QUOTES);
        return $data;
    }

}