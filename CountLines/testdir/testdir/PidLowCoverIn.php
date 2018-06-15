<?php

if (!class_exists(Setting_Conf)) {
    // 本地测试环境 
    require_once '/home/work/orp/php/phplib/bd/Init.php';
    $objApplication = Bd_Init::init('newdatadev');
} else {
    // 线上环境
    $objApplication = Bd_Init::init();
}

$job = new Job_PidLowCoverIn();
$job->execute();
//如果利用noah ct任务系统运行脚本，需要显示退出，设置退出码为0，否则监控系统会报警
exit(0);

/**
 * @name 应用秀
 * @desc 业务层
 * @author (shidongsheng01@baidu.com)
 */
class Job_PidLowCoverIn {

    private $dataObj = null;
    private $jobName = 'pid_low_cover_in';
    // 错误码也关联到发送邮件的配置Setting_Conf::$mail['msgIDList']
    private $msg = array(
        '0' => 'success',
        // 数据预处理错误信息
        '1001' => '接口返回数据为空',
        '1002' => '处理接口原始数据出错',
        '1003' => '接口返回code!=1错误数据',
        '1004' => '接口返回数据关键字段list和count缺失',
        '1005' => '接口返回数据大小不匹配',
        '1007' => '不是合法的优惠类型',
        '1008' => 'list:info信息不存在',
        '1009' => 'list:info中缺少必要信息',
        '1010' => '待插入元素为空',
        // 数据库操作错误信息
        '2001' => '所有包名在数据库中都没有对应的信息存在',
        '2002' => '不存在对应的包名信息',
        '2003' => '下线历史优惠数据失败(set flag=1)',
        '2004' => '插入数据失败',
        // 打印信息
        '9000' => 'start',
        '9001' => '最终执行结果：', // 接收总数据：(%s)，去重包名后：(%s)，有对应PID：(%s)，过滤后计划插入：(%s)，实际成功:(%s)
        '9999' => 'end', // 耗时
    );

    function __construct() {
//        $this->log_id = md5(uniqid(mt_rand(), true));
    }

    /*
     * 执行函数
     * @param 
     * @return
     */

    public function execute() {
        //start
        $this->msgInfo('9000', array(), 1);
        $beginTime = time();
        // 获取接口数据并处理
        $this->getSourceData();

        $endTime = time();
        $this->msgInfo('9999', array('const time:' => $endTime - $beginTime), 1);
        exit(0);
    }

    /**
     * 拼装返回值信息,打印日志
     * @param string $id
     * @param array $ext 其他信息,最终会json输出
     * @param int $echoFlg 是否打印输出default：0不输出
     * @param int $logFlg 是否写入文件 default：1写入
     * @return <array>
     */
    private function msgInfo($id, $ext = array(), $echoFlg = 0, $logFlg = 1) {

        $result = array(
            'msgcode' => $id,
            'msg' => $this->msg[$id],
        );

        if (!empty($ext)) {
            foreach ($ext as $k => $v) {
                $result[$k] = $v;
            }
        }

        $txt = "$this->jobName:" . Comm_Utils::json_encode_ex($result);
        if ($logFlg == 1) {
            Bd_Log::notice($txt);
        }
        if ($echoFlg == 1) {
            echo $txt . "\n";
        }

        // 需要发送报警邮件的错误
        if (in_array($id, Setting_Conf::$mail['msgIDList'])) {
            $content = sprintf("您好，<br>
                                信息如下：
				%s
				<br><br>百度手机助手<br>
				", $txt);
            $param = array(
                'fromName' => (9001 == $id) ? Setting_Conf::$mail['fromName9001'] : Setting_Conf::$mail['fromName'],
                'title' => (9001 == $id) ? Setting_Conf::$mail['title9001'] : Setting_Conf::$mail['title'],
                'receiver' => Setting_Conf::$mail['receiver'], //array
                'content' => addslashes($content),
                'contentType' => 'html',
            );

            $status = Comm_Utils::sendMail($param);
            echo "报警邮件发送 " . ($status ? "成功" : "失败") . "\n";
            Bd_Log::notice("邮件发送" . ($status ? "成功" : "失败"));
        }

        return $result;
    }

    /**
     * 应用秀获取开发者中心数据
     * @param 
     * @return
     */
    public function getSourceData() {

        // 向开发者接口后端发送的参数
        $paramMdev = array(
//            'product' => '',
            't' => time(),
        );

        $ralMdev = new Comm_RalMdev();
        $lifeService = $ralMdev->getLifeService($paramMdev);

        // 接口返回数据为空
        if (empty($lifeService)) {
            return $this->msgInfo('1001');
        }
        // 处理接口原始数据出错,0为处理成功
        if ($lifeService['errno'] != 0) {
            return $this->msgInfo('1002');
        }
        // 接口返回code!=1错误数据
        if (!isset($lifeService['code']) || $lifeService['code'] != 1) {
            return $this->msgInfo('1003');
        }
        // 接口返回数据关键字段list和count缺失
        if (!isset($lifeService['list']) || !isset($lifeService['count'])) {
            return $this->msgInfo('1004');
        }
        // 接口返回数据大小不匹配
        if (count($lifeService['list']) != $lifeService['count']) {
            return $this->msgInfo('1005');
        }

        // 数据处理
        $this->dealData($lifeService['list']);
    }

    /**
     * 应用秀整理数据
     * @param $list 获取的原始list数据
     * @return
     */
    private function dealData($list) {
        // 包名集合
        $listPackage = array();
        $ListDetail = array();

        foreach ($list as $v) {
            $listPackage[] = trim($v['package']);
            continue;
        }
        // 去重，并重新建立索引
        $listPackage = array_values(array_unique($listPackage));

        $this->dataObj = new Service_Data_AndroidLowCover();
        // 包名和pid对应关系集合
        $listPid = $this->getPidList($listPackage);

        // 库中所有数据都不存在
        if (empty($listPid)) {
            return $this->msgInfo('2001');
        }

        // detail查库字段
        $listDetail = $this->formatJson($list, $listPid);
        // 数据库更新和插入操作
        $succNum = $this->doModel($listDetail);

        // 统计最后执行结果
        $ext = array(
            '接收总原始数据：' => count($list),
            '去重包名后：' => count($listPackage),
            '有对应PID：' => count($listPid),
            '过滤后计划插入：' => count($listDetail),
            '实际成功:' => $succNum,
        );
        $this->msgInfo('9001', $ext, 1);
    }

    /**
     * 获取包名和pid对应关系集合
     * @param $listPackage 不重复的包名集合
     * @return
     */
    private function getPidList($listPackage) {
        // 返回pidList
        $ListPid = array();
        // 拼接where条件in的大小
        $len = Setting_Conf::$sqlInLength;
        // 当前分拆数组游标起始位置
        $currentIndex = 0;
        // 是否执行查询操作标记
        $selectFlg = false;
        $arrSubPackage = '';
        // 遍历包名，查找pid
        for ($i = 1; $i <= count($listPackage); $i++) {

            if (($i % $len) == 0 || $i == count($listPackage)) {
                $selectFlg = true;
            }

            // 需要执行查询数据库操作
            if ($selectFlg == true) {
                // 还原标记值
                $selectFlg = false;
                // 查库
                $arrSubPackage = array_slice($listPackage, $currentIndex, $len);
                $currentIndex += $len;

                $data = array(
                    'package' => $arrSubPackage,
                );

                // 查找对应关系
                $ret = $this->dataObj->getAndroidAllInfo($data);
                if (empty($ret)) {
                    continue;
                }

                foreach ($ret as $v) {
                    $ListPid[$v['package']] = $v['packageid'];
                }
            }
        }
        return $ListPid;
    }

    /**
     * 做数据插入操作
     * @param $listDetail 待插入集合
     * @return int $succNum 成功数据
     */
    private function doModel($listDetail) {
        // 下线之前的历史数据
        $updData = array(
            'flag' => 1,
        );

//        $this->dataObj = new Service_Data_AndroidLowCover();
        $retUpd = $this->dataObj->UpdData($updData);

        // 下线失败
        if (empty($retUpd) || $retUpd == 0) {
            $this->msgInfo('2003');
        }

        $succNum = 0;
        $paramIns = array();
        foreach ($listDetail as $v) {
            // field type is pid
            if ($v['docid'] == 0) {
                $paramIns = array(
                    'fields_type' => 'packageid',
                    'fields_value' => $v['packageid'],
                    'operate_type' => Setting_Conf::$operateType[$v['product']],
                    'operate_detail' => Comm_Utils::json_encode_ex($v['detailInfo']),
                    'packageid' => $v['packageid'],
                );
            } else {
                $paramIns = array(
                    'fields_type' => 'docid',
                    'fields_value' => $v['docid'],
                    'operate_type' => Setting_Conf::$operateType[$v['product']],
                    'operate_detail' => Comm_Utils::json_encode_ex($v['detailInfo']),
                    'packageid' => $v['packageid'],
                );
            }
            if (empty($paramIns)) {
                $this->msgInfo('1010');
                continue;
            }

            $rowcount = $this->dataObj->insDuplicateData($paramIns);
            if ($rowcount == 0) {
                continue;
            }

            $succNum++;
        }

        return $succNum;
    }

    /**
     * 拼接各种类型的优惠信息插入detail字段
     * @param type $list 原始数据
     * @param type $listPid package和pid对应关系
     * @return array
     */
    private function formatJson($list, $listPid) {
        $dictDetail = array();
        foreach ($list as $inform) {
            // 不是合法的优惠类型
            if (!array_key_exists($inform['product'], Setting_Conf::$operateType)) {
                $this->msgInfo('1007', $inform);
                continue;
            }
            // 不存在对应的包名
            if (!array_key_exists($inform['package'], $listPid)) {
                $this->msgInfo('2002', array($inform['package']), 1, 0);
                continue;
            }
            // 若docid为多个，则拆分成最小维度
            $arrDocid = explode(',', $inform['docid']);
            foreach ($arrDocid as $docid) {
                $ldkey = $inform['package'] . '_' . $inform['product'] . '_' . trim($docid);
                if (array_key_exists($ldkey, $dictDetail)) {
                    $detailInfo = $this->reNewDetail($inform, $dictDetail[$ldkey]['detailInfo']);
                } else {
                    $detailInfo = $this->newDetail($inform);
                }
                if (empty($detailInfo)) {
                    continue;
                }
                $dictDetail[$ldkey]['detailInfo'] = $detailInfo;
                $dictDetail[$ldkey]['package'] = $inform['package'];
                $dictDetail[$ldkey]['product'] = $inform['product'];
                $dictDetail[$ldkey]['docid'] = $docid;
                $dictDetail[$ldkey]['packageid'] = $listPid[$inform['package']];
            }
        }
        return $dictDetail;
    }

    /**
     * 新增-待插入detail字段
     * @param type $inform 当前优惠信息,最小维度（有docid则为docid维度，否则为pid维度）
     * @return array $detail
     */
    private function newDetail($inform) {
        $detail = array();
        // check info信息是否完整
        if (!$this->checkProductInfo($inform)) {
            return $detail;
        }
        if ($inform['product'] == 0) {
            $detail = array(
                'coupon_latesttime' => intval($inform['addtime']),
                'coupon' => array(array(
                        'coupon_region' => $inform['region'],
                        'coupon_addtime' => intval($inform['addtime']),
                        'coupon_info' => $inform['info'],
                    )),
            );
        } elseif ($inform['product'] == 1) {
            $detail = array(
                'lifeservice_latesttime' => intval($inform['addtime']),
                'lifeservice' => array(array(
                        'lifeservice_region' => $inform['region'],
                        'lifeservice_addtime' => intval($inform['addtime']),
                        'lifeservice_info' => $inform['info'],
                    )),
            );
        } elseif ($inform['product'] == 2) {
            $detail = array(
                'common_card' => array(array(
                        'common_card_type' => $inform['info']['common_card_type'],
                        'common_card_text' => $inform['info']['common_card_text'],
                        'common_card_tag' => $inform['info']['common_card_tag'],
                        'common_card_detail' => array(
                            'addtime' => $inform['addtime'],
                            'card_sort' => $inform['info']['card_sort'],
                            'region' => $inform['region'],
                        ),
                    )),
            );
        } elseif ($inform['product'] == 3) {
            $inform['info']['region'] = $inform['region'];
            $inform['info']['addtime'] = $inform['addtime'];
            $detail['inner_info'][] = $inform['info'];
        } elseif ($inform['product'] == 4) {
            $detail = array(
                'skin_latesttime' => intval($inform['addtime']),
                'skin' => array(array(
                        'skin_region' => $inform['region'],
                        'skin_addtime' => intval($inform['addtime']),
                        'skin_info' => $inform['info'],
                    )),
            );
        } elseif ($inform['product'] == 5) {
            $detail = array(
                'activity_latesttime' => intval($inform['addtime']),
                'activity' => array(array(
                        'activity_region' => $inform['region'],
                        'activity_addtime' => intval($inform['addtime']),
                        'activity_info' => $inform['info'],
                    )),
            );
        } elseif ($inform['product'] == 6) {
            $detail = array(
                'evaluate_latesttime' => intval($inform['addtime']),
                'evaluate' => array(array(
                        'evaluate_region' => $inform['region'],
                        'evaluate_addtime' => intval($inform['addtime']),
                        'evaluate_info' => $inform['info'],
                    )),
            );
        }
        return $detail;
    }

    /**
     * 追加-待插入detail字段
     * @param array $inform 当前优惠信息,最小维度（有docid则为docid维度，否则为pid维度）
     * @param array $detail 拼接好的当前待插入信息
     * @return array $ret
     */
    private function reNewDetail($inform, $detail) {

        // check info信息是否完整
        if (!$this->checkProductInfo($inform)) {
            return $detail;
        }
        if ($inform['product'] == 0) {
            $detail['coupon_latesttime'] = max(intval($inform['addtime']), $detail['coupon_latesttime']);
            $detail['coupon'][] = array(
                'coupon_region' => $inform['region'],
                'coupon_addtime' => intval($inform['addtime']),
                'coupon_info' => $inform['info'],
            );
        } elseif ($inform['product'] == 1) {
            $detail['lifeservice_latesttime'] = max(intval($inform['addtime']), $detail['lifeservice_latesttime']);
            $detail['lifeservice'][] = array(
                'lifeservice_region' => $inform['region'],
                'lifeservice_addtime' => intval($inform['addtime']),
                'lifeservice_info' => $inform['info'],
            );
        } elseif ($inform['product'] == 2) {
            $detail['common_card'][] = array(
                'common_card_type' => $inform['info']['common_card_type'],
                'common_card_text' => $inform['info']['common_card_text'],
                'common_card_tag' => $inform['info']['common_card_tag'],
                'common_card_detail' => array(
                    'addtime' => $inform['addtime'],
                    'card_sort' => $inform['info']['card_sort'],
                    'region' => $inform['region'],
                ),
            );
        } elseif ($inform['product'] == 3) {
            $inform['info']['region'] = $inform['region'];
            $inform['info']['addtime'] = $inform['addtime'];
            $detail['inner_info'][] = $inform['info'];
        } elseif ($inform['product'] == 4) {
            $detail['skin_latesttime'] = max(intval($inform['addtime']), $detail['skin_latesttime']);
            $detail['skin'][] = array(
                'skin_region' => $inform['region'],
                'skin_addtime' => intval($inform['addtime']),
                'skin_info' => $inform['info'],
            );
        } elseif ($inform['product'] == 5) {
            $detail['activity_latesttime'] = max(intval($inform['addtime']), $detail['activity_latesttime']);
            $detail['activity'][] = array(
                'activity_region' => $inform['region'],
                'activity_addtime' => intval($inform['addtime']),
                'activity_info' => $inform['info'],
            );
        } elseif ($inform['product'] == 6) {
            $detail['evaluate_latesttime'] = max(intval($inform['addtime']), $detail['evaluate_latesttime']);
            $detail['evaluate'][] = array(
                'evaluate_region' => $inform['region'],
                'evaluate_addtime' => intval($inform['addtime']),
                'evaluate_info' => $inform['info'],
            );
        }
        return $detail;
    }

    /**
     * check待插入detail字段是否完整
     * @param array $inform 当前优惠信息,最小维度（有docid则为docid维度，否则为pid维度）
     * @return array $ret
     */
    private function checkProductInfo($inform) {

        $ret = 0;

        // 不需要check info内容
        if (Setting_Conf::$appshowInfoKey['switch'] == 0) {
            return $ret;
        }

        if (!isset($inform['info']) || empty($inform['info'])) {
            $this->msgInfo('1008', $inform['info']);
            return $ret;
        }

        if (!array_key_exists($inform['product'], Setting_Conf::$appshowInfoKey)) {
            return $ret;
        }

        foreach (Setting_Conf::$appshowInfoKey[$inform['product']] as $v) {
            if (!array_key_exists($v, $inform['info'])) {
                $this->msgInfo('1009', array('product' => $inform['product'], $v => '字段缺失'));
                return $ret;
            }
        }

        return $ret = 1;
    }

}
