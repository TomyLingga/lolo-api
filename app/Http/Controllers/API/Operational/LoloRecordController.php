<?php

namespace App\Http\Controllers\API\Operational;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\LoloRecord;
use App\Models\Registration;
use App\Models\StorageRecord;
use App\Models\TariffLolo;
use App\Models\TariffStorage;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LoloRecordController extends Controller
{
    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageCreate  = 'Berhasil membuat data';
    private string $messageUpdate  = 'Berhasil memperbarui data';
    private string $messageSuccess = 'Berhasil mengambil data';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Tutup storage record: isi end_date, hitung days & cost.
     */
    private function closeStorageRecord(StorageRecord $sr, string $endDate): void
    {
        $days = Carbon::parse($sr->start_date)->diffInDays(Carbon::parse($endDate))+1;

        $sr->update([
            'end_date'           => $endDate,
            'total_storage_days' => $days,
            'total_storage_cost' => $sr->calculateCost($days),
        ]);
    }

    /**
     * Cek apakah slot sudah terisi registrasi OPEN aktif lain.
     */
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

    // ─── Index ────────────────────────────────────────────────────────────────

    /**
     * GET /registrations/{registrationId}/lolo-records
     */
    public function index($registrationId)
    {
        try {
            $registration = Registration::find($registrationId);

            if (! $registration) {
                return response()->json(['message' => 'Registrasi tidak ditemukan'], 404);
            }

            $data = LoloRecord::with(['cargoStatus', 'createdBy'])
                ->where('registration_id', $registrationId)
                ->orderBy('lolo_at', 'asc')
                ->get();

            return $data->isEmpty()
                ? response()->json(['message' => 'Belum ada lolo record'], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    public function show($id)
    {
        try {
            $data = LoloRecord::with(['cargoStatus', 'createdBy', 'registration', 'registration.size', 'registration.type'])->find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            return response()->json(['data' => $data, 'message' => $this->messageSuccess], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    // ─── Store ────────────────────────────────────────────────────────────────

    /**
     * POST /registrations/{registrationId}/lolo-records
     *
     * LIFT_ON  (kontainer keluar) → close storage record aktif, end_date = lolo_at
     * LIFT_OFF (kontainer masuk)  → buat storage record baru, butuh data lokasi di request
     */
    public function store(Request $request, $registrationId)
    {
        DB::beginTransaction();

        try {
            $registration = Registration::with('storageRecords')->find($registrationId);

            if (! $registration) {
                return response()->json(['message' => 'Registrasi tidak ditemukan', 'success' => false], 404);
            }

            if ($registration->record_status === 'CLOSED' || ! $registration->is_active) {
                return response()->json([
                    'message' => 'Tidak dapat menambah lolo record pada registrasi yang sudah CLOSED atau tidak aktif.',
                    'success' => false,
                ], 400);
            }

            // Validasi base
            $baseRules = [
                'cargo_status_id' => 'required|exists:cargo_statuses,id',
                'operation_type'  => 'required|in:LIFT_ON,LIFT_OFF',
                'vehicle_type'    => 'nullable|string|max:50',
                'vehicle_number'  => 'nullable|string|max:20',
                'lolo_at'         => 'required|date',
            ];

            // Validasi tambahan jika LIFT_OFF (kontainer masuk lagi ke lokasi baru)
            $liftOffRules = [
                'yard_id'    => 'required|exists:yards,id',
                'block_id'   => 'required|exists:blocks,id',
                'pos_length' => 'required|integer|min:1',
                'pos_width'  => 'required|integer|min:1',
                'pos_height' => 'required|integer|min:1',
                'moved_at'   => 'required|date',
                'note'       => 'nullable|string',
            ];

            $rules = $request->operation_type === 'LIFT_OFF'
                ? array_merge($baseRules, $liftOffRules)
                : $baseRules;

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            // Storage record aktif (end_date null)
            $activeStorage = $registration->storageRecords()
                ->whereNull('end_date')
                ->latest('moved_at')
                ->first();

            // Cek storage aktif hanya wajib ada saat LIFT_ON (kontainer keluar dari yard).
            // Saat LIFT_OFF, storage aktif tidak ada karena kontainer baru masuk → akan dibuat baru.
            if ($request->operation_type === 'LIFT_ON' && ! $activeStorage) {
                return response()->json([
                    'message' => 'Tidak ada storage record aktif untuk registrasi ini.',
                    'success' => false,
                ], 400);
            }

            // Ambil lolo record terakhir
            $lastLolo = $registration->loloRecords()->latest('lolo_at')->first();

            // Validasi urutan: LIFT_OFF → LIFT_ON → LIFT_OFF → LIFT_ON (bergantian)
            if ($lastLolo && $lastLolo->operation_type === $request->operation_type) {
                $last = $lastLolo->operation_type === 'LIFT_OFF' ? 'LIFT_OFF' : 'LIFT_ON';
                $next = $last === 'LIFT_OFF' ? 'LIFT_ON' : 'LIFT_OFF';
                return response()->json([
                    'message' => "Urutan lolo tidak valid. Lolo terakhir adalah {$last}, berikutnya harus {$next}.",
                    'success' => false,
                ], 400);
            }

            // Validasi lolo_at tidak boleh lebih kecil dari lolo terakhir
            if ($lastLolo && Carbon::parse($request->lolo_at)->lt(Carbon::parse($lastLolo->lolo_at))) {
                return response()->json([
                    'message' => 'Tanggal/waktu lolo tidak boleh lebih awal dari lolo sebelumnya ('
                        . Carbon::parse($lastLolo->lolo_at)->format('d-m-Y H:i') . ').',
                    'success' => false,
                ], 400);
            }

            // Validasi lolo_at tidak boleh lebih kecil dari moved_at storage record terakhir
            $lastStorage = $registration->storageRecords()->latest('moved_at')->first();
            if ($lastStorage && Carbon::parse($request->lolo_at)->lt(Carbon::parse($lastStorage->moved_at))) {
                return response()->json([
                    'message' => 'Tanggal/waktu lolo tidak boleh lebih awal dari perpindahan kontainer terakhir ('
                        . Carbon::parse($lastStorage->moved_at)->format('d-m-Y H:i') . ').',
                    'success' => false,
                ], 400);
            }

            $loloDate = Carbon::parse($request->lolo_at)->toDateString();

            // Yard yang dipakai untuk lookup tarif lolo:
            // - LIFT_ON  → yard saat ini (activeStorage)
            // - LIFT_OFF → yard tujuan (dari request)
            $yardIdForTariff = $request->operation_type === 'LIFT_OFF'
                ? $request->yard_id
                : $activeStorage->yard_id;

            // Lookup tarif Lolo
            $tariffLolo = TariffLolo::where([
                    'yard_id'           => $yardIdForTariff,
                    'container_size_id' => $registration->container_size_id,
                    'container_type_id' => $registration->container_type_id,
                    'cargo_status_id'   => $request->cargo_status_id,
                    'package_id'        => $registration->package_id,
                ])
                ->where('is_active', true)
                ->where('effective_date', '<=', $loloDate)
                ->orderBy('effective_date', 'desc')
                ->first();

            if (! $tariffLolo) {
                return response()->json([
                    'message' => 'Tarif Lolo tidak ditemukan untuk kombinasi ini.',
                    'success' => false,
                ], 404);
            }

            $tariffPrice = $request->operation_type === 'LIFT_OFF'
                ? $tariffLolo->price_lift_off
                : $tariffLolo->price_lift_on;

            // ── LIFT_ON: kontainer keluar → close storage record aktif ────────
            if ($request->operation_type === 'LIFT_ON') {
                $this->closeStorageRecord($activeStorage, $loloDate);
            }

            // ── LIFT_OFF: kontainer masuk lagi → buat storage record baru ─────
            if ($request->operation_type === 'LIFT_OFF') {
                // Cek slot tujuan tidak terisi
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
                if ($this->isSlotOccupied(
                    $request->block_id,
                    $request->pos_length,
                    $request->pos_width,
                    $request->pos_height,
                    $registration->id
                )) {
                    return response()->json([
                        'message' => 'Slot lokasi tujuan sudah terisi oleh kontainer lain.',
                        'success' => false,
                    ], 400);
                }

                // Lookup tarif storage di yard tujuan
                $tariffStorage = TariffStorage::where([
                        'yard_id'           => $request->yard_id,
                        'container_size_id' => $registration->container_size_id,
                        'container_type_id' => $registration->container_type_id,
                        'cargo_status_id'   => $request->cargo_status_id,
                    ])
                    ->where('is_active', true)
                    ->where('effective_date', '<=', $loloDate)
                    ->orderBy('effective_date', 'desc')
                    ->first();

                if (! $tariffStorage) {
                    return response()->json([
                        'message' => 'Tarif Storage tidak ditemukan untuk kombinasi ini di yard tujuan.',
                        'success' => false,
                    ], 404);
                }

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
                    'start_date'            => $loloDate,   // otomatis dari lolo_at
                    'end_date'              => null,
                    'storage_price_per_day' => $tariffStorage->price_per_day,
                    'total_storage_days'    => 0,
                    'total_storage_cost'    => 0,
                    'note'                  => $request->note,
                ]);
            }

            // Buat lolo record
            $lolo = LoloRecord::create([
                'registration_id' => $registration->id,
                'cargo_status_id' => $request->cargo_status_id,
                'created_by'      => $request->user()->id,
                'operation_type'  => $request->operation_type,
                'vehicle_type'    => $request->vehicle_type,
                'vehicle_number'  => $request->vehicle_number,
                'operator_name'   => $request->user()->name,
                'tariff_price'    => $tariffPrice,
                'lolo_at'         => $request->lolo_at,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $lolo->load(['cargoStatus', 'createdBy']),
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

    // ─── Update ───────────────────────────────────────────────────────────────

    /**
     * PUT /lolo-records/{id}
     * Update data lolo record. Jika cargo_status_id berubah, tarif di-recalculate.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $lolo = LoloRecord::with('registration')->find($id);

            if (! $lolo) {
                return response()->json(['message' => $this->messageMissing, 'success' => false], 404);
            }

            if ($request->user()->role !== 'admin') {
                return response()->json(['message' => 'Hanya admin yang dapat mengubah riwayat LOLO.', 'success' => false], 403);
            }

            if ($lolo->registration->record_status === 'CLOSED') {
                return response()->json([
                    'message' => 'Tidak dapat mengubah lolo record pada registrasi yang sudah CLOSED.',
                    'success' => false,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'cargo_status_id' => 'sometimes|required|exists:cargo_statuses,id',
                'vehicle_type'    => 'nullable|string|max:50',
                'vehicle_number'  => 'nullable|string|max:20',
                'lolo_at'         => 'sometimes|required|date',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            $tariffPrice = $lolo->tariff_price;

            // Recalculate tarif jika cargo_status berubah
            if ($request->filled('cargo_status_id') && $request->cargo_status_id != $lolo->cargo_status_id) {
                $activeStorage = $lolo->registration->storageRecords()
                    ->whereNull('end_date')
                    ->latest('moved_at')
                    ->first();

                $tariffLolo = TariffLolo::where([
                        'yard_id'           => $activeStorage?->yard_id,
                        'container_size_id' => $lolo->registration->container_size_id,
                        'container_type_id' => $lolo->registration->container_type_id,
                        'cargo_status_id'   => $request->cargo_status_id,
                        'package_id'        => $lolo->registration->package_id,
                    ])
                    ->where('is_active', true)
                    ->where('effective_date', '<=', Carbon::parse($lolo->lolo_at)->toDateString())
                    ->orderBy('effective_date', 'desc')
                    ->first();

                if (! $tariffLolo) {
                    return response()->json([
                        'message' => 'Tarif Lolo tidak ditemukan untuk cargo status yang baru.',
                        'success' => false,
                    ], 404);
                }

                $tariffPrice = $lolo->operation_type === 'LIFT_OFF'
                    ? $tariffLolo->price_lift_off
                    : $tariffLolo->price_lift_on;
            }

            $lolo->update([
                'cargo_status_id' => $request->cargo_status_id ?? $lolo->cargo_status_id,
                'vehicle_type'    => $request->has('vehicle_type')   ? $request->vehicle_type   : $lolo->vehicle_type,
                'vehicle_number'  => $request->has('vehicle_number') ? $request->vehicle_number : $lolo->vehicle_number,
                'operator_name'   => $request->user()->name,
                'lolo_at'         => $request->lolo_at ?? $lolo->lolo_at,
                'tariff_price'    => $tariffPrice,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $lolo->fresh()->load(['cargoStatus', 'createdBy']),
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

    // ─── Error helper ─────────────────────────────────────────────────────────

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
