<?php
/**
 *
 * 简单Http客户端工具类
 *
 * $url = 'http://rest.java.yibainetwork.com/stockcenter/ybOverseaStock/main/overseaShelves';
 * $requestType = 'POST';
 * $httpClient = HttpClient::getInstance()->setUrl($url)->setRequestType($requestType)->setTimeout(30)->setConnectTimeout(10)->setHeader(["access_token" => "abc", "sign" => "123"]);
 * $httpClient->execute();
 * $code = $httpClient->getHttpCode();
 * $response = $httpClient->getResponse();
 * print_r(['code' => $code, 'response' => $response]);
 *
 */

namespace app\utils;

class HttpClient
{

    protected static $instance;

    private $url;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/103.0.0.0 Safari/537.36';
    private $header;
    private $connectTimeout = 10;
    private $timeout = 15;
    private $cookiesEnabled = false;
    private $cookiePath;
    private $ssl = false;
    private $requestType;
    private $postFields;
    private $usernamePassword;
    private $latency;
    private $responseBody;
    private $responseHeader;
    private $httpCode;
    private $error;


    public function __construct($url = '')
    {
        $this->url = $url;
    }

    public static function getInstance($url = '')
    {
        if (null === self::$instance) {
            self::$instance = new self($url);
        }
        return self::$instance;
    }

    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    public function setBasicAuthCredentials($username, $password)
    {
        $this->usernamePassword = $username . ':' . $password;
        return $this;
    }

    /**
     * 设置http请求头
     * ->setHeader("access_token","abc");
     * ->setHeader(["access_token" => "abc"]);
     * ->setHeader(["access_token" => "abc", "sign" => "123"]);
     * @param $key string|array
     * @param $value
     * @return $this
     */
    public function setHeader($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $this->setHeaderKeyValue($k, $v);
                } else if (is_numeric($k) && is_string($v)) {
                    list($kk, $vv) = explode(':', $v);
                    if (!empty($kk) && !empty($vv)) {
                        $this->setHeaderKeyValue($kk, trim($vv));
                    }
                }
            }
        } else if (is_string($key) && is_string($value)) {
            $this->setHeaderKeyValue($key, $value);
        }
        return $this;
    }

    private function setHeaderKeyValue($key, $value)
    {
        $this->header[$key] = (string)$value;
        return $this;
    }

    public function enableCookies($cookiePath)
    {
        $this->cookiesEnabled = true;
        $this->cookiePath = $cookiePath;
        return $this;
    }

    public function disableCookies()
    {
        $this->cookiesEnabled = false;
        $this->cookiePath = '';
        return $this;
    }

    public function enableSSL()
    {
        $this->ssl = true;
        return $this;
    }

    public function disableSSL()
    {
        $this->ssl = false;
        return $this;
    }

    public function setTimeout($timeout = 15)
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function getTimeout()
    {
        return $this->timeout;
    }

    public function setConnectTimeout($connectTimeout = 10)
    {
        $this->connectTimeout = $connectTimeout;
        return $this;
    }

    public function getConnectTimeout()
    {
        return $this->connectTimeout;
    }

    public function setRequestType($type)
    {
        $this->requestType = strtoupper($type);
        return $this;
    }

    public function setPostFields($fields = [])
    {
        $this->postFields = $fields;
        return $this;
    }

    public function getResponse()
    {
        return $this->responseBody;
    }

    public function getHeader()
    {
        return $this->responseHeader;
    }

    public function getHttpCode()
    {
        return $this->httpCode;
    }

    public function getLatency()
    {
        return $this->latency;
    }

    public function getError()
    {
        return $this->error;
    }

    public function checkResponseForContent($content = '')
    {
        if ($this->httpCode == 200 && !empty($this->responseBody)) {
            if (strpos($this->responseBody, $content) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool|string
     */
    public function execute()
    {
        $ch = curl_init();
        if (isset($this->usernamePassword)) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->usernamePassword);
        }
        if ($this->cookiesEnabled) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookiePath);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookiePath);
        }
        if (isset($this->header) && !empty($this->header)) {
            $header = [];
            foreach ($this->header as $key => $value) {
                $header[] = $key . ': ' . $value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }
        if (isset($this->requestType)) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->requestType);
            if ($this->requestType == 'POST' && isset($this->postFields)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->postFields);
            }
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->ssl);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);
        $this->responseHeader = substr($response, 0, $header_size);
        $this->responseBody = substr($response, $header_size);
        $this->error = $error;
        $this->httpCode = $http_code;
        $this->latency = round($time * 1000);
        return $this->responseBody;
    }
}
