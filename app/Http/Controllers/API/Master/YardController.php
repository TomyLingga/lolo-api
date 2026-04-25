<?php

namespace App\Http\Controllers\API\Master;

use App\Models\Yard;
use Illuminate\Database\QueryException;

class YardController extends BaseMasterController
{
    protected string $model      = Yard::class;
    protected array  $searchable = ['name', 'code'];
    protected string $orderBy    = 'name';

    protected array $storeRules = [
        'name'        => 'required|string|max:255',
        'code'        => 'required|string|max:50|unique:yards,code',
        'description' => 'nullable|string',
        'is_active'   => 'boolean',
    ];

    protected array $updateRules = [
        'name'        => 'sometimes|required|string|max:255',
        'code'        => 'sometimes|required|string|max:50',
        'description' => 'nullable|string',
        'is_active'   => 'boolean',
    ];

    protected function buildUpdateRules(int|string $id): array
    {
        $rules = $this->updateRules;
        $rules['code'] = 'sometimes|required|string|max:50|unique:yards,code,' . $id;
        return $rules;
    }

    public function show($id)
    {
        try {
            $data = Yard::with('blocks')->find($id);

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
