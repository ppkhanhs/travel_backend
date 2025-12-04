# Travel System – Hướng dẫn chạy nhanh

## Kiến trúc
- Backend (Laravel) – đang được deploy trên Render.
- Frontend Web (React/Vite) – trỏ vào backend Render.
- Mobile (Flutter) – trỏ vào backend Render.

## Backend (nếu muốn chạy local)
1) Yêu cầu: PHP 8.1+, Composer, Postgres.
2) Cài đặt:
```bash
cp .env.example .env   # điền DB, APP_URL, MAIL, SEPAY...
composer install
php artisan key:generate
php artisan migrate --seed
```
3) Chạy:
```bash
php artisan serve
```
4) Gợi ý (tùy chọn):
```bash
php artisan recommendations:train
php artisan queue:work
php artisan schedule:work
```

## Frontend Web (chạy local)
1) Yêu cầu: Node 18+.
2) Cài đặt:
```bash
npm install
```
3) Cấu hình `.env` (hoặc `.env.local`) với `VITE_API_BASE_URL=https://<backend-render-url>`.
4) Chạy dev:
```bash
npm run dev
```

## Mobile (Flutter)
1) Yêu cầu: Flutter SDK.
2) Cấu hình API base URL trỏ về backend Render (ví dụ trong file env/config của app).
3) Chạy:
```bash
flutter pub get
flutter run
```

## Kiểm thử & đánh giá
- Feature test (E2E booking offline): bật `RUN_E2E_TESTS=true` rồi:
```bash
php artisan test --filter=E2EHappyPathTest
```
- k6 smoke (tải nhẹ):
```bash
BASE_URL=https://<backend> AUTH_TOKEN=<sanctum_token> k6 run tests/performance/k6-smoke.js
```
- Đánh giá gợi ý:
```bash
php scripts/export_eval_data.php
python scripts/eval_recommendations.py --recommendations recs.json --groundtruth groundtruth.csv --k 5
```

## Support Ticket (API mới)
- Tạo: `POST /api/support-tickets` (subject, message, booking_id optional)
- Danh sách: `GET /api/support-tickets`
- Chi tiết: `GET /api/support-tickets/{id}`
- Admin cập nhật trạng thái: `PATCH /api/support-tickets/{id}/status` (status: open|in_progress|resolved|closed)

## Lưu ý
- Không commit `.env` thật; dùng `.env.example`.
- Nếu dùng backend Render, chỉ cần chạy frontend web & mobile local, trỏ đúng API base URL.

