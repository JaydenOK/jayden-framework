<?php

/**
 * 亚马逊销售合作伙伴授权API (授权流程/签名/RDT信息)
 * Class AmazonSellingPartnerApi
 */
class AmazonSellingPartnerApi
{

    //================================= Use My Amazon-Api =================================
    const TOKEN_HOST = 'https://api.amazon.com';
    const USER_AGENT = 'My Selling Tool/1.0 (Language=PHP/7.1.8;Platform=CentOS7)';

    public $accessToken;
    public $accessKeyId;
    public $secretAccessKey;
    public $endpoint;
    public $region;
    public $sessionToken;
    public $marketplaceId;
    public $config;
    public $sellingPartnerId;
    public $curlOption = [];

    public function __construct($account)
    {
        $this->region = $account['aws_region'] ?? '';
        $this->endpoint = $account['endpoint'] ?? '';
        $this->marketplaceId = $account['marketplace_id'] ?? '';
        $this->accessToken = $account['access_token'] ?? '';
        $this->accessKeyId = $account['access_key_id'] ?? '';
        $this->secretAccessKey = $account['secret_access_key'] ?? '';
        $this->sessionToken = $account['session_token'] ?? '';
        $this->sellingPartnerId = $account['selling_partner_id'] ?? '';
    }

    //获取授权地址：$authUrl
    //https://sellercentral.amazon.ca/
    //https://sellercentral.amazon.com/
    //https://sellercentral.amazon.com.mx/
    //https://sellercentral.amazon.com.br/
    //https://sellercentral.amazon.sg/
    //https://sellercentral.amazon.com.au/
    //https://sellercentral-japan.amazon.com/
    //https://sellercentral.amazon.ae/
    //https://sellercentral.amazon.in/
    //https://sellercentral.amazon.es/
    //https://sellercentral.amazon.co.uk/
    //https://sellercentral.amazon.fr/
    //https://sellercentral.amazon.nl/
    //https://sellercentral.amazon.de/
    //https://sellercentral.amazon.it/
    //https://sellercentral.amazon.se/
    //https://sellercentral.amazon.com.tr/
    //https://sellercentral.amazon.pl/
    //https://sellercentral.amazon.sa/
    protected function getAuthUrl($authUrl, $applicationId, $state)
    {
        $path = 'apps/authorize/consent';
        return $authUrl . $path . '?application_id=' . $applicationId . '&state=' . base64_encode($state);
    }

    //通过code获取token
    protected function getAccessToken($clientId, $clientSecret, $code)
    {
        $path = '/auth/o2/token';
        $data['grant_type'] = 'authorization_code';
        $data['client_id'] = $clientId;
        $data['client_secret'] = $clientSecret;
        $data['code'] = $code;
        $tokenUrl = self::TOKEN_HOST . $path;
        $header = ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'];
        $responseArr = $this->curlRequest($tokenUrl, $data, $header);
        return $responseArr;
    }

    //刷新token
    protected function refreshAccessToken($clientId, $clientSecret, $refreshToken)
    {
        $path = '/auth/o2/token';
        $data['grant_type'] = 'refresh_token';
        $data['client_id'] = $clientId;
        $data['client_secret'] = $clientSecret;
        $data['refresh_token'] = $refreshToken;
        $tokenUrl = self::TOKEN_HOST . $path;
        $header = [
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8'
        ];
        $responseArr = $this->curlRequest($tokenUrl, $data, $header);
        return $responseArr;
    }

    //获取授权RTD token
    protected function getRestrictedToken()
    {
        $path = '/tokens/2021-03-01/restrictedDataToken';
        $queryParams = '';
        $method = 'POST';
        $restrictedResources = [
            [
                'method' => 'GET',
                'path' => '/orders/v0/orders',
                'dataElements' => ["buyerInfo", "shippingAddress"]
            ]
        ];
        $bodyParam = ['restrictedResources' => $restrictedResources];
        $responseArr = $this->send($path, $queryParams, $bodyParam, $method);
        return $responseArr;
    }

    //发送带亚马逊签名的请求
    public function send($api_uri, $query_params = [], $body_param = [], $method = 'GET')
    {
        try {
            $datetime = gmdate('Ymd\THis\Z');
            $headers = [
                'content-type: application/json;charset=UTF-8',
                'host: ' . $this->endpoint,
                'user-agent: ' . self::USER_AGENT,
                'x-amz-access-token: ' . $this->accessToken,
                'x-amz-security-token: ' . $this->sessionToken,
                'x-amz-date: ' . $datetime,
            ];
            ksort($body_param);
            $api_url = 'https://' . $this->endpoint . $api_uri;
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $body_param = !empty($body_param) ? json_encode($body_param) : '';
                $json_data = $body_param;
            } else {
                if (empty($body_param)) $body_param = '';
                $json_data = '';
            }
            if (!empty($query_params)) {
                ksort($query_params);
                $query_string = http_build_query($query_params);
                if (!empty($query_string)) $api_url .= '?' . $query_string;
            } else {
                $query_string = '';
            }
            $headers[] = 'Authorization: ' . $this->setAuthorization($api_uri, $query_string, $body_param, $method, $datetime);
            $this->curlOption = [];
            $this->curlOption[CURLOPT_CUSTOMREQUEST] = $method;
            $response = $this->curlRequest($api_url, $json_data, $headers, $this->curlOption);
            $data = json_decode($response['data'], true, 512, JSON_BIGINT_AS_STRING);
            $response['data'] = is_array($data) ? $data : $response['data'];
            if (is_string($response['data']) && stripos($response['data'], '<html') !== false) {
                $response['data'] = strip_tags($response['data']);
            }
        } catch (\Exception $e) {
            $response = ['http_code' => 400, 'data' => $e->getMessage()];
        }
        return $response;
    }

    protected function setAuthorization($api_uri, $query_string, $body_param, $method, $datetime)
    {
        $region = $this->region;
        $short_date = substr($datetime, 0, 8);
        $service = 'execute-api';
        $sign_header = 'host;user-agent;x-amz-access-token;x-amz-date';
        $param_sign = "$method\n";
        $param_sign .= "$api_uri\n";
        $param_sign .= "$query_string\n";
        $param_sign .= "host:" . $this->endpoint . "\n";
        $param_sign .= "user-agent:" . self::USER_AGENT . "\n";
        $param_sign .= "x-amz-access-token:" . $this->accessToken . "\n";
        $param_sign .= "x-amz-date:{$datetime}\n";
        $param_sign .= "\n";
        $param_sign .= "$sign_header\n";
        $param_sign .= hash('sha256', $body_param);
        $param_sign = hash('sha256', $param_sign);
        $scope = $this->createScope($short_date, $region, $service);
        $k_signing = $this->getSigningKey($short_date, $region, $service, $this->secretAccessKey);
        $string_sign = sprintf("AWS4-HMAC-SHA256\n%s\n%s\n%s", $datetime, $scope, $param_sign);
        $signature = hash_hmac('sha256', $string_sign, $k_signing);
        $authorization = sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $this->accessKeyId, $scope, $sign_header, $signature);
        return $authorization;
    }

    protected function createScope($short_date, $region, $service)
    {
        return "$short_date/$region/$service/aws4_request";
    }

    protected function getSigningKey($short_date, $region, $service, $secret_key)
    {
        $k_date = hash_hmac('sha256', $short_date, 'AWS4' . $secret_key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        return $k_signing;
    }

    public function getSessionToken($accessKey, $secretKey, $roleArn, $durationSeconds = 3600)
    {
        try {
            $param = [
                'Action' => 'AssumeRole',
                'DurationSeconds' => $durationSeconds,
                'RoleArn' => $roleArn,
                'RoleSessionName' => 'GG-session',
                'Version' => '2011-06-15'
            ];
            ksort($param);
            $query_param = http_build_query($param);
            $host = 'sts.amazonaws.com';
            $datetime = gmdate('Ymd\THis\Z');
            $headers = [
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
                'Host: ' . $host,
                'X-Amz-Date: ' . $datetime,
            ];
            $headers[] = 'Authorization: ' . $this->setAuthorizationSession($query_param, $datetime, $host, $accessKey, $secretKey);
            $this->curlOption = [];
            $this->curlOption[CURLOPT_CUSTOMREQUEST] = 'GET';
            $json_data = [];
            $api_url = sprintf('https://%s/?%s', $host, $query_param);
            $response = $this->curlRequest($api_url, $json_data, $headers, $this->curlOption);
            $res = simplexml_load_string($response['data'], 'SimpleXMLElement', LIBXML_NOCDATA);
            $data = json_decode(json_encode($res), true, 512, JSON_BIGINT_AS_STRING);
            $response['data'] = is_array($data) ? $data : $response['data'];
            if (is_string($response['data']) && stripos($response['data'], '<html') !== false) {
                $response['data'] = strip_tags($response['data']);
            }
        } catch (\Exception $e) {
            $response = ['http_code' => 400, 'data' => $e->getMessage()];
        }
        return $response;
    }

    protected function setAuthorizationSession($query_param, $datetime, $host, $accessKey, $secretKey)
    {
        $region = $this->region;
        $service = 'sts';
        $short_date = substr($datetime, 0, 8);
        $query_str = '';
        $query_str = hash('sha256', $query_str);
        $sign_header = 'host;x-amz-date';
        $param_sign = "GET\n";
        $param_sign .= "/\n";
        $param_sign .= "{$query_param}\n";
        $param_sign .= "host:" . $host . "\n";
        $param_sign .= "x-amz-date:" . $datetime . "\n";
        $param_sign .= "\n";
        $param_sign .= "{$sign_header}\n";
        $param_sign .= $query_str;
        $param_sign = hash('sha256', $param_sign);
        $scope = $this->createScope($short_date, $region, $service);
        $k_signing = $this->getSigningKey($short_date, $region, $service, $secretKey);
        $string_sign = sprintf("AWS4-HMAC-SHA256\n%s\n%s\n%s", $datetime, $scope, $param_sign);
        $signature = hash_hmac('sha256', $string_sign, $k_signing);
        $authorization = sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $accessKey, $scope, $sign_header, $signature);
        return $authorization;
    }

    protected function curlRequest($url, $data = '', $header = [], $option = [], $timeout = 300)
    {
        $opts = [];
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_HEADER] = 0;
        $opts[CURLOPT_HTTPHEADER] = $header;
        $opts[CURLOPT_RETURNTRANSFER] = 1;
        if (!empty($data)) {
            if (isset($option[CURLOPT_CUSTOMREQUEST]) && $option[CURLOPT_CUSTOMREQUEST] === 'POST') {
                $opts[CURLOPT_POST] = 1;
            }
            $opts[CURLOPT_POSTFIELDS] = is_string($data) ? $data : http_build_query($data);
        }
        $opts[CURLOPT_TIMEOUT] = $timeout;
        $opts[CURLOPT_SSL_VERIFYPEER] = 0;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        $opts += $option;
        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        if (0 !== $errno) {
            $response = sprintf('[%s]: %s', $errno, curl_error($ch));
        }
        curl_close($ch);
        return array('http_code' => $http_code, 'data' => $response);
    }

}
