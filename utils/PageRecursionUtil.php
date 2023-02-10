<?php

/**
 * 分页数据处理工具，方便分页任务操作，获取任务量小于指定值时自动停止
 *
 * Class PageRecursionUtil
 */
class PageRecursionUtil
{
    /**
     * @var callable
     */
    protected $callback;
    /**
     * @var array
     */
    protected $params;
    /**
     * @var int
     */
    protected $pageSize = 100;
    /**
     * @var int
     */
    protected $page = 1;
    /**
     * @var int
     */
    private $execNum = 0;

    /**
     * @return callable
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * @param callable $callback
     * @return PageRecursionUtil
     */
    public function setCallback(callable $callback): PageRecursionUtil
    {
        $this->callback = $callback;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * @param mixed $params
     * @return PageRecursionUtil
     */
    public function setParams($params)
    {
        $this->params = $params;
        return $this;
    }

    /**
     * @return int
     */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * @param int $pageSize
     * @return PageRecursionUtil
     */
    public function setPageSize(int $pageSize): PageRecursionUtil
    {
        $this->pageSize = $pageSize;
        return $this;
    }

    /**
     * @return int
     */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * @param int $page
     * @return PageRecursionUtil
     */
    public function setPage(int $page): PageRecursionUtil
    {
        $this->page = $page >= 1 ? $page : 1;
        return $this;
    }

    /**
     * @return int
     */
    public function getExecNum(): int
    {
        return $this->execNum;
    }

    /**
     * @param callable|null $callback
     * @param null $params
     * @param null $pageSize
     * @param null $page
     */
    public function run(callable $callback = null, $params = null, $pageSize = null, $page = null)
    {
        if ($callback != null) {
            $this->setCallback($callback);
        }
        if ($params != null) {
            $this->setParams($params);
        }
        if ($pageSize != null) {
            $this->setPageSize($pageSize);
        }
        if ($page != null) {
            $this->setPage($page);
        }
        $loopSize = call_user_func($this->callback, $this);
        $this->execNum += (int)$loopSize;
        if ($loopSize !== null && $loopSize == $this->pageSize) {
            $this->page++;
            $this->run();
        }
    }

    //usage
    private function demo()
    {
        $pageRecursionUtil = new PageRecursionUtil();
        $pageRecursionUtil->run(function (PageRecursionUtil $pageRecursionUtil) {
            $page = $pageRecursionUtil->getPage();
            $list = ['a' => '1'];
            foreach ($list as $item) {
                //todo something ...
            }
            $roundCount = count($list);
            return $roundCount;
        }, ['account_type' => 16], 100, 1);
        echo 'total exec num:' . $pageRecursionUtil->getExecNum();
    }

}