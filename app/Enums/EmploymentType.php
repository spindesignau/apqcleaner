<?php

namespace App\Enums;

enum EmploymentType: string
{
    case Casual = 'casual';
    case PartTime = 'part_time';
    case FullTime = 'full_time';
    case ContractorAbn = 'contractor_abn';

    public function label(): string
    {
        return __('enums.employment_type.'.$this->value);
    }
}
