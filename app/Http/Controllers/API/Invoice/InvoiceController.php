<?php

namespace App\Http\Controllers\API\Invoice;

use App\Http\Controllers\Controller;
use App\Models\FreightForwarders;
use App\Models\Invoice;
use App\Models\InvoiceRegistration;
use App\Models\Registration;
use App\Models\Tax;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    private string $messageFail    = 'Terjadi kesalahan saat memproses permintaan';
    private string $messageMissing = 'Data tidak ditemukan';
    private string $messageAll     = 'Berhasil mengambil semua data';
    private string $messageSuccess = 'Berhasil mengambil data';
    private string $messageCreate  = 'Berhasil membuat data';
    private string $messageUpdate  = 'Berhasil memperbarui data';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getWith(): array
    {
        return [
            'freightForwarder:id,name,address,contact_person,contact_number',
            'generatedBy:id,name',
            'invoiceRegistrations.registration.loloRecords.cargoStatus',
            'invoiceRegistrations.registration.storageRecords.cargoStatus',
            'invoiceRegistrations.registration.size',
            'invoiceRegistrations.registration.type',
        ];
    }

    /**
     * Hitung lolo_cost, storage_cost, subtotal per registration.
     */
    private function calculateRegistrationCosts(Registration $reg): array
    {
        $loloCost    = $reg->loloRecords()->sum('tariff_price');
        $storageCost = $reg->storageRecords()->sum('total_storage_cost');
        $subtotal    = $loloCost + $storageCost;

        return [
            'lolo_cost'    => $loloCost,
            'storage_cost' => $storageCost,
            'subtotal'     => $subtotal,
        ];
    }

    /**
     * Hitung subtotal invoice (jumlah semua subtotal registrasi),
     * lalu aplikasikan pajak aktif dan kembalikan breakdown lengkap.
     */
    private function calculateTotals(float $subtotal, array $taxIds = []): array
    {
        $taxes      = Tax::whereIn('id', $taxIds)->where('is_active', true)->get();
        $totalAdd  = 0;
        $totalDeduct  = 0;
        $taxDetails = [];

        foreach ($taxes as $tax) {
            if ($tax->value_type === 'PERCENTAGE') {
                $amount = round($subtotal * ($tax->value / 100), 2);
            } else {
                $amount = $tax->value;
            }

            if (strtoupper($tax->type) === 'ADD') {
                $totalAdd += $amount;
            } else {
                $totalDeduct += $amount;
            }

            $taxDetails[] = [
                'id'         => $tax->id,
                'name'       => $tax->name,
                'type'       => $tax->type,
                'value'      => $tax->value,
                'value_type' => $tax->value_type,
                'amount'     => $amount,
            ];
        }

        $grandTotal = $subtotal + $totalAdd - $totalDeduct;

        return [
            'subtotal'    => $subtotal,
            'additions'   => $totalAdd,
            'deductions'  => $totalDeduct,
            'grand_total' => $grandTotal,
            'tax_details' => $taxDetails,
        ];
    }

    /**
     * Generate nomor invoice: SMNT/PORT/{urut}/{bulan-romawi}/{tahun}
     */
    private function generateInvoiceNumber(): string
    {
        $roman = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
        $month = $roman[now()->month - 1];
        $year  = now()->year;

        $count = Invoice::whereYear('invoice_date', $year)
                        ->whereMonth('invoice_date', now()->month)
                        ->count() + 1;

        return sprintf('SMNT/PORT/%03d/%s/%d', $count, $month, $year);
    }

    // ─── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * GET /invoices
     * Filter opsional: freight_forwarder_id, status, date_from, date_to
     */
    public function index(Request $request)
    {
        try {
            $query = Invoice::with($this->getWith())->orderBy('invoice_date', 'desc');

            if ($request->filled('freight_forwarder_id')) {
                $query->where('freight_forwarder_id', $request->freight_forwarder_id);
            }

            if ($request->filled('status')) {
                $query->where('status', strtoupper($request->status));
            }

            if ($request->filled('date_from')) {
                $query->whereDate('invoice_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('invoice_date', '<=', $request->date_to);
            }

            $data = $query->get();

            return $data->isEmpty()
                ? response()->json(['message' => $this->messageMissing], 404)
                : response()->json(['data' => $data, 'message' => $this->messageAll], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /invoices/{id}
     */
    public function show($id)
    {
        try {
            $data = Invoice::with($this->getWith())->find($id);

            if (! $data) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            return response()->json(['data' => $data, 'message' => $this->messageSuccess], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * GET /freight-forwarders/{ffId}/registrations/invoiceable
     * Ambil semua registrasi CLOSED & belum diinvoice milik FF ini.
     * Dipakai frontend untuk tampilkan pilihan sebelum generate invoice.
     */
    public function getInvoiceableRegistrations($ffId)
    {
        try {
            $ff = FreightForwarders::find($ffId);

            if (! $ff) {
                return response()->json(['message' => 'Freight Forwarder tidak ditemukan'], 404);
            }

            $registrations = Registration::with([
                    'size:id,code,description',
                    'type:id,code,description',
                    'loloRecords.cargoStatus:id,code,description',
                    'storageRecords.cargoStatus:id,code,description',
                    'storageRecords.yard:id,name,code',
                ])
                ->where('freight_forwarder_id', $ffId)
                ->where('record_status', 'CLOSED')
                ->where('invoiced', false)
                ->where('is_active', true)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($reg) {
                    $costs = $this->calculateRegistrationCosts($reg);
                    $reg->lolo_cost    = $costs['lolo_cost'];
                    $reg->storage_cost = $costs['storage_cost'];
                    $reg->subtotal     = $costs['subtotal'];
                    return $reg;
                });

            if ($registrations->isEmpty()) {
                return response()->json([
                    'message' => 'Tidak ada registrasi yang bisa diinvoice untuk Freight Forwarder ini.',
                ], 404);
            }

            // Preview total
            $totalSubtotal = $registrations->sum('subtotal');
            $totals        = $this->calculateTotals($totalSubtotal, []); // No taxes applied in preview initially

            return response()->json([
                'freight_forwarder' => $ff,
                'registrations'     => $registrations,
                'preview_totals'    => $totals,
                'message'           => $this->messageAll,
            ], 200);
        } catch (QueryException $e) {
            return $this->queryError($e);
        }
    }

    /**
     * POST /invoices
     * Generate invoice baru dari registrasi yang dipilih.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'freight_forwarder_id'  => 'required|exists:freight_forwarders,id',
                'registration_ids'      => 'required|array|min:1',
                'registration_ids.*'    => 'required|integer|exists:registrations,id',
                'invoice_date'          => 'required|date',
                'bank_name'             => 'required|string|max:255',
                'swift_code'            => 'required|string|max:50',
                'bank_account_name'     => 'required|string|max:255',
                'bank_account_number'   => 'required|string|max:50',
                'signatory_name'        => 'required|string|max:255',
                'signatory_position'    => 'required|string|max:255',
                'tax_ids'               => 'nullable|array',
                'tax_ids.*'             => 'integer|exists:taxes,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            // Validasi semua registrasi: milik FF yang sama, CLOSED, belum diinvoice
            $registrations = Registration::whereIn('id', $request->registration_ids)->get();

            foreach ($registrations as $reg) {
                if ($reg->freight_forwarder_id != $request->freight_forwarder_id) {
                    return response()->json([
                        'message' => "Registrasi #{$reg->id} (container: {$reg->container_number}) bukan milik Freight Forwarder yang dipilih.",
                        'success' => false,
                    ], 400);
                }

                if ($reg->record_status !== 'CLOSED') {
                    return response()->json([
                        'message' => "Registrasi #{$reg->id} (container: {$reg->container_number}) belum CLOSED.",
                        'success' => false,
                    ], 400);
                }

                if ($reg->invoiced) {
                    return response()->json([
                        'message' => "Registrasi #{$reg->id} (container: {$reg->container_number}) sudah pernah diinvoice.",
                        'success' => false,
                    ], 400);
                }
            }

            // Hitung cost per registrasi dan total
            $totalSubtotal = 0;
            $regCosts      = [];

            foreach ($registrations as $reg) {
                $costs           = $this->calculateRegistrationCosts($reg);
                $regCosts[$reg->id] = $costs;
                $totalSubtotal  += $costs['subtotal'];
            }

            // Hitung pajak dan grand total menggunakan tax_ids yang dipilih
            $taxIds = $request->input('tax_ids', []);
            $totals = $this->calculateTotals($totalSubtotal, $taxIds);

            // Buat Invoice
            $invoice = Invoice::create([
                'freight_forwarder_id' => $request->freight_forwarder_id,
                'generated_by'         => $request->user()->id,
                'invoice_number'       => $this->generateInvoiceNumber(),
                'invoice_date'         => $request->invoice_date,
                'bank_name'            => $request->bank_name,
                'swift_code'           => $request->swift_code,
                'bank_account_name'    => $request->bank_account_name,
                'bank_account_number'  => $request->bank_account_number,
                'signatory_name'       => $request->signatory_name,
                'signatory_position'   => $request->signatory_position,
                'subtotal'             => $totals['subtotal'],
                'grand_total'          => $totals['grand_total'],
                'status'               => 'DRAFT',
            ]);

            // Buat InvoiceRegistration pivot dan tandai registrasi sebagai invoiced
            foreach ($registrations as $reg) {
                $costs = $regCosts[$reg->id];

                InvoiceRegistration::create([
                    'invoice_id'      => $invoice->id,
                    'registration_id' => $reg->id,
                    'lolo_cost'       => $costs['lolo_cost'],
                    'storage_cost'    => $costs['storage_cost'],
                    'subtotal'        => $costs['subtotal'],
                ]);

                $reg->update(['invoiced' => true]);
            }

            // Simpan pajak ke pivot table invoice_taxes
            foreach ($totals['tax_details'] as $taxData) {
                $invoice->taxes()->attach($taxData['id'], [
                    'tax_value'         => $taxData['value'],
                    'tax_value_type'    => $taxData['value_type'],
                    'tax_type'          => $taxData['type'],
                    'calculated_amount' => $taxData['amount'],
                ]);
            }

            DB::commit();

            return response()->json([
                'data'        => $invoice->load($this->getWith()),
                'tax_details' => $totals['tax_details'],
                'message'     => $this->messageCreate,
                'success'     => true,
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

    /**
     * PUT /invoices/{id}
     * Update info bank/penandatangan — hanya jika masih DRAFT.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $invoice = Invoice::find($id);

            if (! $invoice) {
                return response()->json(['message' => $this->messageMissing, 'success' => false], 404);
            }

            if ($invoice->status === 'PAID') {
                return response()->json([
                    'message' => 'Invoice yang sudah PAID tidak dapat diubah.',
                    'success' => false,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'invoice_date'        => 'sometimes|required|date',
                'bank_name'           => 'sometimes|required|string|max:255',
                'swift_code'          => 'sometimes|required|string|max:50',
                'bank_account_name'   => 'sometimes|required|string|max:255',
                'bank_account_number' => 'sometimes|required|string|max:50',
                'signatory_name'      => 'sometimes|required|string|max:255',
                'signatory_position'  => 'sometimes|required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'success' => false], 400);
            }

            $invoice->update([
                'invoice_date'        => $request->invoice_date        ?? $invoice->invoice_date,
                'bank_name'           => $request->bank_name           ?? $invoice->bank_name,
                'swift_code'          => $request->swift_code          ?? $invoice->swift_code,
                'bank_account_name'   => $request->bank_account_name   ?? $invoice->bank_account_name,
                'bank_account_number' => $request->bank_account_number ?? $invoice->bank_account_number,
                'signatory_name'      => $request->signatory_name      ?? $invoice->signatory_name,
                'signatory_position'  => $request->signatory_position  ?? $invoice->signatory_position,
            ]);

            DB::commit();

            return response()->json([
                'data'    => $invoice->fresh()->load($this->getWith()),
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
     * GET /invoices/{id}/status
     * Ubah status DRAFT → PAID.
     */
    public function pay($id)
    {
        DB::beginTransaction();

        try {
            $invoice = Invoice::find($id);

            if (! $invoice) {
                return response()->json(['message' => $this->messageMissing, 'success' => false], 404);
            }

            $invoice->update(['status' => 'PAID']);

            DB::commit();

            return response()->json([
                'data'    => $invoice->fresh(),
                'message' => 'Status invoice berhasil diperbarui.',
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
     * DELETE /invoices/{id}
     * Batalkan invoice DRAFT — kembalikan semua registrasi ke invoiced = false.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $invoice = Invoice::with('invoiceRegistrations')->find($id);

            if (! $invoice) {
                return response()->json(['message' => $this->messageMissing, 'success' => false], 404);
            }

            if ($invoice->status === 'PAID') {
                return response()->json([
                    'message' => 'Invoice yang sudah PAID tidak dapat dibatalkan.',
                    'success' => false,
                ], 400);
            }

            // Kembalikan semua registrasi ke invoiced = false
            $regIds = $invoice->invoiceRegistrations->pluck('registration_id');
            Registration::whereIn('id', $regIds)->update(['invoiced' => false]);

            $invoice->delete(); // cascade ke invoice_registrations

            DB::commit();

            return response()->json([
                'message' => 'Invoice berhasil dibatalkan dan registrasi dikembalikan.',
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
     * GET /invoices/{id}/pdf
     * Export invoice ke PDF dalam format sesuai contoh.
     */

    public function exportPdf($id)
    {
        try {
            $invoice = Invoice::with([
                'freightForwarder',
                'generatedBy',
                'invoiceRegistrations.registration.size',
                'invoiceRegistrations.registration.type',
                'invoiceRegistrations.registration.loloRecords.cargoStatus',
                'invoiceRegistrations.registration.storageRecords.cargoStatus',
                'invoiceRegistrations.registration.storageRecords.yard',
            ])->find($id);

            if (! $invoice) {
                return response()->json(['message' => $this->messageMissing], 404);
            }

            // Bangun baris-baris invoice per kontainer (mirip format PDF)
            $rows   = [];
            $taxes  = Tax::where('is_active', true)->get();

            foreach ($invoice->invoiceRegistrations as $ir) {
                $reg  = $ir->registration;
                $size = $reg->size->code ?? '-';         // e.g. "20ft" atau "40ft"
                $is20 = str_contains(strtolower($size), '20');
                $is40 = str_contains(strtolower($size), '40');

                // Baris Storage Records (RENT CY/PLB)
                foreach ($reg->storageRecords as $sr) {
                    if (! $sr->start_date || ! $sr->end_date) continue;
                    $cargoLabel = strtoupper($sr->cargoStatus->code ?? '');
                    $yardCode   = $sr->yard->code ?? 'CY';  // Gunakan yard code (CY/PLB)
                    $dateRange  = Carbon::parse($sr->start_date)->format('d/m/Y')
                        . ' - ' . Carbon::parse($sr->end_date)->format('d/m/Y');

                    $rows[] = [
                        'container_number' => $reg->container_number,
                        'date'             => $dateRange,
                        'do'               => "RENT {$yardCode} ({$cargoLabel})",
                        'period'           => $sr->total_storage_days . ' DAYS',
                        'container_type'   => strtoupper($reg->type->description ?? '-'),
                        'is_20ft'          => $is20,
                        'is_40ft'          => $is40,
                        'qty'              => 1,
                        'total'            => $sr->total_storage_cost,
                    ];
                }

                // Baris Lolo Records (LIFT ON / LIFT OFF)
                foreach ($reg->loloRecords->sortBy('lolo_at') as $lr) {
                    $cargoLabel = strtoupper($lr->cargoStatus->code ?? '');
                    $opLabel    = $lr->operation_type === 'LIFT_ON' ? 'LIFT ON' : 'LIFT OFF';

                    $rows[] = [
                        'container_number' => $reg->container_number,
                        'date'             => Carbon::parse($lr->lolo_at)->format('d/m/Y'),
                        'do'               => "{$opLabel} ({$cargoLabel})",
                        'period'           => 1,
                        'container_type'   => strtoupper($reg->type->description ?? '-'),
                        'is_20ft'          => $is20,
                        'is_40ft'          => $is40,
                        'qty'              => 1,
                        'total'            => $lr->tariff_price,
                    ];
                }
            }

            // Hitung breakdown pajak
            $subtotal = $invoice->subtotal;

            $taxSummary = [];   // ['add' => xxx, 'deduct' => xxx, dll]
            $taxBreakdown = [];

            foreach ($taxes as $tax) {
                $amount = round($subtotal * ($tax->percentage / 100), 2);

                $type = strtolower($tax->type); // contoh: add / deduct

                if (!isset($taxSummary[$type])) {
                    $taxSummary[$type] = 0;
                }

                $taxSummary[$type] += $amount;

                $taxBreakdown[] = [
                    'name'       => $tax->name,
                    'type'       => $tax->type,
                    'percentage' => $tax->percentage,
                    'amount'     => $amount,
                ];
            }

            // Default fallback
            $totalAdd    = $taxSummary['add']    ?? 0;
            $totalDeduct = $taxSummary['deduct'] ?? 0;

            $grandTotal = $subtotal + $totalAdd - $totalDeduct;

            // ─── Build HTML ─────────────────────────────────────────────
            $html = $this->buildInvoiceHtml(
                $invoice,
                $rows,
                $subtotal,
                $totalAdd,     // total pajak tambah
                $totalDeduct,  // total pajak potong
                $grandTotal,
                $taxBreakdown
            );
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
                ->setPaper('a4', 'portrait')
                ->setOptions([
                    'isHtml5ParserEnabled' => true,
                    'isPhpEnabled'         => false,
                    'defaultFont'          => 'Arial',
                    'dpi'                  => 150,
                ]);

            $filename = 'Invoice_' . str_replace('/', '_', $invoice->invoice_number) . '.pdf';

            return $pdf->stream($filename);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $this->messageFail,
                'err'     => $e->getTrace()[0],
                'errMsg'  => $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    // ─── HTML Builder (print-ready, Ctrl+P → Save as PDF) ────────────────────

    private function buildInvoiceHtml(Invoice $invoice, array $rows, float $subtotal, float $ppn, float $pph, float $grandTotal, array $taxBreakdown): string
    {
        $ff          = $invoice->freightForwarder;
        $invoiceNo   = $invoice->invoice_number;
        $invoiceDate = Carbon::parse($invoice->invoice_date)->format('d/m/Y');

        // ── Baca file gambar dari folder public dan konversi ke Base64 ──
        $headerPath = public_path('images/header-invoice.png');
        $headerB64  = base64_encode(file_get_contents($headerPath));
        $headerImg  = 'data:image/png;base64,' . $headerB64;


        // ── Table rows ────────────────────────────────────────────────
        $rowsHtml = '';
        $i = 0;
        foreach ($rows as $r) {
            $bg    = ($i % 2 === 0) ? '#ffffff' : '#f2f2f2';
            $total = 'Rp&nbsp;' . number_format($r['total'], 0, ',', '.');
            $col20 = $r['is_20ft']
                ? '<td style="text-align:center;border:0.5px solid #bbb;padding:4px 6px;">1</td><td style="border:0.5px solid #bbb;padding:4px 6px;"></td>'
                : '<td style="border:0.5px solid #bbb;padding:4px 6px;"></td><td style="text-align:center;border:0.5px solid #bbb;padding:4px 6px;">1</td>';

            $rowsHtml .= '<tr style="background:' . $bg . '">'
                . '<td style="border:0.5px solid #bbb;padding:4px 6px;">' . $r['container_number'] . '</td>'
                . '<td style="text-align:center;border:0.5px solid #bbb;padding:4px 6px;">' . $r['date'] . '</td>'
                . '<td style="text-align:center;border:0.5px solid #bbb;padding:4px 6px;">' . $r['do'] . '</td>'
                . '<td style="text-align:center;border:0.5px solid #bbb;padding:4px 6px;">' . $r['period'] . '</td>'
                . '<td style="text-align:center;border:0.5px solid #bbb;padding:4px 6px;">' . $r['container_type'] . '</td>'
                . $col20
                . '<td style="text-align:right;border:0.5px solid #bbb;padding:4px 6px;">' . $total . '</td>'
                . '</tr>';
            $i++;
        }

        // ── Tax rows ──────────────────────────────────────────────────
        $taxHtml = '';
        foreach ($taxBreakdown as $t) {
            $sign    = strtoupper($t['type']) === 'ADD' ? '' : '-';
            $amount  = $sign . 'Rp&nbsp;' . number_format($t['amount'], 0, ',', '.');
            $taxHtml .= '<tr>'
                . '<td colspan="6" style="text-align:right;padding:3px 8px;">' . $t['name'] . '</td>'
                . '<td style="text-align:right;padding:3px 8px;">' . $amount . '</td>'
                . '</tr>';
        }

        $fmtSubtotal   = 'Rp&nbsp;' . number_format($subtotal,   0, ',', '.');
        $fmtGrandTotal = 'Rp&nbsp;' . number_format($grandTotal, 0, ',', '.');

        $bankLines = $invoice->bank_name
            . '<br/>' . 'SWIFT/BIC ; ' . $invoice->swift_code
            . '<br/>' . 'Nomor Rekening : ' . $invoice->bank_account_number
            . '<br/>' . 'Atas Nama (A/N): ' . $invoice->bank_account_name;

        $html = '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<title>Invoice ' . $invoiceNo . '</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }

/* 🔥 Base font dinaikkan */
body {
  font-family: Arial, Helvetica, sans-serif;
  font-size: 10px;
  color: #000;
  padding: 22px 30px;
}

/* 🔥 Judul lebih besar */
h1 {
  font-size: 32px;
  font-weight: bold;
  text-align: center;
  letter-spacing: 4px;
  margin-bottom: 14px;
}

table { width: 100%; border-collapse: collapse; }

.header-img { width: 100%; margin-bottom: 16px; }

/* 🔥 Header table */
th {
  background-color: #2c5f9e;
  color: #ffffff;
  text-align: center;
  padding: 10px 6px;
  font-size: 18px;
  border: 0.5px solid #2c5f9e;
}

/* 🔥 Isi table */
td {
  font-size: 16px;
  vertical-align: middle;
  padding: 7px;
}

.totals-inner { width: 48%; margin-left: auto; }

.totals-inner table { border: none; }

.totals-inner td {
  border: none;
  padding: 5px 6px;
  font-size: 20px;
}

/* 🔥 Total dibuat lebih standout */
.total-row td {
  font-weight: bold;
  font-size: 22px;
  border-top: 2px solid #000;
  padding-top: 6px;
}

/* 🔥 Signature */
.signatory-name {
  font-weight: bold;
  border-top: 1.5px solid #000;
  padding-top: 5px;
  margin-top: 65px;
  text-align: center;
  font-size: 20px;
}

</style>
</head>
<body>

<table style="margin-bottom:22px;border:none;width:100%;">
  <tr>
    <td style="border:none;text-align:center;padding:0;">
      <img src="' . $headerImg . '" style="width:100%; display:block;" alt="Header Invoice" />
    </td>
  </tr>
</table>

<table style="margin-bottom:22px;">
  <tr>
    <td style="width:50%;border:none; font-size: 15px;">
      <div style="margin-bottom: 5px;">No : ' . $invoiceNo . '</div>
      <div>Tanggal : ' . $invoiceDate . '</div>
    </td>
    <td style="width:50%;text-align:right;border:none; font-size: 15px;">
      <div style="margin-bottom: 5px;">Kepada :</div>
      <div><strong>' . $ff->name . '</strong></div>
    </td>
  </tr>
</table>

<table style="margin-bottom:12px;">
  <thead>
    <tr>
      <th rowspan="2">NO CONTAINER</th>
      <th rowspan="2">DATE</th>
      <th rowspan="2">DO</th>
      <th rowspan="2">PERIOD</th>
      <th rowspan="2">CONTAINER TYPE</th>
      <th colspan="2">SIZE</th>
      <th rowspan="2">TOTAL</th>
    </tr>
    <tr>
      <th>20 FT</th>
      <th>40 FT</th>
    </tr>
  </thead>
  <tbody>' . $rowsHtml . '</tbody>
</table>

<div class="totals-inner" style="margin-top:60px;">
  <table>
    <tr>
      <td colspan="6" style="text-align:right;font-style:italic;">SUB TOTAL</td>
      <td style="text-align:right;">' . $fmtSubtotal . '</td>
    </tr>
    ' . $taxHtml . '
    <tr class="total-row">
      <td colspan="6" style="text-align:right;">TOTAL</td>
      <td style="text-align:right;">' . $fmtGrandTotal . '</td>
    </tr>
  </table>
</div>

<table style="margin-top:50px;border:none;">
  <tr>
    <td style="width:60%;vertical-align:top;border:none;">
      <div style="font-size:18px;font-weight:bold;margin-bottom:5px;">Metode Pembayaran</div>
      <div style="font-size:18px;margin-bottom:14px;line-height:1.5;">' . $bankLines . '</div>
      <div style="font-size:18px;font-weight:bold;margin-bottom:5px;">PT SEI MANGKEI NUSANTARA TIGA</div>
      <div style="font-size:16px;margin-bottom:3px;line-height:1.5;">Jl Kelapa Sawit I No. 1 KEK Sei Mangkei, Kec. Bosar Maligas</div>
      <div style="font-size:16px;margin-bottom:3px;line-height:1.5;">Kab. Simalungun Sumatera Utara</div>
      <div style="font-size:16px;margin-bottom:3px;line-height:1.5;">Indonesia 21183</div>
      <div style="font-size:16px;">Telp: +62 622 7296406</div>
    </td>
    <td style="width:40%;text-align:center;vertical-align:bottom;border:none;">
      <div style="margin-top:65px;padding-top:7px;font-weight:bold;border-top:2px solid #000;font-size:20px;text-decoration:underline;display:inline-block;padding-left:22px;padding-right:22px;">' . $invoice->signatory_name . '</div>
      <div style="font-size:20px;margin-top:5px;">' . $invoice->signatory_position . '</div>
    </td>
  </tr>
</table>

</body>
</html>';

        return $html;
    }


    // ─── Error helper ─────────────────────────────────────────────────────────

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
