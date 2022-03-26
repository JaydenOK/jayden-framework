<?php


namespace app\service\auth2;

use app\service\auth2\repositories\{AccessTokenRepository,
    AuthCodeRepository,
    ClientRepository,
    MysqlRepository,
    RefreshTokenRepository,
    ScopeRepository,
    UserRepository};
use app\service\auth2\entities\UserEntity;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Stream;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;

class Auth2Service
{
    /**
     * @var \League\OAuth2\Server\AuthorizationServer
     */
    protected $authServer;

    public function __construct()
    {
        $this->initAuthorizationServer();
    }

    //初始化授权服务器的新实例并绑定存储接口和授权代码授权
    public function initAuthorizationServer()
    {
        if (is_null($this->authServer)) {
            // Init our repositories
            //oauth2-server 的实现需要我们手动创建 Repositories 与 Entities
            $clientRepository = new ClientRepository(); // instance of ClientRepositoryInterface
            $scopeRepository = new ScopeRepository(); // instance of ScopeRepositoryInterface
            $accessTokenRepository = new AccessTokenRepository(); // instance of AccessTokenRepositoryInterface
            $authCodeRepository = new AuthCodeRepository(); // instance of AuthCodeRepositoryInterface
            $refreshTokenRepository = new RefreshTokenRepository(); // instance of RefreshTokenRepositoryInterface
            $privateKey = APP_ROOT . '/file/rsa/private.key';
            $privateKey = str_replace('\\', '/', $privateKey);
            //window 下使用  new CryptKey('file://path/to/private.key', 'passphrase');
            //$privateKey = new CryptKey('file://' . APP_ROOT . '/file/rsa/private.key', 'passphrase'); // if private key has a pass phrase
            $encryptionKey = 'yEmO8htoiS9vALadhNiwsAL6GypV9FGTlU/DTmR8J2s='; // generate using base64_encode(random_bytes(32))
            // $encryptionKey = Key::loadFromAsciiSafeString($encryptionKey); //如果通过 generate-defuse-key 脚本生成的字符串，可使用此方法传入
            // Setup the authorization server
            //.key文件权限改为600
            $this->authServer = new \League\OAuth2\Server\AuthorizationServer(
                $clientRepository,
                $accessTokenRepository,
                $scopeRepository,
                $privateKey,
                $encryptionKey
            );
            $grant = new \League\OAuth2\Server\Grant\AuthCodeGrant(
                $authCodeRepository,
                $refreshTokenRepository,
                new \DateInterval('PT10M') // authorization codes will expire after 10 minutes
            );
            $grant->setRefreshTokenTTL(new \DateInterval('P1M')); // refresh tokens will expire after 1 month
            // Enable the authentication code grant on the server
            $this->authServer->enableGrantType(
                $grant,
                new \DateInterval('PT1H') // access tokens will expire after 1 hour
            );
        }
        return $this->authServer;
    }

    //授权码授予1
    public function authorize($body)
    {
        $response = new Response();
        $exception = null;
        try {
            $server = $this->authServer;
            // Validate the HTTP request and return an AuthorizationRequest object.
            $request = ServerRequest::fromGlobals();
            $authRequest = $server->validateAuthorizationRequest($request);
            // The auth request object can be serialized and saved into a user's session.
            // You will probably want to redirect the user at this point to a login endpoint.
            // Once the user has logged in set the user on the AuthorizationRequest
            // 认证请求对象可以被序列化并保存到用户的会话中。
            // 此时您可能希望将用户重定向到登录端点。
            // 用户登录后，在 AuthorizationRequest 上设置用户
            $id = MysqlRepository::saveAuthRequest($authRequest);
            $userEntity = new UserEntity();
            $userEntity->setIdentifier($id);
            $authRequest->setUser($userEntity); // an instance of UserEntityInterface
            // At this point you should redirect the user to an authorization page.
            // This form will ask the user to approve the client and the scopes requested.
            // Once the user has approved or denied the client update the status
            // (true = approved, false = denied)
            // 此时您应该将用户重定向到授权页面。
            // 此表单将要求用户批准客户端和请求的范围。
            // 一旦用户批准或拒绝客户端更新状态
            $authRequest->setAuthorizationApproved(true);
            $params = $request->getQueryParams();
            $data = [
                'client_id' => $authRequest->getClient()->getIdentifier(),
                'redirect_uri' => $authRequest->getClient()->getRedirectUri(),
                'state' => $authRequest->getState(),
                'scope' => $params['scope'],
            ];
            $response = new Response(200, [], 'http://jayden.cc?r=api/Auth2Server/loginView&' . http_build_query($data));
            // Return the HTTP redirect response
            // 登录后才，返回 HTTP 重定向响应
            //$response = $server->completeAuthorizationRequest($authRequest, $response);
        } catch (OAuthServerException $exception) {
            // All instances of OAuthServerException can be formatted into a HTTP response
            $response = $exception->generateHttpResponse($response);
        } catch (\Exception $exception) {
            // Unknown exception
            $body = new Stream(fopen('php://temp', 'r+'));
            $body->write($exception->getMessage());
            $response = $response->withStatus(500)->withBody($body);
        }
        return [$response, $exception];
    }

    //授权码授予2, 使用授权码请求访问令牌
    public function accessToken(array $body)
    {
        $server = $this->authServer;
        $response = new Response();
        $exception = null;
        try {
            $request = ServerRequest::fromGlobals();
            // Try to respond to the request
            $response = $server->respondToAccessTokenRequest($request, $response);
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {
            // All instances of OAuthServerException can be formatted into a HTTP response
            $response = $exception->generateHttpResponse($response);
        } catch (\Exception $exception) {
            // Unknown exception
            $body = new Stream(fopen('php://temp', 'r+'));
            $body->write($exception->getMessage());
            $response = $response->withStatus(500)->withBody($body);
        }
        return [$response, $exception];
    }

    //密码授权1（用于定时任务程序）
    public function passwordGrant()
    {
        // Init our repositories
        $clientRepository = new ClientRepository(); // instance of ClientRepositoryInterface
        $scopeRepository = new ScopeRepository(); // instance of ScopeRepositoryInterface
        $accessTokenRepository = new AccessTokenRepository(); // instance of AccessTokenRepositoryInterface
        $userRepository = new UserRepository(); // instance of UserRepositoryInterface
        $refreshTokenRepository = new RefreshTokenRepository(); // instance of RefreshTokenRepositoryInterface

        // Path to public and private keys
        $privateKey = APP_ROOT . '/file/rsa/private.key';
        //$privateKey = new CryptKey('file://path/to/private.key', 'passphrase'); // if private key has a pass phrase
        $encryptionKey = 'yEmO8htoiS9vALadhNiwsAL6GypV9FGTlU/DTmR8J2s='; // generate using base64_encode(random_bytes(32))
        // $encryptionKey = Key::loadFromAsciiSafeString($encryptionKey); //如果通过 generate-defuse-key 脚本生成的字符串，可使用此方法传入

        // Setup the authorization server
        $server = new \League\OAuth2\Server\AuthorizationServer(
            $clientRepository,
            $accessTokenRepository,
            $scopeRepository,
            $privateKey,
            $encryptionKey
        );

        $grant = new \League\OAuth2\Server\Grant\PasswordGrant(
            $userRepository,
            $refreshTokenRepository
        );

        $grant->setRefreshTokenTTL(new \DateInterval('P1M')); // refresh tokens will expire after 1 month

        // Enable the password grant on the server
        $server->enableGrantType(
            $grant,
            new \DateInterval('PT1H') // access tokens will expire after 1 hour
        );
    }

    public function login(array $body)
    {
        $server = $this->authServer;
        $response = new Response();
        $exception = null;
        try {
            // 在会话(session)中取出 authRequest 对象
            /**
             * @var $authRequest AuthorizationRequest|null
             */
            //账号验证
            $isLogin = $this->userLogin($body);
            if (!$isLogin) {
                throw new \Exception('user');
            }
            $authRequest = unserialize($body['']);
            if (is_null($authRequest)) {
                return '非法登录链接';
            }
            // 设置用户实体(userEntity)
            $authRequest->setUser(new UserEntity(1));
            // 设置权限范围
            $authRequest->setScopes(['basic']);
            // true = 批准，false = 拒绝
            $authRequest->setAuthorizationApproved(true);
            // 完成后重定向至客户端请求重定向地址
            $response = $server->completeAuthorizationRequest($authRequest, $response);
        } catch (OAuthServerException $exception) {
            // 可以捕获 OAuthServerException，将其转为 HTTP 响应
            $response = $exception->generateHttpResponse($response);
        } catch (\Exception $exception) {
            // 其他异常
            $response = new Response(200, [], 'Login fail: invalid account or password');
        }
        return [$response, $exception];
    }

    private function userLogin(array $body)
    {
        if (!isset($body['client_id'], $body['account_id'], $body['password'])) {
            return false;
        }
        if ($body['client_id'] == 'clientId123' && $body['account_id'] == 'abc' && $body['password'] == '666666') {
            return true;
        }
        return false;
    }


}