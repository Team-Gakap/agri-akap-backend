<?php

namespace Tests\Unit;

use App\Support\CropPlotClaimer;
use PHPUnit\Framework\TestCase;

class CropPlotMatchTest extends TestCase
{
    public function test_masterlist_rice_and_corn_labels_match_the_form_crop(): void
    {
        $this->assertTrue(CropPlotClaimer::plotMatches('Rice/Palay', 'Rice'));
        $this->assertTrue(CropPlotClaimer::plotMatches('Palay', 'Rice'));
        $this->assertTrue(CropPlotClaimer::plotMatches('RiceWFallow', 'Rice'));
        $this->assertTrue(CropPlotClaimer::plotMatches('Lowland Rice', 'Rice'));
        $this->assertTrue(CropPlotClaimer::plotMatches('Maize', 'Corn'));
        $this->assertTrue(CropPlotClaimer::plotMatches('Mais', 'Corn'));
        $this->assertFalse(CropPlotClaimer::plotMatches('Corn', 'Rice'));
        $this->assertFalse(CropPlotClaimer::plotMatches('Rice/Palay', 'Corn'));
        $this->assertFalse(CropPlotClaimer::plotMatches('RiceWFallow', 'Corn'));
    }
}
