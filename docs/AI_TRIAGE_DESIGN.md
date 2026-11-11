# AI triage design

## Purpose and limits

The symptom-checker provides care-routing suggestions and follow-up questions. It is not a diagnostic tool and must not replace emergency assessment or clinician judgment. The application does not train or fine-tune an AI model.

When `AI_PROVIDER` is configured as `gemini` or `openai` and the provider has an API key, the application sends a request to that provider's already-trained external model. If the provider is unavailable, unconfigured, or returns an invalid response, the application falls back to its deterministic rule-based result. `AI_PROVIDER=rules` uses only those local rules.

## Safety and clinical review

`App\Services\AiTriageService` contains the predefined keyword, vital-sign, and age-group threshold rules. `App\Services\HybridAiTriageService` evaluates those rules alongside any external suggestion. Rule-based urgency upgrades and detected red flags take precedence over a less urgent external suggestion; critical vital thresholds can force Emergency. The service can also fall back to rules when the external result cannot be used safely.

The suggestion is not the final clinical decision. A clinician reviews and confirms the triage level; the project does not automate the clinician's final assessment.

## Information sent to an external provider

`HybridAiTriageService::userPrompt` sends symptom text and follow-up answers after replacing known identifiers and masking common email, phone, patient-reference, name, and address patterns. When `AI_SEND_AGE_SEX=true`, it additionally sends an age band and sex; otherwise those fields are omitted. The system prompt includes configured specialty and active department names. Provider/API credentials are read from environment-backed service configuration. The external AI provider may process requests under its own terms; deployments should review the applicable provider agreement and privacy settings.

The application stores submitted symptom records for care continuity. That local persistence is separate from the minimized request sent to the external provider.

## Evaluation is not model training

Clinician evaluation records and exports measure agreement, overrides, and observed outcomes for review. They do not update model weights, fine-tune a provider, or form an automated training pipeline.
