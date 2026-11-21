<?php

namespace App\Services;

class IntegrationReference
{
    public function for(string $type, int $id): string
    {
        return rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            $type.':'.$id,
            (string) config('app.key'),
            true
        )), '+/', '-_'), '=');
    }
}
