<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        .header { text-align: center; margin-bottom: 20px; }
        .section { margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background-color: #f5f5f5; }
        .totals td { border: none; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h2>HÓA ĐƠN VAT / VAT INVOICE</h2>
        <p>Số hóa đơn / Invoice No: <strong>{{ $invoice->invoice_number }}</strong></p>
        <p>Ngày phát hành / Issue Date: {{ optional($invoice->issued_at)->format('d/m/Y') }}</p>
    </div>

    <div class="section">
        <h4>Thông tin đối tác / Partner Information</h4>
        <p><strong>{{ $partner->invoice_company_name ?? $partner->company_name }}</strong></p>
        <p>MST / Tax Code: {{ $partner->invoice_tax_code }}</p>
        <p>Địa chỉ / Address: {{ $partner->invoice_address ?? $partner->address }}</p>
        <p>Email: {{ $partner->invoice_email ?? $partner->email }}</p>
    </div>

    <div class="section">
        <h4>Thông tin khách hàng / Customer Information</h4>
        <p>Tên / Name: {{ $invoice->customer_name }}</p>
        <p>MST / Tax Code: {{ $invoice->customer_tax_code ?? 'N/A' }}</p>
        <p>Địa chỉ / Address: {{ $invoice->customer_address ?? 'N/A' }}</p>
        <p>Email: {{ $invoice->customer_email ?? 'N/A' }}</p>
    </div>

    <div class="section">
        <h4>Chi tiết dịch vụ / Service Details</h4>
        <table>
            <thead>
                <tr>
                    <th>Mô tả / Description</th>
                    <th>Số lượng / Qty</th>
                    <th>Đơn giá / Unit Price</th>
                    <th>Thành tiền / Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->line_items as $item)
                    <tr>
                        <td>{{ $item['description'] }}</td>
                        <td>{{ $item['quantity'] }}</td>
                        <td>{{ number_format($item['unit_price'], 0, '.', ',') }} {{ $invoice->currency }}</td>
                        <td>{{ number_format($item['amount'], 0, '.', ',') }} {{ $invoice->currency }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <table class="totals">
        <tr>
            <td>Thành tiền / Subtotal:</td>
            <td>{{ number_format($invoice->subtotal, 0, '.', ',') }} {{ $invoice->currency }}</td>
        </tr>
        <tr>
            <td>Thuế VAT ({{ $invoice->vat_rate }}%):</td>
            <td>{{ number_format($invoice->tax_amount, 0, '.', ',') }} {{ $invoice->currency }}</td>
        </tr>
        <tr>
            <td><strong>Tổng cộng / Total:</strong></td>
            <td><strong>{{ number_format($invoice->total, 0, '.', ',') }} {{ $invoice->currency }}</strong></td>
        </tr>
    </table>

    <p style="margin-top:40px;">Cảm ơn quý khách đã sử dụng dịch vụ / Thank you for choosing our service.</p>
</body>
</html>
