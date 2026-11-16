# SePay bank transfer payment confirmation

## Goal

Replace the real-money VietQR checkout demo with an optional SePay bank webhook flow. A booking remains pending until a matching incoming transaction is authenticated and checked against its unique booking code and exact amount. On confirmation, the buyer is sent to the issued ticket.

## Scope

- Block checkout when `SEPAY_ENABLED=false`; never treat a user click as proof of payment.
- When enabled, create a pending booking and reserve its seats before displaying the order-specific VietQR.
- Authenticate SePay webhook requests with an API key, persist transaction IDs idempotently, and confirm only exact incoming payments.
- Add authenticated payment-status polling for the checkout screen.
- Expire unpaid pending bookings and release their reserved seats.
- Send the existing confirmation email only after payment confirmation.
- Document setup, public HTTPS webhook requirements, and environment variables.

## Tasks

1. [x] Add SePay config and a new migration for payment expiry, paid timestamp, and idempotent payment transaction records.
2. [x] Implement pending checkout creation, transaction verification, webhook handling, payment-status endpoint, and expiration command/schedule.
3. [x] Update checkout UI to use an order-specific QR, poll payment status, and navigate to the ticket when confirmed; preserve demo behavior when disabled.
4. [x] Update `.env.example` and architecture docs with SePay setup and operational requirements.

## Verification

- Review PHP syntax and route/config wiring.
- Do not run tests unless the user requests them.
- No external payment provider credentials are included in the repository.

## External setup required

The operator must link the receiving bank account in SePay, configure an incoming-transaction webhook with API-key authentication pointing to the public HTTPS `/webhooks/sepay` endpoint, and configure the matching webhook key and receiving bank details in `.env`.
