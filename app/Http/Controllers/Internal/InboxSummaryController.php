<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\InternalInboxSummaryRequest;
use App\Queries\Inbox\GetInboxSummaryQuery;
use Illuminate\Http\JsonResponse;

class InboxSummaryController extends Controller
{
    public function __invoke(InternalInboxSummaryRequest $request, GetInboxSummaryQuery $query): JsonResponse
    {
        return response()->json([
            'data' => $query->handle($request->validated()),
        ]);
    }
}
