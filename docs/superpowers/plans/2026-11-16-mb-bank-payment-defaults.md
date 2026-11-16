# Set MB Bank as the VietQR receiving bank

## Goal

Use MB Bank as the default bank in the VietQR image, account details, and payment labels, with account number and holder sourced from the existing MB environment variables.

## Tasks

1. [x] Change payment service defaults from Vietcombank to MB Bank and remove hard-coded sample account details.
2. [x] Update checkout labels and fallbacks to MB Bank.

## Verification

- Confirm no Vietcombank/VCB or sample VCB account values remain in the payment config and checkout view.
- Do not run tests unless requested.
