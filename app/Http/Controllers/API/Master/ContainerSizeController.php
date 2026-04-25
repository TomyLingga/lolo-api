<?php

namespace App\Http\Controllers\API\Master;

use App\Models\ContainerSize;

class ContainerSizeController extends BaseMasterController
{
    protected string $model      = ContainerSize::class;
    protected array  $searchable = ['code', 'description'];
    protected string $orderBy    = 'code';

    protected array $storeRules = [
        'code'        => 'required|string|max:20|unique:container_sizes,code',
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
        $rules['code'] = 'sometimes|required|string|max:20|unique:container_sizes,code,' . $id;
        return $rules;
    }
}
