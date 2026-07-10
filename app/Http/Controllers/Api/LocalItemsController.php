<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocalItemsRequest;
use App\Jobs\ProcessLocalItemsBatch;
use Illuminate\Http\JsonResponse;

class LocalItemsController extends Controller
{
    public function store(LocalItemsRequest $request): JsonResponse
    {
        ProcessLocalItemsBatch::dispatch($request->validated()['items']);

        return response()->json(null, 202);
    }
}
