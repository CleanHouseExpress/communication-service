<?php

namespace App\Http\Controllers\Providers;

use App\Actions\Messages\ProcessProviderMessageStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ZapiMessageStatusRequest;
use Illuminate\Http\JsonResponse;

class ZapiMessageStatusController extends Controller
{
    public function __invoke(
        ZapiMessageStatusRequest $request,
        ProcessProviderMessageStatusAction $action,
    ): JsonResponse {
        return response()->json($action->handle($request->validated()));
    }
}
