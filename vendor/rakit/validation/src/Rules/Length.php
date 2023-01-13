<?php

namespace Rakit\Validation\Rules;

use Rakit\Validation\Rule;

class Length extends Rule
{

    /** @var string */
    protected $message = "The :attribute Length must be :min - :max";

    /** @var array */
    protected $fillableParams = ['min', 'max'];

    /**
     * @param $value
     * @return bool
     * @throws \Rakit\Validation\MissingRequiredParameterException
     */
    public function check($value): bool
    {
        $this->requireParameters($this->fillableParams);

        $min = $this->parameter('min');
        $max = $this->parameter('max');

        $length = mb_strlen($value);

        return ($length <= $max && $length >= $min);
    }

}
