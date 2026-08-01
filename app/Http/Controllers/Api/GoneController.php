<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Single-action responder for endpoints that have been retired.
 *
 * Returning 410 Gone rather than deleting the route outright means a client
 * that has not been updated yet gets an explicit "this endpoint was removed"
 * instead of a 404, which is indistinguishable from a routing/deploy bug.
 * Once every client is on a version that no longer calls these, both the route
 * and this responder can be deleted.
 */
class GoneController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return $this->error(
            'تم إلغاء هذه الخدمة. | This endpoint has been removed and is no longer available.',
            [],
            410
        );
    }
}
