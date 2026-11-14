<?php

namespace Tests\Feature;

use App\Enums\Frequency;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\RecurringSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ScheduleImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rosa;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00', 'Australia/Sydney'));
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create(['name' => 'Office Admin']);
        $this->rosa = User::factory()->create(['name' => 'Rosa Example', 'email' => 'rosa@example.com']);
        $this->ana = User::factory()->create(['name' => 'Ana Sample', 'email' => 'ana@example.com']);
        Client::factory()->create(['display_name' => 'Smith – Manly', 'fixed_price' => 250]);
        Client::factory()->create(['display_name' => 'Jones – Dee Why', 'fixed_price' => 200]);
        Client::factory()->create(['display_name' => 'Brown – Mona Vale', 'fixed_price' => 300]);
    }

    private function csv(array $rows): UploadedFile
    {
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($fh, $r, escape: '');
        }
        rewind($fh);

        return UploadedFile::fake()->createWithContent('schedule.csv', stream_get_contents($fh));
    }

    private function upload(UploadedFile $file, ?int $cleaner = null)
    {
        return $this->actingAs($this->admin)->post(route('admin.schedule.import.upload'), array_filter(['file' => $file, 'cleaner' => $cleaner]));
    }

    public function test_template_downloads_with_headers(): void
    {
        $res = $this->actingAs($this->admin)->get(route('admin.schedule.template'));

        $res->assertOk()->assertDownload('apq-schedule-template.csv');
        $this->assertStringContainsString('Cleaner,Client,Date,Start,End,Repeat,Notes', $res->streamedContent());
    }

    public function test_upload_preview_then_confirm_creates_visits_and_regular_schedules(): void
    {
        $this->upload($this->csv([
            ['Cleaner', 'Client', 'Date', 'Start', 'End', 'Repeat', 'Notes'],
            ['rosa@example.com', 'Smith – Manly', '17/11/2026', '9:00', '12:00', 'Fortnightly', 'Oven'],
            ['Rosa', 'Jones – Dee Why', '18/11/2026', '1:00pm', '3:30pm', '', 'One-off'],
            ['Rosa; Ana', 'Brown – Mona Vale', '2026-11-19', '08:30', '13:30', 'Weekly', ''],
        ]))->assertRedirect(route('admin.schedule.import.preview'));

        $this->get(route('admin.schedule.import.preview'))->assertOk()
            ->assertSee(__('schedule_import.will_schedule'))->assertSee(__('schedule_import.will_visit'))
            ->assertSee(__('schedule_import.confirm', ['count' => 3]));
        $this->assertSame(0, Appointment::count());   // preview saves nothing

        $this->post(route('admin.schedule.import.confirm'))->assertRedirect(route('admin.schedule', ['week' => '2026-W47']));

        $smith = RecurringSchedule::whereHas('client', fn ($q) => $q->where('display_name', 'Smith – Manly'))->sole();
        $this->assertSame(Frequency::Fortnightly, $smith->frequency);
        $this->assertSame(2, $smith->weekday);
        $this->assertSame(180, $smith->duration_minutes);
        $this->assertSame([$this->rosa->id], $smith->staff->pluck('id')->all());
        $this->assertSame(4, $smith->appointments()->count());   // fortnightly over the next 8 weeks

        $oneOff = Appointment::whereNull('recurring_schedule_id')->sole();
        $this->assertSame('13:00', $oneOff->localStart()->format('H:i'));
        $this->assertSame('15:30', $oneOff->localEnd()->format('H:i'));
        $this->assertSame('One-off', $oneOff->job_notes);

        $brown = RecurringSchedule::whereHas('client', fn ($q) => $q->where('display_name', 'Brown – Mona Vale'))->sole();
        $this->assertEqualsCanonicalizing([$this->rosa->id, $this->ana->id], $brown->staff->pluck('id')->all());
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));   // upload removed
    }

    public function test_problems_are_shown_and_only_valid_rows_saved(): void
    {
        $this->upload($this->csv([
            ['Cleaner', 'Client', 'Date', 'Start', 'End', 'Repeat'],
            ['Nobody', 'Smith – Manly', '17/11/2026', '9:00', '12:00', ''],
            ['Rosa', 'Unknown Client', '17/11/2026', '9:00', '12:00', ''],
            ['Rosa', 'Jones – Dee Why', '31/02/2026', '9:00', '12:00', ''],
            ['Rosa', 'Jones – Dee Why', '18/11/2026', '14:00', '12:00', ''],
            ['Rosa', 'Jones – Dee Why', '18/11/2026', '9:00', '12:00', 'Sometimes'],
            ['Rosa', 'Brown – Mona Vale', '20/11/2026', '9am', '11am', ''],
        ]));

        $this->get(route('admin.schedule.import.preview'))->assertOk()
            ->assertSee(__('schedule_import.cleaner_unknown', ['value' => 'Nobody']))
            ->assertSee(__('schedule_import.client_unknown', ['value' => 'Unknown Client']))
            ->assertSee(__('schedule_import.bad_date', ['value' => '31/02/2026']))
            ->assertSee(__('schedule_import.end_before_start'))
            ->assertSee(__('schedule_import.bad_repeat', ['value' => 'Sometimes']));

        $this->post(route('admin.schedule.import.confirm'));
        $this->assertSame(1, Appointment::count());
    }

    public function test_double_booking_warns_and_existing_visits_are_skipped(): void
    {
        $existing = Appointment::factory()->for(Client::where('display_name', 'Smith – Manly')->first())->at('2026-11-17 09:00', 180)->create();
        $existing->staff()->attach($this->rosa);

        $this->upload($this->csv([
            ['Cleaner', 'Client', 'Date', 'Start', 'End'],
            ['Rosa', 'Smith – Manly', '17/11/2026', '9:00', '12:00'],     // already in the app
            ['Rosa', 'Jones – Dee Why', '17/11/2026', '10:00', '12:00'],  // overlaps Rosa's Smith visit
            ['Rosa', 'Jones – Dee Why', '17/11/2026', '10:00', '12:00'],  // repeated row
        ]));

        $this->get(route('admin.schedule.import.preview'))
            ->assertSee(__('schedule_import.already_scheduled'))
            ->assertSee('Rosa Example is already booked at Smith – Manly', false)
            ->assertSee(__('schedule_import.dup_in_file', ['line' => 3]));

        $this->post(route('admin.schedule.import.confirm'));
        $this->assertSame(2, Appointment::count());
    }

    public function test_default_cleaner_fills_blank_cells_and_monthly_uses_nth_weekday(): void
    {
        $this->upload($this->csv([
            ['Client', 'Date', 'Start', 'End', 'Repeat'],
            ['Smith – Manly', '26/11/2026', '9:00', '11:00', 'Monthly'],   // 4th Thursday
            ['Jones – Dee Why', '30/11/2026', '9:00', '11:00', 'Mensual'], // a 5th Monday → last Monday
        ]), $this->ana->id);
        $this->post(route('admin.schedule.import.confirm'));

        $smith = RecurringSchedule::whereHas('client', fn ($q) => $q->where('display_name', 'Smith – Manly'))->sole();
        $this->assertSame(4, $smith->nth_week);
        $this->assertSame([$this->ana->id], $smith->staff->pluck('id')->all());
        $this->assertSame(-1, RecurringSchedule::whereHas('client', fn ($q) => $q->where('display_name', 'Jones – Dee Why'))->sole()->nth_week);
    }

    public function test_spanish_headers_and_xlsx_with_excel_dates_and_times(): void
    {
        $path = storage_path('framework/testing-schedule.xlsx');
        $this->makeXlsx($path, [
            ['Personal', 'Cliente', 'Fecha', 'Inicio', 'Fin', 'Repite'],
            ['Ana', 'Jones – Dee Why', 46344, 0.375, 0.5, 'Quincenal'],   // 18/11/2026, 9:00–12:00
        ]);

        $this->upload(new UploadedFile($path, 'horario.xlsx', null, null, true));
        $this->post(route('admin.schedule.import.confirm'));

        $schedule = RecurringSchedule::sole();
        $this->assertSame(Frequency::Fortnightly, $schedule->frequency);
        $this->assertSame('2026-11-18', $schedule->anchor_date->toDateString());
        $this->assertStringStartsWith('09:00', $schedule->start_time);
        $this->assertSame(180, $schedule->duration_minutes);
        @unlink($path);
    }

    public function test_wrong_file_and_staff_access(): void
    {
        $this->upload($this->csv([['foo', 'bar'], ['1', '2']]));
        $this->get(route('admin.schedule.import.preview'))->assertRedirect(route('admin.schedule.import'));

        $this->actingAs($this->rosa)->get(route('admin.schedule.import'))->assertForbidden();
        $this->actingAs($this->rosa)->get(route('admin.schedule.template'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.schedule'))->assertOk()->assertSee(__('schedule_import.button'));
    }

    /** Minimal single-sheet .xlsx writer for the test (numbers as numbers, text as inline strings). */
    private function makeXlsx(string $path, array $rows): void
    {
        $cols = range('A', 'Z');
        $sheetRows = '';
        foreach ($rows as $i => $row) {
            $r = $i + 1;
            $cells = '';
            foreach (array_values($row) as $j => $v) {
                $ref = $cols[$j].$r;
                $cells .= is_numeric($v) && ! is_string($v)
                    ? "<c r=\"{$ref}\"><v>{$v}</v></c>"
                    : "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>".htmlspecialchars((string) $v, ENT_XML1).'</t></is></c>';
            }
            $sheetRows .= "<row r=\"{$r}\">{$cells}</row>";
        }
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Schedule" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();
    }
}
