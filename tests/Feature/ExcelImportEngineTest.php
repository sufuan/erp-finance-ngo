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
use ZipArchive;

/**
 * Excel import/export engine (2026-10-22) on the members dataset:
 * template + export downloads, JSON preview with per-cell errors,
 * re-validation of edited rows, and the all-or-nothing confirm.
 */
class ExcelImportEngineTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private const HEADERS = ['SL', 'MemberID', 'MemberName', 'Samity', 'Gender', "Father's/Husband's Name", 'Mobile', 'Present Address', 'Admission Date', 'NID', 'Primary Product'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    private function cells(array $overrides = []): array
    {
        return array_merge([
            'member_id' => '',
            'name' => 'Engine Member',
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

    /** Cell map → spreadsheet line in HEADERS order. */
    private function line(array $cells, int $sl): array
    {
        return [(string) $sl, $cells['member_id'], $cells['name'], $cells['samity'], $cells['gender'],
            $cells['father_name'], $cells['mobile'], $cells['present_address'], $cells['admission_date'],
            $cells['national_id'], $cells['primary_product']];
    }

    private function xlsxUpload(array $dataRows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'engine-').'.xlsx';
        app(XlsxService::class)->write($path, array_merge([self::HEADERS], $dataRows));

        return new UploadedFile($path, 'members.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Download a BinaryFileResponse's xlsx and read its first sheet. */
    private function downloadGrid($response): array
    {
        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'dl-').'.xlsx';
        copy($path, $copy);

        return [$copy, app(XlsxService::class)->read($copy)];
    }

    public function test_template_download_matches_column_definitions(): void
    {
        $response = $this->actingAs($this->admin)->get('/settings/member-migrations/template');
        [$copy, $grid] = $this->downloadGrid($response);

        // First sheet: exact headers + the marked example row.
        $this->assertSame(self::HEADERS, array_values($grid[0]));
        $this->assertSame('01712345678', $grid[1][6]);

        // Second sheet is the Instructions sheet.
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($copy));
        $this->assertNotFalse($zip->getFromName('xl/worksheets/sheet2.xml'));
        $this->assertStringContainsString('Instructions', (string) $zip->getFromName('xl/workbook.xml'));
        $zip->close();
    }

    public function test_export_download_lists_current_members(): void
    {
        $row = $this->cells(['name' => 'Export Check Member']);
        $this->actingAs($this->admin)->postJson('/settings/member-migrations/confirm-import', ['rows' => [$row]])
            ->assertOk()->assertJsonPath('imported', 1);

        $response = $this->actingAs($this->admin)->get('/settings/member-migrations/export');
        [, $grid] = $this->downloadGrid($response);
        $this->assertSame(self::HEADERS, array_values($grid[0]));
        $mobiles = collect($grid)->skip(1)->map(fn ($r) => $r[6] ?? null)->all();
        $this->assertContains($row['mobile'], $mobiles);
    }

    public function test_preview_flags_errors_on_the_right_cells(): void
    {
        $seededNid = Member::query()->whereNotNull('national_id')->value('national_id');
        $this->assertNotEmpty($seededNid);
        $before = Member::count();

        $rows = [
            $this->line($this->cells(['mobile' => '123']), 1),                                    // bad mobile format
            $this->line($this->cells(['national_id' => '1234567890']), 2),                        // ok
            $this->line($this->cells(['national_id' => '1234567890']), 3),                        // duplicate NID in file
            $this->line($this->cells(['national_id' => $seededNid]), 4),                          // NID already in DB
            $this->line($this->cells(['samity' => 'No Such Samity XYZ']), 5),                     // unknown samity
        ];
        $response = $this->actingAs($this->admin)->post('/settings/member-migrations/preview', [
            'file' => $this->xlsxUpload($rows),
        ]);
        $response->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('rows.0.errors.mobile', fn ($v) => is_string($v) && $v !== '')
            ->assertJsonPath('rows.1.errors', [])
            ->assertJsonPath('rows.2.errors.national_id', fn ($v) => is_string($v) && $v !== '')
            ->assertJsonPath('rows.3.errors.national_id', fn ($v) => is_string($v) && $v !== '')
            ->assertJsonPath('rows.4.errors.samity', fn ($v) => is_string($v) && $v !== '');

        // Preview saves nothing.
        $this->assertSame($before, Member::count());
    }

    public function test_validate_rows_revalidates_after_in_place_edits(): void
    {
        $bad = $this->cells(['mobile' => '123']);
        $this->actingAs($this->admin)->postJson('/settings/member-migrations/validate-rows', ['rows' => [$bad]])
            ->assertOk()
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('rows.0.errors.mobile', fn ($v) => is_string($v) && $v !== '');

        $fixed = $this->cells(['mobile' => '01712349999']);
        $this->actingAs($this->admin)->postJson('/settings/member-migrations/validate-rows', ['rows' => [$fixed]])
            ->assertOk()
            ->assertJsonPath('error_count', 0)
            ->assertJsonPath('rows.0.errors', []);
    }

    public function test_confirm_with_an_error_writes_nothing(): void
    {
        $before = Member::count();
        $batchesBefore = MemberImportBatch::count();
        $rows = [$this->cells(), $this->cells(['mobile' => 'not-a-number'])];

        $this->actingAs($this->admin)->postJson('/settings/member-migrations/confirm-import', ['rows' => $rows])
            ->assertStatus(422)
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('rows.1.errors.mobile', fn ($v) => is_string($v) && $v !== '');

        // All-or-nothing: not even the valid first row was written.
        $this->assertSame($before, Member::count());
        $this->assertSame($batchesBefore, MemberImportBatch::count());
    }

    public function test_confirm_all_valid_imports_everything_and_records_batch(): void
    {
        $before = Member::count();
        $rows = [
            $this->cells(['name' => 'Engine Member One']),
            $this->cells(['name' => 'Engine Member Two', 'member_id' => 'LEGACY-ENG-1']),
        ];

        $response = $this->actingAs($this->admin)->postJson('/settings/member-migrations/confirm-import', ['rows' => $rows]);
        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('imported', 2);

        $this->assertSame($before + 2, Member::count());
        foreach ($rows as $row) {
            $member = Member::where('mobile', $row['mobile'])->firstOrFail();
            $this->assertNotEmpty($member->member_no);
            $this->assertNotNull(MemberUser::where('member_id', $member->id)->first());
        }
        $this->assertNotNull(Member::withoutGlobalScopes()->where('member_no', 'LEGACY-ENG-1')->first());

        $batch = MemberImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame($response->json('batch_id'), $batch->id);
        $this->assertSame('Completed', $batch->status);
        $this->assertSame(2, $batch->imported_count);
        $this->assertSame(0, $batch->failed_count);
    }

    public function test_engine_endpoints_are_permission_gated(): void
    {
        $nobody = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($nobody)->get('/settings/member-migrations/template')->assertForbidden();
        $this->actingAs($nobody)->get('/settings/member-migrations/export')->assertForbidden();
        $this->actingAs($nobody)->postJson('/settings/member-migrations/validate-rows', ['rows' => [$this->cells()]])->assertForbidden();
        $this->actingAs($nobody)->postJson('/settings/member-migrations/confirm-import', ['rows' => [$this->cells()]])->assertForbidden();
        $this->actingAs($nobody)->post('/settings/member-migrations/preview', ['file' => $this->xlsxUpload([])])->assertForbidden();

        // settings.view alone can download, but cannot preview/confirm.
        $viewer = User::factory()->create(['must_change_password' => false]);
        $viewer->givePermissionTo('settings.view');
        $this->actingAs($viewer)->withSession(['totp_passed_at' => now()->toDateTimeString()])
            ->get('/settings/member-migrations/template')->assertOk();
        $this->actingAs($viewer)->postJson('/settings/member-migrations/confirm-import', ['rows' => [$this->cells()]])->assertForbidden();
    }
}
