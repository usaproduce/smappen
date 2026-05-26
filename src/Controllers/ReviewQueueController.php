<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\VendorClassifierService;
use App\Services\VendorDedupeService;

/**
 * ReviewQueueController — admin review surface for ambiguous dedupe
 * matches + low-confidence classifications. Carafe Vendor Network
 * Spec v3 §4.3 + §8.
 *
 * Two kinds of items in the queue:
 *
 *   dedupe   — vendor_dedupe_pairs WHERE decision='review' AND reviewed_at IS NULL.
 *              Operator picks merge / reject / defer.
 *   classify — vendors WHERE classification_needs_review=1 AND
 *              classification_reviewed_at IS NULL. Operator accepts or
 *              overrides the type.
 *
 * Endpoints (all admin-gated):
 *
 *   GET  /api/admin/review-queue
 *   GET  /api/admin/review-queue?kind=dedupe|classify
 *   POST /api/admin/review-queue/dedupe/{id}/merge
 *   POST /api/admin/review-queue/dedupe/{id}/reject
 *   POST /api/admin/review-queue/dedupe/{id}/defer
 *   POST /api/admin/review-queue/classify/{id}/approve
 *   POST /api/admin/review-queue/classify/{id}/update   body: {type, category?}
 *
 * "kind" is required on the action endpoints because IDs from the two
 * sources live in different tables — collapsing them into one ambiguous
 * id space would be a footgun.
 */
class ReviewQueueController
{
    /** GET /api/admin/review-queue — paged combined feed. */
    public function index(Request $request): void
    {
        $kind   = (string) ($request->getQuery('kind') ?? '');
        $limit  = max(1, min(200, (int) $request->getQuery('limit',  50)));
        $offset = max(0,         (int) $request->getQuery('offset', 0));

        $payload = [];
        if ($kind === '' || $kind === 'dedupe') {
            $payload['dedupe']   = self::loadDedupeQueue($limit, $offset);
        }
        if ($kind === '' || $kind === 'classify') {
            $payload['classify'] = self::loadClassifyQueue($limit, $offset);
        }
        $payload['counts'] = self::queueCounts();
        Response::success($payload);
    }

    // ─── Dedupe actions ──────────────────────────────────────────────

    /** POST /api/admin/review-queue/dedupe/{id}/merge */
    public function dedupeMerge(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $reviewer = $request->user['id'] ?? null;

        $pair = self::fetchPair($id);
        if (!$pair) { Response::error('pair not found', 404); return; }
        if ($pair['reviewed_at'] !== null) {
            Response::error('pair already reviewed', 409); return;
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Promote the row's decision to auto_merge so applyPendingAutoMerges
            // does the union-find + reassignment. Keeping the apply step in one
            // place avoids two diverging merge code paths.
            $db->query(
                "UPDATE vendor_dedupe_pairs
                 SET decision='auto_merge',
                     review_outcome='merged',
                     reviewed_at=NOW(),
                     reviewed_by=?
                 WHERE id=?",
                [$reviewer, $id]
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            Response::error($e->getMessage(), 500);
            return;
        }

        try {
            $merged = (new VendorDedupeService())->applyPendingAutoMerges();
            Response::success(['merged_count' => $merged]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /** POST /api/admin/review-queue/dedupe/{id}/reject */
    public function dedupeReject(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $reviewer = $request->user['id'] ?? null;
        Database::getInstance()->query(
            "UPDATE vendor_dedupe_pairs
             SET decision='reject', review_outcome='rejected',
                 reviewed_at=NOW(), reviewed_by=?
             WHERE id=? AND reviewed_at IS NULL",
            [$reviewer, $id]
        );
        Response::success([]);
    }

    /** POST /api/admin/review-queue/dedupe/{id}/defer — punt; stays in queue. */
    public function dedupeDefer(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $reviewer = $request->user['id'] ?? null;
        Database::getInstance()->query(
            "UPDATE vendor_dedupe_pairs
             SET review_outcome='deferred', reviewed_at=NOW(), reviewed_by=?
             WHERE id=? AND reviewed_at IS NULL",
            [$reviewer, $id]
        );
        Response::success([]);
    }

    // ─── Classification actions ──────────────────────────────────────

    /** POST /api/admin/review-queue/classify/{id}/approve — accept current type. */
    public function classifyApprove(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $reviewer = $request->user['id'] ?? null;
        Database::getInstance()->query(
            "UPDATE vendors
             SET classification_needs_review=0,
                 classification_reviewed_at=NOW(),
                 classification_reviewed_by=?,
                 updated_at=NOW()
             WHERE id=? AND classification_needs_review=1",
            [$reviewer, $id]
        );
        Response::success([]);
    }

    /**
     * POST /api/admin/review-queue/classify/{id}/update
     * body: {"type": "produce", "category": "produce"}
     * Operator overrides the classifier's pick — confidence jumps to
     * 100 (operator-confirmed) and the row is removed from the queue.
     */
    public function classifyUpdate(Request $request): void
    {
        $id = $request->getParam('id');
        if (!$id) { Response::error('id required', 422); return; }
        $body = $request->getBody() ?? [];
        $type = (string) ($body['type'] ?? '');
        if ($type === '') { Response::error('type required', 422); return; }
        // category is optional — derive from type-to-category map.
        $cat  = (string) ($body['category'] ?? '');
        if ($cat === '') {
            $cat = self::categoryForType($type);
        }
        $reviewer = $request->user['id'] ?? null;
        Database::getInstance()->query(
            "UPDATE vendors
             SET type=?, primary_category=?,
                 classification_confidence=100,
                 classification_needs_review=0,
                 classification_reviewed_at=NOW(),
                 classification_reviewed_by=?,
                 updated_at=NOW()
             WHERE id=?",
            [$type, $cat, $reviewer, $id]
        );
        Response::success([]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    public static function queueCounts(): array
    {
        $db = Database::getInstance();
        $dedupe = (int) ($db->fetch(
            "SELECT COUNT(*) AS n FROM vendor_dedupe_pairs WHERE decision='review' AND reviewed_at IS NULL"
        )['n'] ?? 0);
        $classify = (int) ($db->fetch(
            "SELECT COUNT(*) AS n FROM vendors WHERE classification_needs_review=1 AND classification_reviewed_at IS NULL"
        )['n'] ?? 0);
        return [
            'dedupe'   => $dedupe,
            'classify' => $classify,
            'total'    => $dedupe + $classify,
        ];
    }

    private static function loadDedupeQueue(int $limit, int $offset): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT p.id, p.score, p.distance_m, p.shared_name_tokens, p.block_key_hit, p.created_at,
                    p.left_vendor_id, p.right_vendor_id,
                    lv.name AS left_name,  lv.primary_category AS left_category,
                    rv.name AS right_name, rv.primary_category AS right_category
             FROM vendor_dedupe_pairs p
             JOIN vendors lv ON lv.id = p.left_vendor_id
             JOIN vendors rv ON rv.id = p.right_vendor_id
             WHERE p.decision='review' AND p.reviewed_at IS NULL
             ORDER BY p.score DESC, p.created_at ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    private static function loadClassifyQueue(int $limit, int $offset): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT id, name, type, primary_category,
                    classification_confidence, classification_signals_json, classified_at
             FROM vendors
             WHERE classification_needs_review=1
               AND classification_reviewed_at IS NULL
               AND merged_into IS NULL
             ORDER BY classification_confidence ASC, classified_at ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    private static function fetchPair(string $id): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT * FROM vendor_dedupe_pairs WHERE id=?',
            [$id]
        );
    }

    /** Mirrors VendorClassifierService::TYPE_TO_CATEGORY. */
    private static function categoryForType(string $type): string
    {
        return match ($type) {
            'broadline','cash_carry' => 'broadline',
            'produce'                => 'produce',
            'meat'                   => 'protein',
            'seafood'                => 'seafood',
            default                  => 'specialty',
        };
    }
}
