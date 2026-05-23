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
use App\Controllers\TrafficIsochroneController;
use App\Controllers\CannibalizationController;
use App\Controllers\TerritoryController;
use App\Controllers\MclpController;
use App\Controllers\SegmentationController;
use App\Controllers\CollaborationController;
use App\Controllers\NotificationController;
use App\Controllers\CompetitorController;
use App\Controllers\FieldNoteController;

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

    // Traffic-aware isochrones
    $r->post('/api/isochrone/traffic', [TrafficIsochroneController::class, 'calculate'], $auth);
    $r->post('/api/isochrone/traffic/grid', [TrafficIsochroneController::class, 'grid'], $auth);

    // Cannibalization
    $r->get('/api/projects/{projectId}/cannibalization', [CannibalizationController::class, 'analyze'], $auth);

    // Territory generation
    $r->post('/api/projects/{projectId}/territories/generate', [TerritoryController::class, 'generate'], $auth);
    $r->get('/api/projects/{projectId}/territories/jobs', [TerritoryController::class, 'listJobs'], $auth);

    // Multi-location optimization (MCLP)
    $r->post('/api/projects/{projectId}/optimize/locations', [MclpController::class, 'optimize'], $auth);

    // Segmentation
    $r->get('/api/segmentation/segments', [SegmentationController::class, 'catalog'], $auth);
    $r->get('/api/areas/{id}/segments', [SegmentationController::class, 'forArea'], $auth);
    $r->post('/api/projects/{projectId}/segments', [SegmentationController::class, 'forProject'], $auth);
    $r->post('/api/segmentation/recompute', [SegmentationController::class, 'recompute'], $auth);

    // Collaboration — versions
    $r->post('/api/projects/{projectId}/versions', [CollaborationController::class, 'snapshotVersion'], $auth);
    $r->get('/api/projects/{projectId}/versions', [CollaborationController::class, 'listVersions'], $auth);
    $r->get('/api/versions/{id}', [CollaborationController::class, 'showVersion'], $auth);

    // Collaboration — comments
    $r->get('/api/projects/{projectId}/comments', [CollaborationController::class, 'listComments'], $auth);
    $r->post('/api/projects/{projectId}/comments', [CollaborationController::class, 'createComment'], $auth);
    $r->post('/api/comments/{id}/resolve', [CollaborationController::class, 'resolveComment'], $auth);
    $r->delete('/api/comments/{id}', [CollaborationController::class, 'deleteComment'], $auth);

    // Collaboration — change log
    $r->get('/api/projects/{projectId}/changes', [CollaborationController::class, 'listChanges'], $auth);

    // Collaboration — collaborators
    $r->get('/api/projects/{projectId}/collaborators', [CollaborationController::class, 'listCollaborators'], $auth);
    $r->post('/api/projects/{projectId}/collaborators', [CollaborationController::class, 'addCollaborator'], $auth);
    $r->delete('/api/projects/{projectId}/collaborators/{userId}', [CollaborationController::class, 'removeCollaborator'], $auth);

    // Collaboration — approvals
    $r->post('/api/projects/{projectId}/approvals', [CollaborationController::class, 'createApproval'], $auth);
    $r->get('/api/projects/{projectId}/approvals', [CollaborationController::class, 'listApprovals'], $auth);
    $r->post('/api/approvals/{id}/decide', [CollaborationController::class, 'decideApproval'], $auth);

    // Notifications
    $r->get('/api/notifications', [NotificationController::class, 'index'], $auth);
    $r->post('/api/notifications/{id}/read', [NotificationController::class, 'markRead'], $auth);
    $r->post('/api/notifications/read-all', [NotificationController::class, 'markAllRead'], $auth);

    // Competitor monitoring
    $r->get('/api/projects/{projectId}/competitor-monitors', [CompetitorController::class, 'index'], $auth);
    $r->post('/api/projects/{projectId}/competitor-monitors', [CompetitorController::class, 'create'], $auth);
    $r->get('/api/competitor-monitors/{id}', [CompetitorController::class, 'show'], $auth);
    $r->put('/api/competitor-monitors/{id}', [CompetitorController::class, 'update'], $auth);
    $r->delete('/api/competitor-monitors/{id}', [CompetitorController::class, 'destroy'], $auth);
    $r->post('/api/competitor-monitors/{id}/scan', [CompetitorController::class, 'scanNow'], $auth);
    $r->get('/api/competitor-monitors/{id}/places', [CompetitorController::class, 'listPlaces'], $auth);
    $r->get('/api/competitor-monitors/{id}/alerts', [CompetitorController::class, 'listAlerts'], $auth);
    $r->post('/api/competitor-alerts/{id}/read', [CompetitorController::class, 'markAlertRead'], $auth);

    // Field notes (mobile PWA)
    $r->get('/api/projects/{projectId}/field-notes', [FieldNoteController::class, 'index'], $auth);
    $r->post('/api/projects/{projectId}/field-notes', [FieldNoteController::class, 'create'], $auth);
    $r->delete('/api/field-notes/{id}', [FieldNoteController::class, 'destroy'], $auth);
    $r->get('/api/projects/{projectId}/where-am-i', [FieldNoteController::class, 'whereAmI'], $auth);
};
