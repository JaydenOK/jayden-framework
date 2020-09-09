<?php
/**
 * 导数据到Excel工具类
 * column为lists存在的字段，如果不存在，将用空字符串填充
 * if (!empty($lists)) {
 * $column = [
 * 'createtime' => '抽奖时间',
 * 'username' => '客户名称',
 * 'telnumber' => '手机',
 * 'address' => '地区',
 * 'title' => '抽奖结果',
 * 'total' => '抽奖次数',
 * 'totalwin' => '中奖次数',
 * 'totalget' => '兑奖次数',
 * ];
 * $columnWidth = [20, 20, 30, 50, 30, 12, 12, 12];
 * return ExportExcelUtil::getInstance()->setTitle("抽奖活动")->setColumn($column)->setColumnWidth($columnWidth)->setData($lists)->export();
 * }
*/

use PHPExcel;
use PHPExcel_Cell_DataType;
use PHPExcel_Exception;
use PHPExcel_Style_Alignment;
use PHPExcel_Writer_Excel2007;
use PHPExcel_Writer_Exception;

class ExportExcelUtil
{

    private static $instance;
    /**
     * @var PHPExcel
     */
    private static $PHPExcel;
    protected $alphabet;
    protected $name;
    protected $columnWidth;
    /**
     * @var array
     */
    protected $column;
    protected $data;
    protected $offset = 0;

    protected $limit = 10;
    protected $title = '';

    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (is_null(self::$PHPExcel)) {
            //引入PHPExcel工具类：https://github.com/PHPOffice/PHPExcel
            include_once 'vendor/PHPExcel/PHPExcel.php';
            self::$PHPExcel = new PHPExcel();
        }
        $this->alphabet = range('A', 'Z');
    }

    public function setColumnWidth($columnWidth)
    {
        $this->columnWidth = $columnWidth;
        return $this;
    }

    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    public function setColumn($column)
    {
        $this->column = $column;
        return $this;
    }

    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }

    public function export()
    {
        $this->handleColumnWidth();
        $this->handleColumn();
        $this->handleData();
        $this->output();
        return true;
    }

    protected function handleColumn()
    {
        try {
            /** @var int $i */
            $i = 0;
            foreach ($this->column as $item) {
                self::$PHPExcel->getActiveSheet()->setCellValue($this->alphabet[$i] . '1', $item);
                self::$PHPExcel->getActiveSheet()->getStyle($this->alphabet[$i] . '1')->getFont()->setBold(true);
                self::$PHPExcel->getActiveSheet()->getStyle($this->alphabet[$i] . '1')->getAlignment()->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
                self::$PHPExcel->getActiveSheet()->getStyle($this->alphabet[$i] . '1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
                $i++;
            }
        } catch (PHPExcel_Exception $e) {
            print_r('导出数据异常');
            print_r($e->getMessage());
        }
    }

    protected function handleColumnWidth()
    {
        if (is_array($this->columnWidth) && !empty($this->columnWidth)) {
            $i = 0;
            foreach ($this->columnWidth as $width) {
                self::$PHPExcel->getActiveSheet()->getColumnDimension($this->alphabet[$i])->setWidth($width);
                $i++;
            }
        }
    }

    protected function handleData()
    {
        $rowIndex = 2;
        foreach ($this->data as $rowData) {
            $i = 0;
            foreach ($this->column as $columnKey => $columnValue) {
                if (isset($rowData[$columnKey])) {
                    $value = $rowData[$columnKey];
                    if (is_int($value) || is_float($value) || is_numeric($value)) {
                        self::$PHPExcel->getActiveSheet()->setCellValueExplicit($this->alphabet[$i] . $rowIndex, $value, PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    } else if (is_string($value)) {
                        self::$PHPExcel->getActiveSheet()->setCellValue($this->alphabet[$i] . $rowIndex, (string)$value);
                    } else {
                        self::$PHPExcel->getActiveSheet()->setCellValue($this->alphabet[$i] . $rowIndex, (string)$value);
                    }
                } else {
                    self::$PHPExcel->getActiveSheet()->setCellValue($this->alphabet[$i] . $rowIndex, '');
                }
                $i++;
            }
            $rowIndex++;
        }
    }

    protected function output()
    {
        try {
            $objWriter = new PHPExcel_Writer_Excel2007(self::$PHPExcel);
            if (ob_get_length() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $this->title . '.xlsx' . '"');
            header('Cache-Control: max-age=0');
            $objWriter->save('php://output');
        } catch (PHPExcel_Writer_Exception $e) {
            print_r('导出数据异常');
            print_r($e->getMessage());
        }
    }
}