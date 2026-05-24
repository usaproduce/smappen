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
use App\Controllers\HealthController;
use App\Controllers\JobController;
use App\Controllers\WebhookSubscriptionController;
use App\Controllers\PublicShareController;
use App\Controllers\AiScoringController;
use App\Controllers\OpenApiController;
use App\Controllers\UsageController;

return function (Router $r) {
    $auth = [Middleware::auth()];
    // Rate-limit profiles — windowed counts on api_usage_log.
    // Tuned so legitimate heavy users never hit them but bots/runaway scripts do.
    $rlGeocode      = [Middleware::auth(), Middleware::rateLimit('geocode',         500,  3600)]; // 500/hour
    $rlGeocodeBatch = [Middleware::auth(), Middleware::rateLimit('geocode_batch',    20, 3600)];  // 20/hour
    $rlImport       = [Middleware::auth(), Middleware::rateLimit('import',           20, 3600)];  // 20/hour
    $rlPlaces       = [Middleware::auth(), Middleware::rateLimit('places',         300,  3600)];
    $rlTerritory    = [Middleware::auth(), Middleware::rateLimit('territory_gen',   30,  3600)];
    $rlMclp         = [Middleware::auth(), Middleware::rateLimit('mclp',            30,  3600)];
    $rlTraffic      = [Middleware::auth(), Middleware::rateLimit('traffic_iso',     60,  3600)];
    $rlCompetitor   = [Middleware::auth(), Middleware::rateLimit('competitor_scan', 60,  3600)];
    $rlReach        = [Middleware::auth(), Middleware::rateLimit('reach',          120,  3600)];
    $rlReport       = [Middleware::auth(), Middleware::rateLimit('report',          50,  3600)];
    $rlExport       = [Middleware::auth(), Middleware::rateLimit('export',          60,  3600)];

    // Health (public)
    $r->get('/api/health', [HealthController::class, 'show']);

    // Auth
    $r->post('/api/auth/register', [AuthController::class, 'register']);
    $r->post('/api/auth/login', [AuthController::class, 'login']);
    $r->post('/api/auth/refresh', [AuthController::class, 'refresh'], $auth);
    $r->post('/api/auth/logout', [AuthController::class, 'logout'], $auth);
    $r->get('/api/auth/me', [AuthController::class, 'me'], $auth);
    $r->post('/api/auth/request-reset', [AuthController::class, 'requestPasswordReset']);
    $r->post('/api/auth/reset', [AuthController::class, 'resetPassword']);
    $r->get('/api/auth/verify-email', [AuthController::class, 'verifyEmail']);
    $r->post('/api/auth/resend-verification', [AuthController::class, 'resendVerification'], $auth);
    $r->put('/api/auth/profile', [AuthController::class, 'updateProfile'], $auth);
    $r->post('/api/auth/change-password', [AuthController::class, 'changePassword'], $auth);
    $r->get('/api/auth/api-key', [AuthController::class, 'showApiKey'], $auth);
    $r->post('/api/auth/api-key/regenerate', [AuthController::class, 'regenerateApiKey'], $auth);

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
    $r->post('/api/areas/reach', [ReachController::class, 'calculate'], $rlReach);
    $r->post('/api/demographics/preview', [ReachController::class, 'preview'], $auth);

    // Isochrone
    $r->post('/api/isochrone/calculate', [IsochroneController::class, 'calculate'], $auth);

    // Geocoding
    $r->post('/api/geocode', [GeocodingController::class, 'geocode'], $rlGeocode);
    $r->post('/api/geocode/batch', [GeocodingController::class, 'batchGeocode'], $rlGeocodeBatch);

    // Places
    $r->post('/api/places/nearby', [PlacesController::class, 'nearby'], $rlPlaces);
    $r->post('/api/places/search', [PlacesController::class, 'search'], $rlPlaces);
    $r->get('/api/places/{placeId}', [PlacesController::class, 'show'], $auth);

    // Import / Export
    $r->post('/api/projects/{projectId}/import/upload', [ImportController::class, 'upload'], $rlImport);
    $r->post('/api/projects/{projectId}/import/configure', [ImportController::class, 'configure'], $rlImport);
    $r->get('/api/imports/{batchId}/status', [ImportController::class, 'status'], $auth);
    $r->delete('/api/imports/{batchId}', [ImportController::class, 'deleteImport'], $auth);
    $r->get('/api/projects/{projectId}/export/areas', [ExportController::class, 'exportAreas'], $rlExport);
    $r->get('/api/areas/{areaId}/export/pois', [ExportController::class, 'exportPOIs'], $rlExport);
    $r->get('/api/projects/{projectId}/export/points', [ExportController::class, 'exportImportedPoints'], $rlExport);
    $r->get('/api/exports/{filename}', [ExportController::class, 'download'], $auth);

    // Reports
    $r->post('/api/areas/{id}/report', [ReportController::class, 'generate'], $rlReport);
    $r->get('/api/reports', [ReportController::class, 'list'], $auth);
    $r->get('/api/reports/{id}/download', [ReportController::class, 'download'], $auth);

    // Billing
    $r->post('/api/billing/checkout', [BillingController::class, 'createCheckout'], $auth);
    $r->post('/api/billing/webhook', [BillingController::class, 'webhook']);
    $r->get('/api/billing/subscription', [BillingController::class, 'subscription'], $auth);
    $r->post('/api/billing/portal', [BillingController::class, 'portal'], $auth);
    $r->post('/api/billing/cancel', [BillingController::class, 'cancel'], $auth);

    // Traffic-aware isochrones
    $r->post('/api/isochrone/traffic', [TrafficIsochroneController::class, 'calculate'], $rlTraffic);
    $r->post('/api/isochrone/traffic/grid', [TrafficIsochroneController::class, 'grid'], $rlTraffic);
    $r->post('/api/isochrone/traffic/day', [TrafficIsochroneController::class, 'day'], $rlTraffic);

    // Google API usage / spend visibility
    $r->get('/api/usage/today', [UsageController::class, 'today'], $auth);
    $r->get('/api/usage/days', [UsageController::class, 'days'], $auth);
    $r->get('/api/usage/pricing', [UsageController::class, 'pricing'], $auth);

    // Cannibalization
    $r->get('/api/projects/{projectId}/cannibalization', [CannibalizationController::class, 'analyze'], $auth);

    // Territory generation
    $r->post('/api/projects/{projectId}/territories/generate', [TerritoryController::class, 'generate'], $rlTerritory);
    $r->get('/api/projects/{projectId}/territories/jobs', [TerritoryController::class, 'listJobs'], $auth);
    $r->post('/api/areas/{id}/rebuild-boundary', [TerritoryController::class, 'rebuildBoundary'], $auth);

    // Multi-location optimization (MCLP)
    $r->post('/api/projects/{projectId}/optimize/locations', [MclpController::class, 'optimize'], $rlMclp);

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
    $r->post('/api/competitor-monitors/{id}/scan', [CompetitorController::class, 'scanNow'], $rlCompetitor);
    $r->get('/api/competitor-monitors/{id}/places', [CompetitorController::class, 'listPlaces'], $auth);
    $r->get('/api/competitor-monitors/{id}/alerts', [CompetitorController::class, 'listAlerts'], $auth);
    $r->post('/api/competitor-alerts/{id}/read', [CompetitorController::class, 'markAlertRead'], $auth);

    // Field notes (mobile PWA)
    $r->get('/api/projects/{projectId}/field-notes', [FieldNoteController::class, 'index'], $auth);
    $r->post('/api/projects/{projectId}/field-notes', [FieldNoteController::class, 'create'], $auth);
    $r->delete('/api/field-notes/{id}', [FieldNoteController::class, 'destroy'], $auth);
    $r->get('/api/projects/{projectId}/where-am-i', [FieldNoteController::class, 'whereAmI'], $auth);

    // Background jobs (queued processing for territory gen / MCLP / scans / imports)
    $r->get('/api/jobs/{id}', [JobController::class, 'show'], $auth);
    $r->post('/api/jobs/{id}/cancel', [JobController::class, 'cancel'], $auth);

    // Webhook subscriptions (#50)
    $r->get('/api/webhooks', [WebhookSubscriptionController::class, 'index'], $auth);
    $r->post('/api/webhooks', [WebhookSubscriptionController::class, 'create'], $auth);
    $r->put('/api/webhooks/{id}', [WebhookSubscriptionController::class, 'update'], $auth);
    $r->delete('/api/webhooks/{id}', [WebhookSubscriptionController::class, 'destroy'], $auth);
    $r->post('/api/webhooks/{id}/test', [WebhookSubscriptionController::class, 'test'], $auth);

    // Public share (#45) — no auth, validates share_token
    $r->get('/api/public/projects/{token}', [PublicShareController::class, 'show']);
    $r->get('/api/public/projects/{token}/embed', [PublicShareController::class, 'embed']);

    // AI site scoring (#41) — uses ANTHROPIC_API_KEY if set
    $r->post('/api/areas/{id}/ai-score', [AiScoringController::class, 'score'], $auth);

    // OpenAPI docs (#47)
    $r->get('/api/openapi.json', [OpenApiController::class, 'spec']);
    $r->get('/api/docs', [OpenApiController::class, 'docs']);
};
