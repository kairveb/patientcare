<?php

namespace App\Console\Commands;

use App\Models\PatientAddress;
use App\Services\PhilippineAddressDataset;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ReportInvalidPatientPsgcAddresses extends Command
{
    protected $signature = 'hims:patients:report-invalid-psgc-addresses';

    protected $description = 'Report patient addresses that do not match the local PSGC dataset';

    public function handle(PhilippineAddressDataset $dataset): int
    {
        $reported = 0;

        PatientAddress::query()
            ->orderBy('id')
            ->chunkById(500, function ($addresses) use ($dataset, &$reported): void {
                foreach ($addresses as $address) {
                    $selection = [];
                    foreach (['region', 'province', 'city', 'submunicipality', 'barangay'] as $level) {
                        $selection[$level] = $address->{$level};
                        $selection[$level.'_psgc_code'] = $address->{$level.'_psgc_code'};
                    }

                    try {
                        if ($dataset->validateSelection($selection) === []) {
                            $invalidFields = ['incomplete_address'];
                        } else {
                            continue;
                        }
                    } catch (ValidationException $exception) {
                        $invalidFields = array_keys($exception->errors());
                    }

                    $reported++;
                    $this->line(sprintf(
                        'address_id=%d patient_id=%d invalid_fields=%s',
                        $address->id,
                        $address->patient_id,
                        implode(',', $invalidFields)
                    ));
                }
            });

        $this->info("Reported {$reported} patient address(es) that do not match the PSGC dataset.");

        return self::SUCCESS;
    }
}
