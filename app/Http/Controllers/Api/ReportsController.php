<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CategoryDateReportRequest;
use App\Http\Requests\Reports\ReportIndexRequest;
use App\Http\Requests\Reports\StorageOccupancyRequest;
use App\Http\Resources\Reports\PendingClaimReportResource;
use App\Queries\Reports\ReclaimReports;
use Illuminate\Http\JsonResponse;

class ReportsController extends Controller
{
    public function pendingClaims(CategoryDateReportRequest $request, ReclaimReports $reports): JsonResponse
    {
        return PendingClaimReportResource::collection($reports->pendingClaims($request->validated()))->response();
    }

    public function returnRates(CategoryDateReportRequest $request, ReclaimReports $reports): JsonResponse
    {
        return response()->json($reports->returnRates($request->validated()));
    }

    public function storageOccupancy(StorageOccupancyRequest $request, ReclaimReports $reports): JsonResponse
    {
        return response()->json($reports->storageOccupancy($request->validated()));
    }

    public function monthlyIntake(CategoryDateReportRequest $request, ReclaimReports $reports): JsonResponse
    {
        return response()->json($reports->monthlyIntake($request->validated()));
    }
}
