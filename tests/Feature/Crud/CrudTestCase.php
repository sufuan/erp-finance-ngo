<?php

namespace Tests\Feature\Crud;

use App\Models\Branch;
use App\Models\Samity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Full-app CRUD sweep (owner's standing gate): every built module gets
 * exercised through its REAL routes with payloads shaped exactly like
 * the React forms submit them — multipart bodies, `_method` spoofing for
 * edits, and real image uploads — as the seeded-permission super admin.
 *
 * The seeded test database is the fixture (branches 101/102, samities,
 * members, products); DatabaseTransactions rolls each test back.
 */
abstract class CrudTestCase extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    protected function branch(string $code): Branch
    {
        return Branch::where('code', $code)->firstOrFail();
    }

    protected function samity(string $name = 'Talgachi Samity'): Samity
    {
        return Samity::where('name', $name)->firstOrFail();
    }

    /** A real decodable PNG — UploadService re-encodes images via GD. */
    protected function realPng(string $name = 'photo.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'crud').'.png';
        file_put_contents($tmp, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    protected function fakeImage(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 120, 120);
    }
}
