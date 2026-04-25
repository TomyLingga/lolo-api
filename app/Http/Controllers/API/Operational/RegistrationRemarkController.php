<?php

namespace App\Http\Controllers\API\Operational;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\RegistrationRemark;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RegistrationRemarkController extends Controller
{
    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageCreate  = 'Berhasil membuat data';

    /**
     * GET /registrations/{registrationId}/remarks
     */
    public function index($registrationId)
    {
        try {
            $registration = Registration::find($registrationId);

            if (! $registration) {
                return response()->json(['message' => 'Registrasi tidak ditemukan'], 404);
            }

            $data = RegistrationRemark::with(['createdBy:id,name'])
                ->where('registration_id', $registrationId)
                ->orderBy('created_at', 'asc')
                ->get();

            return $data->isEmpty()
                ? response()->json(['message' => 'Belum ada remark'], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * POST /registrations/{registrationId}/remarks
     * Tambah remark baru pada registrasi.
     */
    public function store(Request $request, $registrationId)
    {
        DB::beginTransaction();

        try {
            $registration = Registration::find($registrationId);

            if (! $registration) {
                return response()->json(['message' => 'Registrasi tidak ditemukan', 'success' => false], 404);
            }

            $validator = Validator::make($request->all(), [
                'remark' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            $remark = RegistrationRemark::create([
                'registration_id' => $registration->id,
                'created_by'      => $request->user()->id,
                'remark'          => $request->remark,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $remark->load(['createdBy:id,name']),
                'message' => $this->messageCreate,
                'success' => true,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => $this->messageFail,
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    private function queryError(QueryException $e)
    {
        return response()->json([
            'message' => $this->messageFail,
            'err'     => $e->getTrace()[0],
            'errMsg'  => $e->getMessage(),
            'success' => false,
        ], 500);
    }
}
