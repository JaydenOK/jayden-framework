<?php

//亚马逊广告平台API, Amazon Advertising api
class AmazonAdsApi
{
    private $baseUrl;
    private $redirectUrl;

    private $apiTokenUrls = [
        'us-east-1' => 'https://api.amazon.com/auth/o2/token',
        'eu-west-1' => 'https://api.amazon.co.uk/auth/o2/token',
        //'us-west-2' => 'https://api.amazon.co.jp/auth/o2/token',
    ];

    public function __construct($baseUrl = 'https://api.amazon.com', $redirectUrl = 'https://center.yibainetwork.com/shop/Auth/code')
    {
        $this->baseUrl = $baseUrl;
        $this->redirectUrl = $redirectUrl;
    }

    /**
     * @param $authUrl
     * @param $clientId
     * @param $state
     * @return string
     */
    public function getAuthUrl($authUrl, $clientId, $state)
    {
        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'scope' => 'advertising::campaign_management',
            'redirect_uri' => $this->redirectUrl,
            'state' => base64_encode($state),
        ];
        return !empty($authUrl) ? $authUrl . '?' . http_build_query($params) : '';
    }


    /**
     * @param $siteRegion
     * @param $clientId
     * @param $clientSecret
     * @param $code
     * @return array
     */
    public function getAccessToken($siteRegion, $clientId, $clientSecret, $code)
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'charset' => 'UTF-8',
        ];
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
        $apiTokenUrl = $this->apiTokenUrls[$siteRegion] ?? 'https://api.amazon.com/auth/o2/token';
        $responseArr = curl_request($apiTokenUrl, $params, 'array', $headers);
        if (isset($responseArr['access_token']) && !empty($responseArr['access_token'])) {
            return ['status' => 1, 'data' => $responseArr];
        } else {
            return [
                'status' => 0,
                'errorCode' => $responseArr['error'] ?? '',
                'error_message' => $responseArr['error_description'] ?? json_encode($responseArr)
            ];
        }
    }

    /**
     * @param $endPointUrl
     * @param $accessToken
     * @param $clientId
     * @return array
     */
    public function getProfile($endPointUrl, $accessToken, $clientId)
    {
        $headers = [
            'Authorization: bearer ' . $accessToken,
            'Content-Type: application/json',
            'Amazon-Advertising-API-ClientId: ' . $clientId,
        ];
        //$headers['Amazon-Advertising-API-Scope'] = '';
        $url = $endPointUrl . '/v2/profiles';
        //$responseArr可能还没有配置资料，为空数组
        //[{"profileId":3374453491334297,"countryCode":"IT","currencyCode":"EUR","dailyBudget":9999999,"timezone":"Europe\/Paris","accountInfo":{"marketplaceStringId":"APJ6JRA9NG5V4","id":"A1VQNQGGGPIB36","type":"seller","name":"Karfotu","validPaymentMethod":true}},{"profileId":876860177733664,"countryCode":"SE","currencyCode":"SEK","dailyBudget":9999999,"timezone":"Europe\/Stockholm","accountInfo":{"marketplaceStringId":"A2NODRKZP88ZB9","id":"A1VQNQGGGPIB36","type":"seller","name":"Karfotu","validPaymentMethod":true}},{"profileId":2435869695563730,"countryCode":"PL","currencyCode":"PLN","dailyBudget":9999999,"timezone":"Europe\/Warsaw","accountInfo":{"marketplaceStringId":"A1C3SOZRARQ6R3","id":"A1VQNQGGGPIB36","type":"seller","name":"Karfotu","validPaymentMethod":true}},{"profileId":468888469565196,"countryCode":"FR","currencyCode":"EUR","dailyBudget":9999999,"timezone":"Europe\/Paris","accountInfo":{"marketplaceStringId":"A13V1IB3VIYZZH","id":"A1VQNQGGGPIB36","type":"seller","name":"Karfotu","validPaymentMethod":true}},{"profileId":2837475287489425,"countryCode":"TR","currencyCode":"TRY","dailyBudget":999999999,"timezone":"Europe\/Istanbul","accountInfo":{"marketplaceStringId":"A33AVAJ2PDY3EV","id":"A1VQNQGGGPIB36","type":"seller","name":"Karfotu","validPaymentMethod":true}},{"profileId":3342185607068007,"countryCode":"NL","currencyCode":"EUR","dailyBudget":9999999,"timezone":"Europe\/Amsterdam","accountInfo":{"marketplaceStringId":"A1805IZSGTT6HS","id":"A1VQNQGGGPIB36","type":"seller","name":"Karfotu","validPaymentMethod":true}},{"profileId":2539473061851597,"countryCode":"ES","currencyCode":"EUR","dailyBudget":9999999,"timezone":"Europe\/Paris","accountInfo":{"marketplaceStringId":"A1RKKUPIHCS9HS","id":"A1VQNQGGGPIB36","type":"seller","name":"Karfotu","validPaymentMethod":true}},{"profileId":91245004154919,"countryCode":"UK","currencyCode":"GBP","dailyBudget":9999999,"timezone":"Europe\/London","accountInfo":{"marketplaceStringId":"A1F83G8C2ARO7P","id":"A1VQNQGGGPIB36","type":"seller","name":"Karfotu","validPaymentMethod":true}},{"profileId":1524680455937586,"countryCode":"DE","currencyCode":"EUR","dailyBudget":9999999,"timezone":"Europe\/Paris","accountInfo":{"marketplaceStringId":"A1PA6795UKMFR9","id":"A1VQNQGGGPIB36","type":"seller","name":"Karfotu","validPaymentMethod":true}}]
        //[{"profileId":3743658252006159,"countryCode":"CA","currencyCode":"CAD","dailyBudget":999999999,"timezone":"America\\/Los_Angeles","accountInfo":{"marketplaceStringId":"A2EUQ1WTGCTBG2","id":"A2A1FRBB6HS26V","type":"seller","name":"Karfotun","validPaymentMethod":false}},{"profileId":688161550543821,"countryCode":"MX","currencyCode":"MXN","dailyBudget":999999999,"timezone":"America\\/Los_Angeles","accountInfo":{"marketplaceStringId":"A1AM78C64UM0Y8","id":"A2A1FRBB6HS26V","type":"seller","name":"Karfotun","validPaymentMethod":false}},{"profileId":712007767795311,"countryCode":"US","currencyCode":"USD","dailyBudget":999999999,"timezone":"America\\/Los_Angeles","accountInfo":{"marketplaceStringId":"ATVPDKIKX0DER","id":"A2A1FRBB6HS26V","type":"seller","name":"Karfotun","validPaymentMethod":false}}]
        $responseArr = curl_request($url, null, null, $headers, 30);
        if (isset($responseArr) && is_array($responseArr)) {
            return ['status' => 1, 'data' => $responseArr];
        } else {
            return ['status' => 0, 'errorCode' => "", 'error_message' => is_string($responseArr) ? $responseArr : json_encode($responseArr)];
        }
    }

    /**
     * @param $siteRegion
     * @param $clientId
     * @param $clientSecret
     * @param $refreshToken
     * @return array
     */
    public function getRefreshAccessToken($siteRegion, $clientId, $clientSecret, $refreshToken)
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
        $apiTokenUrl = $this->apiTokenUrls[$siteRegion] ?? 'https://api.amazon.com/auth/o2/token';
        $responseArr = curl_request($apiTokenUrl, $params, 'array', $headers);
        if (isset($responseArr['access_token']) && !empty($responseArr['access_token'])) {
            return ['status' => 1, 'data' => $responseArr];
        } else {
            return [
                'status' => 0,
                'errorCode' => $responseArr['error'] ?? '',
                'error_message' => $responseArr['error_description'] ?? json_encode($responseArr)
            ];
        }
    }

    /**
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->redirectUrl;
    }


}

function curl_request($url, $data = '', $data_type = 'array', $header = array(), $timeout = 300, $cookie = '')
{
    if ($data_type == 'json' && empty($header) && is_string($data)) {
        $header = array(
            'Content-Type: application/json;charset=UTF-8',
            'Content-Length: ' . strlen($data)
        );
    }
    $opts = array();
    $opts[CURLOPT_URL] = $url;
    $opts[CURLOPT_HEADER] = 0;
    $opts[CURLOPT_HTTPHEADER] = $header;
    $opts[CURLOPT_RETURNTRANSFER] = 1;
    if (!empty($data)) {
        $opts[CURLOPT_POST] = 1;
        $opts[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
    }
    $opts[CURLOPT_TIMEOUT] = $timeout;
    $opts[CURLOPT_SSL_VERIFYPEER] = 0;
    $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    if (!empty($cookie)) {
        $opts[CURLOPT_COOKIE] = $cookie;
    }
    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    if (0 !== $errno) {
        return curl_getinfo($ch) + array('errno' => $errno, 'error' => curl_error($ch));
    }
    curl_close($ch);
    $data = json_decode($response, true);
    return is_array($data) ? $data : $response;
}