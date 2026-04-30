<?php

namespace App\Http\Controllers\API\Tariff;

use App\Http\Controllers\Controller;
use App\Models\WarehouseTariff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;

class WarehouseTariffController extends Controller
{
    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageSuccess = 'Berhasil mengambil data';
    private string $messageCreate  = 'Berhasil membuat data';
    private string $messageUpdate  = 'Berhasil memperbarui data';

    public function index()
    {
        try {
            $data = WarehouseTariff::with(['warehouse'])
                ->orderBy('id', 'desc')
                ->get();

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);

        } catch (QueryException $e) {
            return response()->json([
                'message' => $this->messageFail,
                'err' => $e->getTrace()[0],
                'errMsg' => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $data = WarehouseTariff::with(['warehouse'])->find($id);

            if (!$data) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            return response()->json([
                'data' => $data,
                'message' => $this->messageSuccess,
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'message' => $this->messageFail,
                'err' => $e->getTrace()[0],
                'errMsg' => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'warehouse_id' => 'required|exists:warehouses,id',
                'price_per_m2' => 'required|numeric',
                'effective_date' => 'required|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            $exists = WarehouseTariff::where([
                'warehouse_id' => $request->warehouse_id,
                'price_per_m2' => $request->price_per_m2,
                'effective_date' => $request->effective_date,
            ])
            ->where('is_active', true)
            ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Tarif sudah ada untuk kombinasi ini di tanggal tersebut',
                    'success' => false
                ], 400);
            }

            $data = WarehouseTariff::create($request->all());

            DB::commit();

            return response()->json([
                'data' => $data->load(['warehouse']),
                'message' => $this->messageCreate,
                'success' => true,
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();

            return response()->json([
                'message' => $this->messageFail,
                'err' => $e->getTrace()[0],
                'errMsg' => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $data = WarehouseTariff::find($id);

            if (!$data) {
                return response()->json([
                    'message' => $this->messageMissing,
                    'success' => false,
                ], 404);
            }

            $data->update([
                'warehouse_id' => $request->warehouse_id ?? $data->warehouse_id,
                'price_per_m2' => $request->price_per_m2 ?? $data->price_per_m2,
                'effective_date' => $request->effective_date ?? $data->effective_date,
            ]);

            DB::commit();

            return response()->json([
                'data' => $data->load(['warehouse']),
                'message' => $this->messageUpdate,
                'success' => true,
            ], 200);

        } catch (\Exception $e) {
            DB::rollback();

            return response()->json([
                'message' => $this->messageFail,
                'err' => $e->getTrace()[0],
                'errMsg' => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $data = WarehouseTariff::find($id);

            if (!$data) {
                return response()->json([
                    'message' => $this->messageMissing,
                    'success' => false,
                ], 404);
            }

            $data->update([
                'is_active' => ! $data->is_active
            ]);

            DB::commit();

            return response()->json([
                'message' => $data->is_active
                    ? 'Tariff berhasil diaktifkan'
                    : 'Tariff berhasil dinonaktifkan',
                'success' => true,
                'is_active' => $data->is_active
            ], 200);

        } catch (\Exception $e) {
            DB::rollback();

            return response()->json([
                'message' => $this->messageFail,
                'err' => $e->getTrace()[0],
                'errMsg' => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function getActiveTariff(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'warehouse_id' => 'required',
                'date' => 'nullable|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false
                ], 400);
            }

            $date = $request->date ?? now()->toDateString();

            $data = WarehouseTariff::where([
                'warehouse_id' => $request->warehouse_id,
            ])
            ->where('is_active', true)
            ->where('effective_date', '<=', $date)
            ->orderBy('effective_date', 'desc')
            ->first();

            if (!$data) {
                return response()->json([
                    'message' => 'Tarif tidak ditemukan',
                    'success' => false
                ], 404);
            }

            return response()->json([
                'data' => $data,
                'message' => $this->messageSuccess,
                'success' => true
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $this->messageFail,
                'errMsg' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }
}
