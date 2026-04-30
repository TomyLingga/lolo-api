<?php

namespace App\Http\Controllers\API\Master;

use App\Models\Warehouse;
use Illuminate\Database\QueryException;

class WarehouseController extends BaseMasterController
{
    protected string $model      = Warehouse::class;
    protected array  $searchable = ['name', 'code'];
    protected string $orderBy    = 'name';

    protected array $storeRules = [
        'name'          => 'required|string|max:255',
        'code'          => 'required|string|max:50|unique:warehouses,code',
        'location'      => 'required|string|max:255',
        'total_area_m2' => 'required|numeric|min:0',
        'description'   => 'nullable|string',
        'is_active'     => 'boolean',
    ];

    protected array $updateRules = [
        'name'          => 'sometimes|required|string|max:255',
        'code'          => 'sometim es|required|string|max:50',
        'location'      => 'sometimes|required|string|max:255',
        'total_area_m2' => 'sometimes|required|numeric|min:0',
        'description'   => 'nullable|string',
        'is_active'     => 'boolean',
    ];

    protected function buildUpdateRules(int|string $id): array
    {
        $rules = $this->updateRules;
        $rules['code'] = 'sometimes|required|string|max:50|unique:warehouses,code,' . $id;
        return $rules;
    }

    public function show($id)
    {
        try {
            $data = Warehouse::with('chambers')->find($id);

            if (! $data) {
                return response()->json(['message' => 'Data not found in record'], 404);
            }

            return response()->json(['data' => $data, 'message' => 'Success to Fetch Data'], 200);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }
}
