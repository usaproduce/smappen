<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Hand-curated OpenAPI 3.1 spec covering the public surface of the API
 * (#47). Paths are grouped to match the controllers, with shared schemas
 * for the standard {success,data} envelope and error shape.
 *
 * GET /api/openapi.json — raw JSON for Swagger/Postman/OpenAPI generators
 * GET /api/docs         — minimal Swagger UI page that loads /api/openapi.json
 */
class OpenApiController
{
    public function spec(Request $request): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        echo json_encode(self::buildSpec(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public function docs(Request $request): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!doctype html>
<html><head>
  <meta charset="utf-8">
  <title>Smappen API</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css">
</head><body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js" crossorigin></script>
  <script>
    window.onload = () => SwaggerUIBundle({
      url: '/api/openapi.json',
      dom_id: '#swagger-ui',
      deepLinking: true,
      docExpansion: 'list',
      defaultModelsExpandDepth: 0,
    });
  </script>
</body></html>
HTML;
        exit;
    }

    private static function buildSpec(): array
    {
        $bearer = ['BearerAuth' => []];
        $apiKey = ['ApiKeyAuth' => []];
        $envelope = ['$ref' => '#/components/schemas/Envelope'];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Smappen API',
                'description' => 'Territory mapping + demographics + competitor intelligence.',
                'version' => '1.0.0',
            ],
            'servers' => [
                ['url' => 'https://smappen.mygreendock.com', 'description' => 'Production'],
            ],
            'security' => [$bearer, $apiKey],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                    'ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
                'schemas' => [
                    'Envelope' => [
                        'type' => 'object',
                        'properties' => [
                            'success' => ['type' => 'boolean'],
                            'data' => ['type' => 'object'],
                        ],
                        'required' => ['success', 'data'],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => false],
                            'error' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'paths' => self::paths($envelope),
        ];
    }

    private static function paths(array $envelope): array
    {
        $standard200 = ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $envelope]]]];
        $standardErr = ['default' => ['description' => 'Error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]];
        $r = fn(string $summary, array $opts = []) => array_merge(['summary' => $summary, 'responses' => $standard200 + $standardErr], $opts);
        $idParam = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        $projParam = ['name' => 'projectId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];

        return [
            '/api/health' => [
                'get' => ['summary' => 'Liveness probe', 'security' => [], 'responses' => $standard200],
            ],
            '/api/auth/register'   => ['post' => $r('Create account')],
            '/api/auth/login'      => ['post' => $r('Login (returns JWT)')],
            '/api/auth/logout'     => ['post' => $r('Revoke current JWT')],
            '/api/auth/me'         => ['get'  => $r('Current user')],
            '/api/auth/refresh'    => ['post' => $r('Refresh JWT')],
            '/api/auth/request-reset' => ['post' => $r('Email a password-reset link')],
            '/api/auth/reset'      => ['post' => $r('Submit new password + token')],
            '/api/auth/verify-email' => ['get' => $r('Confirm email via emailed token')],
            '/api/auth/profile'    => ['put'  => $r('Update profile + notification prefs')],
            '/api/auth/change-password' => ['post' => $r('Change password (auth required)')],
            '/api/auth/api-key'    => ['get'  => $r('Show API key metadata')],
            '/api/auth/api-key/regenerate' => ['post' => $r('Issue a new API key')],

            '/api/projects'        => [
                'get'  => $r('List projects'),
                'post' => $r('Create project'),
            ],
            '/api/projects/{id}'   => [
                'parameters' => [$idParam],
                'get'    => $r('Show project'),
                'put'    => $r('Update project'),
                'delete' => $r('Delete project'),
            ],
            '/api/projects/{projectId}/areas' => [
                'parameters' => [$projParam],
                'get'  => $r('List areas in project'),
                'post' => $r('Create area'),
            ],
            '/api/areas/{id}/demographics' => ['parameters' => [$idParam], 'get' => $r('Area demographics')],
            '/api/areas/{id}/segments' => ['parameters' => [$idParam], 'get' => $r('Segment mix for area')],
            '/api/areas/{id}/ai-score' => ['parameters' => [$idParam], 'post' => $r('AI-scored verdict (1–100)')],
            '/api/areas/{id}/rebuild-boundary' => ['parameters' => [$idParam], 'post' => $r('Rebuild a territory polygon via ST_Union over source tracts')],
            '/api/areas/reach' => ['post' => $r('Smallest circle covering N people')],
            '/api/demographics/preview' => ['post' => $r('Live demographics for a drafted polygon')],
            '/api/jobs/{id}/cancel' => ['parameters' => [$idParam], 'post' => $r('Cancel a running background job')],
            '/api/heatmap/tracts' => ['get' => $r('Choropleth tracts for current viewport')],
            '/api/places/{placeId}' => [
                'parameters' => [['name' => 'placeId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'get' => $r('Google Place details'),
            ],
            '/api/segmentation/segments' => ['get' => $r('Segment catalog (10 personas)')],
            '/api/projects/{projectId}/segments' => ['parameters' => [$projParam], 'post' => $r('Segment mix across project areas')],
            '/api/projects/{projectId}/cannibalization' => [
                'parameters' => [$projParam],
                'get' => $r('Pairwise overlap demographics'),
            ],
            '/api/projects/{projectId}/territories/generate' => [
                'parameters' => [$projParam],
                'post' => $r('Auto-generate balanced territories'),
            ],
            '/api/projects/{projectId}/optimize/locations' => [
                'parameters' => [$projParam],
                'post' => $r('Maximum-coverage location optimizer (MCLP)'),
            ],
            '/api/projects/{projectId}/competitor-monitors' => [
                'parameters' => [$projParam],
                'get'  => $r('List competitor monitors'),
                'post' => $r('Create competitor monitor'),
            ],
            '/api/competitor-monitors/{id}/scan' => ['parameters' => [$idParam], 'post' => $r('Force a scan now')],
            '/api/isochrone/traffic' => ['post' => $r('Time-of-day aware isochrone')],
            '/api/isochrone/traffic/grid' => ['post' => $r('8-window traffic grid for one origin')],
            '/api/projects/{projectId}/field-notes' => [
                'parameters' => [$projParam],
                'get'  => $r('List geo-stamped field notes'),
                'post' => $r('Capture a field note'),
            ],
            '/api/projects/{projectId}/where-am-i' => [
                'parameters' => [$projParam,
                    ['name' => 'lat', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'number']],
                    ['name' => 'lng', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'number']],
                ],
                'get' => $r('Resolve a coordinate to area + tract'),
            ],
            '/api/notifications' => ['get' => $r('User notifications')],
            '/api/webhooks' => [
                'get'  => $r('List webhook subscriptions'),
                'post' => $r('Register a webhook'),
            ],
            '/api/jobs/{id}' => ['parameters' => [$idParam], 'get' => $r('Background job status')],
            '/api/public/projects/{token}' => [
                'parameters' => [['name' => 'token', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'get' => array_merge($r('Public read-only project view'), ['security' => []]),
            ],
        ];
    }
}
