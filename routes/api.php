<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\Invoice\InvoiceController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\Master\BlockController;
use App\Http\Controllers\API\Master\CargoStatusController;
use App\Http\Controllers\API\Master\ContainerSizeController;
use App\Http\Controllers\API\Master\ContainerTypeController;
use App\Http\Controllers\API\Master\FreightForwarderController;
use App\Http\Controllers\API\Master\TaxController;
use App\Http\Controllers\API\Master\YardController;
use App\Http\Controllers\API\Operational\LoloRecordController;
use App\Http\Controllers\API\Operational\RegistrationController;
use App\Http\Controllers\API\Operational\RegistrationRemarkController;
use App\Http\Controllers\API\Operational\StorageRecordController;
use App\Http\Controllers\API\Tariff\TariffLoloController;
use App\Http\Controllers\API\Tariff\TariffStorageController;
use Illuminate\Support\Facades\Route;

// ─── Public ──────────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);
Route::get('/invoices/{id}/pdf',                                        [InvoiceController::class, 'exportPdf']);


// ─── Authenticated ────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // ─── Admin only ───────────────────────────────────────────────────────────
    Route::middleware(['admin'])->group(function () {

        // User management
        Route::get('/users',                            [UserController::class, 'index']);
        Route::get('/users/{id}',                       [UserController::class, 'show']);
        Route::post('/users',                           [UserController::class, 'store']);
        Route::put('/users/{id}',                       [UserController::class, 'update']);
        Route::delete('/users/{id}',                    [UserController::class, 'destroy']);
        Route::post('/users/{id}/reset-password',       [UserController::class, 'resetPassword']);

        // Master data — write (admin only)
        Route::post('/master/yards',                    [YardController::class,           'store']);
        Route::put('/master/yards/{id}',                [YardController::class,           'update']);
        Route::delete('/master/yards/{id}',             [YardController::class,           'destroy']);

        Route::post('/master/blocks',                   [BlockController::class,          'store']);
        Route::put('/master/blocks/{id}',               [BlockController::class,          'update']);
        Route::delete('/master/blocks/{id}',            [BlockController::class,          'destroy']);

        Route::post('/master/container-sizes',          [ContainerSizeController::class,  'store']);
        Route::put('/master/container-sizes/{id}',      [ContainerSizeController::class,  'update']);
        Route::delete('/master/container-sizes/{id}',   [ContainerSizeController::class,  'destroy']);

        Route::post('/master/container-types',          [ContainerTypeController::class,  'store']);
        Route::put('/master/container-types/{id}',      [ContainerTypeController::class,  'update']);
        Route::delete('/master/container-types/{id}',   [ContainerTypeController::class,  'destroy']);

        Route::post('/master/cargo-statuses',           [CargoStatusController::class,    'store']);
        Route::put('/master/cargo-statuses/{id}',       [CargoStatusController::class,    'update']);
        Route::delete('/master/cargo-statuses/{id}',    [CargoStatusController::class,    'destroy']);

        Route::post('/master/freight-forwarders',       [FreightForwarderController::class, 'store']);
        Route::put('/master/freight-forwarders/{id}',   [FreightForwarderController::class, 'update']);
        Route::delete('/master/freight-forwarders/{id}',[FreightForwarderController::class, 'destroy']);

        Route::post('/master/taxes',                    [TaxController::class,            'store']);
        Route::put('/master/taxes/{id}',                [TaxController::class,            'update']);
        Route::delete('/master/taxes/{id}',             [TaxController::class,            'destroy']);

        Route::post('/tariffs/lolo',                    [TariffLoloController::class, 'store']);
        Route::put('/tariffs/lolo/{id}',                [TariffLoloController::class, 'update']);
        Route::delete('/tariffs/lolo/{id}',             [TariffLoloController::class, 'destroy']);

        Route::post('/tariffs/storage',                 [TariffStorageController::class, 'store']);
        Route::put('/tariffs/storage/{id}',             [TariffStorageController::class, 'update']);
        Route::delete('/tariffs/storage/{id}',          [TariffStorageController::class, 'destroy']);

        Route::put('/registrations/{id}',               [RegistrationController::class, 'update']);
        Route::delete('/registrations/{id}',            [RegistrationController::class, 'destroy']);

        Route::put('/lolo-records/{id}',                [LoloRecordController::class, 'update']);
        Route::put('/storage-records/{id}',             [StorageRecordController::class, 'update']);

        Route::delete('/invoices/{id}',                  [InvoiceController::class, 'destroy']);

    });

    // ─── Petugas + Admin — read master data & operasional ────────────────────
    Route::middleware(['petugas'])->group(function () {

        // Master data — read (semua role terautentikasi)
        Route::get('/master/yards',                     [YardController::class,           'index']);
        Route::get('/master/yards/{id}',                [YardController::class,           'show']);

        Route::get('/master/blocks',                    [BlockController::class,          'index']);
        Route::get('/master/blocks/{id}',               [BlockController::class,          'show']);

        Route::get('/master/container-sizes',           [ContainerSizeController::class,  'index']);
        Route::get('/master/container-sizes/{id}',      [ContainerSizeController::class,  'show']);

        Route::get('/master/container-types',           [ContainerTypeController::class,  'index']);
        Route::get('/master/container-types/{id}',      [ContainerTypeController::class,  'show']);

        Route::get('/master/cargo-statuses',            [CargoStatusController::class,    'index']);
        Route::get('/master/cargo-statuses/{id}',       [CargoStatusController::class,    'show']);

        Route::get('/master/freight-forwarders',        [FreightForwarderController::class, 'index']);
        Route::get('/master/freight-forwarders/{id}',   [FreightForwarderController::class, 'show']);

        Route::get('/master/taxes',                     [TaxController::class,            'index']);
        Route::get('/master/taxes/{id}',                [TaxController::class,            'show']);

        Route::get('/tariffs/lolo',                     [TariffLoloController::class, 'index']);
        Route::get('/tariffs/lolo/{id}',                [TariffLoloController::class, 'show']);
        Route::post('/tariffs/lolo/active',             [TariffLoloController::class, 'getActiveTariff']);

        Route::get('/tariffs/storage',                  [TariffStorageController::class, 'index']);
        Route::get('/tariffs/storage/{id}',             [TariffStorageController::class, 'show']);
        Route::post('/tariffs/storage/active',          [TariffStorageController::class, 'getActiveTariff']);

        Route::get('/registrations',                    [RegistrationController::class, 'index']);        // semua + filter tanggal
        Route::get('/registrations/open',               [RegistrationController::class, 'getOpen']);      // OPEN only
        Route::get('/registrations/closed',             [RegistrationController::class, 'getClosed']);    // CLOSED only
        Route::get('/registrations/not-invoiced',       [RegistrationController::class, 'getNotInvoiced']); // CLOSED belum invoice
        Route::get('/registrations/{id}',               [RegistrationController::class, 'show']);
        Route::post('/registrations',                   [RegistrationController::class, 'store']);
        Route::post('/registrations/{id}/close',        [RegistrationController::class, 'close']);

        Route::get('/registrations/{registrationId}/lolo-records',        [LoloRecordController::class, 'index']);
        Route::post('/registrations/{registrationId}/lolo-records',       [LoloRecordController::class, 'store']);

        Route::get('/registrations/{registrationId}/storage-records',     [StorageRecordController::class, 'index']);
        Route::post('/registrations/{registrationId}/storage-records',    [StorageRecordController::class, 'store']);

        Route::get('/registrations/{registrationId}/remarks',  [RegistrationRemarkController::class, 'index']);
        Route::post('/registrations/{registrationId}/remarks', [RegistrationRemarkController::class, 'store']);

        Route::get('/invoices',                                                 [InvoiceController::class, 'index']);
        Route::get('/invoices/{id}',                                            [InvoiceController::class, 'show']);
        Route::get('/freight-forwarders/{ffId}/registrations/invoiceable',      [InvoiceController::class, 'getInvoiceableRegistrations']);
        Route::post('/invoices',                                                [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}/pay',                                        [InvoiceController::class, 'pay']);
        // Route::get('/invoices/{id}/pdf',                                        [InvoiceController::class, 'exportPdf']);
        Route::put('/invoices/{id}',                                            [InvoiceController::class, 'update']);
    });
});
