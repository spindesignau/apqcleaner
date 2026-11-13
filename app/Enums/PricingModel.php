<?php

namespace App\Enums;

enum PricingModel: string
{
    case Fixed = 'fixed';
    case Hourly = 'hourly';

    public function label(): string
    {
        return __('enums.pricing_model.'.$this->value);
    }
}
