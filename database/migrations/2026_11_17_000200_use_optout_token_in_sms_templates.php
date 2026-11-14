<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mobile Message replaces {optout} with the correct opt-out wording for the sender type
 * ("Opt out: Reply Stop" for numbers, an mb.st link for business names that can't receive replies).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sms_templates')->where('key', 'reminder')->update([
            'body_en' => DB::raw("REPLACE(body_en, 'Reply STOP to opt out', '{optout}')"),
            'body_es' => DB::raw("REPLACE(body_es, 'Responda STOP para no recibir más', '{optout}')"),
        ]);
    }

    public function down(): void
    {
        DB::table('sms_templates')->where('key', 'reminder')->update([
            'body_en' => DB::raw("REPLACE(body_en, '{optout}', 'Reply STOP to opt out')"),
            'body_es' => DB::raw("REPLACE(body_es, '{optout}', 'Responda STOP para no recibir más')"),
        ]);
    }
};
