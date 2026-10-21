<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberImportBatch;
use App\Models\MemberUser;
use App\Models\User;
use App\Services\XlsxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Step 9A — member migration: dry-run preview, queued chunked import, error report. */
class MemberMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'member_id' => '',
            'name' => 'Imported Member',
            'samity' => 'Talgachi Samity',
            'gender' => 'Female',
            'father_name' => 'Father Name',
            'mobile' => '017'.str_pad((string) random_int(10000000, 99999999), 8, '0'),
            'present_address' => 'Talgachi, Shahjadpur',
            'admission_date' => '2021-03-01',
            'national_id' => '',
            'primary_product' => '',
        ], $overrides);
    }

    public function test_grid_dry_run_previews_without_saving_then_confirm_imports(): void
    {
        $before = Member::count();
        $rows = collect(range(1, 5))->map(fn ($i) => $this->row(['name' => 'Grid Member '.$i]))->all();

        $response = $this->actingAs($this->admin)->post('/settings/member-migrations/dry-run/grid', ['rows' => $rows]);
        $batch = MemberImportBatch::query()->latest('id')->firstOrFail();
        $response->assertRedirect('/settings/member-migrations/'.$batch->id);

        // Dry run saves nothing.
        $this->assertSame($before, Member::count());
        $this->assertSame('Preview', $batch->status);
        $this->assertSame(5, $batch->total_rows);
        $this->assertSame([], collect($batch->rows)->flatMap(fn ($r) => $r['errors'])->all());

        // Preview page renders with all rows OK.
        $this->actingAs($this->admin)->get('/settings/member-migrations/'.$batch->id)
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/MemberMigrations')
                ->where('preview.id', $batch->id)
                ->where('preview.ok_count', 5)
                ->where('preview.error_count', 0));

        // Confirm → queued import (sync queue in tests) creates all five.
        $this->actingAs($this->admin)->from('/settings/member-migrations')->post('/settings/member-migrations/'.$batch->id.'/confirm')
            ->assertRedirect('/settings/member-migrations');

        $batch->refresh();
        $this->assertSame('Completed', $batch->status);
        $this->assertSame(5, $batch->imported_count);
        $this->assertSame(0, $batch->failed_count);
        $this->assertSame($before + 5, Member::count());
        foreach ($rows as $row) {
            $member = Member::where('mobile', $row['mobile'])->firstOrFail();
            $this->assertSame('Grid Member '.substr($row['name'], -1), $member->name);
            $this->assertNotNull(MemberUser::where('member_id', $member->id)->first());
        }
    }

    public function test_bad_row_is_flagged_and_lands_in_error_report(): void
    {
        $seededNid = Member::query()->whereNotNull('national_id')->value('national_id');
        $before = Member::count();
        $rows = collect(range(1, 5))->map(fn ($i) => $this->row(['name' => 'Mixed Member '.$i]))->all();
        $rows[2]['national_id'] = $seededNid; // duplicate NID

        $this->actingAs($this->admin)->post('/settings/member-migrations/dry-run/grid', ['rows' => $rows]);
        $batch = MemberImportBatch::query()->latest('id')->firstOrFail();

        $flagged = collect($batch->rows)->filter(fn ($r) => ($r['errors'] ?? []) !== []);
        $this->assertCount(1, $flagged);
        $this->assertSame(3, $flagged->first()['no']);
        $this->assertSame($before, Member::count());

        $this->actingAs($this->admin)->post('/settings/member-migrations/'.$batch->id.'/confirm');
        $batch->refresh();
        $this->assertSame('Completed', $batch->status);
        $this->assertSame(4, $batch->imported_count);
        $this->assertSame(1, $batch->failed_count);
        $this->assertSame($before + 4, Member::count());
        $this->assertSame(3, $batch->failures[0]['row']);

        $report = $this->actingAs($this->admin)->get('/settings/member-migrations/'.$batch->id.'/error-report');
        $report->assertOk();
        $this->assertStringStartsWith('PK', file_get_contents($report->baseResponse->getFile()->getPathname()));
    }

    public function test_excel_upload_dry_run_and_import(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'members-').'.xlsx';
        app(XlsxService::class)->write($path, [
            ['SL', 'MemberID', 'MemberName', 'Samity', 'Gender', "Father's/Husband's Name", 'Mobile', 'Present Address', 'Admission Date', 'NID', 'Primary Product'],
            ['1', '', 'Excel Member One', 'Talgachi Samity', 'Female', 'Father One', '01766661111', 'Talgachi', '2021-03-01', '', ''],
            ['2', 'LEGACY-900', 'Excel Member Two', 'Talgachi Mohila Samity', 'Male', 'Father Two', '01766662222', 'Talgachi', '01/04/2021', '', ''],
        ]);
        $file = new UploadedFile($path, 'members.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->admin)->post('/settings/member-migrations/dry-run/excel', ['file' => $file]);
        $batch = MemberImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame([], collect($batch->rows)->flatMap(fn ($r) => $r['errors'])->all());

        $this->actingAs($this->admin)->post('/settings/member-migrations/'.$batch->id.'/confirm');
        $this->assertSame(2, $batch->fresh()->imported_count);
        // Legacy member ID is kept as the member number.
        $this->assertNotNull(Member::withoutGlobalScopes()->where('member_no', 'LEGACY-900')->first());
        $this->assertNotNull(Member::where('mobile', '01766661111')->first());
    }

    public function test_within_file_duplicate_mobile_flagged(): void
    {
        $rows = [$this->row(['mobile' => '01766663333']), $this->row(['mobile' => '01766663333'])];
        $this->actingAs($this->admin)->post('/settings/member-migrations/dry-run/grid', ['rows' => $rows]);
        $batch = MemberImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame([true, false], collect($batch->rows)->map(fn ($r) => ($r['errors'] ?? []) === [])->all());
    }
}
