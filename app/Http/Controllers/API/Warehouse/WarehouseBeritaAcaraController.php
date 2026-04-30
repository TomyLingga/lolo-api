<?php

namespace App\Http\Controllers\API\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\WarehouseBeritaAcara;
use App\Models\WarehouseBaRegistration;
use App\Models\WarehouseBaAdditionalFee;
use App\Models\WarehouseRegistration;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WarehouseBeritaAcaraController extends Controller
{
    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageSuccess = 'Berhasil mengambil data';
    private string $messageCreate  = 'Berita Acara berhasil dibuat';
    private string $messageUpdate  = 'Berita Acara berhasil diperbarui';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getListWith(): array
    {
        return [
            'freightForwarder:id,name,contact_person,contact_number',
            'warehouse:id,name,code',
            'createdBy:id,name',
        ];
    }

    private function getWith(): array
    {
        return [
            'freightForwarder:id,name,address,contact_person,contact_number',
            'warehouse:id,name,code',
            'createdBy:id,name',
            'baRegistrations',
            'additionalFees',
        ];
    }

    private function generateBaNumber(): string
    {
        $roman = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
        $month = $roman[now()->month - 1];
        $year  = now()->year;

        $count = WarehouseBeritaAcara::whereYear('ba_date', $year)
            ->whereMonth('ba_date', now()->month)
            ->count() + 1;

        return sprintf('BA-GUDANG/SMNT/%02d/%s/%d', $count, $month, $year);
    }

    private function buildQuery(Request $request)
    {
        $query = WarehouseBeritaAcara::with($this->getListWith())
            ->orderBy('ba_date', 'desc');

        if ($request->filled('freight_forwarder_id')) {
            $query->where('freight_forwarder_id', $request->freight_forwarder_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->has('invoiced')) {
            $query->where('invoiced', filter_var($request->invoiced, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('ba_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('ba_date', '<=', $request->date_to);
        }

        return $query;
    }

    // ─── Index & Filters ─────────────────────────────────────────────────────

    /**
     * GET /warehouse-berita-acaras
     */
    public function index(Request $request)
    {
        try {
            $data = $this->buildQuery($request)->get();

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /warehouse-berita-acaras/{id}
     */
    public function show($id)
    {
        try {
            $data = WarehouseBeritaAcara::with($this->getWith())->find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            return response()->json(['data' => $data, 'message' => $this->messageSuccess], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /freight-forwarders/{ffId}/warehouse-registrations/invoiceable-ba
     * Registrasi CLOSED, belum diinvoice, dikelompokkan per warehouse.
     */
    public function getInvoiceableRegistrations($ffId)
    {
        try {
            $registrations = WarehouseRegistration::with([
                    'chamber:id,name,code,length_m,width_m,area_m2,warehouse_id',
                    'chamber.warehouse:id,name,code',
                ])
                ->where('freight_forwarder_id', $ffId)
                ->notInvoiced()
                ->orderBy('rent_start', 'asc')
                ->get();

            // Group by warehouse untuk kemudahan frontend
            $grouped = $registrations
                ->groupBy(fn ($reg) => $reg->chamber->warehouse_id)
                ->map(fn ($regs) => [
                    'warehouse'       => $regs->first()->chamber->warehouse,
                    'registrations'   => $regs->values(),
                    'total_subtotal'  => $regs->sum('subtotal'),
                ])
                ->values();

            return response()->json(['data' => $grouped, 'message' => $this->messageAll], 200);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─── CRUD ────────────────────────────────────────────────────────────────

    /**
     * POST /warehouse-berita-acaras
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'freight_forwarder_id'         => 'required|exists:freight_forwarders,id',
                'warehouse_id'                 => 'required|exists:warehouses,id',
                'registration_ids'             => 'required|array|min:1',
                'registration_ids.*'           => 'integer|exists:warehouse_registrations,id',
                'ba_date'                      => 'required|date',
                'bank_name'                    => 'required|string|max:255',
                'bank_account_name'            => 'required|string|max:255',
                'bank_account_number'          => 'required|string|max:50',
                'signer_smnt_name'             => 'required|string|max:255',
                'signer_smnt_position'         => 'required|string|max:255',
                'signer_ff_name'               => 'required|string|max:255',
                'signer_ff_position'           => 'required|string|max:255',
                'approver_ff_name'             => 'nullable|string|max:255',
                'approver_ff_position'         => 'nullable|string|max:255',
                'additional_fees'              => 'nullable|array',
                'additional_fees.*.fee_name'   => 'required_with:additional_fees|string|max:255',
                'additional_fees.*.fee_amount' => 'required_with:additional_fees|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            // Validasi semua registrasi
            $registrations = WarehouseRegistration::with([
                    'chamber:id,name,code,length_m,width_m,area_m2,warehouse_id',
                ])
                ->whereIn('id', $request->registration_ids)
                ->get();

            if ($registrations->count() !== count($request->registration_ids)) {
                return response()->json([
                    'message' => 'Satu atau lebih registrasi tidak ditemukan.',
                    'success' => false,
                ], 404);
            }

            foreach ($registrations as $reg) {
                if ($reg->freight_forwarder_id != $request->freight_forwarder_id) {
                    return response()->json([
                        'message' => "Registrasi #{$reg->id} bukan milik Freight Forwarder yang dipilih.",
                        'success' => false,
                    ], 400);
                }

                if ($reg->invoiced) {
                    return response()->json([
                        'message' => "Registrasi #{$reg->id} sudah pernah dibuatkan Berita Acara.",
                        'success' => false,
                    ], 400);
                }
                if ($reg->chamber->warehouse_id != $request->warehouse_id) {
                    return response()->json([
                        'message' => "Registrasi #{$reg->id} bukan dari warehouse yang dipilih.",
                        'success' => false,
                    ], 400);
                }
            }

            // Hitung subtotal chamber
            $chamberSubtotal = $registrations->sum('subtotal');

            // Hitung biaya tambahan
            $additionalFeesTotal = collect($request->input('additional_fees', []))
                ->sum('fee_amount');

            $subtotal = $chamberSubtotal + $additionalFeesTotal;

            // Buat BA
            $ba = WarehouseBeritaAcara::create([
                'freight_forwarder_id' => $request->freight_forwarder_id,
                'warehouse_id'         => $request->warehouse_id,
                'ba_number'            => $this->generateBaNumber(),
                'ba_date'              => $request->ba_date,
                'subtotal'             => $subtotal,
                'bank_name'            => $request->bank_name,
                'bank_account_name'    => $request->bank_account_name,
                'bank_account_number'  => $request->bank_account_number,
                'signer_smnt_name'     => $request->signer_smnt_name,
                'signer_smnt_position' => $request->signer_smnt_position,
                'signer_ff_name'       => $request->signer_ff_name,
                'signer_ff_position'   => $request->signer_ff_position,
                'approver_ff_name'     => $request->approver_ff_name,
                'approver_ff_position' => $request->approver_ff_position,
                'invoiced'             => false,
                'is_active'            => true,
                'created_by'           => $request->user()->id,
            ]);

            // Pivot BA ↔ registrasi (snapshot data saat ini)
            foreach ($registrations as $reg) {
                $chamber = $reg->chamber;

                WarehouseBaRegistration::create([
                    'ba_id'                     => $ba->id,
                    'warehouse_registration_id' => $reg->id,
                    'chamber_name'              => $chamber->name,
                    'chamber_length_m'          => $chamber->length_m,
                    'chamber_width_m'           => $chamber->width_m,
                    'area_m2'                   => $reg->area_m2,
                    'tariff_per_m2'             => $reg->tariff_per_m2,
                    'rent_start'                => $reg->rent_start,
                    'rent_end'                  => $reg->rent_end,
                    'subtotal'                  => $reg->subtotal,
                ]);

                // Tandai registrasi sudah dibuatkan BA
                $reg->update(['invoiced' => true]);
            }

            // Simpan biaya tambahan
            foreach ($request->input('additional_fees', []) as $fee) {
                WarehouseBaAdditionalFee::create([
                    'ba_id'      => $ba->id,
                    'fee_name'   => $fee['fee_name'],
                    'fee_amount' => $fee['fee_amount'],
                ]);
            }

            DB::commit();

            return response()->json([
                'data'    => $ba->load($this->getWith()),
                'message' => $this->messageCreate,
                'success' => true,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    /**
     * PUT /warehouse-berita-acaras/{id}
     * Hanya update field non-finansial selama belum diinvoice.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $ba = WarehouseBeritaAcara::find($id);

            if (! $ba) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($ba->invoiced) {
                return response()->json([
                    'message' => 'BA yang sudah diinvoice tidak dapat diubah.',
                    'success' => false,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'ba_date'              => 'sometimes|required|date',
                'bank_name'            => 'sometimes|required|string|max:255',
                'bank_account_name'    => 'sometimes|required|string|max:255',
                'bank_account_number'  => 'sometimes|required|string|max:50',
                'signer_smnt_name'     => 'sometimes|required|string|max:255',
                'signer_smnt_position' => 'sometimes|required|string|max:255',
                'signer_ff_name'       => 'sometimes|required|string|max:255',
                'signer_ff_position'   => 'sometimes|required|string|max:255',
                'approver_ff_name'     => 'nullable|string|max:255',
                'approver_ff_position' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            $ba->update($request->only([
                'ba_date',
                'bank_name', 'bank_account_name', 'bank_account_number',
                'signer_smnt_name', 'signer_smnt_position',
                'signer_ff_name', 'signer_ff_position',
                'approver_ff_name', 'approver_ff_position',
            ]));

            DB::commit();

            return response()->json([
                'data'    => $ba->fresh()->load($this->getWith()),
                'message' => $this->messageUpdate,
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    /**
     * DELETE /warehouse-berita-acaras/{id}
     * Admin only — nonaktifkan BA dan kembalikan registrasi ke invoiced=false.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $ba = WarehouseBeritaAcara::with('baRegistrations')->find($id);

            if (! $ba) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($ba->invoiced) {
                return response()->json([
                    'message' => 'BA yang sudah diinvoice tidak dapat dibatalkan.',
                    'success' => false,
                ], 400);
            }

            // Kembalikan registrasi ke belum di-BA
            $regIds = $ba->baRegistrations->pluck('warehouse_registration_id');
            WarehouseRegistration::whereIn('id', $regIds)
                ->update(['invoiced' => false]);

            $ba->update(['is_active' => false]);

            DB::commit();

            return response()->json([
                'message' => 'BA dinonaktifkan dan registrasi dikembalikan.',
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    // ─── Additional Fees ─────────────────────────────────────────────────────

    /**
     * POST /warehouse-berita-acaras/{baId}/additional-fees
     */
    public function storeAdditionalFee(Request $request, $baId)
    {
        DB::beginTransaction();

        try {
            $ba = WarehouseBeritaAcara::with(['baRegistrations', 'additionalFees'])->find($baId);

            if (! $ba) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($ba->invoiced) {
                return response()->json([
                    'message' => 'BA yang sudah diinvoice tidak dapat diubah.',
                    'success' => false,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'fee_name'   => 'required|string|max:255',
                'fee_amount' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            $fee = WarehouseBaAdditionalFee::create([
                'ba_id'      => $baId,
                'fee_name'   => $request->fee_name,
                'fee_amount' => $request->fee_amount,
            ]);

            // Recalculate & update subtotal BA
            $ba->refresh();
            $ba->update(['subtotal' => $ba->calculateSubtotal()]);

            DB::commit();

            return response()->json([
                'data'    => $fee,
                'message' => 'Biaya tambahan berhasil ditambahkan.',
                'success' => true,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    /**
     * DELETE /warehouse-berita-acaras/{baId}/additional-fees/{feeId}
     */
    public function destroyAdditionalFee($baId, $feeId)
    {
        DB::beginTransaction();

        try {
            $ba  = WarehouseBeritaAcara::with(['baRegistrations', 'additionalFees'])->find($baId);
            $fee = WarehouseBaAdditionalFee::where('ba_id', $baId)->find($feeId);

            if (! $ba || ! $fee) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($ba->invoiced) {
                return response()->json([
                    'message' => 'BA yang sudah diinvoice tidak dapat diubah.',
                    'success' => false,
                ], 400);
            }

            $fee->delete();

            // Recalculate & update subtotal BA
            $ba->refresh();
            $ba->update(['subtotal' => $ba->calculateSubtotal()]);

            DB::commit();

            return response()->json([
                'message' => 'Biaya tambahan berhasil dihapus.',
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    // ─── PDF Export ───────────────────────────────────────────────────────────

    public function exportPdf($id)
    {
        try {
            $ba = WarehouseBeritaAcara::with([
                'freightForwarder',
                'warehouse',
                'baRegistrations',
                'additionalFees',
            ])->find($id);

            if (! $ba) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->buildBaPdf($ba))
                ->setPaper('a4', 'portrait')
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'defaultFont'          => 'Arial',
                    'dpi'                  => 150,
                ]);

            return $pdf->stream('BA_' . str_replace('/', '_', $ba->ba_number) . '.pdf');
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    private function buildBaPdf(WarehouseBeritaAcara $ba): string
    {
        $ff        = $ba->freightForwarder;
        $ffName    = strtoupper($ff->name);

        Carbon::setLocale('id'); // WAJIB untuk Bahasa Indonesia

        $baDate    = Carbon::parse($ba->ba_date);
        $baDateStr = $baDate->translatedFormat('l, d F Y'); 
        $bulanStr  = strtoupper($baDate->translatedFormat('F'));
        $tahunStr  = $baDate->format('Y');

        // Header image (Logo Kiri Saja)
        $headerPath = public_path('images/logo-smnt.png');
        $headerB64  = base64_encode(file_get_contents($headerPath));
        $headerImg  = 'data:image/png;base64,' . $headerB64;

        // Rows chamber
        $chamberRows     = '';
        $chamberSubtotal = 0;

        foreach ($ba->baRegistrations as $bar) {
            $dim     = "{$bar->chamber_length_m} M &times; {$bar->chamber_width_m} M";
            $start   = Carbon::parse($bar->rent_start)->translatedFormat('d F Y');
            $end     = Carbon::parse($bar->rent_end)->translatedFormat('d F Y');
            $tariff  = number_format($bar->tariff_per_m2, 0, ',', '.');
            $sub     = number_format($bar->subtotal, 0, ',', '.');
            $chamberSubtotal += (float) $bar->subtotal;

            $chamberRows .= "
            <li>
                Pembayaran atas penggunaan {$bar->chamber_name} {$dim} = {$bar->area_m2} M&sup2; 
                di Dry Port KEK Sei Mangkei, yang dilakukan pada tanggal {$start} &ndash; {$end} 
                Sebesar {$bar->area_m2} M&sup2; X Rp. {$tariff},- = Rp. {$sub},-
            </li>";
        }

        // Fees
        $feeRows      = '';
        $feesSubtotal = 0;

        foreach ($ba->additionalFees as $fee) {
            $amount = number_format($fee->fee_amount, 0, ',', '.');
            $feesSubtotal += (float) $fee->fee_amount;

            $feeRows .= "
            <li>
                {$fee->fee_name} sebesar Rp. {$amount},-
            </li>";
        }

        $subtotal    = $chamberSubtotal + $feesSubtotal;
        $subtotalFmt = number_format($subtotal, 0, ',', '.');
        $tariffPerM2 = number_format($ba->baRegistrations->first()->tariff_per_m2 ?? 0, 0, ',', '.');

        // Memisahkan baris approver untuk ditambahkan di tabel bawah agar sejajar
        $approverRows = $ba->approver_ff_name
            ? "
            <br><br>
            <tr>
                <td></td>
                <td style='padding-top: 40px; padding-bottom: 100px;'>Diketahui Oleh,</td>
            </tr>
            <br><br>
            <tr>
                <td></td>
                <td>
                    <strong><u>{$ba->approver_ff_name}</u></strong><br/>
                    {$ba->approver_ff_position}
                </td>
            </tr>"
            : '';

        return "<!DOCTYPE html>
    <html lang='id'>
    <head>
    <meta charset='UTF-8'/>
    <style>
    body { 
        font-family: 'Times New Roman', Times, serif; 
        font-size: 26px; /* Sesuai instruksi font sangat besar */
        margin: 0; 
        padding: 30px; 
        color: #000;
    }
    h3 { 
        text-align: center; 
        font-size: 28px; /* Sesuai instruksi font sangat besar */
        margin: 2px 0; 
        font-weight: bold;
    }
    p, li { 
        line-height: 1.5; 
        text-align: justify; 
        margin: 5px 0; 
    }
    .header {
        display: flex;
        align-items: center;
        margin-bottom: 20px;
    }
    .header img {
        width: 120px; 
    }
    
    /* Styling List Utama (1-6) */
    ol.main-list {
        padding-left: 45px;
        margin-top: 15px;
        margin-bottom: 15px;
    }
    ol.main-list > li {
        padding-left: 10px;
        margin-bottom: 15px;
    }

    /* Styling Sub List (a-d) */
    ol.sub-list {
        padding-left: 30px;
        margin-top: 10px;
    }
    ol.sub-list > li {
        padding-left: 10px;
        margin-bottom: 10px;
    }

    /* Tabel Rekening Bank */
    .bank-table {
        width: 85%; 
        margin-left: 55px; 
        border-collapse: collapse; 
        margin-top: 10px;
        margin-bottom: 30px;
    }
    .bank-table td { 
        padding: 5px 0; 
        vertical-align: top; 
    }
    .col-label { width: 180px; } /* Diperlebar karena font besar */
    .col-colon { width: 25px; text-align: center; }

    /* Tabel Tanda Tangan */
    .sign-table {
        width: 100%;
        margin-top: 40px;
        text-align: center;
        border-collapse: collapse;
    }
    .sign-table td {
        width: 50%;
        vertical-align: top;
    }
    .sign-table .company-name {
        padding-bottom: 120px; /* Ruang untuk tanda tangan pertama */
    }
    </style>
    </head>
    <body>

    <div class='header'>
        <img src='{$headerImg}' />
    </div>

    <h3>BERITA ACARA</h3>
    <h3>PEMBAYARAN BULAN {$bulanStr}</h3>
    <h3>ATAS PERJANJIAN KERJA SAMA</h3>
    <h3>PENGGUNAAN GUDANG PLB DI DRYPORT</h3>
    <h3>ANTARA</h3>
    <h3>PT SEI MANGKEI NUSANTARA TIGA</h3>
    <h3>DENGAN</h3>
    <h3>{$ffName}</h3>

    <br/>
    <p style='text-align:center;'><strong>NOMOR : {$ba->ba_number}</strong></p>
    <br/>

    <p>Pada hari ini, {$baDateStr} telah disepakati Berita Acara Pembayaran Atas Perjanjian Kerja Sama Penggunaan gudang PLB di Dry Port KEK Sei Mangkei Antara PT Sei Mangkei Nusantara Tiga dengan {$ffName} yang menerangkan sebagai berikut:</p>

    <ol class='main-list'>
        <li>Perjanjian Kerja Sama ini adalah sebagai pedoman dalam penggunaan gudang PLB di Dryport KEK Sei Mangkei.</li>
        <li>Perjanjian Kerja Sama ini adalah untuk menciptakan sinergi usaha dengan prinsip yang saling menguntungkan dengan memanfaatkan potensi yang dimiliki masing-masing Pihak serta mendukung keberadaan Kawasan Industri di Kawasan Ekonomi Khusus (KEK) Sei Mangkei.</li>
        <li>Atas pelaksanaan Perjanjian ini, {$ffName} memberikan kompensasi penggunaan gudang PLB di Dry port sebesar Rp {$tariffPerM2},-/M&sup2; (tidak termasuk PPN) kepada PT Sei Mangkei Nusantara Tiga.</li>
        <li>Berita acara penggunaan gudang ini meliputi berbagai jenis kegiatan yaitu sebagai berikut:
            <ol class='sub-list' style='list-style-type: lower-alpha;'>
                {$chamberRows}
                {$feeRows}
                <li>Total sebesar Rp. {$subtotalFmt},- (tidak termasuk PPN)</li>
            </ol>
        </li>
        <li>Perhitungan Pembayaran Berita acara penggunaan gudang Bulan {$bulanStr} {$tahunStr}.</li>
        <li>Pembayaran dilaksanakan oleh {$ffName} selambat-lambatnya 30 (tiga puluh) hari kerja terhitung sejak Invoice diterima, dan akan ditransfer ke rekening sebagai berikut:</li>
    </ol>

    <table class='bank-table'>
        <tr>
            <td class='col-label'>Nama</td>
            <td class='col-colon'>:</td>
            <td>PT Sei Mangkei Nusantara Tiga</td>
        </tr>
        <tr>
            <td class='col-label'>Bank</td>
            <td class='col-colon'>:</td>
            <td>{$ba->bank_name}</td>
        </tr>
        <tr>
            <td class='col-label'>No. Rekening</td>
            <td class='col-colon'>:</td>
            <td>{$ba->bank_account_number}</td>
        </tr>
    </table>

    <p>Demikian Berita Acara Rekonsiliasi ini dibuat dan ditandatangani dalam rangkap 2 (dua).</p>

    <table class='sign-table'>
        <tr>
            <td class='company-name'><strong>PT SEI MANGKEI NUSANTARA TIGA</strong></td>
            <td class='company-name'><strong>{$ffName}</strong></td>
        </tr>
        <br>
        <br>
        <br>
        <br>
        <br>
        <tr>
            <td>
                <strong><u>{$ba->signer_smnt_name}</u></strong><br/>
                {$ba->signer_smnt_position}
            </td>
            <td>
                <strong><u>{$ba->signer_ff_name}</u></strong><br/>
                {$ba->signer_ff_position}
            </td>
        </tr>
        {$approverRows}
    </table>

    </body>
    </html>";
    }

    // ─── Error Helpers ────────────────────────────────────────────────────────

    private function queryError(QueryException $e)
    {
        return response()->json([
            'message' => $this->messageFail,
            'err'     => $e->getTrace()[0],
            'errMsg'  => $e->getMessage(),
            'success' => false,
        ], 500);
    }

    private function serverError(\Exception $e)
    {
        return response()->json([
            'message' => $this->messageFail,
            'err'     => $e->getTrace()[0],
            'errMsg'  => $e->getMessage(),
            'success' => false,
        ], 500);
    }
}
