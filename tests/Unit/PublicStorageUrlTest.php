<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStorageUrlTest extends TestCase
{
    public function test_builds_url_from_relative_path(): void
    {
        config(['filesystems.disks.public.url' => 'https://api.example.test/storage']);

        $this->assertSame(
            'https://api.example.test/storage/farmer-photos/a.jpg',
            public_storage_url('farmer-photos/a.jpg')
        );
    }

    public function test_strips_storage_prefix(): void
    {
        config(['filesystems.disks.public.url' => 'https://api.example.test/storage']);

        $this->assertSame(
            'https://api.example.test/storage/farmer-photos/a.jpg',
            public_storage_url('storage/farmer-photos/a.jpg')
        );
    }

    public function test_returns_null_for_empty_path(): void
    {
        $this->assertNull(public_storage_url(null));
        $this->assertNull(public_storage_url(''));
    }
}
