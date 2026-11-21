<?php

namespace Database\Seeders;

use App\Models\IntegrationPartner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class IntegrationPartnerSeeder extends Seeder
{
    public function run(): void
    {
        if (! in_array(config('integrations.mode'), ['mock', 'live'], true)) {
            throw new \InvalidArgumentException('INTEGRATION_MODE must be mock or live.');
        }

        foreach (config('integrations.partners', []) as $code => $settings) {
            $partner = IntegrationPartner::firstOrNew(['code' => $code]);
            $partner->name = $settings['name'];
            $partner->active = true;
            $partner->mode = config('integrations.mode', 'mock');
            $partner->allowed_abilities = $settings['abilities'];
            $partner->base_url = $settings['base_url'] ?: null;
            if (filled($settings['api_key'])) {
                $partner->api_key_hash = Hash::make($settings['api_key']);
            }
            if (filled($settings['webhook_secret'])) {
                $partner->webhook_signing_secret = $settings['webhook_secret'];
            }
            if (filled($settings['outbound_token'])) {
                $partner->outbound_token = $settings['outbound_token'];
            }
            $partner->save();
        }
    }
}
