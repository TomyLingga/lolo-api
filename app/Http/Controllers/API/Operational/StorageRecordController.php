<?php

namespace App\Http\Controllers\API\Operational;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Registration;
use App\Models\StorageRecord;
use App\Models\TariffStorage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StorageRecordController extends Controller
{
    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageCreate  = 'Berhasil membuat data';
    private string $messageUpdate  = 'Berhasil memperbarui data';

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

    /**
     * GET /registrations/{registration_id}/storage-records
     */
    public function index($registrationId)
    {
        try {
            $registration = Registration::find($registrationId);

            if (! $registration) {
                return response()->json(['message' => 'Registrasi tidak ditemukan'], 404);
            }

            $data = StorageRecord::with(['yard', 'block', 'cargoStatus'])
                ->where('registration_id', $registrationId)
                ->orderBy('moved_at', 'asc')
                ->get();

            return $data->isEmpty()
                ? response()->json(['message' => 'Belum ada storage record'], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return response()->json([
                'message' => $this->messageFail,
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    /**
     * POST /registrations/{registration_id}/storage-records
     *
     * Pindahkan kontainer ke lokasi baru:
     * 1. Tutup storage record lama (isi end_date, hitung days & cost)
     * 2. Buat storage record baru di lokasi tujuan
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
                    'message' => 'Tidak dapat memindahkan kontainer pada registrasi yang sudah CLOSED atau tidak aktif.',
                    'success' => false,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'cargo_status_id' => 'required|exists:cargo_statuses,id',
                'yard_id'         => 'required|exists:yards,id',
                'block_id'        => 'required|exists:blocks,id',
                'pos_length'      => 'required|integer|min:1',
                'pos_width'       => 'required|integer|min:1',
                'pos_height'      => 'required|integer|min:1',
                'moved_at'        => 'required|date',
                'start_date'      => 'required|date',
                'note'            => 'nullable|string',
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

            $movedAt = \Carbon\Carbon::parse($request->moved_at);

            // Validasi moved_at tidak boleh lebih kecil dari moved_at atau end_date storage sebelumnya
            $lastStorage = $registration->storageRecords()->latest('moved_at')->first();
            if ($lastStorage) {
                // Bandingkan dengan moved_at storage terakhir
                if ($movedAt->lt(\Carbon\Carbon::parse($lastStorage->moved_at))) {
                    return response()->json([
                        'message' => 'Tanggal/waktu perpindahan tidak boleh lebih awal dari perpindahan sebelumnya ('
                            . \Carbon\Carbon::parse($lastStorage->moved_at)->format('d-m-Y H:i') . ').',
                        'success' => false,
                    ], 400);
                }
                // Bandingkan dengan end_date storage terakhir (jika sudah ditutup)
                if ($lastStorage->end_date && $movedAt->lt(\Carbon\Carbon::parse($lastStorage->end_date))) {
                    return response()->json([
                        'message' => 'Tanggal/waktu perpindahan tidak boleh lebih awal dari tanggal keluar storage sebelumnya ('
                            . \Carbon\Carbon::parse($lastStorage->end_date)->format('d-m-Y') . ').',
                        'success' => false,
                    ], 400);
                }
            }

            // Validasi moved_at tidak boleh lebih kecil dari lolo_at terakhir
            $lastLolo = $registration->loloRecords()->latest('lolo_at')->first();
            if ($lastLolo && $movedAt->lt(\Carbon\Carbon::parse($lastLolo->lolo_at))) {
                return response()->json([
                    'message' => 'Tanggal/waktu perpindahan tidak boleh lebih awal dari lolo terakhir ('
                        . \Carbon\Carbon::parse($lastLolo->lolo_at)->format('d-m-Y H:i') . ').',
                    'success' => false,
                ], 400);
            }

            // Cek slot tujuan tidak terisi (kecuali oleh registrasi ini sendiri)
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
                ->where('effective_date', '<=', now()->toDateString())
                ->orderBy('effective_date', 'desc')
                ->first();

            if (! $tariffStorage) {
                return response()->json([
                    'message' => 'Tarif Storage tidak ditemukan untuk kombinasi ini di yard tujuan.',
                    'success' => false,
                ], 404);
            }

            // Tutup storage record lama yang masih open
            $oldStorage = $registration->storageRecords()->whereNull('end_date')->latest('moved_at')->first();

            if ($oldStorage) {
                $endDate  = \Carbon\Carbon::parse($request->start_date)->toDateString();
                $start    = \Carbon\Carbon::parse($oldStorage->start_date);
                $end      = \Carbon\Carbon::parse($endDate);
                $days     = $start->diffInDays($end)+1;

                $oldStorage->update([
                    'end_date'           => $endDate,
                    'total_storage_days' => $days,
                    'total_storage_cost' => $days * $oldStorage->storage_price_per_day,
                ]);
            }

            // Buat storage record baru
            $newStorage = StorageRecord::create([
                'registration_id'       => $registration->id,
                'cargo_status_id'       => $request->cargo_status_id,
                'yard_id'               => $request->yard_id,
                'block_id'              => $request->block_id,
                'moved_by'              => $request->user()->id,
                'pos_length'            => $request->pos_length,
                'pos_width'             => $request->pos_width,
                'pos_height'            => $request->pos_height,
                'moved_at'              => $request->moved_at,
                'start_date'            => $request->start_date,
                'end_date'              => null,
                'storage_price_per_day' => $tariffStorage->price_per_day,
                'total_storage_days'    => 0,
                'total_storage_cost'    => 0,
                'note'                  => $request->note,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $newStorage->load(['yard', 'block', 'cargoStatus']),
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

    /**
     * PUT /storage-records/{id}
     * Edit catatan (note) atau koreksi start_date.
     * Jika start_date diubah, total_storage_days & cost di-recalculate jika end_date sudah ada.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $storage = StorageRecord::with('registration')->find($id);

            if (! $storage) {
                return response()->json(['message' => $this->messageMissing, 'success' => false], 404);
            }

            if ($storage->registration->record_status === 'CLOSED') {
                return response()->json([
                    'message' => 'Tidak dapat mengubah storage record pada registrasi yang sudah CLOSED.',
                    'success' => false,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'start_date' => 'sometimes|required|date',
                'note'       => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            $updateData = [
                'note' => $request->has('note') ? $request->note : $storage->note,
            ];

            // Jika start_date diubah dan end_date sudah ada, recalculate
            if ($request->filled('start_date')) {
                $updateData['start_date'] = $request->start_date;

                if ($storage->end_date) {
                    $days = \Carbon\Carbon::parse($request->start_date)
                        ->diffInDays(\Carbon\Carbon::parse($storage->end_date))+1;

                    $updateData['total_storage_days'] = $days;
                    $updateData['total_storage_cost'] = $days * $storage->storage_price_per_day;
                }
            }

            $storage->update($updateData);

            DB::commit();

            return response()->json([
                'data'    => $storage->fresh()->load(['yard', 'block', 'cargoStatus']),
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
}
