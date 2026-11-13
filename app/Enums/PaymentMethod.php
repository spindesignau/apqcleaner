<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Abn = 'abn';

    public function label(): string
    {
        return __('enums.payment_method.'.$this->value);
    }
}
