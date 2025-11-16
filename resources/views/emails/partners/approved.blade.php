@php
    $loginUrl = config('app.url') . '/partner/login';
@endphp

<div style="font-family:Arial, sans-serif; max-width:600px; margin:0 auto;">
    <h2 style="color:#f97316;">Yêu cầu hợp tác đã được duyệt</h2>
    <p>Chào {{ $partner->contact_name ?? $partner->company_name }},</p>
    <p>Chúng tôi rất vui thông báo hồ sơ hợp tác với <strong>{{ config('app.name') }}</strong> đã được phê duyệt.</p>

    <p>Bạn có thể đăng nhập vào cổng đối tác bằng thông tin sau:</p>

    <table cellpadding="8" cellspacing="0" style="border-collapse:collapse;">
        <tr>
            <td><strong>Tài khoản</strong></td>
            <td>{{ $partner->contact_email }}</td>
        </tr>
        <tr>
            <td><strong>Mật khẩu tạm</strong></td>
            <td>{{ $password }}</td>
        </tr>
        <tr>
            <td><strong>Đường dẫn</strong></td>
            <td><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></td>
        </tr>
    </table>

    <p>Vui lòng đăng nhập và đổi mật khẩu ngay trong lần đầu tiên để đảm bảo an toàn tài khoản.</p>

    <p>Nếu bạn cần hỗ trợ, hãy phản hồi email này hoặc liên hệ đội chăm sóc đối tác.</p>

    <p>Trân trọng,<br/>Đội ngũ {{ config('app.name') }}</p>
</div>
