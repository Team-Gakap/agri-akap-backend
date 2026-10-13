<?php

namespace Tests\Unit;

use App\Support\HvccCatalog;
use PHPUnit\Framework\TestCase;

class HvccCatalogMatchTest extends TestCase
{
    public function test_masterlist_rice_and_corn_labels_match_the_form_crop(): void
    {
        $this->assertTrue(HvccCatalog::plotMatches('Rice/Palay', 'Rice'));
        $this->assertTrue(HvccCatalog::plotMatches('Palay', 'Rice'));
        $this->assertTrue(HvccCatalog::plotMatches('Maize', 'Corn'));
        $this->assertFalse(HvccCatalog::plotMatches('Corn', 'Rice'));
        $this->assertFalse(HvccCatalog::plotMatches('Rice/Palay', 'Corn'));
    }
}
