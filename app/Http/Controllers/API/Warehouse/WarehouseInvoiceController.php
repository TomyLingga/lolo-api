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
            ->whereMonth('invoice_date', now()->month)
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
        $totalAdd    = 0;
        $totalDeduct = 0;
        $taxDetails  = [];

        foreach ($taxes as $tax) {
            $amount = round($subtotal * ((float) $tax->percentage / 100), 2);

            if (strtoupper($tax->type) === 'ADD') {
                $totalAdd += $amount;
            } else {
                $totalDeduct += $amount;
            }

            $taxDetails[] = [
                'id'                => $tax->id,
                'name'              => $tax->name,
                'type'              => strtoupper($tax->type),
                'percentage'        => (float) $tax->percentage,
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

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
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

            // Validasi semua BA
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

            // Hitung subtotal dari semua BA
            $subtotal = $bas->sum(fn ($ba) => $ba->calculateSubtotal());

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

            // Pivot invoice ↔ BA + tandai BA
            foreach ($bas as $ba) {
                WarehouseInvoiceBa::create([
                    'warehouse_invoice_id' => $invoice->id,
                    'ba_id'                => $ba->id,
                    'ba_subtotal'          => $ba->calculateSubtotal(),
                ]);

                $ba->update(['invoiced' => true]);
            }

            // Simpan snapshot pajak
            foreach ($totals['tax_details'] as $t) {
                WarehouseInvoiceTax::create([
                    'warehouse_invoice_id' => $invoice->id,
                    'tax_id'               => $t['id'],
                    'tax_name'             => $t['name'],
                    'tax_value'            => $t['percentage'],
                    'tax_value_type'       => 'PERCENTAGE',
                    'tax_type'             => $t['type'],
                    'calculated_amount'    => $t['calculated_amount'],
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
            $invoice = WarehouseInvoice::with([
                'freightForwarder',
                'warehouse',
                'taxes',
                'invoiceBas.beritaAcara.baRegistrations',
                'invoiceBas.beritaAcara.additionalFees',
            ])->find($id);

            if (! $invoice) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            $headerPath = public_path('images/header-invoice.png');
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
        $invoiceDate = Carbon::parse($invoice->invoice_date)->format('d/m/Y');
        $dueDate     = $invoice->due_date
            ? Carbon::parse($invoice->due_date)->translatedFormat('d F Y')
            : '-';
        $spkDate = $invoice->spk_date
            ? Carbon::parse($invoice->spk_date)->translatedFormat('d F Y')
            : '-';

        // Rows detail
        $rowsHtml = '';
        $i        = 0;

        foreach ($invoice->invoiceBas as $ib) {
            $ba = $ib->beritaAcara;

            foreach ($ba->baRegistrations as $bar) {
                $bg     = ($i % 2 === 0) ? '#ffffff' : '#f2f2f2';
                $start  = Carbon::parse($bar->rent_start)->format('d/m/Y');
                $end    = Carbon::parse($bar->rent_end)->format('d/m/Y');
                $tariff = number_format($bar->tariff_per_m2, 0, ',', '.');
                $sub    = number_format($bar->subtotal, 0, ',', '.');

                $rowsHtml .= "<tr style='background:{$bg}'>
                    <td colspan='4' style='border:0.5px solid #bbb;padding:5px 8px;'>
                        Gudang {$bar->chamber_name} {$bar->area_m2} M2 × Rp.{$tariff},-
                        <br/><small style='color:#666;'>Periode: {$start} s/d {$end}</small>
                    </td>
                    <td style='text-align:right;border:0.5px solid #bbb;padding:5px 8px;'>= Rp.{$sub}</td>
                </tr>";
                $i++;
            }

            foreach ($ba->additionalFees as $fee) {
                $bg     = ($i % 2 === 0) ? '#ffffff' : '#f2f2f2';
                $amount = number_format($fee->fee_amount, 0, ',', '.');
                $rowsHtml .= "<tr style='background:{$bg}'>
                    <td colspan='4' style='border:0.5px solid #bbb;padding:5px 8px;'>{$fee->fee_name}</td>
                    <td style='text-align:right;border:0.5px solid #bbb;padding:5px 8px;'>= Rp.{$amount}</td>
                </tr>";
                $i++;
            }
        }

        // Rows pajak
        $taxHtml = '';
        foreach ($invoice->taxes as $t) {
            $sign       = strtoupper($t->tax_type) === 'ADD' ? '' : '-';
            $valueLabel = "{$t->tax_name} ({$t->tax_value}%)";
            $amount     = $sign . 'Rp.' . number_format($t->calculated_amount, 0, ',', '.');
            $taxHtml   .= "<tr>
                <td colspan='4' style='text-align:right;padding:4px 8px;'>{$valueLabel}</td>
                <td style='text-align:right;padding:4px 8px;'>{$amount}</td>
            </tr>";
        }

        $subtotalFmt   = 'Rp.' . number_format($invoice->subtotal, 0, ',', '.');
        $grandTotalFmt = 'Rp.' . number_format($invoice->grand_total, 0, ',', '.');
        $poLine        = $invoice->po_number ? " ({$invoice->po_number})" : '';
        $headerHtml    = $headerImg
            ? "<img src='{$headerImg}' style='width:100%;display:block;' alt='Header'/>"
            : '';

        return "<!DOCTYPE html><html lang='id'><head><meta charset='UTF-8'/>
        <style>
          * { margin:0; padding:0; box-sizing:border-box; }
          body { font-family:Arial,sans-serif; font-size:11px; color:#000; padding:22px 30px; }
          table { width:100%; border-collapse:collapse; }
          th { background:#2c5f9e; color:#fff; text-align:center; padding:8px 6px; font-size:13px; }
          td { font-size:12px; vertical-align:middle; padding:5px; }
          .total-row td { font-weight:bold; font-size:14px; border-top:2px solid #000; }
        </style></head><body>

        <table style='margin-bottom:20px;border:none;'>
          <tr><td style='border:none;text-align:center;padding:0;'>{$headerHtml}</td></tr>
        </table>

        <table style='margin-bottom:18px;'>
          <tr>
            <td style='border:none;font-size:12px;'>
              No : {$invoice->invoice_number}<br/>Tanggal : {$invoiceDate}
            </td>
            <td style='border:none;text-align:right;font-size:12px;'>
              Kepada Yth :<br/><strong>{$ff->name}</strong><br/>KEK Sei Mangkei
            </td>
          </tr>
        </table>

        <p style='margin-bottom:4px;font-size:11px;'>Debit to PT. Sei Mangkei Nusantara Tiga</p>
        <p style='margin-bottom:12px;font-size:10px;color:#555;'>
          (Jatuh Tempo Pembayaran Maksimal Tanggal {$dueDate})
        </p>

        <table style='margin-bottom:12px;'>
          <tr>
            <td style='border:0.5px solid #bbb;padding:5px 8px;width:30px;'>1.</td>
            <td colspan='4' style='border:0.5px solid #bbb;padding:5px 8px;'>
              Berdasarkan Surat Perjanjian {$invoice->spk_name} antara PT Sei Mangkei Nusantara Tiga
              dengan {$ff->name} NOMOR : {$invoice->spk_number} tanggal {$spkDate}.
            </td>
          </tr>
          <tr>
            <td style='border:0.5px solid #bbb;padding:5px 8px;'>2.</td>
            <td colspan='4' style='border:0.5px solid #bbb;padding:5px 8px;'>
              Kegiatan penggunaan Gudang PLB di Dry Port KEK Sei Mangkei{$poLine}:
            </td>
          </tr>
          {$rowsHtml}
          <tr>
            <td colspan='4' style='text-align:right;padding:4px 8px;font-style:italic;'>SUB TOTAL</td>
            <td style='text-align:right;padding:4px 8px;'>{$subtotalFmt}</td>
          </tr>
          {$taxHtml}
          <tr class='total-row'>
            <td colspan='4' style='text-align:right;padding:6px 8px;'>Total Tagihan</td>
            <td style='text-align:right;padding:6px 8px;'>{$grandTotalFmt}</td>
          </tr>
        </table>

        <table style='margin-top:40px;border:none;'>
          <tr>
            <td style='width:60%;vertical-align:top;border:none;font-size:12px;'>
              <strong>Metode Pembayaran</strong><br/>
              {$invoice->bank_name}<br/>
              SWIFT/BIC: {$invoice->swift_code}<br/>
              No. Rekening: {$invoice->bank_account_number}<br/>
              A/N: {$invoice->bank_account_name}<br/><br/>
              <strong>PT SEI MANGKEI NUSANTARA TIGA</strong><br/>
              Jl Kelapa Sawit I No. 1 KEK Sei Mangkei, Kec. Bosar Maligas<br/>
              Kab. Simalungun Sumatera Utara, Indonesia 21183<br/>
              Telp: +62 622 7296406
            </td>
            <td style='width:40%;text-align:center;vertical-align:bottom;border:none;'>
              <div style='margin-top:65px;'>
                <div style='padding-top:7px;font-weight:bold;border-top:2px solid #000;
                            font-size:14px;text-decoration:underline;display:inline-block;padding:0 20px;'>
                  {$invoice->signatory_name}
                </div>
                <div style='font-size:13px;margin-top:4px;'>{$invoice->signatory_position}</div>
              </div>
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
