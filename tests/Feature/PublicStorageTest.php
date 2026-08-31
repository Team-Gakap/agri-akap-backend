<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStorageTest extends TestCase
{
    public function test_serves_a_file_from_the_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('farmer-photos/test.jpg', 'jpeg-bytes');

        $this->get('/storage/farmer-photos/test.jpg')->assertOk();
    }

    public function test_rejects_path_traversal(): void
    {
        $this->get('/storage/../.env')->assertNotFound();
    }

    public function test_missing_file_is_not_found(): void
    {
        Storage::fake('public');

        $this->get('/storage/farmer-photos/missing.jpg')->assertNotFound();
    }
}
