<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\PlanLimits;
use App\Services\GoogleMapsService;

class GeocodingController
{
    public function geocode(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $address = trim($body['address'] ?? '');
        if ($address === '') Response::error('Address is required');

        try {
            $svc = new GoogleMapsService();
            $result = $svc->geocode($address);
            $svc->logApiUsage('geocode', $request->user['id']);
            Response::success($result);
        } catch (\Throwable $e) {
            Response::error('Geocoding failed: ' . $e->getMessage(), 502);
        }
    }

    public function batchGeocode(Request $request): void
    {
        $body = $request->getBody() ?? [];
        $addresses = $body['addresses'] ?? [];
        if (!is_array($addresses) || empty($addresses)) Response::error('addresses must be a non-empty array');
        if (count($addresses) > 500) Response::error('Maximum 500 addresses per batch');

        $plan = $request->user['plan'] ?? 'free';
        $perBatch = match ($plan) {
            'free' => 10,
            'starter' => 100,
            default => 500,
        };
        if (count($addresses) > $perBatch) {
            Response::error("Batch size $perBatch max on $plan plan", 403);
        }

        $svc = new GoogleMapsService();
        $result = $svc->batchGeocode($addresses);
        $svc->logApiUsage('geocode_batch', $request->user['id']);
        Response::success($result);
    }
}
