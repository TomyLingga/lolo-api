<?php

namespace App\Http\Controllers\API\Master;

use App\Models\WarehouseChamber;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WarehouseChamberController extends BaseMasterController
{
    protected string $model   = WarehouseChamber::class;
    protected string $orderBy = 'code';

    protected array $storeRules = [
        'warehouse_id' => 'required|exists:warehouses,id',
        'name'         => 'required|string|max:255',
        'code'         => 'required|string|max:50|unique:warehouse_chambers,code',
        'length_m'     => 'required|numeric|min:0',
        'width_m'      => 'required|numeric|min:0',
        'area_m2'      => 'required|numeric|min:0',
        'is_active'    => 'boolean',
    ];

    protected array $updateRules = [
        'warehouse_id' => 'sometimes|required|exists:warehouses,id',
        'name'         => 'sometimes|required|string|max:255',
        'code'         => 'sometimes|required|string|max:50',
        'length_m'     => 'sometimes|required|numeric|min:0',
        'width_m'      => 'sometimes|required|numeric|min:0',
        'area_m2'      => 'sometimes|required|numeric|min:0',
        'is_active'    => 'boolean',
    ];

    /**
     * Override index: load relasi yard, filter by yard_id opsional dari query param.
     * Filter/search lain ditangani di frontend.
     */
    public function index(Request $request)
    {
        try {
            $query = WarehouseChamber::with('warehouse')->orderBy('code');

            if ($request->filled('warehouse_id')) {
                $query->where('warehouse_id', $request->warehouse_id);
            }

            $data = $query->get();

            return $data->isEmpty()
                ? response()->json(['message' => 'Data not found in record'], 404)
                : response()->json(['data' => $data, 'message' => 'Success to Fetch All Datas'], 200);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    /**
     * Override store: validasi unique code per warehouse_id.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make(
                $request->all(),
                array_merge($this->storeRules, [
                    'code' => [
                        'required', 'string', 'max:10',
                        Rule::unique('warehouse_chambers', 'code')
                            ->where('warehouse_id', $request->warehouse_id),
                    ],
                ]),
                [
                    'code.unique' => 'Code '.$request->code.' sudah ada di warehouse tersebut',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false
                ], 400);
            }

            $data = WarehouseChamber::create($request->all());

            DB::commit();

            return response()->json([
                'data'    => $data->load('warehouse'),
                'message' => 'Success to Create Data',
                'success' => true,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Something went wrong',
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }
}
