<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\BillItem;
use App\Models\ChargeRate;
use App\Models\Encounter;
use App\Models\EncounterBill;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingAggregationService
{
    public function previewForEncounter(Encounter $encounter): array
    {
        $admission = $this->admissionForEncounter($encounter);
        $encounters = $admission
            ? $this->encountersForAdmission($admission)
            : collect([$encounter]);

        if (! $encounters->contains('id', $encounter->id)) {
            $encounters->push($encounter);
        }

        return $this->preview($encounter->patient, $encounters, $admission);
    }

    public function previewForAdmission(Admission $admission): array
    {
        return $this->preview($admission->patient, $this->encountersForAdmission($admission), $admission);
    }

    public function generateForEncounter(Encounter $encounter, User $actor): EncounterBill
    {
        $admission = $this->admissionForEncounter($encounter);
        $encounters = $admission
            ? $this->encountersForAdmission($admission)
            : collect([$encounter]);

        if (! $encounters->contains('id', $encounter->id)) {
            $encounters->push($encounter);
        }

        return $this->generate($encounter->patient, $encounters, $admission, $admission ? null : $encounter, $actor);
    }

    public function generateForAdmission(Admission $admission, User $actor): EncounterBill
    {
        return $this->generate($admission->patient, $this->encountersForAdmission($admission), $admission, null, $actor);
    }

    public function markPaid(EncounterBill $bill, string $reference, string $amount): EncounterBill
    {
        return DB::transaction(function () use ($bill, $reference, $amount): EncounterBill {
            $bill = EncounterBill::query()->lockForUpdate()->findOrFail($bill->id);
            $paidAmount = $this->formatCents($this->moneyToCents($amount));

            if ($bill->status === EncounterBill::STATUS_PAID) {
                if ($bill->payment_reference === $reference && $this->moneyToCents((string) $bill->amount_paid) === $this->moneyToCents($paidAmount)) {
                    return $bill->load('items');
                }

                throw ValidationException::withMessages([
                    'payment_reference' => ['This statement has already been paid and cannot be settled again.'],
                ]);
            }

            if ($this->moneyToCents((string) $bill->total) !== $this->moneyToCents($paidAmount)) {
                throw ValidationException::withMessages([
                    'amount_paid' => ['Payment amount must exactly match the statement balance.'],
                ]);
            }

            $bill->update([
                'status' => EncounterBill::STATUS_PAID,
                'amount_paid' => $paidAmount,
                'payment_reference' => $reference,
                'paid_at' => now(),
            ]);

            return $bill->fresh('items');
        });
    }

    public function dispense(Prescription $prescription, User $actor): Prescription
    {
        return DB::transaction(function () use ($prescription, $actor): Prescription {
            $prescription = Prescription::query()->lockForUpdate()->findOrFail($prescription->id);

            if ($prescription->dispensed_at) {
                return $prescription;
            }

            if ($this->isPrescriptionBilled($prescription)) {
                throw ValidationException::withMessages([
                    'prescription' => ['A prescription on a paid statement cannot be dispensed again.'],
                ]);
            }

            $prescription->update([
                'dispensed_at' => now(),
                'dispensed_by' => $actor->id,
                'partner_status' => 'DISPENSED',
                'partner_status_updated_at' => now(),
            ]);

            return $prescription->fresh();
        });
    }

    public function assertAdmissionCanDischarge(Admission $admission): void
    {
        $preview = $this->previewForAdmission($admission);
        $bill = EncounterBill::query()->where('admission_id', $admission->id)->with('items')->first();

        if (! $bill || $bill->status !== EncounterBill::STATUS_PAID) {
            throw ValidationException::withMessages([
                'billing' => ['Discharge is blocked until a current billing statement is generated and paid.'],
            ]);
        }

        if (! hash_equals($bill->source_fingerprint, $preview['source_fingerprint'])) {
            throw ValidationException::withMessages([
                'billing' => ['The statement is out of date. Regenerate and settle the current charges before discharge.'],
            ]);
        }
    }

    public function assertErVisitCanDischarge(\App\Models\ErVisit $visit): void
    {
        $encounter = Encounter::query()
            ->where('patient_id', $visit->patient_id)
            ->where('type', Encounter::TYPE_EMERGENCY)
            ->where('started_at', '>=', $visit->arrived_at)
            ->latest('started_at')
            ->first();

        if (! $encounter) {
            throw ValidationException::withMessages([
                'billing' => ['ER completion is blocked until a clinical encounter and its billing statement are available.'],
            ]);
        }

        $items = $this->buildItems(collect([$encounter]), null);
        $fingerprint = hash('sha256', json_encode([
            'patient_id' => $visit->patient_id,
            'admission_id' => null,
            'items' => array_map(fn (array $item) => [
                'source_type' => $item['source_type'],
                'source_id' => $item['source_id'],
                'quantity' => $item['quantity'],
                'unit_amount' => $item['unit_amount'],
                'rate_updated_at' => $item['rate_updated_at'],
                'source_updated_at' => $item['source_updated_at'],
            ], $items),
        ], JSON_THROW_ON_ERROR));
        $bill = EncounterBill::query()->where('encounter_id', $encounter->id)->first();

        if (! $bill || $bill->status !== EncounterBill::STATUS_PAID || ! hash_equals($bill->source_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages([
                'billing' => ['ER completion is blocked until the current encounter statement is generated and paid.'],
            ]);
        }
    }

    public function isPrescriptionBilled(Prescription $prescription): bool
    {
        return BillItem::query()
            ->where('prescription_id', $prescription->id)
            ->whereHas('bill', fn ($query) => $query->where('status', EncounterBill::STATUS_PAID))
            ->exists();
    }

    private function preview(Patient $patient, Collection $encounters, ?Admission $admission): array
    {
        $items = $this->buildItems($encounters, $admission);
        $totalCents = 0;
        foreach ($items as $item) {
            $lineCents = $this->moneyToCents($item['line_total']);
            if ($totalCents > 999_999_999_999 - $lineCents) {
                throw ValidationException::withMessages([
                    'billing' => ['The statement total exceeds the supported billing amount.'],
                ]);
            }
            $totalCents += $lineCents;
        }
        $total = $this->formatCents($totalCents);
        $fingerprint = hash('sha256', json_encode([
            'patient_id' => $patient->id,
            'admission_id' => $admission?->id,
            'items' => array_map(fn (array $item) => [
                'source_type' => $item['source_type'],
                'source_id' => $item['source_id'],
                'quantity' => $item['quantity'],
                'unit_amount' => $item['unit_amount'],
                'rate_updated_at' => $item['rate_updated_at'],
                'source_updated_at' => $item['source_updated_at'],
            ], $items),
        ], JSON_THROW_ON_ERROR));
        $billQuery = EncounterBill::query();
        $admission ? $billQuery->where('admission_id', $admission->id) : $billQuery->where('encounter_id', $encounters->last()?->id);
        $bill = $billQuery->with('items')->first();

        return [
            'patient_id' => $patient->id,
            'admission_id' => $admission?->id,
            'status' => $bill?->status ?? EncounterBill::STATUS_UNPAID,
            'statement_number' => $bill?->statement_number,
            'currency' => 'PHP',
            'subtotal' => $total,
            'total' => $total,
            'discount_flags' => [
                'senior' => filled($patient->senior_id_number)
                    && $patient->senior_id_verified_by !== null
                    && $patient->senior_id_verified_at !== null,
                'pwd' => filled($patient->pwd_id_number)
                    && $patient->pwd_id_verified_by !== null
                    && $patient->pwd_id_verified_at !== null,
            ],
            'source_fingerprint' => $fingerprint,
            'is_current' => $bill ? hash_equals($bill->source_fingerprint, $fingerprint) : false,
            'items' => collect($items)->map(fn (array $item) => collect($item)->except(['rate_updated_at', 'source_updated_at'])->all())->values()->all(),
        ];
    }

    private function generate(
        Patient $patient,
        Collection $encounters,
        ?Admission $admission,
        ?Encounter $rootEncounter,
        User $actor,
    ): EncounterBill {
        return DB::transaction(function () use ($patient, $encounters, $admission, $rootEncounter, $actor): EncounterBill {
            $preview = $this->preview($patient, $encounters, $admission);
            $query = EncounterBill::query()->lockForUpdate();
            $admission ? $query->where('admission_id', $admission->id) : $query->where('encounter_id', $rootEncounter?->id);
            $bill = $query->first();

            if ($bill && $bill->status === EncounterBill::STATUS_PAID) {
                if (hash_equals($bill->source_fingerprint, $preview['source_fingerprint'])) {
                    return $bill->load('items');
                }

                throw ValidationException::withMessages([
                    'billing' => ['A paid statement cannot be altered. Contact billing support to resolve changed charges.'],
                ]);
            }

            $attributes = [
                'patient_id' => $patient->id,
                'encounter_id' => $rootEncounter?->id,
                'admission_id' => $admission?->id,
                'status' => EncounterBill::STATUS_UNPAID,
                'currency' => $preview['currency'],
                'subtotal' => $preview['subtotal'],
                'total' => $preview['total'],
                'source_fingerprint' => $preview['source_fingerprint'],
                'generated_by' => $actor->id,
                'generated_at' => now(),
            ];

            if ($bill) {
                $bill->update($attributes);
                $bill->items()->delete();
            } else {
                $bill = EncounterBill::create(array_merge($attributes, [
                    'statement_number' => 'BILL-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
                ]));
            }

            foreach ($preview['items'] as $item) {
                $bill->items()->create($item);
            }

            app(IntegrationOutboxService::class)->enqueue('bdms', 'billing_statement', $bill->id, [
                'purpose' => 'billing_statement',
                'version' => $bill->source_fingerprint,
            ]);

            return $bill->fresh('items');
        });
    }

    private function buildItems(Collection $encounters, ?Admission $admission): array
    {
        $items = [];
        foreach ($encounters as $encounter) {
            $rate = $this->encounterRate($encounter);
            $items[] = $this->line(
                $rate,
                'ENCOUNTER',
                $encounter->id,
                $encounter->id,
                null,
                null,
                'Clinical encounter '.$encounter->encounter_number,
                1,
                $encounter->updated_at,
            );
        }

        $encounterIds = $encounters->pluck('id');
        $appointmentIds = $encounters->pluck('appointment_id')->filter();
        $prescriptions = Prescription::query()
            ->whereNotNull('dispensed_at')
            ->where(function ($query) use ($encounterIds, $appointmentIds): void {
                $query->whereIn('encounter_id', $encounterIds);
                if ($appointmentIds->isNotEmpty()) {
                    $query->orWhereHas('telehealthSession', fn ($session) => $session->whereIn('appointment_id', $appointmentIds));
                }
            })
            ->get();

        foreach ($prescriptions as $prescription) {
            $rate = $this->activeRate('prescription:'.Str::slug($prescription->medication_name))
                ?? $this->requiredRate('prescription:default', 'Default prescription unit price for '.$prescription->medication_name);
            $linkedEncounter = $encounters->firstWhere('id', $prescription->encounter_id)
                ?? $encounters->firstWhere('appointment_id', $prescription->telehealthSession?->appointment_id);
            $items[] = $this->line(
                $rate,
                'PRESCRIPTION',
                $prescription->id,
                $linkedEncounter?->id,
                $prescription->id,
                null,
                'Dispensed prescription: '.$prescription->medication_name,
                $prescription->quantity,
                $prescription->updated_at,
            );
        }

        if ($admission) {
            if (! $admission->admitted_at) {
                throw ValidationException::withMessages([
                    'billing' => ['Cannot calculate bed charges because the admission timestamp is missing.'],
                ]);
            }

            $rate = $this->bedRate($admission);
            $now = now();
            $elapsedSeconds = max(0, $admission->admitted_at->diffInSeconds($now, false));
            $days = max(1, (int) ceil($elapsedSeconds / 86400));
            $items[] = $this->line(
                $rate,
                'BED_STAY',
                $admission->id,
                null,
                null,
                $admission->id,
                'Inpatient bed stay ('.$days.' day'.($days === 1 ? '' : 's').')',
                $days,
                $admission->updated_at,
            );
        }

        return $items;
    }

    private function line(
        ChargeRate $rate,
        string $sourceType,
        int $sourceId,
        ?int $encounterId,
        ?int $prescriptionId,
        ?int $admissionId,
        string $description,
        int $quantity,
        mixed $sourceUpdatedAt,
    ): array {
        $unitCents = $this->moneyToCents((string) $rate->unit_amount);
        if ($unitCents > 999_999_999_999 || ($quantity > 0 && $unitCents > intdiv(999_999_999_999, $quantity))) {
            throw ValidationException::withMessages([
                'billing' => ['The configured rate and quantity exceed the supported line-item amount.'],
            ]);
        }
        $lineCents = $unitCents * $quantity;
        $unitAmount = $this->formatCents($unitCents);

        return [
            'charge_rate_id' => $rate->id,
            'encounter_id' => $encounterId,
            'prescription_id' => $prescriptionId,
            'admission_id' => $admissionId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'description' => $description,
            'quantity' => number_format($quantity, 2, '.', ''),
            'unit_amount' => $unitAmount,
            'line_total' => $this->formatCents($lineCents),
            'currency' => $rate->currency,
            'rate_snapshot' => [
                'charge_code' => $rate->charge_code,
                'description' => $rate->description,
                'unit_amount' => $unitAmount,
                'currency' => $rate->currency,
            ],
            'rate_updated_at' => $rate->updated_at?->toIso8601String(),
            'source_updated_at' => $sourceUpdatedAt?->toIso8601String(),
        ];
    }

    private function requiredRate(string $code, string $label): ChargeRate
    {
        $rate = $this->activeRate($code);

        if (! $rate) {
            throw ValidationException::withMessages([
                'billing' => ["Missing active charge rate [{$code}]: {$label}. Configure it before generating a statement."],
            ]);
        }

        return $rate;
    }

    private function activeRate(string $code): ?ChargeRate
    {
        $rate = ChargeRate::query()->where('charge_code', $code)->where('active', true)->first();
        if (! $rate) {
            return null;
        }

        if ($rate->currency !== 'PHP' || $this->moneyToCents((string) $rate->unit_amount) < 1) {
            throw ValidationException::withMessages([
                'billing' => ["Charge rate [{$code}] must have a positive amount in PHP."],
            ]);
        }

        return $rate;
    }

    private function encounterRate(Encounter $encounter): ChargeRate
    {
        $encounter->loadMissing('provider.specialties');
        $specialty = $encounter->provider?->specialties?->sortBy('name')->first();
        $specialtyCode = $specialty ? 'consultation:'.Str::slug($specialty->name) : null;

        if ($specialtyCode) {
            $rate = $this->activeRate($specialtyCode);
            if ($rate) {
                return $rate;
            }
        }

        return $this->requiredRate('encounter:'.$encounter->type, 'Encounter fee for '.$encounter->type);
    }

    private function bedRate(Admission $admission): ChargeRate
    {
        $wardType = $admission->bedAssignments()
            ->where('status', \App\Models\BedAssignment::STATUS_ACTIVE)
            ->with('bed.room.ward')
            ->first()?->bed?->room?->ward?->type;
        $type = Str::lower(trim((string) $wardType));
        $bedCode = match ($type) {
            'general medicine', 'general' => 'bed:general-medicine',
            'pediatrics', 'pediatric' => 'bed:pediatrics',
            'critical care', 'icu' => 'bed:critical-care',
            'private', 'private rooms' => 'bed:private',
            default => null,
        };

        if ($bedCode) {
            $rate = $this->activeRate($bedCode);
            if ($rate) {
                return $rate;
            }
        }

        return $this->requiredRate('bed:default', 'Daily bed rate');
    }

    private function moneyToCents(string $amount): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $matches)) {
            throw ValidationException::withMessages([
                'billing' => ['A billing amount is not a valid non-negative PHP currency value.'],
            ]);
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');
        if ($whole > intdiv(PHP_INT_MAX - $fraction, 100)) {
            throw ValidationException::withMessages([
                'billing' => ['A billing amount exceeds the supported currency precision.'],
            ]);
        }

        return $whole * 100 + $fraction;
    }

    private function formatCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function activeAdmissionFor(int $patientId): ?Admission
    {
        return Admission::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', [Admission::STATUS_ADMITTED, Admission::STATUS_TRANSFERRED])
            ->latest('admitted_at')
            ->first();
    }

    private function admissionForEncounter(Encounter $encounter): ?Admission
    {
        $admission = $this->activeAdmissionFor($encounter->patient_id);

        return $admission && $admission->admitted_at && $encounter->started_at->greaterThanOrEqualTo($admission->admitted_at)
            ? $admission
            : null;
    }

    private function encountersForAdmission(Admission $admission): Collection
    {
        return Encounter::query()
            ->where('patient_id', $admission->patient_id)
            ->whereIn('status', ['OPEN', 'COMPLETED'])
            ->when($admission->admitted_at, fn ($query) => $query->where('started_at', '>=', $admission->admitted_at))
            ->orderBy('started_at')
            ->get();
    }
}
