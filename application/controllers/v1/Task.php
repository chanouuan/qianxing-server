<?php

namespace app\controllers;

use ActionPDO;

class Task extends ActionPDO {

    public function _init ()
    {
        set_time_limit(3600);
        \DebugLog::_debug(false);
    }

    public function crond ()
    {
        $timewheel = new \app\library\TimeWheel();
        $timer = $timewheel->tick();
        return (new \app\models\TaskModel())->crond(implode('', $timer));
    }

}
