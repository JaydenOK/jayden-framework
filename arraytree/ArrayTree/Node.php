<?php
/**
 * 数据节点类
 */
namespace ArrayTree;

class Node
{
    /**
     * 自动生成主键
     * @var string
     */
    public $id;

    /**
     * 节点原数组信息，根节点数据为空数组array();
     * @var array
     */
    public $data;

    /**
     * 父节点
     * @var Node
     */
    protected $_parentNode;

    /**
     * 子节点数组
     * @var array
     */
    protected $_childNodes = array();


    /**
     * 构造函数
     * @param $data
     * @param bool $id
     */
    public function __construct($data, $id = false)
    {
        $this->data = $data;
        $this->id = $id ? $id : uniqid('array_tree', true);
        return $this;
    }

    /**
     * 将此节点对象附着到父节点上
     * @param $parentNode Node
     */
    public function attachTo($parentNode)
    {
        $parentNode->appendChild($this);
    }

    /**
     * 设置父节点(只有一个)
     * @param $parentNode Node
     */
    public function setParentNode($parentNode)
    {
        $this->_parentNode = $parentNode;
    }

    /**
     * 追加子节点
     * @param $childNode Node
     */
    public function appendChild($childNode)
    {
        $this->_childNodes[$childNode->id] = $childNode;
        $childNode->setParentNode($this);
    }

    /**
     * 从父节点删除此节点
     */
    public function detach()
    {
        $this->_parentNode->removeChild($this);
    }

    /**
     * 删除子节点
     * @param $childNode Node
     */
    public function removeChild($childNode)
    {
        unset($this->_childNodes[$childNode->id]);
        $childNode->setParentNode(null);
    }

    /**
     * 获取所有子节点
     * @return array
     */
    public function getAllChildNode()
    {
        return $this->_childNodes;
    }

    /**
     * 获取所有父节点
     * @return Node|bool
     */
    public function getParentNode()
    {
        return $this->_parentNode ? $this->_parentNode : false;
    }
}