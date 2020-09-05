<?php

/**
 * Alias 抽奖算法
 * 示例：
 * $probability = [0.1, 0.1, 0.1, 0.1, 0.4, 0.1, 0.1];
 * $index = (new AliasLottery($probability))->nextRand();
 * print_r($index);
 *
 * Class AliasLottery
 */

class AliasLottery
{

    /**
     * 总情况数量
     * @var int
     */
    private $length;
    /**
     *
     * @var array
     */
    private $prob_arr;
    private $alias;

    /**
     * AliasLottery constructor.
     * @param array $pdf 概率分布数组，数组值为小数，如[0.1, 0.1, 0.1, 0.1, 0.2, 0.1, 0.1, 0.2]，总和为1
     */
    public function __construct($pdf)
    {
        $this->length = 0;
        $this->prob_arr = $this->alias = [];
        $this->_init($pdf);
    }

    /**
     * alias算法
     * @param $pdf
     * @throws Exception
     */
    private function _init($pdf)
    {
        $this->length = count($pdf);
        if ($this->length == 0) {
            throw new \Exception('抽奖概率数组不能为空');
        }
        //总概率要等于1
        $sumProb = array_sum($pdf);
        if (bccomp($sumProb, 1.0) != 0) {
            throw new \Exception('抽奖概率数组总和' . $sumProb . '，必须为1');
        }
        $small = $large = [];
        for ($i = 0; $i < $this->length; $i++) {
            $pdf[$i] *= $this->length;// 扩大倍数，使每列高度可为1
            /** 分到两个数组，便于组合 */
            if ($pdf[$i] < 1.0) {
                $small[] = $i;
            } else {
                $large[] = $i;
            }
        }
        /** 将超过1的色块与原色拼凑成1 */
        while (count($small) != 0 && count($large) != 0) {
            $s_index = array_shift($small);
            $l_index = array_shift($large);
            $this->prob_arr[$s_index] = $pdf[$s_index];
            $this->alias[$s_index] = $l_index;
            // 重新调整大色块
            $pdf[$l_index] -= 1.0 - $pdf[$s_index];
            if ($pdf[$l_index] < 1.0) {
                $small[] = $l_index;
            } else {
                $large[] = $l_index;
            }
        }
        /** 一般是精度问题才会执行这一步 */
        while (!empty($small)) {
            $this->prob_arr[array_shift($small)] = 1.0;
        }
        /** 剩下大色块都设为1 */
        while (!empty($large)) {
            $this->prob_arr[array_shift($large)] = 1.0;
        }
    }

    /**
     * 获取概率分布数组下标，从0开始
     * @return int
     */
    public function nextRand()
    {
        $column = mt_rand(0, $this->length - 1);
        return mt_rand() / mt_getrandmax() < $this->prob_arr[$column] ? $column : $this->alias[$column];
    }

    /**
     * 产生0-1的小数，小数位数由mul控制
     * @param int $min
     * @param int $max
     * @param int $mul
     * @return bool|float|int
     */
    function generateFloatRand($min = 0, $max = 1, $mul = 100)
    {
        if ($min > $max) return false;
        return mt_rand($min * $mul, $max * $mul) / $mul;
    }

}

