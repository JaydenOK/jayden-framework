<?php
/**
 * 树处理类
 */

namespace module\arraytree;

use Exception;

class Tree
{
    //源数据
    protected $_dataList = array();
    //主键名
    protected $_idKey = 'id';
    //父键名
    protected $_parentIdKey = 'parent_id';
    //生成结果集的子键名
    protected $_resultChildKey = 'childs';
    //生成结果集的父键名
    protected $_resultParentIdsKey = 'parent_ids';
    //是否已对源数据进行树构造标记
    protected $_builded = false;
    //根节点
    protected $_rootId = false;
    //源数据转为数节点的对象数组（包括根节点的数据，根据点数组）
    protected $_nodes = array();
    //源数据的主键id数组(不包含根节点)
    protected $_childIds = array();

    /**
     * 构造方法
     * @param array $dataList
     */
    public function __construct(array $dataList = array())
    {
        if (!empty($dataList)) {
            $this->setData($dataList);
        }
        return $this;
    }

    /**
     * 设置主键名
     * @param $idKey
     * @return $this
     */
    public function setIdKey($idKey)
    {
        $this->_markChange();
        $this->_idKey = $idKey;
        return $this;
    }

    /**
     * 设置父键名
     * @param $parentKey
     * @return $this
     */
    public function setParentIdKey($parentKey)
    {
        $this->_markChange();
        $this->_parentIdKey = $parentKey;
        return $this;
    }

    /**
     * 设置根节点Id
     * @param $rootId
     */
    public function setRootId($rootId)
    {
        $this->_markChange();
        $this->_rootId = $rootId;
        return $this;
    }

    /**
     * 设置结果集子键名
     * @param $childKey
     * @return $this
     */
    public function setResultChildKey($childKey)
    {
        $this->_markChange();
        $this->_resultChildKey = $childKey;
        return $this;
    }

    /**
     * 设置结果集父键名
     * @param $parentIdsKey
     * @return $this
     */
    public function setResultParentIdsKey($parentIdsKey)
    {
        $this->_markChange();
        $this->_resultParentIdsKey = $parentIdsKey;
        return $this;
    }

    /**
     * 设置源数据
     * @param array $dataList
     * @return $this
     */
    public function setData(array $dataList)
    {
        $this->_markChange();
        $this->_dataList = $dataList;
        return $this;
    }

    /**
     * 添加数据项到源数组
     * @param array $dataEntry
     * @return $this
     */
    public function addData(array $dataEntry)
    {
        $this->_markChange();
        $this->_dataList[] = $dataEntry;
        return $this;
    }

    /**
     * 转换数据为节点数组，再得到树状结果数组
     * @return mixed
     * @throws Exception
     */
    public function getArrayTree()
    {
        if (!$this->_builded) {
            $this->buildTree();
        }
        $arrayTree = $this->_getAllNodeDataRecursively($this->_nodes[$this->_rootId]);
        return $arrayTree[$this->_resultChildKey];
    }

    /**
     * 转换数据为节点数组，再得到二维数组
     * @return array
     * @throws Exception
     */
    public function getArray()
    {
        if (!$this->_builded) {
            $this->buildTree();
        }
        $nodeDataArray = array();
        foreach ($this->_childIds as $eachChildId) {
            $nodeDataArray[] = $this->_nodes[$eachChildId]->data;
        }
        return $nodeDataArray;
    }

    /**
     * 构造树节点Node
     * @return bool
     * @throws Exception
     */
    public function buildTree()
    {
        //保存以$this->_idKey名为主键的数组
        $reUnionedList = array();
        //保存所有父id，以找出根节点
        $parentIds = array();
        foreach ($this->_dataList as $item) {
            $parentIds[] = $item[$this->_parentIdKey];
            $reUnionedList[$item[$this->_idKey]] = $item;
        }
        $this->_findRootId($parentIds, $reUnionedList);
        $this->_generateNode($reUnionedList);
        $this->_organizeNode();
        return true;
    }

    /**
     * 将源数组生成节点对象数组
     * @param $reUnionedList
     * @return bool
     */
    protected function _generateNode($reUnionedList)
    {
        $this->_nodes = array();
        //根节点
        $this->_nodes[$this->_rootId] = new Node(array(), $this->_rootId);
        //$reUnionedList可包含根节点信息
        foreach ($reUnionedList as $key => $item) {
            $this->_nodes[$key] = new Node($item, $key);
        }
        return true;
    }

    /**
     * 组织节点之前的关联关系，父子关系
     * @return bool
     * @throws Exception
     */
    protected function _organizeNode()
    {
        //得到除根节点外的所有节点
        $this->_childIds = array_diff(array_keys($this->_nodes), array($this->_rootId));
        //对每个id循环
        foreach ($this->_childIds as $eachId) {
            /**
             * 此节点对象
             * @var $childNode Node
             */
            $childNode = $this->_nodes[$eachId];
            /**
             * 此节点的父节点对象
             * @var $parentNode Node
             */
            $parentNode = $this->_nodes[$childNode->data[$this->_parentIdKey]];
            //相互添加上下级关联()
            $childNode->attachTo($parentNode);
        }
        //Setting all nodes' parent ids
        if ($this->_resultParentIdsKey) {
            $rootNodeId = $this->_rootId;
            foreach ($this->_childIds as $eachId) {
                $childNode = $this->_nodes[$eachId];
                $childNode->data[$this->_resultParentIdsKey] = $this->_getNodeParentIdRecursively($childNode, $rootNodeId);
            }
        }

        return true;
    }

    /**
     * 递归得到目标节点的父节点数组
     * @param $node Node
     * @param $rootNodeId
     * @param bool $callOnDemand
     * @return array
     * @throws Exception
     */
    protected function _getNodeParentIdRecursively($node, $rootNodeId, $callOnDemand = true)
    {
        $returnValue = array();
        $parentNode = $node->getParentNode();
        if ($parentNode === false) {
            throw new Exception('出错了！此节点没有父节点，也不是根节点' . "nodeId={$node->id}");
        } elseif ($parentNode->id == $rootNodeId) {
            return array();
        }
        $returnValue[] = $parentNode->id;
        $returnValue[] = $this->_getNodeParentIdRecursively($parentNode, $rootNodeId, false);
        if ($callOnDemand) {
            $returnValue = self::FlattenArray($returnValue);
        }
        return $returnValue;
    }

    /**
     * 递归对个节点的数组信息
     * @param $treeNode Node
     * @return mixed
     */
    protected function _getAllNodeDataRecursively($treeNode)
    {
        $returnArr = $treeNode->data;
        $returnArr[$this->_resultChildKey] = array();
        $childNodeList = $treeNode->getAllChildNode();
        if (!empty($childNodeList)) {
            foreach ($childNodeList as $eachChildNode) {
                $returnArr[$this->_resultChildKey][] = $this->_getAllNodeDataRecursively($eachChildNode);
            }
        }
        return $returnArr;
    }

    /**
     * 转为二维数组
     * @param $array
     * @return array
     */
    protected static function FlattenArray($array)
    {
        $returnArray = array();
        $flattenFunc = function ($item, $key) use (&$returnArray) {
            if (!is_array($item)) {
                $returnArray[] = $item;
            }
        };
        array_walk_recursive($array, $flattenFunc);
        return $returnArray;
    }

    /**
     * 树构造标记为已处理
     * @return void
     */
    protected function _markChange()
    {
        $this->_builded = false;
    }

    /**
     * 找根节点Id
     * @param $parentIds
     * @param $reUnionedList
     * @return bool
     * @throws Exception
     */
    protected function _findRootId($parentIds, $reUnionedList)
    {
        if ($this->_rootId !== false) {
            return true;
        }
        $parentIds = array_unique($parentIds);
        $rootIds = array_diff($parentIds, array_keys($reUnionedList));
        if (count($rootIds) != 1) {
            throw new Exception('只能有一个根节点！');
        }
        $this->_rootId = array_pop($rootIds);
        return true;
    }

    /**
     * 获取节点数据
     * @param $id
     * @param bool $includeSelf
     * @return array
     * @throws Exception
     */
    public function getChildNodeDataArray($id, $recursively, $includeSelf = true)
    {
        $returnArr = array();
        if (!$this->_builded) {
            $this->buildTree();
        }
        if (!in_array($id, $this->_childIds)) {
            throw new Exception('节点不存在: id=' . $id);
        }
        /**
         * @var $node Node
         */
        $node = $this->_nodes[$id];
        if ($includeSelf) {
            $returnArr[] = $node->data;
        }
        $childNodeDataArr = $this->getChildNodeData($node, $recursively);
        return array_merge($returnArr, $childNodeDataArr);
    }

    /**
     * 递归获取子节点数据
     * @param $node Node
     */
    protected function getChildNodeData($node, $recursively = true)
    {
        $data = array();
        $childNodes = $node->getAllChildNode();
        if (!empty($childNodes)) {
            foreach ($childNodes as $node) {
                $data[] = $node->data;
                if ($recursively) {
                    $childData = $this->getChildNodeData($node, $recursively);
                    if (!empty($childData)) {
                        $data = array_merge($data, $childData);
                    }
                }
            }
        }
        return $data;
    }
}
