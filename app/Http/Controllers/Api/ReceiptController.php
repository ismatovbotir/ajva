<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReceiptRequest;
use App\Jobs\ProcessReceiptIngestion;
use Illuminate\Http\JsonResponse;

class ReceiptController extends Controller
{
    public function store(ReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();

        ProcessReceiptIngestion::dispatch($data, (int) $data['pos']);

        return response()->json(null, 202);
    }
}
