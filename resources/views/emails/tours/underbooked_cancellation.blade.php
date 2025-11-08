<p>Xin chào {{ $booking->contact_name ?? ($booking->user->name ?? 'Quý khách') }},</p>

<p>Chúng tôi rất tiếc phải thông báo rằng lịch khởi hành ngày
    <strong>{{ optional($schedule->start_date)->format('d/m/Y') }}</strong>
    của tour <strong>{{ $tour->title ?? 'du lịch' }}</strong>
    không thể diễn ra do chưa đủ số lượng khách tối thiểu.</p>

<p>Toàn bộ khoản thanh toán của quý khách sẽ được hoàn trả về phương thức đã sử dụng. Vui lòng cho phép hệ thống xử lý trong vòng 3–5 ngày làm việc.</p>

@if($alternatives->isNotEmpty())
    <p>Quý khách có thể lựa chọn một trong các lịch khởi hành khác của cùng tour:</p>
    <ul>
        @foreach($alternatives as $alternative)
            <li>
                Khởi hành ngày {{ \Illuminate\Support\Carbon::parse($alternative->start_date)->format('d/m/Y') }}
                @if($alternative->end_date)
                    – {{ \Illuminate\Support\Carbon::parse($alternative->end_date)->format('d/m/Y') }}
                @endif
                (Còn lại {{ (int) $alternative->seats_available }} chỗ,
                tối thiểu {{ (int) $alternative->min_participants }} khách)
            </li>
        @endforeach
    </ul>
@endif

@if($vouchers->isNotEmpty())
    <p>Để bù đắp cho sự bất tiện, chúng tôi đã tặng kèm voucher sử dụng cho các tour thuộc cùng đối tác:</p>
    <ul>
        @foreach($vouchers as $voucher)
            <li>
                Mã <strong>{{ $voucher->voucher_code }}</strong> –
                Giảm
                @if(in_array(strtolower($voucher->promotion->discount_type ?? ''), ['percent', 'percentage']))
                    {{ $voucher->promotion->value }}%
                @else
                    {{ number_format($voucher->promotion->value, 0, '.', ',') }} VND
                @endif
                @if($voucher->expires_at)
                    (Hạn dùng tới {{ $voucher->expires_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') }})
                @endif
            </li>
        @endforeach
    </ul>
    <p>Vui lòng nhập mã trên khi đặt tour mới hoặc liên hệ bộ phận hỗ trợ nếu cần trợ giúp.</p>
@endif

<p>Nếu quý khách muốn chuyển sang lịch khác, xin vui lòng phản hồi email này hoặc liên hệ bộ phận hỗ trợ của chúng tôi.</p>

<p>Rất mong được đồng hành cùng quý khách trong những hành trình sắp tới.</p>

<p>Trân trọng,<br>
{{ config('app.name') }}</p>
