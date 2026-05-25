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
use App\Controllers\UploadController;
use App\Controllers\AnalogController;
use App\Controllers\OpsController;
use App\Controllers\DriveTimeMatrixController;
use App\Controllers\TerritoryRebalancerController;
use App\Controllers\ForecastController;
use App\Controllers\CrmController;
use App\Controllers\PresenceController;
use App\Controllers\OnboardingController;
use App\Controllers\AlertsController;
use App\Controllers\CustomLayerController;
use App\Controllers\EmbedController;
use App\Controllers\RestaurantController;
use App\Controllers\PosController;
use App\Controllers\MenuController;
use App\Controllers\MenuEngineeringController;
use App\Controllers\RoiController;
use App\Controllers\PlanningController;
use App\Controllers\GoalController;
use App\Controllers\FoodCostController;
use App\Controllers\LaborController;
use App\Controllers\VendorController;
use App\Controllers\VendorClaimController;
use App\Controllers\VendorMapController;
use App\Controllers\VendorReviewController;
use App\Controllers\SavedVendorController;
use App\Controllers\ComparisonController;
use App\Controllers\ConsolidationController;
use App\Controllers\LeadController;

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
    $rlAnalog       = [Middleware::auth(), Middleware::rateLimit('analog_finder',   30,  3600)]; // 30/hr — heavy tract scan
    $rlDtm          = [Middleware::auth(), Middleware::rateLimit('dtm',              20,  3600)]; // 20/hr — ORS heavy
    $rlForecast     = [Middleware::auth(), Middleware::rateLimit('forecast',         60,  3600)];

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
    $r->post('/api/projects/{projectId}/areas/reorder', [AreaController::class, 'reorder'], $auth);
    $r->post('/api/projects/{id}/archive', [ProjectController::class, 'archive'], $auth);
    $r->get('/api/projects/{id}/export', [ProjectController::class, 'exportBundle'], $auth);
    $r->get('/api/areas/{id}', [AreaController::class, 'show'], $auth);
    $r->put('/api/areas/{id}', [AreaController::class, 'update'], $auth);
    $r->delete('/api/areas/{id}', [AreaController::class, 'destroy'], $auth);
    $r->get('/api/areas/{id}/demographics', [DemographicsController::class, 'show'], $auth);
    $r->get('/api/areas/{id}/demographics/trends', [DemographicsController::class, 'trends'], $auth);
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
    $r->get('/api/projects/{projectId}/import/batches', [ImportController::class, 'batches'], $auth);
    $r->get('/api/imports/{batchId}/status', [ImportController::class, 'status'], $auth);
    $r->delete('/api/imports/{batchId}', [ImportController::class, 'deleteImport'], $auth);
    $r->get('/api/projects/{projectId}/export/areas', [ExportController::class, 'exportAreas'], $rlExport);
    $r->get('/api/areas/{areaId}/export/pois', [ExportController::class, 'exportPOIs'], $rlExport);
    $r->get('/api/projects/{projectId}/export/points', [ExportController::class, 'exportImportedPoints'], $rlExport);
    $r->get('/api/exports/{filename}', [ExportController::class, 'download'], $auth);

    // Reports
    $r->post('/api/areas/{id}/report', [ReportController::class, 'generate'], $rlReport);
    $r->post('/api/areas/{id}/report.pdf', [ReportController::class, 'generatePdf'], $rlReport);
    $r->get('/api/reports', [ReportController::class, 'list'], $auth);
    $r->get('/api/reports/{id}/download', [ReportController::class, 'download'], $auth);
    $r->get('/api/report-templates', [ReportController::class, 'templates'], $auth);

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
    $r->post('/api/usage/log-map-load', [UsageController::class, 'logMapLoad'], $auth);

    // Generic file upload (field-note photos, future area/profile media)
    $r->post('/api/uploads', [UploadController::class, 'upload'], $auth);

    // Cannibalization
    $r->get('/api/projects/{projectId}/cannibalization', [CannibalizationController::class, 'analyze'], $auth);

    // Territory generation
    $r->post('/api/projects/{projectId}/territories/generate', [TerritoryController::class, 'generate'], $rlTerritory);
    $r->get('/api/projects/{projectId}/territories/jobs', [TerritoryController::class, 'listJobs'], $auth);
    $r->post('/api/areas/{id}/rebuild-boundary', [TerritoryController::class, 'rebuildBoundary'], $auth);
    $r->post('/api/projects/{projectId}/territories/rebuild-all', [TerritoryController::class, 'bulkRebuild'], $rlTerritory);

    // Multi-location optimization (MCLP)
    $r->post('/api/projects/{projectId}/optimize/locations', [MclpController::class, 'optimize'], $rlMclp);

    // Analog finder — "find me places like my best store"
    $r->post('/api/areas/{id}/analogs', [AnalogController::class, 'find'], $rlAnalog);

    // NF1 — Drive-time matrix
    $r->post('/api/drive-time-matrix', [DriveTimeMatrixController::class, 'compute'], $rlDtm);

    // NF2 — Sales-territory rebalancer
    $r->post('/api/projects/{projectId}/rebalance', [TerritoryRebalancerController::class, 'analyze'], $rlTerritory);

    // NF3 — Demand forecasting from analogs
    $r->post('/api/areas/{id}/forecast', [ForecastController::class, 'predict'], $rlForecast);

    // #13 — presence cursors (SSE)
    $r->post('/api/projects/{projectId}/presence/ping', [PresenceController::class, 'ping'], $auth);
    $r->get('/api/projects/{projectId}/presence/stream', [PresenceController::class, 'stream'], $auth);

    // #21 — CRM integration scaffolding
    $r->post('/api/integrations/salesforce/connect', [CrmController::class, 'connectSalesforce'], $auth);
    $r->get('/api/integrations/salesforce/callback', [CrmController::class, 'callbackSalesforce'], $auth);
    $r->post('/api/integrations/salesforce/push',    [CrmController::class, 'pushSalesforce'], $auth);
    $r->post('/api/integrations/hubspot/connect',    [CrmController::class, 'connectHubspot'], $auth);
    $r->get('/api/integrations/hubspot/callback',    [CrmController::class, 'callbackHubspot'], $auth);
    $r->post('/api/integrations/hubspot/push',       [CrmController::class, 'pushHubspot'], $auth);

    // Operational features (OP4, OP5, OP9, OP11, OP13, OP21) — small CRUD endpoints.
    $r->get('/api/saved-searches',     [OpsController::class, 'listSavedSearches'],   $auth);
    $r->post('/api/saved-searches',    [OpsController::class, 'createSavedSearch'],   $auth);
    $r->delete('/api/saved-searches/{id}', [OpsController::class, 'deleteSavedSearch'], $auth);
    $r->get('/api/saved-comparisons',  [OpsController::class, 'listSavedComparisons'], $auth);
    $r->post('/api/saved-comparisons', [OpsController::class, 'createSavedComparison'], $auth);
    $r->delete('/api/saved-comparisons/{id}', [OpsController::class, 'deleteSavedComparison'], $auth);
    $r->get('/api/activity',           [OpsController::class, 'activityFeed'],        $auth);
    $r->get('/api/webhooks/deliveries',[OpsController::class, 'webhookDeliveries'],   $auth);
    $r->get('/api/tags',               [OpsController::class, 'listTags'],            $auth);
    $r->post('/api/tags',              [OpsController::class, 'createTag'],           $auth);
    $r->post('/api/areas/{id}/tags',   [OpsController::class, 'attachTag'],           $auth);
    $r->delete('/api/areas/{id}/tags/{tagId}', [OpsController::class, 'detachTag'],   $auth);
    $r->get('/api/scheduled-reports',  [OpsController::class, 'listScheduledReports'], $auth);
    $r->post('/api/scheduled-reports', [OpsController::class, 'createScheduledReport'], $auth);
    $r->delete('/api/scheduled-reports/{id}', [OpsController::class, 'deleteScheduledReport'], $auth);

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
    $r->get('/api/webhooks/{id}/deliveries', [WebhookSubscriptionController::class, 'deliveries'], $auth);

    // Public share (#45) — no auth, validates share_token
    $r->get('/api/public/projects/{token}', [PublicShareController::class, 'show']);
    $r->get('/api/public/projects/{token}/embed', [PublicShareController::class, 'embed']);
    // Public embed render — no auth, validates embed_token (embeds table)
    $r->get('/api/public/embeds/{token}', [PublicShareController::class, 'embedByToken']);

    // AI site scoring (#41) — uses ANTHROPIC_API_KEY if set
    $r->post('/api/areas/{id}/ai-score', [AiScoringController::class, 'score'], $auth);
    $r->post('/api/projects/{projectId}/ai-rankings', [AiScoringController::class, 'rank'], $auth);

    // OpenAPI docs (#47)
    $r->get('/api/openapi.json', [OpenApiController::class, 'spec']);
    $r->get('/api/docs', [OpenApiController::class, 'docs']);

    // Onboarding — first-run wizard + sample clone + activation funnel stamps
    $r->post('/api/onboarding/use-case',    [OnboardingController::class, 'setUseCase'], $auth);
    $r->post('/api/onboarding/seen',        [OnboardingController::class, 'markSeen'],   $auth);
    $r->get('/api/onboarding/state',        [OnboardingController::class, 'state'],      $auth);
    $r->post('/api/onboarding/clone-sample',[OnboardingController::class, 'cloneSample'],$auth);
    $r->post('/api/onboarding/activate',    [OnboardingController::class, 'activate'],   $auth);

    // Alerts — generic threshold/event rules + delivery digest
    $r->get('/api/alerts',                  [AlertsController::class, 'index'],   $auth);
    $r->post('/api/alerts',                 [AlertsController::class, 'create'],  $auth);
    $r->put('/api/alerts/{id}',             [AlertsController::class, 'update'],  $auth);
    $r->delete('/api/alerts/{id}',          [AlertsController::class, 'destroy'], $auth);
    $r->post('/api/alerts/{id}/test',       [AlertsController::class, 'test'],    $auth);
    $r->get('/api/alerts/digest/recent',    [AlertsController::class, 'recentDigest'], $auth);

    // Custom data layers — user-uploaded points overlaying the map
    $r->get('/api/projects/{projectId}/custom-layers',  [CustomLayerController::class, 'index'],   $auth);
    $r->post('/api/projects/{projectId}/custom-layers', [CustomLayerController::class, 'create'],  $auth);
    $r->put('/api/custom-layers/{id}',                  [CustomLayerController::class, 'update'],  $auth);
    $r->delete('/api/custom-layers/{id}',               [CustomLayerController::class, 'destroy'], $auth);
    $r->get('/api/custom-layers/{id}/points',           [CustomLayerController::class, 'points'],  $auth);

    // Embed builder — branded iframe configurations + view counters
    $r->get('/api/projects/{projectId}/embeds',         [EmbedController::class, 'index'],   $auth);
    $r->post('/api/projects/{projectId}/embeds',        [EmbedController::class, 'create'],  $auth);
    $r->put('/api/embeds/{id}',                         [EmbedController::class, 'update'],  $auth);
    $r->delete('/api/embeds/{id}',                      [EmbedController::class, 'destroy'], $auth);

    // ─────────────────────────── Carafe (Phase 1) ───────────────────────────

    // Restaurants — Carafe's primary org-scoped entity.
    $r->get('/api/restaurants',          [RestaurantController::class, 'index'],   $auth);
    $r->post('/api/restaurants',         [RestaurantController::class, 'create'],  $auth);
    $r->get('/api/restaurants/{id}',     [RestaurantController::class, 'show'],    $auth);
    $r->delete('/api/restaurants/{id}',  [RestaurantController::class, 'destroy'], $auth);

    // POS OAuth + sync. Provider-scoped under /pos/{provider} for clarity.
    // Callback is auth-less — validates via signed state token (see PosService).
    $r->get('/api/restaurants/{id}/pos',                       [PosController::class, 'listForRestaurant'], $auth);
    $r->post('/api/restaurants/{id}/pos/{provider}/connect',   [PosController::class, 'connect'],           $auth);
    $r->get('/api/integrations/pos/{provider}/callback',       [PosController::class, 'callback']);
    $r->post('/api/restaurants/{id}/pos/{provider}/sync',      [PosController::class, 'sync'],              $auth);

    // Menu + recipes + plate-cost compute.
    $r->get('/api/restaurants/{id}/menu',                       [MenuController::class, 'listMenu'],              $auth);
    $r->post('/api/restaurants/{id}/menu',                      [MenuController::class, 'createMenuItem'],        $auth);
    $r->put('/api/menu-items/{id}/price',                       [MenuController::class, 'setPrice'],              $auth);
    $r->put('/api/menu-items/{id}/recipe',                      [MenuController::class, 'setRecipe'],             $auth);
    $r->post('/api/restaurants/{id}/recipes',                   [MenuController::class, 'createRecipe'],          $auth);
    $r->get('/api/restaurants/{id}/recipes',                    [MenuController::class, 'listRecipes'],           $auth);
    $r->get('/api/recipes/{id}',                                [MenuController::class, 'showRecipe'],            $auth);
    $r->post('/api/recipes/{id}/ingredients',                   [MenuController::class, 'addIngredient'],         $auth);
    $r->delete('/api/recipe-ingredients/{id}',                  [MenuController::class, 'removeIngredient'],      $auth);
    $r->get('/api/ingredient-catalog',                          [MenuController::class, 'listIngredientCatalog'], $auth);
    $r->post('/api/restaurants/{id}/plate-costs/recompute',     [MenuController::class, 'recomputePlateCosts'],   $auth);
    $r->get('/api/restaurants/{id}/cogs/overpay',               [MenuController::class, 'overpayFlags'],          $auth);

    // Menu engineering — recommendations + accept/dismiss ledger.
    $r->post('/api/menu-items/{id}/recommend',                  [MenuEngineeringController::class, 'recommendForItem'],       $auth);
    $r->post('/api/restaurants/{id}/recommendations/run',       [MenuEngineeringController::class, 'recommendForRestaurant'], $auth);
    $r->get('/api/restaurants/{id}/recommendations',            [MenuEngineeringController::class, 'listForRestaurant'],      $auth);
    $r->get('/api/restaurants/{id}/menu/classify',              [MenuEngineeringController::class, 'classify'],               $auth);
    $r->post('/api/recommendations/{id}/accept',                [MenuEngineeringController::class, 'accept'],                  $auth);
    $r->post('/api/recommendations/{id}/dismiss',               [MenuEngineeringController::class, 'dismiss'],                 $auth);

    // ROI ledger — Carafe-found-you-$X-this-month.
    $r->get('/api/restaurants/{id}/roi/monthly',                [RoiController::class, 'monthly'], $auth);
    $r->post('/api/restaurants/{id}/roi/measure',               [RoiController::class, 'measure'], $auth);

    // Planning sandbox — model a menu change / new location before committing.
    $r->get('/api/sandbox',                                     [PlanningController::class, 'index'],   $auth);
    $r->post('/api/sandbox',                                    [PlanningController::class, 'create'],  $auth);
    $r->get('/api/sandbox/{id}',                                [PlanningController::class, 'show'],    $auth);
    $r->post('/api/sandbox/{id}/compute',                       [PlanningController::class, 'compute'], $auth);
    $r->delete('/api/sandbox/{id}',                             [PlanningController::class, 'destroy'], $auth);

    // Goals — operator scorecard.
    $r->get('/api/restaurants/{id}/goals',                      [GoalController::class, 'index'],    $auth);
    $r->post('/api/restaurants/{id}/goals',                     [GoalController::class, 'create'],   $auth);
    $r->post('/api/goals/{id}/snapshot',                        [GoalController::class, 'snapshot'], $auth);
    $r->delete('/api/goals/{id}',                               [GoalController::class, 'destroy'],  $auth);

    // Food cost — theoretical vs (eventually) actual.
    $r->get('/api/restaurants/{id}/food-cost/theoretical',      [FoodCostController::class, 'theoretical'], $auth);

    // Labor + daypart demand-filling.
    $r->get('/api/restaurants/{id}/labor/analysis',             [LaborController::class, 'analysis'],     $auth);
    $r->get('/api/restaurants/{id}/labor/shifts',               [LaborController::class, 'listShifts'],   $auth);
    $r->post('/api/restaurants/{id}/labor/shifts',              [LaborController::class, 'createShift'],  $auth);

    // ─────────────────────────── Carafe Phase 2 (marketplace) ───────────────────────────

    // Vendor directory — browse + show. Cross-tenant by design.
    $r->get('/api/vendors',                                     [VendorController::class, 'index'], $auth);
    $r->get('/api/vendors/{id}',                                [VendorController::class, 'show'],  $auth);

    // Vendor map — bbox query, drop-a-pin, detail with coverage geometry.
    $r->get('/api/vendors/map/bbox',                            [VendorMapController::class, 'bbox'],   $auth);
    $r->get('/api/vendors/map/serves',                          [VendorMapController::class, 'serves'], $auth);
    $r->get('/api/vendors/map/search',                          [VendorMapController::class, 'search'], $auth);
    $r->get('/api/vendors/{id}/detail',                         [VendorMapController::class, 'detail'], $auth);

    // Vendor reviews — verified-operator only.
    $r->get('/api/vendors/{id}/reviews',                        [VendorReviewController::class, 'list'],      $auth);
    $r->post('/api/vendors/{id}/reviews',                       [VendorReviewController::class, 'submit'],    $auth);
    $r->get('/api/vendors/{id}/reviews/aggregate',              [VendorReviewController::class, 'aggregate'], $auth);
    $r->post('/api/vendor-reviews/{id}/respond',                [VendorReviewController::class, 'respond'],   $auth);

    // Saved vendors (follow / shortlist).
    $r->get('/api/saved-vendors',                               [SavedVendorController::class, 'index'],  $auth);
    $r->post('/api/vendors/{id}/save',                          [SavedVendorController::class, 'save'],   $auth);
    $r->delete('/api/vendors/{id}/save',                        [SavedVendorController::class, 'unsave'], $auth);

    // Vendor claim workflow.
    $r->post('/api/vendors/{id}/claims',                        [VendorClaimController::class, 'create'],        $auth);
    $r->get('/api/vendors/{id}/claims',                         [VendorClaimController::class, 'listForVendor'], $auth);
    $r->post('/api/vendor-claims/{id}/approve',                 [VendorClaimController::class, 'approve'],       $auth);
    $r->post('/api/vendor-claims/{id}/reject',                  [VendorClaimController::class, 'reject'],        $auth);
    $r->post('/api/vendors/{id}/listings',                      [VendorClaimController::class, 'addListing'],    $auth);

    // Honest comparison + order consolidation.
    $r->post('/api/vendors/compare',                            [ComparisonController::class,    'compare'], $auth);
    $r->post('/api/vendors/consolidate',                        [ConsolidationController::class, 'compare'], $auth);

    // Lead funnel — opt-in audit trail + outbox to GreenDock via webhook.
    $r->post('/api/vendors/compare/log',                        [LeadController::class, 'logComparison'], $auth);
    $r->post('/api/leads',                                      [LeadController::class, 'create'],        $auth);
    $r->get('/api/leads',                                       [LeadController::class, 'index'],         $auth);
    $r->post('/api/leads/{id}/emit',                            [LeadController::class, 'emit'],          $auth);
};
