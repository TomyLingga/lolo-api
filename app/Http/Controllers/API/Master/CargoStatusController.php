<?php

namespace App\Http\Controllers\API\Master;

use App\Models\CargoStatus;

class CargoStatusController extends BaseMasterController
{
    protected string $model      = CargoStatus::class;
    protected array  $searchable = ['code', 'description'];
    protected string $orderBy    = 'code';

    protected array $storeRules = [
        'code'        => 'required|string|max:20|unique:cargo_statuses,code',
        'description' => 'nullable|string|max:255',
        'is_active'   => 'boolean',
    ];

    protected array $updateRules = [
        'code'        => 'sometimes|required|string|max:20',
        'description' => 'nullable|string|max:255',
        'is_active'   => 'boolean',
    ];

    protected function buildUpdateRules(int|string $id): array
    {
        $rules = $this->updateRules;
        $rules['code'] = 'sometimes|required|string|max:20|unique:cargo_statuses,code,' . $id;
        return $rules;
    }
}
