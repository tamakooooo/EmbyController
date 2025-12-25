<?php

namespace app\media\controller;

use app\media\model\MediaHistoryModel;
use app\BaseController;
use app\media\model\EmbyDeviceModel;
use app\media\model\EmbyUserModel as EmbyUserModel;
use app\media\model\ExchangeCodeModel;
use app\media\model\FinanceRecordModel;
use app\media\model\PayRecordModel;
use app\media\model\SysConfigModel as SysConfigModel;
use mailer\Mailer;
use Symfony\Component\VarDumper\Cloner\Data;
use think\facade\Request;
use think\facade\Session;
use app\media\model\UserModel as UserModel;
use app\media\validate\Login as LoginValidate;
use app\media\validate\Register as RegisterValidate;
use think\facade\View;
use think\facade\Config;
use think\facade\Cache;


class Server extends BaseController
{
    private $lifetimecost = 999;
    private $lifetimeauthority = 101;
    
    // 续期配置（默认值，实际值从数据库读取）
    private $renewCost = 10;        // 续期费用（RCoin）
    private $renewDays = 30;        // 续期时长（天）
    private $renewSeconds = 2592000; // 续期时长（秒），30天 = 2592000秒

    public function __construct()
    {
        parent::__construct();
        $this->loadRenewConfig();
    }

    /**
     * 从数据库加载续期配置
     */
    private function loadRenewConfig()
    {
        $sysConfigModel = new SysConfigModel();
        
        // 读取续期费用
        $renewCostConfig = $sysConfigModel->where('key', 'renewCost')->find();
        if ($renewCostConfig && $renewCostConfig['value']) {
            $this->renewCost = intval($renewCostConfig['value']);
        }
        
        // 读取续期天数
        $renewDaysConfig = $sysConfigModel->where('key', 'renewDays')->find();
        if ($renewDaysConfig && $renewDaysConfig['value']) {
            $this->renewDays = intval($renewDaysConfig['value']);
            $this->renewSeconds = $this->renewDays * 86400;
        }
    }

    public function index()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        return view();
    }

    public function changeTo()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Session::get('r_user')->authority == 0) {
            $data = Request::get();
            if (isset($data['userId'])) {
                $userModel = new UserModel();
                $user = $userModel->where('id', $data['userId'])->find();
                if ($user) {
                    Session::set('r_user', $user);
                    return redirect('/media/user/index');
                }
            } else if (isset($data['UserId'])) {
                $userModel = new UserModel();
                $user = $userModel->where('id', $data['UserId'])->find();
                if ($user) {
                    Session::set('r_user', $user);
                    return redirect('/media/user/index');
                }
            }
        }
    }

    public function account()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        View::assign('lifetimecost', $this->lifetimecost);
        View::assign('lifetimeauthority', $this->lifetimeauthority);
        // 传递续期配置到视图
        View::assign('renewCost', $this->renewCost);
        View::assign('renewDays', $this->renewDays);
        $userModel = new UserModel();
        $userFromDatabase = $userModel->where('id', Session::get('r_user')->id)->find();
        $userFromDatabase['password'] = null;
        $embyUserModel = new EmbyUserModel();
        $embyUserFromDatabase = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
        $userInfoArray = json_decode(json_encode($embyUserFromDatabase->userInfo), true);
        if (isset($userInfoArray['autoRenew'])) {
            $autoRenew = $userInfoArray['autoRenew'];
        } else {
            $autoRenew = 0;
        }
        if ($embyUserFromDatabase && $embyUserFromDatabase['embyId'] != null) {
            $embyId = $embyUserFromDatabase['embyId'];
            $activateTo = $embyUserFromDatabase['activateTo'];
            $url = Config::get('media.urlBase') . 'Users/' . $embyId . '?api_key=' . Config::get('media.apiKey');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json'
            ]);
            $embyUserFromEmby = json_decode(curl_exec($ch));
        } else {
            $embyUserFromEmby = null;
            $activateTo = null;
        }
        View::assign('userFromDatabase', $userFromDatabase);
        View::assign('embyUserFromDatabase', $embyUserFromDatabase);
        View::assign('embyUserFromEmby', $embyUserFromEmby);
        View::assign('autoRenew', $autoRenew);
        View::assign('activateTo', $activateTo);
        return view();
    }

    public function changePassword()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            if (isset($data['password']) && $data['password'] != '') {
                $embyUserModel = new EmbyUserModel();
                $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
                if (isset($user->embyId)) {
                    $url = Config::get('media.urlBase') . 'Users/' . $user->embyId . '/Password?api_key=' . Config::get('media.apiKey');
                    $data = [
                        'Id' => $user->embyId,
                        'NewPw' => $data['password'],
//                        'ResetPassword' => true
                    ];
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'accept: application/json',
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    $response = curl_exec($ch);
                    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 || curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
                        return json(['code' => 200, 'message' => '修改成功']);
                    } else {
                        return json(['code' => 400, 'message' => $response]);
                    }
                } else {
                    return json(['code' => 400, 'message' => '请先创建Emby账号']);
                }
            } else {
                return json(['code' => 400, 'message' => '密码不能为空']);
            }
        } else if (Request::isGet()) {
            return view();
        }
    }

    public function create()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        $embyUserModel = new EmbyUserModel();
        $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
        if (isset($user->embyId)) {
            return redirect((string) url('/media/server/account'));
        }
        if (Request::isPost()) {
            $data = Request::post();
            $embyUserName = $data['embyUserName'];
            $url = Config::get('media.urlBase') . 'Users/New?api_key=' . Config::get('media.apiKey');
            $data = [
                'Name' => $embyUserName,
                'CopyFromUserId' => Config::get('media.UserTemplateId'),
                'UserCopyOptions' => [
                    'UserPolicy',
                    'UserConfiguration'
                ]
            ];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $response = curl_exec($ch);
            // 如果是400错误，说明用户名已存在
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 400) {
                return json(['code' => 400, 'message' => '用户名已存在']);
            } else {
                $embyUserId = json_decode($response, true)['Id'];
                $embyUserModel = new EmbyUserModel();
                $embyUserModel->save([
                    'userId' => Session::get('r_user')->id,
                    'embyId' => $embyUserId,
                ]);
                $embyUser = $embyUserId;

                $url = Config::get('media.urlBase') . 'Users/' . $embyUserId . '/Policy?api_key=' . Config::get('media.apiKey');
                $data = ['IsDisabled' => true];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: */*',
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

                curl_exec($ch);

                Session::set('m_embyId', $embyUserId);

                return json(['code' => 200, 'message' => '创建成功']);
            }
        } else if (Request::isGet()) {
            return view();
        }
    }

    public function servers()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }

        if (Cache::get('serverList')) {
            View::assign('serverList', Cache::get('serverList'));
            return view();
        }

        $serverList = [];
        $lineList = Config::get('media.lineList');
        foreach ($lineList as $line) {
            $url = $line['url'] . '/emby/System/Ping?api_key=' . Config::get('media.apiKey');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: */*'
            ]);
            $response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                $status = 1;
            } else {
                $status = 0;
            }
            $serverList[] = [
                'name' => $line['name'],
                'url' => $line['url'],
                'status' => $status
            ];
        }

        // 将serverList保存到缓存中
        Cache::set('serverList', $serverList, 1200);

        View::assign('serverList', $serverList);

        return view();
    }

    public function session()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }
        if (Request::isPost()) {
            $embyUserModel = new EmbyUserModel();
            $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
            if (isset($user->embyId)) {

                if (Cache::get('sessionList-' . Session::get('r_user')->id)) {
                    $sessionList = Cache::get('sessionList-' . Session::get('r_user')->id);
                    return json(['code' => 200, 'message' => '获取成功', 'data' => $sessionList]);
                }
                $url = Config::get('media.urlBase') . 'Sessions?api_key=' . Config::get('media.apiKey');
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: application/json'
                ]);
                $response = curl_exec($ch);
                $allSessionList = json_decode($response, true);
                $sessionList = [];
                foreach ($allSessionList as $session) {
                    if (isset($session['UserId']) && $session['UserId'] == $user->embyId) {
                        $sessionList[] = $session;
                    }
                }

                Cache::set('sessionList-' . Session::get('r_user')->id, $sessionList, 10);
            } else {
                $sessionList = null;
            }

            return json(['code' => 200, 'message' => '获取成功', 'data' => $sessionList]);
        }
//        $embyUserModel = new EmbyUserModel();
//        $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
//        if (isset($user->embyId)) {
//            $url = Config::get('media.urlBase') . 'Sessions?api_key=' . Config::get('media.apiKey');
//            $ch = curl_init($url);
//            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
//            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//            curl_setopt($ch, CURLOPT_HTTPHEADER, [
//                'accept: application/json'
//            ]);
//            $response = curl_exec($ch);
//            $allSessionList = json_decode($response, true);
//            $sessionList = [];
//            foreach ($allSessionList as $session) {
//                if (isset($session['UserId']) && $session['UserId'] == $user->embyId) {
//                    $sessionList[] = $session;
//                }
//            }
//
//            View::assign('sessionList', $sessionList);
//        } else {
//            $sessionList = null;
//        }
//
//        View::assign('sessionList', $sessionList);
//        return view();
    }

    public function devices()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }

        $sysConfigModel = new SysConfigModel();
        $sysConfig = $sysConfigModel->where('key', 'maxActiveDeviceCount')->find();
        if ($sysConfig) {
            $maxActiveDeviceCount = $sysConfig->value;
        } else {
            $maxActiveDeviceCount = 0;
        }

        // 获取白名单和黑名单配置
        $clientListConfig = $sysConfigModel->where('key', 'clientList')->find();
        $clientList = $clientListConfig ? json_decode($clientListConfig['value'], true) : [];

        $clientBlackListConfig = $sysConfigModel->where('key', 'clientBlackList')->find();
        $clientBlackList = $clientBlackListConfig ? json_decode($clientBlackListConfig['value'], true) : [];

        $embyUserModel = new EmbyUserModel();
        $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();

        if ($user) {
            $embyDeviceModel = new EmbyDeviceModel();
            $deviceList = $embyDeviceModel
                ->where('embyId', $user->embyId)
                ->where('deactivate', 'in', [0, null])
                ->order('lastUsedTime', 'desc')
                ->select();
        } else {
            $deviceList = null;
        }

        View::assign('maxActiveDeviceCount', $maxActiveDeviceCount);
        View::assign('deviceList', $deviceList);
        View::assign('clientList', $clientList);
        View::assign('clientBlackList', $clientBlackList);
        return view();
    }

    public function deletedevice()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }
        if (Request::isPost()) {
            $data = Request::post();
            $deviceId = $data['deviceId'];

            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', Session::get('r_user')->id)->find();

            // Debugging output
            if (!$embyUser) {
                return json(['code' => 400, 'message' => '用户不存在', 'userId' => Session::get('r_user')->id]);
            }


            $embyDeviceModel = new EmbyDeviceModel();
            $device = $embyDeviceModel
                ->where('deviceId', $deviceId)
                ->where('deactivate', 'in', [0, null])
                ->where('embyId', $embyUser->embyId)
                ->find();

            if (!$device) {
                return json(['code' => 400, 'message' => '设备不存在或者你没有设备所有权', 'deviceId' => $deviceId]);
            }
            $url = Config::get('media.urlBase') . 'Devices/Delete?api_key=' . Config::get('media.apiKey');
            $data = [
                'Id' => $deviceId
            ];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 || curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
                $embyDeviceModel
                    ->where('deviceId', $deviceId)
                    ->update([
                        'deactivate' => 1
                    ]);
                return json(['code' => 200, 'message' => '删除成功']);
            } else {
                return json(['code' => 400, 'message' => $response]);
            }
        }
    }

    public function getItemsByIds()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }
        if (Request::isPost()) {
            $data = Request::post();
            $ids = $data['ids'];
            $url = Config::get('media.urlBase') . 'Items?Ids=' . join(',', $ids) . '&EnableImages=true&&api_key=' . Config::get('media.apiKey');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json'
            ]);
            $response = curl_exec($ch);
            return json(['code' => 200, 'message' => '获取成功', 'data' => json_decode($response, true)]);
        }
    }

    public function viewList()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        $embyUserModel = new EmbyUserModel();
        $user = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
        if (isset($user->embyId)) {
            $url = Config::get('media.urlBase') . 'Users/' . $user->embyId . '/Views?IncludeExternalContent=true&api_key=' . Config::get('media.apiKey');
//            $url = Config::get('media.urlBase') . 'Shows/NextUp?UserId=' . $user->embyId . '&api_key=' . Config::get('media.apiKey');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json'
            ]);
            $response = curl_exec($ch);
            $viewList = json_decode($response, true);
            View::assign('viewList', $viewList);
        } else {
            $viewList = null;
        }
        echo $response;
//        echo json_encode($viewList);
        die();
        View::assign('viewList', $viewList);
        return view();
    }

    public function setAutoRenew()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
            $userInfoArray = json_decode(json_encode($embyUser->userInfo), true);
            $userInfoArray['autoRenew'] = $data['autoRenew'];
            $embyUser->userInfo = $userInfoArray;
            $embyUser->save();

            $financeRecordModel = new FinanceRecordModel();
            $financeRecordModel->save([
                'userId' => Session::get('r_user')->id,
                'action' => 5,
                'count' => $data['autoRenew'],
                'recordInfo' => [
                    'message' => '设置自动续期Emby账号状态为' . ($data['autoRenew']==1?'开启':'关闭')
                ]
            ]);

            sendTGMessage(Session::get('r_user')->id, '您的Emby账号自动续期状态已设置为 <strong>' . ($data['autoRenew']==1?'开启':'关闭') . '</strong>');

            return json(['code' => 200, 'message' => '设置成功']);
        }
    }

    public function activateEmbyUserByBalance()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
            $embyUserId = $embyUser->embyId;
            $userModel = new UserModel();
            $user = $userModel->where('id', Session::get('r_user')->id)->find();
            if ($user->rCoin >= 1 && $user->authority >= 0) {
                $url = Config::get('media.urlBase') . 'Users/' . $embyUserId . '/Policy?api_key=' . Config::get('media.apiKey');
                $profile = $this->getTmpUserProfile();
                $profile['IsDisabled'] = false;
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: */*',
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($profile));
                $response = curl_exec($ch);
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 || curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
                    $activateTo = date('Y-m-d H:i:s', time() + 86400);
                    $embyUser->activateTo = $activateTo;
                    $embyUser->save();
                    $user->rCoin = $user->rCoin - 1;
                    $user->save();
                    $financeRecordModel = new FinanceRecordModel();
                    $financeRecordModel->save([
                        'userId' => Session::get('r_user')->id,
                        'action' => 3,
                        'count' => 1,
                        'recordInfo' => [
                            'message' => '使用余额激活Emby账号'
                        ]
                    ]);

                    sendTGMessage(Session::get('r_user')->id, '您的Emby账号已激活');

                    // 更新Session
                    $r_user = Session::get('r_user');
                    $r_user->rCoin = $user->rCoin;
                    Session::set('r_user', $r_user);
                    return json([
                        'code' => 200,
                        'message' => '激活成功'
                    ]);
                } else {
                    return json([
                        'code' => 400,
                        'message' => $response
                    ]);
                }
            } else {
                return json([
                    'code' => 400,
                    'message' => '余额不足'
                ]);
            }
        }
    }


    public function activateEmbyUserByCode()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $code = $data['code'];
            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
            $embyUserId = $embyUser->embyId;
            $exchangeCodeModel = new ExchangeCodeModel();
            $exchangeCode = $exchangeCodeModel->where('code', $code)->find();
            if ($exchangeCode && $exchangeCode['type'] == 0 && $exchangeCode['exchangeType'] == 1) {
                $url = Config::get('media.urlBase') . 'Users/' . $embyUserId . '/Policy?api_key=' . Config::get('media.apiKey');
                $profile = $this->getTmpUserProfile();
                $profile['IsDisabled'] = false;
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: */*',
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($profile));
                $response = curl_exec($ch);
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 || curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
                    $activateTo = date('Y-m-d H:i:s', time() + 86400);
                    $embyUser->activateTo = $activateTo;
                    $embyUser->save();
                    $exchangeCode->type = 1;
                    $exchangeCode->usedByUserId = Session::get('r_user')->id;
                    $exchangeCode['exchangeDate'] = date('Y-m-d H:i:s', time());
                    $exchangeCode->save();
                    $financeRecordModel = new FinanceRecordModel();
                    $financeRecordModel->save([
                        'userId' => Session::get('r_user')->id,
                        'action' => 2,
                        'count' => $code,
                        'recordInfo' => [
                            'message' => '使用兑换码' . $code . '激活Emby账号'
                        ]
                    ]);
                    sendTGMessage(Session::get('r_user')->id, '您的Emby账号已激活');
                    return json([
                        'code' => 200,
                        'message' => '激活成功'
                    ]);
                }
            } else {
                return json([
                    'code' => 400,
                    'message' => '无效的兑换码'
                ]);
            }
        }
    }

    public function continueSubscribeEmbyUserByBalance()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $userModel = new UserModel();
            $user = $userModel->where('id', Session::get('r_user')->id)->find();
            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
            // 如果用户余额大于等于续期费用
            if ($user->rCoin >= $this->renewCost) {
                $activateTo = $embyUser['activateTo'];
                if ($activateTo == null) {
                    return json([
                        'code' => 400,
                        'message' => 'LifeTime用户无需续期'
                    ]);
                }
                if (strtotime($activateTo) > time()) {
                    $activateTo = date('Y-m-d H:i:s', strtotime($activateTo) + $this->renewSeconds);
                } else {
                    $activateTo = date('Y-m-d H:i:s', time() + $this->renewSeconds);
                }
                $embyUser->activateTo = $activateTo;
                $embyUser->save();
                $user->rCoin = $user->rCoin - $this->renewCost;
                $user->save();
                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => Session::get('r_user')->id,
                    'action' => 3,
                    'count' => $this->renewCost,
                    'recordInfo' => [
                        'message' => '使用余额续期Emby账号'
                    ]
                ]);
                sendTGMessage(Session::get('r_user')->id, '您的Emby账号已续期至 <strong>' . $activateTo . '</strong>');
                // 更新Session
                $r_user = Session::get('r_user');
                $r_user->rCoin = $user->rCoin;
                Session::set('r_user', $r_user);
                return json([
                    'code' => 200,
                    'message' => '续期成功'
                ]);
            } else {
                return json([
                    'code' => 400,
                    'message' => '余额不足'
                ]);
            }
        }
    }

    public function continueSubscribeEmbyUserByCode()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $code = $data['code'];
            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
            $embyUserId = $embyUser->embyId;
            $exchangeCodeModel = new ExchangeCodeModel();
            $exchangeCode = $exchangeCodeModel->where('code', $code)->find();
            if ($exchangeCode && $exchangeCode['type'] == 0 && ($exchangeCode['exchangeType'] == 2 || $exchangeCode['exchangeType'] == 3)) {
                $activateTo = $embyUser['activateTo'];
                if ($activateTo == null) {
                    return json([
                        'code' => 400,
                        'message' => 'LifeTime用户无需续期'
                    ]);
                }
                $seconds = $exchangeCode['exchangeType']==2?(86400*$exchangeCode['exchangeCount']):(2592000*$exchangeCode['exchangeCount']);
                if (strtotime($activateTo) > time()) {
                    $activateTo = date('Y-m-d H:i:s', strtotime($activateTo) + $seconds);
                } else {
                    $activateTo = date('Y-m-d H:i:s', time() + $seconds);
                }
                $embyUser->activateTo = $activateTo;
                $embyUser->save();
                $exchangeCode->type = 1;
                $exchangeCode->usedByUserId = Session::get('r_user')->id;
                $exchangeCode['exchangeDate'] = date('Y-m-d H:i:s', time());
                $exchangeCode->save();
                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => Session::get('r_user')->id,
                    'action' => 2,
                    'count' => $code,
                    'recordInfo' => [
                        'message' => '使用兑换码' . $code . '续期Emby账号'
                    ]
                ]);
                sendTGMessage(Session::get('r_user')->id, '您的Emby账号已续期至 <strong>' . $activateTo . '</strong>');

                return json([
                    'code' => 200,
                    'message' => '续期成功'
                ]);
            } else {
                return json([
                    'code' => 400,
                    'message' => '无效的兑换码'
                ]);
            }
        }
    }

    public function continueSubscribeEmbyUserToLifetimeByRCoin()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $userModel = new UserModel();
            $user = $userModel->where('id', Session::get('r_user')->id)->find();
            if ($user->authority != 0 && $user->authority < $this->lifetimeauthority) {
                return json([
                    'code' => 400,
                    'message' => '您没有权限'
                ]);
            }
            $embyUserModel = new EmbyUserModel();
            $embyUser = $embyUserModel->where('userId', Session::get('r_user')->id)->find();
            if ($embyUser->activateTo == null) {
                return json([
                    'code' => 400,
                    'message' => 'LifeTime用户无需续期'
                ]);
            }
            if ($embyUser->activateTo < date('Y-m-d H:i:s', time())) {
                return json([
                    'code' => 400,
                    'message' => '用户已过期，请先激活至未过期'
                ]);
            }

            if ($user->rCoin >= $this->lifetimecost) {
                $embyUser->activateTo = null;
                $embyUser->save();
                $user->rCoin = $user->rCoin - $this->lifetimecost;
                $user->save();
                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => Session::get('r_user')->id,
                    'action' => 3,
                    'count' => $this->lifetimecost,
                    'recordInfo' => [
                        'message' => '使用余额续期Emby账号至终身'
                    ]
                ]);
                sendTGMessage(Session::get('r_user')->id, '您的Emby账号已续期至终身');
                $poems = [
                    "明月松间照，清泉石上流。",
                    "千里江陵一日还，弱水三千只取一瓢饮。",
                    "落霞与孤鹜齐飞，秋水共长天一色。",
                    "欲穷千里目，更上一层楼。",
                    "寒山转苍翠，秋水日潺湲。",
                    "疏影横斜水清浅，暗香浮动月黄昏。",
                    "白云千载空悠悠，青枫浦上不胜愁。",
                    "孤舟蓑笠翁，独钓寒江雪。",
                    "天姥连天向天横，势拔五岳掩赤城。",
                    "洞庭青草，近中秋，更无一点风色。"
                ];
                $randomPoem = $poems[array_rand($poems)];
                sendTGMessageToGroup($randomPoem . PHP_EOL . PHP_EOL . '🎉 恭喜 <strong>' . (Session::get('r_user')->nickName??Session::get('r_user')->userName) . '</strong> 获得' . Config::get('app.app_name') . ' Lifetime ！');
                // 更新Session
                $r_user = Session::get('r_user');
                $r_user->rCoin = $user->rCoin;
                Session::set('r_user', $r_user);
                return json([
                    'code' => 200,
                    'message' => '续期成功'
                ]);
            } else {
                return json([
                    'code' => 400,
                    'message' => '余额不足'
                ]);
            }
        }
    }

    public function exchangeCode()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            $code = $data['code'];
            $exchangeCodeModel = new ExchangeCodeModel();
            $exchangeCode = $exchangeCodeModel->where('code', $code)->find();
            if ($exchangeCode && $exchangeCode['type'] == 0 && $exchangeCode['exchangeType'] == 4) {
                $exchangeCode->type = 1;
                $exchangeCode->usedByUserId = Session::get('r_user')->id;
                $exchangeCount = $exchangeCode['exchangeCount'];
                $exchangeCode['exchangeDate'] = date('Y-m-d H:i:s', time());
                $exchangeCode->save();

                $userModel = new UserModel();
                $user = $userModel->where('id', Session::get('r_user')->id)->find();
                $rCoin = $user->rCoin + $exchangeCount;
                // $rCoin转换为double类型数据存入数据库
                $rCoin = sprintf("%.2f", $rCoin);
                $user->rCoin = $rCoin;
                $user->save();

                // 添加充值记录
                $financeRecordModel = new FinanceRecordModel();
                $financeRecordModel->save([
                    'userId' => Session::get('r_user')->id,
                    'action' => 2,
                    'count' => $code,
                    'recordInfo' => [
                        'message' => '使用兑换码' . $code . '充值' . $exchangeCount . 'R币'
                    ]
                ]);

                sendTGMessage(Session::get('r_user')->id, '您已经成功兑换了 <strong>' . $exchangeCount . '</strong> R币，当前余额为 <strong>' . $rCoin . '</strong>');

                // 更新Session
                $r_user = Session::get('r_user');
                $r_user->rCoin = $user->rCoin;
                Session::set('r_user', $r_user);

                return json([
                    'code' => 200,
                    'message' => '兑换成功',
                    'rCoin' => $rCoin
                ]);
            } else {
                return json([
                    'code' => 400,
                    'message' => '无效的兑换码，请检查兑换码和其类型是否正确，或者兑换码是否已被使用'
                ]);
            }
        }
    }


    public function crontab()
    {
        // 获取get参数
        $data = Request::get();
        // 判断是否有参数
        if (isset($data['crontabkey']) && $data['crontabkey'] == Config::get('media.crontabKey')) {
            $actionCount = 0;
            $finishCount = 0;
            $errorCount = 0;
            $errorList = [];

            // 任务1: 刷新线路状态
            try {
                $actionCount++;
                $serverList = [];
                $lineList = Config::get('media.lineList');
                foreach ($lineList as $line) {
                    $url = $line['url'] . '/emby/System/Ping?api_key=' . Config::get('media.apiKey');
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'accept: */*'
                    ]);
                    $response = curl_exec($ch);
                    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 && $response == 'Emby Server') {
                        $status = 1;
                    } else {
                        $status = 0;
                    }
                    $serverList[] = [
                        'name' => $line['name'],
                        'url' => $line['url'],
                        'status' => $status
                    ];
                }
                // 将serverList保存到缓存中
                Cache::set('serverList', $serverList, 600);
                $finishCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $errorList[] = [
                    'action' => '刷新线路状态',
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ];
            }

            // 任务2 生成播放日报
//            try{
//                // 如果有缓存
//                if (!Cache::get('playDailyReport-'.date('Y-m-d'))) {
//                    // 如果是晚上8点到8点十分
//                    if (date('H') == 20 && date('i') == 0) {
//                        $this->generatePlayDailyReport();
//                    }
//                }
//
//
//            } catch (\Exception $e) {
//                $errorCount++;
//                $errorList[] = [
//                    'action' => '生成播放日报',
//                    'message' => $e->getMessage(),
//                    'line' => $e->getLine(),
//                ];
//            }


            if ($actionCount == $finishCount) {
                return json([
                    'code' => 200,
                    'message' => '执行成功',
                    'finishCount' => $finishCount
                ]);
            } else if ($actionCount > $finishCount && $finishCount != 0) {
                return json([
                    'code' => 200,
                    'message' => '部分执行成功',
                    'errorCount' => $errorCount,
                    'errorList' => $errorList
                ]);
            } else {
                return json([
                    'code' => 400,
                    'message' => '执行失败',
                    'errorCount' => $errorCount,
                    'errorList' => $errorList
                ]);
            }
        } else {
            return json([
                'code' => 400,
                'message' => '无效的key'
            ]);
        }
    }
    public function resolvePayment()
    {
        if (Request::isGet()) {
            $rate = 1;
            $key = Request::get('key');
            $PayRecordModel = new PayRecordModel();
            $payRecord = $PayRecordModel
                ->where('payCompleteKey', $key)
//                ->where('type', 1)
                ->find();
            if ($payRecord && $payRecord['type'] == 1) {
                $tradeNo = $payRecord['tradeNo'];
                // api.php?act=order&pid={商户ID}&key={商户密钥}&out_trade_no={商户订单号}
                $url = Config::get('payment.epay.urlBase') . 'api.php?act=order&pid=' . Config::get('payment.epay.id') . '&key=' . Config::get('payment.epay.key') . '&out_trade_no=' . $tradeNo;
                $respond = getHttpResponse($url);
                $respond = json_decode($respond, true);
                if ($respond['code'] == 1 && $respond['status'] == 1) {
                    $payRecordInfo = json_decode(json_encode($payRecord['payRecordInfo']), true);
                    $commodity = $payRecordInfo['commodity'];
                    $unit = $payRecordInfo['unit'];
                    $count = $payRecordInfo['count'];
                    $payRecord->type = 2;
                    $payRecord->save();
                    if ($commodity == 'R币充值') {
                        $userModel = new UserModel();
                        $user = $userModel->where('id', $payRecord['userId'])->find();
                        $sysConfigModel = new SysConfigModel();
                        $rateConfig = $sysConfigModel->where('key', 'chargeRate')->find();
                        if ($rateConfig) {
                            $rate = $rateConfig['value'];
                        }
                        $increase = ceil($count*$rate*100)/100;
                        $rCoin = $user->rCoin + $increase;
                        // $rCoin转换为double类型数据存入数据库
                        $rCoin = sprintf("%.2f", $rCoin);
                        $user->rCoin = $rCoin;
                        $user->save();
                        $financeRecordModel = new FinanceRecordModel();
                        $financeRecordModel->save([
                            'userId' => $payRecord['userId'],
                            'action' => 1,
                            'count' => $count,
                            'recordInfo' => [
                                'message' => '使用支付宝支付' . $count . '元充值' . $increase . 'R币' . ($rate!=1?'(其中包含限时优惠赠送' . ($increase-$count) . 'R币)':'')
                            ]
                        ]);
                        sendTGMessage($payRecord['userId'], '您已经成功充值了 <strong>' . $count . '</strong> 元，获得 <strong>' . $increase . '</strong> R币，当前余额为 <strong>' . $rCoin . '</strong>');
                        $money = $payRecord['money'];
                        $userModel = new UserModel();
                        $user = $userModel->where('id', $payRecord['userId'])->find();

                        $mediaMaturityTemplate = '您的账单已经支付成功，您购买的商品为：' . $commodity . '金额：¥ ' . $money . '感谢您的支持';

                        // 发送邮件

                        if ($user && $user['email']) {

                            $sendFlag = true;

                            if ($user['userInfo']) {
                                $userInfo = json_decode(json_encode($user['userInfo']), true);
                                if (isset($userInfo['banEmail']) && $userInfo['banEmail'] == 1) {
                                    $sendFlag = false;
                                }
                            }

                            if ($sendFlag) {
                                $Email = $user['email'];
                                $SiteUrl = Config::get('app.app_host').'/media';

                                $sysConfigModel = new \app\admin\model\SysConfigModel();
                                $sysnotificiations = $sysConfigModel->where('key', 'sysnotificiations')->find();
                                if ($sysnotificiations) {
                                    $sysnotificiations = $sysnotificiations['value'];
                                } else {
                                    $sysnotificiations = '您有一条新消息：{Message}';
                                }

                                $sysnotificiations = str_replace('{Message}', $mediaMaturityTemplate, $sysnotificiations);
                                $sysnotificiations = str_replace('{Email}', $Email, $sysnotificiations);
                                $sysnotificiations = str_replace('{SiteUrl}', $SiteUrl, $sysnotificiations);

                                \think\facade\Queue::push('app\api\job\SendMailMessage', [
                                    'to' => $user['email'],
                                    'subject' => '账单支付成功 - ' . Config::get('app.app_name'),
                                    'content' => $sysnotificiations,
                                    'isHtml' => true
                                ], 'main');
                            }
                        }

                        return "success";
                    }

                } else {
                    return json([
                        'code' => 400,
                        'message' => '支付失败'
                    ]);
                }
            } else if ($payRecord && $payRecord['type'] == 2) {
                return "success";
            } else {
                return json([
                    'code' => 400,
                    'message' => '支付失败'
                ]);
            }
        }
    }

    public function pay()
    {
        if (Session::get('r_user') == null) {
            $url = Request::url(true);
            Session::set('jump_url', $url);
            return redirect('/media/user/login');
        }
        if (Request::isPost()) {
            $data = Request::post();
            // 检测$data['money']是否为数字，并且最多有两位小数
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $data['money']) || $data['money'] <= 0) {
                return json([
                    'code' => 400,
                    'message' => '请输入正确的金额'
                ]);
            }
            $payMethod = 'alipay';
            $chanel = 'epay';
            if (isset($data['method'])) {
                if ($data['method'] == 'usdt' || $data['method'] == 'trx') {
                    $chanel = 'usdt';
                } else {
                    $availablePayMethod = Config::get('payment.epay.availablePayment');
                    if (in_array($data['method'], $availablePayMethod)) {
                        $payMethod = $data['method'];
                    }
                }
            }
            $tradeNo = time() . random_int(1000, 9999);
            $payCompleteKey = generateRandomString();

            $realIp = getRealIp();

            $url = '';
            $sendData = [];
            if ($chanel == 'epay') {
                $url = Config::get('payment.epay.urlBase') . 'mapi.php';
                $sendData = [
                    'pid' => Config::get('payment.epay.id'),
                    'type' => $payMethod,
                    'out_trade_no' => $tradeNo,
                    'notify_url' => Config::get('app.app_host') . '/media/server/resolvePayment?key=' . $payCompleteKey,
                    'return_url' => Config::get('app.app_host') . '/media/server/account',
                    'name' => 'R币充值',
                    'money' => $data['money'],
                    'clientip' => $realIp,
                    'sign' => '',
                    'sign_type' => 'MD5'
                ];
                $sendData['sign'] = getPaySign($sendData);
            } else if ($chanel == 'usdt') {
                $url = Config::get('payment.usdt.urlBase') . 'api/v1/order/create-transaction';
                $sendData = [
                    'trade_type' => $data['method']=='usdt'?'usdt.trc20':'tron.trx',
                    'order_id' => $tradeNo,
                    'amount' => $data['money'],
                    'signature' => '',
                    'notify_url' => Config::get('app.app_host') . '/media/server/resolveUsdtPayment?key=' . $payCompleteKey,
                    'redirect_url' => Config::get('app.app_host') . '/media/server/account'
                ];
            }
            $respond = getHttpResponse($url, $sendData);

            if ($respond == '' || (isset(json_decode($respond, true)['code']) && json_decode($respond, true)['code'] == -1)) {
                return json([
                    'code' => 400,
                    'message' => json_decode($respond, true)['msg']??'请求支付二维码失败',
                    'original' => $respond
                ]);
            } else {
                $jsonRespond = json_decode($respond, true);
                if ((isset($jsonRespond['code']) && $jsonRespond['code'] == -1) || (!isset($jsonRespond['code'])) ) {
                    return json([
                        'code' => 400,
                        'message' => $jsonRespond['msg']??'请求支付二维码失败',
                        'original' => $respond
                    ]);
                }
            }

            $respond = json_decode($respond, true);
            if (isset($respond['qrcode']) || isset($respond['payurl'])) {
                $payUrl = $respond['qrcode']??$respond['payurl'];
            } else {
                return json([
                    'code' => 400,
                    'message' => '请求支付二维码失败',
                    'original' => $respond
                ]);
            }

            $PayRecordModel = new PayRecordModel();
            $PayRecordModel->save([
                'payCompleteKey' => $payCompleteKey,
                'type' => 1,
                'userId' => Session::get('r_user')->id,
                'tradeNo' => $tradeNo,
                'name' => 'R币充值',
                'money' => $data['money'],
                'clientip' => $realIp,
                'payRecordInfo' => json_encode([
                    'commodity' => 'R币充值',
                    'unit' => 'money',
                    'count' => $data['money'],
                    'payUrl' => $payUrl,
                    'payMethod' => $payMethod,
                ])
            ]);

            return json([
                'code' => 200,
                'message' => '请求支付二维码成功，请扫码支付',
                'qrcodeUrl' => $payUrl,
                'method' => $payMethod
            ]);
        }
    }

    public function getTmpUserProfile()
    {
        $embyId = Config::get('media.UserTemplateId');
        $url = Config::get('media.urlBase') . 'Users/' . $embyId . '?api_key=' . Config::get('media.apiKey');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json'
        ]);
        $response = curl_exec($ch);

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
            $userFromEmby = json_decode($response, true);
            if (isset($userFromEmby['Policy'])) {
                return $userFromEmby['Policy'];
            }
        }
        return null;
    }

    // 添加到白名单
    public function addToWhitelist()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $deviceId = $data['deviceId'];

            $embyDeviceModel = new EmbyDeviceModel();
            $device = $embyDeviceModel->where('deviceId', $deviceId)->find();

            if (!$device) {
                return json(['code' => 400, 'message' => '设备不存在']);
            }

            $sysConfigModel = new SysConfigModel();
            $clientListConfig = $sysConfigModel->where('key', 'clientList')->find();
            $clientList = $clientListConfig ? json_decode($clientListConfig['value'], true) : [];

            // 检查是否已在白名单中
            if (in_array($deviceId, $clientList)) {
                return json(['code' => 400, 'message' => '该设备已在白名单中']);
            }

            // 添加到白名单
            $clientList[] = $deviceId;

            if ($clientListConfig) {
                $clientListConfig->value = json_encode($clientList);
                $clientListConfig->save();
            } else {
                $sysConfigModel->save([
                    'key' => 'clientList',
                    'value' => json_encode($clientList)
                ]);
            }

            return json(['code' => 200, 'message' => '添加成功']);
        }
    }

    // 添加到黑名单
    public function addToBlacklist()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $deviceId = $data['deviceId'];

            $embyDeviceModel = new EmbyDeviceModel();
            $device = $embyDeviceModel->where('deviceId', $deviceId)->find();

            if (!$device) {
                return json(['code' => 400, 'message' => '设备不存在']);
            }

            $sysConfigModel = new SysConfigModel();
            $clientBlackListConfig = $sysConfigModel->where('key', 'clientBlackList')->find();
            $clientBlackList = $clientBlackListConfig ? json_decode($clientBlackListConfig['value'], true) : [];

            // 检查是否已在黑名单中
            if (in_array($deviceId, $clientBlackList)) {
                return json(['code' => 400, 'message' => '该设备已在黑名单中']);
            }

            // 添加到黑名单
            $clientBlackList[] = $deviceId;

            if ($clientBlackListConfig) {
                $clientBlackListConfig->value = json_encode($clientBlackList);
                $clientBlackListConfig->save();
            } else {
                $sysConfigModel->save([
                    'key' => 'clientBlackList',
                    'value' => json_encode($clientBlackList)
                ]);
            }

            return json(['code' => 200, 'message' => '添加成功']);
        }
    }

    // 从名单中移除
    public function removeFromList()
    {
        if (!Session::has('r_user')) {
            return json(['code' => 400, 'message' => '请先登录']);
        }

        if (Request::isPost()) {
            $data = Request::post();
            $deviceId = $data['deviceId'];
            $listType = $data['listType'];

            $sysConfigModel = new SysConfigModel();

            if ($listType === 'whitelist') {
                $configKey = 'clientList';
            } else if ($listType === 'blacklist') {
                $configKey = 'clientBlackList';
            } else {
                return json(['code' => 400, 'message' => '无效的列表类型']);
            }

            $listConfig = $sysConfigModel->where('key', $configKey)->find();
            if (!$listConfig) {
                return json(['code' => 400, 'message' => '列表不存在']);
            }

            $list = json_decode($listConfig['value'], true);

            // 从列表中移除设备
            $list = array_filter($list, function($item) use ($deviceId) {
                return $item !== $deviceId;
            });

            $listConfig->value = json_encode(array_values($list));
            $listConfig->save();

            return json(['code' => 200, 'message' => '移除成功']);
        }
    }

    private function generatePlayDailyReport() {
        try {
            // 获取24小时内的播放记录
            $startTime = date('Y-m-d H:i:s', strtotime('-24 hours'));

            $mediaHistoryModel = new MediaHistoryModel();
            $records = $mediaHistoryModel
                ->where('updatedAt', '>=', $startTime)
                ->select();

            if ($records->isEmpty()) {
                return '过去24小时没有播放记录';
            }

            // 用于存储每个影片/剧集的播放次数
            $movieStats = [];
            $seriesStats = [];

            foreach ($records as $record) {
                $historyInfo = json_decode(json_encode($record['historyInfo']), true);

                // 确定媒体标识和名称
                $isSeries = false;
                if (isset($historyInfo['item'])) {
                    if (isset($historyInfo['item']['SeriesName']) && isset($historyInfo['item']['SeriesId'])) {
                        // 这是一个剧集
                        $isSeries = true;
                        $mediaId = 'series_' . $historyInfo['item']['SeriesId'];
                        $mediaName = $historyInfo['item']['SeriesName'];
                        $mediaYear = isset($historyInfo['item']['ProductionYear']) ? $historyInfo['item']['ProductionYear'] : '';
                    } else {
                        // 这是一个电影
                        $mediaId = $record['mediaId'];
                        $mediaName = $record['mediaName'];
                        $mediaYear = $record['mediaYear'];
                    }
                } else {
                    // 兼容旧数据
                    $mediaId = $record['mediaId'];
                    $mediaName = $record['mediaName'];
                    $mediaYear = $record['mediaYear'];
                }

                if ($isSeries) {
                    if (!isset($seriesStats[$mediaId])) {
                        $seriesStats[$mediaId] = [
                            'id' => $mediaId,
                            'name' => $mediaName,
                            'year' => $mediaYear,
                            'count' => 0
                        ];
                    }
                    $seriesStats[$mediaId]['count']++;
                } else {
                    if (!isset($movieStats[$mediaId])) {
                        $movieStats[$mediaId] = [
                            'id' => $mediaId,
                            'name' => $mediaName,
                            'year' => $mediaYear,
                            'count' => 0
                        ];
                    }
                    $movieStats[$mediaId]['count']++;
                }

            }

            // 按播放次数排序
            uasort($seriesStats, function($a, $b) {
                return $b['count'] - $a['count'];
            });

            uasort($movieStats, function($a, $b) {
                return $b['count'] - $a['count'];
            });

            // 只取前10个
            $seriesStats = array_slice($seriesStats, 0, 10);
            $movieStats = array_slice($movieStats, 0, 10);

            // 构建回复消息
            $message = "📊 " . date('Y年m月d日',) . "日最热门影视排行榜：\n\n";

            $message .= "📺 电影\n";
            $rank = 1;
            foreach ($movieStats as $media) {
                $title = $media['name'];
                $year = $media['year'] ? "（{$media['year']}）" : '';
                $count = $media['count'];

                $message .= "{$rank}. {$title}{$year}\n";
                $message .= "   👥 {$count}次播放\n";
                $rank++;
            }

            $message .= "\n📺 剧集\n";
            $rank = 1;
            foreach ($seriesStats as $media) {
                $title = $media['name'];
                $year = $media['year'] ? "（{$media['year']}）" : '';
                $count = $media['count'];

                $message .= "{$rank}. {$title}{$year}\n";
                $message .= "   👥 {$count}次播放\n";
                $rank++;
            }

            // 发送消息到群组
            sendTGMessageToGroup($message);
            Cache::set('playDailyReport-'.date('Y-m-d'), $message, 86400);

            return $message;

        } catch (\Exception $e) {
            return '获取播放记录失败' . PHP_EOL.$e->getMessage();
        }
    }
}
