<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\NumberingService;
use App\Services\UploadService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class CoreInfrastructureTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $head;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        // firstOrCreate: the test DB is seeded (BranchSeeder) in CI.
        $this->head = Branch::firstOrCreate(['code' => '101'], ['name' => 'Head Office', 'type' => 'Head Office', 'status' => 'Active']);
        $this->branch = Branch::firstOrCreate(['code' => '102'], ['name' => 'Talgachi Branch', 'type' => 'Branch', 'parent_branch_id' => $this->head->id, 'status' => 'Active']);
    }

    public function test_numbering_service_increments_per_branch_and_entity(): void
    {
        $numbers = app(NumberingService::class);

        $this->assertSame(1, $numbers->next($this->head->id, 'member'));
        $this->assertSame(2, $numbers->next($this->head->id, 'member'));
        $this->assertSame(1, $numbers->next($this->branch->id, 'member')); // per-branch
        $this->assertSame(1, $numbers->next($this->head->id, 'voucher')); // per-entity
    }

    public function test_display_id_is_branch_prefixed_and_zero_padded(): void
    {
        $numbers = app(NumberingService::class);
        // The plan's canonical example: branch 101 + member seq 162.
        $this->assertSame('101000162', $numbers->displayId($this->head, 162));
    }

    public function test_business_day_close_advances_and_locks_prior_date(): void
    {
        $days = app(BusinessDayService::class);

        $today = $days->currentDate($this->branch->id);
        $days->assertOpen($this->branch->id, $today); // must not throw

        $next = $days->close($this->branch->id);
        $this->assertSame(
            \Carbon\CarbonImmutable::parse($today)->addDay()->toDateString(),
            $next->business_date->toDateString()
        );
        $this->assertTrue($days->isClosed($this->branch->id, $today));

        $this->expectException(DomainException::class);
        $days->assertOpen($this->branch->id, $today); // prior date now rejects postings
    }

    public function test_audit_log_hash_chain_and_redaction(): void
    {
        $audit = app(AuditService::class);
        $audit->log(null, 'test.one', 'members', 5, null, ['name' => 'Karim', 'national_id' => '1234567890']);
        $audit->log(null, 'test.two', 'members', 5, ['national_id' => '1234567890'], null);

        // Scope to this test's rows: audit_logs is append-only, so rows
        // from other (non-transactional) tests legitimately persist.
        $rows = AuditLog::whereIn('action', ['test.one', 'test.two'])->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('[redacted]', $rows[0]->new_values['national_id']);
        $this->assertSame('[redacted]', $rows[1]->old_values['national_id']);
        // Chain linkage: row 2 points at row 1's hash, and row 2's hash
        // recomputes from its payload + that prev_hash (AuditService formula).
        $this->assertSame($rows[0]->hash, $rows[1]->prev_hash);
        $payload = [
            'user_id' => $rows[1]->user_id, 'action' => $rows[1]->action,
            'entity' => $rows[1]->entity, 'entity_id' => $rows[1]->entity_id,
            'old_values' => $rows[1]->old_values, 'new_values' => $rows[1]->new_values,
            'ip_address' => $rows[1]->ip_address,
        ];
        $this->assertSame($rows[1]->hash, hash('sha256', $rows[1]->prev_hash.json_encode($payload)));
    }

    public function test_audit_log_cannot_be_updated_or_deleted_via_model(): void
    {
        $row = app(AuditService::class)->log(null, 'test.x', 'members', 1);

        try {
            $row->update(['action' => 'tampered']);
            $this->fail('UPDATE on audit_logs must be blocked');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $row->delete();
            $this->fail('DELETE on audit_logs must be blocked');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_branch_switcher_sets_active_branch(): void
    {
        $this->post('/branch/switch', ['branch_id' => $this->branch->id])
            ->assertRedirect()
            ->assertSessionHas('active_branch_id', $this->branch->id);

        $this->get('/settings/business-day')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/BusinessDay/Index')
                ->where('branch.id', $this->branch->id)
                ->has('currentDate')
            );
    }

    public function test_upload_rejects_legacy_xls_and_wrong_types(): void
    {
        $uploads = app(UploadService::class);

        $xls = UploadedFile::fake()->create('data.xls', 10, 'application/vnd.ms-excel');
        try {
            $uploads->store($xls, 'spreadsheet');
            $this->fail('.xls must be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $text = UploadedFile::fake()->createWithContent('note.txt', 'not an image');
        try {
            $uploads->store($text, 'photo');
            $this->fail('A text file must not pass as a photo');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_photo_upload_is_reencoded_and_stored_privately(): void
    {
        Storage::fake('private');
        // Build a real JPEG with GD, with junk appended (payload simulation).
        $img = imagecreatetruecolor(8, 8);
        $tmp = tempnam(sys_get_temp_dir(), 'up').'.jpg';
        imagejpeg($img, $tmp);
        imagedestroy($img);
        file_put_contents($tmp, file_get_contents($tmp).'<?php echo "payload";');

        $file = new UploadedFile($tmp, 'photo.jpg', 'image/jpeg', null, true);
        $path = app(UploadService::class)->store($file, 'photo');

        Storage::disk('private')->assertExists($path);
        $stored = Storage::disk('private')->get($path);
        $this->assertStringNotContainsString('payload', $stored); // re-encoding stripped it
    }

    public function test_private_file_url_forbidden_for_guests(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('uploads/secret.jpg', 'bytes');

        $this->get('/files/uploads/secret.jpg')->assertForbidden();
    }
}
