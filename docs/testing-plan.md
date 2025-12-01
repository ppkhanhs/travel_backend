## Testing & Evaluation Plan

This repo now includes scaffolding for multiple layers of validation. Toggle `RUN_E2E_TESTS=true` in `.env.testing` to enable DB-backed E2E tests.

### 1) End-to-end API flows (Feature tests)
- File: `tests/Feature/E2EHappyPathTest.php`
- Covers booking creation (offline), payment-status lookup, notifications list, chatbot and recommendations endpoints.
- Skipped by default to avoid failing on CI without seed data. Enable with `RUN_E2E_TESTS=true` and run `php artisan test --filter=E2EHappyPathTest`.

### 2) Load / stability checks
- File: `tests/performance/k6-smoke.js`
- Run: `BASE_URL=https://your-api AUTH_TOKEN=... k6 run tests/performance/k6-smoke.js`
- Smoke-checks `/api/home`, `/api/recommendations`, `/api/notifications` under configurable VUs/duration.

### 3) Token/cache sync (web & mobile)
- Reuse the E2E test by running multiple requests with the same Sanctum token; extend `E2EHappyPathTest` to add assertions on reused tokens and cache headers.
- Manual checklist: confirm FE/BE share the same `Authorization: Bearer <token>` for all authenticated calls; confirm `cache-control` headers are respected on `/api/home` and recommendation endpoints.

### 4) Recommendation model evaluation (offline)
- Script: `scripts/eval_recommendations.py`
- Inputs: recommendation JSON (`[{user_id, items:[{tour_id, score}]}, ...]`) and groundtruth CSV (`user_id,tour_id`).
- Metrics: Precision@K, Recall@K, NDCG@K. Run with `python scripts/eval_recommendations.py --recommendations recs.json --groundtruth truth.csv --k 5`.

### Suggested next additions
- Add fixtures/factories for Tour/TourSchedule/TourPackage to make E2E tests data-independent.
- Add negative-path tests (invalid payment method, overbooked schedule, unauthorized chatbot).
- Add JMeter/k6 ramp-up profiles for critical endpoints.
- Add cron-based regression job to export live logs → evaluate with the offline script nightly.

