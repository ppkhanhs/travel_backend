@php
    $tour = $booking->tourSchedule?->tour;
    $schedule = $booking->tourSchedule;
    $package = $booking->package;
    $partner = $tour?->partner;
    $paymentMethod = $invoice['payment_method'] ?? null;
@endphp

<div style="font-family: Arial, sans-serif; max-width: 640px; margin: 0 auto;">
    <h2 style="color:#ff5722;">Cảm ơn bạn đã đặt tour tại Travel App!</h2>
    <p>Xin chào {{ $booking->contact_name }},</p>
    <p>Đơn đặt tour của bạn đã được ghi nhận thành công. Dưới đây là thông tin chi tiết hóa đơn.</p>

    <h3>Thông tin tour</h3>
    <ul>
        <li><strong>Tên tour:</strong> {{ $tour?->title }}</li>
        <li><strong>Điểm đến:</strong> {{ $tour?->destination ?? 'Đang cập nhật' }}</li>
        <li><strong>Lịch khởi hành:</strong> {{ optional($schedule?->start_date)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</li>
        @if($package)
            <li><strong>Gói dịch vụ:</strong> {{ $package->title ?? 'Gói tiêu chuẩn' }}</li>
        @endif
        @if($partner)
            <li><strong>Đối tác tổ chức:</strong> {{ $partner->company_name }}</li>
        @endif
        <li><strong>Mã đơn:</strong> {{ $booking->id }}</li>
    </ul>

    <h3>Thông tin hành khách</h3>
    <p>Tổng: {{ $booking->total_adults }} người lớn @if($booking->total_children) và {{ $booking->total_children }} trẻ em @endif</p>
    <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse;">
        <thead>
            <tr>
                <th align="left" style="border-bottom:1px solid #ddd;">Họ tên</th>
                <th align="left" style="border-bottom:1px solid #ddd;">Loại</th>
                <th align="left" style="border-bottom:1px solid #ddd;">Ngày sinh</th>
                <th align="left" style="border-bottom:1px solid #ddd;">Giới tính</th>
            </tr>
        </thead>
        <tbody>
            @foreach($booking->passengers as $passenger)
                <tr>
                    <td style="border-bottom:1px solid #f5f5f5;">{{ $passenger->full_name }}</td>
                    <td style="border-bottom:1px solid #f5f5f5;">{{ $passenger->type === 'child' ? 'Trẻ em' : 'Người lớn' }}</td>
                    <td style="border-bottom:1px solid #f5f5f5;">{{ optional($passenger->date_of_birth)->format('d/m/Y') ?? '--' }}</td>
                    <td style="border-bottom:1px solid #f5f5f5;">{{ $passenger->gender ?? '--' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>Chi tiết thanh toán</h3>
    <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse;">
        <thead>
            <tr>
                <th align="left" style="border-bottom:1px solid #ddd;">Mô tả</th>
                <th align="right" style="border-bottom:1px solid #ddd;">SL</th>
                <th align="right" style="border-bottom:1px solid #ddd;">Đơn giá</th>
                <th align="right" style="border-bottom:1px solid #ddd;">Thành tiền</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice['line_items'] as $item)
                <tr>
                    <td style="border-bottom:1px solid #f5f5f5;">{{ $item['description'] }}</td>
                    <td align="right" style="border-bottom:1px solid #f5f5f5;">{{ $item['quantity'] }}</td>
                    <td align="right" style="border-bottom:1px solid #f5f5f5;">{{ number_format($item['unit_price'], 0, ',', '.') }} đ</td>
                    <td align="right" style="border-bottom:1px solid #f5f5f5;">{{ number_format($item['amount'], 0, ',', '.') }} đ</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" align="right" style="padding-top:12px;">Tạm tính</td>
                <td align="right" style="padding-top:12px;">{{ number_format($invoice['subtotal'], 0, ',', '.') }} đ</td>
            </tr>
            @if(($invoice['discount'] ?? 0) > 0)
                <tr>
                    <td colspan="3" align="right">Ưu đãi</td>
                    <td align="right">-{{ number_format($invoice['discount'], 0, ',', '.') }} đ</td>
                </tr>
            @endif
            <tr>
                <td colspan="3" align="right"><strong>Tổng thanh toán</strong></td>
                <td align="right"><strong>{{ number_format($invoice['total'], 0, ',', '.') }} đ</strong></td>
            </tr>
            @if($paymentMethod)
                <tr>
                    <td colspan="3" align="right">Phương thức</td>
                    <td align="right">{{ strtoupper($paymentMethod) }}</td>
                </tr>
            @endif
        </tfoot>
    </table>

    <p style="margin-top:24px;">Hóa đơn này được gửi tới <strong>{{ $booking->contact_email ?? $booking->user?->email }}</strong>. Vui lòng lưu lại email để tra cứu khi cần.</p>

    <p>Nếu bạn cần hỗ trợ thêm, hãy phản hồi email này hoặc liên hệ bộ phận CSKH.</p>

    <p>Trân trọng,<br/>Đội ngũ Travel App</p>
</div>
