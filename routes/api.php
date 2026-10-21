<?php

use App\Http\Controllers\Api\AccountsController;
use App\Http\Controllers\Api\AccountSecurityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoriesController;
use App\Http\Controllers\Api\ClaimsController;
use App\Http\Controllers\Api\ClaimProofImagesController;
use App\Http\Controllers\Api\FinderReportsController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\ImagesController;
use App\Http\Controllers\Api\ItemsController;
use App\Http\Controllers\Api\MissingItemReportsController;
use App\Http\Controllers\Api\PeopleController;
use App\Http\Controllers\Api\PublicItemsController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\StorageLocationsController;
use App\Http\Controllers\Api\UserAccountsController;
use App\Http\Controllers\Api\UserClaimsController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserAccount;
use App\Http\Middleware\EnsureVerifiedUserEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

Route::post('/auth/google/start', [GoogleAuthController::class, 'start'])->middleware('throttle:google-start');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:google-callback');
Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:google-exchange');

Route::post('/auth/register', [UserAccountsController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'store'])->middleware('throttle:10,1');
Route::post('/auth/password/forgot', [UserAccountsController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/auth/password/verify-code', [UserAccountsController::class, 'verifyResetCode'])->middleware('throttle:5,1');
Route::post('/auth/password/reset', [UserAccountsController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::get('/auth/email/verify/{id}/{hash}', [UserAccountsController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:10,1'])->name('user.email.verify');

Route::get('/public/items', [PublicItemsController::class, 'index'])->middleware('throttle:60,1');
Route::get('/public/items/{item}', [PublicItemsController::class, 'show'])->whereNumber('item')->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, AuthenticateSession::class])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'destroy']);
    Route::get('/me', [AuthController::class, 'show']);
    Route::get('/me/security', [AccountSecurityController::class, 'show']);
    Route::patch('/me/username', [AccountSecurityController::class, 'updateUsername'])->middleware('throttle:username-update');
    Route::post('/me/google/start', [GoogleAuthController::class, 'startLink'])->middleware('throttle:google-link-start');
    Route::post('/me/google/exchange', [GoogleAuthController::class, 'exchangeLink'])->middleware('throttle:google-link-exchange');
    Route::post('/me/password/setup-email', [AccountSecurityController::class, 'sendSetup'])->middleware('throttle:password-setup-email');
    Route::post('/me/password/setup', [AccountSecurityController::class, 'setPassword'])->middleware('throttle:password-setup');
    Route::post('/auth/password', [AccountsController::class, 'changePassword'])->middleware('throttle:5,1');
    Route::get('/me/profile', [UserAccountsController::class, 'profile'])->middleware(EnsureUserAccount::class);
    Route::match(['put', 'patch'], '/me/profile', [UserAccountsController::class, 'updateProfile'])->middleware(EnsureUserAccount::class);

    Route::middleware('can:manageAccounts,App\\Models\\User')->group(function (): void {
        Route::get('/accounts', [AccountsController::class, 'index']);
        Route::post('/accounts', [AccountsController::class, 'store']);
        Route::get('/accounts/{account}', [AccountsController::class, 'show'])->whereNumber('account');
        Route::match(['put', 'patch'], '/accounts/{account}', [AccountsController::class, 'update'])->whereNumber('account');
        Route::put('/accounts/{account}/administrator', [AccountsController::class, 'setAdministrator'])->whereNumber('account');
        Route::post('/accounts/{account}/deactivate', [AccountsController::class, 'deactivate'])->whereNumber('account');
    });

    Route::middleware(['can:manageReferenceData,App\\Models\\Person'])->group(function (): void {
        Route::get('/people', [PeopleController::class, 'index']);
        Route::get('/people/{person}', [PeopleController::class, 'show'])->whereNumber('person');
        Route::post('/people', [PeopleController::class, 'store']);
        Route::match(['put', 'patch'], '/people/{person}', [PeopleController::class, 'update'])->whereNumber('person');
        Route::get('/categories', [CategoriesController::class, 'index']);
        Route::get('/categories/{category}', [CategoriesController::class, 'show'])->whereNumber('category');
        Route::post('/categories', [CategoriesController::class, 'store']);
        Route::match(['put', 'patch'], '/categories/{category}', [CategoriesController::class, 'update'])->whereNumber('category');
        Route::get('/storage-locations', [StorageLocationsController::class, 'index']);
        Route::get('/storage-locations/{location}', [StorageLocationsController::class, 'show'])->whereNumber('location');
        Route::post('/storage-locations', [StorageLocationsController::class, 'store']);
        Route::match(['put', 'patch'], '/storage-locations/{location}', [StorageLocationsController::class, 'update'])->whereNumber('location');
    });

    Route::middleware('can:manageItems,App\\Models\\FoundItem')->group(function (): void {
        Route::get('/reports/pending-claims', [ReportsController::class, 'pendingClaims']);
        Route::get('/reports/return-rates', [ReportsController::class, 'returnRates']);
        Route::get('/reports/storage-occupancy', [ReportsController::class, 'storageOccupancy']);
        Route::get('/reports/monthly-intake', [ReportsController::class, 'monthlyIntake']);
        Route::get('/items/{item}/claims', [ClaimsController::class, 'index'])->whereNumber('item');
        Route::get('/items/{item}/claims/{claimant}/proof-image', [ClaimProofImagesController::class, 'staff'])->whereNumber(['item', 'claimant'])->middleware(['can:manageClaims,App\\Models\\FoundItem', 'throttle:60,1']);
        Route::post('/items/{item}/claims', [ClaimsController::class, 'store'])->whereNumber('item');
        Route::post('/items/{item}/claims/{claimant}/reject', [ClaimsController::class, 'reject'])->whereNumber(['item', 'claimant']);
        Route::post('/items/{item}/claims/{claimant}/return', [ClaimsController::class, 'returnItem'])->whereNumber(['item', 'claimant']);
        Route::get('/items/{item}', [ItemsController::class, 'show'])->whereNumber('item');
        Route::get('/items/{item}/storage-history', [ItemsController::class, 'storageHistory'])->whereNumber('item');
        Route::get('/items', [ItemsController::class, 'index']);
        Route::post('/items', [ItemsController::class, 'store']);
        Route::post('/items/{item}/transfers', [ItemsController::class, 'transfer'])->whereNumber('item');
        Route::put('/staff/items/{item}/public-listing', [PublicItemsController::class, 'publish'])->whereNumber('item');
        Route::delete('/staff/items/{item}/public-listing', [PublicItemsController::class, 'unpublish'])->whereNumber('item');
        Route::get('/staff/finder-reports', [FinderReportsController::class, 'staffIndex']);
        Route::get('/staff/finder-reports/{report}', [FinderReportsController::class, 'staffShow'])->whereNumber('report');
        Route::post('/staff/finder-reports/{report}/receive', [FinderReportsController::class, 'receive'])->whereNumber('report')->middleware('throttle:10,1');
        Route::post('/staff/finder-reports/{report}/close', [FinderReportsController::class, 'close'])->whereNumber('report');
        Route::get('/staff/missing-reports', [MissingItemReportsController::class, 'staffIndex']);
        Route::get('/staff/missing-reports/{report}', [MissingItemReportsController::class, 'staffShow'])->whereNumber('report');
        Route::post('/staff/missing-reports/{report}/review', [MissingItemReportsController::class, 'review'])->whereNumber('report');
    });

    Route::middleware(EnsureUserAccount::class)->group(function (): void {
        Route::post('/auth/email/verification-notification', [UserAccountsController::class, 'resendVerification'])->middleware('throttle:3,1');
    });

    Route::middleware([EnsureUserAccount::class, EnsureVerifiedUserEmail::class])->prefix('my')->group(function (): void {
        Route::get('/finder-reports', [FinderReportsController::class, 'index']);
        Route::post('/finder-reports', [FinderReportsController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/finder-reports/{report}', [FinderReportsController::class, 'show'])->whereNumber('report');
        Route::post('/finder-reports/{report}/withdraw', [FinderReportsController::class, 'withdraw'])->whereNumber('report');
        Route::get('/missing-reports', [MissingItemReportsController::class, 'index']);
        Route::post('/missing-reports', [MissingItemReportsController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/missing-reports/{report}', [MissingItemReportsController::class, 'show'])->whereNumber('report');
        Route::match(['put', 'patch'], '/missing-reports/{report}', [MissingItemReportsController::class, 'update'])->whereNumber('report');
        Route::post('/missing-reports/{report}/resolve', [MissingItemReportsController::class, 'resolve'])->whereNumber('report');
        Route::post('/missing-reports/{report}/withdraw', [MissingItemReportsController::class, 'withdraw'])->whereNumber('report');
        Route::get('/claims', [UserClaimsController::class, 'index']);
        Route::post('/items/{item}/claims', [UserClaimsController::class, 'store'])->whereNumber('item')->middleware('throttle:10,1');
        Route::get('/claims/{item}', [UserClaimsController::class, 'show'])->whereNumber('item');
        Route::get('/claims/{item}/proof-image', [ClaimProofImagesController::class, 'owner'])->whereNumber('item')->middleware('throttle:60,1');
    });
});

Route::get('/public/categories', function (Request $request) {
    $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1']]);

    return response()->json(DB::table('category')->select(['category_id', 'category_name'])->orderBy('category_name')->orderBy('category_id')->paginate($data['per_page'] ?? 100));
})->middleware('throttle:60,1');
Route::get('/public/items/{item}/images/{image}', [ImagesController::class, 'publicContent'])->whereNumber(['item', 'image'])->middleware('throttle:120,1');

$images = static function (string $prefix, string $scope, string $audience, bool $write): void {
    $route = static fn ($r) => $r->defaults('scope', $scope)->defaults('audience', $audience)->whereNumber(['parent', 'image']);
    $route(Route::get($prefix.'/{parent}/images', [ImagesController::class, 'index']));
    $route(Route::get($prefix.'/{parent}/images/{image}/content', [ImagesController::class, 'content']));
    if ($write) {
        $route(Route::post($prefix.'/{parent}/images', [ImagesController::class, 'save']))->middleware('throttle:20,1');
        $route(Route::post($prefix.'/{parent}/images/{image}', [ImagesController::class, 'save']))->middleware('throttle:20,1');
        $route(Route::delete($prefix.'/{parent}/images/{image}', [ImagesController::class, 'destroy']));
    }
    if ($scope === 'item') {
        $route(Route::put($prefix.'/{parent}/images/{image}/publication', [ImagesController::class, 'publication']));
    }
};
Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, AuthenticateSession::class])->group(function () use ($images): void {
    Route::middleware([EnsureUserAccount::class, EnsureVerifiedUserEmail::class])->group(function () use ($images): void {
        $images('/my/finder-reports', 'finder', 'owner', true);
        $images('/my/missing-reports', 'missing', 'owner', true);
    });
    Route::middleware('can:manageItems,App\\Models\\FoundItem')->group(function () use ($images): void {
        $images('/staff/finder-reports', 'finder', 'staff', false);
        $images('/staff/missing-reports', 'missing', 'staff', false);
        $images('/items', 'item', 'staff', true);
    });
});
