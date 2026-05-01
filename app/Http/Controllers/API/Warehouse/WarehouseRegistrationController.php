<?php

namespace App\Http\Controllers\API\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\WarehouseChamber;
use App\Models\WarehouseRegistration;
use App\Models\WarehouseRegistrationRemark;
use App\Models\WarehouseTariff;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WarehouseRegistrationController extends Controller
{
    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageSuccess = 'Berhasil mengambil data';
    private string $messageCreate  = 'Berhasil membuat data';
    private string $messageUpdate  = 'Berhasil memperbarui data';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Relasi ringkas — untuk index / list.
     */
    private function getListWith(): array
    {
        return [
            'freightForwarder:id,name,contact_person,contact_number',
            'chamber:id,name,code,length_m,width_m,area_m2,warehouse_id',
            'chamber.warehouse:id,name,code',
            'createdBy:id,name',
        ];
    }

    /**
     * Relasi lengkap — untuk show() / detail.
     */
    private function getWith(): array
    {
        return [
            'freightForwarder:id,name,contact_person,contact_number',
            'chamber:id,name,code,length_m,width_m,area_m2,warehouse_id',
            'chamber.warehouse:id,name,code',
            'createdBy:id,name',
            'remarks.createdBy:id,name',
        ];
    }

    /**
     * Query builder dengan filter opsional.
     * Dipakai oleh index(), getActive(), getClosed(), getNotInvoiced().
     */
    private function buildQuery(Request $request)
    {
        $query = WarehouseRegistration::with($this->getListWith())
            ->orderBy('created_at', 'desc');

        if ($request->filled('freight_forwarder_id')) {
            $query->where('freight_forwarder_id', $request->freight_forwarder_id);
        }

        if ($request->filled('warehouse_id')) {
            $query->whereHas('chamber', fn ($q) =>
                $q->where('warehouse_id', $request->warehouse_id)
            );
        }

        if ($request->filled('date_from')) {
            $query->whereDate('rent_start', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('rent_start', '<=', $request->date_to);
        }

        return $query;
    }

    // ─── Index & Filters ─────────────────────────────────────────────────────

    /**
     * GET /warehouse-registrations
     * Semua registrasi dengan filter opsional.
     */
    public function index(Request $request)
    {
        try {
            $data = $this->buildQuery($request)->get();

            return response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /warehouse-registrations/active
     * Hanya registrasi ACTIVE & is_active true.
     */
    public function getActive(Request $request)
    {
        try {
            $data = $this->buildQuery($request)->active()->get();

            return response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /warehouse-registrations/closed
     * Hanya registrasi CLOSED, filter date by rent_end.
     */
    public function getClosed(Request $request)
    {
        try {
            $query = $this->buildQuery($request)->closed();

            // Override filter tanggal: untuk CLOSED pakai rent_end
            if ($request->filled('date_from')) {
                $query->whereDate('rent_end', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('rent_end', '<=', $request->date_to);
            }

            $data = $query->orderBy('rent_end', 'desc')->get();

            return response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /warehouse-registrations/not-invoiced
     * CLOSED, belum diinvoice, masih aktif.
     */
    public function getNotInvoiced(Request $request)
    {
        try {
            $data = $this->buildQuery($request)->notInvoiced()->get();

            return response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    // ─── CRUD ────────────────────────────────────────────────────────────────

    /**
     * GET /warehouse-registrations/{id}
     */
    public function show($id)
    {
        try {
            $data = WarehouseRegistration::with($this->getWith())->find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            return response()->json(['data' => $data, 'message' => $this->messageSuccess], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * POST /warehouse-registrations
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'freight_forwarder_id' => 'required|exists:freight_forwarders,id',
                'chamber_id'           => 'required|exists:warehouse_chambers,id',
                'rent_start'           => 'required|date',
                'rent_end'             => 'required|date|after_or_equal:rent_start',
                'remark'               => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            // Cek overlap chamber pada periode yang sama
            if (WarehouseRegistration::hasOverlap(
                $request->chamber_id,
                $request->rent_start,
                $request->rent_end
            )) {
                return response()->json([
                    'message' => 'Chamber sudah disewa pada periode tersebut. Silakan pilih tanggal atau chamber lain.',
                    'success' => false,
                ], 400);
            }

            // Snapshot area dari chamber saat ini
            $chamber = WarehouseChamber::findOrFail($request->chamber_id);

            // Ambil tarif aktif yang berlaku pada rent_start
            $tariff = WarehouseTariff::where('warehouse_id', $chamber->warehouse_id)
                ->where('is_active', true)
                ->where('effective_date', '<=', $request->rent_start)
                ->orderBy('effective_date', 'desc')
                ->first();

            if (! $tariff) {
                return response()->json([
                    'message' => 'Tidak ada tarif aktif untuk warehouse ini. Silakan atur tarif terlebih dahulu.',
                    'success' => false,
                ], 400);
            }

            $registration = WarehouseRegistration::create([
                'freight_forwarder_id' => $request->freight_forwarder_id,
                'chamber_id'           => $request->chamber_id,
                'tariff_per_m2'        => $tariff->price_per_m2,
                'area_m2'              => $chamber->area_m2,
                'rent_start'           => $request->rent_start,
                'rent_end'             => $request->rent_end,
                'record_status'        => 'ACTIVE',
                'invoiced'             => false,
                'is_active'            => true,
                'created_by'           => $request->user()->id,
            ]);

            if ($request->filled('remark')) {
                WarehouseRegistrationRemark::create([
                    'warehouse_registration_id' => $registration->id,
                    'remark'                    => $request->remark,
                    'created_by'                => $request->user()->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'data'    => $registration->load($this->getWith()),
                'message' => $this->messageCreate,
                'success' => true,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    /**
     * PUT /warehouse-registrations/{id}
     * Admin only — hanya bisa edit rent_start & rent_end selama masih ACTIVE.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $registration = WarehouseRegistration::find($id);

            if (! $registration) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($registration->record_status === 'CLOSED') {
                return response()->json([
                    'message' => 'Registrasi yang sudah CLOSED tidak dapat diubah.',
                    'success' => false,
                ], 400);
            }

            if (! $registration->is_active) {
                return response()->json([
                    'message' => 'Registrasi tidak aktif tidak dapat diubah.',
                    'success' => false,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'rent_start' => 'sometimes|required|date',
                'rent_end'   => 'sometimes|required|date|after_or_equal:rent_start',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            $newStart = $request->rent_start ?? $registration->rent_start->toDateString();
            $newEnd   = $request->rent_end   ?? $registration->rent_end->toDateString();

            // Cek overlap, kecualikan registrasi ini sendiri
            if (WarehouseRegistration::hasOverlap(
                $registration->chamber_id,
                $newStart,
                $newEnd,
                $registration->id
            )) {
                return response()->json([
                    'message' => 'Chamber sudah disewa pada periode tersebut.',
                    'success' => false,
                ], 400);
            }

            $registration->update([
                'rent_start' => $newStart,
                'rent_end'   => $newEnd,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $registration->fresh()->load($this->getWith()),
                'message' => $this->messageUpdate,
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    /**
     * POST /warehouse-registrations/{id}/close
     */
    public function close(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $registration = WarehouseRegistration::find($id);

            if (! $registration) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($registration->record_status === 'CLOSED') {
                return response()->json([
                    'message' => 'Registrasi sudah CLOSED.',
                    'success' => false,
                ], 400);
            }

            if (! $registration->is_active) {
                return response()->json([
                    'message' => 'Registrasi tidak aktif.',
                    'success' => false,
                ], 400);
            }

            $registration->update(['record_status' => 'CLOSED']);

            if ($request->filled('remark')) {
                WarehouseRegistrationRemark::create([
                    'warehouse_registration_id' => $registration->id,
                    'remark'                    => $request->remark,
                    'created_by'                => $request->user()->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'data'    => $registration->fresh()->load($this->getWith()),
                'message' => 'Registrasi sewa berhasil ditutup.',
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    /**
     * DELETE /warehouse-registrations/{id}
     * Admin only — toggle is_active.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $registration = WarehouseRegistration::find($id);

            if (! $registration) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($registration->record_status === 'CLOSED') {
                return response()->json([
                    'message' => 'Registrasi yang sudah CLOSED tidak dapat diubah statusnya.',
                    'success' => false,
                ], 400);
            }

            if ($registration->invoiced) {
                return response()->json([
                    'message' => 'Registrasi yang sudah diinvoice tidak dapat dinonaktifkan.',
                    'success' => false,
                ], 400);
            }

            $registration->update(['is_active' => ! $registration->is_active]);

            DB::commit();

            return response()->json([
                'message'   => $registration->is_active
                    ? 'Registrasi berhasil diaktifkan.'
                    : 'Registrasi berhasil dinonaktifkan.',
                'is_active' => $registration->is_active,
                'success'   => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    // ─── Remarks ─────────────────────────────────────────────────────────────

    /**
     * GET /warehouse-registrations/{id}/remarks
     */
    public function indexRemarks($registrationId)
    {
        try {
            $registration = WarehouseRegistration::find($registrationId);

            if (! $registration) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            $remarks = WarehouseRegistrationRemark::with('createdBy:id,name')
                ->where('warehouse_registration_id', $registrationId)
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json(['data' => $remarks, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * POST /warehouse-registrations/{id}/remarks
     */
    public function storeRemark(Request $request, $registrationId)
    {
        try {
            $registration = WarehouseRegistration::find($registrationId);

            if (! $registration) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            $validator = Validator::make($request->all(), [
                'remark' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            $remark = WarehouseRegistrationRemark::create([
                'warehouse_registration_id' => $registrationId,
                'remark'                    => $request->remark,
                'created_by'                => $request->user()->id,
            ]);

            return response()->json([
                'data'    => $remark->load('createdBy:id,name'),
                'message' => 'Catatan berhasil ditambahkan.',
                'success' => true,
            ], 201);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    /**
     * GET /dashboard/warehouse-map
     * Menampilkan semua warehouse dengan status chamber saat ini.
     */
    public function warehouseMap()
    {
        try {
            $data = \App\Models\Warehouse::with(['chambers' => function ($q) {
                $q->where('is_active', true);
            }])
            ->where('is_active', true)
            ->get()
            ->map(function ($warehouse) {
                $chambers = $warehouse->chambers->map(function ($chamber) {
                    // Cari registrasi aktif hari ini
                    $activeReg = WarehouseRegistration::with('freightForwarder:id,name')
                        ->where('chamber_id', $chamber->id)
                        ->where('is_active', true)
                        ->where('record_status', 'ACTIVE')
                        ->whereDate('rent_start', '<=', now())
                        ->whereDate('rent_end', '>=', now())
                        ->first();

                    return array_merge($chamber->toArray(), [
                        'active_registration' => $activeReg
                    ]);
                });

                return array_merge($warehouse->toArray(), [
                    'chambers'       => $chambers,
                    'total_chambers' => $chambers->count(),
                    'occupied_count' => $chambers->whereNotNull('active_registration')->count(),
                ]);
            });

            return response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─── Helper: Available Chambers ──────────────────────────────────────────

    /**
     * GET /warehouses/available-chambers?warehouse_id=&rent_start=&rent_end=
     */
    public function getAvailableChambers(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'warehouse_id' => 'required|exists:warehouses,id',
                'rent_start'   => 'nullable|date',
                'rent_end'     => 'nullable|date|after_or_equal:rent_start',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            // Chamber yang sudah terpakai pada periode ini
            $occupiedIds = collect();
            if ($request->filled('rent_start') && $request->filled('rent_end')) {
                $occupiedIds = WarehouseRegistration::where('is_active', true)
                    ->where('record_status', 'ACTIVE')
                    ->where('rent_start', '<=', $request->rent_end)
                    ->where('rent_end', '>=', $request->rent_start)
                    ->pluck('chamber_id');
            }

            $chambers = WarehouseChamber::where('warehouse_id', $request->warehouse_id)
                ->where('is_active', true)
                ->get()
                ->map(fn ($chamber) => array_merge(
                    $chamber->toArray(),
                    ['is_available' => $occupiedIds->isEmpty() ? true : ! $occupiedIds->contains($chamber->id)]
                ));

            return response()->json(['data' => $chambers, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    // ─── Error Helpers ───────────────────────────────────────────────────────

    private function queryError(QueryException $e)
    {
        return response()->json([
            'message' => $this->messageFail,
            'err'     => $e->getTrace()[0],
            'errMsg'  => $e->getMessage(),
            'success' => false,
        ], 500);
    }

    private function serverError(\Exception $e)
    {
        return response()->json([
            'message' => $this->messageFail,
            'err'     => $e->getTrace()[0],
            'errMsg'  => $e->getMessage(),
            'success' => false,
        ], 500);
    }
}
