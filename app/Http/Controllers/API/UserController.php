<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    private $messageFail    = 'Terjadi kesalahan saat memproses data';
    private $messageMissing = 'Data tidak ditemukan';
    private $messageAll     = 'Berhasil mengambil semua data';
    private $messageSuccess = 'Berhasil mengambil data';
    private $messageCreate  = 'Berhasil membuat data';
    private $messageUpdate  = 'Berhasil memperbarui data';
    private $messageDelete  = 'Berhasil menonaktifkan data';

    public function index(Request $request)
    {
        try {
            $query = User::orderBy('name', 'asc');

            if ($request->filled('role')) {
                $query->where('role', $request->role);
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            $data = $query->get(['id', 'name', 'email', 'jabatan', 'bagian', 'role', 'is_active', 'created_at']);

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return response()->json([
                'message' => $this->messageFail,
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $data = User::findOrFail($id);

            return response()->json([
                'data'    => $data->only(['id', 'name', 'email', 'jabatan', 'bagian', 'role', 'is_active', 'created_at']),
                'message' => $this->messageSuccess,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Data tidak ditemukan',
                'success' => false,
            ], 404);

        }  catch (QueryException $e) {
            return response()->json([
                'message' => $this->messageFail,
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
                'jabatan'  => 'nullable|string|max:255',
                'bagian'   => 'nullable|string|max:255',
                'role'     => 'required|in:admin,operator,finance',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors(),
                    'success' => false,
                ], 400);
            }

            $data = User::create([
                'name'      => $request->name,
                'email'     => $request->email,
                'password'  => Hash::make($request->password),
                'jabatan'   => $request->jabatan,
                'bagian'    => $request->bagian,
                'role'      => $request->role,
                'is_active' => true,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $data->only(['id', 'name', 'email', 'jabatan', 'bagian', 'role', 'is_active']),
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

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'name'    => 'sometimes|required|string|max:255',
                'email'   => 'sometimes|required|email|unique:users,email,' . $id,
                'jabatan' => 'nullable|string|max:255',
                'bagian'  => 'nullable|string|max:255',
                'role'    => 'sometimes|required|in:admin,operator,finance',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors(),
                    'success' => false,
                ], 400);
            }

            $data = User::find($id);

            if (! $data) {
                return response()->json([
                    'message' => $this->messageMissing,
                    'success' => false,
                ], 404);
            }

            $data->update([
                'name'    => $request->filled('name')    ? $request->name    : $data->name,
                'email'   => $request->filled('email')   ? $request->email   : $data->email,
                'jabatan' => $request->filled('jabatan') ? $request->jabatan : $data->jabatan,
                'bagian'  => $request->filled('bagian')  ? $request->bagian  : $data->bagian,
                'role'    => $request->filled('role')    ? $request->role    : $data->role,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $data->fresh()->only(['id', 'name', 'email', 'jabatan', 'bagian', 'role', 'is_active']),
                'message' => $this->messageUpdate,
                'success' => true,
            ], 200);
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

    /**
     * Soft-delete: set is_active = false, cabut semua token aktif.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $data = User::find($id);

            if (! $data) {
                return response()->json([
                    'message' => $this->messageMissing,
                    'success' => false,
                ], 404);
            }

            // Cegah user ubah status dirinya sendiri
            if ($data->id === request()->user()->id) {
                return response()->json([
                    'message' => 'Tidak dapat mengubah status akun sendiri.',
                    'success' => false,
                ], 422);
            }

            // 🔥 TOGGLE
            $data->is_active = ! $data->is_active;
            $data->save();

            // Kalau dinonaktifkan → revoke token
            if (! $data->is_active) {
                $data->tokens()->delete();
            }

            DB::commit();

            return response()->json([
                'message' => $data->is_active
                    ? 'User berhasil diaktifkan'
                    : 'User berhasil dinonaktifkan',
                'success' => true,
                'is_active' => $data->is_active
            ], 200);

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

    /**
     * Reset password user oleh admin.
     * POST /admin/users/{id}/reset-password
     */
    public function resetPassword(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors(),
                    'success' => false,
                ], 400);
            }

            $data = User::find($id);

            if (! $data) {
                return response()->json([
                    'message' => $this->messageMissing,
                    'success' => false,
                ], 404);
            }

            $data->update(['password' => Hash::make($request->password)]);

            // Paksa logout semua sesi aktif user ini
            $data->tokens()->delete();

            DB::commit();

            return response()->json([
                'message' => 'Password berhasil direset. Semua sesi aktif user telah dihapus.',
                'success' => true,
            ], 200);
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
}
