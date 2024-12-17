<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Apis\Project\ProjectController;
use App\Http\Controllers\Apis\Project\ProjectApprovalChainController;
use App\Http\Controllers\Apis\UserAuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


Route::post('login', [UserAuthController::class, 'login']);

Route::middleware('auth:sanctum')->name('apis.')->group(function () {

    Route::post('logout', [UserAuthController::class, 'logout']);

    Route::name('projects.')->prefix('projects')->group(function () {
        Route::post('/{projectId}/updateOrCreateChain', [ProjectApprovalChainController::class, 'updateOrCreateChain']);
        Route::put('/{projectId}/approveChain', [ProjectApprovalChainController::class, 'approveChain']);
        Route::get('/{projectId}/approvalChain', [ProjectApprovalChainController::class, 'getProjectWithApprovalChain']);
    });
});
