<?php


namespace app\api;

use app\core\lib\controller\Controller;

//授权服务器（客户端）

class Auth2ClientController extends Controller
{

    public function authAndGetToken()
    {
        $host = 'http://' . $_SERVER['HTTP_HOST'];
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId' => 'clientId123',    // The client ID assigned to you by the provider
            'clientSecret' => 'clientSecret123',    // The client password assigned to you by the provider
            'redirectUri' => $host . '?r=api/Auth2Client/authRedirect',
            'urlAuthorize' => $host . '?r=api/Auth2Server/authorize',
            'urlAccessToken' => $host . '?r=api/Auth2Server/token',
            'urlResourceOwnerDetails' => $host . '?r=api/Auth2Server/resource',
        ]);

        // If we don't have an authorization code then get one
        if (!isset($_GET['code'])) {
            // Fetch the authorization URL from the provider; this returns the
            // urlAuthorize option and generates and applies any necessary parameters
            // (e.g. state).
            $codeChallenge = 'sha1' . sha1(time());
            $options = [
                'state' => 'MyRandomState',
                'scope' => 'user',
                'code_challenge' => $codeChallenge,
                'redirect_uri' => $host . '?r=api/Auth2Client/authRedirect',
            ];
            $authorizationUrl = $provider->getAuthorizationUrl($options);
            // Get the state generated for you and store it to the session.
            $_SESSION['oauth2state'] = $provider->getState();
            // Redirect the user to the authorization URL.
            //header('Location: ' . $authorizationUrl);
            //还没有授权码code，提供state, response_type，redirect_uri，clientId 跳转到服务端授权
            //http://jayden.cc?r=api/Auth2Server/authorize&state=35bbc03a0e67c7fd504f6f390d79a417&response_type=code&approval_prompt=auto&redirect_uri=http://jayden.cc?r=api/Auth2Client/authRedirect&client_id=clientId123
            echo $authorizationUrl;
            exit;
            // Check given state against previously stored one to mitigate CSRF attack
        } elseif (empty($_GET['state']) || (isset($_SESSION['oauth2state']) && $_GET['state'] !== $_SESSION['oauth2state'])) {
            if (isset($_SESSION['oauth2state'])) {
                unset($_SESSION['oauth2state']);
            }
            exit('Invalid state');
        } else {
            try {
                // Try to get an access token using the authorization code grant.
                $accessToken = $provider->getAccessToken('authorization_code', [
                    'code' => $_GET['code']
                ]);
                // We have an access token, which we may use in authenticated
                // requests against the service provider's API.
                echo 'Access Token: ' . $accessToken->getToken() . "<br>";
                echo 'Refresh Token: ' . $accessToken->getRefreshToken() . "<br>";
                echo 'Expired in: ' . $accessToken->getExpires() . "<br>";
                echo 'Already expired? ' . ($accessToken->hasExpired() ? 'expired' : 'not expired') . "<br>";
                // Using the access token, we may look up details about the
                // resource owner.
                $resourceOwner = $provider->getResourceOwner($accessToken);
                var_export($resourceOwner->toArray());
                // The provider provides a way to get an authenticated API request for
                // the service, using the access token; it returns an object conforming
                // to Psr\Http\Message\RequestInterface.
                $request = $provider->getAuthenticatedRequest(
                    'GET',
                    'https://service.example.com/resource',
                    $accessToken
                );
            } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
                // Failed to get the access token or user details.
                exit($e->getMessage());
            }
        }
    }

    //用户授权重定向
    public function authRedirect()
    {

    }

    public function refreshToken()
    {
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId' => 'XXXXXX',    // The client ID assigned to you by the provider
            'clientSecret' => 'XXXXXX',    // The client password assigned to you by the provider
            'redirectUri' => 'https://my.example.com/your-redirect-url/',
            'urlAuthorize' => 'https://service.example.com/authorize',
            'urlAccessToken' => 'https://service.example.com/token',
            'urlResourceOwnerDetails' => 'https://service.example.com/resource'
        ]);
        $existingAccessToken = getAccessTokenFromYourDataStore();
        if ($existingAccessToken->hasExpired()) {
            $newAccessToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $existingAccessToken->getRefreshToken()
            ]);
            // Purge old access token and store new access token to your data store.
        }
    }


    //#2
    public function passwordAuth()
    {
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId' => 'XXXXXX',    // The client ID assigned to you by the provider
            'clientSecret' => 'XXXXXX',    // The client password assigned to you by the provider
            'redirectUri' => 'https://my.example.com/your-redirect-url/',
            'urlAuthorize' => 'https://service.example.com/authorize',
            'urlAccessToken' => 'https://service.example.com/token',
            'urlResourceOwnerDetails' => 'https://service.example.com/resource'
        ]);
        try {
            // Try to get an access token using the resource owner password credentials grant.
            $accessToken = $provider->getAccessToken('password', [
                'username' => 'myuser',
                'password' => 'mysupersecretpassword'
            ]);
        } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
            // Failed to get the access token
            exit($e->getMessage());
        }
    }

}