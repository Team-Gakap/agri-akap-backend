<?php

namespace App\Http\Requests\Concerns;

use App\Support\OfficialBarangays;
use App\Support\RsbsaTenurialDocuments;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesFarmerLocationsAndTenure
{
    protected function validateLocationBarangays(Validator $validator): void
    {
        $this->validateBarangayForCity(
            $validator,
            'permanent_brgy',
            'permanent_city',
            'Permanent barangay'
        );

        $provincialCity = $this->input('provincial_city');
        if (is_string($provincialCity) && trim($provincialCity) !== '') {
            $this->validateBarangayForCity(
                $validator,
                'provincial_brgy',
                'provincial_city',
                'Provincial/mailing barangay'
            );
        }

        $plots = $this->input('plots', []);
        if (is_array($plots)) {
            foreach ($plots as $index => $plot) {
                if (! is_array($plot)) {
                    continue;
                }
                $this->validateBarangayForCity(
                    $validator,
                    "plots.{$index}.location_brgy",
                    "plots.{$index}.location_city",
                    'Farm plot barangay',
                    $plot['location_brgy'] ?? null,
                    $plot['location_city'] ?? null
                );
            }
        }
    }

    protected function validatePlotTenurialDocuments(Validator $validator): void
    {
        $plots = $this->input('plots', []);
        if (! is_array($plots)) {
            return;
        }

        foreach ($plots as $index => $plot) {
            if (! is_array($plot)) {
                continue;
            }

            $document = $plot['proof_of_ownership_document'] ?? null;
            if ($document === null || trim((string) $document) === '') {
                continue;
            }

            if (! RsbsaTenurialDocuments::isAllowed($plot, (string) $document)) {
                $validator->errors()->add(
                    "plots.{$index}.proof_of_ownership_document",
                    'Select a valid proof of ownership / tenurial document for the chosen tenure status.'
                );
            }

            if (RsbsaTenurialDocuments::requiresOtherSpecify((string) $document)) {
                $other = trim((string) ($plot['proof_of_ownership_other'] ?? ''));
                if ($other === '') {
                    $validator->errors()->add(
                        "plots.{$index}.proof_of_ownership_other",
                        'Please specify the proof of ownership / tenurial document.'
                    );
                }
            }

            if (($plot['ownership_type'] ?? '') === 'Others') {
                $ownershipOther = trim((string) ($plot['ownership_type_other'] ?? ''));
                if ($ownershipOther === '') {
                    $validator->errors()->add(
                        "plots.{$index}.ownership_type_other",
                        'Please specify the ownership / tenurial status.'
                    );
                }
            }

            if (($plot['farm_type'] ?? '') === 'Other') {
                $farmTypeOther = trim((string) ($plot['farm_type_other'] ?? ''));
                if ($farmTypeOther === '') {
                    $validator->errors()->add(
                        "plots.{$index}.farm_type_other",
                        'Please specify the farm type.'
                    );
                }
            }
        }
    }

    private function validateBarangayForCity(
        Validator $validator,
        string $brgyKey,
        string $cityKey,
        string $label,
        mixed $brgy = null,
        mixed $city = null,
    ): void {
        $brgyValue = $brgy ?? $this->input($brgyKey);
        $cityValue = $city ?? $this->input($cityKey);

        if (! is_string($brgyValue) || trim($brgyValue) === '') {
            return;
        }

        if (! OfficialBarangays::isEchagueCity(is_string($cityValue) ? $cityValue : null)) {
            return;
        }

        if (! in_array(trim($brgyValue), OfficialBarangays::names(), true)) {
            $validator->errors()->add($brgyKey, "{$label} must be an official Echague barangay.");
        }
    }
}
