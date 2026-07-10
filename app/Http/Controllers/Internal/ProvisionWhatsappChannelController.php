<?php

namespace App\Http\Controllers\Internal;

use App\Exceptions\ZApiProvisioningException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProvisionWhatsappChannelRequest;
use App\Services\Providers\ZApiProvisioningService;
use Illuminate\Http\JsonResponse;

class ProvisionWhatsappChannelController extends Controller
{
    public function __invoke(ProvisionWhatsappChannelRequest $request, ZApiProvisioningService $provisioning): JsonResponse
    {
        try {
            return response()->json($provisioning->provision($request->payload()), 201);
        } catch (ZApiProvisioningException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        }
    }
}
