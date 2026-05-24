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
            // No explicit logApiUsage — the rateLimit middleware already
            // wrote the row WITH cost via GooglePricing. Calling it here
            // too would duplicate the row and inflate the call count 2×.
            $cost = \App\Services\GooglePricing::costFor('geocode');
            $result['_meta'] = ['api_name' => 'geocode', 'estimated_cost_usd' => $cost];
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
        $count = count($addresses);
        $svc->logApiUsage('geocode_batch', $request->user['id'], 'geocode', $count);
        $result['_meta'] = [
            'api_name' => 'geocode',
            'request_count' => $count,
            'estimated_cost_usd' => \App\Services\GooglePricing::costFor('geocode', $count),
        ];
        Response::success($result);
    }
}
