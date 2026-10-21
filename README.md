# PaymentHooks gateway module for WHMCS 8.x

Connects WHMCS to a [PaymentHooks](https://paymenthooks.com/docs) deployment.
PaymentHooks performs the payment, hosted checkout, gateway routing (Razorpay
for INR, etc.), subscriptions and refunds. This module creates checkout
sessions, redirects the client to the hosted page, and reconciles WHMCS
invoices from PaymentHooks webhooks.

## Features

- One-time invoice payments via hosted redirect checkout.
- Full and partial refunds from the WHMCS admin.
- Recurring billing: the mandate is authorised on the hosted checkout, then
  PaymentHooks drives the billing schedule and WHMCS marks each renewal invoice
  paid from the webhook.
- Webhook signature verification (HMAC-SHA256, 5-minute replay window) with
  duplicate guards: a per-event-id claim plus a direct transaction-id lookup in
  `tblaccounts` before applying a payment.
- Automatic retry/backoff on transient API errors, idempotency keys on creates.
- Test/live key isolation.
- Optional custom PaymentHooks domain (e.g. `payments.mr-innovations.com`),
  defaulting to `paymenthooks.com`.

## Install

1. Copy the contents of `modules/gateways/` into your WHMCS `modules/gateways/`
   directory, preserving the structure:
   ```
   modules/gateways/paymenthooks.php
   modules/gateways/callback/paymenthooks.php
   modules/gateways/paymenthooks/lib/*.php
   modules/gateways/paymenthooks/templates/pay_button.tpl
   modules/gateways/paymenthooks/whmcs.json
   modules/gateways/paymenthooks/logo.png
   ```
   `whmcs.json` and `logo.png` live inside the module's own folder, so they never
   collide with other gateways sharing `modules/gateways/`. The same `logo.png`
   is used on the admin gateway list and above the client "Pay Now" button.
2. In WHMCS admin: **Configuration > System Settings > Payment Gateways**,
   find **PaymentHooks** under "All Payment Gateways", and activate it.
3. Fill in the settings (see below). The support tables are created
   automatically on first use — no SQL to run.

## Configuration

| Field | Notes |
|-------|-------|
| Mode | Test or Live. Selects which key pair is used. |
| PaymentHooks Custom Domain | Optional. e.g. `payments.mr-innovations.com`. Blank = `paymenthooks.com`. HTTPS is always used. |
| Test / Live Secret Key | `sk_test_...` / `sk_live_...` |
| Test / Live Publishable Key | `pk_test_...` / `pk_live_...` |
| Webhook Signing Secret | From your PaymentHooks webhook endpoint. |
| Webhook URL | Read-only. Add this as a webhook endpoint in PaymentHooks. |
| Convenience Fee % | Optional surcharge. |
| Description Template | Shown on checkout. `{invoiceid}` is replaced. |
| Enable Subscriptions | Allow recurring products to set up a mandate at checkout. |
| Payment Mode | `Auto` (subscription for recurring products, one-time otherwise), `Subscription only` (force a mandate on every invoice), or `One-time only` (never set up a subscription). `Subscription only` requires Enable Subscriptions. |
| Debug Logging | Log API calls to Utilities > Logs > Module Log. |

### Forcing subscription-only payments

Set **Payment Mode** to **Subscription only** to make every checkout open a PaymentHooks
subscription (mandate), regardless of whether the WHMCS product is recurring. Use this when
all payments must be collected under a mandate. Enable Subscriptions must also be ticked; if
it isn't, the module falls back to one-time so checkout never dead-ends.

## PaymentHooks dashboard setup

1. Create a webhook endpoint pointing at the **Webhook URL** shown in the
   module config. Subscribe at least to:
   `checkout.session.completed`, `checkout.session.payment_failed`,
   `payment.refunded`, `subscription.renewed`, `invoice.paid`,
   `subscription.renewal_failed`, `invoice.payment_failed`,
   `subscription.cancelled`.
2. Copy the endpoint's signing secret into **Webhook Signing Secret**.

## Recurring billing — how it works

PaymentHooks has no off-session charge API, so **PaymentHooks owns the billing
schedule**, not WHMCS.

**Setup (first payment):**
1. When the invoice for a recurring product is paid, the module maps the WHMCS
   product + billing cycle + amount to a PaymentHooks **Product** and recurring
   **Price** (created on first use, cached in `mod_paymenthooks_price_map`).
2. The checkout session is opened in `mode: subscription` with that `priceId`,
   so the customer authorises a real mandate on the hosted page.
3. On `checkout.session.completed`, the PH **subscription id** is stored on the
   WHMCS service (`tblhosting.subscriptionid`) so cancellation and renewals can
   find it. If PH cannot provide a price (unmappable cycle, API error), the
   module falls back to a one-time payment so checkout never dead-ends.

**Ongoing (renewals):** PaymentHooks charges automatically each cycle and runs
its own dunning. The renewal webhook (`subscription.renewed` / `invoice.paid`)
marks the matching WHMCS invoice paid.

**Cancellation:** cancelling/terminating the WHMCS service calls
`POST /subscriptions/:id/cancel` at PaymentHooks.

### WHMCS automation settings (required)

Disable WHMCS's own auto-charge/capture for these products so the two billing
clocks don't collide — WHMCS still generates the renewal invoice, but the
payment must arrive via the PH webhook, not a WHMCS cron capture. Configure
under **System Settings > Automation Settings**.

### First-charge amount (important for setup fees / promos / mixed invoices)

In subscription mode the checkout is opened with the PaymentHooks `priceId`, so
PaymentHooks charges the **recurring price** (derived from the service's
`tblhosting.amount`). If the first invoice differs from the recurring amount —
a setup fee, a promo, proration, or several items on one invoice — the first
charge will not equal the invoice total, and the invoice may be left under- or
over-paid. For predictable behaviour, keep recurring products on their own
invoice with no setup fee, or collect the setup fee as a separate one-time
invoice. The renewal reconciler prefers an unpaid invoice whose total matches
the charged amount, so ongoing renewals are unaffected.

### Billing-cycle mapping

| WHMCS cycle | PH interval | intervalCount |
|-------------|-------------|---------------|
| Weekly | week | 1 |
| Monthly | month | 1 |
| Quarterly | month | 3 |
| Semi-Annually | month | 6 |
| Annually | year | 1 |
| Biennially | year | 2 |
| Triennially | year | 3 |

### Assumptions to verify against your PH deployment

The public PH docs don't pin down a few field names. The module tolerates
several spellings, but confirm these on your instance (check the Module Log):

- `mode: subscription` checkout accepts a top-level **`priceId`**.
- `checkout.session.completed` carries the new **subscription id** (read as
  `subscriptionId` / `subscription_id` / `subscription`).
- Renewal events carry `subscriptionId`, `paymentId`, `amount`, `currency`.
- A convenience fee, if used, appears as `convenienceFee` on the completed event.

## Limits

- **Hosted redirect only.** No iframe/inline card form; cards are entered only
  on the PaymentHooks domain.
- Renewals cannot be triggered from WHMCS cron (no off-session charge endpoint).

## Tests

Pure logic (signature, minor units, cycle mapping, domain normalisation,
checkout-mode policy) has standalone tests that need no WHMCS or network:

```
php tests/run.php
```

## Testing (test mode)

| Scenario | How |
|----------|-----|
| Successful payment | Card `4242 4242 4242 4242` → invoice marked Paid |
| Decline | Card `4000 0000 0000 0002` → invoice stays Unpaid |
| Duplicate webhook | Resend the same event → applied once |
| Bad signature | Tamper the signature → callback returns 401 |
| Refund | Admin Refund, full and partial |
| Recurring | Order a recurring product → simulate `subscription.renewed` → next invoice paid; cancel service → PH subscription cancelled |

## License

MIT — see [LICENSE](LICENSE). The PaymentHooks name and logo are trademarks of their owner and are included for identification in the WHMCS admin and checkout.
