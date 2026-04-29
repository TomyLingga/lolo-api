<?php

namespace App\Http\Controllers\API\Master;

use App\Models\Package;


class PackageController extends BaseMasterController
{
    protected string $model      = Package::class;
    protected array  $searchable = ['name', 'code'];
    protected string $orderBy    = 'name';

    protected array $storeRules = [
        'name'           => 'required|string|max:100|unique:packages,name',
        'code'           => 'required|string|max:255',
        'free_time_days' => 'required|integer',
        'is_active'      => 'boolean',
    ];

    protected array $updateRules = [
        'name'           => 'sometimes|required|string|max:100',
        'code'           => 'sometimes|required|string|max:255',
        'free_time_days' => 'sometimes|required|integer',
        'is_active'      => 'sometimes|boolean',
    ];

    protected function buildUpdateRules(int|string $id): array
    {
        $rules         = $this->updateRules;
        $rules['name'] = 'sometimes|required|string|max:100|unique:packages,name,' . $id;
        return $rules;
    }
}
