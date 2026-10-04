<?php

namespace App\Services;

use App\Models\Patient;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use InvalidArgumentException;

class PatientQrCodeService
{
    public function payload(Patient $patient): string
    {
        return (string) $patient->reference_code;
    }

    public function dataUri(Patient $patient): string
    {
        return $this->dataUriFor($this->payload($patient));
    }

    public function dataUriFor(string $payload): string
    {
        $svg = (new SvgWriter)->write($this->qrCodeForPayload($payload))->getString();

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function qrCode(Patient $patient): QrCode
    {
        return $this->qrCodeForPayload($this->payload($patient));
    }

    public function qrCodeForPayload(string $payload): QrCode
    {
        if ($payload === '') {
            throw new InvalidArgumentException('A QR code payload is required.');
        }

        return new QrCode(
            $payload,
            new Encoding('UTF-8'),
            ErrorCorrectionLevel::High,
            240,
            10,
            RoundBlockSizeMode::Margin,
        );
    }
}
