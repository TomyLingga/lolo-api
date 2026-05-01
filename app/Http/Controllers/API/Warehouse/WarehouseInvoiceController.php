<?php

namespace App\Http\Controllers\API\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use App\Models\WarehouseBeritaAcara;
use App\Models\WarehouseInvoice;
use App\Models\WarehouseInvoiceBa;
use App\Models\WarehouseInvoiceTax;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WarehouseInvoiceController extends Controller
{
    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageSuccess = 'Berhasil mengambil data';
    private string $messageCreate  = 'Invoice berhasil dibuat';
    private string $messageUpdate  = 'Invoice berhasil diperbarui';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getListWith(): array
    {
        return [
            'freightForwarder:id,name,contact_person,contact_number',
            'warehouse:id,name,code',
            'generatedBy:id,name',
        ];
    }

    private function getWith(): array
    {
        return [
            'freightForwarder:id,name,address,contact_person,contact_number',
            'warehouse:id,name,code',
            'generatedBy:id,name',
            'taxes',
            'invoiceBas.beritaAcara.baRegistrations',
            'invoiceBas.beritaAcara.additionalFees',
        ];
    }

    private function generateInvoiceNumber(): string
    {
        $roman = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
        $month = $roman[now()->month - 1];
        $year  = now()->year;

        $count = WarehouseInvoice::whereYear('invoice_date', $year)
            ->count() + 1;

        return sprintf('SMNT/inv/%03d/%s/%d', $count, $month, $year);
    }

    /**
     * Hitung subtotal + pajak.
     * Tax model harus punya: percentage (decimal), type (ADD|DEDUCT).
     */
    private function calculateTotals(float $subtotal, array $taxIds): array
    {
        $taxes       = Tax::whereIn('id', $taxIds)->where('is_active', true)->get();
        $totalAdd    = 0.0;
        $totalDeduct = 0.0;
        $taxDetails  = [];

        foreach ($taxes as $tax) {
            $value     = (float) $tax->value;
            $valueType = strtoupper(trim($tax->value_type));
            $type      = strtoupper(trim($tax->type));

            // Jika PERCENTAGE → hitung dari subtotal, jika NOMINAL → pakai langsung
            $amount = $valueType === 'NOMINAL'
                ? round($value, 2)
                : round($subtotal * ($value / 100), 2);

            if ($type === 'ADD') {
                $totalAdd += $amount;
            } elseif ($type === 'DEDUCT') {
                $totalDeduct += $amount;
            }

            $taxDetails[] = [
                'id'                => $tax->id,
                'name'              => $tax->name,
                'type'              => $type,
                'value_type'        => $valueType,
                'percentage'        => $value,
                'calculated_amount' => $amount,
            ];
        }

        return [
            'subtotal'    => $subtotal,
            'additions'   => $totalAdd,
            'deductions'  => $totalDeduct,
            'grand_total' => round($subtotal + $totalAdd - $totalDeduct, 2),
            'tax_details' => $taxDetails,
        ];
    }

    private function buildQuery(Request $request)
    {
        $query = WarehouseInvoice::with($this->getListWith())
            ->orderBy('invoice_date', 'desc');

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->status));
        }
        if ($request->filled('freight_forwarder_id')) {
            $query->where('freight_forwarder_id', $request->freight_forwarder_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('invoice_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('invoice_date', '<=', $request->date_to);
        }

        return $query;
    }

    // ─── Index & Filters ─────────────────────────────────────────────────────

    /**
     * GET /warehouse-invoices
     */
    public function index(Request $request)
    {
        try {
            $data = $this->buildQuery($request)->get();

            return response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /warehouse-invoices/{id}
     */
    public function show($id)
    {
        try {
            $data = WarehouseInvoice::with($this->getWith())->find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            return response()->json(['data' => $data, 'message' => $this->messageSuccess], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /freight-forwarders/{ffId}/warehouse-berita-acaras/invoiceable
     * BA yang belum diinvoice untuk FF tertentu.
     */
    public function getInvoiceableBas($ffId)
    {
        try {
            $bas = WarehouseBeritaAcara::with([
                    'warehouse:id,name,code',
                    'baRegistrations',
                    'additionalFees',
                ])
                ->where('freight_forwarder_id', $ffId)
                ->where('invoiced', false)
                ->where('is_active', true)
                ->orderBy('ba_date', 'asc')
                ->get()
                ->map(function ($ba) {
                    $ba->calculated_subtotal = $ba->calculateSubtotal();
                    return $ba;
                });

            return $bas->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $bas, 'message' => $this->messageAll], 200);
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    // ─── CRUD ────────────────────────────────────────────────────────────────

    /**
     * POST /warehouse-invoices
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
    
        try {
            $validator = Validator::make($request->all(), [
                'freight_forwarder_id' => 'required|exists:freight_forwarders,id',
                'warehouse_id'         => 'required|exists:warehouses,id',
                'ba_ids'               => 'required|array|min:1',
                'ba_ids.*'             => 'integer|exists:warehouse_berita_acaras,id',
                'spk_name'             => 'required|string|max:255',
                'spk_number'           => 'required|string|max:100',
                'spk_date'             => 'required|date',
                'po_number'            => 'nullable|string|max:100',
                'invoice_date'         => 'required|date',
                'bank_name'            => 'required|string|max:255',
                'swift_code'           => 'required|string|max:50',
                'bank_account_name'    => 'required|string|max:255',
                'bank_account_number'  => 'required|string|max:50',
                'signatory_name'       => 'required|string|max:255',
                'signatory_position'   => 'required|string|max:255',
                'tax_ids'              => 'nullable|array',
                'tax_ids.*'            => 'integer|exists:taxes,id',
            ]);
    
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }
    
            // Load BA beserta relasinya agar calculateSubtotal() bisa jalan
            $bas = WarehouseBeritaAcara::with(['baRegistrations', 'additionalFees'])
                ->whereIn('id', $request->ba_ids)
                ->get();
    
            if ($bas->count() !== count($request->ba_ids)) {
                return response()->json([
                    'message' => 'Satu atau lebih Berita Acara tidak ditemukan.',
                    'success' => false,
                ], 404);
            }
    
            foreach ($bas as $ba) {
                if ($ba->freight_forwarder_id != $request->freight_forwarder_id) {
                    return response()->json([
                        'message' => "BA #{$ba->ba_number} bukan milik Freight Forwarder yang dipilih.",
                        'success' => false,
                    ], 400);
                }
    
                if ($ba->invoiced) {
                    return response()->json([
                        'message' => "BA #{$ba->ba_number} sudah pernah diinvoice.",
                        'success' => false,
                    ], 400);
                }
    
                if ($ba->warehouse_id != $request->warehouse_id) {
                    return response()->json([
                        'message' => "BA #{$ba->ba_number} bukan dari warehouse yang dipilih.",
                        'success' => false,
                    ], 400);
                }
    
                if (! $ba->is_active) {
                    return response()->json([
                        'message' => "BA #{$ba->ba_number} tidak aktif.",
                        'success' => false,
                    ], 400);
                }
            }
    
            // Hitung subtotal dari semua BA — cast eksplisit ke float
            $subtotal = (float) $bas->sum(fn ($ba) => (float) $ba->calculateSubtotal());
    
            // Hitung pajak
            $taxIds = $request->input('tax_ids', []);
            $totals = $this->calculateTotals($subtotal, $taxIds);
    
            // Buat invoice
            $invoice = WarehouseInvoice::create([
                'freight_forwarder_id' => $request->freight_forwarder_id,
                'warehouse_id'         => $request->warehouse_id,
                'invoice_number'       => $this->generateInvoiceNumber(),
                'spk_name'             => $request->spk_name,
                'spk_number'           => $request->spk_number,
                'spk_date'             => $request->spk_date,
                'po_number'            => $request->po_number,
                'invoice_date'         => $request->invoice_date,
                'due_date'             => Carbon::parse($request->invoice_date)->addDays(30),
                'subtotal'             => $totals['subtotal'],
                'grand_total'          => $totals['grand_total'],
                'bank_name'            => $request->bank_name,
                'swift_code'           => $request->swift_code,
                'bank_account_name'    => $request->bank_account_name,
                'bank_account_number'  => $request->bank_account_number,
                'signatory_name'       => $request->signatory_name,
                'signatory_position'   => $request->signatory_position,
                'status'               => 'DRAFT',
                'is_active'            => true,
                'generated_by'         => $request->user()->id,
            ]);
    
            // Pivot invoice ↔ BA + tandai BA sebagai sudah diinvoice
            foreach ($bas as $ba) {
                WarehouseInvoiceBa::create([
                    'warehouse_invoice_id' => $invoice->id,
                    'ba_id'                => $ba->id,
                    'ba_subtotal'          => (float) $ba->calculateSubtotal(),
                ]);
    
                $ba->update(['invoiced' => true]);
            }
    
            // Simpan snapshot pajak
            foreach ($totals['tax_details'] as $t) {
                WarehouseInvoiceTax::create([
                    'warehouse_invoice_id' => $invoice->id,
                    'tax_id'               => $t['id'],
                    'tax_name'             => $t['name'],
                    'tax_value'            => (float) $t['percentage'],
                    'tax_value_type'       => 'PERCENTAGE',
                    'tax_type'             => $t['type'],
                    'calculated_amount'    => (float) $t['calculated_amount'],
                ]);
            }
    
            DB::commit();
    
            return response()->json([
                'data'    => $invoice->load($this->getWith()),
                'message' => $this->messageCreate,
                'success' => true,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    /**
     * PUT /warehouse-invoices/{id}
     * Admin only — hanya bisa edit selama DRAFT.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $invoice = WarehouseInvoice::find($id);

            if (! $invoice) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($invoice->status === 'PAID') {
                return response()->json([
                    'message' => 'Invoice yang sudah PAID tidak dapat diubah.',
                    'success' => false,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'spk_name'           => 'sometimes|required|string|max:255',
                'spk_number'         => 'sometimes|required|string|max:100',
                'spk_date'           => 'sometimes|required|date',
                'po_number'          => 'nullable|string|max:100',
                'invoice_date'       => 'sometimes|required|date',
                'bank_name'          => 'sometimes|required|string|max:255',
                'swift_code'         => 'sometimes|required|string|max:50',
                'bank_account_name'  => 'sometimes|required|string|max:255',
                'bank_account_number'=> 'sometimes|required|string|max:50',
                'signatory_name'     => 'sometimes|required|string|max:255',
                'signatory_position' => 'sometimes|required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'success' => false,
                ], 400);
            }

            $invoice->update($request->only([
                'spk_name', 'spk_number', 'spk_date', 'po_number',
                'invoice_date',
                'bank_name', 'swift_code', 'bank_account_name', 'bank_account_number',
                'signatory_name', 'signatory_position',
            ]));

            DB::commit();

            return response()->json([
                'data'    => $invoice->fresh()->load($this->getWith()),
                'message' => $this->messageUpdate,
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    /**
     * GET /warehouse-invoices/{id}/pay
     */
    public function pay($id)
    {
        DB::beginTransaction();

        try {
            $invoice = WarehouseInvoice::find($id);

            if (! $invoice) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($invoice->status === 'PAID') {
                return response()->json([
                    'message' => 'Invoice sudah berstatus PAID.',
                    'success' => false,
                ], 400);
            }

            $invoice->update([
                'status'  => 'PAID',
                'paid_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Invoice berhasil ditandai PAID.',
                'success' => true,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->serverError($e);
        }
    }

    /**
     * DELETE /warehouse-invoices/{id}
     * Admin only — nonaktifkan invoice dan kembalikan BA ke invoiced=false.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $invoice = WarehouseInvoice::with('invoiceBas')->find($id);

            if (! $invoice) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            if ($invoice->status === 'PAID') {
                return response()->json([
                    'message' => 'Invoice yang sudah PAID tidak dapat dibatalkan.',
                    'success' => false,
                ], 400);
            }

            // Kembalikan BA ke belum diinvoice
            $baIds = $invoice->invoiceBas->pluck('ba_id');
            WarehouseBeritaAcara::whereIn('id', $baIds)
                ->update(['invoiced' => false]);

            $invoice->update(['is_active' => false]);

            DB::commit();

            return response()->json([
                'message' => 'Invoice dibatalkan dan Berita Acara dikembalikan.',
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
            $invoice = WarehouseInvoice::with($this->getWith())->find($id);

            if (! $invoice) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            $headerPath = public_path('images/logo-smnt.png');
            $headerImg  = file_exists($headerPath)
                ? 'data:image/png;base64,' . base64_encode(file_get_contents($headerPath))
                : '';

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->buildInvoicePdf($invoice, $headerImg))
                ->setPaper('a4', 'portrait')
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'defaultFont'          => 'Arial',
                    'dpi'                  => 150,
                ]);

            return $pdf->stream('Invoice_' . str_replace('/', '_', $invoice->invoice_number) . '.pdf');
        } catch (\Exception $e) {
            return $this->serverError($e);
        }
    }

    private function buildInvoicePdf(WarehouseInvoice $invoice, string $headerImg): string
    {
        $ff          = $invoice->freightForwarder;
        
        Carbon::setLocale('id'); // Bahasa Indonesia
        
        $invoiceDate     = Carbon::parse($invoice->invoice_date)->translatedFormat('d F Y');
        $invoiceDateTop  = "Sei Mangkei, {$invoiceDate}";
        
        $dueDate     = $invoice->due_date
            ? Carbon::parse($invoice->due_date)->translatedFormat('d F Y')
            : '-';
            
        $spkDate = $invoice->spk_date
            ? Carbon::parse($invoice->spk_date)->translatedFormat('d F Y')
            : '-';

        // Menentukan Periode (Min Start - Max End) dari seluruh BA
        $minDate = null;
        $maxDate = null;
        foreach ($invoice->invoiceBas as $ib) {
            foreach ($ib->beritaAcara->baRegistrations as $bar) {
                $start = Carbon::parse($bar->rent_start);
                $end   = Carbon::parse($bar->rent_end);
                if (!$minDate || $start < $minDate) $minDate = $start;
                if (!$maxDate || $end > $maxDate) $maxDate = $end;
            }
        }
        
        $periodeStr = '';
        if ($minDate && $maxDate) {
            $startStr = $minDate->translatedFormat('d F');
            $endStr   = $maxDate->translatedFormat('d F Y');
            $periodeStr = "Periode : {$startStr} s/d {$endStr}";
        }

        // Inner Rows detail (Digabung dalam kolom No. 2)
        $itemsHtml = '<table width="100%" cellpadding="3" cellspacing="0" border="0" style="margin-top: 10px; font-size: 23px;">';
        
        foreach ($invoice->invoiceBas as $ib) {
            $ba = $ib->beritaAcara;

            foreach ($ba->baRegistrations as $bar) {
                $start = Carbon::parse($bar->rent_start);
                $end   = Carbon::parse($bar->rent_end);
                $days  = $start->diffInDays($end) + 1;
                
                $tariffFmt = number_format((float) $bar->tariff_per_m2, 0, ',', '.');
                $sub       = number_format((float) $bar->subtotal, 0, ',', '.');

                $itemsHtml .= "<tr>
                    <td style='width: 60%; padding-left: 15px;'>- {$bar->chamber_name} ({$bar->area_m2} m2 x Rp. {$tariffFmt} / hari x {$days} hari)</td>
                    <td style='width: 5%; text-align: center;'>=</td>
                    <td style='width: 5%;'>Rp.</td>
                    <td style='width: 30%; text-align: right;'>{$sub}</td>
                </tr>";
            }

            foreach ($ba->additionalFees as $fee) {
                $amount = number_format((float) $fee->fee_amount, 0, ',', '.');
                $itemsHtml .= "<tr>
                    <td style='padding-left: 15px;'>- {$fee->fee_name}</td>
                    <td style='text-align: center;'>=</td>
                    <td>Rp.</td>
                    <td style='text-align: right;'>{$amount}</td>
                </tr>";
            }
        }

        // Rows pajak (Misal: PPN 11%)
        foreach ($invoice->taxes as $t) {
            $sign       = strtoupper($t->tax_type) === 'ADD' ? '' : '-';
            $amount     = $sign . number_format((float) $t->calculated_amount, 0, ',', '.');
            $itemsHtml .= "<tr>
                <td style='text-align: left; padding-left: 15px;'>- {$t->tax_name} {$t->tax_value}%</td>
                <td style='text-align: center;'>=</td>
                <td>Rp.</td>
                <td style='text-align: right;'>{$amount}</td>
            </tr>";
        }

        $grandTotalFmt = number_format((float) $invoice->grand_total, 0, ',', '.');
        $itemsHtml .= "<tr>
            <td style='text-align: right; font-weight: bold; padding-top: 5px;'>Total Tagihan</td>
            <td style='text-align: center; font-weight: bold; padding-top: 5px;'>=</td>
            <td style='font-weight: bold; padding-top: 5px;'>Rp.</td>
            <td style='text-align: right; font-weight: bold; padding-top: 5px;'>{$grandTotalFmt}</td>
        </tr>";
        
        $itemsHtml .= "</table>";

        $poLine = $invoice->po_number ? "({$invoice->po_number})" : '';

        // Jika Anda memiliki helper terbilang di aplikasi, ganti bagian ini:
        // $terbilang = terbilang($invoice->grand_total);
        $terbilang = ucwords(terbilang((int) $invoice->grand_total)); 

        $headerHtml = $headerImg
            ? "<img src='{$headerImg}' style='height:70px; display:inline-block;' alt='Header'/>"
            : '';

        return "<!DOCTYPE html><html lang='id'><head><meta charset='UTF-8'/>
        <style>
          * { margin: 0; padding: 0; box-sizing: border-box; }
          body { font-family: Arial, sans-serif; font-size: 23px; color: #000; padding: 30px 40px; }
          .header-right { text-align: right; margin-bottom: 25px; }
          .title-area { text-align: center; margin-bottom: 20px; line-height: 1.5; }
          .title-area h2 { margin: 0; font-size: 26px; text-decoration: underline; font-weight: bold; }
          
          table.main-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
          table.main-table th, table.main-table td { border: 1px solid #000; padding: 6px 10px; vertical-align: top; }
          table.main-table th { font-weight: bold; text-align: center; background-color: transparent; color: #000; font-size: 24px;}
          
          .info-bank { margin-top: 15px; line-height: 1.6; }
          .bank-details { margin-left: 30px; margin-top: 5px; width: 80%; }
          .bank-details td { padding: 2px 5px; vertical-align: top; }
          
          .footer-section { width: 100%; margin-top: 40px; border: none; }
          .footer-section td { border: none; padding: 0; }
        </style></head><body>

        <div class='header-right'>
            {$headerHtml}<br/><br/>
            {$invoiceDateTop}
        </div>

        <div class='title-area'>
            <h2>INVOICE</h2>
            No. {$invoice->invoice_number}<br/><br/>
            Kepada Yth :<br/>
            {$ff->name}<br/>
            Kawasan Ekonomi Khusus Sei Mangkei<br/>
            <i>Kab Simalungun, Prov Sumatera Utara Sei Mangkei</i><br/><br/>
            <i>Debit</i> to PT. Sei Mangkei Nusantara Tiga<br/>
            <i>As Per Spesification Below</i>
        </div>

        <table class='main-table'>
            <tr>
                <th style='width: 5%;'>No</th>
                <th style='width: 95%;'>Uraian</th>
            </tr>
            <tr>
                <td style='text-align: center;'>1.</td>
                <td style='text-align: justify;'>
                    Berdasarkan Surat Perjanjian Kerja Sama {$invoice->spk_name} antara PT Sei Mangkei Nusantara Tiga dengan {$ff->name} NOMOR : {$invoice->spk_number} tanggal {$spkDate}.
                </td>
            </tr>
            <tr>
                <td style='text-align: center;'>2.</td>
                <td style='text-align: justify;'>
                    Kegiatan penggunaan Gudang PLB di Dry Port KEK Sei Mangkei {$periodeStr} {$poLine} antara lain sebagai berikut :
                    
                    {$itemsHtml}

                    <div style='margin-top: 15px; font-weight: bold;'>
                        &gt; Terbilang (<i>{$terbilang} Rupiah</i>)
                    </div>
                </td>
            </tr>
        </table>

        <div class='info-bank'>
            Jumlah tersebut diatas dapat dipindahbukukan ke rekening <strong>{$invoice->bank_name}</strong> atas nama <strong>PT. Sei Mangkei Nusantara Tiga</strong>, pada :
            
            <table class='bank-details' style='border: none;'>
                <tr>
                    <td style='width: 20px; font-weight: bold;'>&gt;</td>
                    <td colspan='3' style='font-weight: bold;'>{$invoice->bank_name} :</td>
                </tr>
                <tr>
                    <td></td>
                    <td style='width: 60px;'>A/N</td>
                    <td style='width: 10px;'>:</td>
                    <td>{$invoice->bank_account_number}</td>
                </tr>
                <tr>
                    <td></td>
                    <td>Branch</td>
                    <td>:</td>
                    <td>Kuala Tanjung</td>
                </tr>
            </table>
        </div>

        <p style='margin-top: 20px; font-weight: bold; font-style: italic;'>
            (Jatuh Tempo Pembayaran Maksimal Tanggal {$dueDate})
        </p>

        <table class='footer-section'>
            <tr>
                <td style='width: 60%; vertical-align: bottom; font-size: 21px; line-height: 1.4;'>
                    <strong>Head Office</strong><br/>
                    Gedung Sei Mangkei Dry Port<br/>
                    Kawasan Ekonomi Khusus (KEK) Sei Mangkei<br/>
                    Jl. Kelapa Sawit 1 Sei Mangkei Bosar Maligas Simalungun &ndash; Sumatera Utara<br/>
                     +62622 7296406<br/>
                    <span style='color: blue; text-decoration: underline;'>Info@seimangkeidryport.com</span><br/>
                    <span style='color: blue; text-decoration: underline;'>www.seimangkeidryport.com</span>
                </td>
                <td style='width: 40%; text-align: center; vertical-align: bottom; font-size: 23px;'>
                    PT. Sei Mangkei Nusantara Tiga<br/>
                    Dry Port Sei Mangkei<br/>
                    <br/><br/><br/><br/><br/>
                    <span style='text-decoration: underline;'>{$invoice->signatory_name}</span><br/>
                    {$invoice->signatory_position}
                </td>
            </tr>
        </table>

        </body></html>";
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
