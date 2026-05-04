<?php

namespace App\Http\Controllers\API\Master;

use App\Models\Block;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BlockController extends BaseMasterController
{
    protected string $model   = Block::class;
    protected string $orderBy = 'block_code';

    protected array $storeRules = [
        'yard_id'    => 'required|exists:yards,id',
        'block_code' => 'required|string',
        'max_length' => 'required|integer|min:1',
        'max_width'  => 'required|integer|min:1',
        'max_height' => 'required|integer|min:1',
        'is_active'  => 'boolean',
    ];

    protected array $updateRules = [
        'block_code' => 'sometimes|required|string',
        'max_length' => 'sometimes|required|integer|min:1',
        'max_width'  => 'sometimes|required|integer|min:1',
        'max_height' => 'sometimes|required|integer|min:1',
        'is_active'  => 'boolean',
    ];

    /**
     * Override index: load relasi yard, filter by yard_id opsional dari query param.
     * Filter/search lain ditangani di frontend.
     */
    public function index(Request $request)
    {
        try {
            $query = Block::with('yard')->orderBy('block_code');

            if ($request->filled('yard_id')) {
                $query->where('yard_id', $request->yard_id);
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
     * Override store: validasi unique block_code per yard_id.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make(
                $request->all(),
                array_merge($this->storeRules, [
                    'block_code' => [
                        'required', 'string',
                        Rule::unique('blocks', 'block_code')
                            ->where('yard_id', $request->yard_id),
                    ],
                ]),
                [
                    'block_code.unique' => 'Blok '.$request->block_code.' sudah ada di yard tersebut',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false
                ], 400);
            }

            $data = Block::create($request->all());

            DB::commit();

            return response()->json([
                'data'    => $data->load('yard'),
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
