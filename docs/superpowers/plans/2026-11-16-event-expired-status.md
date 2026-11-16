# Correct public status for expired events

## Goal

Derive public event availability from its showtime schedule so events with no bookable future showtimes are not labeled as on sale and do not show a ticket-purchase CTA.

## Tasks

1. [x] Add an eager-loadable schedule status on Event: on sale, in progress, ended, or unscheduled.
2. [x] Use that status in event cards, event detail, favorites, and the homepage spotlight; only show ticket-purchase CTA for upcoming showtimes.
3. [x] Update architecture notes.

## Verification

- Inspect the changed queries and views for consistent status mapping.
- Do not run tests unless requested.
