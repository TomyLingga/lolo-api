<?php

namespace App\Http\Controllers\API\Master;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

abstract class BaseMasterController extends Controller
{
    protected string $model;
    protected array  $storeRules  = [];
    protected array  $updateRules = [];
    protected string $orderBy     = 'name';

    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageSuccess = 'Berhasil mengambil data';
    private string $messageCreate  = 'Berhasil membuat data';
    private string $messageUpdate  = 'Berhasil memperbarui data';

    public function index(Request $request)
    {
        try {
            $data = $this->model::orderBy($this->orderBy, 'asc')->get();

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    public function show($id)
    {
        try {
            $data = $this->model::find($id);

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
            $validator = Validator::make($request->all(), $this->storeRules);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            $data = $this->model::create($request->all());

            DB::commit();

            return response()->json([
                'data'    => $data,
                'message' => $this->messageCreate,
                'success' => true,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), $this->buildUpdateRules($id));

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            $data = $this->model::find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing, 'success' => false], 404);
            }

            $data->update($request->all());

            DB::commit();

            return response()->json([
                'data'    => $data->fresh(),
                'message' => $this->messageUpdate,
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $data = $this->model::find($id);

            if (! $data) {
                return response()->json([
                    'message' => $this->messageMissing,
                    'success' => false
                ], 404);
            }

            // 🔥 TOGGLE
            $data->update([
                'is_active' => ! $data->is_active
            ]);

            DB::commit();

            return response()->json([
                'message' => $data->is_active
                    ? 'Data berhasil diaktifkan'
                    : 'Data berhasil dinonaktifkan',
                'success' => true,
                'is_active' => $data->is_active
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    // Override di child untuk inject $id ke unique rule
    protected function buildUpdateRules(int|string $id): array
    {
        return $this->updateRules;
    }

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
