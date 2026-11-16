# Block checkout until bank confirmation is configured

## Goal

Prevent checkout from issuing confirmed bookings through the old mock-payment path. Only create a pending VietQR booking when SePay auto-confirmation is enabled and configured; only the authenticated webhook may confirm it.

## Tasks

1. [x] Reject checkout server-side when SePay is disabled or its webhook API key is missing.
2. [x] Remove the client-side mock confirmation path and disable checkout while bank confirmation is unavailable.
3. [x] Update payment messaging so customers know checkout is unavailable until SePay is configured.

## Verification

- Review the endpoint and UI paths to confirm neither can mark a booking paid without the SePay webhook.
- Do not run tests unless requested.
