<p>Xin chào {{ $invoice->customer_name }},</p>

<p>Hóa đơn <strong>{{ $invoice->invoice_number }}</strong> cho booking {{ $invoice->booking_id }} đã được phát hành bởi {{ $partner->invoice_company_name ?? $partner->company_name }}.</p>

<p>
    Tổng tiền: {{ number_format($invoice->total, 0, '.', ',') }} {{ $invoice->currency }}<br>
    Ngày phát hành: {{ optional($invoice->issued_at)->format('d/m/Y') }}
</p>

<p>Vui lòng xem file đính kèm để tải hóa đơn VAT của bạn. Nếu cần hỗ trợ thêm, hãy phản hồi email này.</p>

<p>Trân trọng,<br>
{{ config('app.name') }}</p>
