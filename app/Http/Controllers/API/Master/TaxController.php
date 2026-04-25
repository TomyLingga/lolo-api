<?php

namespace App\Http\Controllers\API\Master;

use App\Models\Tax;

class TaxController extends BaseMasterController
{
    protected string $model      = Tax::class;
    protected array  $searchable = ['name'];
    protected string $orderBy    = 'id';

    protected array $storeRules = [
        'name'       => 'required|string|max:100|unique:taxes,name',
        'percentage' => 'required|numeric|min:0|max:100',
        'type'       => 'required|in:ADD,DEDUCT',   // ADD=PPN, DEDUCT=PPh
        'is_active'  => 'boolean',
    ];

    protected array $updateRules = [
        'name'       => 'sometimes|required|string|max:100',
        'percentage' => 'sometimes|required|numeric|min:0|max:100',
        'type'       => 'sometimes|required|in:ADD,DEDUCT',
        'is_active'  => 'boolean',
    ];

    protected function buildUpdateRules(int|string $id): array
    {
        $rules = $this->updateRules;
        $rules['name'] = 'sometimes|required|string|max:100|unique:taxes,name,' . $id;
        return $rules;
    }
}
