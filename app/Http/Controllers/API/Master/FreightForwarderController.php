<?php

namespace App\Http\Controllers\API\Master;

use App\Models\FreightForwarders;

class FreightForwarderController extends BaseMasterController
{
    protected string $model      = FreightForwarders::class;
    protected array  $searchable = ['name', 'contact_person', 'email'];
    protected string $orderBy    = 'name';

    protected array $storeRules = [
        'name'            => 'required|string|max:255',
        'email'           => 'nullable|email|max:255|unique:freight_forwarders,email',
        'address'         => 'nullable|string',
        'contact_person'  => 'nullable|string|max:255',
        'contact_number'  => 'nullable|string|max:30',
        'is_active'       => 'boolean',
    ];

    protected array $updateRules = [
        'name'           => 'sometimes|required|string|max:255',
        'email'          => 'nullable|email|max:255',
        'address'        => 'nullable|string',
        'contact_person' => 'nullable|string|max:255',
        'contact_number' => 'nullable|string|max:30',
        'is_active'      => 'boolean',
    ];

    protected function buildUpdateRules(int|string $id): array
    {
        $rules = $this->updateRules;
        $rules['email'] = 'nullable|email|max:255|unique:freight_forwarders,email,' . $id;
        return $rules;
    }
}
