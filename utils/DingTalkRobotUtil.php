<?php

/**
 *
 * 钉钉机器人
 * Class DingTalkRobotUtil
 */
class DingTalkRobotUtil
{

    const API_ROBOT_URL = 'https://oapi.dingtalk.com/robot/send?access_token=%s';
    const MSG_TYPE_TEXT = 'text';
    const MSG_TYPE_MARKDOWN = 'markdown';

    const TOKEN_COMMON = '';

    private $accessToken = '';
    private $key = '';
    private $msgType = 'text';
    private $title = '';
    private $text = '';
    private $isAtAll = false;
    private $content = '';
    private $atMobiles = [];

    public static function send($content, $accessToken = self::TOKEN_COMMON, $isAtAll = false, $mobiles = [])
    {
        return (new self())
            ->setAccessToken($accessToken)
            ->setMsgType(self::MSG_TYPE_TEXT)
            ->setContent($content)
            ->setIsAtAll($isAtAll)
            ->setAtMobiles($mobiles)
            ->push();
    }

    public static function sendMarkdown($title, $content, $token = self::TOKEN_COMMON, $isAtAll = false, $mobiles = [])
    {
        return (new self())
            ->setAccessToken($token)
            ->setMsgType(self::MSG_TYPE_MARKDOWN)
            ->setTitle($title)
            ->setContent($content)
            ->setIsAtAll($isAtAll)
            ->setAtMobiles($mobiles)
            ->push();
    }

    /**
     * @param $accessToken
     * @return $this
     */
    protected function setAccessToken($accessToken)
    {
        if (PHP_OS != 'Linux' || ENVIRONMENT != 'production') {
            $this->accessToken = self::TOKEN_COMMON;
        } else {
            $this->accessToken = $accessToken;
        }
        return $this;
    }

    /**
     * @param $msgType
     * @return $this
     */
    protected function setMsgType($msgType)
    {
        $this->msgType = $msgType;
        return $this;
    }

    /**
     * @param $text
     * @return $this
     */
    protected function setText($text)
    {
        $this->text = $text;
        return $this;
    }

    protected function setAtMobiles($mobile)
    {
        $mobiles = is_array($mobile) ? $mobile : [$mobile];
        foreach ($mobiles as $mobile) {
            $this->appendToAtMobiles($mobile);
        }
        return $this;
    }

    protected function setContent($content)
    {
        if (is_array($content)) {
            $content = implode("\n", $content);
        }
        $this->content = $content . "\n通知时间: " . date('Y-m-d H:i:s');
        return $this;
    }

    protected function setIsAtAll($isAtAll)
    {
        $this->isAtAll = (bool)$isAtAll;
        return $this;
    }

    /**
     * @param $mobile
     * @return $this
     */
    protected function appendToAtMobiles($mobile)
    {
        if (!in_array($mobile, $this->atMobiles)) {
            $this->atMobiles[] = $mobile;
        }
        return $this;
    }

    protected function push()
    {
        $header = ['Content-Type: application/json'];
        return $this->curlPost(sprintf(self::API_ROBOT_URL, $this->accessToken), $this->getPostJson(), 5, $header);
    }

    protected function getQuery()
    {
        $query['access_token'] = $this->accessToken;
        $query['timestamp'] = time();
        $query['sign'] = urlencode(base64_encode(hash(256, $query['timestamp'] . "\n" . $this->key)));
        return $query;
    }

    protected function curlPost($url, $data = '', $timeout = 10, $header = array(), $cookie = "")
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        if (!empty($header)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }
        $https = substr($url, 0, 8) == "https://" ? true : false;
        if ($https) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        if (!empty($cookie)) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }

    protected function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    protected function getPostData()
    {
        $data['msgtype'] = $this->msgType;
        $data['at']['atMobiles'] = $this->atMobiles;
        $data['at']['isAtAll'] = $this->isAtAll;
        if ($this->msgType == self::MSG_TYPE_MARKDOWN) {
            $data['markdown']['title'] = $this->title;
            $data['markdown']['text'] = $this->content;
        } else {
            $data['text']['content'] = $this->content;
        }
        return $data;
    }

    protected function getPostJson()
    {
        return json_encode($this->getPostData(), JSON_UNESCAPED_UNICODE);
    }

}