<?php

namespace App\Http\Controllers\API\Operational;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\LoloRecord;
use App\Models\Registration;
use App\Models\RegistrationRemark;
use App\Models\StorageRecord;
use App\Models\TariffLolo;
use App\Models\TariffStorage;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RegistrationController extends Controller
{
    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageSuccess = 'Berhasil mengambil data';
    private string $messageCreate  = 'Berhasil membuat data';
    private string $messageUpdate  = 'Berhasil memperbarui data';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Relasi ringkas — dipakai untuk daftar (index, getOpen, getClosed, getNotInvoiced).
     * Hanya kolom yang ditampilkan di tabel/list FE.
     */
    private function getListWith(): array
    {
        return [
            'createdBy:id,name',
            'freightForwarders:id,name',
            'size:id,code,description',
            'type:id,code,description',
            'storageRecords.yard:id,name,code',
            'storageRecords.block:id,block_code',
            'storageRecords.cargoStatus:id,code,description',
            'storageRecords.movedBy:id,name,jabatan,bagian',
            'loloRecords.cargoStatus:id,code,description',
            'loloRecords.createdBy:id,name,jabatan,bagian',
            'registrationRemarks',
            'registrationRemarks.createdBy:id,name',
        ];
    }

    /**
     * Relasi lengkap — dipakai hanya untuk show() (detail satu registrasi).
     */
    private function getWith(): array
    {
        return [
            'createdBy:id,name',
            'freightForwarders:id,name,contact_person,contact_number',
            'size:id,code,description',
            'type:id,code,description',
            'registrationRemarks.createdBy:id,name',
            'loloRecords.cargoStatus:id,code,description',
            'loloRecords.createdBy:id,name',
            'storageRecords.yard:id,name,code',
            'storageRecords.block:id,block_code,max_length,max_width,max_height',
            'storageRecords.cargoStatus:id,code,description',
        ];
    }

    private function isSlotOccupied(int $blockId, int $posLength, int $posWidth, int $posHeight, ?int $excludeRegistrationId = null): bool
    {
        return StorageRecord::where('block_id', $blockId)
            ->where('pos_length', $posLength)
            ->where('pos_width', $posWidth)
            ->where('pos_height', $posHeight)
            ->whereNull('end_date')
            ->whereHas('registration', function ($q) use ($excludeRegistrationId) {
                $q->where('record_status', 'OPEN')
                  ->where('is_active', true)
                  ->when($excludeRegistrationId, fn($q) => $q->where('id', '!=', $excludeRegistrationId));
            })
            ->exists();
    }

    private function closeStorageRecord(StorageRecord $sr, string $endDate): void
    {
        $days = Carbon::parse($sr->start_date)->diffInDays(Carbon::parse($endDate))+1;

        $sr->update([
            'end_date'           => $endDate,
            'total_storage_days' => $days,
            'total_storage_cost' => $days * $sr->storage_price_per_day,
        ]);
    }

    /**
     * Query builder dengan semua filter opsional.
     * Dipakai oleh index(), getOpen(), getClosed(), getNotInvoiced().
     */
    private function buildQuery(Request $request)
    {
        $query = Registration::with($this->getListWith())->orderBy('id', 'desc');

        // Filter tanggal berdasarkan lolo_at pertama (LIFT_OFF pertama)
        if ($request->filled('date_from')) {
            $query->whereHas('loloRecords', function ($q) use ($request) {
                $q->where('operation_type', 'LIFT_OFF')
                  ->where('lolo_at', '>=', Carbon::parse($request->date_from)->startOfDay());
            });
        }

        if ($request->filled('date_to')) {
            $query->whereHas('loloRecords', function ($q) use ($request) {
                $q->where('operation_type', 'LIFT_OFF')
                  ->where('lolo_at', '<=', Carbon::parse($request->date_to)->endOfDay());
            });
        }

        if ($request->filled('freight_forwarder_id')) {
            $query->where('freight_forwarder_id', $request->freight_forwarder_id);
        }

        if ($request->filled('container_number')) {
            $query->where('container_number', 'like', '%' . strtoupper($request->container_number) . '%');
        }

        return $query;
    }

    private function validateBlockCapacity($blockId, $length, $width, $height)
    {
        $block = Block::find($blockId);

        if (! $block) {
            return 'Block tidak ditemukan.';
        }

        if ($length > $block->max_length) {
            return "Panjang melebihi kapasitas block (max: {$block->max_length}).";
        }

        if ($width > $block->max_width) {
            return "Lebar melebihi kapasitas block (max: {$block->max_width}).";
        }

        if ($height > $block->max_height) {
            return "Tinggi melebihi kapasitas block (max: {$block->max_height}).";
        }

        return null;
    }

    // ─── Index & Filters ─────────────────────────────────────────────────────

    /**
     * GET /registrations
     * Semua registrasi. Filter opsional: date_from, date_to, freight_forwarder_id, container_number.
     */
    public function index(Request $request)
    {
        try {
            $data = $this->buildQuery($request)->get();

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /registrations/open
     * Hanya registrasi OPEN & aktif.
     */
    public function getOpen(Request $request)
    {
        try {
            $data = $this->buildQuery($request)
                ->where('record_status', 'OPEN')
                ->where('is_active', true)
                ->get();

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /registrations/closed
     * Hanya registrasi CLOSED.
     */
    public function getClosed(Request $request)
    {
        try {
            $data = $this->buildQuery($request)
                ->where('record_status', 'CLOSED')
                ->get();

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /registrations/not-invoiced
     * Registrasi CLOSED tapi belum diinvoice — kandidat untuk generate invoice.
     */
    public function getNotInvoiced(Request $request)
    {
        try {
            $data = $this->buildQuery($request)
                ->where('record_status', 'CLOSED')
                ->where('invoiced', false)
                ->where('is_active', true)
                ->get();

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    // ─── CRUD ────────────────────────────────────────────────────────────────

    public function show($id)
    {
        try {
            $data = Registration::with($this->getWith())->find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            return response()->json(['data' => $data, 'message' => $this->messageSuccess], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'freight_forwarder_id' => 'required|exists:freight_forwarders,id',
                'container_number'     => 'required|string|max:20',
                'container_size_id'    => 'required|exists:container_sizes,id',
                'container_type_id'    => 'required|exists:container_types,id',
                'cargo_status_id'      => 'required|exists:cargo_statuses,id',
                'no_do_jo'             => 'nullable|string|max:100',
                'shipper_tenant'       => 'nullable|string|max:255',
                'remark'               => 'nullable|string',
                'vehicle_type'         => 'nullable|string|max:50',
                'vehicle_number'       => 'nullable|string|max:20',
                'lolo_at'              => 'required|date',  // = start_date storage otomatis
                'yard_id'              => 'required|exists:yards,id',
                'block_id'             => 'required|exists:blocks,id',
                'pos_length'           => 'required|integer|min:1',
                'pos_width'            => 'required|integer|min:1',
                'pos_height'           => 'required|integer|min:1',
                'moved_at'             => 'required|date',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            $capacityError = $this->validateBlockCapacity(
                $request->block_id,
                $request->pos_length,
                $request->pos_width,
                $request->pos_height
            );

            if ($capacityError) {
                return response()->json([
                    'message' => $capacityError,
                    'success' => false,
                ], 400);
            }

            // start_date = tanggal dari lolo_at (otomatis)
            $startDate = Carbon::parse($request->lolo_at)->toDateString();

            // 1. Cek duplikat container + FF sama masih OPEN & aktif
            $duplicate = Registration::where('container_number', strtoupper($request->container_number))
                ->where('freight_forwarder_id', $request->freight_forwarder_id)
                ->where('record_status', 'OPEN')
                ->where('is_active', true)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'message' => 'Kontainer ini sudah memiliki registrasi OPEN aktif untuk Freight Forwarder yang sama.',
                    'success' => false,
                ], 400);
            }

            // 2. Cek slot tidak terisi
            if ($this->isSlotOccupied($request->block_id, $request->pos_length, $request->pos_width, $request->pos_height)) {
                return response()->json([
                    'message' => 'Slot lokasi sudah terisi oleh kontainer lain.',
                    'success' => false,
                ], 400);
            }

            // 3. Lookup tarif Lolo
            $tariffLolo = TariffLolo::where([
                    'yard_id'           => $request->yard_id,
                    'container_size_id' => $request->container_size_id,
                    'container_type_id' => $request->container_type_id,
                    'cargo_status_id'   => $request->cargo_status_id,
                ])
                ->where('is_active', true)
                ->where('effective_date', '<=', $startDate)
                ->orderBy('effective_date', 'desc')
                ->first();

            if (! $tariffLolo) {
                return response()->json([
                    'message' => 'Tarif Lolo tidak ditemukan untuk kombinasi yard, ukuran, tipe, dan status kargo ini.',
                    'success' => false,
                ], 404);
            }

            // 4. Lookup tarif Storage
            $tariffStorage = TariffStorage::where([
                    'yard_id'           => $request->yard_id,
                    'container_size_id' => $request->container_size_id,
                    'container_type_id' => $request->container_type_id,
                    'cargo_status_id'   => $request->cargo_status_id,
                ])
                ->where('is_active', true)
                ->where('effective_date', '<=', $startDate)
                ->orderBy('effective_date', 'desc')
                ->first();

            if (! $tariffStorage) {
                return response()->json([
                    'message' => 'Tarif Storage tidak ditemukan untuk kombinasi yard, ukuran, tipe, dan status kargo ini.',
                    'success' => false,
                ], 404);
            }

            // 5. Buat Registration
            $registration = Registration::create([
                'created_by'           => $request->user()->id,
                'freight_forwarder_id' => $request->freight_forwarder_id,
                'container_number'     => strtoupper($request->container_number),
                'container_size_id'    => $request->container_size_id,
                'container_type_id'    => $request->container_type_id,
                'no_do_jo'             => $request->no_do_jo,
                'shipper_tenant'       => $request->shipper_tenant,
                'record_status'        => 'OPEN',
                'invoiced'             => false,
                'is_active'            => true,
            ]);

            // 6. Buat RegistrationRemark
            if ($request->filled('remark')) {
                RegistrationRemark::create([
                    'registration_id' => $registration->id,
                    'created_by'      => $request->user()->id,
                    'remark'          => $request->remark,
                ]);
            }

            // 7. Buat LoloRecord LIFT_OFF
            LoloRecord::create([
                'registration_id' => $registration->id,
                'cargo_status_id' => $request->cargo_status_id,
                'created_by'      => $request->user()->id,
                'operation_type'  => 'LIFT_OFF',
                'vehicle_type'    => $request->vehicle_type,
                'vehicle_number'  => $request->vehicle_number,
                'operator_name'   => $request->user()->name,
                'tariff_price'    => $tariffLolo->price_lift_off,
                'lolo_at'         => $request->lolo_at,
            ]);

            // 8. Buat StorageRecord — start_date otomatis dari lolo_at
            StorageRecord::create([
                'registration_id'       => $registration->id,
                'cargo_status_id'       => $request->cargo_status_id,
                'yard_id'               => $request->yard_id,
                'block_id'              => $request->block_id,
                'moved_by'              => $request->user()->id,
                'pos_length'            => $request->pos_length,
                'pos_width'             => $request->pos_width,
                'pos_height'            => $request->pos_height,
                'moved_at'              => $request->moved_at,
                'start_date'            => $startDate,   // dari lolo_at
                'end_date'              => null,
                'storage_price_per_day' => $tariffStorage->price_per_day,
                'total_storage_days'    => 0,
                'total_storage_cost'    => 0,
                'note'                  => $request->remark,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $registration->load($this->getWith()),
                'message' => $this->messageCreate,
                'success' => true,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $this->messageFail,
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $data = Registration::find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing, 'success' => false], 404);
            }

            if (! $data->is_active || $data->record_status === 'CLOSED') {
                return response()->json([
                    'message' => 'Registrasi yang sudah CLOSED atau tidak aktif tidak dapat diubah.',
                    'success' => false,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'freight_forwarder_id' => 'sometimes|required|exists:freight_forwarders,id',
                'no_do_jo'             => 'nullable|string|max:100',
                'shipper_tenant'       => 'nullable|string|max:255',
                'container_size_id'    => 'sometimes|required|exists:container_sizes,id',
                'container_type_id'    => 'sometimes|required|exists:container_types,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            $data->update([
                'freight_forwarder_id' => $request->freight_forwarder_id ?? $data->freight_forwarder_id,
                'no_do_jo'             => $request->has('no_do_jo')       ? $request->no_do_jo       : $data->no_do_jo,
                'shipper_tenant'       => $request->has('shipper_tenant') ? $request->shipper_tenant : $data->shipper_tenant,
                'container_size_id'    => $request->container_size_id     ?? $data->container_size_id,
                'container_type_id'    => $request->container_type_id     ?? $data->container_type_id,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $data->fresh()->load($this->getWith()),
                'message' => $this->messageUpdate,
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $this->messageFail,
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $data = Registration::find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing, 'success' => false], 404);
            }

            if ($data->record_status === 'CLOSED') {
                return response()->json([
                    'message' => 'Registrasi yang sudah CLOSED tidak dapat diubah statusnya.',
                    'success' => false,
                ], 400);
            }

            if ($data->invoiced) {
                return response()->json([
                    'message' => 'Registrasi yang sudah diinvoice tidak dapat dinonaktifkan.',
                    'success' => false,
                ], 400);
            }

            $data->update(['is_active' => ! $data->is_active]);

            DB::commit();

            return response()->json([
                'message'   => $data->is_active ? 'Registrasi berhasil diaktifkan.' : 'Registrasi berhasil dinonaktifkan.',
                'is_active' => $data->is_active,
                'success'   => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $this->messageFail,
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function close(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $data = Registration::find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing, 'success' => false], 404);
            }

            $validator = Validator::make($request->all(), [
                'remark' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            if ($data->record_status === 'CLOSED') {
                return response()->json(['message' => 'Registrasi sudah CLOSED.', 'success' => false], 400);
            }

            if (! $data->is_active) {
                return response()->json(['message' => 'Registrasi tidak aktif.', 'success' => false], 400);
            }

            $lastLolo = $data->loloRecords()->latest('lolo_at')->first();

            if (! $lastLolo || $lastLolo->operation_type !== 'LIFT_ON') {
                return response()->json([
                    'message' => 'Registrasi hanya bisa di-close jika lolo record terakhir adalah LIFT_ON (kontainer sudah keluar).',
                    'success' => false,
                ], 400);
            }

            $activeStorage = $data->storageRecords()->whereNull('end_date')->first();

            if ($activeStorage) {
                $endDate = Carbon::parse($lastLolo->lolo_at)->toDateString();
                $this->closeStorageRecord($activeStorage, $endDate);
            }

            $data->update(['record_status' => 'CLOSED']);

            $remark = RegistrationRemark::create([
                'registration_id' => $data->id,
                'created_by'      => $request->user()->id,
                'remark'          => $request->remark,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $data->fresh()->load($this->getWith()),
                'message' => 'Registrasi berhasil di-close.',
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $this->messageFail,
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    // ─── Dashboard ───────────────────────────────────────────────────────────

    /**
     * GET /dashboard/yard-map?container_number=
     * Optimized: single eager-loaded query, PHP-side grouping to eliminate N+1.
     */
    public function yardMap(Request $request)
    {
        try {
            $containerNumber = $request->filled('container_number')
                ? strtoupper(trim($request->container_number))
                : null;

            // Single query: all active storage records with all relations eager-loaded
            $storageRecords = StorageRecord::select(
                    'id', 'block_id', 'yard_id', 'registration_id',
                    'pos_length', 'pos_width', 'pos_height', 'start_date'
                )
                ->whereNull('end_date')
                ->whereHas('registration', fn ($q) =>
                    $q->where('record_status', 'OPEN')->where('is_active', true)
                )
                ->with([
                    'registration:id,container_number,freight_forwarder_id,container_size_id,container_type_id,no_do_jo,shipper_tenant',
                    'registration.freightForwarders:id,name',
                    'registration.size:id,code,description',
                    'registration.type:id,code,description',
                ])
                ->get();

            // PHP-side grouping — no extra queries
            $byBlock = $storageRecords->groupBy('block_id');

            // Yards + blocks in one query
            $yards = \App\Models\Yard::with(['blocks' => fn ($q) => $q->orderBy('block_code')])
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            $result = $yards->map(function ($yard) use ($byBlock, $containerNumber) {
                $totalCapacity = 0;
                $totalOccupied = 0;

                $blocks = $yard->blocks->map(function ($block) use ($byBlock, $containerNumber, &$totalCapacity, &$totalOccupied) {
                    $capacity = $block->max_length * $block->max_width * $block->max_height;
                    $totalCapacity += $capacity;

                    $records = $byBlock->get($block->id, collect());

                    $registrations = $records->map(fn ($sr) => [
                        'id'                => $sr->registration->id,
                        'container_number'  => $sr->registration->container_number,
                        'no_do_jo'          => $sr->registration->no_do_jo,
                        'shipper_tenant'    => $sr->registration->shipper_tenant,
                        'freight_forwarder' => $sr->registration->freightForwarders,
                        'size'              => $sr->registration->size,
                        'type'              => $sr->registration->type,
                        'pos_length'        => $sr->pos_length,
                        'pos_width'         => $sr->pos_width,
                        'pos_height'        => $sr->pos_height,
                        'start_date'        => $sr->start_date,
                    ])->values();

                    $occupied = $registrations->count();
                    $totalOccupied += $occupied;

                    $isHighlighted = $containerNumber && $registrations->contains(
                        fn ($r) => str_contains($r['container_number'], $containerNumber)
                    );

                    return [
                        'id'             => $block->id,
                        'block_code'     => $block->block_code,
                        'max_length'     => $block->max_length,
                        'max_width'      => $block->max_width,
                        'max_height'     => $block->max_height,
                        'capacity'       => $capacity,
                        'is_active'      => $block->is_active,
                        'occupied_count' => $occupied,
                        'is_highlighted' => $isHighlighted,
                        'registrations'  => $registrations,
                    ];
                })->values();

                return [
                    'id'             => $yard->id,
                    'name'           => $yard->name,
                    'code'           => $yard->code,
                    'total_blocks'   => $blocks->count(),
                    'total_capacity' => $totalCapacity,
                    'total_occupied' => $totalOccupied,
                    'blocks'         => $blocks,
                ];
            })->values();

            return response()->json([
                'data'    => $result,
                'message' => 'Berhasil mengambil data yard map',
            ], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    // ─── Error helpers ───────────────────────────────────────────────────────

    private function queryError(QueryException $e)
    {
        return response()->json([
            'message' => $this->messageFail,
            'err'     => $e->getTrace()[0],
            'errMsg'  => $e->getMessage(),
            'success' => false,
        ], 500);
    }
}
