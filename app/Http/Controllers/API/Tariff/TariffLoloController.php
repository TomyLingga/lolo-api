<?php

namespace App\Http\Controllers\API\Tariff;

use App\Http\Controllers\Controller;
use App\Models\TariffLolo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class TariffLoloController extends Controller
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
            $data = TariffLolo::with(['yard', 'containerSize', 'containerType', 'cargoStatus', 'package'])
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
            $data = TariffLolo::with(['yard', 'containerSize', 'containerType', 'cargoStatus', 'package'])->find($id);

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
                'yard_id' => 'required|exists:yards,id',
                'container_size_id' => 'required|exists:container_sizes,id',
                'container_type_id' => 'required|exists:container_types,id',
                'cargo_status_id' => 'required|exists:cargo_statuses,id',
                'package_id' => 'required|exists:packages,id',
                'price_lift_off' => 'required|numeric',
                'price_lift_on' => 'required|numeric',
                'effective_date' => 'required|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            $exists = TariffLolo::where([
                'yard_id' => $request->yard_id,
                'container_size_id' => $request->container_size_id,
                'container_type_id' => $request->container_type_id,
                'cargo_status_id' => $request->cargo_status_id,
                'package_id' => $request->package_id,
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

            $data = TariffLolo::create($request->all());

            DB::commit();

            return response()->json([
                'data' => $data->load(['yard', 'containerSize', 'containerType', 'cargoStatus', 'package']),
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
            $data = TariffLolo::find($id);

            if (!$data) {
                return response()->json([
                    'message' => $this->messageMissing,
                    'success' => false,
                ], 404);
            }

            $data->update([
                'yard_id' => $request->yard_id ?? $data->yard_id,
                'container_size_id' => $request->container_size_id ?? $data->container_size_id,
                'container_type_id' => $request->container_type_id ?? $data->container_type_id,
                'cargo_status_id' => $request->cargo_status_id ?? $data->cargo_status_id,
                'package_id' => $request->package_id ?? $data->package_id,
                'price_lift_off' => $request->price_lift_off ?? $data->price_lift_off,
                'price_lift_on' => $request->price_lift_on ?? $data->price_lift_on,
                'effective_date' => $request->effective_date ?? $data->effective_date,
            ]);

            DB::commit();

            return response()->json([
                'data' => $data->load(['yard', 'containerSize', 'containerType', 'cargoStatus', 'package']),
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
            $data = TariffLolo::find($id);

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
                'yard_id' => 'required',
                'container_size_id' => 'required',
                'container_type_id' => 'required',
                'cargo_status_id' => 'required',
                'date' => 'nullable|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false
                ], 400);
            }

            $date = $request->date ?? now()->toDateString();

            $data = TariffLolo::where([
                'yard_id' => $request->yard_id,
                'container_size_id' => $request->container_size_id,
                'container_type_id' => $request->container_type_id,
                'cargo_status_id' => $request->cargo_status_id,
                'package_id' => $request->package_id,
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
