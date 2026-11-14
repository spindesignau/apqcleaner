<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsTemplate extends Model
{
    /** {optout} is passed to Mobile Message, which replaces it with the official opt-out wording (20 characters). */
    public const PLACEHOLDERS = ['{first_name}', '{client}', '{day}', '{date}', '{time}', '{business_phone}', '{optout}'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function body(string $locale): string
    {
        return $locale === 'es' ? $this->body_es : $this->body_en;
    }
}
