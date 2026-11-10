@props(['hasCurrentGrant' => false])

@unless ($hasCurrentGrant)
    <div {{ $attributes->class(['rounded-xl border border-amber-200 bg-amber-50 p-4']) }}>
        <label for="data_privacy_consent" class="registration-consent flex items-start gap-3 text-sm text-amber-950">
            <input
                id="data_privacy_consent"
                type="checkbox"
                name="data_privacy_consent"
                value="1"
                required
                @checked(old('data_privacy_consent'))
                class="registration-consent-checkbox mt-1 h-4 w-4 shrink-0 rounded border-amber-400 text-teal-700 focus:ring-teal-600"
            >
            <span>
                <strong>{{ config('consents.types.DATA_PRIVACY.title') }} (v{{ config('consents.types.DATA_PRIVACY.version') }})</strong><br>
                {{ config('consents.types.DATA_PRIVACY.text') }}
                <a class="font-semibold underline" href="{{ route('privacy.notice') }}" target="_blank" rel="noopener">Read the Privacy Notice</a>.
            </span>
        </label>
        @error('data_privacy_consent')
            <p class="registration-consent-error mt-2 text-sm text-red-700" role="alert">{{ $message }}</p>
        @enderror
    </div>
@endunless
