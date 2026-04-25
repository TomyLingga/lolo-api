<?php

namespace App\Http\Controllers\API\Master;

use App\Models\ContainerType;

class ContainerTypeController extends BaseMasterController
{
    protected string $model      = ContainerType::class;
    protected array  $searchable = ['code', 'description'];
    protected string $orderBy    = 'code';

    protected array $storeRules = [
        'code'        => 'required|string|max:50|unique:container_types,code',
        'description' => 'nullable|string|max:255',
        'is_active'   => 'boolean',
    ];

    protected array $updateRules = [
        'code'        => 'sometimes|required|string|max:50',
        'description' => 'nullable|string|max:255',
        'is_active'   => 'boolean',
    ];

    protected function buildUpdateRules(int|string $id): array
    {
        $rules = $this->updateRules;
        $rules['code'] = 'sometimes|required|string|max:50|unique:container_types,code,' . $id;
        return $rules;
    }
}
