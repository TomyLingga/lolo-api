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
use App\Http\Controllers\API\Master\PackageController;
use App\Http\Controllers\API\Master\WarehouseChamberController;
use App\Http\Controllers\API\Master\WarehouseController;
use App\Http\Controllers\API\Operational\LoloRecordController;
use App\Http\Controllers\API\Operational\RegistrationController;
use App\Http\Controllers\API\Operational\RegistrationRemarkController;
use App\Http\Controllers\API\Operational\StorageRecordController;
use App\Http\Controllers\API\Tariff\TariffLoloController;
use App\Http\Controllers\API\Tariff\TariffStorageController;
use App\Http\Controllers\API\Tariff\WarehouseTariffController;
use App\Http\Controllers\API\Warehouse\WarehouseRegistrationController;
use App\Http\Controllers\API\Warehouse\WarehouseBeritaAcaraController;
use App\Http\Controllers\API\Warehouse\WarehouseInvoiceController;
use Illuminate\Support\Facades\Route;

// ─── Public ───────────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);
Route::get('/invoices/{id}/pdf',           [InvoiceController::class,          'exportPdf']);
Route::get('/warehouse-invoices/{id}/pdf', [WarehouseInvoiceController::class,  'exportPdf']);
Route::get('/warehouse-berita-acaras/{id}/pdf', [WarehouseBeritaAcaraController::class, 'exportPdf']);

// ─── Authenticated ────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // ─── Admin only ───────────────────────────────────────────────────────────
    Route::middleware(['admin'])->group(function () {

        // User management
        Route::get('/users',                      [UserController::class, 'index']);
        Route::get('/users/{id}',                 [UserController::class, 'show']);
        Route::post('/users',                     [UserController::class, 'store']);
        Route::put('/users/{id}',                 [UserController::class, 'update']);
        Route::delete('/users/{id}',              [UserController::class, 'destroy']);
        Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);

        // Master data — write
        Route::post('/master/yards',                   [YardController::class,              'store']);
        Route::put('/master/yards/{id}',               [YardController::class,              'update']);
        Route::delete('/master/yards/{id}',            [YardController::class,              'destroy']);

        Route::post('/master/blocks',                  [BlockController::class,             'store']);
        Route::put('/master/blocks/{id}',              [BlockController::class,             'update']);
        Route::delete('/master/blocks/{id}',           [BlockController::class,             'destroy']);

        Route::post('/master/container-sizes',         [ContainerSizeController::class,     'store']);
        Route::put('/master/container-sizes/{id}',     [ContainerSizeController::class,     'update']);
        Route::delete('/master/container-sizes/{id}',  [ContainerSizeController::class,     'destroy']);

        Route::post('/master/container-types',         [ContainerTypeController::class,     'store']);
        Route::put('/master/container-types/{id}',     [ContainerTypeController::class,     'update']);
        Route::delete('/master/container-types/{id}',  [ContainerTypeController::class,     'destroy']);

        Route::post('/master/cargo-statuses',          [CargoStatusController::class,       'store']);
        Route::put('/master/cargo-statuses/{id}',      [CargoStatusController::class,       'update']);
        Route::delete('/master/cargo-statuses/{id}',   [CargoStatusController::class,       'destroy']);

        Route::post('/master/freight-forwarders',      [FreightForwarderController::class,  'store']);
        Route::put('/master/freight-forwarders/{id}',  [FreightForwarderController::class,  'update']);
        Route::delete('/master/freight-forwarders/{id}', [FreightForwarderController::class,'destroy']);

        Route::post('/master/taxes',                   [TaxController::class,               'store']);
        Route::put('/master/taxes/{id}',               [TaxController::class,               'update']);
        Route::delete('/master/taxes/{id}',            [TaxController::class,               'destroy']);

        Route::post('/master/package',                 [PackageController::class,           'store']);
        Route::put('/master/package/{id}',             [PackageController::class,           'update']);
        Route::delete('/master/package/{id}',          [PackageController::class,           'destroy']);

        Route::post('/master/warehouses',              [WarehouseController::class,         'store']);
        Route::put('/master/warehouses/{id}',          [WarehouseController::class,         'update']);
        Route::delete('/master/warehouses/{id}',       [WarehouseController::class,         'destroy']);

        Route::post('/master/warehouse-chambers',      [WarehouseChamberController::class,  'store']);
        Route::put('/master/warehouse-chambers/{id}',  [WarehouseChamberController::class,  'update']);
        Route::delete('/master/warehouse-chambers/{id}', [WarehouseChamberController::class,'destroy']);

        // Tariff — write
        Route::post('/tariffs/lolo',                   [TariffLoloController::class,        'store']);
        Route::put('/tariffs/lolo/{id}',               [TariffLoloController::class,        'update']);
        Route::delete('/tariffs/lolo/{id}',            [TariffLoloController::class,        'destroy']);

        Route::post('/tariffs/storage',                [TariffStorageController::class,     'store']);
        Route::put('/tariffs/storage/{id}',            [TariffStorageController::class,     'update']);
        Route::delete('/tariffs/storage/{id}',         [TariffStorageController::class,     'destroy']);

        Route::post('/tariffs/warehouse',              [WarehouseTariffController::class,   'store']);
        Route::put('/tariffs/warehouse/{id}',          [WarehouseTariffController::class,   'update']);
        Route::delete('/tariffs/warehouse/{id}',       [WarehouseTariffController::class,   'destroy']);

        // Container Registration — admin write
        Route::put('/registrations/{id}',              [RegistrationController::class,      'update']);
        Route::delete('/registrations/{id}',           [RegistrationController::class,      'destroy']);

        Route::put('/lolo-records/{id}',               [LoloRecordController::class,        'update']);
        Route::put('/storage-records/{id}',            [StorageRecordController::class,     'update']);

        // Container Invoice — admin only destroy
        Route::delete('/invoices/{id}',                [InvoiceController::class,           'destroy']);

        // Warehouse Registration — admin write
        Route::put('/warehouse-registrations/{id}',    [WarehouseRegistrationController::class, 'update']);
        Route::delete('/warehouse-registrations/{id}', [WarehouseRegistrationController::class, 'destroy']);

        // Warehouse BA — admin destroy
        Route::delete('/warehouse-berita-acaras/{id}', [WarehouseBeritaAcaraController::class, 'destroy']);

        // Warehouse Invoice — admin write/destroy
        Route::put('/warehouse-invoices/{id}',         [WarehouseInvoiceController::class,  'update']);
        Route::delete('/warehouse-invoices/{id}',      [WarehouseInvoiceController::class,  'destroy']);
    });

    // ─── Petugas + Admin ──────────────────────────────────────────────────────
    Route::middleware(['petugas'])->group(function () {

        // ── Master — read ─────────────────────────────────────────────────────
        Route::get('/master/yards',                    [YardController::class,              'index']);
        Route::get('/master/yards/{id}',               [YardController::class,              'show']);

        Route::get('/master/blocks',                   [BlockController::class,             'index']);
        Route::get('/master/blocks/{id}',              [BlockController::class,             'show']);
        Route::get('/master/blocks/{blockId}/occupied-slots', [RegistrationController::class, 'getOccupiedSlots']);

        Route::get('/master/container-sizes',          [ContainerSizeController::class,     'index']);
        Route::get('/master/container-sizes/{id}',     [ContainerSizeController::class,     'show']);

        Route::get('/master/container-types',          [ContainerTypeController::class,     'index']);
        Route::get('/master/container-types/{id}',     [ContainerTypeController::class,     'show']);

        Route::get('/master/cargo-statuses',           [CargoStatusController::class,       'index']);
        Route::get('/master/cargo-statuses/{id}',      [CargoStatusController::class,       'show']);

        Route::get('/master/freight-forwarders',       [FreightForwarderController::class,  'index']);
        Route::get('/master/freight-forwarders/{id}',  [FreightForwarderController::class,  'show']);

        Route::get('/master/taxes',                    [TaxController::class,               'index']);
        Route::get('/master/taxes/{id}',               [TaxController::class,               'show']);

        Route::get('/master/package',                  [PackageController::class,           'index']);
        Route::get('/master/package/{id}',             [PackageController::class,           'show']);

        Route::get('/master/warehouses',               [WarehouseController::class,         'index']);
        Route::get('/master/warehouses/{id}',          [WarehouseController::class,         'show']);

        Route::get('/master/warehouse-chambers',       [WarehouseChamberController::class,  'index']);
        Route::get('/master/warehouse-chambers/{id}',  [WarehouseChamberController::class,  'show']);

        // ── Tariff — read ─────────────────────────────────────────────────────
        Route::get('/tariffs/lolo',                    [TariffLoloController::class,        'index']);
        Route::get('/tariffs/lolo/{id}',               [TariffLoloController::class,        'show']);
        Route::post('/tariffs/lolo/active',            [TariffLoloController::class,        'getActiveTariff']);

        Route::get('/tariffs/storage',                 [TariffStorageController::class,     'index']);
        Route::get('/tariffs/storage/{id}',            [TariffStorageController::class,     'show']);
        Route::post('/tariffs/storage/active',         [TariffStorageController::class,     'getActiveTariff']);

        Route::get('/tariffs/warehouse',               [WarehouseTariffController::class,   'index']);
        Route::get('/tariffs/warehouse/{id}',          [WarehouseTariffController::class,   'show']);
        Route::post('/tariffs/warehouse/active',       [WarehouseTariffController::class,   'getActiveTariff']);

        // ── Container Registration ────────────────────────────────────────────
        Route::get('/registrations',                   [RegistrationController::class,      'index']);
        Route::get('/registrations/open',              [RegistrationController::class,      'getOpen']);
        Route::get('/registrations/closed',            [RegistrationController::class,      'getClosed']);
        Route::get('/registrations/not-invoiced',      [RegistrationController::class,      'getNotInvoiced']);
        Route::get('/dashboard/yard-map',              [RegistrationController::class,      'yardMap']);
        Route::get('/registrations/{id}',              [RegistrationController::class,      'show']);
        Route::post('/registrations',                  [RegistrationController::class,      'store']);
        Route::post('/registrations/{id}/close',       [RegistrationController::class,      'close']);

        Route::get('/registrations/{registrationId}/lolo-records',     [LoloRecordController::class,        'index']);
        Route::get('/lolo-records/{id}',                               [LoloRecordController::class,        'show']);
        Route::post('/registrations/{registrationId}/lolo-records',    [LoloRecordController::class,        'store']);

        Route::get('/registrations/{registrationId}/storage-records',  [StorageRecordController::class,     'index']);
        Route::post('/registrations/{registrationId}/storage-records', [StorageRecordController::class,     'store']);

        Route::get('/registrations/{registrationId}/remarks',          [RegistrationRemarkController::class,'index']);
        Route::post('/registrations/{registrationId}/remarks',         [RegistrationRemarkController::class,'store']);

        // ── Container Invoice ─────────────────────────────────────────────────
        Route::get('/invoices',                                         [InvoiceController::class, 'index']);
        Route::get('/invoices/{id}',                                    [InvoiceController::class, 'show']);
        Route::post('/invoices',                                        [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}/pay',                                [InvoiceController::class, 'pay']);
        Route::put('/invoices/{id}',                                    [InvoiceController::class, 'update']);
        Route::get('/freight-forwarders/{ffId}/registrations/invoiceable', [InvoiceController::class, 'getInvoiceableRegistrations']);

        // ── Warehouse Registration ────────────────────────────────────────────
        Route::get('/warehouse-registrations',                 [WarehouseRegistrationController::class, 'index']);
        Route::get('/warehouse-registrations/active',          [WarehouseRegistrationController::class, 'getActive']);
        Route::get('/warehouse-registrations/closed',          [WarehouseRegistrationController::class, 'getClosed']);
        Route::get('/warehouse-registrations/not-invoiced',    [WarehouseRegistrationController::class, 'getNotInvoiced']);
        Route::get('/warehouse-registrations/{id}',            [WarehouseRegistrationController::class, 'show']);
        Route::post('/warehouse-registrations',                [WarehouseRegistrationController::class, 'store']);
        Route::post('/warehouse-registrations/{id}/close',     [WarehouseRegistrationController::class, 'close']);
        Route::get('/warehouse-registrations/{id}/remarks',    [WarehouseRegistrationController::class, 'indexRemarks']);
        Route::post('/warehouse-registrations/{id}/remarks',   [WarehouseRegistrationController::class, 'storeRemark']);
        Route::get('/warehouses/available-chambers',           [WarehouseRegistrationController::class, 'getAvailableChambers']);
        Route::get('/dashboard/warehouse-map',                 [WarehouseRegistrationController::class, 'warehouseMap']);

        // Helper: registrasi yg bisa dibuatkan BA
        Route::get('/freight-forwarders/{ffId}/warehouse-registrations/invoiceable-ba',
            [WarehouseBeritaAcaraController::class, 'getInvoiceableRegistrations']);

        // ── Warehouse Berita Acara ────────────────────────────────────────────
        Route::get('/warehouse-berita-acaras',                 [WarehouseBeritaAcaraController::class, 'index']);
        Route::get('/warehouse-berita-acaras/{id}',            [WarehouseBeritaAcaraController::class, 'show']);
        Route::post('/warehouse-berita-acaras',                [WarehouseBeritaAcaraController::class, 'store']);
        Route::put('/warehouse-berita-acaras/{id}',            [WarehouseBeritaAcaraController::class, 'update']);
        Route::post('/warehouse-berita-acaras/{baId}/additional-fees',
            [WarehouseBeritaAcaraController::class, 'storeAdditionalFee']);
        Route::delete('/warehouse-berita-acaras/{baId}/additional-fees/{feeId}',
            [WarehouseBeritaAcaraController::class, 'destroyAdditionalFee']);

        // Helper: BA yg bisa diinvoice
        Route::get('/freight-forwarders/{ffId}/warehouse-berita-acaras/invoiceable',
            [WarehouseInvoiceController::class, 'getInvoiceableBas']);

        // ── Warehouse Invoice ─────────────────────────────────────────────────
        Route::get('/warehouse-invoices',                      [WarehouseInvoiceController::class, 'index']);
        Route::get('/warehouse-invoices/{id}',                 [WarehouseInvoiceController::class, 'show']);
        Route::post('/warehouse-invoices',                     [WarehouseInvoiceController::class, 'store']);
        Route::get('/warehouse-invoices/{id}/pay',             [WarehouseInvoiceController::class, 'pay']);
    });
});
