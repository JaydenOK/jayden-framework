<?php

/**
 * 亚马逊销售合作伙伴授权API (Amazon网页授权流程/生成签名/获取RDT受限token信息-PII)
 * Class AmazonSellingPartnerApi
 */
class AmazonSellingPartnerApi
{

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
    public $withSecurityToken = true;

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
    public function send($uri, $queryParams = [], $bodyParams = [], $method = 'GET')
    {
        try {
            $datetime = gmdate('Ymd\THis\Z');
            $headersArr = [];
            $headersArr['host'] = $this->endpoint;
            $headersArr['user-agent'] = self::USER_AGENT;
            $headersArr['x-amz-access-token'] = $this->accessToken;
            $headersArr['x-amz-date'] = $datetime;
            $headersArr['content-type'] = 'application/json';
            if ($this->withSecurityToken) {
                $headersArr['x-amz-security-token'] = $this->sessionToken;
            }
            ksort($headersArr);
            foreach ($headersArr as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            ksort($bodyParams);
            $apiUrl = 'https://' . $this->endpoint . $uri;
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $bodyParams = !empty($bodyParams) ? json_encode($bodyParams) : '';
                $jsonData = $bodyParams;
            } else {
                if (empty($bodyParams)) $bodyParams = '';
                $jsonData = '';
            }
            if (!empty($queryParams)) {
                ksort($queryParams);
                $queryString = http_build_query($queryParams);
                if (!empty($queryString)) $apiUrl .= '?' . $queryString;
            } else {
                $queryString = '';
            }
            $headers[] = 'Authorization: ' . $this->setAuthorization($uri, $queryString, $bodyParams, $method, $datetime);
            $this->curlOption = [];
            $this->curlOption[CURLOPT_CUSTOMREQUEST] = $method;
            $response = $this->curlRequest($apiUrl, $jsonData, $headers, $this->curlOption);
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

    protected function setAuthorization($uri, $queryString, $bodyParams, $method, $datetime)
    {
        $shortDate = substr($datetime, 0, 8);
        $service = 'execute-api';
        $signHeader = 'host;user-agent;x-amz-date';
        $paramSign = "$method\n";
        $paramSign .= "$uri\n";
        $paramSign .= "$queryString\n";
        $paramSign .= "host:" . $this->endpoint . "\n";
        $paramSign .= "user-agent:" . self::USER_AGENT . "\n";
        $paramSign .= "x-amz-date:{$datetime}\n";
        $paramSign .= "\n";
        $paramSign .= "$signHeader\n";
        $paramSign .= hash('sha256', $bodyParams);
        $paramSign = hash('sha256', $paramSign);
        $scope = $this->createScope($shortDate, $this->region, $service);
        $kSigning = $this->getSigningKey($shortDate, $this->region, $service, $this->secretAccessKey);
        $stringSign = sprintf("AWS4-HMAC-SHA256\n%s\n%s\n%s", $datetime, $scope, $paramSign);
        $signature = hash_hmac('sha256', $stringSign, $kSigning);
        $authorization = sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $this->accessKeyId, $scope, $signHeader, $signature);
        return $authorization;
    }

    protected function createScope($shortDate, $region, $service)
    {
        return "$shortDate/$region/$service/aws4_request";
    }

    protected function getSigningKey($shortDate, $region, $service, $secretKey)
    {
        return hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service,
            hash_hmac('sha256', $region, hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true), true),
            true), true);
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
            $queryParam = http_build_query($param);
            $host = 'sts.amazonaws.com';
            $datetime = gmdate('Ymd\THis\Z');
            $headers = [
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
                'Host: ' . $host,
                'X-Amz-Date: ' . $datetime,
            ];
            $headers[] = 'Authorization: ' . $this->setAuthorizationSession($queryParam, $datetime, $host, $accessKey, $secretKey);
            $this->curlOption = [];
            $this->curlOption[CURLOPT_CUSTOMREQUEST] = 'GET';
            $jsonData = [];
            $apiUrl = sprintf('https://%s/?%s', $host, $queryParam);
            $response = $this->curlRequest($apiUrl, $jsonData, $headers, $this->curlOption);
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

    protected function setAuthorizationSession($queryParam, $datetime, $host, $accessKey, $secretKey)
    {
        $service = 'sts';
        $shortDate = substr($datetime, 0, 8);
        $queryStr = '';
        $queryStr = hash('sha256', $queryStr);
        $signHeader = 'host;x-amz-date';
        $paramSign = "GET\n";
        $paramSign .= "/\n";
        $paramSign .= "{$queryParam}\n";
        $paramSign .= "host:" . $host . "\n";
        $paramSign .= "x-amz-date:" . $datetime . "\n";
        $paramSign .= "\n";
        $paramSign .= "{$signHeader}\n";
        $paramSign .= $queryStr;
        $paramSign = hash('sha256', $paramSign);
        $scope = $this->createScope($shortDate, $this->region, $service);
        $kSigning = $this->getSigningKey($shortDate, $this->region, $service, $secretKey);
        $stringSign = sprintf("AWS4-HMAC-SHA256\n%s\n%s\n%s", $datetime, $scope, $paramSign);
        $signature = hash_hmac('sha256', $stringSign, $kSigning);
        $authorization = sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $accessKey, $scope, $signHeader, $signature);
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        if (0 !== $errno) {
            $response = sprintf('[%s]: %s', $errno, curl_error($ch));
        }
        curl_close($ch);
        return array('http_code' => $httpCode, 'data' => $response);
    }

}
