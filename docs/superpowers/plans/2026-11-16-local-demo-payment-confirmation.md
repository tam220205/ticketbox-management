# Local Demo Payment Confirmation

## Goal

Restore the simulated payment confirmation flow for local development without allowing simulated payments in production.

## Scope

- Add an explicit `PAYMENT_DEMO_MODE` setting, disabled by default.
- Permit demo confirmation only when Laravel runs in `local` or `testing`.
- Restore a clearly labeled demo confirmation action in checkout.
- Enable the setting in the local `.env` only.

## Tasks

- [x] Add the guarded server-side demo checkout path.
- [x] Add the local demo UI and explicit simulation label.
- [x] Enable demo mode in this local environment and clear Laravel's config cache.
- [x] Document that demo payments never verify real bank transfers.
