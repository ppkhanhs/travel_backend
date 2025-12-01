import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.AUTH_TOKEN || '';

export const options = {
  vus: Number(__ENV.VUS || 5),
  duration: __ENV.DURATION || '30s',
};

const authHeaders = TOKEN
  ? {
      Authorization: `Bearer ${TOKEN}`,
    }
  : {};

export default function () {
  const publicRes = http.get(`${BASE_URL}/api/home`);
  check(publicRes, {
    'home responds 200': (r) => r.status === 200,
  });

  const recRes = http.get(`${BASE_URL}/api/recommendations`, {
    headers: authHeaders,
  });
  check(recRes, {
    'recommendations responds': (r) => [200, 401].includes(r.status),
  });

  if (TOKEN) {
    const notifications = http.get(`${BASE_URL}/api/notifications`, {
      headers: authHeaders,
    });
    check(notifications, {
      'notifications responds': (r) => r.status === 200,
    });
  }

  sleep(1);
}

