<?php
use App\Core\Router;
use App\Core\Middleware;
use App\Controllers\AuthController;
use App\Controllers\ProjectController;
use App\Controllers\FolderController;
use App\Controllers\AreaController;
use App\Controllers\IsochroneController;
use App\Controllers\GeocodingController;
use App\Controllers\PlacesController;
use App\Controllers\DemographicsController;
use App\Controllers\HeatmapController;
use App\Controllers\ReachController;
use App\Controllers\ImportController;
use App\Controllers\ExportController;
use App\Controllers\ReportController;
use App\Controllers\BillingController;

return function (Router $r) {
    $auth = [Middleware::auth()];

    // Auth
    $r->post('/api/auth/register', [AuthController::class, 'register']);
    $r->post('/api/auth/login', [AuthController::class, 'login']);
    $r->post('/api/auth/refresh', [AuthController::class, 'refresh'], $auth);
    $r->get('/api/auth/me', [AuthController::class, 'me'], $auth);

    // Projects
    $r->get('/api/projects', [ProjectController::class, 'index'], $auth);
    $r->post('/api/projects', [ProjectController::class, 'store'], $auth);
    $r->get('/api/projects/{id}', [ProjectController::class, 'show'], $auth);
    $r->put('/api/projects/{id}', [ProjectController::class, 'update'], $auth);
    $r->delete('/api/projects/{id}', [ProjectController::class, 'destroy'], $auth);
    $r->get('/api/shared/{shareToken}', [ProjectController::class, 'shared']);

    // Folders
    $r->get('/api/projects/{projectId}/folders', [FolderController::class, 'index'], $auth);
    $r->post('/api/projects/{projectId}/folders', [FolderController::class, 'store'], $auth);
    $r->put('/api/folders/{id}', [FolderController::class, 'update'], $auth);
    $r->delete('/api/folders/{id}', [FolderController::class, 'destroy'], $auth);

    // Areas
    $r->get('/api/projects/{projectId}/areas', [AreaController::class, 'index'], $auth);
    $r->post('/api/projects/{projectId}/areas', [AreaController::class, 'store'], $auth);
    $r->get('/api/areas/{id}', [AreaController::class, 'show'], $auth);
    $r->put('/api/areas/{id}', [AreaController::class, 'update'], $auth);
    $r->delete('/api/areas/{id}', [AreaController::class, 'destroy'], $auth);
    $r->get('/api/areas/{id}/demographics', [DemographicsController::class, 'show'], $auth);
    $r->get('/api/areas/{id}/pois', [PlacesController::class, 'forArea'], $auth);
    $r->post('/api/demographics/compare', [DemographicsController::class, 'compare'], $auth);

    // Heatmap viewport tracts (choropleth)
    $r->get('/api/heatmap/tracts', [HeatmapController::class, 'tracts'], $auth);

    // Smart area sizing + live demographics preview
    $r->post('/api/areas/reach', [ReachController::class, 'calculate'], $auth);
    $r->post('/api/demographics/preview', [ReachController::class, 'preview'], $auth);

    // Isochrone
    $r->post('/api/isochrone/calculate', [IsochroneController::class, 'calculate'], $auth);

    // Geocoding
    $r->post('/api/geocode', [GeocodingController::class, 'geocode'], $auth);
    $r->post('/api/geocode/batch', [GeocodingController::class, 'batchGeocode'], $auth);

    // Places
    $r->post('/api/places/nearby', [PlacesController::class, 'nearby'], $auth);
    $r->post('/api/places/search', [PlacesController::class, 'search'], $auth);
    $r->get('/api/places/{placeId}', [PlacesController::class, 'show'], $auth);

    // Import / Export
    $r->post('/api/projects/{projectId}/import/upload', [ImportController::class, 'upload'], $auth);
    $r->post('/api/projects/{projectId}/import/configure', [ImportController::class, 'configure'], $auth);
    $r->get('/api/imports/{batchId}/status', [ImportController::class, 'status'], $auth);
    $r->delete('/api/imports/{batchId}', [ImportController::class, 'deleteImport'], $auth);
    $r->get('/api/projects/{projectId}/export/areas', [ExportController::class, 'exportAreas'], $auth);
    $r->get('/api/areas/{areaId}/export/pois', [ExportController::class, 'exportPOIs'], $auth);
    $r->get('/api/projects/{projectId}/export/points', [ExportController::class, 'exportImportedPoints'], $auth);
    $r->get('/api/exports/{filename}', [ExportController::class, 'download'], $auth);

    // Reports
    $r->post('/api/areas/{id}/report', [ReportController::class, 'generate'], $auth);
    $r->get('/api/reports', [ReportController::class, 'list'], $auth);
    $r->get('/api/reports/{id}/download', [ReportController::class, 'download'], $auth);

    // Billing
    $r->post('/api/billing/checkout', [BillingController::class, 'createCheckout'], $auth);
    $r->post('/api/billing/webhook', [BillingController::class, 'webhook']);
    $r->get('/api/billing/subscription', [BillingController::class, 'subscription'], $auth);
    $r->post('/api/billing/portal', [BillingController::class, 'portal'], $auth);
    $r->post('/api/billing/cancel', [BillingController::class, 'cancel'], $auth);
};
