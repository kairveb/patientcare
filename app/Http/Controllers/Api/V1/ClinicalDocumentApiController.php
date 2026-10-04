<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClinicalDocument;
use App\Models\Patient;
use App\Services\PatientIdentifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ClinicalDocumentApiController extends Controller
{
    public function index(Request $request, int $patientId): JsonResponse
    {
        $patient = Patient::findOrFail($patientId);

        abort_unless(Gate::allows('view', $patient), 403, 'You are not authorized.');

        $documents = ClinicalDocument::where('patient_id', $patient->id)
            ->when(
                ! $this->canViewIdentityDocuments($request, $patient),
                fn ($query) => $query->whereNull('identifier_type')
            )
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Clinical documents retrieved successfully.',
            'data' => $documents,
        ]);
    }

    public function store(Request $request, int $patientId): JsonResponse
    {
        $patient = Patient::findOrFail($patientId);

        abort_unless(Gate::allows('update', $patient), 403, 'You are not authorized.');

        $data = $request->validate([
            'document' => ['required', 'file', 'max:20480'],
            'name' => ['nullable', 'string', 'max:255'],
            'encounter_id' => ['nullable', 'exists:encounters,id'],
        ]);

        $file = $request->file('document');
        $document = $this->createDocument(
            $request,
            $patient,
            $file,
            $data['name'] ?? $file->getClientOriginalName(),
            $data['encounter_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Clinical document uploaded successfully.',
            'data' => $document,
        ], 201);
    }

    public function storeOwnIdentityDocument(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->hasRole('patient'), 403);
        $patient = $request->user()->patient()->firstOrFail();
        $data = $request->validate([
            'identifier_type' => ['required', 'string', Rule::in(array_keys(PatientIdentifierService::verificationColumns()))],
            'document' => ['required', 'image', 'max:10240'],
        ]);
        $file = $request->file('document');
        $document = $this->createDocument(
            $request,
            $patient,
            $file,
            str_replace('_', ' ', ucfirst($data['identifier_type'])).' image',
            null,
            $data['identifier_type']
        );

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Identity document uploaded for Registration Staff review.',
                'data' => $document,
            ], 201);
        }

        return redirect()->route('patients.profile')
            ->with('success', 'Identity document uploaded for Registration Staff review.');
    }

    public function show(Request $request, int $patientId, int $id)
    {
        $patient = Patient::findOrFail($patientId);

        abort_unless(Gate::allows('view', $patient), 403, 'You are not authorized.');

        $document = ClinicalDocument::where('patient_id', $patient->id)->findOrFail($id);
        abort_unless(
            $document->identifier_type === null || $this->canViewIdentityDocuments($request, $patient),
            403,
            'You are not authorized to view this identity document.'
        );

        if ($request->boolean('download')) {
            return Storage::download($document->path, $document->name);
        }

        return response()->json([
            'success' => true,
            'message' => 'Clinical document retrieved successfully.',
            'data' => $document,
        ]);
    }

    private function createDocument(
        Request $request,
        Patient $patient,
        \Illuminate\Http\UploadedFile $file,
        string $name,
        ?int $encounterId = null,
        ?string $identifierType = null
    ): ClinicalDocument {
        return ClinicalDocument::create([
            'patient_id' => $patient->id,
            'encounter_id' => $encounterId,
            'uploaded_by' => $request->user()->id,
            'name' => $name,
            'path' => $file->store('clinical_documents'),
            'mime_type' => $file->getClientMimeType(),
            'identifier_type' => $identifierType,
        ]);
    }

    private function canViewIdentityDocuments(Request $request, Patient $patient): bool
    {
        $user = $request->user();

        return $user->hasRole('registration', 'super-admin')
            || ($user->hasRole('patient') && $patient->user_id === $user->id);
    }
}
