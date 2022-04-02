<?php


namespace app\api;

use app\core\lib\controller\Controller;
use app\language\Api;
use app\module\utils\ResponseUtil;
use app\service\auth2\Auth2Service;
use League\OAuth2\Server\Exception\OAuthServerException;

//授权服务器（服务端）
//
//生成公钥和私钥
//公钥/私钥对用于对传输的 JWT 进行签名和验证。授权服务器拥有私钥来签署令牌，资源服务器拥有相应的公钥来验证签名。要生成私钥，请在终端上运行以下命令：
//openssl genrsa -out private.key 2048
//如果您想为您的私钥提供密码，请改为运行以下命令：
//openssl genrsa -aes128 -passout pass:_passphrase_ -out private.key 2048
//然后从私钥中提取公钥：
//openssl rsa -in private.key -pubout -out public.key
//或使用您的密码（如果在生成私钥时提供）：
//openssl rsa -in private.key -passin pass:_passphrase_ -pubout -out public.key
//私钥必须保密（即在授权服务器的网络根目录之外）。授权服务器还需要公钥。
//如果密码已用于生成私钥，则必须将其提供给授权服务器。
//公钥应该分发给任何验证访问令牌的服务（例如资源服务器）。

class Auth2ServerController extends Controller
{

    //授权码接口（服务端）
    public function authorize()
    {
        $auth2Service = new Auth2Service();
        list($response, $exception) = $auth2Service->authorize($this->body);
        $data = [
            'code' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'contents' => json_decode($response->getBody()->getContents()),
            'headers' => $response->getHeaders(),
            'exception' => is_null($exception) ? null : [
                'exception' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'trace' => $exception->getTrace(),
            ],
        ];
        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $data);
    }

    //用户授权登录（显示页）
    public function loginView()
    {
        $clientId = $this->app->request::get('client_id', 0);
        $redirectUri = $this->app->request::get('redirect_uri', '');
        $state = $this->app->request::get('state', '');
        $scope = $this->app->request::get('scope', '');
        $lgid = $this->app->request::get('lgid', '');
        $html = <<<EOT
<html>
<title>User Login</title>
<form method="post", action="?r=api/Auth2Server/login">
<h5>User Login</h5>
<input type="hidden" name="client_id" value="{$clientId}">
<input type="hidden" name="redirect_uri" value="{$redirectUri}">
<input type="hidden" name="state" value="{$state}">
<input type="hidden" name="scope" value="{$scope}">
<input type="hidden" name="lgid" value="{$lgid}">
Account：<input type="input" name="account_id"><br>
Password：<input type="password" name="password"><br>
<input type="submit" value="Submit"><br>
</form>
</html>
EOT;
        echo $html;
        exit;
    }

    //用户授权登录，
    //登录成功，携带code=&state=跳转到客户端设置的回调地址
    //登录失败，contents返回异常信息 ，（线上exception不返回）
    public function login()
    {
        $auth2Service = new Auth2Service();
        list($response, $exception) = $auth2Service->login($this->body);
        $data = [
            'code' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'contents' => $response->getBody()->getContents(),
            'headers' => $response->getHeaders(),
//            'exception' => is_null($exception) ? null : ['exception' => $exception->getMessage(), 'file' => $exception->getFile(), 'trace' => $exception->getTrace(),],
        ];
        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $data);
    }

    //授权码服务端接口，code换取token（服务端）
    public function token()
    {
        $auth2Service = new Auth2Service();
        list($response, $exception) = $auth2Service->accessToken($this->body);
        $data = [
            'code' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'contents' => $response->getBody()->getContents(),
            'headers' => $response->getHeaders(),
            'exception' => is_null($exception) ? null : [
                'exception' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'trace' => $exception->getTrace(),
            ],
        ];
        return ResponseUtil::getOutputArrayByCodeAndData(Api::SUCCESS, $data);
    }

}